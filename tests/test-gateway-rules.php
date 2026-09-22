<?php
/**
 * The free/Pro line for donation gateways (includes/payments/npmp-gateway-rules.php).
 *
 * One-time gifts through PayPal (link), Venmo (link) and Stripe are free.
 * The PayPal API gateway is Pro. Stripe drifted to Pro-only once (2.0.1)
 * while every listing still called it free, so this is pinned.
 *
 * Run: php tests/test-gateway-rules.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/payments/npmp-gateway-rules.php';

$free = npmp_allowed_gateways( false );
$pro  = npmp_allowed_gateways( true );

check( 'free: PayPal link allowed', true, in_array( 'paypal_link', $free, true ) );
check( 'free: Venmo link allowed', true, in_array( 'venmo_link', $free, true ) );
check( 'free: Stripe allowed (one-time gifts)', true, in_array( 'stripe', $free, true ) );
check( 'free: PayPal API needs Pro', false, in_array( 'paypal_api', $free, true ) );
check( 'Pro: everything free has', true, array() === array_diff( $free, $pro ) );
check( 'Pro: PayPal API allowed', true, in_array( 'paypal_api', $pro, true ) );
check( 'unknown gateway never allowed', false, in_array( 'bitcoin', $pro, true ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
