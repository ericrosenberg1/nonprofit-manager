<?php
/**
 * Real-WordPress check of The Events Calendar importer against the real TEC:
 * seed events through TEC's own API, stage them, uncheck two, migrate, check
 * meta and terms, run again with no duplicates, deactivate TEC and its
 * add-ons, confirm TEC's data survived, and follow the old URLs over HTTP.
 *
 * Needs a throwaway WordPress with this plugin and The Events Calendar active,
 * and pretty permalinks. It follows old URLs at home_url, and starts php -S
 * there when nothing answers yet. The expected times assume London time, so
 * it sets that and puts the timezone and modules back at the end:
 *   wp plugin install the-events-calendar --activate
 *   wp rewrite structure '/%postname%/'
 *   wp eval-file tests/rig/tec-importer.php
 *
 * It turns TEC off at the end. Turn it back on before running it again.
 */

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . $detail . ']' : '' );
};

if ( ! function_exists( 'tribe_create_event' ) ) {
	echo "The Events Calendar isn't active. Run: wp plugin activate the-events-calendar\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
// Every expected local time below is London time. Put back what the site had.
$rig_saved = array();
foreach ( array( 'timezone_string', 'gmt_offset', 'npmp_enabled_features', 'npmp_calendar_default_view' ) as $rig_opt ) {
	$rig_saved[ $rig_opt ] = get_option( $rig_opt, null );
}
register_shutdown_function(
	static function () use ( $rig_saved ) {
		foreach ( $rig_saved as $rig_opt => $rig_val ) {
			null === $rig_val ? delete_option( $rig_opt ) : update_option( $rig_opt, $rig_val );
		}
	}
);
update_option( 'timezone_string', 'Europe/London' );
update_option( 'gmt_offset', '' );
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

global $wpdb, $wp_rewrite;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

echo "Clean slate\n";
foreach ( array( 'npmp_event', 'tribe_events', 'tribe_venue', 'tribe_organizer' ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $pid ) {
		wp_delete_post( $pid, true );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}tec_occurrences" );
foreach ( array( 'tribe_events_cat', 'npmp_event_category' ) as $tax ) {
	if ( taxonomy_exists( $tax ) ) {
		foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ) ) as $tid ) {
			wp_delete_term( $tid, $tax );
		}
	}
}
// Leftover npmp terms when the calendar module is off and the taxonomy isn't registered.
$wpdb->query( "DELETE t, tt FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'npmp_event_category'" );
npmp_tec_reset_stage();
delete_option( 'npmp_tec_imported' );
$cal_was_loaded = post_type_exists( 'npmp_event' );
delete_option( 'npmp_enabled_features' );
$ok( 'calendar module option starts off', ! get_option( 'npmp_enabled_features' ) );
if ( $cal_was_loaded ) {
	echo "  note  the calendar loaded at boot from an earlier run, so mid-request registration isn't covered this time. Run `wp option delete npmp_enabled_features` first to cover it.\n";
}

// TEC reads the permalink setting when it loads, so set it before this runs.
$ok( 'pretty permalinks on (wp rewrite structure /%postname%/)', '' !== (string) get_option( 'permalink_structure' ) );
if ( '' === (string) get_option( 'permalink_structure' ) ) {
	exit( 1 );
}
flush_rewrite_rules();

// Two add-ons that need TEC: one declares it, one only carries TEC's name.
$plugins_dir = WP_PLUGIN_DIR;
$fakes       = array(
	'the-events-calendar-shortcode/the-events-calendar-shortcode.php' => "<?php\n/**\n * Plugin Name: The Events Calendar Shortcode & Templates\n * Version: 1.0\n */\n",
	'npmp-rig-tec-addon/npmp-rig-tec-addon.php'                       => "<?php\n/**\n * Plugin Name: Rig TEC Add-on\n * Version: 1.0\n * Requires Plugins: the-events-calendar\n */\n",
);
// The first fake sits at a real wordpress.org plugin's path, and the end of the
// run deletes both, so refuse to run beside a real copy of either.
foreach ( $fakes as $rel => $code ) {
	$fake_dir = dirname( $plugins_dir . '/' . $rel );
	if ( file_exists( $fake_dir ) && ! ( is_file( $plugins_dir . '/' . $rel ) && file_get_contents( $plugins_dir . '/' . $rel ) === $code && array( basename( $rel ) ) === array_values( array_diff( (array) scandir( $fake_dir ), array( '.', '..' ) ) ) ) ) {
		echo "Refusing: $fake_dir is already there, and this rig writes a stub plugin over it and then deletes it. Use a site without that plugin.\n";
		exit( 1 );
	}
}
foreach ( $fakes as $rel => $code ) {
	wp_mkdir_p( dirname( $plugins_dir . '/' . $rel ) );
	file_put_contents( $plugins_dir . '/' . $rel, $code );
}
wp_clean_plugins_cache( false );
foreach ( array_keys( $fakes ) as $rel ) {
	$r = activate_plugin( $rel );
	$ok( "activated $rel", ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
}

echo "Seed TEC events through TEC's API\n";
$cat_meet = wp_insert_term( 'Meetings', 'tribe_events_cat', array( 'slug' => 'meetings' ) );
$cat_fund = wp_insert_term( 'Fundraisers', 'tribe_events_cat', array( 'slug' => 'fundraisers' ) );
$cat_gala = wp_insert_term( 'Galas', 'tribe_events_cat', array( 'slug' => 'galas', 'parent' => $cat_fund['term_id'] ) );

$venue = tribe_create_venue( array( 'Venue' => 'Ventura Hall', 'Address' => '1 Main St', 'City' => 'Ventura', 'State' => 'CA', 'Zip' => '93001', 'Country' => 'United States' ) );
$park  = tribe_create_venue( array( 'Venue' => 'Arroyo Verde Park', 'City' => 'Ventura', 'State' => 'CA' ) );

$make = function ( $title, $args, $cats ) {
	$id = tribe_create_event(
		array_merge(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => "<!-- wp:tribe/event-datetime /-->\n<!-- wp:paragraph --><p>About $title.</p><!-- /wp:paragraph -->",
				'post_excerpt' => "Short $title.",
			),
			$args
		)
	);
	if ( $id && $cats ) {
		wp_set_object_terms( $id, $cats, 'tribe_events_cat' );
	}
	return $id;
};
$times = function ( $day, $sh, $sm, $eh, $em ) {
	return array( 'EventStartDate' => $day, 'EventEndDate' => $day, 'EventStartHour' => $sh, 'EventStartMinute' => $sm, 'EventEndHour' => $eh, 'EventEndMinute' => $em );
};

$e_meeting = $make( 'November Board Meeting', $times( '2026-11-18', '19', '00', '20', '30' ) + array( 'EventTimezone' => 'Europe/London', 'Venue' => array( 'VenueID' => $venue ), 'Organizer' => array( 'Organizer' => 'CACC Board' ) ), array( 'meetings' ) );
$e_gala    = $make( 'Spring Gala', $times( '2027-04-10', '18', '30', '22', '00' ) + array( 'EventTimezone' => 'America/New_York', 'Venue' => array( 'VenueID' => $venue ), 'EventURL' => 'https://example.org/gala-tickets' ), array( 'galas' ) );
$e_allday  = $make( 'Park Cleanup Day', array( 'EventStartDate' => '2026-12-05', 'EventEndDate' => '2026-12-05', 'EventAllDay' => 'yes', 'EventTimezone' => 'America/Los_Angeles', 'Venue' => array( 'VenueID' => $park ) ), array( 'fundraisers' ) );
$e_india   = $make( 'Partner Call', $times( '2026-12-10', '09', '00', '10', '00' ) + array( 'EventTimezone' => 'UTC+5.5' ), array( 'meetings' ) );
$e_skip1   = $make( 'Old Picnic', $times( '2026-11-20', '12', '00', '14', '00' ) + array( 'EventTimezone' => 'Europe/London', 'Venue' => array( 'VenueID' => $park ) ), array() );
$e_skip2   = $make( 'Cancelled Social', $times( '2026-11-25', '18', '00', '20', '00' ) + array( 'EventTimezone' => 'Europe/London' ), array( 'meetings' ) );
$e_draft   = $make( 'Draft Planning Session', $times( '2027-01-15', '17', '00', '18', '00' ) + array( 'EventTimezone' => 'Europe/London', 'post_status' => 'draft' ), array() );
$e_yoga    = $make( 'Weekly Yoga', $times( '2026-11-05', '08', '00', '09', '00' ) + array( 'EventTimezone' => 'Europe/London', 'Venue' => array( 'VenueID' => $park ) ), array( 'meetings' ) );

$all_tec = array( $e_meeting, $e_gala, $e_allday, $e_india, $e_skip1, $e_skip2, $e_draft, $e_yoga );
$ok( 'eight TEC events created', 8 === count( array_filter( $all_tec ) ) );
$ok( 'gala stored in New York time', 'America/New_York' === get_post_meta( $e_gala, '_EventTimezone', true ) && '2027-04-10 22:30:00' === get_post_meta( $e_gala, '_EventStartDateUTC', true ), get_post_meta( $e_gala, '_EventStartDateUTC', true ) );
$ok( 'cleanup day is all day', npmp_tec_is_all_day( get_post_meta( $e_allday, '_EventAllDay', true ) ) );

// A real image on the gala.
$tmp = wp_tempnam( 'gala.png' );
file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
$att = media_handle_sideload( array( 'name' => 'gala.png', 'tmp_name' => $tmp ), $e_gala );
set_post_thumbnail( $e_gala, $att );

// TEC Pro isn't on wp.org, so fake a three-date series the way Pro stores it:
// rows in wp_tec_occurrences for the one post.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d", $e_yoga ) );
foreach ( array( '2026-11-05', '2026-11-12', '2026-11-19' ) as $day ) {
	$wpdb->insert(
		$wpdb->prefix . 'tec_occurrences',
		array(
			'event_id'       => 0,
			'post_id'        => $e_yoga,
			'start_date'     => "$day 08:00:00",
			'start_date_utc' => "$day 08:00:00",
			'end_date'       => "$day 09:00:00",
			'end_date_utc'   => "$day 09:00:00",
			'duration'       => 3600,
			'hash'           => sha1( "rig-$e_yoga-$day" ),
		)
	);
}

$old_gala_url = get_permalink( $e_gala );
$old_yoga_url = get_permalink( $e_yoga );
$ok( 'TEC permalinks are pretty', false !== strpos( $old_gala_url, '/event/spring-gala/' ), $old_gala_url );

echo "Step 1: stage in batches of three\n";
$captured = array();
add_action(
	'npmp_tec_event_imported',
	function ( $new_id, $tec_id, $slugs, $options ) use ( &$captured ) {
		$captured[ $tec_id ][] = array( $new_id, $slugs, $options );
	},
	10,
	4
);

$rounds = 0;
$res    = npmp_tec_stage_batch( true, 3 );
while ( ! $res['done'] && $rounds++ < 20 ) {
	$res = npmp_tec_stage_batch( false, 3 );
}
$stage = npmp_tec_get_stage();
$ok( 'read all eight posts', 8 === $res['read'] && 8 === $res['total'], wp_json_encode( $res ) );
$ok( 'ten items: seven events plus three yoga dates', 10 === count( $stage['items'] ), (string) count( $stage['items'] ) );
$gala_item = $stage['items'][ (string) $e_gala ];
$ok( 'gala start in London time', '2027-04-10 23:30:00' === $gala_item['start'], $gala_item['start'] );
$ok( 'gala venue line', 'Ventura Hall, 1 Main St, Ventura, CA 93001, United States' === $gala_item['location'], $gala_item['location'] );
$ok( 'gala old path', array( '/event/spring-gala' ) === $gala_item['paths'], wp_json_encode( $gala_item['paths'] ) );
$ok( 'draft has no old path', array() === $stage['items'][ (string) $e_draft ]['paths'] );
$yoga_keys = array_values( array_filter( array_keys( $stage['items'] ), function ( $k ) use ( $e_yoga ) { return 0 === strpos( (string) $k, $e_yoga . '@' ); } ) );
$ok( 'three yoga occurrences staged', 3 === count( $yoga_keys ) );
$first_yoga = $stage['items'][ $yoga_keys[0] ];
$ok( 'first yoga date carries the series path too', in_array( '/event/weekly-yoga', $first_yoga['paths'], true ) && in_array( '/event/weekly-yoga/2026-11-05', $first_yoga['paths'], true ), wp_json_encode( $first_yoga['paths'] ) );

echo "Steps 2 and 3: uncheck two, approve\n";
npmp_tec_set_skip( array( (string) $e_skip1, (string) $e_skip2 ) );
$queued = npmp_tec_approve( array( 'categories_to_groups' => '1' ) );
$ok( 'eight queued (ten minus two unchecked)', 8 === $queued, (string) $queued );

echo "Step 4: migrate in batches of three\n";
$rounds = 0;
do {
	$res = npmp_tec_migrate_batch( 3 );
} while ( ! $res['done'] && $rounds++ < 20 );
$stage = npmp_tec_get_stage();
$ok( 'eight created, nothing failed', 8 === $stage['results']['created'] && ! $stage['results']['failed'], wp_json_encode( $stage['results'] ) );
$features = get_option( 'npmp_enabled_features' );
$ok( 'calendar module turned on', ! empty( $features['calendar'] ) && post_type_exists( 'npmp_event' ) && taxonomy_exists( 'npmp_event_category' ) );

$by_source = function ( $tec_id ) {
	return get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_npmp_tec_source_id', 'meta_value' => $tec_id ) );
};
$ok( 'unchecked events stayed behind', ! $by_source( $e_skip1 ) && ! $by_source( $e_skip2 ) );

$new_gala = $by_source( $e_gala )[0] ?? 0;
$gp       = get_post( $new_gala );
$ok( 'gala: title and status', $gp && 'Spring Gala' === $gp->post_title && 'publish' === $gp->post_status );
$ok( 'gala: TEC block stripped, paragraph kept', $gp && false === strpos( $gp->post_content, 'wp:tribe' ) && false !== strpos( $gp->post_content, 'About Spring Gala.' ) );
$ok( 'gala: excerpt', $gp && 'Short Spring Gala.' === $gp->post_excerpt );
$ok( 'gala: start 23:30 London', '2027-04-10 23:30:00' === get_post_meta( $new_gala, '_npmp_event_start', true ) );
$ok( 'gala: end 03:00 London next day', '2027-04-11 03:00:00' === get_post_meta( $new_gala, '_npmp_event_end', true ), get_post_meta( $new_gala, '_npmp_event_end', true ) );
$ok( 'gala: location', 'Ventura Hall, 1 Main St, Ventura, CA 93001, United States' === get_post_meta( $new_gala, '_npmp_event_location', true ) );
$ok( 'gala: event website', 'https://example.org/gala-tickets' === get_post_meta( $new_gala, '_npmp_event_url', true ) );
$ok( 'gala: featured image', (int) $att === (int) get_post_thumbnail_id( $new_gala ) );
$gala_terms = wp_get_object_terms( $new_gala, 'npmp_event_category' );
$ok( 'gala: Galas term under Fundraisers', 1 === count( $gala_terms ) && 'galas' === $gala_terms[0]->slug && 'fundraisers' === get_term( $gala_terms[0]->parent )->slug );
$ok( 'gala: source id and old path', (int) $e_gala === (int) get_post_meta( $new_gala, '_npmp_tec_source_id', true ) && '/event/spring-gala' === get_post_meta( $new_gala, '_npmp_tec_old_path', true ) );

$new_allday = $by_source( $e_allday )[0] ?? 0;
$ok( 'all-day: keeps its date, 00:00', '2026-12-05 00:00:00' === get_post_meta( $new_allday, '_npmp_event_start', true ), get_post_meta( $new_allday, '_npmp_event_start', true ) );
$ok( 'all-day: ends 23:59', '2026-12-05 23:59:00' === get_post_meta( $new_allday, '_npmp_event_end', true ) );
$ok( 'all-day: flag', '1' === get_post_meta( $new_allday, '_npmp_event_all_day', true ) );
$ok( 'all-day: venue without street', 'Arroyo Verde Park, Ventura, CA' === get_post_meta( $new_allday, '_npmp_event_location', true ) );
$ok( 'all-day: Fundraisers term', array( 'fundraisers' ) === wp_get_object_terms( $new_allday, 'npmp_event_category', array( 'fields' => 'slugs' ) ) );

$new_india = $by_source( $e_india )[0] ?? 0;
$ok( 'UTC+5.5 event: 09:00 there is 03:30 London', '2026-12-10 03:30:00' === get_post_meta( $new_india, '_npmp_event_start', true ), get_post_meta( $new_india, '_npmp_event_start', true ) );
$ok( 'meeting: organizer kept', 'CACC Board' === get_post_meta( $by_source( $e_meeting )[0] ?? 0, '_npmp_tec_organizer', true ) );
$ok( 'draft stays a draft', 'draft' === get_post_status( $by_source( $e_draft )[0] ?? 0 ) );
$yoga_new = $by_source( $e_yoga );
$ok( 'yoga: one event per date', 3 === count( $yoga_new ) );
$ok( 'Meetings term reused, not doubled', 1 === count( get_terms( array( 'taxonomy' => 'npmp_event_category', 'slug' => 'meetings', 'hide_empty' => false ) ) ) );

echo "Pro hook\n";
$ok( 'npmp_tec_event_imported fired once per new event', 8 === array_sum( array_map( 'count', $captured ) ) );
$ok( 'hook gets the TEC category slugs', array( 'galas' ) === ( $captured[ $e_gala ][0][1] ?? null ) );
$ok( 'hook gets the approve-form options', array( 'categories_to_groups' => '1' ) === ( $captured[ $e_gala ][0][2] ?? null ) );
$ok( 'hook gets the new ID', $new_gala === ( $captured[ $e_gala ][0][0] ?? 0 ) );

echo "Run again: flagged, never doubled\n";
$before = count( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
$res    = npmp_tec_stage_batch( true );
$stage  = npmp_tec_get_stage();
$flag   = count( array_filter( wp_list_pluck( $stage['items'], 'imported' ) ) );
$ok( 'eight items flagged as already moved', 8 === $flag, (string) $flag );
$ok( 'only the two unchecked are selectable', 2 === count( npmp_tec_selected_keys( $stage ) ) );
// Force the guard: queue keys that already exist, as a second open tab would.
npmp_tec_approve( array() );
$stage          = npmp_tec_get_stage();
$stage['queue'] = array_map( 'strval', array_keys( $stage['items'] ) );
foreach ( $stage['items'] as $k => $it ) {
	$stage['items'][ $k ]['imported'] = 0;
}
npmp_tec_save_stage( $stage );
do {
	$res = npmp_tec_migrate_batch();
} while ( ! $res['done'] );
$stage = npmp_tec_get_stage();
$after = count( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
$ok( 'the eight existing ones counted as duplicates', 8 === $stage['results']['duplicates'], wp_json_encode( $stage['results'] ) );
$ok( 'only the two left-behind events were added', $before + 2 === $after, "$before -> $after" );
$ok( 'gala still has exactly one copy', 1 === count( $by_source( $e_gala ) ) );

echo "Step 5: deactivate\n";
$list = npmp_tec_plugins_to_deactivate();
$ok( 'lists TEC and both add-ons by name', in_array( 'The Events Calendar', $list, true ) && in_array( 'The Events Calendar Shortcode & Templates', $list, true ) && in_array( 'Rig TEC Add-on', $list, true ) && 3 === count( $list ), wp_json_encode( array_values( $list ) ) );
$ok( 'TEC goes last', 'The Events Calendar' === end( $list ) );
$off = npmp_tec_deactivate();
$ok( 'all three off', 3 === count( $off ) && ! is_plugin_active( npmp_tec_plugin_file() ) && ! is_plugin_active( 'the-events-calendar-shortcode/the-events-calendar-shortcode.php' ) );
$tec_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_events'" );
$ok( 'TEC events still in the database', 8 === $tec_rows, (string) $tec_rows );
$ok( 'TEC venues still in the database', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_venue'" ) );
$ok( 'TEC event meta still there', '2027-04-10 18:30:00' === get_post_meta( $e_gala, '_EventStartDate', true ) );
$ok( 'TEC categories still there', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat'" ) );

echo "Old URLs over HTTP (php -S)\n";
$get = function ( $url ) {
	return trim( (string) shell_exec( 'curl -s -o /dev/null --max-time 20 -w "%{http_code} %{redirect_url}" ' . escapeshellarg( $url ) ) );
};
// Serve the site at its home URL, unless something already does.
$pid = 0;
if ( '000' === substr( $get( home_url( '/wp-login.php' ) ), 0, 3 ) ) {
	$home = wp_parse_url( home_url() );
	$pid  = (int) shell_exec( sprintf( 'php -S %s -t %s > /dev/null 2>&1 & echo $!', escapeshellarg( $home['host'] . ':' . ( $home['port'] ?? 80 ) ), escapeshellarg( untrailingslashit( ABSPATH ) ) ) );
}
for ( $i = 0; $i < 50 && '000' === substr( $get( home_url( '/wp-login.php' ) ), 0, 3 ); $i++ ) {
	usleep( 100000 );
}
$hit = $get( $old_gala_url );
$ok( 'old gala URL 301s to the new event', '301 ' . get_permalink( $new_gala ) === $hit, $hit );
$hit = $get( home_url( '/EVENT/Spring-Gala?utm_source=newsletter' ) );
$ok( 'case and tracking args still match', '301 ' . get_permalink( $new_gala ) === $hit, $hit );
$second = get_posts( array( 'post_type' => 'npmp_event', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_npmp_tec_old_path', 'meta_value' => '/event/weekly-yoga/2026-11-12' ) );
$hit    = $get( trailingslashit( $old_yoga_url ) . '2026-11-12/' );
$ok( 'a series date 301s to its own event', $second && '301 ' . get_permalink( $second[0] ) === $hit, $hit );
$hit = $get( $old_yoga_url );
$ok( 'the series URL 301s to its first date', 0 === strpos( $hit, '301 ' ) && false !== strpos( $hit, '2026-11-05' ), $hit );
$hit = $get( home_url( '/event/not-a-real-event/' ) );
$ok( 'unknown event path stays a 404', 0 === strpos( $hit, '404' ), $hit );
$hit = $get( get_permalink( $new_gala ) );
$ok( 'new event page answers 200', 0 === strpos( $hit, '200' ), $hit );
if ( $pid ) {
	posix_kill( $pid, SIGTERM );
	usleep( 200000 );
	$ok( 'php -S stopped', '000' === substr( $get( home_url( '/' ) ), 0, 3 ) );
}

foreach ( array_keys( $fakes ) as $rel ) {
	unlink( $plugins_dir . '/' . $rel );
	rmdir( dirname( $plugins_dir . '/' . $rel ) );
}

printf( "\n%s\n", $fails ? "$fails FAILED" : 'All checks passed.' );
exit( $fails ? 1 : 0 );
