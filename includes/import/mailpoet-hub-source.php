<?php
/**
 * File path: includes/import/mailpoet-hub-source.php
 *
 * MailPoet as a source on the Switch Plugins screen
 * (includes/import/import-hub.php).
 *
 * Import is the five-screen importer in mailpoet-importer.php, which reads
 * MailPoet's tables straight from the database, so it runs with MailPoet off.
 * Deactivate is npmp_mailpoet_deactivate(), which turns off MailPoet's add-ons
 * first. Remove plugin deletes MailPoet and every installed add-on that needs
 * it (MailPoet 5.45 ships no uninstall.php, so its data stays for step 5).
 *
 * Remove data deletes only what MailPoet wrote:
 *   - posts of type mailpoet_page and mailpoet_email, with their meta,
 *   - options npmp_mailpoet_hub_option_is_mailpoet() names (MailPoet keeps
 *     its own settings in a table, so these are flags, notices, its widget's
 *     settings, and its caches),
 *   - every table whose name starts with the site prefix and mailpoet_.
 *
 * What stays: every contact, including the ones the importer made (they carry
 * npmp_source = mailpoet), the npmp_mailpoet_* options, and the sender and
 * signup settings the importer copied into npmp_email_settings and
 * npmp_membership_form_settings. Action Scheduler's rows stay too, since its
 * tables belong to every plugin that uses it, and old MailPoet widgets stay in
 * your sidebars until you take them out (they show nothing once MailPoet is
 * gone).
 *
 * Data removal refuses while MailPoet or any add-on that needs it is still
 * installed, since MailPoet Premium reads the same tables.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers (no database), covered by tests/test-import-hub.php
// =====================================================================

/**
 * Post types MailPoet registers.
 *
 * @return string[]
 */
function npmp_mailpoet_hub_post_types() {
	return array( 'mailpoet_page', 'mailpoet_email' );
}

/**
 * Is this option one MailPoet wrote?
 *
 * Names come from MailPoet 5.45's source. Nothing starting with npmp ever
 * matches. Transients are caches that rebuild themselves, so any transient
 * whose name starts with mailpoet counts.
 *
 * @param string $name Option name.
 * @return bool
 */
function npmp_mailpoet_hub_option_is_mailpoet( $name ) {
	$name = (string) $name;
	if ( '' === $name || 0 === strpos( $name, 'npmp' ) ) {
		return false;
	}
	foreach ( array( '_transient_timeout_', '_transient_', '_site_transient_timeout_', '_site_transient_' ) as $wrap ) {
		if ( 0 === strpos( $name, $wrap ) ) {
			return 0 === strpos( substr( $name, strlen( $wrap ) ), 'mailpoet' );
		}
	}
	if ( 'widget_mailpoet_form' === $name ) {
		return true;
	}
	return 0 === strpos( $name, 'mailpoet_' ) || 0 === strpos( $name, 'mailpoet-' );
}

/**
 * Is this table one of MailPoet's on the site with this prefix?
 *
 * On multisite the main site's prefix (wp_) never matches another site's
 * tables (wp_2_mailpoet_...), because those carry their own number first.
 *
 * @param string $table  Full table name.
 * @param string $prefix Site table prefix, such as wp_.
 * @return bool
 */
function npmp_mailpoet_hub_table_is_mailpoet( $table, $prefix ) {
	$table = (string) $table;
	$start = (string) $prefix . 'mailpoet_';
	if ( '' === (string) $prefix || 0 !== strpos( $table, $start ) ) {
		return false;
	}
	return (bool) preg_match( '/^[a-z0-9_]+$/', substr( $table, strlen( $start ) ) );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_mailpoet_hub_register' );

/**
 * Add MailPoet to the hub.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_mailpoet_hub_register( $sources ) {
	$sources['mailpoet'] = array(
		'name'              => 'MailPoet',
		'covers'            => __( 'Subscribers, signup dates, lists, and sender settings', 'nonprofit-manager' ),
		'feature'           => __( 'Contacts and newsletters', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'Turn each list into a group', 'nonprofit-manager' ) ),
		'plugin_folders'    => array( 'mailpoet' ),
		'has_data'          => 'npmp_mailpoet_hub_has_data',
		'is_imported'       => 'npmp_mailpoet_hub_is_imported',
		'can_import'        => 'npmp_mailpoet_hub_can_import',
		'import_url'        => 'npmp_mailpoet_page_url',
		'import_summary'    => 'npmp_mailpoet_hub_import_summary',
		'counts'            => 'npmp_mailpoet_hub_counts',
		'checklist'         => array(
			__( 'Open a few of your new contacts and check their names, emails, and signup dates.', 'nonprofit-manager' ),
			__( 'Check the sender name and address in your email settings.', 'nonprofit-manager' ),
			__( 'Put a new signup form on every page that had a MailPoet form.', 'nonprofit-manager' ),
			__( 'Send a test newsletter to yourself.', 'nonprofit-manager' ),
		),
		'deactivate'        => 'npmp_mailpoet_deactivate',
		'active_plugins'    => 'npmp_mailpoet_plugins_to_deactivate',
		'installed_plugins' => 'npmp_mailpoet_hub_installed_plugins',
		'data_removal'      => array(
			'describe' => 'npmp_mailpoet_hub_data_lines',
			'blockers' => 'npmp_mailpoet_hub_blockers',
			'batch'    => 'npmp_mailpoet_hub_remove_batch',
			'keeps'    => __( 'Every contact stays, along with the sender and signup settings you copied.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * MailPoet and every installed add-on that needs it, basename => name.
 *
 * @return array<string,string>
 */
function npmp_mailpoet_hub_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		if ( 'mailpoet' === strtok( (string) $file, '/' ) || npmp_mailpoet_plugin_needs_mailpoet( $file, $data ) ) {
			$out[ $file ] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * Has an import finished? Counts contacts from every admin, plus your own
 * run when it moved settings and no contacts.
 *
 * @return bool
 */
function npmp_mailpoet_hub_is_imported() {
	if ( (int) get_option( 'npmp_mailpoet_imported', 0 ) > 0 ) {
		return true;
	}
	$stage = npmp_mailpoet_get_stage();
	return ! empty( $stage['migrated'] );
}

/**
 * The importer reads MailPoet's tables, so it needs them and nothing else.
 *
 * @return true|string
 */
function npmp_mailpoet_hub_can_import() {
	if ( npmp_mailpoet_has_table( 'subscribers' ) ) {
		return true;
	}
	return __( 'You need MailPoet\'s subscriber table to import, and this site doesn\'t have one. Install MailPoet, turn it on once so it sets up its tables, and come back to this screen.', 'nonprofit-manager' );
}

/**
 * How many contacts the importer made.
 *
 * @return int
 */
function npmp_mailpoet_hub_count_contacts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_source' AND pm.meta_value = 'mailpoet' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
}

/**
 * MailPoet subscribers (not trashed) whose address isn't on any contact.
 *
 * @return int
 */
function npmp_mailpoet_hub_count_left_behind() {
	global $wpdb;
	if ( ! npmp_mailpoet_has_table( 'subscribers' ) ) {
		return 0;
	}
	$table = npmp_mailpoet_table( 'subscribers' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} s WHERE s.deleted_at IS NULL AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND LOWER(pm.meta_value) = LOWER(s.email) AND p.post_type = 'npmp_contact' AND p.post_status = 'publish' )" );
}

/**
 * One line for a finished import.
 *
 * @return string
 */
function npmp_mailpoet_hub_import_summary() {
	$made = npmp_mailpoet_hub_count_contacts();
	if ( ! $made ) {
		return __( 'You brought over your subscribers\' details and your sender settings.', 'nonprofit-manager' );
	}
	return sprintf(
		/* translators: %s: number of contacts. */
		_n( 'You have %s new contact from MailPoet.', 'You have %s new contacts from MailPoet.', $made, 'nonprofit-manager' ),
		number_format_i18n( $made )
	);
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_mailpoet_hub_counts() {
	$old   = npmp_mailpoet_count_subscribers();
	$left  = npmp_mailpoet_hub_count_left_behind();
	$lists = npmp_mailpoet_has_table( 'segments' ) ? count( npmp_mailpoet_read_lists() ) : 0;

	// The free limit (import-cap.php) is a total for the site. It's what left
	// people behind when it's on and there are fewer places than people.
	$capped    = function_exists( 'npmp_import_is_capped' ) ? npmp_import_is_capped() : ( function_exists( 'npmp_import_max_rows' ) && npmp_import_max_rows() < PHP_INT_MAX );
	$places    = function_exists( 'npmp_import_free_remaining' ) ? npmp_import_free_remaining() : PHP_INT_MAX;
	$left_note = __( 'Subscribers you chose to leave out stay in MailPoet. Delete MailPoet\'s data and they\'re gone.', 'nonprofit-manager' );
	if ( $left && $capped && $places < $left ) {
		$left_note = sprintf(
			/* translators: %s: free import limit, such as 100. */
			__( 'In the free version you can import %s contacts in all, so the rest are still only in MailPoet. Delete MailPoet\'s data and they\'re gone.', 'nonprofit-manager' ),
			number_format_i18n( defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ? NPMP_IMPORT_FREE_MAX_ROWS : npmp_import_max_rows() )
		);
	}

	return array(
		array(
			'label' => __( 'Subscribers', 'nonprofit-manager' ),
			'old'   => $old,
			'new'   => npmp_mailpoet_hub_count_contacts(),
			'note'  => __( 'Someone who was already your contact keeps where they first came from, so they don\'t count here.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Subscribers not in your contacts', 'nonprofit-manager' ),
			'old'   => $left,
			'new'   => null,
			'note'  => $left ? $left_note : '',
		),
		array(
			'label' => __( 'Lists', 'nonprofit-manager' ),
			'old'   => $lists,
			'new'   => null,
			'note'  => npmp_mailpoet_groups_supported()
				? __( 'Each list you kept became a group.', 'nonprofit-manager' )
				: __( 'Lists become groups with Pro. Delete MailPoet\'s data and who was on each list is gone.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Pages with a MailPoet form', 'nonprofit-manager' ),
			'old'   => count( npmp_mailpoet_find_form_posts() ),
			'new'   => null,
			'note'  => __( 'Swap each one for a new signup form before you turn off MailPoet.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Widgets with a MailPoet form', 'nonprofit-manager' ),
			'old'   => npmp_mailpoet_count_form_widgets(),
			'new'   => null,
			'note'  => '',
		),
	);
}

/**
 * MailPoet's tables on this site, with the prefix.
 *
 * @return string[]
 */
function npmp_mailpoet_hub_existing_tables() {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix . 'mailpoet_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
	$names = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	$out   = array();
	foreach ( $names as $name ) {
		if ( npmp_mailpoet_hub_table_is_mailpoet( $name, $wpdb->prefix ) ) {
			$out[] = (string) $name;
		}
	}
	sort( $out );
	return $out;
}

/**
 * MailPoet's options on this site.
 *
 * @return string[]
 */
function npmp_mailpoet_hub_option_names() {
	global $wpdb;
	$like  = '%' . $wpdb->esc_like( 'mailpoet' ) . '%';
	$query = $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared on the line above. One read before deleting.
	$names = (array) $wpdb->get_col( $query );
	return array_values( array_filter( $names, 'npmp_mailpoet_hub_option_is_mailpoet' ) );
}

/**
 * How many MailPoet posts are left.
 *
 * @return int
 */
function npmp_mailpoet_hub_count_posts() {
	global $wpdb;
	$types = npmp_mailpoet_hub_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in)", $types ) );
}

/**
 * Does MailPoet have anything left in the database?
 *
 * @return bool
 */
function npmp_mailpoet_hub_has_data() {
	if ( npmp_mailpoet_hub_existing_tables() ) {
		return true;
	}
	if ( npmp_mailpoet_hub_count_posts() ) {
		return true;
	}
	return (bool) npmp_mailpoet_hub_option_names();
}

/**
 * Plugins that must go before MailPoet's data can, by name.
 *
 * @return string[]
 */
function npmp_mailpoet_hub_blockers() {
	return array_values( npmp_mailpoet_hub_installed_plugins() );
}

/**
 * What step 5 deletes, in plain words.
 *
 * @return string[]
 */
function npmp_mailpoet_hub_data_lines() {
	$subs   = npmp_mailpoet_count_subscribers();
	$left   = npmp_mailpoet_hub_count_left_behind();
	$posts  = npmp_mailpoet_hub_count_posts();
	$opts   = count( npmp_mailpoet_hub_option_names() );
	$tables = count( npmp_mailpoet_hub_existing_tables() );

	$lines = array(
		sprintf(
			/* translators: %s: number of subscribers. */
			_n( 'MailPoet\'s copy of %s subscriber, with its lists, emails, forms, and stats', 'MailPoet\'s copy of %s subscribers, with its lists, emails, forms, and stats', $subs, 'nonprofit-manager' ),
			number_format_i18n( $subs )
		),
	);
	if ( $left ) {
		$lines[] = sprintf(
			/* translators: %s: number of subscribers. */
			_n( '%s of them isn\'t in your contacts and will be gone for good', '%s of them aren\'t in your contacts and will be gone for good', $left, 'nonprofit-manager' ),
			number_format_i18n( $left )
		);
	}
	$lines[] = sprintf(
		/* translators: %s: number of pages and emails. */
		_n( '%s MailPoet page or email', '%s MailPoet pages and emails', $posts, 'nonprofit-manager' ),
		number_format_i18n( $posts )
	);
	$lines[] = sprintf(
		/* translators: %s: number of settings. */
		_n( '%s saved setting', '%s saved settings', $opts, 'nonprofit-manager' ),
		number_format_i18n( $opts )
	);
	$lines[] = sprintf(
		/* translators: %s: number of tables. */
		_n( '%s database table', '%s database tables', $tables, 'nonprofit-manager' ),
		number_format_i18n( $tables )
	);
	return $lines;
}

/**
 * Delete the next batch of MailPoet data.
 *
 * Stateless: each call looks at what's left and deletes the next piece, posts
 * first, then options and tables. A request cut off halfway picks up where it
 * stopped.
 *
 * @param int $limit Posts per batch.
 * @return array{done:bool,removed:int,phase:string}
 */
function npmp_mailpoet_hub_remove_batch( $limit = 50 ) {
	global $wpdb;

	$types = npmp_mailpoet_hub_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a placeholder list.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( $types, array( max( 1, (int) $limit ) ) ) ) ) );
	if ( $ids ) {
		foreach ( $ids as $id ) {
			if ( ! wp_delete_post( $id, true ) ) {
				// Never loop forever on a post WordPress won't delete.
				$wpdb->delete( $wpdb->posts, array( 'ID' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
		return array(
			'done'    => false,
			'removed' => count( $ids ),
			'phase'   => 'posts',
		);
	}

	$removed = 0;
	foreach ( npmp_mailpoet_hub_option_names() as $name ) {
		if ( delete_option( $name ) ) {
			++$removed;
		}
	}

	foreach ( npmp_mailpoet_hub_existing_tables() as $table ) {
		// Names come from SHOW TABLES, checked by npmp_mailpoet_hub_table_is_mailpoet(), never from input.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		++$removed;
	}

	return array(
		'done'    => true,
		'removed' => $removed,
		'phase'   => 'settings',
	);
}
