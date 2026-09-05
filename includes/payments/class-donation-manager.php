<?php
/**
 * Donation manager service layer.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Centralised donation persistence helper.
 */
class NPMP_Donation_Manager {

	const POST_TYPE      = 'npmp_donation';
	const META_EMAIL     = '_npmp_donation_email';
	const META_AMOUNT    = '_npmp_donation_amount';
	const META_FREQUENCY = '_npmp_donation_frequency';
	const META_GATEWAY   = '_npmp_donation_gateway';
	const META_TXN_ID    = '_npmp_donation_txn_id';

	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return NPMP_Donation_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Log a donation to the DB.
	 *
	 * @param array $data {
	 *     @type string $email     Donor email.
	 *     @type string $name      Donor name.
	 *     @type float  $amount    Donation amount.
	 *     @type string $frequency Donation frequency.
	 *     @type string $gateway   Donation gateway.
	 * }
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log_donation( $data ) {
		$email     = sanitize_email( $data['email'] ?? '' );
		$name      = sanitize_text_field( $data['name'] ?? '' );
		$amount    = floatval( $data['amount'] ?? 0 );
		$frequency = sanitize_text_field( $data['frequency'] ?? 'one_time' );
		$gateway   = sanitize_text_field( $data['gateway'] ?? 'paypal' );
		$txn_id    = sanitize_text_field( $data['transaction_id'] ?? '' );

		$legacy_id  = isset( $data['legacy_id'] ) ? absint( $data['legacy_id'] ) : 0;
		$created_at = isset( $data['created_at'] ) ? strtotime( $data['created_at'] ) : false;

		if ( ! $email || $amount <= 0 ) {
			return false;
		}

		// A gateway transaction id makes the write idempotent: a replayed
		// AJAX call or a refreshed success page can't record the same
		// payment twice.
		if ( $txn_id ) {
			$existing = $this->find_by_transaction_id( $txn_id );
			if ( $existing ) {
				return $existing;
			}
		}

		if ( false === $created_at ) {
			$created_at = current_time( 'timestamp' );
		}

		$post_date      = date_i18n( 'Y-m-d H:i:s', $created_at );
		$post_date_gmt  = get_gmt_from_date( $post_date );
		$meta_input     = array(
			self::META_EMAIL     => $email,
			self::META_AMOUNT    => $amount,
			self::META_FREQUENCY => $frequency,
			self::META_GATEWAY   => $gateway,
		);
		if ( $txn_id ) {
			$meta_input[ self::META_TXN_ID ] = $txn_id;
		}
		if ( $legacy_id ) {
			$meta_input['_npmp_legacy_donation_id'] = $legacy_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				/* translators: 1: Donor name, 2: Donor email address. */
				'post_title'  => $name ? sprintf( __( '%1$s (%2$s)', 'nonprofit-manager' ), $name, $email ) : $email,
				'post_date'   => $post_date,
				'post_date_gmt' => $post_date_gmt,
				'meta_input'  => $meta_input,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		if ( class_exists( 'NPMP_Member_Manager' ) ) {
			NPMP_Member_Manager::get_instance()->record_donation(
				array(
					'donation_id' => $post_id,
					'email'       => $email,
					'name'        => $name,
					'amount'      => $amount,
					'frequency'   => $frequency,
					'gateway'     => $gateway,
					'created_at'  => current_time( 'mysql' ),
				)
			);
		}

		// First recorded donation is a real usage milestone. Enables the
		// one-time review nudge on the next admin visit.
		if ( function_exists( 'npmp_mark_milestone' ) ) {
			npmp_mark_milestone( 'donation' );
		}

		/**
		 * Fires after a donation is recorded.
		 *
		 * Pro's automation engine listens here to run donation_received
		 * automations (welcome sequences, receipts). The listener existed
		 * for a while with nothing firing the hook, so donation automations
		 * silently never ran.
		 *
		 * @param int   $post_id Donation post ID.
		 * @param array $data    Donation fields (email, name, amount, frequency, gateway).
		 */
		do_action(
			'npmp_donation_recorded',
			$post_id,
			array(
				'email'     => $email,
				'name'      => $name,
				'amount'    => $amount,
				'frequency' => $frequency,
				'gateway'   => $gateway,
			)
		);

		return $post_id;
	}

	/**
	 * Find a donation by its gateway transaction id.
	 *
	 * @param string $txn_id Gateway transaction/session/order id.
	 * @return int Donation post ID, or 0 when none exists.
	 */
	public function find_by_transaction_id( $txn_id ) {
		$txn_id = sanitize_text_field( $txn_id );
		if ( ! $txn_id ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact-match lookup on a dedupe key.
				'meta_query'     => array(
					array(
						'key'   => self::META_TXN_ID,
						'value' => $txn_id,
					),
				),
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Retrieve all donations, newest first.
	 *
	 * @return array List of donation records.
	 */
	public function get_all_donations() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Get all years in which donations exist.
	 *
	 * @return array List of years (int).
	 */
	public function years_with_donations() {
		global $wpdb;

		// This drives a year dropdown, so the answer is a handful of numbers.
		// It used to fetch every donation ID ever recorded and call
		// get_the_date() on each one, and because 'fields' => 'ids' skips
		// meta and post cache priming, each of those calls could be its own
		// query. On a charity with years of history that was the single most
		// expensive thing on the Donations screen.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small DISTINCT aggregate; there is no row set worth caching.
		$years = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT YEAR(post_date) AS y
				 FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish'
				 ORDER BY y DESC",
				self::POST_TYPE
			)
		);

		$years = array_values( array_filter( array_map( 'intval', (array) $years ) ) );

		return $years ?: array( intval( gmdate( 'Y' ) ) );
	}

	/**
	 * Summary counts and totals by day or by month.
	 *
	 * @param int      $year  Four-digit year.
	 * @param int|null $month Optional 1-12 month.
	 * @return array List of [ 'period' => string, 'count' => int, 'total' => float ].
	 */
	public function summary( $year, $month = null ) {
		global $wpdb;

		$year  = absint( $year );
		$month = $month ? absint( $month ) : null;

		// Group and total in the database rather than loading every donation
		// in the period and adding them up a row at a time. The grouping is
		// deliberately kept as it was: the period is cut on post_date_gmt while
		// the year/month filter reads post_date, which is what the date_query
		// this replaces did.
		// The percent signs are doubled because this string goes through
		// $wpdb->prepare(), which reads % as the start of a placeholder. Left
		// single, the %d in the day format is eaten as an integer placeholder,
		// the parameters shift, and the query silently returns nothing.
		$period_expr = $month
			? "DATE_FORMAT(p.post_date_gmt, '%%Y-%%m-%%d')"
			: "DATE_FORMAT(p.post_date_gmt, '%%Y-%%m')";

		$sql = "SELECT {$period_expr} AS period_key,
		               COUNT(*) AS donation_count,
		               SUM(a.meta_value + 0) AS total_amount,
		               MAX(p.post_date_gmt) AS latest
		        FROM {$wpdb->posts} p
		        INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
		        WHERE p.post_type = %s
		          AND p.post_status = 'publish'
		          AND YEAR(p.post_date) = %d
		          AND (a.meta_value + 0) > 0";

		$params = array( self::META_AMOUNT, self::POST_TYPE, $year );

		if ( $month ) {
			$sql     .= ' AND MONTH(p.post_date) = %d';
			$params[] = $month;
		}

		$sql .= ' GROUP BY period_key';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Built from a fixed template; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$output = array();

		foreach ( $rows as $row ) {
			$period = (string) $row['period_key'];

			$output[] = array(
				'period'    => $month
					? date_i18n( 'M j, Y', strtotime( $period ) )
					: date_i18n( 'F Y', strtotime( $period . '-01' ) ),
				'count'     => (int) $row['donation_count'],
				'total'     => (float) $row['total_amount'],
				'timestamp' => (int) strtotime( (string) $row['latest'] ),
			);
		}

		usort(
			$output,
			static function ( $a, $b ) {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		return array_map(
			static function ( $row ) {
				unset( $row['timestamp'] );
				return $row;
			},
			$output
		);
	}

	/**
	 * Retrieve donation aggregate info for an email address.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public function get_totals_for_email( $email ) {
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return array(
				'count' => 0,
				'total' => 0,
				'last'  => '',
			);
		}

		global $wpdb;

		// Three numbers about one donor. Loading every donation they ever made
		// to add them up in PHP is work the database already does in one pass.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single aggregate row; nothing to cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS donation_count,
				        SUM(a.meta_value + 0) AS total_amount,
				        MAX(p.post_date_gmt) AS last_at
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND e.meta_value = %s",
				self::META_EMAIL,
				self::META_AMOUNT,
				self::POST_TYPE,
				$email
			),
			ARRAY_A
		);

		return array(
			'count' => isset( $row['donation_count'] ) ? (int) $row['donation_count'] : 0,
			'total' => isset( $row['total_amount'] ) ? (float) $row['total_amount'] : 0.0,
			// Matches the previous behaviour: the most recent donation's GMT
			// time, and an empty string when this donor has none.
			'last'  => ! empty( $row['last_at'] ) ? (string) $row['last_at'] : '',
		);
	}

	/**
	 * Persist a gateway payment-verification record for a donation.
	 *
	 * Server-side verification (see npmp_paypal_verify_order()) fetches the
	 * gateway's own record of the transaction before a donation is trusted,
	 * but until now that response was discarded once the check passed. This
	 * keeps it (capture id, verified status, and the raw gateway response)
	 * for later reconciliation ("the donor says they paid but there's no
	 * record") and dispute/chargeback handling.
	 *
	 * @param array $data {
	 *     @type int    $donation_id        Donation post ID this verification belongs to.
	 *     @type string $gateway            Gateway slug, e.g. 'paypal_api'.
	 *     @type string $gateway_order_id   Gateway order/session id as reported by the client.
	 *     @type string $gateway_capture_id Gateway capture id, when the API response includes one.
	 *     @type string $status             Gateway-reported status (e.g. 'COMPLETED'), or 'unverified'.
	 *     @type bool   $verified           Whether server-side verification actually ran and passed.
	 *     @type array|null $raw_response   Full decoded gateway API response, when verification ran.
	 * }
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function log_payment_verification( $data ) {
		global $wpdb;

		$donation_id = absint( $data['donation_id'] ?? 0 );
		if ( ! $donation_id ) {
			return false;
		}

		$raw_response = $data['raw_response'] ?? null;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit table, no caching layer needed for a write.
			$wpdb->prefix . 'npmp_payment_log',
			array(
				'donation_id'        => $donation_id,
				'gateway'            => sanitize_text_field( $data['gateway'] ?? '' ),
				'gateway_order_id'   => sanitize_text_field( $data['gateway_order_id'] ?? '' ),
				'gateway_capture_id' => sanitize_text_field( $data['gateway_capture_id'] ?? '' ),
				'status'             => sanitize_text_field( $data['status'] ?? '' ),
				'verified'           => empty( $data['verified'] ) ? 0 : 1,
				'raw_response'       => is_array( $raw_response ) ? wp_json_encode( $raw_response ) : null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}
}
