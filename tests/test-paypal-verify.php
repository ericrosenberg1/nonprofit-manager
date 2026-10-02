<?php
/**
 * PayPal order verification against PayPal-shaped API answers.
 *
 * npmp_paypal_verify_order() is what stands between a client-reported PayPal
 * order id and a recorded, receipted donation. It asks PayPal for an OAuth
 * token, reads the order back, and checks status, currency, amount and payee.
 * The payee check learns the site's own merchant id by creating an order that
 * is never approved.
 *
 * The gate had no test of any of this. The WordPress rig covers
 * npmp_paypal_payee_is_ours() with a pre-seeded merchant id, but needs MySQL
 * and never runs the HTTP side. This drives the three real functions,
 * extracted from the plugin file, through the responses PayPal's REST API
 * gives: the token grant, GET /v2/checkout/orders/{id} for a captured order,
 * the 201 from POST /v2/checkout/orders, and PayPal's error bodies for a bad
 * client, a missing order and an outage. Only the transport is faked.
 *
 * Run: php tests/test-paypal-verify.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'HOUR_IN_SECONDS', 3600 );

// --- WordPress stubs ---------------------------------------------------------
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_email( $v ) { return trim( (string) $v ); }
function apply_filters( $tag, $value ) { return $value; }
function wp_json_encode( $v ) { return json_encode( $v ); }

$GLOBALS['t_options']    = array();
$GLOBALS['t_transients'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['t_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['t_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['t_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['t_transients'][ $key ] ); return true; }

// --- PayPal transport ----------------------------------------------------------
// $GLOBALS['t_paypal'] maps a route ('token', 'get_order', 'create_order') to
// a response: array( code, body array ) or a WP_Error. Every call is logged.
$GLOBALS['t_paypal'] = array();
$GLOBALS['t_calls']  = array();

function paypal_answer( $route, $url, $args ) {
	$GLOBALS['t_calls'][] = array( 'route' => $route, 'url' => $url, 'args' => $args );
	$canned = $GLOBALS['t_paypal'][ $route ] ?? new WP_Error( 'http_request_failed', 'No canned answer for ' . $route );
	if ( is_wp_error( $canned ) ) {
		return $canned;
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => json_encode( $canned[1] ),
		'response' => array( 'code' => $canned[0], 'message' => '' ),
	);
}
function wp_remote_post( $url, $args = array() ) {
	return paypal_answer( false !== strpos( $url, '/v1/oauth2/token' ) ? 'token' : 'create_order', $url, $args );
}
function wp_remote_get( $url, $args = array() ) { return paypal_answer( 'get_order', $url, $args ); }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

// Real PayPal shapes, trimmed to the fields PayPal always sends.
function token_ok() {
	return array( 200, array( 'scope' => 'https://uri.paypal.com/services/payments/payment', 'access_token' => 'A21AAtest', 'token_type' => 'Bearer', 'app_id' => 'APP-80W284485P519543T', 'expires_in' => 32400, 'nonce' => '2026-09-25T00:00:00Z' ) );
}
function captured_order( $value = '25.00', $currency = 'USD', $merchant = 'OURMERCHANT1', $email = 'giving@example.org', $status = 'COMPLETED' ) {
	return array(
		200,
		array(
			'id'             => '5O190127TN364715T',
			'intent'         => 'CAPTURE',
			'status'         => $status,
			'purchase_units' => array(
				array(
					'reference_id' => 'default',
					'amount'       => array( 'currency_code' => $currency, 'value' => $value ),
					'payee'        => array( 'email_address' => $email, 'merchant_id' => $merchant ),
				),
			),
			'payer'          => array( 'email_address' => 'donor@example.com', 'payer_id' => 'QYR5Z8XDVJNXQ' ),
		),
	);
}
function created_order( $merchant = 'OURMERCHANT1' ) {
	return array(
		201,
		array(
			'id'             => '7XS70547FW3190234',
			'intent'         => 'CAPTURE',
			'status'         => 'CREATED',
			'purchase_units' => array(
				array(
					'reference_id' => 'default',
					'amount'       => array( 'currency_code' => 'USD', 'value' => '1.00' ),
					'payee'        => array( 'email_address' => 'giving@example.org', 'merchant_id' => $merchant ),
				),
			),
		),
	);
}

// Take the three functions out of a file that needs WordPress to load.
$src = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
foreach ( array( 'npmp_paypal_verify_order', 'npmp_paypal_payee_is_ours', 'npmp_paypal_own_merchant_id' ) as $fn ) {
	$start = strpos( $src, "function {$fn}(" );
	$end   = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting the functions rather than booting WordPress.
}

function reset_site( $mode = 'live' ) {
	$GLOBALS['t_options']    = array(
		'npmp_paypal_mode'              => $mode,
		'npmp_paypal_live_client_id'    => 'LIVECLIENT',
		'npmp_paypal_live_secret'       => 'livesecret',
		'npmp_paypal_sandbox_client_id' => 'SANDCLIENT',
		'npmp_paypal_sandbox_secret'    => 'sandsecret',
	);
	$GLOBALS['t_transients'] = array();
	$GLOBALS['t_calls']      = array();
	$GLOBALS['t_paypal']     = array( 'token' => token_ok(), 'create_order' => created_order(), 'get_order' => captured_order() );
}

function verify( $order_id = '5O190127TN364715T', $amount = 25 ) {
	$data   = 'untouched';
	$result = npmp_paypal_verify_order( $order_id, $amount, $data );
	return array( is_wp_error( $result ) ? $result->get_error_code() : $result, $data );
}

/** How many requests went to one route. */
function calls( $route ) {
	return count( array_filter( $GLOBALS['t_calls'], function ( $c ) use ( $route ) { return $c['route'] === $route; } ) );
}

$merchant_option = 'npmp_paypal_merchant_' . md5( 'live|LIVECLIENT' );

echo "\n== 1. No API secret: legacy unverified path ==\n";
reset_site();
unset( $GLOBALS['t_options']['npmp_paypal_live_secret'] );
list( $r, $data ) = verify();
check( 'accepted without verification', true, $r );
check( 'order data left null so the caller knows', null, $data );
check( 'PayPal never called', 0, count( $GLOBALS['t_calls'] ) );

echo "\n== 2. Order id is sanitized before it reaches a URL ==\n";
reset_site();
list( $r ) = verify( '../../v1/identity' );
check( 'path characters stripped from the order URL', 'https://api-m.paypal.com/v2/checkout/orders/v1identity', $GLOBALS['t_calls'][1]['url'] );
reset_site();
list( $r ) = verify( '<>!' );
check( 'nothing left: refused', 'npmp_paypal_no_order', $r );
check( 'nothing left: PayPal never called', 0, count( $GLOBALS['t_calls'] ) );

echo "\n== 3. PayPal refuses the credentials ==\n";
reset_site();
$GLOBALS['t_paypal']['token'] = array( 401, array( 'error' => 'invalid_client', 'error_description' => 'Client Authentication failed' ) );
list( $r, $data ) = verify();
check( '401 invalid_client: refused', 'npmp_paypal_auth', $r );
check( 'order never fetched', 0, calls( 'get_order' ) );
check( 'order data null on failure', null, $data );
$token_call = $GLOBALS['t_calls'][0];
check( 'token request uses HTTP Basic client:secret', 'Basic ' . base64_encode( 'LIVECLIENT:livesecret' ), $token_call['args']['headers']['Authorization'] );
check( 'token request asks for client_credentials', 'grant_type=client_credentials', $token_call['args']['body'] );

echo "\n== 4. PayPal unreachable ==\n";
reset_site();
$GLOBALS['t_paypal']['token'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
list( $r ) = verify();
check( 'network failure on token passes through', 'http_request_failed', $r );
reset_site();
$GLOBALS['t_paypal']['get_order'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
list( $r ) = verify();
check( 'network failure on order passes through', 'http_request_failed', $r );

echo "\n== 5. The order is not a completed payment ==\n";
reset_site();
$GLOBALS['t_paypal']['get_order'] = array( 404, array( 'name' => 'RESOURCE_NOT_FOUND', 'details' => array( array( 'issue' => 'INVALID_RESOURCE_ID' ) ), 'message' => 'The specified resource does not exist.' ) );
list( $r ) = verify();
check( 'unknown order id (404): refused', 'npmp_paypal_not_completed', $r );
reset_site();
$GLOBALS['t_paypal']['get_order'] = captured_order( '25.00', 'USD', 'OURMERCHANT1', 'giving@example.org', 'APPROVED' );
list( $r ) = verify();
check( 'approved but never captured: refused', 'npmp_paypal_not_completed', $r );
reset_site();
$GLOBALS['t_paypal']['get_order'] = array( 503, array( 'name' => 'INTERNAL_SERVER_ERROR', 'message' => 'An internal server error occurred.' ) );
list( $r ) = verify();
check( 'PayPal 503 on the order: refused, not recorded', 'npmp_paypal_not_completed', $r );

echo "\n== 6. Currency and amount ==\n";
reset_site();
$GLOBALS['t_paypal']['get_order'] = captured_order( '25.00', 'JPY' );
list( $r ) = verify();
check( '25 JPY is not a $25 gift', 'npmp_paypal_currency_mismatch', $r );
reset_site();
$GLOBALS['t_paypal']['get_order'] = captured_order( '1.00' );
list( $r ) = verify( '5O190127TN364715T', 25 );
check( 'paid $1, claimed $25: refused', 'npmp_paypal_amount_mismatch', $r );
reset_site();
$GLOBALS['t_paypal']['get_order'] = captured_order( '24.99' );
list( $r ) = verify( '5O190127TN364715T', 25 );
check( 'one cent short: refused', 'npmp_paypal_amount_mismatch', $r );

echo "\n== 7. Payee: the site learns its own merchant id ==\n";
reset_site();
list( $r, $data ) = verify();
check( 'own payee: verified', true, $r );
check( 'order data handed back', 'COMPLETED', $data['status'] ?? null );
check( 'merchant id learned from a created order', 'OURMERCHANT1', get_option( $merchant_option ) );
check( 'learning order is created with the bearer token', 'Bearer A21AAtest', $GLOBALS['t_calls'][2]['args']['headers']['Authorization'] ?? null );
check( 'retry marker cleared after learning', false, get_transient( $merchant_option . '_retry' ) );
$GLOBALS['t_calls'] = array();
list( $r ) = verify();
check( 'second verify: still verified', true, $r );
check( 'second verify: no second learning order', 0, calls( 'create_order' ) );

reset_site();
$GLOBALS['t_paypal']['get_order'] = captured_order( '25.00', 'USD', 'ATTACKER9999' );
list( $r, $data ) = verify();
check( 'paid to another merchant: refused', 'npmp_paypal_wrong_payee', $r );
check( 'order data null on wrong payee', null, $data );

echo "\n== 8. Payee: PayPal cannot tell us our merchant id ==\n";
reset_site();
$GLOBALS['t_paypal']['create_order'] = array( 422, array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'PAYEE_ACCOUNT_RESTRICTED' ) ) ) );
$GLOBALS['t_options']['npmp_paypal_email'] = 'Giving@Example.org';
list( $r ) = verify();
check( 'falls back to the saved email, case-insensitive', true, $r );
check( 'nothing cached from an error body', false, get_option( $merchant_option, false ) );
check( 'retry marker set so PayPal is asked at most hourly', 1, get_transient( $merchant_option . '_retry' ) );
$GLOBALS['t_paypal']['get_order'] = captured_order( '25.00', 'USD', 'ATTACKER9999', 'thief@example.net' );
$GLOBALS['t_calls'] = array();
list( $r ) = verify();
check( 'other email: refused', 'npmp_paypal_wrong_payee', $r );
check( 'inside the hour: no second learning order', 0, calls( 'create_order' ) );

reset_site();
$GLOBALS['t_paypal']['create_order'] = array( 201, array( 'id' => 'X', 'purchase_units' => array( array( 'payee' => array( 'merchant_id' => 'bad id!' ) ) ) ) );
$GLOBALS['t_options']['npmp_paypal_email'] = 'giving@example.org';
list( $r ) = verify();
check( 'malformed merchant id is not cached', false, get_option( $merchant_option, false ) );
check( 'and the email fallback still decides', true, $r );

echo "\n== 9. Sandbox mode ==\n";
reset_site( 'sandbox' );
list( $r ) = verify();
check( 'sandbox: verified', true, $r );
check( 'sandbox: token from the sandbox host', 'https://api-m.sandbox.paypal.com/v1/oauth2/token', $GLOBALS['t_calls'][0]['url'] );
check( 'sandbox: sandbox credentials', 'Basic ' . base64_encode( 'SANDCLIENT:sandsecret' ), $GLOBALS['t_calls'][0]['args']['headers']['Authorization'] );
check( 'sandbox: merchant cached per mode and client', 'OURMERCHANT1', get_option( 'npmp_paypal_merchant_' . md5( 'sandbox|SANDCLIENT' ) ) );
check( 'sandbox: live cache untouched', false, get_option( $merchant_option, false ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
