<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/setup-wizard-redirect.php
//
// End-to-end check, on a real WordPress, of what a first-time admin sees after
// activating the free plugin: the one-time redirect into the setup wizard
// (includes/npmp-setup-wizard.php) and the Pro note the wizard renders.
//
// Activates the plugin on a site scrubbed back to "never ran Nonprofit
// Manager", reads the queued flag out of the options table, then fires
// admin_init as the administrator and catches the redirect. Repeats for the
// cases that must NOT redirect. Last, renders the wizard and reads the Pro
// note out of the HTML.
//
// Runs on the SQLite rig in CLAUDE.md, "Testing beyond unit tests": no MySQL
// and no web server, because every step here is a PHP call. Activating
// through wp-cli proves nothing, since WP_CLI switches the queue off on
// purpose, so this loads wp-load.php in a plain PHP process instead.
//
// Run it with Pro inactive for the note's checks. With Pro active it checks
// the wizard leaves the note out.
//
// Not run by the pre-push gate (it needs a WordPress).
//
// Hold all output until the end. In the CLI headers_sent() turns true at the
// first byte printed, and the redirect rightly waits when it is.
ob_start();

define( 'WP_ADMIN', true );
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = 'npm-rig.test';
$_SERVER['REQUEST_URI']     = '/wp-admin/index.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function rig_has( $label, $needle, $haystack ) {
	rig_check( $label, true, false !== strpos( $haystack, $needle ) );
}

class Rig_Redirect extends Exception {}
add_filter(
	'wp_redirect',
	function ( $location ) {
		throw new Rig_Redirect( $location );
	},
	1
);

/**
 * Read an option straight from the table, past anything cached in-process.
 */
function rig_option( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

/**
 * Put the site back to "Nonprofit Manager has never run here".
 */
function rig_fresh_site() {
	global $wpdb;
	deactivate_plugins( 'nonprofit-manager/nonprofit-manager.php', true );
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'npmp\\_%'" );
	foreach ( $names as $n ) {
		delete_option( $n );
	}
	foreach ( get_posts( array( 'post_type' => array( 'npmp_contact', 'npmp_donation' ), 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any' ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	wp_cache_flush();
}

/**
 * Fire the admin_init redirect handler the way an admin page load does.
 *
 * @return string The redirect URL, or 'stayed'.
 */
function rig_admin_load() {
	try {
		npmp_maybe_redirect_to_setup_wizard();
	} catch ( Rig_Redirect $r ) {
		return $r->getMessage();
	}
	return 'stayed';
}

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
set_current_screen( 'dashboard' );
$wizard_url = admin_url( 'admin.php?page=npmp_setup_wizard' );

echo "Activation on a fresh site\n";
rig_fresh_site();
rig_check( 'flag absent before activation', null, rig_option( 'npmp_setup_wizard_redirect' ) );
$err = activate_plugin( 'nonprofit-manager/nonprofit-manager.php' );
rig_check( 'plugin activates without error', false, is_wp_error( $err ) );
if ( is_wp_error( $err ) ) {
	echo '  ' . $err->get_error_message() . "\n";
}
rig_check( 'plugin is active', true, is_plugin_active( 'nonprofit-manager/nonprofit-manager.php' ) );
rig_check( 'activation queued the one-time redirect', '1', rig_option( 'npmp_setup_wizard_redirect' ) );
rig_check( 'register_activation_hook is the wiring', true, (bool) has_action( 'activate_nonprofit-manager/nonprofit-manager.php', 'npmp_run_plugin_activation_tasks' ) );
rig_check( 'the handler is on admin_init', 1, has_action( 'admin_init', 'npmp_maybe_redirect_to_setup_wizard' ) );

echo "\nThe first admin page load\n";
wp_cache_flush();
rig_check( 'first admin load goes to the setup wizard', $wizard_url, rig_admin_load() );
rig_check( 'the flag is used up', null, rig_option( 'npmp_setup_wizard_redirect' ) );
rig_check( 'the second admin load stays put', 'stayed', rig_admin_load() );

echo "\nThe cases that must not redirect\n";
update_option( 'npmp_setup_wizard_redirect', 1, true );
$_GET['activate-multi'] = 'true';
rig_check( 'bulk activation landing does not redirect', 'stayed', rig_admin_load() );
rig_check( 'bulk activation landing clears the flag', null, rig_option( 'npmp_setup_wizard_redirect' ) );
unset( $_GET['activate-multi'] );

update_option( 'npmp_setup_wizard_redirect', 1, true );
$_SERVER['REQUEST_METHOD'] = 'POST';
rig_check( 'a form post does not redirect', 'stayed', rig_admin_load() );
rig_check( 'a form post leaves the flag for the next page', '1', rig_option( 'npmp_setup_wizard_redirect' ) );
$_SERVER['REQUEST_METHOD'] = 'GET';

$_GET['page'] = 'npmp_setup_wizard';
rig_check( 'already on the wizard does not redirect', 'stayed', rig_admin_load() );
unset( $_GET['page'] );
update_option( 'npmp_setup_wizard_redirect', 1, true );

update_option( 'npmp_setup_completed', true );
rig_check( 'a finished wizard does not redirect', 'stayed', rig_admin_load() );
rig_check( 'a finished wizard clears the flag', null, rig_option( 'npmp_setup_wizard_redirect' ) );
delete_option( 'npmp_setup_completed' );

echo "\nReactivating a site that already ran the plugin\n";
deactivate_plugins( 'nonprofit-manager/nonprofit-manager.php', true );
delete_option( 'npmp_setup_wizard_redirect' );
activate_plugin( 'nonprofit-manager/nonprofit-manager.php' );
rig_check( 'a second activation queues nothing', null, rig_option( 'npmp_setup_wizard_redirect' ) );

echo "\nThe Pro note the wizard renders\n";
set_current_screen( 'admin_page_npmp_setup_wizard' );
ob_start();
npmp_render_setup_wizard();
$html = ob_get_clean();
$link = npmp_get_upgrade_url( 'setup_wizard' );
// The note is for free sites only. With Pro active the wizard leaves it out.
if ( is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) ) {
	rig_check( 'Pro active: no Pro note', false, false !== strpos( $html, 'Want more features?' ) );
	rig_check( 'Pro active: no upgrade link', false, false !== strpos( $html, esc_url( $link ) ) );
	printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
	ob_end_flush();
	exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
}
rig_has( 'the note is on the page', 'Want more features?', $html );
rig_has( 'it names what Pro adds', 'groups, recurring gifts, dues billing', $html );
rig_has( 'the campaign-tagged link is in the markup', esc_url( $link ), $html );
rig_check( 'the tagged link names this screen', true, false !== strpos( $link, 'utm_campaign=setup_wizard' ) );
preg_match( '#<a[^>]*href="' . preg_quote( esc_url( $link ), '#' ) . '"[^>]*>(.*?)</a>#s', $html, $m );
$anchor = isset( $m[0] ) ? $m[0] : '';
rig_check( 'the link is an anchor, not just text', true, '' !== $anchor );
rig_check( 'the link looks like a button', true, (bool) preg_match( '/class="[^"]*\bbutton\b/', $anchor ) );
rig_check( 'the link opens in a new tab safely', true, false !== strpos( $anchor, 'rel="noopener"' ) );
echo '  anchor: ' . trim( preg_replace( '/\s+/', ' ', $anchor ) ) . "\n";

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
ob_end_flush();
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
