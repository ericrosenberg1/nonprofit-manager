<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/security-regressions.php
//
// Regressions from the 2026-09-24 audit, on a real WordPress with free and Pro
// active and every feature module on (option npmp_enabled_features). Checks
// capability gates a Contributor used to slip past, the public signup form,
// PayPal replays, the Stripe return URL, private import files, notification
// recipients, click tracking and Pro's segment filter. Leaves no contacts or
// users behind. Not run by the pre-push gate (it needs MySQL and WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = parse_url( 'http://127.0.0.1:10187', PHP_URL_HOST );
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
require_once ABSPATH . 'wp-admin/includes/post.php';
// A fresh visitor address per run, so the per-visitor limits don't carry over.
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
// wp_send_json_* and wp_die end the request. Turn them into exceptions.
// An Error, not an Exception, so handlers with catch ( Exception ) blocks do not swallow it.
class Rig_Stop extends Error { public $payload; }
add_filter( 'wp_die_ajax_handler', function () { return function ( $msg ) { $e = new Rig_Stop( 'die' ); $e->payload = $msg; throw $e; }; } );
add_filter( 'wp_die_handler', function () { return function ( $msg ) { $e = new Rig_Stop( 'die' ); $e->payload = $msg; throw $e; }; } );
add_filter( 'wp_doing_ajax', '__return_true' );
function rig_ajax( $callable ) {
	ob_start();
	try { call_user_func( $callable ); } catch ( Rig_Stop $e ) { /* ended */ }
	$out = ob_get_clean();
	return json_decode( $out, true );
}
function rig_user( $login, $role ) {
	$id = username_exists( $login ) ?: wp_create_user( $login, wp_generate_password(), $login . '@example.org' );
	( new WP_User( $id ) )->set_role( $role );
	return (int) $id;
}
$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
$contrib = rig_user( 'rig_contrib', 'contributor' );
$editor  = rig_user( 'rig_editor', 'editor' );
$mm      = NPMP_Member_Manager::get_instance();
$cleanup_emails = array( 'rig-unsub@example.org', 'rig-sub@example.org', 'rig-new@example.org', 'rig-donor@example.org' );
foreach ( $cleanup_emails as $e ) { $m = $mm->get_member_by_email( $e ); if ( $m ) { $mm->delete_member( $m->id ); } }

echo "== Newsletter send needs Editor ==\n";
wp_set_current_user( $contrib );
$nl = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_title' => 'rig', 'post_status' => 'draft', 'post_author' => $contrib ) );
$_POST = array( 'post_id' => $nl, 'nonce' => wp_create_nonce( 'npmp_send_newsletter_' . $nl ) );
$r = rig_ajax( function () { do_action( 'wp_ajax_npmp_send_newsletter_now' ); } );
rig_check( 'contributor refused', false, $r['success'] ?? null );
wp_set_current_user( $editor );
$_POST = array( 'post_id' => $nl, 'nonce' => wp_create_nonce( 'npmp_send_newsletter_' . $nl ), 'levels' => array( '__all__' ) );
$r = rig_ajax( function () { do_action( 'wp_ajax_npmp_send_newsletter_now' ); } );
rig_check( 'editor gets past the gate', true, isset( $r['success'] ) && ( $r['success'] || false === strpos( (string) ( $r['data'] ?? '' ), 'Permission' ) ) );

echo "== Convert to event ==\n";
wp_set_current_user( $contrib );
$draft = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'rig convert', 'post_status' => 'draft', 'post_author' => $contrib ) );
$_POST = array( 'post_id' => $draft, 'nonce' => wp_create_nonce( 'npmp_convert_event_' . $draft ), 'start' => '2030-01-01 10:00', 'delete_original' => '1' );
$r = rig_ajax( 'npmp_ajax_convert_to_event' );
$ev = (int) ( $r['data']['event_id'] ?? 0 );
rig_check( 'contributor event is pending, not published', 'pending', $ev ? get_post_status( $ev ) : null );
wp_set_current_user( 1 );
$pub = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'rig convert 2', 'post_status' => 'draft' ) );
$_POST = array( 'post_id' => $pub, 'nonce' => wp_create_nonce( 'npmp_convert_event_' . $pub ), 'start' => '2030-01-01 10:00' );
$r = rig_ajax( 'npmp_ajax_convert_to_event' );
$ev2 = (int) ( $r['data']['event_id'] ?? 0 );
rig_check( 'admin event is published', 'publish', $ev2 ? get_post_status( $ev2 ) : null );

echo "== Share Now ==\n";
$shared = 0;
add_filter( 'npmp_social_share_networks_pre', function ( $v ) use ( &$shared ) { $shared++; return $v; } );
wp_set_current_user( $contrib );
$d2 = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'rig share', 'post_status' => 'draft', 'post_author' => $contrib ) );
delete_post_meta( $d2, '_npmp_shared_on' );
$_POST = array( 'npmp_share_now' => '1', 'npmp_social_manual_nonce' => wp_create_nonce( 'npmp_social_manual_share' ) );
npmp_social_handle_manual_share( $d2 );
rig_check( 'draft by contributor not shared', '', (string) get_post_meta( $d2, '_npmp_shared_on', true ) );
$_POST = array();

echo "== Public signup ==\n";
$uid = $mm->add_member( array( 'email' => 'rig-unsub@example.org', 'name' => 'Real Name', 'status' => 'unsubscribed' ) );
$sid = $mm->add_member( array( 'email' => 'rig-sub@example.org', 'name' => 'Kept Name', 'status' => 'subscribed' ) );
wp_set_current_user( 0 );
function rig_signup( $email, $name, $extra = array() ) {
	$_POST = array_merge( array( 'npmp_action' => 'email_signup', 'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ), 'npmp_email' => $email, 'npmp_name' => $name ), $extra );
	add_filter( 'wp_redirect', function () { throw new Rig_Stop( 'redirect' ); }, 1 );
	try { npmp_handle_membership_form(); } catch ( Rig_Stop $e ) { /* redirected */ }
	remove_all_filters( 'wp_redirect', 1 );
	$_POST = array();
	wp_cache_flush();
}
$mails = array();
rig_signup( 'rig-unsub@example.org', 'Attacker Text' );
$m = $mm->get_member_by_email( 'rig-unsub@example.org' );
rig_check( 'unsubscribed contact stays unsubscribed', 'unsubscribed', $m->status );
rig_check( 'unsubscribed contact name kept', 'Real Name', $m->name );
rig_check( 'confirmation email sent to them', 1, count( $mails ) );
rig_signup( 'rig-sub@example.org', 'Attacker Text' );
rig_check( 'existing name not overwritten', 'Kept Name', $mm->get_member_by_email( 'rig-sub@example.org' )->name );
rig_signup( 'rig-new@example.org', 'Bot', array( 'npmp_signup_website' => 'http://spam' ) );
rig_check( 'honeypot adds nobody', null, $mm->get_member_by_email( 'rig-new@example.org' ) );
// Confirm link: GET shows a button, POST resubscribes.
$_REQUEST = array( 'email' => 'rig-unsub@example.org', 'token' => npmp_generate_resubscribe_token( 'rig-unsub@example.org' ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
try { npmp_handle_confirm_resubscribe(); } catch ( Rig_Stop $e ) {}
wp_cache_flush();
rig_check( 'GET on confirm link changes nothing', 'unsubscribed', $mm->get_member_by_email( 'rig-unsub@example.org' )->status );
$_SERVER['REQUEST_METHOD'] = 'POST';
try { npmp_handle_confirm_resubscribe(); } catch ( Rig_Stop $e ) {}
wp_cache_flush();
rig_check( 'POST on confirm link resubscribes', 'subscribed', $mm->get_member_by_email( 'rig-unsub@example.org' )->status );

echo "== One-click unsubscribe ==\n";
$_REQUEST = array( 'email' => 'rig-sub@example.org', 'token' => npmp_generate_unsubscribe_token( 'rig-sub@example.org' ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
try { npmp_handle_one_click_unsubscribe(); } catch ( Rig_Stop $e ) {}
wp_cache_flush();
rig_check( 'link scanner GET does not unsubscribe', 'subscribed', $mm->get_member_by_email( 'rig-sub@example.org' )->status );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'npmp_confirm' => '1' );
add_filter( 'wp_redirect', function () { throw new Rig_Stop( 'redirect' ); }, 1 );
try { npmp_handle_one_click_unsubscribe(); } catch ( Rig_Stop $e ) {}
remove_all_filters( 'wp_redirect', 1 );
wp_cache_flush();
rig_check( 'button POST unsubscribes', 'unsubscribed', $mm->get_member_by_email( 'rig-sub@example.org' )->status );
$_POST = array(); $_REQUEST = array(); $_SERVER['REQUEST_METHOD'] = 'GET';

echo "== Notifications skip unsubscribed ==\n";
update_post_meta( $m->id, '_npmp_weekly_digest', '1' );
$sub_row = $mm->get_member_by_email( 'rig-sub@example.org' );
update_post_meta( $sub_row->id, '_npmp_weekly_digest', '1' );
$ids = get_posts( array( 'post_type' => 'npmp_contact', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( 'relation' => 'AND', array( 'key' => '_npmp_weekly_digest', 'value' => '1' ), array( 'relation' => 'OR', array( 'key' => 'npmp_status', 'compare' => 'NOT EXISTS' ), array( 'key' => 'npmp_status', 'value' => 'unsubscribed', 'compare' => '!=' ) ) ) ) );
rig_check( 'digest query keeps the subscribed contact', true, in_array( (int) $m->id, array_map( 'intval', $ids ), true ) );
rig_check( 'digest query drops the unsubscribed one', false, in_array( (int) $sub_row->id, array_map( 'intval', $ids ), true ) );

echo "== PayPal replay ==\n";
delete_option( 'npmp_paypal_live_secret' ); delete_option( 'npmp_paypal_sandbox_secret' );
$mails = array();
$_POST = array( 'nonce' => wp_create_nonce( 'npmp_donation' ), 'email' => 'rig-donor@example.org', 'name' => 'Donor', 'amount' => '5', 'gateway' => 'paypal_api', 'transaction_id' => 'RIGORDER' . wp_rand() );
$first  = rig_ajax( 'npmp_ajax_log_donation' ); if ( empty( $first['success'] ) ) { echo '    first: ' . wp_json_encode( $first ) . "\n"; }
$count1 = count( $mails );
$_POST['email'] = 'victim@example.org'; $_POST['name'] = 'Click here: evil.example';
$second = rig_ajax( 'npmp_ajax_log_donation' );
rig_check( 'first capture recorded', true, ! empty( $first['success'] ) );
rig_check( 'replay answers the same donation', $first['data']['donation_id'] ?? 'x', $second['data']['donation_id'] ?? 'y' );
rig_check( 'replay sends no email', $count1, count( $mails ) );
rig_check( 'replay adds no member', null, $mm->get_member_by_email( 'victim@example.org' ) );
$_POST = array();

echo "== Stripe return URL ==\n";
rig_check( 'fragment dropped', home_url( '/donate/' ), npmp_payment_return_base( home_url( '/donate/#give' ) ) );
rig_check( 'stale status args dropped', home_url( '/donate/?x=1' ), npmp_payment_return_base( home_url( '/donate/?x=1&npmp_donation=success&npmp_session_id=cs_1' ) ) );

echo "== Import files are private ==\n";
$up  = wp_upload_dir();
$src = trailingslashit( $up['path'] ) . 'members-rig.csv';
file_put_contents( $src, "email\nrig@example.org\n" );
$dst = npmp_import_privatize_upload( $src );
rig_check( 'moved out of the public path', false, file_exists( $src ) );
rig_check( 'lands in npmp-private', true, is_string( $dst ) && false !== strpos( $dst, '/npmp-private/import-' ) );
rig_check( 'deny rules written', true, file_exists( dirname( $dst ) . '/.htaccess' ) && file_exists( dirname( $dst ) . '/index.php' ) );
rig_check( 'cleanup scheduled', true, (bool) wp_next_scheduled( 'npmp_delete_import_file', array( $dst ) ) );
npmp_delete_import_file( ABSPATH . 'wp-config.php' );
rig_check( 'cleanup refuses paths outside the folder', true, file_exists( ABSPATH . 'wp-config.php' ) );
npmp_delete_import_file( $dst );
rig_check( 'cleanup deletes the import file', false, file_exists( $dst ) );
wp_clear_scheduled_hook( 'npmp_delete_import_file', array( $dst ) );

echo "== Click tracking with percent escapes ==\n";
$tracker = NPMP_Newsletter_Tracker::get_instance();
$target  = 'https://example.org/page?utm_campaign=fall%20drive&q=a%2Bb';
$link    = $tracker->create_tracked_url( $target, 7, 9 );
parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $q );
$_GET = $q;
$loc = null;
add_filter( 'wp_redirect', function ( $l ) use ( &$loc ) { $loc = $l; throw new Rig_Stop( 'redirect' ); }, 1 );
try { $tracker->process_click(); } catch ( Rig_Stop $e ) {}
remove_all_filters( 'wp_redirect', 1 );
rig_check( 'redirects to the exact signed URL', $target, $loc );
$_GET = array();

echo "== Pro segments reach the send ==\n";
$flt = function ( $r, $id ) { return array( array( 'email' => 'rig-unsub@example.org', 'name' => 'x', 'user_id' => 1 ), array( 'email' => 'rig-sub@example.org', 'name' => 'y', 'user_id' => 2 ) ); };
add_filter( 'npmp_newsletter_recipients', $flt, 99, 2 );
$got = NPMP_Newsletter_Manager::apply_recipient_filter( array( (object) array( 'ID' => 5, 'user_email' => 'someone@example.org', 'name' => '' ) ), $nl );
remove_filter( 'npmp_newsletter_recipients', $flt, 99 );
rig_check( 'segment replaces the audience, unsubscribed removed', array( 'rig-unsub@example.org' ), array_map( function ( $o ) { return $o->user_email; }, $got ) );

echo "== Pro: segment operators survive saving ==\n";
rig_check( '> kept', '>', NPMP_Segment_Builder::clean_operator( '>' ) );
rig_check( '!= kept', '!=', NPMP_Segment_Builder::clean_operator( '!=' ) );
rig_check( 'unknown falls back to =', '=', NPMP_Segment_Builder::clean_operator( 'DROP' ) );

echo "== Pro: basil invoices ==\n";
$inv = json_decode( '{"parent":{"subscription_details":{"subscription":"sub_basil"}}}' );
rig_check( 'subscription read from parent', 'sub_basil', npmp_stripe_invoice_subscription_id( $inv ) );
rig_check( 'legacy field still read', 'sub_old', npmp_stripe_invoice_subscription_id( json_decode( '{"subscription":"sub_old"}' ) ) );
$secret = 'whsec_rig';
$t      = time();
$sig    = hash_hmac( 'sha256', $t . '.{}', $secret );
rig_check( 'second v1 signature accepted (secret roll)', true, npmp_verify_stripe_signature( '{}', "t=$t,v1=" . str_repeat( 'a', 64 ) . ",v1=$sig", $secret ) );
rig_check( 'bad signatures refused', false, npmp_verify_stripe_signature( '{}', "t=$t,v1=" . str_repeat( 'a', 64 ), $secret ) );

echo "== Signup and Stripe session rate limits ==\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
$made = 0;
for ( $i = 0; $i < 12; $i++ ) {
	$e = "rig-rl$i@example.org";
	rig_signup( $e, 'RL' );
	if ( $mm->get_member_by_email( $e ) ) { $made++; }
	$cleanup_emails[] = $e;
}
rig_check( 'one visitor adds at most 10 signups an hour', 10, $made );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
rig_signup( 'rig-rl-other@example.org', 'Other' );
rig_check( 'another visitor is not blocked', true, (bool) $mm->get_member_by_email( 'rig-rl-other@example.org' ) );
$cleanup_emails[] = 'rig-rl-other@example.org';
update_option( 'npmp_stripe_live_secret_key', 'sk_live_rigdummy' );
add_filter( 'pre_http_request', $rig_stripe_stub = function ( $pre, $args, $url ) { return false !== strpos( $url, 'api.stripe.com' ) ? array( 'headers' => array(), 'body' => '{"id":"cs_rig","url":"https://checkout.stripe.com/x"}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ) : $pre; }, 10, 3 );
$ok = 0; $limited = false;
for ( $i = 0; $i < 11; $i++ ) {
	$_POST = array( 'nonce' => wp_create_nonce( 'npmp_stripe_checkout' ), 'amount' => '5', 'email' => 'rig-donor@example.org' );
	$r = rig_ajax( 'npmp_ajax_create_stripe_session' );
	if ( ! empty( $r['success'] ) ) { $ok++; } elseif ( false !== strpos( (string) ( $r['data'] ?? '' ), 'Too many' ) ) { $limited = true; }
}
remove_filter( 'pre_http_request', $rig_stripe_stub, 10 );
delete_option( 'npmp_stripe_live_secret_key' );
$_POST = array();
rig_check( 'Stripe sessions capped at 10 per visitor', 10, $ok );
rig_check( 'the 11th is told to wait', true, $limited );

echo "== PayPal payee must be this site ==\n";
update_option( 'npmp_paypal_merchant_' . md5( 'live|CLIENT' ), 'OURMERCHANT1' );
$order = function ( $merchant, $email = '' ) { return array( 'purchase_units' => array( array( 'payee' => array( 'merchant_id' => $merchant, 'email_address' => $email ) ) ) ); };
rig_check( 'own merchant accepted', true, npmp_paypal_payee_is_ours( $order( 'OURMERCHANT1' ), 'https://api-m.paypal.com', 't', 'live', 'CLIENT' ) );
rig_check( 'other merchant refused', 'npmp_paypal_wrong_payee', npmp_paypal_payee_is_ours( $order( 'ATTACKER99' ), 'https://api-m.paypal.com', 't', 'live', 'CLIENT' )->get_error_code() );
delete_option( 'npmp_paypal_merchant_' . md5( 'live|CLIENT' ) );
set_transient( 'npmp_paypal_merchant_' . md5( 'live|CLIENT2' ) . '_retry', 1, 60 );
update_option( 'npmp_paypal_email', 'Giving@Example.org' );
rig_check( 'saved PayPal email accepted', true, npmp_paypal_payee_is_ours( $order( '', 'giving@example.org' ), 'https://api-m.paypal.com', 't', 'live', 'CLIENT2' ) );
rig_check( 'other email refused', true, is_wp_error( npmp_paypal_payee_is_ours( $order( '', 'thief@example.org' ), 'https://api-m.paypal.com', 't', 'live', 'CLIENT2' ) ) );
delete_option( 'npmp_paypal_email' );
add_filter( 'pre_http_request', $rig_pp_stub = function ( $pre, $args, $url ) { return false !== strpos( $url, '/v2/checkout/orders' ) ? array( 'headers' => array(), 'body' => '{"id":"X","purchase_units":[{"payee":{"merchant_id":"LEARNED777"}}]}', 'response' => array( 'code' => 201, 'message' => 'Created' ), 'cookies' => array(), 'filename' => null ) : $pre; }, 10, 3 );
rig_check( 'merchant id learned from an unapproved order', 'LEARNED777', npmp_paypal_own_merchant_id( 'https://api-m.paypal.com', 't', 'live', 'CLIENT3' ) );
remove_filter( 'pre_http_request', $rig_pp_stub, 10 );
delete_option( 'npmp_paypal_merchant_' . md5( 'live|CLIENT3' ) );

echo "== Newsletters stay out of the public REST API ==\n";
wp_set_current_user( 0 );
rig_check( 'anonymous newsletter list refused', 401, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/npmp_newsletter' ) )->get_status() );
wp_set_current_user( 1 );
rig_check( 'admin newsletter list allowed', 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/npmp_newsletter' ) )->get_status() );

// Cleanup.
foreach ( array_merge( $cleanup_emails, array( 'victim@example.org' ) ) as $e ) { $x = $mm->get_member_by_email( $e ); if ( $x ) { $mm->delete_member( $x->id ); } }
foreach ( array( $nl, $draft, $pub, $d2, $ev, $ev2 ) as $p ) { if ( $p ) { wp_delete_post( $p, true ); } }
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $contrib ); wp_delete_user( $editor );

if ( $GLOBALS['rig_errors'] ) { echo "\nPHP notices from the plugins:\n  " . implode( "\n  ", array_unique( $GLOBALS['rig_errors'] ) ) . "\n"; }
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
