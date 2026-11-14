<?php
/**
 * Real-WordPress run of the Switch Plugins hub with The Events Calendar:
 * all five steps in order, the refusals (a step out of order, a missing
 * capability, DISALLOW_FILE_MODS, a plugin that shares TEC's data, a missing
 * or wrong typed confirmation), and proof that the imported events, their
 * categories and featured image, and the 301s from old TEC links survive
 * once TEC's data is gone.
 *
 * Each step after the import runs in its own `wp` process, the way each form
 * post is its own request, so TEC's code isn't loaded in a request that runs
 * after TEC is off or deleted.
 *
 * Needs a throwaway WordPress with this plugin and The Events Calendar active,
 * pretty permalinks, home_url at http://127.0.0.1:<free port>, `wp` on PATH:
 *   wp plugin install the-events-calendar --activate
 *   wp rewrite structure '/%postname%/'
 *   wp eval-file tests/rig/import-hub.php
 *
 * It deletes TEC's files and data. Install TEC again before another run.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};
$code = function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : ( is_array( $result ) ? (string) ( $result['message'] ?? 'ok' ) : 'ok' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin  = (int) $admins[0]->ID;
wp_set_current_user( $admin );

$slug = 'the-events-calendar';

/**
 * Run a phase of this file in a fresh wp process and pass its output through.
 *
 * @param string $phase  Phase name.
 * @param string $before PHP for --exec, run before WordPress loads.
 * @return int Exit code.
 */
$run_phase = function ( $phase, $before = '' ) {
	$cmd = sprintf(
		'wp --path=%s %s eval-file %s %s 2>&1',
		escapeshellarg( untrailingslashit( ABSPATH ) ),
		'' !== $before ? '--exec=' . escapeshellarg( $before ) : '',
		escapeshellarg( __FILE__ ),
		escapeshellarg( $phase )
	);
	$out  = array();
	$exit = 0;
	exec( $cmd, $out, $exit );
	foreach ( $out as $line ) {
		if ( false === strpos( $line, 'Deprecated' ) && false === strpos( $line, 'tribe-canonical-line' ) ) {
			echo $line, "\n";
		}
	}
	return $exit;
};

if ( 'main' === $phase ) {
	if ( ! function_exists( 'tribe_create_event' ) ) {
		echo "The Events Calendar isn't active. Run: wp plugin install the-events-calendar --activate\n";
		exit( 1 );
	}
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		echo "Pretty permalinks are off. Run: wp rewrite structure '/%postname%/'\n";
		exit( 1 );
	}

	echo "Clean slate\n";
	// Straight from the table: TEC's query filters hide events it can't date.
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('npmp_event','tribe_events','tribe_venue','tribe_organizer')" ) as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}tec_occurrences" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}tec_events" );
	$wpdb->query( "DELETE t, tt FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ('npmp_event_category','tribe_events_cat')" );
	npmp_tec_reset_stage();
	delete_option( 'npmp_tec_imported' );
	delete_option( 'npmp_import_hub' );
	delete_option( 'npmp_rig_hub' );
	flush_rewrite_rules();

	echo "Seed TEC, an add-on, and things that must survive\n";
	$fund  = wp_insert_term( 'Fundraisers', 'tribe_events_cat', array( 'slug' => 'fundraisers' ) );
	$venue = tribe_create_venue( array( 'Venue' => 'Ventura Hall', 'City' => 'Ventura', 'State' => 'CA' ) );
	$make  = function ( $title, $day, $cats ) use ( $venue ) {
		$id = tribe_create_event(
			array(
				'post_title'       => $title,
				'post_status'      => 'publish',
				'post_content'     => "<p>About $title.</p>",
				'EventStartDate'   => $day,
				'EventEndDate'     => $day,
				'EventStartHour'   => '18',
				'EventStartMinute' => '00',
				'EventEndHour'     => '20',
				'EventEndMinute'   => '00',
				'EventTimezone'    => 'America/Los_Angeles',
				'Venue'            => array( 'VenueID' => $venue ),
				'Organizer'        => array( 'Organizer' => 'Rig Board' ),
			)
		);
		if ( $id && $cats ) {
			wp_set_object_terms( $id, $cats, 'tribe_events_cat' );
		}
		return $id;
	};
	$gala  = $make( 'Hub Gala', '2027-04-10', array( 'fundraisers' ) );
	$walk  = $make( 'Hub Walkathon', '2027-05-01', array( 'fundraisers' ) );
	$board = $make( 'Hub Board Meeting', '2027-06-01', array() );
	$ok( 'three TEC events', $gala && $walk && $board );

	// A featured image attached to the gala, so data removal must keep it.
	$upload = wp_upload_dir();
	$png    = trailingslashit( $upload['path'] ) . 'hub-rig.png';
	file_put_contents( $png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
	$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Hub rig image', 'post_status' => 'inherit' ), $png, $gala );
	set_post_thumbnail( $gala, $att );
	$ok( 'gala has an attached featured image', (int) get_post_thumbnail_id( $gala ) === (int) $att && (int) get_post( $att )->post_parent === (int) $gala );

	$keep_post = wp_insert_post( array( 'post_title' => 'Hub rig keeper', 'post_status' => 'publish', 'post_type' => 'post' ) );
	$keep_cat  = wp_insert_term( 'Fundraisers', 'category', array( 'slug' => 'fundraisers-news' ) );
	update_option( 'technology_options', 'keep' );
	update_option( 'tribes_plugin_options', 'keep' );

	$addon = 'npmp-rig-hub-addon/npmp-rig-hub-addon.php';
	wp_mkdir_p( WP_PLUGIN_DIR . '/npmp-rig-hub-addon' );
	file_put_contents( WP_PLUGIN_DIR . '/' . $addon, "<?php\n/**\n * Plugin Name: Rig Hub TEC Add-on\n * Version: 1.0\n * Requires Plugins: the-events-calendar\n */\n" );
	wp_clean_plugins_cache( false );
	activate_plugin( $addon );
	$ok( 'the add-on is active', is_plugin_active( $addon ) );

	echo "Registry and first look\n";
	$source = npmp_import_hub_source( $slug );
	$ok( 'TEC is registered', is_array( $source ) && 'The Events Calendar' === $source['name'] );
	$ok( 'TEC is a free source', 'free' === $source['tier'] );
	$ok( 'TEC declares data removal', true === $source['data_removal']['available'] );
	$facts  = npmp_import_hub_facts( $source );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'detected as active', 'active' === npmp_import_hub_detect_state( $facts ) );
	$ok( 'TEC goes on top, not in the folded group', in_array( $slug, npmp_import_hub_partition( array( $slug => $facts ) )['here'], true ) );
	$ok( 'import is the next step', 'ready' === $states['import'] && 'locked' === $states['verify'] && 'locked' === $states['remove_data'], $states );

	echo "Refusals before the import\n";
	$ok( 'verify out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
	$ok( 'deactivate out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	$ok( 'remove data out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$ok( 'unknown source', 'npmp_hub_unknown' === $code( npmp_import_hub_run_step( 'nope', 'import' ) ) );
	$sub = wp_insert_user( array( 'user_login' => 'hubrigsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	$ok( 'a subscriber gets the capability refusal', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'import' ) ) );
	wp_set_current_user( $admin );
	wp_delete_user( $sub );

	echo "Step 1: import through TEC's own importer\n";
	$res = npmp_import_hub_run_step( $slug, 'import' );
	$ok( 'the step hands back the importer screen', is_array( $res ) && false !== strpos( $res['url'], 'page=npmp_tec_import' ), $res );
	$guard = 0;
	do {
		$r = npmp_tec_stage_batch( 0 === $guard, 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	npmp_tec_set_skip( array() );
	npmp_tec_approve( array(), array() );
	$guard = 0;
	do {
		$r = npmp_tec_migrate_batch( 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	$ok( 'three events moved', 3 === (int) get_option( 'npmp_tec_imported' ), get_option( 'npmp_tec_imported' ) );
	$new = array();
	foreach ( array( 'gala' => $gala, 'walk' => $walk, 'board' => $board ) as $k => $tec_id ) {
		$found     = get_posts( array( 'post_type' => 'npmp_event', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_npmp_tec_source_id', 'meta_value' => $tec_id ) );
		$new[ $k ] = $found ? (int) $found[0] : 0;
	}
	$ok( 'each TEC event has its new event', 3 === count( array_filter( $new ) ) );
	$ok( 'the new gala uses the same featured image', (int) get_post_thumbnail_id( $new['gala'] ) === (int) $att );
	$old_paths = array();
	foreach ( $new as $k => $id ) {
		$old_paths[ $k ] = get_post_meta( $id, '_npmp_tec_old_path' );
	}
	$ok( 'old TEC paths saved on the new events', ! empty( $old_paths['gala'] ) && ! empty( $old_paths['board'] ), $old_paths );
	$states = npmp_import_hub_step_states( npmp_import_hub_facts( $source ) );
	$ok( 'import done, verify next', 'done' === $states['import'] && 'ready' === $states['verify'], $states );

	echo "Step 2: verify\n";
	$counts = npmp_tec_hub_counts();
	$ok( 'events: 3 in TEC, 3 here', 3 === $counts[0]['old'] && 3 === $counts[0]['new'], $counts[0] );
	$ok( 'categories: 1 in TEC, 1 here', 1 === $counts[1]['old'] && 1 === $counts[1]['new'], $counts[1] );
	$ok( 'redirects counted', $counts[3]['new'] >= 3, $counts[3] );
	$ok( 'no box ticked, refused', 'npmp_hub_unchecked' === $code( npmp_import_hub_run_step( $slug, 'verify', array() ) ) );
	$ok( 'still waiting on verify', 'ready' === npmp_import_hub_step_states( npmp_import_hub_facts( $source ) )['verify'] );
	$ok( 'box ticked, verified', 'verified' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
	$ok( 'verify can run again', 'verified' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );

	echo "The screen renders\n";
	set_current_screen( 'dashboard' );
	ob_start();
	npmp_import_hub_render_page();
	$html = ob_get_clean();
	$ok( 'shows TEC with its status', false !== strpos( $html, 'id="npmp-hub-the-events-calendar"' ) && false !== strpos( $html, 'Active on this site' ) );
	$ok( 'shows the counts table', false !== strpos( $html, 'npmp-hub-counts' ) );
	$ok( 'the Pro part shows locked or included', false !== strpos( $html, 'Turn each event category into a group' ) );
	$ok( 'deactivate form is next', false !== strpos( $html, 'value="deactivate"' ) );
	$ok( 'no delete form yet', false === strpos( $html, 'name="npmp_hub_confirm"' ) );

	echo "Step 3: deactivate\n";
	$role = add_role( 'npmp_rig_manager', 'Rig manager', array( 'read' => true, 'manage_options' => true ) );
	$mgr  = wp_insert_user( array( 'user_login' => 'hubrigmgr' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'npmp_rig_manager' ) );
	wp_set_current_user( $mgr );
	$ok( 'without activate_plugins, refused', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	$ok( 'TEC still on', is_plugin_active( npmp_tec_plugin_file() ) );
	wp_set_current_user( $admin );
	wp_delete_user( $mgr );
	remove_role( 'npmp_rig_manager' );
	$ok( 'deactivated', 'deactivated' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	$ok( 'TEC off', ! is_plugin_active( npmp_tec_plugin_file() ) );
	$ok( 'the add-on off too', ! is_plugin_active( $addon ) );

	update_option(
		'npmp_rig_hub',
		array(
			'new'       => $new,
			'att'       => (int) $att,
			'paths'     => $old_paths,
			'keep_post' => (int) $keep_post,
			'keep_cat'  => is_array( $keep_cat ) ? (int) $keep_cat['term_id'] : 0,
			'addon'     => $addon,
		),
		false
	);

	echo "--- next request, TEC off ---\n";
	$fails += $run_phase( 'remove' ) ? 1 : 0;
	echo "--- DISALLOW_FILE_MODS ---\n";
	$fails += $run_phase( 'filemods', "define( 'DISALLOW_FILE_MODS', true );" ) ? 1 : 0;
	echo "--- next request ---\n";
	$fails += $run_phase( 'remove2' ) ? 1 : 0;
	echo "--- next request, TEC deleted ---\n";
	$fails += $run_phase( 'data' ) ? 1 : 0;
	echo "--- next request, after data removal ---\n";
	$fails += $run_phase( 'after' ) ? 1 : 0;

	// This process loaded TEC before a child process deleted its files, so
	// TEC's shutdown work would fatal here. A real request never has TEC
	// loaded once it's deleted.
	remove_all_actions( 'shutdown' );
	printf( "\n%s\n", $fails ? "$fails failed" : 'all passed' );
	exit( $fails ? 1 : 0 );
}

$rig    = get_option( 'npmp_rig_hub', array() );
$source = npmp_import_hub_source( $slug );

if ( 'remove' === $phase ) {
	echo "Step 4: refusals while TEC is off but installed\n";
	$ok( 'TEC classes not loaded in this request', ! class_exists( 'Tribe__Events__Main', false ) );
	$facts = npmp_import_hub_facts( $source );
	$ok( 'detected as installed and off', 'inactive' === npmp_import_hub_detect_state( $facts ) );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'remove plugin is next', 'done' === $states['deactivate'] && 'ready' === $states['remove_plugin'] && 'locked' === $states['remove_data'], $states );
	$ok( 'remove data out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$ok( 'TEC import is blocked with TEC off', 'blocked' !== $states['import'] && is_string( npmp_tec_hub_can_import() ) );
	$strip = function ( $caps ) {
		unset( $caps['delete_plugins'] );
		return $caps;
	};
	add_filter( 'user_has_cap', $strip );
	$ok( 'without delete_plugins, refused', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'remove_plugin' ) ) );
	remove_filter( 'user_has_cap', $strip );
	$ok( 'TEC files still there', '' !== npmp_tec_plugin_file() );
	exit( $fails ? 1 : 0 );
}

if ( 'filemods' === $phase ) {
	$ok( 'DISALLOW_FILE_MODS is on in this request', defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	$ok( 'remove plugin refused', 'npmp_hub_file_mods' === $code( npmp_import_hub_run_step( $slug, 'remove_plugin' ) ) );
	$ok( 'TEC files still there', '' !== npmp_tec_plugin_file() );
	exit( $fails ? 1 : 0 );
}

if ( 'remove2' === $phase ) {
	echo "Step 4: delete the plugin\n";
	$installed = npmp_tec_hub_installed_plugins();
	$ok( 'TEC and the add-on are what gets deleted', 2 === count( $installed ) && isset( $installed[ $rig['addon'] ] ), $installed );
	$res = npmp_import_hub_run_step( $slug, 'remove_plugin' );
	$ok( 'deleted', 'removed_plugin' === $code( $res ), $code( $res ) );
	wp_clean_plugins_cache( false );
	$ok( 'TEC folder gone', ! is_dir( WP_PLUGIN_DIR . '/the-events-calendar' ) );
	$ok( 'add-on folder gone', ! is_dir( WP_PLUGIN_DIR . '/npmp-rig-hub-addon' ) );
	$ok( 'this plugin still here', is_dir( WP_PLUGIN_DIR . '/nonprofit-manager' ) );
	$ok( 'TEC data still in the database after deleting files', npmp_tec_hub_has_data() );
	exit( $fails ? 1 : 0 );
}

if ( 'data' === $phase ) {
	echo "Step 5: delete TEC's data\n";
	$facts = npmp_import_hub_facts( $source );
	$ok( 'detected as data left behind', 'data' === npmp_import_hub_detect_state( $facts ) );
	$ok( 'remove data is next', 'ready' === npmp_import_hub_step_states( $facts )['remove_data'] );

	// Event Tickets shares tribe-common's options, so its presence blocks the step.
	// The check writes a stub at Event Tickets' own path and deletes it, so it
	// refuses to run beside a real Event Tickets.
	if ( file_exists( WP_PLUGIN_DIR . '/event-tickets' ) ) {
		echo "Refusing: Event Tickets is installed in " . WP_PLUGIN_DIR . "/event-tickets, and this step writes a stub over its main file and then deletes it. Use a site without Event Tickets.\n";
		exit( 1 );
	}
	wp_mkdir_p( WP_PLUGIN_DIR . '/event-tickets' );
	file_put_contents( WP_PLUGIN_DIR . '/event-tickets/event-tickets.php', "<?php\n/**\n * Plugin Name: Event Tickets\n * Version: 1.0\n */\n" );
	wp_clean_plugins_cache( false );
	$ok( 'Event Tickets installed: refused', 'npmp_hub_blocked' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	unlink( WP_PLUGIN_DIR . '/event-tickets/event-tickets.php' );
	rmdir( WP_PLUGIN_DIR . '/event-tickets' );
	wp_clean_plugins_cache( false );

	$ok( 'no confirmation: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array() ) ) );
	$ok( 'wrong word: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELET' ) ) ) );
	$ok( 'the plugin name is not the word: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'The Events Calendar' ) ) ) );
	$ok( 'nothing deleted after the refusals', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tribe_events'" ) );

	$res = npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => ' delete ', 'one_batch' => 1 ) );
	$ok( 'first batch runs and stops part way', 'removing' === $code( $res ), $res );
	$state = npmp_import_hub_get_state( $slug );
	$ok( 'started is saved', ! empty( $state['removal']['started'] ) && empty( $state['removal']['done'] ) );
	$guard = 0;
	do {
		$res = npmp_import_hub_run_step( $slug, 'remove_data', array( 'one_batch' => 1 ) );
	} while ( 'removing' === $code( $res ) && ++$guard < 20 );
	$ok( 'later batches need no retyping and finish', 'removed_data' === $code( $res ), $res );
	exit( $fails ? 1 : 0 );
}

if ( 'after' === $phase ) {
	echo "What's gone\n";
	$ok( 'no TEC posts', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('tribe_events','tribe_venue','tribe_organizer','tribe_event_series')" ) );
	$ok( 'no orphaned TEC post meta', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_EventStartDate','_VenueCity','_OrganizerEmail')" ) );
	$ok( 'no TEC categories', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat'" ) );
	$ok( 'no TEC options', array() === npmp_tec_hub_option_names(), npmp_tec_hub_option_names() );
	$ok( 'no TEC tables', array() === npmp_tec_hub_existing_tables(), npmp_tec_hub_existing_tables() );
	$left = array();
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( npmp_tec_hub_cron_is_tec( $hook ) ) {
				$left[] = $hook;
			}
		}
	}
	$ok( 'no TEC cron hooks', ! $left, $left );
	$ok( 'has_data is false now', ! npmp_tec_hub_has_data() );

	echo "What stays\n";
	foreach ( $rig['new'] as $k => $id ) {
		$p = get_post( $id );
		$ok( "imported $k event still published", $p && 'npmp_event' === $p->post_type && 'publish' === $p->post_status );
	}
	$ok( 'old paths still on the new events', get_post_meta( $rig['new']['gala'], '_npmp_tec_old_path' ) === $rig['paths']['gala'] );
	$ok( 'event category still on the gala', has_term( 'fundraisers', 'npmp_event_category', $rig['new']['gala'] ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = 'npmp_event_category' AND t.slug = 'fundraisers'", $rig['new']['gala'] ) ) );
	$att = get_post( $rig['att'] );
	$ok( 'featured image kept, moved up to the media library', $att && 'attachment' === $att->post_type && 0 === (int) $att->post_parent );
	$ok( 'new gala still shows it', (int) get_post_meta( $rig['new']['gala'], '_thumbnail_id', true ) === (int) $rig['att'] );
	$ok( 'unrelated post kept', (bool) get_post( $rig['keep_post'] ) );
	$ok( 'same-named post category kept', (bool) get_term( $rig['keep_cat'], 'category' ) );
	$ok( 'look-alike options kept', 'keep' === get_option( 'technology_options' ) && 'keep' === get_option( 'tribes_plugin_options' ) );
	$ok( 'import counter kept', 3 === (int) get_option( 'npmp_tec_imported' ) );

	echo "The hub after the switch\n";
	$facts  = npmp_import_hub_facts( $source );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'every step done', npmp_import_hub_finished( $states ), $states );
	$ok( 'TEC stays listed on top', in_array( $slug, npmp_import_hub_partition( array( $slug => $facts ) )['here'], true ) );
	$ok( 'remove data can\'t run twice', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$ok( 'TEC importer screen still registered', npmp_tec_importer_visible() );

	echo "Old URLs over HTTP (php -S)\n";
	$port = (int) wp_parse_url( home_url(), PHP_URL_PORT );
	$pid  = (int) shell_exec( sprintf( 'php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!', $port, escapeshellarg( untrailingslashit( ABSPATH ) ) ) );
	$get  = function ( $url ) {
		return trim( (string) shell_exec( 'curl -s -o /dev/null --max-time 20 -w "%{http_code} %{redirect_url}" ' . escapeshellarg( $url ) ) );
	};
	for ( $i = 0; $i < 50 && '000' === substr( $get( home_url( '/wp-login.php' ) ), 0, 3 ); $i++ ) {
		usleep( 100000 );
	}
	foreach ( array( 'gala', 'walk', 'board' ) as $k ) {
		$path = $rig['paths'][ $k ][0] ?? '';
		$hit  = $get( home_url( trailingslashit( $path ) ) );
		$ok( "old $k link 301s to the new event", '301 ' . get_permalink( $rig['new'][ $k ] ) === $hit, $path . ' => ' . $hit );
		$hit = $get( get_permalink( $rig['new'][ $k ] ) );
		$ok( "new $k page answers 200", 0 === strpos( $hit, '200' ), $hit );
	}
	$hit = $get( home_url( '/event/not-a-real-event/' ) );
	$ok( 'an unknown old path stays a 404', 0 === strpos( $hit, '404' ), $hit );
	if ( $pid ) {
		posix_kill( $pid, SIGTERM );
	}
	exit( $fails ? 1 : 0 );
}
