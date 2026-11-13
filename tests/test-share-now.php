<?php
/**
 * Share now in the Social Sharing meta box (includes/social-sharing/share-hooks.php).
 *
 * The button used to be a submit button read in save_post. The block editor
 * saves meta boxes without the submit button, so in the block editor it did
 * nothing. It's an admin-ajax action now, with a nonce per post and the same
 * capability rules: published posts only, shared by someone who can edit and
 * publish them.
 *
 * Run: php tests/test-share-now.php
 */

require_once __DIR__ . '/bootstrap.php';

class T_Json extends Exception {
	public $success;
	public $data;
}
class WP_Error {
	private $msg;
	public function __construct( $code = '', $msg = '' ) {
		$this->msg = $msg;
	}
	public function get_error_message() {
		return $this->msg;
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

$GLOBALS['t_posts'] = array();
$GLOBALS['t_meta']  = array();
$GLOBALS['t_caps']  = array();
$GLOBALS['t_shared'] = 0;
$GLOBALS['t_actions'] = array();

class NPMP_Social_Share_Manager {
	public static function get_instance() {
		return new self();
	}
	public function share_post( $post_id ) {
		$GLOBALS['t_shared']++;
		return array( 'facebook' => true );
	}
	public function get_registered_networks() {
		return array( 'facebook' => array( 'label' => 'Facebook' ) );
	}
	public function get_connected_accounts() {
		return array( 'facebook' => array() );
	}
}

function add_action( $hook, $cb ) {
	$GLOBALS['t_actions'][ $hook ] = $cb;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function absint( $v ) {
	return abs( (int) $v );
}
function check_ajax_referer( $action, $field, $die ) {
	return isset( $_POST[ $field ] ) && 'nonce-' . $action === $_POST[ $field ];
}
function get_post_type( $id ) {
	return $GLOBALS['t_posts'][ $id ]->post_type ?? false;
}
function get_post_status( $id ) {
	return $GLOBALS['t_posts'][ $id ]->post_status ?? false;
}
function get_post( $id ) {
	return $GLOBALS['t_posts'][ $id ] ?? null;
}
function get_post_type_object( $type ) {
	$o                     = new stdClass();
	$o->cap                = new stdClass();
	$o->cap->publish_posts = 'npmp_event' === $type ? 'publish_npmp_events' : 'publish_posts';
	$o->labels             = new stdClass();
	$o->labels->singular_name = 'npmp_event' === $type ? 'Event' : 'Post';
	return $o;
}
function current_user_can( $cap, $id = null ) {
	return in_array( $cap, $GLOBALS['t_caps'], true );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['t_meta'][ $id ][ $key ] = $value;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function current_time( $type ) {
	return '2026-11-12 10:00:00';
}
function get_option( $name, $default = false ) {
	return array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' )[ $name ] ?? $default;
}
function mysql2date( $format, $date ) {
	return gmdate( $format, strtotime( $date ) );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function wp_send_json_success( $data ) {
	$e          = new T_Json();
	$e->success = true;
	$e->data    = $data;
	throw $e;
}
function wp_send_json_error( $data, $code = null ) {
	$e          = new T_Json();
	$e->success = false;
	$e->data    = $data;
	throw $e;
}

require_once dirname( __DIR__ ) . '/includes/social-sharing/share-hooks.php';

function t_post( $id, $type, $status ) {
	$p                          = new stdClass();
	$p->ID                      = $id;
	$p->post_type               = $type;
	$p->post_status             = $status;
	$GLOBALS['t_posts'][ $id ] = $p;
}

function t_share( $post_id, $nonce ) {
	$_POST = array( 'post_id' => $post_id, 'nonce' => $nonce );
	try {
		npmp_social_ajax_share_now();
	} catch ( T_Json $e ) {
		return $e;
	}
	return null;
}

t_post( 10, 'post', 'publish' );
t_post( 11, 'post', 'draft' );
t_post( 12, 'npmp_event', 'publish' );
t_post( 13, 'page', 'publish' );

echo "Wiring\n";
check( 'registered as an admin-ajax action', 'npmp_social_ajax_share_now', $GLOBALS['t_actions']['wp_ajax_npmp_social_share_now'] ?? null );
check( 'no longer a save_post handler', false, isset( $GLOBALS['t_actions']['save_post'] ) );

echo "Refusals\n";
$GLOBALS['t_caps'] = array( 'edit_post', 'publish_posts' );
$r = t_share( 10, 'nonce-npmp_social_share_now_99' );
check( 'nonce for another post refused', false, $r->success );
$r = t_share( 10, 'garbage' );
check( 'bad nonce refused', false, $r->success );
$r = t_share( 11, 'nonce-npmp_social_share_now_11' );
check( 'draft refused', false, $r->success );
$r = t_share( 13, 'nonce-npmp_social_share_now_13' );
check( 'unsupported post type refused', false, $r->success );
$GLOBALS['t_caps'] = array( 'edit_post' );
$r = t_share( 10, 'nonce-npmp_social_share_now_10' );
check( 'can edit but not publish: refused', false, $r->success );
$GLOBALS['t_caps'] = array( 'publish_posts' );
$r = t_share( 10, 'nonce-npmp_social_share_now_10' );
check( 'can publish but not edit this one: refused', false, $r->success );
check( 'nothing shared so far', 0, $GLOBALS['t_shared'] );
check( 'no share date written', '', get_post_meta( 10, '_npmp_shared_on', true ) );

echo "Allowed\n";
$GLOBALS['t_caps'] = array( 'edit_post', 'publish_posts' );
$r = t_share( 10, 'nonce-npmp_social_share_now_10' );
check( 'published post by an editor: success', true, $r->success );
check( 'shared once', 1, $GLOBALS['t_shared'] );
check( 'share date written', '2026-11-12 10:00:00', get_post_meta( 10, '_npmp_shared_on', true ) );
check( 'results returned as HTML for the box', true, false !== strpos( $r->data['html'], 'Facebook' ) );
check( 'the check mark carries words for screen readers', true, false !== strpos( $r->data['html'], '<span class="screen-reader-text">Shared</span>' ) );
$GLOBALS['t_caps'] = array( 'edit_post', 'publish_npmp_events' );
$r = t_share( 12, 'nonce-npmp_social_share_now_12' );
check( 'event checks the event publish cap', true, $r->success );

echo "Status text\n";
$GLOBALS['t_meta'][12] = array();
check( 'unshared event names the type', "<p>This event hasn&#039;t been shared yet.</p>", npmp_social_share_status_html( get_post( 12 ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
