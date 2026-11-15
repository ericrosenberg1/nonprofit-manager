<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/email-members-sweep.php
//
// The real-WordPress half of tests/test-email-members-sweep.php (the 2026-11
// email and members sweep):
// - We send one address at most 3 unsubscribe confirmations an hour, whoever
//   asks, and show the same answer past the cap.
// - We print no signed link for the address in [npmp_can_spam email="..."].
// - We queue no "New event" email for an event an importer writes as
//   published, and still queue one when someone publishes an event.
// - We answer Send now in plain text.
// - We save an SMTP password with %41, a tag shape, and a double space as typed.
// - We render a template that includes itself once.
// We catch mail with pre_wp_mail, put back every option we change, and delete
// the posts and contacts we make. Run it with or without Pro. The pre-push
// gate doesn't run it. Before the fix, the template check ran the request out
// of memory, and the run stopped there.
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
require_once ABSPATH . 'wp-admin/includes/template.php'; // submit_button() on Email Settings.

// Feature flags decide which files load, so they must be on before this runs:
// wp option update npmp_enabled_features '{"members":true,"newsletters":true,"donations":true,"calendar":true,"social":false}' --format=json
$features = (array) get_option( 'npmp_enabled_features', array() );
if ( empty( $features['members'] ) || empty( $features['newsletters'] ) || empty( $features['calendar'] ) ) {
	fwrite( STDERR, "Turn on the members, newsletters, and calendar features first (see the comment above).\n" );
	exit( 2 );
}

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

class Rig_Stop extends Error { public $payload; }
function rig_stop( $msg ) { $e = new Rig_Stop( 'stop' ); $e->payload = $msg; throw $e; }
add_filter( 'wp_die_handler', function () { return function ( $msg ) { rig_stop( is_string( $msg ) ? $msg : '' ); }; } );
add_filter( 'wp_die_ajax_handler', function () { return function ( $msg ) { rig_stop( is_string( $msg ) ? $msg : '' ); }; } );
add_filter( 'wp_redirect', function ( $location ) { rig_stop( $location ); }, 999 );
// The per-visitor and site-wide caps would trip over one IP's many posts, so
// they're off here. The per-address cap keeps its default.
add_filter( 'npmp_rate_limit_max', function ( $max, $bucket ) { return 'unsubscribe_address' === $bucket ? $max : 0; }, 10, 2 );
$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );

// Runs a callable with the request set. Returns what it printed, and the
// redirect or die payload when it stopped.
function rig_run( $callable, $post = array(), &$stopped = null ) {
	$_POST    = $post;
	$_REQUEST = $post;
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	$stopped  = null;
	$level    = ob_get_level();
	ob_start();
	try {
		call_user_func( $callable );
	} catch ( Rig_Stop $e ) {
		$stopped = (string) $e->payload;
	}
	// Close any buffer the code under test left open too, or this rig's own
	// output disappears into it.
	$printed = '';
	while ( ob_get_level() > $level ) {
		$printed = ob_get_clean() . $printed;
	}
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $printed;
}
function rig_clear_limits() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_npmp\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_npmp\\_rl\\_%'" );
	wp_cache_flush();
}

// Options this rig writes, put back at the end.
$missing = '__rig_missing__';
$watched = array( 'npmp_email_settings', 'npmp_email_method', 'npmp_email_from_email', 'npmp_email_from_name', 'npmp_smtp_host', 'npmp_smtp_port', 'npmp_smtp_encryption', 'npmp_smtp_auth', 'npmp_smtp_username', 'npmp_smtp_password', 'npmp_enable_event_notifications', 'npmp_enable_post_notifications' );
$saved   = array();
foreach ( $watched as $o ) {
	$saved[ $o ] = get_option( $o, $missing );
}
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
$mm       = NPMP_Member_Manager::get_instance();
$posts    = array();
$email    = 'rig-sweep-unsub@example.org';
$cleanup  = function () use ( $mm, $email, &$posts ) {
	$m = $mm->get_member_by_email( $email );
	if ( $m ) {
		$mm->delete_member( (int) $m->id );
	}
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	wp_clear_scheduled_hook( 'npmp_async_post_notification' );
};
$cleanup();
rig_clear_limits();

echo "== Unsubscribe form: 3 emails an hour per address ==\n";
$mm->add_member( array( 'name' => 'Rig Unsub', 'email' => $email, 'status' => 'subscribed', 'source' => 'manual' ) );
$landed = array();
for ( $i = 0; $i < 5; $i++ ) {
	rig_run(
		'npmp_handle_membership_form',
		array(
			'action'                       => 'npmp_handle_form',
			'npmp_action'                  => 'email_unsubscribe',
			'npmp_email_unsubscribe_nonce' => wp_create_nonce( 'npmp_email_unsubscribe' ),
			'npmp_email'                   => $email,
		),
		$stop
	);
	$landed[] = preg_match( '/npmp_unsubscribe=([a-z_]+)/', (string) $stop, $b ) ? $b[1] : (string) $stop;
}
$to_them = array_filter( $GLOBALS['rig_mails'], function ( $m ) use ( $email ) { return in_array( $email, (array) $m['to'], true ); } );
rig_check( 'five requests send three emails', 3, count( $to_them ) );
rig_check( 'every request lands on the same answer', array( 'confirm', 'confirm', 'confirm', 'confirm', 'confirm' ), $landed );
rig_check( 'the contact is still subscribed', 'subscribed', (string) $mm->get_member_by_email( $email )->status );
rig_clear_limits();

echo "== [npmp_can_spam email=...] prints no signed link ==\n";
$footer = do_shortcode( '[npmp_can_spam email="' . $email . '"]' );
$token  = function_exists( 'npmp_generate_unsubscribe_token' ) ? npmp_generate_unsubscribe_token( $email ) : 'no-token-function';
rig_check( 'the footer has no token for that address', false, false !== strpos( $footer, $token ) );
rig_check( 'the footer has no copy of the address', false, false !== strpos( $footer, rawurlencode( $email ) ) || false !== strpos( $footer, $email ) );
$send_footer = npmp_can_spam_shortcode( array( 'email' => $email ) );
$link_ok     = false !== strpos( $send_footer, $token ) || '' === trim( (string) get_option( 'npmp_newsletter_can_spam_footer' ) );
rig_check( 'a send still builds the reader\'s own link', true, $link_ok );

echo "== Imported events queue no notification ==\n";
update_option( 'npmp_enable_event_notifications', 1 );
wp_clear_scheduled_hook( 'npmp_async_post_notification' );
$imported = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig imported event', 'meta_input' => array( '_npmp_event_start' => '2030-01-01 10:00:00', '_npmp_import_key' => 'rig:1', '_npmp_import_source' => 'rig' ) ) );
$posts[]  = $imported;
rig_check( 'an event inserted with _npmp_import_key schedules nothing', false, (bool) wp_next_scheduled( 'npmp_async_post_notification', array( $imported, '_npmp_notify_events' ) ) );
rig_check( 'and never gets marked sent', '', (string) get_post_meta( $imported, '_npmp_notification_sent', true ) );
$tec = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig TEC event', 'meta_input' => array( '_npmp_event_start' => '2030-01-02 10:00:00', '_npmp_tec_source_id' => 99001 ) ) );
$posts[] = $tec;
rig_check( 'an event inserted with _npmp_tec_source_id schedules nothing', false, (bool) wp_next_scheduled( 'npmp_async_post_notification', array( $tec, '_npmp_notify_events' ) ) );
$during = 0;
if ( function_exists( 'npmp_import_run_as_import' ) ) {
	npmp_import_run_as_import( function () use ( &$during ) {
		$during = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig during import', 'meta_input' => array( '_npmp_event_start' => '2030-01-03 10:00:00' ) ) );
	} );
	$posts[] = $during;
}
rig_check( 'an event made inside npmp_import_run_as_import() schedules nothing', false, $during && (bool) wp_next_scheduled( 'npmp_async_post_notification', array( $during, '_npmp_notify_events' ) ) );
$hand    = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'draft', 'post_title' => 'Rig hand event', 'meta_input' => array( '_npmp_event_start' => '2030-01-04 10:00:00' ) ) );
$posts[] = $hand;
wp_publish_post( $hand );
rig_check( 'an event someone publishes still schedules its email', true, (bool) wp_next_scheduled( 'npmp_async_post_notification', array( $hand, '_npmp_notify_events' ) ) );
wp_update_post( array( 'ID' => $imported, 'post_status' => 'draft' ) );
wp_publish_post( $imported );
rig_check( 'an imported event someone republishes later notifies', true, (bool) wp_next_scheduled( 'npmp_async_post_notification', array( $imported, '_npmp_notify_events' ) ) );
wp_clear_scheduled_hook( 'npmp_async_post_notification' );

echo "== Send now answers in plain text ==\n";
wp_set_current_user( $admin_id );
add_filter( 'wp_doing_ajax', '__return_true' );
$nl      = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Rig imported newsletter', 'meta_input' => array( '_npmp_import_key' => 'rig:nl1' ) ) );
$posts[] = $nl;
$printed = rig_run( function () { do_action( 'wp_ajax_npmp_send_newsletter_now' ); }, array( 'post_id' => $nl, 'nonce' => wp_create_nonce( 'npmp_send_newsletter_' . $nl ) ) );
$json    = json_decode( $printed, true );
rig_check( 'an imported newsletter is refused', false, $json['success'] ?? null );
rig_check( 'the refusal reads "can\'t", not &#039;', true, false !== strpos( (string) ( $json['data'] ?? '' ), "can't" ) && false === strpos( (string) ( $json['data'] ?? '' ), '&#' ) );
remove_filter( 'wp_doing_ajax', '__return_true' );

echo "== SMTP password saves as typed ==\n";
$typed = 'ab%41cd<ef>  gh';
rig_run(
	'npmp_render_email_settings_page',
	array(
		'npmp_email_settings_nonce' => wp_create_nonce( 'npmp_email_settings' ),
		'npmp_email_from_name'      => 'Rig Site',
		'npmp_email_from_email'     => 'rig@example.org',
		'npmp_email_provider'       => 'smtp',
		'npmp_smtp_host'            => 'smtp.example.org',
		'npmp_smtp_port'            => '587',
		'npmp_smtp_encryption'      => 'tls',
		'npmp_smtp_auth'            => '1',
		'npmp_smtp_username'        => 'rig-user',
		'npmp_smtp_password'        => wp_slash( $typed ),
	)
);
wp_cache_delete( 'npmp_email_settings', 'options' );
$got = npmp_email_get_settings()['smtp']['password'] ?? null;
rig_check( 'the saved password matches what was typed', $typed, $got );
rig_check( 'the legacy option matches too', $typed, get_option( 'npmp_smtp_password' ) );
wp_set_current_user( 0 );

echo "== A template that includes itself ==\n";
$tpl     = wp_insert_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig loop', 'post_content' => 'LOOP-START' ) );
$posts[] = $tpl;
wp_update_post( array( 'ID' => $tpl, 'post_content' => 'LOOP-START [npmp_nl_template id="' . $tpl . '"] LOOP-END' ) );
$html = do_shortcode( '[npmp_nl_template id="' . $tpl . '"]' );
rig_check( 'renders once', 1, substr_count( $html, 'LOOP-START' ) );
rig_check( 'and finishes', 1, substr_count( $html, 'LOOP-END' ) );

// Put everything back.
$cleanup();
rig_clear_limits();
foreach ( $saved as $o => $v ) {
	if ( $missing === $v ) {
		delete_option( $o );
	} else {
		update_option( $o, $v );
	}
}

if ( $GLOBALS['rig_errors'] ) {
	echo "\nPHP notices from the plugin:\n  " . implode( "\n  ", array_unique( $GLOBALS['rig_errors'] ) ) . "\n";
}
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
