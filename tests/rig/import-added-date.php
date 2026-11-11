<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/import-added-date.php
//
// The "Date Added" import column on a real WordPress: a MailPoet-style CSV
// sets each new contact's Added date, a blank or future cell leaves it alone,
// and an "update" import only moves Added earlier. Leaves no contacts behind.
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
require_once ABSPATH . 'wp-admin/includes/file.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

update_option( 'timezone_string', 'Europe/London' );
$mm     = NPMP_Member_Manager::get_instance();
$emails = array( 'rig-added-1@example.org', 'rig-added-2@example.org', 'rig-added-3@example.org' );
$sweep  = function () use ( $mm, $emails ) {
	foreach ( $emails as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( $m->id );
		}
	}
};
$sweep();

$im      = NPMP_Import_Manager::get_instance();
$headers = array( 'Email', 'First name', 'Last name', 'Confirmation time' );
$mapping = $im->detect_columns( $headers );
rig_check( 'MailPoet "Confirmation time" header auto-maps to Date Added', 'created_at', $mapping[3] ?? '' );

$csv = wp_tempnam( 'rig-added.csv' );
file_put_contents(
	$csv,
	"Email,First name,Last name,Confirmation time\n" .
	"rig-added-1@example.org,Ada,One,2019-04-02 10:15:00\n" .
	"rig-added-2@example.org,Bea,Two,\n" .
	"rig-added-3@example.org,Cy,Three,2099-01-01 00:00:00\n"
);
$stats = $im->import_csv( $csv, $mapping, array( 'duplicate_handling' => 'update' ) );
rig_check( 'Three rows imported', 3, (int) ( $stats['imported'] ?? -1 ) );

$one = $mm->get_member_by_email( 'rig-added-1@example.org' );
rig_check( 'Added comes from the CSV (site-local time)', '2019-04-02 10:15:00', $one ? $one->created_at : null );
rig_check( 'post_date_gmt is the BST offset', '2019-04-02 09:15:00', $one ? get_post_field( 'post_date_gmt', $one->id ) : null );
rig_check( 'First and last name still merge', 'Ada One', $one ? $one->name : null );

$today = current_time( 'Y-m-d' );
$two   = $mm->get_member_by_email( 'rig-added-2@example.org' );
rig_check( 'Blank cell: Added is import day', $today, $two ? substr( $two->created_at, 0, 10 ) : null );
$three = $mm->get_member_by_email( 'rig-added-3@example.org' );
rig_check( 'Future cell: Added is import day', $today, $three ? substr( $three->created_at, 0, 10 ) : null );

// Second import in update mode: an earlier date wins, a later one doesn't.
file_put_contents(
	$csv,
	"Email,Confirmation time\n" .
	"rig-added-1@example.org,2023-06-01 08:00:00\n" .
	"rig-added-2@example.org,2018-01-15\n"
);
$im->import_csv( $csv, array( 0 => 'email', 1 => 'created_at' ), array( 'duplicate_handling' => 'update' ) );
$one = $mm->get_member_by_email( 'rig-added-1@example.org' );
rig_check( 'Update with a later date keeps the earlier Added', '2019-04-02 10:15:00', $one ? $one->created_at : null );
$two = $mm->get_member_by_email( 'rig-added-2@example.org' );
rig_check( 'Update with an earlier date moves Added back', '2018-01-15 00:00:00', $two ? $two->created_at : null );
rig_check( 'Update leaves the name alone', 'Bea Two', $two ? $two->name : null );

unlink( $csv );
$sweep();
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
