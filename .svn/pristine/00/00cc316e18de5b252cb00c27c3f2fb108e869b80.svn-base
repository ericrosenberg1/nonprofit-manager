<?php
/**
 * Google reCAPTCHA v3 integration helpers.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Determine whether reCAPTCHA keys are present.
 *
 * @return bool
 */
function npmp_recaptcha_is_configured() {
	$site   = get_option( 'npmp_recaptcha_site_key', '' );
	$secret = get_option( 'npmp_recaptcha_secret_key', '' );

	return ( ! empty( $site ) && ! empty( $secret ) );
}

/**
 * Check if reCAPTCHA is the active provider and enabled.
 *
 * @return bool
 */
function npmp_recaptcha_enabled() {
	$provider = get_option( 'npmp_captcha_provider', 'none' );

	return (
		'recaptcha' === $provider
		&& npmp_is_pro()
		&& npmp_recaptcha_is_configured()
	);
}

/**
 * Enqueue the Google reCAPTCHA v3 script.
 *
 * @return void
 */
function npmp_recaptcha_enqueue_script() {
	if ( ! npmp_recaptcha_enabled() ) {
		return;
	}

	$site_key = get_option( 'npmp_recaptcha_site_key', '' );
	if ( empty( $site_key ) ) {
		return;
	}

	$handle = 'google-recaptcha-v3';
	if ( ! wp_script_is( $handle, 'registered' ) ) {
		wp_register_script(
			$handle,
			'https://www.google.com/recaptcha/api.js?render=' . $site_key,
			array(),
			null,
			true
		);
	}

	if ( ! wp_script_is( $handle, 'enqueued' ) ) {
		wp_enqueue_script( $handle );
	}

	// Add inline script to execute reCAPTCHA on form submission
	$inline_script = "
	document.addEventListener('DOMContentLoaded', function() {
		var forms = document.querySelectorAll('form[data-recaptcha=\"true\"]');
		forms.forEach(function(form) {
			form.addEventListener('submit', function(e) {
				if (form.querySelector('input[name=\"g-recaptcha-response\"]')) {
					return; // Already has token
				}
				e.preventDefault();
				grecaptcha.ready(function() {
					grecaptcha.execute('" . esc_js( $site_key ) . "', {action: 'submit'}).then(function(token) {
						var input = document.createElement('input');
						input.type = 'hidden';
						input.name = 'g-recaptcha-response';
						input.value = token;
						form.appendChild(input);
						form.submit();
					});
				});
			});
		});
	});
	";

	wp_add_inline_script( $handle, $inline_script );
}

/**
 * Render the reCAPTCHA v3 widget container.
 * For v3, this is invisible and automatic.
 *
 * @return void
 */
function npmp_recaptcha_render_widget() {
	if ( ! npmp_recaptcha_enabled() ) {
		return;
	}

	npmp_recaptcha_enqueue_script();

	// Add data attribute to form to enable reCAPTCHA
	echo '<input type="hidden" name="recaptcha-enabled" value="1">';
	echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var form = document.querySelector("form input[name=\"recaptcha-enabled\"]");
			if (form) {
				form.closest("form").setAttribute("data-recaptcha", "true");
			}
		});
	</script>';
}

/**
 * Validate the reCAPTCHA v3 response.
 *
 * @param string $response_field Optional POST field key.
 * @param float  $threshold      Minimum score threshold (0.0 to 1.0).
 * @return bool
 */
function npmp_recaptcha_verify( $response_field = 'g-recaptcha-response', $threshold = 0.5 ) {
	if ( ! npmp_recaptcha_enabled() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reCAPTCHA response is validated server-side without a WordPress nonce.
	$token = isset( $_POST[ $response_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $response_field ] ) ) : '';

	if ( '' === $token ) {
		return false;
	}

	$secret = get_option( 'npmp_recaptcha_secret_key', '' );
	if ( empty( $secret ) ) {
		return false;
	}

	$args = array(
		'timeout' => 10,
		'body'    => array(
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		),
	);

	$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', $args );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || ! is_array( $data ) ) {
		return false;
	}

	// Check if verification was successful
	if ( empty( $data['success'] ) ) {
		return false;
	}

	// Check score (v3 returns a score between 0.0 and 1.0)
	$score = isset( $data['score'] ) ? (float) $data['score'] : 0.0;

	return $score >= $threshold;
}

/**
 * Test the configured reCAPTCHA keys.
 *
 * @return true|WP_Error
 */
function npmp_recaptcha_test_keys() {
	if ( ! npmp_recaptcha_is_configured() ) {
		return new WP_Error( 'npmp-recaptcha-missing', __( 'Enter your reCAPTCHA site and secret keys before testing.', 'nonprofit-manager' ) );
	}

	// For reCAPTCHA v3, we can't test without a valid token from the frontend
	// So we just verify the keys are present and properly formatted
	$site_key   = get_option( 'npmp_recaptcha_site_key', '' );
	$secret_key = get_option( 'npmp_recaptcha_secret_key', '' );

	// Basic validation - reCAPTCHA v3 keys are 40 characters
	if ( strlen( $site_key ) < 30 || strlen( $secret_key ) < 30 ) {
		return new WP_Error( 'npmp-recaptcha-invalid', __( 'The reCAPTCHA keys appear to be invalid. Please check that you copied them correctly.', 'nonprofit-manager' ) );
	}

	return true;
}
