<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/stripe-return.php
//
// The one-time Stripe gift return (npmp_maybe_finalize_stripe_donation) on a
// real WordPress with a real $wpdb. Only the network is faked: a
// pre_http_request filter answers api.stripe.com in Stripe's documented
// Checkout Session shape, so WP_Http's response helpers, the real
// NPMP_Donation_Manager and the member table all run as they do live. The
// filter also records the outgoing request so the URL and auth header are
// checked, not assumed.
//
// Covers: a paid gift recorded once with the right amount and currency, a
// Stripe 503 that releases the lock, a failed insert that releases the lock
// instead of thanking the donor and holding it for 30 days (the gift used to
// be lost for good), and a revisit that sends no second thank-you. Leaves no
// donations, members or transients behind. Not run by the pre-push gate (it
// needs MySQL and WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$GLOBALS['rig_errors']      = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

// Stripe, faked at the transport. $rig_reply is [status, body] or a WP_Error.
$rig_reply    = null;
$rig_requests = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$rig_reply, &$rig_requests ) {
		if ( false === strpos( $url, 'api.stripe.com' ) ) {
			return $pre;
		}
		$rig_requests[] = array( 'url' => $url, 'method' => $args['method'] ?? '', 'auth' => $args['headers']['Authorization'] ?? '' );
		if ( is_wp_error( $rig_reply ) ) {
			return $rig_reply;
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json', 'request-id' => 'req_rig' ),
			'body'     => $rig_reply[1],
			'response' => array( 'code' => $rig_reply[0], 'message' => get_status_header_desc( $rig_reply[0] ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );

function rig_session_json( $id, $email, $amount_minor = 2500, $currency = 'usd' ) {
	return wp_json_encode(
		array(
			'id'               => $id,
			'object'           => 'checkout.session',
			'amount_subtotal'  => $amount_minor,
			'amount_total'     => $amount_minor,
			'currency'         => $currency,
			'customer'         => null,
			'customer_details' => array( 'email' => $email, 'name' => 'Rig Donor', 'address' => null, 'phone' => null, 'tax_exempt' => 'none', 'tax_ids' => array() ),
			'customer_email'   => $email,
			'livemode'         => false,
			'metadata'         => array( 'gateway' => 'stripe', 'frequency' => 'one_time' ),
			'mode'             => 'payment',
			'payment_intent'   => 'pi_rig',
			'payment_status'   => 'paid',
			'status'           => 'complete',
		)
	);
}

function rig_land( $session_id ) {
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session_id );
	npmp_maybe_finalize_stripe_donation();
	$_GET = array();
}

$prev_mode = get_option( 'npmp_stripe_mode', null );
$prev_key  = get_option( 'npmp_stripe_test_secret_key', null );
update_option( 'npmp_stripe_mode', 'test' );
update_option( 'npmp_stripe_test_secret_key', 'sk_test_rigdummy' );

$dm      = NPMP_Donation_Manager::get_instance();
$mm      = NPMP_Member_Manager::get_instance();
$run     = wp_rand( 1000, 9999 );
$sid_ok  = "cs_test_rigok{$run}";
$sid_503 = "cs_test_rig503{$run}";
$sid_db  = "cs_test_rigdb{$run}";
$emails  = array( "rig-ok{$run}@example.org", "rig-503{$run}@example.org", "rig-db{$run}@example.org" );

echo "== A paid gift is recorded once ==\n";
$rig_reply = array( 200, rig_session_json( $sid_ok, $emails[0], 4200, 'eur' ) );
rig_land( $sid_ok );
$req = end( $rig_requests );
rig_check( 'GET the session by id', array( 'GET', 'https://api.stripe.com/v1/checkout/sessions/' . $sid_ok ), array( $req['method'], $req['url'] ) );
rig_check( 'test secret in the auth header', 'Bearer sk_test_rigdummy', $req['auth'] );
$row = $dm->find_by_transaction_id( $sid_ok );
rig_check( 'donation row exists', true, (bool) $row );
$row_id = is_object( $row ) ? (int) ( $row->id ?? $row->ID ?? 0 ) : (int) $row;
rig_check( 'amount 42.00', 42.0, (float) get_post_meta( $row_id, NPMP_Donation_Manager::META_AMOUNT, true ) );
rig_check( 'currency EUR', 'EUR', get_post_meta( $row_id, NPMP_Donation_Manager::META_CURRENCY, true ) );
rig_check( 'donor became a member', true, (bool) $mm->get_member_by_email( $emails[0] ) );
$mail_count = count( $mails );
delete_transient( 'npmp_stripe_fin_' . md5( $sid_ok ) ); // As if the lock expired.
rig_land( $sid_ok );
rig_check( 'a revisit after the lock sends no second email', $mail_count, count( $mails ) );

echo "== Stripe 503, then recovery ==\n";
$rig_reply = array( 503, '{"error":{"message":"An unknown error occurred","type":"api_error"}}' );
rig_land( $sid_503 );
rig_check( 'nothing recorded on a 503', false, (bool) $dm->find_by_transaction_id( $sid_503 ) );
rig_check( 'lock released', false, get_transient( 'npmp_stripe_fin_' . md5( $sid_503 ) ) );
$rig_reply = array( 200, rig_session_json( $sid_503, $emails[1] ) );
rig_land( $sid_503 );
rig_check( 'the refresh records it', true, (bool) $dm->find_by_transaction_id( $sid_503 ) );

echo "== The insert fails, then recovers ==\n";
// wp_insert_post( ..., true ) returns WP_Error('empty_content') when this filter says so.
add_filter( 'wp_insert_post_empty_content', $rig_block = function ( $empty, $postarr ) { return 'npmp_donation' === ( $postarr['post_type'] ?? '' ) ? true : $empty; }, 10, 2 );
$rig_reply  = array( 200, rig_session_json( $sid_db, $emails[2] ) );
$mail_count = count( $mails );
rig_land( $sid_db );
remove_filter( 'wp_insert_post_empty_content', $rig_block, 10 );
rig_check( 'nothing recorded', false, (bool) $dm->find_by_transaction_id( $sid_db ) );
rig_check( 'lock released so a refresh retries', false, get_transient( 'npmp_stripe_fin_' . md5( $sid_db ) ) );
rig_check( 'donor not added as a member yet', false, (bool) $mm->get_member_by_email( $emails[2] ) );
rig_check( 'no thank-you sent for an unrecorded gift', $mail_count, count( $mails ) );
rig_land( $sid_db );
rig_check( 'the refresh records it', true, (bool) $dm->find_by_transaction_id( $sid_db ) );
rig_check( 'and adds the member', true, (bool) $mm->get_member_by_email( $emails[2] ) );

// Clean up.
foreach ( array( $sid_ok, $sid_503, $sid_db ) as $sid ) {
	$r = $dm->find_by_transaction_id( $sid );
	$id = is_object( $r ) ? (int) ( $r->id ?? $r->ID ?? 0 ) : (int) $r;
	if ( $id ) {
		wp_delete_post( $id, true );
	}
	delete_transient( 'npmp_stripe_fin_' . md5( $sid ) );
}
foreach ( $emails as $e ) {
	$m = $mm->get_member_by_email( $e );
	if ( $m ) {
		$mm->delete_member( $m->id );
	}
}
null === $prev_mode ? delete_option( 'npmp_stripe_mode' ) : update_option( 'npmp_stripe_mode', $prev_mode );
null === $prev_key ? delete_option( 'npmp_stripe_test_secret_key' ) : update_option( 'npmp_stripe_test_secret_key', $prev_key );

rig_check( 'no PHP notices from the plugins', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
