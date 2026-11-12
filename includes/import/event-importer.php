<?php
/**
 * File path: includes/import/event-importer.php
 *
 * The importer screen for event plugins that aren't The Events Calendar
 * (Events Manager, Sugar Calendar). TEC keeps its own staged importer
 * (tec-importer.php). This one reuses TEC's helpers for times, categories,
 * and paths, and writes the same npmp_event posts.
 *
 * An event source registers on the npmp_event_import_sources filter:
 *
 *     $sources['events-manager'] = array(
 *         'name'     => 'Events Manager',
 *         'has_data' => callable, bool.
 *         'counts'   => callable, array{events:int,dates:int,categories:int,venues:int,skipped:int}.
 *         'batch'    => callable( int $after, int $limit ): items, each
 *                       array{cursor:int,key:string,source_id:int,title:string,content:string,
 *                       excerpt:string,status:string,author:int,post_name:string,start:string,end:string,
 *                       all_day:bool,location:string,url:string,thumbnail:int,categories:array[],paths:string[]}.
 *                       A category with 'assign' => false is a parent that only keeps the tree whole
 *                       (npmp_event_import_post_terms()).
 *                       start and end are site time, Y-m-d H:i:s (npmp_tec_event_times()).
 *                       cursor moves past the item. A series gives one item per date.
 *                       An item with 'skip' => true only moves the cursor.
 *         'settings' => callable, array of npmp_tec_settings_map() key => value this plugin would save.
 *         'series'   => false when the source brings one date per event (no repeating dates).
 *         'venues'   => false when the source has a location line but no venues.
 *     );
 *
 * A second run skips every event already here (_npmp_import_key). Each old
 * public URL gets a 301 to its new event (_npmp_import_old_path).
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_EVENT_IMPORT_SECONDS' ) ) {
	define( 'NPMP_EVENT_IMPORT_SECONDS', 20 );
}

/**
 * Registered event sources.
 *
 * @return array<string,array>
 */
function npmp_event_import_sources() {
	$out = array();
	foreach ( (array) apply_filters( 'npmp_event_import_sources', array() ) as $slug => $source ) {
		if ( is_string( $slug ) && preg_match( '/^[a-z0-9_-]{1,40}$/', $slug ) && is_array( $source ) && ! empty( $source['name'] ) && is_callable( $source['batch'] ?? null ) ) {
			$source['slug'] = $slug;
			$out[ $slug ]   = $source;
		}
	}
	return $out;
}

/**
 * Empty results.
 *
 * @return array<string,int>
 */
function npmp_event_import_empty_results() {
	return array(
		'created'  => 0,
		'exists'   => 0,
		'invalid'  => 0,
		'paths'    => 0,
		'settings' => 0,
	);
}

/**
 * Old public path of an event post.
 *
 * @param string $slug      dbem_cp_events_slug.
 * @param string $post_name Post slug.
 * @return string
 */
function npmp_event_import_old_path( $slug, $post_name ) {
	$slug = trim( (string) $slug, '/' );
	if ( '' === trim( (string) $post_name ) ) {
		return '';
	}
	return npmp_tec_normalize_path( '/' . ( '' !== $slug ? $slug : 'events' ) . '/' . rawurldecode( (string) $post_name ) . '/' );
}

/**
 * A post's terms in one taxonomy, plus every parent above them, read from the
 * term tables so it works with the old plugin off. Parents carry
 * 'assign' => false: they come over so the tree stays whole, but the event
 * only gets the terms it had.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 * @return array[] slug, name, parent, description, assign.
 */
function npmp_event_import_post_terms( $post_id, $taxonomy ) {
	global $wpdb;
	if ( ! $post_id ) {
		return array();
	}
	$select = "SELECT t.slug, t.name, tt.description, tt.parent AS parent_id, pt.slug AS parent FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id LEFT JOIN {$wpdb->terms} pt ON pt.term_id = tt.parent AND tt.parent > 0";
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin import, table names from $wpdb.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "{$select} INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s", (int) $post_id, $taxonomy ), ARRAY_A );
	$out  = array();
	$seen = array();
	$todo = array();
	foreach ( $rows as $row ) {
		$seen[ $row['slug'] ] = true;
		$out[]                = $row + array( 'assign' => true );
		if ( (int) $row['parent_id'] ) {
			$todo[] = (int) $row['parent_id'];
		}
	}
	for ( $depth = 0; $todo && $depth < 5; $depth++ ) {
		$next = array();
		foreach ( array_unique( $todo ) as $term_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "{$select} WHERE tt.term_id = %d AND tt.taxonomy = %s", $term_id, $taxonomy ), ARRAY_A );
			if ( ! $row || isset( $seen[ $row['slug'] ] ) ) {
				continue;
			}
			$seen[ $row['slug'] ] = true;
			$out[]                = $row + array( 'assign' => false );
			if ( (int) $row['parent_id'] ) {
				$next[] = (int) $row['parent_id'];
			}
		}
		$todo = $next;
	}
	// phpcs:enable
	return array_map(
		function ( $row ) {
			return array(
				'slug'        => (string) $row['slug'],
				'name'        => html_entity_decode( (string) $row['name'], ENT_QUOTES, 'UTF-8' ),
				'parent'      => (string) ( $row['parent'] ?? '' ),
				'description' => (string) $row['description'],
				'assign'      => (bool) $row['assign'],
			);
		},
		$out
	);
}

/**
 * Saved progress for one source.
 *
 * @param string $slug Source slug.
 * @return array{cursor:int,running:bool,finished_at:int,results:array}
 */
function npmp_event_import_state( $slug ) {
	$state = get_option( 'npmp_event_import_' . $slug, array() );
	$state = is_array( $state ) ? $state : array();
	return array(
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'results'     => array_merge( npmp_event_import_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param string $slug  Source slug.
 * @param array  $state State.
 * @return void
 */
function npmp_event_import_save_state( $slug, array $state ) {
	update_option( 'npmp_event_import_' . $slug, $state, false );
}

/**
 * Find an event you already brought over.
 *
 * @param string $key Import key.
 * @return int
 */
function npmp_event_import_find( $key ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup per event.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_import_key' AND pm.meta_value = %s AND p.post_type = 'npmp_event' LIMIT 1", (string) $key ) );
}

/**
 * Write one event.
 *
 * @param string $slug Source slug.
 * @param array  $item From a source batch.
 * @return string created, exists, or invalid.
 */
function npmp_event_import_item( $slug, array $item ) {
	$key = preg_replace( '/[^a-z0-9_:@.-]/i', '', $slug . ':' . (string) ( $item['key'] ?? '' ) );
	if ( npmp_event_import_find( $key ) ) {
		return 'exists';
	}
	if ( '' === (string) ( $item['start'] ?? '' ) || '' === trim( (string) ( $item['title'] ?? '' ) ) ) {
		return 'invalid';
	}
	$status = in_array( $item['status'] ?? '', array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ? $item['status'] : 'draft';
	$meta   = array(
		'_npmp_event_start'    => (string) $item['start'],
		'_npmp_event_end'      => (string) ( $item['end'] ?: $item['start'] ),
		'_npmp_import_key'     => $key,
		'_npmp_import_source'  => $slug,
	);
	if ( ! empty( $item['all_day'] ) ) {
		$meta['_npmp_event_all_day'] = 1;
	}
	if ( '' !== (string) ( $item['location'] ?? '' ) ) {
		$meta['_npmp_event_location'] = sanitize_text_field( (string) $item['location'] );
	}
	if ( '' !== (string) ( $item['url'] ?? '' ) ) {
		$meta['_npmp_event_url'] = esc_url_raw( (string) $item['url'] );
	}
	if ( ! empty( $item['thumbnail'] ) && get_post( (int) $item['thumbnail'] ) ) {
		$meta['_thumbnail_id'] = (int) $item['thumbnail'];
	}

	$new_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => 'npmp_event',
				'post_status'    => $status,
				'post_title'     => (string) $item['title'],
				'post_content'   => (string) ( $item['content'] ?? '' ),
				'post_excerpt'   => (string) ( $item['excerpt'] ?? '' ),
				'post_author'    => (int) ( $item['author'] ?? 0 ),
				'post_name'      => sanitize_title( (string) ( $item['post_name'] ?? '' ) ),
				'comment_status' => 'closed',
				'meta_input'     => $meta,
			)
		),
		true
	);
	if ( is_wp_error( $new_id ) || ! $new_id ) {
		return 'invalid';
	}
	foreach ( array_unique( (array) ( $item['paths'] ?? array() ) ) as $path ) {
		if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
			add_post_meta( $new_id, '_npmp_import_old_path', $path );
		}
	}

	$cats    = (array) ( $item['categories'] ?? array() );
	$by_slug = array();
	foreach ( $cats as $cat ) {
		$by_slug[ $cat['slug'] ] = $cat;
	}
	$terms = array();
	foreach ( $cats as $cat ) {
		$id = npmp_tec_ensure_term( $cat, $by_slug );
		if ( $id && ( $cat['assign'] ?? true ) ) {
			$terms[] = $id;
		}
	}
	if ( $terms ) {
		wp_set_object_terms( $new_id, $terms, 'npmp_event_category' );
	}
	return 'created';
}

/**
 * Copy the source's calendar settings where yours are still on the default.
 *
 * @param array $source Source.
 * @return int Settings copied.
 */
function npmp_event_import_settings( array $source ) {
	if ( ! is_callable( $source['settings'] ?? null ) ) {
		return 0;
	}
	$map    = npmp_tec_settings_map();
	$copied = 0;
	foreach ( (array) call_user_func( $source['settings'] ) as $id => $value ) {
		if ( ! isset( $map[ $id ] ) ) {
			continue;
		}
		$value = npmp_tec_convert_setting( $id, $value );
		if ( null === $value ) {
			continue;
		}
		$current = get_option( $map[ $id ]['target'], null );
		if ( npmp_tec_setting_on_default( $id, $current ) && $value !== $map[ $id ]['default'] ) {
			update_option( $map[ $id ]['target'], $value );
			++$copied;
		}
	}
	return $copied;
}

/**
 * Run batches until the source runs out or the time is up.
 *
 * @param string $slug    Source slug.
 * @param int    $seconds Time budget.
 * @param int    $limit   Items per batch.
 * @return array State after the run.
 */
function npmp_event_import_run( $slug, $seconds = NPMP_EVENT_IMPORT_SECONDS, $limit = 25 ) {
	$sources = npmp_event_import_sources();
	if ( ! isset( $sources[ $slug ] ) ) {
		return npmp_event_import_state( $slug );
	}
	$source = $sources[ $slug ];
	$state  = npmp_event_import_state( $slug );
	if ( ! $state['running'] ) {
		npmp_tec_ensure_calendar_module();
		$state = array(
			'cursor'      => 0,
			'running'     => true,
			'finished_at' => $state['finished_at'],
			'results'     => npmp_event_import_empty_results(),
		);
		$state['results']['settings'] = npmp_event_import_settings( $source );
	}

	$start = microtime( true );
	do {
		$items = (array) call_user_func( $source['batch'], $state['cursor'], $limit );
		foreach ( $items as $item ) {
			if ( ! empty( $item['skip'] ) ) {
				// A row the source leaves behind (a template, a trashed event).
				$state['cursor'] = max( $state['cursor'], (int) ( $item['cursor'] ?? 0 ) );
				continue;
			}
			$status = npmp_event_import_item( $slug, (array) $item );
			++$state['results'][ $status ];
			if ( 'created' === $status ) {
				$state['results']['paths'] += count( (array) ( $item['paths'] ?? array() ) );
			}
			$state['cursor'] = max( $state['cursor'], (int) ( $item['cursor'] ?? 0 ) );
		}
		$finished = count( $items ) < $limit;
		npmp_event_import_save_state( $slug, $state );
	} while ( ! $finished && ( microtime( true ) - $start ) < $seconds );

	if ( $finished ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		npmp_event_import_save_state( $slug, $state );
		update_option( 'npmp_event_import_redirects', 1, true );
	}
	return $state;
}

/**
 * Events you brought over from one source.
 *
 * @param string $slug Source slug.
 * @return array{events:int,paths:int,categories:int}
 */
function npmp_event_import_imported_counts( $slug ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	$events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_import_source' AND pm.meta_value = %s AND p.post_type = 'npmp_event' AND p.post_status NOT IN ('trash','auto-draft')", $slug ) );
	$paths  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} op INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = op.post_id AND pm.meta_key = '_npmp_import_source' AND pm.meta_value = %s WHERE op.meta_key = '_npmp_import_old_path'", $slug ) );
	$terms  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = '_npmp_import_source' AND pm.meta_value = %s WHERE tt.taxonomy = 'npmp_event_category'", $slug ) );
	// phpcs:enable
	$all = array();
	foreach ( $terms as $term_id ) {
		$all[ (int) $term_id ] = true;
		foreach ( get_ancestors( (int) $term_id, 'npmp_event_category', 'taxonomy' ) as $parent ) {
			$all[ (int) $parent ] = true;
		}
	}
	$cats = count( $all );
	return array( 'events' => $events, 'paths' => $paths, 'categories' => $cats );
}

/**
 * URL of the importer screen.
 *
 * @param string $slug Source slug.
 * @return string
 */
function npmp_event_import_url( $slug ) {
	return add_query_arg( array( 'source' => $slug ), admin_url( 'admin.php?page=npmp_event_import' ) );
}

// =====================================================================
// Redirects from old event URLs
// =====================================================================

/**
 * The event an old path belongs to.
 *
 * @param string $path From npmp_tec_normalize_path().
 * @return int
 */
function npmp_event_import_find_redirect( $path ) {
	global $wpdb;
	$cache_key = 'path_' . md5( $path );
	$found     = wp_cache_get( $cache_key, 'npmp_event_import_redirect' );
	if ( false !== $found ) {
		return (int) $found;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Cached just above.
	$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_npmp_import_old_path' AND pm.meta_value = %s AND p.post_type = 'npmp_event' AND p.post_status = 'publish' ORDER BY pm.post_id ASC LIMIT 1", $path ) );
	wp_cache_set( $cache_key, $found, 'npmp_event_import_redirect', HOUR_IN_SECONDS );
	return $found;
}

/**
 * Send a 301 from an old event URL to its new event, once the old plugin no
 * longer answers it.
 *
 * @return void
 */
function npmp_event_import_maybe_redirect() {
	if ( ! get_option( 'npmp_event_import_redirects' ) || ! is_404() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- npmp_tec_normalize_path() strips it to a path, and the lookup is prepared.
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
	$target = npmp_event_import_find_redirect( npmp_tec_normalize_path( $uri, npmp_tec_home_path() ) );
	if ( ! $target ) {
		return;
	}
	$url = get_permalink( $target );
	if ( $url ) {
		wp_safe_redirect( $url, 301, 'Nonprofit Manager' );
		exit;
	}
}
add_action( 'template_redirect', 'npmp_event_import_maybe_redirect', 1 );

// =====================================================================
// Hub source
// =====================================================================

/**
 * The hub source both event plugins share.
 *
 * @param string   $slug      Slug.
 * @param string   $name      Plugin name.
 * @param string[] $folders   Plugin folders.
 * @param callable $installed Installed plugins callable.
 * @param string[] $checklist Verify checklist.
 * @param string   $reason    Why step 5 is unavailable.
 * @return array
 */
function npmp_event_hub_source( $slug, $name, array $folders, $installed, array $checklist, $reason ) {
	return array(
		'name'              => $name,
		'covers'            => __( 'Events, venues, and categories', 'nonprofit-manager' ),
		'feature'           => __( 'Events', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array(),
		'plugin_folders'    => $folders,
		'has_data'          => function () use ( $slug ) {
			$sources = npmp_event_import_sources();
			return isset( $sources[ $slug ] ) && (bool) call_user_func( $sources[ $slug ]['has_data'] );
		},
		'is_imported'       => function () use ( $slug ) {
			return npmp_event_import_state( $slug )['finished_at'] > 0;
		},
		'can_import'        => true,
		'import_url'        => function () use ( $slug ) {
			return npmp_event_import_url( $slug );
		},
		'import_summary'    => function () use ( $slug ) {
			$n = npmp_event_import_imported_counts( $slug );
			return sprintf(
				/* translators: 1: number of events, 2: number of old links. */
				__( 'You have %1$s events here, and %2$s old links lead to the new events.', 'nonprofit-manager' ),
				number_format_i18n( $n['events'] ),
				number_format_i18n( $n['paths'] )
			);
		},
		'counts'            => function () use ( $slug ) {
			$sources = npmp_event_import_sources();
			$old     = isset( $sources[ $slug ] ) ? (array) call_user_func( $sources[ $slug ]['counts'] ) : array();
			$new     = npmp_event_import_imported_counts( $slug );
			$series  = $sources[ $slug ]['series'] ?? true;
			$rows    = array(
				array(
					'label' => __( 'Event dates', 'nonprofit-manager' ),
					'old'   => (int) ( $old['dates'] ?? 0 ),
					'new'   => $new['events'],
					'note'  => $series ? __( 'You get one event here for each date of a repeating event.', 'nonprofit-manager' ) : '',
				),
				array(
					'label' => __( 'Event categories', 'nonprofit-manager' ),
					'old'   => (int) ( $old['categories'] ?? 0 ),
					'new'   => $new['categories'],
					'note'  => __( 'Categories come over with their events and their parent categories. Categories with no events stay in the old plugin.', 'nonprofit-manager' ),
				),
				( $sources[ $slug ]['venues'] ?? true ) ? array(
					'label' => __( 'Venues', 'nonprofit-manager' ),
					'old'   => (int) ( $old['venues'] ?? 0 ),
					'new'   => null,
					'note'  => __( 'Each venue becomes the location line on its events.', 'nonprofit-manager' ),
				) : null,
				array(
					'label' => __( 'Old event links that redirect', 'nonprofit-manager' ),
					'old'   => null,
					'new'   => $new['paths'],
					'note'  => '',
				),
			);
			return array_values( array_filter( $rows ) );
		},
		'checklist'         => $checklist,
		'installed_plugins' => $installed,
		'data_removal'      => array( 'reason' => $reason ),
	);
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_event_import_register_menu', 30 );
add_action( 'admin_head', 'npmp_event_import_hide_menu' );
add_action( 'admin_post_npmp_event_import', 'npmp_event_import_post' );
add_action( 'admin_enqueue_scripts', 'npmp_event_import_enqueue' );

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_event_import_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import events', 'nonprofit-manager' ),
		__( 'Import events', 'nonprofit-manager' ),
		'manage_options',
		'npmp_event_import',
		'npmp_event_import_render_page'
	);
}

/**
 * Take the screen out of the menu after WordPress checked access to it
 * (removing it on admin_menu makes WordPress refuse the screen).
 *
 * @return void
 */
function npmp_event_import_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_event_import' );
}

/**
 * The hub's styles and its carry-on script.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_event_import_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_event_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Handle the Import button and each carry-on request.
 *
 * @return void
 */
function npmp_event_import_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	$slug = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	check_admin_referer( 'npmp_event_import_' . $slug, 'npmp_event_nonce' );
	if ( ! isset( npmp_event_import_sources()[ $slug ] ) ) {
		wp_die( esc_html__( 'We can\'t import events from that plugin.', 'nonprofit-manager' ), 400 );
	}
	if ( ! empty( $_POST['restart'] ) ) {
		$state            = npmp_event_import_state( $slug );
		$state['running'] = false;
		npmp_event_import_save_state( $slug, $state );
	}
	npmp_event_import_run( $slug );
	wp_safe_redirect( npmp_event_import_url( $slug ) );
	exit;
}

/**
 * Hidden fields.
 *
 * @param string $slug Source slug.
 * @return void
 */
function npmp_event_import_form_fields( $slug ) {
	echo '<input type="hidden" name="action" value="npmp_event_import">';
	echo '<input type="hidden" name="source" value="' . esc_attr( $slug ) . '">';
	wp_nonce_field( 'npmp_event_import_' . $slug, 'npmp_event_nonce' );
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_event_import_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$slug    = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
	$sources = npmp_event_import_sources();
	echo '<div class="wrap npmp-hub-wrap">';
	if ( ! isset( $sources[ $slug ] ) ) {
		echo '<h1>' . esc_html__( 'Import events', 'nonprofit-manager' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pick the plugin you\'re switching from on the Switch Plugins screen.', 'nonprofit-manager' ) . ' <a href="' . esc_url( npmp_import_hub_url() ) . '">' . esc_html__( 'Switch Plugins', 'nonprofit-manager' ) . '</a></p></div>';
		return;
	}
	$source = $sources[ $slug ];
	$state  = npmp_event_import_state( $slug );
	$counts = array_merge( array( 'events' => 0, 'dates' => 0, 'categories' => 0, 'venues' => 0, 'skipped' => 0 ), (array) call_user_func( $source['counts'] ) );

	/* translators: %s: old plugin name. */
	echo '<h1>' . esc_html( sprintf( __( 'Import events from %s', 'nonprofit-manager' ), $source['name'] ) ) . '</h1>';
	echo '<p><a href="' . esc_url( npmp_import_hub_url( $slug ) ) . '">&larr; ' . esc_html__( 'Back to Switch Plugins', 'nonprofit-manager' ) . '</a></p>';

	if ( $state['running'] ) {
		npmp_event_import_render_results( $state['results'] );
		echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
		npmp_event_import_form_fields( $slug );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form></div>';
		return;
	}
	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_event_import_render_results( $state['results'] );
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( $slug ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a></p>';
	}

	echo '<h2>' . esc_html__( 'What comes over', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: %s: number of events. */
			_n( '%s event, with its dates, times, description, and featured image.', '%s events, each with its dates, times, description, and featured image.', $counts['events'], 'nonprofit-manager' ),
			number_format_i18n( $counts['events'] )
		)
	);
	if ( $source['series'] ?? true ) {
		echo ' ' . esc_html(
			sprintf(
				/* translators: %s: number of dates. */
				_n( 'You get %s event date here, one per date of a repeating event.', 'You get %s event dates here, one per date of a repeating event.', $counts['dates'], 'nonprofit-manager' ),
				number_format_i18n( $counts['dates'] )
			)
		);
	}
	echo '</li>';
	if ( $source['venues'] ?? true ) {
		echo '<li>' . esc_html__( 'Each venue becomes the location line on its events, and each category comes over with its events.', 'nonprofit-manager' ) . '</li>';
	} else {
		echo '<li>' . esc_html__( 'Each event keeps its location line and its categories.', 'nonprofit-manager' ) . '</li>';
	}
	echo '<li>' . esc_html__( 'Each old event link leads to the new event once you turn the old plugin off.', 'nonprofit-manager' ) . '</li>';
	if ( is_callable( $source['settings'] ?? null ) ) {
		echo '<li>' . esc_html__( 'Your calendar\'s default view and list length, where yours are still on the default.', 'nonprofit-manager' ) . '</li>';
	}
	echo '</ul>';

	echo '<h2>' . esc_html__( 'What you leave in the old plugin', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Bookings, tickets, and attendee lists.', 'nonprofit-manager' ) . '</li>';
	if ( $counts['skipped'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of events. */
				_n( '%s event in the trash or with no start date.', '%s events in the trash or with no start date.', $counts['skipped'], 'nonprofit-manager' ),
				number_format_i18n( $counts['skipped'] )
			)
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'Map keys, licence keys, and payment settings.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_event_import_form_fields( $slug );
	echo '<input type="hidden" name="restart" value="1">';
	echo '<p><button type="submit" class="button button-primary">' . esc_html( $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import events', 'nonprofit-manager' ) ) . '</button></p>';
	if ( $state['finished_at'] ) {
		echo '<p class="description">' . esc_html__( 'Running it again adds only events you don\'t have here yet.', 'nonprofit-manager' ) . '</p>';
	}
	echo '</form></div>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_event_import_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of events. */
		sprintf( __( 'New events here: %s', 'nonprofit-manager' ), number_format_i18n( $r['created'] ) ),
		/* translators: %s: number of events. */
		sprintf( __( 'Events you already had: %s', 'nonprofit-manager' ), number_format_i18n( $r['exists'] ) ),
		/* translators: %s: number of links. */
		sprintf( __( 'Old links that lead to the new events: %s', 'nonprofit-manager' ), number_format_i18n( $r['paths'] ) ),
	);
	if ( $r['settings'] ) {
		/* translators: %s: number of settings. */
		$lines[] = sprintf( __( 'Calendar settings copied: %s', 'nonprofit-manager' ), number_format_i18n( $r['settings'] ) );
	}
	if ( $r['invalid'] ) {
		/* translators: %s: number of events. */
		$lines[] = sprintf( __( 'Events we skipped for a missing title or start: %s', 'nonprofit-manager' ), number_format_i18n( $r['invalid'] ) );
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	echo '</ul>';
}
