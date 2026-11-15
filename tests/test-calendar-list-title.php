<?php
/**
 * Calendar list view title (includes/npmp-calendar.php).
 *
 * With "Include past events in the list view" on, the list holds past events
 * too. The printout header already said "Events" then, but the on-screen
 * toolbar still said "Upcoming events". Both read npmp_calendar_list_title()
 * now.
 *
 * Run: php tests/test-calendar-list-title.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['t_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function add_action() {
	return true;
}
function add_filter() {
	return true;
}
function add_shortcode() {
	return true;
}
function esc_attr__( $text ) {
	return $text;
}
function esc_url( $url ) {
	return $url;
}
function remove_query_arg( $keys ) {
	return '/calendar/';
}
function add_query_arg( $args, $base ) {
	return $base . '?' . http_build_query( $args );
}
function wp_date( $format, $ts, $tz = null ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ? $tz : new DateTimeZone( 'UTC' ) )->format( $format );
}

require_once dirname( __DIR__ ) . '/includes/npmp-calendar.php';

$tz     = new DateTimeZone( 'America/Los_Angeles' );
$anchor = new DateTimeImmutable( '2026-11-15', $tz );

echo "Helper\n";
check( 'option off: Upcoming events', 'Upcoming events', npmp_calendar_list_title() );
$GLOBALS['t_options']['npmp_calendar_list_show_past'] = 1;
check( 'option on: Events', 'Events', npmp_calendar_list_title() );

echo "Toolbar\n";
$GLOBALS['t_options']['npmp_calendar_list_show_past'] = 0;
check( 'list toolbar, past off', true, false !== strpos( npmp_calendar_render_toolbar( 'list', $anchor, $tz, '' ), '<h2 class="npmp-calendar-title">Upcoming events</h2>' ) );
$GLOBALS['t_options']['npmp_calendar_list_show_past'] = 1;
$html = npmp_calendar_render_toolbar( 'list', $anchor, $tz, '' );
check( 'list toolbar, past on', true, false !== strpos( $html, '<h2 class="npmp-calendar-title">Events</h2>' ) );
check( 'no "Upcoming" with past on', false, strpos( $html, 'Upcoming events' ) );
check( 'month toolbar keeps the month', true, false !== strpos( npmp_calendar_render_toolbar( 'month', $anchor, $tz, '' ), '<h2 class="npmp-calendar-title">November 2026</h2>' ) );

echo "Printout\n";
$cal = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-calendar.php' );
check( 'print header uses the helper', true, false !== strpos( $cal, "npmp_calendar_print_header_html(\n\t\t\tnpmp_calendar_list_title()," ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
