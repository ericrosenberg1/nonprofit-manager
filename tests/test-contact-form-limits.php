<?php
/**
 * The contact form caps messages per visitor and site-wide (sweep
 * 2026-11-21, F7).
 *
 * Every message mails the site's inbox, and anyone holding the page's nonce
 * can post the form, so a script could flood the inbox. These drive the real
 * handler and the real limiter (includes/npmp-rate-limit.php) with
 * WordPress stubbed.
 *
 * Run: php tests/test-contact-form-limits.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'HOUR_IN_SECONDS', 3600 );

/** Stands in for the exit after wp_safe_redirect(). */
class Npmp_Test_Redirect extends Error {
	public $url;
	public function __construct( $url ) {
		parent::__construct( 'redirect' );
		$this->url = $url;
	}
}

$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
$GLOBALS['t_max']        = array();

function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return strtolower( trim( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $v ) { return (string) $v; }
function home_url( $p = '' ) { return 'https://example.org' . $p; }
function wp_verify_nonce( $nonce, $action ) { return 'good' === $nonce && 'npmp_contact_form' === $action; }
function wp_salt( $scheme = 'auth' ) { return 'salt'; }
function get_transient( $key ) { return $GLOBALS['t_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['t_transients'][ $key ] = $value; return true; }
function apply_filters( $tag, $value, ...$args ) {
	if ( 'npmp_rate_limit_max' === $tag && isset( $GLOBALS['t_max'][ $args[0] ] ) ) {
		return $GLOBALS['t_max'][ $args[0] ];
	}
	return $value;
}
function get_option( $key, $default = false ) { return 'admin_email' === $key ? 'office@example.org' : $default; }
function get_bloginfo( $what ) { return 'Riverside Club'; }
function wp_specialchars_decode( $v, $q = null ) { return $v; }
function add_query_arg( ...$a ) {
	// Both forms WordPress takes: ( array, url ) and ( key, value, url ).
	$args = is_array( $a[0] ) ? $a[0] : array( $a[0] => $a[1] );
	return end( $a ) . '?' . http_build_query( $args );
}
function wp_safe_redirect( $url ) { throw new Npmp_Test_Redirect( $url ); }
function npmp_send_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['t_mail'][] = $subject;
	return true;
}

require_once __DIR__ . '/../includes/npmp-rate-limit.php';

// The handler, the recipient, and the Reply-To helper, out of a file that
// needs WordPress to load.
$src = file_get_contents( __DIR__ . '/../includes/npmp-content-blocks.php' );
foreach ( array( 'npmp_contact_form_recipient', 'npmp_contact_form_reply_to', 'npmp_contact_form_handle' ) as $fn ) {
	$start = strpos( $src, "function $fn(" );
	$end   = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting the handler rather than booting WordPress.
}

/**
 * Post the form from one address and return the status it redirects with.
 *
 * @param string $ip     Visitor address.
 * @param array  $fields Field overrides.
 * @return string success, or the error reason.
 */
function contact_post( $ip, $fields = array() ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	$_POST                  = array_merge(
		array(
			'npmp_contact_nonce'    => 'good',
			'npmp_contact_redirect' => 'https://example.org/contact/',
			'npmp_contact_name'     => 'Sam Sender',
			'npmp_contact_email'    => 'sam@example.com',
			'npmp_contact_subject'  => 'Hello',
			'npmp_contact_message'  => 'A question about the bake sale.',
		),
		$fields
	);
	try {
		npmp_contact_form_handle();
	} catch ( Npmp_Test_Redirect $r ) {
		parse_str( (string) wp_parse_url_query( $r->url ), $q );
		return 'success' === ( $q['npmp_contact'] ?? '' ) ? 'success' : (string) ( $q['npmp_contact_reason'] ?? 'error' );
	}
	return 'no redirect';
}

function wp_parse_url_query( $url ) {
	return (string) parse_url( $url, PHP_URL_QUERY );
}

echo "\n== 1. One visitor ==\n";
$results = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$results[] = contact_post( '203.0.113.5' );
}
check( 'the first 10 messages send', array_fill( 0, 10, 'success' ), array_slice( $results, 0, 10 ) );
check( 'the 11th and 12th get the busy message', array( 'busy', 'busy' ), array_slice( $results, 10 ) );
check( 'the inbox gets 10 emails, no more', 10, count( $GLOBALS['t_mail'] ) );
check( 'another visitor still sends', 'success', contact_post( '198.51.100.7' ) );
check( 'an IPv6 visitor still sends', 'success', contact_post( '2001:db8::1' ) );

echo "\n== 2. What never counts ==\n";
$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
for ( $i = 1; $i <= 15; $i++ ) {
	contact_post( '203.0.113.9', array( 'npmp_contact_message' => '' ) );
	contact_post( '203.0.113.9', array( 'npmp_contact_nonce' => 'bad' ) );
	contact_post( '203.0.113.9', array( 'npmp_contact_website' => 'http://spam.example' ) );
}
check( '45 refused or honeypot posts sent nothing', 0, count( $GLOBALS['t_mail'] ) );
check( 'and left the visitor a full allowance', 'success', contact_post( '203.0.113.9' ) );

echo "\n== 3. The whole site ==\n";
// Many addresses (a botnet, or a form behind a proxy that hides the
// visitor) can't flood the inbox either.
$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
$site                    = array();
for ( $i = 1; $i <= 105; $i++ ) {
	$site[] = contact_post( '192.0.2.' . $i );
}
check( '100 messages from 100 addresses send', 100, count( array_keys( array_slice( $site, 0, 100 ), 'success', true ) ) );
check( 'the 101st address gets the busy message', 'busy', $site[100] );
check( 'the inbox gets 100 emails, no more', 100, count( $GLOBALS['t_mail'] ) );

echo "\n== 4. The caps take the shared filter ==\n";
$GLOBALS['t_transients'] = array();
$GLOBALS['t_mail']       = array();
$GLOBALS['t_max']        = array( 'contact_form' => 2 );
check( 'a site that sets 2: first', 'success', contact_post( '203.0.113.20' ) );
check( 'a site that sets 2: second', 'success', contact_post( '203.0.113.20' ) );
check( 'a site that sets 2: third is busy', 'busy', contact_post( '203.0.113.20' ) );
$GLOBALS['t_max'] = array( 'contact_form' => 0, 'contact_form_site' => 0 );
$off              = array();
for ( $i = 1; $i <= 120; $i++ ) {
	$off[] = contact_post( '203.0.113.21' );
}
check( '0 turns both caps off', 120, count( array_keys( $off, 'success', true ) ) );
$GLOBALS['t_max'] = array();

echo "\n== 5. The visitor reads why ==\n";
check( 'the form has a busy message', true, false !== strpos( $src, "'busy'    => __( 'We got too many messages in a short time. Please try again in an hour.'" ) );
check( 'the limit runs after the fields check', true, strpos( $src, "'fields' ), \$redirect" ) < strpos( $src, "npmp_rate_limit_allows( 'contact_form'" ) );
check( 'the limit runs before the email', true, strpos( $src, "npmp_rate_limit_allows( 'contact_form'" ) < strpos( $src, '$sent = npmp_send_mail(' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
