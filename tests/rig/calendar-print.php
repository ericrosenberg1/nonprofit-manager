<?php
/**
 * Real-WordPress check of "Print or save as PDF" on the front-end calendar,
 * [npmp_events] and the Events and Calendar blocks: the button shows by
 * default, hides with the setting off and with print="no", the print assets
 * load only when a calendar renders, the printout header carries the
 * organization name and the period, and the settings screen saves the box.
 *
 * Run inside a throwaway WordPress with the plugin active and the calendar
 * module on (CLAUDE.md, "Testing beyond unit tests"):
 *   wp eval-file tests/rig/calendar-print.php
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_calendar_print_wants_button' ) ) {
	echo "  FAIL  calendar module is off. Turn on 'calendar' in npmp_enabled_features first.\n";
	exit( 1 );
}

$saved_org = get_option( 'npmp_org_settings', null );
update_option( 'npmp_org_settings', array_merge( is_array( $saved_org ) ? $saved_org : array(), array( 'name' => 'Rig Valley Garden Club' ) ) );
delete_option( 'npmp_calendar_print_button' );
delete_option( 'npmp_events_loop' );
delete_option( 'npmp_calendar_list_show_past' );

foreach ( get_posts( array( 'post_type' => 'npmp_event', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}

$tz    = wp_timezone();
$today = new DateTimeImmutable( 'today', $tz );
$make  = function ( $title, DateTimeImmutable $start, $location = '' ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'npmp_event',
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	update_post_meta( $id, '_npmp_event_start', $start->format( 'Y-m-d H:i:s' ) );
	update_post_meta( $id, '_npmp_event_end', $start->modify( '+2 hours' )->format( 'Y-m-d H:i:s' ) );
	update_post_meta( $id, '_npmp_event_location', $location );
	return $id;
};
$make( 'Seed Swap', $today->modify( '+1 day' )->setTime( 18, 0 ), 'Library Hall' );
$make( 'Plant Sale', $today->modify( '+9 days' )->setTime( 10, 0 ), 'Town Green' );
$make( 'Annual Meeting', $today->modify( '+40 days' )->setTime( 19, 0 ) );

$month_label = wp_date( 'F Y', $today->getTimestamp(), $tz );
$reset_assets = function () {
	wp_dequeue_script( 'npmp-calendar-print' );
	wp_dequeue_style( 'npmp-calendar-print' );
};
$has_button = function ( $html ) {
	return false !== strpos( $html, 'class="npmp-print-button"' );
};

echo "Assets load only when a calendar renders\n";
$reset_assets();
$ok( 'print script registered', wp_script_is( 'npmp-calendar-print', 'registered' ) );
$ok( 'print stylesheet registered', wp_style_is( 'npmp-calendar-print', 'registered' ) );
$ok( 'script not enqueued before a render', ! wp_script_is( 'npmp-calendar-print', 'enqueued' ) );
$ok( 'stylesheet not enqueued before a render', ! wp_style_is( 'npmp-calendar-print', 'enqueued' ) );
do_shortcode( 'Plain text with no calendar.' );
$ok( 'still not enqueued after a page with no calendar', ! wp_script_is( 'npmp-calendar-print', 'enqueued' ) );
$html = do_shortcode( '[npmp_calendar view="month"]' );
$ok( 'script enqueued after a calendar renders', wp_script_is( 'npmp-calendar-print', 'enqueued' ) );
$ok( 'stylesheet enqueued after a calendar renders', wp_style_is( 'npmp-calendar-print', 'enqueued' ) );
$src = wp_scripts()->registered['npmp-calendar-print']->src;
$ok( 'script is a file, not inline', false !== strpos( $src, 'assets/js/npmp-calendar-print.js' ) );
$ok( 'script loads in the footer', 1 === (int) wp_scripts()->get_data( 'npmp-calendar-print', 'group' ) );

echo "Button by default\n";
$views = array();
foreach ( array( 'month', 'week', 'list' ) as $view ) {
	$views[ $view ] = do_shortcode( '[npmp_calendar view="' . $view . '"]' );
	$ok( $view . ' view has the button', $has_button( $views[ $view ] ) );
	$ok( $view . ' view has one button only', 1 === substr_count( $views[ $view ], 'npmp-print-button' ) );
	$ok( $view . ' view is a print region', false !== strpos( $views[ $view ], 'npmp-calendar-wrapper npmp-print-region' ) );
	$ok( $view . ' view has one print header', 1 === substr_count( $views[ $view ], 'class="npmp-print-header"' ) );
}
$events = do_shortcode( '[npmp_events]' );
$ok( '[npmp_events] has the button', $has_button( $events ) );
$ok( '[npmp_events] is a print region', false !== strpos( $events, 'npmp-print-region' ) );
$ok( 'the button is a real button with a text label', false !== strpos( $events, '<button type="button" class="npmp-print-button">' ) && false !== strpos( $events, 'Print or save as PDF' ) );

$GLOBALS['wp_query'] = new WP_Query();
$block = do_blocks( '<!-- wp:nonprofit-manager/events {"limit":0,"layout":"mosaic"} /-->' );
$ok( 'Events block has the button', $has_button( $block ) );
$ok( 'button sits inside the block wrapper', 1 === preg_match( '#<div[^>]*wp-block-nonprofit-manager-events[^>]*>.*npmp-print-button#s', $block ) );
$ok( 'mosaic sizes its first row to the bar', false !== strpos( $block, 'npmp-events--has-print' ) );
$block = do_blocks( '<!-- wp:nonprofit-manager/calendar {"view":"week"} /-->' );
$ok( 'Calendar block has the button', $has_button( $block ) );

echo "Printout header: organization name and period\n";
$ok( 'month header has the org name', false !== strpos( $views['month'], '<p class="npmp-print-org">Rig Valley Garden Club</p>' ) );
$ok( 'month header has the month', false !== strpos( $views['month'], '<p class="npmp-print-title">' . esc_html( $month_label ) . '</p>' ) );
$week_start = npmp_calendar_week_start( $today, (int) get_option( 'start_of_week', 0 ) );
$week_label = npmp_calendar_print_range_label( $week_start->getTimestamp(), $week_start->modify( '+6 days' )->getTimestamp(), $tz );
$ok( 'week header has the date range', false !== strpos( $views['week'], '<p class="npmp-print-title">' . esc_html( $week_label ) . '</p>' ) );
$list_label = npmp_calendar_print_range_label( $today->modify( '+1 day' )->setTime( 18, 0 )->getTimestamp(), $today->modify( '+40 days' )->setTime( 19, 0 )->getTimestamp(), $tz );
$ok( 'list header has the events date range', false !== strpos( $views['list'], '<p class="npmp-print-range">' . esc_html( $list_label ) . '</p>' ) );
$ok( '[npmp_events] header has the org name', false !== strpos( $events, 'Rig Valley Garden Club' ) );
$ok( 'header stays hidden on screen', false !== strpos( $views['month'], '<div class="npmp-print-header" hidden>' ) );
$ok( 'month chips carry the location for paper', false !== strpos( $views['month'] . $views['week'], '<span class="npmp-calendar-event-location" hidden>Library Hall</span>' ) );

add_filter(
	'npmp_calendar_print_heading',
	$extra = static function ( $heading, $context ) {
		$heading['lines'][] = 'Showing: ' . $context['source'] . '/' . $context['view'];
		return $heading;
	},
	10,
	2
);
$ok( 'npmp_calendar_print_heading adds a line', false !== strpos( do_shortcode( '[npmp_calendar view="month"]' ), '<p class="npmp-print-line">Showing: calendar/month</p>' ) );
$ok( 'npmp_calendar_print_heading sees the events list', false !== strpos( do_shortcode( '[npmp_events]' ), 'Showing: events/list' ) );
remove_filter( 'npmp_calendar_print_heading', $extra, 10 );

echo "print=\"no\" hides it on one instance\n";
foreach ( array( 'month', 'week', 'list' ) as $view ) {
	$ok( $view . ' view with print="no"', ! $has_button( do_shortcode( '[npmp_calendar view="' . $view . '" print="no"]' ) ) );
}
$ok( '[npmp_events print="no"]', ! $has_button( do_shortcode( '[npmp_events print="no"]' ) ) );
$GLOBALS['wp_query'] = new WP_Query();
$ok( 'Events block with print "no"', ! $has_button( do_blocks( '<!-- wp:nonprofit-manager/events {"print":"no"} /-->' ) ) );
$ok( 'Calendar block with print "no"', ! $has_button( do_blocks( '<!-- wp:nonprofit-manager/calendar {"print":"no"} /-->' ) ) );
$reset_assets();
$html = do_shortcode( '[npmp_calendar print="no"]' );
$ok( 'header still renders for Ctrl+P', false !== strpos( $html, 'npmp-print-header' ) );
$ok( 'no script without a button', ! wp_script_is( 'npmp-calendar-print', 'enqueued' ) );
$ok( 'stylesheet still loads for Ctrl+P', wp_style_is( 'npmp-calendar-print', 'enqueued' ) );

echo "Setting off hides it everywhere\n";
update_option( 'npmp_calendar_print_button', 0 );
foreach ( array( 'month', 'week', 'list' ) as $view ) {
	$ok( $view . ' view with the setting off', ! $has_button( do_shortcode( '[npmp_calendar view="' . $view . '"]' ) ) );
}
$ok( '[npmp_events] with the setting off', ! $has_button( do_shortcode( '[npmp_events]' ) ) );
$ok( 'print="yes" brings it back on one calendar', $has_button( do_shortcode( '[npmp_calendar print="yes"]' ) ) );
delete_option( 'npmp_calendar_print_button' );

echo "No button on an empty list\n";
$ok( 'empty list has no button', ! $has_button( do_shortcode( '[npmp_events category="no-such-category"]' ) ) );

echo "Calendar Settings saves the box\n";
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin ? $admin[0]->ID : 1 );
ob_start();
npmp_render_event_settings_page();
$page = ob_get_clean();
$ok( 'checkbox on the screen, checked by default', 1 === preg_match( '#name="npmp_calendar_print_button" value="1"\s+checked#', $page ) );
$ok( 'checkbox label', false !== strpos( $page, 'Show a Print or save as PDF button on calendars' ) );

$_POST = array(
	'npmp_save_calendar_settings' => 'Save Settings',
	'npmp_event_settings_nonce'   => wp_create_nonce( 'npmp_save_calendar_settings' ),
	'npmp_calendar_page_id'       => (string) absint( get_option( 'npmp_calendar_page_id', 0 ) ),
	'npmp_calendar_default_view'  => get_option( 'npmp_calendar_default_view', 'month' ),
	'npmp_calendar_show_times'    => '1',
	'npmp_calendar_accent'        => '#0f766e',
	'npmp_calendar_list_limit'    => '10',
);
ob_start();
npmp_render_event_settings_page();
ob_end_clean();
$ok( 'unchecked box saves 0', '0' === (string) get_option( 'npmp_calendar_print_button' ) );
$ok( 'site setting now off', ! npmp_calendar_print_site_enabled() );

$_POST['npmp_calendar_print_button'] = '1';
ob_start();
npmp_render_event_settings_page();
ob_end_clean();
$ok( 'checked box saves 1', '1' === (string) get_option( 'npmp_calendar_print_button' ) );

$_POST                               = array( 'npmp_save_calendar_settings' => 'Save Settings', 'npmp_event_settings_nonce' => 'bad' );
update_option( 'npmp_calendar_print_button', 1 );
ob_start();
npmp_render_event_settings_page();
ob_end_clean();
$ok( 'a bad nonce saves nothing', '1' === (string) get_option( 'npmp_calendar_print_button' ) );
$_POST = array();

// Leave the rig as it was.
delete_option( 'npmp_calendar_print_button' );
if ( null === $saved_org ) {
	delete_option( 'npmp_org_settings' );
} else {
	update_option( 'npmp_org_settings', $saved_org );
}

printf( "\n%s\n", $fails ? "{$fails} FAILED" : 'All passed' );
exit( $fails ? 1 : 0 );
