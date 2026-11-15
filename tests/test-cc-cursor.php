<?php
/**
 * Constant Contact paging (includes/import/constant-contact-api.php).
 *
 * The next-page cursor comes out of parse_str() decoded, and add_query_arg()
 * never encodes a value, so a cursor holding "+" or "=" reached Constant
 * Contact as a different cursor and the import asked for the wrong page.
 *
 * Run: php tests/test-cc-cursor.php
 */

require_once __DIR__ . '/bootstrap.php';

$npmp_cc_requests = array();
$npmp_cc_next     = '';

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Like core: values go in as they are, never encoded.
	 */
	function add_query_arg( $key, $value = null, $url = null ) {
		if ( is_array( $key ) ) {
			$url   = $value;
			$pairs = $key;
		} else {
			$pairs = array( $key => $value );
		}
		$parts = array();
		foreach ( $pairs as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $parts );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		global $npmp_cc_requests, $npmp_cc_next;
		$npmp_cc_requests[] = $url;
		return array(
			'code' => 200,
			'body' => json_encode(
				array(
					'contacts' => array(),
					'_links'   => array( 'next' => array( 'href' => $npmp_cc_next ) ),
				)
			),
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) {
		return $r['code'];
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) {
		return $r['body'];
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

require_once dirname( __DIR__ ) . '/includes/import/constant-contact-api.php';

// Constant Contact sends the cursor percent-encoded in the next link.
$npmp_cc_next = '/v3/contacts?cursor=bGltaXQ9MSZuZXh0PTI%2B%2Bx%3D&limit=100';
$page         = npmp_cc_get_contacts( 'token', 'list-1' );
check( 'the next cursor reads decoded', 'bGltaXQ9MSZuZXh0PTI++x=', $page['cursor'] );

npmp_cc_get_contacts( 'token', 'list-1', $page['cursor'] );
$second = end( $npmp_cc_requests );
check( 'page two sends the cursor encoded again', 1, substr_count( $second, 'cursor=bGltaXQ9MSZuZXh0PTI%2B%2Bx%3D' ) );
parse_str( (string) parse_url( $second, PHP_URL_QUERY ), $sent );
check( 'Constant Contact reads back the same cursor', $page['cursor'], $sent['cursor'] ?? null );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
