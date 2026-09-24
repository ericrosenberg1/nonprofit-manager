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

function is_admin() { return false; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return strtolower( trim( (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_verify_nonce( $nonce, $action ) { return 'good' === $nonce; }
function get_option( $key, $default = false ) { return $default; }
function date_i18n( $format ) { return '2026-09-18'; }
function get_transient( $key ) { return $GLOBALS['t_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['t_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['t_transients'][ $key ] ); return true; }
function is_wp_error( $v ) { return false; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['t_http']++;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'payment_status'   => 'paid',
				'mode'             => 'payment',
				'amount_total'     => 2500,
				'customer_details' => array( 'email' => 'donor@example.com', 'name' => 'Dana Donor' ),
				'metadata'         => array( 'frequency' => 'one_time' ),
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
function npmp_paypal_verify_order( $order_id, $amount, &$order_data = null ) {
	$GLOBALS['t_verified']++;
	$order_data = array( 'status' => 'COMPLETED' );
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
	public function log_donation( $data ) {
		if ( isset( $this->by_txn[ $data['transaction_id'] ] ) ) {
			return $this->by_txn[ $data['transaction_id'] ];
		}
		return $this->by_txn[ $data['transaction_id'] ] = 100 + count( $this->by_txn );
	}
	public function log_payment_verification( $data ) {
		$GLOBALS['t_audit_rows']++;
	}
}

// Take the two handlers out of a file that needs WordPress to load.
$src = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
foreach ( array( 'npmp_maybe_finalize_stripe_donation', 'npmp_ajax_log_donation' ) as $fn ) {
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

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
