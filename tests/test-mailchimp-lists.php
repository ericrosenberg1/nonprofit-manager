<?php
/**
 * Mailchimp audience list paging (includes/import/mailchimp-api.php).
 *
 * Run: php tests/test-mailchimp-lists.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $x ) {
		return $x instanceof WP_Error;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) );
	}
}
$GLOBALS['sent'] = array();
$GLOBALS['fake'] = array();
function wp_remote_get( $url, $args ) {
	$GLOBALS['sent'][] = $url;
	return array_shift( $GLOBALS['fake'] );
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}

require_once dirname( __DIR__ ) . '/includes/import/mailchimp-api.php';

function page( $from, $n, $total ) {
	$lists = array();
	for ( $i = $from; $i < $from + $n; $i++ ) {
		$lists[] = array( 'id' => 'id' . $i, 'name' => 'List ' . $i, 'stats' => array( 'member_count' => $i ) );
	}
	return array( 'code' => 200, 'body' => json_encode( array( 'total_items' => $total, 'lists' => $lists ) ) );
}

$key = str_repeat( 'a', 32 ) . '-us6';

// 250 audiences come over three calls.
$GLOBALS['fake'] = array( page( 0, 100, 250 ), page( 100, 100, 250 ), page( 200, 50, 250 ) );
$GLOBALS['sent'] = array();
$lists           = npmp_mailchimp_get_lists( $key );
check( 'all 250 audiences return', true, is_array( $lists ) && 250 === count( $lists ) );
check( 'three calls', true, 3 === count( $GLOBALS['sent'] ) );
check( 'offsets walk 0, 100, 200', true, false !== strpos( $GLOBALS['sent'][0], 'offset=0' ) && false !== strpos( $GLOBALS['sent'][1], 'offset=100' ) && false !== strpos( $GLOBALS['sent'][2], 'offset=200' ) );
check( 'last audience is kept', true, 'id249' === $lists[249]['id'] && 249 === $lists[249]['member_count'] );

// Exactly one page stops after one call.
$GLOBALS['fake'] = array( page( 0, 100, 100 ) );
$GLOBALS['sent'] = array();
$lists           = npmp_mailchimp_get_lists( $key );
check( 'a full single page makes one call', true, 100 === count( $lists ) && 1 === count( $GLOBALS['sent'] ) );

// An empty account.
$GLOBALS['fake'] = array( array( 'code' => 200, 'body' => json_encode( array( 'total_items' => 0, 'lists' => array() ) ) ) );
check( 'no audiences gives an empty array', true, array() === npmp_mailchimp_get_lists( $key ) );

// A total that overstates stops on an empty page.
$GLOBALS['fake'] = array( page( 0, 100, 500 ), page( 100, 0, 500 ) );
$GLOBALS['sent'] = array();
$lists           = npmp_mailchimp_get_lists( $key );
check( 'an empty page ends the walk', true, 100 === count( $lists ) && 2 === count( $GLOBALS['sent'] ) );

// An error on page two is an error.
$GLOBALS['fake'] = array( page( 0, 100, 250 ), array( 'code' => 401, 'body' => json_encode( array( 'detail' => 'Bad key' ) ) ) );
$r               = npmp_mailchimp_get_lists( $key );
check( 'an error on a later page returns the error', true, is_wp_error( $r ) && 'npmp_mc_api' === $r->get_error_code() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
