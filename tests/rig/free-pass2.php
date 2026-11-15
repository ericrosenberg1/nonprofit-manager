<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/free-pass2.php
//
// The real-WordPress half of tests/test-free-pass2.php (second pass of the
// 2026-11 free sweep):
// - Send now while a newsletter still drains mails nobody twice, and a third
//   press with everyone mailed sends nothing and says so.
// - The newsletter and the weekly digest skip a reader who unsubscribed (or
//   turned the digest off) after the queue was built, and Reports counts only
//   who we mailed.
// - Signup and resubscribe links stop working after 7 days (filterable), and
//   the page says so with a link to sign up again.
// - Mailchimp and Constant Contact imports keep the key sealed in their
//   transient and open it only with the browser's secret (a fake of each API).
// - The calendar's list view with past events on lists upcoming events first,
//   then the newest past ones, and an empty list says which events it looked for.
// - With Pro active: Email Settings saves the AWS secret and the API keys as typed.
// We catch mail with pre_wp_mail, put back every option we change, and delete
// the posts and contacts we make. Run it with Pro off and on. Feature flags
// decide which files load, so turn them on first:
// wp option update npmp_enabled_features '{"members":true,"newsletters":true,"donations":true,"calendar":true,"social":false}' --format=json
// The pre-push gate doesn't run it.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$features = (array) get_option( 'npmp_enabled_features', array() );
if ( empty( $features['members'] ) || empty( $features['newsletters'] ) || empty( $features['calendar'] ) ) {
	fwrite( STDERR, "Turn on the members, newsletters, and calendar features first (see the comment above).\n" );
	exit( 2 );
}

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-72s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

class Rig_Stop extends Error { public $payload = ''; public $status = 0; }
$rig_die = function () {
	return function ( $msg, $title = '', $args = array() ) {
		$e          = new Rig_Stop( 'die' );
		$e->payload = is_string( $msg ) ? $msg : '';
		$e->status  = (int) ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 );
		throw $e;
	};
};
add_filter( 'wp_die_handler', $rig_die );
add_filter( 'wp_die_ajax_handler', $rig_die );
add_filter( 'wp_redirect', function ( $location ) { $e = new Rig_Stop( 'redirect' ); $e->payload = $location; throw $e; }, 999 );
add_filter( 'npmp_rate_limit_max', '__return_zero' );

// Runs a callable with the request set. Returns the Rig_Stop it ended on, or null.
function rig_run( $callable, $request = array(), $method = 'GET' ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_REQUEST                  = $request;
	if ( 'POST' === $method ) {
		$_POST = $request;
	} else {
		$_GET = $request;
	}
	$level = ob_get_level();
	ob_start();
	$stop = null;
	try {
		call_user_func( $callable );
	} catch ( Rig_Stop $e ) {
		$stop = $e;
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	$_GET = $_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $stop;
}

// The bodies of a plugin's own JSON answers (wp_send_json() echoes, then dies).
function rig_ajax( $action, $post ) {
	add_filter( 'wp_doing_ajax', '__return_true' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = $_REQUEST = $post;
	$level                     = ob_get_level();
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( Rig_Stop $e ) {
		unset( $e );
	}
	$out = '';
	while ( ob_get_level() > $level ) {
		$out = ob_get_clean() . $out;
	}
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	remove_filter( 'wp_doing_ajax', '__return_true' );
	return json_decode( $out, true );
}

$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );
function rig_mail_count( $to, $subject_has ) {
	$n = 0;
	foreach ( $GLOBALS['rig_mails'] as $m ) {
		$tos = (array) $m['to'];
		if ( in_array( $to, $tos, true ) && false !== strpos( (string) $m['subject'], $subject_has ) ) {
			$n++;
		}
	}
	return $n;
}

global $wpdb;
$missing = '__rig_missing__';
$watched = array( 'npmp_newsletter_rate_limit', 'npmp_digest_rate_limit', 'npmp_enable_weekly_digest', 'npmp_digest_pending_content', 'npmp_membership_form_settings', 'npmp_calendar_list_show_past', 'npmp_calendar_list_limit', 'npmp_email_settings', 'npmp_aws_ses_access_key', 'npmp_aws_ses_secret_key', 'npmp_brevo_api_key', 'npmp_sendgrid_api_key', 'npmp_mailgun_api_key', 'npmp_mailgun_domain', 'npmp_postmark_api_key', 'npmp_email_method', 'npmp_email_from_email', 'npmp_email_from_name', 'npmp_smtp_host', 'npmp_smtp_port', 'npmp_smtp_encryption', 'npmp_smtp_auth', 'npmp_smtp_username', 'npmp_smtp_password' );
$saved   = array();
foreach ( $watched as $o ) {
	$saved[ $o ] = get_option( $o, $missing );
}

$mm       = NPMP_Member_Manager::get_instance();
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
$posts    = array();
$addrs    = array( 'rig-p2-a@example.org', 'rig-p2-b@example.org', 'rig-p2-c@example.org', 'rig-p2-new@example.org', 'rig-p2-gone@example.org', 'rig-p2-mc@example.org', 'rig-p2-cc@example.org' );
$cleanup  = function () use ( $mm, $addrs, &$posts ) {
	global $wpdb;
	foreach ( $addrs as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( (int) $m->id );
		}
	}
	foreach ( $posts as $id ) {
		$wpdb->delete( $wpdb->prefix . 'npmp_newsletter_queue', array( 'newsletter_id' => (int) $id ), array( '%d' ) );
		wp_delete_post( $id, true );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}npmp_digest_queue WHERE email LIKE 'rig-p2-%'" );
	$mm->forget_email();
	wp_cache_flush();
};
$cleanup();

// Only this rig's contacts get the newsletters it sends.
add_filter(
	'npmp_newsletter_recipients',
	function ( $list ) {
		return array_values( array_filter( (array) $list, function ( $r ) { return 0 === strpos( (string) ( $r->user_email ?? '' ), 'rig-p2-' ); } ) );
	}
);

$ids = array();
foreach ( array( 'a', 'b', 'c' ) as $k ) {
	$ids[ $k ] = (int) $mm->add_member( array( 'name' => 'Rig ' . strtoupper( $k ), 'email' => 'rig-p2-' . $k . '@example.org', 'status' => 'subscribed', 'source' => 'manual' ) );
}
rig_check( 'three subscribed contacts', true, $ids['a'] > 0 && $ids['b'] > 0 && $ids['c'] > 0 );
$queue = $wpdb->prefix . 'npmp_newsletter_queue';
$rows  = function ( $nl, $status ) use ( $wpdb, $queue ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = %d AND status = %s", $nl, $status ) ); // phpcs:ignore
};
$new_newsletter = function ( $title ) use ( &$posts ) {
	$id = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Hello from the rig.</p>' ) );
	update_post_meta( $id, '_npmp_newsletter_template', 'none' );
	$posts[] = $id;
	return (int) $id;
};

echo "== Send now while the newsletter still drains ==\n";
update_option( 'npmp_newsletter_rate_limit', 2 );
$GLOBALS['rig_mails'] = array();
$nl = $new_newsletter( 'Rig requeue' );
rig_check( 'first Send now queues three', 3, NPMP_Newsletter_Manager::queue_newsletter( $nl ) );
NPMP_Newsletter_Manager::process_queue();
rig_check( 'one batch of two went out', 2, count( $GLOBALS['rig_mails'] ) );
$second = NPMP_Newsletter_Manager::queue_newsletter( $nl );
rig_check( 'second Send now leaves one waiting', 1, $second );
rig_check( 'and skips the two already mailed', 2, method_exists( 'NPMP_Newsletter_Manager', 'last_queue_already_sent' ) ? NPMP_Newsletter_Manager::last_queue_already_sent() : -1 );
rig_check( 'the sent rows stay sent', 2, $rows( $nl, 'sent' ) );
rig_check( 'one row waits', 1, $rows( $nl, 'pending' ) );
NPMP_Newsletter_Manager::process_queue();
NPMP_Newsletter_Manager::process_queue();
foreach ( array( 'a', 'b', 'c' ) as $k ) {
	rig_check( "reader $k got exactly one copy", 1, rig_mail_count( 'rig-p2-' . $k . '@example.org', 'Rig requeue' ) );
}
rig_check( 'the newsletter is marked sent', 'sent', get_post_meta( $nl, '_npmp_newsletter_status', true ) );
$date_before = get_post_meta( $nl, '_npmp_newsletter_queued_at', true );
update_post_meta( $nl, '_npmp_newsletter_queued_at', '2026-01-02 03:04:05' );
wp_set_current_user( $admin_id );
$answer = rig_ajax(
	'npmp_send_newsletter_now',
	array(
		'post_id' => $nl,
		'nonce'   => wp_create_nonce( 'npmp_send_newsletter_' . $nl ),
	)
);
wp_set_current_user( 0 );
rig_check( 'a third Send now queues nobody', 0, $rows( $nl, 'pending' ) );
rig_check( 'and says everyone already got it', true, is_array( $answer ) && false !== strpos( (string) ( $answer['data'] ?? '' ), 'already got this newsletter' ) );
rig_check( 'the send date stays put', '2026-01-02 03:04:05', get_post_meta( $nl, '_npmp_newsletter_queued_at', true ) );
rig_check( 'the status stays sent', 'sent', get_post_meta( $nl, '_npmp_newsletter_status', true ) );
NPMP_Newsletter_Manager::process_queue();
rig_check( 'still one copy each after the third press', 3, rig_mail_count( 'rig-p2-a@example.org', 'Rig requeue' ) + rig_mail_count( 'rig-p2-b@example.org', 'Rig requeue' ) + rig_mail_count( 'rig-p2-c@example.org', 'Rig requeue' ) );
unset( $date_before );

echo "== A failed send gets another try ==\n";
$nl_f = $new_newsletter( 'Rig retry' );
NPMP_Newsletter_Manager::queue_newsletter( $nl_f );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'failed' WHERE newsletter_id = %d AND email = %s", $nl_f, 'rig-p2-a@example.org' ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'sent' WHERE newsletter_id = %d AND email = %s", $nl_f, 'rig-p2-b@example.org' ) ); // phpcs:ignore
rig_check( 'queuing again waits on a and c only', 2, NPMP_Newsletter_Manager::queue_newsletter( $nl_f ) );
rig_check( 'a\'s failed row is gone', 0, $rows( $nl_f, 'failed' ) );

echo "== Unsubscribed after the newsletter was queued ==\n";
update_option( 'npmp_newsletter_rate_limit', 10 );
$GLOBALS['rig_mails'] = array();
$nl2 = $new_newsletter( 'Rig unsub' );
rig_check( 'queued to three', 3, NPMP_Newsletter_Manager::queue_newsletter( $nl2 ) );
$mm->update_member( $ids['b'], array( 'status' => 'unsubscribed' ) );
NPMP_Newsletter_Manager::process_queue();
rig_check( 'a still gets it', 1, rig_mail_count( 'rig-p2-a@example.org', 'Rig unsub' ) );
rig_check( 'b, who unsubscribed, does not', 0, rig_mail_count( 'rig-p2-b@example.org', 'Rig unsub' ) );
rig_check( 'c still gets it', 1, rig_mail_count( 'rig-p2-c@example.org', 'Rig unsub' ) );
rig_check( 'b\'s row is gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = %d AND email = %s", $nl2, 'rig-p2-b@example.org' ) ) ); // phpcs:ignore
wp_cache_flush();
rig_check( 'Reports counts two recipients', 2, NPMP_Newsletter_Stats::get_instance()->get_total_recipients( $nl2 ) );
rig_check( 'Reports counts no failures', 0, NPMP_Newsletter_Stats::get_instance()->get_failed_count( $nl2 ) );
rig_check( 'the newsletter finishes as sent', 'sent', get_post_meta( $nl2, '_npmp_newsletter_status', true ) );
$mm->update_member( $ids['b'], array( 'status' => 'subscribed' ) );

echo "== The weekly digest after someone leaves ==\n";
update_option( 'npmp_enable_weekly_digest', 1 );
update_option( 'npmp_digest_rate_limit', 10 );
foreach ( $ids as $id ) {
	update_post_meta( $id, '_npmp_weekly_digest', '1' );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}npmp_digest_queue" ); // phpcs:ignore -- Throwaway rig site.
$posts[] = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rig digest post', 'post_content' => 'News.' ) );
npmp_process_weekly_digest();
rig_check( 'three digest rows wait', 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_digest_queue WHERE email LIKE 'rig-p2-%' AND status = 'pending'" ) ); // phpcs:ignore
$mm->update_member( $ids['b'], array( 'status' => 'unsubscribed' ) );
update_post_meta( $ids['c'], '_npmp_weekly_digest', '0' );
$GLOBALS['rig_mails'] = array();
npmp_process_digest_queue();
rig_check( 'a gets the digest', 1, rig_mail_count( 'rig-p2-a@example.org', 'Weekly Digest' ) );
rig_check( 'b, who unsubscribed, does not', 0, rig_mail_count( 'rig-p2-b@example.org', 'Weekly Digest' ) );
rig_check( 'c, who turned the digest off, does not', 0, rig_mail_count( 'rig-p2-c@example.org', 'Weekly Digest' ) );
rig_check( 'nothing waits afterwards', 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_digest_queue WHERE status = 'pending'" ) ); // phpcs:ignore
$mm->update_member( $ids['b'], array( 'status' => 'subscribed' ) );

echo "== Signup links expire ==\n";
$form = is_array( $saved['npmp_membership_form_settings'] ) ? $saved['npmp_membership_form_settings'] : array();
$form['double_optin']   = 1;
$form['signup_page_id'] = 0;
update_option( 'npmp_membership_form_settings', $form );
npmp_get_membership_form_settings( true );
function rig_signup( $email ) {
	return rig_run(
		'npmp_handle_membership_form',
		array(
			'action'                  => 'npmp_handle_form',
			'npmp_action'             => 'email_signup',
			'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
			'npmp_name'               => 'Rig New',
			'npmp_email'              => $email,
			'npmp_signup_atts'        => npmp_signup_atts_token( array() ),
		),
		'POST'
	);
}
function rig_link( $action ) {
	foreach ( array_reverse( $GLOBALS['rig_mails'] ) as $m ) {
		if ( preg_match( '#(https?://\S+action=' . $action . '\S*)#', (string) $m['message'], $hit ) ) {
			return $hit[1];
		}
	}
	return '';
}
function rig_open( $link, $method, $handler ) {
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	if ( 'POST' === $method ) {
		$q['npmp_confirm'] = '1';
	}
	return rig_run( $handler, $q, $method );
}
$GLOBALS['rig_mails'] = array();
rig_signup( 'rig-p2-new@example.org' );
$new  = $mm->get_member_by_email( 'rig-p2-new@example.org' );
$link = rig_link( 'npmp_confirm_signup' );
rig_check( 'the signup made a Pending contact and sent a link', true, $new && 'pending' === $new->status && '' !== $link );
$pending         = (array) get_post_meta( (int) $new->id, '_npmp_signup_confirm', true );
$pending['time'] = time() - 8 * DAY_IN_SECONDS;
update_post_meta( (int) $new->id, '_npmp_signup_confirm', $pending );
$stop = rig_open( $link, 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'an 8-day-old link answers 410', 410, $stop ? $stop->status : 0 );
rig_check( 'and says it expired', true, $stop && false !== strpos( $stop->payload, 'This link is too old to use' ) );
rig_check( 'with a link to sign up again', true, $stop && false !== strpos( $stop->payload, 'Sign up again' ) && false !== strpos( $stop->payload, 'href="' . esc_url( home_url( '/' ) ) . '"' ) );
$mm->forget_email();
wp_cache_flush();
rig_check( 'the contact stays Pending', 'pending', $mm->get_member_by_email( 'rig-p2-new@example.org' )->status );
$stop = rig_open( $link, 'GET', 'npmp_handle_confirm_signup' );
rig_check( 'opening the old link shows no confirm button', true, $stop && false === strpos( $stop->payload, '<button' ) );
add_filter( 'npmp_signup_confirm_expiry', function () { return 30 * DAY_IN_SECONDS; } );
$stop = rig_open( $link, 'POST', 'npmp_handle_confirm_signup' );
remove_all_filters( 'npmp_signup_confirm_expiry' );
wp_cache_flush();
rig_check( 'with the filter at 30 days the same link confirms', 'subscribed', $mm->get_member_by_email( 'rig-p2-new@example.org' )->status );
$mm->delete_member( (int) $new->id );
$GLOBALS['rig_mails'] = array();
rig_signup( 'rig-p2-new@example.org' );
$new  = $mm->get_member_by_email( 'rig-p2-new@example.org' );
$link = rig_link( 'npmp_confirm_signup' );
$pending         = (array) get_post_meta( (int) $new->id, '_npmp_signup_confirm', true );
$pending['time'] = time() - 6 * DAY_IN_SECONDS;
update_post_meta( (int) $new->id, '_npmp_signup_confirm', $pending );
rig_open( $link, 'POST', 'npmp_handle_confirm_signup' );
wp_cache_flush();
rig_check( 'a 6-day-old link still confirms', 'subscribed', $mm->get_member_by_email( 'rig-p2-new@example.org' )->status );

echo "== Resubscribe links expire ==\n";
$gone = (int) $mm->add_member( array( 'name' => 'Rig Gone', 'email' => 'rig-p2-gone@example.org', 'status' => 'unsubscribed', 'source' => 'manual' ) );
$GLOBALS['rig_mails'] = array();
rig_signup( 'rig-p2-gone@example.org' );
$link = rig_link( 'npmp_confirm_resubscribe' );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
rig_check( 'the resubscribe link carries its time', true, isset( $q['ts'] ) && abs( time() - (int) $q['ts'] ) < 60 );
$old_ts = time() - 8 * DAY_IN_SECONDS;
$old    = add_query_arg( array( 'ts' => $old_ts, 'token' => npmp_generate_resubscribe_token( 'rig-p2-gone@example.org', $old_ts ) ), $link );
$stop   = rig_open( $old, 'POST', 'npmp_handle_confirm_resubscribe' );
rig_check( 'an 8-day-old resubscribe link answers 410', 410, $stop ? $stop->status : 0 );
wp_cache_flush();
rig_check( 'and the contact stays unsubscribed', 'unsubscribed', $mm->get_member_by_email( 'rig-p2-gone@example.org' )->status );
$forged = add_query_arg( 'ts', time(), $old );
$stop   = rig_open( $forged, 'POST', 'npmp_handle_confirm_resubscribe' );
rig_check( 'a link with its time changed is invalid', 400, $stop ? $stop->status : 0 );
$legacy = add_query_arg( array( 'ts' => false, 'token' => npmp_generate_resubscribe_token( 'rig-p2-gone@example.org' ) ), $link );
$pending         = get_post_meta( $gone, '_npmp_signup_confirm', true );
$pending         = is_array( $pending ) ? $pending : array( 'kind' => 'resubscribe', 'key' => 'rig' );
$pending['time'] = time() - 8 * DAY_IN_SECONDS;
update_post_meta( $gone, '_npmp_signup_confirm', $pending );
$stop = rig_open( $legacy, 'POST', 'npmp_handle_confirm_resubscribe' );
rig_check( 'a link from before, with an 8-day-old signup, answers 410', 410, $stop ? $stop->status : 0 );
$stop = rig_open( $link, 'GET', 'npmp_handle_confirm_resubscribe' );
rig_check( 'the new link shows the button', true, $stop && false !== strpos( $stop->payload, '<button' ) && false !== strpos( $stop->payload, 'name="ts"' ) );
rig_open( $link, 'POST', 'npmp_handle_confirm_resubscribe' );
wp_cache_flush();
rig_check( 'the new link subscribes them again', 'subscribed', $mm->get_member_by_email( 'rig-p2-gone@example.org' )->status );

echo "== Mailchimp and Constant Contact keys stay sealed ==\n";
// A fake of each API. Never run against the live services.
$GLOBALS['rig_api_auth'] = array();
$fake_api = function ( $pre, $args, $url ) {
	$auth = (string) ( $args['headers']['Authorization'] ?? '' );
	$json = function ( $body ) { return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => wp_json_encode( $body ) ); };
	if ( false !== strpos( $url, '.api.mailchimp.com/' ) ) {
		$GLOBALS['rig_api_auth'][] = $auth;
		if ( false !== strpos( $url, '/merge-fields' ) ) {
			return $json( array( 'merge_fields' => array( array( 'tag' => 'FNAME', 'name' => 'First Name', 'type' => 'text' ) ) ) );
		}
		return $json( array( 'members' => array( array( 'email_address' => 'rig-p2-mc@example.org', 'status' => 'subscribed', 'merge_fields' => array( 'FNAME' => 'Mia' ), 'tags' => array(), 'timestamp_opt' => '' ) ), 'total_items' => 1 ) );
	}
	if ( 0 === strpos( $url, 'https://api.cc.email/v3/' ) ) {
		$GLOBALS['rig_api_auth'][] = $auth;
		if ( false !== strpos( $url, '/contact_custom_fields' ) ) {
			return $json( array( 'custom_fields' => array() ) );
		}
		return $json( array( 'contacts' => array( array( 'email_address' => array( 'address' => 'rig-p2-cc@example.org', 'permission_to_send' => 'implicit' ), 'first_name' => 'Cal' ) ) ) );
	}
	return $pre;
};
add_filter( 'pre_http_request', $fake_api, 10, 3 );
wp_set_current_user( $admin_id );
$import_nonce = wp_create_nonce( 'npmp_import_nonce' );
$sources      = array(
	'mailchimp'        => array( 'npmp_import_mc_', array( 'mc_api_key' => 'rigkey0123456789-us6', 'mc_list_id' => 'abc123' ), 'rigkey0123456789-us6', 'rig-p2-mc@example.org' ),
	'constant_contact' => array( 'npmp_import_cc_', array( 'cc_token' => 'rig-cc-token-0123456789', 'cc_list_id' => 'list-1' ), 'rig-cc-token-0123456789', 'rig-p2-cc@example.org' ),
);
foreach ( $sources as $source => $spec ) {
	list( $prefix, $fields, $secret_value, $address ) = $spec;
	$preview = rig_ajax( 'npmp_import_preview', array_merge( array( 'nonce' => $import_nonce, 'source' => $source ), $fields ) );
	$token   = (string) ( $preview['data']['file_token'] ?? '' );
	$stored  = get_transient( $prefix . $token );
	rig_check( "$source preview answers", true, ! empty( $preview['success'] ) && '' !== $token );
	rig_check( "$source transient holds no plain key", false, is_array( $stored ) && false !== strpos( maybe_serialize( $stored ), $secret_value ) );
	rig_check( "$source transient holds a sealed box", true, is_array( $stored ) && ! empty( $stored['box'] ) );
	rig_check( "$source preview hands the browser its secret", 64, strlen( (string) ( $preview['data']['import_secret'] ?? '' ) ) );
	$step = array(
		'nonce'              => $import_nonce,
		'source'             => $source,
		'job_token'          => $token,
		'mapping'            => array( 0 => 'email' ),
		'duplicate_handling' => 'skip',
		'default_status'     => 'subscribed',
		'import_secret'      => str_repeat( '0', 64 ),
	);
	$wrong = rig_ajax( 'npmp_import_step', $step );
	rig_check( "$source step with a wrong secret stops", true, empty( $wrong['success'] ) && false !== strpos( wp_json_encode( $wrong ), 'expired' ) );
	rig_check( "$source wrong secret leaves no transient", false, get_transient( $prefix . $token ) );
	// Start again and run it with the browser's secret.
	$preview                = rig_ajax( 'npmp_import_preview', array_merge( array( 'nonce' => $import_nonce, 'source' => $source ), $fields ) );
	$step['job_token']      = (string) ( $preview['data']['file_token'] ?? '' );
	$step['import_secret']  = (string) ( $preview['data']['import_secret'] ?? '' );
	$GLOBALS['rig_api_auth'] = array();
	$done                   = rig_ajax( 'npmp_import_step', $step );
	$mm->forget_email();
	wp_cache_flush();
	rig_check( "$source step with the secret imports", true, ! empty( $done['success'] ) && ! empty( $done['data']['done'] ) && (bool) $mm->get_member_by_email( $address ) );
	$want = 'mailchimp' === $source ? 'Basic ' . base64_encode( 'anystring:' . $secret_value ) : 'Bearer ' . $secret_value;
	rig_check( "$source step sent the opened key to the API", true, in_array( $want, $GLOBALS['rig_api_auth'], true ) );
}
wp_set_current_user( 0 );
remove_filter( 'pre_http_request', $fake_api, 10 );

echo "== The calendar's list view with past events on ==\n";
$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_type = 'npmp_event' AND post_status = 'publish' AND post_title NOT LIKE 'Rig P2 %'" ); // phpcs:ignore -- Throwaway rig site, put back below.
$hidden = (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_event' AND post_status = 'draft' AND post_title NOT LIKE 'Rig P2 %'" ); // phpcs:ignore
$event  = function ( $title, $start ) use ( &$posts ) {
	$id = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '' ) );
	update_post_meta( $id, '_npmp_event_start', $start );
	update_post_meta( $id, '_npmp_event_end', $start );
	$posts[] = $id;
};
for ( $m = 1; $m <= 12; $m++ ) {
	$event( sprintf( 'Rig P2 past %02d', $m ), sprintf( '2025-%02d-10 18:00:00', $m ) );
}
$event( 'Rig P2 soon 1', '2027-02-01 18:00:00' );
$event( 'Rig P2 soon 2', '2027-03-01 18:00:00' );
$event( 'Rig P2 soon 3', '2027-04-01 18:00:00' );
update_option( 'npmp_calendar_list_show_past', 1 );
update_option( 'npmp_calendar_list_limit', 10 );
$html = npmp_calendar_render_list_view( '' );
preg_match_all( '#Rig P2 (past \d+|soon \d)#', $html, $hit );
$titles = array_values( array_unique( $hit[1] ) );
rig_check( 'ten events', 10, count( $titles ) );
rig_check(
	'upcoming soonest first, then the newest past events',
	array( 'soon 1', 'soon 2', 'soon 3', 'past 12', 'past 11', 'past 10', 'past 09', 'past 08', 'past 07', 'past 06' ),
	$titles
);
rig_check( 'one Past events label', 1, substr_count( $html, 'npmp-events-past-label' ) );
rig_check( 'the label sits before the first past event', true, strpos( $html, 'npmp-events-past-label' ) < strpos( $html, 'Rig P2 past 12' ) && strpos( $html, 'npmp-events-past-label' ) > strpos( $html, 'Rig P2 soon 3' ) );
update_option( 'npmp_calendar_list_show_past', 0 );
$html = npmp_calendar_render_list_view( '' );
preg_match_all( '#Rig P2 (past \d+|soon \d)#', $html, $hit );
rig_check( 'past events off lists only the upcoming three', array( 'soon 1', 'soon 2', 'soon 3' ), array_values( array_unique( $hit[1] ) ) );
rig_check( 'and no past label', 0, substr_count( $html, 'npmp-events-past-label' ) );
$base = npmp_events_shortcode_defaults();
rig_check( 'an empty upcoming list says so', true, false !== strpos( npmp_events_loop_render( array_merge( $base, array( 'category' => 'rig-none', 'past' => 'false' ) ) ), 'No upcoming events.' ) );
rig_check( 'an empty past list says so', true, false !== strpos( npmp_events_loop_render( array_merge( $base, array( 'category' => 'rig-none', 'past' => 'true' ) ) ), 'No past events.' ) );
rig_check( 'an empty list of both says no events', true, false !== strpos( npmp_events_loop_render( array_merge( $base, array( 'category' => 'rig-none', 'past' => 'all' ) ) ), 'No events found.' ) );
foreach ( $hidden as $hid ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => (int) $hid ) );
}

if ( function_exists( 'npmp_is_pro' ) && npmp_is_pro() ) {
	echo "== Email Settings with Pro: keys save as typed ==\n";
	wp_set_current_user( $admin_id );
	$typed = 'ab%41cd<ef>  gh';
	$post  = array(
		'npmp_email_settings_nonce' => wp_create_nonce( 'npmp_email_settings' ),
		'npmp_email_from_name'      => 'Rig Site',
		'npmp_email_from_email'     => 'rig@example.org',
		'npmp_email_provider'       => 'wordpress',
		'npmp_aws_ses_secret_key'   => wp_slash( $typed . '-aws' ),
		'npmp_brevo_api_key'        => wp_slash( $typed . '-brevo' ),
		'npmp_sendgrid_api_key'     => wp_slash( $typed . '-sendgrid' ),
		'npmp_mailgun_api_key'      => wp_slash( $typed . '-mailgun' ),
		'npmp_postmark_api_key'     => wp_slash( $typed . '-postmark' ),
	);
	rig_run( 'npmp_render_email_settings_page', $post, 'POST' );
	wp_set_current_user( 0 );
	foreach ( array( 'npmp_aws_ses_secret_key' => 'aws', 'npmp_brevo_api_key' => 'brevo', 'npmp_sendgrid_api_key' => 'sendgrid', 'npmp_mailgun_api_key' => 'mailgun', 'npmp_postmark_api_key' => 'postmark' ) as $opt => $tag ) {
		wp_cache_delete( $opt, 'options' );
		rig_check( $opt . ' saved as typed', $typed . '-' . $tag, get_option( $opt ) );
	}
} else {
	echo "== Email Settings with Pro: skipped, Pro is off ==\n";
}

// Put everything back.
$cleanup();
foreach ( $saved as $o => $v ) {
	if ( $missing === $v ) {
		delete_option( $o );
	} else {
		update_option( $o, $v );
	}
}
wp_clear_scheduled_hook( 'npmp_process_digest_queue' );

if ( $GLOBALS['rig_errors'] ) {
	echo "\nPHP notices from the plugin:\n  " . implode( "\n  ", array_unique( $GLOBALS['rig_errors'] ) ) . "\n";
}
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
