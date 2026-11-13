<?php
/**
 * Real-WordPress check that The Events Calendar importer keeps the whole
 * category tree, parents with no event of their own included, when the move
 * runs after TEC is off.
 *
 * The 2026-11-13 review lost a parent category ("Club Events") this way: the
 * events carried only the child, and the migrate step looked the parent up
 * through TEC, which was off by then. Staging now reads every parent from
 * the term tables.
 *
 * Needs a throwaway WordPress with this plugin and The Events Calendar active,
 * and `wp` on PATH:
 *   wp plugin install the-events-calendar --activate
 *   wp eval-file tests/rig/tec-categories.php
 *
 * The migrate runs in its own `wp` process with TEC off, the way it would on
 * a real site. TEC is on again at the end, and everything the rig made is gone.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . $detail . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );

if ( 'migrate' === $phase ) {
	$ok( 'TEC is off in this process', ! function_exists( 'tribe_create_event' ) && ! taxonomy_exists( 'tribe_events_cat' ) );
	$guard = 0;
	do {
		$r = npmp_tec_migrate_batch();
	} while ( empty( $r['done'] ) && ++$guard < 50 );

	$terms = array();
	foreach ( get_terms( array( 'taxonomy' => 'npmp_event_category', 'hide_empty' => false ) ) as $t ) {
		$terms[ $t->slug ] = $t;
	}
	$parent = $terms['rig-club-events'] ?? null;
	$ok( 'the parent with no event of its own came over', (bool) $parent );
	$ok( 'Book Club sits under it', $parent && isset( $terms['rig-book-club'] ) && (int) $terms['rig-book-club']->parent === (int) $parent->term_id );
	$ok( 'Hikes sits under it', $parent && isset( $terms['rig-hikes'] ) && (int) $terms['rig-hikes']->parent === (int) $parent->term_id );
	$ok( 'the grandparent came over too', $parent && isset( $terms['rig-everything'] ) && (int) $parent->parent === (int) $terms['rig-everything']->term_id );

	$events = array();
	foreach ( get_posts( array( 'post_type' => 'npmp_event', 'post_status' => 'any', 'numberposts' => -1, 's' => 'Rig cat' ) ) as $p ) {
		$events[ $p->post_title ] = wp_get_object_terms( $p->ID, 'npmp_event_category', array( 'fields' => 'slugs' ) );
	}
	$ok( 'the book club event keeps only its own category', array( 'rig-book-club' ) === ( $events['Rig cat book club'] ?? null ), wp_json_encode( $events ) );
	$ok( 'the hike keeps only its own category', array( 'rig-hikes' ) === ( $events['Rig cat hike'] ?? null ), wp_json_encode( $events ) );
	exit( $fails ? 1 : 0 );
}

if ( ! function_exists( 'tribe_create_event' ) ) {
	echo "The Events Calendar isn't active. Run: wp plugin activate the-events-calendar\n";
	exit( 1 );
}

$clean = function () {
	global $wpdb;
	foreach ( array( 'npmp_event', 'tribe_events' ) as $type ) {
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'Rig cat' ) ) as $pid ) {
			wp_delete_post( $pid, true );
		}
	}
	// By slug, so the rig never touches a category it didn't make.
	$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE t.slug LIKE 'rig-%'" ); // phpcs:ignore
	$wpdb->query( "DELETE tt FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE t.slug LIKE 'rig-%'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE slug LIKE 'rig-%'" ); // phpcs:ignore
	clean_term_cache( array(), 'npmp_event_category' );
	npmp_tec_reset_stage();
};

echo "Seed a category tree in TEC, with events on the children only\n";
$clean();
$had_imported = get_option( 'npmp_tec_imported', null );
$top   = wp_insert_term( 'Everything', 'tribe_events_cat', array( 'slug' => 'rig-everything' ) );
$club  = wp_insert_term( 'Club Events', 'tribe_events_cat', array( 'slug' => 'rig-club-events', 'parent' => $top['term_id'] ) );
$book  = wp_insert_term( 'Book Club', 'tribe_events_cat', array( 'slug' => 'rig-book-club', 'parent' => $club['term_id'] ) );
$hikes = wp_insert_term( 'Hikes', 'tribe_events_cat', array( 'slug' => 'rig-hikes', 'parent' => $club['term_id'] ) );
$make  = function ( $title, $cat ) {
	$id = tribe_create_event( array( 'post_title' => $title, 'post_status' => 'publish', 'EventStartDate' => '2026-12-01', 'EventEndDate' => '2026-12-01', 'EventStartHour' => '10', 'EventStartMinute' => '00', 'EventEndHour' => '11', 'EventEndMinute' => '00' ) );
	wp_set_object_terms( $id, array( $cat ), 'tribe_events_cat' );
	return $id;
};
$make( 'Rig cat book club', 'rig-book-club' );
$make( 'Rig cat hike', 'rig-hikes' );

$r = npmp_tec_stage_batch( true );
while ( empty( $r['done'] ) ) {
	$r = npmp_tec_stage_batch();
}
$stage = npmp_tec_get_stage();
$item  = null;
foreach ( (array) $stage['items'] as $it ) {
	if ( 'Rig cat book club' === $it['title'] ) {
		$item = $it;
	}
}
$slugs = $item ? wp_list_pluck( $item['categories'], 'slug' ) : array();
sort( $slugs );
$ok( 'staging holds the event\'s category and both parents', array( 'rig-book-club', 'rig-club-events', 'rig-everything' ) === $slugs, wp_json_encode( $slugs ) );
$ok( 'the review shows the event\'s own category only', $item && array( 'Book Club' ) === wp_list_pluck( npmp_tec_assigned_categories( $item['categories'] ), 'name' ) );
npmp_tec_approve( array() );

echo "Turn TEC off, then move the events in a fresh process\n";
deactivate_plugins( 'the-events-calendar/the-events-calendar.php' );
$cmd = sprintf( 'wp --path=%s eval-file %s migrate 2>&1', escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( __FILE__ ) );
$out = array();
exec( $cmd, $out, $exit ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
foreach ( $out as $line ) {
	if ( false === stripos( $line, 'deprecated' ) && '' !== trim( $line ) ) {
		echo $line . "\n";
		if ( false !== strpos( $line, 'FAIL' ) ) {
			$fails++;
		}
	}
}
$ok( 'the migrate process exited cleanly', 0 === (int) $exit );

echo "Put things back\n";
activate_plugin( 'the-events-calendar/the-events-calendar.php' );
$clean();
null === $had_imported ? delete_option( 'npmp_tec_imported' ) : update_option( 'npmp_tec_imported', $had_imported );
$ok( 'TEC is on again', is_plugin_active( 'the-events-calendar/the-events-calendar.php' ) );

echo $fails ? "\n{$fails} FAILED\n" : "\nAll passed\n";
exit( $fails ? 1 : 0 );
