<?php
/**
 * File path: includes/payments/npmp-gateway-rules.php
 *
 * Which donation gateways a site can switch on.
 *
 * One-time gifts are free on every site: the PayPal donation link, the Venmo
 * link and Stripe card checkout. Pro adds the PayPal API (Smart Buttons) and
 * recurring gifts. Stripe was free in 2.0.0, locked behind Pro in 2.0.1 while
 * the WordPress.org listing kept calling it free, and freed again in
 * 2026.09.19. tests/test-gateway-rules.php pins the split.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_allowed_gateways' ) ) {
	/**
	 * Gateway slugs this site may enable.
	 *
	 * @param bool $is_pro Whether Nonprofit Manager Pro is active.
	 * @return string[]
	 */
	function npmp_allowed_gateways( $is_pro ) {
		$free = array( 'paypal_link', 'venmo_link', 'stripe' );
		return $is_pro ? array_merge( $free, array( 'paypal_api' ) ) : $free;
	}
}
