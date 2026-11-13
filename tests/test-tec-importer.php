<?php
/**
 * The Events Calendar importer (includes/import/tec-importer.php): timezone
 * conversion from TEC's stored values, venue lines, the redirect path
 * normaliser, block cleanup, staging keys, which plugins need TEC, and the
 * settings map with its value conversion and "still on default" decision.
 *
 * The database half runs on a real WordPress with TEC installed:
 * tests/rig/tec-importer.php.
 *
 * Run: php tests/test-tec-importer.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][] = array( $hook, $cb, $prio );
}
function is_admin() {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/import/tec-importer.php';

$london = new DateTimeZone( 'Europe/London' );

echo "Front end gets only the redirect hook\n";
check( 'one hook outside admin', 1, count( $GLOBALS['t_hooks'] ) );
check( 'it is template_redirect', 'template_redirect', $GLOBALS['t_hooks'][0][0] );
check( 'it runs before redirect_canonical', 1, $GLOBALS['t_hooks'][0][2] );

echo "TEC timezone strings\n";
check( 'IANA name', 'America/New_York', npmp_tec_parse_timezone( 'America/New_York', $london )->getName() );
check( 'manual UTC+5', '+05:00', npmp_tec_parse_timezone( 'UTC+5', $london )->getName() );
check( 'manual UTC-3.5 is three and a half hours', '-03:30', npmp_tec_parse_timezone( 'UTC-3.5', $london )->getName() );
check( 'manual UTC+5:45', '+05:45', npmp_tec_parse_timezone( 'UTC+5:45', $london )->getName() );
check( 'plain UTC', 'UTC', npmp_tec_parse_timezone( 'UTC', $london )->getName() );
check( 'empty falls back to the site', 'Europe/London', npmp_tec_parse_timezone( '', $london )->getName() );
check( 'nonsense falls back to the site', 'Europe/London', npmp_tec_parse_timezone( 'Mars/Olympus', $london )->getName() );

echo "Dates convert to the site timezone\n";
check( 'UTC copy wins: 18:30 New York in winter is 23:30 London', '2026-12-01 23:30:00', npmp_tec_convert_datetime( '2026-12-01 18:30:00', '2026-12-01 23:30:00', 'America/New_York', $london ) );
check( 'no UTC copy: read in the event timezone', '2026-12-01 23:30:00', npmp_tec_convert_datetime( '2026-12-01 18:30:00', '', 'America/New_York', $london ) );
check( 'summer time on both sides: 18:30 New York is 23:30 BST', '2026-07-01 23:30:00', npmp_tec_convert_datetime( '2026-07-01 18:30:00', '', 'America/New_York', $london ) );
check( 'the week the US changes clocks first (March 10)', '2026-03-10 22:30:00', npmp_tec_convert_datetime( '2026-03-10 18:30:00', '', 'America/New_York', $london ) );
check( 'manual offset event', '2026-12-01 13:00:00', npmp_tec_convert_datetime( '2026-12-01 18:00:00', '', 'UTC+5', $london ) );
check( 'same timezone stays put', '2026-12-01 19:00:00', npmp_tec_convert_datetime( '2026-12-01 19:00:00', '2026-12-01 19:00:00', 'Europe/London', $london ) );
check( 'crosses midnight into the next day', '2026-12-02 02:00:00', npmp_tec_convert_datetime( '2026-12-01 18:00:00', '2026-12-02 02:00:00', 'America/Los_Angeles', $london ) );
check( 'zero date is empty', '', npmp_tec_convert_datetime( '0000-00-00 00:00:00', '', 'UTC', $london ) );
check( 'garbage is empty', '', npmp_tec_convert_datetime( 'not a date', '', 'UTC', $london ) );

echo "Event start and end\n";
$t = npmp_tec_event_times( array( 'start' => '2026-12-01 18:30:00', 'end' => '2026-12-01 20:00:00', 'start_utc' => '2026-12-01 23:30:00', 'end_utc' => '2026-12-02 01:00:00', 'timezone' => 'America/New_York', 'all_day' => '' ), $london );
check( 'timed start', '2026-12-01 23:30:00', $t['start'] );
check( 'timed end', '2026-12-02 01:00:00', $t['end'] );
check( 'not all day', false, $t['all_day'] );
$t = npmp_tec_event_times( array( 'start' => '2026-12-05 00:00:00', 'end' => '2026-12-05 23:59:59', 'start_utc' => '2026-12-05 08:00:00', 'end_utc' => '2026-12-06 07:59:59', 'timezone' => 'America/Los_Angeles', 'all_day' => 'yes' ), $london );
check( 'all-day keeps its own date, no shift', '2026-12-05 00:00:00', $t['start'] );
check( 'all-day ends 23:59 like the editor saves', '2026-12-05 23:59:00', $t['end'] );
check( 'all-day flag', true, $t['all_day'] );
$t = npmp_tec_event_times( array( 'start' => '2026-12-05 00:00:00', 'end' => '2026-12-07 23:59:59', 'all_day' => '1' ), $london );
check( 'multi-day all-day end', '2026-12-07 23:59:00', $t['end'] );
$t = npmp_tec_event_times( array( 'start' => '2026-12-05 10:00:00', 'end' => '', 'timezone' => 'Europe/London' ), $london );
check( 'missing end falls back to start', '2026-12-05 10:00:00', $t['end'] );
check( 'all-day yes', true, npmp_tec_is_all_day( 'yes' ) );
check( 'all-day true', true, npmp_tec_is_all_day( true ) );
check( 'all-day empty', false, npmp_tec_is_all_day( '' ) );
check( 'all-day no', false, npmp_tec_is_all_day( 'no' ) );

echo "Cancelled and postponed events\n";
check( 'a scheduled event stays published', 'publish', npmp_tec_import_status( 'publish', 'scheduled' ) );
check( 'no event status stays published', 'publish', npmp_tec_import_status( 'publish', '' ) );
check( 'a cancelled event comes over as a draft', 'draft', npmp_tec_import_status( 'publish', 'canceled' ) );
check( 'a postponed event comes over as a draft', 'draft', npmp_tec_import_status( 'publish', 'postponed' ) );
check( 'a scheduled-ahead cancelled event comes over as a draft', 'draft', npmp_tec_import_status( 'future', 'canceled' ) );
check( 'a private cancelled event stays private', 'private', npmp_tec_import_status( 'private', 'canceled' ) );

echo "Venue lines\n";
check( 'full address', 'Ventura Hall, 1 Main St, Ventura, CA 93001, United States', npmp_tec_format_venue( 'Ventura Hall', array( 'address' => '1 Main St', 'city' => 'Ventura', 'state' => 'CA', 'zip' => '93001', 'country' => 'United States' ) ) );
check( 'name only', 'Online', npmp_tec_format_venue( 'Online', array() ) );
check( 'address only', '1 Main St, Ventura', npmp_tec_format_venue( '', array( 'address' => '1 Main St', 'city' => 'Ventura' ) ) );
check( 'zip without state', 'Hall, London, SW1A 1AA', npmp_tec_format_venue( 'Hall', array( 'city' => 'London', 'zip' => 'SW1A 1AA' ) ) );
check( 'name equal to address prints once', '1 Main St, Ventura', npmp_tec_format_venue( '1 Main St', array( 'address' => '1 main st', 'city' => 'Ventura' ) ) );
check( 'tags, stray commas, and spaces cleaned', 'The Barn, 2 Oak Rd', npmp_tec_format_venue( '<b>The  Barn</b>', array( 'address' => ' 2 Oak Rd, ' ) ) );

echo "Redirect path normaliser\n";
check( 'full URL', '/event/spring-gala', npmp_tec_normalize_path( 'https://example.org/event/spring-gala/' ) );
check( 'request URI with tracking args', '/event/spring-gala', npmp_tec_normalize_path( '/event/spring-gala/?utm_source=x&fbclid=y' ) );
check( 'case and double slashes', '/event/spring-gala', npmp_tec_normalize_path( '//Event//Spring-Gala///' ) );
check( 'subdirectory install', '/event/spring-gala', npmp_tec_normalize_path( 'https://example.org/blog/event/spring-gala/', '/blog/' ) );
check( 'request URI in a subdirectory', '/event/spring-gala', npmp_tec_normalize_path( '/blog/event/spring-gala', '/blog' ) );
check( 'subdirectory prefix only at a boundary', '/blogger/event/x', npmp_tec_normalize_path( '/blogger/event/x', '/blog' ) );
check( 'home itself', '/', npmp_tec_normalize_path( 'https://example.org/blog/', '/blog' ) );
check( 'percent-encoded slug decodes', '/event/café-night', npmp_tec_normalize_path( '/event/caf%C3%A9-night/' ) );
check( 'encoded and decoded match', npmp_tec_normalize_path( '/event/caf%C3%A9-night/' ), npmp_tec_normalize_path( 'https://example.org/event/café-night' ) );
check( 'recurring date segment', '/event/yoga/2026-11-05', npmp_tec_normalize_path( '/event/yoga/2026-11-05/' ) );
check( 'plain permalink keeps tribe_events', '/?tribe_events=spring-gala', npmp_tec_normalize_path( 'https://example.org/?tribe_events=spring-gala' ) );
check( 'plain permalink with eventDate', '/?tribe_events=yoga&eventdate=2026-11-05', npmp_tec_normalize_path( '/?eventDate=2026-11-05&tribe_events=Yoga&utm=1' ) );
check( 'bad eventDate dropped', '/?tribe_events=yoga', npmp_tec_normalize_path( '/?tribe_events=yoga&eventDate=soon' ) );
check( 'control characters stripped', '/event/x', npmp_tec_normalize_path( "/event/x\n" ) );

echo "Occurrence URLs\n";
check( 'pretty', 'https://example.org/event/yoga/2026-11-05/', npmp_tec_occurrence_url( 'https://example.org/event/yoga/', '2026-11-05' ) );
check( 'plain', 'https://example.org/?tribe_events=yoga&eventDate=2026-11-05', npmp_tec_occurrence_url( 'https://example.org/?tribe_events=yoga', '2026-11-05' ) );

echo "TEC blocks come out of the content\n";
$body = "<!-- wp:tribe/event-datetime /-->\n<!-- wp:paragraph --><p>Bring a chair.</p><!-- /wp:paragraph -->\n<!-- wp:tribe/event-venue {\"venue\":5} /-->\n<!-- wp:tribe/event-links /-->";
check( 'self-closing tribe blocks gone, paragraph kept', '<!-- wp:paragraph --><p>Bring a chair.</p><!-- /wp:paragraph -->', npmp_tec_clean_content( $body ) );
check( 'paired tribe block keeps its HTML', '<p>Inner</p>', npmp_tec_clean_content( '<!-- wp:tribe/event-price {"cost":"5"} --><p>Inner</p><!-- /wp:tribe/event-price -->' ) );
check( 'classic content untouched', "<p>Hi</p>\n\nThere", npmp_tec_clean_content( "<p>Hi</p>\n\nThere" ) );

echo "Staging keys\n";
check( 'keeps good keys, drops junk and repeats', array( '12', '15@202611051800' ), npmp_tec_clean_keys( '12, 15@202611051800,abc,12,1;DROP' ) );
check( 'array input', array( '7' ), npmp_tec_clean_keys( array( '7', '<x>' ) ) );

echo "Plugins that need TEC\n";
check( 'TEC itself is not an add-on', false, npmp_tec_plugin_needs_tec( 'the-events-calendar/the-events-calendar.php', array( 'Name' => 'The Events Calendar' ) ) );
check( 'Requires Plugins header', true, npmp_tec_plugin_needs_tec( 'my-addon/my-addon.php', array( 'Name' => 'My Addon', 'RequiresPlugins' => 'woocommerce, the-events-calendar' ) ) );
check( 'Events Calendar Pro folder', true, npmp_tec_plugin_needs_tec( 'events-calendar-pro/events-calendar-pro.php', array( 'Name' => 'The Events Calendar PRO' ) ) );
check( 'Shortcode & Templates by name', true, npmp_tec_plugin_needs_tec( 'some-folder/x.php', array( 'Name' => 'The Events Calendar Shortcode & Templates' ) ) );
check( 'tribe-ext extension folder', true, npmp_tec_plugin_needs_tec( 'tribe-ext-hide-past/plugin.php', array( 'Name' => 'Hide Past' ) ) );
check( 'Event Tickets runs alone, so it stays on', false, npmp_tec_plugin_needs_tec( 'event-tickets/event-tickets.php', array( 'Name' => 'Event Tickets' ) ) );
check( 'unrelated plugin', false, npmp_tec_plugin_needs_tec( 'akismet/akismet.php', array( 'Name' => 'Akismet' ) ) );

echo "Settings map\n";
$map = npmp_tec_settings_map();
check( 'three settings have a match', array( 'default_view', 'list_limit', 'accent' ), array_keys( $map ) );
check( 'view reads viewOption', array( 'tribe_events_calendar_options', array( 'viewOption' ) ), array( $map['default_view']['source'], $map['default_view']['path'] ) );
check( 'page size reads postsPerPage', array( 'postsPerPage' ), $map['list_limit']['path'] );
check( 'accent reads the Customizer option', array( 'tribe_customizer', array( 'global_elements', 'accent_color' ) ), array( $map['accent']['source'], $map['accent']['path'] ) );
check( 'targets', array( 'npmp_calendar_default_view', 'npmp_calendar_list_limit', 'npmp_calendar_accent' ), array_column( $map, 'target' ) );
check( 'defaults match the calendar code', array( 'month', 10, '#0f766e' ), array_column( $map, 'default' ) );
$calendar_src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-calendar.php' );
foreach ( $map as $id => $entry ) {
	check( "calendar reads {$entry['target']} with the same default", true, false !== strpos( $calendar_src, "get_option( '{$entry['target']}', " . var_export( $entry['default'], true ) . ' )' ) );
}

echo "Setting values convert\n";
check( 'list view', 'list', npmp_tec_convert_setting( 'default_view', 'list' ) );
check( 'month view, any case', 'month', npmp_tec_convert_setting( 'default_view', ' Month ' ) );
check( 'TEC Pro week view', 'week', npmp_tec_convert_setting( 'default_view', 'week' ) );
check( 'day view has no match', null, npmp_tec_convert_setting( 'default_view', 'day' ) );
check( 'photo view has no match', null, npmp_tec_convert_setting( 'default_view', 'photo' ) );
check( 'page size as TEC stores it, a string', 12, npmp_tec_convert_setting( 'list_limit', '12' ) );
check( 'page size as an int', 25, npmp_tec_convert_setting( 'list_limit', 25 ) );
check( 'page size caps at the settings screen limit', 100, npmp_tec_convert_setting( 'list_limit', '500' ) );
check( 'zero page size has no match', null, npmp_tec_convert_setting( 'list_limit', '0' ) );
check( 'negative page size has no match', null, npmp_tec_convert_setting( 'list_limit', '-1' ) );
check( 'hex color lowercased', '#aa3366', npmp_tec_convert_setting( 'accent', '#AA3366' ) );
check( 'short hex expands', '#aa3366', npmp_tec_convert_setting( 'accent', '#a36' ) );
check( 'hex without the hash', '#334aff', npmp_tec_convert_setting( 'accent', '334AFF' ) );
check( 'color name has no match', null, npmp_tec_convert_setting( 'accent', 'red' ) );
check( 'markup in a color has no match', null, npmp_tec_convert_setting( 'accent', '#fff"><script>' ) );
check( 'array value has no match', null, npmp_tec_convert_setting( 'default_view', array( 'list' ) ) );
check( 'unknown setting has no match', null, npmp_tec_convert_setting( 'eventsSlug', 'events' ) );

echo "Settings read from TEC's options\n";
$tec_sources = array(
	'tribe_events_calendar_options' => array(
		'viewOption'            => 'list',
		'postsPerPage'          => '25',
		'tribeEnableViews'      => array( 'list', 'month', 'day' ),
		'showComments'          => true,
		'eventsSlug'            => 'whats-on',
		'defaultCurrencySymbol' => '$',
	),
	'tribe_customizer'              => array( 'global_elements' => array( 'accent_color' => '#AA3366', 'link_color' => '#000000' ) ),
);
$staged = npmp_tec_read_settings( $tec_sources );
check( 'only mapped settings staged', array( 'default_view', 'list_limit', 'accent' ), array_keys( $staged ) );
check( 'view staged with both values', array( 'tec' => 'list', 'value' => 'list' ), $staged['default_view'] );
check( 'page size staged as an int', array( 'tec' => '25', 'value' => 25 ), $staged['list_limit'] );
check( 'accent keeps TEC\'s spelling and the clean value', array( 'tec' => '#AA3366', 'value' => '#aa3366' ), $staged['accent'] );
check( 'no TEC options, nothing staged', array(), npmp_tec_read_settings( array() ) );
check( 'TEC options not an array, nothing staged', array(), npmp_tec_read_settings( array( 'tribe_events_calendar_options' => false, 'tribe_customizer' => '' ) ) );
check( 'day view is left out, page size still comes', array( 'list_limit' ), array_keys( npmp_tec_read_settings( array( 'tribe_events_calendar_options' => array( 'viewOption' => 'day', 'postsPerPage' => 8 ) ) ) ) );

echo "Is the setting still on its default\n";
check( 'unset option', true, npmp_tec_setting_on_default( 'default_view', null ) );
check( 'saved as the default', true, npmp_tec_setting_on_default( 'default_view', 'month' ) );
check( 'changed view', false, npmp_tec_setting_on_default( 'default_view', 'week' ) );
check( 'page size saved as the string 10', true, npmp_tec_setting_on_default( 'list_limit', '10' ) );
check( 'changed page size', false, npmp_tec_setting_on_default( 'list_limit', 5 ) );
check( 'accent default in capitals', true, npmp_tec_setting_on_default( 'accent', '#0F766E' ) );
check( 'the pinned legacy blue counts as changed', false, npmp_tec_setting_on_default( 'accent', '#2271b1' ) );
check( 'a value the calendar can\'t use falls back to the default', true, npmp_tec_setting_on_default( 'default_view', 'agenda' ) );
check( 'unknown setting is never on default', false, npmp_tec_setting_on_default( 'eventsSlug', null ) );

echo "Approve rows\n";
$rows = npmp_tec_settings_rows( $staged, array( 'npmp_calendar_default_view' => null, 'npmp_calendar_list_limit' => 5, 'npmp_calendar_accent' => '#aa3366' ) );
check( 'on default: ticked', array( 'tec' => 'list', 'value' => 'list', 'current' => 'month', 'same' => false, 'checked' => true ), $rows['default_view'] );
check( 'already changed: unticked, current value shown', array( 'tec' => '25', 'value' => 25, 'current' => 5, 'same' => false, 'checked' => false ), $rows['list_limit'] );
check( 'already the same: no tick', array( true, false ), array( $rows['accent']['same'], $rows['accent']['checked'] ) );
$rows = npmp_tec_settings_rows( array( 'default_view' => array( 'tec' => 'month', 'value' => 'month' ) ), array() );
check( 'TEC on the same default: same, no tick', array( true, false ), array( $rows['default_view']['same'], $rows['default_view']['checked'] ) );
check( 'no settings staged (a list from the older importer), no rows', array(), npmp_tec_settings_rows( array(), array( 'npmp_calendar_list_limit' => 5 ) ) );
check( 'a tampered staged value drops out', array(), npmp_tec_settings_rows( array( 'accent' => array( 'tec' => 'x', 'value' => 'javascript:1' ), 'bogus' => array( 'value' => 1 ) ), array() ) );

echo "Ticked settings\n";
check( 'keeps staged, mapped keys in map order', array( 'default_view', 'accent' ), npmp_tec_clean_setting_keys( array( 'accent', 'default_view', 'eventsSlug', array( 'x' ) ), $staged ) );
check( 'a mapped key that isn\'t staged drops out', array(), npmp_tec_clean_setting_keys( array( 'accent' ), array( 'list_limit' => array( 'tec' => '5', 'value' => 5 ) ) ) );
check( 'nothing ticked', array(), npmp_tec_clean_setting_keys( array(), $staged ) );

echo "Setting names and values in words\n";
check( 'view value', 'List', npmp_tec_setting_display( 'default_view', 'list' ) );
check( 'number value', '25', npmp_tec_setting_display( 'list_limit', 25 ) );
check( 'every mapped setting has a name', 3, count( array_filter( array_map( 'npmp_tec_setting_label', array_keys( $map ) ), static function ( $l ) use ( $map ) { return ! isset( $map[ $l ] ); } ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
