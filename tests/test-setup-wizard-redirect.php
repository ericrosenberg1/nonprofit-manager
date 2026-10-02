<?php
/**
 * The one-time redirect into the setup wizard after activation
 * (includes/npmp-setup-wizard.php), its Skip button, and how the onboarding
 * tour (includes/onboarding/class-tour.php) stays out of the wizard's way.
 *
 * The redirect never fired before: the handler deleted the transient, then
 * asked a helper that read the same transient. These checks pin the decision
 * for every kind of request, the activation-time "fresh install?" check, and
 * the hand-off to the tour. The real redirect chain, including free and Pro
 * activated together, is checked on the Pro repo's WordPress rig.
 *
 * Run: php tests/test-setup-wizard-redirect.php
 */

// Hold all output until the end. In the CLI, headers_sent() turns true at
// the first byte printed, and the redirect rightly waits when it is.
ob_start();

require_once __DIR__ . '/bootstrap.php';

// In-memory stand-ins for the WordPress functions these files call.
$GLOBALS['t_options']  = array();
$GLOBALS['t_can']      = true;
$GLOBALS['t_screen']   = 'toplevel_page_npmp_main';
$GLOBALS['t_usermeta'] = array();
$GLOBALS['t_hooks']    = array();
$GLOBALS['t_ajax']     = false;
$GLOBALS['t_cron']     = false;
$GLOBALS['t_network']  = false;

class Test_Redirect extends Exception {}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][ $hook ][ $priority ][] = $callback;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['t_options'][ $name ] );
	return true;
}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function wp_doing_ajax() {
	return $GLOBALS['t_ajax'];
}
function wp_doing_cron() {
	return $GLOBALS['t_cron'];
}
function is_network_admin() {
	return $GLOBALS['t_network'];
}
function is_user_admin() {
	return false;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function wp_unslash( $v ) {
	return $v;
}
function admin_url( $path = '' ) {
	return 'http://example.org/wp-admin/' . ltrim( $path, '/' );
}
function wp_safe_redirect( $url ) {
	throw new Test_Redirect( $url );
}
function wp_verify_nonce( $nonce, $action ) {
	return 'good-nonce' === $nonce ? 1 : false;
}
function get_current_screen() {
	return $GLOBALS['t_screen'] ? (object) array( 'id' => $GLOBALS['t_screen'] ) : null;
}
function get_current_user_id() {
	return 1;
}
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['t_usermeta'][ $key ] ) ? $GLOBALS['t_usermeta'][ $key ] : '';
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * A $wpdb that answers the three prior-install queries from fixtures.
 */
class Test_WPDB {
	public $posts      = 'wp_posts';
	public $prefix     = 'wp_';
	public $has_post   = false;
	public $has_table  = false;
	public $table_rows = false;
	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
	}
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'wp_posts' ) ) {
			return $this->has_post ? '12' : null;
		}
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $this->has_table ? 'wp_npmp_members' : null;
		}
		return $this->table_rows ? '1' : null;
	}
}
$GLOBALS['wpdb'] = new Test_WPDB();

require_once dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php';
require_once dirname( __DIR__ ) . '/includes/onboarding/class-tour.php';

/**
 * Reset to a fresh site, an administrator, a normal GET of the dashboard.
 */
function t_reset() {
	$GLOBALS['t_options']  = array();
	$GLOBALS['t_can']      = true;
	$GLOBALS['t_screen']   = 'toplevel_page_npmp_main';
	$GLOBALS['t_usermeta'] = array();
	$GLOBALS['t_ajax']     = false;
	$GLOBALS['t_cron']     = false;
	$GLOBALS['t_network']  = false;
	$GLOBALS['wpdb']       = new Test_WPDB();
	$_GET                  = array();
	$_POST                 = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
}

/**
 * Run the admin_init redirect handler and report what it did.
 *
 * @return string The redirect URL, or 'stayed'.
 */
function t_admin_load() {
	try {
		npmp_maybe_redirect_to_setup_wizard();
	} catch ( Test_Redirect $r ) {
		return $r->getMessage();
	}
	return 'stayed';
}

/**
 * Run the wizard's form handler (the admin_init closure at priority 10).
 *
 * @return string The redirect URL, or 'stayed'.
 */
function t_post_wizard( $post ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = $post;
	try {
		foreach ( $GLOBALS['t_hooks']['admin_init'][10] as $cb ) {
			call_user_func( $cb );
		}
	} catch ( Test_Redirect $r ) {
		return $r->getMessage();
	}
	return 'stayed';
}

$wizard_url = 'http://example.org/wp-admin/admin.php?page=npmp_setup_wizard';
$flag       = NPMP_SETUP_REDIRECT_FLAG;

// --- The decision, case by case -------------------------------------------
echo "Decision\n";
$admin_get = array( 'can_manage' => true, 'method' => 'GET' );
check( 'no flag: nothing to do', 'none', npmp_setup_redirect_decision( false, false, $admin_get ) );
check( 'admin GET of a normal admin page: redirect', 'redirect', npmp_setup_redirect_decision( true, false, $admin_get ) );
check( 'bulk activation (activate-multi): clear, no redirect', 'clear', npmp_setup_redirect_decision( true, false, $admin_get + array( 'is_bulk' => true ) ) );
check( 'network admin: clear, no redirect', 'clear', npmp_setup_redirect_decision( true, false, $admin_get + array( 'is_network_admin' => true ) ) );
check( 'setup already completed: clear', 'clear', npmp_setup_redirect_decision( true, true, $admin_get ) );
check( 'already on the wizard: clear, no loop', 'clear', npmp_setup_redirect_decision( true, false, $admin_get + array( 'page' => 'npmp_setup_wizard' ) ) );
check( 'Membership Manager (no manage_options): wait', 'wait', npmp_setup_redirect_decision( true, false, array( 'can_manage' => false, 'method' => 'GET' ) ) );
foreach ( array( 'is_ajax' => 'AJAX', 'is_rest' => 'REST', 'is_cron' => 'cron', 'is_cli' => 'WP-CLI', 'is_xmlrpc' => 'XML-RPC', 'is_iframe' => 'iframe', 'headers_sent' => 'headers already sent' ) as $key => $label ) {
	check( "$label request: wait", 'wait', npmp_setup_redirect_decision( true, false, array( $key => true ) + $admin_get ) );
}
check( 'POST: wait', 'wait', npmp_setup_redirect_decision( true, false, array( 'method' => 'POST' ) + $admin_get ) );
check( 'HEAD: wait', 'wait', npmp_setup_redirect_decision( true, false, array( 'method' => 'HEAD' ) + $admin_get ) );
check( 'AJAX in a bulk landing still waits (never clears from AJAX)', 'wait', npmp_setup_redirect_decision( true, false, $admin_get + array( 'is_ajax' => true, 'is_bulk' => true ) ) );

// --- Activation: fresh install or not -------------------------------------
echo "\nActivation\n";
t_reset();
check( 'fresh site counts as never configured', false, npmp_site_was_configured_before() );
npmp_queue_setup_wizard_on_activation( false );
check( 'fresh activation queues the redirect', 1, get_option( $flag ) );

t_reset();
npmp_queue_setup_wizard_on_activation( true );
check( 'network-wide activation queues nothing', false, get_option( $flag ) );

t_reset();
$GLOBALS['t_options']['npmp_setup_completed'] = true;
npmp_queue_setup_wizard_on_activation( false );
check( 'reactivation after setup completed queues nothing', false, get_option( $flag ) );

foreach ( NPMP_PRIOR_INSTALL_OPTIONS as $opt ) {
	t_reset();
	$GLOBALS['t_options'][ $opt ] = '0';
	npmp_queue_setup_wizard_on_activation( false );
	check( "prior install marker $opt: no redirect", false, get_option( $flag ) );
}

t_reset();
$GLOBALS['wpdb']->has_post = true;
npmp_queue_setup_wizard_on_activation( false );
check( 'stored contacts or donations: no redirect', false, get_option( $flag ) );

t_reset();
$GLOBALS['wpdb']->has_table  = true;
$GLOBALS['wpdb']->table_rows = true;
npmp_queue_setup_wizard_on_activation( false );
check( 'rows in the legacy members table: no redirect', false, get_option( $flag ) );

t_reset();
$GLOBALS['wpdb']->has_table = true;
npmp_queue_setup_wizard_on_activation( false );
check( 'empty legacy members table alone: still fresh', 1, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$GLOBALS['t_options']['npmp_roles_version'] = '1';
npmp_queue_setup_wizard_on_activation( false );
check( 'reactivating a configured site clears a stale flag', false, get_option( $flag ) );

check( 'WP-CLI activation queues nothing', false, npmp_should_queue_setup_wizard( array( 'is_cli' => true ) ) );
check( 'plain single-site activation queues', true, npmp_should_queue_setup_wizard( array() ) );

// --- The handler on real requests -----------------------------------------
echo "\nHandler\n";
t_reset();
check( 'no flag: admin page loads normally', 'stayed', t_admin_load() );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
check( 'first admin load after activation goes to the wizard', $wizard_url, t_admin_load() );
check( 'flag is used up', false, get_option( $flag ) );
check( 'second admin load stays put (once only)', 'stayed', t_admin_load() );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$GLOBALS['t_can']              = false;
check( 'Membership Manager is never redirected', 'stayed', t_admin_load() );
check( 'and the flag is left for an administrator', 1, get_option( $flag ) );
$GLOBALS['t_can'] = true;
check( 'the administrator then gets the wizard', $wizard_url, t_admin_load() );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$_GET['activate-multi']        = 'true';
check( 'bulk activation landing: no redirect', 'stayed', t_admin_load() );
check( 'bulk activation landing clears the flag', false, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$GLOBALS['t_network']          = true;
check( 'network admin: no redirect', 'stayed', t_admin_load() );
check( 'network admin clears the flag', false, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$GLOBALS['t_ajax']             = true;
check( 'admin-ajax (heartbeat) does not redirect', 'stayed', t_admin_load() );
check( 'admin-ajax leaves the flag for the next page', 1, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$GLOBALS['t_cron']             = true;
check( 'cron does not redirect or use the flag', 'stayed', t_admin_load() );
check( 'cron leaves the flag', 1, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
$_SERVER['REQUEST_METHOD']     = 'POST';
check( 'a form post does not redirect', 'stayed', t_admin_load() );
check( 'a form post leaves the flag', 1, get_option( $flag ) );

t_reset();
$GLOBALS['t_options'][ $flag ]                = 1;
$GLOBALS['t_options']['npmp_setup_completed'] = true;
check( 'setup completed: no redirect', 'stayed', t_admin_load() );
check( 'setup completed clears the flag', false, get_option( $flag ) );

t_reset();
$ctx = npmp_setup_redirect_request_context();
check( 'context: GET read from the request', 'GET', $ctx['method'] );
check( 'context: not bulk without activate-multi', false, $ctx['is_bulk'] );
$_GET = array( 'activate-multi' => 'true', 'page' => 'npmp_setup_wizard' );
$ctx  = npmp_setup_redirect_request_context();
check( 'context: activate-multi read as bulk', true, $ctx['is_bulk'] );
check( 'context: page slug read', 'npmp_setup_wizard', $ctx['page'] );

// --- Skip -----------------------------------------------------------------
echo "\nSkip\n";
t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
check( 'Skip goes to the Overview', 'http://example.org/wp-admin/admin.php?page=npmp_main', t_post_wizard( array( 'npmp_setup_nonce' => 'good-nonce', 'npmp_setup_step' => 'complete', 'npmp_setup_skip' => '1' ) ) );
check( 'Skip counts as setup completed', true, get_option( 'npmp_setup_completed' ) );
check( 'Skip clears the redirect flag', false, get_option( $flag ) );
check( 'Skip leaves the feature choices untouched', false, get_option( 'npmp_enabled_features' ) );
$_SERVER['REQUEST_METHOD'] = 'GET';
check( 'after Skip, no redirect back to the wizard', 'stayed', t_admin_load() );
check( 'after Skip, a later activation of this site queues nothing', false, npmp_should_queue_setup_wizard( array( 'setup_completed' => npmp_setup_wizard_is_done() ) ) );

t_reset();
$GLOBALS['t_can'] = false;
check( 'Skip refused for a Membership Manager', 'stayed', t_post_wizard( array( 'npmp_setup_nonce' => 'good-nonce', 'npmp_setup_skip' => '1' ) ) );
check( 'refused Skip saves nothing', false, get_option( 'npmp_setup_completed' ) );

t_reset();
check( 'Skip refused with a bad nonce', 'stayed', t_post_wizard( array( 'npmp_setup_nonce' => 'forged', 'npmp_setup_skip' => '1' ) ) );
check( 'bad-nonce Skip saves nothing', false, get_option( 'npmp_setup_completed' ) );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
check( 'Complete Setup also goes to the Overview', 'http://example.org/wp-admin/admin.php?page=npmp_main&setup_complete=1', t_post_wizard( array( 'npmp_setup_nonce' => 'good-nonce', 'npmp_setup_step' => 'complete', 'npmp_feature_members' => 'on' ) ) );
check( 'Complete Setup clears the redirect flag', false, get_option( $flag ) );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php' );
check( 'wizard renders a Skip button', 1, preg_match( '/name="npmp_setup_skip"/', $src ) );

// --- Pro waits for the free wizard ----------------------------------------
echo "\nFree wizard first\n";
t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
check( 'pending free redirect: free wizard owns the screen', true, npmp_setup_wizard_in_progress( 'npmp_main' ) );
delete_option( $flag );
check( 'on the free wizard, not finished: still in progress', true, npmp_setup_wizard_in_progress( 'npmp_setup_wizard' ) );
check( 'elsewhere, nothing pending: not in progress', false, npmp_setup_wizard_in_progress( 'npmp_main' ) );
update_option( 'npmp_setup_completed', true );
check( 'finished or skipped: not in progress even on the wizard', false, npmp_setup_wizard_in_progress( 'npmp_setup_wizard' ) );

// --- Tour -----------------------------------------------------------------
echo "\nTour\n";
t_reset();
check( 'Overview is a tour screen', true, NPMP_Tour::is_npmp_admin_screen() );
check( 'fresh admin on the Overview gets the modal', true, NPMP_Tour::should_show_modal() );
$GLOBALS['t_screen'] = 'admin_page_npmp_setup_wizard';
check( 'free wizard is not a tour screen', false, NPMP_Tour::is_npmp_admin_screen() );
check( 'no modal on the free wizard', false, NPMP_Tour::should_show_modal() );
check( 'no banner on the free wizard', false, NPMP_Tour::should_show_banner() );
$GLOBALS['t_screen'] = 'admin_page_npmp_pro_setup_wizard';
check( 'no modal on the Pro wizard', false, NPMP_Tour::should_show_modal() );
check( 'no banner on the Pro wizard', false, NPMP_Tour::should_show_banner() );
$GLOBALS['t_screen'] = 'plugins';
check( 'no modal on plugins.php', false, NPMP_Tour::should_show_modal() );

t_reset();
$GLOBALS['t_options'][ $flag ] = 1;
check( 'free redirect pending: no modal yet', false, NPMP_Tour::should_show_modal() );
t_post_wizard( array( 'npmp_setup_nonce' => 'good-nonce', 'npmp_setup_skip' => '1' ) );
check( 'after Skip, the modal shows on the next NPM screen', true, NPMP_Tour::should_show_modal() );

t_reset();
$GLOBALS['t_options']['npmp_pro_setup_wizard_redirect'] = 1;
check( 'idle Pro flag (Pro redirect not loaded) does not hold the tour', true, NPMP_Tour::should_show_modal() );
if ( true ) {
	function npmp_pro_maybe_redirect_to_setup_wizard() {}
}
check( 'Pro redirect pending: no modal yet', false, NPMP_Tour::should_show_modal() );
$GLOBALS['t_options']['npmp_pro_setup_completed'] = true;
check( 'Pro setup done: modal shows', true, NPMP_Tour::should_show_modal() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
ob_end_flush();
exit( $fail > 0 ? 1 : 0 );
