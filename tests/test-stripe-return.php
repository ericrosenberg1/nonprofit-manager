<?php
/**
 * Stripe one-time gift finalisation on the donor's return
 * (npmp_maybe_finalize_stripe_donation in includes/payments/npmp-payment-gateways.php).
 *
 * The free plugin has no Stripe webhook for one-time gifts. The single
 * GET /v1/checkout/sessions/{id} made when the donor lands back on the site
 * is the only time the gift is recorded. A 15-minute lock keeps a refresh
 * from recording it twice.
 *
 * The lock used to be released only on a network error. A Stripe 5xx or 429
 * held it for 15 minutes, so the refresh that would have recorded the gift
 * did nothing, and the donor was charged with no donation on file.
 *
 * Session bodies are real Checkout Session JSON text, decoded by the code
 * under test, so the test exercises the same parsing a live response gets.
 *
 * Run: php tests/test-stripe-return.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['npmp_test_options']    = array( 'npmp_stripe_mode' => 'test', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );
$GLOBALS['npmp_test_transients'] = array();
$GLOBALS['npmp_test_http']       = null;
$GLOBALS['npmp_test_http_calls'] = 0;

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
	return false;
}
function get_transient( $key ) {
	return $GLOBALS['npmp_test_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['npmp_test_transients'][ $key ] = $value;
	$GLOBALS['npmp_test_ttls'][ $key ]       = $ttl;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['npmp_test_transients'][ $key ] );
	return true;
}
class WP_Error {
	public function __construct( $code = '', $message = '' ) {}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['npmp_test_http_calls']++;
	$GLOBALS['npmp_test_http_last'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['npmp_test_http'];
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
}

class NPMP_Donation_Manager {
	public $logged      = array();
	public $fail_insert = false;
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
		// The real method returns false when wp_insert_post() hands back a WP_Error.
		if ( $this->fail_insert ) {
			return false;
		}
		$this->logged[] = $data;
		return count( $this->logged );
	}
}

/** Counts members the return handler adds, so a bail-out can be seen to skip it. */
class NPMP_Member_Manager {
	public $added = array();
	private static $instance;
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function get_member_by_email( $email ) {
		return in_array( $email, $this->added, true ) ? (object) array( 'email' => $email ) : null;
	}
	public function add_member( $data ) {
		$this->added[] = $data['email'];
		return count( $this->added );
	}
}

require_once __DIR__ . '/../includes/npmp-currency.php';
require_once __DIR__ . '/../includes/payments/npmp-payment-gateways.php';

/** A paid one-time Checkout Session, as the raw JSON text Stripe returns. */
function paid_session_json( $id ) {
	return '{
  "id": "' . $id . '",
  "object": "checkout.session",
  "amount_subtotal": 2500,
  "amount_total": 2500,
  "currency": "usd",
  "customer_details": {"email": "donor@example.com", "name": "Dana Donor", "address": null, "phone": null, "tax_exempt": "none", "tax_ids": []},
  "customer_email": null,
  "livemode": false,
  "metadata": {"gateway": "stripe", "frequency": "one_time"},
  "mode": "payment",
  "payment_status": "paid",
  "status": "complete"
}';
}

function stripe_reply( $code, $body ) {
	return array( 'response' => array( 'code' => $code ), 'body' => $body );
}

function land( $session_id ) {
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session_id );
	npmp_maybe_finalize_stripe_donation();
}

function lock_held( $session_id ) {
	return false !== get_transient( 'npmp_stripe_fin_' . md5( $session_id ) );
}

$donations = NPMP_Donation_Manager::get_instance();

echo "\n== 1. A paid session is recorded once from Stripe's real JSON ==\n";
$GLOBALS['npmp_test_http'] = stripe_reply( 200, paid_session_json( 'cs_test_ok' ) );
land( 'cs_test_ok' );
check( 'one donation logged', 1, count( $donations->logged ) );
check( 'amount from amount_total (minor units)', 25.0, $donations->logged[0]['amount'] ?? 0.0 );
check( 'currency upper-cased from the session', 'USD', $donations->logged[0]['currency'] ?? '' );
check( 'email from customer_details', 'donor@example.com', $donations->logged[0]['email'] ?? '' );
check( 'transaction id is the session id', 'cs_test_ok', $donations->logged[0]['transaction_id'] ?? '' );
land( 'cs_test_ok' );
check( 'a refresh does not log it again', 1, count( $donations->logged ) );

foreach ( array( 500, 502, 503, 429 ) as $code ) {
	echo "\n== 2. Stripe answers HTTP $code on the return, then recovers ==\n";
	$donations->logged = array();
	$sid               = 'cs_test_blip_' . $code;
	$GLOBALS['npmp_test_http'] = stripe_reply( $code, '{"error":{"message":"An unknown error occurred","type":"api_error"}}' );
	land( $sid );
	check( 'nothing logged while Stripe is failing', 0, count( $donations->logged ) );
	check( 'lock released so a refresh can retry', false, lock_held( $sid ) );

	$GLOBALS['npmp_test_http'] = stripe_reply( 200, paid_session_json( $sid ) );
	land( $sid );
	check( 'the refresh records the gift', 1, count( $donations->logged ) );
}

echo "\n== 3. A network error releases the lock too ==\n";
$donations->logged         = array();
$GLOBALS['npmp_test_http'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
land( 'cs_test_net' );
check( 'lock released after a network error', false, lock_held( 'cs_test_net' ) );

echo "\n== 4. An unpaid session keeps the lock and logs nothing ==\n";
$GLOBALS['npmp_test_http'] = stripe_reply( 200, str_replace( '"payment_status": "paid"', '"payment_status": "unpaid"', paid_session_json( 'cs_test_unpaid' ) ) );
land( 'cs_test_unpaid' );
check( 'nothing logged', 0, count( $donations->logged ) );
check( 'lock kept (a definitive answer)', true, lock_held( 'cs_test_unpaid' ) );
$calls = $GLOBALS['npmp_test_http_calls'];
land( 'cs_test_unpaid' );
check( 'a refresh inside the window does not call Stripe again', $calls, $GLOBALS['npmp_test_http_calls'] );

echo "\n== 5. A session from another integration on the same account is ignored ==\n";
$GLOBALS['npmp_test_http'] = stripe_reply( 200, str_replace( '"gateway": "stripe"', '"gateway": "woocommerce"', paid_session_json( 'cs_test_store' ) ) );
land( 'cs_test_store' );
check( 'store checkout not logged as a donation', 0, count( $donations->logged ) );

echo "\n== 6. The return asks Stripe for exactly this session with the mode's key ==\n";
$GLOBALS['npmp_test_http'] = stripe_reply( 200, paid_session_json( 'cs_test_req' ) );
land( 'cs_test_req' );
$req = $GLOBALS['npmp_test_http_last'];
check( 'GET the session by id', 'https://api.stripe.com/v1/checkout/sessions/cs_test_req', $req['url'] );
check( 'test mode sends the test secret', 'Bearer sk_test_fake', $req['args']['headers']['Authorization'] ?? '' );
$GLOBALS['npmp_test_options'] = array( 'npmp_stripe_mode' => 'live', 'npmp_stripe_live_secret_key' => 'sk_live_fake', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );
$GLOBALS['npmp_test_http']    = stripe_reply( 200, paid_session_json( 'cs_live_req' ) );
land( 'cs_live_req' );
check( 'live mode sends the live secret', 'Bearer sk_live_fake', $GLOBALS['npmp_test_http_last']['args']['headers']['Authorization'] ?? '' );
$GLOBALS['npmp_test_options'] = array( 'npmp_stripe_mode' => 'test', 'npmp_stripe_test_secret_key' => 'sk_test_fake' );

echo "\n== 7. Ids that are not Checkout Session ids never reach Stripe ==\n";
$calls = $GLOBALS['npmp_test_http_calls'];
foreach ( array( '', 'pi_123', 'cs_test/../../v1/charges', 'cs_test_ok?expand=x', 'cs_' ) as $bad ) {
	land( $bad );
}
check( 'no Stripe call for a malformed id', $calls, $GLOBALS['npmp_test_http_calls'] );
$_GET = array( 'npmp_donation' => 'cancel', 'npmp_session_id' => 'cs_test_cancel' );
npmp_maybe_finalize_stripe_donation();
check( 'no Stripe call on a cancel return', $calls, $GLOBALS['npmp_test_http_calls'] );

echo "\n== 8. A zero-decimal currency is not divided by 100 ==\n";
$donations->logged         = array();
$GLOBALS['npmp_test_http'] = stripe_reply( 200, str_replace( '"currency": "usd"', '"currency": "jpy"', paid_session_json( 'cs_test_jpy' ) ) );
land( 'cs_test_jpy' );
check( 'JPY 2500 stays 2500', 2500.0, $donations->logged[0]['amount'] ?? 0.0 );
check( 'currency is JPY', 'JPY', $donations->logged[0]['currency'] ?? '' );

echo "\n== 9. A session with no customer_details email falls back to customer_email ==\n";
$donations->logged = array();
$json              = str_replace(
	array( '"customer_details": {"email": "donor@example.com",', '"customer_email": null' ),
	array( '"customer_details": {"email": null,', '"customer_email": "prefilled@example.com"' ),
	paid_session_json( 'cs_test_fallback' )
);
$GLOBALS['npmp_test_http'] = stripe_reply( 200, $json );
land( 'cs_test_fallback' );
check( 'email from customer_email', 'prefilled@example.com', $donations->logged[0]['email'] ?? '' );

echo "\n== 10. A subscription session leaves the record to Pro's webhook ==\n";
$donations->logged         = array();
$GLOBALS['npmp_test_http'] = stripe_reply( 200, str_replace( '"mode": "payment"', '"mode": "subscription"', paid_session_json( 'cs_test_sub' ) ) );
land( 'cs_test_sub' );
check( 'no donation row from the return', 0, count( $donations->logged ) );
check( 'lock held 30 days to cover a revisit', 30 * DAY_IN_SECONDS, $GLOBALS['npmp_test_ttls'][ 'npmp_stripe_fin_' . md5( 'cs_test_sub' ) ] ?? 0 );

echo "\n== 11. A failed insert releases the lock instead of losing the gift ==\n";
$donations->logged      = array();
$donations->fail_insert = true;
$members                = NPMP_Member_Manager::get_instance();
$members->added         = array();
$GLOBALS['npmp_test_http'] = stripe_reply( 200, str_replace( 'donor@example.com', 'retry@example.com', paid_session_json( 'cs_test_dbfail' ) ) );
land( 'cs_test_dbfail' );
check( 'nothing recorded while the insert fails', 0, count( $donations->logged ) );
check( 'lock released so a refresh can retry', false, lock_held( 'cs_test_dbfail' ) );
check( 'donor not added to members on the failed attempt', array(), $members->added );
$donations->fail_insert = false;
land( 'cs_test_dbfail' );
check( 'the refresh records the gift', 1, count( $donations->logged ) );
check( 'and adds the donor once', array( 'retry@example.com' ), $members->added );
check( 'then holds the 30-day lock', 30 * DAY_IN_SECONDS, $GLOBALS['npmp_test_ttls'][ 'npmp_stripe_fin_' . md5( 'cs_test_dbfail' ) ] ?? 0 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
