<?php
/**
 * Real-WordPress check of the calendar's own pieces: weekday headings that
 * fit a phone, event times that don't repeat themselves, an end time saved
 * without an end date, iCal text escaping, and the Overview's settings link.
 *
 * Run inside a throwaway WordPress with the plugin active and the calendar
 * module on (CLAUDE.md, "Testing beyond unit tests"):
 *   wp eval-file tests/rig/calendar.php
 * It makes two events and deletes them again.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_calendar_shortcode' ) ) {
	echo "  FAIL  calendar module is off. Turn on 'calendar' in npmp_enabled_features first.\n";
	exit( 1 );
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

echo "Weekday headings fit a phone\n";
$html = npmp_calendar_render_month_view( new DateTimeImmutable( '2026-11-15', wp_timezone() ), wp_timezone(), '' );
preg_match_all( '#<th scope="col" abbr="([^"]+)"><span class="npmp-calendar-weekday-full">([^<]+)</span><span class="npmp-calendar-weekday-short" aria-hidden="true">([^<]+)</span></th>#', $html, $m );
$ok( 'month view has seven headings with a full and a short name', 7 === count( $m[0] ) );
$ok( 'the full name is the abbr', $m[1] && $m[1] === $m[2] );
$ok( 'short names are three letters or so', $m[3] && max( array_map( 'mb_strlen', $m[3] ) ) <= 4 );
$ok( 'Wednesday is Wed', in_array( 'Wed', $m[3], true ) && in_array( 'Wednesday', $m[2], true ) );
$week = npmp_calendar_render_week_view( new DateTimeImmutable( '2026-11-15', wp_timezone() ), wp_timezone(), '' );
$ok( 'week view uses the same headings', 7 === substr_count( $week, 'npmp-calendar-weekday-short' ) );
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/npmp-calendar.css' );
$ok( 'phones show the short name', (bool) preg_match( '/@media \(max-width: 600px\)[\s\S]*\.npmp-calendar-weekday-short\s*\{\s*display: inline;/', $css ) );
$print_css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/npmp-calendar-print.css' );
$ok( 'paper shows the full name', false !== strpos( $print_css, '.npmp-calendar-weekday-short {' ) && false !== strpos( $print_css, 'clip: auto !important;' ) );

echo "Event times\n";
$same = npmp_format_event_datetime( array( 'start' => '2026-11-06 19:00:00', 'end' => '2026-11-06 19:00:00' ) );
$ok( 'an end equal to the start prints the start once', 'November 6, 2026 7:00 pm' === $same );
$back = npmp_format_event_datetime( array( 'start' => '2026-11-06 19:00:00', 'end' => '2026-11-05 10:00:00' ) );
$ok( 'an end before the start prints the start once', 'November 6, 2026 7:00 pm' === $back );
$span = npmp_format_event_datetime( array( 'start' => '2026-11-06 19:00:00', 'end' => '2026-11-06 21:00:00' ) );
$ok( 'a real range still prints both times', 'November 6, 2026 7:00 pm &ndash; 9:00 pm' === $span );

echo "Saving an end time without an end date\n";
$id    = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig calendar event' ) );
$_POST = array(
	'npmp_event_details_nonce' => wp_create_nonce( 'npmp_save_event_details' ),
	'npmp_event_start_date'    => '2026-11-20',
	'npmp_event_start_time'    => '18:00',
	'npmp_event_end_date'      => '',
	'npmp_event_end_time'      => '20:30',
	'npmp_event_location'      => 'Hall',
	'npmp_event_url'           => '',
);
wp_update_post( array( 'ID' => $id ) );
$ok( 'start saved', '2026-11-20 18:00:00' === get_post_meta( $id, '_npmp_event_start', true ) );
$ok( 'end lands on the start day', '2026-11-20 20:30:00' === get_post_meta( $id, '_npmp_event_end', true ) );
$_POST['npmp_event_end_time'] = '';
wp_update_post( array( 'ID' => $id ) );
$ok( 'no end time and no end date clears the end', '' === get_post_meta( $id, '_npmp_event_end', true ) );
$_POST['npmp_event_end_date'] = '2026-11-21';
wp_update_post( array( 'ID' => $id ) );
$ok( 'an end date with no time still ends at 23:59', '2026-11-21 23:59:00' === get_post_meta( $id, '_npmp_event_end', true ) );
$_POST = array();
wp_delete_post( $id, true );

echo "iCal text\n";
$ok( 'a line break becomes \\n, not \\\\n', 'Line one\\nLine two' === npmp_ics_escape_text( "Line one\nLine two" ) );
$ok( 'a backslash doubles', 'C:\\\\path' === npmp_ics_escape_text( 'C:\\path' ) );
$ok( 'commas and semicolons are escaped', 'Hall\\, Room 2\\; upstairs' === npmp_ics_escape_text( 'Hall, Room 2; upstairs' ) );

echo "Category classes\n";
$cat = wp_insert_term( 'Rig Workshops', 'npmp_event_category', array( 'slug' => 'rig-workshops' ) );
$cid = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig category event' ) );
update_post_meta( $cid, '_npmp_event_start', wp_date( 'Y-m-d 18:00:00', time() + 20 * DAY_IN_SECONDS ) );
wp_set_object_terms( $cid, array( (int) $cat['term_id'] ), 'npmp_event_category' );
$ok( 'the helper names the category', 'npmp-cat-rig-workshops' === npmp_calendar_event_category_classes( $cid ) );
$chip = npmp_calendar_day_events_html( array( array( 'title' => 'T', 'permalink' => 'http://x.test/', 'start_ts' => 1, 'end_ts' => 1, 'start_key' => 'k', 'location' => '', 'classes' => 'npmp-cat-rig-workshops' ) ), 'k', wp_timezone() );
$ok( 'a grid chip carries the class', false !== strpos( $chip, '<li class="npmp-cat-rig-workshops">' ) );
$list = do_shortcode( '[npmp_events limit="50"]' );
$ok( 'an events list card carries the class', 1 === preg_match( '/<article class="npmp-event[^"]*npmp-cat-rig-workshops/', $list ) );
wp_delete_post( $cid, true );
wp_delete_term( (int) $cat['term_id'], 'npmp_event_category' );

echo "Overview\n";
$saved_page = get_option( 'npmp_calendar_page_id', 0 );
update_option( 'npmp_calendar_page_id', 0 );
ob_start();
npmp_render_events_dashboard();
$overview = ob_get_clean();
update_option( 'npmp_calendar_page_id', $saved_page );
$ok( 'with no calendar page, the tip links to Settings', false !== strpos( $overview, 'page=npmp_event_settings' ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
