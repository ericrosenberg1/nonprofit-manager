<?php
/**
 * Cloudflare Turnstile integration helpers.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine whether Turnstile keys are present.
 *
 * @return bool
 */
function npmp_turnstile_is_configured() {
	$site   = get_option( 'npmp_turnstile_site_key', '' );
	$secret = get_option( 'npmp_turnstile_secret_key', '' );

	return ( ! empty( $site ) && ! empty( $secret ) );
}

/**
 * Check if Turnstile is the active provider and enabled.
 *
 * @return bool
 */
function npmp_turnstile_enabled() {
	$provider = get_option( 'npmp_captcha_provider', 'none' );
	$enabled  = (int) get_option( 'npmp_turnstile_enabled', 0 );

	return (
		'turnstile' === $provider
		&& 1 === $enabled
		&& npmp_turnstile_is_configured()
	);
}

/**
 * Enqueue the local Turnstile loader that injects the remote script on demand.
 *
 * @return void
 */
function npmp_turnstile_enqueue_loader() {
	if ( ! npmp_turnstile_enabled() ) {
		return;
	}

	$handle    = 'npmp-turnstile-loader';
	$script    = plugins_url( 'assets/js/turnstile-loader.js', dirname( __DIR__ ) . '/nonprofit-manager.php' );
	$version   = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/turnstile-loader.js' ) : null;
	$deps      = array();

	if ( ! wp_script_is( $handle, 'registered' ) ) {
		wp_register_script( $handle, $script, $deps, $version, true );
	}

	wp_enqueue_script( $handle );
}

/**
 * Render the Turnstile widget container.
 *
 * @param string $theme Optional theme value.
 * @return void
 */
function npmp_turnstile_render_widget( $theme = 'auto' ) {
	if ( ! npmp_turnstile_enabled() ) {
		return;
	}

	npmp_turnstile_enqueue_loader();

	$site_key = get_option( 'npmp_turnstile_site_key', '' );

	if ( $site_key ) {
		echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" data-theme="' . esc_attr( $theme ) . '"></div>';
	}
}

/**
 * Validate the Turnstile response.
 *
 * @param string $response_field Optional POST field key.
 * @return bool
 */
function npmp_turnstile_verify( $response_field = 'cf-turnstile-response' ) {
	if ( ! npmp_turnstile_enabled() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Turnstile response is validated server-side without a WordPress nonce.
	$token = isset( $_POST[ $response_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $response_field ] ) ) : '';

	if ( '' === $token ) {
		return false;
	}

	$args     = array(
		'timeout' => 10,
		'body'    => array(
			'secret'   => get_option( 'npmp_turnstile_secret_key', '' ),
			'response' => $token,
			'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		),
	);
	$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $args );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return ( 200 === $code && is_array( $data ) && ! empty( $data['success'] ) );
}

/**
 * Test the configured Turnstile keys by validating the secret.
 *
 * @return true|WP_Error
 */
function npmp_turnstile_test_keys() {
	if ( ! npmp_turnstile_is_configured() ) {
		return new WP_Error( 'npmp-turnstile-missing', __( 'Enter your Turnstile site and secret keys before testing.', 'nonprofit-manager' ) );
	}

	$request = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => get_option( 'npmp_turnstile_secret_key', '' ),
				'response' => 'npmp-test-token',
			),
		)
	);

	if ( is_wp_error( $request ) ) {
		return new WP_Error( 'npmp-turnstile-request', __( 'Unable to reach the Cloudflare Turnstile service. Please try again.', 'nonprofit-manager' ), $request->get_error_message() );
	}

	$data = json_decode( wp_remote_retrieve_body( $request ), true );

	if ( ! is_array( $data ) ) {
		return new WP_Error( 'npmp-turnstile-invalid', __( 'Unexpected response from Cloudflare Turnstile.', 'nonprofit-manager' ) );
	}

	if ( ! empty( $data['success'] ) ) {
		return true;
	}

	$error_codes = isset( $data['error-codes'] ) ? (array) $data['error-codes'] : array();

	if ( in_array( 'invalid-input-secret', $error_codes, true ) || in_array( 'missing-input-secret', $error_codes, true ) ) {
		return new WP_Error( 'npmp-turnstile-secret', __( 'The Turnstile secret key appears to be invalid. Please double-check and try again.', 'nonprofit-manager' ) );
	}

	return true;
}
