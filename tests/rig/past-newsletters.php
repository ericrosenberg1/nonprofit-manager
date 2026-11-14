<?php
/**
 * Real-WordPress run of Past newsletters on the real MailPoet and the real
 * Newsletter Plugin.
 *
 * Seeds through each plugin's own code, and mails through each plugin's own
 * send flow into a local SMTP sink (no real address anywhere):
 *   MailPoet
 *     A  standard, sent to two lists, MailPoet keeps its stored copy
 *     B  standard, sent, every block type plus a latest-posts block, stored
 *        copy cleared the way MailPoet's 30-day cleanup clears it
 *     C  standard draft             D  standard, scheduled for 2031
 *     E  standard, sent, then moved to MailPoet's trash
 *     F  welcome email              G  post notification, with one sent
 *                                      notification_history row (a row
 *                                      MailPoet writes when it sends one,
 *                                      added here with $wpdb)
 *   The Newsletter Plugin
 *     T1 regular, sent to lists 1 and 2 by its engine, with tracking on
 *     T2 regular, sent, its body holds a tracked link copied from T1's mail
 *     T3 draft    T4 automated series email marked sent    T5 paused
 * Only A, B, T1, and T2 come over.
 *
 * Then it imports, checks every field and the scrub, imports again (no
 * duplicates), proves no send path can mail an imported newsletter, ticks
 * them through the bulk action, checks [npmp_newsletters] and the public
 * page, checks the Switch Plugins rows, and deletes everything it made.
 *
 * Needs a throwaway WordPress with this plugin (Newsletters and Contacts on),
 * MailPoet (5.45.2 verified) and Newsletter (9.5.7 verified) active, `wp`
 * on PATH, and tests/rig/smtp-sink.py listening on 127.0.0.1:2641 writing
 * the file named by NPMP_RIG_SINK (a JSON line per message).
 *   NPMP_RIG_SINK=/path/sink.jsonl wp eval-file tests/rig/past-newsletters.php
 * Run it with Pro off, then with Pro on (lists become matching groups):
 *   wp plugin activate nonprofit-manager-pro
 */

use MailPoet\DI\ContainerWrapper;

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );

$pro    = function_exists( 'npmp_pro_ensure_group' );
$sink   = (string) getenv( 'NPMP_RIG_SINK' );
$domain = 'rig-pn.example.org';
$emails = array( 'pat@' . $domain, 'sam@' . $domain, 'tia@' . $domain, 'uma@' . $domain );
echo 'Past newsletters rig, Pro ' . ( $pro ? 'on' : 'off' ) . "\n";

if ( ! class_exists( ContainerWrapper::class ) || ! class_exists( 'NewsletterEngine' ) ) {
	echo "  FAIL  MailPoet and The Newsletter Plugin must both be active\n";
	exit( 1 );
}
if ( '' === $sink || ! is_file( $sink ) ) {
	echo "  FAIL  set NPMP_RIG_SINK to the SMTP sink's output file\n";
	exit( 1 );
}

// Every wp_mail goes to the local sink, and a count of each call.
$wp_mails = 0;
add_action(
	'phpmailer_init',
	static function ( $m ) {
		$m->isSMTP();
		$m->Host     = '127.0.0.1';
		$m->Port     = 2641;
		$m->SMTPAuth = false;
		$m->SMTPAutoTLS = false;
	}
);
add_filter(
	'pre_wp_mail',
	static function ( $r ) use ( &$wp_mails ) {
		++$wp_mails;
		return $r;
	}
);
$sink_lines = static function () use ( $sink ) {
	clearstatcache();
	return count( array_filter( explode( "\n", (string) file_get_contents( $sink ) ) ) );
};
$sink_since = static function ( $from ) use ( $sink ) {
	$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $sink ) ) ) );
	$out   = array();
	foreach ( array_slice( $lines, $from ) as $line ) {
		$j = json_decode( $line, true );
		if ( is_array( $j ) ) {
			$out[] = $j;
		}
	}
	return $out;
};

// Throw on redirect and on an AJAX exit, so the handlers can run in process.
class NPMP_Rig_PN_Exit extends Exception {
	public $data;
	public function __construct( $message, $data = null ) {
		parent::__construct( $message );
		$this->data = $data;
	}
}
$catch = static function ( callable $fn ) {
	$redirect = static function ( $location ) {
		throw new NPMP_Rig_PN_Exit( 'redirect', $location );
	};
	$die = static function () {
		return static function ( $message ) {
			throw new NPMP_Rig_PN_Exit( 'die', $message );
		};
	};
	add_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $die, PHP_INT_MAX );
	add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	ob_start();
	$got = null;
	try {
		$fn();
	} catch ( NPMP_Rig_PN_Exit $e ) {
		$got = $e->data;
	}
	$printed = (string) ob_get_clean();
	remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
	remove_filter( 'wp_die_ajax_handler', $die, PHP_INT_MAX );
	remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	return array(
		'out'  => $printed,
		'data' => $got,
	);
};


/**
 * Delete everything a run of this rig makes, found by its markers (the
 * "Rig PN" subjects and list names, the rig domain, the import marks), so
 * a run that stopped half way can be cleaned by the next one. Puts back
 * the MailPoet settings and the Newsletter lists it saved at the start.
 *
 * @return int What's still there (0 when clean).
 */
function npmp_rig_pn_cleanup() {
	global $wpdb;
	$p      = $wpdb->prefix . 'mailpoet_';
	$domain = 'rig-pn.example.org';
	foreach ( get_posts( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_npmp_import_key' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		wp_delete_post( $id, true );
	}
	foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_newsletter' AND post_title LIKE 'Rig PN%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$user = get_user_by( 'login', 'rigpnauthor' );
	if ( $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user->ID );
	}
	delete_option( 'npmp_past_nl_mailpoet' );
	delete_option( 'npmp_past_nl_newsletter' );
	delete_transient( 'npmp_past_nl_mp_lost' );
	if ( function_exists( 'npmp_mail_queue_unlock' ) ) {
		npmp_mail_queue_unlock( 'import_past_mailpoet' );
		npmp_mail_queue_unlock( 'import_past_newsletter' );
	}

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'newsletters' ) ) ) {
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$p}newsletters WHERE subject LIKE 'Rig PN%'" ) );
		if ( $ids ) {
			$in    = implode( ',', $ids );
			$tasks = array_map( 'intval', (array) $wpdb->get_col( "SELECT task_id FROM {$p}sending_queues WHERE newsletter_id IN ({$in})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( array( 'sending_queues', 'newsletter_segment', 'newsletter_links', 'newsletter_option', 'statistics_newsletters', 'newsletter_posts' ) as $t ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $t ) ) ) {
					$wpdb->query( "DELETE FROM {$p}{$t} WHERE newsletter_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			if ( $tasks ) {
				$tin = implode( ',', $tasks );
				$wpdb->query( "DELETE FROM {$p}scheduled_task_subscribers WHERE task_id IN ({$tin})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM {$p}scheduled_tasks WHERE id IN ({$tin})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( "DELETE FROM {$p}newsletters WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$p}segments WHERE name LIKE 'Rig PN%'" ) as $sid ) {
			$wpdb->delete( $p . 'subscriber_segment', array( 'segment_id' => (int) $sid ) );
			$wpdb->delete( $p . 'segments', array( 'id' => (int) $sid ) );
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}subscribers WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) as $sid ) {
			$wpdb->delete( $p . 'subscriber_segment', array( 'subscriber_id' => (int) $sid ) );
			$wpdb->delete( $p . 'subscribers', array( 'id' => (int) $sid ) );
		}
	}
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'newsletter_emails' ) ) ) {
		$tids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}newsletter_emails WHERE subject LIKE 'Rig PN%'" ) );
		if ( $tids ) {
			$tin = implode( ',', $tids );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}newsletter_sent WHERE email_id IN ({$tin})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}newsletter_emails WHERE id IN ({$tin})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}newsletter WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
	}

	$keep = get_option( 'npmp_rig_pn_keep', null );
	if ( is_array( $keep ) ) {
		if ( class_exists( ContainerWrapper::class ) ) {
			$settings = ContainerWrapper::getInstance()->get( \MailPoet\Settings\SettingsController::class );
			foreach ( (array) $keep['mailpoet'] as $k => $v ) {
				$settings->set( $k, $v );
			}
		}
		if ( false === $keep['lists'] ) {
			delete_option( 'newsletter_lists' );
		} else {
			update_option( 'newsletter_lists', $keep['lists'] );
		}
		delete_option( 'npmp_rig_pn_keep' );
	}

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_key'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}newsletters WHERE subject LIKE 'Rig PN%'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}newsletter_emails WHERE subject LIKE 'Rig PN%'" )
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}subscribers WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
}

if ( in_array( 'cleanup', (array) ( $args ?? array() ), true ) ) {
	echo 'Clean up only: ' . npmp_rig_pn_cleanup() . " left\n";
	return;
}
$stale = npmp_rig_pn_cleanup();
if ( $stale ) {
	echo "  FAIL  an earlier run left {$stale} things that couldn't be cleaned\n";
	exit( 1 );
}

$made = array(
	'mp_newsletters' => array(),
	'mp_lists'       => array(),
	'tnp_emails'     => array(),
	'posts'          => array(),
	'groups'         => array(),
);

// =====================================================================
// Seed MailPoet through its own code
// =====================================================================
echo "Seed MailPoet\n";
$c        = ContainerWrapper::getInstance();
$settings = $c->get( \MailPoet\Settings\SettingsController::class );
$keep_mp  = array();
foreach ( array( 'mta', 'mta_group', 'sender', 'signup_confirmation', 'tracking' ) as $k ) {
	$keep_mp[ $k ] = $settings->get( $k );
}
// Saved in an option, so a run that stops half way still gets them back.
update_option(
	'npmp_rig_pn_keep',
	array(
		'mailpoet' => $keep_mp,
		'lists'    => get_option( 'newsletter_lists', false ),
	),
	false
);
$settings->set( 'mta', array( 'method' => 'SMTP', 'host' => '127.0.0.1', 'port' => 2641, 'authentication' => '0', 'encryption' => '', 'login' => '', 'password' => '', 'frequency' => array( 'emails' => 100, 'interval' => 1 ) ) );
$settings->set( 'mta_group', 'smtp' );
$settings->set( 'sender', array( 'name' => 'Rig Club', 'address' => 'club@' . $domain ) );
$settings->set( 'signup_confirmation.enabled', '0' );
$settings->set( 'tracking.level', 'full' );

$api   = \MailPoet\API\API::MP( 'v1' );
$alpha = $api->addList( array( 'name' => 'Rig PN Alpha' ) );
$beta  = $api->addList( array( 'name' => 'Rig PN Beta' ) );
$made['mp_lists'] = array( (int) $alpha['id'], (int) $beta['id'] );
$api->addSubscriber( array( 'email' => $emails[0], 'first_name' => 'Pat' ), array( $alpha['id'], $beta['id'] ), array( 'send_confirmation_email' => false, 'skip_subscriber_notification' => true ) );
$api->addSubscriber( array( 'email' => $emails[1], 'first_name' => 'Sam' ), array( $alpha['id'] ), array( 'send_confirmation_email' => false, 'skip_subscriber_notification' => true ) );

$nls     = $c->get( \MailPoet\API\JSON\v1\Newsletters::class );
$queue   = $c->get( \MailPoet\API\JSON\v1\SendingQueue::class );
$worker  = $c->get( \MailPoet\Cron\Workers\SendingQueue\SendingQueue::class );
$p       = $wpdb->prefix . 'mailpoet_';
$college = json_decode( (string) $wpdb->get_var( "SELECT body FROM {$p}newsletter_templates WHERE name = 'College'" ), true );

$mp_make = static function ( $type, $subject, $body, array $lists, array $extra = array() ) use ( $nls, &$made ) {
	$r  = $nls->create( array_merge( array( 'type' => $type, 'subject' => $subject ), $extra ) );
	$id = (int) $r->data['id'];
	$nls->save(
		array_merge(
			array(
				'id'        => $id,
				'body'      => wp_json_encode( $body ),
				'segments'  => array_map(
					static function ( $l ) {
						return array( 'id' => $l );
					},
					$lists
				),
				'preheader' => 'Preheader for ' . $subject,
			),
			$extra
		)
	);
	$made['mp_newsletters'][] = $id;
	return $id;
};
$mp_send = static function ( $id ) use ( $queue, $worker ) {
	$queue->add( array( 'newsletter_id' => $id ) );
	try {
		$worker->process( microtime( true ) );
	} catch ( \Throwable $e ) {
		echo '  (worker: ' . $e->getMessage() . ")\n";
	}
};

// Every block type the converter handles, two columns, and a latest-posts
// block it can't rebuild.
$every = array(
	'content'      => array(
		'type'        => 'container',
		'orientation' => 'vertical',
		'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
		'blocks'      => array(
			array(
				'type'        => 'container',
				'orientation' => 'horizontal',
				'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
				'blocks'      => array(
					array(
						'type'        => 'container',
						'orientation' => 'vertical',
						'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
						'blocks'      => array(
							array(
								'type'   => 'header',
								'text'   => '<p><a href="[link:newsletter_view_in_browser_url]">View this in your browser.</a></p>',
								'styles' => array(
									'block' => array( 'backgroundColor' => '#333333' ),
									'text'  => array( 'fontColor' => '#ffffff', 'fontFamily' => 'Arial', 'fontSize' => '12px', 'textAlign' => 'center' ),
									'link'  => array( 'fontColor' => '#ffffff', 'textDecoration' => 'underline' ),
								),
							),
						),
					),
				),
			),
			array(
				'type'         => 'container',
				'orientation'  => 'horizontal',
				'columnLayout' => '2_1',
				'styles'       => array( 'block' => array( 'backgroundColor' => '#ffffff' ) ),
				'blocks'       => array(
					array(
						'type'        => 'container',
						'orientation' => 'vertical',
						'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
						'blocks'      => array(
							array( 'type' => 'text', 'text' => '<h1>Every block, [subscriber:firstname | default:reader]</h1><p>Our address is [subscriber:email]. Read <a href="https://example.org/story">the story</a>.</p>' ),
							array( 'type' => 'image', 'link' => 'https://example.org/photo', 'src' => '/wp-content/uploads/rig-pn-photo.png', 'alt' => 'Rig photo', 'fullWidth' => false, 'width' => '320px', 'height' => '200px', 'styles' => array( 'block' => array( 'textAlign' => 'left' ) ) ),
							array( 'type' => 'image', 'link' => '', 'src' => 'https://images.example.net/far.png', 'alt' => 'Far image', 'fullWidth' => true, 'width' => '600px', 'height' => '300px', 'styles' => array( 'block' => array( 'textAlign' => 'center' ) ) ),
						),
					),
					array(
						'type'        => 'container',
						'orientation' => 'vertical',
						'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
						'blocks'      => array(
							array( 'type' => 'button', 'text' => 'Donate now', 'url' => 'https://example.org/give', 'styles' => array( 'block' => array( 'backgroundColor' => '#2ea1cd', 'borderColor' => '#0074a2', 'borderWidth' => '1px', 'borderRadius' => '5px', 'borderStyle' => 'solid', 'width' => '180px', 'lineHeight' => '40px', 'fontColor' => '#ffffff', 'fontFamily' => 'Verdana', 'fontSize' => '18px', 'fontWeight' => 'normal', 'textAlign' => 'center' ) ) ),
							array( 'type' => 'divider', 'styles' => array( 'block' => array( 'backgroundColor' => 'transparent', 'padding' => '13px', 'borderStyle' => 'dotted', 'borderWidth' => '3px', 'borderColor' => '#aaaaaa' ) ) ),
							array( 'type' => 'spacer', 'styles' => array( 'block' => array( 'backgroundColor' => 'transparent', 'height' => '40px' ) ) ),
						),
					),
				),
			),
			array(
				'type'        => 'container',
				'orientation' => 'horizontal',
				'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
				'blocks'      => array(
					array(
						'type'        => 'container',
						'orientation' => 'vertical',
						'styles'      => array( 'block' => array( 'backgroundColor' => 'transparent' ) ),
						'blocks'      => array(
							array(
								'type'    => 'social',
								'iconSet' => 'default',
								'icons'   => array(
									array( 'type' => 'socialIcon', 'iconType' => 'facebook', 'link' => 'https://www.facebook.com/rigclub', 'image' => site_url( '/wp-content/plugins/mailpoet/assets/img/newsletter_editor/social-icons/01-social/Facebook.png' ), 'height' => '32px', 'width' => '32px', 'text' => 'Facebook' ),
								),
							),
							array( 'type' => 'automatedLatestContentLayout', 'withLayout' => true, 'amount' => '3', 'contentType' => 'post', 'terms' => array(), 'inclusionType' => 'include', 'displayType' => 'excerpt', 'titleFormat' => 'h1', 'titleAlignment' => 'left', 'titleIsLink' => false, 'imageFullWidth' => false, 'featuredImagePosition' => 'alternate', 'showAuthor' => 'no', 'authorPrecededBy' => 'Author:', 'showCategories' => 'no', 'categoriesPrecededBy' => 'Categories:', 'readMoreType' => 'button', 'readMoreText' => 'Read more', 'readMoreButton' => array( 'type' => 'button', 'text' => 'Read more', 'url' => '[postLink]', 'styles' => array( 'block' => array() ) ), 'sortBy' => 'newest', 'showDivider' => true, 'divider' => array( 'type' => 'divider', 'styles' => array( 'block' => array() ) ), 'backgroundColor' => '#ffffff', 'backgroundColorAlternate' => '#eeeeee', 'styles' => array( 'block' => array() ) ),
							array(
								'type'   => 'footer',
								'text'   => '<p><a href="[link:subscription_unsubscribe_url]">Unsubscribe</a> | <a href="[link:subscription_manage_url]">Manage your subscription</a><br />Rig Club, 1 Main St</p>',
								'styles' => array(
									'block' => array( 'backgroundColor' => 'transparent' ),
									'text'  => array( 'fontColor' => '#222222', 'fontFamily' => 'Arial', 'fontSize' => '12px', 'textAlign' => 'center' ),
									'link'  => array( 'fontColor' => '#6cb7d4', 'textDecoration' => 'none' ),
								),
							),
						),
					),
				),
			),
		),
	),
	'globalStyles' => $college['globalStyles'] ?? array(),
);

$a = $mp_make( 'standard', 'Rig PN A for [subscriber:firstname | default:friend]', $college, array( (int) $alpha['id'], (int) $beta['id'] ) );
$mp_send( $a );
$b = $mp_make( 'standard', 'Rig PN B every block', $every, array( (int) $alpha['id'] ) );
$mp_send( $b );
// MailPoet's SendingQueueBodyCleanup empties the stored copy after 30 days.
$wpdb->query( $wpdb->prepare( "UPDATE {$p}sending_queues SET newsletter_rendered_body = NULL WHERE newsletter_id = %d", $b ) );
$cd = $mp_make( 'standard', 'Rig PN C draft', $college, array( (int) $alpha['id'] ) );
$d  = $mp_make( 'standard', 'Rig PN D scheduled', $college, array( (int) $alpha['id'] ), array( 'options' => array( 'isScheduled' => '1', 'scheduledAt' => '2031-01-01 10:00:00' ) ) );
$queue->add( array( 'newsletter_id' => $d ) );
$e = $mp_make( 'standard', 'Rig PN E trashed', $college, array( (int) $alpha['id'] ) );
$mp_send( $e );
$nls->trash( array( 'id' => $e ) );
$f = $mp_make( 'welcome', 'Rig PN F welcome', $college, array(), array( 'options' => array( 'event' => 'segment', 'segment' => (string) $alpha['id'], 'afterTimeType' => 'immediate', 'afterTimeNumber' => '0' ) ) );
$g = $mp_make( 'notification', 'Rig PN G notification', $college, array( (int) $alpha['id'] ), array( 'options' => array( 'intervalType' => 'daily', 'timeOfDay' => '0', 'weekDay' => '0', 'monthDay' => '0', 'nthWeekDay' => '1', 'schedule' => '0 0 * * *' ) ) );
$wpdb->insert( $p . 'newsletters', array( 'hash' => wp_generate_password( 12, false ), 'parent_id' => $g, 'subject' => 'Rig PN G history', 'type' => 'notification_history', 'status' => 'sent', 'body' => wp_json_encode( $college ), 'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$made['mp_newsletters'][] = (int) $wpdb->insert_id;

$rows = $wpdb->get_results( "SELECT id, type, status, deleted_at IS NOT NULL AS trashed, sent_at FROM {$p}newsletters WHERE subject LIKE 'Rig PN%' ORDER BY id", ARRAY_A );
$by   = array();
foreach ( $rows as $r ) {
	$by[ (int) $r['id'] ] = $r;
}
$ok( 'MailPoet sent A itself', 'sent' === ( $by[ $a ]['status'] ?? '' ) && ! empty( $by[ $a ]['sent_at'] ), $by[ $a ] ?? null );
$ok( 'MailPoet sent B itself', 'sent' === ( $by[ $b ]['status'] ?? '' ), $by[ $b ] ?? null );
$ok( 'C is a draft', 'draft' === ( $by[ $cd ]['status'] ?? '' ), $by[ $cd ] ?? null );
$ok( 'D is scheduled', 'scheduled' === ( $by[ $d ]['status'] ?? '' ), $by[ $d ] ?? null );
$ok( 'E went out and sits in the trash', 'sent' === ( $by[ $e ]['status'] ?? '' ) && '1' === (string) ( $by[ $e ]['trashed'] ?? '' ), $by[ $e ] ?? null );
$ok( 'F is a welcome email, G a notification', 'welcome' === ( $by[ $f ]['type'] ?? '' ) && 'notification' === ( $by[ $g ]['type'] ?? '' ) );
$stored_a = (string) $wpdb->get_var( $wpdb->prepare( "SELECT newsletter_rendered_body FROM {$p}sending_queues WHERE newsletter_id = %d", $a ) );
$ok( 'MailPoet stored its copy of A, with hashed click links and an open pixel', false !== strpos( $stored_a, '[mailpoet_click_data]-' ) && false !== strpos( $stored_a, 'mailpoet_open_data' ) );
$mp_mail = $sink_since( 0 );
$ok( 'the sink caught MailPoet\'s mail for Pat', (bool) array_filter( $mp_mail, static function ( $m ) use ( $emails ) {
	return in_array( $emails[0], (array) $m['rcpt_to'], true );
} ) );

// =====================================================================
// Seed The Newsletter Plugin through its own code
// =====================================================================
echo "Seed The Newsletter Plugin\n";
$keep_lists = get_option( 'newsletter_lists', false );
$lists_opt  = is_array( $keep_lists ) ? $keep_lists : array();
$lists_opt['list_1'] = 'Rig PN One';
$lists_opt['list_2'] = 'Rig PN Two';
update_option( 'newsletter_lists', $lists_opt );
TNP::add_subscriber( array( 'email' => $emails[2], 'name' => 'Tia', 'status' => 'C', 'lists' => array( 1 ) ) );
TNP::add_subscriber( array( 'email' => $emails[3], 'name' => 'Uma', 'status' => 'C', 'lists' => array( 2 ) ) );
$tnp       = Newsletter::instance();
$users_tbl = $wpdb->prefix . 'newsletter';
$tnp_html  = '<!DOCTYPE html><html><head><title>x</title><style>p{color:#333}</style></head><body style="background-color:#f4f4f4">'
	. '<p>Hello {name},</p><p>Read <a href="https://example.org/tnp-story">our story</a> or see it <a href="{email_url}">online</a>.</p>'
	. '<p><img src="' . esc_url( site_url( '/wp-content/uploads/rig-pn-tnp.png' ) ) . '" alt="TNP photo" width="400"></p>'
	. '<p><img src="' . esc_url( site_url( '/wp-content/plugins/newsletter/images/social/facebook.png' ) ) . '" alt="facebook"></p>'
	. '<p>{blog_title} &middot; <a href="{profile_url}">Your profile</a> &middot; <a href="{unsubscription_url}">Unsubscribe</a> &middot; sent to {email}</p>'
	. '</body></html>';
$tnp_make  = static function ( $subject, $message, $status, $type = 'message', $lists = array( 1, 2 ) ) use ( $tnp, $users_tbl, &$made ) {
	$where = array();
	foreach ( $lists as $l ) {
		$where[] = 'list_' . (int) $l . '=1';
	}
	$email = $tnp->save_email(
		array(
			'subject' => $subject,
			'message' => $message,
			'status'  => $status,
			'type'    => $type,
			'track'   => 1,
			'send_on' => time() - 60,
			'query'   => "select * from {$users_tbl} where status='C'" . ( $where ? ' and (' . implode( ' or ', $where ) . ')' : '' ),
			'options' => array(
				'lists'          => $lists,
				'lists_operator' => 'or',
				'preheader'      => 'TNP preheader ' . $subject,
			),
		)
	);
	$made['tnp_emails'][] = (int) $email->id;
	return (int) $email->id;
};
$tnp_send = static function ( $id ) use ( $tnp ) {
	for ( $i = 0; $i < 5; $i++ ) {
		$email = $tnp->get_email( $id );
		if ( ! $email || 'sent' === $email->status ) {
			break;
		}
		NewsletterEngine::instance()->send( $email );
	}
};
$before_t1 = $sink_lines();
$t1        = $tnp_make( 'Rig PN T1 for {name}', $tnp_html, 'sending' );
$tnp_send( $t1 );
$t1_mail = $sink_since( $before_t1 );
$tracked = '';
foreach ( $t1_mail as $m ) {
	$raw = quoted_printable_decode( (string) $m['data'] );
	if ( preg_match( '#href="?(https?://[^"\s>]*(?:nltr=|/tnp/l/)[^"\s>]*)#i', $raw, $hit ) ) {
		$tracked = html_entity_decode( $hit[1] );
		break;
	}
}
$ok( 'The Newsletter Plugin mailed T1 to both lists through its engine', 2 === count( $t1_mail ), count( $t1_mail ) );
$ok( 'its mail carries a tracked link', '' !== $tracked );
$t2 = $tnp_make( 'Rig PN T2 pasted link', '<p>A link copied from a received copy: <a href="' . esc_url( $tracked ) . '">the tracked story</a>.</p><p>Bye {first_name}</p>', 'sending', 'message', array( 1 ) );
$tnp_send( $t2 );
$t3 = $tnp_make( 'Rig PN T3 draft', $tnp_html, 'new' );
$t4 = $tnp_make( 'Rig PN T4 automated', $tnp_html, 'sent', 'automated_1' );
$t5 = $tnp_make( 'Rig PN T5 paused', $tnp_html, 'paused' );
$t_status = $wpdb->get_results( "SELECT id, status, type FROM {$wpdb->prefix}newsletter_emails WHERE subject LIKE 'Rig PN%' ORDER BY id", OBJECT_K );
$ok( 'T1 and T2 are sent, T3 new, T4 an automated email, T5 paused', 'sent' === $t_status[ $t1 ]->status && 'sent' === $t_status[ $t2 ]->status && 'new' === $t_status[ $t3 ]->status && 'automated_1' === $t_status[ $t4 ]->type && 'paused' === $t_status[ $t5 ]->status, $t_status );

// =====================================================================
// Choice screens
// =====================================================================
echo "Approve screens\n";
ob_start();
do_action( 'npmp_import_approve_parts', 'mailpoet' );
$choice = (string) ob_get_clean();
$ok( 'MailPoet approve shows the box, unticked, with the count', false !== strpos( $choice, 'name="npmp_past_newsletters"' ) && false === strpos( $choice, 'checked' ) && false !== strpos( $choice, 'Bring over 2 newsletters you already sent' ), wp_strip_all_tags( $choice ) );
$ok( 'and says one loses some formatting', false !== strpos( $choice, '1 newsletter loses some formatting' ) );
ob_start();
do_action( 'npmp_import_approve_parts', 'newsletter' );
$choice = (string) ob_get_clean();
$ok( 'The Newsletter Plugin screen shows its box with 2', false !== strpos( $choice, 'Bring over 2 newsletters you already sent' ) );

// =====================================================================
// Import
// =====================================================================
echo "Import\n";
$mails_before = $wp_mails;
$sink_before  = $sink_lines();
$run_all      = static function ( $slug ) {
	$r = array();
	for ( $i = 0; $i < 30; $i++ ) {
		$r = npmp_past_nl_run( $slug, 5, 1 );
		if ( ! empty( $r['done'] ) ) {
			break;
		}
	}
	return $r;
};
npmp_past_nl_choose( 'mailpoet', true );
npmp_past_nl_choose( 'newsletter', true );
$rm = $run_all( 'mailpoet' );
$rt = $run_all( 'newsletter' );
$sm = npmp_past_nl_state( 'mailpoet' );
$st = npmp_past_nl_state( 'newsletter' );
$ok( 'MailPoet run finished, 2 of 2', ! empty( $rm['done'] ) && 2 === $rm['position'] && 2 === $rm['total'], $rm );
$ok( 'MailPoet: 2 created, 1 lost formatting, nothing failed', 2 === $sm['results']['created'] && 1 === $sm['results']['lost'] && ! $sm['results']['failed'], $sm['results'] );
$ok( 'Newsletter run: 2 created, nothing failed', ! empty( $rt['done'] ) && 2 === $st['results']['created'] && ! $st['results']['failed'], $st['results'] );
$ok( 'no email went out during the import', $mails_before === $wp_mails && $sink_before === $sink_lines() );

$posts = get_posts(
	array(
		'post_type'      => 'npmp_newsletter',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'meta_key'       => '_npmp_import_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
$byk   = array();
foreach ( $posts as $post ) {
	$byk[ get_post_meta( $post->ID, '_npmp_import_key', true ) ] = $post;
	$made['posts'][] = $post->ID;
}
$ok( 'exactly A, B, T1, and T2 came over', array( 'mailpoet:' . $a, 'mailpoet:' . $b, 'newsletter:' . $t1, 'newsletter:' . $t2 ) === array_keys( $byk ), array_keys( $byk ) );

$pa = $byk[ 'mailpoet:' . $a ] ?? null;
$pb = $byk[ 'mailpoet:' . $b ] ?? null;
$p1 = $byk[ 'newsletter:' . $t1 ] ?? null;
$p2 = $byk[ 'newsletter:' . $t2 ] ?? null;
if ( ! $pa || ! $pb || ! $p1 || ! $p2 ) {
	echo "  FAIL  missing imported newsletters, stopping\n";
	exit( 1 );
}
$sent_a = strtotime( $by[ $a ]['sent_at'] . ' UTC' );
$ok( 'A: title from the subject, the name tag turned into its default', 'Rig PN A for friend' === $pa->post_title, $pa->post_title );
$ok( 'A: dated when MailPoet sent it', gmdate( 'Y-m-d H:i:s', $sent_a ) === $pa->post_date_gmt && get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $sent_a ) ) === get_post_meta( $pa->ID, '_npmp_newsletter_queued_at', true ), array( $pa->post_date_gmt, $by[ $a ]['sent_at'] ) );
$ok( 'A: status sent, template none, preheader kept', 'sent' === get_post_meta( $pa->ID, '_npmp_newsletter_status', true ) && 'none' === get_post_meta( $pa->ID, '_npmp_newsletter_template', true ) && 'Preheader for Rig PN A for friend' === get_post_meta( $pa->ID, '_npmp_newsletter_preheader', true ), get_post_meta( $pa->ID, '_npmp_newsletter_preheader', true ) );
$ok( 'A: audience names both lists', 'MailPoet lists: Rig PN Alpha, Rig PN Beta' === get_post_meta( $pa->ID, '_npmp_newsletter_audience_label', true ), get_post_meta( $pa->ID, '_npmp_newsletter_audience_label', true ) );
$ok( 'A: no stats stored, not public, marked imported', '' === get_post_meta( $pa->ID, '_npmp_newsletter_recipient_total', true ) && '' === get_post_meta( $pa->ID, '_npmp_newsletter_public', true ) && 'mailpoet' === get_post_meta( $pa->ID, '_npmp_import_source', true ) && (int) $a === (int) get_post_meta( $pa->ID, '_npmp_import_old_id', true ) );
$ok( 'A: came from the stored copy (College template text) with its click links restored', false !== strpos( $pa->post_content, 'Graduation Day' ) && false === strpos( $pa->post_content, '[mailpoet_click_data]' ) );
$ok( 'A: Custom HTML block', 0 === strpos( $pa->post_content, '<!-- wp:html -->' ) );
$ok( 'B: rebuilt from blocks, name tag converted', false !== strpos( $pb->post_content, 'Every block, [first_name default="reader"]' ) );
$ok( 'B: columns, button, divider, spacer kept', false !== strpos( $pb->post_content, 'display:flex' ) && false !== strpos( $pb->post_content, '>Donate now</a>' ) && false !== strpos( $pb->post_content, 'border-top-style:dotted' ) && false !== strpos( $pb->post_content, 'height:40px' ) );
$ok( 'B: site image gets the full site address, the other host stays', false !== strpos( $pb->post_content, 'src="' . site_url( '/wp-content/uploads/rig-pn-photo.png' ) . '"' ) && false !== strpos( $pb->post_content, 'src="https://images.example.net/far.png"' ) );
$ok( 'B: MailPoet\'s social icon is a text link now', false !== strpos( $pb->post_content, '<a href="https://www.facebook.com/rigclub">Facebook </a>' ) && false === strpos( $pb->post_content, 'plugins/mailpoet' ) );
$ok( 'B: the address line stays, unsubscribe and manage go', false !== strpos( $pb->post_content, 'Rig Club, 1 Main St' ) && false === stripos( $pb->post_content, 'Unsubscribe' ) && false === stripos( $pb->post_content, 'Manage your' ) );
$ok( 'B: marked as losing formatting', '1' === get_post_meta( $pb->ID, '_npmp_import_lost_formatting', true ) );
$ok( 'T1: title, list names, send date', 'Rig PN T1 for' === $p1->post_title && 'The Newsletter Plugin lists: Rig PN One, Rig PN Two' === get_post_meta( $p1->ID, '_npmp_newsletter_audience_label', true ) && abs( strtotime( $p1->post_date_gmt . ' UTC' ) - (int) $wpdb->get_var( $wpdb->prepare( "SELECT send_on FROM {$wpdb->prefix}newsletter_emails WHERE id = %d", $t1 ) ) ) < 2, array( $p1->post_title, get_post_meta( $p1->ID, '_npmp_newsletter_audience_label', true ) ) );
$ok( 'T1: {name} became [first_name], site title filled', false !== strpos( $p1->post_content, 'Hello [first_name],' ) && false !== strpos( $p1->post_content, get_bloginfo( 'name' ) ) );
$ok( 'T1: view online, profile, and unsubscribe links go, the story link stays', false === stripos( $p1->post_content, 'online</a>' ) && false === stripos( $p1->post_content, 'profile' ) && false === stripos( $p1->post_content, 'Unsubscribe' ) && false !== strpos( $p1->post_content, 'href="https://example.org/tnp-story"' ) );
$ok( 'T1: the plugin-folder icon became its alt text, the uploads image stays', false === strpos( $p1->post_content, 'plugins/newsletter' ) && false !== strpos( $p1->post_content, 'rig-pn-tnp.png' ) );
$ok( 'T2: the tracked link pasted from a received copy points at the real page again', false !== strpos( $p2->post_content, 'href="https://example.org/tnp-story"' ), $p2->post_content );
$ok( 'T2: preheader from the options column', 'TNP preheader Rig PN T2 pasted link' === get_post_meta( $p2->ID, '_npmp_newsletter_preheader', true ) );

echo "Scrub\n";
foreach ( array( 'A' => $pa, 'B' => $pb, 'T1' => $p1, 'T2' => $p2 ) as $label => $post ) {
	$body  = $post->post_content . ' ' . $post->post_title . ' ' . get_post_meta( $post->ID, '_npmp_newsletter_preheader', true );
	$leaks = npmp_past_nl_find_leaks( $body, $emails );
	$ok( $label . ': no reader tag, address, token, or old tracking URL', ! $leaks, $leaks );
	$ok( $label . ': no script, style, form, or comment', 0 === preg_match( '#<(script|style|form|iframe|input)\b|<!--(?! /?wp:html)#i', $body ) );
	$ok( $label . ': no link into either old plugin', false === stripos( $body, 'mailpoet_router' ) && false === strpos( $body, 'admin-ajax.php?action=tnptr' ) && 0 === preg_match( '#[?&]na=#', $body ) );
}

// =====================================================================
// Run it again
// =====================================================================
echo "Run again\n";
npmp_past_nl_choose( 'mailpoet', true );
npmp_past_nl_choose( 'newsletter', true );
$run_all( 'mailpoet' );
$run_all( 'newsletter' );
$sm    = npmp_past_nl_state( 'mailpoet' );
$st    = npmp_past_nl_state( 'newsletter' );
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_key'" );
$ok( 'second run adds nothing and counts 2 + 2 already here', 0 === $sm['results']['created'] && 2 === $sm['results']['existing'] && 0 === $st['results']['created'] && 2 === $st['results']['existing'] && 4 === $count, array( $sm['results'], $st['results'], $count ) );
ob_start();
do_action( 'npmp_import_after_migrate', 'mailpoet' );
$after = (string) ob_get_clean();
$ok( 'the results say how many and how to make them public', false !== strpos( $after, 'You already had 2 from an earlier import' ) && false !== strpos( $after, 'Show in the public archive' ) && false !== strpos( $after, 'page=npmp_newsletter_archive' ), wp_strip_all_tags( $after ) );
npmp_past_nl_choose( 'mailpoet', false );
ob_start();
do_action( 'npmp_import_approve_parts', 'mailpoet' );
$ok( 'unticking stops it: the box reads unticked', false === strpos( (string) ob_get_clean(), 'checked' ) );
$ok( 'an unticked run does nothing', ! empty( npmp_past_nl_run( 'mailpoet' )['done'] ) && 0 === npmp_past_nl_state( 'mailpoet' )['results']['created'] );

// =====================================================================
// No send path mails an imported newsletter
// =====================================================================
echo "No re-send\n";
$qt           = $wpdb->prefix . 'npmp_newsletter_queue';
$mails_before = $wp_mails;
$sink_before  = $sink_lines();
$ok( 'queue_newsletter() queues nobody', 0 === NPMP_Newsletter_Manager::queue_newsletter( $pa->ID ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$qt} WHERE newsletter_id = %d", $pa->ID ) ) );
$ok( 'and leaves it marked sent with its date', 'sent' === get_post_meta( $pa->ID, '_npmp_newsletter_status', true ) && get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $sent_a ) ) === get_post_meta( $pa->ID, '_npmp_newsletter_queued_at', true ) );

add_filter( 'wp_doing_ajax', '__return_true' );
$_POST = array(
	'post_id' => $pa->ID,
	'nonce'   => wp_create_nonce( 'npmp_send_newsletter_' . $pa->ID ),
);
$r = $catch(
	static function () {
		do_action( 'wp_ajax_npmp_send_newsletter_now' );
	}
);
$ok( 'Send now refuses', false !== strpos( $r['out'], '"success":false' ) && false !== strpos( $r['out'], 'imported this newsletter' ), $r['out'] );
$_POST = array(
	'post_id' => $pa->ID,
	'nonce'   => wp_create_nonce( 'npmp_send_later_' . $pa->ID ),
	'at'      => wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS ),
);
$r = $catch(
	static function () {
		do_action( 'wp_ajax_npmp_send_later' );
	}
);
$ok( 'Send later refuses', false !== strpos( $r['out'], '"success":false' ) && false !== strpos( $r['out'], 'imported this newsletter' ), $r['out'] );
remove_filter( 'wp_doing_ajax', '__return_true' );
$_POST = array();
$ok( 'and no schedule row exists for it', ! npmp_scheduled_emails_list_row( $pa->ID ) );

// A Send later row that got in some other way (an older version, a script).
npmp_scheduled_emails_insert_row(
	array(
		'target_type' => 'list',
		'target_id'   => $pa->ID,
		'rule_key'    => 'send_later',
		'subject'     => $pa->post_title,
		'send_at'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'status'      => 'pending',
		'note'        => '',
	)
);
$row    = npmp_scheduled_emails_list_row( $pa->ID );
$status = npmp_scheduled_emails_process_list_row( $row, time() );
$ok( 'a Send later row for it gets cancelled when due', 'cancelled' === $status && 'cancelled' === npmp_scheduled_emails_list_row( $pa->ID )->status );
$wpdb->delete( $wpdb->prefix . 'npmp_scheduled_emails', array( 'id' => (int) $row->id ) );

// A queue row someone added by hand.
$wpdb->insert( $qt, array( 'newsletter_id' => $pa->ID, 'user_id' => 0, 'email' => $emails[0], 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ) );
$qid = (int) $wpdb->insert_id;
NPMP_Newsletter_Manager::process_queue();
// Since the drain clears rows for a newsletter it won't render (deleted or
// imported), the row is gone rather than marked failed.
$ok( 'the mail queue drops a row for it unsent', null === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$qt} WHERE id = %d", $qid ) ) );
$wpdb->delete( $qt, array( 'id' => $qid ) );
$ok( 'none of that sent an email', $mails_before === $wp_mails && $sink_before === $sink_lines(), array( $wp_mails - $mails_before, $sink_lines() - $sink_before ) );

ob_start();
npmp_newsletter_send_controls_html( $pa );
$box = (string) ob_get_clean();
$ok( 'the editor shows a note and no Send or Send later', false !== strpos( $box, 'send it again from here' ) && false === strpos( $box, 'npmp-send-newsletter' ) && false === strpos( $box, 'npmp-send-later' ), wp_strip_all_tags( $box ) );
$ok( 'the audience column shows the old lists', 'MailPoet lists: Rig PN Alpha, Rig PN Beta' === npmp_newsletter_get_audience_label( $pa->ID ) );

// Duplicate makes a fresh draft you can send.
$_GET = array(
	'newsletter_id' => $pa->ID,
	'_wpnonce'      => wp_create_nonce( 'npmp_duplicate_newsletter_' . $pa->ID ),
);
$_REQUEST = $_GET;
$r        = $catch( 'npmp_handle_duplicate_newsletter' );
$_GET     = array();
$_REQUEST = array();
$dup      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_newsletter' AND post_status = 'draft' AND post_title LIKE %s ORDER BY ID DESC LIMIT 1", $wpdb->esc_like( $pa->post_title ) . '%' ) );
if ( $dup ) {
	$made['posts'][] = $dup;
}
$ok( 'Duplicate makes a draft with no import mark', $dup && ! npmp_newsletter_is_imported( $dup ), $r );

ob_start();
npmp_render_newsletter_reports();
$rep = (string) ob_get_clean();
$ok( 'Reports show a dash, never a made-up 0, for an imported one', (bool) preg_match( '#Rig PN A for friend</a></td><td>[^<]*</td><td>[^<]*</td><td>—</td><td>—</td><td>—</td><td>—</td>#u', $rep ) );

// =====================================================================
// Public archive: opt in through the bulk action
// =====================================================================
echo "Public archive\n";
$titles = static function ( $html ) {
	preg_match_all( '#class="npmp-newsletter-title"[^>]*>\s*<a[^>]*>(.*?)</a>#s', (string) $html, $m );
	return array_map( 'wp_strip_all_tags', $m[1] );
};
$ok( 'none are in [npmp_newsletters] before you tick them', ! array_intersect( array( 'Rig PN A for friend', 'Rig PN B every block' ), $titles( do_shortcode( '[npmp_newsletters limit="0"]' ) ) ) );
$ok( 'none have a public page yet', ! npmp_newsletter_archive_is_public( $pa ) );

ob_start();
npmp_render_newsletter_archive();
$screen = (string) ob_get_clean();
$ok( 'the list screen has the two bulk actions and a box per row', false !== strpos( $screen, 'value="npmp_public_show"' ) && false !== strpos( $screen, 'value="npmp_public_hide"' ) && false !== strpos( $screen, 'name="npmp_public_ids[]" value="' . $pa->ID . '"' ) );

$_POST = array(
	'action'             => 'npmp_newsletter_public_bulk',
	'npmp_public_action' => 'npmp_public_show',
	'npmp_public_ids'    => array( $pa->ID, $pb->ID, $p1->ID, $dup ),
	'npmp_public_nonce'  => wp_create_nonce( 'npmp_newsletter_public_bulk' ),
);
$_REQUEST = $_POST;
$r        = $catch( 'npmp_newsletter_public_bulk_post' );
$ok( 'Show goes to a confirmation first and ticks nothing yet', false !== strpos( (string) $r['data'], 'npmp_public_confirm=1' ) && ! npmp_newsletter_archive_is_public( $pa ), $r['data'] );
$_GET = array( 'npmp_public_confirm' => '1' );
ob_start();
npmp_newsletter_public_bulk_notices();
$confirm = (string) ob_get_clean();
$_GET    = array();
$ok( 'the confirmation counts 3, and the unsent copy stays hidden', false !== strpos( $confirm, 'Show 3 newsletters in the public archive?' ) && false !== strpos( $confirm, '1 newsletter you picked hasn&#039;t gone out yet' ), wp_strip_all_tags( $confirm ) );
$_POST = array(
	'action'                => 'npmp_newsletter_public_bulk',
	'npmp_public_confirmed' => '1',
	'npmp_public_nonce'     => wp_create_nonce( 'npmp_newsletter_public_bulk' ),
);
$_REQUEST = $_POST;
$r        = $catch( 'npmp_newsletter_public_bulk_post' );
$ok( 'confirming ticks 3', false !== strpos( (string) $r['data'], 'npmp_public_done=show' ) && false !== strpos( (string) $r['data'], 'npmp_public_n=3' ), $r['data'] );
$ok( 'A, B, and T1 are public, the draft copy and T2 aren\'t', npmp_newsletter_archive_is_public( $pa ) && npmp_newsletter_archive_is_public( $pb ) && npmp_newsletter_archive_is_public( $p1 ) && ! npmp_newsletter_archive_is_public( $p2 ) && ! npmp_newsletter_archive_is_public( $dup ) );
$list = $titles( do_shortcode( '[npmp_newsletters limit="0"]' ) );
$want = array( $pa, $pb, $p1 );
usort(
	$want,
	static function ( $x, $y ) {
		return strcmp( get_post_meta( $y->ID, '_npmp_newsletter_queued_at', true ) . sprintf( '%09d', $y->ID ), get_post_meta( $x->ID, '_npmp_newsletter_queued_at', true ) . sprintf( '%09d', $x->ID ) );
	}
);
$ok( '[npmp_newsletters] lists them now, newest send date first', array_slice( $list, 0, 3 ) === wp_list_pluck( $want, 'post_title' ), array( $list, wp_list_pluck( $want, 'post_title' ) ) );
$listing = do_shortcode( '[npmp_newsletters limit="0"]' );
$ok( 'the list carries no reader name or address', false === strpos( $listing, 'Pat' ) && ! npmp_past_nl_find_leaks( $listing, $emails ) );

foreach ( array( 'A' => $pa, 'B' => $pb, 'T1' => $p1 ) as $label => $post ) {
	$page = npmp_newsletter_archive_page_html( $post->ID );
	$ok( $label . ' public page: title and body', false !== strpos( $page, '<h1 class="npmp-nlv-title">' . esc_html( $post->post_title ) . '</h1>' ) && strlen( $page ) > 500 );
	$ok( $label . ' public page: no name, address, token, or tracking', false === strpos( $page, 'Pat' ) && false === strpos( $page, 'npmp_token' ) && false === strpos( $page, 'npmp_track' ) && 0 === preg_match( '/[?&;]token=/', $page ) && ! npmp_past_nl_find_leaks( $page, $emails ), npmp_past_nl_find_leaks( $page, $emails ) );
	$ok( $label . ' public page: no unsubscribe footer for a reader', false === stripos( $page, 'unsubscribe' ) );
}
$ok( 'B public page: the name tag shows its default', false !== strpos( npmp_newsletter_archive_page_html( $pb->ID ), 'Every block, reader' ) );

$url  = npmp_newsletter_archive_url( $pa->ID );
$resp = wp_remote_get( $url, array( 'timeout' => 60 ) );
$html = is_wp_error( $resp ) ? '' : wp_remote_retrieve_body( $resp );
$ok( 'A public page over HTTP: 200 with the title and nobody\'s address', 200 === wp_remote_retrieve_response_code( $resp ) && false !== strpos( $html, 'Rig PN A for friend' ) && ! npmp_past_nl_find_leaks( $html, $emails ), is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_response_code( $resp ) );

$_POST = array(
	'action'             => 'npmp_newsletter_public_bulk',
	'npmp_public_action' => 'npmp_public_hide',
	'npmp_public_ids'    => array( $pa->ID, $pb->ID ),
	'npmp_public_nonce'  => wp_create_nonce( 'npmp_newsletter_public_bulk' ),
);
$_REQUEST = $_POST;
$r        = $catch( 'npmp_newsletter_public_bulk_post' );
$ok( 'Hide takes 2 out right away', false !== strpos( (string) $r['data'], 'npmp_public_done=hide' ) && false !== strpos( (string) $r['data'], 'npmp_public_n=2' ) && ! npmp_newsletter_archive_is_public( $pa ) && npmp_newsletter_archive_is_public( $p1 ), $r['data'] );

// The WordPress list's bulk action menu goes the same way.
$ok( 'the WordPress list offers both actions', isset( apply_filters( 'bulk_actions-edit-npmp_newsletter', array() )['npmp_public_show'] ) );
$redir = apply_filters( 'handle_bulk_actions-edit-npmp_newsletter', 'edit.php', 'npmp_public_hide', array( $p1->ID ) );
$ok( 'and Hide from it works', false !== strpos( $redir, 'npmp_public_n=1' ) && ! npmp_newsletter_archive_is_public( $p1 ) );

// Someone who can't send sees no bulk actions and gets refused.
$author = wp_insert_user( array( 'user_login' => 'rigpnauthor', 'user_pass' => wp_generate_password(), 'user_email' => 'author@' . $domain, 'role' => 'author' ) );
wp_set_current_user( $author );
$ok( 'an author gets no bulk actions', ! isset( apply_filters( 'bulk_actions-edit-npmp_newsletter', array() )['npmp_public_show'] ) );
$_POST = array(
	'action'             => 'npmp_newsletter_public_bulk',
	'npmp_public_action' => 'npmp_public_show',
	'npmp_public_ids'    => array( $pa->ID ),
	'npmp_public_nonce'  => wp_create_nonce( 'npmp_newsletter_public_bulk' ),
);
$_REQUEST = $_POST;
$r        = $catch( 'npmp_newsletter_public_bulk_post' );
$ok( 'and the handler refuses an author', ! npmp_newsletter_archive_is_public( $pa ) && false !== strpos( (string) $r['data'], 'permission' ), $r['data'] );
wp_set_current_user( (int) $admins[0]->ID );
wp_delete_user( $author );
$_POST    = array();
$_REQUEST = array();

// =====================================================================
// Switch Plugins
// =====================================================================
echo "Switch Plugins\n";
$sources = npmp_import_sources();
$rows_mp = (array) npmp_import_hub_call( $sources['mailpoet']['counts'], array() );
$row_mp  = array_values(
	array_filter(
		$rows_mp,
		static function ( $r ) {
			return 'Sent newsletters' === $r['label'];
		}
	)
);
$ok( 'MailPoet Verify row: 2 sent there, 2 here, the rest named as staying behind', $row_mp && 2 === (int) $row_mp[0]['old'] && 2 === (int) $row_mp[0]['new'] && false !== strpos( $row_mp[0]['note'], 'in the old plugin' ), $row_mp );
$rows_t = (array) npmp_import_hub_call( $sources['newsletter']['counts'], array() );
$ok( 'Newsletter Verify row: 2 and 2', (bool) array_filter( $rows_t, static function ( $r ) {
	return 'Sent newsletters' === $r['label'] && 2 === (int) $r['old'] && 2 === (int) $r['new'];
} ), $rows_t );
$ok( 'the summary mentions them', false !== strpos( (string) npmp_import_hub_call( $sources['mailpoet']['import_summary'], '' ), 'sent newsletters' ) );
$ok( 'a checklist line asks you to check them', (bool) preg_grep( '/past newsletters/', $sources['mailpoet']['checklist'] ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'mailpoet:999999' ), array( 'post_id' => $pb->ID, 'meta_key' => '_npmp_import_key' ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'rig' ), array( 'post_id' => $pb->ID, 'meta_key' => '_npmp_import_source' ) );
$lines = (array) npmp_import_hub_call( $sources['mailpoet']['data_removal']['describe'], array() );
$ok( 'Remove data warns about a sent newsletter you didn\'t bring over', (bool) preg_grep( '/1 sent newsletter you never brought over/', $lines ), $lines );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'mailpoet:' . $b ), array( 'post_id' => $pb->ID, 'meta_key' => '_npmp_import_key' ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'mailpoet' ), array( 'post_id' => $pb->ID, 'meta_key' => '_npmp_import_source' ) );

// =====================================================================
// MailPoet off: it still reads
// =====================================================================
echo "MailPoet off\n";
$mp_file = 'mailpoet/mailpoet.php';
foreach ( $made['posts'] as $id ) {
	if ( $id !== $dup ) {
		wp_delete_post( $id, true );
	}
}
deactivate_plugins( $mp_file, true );
$ok( 'MailPoet is off', ! is_plugin_active( $mp_file ) );
npmp_past_nl_choose( 'mailpoet', true );
$run_all( 'mailpoet' );
$ok( 'with MailPoet off, both come over again from its tables', 2 === npmp_past_nl_state( 'mailpoet' )['results']['created'] && 2 === npmp_past_nl_count_imported( 'mailpoet' ) );

// =====================================================================
// Pro: lists become the groups that share their name
// =====================================================================
if ( $pro ) {
	echo "Pro groups\n";
	foreach ( get_posts( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_npmp_import_source', 'meta_value' => 'mailpoet' ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		wp_delete_post( $id, true );
	}
	$had_alpha = term_exists( 'Rig PN Alpha', NPMP_PRO_GROUP_TAXONOMY );
	$alpha_id  = npmp_pro_ensure_group( 'Rig PN Alpha' );
	if ( ! $had_alpha ) {
		$made['groups'][] = $alpha_id;
	}
	$ok( 'Beta has no group here', ! term_exists( 'Rig PN Beta', NPMP_PRO_GROUP_TAXONOMY ) );
	npmp_past_nl_choose( 'mailpoet', true );
	$run_all( 'mailpoet' );
	$new_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_key' AND meta_value = %s", 'mailpoet:' . $a ) );
	$ok( 'A joins Alpha, and no Beta group gets made', array( $alpha_id ) === npmp_pro_get_newsletter_groups( $new_a ) && ! term_exists( 'Rig PN Beta', NPMP_PRO_GROUP_TAXONOMY ), npmp_pro_get_newsletter_groups( $new_a ) );
	$ok( 'the results count it', 2 === npmp_past_nl_state( 'mailpoet' )['results']['groups'] );
	update_post_meta( $new_a, '_npmp_newsletter_public', '1' );
	$ok( '[npmp_newsletters group="Rig PN Alpha"] lists A', in_array( 'Rig PN A for friend', $titles( do_shortcode( '[npmp_newsletters group="Rig PN Alpha"]' ) ), true ) );
} else {
	echo "No Pro\n";
	$ok( 'no groups without Pro', '' === get_post_meta( (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_key' AND meta_value = %s", 'mailpoet:' . $a ) ), '_npmp_newsletter_groups', true ) );
	ob_start();
	activate_plugin( $mp_file );
	ob_end_clean();
	ob_start();
	do_action( 'npmp_import_approve_parts', 'mailpoet' );
	$ok( 'the approve screen doesn\'t promise groups', false === strpos( (string) ob_get_clean(), 'joins the group' ) );
}

// =====================================================================
// Clean up: leave the site as we found it
// =====================================================================
echo "Clean up\n";
if ( ! is_plugin_active( $mp_file ) ) {
	ob_start();
	activate_plugin( $mp_file );
	ob_end_clean();
}
foreach ( $made['groups'] as $id ) {
	wp_delete_term( $id, NPMP_PRO_GROUP_TAXONOMY );
}
$left = npmp_rig_pn_cleanup();
$ok( 'nothing the rig made is left', 0 === $left, $left );
$ok( 'MailPoet is on again, as it was', is_plugin_active( $mp_file ) );

printf( "\n%s: %d failed\n", $fails ? 'FAILED' : 'OK', $fails );
exit( $fails ? 1 : 0 );
