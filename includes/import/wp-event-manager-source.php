<?php
/**
 * File path: includes/import/wp-event-manager-source.php
 *
 * WP Event Manager as an event source (event-importer.php) and a Switch
 * Plugins source. Read from WP Event Manager 3.4.2's own source:
 *
 * - Each event is an event_listing post. _event_start_date and
 *   _event_end_date hold the date and time together (Y-m-d H:i:s), with the
 *   time again in _event_start_time and _event_end_time.
 * - Times are wall-clock in the site's time zone, unless the time zone
 *   setting (event_manager_timezone_setting) is 'each_event', when
 *   _event_timezone holds the event's zone (a name or "UTC+5"). There is no
 *   all-day flag and no repeating events in the free plugin.
 * - Statuses: publish, expired (past, still public), preview (an unfinished
 *   submission), pending, pending_payment, draft. _cancelled 1 is cancelled.
 * - Place: _event_venue_ids (event_venue post IDs), _event_location (the
 *   address line), _event_pincode, _event_country. An online event
 *   (_event_online 'yes') has none of them and no link of its own.
 * - Image: _thumbnail_id, else _event_banner (a URL or a list of URLs).
 * - Categories: event_listing_category (with parents). event_listing_type,
 *   organizers (_event_organizer_ids), _registration, and ticket fields have
 *   no home here.
 * - Old links are /{event_base}/{post_name}/ (option wpem_permalinks,
 *   default "event").
 *
 * Only event_manager_per_page maps to a setting here (list length).
 *
 * WP Event Manager's uninstall deletes its events, venues, organizers, and
 * terms only when "Delete Data On Uninstall" is on, so step 4 warns then.
 * Deactivating deletes nothing. Step 5 stays unavailable.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-event-sources.php
// =====================================================================

/**
 * Post status for an imported WP Event Manager event.
 *
 * @param string $post_status Post status.
 * @param mixed  $cancelled   _cancelled.
 * @return string publish, private, pending, draft, future, or '' to skip.
 */
function npmp_wpem_status( $post_status, $cancelled ) {
	$post_status = (string) $post_status;
	if ( in_array( $post_status, array( 'trash', 'auto-draft', 'inherit', '' ), true ) ) {
		return '';
	}
	if ( (int) $cancelled ) {
		// A cancelled event comes over as a draft, so nobody signs up for it.
		return 'draft';
	}
	switch ( $post_status ) {
		case 'publish':
		case 'expired':
			// An expired event is a past event that still has a public page.
			return 'publish';
		case 'pending':
		case 'pending_payment':
			return 'pending';
		case 'private':
		case 'future':
			return $post_status;
		default:
			return 'draft';
	}
}

/**
 * One start or end in "Y-m-d H:i:s". Older events keep the time only in the
 * separate time field.
 *
 * @param string $date     _event_start_date or _event_end_date.
 * @param string $time     _event_start_time or _event_end_time.
 * @param string $fallback Time to use when there is none.
 * @return string
 */
function npmp_wpem_datetime( $date, $time, $fallback ) {
	$date = trim( (string) $date );
	if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}(?::\d{2})?))?/', $date, $m ) ) {
		return '';
	}
	$clock = $m[2] ?? '';
	if ( '' === $clock ) {
		$clock = preg_match( '/^\d{1,2}:\d{2}(?::\d{2})?$/', trim( (string) $time ) ) ? trim( (string) $time ) : $fallback;
	}
	$parts = array_map( 'intval', explode( ':', $clock ) );
	return sprintf( '%s %02d:%02d:%02d', $m[1], $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0 );
}

/**
 * The events slug from wpem_permalinks.
 *
 * @param mixed $permalinks Stored wpem_permalinks.
 * @return string
 */
function npmp_wpem_slug( $permalinks ) {
	$slug = is_array( $permalinks ) && isset( $permalinks['event_base'] ) && is_string( $permalinks['event_base'] ) ? trim( $permalinks['event_base'], '/' ) : '';
	return '' !== $slug ? $slug : 'event';
}

/**
 * WP Event Manager settings with a match here, in npmp_tec_read_settings()'s shape.
 *
 * @param mixed $per_page event_manager_per_page.
 * @return array
 */
function npmp_wpem_read_settings( $per_page ) {
	$value = npmp_tec_convert_setting( 'list_limit', $per_page );
	return null === $value ? array() : array( 'list_limit' => array( 'tec' => trim( (string) $per_page ), 'value' => $value ) );
}

// =====================================================================
// Database
// =====================================================================

/**
 * The location line for an event.
 *
 * @param int $post_id Event post.
 * @return string
 */
function npmp_wpem_location( $post_id ) {
	if ( 'yes' === strtolower( (string) get_post_meta( $post_id, '_event_online', true ) ) ) {
		return '';
	}
	$name = '';
	foreach ( (array) get_post_meta( $post_id, '_event_venue_ids', true ) as $venue_id ) {
		$venue = get_post( (int) $venue_id );
		if ( $venue && 'event_venue' === $venue->post_type && 'trash' !== $venue->post_status ) {
			$name = html_entity_decode( (string) $venue->post_title, ENT_QUOTES, 'UTF-8' );
			break;
		}
	}
	return npmp_tec_format_venue(
		$name,
		array(
			'address' => (string) get_post_meta( $post_id, '_event_location', true ),
			'zip'     => (string) get_post_meta( $post_id, '_event_pincode', true ),
			'country' => (string) get_post_meta( $post_id, '_event_country', true ),
		)
	);
}

/**
 * The next events.
 *
 * @param int $after Last post ID done.
 * @param int $limit How many.
 * @return array[]
 */
function npmp_wpem_batch( $after, $limit ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched read with the plugin off.
	$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event_listing' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $after, (int) $limit ) );
	$site_tz = wp_timezone();
	$each    = 'each_event' === get_option( 'event_manager_timezone_setting', 'site_timezone' );
	$slug    = npmp_wpem_slug( get_option( 'wpem_permalinks', array() ) );
	$out     = array();
	foreach ( (array) $ids as $id ) {
		$post   = get_post( (int) $id );
		$status = $post ? npmp_wpem_status( $post->post_status, get_post_meta( $post->ID, '_cancelled', true ) ) : '';
		$start  = $post ? npmp_wpem_datetime( get_post_meta( $post->ID, '_event_start_date', true ), get_post_meta( $post->ID, '_event_start_time', true ), '00:00:00' ) : '';
		if ( '' === $status ) {
			$out[] = array( 'cursor' => (int) $id, 'skip' => true );
			continue;
		}
		$end   = npmp_wpem_datetime( get_post_meta( $post->ID, '_event_end_date', true ), get_post_meta( $post->ID, '_event_end_time', true ), '23:59:00' );
		$times = npmp_tec_event_times(
			array(
				'start'    => $start,
				'end'      => '' !== $end ? $end : $start,
				'timezone' => $each ? (string) get_post_meta( $post->ID, '_event_timezone', true ) : '',
				'all_day'  => '',
			),
			$site_tz
		);
		$public = in_array( $post->post_status, array( 'publish', 'expired' ), true );
		$out[]  = array(
			'cursor'     => (int) $post->ID,
			'key'        => (string) (int) $post->ID,
			'source_id'  => (int) $post->ID,
			'title'      => html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'content'    => (string) $post->post_content,
			'excerpt'    => (string) $post->post_excerpt,
			'status'     => $status,
			'author'     => (int) $post->post_author,
			'post_name'  => (string) $post->post_name,
			'start'      => $times['start'],
			'end'        => $times['end'],
			'all_day'    => false,
			'location'   => npmp_wpem_location( $post->ID ),
			'url'        => '',
			'thumbnail'  => npmp_event_import_image( $post->ID, get_post_meta( $post->ID, '_event_banner', true ) ),
			'categories' => npmp_event_import_post_terms( $post->ID, 'event_listing_category' ),
			'paths'      => $public ? array_filter( array( npmp_event_import_old_path( $slug, $post->post_name ) ) ) : array(),
		);
	}
	return $out;
}

/**
 * Counts for the screen and the Verify step.
 *
 * @return array{events:int,dates:int,categories:int,venues:int,skipped:int}
 */
function npmp_wpem_counts() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	$live    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event_listing' AND post_status NOT IN ('trash','auto-draft','inherit')" );
	$skipped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event_listing' AND post_status = 'trash'" );
	$venues  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event_venue' AND post_status NOT IN ('trash','auto-draft','inherit')" );
	$cats    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'event_listing_category'" );
	// phpcs:enable
	return array(
		'events'     => $live,
		'dates'      => $live,
		'categories' => $cats,
		'venues'     => $venues,
		'skipped'    => $skipped,
	);
}

/**
 * Anything of WP Event Manager's left?
 *
 * @return bool
 */
function npmp_wpem_has_data() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	return (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->posts} WHERE post_type IN ('event_listing','event_venue','event_organizer') LIMIT 1" );
}

/**
 * Settings for the import screen.
 *
 * @return array
 */
function npmp_wpem_settings() {
	return npmp_wpem_read_settings( get_option( 'event_manager_per_page', null ) );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_event_import_sources', 'npmp_wpem_register_event_source' );
add_filter( 'npmp_import_sources', 'npmp_wpem_register_hub_source' );

/**
 * Add WP Event Manager to the event importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_wpem_register_event_source( $sources ) {
	$sources['wp-event-manager'] = array(
		'name'     => 'WP Event Manager',
		'has_data' => 'npmp_wpem_has_data',
		'counts'   => 'npmp_wpem_counts',
		'batch'    => 'npmp_wpem_batch',
		'settings' => 'npmp_wpem_settings',
		'series'   => false,
		'brings'   => array(
			__( 'You get expired events as past events.', 'nonprofit-manager' ),
		),
		'leaves'   => array(
			__( 'Organizers, event types, ticket prices, and registration links and emails.', 'nonprofit-manager' ),
			__( 'Health guidelines and event videos. You get online events with no location line, since WP Event Manager has no link field for an online event.', 'nonprofit-manager' ),
			__( 'Settings with no match here: the list or box layout, date formats, and hiding expired or cancelled events.', 'nonprofit-manager' ),
		),
		'warning'  => __( 'If you sell tickets or take registrations with WP Event Manager\'s paid add-ons or WooCommerce, export those orders and attendees before you delete WP Event Manager\'s data. We can\'t copy orders or attendees.', 'nonprofit-manager' ),
	);
	return $sources;
}

/**
 * WP Event Manager and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_wpem_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		$addon  = ( 0 === strpos( $folder, 'wp-event-manager-' ) || 0 === strpos( $folder, 'wpem-' ) ) && false !== stripos( $name, 'Event Manager' );
		if ( 'wp-event-manager' === $folder || $addon ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Step 4 warning while WP Event Manager would delete its data on delete.
 *
 * @return string
 */
function npmp_wpem_delete_note() {
	if ( ! get_option( 'event_manager_delete_data_on_uninstall' ) ) {
		return '';
	}
	return __( 'WP Event Manager\'s Delete Data On Uninstall setting is on, so deleting WP Event Manager also deletes its events, venues, organizers, and categories. To keep them, clear that box on the General tab of WP Event Manager\'s Settings screen first.', 'nonprofit-manager' );
}

/**
 * Add WP Event Manager to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_wpem_register_hub_source( $sources ) {
	$sources['wp-event-manager'] = npmp_event_hub_source(
		'wp-event-manager',
		'WP Event Manager',
		array( 'wp-event-manager' ),
		'npmp_wpem_installed_plugins',
		array(
			__( 'Open three events under Events and check the dates, times, and locations.', 'nonprofit-manager' ),
			__( 'Turn WP Event Manager off, then visit a link to an old event page. You should land on the new event.', 'nonprofit-manager' ),
			__( 'Export your registrations and ticket orders first if you need the attendee lists.', 'nonprofit-manager' ),
		),
		__( 'WP Event Manager has its own Delete Data On Uninstall setting, and you can delete its events with that setting when you delete the plugin. Its paid add-ons keep registrations and tickets tied to those events, so we leave that choice with WP Event Manager.', 'nonprofit-manager' ),
		array( 'delete_note' => 'npmp_wpem_delete_note' )
	);
	return $sources;
}
