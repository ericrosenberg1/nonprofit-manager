<?php
/**
 * Donation links, prefill tokens and the email Donate button
 * (includes/payments/npmp-donation-links.php).
 *
 * - A payment link template saves only as https with a real host, no
 *   placeholder in the host, no user name or password, and only known
 *   placeholders, and the placeholders survive esc_url_raw().
 * - Filling a template URL-encodes each value.
 * - A prefill token names its address, and a forged, changed, expired, or
 *   other-purpose token names nobody.
 * - The email button is a table with inline #0f766e and white text.
 * - The Payment link gateway is free (npmp-gateway-rules.php).
 *
 * The same pieces run on real WordPress in tests/rig/donation-links.php.
 *
 * Run: php tests/test-donation-links.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['npmp_test_options'] = array();
$GLOBALS['npmp_test_filters'] = array();

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['npmp_test_filters'][ $hook ][] = $cb;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $name ] : $default;
}
function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}
function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
// The character filter and protocol check of WordPress's esc_url(), which
// is what strips braces and makes the placeholder swap necessary.
function esc_url_raw( $url, $protocols = null ) {
	$url = str_replace( ' ', '%20', ltrim( (string) $url ) );
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );
	if ( '' === $url ) {
		return '';
	}
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( $protocols && ! in_array( $scheme, $protocols, true ) ) {
		return '';
	}
	return $url;
}
function esc_url( $url ) {
	return esc_attr( esc_url_raw( $url ) );
}

require_once dirname( __DIR__ ) . '/includes/payments/npmp-gateway-rules.php';
require_once dirname( __DIR__ ) . '/includes/payments/npmp-donation-links.php';

echo "Payment link template on save\n";
$monzo = npmp_payment_link_validate_template( 'https://monzo.me/yourname/{amount}?d={note}' );
check( 'Monzo template saves', 'https://monzo.me/yourname/{amount}?d={note}', $monzo['url'] );
check( 'Monzo template has no error', '', $monzo['error'] );
$all = npmp_payment_link_validate_template( '  https://pay.example.org/give?amt={amount}&cur={currency}&n={name}&e={email}  ' );
check( 'every placeholder survives the clean, spaces trimmed', 'https://pay.example.org/give?amt={amount}&cur={currency}&n={name}&e={email}', $all['url'] );
$upper = npmp_payment_link_validate_template( 'https://pay.example.org/{AMOUNT}' );
check( 'placeholder case folds to lower', 'https://pay.example.org/{amount}', $upper['url'] );

$bad = array(
	'http only'          => 'http://monzo.me/yourname/{amount}',
	'javascript scheme'  => 'javascript:alert(1)//{amount}',
	'data scheme'        => 'data:text/html,{amount}',
	'no host'            => 'https:///{amount}',
	'placeholder host'   => 'https://{name}.example.org/pay',
	'placeholder in host after dot' => 'https://pay.{note}/x',
	'user and password'  => 'https://user:pw@example.org/{amount}',
	'unknown placeholder' => 'https://example.org/{amt}',
	'protocol-relative'  => '//example.org/{amount}',
);
foreach ( $bad as $label => $tpl ) {
	$r = npmp_payment_link_validate_template( $tpl );
	check( "refused: {$label}", true, '' === $r['url'] && '' !== $r['error'] );
}
$empty = npmp_payment_link_validate_template( '   ' );
check( 'blank row is neither saved nor an error', array( 'url' => '', 'error' => '' ), $empty );

echo "\nPosted rows\n";
$rows = npmp_payment_links_from_post(
	array( 'Monzo', '', 'Bad' ),
	array( 'https://monzo.me/yourname/{amount}?d={note}', '', 'http://x.example/{amount}' ),
	array( 'Club <b>fees</b>', '', '' )
);
check( 'one good row kept', 1, count( $rows['links'] ) );
check( 'label kept', 'Monzo', $rows['links'][0]['label'] );
check( 'note stripped of tags', 'Club fees', $rows['links'][0]['note'] );
check( 'bad row reported with its label', 1, count( $rows['errors'] ) );
check( 'error names the row', 0, strpos( $rows['errors'][0], 'Bad: ' ) );

$many = npmp_payment_links_from_post( array(), array_fill( 0, 7, 'https://example.org/{amount}' ), array() );
check( 'no more than the cap saved', NPMP_PAYMENT_LINKS_MAX, count( $many['links'] ) );
check( 'cap reported', 1, count( $many['errors'] ) );
check( 'unlabelled link gets a label', 'Pay online', $many['links'][0]['label'] );

$GLOBALS['npmp_test_options']['npmp_payment_links'] = array(
	array( 'label' => 'Good', 'url' => 'https://monzo.me/a/{amount}', 'note' => '' ),
	array( 'label' => 'Tampered', 'url' => 'javascript:alert(1)', 'note' => '' ),
	'junk',
);
$saved = npmp_get_payment_links();
check( 'a tampered saved link is dropped on the way out', array( 'Good' ), array_column( $saved, 'label' ) );

echo "\nFilling a template\n";
$filled = npmp_payment_link_fill(
	'https://monzo.me/yourname/{amount}?d={note}&n={name}&e={email}&c={currency}',
	array(
		'amount'   => '5.00',
		'note'     => 'Club & co #1',
		'name'     => 'Pete O\'Neil',
		'email'    => 'pete+u3a@example.org',
		'currency' => 'GBP',
	)
);
check( 'each value URL-encoded', 'https://monzo.me/yourname/5.00?d=Club%20%26%20co%20%231&n=Pete%20O%27Neil&e=pete%2Bu3a%40example.org&c=GBP', $filled );
check( 'a value cannot add a path or host', 'https://example.org/pay/%2F%2Fevil.example%2F', npmp_payment_link_fill( 'https://example.org/pay/{name}', array( 'name' => '//evil.example/' ) ) );
check( 'missing value is blank', 'https://example.org/?a=', npmp_payment_link_fill( 'https://example.org/?a={amount}', array() ) );

echo "\nPrefill token\n";
$now   = 1_800_000_000;
$token = npmp_donor_prefill_token( 'Pete@Example.org', $now );
check( 'token made for a good address', true, '' !== $token );
check( 'token is URL-safe', 1, preg_match( '/^[A-Za-z0-9_.-]+$/', $token ) );
check( 'token names its address (lowercased)', 'pete@example.org', npmp_donor_prefill_email( $token, $now ) );
check( 'still good after 89 days', 'pete@example.org', npmp_donor_prefill_email( $token, $now + 89 * DAY_IN_SECONDS ) );
check( 'expired after 91 days', '', npmp_donor_prefill_email( $token, $now + 91 * DAY_IN_SECONDS ) );
check( 'refused when issued in the future', '', npmp_donor_prefill_email( $token, $now - 2 * HOUR_IN_SECONDS ) );
check( 'no token for a bad address', '', npmp_donor_prefill_token( 'not-an-email', $now ) );

list( $addr, $time, $sig ) = explode( '.', $token );
$other  = npmp_donor_b64url( 'someone@example.org' );
check( 'forged: other address, same signature', '', npmp_donor_prefill_email( $other . '.' . $time . '.' . $sig, $now ) );
check( 'forged: later time, same signature', '', npmp_donor_prefill_email( $addr . '.' . base_convert( (string) ( $now + 5 ), 10, 36 ) . '.' . $sig, $now ) );
check( 'forged: signature flipped', '', npmp_donor_prefill_email( $addr . '.' . $time . '.' . strrev( $sig ), $now ) );
check( 'forged: bare address', '', npmp_donor_prefill_email( 'pete@example.org', $now ) );
check( 'forged: empty', '', npmp_donor_prefill_email( '', $now ) );
$unsub_style = substr( hash_hmac( 'sha256', 'unsubscribe|pete@example.org', wp_salt( 'auth' ) ), 0, 32 );
check( 'an unsubscribe-style signature does not work here', '', npmp_donor_prefill_email( $addr . '.' . $time . '.' . $unsub_style, $now ) );
$other_salt = substr( hash_hmac( 'sha256', 'donor-prefill|' . $addr . '.' . $time, 'some-other-site-salt' ), 0, 32 );
check( 'a token signed with another site\'s salt fails', '', npmp_donor_prefill_email( $addr . '.' . $time . '.' . $other_salt, $now ) );

echo "\nNewsletter tag\n";
check( 'tag filter registered', true, in_array( 'npmp_donation_recipient_tag', $GLOBALS['npmp_test_filters']['npmp_email_recipient_tags'] ?? array(), true ) );
$tags = npmp_donation_recipient_tag( array( 'first_name' => 'Pete' ), null, 'pete@example.org' );
check( 'reader gets a working key', 'pete@example.org', npmp_donor_prefill_email( $tags['npmp_donor_key'] ) );
check( 'other tags untouched', 'Pete', $tags['first_name'] );
$public = npmp_donation_recipient_tag( array(), null, '' );
check( 'public archive (no reader) gets an empty key', '', $public['npmp_donor_key'] );
check(
	'leftover tag stripped from mail that never fills tags',
	'<a href="https://x.example/give/?npmp_donor=">D</a> <a href="https://x.example/?a=1&npmp_donor=">E</a>',
	npmp_donation_strip_unfilled_key( '<a href="https://x.example/give/?npmp_donor=[npmp_donor_key]">D</a> <a href="https://x.example/?a=1&npmp_donor=%5Bnpmp_donor_key%5D">E</a>' )
);

echo "\nEmail button\n";
$btn = npmp_email_donate_button_html( 'https://x.example/give/?npmp_donor=[npmp_donor_key]', 'Donate <now>' );
check( 'table based', 0, strpos( $btn, '<table role="presentation"' ) );
check( 'no form in it', false, stripos( $btn, '<form' ) );
check( 'no script in it', false, stripos( $btn, '<script' ) );
check( 'brand color on the cell', true, false !== strpos( $btn, 'bgcolor="#0f766e"' ) && false !== strpos( $btn, 'background-color:#0f766e' ) );
check( 'white text inline', true, false !== strpos( $btn, 'color:#ffffff' ) );
check( 'label escaped', true, false !== strpos( $btn, 'Donate &lt;now&gt;' ) );
check( 'tag kept in the link for the newsletter to fill', true, false !== strpos( $btn, 'npmp_donor=[npmp_donor_key]' ) );

echo "\nGateway rules\n";
check( 'free: payment link allowed', true, in_array( 'payment_link', npmp_allowed_gateways( false ), true ) );
check( 'Pro: payment link allowed', true, in_array( 'payment_link', npmp_allowed_gateways( true ), true ) );

echo "\nNever an unsigned email\n";
$src = file_get_contents( dirname( __DIR__ ) . '/includes/payments/npmp-donation-links.php' );
check( 'prefill reads no email= or npmp_email= query arg', 0, preg_match( '/\$_(GET|REQUEST)\[\s*\'(email|npmp_email)\'/', $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
