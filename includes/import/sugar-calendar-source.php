<?php
/**
 * File path: includes/import/sugar-calendar-source.php
 *
 * Sugar Calendar as an event source (event-importer.php) and a Switch Plugins
 * source. Read from Sugar Calendar Lite 3.17.0's own source:
 *
 * - Each event is an sc_event post plus a row in {prefix}sc_events
 *   (object_id = post ID, object_subtype = 'sc_event'). The row's start, end,
 *   and all_day are what the calendar shows.
 * - start and end are wall-clock times in start_tz and end_tz. An empty zone
 *   ("floating", and every all-day event) means the site's timezone.
 * - {prefix}sc_eventmeta is keyed by sc_events.id: 'location' is the plain
 *   location line and 'url' the event link.
 * - Calendars are the sc_event_category taxonomy. Old links are
 *   /{SC_EVENTS_SLUG}/{post_name}/ (default "events").
 * - Lite has no venues and no recurrence editor. A row that Pro made
 *   repeating comes over once, on its first date.
 *
 * Never read: the sugar_calendar option (it holds the licence and the Google
 * Maps key), any sc_stripe_* option, sugar_calendar_connect_token, the
 * sc_oauth_connections table, or the meeting_password meta.
 *
 * Data removal stays unavailable: Sugar Calendar's tables also hold ticket
 * orders and attendees.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full table name when it exists.
 *
 * @param string $short Name without the prefix.
 * @return string
 */
function npmp_sc_table( $short ) {
	global $wpdb;
	$name = $wpdb->prefix . $short;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ? $name : '';
}

/**
 * The events slug Sugar Calendar used for links.
 *
 * @return string
 */
function npmp_sc_slug() {
	return defined( 'SC_EVENTS_SLUG' ) && '' !== (string) SC_EVENTS_SLUG ? (string) SC_EVENTS_SLUG : 'events';
}

/**
 * Calendars on a post, read from the term tables so it works with Sugar off.
 *
 * @param int $post_id Post ID.
 * @return array[]
 */
function npmp_sc_categories( $post_id ) {
	return npmp_event_import_post_terms( $post_id, 'sc_event_category' );
}

/**
 * The next events.
 *
 * @param int $after Last sc_events.id done.
 * @param int $limit How many.
 * @return array[]
 */
function npmp_sc_batch( $after, $limit ) {
	global $wpdb;
	$events = npmp_sc_table( 'sc_events' );
	if ( ! $events ) {
		return array();
	}
	$meta    = npmp_sc_table( 'sc_eventmeta' );
	$site_tz = wp_timezone();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$post = 'sc_event' === (string) $row['object_subtype'] ? get_post( (int) $row['object_id'] ) : null;
		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			$out[] = array( 'cursor' => (int) $row['id'], 'skip' => true );
			continue;
		}
		$values = array();
		if ( $meta ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta} WHERE sc_event_id = %d AND meta_key IN ('location','url')", (int) $row['id'] ), ARRAY_A ) as $m ) {
				$values[ $m['meta_key'] ] = (string) $m['meta_value'];
			}
		}
		$times = npmp_tec_event_times(
			array(
				'start'    => (string) $row['start'],
				'end'      => (string) $row['end'],
				'timezone' => (int) $row['all_day'] ? '' : (string) $row['start_tz'],
				'all_day'  => (int) $row['all_day'] ? 'yes' : '',
			),
			$site_tz
		);
		$out[] = array(
			'cursor'     => (int) $row['id'],
			'key'        => (string) (int) $row['id'],
			'source_id'  => (int) $post->ID,
			'title'      => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'content'    => '' !== (string) $post->post_content ? (string) $post->post_content : (string) $row['content'],
			'excerpt'    => (string) $post->post_excerpt,
			'status'     => (string) $post->post_status,
			'author'     => (int) $post->post_author,
			'post_name'  => (string) $post->post_name,
			'start'      => $times['start'],
			'end'        => $times['end'],
			'all_day'    => $times['all_day'],
			'location'   => (string) ( $values['location'] ?? '' ),
			'url'        => (string) ( $values['url'] ?? '' ),
			'thumbnail'  => (int) get_post_meta( $post->ID, '_thumbnail_id', true ),
			'categories' => npmp_sc_categories( $post->ID ),
			'paths'      => 'publish' === $post->post_status ? array( npmp_event_import_old_path( npmp_sc_slug(), $post->post_name ) ) : array(),
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
function npmp_sc_counts() {
	global $wpdb;
	$events = npmp_sc_table( 'sc_events' );
	if ( ! $events ) {
		return array( 'events' => 0, 'dates' => 0, 'categories' => 0, 'venues' => 0, 'skipped' => 0 );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts.
	$live    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e INNER JOIN {$wpdb->posts} p ON p.ID = e.object_id WHERE e.object_subtype = 'sc_event' AND p.post_status NOT IN ('trash','auto-draft','inherit')" );
	$all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} WHERE object_subtype = 'sc_event'" );
	$cats    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'sc_event_category'" );
	// phpcs:enable
	return array(
		'events'     => $live,
		'dates'      => $live,
		'categories' => $cats,
		'venues'     => 0,
		'skipped'    => max( 0, $all - $live ),
	);
}

/**
 * Anything of Sugar Calendar's left?
 *
 * @return bool
 */
function npmp_sc_has_data() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'sc_event'" );
	return $posts > 0 || '' !== npmp_sc_table( 'sc_events' );
}

add_filter( 'npmp_event_import_sources', 'npmp_sc_register_event_source' );
add_filter( 'npmp_import_sources', 'npmp_sc_register_hub_source' );

/**
 * Add Sugar Calendar to the event importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_sc_register_event_source( $sources ) {
	$sources['sugar-calendar'] = array(
		'name'     => 'Sugar Calendar',
		'has_data' => 'npmp_sc_has_data',
		'counts'   => 'npmp_sc_counts',
		'batch'    => 'npmp_sc_batch',
		'series'   => false,
		'venues'   => false,
	);
	return $sources;
}

/**
 * Sugar Calendar (Lite or Pro) and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_sc_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 0 === strpos( $folder, 'sugar-calendar' ) && 0 === stripos( $name, 'Sugar Calendar' ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Add Sugar Calendar to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_sc_register_hub_source( $sources ) {
	$sources['sugar-calendar'] = npmp_event_hub_source(
		'sugar-calendar',
		'Sugar Calendar',
		array( 'sugar-calendar-lite', 'sugar-calendar' ),
		'npmp_sc_installed_plugins',
		array(
			__( 'Open three events under Events and check the dates, times, and locations.', 'nonprofit-manager' ),
			__( 'Turn Sugar Calendar off, then visit a link to an old event page. You should land on the new event.', 'nonprofit-manager' ),
			__( 'Check any repeating event. You get its first date here, so add the other dates yourself.', 'nonprofit-manager' ),
		),
		__( 'Sugar Calendar keeps its ticket orders and attendees in the same tables as its events, so we leave Sugar Calendar\'s data in your database, and you can leave the data in place.', 'nonprofit-manager' )
	);
	return $sources;
}
