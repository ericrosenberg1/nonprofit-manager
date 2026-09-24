<?php
/**
 * File path: includes/npmp-rate-limit.php
 *
 * Small per-visitor rate limiter for the public forms (signup, Stripe
 * checkout). Counts in a transient keyed on a hash of the visitor's IP, so no
 * address is stored.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The visitor's IP as this site sees it. Sites behind a proxy that doesn't
 * restore the real address can supply it through the npmp_client_ip filter.
 *
 * @return string
 */
function npmp_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$ip = (string) apply_filters( 'npmp_client_ip', $ip );
	return preg_replace( '/[^0-9a-fA-F:.]/', '', $ip );
}

/**
 * Count one attempt against a limit and say whether it's allowed.
 *
 * @param string $bucket Name of the thing being limited, e.g. 'signup'.
 * @param int    $max    Attempts allowed per window.
 * @param int    $window Window length in seconds.
 * @param string $key    Who is counted. Defaults to the visitor's IP; pass
 *                       'site' for a site-wide ceiling.
 * @return bool True when this attempt is within the limit.
 */
function npmp_rate_limit_allows( $bucket, $max, $window, $key = '' ) {
	$max = (int) apply_filters( 'npmp_rate_limit_max', (int) $max, $bucket, $key );
	if ( $max <= 0 ) {
		return true; // A filter set to 0 switches the limit off.
	}
	if ( '' === $key ) {
		$key = npmp_client_ip();
	}
	$transient = 'npmp_rl_' . substr( md5( $bucket . '|' . $key . '|' . wp_salt( 'nonce' ) ), 0, 24 );
	$state     = get_transient( $transient );
	if ( ! is_array( $state ) || empty( $state['until'] ) || $state['until'] < time() ) {
		$state = array(
			'n'     => 0,
			'until' => time() + (int) $window,
		);
	}
	$state['n']++;
	set_transient( $transient, $state, max( 1, $state['until'] - time() ) );
	return $state['n'] <= $max;
}
