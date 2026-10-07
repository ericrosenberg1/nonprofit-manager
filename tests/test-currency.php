<?php
/**
 * Site currency helpers (includes/npmp-currency.php): formatting per
 * currency, conversion to and from Stripe minor units, gateway minimums, the
 * settings allowlist, Venmo hiding, NULL-as-USD for old records, and
 * per-currency totals that never add two currencies together.
 *
 * Run: php tests/test-currency.php
 */

require_once __DIR__ . '/bootstrap.php';

// WordPress pieces the helpers reach for. number_format_i18n is left
// undefined on purpose: the helpers fall back to number_format(), which is
// what an English-locale site gets from number_format_i18n anyway.
$GLOBALS['npmp_test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['npmp_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['npmp_test_options'][ $key ] );
	return true;
}
function apply_filters( $tag, $value, ...$args ) {
	return $value;
}

require_once __DIR__ . '/../includes/npmp-currency.php';

function use_currency( $code ) {
	$GLOBALS['npmp_test_options']['npmp_currency'] = $code;
}

echo "\n== 1. A site that never set a currency is a US-dollar site ==\n";
check( 'no option → USD', 'USD', npmp_currency() );
check( 'USD format unchanged', '$1,234.56', npmp_format_amount( 1234.56 ) );
check( 'USD rounds to cents', '$19.99', npmp_format_amount( 19.989 ) );
check( 'USD zero', '$0.00', npmp_format_amount( 0 ) );
check( 'USD symbol', '$', npmp_currency_symbol() );
check( 'USD minimum message is the old sentence', 'Please enter a valid donation amount (minimum $1).', npmp_currency_min_amount_message( 'enter' ) );
check( 'USD server message is the old sentence', 'Please provide a valid donation amount (minimum $1).', npmp_currency_min_amount_message( 'provide' ) );
check( 'USD input step', '0.01', npmp_currency_input_attrs()['step'] );
check( 'USD input min stays 1', '1', npmp_currency_input_attrs()['min'] );

echo "\n== 2. Formatting per currency ==\n";
check( 'GBP', '£1,234.56', npmp_format_amount( 1234.56, 'GBP' ) );
check( 'EUR, symbol after with comma decimals', '1.234,56 €', npmp_format_amount( 1234.56, 'EUR' ) );
check( 'JPY has no decimals', '¥5,000', npmp_format_amount( 5000, 'JPY' ) );
check( 'JPY rounds a stray fraction', '¥1,235', npmp_format_amount( 1234.6, 'JPY' ) );
check( 'CHF', "CHF 1'234.56", npmp_format_amount( 1234.56, 'CHF' ) );
check( 'SEK', '1 234,56 kr', npmp_format_amount( 1234.56, 'SEK' ) );
check( 'CAD', '$20.00', npmp_format_amount( 20, 'CAD' ) );
check( 'HKD', 'HK$20.00', npmp_format_amount( 20, 'HKD' ) );
check( 'lower-case code accepted', '£5.00', npmp_format_amount( 5, 'gbp' ) );
check( 'negative amount', '-£5.00', npmp_format_amount( -5, 'GBP' ) );
check( 'unknown code falls back to CODE number', 'BRL 10.00', npmp_format_amount( 10, 'BRL' ) );
check( 'unknown zero-decimal code', 'KRW 1,000', npmp_format_amount( 1000, 'KRW' ) );
check( 'site currency used when none given', '£7.50', ( function () { use_currency( 'GBP' ); $out = npmp_format_amount( 7.5 ); use_currency( 'USD' ); return $out; } )() );

echo "\n== 3. Stripe minor units, both ways ==\n";
check( 'USD 19.99 → 1999 (no float truncation)', 1999, npmp_currency_to_minor( 19.99, 'USD' ) );
check( 'USD 0.29 → 29', 29, npmp_currency_to_minor( 0.29, 'USD' ) );
check( 'GBP 25 → 2500', 2500, npmp_currency_to_minor( 25, 'GBP' ) );
check( 'JPY 5000 → 5000 (zero-decimal)', 5000, npmp_currency_to_minor( 5000, 'JPY' ) );
check( 'JPY 49.6 → 50 (rounded, never fractional yen)', 50, npmp_currency_to_minor( 49.6, 'JPY' ) );
check( 'KWD 1.5 → 1500 (three-decimal)', 1500, npmp_currency_to_minor( 1.5, 'KWD' ) );
check( 'USD 1999 → 19.99', 19.99, npmp_currency_from_minor( 1999, 'USD' ) );
check( 'GBP 2500 → 25.00', 25.0, npmp_currency_from_minor( 2500, 'GBP' ) );
check( 'JPY 5000 → 5000', 5000.0, npmp_currency_from_minor( 5000, 'JPY' ) );
check( 'numeric string from JSON', 12.34, npmp_currency_from_minor( '1234', 'EUR' ) );
check( 'garbage minor units → 0', 0.0, npmp_currency_from_minor( 'abc', 'USD' ) );
check( 'round trip USD', 123.45, npmp_currency_from_minor( npmp_currency_to_minor( 123.45, 'USD' ), 'USD' ) );
check( 'decimals JPY', 0, npmp_currency_decimals( 'JPY' ) );
check( 'decimals EUR', 2, npmp_currency_decimals( 'EUR' ) );
check( 'decimals KWD', 3, npmp_currency_decimals( 'KWD' ) );
check( 'PayPal API value USD', '25.00', npmp_currency_api_amount( 25, 'USD' ) );
check( 'PayPal API value JPY has no decimals', '5000', npmp_currency_api_amount( 5000, 'JPY' ) );
check( 'PayPal API value has no thousands separator', '1234.50', npmp_currency_api_amount( 1234.5, 'EUR' ) );

echo "\n== 4. Minimums ==\n";
check( 'Stripe minimum USD', 0.50, npmp_currency_stripe_minimum( 'USD' ) );
check( 'Stripe minimum GBP', 0.30, npmp_currency_stripe_minimum( 'GBP' ) );
check( 'Stripe minimum JPY', 50.0, npmp_currency_stripe_minimum( 'JPY' ) );
check( 'form floor USD stays 1', 1.0, npmp_currency_min_donation( 'USD' ) );
check( 'form floor GBP is 1 (above Stripe 0.30)', 1.0, npmp_currency_min_donation( 'GBP' ) );
check( 'form floor JPY is Stripe 50', 50.0, npmp_currency_min_donation( 'JPY' ) );
check( 'form floor MXN is Stripe 10', 10.0, npmp_currency_min_donation( 'MXN' ) );
check( 'USD 1 accepted', true, npmp_currency_meets_minimum( 1, 'USD' ) );
check( 'USD 0.99 refused', false, npmp_currency_meets_minimum( 0.99, 'USD' ) );
check( 'JPY 50 accepted', true, npmp_currency_meets_minimum( 50, 'JPY' ) );
check( 'JPY 49 refused', false, npmp_currency_meets_minimum( 49, 'JPY' ) );
check( 'DKK 2.50 accepted', true, npmp_currency_meets_minimum( 2.5, 'DKK' ) );
check( 'DKK 2.49 refused', false, npmp_currency_meets_minimum( 2.49, 'DKK' ) );
check( 'float noise does not refuse 0.1 + 0.9', true, npmp_currency_meets_minimum( 0.1 + 0.9, 'USD' ) );
check( 'GBP message names the minimum', 'Please provide a valid donation amount (minimum £1.00).', npmp_currency_min_amount_message( 'provide', 'GBP' ) );
check( 'JPY message names ¥50', 'Please enter a valid donation amount (minimum ¥50).', npmp_currency_min_amount_message( 'enter', 'JPY' ) );
check( 'JPY input step is whole yen', '1', npmp_currency_input_attrs( 'JPY' )['step'] );
check( 'JPY input min', '50', npmp_currency_input_attrs( 'JPY' )['min'] );
check( 'DKK input min', '2.5', npmp_currency_input_attrs( 'DKK' )['min'] );

echo "\n== 5. The setting is limited to the allowlist ==\n";
check( 'GBP kept', 'GBP', npmp_sanitize_currency( 'GBP' ) );
check( 'lower case normalised', 'EUR', npmp_sanitize_currency( ' eur ' ) );
check( 'JPY kept', 'JPY', npmp_sanitize_currency( 'JPY' ) );
check( 'valid ISO code outside the list → USD', 'USD', npmp_sanitize_currency( 'BRL' ) );
check( 'made-up code → USD', 'USD', npmp_sanitize_currency( 'XYZ' ) );
check( 'markup → USD', 'USD', npmp_sanitize_currency( '<script>' ) );
check( 'array → USD', 'USD', npmp_sanitize_currency( array( 'GBP' ) ) );
check( 'empty → USD', 'USD', npmp_sanitize_currency( '' ) );
use_currency( 'BTC' );
check( 'a stored value outside the list reads as USD', 'USD', npmp_currency() );
use_currency( 'USD' );
$required = array( 'USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'MXN', 'SGD', 'HKD' );
check( 'every required currency is offered', array(), array_values( array_diff( $required, array_keys( npmp_supported_currencies() ) ) ) );
check( 'every offered currency has a format entry', array(), array_values( array_diff( array_keys( npmp_supported_currencies() ), array_keys( npmp_currency_formats() ) ) ) );
$mismatch = array();
foreach ( npmp_currency_formats() as $code => $format ) {
	if ( (int) $format['decimals'] !== npmp_currency_decimals( $code ) ) {
		$mismatch[] = $code;
	}
}
check( 'format table decimals agree with Stripe minor units', array(), $mismatch );

echo "\n== 6. Venmo is US dollars only ==\n";
$gateways = array( 'paypal_link', 'venmo_link', 'stripe' );
check( 'USD keeps Venmo', $gateways, npmp_filter_gateways_for_currency( $gateways, 'USD' ) );
check( 'GBP drops Venmo', array( 'paypal_link', 'stripe' ), npmp_filter_gateways_for_currency( $gateways, 'GBP' ) );
check( 'Venmo-only on CAD leaves nothing', array(), npmp_filter_gateways_for_currency( array( 'venmo_link' ), 'CAD' ) );
check( 'non-array gateway option is safe', array(), npmp_filter_gateways_for_currency( 'venmo_link', 'GBP' ) );
use_currency( 'AUD' );
check( 'site currency AUD → Venmo unavailable', false, npmp_venmo_available() );
use_currency( 'USD' );
check( 'site currency USD → Venmo available', true, npmp_venmo_available() );

echo "\n== 7. Old records with no currency are US dollars ==\n";
check( 'null → USD', 'USD', npmp_record_currency( null ) );
check( 'empty string → USD', 'USD', npmp_record_currency( '' ) );
check( 'stored gbp → GBP', 'GBP', npmp_record_currency( 'gbp' ) );
check( 'a record keeps a currency no longer offered', 'BRL', npmp_record_currency( 'BRL' ) );
check( 'garbage stored value → USD', 'USD', npmp_record_currency( '12' ) );
use_currency( 'GBP' );
check( 'format of an old row on a GBP site is still $', '$25.00', npmp_format_amount( 25, npmp_record_currency( '' ) ) );
use_currency( 'USD' );

echo "\n== 8. Totals are kept per currency and never added together ==\n";
$rows = array(
	array( 'currency' => '', 'total' => '100.00' ),
	array( 'currency' => 'USD', 'total' => '50.00' ),
	array( 'currency' => 'GBP', 'total' => '30.00' ),
	(object) array( 'currency' => 'gbp', 'total' => '5.00' ),
	array( 'currency' => 'JPY', 'total' => '5000' ),
);
$totals = npmp_group_totals_by_currency( $rows );
check( 'old rows and USD rows share USD', 150.0, $totals['USD'] );
check( 'GBP summed on its own', 35.0, $totals['GBP'] );
check( 'JPY on its own', 5000.0, $totals['JPY'] );
check( 'three currencies, three totals', 3, count( $totals ) );
check( 'site currency listed first', 'USD', array_key_first( $totals ) );
check( 'mixed totals listed side by side', '$150.00 USD · £35.00 GBP · ¥5,000 JPY', npmp_format_amounts_by_currency( $totals ) );
check( 'single currency reads as a plain amount', '$1,200.00', npmp_format_amounts_by_currency( array( 'USD' => 1200 ) ) );
check( 'single GBP reads as a plain amount', '£80.00', npmp_format_amounts_by_currency( array( 'GBP' => 80 ) ) );
check( 'nothing yet shows zero in the site currency', '$0.00', npmp_format_amounts_by_currency( array() ) );
check( 'a code already in the text is not repeated', '$1.00 USD · CHF 5.00', npmp_format_amounts_by_currency( array( 'CHF' => 5, 'USD' => 1 ) ) );
use_currency( 'GBP' );
$sorted = npmp_group_totals_by_currency( $rows );
check( 'after switching to GBP, GBP is listed first', 'GBP', array_key_first( $sorted ) );
use_currency( 'USD' );
check( 'custom amount key', array( 'USD' => 7.0 ), npmp_group_totals_by_currency( array( array( 'currency' => null, 'total_amount' => 7 ) ), 'total_amount' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
