<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/import-cap-total.php
//
// The free import limit as a total for the site, on a real WordPress. Three
// imports of new contacts stop at 100 between them, a contact that already
// exists still updates at the limit, a deleted import gives its place back,
// contacts added by hand never count, and Pro's filter turns the limit off.
// Run with Nonprofit Manager Pro inactive. Leaves no contacts behind.
// Not run by the pre-push gate (it needs WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
if ( ! class_exists( 'NPMP_Import_Manager' ) || ! function_exists( 'npmp_import_free_remaining' ) ) {
	fwrite( STDERR, "Turn on the members module first.\n" );
	exit( 2 );
}
if ( ! npmp_import_is_capped() ) {
	fwrite( STDERR, "Deactivate Nonprofit Manager Pro first: it lifts the limit.\n" );
	exit( 2 );
}

$im = NPMP_Import_Manager::get_instance();
$mm = NPMP_Member_Manager::get_instance();
$rows = function ( $from, $to, $name = 'Rig Cap' ) {
	$out = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$out[] = array( 'email' => "rig-cap-$i@example.org", 'name' => "$name $i" );
	}
	return $out;
};
$map   = array( 'email' => 'email', 'name' => 'name' );
$purge = function () use ( $mm ) {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE 'rig-cap-%'" );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	return count( $ids );
};
$purge();
$base = npmp_import_free_used( true );
rig_check( 'the rig starts with no imported contacts counted', 0, $base );
rig_check( '100 places left at the start', 100, npmp_import_free_remaining() );

echo "== Three imports share one total ==\n";
$a = $im->process_named_rows( $rows( 1, 60 ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'first import brings in 60', 60, (int) $a['imported'] );
rig_check( '40 places left', 40, npmp_import_free_remaining() );

$b = $im->process_named_rows( $rows( 61, 90 ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'second import brings in 30', 30, (int) $b['imported'] );
rig_check( '10 places left', 10, npmp_import_free_remaining() );

$c = $im->process_named_rows( $rows( 91, 130 ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'third import brings in only the last 10', 10, (int) $c['imported'] );
rig_check( 'and skips the other 30', 30, (int) $c['skipped'] );
rig_check( 'with no errors counted', 0, (int) $c['errors'] );
rig_check( 'and one line that says why', array( npmp_import_free_limit_message() ), $c['error_messages'] );
rig_check( 'no places left', 0, npmp_import_free_remaining() );
rig_check( 'contact 100 exists', true, (bool) $mm->get_member_by_email( 'rig-cap-100@example.org' ) );
rig_check( 'contact 101 does not', false, (bool) $mm->get_member_by_email( 'rig-cap-101@example.org' ) );

echo "== At the limit ==\n";
$d = $im->process_named_rows( array_merge( $rows( 1, 5, 'Renamed' ), $rows( 200, 204 ) ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'contacts you already have still update', 5, (int) $d['updated'] );
rig_check( 'new ones are skipped', 5, (int) $d['skipped'] );
rig_check( 'nothing new came in', 0, (int) $d['imported'] );
$one = $mm->get_member_by_email( 'rig-cap-1@example.org' );
rig_check( 'the update landed', 'Renamed 1', $one ? $one->name : null );
rig_check( 'an update never counts twice', 100, npmp_import_free_used( true ) );

echo "== A contact added by hand never counts ==\n";
$hand = $mm->add_member( array( 'email' => 'rig-cap-hand@example.org', 'name' => 'By Hand' ) );
rig_check( 'added at the limit', true, is_int( $hand ) || ( is_numeric( $hand ) && $hand > 0 ) );
rig_check( 'the count stays at 100', 100, npmp_import_free_used( true ) );

echo "== Deleting an import gives its place back ==\n";
$mm->delete_member( $mm->get_member_by_email( 'rig-cap-2@example.org' )->id );
$mm->delete_member( $mm->get_member_by_email( 'rig-cap-3@example.org' )->id );
rig_check( 'two places open', 2, npmp_import_free_remaining() );
$e = $im->process_named_rows( $rows( 300, 305 ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'the next import fills exactly those two', 2, (int) $e['imported'] );
rig_check( 'and skips the rest', 4, (int) $e['skipped'] );

echo "== Pro turns the limit off ==\n";
add_filter( 'npmp_import_max_rows', 'rig_cap_unlimited' );
function rig_cap_unlimited() {
	return PHP_INT_MAX;
}
rig_check( 'the limit is off', false, npmp_import_is_capped() );
$f = $im->process_named_rows( $rows( 400, 449 ), $map, array( 'duplicate_handling' => 'update' ) );
rig_check( 'all 50 come in past the free total', 50, (int) $f['imported'] );
$marked = get_post_meta( $mm->get_member_by_email( 'rig-cap-400@example.org' )->id, NPMP_IMPORT_FREE_META, true );
rig_check( 'and carry no free-import mark', '', (string) $marked );
remove_filter( 'npmp_import_max_rows', 'rig_cap_unlimited' );
rig_check( 'back in free, the count is still 100', 100, npmp_import_free_used( true ) );

echo "== The Import screen says what is left ==\n";
ob_start();
npmp_import_free_total_notice();
$notice = ob_get_clean();
rig_check( 'at the limit the notice says so', true, false !== strpos( $notice, 'You have imported your 100 free contacts.' ) );
$mm->delete_member( $mm->get_member_by_email( 'rig-cap-4@example.org' )->id );
ob_start();
npmp_import_free_total_notice();
$notice = ob_get_clean();
rig_check( 'with one place open it counts one', true, false !== strpos( $notice, 'You can import 1 more contact for free, out of 100 in total.' ) );

$purge();
rig_check( 'cleanup leaves nothing counted', 0, npmp_import_free_used( true ) );
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
