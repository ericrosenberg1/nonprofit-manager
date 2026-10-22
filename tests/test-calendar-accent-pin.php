<?php
/**
 * The calendar highlight color an upgraded site keeps
 * (npmp_maybe_pin_legacy_calendar_accent() in includes/activation-hooks.php).
 *
 * 2026.10.11 moved the unsaved fallback from WordPress blue to the brand
 * teal. Pinned here: an upgraded site that never picked a color gets blue
 * saved once, a color the admin picked survives, a fresh install keeps the
 * teal, and the check runs only once.
 *
 * Run: php tests/test-calendar-accent-pin.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function plugin_dir_path( $file ) {
	return rtrim( $file, '/' ) . '/';
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['t_options'] ) ) {
		return false;
	}
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php';
require_once dirname( __DIR__ ) . '/includes/activation-hooks.php';

echo "Upgraded site, no color saved:\n";
$GLOBALS['t_options'] = array( 'npmp_enabled_features' => array( 'calendar' => true ) );
npmp_maybe_pin_legacy_calendar_accent();
check( 'keeps WordPress blue', '#2271b1', get_option( 'npmp_calendar_accent' ) );
check( 'marks the check done', 1, get_option( 'npmp_calendar_accent_checked' ) );

echo "Upgraded site with its own color:\n";
$GLOBALS['t_options'] = array(
	'npmp_enabled_features' => array( 'calendar' => true ),
	'npmp_calendar_accent'  => '#aa3300',
);
npmp_maybe_pin_legacy_calendar_accent();
check( 'keeps the saved color', '#aa3300', get_option( 'npmp_calendar_accent' ) );

echo "Fresh install:\n";
$GLOBALS['t_options'] = array();
npmp_maybe_pin_legacy_calendar_accent();
check( 'saves no color, so the teal fallback shows', false, get_option( 'npmp_calendar_accent' ) );
$GLOBALS['t_options']['npmp_enabled_features'] = array( 'calendar' => true );
npmp_maybe_pin_legacy_calendar_accent();
check( 'a later load still saves no color', false, get_option( 'npmp_calendar_accent' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
