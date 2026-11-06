<?php
/**
 * File path: includes/import/tec-importer.php
 *
 * Importer for sites switching from The Events Calendar (TEC), plus 301s from
 * its old event URLs.
 *
 * Five screens: 1. Import reads every TEC event into a per-user staging list,
 * 2. Review shows them in a table with a checkbox each, 3. Approve confirms
 * what moves, 4. Migrate creates the npmp_event posts, and 5. Deactivate turns
 * TEC and its add-ons off. Reading and migrating run in batches of 100 over
 * admin-ajax so a site with thousands of events never times out.
 *
 * TEC's own data is never changed or deleted, so you can switch TEC back on.
 *
 * This file loads on every request. The front end only gets the 404 redirect
 * hook, and it returns at once unless an import has run and the request is a
 * 404 (or an old ?tribe_events= link once TEC is off).
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Events read or migrated per request. */
if ( ! defined( 'NPMP_TEC_BATCH' ) ) {
	define( 'NPMP_TEC_BATCH', 100 );
}

// =====================================================================
// Pure helpers (no database), covered by tests/test-tec-importer.php
// =====================================================================

/**
 * Turn a TEC timezone string into a DateTimeZone.
 *
 * TEC stores either an IANA name ("America/New_York") or a manual offset in
 * WordPress's style ("UTC+5", "UTC-3.5", "UTC+5:30", "UTC").
 *
 * @param string       $tz       Timezone as TEC stored it.
 * @param DateTimeZone $fallback Zone to use when the value is empty or unreadable.
 * @return DateTimeZone
 */
function npmp_tec_parse_timezone( $tz, DateTimeZone $fallback ) {
	$tz = trim( (string) $tz );
	if ( '' === $tz ) {
		return $fallback;
	}

	if ( preg_match( '/^(?:UTC|GMT)?\s*([+-])(\d{1,2})(?:([.:])(\d{1,2}))?$/i', $tz, $m ) ) {
		$minutes = 0;
		if ( isset( $m[4] ) && '' !== $m[4] ) {
			$minutes = '.' === $m[3] ? (int) round( (float) ( '0.' . $m[4] ) * 60 ) : (int) $m[4];
		}
		try {
			return new DateTimeZone( sprintf( '%s%02d:%02d', $m[1], (int) $m[2], $minutes ) );
		} catch ( Exception $e ) {
			return $fallback;
		}
	}

	if ( in_array( strtoupper( $tz ), array( 'UTC', 'GMT', 'Z' ), true ) ) {
		return new DateTimeZone( 'UTC' );
	}

	try {
		return new DateTimeZone( $tz );
	} catch ( Exception $e ) {
		return $fallback;
	}
}

/**
 * Convert one TEC date into the site timezone.
 *
 * The UTC copy wins when TEC saved one, since it is unambiguous. Otherwise the
 * local value is read in the event's own timezone.
 *
 * @param string       $local    "Y-m-d H:i:s" in the event's timezone.
 * @param string       $utc      "Y-m-d H:i:s" in UTC, or ''.
 * @param string       $event_tz The event's timezone as TEC stored it.
 * @param DateTimeZone $site_tz  The site timezone (wp_timezone()).
 * @return string "Y-m-d H:i:s" in the site timezone, or '' when unreadable.
 */
function npmp_tec_convert_datetime( $local, $utc, $event_tz, DateTimeZone $site_tz ) {
	$utc   = trim( (string) $utc );
	$local = trim( (string) $local );

	try {
		if ( '' !== $utc && '0000-00-00 00:00:00' !== $utc ) {
			$date = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
		} elseif ( '' !== $local && '0000-00-00 00:00:00' !== $local ) {
			$date = new DateTimeImmutable( $local, npmp_tec_parse_timezone( $event_tz, $site_tz ) );
		} else {
			return '';
		}
	} catch ( Exception $e ) {
		return '';
	}

	return $date->setTimezone( $site_tz )->format( 'Y-m-d H:i:s' );
}

/**
 * Is a TEC all-day flag on? TEC has saved 'yes', '1', and true over the years.
 *
 * @param mixed $value Stored _EventAllDay value.
 * @return bool
 */
function npmp_tec_is_all_day( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	return in_array( strtolower( trim( (string) $value ) ), array( 'yes', '1', 'true', 'on' ), true );
}

/**
 * Work out an event's start and end in the site timezone.
 *
 * An all-day event keeps its calendar dates, since "all day on December 5"
 * means December 5 wherever you are. It runs 00:00 to 23:59, the same values
 * the event editor saves for an empty start and end time.
 *
 * @param array        $dates   Keys start, end, start_utc, end_utc, timezone, all_day.
 * @param DateTimeZone $site_tz The site timezone.
 * @return array{start:string,end:string,all_day:bool}
 */
function npmp_tec_event_times( array $dates, DateTimeZone $site_tz ) {
	$all_day = npmp_tec_is_all_day( $dates['all_day'] ?? '' );
	$tz      = (string) ( $dates['timezone'] ?? '' );

	if ( $all_day ) {
		$start_day = substr( trim( (string) ( $dates['start'] ?? '' ) ), 0, 10 );
		$end_day   = substr( trim( (string) ( $dates['end'] ?? '' ) ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_day ) ) {
			$start_day = substr( npmp_tec_convert_datetime( '', $dates['start_utc'] ?? '', $tz, $site_tz ), 0, 10 );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_day ) || $end_day < $start_day ) {
			$end_day = $start_day;
		}
		return array(
			'start'   => $start_day ? $start_day . ' 00:00:00' : '',
			'end'     => $end_day ? $end_day . ' 23:59:00' : '',
			'all_day' => true,
		);
	}

	$start = npmp_tec_convert_datetime( $dates['start'] ?? '', $dates['start_utc'] ?? '', $tz, $site_tz );
	$end   = npmp_tec_convert_datetime( $dates['end'] ?? '', $dates['end_utc'] ?? '', $tz, $site_tz );
	if ( '' === $end || ( '' !== $start && $end < $start ) ) {
		$end = $start;
	}

	return array(
		'start'   => $start,
		'end'     => $end,
		'all_day' => false,
	);
}

/**
 * Build the one-line location the event editor shows: the venue name, then
 * the address. "Ventura Hall, 1 Main St, Ventura, CA 93001, United States".
 *
 * @param string $name  Venue name.
 * @param array  $parts Keys address, city, state, zip, country.
 * @return string
 */
function npmp_tec_format_venue( $name, array $parts ) {
	$clean = static function ( $value ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ), " \t\n\r\0\x0B," ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure helper, unit tested without WordPress.
	};

	$state_zip = trim( $clean( $parts['state'] ?? '' ) . ' ' . $clean( $parts['zip'] ?? '' ) );
	$pieces    = array(
		$clean( $name ),
		$clean( $parts['address'] ?? '' ),
		$clean( $parts['city'] ?? '' ),
		$state_zip,
		$clean( $parts['country'] ?? '' ),
	);

	$out = array();
	foreach ( $pieces as $piece ) {
		if ( '' === $piece ) {
			continue;
		}
		// A venue named after its street address shouldn't print the address twice.
		if ( $out && 0 === strcasecmp( end( $out ), $piece ) ) {
			continue;
		}
		$out[] = $piece;
	}

	return implode( ', ', $out );
}

/**
 * Normalise a URL or request path into the form the redirect lookup stores.
 *
 * Drops the scheme, host, any WordPress subdirectory, repeated and trailing
 * slashes, and every query argument except TEC's plain-permalink pair
 * (?tribe_events=slug&eventDate=Y-m-d). Lowercases the result, since
 * WordPress slugs are lowercase and people type URLs any way they like.
 *
 * @param string $url       Full URL or request URI.
 * @param string $home_path Path part of home_url(), such as '/blog' or ''.
 * @return string Path starting with '/', such as '/event/spring-gala'.
 */
function npmp_tec_normalize_path( $url, $home_path = '' ) {
	$url = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $url );

	// A request URI of "//event/x" would read as a host to parse_url().
	if ( false === strpos( $url, '://' ) ) {
		$url = '/' . ltrim( $url, '/' );
	}

	$path  = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, unit tested without WordPress.
	$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, unit tested without WordPress.

	$path = rawurldecode( $path );
	$path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' );

	$home = '/' . trim( (string) $home_path, '/' );
	if ( '/' !== $home ) {
		if ( strtolower( $path ) === strtolower( $home ) ) {
			$path = '/';
		} elseif ( 0 === stripos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
	}

	$path = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );

	if ( '' !== $query ) {
		parse_str( $query, $args );
		if ( isset( $args['tribe_events'] ) && is_string( $args['tribe_events'] ) && '' !== trim( $args['tribe_events'] ) ) {
			$path  = rtrim( $path, '/' ) . '/?tribe_events=' . strtolower( trim( $args['tribe_events'] ) );
			$event = $args['eventDate'] ?? ( $args['eventdate'] ?? '' );
			if ( is_string( $event ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event ) ) {
				$path .= '&eventdate=' . $event;
			}
		}
	}

	return $path;
}

/**
 * Old URL of one date in a TEC Pro recurring series.
 *
 * Pretty permalinks add the date as a path segment (/event/yoga/2026-11-05/).
 * Plain permalinks add it as ?eventDate=.
 *
 * @param string $base_url Permalink of the series.
 * @param string $date     Occurrence date, "Y-m-d".
 * @return string
 */
function npmp_tec_occurrence_url( $base_url, $date ) {
	if ( false !== strpos( $base_url, 'tribe_events=' ) ) {
		return $base_url . ( false === strpos( $base_url, '?' ) ? '?' : '&' ) . 'eventDate=' . $date;
	}
	return rtrim( $base_url, '/' ) . '/' . $date . '/';
}

/**
 * Strip TEC's own blocks from event content.
 *
 * Events written in the block editor carry blocks like tribe/event-datetime
 * and tribe/event-venue. They store no HTML, so once TEC is off they render
 * nothing, and they'd only clutter the editor. Paired tribe blocks keep the
 * HTML inside them.
 *
 * @param string $content Post content.
 * @return string
 */
function npmp_tec_clean_content( $content ) {
	$content = (string) $content;
	if ( false === strpos( $content, 'wp:tribe/' ) ) {
		return $content;
	}
	// Self-closing blocks, then the opening and closing delimiters of paired ones.
	$content = preg_replace( '#<!--\s+wp:tribe/[a-z0-9_-]+(\s+\{.*?\})?\s+/-->\s*#s', '', $content );
	$content = preg_replace( '#<!--\s+/?wp:tribe/[a-z0-9_-]+(\s+\{.*?\})?\s+-->\s*#s', '', $content );
	return trim( $content );
}

/**
 * Does this plugin need The Events Calendar to run?
 *
 * True when the plugin says so in its Requires Plugins header, when it lives in
 * a folder TEC's own add-ons use, or when its name starts with "The Events
 * Calendar" (for example "The Events Calendar Shortcode & Templates", which
 * declares nothing).
 *
 * @param string $file Plugin basename, such as 'the-events-calendar-shortcode/the-events-calendar-shortcode.php'.
 * @param array  $data Plugin header data from get_plugins().
 * @return bool
 */
function npmp_tec_plugin_needs_tec( $file, array $data ) {
	$folder = strtolower( strtok( (string) $file, '/' ) );
	if ( 'the-events-calendar' === $folder ) {
		return false;
	}

	$requires = array_map( 'trim', explode( ',', strtolower( (string) ( $data['RequiresPlugins'] ?? '' ) ) ) );
	if ( in_array( 'the-events-calendar', $requires, true ) ) {
		return true;
	}

	$known = array(
		'events-calendar-pro',
		'the-events-calendar-filterbar',
		'tribe-filterbar',
		'the-events-calendar-community-events',
		'events-community',
		'the-events-calendar-community-events-tickets',
		'events-virtual',
		'event-aggregator',
		'event-automator',
		'the-events-calendar-shortcode',
		'events-calendar-shortcode',
		'tec-events-shortcode',
	);
	if ( in_array( $folder, $known, true ) || 0 === strpos( $folder, 'tribe-ext-' ) || 0 === strpos( $folder, 'tec-labs-' ) ) {
		return true;
	}

	return 0 === stripos( trim( (string) ( $data['Name'] ?? '' ) ), 'The Events Calendar' );
}

// =====================================================================
// State
// =====================================================================

/**
 * Is The Events Calendar running on this request?
 *
 * @return bool
 */
function npmp_tec_is_active() {
	return class_exists( 'Tribe__Events__Main', false ) || post_type_exists( 'tribe_events' );
}

/**
 * Show the importer? Yes while TEC runs, and afterwards once anything came over.
 *
 * @return bool
 */
function npmp_tec_importer_visible() {
	return npmp_tec_is_active() || (int) get_option( 'npmp_tec_imported', 0 ) > 0;
}

/**
 * Option name for a user's staging list.
 *
 * Staging lives in one non-autoloaded option per user. A transient could be
 * evicted by an object cache halfway through a review, and an option keyed to
 * the user keeps two admins from overwriting each other's list. It holds
 * titles, dates, venues, and categories, not post content: the migrate step
 * reads content straight from the TEC post, which TEC's data still holds.
 *
 * @param int $user_id User ID, 0 for the current user.
 * @return string
 */
function npmp_tec_stage_option( $user_id = 0 ) {
	return 'npmp_tec_stage_' . ( $user_id ? (int) $user_id : get_current_user_id() );
}

/**
 * Read the current user's staging list.
 *
 * @return array
 */
function npmp_tec_get_stage() {
	$stage = get_option( npmp_tec_stage_option(), array() );
	return array_merge(
		array(
			'cursor'      => 0,
			'read_posts'  => 0,
			'done'        => false,
			'items'       => array(),
			'skip'        => array(),
			'reviewed'    => false,
			'approved'    => false,
			'options'     => array(),
			'queue'       => array(),
			'position'    => 0,
			'migrated'    => false,
			'results'     => array(
				'created'    => 0,
				'duplicates' => 0,
				'failed'     => array(),
			),
			'deactivated' => array(),
		),
		is_array( $stage ) ? $stage : array()
	);
}

/**
 * Save the current user's staging list.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_save_stage( array $stage ) {
	update_option( npmp_tec_stage_option(), $stage, false );
}

/**
 * Throw away the current user's staging list. Imported events stay.
 *
 * @return void
 */
function npmp_tec_reset_stage() {
	delete_option( npmp_tec_stage_option() );
}

/**
 * Post statuses worth moving. Trash and auto-drafts stay behind.
 *
 * @return string[]
 */
function npmp_tec_statuses() {
	return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

// =====================================================================
// Step 1: read TEC events into staging
// =====================================================================

/**
 * Count the TEC events the importer will read.
 *
 * @return int
 */
function npmp_tec_count_events() {
	global $wpdb;
	$statuses = npmp_tec_statuses();
	$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND post_status IN ($in)", $statuses ) );
}

/**
 * Does TEC's occurrences table exist on this site?
 *
 * @return bool
 */
function npmp_tec_has_occurrences_table() {
	global $wpdb;
	static $exists = null;
	if ( null === $exists ) {
		$table  = $wpdb->prefix . 'tec_occurrences';
		$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	return $exists;
}

/**
 * Occurrences for a batch of TEC events, keyed by post ID.
 *
 * Only events with more than one row are recurring. TEC 6 writes one row for
 * every single event too, and those come in from post meta like any other.
 *
 * @param int[] $post_ids TEC event IDs.
 * @return array<int,array<int,object>>
 */
function npmp_tec_get_occurrences( array $post_ids ) {
	global $wpdb;
	if ( ! $post_ids || ! npmp_tec_has_occurrences_table() ) {
		return array();
	}
	$post_ids = array_map( 'absint', $post_ids );
	$in       = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	$table    = $wpdb->prefix . 'tec_occurrences';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, $in is a placeholder list.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, start_date, start_date_utc, end_date, end_date_utc FROM {$table} WHERE post_id IN ($in) ORDER BY post_id, start_date_utc", $post_ids ) );

	$by_post = array();
	foreach ( (array) $rows as $row ) {
		$by_post[ (int) $row->post_id ][] = $row;
	}
	return array_filter(
		$by_post,
		static function ( $list ) {
			return count( $list ) > 1;
		}
	);
}

/**
 * Find npmp events already created from these staging keys.
 *
 * @param string[] $keys Staging keys.
 * @return array<string,int> Key => npmp_event ID.
 */
function npmp_tec_find_imported( array $keys ) {
	global $wpdb;
	$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );
	if ( ! $keys ) {
		return array();
	}
	$found = array();
	foreach ( array_chunk( $keys, 500 ) as $chunk ) {
		$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS k, pm.post_id AS id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_tec_source_key' AND p.post_type = 'npmp_event' AND pm.meta_value IN ($in)", $chunk ) );
		foreach ( (array) $rows as $row ) {
			$found[ (string) $row->k ] = (int) $row->id;
		}
	}
	return $found;
}

/**
 * Read one TEC venue as a location line.
 *
 * @param int $venue_id Venue post ID.
 * @return array{name:string,location:string}
 */
function npmp_tec_read_venue( $venue_id ) {
	static $cache = array();
	$venue_id     = absint( $venue_id );
	if ( ! $venue_id ) {
		return array(
			'name'     => '',
			'location' => '',
		);
	}
	if ( ! isset( $cache[ $venue_id ] ) ) {
		$venue = get_post( $venue_id );
		$name  = $venue ? html_entity_decode( (string) $venue->post_title, ENT_QUOTES, 'UTF-8' ) : '';
		$state = get_post_meta( $venue_id, '_VenueStateProvince', true );
		if ( '' === (string) $state ) {
			$state = get_post_meta( $venue_id, '_VenueState', true );
		}
		if ( '' === (string) $state ) {
			$state = get_post_meta( $venue_id, '_VenueProvince', true );
		}
		$cache[ $venue_id ] = array(
			'name'     => $name,
			'location' => npmp_tec_format_venue(
				$name,
				array(
					'address' => get_post_meta( $venue_id, '_VenueAddress', true ),
					'city'    => get_post_meta( $venue_id, '_VenueCity', true ),
					'state'   => $state,
					'zip'     => get_post_meta( $venue_id, '_VenueZip', true ),
					'country' => get_post_meta( $venue_id, '_VenueCountry', true ),
				)
			),
		);
	}
	return $cache[ $venue_id ];
}

/**
 * Read the categories on a TEC event, parents first.
 *
 * @param int $post_id TEC event ID.
 * @return array<int,array{slug:string,name:string,parent:string,description:string}>
 */
function npmp_tec_read_categories( $post_id ) {
	if ( ! taxonomy_exists( 'tribe_events_cat' ) ) {
		return array();
	}
	$terms = wp_get_object_terms( $post_id, 'tribe_events_cat' );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$out = array();
	foreach ( $terms as $term ) {
		$parent = '';
		if ( $term->parent ) {
			$parent_term = get_term( $term->parent, 'tribe_events_cat' );
			$parent      = ( $parent_term && ! is_wp_error( $parent_term ) ) ? $parent_term->slug : '';
		}
		$out[] = array(
			'slug'        => $term->slug,
			'name'        => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
			'parent'      => $parent,
			'description' => (string) $term->description,
		);
	}
	return $out;
}

/**
 * Turn one TEC event into one staging item, or one per date for a series.
 *
 * @param WP_Post      $post        TEC event.
 * @param array|null   $occurrences Rows from npmp_tec_get_occurrences(), or null.
 * @param DateTimeZone $site_tz     Site timezone.
 * @return array<string,array> Items keyed by staging key.
 */
function npmp_tec_read_event( $post, $occurrences, DateTimeZone $site_tz ) {
	$id       = (int) $post->ID;
	$venue_id = (int) get_post_meta( $id, '_EventVenueID', true );
	$venue    = npmp_tec_read_venue( $venue_id );

	$organizers = array();
	foreach ( (array) get_post_meta( $id, '_EventOrganizerID', false ) as $org_id ) {
		$org = $org_id ? get_post( (int) $org_id ) : null;
		if ( $org ) {
			$organizers[] = html_entity_decode( (string) $org->post_title, ENT_QUOTES, 'UTF-8' );
		}
	}

	$permalink = 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
	$all_day   = get_post_meta( $id, '_EventAllDay', true );
	$timezone  = (string) get_post_meta( $id, '_EventTimezone', true );

	$base = array(
		'tec_id'     => $id,
		'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'status'     => $post->post_status,
		'venue'      => $venue['name'],
		'location'   => $venue['location'],
		'organizers' => $organizers,
		'url'        => esc_url_raw( (string) get_post_meta( $id, '_EventURL', true ) ),
		'thumbnail'  => (int) get_post_thumbnail_id( $post ),
		'categories' => npmp_tec_read_categories( $id ),
		'timezone'   => $timezone,
		'imported'   => 0,
	);

	$items = array();

	if ( $occurrences ) {
		foreach ( array_values( $occurrences ) as $i => $row ) {
			$times = npmp_tec_event_times(
				array(
					'start'     => $row->start_date,
					'end'       => $row->end_date,
					'start_utc' => $row->start_date_utc,
					'end_utc'   => $row->end_date_utc,
					'timezone'  => $timezone,
					'all_day'   => $all_day,
				),
				$site_tz
			);
			$day   = substr( (string) $row->start_date, 0, 10 );
			$key   = $id . '@' . gmdate( 'YmdHi', strtotime( $row->start_date_utc . ' UTC' ) );
			$paths = array();
			if ( $permalink ) {
				$paths[] = npmp_tec_normalize_path( npmp_tec_occurrence_url( $permalink, $day ), npmp_tec_home_path() );
				if ( 0 === $i ) {
					// The series URL itself lands on its first date.
					$paths[] = npmp_tec_normalize_path( $permalink, npmp_tec_home_path() );
				}
			}
			$items[ $key ] = array_merge(
				$base,
				$times,
				array(
					'key'        => $key,
					'occurrence' => $day,
					'paths'      => array_values( array_unique( $paths ) ),
				)
			);
		}
		return $items;
	}

	$times = npmp_tec_event_times(
		array(
			'start'     => get_post_meta( $id, '_EventStartDate', true ),
			'end'       => get_post_meta( $id, '_EventEndDate', true ),
			'start_utc' => get_post_meta( $id, '_EventStartDateUTC', true ),
			'end_utc'   => get_post_meta( $id, '_EventEndDateUTC', true ),
			'timezone'  => $timezone,
			'all_day'   => $all_day,
		),
		$site_tz
	);

	$key           = (string) $id;
	$items[ $key ] = array_merge(
		$base,
		$times,
		array(
			'key'        => $key,
			'occurrence' => '',
			'paths'      => $permalink ? array( npmp_tec_normalize_path( $permalink, npmp_tec_home_path() ) ) : array(),
		)
	);
	return $items;
}

/**
 * Path part of the home URL, for the redirect lookup.
 *
 * @return string
 */
function npmp_tec_home_path() {
	return (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
}

/**
 * Read the next batch of TEC events into the current user's staging list.
 *
 * @param bool $restart Start a fresh list.
 * @param int  $limit   Events per batch.
 * @return array{done:bool,read:int,total:int,items:int}
 */
function npmp_tec_stage_batch( $restart = false, $limit = NPMP_TEC_BATCH ) {
	global $wpdb;

	if ( $restart ) {
		npmp_tec_reset_stage();
	}
	$stage = npmp_tec_get_stage();
	$total = npmp_tec_count_events();

	if ( $stage['done'] ) {
		return array(
			'done'  => true,
			'read'  => (int) $stage['read_posts'],
			'total' => $total,
			'items' => count( $stage['items'] ),
		);
	}

	$statuses = npmp_tec_statuses();
	$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args     = array_merge( array( (int) $stage['cursor'] ), $statuses, array( max( 1, (int) $limit ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND ID > %d AND post_status IN ($in) ORDER BY ID ASC LIMIT %d", $args ) ) );

	if ( $ids ) {
		_prime_post_caches( $ids, false, true );
		$site_tz     = wp_timezone();
		$occurrences = npmp_tec_get_occurrences( $ids );
		$new_items   = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$new_items += npmp_tec_read_event( $post, $occurrences[ $id ] ?? null, $site_tz );
			}
		}
		foreach ( npmp_tec_find_imported( array_keys( $new_items ) ) as $key => $new_id ) {
			$new_items[ $key ]['imported'] = $new_id;
		}
		$stage['items']      += $new_items;
		$stage['cursor']      = end( $ids );
		$stage['read_posts'] += count( $ids );
	}

	if ( count( $ids ) < $limit ) {
		$stage['done'] = true;
	}
	npmp_tec_save_stage( $stage );

	return array(
		'done'  => (bool) $stage['done'],
		'read'  => (int) $stage['read_posts'],
		'total' => $total,
		'items' => count( $stage['items'] ),
	);
}

// =====================================================================
// Steps 2 and 3: review and approve
// =====================================================================

/**
 * Keep only well-formed staging keys ("123" or "123@202611051800").
 *
 * @param mixed $keys Raw keys, an array or a comma-joined string.
 * @return string[]
 */
function npmp_tec_clean_keys( $keys ) {
	if ( is_string( $keys ) ) {
		$keys = explode( ',', $keys );
	}
	$out = array();
	foreach ( (array) $keys as $key ) {
		$key = trim( (string) $key );
		if ( preg_match( '/^\d+(@\d{12})?$/', $key ) ) {
			$out[ $key ] = $key;
		}
	}
	return array_values( $out );
}

/**
 * Save which staged events stay behind.
 *
 * @param string[] $skip Keys you unchecked.
 * @return void
 */
function npmp_tec_set_skip( array $skip ) {
	$stage             = npmp_tec_get_stage();
	$stage['skip']     = array_values( array_intersect( npmp_tec_clean_keys( $skip ), array_keys( $stage['items'] ) ) );
	$stage['reviewed'] = true;
	$stage['approved'] = false;
	npmp_tec_save_stage( $stage );
}

/**
 * Keys that will move: checked and not already imported.
 *
 * @param array $stage Stage.
 * @return string[]
 */
function npmp_tec_selected_keys( array $stage ) {
	$skip = array_flip( $stage['skip'] );
	$keys = array();
	foreach ( $stage['items'] as $key => $item ) {
		if ( empty( $item['imported'] ) && ! isset( $skip[ (string) $key ] ) ) {
			$keys[] = (string) $key;
		}
	}
	return $keys;
}

/**
 * Sanitize the importer options form, including fields Pro adds through the
 * npmp_tec_import_options action (names under npmp_tec_options[...]).
 *
 * @param mixed $raw Raw posted value.
 * @return array
 */
function npmp_tec_sanitize_options( $raw ) {
	$out = array();
	foreach ( (array) $raw as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			continue;
		}
		$out[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) : sanitize_text_field( (string) $value );
	}
	return $out;
}

/**
 * Approve the selection and queue it for the migrate step.
 *
 * @param array $options Sanitized options from the approve form.
 * @return int Events queued.
 */
function npmp_tec_approve( array $options = array() ) {
	$stage             = npmp_tec_get_stage();
	$stage['options']  = $options;
	$stage['queue']    = npmp_tec_selected_keys( $stage );
	$stage['position'] = 0;
	$stage['approved'] = true;
	$stage['migrated'] = false;
	$stage['results']  = array(
		'created'    => 0,
		'duplicates' => 0,
		'failed'     => array(),
	);
	npmp_tec_save_stage( $stage );
	return count( $stage['queue'] );
}

// =====================================================================
// Step 4: migrate
// =====================================================================

/**
 * Turn on the calendar module so the new events have a home.
 *
 * Registers the event post type and taxonomy on this request too, because
 * the calendar files only load at plugin start.
 *
 * @return void
 */
function npmp_tec_ensure_calendar_module() {
	$features = get_option(
		'npmp_enabled_features',
		array(
			'members'     => true,
			'newsletters' => false,
			'donations'   => true,
			'calendar'    => false,
			'social'      => false,
		)
	);
	if ( empty( $features['calendar'] ) ) {
		$features['calendar'] = true;
		// An existing option fires npmp_handle_feature_toggle(), which loads
		// the calendar and flushes rewrites. A missing one only adds the option.
		update_option( 'npmp_enabled_features', $features );
	}

	if ( ! post_type_exists( 'npmp_event' ) || ! taxonomy_exists( 'npmp_event_category' ) ) {
		$calendar = dirname( __DIR__ ) . '/npmp-calendar.php';
		if ( file_exists( $calendar ) ) {
			require_once $calendar;
		}
		if ( function_exists( 'npmp_register_events_module' ) ) {
			npmp_register_events_module();
		}
		// Rebuild rewrite rules on the next request so /events/ resolves.
		delete_option( 'rewrite_rules' );
	}
}

/**
 * Find or create the npmp_event_category term for a TEC category.
 *
 * @param array $cat        Category from npmp_tec_read_categories().
 * @param array $all_by_slug Every category on the item, by slug, for parents.
 * @param int   $depth      Recursion guard.
 * @return int Term ID, 0 on failure.
 */
function npmp_tec_ensure_term( array $cat, array $all_by_slug = array(), $depth = 0 ) {
	$existing = get_term_by( 'slug', $cat['slug'], 'npmp_event_category' );
	if ( $existing ) {
		return (int) $existing->term_id;
	}

	$parent_id = 0;
	if ( '' !== $cat['parent'] && $depth < 5 ) {
		$parent_existing = get_term_by( 'slug', $cat['parent'], 'npmp_event_category' );
		if ( $parent_existing ) {
			$parent_id = (int) $parent_existing->term_id;
		} else {
			$parent_cat = $all_by_slug[ $cat['parent'] ] ?? null;
			if ( ! $parent_cat && taxonomy_exists( 'tribe_events_cat' ) ) {
				$tec_parent = get_term_by( 'slug', $cat['parent'], 'tribe_events_cat' );
				if ( $tec_parent ) {
					$grand      = $tec_parent->parent ? get_term( $tec_parent->parent, 'tribe_events_cat' ) : null;
					$parent_cat = array(
						'slug'        => $tec_parent->slug,
						'name'        => html_entity_decode( $tec_parent->name, ENT_QUOTES, 'UTF-8' ),
						'parent'      => ( $grand && ! is_wp_error( $grand ) ) ? $grand->slug : '',
						'description' => (string) $tec_parent->description,
					);
				}
			}
			if ( $parent_cat ) {
				$parent_id = npmp_tec_ensure_term( $parent_cat, $all_by_slug, $depth + 1 );
			}
		}
	}

	$made = wp_insert_term(
		$cat['name'],
		'npmp_event_category',
		array(
			'slug'        => $cat['slug'],
			'parent'      => $parent_id,
			'description' => $cat['description'] ?? '',
		)
	);
	if ( is_wp_error( $made ) ) {
		// A same-named term under another slug already exists. Use it.
		$id = $made->get_error_data( 'term_exists' );
		return $id ? (int) $id : 0;
	}
	return (int) $made['term_id'];
}

/**
 * Create one npmp_event from a staging item.
 *
 * @param array $item    Staging item.
 * @param array $options Approve-form options.
 * @return int|WP_Error New event ID.
 */
function npmp_tec_migrate_item( array $item, array $options = array() ) {
	$source = get_post( (int) $item['tec_id'] );
	if ( ! $source ) {
		return new WP_Error( 'npmp_tec_missing', __( 'The original event is gone from the database.', 'nonprofit-manager' ) );
	}

	$status = in_array( $item['status'], npmp_tec_statuses(), true ) ? $item['status'] : 'draft';
	$meta   = array(
		'_npmp_event_start'     => $item['start'],
		'_npmp_event_end'       => $item['end'],
		'_npmp_tec_source_id'   => (int) $item['tec_id'],
		'_npmp_tec_source_key'  => (string) $item['key'],
	);
	if ( '' !== $item['location'] ) {
		$meta['_npmp_event_location'] = $item['location'];
	}
	if ( '' !== $item['url'] ) {
		$meta['_npmp_event_url'] = $item['url'];
	}
	if ( ! empty( $item['all_day'] ) ) {
		$meta['_npmp_event_all_day'] = 1;
	}
	if ( ! empty( $item['organizers'] ) ) {
		$meta['_npmp_tec_organizer'] = implode( ', ', $item['organizers'] );
	}
	if ( $item['thumbnail'] && get_post( $item['thumbnail'] ) ) {
		$meta['_thumbnail_id'] = (int) $item['thumbnail'];
	}

	$postarr = array(
		'post_type'      => 'npmp_event',
		'post_status'    => $status,
		'post_title'     => $item['title'],
		'post_content'   => npmp_tec_clean_content( $source->post_content ),
		'post_excerpt'   => $source->post_excerpt,
		'post_author'    => (int) $source->post_author,
		'post_name'      => '' !== $item['occurrence'] ? $source->post_name . '-' . $item['occurrence'] : $source->post_name,
		'post_password'  => $source->post_password,
		'comment_status' => 'closed',
		'meta_input'     => $meta,
	);
	if ( 'future' === $status || 'publish' === $status ) {
		$postarr['post_date']     = $source->post_date;
		$postarr['post_date_gmt'] = $source->post_date_gmt;
	}

	$new_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $new_id ) ) {
		return $new_id;
	}

	foreach ( $item['paths'] as $path ) {
		add_post_meta( $new_id, '_npmp_tec_old_path', $path );
	}

	$slugs   = array();
	$by_slug = array();
	foreach ( $item['categories'] as $cat ) {
		$by_slug[ $cat['slug'] ] = $cat;
	}
	$term_ids = array();
	foreach ( $item['categories'] as $cat ) {
		$slugs[]  = $cat['slug'];
		$term_id  = npmp_tec_ensure_term( $cat, $by_slug );
		if ( $term_id ) {
			$term_ids[] = $term_id;
		}
	}
	if ( $term_ids ) {
		wp_set_object_terms( $new_id, $term_ids, 'npmp_event_category' );
	}

	/**
	 * One TEC event just became an npmp_event.
	 *
	 * Pro hooks this to put the event in a group for each TEC category when
	 * you ticked that option on the approve screen.
	 *
	 * @param int   $new_id   New npmp_event ID.
	 * @param int   $tec_id   TEC event (tribe_events post) ID.
	 * @param array $slugs    TEC category slugs on the event (tribe_events_cat).
	 * @param array $options  Sanitized values from the approve form, keyed by
	 *                        the name inside npmp_tec_options[...].
	 */
	do_action( 'npmp_tec_event_imported', (int) $new_id, (int) $item['tec_id'], $slugs, $options );

	return (int) $new_id;
}

/**
 * Migrate the next batch of approved events.
 *
 * @param int $limit Events per batch.
 * @return array{done:bool,position:int,total:int,created:int}
 */
function npmp_tec_migrate_batch( $limit = NPMP_TEC_BATCH ) {
	$stage = npmp_tec_get_stage();
	$total = count( $stage['queue'] );

	if ( ! $stage['approved'] || $stage['migrated'] ) {
		return array(
			'done'     => (bool) $stage['migrated'],
			'position' => (int) $stage['position'],
			'total'    => $total,
			'created'  => (int) $stage['results']['created'],
		);
	}

	$slice  = array_slice( $stage['queue'], (int) $stage['position'], max( 1, (int) $limit ) );
	$before = (int) $stage['results']['created'];
	if ( $slice ) {
		$already = npmp_tec_find_imported( $slice );
		$todo    = array_diff( $slice, array_keys( $already ) );
		if ( $todo ) {
			npmp_tec_ensure_calendar_module();
		}
		foreach ( $slice as $key ) {
			if ( isset( $already[ $key ] ) ) {
				// Another tab or an earlier run made it first. Never twice.
				$stage['items'][ $key ]['imported'] = $already[ $key ];
				++$stage['results']['duplicates'];
				continue;
			}
			$item = $stage['items'][ $key ] ?? null;
			if ( ! $item ) {
				continue;
			}
			$result = npmp_tec_migrate_item( $item, (array) $stage['options'] );
			if ( is_wp_error( $result ) ) {
				$stage['results']['failed'][] = array(
					'title' => $item['title'],
					'error' => $result->get_error_message(),
				);
				continue;
			}
			$stage['items'][ $key ]['imported'] = $result;
			++$stage['results']['created'];
		}
		$stage['position'] += count( $slice );
	}

	if ( $stage['position'] >= $total ) {
		$stage['migrated'] = true;
	}
	npmp_tec_save_stage( $stage );

	$made = (int) $stage['results']['created'] - $before;
	if ( $made ) {
		// Autoloaded, so the 404 redirect and the menu check cost no query.
		update_option( 'npmp_tec_imported', (int) get_option( 'npmp_tec_imported', 0 ) + $made, true );
	}

	return array(
		'done'     => (bool) $stage['migrated'],
		'position' => (int) $stage['position'],
		'total'    => $total,
		'created'  => (int) $stage['results']['created'],
	);
}

// =====================================================================
// Step 5: deactivate TEC
// =====================================================================

/**
 * Plugin basename of The Events Calendar, or '' when it isn't installed.
 *
 * @return string
 */
function npmp_tec_plugin_file() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( 0 === strpos( $file, 'the-events-calendar/' ) ) {
			return $file;
		}
	}
	return '';
}

/**
 * Active plugins the deactivate step turns off: add-ons first, TEC last.
 *
 * @return array<string,string> Basename => plugin name.
 */
function npmp_tec_plugins_to_deactivate() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all = get_plugins();
	$tec = npmp_tec_plugin_file();
	$out = array();
	foreach ( $all as $file => $data ) {
		if ( $file !== $tec && is_plugin_active( $file ) && npmp_tec_plugin_needs_tec( $file, $data ) ) {
			$out[ $file ] = $data['Name'];
		}
	}
	if ( $tec && is_plugin_active( $tec ) ) {
		$out[ $tec ] = $all[ $tec ]['Name'];
	}
	return $out;
}

/**
 * Deactivate The Events Calendar and every active plugin that needs it.
 * Deletes nothing, so TEC's events, venues, and settings stay in the database.
 *
 * @return array<string,string> Plugins turned off, basename => name.
 */
function npmp_tec_deactivate() {
	$plugins = npmp_tec_plugins_to_deactivate();
	if ( ! $plugins ) {
		return array();
	}
	deactivate_plugins( array_keys( $plugins ) );
	// TEC's routes (/event/slug/) must go so its old URLs reach the 404 redirect.
	delete_option( 'rewrite_rules' );

	$stage                = npmp_tec_get_stage();
	$stage['deactivated'] = array_values( $plugins );
	npmp_tec_save_stage( $stage );

	return array_filter(
		$plugins,
		static function ( $file ) {
			return ! is_plugin_active( $file );
		},
		ARRAY_FILTER_USE_KEY
	);
}

// =====================================================================
// Front end: 301 from old TEC URLs
// =====================================================================

/**
 * Find the npmp_event that replaced an old TEC path.
 *
 * One query on the indexed meta_key, cached per path in the object cache.
 *
 * @param string $path Normalised path.
 * @return int Event ID, 0 when none.
 */
function npmp_tec_find_redirect( $path ) {
	global $wpdb;
	$cache_key = 'path_' . md5( $path );
	$found     = wp_cache_get( $cache_key, 'npmp_tec_redirect' );
	if ( false !== $found ) {
		return (int) $found;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached just above.
	$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_tec_old_path' AND pm.meta_value = %s AND p.post_type = 'npmp_event' AND p.post_status = 'publish' ORDER BY pm.post_id ASC LIMIT 1", $path ) );
	wp_cache_set( $cache_key, $found, 'npmp_tec_redirect', HOUR_IN_SECONDS );
	return $found;
}

/**
 * Send a 301 from an old TEC event URL to its new event.
 *
 * Runs before redirect_canonical() so WordPress's own 404 guesser can't send
 * the visitor somewhere else first.
 *
 * @return void
 */
function npmp_tec_maybe_redirect() {
	if ( ! get_option( 'npmp_tec_imported' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup of a public URL.
	$legacy = isset( $_GET['tribe_events'] ) && ! post_type_exists( 'tribe_events' );
	if ( ! $legacy && ! is_404() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- npmp_tec_normalize_path() strips it to a path, and the lookup is prepared. sanitize_text_field() would drop %-encoded slugs.
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
	$path   = npmp_tec_normalize_path( $uri, npmp_tec_home_path() );
	$target = npmp_tec_find_redirect( $path );
	if ( ! $target ) {
		return;
	}

	$url = get_permalink( $target );
	if ( $url ) {
		wp_safe_redirect( $url, 301, 'Nonprofit Manager' );
		exit;
	}
}
add_action( 'template_redirect', 'npmp_tec_maybe_redirect', 1 );

// =====================================================================
// Admin
// =====================================================================

if ( is_admin() ) {
	add_action( 'admin_menu', 'npmp_tec_register_menu', 11 );
	add_action( 'admin_notices', 'npmp_tec_admin_notice' );
	add_action( 'admin_enqueue_scripts', 'npmp_tec_enqueue' );
	add_action( 'npmp_import_page_notices', 'npmp_tec_import_screen_card' );
	add_action( 'wp_ajax_npmp_tec_stage', 'npmp_tec_ajax_stage' );
	add_action( 'wp_ajax_npmp_tec_migrate', 'npmp_tec_ajax_migrate' );
	add_action( 'admin_post_npmp_tec_stage', 'npmp_tec_post_stage' );
	add_action( 'admin_post_npmp_tec_review', 'npmp_tec_post_review' );
	add_action( 'admin_post_npmp_tec_approve', 'npmp_tec_post_approve' );
	add_action( 'admin_post_npmp_tec_migrate', 'npmp_tec_post_migrate' );
	add_action( 'admin_post_npmp_tec_deactivate', 'npmp_tec_post_deactivate' );
	add_action( 'admin_post_npmp_tec_reset', 'npmp_tec_post_reset' );
	add_action( 'admin_post_npmp_tec_dismiss', 'npmp_tec_post_dismiss' );
}

/**
 * URL of the importer screen.
 *
 * @param int   $step Step to open, 0 for wherever you left off.
 * @param array $args Extra query args.
 * @return string
 */
function npmp_tec_page_url( $step = 0, array $args = array() ) {
	if ( $step ) {
		$args['step'] = (int) $step;
	}
	return add_query_arg( $args, admin_url( 'admin.php?page=npmp_tec_import' ) );
}

/**
 * Register the importer screen under Events, or under the main menu when the
 * calendar module is off.
 *
 * @return void
 */
function npmp_tec_register_menu() {
	if ( ! npmp_tec_importer_visible() ) {
		return;
	}
	$features = get_option( 'npmp_enabled_features', array() );
	add_submenu_page(
		! empty( $features['calendar'] ) ? 'npmp-events' : 'npmp_main',
		__( 'Import from The Events Calendar', 'nonprofit-manager' ),
		__( 'Import from TEC', 'nonprofit-manager' ),
		'manage_options',
		'npmp_tec_import',
		'npmp_tec_render_page'
	);
}

/**
 * The notice on the Events screens while TEC is active.
 *
 * @return void
 */
function npmp_tec_admin_notice() {
	if ( ! npmp_tec_is_active() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_user_meta( get_current_user_id(), 'npmp_tec_notice_dismissed', true ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$features = get_option( 'npmp_enabled_features', array() );
	$screens  = array( 'toplevel_page_npmp-events', 'edit-npmp_event', 'npmp_event', 'edit-npmp_event_category' );
	if ( empty( $features['calendar'] ) ) {
		// No Events menu yet, so the main screen carries the notice.
		$screens[] = 'toplevel_page_npmp_main';
	}
	if ( ! in_array( $screen->id, $screens, true ) && false === strpos( (string) $screen->id, 'npmp_event_settings' ) ) {
		return;
	}

	$stage   = npmp_tec_get_stage();
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=npmp_tec_dismiss' ), 'npmp_tec_dismiss' );
	echo '<div class="notice notice-info npmp-tec-notice"><p>';
	if ( $stage['migrated'] ) {
		esc_html_e( 'You moved your events. You can turn off The Events Calendar now, and its old event links will keep working.', 'nonprofit-manager' );
		echo '</p><p><a class="button button-primary" href="' . esc_url( npmp_tec_page_url( 5 ) ) . '">' . esc_html__( 'Turn off The Events Calendar', 'nonprofit-manager' ) . '</a> ';
	} else {
		echo esc_html(
			sprintf(
				/* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */
				__( 'You have The Events Calendar running. You can move its events into %s in five steps, and links to your old event pages will keep working.', 'nonprofit-manager' ),
				npmp_label( 'app_name' )
			)
		);
		echo '</p><p><a class="button button-primary" href="' . esc_url( npmp_tec_page_url() ) . '">' . esc_html__( 'Move my events', 'nonprofit-manager' ) . '</a> ';
	}
	echo '<a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Not now', 'nonprofit-manager' ) . '</a></p></div>';
}

/**
 * A card at the top of the member Import screen.
 *
 * @return void
 */
function npmp_tec_import_screen_card() {
	if ( ! npmp_tec_importer_visible() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-info inline npmp-tec-card"><p><strong>' . esc_html__( 'Switching from The Events Calendar?', 'nonprofit-manager' ) . '</strong> ';
	esc_html_e( 'You can bring over every event, venue, and category, and keep the old event links working.', 'nonprofit-manager' );
	echo ' <a href="' . esc_url( npmp_tec_page_url() ) . '">' . esc_html__( 'Import your events', 'nonprofit-manager' ) . '</a></p></div>';
}

/**
 * Load the importer's script and styles on its own screen.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_tec_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_tec_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-tec-import.js' ) : '1';
	wp_enqueue_style( 'npmp-tec-import', $base . 'assets/css/npmp-tec-import.css', array(), $ver );
	wp_enqueue_script( 'npmp-tec-import', $base . 'assets/js/npmp-tec-import.js', array(), $ver, true );
}

/**
 * Stop unless the user can import and the nonce checks out.
 *
 * @param string $cap Capability.
 * @return void
 */
function npmp_tec_check_request( $cap = 'manage_options' ) {
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_tec_import', 'npmp_tec_nonce' );
}

/**
 * AJAX: read one batch.
 *
 * @return void
 */
function npmp_tec_ajax_stage() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 );
	}
	check_ajax_referer( 'npmp_tec_import', 'npmp_tec_nonce' );
	if ( ! npmp_tec_is_active() ) {
		wp_send_json_error( array( 'message' => __( 'The Events Calendar is off, so there are no events to read.', 'nonprofit-manager' ) ) );
	}
	wp_send_json_success( npmp_tec_stage_batch( ! empty( $_POST['restart'] ) ) );
}

/**
 * AJAX: migrate one batch.
 *
 * @return void
 */
function npmp_tec_ajax_migrate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 );
	}
	check_ajax_referer( 'npmp_tec_import', 'npmp_tec_nonce' );
	wp_send_json_success( npmp_tec_migrate_batch() );
}

/**
 * Run batches for up to 20 seconds. The no-JavaScript fallback.
 *
 * @param callable $batch Batch function returning an array with 'done'.
 * @return void
 */
function npmp_tec_run_for_a_while( $batch ) {
	$until = microtime( true ) + 20;
	do {
		$result = call_user_func( $batch );
	} while ( empty( $result['done'] ) && microtime( true ) < $until );
}

/**
 * Form: read events without JavaScript.
 *
 * @return void
 */
function npmp_tec_post_stage() {
	npmp_tec_check_request();
	$restart = ! empty( $_POST['restart'] );
	npmp_tec_run_for_a_while(
		static function () use ( &$restart ) {
			$result  = npmp_tec_stage_batch( $restart );
			$restart = false;
			return $result;
		}
	);
	wp_safe_redirect( npmp_tec_page_url() );
	exit;
}

/**
 * Form: save the review table.
 *
 * With JavaScript the form posts one comma-joined list of the boxes you
 * unchecked, which stays under PHP's max_input_vars on a site with thousands
 * of events. Without it, the checked boxes post one by one.
 *
 * @return void
 */
function npmp_tec_post_review() {
	npmp_tec_check_request();
	$stage = npmp_tec_get_stage();
	if ( isset( $_POST['npmp_tec_mode'] ) && 'skip' === sanitize_key( wp_unslash( $_POST['npmp_tec_mode'] ) ) ) {
		$skip = npmp_tec_clean_keys( sanitize_text_field( wp_unslash( $_POST['npmp_tec_skip'] ?? '' ) ) );
	} else {
		$keep = npmp_tec_clean_keys( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npmp_tec_keep'] ?? array() ) ) );
		$skip = array_diff( array_map( 'strval', array_keys( $stage['items'] ) ), $keep );
	}
	npmp_tec_set_skip( $skip );
	wp_safe_redirect( npmp_tec_page_url( 3 ) );
	exit;
}

/**
 * Form: approve and start the migration.
 *
 * @return void
 */
function npmp_tec_post_approve() {
	npmp_tec_check_request();
	$options = npmp_tec_sanitize_options( wp_unslash( $_POST['npmp_tec_options'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized key by key in npmp_tec_sanitize_options().
	npmp_tec_approve( $options );
	wp_safe_redirect( npmp_tec_page_url( 4 ) );
	exit;
}

/**
 * Form: migrate without JavaScript.
 *
 * @return void
 */
function npmp_tec_post_migrate() {
	npmp_tec_check_request();
	npmp_tec_run_for_a_while( 'npmp_tec_migrate_batch' );
	wp_safe_redirect( npmp_tec_page_url( 4 ) );
	exit;
}

/**
 * Form: deactivate TEC and its add-ons.
 *
 * @return void
 */
function npmp_tec_post_deactivate() {
	npmp_tec_check_request( 'activate_plugins' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	npmp_tec_deactivate();
	wp_safe_redirect( npmp_tec_page_url( 5, array( 'off' => 1 ) ) );
	exit;
}

/**
 * Form: start over with a fresh list.
 *
 * @return void
 */
function npmp_tec_post_reset() {
	npmp_tec_check_request();
	npmp_tec_reset_stage();
	wp_safe_redirect( npmp_tec_page_url( 1 ) );
	exit;
}

/**
 * Link: hide the Events screen notice for this user.
 *
 * @return void
 */
function npmp_tec_post_dismiss() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run the importer.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'npmp_tec_dismiss' );
	update_user_meta( get_current_user_id(), 'npmp_tec_notice_dismissed', 1 );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=npmp_main' ) );
	exit;
}

/**
 * The furthest step you can open with the list as it stands.
 *
 * @param array $stage Stage.
 * @return int
 */
function npmp_tec_max_step( array $stage ) {
	if ( ! $stage['done'] ) {
		return 1;
	}
	if ( ! $stage['reviewed'] ) {
		return 2;
	}
	if ( ! $stage['approved'] ) {
		return 3;
	}
	if ( ! $stage['migrated'] ) {
		return 4;
	}
	return 5;
}

/**
 * Render a hidden nonce plus an action for an admin-post form.
 *
 * @param string $action admin-post action.
 * @return void
 */
function npmp_tec_form_fields( $action ) {
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( 'npmp_tec_import', 'npmp_tec_nonce' );
}

/**
 * Format a staged start for the review table.
 *
 * @param array $item Staging item.
 * @return string
 */
function npmp_tec_display_date( array $item ) {
	if ( '' === $item['start'] ) {
		return __( 'No date', 'nonprofit-manager' );
	}
	try {
		$ts = ( new DateTimeImmutable( $item['start'], wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return $item['start'];
	}
	if ( ! empty( $item['all_day'] ) ) {
		/* translators: %s: event date. */
		return sprintf( __( '%s, all day', 'nonprofit-manager' ), wp_date( get_option( 'date_format' ), $ts ) );
	}
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
}

/**
 * The importer screen.
 *
 * @return void
 */
function npmp_tec_render_page() {
	npmp_verify_admin_access( 'manage_options' );

	$stage = npmp_tec_get_stage();
	$max   = npmp_tec_max_step( $stage );
	$step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : $max; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
	if ( $step < 1 || $step > $max || ( $max >= 4 && $step < 4 ) ) {
		$step = $max;
	}

	$labels = array(
		1 => __( 'Import', 'nonprofit-manager' ),
		2 => __( 'Review', 'nonprofit-manager' ),
		3 => __( 'Approve', 'nonprofit-manager' ),
		4 => __( 'Migrate', 'nonprofit-manager' ),
		5 => __( 'Turn off The Events Calendar', 'nonprofit-manager' ),
	);

	echo '<div class="wrap npmp-tec-wrap">';
	echo '<h1>' . esc_html__( 'Import from The Events Calendar', 'nonprofit-manager' ) . '</h1>';
	/* translators: 1: step number, 2: total steps, 3: step name. */
	echo '<p class="npmp-tec-progress-line">' . esc_html( sprintf( __( 'Step %1$d of %2$d: %3$s', 'nonprofit-manager' ), $step, 5, $labels[ $step ] ) ) . '</p>';
	echo '<ol class="npmp-tec-steps">';
	foreach ( $labels as $n => $label ) {
		$class = $n === $step ? 'is-current' : ( $n < $step ? 'is-done' : '' );
		echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</li>';
	}
	echo '</ol>';

	switch ( $step ) {
		case 2:
			npmp_tec_render_review( $stage );
			break;
		case 3:
			npmp_tec_render_approve( $stage );
			break;
		case 4:
			npmp_tec_render_migrate( $stage );
			break;
		case 5:
			npmp_tec_render_deactivate( $stage );
			break;
		default:
			npmp_tec_render_import( $stage );
	}

	if ( $stage['items'] || $stage['read_posts'] ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-reset">';
		npmp_tec_form_fields( 'npmp_tec_reset' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Start over with a fresh list', 'nonprofit-manager' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Events you already moved stay put and show up as moved.', 'nonprofit-manager' ) . '</span></form>';
	}
	echo '</div>';
}

/**
 * A progress bar the script drives through admin-ajax, with a plain form
 * underneath for browsers without JavaScript.
 *
 * @param string $ajax_action   wp_ajax action.
 * @param string $post_action   admin-post fallback action.
 * @param string $button        Button label.
 * @param string $next_url      Where to go when done.
 * @param bool   $autostart     Start without a click.
 * @param bool   $restart       Send restart=1 on the first request.
 * @return void
 */
function npmp_tec_render_runner( $ajax_action, $post_action, $button, $next_url, $autostart = false, $restart = false ) {
	printf(
		'<div class="npmp-tec-runner" data-ajax-url="%1$s" data-action="%2$s" data-nonce="%3$s" data-next="%4$s" data-autostart="%5$s" data-restart="%6$s" data-label-progress="%7$s" data-label-error="%8$s">',
		esc_url( admin_url( 'admin-ajax.php' ) ),
		esc_attr( $ajax_action ),
		esc_attr( wp_create_nonce( 'npmp_tec_import' ) ),
		esc_url( $next_url ),
		$autostart ? '1' : '0',
		$restart ? '1' : '0',
		/* translators: 1: events done so far, 2: total events. Keep the placeholders. */
		esc_attr__( '%1$s of %2$s events', 'nonprofit-manager' ),
		esc_attr__( 'Something went wrong. Reload the page to pick up where it stopped.', 'nonprofit-manager' )
	);
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-runner-form">';
	npmp_tec_form_fields( $post_action );
	if ( $restart ) {
		echo '<input type="hidden" name="restart" value="1">';
	}
	echo '<button type="submit" class="button button-primary button-hero npmp-tec-runner-start">' . esc_html( $button ) . '</button></form>';
	echo '<div class="npmp-tec-bar" hidden><div class="npmp-tec-bar-fill"></div></div>';
	echo '<p class="npmp-tec-bar-text" aria-live="polite"></p>';
	echo '</div>';
}

/**
 * Step 1: read the events.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_render_import( array $stage ) {
	if ( ! npmp_tec_is_active() ) {
		echo '<p>' . esc_html__( 'The Events Calendar is off, so there are no events to read. To bring more events over, turn it back on from Plugins.', 'nonprofit-manager' ) . '</p>';
		$moved = (int) get_option( 'npmp_tec_imported', 0 );
		if ( $moved ) {
			echo '<p>' . esc_html__( 'Your moved events are under Events, and their old links still send visitors to the new pages.', 'nonprofit-manager' ) . '</p>';
		}
		return;
	}

	$total = npmp_tec_count_events();
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( 'You have %s event in The Events Calendar.', 'You have %s events in The Events Calendar.', $total, 'nonprofit-manager' ),
			number_format_i18n( $total )
		)
	) . '</p>';
	echo '<p>' . esc_html__( 'First, the importer reads each one: its title, description, image, dates, venue, organizer, and categories. Nothing moves yet, and The Events Calendar keeps all of its data. You pick what moves on the next screen.', 'nonprofit-manager' ) . '</p>';
	if ( npmp_tec_has_occurrences_table() ) {
		echo '<p class="description">' . esc_html__( 'A repeating event from Events Calendar Pro comes in as one event for each date.', 'nonprofit-manager' ) . '</p>';
	}
	if ( $stage['read_posts'] && ! $stage['done'] ) {
		/* translators: 1: events read so far, 2: total events. */
		echo '<p>' . esc_html( sprintf( __( 'You read %1$s of %2$s events so far. Keep going to finish the list.', 'nonprofit-manager' ), number_format_i18n( $stage['read_posts'] ), number_format_i18n( $total ) ) ) . '</p>';
	}
	npmp_tec_render_runner(
		'npmp_tec_stage',
		'npmp_tec_stage',
		$stage['read_posts'] ? __( 'Keep reading', 'nonprofit-manager' ) : __( 'Read my events', 'nonprofit-manager' ),
		npmp_tec_page_url( 2 ),
		false,
		! $stage['read_posts']
	);
}

/**
 * Step 2: the review table.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_render_review( array $stage ) {
	$items = $stage['items'];
	if ( ! $items ) {
		echo '<p>' . esc_html__( 'The Events Calendar has no events to move.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	$skip  = array_flip( $stage['skip'] );
	$moved = count( array_filter( wp_list_pluck( $items, 'imported' ) ) );

	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( 'The importer found %s event and checked the box. Uncheck the box if you don\'t want to move the event.', 'The importer found %s events and checked every one. Uncheck the ones you don\'t want to move.', count( $items ), 'nonprofit-manager' ),
			number_format_i18n( count( $items ) )
		)
	) . '</p>';
	if ( $moved ) {
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of events. */
				_n( '%s event is already in Events from an earlier import, so it stays as it is.', '%s events are already in Events from an earlier import, so they stay as they are.', $moved, 'nonprofit-manager' ),
				number_format_i18n( $moved )
			)
		) . '</p>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-tec-review-form">';
	npmp_tec_form_fields( 'npmp_tec_review' );
	echo '<input type="hidden" name="npmp_tec_mode" value="keep">';
	echo '<input type="hidden" name="npmp_tec_skip" value="">';
	echo '<p class="npmp-tec-select"><button type="button" class="button-link" data-npmp-tec-select="all">' . esc_html__( 'Select all', 'nonprofit-manager' ) . '</button> | <button type="button" class="button-link" data-npmp-tec-select="none">' . esc_html__( 'Select none', 'nonprofit-manager' ) . '</button></p>';
	echo '<div class="npmp-tec-table-wrap"><table class="widefat striped npmp-tec-table"><thead><tr>';
	echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Move', 'nonprofit-manager' ) . '</span></td>';
	echo '<th scope="col">' . esc_html__( 'Event', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Venue', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Category', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $items as $key => $item ) {
		$key      = (string) $key;
		$imported = (int) $item['imported'];
		$checked  = ! $imported && ! isset( $skip[ $key ] );
		echo '<tr' . ( $imported ? ' class="npmp-tec-imported"' : '' ) . '><th scope="row" class="check-column">';
		if ( $imported ) {
			echo '<input type="checkbox" disabled aria-label="' . esc_attr__( 'Already moved', 'nonprofit-manager' ) . '">';
		} else {
			printf(
				'<input type="checkbox" class="npmp-tec-pick" name="npmp_tec_keep[]" value="%1$s" %2$s aria-label="%3$s">',
				esc_attr( $key ),
				checked( $checked, true, false ),
				/* translators: %s: event title. */
				esc_attr( sprintf( __( 'Move %s', 'nonprofit-manager' ), $item['title'] ) )
			);
		}
		echo '</th><td><strong>' . esc_html( $item['title'] ) . '</strong>';
		if ( 'publish' !== $item['status'] ) {
			echo ' <span class="npmp-tec-status">' . esc_html( get_post_status_object( $item['status'] ) ? get_post_status_object( $item['status'] )->label : $item['status'] ) . '</span>';
		}
		if ( $imported ) {
			echo ' <a class="npmp-tec-badge" href="' . esc_url( (string) get_edit_post_link( $imported ) ) . '">' . esc_html__( 'Already moved', 'nonprofit-manager' ) . '</a>';
		}
		echo '</td><td>' . esc_html( npmp_tec_display_date( $item ) ) . '</td>';
		echo '<td>' . esc_html( $item['venue'] ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', wp_list_pluck( $item['categories'], 'name' ) ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'nonprofit-manager' ) . '</button></p></form>';
}

/**
 * Step 3: approve, with options. This is the form that starts the migration.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_render_approve( array $stage ) {
	$selected = npmp_tec_selected_keys( $stage );
	$count    = count( $selected );
	$left     = array_intersect_key( $stage['items'], array_flip( $stage['skip'] ) );

	if ( ! $count ) {
		echo '<p>' . esc_html__( 'You unchecked every event, so nothing will move.', 'nonprofit-manager' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( npmp_tec_page_url( 2 ) ) . '">' . esc_html__( 'Back to the list', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( 'You\'re moving %s event into Events.', 'You\'re moving %s events into Events.', $count, 'nonprofit-manager' ),
			number_format_i18n( $count )
		)
	) . '</p>';
	echo '<ul class="ul-disc">';
	echo '<li>' . esc_html__( 'Each one keeps its title, description, featured image, dates, venue and address, website link, and categories.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Dates come over in your site\'s timezone, set under Settings, General.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Links to the old event pages will send visitors to the new ones.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'The Events Calendar keeps its own copy, so you can switch it back on if something looks wrong.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( $left ) {
		echo '<details class="npmp-tec-left"><summary>' . esc_html(
			sprintf(
				/* translators: %s: number of events. */
				_n( '%s event stays behind', '%s events stay behind', count( $left ), 'nonprofit-manager' ),
				number_format_i18n( count( $left ) )
			)
		) . '</summary><ul class="ul-disc">';
		foreach ( $left as $item ) {
			echo '<li>' . esc_html( $item['title'] . ' (' . npmp_tec_display_date( $item ) . ')' ) . '</li>';
		}
		echo '</ul></details>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="npmp-tec-migrate-form" class="npmp-tec-migrate-form">';
	npmp_tec_form_fields( 'npmp_tec_approve' );
	echo '<div class="npmp-tec-options">';
	/**
	 * Add fields to the importer's approve form, the form that starts the
	 * migration. Name them npmp_tec_options[your_key]. The sanitized values
	 * reach every npmp_tec_event_imported call in $options.
	 */
	do_action( 'npmp_tec_import_options' );
	echo '</div>';
	echo '<p><a class="button" href="' . esc_url( npmp_tec_page_url( 2 ) ) . '">' . esc_html__( 'Back to the list', 'nonprofit-manager' ) . '</a> ';
	echo '<button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( 'Approve and move %s event', 'Approve and move %s events', $count, 'nonprofit-manager' ),
			number_format_i18n( $count )
		)
	) . '</button></p></form>';
}

/**
 * Step 4: the migration's progress, then what happened.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_render_migrate( array $stage ) {
	if ( ! $stage['migrated'] ) {
		echo '<p>' . esc_html__( 'Keep this page open while your events move. It goes 100 at a time.', 'nonprofit-manager' ) . '</p>';
		npmp_tec_render_runner( 'npmp_tec_migrate', 'npmp_tec_migrate', __( 'Move my events', 'nonprofit-manager' ), npmp_tec_page_url( 4 ), true );
		return;
	}

	$r = $stage['results'];
	echo '<div class="notice notice-success inline"><p>' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( 'You moved %s event.', 'You moved %s events.', (int) $r['created'], 'nonprofit-manager' ),
			number_format_i18n( (int) $r['created'] )
		)
	) . '</p></div>';
	if ( $r['duplicates'] ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of events. */
				_n( '%s event was already there, so the importer left it alone.', '%s events were already there, so the importer left them alone.', (int) $r['duplicates'], 'nonprofit-manager' ),
				number_format_i18n( (int) $r['duplicates'] )
			)
		) . '</p>';
	}
	if ( $r['failed'] ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These events didn\'t move:', 'nonprofit-manager' ) . '</p><ul class="ul-disc">';
		foreach ( $r['failed'] as $fail ) {
			echo '<li>' . esc_html( $fail['title'] . ': ' . $fail['error'] ) . '</li>';
		}
		echo '</ul></div>';
	}
	echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ) . '">' . esc_html__( 'See your events', 'nonprofit-manager' ) . '</a> ';
	echo '<a class="button button-primary" href="' . esc_url( npmp_tec_page_url( 5 ) ) . '">' . esc_html__( 'Continue', 'nonprofit-manager' ) . '</a></p>';
}

/**
 * Step 5: turn TEC off, with a confirm screen that names every plugin.
 *
 * @param array $stage Stage.
 * @return void
 */
function npmp_tec_render_deactivate( array $stage ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		echo '<p>' . esc_html__( 'Ask someone who can manage plugins on this site to turn off The Events Calendar.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	$plugins = npmp_tec_plugins_to_deactivate();
	if ( ! $plugins ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The Events Calendar is off. Its events, venues, and settings are still in your database, so you can turn it back on from Plugins if something looks wrong.', 'nonprofit-manager' ) . '</p></div>';
		if ( $stage['deactivated'] ) {
			echo '<p>' . esc_html__( 'You turned off:', 'nonprofit-manager' ) . '</p><ul class="ul-disc">';
			foreach ( $stage['deactivated'] as $name ) {
				echo '<li>' . esc_html( $name ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ) . '">' . esc_html__( 'See your events', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only. The deactivate form below carries the nonce.
	$confirm = ! empty( $_GET['confirm'] );
	if ( ! $confirm ) {
		echo '<p>' . esc_html__( 'You moved your events. Once you check a few of the new ones, you can turn off The Events Calendar so it stops loading on every page.', 'nonprofit-manager' ) . '</p>';
		echo '<p>' . esc_html__( 'The importer deletes nothing. The Events Calendar keeps its events, venues, and settings, so you can turn it back on from Plugins.', 'nonprofit-manager' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_tec_page_url( 5, array( 'confirm' => 1 ) ) ) . '">' . esc_html__( 'Turn off The Events Calendar', 'nonprofit-manager' ) . '</a></p>';
		return;
	}

	echo '<p>' . esc_html(
		_n( 'You\'re about to turn off this plugin:', 'You\'re about to turn off these plugins:', count( $plugins ), 'nonprofit-manager' )
	) . '</p><ul class="ul-disc">';
	foreach ( $plugins as $name ) {
		echo '<li><strong>' . esc_html( $name ) . '</strong></li>';
	}
	echo '</ul>';
	if ( count( $plugins ) > 1 ) {
		echo '<p>' . esc_html__( 'The others can\'t run without The Events Calendar, so you turn them off together.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_tec_form_fields( 'npmp_tec_deactivate' );
	echo '<p><a class="button" href="' . esc_url( npmp_tec_page_url( 5 ) ) . '">' . esc_html__( 'Cancel', 'nonprofit-manager' ) . '</a> ';
	echo '<button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: number of plugins. */
			_n( 'Yes, turn off %s plugin', 'Yes, turn off %s plugins', count( $plugins ), 'nonprofit-manager' ),
			number_format_i18n( count( $plugins ) )
		)
	) . '</button></p></form>';
}
