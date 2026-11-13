<?php
/**
 * Real-WordPress run of The Newsletter Plugin importer on the real plugin:
 * seed subscribers, lists, and settings through Newsletter's own code, import
 * with a narrow free total, check every contact, status, date, list, and
 * setting, import again with room, turn Newsletter off through Switch
 * Plugins, and import once more with it off.
 *
 * Seeds (one domain, rig-tnp.example.org):
 *   ada  confirmed, on Rig Members, joined 2024-01-15 10:00 UTC
 *   ben  not confirmed, on Rig Board
 *   cal  unsubscribed
 *   dee  bounced
 *   eve  complained (marked as spam), past the free limit on the first run
 *   tim  temporary (a signup that never finished, stays behind)
 *   old  confirmed, already a contact who unsubscribed here (stays unsubscribed)
 *   pen  confirmed, already a contact waiting as Pending (becomes subscribed)
 *
 * Needs a throwaway WordPress with this plugin and Newsletter (9.5.7 verified)
 * active, Pro inactive, `wp` on PATH. Visit wp-admin once so Newsletter makes
 * its tables. Verified on SQLite:
 *   wp plugin install newsletter --activate
 *   wp eval-file tests/rig/newsletter-importer.php
 *
 * It turns Newsletter off at the end and puts your sender and signup settings
 * back. Turn Newsletter on again for another run.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';
$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );
$domain = 'rig-tnp.example.org';

// ---------------------------------------------------------------------
// Child phases.
// ---------------------------------------------------------------------
if ( 0 === strpos( $phase, 'import:' ) ) {
	$settings = 'fresh' === substr( $phase, 7 ) ? array( 'from_name', 'from_email', 'double_optin' ) : array();
	$lists    = array();
	$mails    = 0;
	add_action( 'npmp_import_row_imported', function ( $id, $record, $raw ) use ( &$lists ) {
		$lists[ $record['email'] ] = array( $raw['lists'] ?? null, $record['groups'] ?? '', $raw['source_id'] ?? null );
	}, 5, 3 );
	add_filter( 'pre_wp_mail', function () use ( &$mails ) {
		++$mails;
		return true;
	} );
	// The parent narrows the free total with NPMP_IMPORT_FREE_MAX_ROWS, which
	// is also the row limit for one job. Keep the limit on but wide, so on a
	// new site with no contacts the job still reads every rig row.
	add_filter(
		'npmp_import_max_rows',
		static function () {
			return 100000;
		}
	);
	npmp_tnp_start( array(), $settings );
	$state = npmp_tnp_run( 60, 3 );
	echo 'JSON ', wp_json_encode( array( 'state' => $state, 'lists' => $lists, 'mails' => $mails, 'left' => npmp_import_free_remaining(), 'active' => is_plugin_active( 'newsletter/plugin.php' ) ) ), "\n";
	return;
}

if ( 'hub' === $phase ) {
	$sources = npmp_import_sources();
	$src     = $sources['newsletter'] ?? array();
	$verify  = npmp_import_hub_run_step( 'newsletter', 'verify', array( 'verified' => 1 ) );
	$off     = npmp_import_hub_run_step( 'newsletter', 'deactivate' );
	echo 'JSON ', wp_json_encode(
		array(
			'registered' => isset( $sources['newsletter'] ),
			'has_batch'  => isset( $src['data_removal']['batch'] ),
			'reason'     => (string) ( $src['data_removal']['reason'] ?? '' ),
			'rows'       => npmp_import_hub_call( $src['counts'] ?? null, null ),
			'summary'    => npmp_import_hub_call( $src['import_summary'] ?? null, '' ),
			'verify'     => is_wp_error( $verify ) ? $verify->get_error_code() : 'ok',
			'deactivate' => is_wp_error( $off ) ? $off->get_error_code() : 'ok',
			'active'     => is_plugin_active( 'newsletter/plugin.php' ),
		)
	), "\n";
	return;
}

$child = function ( $phase, $before = '' ) {
	$cmd = sprintf(
		'wp --path=%s %s eval-file %s %s 2>&1',
		escapeshellarg( untrailingslashit( ABSPATH ) ),
		'' !== $before ? '--exec=' . escapeshellarg( $before ) : '',
		escapeshellarg( __FILE__ ),
		escapeshellarg( $phase )
	);
	$out = array();
	exec( $cmd, $out );
	// The child changed options and contacts behind this process's caches.
	wp_cache_flush();
	foreach ( $out as $line ) {
		if ( 0 === strpos( $line, 'JSON ' ) ) {
			return json_decode( substr( $line, 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === stripos( $l, 'deprecated' );
	} ) ) );
};

echo "Setup\n";
$ok( 'Newsletter is active', is_plugin_active( 'newsletter/plugin.php' ) );
$ok( 'Pro is inactive', ! is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) );
$ok( 'subscriber table exists', '' !== npmp_tnp_table() );
$ok( 'The Newsletter Plugin is on Switch Plugins', isset( npmp_import_sources()['newsletter'] ) );

// Save your settings to put back at the end, then clear the ones the rig checks.
$saved_email = get_option( 'npmp_email_settings' );
$saved_forms = get_option( 'npmp_membership_form_settings' );
$saved_name  = get_option( 'npmp_email_from_name' );
$saved_addr  = get_option( 'npmp_email_from_email' );
$email       = is_array( $saved_email ) ? $saved_email : array();
unset( $email['from_name'], $email['from_email'] );
update_option( 'npmp_email_settings', $email );
delete_option( 'npmp_email_from_name' );
delete_option( 'npmp_email_from_email' );
$forms = is_array( $saved_forms ) ? $saved_forms : array();
$forms['double_optin'] = 0;
update_option( 'npmp_membership_form_settings', $forms );

// Clean slate for this domain.
$members = NPMP_Member_Manager::get_instance();
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) as $old_id ) {
	wp_delete_post( (int) $old_id, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}newsletter WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
delete_option( 'npmp_tnp_import' );
delete_option( 'npmp_tnp_imported' );
$hub = get_option( 'npmp_import_hub', array() );
unset( $hub['newsletter'] );
update_option( 'npmp_import_hub', $hub );

// ---------------------------------------------------------------------
// Seed through Newsletter's own code.
// ---------------------------------------------------------------------
$lists_option = get_option( 'newsletter_lists', array() );
$lists_option = is_array( $lists_option ) ? $lists_option : array();
update_option( 'newsletter_lists', array_merge( $lists_option, array( 'list_1' => 'Rig Members', 'list_1_status' => 1, 'list_2' => 'Rig Board', 'list_2_status' => 1 ) ), true );
$main = get_option( 'newsletter_main', array() );
update_option( 'newsletter_main', array_merge( is_array( $main ) ? $main : array(), array( 'sender_name' => 'Rig Club', 'sender_email' => 'news@' . $domain, 'reply_to' => 'help@' . $domain ) ), true );
$sub = get_option( 'newsletter_subscription', array() );
update_option( 'newsletter_subscription', array_merge( is_array( $sub ) ? $sub : array(), array( 'noconfirmation' => 0 ) ), true );

$tnp  = Newsletter::instance();
// created is a TIMESTAMP, so MySQL reads ada's literal in the session time
// zone. Seed in UTC, then put the session back before the import runs, so a
// server on SYSTEM time (PST on this Mac) still proves the importer reads the
// instant, not the wall clock.
$rig_session_tz = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
$wpdb->query( "SET time_zone = '+00:00'" );
$seed = array(
	'ada' => array( 'C', 'Ada', 'Lovelace', array( 'list_1' => 1 ), '2024-01-15 10:00:00' ),
	'ben' => array( 'S', 'Ben', '', array( 'list_2' => 1 ), '' ),
	'cal' => array( 'U', 'Cal', 'Ray', array( 'list_1' => 1 ), '' ),
	'dee' => array( 'B', 'Dee', '', array(), '' ),
	'eve' => array( 'P', 'Eve', '', array(), '' ),
	'tim' => array( 'T', 'Tim', '', array(), '' ),
	'old' => array( 'C', 'Old', 'Friend', array(), '' ),
	'pen' => array( 'C', 'Pen', 'Pal', array(), '' ),
);
foreach ( $seed as $key => $s ) {
	$user = array_merge(
		array(
			'email'   => $key . '@' . $domain,
			'name'    => $s[1],
			'surname' => $s[2],
			'status'  => $s[0],
		),
		$s[3]
	);
	if ( '' !== $s[4] ) {
		$user['created'] = $s[4];
	}
	$tnp->save_user( $user );
}
$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', $rig_session_tz ) );
$ok( 'Newsletter saved 8 subscribers', 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}newsletter WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );
$ok( 'Newsletter reads the lists back', isset( $tnp->get_lists()['1'] ) && 'Rig Members' === $tnp->get_lists()['1']->name );
$ok( 'Newsletter reads double opt-in back', $tnp->get_option( 'noconfirmation', 'subscription' ) == 0 ); // phpcs:ignore Universal.Operators.StrictComparisons -- Newsletter's own loose check.

$ok( 'old is a contact who unsubscribed', is_int( $members->add_member( array( 'name' => 'Old Friend', 'email' => 'old@' . $domain, 'status' => 'unsubscribed', 'source' => 'manual' ) ) ) );
$ok( 'pen is a contact waiting as Pending', is_int( $members->add_member( array( 'name' => '', 'email' => 'pen@' . $domain, 'status' => 'pending', 'source' => 'manual' ) ) ) );

// Rows outside this rig (Newsletter adds the admin at setup) count too.
$table      = $wpdb->prefix . 'newsletter';
$all_rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status <> 'T'" );
$extra_new  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} s WHERE s.status <> 'T' AND s.email NOT LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.meta_key = 'npmp_email' AND LOWER(pm.meta_value) = LOWER(s.email) )", '%@' . $wpdb->esc_like( $domain ) ) );
$extra_rows = $all_rows - 7;
// Contacts from Newsletter outside this rig, made by an earlier run.
$other_tnp = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} src INNER JOIN {$wpdb->postmeta} em ON em.post_id = src.post_id AND em.meta_key = 'npmp_email' WHERE src.meta_key = 'npmp_source' AND src.meta_value = 'newsletter' AND em.meta_value NOT LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );

// ---------------------------------------------------------------------
// First run, with room for four new rig contacts.
// ---------------------------------------------------------------------
$used = npmp_import_free_used( true ) + $extra_new;
echo "First run (4 places left)\n";
$r   = $child( 'import:fresh', sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 4 ) );
$res = $r['state']['results'] ?? array();
$ok( 'run finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( '4 new rig contacts', 4 + $extra_new === (int) ( $res['created'] ?? -1 ), $res );
$ok( '1 past the free limit', 1 === (int) ( $res['over_limit'] ?? -1 ), $res );
$ok( 'pen updated', 1 === (int) ( $res['updated'] ?? -1 ), $res );
$ok( 'old kept unsubscribed', 1 === (int) ( $res['kept_unsubscribed'] ?? -1 ), $res );
$ok( 'no places left', 0 === (int) ( $r['left'] ?? -1 ), (string) ( $r['left'] ?? '' ) );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ), (string) ( $r['mails'] ?? '' ) );
$ok( 'three settings copied', array( 'from_name', 'from_email', 'double_optin' ) === array_keys( (array) ( $res['settings'] ?? array() ) ), $res['settings'] ?? '' );
$ok( 'old unchanged', ( 1 + $extra_rows - $extra_new ) === (int) ( $res['unchanged'] ?? -1 ) || 1 === (int) ( $res['unchanged'] ?? -1 ), $res );

$get = function ( $key ) use ( $members, $domain ) {
	return $members->get_member_by_email( $key . '@' . $domain );
};
$ada = $get( 'ada' );
$ok( 'ada is subscribed', $ada && 'subscribed' === $ada->status, $ada ? $ada->status : 'missing' );
$ok( 'ada has her full name', $ada && 'Ada Lovelace' === $ada->name );
// Newsletter keeps UTC, a contact keeps the site's own time.
$ok( 'ada keeps her signup date', $ada && 0 === strpos( (string) $ada->created_at, get_date_from_gmt( '2024-01-15 10:00:00', 'Y-m-d H:i' ) ), $ada ? $ada->created_at : '' );
$ok( 'ada came from The Newsletter Plugin', $ada && 'newsletter' === $ada->source );
$ok( 'ada list reaches the import hook', array( 'Rig Members' ) === ( $r['lists'][ 'ada@' . $domain ][0] ?? null ), $r['lists'][ 'ada@' . $domain ] ?? '' );
$ok( 'ada groups cell for Pro', 'Rig Members;' === ( $r['lists'][ 'ada@' . $domain ][1] ?? null ) );
$ok( 'the hook gets the Newsletter ID', is_int( $r['lists'][ 'ada@' . $domain ][2] ?? null ) );
$ben = $get( 'ben' );
$ok( 'ben waits as pending', $ben && 'pending' === $ben->status, $ben ? $ben->status : 'missing' );
$ok( 'ben is on Rig Board', array( 'Rig Board' ) === ( $r['lists'][ 'ben@' . $domain ][0] ?? null ) );
$cal = $get( 'cal' );
$ok( 'cal is unsubscribed', $cal && 'unsubscribed' === $cal->status );
$ok( 'cal joins no group', '' === ( $r['lists'][ 'cal@' . $domain ][1] ?? 'x' ) );
$dee = $get( 'dee' );
$ok( 'bounced dee is unsubscribed', $dee && 'unsubscribed' === $dee->status );
$ok( 'eve is past the limit', ! $get( 'eve' ) );
$ok( 'temporary tim stays behind', ! $get( 'tim' ) );
$old = $get( 'old' );
$ok( 'old stays unsubscribed', $old && 'unsubscribed' === $old->status );
$ok( 'old keeps where they came from', $old && 'manual' === $old->source );
$pen = $get( 'pen' );
$ok( 'pending pen becomes subscribed', $pen && 'subscribed' === $pen->status, $pen ? $pen->status : 'missing' );
$ok( 'pen gets a name', $pen && 'Pen Pal' === $pen->name );

echo "Settings\n";
$now = function_exists( 'npmp_email_get_settings' ) ? npmp_email_get_settings() : array();
$ok( 'sender name copied', 'Rig Club' === ( $now['from_name'] ?? '' ), $now['from_name'] ?? '' );
$ok( 'sender email copied', 'news@' . $domain === ( $now['from_email'] ?? '' ) );
$forms_now = get_option( 'npmp_membership_form_settings', array() );
$ok( 'double opt-in turned on', ! empty( $forms_now['double_optin'] ) );
$plan = npmp_tnp_settings_plan();
$ok( 'reply-to shows as no match', 'unsupported' === ( $plan['reply_to']['state'] ?? '' ) );
$ok( 'sender now the same', 'same' === ( $plan['from_name']['state'] ?? '' ) && 'same' === ( $plan['from_email']['state'] ?? '' ) );

echo "Second run with room\n";
$r   = $child( 'import:again' );
$res = $r['state']['results'] ?? array();
$ok( 'eve comes over', 1 === (int) ( $res['created'] ?? -1 ) && 0 === (int) ( $res['over_limit'] ?? -1 ), $res );
$eve = $get( 'eve' );
$ok( 'complained eve is unsubscribed', $eve && 'unsubscribed' === $eve->status );
$ok( 'nobody doubled', 1 === count( get_posts( array( 'post_type' => 'npmp_contact', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'npmp_email', 'meta_value' => 'ada@' . $domain ) ) ) );
$ok( 'no settings copied this time', array() === ( $res['settings'] ?? null ), $res['settings'] ?? '' );

echo "Switch Plugins\n";
$h = $child( 'hub' );
$ok( 'registered', ! empty( $h['registered'] ), $h );
$ok( 'step 5 unavailable', empty( $h['has_batch'] ) && '' !== ( $h['reason'] ?? '' ), $h );
$ok( 'counts: every subscriber but the temporary one', $all_rows === (int) ( $h['rows'][0]['old'] ?? -1 ), $h['rows'][0] ?? $h );
$ok( 'counts: 5 new rig contacts', 5 + $extra_new + $other_tnp === (int) ( $h['rows'][0]['new'] ?? -1 ), $h['rows'][0] ?? '' );
$ok( 'counts: nobody left out of contacts', 0 === (int) ( $h['rows'][1]['old'] ?? -1 ), $h['rows'][1] ?? '' );
$ok( 'counts: 2 lists', 2 <= (int) ( $h['rows'][2]['old'] ?? -1 ), $h['rows'][2] ?? '' );
$ok( 'summary names the count', false !== strpos( (string) ( $h['summary'] ?? '' ), (string) ( 5 + $extra_new + $other_tnp ) ), $h['summary'] ?? '' );
$ok( 'verified', 'ok' === ( $h['verify'] ?? '' ), $h );
$ok( 'Newsletter turned off', 'ok' === ( $h['deactivate'] ?? '' ) && false === ( $h['active'] ?? true ), $h );
$ok( 'Newsletter subscribers still in the database', 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}newsletter WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );

echo "Import with Newsletter off\n";
$r   = $child( 'import:again' );
$res = $r['state']['results'] ?? array();
$ok( 'reads with Newsletter off and adds nobody', false === ( $r['active'] ?? true ) && 0 === (int) ( $res['created'] ?? -1 ) && $all_rows === (int) ( $res['updated'] ?? 0 ) + (int) ( $res['unchanged'] ?? 0 ), $res );

// Put your settings back.
update_option( 'npmp_email_settings', $saved_email );
update_option( 'npmp_membership_form_settings', $saved_forms );
false === $saved_name ? delete_option( 'npmp_email_from_name' ) : update_option( 'npmp_email_from_name', $saved_name );
false === $saved_addr ? delete_option( 'npmp_email_from_email' ) : update_option( 'npmp_email_from_email', $saved_addr );

// Leave no rig contacts or subscribers behind (import-cap-total counts imported contacts).
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) as $old_id ) {
	wp_delete_post( (int) $old_id, true );
}
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'newsletter' ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}newsletter WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
}
delete_option( 'npmp_tnp_import' );
delete_option( 'npmp_tnp_imported' );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
