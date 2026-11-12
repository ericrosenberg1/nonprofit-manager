<?php
/**
 * Real-WordPress run of the Paid Memberships Pro importer on the real plugin:
 * seed levels and members through Paid Memberships Pro's own functions, import
 * with a narrow free total, check every contact, status, level, date, and
 * address, import again with room, turn Paid Memberships Pro off through
 * Switch Plugins, and import once more with it off.
 *
 * Seeds (one domain, rig-pmpro.example.org, levels named "Rig ..."):
 *   ada  current Rig Gold, renews monthly, joined 2023-03-01 12:00 site time, city and phone
 *   ben  current Rig Silver, no first or last name, display name only
 *   cal  past member (Rig Gold, expired)
 *   dee  current Rig Silver and Rig Board (two groups), Board is newer
 *   eve  current Rig Silver, past the free limit on the first run
 *   old  current Rig Gold, already a contact who unsubscribed here with a level set by hand
 *   pen  current Rig Silver, already a contact waiting as Pending with no level
 * "Rig Silver" is already one of your membership levels, so it isn't added twice.
 *
 * Needs a throwaway WordPress with this plugin and Paid Memberships Pro
 * (3.9.1 from GitHub verified, it left wordpress.org) active, Pro inactive,
 * `wp` on PATH. Verified on SQLite, where Paid Memberships Pro's own reads
 * (CONVERT_TZ() with @@global.time_zone) fail, so the rig seeds with its
 * writers and checks its tables directly. The importer never calls them.
 *   wp plugin activate paid-memberships-pro
 *   wp eval-file tests/rig/pmpro-importer.php
 *
 * It turns Paid Memberships Pro off at the end and puts your membership levels
 * and time zone back. Turn it on again for another run.
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
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );
$domain = 'rig-pmpro.example.org';

// ---------------------------------------------------------------------
// Child phases.
// ---------------------------------------------------------------------
if ( 'import' === $phase ) {
	$hook  = array();
	$mails = 0;
	add_action( 'npmp_import_row_imported', function ( $id, $record, $raw, $x, $status ) use ( &$hook ) {
		$hook[ $record['email'] ] = array( $record['membership_level'] ?? null, $raw['source_id'] ?? null, $status );
	}, 5, 5 );
	add_filter( 'pre_wp_mail', function () use ( &$mails ) {
		++$mails;
		return true;
	} );
	npmp_pmpro_start( array() );
	$state = npmp_pmpro_run( 60, 3 );
	echo 'JSON ', wp_json_encode( array( 'state' => $state, 'hook' => $hook, 'mails' => $mails, 'left' => npmp_import_free_remaining(), 'active' => is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) ) ), "\n";
	return;
}

if ( 'hub' === $phase ) {
	$sources = npmp_import_sources();
	$src     = $sources['pmpro'] ?? array();
	$step4   = function () use ( $src ) {
		ob_start();
		npmp_import_hub_render_step_remove_plugin( $src, array(), 'next', true );
		return wp_strip_all_tags( (string) ob_get_clean() );
	};
	$saved_uninstall = get_option( 'pmpro_uninstall' );
	update_option( 'pmpro_uninstall', 0 );
	$step4_off = $step4();
	update_option( 'pmpro_uninstall', 1 );
	$step4_on = $step4();
	false === $saved_uninstall ? delete_option( 'pmpro_uninstall' ) : update_option( 'pmpro_uninstall', $saved_uninstall );
	$verify  = npmp_import_hub_run_step( 'pmpro', 'verify', array( 'verified' => 1 ) );
	$off     = npmp_import_hub_run_step( 'pmpro', 'deactivate' );
	echo 'JSON ', wp_json_encode(
		array(
			'registered' => isset( $sources['pmpro'] ),
			'has_batch'  => isset( $src['data_removal']['batch'] ),
			'reason'     => (string) ( $src['data_removal']['reason'] ?? '' ),
			'step4_off'  => $step4_off,
			'step4_on'   => $step4_on,
			'pro_parts'  => $src['pro_parts'] ?? array(),
			'installed'  => npmp_import_hub_call( $src['installed_plugins'] ?? null, array() ),
			'rows'       => npmp_import_hub_call( $src['counts'] ?? null, null ),
			'summary'    => npmp_import_hub_call( $src['import_summary'] ?? null, '' ),
			'verify'     => is_wp_error( $verify ) ? $verify->get_error_code() : 'ok',
			'deactivate' => is_wp_error( $off ) ? $off->get_error_code() : 'ok',
			'active'     => is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ),
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
$ok( 'Paid Memberships Pro is active', is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) );
$ok( 'Pro is inactive', ! is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) );
$ok( 'membership tables exist', npmp_pmpro_has_tables() );
$ok( 'Paid Memberships Pro is on Switch Plugins', isset( npmp_import_sources()['pmpro'] ) );

$saved_levels = get_option( 'npmp_membership_levels' );
$saved_tz     = get_option( 'timezone_string' );
$saved_offset = get_option( 'gmt_offset' );
// A site zone off UTC, so a start date read in the wrong zone shows up.
update_option( 'timezone_string', 'America/Los_Angeles' );

// Clean slate for this domain and the Rig levels.
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) as $old_id ) {
	wp_delete_post( (int) $old_id, true );
}
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) as $uid ) {
	$wpdb->delete( $wpdb->prefix . 'pmpro_memberships_users', array( 'user_id' => (int) $uid ) );
	wp_delete_user( (int) $uid );
}
foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name LIKE 'Rig %'" ) as $lid ) {
	$wpdb->delete( $wpdb->pmpro_membership_levels_groups, array( 'level' => (int) $lid ) );
	$wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => (int) $lid ) );
}
$wpdb->query( "DELETE FROM {$wpdb->pmpro_groups} WHERE name LIKE 'Rig %'" );
$wpdb->delete( $wpdb->pmpro_memberships_users, array( 'user_id' => 999999 ) );
delete_option( 'npmp_pmpro_import' );
delete_option( 'npmp_pmpro_imported' );
$hub = get_option( 'npmp_import_hub', array() );
unset( $hub['pmpro'] );
update_option( 'npmp_import_hub', $hub );
$base_levels = array_values( array_filter( (array) ( is_array( $saved_levels ) ? $saved_levels : array() ), function ( $l ) {
	return 0 !== stripos( (string) $l, 'Rig ' );
} ) );
update_option( 'npmp_membership_levels', array_merge( $base_levels, array( 'Rig Silver' ) ) );

// ---------------------------------------------------------------------
// Seed through Paid Memberships Pro's own functions.
// ---------------------------------------------------------------------
$level = function ( $name, $billing ) use ( $wpdb ) {
	pmpro_insert_or_replace(
		$wpdb->pmpro_membership_levels,
		array(
			'id'                => 0,
			'name'              => $name,
			'description'       => '',
			'confirmation'      => '',
			'initial_payment'   => $billing ? 10 : 25,
			'billing_amount'    => $billing ? 10 : 0,
			'cycle_number'      => $billing ? 1 : 0,
			'cycle_period'      => 'Month',
			'billing_limit'     => 0,
			'trial_amount'      => 0,
			'trial_limit'       => 0,
			'expiration_number' => 0,
			'expiration_period' => '',
			'allow_signups'     => 1,
		),
		array( '%d', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%d', '%f', '%d', '%d', '%s', '%d' )
	);
	return (int) $wpdb->insert_id;
};
$gold   = $level( 'Rig Gold', true );
$silver = $level( 'Rig Silver', false );
$board  = $level( 'Rig Board', false );
$g1     = pmpro_create_level_group( 'Rig Dues', false );
$g2     = pmpro_create_level_group( 'Rig Roles', true );
pmpro_add_level_to_group( $gold, $g1 );
pmpro_add_level_to_group( $silver, $g1 );
pmpro_add_level_to_group( $board, $g2 );
$ok( 'three Rig levels', $gold && $silver && $board && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels} WHERE name LIKE 'Rig %'" ) );

$users = array();
$seed  = array(
	'ada' => array( 'Ada', 'Lovelace', '' ),
	'ben' => array( '', '', 'Ben Bright' ),
	'cal' => array( 'Cal', 'Ray', '' ),
	'dee' => array( 'Dee', 'Dot', '' ),
	'eve' => array( 'Eve', 'Ning', '' ),
	'old' => array( 'Old', 'Friend', '' ),
	'pen' => array( 'Pen', 'Pal', '' ),
);
foreach ( $seed as $key => $s ) {
	$users[ $key ] = wp_insert_user(
		array(
			'user_login'   => 'rigpmpro_' . $key,
			'user_email'   => $key . '@' . $domain,
			'user_pass'    => wp_generate_password( 24 ),
			'first_name'   => $s[0],
			'last_name'    => $s[1],
			'display_name' => '' !== $s[2] ? $s[2] : 'rigpmpro_' . $key,
			'role'         => 'subscriber',
		)
	);
}
$ok( 'seven users', 7 === count( array_filter( $users, 'is_int' ) ), $users );
update_user_meta( $users['ada'], 'pmpro_bcity', 'Ventura' );
update_user_meta( $users['ada'], 'pmpro_bphone', '805-555-0100' );
update_user_meta( $users['old'], 'pmpro_bcity', 'Oxnard' );

$join = function ( $key, $level_id, $start, $billing = false ) use ( $users ) {
	return pmpro_changeMembershipLevel(
		array(
			'user_id'         => $users[ $key ],
			'membership_id'   => $level_id,
			'code_id'         => 0,
			'initial_payment' => $billing ? 10 : 25,
			'billing_amount'  => $billing ? 10 : 0,
			'cycle_number'    => $billing ? 1 : 0,
			'cycle_period'    => 'Month',
			'billing_limit'   => 0,
			'trial_amount'    => 0,
			'trial_limit'     => 0,
			'startdate'       => $start,
			'enddate'         => '0000-00-00 00:00:00',
		),
		$users[ $key ]
	);
};
$ok( 'ada joins Rig Gold, renewing', false !== $join( 'ada', $gold, '2023-03-01 12:00:00', true ) );
$ok( 'ben joins Rig Silver', false !== $join( 'ben', $silver, '2024-02-01 00:00:00' ) );
$ok( 'cal joins Rig Gold', false !== $join( 'cal', $gold, '2022-05-01 00:00:00' ) );
// pmpro_cancelMembershipLevel() first reads the user's levels with MySQL's
// CONVERT_TZ(), which SQLite can't parse, so the rig writes the expiry the
// way that function does: the same update on the same row.
$ok( 'cal expires', 1 === $wpdb->update( $wpdb->pmpro_memberships_users, array( 'status' => 'expired', 'enddate' => current_time( 'mysql' ) ), array( 'user_id' => $users['cal'], 'membership_id' => $gold, 'status' => 'active' ), array( '%s', '%s' ) ) );
$ok( 'dee joins Rig Silver', false !== $join( 'dee', $silver, '2024-01-01 00:00:00' ) );
$ok( 'dee joins Rig Board too', false !== $join( 'dee', $board, '2025-01-01 00:00:00' ) );
$ok( 'eve joins Rig Silver', false !== $join( 'eve', $silver, '2024-03-01 00:00:00' ) );
$ok( 'old joins Rig Gold', false !== $join( 'old', $gold, '2024-04-01 00:00:00' ) );
$ok( 'pen joins Rig Silver', false !== $join( 'pen', $silver, '2024-05-01 00:00:00' ) );
$active = function ( $key ) use ( $wpdb, $users ) {
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND status = 'active' ORDER BY id", $users[ $key ] ) ) );
};
$ok( 'ada holds Rig Gold', array( $gold ) === $active( 'ada' ) );
$ok( 'cal holds nothing', array() === $active( 'cal' ) );
$ok( 'dee holds two levels', array( $silver, $board ) === $active( 'dee' ), $active( 'dee' ) );

$members = NPMP_Member_Manager::get_instance();
$ok( 'old is a contact who unsubscribed, with a level set by hand', is_int( $members->add_member( array( 'name' => 'Old Friend', 'email' => 'old@' . $domain, 'status' => 'unsubscribed', 'source' => 'manual', 'membership_level' => 'Board Chair' ) ) ) );
$ok( 'pen is a contact waiting as Pending', is_int( $members->add_member( array( 'name' => '', 'email' => 'pen@' . $domain, 'status' => 'pending', 'source' => 'manual' ) ) ) );

// Members outside this rig count too.
$mu        = $wpdb->pmpro_memberships_users;
$all_users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT mu.user_id) FROM {$mu} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id" );
$extra_new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT u.ID) FROM {$mu} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE u.user_email NOT LIKE %s AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.meta_key = 'npmp_email' AND LOWER(pm.meta_value) = LOWER(u.user_email) )", '%@' . $wpdb->esc_like( $domain ) ) );
$ok( 'no members outside the rig', 7 === $all_users, (string) $all_users );
// A membership row whose WordPress user is gone counts nowhere.
$wpdb->insert( $mu, array( 'user_id' => 999999, 'membership_id' => $gold, 'code_id' => 0, 'initial_payment' => 10, 'billing_amount' => 10, 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => '2020-01-01 00:00:00' ) );
$wpdb->insert( $mu, array( 'user_id' => 999999, 'membership_id' => $board, 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'status' => 'active', 'startdate' => '2020-01-01 00:00:00' ) );

// ---------------------------------------------------------------------
// First run, with room for four new rig contacts.
// ---------------------------------------------------------------------
$used = npmp_import_free_used( true ) + $extra_new;
echo "First run (4 places left)\n";
$r   = $child( 'import', sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 4 ) );
$res = $r['state']['results'] ?? array();
$ok( 'run finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( '4 new contacts', 4 === (int) ( $res['created'] ?? -1 ), $res );
$ok( '1 past the free limit', 1 === (int) ( $res['over_limit'] ?? -1 ), $res );
$ok( 'old and pen updated', 2 === (int) ( $res['updated'] ?? -1 ), $res );
$ok( 'old kept unsubscribed', 1 === (int) ( $res['kept_unsubscribed'] ?? -1 ), $res );
$ok( 'Rig Gold and Rig Board added, Rig Silver not twice', array( 'Rig Gold', 'Rig Board' ) === ( $res['levels_added'] ?? null ), $res['levels_added'] ?? '' );
$ok( 'no places left', 0 === (int) ( $r['left'] ?? -1 ), (string) ( $r['left'] ?? '' ) );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ), (string) ( $r['mails'] ?? '' ) );
$ok( 'the hook marks new contacts as imported', 'imported' === ( $r['hook'][ 'ada@' . $domain ][2] ?? '' ) && 'updated' === ( $r['hook'][ 'pen@' . $domain ][2] ?? '' ), $r['hook'] ?? '' );
$ok( 'the hook gets the user ID', $users['ada'] === ( $r['hook'][ 'ada@' . $domain ][1] ?? null ) );

$levels_now = get_option( 'npmp_membership_levels', array() );
$ok( 'membership levels hold each Rig level once', 1 === count( array_keys( $levels_now, 'Rig Silver', true ) ) && in_array( 'Rig Gold', $levels_now, true ) && in_array( 'Rig Board', $levels_now, true ), $levels_now );

$get = function ( $key ) use ( $members, $domain ) {
	return $members->get_member_by_email( $key . '@' . $domain );
};
$ada = $get( 'ada' );
$ok( 'ada is Active', $ada && 'subscribed' === $ada->status, $ada ? $ada->status : 'missing' );
$ok( 'ada has her full name', $ada && 'Ada Lovelace' === $ada->name );
$ok( 'ada is Rig Gold', $ada && 'Rig Gold' === $ada->membership_level );
$ok( 'ada keeps her join date and time', $ada && 0 === strpos( (string) $ada->created_at, '2023-03-01 12:00' ), $ada ? $ada->created_at : '' );
$ok( 'ada has her city and phone', $ada && 'Ventura' === $ada->city && '805-555-0100' === $ada->phone );
$ok( 'ada came from Paid Memberships Pro', $ada && 'pmpro' === $ada->source );
$ok( 'ada counts toward the free total', $ada && '' !== (string) get_post_meta( $ada->id, '_npmp_free_import', true ) );
$ben = $get( 'ben' );
$ok( 'ben gets his display name', $ben && 'Ben Bright' === $ben->name, $ben ? $ben->name : 'missing' );
$ok( 'ben is Rig Silver', $ben && 'Rig Silver' === $ben->membership_level );
$cal = $get( 'cal' );
$ok( 'past member cal is Inactive', $cal && 'inactive' === $cal->status, $cal ? $cal->status : 'missing' );
$ok( 'cal has no level', $cal && '' === $cal->membership_level, $cal ? $cal->membership_level : '' );
$ok( 'cal keeps his first join date', $cal && 0 === strpos( (string) $cal->created_at, '2022-05-01' ), $cal ? $cal->created_at : '' );
$dee = $get( 'dee' );
$ok( 'dee gets her newest level', $dee && 'Rig Board' === $dee->membership_level, $dee ? $dee->membership_level : '' );
$ok( 'eve is past the limit', ! $get( 'eve' ) );
$old = $get( 'old' );
$ok( 'old stays unsubscribed', $old && 'unsubscribed' === $old->status );
$ok( 'old keeps the level set by hand', $old && 'Board Chair' === $old->membership_level, $old ? $old->membership_level : '' );
$ok( 'old gets the empty city filled', $old && 'Oxnard' === $old->city );
$ok( 'old keeps where they came from', $old && 'manual' === $old->source );
$pen = $get( 'pen' );
$ok( 'pending pen becomes Active', $pen && 'subscribed' === $pen->status, $pen ? $pen->status : 'missing' );
$ok( 'pen gets a name and a level', $pen && 'Pen Pal' === $pen->name && 'Rig Silver' === $pen->membership_level );

echo "Second run with room\n";
$r   = $child( 'import' );
$res = $r['state']['results'] ?? array();
$ok( 'eve comes over', 1 === (int) ( $res['created'] ?? -1 ) && 0 === (int) ( $res['over_limit'] ?? -1 ), $res );
$ok( 'nothing else changes', 6 === (int) ( $res['unchanged'] ?? -1 ), $res );
$ok( 'no levels added this time', array() === ( $res['levels_added'] ?? null ) );
$ok( 'nobody doubled', 1 === count( get_posts( array( 'post_type' => 'npmp_contact', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'npmp_email', 'meta_value' => 'ada@' . $domain ) ) ) );

echo "Switch Plugins\n";
$h = $child( 'hub' );
$ok( 'registered', ! empty( $h['registered'] ), $h );
$ok( 'step 5 unavailable', empty( $h['has_batch'] ) && false !== strpos( (string) ( $h['reason'] ?? '' ), 'Uninstall PMPro on deletion?' ), $h['reason'] ?? $h );
$ok( 'step 4: data stays while the PMPro setting is off', false !== strpos( (string) ( $h['step4_off'] ?? '' ), 'stays in your database' ) && false === strpos( (string) ( $h['step4_off'] ?? '' ), 'order history' ), $h['step4_off'] ?? '' );
$ok( 'step 4: warns while the PMPro setting is on', false !== strpos( (string) ( $h['step4_on'] ?? '' ), 'also deletes its tables, settings, and order history' ) && false === strpos( (string) ( $h['step4_on'] ?? '' ), 'stays in your database' ), $h['step4_on'] ?? '' );
$ok( 'dues billing shows as a Pro part', 1 === count( (array) ( $h['pro_parts'] ?? array() ) ) );
$ok( 'installed plugins name Paid Memberships Pro', isset( $h['installed']['paid-memberships-pro/paid-memberships-pro.php'] ), $h['installed'] ?? '' );
$ok( 'counts: 7 members', 7 === (int) ( $h['rows'][0]['old'] ?? -1 ), $h['rows'][0] ?? $h );
$ok( 'counts: 5 new contacts', 5 === (int) ( $h['rows'][0]['new'] ?? -1 ), $h['rows'][0] ?? '' );
$ok( 'counts: nobody left out of contacts', 0 === (int) ( $h['rows'][1]['old'] ?? -1 ), $h['rows'][1] ?? '' );
$all_levels = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" );
$ok( 'counts: every level is here', $all_levels === (int) ( $h['rows'][2]['old'] ?? -1 ) && $all_levels === (int) ( $h['rows'][2]['new'] ?? -1 ), $h['rows'][2] ?? '' );
$ok( 'counts: one member holds two levels', false !== strpos( (string) ( $h['rows'][2]['note'] ?? '' ), '1 member' ), $h['rows'][2]['note'] ?? '' );
$ok( 'counts: one renewing member, with a warning', 1 === (int) ( $h['rows'][3]['old'] ?? -1 ) && false !== strpos( (string) ( $h['rows'][3]['note'] ?? '' ), 'payment processor' ), $h['rows'][3] ?? '' );
$ok( 'summary names the count', false !== strpos( (string) ( $h['summary'] ?? '' ), '5' ), $h['summary'] ?? '' );
$ok( 'verified', 'ok' === ( $h['verify'] ?? '' ), $h );
$ok( 'Paid Memberships Pro turned off', 'ok' === ( $h['deactivate'] ?? '' ) && false === ( $h['active'] ?? true ), $h );
$ok( 'memberships still in the database', 8 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$mu} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE u.user_email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );
$ok( 'WordPress accounts untouched', 7 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );

echo "Import with Paid Memberships Pro off\n";
$r   = $child( 'import' );
$res = $r['state']['results'] ?? array();
$ok( 'reads with PMPro off and adds nobody', false === ( $r['active'] ?? true ) && 0 === (int) ( $res['created'] ?? -1 ) && 7 === (int) ( $res['updated'] ?? 0 ) + (int) ( $res['unchanged'] ?? 0 ), $res );

// Put your membership levels and time zone back.
false === $saved_levels ? delete_option( 'npmp_membership_levels' ) : update_option( 'npmp_membership_levels', $saved_levels );
update_option( 'timezone_string', (string) $saved_tz );
update_option( 'gmt_offset', $saved_offset );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
