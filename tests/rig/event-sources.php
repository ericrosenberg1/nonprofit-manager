<?php
/**
 * Real-WordPress run of the Events Manager and Sugar Calendar importers on
 * the real plugins: seed events through each plugin's own code, import, and
 * check every date, time, place, link, category, status, and skip, then run
 * it again, turn the old plugin off through Switch Plugins, follow the old
 * links over HTTP, and import once more with the plugin off.
 *
 * Seeds, Events Manager (site timezone Europe/London):
 *   Rig EM Gala       one evening in New York time, a venue, a child category
 *   Rig EM Webinar    all day, online (a URL location)
 *   Rig EM Cancelled  cancelled (comes over as a draft)
 *   Rig EM Trashed    in the trash (stays behind)
 *   Rig EM Series     weekly, four dates with no post of their own
 * Seeds, Sugar Calendar:
 *   Rig SC Picnic     Los Angeles time, a location line, an event link, a calendar
 *   Rig SC Fair       all day
 *   Rig SC Trashed    in the trash (stays behind)
 *
 * Needs a throwaway WordPress with this plugin, Events Manager, and Sugar
 * Calendar Lite active, Pro inactive, `wp`
 * and `curl` on PATH, and the site served at its home URL (php -S works).
 * Visit wp-admin once first so both plugins create their tables. Verified on
 * SQLite with Events Manager 7.4.14 and Sugar Calendar Lite 3.17.0:
 *   wp plugin install events-manager sugar-calendar-lite --activate
 *   wp eval-file tests/rig/event-sources.php
 *
 * It runs on London time and puts the site's timezone back at the end. It
 * turns both plugins off at the end. Turn them on again for another run.
 * A second run reuses the seeded events.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';
$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );

$plugin_files = array(
	'events-manager' => 'events-manager/events-manager.php',
	'sugar-calendar' => 'sugar-calendar-lite/sugar-calendar-lite.php',
);

// ---------------------------------------------------------------------
// Child phases.
// ---------------------------------------------------------------------
if ( 0 === strpos( $phase, 'import:' ) ) {
	$slug  = substr( $phase, 7 );
	$state = npmp_event_import_state( $slug );
	$state['running'] = false;
	npmp_event_import_save_state( $slug, $state );
	$state = npmp_event_import_run( $slug, 60, 2 );
	echo 'JSON ', wp_json_encode( array( 'state' => $state, 'active' => is_plugin_active( $plugin_files[ $slug ] ) ) ), "\n";
	return;
}

if ( 0 === strpos( $phase, 'hub:' ) ) {
	$slug   = substr( $phase, 4 );
	$step   = function ( $name, $input = array() ) use ( $slug ) {
		$r = npmp_import_hub_run_step( $slug, $name, $input );
		return is_wp_error( $r ) ? $r->get_error_code() : $r;
	};
	$verify = $step( 'verify', array( 'verified' => 1 ) );
	$off    = $step( 'deactivate' );
	echo 'JSON ', wp_json_encode( array( 'verify' => $verify, 'deactivate' => $off, 'active' => is_plugin_active( $plugin_files[ $slug ] ) ) ), "\n";
	return;
}

if ( 0 === strpos( $phase, 'source:' ) ) {
	$slug    = substr( $phase, 7 );
	$sources = npmp_import_sources();
	$src     = $sources[ $slug ] ?? array();
	echo 'JSON ', wp_json_encode(
		array(
			'registered' => isset( $sources[ $slug ] ),
			'removal'    => isset( $src['data_removal'] ) ? array_keys( (array) $src['data_removal'] ) : array(),
			'has_batch'  => isset( $src['data_removal']['batch'] ),
			'has_data'   => isset( $src['has_data'] ) ? (bool) npmp_import_hub_call( $src['has_data'], false ) : null,
			'rows'       => isset( $src['counts'] ) ? npmp_import_hub_call( $src['counts'], null ) : null,
			'counts'     => 'events-manager' === $slug ? npmp_em_counts() : npmp_sc_counts(),
		)
	), "\n";
	return;
}

$child = function ( $phase ) {
	$cmd = sprintf( 'wp --path=%s eval-file %s %s 2>&1', escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( __FILE__ ), escapeshellarg( $phase ) );
	$out = array();
	exec( $cmd, $out );
	foreach ( $out as $line ) {
		if ( 0 === strpos( $line, 'JSON ' ) ) {
			return json_decode( substr( $line, 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === stripos( $l, 'deprecated' );
	} ) ) );
};

echo "Setup\n";
$ok( 'Events Manager is active', is_plugin_active( $plugin_files['events-manager'] ) );
$ok( 'Sugar Calendar is active', is_plugin_active( $plugin_files['sugar-calendar'] ) );
$ok( 'Pro is inactive', ! is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) );
// Every expected time below is London time. The child phases read it from
// the database, so set it there and put the site's own back at the end.
$rig_tz  = get_option( 'timezone_string' );
$rig_gmt = get_option( 'gmt_offset' );
register_shutdown_function(
	static function () use ( $rig_tz, $rig_gmt ) {
		update_option( 'timezone_string', $rig_tz );
		update_option( 'gmt_offset', $rig_gmt );
	}
);
update_option( 'timezone_string', 'Europe/London' );
update_option( 'gmt_offset', '' );
$ok( 'site timezone is Europe/London', 'Europe/London' === wp_timezone_string() );
$ok( 'both event sources registered', isset( npmp_event_import_sources()['events-manager'], npmp_event_import_sources()['sugar-calendar'] ) );
$ok( 'EM tables exist', '' !== npmp_em_table( 'em_events' ) && '' !== npmp_em_table( 'em_event_recurrences' ) );
$ok( 'Sugar tables exist', '' !== npmp_sc_table( 'sc_events' ) && '' !== npmp_sc_table( 'sc_eventmeta' ) );

// Start from a clean import: drop what an earlier run brought over.
foreach ( array( 'events-manager', 'sugar-calendar' ) as $slug ) {
	foreach ( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_npmp_import_source', 'meta_value' => $slug ) ) as $old ) {
		wp_delete_post( $old, true );
	}
	delete_option( 'npmp_event_import_' . $slug );
	$hub = get_option( 'npmp_import_hub', array() );
	unset( $hub[ $slug ] );
	update_option( 'npmp_import_hub', $hub );
}
foreach ( array( 'rig-fundraisers', 'rig-community', 'rig-outdoors' ) as $term_slug ) {
	$term = get_term_by( 'slug', $term_slug, 'npmp_event_category' );
	if ( $term ) {
		wp_delete_term( $term->term_id, 'npmp_event_category' );
	}
}

// ---------------------------------------------------------------------
// Seed through each plugin's own code (once).
// ---------------------------------------------------------------------
$em_ids = function ( $title ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT event_id, post_id FROM {$wpdb->prefix}em_events WHERE event_name = %s AND event_type IN ('single','recurring') ORDER BY event_id ASC LIMIT 1", $title ), ARRAY_A );
};

if ( ! $em_ids( 'Rig EM Gala' ) ) {
	update_option( 'dbem_event_status_enabled', 1 );
	$parent = wp_insert_term( 'Rig Community', 'event-categories', array( 'slug' => 'rig-community' ) );
	// Not $child: that name holds the closure that runs each child phase.
	$sub_cat = wp_insert_term( 'Rig Fundraisers', 'event-categories', array( 'slug' => 'rig-fundraisers', 'parent' => $parent['term_id'], 'description' => 'Money nights' ) );

	$loc = new EM_Location();
	$loc->location_name     = 'Rig Town Hall';
	$loc->location_address  = '1 Main St';
	$loc->location_town     = 'Springfield';
	$loc->location_state    = 'IL';
	$loc->location_postcode = '62701';
	$loc->location_country  = 'US';
	$loc->save();

	$save = function ( array $post ) {
		$_POST    = $post;
		$_REQUEST = $post;
		$e        = new EM_Event();
		$e->get_post();
		$e->save();
		$_POST    = array();
		$_REQUEST = array();
		return $e;
	};
	$save(
		array(
			'event_name'       => 'Rig EM Gala',
			'content'          => 'Black tie.',
			'event_type'       => 'single',
			'event_start_date' => '2027-03-10',
			'event_end_date'   => '2027-03-10',
			'event_timezone'   => 'America/New_York',
			'event_timeranges' => array( array( 'start' => '18:00', 'end' => '20:30' ) ),
			'location_id'      => $loc->location_id,
			'event_categories' => array( $sub_cat['term_id'] ),
		)
	);
	$save(
		array(
			'event_name'             => 'Rig EM Webinar',
			'content'                => 'Online.',
			'event_type'             => 'single',
			'event_start_date'       => '2027-05-01',
			'event_end_date'         => '2027-05-01',
			'event_timezone'         => 'Europe/London',
			'event_timeranges'       => array( array( 'all_day' => 1 ) ),
			'location_type'          => 'url',
			'event_location_url'     => 'https://example.org/rig-live',
			'event_location_url_text' => 'Watch',
		)
	);
	$cancelled = $save(
		array(
			'event_name'          => 'Rig EM Cancelled',
			'content'             => 'Off.',
			'event_type'          => 'single',
			'event_start_date'    => '2027-06-01',
			'event_end_date'      => '2027-06-01',
			'event_timezone'      => 'Europe/London',
			'event_timeranges'    => array( array( 'start' => '09:00', 'end' => '10:00' ) ),
			'event_active_status' => 0,
			'no_location'         => 1,
		)
	);
	$trashed = $save(
		array(
			'event_name'       => 'Rig EM Trashed',
			'content'          => 'Gone.',
			'event_type'       => 'single',
			'event_start_date' => '2027-06-02',
			'event_end_date'   => '2027-06-02',
			'event_timezone'   => 'Europe/London',
			'event_timeranges' => array( array( 'start' => '09:00', 'end' => '10:00' ) ),
			'no_location'      => 1,
		)
	);
	wp_trash_post( $trashed->post_id );
	$save(
		array(
			'event_name'       => 'Rig EM Series',
			'content'          => 'Every Monday.',
			'event_type'       => 'recurring',
			'event_start_date' => '2027-04-05',
			'event_end_date'   => '2027-04-05',
			'event_timezone'   => 'Europe/London',
			'timeranges'       => array( array( 'start' => '10:00', 'end' => '11:00' ) ),
			'location_id'      => $loc->location_id,
			'recurrences'      => array(
				'include' => array(
					array(
						'recurrence_freq'       => 'weekly',
						'recurrence_interval'   => 1,
						'recurrence_bydays'     => array( 1 ),
						'recurrence_start_date' => '2027-04-05',
						'recurrence_end_date'   => '2027-04-26',
						'timeranges'            => array( array( 'start' => '10:00', 'end' => '11:00' ) ),
					),
				),
			),
		)
	);
}

$sc_id = function ( $title ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}sc_events WHERE title = %s LIMIT 1", $title ) );
};

if ( ! $sc_id( 'Rig SC Picnic' ) ) {
	$cal  = wp_insert_term( 'Rig Outdoors', 'sc_event_category', array( 'slug' => 'rig-outdoors' ) );
	$make = function ( $title, $start, $end, $tz, $all_day, $status, array $meta = array() ) {
		$post_id = wp_insert_post( array( 'post_type' => 'sc_event', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $title . ' body', 'post_name' => sanitize_title( $title ) ) );
		sugar_calendar_add_event(
			array_merge(
				array(
					'object_id'      => $post_id,
					'object_type'    => 'post',
					'object_subtype' => 'sc_event',
					'title'          => $title,
					'content'        => $title . ' body',
					'status'         => 'publish',
					'start'          => $start,
					'start_tz'       => $tz,
					'end'            => $end,
					'end_tz'         => $tz,
					'all_day'        => $all_day,
				),
				$meta
			)
		);
		if ( 'trash' === $status ) {
			wp_trash_post( $post_id );
		}
		return $post_id;
	};
	$picnic = $make( 'Rig SC Picnic', '2027-06-01 12:00:00', '2027-06-01 14:00:00', 'America/Los_Angeles', 0, 'publish', array( 'location' => 'Rig Central Park' ) );
	wp_set_object_terms( $picnic, array( (int) $cal['term_id'] ), 'sc_event_category' );

	$make( 'Rig SC Fair', '2027-07-04 00:00:00', '2027-07-04 23:59:59', '', 1, 'publish' );
	$make( 'Rig SC Trashed', '2027-07-05 09:00:00', '2027-07-05 10:00:00', '', 0, 'trash' );
}

// Lite's editor has no event link field and add_event() keeps only the meta
// keys Lite registers. Pro's editor and Sugar's own TEC importer save the link
// as event meta 'url', so write it the way they do.
if ( ! get_event_meta( $sc_id( 'Rig SC Picnic' ), 'url', true ) ) {
	add_event_meta( $sc_id( 'Rig SC Picnic' ), 'url', 'https://example.org/rig-picnic' );
}

$gala = $em_ids( 'Rig EM Gala' );
$ok( 'EM saved the gala with a post', $gala && (int) $gala['post_id'] > 0 );
$ok( 'EM saved the webinar link where the source reads it', 'https://example.org/rig-live' === get_post_meta( (int) $em_ids( 'Rig EM Webinar' )['post_id'], '_event_location_url', true ) );
$series_dates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}em_events WHERE event_name = 'Rig EM Series' AND event_type = 'recurrence' AND (post_id IS NULL OR post_id = 0)" );
$ok( 'EM made four series dates with no post of their own', 4 === $series_dates, (string) $series_dates );
$ok( 'Sugar saved the picnic location as event meta', 'Rig Central Park' === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}sc_eventmeta WHERE sc_event_id = %d AND meta_key = 'location'", $sc_id( 'Rig SC Picnic' ) ) ) );

// ---------------------------------------------------------------------
// Counts on Switch Plugins before the import.
// ---------------------------------------------------------------------
echo "Counts\n";
$em_src = $child( 'source:events-manager' );
$ok( 'EM hub source registered', ! empty( $em_src['registered'] ), $em_src );
$ok( 'EM data removal has no batch', empty( $em_src['has_batch'] ), $em_src );
$ok( 'EM counts: 3 single events and 1 series', 4 === (int) ( $em_src['counts']['events'] ?? -1 ), $em_src['counts'] ?? $em_src );
$ok( 'EM counts: 7 dates', 7 === (int) ( $em_src['counts']['dates'] ?? -1 ), $em_src['counts'] ?? '' );
$ok( 'EM counts: 1 venue', 1 === (int) ( $em_src['counts']['venues'] ?? -1 ), $em_src['counts'] ?? '' );
$ok( 'EM counts: 1 skipped', 1 === (int) ( $em_src['counts']['skipped'] ?? -1 ), $em_src['counts'] ?? '' );
$sc_src = $child( 'source:sugar-calendar' );
$ok( 'Sugar hub source registered', ! empty( $sc_src['registered'] ), $sc_src );
$ok( 'Sugar data removal has no batch', empty( $sc_src['has_batch'] ), $sc_src );
$ok( 'Sugar counts: 2 events, 1 skipped', 2 === (int) ( $sc_src['counts']['events'] ?? -1 ) && 1 === (int) ( $sc_src['counts']['skipped'] ?? -1 ), $sc_src['counts'] ?? $sc_src );
$ok( 'Sugar hub screen has no venue row', ! in_array( 'Venues', array_column( (array) ( $sc_src['rows'] ?? array() ), 'label' ), true ), $sc_src['rows'] ?? $sc_src );
$ok( 'EM hub screen has a venue row', in_array( 'Venues', array_column( (array) ( $em_src['rows'] ?? array() ), 'label' ), true ), $em_src['rows'] ?? $em_src );
$ok( 'EM hub screen shows 7 old event dates', 'Event dates' === ( $em_src['rows'][0]['label'] ?? '' ) && 7 === (int) $em_src['rows'][0]['old'], $em_src['rows'][0] ?? $em_src );

// ---------------------------------------------------------------------
// Import.
// ---------------------------------------------------------------------
$find = function ( $slug, $title ) {
	return get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'title' => $title, 'meta_key' => '_npmp_import_source', 'meta_value' => $slug, 'orderby' => 'ID', 'order' => 'ASC' ) );
};

echo "Events Manager import\n";
$r = $child( 'import:events-manager' );
$ok( 'EM import finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'EM created 7 events', 7 === (int) ( $r['state']['results']['created'] ?? -1 ), $r['state']['results'] ?? $r );
$ok( 'EM nothing invalid', 0 === (int) ( $r['state']['results']['invalid'] ?? -1 ), $r['state']['results'] ?? '' );
$ok( 'EM 4 old links (3 posts and the series)', 4 === (int) ( $r['state']['results']['paths'] ?? -1 ), $r['state']['results'] ?? '' );

$g = $find( 'events-manager', 'Rig EM Gala' );
$ok( 'gala came over once', 1 === count( $g ) );
if ( $g ) {
	$id = $g[0]->ID;
	$ok( 'gala start is 6 pm New York in London time', '2027-03-10 23:00:00' === get_post_meta( $id, '_npmp_event_start', true ), get_post_meta( $id, '_npmp_event_start', true ) );
	$ok( 'gala end is 8:30 pm New York in London time', '2027-03-11 01:30:00' === get_post_meta( $id, '_npmp_event_end', true ), get_post_meta( $id, '_npmp_event_end', true ) );
	$ok( 'gala location has the venue and address', 'Rig Town Hall, 1 Main St, Springfield, IL 62701, US' === get_post_meta( $id, '_npmp_event_location', true ), get_post_meta( $id, '_npmp_event_location', true ) );
	$ok( 'gala is published', 'publish' === $g[0]->post_status );
	$ok( 'gala keeps its body', 'Black tie.' === trim( $g[0]->post_content ) );
	$terms = wp_get_object_terms( $id, 'npmp_event_category' );
	$ok( 'gala category came over with its parent', 1 === count( $terms ) && 'rig-fundraisers' === $terms[0]->slug && $terms[0]->parent && 'rig-community' === get_term( $terms[0]->parent )->slug, wp_list_pluck( $terms, 'slug' ) );
	$ok( 'gala category description', 1 === count( $terms ) && 'Money nights' === $terms[0]->description );
	$ok( 'gala old link stored', array( '/events/rig-em-gala' ) === get_post_meta( $id, '_npmp_import_old_path' ), get_post_meta( $id, '_npmp_import_old_path' ) );
}
$w = $find( 'events-manager', 'Rig EM Webinar' );
if ( $w ) {
	$id = $w[0]->ID;
	$ok( 'webinar is all day', '1' === (string) get_post_meta( $id, '_npmp_event_all_day', true ) );
	$ok( 'webinar starts at midnight', '2027-05-01 00:00:00' === get_post_meta( $id, '_npmp_event_start', true ), get_post_meta( $id, '_npmp_event_start', true ) );
	$ok( 'webinar ends the same day', 0 === strpos( (string) get_post_meta( $id, '_npmp_event_end', true ), '2027-05-01 23:59' ), get_post_meta( $id, '_npmp_event_end', true ) );
	$ok( 'webinar link came over', 'https://example.org/rig-live' === get_post_meta( $id, '_npmp_event_url', true ) );
	$ok( 'webinar has no location', '' === get_post_meta( $id, '_npmp_event_location', true ) );
} else {
	$ok( 'webinar came over', false );
}
$c = $find( 'events-manager', 'Rig EM Cancelled' );
$ok( 'cancelled event came over as a draft', 1 === count( $c ) && 'draft' === $c[0]->post_status, $c ? $c[0]->post_status : 'missing' );
$ok( 'trashed event stayed behind', 0 === count( $find( 'events-manager', 'Rig EM Trashed' ) ) );
$ok( 'EM imported categories count the parent too', 2 === npmp_event_import_imported_counts( 'events-manager' )['categories'], npmp_event_import_imported_counts( 'events-manager' ) );
$s = $find( 'events-manager', 'Rig EM Series' );
$ok( 'series came over as 4 events', 4 === count( $s ), (string) count( $s ) );
if ( 4 === count( $s ) ) {
	$starts = array_map( function ( $p ) {
		return get_post_meta( $p->ID, '_npmp_event_start', true );
	}, $s );
	sort( $starts );
	$ok( 'series dates are the four Mondays at 10 am', array( '2027-04-05 10:00:00', '2027-04-12 10:00:00', '2027-04-19 10:00:00', '2027-04-26 10:00:00' ) === $starts, $starts );
	$with_path = array_values( array_filter( $s, function ( $p ) {
		return (bool) get_post_meta( $p->ID, '_npmp_import_old_path', true );
	} ) );
	$ok( 'only the first date holds the series link', 1 === count( $with_path ) && '2027-04-05 10:00:00' === get_post_meta( $with_path[0]->ID, '_npmp_event_start', true ) && '/events/rig-em-series' === get_post_meta( $with_path[0]->ID, '_npmp_import_old_path', true ) );
	$ok( 'series dates borrow the parent body', 'Every Monday.' === trim( $s[1]->post_content ) );
	$ok( 'series dates have the venue', 0 === strpos( (string) get_post_meta( $s[2]->ID, '_npmp_event_location', true ), 'Rig Town Hall' ) );
	$ok( 'series dates are published', 'publish' === $s[3]->post_status );
}

echo "Sugar Calendar import\n";
$r = $child( 'import:sugar-calendar' );
$ok( 'Sugar import finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'Sugar created 2 events', 2 === (int) ( $r['state']['results']['created'] ?? -1 ), $r['state']['results'] ?? $r );
$p = $find( 'sugar-calendar', 'Rig SC Picnic' );
$ok( 'picnic came over once', 1 === count( $p ) );
if ( $p ) {
	$id = $p[0]->ID;
	$ok( 'picnic start is noon Los Angeles in London time', '2027-06-01 20:00:00' === get_post_meta( $id, '_npmp_event_start', true ), get_post_meta( $id, '_npmp_event_start', true ) );
	$ok( 'picnic end is 2 pm Los Angeles in London time', '2027-06-01 22:00:00' === get_post_meta( $id, '_npmp_event_end', true ), get_post_meta( $id, '_npmp_event_end', true ) );
	$ok( 'picnic location', 'Rig Central Park' === get_post_meta( $id, '_npmp_event_location', true ) );
	$ok( 'picnic link', 'https://example.org/rig-picnic' === get_post_meta( $id, '_npmp_event_url', true ) );
	$terms = wp_get_object_terms( $id, 'npmp_event_category', array( 'fields' => 'slugs' ) );
	$ok( 'picnic calendar became a category', array( 'rig-outdoors' ) === $terms, $terms );
	$ok( 'picnic old link stored', '/events/rig-sc-picnic' === get_post_meta( $id, '_npmp_import_old_path', true ) );
}
$f = $find( 'sugar-calendar', 'Rig SC Fair' );
$ok( 'fair is all day on July 4', 1 === count( $f ) && '1' === (string) get_post_meta( $f[0]->ID, '_npmp_event_all_day', true ) && '2027-07-04 00:00:00' === get_post_meta( $f[0]->ID, '_npmp_event_start', true ) );
$ok( 'trashed Sugar event stayed behind', 0 === count( $find( 'sugar-calendar', 'Rig SC Trashed' ) ) );

echo "Second run\n";
$r = $child( 'import:events-manager' );
$ok( 'EM rerun creates nothing', 0 === (int) ( $r['state']['results']['created'] ?? -1 ) && 7 === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );
$r = $child( 'import:sugar-calendar' );
$ok( 'Sugar rerun creates nothing', 0 === (int) ( $r['state']['results']['created'] ?? -1 ) && 2 === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );

// ---------------------------------------------------------------------
// Switch Plugins: verify and turn off, then old links over HTTP.
// ---------------------------------------------------------------------
echo "Switch Plugins\n";
foreach ( array( 'events-manager', 'sugar-calendar' ) as $slug ) {
	$h = $child( 'hub:' . $slug );
	$ok( "$slug verified", ! is_string( $h['verify'] ?? 'x' ), $h );
	$ok( "$slug turned off", isset( $h['active'] ) && false === $h['active'], $h );
}
$ok( 'EM events still in the database', 7 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}em_events WHERE event_name LIKE 'Rig EM %'" ) );
$ok( 'Sugar events still in the database', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sc_events WHERE title LIKE 'Rig SC %'" ) );

echo "Old links over HTTP\n";
$get = function ( $url ) {
	return trim( (string) shell_exec( 'curl -s -o /dev/null --max-time 20 -w "%{http_code} %{redirect_url}" ' . escapeshellarg( $url ) ) );
};
$lands = function ( $old, $post_id ) use ( $get ) {
	$hit = $get( home_url( $old ) );
	$new = get_permalink( $post_id );
	// The new event either sits at the same address (same slug) or the old one 301s to it.
	return array( '301 ' . $new === $hit || ( '200' === $hit && trailingslashit( home_url( $old ) ) === $new ), $hit . ' (new ' . $new . ')' );
};
if ( $g ) {
	list( $pass, $why ) = $lands( '/events/rig-em-gala/', $g[0]->ID );
	$ok( 'old gala link reaches the new event', $pass, $why );
}
if ( 4 === count( $s ) ) {
	list( $pass, $why ) = $lands( '/events/rig-em-series/', $with_path[0]->ID );
	$ok( 'old series link reaches its first date', $pass && 0 === strpos( $why, '301 ' ), $why );
}
if ( $p ) {
	list( $pass, $why ) = $lands( '/events/rig-sc-picnic/', $p[0]->ID );
	$ok( 'old picnic link reaches the new event', $pass, $why );
}
$hit = $get( home_url( '/events/rig-not-a-real-event/' ) );
$ok( 'an unknown event link still 404s', '404' === substr( $hit, 0, 3 ), $hit );

echo "Import with the plugins off\n";
$r = $child( 'import:events-manager' );
$ok( 'EM import reads with EM off', false === ( $r['active'] ?? true ) && 7 === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );
$r = $child( 'import:sugar-calendar' );
$ok( 'Sugar import reads with Sugar off', false === ( $r['active'] ?? true ) && 2 === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
