<?php
/**
 * File path: includes/import/event-organiser-source.php
 *
 * Event Organiser as an event source (event-importer.php) and a Switch Plugins
 * source. Read from Event Organiser 3.12.12's own source:
 *
 * - Each event is an `event` post. Every date of it is a row in
 *   {prefix}eo_events (event_id, post_id, StartDate, EndDate, StartTime,
 *   FinishTime, event_occurrence), one row per date, a repeating event too.
 *   Dates you take out of a series are deleted from that table and listed in
 *   the schedule's 'exclude'.
 * - Dates and times are site-local wall time (eo_get_blog_timezone()). There
 *   is no per-event time zone and no UTC copy.
 * - All day lives in post meta _eventorganiser_event_schedule['all_day'].
 * - Venues are the event-venue taxonomy. Their address is in its own table,
 *   {prefix}eo_venuemeta (eo_venue_id, meta_key _address, _city, _state,
 *   _postcode, _country).
 * - Categories are event-category (with parents). event-tag has no home here.
 * - Old links are /{url_event}/{post_name}/ (option eventorganiser_options,
 *   default "events/event"). Every date of a series shares that link.
 * - No organisers, no online link, no cancelled status, no bookings (those
 *   are in the paid Event Organiser Pro).
 *
 * Never read: eventorganiser_options['google_api_key'] (only url_event is read).
 *
 * Event Organiser's uninstall (eventorganiser_uninstall_site()) runs on every
 * delete with no setting to stop it: it drops eo_events and eo_venuemeta and
 * deletes every category, venue, and tag. It leaves the event posts. So step 4
 * warns, and step 5 stays unavailable.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-event-sources.php
// =====================================================================

/**
 * Post status for an imported Event Organiser date.
 *
 * @param string $post_status Status of the event post.
 * @return string publish, private, pending, draft, future, or '' to skip.
 */
function npmp_eo_status( $post_status ) {
	$post_status = (string) $post_status;
	if ( in_array( $post_status, array( 'publish', 'private', 'pending', 'draft', 'future' ), true ) ) {
		return $post_status;
	}
	return '';
}

/**
 * Is the event all day? EO saved 1, '1', and true over the years.
 *
 * @param mixed $schedule _eventorganiser_event_schedule, unserialized.
 * @return bool
 */
function npmp_eo_all_day( $schedule ) {
	return is_array( $schedule ) && ! empty( $schedule['all_day'] ) && '0' !== (string) $schedule['all_day'];
}

/**
 * The events slug from eventorganiser_options.
 *
 * @param mixed $options Stored eventorganiser_options.
 * @return string
 */
function npmp_eo_event_slug( $options ) {
	$slug = is_array( $options ) && isset( $options['url_event'] ) && is_string( $options['url_event'] ) ? trim( $options['url_event'], '/' ) : '';
	return '' !== $slug ? $slug : 'events/event';
}

// =====================================================================
// Database
// =====================================================================

/**
 * The venue line for an event, read from the term tables and eo_venuemeta.
 *
 * @param int $post_id Event post.
 * @return string
 */
function npmp_eo_venue( $post_id ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin import, table names from $wpdb.
	$venue = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = 'event-venue' ORDER BY t.term_id ASC LIMIT 1", (int) $post_id ), ARRAY_A );
	if ( ! $venue ) {
		return '';
	}
	$parts = array();
	$meta  = npmp_event_import_table( 'eo_venuemeta' );
	if ( $meta ) {
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta} WHERE eo_venue_id = %d AND meta_key IN ('_address','_city','_state','_postcode','_country')", (int) $venue['term_id'] ), ARRAY_A ) as $row ) {
			$parts[ ltrim( $row['meta_key'], '_' ) ] = (string) $row['meta_value'];
		}
	}
	// phpcs:enable
	return npmp_tec_format_venue(
		html_entity_decode( (string) $venue['name'], ENT_QUOTES, 'UTF-8' ),
		array(
			'address' => $parts['address'] ?? '',
			'city'    => $parts['city'] ?? '',
			'state'   => $parts['state'] ?? '',
			'zip'     => $parts['postcode'] ?? '',
			'country' => $parts['country'] ?? '',
		)
	);
}

/**
 * The next dates, one item per eo_events row.
 *
 * @param int $after Last event_id done.
 * @param int $limit How many.
 * @return array[]
 */
function npmp_eo_batch( $after, $limit ) {
	global $wpdb;
	$table = npmp_event_import_table( 'eo_events' );
	if ( ! $table ) {
		return array();
	}
	$site_tz = wp_timezone();
	$slug    = npmp_eo_event_slug( get_option( 'eventorganiser_options', array() ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id > %d ORDER BY event_id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$out   = array();
	$first = array();
	foreach ( (array) $rows as $row ) {
		$post   = get_post( (int) $row['post_id'] );
		$status = ( $post && 'event' === $post->post_type ) ? npmp_eo_status( $post->post_status ) : '';
		if ( '' === $status ) {
			$out[] = array( 'cursor' => (int) $row['event_id'], 'skip' => true );
			continue;
		}
		if ( ! isset( $first[ $post->ID ] ) ) {
			$first[ $post->ID ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(event_id) FROM {$table} WHERE post_id = %d", $post->ID ) );
		}
		$is_first = (int) $row['event_id'] === $first[ $post->ID ];
		$all_day  = npmp_eo_all_day( get_post_meta( $post->ID, '_eventorganiser_event_schedule', true ) );
		$times    = npmp_tec_event_times(
			array(
				'start'    => trim( $row['StartDate'] . ' ' . ( $row['StartTime'] ?: '00:00:00' ) ),
				'end'      => trim( ( $row['EndDate'] ?: $row['StartDate'] ) . ' ' . ( $row['FinishTime'] ?: '23:59:00' ) ),
				'timezone' => '',
				'all_day'  => $all_day ? 'yes' : '',
			),
			$site_tz
		);
		$out[] = array(
			'cursor'     => (int) $row['event_id'],
			'key'        => (string) (int) $row['event_id'],
			'source_id'  => (int) $post->ID,
			'title'      => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'content'    => (string) $post->post_content,
			'excerpt'    => (string) $post->post_excerpt,
			'status'     => $status,
			'author'     => (int) $post->post_author,
			'post_name'  => $is_first ? (string) $post->post_name : $post->post_name . '-' . $row['StartDate'],
			'start'      => $times['start'],
			'end'        => $times['end'],
			'all_day'    => $times['all_day'],
			'location'   => npmp_eo_venue( $post->ID ),
			'url'        => '',
			'thumbnail'  => npmp_event_import_image( $post->ID ),
			'categories' => npmp_event_import_post_terms( $post->ID, 'event-category' ),
			// Every date shares one link, so the first date holds it.
			'paths'      => ( $is_first && 'publish' === $post->post_status ) ? array_filter( array( npmp_event_import_old_path( $slug, $post->post_name ) ) ) : array(),
		);
	}
	// phpcs:enable
	return $out;
}

/**
 * Counts for the screen and the Verify step.
 *
 * @return array{events:int,dates:int,categories:int,venues:int,skipped:int}
 */
function npmp_eo_counts() {
	global $wpdb;
	$table = npmp_event_import_table( 'eo_events' );
	if ( ! $table ) {
		return array( 'events' => 0, 'dates' => 0, 'categories' => 0, 'venues' => 0, 'skipped' => 0 );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts.
	$live    = "p.post_type = 'event' AND p.post_status IN ('publish','private','pending','draft','future')";
	$dates   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id WHERE {$live}" );
	$events  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.post_id) FROM {$table} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id WHERE {$live}" );
	$skipped = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.post_id) FROM {$table} e LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id WHERE p.ID IS NULL OR NOT ( {$live} )" );
	$cats    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'event-category'" );
	$venues  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'event-venue'" );
	// phpcs:enable
	return array(
		'events'     => $events,
		'dates'      => $dates,
		'categories' => $cats,
		'venues'     => $venues,
		'skipped'    => $skipped,
	);
}

/**
 * Anything of Event Organiser's left? Events Manager also uses the `event`
 * post type, so only EO's own table or schedule meta counts.
 *
 * @return bool
 */
function npmp_eo_has_data() {
	global $wpdb;
	if ( '' !== npmp_event_import_table( 'eo_events' ) ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_eventorganiser_schedule_start_start' LIMIT 1" );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_event_import_sources', 'npmp_eo_register_event_source' );
add_filter( 'npmp_import_sources', 'npmp_eo_register_hub_source' );

/**
 * Add Event Organiser to the event importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_eo_register_event_source( $sources ) {
	$sources['event-organiser'] = array(
		'name'     => 'Event Organiser',
		'has_data' => 'npmp_eo_has_data',
		'counts'   => 'npmp_eo_counts',
		'batch'    => 'npmp_eo_batch',
		'brings'   => array(
			__( 'Dates you took out of a repeating event stay out.', 'nonprofit-manager' ),
		),
		'leaves'   => array(
			__( 'Event tags, since events here have categories only.', 'nonprofit-manager' ),
			__( 'Category colors and venue maps.', 'nonprofit-manager' ),
			__( 'Settings with no match here: showing past events, the date format, and deleting expired events. The calendar view you picked lives in your shortcodes, so set the default view under Events, Settings.', 'nonprofit-manager' ),
		),
		'warning'  => __( 'If you take bookings with Event Organiser Pro, export those bookings before you delete Event Organiser. We can\'t copy bookings.', 'nonprofit-manager' ),
	);
	return $sources;
}

/**
 * Event Organiser and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_eo_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 'event-organiser' === $folder || ( 0 === strpos( $folder, 'event-organiser-' ) && 0 === stripos( $name, 'Event Organiser' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Step 4 warning: deleting Event Organiser deletes its dates and terms.
 *
 * @return string
 */
function npmp_eo_delete_note() {
	return __( 'Event Organiser has no setting to keep its data, so deleting Event Organiser also deletes every event date, venue, category, and tag it saved. You keep everything you imported here, but you can\'t import from Event Organiser again afterward.', 'nonprofit-manager' );
}

/**
 * Add Event Organiser to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_eo_register_hub_source( $sources ) {
	$sources['event-organiser'] = npmp_event_hub_source(
		'event-organiser',
		'Event Organiser',
		array( 'event-organiser' ),
		'npmp_eo_installed_plugins',
		array(
			__( 'Open three events under Events and check the dates, times, and venues.', 'nonprofit-manager' ),
			__( 'Check a repeating event. You get one event here for each of its dates.', 'nonprofit-manager' ),
			__( 'Turn Event Organiser off, then visit a link to an old event page. You should land on the new event.', 'nonprofit-manager' ),
		),
		__( 'In step 4 you delete Event Organiser along with its dates, venues, and categories. We leave its event posts in place, since you may keep Event Organiser Pro bookings on those posts.', 'nonprofit-manager' ),
		array(
			'delete_note'         => 'npmp_eo_delete_note',
			'delete_note_setting' => false,
		)
	);
	return $sources;
}
