<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/double-optin.php
//
// Double opt-in on the public signup form, on a real WordPress. A new address
// is saved as Pending and gets one email with a link. Opening the link changes
// nothing, a wrong key changes nothing, and pressing the button subscribes the
// contact, saves the checkboxes they ticked on the form, and fires
// npmp_signup_confirmed. The link works once. A known subscriber gets a link
// too, an unsubscribed one keeps the resubscribe path, and with the setting
// off a signup subscribes at once. Leaves no contacts, pages or settings behind.
// Not run by the pre-push gate.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
class Rig_Stop extends Error { public $payload; public $status = 0; }
add_filter( 'wp_die_handler', function () {
	return function ( $msg, $title = '', $args = array() ) {
		$e          = new Rig_Stop( 'die' );
		$e->payload = is_string( $msg ) ? $msg : '';
		$e->status  = (int) ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 );
		throw $e;
	};
} );
add_filter( 'wp_redirect', function ( $location ) { $e = new Rig_Stop( 'redirect' ); $e->payload = $location; throw $e; } );
add_filter( 'npmp_rate_limit_max', '__return_zero' );
function rig_run( $callable ) {
	ob_start();
	try { call_user_func( $callable ); $r = null; } catch ( Rig_Stop $e ) { $r = $e; }
	ob_end_clean();
	return $r;
}
$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );
$GLOBALS['rig_confirmed'] = array();
add_action( 'npmp_signup_confirmed', function ( $id, $is_new ) { $GLOBALS['rig_confirmed'][] = array( (int) $id, $is_new ); }, 10, 2 );

function rig_signup( $email, $name, $extra = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(
		array(
			'action'                  => 'npmp_handle_form',
			'npmp_action'             => 'email_signup',
			'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
			'npmp_name'               => $name,
			'npmp_email'              => $email,
			'npmp_signup_atts'        => npmp_signup_atts_token( array() ),
		),
		$extra
	);
	$_REQUEST = $_POST;
	$stop = rig_run( 'npmp_handle_membership_form' );
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $stop ? (string) $stop->payload : '';
}
function rig_link_from( $mail ) {
	return preg_match( '#(https?://\S+action=npmp_confirm_signup\S*)#', (string) ( $mail['message'] ?? '' ), $m ) ? $m[1] : '';
}
function rig_open( $link, $method ) {
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	$_SERVER['REQUEST_METHOD'] = $method;
	$_REQUEST = $q;
	if ( 'POST' === $method ) {
		$_POST = $q + array( 'npmp_confirm' => '1' );
		$_REQUEST = $_POST;
	} else {
		$_GET = $q;
	}
	$stop = rig_run( 'npmp_handle_confirm_signup' );
	$_GET = $_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $stop;
}

$mm     = NPMP_Member_Manager::get_instance();
$emails = array( 'rig-optin-new@example.org', 'rig-optin-known@example.org', 'rig-optin-gone@example.org', 'rig-optin-off@example.org' );
$sweep  = function () use ( $mm, $emails ) {
	foreach ( $emails as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( $m->id );
		}
	}
};
$sweep();
$settings_before = get_option( 'npmp_membership_form_settings' );

echo "== Default ==\n";
rig_check( 'On by default for a new site', 1, npmp_membership_form_default_settings()['double_optin'] );
$saved = is_array( $settings_before ) ? $settings_before : array();
unset( $saved['double_optin'] );
$saved['signup_page_id'] = 0;
update_option( 'npmp_membership_form_settings', $saved );
npmp_get_membership_form_settings( true );
rig_check( 'On for a site that saved settings before 2026.11.3', true, npmp_double_optin_enabled() );

echo "== A new address signs up ==\n";
$GLOBALS['rig_mails'] = array();
$where   = rig_signup( 'rig-optin-new@example.org', 'Nora New', array( 'npmp_notify_events' => '1' ) );
$contact = $mm->get_member_by_email( 'rig-optin-new@example.org' );
rig_check( 'Visitor is told to check their inbox', true, false !== strpos( $where, 'npmp_signup=check_email' ) );
rig_check( 'Contact is saved as Pending', 'pending', $contact ? $contact->status : null );
rig_check( 'Pending contact is not a newsletter recipient', false, (bool) array_filter( $mm->get_members( array( 'per_page' => -1, 'status' => 'subscribed' ) ), function ( $m ) { return 'rig-optin-new@example.org' === $m->email; } ) );
rig_check( 'Ticked checkbox is not saved yet', '', (string) get_post_meta( $contact->id, '_npmp_notify_events', true ) );
rig_check( 'One email, to that address', array( 'rig-optin-new@example.org' ), array_column( $GLOBALS['rig_mails'], 'to' ) );
$link = rig_link_from( $GLOBALS['rig_mails'][0] ?? array() );
rig_check( 'Email carries a confirm link', true, '' !== $link );
rig_check( 'Subject asks to confirm', 0, strpos( (string) ( $GLOBALS['rig_mails'][0]['subject'] ?? '' ), 'Please confirm your subscription' ) );

echo "== Opening the link ==\n";
$stop = rig_open( $link, 'GET' );
rig_check( 'GET shows a page with a button', true, $stop && false !== strpos( $stop->payload, 'value="npmp_confirm_signup"' ) );
rig_check( 'GET changes nothing', 'pending', $mm->get_member_by_email( 'rig-optin-new@example.org' )->status );
$stop = rig_open( preg_replace( '/token=[^&]+/', 'token=forged0000000000000000000000000', $link ), 'POST' );
rig_check( 'Wrong key: 400', 400, $stop ? $stop->status : 0 );
rig_check( 'Wrong key changes nothing', 'pending', $mm->get_member_by_email( 'rig-optin-new@example.org' )->status );

echo "== Pressing the button ==\n";
$stop    = rig_open( $link, 'POST' );
$contact = $mm->get_member_by_email( 'rig-optin-new@example.org' );
rig_check( 'Contact is subscribed', 'subscribed', $contact->status );
rig_check( 'Name kept from the form', 'Nora New', $contact->name );
rig_check( 'Ticked checkbox saved on confirm', '1', (string) get_post_meta( $contact->id, '_npmp_notify_events', true ) );
rig_check( 'npmp_signup_confirmed fired as a new contact', array( array( (int) $contact->id, true ) ), $GLOBALS['rig_confirmed'] );
rig_check( 'No signup page: a thank-you page', true, $stop && 200 === $stop->status && false !== strpos( $stop->payload, 'subscribed' ) );
$stop = rig_open( $link, 'POST' );
rig_check( 'Second press: already subscribed', true, $stop && false !== strpos( $stop->payload, 'already subscribed' ) );
rig_check( 'Second press fires nothing', 1, count( $GLOBALS['rig_confirmed'] ) );

echo "== Someone already on the list ==\n";
$known = $mm->add_member( array( 'email' => 'rig-optin-known@example.org', 'name' => 'Kim Known', 'status' => 'subscribed' ) );
$page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Signup', 'post_content' => '[npmp_email_signup]' ) );
$s     = npmp_get_membership_form_settings( true );
$s['signup_page_id'] = $page;
update_option( 'npmp_membership_form_settings', $s );
npmp_get_membership_form_settings( true );
$GLOBALS['rig_mails']     = array();
$GLOBALS['rig_confirmed'] = array();
$where = rig_signup( 'rig-optin-known@example.org', 'Someone Else' );
rig_check( 'Same answer as a new address', true, false !== strpos( $where, 'npmp_signup=check_email' ) );
rig_check( 'Still subscribed', 'subscribed', $mm->get_member_by_email( 'rig-optin-known@example.org' )->status );
$stop = rig_open( rig_link_from( $GLOBALS['rig_mails'][0] ?? array() ), 'POST' );
rig_check( 'Confirm lands on the signup page', 0, $stop ? strpos( $stop->payload, get_permalink( $page ) ) : -1 );
rig_check( 'With the "You\'re subscribed" banner', true, $stop && false !== strpos( $stop->payload, 'npmp_signup=confirmed' ) );
rig_check( 'A form never renames a contact', 'Kim Known', $mm->get_member_by_email( 'rig-optin-known@example.org' )->name );
rig_check( 'Fired as an existing contact', array( array( (int) $known, false ) ), $GLOBALS['rig_confirmed'] );
$_GET   = array( 'npmp_signup' => 'confirmed', 'npmp_signup_nonce' => wp_create_nonce( 'npmp_banner_npmp_signup_confirmed' ) );
rig_check( 'Banner text', true, false !== strpos( do_shortcode( '[npmp_email_signup]' ), 'Thanks for confirming' ) );
$_GET   = array( 'npmp_signup' => 'check_email', 'npmp_signup_nonce' => wp_create_nonce( 'npmp_banner_npmp_signup_check_email' ) );
rig_check( 'Check-your-inbox banner text', true, false !== strpos( do_shortcode( '[npmp_email_signup]' ), 'Check your inbox' ) );
$_GET   = array();

echo "== Someone who unsubscribed ==\n";
$mm->add_member( array( 'email' => 'rig-optin-gone@example.org', 'name' => 'Gus Gone', 'status' => 'unsubscribed' ) );
$GLOBALS['rig_mails'] = array();
$where = rig_signup( 'rig-optin-gone@example.org', 'Gus Gone' );
rig_check( 'Keeps the resubscribe email', true, false !== strpos( (string) ( $GLOBALS['rig_mails'][0]['message'] ?? '' ), 'npmp_confirm_resubscribe' ) );
rig_check( 'Stays unsubscribed until they confirm', 'unsubscribed', $mm->get_member_by_email( 'rig-optin-gone@example.org' )->status );

echo "== Setting off ==\n";
$s['double_optin'] = 0;
update_option( 'npmp_membership_form_settings', $s );
npmp_get_membership_form_settings( true );
$GLOBALS['rig_mails'] = array();
$where = rig_signup( 'rig-optin-off@example.org', 'Otto Off' );
rig_check( 'Signup subscribes at once', 'subscribed', $mm->get_member_by_email( 'rig-optin-off@example.org' )->status );
rig_check( 'Success banner', true, false !== strpos( $where, 'npmp_signup=success' ) );
rig_check( 'No confirmation email', 0, count( array_filter( $GLOBALS['rig_mails'], function ( $m ) { return false !== strpos( (string) $m['message'], 'npmp_confirm_signup' ); } ) ) );

echo "== Settings screen ==\n";
wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) );
$s['double_optin'] = 1;
update_option( 'npmp_membership_form_settings', $s );
npmp_get_membership_form_settings( true );
ob_start();
npmp_render_membership_forms_page();
$screen = ob_get_clean();
rig_check( 'Checkbox shows, ticked', true, (bool) preg_match( '/name="double_optin" value="1"\s*checked/', $screen ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'npmp_membership_form_save' ), 'signup_heading' => $s['signup_heading'] ?? 'Sign up' );
ob_start();
npmp_render_membership_forms_page();
ob_end_clean();
$_POST = $_REQUEST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
rig_check( 'Saving with the box unticked turns it off', 0, (int) npmp_get_membership_form_settings( true )['double_optin'] );

$sweep();
wp_delete_post( $page, true );
update_option( 'npmp_membership_form_settings', $settings_before );
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
