<?php
/**
 * The PayPal API order's purchase unit, with and without a DONATION item.
 *
 * PayPal's Orders v2 API marks a payment as a donation through
 * purchase_units[].items[].category = DONATION. An item needs a name, a
 * quantity and a unit_amount, and the amount then needs a breakdown whose
 * item_total equals unit_amount times quantity. PayPal refuses the item on an
 * account it hasn't enabled for donations, so the free plugin sends none
 * unless the npmp_paypal_donation_item filter (Pro's opt-in setting) says to.
 *
 * This drives npmp_paypal_purchase_unit() and its JavaScript form through both
 * states and checks that both Smart Buttons forms build their order from it.
 *
 * Run: php tests/test-paypal-donation-item.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_donation_item'] = false;
function apply_filters( $tag, $value, ...$args ) {
	return 'npmp_paypal_donation_item' === $tag ? $GLOBALS['t_donation_item'] : $value;
}
function wp_json_encode( $v ) { return json_encode( $v ); }

$src = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
foreach ( array( 'npmp_paypal_purchase_unit', 'npmp_paypal_purchase_unit_js' ) as $fn ) {
	$start = strpos( $src, "function {$fn}(" );
	$end   = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting the functions rather than booting WordPress.
}

/**
 * The JavaScript literal with its variable swapped for a JSON string, decoded.
 * Proves the literal is the same object the PHP builds.
 */
function js_unit_as_array( $value ) {
	$js = npmp_paypal_purchase_unit_js( 'npmpOrderValue', 'USD' );
	return json_decode( str_replace( 'npmpOrderValue', json_encode( $value ), $js ), true );
}

echo "\n== off (the default) ==\n";
$GLOBALS['t_donation_item'] = false;
$unit = npmp_paypal_purchase_unit( '25.00', 'USD' );
check( 'amount value', '25.00', $unit['amount']['value'] );
check( 'amount currency', 'USD', $unit['amount']['currency_code'] );
check( 'description', 'Donation', $unit['description'] );
check( 'no items', false, isset( $unit['items'] ) );
check( 'no breakdown', false, isset( $unit['amount']['breakdown'] ) );
check( 'JavaScript literal decodes to the same unit', $unit, js_unit_as_array( '25.00' ) );

echo "\n== on ==\n";
$GLOBALS['t_donation_item'] = true;
$unit = npmp_paypal_purchase_unit( '1500', 'JPY' );
check( 'amount value', '1500', $unit['amount']['value'] );
check( 'one item', 1, count( $unit['items'] ) );
check( 'item category', 'DONATION', $unit['items'][0]['category'] );
check( 'item name', 'Donation', $unit['items'][0]['name'] );
check( 'item quantity is the string 1', '1', $unit['items'][0]['quantity'] );
check( 'item unit_amount equals the gift', $unit['amount']['value'], $unit['items'][0]['unit_amount']['value'] );
check( 'item unit_amount currency', 'JPY', $unit['items'][0]['unit_amount']['currency_code'] );
check( 'breakdown item_total equals the gift', $unit['amount']['value'], $unit['amount']['breakdown']['item_total']['value'] );
check( 'breakdown item_total currency', 'JPY', $unit['amount']['breakdown']['item_total']['currency_code'] );

$unit = npmp_paypal_purchase_unit( '25.00', 'USD' );
check( 'JavaScript literal decodes to the same unit', $unit, js_unit_as_array( '25.00' ) );
$js = npmp_paypal_purchase_unit_js( 'npmpOrderValue', 'USD' );
check( 'every value slot is the bare variable', 3, substr_count( $js, ':npmpOrderValue' ) );
check( 'the variable is never quoted', 0, substr_count( $js, '"npmpOrderValue"' ) );
check( 'no less-than sign for wptexturize to trip on', 0, substr_count( $js, '<' ) );
check( 'a bad variable name is not printed', 0, substr_count( npmp_paypal_purchase_unit_js( 'x);alert(1', 'USD' ), 'alert' ) );

echo "\n== both Smart Buttons forms use it ==\n";
check( 'createOrder calls build from the helper', 2, substr_count( $src, "purchase_units: [<?php echo npmp_paypal_purchase_unit_js( 'npmpOrderValue', \$currency )" ) );
check( 'no hand-built purchase unit left', 0, preg_match_all( '/purchase_units:\s*\[\{/', $src ) );
check( 'each form sets the value variable first', 2, substr_count( $src, 'var npmpOrderValue = ' ) );

echo "\n== settings hooks for Pro ==\n";
$settings = file_get_contents( __DIR__ . '/../includes/npmp-payments-settings.php' );
check( 'PayPal API section fires the fields hook', 1, substr_count( $settings, "do_action( 'npmp_paypal_api_settings_fields' )" ) );
check( 'PayPal API save fires the save hook', 1, substr_count( $settings, "do_action( 'npmp_paypal_api_settings_save' )" ) );
$nonce = strpos( $settings, "wp_verify_nonce( sanitize_text_field( wp_unslash( \$_POST['npmp_payment_gateway_nonce'] ) )" );
check( 'save hook fires after the nonce check', true, false !== $nonce && strpos( $settings, "do_action( 'npmp_paypal_api_settings_save' )" ) > $nonce );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
