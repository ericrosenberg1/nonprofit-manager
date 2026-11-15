<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/signup-resubscribe.php
//
// Someone who left the list (Unsubscribed or Inactive) is only put back by
// their own click, on a real WordPress, with double opt-in on and off. Before
// 2026.11.14, with double opt-in off, a stranger who typed an Inactive
// contact's address into [npmp_email_signup] made them Active at once. Now
// the form sends that address the "subscribe again" email and changes
// nothing until the owner presses the button behind its link. The visitor
// sees the same banner for every address. A new address still subscribes at
// once with double opt-in off. A signed-in user typing their own address gets
// the email too, since an account's address isn't proof of who reads it. Each
// address gets at most 3 confirmation emails an hour, whoever asks. Works with
// or without Pro. Mail is caught with pre_wp_mail. Leaves no contacts, users,
// or settings behind. Not run by the pre-push gate.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
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
add_filter( 'wp_redirect', function ( $location ) { $e = new Rig_Stop( 'redirect' ); $e->payload = $location; throw $e; }, 999 );
// The per-visitor and site-wide caps would trip over a rig's many posts from
// one IP, so they're off here. The per-address cap keeps its default.
add_filter( 'npmp_rate_limit_max', function ( $max, $bucket ) { return 'signup_address' === $bucket ? $max : 0; }, 10, 2 );
$GLOBALS['rig_doi'] = false;
add_filter( 'npmp_double_optin_enabled', function () { return $GLOBALS['rig_doi']; } );
$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );

function rig_run( $callable ) {
	ob_start();
	try { call_user_func( $callable ); $r = null; } catch ( Rig_Stop $e ) { $r = $e; }
	ob_end_clean();
	return $r;
}
// Post the public form. Returns the banner value it lands on.
function rig_signup( $email, $user_id = 0 ) {
	wp_set_current_user( $user_id );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array(
		'action'                  => 'npmp_handle_form',
		'npmp_action'             => 'email_signup',
		'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
		'npmp_name'               => 'Someone Typing',
		'npmp_email'              => $email,
	);
	$_REQUEST = $_POST;
	$stop = rig_run( 'npmp_handle_membership_form' );
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	wp_set_current_user( 0 );
	$where = $stop ? (string) $stop->payload : '';
	return preg_match( '/npmp_signup=([a-z_]+)/', $where, $b ) ? $b[1] : $where;
}
function rig_link_from( $mail, $action ) {
	return preg_match( '#(https?://\S+action=' . $action . '\S*)#', (string) ( $mail['message'] ?? '' ), $m ) ? $m[1] : '';
}
function rig_open( $link, $method, $handler ) {
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	$_SERVER['REQUEST_METHOD'] = $method;
	if ( 'POST' === $method ) {
		$_POST    = $q + array( 'npmp_confirm' => '1' );
		$_REQUEST = $_POST;
	} else {
		$_GET = $_REQUEST = $q;
	}
	$stop = rig_run( $handler );
	$_GET = $_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $stop;
}
function rig_status( $email ) {
	$m = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
	return $m ? (string) $m->status : '';
}
function rig_clear_limits() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_npmp\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_npmp\\_rl\\_%'" );
	wp_cache_flush();
}

$mm     = NPMP_Member_Manager::get_instance();
$emails = array( 'rig-resub-inactive@example.org', 'rig-resub-gone@example.org', 'rig-resub-active@example.org', 'rig-resub-new@example.org', 'rig-resub-own@example.org', 'rig-resub-flood@example.org', 'rig-resub-other@example.org' );
$sweep  = function () use ( $mm, $emails ) {
	foreach ( $emails as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( (int) $m->id );
		}
		$u = get_user_by( 'email', $e );
		if ( $u ) {
			wp_delete_user( $u->ID );
		}
	}
	rig_clear_limits();
};
$sweep();
$seed = function () use ( $mm ) {
	foreach ( array( 'rig-resub-inactive@example.org' => 'inactive', 'rig-resub-gone@example.org' => 'unsubscribed', 'rig-resub-active@example.org' => 'subscribed' ) as $e => $s ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->update_member( (int) $m->id, array( 'status' => $s ) );
			delete_post_meta( (int) $m->id, '_npmp_signup_confirm' );
		} else {
			$mm->add_member( array( 'name' => 'Rig ' . $s, 'email' => $e, 'status' => $s, 'source' => 'manual' ) );
		}
	}
	$n = $mm->get_member_by_email( 'rig-resub-new@example.org' );
	if ( $n ) {
		$mm->delete_member( (int) $n->id );
	}
	rig_clear_limits();
};

foreach ( array( false, true ) as $doi ) {
	$GLOBALS['rig_doi'] = $doi;
	$label  = $doi ? 'on' : 'off';
	$answer = $doi ? 'check_email' : 'success';
	$seed();
	echo "Double opt-in $label\n";

	foreach ( array( 'rig-resub-inactive@example.org' => 'inactive', 'rig-resub-gone@example.org' => 'unsubscribed' ) as $email => $was ) {
		$GLOBALS['rig_mails'] = array();
		$banner = rig_signup( $email );
		rig_check( "$was: the visitor sees the usual answer", $answer, $banner );
		rig_check( "$was: the form changes nothing", $was, rig_status( $email ) );
		rig_check( "$was: one email goes out", 1, count( $GLOBALS['rig_mails'] ) );
		$mail = $GLOBALS['rig_mails'][0] ?? array();
		rig_check( "$was: to that address", $email, is_array( $mail['to'] ?? '' ) ? ( $mail['to'][0] ?? '' ) : ( $mail['to'] ?? '' ) );
		rig_check( "$was: asking them to subscribe again", 'Confirm you want to subscribe again', (string) ( $mail['subject'] ?? '' ) );
		$link = rig_link_from( $mail, 'npmp_confirm_resubscribe' );
		rig_check( "$was: with a link", true, '' !== $link );
		$get = rig_open( $link, 'GET', 'npmp_handle_confirm_resubscribe' );
		rig_check( "$was: opening the link changes nothing", $was, rig_status( $email ) );
		rig_check( "$was: and shows a button", true, $get && false !== strpos( (string) $get->payload, 'npmp_confirm_resubscribe' ) );
		$forged = $link ? preg_replace( '/token=[^&]+/', 'token=wrongtoken0000000000', $link ) : '';
		rig_open( $forged, 'POST', 'npmp_handle_confirm_resubscribe' );
		rig_check( "$was: a wrong token changes nothing", $was, rig_status( $email ) );
		rig_open( $link, 'POST', 'npmp_handle_confirm_resubscribe' );
		rig_check( "$was: the owner's button puts them back", 'subscribed', rig_status( $email ) );
	}

	$GLOBALS['rig_mails'] = array();
	rig_check( 'An Active contact gets the same answer', $answer, rig_signup( 'rig-resub-active@example.org' ) );
	rig_check( 'And stays Active', 'subscribed', rig_status( 'rig-resub-active@example.org' ) );

	$GLOBALS['rig_mails'] = array();
	rig_check( 'A new address gets the same answer', $answer, rig_signup( 'rig-resub-new@example.org' ) );
	if ( $doi ) {
		rig_check( 'New address: saved as Pending', 'pending', rig_status( 'rig-resub-new@example.org' ) );
		rig_check( 'New address: one confirmation email', 1, count( $GLOBALS['rig_mails'] ) );
	} else {
		rig_check( 'New address: Active at once', 'subscribed', rig_status( 'rig-resub-new@example.org' ) );
		$confirms = array_filter( $GLOBALS['rig_mails'], function ( $m ) { return false !== stripos( (string) ( $m['subject'] ?? '' ), 'confirm' ); } );
		rig_check( 'New address: no confirmation email', 0, count( $confirms ) );
	}
}

echo "Signed-in user, own address\n";
$GLOBALS['rig_doi'] = false;
rig_clear_limits();
$uid = wp_insert_user( array( 'user_login' => 'rig-resub-own', 'user_email' => 'rig-resub-own@example.org', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
$mm->add_member( array( 'name' => 'Rig Own', 'email' => 'rig-resub-own@example.org', 'status' => 'inactive', 'source' => 'manual' ) );
$GLOBALS['rig_mails'] = array();
rig_check( 'Usual answer', 'success', rig_signup( 'rig-resub-own@example.org', (int) $uid ) );
rig_check( 'Stays Inactive until they press the button', 'inactive', rig_status( 'rig-resub-own@example.org' ) );
rig_check( 'Gets the subscribe-again email', 'Confirm you want to subscribe again', (string) ( $GLOBALS['rig_mails'][0]['subject'] ?? '' ) );

echo "Per-address cap\n";
rig_clear_limits();
$mm->add_member( array( 'name' => 'Rig Flood', 'email' => 'rig-resub-flood@example.org', 'status' => 'inactive', 'source' => 'manual' ) );
$mm->add_member( array( 'name' => 'Rig Other', 'email' => 'rig-resub-other@example.org', 'status' => 'unsubscribed', 'source' => 'manual' ) );
$GLOBALS['rig_mails'] = array();
$banners = array();
for ( $i = 0; $i < 5; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . ( 10 + $i ); // A different visitor each time.
	$banners[]              = rig_signup( 'rig-resub-flood@example.org' );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
rig_check( 'Five visitors, five usual answers', array_fill( 0, 5, 'success' ), $banners );
rig_check( 'Only 3 emails reach that address', 3, count( $GLOBALS['rig_mails'] ) );
rig_check( 'Still Inactive', 'inactive', rig_status( 'rig-resub-flood@example.org' ) );
$flood    = $mm->get_member_by_email( 'rig-resub-flood@example.org' );
$kept     = get_post_meta( (int) $flood->id, '_npmp_signup_confirm', true );
$last_key = rig_link_from( $GLOBALS['rig_mails'][2] ?? array(), 'npmp_confirm_resubscribe' );
rig_check( 'The newest link sent still carries the saved key', true, is_array( $kept ) && false !== strpos( $last_key, 'join=' . $kept['key'] ) );
$GLOBALS['rig_mails'] = array();
rig_signup( 'rig-resub-other@example.org' );
rig_check( 'Another address still gets its email', 1, count( $GLOBALS['rig_mails'] ) );

echo "Clean up\n";
$sweep();
$left = 0;
foreach ( $emails as $e ) {
	$left += $mm->get_member_by_email( $e ) ? 1 : 0;
}
rig_check( 'No rig contacts left', 0, $left );
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
