<?php
// includes/payments/npmp-paypal.php
defined( 'ABSPATH' ) || exit;

/* =======================================================================
 * Donation Manager
 * =======================================================================*/
class NPMP_Donation_Manager {
	private static $instance = null;

	public static function get_instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	/* ---------- Log a donation ---------- */
	public function log_donation( $data ) {
		global $wpdb;
		return $wpdb->insert(
			$wpdb->prefix . 'npmp_donations',
			array(
				'email'      => $data['email'],
				'name'       => $data['name'] ?? '',
				'amount'     => $data['amount'],
				'frequency'  => $data['frequency'] ?? 'one_time',
				'gateway'    => $data['gateway'] ?? 'paypal',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s', '%s', '%s' )
		);
	}

	/* ---------- Full list ---------- */
	public function get_all_donations() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'npmp_donations ORDER BY created_at DESC' );
	}

	/* ---------- For summary table ---------- */
	public function years_with_donations() {
		global $wpdb;
		$rows = $wpdb->get_col( 'SELECT DISTINCT YEAR(created_at) FROM ' . $wpdb->prefix . 'npmp_donations ORDER BY YEAR(created_at) DESC' );
		return $rows ?: array( date( 'Y' ) );
	}

	public function summary( $year, $month = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'npmp_donations';

		if ( $month ) {
			// breakdown by day in selected month
			$sql = $wpdb->prepare( "
				SELECT DATE(created_at) AS period, COUNT(*) AS cnt, SUM(amount) AS total
				FROM $table
				WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d
				GROUP BY DATE(created_at)
				ORDER BY DATE(created_at) DESC", $year, $month );
		} else {
			// breakdown by month in selected year
			$sql = $wpdb->prepare( "
				SELECT DATE_FORMAT(created_at,'%%Y‑%%m') AS period, COUNT(*) AS cnt, SUM(amount) AS total
				FROM $table
				WHERE YEAR(created_at) = %d
				GROUP BY DATE_FORMAT(created_at,'%%Y‑%%m')
				ORDER BY period DESC", $year );
		}

		$rows = $wpdb->get_results( $sql );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'period' => $month ? date_i18n( 'M j, Y', strtotime( $r->period ) )
								   : date_i18n( 'F Y',    strtotime( $r->period . '-01' ) ),
				'count'  => (int) $r->cnt,
				'total'  => (float) $r->total,
			);
		}
		return $out;
	}
}

/* =======================================================================
 *  PayPal integration (loaded only if gateway is enabled)
 * =======================================================================*/
if ( get_option( 'npmp_enable_paypal' ) ) {

	/* ---------- donation form shortcode ---------- */
	add_shortcode( 'npmp_donation_form', function () {

		ob_start();

		$opts = array(
			'method'      => get_option( 'npmp_paypal_method', 'sdk' ),
			'mode'        => get_option( 'npmp_paypal_mode', 'live' ),
			'email_link'  => sanitize_email( get_option( 'npmp_paypal_email' ) ),
			'client_id'   => esc_attr( get_option( 'npmp_paypal_client_id' ) ),
			'title'       => get_option( 'npmp_donation_form_title', 'Support Our Mission' ),
			'intro'       => get_option( 'npmp_donation_form_intro', 'Your contribution helps us make a difference.' ),
			'amount_lbl'  => get_option( 'npmp_donation_amount_label', 'Donation Amount' ),
			'email_lbl'   => get_option( 'npmp_donation_email_label', 'Your Email' ),
			'btn_lbl'     => get_option( 'npmp_donation_button_label', 'Donate Now' ),
			'min_amount'  => floatval( get_option( 'npmp_paypal_minimum', 1 ) ),
		);

		$freqs = array();
		if ( get_option( 'npmp_enable_one_time' ) ) $freqs['one_time'] = __( 'One‑Time', 'nonprofit-manager' );
		if ( get_option( 'npmp_enable_monthly' ) )  $freqs['monthly']  = __( 'Monthly',  'nonprofit-manager' );
		if ( get_option( 'npmp_enable_annual' ) )   $freqs['annual']   = __( 'Annual',   'nonprofit-manager' );

		/* success banner (from PayPal redirect) */
		if ( isset( $_GET['paypal_success'], $_GET['_wpnonce'] ) &&
			 wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'npmp_paypal_success' ) &&
			 '1' === $_GET['paypal_success']
		) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Thank you for your donation!', 'nonprofit-manager' ) . '</p></div>';
		}

		echo '<div class="npmp-donation-form" style="max-width:500px;">';
		echo '<h3>' . esc_html( $opts['title'] ) . '</h3>';
		echo '<p>' . esc_html( $opts['intro'] ) . '</p>';
		echo '<form id="npmp-paypal-form">';

		echo '<p><label>' . esc_html( $opts['amount_lbl'] ) . '<br><input type="number" step="0.01" min="' . esc_attr( $opts['min_amount'] ) . '" name="amount" required style="width:100%;"></label></p>';
		echo '<p><label>' . esc_html( $opts['email_lbl'] ) . '<br><input type="email" name="email" required style="width:100%;"></label></p>';

		if ( 'sdk' === $opts['method'] && $freqs ) {
			echo '<p><label>' . esc_html__( 'Frequency', 'nonprofit-manager' ) . '<br><select name="frequency" required style="width:100%;">';
			foreach ( $freqs as $val => $lab ) {
				echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $lab ) . '</option>';
			}
			echo '</select></label></p>';
		}

		wp_nonce_field( 'npmp_paypal_donation_nonce', 'npmp_paypal_donation_nonce_field' );
		echo '<input type="hidden" id="npmp_paypal_success_nonce" value="' . esc_attr( wp_create_nonce( 'npmp_paypal_success' ) ) . '">';

		if ( 'email' === $opts['method'] && $opts['email_link'] ) {
			echo '<input type="hidden" id="npmp-paypal-business" value="' . esc_attr( $opts['email_link'] ) . '">';
			echo '<p style="margin-top:20px;"><a class="button button-primary" style="width:100%;" href="#" onclick="return npmpRedirectToPayPal(this)">' . esc_html( $opts['btn_lbl'] ) . '</a></p>';
		}

		if ( 'sdk' === $opts['method'] && $opts['client_id'] ) {
			echo '<div id="paypal-button-container" style="margin-top:20px;"></div>';
		}

		echo '</form></div>';
		return ob_get_clean();
	} );

	/* ---------- AJAX logger ---------- */
	add_action( 'wp_ajax_npmp_log_paypal_donation',        'npmp_handle_paypal_donation' );
	add_action( 'wp_ajax_nopriv_npmp_log_paypal_donation', 'npmp_handle_paypal_donation' );

	function npmp_handle_paypal_donation() {
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['npmp_paypal_donation_nonce_field'] ?? '' ), 'npmp_paypal_donation_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ) );
		}

		$email     = sanitize_email( $_POST['email'] ?? '' );
		$amount    = floatval( $_POST['amount'] ?? 0 );
		$frequency = sanitize_text_field( $_POST['frequency'] ?? 'one_time' );

		if ( $email && $amount > 0 ) {
			NPMP_Donation_Manager::get_instance()->log_donation(
				array(
					'email'     => $email,
					'amount'    => $amount,
					'frequency' => $frequency,
					'gateway'   => 'paypal',
				)
			);
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => 'invalid_payload' ) );
	}

	/* ---------- PayPal settings section ---------- */
	add_action( 'npmp_render_paypal_settings_section', function () {
		$method = get_option( 'npmp_paypal_method', 'sdk' );
		$mode   = get_option( 'npmp_paypal_mode', 'live' );

		echo '<div><h3>' . esc_html__( 'PayPal Settings', 'nonprofit-manager' ) . '</h3><table class="form-table">';

		echo '<tr><th>' . esc_html__( 'Integration Method', 'nonprofit-manager' ) . '</th><td>';
		echo '<label><input type="radio" name="npmp_paypal_method" value="email" ' . checked( $method, 'email', false ) . '> ' . esc_html__( 'Email Link', 'nonprofit-manager' ) . '</label><br>';
		echo '<label><input type="radio" name="npmp_paypal_method" value="sdk" ' . checked( $method, 'sdk', false ) . '> ' . esc_html__( 'Smart Button', 'nonprofit-manager' ) . '</label>';
		echo '</td></tr>';

		echo '<tr data-method="email"><th>' . esc_html__( 'PayPal Email', 'nonprofit-manager' ) . '</th><td><input type="email" name="npmp_paypal_email" value="' . esc_attr( get_option( 'npmp_paypal_email' ) ) . '" class="regular-text"></td></tr>';

		echo '<tr data-method="sdk"><th>Client ID</th><td><input type="text" name="npmp_paypal_client_id" value="' . esc_attr( get_option( 'npmp_paypal_client_id' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr data-method="sdk"><th>Secret</th><td><input type="text" name="npmp_paypal_secret" value="' . esc_attr( get_option( 'npmp_paypal_secret' ) ) . '" class="regular-text"></td></tr>';

		echo '<tr data-method="sdk"><th>' . esc_html__( 'Environment', 'nonprofit-manager' ) . '</th><td>';
		echo '<label><input type="radio" name="npmp_paypal_mode" value="live" ' . checked( $mode, 'live', false ) . '> Live&nbsp;</label>';
		echo '<label><input type="radio" name="npmp_paypal_mode" value="sandbox" ' . checked( $mode, 'sandbox', false ) . '> Sandbox</label></td></tr>';

		echo '<tr><th>' . esc_html__( 'Minimum Amount', 'nonprofit-manager' ) . '</th><td><input type="number" step="0.01" name="npmp_paypal_minimum" value="' . esc_attr( get_option( 'npmp_paypal_minimum', 1 ) ) . '" class="small-text"> USD</td></tr>';
		echo '</table></div>';
	} );
}
