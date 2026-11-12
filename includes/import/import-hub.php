<?php
/**
 * File path: includes/import/import-hub.php
 *
 * The Switch Plugins screen: one place to move off every plugin Nonprofit
 * Manager can replace, one plugin and one step at a time.
 *
 * Each old plugin is a "source" in npmp_import_sources(). Every source walks
 * the same five steps in order, and a step opens only once the one before it
 * is done:
 *
 *   1. Import         Bring over data and settings (the source's own importer).
 *   2. Verify         Old counts next to ours, a checklist, and a box you tick.
 *   3. Deactivate     Turn the old plugin (and its add-ons) off.
 *   4. Remove plugin  Delete its files through WordPress's delete_plugins().
 *   5. Remove data    Delete what the source declares, in batches. The only
 *                     step that can't be undone.
 *
 * Import and Verify can run again. Steps 3 and 4 read their state from the
 * plugin list, so turning the old plugin back on from Plugins reopens them.
 *
 * Adding a source (MailPoet and the rest plug in here):
 *
 *     add_filter( 'npmp_import_sources', function ( $sources ) {
 *         $sources['mailpoet'] = array(
 *             'name'           => 'MailPoet',
 *             'covers'         => __( 'Subscribers, lists, and sender settings', 'nonprofit-manager' ),
 *             'feature'        => __( 'Contacts and newsletters', 'nonprofit-manager' ),
 *             'tier'           => 'free',                         // 'pro' locks the whole source in free.
 *             'pro_parts'      => array( __( 'Lists as groups', 'nonprofit-manager' ) ),
 *             'plugin_folders' => array( 'mailpoet', 'mailpoet-premium' ),
 *             'has_data'       => 'npmp_mailpoet_has_data',       // bool: anything left in the database?
 *             'is_imported'    => 'npmp_mailpoet_is_imported',    // bool: has an import finished?
 *             'can_import'     => 'npmp_mailpoet_can_import',     // true, or a string saying why not.
 *             'import_url'     => 'npmp_mailpoet_page_url',       // string or callable: the importer screen.
 *             'counts'         => 'npmp_mailpoet_hub_counts',     // rows of label, old, new, note.
 *             'checklist'      => array( ... ),                   // strings.
 *             'data_removal'   => array(
 *                 'describe' => 'npmp_mailpoet_data_lines',     // strings: what step 5 deletes.
 *                 'blockers' => 'npmp_mailpoet_data_blockers',  // names of plugins that share the data.
 *                 'batch'    => 'npmp_mailpoet_remove_batch',   // array{done:bool,removed:int,phase:string}.
 *                 'keeps'    => __( 'What stays', 'nonprofit-manager' ), // one line under the list.
 *             ),
 *         );
 *         return $sources;
 *     } );
 *
 * Optional: 'deactivate' (callable, returns what it turned off), 'active_plugins'
 * and 'installed_plugins' (callables returning basename => name) when a source
 * has add-ons, 'import_summary' (callable returning a sentence), and
 * 'delete_note' (callable returning a warning, or '') for a plugin whose own
 * uninstall deletes its data when step 4 deletes its files. Leave
 * 'data_removal' out, or give it 'reason' and no 'batch', and step 5 shows as
 * unavailable with that reason.
 *
 * Pure helpers sit at the top and need no database (tests/test-import-hub.php).
 * The real-WordPress run is tests/rig/import-hub.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Seconds one request spends deleting old data before it hands back. */
if ( ! defined( 'NPMP_IMPORT_HUB_SECONDS' ) ) {
	define( 'NPMP_IMPORT_HUB_SECONDS', 20 );
}

// =====================================================================
// Pure helpers (no database), covered by tests/test-import-hub.php
// =====================================================================

/**
 * The five steps, in order.
 *
 * @return string[]
 */
function npmp_import_hub_step_ids() {
	return array( 'import', 'verify', 'deactivate', 'remove_plugin', 'remove_data' );
}

/**
 * Fill in a source's defaults and drop one that can't work.
 *
 * @param string $slug   Source key.
 * @param mixed  $source Source as registered.
 * @return array|null Normalised source, or null when it's unusable.
 */
function npmp_import_hub_normalize_source( $slug, $source ) {
	$slug = is_string( $slug ) ? strtolower( $slug ) : '';
	if ( '' === $slug || ! preg_match( '/^[a-z0-9_-]{1,40}$/', $slug ) || ! is_array( $source ) ) {
		return null;
	}
	if ( empty( $source['name'] ) || ! is_string( $source['name'] ) ) {
		return null;
	}

	$source = array_merge(
		array(
			'name'              => '',
			'covers'            => '',
			'feature'           => '',
			'tier'              => 'free',
			'pro_parts'         => array(),
			'plugin_folders'    => array(),
			'has_data'          => null,
			'is_imported'       => null,
			'can_import'        => null,
			'import_url'        => '',
			'import_summary'    => null,
			'counts'            => null,
			'checklist'         => array(),
			'deactivate'        => null,
			'active_plugins'    => null,
			'installed_plugins' => null,
			'delete_note'       => null,
			'data_removal'      => array(),
		),
		$source
	);

	$source['slug']           = $slug;
	$source['tier']           = 'pro' === $source['tier'] ? 'pro' : 'free';
	$source['pro_parts']      = array_values( array_filter( (array) $source['pro_parts'], 'is_string' ) );
	$source['checklist']      = array_values( array_filter( (array) $source['checklist'], 'is_string' ) );
	$source['plugin_folders'] = array_values(
		array_filter(
			array_map( 'strval', (array) $source['plugin_folders'] ),
			static function ( $folder ) {
				return (bool) preg_match( '/^[a-z0-9_.-]+$/i', $folder );
			}
		)
	);

	$removal = is_array( $source['data_removal'] ) ? $source['data_removal'] : array();
	$removal = array_merge(
		array(
			'describe' => null,
			'blockers' => null,
			'batch'    => null,
			'reason'   => '',
			'keeps'    => '',
		),
		$removal
	);
	$removal['available']   = is_callable( $removal['batch'] );
	$source['data_removal'] = $removal;

	return $source;
}

/**
 * Plain-words detection state.
 *
 * @param array $facts installed, active, has_data.
 * @return string 'active', 'inactive', 'data', or 'none'.
 */
function npmp_import_hub_detect_state( array $facts ) {
	if ( ! empty( $facts['active'] ) ) {
		return 'active';
	}
	if ( ! empty( $facts['installed'] ) ) {
		return 'inactive';
	}
	if ( ! empty( $facts['has_data'] ) ) {
		return 'data';
	}
	return 'none';
}

/**
 * The state of every step.
 *
 * 'done' and 'ready' can run (Import and Verify again once done), 'locked'
 * waits on an earlier step, 'blocked' can't start yet for a reason the source
 * gives (the old plugin is off, so there's nothing to read), and 'unavailable'
 * means this source has no safe way to do the step.
 *
 * @param array $facts imported, can_import, verified, active, installed,
 *                     data_removed, removal_available.
 * @return array<string,string> Step ID => state.
 */
function npmp_import_hub_step_states( array $facts ) {
	$f = array_merge(
		array(
			'imported'          => false,
			'can_import'        => true,
			'verified'          => false,
			'active'            => false,
			'installed'         => false,
			'data_removed'      => false,
			'removal_available' => false,
		),
		$facts
	);

	$states = array();
	if ( $f['imported'] ) {
		$states['import'] = 'done';
	} else {
		$states['import'] = true === $f['can_import'] ? 'ready' : 'blocked';
	}

	$open = 'done' === $states['import'];
	$states['verify'] = $open ? ( $f['verified'] ? 'done' : 'ready' ) : 'locked';

	$open = $open && 'done' === $states['verify'];
	$states['deactivate'] = $open ? ( $f['active'] ? 'ready' : 'done' ) : 'locked';

	$open = $open && 'done' === $states['deactivate'];
	$states['remove_plugin'] = $open ? ( $f['installed'] ? 'ready' : 'done' ) : 'locked';

	$open = $open && 'done' === $states['remove_plugin'];
	if ( ! $open ) {
		$states['remove_data'] = 'locked';
	} elseif ( $f['data_removed'] ) {
		$states['remove_data'] = 'done';
	} else {
		$states['remove_data'] = $f['removal_available'] ? 'ready' : 'unavailable';
	}

	return $states;
}

/**
 * Can this step run now?
 *
 * @param string $step   Step ID.
 * @param array  $states From npmp_import_hub_step_states().
 * @return bool
 */
function npmp_import_hub_can_run( $step, array $states ) {
	if ( ! isset( $states[ $step ] ) ) {
		return false;
	}
	if ( 'ready' === $states[ $step ] ) {
		return true;
	}
	// Import and Verify can run again.
	return 'done' === $states[ $step ] && in_array( $step, array( 'import', 'verify' ), true );
}

/**
 * The step to show open: the first one not done, or the last when all are.
 *
 * @param array $states From npmp_import_hub_step_states().
 * @return string Step ID.
 */
function npmp_import_hub_current_step( array $states ) {
	foreach ( npmp_import_hub_step_ids() as $id ) {
		if ( isset( $states[ $id ] ) && 'done' !== $states[ $id ] ) {
			return $id;
		}
	}
	return 'remove_data';
}

/**
 * Did the old plugin's data come back after step 5 finished?
 *
 * That happens when someone installs the plugin again and it builds its
 * tables, or when a removal left something behind. The old check and the old
 * removal no longer describe what's in the database, so the hub forgets both:
 * you check again and type the word again before anything new is deleted.
 *
 * @param array $state    Saved hub state for the source.
 * @param bool  $has_data Whether the source has data now.
 * @return bool
 */
function npmp_import_hub_data_returned( array $state, $has_data ) {
	return $has_data && ! empty( $state['removal']['done'] );
}

/**
 * Has every step finished?
 *
 * @param array $states Step states.
 * @return bool
 */
function npmp_import_hub_finished( array $states ) {
	foreach ( npmp_import_hub_step_ids() as $id ) {
		if ( ! isset( $states[ $id ] ) || 'done' !== $states[ $id ] ) {
			return false;
		}
	}
	return true;
}

/**
 * Split sources into the ones on this site and the rest.
 *
 * A source belongs on top while its plugin is installed, its data is still in
 * the database, or you've started moving it (so a finished switch stays
 * visible). Everything else waits in the collapsed group.
 *
 * @param array<string,array> $facts Slug => facts (installed, has_data, touched).
 * @return array{here:string[],other:string[]}
 */
function npmp_import_hub_partition( array $facts ) {
	$out = array(
		'here'  => array(),
		'other' => array(),
	);
	foreach ( $facts as $slug => $f ) {
		$here = ! empty( $f['installed'] ) || ! empty( $f['has_data'] ) || ! empty( $f['touched'] );
		$out[ $here ? 'here' : 'other' ][] = (string) $slug;
	}
	return $out;
}

/**
 * Is a source locked in this copy?
 *
 * @param string $tier   'free' or 'pro'.
 * @param bool   $is_pro Pro active.
 * @return bool
 */
function npmp_import_hub_tier_locked( $tier, $is_pro ) {
	return 'pro' === $tier && ! $is_pro;
}

/**
 * Does the typed confirmation match?
 *
 * Trims and ignores case, so "delete " and "Delete" both count, but an empty
 * box or a near miss never does.
 *
 * @param mixed  $typed What you typed.
 * @param string $word  The word asked for.
 * @return bool
 */
function npmp_import_hub_confirm_matches( $typed, $word ) {
	if ( ! is_string( $typed ) || ! is_string( $word ) ) {
		return false;
	}
	$typed = trim( $typed );
	$word  = trim( $word );
	if ( '' === $word || '' === $typed ) {
		return false;
	}
	return function_exists( 'mb_strtolower' )
		? mb_strtolower( $typed, 'UTF-8' ) === mb_strtolower( $word, 'UTF-8' )
		: strtolower( $typed ) === strtolower( $word );
}

/**
 * Pick plugin basenames out of a plugin list by folder.
 *
 * @param array    $plugins Basename => header data, as get_plugins() returns.
 * @param string[] $folders Folder names.
 * @return array<string,string> Basename => name.
 */
function npmp_import_hub_plugins_in_folders( array $plugins, array $folders ) {
	$out = array();
	foreach ( $plugins as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		if ( in_array( $folder, $folders, true ) && false !== strpos( (string) $file, '/' ) ) {
			$out[ $file ] = isset( $data['Name'] ) ? (string) $data['Name'] : (string) $file;
		}
	}
	return $out;
}

// =====================================================================
// Registry and state
// =====================================================================

/**
 * Every source the hub knows, normalised and keyed by slug.
 *
 * @return array<string,array>
 */
function npmp_import_sources() {
	/**
	 * Sources the Switch Plugins screen lists. See the top of this file for
	 * the keys. Register only sources with a working importer.
	 *
	 * @param array $sources Slug => source.
	 */
	$raw = apply_filters( 'npmp_import_sources', array() );
	$out = array();
	foreach ( (array) $raw as $slug => $source ) {
		$clean = npmp_import_hub_normalize_source( $slug, $source );
		if ( $clean ) {
			$out[ $clean['slug'] ] = $clean;
		}
	}
	return $out;
}

/**
 * One source, or null.
 *
 * @param string $slug Slug.
 * @return array|null
 */
function npmp_import_hub_source( $slug ) {
	$all = npmp_import_sources();
	return $all[ $slug ] ?? null;
}

/**
 * Saved hub state for one source: who verified and when, and how far data
 * removal got. One non-autoloaded option for the whole site, since every step
 * here changes the whole site.
 *
 * @param string $slug Slug.
 * @return array
 */
function npmp_import_hub_get_state( $slug ) {
	$all   = get_option( 'npmp_import_hub', array() );
	$state = is_array( $all ) && isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
	return array_merge(
		array(
			'verified' => array(),
			'removal'  => array(),
		),
		$state
	);
}

/**
 * Save hub state for one source.
 *
 * @param string $slug  Slug.
 * @param array  $state State.
 * @return void
 */
function npmp_import_hub_save_state( $slug, array $state ) {
	$all = get_option( 'npmp_import_hub', array() );
	$all = is_array( $all ) ? $all : array();
	$all[ $slug ] = $state;
	update_option( 'npmp_import_hub', $all, false );
}

/**
 * Call a source callback, or return a default.
 *
 * @param mixed $cb      Callable or anything else.
 * @param mixed $default Value when it isn't callable.
 * @param array $args    Arguments.
 * @return mixed
 */
function npmp_import_hub_call( $cb, $default, array $args = array() ) {
	return is_callable( $cb ) ? call_user_func_array( $cb, $args ) : $default;
}

/**
 * Active plugins that belong to a source, basename => name.
 *
 * @param array $source Source.
 * @return array<string,string>
 */
function npmp_import_hub_active_plugins( array $source ) {
	if ( is_callable( $source['active_plugins'] ) ) {
		return (array) call_user_func( $source['active_plugins'] );
	}
	return array_filter(
		npmp_import_hub_installed_plugins( $source ),
		static function ( $file ) {
			return is_plugin_active( $file );
		},
		ARRAY_FILTER_USE_KEY
	);
}

/**
 * Installed plugins that belong to a source, basename => name. These are the
 * files the Remove plugin step deletes.
 *
 * @param array $source Source.
 * @return array<string,string>
 */
function npmp_import_hub_installed_plugins( array $source ) {
	if ( is_callable( $source['installed_plugins'] ) ) {
		return (array) call_user_func( $source['installed_plugins'] );
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return npmp_import_hub_plugins_in_folders( get_plugins(), $source['plugin_folders'] );
}

/**
 * Everything the step rules need to know about one source right now.
 *
 * @param array $source Source.
 * @return array
 */
function npmp_import_hub_facts( array $source ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$state     = npmp_import_hub_get_state( $source['slug'] );
	$installed = npmp_import_hub_installed_plugins( $source );
	$active    = npmp_import_hub_active_plugins( $source );
	$has_data  = (bool) npmp_import_hub_call( $source['has_data'], false );
	if ( npmp_import_hub_data_returned( $state, $has_data ) ) {
		unset( $state['removal'], $state['verified'] );
		npmp_import_hub_save_state( $source['slug'], $state );
	}
	$imported  = (bool) npmp_import_hub_call( $source['is_imported'], false );
	$can       = npmp_import_hub_call( $source['can_import'], true );

	return array(
		'installed'         => (bool) $installed,
		'active'            => (bool) $active,
		'has_data'          => $has_data,
		'imported'          => $imported,
		'can_import'        => true === $can ? true : ( is_string( $can ) && '' !== $can ? $can : false ),
		'verified'          => ! empty( $state['verified']['at'] ),
		'data_removed'      => ! empty( $state['removal']['done'] ) || ( $imported && ! $has_data ),
		'removal_available' => $source['data_removal']['available'],
		'touched'           => $imported || ! empty( $state['verified'] ) || ! empty( $state['removal'] ),
	);
}

// =====================================================================
// Running a step
// =====================================================================

/**
 * Run one step for one source as the current user.
 *
 * Every refusal lives here, so the form handler and the rig go through the
 * same checks: unknown source, Pro lock, capability, order, and what each
 * step asks of you.
 *
 * @param string $slug  Source slug.
 * @param string $step  Step ID.
 * @param array  $input verified (bool), confirm (string).
 * @return array|WP_Error Result with 'message' (a code) and step details.
 */
function npmp_import_hub_run_step( $slug, $step, array $input = array() ) {
	$source = npmp_import_hub_source( $slug );
	if ( ! $source || ! in_array( $step, npmp_import_hub_step_ids(), true ) ) {
		return new WP_Error( 'npmp_hub_unknown', __( 'We don\'t know that plugin or step.', 'nonprofit-manager' ) );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'npmp_hub_cap', __( 'You don\'t have permission to run this step.', 'nonprofit-manager' ) );
	}
	if ( npmp_import_hub_tier_locked( $source['tier'], function_exists( 'npmp_is_pro' ) && npmp_is_pro() ) ) {
		return new WP_Error( 'npmp_hub_locked', __( 'This import comes with Nonprofit Manager Pro.', 'nonprofit-manager' ) );
	}

	$facts  = npmp_import_hub_facts( $source );
	$states = npmp_import_hub_step_states( $facts );
	if ( ! npmp_import_hub_can_run( $step, $states ) ) {
		return new WP_Error( 'npmp_hub_order', __( 'Finish the steps above this one first.', 'nonprofit-manager' ) );
	}

	$state = npmp_import_hub_get_state( $slug );

	switch ( $step ) {
		case 'import':
			// The source's own importer does the work. This only hands back where it lives.
			return array(
				'message' => 'import',
				'url'     => (string) npmp_import_hub_call( $source['import_url'], is_string( $source['import_url'] ) ? $source['import_url'] : '' ),
			);

		case 'verify':
			if ( empty( $input['verified'] ) ) {
				return new WP_Error( 'npmp_hub_unchecked', __( 'Tick the box once you\'ve checked everything on the list.', 'nonprofit-manager' ) );
			}
			$state['verified'] = array(
				'at'   => time(),
				'user' => get_current_user_id(),
			);
			npmp_import_hub_save_state( $slug, $state );
			return array( 'message' => 'verified' );

		case 'deactivate':
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return new WP_Error( 'npmp_hub_cap', __( 'You need permission to manage plugins to turn this one off.', 'nonprofit-manager' ) );
			}
			if ( is_callable( $source['deactivate'] ) ) {
				call_user_func( $source['deactivate'] );
			} else {
				deactivate_plugins( array_keys( npmp_import_hub_active_plugins( $source ) ) );
			}
			if ( npmp_import_hub_active_plugins( $source ) ) {
				return new WP_Error( 'npmp_hub_still_active', __( 'WordPress couldn\'t turn the plugin off. Turn the plugin off on the Plugins screen, then come back to this screen.', 'nonprofit-manager' ) );
			}
			// The old plugin's routes stay cached until the rules rebuild, and
			// they keep old links from reaching a 404 and its redirect.
			delete_option( 'rewrite_rules' );
			return array( 'message' => 'deactivated' );

		case 'remove_plugin':
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				return new WP_Error( 'npmp_hub_file_mods', __( 'Your host turned off plugin changes from the dashboard, so ask your host to delete the plugin files.', 'nonprofit-manager' ) );
			}
			if ( ! current_user_can( 'delete_plugins' ) ) {
				return new WP_Error(
					'npmp_hub_cap',
					is_multisite()
						? __( 'Only a network admin can delete plugins on this network.', 'nonprofit-manager' )
						: __( 'You need permission to delete plugins for this step.', 'nonprofit-manager' )
				);
			}
			$files = array_keys( npmp_import_hub_installed_plugins( $source ) );
			foreach ( $files as $file ) {
				if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
					return new WP_Error( 'npmp_hub_still_active', __( 'Turn the plugin off before you delete the plugin\'s files.', 'nonprofit-manager' ) );
				}
			}
			if ( ! $files ) {
				return array( 'message' => 'removed_plugin' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( 'direct' !== get_filesystem_method() ) {
				return new WP_Error( 'npmp_hub_fs', __( 'WordPress needs your FTP details to delete files on this host. Delete the plugin from the Plugins screen, then come back to this screen.', 'nonprofit-manager' ) );
			}
			$result = delete_plugins( $files );
			wp_clean_plugins_cache( false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( true !== $result || npmp_import_hub_installed_plugins( $source ) ) {
				return new WP_Error( 'npmp_hub_delete_failed', __( 'WordPress couldn\'t delete every file. Delete the plugin from the Plugins screen, then come back to this screen.', 'nonprofit-manager' ) );
			}
			return array( 'message' => 'removed_plugin' );

		case 'remove_data':
			if ( empty( $state['verified']['at'] ) ) {
				return new WP_Error( 'npmp_hub_order', __( 'Finish the steps above this one first.', 'nonprofit-manager' ) );
			}
			$blockers = (array) npmp_import_hub_call( $source['data_removal']['blockers'], array() );
			if ( $blockers ) {
				return new WP_Error(
					'npmp_hub_blocked',
					sprintf(
						/* translators: %s: plugin names, comma separated. */
						__( 'Delete these plugins first, since they share this data: %s.', 'nonprofit-manager' ),
						implode( ', ', array_map( 'strval', $blockers ) )
					)
				);
			}
			$started = ! empty( $state['removal']['started'] );
			if ( ! $started && ! npmp_import_hub_confirm_matches( $input['confirm'] ?? '', npmp_import_hub_confirm_word() ) ) {
				return new WP_Error(
					'npmp_hub_confirm',
					sprintf(
						/* translators: %s: the word to type, such as DELETE. */
						__( 'Type %s in the box to delete the old data.', 'nonprofit-manager' ),
						npmp_import_hub_confirm_word()
					)
				);
			}
			if ( ! $started ) {
				$state['removal'] = array(
					'started' => time(),
					'user'    => get_current_user_id(),
					'removed' => 0,
					'done'    => false,
				);
				npmp_import_hub_save_state( $slug, $state );
			}

			$until  = microtime( true ) + max( 1, (int) NPMP_IMPORT_HUB_SECONDS );
			$result = array( 'done' => false );
			do {
				$result = (array) call_user_func( $source['data_removal']['batch'] );
				$state['removal']['removed'] = (int) ( $state['removal']['removed'] ?? 0 ) + (int) ( $result['removed'] ?? 0 );
			} while ( empty( $result['done'] ) && microtime( true ) < $until && empty( $input['one_batch'] ) );

			if ( ! empty( $result['done'] ) ) {
				$state['removal']['done'] = time();
			}
			npmp_import_hub_save_state( $slug, $state );
			return array(
				'message' => empty( $result['done'] ) ? 'removing' : 'removed_data',
				'done'    => ! empty( $result['done'] ),
				'removed' => (int) $state['removal']['removed'],
			);
	}

	return new WP_Error( 'npmp_hub_unknown', __( 'We don\'t know that plugin or step.', 'nonprofit-manager' ) );
}

/**
 * The word you type to confirm data removal.
 *
 * @return string
 */
function npmp_import_hub_confirm_word() {
	/* translators: The word a site owner types to confirm deleting an old plugin's data. Keep it one short word. */
	return _x( 'DELETE', 'typed confirmation', 'nonprofit-manager' );
}

// =====================================================================
// Admin screen
// =====================================================================

if ( is_admin() ) {
	add_action( 'admin_menu', 'npmp_import_hub_register_menu', 12 );
	add_action( 'admin_enqueue_scripts', 'npmp_import_hub_enqueue' );
	add_action( 'admin_post_npmp_import_hub', 'npmp_import_hub_post' );
	add_action( 'npmp_import_page_notices', 'npmp_import_hub_tabs_on_import', 1 );
}

/**
 * URL of the hub, optionally pointing at one source.
 *
 * @param string $slug Source slug.
 * @param array  $args Extra query args.
 * @return string
 */
function npmp_import_hub_url( $slug = '', array $args = array() ) {
	$url = admin_url( 'admin.php?page=npmp_import_hub' );
	if ( $args ) {
		$url = add_query_arg( $args, $url );
	}
	return $slug ? $url . '#npmp-hub-' . rawurlencode( $slug ) : $url;
}

/**
 * Register the screen under the main menu.
 *
 * @return void
 */
function npmp_import_hub_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Switch Plugins', 'nonprofit-manager' ),
		__( 'Switch Plugins', 'nonprofit-manager' ),
		'manage_options',
		'npmp_import_hub',
		'npmp_import_hub_render_page'
	);
}

/**
 * Styles and the small script for the hub.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_import_hub_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_import_hub' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Tabs linking the contact Import screen and this one.
 *
 * @param string $current 'contacts' or 'switch'.
 * @return void
 */
function npmp_import_hub_tabs( $current ) {
	$import = function_exists( 'menu_page_url' ) ? menu_page_url( 'npmp_import', false ) : '';
	if ( ! $import || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$tabs = array(
		'contacts' => array( __( 'Contacts from a file or service', 'nonprofit-manager' ), $import ),
		'switch'   => array( __( 'Switch from another plugin', 'nonprofit-manager' ), npmp_import_hub_url() ),
	);
	echo '<nav class="nav-tab-wrapper npmp-hub-tabs" aria-label="' . esc_attr__( 'Import', 'nonprofit-manager' ) . '">';
	foreach ( $tabs as $key => $tab ) {
		$class = 'nav-tab' . ( $key === $current ? ' nav-tab-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab[1] ) . '"' . ( $key === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . '</a>';
	}
	echo '</nav>';
}

/**
 * The tabs on top of the contact Import screen.
 *
 * @return void
 */
function npmp_import_hub_tabs_on_import() {
	npmp_import_hub_tabs( 'contacts' );
}

/**
 * Form handler for every step.
 *
 * @return void
 */
function npmp_import_hub_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to run this step.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	$slug = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
	$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
	check_admin_referer( 'npmp_import_hub_' . $slug . '_' . $step, 'npmp_hub_nonce' );

	$result = npmp_import_hub_run_step(
		$slug,
		$step,
		array(
			'verified' => ! empty( $_POST['npmp_hub_verified'] ),
			'confirm'  => isset( $_POST['npmp_hub_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_hub_confirm'] ) ) : '',
		)
	);

	if ( is_wp_error( $result ) ) {
		if ( 'npmp_hub_cap' === $result->get_error_code() ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}
		set_transient( 'npmp_hub_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( npmp_import_hub_url( $slug, array( 'npmp_hub' => 'error' ) ) );
		exit;
	}
	if ( 'import' === $result['message'] && '' !== $result['url'] ) {
		wp_safe_redirect( $result['url'] );
		exit;
	}
	wp_safe_redirect( npmp_import_hub_url( $slug, array( 'npmp_hub' => $result['message'], 'source' => $slug ) ) );
	exit;
}

/**
 * Plain words for a detection state.
 *
 * @param string $state From npmp_import_hub_detect_state().
 * @param bool   $done  Every step done.
 * @return string
 */
function npmp_import_hub_detect_label( $state, $done ) {
	if ( $done ) {
		return __( 'Switched. Nothing left to do.', 'nonprofit-manager' );
	}
	$labels = array(
		'active'   => __( 'Active on this site', 'nonprofit-manager' ),
		'inactive' => __( 'Installed and turned off', 'nonprofit-manager' ),
		'data'     => __( 'Deleted, with its data still in your database', 'nonprofit-manager' ),
		'none'     => __( 'Not on this site', 'nonprofit-manager' ),
	);
	return $labels[ $state ] ?? $labels['none'];
}

/**
 * Step names.
 *
 * @return array<string,string>
 */
function npmp_import_hub_step_labels() {
	return array(
		'import'        => __( 'Import data and settings', 'nonprofit-manager' ),
		'verify'        => __( 'Check the results', 'nonprofit-manager' ),
		'deactivate'    => __( 'Turn off the plugin', 'nonprofit-manager' ),
		'remove_plugin' => __( 'Delete the plugin', 'nonprofit-manager' ),
		'remove_data'   => __( 'Delete the plugin\'s data', 'nonprofit-manager' ),
	);
}

/**
 * Words for a step's state badge.
 *
 * @param string $state Step state.
 * @return string
 */
function npmp_import_hub_state_label( $state ) {
	$labels = array(
		'done'        => __( 'Done', 'nonprofit-manager' ),
		'ready'       => __( 'Next', 'nonprofit-manager' ),
		'locked'      => __( 'Waiting', 'nonprofit-manager' ),
		'blocked'     => __( 'Waiting', 'nonprofit-manager' ),
		'unavailable' => __( 'Not available', 'nonprofit-manager' ),
	);
	return $labels[ $state ] ?? '';
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_import_hub_render_page() {
	npmp_verify_admin_access( 'manage_options' );

	$sources = npmp_import_sources();
	$facts   = array();
	foreach ( $sources as $slug => $source ) {
		$facts[ $slug ] = npmp_import_hub_facts( $source );
	}
	$groups = npmp_import_hub_partition( $facts );

	echo '<div class="wrap npmp-hub-wrap">';
	echo '<h1>' . esc_html__( 'Switch from another plugin', 'nonprofit-manager' ) . '</h1>';
	npmp_import_hub_tabs( 'switch' );
	echo '<p class="npmp-hub-intro">' . esc_html(
		sprintf(
			/* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */
			__( 'Move each old plugin into %s as you set up the matching feature. You finish the steps in order, and you delete data for good only in the last step.', 'nonprofit-manager' ),
			npmp_label( 'app_name' )
		)
	) . '</p>';

	npmp_import_hub_render_message();

	if ( ! $groups['here'] ) {
		echo '<p class="npmp-hub-empty">' . esc_html__( 'None of the plugins we can import from are on this site.', 'nonprofit-manager' ) . '</p>';
	}
	foreach ( $groups['here'] as $slug ) {
		npmp_import_hub_render_source( $sources[ $slug ], $facts[ $slug ] );
	}

	if ( $groups['other'] ) {
		echo '<details class="npmp-hub-other"' . ( $groups['here'] ? '' : ' open' ) . '><summary>' . esc_html(
			sprintf(
				/* translators: %s: number of plugins. */
				__( 'Other plugins we can import from (%s)', 'nonprofit-manager' ),
				number_format_i18n( count( $groups['other'] ) )
			)
		) . '</summary><ul class="npmp-hub-other-list">';
		foreach ( $groups['other'] as $slug ) {
			$source = $sources[ $slug ];
			echo '<li><strong>' . esc_html( $source['name'] ) . '</strong> ';
			if ( npmp_import_hub_tier_locked( $source['tier'], npmp_is_pro() ) ) {
				echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'import_hub' ), __( 'This import comes with Nonprofit Manager Pro', 'nonprofit-manager' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
			}
			if ( '' !== $source['covers'] ) {
				echo '<span class="npmp-hub-muted">' . esc_html( $source['covers'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'You see each one above once you install its plugin on this site.', 'nonprofit-manager' ) . '</p></details>';
	}
	echo '</div>';
}

/**
 * The result of the last step you ran.
 *
 * @return void
 */
function npmp_import_hub_render_message() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$code = isset( $_GET['npmp_hub'] ) ? sanitize_key( wp_unslash( $_GET['npmp_hub'] ) ) : '';
	if ( '' === $code ) {
		return;
	}
	if ( 'error' === $code ) {
		$key = 'npmp_hub_error_' . get_current_user_id();
		$msg = get_transient( $key );
		delete_transient( $key );
		if ( $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}
		return;
	}
	$messages = array(
		'verified'       => __( 'Marked as checked. You can turn off the old plugin next.', 'nonprofit-manager' ),
		'deactivated'    => __( 'The old plugin is off. Its data is still in your database.', 'nonprofit-manager' ),
		'removed_plugin' => __( 'The old plugin\'s files are gone. Its data is still in your database.', 'nonprofit-manager' ),
		'removing'       => __( 'Still deleting in batches. Keep this page open until you see the done message.', 'nonprofit-manager' ),
		'removed_data'   => __( 'The old plugin\'s data is gone.', 'nonprofit-manager' ),
	);
	if ( isset( $messages[ $code ] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
	}
}

/**
 * Hidden fields for a step form.
 *
 * @param string $slug Slug.
 * @param string $step Step.
 * @return void
 */
function npmp_import_hub_form_fields( $slug, $step ) {
	echo '<input type="hidden" name="action" value="npmp_import_hub">';
	echo '<input type="hidden" name="source" value="' . esc_attr( $slug ) . '">';
	echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
	wp_nonce_field( 'npmp_import_hub_' . $slug . '_' . $step, 'npmp_hub_nonce' );
}

/**
 * One source's card.
 *
 * @param array $source Source.
 * @param array $facts  From npmp_import_hub_facts().
 * @return void
 */
function npmp_import_hub_render_source( array $source, array $facts ) {
	$slug    = $source['slug'];
	$locked  = npmp_import_hub_tier_locked( $source['tier'], npmp_is_pro() );
	$states  = npmp_import_hub_step_states( $facts );
	$done    = npmp_import_hub_finished( $states );
	$current = npmp_import_hub_current_step( $states );
	$labels  = npmp_import_hub_step_labels();

	echo '<section class="npmp-hub-source' . ( $locked ? ' is-locked' : '' ) . '" id="npmp-hub-' . esc_attr( $slug ) . '">';
	echo '<header class="npmp-hub-head"><h2>' . esc_html( $source['name'] );
	if ( $locked ) {
		echo ' ' . npmp_pro_lock_badge( npmp_get_upgrade_url( 'import_hub' ), __( 'This import comes with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
	}
	echo '</h2><p class="npmp-hub-status is-' . esc_attr( $done ? 'done' : npmp_import_hub_detect_state( $facts ) ) . '">' . esc_html( npmp_import_hub_detect_label( npmp_import_hub_detect_state( $facts ), $done ) ) . '</p></header>';

	if ( '' !== $source['covers'] || '' !== $source['feature'] ) {
		echo '<p class="npmp-hub-covers">';
		if ( '' !== $source['covers'] ) {
			echo '<span>' . esc_html( $source['covers'] ) . '</span>';
		}
		if ( '' !== $source['feature'] ) {
			echo ' <span class="npmp-hub-arrow" aria-hidden="true">&rarr;</span> <span class="screen-reader-text">' . esc_html__( 'moves to', 'nonprofit-manager' ) . '</span> <strong>' . esc_html( $source['feature'] ) . '</strong>';
		}
		echo '</p>';
	}
	if ( $source['pro_parts'] ) {
		echo '<ul class="npmp-hub-pro-parts">';
		foreach ( $source['pro_parts'] as $part ) {
			echo '<li>' . esc_html( $part ) . ' ';
			if ( npmp_is_pro() ) {
				echo '<span class="npmp-hub-muted">' . esc_html__( '(included with your Pro licence)', 'nonprofit-manager' ) . '</span>';
			} else {
				echo npmp_pro_lock_badge( npmp_get_upgrade_url( 'import_hub' ), __( 'This part comes with Nonprofit Manager Pro', 'nonprofit-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in npmp_pro_lock_badge().
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	if ( $locked ) {
		echo '<p>' . esc_html__( 'You can see this import here, and you can run it once you add Nonprofit Manager Pro.', 'nonprofit-manager' ) . ' <a href="' . esc_url( npmp_get_upgrade_url( 'import_hub' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro plans', 'nonprofit-manager' ) . '</a></p>';
		echo '</section>';
		return;
	}

	echo '<ol class="npmp-hub-steps">';
	$n = 0;
	foreach ( npmp_import_hub_step_ids() as $step ) {
		++$n;
		$state = $states[ $step ];
		$open  = $step === $current && ! $done;
		echo '<li class="npmp-hub-step is-' . esc_attr( $state ) . ( $open ? ' is-open' : '' ) . '">';
		echo '<div class="npmp-hub-step-head"><span class="npmp-hub-num" aria-hidden="true">' . esc_html( (string) $n ) . '</span>';
		echo '<span class="npmp-hub-step-name">' . esc_html( $labels[ $step ] ) . '</span>';
		echo '<span class="npmp-hub-badge is-' . esc_attr( $state ) . '">' . esc_html( npmp_import_hub_state_label( $state ) ) . '</span></div>';
		echo '<div class="npmp-hub-step-body">';
		$fn = 'npmp_import_hub_render_step_' . $step;
		$fn( $source, $facts, $state, $open );
		echo '</div></li>';
	}
	echo '</ol></section>';
}

/**
 * Step 1 body.
 *
 * @param array  $source Source.
 * @param array  $facts  Facts.
 * @param string $state  Step state.
 * @param bool   $open   Current step.
 * @return void
 */
function npmp_import_hub_render_step_import( array $source, array $facts, $state, $open ) {
	$url = (string) npmp_import_hub_call( $source['import_url'], is_string( $source['import_url'] ) ? $source['import_url'] : '' );
	if ( 'done' === $state ) {
		$summary = (string) npmp_import_hub_call( $source['import_summary'], '' );
		echo '<p>' . esc_html( '' !== $summary ? $summary : __( 'Imported.', 'nonprofit-manager' ) ) . '</p>';
		if ( $url && true === $facts['can_import'] ) {
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Import again', 'nonprofit-manager' ) . '</a></p>';
		}
		return;
	}
	if ( 'blocked' === $state ) {
		$why = is_string( $facts['can_import'] ) ? $facts['can_import'] : __( 'This import can\'t start yet.', 'nonprofit-manager' );
		echo '<p>' . esc_html( $why ) . '</p>';
		return;
	}
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: old plugin name. */
			__( 'Bring over what you keep in %s. You review everything before you move anything, and you keep the old plugin\'s copy too.', 'nonprofit-manager' ),
			$source['name']
		)
	) . '</p>';
	if ( $url ) {
		echo '<p><a class="button button-primary npmp-hub-go" href="' . esc_url( $url ) . '">' . esc_html__( 'Start the import', 'nonprofit-manager' ) . '</a></p>';
	}
}

/**
 * Step 2 body: counts, checklist, and the box.
 *
 * @param array  $source Source.
 * @param array  $facts  Facts.
 * @param string $state  Step state.
 * @param bool   $open   Current step.
 * @return void
 */
function npmp_import_hub_render_step_verify( array $source, array $facts, $state, $open ) {
	if ( 'locked' === $state ) {
		echo '<p class="npmp-hub-muted">' . esc_html__( 'Opens after the import.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	$slug   = $source['slug'];
	$hub    = npmp_import_hub_get_state( $slug );
	$counts = (array) npmp_import_hub_call( $source['counts'], array() );

	if ( 'done' === $state && ! empty( $hub['verified']['at'] ) ) {
		$who = get_userdata( (int) ( $hub['verified']['user'] ?? 0 ) );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: date, 2: person's name. */
				__( 'Checked on %1$s by %2$s.', 'nonprofit-manager' ),
				wp_date( get_option( 'date_format' ), (int) $hub['verified']['at'] ),
				$who ? $who->display_name : __( 'someone who has since left', 'nonprofit-manager' )
			)
		) . '</p>';
	}
	$folded = 'done' === $state;
	if ( $folded ) {
		if ( ! empty( $facts['data_removed'] ) ) {
			// The old data is gone, so there's nothing left to compare.
			return;
		}
		echo '<details class="npmp-hub-again"><summary>' . esc_html__( 'See the counts and check again', 'nonprofit-manager' ) . '</summary>';
	}

	if ( $counts ) {
		echo '<div class="npmp-hub-table-wrap"><table class="widefat striped npmp-hub-counts"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'What', 'nonprofit-manager' ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html( $source['name'] ) . '</th>';
		echo '<th scope="col" class="num">' . esc_html( npmp_label( 'app_name' ) ) . '</th></tr></thead><tbody>';
		foreach ( $counts as $row ) {
			$row = array_merge(
				array(
					'label' => '',
					'old'   => null,
					'new'   => null,
					'note'  => '',
				),
				(array) $row
			);
			echo '<tr><th scope="row">' . esc_html( $row['label'] );
			if ( '' !== $row['note'] ) {
				echo '<span class="npmp-hub-note">' . esc_html( $row['note'] ) . '</span>';
			}
			echo '</th><td class="num">' . esc_html( null === $row['old'] ? '–' : number_format_i18n( (int) $row['old'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( null === $row['new'] ? '–' : number_format_i18n( (int) $row['new'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	if ( $source['checklist'] ) {
		echo '<p><strong>' . esc_html__( 'Look these over on your site:', 'nonprofit-manager' ) . '</strong></p><ul class="npmp-hub-checklist">';
		foreach ( $source['checklist'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-hub-form">';
	npmp_import_hub_form_fields( $slug, 'verify' );
	echo '<p><label class="npmp-hub-check"><input type="checkbox" name="npmp_hub_verified" value="1" required> ';
	esc_html_e( 'I checked everything on this list, and it looks right.', 'nonprofit-manager' );
	echo '</label></p><p><button type="submit" class="button' . ( 'ready' === $state ? ' button-primary' : '' ) . '">';
	echo esc_html( 'done' === $state ? __( 'Check again', 'nonprofit-manager' ) : __( 'Mark as checked', 'nonprofit-manager' ) );
	echo '</button></p></form>';
	if ( $folded ) {
		echo '</details>';
	}
}

/**
 * Step 3 body.
 *
 * @param array  $source Source.
 * @param array  $facts  Facts.
 * @param string $state  Step state.
 * @param bool   $open   Current step.
 * @return void
 */
function npmp_import_hub_render_step_deactivate( array $source, array $facts, $state, $open ) {
	if ( 'done' === $state ) {
		echo '<p>' . esc_html__( 'Off. You can turn the plugin back on from the Plugins screen.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( 'locked' === $state ) {
		echo '<p class="npmp-hub-muted">' . esc_html__( 'Opens once you check the results.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		echo '<p>' . esc_html__( 'Ask someone who can manage plugins on this site to run this step.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	$plugins = npmp_import_hub_active_plugins( $source );
	echo '<p>' . esc_html( _n( 'You turn off this plugin and delete nothing, so you can switch the plugin back on later.', 'You turn off these plugins together and delete nothing, so you can switch them back on later.', count( $plugins ), 'nonprofit-manager' ) ) . '</p>';
	npmp_import_hub_render_plugin_list( $plugins );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-hub-form">';
	npmp_import_hub_form_fields( $source['slug'], 'deactivate' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: old plugin name. */
			__( 'Turn off %s', 'nonprofit-manager' ),
			$source['name']
		)
	) . '</button></p></form>';
}

/**
 * Step 4 body.
 *
 * @param array  $source Source.
 * @param array  $facts  Facts.
 * @param string $state  Step state.
 * @param bool   $open   Current step.
 * @return void
 */
function npmp_import_hub_render_step_remove_plugin( array $source, array $facts, $state, $open ) {
	if ( 'done' === $state ) {
		echo '<p>' . esc_html__( 'Deleted. The plugin\'s files are off your server.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( 'locked' === $state ) {
		echo '<p class="npmp-hub-muted">' . esc_html__( 'Opens once you turn the plugin off.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		echo '<p>' . esc_html__( 'Your host turned off plugin changes from the dashboard, so ask your host to delete the plugin files. You finish this step once your host deletes the files.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( ! current_user_can( 'delete_plugins' ) ) {
		echo '<p>' . esc_html( is_multisite() ? __( 'Only a network admin can delete plugins on this network. This step finishes once the plugin is gone.', 'nonprofit-manager' ) : __( 'Ask someone who can delete plugins on this site to run this step.', 'nonprofit-manager' ) ) . '</p>';
		return;
	}
	$plugins = npmp_import_hub_installed_plugins( $source );
	$note = npmp_import_hub_call( $source['delete_note'], '' );
	if ( is_string( $note ) && '' !== $note ) {
		// The old plugin's own uninstall deletes its data along with its files.
		echo '<p>' . esc_html__( 'You delete the plugin\'s files from your server.', 'nonprofit-manager' ) . '</p>';
		echo '<p><strong>' . esc_html( $note ) . '</strong></p>';
	} else {
		echo '<p>' . esc_html__( 'You delete the plugin\'s files from your server. Its data stays in your database until the last step.', 'nonprofit-manager' ) . '</p>';
	}
	if ( is_multisite() ) {
		echo '<p><strong>' . esc_html__( 'This deletes the plugin for every site in your network.', 'nonprofit-manager' ) . '</strong></p>';
	}
	npmp_import_hub_render_plugin_list( $plugins );
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-hub-form">';
	npmp_import_hub_form_fields( $source['slug'], 'remove_plugin' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html(
		sprintf(
			/* translators: %s: old plugin name. */
			__( 'Delete %s', 'nonprofit-manager' ),
			$source['name']
		)
	) . '</button></p></form>';
}

/**
 * Step 5 body: what goes, the warning, and the typed confirmation.
 *
 * @param array  $source Source.
 * @param array  $facts  Facts.
 * @param string $state  Step state.
 * @param bool   $open   Current step.
 * @return void
 */
function npmp_import_hub_render_step_remove_data( array $source, array $facts, $state, $open ) {
	if ( 'done' === $state ) {
		echo '<p>' . esc_html__( 'Deleted. You have no data from the old plugin left in your database.', 'nonprofit-manager' ) . '</p>';
		return;
	}
	if ( 'unavailable' === $state ) {
		$why = '' !== $source['data_removal']['reason'] ? $source['data_removal']['reason'] : __( 'We can\'t delete this plugin\'s data safely yet, so you can leave the data in your database without any harm.', 'nonprofit-manager' );
		echo '<p>' . esc_html( $why ) . '</p>';
		return;
	}
	if ( 'locked' === $state ) {
		echo '<p class="npmp-hub-muted">' . esc_html__( 'Opens once you delete the plugin.', 'nonprofit-manager' ) . '</p>';
		return;
	}

	$slug     = $source['slug'];
	$hub      = npmp_import_hub_get_state( $slug );
	$blockers = (array) npmp_import_hub_call( $source['data_removal']['blockers'], array() );
	$lines    = (array) npmp_import_hub_call( $source['data_removal']['describe'], array() );

	if ( ! empty( $hub['removal']['started'] ) ) {
		// Part way through: a form that carries on by itself.
		echo '<p>' . esc_html__( 'Deleting in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. After an error the form waits for a click, so a refusal can't loop.
		$auto = ! ( isset( $_GET['npmp_hub'] ) && 'error' === sanitize_key( wp_unslash( $_GET['npmp_hub'] ) ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-hub-form"' . ( $auto ? ' data-npmp-hub-continue="1"' : '' ) . '>';
		npmp_import_hub_form_fields( $slug, 'remove_data' );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form>';
		return;
	}

	if ( $lines ) {
		echo '<p>' . esc_html__( 'This deletes:', 'nonprofit-manager' ) . '</p><ul class="npmp-hub-checklist">';
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( (string) $line ) . '</li>';
		}
		echo '</ul>';
	}
	if ( '' !== (string) $source['data_removal']['keeps'] ) {
		echo '<p>' . esc_html( (string) $source['data_removal']['keeps'] ) . '</p>';
	}
	echo '<div class="npmp-hub-warning" role="note"><p><strong>' . esc_html__( 'You can\'t undo this step.', 'nonprofit-manager' ) . '</strong> ';
	esc_html_e( 'Back up your database first, so you have a copy if you ever need the old records.', 'nonprofit-manager' );
	echo '</p></div>';

	if ( $blockers ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: plugin names, comma separated. */
				__( 'Delete these plugins first, since they share this data: %s.', 'nonprofit-manager' ),
				implode( ', ', array_map( 'strval', $blockers ) )
			)
		) . '</p>';
		return;
	}

	$word = npmp_import_hub_confirm_word();
	$id   = 'npmp-hub-confirm-' . $slug;
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="npmp-hub-form npmp-hub-danger">';
	npmp_import_hub_form_fields( $slug, 'remove_data' );
	echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html(
		sprintf(
			/* translators: %s: the word to type, such as DELETE. */
			__( 'Type %s to confirm', 'nonprofit-manager' ),
			$word
		)
	) . '</label><br><input type="text" id="' . esc_attr( $id ) . '" name="npmp_hub_confirm" class="regular-text" autocomplete="off" spellcheck="false" data-npmp-hub-word="' . esc_attr( $word ) . '" required></p>';
	echo '<p><button type="submit" class="button npmp-hub-delete" data-npmp-hub-needs-word="1">' . esc_html(
		sprintf(
			/* translators: %s: old plugin name. */
			__( 'Delete %s data for good', 'nonprofit-manager' ),
			$source['name']
		)
	) . '</button></p></form>';
}

/**
 * A list of plugin names.
 *
 * @param array<string,string> $plugins Basename => name.
 * @return void
 */
function npmp_import_hub_render_plugin_list( array $plugins ) {
	if ( ! $plugins ) {
		return;
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $plugins as $name ) {
		echo '<li><strong>' . esc_html( $name ) . '</strong></li>';
	}
	echo '</ul>';
}
