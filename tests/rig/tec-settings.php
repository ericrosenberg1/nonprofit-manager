<?php
/**
 * Real-WordPress check of the settings half of The Events Calendar importer:
 * seed TEC's two option rows and two events straight into the database, stage,
 * approve with one setting ticked, migrate, and confirm the ticked setting
 * saved, the unticked one didn't, a value you already changed survived, and a
 * staging list from before the importer read settings still migrates.
 *
 * The Events Calendar doesn't need to be installed. The importer reads its
 * posts, meta, and options from the database. The events half, with the real
 * TEC, is tests/rig/tec-importer.php.
 *
 * Needs a throwaway WordPress with this plugin active:
 *   wp eval-file tests/rig/tec-settings.php
 */

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . $detail . ']' : '' );
};

if ( ! function_exists( 'npmp_tec_settings_map' ) ) {
	echo "Nonprofit Manager isn't active, or it's a version without the settings import.\n";
	exit( 1 );
}

global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

// TEC is off here, so stand in for its post type the way a deactivated TEC leaves it: rows only.
if ( ! post_type_exists( 'tribe_events' ) ) {
	register_post_type( 'tribe_events', array( 'public' => true, 'rewrite' => array( 'slug' => 'event' ) ) );
}

$targets = array( 'npmp_calendar_default_view', 'npmp_calendar_list_limit', 'npmp_calendar_accent' );
$clean   = function () use ( $targets ) {
	foreach ( array( 'npmp_event', 'tribe_events' ) as $type ) {
		foreach ( get_posts( array( 'post_type' => $type, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) {
			wp_delete_post( $pid, true );
		}
	}
	foreach ( array_merge( $targets, array( 'tribe_events_calendar_options', 'tribe_customizer', 'npmp_tec_imported' ) ) as $name ) {
		delete_option( $name );
	}
	npmp_tec_reset_stage();
};
$seed_event = function ( $title, $day ) {
	return wp_insert_post(
		array(
			'post_type'   => 'tribe_events',
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => array(
				'_EventStartDate'    => "$day 18:00:00",
				'_EventEndDate'      => "$day 20:00:00",
				'_EventStartDateUTC' => "$day 18:00:00",
				'_EventEndDateUTC'   => "$day 20:00:00",
				'_EventTimezone'     => 'UTC',
			),
		)
	);
};
$run = function () {
	$rounds = 0;
	do {
		$res = npmp_tec_migrate_batch( 1 );
	} while ( ! $res['done'] && $rounds++ < 20 );
	return npmp_tec_get_stage();
};
$render = function ( $callback, $stage ) {
	ob_start();
	call_user_func( $callback, $stage );
	return (string) ob_get_clean();
};

echo "Clean slate, then seed TEC's rows\n";
$clean();
$tec_options = array(
	'viewOption'              => 'list',
	'postsPerPage'            => '25',
	'tribeEnableViews'        => array( 'list', 'month', 'day' ),
	'showComments'            => true,
	'eventsSlug'              => 'whats-on',
	'singleEventSlug'         => 'whats-on-event',
	'defaultCurrencySymbol'   => '£',
	'defaultCurrencyCode'     => 'GBP',
	'reverseCurrencyPosition' => false,
	'embedGoogleMaps'         => true,
	'dateWithYearFormat'      => 'j F Y',
);
$tec_customizer = array( 'global_elements' => array( 'accent_color' => '#AA3366', 'link_color' => '#112233' ) );
update_option( 'tribe_events_calendar_options', $tec_options );
update_option( 'tribe_customizer', $tec_customizer );
$e1 = $seed_event( 'Rig Settings Supper', '2027-02-10' );
$e2 = $seed_event( 'Rig Settings Walk', '2027-02-11' );
$ok( 'two TEC events and both option rows seeded', $e1 && $e2 && 2 === npmp_tec_count_events() && is_array( get_option( 'tribe_customizer' ) ) );

// You already changed the list size. The other two are untouched.
update_option( 'npmp_calendar_list_limit', 5 );
$ok( 'view and color options start unset', null === get_option( 'npmp_calendar_default_view', null ) && null === get_option( 'npmp_calendar_accent', null ) );

echo "Step 1: settings land in the staging list on the last batch\n";
$res = npmp_tec_stage_batch( true, 1 );
$ok( 'not staged while events are still being read', ! $res['done'] && array() === npmp_tec_get_stage()['settings'] );
$rounds = 0;
while ( ! $res['done'] && $rounds++ < 10 ) {
	$res = npmp_tec_stage_batch( false, 1 );
}
$stage = npmp_tec_get_stage();
$ok( 'both events staged', 2 === count( $stage['items'] ) );
$ok( 'three settings staged', array( 'default_view', 'list_limit', 'accent' ) === array_keys( $stage['settings'] ), wp_json_encode( $stage['settings'] ) );
$ok( 'staged values', 'list' === $stage['settings']['default_view']['value'] && 25 === $stage['settings']['list_limit']['value'] && '#aa3366' === $stage['settings']['accent']['value'], wp_json_encode( $stage['settings'] ) );

echo "Step 3: the Approve screen\n";
$rows = npmp_tec_settings_rows( $stage['settings'], npmp_tec_current_settings() );
$ok( 'view ticked (still on the default)', true === $rows['default_view']['checked'] && 'month' === $rows['default_view']['current'] );
$ok( 'list size unticked (you changed it to 5)', false === $rows['list_limit']['checked'] && 5 === $rows['list_limit']['current'] );
$ok( 'color ticked (still on the default)', true === $rows['accent']['checked'] && '#0f766e' === $rows['accent']['current'] );
npmp_tec_set_skip( array() );
$stage = npmp_tec_get_stage();
$html  = $render( 'npmp_tec_render_approve', $stage );
$ok( 'table has a ticked box for the view', 1 === preg_match( '/name="npmp_tec_settings\[\]" value="default_view"\s+checked=\'checked\'/', $html ) );
$ok( 'table has an unticked box for the list size', 1 === preg_match( '/name="npmp_tec_settings\[\]" value="list_limit"\s+aria-label/', $html ) );
$ok( 'row shows TEC\'s value, the new value, and the current one', false !== strpos( $html, '<td>#AA3366</td><td>#aa3366</td><td>#0f766e</td>' ) );
$ok( 'the sentence about settings with no match is there', false !== strpos( $html, 'URL slugs, currency, timezone display, comments, and maps.' ) );
$ok( 'the events form still carries its nonce and action', false !== strpos( $html, 'name="npmp_tec_nonce"' ) && false !== strpos( $html, 'value="npmp_tec_approve"' ) );

echo "Step 4: tick the view only, migrate\n";
$queued = npmp_tec_approve( array(), array( 'default_view', 'eventsSlug', 'not_a_setting' ) );
$stage  = npmp_tec_get_stage();
$ok( 'two events queued', 2 === $queued );
$ok( 'only the real, ticked key is kept', array( 'default_view' ) === $stage['settings_apply'], wp_json_encode( $stage['settings_apply'] ) );
$res = npmp_tec_migrate_batch( 1 );
$ok( 'nothing saved before the last batch', ! $res['done'] && null === get_option( 'npmp_calendar_default_view', null ) );
$stage = $run();
$ok( 'both events created', 2 === $stage['results']['created'] && ! $stage['results']['failed'], wp_json_encode( $stage['results'] ) );
$ok( 'ticked setting saved: default view is list', 'list' === get_option( 'npmp_calendar_default_view' ) );
$ok( 'unticked setting not saved: color option still unset', null === get_option( 'npmp_calendar_accent', null ) );
$ok( 'the value you changed survived: list size still 5', 5 === (int) get_option( 'npmp_calendar_list_limit' ) );
$ok( 'report lists the one change', array( 'default_view' => array( 'from' => 'month', 'to' => 'list' ) ) === $stage['settings_changed'], wp_json_encode( $stage['settings_changed'] ) );
$html = $render( 'npmp_tec_render_migrate', $stage );
$ok( 'results screen names the change', false !== strpos( $html, 'You brought over 1 setting:' ) && false !== strpos( $html, 'Default view: Month changed to List' ), wp_strip_all_tags( $html ) );
$ok( 'TEC\'s own options are untouched', $tec_options === get_option( 'tribe_events_calendar_options' ) && $tec_customizer === get_option( 'tribe_customizer' ) );
$ok( 'the calendar opens in list view', 'list' === get_option( 'npmp_calendar_default_view', 'month' ) && in_array( get_option( 'npmp_calendar_default_view' ), array( 'month', 'week', 'list' ), true ) );

echo "Run again: tick the box on the value you changed\n";
npmp_tec_stage_batch( true );
$stage = npmp_tec_get_stage();
$rows  = npmp_tec_settings_rows( $stage['settings'], npmp_tec_current_settings() );
$ok( 'view now reads as already the same, no tick', true === $rows['default_view']['same'] && false === $rows['default_view']['checked'] );
$ok( 'both events flagged as already moved', 0 === count( npmp_tec_selected_keys( $stage ) ) );
npmp_tec_set_skip( array() );
$html = $render( 'npmp_tec_render_approve', npmp_tec_get_stage() );
$ok( 'Approve screen still offers the settings with no events left', false !== strpos( $html, 'Bring over the settings' ) && false !== strpos( $html, 'value="list_limit"' ) );
$ok( 'the matching setting gets a disabled box', 1 === preg_match( '/<input type="checkbox" disabled[^>]*>\s*<\/th><td><strong>Default view/', $html ) );
npmp_tec_approve( array(), array( 'list_limit', 'default_view' ) );
$stage = $run();
$ok( 'ticked box overwrites your value: list size is 25', 25 === (int) get_option( 'npmp_calendar_list_limit' ) );
$ok( 'report lists only the list size', array( 'list_limit' => array( 'from' => 5, 'to' => 25 ) ) === $stage['settings_changed'], wp_json_encode( $stage['settings_changed'] ) );
$ok( 'no new events', 0 === $stage['results']['created'] && 2 === count( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ) );
$ok( 'color still unset after two runs', null === get_option( 'npmp_calendar_accent', null ) );

echo "A half-finished import from the older importer (no settings keys)\n";
foreach ( get_posts( array( 'post_type' => 'npmp_event', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) {
	wp_delete_post( $pid, true );
}
update_option( 'npmp_calendar_list_limit', 5 );
delete_option( 'npmp_calendar_default_view' );
npmp_tec_stage_batch( true );
$old = get_option( npmp_tec_stage_option() );
unset( $old['settings'], $old['settings_apply'], $old['settings_changed'] );
$old['reviewed'] = true;
update_option( npmp_tec_stage_option(), $old, false );
$stage = npmp_tec_get_stage();
$ok( 'missing keys read as no settings staged', array() === $stage['settings'] && array() === $stage['settings_apply'] && array() === $stage['settings_changed'] );
$res = npmp_tec_stage_batch();
$ok( 'a finished list isn\'t read again', $res['done'] && array() === npmp_tec_get_stage()['settings'] );
$html = $render( 'npmp_tec_render_approve', $stage );
$ok( 'Approve screen renders with no settings table', false === strpos( $html, 'npmp_tec_settings[]' ) && false !== strpos( $html, 'Approve and move 2 events' ) );
npmp_tec_approve( array(), array( 'default_view' ) );
$stage = $run();
$ok( 'events still migrate', 2 === $stage['results']['created'], wp_json_encode( $stage['results'] ) );
$ok( 'no setting changes, even with a posted key', array() === $stage['settings_changed'] && null === get_option( 'npmp_calendar_default_view', null ) && 5 === (int) get_option( 'npmp_calendar_list_limit' ) );
$html = $render( 'npmp_tec_render_migrate', $stage );
$ok( 'results screen has no settings line', false === strpos( $html, 'You brought over' ) && false !== strpos( $html, 'You moved 2 events.' ) );

echo "No TEC option rows at all\n";
delete_option( 'tribe_events_calendar_options' );
delete_option( 'tribe_customizer' );
npmp_tec_stage_batch( true );
$ok( 'nothing staged, no warnings', array() === npmp_tec_get_stage()['settings'] );

$clean();
printf( "\n%s\n", $fails ? "$fails FAILED" : 'All checks passed.' );
exit( $fails ? 1 : 0 );
