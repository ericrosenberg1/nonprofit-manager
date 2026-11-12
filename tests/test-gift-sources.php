<?php
/**
 * GiveWP and Charitable gift sources: the pure mapping
 * (includes/import/givewp-source.php, includes/import/charitable-source.php).
 *
 * Run: php tests/test-gift-sources.php
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( array( 'add_filter', 'add_action' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}
if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $d ) {
		return $d;
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/npmp-currency.php';
require_once $root . '/includes/import/past-gifts.php';
require_once $root . '/includes/import/givewp-source.php';
require_once $root . '/includes/import/charitable-source.php';

// --- GiveWP meta table -------------------------------------------------
check( 'fresh install with donationmeta', 'give_donationmeta', npmp_givewp_pick_meta_table( array(), true, array( 'give_donationmeta' ) ) );
check( 'meta not moved yet means postmeta', 'postmeta', npmp_givewp_pick_meta_table( array(), false, array( 'give_paymentmeta' ) ) );
check( 'moved, not renamed', 'give_paymentmeta', npmp_givewp_pick_meta_table( array( 'v20_move_metadata_into_new_table' ), false, array( 'give_paymentmeta' ) ) );
check( 'moved and renamed', 'give_donationmeta', npmp_givewp_pick_meta_table( array( 'v20_move_metadata_into_new_table', 'v220_rename_donation_meta_type' ), false, array( 'give_donationmeta', 'give_paymentmeta' ) ) );
check( 'fresh with no tables', 'postmeta', npmp_givewp_pick_meta_table( array(), true, array() ) );

// --- GiveWP gifts ------------------------------------------------------
$post = array( 'ID' => 12, 'post_date' => '2025-03-01 09:00:00', 'post_date_gmt' => '2025-03-01 17:00:00' );
$meta = array(
	'_give_payment_total'            => '25.000000',
	'_give_payment_currency'         => 'EUR',
	'_give_payment_gateway'          => 'stripe_payment_element',
	'_give_payment_mode'             => 'live',
	'_give_payment_transaction_id'   => 'pi_123',
	'_give_donor_billing_first_name' => 'Ada',
	'_give_donor_billing_last_name'  => 'Lovelace',
);
$g = npmp_givewp_gift_from_row( $post, $meta, array(), array(), 'USD' );
check( 'givewp id', 12, $g['source_id'] );
check( 'givewp amount string', '25.000000', $g['amount'] );
check( 'givewp currency', 'EUR', $g['currency'] );
check( 'givewp date is GMT', '2025-03-01 17:00:00', $g['date_gmt'] );
check( 'givewp one-time', 'one_time', $g['frequency'] );
check( 'givewp name from billing', 'Ada Lovelace', $g['name'] );
check( 'givewp transaction', 'pi_123', $g['transaction_id'] );

check( 'givewp test gift skipped', null, npmp_givewp_gift_from_row( $post, array_merge( $meta, array( '_give_payment_mode' => 'test' ) ), array(), array(), 'USD' ) );
check( 'givewp blank currency falls back', 'CAD', npmp_givewp_gift_from_row( $post, array_merge( $meta, array( '_give_payment_currency' => '' ) ), array(), array(), 'CAD' )['currency'] );
check( 'givewp zero GMT uses local date', '2025-03-01 09:00:00', npmp_givewp_gift_from_row( array_merge( $post, array( 'post_date_gmt' => '0000-00-00 00:00:00' ) ), $meta, array(), array(), 'USD' )['date_gmt'] );

$plans = array( 7 => array( 'id' => 7, 'period' => 'month', 'frequency' => 1, 'parent_payment_id' => 12 ) );
check( 'givewp first payment monthly', 'monthly', npmp_givewp_gift_from_row( $post, $meta, array(), array( 12 => $plans[7] ), 'USD' )['frequency'] );
check( 'givewp renewal by subscription_id', 'monthly', npmp_givewp_gift_from_row( array_merge( $post, array( 'ID' => 13 ) ), array_merge( $meta, array( 'subscription_id' => '7' ) ), $plans, array(), 'USD' )['frequency'] );
$odd = array( 8 => array( 'id' => 8, 'period' => 'month', 'frequency' => 2, 'parent_payment_id' => 1 ) );
check( 'givewp every two months is recurring', 'recurring', npmp_givewp_gift_from_row( $post, array_merge( $meta, array( 'subscription_id' => '8' ) ), $odd, array(), 'USD' )['frequency'] );
check( 'givewp renewal with plan gone is recurring', 'recurring', npmp_givewp_gift_from_row( $post, array_merge( $meta, array( 'subscription_id' => '99' ) ), array(), array(), 'USD' )['frequency'] );
check( 'givewp yearly plan', 'annual', npmp_givewp_gift_from_row( $post, $meta, array(), array( 12 => array( 'period' => 'year', 'frequency' => 1 ) ), 'USD' )['frequency'] );

// --- Charitable gifts --------------------------------------------------
$row   = array( 'ID' => 40, 'post_date' => '2024-12-31 20:00:00', 'post_date_gmt' => '2025-01-01 04:00:00', 'amount' => '30.0000', 'parent_type' => null );
$cmeta = array( 'test_mode' => '0', 'currency' => 'GBP', 'donation_gateway' => 'stripe', '_gateway_transaction_id' => 'ch_9' );
$c     = npmp_charitable_gift_from_row( $row, $cmeta, 'USD' );
check( 'charitable id', 40, $c['source_id'] );
check( 'charitable amount', '30.000', $c['amount'] );
check( 'charitable currency', 'GBP', $c['currency'] );
check( 'charitable one-time', 'one_time', $c['frequency'] );
check( 'charitable gateway', 'stripe', $c['gateway'] );
check( 'charitable test skipped', null, npmp_charitable_gift_from_row( $row, array_merge( $cmeta, array( 'test_mode' => '1' ) ), 'USD' ) );
check( 'charitable partial refund comes off', '20.000', npmp_charitable_gift_from_row( $row, array_merge( $cmeta, array( 'donation_refund' => array( 'total_refund' => 10 ) ) ), 'USD' )['amount'] );
check( 'charitable full refund skipped', null, npmp_charitable_gift_from_row( $row, array_merge( $cmeta, array( 'donation_refund' => array( 'total_refund' => '30.00' ) ) ), 'USD' ) );
check( 'charitable renewal is recurring', 'recurring', npmp_charitable_gift_from_row( array_merge( $row, array( 'parent_type' => 'recurring_donation' ) ), $cmeta, 'USD' )['frequency'] );
check( 'charitable blank currency falls back', 'AUD', npmp_charitable_gift_from_row( $row, array_merge( $cmeta, array( 'currency' => '' ) ), 'AUD' )['currency'] );

// --- No secret leaves the old plugin ------------------------------------
$secret_names = array( 'secret_key', 'publishable_key', 'access_token', 'refresh_token', 'api_password', 'api_signature', 'webhook_signing', 'license_key', 'client_token', 'seller_access', 'crypto_secret', 'connect_token', 'aes_key', 'recaptcha', 'middleware_secret' );
foreach ( array( 'givewp-source.php', 'charitable-source.php', 'gift-importer.php', 'past-gifts.php' ) as $file ) {
	$src = file_get_contents( $root . '/includes/import/' . $file );
	$hit = '';
	foreach ( $secret_names as $needle ) {
		if ( false !== stripos( $src, $needle ) ) {
			$hit = $needle;
		}
	}
	check( "{$file} names no secret", '', $hit );
	preg_match_all( "/\\\$settings\[\s*'([a-z_]+)'/", $src, $m );
	check( "{$file} reads only the currency setting", array(), array_values( array_diff( array_unique( $m[1] ), array( 'currency' ) ) ) );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
