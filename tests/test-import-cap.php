<?php
/**
 * The free import cap (includes/import/import-cap.php).
 *
 * Free imports up to 100 contacts in one job from any email or CRM source, and
 * Pro lifts the cap through the npmp_import_max_rows filter. The cap was 50
 * until Eric raised it on 2026-11-12. The copy that states the number has to
 * move with it, so this file reads the readme, the tour, and the import screen
 * too.
 *
 * Run: php tests/test-import-cap.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['cap_filter'] = null;
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return ( 'npmp_import_max_rows' === $hook && null !== $GLOBALS['cap_filter'] ) ? $GLOBALS['cap_filter'] : $value;
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/import-cap.php';

check( 'the free cap is 100', 100, NPMP_IMPORT_FREE_MAX_ROWS );
check( 'npmp_import_max_rows() returns the free cap', 100, npmp_import_max_rows() );

$GLOBALS['cap_filter'] = PHP_INT_MAX;
check( 'Pro lifts the cap through the filter', PHP_INT_MAX, npmp_import_max_rows() );
$GLOBALS['cap_filter'] = '250';
check( 'a string from a filter becomes a number', 250, npmp_import_max_rows() );
$GLOBALS['cap_filter'] = 0;
check( 'zero clamps to one', 1, npmp_import_max_rows() );
$GLOBALS['cap_filter'] = -5;
check( 'a negative clamps to one', 1, npmp_import_max_rows() );
$GLOBALS['cap_filter'] = null;

echo "Fill order\n";
check( 'three passes, people you can write to first', array( 'subscribed', 'pending', 'rest' ), npmp_import_fill_passes() );
check( 'a fresh run starts with subscribed', 'subscribed', npmp_import_fill_next_pass( '' ) );
check( 'then pending', 'pending', npmp_import_fill_next_pass( 'subscribed' ) );
check( 'then the rest', 'rest', npmp_import_fill_next_pass( 'pending' ) );
check( 'then done', '', npmp_import_fill_next_pass( 'rest' ) );
check( 'an unknown pass ends the run', '', npmp_import_fill_next_pass( 'nope' ) );
check( 'the approve line names the order', true, false !== strpos( npmp_import_fill_order_message(), 'subscribed people first, then people who haven\'t confirmed yet, then everyone else' ) );

// The number in the copy matches the cap.
$copy = array(
	'readme.txt'                       => 'imports up to 100 supporters in total',
	'README.md'                        => 'imports 100 contacts in total',
	'includes/onboarding/tour-data.php' => 'imports up to 100 people in total',
);
foreach ( $copy as $file => $phrase ) {
	$text = (string) file_get_contents( $root . '/' . $file );
	check( $file . ' states 100', true, false !== strpos( $text, $phrase ) );
	check( $file . ' no longer states 50', 0, preg_match( '/imports? (?:up to )?50 (?:supporters|contacts|people)|50-contact cap/', $text ) );
}

// Places left out of the total.
check( 'nothing used leaves 100', 100, npmp_import_places_left( 100, 0 ) );
check( '40 used leaves 60', 60, npmp_import_places_left( 100, 40 ) );
check( 'all used leaves none', 0, npmp_import_places_left( 100, 100 ) );
check( 'over the total never goes negative', 0, npmp_import_places_left( 100, 250 ) );
check( 'a negative count reads as zero', 100, npmp_import_places_left( 100, -3 ) );
$GLOBALS['cap_filter'] = PHP_INT_MAX;
check( 'with Pro the limit is off', false, npmp_import_is_capped() );
check( 'with Pro the places left are unlimited', PHP_INT_MAX, npmp_import_free_remaining() );
$GLOBALS['cap_filter'] = null;
check( 'in free the limit is on', true, npmp_import_is_capped() );

// The import screen's script falls back to the constant, never a typed number.
$screen = (string) file_get_contents( $root . '/includes/import/admin-import.php' );
check( 'the import screen falls back to the constant', true, false !== strpos( $screen, 's.cap_max_rows || <?php echo (int) NPMP_IMPORT_FREE_MAX_ROWS; ?>' ) );
check( 'no typed 50 fallback left', false, strpos( $screen, 's.cap_max_rows || 50' ) );

// Left-behind counts: one lookup in PHP, case and spaces ignored.
check( 'every old address on a contact leaves none behind', 0, npmp_import_count_missing_emails( array( 'A@x.org', 'b@x.org ' ), array( 'a@X.org', 'B@x.org' ) ) );
check( 'an address on no contact counts once', 1, npmp_import_count_missing_emails( array( 'a@x.org', 'c@x.org' ), array( 'a@x.org' ) ) );
check( 'no contacts leaves everyone behind', 2, npmp_import_count_missing_emails( array( 'a@x.org', 'c@x.org' ), array() ) );
$hub_src = '';
foreach ( array( 'mailpoet-hub-source.php', 'newsletter-importer.php', 'pmpro-importer.php' ) as $f ) {
	$hub_src .= file_get_contents( $root . '/includes/import/' . $f );
}
check( 'no left-behind count runs a LOWER() = LOWER() subquery', 0, preg_match_all( '/LOWER\(pm\.meta_value\) = LOWER\(/', $hub_src ) );
check( 'all three left-behind counts use the PHP lookup', 3, substr_count( $hub_src, 'npmp_import_count_emails_not_on_contacts(' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
