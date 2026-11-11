<?php
/**
 * The setup wizard's newsletter opt-in (includes/npmp-marketing-optin.php).
 *
 * Until 2026.11.4 the endpoint was listmonk.ericrosenberg.com, which had no
 * DNS record, and a failure only reached the PHP error log, so every opt-in
 * since 2026.09.10 vanished. Pinned here: the endpoint sits on the
 * nonprofitmanager.app domain, the request body listmonk expects, that only
 * a real listmonk reply counts as success, and that the wizard hands the
 * result to the welcome screen.
 *
 * Run: php tests/test-marketing-optin.php
 */

require_once __DIR__ . '/bootstrap.php';

// WordPress HTTP stubs. $npmp_test_http decides what the next call returns.
$npmp_test_http  = null;
$npmp_test_posts = array();

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_remote_post( $url, $args ) {
	global $npmp_test_http, $npmp_test_posts;
	$npmp_test_posts[] = array( 'url' => $url, 'args' => $args );
	return $npmp_test_http;
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? $response['response']['code'] : '';
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? $response['body'] : '';
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function sanitize_email( $email ) {
	return trim( (string) $email );
}
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

/**
 * Fake an HTTP reply.
 *
 * @param int    $code HTTP status.
 * @param string $body Response body.
 * @return array
 */
function npmp_test_reply( $code, $body ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
	);
}

ini_set( 'error_log', '/dev/null' );

require_once dirname( __DIR__ ) . '/includes/npmp-marketing-optin.php';

echo "Endpoint\n";
$host = parse_url( NPMP_NEWSLETTER_SUBSCRIBE_URL, PHP_URL_HOST );
check( 'endpoint is https', 'https', parse_url( NPMP_NEWSLETTER_SUBSCRIBE_URL, PHP_URL_SCHEME ) );
check( 'endpoint is on nonprofitmanager.app', 1, preg_match( '/(^|\.)nonprofitmanager\.app$/', $host ) );
check( 'endpoint is listmonk\'s public subscription API', '/api/public/subscription', parse_url( NPMP_NEWSLETTER_SUBSCRIBE_URL, PHP_URL_PATH ) );
check( 'list uuid is a uuid', 1, preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', NPMP_NEWSLETTER_LIST_UUID ) );

echo "\nRequest body\n";
$body = npmp_newsletter_subscribe_request( 'ann@example.org', 'Ann Lee' );
check( 'email passes through', 'ann@example.org', $body['email'] );
check( 'name passes through', 'Ann Lee', $body['name'] );
check( 'list is the newsletter list', array( NPMP_NEWSLETTER_LIST_UUID ), $body['list_uuids'] );
check( 'an empty name falls back to the mailbox', 'ann', npmp_newsletter_subscribe_request( 'ann@example.org', '' )['name'] );
check( 'a blank name falls back to the mailbox', 'ann', npmp_newsletter_subscribe_request( 'ann@example.org', '   ' )['name'] );
check( 'tags are stripped from the name', 'Ann', npmp_newsletter_subscribe_request( 'ann@example.org', '<b>Ann</b>' )['name'] );

echo "\nReading the reply\n";
check( 'listmonk 200 with data true is accepted', true, npmp_newsletter_subscribe_outcome( npmp_test_reply( 200, '{"data":true}' ) ) );
check( 'a 200 HTML page is not accepted', 'HTTP 200 without a listmonk reply', npmp_newsletter_subscribe_outcome( npmp_test_reply( 200, '<html>parked</html>' ) ) );
check( 'a 200 with data false is not accepted', 'HTTP 200 without a listmonk reply', npmp_newsletter_subscribe_outcome( npmp_test_reply( 200, '{"data":false}' ) ) );
check( 'a 400 carries listmonk\'s message', 'HTTP 400: Invalid email.', npmp_newsletter_subscribe_outcome( npmp_test_reply( 400, '{"message":"Invalid email."}' ) ) );
check( 'a 502 without JSON says the code', 'HTTP 502', npmp_newsletter_subscribe_outcome( npmp_test_reply( 502, 'Bad Gateway' ) ) );
check( 'a transport error says why', 'request failed: Could not resolve host', npmp_newsletter_subscribe_outcome( new WP_Error( 'http_request_failed', 'Could not resolve host' ) ) );

echo "\nSubscribing\n";
$npmp_test_http = npmp_test_reply( 200, '{"data":true}' );
check( 'accepted signup returns true', true, npmp_subscribe_to_newsletter( 'ann@example.org', 'Ann' ) );
$sent = end( $npmp_test_posts );
check( 'posts to the endpoint', NPMP_NEWSLETTER_SUBSCRIBE_URL, $sent['url'] );
check( 'posts JSON', 'application/json', $sent['args']['headers']['Content-Type'] );
check( 'posts the request body', npmp_newsletter_subscribe_request( 'ann@example.org', 'Ann' ), json_decode( $sent['args']['body'], true ) );

$npmp_test_http = new WP_Error( 'http_request_failed', 'Could not resolve host' );
check( 'a DNS failure returns false', false, npmp_subscribe_to_newsletter( 'ann@example.org', 'Ann' ) );
$npmp_test_http = npmp_test_reply( 200, '<html></html>' );
check( 'a non-listmonk 200 returns false', false, npmp_subscribe_to_newsletter( 'ann@example.org', 'Ann' ) );

$before = count( $npmp_test_posts );
check( 'a bad address returns false', false, npmp_subscribe_to_newsletter( 'not-an-email', 'Ann' ) );
check( 'a bad address makes no request', $before, count( $npmp_test_posts ) );

echo "\nWizard and welcome screen\n";
$wizard   = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php' );
$settings = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-admin-settings.php' );
check( 'wizard uses the opt-in result', 1, preg_match( '/\$optin_result = npmp_subscribe_to_newsletter\(/', $wizard ) );
check( 'wizard passes the result on the redirect', 1, preg_match( "/add_query_arg\( 'npmp_optin', \\\$optin_result/", $wizard ) );
foreach ( array( 'sent', 'failed', 'invalid' ) as $state ) {
	check( "welcome screen handles $state", 1, preg_match( "/'$state' === \\\$optin_result/", $settings ) );
}

foreach ( glob( dirname( __DIR__ ) . '/{includes,readme.txt,nonprofit-manager.php}', GLOB_BRACE ) as $root ) {
	$files = is_dir( $root ) ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) : array( new SplFileInfo( $root ) );
	foreach ( $files as $f ) {
		if ( preg_match( '/\.(php|txt)$/', $f->getFilename() ) && false !== strpos( (string) file_get_contents( $f->getPathname() ), 'listmonk.ericrosenberg.com' ) ) {
			$code = file_get_contents( $f->getPathname() );
			// The comment recording the old host is allowed, a live URL is not.
			if ( preg_match( '#https?://listmonk\.ericrosenberg\.com|at listmonk\.ericrosenberg\.com#', $code ) ) {
				check( 'no live reference to the dead host in ' . basename( $f->getPathname() ), false, true );
			}
		}
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
