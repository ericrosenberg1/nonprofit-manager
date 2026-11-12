<?php
/**
 * File path: includes/import/events-manager-source.php
 *
 * Events Manager as an event source (event-importer.php) and a Switch Plugins
 * source. Read from Events Manager 7.4.14's own source:
 *
 * - {prefix}em_events holds every event. event_type 'single' is one event,
 *   'recurrence' is one date of a series, and 'recurring', 'repeating', and
 *   'timeslot' rows are templates or slots, so those stay behind.
 * - event_start_date/time and event_end_date/time are local to
 *   event_timezone (falling back to dbem_timezone_default). event_start and
 *   event_end are the UTC copies EM works out from them.
 * - A series date with no post of its own (a "recurring" series) borrows its
 *   parent's post for content, image, and categories: recurrence_set_id ->
 *   em_event_recurrences.event_id -> em_events.post_id.
 * - location_id points at em_locations.location_id. An online event has
 *   event_location_type 'url' and its link in post meta _event_location_url.
 * - Categories are the event-categories taxonomy. Old links are
 *   /{dbem_cp_events_slug}/{post_name}/ (default slug "events").
 *
 * Never read: dbem_google_maps_browser_key, dbem_smtp_*, dbem_pro_api_key,
 * dbem_oauth, or any booking table.
 *
 * Data removal stays with Events Manager: its own Uninstall button under
 * Events, Settings deletes its events, locations, terms, and tables.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-event-sources.php
// =====================================================================

/**
 * Post status for an imported event.
 *
 * @param string $post_status   Status of the event's post, or '' with no post.
 * @param mixed  $event_status  em_events.event_status: 1, 0, -1, or null.
 * @param int    $private       em_events.event_private.
 * @param int    $active_status em_events.event_active_status, 0 for cancelled.
 * @param bool   $status_on     dbem_event_status_enabled.
 * @return string publish, private, pending, draft, or '' to skip.
 */
function npmp_em_status( $post_status, $event_status, $private, $active_status, $status_on ) {
	if ( 'trash' === $post_status || ( '' === $post_status && null !== $event_status && -1 === (int) $event_status ) ) {
		return '';
	}
	if ( $status_on && 0 === (int) $active_status && null !== $active_status ) {
		// A cancelled event comes over as a draft, so nobody signs up for it.
		return 'draft';
	}
	if ( in_array( $post_status, array( 'publish', 'private', 'pending', 'draft', 'future' ), true ) ) {
		return $post_status;
	}
	if ( null === $event_status || '' === $event_status ) {
		return 'draft';
	}
	if ( 1 === (int) $event_status ) {
		return (int) $private ? 'private' : 'publish';
	}
	return 'pending';
}

// =====================================================================
// Database
// =====================================================================

/**
 * Full table name when it exists.
 *
 * @param string $short Name without the prefix.
 * @return string
 */
function npmp_em_table( $short ) {
	global $wpdb;
	$name = $wpdb->prefix . $short;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ? $name : '';
}

/**
 * Categories on a post, read from the term tables so it works with EM off.
 *
 * @param int $post_id Post ID.
 * @return array[]
 */
function npmp_em_categories( $post_id ) {
	return npmp_event_import_post_terms( $post_id, 'event-categories' );
}

/**
 * The next events, one item per date.
 *
 * @param int $after Last event_id done.
 * @param int $limit How many.
 * @return array[]
 */
function npmp_em_batch( $after, $limit ) {
	global $wpdb;
	$events = npmp_em_table( 'em_events' );
	if ( ! $events ) {
		return array();
	}
	$locations   = npmp_em_table( 'em_locations' );
	$recurrences = npmp_em_table( 'em_event_recurrences' );
	$site_tz     = wp_timezone();
	$default_tz  = (string) get_option( 'dbem_timezone_default', '' );
	$status_on   = (bool) get_option( 'dbem_event_status_enabled', false );
	$slug        = (string) get_option( 'dbem_cp_events_slug', 'events' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE event_id > %d ORDER BY event_id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$out  = array();
	$first_of_series = array();
	foreach ( (array) $rows as $row ) {
		$item = array( 'cursor' => (int) $row['event_id'], 'skip' => true );
		$type = (string) ( $row['event_type'] ?? 'single' );
		if ( ! in_array( $type, array( 'single', 'recurrence', '' ), true ) ) {
			$out[] = $item;
			continue;
		}

		$post_id = (int) $row['post_id'];
		$parent  = null;
		if ( 'recurrence' === $type && $recurrences && ! empty( $row['recurrence_set_id'] ) ) {
			$parent_event = (int) $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$recurrences} WHERE recurrence_set_id = %d LIMIT 1", (int) $row['recurrence_set_id'] ) );
			if ( $parent_event ) {
				$parent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE event_id = %d", $parent_event ), ARRAY_A );
			}
		}
		$post        = $post_id ? get_post( $post_id ) : null;
		$parent_post = ( $parent && ! empty( $parent['post_id'] ) ) ? get_post( (int) $parent['post_id'] ) : null;
		$content_of  = $post ? $post : $parent_post;

		$status = npmp_em_status( $post ? $post->post_status : '', $row['event_status'], (int) $row['event_private'], $row['event_active_status'], $status_on );
		if ( '' === $status ) {
			$out[] = $item;
			continue;
		}

		$tz    = '' !== (string) $row['event_timezone'] ? (string) $row['event_timezone'] : $default_tz;
		$times = npmp_tec_event_times(
			array(
				'start'     => trim( $row['event_start_date'] . ' ' . ( $row['event_start_time'] ?: '00:00:00' ) ),
				'end'       => trim( ( $row['event_end_date'] ?: $row['event_start_date'] ) . ' ' . ( $row['event_end_time'] ?: '23:59:59' ) ),
				'start_utc' => (string) ( $row['event_start'] ?? '' ),
				'end_utc'   => (string) ( $row['event_end'] ?? '' ),
				'timezone'  => $tz,
				'all_day'   => (int) $row['event_all_day'] ? 'yes' : '',
			),
			$site_tz
		);

		$location = '';
		if ( $locations && (int) $row['location_id'] ) {
			$loc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$locations} WHERE location_id = %d", (int) $row['location_id'] ), ARRAY_A );
			if ( $loc ) {
				$location = npmp_tec_format_venue(
					(string) $loc['location_name'],
					array(
						'address' => $loc['location_address'],
						'city'    => $loc['location_town'],
						'state'   => $loc['location_state'],
						'zip'     => $loc['location_postcode'],
						'country' => $loc['location_country'],
					)
				);
			}
		}
		$meta_from = $post ? $post->ID : ( $parent_post ? $parent_post->ID : 0 );
		$url       = $meta_from ? (string) get_post_meta( $meta_from, '_event_location_url', true ) : '';

		$paths = array();
		if ( $post && 'publish' === $post->post_status ) {
			$paths[] = npmp_event_import_old_path( $slug, $post->post_name );
		} elseif ( ! $post && $parent_post && 'publish' === $parent_post->post_status && ! isset( $first_of_series[ $parent_post->ID ] ) ) {
			// A series with no post per date: the series link lands on its first date.
			$first_of_series[ $parent_post->ID ] = true;
			$already = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE recurrence_set_id = %d AND event_id < %d AND event_type = 'recurrence'", (int) $row['recurrence_set_id'], (int) $row['event_id'] ) );
			if ( 0 === $already ) {
				$paths[] = npmp_event_import_old_path( $slug, $parent_post->post_name );
			}
		}

		$title = $post ? $post->post_title : (string) $row['event_name'];
		$out[] = array(
			'cursor'     => (int) $row['event_id'],
			'key'        => (string) (int) $row['event_id'],
			'source_id'  => (int) $row['event_id'],
			'title'      => html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ),
			'content'    => $content_of ? (string) $content_of->post_content : (string) $row['post_content'],
			'excerpt'    => $content_of ? (string) $content_of->post_excerpt : '',
			'status'     => $status,
			'author'     => $content_of ? (int) $content_of->post_author : (int) $row['event_owner'],
			'post_name'  => $post ? $post->post_name : ( $parent_post ? $parent_post->post_name . '-' . $row['event_start_date'] : '' ),
			'start'      => $times['start'],
			'end'        => $times['end'],
			'all_day'    => $times['all_day'],
			'location'   => $location,
			'url'        => $url,
			'thumbnail'  => $meta_from ? (int) get_post_meta( $meta_from, '_thumbnail_id', true ) : 0,
			'categories' => npmp_em_categories( $meta_from ),
			'paths'      => array_values( array_filter( $paths ) ),
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
function npmp_em_counts() {
	global $wpdb;
	$events = npmp_em_table( 'em_events' );
	if ( ! $events ) {
		return array( 'events' => 0, 'dates' => 0, 'categories' => 0, 'venues' => 0, 'skipped' => 0 );
	}
	$locations = npmp_em_table( 'em_locations' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts.
	$live    = "e.event_type IN ('single','recurrence','') AND COALESCE(p.post_status, '') <> 'trash' AND NOT ( p.ID IS NULL AND e.event_status = -1 )";
	$join    = "LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id";
	$dates   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e {$join} WHERE {$live}" );
	$singles = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e {$join} WHERE {$live} AND e.event_type IN ('single','')" );
	$series  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.recurrence_set_id) FROM {$events} e {$join} WHERE {$live} AND e.event_type = 'recurrence'" );
	$skipped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e {$join} WHERE e.event_type IN ('single','recurrence','') AND NOT ( {$live} )" );
	$cats    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'event-categories'" );
	$venues  = $locations ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$locations} WHERE COALESCE(location_status, 1) >= 0" ) : 0;
	// phpcs:enable
	return array(
		'events'     => $singles + $series,
		'dates'      => $dates,
		'categories' => $cats,
		'venues'     => $venues,
		'skipped'    => $skipped,
	);
}

/**
 * Anything of Events Manager's left?
 *
 * @return bool
 */
function npmp_em_has_data() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('event','event-recurring','location')" );
	return $posts > 0 || '' !== npmp_em_table( 'em_events' );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_event_import_sources', 'npmp_em_register_event_source' );
add_filter( 'npmp_import_sources', 'npmp_em_register_hub_source' );

/**
 * Add Events Manager to the event importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_em_register_event_source( $sources ) {
	$sources['events-manager'] = array(
		'name'     => 'Events Manager',
		'has_data' => 'npmp_em_has_data',
		'counts'   => 'npmp_em_counts',
		'batch'    => 'npmp_em_batch',
	);
	return $sources;
}

/**
 * Events Manager and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_em_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 'events-manager' === $folder || ( 0 === strpos( $folder, 'events-manager-' ) && 0 === stripos( $name, 'Events Manager' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Add Events Manager to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_em_register_hub_source( $sources ) {
	$sources['events-manager'] = npmp_event_hub_source(
		'events-manager',
		'Events Manager',
		array( 'events-manager' ),
		'npmp_em_installed_plugins',
		array(
			__( 'Open three events under Events and check the dates, times, and locations.', 'nonprofit-manager' ),
			__( 'Turn Events Manager off, then visit a link to an old event page. You should land on the new event.', 'nonprofit-manager' ),
			__( 'Export your bookings from Events Manager first if you need the attendee lists.', 'nonprofit-manager' ),
		),
		__( 'Events Manager has its own Uninstall button on its General settings tab, and you can delete its events, locations, and tables with that button. We leave that choice with Events Manager.', 'nonprofit-manager' )
	);
	return $sources;
}
