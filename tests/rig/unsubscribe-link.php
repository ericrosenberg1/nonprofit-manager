<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/unsubscribe-link.php
//
// The unsubscribe link in a sent newsletter, on a real WordPress. Each
// recipient's footer link opens the site's unsubscribe page with their address
// filled in and one button. A GET changes nothing, a forged token falls back
// to the plain form, and the button's POST unsubscribes and lands back on the
// page. With no unsubscribe page set, the link is the one-click link.
// Leaves no contacts or pages behind. Not run by the pre-push gate.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
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
class Rig_Stop extends Error { public $payload; }
add_filter( 'wp_die_handler', function () { return function ( $msg ) { $e = new Rig_Stop( 'die' ); $e->payload = $msg; throw $e; }; } );
add_filter( 'wp_redirect', function ( $location ) { $e = new Rig_Stop( 'redirect' ); $e->payload = $location; throw $e; } );
function rig_run( $callable ) {
	ob_start();
	try { call_user_func( $callable ); $r = null; } catch ( Rig_Stop $e ) { $r = $e; }
	ob_end_clean();
	return $r;
}
function rig_href_for( $html, $text ) {
	return preg_match( '#<a href="([^"]+)">' . preg_quote( $text, '#' ) . '</a>#', $html, $m ) ? html_entity_decode( $m[1] ) : '';
}

$mm    = NPMP_Member_Manager::get_instance();
$email = 'rig-unsub-link@example.org';
$old   = $mm->get_member_by_email( $email );
if ( $old ) {
	$mm->delete_member( $old->id );
}
$contact = $mm->add_member( array( 'email' => $email, 'name' => 'Rig Reader', 'status' => 'subscribed' ) );

$settings_before = get_option( 'npmp_membership_form_settings' );
$page            = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Unsubscribe', 'post_content' => '[npmp_email_unsubscribe]' ) );
$settings        = npmp_get_membership_form_settings();
$settings['unsubscribe_page_id'] = $page;
update_option( 'npmp_membership_form_settings', $settings );
npmp_get_membership_form_settings( true );
update_option( 'npmp_newsletter_can_spam_footer', 'From [organization]. <a href="[unsubscribe_url]">Unsubscribe</a>' );

$nl   = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig', 'post_content' => '<p>Hello</p>' ) );
update_post_meta( $nl, '_npmp_newsletter_template', 'none' );
$body = NPMP_Newsletter_Manager::get_newsletter_content( $nl, false, $contact, $email );
$href = rig_href_for( $body, 'Unsubscribe' );
parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $q );

echo "== The footer link ==\n";
rig_check( 'Points at the unsubscribe page', 0, strpos( $href, get_permalink( $page ) ) );
rig_check( 'Carries the recipient address', $email, $q['npmp_email'] ?? '' );
rig_check( 'Carries the signed token', npmp_generate_unsubscribe_token( $email ), $q['npmp_token'] ?? '' );
$other = NPMP_Newsletter_Manager::get_newsletter_content( $nl, false, $contact, 'someone-else@example.org' );
parse_str( (string) wp_parse_url( rig_href_for( $other, 'Unsubscribe' ), PHP_URL_QUERY ), $q2 );
rig_check( 'Each recipient gets their own link', 'someone-else@example.org', $q2['npmp_email'] ?? '' );
rig_check( 'Bare [npmp_can_spam] keeps the plain page', get_permalink( $page ), rig_href_for( do_shortcode( '[npmp_can_spam]' ), 'Unsubscribe' ) );

echo "== Opening the link ==\n";
$_GET = $q;
$form = do_shortcode( '[npmp_email_unsubscribe]' );
rig_check( 'Page names the address', true, false !== strpos( $form, '<strong>' . $email . '</strong>' ) );
rig_check( 'No email box to type in', false, (bool) preg_match( '/type="email"/', $form ) );
rig_check( 'Button posts to the one-click handler', true, false !== strpos( $form, 'value="npmp_one_click_unsubscribe"' ) );
rig_check( 'Offers the plain form for another address', true, false !== strpos( $form, 'Unsubscribe a different address' ) );
rig_check( 'Opening the link changes nothing', 'subscribed', $mm->get_member_by_email( $email )->status );

$_GET = array( 'npmp_email' => $email, 'npmp_token' => 'forged0000000000000' );
$form = do_shortcode( '[npmp_email_unsubscribe]' );
rig_check( 'Forged token: plain form with an email box', true, (bool) preg_match( '/type="email"/', $form ) );
rig_check( 'Forged token: the address is not shown', false, false !== strpos( $form, $email ) );
$_GET = array();

echo "== Pressing the button ==\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST    = array( 'action' => 'npmp_one_click_unsubscribe', 'email' => $email, 'token' => $q['npmp_token'], 'npmp_confirm' => '1' );
$_REQUEST = $_POST;
$stop     = rig_run( 'npmp_handle_one_click_unsubscribe' );
rig_check( 'Contact is unsubscribed', 'unsubscribed', $mm->get_member_by_email( $email )->status );
rig_check( 'Lands back on the unsubscribe page', 0, $stop ? strpos( (string) $stop->payload, get_permalink( $page ) ) : -1 );
rig_check( 'With the "You\'re unsubscribed" banner', true, $stop && false !== strpos( (string) $stop->payload, 'npmp_unsubscribe=unsubscribed' ) );
$_POST = $_REQUEST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "== No unsubscribe page set ==\n";
$settings['unsubscribe_page_id'] = 0;
update_option( 'npmp_membership_form_settings', $settings );
npmp_get_membership_form_settings( true );
$href = rig_href_for( NPMP_Newsletter_Manager::get_newsletter_content( $nl, false, $contact, $email ), 'Unsubscribe' );
rig_check( 'Falls back to the one-click link', npmp_get_one_click_unsubscribe_url( $email ), $href );

wp_delete_post( $nl, true );
wp_delete_post( $page, true );
$mm->delete_member( $contact );
update_option( 'npmp_membership_form_settings', $settings_before );
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
