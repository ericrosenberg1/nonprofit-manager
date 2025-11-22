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

		$legacy_id  = isset( $data['legacy_id'] ) ? absint( $data['legacy_id'] ) : 0;
		$created_at = isset( $data['created_at'] ) ? strtotime( $data['created_at'] ) : false;

		if ( ! $email || $amount <= 0 ) {
			return false;
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

		return $post_id;
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
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$years = array();

		foreach ( $ids as $post_id ) {
			$year = (int) get_the_date( 'Y', $post_id );
			if ( $year ) {
				$years[] = $year;
			}
		}

		$years = array_values( array_unique( $years ) );
		rsort( $years, SORT_NUMERIC );

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
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array(
				array(
					'year'     => absint( $year ),
					'monthnum' => $month ? absint( $month ) : null,
				),
			),
		);

		if ( ! $month ) {
			unset( $args['date_query'][0]['monthnum'] );
		}

		$ids     = get_posts( $args );
		$summary = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$timestamp = strtotime( $post->post_date_gmt );
			$amount    = (float) get_post_meta( $post_id, self::META_AMOUNT, true );
			if ( $amount <= 0 ) {
				continue;
			}

			$key = $month ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m', $timestamp );

			if ( ! isset( $summary[ $key ] ) ) {
				$summary[ $key ] = array(
					'count'     => 0,
					'total'     => 0.0,
					'timestamp' => $timestamp,
				);
			}

			$summary[ $key ]['count'] ++;
			$summary[ $key ]['total'] += $amount;
			$summary[ $key ]['timestamp'] = max( $summary[ $key ]['timestamp'], $timestamp );
		}

		$output = array();

		foreach ( $summary as $period => $data ) {
			$output[] = array(
				'period'    => $month
					? date_i18n( 'M j, Y', strtotime( $period ) )
					: date_i18n( 'F Y', strtotime( $period . '-01' ) ),
				'count'     => (int) $data['count'],
				'total'     => (float) $data['total'],
				'timestamp' => (int) $data['timestamp'],
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

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'date',
					'order'          => 'DESC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Donations are stored in post meta; filtering by email requires a meta query.
					'meta_query'     => array(
						array(
							'key'   => self::META_EMAIL,
							'value' => $email,
						),
					),
			)
		);

		$total_amount = 0.0;
		$last_at      = '';

		foreach ( $query->posts as $post_id ) {
			$total_amount += (float) get_post_meta( $post_id, self::META_AMOUNT, true );
			if ( ! $last_at ) {
				$last_at = get_post_time( 'Y-m-d H:i:s', true, $post_id );
			}
		}

		return array(
			'count' => (int) count( $query->posts ),
			'total' => (float) $total_amount,
			'last'  => $last_at,
		);
	}
}
