<?php
/**
 * Calendar print (includes/npmp-calendar-print.php): the site setting's
 * default, the print="" attribute, the date range label, and the printout
 * header with its filter.
 *
 * Run: php tests/test-calendar-print.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_filters'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return true;
}
function add_filter( $hook, $callback ) {
	$GLOBALS['t_filters'][ $hook ][] = $callback;
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/London' );
}
function wp_date( $format, $ts, $tz = null ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
}

class NPMP_Org_Settings {
	public static function get() {
		return array( 'name' => $GLOBALS['t_org_name'] ?? '' );
	}
}

require_once dirname( __DIR__ ) . '/includes/npmp-calendar-print.php';

$tz = new DateTimeZone( 'Europe/London' );
$ts = static function ( $when ) use ( $tz ) {
	return ( new DateTimeImmutable( $when, $tz ) )->getTimestamp();
};

echo "Site setting: an unset option means on\n";
check( 'unset option shows the button', true, npmp_calendar_print_site_enabled() );
$GLOBALS['t_options']['npmp_calendar_print_button'] = '0';
check( 'saved 0 hides it', false, npmp_calendar_print_site_enabled() );
$GLOBALS['t_options']['npmp_calendar_print_button'] = '1';
check( 'saved 1 shows it', true, npmp_calendar_print_site_enabled() );

echo "print attribute\n";
check( 'blank follows the setting (on)', true, npmp_calendar_print_wants_button( '' ) );
check( 'no hides it', false, npmp_calendar_print_wants_button( 'no' ) );
check( 'NO is case-insensitive', false, npmp_calendar_print_wants_button( ' NO ' ) );
check( 'false hides it', false, npmp_calendar_print_wants_button( 'false' ) );
check( 'unknown value follows the setting', true, npmp_calendar_print_wants_button( 'maybe' ) );
$GLOBALS['t_options']['npmp_calendar_print_button'] = 0;
check( 'blank follows the setting (off)', false, npmp_calendar_print_wants_button( '' ) );
check( 'yes shows it with the setting off', true, npmp_calendar_print_wants_button( 'yes' ) );
check( 'boolean false', false, npmp_calendar_print_wants_button( false ) );
unset( $GLOBALS['t_options']['npmp_calendar_print_button'] );

echo "Date range label\n";
check( 'no start, no label', '', npmp_calendar_print_range_label( 0, 0, $tz ) );
check( 'one day', 'November 3, 2026', npmp_calendar_print_range_label( $ts( '2026-11-03 19:00' ), 0, $tz ) );
check( 'same day twice', 'November 3, 2026', npmp_calendar_print_range_label( $ts( '2026-11-03 09:00' ), $ts( '2026-11-03 21:00' ), $tz ) );
check( 'same year', 'November 1 to November 7, 2026', npmp_calendar_print_range_label( $ts( '2026-11-01' ), $ts( '2026-11-07' ), $tz ) );
check( 'across the new year', 'December 28, 2026 to January 3, 2027', npmp_calendar_print_range_label( $ts( '2026-12-28' ), $ts( '2027-01-03' ), $tz ) );
check( 'days use the site timezone, not UTC', 'March 29, 2026', npmp_calendar_print_range_label( $ts( '2026-03-29 00:30' ), $ts( '2026-03-29 23:30' ), $tz ) );

echo "Printout header\n";
$GLOBALS['t_options']['blogname'] = 'Site Title';
$html = npmp_calendar_print_header_html( 'November 2026', '', array( 'source' => 'calendar' ) );
check( 'falls back to the site title', true, false !== strpos( $html, '<p class="npmp-print-org">Site Title</p>' ) );
check( 'hidden on screen', true, 0 === strpos( $html, '<div class="npmp-print-header" hidden>' ) );
check( 'carries the month', true, false !== strpos( $html, '<p class="npmp-print-title">November 2026</p>' ) );
check( 'no empty range line', false, strpos( $html, 'npmp-print-range' ) );

$GLOBALS['t_org_name'] = 'Friends & Neighbors';
$html = npmp_calendar_print_header_html( 'Upcoming events', 'November 1 to November 7, 2026', array() );
check( 'org name from the organization settings, escaped', true, false !== strpos( $html, 'Friends &amp; Neighbors' ) );
check( 'range line', true, false !== strpos( $html, '<p class="npmp-print-range">November 1 to November 7, 2026</p>' ) );

add_filter(
	'npmp_calendar_print_heading',
	static function ( $heading, $context ) {
		if ( 'calendar' === ( $context['source'] ?? '' ) ) {
			$heading['lines'][] = 'Showing: Art, History';
		}
		return $heading;
	}
);
$html = npmp_calendar_print_header_html( 'November 2026', '', array( 'source' => 'calendar' ) );
check( 'filter adds a line', true, false !== strpos( $html, '<p class="npmp-print-line">Showing: Art, History</p>' ) );
$html = npmp_calendar_print_header_html( 'Upcoming events', '', array( 'source' => 'events' ) );
check( 'filter sees the context', false, strpos( $html, 'Showing:' ) );

echo "Button\n";
$html = npmp_calendar_print_button_html();
check( 'a real button', true, false !== strpos( $html, '<button type="button" class="npmp-print-button">' ) );
check( 'text label', true, false !== strpos( $html, 'Print or save as PDF</span>' ) );
check( 'bar hidden until the script loads', true, 0 === strpos( $html, '<div class="npmp-print-bar" hidden>' ) );
check( 'icon hidden from screen readers', true, false !== strpos( $html, 'aria-hidden="true"' ) );

echo "Print assets\n";
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/npmp-calendar-print.js' );
check( 'script calls window.print()', true, false !== strpos( $js, 'window.print()' ) );
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/npmp-calendar-print.css' );
check( 'stylesheet is print-only', 1, preg_match( '/^\s*@media print \{/m', $css ) );
check( 'no bare @page outside a named page', 0, preg_match( '/@page\s*\{/', $css ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
