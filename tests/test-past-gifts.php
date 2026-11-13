<?php
/**
 * Pure helpers for gifts brought over from GiveWP and Charitable
 * (includes/import/past-gifts.php).
 *
 * Run: php tests/test-past-gifts.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );
require_once $root . '/includes/import/past-gifts.php';

check( 'key joins source and id', 'givewp:42', npmp_past_gift_key( 'givewp', 42 ) );
check( 'key lowercases the source', 'charitable:7', npmp_past_gift_key( 'Charitable', '7' ) );
check( 'key strips odd characters', 'givewp:12abc', npmp_past_gift_key( 'give wp!', '12 abc' ) );

check( 'no period is one-time', 'one_time', npmp_past_gift_frequency( '' ) );
check( 'month is monthly', 'monthly', npmp_past_gift_frequency( 'month' ) );
check( 'every three months is quarterly', 'quarterly', npmp_past_gift_frequency( 'month', 3 ) );
check( 'every twelve months is annual', 'annual', npmp_past_gift_frequency( 'month', 12 ) );
check( 'quarter is quarterly', 'quarterly', npmp_past_gift_frequency( 'quarter' ) );
check( 'year is annual', 'annual', npmp_past_gift_frequency( 'YEAR' ) );
check( 'week is weekly', 'weekly', npmp_past_gift_frequency( 'week' ) );
check( 'every two months has no match', '', npmp_past_gift_frequency( 'month', 2 ) );
check( 'day has no match', '', npmp_past_gift_frequency( 'day' ) );
check( 'every two years has no match', '', npmp_past_gift_frequency( 'year', 2 ) );
check( 'frequency 0 counts as 1', 'monthly', npmp_past_gift_frequency( 'month', 0 ) );

check( 'stripe gateway stays stripe', 'stripe', npmp_past_gift_gateway( 'stripe' ) );
check( 'GiveWP stripe variant maps to stripe', 'stripe', npmp_past_gift_gateway( 'stripe_payment_element' ) );
check( 'PayPal Commerce maps to paypal', 'paypal', npmp_past_gift_gateway( 'paypal-commerce' ) );
check( 'offline stays offline', 'offline', npmp_past_gift_gateway( 'offline' ) );
check( 'empty gateway is manual', 'manual', npmp_past_gift_gateway( '' ) );
check( 'manual stays manual', 'manual', npmp_past_gift_gateway( 'manual' ) );

check( 'decimal string amount', 25.5, npmp_past_gift_amount( '25.50' ) );
check( 'integer amount', 10.0, npmp_past_gift_amount( 10 ) );
check( 'three decimals survive', 1.234, npmp_past_gift_amount( '1.234' ) );
check( 'negative string is zero', 0.0, npmp_past_gift_amount( '-5.00' ) );
check( 'comma string is zero', 0.0, npmp_past_gift_amount( '1,000.00' ) );
check( 'zero is zero', 0.0, npmp_past_gift_amount( '0.00' ) );
check( 'text is zero', 0.0, npmp_past_gift_amount( 'abc' ) );

// No secret ever leaves the old plugin through this file.
$src = file_get_contents( $root . '/includes/import/past-gifts.php' );
check( 'writer never calls log_donation()', 0, preg_match( '/->log_donation\s*\(/', $src ) );
check( 'writer never fires npmp_donation_recorded', 0, preg_match( "/do_action\(\s*'npmp_donation_recorded'/", $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
