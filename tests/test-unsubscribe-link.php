<?php
/**
 * The newsletter footer's unsubscribe link fills in the reader's address.
 *
 * Until 2026.11.3 [unsubscribe_url] was the bare unsubscribe page for every
 * recipient, so readers had to type their address (wordpress.org thread "Auto
 * fill email address on unsubscribe"). The behavior itself is checked on a
 * real WordPress by tests/rig/unsubscribe-link.php. This guards the wiring in
 * the pre-push gate.
 *
 * Run: php tests/test-unsubscribe-link.php
 */

require_once __DIR__ . '/bootstrap.php';

$root   = dirname( __DIR__ );
$footer = file_get_contents( $root . '/includes/email-newsletter/can-spam-shortcode.php' );
$forms  = file_get_contents( $root . '/includes/npmp-membership-forms.php' );

check( 'Footer passes the recipient to the unsubscribe URL', 1, substr_count( $footer, 'npmp_can_spam_get_unsubscribe_url( $email )' ) );
check( 'URL helper takes an optional address', 1, substr_count( $footer, "function npmp_can_spam_get_unsubscribe_url( \$email = '' )" ) );
check( 'Signed link carries npmp_email and npmp_token', 1, preg_match( "/'npmp_email' => rawurlencode\( \\\$email \),\s*'npmp_token' => npmp_generate_unsubscribe_token\( \\\$email \)/", $footer ) );
check( 'No unsubscribe page: one-click link', 1, substr_count( $footer, 'npmp_get_one_click_unsubscribe_url( $email )' ) );

check( 'Shortcode checks the token with hash_equals', 1, preg_match( '/hash_equals\( npmp_generate_unsubscribe_token\( \$email \), \$token \)/', substr( $forms, strpos( $forms, 'function npmp_unsubscribe_link_email' ), 900 ) ) );
$start     = strpos( $forms, 'function npmp_email_unsubscribe_shortcode' );
$shortcode = substr( $forms, $start, strpos( $forms, 'add_shortcode( \'npmp_email_unsubscribe\'', $start ) - $start );
check( 'Signed visit posts to the one-click handler', 1, substr_count( $shortcode, '<input type="hidden" name="action" value="npmp_one_click_unsubscribe">' ) );
check( 'The button press is marked as a person, not RFC 8058', 1, substr_count( $shortcode, '<input type="hidden" name="npmp_confirm" value="1">' ) );
check( 'Signed visit checks the link first', 1, substr_count( $shortcode, 'npmp_unsubscribe_link_email()' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
