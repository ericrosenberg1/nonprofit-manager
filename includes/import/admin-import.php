<?php
/**
 * File path: includes/import/admin-import.php
 *
 * Admin page: wizard-style member / email-list import.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Menu registration
// =====================================================================

// Priority 11 so the membership module (default priority 10) has registered
// its menu first; we read the menu globals to choose our parent.
add_action( 'admin_menu', 'npmp_import_register_menu', 11 );

/**
 * Register the Import submenu page.
 *
 * Defaults to nesting under the Members menu group (where users naturally look
 * for "Import members"), with a fallback to the main Nonprofit Manager menu
 * if the membership group hasn't been registered in this install.
 */
function npmp_import_register_menu() {
	$parent = npmp_admin_menu_slug_exists( 'npmp_membership' ) ? 'npmp_membership' : 'npmp_main';

	add_submenu_page(
		$parent,
		__( 'Import Members', 'nonprofit-manager' ),
		__( 'Import', 'nonprofit-manager' ),
		'manage_options',
		'npmp_import',
		'npmp_import_render_page'
	);
}

/**
 * Helper: is a parent admin menu slug already registered?
 *
 * Reads the $menu / $submenu globals that WordPress populates as
 * add_menu_page / add_submenu_page run. Safe from inside admin_menu hooks
 * provided this function runs at priority >= 11 (after the other modules
 * have registered theirs). npmp_import_register_menu hooks at priority 11
 * just below to give the membership module first crack.
 *
 * @param string $slug Slug to check.
 * @return bool
 */
function npmp_admin_menu_slug_exists( $slug ) {
	global $menu, $submenu;

	if ( is_array( $submenu ) && isset( $submenu[ $slug ] ) ) {
		return true;
	}
	if ( is_array( $menu ) ) {
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return true;
			}
		}
	}
	return false;
}

// =====================================================================
// AJAX handlers
// =====================================================================

add_action( 'wp_ajax_npmp_import_preview', 'npmp_import_ajax_preview' );
add_action( 'wp_ajax_npmp_import_execute', 'npmp_import_ajax_execute' );
add_action( 'wp_ajax_npmp_import_step', 'npmp_import_ajax_step' );
add_action( 'wp_ajax_npmp_import_mailchimp_lists', 'npmp_import_ajax_mc_lists' );
add_action( 'wp_ajax_npmp_import_cc_lists', 'npmp_import_ajax_cc_lists' );

/**
 * AJAX: Upload a file (or fetch from URL / API) and return preview data.
 */
function npmp_import_ajax_preview() {
	check_ajax_referer( 'npmp_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'You do not have permission to import members.', 'nonprofit-manager' ) );
	}

	$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
	$import = NPMP_Import_Manager::get_instance();

	switch ( $source ) {
		case 'csv':
		case 'xlsx':
			if ( empty( $_FILES['import_file'] ) ) {
				wp_send_json_error( __( 'No file uploaded.', 'nonprofit-manager' ) );
			}

			$file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			// Validate MIME type.
			$allowed = 'xlsx' === $source
				? array(
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					'application/vnd.ms-excel',
					'application/octet-stream',
				)
				: array( 'text/csv', 'text/plain', 'application/csv', 'application/octet-stream' );

			// WordPress handles upload for us.
			$upload_overrides = array(
				'test_form' => false,
				'mimes'     => 'xlsx' === $source
					? array( 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' )
					: array( 'csv' => 'text/csv' ),
			);

			$uploaded = wp_handle_upload( $file, $upload_overrides );
			if ( isset( $uploaded['error'] ) ) {
				wp_send_json_error( $uploaded['error'] );
			}

			$file_path = $uploaded['file'];

			// Store path in transient for the execute step.
			$token = wp_generate_password( 16, false );
			set_transient( 'npmp_import_file_' . $token, $file_path, HOUR_IN_SECONDS );

			$preview = $import->get_file_preview( $file_path, $source );
			if ( is_wp_error( $preview ) ) {
				wp_send_json_error( $preview->get_error_message() );
			}

			$preview['file_token'] = $token;
			$preview['source']     = $source;
			wp_send_json_success( $preview );
			break;

		case 'google_sheet':
			$url = isset( $_POST['sheet_url'] ) ? esc_url_raw( wp_unslash( $_POST['sheet_url'] ) ) : '';
			if ( empty( $url ) ) {
				wp_send_json_error( __( 'Please enter a Google Sheet URL.', 'nonprofit-manager' ) );
			}

			// Constrain to docs.google.com host before any network call. The user
			// types this URL; without a host check we'd happily fetch arbitrary
			// internal endpoints (SSRF — server-side request forgery). The Google
			// Sheets "publish to web" URL is always under docs.google.com.
			$parsed_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! $parsed_host || 'docs.google.com' !== strtolower( $parsed_host ) ) {
				wp_send_json_error( __( 'Only docs.google.com URLs are supported. Use the "Publish to web" link from File > Share.', 'nonprofit-manager' ) );
			}

			// Ensure URL outputs CSV.
			if ( false === strpos( $url, 'output=csv' ) ) {
				$url = add_query_arg( 'output', 'csv', $url );
			}

			// wp_safe_remote_get applies the http_request_host_is_external filter
			// (blocks loopback / RFC1918 hosts unless explicitly allowed) and
			// reject_unsafe_urls (validates redirect targets). Pair with the host
			// check above so even a docs.google.com URL that 302s to an internal
			// address gets rejected.
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 60,
					'reject_unsafe_urls' => true,
					'limit_response_size' => 10 * MB_IN_BYTES, // cap at 10 MB; a 50k-row CSV is ~3-5 MB.
				)
			);
			if ( is_wp_error( $response ) ) {
				wp_send_json_error( __( 'Could not fetch the Google Sheet. Make sure it is published to the web.', 'nonprofit-manager' ) );
			}

			$body = wp_remote_retrieve_body( $response );
			if ( empty( $body ) ) {
				wp_send_json_error( __( 'The Google Sheet returned no data.', 'nonprofit-manager' ) );
			}

			$tmp = wp_tempnam( 'npmp_gs' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $tmp, $body );

			$token = wp_generate_password( 16, false );
			set_transient( 'npmp_import_file_' . $token, $tmp, HOUR_IN_SECONDS );
			set_transient( 'npmp_import_url_' . $token, $url, HOUR_IN_SECONDS );

			$preview = $import->get_file_preview( $tmp, 'csv' );
			if ( is_wp_error( $preview ) ) {
				wp_send_json_error( $preview->get_error_message() );
			}

			$preview['file_token'] = $token;
			$preview['source']     = 'google_sheet';
			wp_send_json_success( $preview );
			break;

		case 'mailchimp':
			$api_key = isset( $_POST['mc_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mc_api_key'] ) ) : '';
			$list_id = isset( $_POST['mc_list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mc_list_id'] ) ) : '';

			if ( empty( $api_key ) || empty( $list_id ) ) {
				wp_send_json_error( __( 'API key and list selection are required.', 'nonprofit-manager' ) );
			}

			// Fetch the audience's merge-field schema so the preview surfaces ALL
			// of the org's custom merge tags (not just the four standard ones).
			$merge_fields = npmp_mailchimp_get_merge_fields( $api_key, $list_id );
			if ( is_wp_error( $merge_fields ) ) {
				wp_send_json_error( $merge_fields->get_error_message() );
			}

			// Fetch first page of members for preview rows.
			$result = npmp_mailchimp_get_members( $api_key, $list_id, 0, 5 );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			// Build the column order. Three Mailchimp-native columns always come
			// first (email / status / tags), then every merge field in the order
			// Mailchimp returned them. The column_order array is also persisted
			// in the credentials transient so the step handler can convert the
			// UI's index-based mapping back into named keys for any merge tag.
			$headers      = array( 'Email', 'Status', 'Tags' );
			$column_order = array( 'email_address', 'status', 'tags' );
			$auto_map     = array(
				0 => 'email',
				1 => 'status',
				2 => 'tags',
			);
			foreach ( $merge_fields as $mf ) {
				$tag                  = $mf['tag'];
				$headers[]            = $mf['name'] ? sprintf( '%s (%s)', $mf['name'], $tag ) : $tag;
				$column_order[]       = 'mf:' . $tag;
				$auto_map[ count( $column_order ) - 1 ] = npmp_mailchimp_suggest_field( $tag, $mf['name'] );
			}

			// Build preview rows aligned to column_order.
			$rows = array();
			foreach ( $result['members'] as $m ) {
				$tag_names = ! empty( $m['tags'] ) ? wp_list_pluck( $m['tags'], 'name' ) : array();
				$row       = array(
					isset( $m['email_address'] ) ? $m['email_address'] : '',
					isset( $m['status'] ) ? $m['status'] : '',
					implode( ', ', $tag_names ),
				);
				foreach ( $merge_fields as $mf ) {
					$tag = $mf['tag'];
					$val = isset( $m['merge_fields'][ $tag ] ) ? $m['merge_fields'][ $tag ] : '';
					// Mailchimp returns ADDRESS as a structured value; flatten to "street, city, state zip"
					// for the preview. The actual import handler unpacks the structure into NPM address fields.
					if ( is_array( $val ) ) {
						$parts = array_filter(
							array(
								isset( $val['addr1'] ) ? $val['addr1'] : '',
								isset( $val['addr2'] ) ? $val['addr2'] : '',
								isset( $val['city'] ) ? $val['city'] : '',
								isset( $val['state'] ) ? $val['state'] : '',
								isset( $val['zip'] ) ? $val['zip'] : '',
							),
							'strlen'
						);
						$val   = implode( ', ', $parts );
					}
					$row[] = (string) $val;
				}
				$rows[] = $row;
			}

			// Store credentials + column order so the step handler knows what
			// fields to extract for each member, in the order the UI's mapping
			// references.
			$token = wp_generate_password( 16, false );
			set_transient(
				'npmp_import_mc_' . $token,
				array(
					'api_key'      => $api_key,
					'list_id'      => $list_id,
					'column_order' => $column_order,
					'merge_fields' => $merge_fields,
				),
				HOUR_IN_SECONDS
			);

			wp_send_json_success(
				array(
					'headers'    => $headers,
					'rows'       => $rows,
					'total_rows' => isset( $result['total_items'] ) ? $result['total_items'] : count( $rows ),
					'auto_map'   => $auto_map,
					'file_token' => $token,
					'source'     => 'mailchimp',
				)
			);
			break;

		case 'constant_contact':
			$access_token = isset( $_POST['cc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cc_token'] ) ) : '';
			$list_id      = isset( $_POST['cc_list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cc_list_id'] ) ) : '';

			if ( empty( $access_token ) || empty( $list_id ) ) {
				wp_send_json_error( __( 'Access token and list selection are required.', 'nonprofit-manager' ) );
			}

			$result = npmp_cc_get_contacts( $access_token, $list_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			$token = wp_generate_password( 16, false );
			set_transient(
				'npmp_import_cc_' . $token,
				array(
					'access_token' => $access_token,
					'list_id'      => $list_id,
				),
				HOUR_IN_SECONDS
			);

			$headers = array( 'Email', 'First Name', 'Last Name', 'Phone' );
			$rows    = array();

			$preview_contacts = array_slice( $result['contacts'], 0, 5 );
			foreach ( $preview_contacts as $c ) {
				$email = ! empty( $c['email_addresses'] ) ? $c['email_addresses'][0]['address'] : '';
				$phone = ! empty( $c['phone_numbers'] ) ? $c['phone_numbers'][0]['phone_number'] : '';
				$rows[] = array(
					$email,
					isset( $c['first_name'] ) ? $c['first_name'] : '',
					isset( $c['last_name'] ) ? $c['last_name'] : '',
					$phone,
				);
			}

			$auto_map = array(
				0 => 'email',
				1 => 'first_name',
				2 => 'last_name',
				3 => 'phone',
			);

			wp_send_json_success(
				array(
					'headers'    => $headers,
					'rows'       => $rows,
					'total_rows' => count( $result['contacts'] ),
					'auto_map'   => $auto_map,
					'file_token' => $token,
					'source'     => 'constant_contact',
				)
			);
			break;

		default:
			wp_send_json_error( __( 'Invalid import source.', 'nonprofit-manager' ) );
	}
}

/**
 * AJAX: Execute the import.
 */
function npmp_import_ajax_execute() {
	check_ajax_referer( 'npmp_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'You do not have permission to import members.', 'nonprofit-manager' ) );
	}

	$source     = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
	$file_token = isset( $_POST['file_token'] ) ? sanitize_text_field( wp_unslash( $_POST['file_token'] ) ) : '';
	$mapping    = isset( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : array();

	$options = array(
		'duplicate_handling' => isset( $_POST['duplicate_handling'] ) ? sanitize_key( $_POST['duplicate_handling'] ) : 'skip',
		'default_level'      => isset( $_POST['default_level'] ) ? sanitize_text_field( wp_unslash( $_POST['default_level'] ) ) : '',
		'default_status'     => isset( $_POST['default_status'] ) ? sanitize_key( $_POST['default_status'] ) : 'subscribed',
		'source'             => isset( $_POST['import_source_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['import_source_tag'] ) ) : '',
	);

	$import = NPMP_Import_Manager::get_instance();

	switch ( $source ) {
		case 'csv':
		case 'xlsx':
		case 'google_sheet':
			$file_path = get_transient( 'npmp_import_file_' . $file_token );
			if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
				wp_send_json_error( __( 'Import file has expired. Please upload again.', 'nonprofit-manager' ) );
			}

			$type = 'xlsx' === $source ? 'xlsx' : 'csv';
			if ( 'google_sheet' === $source ) {
				$type = 'csv';
			}

			$stats = ( 'xlsx' === $type )
				? $import->import_xlsx( $file_path, $mapping, $options )
				: $import->import_csv( $file_path, $mapping, $options );

			// Clean up.
			delete_transient( 'npmp_import_file_' . $file_token );
			wp_delete_file( $file_path );
			break;

		case 'mailchimp':
			// Mailchimp is processed via the chunked step path (npmp_import_ajax_step)
			// to keep large audiences under PHP's max_execution_time. The single-shot
			// execute case used to live here but had a stale column-order assumption
			// that didn't match the merge-field-aware preview. Reject explicitly with
			// a clear error so JS bugs or direct-API hits surface instead of silently
			// running the wrong code path.
			wp_send_json_error( __( 'Mailchimp imports use the chunked step API (npmp_import_step), not the legacy execute path.', 'nonprofit-manager' ) );
			return;

		case 'constant_contact':
			$creds = get_transient( 'npmp_import_cc_' . $file_token );
			if ( empty( $creds ) ) {
				wp_send_json_error( __( 'Constant Contact session expired. Please start over.', 'nonprofit-manager' ) );
			}

			$api_keys  = array( 'email_address', 'first_name', 'last_name', 'phone', 'address_line1', 'city', 'state', 'postal_code', 'country', 'tags' );
			$named_map = array();
			foreach ( $mapping as $idx => $field ) {
				if ( '' !== $field && isset( $api_keys[ $idx ] ) ) {
					$named_map[ $api_keys[ $idx ] ] = $field;
				}
			}

			$stats = $import->import_constant_contact( $creds['access_token'], $creds['list_id'], $named_map, $options );
			delete_transient( 'npmp_import_cc_' . $file_token );
			break;

		default:
			wp_send_json_error( __( 'Invalid import source.', 'nonprofit-manager' ) );
			return;
	}

	if ( is_wp_error( $stats ) ) {
		wp_send_json_error( $stats->get_error_message() );
	}

	wp_send_json_success( $stats );
}

/**
 * AJAX: Run one chunk of an import job and return progress.
 *
 * Lets the browser drive a long-running import as a series of short requests
 * that each stay well under PHP's max_execution_time. The first call from a
 * given job_token initializes a state transient with the mapping/options/cursor.
 * Each subsequent call:
 *
 *   1. Loads the state transient.
 *   2. Calls the per-page importer for the source (Mailchimp today).
 *   3. Merges page stats into running totals.
 *   4. Writes state back. If done, deletes both the state and the credentials
 *      transient and returns the final stats.
 *
 * Response:
 *   { done: bool, progress: int, total: int, partial_stats: {...}, stats?: {...} }
 *
 * Only Mailchimp uses this path today; CSV / XLSX / Google Sheets continue to
 * use the single-shot npmp_import_execute (those imports tend to be small).
 * Constant Contact would naturally fit here when next iterated on.
 */
function npmp_import_ajax_step() {
	check_ajax_referer( 'npmp_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'You do not have permission to import members.', 'nonprofit-manager' ) );
	}

	$job_token = isset( $_POST['job_token'] ) ? sanitize_text_field( wp_unslash( $_POST['job_token'] ) ) : '';
	if ( empty( $job_token ) ) {
		wp_send_json_error( __( 'Missing import job token.', 'nonprofit-manager' ) );
	}

	$state_key = 'npmp_import_job_' . $job_token;
	$lock_key  = 'npmp_import_lock_' . $job_token;

	// Best-effort mutex via a short-TTL transient. If a second request for the
	// same job_token arrives while the first is still mid-page (double-click,
	// two tabs, slow handler, JS retry race), it sees the lock and bails with
	// 409. The lock is auto-released at the end of this handler; the 90-second
	// TTL is a safety net in case PHP dies. Real consistency comes from D1-style
	// locks, not transients, but this catches 99% of the practical race.
	if ( false !== get_transient( $lock_key ) ) {
		wp_send_json_error( array( 'message' => __( 'An import step is already running. Try again in a moment.', 'nonprofit-manager' ), 'code' => 'busy' ), 409 );
	}
	set_transient( $lock_key, 1, 90 );

	// Ensure the lock is always released, including on PHP shutdown / fatal.
	register_shutdown_function(
		static function () use ( $lock_key ) {
			delete_transient( $lock_key );
		}
	);

	$state = get_transient( $state_key );

	// First call from this job initializes the state from POST.
	if ( ! is_array( $state ) ) {
		$source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
		if ( 'mailchimp' !== $source ) {
			wp_send_json_error( __( 'Chunked import is currently only available for Mailchimp.', 'nonprofit-manager' ) );
		}

		$mapping = isset( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : array();
		$options = array(
			'duplicate_handling' => isset( $_POST['duplicate_handling'] ) ? sanitize_key( $_POST['duplicate_handling'] ) : 'skip',
			'default_level'      => isset( $_POST['default_level'] ) ? sanitize_text_field( wp_unslash( $_POST['default_level'] ) ) : '',
			'default_status'     => isset( $_POST['default_status'] ) ? sanitize_key( $_POST['default_status'] ) : 'subscribed',
			'source'             => isset( $_POST['import_source_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['import_source_tag'] ) ) : '',
		);

		$state = array(
			'source'  => $source,
			'mapping' => $mapping,
			'options' => $options,
			'cursor'  => 0,
			'totals'  => array(
				'imported'       => 0,
				'updated'        => 0,
				'skipped'        => 0,
				'errors'         => 0,
				'error_messages' => array(),
			),
		);
	}

	$import = NPMP_Import_Manager::get_instance();

	if ( 'mailchimp' === $state['source'] ) {
		$creds = get_transient( 'npmp_import_mc_' . $job_token );
		if ( empty( $creds ) ) {
			delete_transient( $state_key );
			wp_send_json_error( __( 'Mailchimp session expired. Please start over.', 'nonprofit-manager' ) );
		}

		// Convert the UI's index-based mapping into named keys using the column
		// order we captured in the preview step. column_order is the source-side
		// key for each preview column (e.g., "email_address", "status", "mf:FNAME",
		// "mf:CUSTOM_TAG_THE_ORG_DEFINED"). Each named key gets matched against
		// the row shape import_mailchimp_page emits.
		$column_order = isset( $creds['column_order'] ) && is_array( $creds['column_order'] )
			? $creds['column_order']
			: array( 'email_address', 'first_name', 'last_name', 'phone', 'status', 'tags' ); // back-compat
		$named_map = array();
		foreach ( $state['mapping'] as $idx => $field ) {
			$idx = (int) $idx;
			if ( '' !== $field && isset( $column_order[ $idx ] ) ) {
				$named_map[ $column_order[ $idx ] ] = $field;
			}
		}

		// Free-plugin row cap. If the next page would put us over the limit,
		// fetch only enough to reach the cap, then stop with a cap_reached flag.
		$max_rows  = npmp_import_max_rows();
		$cursor    = (int) $state['cursor'];
		$page_size = 100;
		if ( $max_rows < PHP_INT_MAX ) {
			$remaining = $max_rows - $cursor;
			if ( $remaining <= 0 ) {
				// Already at the cap before this step. Treat as done.
				delete_transient( $state_key );
				delete_transient( 'npmp_import_mc_' . $job_token );
				wp_send_json_success(
					array(
						'done'         => true,
						'progress'     => $cursor,
						'total'        => $cursor, // we don't actually know more; cap is the ceiling for UI math.
						'stats'        => $state['totals'],
						'cap_reached'  => true,
						'cap_max_rows' => $max_rows,
					)
				);
			}
			$page_size = min( $page_size, $remaining );
		}

		$page = $import->import_mailchimp_page(
			$creds['api_key'],
			$creds['list_id'],
			$named_map,
			$state['options'],
			$cursor,
			$page_size
		);

		if ( is_wp_error( $page ) ) {
			delete_transient( $state_key );
			delete_transient( 'npmp_import_mc_' . $job_token );
			wp_send_json_error( $page->get_error_message() );
		}

		// Merge page stats into running totals.
		$stats                                = isset( $page['page_stats'] ) ? $page['page_stats'] : array();
		$state['totals']['imported']         += isset( $stats['imported'] ) ? (int) $stats['imported'] : 0;
		$state['totals']['updated']          += isset( $stats['updated'] ) ? (int) $stats['updated'] : 0;
		$state['totals']['skipped']          += isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0;
		$state['totals']['errors']           += isset( $stats['errors'] ) ? (int) $stats['errors'] : 0;
		if ( ! empty( $stats['error_messages'] ) && is_array( $stats['error_messages'] ) ) {
			// Cap accumulated error messages so the transient does not grow unbounded.
			$state['totals']['error_messages'] = array_slice(
				array_merge( $state['totals']['error_messages'], $stats['error_messages'] ),
				0,
				200
			);
		}
		$state['cursor'] = (int) $page['next_cursor'];

		// Cap-reached check after this page completed.
		$total_in_source = (int) $page['total'];
		$cap_reached     = ( $max_rows < PHP_INT_MAX ) && ( $state['cursor'] >= $max_rows ) && ( $total_in_source > $max_rows );

		if ( ! empty( $page['done'] ) || $cap_reached ) {
			delete_transient( $state_key );
			delete_transient( 'npmp_import_mc_' . $job_token );
			wp_send_json_success(
				array(
					'done'         => true,
					'progress'     => (int) $page['next_cursor'],
					'total'        => $total_in_source,
					'stats'        => $state['totals'],
					'cap_reached'  => $cap_reached,
					'cap_max_rows' => $max_rows,
				)
			);
		}

		// More pages remain; persist state and report progress.
		set_transient( $state_key, $state, HOUR_IN_SECONDS );
		wp_send_json_success(
			array(
				'done'          => false,
				'progress'      => (int) $page['next_cursor'],
				'total'         => (int) $page['total'],
				'partial_stats' => $state['totals'],
			)
		);
	}

	delete_transient( $state_key );
	wp_send_json_error( __( 'Unsupported source for chunked import.', 'nonprofit-manager' ) );
}

/**
 * AJAX: Fetch Mailchimp lists.
 */
function npmp_import_ajax_mc_lists() {
	check_ajax_referer( 'npmp_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'nonprofit-manager' ) );
	}

	$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
	if ( empty( $api_key ) ) {
		wp_send_json_error( __( 'Please enter your Mailchimp API key.', 'nonprofit-manager' ) );
	}

	$lists = npmp_mailchimp_get_lists( $api_key );
	if ( is_wp_error( $lists ) ) {
		wp_send_json_error( $lists->get_error_message() );
	}

	wp_send_json_success( $lists );
}

/**
 * AJAX: Fetch Constant Contact lists.
 */
function npmp_import_ajax_cc_lists() {
	check_ajax_referer( 'npmp_import_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'nonprofit-manager' ) );
	}

	$access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : '';
	if ( empty( $access_token ) ) {
		wp_send_json_error( __( 'Please enter your Constant Contact access token.', 'nonprofit-manager' ) );
	}

	$lists = npmp_cc_get_lists( $access_token );
	if ( is_wp_error( $lists ) ) {
		wp_send_json_error( $lists->get_error_message() );
	}

	wp_send_json_success( $lists );
}

// =====================================================================
// Admin page renderer
// =====================================================================

/**
 * Render the import wizard page.
 */
function npmp_import_render_page() {
	$import        = NPMP_Import_Manager::get_instance();
	$field_labels  = $import->get_field_labels();
	$member_mgr    = NPMP_Member_Manager::get_instance();
	?>
	<div class="wrap npmp-import-wrap">
		<h1><?php esc_html_e( 'Import Members', 'nonprofit-manager' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Import your members or email subscribers from a file or another service.', 'nonprofit-manager' ); ?></p>

		<?php wp_nonce_field( 'npmp_import_nonce', 'npmp_import_nonce' ); ?>

		<!-- ========== STEP 1: Choose Source ========== -->
		<div id="npmp-import-step-1" class="npmp-import-step">
			<h2><?php esc_html_e( 'Step 1: Choose Your Source', 'nonprofit-manager' ); ?></h2>

			<div class="npmp-import-sources">

				<label class="npmp-import-source-card">
					<input type="radio" name="import_source" value="csv" checked>
					<span class="npmp-import-source-icon dashicons dashicons-media-spreadsheet"></span>
					<span class="npmp-import-source-title"><?php esc_html_e( 'CSV File', 'nonprofit-manager' ); ?></span>
					<span class="npmp-import-source-desc"><?php esc_html_e( 'Upload a .csv file exported from any service.', 'nonprofit-manager' ); ?></span>
				</label>

				<label class="npmp-import-source-card">
					<input type="radio" name="import_source" value="xlsx">
					<span class="npmp-import-source-icon dashicons dashicons-media-document"></span>
					<span class="npmp-import-source-title"><?php esc_html_e( 'Excel / XLSX', 'nonprofit-manager' ); ?></span>
					<span class="npmp-import-source-desc"><?php esc_html_e( 'Upload an Excel spreadsheet (.xlsx).', 'nonprofit-manager' ); ?></span>
				</label>

				<label class="npmp-import-source-card">
					<input type="radio" name="import_source" value="google_sheet">
					<span class="npmp-import-source-icon dashicons dashicons-cloud"></span>
					<span class="npmp-import-source-title"><?php esc_html_e( 'Google Sheets', 'nonprofit-manager' ); ?></span>
					<span class="npmp-import-source-desc"><?php esc_html_e( 'Paste a published Google Sheet URL.', 'nonprofit-manager' ); ?></span>
				</label>

				<label class="npmp-import-source-card">
					<input type="radio" name="import_source" value="mailchimp">
					<span class="npmp-import-source-icon dashicons dashicons-email-alt"></span>
					<span class="npmp-import-source-title"><?php esc_html_e( 'Mailchimp', 'nonprofit-manager' ); ?></span>
					<span class="npmp-import-source-desc"><?php esc_html_e( 'Connect to Mailchimp and pull in an audience.', 'nonprofit-manager' ); ?></span>
				</label>

				<label class="npmp-import-source-card">
					<input type="radio" name="import_source" value="constant_contact">
					<span class="npmp-import-source-icon dashicons dashicons-groups"></span>
					<span class="npmp-import-source-title"><?php esc_html_e( 'Constant Contact', 'nonprofit-manager' ); ?></span>
					<span class="npmp-import-source-desc"><?php esc_html_e( 'Connect to Constant Contact and import a list.', 'nonprofit-manager' ); ?></span>
				</label>
			</div>

			<!-- Source-specific inputs -->
			<div id="npmp-source-fields" class="npmp-source-fields">

				<!-- CSV / XLSX upload -->
				<div class="npmp-source-panel" data-source="csv xlsx">
					<p><strong><?php esc_html_e( 'Select your file:', 'nonprofit-manager' ); ?></strong></p>
					<input type="file" id="npmp-import-file" accept=".csv,.xlsx,.xls">
				</div>

				<!-- Google Sheets URL -->
				<div class="npmp-source-panel" data-source="google_sheet" style="display:none;">
					<p>
						<strong><?php esc_html_e( 'Published Google Sheet URL:', 'nonprofit-manager' ); ?></strong><br>
						<span class="description">
							<?php esc_html_e( 'Go to File > Share > Publish to web. Choose "Comma-separated values (.csv)" and copy the link.', 'nonprofit-manager' ); ?>
						</span>
					</p>
					<input type="url" id="npmp-gsheet-url" class="regular-text" placeholder="https://docs.google.com/spreadsheets/d/.../pub?output=csv" style="width:100%;max-width:600px;">
				</div>

				<!-- Mailchimp -->
				<div class="npmp-source-panel" data-source="mailchimp" style="display:none;">
					<p>
						<strong><?php esc_html_e( 'Mailchimp API key', 'nonprofit-manager' ); ?></strong><br>
						<span class="description">
							<?php
							printf(
								/* translators: %s is a link to the Mailchimp API keys page. */
								wp_kses(
									__( 'Get a key at <a href="%s" target="_blank" rel="noopener">your Mailchimp account &rarr; Extras &rarr; API keys</a>. Click <strong>Create A Key</strong>, name it "Nonprofit Manager", and copy the value. The key ends with the data center, e.g. <code>-us21</code>.', 'nonprofit-manager' ),
									array(
										'a'      => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
										'strong' => array(),
										'code'   => array(),
									)
								),
								esc_url( 'https://us1.admin.mailchimp.com/account/api/' )
							);
							?>
						</span>
					</p>
					<input type="text" id="npmp-mc-api-key" class="regular-text" placeholder="xxxxxxxxxx-us21" autocomplete="off" spellcheck="false" style="width:100%;max-width:400px;">
					<button type="button" id="npmp-mc-fetch-lists" class="button" style="margin-left:8px;"><?php esc_html_e( 'Fetch lists', 'nonprofit-manager' ); ?></button>
					<span id="npmp-mc-lists-spinner" class="spinner" style="float:none;"></span>

					<div id="npmp-mc-lists-wrap" style="display:none;margin-top:12px;">
						<label for="npmp-mc-list-select"><strong><?php esc_html_e( 'Select a list:', 'nonprofit-manager' ); ?></strong></label><br>
						<select id="npmp-mc-list-select" style="min-width:300px;"></select>
					</div>
				</div>

				<!-- Constant Contact -->
				<div class="npmp-source-panel" data-source="constant_contact" style="display:none;">
					<p>
						<strong><?php esc_html_e( 'Constant Contact Access Token:', 'nonprofit-manager' ); ?></strong><br>
						<span class="description">
							<?php esc_html_e( 'Enter a valid Constant Contact v3 API access token.', 'nonprofit-manager' ); ?>
						</span>
					</p>
					<input type="text" id="npmp-cc-token" class="regular-text" autocomplete="off" spellcheck="false" style="width:100%;max-width:400px;">
					<button type="button" id="npmp-cc-fetch-lists" class="button" style="margin-left:8px;"><?php esc_html_e( 'Fetch Lists', 'nonprofit-manager' ); ?></button>
					<span id="npmp-cc-lists-spinner" class="spinner" style="float:none;"></span>

					<div id="npmp-cc-lists-wrap" style="display:none;margin-top:12px;">
						<label for="npmp-cc-list-select"><strong><?php esc_html_e( 'Select a list:', 'nonprofit-manager' ); ?></strong></label><br>
						<select id="npmp-cc-list-select" style="min-width:300px;"></select>
					</div>
				</div>
			</div>

			<p style="margin-top:20px;">
				<button type="button" id="npmp-import-next-1" class="button button-primary button-hero"><?php esc_html_e( 'Continue', 'nonprofit-manager' ); ?></button>
				<span id="npmp-step1-spinner" class="spinner" style="float:none;"></span>
			</p>
			<div id="npmp-step1-error" class="notice notice-error inline" role="alert" style="display:none;"><p></p></div>
		</div>

		<!-- ========== STEP 2: Preview & Map ========== -->
		<div id="npmp-import-step-2" class="npmp-import-step" style="display:none;">
			<h2><?php esc_html_e( 'Step 2: Preview & Map Columns', 'nonprofit-manager' ); ?></h2>

			<p>
				<?php esc_html_e( 'Below is a preview of your data. Choose which member field each column maps to, or select "Skip" to ignore it.', 'nonprofit-manager' ); ?>
				<span class="npmp-import-total-badge"></span>
			</p>

			<div class="npmp-import-preview-table-wrap">
				<table id="npmp-import-preview-table" class="widefat striped">
					<thead id="npmp-preview-thead"></thead>
					<tbody id="npmp-preview-tbody"></tbody>
				</table>
			</div>

			<!-- Import options -->
			<div class="npmp-import-options">
				<h3><?php esc_html_e( 'Import Options', 'nonprofit-manager' ); ?></h3>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="npmp-opt-duplicates"><?php esc_html_e( 'Duplicate Handling', 'nonprofit-manager' ); ?></label></th>
						<td>
							<select id="npmp-opt-duplicates">
								<option value="skip" selected><?php esc_html_e( 'Skip duplicates', 'nonprofit-manager' ); ?></option>
								<option value="update"><?php esc_html_e( 'Update existing members', 'nonprofit-manager' ); ?></option>
								<option value="create_new"><?php esc_html_e( 'Create new (allow duplicates)', 'nonprofit-manager' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp-opt-level"><?php esc_html_e( 'Default Membership Level', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp-opt-level" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Standard, Premium', 'nonprofit-manager' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp-opt-status"><?php esc_html_e( 'Default Status', 'nonprofit-manager' ); ?></label></th>
						<td>
							<select id="npmp-opt-status">
								<?php foreach ( $member_mgr->get_statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, 'subscribed' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp-opt-source"><?php esc_html_e( 'Source Tag', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp-opt-source" class="regular-text" placeholder="<?php echo esc_attr( sprintf( 'Import %s', wp_date( 'Y-m-d' ) ) ); ?>">
							<p class="description"><?php esc_html_e( 'An optional label to identify where these members came from.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<p style="margin-top:20px;">
				<button type="button" id="npmp-import-back-2" class="button"><?php esc_html_e( 'Back', 'nonprofit-manager' ); ?></button>
				<button type="button" id="npmp-import-run" class="button button-primary button-hero"><?php esc_html_e( 'Run Import', 'nonprofit-manager' ); ?></button>
				<span id="npmp-step2-spinner" class="spinner" style="float:none;"></span>
			</p>
			<div id="npmp-step2-error" class="notice notice-error inline" role="alert" style="display:none;"><p></p></div>
		</div>

		<!-- ========== STEP 3: Results ========== -->
		<div id="npmp-import-step-3" class="npmp-import-step" style="display:none;">
			<h2><?php esc_html_e( 'Step 3: Import Results', 'nonprofit-manager' ); ?></h2>

			<div id="npmp-import-progress-wrap" style="margin-bottom:20px;">
				<div class="npmp-import-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Import progress', 'nonprofit-manager' ); ?>">
					<div class="npmp-import-progress-fill" style="width:0%"></div>
				</div>
				<p id="npmp-import-progress-text" aria-live="polite"><?php esc_html_e( 'Importing...', 'nonprofit-manager' ); ?></p>
			</div>

			<div id="npmp-import-results" style="display:none;">
				<div class="npmp-import-stats-grid">
					<div class="npmp-import-stat npmp-stat-imported">
						<span class="npmp-stat-number" id="npmp-stat-imported">0</span>
						<span class="npmp-stat-label"><?php esc_html_e( 'Imported', 'nonprofit-manager' ); ?></span>
					</div>
					<div class="npmp-import-stat npmp-stat-updated">
						<span class="npmp-stat-number" id="npmp-stat-updated">0</span>
						<span class="npmp-stat-label"><?php esc_html_e( 'Updated', 'nonprofit-manager' ); ?></span>
					</div>
					<div class="npmp-import-stat npmp-stat-skipped">
						<span class="npmp-stat-number" id="npmp-stat-skipped">0</span>
						<span class="npmp-stat-label"><?php esc_html_e( 'Skipped', 'nonprofit-manager' ); ?></span>
					</div>
					<div class="npmp-import-stat npmp-stat-errors">
						<span class="npmp-stat-number" id="npmp-stat-errors">0</span>
						<span class="npmp-stat-label"><?php esc_html_e( 'Errors', 'nonprofit-manager' ); ?></span>
					</div>
				</div>

				<!-- Free-cap upsell, shown when the import was truncated at the cap. -->
				<div id="npmp-import-cap-notice" class="notice notice-info inline" style="display:none;margin-top:20px;border-left-color:#2271b1;">
					<h3 style="margin-top:.5em;"><?php esc_html_e( 'Hit the free import limit', 'nonprofit-manager' ); ?></h3>
					<p id="npmp-import-cap-text"></p>
					<p>
						<a href="<?php echo esc_url( function_exists( 'npmp_get_upgrade_url' ) ? npmp_get_upgrade_url() : 'https://nonprofitmanager.ericrosenberg.com/pricing' ); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Upgrade to Pro', 'nonprofit-manager' ); ?></a>
						<a href="https://nonprofitmanager.ericrosenberg.com/" target="_blank" rel="noopener" style="margin-left:8px;"><?php esc_html_e( 'Learn more', 'nonprofit-manager' ); ?></a>
					</p>
				</div>

				<div id="npmp-import-error-details" style="display:none;margin-top:20px;">
					<h3><?php esc_html_e( 'Error Details', 'nonprofit-manager' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( '#', 'nonprofit-manager' ); ?></th>
								<th><?php esc_html_e( 'Error', 'nonprofit-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="npmp-error-tbody"></tbody>
					</table>
				</div>

				<p style="margin-top:24px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View Members', 'nonprofit-manager' ); ?></a>
					<button type="button" id="npmp-import-again" class="button"><?php esc_html_e( 'Import More', 'nonprofit-manager' ); ?></button>
				</p>
			</div>
		</div>
	</div>

	<?php npmp_import_render_styles(); ?>
	<?php npmp_import_render_scripts( $field_labels ); ?>
	<?php
}

// =====================================================================
// Inline styles
// =====================================================================

/**
 * Output import page CSS.
 */
function npmp_import_render_styles() {
	?>
	<style>
		.npmp-import-wrap { max-width: 900px; }
		.npmp-import-step h2 { margin-top: 0; }

		/* Source cards */
		.npmp-import-sources {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
			gap: 12px;
			margin: 16px 0;
		}
		.npmp-import-source-card {
			display: flex;
			flex-direction: column;
			align-items: center;
			text-align: center;
			padding: 20px 12px;
			border: 2px solid #dcdcde;
			border-radius: 8px;
			cursor: pointer;
			transition: border-color 0.2s, box-shadow 0.2s;
			background: #fff;
		}
		.npmp-import-source-card:hover {
			border-color: #2271b1;
		}
		.npmp-import-source-card input[type="radio"] {
			position: absolute;
			opacity: 0;
			pointer-events: none;
		}
		.npmp-import-source-card:has(input:checked) {
			border-color: #2271b1;
			box-shadow: 0 0 0 1px #2271b1;
			background: #f0f6fc;
		}
		/* Keyboard focus indicator. The native radio is opacity-0, so the card
		 * needs its own visible focus ring or keyboard users land here blind. */
		.npmp-import-source-card:has(input:focus-visible) {
			outline: 2px solid #2271b1;
			outline-offset: 2px;
		}
		.npmp-import-source-icon {
			font-size: 36px;
			width: 36px;
			height: 36px;
			color: #2271b1;
			margin-bottom: 8px;
		}
		.npmp-import-source-title {
			font-weight: 600;
			font-size: 14px;
			margin-bottom: 4px;
		}
		.npmp-import-source-desc {
			font-size: 12px;
			color: #646970;
		}

		/* Source panels */
		.npmp-source-fields { margin-top: 16px; }

		/* Preview table */
		.npmp-import-preview-table-wrap {
			overflow-x: auto;
			margin: 16px 0;
		}
		#npmp-import-preview-table th {
			white-space: nowrap;
			vertical-align: top;
			padding: 10px 8px;
		}
		#npmp-import-preview-table .npmp-col-header-name {
			display: block;
			font-weight: 600;
			margin-bottom: 6px;
		}
		#npmp-import-preview-table .npmp-col-auto-detected {
			color: #007a23; /* darkened from #00a32a to hit WCAG AA 4.5:1 contrast */
			font-size: 13px;
			font-weight: 600;
			display: block;
			margin-top: 2px;
		}
		.npmp-import-total-badge {
			background: #dcdcde;
			padding: 2px 10px;
			border-radius: 10px;
			font-size: 13px;
			margin-left: 6px;
		}

		/* Options */
		.npmp-import-options {
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 0 16px 16px;
			margin: 20px 0;
		}
		.npmp-import-options .form-table th { padding-left: 0; }

		/* Progress bar */
		.npmp-import-progress-bar {
			height: 24px;
			background: #dcdcde;
			border-radius: 12px;
			overflow: hidden;
		}
		.npmp-import-progress-fill {
			height: 100%;
			background: #2271b1;
			border-radius: 12px;
			transition: width 0.4s ease;
		}

		/* Stats grid */
		.npmp-import-stats-grid {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 16px;
			margin: 20px 0;
		}
		.npmp-import-stat {
			text-align: center;
			padding: 20px;
			border-radius: 8px;
			background: #fff;
			border: 1px solid #dcdcde;
		}
		.npmp-stat-number {
			display: block;
			font-size: 32px;
			font-weight: 700;
			line-height: 1.2;
		}
		.npmp-stat-label {
			display: block;
			font-size: 13px;
			color: #646970;
			margin-top: 4px;
		}
		.npmp-stat-imported .npmp-stat-number { color: #00a32a; }
		.npmp-stat-updated .npmp-stat-number  { color: #2271b1; }
		.npmp-stat-skipped .npmp-stat-number  { color: #dba617; }
		.npmp-stat-errors .npmp-stat-number   { color: #d63638; }

		/* Inline notice */
		.notice.inline { margin: 12px 0; }
	</style>
	<?php
}

// =====================================================================
// Inline JavaScript
// =====================================================================

/**
 * Output import page JS.
 *
 * @param array $field_labels Destination field labels.
 */
function npmp_import_render_scripts( $field_labels ) {
	// Add special fields for first/last name.
	$field_labels['first_name'] = __( 'First Name', 'nonprofit-manager' );
	$field_labels['last_name']  = __( 'Last Name', 'nonprofit-manager' );
	?>
	<script>
	(function($) {
		'use strict';

		var nonce       = $('#npmp_import_nonce').val(),
			ajaxUrl     = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
			fieldLabels = <?php echo wp_json_encode( $field_labels ); ?>,
			previewData = null;

		// -------------------------------------------------------
		// Step 1: Source selection — show/hide panels
		// -------------------------------------------------------
		$('input[name="import_source"]').on('change', function() {
			var val = $(this).val();
			$('.npmp-source-panel').hide();
			// CSV and XLSX share the file-upload panel.
			if (val === 'csv' || val === 'xlsx') {
				$('.npmp-source-panel[data-source="csv xlsx"]').show();
				var accept = val === 'xlsx' ? '.xlsx,.xls' : '.csv';
				$('#npmp-import-file').attr('accept', accept);
			} else {
				$('.npmp-source-panel[data-source="' + val + '"]').show();
			}
		}).filter(':checked').trigger('change');

		// -------------------------------------------------------
		// Mailchimp: fetch lists
		// -------------------------------------------------------
		$('#npmp-mc-fetch-lists').on('click', function() {
			var key = $('#npmp-mc-api-key').val().trim();
			if (!key) { alert('<?php echo esc_js( __( 'Please enter your Mailchimp API key.', 'nonprofit-manager' ) ); ?>'); return; }

			$('#npmp-mc-lists-spinner').addClass('is-active');
			$.post(ajaxUrl, { action: 'npmp_import_mailchimp_lists', nonce: nonce, api_key: key }, function(resp) {
				$('#npmp-mc-lists-spinner').removeClass('is-active');
				if (!resp.success) { showError('#npmp-step1-error', resp.data); return; }
				var sel = $('#npmp-mc-list-select').empty();
				$.each(resp.data, function(i, l) {
					// Build the option with .val()/.text() so a list named
					// `<img src=x onerror=...>` doesn't execute. Mailchimp
					// permits arbitrary characters in audience names, and the
					// API echoes them back unchanged.
					var count = parseInt(l.member_count, 10);
					if (isNaN(count)) { count = 0; }
					$('<option/>')
						.val(String(l.id == null ? '' : l.id))
						.text(String(l.name == null ? '' : l.name) + ' (' + count + ' members)')
						.appendTo(sel);
				});
				$('#npmp-mc-lists-wrap').show();
			}).fail(function(){ $('#npmp-mc-lists-spinner').removeClass('is-active'); showError('#npmp-step1-error', 'Request failed.'); });
		});

		// -------------------------------------------------------
		// Constant Contact: fetch lists
		// -------------------------------------------------------
		$('#npmp-cc-fetch-lists').on('click', function() {
			var token = $('#npmp-cc-token').val().trim();
			if (!token) { alert('<?php echo esc_js( __( 'Please enter your Constant Contact access token.', 'nonprofit-manager' ) ); ?>'); return; }

			$('#npmp-cc-lists-spinner').addClass('is-active');
			$.post(ajaxUrl, { action: 'npmp_import_cc_lists', nonce: nonce, access_token: token }, function(resp) {
				$('#npmp-cc-lists-spinner').removeClass('is-active');
				if (!resp.success) { showError('#npmp-step1-error', resp.data); return; }
				var sel = $('#npmp-cc-list-select').empty();
				$.each(resp.data, function(i, l) {
					// Same DOM-safe construction as the Mailchimp branch —
					// see comment above for the threat model.
					var count = parseInt(l.member_count, 10);
					if (isNaN(count)) { count = 0; }
					$('<option/>')
						.val(String(l.id == null ? '' : l.id))
						.text(String(l.name == null ? '' : l.name) + ' (' + count + ' contacts)')
						.appendTo(sel);
				});
				$('#npmp-cc-lists-wrap').show();
			}).fail(function(){ $('#npmp-cc-lists-spinner').removeClass('is-active'); showError('#npmp-step1-error', 'Request failed.'); });
		});

		// -------------------------------------------------------
		// Step 1 → Step 2 (preview)
		// -------------------------------------------------------
		$('#npmp-import-next-1').on('click', function() {
			var source = $('input[name="import_source"]:checked').val(),
				fd     = new FormData();

			fd.append('action', 'npmp_import_preview');
			fd.append('nonce', nonce);
			fd.append('source', source);

			if (source === 'csv' || source === 'xlsx') {
				var file = $('#npmp-import-file')[0].files[0];
				if (!file) { showError('#npmp-step1-error', '<?php echo esc_js( __( 'Please select a file.', 'nonprofit-manager' ) ); ?>'); return; }
				fd.append('import_file', file);
			} else if (source === 'google_sheet') {
				var url = $('#npmp-gsheet-url').val().trim();
				if (!url) { showError('#npmp-step1-error', '<?php echo esc_js( __( 'Please enter a Google Sheet URL.', 'nonprofit-manager' ) ); ?>'); return; }
				fd.append('sheet_url', url);
			} else if (source === 'mailchimp') {
				fd.append('mc_api_key', $('#npmp-mc-api-key').val().trim());
				fd.append('mc_list_id', $('#npmp-mc-list-select').val());
			} else if (source === 'constant_contact') {
				fd.append('cc_token', $('#npmp-cc-token').val().trim());
				fd.append('cc_list_id', $('#npmp-cc-list-select').val());
			}

			hideError('#npmp-step1-error');
			$('#npmp-step1-spinner').addClass('is-active');

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				success: function(resp) {
					$('#npmp-step1-spinner').removeClass('is-active');
					if (!resp.success) { showError('#npmp-step1-error', resp.data); return; }
					previewData = resp.data;
					renderPreview(resp.data);
					goToStep(2);
				},
				error: function() {
					$('#npmp-step1-spinner').removeClass('is-active');
					showError('#npmp-step1-error', '<?php echo esc_js( __( 'Upload failed. Please try again.', 'nonprofit-manager' ) ); ?>');
				}
			});
		});

		// -------------------------------------------------------
		// Step 2: Build preview table
		// -------------------------------------------------------
		function renderPreview(data) {
			var thead = $('#npmp-preview-thead').empty(),
				tbody = $('#npmp-preview-tbody').empty(),
				autoMap = data.auto_map || {};

			$('.npmp-import-total-badge').text(data.total_rows + ' rows total');

			// Build mapping select for each column.
			var headerRow = '<tr>';
			$.each(data.headers, function(i, h) {
				var mapVal = autoMap[i] || '';
				var detected = mapVal !== '';

				headerRow += '<th>';
				headerRow += '<span class="npmp-col-header-name">' + escHtml(h) + '</span>';
				headerRow += '<select class="npmp-col-map" data-col="' + i + '">';
				headerRow += '<option value=""><?php echo esc_js( __( '-- Skip --', 'nonprofit-manager' ) ); ?></option>';

				$.each(fieldLabels, function(key, label) {
					var sel = (mapVal === key) ? ' selected' : '';
					headerRow += '<option value="' + key + '"' + sel + '>' + escHtml(label) + '</option>';
				});

				headerRow += '</select>';

				if (detected) {
					headerRow += '<span class="npmp-col-auto-detected">&#10003; Auto-detected</span>';
				}

				headerRow += '</th>';
			});
			headerRow += '</tr>';
			thead.html(headerRow);

			// Data rows.
			$.each(data.rows, function(i, row) {
				var tr = '<tr>';
				$.each(data.headers, function(j) {
					var val = (row[j] !== undefined && row[j] !== null) ? row[j] : '';
					tr += '<td>' + escHtml(val) + '</td>';
				});
				tr += '</tr>';
				tbody.append(tr);
			});

			// Auto-populate source tag.
			if (!$('#npmp-opt-source').val()) {
				var srcLabel = data.source ? data.source.replace(/_/g, ' ') : 'File';
				srcLabel = srcLabel.charAt(0).toUpperCase() + srcLabel.slice(1);
				$('#npmp-opt-source').val(srcLabel + ' Import <?php echo esc_js( wp_date( 'Y-m-d' ) ); ?>');
			}
		}

		// -------------------------------------------------------
		// Step 2 → Run import
		// -------------------------------------------------------
		$('#npmp-import-run').on('click', function() {
			if (!previewData) return;

			// Gather mapping.
			var mapping = {};
			$('.npmp-col-map').each(function() {
				mapping[ $(this).data('col') ] = $(this).val();
			});

			// Make sure at least email is mapped.
			var hasEmail = false;
			$.each(mapping, function(k, v) { if (v === 'email') hasEmail = true; });
			if (!hasEmail) {
				showError('#npmp-step2-error', '<?php echo esc_js( __( 'You must map at least one column to "Email".', 'nonprofit-manager' ) ); ?>');
				return;
			}

			hideError('#npmp-step2-error');
			$('#npmp-step2-spinner').addClass('is-active');

			goToStep(3);
			$('#npmp-import-results').hide();
			$('#npmp-import-progress-wrap').show();
			$('.npmp-import-progress-fill').css('width', '10%');
			$('.npmp-import-progress-bar').attr('aria-valuenow', 10);
			$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Starting import...', 'nonprofit-manager' ) ); ?>');

			var commonPayload = {
				nonce:              nonce,
				source:             previewData.source,
				mapping:            mapping,
				duplicate_handling: $('#npmp-opt-duplicates').val(),
				default_level:      $('#npmp-opt-level').val(),
				default_status:     $('#npmp-opt-status').val(),
				import_source_tag:  $('#npmp-opt-source').val()
			};

			if (previewData.source === 'mailchimp') {
				// Chunked path: each request fetches one Mailchimp page so big
				// audiences don't blow PHP's max_execution_time. Server keeps
				// state in a transient keyed on file_token; we just loop until
				// the response says done.
				runChunkedImport(previewData.file_token, commonPayload);
			} else {
				// Single-shot path for CSV / XLSX / Google Sheets / Constant Contact.
				$.post(ajaxUrl, $.extend({
					action:     'npmp_import_execute',
					file_token: previewData.file_token
				}, commonPayload), function(resp) {
					$('#npmp-step2-spinner').removeClass('is-active');
					$('.npmp-import-progress-fill').css('width', '100%');
					$('.npmp-import-progress-bar').attr('aria-valuenow', 100);
					$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Complete!', 'nonprofit-manager' ) ); ?>');

					if (!resp.success) {
						$('#npmp-import-progress-text').text('Import failed: ' + resp.data);
						return;
					}
					renderImportResults(resp.data);
				}).fail(function() {
					$('#npmp-step2-spinner').removeClass('is-active');
					$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Import request failed. Please try again.', 'nonprofit-manager' ) ); ?>');
				});
			}
		});

		// -------------------------------------------------------
		// Chunked import driver (Mailchimp)
		// -------------------------------------------------------
		function runChunkedImport(jobToken, basePayload) {
			var stopped = false;

			function step() {
				if (stopped) return;
				$.post(ajaxUrl, $.extend({
					action:    'npmp_import_step',
					job_token: jobToken
				}, basePayload), function(resp) {
					if (!resp.success) {
						stopped = true;
						$('#npmp-step2-spinner').removeClass('is-active');
						$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Import failed:', 'nonprofit-manager' ) ); ?> ' + resp.data);
						return;
					}

					var d = resp.data;
					var pct = (d.total > 0)
						? Math.min(100, Math.round((d.progress / d.total) * 100))
						: 50;
					$('.npmp-import-progress-fill').css('width', pct + '%');
					$('.npmp-import-progress-bar').attr('aria-valuenow', pct);

					if (d.done) {
						$('#npmp-step2-spinner').removeClass('is-active');
						$('.npmp-import-progress-fill').css('width', '100%');
						$('.npmp-import-progress-bar').attr('aria-valuenow', 100);
						$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Complete!', 'nonprofit-manager' ) ); ?>');
						// Merge cap fields from the chunked response into the stats object
						// so renderImportResults sees them the same way single-shot results do.
						renderImportResults($.extend({}, d.stats, {
							cap_reached:  !!d.cap_reached,
							cap_max_rows: d.cap_max_rows,
							source_total: d.total
						}));
						return;
					}

					$('#npmp-import-progress-text').text(
						'<?php echo esc_js( __( 'Imported', 'nonprofit-manager' ) ); ?>' +
						' ' + d.progress + ' ' + '<?php echo esc_js( __( 'of', 'nonprofit-manager' ) ); ?>' + ' ' +
						d.total + '...'
					);
					// Hand back to the event loop briefly so the bar paints.
					setTimeout(step, 50);
				}).fail(function() {
					stopped = true;
					$('#npmp-step2-spinner').removeClass('is-active');
					$('#npmp-import-progress-text').text('<?php echo esc_js( __( 'Import request failed. Please try again.', 'nonprofit-manager' ) ); ?>');
				});
			}

			step();
		}

		function renderImportResults(s) {
			$('#npmp-stat-imported').text(s.imported || 0);
			$('#npmp-stat-updated').text(s.updated || 0);
			$('#npmp-stat-skipped').text(s.skipped || 0);
			$('#npmp-stat-errors').text(s.errors || 0);

			if (s.error_messages && s.error_messages.length) {
				var errorTbody = $('#npmp-error-tbody').empty();
				$.each(s.error_messages, function(i, msg) {
					errorTbody.append('<tr><td>' + (i+1) + '</td><td>' + escHtml(msg) + '</td></tr>');
				});
				$('#npmp-import-error-details').show();
			} else {
				$('#npmp-import-error-details').hide();
			}

			// Free-cap upsell. Shown only when the importer truncated the source.
			// Pro lifts npmp_import_max_rows so cap_reached is always false there.
			if (s.cap_reached) {
				var imported = (s.imported || 0) + (s.updated || 0);
				var total    = s.source_total || imported;
				var cap      = s.cap_max_rows || 50;
				var template = '<?php echo esc_js( __( 'Imported the first %1$s of %2$s records. The free version imports up to %3$s at a time. Upgrade to Pro to import the rest in one pass.', 'nonprofit-manager' ) ); ?>';
				$('#npmp-import-cap-text').text(
					template
						.replace('%1$s', imported)
						.replace('%2$s', total)
						.replace('%3$s', cap)
				);
				$('#npmp-import-cap-notice').show();
			} else {
				$('#npmp-import-cap-notice').hide();
			}

			$('#npmp-import-results').show();
		}

		// -------------------------------------------------------
		// Navigation helpers
		// -------------------------------------------------------
		$('#npmp-import-back-2').on('click', function() { goToStep(1); });
		$('#npmp-import-again').on('click', function() {
			previewData = null;
			$('#npmp-import-file').val('');
			$('#npmp-opt-source').val('');
			goToStep(1);
		});

		function goToStep(n) {
			$('.npmp-import-step').hide();
			$('#npmp-import-step-' + n).show();
		}

		function showError(sel, msg) {
			$(sel).show().find('p').text(msg);
		}
		function hideError(sel) {
			$(sel).hide();
		}

		function escHtml(str) {
			if (typeof str !== 'string') return '';
			return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
		}

	})(jQuery);
	</script>
	<?php
}
