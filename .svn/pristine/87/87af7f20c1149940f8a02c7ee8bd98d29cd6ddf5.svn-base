<?php
// includes/payments/npmp-paypal.php

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'class-donation-manager.php';

/* =======================================================================
 *  PayPal integration (loaded only if gateway is enabled)
 * =======================================================================*/

if ( get_option( 'npmp_enable_paypal' ) ) {

	remove_shortcode( 'npmp_donation_form' );

	/**
	 * Render the PayPal donation form markup.
	 *
	 * @return string
	 */
	function npmp_render_paypal_donation_form() {

		ob_start();

		$opts = array(
			'method'      => get_option( 'npmp_paypal_method', 'sdk' ),
			'mode'        => get_option( 'npmp_paypal_mode', 'live' ),
			'email_link'  => sanitize_email( get_option( 'npmp_paypal_email' ) ),
			'client_id'   => sanitize_text_field( 'sandbox' === get_option( 'npmp_paypal_mode', 'live' ) ? get_option( 'npmp_paypal_sandbox_client_id', '' ) : get_option( 'npmp_paypal_live_client_id', '' ) ),
			'title'       => sanitize_text_field( get_option( 'npmp_donation_form_title', 'Support Our Mission' ) ),
			'intro'       => wp_kses_post( get_option( 'npmp_donation_form_intro', 'Your contribution helps us make a difference.' ) ),
			'amount_lbl'  => sanitize_text_field( get_option( 'npmp_donation_amount_label', 'Donation Amount' ) ),
			'email_lbl'   => sanitize_text_field( get_option( 'npmp_donation_email_label', 'Your Email' ) ),
			'btn_lbl'     => sanitize_text_field( get_option( 'npmp_donation_button_label', 'Donate Now' ) ),
			'min_amount'  => floatval( get_option( 'npmp_paypal_minimum', 1 ) ),
		);

		$freqs = array();
		if ( get_option( 'npmp_enable_one_time' ) ) {
			$freqs['one_time'] = __( 'One-Time', 'nonprofit-manager' );
		}
		if ( get_option( 'npmp_enable_monthly' ) ) {
			$freqs['monthly'] = __( 'Monthly', 'nonprofit-manager' );
		}
		if ( get_option( 'npmp_enable_annual' ) ) {
			$freqs['annual'] = __( 'Annual', 'nonprofit-manager' );
		}

		// Success banner (from PayPal redirect).
		if ( isset( $_GET['paypal_success'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'npmp_paypal_success' )
			&& '1' === sanitize_text_field( wp_unslash( $_GET['paypal_success'] ) )
		) {
			echo '<div class="notice notice-success"><p>'
				. esc_html__( 'Thank you for your donation!', 'nonprofit-manager' )
				. '</p></div>';
		}

		echo '<div class="npmp-donation-form" style="max-width:500px;">';
		echo '<h3>' . esc_html( $opts['title'] ) . '</h3>';
		echo '<p>'  . wp_kses_post( $opts['intro'] ) . '</p>';
		echo '<form id="npmp-paypal-form">';

		// Amount & Email fields.
		echo '<p><label>' . esc_html( $opts['amount_lbl'] ) . '<br>'
			. '<input type="number" step="0.01" min="' . esc_attr( $opts['min_amount'] ) . '" name="amount" id="np-donation-amount" required style="width:100%;"></label></p>';

		echo '<p><label>' . esc_html( $opts['email_lbl'] ) . '<br>'
			. '<input type="email" name="email" id="np-donation-email" required style="width:100%;"></label></p>';

		// Frequency select for SDK.
		if ( 'sdk' === $opts['method'] && $freqs ) {
			echo '<p><label>' . esc_html__( 'Frequency', 'nonprofit-manager' ) . '<br>'
				. '<select name="frequency" id="np-donation-frequency" required style="width:100%;">';
			foreach ( $freqs as $val => $lab ) {
				echo '<option value="' . esc_attr( $val ) . '">' . esc_html( $lab ) . '</option>';
			}
			echo '</select></label></p>';
		}

		// Nonce fields.
		wp_nonce_field( 'npmp_paypal_donation_nonce', 'npmp_paypal_donation_nonce_field' );
		echo '<input type="hidden" id="npmp_paypal_success_nonce" value="' . esc_attr( wp_create_nonce( 'npmp_paypal_success' ) ) . '">';

		// Email-link method.
		if ( 'email' === $opts['method'] && $opts['email_link'] ) {
			echo '<input type="hidden" id="npmp-paypal-business" value="' . esc_attr( $opts['email_link'] ) . '">';
			echo '<p style="margin-top:20px;"><a class="button button-primary" style="width:100%;" href="#" onclick="return npmpRedirectToPayPal(this)">'
				. esc_html( $opts['btn_lbl'] )
				. '</a></p>';
		}

		// SDK button container.
		if ( 'sdk' === $opts['method'] && $opts['client_id'] ) {
			echo '<div id="paypal-button-container" style="margin-top:20px;"></div>';
		}

		echo '</form></div>';

		return ob_get_clean();
	}

	add_shortcode( 'npmp_donation_form', 'npmp_render_paypal_donation_form' );

	/**
	 * AJAX handler: record PayPal donation via JS.
	 */
	add_action( 'wp_ajax_npmp_log_paypal_donation',        'npmp_handle_paypal_donation' );
	add_action( 'wp_ajax_nopriv_npmp_log_paypal_donation', 'npmp_handle_paypal_donation' );

	function npmp_handle_paypal_donation() {
		// Verify nonce
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['npmp_paypal_donation_nonce_field'] ?? '' ) ),
			'npmp_paypal_donation_nonce'
		) ) {
			wp_send_json_error( array( 'message' => 'bad_nonce' ) );
		}

		$email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$amount    = floatval( wp_unslash( $_POST['amount'] ?? 0 ) );
		$frequency = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'one_time' ) );

		if ( $email && $amount > 0 ) {
			NPMP_Donation_Manager::get_instance()->log_donation( compact( 'email', 'amount', 'frequency' ) );
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => 'invalid_payload' ) );
	}

	/**
	 * Render the PayPal settings in your settings page.
	 */
	add_action( 'npmp_render_paypal_settings_section', function() {
		$method = get_option( 'npmp_paypal_method', 'sdk' );
		$mode   = get_option( 'npmp_paypal_mode',   'live' );

		echo '<div><h3>' . esc_html__( 'PayPal Settings', 'nonprofit-manager' ) . '</h3><table class="form-table">';

		// Integration method
		echo '<tr><th>' . esc_html__( 'Integration Method', 'nonprofit-manager' ) . '</th><td>';
		echo '<label><input type="radio" name="npmp_paypal_method" value="email" '
			. checked( $method, 'email', false ) . '> ' . esc_html__( 'Email Link', 'nonprofit-manager' ) . '</label><br>';
		echo '<label><input type="radio" name="npmp_paypal_method" value="sdk" '
			. checked( $method, 'sdk', false ) . '> ' . esc_html__( 'Smart Button', 'nonprofit-manager' ) . '</label>';
		echo '</td></tr>';

		// Email link field
		echo '<tr data-method="email"><th>' . esc_html__( 'PayPal Email', 'nonprofit-manager' ) . '</th><td>'
			. '<input type="email" name="npmp_paypal_email" value="' . esc_attr( get_option( 'npmp_paypal_email' ) ) . '" class="regular-text"></td></tr>';

		// SDK fields
		echo '<tr data-method="sdk"><th>' . esc_html__( 'Client ID', 'nonprofit-manager' ) . '</th><td>'
			. '<input type="text" name="npmp_paypal_client_id" value="' . esc_attr( get_option( 'npmp_paypal_client_id' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr data-method="sdk"><th>' . esc_html__( 'Secret', 'nonprofit-manager' ) . '</th><td>'
			. '<input type="text" name="npmp_paypal_secret" value="' . esc_attr( get_option( 'npmp_paypal_secret' ) ) . '" class="regular-text"></td></tr>';
		echo '<tr data-method="sdk"><th>' . esc_html__( 'Environment', 'nonprofit-manager' ) . '</th><td>';
		echo '<label><input type="radio" name="npmp_paypal_mode" value="live" '
			. checked( $mode, 'live', false ) . '> ' . esc_html__( 'Live', 'nonprofit-manager' ) . '</label> ';
		echo '<label><input type="radio" name="npmp_paypal_mode" value="sandbox" '
			. checked( $mode, 'sandbox', false ) . '> ' . esc_html__( 'Sandbox', 'nonprofit-manager' ) . '</label>';
		echo '</td></tr>';

		// Minimum amount
		echo '<tr><th>' . esc_html__( 'Minimum Amount', 'nonprofit-manager' ) . '</th><td>'
			. '<input type="number" step="0.01" name="npmp_paypal_minimum" value="'
			. esc_attr( get_option( 'npmp_paypal_minimum', 1 ) )
			. '" class="small-text"> USD</td></tr>';

		echo '</table></div>';
	} );
}
