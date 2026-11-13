<?php
/**
 * File path: includes/import/gift-csv-source.php
 *
 * Past gifts from a file: a CSV exported from Zeffy, Givebutter, Donorbox, or
 * a spreadsheet, brought in through the same gift engine GiveWP and
 * Charitable use (gift-importer.php, past-gifts.php). Donors become contacts
 * under the free total, and gifts land as past gifts, never as charges, so no
 * receipt goes out and no automation fires.
 *
 * Steps on the screen: upload, check the columns, check what comes over,
 * import in batches. The upload uses the contact importer's private folder
 * and its delete-later event (admin-import.php). The parsing rules and both
 * passes over the file live in gift-csv-parse.php.
 *
 * This source has no plugin to turn off, so it isn't on Switch Plugins: its
 * Deactivate, Remove plugin, and Remove data steps would have nothing to act
 * on. It opens from a card on the Import screen.
 *
 * Free and Pro: the same split as GiveWP. A gift from a recurring plan comes
 * over as a past gift with its frequency, in free, and no plan is created.
 * Pro adds nothing here except lifting the free total.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/gift-csv-parse.php';

/** Option holding the file you're working on. */
if ( ! defined( 'NPMP_GIFT_CSV_JOB' ) ) {
	define( 'NPMP_GIFT_CSV_JOB', 'npmp_gift_csv_job' );
}

// =====================================================================
// The job
// =====================================================================

/**
 * The file you're working on.
 *
 * @return array Empty when there's none.
 */
function npmp_gift_csv_job() {
	$job = get_option( NPMP_GIFT_CSV_JOB, array() );
	return is_array( $job ) ? $job : array();
}

/**
 * Save the job.
 *
 * @param array $job Job.
 * @return void
 */
function npmp_gift_csv_save_job( array $job ) {
	update_option( NPMP_GIFT_CSV_JOB, $job, false );
}

/**
 * Forget the job and delete its files.
 *
 * @return void
 */
function npmp_gift_csv_forget_job() {
	$job = npmp_gift_csv_job();
	foreach ( array( 'file', 'index' ) as $key ) {
		if ( ! empty( $job[ $key ] ) && function_exists( 'npmp_delete_import_file' ) ) {
			npmp_delete_import_file( (string) $job[ $key ] );
		}
	}
	delete_option( NPMP_GIFT_CSV_JOB );
	delete_option( 'npmp_gift_import_' . NPMP_GIFT_CSV_SOURCE );
}

/**
 * Settings the parser reads the job's file with.
 *
 * @param array $job Job.
 * @return array
 */
function npmp_gift_csv_job_settings( array $job ) {
	$s        = (array) ( $job['settings'] ?? array() );
	$timezone = 'utc' === ( $s['timezone'] ?? '' ) ? 'UTC' : ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' );
	return npmp_gift_csv_settings(
		array(
			'timezone'   => $timezone,
			'date_order' => (string) ( $s['date_order_used'] ?? 'mdy' ),
			'currency'   => (string) ( $s['currency'] ?? npmp_currency() ),
			'accept'     => (array) ( $s['accept'] ?? array() ),
			'preset'     => (string) ( $job['preset'] ?? '' ),
		)
	);
}

/**
 * Are both of the job's files still on disk? The contact importer's
 * delete-later event removes uploads after three hours.
 *
 * @param array $job Job.
 * @return bool
 */
function npmp_gift_csv_files_ok( array $job ) {
	if ( empty( $job['file'] ) || ! is_readable( (string) $job['file'] ) ) {
		return false;
	}
	return empty( $job['index'] ) || is_readable( (string) $job['index'] );
}

/**
 * Push the job's delete-later events back three hours, so a long import
 * isn't cut off.
 *
 * @param array $job Job.
 * @return void
 */
function npmp_gift_csv_keep_files( array $job ) {
	foreach ( array( 'file', 'index' ) as $key ) {
		if ( empty( $job[ $key ] ) ) {
			continue;
		}
		$args = array( (string) $job[ $key ] );
		wp_clear_scheduled_hook( 'npmp_delete_import_file', $args );
		wp_schedule_single_event( time() + 3 * HOUR_IN_SECONDS, 'npmp_delete_import_file', $args );
	}
}

// =====================================================================
// The gift engine
// =====================================================================

/**
 * Put this source on the gift engine. Only this screen's handlers call it,
 * so the plugin-switch screen (npmp_gift_import) never lists a file.
 *
 * @return void
 */
function npmp_gift_csv_engine_on() {
	if ( ! has_filter( 'npmp_gift_import_sources', 'npmp_gift_csv_register_gift_source' ) ) {
		add_filter( 'npmp_gift_import_sources', 'npmp_gift_csv_register_gift_source' );
	}
}

/**
 * Add the file to the gift engine.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_gift_csv_register_gift_source( $sources ) {
	$sources[ NPMP_GIFT_CSV_SOURCE ] = array(
		'name'        => __( 'a file', 'nonprofit-manager' ),
		'has_data'    => function () {
			$job = npmp_gift_csv_job();
			return ! empty( $job['index'] ) && npmp_gift_csv_files_ok( $job );
		},
		'counts'      => function () {
			$s = (array) ( npmp_gift_csv_job()['summary'] ?? array() );
			return array(
				'gifts'        => (int) ( $s['ready'] ?? 0 ),
				'donors'       => (int) ( $s['donors'] ?? 0 ),
				'test'         => 0,
				'not_received' => (int) ( $s['skipped']['refunded'] ?? 0 ) + (int) ( $s['skipped']['not_received'] ?? 0 ),
				'active_plans' => 0,
			);
		},
		'donor_batch' => 'npmp_gift_csv_donor_batch',
	);
	return $sources;
}

/**
 * The engine's donor batch: donors from the index, from a byte cursor.
 *
 * @param int $after Byte cursor in the index.
 * @param int $limit Donors.
 * @return array[]
 */
function npmp_gift_csv_donor_batch( $after, $limit ) {
	$job = npmp_gift_csv_job();
	if ( empty( $job['index'] ) || ! npmp_gift_csv_files_ok( $job ) ) {
		return array();
	}
	// Each contact lookup and gift write leaves entries in WordPress's
	// in-memory cache, and one request can reach thousands of donors. Without
	// a persistent object cache that memory is all the cache holds, so
	// emptying it between batches keeps a long request flat.
	if ( (int) $after > 0 && function_exists( 'wp_using_ext_object_cache' ) && ! wp_using_ext_object_cache() ) {
		wp_cache_flush();
	}
	return npmp_gift_csv_read_donors(
		(string) $job['file'],
		(string) $job['index'],
		(array) $job['head'],
		(array) $job['map'],
		npmp_gift_csv_job_settings( $job ),
		(int) $after,
		(int) $limit
	);
}

// =====================================================================
// Screen and handlers
// =====================================================================

if ( is_admin() ) {
	add_action( 'admin_menu', 'npmp_gift_csv_register_menu', 31 );
	add_action( 'admin_head', 'npmp_gift_csv_hide_menu' );
	add_action( 'admin_enqueue_scripts', 'npmp_gift_csv_enqueue' );
	add_action( 'admin_post_npmp_gift_csv', 'npmp_gift_csv_post' );
	add_action( 'npmp_import_source_cards', 'npmp_gift_csv_import_card' );
}

/**
 * URL of the screen.
 *
 * @param array $args Query args.
 * @return string
 */
function npmp_gift_csv_url( array $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=npmp_gift_csv_import' ) );
}

/**
 * Register the screen. It opens from the Import screen's card.
 *
 * @return void
 */
function npmp_gift_csv_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import past gifts from a file', 'nonprofit-manager' ),
		__( 'Import past gifts from a file', 'nonprofit-manager' ),
		'manage_options',
		'npmp_gift_csv_import',
		'npmp_gift_csv_render_page'
	);
}

/**
 * Take the screen out of the menu after WordPress checked access to it.
 *
 * @return void
 */
function npmp_gift_csv_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_gift_csv_import' );
}

/**
 * The hub's styles and its carry-on script, which this screen shares.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_gift_csv_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_gift_csv_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * The card beside the contact imports on the Import screen.
 *
 * @return void
 */
function npmp_gift_csv_import_card() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<a class="npmp-import-source-card npmp-import-source-link" href="' . esc_url( npmp_gift_csv_url() ) . '" style="text-decoration:none;color:inherit;">';
	echo '<span class="npmp-import-source-icon dashicons dashicons-heart" aria-hidden="true"></span>';
	echo '<span class="npmp-import-source-title">' . esc_html__( 'Past gifts from a file', 'nonprofit-manager' ) . '</span>';
	echo '<span class="npmp-import-source-desc">' . esc_html__( 'Donors and gifts from Zeffy, Givebutter, Donorbox, or a spreadsheet.', 'nonprofit-manager' ) . '</span>';
	echo '</a>';
}

/**
 * Hidden fields for a form on this screen.
 *
 * @param string $step Step name.
 * @return void
 */
function npmp_gift_csv_form_fields( $step ) {
	echo '<input type="hidden" name="action" value="npmp_gift_csv">';
	echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
	wp_nonce_field( 'npmp_gift_csv_' . $step, 'npmp_gift_csv_nonce' );
}

/**
 * Send the browser back to the screen, with a message key.
 *
 * @param string $notice Message key, or ''.
 * @return void
 */
function npmp_gift_csv_back( $notice = '' ) {
	wp_safe_redirect( npmp_gift_csv_url( '' !== $notice ? array( 'npmp_notice' => $notice ) : array() ) );
	exit;
}

/**
 * Every form on the screen posts here.
 *
 * @return void
 */
function npmp_gift_csv_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You don\'t have permission to import gifts.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
	check_admin_referer( 'npmp_gift_csv_' . $step, 'npmp_gift_csv_nonce' );

	switch ( $step ) {
		case 'upload':
			npmp_gift_csv_back( npmp_gift_csv_handle_upload() );
			break;
		case 'map':
			npmp_gift_csv_back( npmp_gift_csv_handle_map( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized in the handler.
			break;
		case 'remap':
			$job = npmp_gift_csv_job();
			if ( ! empty( $job['index'] ) ) {
				npmp_delete_import_file( (string) $job['index'] );
			}
			unset( $job['summary'], $job['index'] );
			npmp_gift_csv_save_job( $job );
			npmp_gift_csv_back();
			break;
		case 'run':
			npmp_gift_csv_back( npmp_gift_csv_handle_run( ! empty( $_POST['restart'] ) ) );
			break;
		case 'reset':
			npmp_gift_csv_forget_job();
			npmp_gift_csv_back();
			break;
	}
	npmp_gift_csv_back();
}

/**
 * Upload: store the file privately, read its head, and match the columns.
 *
 * @return string Message key.
 */
function npmp_gift_csv_handle_upload() {
	if ( empty( $_FILES['gift_file'] ) || ! function_exists( 'npmp_import_privatize_upload' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in npmp_gift_csv_post().
		return 'no_file';
	}
	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$file     = $_FILES['gift_file']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- wp_handle_upload() checks it.
	$uploaded = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => array( 'csv' => 'text/csv' ),
		)
	);
	if ( isset( $uploaded['error'] ) ) {
		set_transient( 'npmp_gift_csv_error_' . get_current_user_id(), (string) $uploaded['error'], 300 );
		return 'upload_error';
	}
	$path = npmp_import_privatize_upload( $uploaded['file'] );
	if ( is_wp_error( $path ) ) {
		wp_delete_file( $uploaded['file'] );
		return 'store_error';
	}
	return npmp_gift_csv_start_job( $path, (string) ( $file['name'] ?? 'gifts.csv' ) );
}

/**
 * Start a job on a file already in the private import folder: read its head
 * and match the columns.
 *
 * @param string $path File in the private folder.
 * @param string $name The file's name as uploaded.
 * @return string Message key, '' when it worked.
 */
function npmp_gift_csv_start_job( $path, $name ) {
	$head = npmp_gift_csv_read_head( $path );
	if ( ! $head ) {
		npmp_delete_import_file( $path );
		return 'empty';
	}

	npmp_gift_csv_forget_job();
	$preset = npmp_gift_csv_detect_preset( $head['headers'] );
	$map    = npmp_gift_csv_auto_map( $head['headers'], $preset );
	npmp_gift_csv_save_job(
		array(
			'file'     => $path,
			'name'     => sanitize_file_name( $name ),
			'size'     => (int) filesize( $path ),
			'head'     => $head,
			'preset'   => $preset,
			'map'      => $map,
			'settings' => array(
				'timezone'   => npmp_gift_csv_default_timezone( $head['headers'], $map, $preset ),
				'date_order' => 'auto',
				'currency'   => npmp_currency(),
				'accept'     => array(),
			),
		)
	);
	return '';
}

/**
 * Save the column choices and run the check pass.
 *
 * @param array $post Posted values, unslashed.
 * @return string Message key.
 */
function npmp_gift_csv_handle_map( array $post ) {
	$job = npmp_gift_csv_job();
	if ( empty( $job['file'] ) || ! npmp_gift_csv_files_ok( array( 'file' => $job['file'] ) ) ) {
		return 'gone';
	}
	$columns = count( (array) $job['head']['headers'] );
	if ( isset( $post['map'] ) && is_array( $post['map'] ) ) {
		foreach ( array_keys( npmp_gift_csv_fields() ) as $field ) {
			$col                  = isset( $post['map'][ $field ] ) ? (int) $post['map'][ $field ] : -1;
			$job['map'][ $field ] = ( $col >= 0 && $col < $columns ) ? $col : -1;
		}
	}
	$settings = (array) $job['settings'];
	if ( isset( $post['timezone'] ) ) {
		$settings['timezone'] = 'utc' === sanitize_key( $post['timezone'] ) ? 'utc' : 'site';
	}
	if ( isset( $post['date_order'] ) ) {
		$order                  = sanitize_key( $post['date_order'] );
		$settings['date_order'] = in_array( $order, array( 'mdy', 'dmy' ), true ) ? $order : 'auto';
	}
	if ( isset( $post['currency'] ) ) {
		$code                 = npmp_normalize_currency_code( sanitize_text_field( (string) $post['currency'] ) );
		$settings['currency'] = npmp_gift_csv_currency_supported( $code ) ? $code : npmp_currency();
	}
	if ( isset( $post['accept_sent'] ) ) {
		$settings['accept'] = array_values( array_unique( array_map( 'npmp_gift_csv_status_key', array_map( 'sanitize_text_field', (array) ( $post['accept'] ?? array() ) ) ) ) );
	}
	foreach ( array( 'email', 'amount', 'date' ) as $needed ) {
		if ( $job['map'][ $needed ] < 0 ) {
			$job['settings'] = $settings;
			npmp_gift_csv_save_job( $job );
			return 'missing_' . $needed;
		}
	}

	$settings['date_order_used'] = 'auto' === $settings['date_order']
		? npmp_gift_csv_detect_date_order( (string) $job['file'], (array) $job['head'], (int) $job['map']['date'] )
		: $settings['date_order'];
	$job['settings'] = $settings;

	$dir = npmp_import_private_dir();
	if ( is_wp_error( $dir ) ) {
		return 'store_error';
	}
	if ( ! empty( $job['index'] ) ) {
		npmp_delete_import_file( (string) $job['index'] );
	}
	$index   = $dir . 'gift-index-' . wp_generate_password( 32, false ) . '.txt';
	$summary = npmp_gift_csv_build_index( (string) $job['file'], (array) $job['head'], (array) $job['map'], npmp_gift_csv_job_settings( $job ), $index );
	if ( null === $summary ) {
		return 'store_error';
	}
	$job['index']   = $index;
	$job['summary'] = $summary;
	npmp_gift_csv_save_job( $job );
	npmp_gift_csv_keep_files( $job );
	// A new check starts a new import count. Gifts already here stay skipped by their key.
	delete_option( 'npmp_gift_import_' . NPMP_GIFT_CSV_SOURCE );
	return '';
}

/**
 * Start or carry on the import.
 *
 * @param bool $restart Start from the first donor.
 * @return string Message key.
 */
function npmp_gift_csv_handle_run( $restart ) {
	$job = npmp_gift_csv_job();
	if ( empty( $job['index'] ) || ! npmp_gift_csv_files_ok( $job ) ) {
		return 'gone';
	}
	npmp_gift_csv_engine_on();
	if ( $restart ) {
		$state            = npmp_gift_import_state( NPMP_GIFT_CSV_SOURCE );
		$state['running'] = false;
		npmp_gift_import_save_state( NPMP_GIFT_CSV_SOURCE, $state );
	}
	npmp_gift_csv_keep_files( $job );
	npmp_gift_import_run( NPMP_GIFT_CSV_SOURCE );
	return '';
}

// =====================================================================
// Rendering
// =====================================================================

/**
 * The screen.
 *
 * @return void
 */
function npmp_gift_csv_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	$job   = npmp_gift_csv_job();
	$state = npmp_gift_import_state( NPMP_GIFT_CSV_SOURCE );

	echo '<div class="wrap npmp-hub-wrap npmp-gift-csv">';
	echo '<h1>' . esc_html__( 'Import past gifts from a file', 'nonprofit-manager' ) . '</h1>';
	echo '<hr class="wp-header-end">';
	$import = function_exists( 'menu_page_url' ) ? menu_page_url( 'npmp_import', false ) : '';
	if ( $import ) {
		echo '<p><a href="' . esc_url( $import ) . '">&larr; ' . esc_html__( 'Back to Import', 'nonprofit-manager' ) . '</a></p>';
	}
	npmp_gift_csv_render_notice();
	npmp_gift_csv_render_styles();

	if ( $job && ! npmp_gift_csv_files_ok( $job ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'We delete an uploaded file three hours after you last work on the import, and we deleted this file. Upload the file again to carry on. We skip every gift you already imported.', 'nonprofit-manager' ) . '</p></div>';
		npmp_gift_csv_render_reset( __( 'Upload a file', 'nonprofit-manager' ), true );
		echo '</div>';
		return;
	}

	if ( ! $job ) {
		npmp_gift_csv_render_upload();
	} elseif ( $state['running'] ) {
		npmp_gift_csv_render_running( $state );
	} elseif ( empty( $job['summary'] ) ) {
		npmp_gift_csv_render_map( $job );
	} else {
		npmp_gift_csv_render_review( $job, $state );
	}
	echo '</div>';
}

/**
 * The message after a redirect.
 *
 * @return void
 */
function npmp_gift_csv_render_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
	$key = isset( $_GET['npmp_notice'] ) ? sanitize_key( wp_unslash( $_GET['npmp_notice'] ) ) : '';
	if ( '' === $key ) {
		return;
	}
	$fields   = npmp_gift_csv_fields();
	$messages = array(
		'no_file'        => __( 'Pick a CSV file first.', 'nonprofit-manager' ),
		'store_error'    => __( 'We couldn\'t save the file in your uploads folder. Check that WordPress can write to the uploads folder.', 'nonprofit-manager' ),
		'empty'          => __( 'That file has no header row we can read. Save it as CSV with a header row and try again.', 'nonprofit-manager' ),
		'gone'           => __( 'The file is gone. Upload it again to carry on.', 'nonprofit-manager' ),
		'missing_email'  => sprintf( /* translators: %s: field name. */ __( 'Pick a column for the %s.', 'nonprofit-manager' ), strtolower( $fields['email']['label'] ) ),
		'missing_amount' => sprintf( /* translators: %s: field name. */ __( 'Pick a column for the %s.', 'nonprofit-manager' ), strtolower( $fields['amount']['label'] ) ),
		'missing_date'   => sprintf( /* translators: %s: field name. */ __( 'Pick a column for the %s.', 'nonprofit-manager' ), strtolower( $fields['date']['label'] ) ),
	);
	if ( 'upload_error' === $key ) {
		$text = (string) get_transient( 'npmp_gift_csv_error_' . get_current_user_id() );
		delete_transient( 'npmp_gift_csv_error_' . get_current_user_id() );
		$messages['upload_error'] = '' !== $text ? $text : __( 'The upload didn\'t work. Try again.', 'nonprofit-manager' );
	}
	if ( isset( $messages[ $key ] ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
	}
}

/**
 * A few styles of the screen's own. The rest come from the hub's sheet.
 *
 * @return void
 */
function npmp_gift_csv_render_styles() {
	echo '<style>.npmp-gift-csv{max-width:900px}.npmp-gift-csv .npmp-gift-csv-scroll{overflow-x:auto;max-width:100%;margin:12px 0}.npmp-gift-csv .npmp-gift-csv-scroll table{min-width:480px}.npmp-gift-csv .npmp-gift-csv-scroll td,.npmp-gift-csv .npmp-gift-csv-scroll th{white-space:nowrap;max-width:220px;overflow:hidden;text-overflow:ellipsis}.npmp-gift-csv select{max-width:100%}.npmp-gift-csv .npmp-gift-csv-map td{vertical-align:middle}.npmp-gift-csv .npmp-gift-csv-sample{color:#50575e;word-break:break-word}.npmp-gift-csv .npmp-gift-csv-num{white-space:nowrap}@media (max-width:600px){.npmp-gift-csv .npmp-gift-csv-map th,.npmp-gift-csv .npmp-gift-csv-map td{display:block;width:auto;padding:4px 0}.npmp-gift-csv .npmp-gift-csv-map tr{display:block;padding:8px 0;border-bottom:1px solid #dcdcde}}</style>';
}

/**
 * The free total line, for sites without Pro.
 *
 * @return void
 */
function npmp_gift_csv_render_free_line() {
	if ( function_exists( 'npmp_is_pro' ) && ! npmp_is_pro() && function_exists( 'npmp_import_free_remaining' ) && function_exists( 'npmp_import_is_capped' ) && npmp_import_is_capped() ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: free total of new contacts, 2: places left. */
				__( 'With the free plugin, you can add up to %1$s new contacts through imports, and you have %2$s places left. A donor who\'s already a contact doesn\'t use a place. We leave out any donor past the limit, with every one of their gifts, and you can bring those donors in by running the import again with Pro.', 'nonprofit-manager' ),
				number_format_i18n( NPMP_IMPORT_FREE_MAX_ROWS ),
				number_format_i18n( npmp_import_free_remaining() )
			)
		) . '</p>';
	}
}

/**
 * What comes over and what stays behind, on every step before the import.
 *
 * @return void
 */
function npmp_gift_csv_render_scope() {
	echo '<h2>' . esc_html__( 'What comes over', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Every paid gift, with its date, amount, currency, and payment method, as a past gift. We never charge anyone, and we send no donor an email or a receipt.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'We add each donor as a contact, or add the gifts to the contact you already have with that email, and work out their giving totals and last gift date from these gifts. A contact who unsubscribed stays unsubscribed.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'We bring payments from a recurring plan over one by one as past gifts, marked with their frequency. We don\'t create a plan, and your donors keep paying through the old tool until you cancel each plan in the old tool. You get monthly gifts on your own form with Pro.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'We bring a partly refunded gift over as the amount the donor kept, if you export a refunded amount column.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';
	echo '<h2>' . esc_html__( 'What stays behind', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Refunded, disputed, failed, canceled, and unfinished payments. We never count any of them as gifts.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Rows with no email address, as on most cash gifts and anonymous gifts. We file every gift under a contact. We still bring over the name of a donor who asked to give anonymously in public, since only your team sees the contact list.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: %s: list of currency codes. */
			__( 'Gifts in a currency we don\'t support. We support %s.', 'nonprofit-manager' ),
			implode( ', ', array_keys( npmp_supported_currencies() ) )
		)
	) . '</li>';
	echo '<li>' . esc_html__( 'Campaigns, funds, fees, tickets, dedications, and answers to custom questions.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';
}

/**
 * Step 1: upload.
 *
 * @return void
 */
function npmp_gift_csv_render_upload() {
	echo '<p>' . esc_html__( 'You can bring donors and their past gifts over from Zeffy, Givebutter, Donorbox, or your own spreadsheet. Export a CSV file from the tool, with one row for each payment, and upload the file here.', 'nonprofit-manager' ) . '</p>';
	echo '<h2>' . esc_html__( 'Get the file', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Givebutter: open Transactions, choose Export to CSV, then Only donations.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Zeffy: open Payments and export Payments with All columns. Skip Itemized Payments, with its row for each ticket.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Donorbox: open Donations, click Export, and pick all columns.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'A spreadsheet: save it as CSV with a header row and one gift on each row.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';
	echo '<p>' . esc_html__( 'We recognise Givebutter and Zeffy exports and match their columns for you. For any other file, you match the columns on the next step.', 'nonprofit-manager' ) . '</p>';

	npmp_gift_csv_render_scope();
	npmp_gift_csv_render_free_line();

	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_gift_csv_form_fields( 'upload' );
	echo '<p><label for="npmp-gift-csv-file"><strong>' . esc_html__( 'Your CSV file', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="file" id="npmp-gift-csv-file" name="gift_file" accept=".csv,text/csv" required></p>';
	echo '<p class="description">' . esc_html(
		sprintf(
			/* translators: %s: largest upload size, such as 64 MB. */
			__( 'You can upload files up to %s here. To shrink a big export, pick only the columns you need before you export the file.', 'nonprofit-manager' ),
			size_format( wp_max_upload_size() )
		)
	) . '</p>';
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Upload and preview', 'nonprofit-manager' ) . '</button></p>';
	echo '</form>';
}

/**
 * A select listing the file's columns.
 *
 * @param string   $name     Field name attribute.
 * @param string   $id       ID attribute.
 * @param string[] $headers  Header row.
 * @param int      $selected Chosen column.
 * @return void
 */
function npmp_gift_csv_column_select( $name, $id, array $headers, $selected ) {
	echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
	echo '<option value="-1"' . selected( $selected, -1, false ) . '>' . esc_html__( 'Not in this file', 'nonprofit-manager' ) . '</option>';
	foreach ( $headers as $i => $header ) {
		$label = '' !== $header ? $header : sprintf( /* translators: %d: column number. */ __( 'Column %d', 'nonprofit-manager' ), $i + 1 );
		echo '<option value="' . esc_attr( (string) $i ) . '"' . selected( $selected, (int) $i, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
}

/**
 * Step 2: check the columns.
 *
 * @param array $job Job.
 * @return void
 */
function npmp_gift_csv_render_map( array $job ) {
	$head     = (array) $job['head'];
	$headers  = (array) $head['headers'];
	$preview  = (array) $head['preview'];
	$preset   = (string) ( $job['preset'] ?? '' );
	$settings = (array) $job['settings'];

	echo '<h2>' . esc_html__( 'Check the columns', 'nonprofit-manager' ) . '</h2>';
	/* translators: %s: file name. */
	echo '<p>' . esc_html( sprintf( __( 'File: %s', 'nonprofit-manager' ), (string) $job['name'] ) ) . '</p>';
	if ( 'givebutter' === $preset ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'We think this is a Givebutter export, so we matched its columns from Givebutter\'s help center. Givebutter writes its dates in UTC. Check each match before you go on.', 'nonprofit-manager' ) . '</p></div>';
	} elseif ( 'zeffy' === $preset ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'We think this is a Zeffy export. Zeffy names its Amount, Payment method, Payment status, and Refunded amount columns in its help center, so we matched those four. Check the email, name, and date matches before you go on.', 'nonprofit-manager' ) . '</p></div>';
	} else {
		echo '<p>' . esc_html__( 'We matched what we could from the column names. Check each match, and pick a column for anything we missed.', 'nonprofit-manager' ) . '</p>';
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_gift_csv_form_fields( 'map' );
	echo '<table class="form-table npmp-gift-csv-map" role="presentation"><tbody>';
	foreach ( npmp_gift_csv_fields() as $field => $info ) {
		$col    = (int) ( $job['map'][ $field ] ?? -1 );
		$sample = '';
		if ( $col >= 0 ) {
			foreach ( $preview as $row ) {
				if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
					$sample = (string) $row[ $col ];
					break;
				}
			}
		}
		echo '<tr><th scope="row"><label for="npmp-gift-map-' . esc_attr( $field ) . '">' . esc_html( $info['label'] ) . ( $info['required'] ? ' <span aria-hidden="true">*</span>' : '' ) . '</label></th><td>';
		npmp_gift_csv_column_select( 'map[' . $field . ']', 'npmp-gift-map-' . $field, $headers, $col );
		if ( '' !== $sample ) {
			/* translators: %s: a value from the file. */
			echo ' <span class="npmp-gift-csv-sample">' . esc_html( sprintf( __( 'such as %s', 'nonprofit-manager' ), wp_html_excerpt( $sample, 40, '…' ) ) ) . '</span>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="description">' . esc_html__( '* Every gift needs an email, an amount, and a date. Pick a full name column or the first and last name columns.', 'nonprofit-manager' ) . '</p>';

	echo '<h3>' . esc_html__( 'Dates and money in your file', 'nonprofit-manager' ) . '</h3>';
	echo '<table class="form-table npmp-gift-csv-map" role="presentation"><tbody>';
	echo '<tr><th scope="row"><label for="npmp-gift-tz">' . esc_html__( 'Times in this file', 'nonprofit-manager' ) . '</label></th><td><select name="timezone" id="npmp-gift-tz">';
	/* translators: %s: the site's time zone, such as America/New_York. */
	echo '<option value="site"' . selected( $settings['timezone'], 'site', false ) . '>' . esc_html( sprintf( __( 'Your site\'s time zone (%s)', 'nonprofit-manager' ), wp_timezone_string() ) ) . '</option>';
	echo '<option value="utc"' . selected( $settings['timezone'], 'utc', false ) . '>' . esc_html__( 'UTC', 'nonprofit-manager' ) . '</option>';
	echo '</select><p class="description">' . esc_html__( 'We keep the offset on any date that has one.', 'nonprofit-manager' ) . '</p></td></tr>';
	echo '<tr><th scope="row"><label for="npmp-gift-order">' . esc_html__( 'Dates like 03/04/2025', 'nonprofit-manager' ) . '</label></th><td><select name="date_order" id="npmp-gift-order">';
	echo '<option value="auto"' . selected( $settings['date_order'], 'auto', false ) . '>' . esc_html__( 'Work it out from the file', 'nonprofit-manager' ) . '</option>';
	echo '<option value="mdy"' . selected( $settings['date_order'], 'mdy', false ) . '>' . esc_html__( 'Month first (March 4)', 'nonprofit-manager' ) . '</option>';
	echo '<option value="dmy"' . selected( $settings['date_order'], 'dmy', false ) . '>' . esc_html__( 'Day first (3 April)', 'nonprofit-manager' ) . '</option>';
	echo '</select></td></tr>';
	echo '<tr><th scope="row"><label for="npmp-gift-currency">' . esc_html__( 'Currency for rows with none', 'nonprofit-manager' ) . '</label></th><td><select name="currency" id="npmp-gift-currency">';
	foreach ( npmp_supported_currencies() as $code => $label ) {
		echo '<option value="' . esc_attr( $code ) . '"' . selected( $settings['currency'], $code, false ) . '>' . esc_html( $code . ' · ' . $label ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '</tbody></table>';

	if ( $preview ) {
		echo '<h3>' . esc_html__( 'The first rows of your file', 'nonprofit-manager' ) . '</h3>';
		echo '<div class="npmp-gift-csv-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $headers as $header ) {
			echo '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $preview as $row ) {
			echo '<tr>';
			foreach ( array_keys( $headers ) as $i ) {
				echo '<td>' . esc_html( wp_html_excerpt( (string) ( $row[ $i ] ?? '' ), 60, '…' ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Check the file', 'nonprofit-manager' ) . '</button></p>';
	echo '</form>';
	npmp_gift_csv_render_reset( __( 'Use a different file', 'nonprofit-manager' ) );
}

/**
 * The button that forgets the file.
 *
 * @param string $label   Button label.
 * @param bool   $primary Primary button.
 * @return void
 */
function npmp_gift_csv_render_reset( $label, $primary = false ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_gift_csv_form_fields( 'reset' );
	echo '<p><button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></p></form>';
}

/**
 * Format money totals per currency.
 *
 * @param array $totals Code => amount.
 * @return string
 */
function npmp_gift_csv_totals_text( array $totals ) {
	$parts = array();
	$mixed = count( $totals ) > 1;
	foreach ( $totals as $code => $amount ) {
		$text = function_exists( 'npmp_format_amount' ) ? npmp_format_amount( (float) $amount, (string) $code ) : $code . ' ' . $amount;
		// Several currencies share a symbol ($ for USD, CAD, AUD, NZD), so name each one when the file mixes them.
		$parts[] = $mixed ? $text . ' ' . $code : $text;
	}
	return implode( ', ', $parts );
}

/**
 * Step 3: what comes over, then the import button and the results.
 *
 * @param array $job   Job.
 * @param array $state Engine state.
 * @return void
 */
function npmp_gift_csv_render_review( array $job, array $state ) {
	$s       = array_replace_recursive( npmp_gift_csv_empty_summary(), (array) $job['summary'] );
	$skipped = $s['skipped'];

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_gift_import_render_results( $state['results'] );
		$members = admin_url( 'admin.php?page=npmp_members' );
		echo '<p><a class="button" href="' . esc_url( $members ) . '">' . esc_html__( 'See your contacts', 'nonprofit-manager' ) . '</a></p>';
	}

	echo '<h2>' . esc_html__( 'What you bring over from this file', 'nonprofit-manager' ) . '</h2>';
	/* translators: %s: file name. */
	echo '<p>' . esc_html( sprintf( __( 'File: %s', 'nonprofit-manager' ), (string) $job['name'] ) ) . '</p>';
	echo '<ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html(
		sprintf(
			/* translators: 1: number of gifts, 2: number of donors. */
			_n( '%1$s gift ready to import, from %2$s donor.', '%1$s gifts ready to import, from %2$s donors.', (int) $s['ready'], 'nonprofit-manager' ),
			number_format_i18n( (int) $s['ready'] ),
			number_format_i18n( (int) $s['donors'] )
		)
	) . '</li>';
	if ( $s['totals'] ) {
		/* translators: %s: amounts per currency. */
		echo '<li>' . esc_html( sprintf( __( 'In all: %s.', 'nonprofit-manager' ), npmp_gift_csv_totals_text( (array) $s['totals'] ) ) ) . '</li>';
	}
	if ( '' !== $s['first'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: 1: first date, 2: last date. */
				__( 'The first gift is from %1$s and the last from %2$s.', 'nonprofit-manager' ),
				wp_date( get_option( 'date_format' ), strtotime( $s['first'] . ' UTC' ) ),
				wp_date( get_option( 'date_format' ), strtotime( $s['last'] . ' UTC' ) )
			)
		) . '</li>';
	}
	if ( $s['recurring'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of gifts. */
				_n( '%s gift is a payment from a recurring plan. We bring it over as a past gift and don\'t create a plan.', '%s gifts are payments from recurring plans. We bring them over as past gifts and don\'t create any plans.', (int) $s['recurring'], 'nonprofit-manager' ),
				number_format_i18n( (int) $s['recurring'] )
			)
		) . '</li>';
	}
	echo '</ul>';

	/* translators: %s: number of rows, in each line below. */
	$lines = array(
		'refunded'     => __( 'Refunded or disputed: %s', 'nonprofit-manager' ),
		'not_received' => __( 'Failed, canceled, or never finished: %s', 'nonprofit-manager' ),
		'email'        => __( 'No email, or an email we can\'t read: %s', 'nonprofit-manager' ),
		'amount'       => __( 'No amount, a zero, or a negative amount: %s', 'nonprofit-manager' ),
		'date'         => __( 'A date we can\'t read: %s', 'nonprofit-manager' ),
	);
	$any = array_sum( $skipped ) + (int) $s['duplicates'];
	if ( $any ) {
		echo '<h3>' . esc_html__( 'Rows we leave out', 'nonprofit-manager' ) . '</h3><ul class="npmp-hub-checklist">';
		foreach ( $lines as $key => $line ) {
			if ( ! empty( $skipped[ $key ] ) ) {
				echo '<li>' . esc_html( sprintf( $line, number_format_i18n( (int) $skipped[ $key ] ) ) ) . '</li>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			}
		}
		if ( ! empty( $skipped['currency'] ) ) {
			$codes = array();
			foreach ( (array) $s['currencies'] as $code => $n ) {
				$codes[] = $code . ' (' . number_format_i18n( (int) $n ) . ')';
			}
			/* translators: %s: currency codes with counts. */
			echo '<li>' . esc_html( sprintf( __( 'In a currency we don\'t support: %s', 'nonprofit-manager' ), implode( ', ', $codes ) ) ) . '</li>';
		}
		if ( $s['duplicates'] ) {
			/* translators: %s: number of rows. */
			echo '<li>' . esc_html( sprintf( __( 'Repeats of a row higher up in the file: %s', 'nonprofit-manager' ), number_format_i18n( (int) $s['duplicates'] ) ) ) . '</li>';
		}
		echo '</ul>';
	}

	if ( ! empty( $skipped['unknown_status'] ) ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'We don\'t know these payment statuses, so we left those rows out. Tick a status to count its rows as money you received, then check the file again.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		npmp_gift_csv_form_fields( 'map' );
		echo '<input type="hidden" name="accept_sent" value="1">';
		$accepted = (array) ( $job['settings']['accept'] ?? array() );
		foreach ( $accepted as $status ) {
			echo '<input type="hidden" name="accept[]" value="' . esc_attr( $status ) . '">';
		}
		foreach ( (array) $s['statuses'] as $status => $n ) {
			echo '<p><label><input type="checkbox" name="accept[]" value="' . esc_attr( (string) $status ) . '"> ' . esc_html( sprintf( '"%s"', '' !== (string) $status ? (string) $status : '?' ) ) . ' <span class="npmp-gift-csv-num">(' . esc_html( number_format_i18n( (int) $n ) ) . ')</span></label></p>';
		}
		echo '<p><button type="submit" class="button">' . esc_html__( 'Check the file again', 'nonprofit-manager' ) . '</button></p></form></div>';
	}

	echo '<p>' . esc_html__( 'When you run the same file again, or a file that overlaps an earlier one, we add only gifts you don\'t have yet. We tell gifts apart by the transaction ID if you mapped one. Without one, we count rows with the same email, date and time, amount, and currency as one gift.', 'nonprofit-manager' ) . '</p>';
	npmp_gift_csv_render_free_line();

	if ( $s['ready'] > 0 ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		npmp_gift_csv_form_fields( 'run' );
		echo '<input type="hidden" name="restart" value="1">';
		$label = $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import gifts', 'nonprofit-manager' );
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p></form>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_gift_csv_form_fields( 'remap' );
	echo '<p><button type="submit" class="button">' . esc_html__( 'Change the columns', 'nonprofit-manager' ) . '</button></p></form>';
	npmp_gift_csv_render_reset( __( 'Use a different file', 'nonprofit-manager' ) );
}

/**
 * While the import runs: progress and the carry-on form.
 *
 * @param array $state Engine state.
 * @return void
 */
function npmp_gift_csv_render_running( array $state ) {
	npmp_gift_import_render_results( $state['results'] );
	echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
	npmp_gift_csv_form_fields( 'run' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form>';
}
