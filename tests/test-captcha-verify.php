<?php
/**
 * CAPTCHA verification against the providers' documented siteverify answers.
 *
 * npmp_captcha_verify() is what stands between a form post and a stored
 * contact, a newsletter signup or an unsubscribe. The free plugin had no test
 * of either provider: a change that made Turnstile fail open, or sent the
 * wrong field names to Cloudflare, would have passed the gate.
 *
 * This drives the real npmp-captcha.php, npmp-turnstile.php and
 * npmp-recaptcha.php with only the transport faked. Every outgoing request is
 * recorded so the URL and the posted fields are checked, not assumed, and
 * each answer is the shape the provider documents:
 *   Cloudflare: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 *   Google:     https://developers.google.com/recaptcha/docs/v3 ("Site verify response")
 *
 * Run: php tests/test-captcha-verify.php
 */

require_once __DIR__ . '/bootstrap.php';

// --- WordPress stubs ---------------------------------------------------------
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

$GLOBALS['t_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default; }

$GLOBALS['t_is_pro'] = false;
function npmp_is_pro() { return $GLOBALS['t_is_pro']; }

// --- Transport -----------------------------------------------------------------
// $GLOBALS['t_reply'] is array( http code, body array|string ) or a WP_Error.
// Every call lands in $GLOBALS['t_calls'] with the URL and the posted body.
$GLOBALS['t_reply'] = null;
$GLOBALS['t_calls'] = array();
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['t_calls'][] = array( 'url' => $url, 'args' => $args );
	$reply = $GLOBALS['t_reply'];
	if ( is_wp_error( $reply ) ) {
		return $reply;
	}
	if ( null === $reply ) {
		return new WP_Error( 'http_request_failed', 'No canned answer set by the test.' );
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => is_string( $reply[1] ) ? $reply[1] : json_encode( $reply[1] ),
		'response' => array( 'code' => $reply[0], 'message' => '' ),
	);
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) $r['response']['code'] : ''; }

require_once __DIR__ . '/../includes/npmp-captcha.php';

// --- Provider answers, as documented ----------------------------------------------
function turnstile_ok() {
	return array( 200, array( 'success' => true, 'challenge_ts' => '2026-10-23T15:14:30.096Z', 'hostname' => 'example.org', 'error-codes' => array(), 'action' => 'contact', 'cdata' => '', 'metadata' => array( 'ephemeral_id' => 'x:9f78e0ed210960d7693b167e' ) ) );
}
function turnstile_fail( $codes = array( 'invalid-input-response' ) ) {
	return array( 200, array( 'success' => false, 'error-codes' => $codes, 'messages' => array() ) );
}
function recaptcha_ok( $score = 0.9 ) {
	return array( 200, array( 'success' => true, 'score' => $score, 'action' => 'submit', 'challenge_ts' => '2026-10-23T15:14:30Z', 'hostname' => 'example.org' ) );
}
function recaptcha_fail( $codes = array( 'invalid-input-response' ) ) {
	return array( 200, array( 'success' => false, 'error-codes' => $codes ) );
}

function reset_world( $options = array() ) {
	$GLOBALS['t_options'] = $options;
	$GLOBALS['t_reply']   = null;
	$GLOBALS['t_calls']   = array();
	$GLOBALS['t_is_pro']  = false;
	$_POST                = array();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
}
function turnstile_site() {
	return array( 'npmp_captcha_provider' => 'turnstile', 'npmp_turnstile_enabled' => 1, 'npmp_turnstile_site_key' => '0x4AAAAAAASiteKey', 'npmp_turnstile_secret_key' => '0x4AAAAAAASecretKeyXXXXXXXXXXXXXX' );
}
function recaptcha_site() {
	return array( 'npmp_captcha_provider' => 'recaptcha', 'npmp_recaptcha_site_key' => str_repeat( 'S', 40 ), 'npmp_recaptcha_secret_key' => str_repeat( 'K', 40 ) );
}
function last_body() {
	$calls = $GLOBALS['t_calls'];
	return $calls ? $calls[ count( $calls ) - 1 ]['args']['body'] : null;
}

echo "\n== 1. Nothing configured: no provider, no verification, no request ==\n";
reset_world();
check( 'provider defaults to none', 'none', npmp_captcha_get_provider() );
check( 'captcha not enabled', false, npmp_captcha_is_enabled() );
check( 'verify passes with no provider', true, npmp_captcha_verify( 'contact_form' ) );
reset_world( array( 'npmp_captcha_provider' => 'hcaptcha' ) );
check( 'an unknown provider value reads as none', 'none', npmp_captcha_get_provider() );
check( 'unknown provider: verify passes', true, npmp_captcha_verify() );
check( 'no request was made', 0, count( $GLOBALS['t_calls'] ) );

echo "\n== 2. Turnstile: a half-configured site is not enabled ==\n";
reset_world( array( 'npmp_captcha_provider' => 'turnstile', 'npmp_turnstile_enabled' => 1, 'npmp_turnstile_site_key' => 'site' ) );
check( 'missing secret: not configured', false, npmp_turnstile_is_configured() );
check( 'missing secret: not enabled', false, npmp_turnstile_enabled() );
check( 'missing secret: captcha not enabled', false, npmp_captcha_is_enabled() );
reset_world( array_merge( turnstile_site(), array( 'npmp_turnstile_enabled' => 0 ) ) );
check( 'keys present but toggle off: not enabled', false, npmp_turnstile_enabled() );
reset_world( array_merge( turnstile_site(), array( 'npmp_turnstile_enabled' => '1' ) ) );
check( 'toggle stored as the string "1" still counts', true, npmp_turnstile_enabled() );

echo "\n== 3. Turnstile: a passing token, and what Cloudflare is asked ==\n";
reset_world( turnstile_site() );
$GLOBALS['t_reply'] = turnstile_ok();
$_POST['cf-turnstile-response'] = '0.abc123.token';
check( 'verify returns true', true, npmp_captcha_verify( 'email_signup' ) );
check( 'exactly one request', 1, count( $GLOBALS['t_calls'] ) );
check( 'posted to the siteverify endpoint', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $GLOBALS['t_calls'][0]['url'] );
$body = last_body();
check( 'secret is the stored secret key', '0x4AAAAAAASecretKeyXXXXXXXXXXXXXX', $body['secret'] );
check( 'response is the posted token', '0.abc123.token', $body['response'] );
check( 'remoteip is the visitor address', '203.0.113.7', $body['remoteip'] );
check( 'request has a timeout', 10, $GLOBALS['t_calls'][0]['args']['timeout'] );
check( 'only the three documented fields are sent', array( 'secret', 'response', 'remoteip' ), array_keys( $body ) );

echo "\n== 4. Turnstile fails closed ==\n";
reset_world( turnstile_site() );
check( 'no token: false', false, npmp_turnstile_verify() );
check( 'no token: no request', 0, count( $GLOBALS['t_calls'] ) );
$_POST['cf-turnstile-response'] = '   ';
check( 'whitespace token: false', false, npmp_turnstile_verify() );
check( 'whitespace token: no request', 0, count( $GLOBALS['t_calls'] ) );

$_POST['cf-turnstile-response'] = 'tok';
$GLOBALS['t_reply'] = turnstile_fail();
check( 'success:false with invalid-input-response: false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = turnstile_fail( array( 'timeout-or-duplicate' ) );
check( 'a replayed token (timeout-or-duplicate): false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
check( 'network failure: false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = array( 503, '<html>Service Unavailable</html>' );
check( 'Cloudflare 503: false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = array( 500, array( 'success' => true ) );
check( 'success:true on a non-200 status is not trusted', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = array( 200, 'not json' );
check( 'unreadable 200 body: false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = array( 200, array( 'challenge_ts' => '2026-10-23T15:14:30.096Z' ) );
check( 'a 200 with no success field: false', false, npmp_turnstile_verify() );
$GLOBALS['t_reply'] = array( 200, array( 'success' => 'false' ) );
check( 'the string "false" is truthy in PHP and still passes', true, npmp_turnstile_verify() );

echo "\n== 5. Turnstile: the custom field name ==\n";
reset_world( turnstile_site() );
$GLOBALS['t_reply'] = turnstile_ok();
$_POST['my-field'] = 'tok2';
check( 'token read from the named field', true, npmp_turnstile_verify( 'my-field' ) );
check( 'that token was sent', 'tok2', last_body()['response'] );
check( 'default field name is cf-turnstile-response', false, npmp_turnstile_verify() );

echo "\n== 6. Turnstile: testing the keys from settings ==\n";
reset_world( array( 'npmp_turnstile_site_key' => 'site' ) );
$r = npmp_turnstile_test_keys();
check( 'missing secret: WP_Error', true, is_wp_error( $r ) );
check( 'missing secret: code', 'npmp-turnstile-missing', $r->get_error_code() );
check( 'missing secret: no request', 0, count( $GLOBALS['t_calls'] ) );

reset_world( turnstile_site() );
$GLOBALS['t_reply'] = turnstile_fail( array( 'invalid-input-response' ) );
check( 'a bad token with a good secret is a pass (the secret is what is under test)', true, npmp_turnstile_test_keys() );
check( 'the probe token is sent', 'npmp-test-token', last_body()['response'] );
check( 'the stored secret is sent', '0x4AAAAAAASecretKeyXXXXXXXXXXXXXX', last_body()['secret'] );

$GLOBALS['t_reply'] = turnstile_fail( array( 'invalid-input-secret' ) );
$r = npmp_turnstile_test_keys();
check( 'invalid-input-secret: WP_Error', true, is_wp_error( $r ) );
check( 'invalid-input-secret: code names the secret', 'npmp-turnstile-secret', $r->get_error_code() );
$GLOBALS['t_reply'] = turnstile_fail( array( 'missing-input-secret' ) );
check( 'missing-input-secret: same error', 'npmp-turnstile-secret', npmp_turnstile_test_keys()->get_error_code() );
$GLOBALS['t_reply'] = new WP_Error( 'http_request_failed', 'cURL error 6' );
check( 'unreachable: request error', 'npmp-turnstile-request', npmp_turnstile_test_keys()->get_error_code() );
$GLOBALS['t_reply'] = array( 502, '<html>Bad Gateway</html>' );
check( 'HTML body: invalid-response error', 'npmp-turnstile-invalid', npmp_turnstile_test_keys()->get_error_code() );
$GLOBALS['t_reply'] = turnstile_ok();
check( 'success:true: pass', true, npmp_turnstile_test_keys() );

echo "\n== 7. reCAPTCHA is a Pro provider: free never verifies it ==\n";
reset_world( recaptcha_site() );
check( 'keys configured', true, npmp_recaptcha_is_configured() );
check( 'free site: not enabled', false, npmp_recaptcha_enabled() );
check( 'free site: captcha not enabled', false, npmp_captcha_is_enabled() );
$_POST['g-recaptcha-response'] = 'tok';
check( 'free site: verify passes without a request', true, npmp_captcha_verify( 'contact_form' ) );
check( 'free site: no request', 0, count( $GLOBALS['t_calls'] ) );

echo "\n== 8. reCAPTCHA v3 on Pro: a passing score, and what Google is asked ==\n";
reset_world( recaptcha_site() );
$GLOBALS['t_is_pro'] = true;
check( 'Pro site: enabled', true, npmp_captcha_is_enabled() );
$GLOBALS['t_reply'] = recaptcha_ok( 0.9 );
$_POST['g-recaptcha-response'] = '03AGdBq24token';
check( 'score 0.9 passes the default 0.5 threshold', true, npmp_captcha_verify( 'email_signup' ) );
check( 'posted to Google siteverify', 'https://www.google.com/recaptcha/api/siteverify', $GLOBALS['t_calls'][0]['url'] );
$body = last_body();
check( 'secret is the stored secret', str_repeat( 'K', 40 ), $body['secret'] );
check( 'response is the posted token', '03AGdBq24token', $body['response'] );
check( 'remoteip is the visitor address', '203.0.113.7', $body['remoteip'] );
check( 'only the three documented fields are sent', array( 'secret', 'response', 'remoteip' ), array_keys( $body ) );

echo "\n== 9. reCAPTCHA v3 fails closed ==\n";
reset_world( recaptcha_site() );
$GLOBALS['t_is_pro'] = true;
check( 'no token: false', false, npmp_recaptcha_verify() );
check( 'no token: no request', 0, count( $GLOBALS['t_calls'] ) );
$_POST['g-recaptcha-response'] = 'tok';
$GLOBALS['t_reply'] = recaptcha_ok( 0.3 );
check( 'score 0.3 fails the default threshold', false, npmp_recaptcha_verify() );
check( 'score 0.3 passes a caller threshold of 0.2', true, npmp_recaptcha_verify( 'g-recaptcha-response', 0.2 ) );
$GLOBALS['t_reply'] = recaptcha_ok( 0.5 );
check( 'score equal to the threshold passes', true, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = array( 200, array( 'success' => true, 'challenge_ts' => '2026-10-23T15:14:30Z', 'hostname' => 'example.org' ) );
check( 'a v2-shaped success with no score is treated as 0.0 and fails', false, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = recaptcha_fail();
check( 'success:false: false', false, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = recaptcha_fail( array( 'timeout-or-duplicate' ) );
check( 'replayed token: false', false, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
check( 'network failure: false', false, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = array( 500, array( 'success' => true, 'score' => 0.9 ) );
check( 'success on a non-200 status is not trusted', false, npmp_recaptcha_verify() );
$GLOBALS['t_reply'] = array( 200, 'not json' );
check( 'unreadable body: false', false, npmp_recaptcha_verify() );

reset_world( array( 'npmp_captcha_provider' => 'recaptcha', 'npmp_recaptcha_site_key' => str_repeat( 'S', 40 ) ) );
$GLOBALS['t_is_pro'] = true;
$_POST['g-recaptcha-response'] = 'tok';
$GLOBALS['t_reply'] = recaptcha_ok();
check( 'no secret stored: not enabled, so verify passes without a request', true, npmp_recaptcha_verify() );
check( 'no secret stored: nothing sent to Google', 0, count( $GLOBALS['t_calls'] ) );

echo "\n== 10. reCAPTCHA: testing the keys is offline ==\n";
reset_world( array( 'npmp_recaptcha_site_key' => str_repeat( 'S', 40 ) ) );
check( 'missing secret: code', 'npmp-recaptcha-missing', npmp_recaptcha_test_keys()->get_error_code() );
reset_world( array( 'npmp_recaptcha_site_key' => 'short', 'npmp_recaptcha_secret_key' => 'short' ) );
check( 'short keys: code', 'npmp-recaptcha-invalid', npmp_recaptcha_test_keys()->get_error_code() );
reset_world( recaptcha_site() );
check( '40-character keys: pass', true, npmp_recaptcha_test_keys() );
check( 'no request was made', 0, count( $GLOBALS['t_calls'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
