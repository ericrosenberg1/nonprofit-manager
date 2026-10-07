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
	// ISO 4217 code the gift was taken in. Donations recorded before currency
	// support have no value here and are US dollars (npmp_record_currency()).
	const META_CURRENCY  = '_npmp_donation_currency';

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
	 *     @type string $currency  ISO 4217 code the gift was taken in. Defaults to
	 *                             the site currency. Gateway handlers pass the
	 *                             currency the gateway reports, never the setting.
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
		$currency  = npmp_normalize_currency_code( $data['currency'] ?? '' );
		if ( '' === $currency ) {
			$currency = npmp_currency();
		}

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
			self::META_CURRENCY  => $currency,
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

		// The dashboards read the list of currencies in use from a cache. A
		// currency it hasn't seen yet means the list changed.
		$known = get_option( 'npmp_donation_currencies_cache', false );
		if ( ! is_array( $known ) || ! in_array( $currency, $known, true ) ) {
			npmp_flush_donation_currencies();
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
					'currency'    => $currency,
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
		 * @param array $data    Donation fields (email, name, amount, frequency, gateway, currency).
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
				'currency'  => $currency,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small DISTINCT aggregate. There is no row set worth caching.
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
	 * Retrieve donation aggregate info for an email address.
	 *
	 * Totals are kept per currency and never added across currencies.
	 * 'total' and 'currency' are the donor's single currency when they only
	 * ever gave in one (every donor on a site that never changed currency).
	 * For a donor with gifts in several, they are the site currency's share,
	 * and 'totals' carries every currency.
	 *
	 * @param string $email Email address.
	 * @return array{count:int,total:float,currency:string,totals:array<string,float>,last:string}
	 */
	public function get_totals_for_email( $email ) {
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return array(
				'count'    => 0,
				'total'    => 0,
				'currency' => npmp_currency(),
				'totals'   => array(),
				'last'     => '',
			);
		}

		global $wpdb;

		// One grouped pass instead of loading every donation this donor made.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A row per currency, nothing to cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE( c.meta_value, '' ) AS currency,
				        COUNT(*) AS donation_count,
				        SUM(a.meta_value + 0) AS total_amount,
				        MAX(p.post_date_gmt) AS last_at
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND e.meta_value = %s
				 GROUP BY COALESCE( c.meta_value, '' )",
				self::META_EMAIL,
				self::META_AMOUNT,
				self::META_CURRENCY,
				self::POST_TYPE,
				$email
			),
			ARRAY_A
		);

		$count = 0;
		$last  = '';
		foreach ( (array) $rows as $row ) {
			$count += (int) $row['donation_count'];
			if ( ! empty( $row['last_at'] ) && (string) $row['last_at'] > $last ) {
				$last = (string) $row['last_at'];
			}
		}

		$totals   = npmp_group_totals_by_currency( (array) $rows, 'total_amount' );
		$currency = 1 === count( $totals ) ? (string) array_key_first( $totals ) : npmp_currency();

		return array(
			'count'    => $count,
			'total'    => (float) ( $totals[ $currency ] ?? 0.0 ),
			'currency' => $currency,
			'totals'   => $totals,
			// Matches the previous behaviour: the most recent donation's GMT
			// time, and an empty string when this donor has none.
			'last'     => $last,
		);
	}

	/**
	 * Currency a stored donation was taken in.
	 *
	 * @param int $donation_id Donation post ID.
	 * @return string ISO code, USD for a donation recorded before currency support.
	 */
	public function get_donation_currency( $donation_id ) {
		return npmp_record_currency( get_post_meta( (int) $donation_id, self::META_CURRENCY, true ) );
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
