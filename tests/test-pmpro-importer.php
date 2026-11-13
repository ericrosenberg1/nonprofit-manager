<?php
/**
 * Paid Memberships Pro importer: the pure parts
 * (includes/import/pmpro-importer.php).
 *
 * Run: php tests/test-pmpro-importer.php
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( array( 'add_filter', 'add_action' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/mailpoet-importer.php';
require_once $root . '/includes/import/pmpro-importer.php';

echo "Groups and status\n";
check( 'an active row makes a current member', 'current', npmp_pmpro_member_group( array( 'expired', 'active' ) ) );
check( 'no active row makes a past member', 'past', npmp_pmpro_member_group( array( 'expired', 'cancelled', 'admin_changed' ) ) );
check( 'case and spaces', 'current', npmp_pmpro_member_group( array( ' Active ' ) ) );
check( 'no rows is past', 'past', npmp_pmpro_member_group( array() ) );
check( 'current comes over as Active', 'subscribed', npmp_pmpro_contact_status( 'current' ) );
check( 'past comes over as Inactive', 'inactive', npmp_pmpro_contact_status( 'past' ) );
check( 'a past member never unsubscribes a contact', '', npmp_mailpoet_merge_status( 'subscribed', 'inactive' ) );
check( 'a current member confirms a pending contact', 'subscribed', npmp_mailpoet_merge_status( 'pending', 'subscribed' ) );
check( 'an unsubscribed contact stays unsubscribed', '', npmp_mailpoet_merge_status( 'unsubscribed', 'subscribed' ) );

echo "Dates\n";
check( 'UTC value', 1700000000, npmp_pmpro_date_to_ts( '2023-11-14 22:13:20', new DateTimeZone( 'UTC' ) ) );
check( 'startdate read in the site zone', 1700000000, npmp_pmpro_date_to_ts( '2023-11-14 14:13:20', new DateTimeZone( 'America/Los_Angeles' ) ) );
check( 'empty date', 0, npmp_pmpro_date_to_ts( '' ) );
check( 'zero date', 0, npmp_pmpro_date_to_ts( '0000-00-00 00:00:00' ) );
check( 'junk date', 0, npmp_pmpro_date_to_ts( 'not a date' ) );
$pm = file_get_contents( dirname( __DIR__ ) . '/includes/import/pmpro-importer.php' );
check( 'startdate is read in the site zone', true, false !== strpos( $pm, "npmp_pmpro_date_to_ts( \$row['startdate'] )" ) );
check( 'user_registered is read as UTC', true, false !== strpos( $pm, "npmp_pmpro_date_to_ts( \$user->user_registered, new DateTimeZone( 'UTC' ) )" ) );
check( 'earliest start wins', 100, npmp_pmpro_first_start( array( array( 'start' => 300 ), array( 'start' => 100 ), array( 'start' => 0 ) ) ) );
check( 'no starts', 0, npmp_pmpro_first_start( array( array( 'start' => 0 ) ) ) );

echo "Levels\n";
$rows = array(
	array( 'id' => 1, 'status' => 'expired', 'name' => 'Gold', 'start' => 900 ),
	array( 'id' => 2, 'status' => 'active', 'name' => 'Silver', 'start' => 100 ),
	array( 'id' => 3, 'status' => 'active', 'name' => 'Family', 'start' => 500 ),
);
check( 'newest active level wins', 'Family', npmp_pmpro_pick_level( $rows ) );
check( 'same start, higher row wins', 'B', npmp_pmpro_pick_level( array( array( 'id' => 4, 'status' => 'active', 'name' => 'A', 'start' => 5 ), array( 'id' => 9, 'status' => 'active', 'name' => 'B', 'start' => 5 ) ) ) );
check( 'past member has no level', '', npmp_pmpro_pick_level( array( $rows[0] ) ) );
check( 'deleted level name is skipped', '', npmp_pmpro_pick_level( array( array( 'id' => 1, 'status' => 'active', 'name' => '', 'start' => 1 ) ) ) );

$merged = npmp_pmpro_merge_levels( array( 'Basic', 'gold' ), array( 'Gold', ' Family ', '<b>Board</b>', 'Family', '' ) );
check( 'levels merge without case duplicates', array( 'Basic', 'gold', 'Family', 'Board' ), $merged['levels'] );
check( 'only new names are added', array( 'Family', 'Board' ), $merged['added'] );

check( 'empty level takes the member level', 'Gold', npmp_pmpro_level_update( '', 'manual', 'Gold' ) );
check( 'our own contact follows PMPro', 'Gold', npmp_pmpro_level_update( 'Silver', 'pmpro', 'Gold' ) );
check( 'a level set by hand stays', '', npmp_pmpro_level_update( 'Board', 'manual', 'Gold' ) );
check( 'same level, no change', '', npmp_pmpro_level_update( 'Gold', 'pmpro', 'Gold' ) );
check( 'past member clears nothing', '', npmp_pmpro_level_update( 'Gold', 'pmpro', '' ) );

echo "Address\n";
$fill = npmp_pmpro_fill_fields(
	(object) array( 'phone' => '555-0100', 'city' => '', 'state' => '' ),
	array( 'phone' => '555-0199', 'city' => 'Ventura', 'state' => ' CA ', 'country' => '' )
);
check( 'fills only empty fields', array( 'city' => 'Ventura', 'state' => 'CA' ), $fill );
check( 'billing meta keys from PMPro privacy.php', array( 'pmpro_bphone', 'pmpro_baddress1', 'pmpro_baddress2', 'pmpro_bcity', 'pmpro_bstate', 'pmpro_bzipcode', 'pmpro_bcountry' ), array_values( npmp_pmpro_address_keys() ) );

echo "What we never read\n";
$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( $root . '/includes/import/pmpro-importer.php' ) );
check( 'reads only the levels and memberships tables', array( 'membership_levels', 'memberships_users' ), ( function () use ( $code ) {
	preg_match_all( "/npmp_pmpro_table\(\s*'([a-z_]+)'/", $code, $m );
	$names = array_values( array_unique( $m[1] ) );
	sort( $names );
	return $names;
} )() );
check( 'no orders, subscriptions, or discount codes', 0, preg_match( '/pmpro_(membership_orders|subscriptions|discount_codes)|accountnumber|cardtype|gateway_/i', $code ) );
check( 'reads only the pmpro_uninstall option', array( 'pmpro_uninstall' ), ( function () use ( $code ) {
	preg_match_all( "/get_option\(\s*'(pmpro_[a-z_]+)'/", $code, $m );
	return array_values( array_unique( $m[1] ) );
} )() );
check( 'step 4 warns when PMPro deletes its own data', 1, preg_match( "/'delete_note'\s*=>\s*'npmp_pmpro_delete_note'/", $code ) );
check( 'no password or key names', 0, preg_match( '/password|api_key|secret|licen[cs]e_key/i', $code ) );
check( 'honors the free total', 1, preg_match( '/npmp_import_free_remaining\(\) < 1/', $code ) );
check( 'fires the import hook', 1, substr_count( $code, "'npmp_import_row_imported'" ) );
check( 'step 5 has a reason and no batch', 1, preg_match( "/'data_removal'\s*=>\s*array\(\s*'reason'/", $code ) );
check( 'loaded after the MailPoet importer', true, strpos( file_get_contents( $root . '/nonprofit-manager.php' ), 'pmpro-importer.php' ) > strpos( file_get_contents( $root . '/nonprofit-manager.php' ), 'mailpoet-importer.php' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
