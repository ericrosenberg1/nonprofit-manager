<?php
/**
 * Helpers added in the 2026.11 settings and payments sweep: Stripe error
 * wording, the gateway and frequency names on the Donations table, the
 * warnings for a payment method that's on but missing its details, the
 * Dashboard's recurring run rate, and the tour's skip predicates.
 *
 * Run: php tests/test-payments-sweep.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npmp_test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $key ] : $default;
}
function npmp_record_currency( $code ) {
	return '' === (string) $code ? 'USD' : strtoupper( (string) $code );
}
function npmp_sort_currency_totals( $totals ) {
	ksort( $totals );
	return $totals;
}

/**
 * Pull one function out of a plugin file without loading WordPress.
 */
function npmp_test_extract( $file, $fn ) {
	$src   = file_get_contents( __DIR__ . '/../' . $file );
	$start = strpos( $src, "function $fn(" );
	if ( false === $start ) {
		echo "  FAIL  $fn not found in $file\n";
		exit( 1 );
	}
	$end = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness.
}

npmp_test_extract( 'includes/payments/npmp-payment-gateways.php', 'npmp_stripe_session_error_message' );
npmp_test_extract( 'includes/npmp-payments-settings.php', 'npmp_gateway_label' );
npmp_test_extract( 'includes/npmp-payments-settings.php', 'npmp_frequency_label' );
npmp_test_extract( 'includes/npmp-payments-settings.php', 'npmp_payment_setup_gaps' );
npmp_test_extract( 'includes/npmp-currency.php', 'npmp_group_totals_by_currency' );
npmp_test_extract( 'includes/npmp-dashboard-widgets.php', 'npmp_annualize_donation_amount' );
npmp_test_extract( 'includes/npmp-dashboard-widgets.php', 'npmp_recurring_gift_is_current' );
npmp_test_extract( 'includes/npmp-dashboard-widgets.php', 'npmp_recurring_run_rate_totals' );
npmp_test_extract( 'includes/npmp-dashboard-widgets.php', 'npmp_recurring_latest_rows' );
npmp_test_extract( 'includes/onboarding/tour-data.php', 'npmp_tour_feature_off' );
npmp_test_extract( 'includes/onboarding/tour-data.php', 'npmp_tour_skip_without_members' );
npmp_test_extract( 'includes/onboarding/tour-data.php', 'npmp_tour_skip_without_donations' );
npmp_test_extract( 'includes/onboarding/tour-data.php', 'npmp_tour_skip_without_calendar' );

echo "\n== 1. Stripe errors a donor sees ==\n";
$key_error = npmp_stripe_session_error_message( 401, array( 'error' => array( 'message' => 'Invalid API Key provided: sk_live_****abcd' ) ) );
check( 'a bad key never shows the key to the donor', false, false !== strpos( $key_error, 'sk_live' ) );
check( 'a bad key asks the donor to contact the site', true, false !== strpos( $key_error, 'site administrator' ) );
check( '403 reads the same as 401', $key_error, npmp_stripe_session_error_message( 403, array() ) );
check( 'other errors pass Stripe\'s message through', 'Stripe error: Amount must be at least 50 cents', npmp_stripe_session_error_message( 400, array( 'error' => array( 'message' => 'Amount must be at least 50 cents' ) ) ) );
check( 'no body still reads as a sentence', 'Stripe error: Unknown error', npmp_stripe_session_error_message( 500, null ) );

echo "\n== 2. Donations table names ==\n";
check( 'stripe', 'Stripe', npmp_gateway_label( 'stripe' ) );
check( 'paypal_api reads PayPal', 'PayPal', npmp_gateway_label( 'paypal_api' ) );
check( 'venmo_link reads Venmo', 'Venmo', npmp_gateway_label( 'venmo_link' ) );
check( 'unknown gateway is tidied, not dropped', 'Square pos', npmp_gateway_label( 'square_pos' ) );
check( 'one_time reads One-Time', 'One-Time', npmp_frequency_label( 'one_time' ) );
check( 'yearly and annual read the same', npmp_frequency_label( 'annual' ), npmp_frequency_label( 'yearly' ) );

echo "\n== 3. Payment method on but missing details ==\n";
$GLOBALS['npmp_test_options'] = array();
check( 'nothing on, nothing to warn about', array(), npmp_payment_setup_gaps( array(), false ) );
check( 'PayPal button with no email warns once', 1, count( npmp_payment_setup_gaps( array( 'paypal_link' ), false ) ) );
check( 'Venmo with no username warns once', 1, count( npmp_payment_setup_gaps( array( 'venmo_link' ), false ) ) );
$GLOBALS['npmp_test_options'] = array(
	'npmp_stripe_mode'                 => 'test',
	'npmp_stripe_test_publishable_key' => 'pk_test_x',
	'npmp_stripe_test_secret_key'      => 'sk_test_x',
);
check( 'Stripe in test mode with both test keys is fine', array(), npmp_payment_setup_gaps( array( 'stripe' ), false ) );
$GLOBALS['npmp_test_options']['npmp_stripe_mode'] = 'live';
$gaps = npmp_payment_setup_gaps( array( 'stripe' ), false );
check( 'switching to live with only test keys warns', true, 1 === count( $gaps ) && false !== strpos( $gaps[0], 'Live mode' ) );
check( 'PayPal API on a free site never warns (free has no PayPal API)', array(), npmp_payment_setup_gaps( array( 'paypal_api' ), false ) );
check( 'PayPal API on Pro with no client ID warns', 1, count( npmp_payment_setup_gaps( array( 'paypal_api' ), true ) ) );
foreach ( array_merge( npmp_payment_setup_gaps( array( 'paypal_link', 'venmo_link', 'stripe', 'paypal_api' ), true ) ) as $line ) {
	check( 'no em dash or semicolon: ' . substr( $line, 0, 40 ), false, false !== strpos( $line, "\xE2\x80\x94" ) || false !== strpos( $line, ';' ) );
}

echo "\n== 4. Recurring run rate ==\n";
$now = strtotime( '2026-11-03 12:00:00 UTC' );
$latest = npmp_recurring_latest_rows( array( array( 'frequency' => 'monthly', 'currency' => 'USD', 'latest' => '2026-11-01 10:00:00 1000000000042|25.50', 'gift_date' => '2026-11-01 10:00:00' ) ) );
check( 'the latest gift\'s amount comes out of the MAX key', '25.50', $latest[0]['amount'] );
check( 'and the row keeps the shape the totals read', array( 'frequency', 'currency', 'amount', 'gift_date' ), array_keys( $latest[0] ) );
check( 'an amount with a pipe in it survives', '1|2', npmp_recurring_latest_rows( array( array( 'latest' => '2026-11-01 10:00:00 1000000000042|1|2' ) ) )[0]['amount'] );
$dw_src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-dashboard-widgets.php' );
check( 'no GROUP_CONCAT SEPARATOR, which SQLite can\'t run', false, (bool) preg_match( '/GROUP_CONCAT\s*\(/', $dw_src ) );
check( 'the ID is wrapped so SQLite adds before it joins', true, false !== strpos( $dw_src, "' ', ( p.ID + 1000000000000 ), '|'" ) );
check( 'monthly gift 30 days old is current', true, npmp_recurring_gift_is_current( 'monthly', $now - 30 * 86400, $now ) );
check( 'monthly gift 60 days old has lapsed', false, npmp_recurring_gift_is_current( 'monthly', $now - 60 * 86400, $now ) );
check( 'weekly gift 20 days old has lapsed', false, npmp_recurring_gift_is_current( 'weekly', $now - 20 * 86400, $now ) );
check( 'quarterly gift 95 days old is current', true, npmp_recurring_gift_is_current( 'quarterly', $now - 95 * 86400, $now ) );
check( 'yearly gift 370 days old is current', true, npmp_recurring_gift_is_current( 'yearly', $now - 370 * 86400, $now ) );
check( 'annual gift 400 days old has lapsed', false, npmp_recurring_gift_is_current( 'annual', $now - 400 * 86400, $now ) );
check( 'one_time is never recurring', false, npmp_recurring_gift_is_current( 'one_time', $now, $now ) );
check( 'Monthly in capitals still counts', true, npmp_recurring_gift_is_current( ' Monthly ', $now - 86400, $now ) );
$rows = array(
	array( 'frequency' => 'monthly', 'currency' => 'USD', 'amount' => '10', 'gift_date' => '2026-10-20 09:00:00' ),
	array( 'frequency' => 'annual', 'currency' => '', 'amount' => '100', 'gift_date' => '2026-01-15 09:00:00' ),
	array( 'frequency' => 'weekly', 'currency' => 'USD', 'amount' => '5', 'gift_date' => '2026-08-01 09:00:00' ),
	array( 'frequency' => 'quarterly', 'currency' => 'GBP', 'amount' => '20', 'gift_date' => '2026-10-01 09:00:00' ),
);
check( 'one row per donor, lapsed donors dropped, currencies kept apart', array( 'GBP' => 80.0, 'USD' => 220.0 ), npmp_recurring_run_rate_totals( $rows, $now ) );
check( 'no rows, no totals', array(), npmp_recurring_run_rate_totals( array(), $now ) );

echo "\n== 5. Tour skips the steps for features that are off ==\n";
$GLOBALS['npmp_test_options'] = array( 'npmp_enabled_features' => array( 'members' => true, 'donations' => true, 'calendar' => true ) );
check( 'calendar on: events step shows (it used to skip)', false, npmp_tour_skip_without_calendar() );
$GLOBALS['npmp_test_options']['npmp_enabled_features']['calendar'] = false;
check( 'calendar off: events step skips', true, npmp_tour_skip_without_calendar() );
$GLOBALS['npmp_test_options']['npmp_enabled_features']['members'] = false;
check( 'members off: member, email, and import steps skip', true, npmp_tour_skip_without_members() );
check( 'donations on: donations step shows', false, npmp_tour_skip_without_donations() );
$GLOBALS['npmp_test_options'] = array();
check( 'fresh site: members step follows the default (on)', false, npmp_tour_skip_without_members() );
check( 'fresh site: calendar step follows the default (off)', true, npmp_tour_skip_without_calendar() );

$tour_src = file_get_contents( __DIR__ . '/../includes/onboarding/tour-data.php' );
check( 'no step still uses the inverted calendar predicate', false, false !== strpos( $tour_src, "'option:npmp_enabled_features.calendar'" ) );
check( 'every named skip predicate is defined', 0, count( array_filter(
	( preg_match_all( "/'skip_if'\s*=>\s*'(npmp_[a-z_]+)'/", $tour_src, $m ) ? $m[1] : array() ),
	static function ( $fn ) {
		return ! function_exists( $fn );
	}
) ) );

echo "\n== 6. General Settings Test buttons submit their own forms ==\n";
// A <form> inside another <form> is dropped by the browser, so the Test
// buttons used to submit the security form: it saved and never tested.
$gs = file_get_contents( __DIR__ . '/../includes/npmp-general-settings.php' );
foreach ( array( 'turnstile', 'recaptcha' ) as $kind ) {
	check( "$kind Test button points at its own form", true, false !== strpos( $gs, 'form="npmp-test-' . $kind . '-form"' ) );
	$form_at  = strpos( $gs, 'id="npmp-test-' . $kind . '-form"' );
	$outer_at = strpos( $gs, "submit_button( __( 'Save security settings'" );
	check( "$kind test form sits after the security form closes", true, false !== $form_at && false !== $outer_at && $form_at > $outer_at );
}
$depth = 0;
$max   = 0;
if ( preg_match_all( '#<(/?)form\b#', $gs, $tags ) ) {
	foreach ( $tags[1] as $closing ) {
		$depth += '' === $closing ? 1 : -1;
		$max    = max( $max, $depth );
	}
}
check( 'no form opens inside another form', 1, $max );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
