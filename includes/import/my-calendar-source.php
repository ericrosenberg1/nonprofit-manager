<?php
/**
 * File path: includes/import/my-calendar-source.php
 *
 * My Calendar as an event source (event-importer.php) and a Switch Plugins
 * source. Read from My Calendar 3.8.13's own source:
 *
 * - Events are rows in {prefix}my_calendar, not posts. Every date, a single
 *   event's too, is a row in {prefix}my_calendar_events (occur_id,
 *   occur_event_id, occur_begin, occur_end). Deleting one date of a series
 *   deletes its row.
 * - occur_begin and occur_end are site-local wall time. There is no per-event
 *   time zone. All day is event_time 00:00:00 with event_endtime 23:59:59.
 * - event_approved: 0 draft, 1 published, 2 trash, 3 cancelled, 4 private,
 *   5 personal, 6 scheduled. event_flagged 1 is spam.
 * - event_desc is the description (HTML), event_short the excerpt, event_link
 *   the event's own link. event_image is an image URL, and the event's
 *   mc-events post (event_post) carries the featured image.
 * - event_location points at my_calendar_locations.location_id. Older events
 *   keep the address in event_label, event_street, and the rest.
 * - Categories: my_calendar_category_relationships to my_calendar_categories,
 *   mirrored on the mc-events post as the mc-event-category taxonomy.
 * - Old links are the mc-events post, /{cpt_base}/{post_name}/ (option
 *   my_calendar_options, default "mc-events"). A date of a series adds
 *   ?mc_id={occur_id}.
 *
 * Never read: any key of my_calendar_options but cpt_base and drop_tables
 * (it also holds API keys for maps).
 *
 * My Calendar's uninstall deletes its events, posts, and tables only when
 * "Drop database tables on uninstall" (drop_tables) is on, so step 4 warns
 * then. Deactivating deletes nothing. Step 5 stays unavailable.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-event-sources.php
// =====================================================================

/**
 * Post status for an imported My Calendar date.
 *
 * @param mixed $approved event_approved.
 * @param mixed $flagged  event_flagged.
 * @return string publish, private, draft, or '' to skip.
 */
function npmp_mc_status( $approved, $flagged ) {
	if ( (int) $flagged ) {
		return '';
	}
	switch ( (int) $approved ) {
		case 1:
			return 'publish';
		case 2:
			return '';
		case 4:
		case 5:
			return 'private';
		default:
			// Draft, scheduled, and cancelled (a draft, so nobody signs up for it).
			return 'draft';
	}
}

/**
 * Is a My Calendar event all day?
 *
 * @param string $time    event_time.
 * @param string $endtime event_endtime.
 * @return bool
 */
function npmp_mc_all_day( $time, $endtime ) {
	return '00:00:00' === substr( (string) $time, 0, 8 ) && '23:59:59' === substr( (string) $endtime, 0, 8 );
}

/**
 * The mc-events slug from my_calendar_options.
 *
 * @param mixed $options Stored my_calendar_options.
 * @return string
 */
function npmp_mc_slug( $options ) {
	$slug = is_array( $options ) && isset( $options['cpt_base'] ) && is_string( $options['cpt_base'] ) ? trim( $options['cpt_base'], '/' ) : '';
	return '' !== $slug ? $slug : 'mc-events';
}

/**
 * Does My Calendar delete its data when you delete the plugin?
 *
 * @param mixed $options Stored my_calendar_options.
 * @return bool
 */
function npmp_mc_drops_tables( $options ) {
	return is_array( $options ) && 'true' === ( $options['drop_tables'] ?? '' );
}

// =====================================================================
// Database
// =====================================================================

/**
 * The location line for an event row.
 *
 * @param array $event my_calendar row.
 * @return string
 */
function npmp_mc_location( array $event ) {
	global $wpdb;
	$prefix = 'event';
	$row    = $event;
	$table  = npmp_event_import_table( 'my_calendar_locations' );
	if ( $table && (int) $event['event_location'] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
		$loc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE location_id = %d", (int) $event['event_location'] ), ARRAY_A );
		if ( $loc ) {
			$prefix = 'location';
			$row    = $loc;
		}
	}
	$address = trim( ( $row[ $prefix . '_street' ] ?? '' ) . ' ' . ( $row[ $prefix . '_street2' ] ?? '' ) );
	return npmp_tec_format_venue(
		html_entity_decode( (string) ( $row[ $prefix . '_label' ] ?? '' ), ENT_QUOTES, 'UTF-8' ),
		array(
			'address' => $address,
			'city'    => $row[ $prefix . '_city' ] ?? '',
			'state'   => $row[ $prefix . '_state' ] ?? '',
			'zip'     => $row[ $prefix . '_postcode' ] ?? '',
			'country' => $row[ $prefix . '_country' ] ?? '',
		)
	);
}

/**
 * Categories of an event: the mc-event-category terms on its post (with any
 * parents), else the names in My Calendar's own category table.
 *
 * @param array $event my_calendar row.
 * @return array[]
 */
function npmp_mc_categories( array $event ) {
	global $wpdb;
	$terms = (int) $event['event_post'] ? npmp_event_import_post_terms( (int) $event['event_post'], 'mc-event-category' ) : array();
	if ( $terms ) {
		return $terms;
	}
	$cats = npmp_event_import_table( 'my_calendar_categories' );
	$rels = npmp_event_import_table( 'my_calendar_category_relationships' );
	if ( ! $cats || ! $rels ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT c.category_name FROM {$rels} r INNER JOIN {$cats} c ON c.category_id = r.category_id WHERE r.event_id = %d ORDER BY c.category_id ASC", (int) $event['event_id'] ) );
	$out   = array();
	foreach ( (array) $names as $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		if ( '' !== trim( $name ) ) {
			$out[] = array( 'slug' => sanitize_title( $name ), 'name' => $name, 'parent' => '', 'description' => '', 'assign' => true );
		}
	}
	return $out;
}

/**
 * The next dates, one item per my_calendar_events row.
 *
 * @param int $after Last occur_id done.
 * @param int $limit How many.
 * @return array[]
 */
function npmp_mc_batch( $after, $limit ) {
	global $wpdb;
	$occurs = npmp_event_import_table( 'my_calendar_events' );
	$events = npmp_event_import_table( 'my_calendar' );
	if ( ! $occurs || ! $events ) {
		return array();
	}
	$site_tz = wp_timezone();
	$slug    = npmp_mc_slug( get_option( 'my_calendar_options', array() ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT o.occur_id, o.occur_begin, o.occur_end, e.* FROM {$occurs} o LEFT JOIN {$events} e ON e.event_id = o.occur_event_id WHERE o.occur_id > %d ORDER BY o.occur_id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$out   = array();
	$first = array();
	foreach ( (array) $rows as $row ) {
		$status = null === $row['event_id'] ? '' : npmp_mc_status( $row['event_approved'], $row['event_flagged'] );
		if ( '' === $status ) {
			$out[] = array( 'cursor' => (int) $row['occur_id'], 'skip' => true );
			continue;
		}
		$event_id = (int) $row['event_id'];
		if ( ! isset( $first[ $event_id ] ) ) {
			$first[ $event_id ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(occur_id) FROM {$occurs} WHERE occur_event_id = %d", $event_id ) );
		}
		$is_first = (int) $row['occur_id'] === $first[ $event_id ];
		$all_day  = npmp_mc_all_day( $row['event_time'], $row['event_endtime'] );
		$times    = npmp_tec_event_times(
			array(
				'start'    => (string) $row['occur_begin'],
				'end'      => (string) $row['occur_end'],
				'timezone' => '',
				'all_day'  => $all_day ? 'yes' : '',
			),
			$site_tz
		);
		$post  = (int) $row['event_post'] ? get_post( (int) $row['event_post'] ) : null;
		$post  = ( $post && 'mc-events' === $post->post_type ) ? $post : null;
		$paths = array();
		if ( $post && 'publish' === $post->post_status && 1 === (int) $row['event_approved'] ) {
			$base = npmp_event_import_old_path( $slug, $post->post_name );
			if ( $is_first ) {
				$paths[] = $base;
			}
			// A date's own link (?mc_id=) lands on that date.
			$paths[] = npmp_event_import_path_with_query( $base, 'mc_id', (string) (int) $row['occur_id'] );
		}
		$name  = $post ? (string) $post->post_name : sanitize_title( (string) $row['event_title'] );
		$out[] = array(
			'cursor'     => (int) $row['occur_id'],
			'key'        => (string) (int) $row['occur_id'],
			'source_id'  => $event_id,
			'title'      => html_entity_decode( (string) $row['event_title'], ENT_QUOTES, 'UTF-8' ),
			'content'    => (string) $row['event_desc'],
			'excerpt'    => (string) $row['event_short'],
			'status'     => $status,
			'author'     => (int) $row['event_author'],
			'post_name'  => $is_first ? $name : $name . '-' . substr( (string) $row['occur_begin'], 0, 10 ),
			'start'      => $times['start'],
			'end'        => $times['end'],
			'all_day'    => $times['all_day'],
			'location'   => npmp_mc_location( $row ),
			'url'        => (string) $row['event_link'],
			'thumbnail'  => npmp_event_import_image( $post ? $post->ID : 0, (string) $row['event_image'] ),
			'categories' => npmp_mc_categories( $row ),
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
function npmp_mc_counts() {
	global $wpdb;
	$events = npmp_event_import_table( 'my_calendar' );
	$occurs = npmp_event_import_table( 'my_calendar_events' );
	if ( ! $events || ! $occurs ) {
		return array( 'events' => 0, 'dates' => 0, 'categories' => 0, 'venues' => 0, 'skipped' => 0 );
	}
	$cats = npmp_event_import_table( 'my_calendar_categories' );
	$locs = npmp_event_import_table( 'my_calendar_locations' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts.
	$live  = 'e.event_approved <> 2 AND COALESCE(e.event_flagged, 0) = 0';
	$count = array(
		'events'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e WHERE {$live}" ),
		'dates'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$occurs} o INNER JOIN {$events} e ON e.event_id = o.occur_event_id WHERE {$live}" ),
		'categories' => $cats ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cats}" ) : 0,
		'venues'     => $locs ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$locs}" ) : 0,
		'skipped'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e WHERE NOT ( {$live} )" ),
	);
	// phpcs:enable
	return $count;
}

/**
 * Anything of My Calendar's left?
 *
 * @return bool
 */
function npmp_mc_has_data() {
	return '' !== npmp_event_import_table( 'my_calendar' );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_event_import_sources', 'npmp_mc_register_event_source' );
add_filter( 'npmp_import_sources', 'npmp_mc_register_hub_source' );

/**
 * Add My Calendar to the event importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_mc_register_event_source( $sources ) {
	$sources['my-calendar'] = array(
		'name'       => 'My Calendar',
		'has_data'   => 'npmp_mc_has_data',
		'counts'     => 'npmp_mc_counts',
		'batch'      => 'npmp_mc_batch',
		'query_args' => array( 'mc_id' ),
		'brings'     => array(
			__( 'Each event\'s own link. An old link to one date of a repeating event leads to that date.', 'nonprofit-manager' ),
		),
		'leaves'     => array(
			__( 'Event hosts, ticket links, and registration details.', 'nonprofit-manager' ),
			__( 'Category colors and icons, and each location\'s phone numbers and map.', 'nonprofit-manager' ),
			__( 'Settings with no match here: the calendar views you turned on, how many months a list shows, and your style colors. Set the default view under Events, Settings.', 'nonprofit-manager' ),
		),
		'warning'    => __( 'If you sell tickets for these events with My Tickets or My Calendar Pro, export those orders before you delete My Calendar\'s data. We can\'t copy ticket orders.', 'nonprofit-manager' ),
	);
	return $sources;
}

/**
 * My Calendar and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_mc_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 'my-calendar' === $folder || ( 0 === strpos( $folder, 'my-calendar-' ) && 0 === stripos( $name, 'My Calendar' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Step 4 warning while My Calendar would drop its tables on delete.
 *
 * @return string
 */
function npmp_mc_delete_note() {
	if ( ! npmp_mc_drops_tables( get_option( 'my_calendar_options', array() ) ) ) {
		return '';
	}
	return __( 'My Calendar\'s "Drop database tables on uninstall" setting is on, so deleting My Calendar also deletes its events, locations, categories, and tables. To keep them, clear that box on the General tab of My Calendar\'s Settings screen first.', 'nonprofit-manager' );
}

/**
 * Add My Calendar to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_mc_register_hub_source( $sources ) {
	$sources['my-calendar'] = npmp_event_hub_source(
		'my-calendar',
		'My Calendar',
		array( 'my-calendar' ),
		'npmp_mc_installed_plugins',
		array(
			__( 'Open three events under Events and check the dates, times, and locations.', 'nonprofit-manager' ),
			__( 'Check a repeating event. You get one event here for each of its dates.', 'nonprofit-manager' ),
			__( 'Turn My Calendar off, then visit a link to an old event page. You should land on the new event.', 'nonprofit-manager' ),
		),
		__( 'My Calendar has its own "Drop database tables on uninstall" setting, and you can delete its events and tables with that setting when you delete the plugin. Ticket plugins can keep orders tied to those events, so we leave that choice with My Calendar.', 'nonprofit-manager' ),
		array( 'delete_note' => 'npmp_mc_delete_note' )
	);
	return $sources;
}
