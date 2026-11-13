<?php
/**
 * Quick Add widgets on the WordPress Dashboard.
 *
 * - Quick Add Event saves a draft. It used to publish straight to the public
 *   calendar, feeds, and scheduled emails with only a title.
 * - Both forms save on load-index.php and redirect, so a reload doesn't post
 *   them again. They used to save while the widget rendered.
 * - Notices carry "inline" so core leaves them in the widget instead of
 *   lifting them to the top of the Dashboard.
 * - A missing class shows a plain message, never "Member Manager class not
 *   found."
 *
 * Run: php tests/test-dashboard-widgets.php
 */

require_once __DIR__ . '/bootstrap.php';

class T_Redirect extends Exception {}
class WP_Error {
	public function get_error_message() {
		return 'boom';
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

$GLOBALS['t_actions']  = array();
$GLOBALS['t_inserted'] = array();
$GLOBALS['t_can']      = array( 'manage_options', 'npmp_staff' );

function add_action( $hook, $cb ) {
	$GLOBALS['t_actions'][ $hook ] = $cb;
}
function npmp_staff_cap() {
	return 'npmp_staff';
}
function npmp_label( $key ) {
	return array( 'member' => 'Member', 'members' => 'Members' )[ $key ] ?? $key;
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['t_can'], true );
}
function wp_verify_nonce( $nonce, $action ) {
	return 'nonce-' . $action === $nonce ? 1 : false;
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_email( $v ) {
	return filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : '';
}
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) );
}
function wp_unslash( $v ) {
	return $v;
}
function absint( $v ) {
	return abs( (int) $v );
}
function wp_timezone() {
	return new DateTimeZone( 'America/Los_Angeles' );
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . $path;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function wp_insert_post( $args, $wp_error = false ) {
	$GLOBALS['t_inserted'][] = $args;
	return 77;
}
function wp_safe_redirect( $url ) {
	throw new T_Redirect( $url );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html__( $s ) {
	return esc_html( $s );
}

require_once dirname( __DIR__ ) . '/includes/npmp-dashboard-widgets.php';

function t_post( $post ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = $post;
	try {
		npmp_quick_add_handle_post();
	} catch ( T_Redirect $e ) {
		return $e->getMessage();
	}
	return null;
}

echo "Quick Add Event\n";
$args = npmp_quick_add_event_args( 'Spring picnic', '2031-05-06T18:30', 'Library' );
check( 'saves a draft', 'draft', $args['post_status'] );
check( 'event post type', 'npmp_event', $args['post_type'] );
check( 'start in site time, in meta_input before save_post', '2031-05-06 18:30:00', $args['meta_input']['_npmp_event_start'] );
check( 'location in meta_input', 'Library', $args['meta_input']['_npmp_event_location'] );
check( 'no date, no start meta', array(), npmp_quick_add_event_args( 'Picnic', '', '' )['meta_input'] );

check( 'handler hooked before the Dashboard prints', 'npmp_quick_add_handle_post', $GLOBALS['t_actions']['load-index.php'] ?? null );
$event = array(
	'npmp_quick_add_event_nonce' => 'nonce-npmp_quick_add_event',
	'npmp_event_title'           => 'Spring picnic',
	'npmp_event_datetime'        => '2031-05-06T18:30',
	'npmp_event_location'        => '',
);
$to = t_post( $event );
check( 'redirects to the Dashboard with the new draft', 'https://example.org/wp-admin/index.php?npmp_event=77&npmp_quick=event_added', $to );
check( 'inserted as a draft', 'draft', $GLOBALS['t_inserted'][0]['post_status'] ?? null );
$to = t_post( array_merge( $event, array( 'npmp_event_title' => '' ) ) );
check( 'no title: error flag', true, false !== strpos( (string) $to, 'npmp_quick=event_no_title' ) );
check( 'no title: nothing inserted', 1, count( $GLOBALS['t_inserted'] ) );
$to = t_post( array_merge( $event, array( 'npmp_quick_add_event_nonce' => 'bad' ) ) );
check( 'bad nonce: no redirect, nothing saved', array( null, 1 ), array( $to, count( $GLOBALS['t_inserted'] ) ) );
$GLOBALS['t_can'] = array( 'npmp_staff' );
$to = t_post( $event );
check( 'staff without manage_options cannot add events', array( null, 1 ), array( $to, count( $GLOBALS['t_inserted'] ) ) );
$GLOBALS['t_can'] = array( 'manage_options', 'npmp_staff' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST                     = $event;
npmp_quick_add_handle_post();
check( 'GET request ignored', 1, count( $GLOBALS['t_inserted'] ) );

echo "Notices\n";
$_GET = array( 'npmp_quick' => 'event_added', 'npmp_event' => '77' );
$html = npmp_quick_add_notice( 'event' );
check( 'event notice is inline', true, false !== strpos( $html, '<div class="notice notice-success inline">' ) );
check( 'event notice says draft', true, false !== strpos( $html, 'saved as a draft' ) );
check( 'event notice links to the draft', true, false !== strpos( $html, 'post.php?post=77&amp;action=edit' ) );
check( 'event notice stays out of the member widget', '', npmp_quick_add_notice( 'member' ) );

echo "Quick Add Member\n";
$to = t_post(
	array(
		'npmp_quick_add_member_nonce' => 'nonce-npmp_quick_add_member',
		'npmp_member_email'           => 'pat@example.org',
	)
);
check( 'missing manager class: generic error flag', true, false !== strpos( (string) $to, 'npmp_quick=member_error' ) );
$_GET = array( 'npmp_quick' => 'member_error' );
$html = npmp_quick_add_notice( 'member' );
check( 'plain error, no class name', true, false !== strpos( $html, 'Couldn&#039;t save this contact. Please try again.' ) && false === strpos( $html, 'class not found' ) );
check( 'error notice is inline', true, false !== strpos( $html, 'notice-error inline' ) );
$to = t_post(
	array(
		'npmp_quick_add_member_nonce' => 'nonce-npmp_quick_add_member',
		'npmp_member_email'           => 'nope',
	)
);
check( 'no valid email: flag', true, false !== strpos( (string) $to, 'npmp_quick=member_no_email' ) );
$_GET = array( 'npmp_quick' => 'member_added' );
check( 'added notice uses the member word', true, false !== strpos( npmp_quick_add_notice( 'member' ), 'Member added.' ) );
$_GET = array( 'npmp_quick' => 'member_<script>' );
check( 'unknown flag prints nothing', '', npmp_quick_add_notice( 'member' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
