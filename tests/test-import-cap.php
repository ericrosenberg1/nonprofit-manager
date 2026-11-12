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

// The number in the copy matches the cap.
$copy = array(
	'readme.txt'                       => 'imports up to 100 supporters per job',
	'README.md'                        => 'imports 100 contacts per job',
	'includes/onboarding/tour-data.php' => 'imports up to 100 people at a time',
);
foreach ( $copy as $file => $phrase ) {
	$text = (string) file_get_contents( $root . '/' . $file );
	check( $file . ' states 100', true, false !== strpos( $text, $phrase ) );
	check( $file . ' no longer states 50', 0, preg_match( '/imports? (?:up to )?50 (?:supporters|contacts|people)|50-contact cap/', $text ) );
}

// The import screen's script falls back to the constant, never a typed number.
$screen = (string) file_get_contents( $root . '/includes/import/admin-import.php' );
check( 'the import screen falls back to the constant', true, false !== strpos( $screen, 's.cap_max_rows || <?php echo (int) NPMP_IMPORT_FREE_MAX_ROWS; ?>' ) );
check( 'no typed 50 fallback left', false, strpos( $screen, 's.cap_max_rows || 50' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
