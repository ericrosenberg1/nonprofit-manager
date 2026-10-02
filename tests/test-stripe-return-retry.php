<?php
/**
 * A Stripe error on the success return leaves the donor able to retry.
 *
 * npmp_maybe_finalize_stripe_donation() takes a 15-minute lock, then asks
 * Stripe for the Checkout Session. In the free plugin that return visit is
 * the only thing that records a one-time donation. A dropped connection
 * released the lock, but an HTTP error from Stripe (429, 5xx) kept it, so
 * every refresh in the next 15 minutes was ignored and a donor who gave up
 * in that window was never recorded or thanked.
 *
 * This drives the real handler with WordPress, the donation manager and the
 * Stripe API stubbed.
 *
 * Run: php tests/test-stripe-return-retry.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['t_transients']  = array();
$GLOBALS['t_emails']      = 0;
$GLOBALS['t_http']        = 0;
$GLOBALS['t_stripe_code'] = 200; // What Stripe answers the session lookup with.
$GLOBALS['t_log']         = array();

function is_admin() { return false; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return strtolower( trim( (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function get_option( $key, $default = false ) { return $default; }
function date_i18n( $format ) { return '2026-09-21'; }
function get_transient( $key ) { return $GLOBALS['t_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['t_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['t_transients'][ $key ] ); return true; }
function is_wp_error( $v ) { return false; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['t_http']++;
	if ( 200 !== $GLOBALS['t_stripe_code'] ) {
		return array(
			'response' => array( 'code' => $GLOBALS['t_stripe_code'] ),
			'body'     => json_encode( array( 'error' => array( 'message' => 'Try again later' ) ) ),
		);
	}
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
function npmp_stripe_secret_key() { return 'sk_test_x'; }
function npmp_payment_debug_log( $msg ) { $GLOBALS['t_log'][] = $msg; }
function npmp_send_thank_you_email( $args ) { $GLOBALS['t_emails']++; return true; }
function npmp_add_donor_to_membership( $email, $name ) {}

/** Records keyed on transaction id, deduped the way the real manager does. */
class NPMP_Donation_Manager {
	private static $instance;
	public $by_txn  = array();
	public $amounts = array();
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
		$this->amounts[ $data['transaction_id'] ] = $data['amount'];
		return $this->by_txn[ $data['transaction_id'] ] = 100 + count( $this->by_txn );
	}
}

// Take the handler out of a file that needs WordPress to load.
$src   = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
$start = strpos( $src, 'function npmp_maybe_finalize_stripe_donation(' );
$end   = strpos( $src, "\n}", $start ) + 2;
eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting the handler rather than booting WordPress.

function stripe_return( $session_id ) {
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session_id );
	npmp_maybe_finalize_stripe_donation();
}

function lock_held( $session_id ) {
	return false !== get_transient( 'npmp_stripe_fin_' . md5( $session_id ) );
}

$donations = NPMP_Donation_Manager::get_instance();

echo "\n== 1. Stripe rate-limits the lookup ==\n";
$GLOBALS['t_stripe_code'] = 429;
stripe_return( 'cs_test_busy' );
check( 'nothing recorded', 0, $donations->find_by_transaction_id( 'cs_test_busy' ) );
check( 'nothing sent', 0, $GLOBALS['t_emails'] );
check( 'lock released', false, lock_held( 'cs_test_busy' ) );
check( 'status code logged', 'stripe session lookup HTTP 429', end( $GLOBALS['t_log'] ) );

echo "\n== 2. Stripe is down ==\n";
$GLOBALS['t_stripe_code'] = 503;
stripe_return( 'cs_test_busy' );
check( 'lock released on 503', false, lock_held( 'cs_test_busy' ) );
check( 'refresh did call Stripe again', 2, $GLOBALS['t_http'] );

echo "\n== 3. Refresh once Stripe recovers ==\n";
$GLOBALS['t_stripe_code'] = 200;
stripe_return( 'cs_test_busy' );
check( 'donation recorded', true, $donations->find_by_transaction_id( 'cs_test_busy' ) > 0 );
check( 'amount recorded in dollars', 25.0, $donations->amounts['cs_test_busy'] ?? null );
check( 'donor thanked once', 1, $GLOBALS['t_emails'] );
check( 'lock held after success', true, lock_held( 'cs_test_busy' ) );

echo "\n== 4. A refresh inside the lock after success still does nothing ==\n";
stripe_return( 'cs_test_busy' );
check( 'no second Stripe call', 3, $GLOBALS['t_http'] );
check( 'no second thank-you', 1, $GLOBALS['t_emails'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
