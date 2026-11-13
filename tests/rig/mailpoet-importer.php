<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/mailpoet-importer.php
//
// The MailPoet importer on a real WordPress, without MailPoet itself. The
// script builds MailPoet's tables with the column names from MailPoet 5.45.2
// (lib/Migrations/Db/Migration_20221028_105818.php), fills them with one
// subscriber of every status, a trashed one, two lists, a trashed list, the
// WordPress Users list, and serialized settings rows, then runs all five
// steps through the importer's own functions: read, review, approve, migrate,
// and deactivate. It runs the import twice to prove nothing duplicates, and a
// third time under a two-row cap.
//
// Run it twice, with Pro on and with Pro off:
//   wp plugin activate nonprofit-manager-pro     (lists become groups)
//   wp plugin deactivate nonprofit-manager-pro   (contacts only)
// Pro's features load without a licence on 127.0.0.1.
//
// Works on SQLite and MySQL. It writes two stub plugins (mailpoet and
// mailpoet-premium, a header and one constant each) to test the deactivate
// step, and removes them, the tables, the contacts, the groups, and the test
// pages when it ends. Not run by the pre-push gate (it needs WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=npmp_mailpoet_import';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_GET['page']               = 'npmp_mailpoet_import';
define( 'WP_ADMIN', true );
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-74s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function rig_has( $label, $needle, $haystack ) {
	rig_check( $label, true, false !== strpos( (string) $haystack, $needle ) );
}
function rig_render( $callback, $stage = null ) {
	ob_start();
	null === $stage ? call_user_func( $callback ) : call_user_func( $callback, $stage );
	return html_entity_decode( wp_strip_all_tags( ob_get_clean() ), ENT_QUOTES, 'UTF-8' );
}

if ( ! function_exists( 'npmp_mailpoet_stage_batch' ) ) {
	echo "The importer isn't loaded. Is the members module on, and does the plugin symlink point at this checkout?\n";
	exit( 1 );
}

global $wpdb;
// The rig writes a stub over mailpoet/mailpoet.php and drops MailPoet's tables,
// so it refuses a site where the real MailPoet lives (rig mailpoet-hub.php covers that).
$real_mailpoet = file_exists( WP_PLUGIN_DIR . '/mailpoet/mailpoet.php' ) && false === strpos( (string) file_get_contents( WP_PLUGIN_DIR . '/mailpoet/mailpoet.php' ), '0.0.1-rig' );
if ( $real_mailpoet || npmp_mailpoet_has_table() ) {
	echo "This site has the real MailPoet's files or tables. Run this rig on a site without them.\n";
	exit( 1 );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
set_current_screen( 'membership_page_npmp_mailpoet_import' );

$mm     = NPMP_Member_Manager::get_instance();
$pro    = npmp_mailpoet_groups_supported();
$domain = 'rig-mailpoet.example.org';
$tables = array( 'subscribers', 'segments', 'subscriber_segment', 'settings', 'tags', 'subscriber_tag' );
$tax    = defined( 'NPMP_PRO_GROUP_TAXONOMY' ) ? NPMP_PRO_GROUP_TAXONOMY : '';
printf( "Pro groups: %s\n", $pro ? 'on' : 'off' );

// ---------------------------------------------------------------------
// Clean slate, and remember every setting the run touches.
// ---------------------------------------------------------------------
$keep = array();
foreach ( array( 'npmp_email_settings', 'npmp_email_from_name', 'npmp_email_from_email', 'npmp_membership_form_settings', 'timezone_string', 'sidebars_widgets', 'npmp_mailpoet_imported' ) as $option ) {
	$keep[ $option ] = get_option( $option, null );
}
// The stubs below overwrite mailpoet/mailpoet.php and the sweep drops
// MailPoet's tables, so a real MailPoet on this site would be wrecked.
if ( is_dir( WP_PLUGIN_DIR . '/mailpoet/lib' ) ) {
	echo "Refusing: the real MailPoet is installed here, and this rig overwrites its files and tables. Use a site without it (tests/rig/past-newsletters.php and mailpoet-hub.php run on the real one).\n";
	exit( 1 );
}
$stubs = array(
	'mailpoet/mailpoet.php'                 => "<?php\n/**\n * Plugin Name: MailPoet\n * Version: 0.0.1-rig\n */\nif ( ! defined( 'MAILPOET_VERSION' ) ) {\n\tdefine( 'MAILPOET_VERSION', '0.0.1-rig' );\n}\n",
	'mailpoet-premium/mailpoet-premium.php' => "<?php\n/**\n * Plugin Name: MailPoet Premium\n * Version: 0.0.1-rig\n */\n",
);
$rig_posts = array();
$sweep     = function () use ( $wpdb, $mm, $domain, $tables, $stubs, &$rig_posts, $tax ) {
	foreach ( $tables as $name ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . npmp_mailpoet_table( $name ) ); // phpcs:ignore
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	wp_cache_flush();
	if ( $tax && taxonomy_exists( $tax ) ) {
		foreach ( array( 'Walking Group', 'Arts, Crafts', 'Old List', 'WordPress Users', 'Nobody Left' ) as $group ) {
			$term = get_term_by( 'name', $group, $tax );
			if ( $term ) {
				wp_delete_term( $term->term_id, $tax );
			}
		}
	}
	foreach ( $rig_posts as $pid ) {
		wp_delete_post( $pid, true );
	}
	$rig_posts = array();
	deactivate_plugins( array_keys( $stubs ), true );
	foreach ( array_keys( $stubs ) as $rel ) {
		if ( file_exists( WP_PLUGIN_DIR . '/' . $rel ) ) {
			unlink( WP_PLUGIN_DIR . '/' . $rel );
			rmdir( dirname( WP_PLUGIN_DIR . '/' . $rel ) );
		}
	}
	wp_clean_plugins_cache( false );
	npmp_mailpoet_reset_stage();
	delete_option( 'npmp_mailpoet_imported' );
	delete_user_meta( get_current_user_id(), 'npmp_mailpoet_notice_dismissed' );
};
$sweep();

update_option( 'timezone_string', 'Europe/London' );
delete_option( 'npmp_email_settings' );
delete_option( 'npmp_email_from_name' );
delete_option( 'npmp_email_from_email' );
delete_option( 'npmp_membership_form_settings' );
npmp_get_membership_form_settings( true );

echo "Before MailPoet's tables exist\n";
rig_check( 'no subscribers table', false, npmp_mailpoet_has_table() );
rig_check( 'count is 0, no SQL error', 0, npmp_mailpoet_count_subscribers() );

// ---------------------------------------------------------------------
// MailPoet's tables, with its own column names and types.
// ---------------------------------------------------------------------
$t_sub  = npmp_mailpoet_table( 'subscribers' );
$t_seg  = npmp_mailpoet_table( 'segments' );
$t_link = npmp_mailpoet_table( 'subscriber_segment' );
$t_set  = npmp_mailpoet_table( 'settings' );
$t_tag  = npmp_mailpoet_table( 'tags' );
$t_st   = npmp_mailpoet_table( 'subscriber_tag' );
$ddl    = array(
	"CREATE TABLE {$t_sub} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		wp_user_id bigint(20) NULL,
		is_woocommerce_user int(1) NOT NULL DEFAULT 0,
		first_name varchar(255) NOT NULL DEFAULT '',
		last_name varchar(255) NOT NULL DEFAULT '',
		email varchar(150) NOT NULL,
		status varchar(12) NOT NULL DEFAULT 'unconfirmed',
		subscribed_ip varchar(45) NULL,
		confirmed_ip varchar(45) NULL,
		confirmed_at timestamp NULL,
		last_subscribed_at timestamp NULL,
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		deleted_at timestamp NULL,
		unconfirmed_data longtext,
		source enum('form','imported','administrator','api','wordpress_user','woocommerce_user','woocommerce_checkout','unknown') DEFAULT 'unknown',
		count_confirmations int(11) unsigned NOT NULL DEFAULT 0,
		unsubscribe_token char(15) NULL,
		link_token char(32) NULL,
		engagement_score FLOAT unsigned NULL,
		engagement_score_updated_at timestamp NULL,
		last_engagement_at timestamp NULL,
		woocommerce_synced_at timestamp NULL,
		email_count int(11) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		UNIQUE KEY unsubscribe_token (unsubscribe_token),
		KEY wp_user_id (wp_user_id),
		KEY updated_at (updated_at),
		KEY status_deleted_at (status,deleted_at),
		KEY last_subscribed_at (last_subscribed_at),
		KEY engagement_score_updated_at (engagement_score_updated_at),
		KEY link_token (link_token)
	)",
	"CREATE TABLE {$t_seg} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(90) NOT NULL,
		type varchar(90) NOT NULL DEFAULT 'default',
		description varchar(250) NOT NULL DEFAULT '',
		public_description text NULL,
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		deleted_at timestamp NULL,
		average_engagement_score FLOAT unsigned NULL,
		average_engagement_score_updated_at timestamp NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY name (name),
		KEY average_engagement_score_updated_at (average_engagement_score_updated_at)
	)",
	"CREATE TABLE {$t_link} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		subscriber_id int(11) unsigned NOT NULL,
		segment_id int(11) unsigned NOT NULL,
		status varchar(12) NOT NULL DEFAULT 'subscribed',
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY subscriber_segment (subscriber_id,segment_id),
		KEY segment_id (segment_id)
	)",
	"CREATE TABLE {$t_set} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(50) NOT NULL,
		value longtext,
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY name (name)
	)",
	// MailPoet 4.9 tags (lib/Migrations/Db/Migration_20221028_105818.php in 5.45.2).
	"CREATE TABLE {$t_tag} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(191) NOT NULL,
		description text NOT NULL DEFAULT '',
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY name (name)
	)",
	"CREATE TABLE {$t_st} (
		id int(11) unsigned NOT NULL AUTO_INCREMENT,
		subscriber_id int(11) unsigned NOT NULL,
		tag_id int(11) unsigned NOT NULL,
		created_at timestamp NULL,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY subscriber_tag (subscriber_id,tag_id),
		KEY tag_id (tag_id)
	)",
);
foreach ( $ddl as $sql ) {
	$wpdb->query( $sql ); // phpcs:ignore
}
// MailPoet writes UTC. On MySQL a TIMESTAMP is read in the session's zone.
$wpdb->suppress_errors( true );
$wpdb->query( "SET time_zone = '+00:00'" );
$wpdb->suppress_errors( false );

$lists = array(
	1 => array( 'Walking Group', 'default', null ),
	2 => array( 'Arts, Crafts', 'default', null ),
	3 => array( 'WordPress Users', 'wp_users', null ),
	4 => array( 'Old List', 'default', '2024-01-01 00:00:00' ),
	5 => array( 'Nobody Left', 'default', null ),
);
foreach ( $lists as $id => $l ) {
	$wpdb->insert( $t_seg, array( 'id' => $id, 'name' => $l[0], 'type' => $l[1], 'created_at' => '2018-01-01 00:00:00', 'deleted_at' => $l[2] ) );
}

// id => first, last, email, status, created_at, confirmed_at, deleted_at, lists (segment => per-list status).
$people = array(
	1  => array( 'Ada', 'Lovelace', "ada@$domain", 'subscribed', '2019-04-02 09:15:00', '2019-04-02 09:20:00', null, array( 1 => 'subscribed', 2 => 'subscribed' ) ),
	2  => array( 'Bea', '', "bea@$domain", 'unconfirmed', '2024-06-01 12:00:00', null, null, array( 1 => 'subscribed' ) ),
	3  => array( 'Cy', 'Young', "cy@$domain", 'unsubscribed', '2020-01-10 08:00:00', '2020-01-10 08:05:00', null, array( 1 => 'unsubscribed' ) ),
	4  => array( 'Dee', 'Dee', "dee@$domain", 'bounced', '2021-03-03 03:03:03', '2021-03-03 03:04:00', null, array( 2 => 'subscribed' ) ),
	5  => array( 'Eve', 'Stone', "eve@$domain", 'inactive', '2017-07-07 07:07:07', '2017-07-07 07:08:00', null, array( 2 => 'subscribed' ) ),
	6  => array( 'Fay', 'Trashed', "fay@$domain", 'subscribed', '2022-02-02 02:02:02', '2022-02-02 02:03:00', '2025-05-05 05:05:05', array( 1 => 'subscribed' ) ),
	7  => array( 'Gus', 'Green', "gus@$domain", 'subscribed', '2023-08-08 18:00:00', '2023-08-08 18:01:00', null, array( 1 => 'subscribed', 2 => 'unsubscribed' ) ),
	8  => array( 'Hal', 'Nine', "hal@$domain", 'subscribed', '2016-09-09 09:09:09', '2016-09-09 09:10:00', null, array( 1 => 'subscribed' ) ),
	9  => array( 'Ivy', 'League', "ivy@$domain", 'subscribed', '2018-11-11 11:11:11', '2018-11-11 11:12:00', null, array( 2 => 'subscribed' ) ),
	10 => array( 'No', 'Address', 'not-an-email', 'subscribed', '2022-01-01 00:00:00', null, null, array( 1 => 'subscribed' ) ),
	11 => array( 'Jon', 'Imported', "jon@$domain", 'unsubscribed', '2022-12-12 12:12:12', null, null, array() ),
	12 => array( 'Kim', '', "kim@$domain", 'weird', '2024-04-04 04:04:04', null, null, array() ),
	13 => array( 'Lee', 'History', "lee@$domain", 'subscribed', '2023-01-01 10:00:00', '2015-05-05 05:05:05', null, array( 3 => 'subscribed', 4 => 'subscribed' ) ),
);
foreach ( $people as $id => $p ) {
	$wpdb->insert( $t_sub, array( 'id' => $id, 'first_name' => $p[0], 'last_name' => $p[1], 'email' => $p[2], 'status' => $p[3], 'created_at' => $p[4], 'confirmed_at' => $p[5], 'deleted_at' => $p[6], 'source' => 'form' ) );
	foreach ( $p[7] as $segment => $status ) {
		$wpdb->insert( $t_link, array( 'subscriber_id' => $id, 'segment_id' => $segment, 'status' => $status, 'created_at' => $p[4] ) );
	}
}
// Nobody Left has only people who left it or bounced, so it has no subscribed member.
$wpdb->insert( $t_link, array( 'subscriber_id' => 3, 'segment_id' => 5, 'status' => 'unsubscribed', 'created_at' => '2020-01-10 08:00:00' ) );
$wpdb->insert( $t_link, array( 'subscriber_id' => 4, 'segment_id' => 5, 'status' => 'subscribed', 'created_at' => '2021-03-03 03:03:03' ) );
// Tags: Volunteer on Ada, Hal, and trashed Fay. Board on Ada and Jon. "Lunch, Tuesday" carries a comma.
foreach ( array( 1 => 'Volunteer', 2 => 'Board', 3 => 'Lunch, Tuesday' ) as $tid => $tname ) {
	$wpdb->insert( $t_tag, array( 'id' => $tid, 'name' => $tname, 'description' => '', 'created_at' => '2023-01-01 00:00:00' ) );
}
foreach ( array( array( 1, 1 ), array( 1, 2 ), array( 8, 1 ), array( 6, 1 ), array( 9, 3 ), array( 11, 2 ) ) as $pair ) {
	$wpdb->insert( $t_st, array( 'subscriber_id' => $pair[0], 'tag_id' => $pair[1], 'created_at' => '2023-01-01 00:00:00' ) );
}
$settings_rows = array(
	'sender'              => serialize( array( 'name' => 'Ventura U3A', 'address' => "news@$domain" ) ),
	'reply_to'            => serialize( array( 'name' => 'Membership', 'address' => "members@$domain" ) ),
	'signup_confirmation' => serialize( array( 'enabled' => '', 'subject' => 'Confirm your subscription' ) ),
	'mta'                 => serialize( array( 'method' => 'SMTP', 'host' => 'smtp.example.org', 'login' => 'rig', 'password' => 'rig-smtp-secret' ) ),
	'db_version'          => '5.45.2',
);
foreach ( $settings_rows as $name => $value ) {
	$wpdb->insert( $t_set, array( 'name' => $name, 'value' => $value ) );
}
$mailpoet_rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_sub}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_link}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_seg}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_set}" );
rig_check( 'seeded 13 subscribers', 13, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_sub}" ) );

// Contacts that are already here.
$hal_id = $mm->add_member( array( 'name' => 'Hal Existing', 'email' => "hal@$domain", 'status' => 'unsubscribed', 'source' => 'manual', 'tags' => 'Choir,volunteer' ) );
$ivy_id = $mm->add_member( array( 'name' => '', 'email' => "ivy@$domain", 'status' => 'pending', 'source' => 'form_signup' ) );
$jon_id = $mm->add_member( array( 'name' => 'J. Typed', 'email' => "jon@$domain", 'status' => 'donor', 'source' => 'stripe', 'tags' => 'Major donor' ) );
rig_check( 'three contacts already here', true, $hal_id > 0 && $ivy_id > 0 && $jon_id > 0 );

// Pages with MailPoet forms on them, and a MailPoet widget.
$rig_posts[] = $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Join Us', 'post_content' => 'Join: [mailpoet_form id="1"]' ) );
$rig_posts[] = $post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rig Newsletter News', 'post_content' => '<!-- wp:mailpoet/subscription-form-block {"formId":1} /-->' ) );
$rig_posts[] = $draft_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Rig Draft', 'post_content' => '[mailpoet_form id="1"]' ) );
$rig_posts[] = $plain_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Plain', 'post_content' => 'We used to use MailPoet. [npmp_email_signup]' ) );
$sidebars                  = (array) get_option( 'sidebars_widgets', array() );
$sidebars['rig-sidebar']   = array( 'mailpoet_form-2', 'search-1' );
$sidebars['wp_inactive_widgets'] = array_merge( (array) ( $sidebars['wp_inactive_widgets'] ?? array() ), array( 'mailpoet_form-9' ) );
update_option( 'sidebars_widgets', $sidebars );

// Stub plugins for the deactivate step.
foreach ( $stubs as $rel => $code ) {
	wp_mkdir_p( dirname( WP_PLUGIN_DIR . '/' . $rel ) );
	file_put_contents( WP_PLUGIN_DIR . '/' . $rel, $code );
}
wp_clean_plugins_cache( false );
foreach ( array_keys( $stubs ) as $rel ) {
	$r = activate_plugin( $rel );
	rig_check( "activated stub $rel", false, is_wp_error( $r ) );
}

// ---------------------------------------------------------------------
echo "Step 1: read\n";
// ---------------------------------------------------------------------
rig_check( 'MailPoet reads as active', true, npmp_mailpoet_is_active() );
rig_check( 'importer is visible', true, npmp_mailpoet_importer_visible() );
rig_check( 'subscribers table found', true, npmp_mailpoet_has_table() );
rig_check( '12 subscribers to read, the trashed one left out', 12, npmp_mailpoet_count_subscribers() );
rig_has( 'intro names what stays in MailPoet', 'You leave drafts, automations, stats, and WooCommerce segments in MailPoet.', rig_render( 'npmp_mailpoet_render_import', npmp_mailpoet_get_stage() ) );

$batches = 0;
$restart = true;
do {
	$r       = npmp_mailpoet_stage_batch( $restart, 5 );
	$restart = false;
	++$batches;
} while ( ! $r['done'] && $batches < 20 );
$stage = npmp_mailpoet_get_stage();
rig_check( 'read in three batches of 5', 3, $batches );
rig_check( 'read 12', 12, (int) $stage['read'] );
ksort( $stage['counts'] );
rig_check( 'counts by MailPoet status', array( 'bounced' => 1, 'inactive' => 1, 'other' => 1, 'subscribed' => 6, 'unconfirmed' => 1, 'unsubscribed' => 2 ), $stage['counts'] );
rig_check( '8 new, 3 already here, 1 bad address', array( 8, 3, 1 ), array( $stage['plan']['new'], $stage['plan']['existing'], $stage['plan']['invalid'] ) );
rig_check( '1 already unsubscribed here stays that way', 1, (int) $stage['plan']['stay_unsubbed'] );
rig_check( 'three lists: not the trashed one, not WordPress Users', array( 'Arts, Crafts', 'Nobody Left', 'Walking Group' ), array_values( wp_list_pluck( $stage['lists'], 'name' ) ) );
rig_check( 'Nobody Left counts 0 (Cy left it, Dee bounced)', 0, (int) $stage['lists'][5]['count'] );
rig_check( 'tags read with counts, the trashed subscriber left out', array( 'Board' => 2, 'Lunch, Tuesday' => 1, 'Volunteer' => 2 ), $stage['tags'] );
rig_check( 'Walking Group mails 5 (Ada, Bea, Gus, Hal, bad address; not Cy, not trashed Fay)', 5, (int) $stage['lists'][1]['count'] );
rig_check( 'Arts, Crafts mails 3 (Ada, Eve, Ivy; not Gus, not bounced Dee)', 3, (int) $stage['lists'][2]['count'] );
rig_check( 'sender name read from the serialized row', 'Ventura U3A', $stage['settings']['from_name'] );
rig_check( 'sender address', "news@$domain", $stage['settings']['from_email'] );
rig_check( 'reply-to', "Membership <members@$domain>", $stage['settings']['reply_to'] );
rig_check( 'signup confirmation is off in MailPoet', false, $stage['settings']['double_optin'] );
rig_check( 'SMTP password is nowhere in the staging summary', false, strpos( serialize( $stage ), 'rig-smtp-secret' ) );
rig_check( 'staging summary stays small (sample of 12, not a full list)', 12, count( $stage['sample'] ) );
rig_check( 'no contact created by reading', 3, count( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) ) );

// ---------------------------------------------------------------------
echo "Step 2: review\n";
// ---------------------------------------------------------------------
$review = rig_render( 'npmp_mailpoet_render_review', $stage );
rig_has( 'review shows the bounced row', 'Bounced', $review );
rig_has( 'review says unconfirmed wait as Pending', 'Unconfirmed ones wait as Pending', $review );
rig_has( $pro ? 'review says lists become groups' : 'review says lists need Pro groups', $pro ? 'Each checked list becomes a group with the same name.' : 'Lists become groups, and groups come with Pro. Your contacts still come over without their lists.', $review );
rig_has( 'review shows the tag count', 'You bring 3 MailPoet tags into each contact', $review );
if ( $pro ) {
	rig_has( 'review says the empty list stays behind', '1 list has no subscribed members, so it stays in MailPoet and you get no empty group.', $review );
}
npmp_mailpoet_set_review( npmp_mailpoet_status_keys(), array( 1, 2, 5 ) );
$stage = npmp_mailpoet_get_stage();
rig_check( 'every status checked', array(), $stage['skip_statuses'] );
rig_check( 'the empty list is skipped even when ticked', $pro ? array( 5 => 'Nobody Left' ) : array(), npmp_mailpoet_empty_lists( $stage ) );
rig_check( '12 selected', 12, npmp_mailpoet_selected_count( $stage ) );
rig_check( $pro ? 'two lists selected' : 'no lists move without Pro', $pro ? array( 2 => 'Arts, Crafts', 1 => 'Walking Group' ) : array(), npmp_mailpoet_selected_lists( $stage ) );

// ---------------------------------------------------------------------
echo "Step 3: approve\n";
// ---------------------------------------------------------------------
$plan = npmp_mailpoet_current_plan();
rig_check( 'From name: a change, ticked since nobody saved one here', array( 'change', true ), array( $plan['from_name']['state'], $plan['from_name']['checked'] ) );
rig_check( 'From address: a change, ticked', array( 'change', true ), array( $plan['from_email']['state'], $plan['from_email']['checked'] ) );
rig_check( 'Reply-to: no matching setting', 'unsupported', $plan['reply_to']['state'] );
rig_check( 'Confirmation: MailPoet off, here on, offered but unticked', array( 'change', false ), array( $plan['double_optin']['state'], $plan['double_optin']['checked'] ) );
$approve = rig_render( 'npmp_mailpoet_render_approve', $stage );
rig_has( 'approve shows MailPoet sender', 'Ventura U3A', $approve );
rig_has( 'approve says reply-to has no home', 'No matching setting here, so you skip this one.', $approve );
rig_has( 'approve says credentials stay behind', 'You never copy MailPoet\'s sending method, SMTP password, or API keys.', $approve );
rig_check( 'approve never prints the SMTP password', false, strpos( $approve, 'rig-smtp-secret' ) );

// Tick From name only. From address and confirmation stay as they are.
rig_has( 'approve counts the tags', 'You bring 3 tags into the Tags field.', $approve );
$from_email_before = npmp_email_get_settings()['from_email'];
rig_check( 'approve queues 12', 12, npmp_mailpoet_approve( array( 'from_name', 'reply_to', 'smtp_password' ) ) );
rig_check( 'only known setting keys survive', array( 'from_name' ), npmp_mailpoet_get_stage()['apply_settings'] );
rig_check( 'nothing changed before migrate', false, 'Ventura U3A' === npmp_email_get_settings()['from_name'] );

// ---------------------------------------------------------------------
echo "Step 4: migrate\n";
// ---------------------------------------------------------------------
$hooked = array();
add_action(
	'npmp_import_row_imported',
	function ( $member_id, $record, $row, $mapping, $status, $options ) use ( &$hooked ) {
		$hooked[ $record['email'] ] = array( $status, $record['groups'] ?? '', $options['source'] ?? '' );
	},
	5,
	6
);
$batches  = 0;
$lookups  = array();
$per_row  = 0;
$watch_sql = function ( $query ) use ( &$lookups, &$per_row ) {
	if ( false !== strpos( $query, "meta_key = 'npmp_email'" ) && false !== stripos( $query, ' IN (' ) ) {
		$lookups[ count( $lookups ) ] = 1;
	} elseif ( preg_match( "/meta_key\s*=\s*'npmp_email'\s+AND\s+(LOWER\()?meta_value/i", $query ) ) {
		++$per_row;
	}
	return $query;
};
add_filter( 'query', $watch_sql );
do {
	$r = npmp_mailpoet_migrate_batch( 5 );
	++$batches;
} while ( ! $r['done'] && $batches < 20 );
remove_filter( 'query', $watch_sql );
$stage = npmp_mailpoet_get_stage();
$res   = $stage['results'];
// Subscribed (7 rows) takes two batches, unconfirmed one, everyone else two.
rig_check( 'migrated in five batches, subscribed people first', 5, $batches );
rig_check( 'one batched email lookup per batch at most', true, count( $lookups ) <= $batches && count( $lookups ) > 0, count( $lookups ) );
rig_check( 'no email lookup row by row', 0, $per_row );
rig_check( 'position reached 12', 12, (int) $stage['position'] );
rig_check( '8 created', 8, (int) $res['created'] );
rig_check( '3 updated (Hal date, Ivy status + name + date, Jon status)', 3, (int) $res['updated'] );
rig_check( '1 kept unsubscribed', 1, (int) $res['kept_unsubscribed'] );
rig_check( '1 invalid address left behind', 1, (int) $res['invalid'] );
rig_check( 'no failures, no cap', array( array(), false ), array( $res['failed'], $res['cap_reached'] ) );
rig_check( 'imported counter is 8', 8, (int) get_option( 'npmp_mailpoet_imported' ) );

wp_cache_flush();
$get = function ( $who ) use ( $mm, $domain ) {
	return $mm->get_member_by_email( "$who@$domain" );
};
$ada = $get( 'ada' );
rig_check( 'Ada: subscribed', 'subscribed', $ada ? $ada->status : null );
rig_check( 'Ada: name joined', 'Ada Lovelace', $ada ? $ada->name : null );
rig_check( 'Ada: Added is her MailPoet signup, in site time (BST)', '2019-04-02 10:15:00', $ada ? $ada->created_at : null );
rig_check( 'Ada: post_date_gmt is the UTC MailPoet stored', '2019-04-02 09:15:00', $ada ? get_post_field( 'post_date_gmt', $ada->id ) : null );
rig_check( 'Ada: source is mailpoet', 'mailpoet', $ada ? $ada->source : null );
rig_check( 'Bea: unconfirmed is Pending', 'pending', $get( 'bea' ) ? $get( 'bea' )->status : null );
rig_check( 'Cy: unsubscribed stays unsubscribed', 'unsubscribed', $get( 'cy' ) ? $get( 'cy' )->status : null );
rig_check( 'Dee: bounced is unsubscribed', 'unsubscribed', $get( 'dee' ) ? $get( 'dee' )->status : null );
rig_check( 'Eve: inactive stays inactive', 'inactive', $get( 'eve' ) ? $get( 'eve' )->status : null );
rig_check( 'Fay: trashed in MailPoet, not imported', null, $get( 'fay' ) );
rig_check( 'Kim: unknown status is Pending', 'pending', $get( 'kim' ) ? $get( 'kim' )->status : null );
rig_check( 'Lee: older confirmed_at wins as Added', '2015-05-05 06:05:05', $get( 'lee' ) ? $get( 'lee' )->created_at : null );
$hal = $get( 'hal' );
rig_check( 'Hal: unsubscribed here, subscribed in MailPoet, stays unsubscribed', 'unsubscribed', $hal ? $hal->status : null );
rig_check( 'Hal: same contact, no duplicate', (int) $hal_id, $hal ? (int) $hal->id : 0 );
rig_check( 'Hal: typed name stays', 'Hal Existing', $hal ? $hal->name : null );
$ivy = $get( 'ivy' );
rig_check( 'Ivy: pending here, confirmed in MailPoet, now subscribed', 'subscribed', $ivy ? $ivy->status : null );
rig_check( 'Ivy: blank name filled', 'Ivy League', $ivy ? $ivy->name : null );
rig_check( 'Ivy: Added moved back to 2018', '2018-11-11 11:11:11', $ivy ? $ivy->created_at : null );
rig_check( 'Ivy: source stays form_signup', 'form_signup', $ivy ? $ivy->source : null );
$jon = $get( 'jon' );
rig_check( 'Jon: donor here, unsubscribed in MailPoet, now unsubscribed', 'unsubscribed', $jon ? $jon->status : null );
rig_check( 'Jon: typed name stays', 'J. Typed', $jon ? $jon->name : null );
rig_check( 'Ada: both MailPoet tags', 'Board,Volunteer', $ada ? $ada->tags : null );
rig_check( 'Hal: own tags kept, Volunteer not doubled', 'Choir,volunteer', $hal ? $hal->tags : null );
rig_check( 'Ivy: the comma in a tag name turns into a space', 'Lunch Tuesday', $ivy ? $ivy->tags : null );
rig_check( 'Jon: Board added after his own tag', 'Major donor,Board', $jon ? $jon->tags : null );
rig_check( 'Bea: no tags', '', $get( 'bea' ) ? $get( 'bea' )->tags : null );
rig_check( '11 contacts in all, none doubled', 11, count( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) ) );

echo "Step 4: lists\n";
rig_check( 'the import row hook fired for all 11', 11, count( $hooked ) );
rig_check( 'hook says imported for a new contact, from mailpoet', array( 'imported', 'mailpoet' ), array( $hooked[ "ada@$domain" ][0] ?? '', $hooked[ "ada@$domain" ][2] ?? '' ) );
rig_check( 'hook says updated for an existing one', 'updated', $hooked[ "ivy@$domain" ][0] ?? '' );
if ( $pro ) {
	rig_check( 'Ada carries both lists', 'Walking Group; Arts, Crafts;', $hooked[ "ada@$domain" ][1] ?? '' );
	rig_check( 'Gus carries only the list he is still on', 'Walking Group;', $hooked[ "gus@$domain" ][1] ?? '' );
	rig_check( 'Dee bounced, so no lists', '', $hooked[ "dee@$domain" ][1] ?? 'x' );
	rig_check( 'Hal unsubscribed here, so no lists', '', $hooked[ "hal@$domain" ][1] ?? 'x' );
	rig_check( 'Lee is only on WordPress Users and a trashed list, so no lists', '', $hooked[ "lee@$domain" ][1] ?? 'x' );

	$group_names = function ( $member ) use ( $tax ) {
		$names = $member ? wp_get_object_terms( (int) $member->id, $tax, array( 'fields' => 'names' ) ) : array();
		sort( $names );
		return $names;
	};
	rig_check( 'group Walking Group exists', true, (bool) get_term_by( 'name', 'Walking Group', $tax ) );
	rig_check( 'group "Arts, Crafts" exists as one group', true, (bool) get_term_by( 'name', 'Arts, Crafts', $tax ) );
	rig_check( 'no group named Arts or Crafts', false, get_term_by( 'name', 'Arts', $tax ) || get_term_by( 'name', 'Crafts', $tax ) );
	rig_check( 'no group for the trashed list', false, (bool) get_term_by( 'name', 'Old List', $tax ) );
	rig_check( 'no group for WordPress Users', false, (bool) get_term_by( 'name', 'WordPress Users', $tax ) );
	rig_check( 'no empty group for Nobody Left', false, (bool) get_term_by( 'name', 'Nobody Left', $tax ) );
	rig_has( 'result counts the skipped list', 'You skipped 1 list with no subscribed members.', rig_render( 'npmp_mailpoet_render_migrate', $stage ) );
	rig_check( 'Ada is in both groups', array( 'Arts, Crafts', 'Walking Group' ), $group_names( $ada ) );
	rig_check( 'Bea (pending) is in Walking Group', array( 'Walking Group' ), $group_names( $get( 'bea' ) ) );
	rig_check( 'Cy left the list, so no group', array(), $group_names( $get( 'cy' ) ) );
	rig_check( 'Dee bounced, so no group', array(), $group_names( $get( 'dee' ) ) );
	rig_check( 'Eve is in Arts, Crafts', array( 'Arts, Crafts' ), $group_names( $get( 'eve' ) ) );
	rig_check( 'Gus is only in Walking Group', array( 'Walking Group' ), $group_names( $get( 'gus' ) ) );
	rig_check( 'Hal joined no group', array(), $group_names( $hal ) );
	rig_check( 'Ivy (already here) joined Arts, Crafts', array( 'Arts, Crafts' ), $group_names( $ivy ) );
	rig_check( 'Lee joined no group', array(), $group_names( $get( 'lee' ) ) );
} else {
	rig_check( 'without Pro no row carries lists', array(), array_filter( wp_list_pluck( $hooked, 1 ) ) );
	rig_check( 'without Pro there is no group taxonomy', '', $tax );
	rig_has( 'result says lists stayed in MailPoet', 'Your lists stayed in MailPoet, since groups come with Pro.', rig_render( 'npmp_mailpoet_render_migrate', $stage ) );
}

echo "Step 4: settings\n";
$email_settings = npmp_email_get_settings();
rig_check( 'ticked From name copied', 'Ventura U3A', $email_settings['from_name'] );
rig_check( 'legacy From name option kept in step', 'Ventura U3A', get_option( 'npmp_email_from_name' ) );
rig_check( 'unticked From address untouched', $from_email_before, $email_settings['from_email'] );
rig_check( 'unticked confirmation still on', true, npmp_double_optin_enabled() );
rig_check( 'MailPoet SMTP password not copied', '', (string) $email_settings['smtp']['password'] );
rig_check( 'MailPoet SMTP host not copied', '', (string) $email_settings['smtp']['host'] );
rig_check( 'summary lists what was applied', array( 'from_name' => 'Ventura U3A' ), $stage['settings_applied'] );
$done = rig_render( 'npmp_mailpoet_render_migrate', $stage );
rig_has( 'result screen counts new contacts', 'You added 8 new contacts.', $done );
rig_has( 'result screen names the setting', 'You set From name to Ventura U3A.', $done );

// A value someone saved here is never overwritten without its checkbox.
$plan = npmp_mailpoet_current_plan();
rig_check( 'From name now matches', 'same', $plan['from_name']['state'] );
rig_check( 'From address: saved here now, so it starts unticked', array( 'change', false ), array( $plan['from_email']['state'], $plan['from_email']['checked'] ) );
rig_check( 'applying nothing changes nothing', array(), npmp_mailpoet_apply_settings( array() ) );
rig_check( 'From address still untouched', $from_email_before, npmp_email_get_settings()['from_email'] );
rig_check( 'ticking confirmation copies MailPoet off', array( 'double_optin' => false ), npmp_mailpoet_apply_settings( array( 'double_optin' ) ) );
rig_check( 'confirmation now off, saved in npmp_membership_form_settings', 0, get_option( 'npmp_membership_form_settings' )['double_optin'] ?? null );
rig_check( 'ticking From address copies it', array( 'from_email' => "news@$domain" ), npmp_mailpoet_apply_settings( array( 'from_email' ) ) );
rig_check( 'saved in npmp_email_settings and the legacy option', array( "news@$domain", "news@$domain" ), array( get_option( 'npmp_email_settings' )['from_email'], get_option( 'npmp_email_from_email' ) ) );

// ---------------------------------------------------------------------
echo "Run it again: nothing doubles\n";
// ---------------------------------------------------------------------
npmp_mailpoet_reset_stage();
do {
	$r = npmp_mailpoet_stage_batch( false, 5 );
} while ( ! $r['done'] );
$stage = npmp_mailpoet_get_stage();
rig_check( 'second read: 0 new, 11 already here', array( 0, 11 ), array( $stage['plan']['new'], $stage['plan']['existing'] ) );
npmp_mailpoet_set_review( npmp_mailpoet_status_keys(), array( 1, 2 ) );
npmp_mailpoet_approve( array() );
do {
	$r = npmp_mailpoet_migrate_batch( 5 );
} while ( ! $r['done'] );
$res = npmp_mailpoet_get_stage()['results'];
rig_check( 'second run: 0 created, 0 updated, 11 unchanged', array( 0, 0, 11 ), array( (int) $res['created'], (int) $res['updated'], (int) $res['unchanged'] ) );
wp_cache_flush();
rig_check( 'still 11 contacts', 11, count( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) ) );
rig_check( 'Hal is still unsubscribed', 'unsubscribed', $get( 'hal' )->status );
rig_check( 'Jon is still unsubscribed', 'unsubscribed', $get( 'jon' )->status );
rig_check( 'Ada keeps her 2019 date', '2019-04-02 10:15:00', $get( 'ada' )->created_at );

// Someone who unsubscribes here after the import stays out on the next run.
$mm->update_member( $get( 'ada' )->id, array( 'status' => 'unsubscribed' ) );

// ---------------------------------------------------------------------
echo "Third run: one status unchecked, under a two-row cap\n";
// ---------------------------------------------------------------------
$wpdb->insert( $t_sub, array( 'id' => 14, 'first_name' => 'Max', 'last_name' => 'Late', 'email' => "max@$domain", 'status' => 'unconfirmed', 'created_at' => '2025-01-01 00:00:00', 'source' => 'form' ) );
$wpdb->insert( $t_sub, array( 'id' => 15, 'first_name' => 'Nia', 'last_name' => 'New', 'email' => "nia@$domain", 'status' => 'subscribed', 'created_at' => '2025-02-02 00:00:00', 'confirmed_at' => '2025-02-02 00:01:00', 'source' => 'form' ) );
$cap = function () {
	return 2;
};
add_filter( 'npmp_import_max_rows', $cap, PHP_INT_MAX );
npmp_mailpoet_reset_stage();
do {
	$r = npmp_mailpoet_stage_batch( false, 5 );
} while ( ! $r['done'] );
npmp_mailpoet_set_review( array( 'subscribed', 'inactive', 'unsubscribed', 'bounced', 'other' ), array( 1, 2 ) );
$stage = npmp_mailpoet_get_stage();
rig_check( 'unconfirmed unchecked', array( 'unconfirmed' ), $stage['skip_statuses'] );
$capped_approve = rig_render( 'npmp_mailpoet_render_approve', $stage );
rig_has( 'approve warns about the per-import limit', 'In the free version you can move 2 subscribers in one import.', $capped_approve );
npmp_mailpoet_approve( array() );
do {
	$r = npmp_mailpoet_migrate_batch( 5 );
} while ( ! $r['done'] );
$stage = npmp_mailpoet_get_stage();
rig_check( 'stopped at the cap', true, $stage['results']['cap_reached'] );
rig_check( 'handled exactly 2', 2, (int) $stage['handled'] );
rig_check( 'the two places went to subscribed people (Ada, Gus), not Cy', array( 2, 7 ), array( (int) $stage['position'], (int) $stage['migrate_cursor'] ) );
rig_has( 'approve says who fills the places first', 'You fill your free places with subscribed people first', $capped_approve );
rig_check( 'the cap came before the unconfirmed pass, so nothing was counted as left', 0, (int) $stage['results']['left'] );
rig_has( 'result says the rest are still in MailPoet', 'so the rest are still in MailPoet.', rig_render( 'npmp_mailpoet_render_migrate', $stage ) );
remove_filter( 'npmp_import_max_rows', $cap, PHP_INT_MAX );
wp_cache_flush();
rig_check( 'Ada unsubscribed here after the first import, and the rerun left her out', 'unsubscribed', $get( 'ada' )->status );

// With the cap gone, the unchecked status stays behind and the new one comes in.
npmp_mailpoet_approve( array() );
do {
	$r = npmp_mailpoet_migrate_batch( 5 );
} while ( ! $r['done'] );
wp_cache_flush();
rig_check( 'Max (unconfirmed, unchecked) was not imported', null, $get( 'max' ) );
rig_check( 'Nia (new subscriber) came in', 'subscribed', $get( 'nia' ) ? $get( 'nia' )->status : null );
rig_check( 'Bea was imported earlier and is untouched', 'pending', $get( 'bea' )->status );

// ---------------------------------------------------------------------
echo "Step 5: forms left on the site\n";
// ---------------------------------------------------------------------
$found = wp_list_pluck( npmp_mailpoet_find_form_posts(), 'kinds', 'id' );
rig_check( 'page with the shortcode listed', array( 'shortcode' ), $found[ $page_id ] ?? null );
rig_check( 'post with the block listed', array( 'block' ), $found[ $post_id ] ?? null );
rig_check( 'draft not listed', false, isset( $found[ $draft_id ] ) );
rig_check( 'page without a MailPoet form not listed', false, isset( $found[ $plain_id ] ) );
rig_check( 'one active MailPoet widget counted, the inactive one ignored', 1, npmp_mailpoet_count_form_widgets() );
$content_before = get_post_field( 'post_content', $page_id );
$screen         = rig_render( 'npmp_mailpoet_render_deactivate', $stage );
rig_has( 'last step lists the page', 'Rig Join Us', $screen );
rig_has( 'last step names the shortcode to use', '[npmp_email_signup]', $screen );
rig_has( 'last step promises nothing is deleted', 'You delete nothing here.', $screen );
ob_start();
npmp_mailpoet_render_forms_list();
rig_has( 'each one has an edit link', 'post.php?post=' . $page_id . '&', ob_get_clean() );
rig_check( 'page content not rewritten', $content_before, get_post_field( 'post_content', $page_id ) );

// ---------------------------------------------------------------------
echo "Step 5: deactivate\n";
// ---------------------------------------------------------------------
$off = npmp_mailpoet_plugins_to_deactivate();
rig_check( 'add-on first, MailPoet last', array( 'mailpoet-premium/mailpoet-premium.php', 'mailpoet/mailpoet.php' ), array_keys( $off ) );
rig_check( 'this plugin is not on the list', false, isset( $off['nonprofit-manager/nonprofit-manager.php'] ) );
$turned_off = npmp_mailpoet_deactivate();
rig_check( 'two plugins turned off', 2, count( $turned_off ) );
rig_check( 'MailPoet inactive', false, is_plugin_active( 'mailpoet/mailpoet.php' ) );
rig_check( 'MailPoet Premium inactive', false, is_plugin_active( 'mailpoet-premium/mailpoet-premium.php' ) );
rig_check( 'Nonprofit Manager still active', true, is_plugin_active( 'nonprofit-manager/nonprofit-manager.php' ) );
rig_check( 'stub plugin files still on disk (nothing deleted)', true, file_exists( WP_PLUGIN_DIR . '/mailpoet/mailpoet.php' ) );
rig_check( 'nothing left to turn off', array(), npmp_mailpoet_plugins_to_deactivate() );
rig_has( 'screen says MailPoet is off and keeps its data', 'MailPoet is off. Its subscribers, lists, emails, and settings are still in your database', rig_render( 'npmp_mailpoet_render_deactivate', npmp_mailpoet_get_stage() ) );

echo "MailPoet's data is untouched\n";
$mailpoet_rows_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_sub}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_link}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_seg}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_set}" );
rig_check( 'same row count plus the two this script added', $mailpoet_rows_before + 2, $mailpoet_rows_after );
rig_check( 'Hal is still subscribed in MailPoet', 'subscribed', $wpdb->get_var( "SELECT status FROM {$t_sub} WHERE id = 8" ) );
rig_check( 'Fay is still in MailPoet\'s trash', 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_sub} WHERE id = 6 AND deleted_at IS NOT NULL" ) );
rig_check( 'MailPoet sender row unchanged', $settings_rows['sender'], $wpdb->get_var( "SELECT value FROM {$t_set} WHERE name = 'sender'" ) );
rig_check( 'the importer still reads with MailPoet off', 14, npmp_mailpoet_count_subscribers() );

echo "The whole screen renders\n";
ob_start();
npmp_mailpoet_render_page();
$page = ob_get_clean();
rig_has( 'page title', 'Import from MailPoet', $page );
rig_has( 'step line', 'Step 5 of 5: Turn off MailPoet', wp_strip_all_tags( $page ) );
ob_start();
npmp_mailpoet_import_screen_card();
rig_has( 'Import screen card', 'Switching from MailPoet?', ob_get_clean() );
set_current_screen( 'membership_page_npmp_members' );
ob_start();
npmp_mailpoet_admin_notice();
$notice = ob_get_clean();
rig_has( 'notice on the member list offers to finish switching', 'Finish switching', $notice );
rig_has( 'and links to the Switch Plugins hub', 'page=npmp_import_hub', $notice );

// ---------------------------------------------------------------------
// Clean up.
// ---------------------------------------------------------------------
$sweep();
foreach ( $keep as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value );
	}
}
rig_check( 'tables dropped', false, $t_sub === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t_sub ) ) ) );
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
