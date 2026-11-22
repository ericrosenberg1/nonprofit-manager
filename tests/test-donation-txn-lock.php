<?php
/**
 * One donation row per gateway transaction, whatever lands at the same time.
 *
 * NPMP_Donation_Manager::log_donation() dedupes on the gateway's id by reading
 * "is it on file?" and then writing. Two requests for one payment both read
 * "no" and both wrote, so one Stripe gift or one PayPal order became several
 * donation rows and several thank-you emails. The read and the write now sit
 * under one lock per id, and the read skips WordPress's query cache.
 *
 * This loads the real class with WordPress and the lock stubbed, and checks
 * each way the lock can answer. Real processes racing on a real database:
 * tests/rig/donation-race.php.
 *
 * Run: php tests/test-donation-txn-lock.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_posts']      = array(); // post id => transaction id.
$GLOBALS['t_inserts']    = 0;
$GLOBALS['t_insert_ok']  = true;
$GLOBALS['t_query_args'] = array();
$GLOBALS['t_lock_tries'] = array();  // Lock names asked for, in order.
$GLOBALS['t_unlocked']   = array();  // Lock names released, in order.
$GLOBALS['t_lock_plan']  = array();  // One answer per try. Runs out: true.
$GLOBALS['t_on_try']     = null;     // Called before each try, as another request acting in between.
$GLOBALS['t_recorded']   = 0;        // npmp_donation_recorded fired.

class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_email( $v ) { return strtolower( trim( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $key, $default = false ) { return 'npmp_donation_currencies_cache' === $key ? array( 'USD' ) : $default; }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function current_time( $type ) { return 'timestamp' === $type ? 1795000000 : '2026-11-20 12:00:00'; }
function date_i18n( $format, $ts = null ) { return '2026-11-20 12:00:00'; }
function get_gmt_from_date( $date ) { return $date; }
function do_action( $tag, ...$args ) {
	if ( 'npmp_donation_recorded' === $tag ) {
		$GLOBALS['t_recorded']++;
	}
}
function wp_insert_post( $postarr, $wp_error = false ) {
	$GLOBALS['t_inserts']++;
	if ( ! $GLOBALS['t_insert_ok'] ) {
		return new WP_Error();
	}
	$id                        = 500 + count( $GLOBALS['t_posts'] );
	$GLOBALS['t_posts'][ $id ] = $postarr['meta_input']['_npmp_donation_txn_id'] ?? '';
	return $id;
}
function get_posts( $args ) {
	$GLOBALS['t_query_args'] = $args;
	$want                    = $args['meta_query'][0]['value'];
	return array_keys( $GLOBALS['t_posts'], $want, true );
}
function npmp_mail_queue_lock( $name, $ttl = 300 ) {
	if ( $GLOBALS['t_on_try'] ) {
		( $GLOBALS['t_on_try'] )( count( $GLOBALS['t_lock_tries'] ) );
	}
	$GLOBALS['t_lock_tries'][] = $name;
	return $GLOBALS['t_lock_plan'] ? array_shift( $GLOBALS['t_lock_plan'] ) : true;
}
function npmp_mail_queue_unlock( $name ) {
	$GLOBALS['t_unlocked'][] = $name;
}

require_once __DIR__ . '/../includes/npmp-currency.php';
require_once __DIR__ . '/../includes/payments/class-donation-manager.php';

/** Start a case with nothing on file and a free lock. */
function fresh( $lock_plan = array(), $on_try = null ) {
	$GLOBALS['t_posts']      = array();
	$GLOBALS['t_inserts']    = 0;
	$GLOBALS['t_insert_ok']  = true;
	$GLOBALS['t_lock_tries'] = array();
	$GLOBALS['t_unlocked']   = array();
	$GLOBALS['t_lock_plan']  = $lock_plan;
	$GLOBALS['t_on_try']     = $on_try;
	$GLOBALS['t_recorded']   = 0;
}
function gift( $txn ) {
	$GLOBALS['t_created'] = 'unset';
	return NPMP_Donation_Manager::get_instance()->log_donation(
		array( 'email' => 'donor@example.com', 'name' => 'Dana Donor', 'amount' => 25, 'gateway' => 'stripe', 'transaction_id' => $txn, 'currency' => 'USD' ),
		$GLOBALS['t_created']
	);
}

$lock_name = 'donation_txn_' . md5( 'cs_test_one' );

echo "\n== 1. The lock is free ==\n";
fresh();
$id = gift( 'cs_test_one' );
check( 'the gift is written', 500, $id );
check( 'under one lock named for the transaction', array( $lock_name ), $GLOBALS['t_lock_tries'] );
check( 'released after the write', array( $lock_name ), $GLOBALS['t_unlocked'] );
check( 'the dedupe read skips the query cache', false, $GLOBALS['t_query_args']['cache_results'] ?? null );
check( 'the recorded hook fires once', 1, $GLOBALS['t_recorded'] );
check( '$created says this call wrote the row', true, $GLOBALS['t_created'] );

echo "\n== 2. A later replay, nobody else writing ==\n";
$GLOBALS['t_lock_tries'] = array();
$GLOBALS['t_unlocked']   = array();
check( 'the replay gets the first row back', 500, gift( 'cs_test_one' ) );
check( 'nothing new written', 1, $GLOBALS['t_inserts'] );
check( 'the hook does not fire again', 1, $GLOBALS['t_recorded'] );
check( '$created says another call wrote the row', false, $GLOBALS['t_created'] );
check( 'the lock is released', array( $lock_name ), $GLOBALS['t_unlocked'] );

echo "\n== 3. Another request holds the lock and writes the row ==\n";
fresh(
	array( false ),
	function ( $try ) {
		if ( 0 === $try ) {
			$GLOBALS['t_posts'][900] = 'cs_test_one'; // The other request's row lands while we wait.
		}
	}
);
check( 'this request answers false', false, gift( 'cs_test_one' ) );
check( 'and writes nothing', 0, $GLOBALS['t_inserts'] );
check( 'and fires no hook', 0, $GLOBALS['t_recorded'] );
check( 'and $created is false', false, $GLOBALS['t_created'] );
check( 'and releases nothing, since it never held the lock', array(), $GLOBALS['t_unlocked'] );

echo "\n== 4. The other request finishes just as this one gets the lock ==\n";
fresh(
	array( false, true ),
	function ( $try ) {
		if ( 1 === $try ) {
			$GLOBALS['t_posts'][901] = 'cs_test_one'; // Written after our look, before our second try.
		}
	}
);
check( 'a row that appeared while we waited still answers false', false, gift( 'cs_test_one' ) );
check( 'nothing written', 0, $GLOBALS['t_inserts'] );
check( 'the lock we took is released', array( $lock_name ), $GLOBALS['t_unlocked'] );

echo "\n== 4b. Another request wrote the row just before this one took a free lock ==\n";
// No wait, so log_donation() hands back the other request's row. The caller
// must not read that as its own write and thank the donor again.
fresh(
	array(),
	function ( $try ) {
		if ( 0 === $try ) {
			$GLOBALS['t_posts'][902] = 'cs_test_one';
		}
	}
);
check( 'the other request\'s row comes back', 902, gift( 'cs_test_one' ) );
check( 'with $created false', false, $GLOBALS['t_created'] );
check( 'nothing written', 0, $GLOBALS['t_inserts'] );

echo "\n== 5. The lock never frees and no row ever shows up ==\n";
fresh( array_fill( 0, 20, false ) );
$started = microtime( true );
$id      = gift( 'cs_test_one' );
check( 'the gift is written anyway, since the gateway has the money', 500, $id );
check( 'and $created says this call wrote it', true, $GLOBALS['t_created'] );
check( 'after 20 tries', 20, count( $GLOBALS['t_lock_tries'] ) );
check( 'spread over about two seconds', true, microtime( true ) - $started >= 1.9 );
check( 'and the lock it never held is left alone', array(), $GLOBALS['t_unlocked'] );

echo "\n== 6. The write fails ==\n";
fresh();
$GLOBALS['t_insert_ok'] = false;
check( 'a failed insert answers false', false, gift( 'cs_test_one' ) );
check( 'with $created false', false, $GLOBALS['t_created'] );
check( 'and still releases the lock', array( $lock_name ), $GLOBALS['t_unlocked'] );

echo "\n== 7. No transaction id ==\n";
fresh();
$id = NPMP_Donation_Manager::get_instance()->log_donation( array( 'email' => 'donor@example.com', 'amount' => 25, 'gateway' => 'stripe', 'currency' => 'USD' ) );
check( 'the gift is written', 500, $id );
check( 'with no lock asked for', array(), $GLOBALS['t_lock_tries'] );

echo "\n== 8. Two different payments ==\n";
fresh();
gift( 'cs_test_one' );
gift( 'cs_test_two' );
check( 'each takes its own lock', array( $lock_name, 'donation_txn_' . md5( 'cs_test_two' ) ), $GLOBALS['t_lock_tries'] );
check( 'and both are written', 2, $GLOBALS['t_inserts'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
