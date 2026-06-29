<?php
/**
 * File path: includes/import/class-import-manager.php
 *
 * NPMP_Import_Manager - Core import engine for CSV, XLSX, Google Sheets,
 * Mailchimp, and Constant Contact.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

class NPMP_Import_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Member manager reference.
	 *
	 * @var NPMP_Member_Manager
	 */
	private $member_manager;

	/**
	 * Field labels used in the column-mapping UI.
	 *
	 * @var array
	 */
	private $field_labels = array(
		'email'            => 'Email',
		'name'             => 'Full Name',
		'membership_level' => 'Membership Level',
		'status'           => 'Status',
		'phone'            => 'Phone',
		'mobile'           => 'Mobile',
		'address_line1'    => 'Address Line 1',
		'address_line2'    => 'Address Line 2',
		'city'             => 'City',
		'state'            => 'State / Province',
		'postal_code'      => 'ZIP / Postal Code',
		'country'          => 'Country',
		'tags'             => 'Tags',
		'source'           => 'Source',
		'notes'            => 'Notes',
	);

	/**
	 * Common header synonyms used by popular email services.
	 *
	 * @var array  Destination field => array of recognised header variants (lowercase).
	 */
	private $header_synonyms = array(
		'email'            => array( 'email', 'email address', 'email_address', 'e-mail', 'e-mail address', 'emailaddress', 'contact email', 'member email', 'primary email' ),
		'name'             => array( 'name', 'full name', 'full_name', 'fullname', 'contact name', 'member name', 'display name', 'display_name' ),
		'phone'            => array( 'phone', 'phone number', 'phone_number', 'telephone', 'tel', 'home phone', 'work phone', 'primary phone' ),
		'mobile'           => array( 'mobile', 'mobile phone', 'mobile_phone', 'cell', 'cell phone', 'cellphone' ),
		'address_line1'    => array( 'address', 'address line 1', 'address_line1', 'address1', 'street', 'street address', 'mailing address', 'addr1' ),
		'address_line2'    => array( 'address line 2', 'address_line2', 'address2', 'addr2', 'apt', 'suite', 'unit' ),
		'city'             => array( 'city', 'town', 'locality' ),
		'state'            => array( 'state', 'state/province', 'state_province', 'province', 'region', 'state/region' ),
		'postal_code'      => array( 'zip', 'zip code', 'zip_code', 'zipcode', 'postal code', 'postal_code', 'postalcode', 'postcode' ),
		'country'          => array( 'country', 'country code', 'country_code' ),
		'tags'             => array( 'tags', 'tag', 'groups', 'group', 'interests', 'categories' ),
		'membership_level' => array( 'membership level', 'membership_level', 'level', 'tier', 'membership', 'member level', 'member type' ),
		'status'           => array( 'status', 'member status', 'subscription status', 'contact status' ),
		'notes'            => array( 'notes', 'note', 'comments', 'comment', 'description' ),
		'source'           => array( 'source', 'origin', 'signup source', 'lead source' ),
	);

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		return self::$instance ? self::$instance : ( self::$instance = new self() );
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->member_manager = NPMP_Member_Manager::get_instance();
	}

	// ------------------------------------------------------------------
	// Public field helpers
	// ------------------------------------------------------------------

	/**
	 * Return associative array of importable fields and their labels.
	 *
	 * @return array
	 */
	public function get_field_labels() {
		return $this->field_labels;
	}

	// ------------------------------------------------------------------
	// Import entry points
	// ------------------------------------------------------------------

	/**
	 * Import members from a CSV file.
	 *
	 * @param string $file_path Absolute path to CSV.
	 * @param array  $mapping   Column index => field name.
	 * @param array  $options   Import options.
	 * @return array Stats array.
	 */
	public function import_csv( $file_path, $mapping, $options = array() ) {
		$rows = $this->parse_csv( $file_path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// First row is headers — skip it.
		array_shift( $rows );

		return $this->process_rows( $rows, $mapping, $options );
	}

	/**
	 * Import members from an XLSX file (zero-dependency).
	 *
	 * @param string $file_path Absolute path to XLSX.
	 * @param array  $mapping   Column index => field name.
	 * @param array  $options   Import options.
	 * @return array|WP_Error Stats array.
	 */
	public function import_xlsx( $file_path, $mapping, $options = array() ) {
		$rows = $this->parse_xlsx( $file_path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// First row is headers — skip it.
		array_shift( $rows );

		return $this->process_rows( $rows, $mapping, $options );
	}

	/**
	 * Import members from a published Google Sheet CSV URL.
	 *
	 * @param string $url     Published CSV URL.
	 * @param array  $mapping Column index => field name.
	 * @param array  $options Import options.
	 * @return array|WP_Error Stats array.
	 */
	public function import_google_sheet( $url, $mapping, $options = array() ) {
		// Host-restrict before any network call. This method accepts a URL parameter
		// from anywhere; prevent SSRF (server-side request forgery, where a URL pointed
		// at an internal address gets fetched as the server) by enforcing docs.google.com.
		$parsed_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $parsed_host || 'docs.google.com' !== strtolower( $parsed_host ) ) {
			return new WP_Error( 'npmp_gsheet_host', __( 'Only docs.google.com URLs are supported.', 'nonprofit-manager' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 10 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'npmp_gsheet_fetch', __( 'Could not fetch Google Sheet. Check the URL and try again.', 'nonprofit-manager' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'npmp_gsheet_empty', __( 'The Google Sheet returned no data. Make sure it is published as CSV.', 'nonprofit-manager' ) );
		}

		// Write to temp file and parse.
		$tmp = wp_tempnam( 'npmp_gsheet' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $body );
		$rows = $this->parse_csv( $tmp );
		wp_delete_file( $tmp );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		array_shift( $rows );

		return $this->process_rows( $rows, $mapping, $options );
	}

	/**
	 * Import members from Mailchimp via API.
	 *
	 * @param string $api_key Mailchimp API key.
	 * @param string $list_id List/audience ID.
	 * @param array  $mapping Column name => field name.
	 * @param array  $options Import options.
	 * @return array|WP_Error Stats array.
	 */
	public function import_mailchimp( $api_key, $list_id, $mapping, $options = array() ) {
		if ( ! function_exists( 'npmp_mailchimp_get_members' ) ) {
			return new WP_Error( 'npmp_mc_missing', __( 'Mailchimp API module is not loaded.', 'nonprofit-manager' ) );
		}

		$all_rows = array();
		$offset   = 0;
		$count    = 100;

		do {
			$result = npmp_mailchimp_get_members( $api_key, $list_id, $offset, $count );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result['members'] as $mc_member ) {
				$row = array(
					'email_address' => isset( $mc_member['email_address'] ) ? $mc_member['email_address'] : '',
					'first_name'    => isset( $mc_member['merge_fields']['FNAME'] ) ? $mc_member['merge_fields']['FNAME'] : '',
					'last_name'     => isset( $mc_member['merge_fields']['LNAME'] ) ? $mc_member['merge_fields']['LNAME'] : '',
					'phone'         => isset( $mc_member['merge_fields']['PHONE'] ) ? $mc_member['merge_fields']['PHONE'] : '',
					'status'        => $this->map_mailchimp_status( isset( $mc_member['status'] ) ? $mc_member['status'] : '' ),
					'tags'          => '',
				);

				// Combine tags.
				if ( ! empty( $mc_member['tags'] ) ) {
					$tag_names  = wp_list_pluck( $mc_member['tags'], 'name' );
					$row['tags'] = implode( ',', $tag_names );
				}

				// Address fields from merge fields.
				if ( ! empty( $mc_member['merge_fields']['ADDRESS'] ) ) {
					$addr = $mc_member['merge_fields']['ADDRESS'];
					$row['address_line1'] = isset( $addr['addr1'] ) ? $addr['addr1'] : '';
					$row['address_line2'] = isset( $addr['addr2'] ) ? $addr['addr2'] : '';
					$row['city']          = isset( $addr['city'] ) ? $addr['city'] : '';
					$row['state']         = isset( $addr['state'] ) ? $addr['state'] : '';
					$row['postal_code']   = isset( $addr['zip'] ) ? $addr['zip'] : '';
					$row['country']       = isset( $addr['country'] ) ? $addr['country'] : '';
				}

				$all_rows[] = $row;
			}

			$offset     += $count;
			$total_items = isset( $result['total_items'] ) ? (int) $result['total_items'] : 0;

		} while ( $offset < $total_items );

		// For API sources we use named-key rows and convert mapping.
		return $this->process_named_rows( $all_rows, $mapping, $options );
	}

	/**
	 * Import a single page of Mailchimp members. Used by the chunked AJAX
	 * import path so a 5,000-member list does not block PHP's max_execution_time.
	 *
	 * Each call:
	 *   1. Fetches one page from Mailchimp at the given offset.
	 *   2. Maps merge fields into NPM rows.
	 *   3. Runs process_named_rows on that page only (so it commits to DB now).
	 *   4. Returns the partial stats + the next cursor + the upstream total.
	 *
	 * The caller (npmp_import_ajax_step) loops until next_cursor >= total,
	 * accumulating stats in a transient between calls.
	 *
	 * @param string $api_key    Mailchimp API key.
	 * @param string $list_id    Audience id.
	 * @param array  $mapping    Named mapping (mc_field => npm_field).
	 * @param array  $options    Import options (duplicate_handling, default_level, etc.).
	 * @param int    $cursor     Offset to start at (number of members already processed).
	 * @param int    $batch_size Members per page; Mailchimp caps at 1000, default 100.
	 * @return array|WP_Error {
	 *   page_stats: same shape as process_named_rows return,
	 *   next_cursor: int,
	 *   total: int (Mailchimp's reported total_items),
	 *   done: bool
	 * }
	 */
	public function import_mailchimp_page( $api_key, $list_id, $mapping, $options = array(), $cursor = 0, $batch_size = 100 ) {
		if ( ! function_exists( 'npmp_mailchimp_get_members' ) ) {
			return new WP_Error( 'npmp_mc_missing', __( 'Mailchimp API module is not loaded.', 'nonprofit-manager' ) );
		}

		$batch_size = max( 1, min( 1000, (int) $batch_size ) );
		$cursor     = max( 0, (int) $cursor );

		$result = npmp_mailchimp_get_members( $api_key, $list_id, $cursor, $batch_size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Each row emits Mailchimp-native columns under fixed keys plus every
		// merge_field under "mf:<TAG>" keys. The named mapping coming from
		// admin-import.php uses the same "mf:<TAG>" convention, so custom merge
		// tags map through without any further plumbing here.
		$rows = array();
		foreach ( $result['members'] as $mc_member ) {
			$tag_names = ! empty( $mc_member['tags'] ) ? wp_list_pluck( $mc_member['tags'], 'name' ) : array();

			$row = array(
				'email_address' => isset( $mc_member['email_address'] ) ? $mc_member['email_address'] : '',
				'status'        => $this->map_mailchimp_status( isset( $mc_member['status'] ) ? $mc_member['status'] : '' ),
				'tags'          => implode( ',', $tag_names ),
				// Back-compat keys for tests / callers that pre-date the mf:* convention.
				'first_name'    => isset( $mc_member['merge_fields']['FNAME'] ) ? $mc_member['merge_fields']['FNAME'] : '',
				'last_name'     => isset( $mc_member['merge_fields']['LNAME'] ) ? $mc_member['merge_fields']['LNAME'] : '',
				'phone'         => isset( $mc_member['merge_fields']['PHONE'] ) ? $mc_member['merge_fields']['PHONE'] : '',
			);

			if ( ! empty( $mc_member['merge_fields'] ) && is_array( $mc_member['merge_fields'] ) ) {
				foreach ( $mc_member['merge_fields'] as $tag => $value ) {
					// ADDRESS is the one structured merge field. Map its parts onto
					// NPM's compound address fields AND expose the full structure
					// under mf:ADDRESS so a user could remap it manually if they want.
					if ( 'ADDRESS' === $tag && is_array( $value ) ) {
						$row['address_line1'] = isset( $value['addr1'] ) ? $value['addr1'] : '';
						$row['address_line2'] = isset( $value['addr2'] ) ? $value['addr2'] : '';
						$row['city']          = isset( $value['city'] ) ? $value['city'] : '';
						$row['state']         = isset( $value['state'] ) ? $value['state'] : '';
						$row['postal_code']   = isset( $value['zip'] ) ? $value['zip'] : '';
						$row['country']       = isset( $value['country'] ) ? $value['country'] : '';
						$row[ 'mf:' . $tag ]  = trim(
							implode(
								', ',
								array_filter(
									array(
										$row['address_line1'],
										$row['city'],
										$row['state'],
										$row['postal_code'],
									),
									'strlen'
								)
							)
						);
					} else {
						$row[ 'mf:' . $tag ] = is_scalar( $value ) ? (string) $value : '';
					}
				}
			}

			$rows[] = $row;
		}

		$page_stats = $this->process_named_rows( $rows, $mapping, $options );

		$fetched_count = count( $result['members'] );
		$total         = isset( $result['total_items'] ) ? (int) $result['total_items'] : 0;
		$next_cursor   = $cursor + $fetched_count;

		// If the page returned fewer than requested, we have reached the end
		// even if total appears larger (e.g., race with concurrent unsubscribes).
		$done = ( $next_cursor >= $total ) || ( $fetched_count < $batch_size );

		return array(
			'page_stats'  => $page_stats,
			'next_cursor' => $next_cursor,
			'total'       => $total,
			'done'        => $done,
		);
	}

	/**
	 * Import members from Constant Contact via API.
	 *
	 * @param string $access_token CC access token.
	 * @param string $list_id      Contact list ID.
	 * @param array  $mapping      Column name => field name.
	 * @param array  $options      Import options.
	 * @return array|WP_Error Stats array.
	 */
	public function import_constant_contact( $access_token, $list_id, $mapping, $options = array() ) {
		if ( ! function_exists( 'npmp_cc_get_contacts' ) ) {
			return new WP_Error( 'npmp_cc_missing', __( 'Constant Contact API module is not loaded.', 'nonprofit-manager' ) );
		}

		$all_rows = array();
		$cursor   = null;

		do {
			$result = npmp_cc_get_contacts( $access_token, $list_id, $cursor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			foreach ( $result['contacts'] as $cc_contact ) {
				$email = '';
				if ( ! empty( $cc_contact['email_addresses'] ) ) {
					$email = $cc_contact['email_addresses'][0]['address'];
				}

				$row = array(
					'email_address' => $email,
					'first_name'    => isset( $cc_contact['first_name'] ) ? $cc_contact['first_name'] : '',
					'last_name'     => isset( $cc_contact['last_name'] ) ? $cc_contact['last_name'] : '',
					'phone'         => '',
					'tags'          => '',
				);

				// Phone numbers.
				if ( ! empty( $cc_contact['phone_numbers'] ) ) {
					$row['phone'] = $cc_contact['phone_numbers'][0]['phone_number'];
				}

				// Street address.
				if ( ! empty( $cc_contact['street_addresses'] ) ) {
					$addr = $cc_contact['street_addresses'][0];
					$row['address_line1'] = isset( $addr['street'] ) ? $addr['street'] : '';
					$row['city']          = isset( $addr['city'] ) ? $addr['city'] : '';
					$row['state']         = isset( $addr['state'] ) ? $addr['state'] : '';
					$row['postal_code']   = isset( $addr['postal_code'] ) ? $addr['postal_code'] : '';
					$row['country']       = isset( $addr['country'] ) ? $addr['country'] : '';
				}

				// Tags / lists.
				if ( ! empty( $cc_contact['taggings'] ) ) {
					$row['tags'] = implode( ',', $cc_contact['taggings'] );
				}

				$all_rows[] = $row;
			}

			$cursor = isset( $result['cursor'] ) ? $result['cursor'] : null;

		} while ( ! empty( $cursor ) );

		return $this->process_named_rows( $all_rows, $mapping, $options );
	}

	// ------------------------------------------------------------------
	// Preview helpers
	// ------------------------------------------------------------------

	/**
	 * Parse a file and return headers + preview rows.
	 *
	 * @param string $file_path Absolute path.
	 * @param string $type      csv|xlsx.
	 * @param int    $limit     Number of preview rows.
	 * @return array|WP_Error { headers: [], rows: [] }
	 */
	public function get_file_preview( $file_path, $type = 'csv', $limit = 5 ) {
		$rows = 'xlsx' === $type ? $this->parse_xlsx( $file_path ) : $this->parse_csv( $file_path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$headers      = ! empty( $rows ) ? array_shift( $rows ) : array();
		$preview_rows = array_slice( $rows, 0, $limit );

		return array(
			'headers'     => $headers,
			'rows'        => $preview_rows,
			'total_rows'  => count( $rows ),
			'auto_map'    => $this->detect_columns( $headers ),
		);
	}

	/**
	 * Auto-detect column mappings from header names.
	 *
	 * @param array $headers Array of header strings.
	 * @return array Column index => destination field (or empty string).
	 */
	public function detect_columns( $headers ) {
		$mapping = array();

		// Track which destination fields have been assigned to prevent duplicates.
		$assigned = array();

		foreach ( $headers as $index => $header ) {
			$normalised = strtolower( trim( $header ) );
			$normalised = preg_replace( '/[^a-z0-9 _\/]/', '', $normalised );
			$found      = '';

			foreach ( $this->header_synonyms as $field => $synonyms ) {
				if ( in_array( $normalised, $synonyms, true ) ) {
					$found = $field;
					break;
				}
			}

			// Handle first_name + last_name combination: map to 'first_name' / 'last_name'.
			if ( '' === $found ) {
				if ( in_array( $normalised, array( 'first name', 'first_name', 'fname', 'given name' ), true ) ) {
					$found = 'first_name';
				} elseif ( in_array( $normalised, array( 'last name', 'last_name', 'lname', 'surname', 'family name' ), true ) ) {
					$found = 'last_name';
				}
			}

			if ( '' !== $found && ! isset( $assigned[ $found ] ) ) {
				$mapping[ $index ] = $found;
				$assigned[ $found ] = true;
			} else {
				$mapping[ $index ] = '';
			}
		}

		return $mapping;
	}

	// ------------------------------------------------------------------
	// Row processing
	// ------------------------------------------------------------------

	/**
	 * Process index-based rows (CSV / XLSX).
	 *
	 * @param array $rows    Rows (arrays of cell values).
	 * @param array $mapping Column index => field name.
	 * @param array $options Import options.
	 * @return array Stats.
	 */
	public function process_rows( $rows, $mapping, $options = array() ) {
		$options    = $this->normalize_options( $options );
		$stats      = $this->empty_stats();
		$source_n   = count( $rows );
		$max        = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
		$capped     = ( $max < PHP_INT_MAX ) && ( $source_n > $max );
		$rows_to_do = $capped ? array_slice( $rows, 0, $max, true ) : $rows;

		foreach ( $rows_to_do as $row_number => $row ) {
			$record = $this->map_indexed_row( $row, $mapping );
			$result = $this->import_single_record( $record, $options, $row_number + 2 ); // +2 for 1-indexed + header offset.

			$this->tally_result( $stats, $result );
		}

		if ( $capped ) {
			$stats['cap_reached']  = true;
			$stats['cap_max_rows'] = $max;
			$stats['source_total'] = $source_n;
		}

		return $stats;
	}

	/**
	 * Process named-key rows (API sources).
	 *
	 * @param array $rows    Rows (associative arrays).
	 * @param array $mapping Source key => destination field.
	 * @param array $options Import options.
	 * @return array Stats.
	 */
	public function process_named_rows( $rows, $mapping, $options = array() ) {
		$options    = $this->normalize_options( $options );
		$stats      = $this->empty_stats();
		$source_n   = count( $rows );
		$max        = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
		// For named rows from a paginating API (Mailchimp) the per-page cap is
		// enforced at the page-fetch level in npmp_import_ajax_step. This guard
		// catches one-shot named-row callers (e.g., Constant Contact, future
		// providers that pull-all-then-process) so they also honor the cap.
		$capped     = ( $max < PHP_INT_MAX ) && ( $source_n > $max );
		$rows_to_do = $capped ? array_slice( $rows, 0, $max ) : $rows;

		foreach ( $rows_to_do as $row_number => $row ) {
			$record = $this->map_named_row( $row, $mapping );
			$result = $this->import_single_record( $record, $options, $row_number + 1 );

			$this->tally_result( $stats, $result );
		}

		if ( $capped ) {
			$stats['cap_reached']  = true;
			$stats['cap_max_rows'] = $max;
			$stats['source_total'] = $source_n;
		}

		return $stats;
	}

	/**
	 * Import a single record via NPMP_Member_Manager.
	 *
	 * @param array $record  Mapped member data.
	 * @param array $options Import options.
	 * @param int   $row_num Row number for error reporting.
	 * @return array { status: imported|skipped|updated|error, message?: string }
	 */
	private function import_single_record( $record, $options, $row_num ) {
		// Validate email.
		if ( empty( $record['email'] ) || ! is_email( $record['email'] ) ) {
			$email_display = ! empty( $record['email'] ) ? $record['email'] : '(empty)';
			return array(
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %1$d: row number, %2$s: invalid email value */
					__( 'Row %1$d: Invalid email format "%2$s"', 'nonprofit-manager' ),
					$row_num,
					$email_display
				),
			);
		}

		// Apply defaults.
		if ( ! empty( $options['default_level'] ) && empty( $record['membership_level'] ) ) {
			$record['membership_level'] = $options['default_level'];
		}
		if ( ! empty( $options['default_status'] ) && empty( $record['status'] ) ) {
			$record['status'] = $options['default_status'];
		}
		if ( ! empty( $options['source'] ) ) {
			$record['source'] = $options['source'];
		}

		// Check for existing member.
		$existing = $this->member_manager->get_member_by_email( $record['email'] );

		if ( $existing ) {
			switch ( $options['duplicate_handling'] ) {
				case 'update':
					$result = $this->member_manager->update_member( $existing->id, $record );
					if ( is_wp_error( $result ) ) {
						return array(
							'status'  => 'error',
							'message' => sprintf(
								/* translators: %1$d: row number, %2$s: error message */
								__( 'Row %1$d: Update failed — %2$s', 'nonprofit-manager' ),
								$row_num,
								$result->get_error_message()
							),
						);
					}
					return array( 'status' => 'updated' );

				case 'create_new':
					// Fall through to add_member below.
					break;

				case 'skip':
				default:
					return array( 'status' => 'skipped' );
			}
		}

		$result = $this->member_manager->add_member( $record );
		if ( is_wp_error( $result ) ) {
			return array(
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %1$d: row number, %2$s: error message */
					__( 'Row %1$d: %2$s', 'nonprofit-manager' ),
					$row_num,
					$result->get_error_message()
				),
			);
		}

		return array( 'status' => 'imported' );
	}

	// ------------------------------------------------------------------
	// File parsers
	// ------------------------------------------------------------------

	/**
	 * Parse a CSV file into an array of rows.
	 *
	 * @param string $file_path Absolute path.
	 * @return array|WP_Error
	 */
	private function parse_csv( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'npmp_csv_read', __( 'Cannot read the uploaded CSV file.', 'nonprofit-manager' ) );
		}

		$rows   = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'npmp_csv_open', __( 'Failed to open CSV file.', 'nonprofit-manager' ) );
		}

		// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$rows[] = array_map( 'trim', $row );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( empty( $rows ) ) {
			return new WP_Error( 'npmp_csv_empty', __( 'The CSV file is empty.', 'nonprofit-manager' ) );
		}

		return $rows;
	}

	/**
	 * Parse an XLSX file using ZipArchive + XML (zero external deps).
	 *
	 * XLSX is a ZIP containing XML files:
	 *   xl/sharedStrings.xml — string table
	 *   xl/worksheets/sheet1.xml — first sheet data
	 *
	 * @param string $file_path Absolute path.
	 * @return array|WP_Error Array of rows.
	 */
	private function parse_xlsx( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'npmp_xlsx_zip', __( 'ZipArchive PHP extension is required for XLSX imports.', 'nonprofit-manager' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'npmp_xlsx_open', __( 'Could not open the XLSX file. Is it a valid Excel file?', 'nonprofit-manager' ) );
		}

		// Parse shared strings.
		$strings     = array();
		$strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $strings_xml ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$sst = @simplexml_load_string( $strings_xml );
			if ( $sst ) {
				foreach ( $sst->si as $si ) {
					// Handle both simple <t> and rich-text <r><t> nodes.
					if ( isset( $si->t ) ) {
						$strings[] = (string) $si->t;
					} else {
						$text = '';
						foreach ( $si->r as $r ) {
							$text .= (string) $r->t;
						}
						$strings[] = $text;
					}
				}
			}
		}

		// Parse first worksheet.
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( false === $sheet_xml ) {
			return new WP_Error( 'npmp_xlsx_sheet', __( 'Could not find the first worksheet in the XLSX file.', 'nonprofit-manager' ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sheet = @simplexml_load_string( $sheet_xml );
		if ( ! $sheet ) {
			return new WP_Error( 'npmp_xlsx_parse', __( 'Failed to parse XLSX worksheet XML.', 'nonprofit-manager' ) );
		}

		$rows = array();
		if ( ! isset( $sheet->sheetData->row ) ) {
			return new WP_Error( 'npmp_xlsx_empty', __( 'The XLSX file contains no data.', 'nonprofit-manager' ) );
		}

		foreach ( $sheet->sheetData->row as $xml_row ) {
			$row_data  = array();
			$max_col   = 0;

			foreach ( $xml_row->c as $cell ) {
				$cell_ref = (string) $cell['r']; // e.g. "B3"
				$col_idx  = $this->xlsx_col_index( $cell_ref );
				$type     = isset( $cell['t'] ) ? (string) $cell['t'] : '';

				if ( 's' === $type ) {
					// Shared string reference.
					$idx       = (int) (string) $cell->v;
					$value     = isset( $strings[ $idx ] ) ? $strings[ $idx ] : '';
				} elseif ( 'inlineStr' === $type && isset( $cell->is->t ) ) {
					$value = (string) $cell->is->t;
				} else {
					$value = isset( $cell->v ) ? (string) $cell->v : '';
				}

				$row_data[ $col_idx ] = trim( $value );
				if ( $col_idx > $max_col ) {
					$max_col = $col_idx;
				}
			}

			// Fill gaps with empty strings.
			$filled_row = array();
			for ( $i = 0; $i <= $max_col; $i++ ) {
				$filled_row[] = isset( $row_data[ $i ] ) ? $row_data[ $i ] : '';
			}

			$rows[] = $filled_row;
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'npmp_xlsx_empty', __( 'The XLSX file is empty.', 'nonprofit-manager' ) );
		}

		return $rows;
	}

	/**
	 * Convert an XLSX cell reference like "AB3" to a 0-based column index.
	 *
	 * @param string $cell_ref Cell reference (e.g. "C5").
	 * @return int Column index.
	 */
	private function xlsx_col_index( $cell_ref ) {
		$letters = preg_replace( '/[0-9]/', '', $cell_ref );
		$index   = 0;
		$len     = strlen( $letters );

		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( strtoupper( $letters[ $i ] ) ) - 64 );
		}

		return $index - 1; // 0-based.
	}

	// ------------------------------------------------------------------
	// Mapping helpers
	// ------------------------------------------------------------------

	/**
	 * Map an indexed row array to member field keys.
	 *
	 * @param array $row     Indexed row.
	 * @param array $mapping Column index => field name.
	 * @return array Associative member data.
	 */
	private function map_indexed_row( $row, $mapping ) {
		$record     = array();
		$first_name = '';
		$last_name  = '';

		foreach ( $mapping as $col_index => $field ) {
			if ( '' === $field || 'skip' === $field ) {
				continue;
			}

			$value = isset( $row[ $col_index ] ) ? $row[ $col_index ] : '';

			if ( 'first_name' === $field ) {
				$first_name = $value;
				continue;
			}
			if ( 'last_name' === $field ) {
				$last_name = $value;
				continue;
			}

			$record[ $field ] = $value;
		}

		// Merge first + last into name if no direct 'name' mapping.
		if ( empty( $record['name'] ) && ( '' !== $first_name || '' !== $last_name ) ) {
			$record['name'] = trim( $first_name . ' ' . $last_name );
		}

		return $record;
	}

	/**
	 * Map a named-key row (from API sources) to member fields.
	 *
	 * @param array $row     Associative source row.
	 * @param array $mapping Source key => destination field.
	 * @return array Member data.
	 */
	private function map_named_row( $row, $mapping ) {
		$record     = array();
		$first_name = '';
		$last_name  = '';

		foreach ( $mapping as $source_key => $field ) {
			if ( '' === $field || 'skip' === $field ) {
				continue;
			}

			$value = isset( $row[ $source_key ] ) ? $row[ $source_key ] : '';

			if ( 'first_name' === $field ) {
				$first_name = $value;
				continue;
			}
			if ( 'last_name' === $field ) {
				$last_name = $value;
				continue;
			}

			$record[ $field ] = $value;
		}

		if ( empty( $record['name'] ) && ( '' !== $first_name || '' !== $last_name ) ) {
			$record['name'] = trim( $first_name . ' ' . $last_name );
		}

		return $record;
	}

	// ------------------------------------------------------------------
	// Internal utilities
	// ------------------------------------------------------------------

	/**
	 * Map a Mailchimp subscription status to an NPM status. Fail-closed:
	 * unknown values return an empty string so the import falls through to
	 * the user-selected default_status rather than silently treating an
	 * unrecognised state (a future Mailchimp status, a typo, or a hostile
	 * upstream value) as "subscribed".
	 *
	 * Mailchimp uses: subscribed, unsubscribed, pending, cleaned, archived,
	 * transactional. We collapse the "no longer reachable / opted out" buckets
	 * into NPM's `unsubscribed` so historical contacts come across with the
	 * right outreach state, and we preserve `pending` so double-opt-in
	 * invitees aren't silently promoted to subscribers on import.
	 *
	 * @param string $raw Mailchimp status string.
	 * @return string NPM status, or '' for unknown.
	 */
	private function map_mailchimp_status( $raw ) {
		$raw = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		$map = array(
			'subscribed'    => 'subscribed',
			'unsubscribed'  => 'unsubscribed',
			'cleaned'       => 'unsubscribed',
			'archived'      => 'unsubscribed',
			'pending'       => 'pending',
			'transactional' => 'unsubscribed',
		);
		return isset( $map[ $raw ] ) ? $map[ $raw ] : '';
	}

	/**
	 * Normalise import options with defaults.
	 *
	 * @param array $options Raw options.
	 * @return array
	 */
	private function normalize_options( $options ) {
		return wp_parse_args(
			$options,
			array(
				'duplicate_handling' => 'skip',
				'default_level'     => '',
				'default_status'    => 'subscribed',
				'source'            => '',
			)
		);
	}

	/**
	 * Empty stats structure.
	 *
	 * @return array
	 */
	private function empty_stats() {
		return array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'error_messages' => array(),
		);
	}

	/**
	 * Tally a single-record result into the stats array.
	 *
	 * @param array $stats  Reference to stats.
	 * @param array $result Record result.
	 */
	private function tally_result( &$stats, $result ) {
		switch ( $result['status'] ) {
			case 'imported':
				$stats['imported']++;
				break;
			case 'updated':
				$stats['updated']++;
				break;
			case 'skipped':
				$stats['skipped']++;
				break;
			case 'error':
				$stats['errors']++;
				if ( ! empty( $result['message'] ) ) {
					$stats['error_messages'][] = $result['message'];
				}
				break;
		}
	}
}
