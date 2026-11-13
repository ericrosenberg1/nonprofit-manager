<?php
/**
 * Real-WordPress run of the Event Organiser, My Calendar, and WP Event
 * Manager importers on the real plugins: seed events through each plugin's
 * own code, check the counts on Switch Plugins, import, check every date,
 * time, place, link, image, category, status, and skip, run it again, turn
 * each plugin off through Switch Plugins, follow the old links over HTTP, and
 * import once more with the plugins off.
 *
 * Seeds (site time zone America/Denver):
 *   Event Organiser (eo_insert_event):
 *     Rig EO Gala      one evening, a venue with an address, a child category, an image
 *     Rig EO Fair      all day
 *     Rig EO Weekend   three days
 *     Rig EO Weekly    four Mondays with the second one taken out (three dates)
 *     Rig EO Past      last year
 *     Rig EO Draft     a draft (comes over as a draft, no old link)
 *     Rig EO Trashed   in the trash (stays behind)
 *   My Calendar (mc_insert_event, mc_insert_location, mc_create_category):
 *     Rig MC Gala      one evening, a location, a category, an event link, an image URL
 *     Rig MC Families' Fair  all day
 *     Rig MC Weekend   three days
 *     Rig MC Weekly    four Mondays with one date deleted (mc_delete_instance)
 *     Rig MC Past      last year
 *     Rig MC Cancelled cancelled (comes over as a draft)
 *     Rig MC Trashed   in the trash (stays behind)
 *   WP Event Manager (its own admin save, WP_Event_Manager_Writepanels):
 *     Rig WPEM Gala    New York time (per-event time zones on), a venue, a child category, an image
 *     Rig WPEM Weekend three days
 *     Rig WPEM Online  online (no location line)
 *     Rig WPEM Past    expired
 *     Rig WPEM Cancelled  cancelled (comes over as a draft)
 *     Rig WPEM Trashed in the trash (stays behind)
 *   WP Event Manager has no repeating events and Event Organiser no cancelled
 *   status, so those seeds don't exist.
 *
 * Needs a throwaway WordPress with this plugin and the three old plugins
 * installed from wordpress.org (Event Organiser 3.12.12, My Calendar 3.8.13,
 * WP Event Manager 3.4.2 verified), the calendar feature on, pretty
 * permalinks, the site time zone America/Denver, `wp` and `curl` on PATH,
 * and the site served at its home URL (php -S works). Visit wp-admin once so
 * My Calendar finishes its install. Run with Pro on and off:
 *   wp plugin install event-organiser my-calendar wp-event-manager
 *   wp eval-file tests/rig/more-event-sources.php
 *
 * The main run turns the three plugins on at the start and off through
 * Switch Plugins at the end, and puts back every setting it changes. A second
 * run reuses the seeded events. `wp eval-file tests/rig/more-event-sources.php
 * remove` then runs step 4 (deletes the three plugins, and Event Organiser's
 * own uninstall drops its dates), checks step 5 stays unavailable, and checks
 * the old links still lead to the new events. Reinstall the plugins after it.
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
	'event-organiser'  => 'event-organiser/event-organiser.php',
	'my-calendar'      => 'my-calendar/my-calendar.php',
	'wp-event-manager' => 'wp-event-manager/wp-event-manager.php',
);
$slugs = array_keys( $plugin_files );

// ---------------------------------------------------------------------
// Child phases, each in its own `wp` process.
// ---------------------------------------------------------------------
if ( 0 === strpos( $phase, 'import:' ) ) {
	$parts  = explode( ':', $phase );
	$slug   = $parts[1];
	$picked = isset( $parts[2] ) ? array_filter( explode( ',', $parts[2] ) ) : null;
	$state  = npmp_event_import_state( $slug );
	$state['running'] = false;
	npmp_event_import_save_state( $slug, $state );
	$state = npmp_event_import_run( $slug, 60, 2, $picked );
	echo 'JSON ', wp_json_encode( array( 'state' => $state, 'active' => is_plugin_active( $plugin_files[ $slug ] ) ) ), "\n";
	return;
}

if ( 0 === strpos( $phase, 'hub:' ) ) {
	$slug   = substr( $phase, 4 );
	$step   = function ( $name, $input = array() ) use ( $slug ) {
		$r = npmp_import_hub_run_step( $slug, $name, $input );
		return is_wp_error( $r ) ? $r->get_error_code() : $r;
	};
	$early  = $step( 'remove_data', array( 'confirm' => npmp_import_hub_confirm_word() ) );
	$unchk  = $step( 'verify' );
	$verify = $step( 'verify', array( 'verified' => 1 ) );
	$off    = $step( 'deactivate' );
	echo 'JSON ', wp_json_encode(
		array(
			'early'    => $early,
			'unticked' => $unchk,
			'verify'   => $verify,
			'deactivate' => $off,
			'active'   => is_plugin_active( $plugin_files[ $slug ] ),
			'rules'    => get_option( 'rewrite_rules' ),
		)
	), "\n";
	return;
}

if ( 0 === strpos( $phase, 'remove:' ) ) {
	$slug   = substr( $phase, 7 );
	$r      = npmp_import_hub_run_step( $slug, 'remove_plugin' );
	$data   = npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => npmp_import_hub_confirm_word() ) );
	$states = npmp_import_hub_step_states( npmp_import_hub_facts( npmp_import_sources()[ $slug ] ) );
	echo 'JSON ', wp_json_encode(
		array(
			'remove_plugin' => is_wp_error( $r ) ? $r->get_error_code() : $r,
			'remove_data'   => is_wp_error( $data ) ? $data->get_error_code() : $data,
			'states'        => $states,
			'installed'     => file_exists( WP_PLUGIN_DIR . '/' . $plugin_files[ $slug ] ),
		)
	), "\n";
	return;
}

if ( 0 === strpos( $phase, 'source:' ) ) {
	$slug    = substr( $phase, 7 );
	$sources = npmp_import_sources();
	$src     = $sources[ $slug ] ?? array();
	$counts  = array(
		'event-organiser'  => 'npmp_eo_counts',
		'my-calendar'      => 'npmp_mc_counts',
		'wp-event-manager' => 'npmp_wpem_counts',
	);
	ob_start();
	npmp_import_hub_render_step_deactivate( $src, array( 'installed' => true ), 'open', true );
	$step3 = wp_strip_all_tags( (string) ob_get_clean() );
	echo 'JSON ', wp_json_encode(
		array(
			'step3'       => $step3,
			'registered'  => isset( $sources[ $slug ] ),
			'has_batch'   => isset( $src['data_removal']['batch'] ),
			'reason'      => (string) ( $src['data_removal']['reason'] ?? '' ),
			'has_data'    => isset( $src['has_data'] ) ? (bool) npmp_import_hub_call( $src['has_data'], false ) : null,
			'rows'        => isset( $src['counts'] ) ? npmp_import_hub_call( $src['counts'], null ) : null,
			'counts'      => call_user_func( $counts[ $slug ] ),
			'delete_note' => (string) npmp_import_hub_call( $src['delete_note'] ?? null, '' ),
			'deactivate_note' => (string) npmp_import_hub_call( $src['deactivate_note'] ?? null, '' ),
			'settings'    => npmp_event_import_setting_rows( npmp_event_import_sources()[ $slug ] ),
			'installed'   => array_keys( (array) npmp_import_hub_call( $src['installed_plugins'] ?? null, array() ) ),
		)
	), "\n";
	return;
}

$run_phase = function ( $phase ) {
	$cmd = sprintf( 'wp --path=%s eval-file %s %s 2>&1', escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( __FILE__ ), escapeshellarg( $phase ) );
	$out = array();
	exec( $cmd, $out );
	foreach ( $out as $line ) {
		if ( 0 === strpos( $line, 'JSON ' ) ) {
			return json_decode( substr( $line, 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === stripos( $l, 'deprecated' ) && false === stripos( $l, '_load_textdomain' );
	} ) ) );
};

$get = function ( $url ) {
	return trim( (string) shell_exec( 'curl -s -o /dev/null --max-time 20 -w "%{http_code} %{redirect_url}" ' . escapeshellarg( $url ) ) );
};

$find = function ( $slug, $title ) {
	return get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'title' => $title, 'meta_key' => '_npmp_import_source', 'meta_value' => $slug, 'orderby' => 'ID', 'order' => 'ASC' ) );
};
$meta  = function ( $id, $key ) {
	return (string) get_post_meta( $id, $key, true );
};

// A child process wrote the option, so skip this process's option cache.
$fresh = function ( $name ) {
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	return get_option( $name );
};

$pro_on = is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' );

// ---------------------------------------------------------------------
// Step 4 run: delete the three plugins, then check what's left.
// ---------------------------------------------------------------------
if ( 'remove' === $phase ) {
	echo 'Remove plugins (Pro ' . ( $pro_on ? 'on' : 'off' ) . ")\n";
	$before = array();
	foreach ( $slugs as $slug ) {
		$before[ $slug ] = npmp_event_import_imported_counts( $slug );
		$r = $run_phase( 'remove:' . $slug );
		$ok( "$slug step 4 deleted the plugin", 'removed_plugin' === ( $r['remove_plugin']['message'] ?? '' ) && false === ( $r['installed'] ?? true ), $r );
		$ok( "$slug step 5 refuses (unavailable)", is_string( $r['remove_data'] ?? null ) && 'unavailable' === ( $r['states']['remove_data'] ?? '' ), $r );
		$ok( "$slug imported events untouched", $before[ $slug ] === npmp_event_import_imported_counts( $slug ), npmp_event_import_imported_counts( $slug ) );
	}
	$ok( 'Event Organiser uninstall dropped its dates table', '' === npmp_event_import_table( 'eo_events' ) );
	$ok( 'My Calendar kept its tables (drop setting off)', '' !== npmp_event_import_table( 'my_calendar' ) );
	foreach ( array( '/events/event/rig-eo-gala/', '/mc-events/rig-mc-gala/', '/event/rig-wpem-gala/' ) as $old ) {
		$hit = $get( home_url( $old ) );
		$ok( "old link $old still answers 301 after the delete", 0 === strpos( $hit, '301 ' . home_url( '/events/' ) ), $hit );
	}
	printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
	return;
}

echo 'Setup (Pro ' . ( $pro_on ? 'on' : 'off' ) . ")\n";
foreach ( $plugin_files as $slug => $plugin ) {
	if ( ! is_plugin_active( $plugin ) ) {
		activate_plugin( $plugin );
	}
	$ok( "$slug is active", is_plugin_active( $plugin ) );
}
$ok( 'site time zone is America/Denver (not UTC)', 'America/Denver' === wp_timezone_string() );
$ok( 'pretty permalinks', '' !== (string) get_option( 'permalink_structure' ) );
foreach ( $slugs as $slug ) {
	$ok( "$slug event source registered", isset( npmp_event_import_sources()[ $slug ] ) );
}
$ok( 'Event Organiser tables exist', '' !== npmp_event_import_table( 'eo_events' ) && '' !== npmp_event_import_table( 'eo_venuemeta' ) );
$ok( 'My Calendar tables exist', '' !== npmp_event_import_table( 'my_calendar' ) && '' !== npmp_event_import_table( 'my_calendar_events' ) );

// Start from a clean import.
foreach ( $slugs as $slug ) {
	foreach ( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_npmp_import_source', 'meta_value' => $slug ) ) as $old ) {
		wp_delete_post( $old, true );
	}
	delete_option( 'npmp_event_import_' . $slug );
	$hub = get_option( 'npmp_import_hub', array() );
	unset( $hub[ $slug ] );
	update_option( 'npmp_import_hub', $hub );
}
foreach ( array( 'rig-eo-community', 'rig-eo-fundraisers', 'rig-mc-arts', 'rig-wpem-community', 'rig-wpem-fundraisers' ) as $term_slug ) {
	$term = get_term_by( 'slug', $term_slug, 'npmp_event_category' );
	if ( $term ) {
		wp_delete_term( $term->term_id, 'npmp_event_category' );
	}
}

// One image in the media library, for all three.
$image = (int) $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = 'rig-events.png' LIMIT 1" );
if ( ! $image ) {
	$up = wp_upload_dir();
	file_put_contents( $up['basedir'] . '/rig-events.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
	$image = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Rig events', 'post_status' => 'inherit', 'guid' => $up['baseurl'] . '/rig-events.png' ), $up['basedir'] . '/rig-events.png' );
}
$image_url = wp_get_attachment_url( $image );
$ok( 'rig image is in the media library', $image && $image === (int) attachment_url_to_postid( $image_url ), $image_url );

// ---------------------------------------------------------------------
// Seed Event Organiser through eo_insert_event (once).
// ---------------------------------------------------------------------
$eo_post = function ( $title ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event' AND post_title = %s ORDER BY ID ASC LIMIT 1", $title ) );
};
if ( ! $eo_post( 'Rig EO Gala' ) ) {
	$tz     = eo_get_blog_timezone();
	$at     = function ( $when ) use ( $tz ) {
		return new DateTime( $when, $tz );
	};
	$parent = wp_insert_term( 'Rig EO Community', 'event-category', array( 'slug' => 'rig-eo-community' ) );
	$child  = wp_insert_term( 'Rig EO Fundraisers', 'event-category', array( 'slug' => 'rig-eo-fundraisers', 'parent' => $parent['term_id'], 'description' => 'Money nights' ) );
	$venue  = eo_insert_venue( 'Rig EO Hall', array( 'address' => '1 Main St', 'city' => 'Denver', 'state' => 'CO', 'postcode' => '80202', 'country' => 'US' ) );
	$gala   = eo_insert_event(
		array( 'post_title' => 'Rig EO Gala', 'post_content' => '<p><strong>Black tie.</strong> Doors at six.</p>', 'post_status' => 'publish' ),
		array( 'start' => $at( '2027-03-10 18:00' ), 'end' => $at( '2027-03-10 20:30' ), 'venue' => array( (int) $venue['term_id'] ), 'category' => array( $child['term_id'] ) )
	);
	set_post_thumbnail( $gala, $image );
	eo_insert_event( array( 'post_title' => 'Rig EO Fair', 'post_status' => 'publish' ), array( 'start' => $at( '2027-05-01 00:00' ), 'end' => $at( '2027-05-01 23:59' ), 'all_day' => 1 ) );
	eo_insert_event( array( 'post_title' => 'Rig EO Weekend', 'post_status' => 'publish' ), array( 'start' => $at( '2027-06-05 09:00' ), 'end' => $at( '2027-06-07 17:00' ) ) );
	eo_insert_event(
		array( 'post_title' => 'Rig EO Weekly', 'post_content' => 'Every Monday.', 'post_status' => 'publish' ),
		array( 'start' => $at( '2027-04-05 10:00' ), 'end' => $at( '2027-04-05 11:00' ), 'schedule' => 'weekly', 'frequency' => 1, 'until' => $at( '2027-04-26 10:00' ), 'exclude' => array( $at( '2027-04-12 10:00' ) ), 'venue' => array( (int) $venue['term_id'] ) )
	);
	eo_insert_event( array( 'post_title' => 'Rig EO Past', 'post_status' => 'publish' ), array( 'start' => $at( '2025-01-10 19:00' ), 'end' => $at( '2025-01-10 21:00' ) ) );
	eo_insert_event( array( 'post_title' => 'Rig EO Draft', 'post_status' => 'draft' ), array( 'start' => $at( '2027-08-01 12:00' ), 'end' => $at( '2027-08-01 13:00' ) ) );
	$trash = eo_insert_event( array( 'post_title' => 'Rig EO Trashed', 'post_status' => 'publish' ), array( 'start' => $at( '2027-08-02 12:00' ), 'end' => $at( '2027-08-02 13:00' ) ) );
	wp_trash_post( $trash );
}
$weekly_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eo_events WHERE post_id = %d", $eo_post( 'Rig EO Weekly' ) ) );
$ok( 'EO saved three Mondays (one taken out)', 3 === $weekly_rows, (string) $weekly_rows );

// ---------------------------------------------------------------------
// Seed My Calendar through its own functions (once).
// ---------------------------------------------------------------------
$mc_event = function ( $title ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}my_calendar WHERE event_title = %s ORDER BY event_id ASC LIMIT 1", $title ), ARRAY_A );
};
if ( ! $mc_event( 'Rig MC Gala' ) ) {
	$keys = array( 'location_label', 'location_street', 'location_street2', 'location_city', 'location_state', 'location_postcode', 'location_region', 'location_country', 'location_url', 'location_longitude', 'location_latitude', 'location_zoom', 'location_phone', 'location_phone2' );
	$loc  = mc_insert_location( array_merge( array_fill_keys( $keys, '' ), array( 'location_label' => 'Rig MC Hall', 'location_street' => '2 Oak Ave', 'location_city' => 'Boulder', 'location_state' => 'CO', 'location_postcode' => '80302', 'location_country' => 'US', 'location_zoom' => 16 ) ) );
	$cat  = mc_create_category( array( 'category_name' => 'Rig MC Arts', 'category_color' => '#aa0000' ) );
	$base = array( 'event_nonce_name' => wp_create_nonce( 'event_nonce' ), 'event_group_id' => 0, 'event_approved' => 1, 'event_category' => array( $cat ) );
	$add  = function ( array $fields ) use ( $base ) {
		return mc_insert_event( 'add', array_merge( $base, $fields ), 0 );
	};
	$add( array( 'event_title' => 'Rig MC Gala', 'content' => '<p><em>Strings</em> and snacks.</p>', 'event_short' => 'Strings.', 'event_begin' => array( '2027-03-11' ), 'event_end' => array( '2027-03-11' ), 'event_time' => array( '19:00' ), 'event_endtime' => array( '21:00' ), 'location_preset' => $loc['location_id'] ?? $loc, 'event_link' => 'https://example.org/rig-mc-gala', 'event_image' => $image_url ) );
	$add( array( 'event_title' => "Rig MC Families' Fair", 'content' => 'All day.', 'event_begin' => array( '2027-05-02' ), 'event_end' => array( '2027-05-02' ), 'event_allday' => 1 ) );
	$add( array( 'event_title' => 'Rig MC Weekend', 'content' => 'Three days.', 'event_begin' => array( '2027-06-05' ), 'event_end' => array( '2027-06-07' ), 'event_time' => array( '09:00' ), 'event_endtime' => array( '17:00' ) ) );
	$weekly = $add( array( 'event_title' => 'Rig MC Weekly', 'content' => 'Every Monday.', 'event_begin' => array( '2027-04-05' ), 'event_end' => array( '2027-04-05' ), 'event_time' => array( '10:00' ), 'event_endtime' => array( '11:00' ), 'event_recur' => 'W', 'event_every' => 1, 'event_repeats' => '2027-04-26', 'location_preset' => $loc['location_id'] ?? $loc ) );
	$second = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}my_calendar_events WHERE occur_event_id = %d AND occur_begin LIKE %s", (int) $weekly['event_id'], '2027-04-12%' ), ARRAY_A );
	mc_delete_instance( (int) $second['occur_id'], (int) $weekly['event_id'], $second['occur_begin'], $second['occur_end'] );
	$add( array( 'event_title' => 'Rig MC Past', 'content' => 'Last year.', 'event_begin' => array( '2025-01-11' ), 'event_end' => array( '2025-01-11' ), 'event_time' => array( '19:00' ), 'event_endtime' => array( '20:00' ) ) );
	$add( array( 'event_title' => 'Rig MC Cancelled', 'content' => 'Off.', 'event_begin' => array( '2027-08-03' ), 'event_end' => array( '2027-08-03' ), 'event_time' => array( '12:00' ), 'event_endtime' => array( '13:00' ), 'event_approved' => 3 ) );
	$add( array( 'event_title' => 'Rig MC Trashed', 'content' => 'Gone.', 'event_begin' => array( '2027-08-04' ), 'event_end' => array( '2027-08-04' ), 'event_time' => array( '12:00' ), 'event_endtime' => array( '13:00' ), 'event_approved' => 2 ) );
}
$mc_gala = $mc_event( 'Rig MC Gala' );
$ok( 'MC saved the gala published with a location and a post', $mc_gala && 1 === (int) $mc_gala['event_approved'] && (int) $mc_gala['event_location'] > 0 && (int) $mc_gala['event_post'] > 0, $mc_gala );
$mc_weekly = $mc_event( 'Rig MC Weekly' );
$mc_dates  = $wpdb->get_col( $wpdb->prepare( "SELECT occur_id FROM {$wpdb->prefix}my_calendar_events WHERE occur_event_id = %d ORDER BY occur_id ASC", (int) $mc_weekly['event_id'] ) );
$ok( 'MC kept three Mondays after deleting one', 3 === count( $mc_dates ), $mc_dates );
$ok( 'MC saved the fair as all day', npmp_mc_all_day( $mc_event( "Rig MC Families' Fair" )['event_time'] ?? '', $mc_event( "Rig MC Families' Fair" )['event_endtime'] ?? '' ) );
$ok( 'MC saved cancelled as 3 and trashed as 2', 3 === (int) $mc_event( 'Rig MC Cancelled' )['event_approved'] && 2 === (int) $mc_event( 'Rig MC Trashed' )['event_approved'] );

// ---------------------------------------------------------------------
// Seed WP Event Manager through its own admin save (once).
// ---------------------------------------------------------------------
$wpem_post = function ( $title ) use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'event_listing' AND post_title = %s ORDER BY ID ASC LIMIT 1", $title ) );
};
$old_tz_setting = get_option( 'event_manager_timezone_setting' );
update_option( 'event_manager_timezone_setting', 'each_event' );
if ( ! $wpem_post( 'Rig WPEM Gala' ) ) {
	include_once WP_PLUGIN_DIR . '/wp-event-manager/admin/wp-event-manager-writepanels.php';
	$parent = wp_insert_term( 'Rig WPEM Community', 'event_listing_category', array( 'slug' => 'rig-wpem-community' ) );
	$child  = wp_insert_term( 'Rig WPEM Fundraisers', 'event_listing_category', array( 'slug' => 'rig-wpem-fundraisers', 'parent' => $parent['term_id'] ) );
	$venue  = wp_insert_post( array( 'post_type' => 'event_venue', 'post_status' => 'publish', 'post_title' => 'Rig WPEM Hall' ) );
	$save   = function ( $title, $status, array $fields, $content = '' ) {
		$_POST = array_merge( array( 'event_manager_nonce' => wp_create_nonce( 'save_meta_data' ), 'date_format' => 'Y-m-d', '_event_online' => 'no' ), $fields );
		$id    = wp_insert_post( array( 'post_type' => 'event_listing', 'post_status' => $status, 'post_title' => $title, 'post_content' => '' !== $content ? $content : $title . ' body.' ) );
		$_POST = array();
		return $id;
	};
	$gala = $save( 'Rig WPEM Gala', 'publish', array( '_event_start_date' => '2027-03-12', '_event_start_time' => '18:00', '_event_end_date' => '2027-03-12', '_event_end_time' => '20:30', '_event_location' => '3 Elm St, Golden', '_event_pincode' => '80401', '_registration' => 'https://example.org/rig-wpem-register' ), '<p><strong>Black tie.</strong></p>' );
	// The submit form's fields with no admin box: the venue link and the event's time zone.
	update_post_meta( $gala, '_event_venue_ids', array( $venue ) );
	update_post_meta( $gala, '_event_timezone', 'America/New_York' );
	wp_set_object_terms( $gala, array( (int) $child['term_id'] ), 'event_listing_category' );
	set_post_thumbnail( $gala, $image );
	$save( 'Rig WPEM Weekend', 'publish', array( '_event_start_date' => '2027-06-05', '_event_start_time' => '09:00', '_event_end_date' => '2027-06-07', '_event_end_time' => '17:00', '_event_location' => '4 Pine Rd, Denver' ) );
	$online = $save( 'Rig WPEM Online', 'publish', array( '_event_start_date' => '2027-05-03', '_event_start_time' => '12:00', '_event_end_date' => '2027-05-03', '_event_end_time' => '13:00', '_event_online' => 'yes', '_event_banner' => $image_url ) );
	delete_post_thumbnail( $online );
	$past = $save( 'Rig WPEM Past', 'publish', array( '_event_start_date' => '2025-01-12', '_event_start_time' => '19:00', '_event_end_date' => '2025-01-12', '_event_end_time' => '20:00', '_event_location' => '5 Main St' ) );
	wp_update_post( array( 'ID' => $past, 'post_status' => 'expired' ) );
	$save( 'Rig WPEM Cancelled', 'publish', array( '_event_start_date' => '2027-08-05', '_event_start_time' => '12:00', '_event_end_date' => '2027-08-05', '_event_end_time' => '13:00', '_cancelled' => '1' ) );
	$trash = $save( 'Rig WPEM Trashed', 'publish', array( '_event_start_date' => '2027-08-06', '_event_start_time' => '12:00', '_event_end_date' => '2027-08-06', '_event_end_time' => '13:00' ) );
	wp_trash_post( $trash );
}
$wg = $wpem_post( 'Rig WPEM Gala' );
$ok( 'WPEM saved date and time together', '2027-03-12 18:00:00' === get_post_meta( $wg, '_event_start_date', true ), get_post_meta( $wg, '_event_start_date', true ) );
$ok( 'WPEM saved the cancelled flag', 1 === (int) get_post_meta( $wpem_post( 'Rig WPEM Cancelled' ), '_cancelled', true ) );
$ok( 'WPEM past event is expired', 'expired' === get_post_status( $wpem_post( 'Rig WPEM Past' ) ) );
$ok( 'WPEM online event has the banner but no thumbnail', ! has_post_thumbnail( $wpem_post( 'Rig WPEM Online' ) ) && $image_url === get_post_meta( $wpem_post( 'Rig WPEM Online' ), '_event_banner', true ) );

// Settings: one WP Event Manager setting maps, the list length.
$old_per_page = get_option( 'event_manager_per_page' );
$old_limit    = get_option( 'npmp_calendar_list_limit', null );
update_option( 'event_manager_per_page', '25' );
delete_option( 'npmp_calendar_list_limit' );

// Delete settings the rig flips to see the step 4 warnings.
$mc_options = get_option( 'my_calendar_options' );
$wpem_del   = get_option( 'event_manager_delete_data_on_uninstall' );

// What the old plugins hold that isn't a rig seed (My Calendar's demo event, say).
$others = array(
	'event-organiser'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eo_events e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id WHERE p.post_title NOT LIKE 'Rig EO %' AND p.post_status IN ('publish','private','pending','draft','future')" ),
	'my-calendar'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}my_calendar_events o INNER JOIN {$wpdb->prefix}my_calendar e ON e.event_id = o.occur_event_id WHERE e.event_title NOT LIKE 'Rig MC %' AND e.event_approved <> 2 AND e.event_flagged = 0" ),
	'wp-event-manager' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'event_listing' AND post_title NOT LIKE 'Rig WPEM %' AND post_status NOT IN ('trash','auto-draft','inherit')" ),
);
$rig_dates = array( 'event-organiser' => 8, 'my-calendar' => 8, 'wp-event-manager' => 5 );

// ---------------------------------------------------------------------
// Counts and warnings on Switch Plugins before the import.
// ---------------------------------------------------------------------
echo "Counts\n";
$src = array();
foreach ( $slugs as $slug ) {
	$src[ $slug ] = $run_phase( 'source:' . $slug );
	$s            = $src[ $slug ];
	$ok( "$slug hub source registered with data", ! empty( $s['registered'] ) && ! empty( $s['has_data'] ), $s );
	$ok( "$slug data removal unavailable, with a reason", empty( $s['has_batch'] ) && '' !== $s['reason'], $s );
	$ok( "$slug counts {$rig_dates[ $slug ]} rig dates", $rig_dates[ $slug ] + $others[ $slug ] === (int) ( $s['counts']['dates'] ?? -1 ), $s['counts'] ?? $s );
	$ok( "$slug counts one skipped event", 1 === (int) ( $s['counts']['skipped'] ?? -1 ) || ( 'my-calendar' === $slug && 1 <= (int) ( $s['counts']['skipped'] ?? -1 ) ), $s['counts'] ?? $s );
	$ok( "$slug hub screen has a venue row", in_array( 'Venues', array_column( (array) ( $s['rows'] ?? array() ), 'label' ), true ), $s['rows'] ?? $s );
	$ok( "$slug never warns at step 3", '' === $s['deactivate_note'], $s['deactivate_note'] );
	$ok( "$slug installed list is the plugin alone", array( $plugin_files[ $slug ] ) === $s['installed'], $s['installed'] );
}
$ok( 'EO counts 6 events (7 seeded, 1 trashed)', 6 + (int) $wpdb->get_var( "SELECT COUNT(DISTINCT e.post_id) FROM {$wpdb->prefix}eo_events e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id WHERE p.post_title NOT LIKE 'Rig EO %' AND p.post_status IN ('publish','private','pending','draft','future')" ) === (int) $src['event-organiser']['counts']['events'], $src['event-organiser']['counts'] );
$ok( 'EO warns at step 4 (its uninstall always deletes)', '' !== $src['event-organiser']['delete_note'] );
$ok( 'EO step 3 shows the warning early', false !== strpos( $src['event-organiser']['step3'], 'no setting to keep its data' ), $src['event-organiser']['step3'] );
$ok( 'EO step 3 asks for no setting change (it has none)', false === strpos( $src['event-organiser']['step3'], 'Change that setting' ), $src['event-organiser']['step3'] );
$ok( 'MC no step 4 warning with its drop setting off', '' === $src['my-calendar']['delete_note'], $src['my-calendar']['delete_note'] );
$ok( 'WPEM no step 4 warning with its delete setting off', '' === $src['wp-event-manager']['delete_note'], $src['wp-event-manager']['delete_note'] );
$ok( 'WPEM offers the list length, ticked', isset( $src['wp-event-manager']['settings']['list_limit'] ) && 25 === $src['wp-event-manager']['settings']['list_limit']['value'] && true === $src['wp-event-manager']['settings']['list_limit']['checked'], $src['wp-event-manager']['settings'] );
$ok( 'EO and MC offer no settings', array() === $src['event-organiser']['settings'] && array() === $src['my-calendar']['settings'] );

$mc_flip = is_array( $mc_options ) ? $mc_options : array();
$mc_flip['drop_tables'] = 'true';
update_option( 'my_calendar_options', $mc_flip );
update_option( 'event_manager_delete_data_on_uninstall', '1' );
$mc_src    = $run_phase( 'source:my-calendar' );
$mc_note   = $mc_src['delete_note'] ?? '';
$wpem_note = $run_phase( 'source:wp-event-manager' )['delete_note'] ?? '';
$ok( 'MC warns at step 4 once its drop setting is on', false !== strpos( $mc_note, 'Drop database tables on uninstall' ), $mc_note );
$ok( 'WPEM warns at step 4 once its delete setting is on', false !== strpos( $wpem_note, 'Delete Data On Uninstall' ), $wpem_note );
$ok( 'MC step 3 asks you to change its drop setting first', false !== strpos( $mc_src['step3'] ?? '', 'Change that setting' ), $mc_src['step3'] ?? '' );
update_option( 'my_calendar_options', $mc_options );
update_option( 'event_manager_delete_data_on_uninstall', $wpem_del );

update_option( 'npmp_calendar_list_limit', 15 );
$owner = $run_phase( 'source:wp-event-manager' )['settings']['list_limit'] ?? array();
$ok( 'WPEM list length unticked once you changed yours', isset( $owner['checked'] ) && false === $owner['checked'], $owner );
delete_option( 'npmp_calendar_list_limit' );

// ---------------------------------------------------------------------
// Import.
// ---------------------------------------------------------------------
echo "Event Organiser import\n";
$r = $run_phase( 'import:event-organiser' );
$ok( 'EO import finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'EO created 8 rig dates', 8 + $others['event-organiser'] === (int) ( $r['state']['results']['created'] ?? -1 ), $r['state']['results'] ?? $r );
$ok( 'EO nothing invalid', 0 === (int) ( $r['state']['results']['invalid'] ?? -1 ), $r['state']['results'] ?? '' );
$g = $find( 'event-organiser', 'Rig EO Gala' );
$ok( 'EO gala came over once', 1 === count( $g ) );
if ( $g ) {
	$id = $g[0]->ID;
	$ok( 'EO gala starts 6 pm Denver', '2027-03-10 18:00:00' === $meta( $id, '_npmp_event_start' ), $meta( $id, '_npmp_event_start' ) );
	$ok( 'EO gala ends 8:30 pm', '2027-03-10 20:30:00' === $meta( $id, '_npmp_event_end' ), $meta( $id, '_npmp_event_end' ) );
	$ok( 'EO gala venue line', 'Rig EO Hall, 1 Main St, Denver, CO 80202, US' === $meta( $id, '_npmp_event_location' ), $meta( $id, '_npmp_event_location' ) );
	$ok( 'EO gala keeps its formatting', false !== strpos( $g[0]->post_content, '<strong>Black tie.</strong>' ), $g[0]->post_content );
	$ok( 'EO gala image', $image === (int) get_post_thumbnail_id( $id ) );
	$terms = wp_get_object_terms( $id, 'npmp_event_category' );
	$ok( 'EO gala category came over with its parent', 1 === count( $terms ) && 'rig-eo-fundraisers' === $terms[0]->slug && 'rig-eo-community' === get_term( $terms[0]->parent )->slug, wp_list_pluck( $terms, 'slug' ) );
	$ok( 'EO gala old link stored', array( '/events/event/rig-eo-gala' ) === get_post_meta( $id, '_npmp_import_old_path' ), get_post_meta( $id, '_npmp_import_old_path' ) );
	$ok( 'EO gala published', 'publish' === $g[0]->post_status );
}
$f = $find( 'event-organiser', 'Rig EO Fair' );
$ok( 'EO fair all day on May 1', 1 === count( $f ) && '1' === $meta( $f[0]->ID, '_npmp_event_all_day' ) && '2027-05-01 00:00:00' === $meta( $f[0]->ID, '_npmp_event_start' ) && 0 === strpos( $meta( $f[0]->ID, '_npmp_event_end' ), '2027-05-01 23:59' ) );
$w = $find( 'event-organiser', 'Rig EO Weekend' );
$ok( 'EO weekend runs three days', 1 === count( $w ) && '2027-06-05 09:00:00' === $meta( $w[0]->ID, '_npmp_event_start' ) && '2027-06-07 17:00:00' === $meta( $w[0]->ID, '_npmp_event_end' ) );
$s = $find( 'event-organiser', 'Rig EO Weekly' );
$starts = array_map( function ( $p ) use ( $meta ) {
	return $meta( $p->ID, '_npmp_event_start' );
}, $s );
sort( $starts );
$ok( 'EO weekly came over as the three Mondays left', array( '2027-04-05 10:00:00', '2027-04-19 10:00:00', '2027-04-26 10:00:00' ) === $starts, $starts );
$with_path = array_values( array_filter( $s, function ( $p ) {
	return (bool) get_post_meta( $p->ID, '_npmp_import_old_path', true );
} ) );
$ok( 'EO only the first Monday holds the link', 1 === count( $with_path ) && '2027-04-05 10:00:00' === $meta( $with_path[0]->ID, '_npmp_event_start' ) );
$ok( 'EO weekly dates have the venue and body', $s && 0 === strpos( $meta( end( $s )->ID, '_npmp_event_location' ), 'Rig EO Hall' ) && 'Every Monday.' === trim( end( $s )->post_content ) );
$p = $find( 'event-organiser', 'Rig EO Past' );
$ok( 'EO past event came over published', 1 === count( $p ) && 'publish' === $p[0]->post_status && '2025-01-10 19:00:00' === $meta( $p[0]->ID, '_npmp_event_start' ) );
$d = $find( 'event-organiser', 'Rig EO Draft' );
$ok( 'EO draft came over as a draft with no old link', 1 === count( $d ) && 'draft' === $d[0]->post_status && ! get_post_meta( $d[0]->ID, '_npmp_import_old_path', true ) );
$ok( 'EO trashed event stayed behind', 0 === count( $find( 'event-organiser', 'Rig EO Trashed' ) ) );
$eo_gala = $g;

echo "My Calendar import\n";
$r = $run_phase( 'import:my-calendar' );
$ok( 'MC import finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'MC created 8 rig dates', 8 + $others['my-calendar'] === (int) ( $r['state']['results']['created'] ?? -1 ), $r['state']['results'] ?? $r );
$g = $find( 'my-calendar', 'Rig MC Gala' );
$ok( 'MC gala came over once', 1 === count( $g ) );
if ( $g ) {
	$id = $g[0]->ID;
	$ok( 'MC gala starts 7 pm Denver', '2027-03-11 19:00:00' === $meta( $id, '_npmp_event_start' ), $meta( $id, '_npmp_event_start' ) );
	$ok( 'MC gala ends 9 pm', '2027-03-11 21:00:00' === $meta( $id, '_npmp_event_end' ), $meta( $id, '_npmp_event_end' ) );
	$ok( 'MC gala location line', 'Rig MC Hall, 2 Oak Ave, Boulder, CO 80302, US' === $meta( $id, '_npmp_event_location' ), $meta( $id, '_npmp_event_location' ) );
	$ok( 'MC gala event link', 'https://example.org/rig-mc-gala' === $meta( $id, '_npmp_event_url' ), $meta( $id, '_npmp_event_url' ) );
	$ok( 'MC gala keeps its formatting and excerpt', false !== strpos( $g[0]->post_content, '<em>Strings</em>' ) && 'Strings.' === $g[0]->post_excerpt, $g[0]->post_content );
	$ok( 'MC gala image', $image === (int) get_post_thumbnail_id( $id ), (string) get_post_thumbnail_id( $id ) );
	$ok( 'MC gala category', array( 'rig-mc-arts' ) === wp_get_object_terms( $id, 'npmp_event_category', array( 'fields' => 'slugs' ) ), wp_get_object_terms( $id, 'npmp_event_category', array( 'fields' => 'slugs' ) ) );
	$paths = get_post_meta( $id, '_npmp_import_old_path' );
	sort( $paths );
	$ok( 'MC gala old links stored', 2 === count( $paths ) && '/mc-events/rig-mc-gala' === $paths[0] && 0 === strpos( $paths[1], '/mc-events/rig-mc-gala/?mc_id=' ), $paths );
}
$f = $find( 'my-calendar', "Rig MC Families' Fair" );
$ok( 'MC fair keeps its apostrophe and is all day', 1 === count( $f ) && '1' === $meta( $f[0]->ID, '_npmp_event_all_day' ) && '2027-05-02 00:00:00' === $meta( $f[0]->ID, '_npmp_event_start' ), $f ? $f[0]->post_title : 'missing' );
$w = $find( 'my-calendar', 'Rig MC Weekend' );
$ok( 'MC weekend runs three days', 1 === count( $w ) && '2027-06-05 09:00:00' === $meta( $w[0]->ID, '_npmp_event_start' ) && '2027-06-07 17:00:00' === $meta( $w[0]->ID, '_npmp_event_end' ) );
$s = $find( 'my-calendar', 'Rig MC Weekly' );
$starts = array_map( function ( $p ) use ( $meta ) {
	return $meta( $p->ID, '_npmp_event_start' );
}, $s );
sort( $starts );
$ok( 'MC weekly came over as the three Mondays left', array( '2027-04-05 10:00:00', '2027-04-19 10:00:00', '2027-04-26 10:00:00' ) === $starts, $starts );
$mc_last = null;
foreach ( $s as $one ) {
	if ( '2027-04-26 10:00:00' === $meta( $one->ID, '_npmp_event_start' ) ) {
		$mc_last = $one;
	}
}
$ok( 'MC last Monday holds its own ?mc_id link', $mc_last && array( '/mc-events/rig-mc-weekly/?mc_id=' . end( $mc_dates ) ) === get_post_meta( $mc_last->ID, '_npmp_import_old_path' ), $mc_last ? get_post_meta( $mc_last->ID, '_npmp_import_old_path' ) : 'missing' );
$c = $find( 'my-calendar', 'Rig MC Cancelled' );
$ok( 'MC cancelled event came over as a draft', 1 === count( $c ) && 'draft' === $c[0]->post_status, $c ? $c[0]->post_status : 'missing' );
$ok( 'MC trashed event stayed behind', 0 === count( $find( 'my-calendar', 'Rig MC Trashed' ) ) );
$ok( 'MC past event came over', 1 === count( $find( 'my-calendar', 'Rig MC Past' ) ) );
$mc_gala_new = $g;

echo "WP Event Manager import\n";
$r = $run_phase( 'import:wp-event-manager' );
$ok( 'WPEM import finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'WPEM created 5 rig events', 5 + $others['wp-event-manager'] === (int) ( $r['state']['results']['created'] ?? -1 ), $r['state']['results'] ?? $r );
$ok( 'WPEM copied the ticked list length', 1 === (int) ( $r['state']['results']['settings'] ?? -1 ) && 25 === (int) $fresh( 'npmp_calendar_list_limit' ), $fresh( 'npmp_calendar_list_limit' ) );
$g = $find( 'wp-event-manager', 'Rig WPEM Gala' );
if ( $g ) {
	$id = $g[0]->ID;
	$ok( 'WPEM gala 6 pm New York is 4 pm Denver', '2027-03-12 16:00:00' === $meta( $id, '_npmp_event_start' ), $meta( $id, '_npmp_event_start' ) );
	$ok( 'WPEM gala ends 6:30 pm Denver', '2027-03-12 18:30:00' === $meta( $id, '_npmp_event_end' ), $meta( $id, '_npmp_event_end' ) );
	$ok( 'WPEM gala venue and address', 'Rig WPEM Hall, 3 Elm St, Golden, 80401' === $meta( $id, '_npmp_event_location' ), $meta( $id, '_npmp_event_location' ) );
	$ok( 'WPEM gala image', $image === (int) get_post_thumbnail_id( $id ) );
	$ok( 'WPEM gala keeps its formatting', false !== strpos( $g[0]->post_content, '<strong>Black tie.</strong>' ) );
	$ok( 'WPEM gala registration link stays behind', '' === $meta( $id, '_npmp_event_url' ) );
	$terms = wp_get_object_terms( $id, 'npmp_event_category' );
	$ok( 'WPEM gala category came over with its parent', 1 === count( $terms ) && 'rig-wpem-fundraisers' === $terms[0]->slug && 'rig-wpem-community' === get_term( $terms[0]->parent )->slug, wp_list_pluck( $terms, 'slug' ) );
	$ok( 'WPEM gala old link stored', array( '/event/rig-wpem-gala' ) === get_post_meta( $id, '_npmp_import_old_path' ), get_post_meta( $id, '_npmp_import_old_path' ) );
} else {
	$ok( 'WPEM gala came over', false );
}
$w = $find( 'wp-event-manager', 'Rig WPEM Weekend' );
$ok( 'WPEM weekend runs three days', 1 === count( $w ) && '2027-06-05 09:00:00' === $meta( $w[0]->ID, '_npmp_event_start' ) && '2027-06-07 17:00:00' === $meta( $w[0]->ID, '_npmp_event_end' ) );
$o = $find( 'wp-event-manager', 'Rig WPEM Online' );
$ok( 'WPEM online event: no location, image from its banner', 1 === count( $o ) && '' === $meta( $o[0]->ID, '_npmp_event_location' ) && $image === (int) get_post_thumbnail_id( $o[0]->ID ) );
$p = $find( 'wp-event-manager', 'Rig WPEM Past' );
$ok( 'WPEM expired event came over published with its old link', 1 === count( $p ) && 'publish' === $p[0]->post_status && '/event/rig-wpem-past' === $meta( $p[0]->ID, '_npmp_import_old_path' ) );
$c = $find( 'wp-event-manager', 'Rig WPEM Cancelled' );
$ok( 'WPEM cancelled event came over as a draft', 1 === count( $c ) && 'draft' === $c[0]->post_status );
$ok( 'WPEM trashed event stayed behind', 0 === count( $find( 'wp-event-manager', 'Rig WPEM Trashed' ) ) );
$wpem_gala_new = $g;

echo "Second run\n";
foreach ( $slugs as $slug ) {
	$r = $run_phase( 'import:' . $slug . ':' );
	$ok( "$slug rerun creates nothing", 0 === (int) ( $r['state']['results']['created'] ?? -1 ) && $rig_dates[ $slug ] + $others[ $slug ] === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );
}
update_option( 'npmp_calendar_list_limit', 30 );
$r = $run_phase( 'import:wp-event-manager:' );
$ok( 'WPEM rerun with the box unticked leaves your list length alone', 0 === (int) ( $r['state']['results']['settings'] ?? -1 ) && 30 === (int) $fresh( 'npmp_calendar_list_limit' ), $fresh( 'npmp_calendar_list_limit' ) );

// ---------------------------------------------------------------------
// Switch Plugins: refusals, verify, turn off, then old links over HTTP.
// ---------------------------------------------------------------------
echo "Switch Plugins\n";
foreach ( $slugs as $slug ) {
	$h = $run_phase( 'hub:' . $slug );
	$ok( "$slug step 5 refused before the steps above it", 'npmp_hub_order' === ( $h['early'] ?? '' ), $h );
	$ok( "$slug verify refused without the box", 'npmp_hub_unchecked' === ( $h['unticked'] ?? '' ), $h );
	$ok( "$slug verified", is_array( $h['verify'] ?? null ), $h );
	$ok( "$slug turned off", isset( $h['active'] ) && false === $h['active'], $h );
	$ok( "$slug turning off cleared the cached rewrite rules", empty( $h['rules'] ), is_array( $h['rules'] ?? null ) ? count( $h['rules'] ) . ' rules' : '' );
}
$ok( 'EO dates still in the database', 8 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eo_events" ) );
$ok( 'MC events still in the database', 7 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}my_calendar WHERE event_title LIKE 'Rig MC %'" ) );

echo "Old links over HTTP\n";
$lands = function ( $old, $post_id ) use ( $get ) {
	$hit = $get( home_url( $old ) );
	return array( '301 ' . get_permalink( $post_id ) === $hit, $hit . ' (new ' . get_permalink( $post_id ) . ')' );
};
$checks = array();
if ( $eo_gala ) {
	$checks['old EO gala link'] = array( '/events/event/rig-eo-gala/', $eo_gala[0]->ID );
}
if ( $with_path ) {
	$checks['old EO weekly link reaches its first Monday'] = array( '/events/event/rig-eo-weekly/', $with_path[0]->ID );
}
if ( $mc_gala_new ) {
	$checks['old MC gala link'] = array( '/mc-events/rig-mc-gala/', $mc_gala_new[0]->ID );
}
if ( $mc_last ) {
	$checks['old MC link to one Monday reaches that Monday'] = array( '/mc-events/rig-mc-weekly/?mc_id=' . end( $mc_dates ), $mc_last->ID );
}
if ( $wpem_gala_new ) {
	$checks['old WPEM gala link'] = array( '/event/rig-wpem-gala/', $wpem_gala_new[0]->ID );
}
foreach ( $checks as $label => $check ) {
	list( $pass, $why ) = $lands( $check[0], $check[1] );
	$ok( $label, $pass, $why );
}
foreach ( array( '/events/event/rig-not-real/', '/mc-events/rig-not-real/', '/event/rig-not-real/' ) as $bad ) {
	$hit = $get( home_url( $bad ) );
	$ok( "unknown $bad still 404s", '404' === substr( $hit, 0, 3 ), $hit );
}
$hit = $get( home_url( '/events/event/rig-eo-draft/' ) );
$ok( 'a draft has no redirect', '404' === substr( $hit, 0, 3 ), $hit );

echo "Import with the plugins off\n";
foreach ( $slugs as $slug ) {
	$r = $run_phase( 'import:' . $slug . ':' );
	$ok( "$slug import reads with the plugin off", false === ( $r['active'] ?? true ) && $rig_dates[ $slug ] + $others[ $slug ] === (int) ( $r['state']['results']['exists'] ?? -1 ), $r['state']['results'] ?? $r );
}

// Put back what the rig changed.
update_option( 'event_manager_timezone_setting', $old_tz_setting );
update_option( 'event_manager_per_page', $old_per_page );
if ( null === $old_limit ) {
	delete_option( 'npmp_calendar_list_limit' );
} else {
	update_option( 'npmp_calendar_list_limit', $old_limit );
}

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
