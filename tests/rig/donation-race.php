<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/donation-race.php [rounds] [returns per round]
//
// One paid Stripe gift, many donor returns in the same instant, on a real
// WordPress with a real database. Each return is its own PHP process, as each
// is its own request on a live site. All of them load WordPress, wait for one
// shared start time, and call npmp_maybe_finalize_stripe_donation() with the
// same session id. Only the network is faked: a pre_http_request filter
// answers api.stripe.com with a paid Checkout Session after 150 ms.
//
// Until the release after 2026.11.25, six returns wrote five or six donation
// rows for the one payment. The transient at the top of the handler is a read
// and then a write, so returns inside about a millisecond of each other all
// passed it, and every later "already recorded?" read in the same request came
// from WordPress's query cache.
//
// The PayPal logger (npmp_ajax_log_donation) had the same fault with a wider
// gap. It reads "already recorded?", asks PayPal about the order (two calls,
// faked here at 250 ms each), and reads again from the query cache, so a
// second post of the same order 300 ms after the first also wrote a row.
//
// Covers: one donation row per session however many returns land together, at
// most one thank-you (Pro sends it, so with Pro off the count is zero), no
// lock row left in the options table, one row and one verification row for a
// PayPal order posted twice 300 ms apart, and a second look in the same
// request that sees a row another request wrote. Leaves no donations, members
// or options behind. Not run by the pre-push gate (it needs WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

// --- One donor return (child process) --------------------------------------
if ( isset( $argv[1] ) && 'return' === $argv[1] ) {
	list( , , $sid, $email, $start_at, $mail_log ) = $argv;
	require $wp_root . '/wp-load.php';
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $sid, $email ) {
			if ( false === strpos( $url, 'api.stripe.com' ) ) {
				return $pre;
			}
			usleep( 150000 );
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'id'               => $sid,
						'object'           => 'checkout.session',
						'amount_total'     => 2500,
						'currency'         => 'usd',
						'customer_details' => array( 'email' => $email, 'name' => 'Race Donor' ),
						'customer_email'   => $email,
						'metadata'         => array( 'gateway' => 'stripe', 'frequency' => 'one_time' ),
						'mode'             => 'payment',
						'payment_status'   => 'paid',
						'status'           => 'complete',
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
	add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) use ( $mail_log ) {
			file_put_contents( $mail_log, ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . "\n", FILE_APPEND | LOCK_EX );
			return true;
		},
		10,
		2
	);
	while ( microtime( true ) < (float) $start_at ) {
		usleep( 100 );
	}
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $sid );
	npmp_maybe_finalize_stripe_donation();
	exit( 0 );
}

// --- One post of a PayPal order (child process) -----------------------------
if ( isset( $argv[1] ) && 'paypal' === $argv[1] ) {
	list( , , $order_id, $email, $start_at, $mail_log ) = $argv;
	require $wp_root . '/wp-load.php';
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $order_id ) {
			if ( false === strpos( $url, 'paypal.com' ) ) {
				return $pre;
			}
			usleep( 250000 );
			$order = array(
				'id'             => $order_id,
				'status'         => 'COMPLETED',
				'purchase_units' => array(
					array(
						'amount'   => array( 'currency_code' => 'USD', 'value' => '25.00' ),
						'payee'    => array( 'merchant_id' => 'RIGMERCHANT01' ),
						'payments' => array( 'captures' => array( array( 'id' => 'CAPRIG1', 'amount' => array( 'currency_code' => 'USD', 'value' => '25.00' ) ) ) ),
					),
				),
			);
			$body = false !== strpos( $url, '/v1/oauth2/token' ) ? array( 'access_token' => 'rig-token', 'token_type' => 'Bearer' ) : $order;
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
	add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) use ( $mail_log ) {
			file_put_contents( $mail_log, ( is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'] ) . "\n", FILE_APPEND | LOCK_EX );
			return true;
		},
		10,
		2
	);
	while ( microtime( true ) < (float) $start_at ) {
		usleep( 100 );
	}
	$_POST = array(
		'nonce'          => wp_create_nonce( 'npmp_donation' ),
		'email'          => $email,
		'name'           => 'Race Donor',
		'amount'         => '25',
		'gateway'        => 'paypal_api',
		'transaction_id' => $order_id,
	);
	npmp_ajax_log_donation();
	exit( 0 );
}

// --- A write from another request, for the same-request read check ---------
if ( isset( $argv[1] ) && 'write' === $argv[1] ) {
	require $wp_root . '/wp-load.php';
	echo (int) NPMP_Donation_Manager::get_instance()->log_donation(
		array( 'email' => $argv[3], 'amount' => 5, 'gateway' => 'stripe', 'transaction_id' => $argv[2] )
	);
	exit( 0 );
}

// --- The run (parent) ------------------------------------------------------
$rounds  = isset( $argv[1] ) ? max( 1, (int) $argv[1] ) : 5;
$returns = isset( $argv[2] ) ? max( 2, (int) $argv[2] ) : 6;

$GLOBALS['rig_errors'] = array();
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

/** Rows on file for a session id, read straight from the database. */
function rig_rows( $sid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $sid ) );
}

/** Lock rows left behind by log_donation(). */
function rig_locks() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'npmp_queue_lock_donation_txn_%'" );
}

$prev_mode = get_option( 'npmp_stripe_mode', null );
$prev_key  = get_option( 'npmp_stripe_test_secret_key', null );
update_option( 'npmp_stripe_mode', 'test' );
update_option( 'npmp_stripe_test_secret_key', 'sk_test_rigdummy' );

$dm       = NPMP_Donation_Manager::get_instance();
$mm       = NPMP_Member_Manager::get_instance();
$run      = wp_rand( 1000, 9999 );
$php      = PHP_BINARY;
$self     = __FILE__;
$mail_log = tempnam( sys_get_temp_dir(), 'npmp-race-mail' );
$sids     = array();
$emails   = array();
$pro_on   = function_exists( 'npmp_is_pro' ) && npmp_is_pro();
global $wpdb;

echo "== $returns returns in the same instant, $rounds rounds, Pro " . ( $pro_on ? 'on' : 'off' ) . " ==\n";
for ( $round = 1; $round <= $rounds; $round++ ) {
	$sid      = "cs_test_rigrace{$run}x{$round}";
	$email    = "rig-race{$run}x{$round}@example.org";
	$sids[]   = $sid;
	$emails[] = $email;
	file_put_contents( $mail_log, '' );

	$start_at = sprintf( '%.4f', microtime( true ) + 2.5 );
	$procs    = array();
	for ( $i = 0; $i < $returns; $i++ ) {
		$procs[] = proc_open(
			array( $php, $self, 'return', $sid, $email, $start_at, $mail_log ),
			array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
			$pipes,
			null,
			array( 'NPMP_RIG_WP' => $wp_root ) + getenv()
		);
	}
	foreach ( $procs as $proc ) {
		proc_close( $proc );
	}

	$to_donor = count( array_filter( file( $mail_log, FILE_IGNORE_NEW_LINES ), function ( $to ) use ( $email ) { return false !== strpos( $to, $email ); } ) );
	rig_check( "round $round: one donation row for the one payment", 1, rig_rows( $sid ) );
	rig_check( "round $round: the donor is thanked " . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $pro_on ? 1 : 0, $to_donor );
}
rig_check( 'no lock row left in the options table', 0, rig_locks() );

echo "\n== One PayPal order posted twice, 300 ms apart ==\n";
$paypal_opts = array( 'npmp_paypal_mode' => 'sandbox', 'npmp_paypal_sandbox_client_id' => 'rig-client', 'npmp_paypal_sandbox_secret' => 'rig-secret' );
$paypal_prev = array();
foreach ( $paypal_opts as $opt => $value ) {
	$paypal_prev[ $opt ] = get_option( $opt, null );
	update_option( $opt, $value );
}
$merchant_opt = 'npmp_paypal_merchant_' . md5( 'sandbox|rig-client' );
$order_id     = "RIGORDER{$run}";
$sids[]       = $order_id;
$emails[]     = "rig-paypal{$run}@example.org";
file_put_contents( $mail_log, '' );
$start_at = microtime( true ) + 2.5;
$procs    = array();
foreach ( array( 0.0, 0.3 ) as $gap ) {
	$procs[] = proc_open(
		array( $php, $self, 'paypal', $order_id, end( $emails ), sprintf( '%.4f', $start_at + $gap ), $mail_log ),
		array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes,
		null,
		array( 'NPMP_RIG_WP' => $wp_root ) + getenv()
	);
}
foreach ( $procs as $proc ) {
	proc_close( $proc );
}
$paypal_email = end( $emails );
$to_donor     = count( array_filter( file( $mail_log, FILE_IGNORE_NEW_LINES ), function ( $to ) use ( $paypal_email ) { return false !== strpos( $to, $paypal_email ); } ) );
rig_check( 'one donation row for the one order', 1, rig_rows( $order_id ) );
rig_check( 'one verification row', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_payment_log WHERE gateway_order_id = %s", $order_id ) ) );
rig_check( 'the donor is thanked ' . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $pro_on ? 1 : 0, $to_donor );
rig_check( 'no lock row left in the options table', 0, rig_locks() );
$wpdb->delete( $wpdb->prefix . 'npmp_payment_log', array( 'gateway_order_id' => $order_id ), array( '%s' ) );
foreach ( $paypal_prev as $opt => $value ) {
	null === $value ? delete_option( $opt ) : update_option( $opt, $value );
}
delete_option( $merchant_opt );
delete_transient( $merchant_opt . '_retry' );

echo "\n== A second look in the same request sees another request's row ==\n";
$sid_read = "cs_test_rigread{$run}";
$sids[]   = $sid_read;
$emails[] = "rig-read{$run}@example.org";
rig_check( 'nothing on file at first', 0, $dm->find_by_transaction_id( $sid_read ) );
$other = (int) shell_exec( 'NPMP_RIG_WP=' . escapeshellarg( $wp_root ) . ' ' . escapeshellarg( $php ) . ' ' . escapeshellarg( $self ) . ' write ' . escapeshellarg( $sid_read ) . ' ' . escapeshellarg( end( $emails ) ) . ' 2>/dev/null' );
rig_check( 'another request recorded the gift', true, $other > 0 );
rig_check( 'the second look finds that row', $other, $dm->find_by_transaction_id( $sid_read ) );
rig_check( 'and log_donation() writes no second row', 1, ( $dm->log_donation( array( 'email' => end( $emails ), 'amount' => 5, 'gateway' => 'stripe', 'transaction_id' => $sid_read ) ) ? rig_rows( $sid_read ) : -1 ) );

// --- Clean up --------------------------------------------------------------
foreach ( $sids as $sid ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $sid ) ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	delete_transient( 'npmp_stripe_fin_' . md5( $sid ) );
}
foreach ( $emails as $email ) {
	$member = $mm->get_member_by_email( $email );
	if ( $member && ! empty( $member->id ) ) {
		$mm->delete_member( (int) $member->id );
	}
}
null === $prev_mode ? delete_option( 'npmp_stripe_mode' ) : update_option( 'npmp_stripe_mode', $prev_mode );
null === $prev_key ? delete_option( 'npmp_stripe_test_secret_key' ) : update_option( 'npmp_stripe_test_secret_key', $prev_key );
unlink( $mail_log );

rig_check( 'no PHP notices from the plugins', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
