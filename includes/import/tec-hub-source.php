<?php
/**
 * File path: includes/import/tec-hub-source.php
 *
 * The Events Calendar as a source on the Switch Plugins screen
 * (includes/import/import-hub.php).
 *
 * Import is the five-screen importer in tec-importer.php. Deactivate is
 * npmp_tec_deactivate(), which turns off TEC's add-ons first. Remove plugin
 * deletes TEC and every installed add-on that needs it (TEC 6.18's
 * uninstall.php deletes nothing, so its data stays for step 5).
 *
 * Remove data deletes only what TEC wrote:
 *   - posts of type tribe_events, tribe_venue, tribe_organizer, and
 *     tribe_event_series, with their meta, comments, and revisions
 *     (wp_delete_post() moves attached images up to the media library, so a
 *     featured image an imported event now uses stays),
 *   - terms in tribe_events_cat (wp_delete_term() keeps a term row another
 *     taxonomy still uses),
 *   - options and cron hooks npmp_tec_hub_option_is_tec() and
 *     npmp_tec_hub_cron_is_tec() name,
 *   - the tables in npmp_tec_hub_tables().
 *
 * What the 301s and imported events read stays: the npmp_event posts, their
 * _npmp_tec_old_path, _npmp_tec_source_id, and _npmp_tec_source_key meta, the
 * npmp_event_category terms, and the npmp_tec_imported option. None of those
 * names match the lists above. Network options on multisite stay, since other
 * sites in the network may still run TEC.
 *
 * Data removal refuses while Event Tickets (or any plugin that needs TEC) is
 * installed, because tribe-common keeps its settings in the same option names.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers (no database), covered by tests/test-import-hub.php
// =====================================================================

/**
 * Post types The Events Calendar (and TEC Pro's series) register.
 *
 * @return string[]
 */
function npmp_tec_hub_post_types() {
	return array( 'tribe_events', 'tribe_venue', 'tribe_organizer', 'tribe_event_series' );
}

/**
 * Tables TEC 6 creates, without the site prefix.
 *
 * @return string[]
 */
function npmp_tec_hub_tables() {
	return array(
		'tec_occurrences',
		'tec_events',
		'tec_series_relationships',
		'tec_kv_cache',
		'shepherd_tec_tasks',
		'shepherd_tec_task_logs',
		'stellarwp_tec_migration_logs',
		'stellarwp_tec_migration_executions',
	);
}

/**
 * Is this option one The Events Calendar wrote?
 *
 * Names come from a TEC 6.18 install with events, venues, organizers, and a
 * category. Nothing starting with npmp ever matches. Transients are caches
 * that rebuild themselves, so any tribe or tec_ transient counts.
 *
 * @param string $name Option name.
 * @return bool
 */
function npmp_tec_hub_option_is_tec( $name ) {
	$name = (string) $name;
	if ( '' === $name || 0 === strpos( $name, 'npmp' ) ) {
		return false;
	}

	foreach ( array( '_transient_timeout_', '_transient_', '_site_transient_timeout_', '_site_transient_' ) as $wrap ) {
		if ( 0 === strpos( $name, $wrap ) ) {
			$inner = substr( $name, strlen( $wrap ) );
			return 0 === strpos( $inner, 'tribe' ) || 0 === strpos( $inner, 'tec_' ) || 0 === strpos( $inner, 'tec-' );
		}
	}

	$prefixes = array(
		'tribe_events_',
		'tribe_events-',
		'tribe_last_',
		'tribe_customizer',
		'tribe_settings_',
		'tribe_pue_',
		'tribe_feature_',
		'tribe_dismissed_',
		'tec_ct1_',
		'tec_events_',
		'tec_timed_',
		'tec_custom_tables_',
		'tec_freemius_',
		'tec_telemetry_',
		'tec_onboarding_',
		'widget_tribe-',
		'stellarwp_telemetry_the-events-calendar',
		'stellar_schema_version_stellarwp-migrations-tec-',
		'stellar_schema_version_stellarwp-shepherd-tec-',
		'stellar_schema_version_tec-',
		'external_updates-the-events-calendar',
		'pue_install_key_the_events_calendar',
	);
	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Is this cron hook one The Events Calendar scheduled?
 *
 * @param string $hook Hook name.
 * @return bool
 */
function npmp_tec_hub_cron_is_tec( $hook ) {
	$hook = (string) $hook;
	foreach ( array( 'tribe_', 'tribe-', 'tec_events_', 'tec_ct1_', 'tec_kv_', 'tec_shepherd_' ) as $prefix ) {
		if ( 0 === strpos( $hook, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Plugin folders that share TEC's data and must be gone before it's deleted.
 *
 * @param string $file Plugin basename.
 * @param array  $data Header data.
 * @return bool
 */
function npmp_tec_hub_shares_data( $file, array $data ) {
	$folder = strtolower( (string) strtok( (string) $file, '/' ) );
	if ( in_array( $folder, array( 'the-events-calendar', 'event-tickets', 'event-tickets-plus' ), true ) ) {
		return true;
	}
	return function_exists( 'npmp_tec_plugin_needs_tec' ) && npmp_tec_plugin_needs_tec( $file, $data );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_tec_hub_register' );

/**
 * Add The Events Calendar to the hub.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_tec_hub_register( $sources ) {
	$sources['the-events-calendar'] = array(
		'name'              => 'The Events Calendar',
		'covers'            => __( 'Events, venues, organizers, categories, and calendar settings', 'nonprofit-manager' ),
		'feature'           => __( 'Events', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'Turn each event category into a group', 'nonprofit-manager' ) ),
		'plugin_folders'    => array( 'the-events-calendar' ),
		'has_data'          => 'npmp_tec_hub_has_data',
		'is_imported'       => 'npmp_tec_hub_is_imported',
		'import_running'    => static function () {
			$stage = npmp_tec_get_stage();
			return ! empty( $stage['approved'] ) && empty( $stage['migrated'] );
		},
		'can_import'        => 'npmp_tec_hub_can_import',
		'import_url'        => 'npmp_tec_page_url',
		'import_summary'    => 'npmp_tec_hub_import_summary',
		'counts'            => 'npmp_tec_hub_counts',
		'checklist'         => array(
			__( 'Open three events under Events and check the dates, times, and locations.', 'nonprofit-manager' ),
			__( 'Visit a link to an old event page. It should land on the new event.', 'nonprofit-manager' ),
			__( 'Look at your calendar page and your event list on the site.', 'nonprofit-manager' ),
			__( 'Check the calendar settings under Events, Settings.', 'nonprofit-manager' ),
		),
		'deactivate'        => 'npmp_tec_deactivate',
		'active_plugins'    => 'npmp_tec_plugins_to_deactivate',
		'installed_plugins' => 'npmp_tec_hub_installed_plugins',
		'data_removal'      => array(
			'describe' => 'npmp_tec_hub_data_lines',
			'blockers' => 'npmp_tec_hub_blockers',
			'batch'    => 'npmp_tec_hub_remove_batch',
			'keeps'    => __( 'Your imported events, their categories and featured images, and the redirects from old links all stay.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * TEC and every installed add-on that needs it, basename => name.
 *
 * @return array<string,string>
 */
function npmp_tec_hub_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		if ( 'the-events-calendar' === $folder || npmp_tec_plugin_needs_tec( $file, $data ) ) {
			$out[ $file ] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * Has an import finished? Counts events from every admin, plus your own run
 * when you moved settings and no events.
 *
 * @return bool
 */
function npmp_tec_hub_is_imported() {
	if ( (int) get_option( 'npmp_tec_imported', 0 ) > 0 ) {
		return true;
	}
	$stage = npmp_tec_get_stage();
	return ! empty( $stage['migrated'] );
}

/**
 * The importer reads through TEC, so it needs TEC on.
 *
 * @return true|string
 */
function npmp_tec_hub_can_import() {
	if ( npmp_tec_is_active() ) {
		return true;
	}
	if ( npmp_tec_plugin_file() ) {
		return __( 'Turn The Events Calendar back on from Plugins to import. Its events are still in your database.', 'nonprofit-manager' );
	}
	return __( 'You need The Events Calendar installed and on to import its events, so install it again, import, and come back to this screen.', 'nonprofit-manager' );
}

/**
 * One line for a finished import.
 *
 * @return string
 */
function npmp_tec_hub_import_summary() {
	$moved = (int) get_option( 'npmp_tec_imported', 0 );
	if ( ! $moved ) {
		return __( 'You brought over the calendar settings.', 'nonprofit-manager' );
	}
	return sprintf(
		/* translators: %s: number of events. */
		_n( '%s event came over. Links to its old page go to the new one.', '%s events came over. Links to their old pages go to the new ones.', $moved, 'nonprofit-manager' ),
		number_format_i18n( $moved )
	);
}

/**
 * Count TEC posts of one type, every status but auto-drafts.
 *
 * @param string $type Post type.
 * @return int
 */
function npmp_tec_hub_count_type( $type ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','trash')", $type ) );
}

/**
 * Count rows in a taxonomy straight from the database, so it works with the
 * taxonomy unregistered.
 *
 * @param string $taxonomy Taxonomy.
 * @return int
 */
function npmp_tec_hub_count_terms( $taxonomy ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_tec_hub_counts() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	$events = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_tec_source_id' AND p.post_type = 'npmp_event' AND p.post_status NOT IN ('auto-draft','trash')" );
	$links  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_tec_old_path' AND p.post_type = 'npmp_event'" );
	// Categories on imported events only, so ones you made here yourself don't count.
	$cats = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tt.term_id) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = '_npmp_tec_source_id' WHERE tt.taxonomy = 'npmp_event_category'" );
	// phpcs:enable

	return array(
		array(
			'label' => __( 'Events', 'nonprofit-manager' ),
			'old'   => npmp_tec_hub_count_type( 'tribe_events' ),
			'new'   => $events,
			'note'  => __( 'You get one event here for each date of a repeating event, minus any events you unchecked.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Event categories', 'nonprofit-manager' ),
			'old'   => npmp_tec_hub_count_terms( 'tribe_events_cat' ),
			'new'   => $cats,
			'note'  => __( 'Only categories with an imported event come over.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Venues', 'nonprofit-manager' ),
			'old'   => npmp_tec_hub_count_type( 'tribe_venue' ),
			'new'   => null,
			'note'  => __( 'Each venue becomes the location line on its events.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Old event links that redirect', 'nonprofit-manager' ),
			'old'   => null,
			'new'   => $links,
			'note'  => '',
		),
	);
}

/**
 * Does TEC have anything left in the database?
 *
 * @return bool
 */
function npmp_tec_hub_has_data() {
	global $wpdb;
	$types = npmp_tec_hub_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	$any = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) LIMIT 1", $types ) );
	if ( $any ) {
		return true;
	}
	if ( npmp_tec_hub_count_terms( 'tribe_events_cat' ) ) {
		return true;
	}
	if ( false !== get_option( 'tribe_events_calendar_options', false ) ) {
		return true;
	}
	return (bool) npmp_tec_hub_existing_tables();
}

/**
 * Which of TEC's tables exist, with the site prefix.
 *
 * @return string[]
 */
function npmp_tec_hub_existing_tables() {
	global $wpdb;
	$out = array();
	foreach ( npmp_tec_hub_tables() as $table ) {
		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		if ( $name === $found ) {
			$out[] = $name;
		}
	}
	return $out;
}

/**
 * TEC's options on this site.
 *
 * @return string[]
 */
function npmp_tec_hub_option_names() {
	global $wpdb;
	$like  = array_map(
		static function ( $part ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( $part ) . '%';
		},
		array( 'tribe', 'tec', 'the-events-calendar', 'the_events_calendar' )
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One read before deleting.
	$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like ) );
	return array_values( array_filter( $names, 'npmp_tec_hub_option_is_tec' ) );
}

/**
 * Plugins that must go before TEC's data can, by name.
 *
 * @return string[]
 */
function npmp_tec_hub_blockers() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( npmp_tec_hub_shares_data( $file, $data ) ) {
			$out[] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * What step 5 deletes, in plain words.
 *
 * @return string[]
 */
function npmp_tec_hub_data_lines() {
	global $wpdb;
	$types = npmp_tec_hub_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	$posts  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in)", $types ) );
	$terms  = npmp_tec_hub_count_terms( 'tribe_events_cat' );
	$opts   = count( npmp_tec_hub_option_names() );
	$tables = count( npmp_tec_hub_existing_tables() );

	return array(
		sprintf(
			/* translators: %s: number of records. */
			_n( '%s event, venue, or organizer record from The Events Calendar, with its details and revisions', '%s event, venue, and organizer records from The Events Calendar, with their details and revisions', $posts, 'nonprofit-manager' ),
			number_format_i18n( $posts )
		),
		sprintf(
			/* translators: %s: number of categories. */
			_n( '%s of its event categories', '%s of its event categories', $terms, 'nonprofit-manager' ),
			number_format_i18n( $terms )
		),
		sprintf(
			/* translators: %s: number of settings. */
			_n( '%s saved setting', '%s saved settings', $opts, 'nonprofit-manager' ),
			number_format_i18n( $opts )
		),
		sprintf(
			/* translators: %s: number of tables. */
			_n( '%s database table', '%s database tables', $tables, 'nonprofit-manager' ),
			number_format_i18n( $tables )
		),
	);
}

/**
 * Delete the next batch of TEC data.
 *
 * Stateless: each call looks at what's left and deletes the next piece, posts
 * first, then categories, then settings, cron hooks, and tables. A request
 * cut off halfway picks up where it stopped.
 *
 * @param int $limit Posts per batch.
 * @return array{done:bool,removed:int,phase:string}
 */
function npmp_tec_hub_remove_batch( $limit = 50 ) {
	global $wpdb;

	$types = npmp_tec_hub_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( $types, array( max( 1, (int) $limit ) ) ) ) ) );
	if ( $ids ) {
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			} else {
				// Never loop forever on a post WordPress won't delete.
				$wpdb->delete( $wpdb->posts, array( 'ID' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				++$removed;
			}
		}
		return array(
			'done'    => false,
			'removed' => $removed,
			'phase'   => 'posts',
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read straight from the table, since TEC is gone and its taxonomy isn't registered.
	$term_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'tribe_events_cat' ORDER BY term_id DESC LIMIT 100" ) );
	if ( $term_ids ) {
		if ( ! taxonomy_exists( 'tribe_events_cat' ) ) {
			// wp_delete_term() needs the taxonomy registered. A private stand-in for this request only.
			register_taxonomy( 'tribe_events_cat', array(), array( 'public' => false, 'hierarchical' => true, 'rewrite' => false ) );
		}
		$removed = 0;
		foreach ( $term_ids as $term_id ) {
			$done = wp_delete_term( $term_id, 'tribe_events_cat' );
			if ( true !== $done ) {
				// Gone or refused: drop its taxonomy row so the loop ends. The term row stays for anything else using it.
				$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $term_id, 'taxonomy' => 'tribe_events_cat' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			++$removed;
		}
		return array(
			'done'    => false,
			'removed' => $removed,
			'phase'   => 'terms',
		);
	}

	$removed = 0;
	foreach ( npmp_tec_hub_option_names() as $name ) {
		if ( delete_option( $name ) ) {
			++$removed;
		}
	}

	$crons = _get_cron_array();
	foreach ( is_array( $crons ) ? $crons : array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( npmp_tec_hub_cron_is_tec( $hook ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}

	foreach ( npmp_tec_hub_existing_tables() as $table ) {
		// Names come from npmp_tec_hub_tables() with the site prefix, never from input.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		++$removed;
	}

	return array(
		'done'    => true,
		'removed' => $removed,
		'phase'   => 'settings',
	);
}
