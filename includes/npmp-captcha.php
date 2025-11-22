<?php
/**
 * Unified CAPTCHA helpers.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/npmp-turnstile.php';
require_once __DIR__ . '/npmp-recaptcha.php';

/**
 * Get the active CAPTCHA provider.
 *
 * @return string one of none|turnstile|recaptcha.
 */
function npmp_captcha_get_provider() {
	$provider = get_option( 'npmp_captcha_provider', 'none' );
	$allowed  = array( 'none', 'turnstile', 'recaptcha' );

	if ( ! in_array( $provider, $allowed, true ) ) {
		$provider = 'none';
	}

	return $provider;
}

/**
 * Determine whether a CAPTCHA provider is fully configured and enabled.
 *
 * @return bool
 */
function npmp_captcha_is_enabled() {
	$provider = npmp_captcha_get_provider();

	switch ( $provider ) {
		case 'turnstile':
			return npmp_turnstile_enabled();

		case 'recaptcha':
			return npmp_recaptcha_enabled();

		default:
			return false;
	}
}

/**
 * Render the active CAPTCHA widget markup.
 *
 * @param string $context Optional context identifier.
 * @return string
 */
function npmp_captcha_render_widget( $context = 'signup' ) {
	$provider = npmp_captcha_get_provider();
	$output   = '';

	if ( 'turnstile' === $provider && npmp_turnstile_enabled() ) {
		ob_start();
		npmp_turnstile_render_widget();
		$output = ob_get_clean();
	} elseif ( 'recaptcha' === $provider && npmp_recaptcha_enabled() ) {
		ob_start();
		npmp_recaptcha_render_widget();
		$output = ob_get_clean();
	}

	return $output;
}

/**
 * Execute server-side CAPTCHA verification for the active provider.
 *
 * @param string $context Optional context identifier.
 * @return bool
 */
function npmp_captcha_verify( $context = 'signup' ) {
	$provider = npmp_captcha_get_provider();

	if ( 'turnstile' === $provider ) {
		return npmp_turnstile_verify();
	}

	if ( 'recaptcha' === $provider ) {
		return npmp_recaptcha_verify();
	}

	return true;
}
