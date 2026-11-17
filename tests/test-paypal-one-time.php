<?php
/**
 * PayPal API gifts are one-time only.
 *
 * The PayPal API form captures one PayPal order, and nothing in free or Pro
 * renews it. Until this release Payment Settings offered Weekly, Monthly,
 * Quarterly, and Yearly PayPal gifts and said PayPal "supports recurring
 * subscriptions", so a donor who picked Monthly paid once and the gift was
 * saved as monthly. Pinned here: the PayPal form offers only One-Time whatever
 * the old npmp_paypal_enable_* options hold, Stripe's choices still follow its
 * own options, the AJAX logger saves every PayPal API gift as one_time, and
 * the settings screen no longer offers or saves PayPal schedules.
 *
 * Run: php tests/test-paypal-one-time.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_pro']     = true;
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
}
function npmp_is_pro() {
	return $GLOBALS['t_pro'];
}

$gateways = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
foreach ( array( 'npmp_get_enabled_frequencies', 'npmp_has_multiple_frequencies' ) as $fn ) {
	$start = strpos( $gateways, "function {$fn}(" );
	$end   = strpos( $gateways, "\n}", $start ) + 2;
	eval( substr( $gateways, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting the functions rather than booting WordPress.
}

echo "Frequencies\n";
foreach ( array( 'weekly', 'monthly', 'quarterly', 'annual' ) as $f ) {
	$GLOBALS['t_options'][ 'npmp_paypal_enable_' . $f ] = 1;
	$GLOBALS['t_options'][ 'npmp_stripe_enable_' . $f ] = 1;
}
check( 'PayPal offers One-Time only, old options on', array( 'one_time' ), array_keys( npmp_get_enabled_frequencies( 'paypal_api' ) ) );
check( 'PayPal form shows no Frequency list', false, npmp_has_multiple_frequencies( 'paypal_api' ) );
check( 'Stripe still offers its schedules', array( 'one_time', 'weekly', 'monthly', 'quarterly', 'annual' ), array_keys( npmp_get_enabled_frequencies( 'stripe' ) ) );
$GLOBALS['t_options']['npmp_stripe_enable_weekly'] = 0;
check( 'Stripe follows its own options', false, isset( npmp_get_enabled_frequencies( 'stripe' )['weekly'] ) );
$GLOBALS['t_pro'] = false;
check( 'free Stripe stays one-time', array( 'one_time' ), array_keys( npmp_get_enabled_frequencies( 'stripe' ) ) );

echo "\nLogger\n";
$start  = strpos( $gateways, 'function npmp_ajax_log_donation(' );
$end    = strpos( $gateways, "\n}", $start );
$logger = substr( $gateways, $start, $end - $start );
check( 'logger never reads a posted frequency', false, (bool) preg_match( "/\\\$_POST\\[\\s*'frequency'/", $logger ) );
check( 'logger saves one_time', 1, preg_match( "/\\\$frequency\\s*=\\s*'one_time'\\s*;/", $logger ) );

echo "\nSettings screen\n";
$settings = file_get_contents( __DIR__ . '/../includes/npmp-payments-settings.php' );
check( 'no PayPal schedule checkboxes', false, false !== strpos( $settings, 'name="npmp_paypal_enable_' ) );
check( 'no PayPal schedule saves', false, (bool) preg_match( "/update_option\\(\\s*'npmp_paypal_enable_/", $settings ) );
check( 'no claim that PayPal recurs', false, false !== strpos( $settings, 'PayPal API supports recurring' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
