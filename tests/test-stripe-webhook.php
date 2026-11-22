<?php
/**
 * The one-time gift webhook (includes/payments/npmp-stripe-webhook.php) and
 * the recorder every Stripe gift path shares
 * (npmp_stripe_record_checkout_session() in npmp-payment-gateways.php).
 *
 * A one-time Stripe gift used to be recorded only when the donor's browser
 * came back to the success URL. A donor who paid and closed the tab first
 * left a paid gift with no record and no thank-you. checkout.session.completed
 * now records it, signed with the secret saved in Payment Settings.
 *
 * Covers: the Stripe-Signature check (timestamp tolerance, rolled secrets,
 * v0 and debug entries ignored, a tampered body or wrong secret refused), no
 * secret means 503 and nothing recorded, event filtering (paid, payment mode,
 * this form's metadata, the session's own amount and currency), one row and
 * one thank-you across repeats and a late writer, a failed write that answers
 * 500 for Stripe's retry, and the hand-off to Pro's dispatcher. Real
 * processes on a real database: tests/rig/donation-race.php.
 *
 * Run: php tests/test-stripe-webhook.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

const T_SECRET = 'whsec_test_unit_secret';

$GLOBALS['t_options'] = array( 'npmp_stripe_webhook_secret' => T_SECRET );
$GLOBALS['t_mail']    = array();
$GLOBALS['t_pro']     = array();
$GLOBALS['t_http']    = 0;

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
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
	return strtolower( trim( (string) $v ) );
}
function is_email( $v ) {
	return false !== filter_var( $v, FILTER_VALIDATE_EMAIL );
}
function date_i18n( $format ) {
	return '2026-11-22';
}
function get_bloginfo( $what ) {
	return 'Rig Charity';
}
function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, $d );
}
// Pro active, so free's receipt sends. The receipt counts as the thank-you.
function npmp_is_pro() {
	return true;
}
function npmp_send_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['t_mail'][] = $to;
	return true;
}
function get_transient( $key ) {
	return false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	return true;
}
function delete_transient( $key ) {
	return true;
}
class WP_Error {
	private $msg;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->msg  = $message;
		$this->data = $data;
	}
	public function get_error_message() {
		return $this->msg;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

/** Donation rows keyed on transaction id, with the lock answers the real class gives. */
class NPMP_Donation_Manager {
	public $rows        = array();
	public $fail_insert = false;
	public $late_writer = false; // Another request writes the row just before the lock.
	private static $instance;
	public static function get_instance() {
		return self::$instance ?? ( self::$instance = new self() );
	}
	public function find_by_transaction_id( $id ) {
		return isset( $this->rows[ $id ] ) ? 900 : 0;
	}
	public function log_donation( $data, &$created = null ) {
		$created = false;
		if ( $this->late_writer ) {
			$this->rows[ $data['transaction_id'] ] = array( 'by' => 'another request' ) + $data;
		}
		if ( isset( $this->rows[ $data['transaction_id'] ] ) ) {
			return 900;
		}
		if ( $this->fail_insert ) {
			return false;
		}
		$this->rows[ $data['transaction_id'] ] = $data;
		$created                               = true;
		return 900;
	}
}
class NPMP_Member_Manager {
	public $added = array();
	private static $instance;
	public static function get_instance() {
		return self::$instance ?? ( self::$instance = new self() );
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
require_once __DIR__ . '/../includes/payments/npmp-stripe-webhook.php';

/** Sign a body the way Stripe does. */
function sign( $payload, $secret = T_SECRET, $t = null ) {
	$t = null === $t ? time() : $t;
	return 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, $secret );
}

/** A checkout.session.completed event for a paid one-time gift from the form, as raw JSON. */
function gift_event( $session_id, $changes = array() ) {
	$session = array_replace_recursive(
		array(
			'id'               => $session_id,
			'object'           => 'checkout.session',
			'amount_subtotal'  => 2500,
			'amount_total'     => 2500,
			'currency'         => 'usd',
			'customer_details' => array( 'email' => 'Donor@Example.com', 'name' => 'Dana Donor' ),
			'customer_email'   => null,
			'livemode'         => false,
			'metadata'         => array( 'frequency' => 'one_time', 'gateway' => 'stripe' ),
			'mode'             => 'payment',
			'payment_intent'   => 'pi_test_1',
			'payment_status'   => 'paid',
			'status'           => 'complete',
		),
		$changes
	);
	foreach ( $changes as $key => $value ) {
		if ( null === $value ) {
			unset( $session[ $key ] );
		}
	}
	return json_encode(
		array(
			'id'          => 'evt_' . $session_id,
			'object'      => 'event',
			'api_version' => '2025-03-31.basil',
			'type'        => 'checkout.session.completed',
			'livemode'    => false,
			'data'        => array( 'object' => $session ),
		)
	);
}

/** Stripe's API answer on the donor's return: the session for the id asked about. */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['t_http']++;
	$id = basename( (string) wp_parse_url_path( $url ) );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( json_decode( gift_event( $id ), true )['data']['object'] ) );
}
function wp_parse_url_path( $url ) {
	return parse_url( $url, PHP_URL_PATH );
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'];
}

/** The donor's browser lands on the success URL. */
function stripe_return( $session_id ) {
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session_id );
	npmp_maybe_finalize_stripe_donation();
}

/** Deliver a signed event. */
function deliver( $payload, $sig = null ) {
	return npmp_stripe_webhook_receive( $payload, null === $sig ? sign( $payload ) : $sig );
}

$dm  = NPMP_Donation_Manager::get_instance();
$now = 1795000000;
$pay = '{"id":"evt_1","type":"checkout.session.completed"}';
$sig = function ( $t, $secret = T_SECRET, $body = null ) use ( $pay ) {
	return hash_hmac( 'sha256', $t . '.' . ( null === $body ? $pay : $body ), $secret );
};

echo "\n== 1. Stripe-Signature check ==\n";
check( 'a good signature passes', true, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . $sig( $now ), T_SECRET, $now ) );
check( 'a changed body fails', false, npmp_stripe_webhook_signature_valid( $pay . ' ', 't=' . $now . ',v1=' . $sig( $now ), T_SECRET, $now ) );
check( 'another endpoint\'s secret fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . $sig( $now, 'whsec_other' ), T_SECRET, $now ) );
check( '300 seconds old passes', true, npmp_stripe_webhook_signature_valid( $pay, 't=' . ( $now - 300 ) . ',v1=' . $sig( $now - 300 ), T_SECRET, $now ) );
check( '301 seconds old fails (replay)', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . ( $now - 301 ) . ',v1=' . $sig( $now - 301 ), T_SECRET, $now ) );
check( '301 seconds ahead fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . ( $now + 301 ) . ',v1=' . $sig( $now + 301 ), T_SECRET, $now ) );
check( 'a rolled secret: any one v1 match passes', true, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . $sig( $now, 'whsec_old' ) . ',v1=' . $sig( $now ), T_SECRET, $now ) );
check( 'a correct v0 alone fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v0=' . $sig( $now ), T_SECRET, $now ) );
check( 'Stripe\'s debug entries are ignored', true, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ', v1=' . $sig( $now ) . ',v0=abc,secret=whsec_****PzVt,destination=we_1,payload=a25aefc6', T_SECRET, $now ) );
check( 'a forged secret= entry proves nothing', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . $sig( $now, 'whsec_forged' ) . ',secret=' . T_SECRET, T_SECRET, $now ) );
check( 'no timestamp fails', false, npmp_stripe_webhook_signature_valid( $pay, 'v1=' . $sig( $now ), T_SECRET, $now ) );
check( 'a timestamp that isn\'t digits fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . 'x,v1=' . $sig( $now ), T_SECRET, $now ) );
check( 'no v1 fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now, T_SECRET, $now ) );
check( 'an empty secret fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . hash_hmac( 'sha256', $now . '.' . $pay, '' ), '', $now ) );
check( 'a truncated signature fails', false, npmp_stripe_webhook_signature_valid( $pay, 't=' . $now . ',v1=' . substr( $sig( $now ), 0, 32 ), T_SECRET, $now ) );

echo "\n== 2. What the endpoint refuses ==\n";
$event = gift_event( 'cs_test_refuse' );
check( 'an empty body is 400', 400, deliver( '' )[0] );
check( 'no signature is 400', 400, deliver( $event, '' )[0] );
check( 'a bad signature is 403', 403, deliver( $event, sign( $event, 'whsec_wrong' ) )[0] );
check( 'a stale signature is 403', 403, deliver( $event, sign( $event, T_SECRET, time() - 600 ) )[0] );
check( 'signed junk is 400', 400, deliver( 'not json' )[0] );
check( 'a signed event with no object is 400', 400, deliver( '{"id":"evt_x","type":"checkout.session.completed","data":{}}' )[0] );
check( 'nothing recorded by any refusal', 0, count( $dm->rows ) );

$GLOBALS['t_options'] = array();
$no_secret            = deliver( $event, sign( $event, 'whsec_anything' ) );
check( 'no secret saved: 503, so Stripe keeps the event', 503, $no_secret[0] );
check( 'no secret saved: nothing recorded', 0, count( $dm->rows ) );
$GLOBALS['t_options'] = array( 'npmp_stripe_webhook_secret' => T_SECRET );

echo "\n== 3. A paid one-time gift is recorded once ==\n";
$GLOBALS['t_mail'] = array();
$reply             = deliver( gift_event( 'cs_test_paid' ) );
$row               = $dm->rows['cs_test_paid'] ?? array();
check( 'answers 200', 200, $reply[0] );
check( 'says it recorded the gift', 'Gift recorded.', $reply[1] );
check( 'the row is keyed on the session id', 'cs_test_paid', $row['transaction_id'] ?? '' );
check( 'amount from amount_total', 25.0, $row['amount'] ?? 0.0 );
check( 'currency from the session', 'USD', $row['currency'] ?? '' );
check( 'email from customer_details', 'donor@example.com', $row['email'] ?? '' );
check( 'gateway stripe', 'stripe', $row['gateway'] ?? '' );
check( 'the donor is thanked once', array( 'donor@example.com' ), $GLOBALS['t_mail'] );
check( 'and added as a contact', true, in_array( 'donor@example.com', NPMP_Member_Manager::get_instance()->added, true ) );
$again = deliver( gift_event( 'cs_test_paid' ) );
check( 'Stripe sends it again: 200', 200, $again[0] );
check( 'and says it was already recorded', 'Gift already recorded.', $again[1] );
check( 'no second thank-you', 1, count( $GLOBALS['t_mail'] ) );
check( 'one row', 1, count( array_keys( $dm->rows, $dm->rows['cs_test_paid'], true ) ) );

echo "\n== 4. The session's own amount and currency, never anything else ==\n";
deliver( gift_event( 'cs_test_jpy', array( 'amount_total' => 5000, 'currency' => 'jpy', 'metadata' => array( 'npmp_amount' => '1' ) ) ) );
check( 'yen stays whole', 5000.0, $dm->rows['cs_test_jpy']['amount'] ?? 0.0 );
check( 'currency JPY', 'JPY', $dm->rows['cs_test_jpy']['currency'] ?? '' );
deliver( gift_event( 'cs_test_eur', array( 'amount_total' => 1999, 'currency' => 'eur' ) ) );
check( '19.99 euros', 19.99, $dm->rows['cs_test_eur']['amount'] ?? 0.0 );
deliver( gift_event( 'cs_test_fallback', array( 'customer_details' => array( 'email' => null ), 'customer_email' => 'typed@example.com' ) ) );
check( 'email falls back to customer_email', 'typed@example.com', $dm->rows['cs_test_fallback']['email'] ?? '' );

echo "\n== 5. Events this form didn't make, or that carry no money, record nothing ==\n";
$before = count( $dm->rows );
$mails  = count( $GLOBALS['t_mail'] );
$skips  = array(
	'unpaid (a delayed payment method)' => gift_event( 'cs_test_unpaid', array( 'payment_status' => 'unpaid' ) ),
	'no payment required'               => gift_event( 'cs_test_nopay', array( 'payment_status' => 'no_payment_required' ) ),
	'another store on the account'      => gift_event( 'cs_test_store', array( 'metadata' => array( 'gateway' => 'woocommerce' ) ) ),
	'no metadata'                       => gift_event( 'cs_test_nometa', array( 'metadata' => null ) ),
	'a recurring gift (subscription)'   => gift_event( 'cs_test_sub', array( 'mode' => 'subscription' ) ),
	'a dues join'                       => gift_event( 'cs_test_dues', array( 'metadata' => array( 'gateway' => null, 'npmp_source' => 'dues' ) ) ),
	'a zero total'                      => gift_event( 'cs_test_zero', array( 'amount_total' => 0 ) ),
	'no email'                          => gift_event( 'cs_test_noemail', array( 'customer_details' => array( 'email' => null ) ) ),
	'an id that isn\'t a session'       => gift_event( 'pi_test_notasession' ),
);
foreach ( $skips as $label => $payload ) {
	$reply = deliver( $payload );
	check( "$label: 200, so Stripe stops", 200, $reply[0] );
}
$other = json_encode( array( 'id' => 'evt_pi', 'type' => 'payment_intent.succeeded', 'data' => array( 'object' => array( 'id' => 'pi_1', 'amount' => 2500 ) ) ) );
check( 'another event type: 200', 200, deliver( $other )[0] );
check( 'no row from any of them', $before, count( $dm->rows ) );
check( 'no thank-you from any of them', $mails, count( $GLOBALS['t_mail'] ) );

echo "\n== 6. A failed write answers 500 so Stripe retries ==\n";
$dm->fail_insert = true;
$db_fail         = deliver( gift_event( 'cs_test_dbfail' ) );
check( 'answers 500', 500, $db_fail[0] );
check( 'nothing recorded', false, isset( $dm->rows['cs_test_dbfail'] ) );
check( 'nobody thanked', $mails, count( $GLOBALS['t_mail'] ) );
$dm->fail_insert = false;
check( 'the retry answers 200', 200, deliver( gift_event( 'cs_test_dbfail' ) )[0] );
check( 'and records the gift', true, isset( $dm->rows['cs_test_dbfail'] ) );
check( 'and thanks the donor', $mails + 1, count( $GLOBALS['t_mail'] ) );

echo "\n== 7. Another request wrote the row just before the lock ==\n";
$mails           = count( $GLOBALS['t_mail'] );
$dm->late_writer = true;
$late            = deliver( gift_event( 'cs_test_late' ) );
$dm->late_writer = false;
check( 'answers 200', 200, $late[0] );
check( 'as a gift already recorded', 'Gift already recorded.', $late[1] );
check( 'no thank-you from this request', $mails, count( $GLOBALS['t_mail'] ) );

echo "\n== 8. The donor's return after the webhook sends nothing ==\n";
// The webhook recorded cs_test_paid above. The return finds the row before
// it asks Stripe anything.
$GLOBALS['t_options']['npmp_stripe_mode']            = 'test';
$GLOBALS['t_options']['npmp_stripe_test_secret_key'] = 'sk_test_x';
$GLOBALS['t_http']                                   = 0;
$mails                                               = count( $GLOBALS['t_mail'] );
stripe_return( 'cs_test_paid' );
check( 'no Stripe call', 0, $GLOBALS['t_http'] );
check( 'no second thank-you', $mails, count( $GLOBALS['t_mail'] ) );

echo "\n== 9. The webhook after the donor's return sends nothing ==\n";
$mails = count( $GLOBALS['t_mail'] );
stripe_return( 'cs_test_return_first' );
check( 'the return asks Stripe once', 1, $GLOBALS['t_http'] );
check( 'and records the gift', true, isset( $dm->rows['cs_test_return_first'] ) );
check( 'and thanks the donor', $mails + 1, count( $GLOBALS['t_mail'] ) );
$hook = deliver( gift_event( 'cs_test_return_first' ) );
check( 'the webhook answers 200', 200, $hook[0] );
check( 'as already recorded', 'Gift already recorded.', $hook[1] );
check( 'no second thank-you', $mails + 1, count( $GLOBALS['t_mail'] ) );

echo "\n== 10. With Pro active, every verified event goes to Pro too ==\n";
// Pro's dispatcher, as a stand-in. Defined only now, so every case above ran
// as a site without Pro.
// Inside a block, so PHP defines it here and not when the file loads.
if ( ! function_exists( 'npmp_stripe_process_event' ) ) {
	function npmp_stripe_process_event( $event ) {
		$GLOBALS['t_pro'][] = $event;
		return $GLOBALS['t_pro_answer'];
	}
}
$GLOBALS['t_pro_answer'] = true;
$invoice                 = json_encode( array( 'id' => 'evt_inv', 'type' => 'invoice.payment_succeeded', 'data' => array( 'object' => array( 'id' => 'in_1', 'subscription' => 'sub_1' ) ) ) );
check( 'an invoice event: 200', 200, deliver( $invoice )[0] );
check( 'Pro got it once', 1, count( $GLOBALS['t_pro'] ) );
check( 'as the object Pro\'s own address decodes', 'sub_1', $GLOBALS['t_pro'][0]->data->object->subscription ?? '' );
check( 'an unsigned invoice never reaches Pro', 403, deliver( $invoice, sign( $invoice, 'whsec_wrong' ) )[0] );
check( 'still once', 1, count( $GLOBALS['t_pro'] ) );

$GLOBALS['t_pro_answer'] = false;
$dup                     = deliver( $invoice );
check( 'Pro already claimed the event: 200', 200, $dup[0] );
check( 'and says so', 'Event already processed.', $dup[1] );

$GLOBALS['t_pro_answer'] = new WP_Error( 'npmp_local_write_failed', 'Could not store the next payment date; retry.', array( 'status' => 500 ) );
$pro_fail                = deliver( $invoice );
check( 'Pro asks for a retry: 500', 500, $pro_fail[0] );
check( 'with Pro\'s message', 'Could not store the next payment date; retry.', $pro_fail[1] );

$GLOBALS['t_pro']        = array();
$GLOBALS['t_pro_answer'] = true;
$mails                   = count( $GLOBALS['t_mail'] );
check( 'a gift with Pro: 200', 200, deliver( gift_event( 'cs_test_pro_gift' ) )[0] );
check( 'recorded once', true, isset( $dm->rows['cs_test_pro_gift'] ) );
check( 'thanked once', $mails + 1, count( $GLOBALS['t_mail'] ) );
check( 'and Pro saw the event', 1, count( $GLOBALS['t_pro'] ) );

$GLOBALS['t_pro'] = array();
$dm->fail_insert  = true;
check( 'a gift whose write fails: 500', 500, deliver( gift_event( 'cs_test_pro_fail' ) )[0] );
check( 'and Pro never sees it, so Pro\'s claim stays free for the retry', 0, count( $GLOBALS['t_pro'] ) );
$dm->fail_insert = false;

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
