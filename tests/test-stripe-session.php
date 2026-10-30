<?php
/**
 * Stripe Checkout Session creation (npmp_ajax_create_stripe_session in
 * includes/payments/npmp-payment-gateways.php), and its round trip into the
 * success-page return that records the gift.
 *
 * The free plugin has no webhook for one-time gifts. The donor's return to
 * success_url is the only place a gift is recorded, and that return trusts
 * three things the creation request has to put on the session: the literal
 * {CHECKOUT_SESSION_ID} placeholder in success_url, metadata[gateway]=stripe,
 * and an amount in the currency's minor unit. Drop any one of them and every
 * donor is charged with nothing on file, while test-stripe-return.php (which
 * feeds the return hand-written session JSON) stays green.
 *
 * The outgoing body is decoded here with parse_str(), the same
 * application/x-www-form-urlencoded decoding Stripe applies. The round-trip
 * case then builds the session Stripe would hand back from what was actually
 * sent, follows the real success_url, and runs the real return handler.
 *
 * Run: php tests/test-stripe-session.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/** Thrown by the wp_send_json_* stubs, standing in for the die() they end in. */
class NPMP_Test_Json_Exit extends Exception {
	public $success;
	public $data;
	public function __construct( $success, $data ) {
		parent::__construct( 'json exit' );
		$this->success = $success;
		$this->data    = $data;
	}
}

$GLOBALS['npmp_test_options']    = array();
$GLOBALS['npmp_test_transients'] = array();
$GLOBALS['npmp_test_is_pro']     = false;
$GLOBALS['npmp_test_rate_ok']    = true;
$GLOBALS['npmp_test_post_reply'] = null;
$GLOBALS['npmp_test_posts']      = array();
$GLOBALS['npmp_test_get_reply']  = null;

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $key ] : $default;
}
function apply_filters( $tag, $value, ...$args ) {
	return $value;
}
function add_action( ...$args ) {}
function is_admin() {
	return false;
}
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_email( $v ) {
	return trim( (string) $v );
}
function is_email( $v ) {
	return false !== filter_var( $v, FILTER_VALIDATE_EMAIL );
}
function date_i18n( $format ) {
	return gmdate( 'Y-m-d' );
}
function npmp_is_pro() {
	return $GLOBALS['npmp_test_is_pro'];
}
function npmp_label( $key ) {
	return 'donation_lc' === $key ? 'donation' : 'Donation';
}
function wp_verify_nonce( $nonce, $action ) {
	return 'good-nonce' === $nonce && 'npmp_stripe_checkout' === $action ? 1 : false;
}
function npmp_rate_limit_allows( ...$args ) {
	return $GLOBALS['npmp_test_rate_ok'];
}
function wp_send_json_success( $data = null ) {
	throw new NPMP_Test_Json_Exit( true, $data );
}
function wp_send_json_error( $data = null ) {
	throw new NPMP_Test_Json_Exit( false, $data );
}
function home_url( $path = '' ) {
	return 'https://give.example.org' . $path;
}
function esc_url_raw( $url ) {
	return preg_match( '#^https?://#i', $url ) ? $url : '';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function remove_query_arg( $keys, $url ) {
	$parts = parse_url( $url );
	if ( empty( $parts['query'] ) ) {
		return $url;
	}
	parse_str( $parts['query'], $q );
	foreach ( (array) $keys as $k ) {
		unset( $q[ $k ] );
	}
	$base = strtok( $url, '?' );
	return $q ? $base . '?' . http_build_query( $q ) : $base;
}
// WordPress's add_query_arg() appends without encoding the value (build_query()
// passes urlencode = false), and so does this stand-in.
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}
function get_transient( $key ) {
	return $GLOBALS['npmp_test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['npmp_test_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['npmp_test_transients'][ $key ] );
	return true;
}
class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
	public function get_error_code() {
		return 'http_request_failed';
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['npmp_test_posts'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['npmp_test_post_reply'];
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['npmp_test_get_last'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['npmp_test_get_reply'];
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
}

class NPMP_Donation_Manager {
	public $logged = array();
	private static $instance;
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function find_by_transaction_id( $id ) {
		foreach ( $this->logged as $row ) {
			if ( $row['transaction_id'] === $id ) {
				return $row;
			}
		}
		return null;
	}
	public function log_donation( $data ) {
		$this->logged[] = $data;
		return count( $this->logged );
	}
}

require_once __DIR__ . '/../includes/npmp-currency.php';
require_once __DIR__ . '/../includes/payments/npmp-payment-gateways.php';

/** A Checkout Session create reply, as the raw JSON text Stripe returns. */
function created_session_json( $id ) {
	return '{"id": "' . $id . '", "object": "checkout.session", "url": "https://checkout.stripe.com/c/pay/' . $id . '", "payment_status": "unpaid", "status": "open"}';
}

function stripe_reply( $code, $body ) {
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}

/**
 * Post the donation form to the AJAX handler.
 *
 * @return array{0: NPMP_Test_Json_Exit|null, 1: array|null} The JSON exit and
 *         the form fields Stripe decoded from the request body, if one was sent.
 */
function create_session( array $post ) {
	$GLOBALS['npmp_test_posts'] = array();
	$_POST                      = $post + array( 'nonce' => 'good-nonce' );
	$exit                       = null;
	try {
		npmp_ajax_create_stripe_session();
	} catch ( NPMP_Test_Json_Exit $e ) {
		$exit = $e;
	}
	$sent = null;
	if ( $GLOBALS['npmp_test_posts'] ) {
		parse_str( $GLOBALS['npmp_test_posts'][0]['args']['body'], $sent );
	}
	return array( $exit, $sent );
}

$GLOBALS['npmp_test_options']    = array( 'npmp_stripe_mode' => 'test', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );
$GLOBALS['npmp_test_post_reply'] = stripe_reply( 200, created_session_json( 'cs_test_new' ) );

echo "\n== 1. A one-time USD gift asks Stripe for what the return will check ==\n";
list( $exit, $sent ) = create_session(
	array( 'amount' => '19.99', 'email' => 'donor@example.com', 'page_url' => 'https://give.example.org/donate/?campaign=fall#form' )
);
$req = $GLOBALS['npmp_test_posts'][0] ?? array();
check( 'POST to the Checkout Sessions endpoint', 'https://api.stripe.com/v1/checkout/sessions', $req['url'] ?? '' );
check( 'test mode sends the test secret', 'Bearer sk_test_fake', $req['args']['headers']['Authorization'] ?? '' );
check( 'form-encoded, as Stripe requires', 'application/x-www-form-urlencoded', $req['args']['headers']['Content-Type'] ?? '' );
check( 'mode is payment', 'payment', $sent['mode'] ?? '' );
check( '19.99 is sent as 1999 cents', '1999', $sent['line_items'][0]['price_data']['unit_amount'] ?? '' );
check( 'currency lower-cased', 'usd', $sent['line_items'][0]['price_data']['currency'] ?? '' );
check( 'metadata gateway is stripe (the return ignores anything else)', 'stripe', $sent['metadata']['gateway'] ?? '' );
check( 'metadata frequency', 'one_time', $sent['metadata']['frequency'] ?? '' );
check( 'card only', array( 'card' ), $sent['payment_method_types'] ?? array() );
check( 'customer_email prefilled', 'donor@example.com', $sent['customer_email'] ?? '' );
check(
	'success_url keeps the page, drops the #fragment, carries the literal placeholder',
	'https://give.example.org/donate/?campaign=fall&npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}',
	$sent['success_url'] ?? ''
);
check( 'cancel_url flags a cancel', 'https://give.example.org/donate/?campaign=fall&npmp_donation=cancelled', $sent['cancel_url'] ?? '' );
check( 'nothing recorded at creation', 0, count( NPMP_Donation_Manager::get_instance()->logged ) );
check( 'the browser gets the session back', 'cs_test_new', $exit && $exit->success ? ( $exit->data['id'] ?? '' ) : '' );

echo "\n== 2. Round trip: Stripe's redirect lands on the return and records the gift ==\n";
// Stripe swaps the placeholder for the session id and redirects there.
$landing = str_replace( '{CHECKOUT_SESSION_ID}', 'cs_test_round', $sent['success_url'] );
parse_str( (string) parse_url( $landing, PHP_URL_QUERY ), $_GET );
// The session Stripe hands back carries exactly what creation sent.
$GLOBALS['npmp_test_get_reply'] = stripe_reply(
	200,
	json_encode(
		array(
			'id'               => 'cs_test_round',
			'object'           => 'checkout.session',
			'amount_total'     => (int) $sent['line_items'][0]['price_data']['unit_amount'],
			'currency'         => $sent['line_items'][0]['price_data']['currency'],
			'customer_details' => array( 'email' => $sent['customer_email'], 'name' => 'Dana Donor' ),
			'customer_email'   => $sent['customer_email'],
			'metadata'         => $sent['metadata'],
			'mode'             => $sent['mode'],
			'payment_status'   => 'paid',
			'status'           => 'complete',
		)
	)
);
npmp_maybe_finalize_stripe_donation();
$logged = NPMP_Donation_Manager::get_instance()->logged;
check( 'the return looked up the session Stripe named', 'https://api.stripe.com/v1/checkout/sessions/cs_test_round', $GLOBALS['npmp_test_get_last']['url'] ?? '' );
check( 'one donation recorded', 1, count( $logged ) );
check( 'for the amount the donor typed', 19.99, $logged[0]['amount'] ?? 0.0 );
check( 'in USD', 'USD', $logged[0]['currency'] ?? '' );
check( 'keyed to the session id', 'cs_test_round', $logged[0]['transaction_id'] ?? '' );

echo "\n== 3. A zero-decimal currency is sent in whole units ==\n";
$GLOBALS['npmp_test_options']['npmp_currency'] = 'JPY';
list( $exit, $sent ) = create_session( array( 'amount' => '500', 'email' => 'donor@example.com' ) );
check( 'JPY 500 is sent as 500, not 50000', '500', $sent['line_items'][0]['price_data']['unit_amount'] ?? '' );
check( 'currency jpy', 'jpy', $sent['line_items'][0]['price_data']['currency'] ?? '' );
check( 'no page_url falls back to the homepage', 'https://give.example.org/?npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}', $sent['success_url'] ?? '' );
unset( $GLOBALS['npmp_test_options']['npmp_currency'] );

echo "\n== 4. An off-site page_url never becomes the return address ==\n";
list( $exit, $sent ) = create_session( array( 'amount' => '25', 'email' => 'donor@example.com', 'page_url' => 'https://evil.example.net/donate/' ) );
check( 'success_url is on this site', 'https://give.example.org/?npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}', $sent['success_url'] ?? '' );

echo "\n== 5. A recurring gift with Pro is a real Stripe subscription ==\n";
$GLOBALS['npmp_test_is_pro'] = true;
list( $exit, $sent )         = create_session( array( 'amount' => '10', 'email' => 'donor@example.com', 'frequency' => 'quarterly' ) );
check( 'mode is subscription', 'subscription', $sent['mode'] ?? '' );
check( 'every 3 months', array( 'interval' => 'month', 'interval_count' => '3' ), $sent['line_items'][0]['price_data']['recurring'] ?? array() );
check( 'subscription stamped for Pro\'s webhook', 'donation', $sent['subscription_data']['metadata']['npmp_source'] ?? '' );
check( 'subscription frequency', 'quarterly', $sent['subscription_data']['metadata']['frequency'] ?? '' );
$GLOBALS['npmp_test_is_pro'] = false;

echo "\n== 6. Refusals never reach Stripe ==\n";
$cases = array(
	'recurring without Pro' => array( 'amount' => '10', 'email' => 'donor@example.com', 'frequency' => 'monthly' ),
	'bad nonce'             => array( 'amount' => '10', 'email' => 'donor@example.com', 'nonce' => 'stale' ),
	'bad email'             => array( 'amount' => '10', 'email' => 'not-an-email' ),
	'below the minimum'     => array( 'amount' => '0.50', 'email' => 'donor@example.com' ),
);
foreach ( $cases as $label => $post ) {
	list( $exit, $sent ) = create_session( $post );
	check( "$label: refused", false, $exit ? $exit->success : null );
	check( "$label: no Stripe call", 0, count( $GLOBALS['npmp_test_posts'] ) );
}
$GLOBALS['npmp_test_rate_ok'] = false;
list( $exit, $sent )          = create_session( array( 'amount' => '10', 'email' => 'donor@example.com' ) );
check( 'rate limited: no Stripe call', 0, count( $GLOBALS['npmp_test_posts'] ) );
$GLOBALS['npmp_test_rate_ok'] = true;
$GLOBALS['npmp_test_options'] = array( 'npmp_stripe_mode' => 'live', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );
list( $exit, $sent )          = create_session( array( 'amount' => '10', 'email' => 'donor@example.com' ) );
check( 'no live key: refused without a Stripe call', array( false, 0 ), array( $exit ? $exit->success : null, count( $GLOBALS['npmp_test_posts'] ) ) );
$GLOBALS['npmp_test_options'] = array( 'npmp_stripe_mode' => 'test', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );

echo "\n== 7. Stripe's documented error answer reaches the donor ==\n";
$GLOBALS['npmp_test_post_reply'] = stripe_reply( 400, '{"error": {"code": "amount_too_small", "message": "Amount must be at least $0.50 usd", "param": "line_items[0][price_data][unit_amount]", "type": "invalid_request_error"}}' );
list( $exit, $sent )             = create_session( array( 'amount' => '10', 'email' => 'donor@example.com' ) );
check( 'refused', false, $exit ? $exit->success : null );
check( 'with Stripe\'s message', 'Stripe error: Amount must be at least $0.50 usd', $exit ? $exit->data : '' );
$GLOBALS['npmp_test_post_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
list( $exit, $sent )             = create_session( array( 'amount' => '10', 'email' => 'donor@example.com' ) );
check( 'network error: refused with a retry message', array( false, 'Could not reach the payment provider. Please try again.' ), array( $exit ? $exit->success : null, $exit ? $exit->data : '' ) );
check( 'nothing recorded by any refusal', 1, count( NPMP_Donation_Manager::get_instance()->logged ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
