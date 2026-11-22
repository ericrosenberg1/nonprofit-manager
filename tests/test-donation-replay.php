<?php
/**
 * A completed donation thanks the donor once.
 *
 * The Stripe success-return handler and the PayPal AJAX logger both record a
 * donation keyed on the gateway's id, and log_donation() dedupes that record.
 * The thank-you email was not deduped: a revisit to a Stripe success URL after
 * its 15-minute lock expired, or a re-posted PayPal order id, emailed the
 * donor again. These drive the real handlers with WordPress, the donation
 * manager and the gateways stubbed.
 *
 * Run: php tests/test-donation-replay.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

/** Stands in for wp_send_json_*(), which exits. Not an Exception, so the handler's catch leaves it alone. */
class Npmp_Test_Json_Exit extends Error {
	public $success;
	public $data;
	public function __construct( $success, $data ) {
		parent::__construct( 'json exit' );
		$this->success = $success;
		$this->data    = $data;
	}
}

$GLOBALS['t_transients'] = array();
$GLOBALS['t_emails']     = 0;
$GLOBALS['t_members']    = 0;
$GLOBALS['t_http']       = 0;
$GLOBALS['t_verified']   = 0;
$GLOBALS['t_audit_rows'] = 0;
$GLOBALS['t_mode']       = 'payment';

function is_admin() { return false; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return strtolower( trim( (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_verify_nonce( $nonce, $action ) { return 'good' === $nonce; }
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function date_i18n( $format ) { return '2026-09-18'; }
function get_transient( $key ) { return $GLOBALS['t_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['t_transients'][ $key ] = $value; $GLOBALS['t_ttls'][ $key ] = $ttl; return true; }
function delete_transient( $key ) { unset( $GLOBALS['t_transients'][ $key ] ); return true; }
function is_wp_error( $v ) { return false; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['t_http']++;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'payment_status'   => 'paid',
				'mode'             => $GLOBALS['t_mode'],
				'amount_total'     => 2500,
				'currency'         => 'usd',
				'customer_details' => array( 'email' => 'donor@example.com', 'name' => 'Dana Donor' ),
				'metadata'         => array( 'frequency' => 'one_time', 'gateway' => 'stripe' ),
			)
		),
	);
}
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
function wp_send_json_success( $data = null ) { throw new Npmp_Test_Json_Exit( true, $data ); }
function wp_send_json_error( $data = null ) { throw new Npmp_Test_Json_Exit( false, $data ); }
function npmp_stripe_secret_key() { return 'sk_test_x'; }
function npmp_payment_debug_log( $msg ) {}
function npmp_send_thank_you_email( $args ) { $GLOBALS['t_emails']++; return true; }
function npmp_add_donor_to_membership( $email, $name ) { $GLOBALS['t_members']++; }
// Every session here is this site's. test-stripe-webhook.php covers the check.
function npmp_stripe_session_is_this_site( $session ) { return true; }
function npmp_paypal_verify_order( $order_id, $amount, &$order_data = null ) {
	$GLOBALS['t_verified']++;
	$order_data = array( 'status' => 'COMPLETED' );
	if ( 'PAYPAL-ORDER-JPY' === $order_id ) {
		$order_data['purchase_units'][0] = array(
			'amount'   => array( 'value' => '101', 'currency_code' => 'JPY' ),
			'payments' => array( 'captures' => array( array( 'amount' => array( 'value' => '101' ) ) ) ),
		);
	}
	return true;
}

/** Records keyed on transaction id, deduped the way the real manager does. */
class NPMP_Donation_Manager {
	private static $instance;
	public $by_txn = array();
	public static function get_instance() {
		return self::$instance ?? ( self::$instance = new self() );
	}
	public function find_by_transaction_id( $txn ) {
		return $this->by_txn[ $txn ] ?? 0;
	}
	public $amounts = array();
	/** When true, log_donation() answers as it does for a request that waited while another one wrote the row. */
	public $lost_race = false;
	/**
	 * When true, another request writes the row between the caller's look and
	 * the lock, and log_donation() hands back that row's id with $created false.
	 */
	public $late_writer = false;
	public function log_donation( $data, &$created = null ) {
		$created = false;
		$this->amounts[ $data['transaction_id'] ] = $data['amount'];
		if ( $this->lost_race ) {
			$this->by_txn[ $data['transaction_id'] ] = 777;
			return false;
		}
		if ( $this->late_writer ) {
			$this->by_txn[ $data['transaction_id'] ] = 888;
		}
		if ( isset( $this->by_txn[ $data['transaction_id'] ] ) ) {
			return $this->by_txn[ $data['transaction_id'] ];
		}
		$created = true;
		return $this->by_txn[ $data['transaction_id'] ] = 100 + count( $this->by_txn );
	}
	public function log_payment_verification( $data ) {
		$GLOBALS['t_audit_rows']++;
	}
}

require_once __DIR__ . '/../includes/npmp-currency.php';

// Take the two handlers out of a file that needs WordPress to load.
$src = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
foreach ( array( 'npmp_maybe_finalize_stripe_donation', 'npmp_stripe_session_gift', 'npmp_stripe_record_checkout_session', 'npmp_ajax_log_donation', 'npmp_paypal_recorded_amount' ) as $fn ) {
	$start = strpos( $src, "function $fn(" );
	$end   = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting handlers rather than booting WordPress.
}

function stripe_return( $session_id ) {
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session_id );
	npmp_maybe_finalize_stripe_donation();
}

function paypal_post( $order_id ) {
	$_POST = array(
		'nonce'          => 'good',
		'email'          => 'donor@example.com',
		'name'           => 'Dana Donor',
		'amount'         => '25',
		'frequency'      => 'one_time',
		'gateway'        => 'paypal_api',
		'transaction_id' => $order_id,
	);
	try {
		npmp_ajax_log_donation();
	} catch ( Npmp_Test_Json_Exit $e ) {
		return $e;
	}
	return null;
}

echo "\n== 1. Stripe success return ==\n";
stripe_return( 'cs_test_one' );
check( 'first visit records the donation', 1, count( NPMP_Donation_Manager::get_instance()->by_txn ) );
check( 'first visit thanks the donor', 1, $GLOBALS['t_emails'] );
stripe_return( 'cs_test_one' );
check( 'refresh inside the lock sends nothing', 1, $GLOBALS['t_emails'] );

// The lock is a transient: it expires, and an object cache flush drops it.
$GLOBALS['t_transients'] = array();
$http_before            = $GLOBALS['t_http'];
stripe_return( 'cs_test_one' );
check( 'revisit after the lock is gone does not re-thank', 1, $GLOBALS['t_emails'] );
check( 'revisit does not re-add to membership', 1, $GLOBALS['t_members'] );
check( 'revisit does not call Stripe again', $http_before, $GLOBALS['t_http'] );

stripe_return( 'cs_test_two' );
check( 'a different session is still thanked', 2, $GLOBALS['t_emails'] );
check( 'bad session id ignored', null, stripe_return( 'not-a-session' ) );
check( 'bad session id sends nothing', 2, $GLOBALS['t_emails'] );

// A subscription session leaves no donation record here (Pro's webhook
// records each invoice), so only the lock stands between a revisit and a
// second thank-you. It has to outlast the 15-minute refresh window.
$GLOBALS['t_mode'] = 'subscription';
stripe_return( 'cs_test_sub' );
check( 'subscription return thanks the donor', 3, $GLOBALS['t_emails'] );
stripe_return( 'cs_test_sub' );
check( 'subscription revisit does not re-thank', 3, $GLOBALS['t_emails'] );
check( 'subscription lock outlasts the refresh window', true, ( $GLOBALS['t_ttls'][ 'npmp_stripe_fin_' . md5( 'cs_test_sub' ) ] ?? 0 ) > DAY_IN_SECONDS );
$GLOBALS['t_mode'] = 'payment';

echo "\n== 2. PayPal AJAX logger ==\n";
$GLOBALS['t_emails'] = 0;
$first               = paypal_post( 'PAYPAL-ORDER-1' );
check( 'first call succeeds', true, $first->success );
check( 'first call verifies with PayPal', 1, $GLOBALS['t_verified'] );
check( 'first call writes one audit row', 1, $GLOBALS['t_audit_rows'] );
check( 'first call thanks the donor', 1, $GLOBALS['t_emails'] );

$replay = paypal_post( 'PAYPAL-ORDER-1' );
check( 'replay still answers success', true, $replay->success );
check( 'replay returns the original donation id', $first->data['donation_id'], $replay->data['donation_id'] );
check( 'replay does not re-verify', 1, $GLOBALS['t_verified'] );
check( 'replay writes no second audit row', 1, $GLOBALS['t_audit_rows'] );
check( 'replay does not re-thank', 1, $GLOBALS['t_emails'] );

$other = paypal_post( 'PAYPAL-ORDER-2' );
check( 'a new order is processed', 2, $GLOBALS['t_verified'] );
check( 'a new order is thanked', 2, $GLOBALS['t_emails'] );

echo "\n== 3. PayPal records what PayPal captured ==\n";
// The browser sends the typed amount. A yen gift typed as 100.5 becomes a
// 101 yen order, and the record used to say 100.5.
$_POST_amount = paypal_post( 'PAYPAL-ORDER-JPY' );
check( 'verified order records the captured amount', 101.0, NPMP_Donation_Manager::get_instance()->amounts['PAYPAL-ORDER-JPY'] );
check( 'unverified order keeps the reported amount', 25.5, npmp_paypal_recorded_amount( 25.5, null ) );
check( 'order without amounts keeps the reported amount', 25.5, npmp_paypal_recorded_amount( 25.5, array( 'status' => 'COMPLETED' ) ) );
check( 'purchase unit amount is used when no capture is listed', 40.0, npmp_paypal_recorded_amount( 25.0, array( 'purchase_units' => array( array( 'amount' => array( 'value' => '40.00' ) ) ) ) ) );
check( 'a zero capture keeps the reported amount', 25.0, npmp_paypal_recorded_amount( 25.0, array( 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'amount' => array( 'value' => '0' ) ) ) ) ) ) ) ) );

echo "\n== 4. Another request records the same payment while this one waits ==\n";
// log_donation() answers false to the request that waited, and the row is on
// file. That request must not thank the donor, and must not report a failure.
$dm                  = NPMP_Donation_Manager::get_instance();
$dm->lost_race       = true;
$GLOBALS['t_emails'] = 0;
$GLOBALS['t_members'] = 0;
stripe_return( 'cs_test_lost' );
check( 'Stripe return: no second thank-you', 0, $GLOBALS['t_emails'] );
check( 'Stripe return: no second membership add', 0, $GLOBALS['t_members'] );
check( 'Stripe return: the lock stays, since the gift is on file', true, (bool) get_transient( 'npmp_stripe_fin_' . md5( 'cs_test_lost' ) ) );

$audit_before = $GLOBALS['t_audit_rows'];
$lost         = paypal_post( 'PAYPAL-ORDER-LOST' );
check( 'PayPal: answers success, as a replay does', true, $lost->success );
check( 'PayPal: with the row the other request wrote', 777, $lost->data['donation_id'] ?? 0 );
check( 'PayPal: no second thank-you', 0, $GLOBALS['t_emails'] );
check( 'PayPal: no second audit row', $audit_before, $GLOBALS['t_audit_rows'] );
$dm->lost_race = false;

echo "\n== 5. A claim with no PayPal order (sweep 2026-11-21, F7) ==\n";
// Both buttons post the order PayPal just captured. A claim with no order
// can't be a real gift, and on a site with no API secret it used to record.
$before = array( $GLOBALS['t_verified'], count( $dm->by_txn ), $GLOBALS['t_emails'], $GLOBALS['t_members'] );
foreach ( array( 'empty' => '', 'spaces' => '   ' ) as $label => $order ) {
	$refused = paypal_post( $order );
	check( "$label order id: refused", false, $refused->success );
	check( "$label order id: the donor sees the verify message", true, false !== strpos( (string) ( $refused->data['message'] ?? '' ), 'could not verify' ) );
}
$_POST = array( 'nonce' => 'good', 'email' => 'donor@example.com', 'name' => 'Dana', 'amount' => '25', 'gateway' => 'paypal_api' );
try {
	npmp_ajax_log_donation();
	$missing = null;
} catch ( Npmp_Test_Json_Exit $e ) {
	$missing = $e;
}
check( 'missing order id: refused', false, $missing->success );
check( 'no claim asked PayPal', $before[0], $GLOBALS['t_verified'] );
check( 'no claim recorded a gift', $before[1], count( $dm->by_txn ) );
check( 'no claim sent a thank-you', $before[2], $GLOBALS['t_emails'] );
check( 'no claim added a member', $before[3], $GLOBALS['t_members'] );

echo "\n== 6. No cap ever drops a verified, paid order ==\n";
// The buttons post each paid order once and ignore our answer, so a refused
// post is a lost gift. 60 orders from one address in one go (a church
// office, a campus, a carrier's shared address) all record.
$recorded = 0;
$thanked  = $GLOBALS['t_emails'];
for ( $i = 1; $i <= 60; $i++ ) {
	$r = paypal_post( 'PAYPAL-BURST-' . $i );
	if ( $r && $r->success && ! empty( $r->data['donation_id'] ) ) {
		$recorded++;
	}
}
check( 'all 60 orders record', 60, $recorded );
check( 'all 60 donors get their thank-you', $thanked + 60, $GLOBALS['t_emails'] );
$handler = substr( $src, strpos( $src, 'function npmp_ajax_log_donation(' ) );
$handler = substr( $handler, 0, strpos( $handler, "\n}" ) );
check( 'the PayPal logger calls no rate limit', false, strpos( $handler, 'npmp_rate_limit_allows' ) );

echo "\n== 7. Another request writes the row between this one's look and the lock ==\n";
// log_donation() takes the lock after the other request let it go, finds that
// request's row, and hands back its id with $created false. Before $created,
// the caller read the id as its own write and thanked the donor a second time.
$dm->late_writer      = true;
$GLOBALS['t_emails']  = 0;
$GLOBALS['t_members'] = 0;
stripe_return( 'cs_test_late' );
check( 'Stripe return: no second thank-you', 0, $GLOBALS['t_emails'] );
check( 'Stripe return: no second membership add', 0, $GLOBALS['t_members'] );
check( 'Stripe return: the row is the other request\'s', 888, $dm->by_txn['cs_test_late'] ?? 0 );

$audit_before = $GLOBALS['t_audit_rows'];
$late         = paypal_post( 'PAYPAL-ORDER-LATE' );
check( 'PayPal: answers success with the other request\'s row', 888, $late->data['donation_id'] ?? 0 );
check( 'PayPal: no second thank-you', 0, $GLOBALS['t_emails'] );
check( 'PayPal: no second audit row', $audit_before, $GLOBALS['t_audit_rows'] );
$dm->late_writer = false;

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
