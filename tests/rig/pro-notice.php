<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/pro-notice.php
//
// Real-WordPress check of the post-setup Pro notice
// (includes/npmp-pro-notice.php): that an administrator on a free install
// that finished the setup wizard sees it on a Nonprofit Manager screen, what
// its link carries, that WordPress' own screens stay clear of it, and that
// the Dismiss link takes it away for good.
//
// Unit checks (tests/test-pro-notice.php) answer the gate with stand-ins for
// WordPress. This renders the real notice through admin_notices on a real
// screen, which is the only way to see what esc_url() does to the link and
// whether the dismissal nonce holds.
//
// Runs on the SQLite rig in CLAUDE.md, "Testing beyond unit tests": no MySQL
// and no web server. Run it with Pro inactive.
//
// Not run by the pre-push gate (it needs a WordPress).
//
// Hold all output until the end, the way the wizard rig does: in the CLI
// headers_sent() turns true at the first byte printed, and a redirect inside
// admin_init rightly waits when it is.
ob_start();

define( 'WP_ADMIN', true );
$wp_root                    = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = 'npm-rig.test';
$_SERVER['REQUEST_URI']     = '/wp-admin/admin.php?page=npmp_main';
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
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
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
 * The Pro notice as admin_notices renders it, or '' when it stays away.
 *
 * @return string
 */
function rig_notice_html() {
	ob_start();
	do_action( 'admin_notices' );
	$all = (string) ob_get_clean();
	if ( ! preg_match( '#<div class="notice[^"]*npmp-pro-notice.*?</div>#s', $all, $m ) ) {
		return '';
	}
	return $m[0];
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "  FAIL  no administrator on this site\n";
	echo ob_get_clean();
	exit( 1 );
}
wp_set_current_user( $admin[0]->ID );
rig_check( 'running as an administrator', true, current_user_can( 'manage_options' ) );
rig_check( 'free plugin, no Pro', false, npmp_is_pro() );

// A site that finished the wizard, with nothing dismissed.
update_option( 'npmp_setup_completed', true );
delete_option( NPMP_SETUP_REDIRECT_FLAG );
delete_user_meta( get_current_user_id(), 'npmp_pro_notice_dismissed' );
// Keep the two older asks off the screen, the way a fresh install has them.
delete_option( 'npmp_first_milestone_at' );
delete_option( 'npmp_first_donation_at' );

echo "On a Nonprofit Manager screen\n";
set_current_screen( 'nonprofit-manager_page_npmp_general_settings' );
$html = rig_notice_html();
rig_check( 'the notice renders', true, '' !== $html );
rig_check( 'once, not twice', 1, substr_count( $html, 'npmp-pro-notice' ) );
rig_has( 'the lead asks about monthly giving and dues', 'Taking monthly donations or charging dues?', $html );
rig_has( 'the body names Pro', 'Nonprofit Manager Pro', $html );
rig_has( 'the body carries the price', '$47 a year', $html );
rig_has( 'the button says what it opens', 'See Pro plans', $html );
rig_has( 'and it is a button', 'class="button button-primary"', $html );
rig_has( 'a Dismiss link sits beside it', 'Dismiss', $html );

echo "The pricing link, after esc_url()\n";
preg_match_all( '#href="([^"]+)"#', $html, $hrefs );
$links = array_map( 'html_entity_decode', $hrefs[1] );
$pro   = '';
foreach ( $links as $link ) {
	if ( false !== strpos( $link, 'nonprofitmanager.app' ) ) {
		$pro = $link;
	}
}
rig_check( 'the notice links to the pricing page', 'https://nonprofitmanager.app/pricing', strtok( $pro, '?' ) );
parse_str( (string) parse_url( $pro, PHP_URL_QUERY ), $q );
rig_check( 'utm_source', 'plugin', $q['utm_source'] ?? '' );
rig_check( 'utm_medium', 'admin-notice', $q['utm_medium'] ?? '' );
rig_check( 'utm_campaign', 'post_setup', $q['utm_campaign'] ?? '' );
rig_check( 'three tags and no more', 3, count( $q ) );
rig_has( 'it opens in a new tab', 'target="_blank"', $html );
rig_has( 'with no referrer leak through window.opener', 'rel="noopener"', $html );

$dismiss = '';
foreach ( $links as $link ) {
	if ( false !== strpos( $link, 'npmp_pro_notice' ) ) {
		$dismiss = $link;
	}
}
parse_str( (string) parse_url( $dismiss, PHP_URL_QUERY ), $dq );
rig_check( 'the Dismiss link carries a nonce', true, ! empty( $dq['_wpnonce'] ) );
rig_check( 'and asks for a dismissal', 'dismiss', $dq['npmp_pro_notice'] ?? '' );

echo "Where it stays away\n";
set_current_screen( 'dashboard' );
rig_check( 'not on the WordPress dashboard', '', rig_notice_html() );
set_current_screen( 'edit-post' );
rig_check( 'not on the posts list', '', rig_notice_html() );
set_current_screen( 'nonprofit-manager_page_npmp_general_settings' );
delete_option( 'npmp_setup_completed' );
rig_check( 'not before the wizard is finished', '', rig_notice_html() );
update_option( 'npmp_setup_completed', true );
rig_check( 'and back once it is', true, '' !== rig_notice_html() );

echo "Dismissal\n";
$_GET['npmp_pro_notice'] = 'dismiss';
$_GET['_wpnonce']        = $dq['_wpnonce'];
$_REQUEST                = $_GET;
$landed                  = '';
try {
	do_action( 'admin_init' );
} catch ( Rig_Redirect $e ) {
	$landed = $e->getMessage();
}
unset( $_GET['npmp_pro_notice'], $_GET['_wpnonce'] );
$_REQUEST = array();
rig_check( 'the handler sends the admin back', true, '' !== $landed );
rig_check( 'and remembers the dismissal for this user', '1', (string) get_user_meta( get_current_user_id(), 'npmp_pro_notice_dismissed', true ) );
rig_check( 'the notice is gone', '', rig_notice_html() );
delete_user_meta( get_current_user_id(), 'npmp_pro_notice_dismissed' );
rig_check( 'the user meta was the only thing hiding it', true, '' !== rig_notice_html() );

$out = ob_get_clean();
printf( "%s\n%d passed, %d failed\n", $out, $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
