<?php
/**
 * Plugin activation and cron utilities.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

$npmp_main_file = plugin_dir_path( __DIR__ ) . 'nonprofit-manager.php';

register_activation_hook( $npmp_main_file, 'npmp_run_plugin_activation_tasks' );
register_deactivation_hook( $npmp_main_file, 'npmp_clear_newsletter_cron' );

/**
 * Perform setup tasks on activation.
 *
 * @return void
 */
function npmp_run_plugin_activation_tasks() {
	ob_start();

	npmp_create_members_table();
	npmp_create_donations_table();
	npmp_create_contacts_table();
	npmp_create_newsletter_queue_table();
	npmp_create_newsletter_opens_table();
	npmp_create_newsletter_clicks_table();
	npmp_create_payment_log_table();
	npmp_create_digest_queue_table();
	npmp_initialize_default_newsletter_settings();
	npmp_maybe_create_unsubscribe_page();
	npmp_schedule_newsletter_cron();

	// Set transient to trigger setup wizard redirect
	set_transient( 'npmp_activation_redirect', true, 30 );

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

	if ( ! empty( $features['calendar'] ) ) {
		$calendar_file = plugin_dir_path( __FILE__ ) . 'npmp-calendar.php';
		if ( file_exists( $calendar_file ) ) {
			require_once $calendar_file;
			if ( function_exists( 'npmp_register_event_post_type' ) ) {
				npmp_register_event_post_type();
			}
			if ( function_exists( 'npmp_register_event_taxonomy' ) ) {
				npmp_register_event_taxonomy();
			}
		}
	}

	flush_rewrite_rules();

	ob_end_clean();
}

/**
 * Migrate legacy donations stored in the custom table into the CPT.
 *
 * @return void
 */
function npmp_maybe_migrate_legacy_donations() {
	if ( get_option( 'npmp_donations_migrated_to_cpt', false ) ) {
		return;
	}

	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'npmp_donations';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $found !== $table ) {
		update_option( 'npmp_donations_migrated_to_cpt', 1 );
		return;
	}

		$rows = $wpdb->get_results( "SELECT * FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is derived from $wpdb->prefix for this one-time migration.
	if ( empty( $rows ) ) {
		update_option( 'npmp_donations_migrated_to_cpt', 1 );
		return;
	}

	$manager = NPMP_Donation_Manager::get_instance();

	foreach ( $rows as $row ) {
		$email  = sanitize_email( $row->email );
		$amount = (float) $row->amount;

		if ( ! $email || $amount <= 0 ) {
			continue;
		}

		$existing = get_posts(
			array(
				'post_type'      => NPMP_Donation_Manager::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy migration must locate donations by stored legacy ID.
					'meta_query'     => array(
					array(
						'key'   => '_npmp_legacy_donation_id',
						'value' => (int) $row->id,
					),
				),
			)
		);

		if ( $existing ) {
			continue;
		}

		$manager->log_donation(
			array(
				'email'      => $email,
				'name'       => sanitize_text_field( $row->name ),
				'amount'     => $amount,
				'frequency'  => sanitize_text_field( $row->frequency ),
				'gateway'    => sanitize_text_field( $row->gateway ),
				'created_at' => $row->created_at,
				'legacy_id'  => (int) $row->id,
			)
		);
	}

	update_option( 'npmp_donations_migrated_to_cpt', 1 );
}
add_action( 'plugins_loaded', 'npmp_maybe_migrate_legacy_donations', 40 );

/**
 * Migrate newsletter open/click tracking events off wp_posts and into the
 * dedicated wp_npmp_newsletter_opens / wp_npmp_newsletter_clicks tables (see
 * NPMP_Newsletter_Tracker, which now writes new events straight to those
 * tables instead of creating a post per open/click).
 *
 * Processes a bounded batch per plugins_loaded call rather than loading
 * every historical event at once. A site that's been sending newsletters
 * for a while can have tens of thousands of these posts, and migrating them
 * in a single unbounded query would risk exactly the kind of timeout/memory
 * problem this whole tracking-storage fix is about. Tracks a cursor (last
 * migrated post ID) in an option and re-runs on the next admin page load
 * until a pass finds nothing left, then marks itself done.
 *
 * @return void
 */
function npmp_maybe_migrate_newsletter_events() {
	if ( get_option( 'npmp_newsletter_events_migrated', false ) ) {
		return;
	}

	if ( ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
		return;
	}

	// Guarantee the destination tables exist before migrating into them,
	// regardless of plugins_loaded hook registration order (dbDelta is
	// idempotent, so this is cheap on every call after the first).
	npmp_create_newsletter_opens_table();
	npmp_create_newsletter_clicks_table();

	global $wpdb;

	$batch_size = 500;
	$cursor     = (int) get_option( 'npmp_newsletter_events_migration_cursor', 0 );

	// Pivot postmeta into one row per event in a single query, far cheaper
	// than a WP_Query + get_post_meta() per post for a bulk one-time job.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk migration, not a per-request query.
		$wpdb->prepare(
			"SELECT p.ID,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS newsletter_id,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS user_id,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS event_type,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS event_url,
					MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS event_time
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = %s AND p.ID > %d
			 GROUP BY p.ID
			 ORDER BY p.ID ASC
			 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts/$wpdb->postmeta are the table-name properties, not user input.
			NPMP_Newsletter_Manager::EVENT_NEWSLETTER_META,
			NPMP_Newsletter_Manager::EVENT_USER_META,
			NPMP_Newsletter_Manager::EVENT_TYPE_META,
			NPMP_Newsletter_Manager::EVENT_URL_META,
			NPMP_Newsletter_Manager::EVENT_TIME_META,
			NPMP_Newsletter_Manager::EVENT_POST_TYPE,
			$cursor,
			$batch_size
		)
	);

	if ( empty( $rows ) ) {
		delete_option( 'npmp_newsletter_events_migration_cursor' );
		update_option( 'npmp_newsletter_events_migrated', 1 );
		return;
	}

	$opens_table  = $wpdb->prefix . 'npmp_newsletter_opens';
	$clicks_table = $wpdb->prefix . 'npmp_newsletter_clicks';
	$migrated_ids = array();

	foreach ( $rows as $row ) {
		$newsletter_id = absint( $row->newsletter_id );
		$user_id       = absint( $row->user_id );
		$event_time    = $row->event_time ? $row->event_time : current_time( 'mysql' );

		if ( ! $newsletter_id || ! $user_id ) {
			$migrated_ids[] = (int) $row->ID; // Malformed legacy row; drop it, nothing to carry over.
			continue;
		}

		if ( NPMP_Newsletter_Manager::ACTION_OPEN === $row->event_type ) {
			$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk migration; IGNORE relies on the destination table's unique key for dedup.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$opens_table} (user_id, newsletter_id, opened_at) VALUES (%d, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
					$user_id,
					$newsletter_id,
					$event_time
				)
			);
			// false means a real DB error (e.g. table genuinely still
			// missing): leave the post in place so the next batch retries
			// it, instead of deleting source data an insert never captured.
			// 0 (IGNORE'd as an existing duplicate) still counts as success.
			if ( false !== $result ) {
				$migrated_ids[] = (int) $row->ID;
			}
		} elseif ( NPMP_Newsletter_Manager::ACTION_CLICK === $row->event_type ) {
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time bulk migration.
				$clicks_table,
				array(
					'newsletter_id' => $newsletter_id,
					'user_id'       => $user_id,
					'url'           => esc_url_raw( (string) $row->event_url ),
					'clicked_at'    => $event_time,
				),
				array( '%d', '%d', '%s', '%s' )
			);
			if ( false !== $result ) {
				$migrated_ids[] = (int) $row->ID;
			}
		} else {
			// Unrecognized event_type: leave the post alone rather than
			// silently drop data an unexpected future event type might have
			// carried.
			continue;
		}
	}

	if ( ! empty( $migrated_ids ) ) {
		$id_list = implode( ',', array_map( 'absint', $migrated_ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint()-sanitized above, not raw user input.
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$id_list})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint()-sanitized above, not raw user input.

		// Raw SQL deletes above don't go through wp_delete_post(), so they
		// never clear WordPress's post object cache. Harmless on a default
		// (non-persistent, per-request) cache, but a site running Redis or
		// Memcached would keep serving a deleted post's cached copy from
		// get_post() until that cache entry's own TTL expired.
		foreach ( $migrated_ids as $migrated_id ) {
			clean_post_cache( $migrated_id );
		}
	}

	$last_id = (int) $rows[ count( $rows ) - 1 ]->ID;
	update_option( 'npmp_newsletter_events_migration_cursor', $last_id );

	if ( count( $rows ) < $batch_size ) {
		delete_option( 'npmp_newsletter_events_migration_cursor' );
		update_option( 'npmp_newsletter_events_migrated', 1 );
	}
}
add_action( 'plugins_loaded', 'npmp_maybe_migrate_newsletter_events', 40 );

/**
 * Create (or update) the members table.
 *
 * @return void
 */
function npmp_create_members_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_members';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL,
		email VARCHAR(255) NOT NULL,
		membership_level VARCHAR(100) DEFAULT '',
		status VARCHAR(50) DEFAULT 'subscribed',
		phone VARCHAR(50) DEFAULT '',
		mobile VARCHAR(50) DEFAULT '',
		address_line1 VARCHAR(255) DEFAULT '',
		address_line2 VARCHAR(255) DEFAULT '',
		city VARCHAR(120) DEFAULT '',
		state VARCHAR(120) DEFAULT '',
		postal_code VARCHAR(30) DEFAULT '',
		country VARCHAR(120) DEFAULT '',
		tags VARCHAR(255) DEFAULT '',
		source VARCHAR(120) DEFAULT '',
		last_contacted DATETIME NULL,
		last_donation_at DATETIME NULL,
		donation_count INT UNSIGNED DEFAULT 0,
		donation_total DECIMAL(12,2) DEFAULT 0,
		notes TEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY email (email),
		KEY status (status),
		KEY last_donation (last_donation_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create (or update) the donations table.
 *
 * @return void
 */
function npmp_create_donations_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_donations';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(255) NOT NULL,
		email VARCHAR(255) NOT NULL,
		amount DECIMAL(10,2) NOT NULL,
		frequency VARCHAR(20) DEFAULT 'one_time',
		gateway VARCHAR(50) DEFAULT 'paypal',
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create (or update) the contacts table.
 *
 * @return void
 */
function npmp_create_contacts_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_contacts';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(255) NOT NULL UNIQUE,
		name VARCHAR(255),
		status ENUM('subscribed','unsubscribed','pending') DEFAULT 'pending',
		token VARCHAR(64),
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create (or update) the newsletter queue table.
 *
 * @return void
 */
function npmp_create_newsletter_queue_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_newsletter_queue';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		newsletter_id BIGINT NOT NULL,
		user_id BIGINT,
		email VARCHAR(255),
		status ENUM('pending','sent','failed') DEFAULT 'pending',
		queued_at DATETIME,
		sent_at DATETIME NULL,
		PRIMARY KEY (id),
		KEY newsletter_status (newsletter_id,status)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create (or update) the newsletter opens table.
 *
 * @return void
 */
function npmp_create_newsletter_opens_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_newsletter_opens';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT NOT NULL,
		newsletter_id BIGINT NOT NULL,
		opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		ip_address VARCHAR(100) DEFAULT '',
		user_agent TEXT,
		PRIMARY KEY (id),
		UNIQUE KEY user_newsletter (user_id, newsletter_id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create (or update) the newsletter clicks table.
 *
 * @return void
 */
function npmp_create_newsletter_clicks_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_newsletter_clicks';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		newsletter_id BIGINT NOT NULL,
		user_id BIGINT NOT NULL,
		url TEXT NOT NULL,
		clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		ip_address VARCHAR(100) DEFAULT '',
		user_agent TEXT,
		PRIMARY KEY (id),
		KEY newsletter_user (newsletter_id,user_id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Ensure the newsletter opens/clicks tracking tables exist on sites that
 * activated the plugin before these tables were introduced. Belt-and-braces
 * alongside the activation-time creation above, same reasoning as the other
 * npmp_maybe_create_*_table() functions in this file.
 *
 * @return void
 */
function npmp_maybe_create_newsletter_tracking_tables() {
	if ( get_option( 'npmp_newsletter_tracking_tables_created', false ) ) {
		return;
	}

	npmp_create_newsletter_opens_table();
	npmp_create_newsletter_clicks_table();
	update_option( 'npmp_newsletter_tracking_tables_created', 1 );
}
add_action( 'plugins_loaded', 'npmp_maybe_create_newsletter_tracking_tables', 40 );

/**
 * Create (or update) the gateway payment-verification audit log table.
 *
 * Stores the server-side verification result for each gateway capture
 * (PayPal today): capture id, verified status, and the raw API response,
 * so a successful donation leaves an audit trail instead of the
 * verification response being checked once and discarded. See
 * NPMP_Donation_Manager::log_payment_verification().
 *
 * @return void
 */
function npmp_create_payment_log_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_payment_log';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		donation_id BIGINT UNSIGNED NOT NULL,
		gateway VARCHAR(50) NOT NULL,
		gateway_order_id VARCHAR(100) DEFAULT '',
		gateway_capture_id VARCHAR(100) DEFAULT '',
		status VARCHAR(50) DEFAULT '',
		verified TINYINT(1) NOT NULL DEFAULT 0,
		raw_response LONGTEXT,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY donation_id (donation_id),
		KEY gateway_order (gateway, gateway_order_id)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Ensure the payment log table exists on sites that activated the plugin
 * before this table was introduced (activation hooks only run on a fresh
 * activation, not on every version's already-active install).
 *
 * @return void
 */
function npmp_maybe_create_payment_log_table() {
	if ( get_option( 'npmp_payment_log_table_created', false ) ) {
		return;
	}

	npmp_create_payment_log_table();
	update_option( 'npmp_payment_log_table_created', 1 );
}
add_action( 'plugins_loaded', 'npmp_maybe_create_payment_log_table', 40 );

/**
 * Create (or update) the weekly digest send queue table.
 *
 * The digest used to build its recipient list and mail everyone inline
 * inside a single wp-cron run, so a large subscriber list risked hitting
 * max_execution_time partway through with no way to resume. This table
 * lets npmp_process_weekly_digest() (includes/npmp-subscription-preferences.php)
 * enqueue recipients instead of mailing them directly, and a throttled
 * per-minute cron (npmp_process_digest_queue) drains it in small batches,
 * the same pattern the newsletter queue already uses.
 *
 * @return void
 */
function npmp_create_digest_queue_table() {
	global $wpdb;

	$table   = $wpdb->prefix . 'npmp_digest_queue';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(255) NOT NULL,
		status ENUM('pending','sent','failed') DEFAULT 'pending',
		queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		sent_at DATETIME NULL,
		PRIMARY KEY (id),
		KEY status_queued (status, queued_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Ensure the digest queue table exists on sites that activated the plugin
 * before this table was introduced.
 *
 * @return void
 */
function npmp_maybe_create_digest_queue_table() {
	if ( get_option( 'npmp_digest_queue_table_created', false ) ) {
		return;
	}

	npmp_create_digest_queue_table();
	update_option( 'npmp_digest_queue_table_created', 1 );
}
add_action( 'plugins_loaded', 'npmp_maybe_create_digest_queue_table', 40 );

/**
 * Seed default newsletter-related options.
 *
 * @return void
 */
function npmp_initialize_default_newsletter_settings() {
	if ( false === get_option( 'npmp_newsletter_can_spam_footer' ) ) {
		update_option(
			'npmp_newsletter_can_spam_footer',
			__(
				"You're receiving this email from [organization] at [address].\nTo unsubscribe, click here: [unsubscribe_url]",
				'nonprofit-manager'
			)
		);
	}

	if ( false === get_option( 'npmp_newsletter_rate_limit' ) ) {
		update_option( 'npmp_newsletter_rate_limit', 10 );
	}
}

/**
 * Ensure a working unsubscribe page exists and is wired to the form settings.
 *
 * Creates a published page containing [npmp_email_unsubscribe] on first
 * activation, then records its ID so CAN-SPAM unsubscribe links resolve out of
 * the box. Skips when an unsubscribe page is already configured and published,
 * and reuses an existing page that already hosts the shortcode.
 *
 * @return void
 */
function npmp_maybe_create_unsubscribe_page() {
	$option   = 'npmp_membership_form_settings';
	$settings = get_option( $option, array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$existing = absint( $settings['unsubscribe_page_id'] ?? 0 );
	if ( $existing && 'publish' === get_post_status( $existing ) ) {
		return; // Already set up.
	}

	// Reuse a published page that already hosts the shortcode, if one exists.
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			's'              => '[npmp_email_unsubscribe]',
		)
	);

	if ( ! empty( $found ) ) {
		$page_id = (int) $found[0];
	} else {
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Unsubscribe', 'nonprofit-manager' ),
				'post_name'    => 'unsubscribe',
				'post_content' => '[npmp_email_unsubscribe]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	}

	if ( ! $page_id || is_wp_error( $page_id ) ) {
		return;
	}

	$settings['unsubscribe_page_id'] = (int) $page_id;
	update_option( $option, $settings );
}

/**
 * Determine if newsletter functionality is currently enabled.
 *
 * @return bool
 */
function npmp_newsletters_enabled() {
	$features = get_option(
		'npmp_enabled_features',
		array(
			'members'     => true,
			'newsletters' => false,
		)
	);

	return ( ! empty( $features['members'] ) && ! empty( $features['newsletters'] ) );
}

/**
 * Schedule the newsletter processing cron (if enabled).
 *
 * @return void
 */
function npmp_schedule_newsletter_cron() {
	if ( ! npmp_newsletters_enabled() ) {
		return;
	}

	if ( ! wp_next_scheduled( 'npmp_process_queued_newsletters' ) ) {
		wp_schedule_event( time(), 'every_minute', 'npmp_process_queued_newsletters' );
	}
}

/**
 * Clear the newsletter processing cron hook.
 *
 * @return void
 */
function npmp_clear_newsletter_cron() {
	wp_clear_scheduled_hook( 'npmp_process_queued_newsletters' );
	wp_clear_scheduled_hook( 'npmp_send_weekly_digest' );
	wp_clear_scheduled_hook( 'npmp_process_digest_queue' );
	// Pending async notification blasts carry per-post args, so clear every
	// instance regardless of args.
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'npmp_async_post_notification' );
	}
}

/**
 * Handle feature flag changes to keep cron in sync.
 *
 * @param array $old_value Previous option value.
 * @param array $value     New option value.
 * @return void
 */
function npmp_handle_feature_toggle( $old_value, $value ) {
	$old_enabled = ! empty( $old_value['members'] ) && ! empty( $old_value['newsletters'] );
	$new_enabled = ! empty( $value['members'] ) && ! empty( $value['newsletters'] );
	$old_calendar = ! empty( $old_value['calendar'] );
	$new_calendar = ! empty( $value['calendar'] );

	if ( $new_enabled && ! $old_enabled ) {
		npmp_schedule_newsletter_cron();
	} elseif ( ! $new_enabled && $old_enabled ) {
		npmp_clear_newsletter_cron();
	}

	if ( $new_calendar && ! $old_calendar ) {
		$calendar_file = plugin_dir_path( __FILE__ ) . 'npmp-calendar.php';
		if ( file_exists( $calendar_file ) ) {
			require_once $calendar_file;
			if ( function_exists( 'npmp_register_event_post_type' ) ) {
				npmp_register_event_post_type();
			}
			if ( function_exists( 'npmp_register_event_taxonomy' ) ) {
				npmp_register_event_taxonomy();
			}
		}
		flush_rewrite_rules();
	} elseif ( ! $new_calendar && $old_calendar ) {
		flush_rewrite_rules();
	}
}
add_action( 'update_option_npmp_enabled_features', 'npmp_handle_feature_toggle', 10, 2 );

/**
 * Register the cron callback once all plugin files have loaded.
 *
 * @return void
 */
function npmp_register_newsletter_cron_handler() {
	if ( class_exists( 'NPMP_Newsletter_Manager' ) ) {
		add_action( 'npmp_process_queued_newsletters', array( 'NPMP_Newsletter_Manager', 'process_queue' ) );
	}
}
add_action( 'plugins_loaded', 'npmp_register_newsletter_cron_handler' );

/**
 * Add a 60-second cron interval for queue processing.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function npmp_register_minutely_schedule( $schedules ) {
	$schedules['every_minute'] = array(
		'interval' => 60,
		'display'  => __( 'Every Minute', 'nonprofit-manager' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'npmp_register_minutely_schedule' );
