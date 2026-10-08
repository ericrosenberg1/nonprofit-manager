<?php
/**
 * No front-end PayPal SDK from the retired v1 PayPal options.
 *
 * includes/npmp-scripts.php used to enqueue https://www.paypal.com/sdk/js on
 * the donation page whenever the options `npmp_enable_paypal` and
 * `npmp_paypal_method` said so. Nothing has written either option since the
 * v1 PayPal module (payments/npmp-paypal.php) was removed on 2026-06-29, but
 * sites that ran v1 still carry them. CACC does: `npmp_enable_paypal` is 1, the
 * method defaults to 'sdk', and no client id is saved, so its donate page
 * loaded `sdk/js?client-id=&currency=USD`, which PayPal answers with a 400.
 * Found 2026-10-08.
 *
 * The PayPal forms that work today enqueue their own SDK with a real client id
 * (npmp_render_paypal_api_form() and the multi-gateway form), so this file
 * should never load one. Pinned here with CACC's exact option set. The
 * Payment Settings screen's inline toggle for the same v1 block (a
 * #npmp-paypal-settings element no screen renders) is checked gone as well.
 *
 * Run: php tests/test-legacy-paypal-sdk.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npmp_test_actions']  = array();
$GLOBALS['npmp_test_scripts']  = array();
$GLOBALS['npmp_test_inline']   = array();
$GLOBALS['npmp_test_options']  = array(
	'npmp_enable_paypal'            => 1,
	'npmp_paypal_mode'              => 'live',
	'npmp_paypal_live_client_id'    => '',
	'npmp_paypal_sandbox_client_id' => '',
	'npmp_donation_page_id'         => 18,
);

class WP_Post {
	public $post_content = '[npmp_donation_form]';
}

function add_action( $hook, $callback ) {
	$GLOBALS['npmp_test_actions'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $name ] : $default;
}
function absint( $value ) {
	return abs( (int) $value );
}
function is_page( $ids = null ) {
	return true;
}
function is_singular() {
	return true;
}
function get_post() {
	return new WP_Post();
}
function has_shortcode( $content, $tag ) {
	return false !== strpos( $content, '[' . $tag );
}
function get_post_type() {
	return 'page';
}
function plugin_dir_path( $file ) {
	return rtrim( $file, '/' ) . '/';
}
function plugins_url( $path, $plugin ) {
	return 'https://example.org/wp-content/plugins/nonprofit-manager/' . $path;
}
function wp_register_style( ...$args ) {}
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( $handle, $src = '', ...$rest ) {
	$GLOBALS['npmp_test_scripts'][ $handle ] = $src;
}
function wp_add_inline_script( $handle, $js ) {
	$GLOBALS['npmp_test_inline'][] = $js;
}
function npmp_currency() {
	return 'USD';
}

require_once dirname( __DIR__ ) . '/includes/npmp-scripts.php';

echo "Front end, CACC's donate page with the v1 options still set\n";
foreach ( $GLOBALS['npmp_test_actions']['wp_enqueue_scripts'] ?? array() as $callback ) {
	call_user_func( $callback );
}
$paypal = array_filter(
	$GLOBALS['npmp_test_scripts'],
	function ( $src ) {
		return false !== strpos( (string) $src, 'paypal.com/sdk' );
	}
);
check( 'no PayPal SDK is enqueued by npmp-scripts.php', array(), $paypal );
check( 'no SDK URL with an empty client id', false, (bool) preg_grep( '/client-id=(&|$)/', $GLOBALS['npmp_test_scripts'] ) );

echo "\nAdmin, Payment Settings screen\n";
foreach ( $GLOBALS['npmp_test_actions']['admin_enqueue_scripts'] ?? array() as $callback ) {
	call_user_func( $callback, 'nonprofit-manager_page_npmp_payment_settings' );
}
$stale = array_filter(
	$GLOBALS['npmp_test_inline'],
	function ( $js ) {
		return false !== strpos( $js, 'npmp-paypal-settings' ) || false !== strpos( $js, 'npmp_paypal_method' );
	}
);
check( 'no inline script for the removed v1 PayPal settings block', array(), $stale );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
