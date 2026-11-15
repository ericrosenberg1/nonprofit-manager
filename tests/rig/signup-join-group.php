<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/signup-join-group.php
//
// An existing contact joins groups through [npmp_email_signup groups="..."
// choose="yes"], on a real WordPress with free and Pro active. Pete at U3A
// re-used the form for a subscriber and saw no new groups. Now a contact we
// already have gets an email to confirm the join, and only the button behind
// its link adds the groups, with double opt-in on or off. Groups are only
// ever added. An unsubscribed contact keeps the resubscribe email and gets
// the groups when they confirm. Pending contacts work as before. The visitor
// sees the same banner for every address. Nonce, captcha, and rate limit
// still stop a request before any email. Mail is caught with pre_wp_mail.
// Leaves no contacts, groups, pages, or settings behind. Not run by the
// pre-push gate.
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
require_once ABSPATH . 'wp-admin/includes/template.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
if ( ! function_exists( 'npmp_pro_ensure_group' ) ) {
	echo "  FAIL  needs free and Pro active, with Pro's group features loaded\n";
	exit( 1 );
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
$GLOBALS['rig_limit'] = 0;
add_filter( 'npmp_rate_limit_max', function ( $max, $bucket ) { return 'signup' === $bucket ? $GLOBALS['rig_limit'] : 0; }, 10, 2 );
$GLOBALS['rig_doi'] = true;
add_filter( 'npmp_double_optin_enabled', function () { return $GLOBALS['rig_doi']; } );
function rig_run( $callable ) {
	ob_start();
	try { call_user_func( $callable ); $r = null; } catch ( Rig_Stop $e ) { $r = $e; }
	ob_end_clean();
	return $r;
}
$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );

$mm = NPMP_Member_Manager::get_instance();

// Post the form as a logged-out visitor would, with the form's own signed
// attributes and the boxes they ticked. Returns the banner value it lands on.
function rig_signup( $form_html, $email, $ticked, $extra = array() ) {
	preg_match( '/name="npmp_signup_atts" value="([^"]*)"/', $form_html, $m );
	wp_set_current_user( 0 );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(
		array(
			'action'                  => 'npmp_handle_form',
			'npmp_action'             => 'email_signup',
			'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
			'npmp_name'               => 'Someone Typing',
			'npmp_email'              => $email,
			'npmp_signup_atts'        => isset( $m[1] ) ? html_entity_decode( $m[1] ) : '',
			'npmp_signup_groups'      => array_map( 'strval', $ticked ),
		),
		$extra
	);
	$_REQUEST = $_POST;
	$stop = rig_run( 'npmp_handle_membership_form' );
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$where = $stop ? (string) $stop->payload : '';
	return preg_match( '/npmp_signup=([a-z_]+)/', $where, $b ) ? $b[1] : $where;
}
function rig_link_from( $mail, $action ) {
	return preg_match( '#(https?://\S+action=' . $action . '\S*)#', (string) ( $mail['message'] ?? '' ), $m ) ? $m[1] : '';
}
// Open a link with GET, or press the page's button with POST. The POST sends
// the hidden fields the GET page printed, the way a browser would.
function rig_open( $link, $method, $handler ) {
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
	$_SERVER['REQUEST_METHOD'] = $method;
	if ( 'POST' === $method ) {
		$_POST = $q + array( 'npmp_confirm' => '1' );
		$_REQUEST = $_POST;
	} else {
		$_GET = $_REQUEST = $q;
	}
	$stop = rig_run( $handler );
	$_GET = $_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $stop;
}
function rig_page_fields( $html ) {
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)"/', (string) $html, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $row ) {
		$out[ $row[1] ] = html_entity_decode( $row[2] );
	}
	return $out;
}
function rig_groups( $email ) {
	$m = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
	$ids = $m ? npmp_pro_get_member_groups( (int) $m->id ) : array();
	sort( $ids );
	return $ids;
}

// --- Clean slate ---------------------------------------------------------
$emails = array( 'rig-join-sub@example.org', 'rig-join-both@example.org', 'rig-join-new@example.org', 'rig-join-new2@example.org', 'rig-join-off@example.org', 'rig-join-gone@example.org', 'rig-join-pend@example.org', 'rig-join-pend2@example.org', 'rig-join-limit@example.org' );
$sweep  = function () use ( $mm, $emails ) {
	foreach ( $emails as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( $m->id );
		}
	}
};
$sweep();
$made_groups = array();
foreach ( array( 'rig-join-art' => 'Rig Join Art', 'rig-join-history' => 'Rig Join History' ) as $slug => $label ) {
	$had = get_term_by( 'slug', $slug, NPMP_PRO_GROUP_TAXONOMY );
	$gid = npmp_pro_ensure_group( $label, $slug );
	if ( ! $had ) {
		$made_groups[] = $gid;
	}
}
$art  = (int) get_term_by( 'slug', 'rig-join-art', NPMP_PRO_GROUP_TAXONOMY )->term_id;
$hist = (int) get_term_by( 'slug', 'rig-join-history', NPMP_PRO_GROUP_TAXONOMY )->term_id;
$settings_before = get_option( 'npmp_membership_form_settings', null );
$s = npmp_get_membership_form_settings( true );
$s['signup_page_id'] = 0;
update_option( 'npmp_membership_form_settings', $s );
npmp_get_membership_form_settings( true );
$form = do_shortcode( '[npmp_email_signup groups="rig-join-art,rig-join-history" choose="yes"]' );
rig_check( 'The form offers both groups as checkboxes', 2, substr_count( $form, 'name="npmp_signup_groups[]"' ) );
$both = array( $art, $hist );
sort( $both );

echo "== A subscriber asks to join a group (double opt-in on) ==\n";
$sub = $mm->add_member( array( 'email' => 'rig-join-sub@example.org', 'name' => 'Pete Member', 'status' => 'subscribed' ) );
npmp_pro_add_member_to_groups( (int) $sub, array( $art ) );
$GLOBALS['rig_mails'] = array();
$new_banner = rig_signup( $form, 'rig-join-new@example.org', array( $art ) );
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-sub@example.org', array( $art, $hist ) );
rig_check( 'Same banner as a brand-new address', $new_banner, $banner );
rig_check( 'That banner is check_email', 'check_email', $banner );
rig_check( 'Nothing changes on submit', array( $art ), rig_groups( 'rig-join-sub@example.org' ) );
rig_check( 'One email, to that address', array( 'rig-join-sub@example.org' ), array_column( $GLOBALS['rig_mails'], 'to' ) );
$mail = $GLOBALS['rig_mails'][0] ?? array();
rig_check( 'Subject names the new group only', 'Confirm you want to join Rig Join History', (string) ( $mail['subject'] ?? '' ) );
rig_check( 'Body names it', true, false !== strpos( (string) ( $mail['message'] ?? '' ), 'add you to Rig Join History at' ) );
rig_check( 'Body is not the new-subscriber email', false, false !== strpos( (string) ( $mail['message'] ?? '' ), 'Thanks for signing up' ) );
$link = rig_link_from( $mail, 'npmp_confirm_signup' );
rig_check( 'Email carries a confirm link', true, '' !== $link );
$stop = rig_open( $link, 'GET', 'npmp_handle_confirm_signup' );
rig_check( 'GET shows a button that names the group', true, $stop && false !== strpos( $stop->payload, 'Press the button to join Rig Join History.' ) && false !== strpos( $stop->payload, 'value="npmp_confirm_signup"' ) );
rig_check( 'GET changes nothing', array( $art ), rig_groups( 'rig-join-sub@example.org' ) );
$stop = rig_open( preg_replace( '/token=[^&]+/', 'token=forged0000000000000000000000000', $link ), 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'A wrong key: 400', 400, $stop ? $stop->status : 0 );
rig_check( 'A wrong key changes nothing', array( $art ), rig_groups( 'rig-join-sub@example.org' ) );
$stop = rig_open( $link, 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'Pressing the button joins History and keeps Art', $both, rig_groups( 'rig-join-sub@example.org' ) );
rig_check( 'Says so', true, $stop && false !== strpos( $stop->payload, 'in your new groups' ) );
rig_check( 'Still subscribed', 'subscribed', $mm->get_member_by_email( 'rig-join-sub@example.org' )->status );
rig_check( 'Name untouched', 'Pete Member', $mm->get_member_by_email( 'rig-join-sub@example.org' )->name );
$stop = rig_open( $link, 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'The link works once', $both, rig_groups( 'rig-join-sub@example.org' ) );

echo "== Groups are only ever added ==\n";
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-sub@example.org', array( $hist ) );
rig_check( 'Unticking Art on the form: same banner', 'check_email', $banner );
rig_check( 'No join email when there is nothing new', false, false !== strpos( (string) ( $GLOBALS['rig_mails'][0]['subject'] ?? '' ), 'join' ) );
rig_open( rig_link_from( $GLOBALS['rig_mails'][0] ?? array(), 'npmp_confirm_signup' ), 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'Confirming that email leaves both groups', $both, rig_groups( 'rig-join-sub@example.org' ) );

echo "== Double opt-in off ==\n";
$GLOBALS['rig_doi'] = false;
$off = $mm->add_member( array( 'email' => 'rig-join-off@example.org', 'name' => 'Olive Off', 'status' => 'subscribed' ) );
$GLOBALS['rig_mails'] = array();
$new_banner = rig_signup( $form, 'rig-join-new2@example.org', array( $art ) );
rig_check( 'A new address still joins at once', array( $art ), rig_groups( 'rig-join-new2@example.org' ) );
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-off@example.org', array( $art, $hist ) );
rig_check( 'Same banner as a new address', $new_banner, $banner );
rig_check( 'A stranger typing the address adds no groups', array(), rig_groups( 'rig-join-off@example.org' ) );
rig_check( 'The owner gets the join email', 'Confirm you want to join Rig Join Art and Rig Join History', (string) ( $GLOBALS['rig_mails'][0]['subject'] ?? '' ) );
rig_open( rig_link_from( $GLOBALS['rig_mails'][0] ?? array(), 'npmp_confirm_signup' ), 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'Their button adds both', $both, rig_groups( 'rig-join-off@example.org' ) );
$GLOBALS['rig_doi'] = true;

echo "== Someone who unsubscribed ==\n";
$gone = $mm->add_member( array( 'email' => 'rig-join-gone@example.org', 'name' => 'Gus Gone', 'status' => 'unsubscribed' ) );
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-gone@example.org', array( $hist ) );
rig_check( 'Same banner as a new address', 'check_email', $banner );
rig_check( 'Stays unsubscribed until they confirm', 'unsubscribed', $mm->get_member_by_email( 'rig-join-gone@example.org' )->status );
rig_check( 'No groups yet', array(), rig_groups( 'rig-join-gone@example.org' ) );
$mail = $GLOBALS['rig_mails'][0] ?? array();
rig_check( 'Keeps the resubscribe email', 'Confirm you want to subscribe again', (string) ( $mail['subject'] ?? '' ) );
rig_check( 'That email names the group', true, false !== strpos( (string) ( $mail['message'] ?? '' ), 'Rig Join History' ) );
$link = rig_link_from( $mail, 'npmp_confirm_resubscribe' );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $lq );
$stop = rig_open( add_query_arg( array( 'action' => 'npmp_confirm_signup', 'token' => $lq['join'] ?? '' ), $link ), 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'Its key does nothing on the signup confirm link', 'unsubscribed', $mm->get_member_by_email( 'rig-join-gone@example.org' )->status );
$stop = rig_open( $link, 'GET', 'npmp_handle_confirm_resubscribe' );
$page = $stop ? rig_page_fields( $stop->payload ) : array();
rig_check( 'The resubscribe page carries the join key to its button', $lq['join'] ?? 'missing', $page['join'] ?? '' );
rig_check( 'GET changes nothing', 'unsubscribed', $mm->get_member_by_email( 'rig-join-gone@example.org' )->status );
$stop = rig_open( add_query_arg( $page, admin_url( 'admin-post.php' ) ), 'POST', 'npmp_handle_confirm_resubscribe' );
rig_check( 'The button resubscribes them', 'subscribed', $mm->get_member_by_email( 'rig-join-gone@example.org' )->status );
rig_check( 'And adds the group', array( $hist ), rig_groups( 'rig-join-gone@example.org' ) );

echo "== Pending contacts work as before ==\n";
$pend = $mm->add_member( array( 'email' => 'rig-join-pend@example.org', 'name' => 'Pat Pending', 'status' => 'pending' ) );
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-pend@example.org', array( $art ) );
rig_check( 'check_email banner', 'check_email', $banner );
rig_check( 'The usual "please confirm" email', 0, strpos( (string) ( $GLOBALS['rig_mails'][0]['subject'] ?? '' ), 'Please confirm your subscription' ) );
rig_check( 'It names the group', true, false !== strpos( (string) ( $GLOBALS['rig_mails'][0]['message'] ?? '' ), 'You asked to join this group: Rig Join Art.' ) );
rig_open( rig_link_from( $GLOBALS['rig_mails'][0] ?? array(), 'npmp_confirm_signup' ), 'POST', 'npmp_handle_confirm_signup' );
rig_check( 'Confirming subscribes them', 'subscribed', $mm->get_member_by_email( 'rig-join-pend@example.org' )->status );
rig_check( 'And adds the group', array( $art ), rig_groups( 'rig-join-pend@example.org' ) );
$GLOBALS['rig_doi'] = false;
$mm->add_member( array( 'email' => 'rig-join-pend2@example.org', 'name' => 'Pia Pending', 'status' => 'pending' ) );
rig_signup( $form, 'rig-join-pend2@example.org', array( $hist ) );
rig_check( 'With double opt-in off, a pending contact joins at once', array( $hist ), rig_groups( 'rig-join-pend2@example.org' ) );
$GLOBALS['rig_doi'] = true;

echo "== The form's guards still run first ==\n";
$both_c = $mm->add_member( array( 'email' => 'rig-join-both@example.org', 'name' => 'Bea Both', 'status' => 'subscribed' ) );
$GLOBALS['rig_mails'] = array();
rig_signup( $form, 'rig-join-both@example.org', array( $art ), array( 'npmp_email_signup_nonce' => 'bad' ) );
rig_check( 'A bad nonce sends nothing', 0, count( $GLOBALS['rig_mails'] ) );
$captcha_on = function ( $name ) {
	return function () use ( $name ) {
		$fake = array( 'npmp_captcha_provider' => 'turnstile', 'npmp_turnstile_enabled' => 1, 'npmp_turnstile_site_key' => 'rig-site', 'npmp_turnstile_secret_key' => 'rig-secret' );
		return $fake[ $name ];
	};
};
$captcha_filters = array();
foreach ( array( 'npmp_captcha_provider', 'npmp_turnstile_enabled', 'npmp_turnstile_site_key', 'npmp_turnstile_secret_key' ) as $opt ) {
	$captcha_filters[ $opt ] = $captcha_on( $opt );
	add_filter( 'pre_option_' . $opt, $captcha_filters[ $opt ] );
}
$banner = rig_signup( $form, 'rig-join-both@example.org', array( $art ) );
foreach ( $captcha_filters as $opt => $cb ) {
	remove_filter( 'pre_option_' . $opt, $cb );
}
rig_check( 'A missing captcha answer stops it', 'captcha', $banner );
rig_check( 'And sends nothing', 0, count( $GLOBALS['rig_mails'] ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 2, 250 );
$GLOBALS['rig_limit']   = 1;
rig_signup( $form, 'rig-join-limit@example.org', array( $art ) );
$GLOBALS['rig_mails'] = array();
$banner = rig_signup( $form, 'rig-join-both@example.org', array( $art ) );
rig_check( 'Past the per-visitor limit: busy', 'busy', $banner );
rig_check( 'And sends nothing', 0, count( $GLOBALS['rig_mails'] ) );
rig_check( 'And joins nothing', array(), rig_groups( 'rig-join-both@example.org' ) );
delete_transient( 'npmp_rl_' . substr( md5( 'signup|' . $_SERVER['REMOTE_ADDR'] . '|' . wp_salt( 'nonce' ) ), 0, 24 ) );
$GLOBALS['rig_limit']   = 0;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

echo "== Banners ==\n";
foreach ( array( 'check_email' => 'Check your inbox', 'joined' => 'in your new groups' ) as $value => $text ) {
	$_GET = array( 'npmp_signup' => $value, 'npmp_signup_nonce' => wp_create_nonce( 'npmp_banner_npmp_signup_' . $value ) );
	rig_check( "Banner $value", true, false !== strpos( do_shortcode( '[npmp_email_signup]' ), $text ) );
}
$_GET = array();

// --- Clean up ------------------------------------------------------------
$sweep();
foreach ( $made_groups as $gid ) {
	wp_delete_term( (int) $gid, NPMP_PRO_GROUP_TAXONOMY );
}
if ( null === $settings_before ) {
	delete_option( 'npmp_membership_form_settings' );
} else {
	update_option( 'npmp_membership_form_settings', $settings_before );
}
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
