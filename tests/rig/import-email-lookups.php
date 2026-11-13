<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/import-email-lookups.php
//
// Imports look contacts up by email once per batch (NPMP_Member_Manager::
// lookup_emails()), never one meta_value scan per row. npmp_email has no
// index, so the old per-row lookup made a 50,000-row MailPoet import take
// 3.7 seconds a batch by the end. This checks the CSV path makes one lookup
// per batch, and that the request-scoped index never answers with a stale
// contact after an add, a delete, or an email change. Leaves no contacts
// behind. Not run by the pre-push gate (it needs WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

global $wpdb;
$domain = 'rig-lookups.example.org';
$mm     = NPMP_Member_Manager::get_instance();
$sweep  = function () use ( $wpdb, $domain ) {
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	wp_cache_flush();
};
$sweep();
// Pro, if active, lifts the per-import cap. Keep the free cap out of the way.
add_filter( 'npmp_import_max_rows', static function () { return 100000; }, PHP_INT_MAX );

echo "CSV rows: one lookup per batch\n";
$ann = $mm->add_member( array( 'name' => 'Ann Here', 'email' => "ann@$domain", 'status' => 'subscribed', 'source' => 'manual' ) );
$bob = $mm->add_member( array( 'name' => 'Bob Here', 'email' => "bob@$domain", 'status' => 'subscribed', 'source' => 'manual' ) );
$mm->forget_email();
wp_cache_flush();
$rows = array( array( "ann@$domain", 'Ann Updated' ), array( "bob@$domain", 'Bob Updated' ) );
for ( $i = 0; $i < 28; $i++ ) {
	$rows[] = array( "new$i@$domain", "New $i" );
}
$per_row = 0;
$batched = 0;
$watch   = function ( $query ) use ( &$per_row, &$batched ) {
	if ( false !== strpos( $query, 'npmp_email' ) ) {
		if ( false !== stripos( $query, 'meta_value IN (' ) ) {
			++$batched;
		} elseif ( preg_match( "/meta_key\\s*=\\s*'npmp_email'\\s+AND\\s+(?:CAST\\()?\\S*meta_value\\s*=/i", $query ) ) {
			++$per_row;
		}
	}
	return $query;
};
add_filter( 'query', $watch );
$stats = NPMP_Import_Manager::get_instance()->process_rows( $rows, array( 0 => 'email', 1 => 'name' ), array( 'duplicate_handling' => 'update', 'source' => 'rig' ) );
remove_filter( 'query', $watch );
rig_check( '28 new contacts', 28, (int) $stats['imported'] );
rig_check( 'Ann and Bob updated, not doubled', 2, (int) $stats['updated'] );
rig_check( 'one batched email lookup', 1, $batched );
rig_check( 'no email lookup row by row', 0, $per_row );
rig_check( '30 rig contacts in all', 30, count( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) ) );

echo "The index never answers with a stale contact\n";
$mm->lookup_emails( array( "cat@$domain", "ann@$domain" ) );
rig_check( 'a known-absent address reads as absent', null, $mm->get_member_by_email( "cat@$domain" ) );
$cat = $mm->add_member( array( 'name' => 'Cat', 'email' => "cat@$domain", 'status' => 'subscribed', 'source' => 'manual' ) );
rig_check( 'added after the lookup, found at once', (int) $cat, (int) ( $mm->get_member_by_email( "cat@$domain" )->id ?? 0 ) );
rig_check( 'a second add of the same address is refused', true, is_wp_error( $mm->add_member( array( 'name' => 'Cat Again', 'email' => "cat@$domain", 'status' => 'subscribed' ) ) ) );
$mm->delete_member( $cat );
rig_check( 'deleted, gone from the index', null, $mm->get_member_by_email( "cat@$domain" ) );
$mm->lookup_emails( array( "ann@$domain", "ann2@$domain" ) );
$mm->update_member( $ann, array( 'email' => "ann2@$domain" ) );
rig_check( 'old address after an email change reads as absent', null, $mm->get_member_by_email( "ann@$domain" ) );
rig_check( 'new address after an email change finds Ann', (int) $ann, (int) ( $mm->get_member_by_email( "ann2@$domain" )->id ?? 0 ) );
$mm->lookup_emails( array( "bob@$domain", "bobby@$domain" ) );
update_post_meta( $bob, 'npmp_email', "bobby@$domain" );
wp_cache_flush();
rig_check( 'a direct meta write drops the old address', null, $mm->get_member_by_email( "bob@$domain" ) );
rig_check( 'and finds the new one', (int) $bob, (int) ( $mm->get_member_by_email( "bobby@$domain" )->id ?? 0 ) );

$sweep();
rig_check( 'cleanup leaves no rig contacts', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
