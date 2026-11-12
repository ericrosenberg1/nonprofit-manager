<?php
/**
 * File path: includes/import/newsletter-importer.php
 *
 * Importer and Switch Plugins source for The Newsletter Plugin (plugin name
 * "Newsletter", folder newsletter). Read from Newsletter 9.5.7's own source:
 *
 * - Subscribers live in {prefix}newsletter (NEWSLETTER_USERS_TABLE): email,
 *   name, surname, status, created (a TIMESTAMP), and one list_N column per
 *   list (1 means on the list).
 * - status is one letter (TNP_User): C confirmed, S not confirmed,
 *   U unsubscribed, B bounced, P complained, T temporary (a signup that never
 *   finished, so it stays behind).
 * - Options are newsletter_<module> arrays (NewsletterModuleBase::get_prefix()).
 *   newsletter_lists holds list_N => name. newsletter_main holds sender_name,
 *   sender_email, and reply_to. newsletter_subscription holds noconfirmation,
 *   0 for double opt-in.
 * - The plugin ships no uninstall code, so its data stays after you delete it.
 *
 * Read by name and nothing else: those three options and the subscriber
 * columns above. Never read: the SMTP and delivery add-on settings, licence
 * keys, or any API key.
 *
 * Contacts go through the MailPoet importer's writer
 * (npmp_mailpoet_import_subscriber()) with source 'newsletter', so status
 * merging, names, signup dates, groups (Pro), and the free total all work the
 * same way.
 *
 * Data removal stays unavailable: the plugin's add-ons keep their data under
 * the same newsletter_ names, and we can't tell the two apart safely.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_TNP_BATCH' ) ) {
	define( 'NPMP_TNP_BATCH', 100 );
}
if ( ! defined( 'NPMP_TNP_SECONDS' ) ) {
	define( 'NPMP_TNP_SECONDS', 20 );
}

// =====================================================================
// Pure helpers, covered by tests/test-newsletter-importer.php
// =====================================================================

/**
 * Status groups you can keep or leave out, key => the letters in each.
 *
 * @return array<string,string[]>
 */
function npmp_tnp_status_groups() {
	return array(
		'confirmed'     => array( 'C' ),
		'not_confirmed' => array( 'S' ),
		'unsubscribed'  => array( 'U' ),
		'bounced'       => array( 'B', 'P' ),
	);
}

/**
 * The status group for a stored status letter, or '' for a row that stays
 * behind (a temporary signup).
 *
 * @param mixed $raw newsletter.status.
 * @return string
 */
function npmp_tnp_status_group( $raw ) {
	$raw = is_scalar( $raw ) ? strtoupper( trim( (string) $raw ) ) : '';
	if ( 'T' === $raw ) {
		return '';
	}
	foreach ( npmp_tnp_status_groups() as $group => $letters ) {
		if ( in_array( $raw, $letters, true ) ) {
			return $group;
		}
	}
	return 'not_confirmed';
}

/**
 * The MailPoet status a Newsletter status reads as, for the shared writer
 * (npmp_mailpoet_map_status()). Confirmed becomes subscribed, not confirmed
 * waits as pending, and unsubscribed, bounced, and complained all become
 * unsubscribed. A letter we don't know waits as pending.
 *
 * @param mixed $raw newsletter.status.
 * @return string
 */
function npmp_tnp_mailpoet_status( $raw ) {
	$map = array(
		'confirmed'     => 'subscribed',
		'not_confirmed' => 'unconfirmed',
		'unsubscribed'  => 'unsubscribed',
		'bounced'       => 'bounced',
	);
	$group = npmp_tnp_status_group( $raw );
	return '' === $group ? '' : $map[ $group ];
}

/**
 * List names from the newsletter_lists option, list number => name.
 *
 * @param mixed $option Stored option.
 * @param int   $max    Highest list number.
 * @return array<int,string>
 */
function npmp_tnp_list_names( $option, $max = 40 ) {
	$out = array();
	if ( ! is_array( $option ) ) {
		return $out;
	}
	for ( $i = 1; $i <= (int) $max; $i++ ) {
		$name = $option[ 'list_' . $i ] ?? '';
		$name = is_scalar( $name ) ? trim( wp_strip_all_tags( (string) $name ) ) : '';
		if ( '' !== $name ) {
			$out[ $i ] = $name;
		}
	}
	return $out;
}

/**
 * The sender and signup settings in the shape npmp_mailpoet_settings_plan()
 * reads. Only values saved in the options count, never Newsletter's
 * defaults.
 *
 * @param mixed $main         newsletter_main.
 * @param mixed $subscription newsletter_subscription.
 * @return array{from_name:string,from_email:string,reply_to:string,double_optin:?bool}
 */
function npmp_tnp_read_settings( $main, $subscription ) {
	$clean = static function ( $value ) {
		return is_scalar( $value ) ? trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) ) : '';
	};
	$main         = is_array( $main ) ? $main : array();
	$subscription = is_array( $subscription ) ? $subscription : array();

	$optin = null;
	if ( array_key_exists( 'noconfirmation', $subscription ) && is_scalar( $subscription['noconfirmation'] ) ) {
		$optin = '0' === trim( (string) $subscription['noconfirmation'] );
	}
	return array(
		'from_name'    => $clean( $main['sender_name'] ?? '' ),
		'from_email'   => $clean( $main['sender_email'] ?? '' ),
		'reply_to'     => $clean( $main['reply_to'] ?? '' ),
		'double_optin' => $optin,
	);
}

// =====================================================================
// Reading The Newsletter Plugin's data
// =====================================================================

/**
 * The subscriber table, when it exists.
 *
 * @return string
 */
function npmp_tnp_table() {
	global $wpdb;
	$name = $wpdb->prefix . 'newsletter';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ? $name : '';
}

/**
 * Lists that have a name and a column, list number => name.
 *
 * @return array<int,string>
 */
function npmp_tnp_lists() {
	global $wpdb;
	$table = npmp_tnp_table();
	$names = npmp_tnp_list_names( get_option( 'newsletter_lists', array() ), defined( 'NEWSLETTER_LIST_MAX' ) ? (int) NEWSLETTER_LIST_MAX : 40 );
	if ( ! $table || ! $names ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	$columns = array_flip( (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
	return array_filter(
		$names,
		function ( $i ) use ( $columns ) {
			return isset( $columns[ 'list_' . $i ] );
		},
		ARRAY_FILTER_USE_KEY
	);
}

/**
 * Subscribers by status group. Temporary signups don't count.
 *
 * @return array<string,int>
 */
function npmp_tnp_counts() {
	global $wpdb;
	$out   = array_fill_keys( array_keys( npmp_tnp_status_groups() ), 0 );
	$table = npmp_tnp_table();
	if ( ! $table ) {
		return $out;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) {
		$group = npmp_tnp_status_group( $row['status'] );
		if ( '' !== $group ) {
			$out[ $group ] += (int) $row['n'];
		}
	}
	return $out;
}

/**
 * One batch of subscribers, oldest ID first, in the MailPoet writer's shape.
 *
 * @param int               $after Last subscriber ID read.
 * @param int               $limit Rows.
 * @param array<int,string> $lists npmp_tnp_lists().
 * @return object[] id, email, first_name, last_name, status (MailPoet's word),
 *                  group, created, confirmed, lists (names).
 */
function npmp_tnp_read_rows( $after, $limit, array $lists ) {
	global $wpdb;
	$table = npmp_tnp_table();
	if ( ! $table ) {
		return array();
	}
	$list_cols = '';
	foreach ( array_keys( $lists ) as $i ) {
		$list_cols .= ', list_' . (int) $i;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and list column names are built here, values are prepared.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, name, surname, status, UNIX_TIMESTAMP(created) AS created_ts{$list_cols} FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$on = array();
		foreach ( $lists as $i => $name ) {
			if ( 1 === (int) ( $row[ 'list_' . $i ] ?? 0 ) ) {
				$on[] = $name;
			}
		}
		$out[] = (object) array(
			'id'         => (int) $row['id'],
			'email'      => (string) $row['email'],
			'first_name' => (string) $row['name'],
			'last_name'  => (string) $row['surname'],
			'status'     => npmp_tnp_mailpoet_status( $row['status'] ),
			'group'      => npmp_tnp_status_group( $row['status'] ),
			'created'    => (int) $row['created_ts'],
			'confirmed'  => 0,
			'lists'      => $on,
		);
	}
	return $out;
}

/**
 * Sender and signup settings next to yours.
 *
 * @return array npmp_mailpoet_settings_plan() output.
 */
function npmp_tnp_settings_plan() {
	return npmp_mailpoet_settings_plan(
		npmp_tnp_read_settings( get_option( 'newsletter_main', array() ), get_option( 'newsletter_subscription', array() ) ),
		npmp_mailpoet_current_settings()
	);
}

// =====================================================================
// The import
// =====================================================================

/**
 * Empty results.
 *
 * @return array
 */
function npmp_tnp_empty_results() {
	return array(
		'created'           => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'invalid'           => 0,
		'left'              => 0,
		'over_limit'        => 0,
		'cap_reached'       => false,
		'kept_unsubscribed' => 0,
		'failed'            => array(),
		'settings'          => array(),
	);
}

/**
 * Saved progress.
 *
 * @return array{cursor:int,running:bool,finished_at:int,handled:int,skip:string[],settings:string[],results:array}
 */
function npmp_tnp_state() {
	$state = get_option( 'npmp_tnp_import', array() );
	$state = is_array( $state ) ? $state : array();
	return array(
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'handled'     => (int) ( $state['handled'] ?? 0 ),
		'skip'        => array_values( array_intersect( (array) ( $state['skip'] ?? array() ), array_keys( npmp_tnp_status_groups() ) ) ),
		'settings'    => array_values( array_map( 'strval', (array) ( $state['settings'] ?? array() ) ) ),
		'results'     => array_merge( npmp_tnp_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param array $state State.
 * @return void
 */
function npmp_tnp_save_state( array $state ) {
	update_option( 'npmp_tnp_import', $state, false );
}

/**
 * Start a fresh run.
 *
 * @param string[] $skip     Status groups to leave out.
 * @param string[] $settings Settings plan keys to copy.
 * @return void
 */
function npmp_tnp_start( array $skip, array $settings ) {
	$state             = npmp_tnp_state();
	$state['cursor']   = 0;
	$state['running']  = true;
	$state['handled']  = 0;
	$state['skip']     = array_values( array_intersect( array_map( 'strval', $skip ), array_keys( npmp_tnp_status_groups() ) ) );
	$state['settings'] = array_values( array_map( 'sanitize_key', $settings ) );
	$state['results']  = npmp_tnp_empty_results();

	$state['results']['settings'] = npmp_import_apply_settings_plan( npmp_tnp_settings_plan(), $state['settings'] );
	npmp_tnp_save_state( $state );
}

/**
 * Import batches until the subscribers run out or the time is up.
 *
 * The free plugin reads up to npmp_import_max_rows() subscribers in one run
 * and adds new contacts only while the site has free places left
 * (npmp_import_free_remaining(), shared with every import). Past that it
 * still updates contacts you already have. Pro lifts both limits.
 *
 * @param int $seconds Time budget.
 * @param int $limit   Rows per batch.
 * @return array State after the run.
 */
function npmp_tnp_run( $seconds = NPMP_TNP_SECONDS, $limit = NPMP_TNP_BATCH ) {
	$state = npmp_tnp_state();
	if ( ! $state['running'] || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return $state;
	}
	$max   = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
	$skip  = array_flip( $state['skip'] );
	$lists = npmp_tnp_lists();
	$start = microtime( true );
	$done  = false;

	do {
		$rows = npmp_tnp_read_rows( $state['cursor'], $limit, $lists );
		if ( $rows ) {
			$existing = npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) );
		}
		foreach ( $rows as $row ) {
			if ( '' === $row->group ) {
				$state['cursor'] = $row->id;
				continue;
			}
			if ( isset( $skip[ $row->group ] ) ) {
				++$state['results']['left'];
				$state['cursor'] = $row->id;
				continue;
			}
			if ( $state['handled'] >= $max ) {
				$state['results']['cap_reached'] = true;
				$done                            = true;
				break;
			}
			$email = strtolower( sanitize_email( $row->email ) );
			$known = $existing[ $email ] ?? null;
			if ( ! $known && is_email( $email ) && function_exists( 'npmp_import_free_remaining' ) && npmp_import_free_remaining() < 1 ) {
				$state['results']['cap_reached'] = true;
				++$state['results']['over_limit'];
				$state['cursor'] = $row->id;
				continue;
			}
			$result = npmp_mailpoet_import_subscriber( $row, $known, $row->lists, 'newsletter' );
			if ( 'invalid' === $result['result'] ) {
				++$state['results']['invalid'];
			} elseif ( 'error' === $result['result'] ) {
				if ( count( $state['results']['failed'] ) < 50 ) {
					$state['results']['failed'][] = array(
						'email' => $row->email,
						'error' => $result['message'],
					);
				}
			} else {
				++$state['results'][ $result['result'] ];
				++$state['handled'];
				if ( ! $known ) {
					// A second row with this address in the same batch updates this contact.
					$existing[ $email ] = array(
						'id'     => (int) $result['member_id'],
						'status' => '',
					);
				}
				if ( $result['kept_unsubscribed'] ) {
					++$state['results']['kept_unsubscribed'];
				}
			}
			$state['cursor'] = $row->id;
		}
		if ( count( $rows ) < $limit ) {
			$done = true;
		}
		npmp_tnp_save_state( $state );
	} while ( ! $done && ( microtime( true ) - $start ) < $seconds );

	if ( $done ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		npmp_tnp_save_state( $state );
		update_option( 'npmp_tnp_imported', 1, true );
	}
	return $state;
}

/**
 * Contacts the importer made.
 *
 * @return int
 */
function npmp_tnp_count_contacts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_source' AND pm.meta_value = 'newsletter' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
}

/**
 * Subscribers (temporary signups aside) whose address isn't on any contact.
 *
 * @return int
 */
function npmp_tnp_count_left_behind() {
	global $wpdb;
	$table = npmp_tnp_table();
	if ( ! $table ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no input.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} s WHERE s.status <> 'T' AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND LOWER(pm.meta_value) = LOWER(s.email) AND p.post_type = 'npmp_contact' AND p.post_status = 'publish' )" );
}

// =====================================================================
// Switch Plugins source
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_tnp_register_hub_source' );

/**
 * The Newsletter Plugin and installed plugins that require it, basename => name.
 *
 * @return array<string,string>
 */
function npmp_tnp_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$requires = array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) );
		if ( 'newsletter/plugin.php' === $file || in_array( 'newsletter', $requires, true ) ) {
			$out[ $file ] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * Add The Newsletter Plugin to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_tnp_register_hub_source( $sources ) {
	$sources['newsletter'] = array(
		'name'              => 'The Newsletter Plugin',
		'covers'            => __( 'Subscribers, signup dates, lists, and sender settings', 'nonprofit-manager' ),
		'feature'           => __( 'Contacts and newsletters', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'Turn each list into a group', 'nonprofit-manager' ) ),
		'plugin_folders'    => array( 'newsletter' ),
		'has_data'          => function () {
			return '' !== npmp_tnp_table();
		},
		'is_imported'       => function () {
			return (bool) get_option( 'npmp_tnp_imported', 0 );
		},
		'can_import'        => function () {
			return '' !== npmp_tnp_table() ? true : __( 'You need The Newsletter Plugin\'s subscriber table to import, and this site doesn\'t have one. Install The Newsletter Plugin, turn it on once so it sets up its tables, and come back to this screen.', 'nonprofit-manager' );
		},
		'import_url'        => 'npmp_tnp_url',
		'import_summary'    => function () {
			$made = npmp_tnp_count_contacts();
			if ( ! $made ) {
				return __( 'You brought over your subscribers\' details and your sender settings.', 'nonprofit-manager' );
			}
			return sprintf(
				/* translators: %s: number of contacts. */
				_n( 'You have %s new contact from The Newsletter Plugin.', 'You have %s new contacts from The Newsletter Plugin.', $made, 'nonprofit-manager' ),
				number_format_i18n( $made )
			);
		},
		'counts'            => 'npmp_tnp_hub_counts',
		'checklist'         => array(
			__( 'Open a few of your new contacts and check their names, emails, and signup dates.', 'nonprofit-manager' ),
			__( 'Check the sender name and address in your email settings.', 'nonprofit-manager' ),
			__( 'Put a new signup form on every page that had a form from The Newsletter Plugin.', 'nonprofit-manager' ),
			__( 'Send a test newsletter to yourself.', 'nonprofit-manager' ),
		),
		'installed_plugins' => 'npmp_tnp_installed_plugins',
		'data_removal'      => array(
			'reason' => __( 'The Newsletter Plugin\'s add-ons keep their data under the same names as the plugin, and we can\'t tell the two apart safely. We leave the data in your database, and you can leave the data in place.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_tnp_hub_counts() {
	$left      = npmp_tnp_count_left_behind();
	$capped    = function_exists( 'npmp_import_is_capped' ) ? npmp_import_is_capped() : ( function_exists( 'npmp_import_max_rows' ) && npmp_import_max_rows() < PHP_INT_MAX );
	$places    = function_exists( 'npmp_import_free_remaining' ) ? npmp_import_free_remaining() : PHP_INT_MAX;
	$left_note = __( 'Subscribers you chose to leave out stay in The Newsletter Plugin.', 'nonprofit-manager' );
	if ( $left && $capped && $places < $left ) {
		$left_note = sprintf(
			/* translators: %s: free import limit, such as 100. */
			__( 'In the free version you can import %s contacts in all, so the rest are still only in The Newsletter Plugin.', 'nonprofit-manager' ),
			number_format_i18n( defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ? NPMP_IMPORT_FREE_MAX_ROWS : npmp_import_max_rows() )
		);
	}
	return array(
		array(
			'label' => __( 'Subscribers', 'nonprofit-manager' ),
			'old'   => array_sum( npmp_tnp_counts() ),
			'new'   => npmp_tnp_count_contacts(),
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
			'old'   => count( npmp_tnp_lists() ),
			'new'   => null,
			'note'  => npmp_mailpoet_groups_supported()
				? __( 'Each list became a group.', 'nonprofit-manager' )
				: __( 'Lists become groups with Pro.', 'nonprofit-manager' ),
		),
	);
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_tnp_register_menu', 30 );
add_action( 'admin_head', 'npmp_tnp_hide_menu' );
add_action( 'admin_post_npmp_tnp_import', 'npmp_tnp_post' );
add_action( 'admin_enqueue_scripts', 'npmp_tnp_enqueue' );

/**
 * URL of the screen.
 *
 * @return string
 */
function npmp_tnp_url() {
	return admin_url( 'admin.php?page=npmp_tnp_import' );
}

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_tnp_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import from The Newsletter Plugin', 'nonprofit-manager' ),
		__( 'Import from The Newsletter Plugin', 'nonprofit-manager' ),
		'manage_options',
		'npmp_tnp_import',
		'npmp_tnp_render_page'
	);
}

/**
 * Take the screen out of the menu on admin_head (removing it on admin_menu
 * makes WordPress refuse the screen).
 *
 * @return void
 */
function npmp_tnp_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_tnp_import' );
}

/**
 * The hub's styles and its carry-on script.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_tnp_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_tnp_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Handle Import and each carry-on request.
 *
 * @return void
 */
function npmp_tnp_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	check_admin_referer( 'npmp_tnp_import', 'npmp_tnp_nonce' );
	if ( ! empty( $_POST['restart'] ) ) {
		$keep     = isset( $_POST['keep'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['keep'] ) ) : array();
		$settings = isset( $_POST['settings'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['settings'] ) ) : array();
		npmp_tnp_start( array_diff( array_keys( npmp_tnp_status_groups() ), $keep ), $settings );
	}
	npmp_tnp_run();
	wp_safe_redirect( npmp_tnp_url() );
	exit;
}

/**
 * Hidden fields for the forms.
 *
 * @return void
 */
function npmp_tnp_form_fields() {
	echo '<input type="hidden" name="action" value="npmp_tnp_import">';
	wp_nonce_field( 'npmp_tnp_import', 'npmp_tnp_nonce' );
}

/**
 * Labels for the status groups.
 *
 * @return array<string,string>
 */
function npmp_tnp_status_labels() {
	return array(
		'confirmed'     => __( 'Confirmed', 'nonprofit-manager' ),
		'not_confirmed' => __( 'Not confirmed (they come over as Pending)', 'nonprofit-manager' ),
		'unsubscribed'  => __( 'Unsubscribed', 'nonprofit-manager' ),
		'bounced'       => __( 'Bounced or marked as spam (they come over as Unsubscribed)', 'nonprofit-manager' ),
	);
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_tnp_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	$state = npmp_tnp_state();
	echo '<div class="wrap npmp-hub-wrap">';
	echo '<h1>' . esc_html__( 'Import from The Newsletter Plugin', 'nonprofit-manager' ) . '</h1>';
	echo '<p><a href="' . esc_url( npmp_import_hub_url( 'newsletter' ) ) . '">&larr; ' . esc_html__( 'Back to Switch Plugins', 'nonprofit-manager' ) . '</a></p>';

	if ( ! npmp_tnp_table() ) {
		echo '<p>' . esc_html__( 'You need The Newsletter Plugin\'s subscriber table to import, and this site doesn\'t have one.', 'nonprofit-manager' ) . '</p></div>';
		return;
	}

	if ( $state['running'] ) {
		npmp_tnp_render_results( $state['results'] );
		echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
		npmp_tnp_form_fields();
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form></div>';
		return;
	}

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_tnp_render_results( $state['results'] );
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( 'newsletter' ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a></p>';
	}

	$counts = npmp_tnp_counts();
	$lists  = npmp_tnp_lists();
	$plan   = npmp_tnp_settings_plan();
	$labels = npmp_tnp_status_labels();

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_tnp_form_fields();
	echo '<input type="hidden" name="restart" value="1">';

	echo '<h2>' . esc_html__( 'Who comes over', 'nonprofit-manager' ) . '</h2><fieldset class="npmp-hub-checklist">';
	foreach ( $labels as $key => $label ) {
		printf(
			'<p><label><input type="checkbox" name="keep[]" value="%1$s"%2$s> %3$s: %4$s</label></p>',
			esc_attr( $key ),
			checked( ! in_array( $key, $state['skip'], true ), true, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $counts[ $key ] ) )
		);
	}
	echo '</fieldset>';
	echo '<ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Each subscriber becomes a contact with their name, email, and signup date, or joins the contact you already have with that email.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Someone who unsubscribed on either side stays unsubscribed.', 'nonprofit-manager' ) . '</li>';
	if ( $lists ) {
		$names = implode( ', ', $lists );
		echo '<li>' . esc_html(
			npmp_mailpoet_groups_supported()
				/* translators: %s: list names. */
				? sprintf( __( 'Each list becomes a group, and each subscriber joins the groups for their lists: %s.', 'nonprofit-manager' ), $names )
				/* translators: %s: list names. */
				: sprintf( __( 'Your lists stay in The Newsletter Plugin, since groups come with Pro: %s.', 'nonprofit-manager' ), $names )
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'No subscriber gets an email.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( function_exists( 'npmp_is_pro' ) && ! npmp_is_pro() && function_exists( 'npmp_import_free_remaining' ) ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: free total of new contacts, 2: places left. */
				__( 'With the free plugin, you can add up to %1$s new contacts through imports, and you have %2$s places left. A subscriber who\'s already a contact doesn\'t use a place.', 'nonprofit-manager' ),
				number_format_i18n( NPMP_IMPORT_FREE_MAX_ROWS ),
				number_format_i18n( npmp_import_free_remaining() )
			)
		) . '</p>';
	}

	echo '<h2>' . esc_html__( 'Settings', 'nonprofit-manager' ) . '</h2>';
	$names = array(
		'from_name'    => __( 'Sender name', 'nonprofit-manager' ),
		'from_email'   => __( 'Sender email', 'nonprofit-manager' ),
		'reply_to'     => __( 'Reply-to address', 'nonprofit-manager' ),
		'double_optin' => __( 'Signup confirmation email', 'nonprofit-manager' ),
	);
	if ( ! $plan ) {
		echo '<p>' . esc_html__( 'The Newsletter Plugin has no sender or signup settings saved.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $plan as $key => $row ) {
		$value = 'double_optin' === $key ? ( $row['mailpoet'] ? __( 'On', 'nonprofit-manager' ) : __( 'Off', 'nonprofit-manager' ) ) : (string) $row['mailpoet'];
		$label = $names[ $key ] . ': ' . $value;
		if ( 'change' === $row['state'] ) {
			printf(
				'<li><label><input type="checkbox" name="settings[]" value="%1$s"%2$s> %3$s</label></li>',
				esc_attr( $key ),
				checked( $row['checked'], true, false ),
				esc_html( $label )
			);
		} elseif ( 'same' === $row['state'] ) {
			/* translators: %s: setting and value. */
			echo '<li>' . esc_html( sprintf( __( '%s (you already have the same setting)', 'nonprofit-manager' ), $label ) ) . '</li>';
		} else {
			/* translators: %s: setting and value. */
			echo '<li>' . esc_html( sprintf( __( '%s (Nonprofit Manager has no matching setting, so this one stays in The Newsletter Plugin)', 'nonprofit-manager' ), $label ) ) . '</li>';
		}
	}
	echo '</ul>';

	echo '<h2>' . esc_html__( 'What you leave in the old plugin', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Sent newsletters, open and click stats, and signups that never finished.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Delivery settings, license keys, and add-on settings.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	$label = $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import subscribers', 'nonprofit-manager' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
	if ( $state['finished_at'] ) {
		echo '<p class="description">' . esc_html__( 'Running it again updates the contacts you have and adds anyone new.', 'nonprofit-manager' ) . '</p>';
	}
	echo '</form></div>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_tnp_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of contacts. */
		sprintf( __( 'New contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['created'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts updated: %s', 'nonprofit-manager' ), number_format_i18n( $r['updated'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts with nothing to change: %s', 'nonprofit-manager' ), number_format_i18n( $r['unchanged'] ) ),
	);
	if ( $r['left'] ) {
		/* translators: %s: number of subscribers. */
		$lines[] = sprintf( __( 'Subscribers you left out: %s', 'nonprofit-manager' ), number_format_i18n( $r['left'] ) );
	}
	if ( $r['over_limit'] ) {
		/* translators: %s: number of subscribers. */
		$lines[] = sprintf( __( 'Subscribers past the free limit: %s. Pro brings them over.', 'nonprofit-manager' ), number_format_i18n( $r['over_limit'] ) );
	}
	if ( $r['kept_unsubscribed'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'Contacts who stay unsubscribed here: %s', 'nonprofit-manager' ), number_format_i18n( $r['kept_unsubscribed'] ) );
	}
	if ( $r['invalid'] ) {
		/* translators: %s: number of subscribers. */
		$lines[] = sprintf( __( 'Subscribers we skipped for a missing or broken email: %s', 'nonprofit-manager' ), number_format_i18n( $r['invalid'] ) );
	}
	if ( $r['settings'] ) {
		/* translators: %s: number of settings. */
		$lines[] = sprintf( __( 'Settings copied: %s', 'nonprofit-manager' ), number_format_i18n( count( (array) $r['settings'] ) ) );
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	foreach ( (array) $r['failed'] as $fail ) {
		echo '<li>' . esc_html( $fail['email'] . ': ' . $fail['error'] ) . '</li>';
	}
	echo '</ul>';
}
