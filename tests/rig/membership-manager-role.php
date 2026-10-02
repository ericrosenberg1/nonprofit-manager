<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/membership-manager-role.php
//
// End-to-end check of the Membership Manager role on a real WordPress with
// free and Pro active, every feature module on, and the site served over HTTP
// at its siteurl (php -S, see the Pro repo's tests/rig/README.md).
//
// Part 1 runs in-process: the role's capabilities, and every settings handler
// (admin_init, admin-ajax, REST) called as a Membership Manager with a valid
// nonce, which must change nothing. An administrator control run proves the
// harness would notice a handler that let them through.
// Part 2 signs in over HTTP as a Membership Manager, an Author and an
// administrator, and requests the real admin screens: 200 for the day-to-day
// screens, 403 for every settings screen and the rest of wp-admin.
//
// Creates its own users, contacts and newsletters and deletes them at the end.
// Not run by the pre-push gate (it needs MySQL and a running WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
// admin_init callbacks call the Settings API, which lives in the admin includes.
require_once ABSPATH . 'wp-admin/includes/admin.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-74s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

// wp_die, wp_send_json_* and wp_redirect end the request. Turn them into a
// throwable so one script can call many handlers.
class Rig_Stop extends Error { public $kind; public $payload; }
function rig_stop( $kind, $payload = null ) {
	$e = new Rig_Stop( $kind );
	$e->kind = $kind;
	$e->payload = $payload;
	throw $e;
}
add_filter( 'wp_die_ajax_handler', function () { return function ( $msg ) { rig_stop( 'die', $msg ); }; } );
add_filter( 'wp_die_handler', function () { return function ( $msg ) { rig_stop( 'die', $msg ); }; } );
add_filter( 'wp_redirect', function ( $location ) { rig_stop( 'redirect', $location ); }, 1 );
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Run a callable with request superglobals set. Returns 'redirect', 'die',
 * 'json' (with the decoded payload in $out) or 'returned'.
 */
function rig_run( $callable, $post = array(), $get = array(), &$out = null ) {
	$_POST    = $post;
	$_GET     = $get;
	$_REQUEST = array_merge( $get, $post );
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	ob_start();
	$kind = 'returned';
	try {
		call_user_func( $callable );
	} catch ( Rig_Stop $e ) {
		$kind = $e->kind;
	}
	$printed = ob_get_clean();
	$json    = json_decode( $printed, true );
	if ( is_array( $json ) && array_key_exists( 'success', $json ) ) {
		$kind = 'json';
		$out  = $json;
	}
	$_POST = $_GET = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $kind;
}

/** Fire admin_init with a request and report whether anything was written. */
function rig_admin_init( $post, $get = array(), $options = array() ) {
	$before = array();
	foreach ( $options as $o ) {
		wp_cache_delete( $o, 'options' );
		$before[ $o ] = get_option( $o );
	}
	$kind    = rig_run( function () { do_action( 'admin_init' ); }, $post, $get );
	$changed = array();
	foreach ( $options as $o ) {
		wp_cache_delete( $o, 'options' );
		if ( get_option( $o ) !== $before[ $o ] ) {
			$changed[] = $o;
		}
	}
	return array( 'kind' => $kind, 'changed' => $changed, 'before' => $before );
}

function rig_user( $login, $role, $password ) {
	$id = username_exists( $login );
	if ( ! $id ) {
		$id = wp_create_user( $login, $password, $login . '@example.org' );
	}
	wp_set_password( $password, $id );
	( new WP_User( $id ) )->set_role( $role );
	return (int) $id;
}

// Every module on, so every screen and handler exists.
update_option( 'npmp_enabled_features', array( 'members' => true, 'newsletters' => true, 'donations' => true, 'calendar' => true, 'social' => true ) );

global $wpdb;
$first_new_post = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
$password       = wp_generate_password( 24, false );
$mm_id          = rig_user( 'rig_membership_mgr', 'npmp_membership_manager', $password );
$author_id      = rig_user( 'rig_author', 'author', $password );
$admin_id       = rig_user( 'rig_admin', 'administrator', $password );
$members        = NPMP_Member_Manager::get_instance();

echo "== The role ==\n";
$role = get_role( 'npmp_membership_manager' );
rig_check( 'role exists', true, (bool) $role );
$caps = $role ? array_keys( array_filter( $role->capabilities ) ) : array();
sort( $caps );
rig_check( 'role holds exactly read + npmp_manage_members', array( 'npmp_manage_members', 'read' ), $caps );
rig_check( 'role label', 'Membership Manager', wp_roles()->get_names()['npmp_membership_manager'] ?? null );
rig_check( 'administrator role has the cap', true, ! empty( get_role( 'administrator' )->capabilities['npmp_manage_members'] ) );
rig_check( 'roles version stored (upgrade routine ran without re-activation)', '1', (string) get_option( 'npmp_roles_version' ) );

$mm = new WP_User( $mm_id );
foreach ( array( 'npmp_manage_members', 'read', 'edit_npmp_newsletters', 'edit_others_npmp_newsletters', 'publish_npmp_newsletters', 'delete_npmp_newsletters' ) as $cap ) {
	rig_check( "Membership Manager can $cap", true, user_can( $mm, $cap ) );
}
foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'upload_files', 'list_users', 'edit_users', 'promote_users', 'unfiltered_html', 'manage_categories', 'edit_theme_options', 'activate_plugins', 'update_plugins', 'export' ) as $cap ) {
	rig_check( "Membership Manager cannot $cap", false, user_can( $mm, $cap ) );
}
$author = new WP_User( $author_id );
rig_check( 'Author still edits newsletters (edit_posts maps over)', true, user_can( $author, 'edit_npmp_newsletters' ) );
rig_check( 'Author still cannot send (no edit_others_posts)', false, user_can( $author, npmp_newsletter_send_capability() ) );
rig_check( 'Author has no staff cap', false, user_can( $author, 'npmp_manage_members' ) );
rig_check( 'administrator has the staff cap', true, user_can( $admin_id, 'npmp_manage_members' ) );
get_role( 'administrator' )->remove_cap( 'npmp_manage_members' );
rig_check( 'administrator keeps it even if a role editor strips it', true, user_can( new WP_User( $admin_id ), 'npmp_manage_members' ) );
get_role( 'administrator' )->add_cap( 'npmp_manage_members' );
rig_check( 'limited-staff test: Membership Manager', true, npmp_is_limited_staff( $mm ) );
rig_check( 'limited-staff test: administrator', false, npmp_is_limited_staff( new WP_User( $admin_id ) ) );

echo "== Settings handlers refuse a Membership Manager holding a valid nonce ==\n";
wp_set_current_user( $mm_id );
$cases = array(
	'features toggle'          => array( array( 'npmp_features_nonce' => wp_create_nonce( 'npmp_save_features' ) ), array(), array( 'npmp_enabled_features' ) ),
	'general: add level'       => array( array( 'npmp_add_level_nonce' => wp_create_nonce( 'npmp_add_membership_level' ), 'npmp_new_level' => 'RigEvil' ), array(), array( 'npmp_membership_levels' ) ),
	'general: default level'   => array( array( 'npmp_default_level_nonce' => wp_create_nonce( 'npmp_save_default_level' ), 'npmp_default_level' => 'RigEvil' ), array(), array( 'npmp_default_membership_level' ) ),
	'general: CAPTCHA keys'    => array( array( 'npmp_captcha_settings_nonce' => wp_create_nonce( 'npmp_save_captcha_settings' ), 'npmp_captcha_provider' => 'none', 'npmp_turnstile_site_key' => 'evil', 'npmp_turnstile_secret_key' => 'evil' ), array(), array( 'npmp_captcha_provider', 'npmp_turnstile_site_key', 'npmp_turnstile_secret_key' ) ),
	'general: powered-by'      => array( array( 'npmp_powered_by_nonce' => wp_create_nonce( 'npmp_save_powered_by' ), 'npmp_powered_by_optin' => '1' ), array(), array( 'npmp_powered_by_optin' ) ),
	'org identity'             => array( array( 'npmp_org_settings_nonce' => wp_create_nonce( 'npmp_save_org_settings' ), 'npmp_org_settings' => array( 'name' => 'Evil Org' ) ), array(), array( 'npmp_org_settings' ) ),
	'setup wizard'             => array( array( 'npmp_setup_nonce' => wp_create_nonce( 'npmp_setup_wizard' ), 'npmp_setup_step' => 'complete' ), array(), array( 'npmp_enabled_features', 'npmp_setup_completed' ) ),
	'credit ask'               => array( array( 'npmp_credit_ask_nonce' => wp_create_nonce( 'npmp_credit_ask' ), 'npmp_credit_ask_choice' => 'save', 'npmp_powered_by_optin' => '1' ), array(), array( 'npmp_powered_by_optin', 'npmp_credit_ask_done' ) ),
	'social sharing settings'  => array( array( 'npmp_social_settings_nonce' => wp_create_nonce( 'npmp_save_social_settings' ), 'npmp_share_template' => 'evil' ), array(), array() ),
	'Pro license activate'     => array( array( 'npmp_license_activate' => '1', 'npmp_license_key' => 'EVIL', '_wpnonce' => wp_create_nonce( 'npmp_license_action' ) ), array(), array( 'npmp_pro_license_key' ) ),
	'Pro custom field define'  => array( array( 'npmp_cf_action' => 'save', 'npmp_cf_nonce' => wp_create_nonce( 'npmp_cf_save' ), 'field_key' => 'rig_evil', 'field_label' => 'Evil' ), array(), array( 'npmp_custom_field_definitions' ) ),
	'Pro automation save'      => array( array( 'npmp_automation_nonce' => wp_create_nonce( 'npmp_save_automation' ), 'name' => 'Evil' ), array( 'page' => 'npmp_automations' ), array() ),
	'Pro segment save'         => array( array( 'npmp_save_segment' => '1', '_wpnonce' => wp_create_nonce( 'npmp_segment_action' ), 'segment_name' => 'Evil' ), array(), array( 'npmp_segments' ) ),
	'Pro dues pricing'         => array( array( 'npmp_dues_save_settings' => '1', 'npmp_dues_nonce' => wp_create_nonce( 'npmp_dues_save_settings' ), 'npmp_dues_fallback_level' => 'RigEvil', 'npmp_dues_failure_threshold' => '99' ), array( 'page' => 'npmp_membership_dues' ), array( 'npmp_dues_fallback_level', 'npmp_dues_failure_threshold', 'npmp_dues_level_pricing' ) ),
	'setup tour restart'       => array( array(), array( 'npmp_tour_restart' => '1' ), array() ),
);
foreach ( $cases as $label => $c ) {
	$r = rig_admin_init( $c[0], $c[1], $c[2] );
	rig_check( "$label: no redirect, nothing written", array( 'returned', array() ), array( $r['kind'], $r['changed'] ) );
}

// A recurring subscription the row actions could cancel.
$contact = $members->add_member( array( 'email' => 'rig-mm-sub@example.org', 'name' => 'Rig Subscriber', 'status' => 'subscribed', 'membership_level' => 'RigLevel' ) );
$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => (int) $contact, 'amount' => 10, 'frequency' => 'monthly', 'status' => 'active', 'payment_method' => 'stripe', 'source' => 'donation' ) );
$sub_id = (int) $wpdb->insert_id;
$r = rig_admin_init( array(), array( 'page' => 'npmp_recurring_donations', 'npmp_action' => 'cancel', 'subscription_id' => $sub_id, '_wpnonce' => wp_create_nonce( 'npmp_recurring_action_' . $sub_id ) ) );
rig_check( 'Pro recurring cancel: no redirect', 'returned', $r['kind'] );
rig_check( 'Pro recurring cancel: subscription still active', 'active', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $sub_id ) ) );
$r = rig_admin_init( array(), array( 'page' => 'npmp_membership_dues', 'npmp_action' => 'pause', 'subscription_id' => $sub_id, '_wpnonce' => wp_create_nonce( 'npmp_dues_action_' . $sub_id ) ) );
rig_check( 'Pro dues pause: no redirect', 'returned', $r['kind'] );

// Tier management on the Membership dashboard.
$levels_before = get_option( 'npmp_membership_levels' );
rig_run( 'npmp_render_membership_dashboard', array( 'add_level' => '1', 'new_level' => 'RigEvilTier', '_wpnonce' => wp_create_nonce( 'npmp_levels' ) ) );
rig_check( 'membership dashboard: tier not added', $levels_before, get_option( 'npmp_membership_levels' ) );

echo "== AJAX and REST refuse a Membership Manager ==\n";
add_filter( 'wp_doing_ajax', '__return_true' );
$ajax = array(
	'import: Mailchimp lists'         => array( 'npmp_import_ajax_mc_lists', array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ), 'api_key' => 'x-us1' ) ),
	'import: Constant Contact lists'  => array( 'npmp_import_ajax_cc_lists', array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ), 'access_token' => 'x' ) ),
	'import: Mailchimp preview'       => array( 'npmp_import_ajax_preview', array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ), 'source' => 'mailchimp', 'mc_api_key' => 'x-us1', 'mc_list_id' => 'y' ) ),
	'import: Constant Contact execute' => array( 'npmp_import_ajax_execute', array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ), 'source' => 'constant_contact', 'file_token' => 'x' ) ),
	'import: Mailchimp chunk'         => array( 'npmp_import_ajax_step', array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ), 'source' => 'mailchimp', 'job_token' => 'rigjob' . wp_rand() ) ),
	'Pro: test email provider'        => array( 'npmp_ajax_test_email_provider', array( '_nonce' => wp_create_nonce( 'npmp_wizard_test' ), 'provider' => 'smtp' ) ),
);
foreach ( $ajax as $label => $a ) {
	$out  = null;
	$kind = rig_run( $a[0], $a[1], array(), $out );
	rig_check( "$label refused", array( 'json', false ), array( $kind, $out['success'] ?? null ) );
}
remove_filter( 'wp_doing_ajax', '__return_true' );
foreach ( array(
	array( 'GET', '/wp/v2/settings' ),
	array( 'POST', '/wp/v2/posts' ),
	array( 'POST', '/wp/v2/pages' ),
	array( 'POST', '/wp/v2/media' ),
	array( 'POST', '/wp/v2/users' ),
	array( 'GET', '/wp/v2/plugins' ),
	array( 'GET', '/npmp/v1/tour' ),
) as $route ) {
	$req = new WP_REST_Request( $route[0], $route[1] );
	if ( 'POST' === $route[0] ) {
		$req->set_body_params( array( 'title' => 'rig', 'username' => 'rigx', 'email' => 'rigx@example.org', 'password' => 'x' ) );
	}
	rig_check( "REST {$route[0]} {$route[1]} refused", 403, rest_do_request( $req )->get_status() );
}

echo "== Day-to-day AJAX and REST work for a Membership Manager ==\n";
$req = new WP_REST_Request( 'POST', '/wp/v2/npmp_newsletter' );
$req->set_body_params( array( 'title' => 'Rig newsletter', 'content' => '<!-- wp:paragraph --><p>Hello members</p><!-- /wp:paragraph -->', 'status' => 'draft' ) );
$res = rest_do_request( $req );
rig_check( 'REST create newsletter draft', 201, $res->get_status() );
$nl_id = (int) ( $res->get_data()['id'] ?? 0 );
$req = new WP_REST_Request( 'POST', '/wp/v2/npmp_newsletter_topic' );
$req->set_body_params( array( 'name' => 'Rig Topic ' . wp_rand() ) );
$res = rest_do_request( $req );
rig_check( 'REST create newsletter topic (assign_terms)', 201, $res->get_status() );
$topic_id = (int) ( $res->get_data()['id'] ?? 0 );
add_filter( 'wp_doing_ajax', '__return_true' );
$out  = null;
$kind = rig_run( function () { do_action( 'wp_ajax_npmp_send_test_newsletter' ); }, array( 'post_id' => $nl_id, 'nonce' => wp_create_nonce( 'npmp_send_test_' . $nl_id ) ), array(), $out );
rig_check( 'send test newsletter', array( 'json', true ), array( $kind, $out['success'] ?? null ) );
$out  = null;
$kind = rig_run( function () { do_action( 'wp_ajax_npmp_send_newsletter_now' ); }, array( 'post_id' => $nl_id, 'nonce' => wp_create_nonce( 'npmp_send_newsletter_' . $nl_id ), 'levels' => array( 'RigLevel' ) ), array(), $out );
rig_check( 'send newsletter to the list', array( 'json', true ), array( $kind, $out['success'] ?? null ) );
remove_filter( 'wp_doing_ajax', '__return_true' );

// Harness control: the same requests as an administrator do go through, so
// the "nothing written" results above aren't vacuous.
echo "== Control: an administrator gets through the same handlers ==\n";
wp_set_current_user( $admin_id );
$features = get_option( 'npmp_enabled_features' );
$r = rig_admin_init( array( 'npmp_features_nonce' => wp_create_nonce( 'npmp_save_features' ), 'npmp_feature_members' => '1', 'npmp_feature_newsletters' => '1', 'npmp_feature_donations' => '1', 'npmp_feature_calendar' => '1', 'npmp_feature_social' => '1' ) );
rig_check( 'admin features save redirects', 'redirect', $r['kind'] );
update_option( 'npmp_enabled_features', $features );
$threshold = get_option( 'npmp_dues_failure_threshold' );
$r = rig_admin_init( array( 'npmp_dues_save_settings' => '1', 'npmp_dues_nonce' => wp_create_nonce( 'npmp_dues_save_settings' ), 'npmp_dues_failure_threshold' => '7' ), array( 'page' => 'npmp_membership_dues' ), array( 'npmp_dues_failure_threshold' ) );
rig_check( 'admin dues pricing save writes and redirects', array( 'redirect', array( 'npmp_dues_failure_threshold' ) ), array( $r['kind'], $r['changed'] ) );
false === $threshold ? delete_option( 'npmp_dues_failure_threshold' ) : update_option( 'npmp_dues_failure_threshold', $threshold );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
// Part 2: real HTTP requests against the running site.
// ---------------------------------------------------------------------------
remove_all_filters( 'wp_redirect', 1 );
$base = untrailingslashit( site_url() );

function rig_http( $jar, $method, $url, $data = null, $headers = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_HTTPHEADER     => $headers,
	) );
	if ( null !== $data ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
	}
	$raw    = curl_exec( $ch );
	$code   = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$hsize  = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$header = substr( (string) $raw, 0, $hsize );
	$body   = substr( (string) $raw, $hsize );
	$loc    = preg_match( '/^Location:\s*(\S+)/mi', $header, $m ) ? $m[1] : '';
	return array( 'code' => $code, 'body' => $body, 'location' => $loc );
}
function rig_login( $base, $login, $password ) {
	$jar = tempnam( sys_get_temp_dir(), 'npmp-rig-jar' );
	file_put_contents( $jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\twordpress_test_cookie\tWP%20Cookie%20check\n" );
	$r = rig_http( $jar, 'POST', $base . '/wp-login.php', http_build_query( array( 'log' => $login, 'pwd' => $password, 'wp-submit' => 'Log In', 'testcookie' => '1' ) ) );
	return array( $jar, $r );
}
function rig_denied( $r ) {
	// wp_die() pages carry this class; a page that rendered does not.
	return 403 === $r['code'] || false !== strpos( $r['body'], 'wp-die-message' );
}

echo "== HTTP: Membership Manager ==\n";
list( $jar, $login ) = rig_login( $base, 'rig_membership_mgr', $password );
rig_check( 'login lands on the member list', admin_url( 'admin.php?page=npmp_members' ), $login['location'] );

$allowed = array(
	'admin.php?page=npmp_members',
	'admin.php?page=npmp_members&action=new',
	'admin.php?page=npmp_members&action=view&id=' . (int) $contact,
	'admin.php?page=npmp_membership',
	'admin.php?page=npmp_import',
	'admin.php?page=npmp_donations_group',
	'admin.php?page=npmp-newsletters',
	'admin.php?page=npmp_newsletter_templates',
	'admin.php?page=npmp_newsletter_archive',
	'admin.php?page=npmp_newsletter_reports',
	'edit.php?post_type=npmp_newsletter',
	'post-new.php?post_type=npmp_newsletter',
	'post.php?post=' . $nl_id . '&action=edit',
	'post-new.php?post_type=npmp_nl_template',
	'edit.php?post_type=npmp_nl_template',
	'admin.php?page=npmp_recurring_donations',
	'admin.php?page=npmp_membership_dues',
	'index.php',
	'profile.php',
);
$bodies = array();
foreach ( $allowed as $path ) {
	$r = rig_http( $jar, 'GET', admin_url( $path ) );
	$bodies[ $path ] = $r['body'];
	if ( getenv( 'RIG_DUMP' ) ) { file_put_contents( sys_get_temp_dir() . '/npmp-rig-' . md5( $path ) . '.html', $r['body'] ); }
	rig_check( "200 $path", array( 200, false ), array( $r['code'], rig_denied( $r ) ) );
}

$forbidden = array(
	// Nonprofit Manager settings and setup.
	'admin.php?page=npmp_main',
	'admin.php?page=npmp_general_settings',
	'admin.php?page=npmp_membership_forms',
	'admin.php?page=npmp_email_settings',
	'admin.php?page=npmp_newsletter_settings',
	'admin.php?page=npmp_donation_settings',
	'admin.php?page=npmp_payment_settings',
	'admin.php?page=npmp_setup_wizard',
	'admin.php?page=npmp_social_sharing',
	'admin.php?page=npmp_event_settings',
	'admin.php?page=npmp-events',
	'edit.php?post_type=npmp_event',
	// Pro settings.
	'admin.php?page=npmp_license',
	'admin.php?page=npmp_custom_fields',
	'admin.php?page=npmp_automations',
	'admin.php?page=npmp_segments',
	'admin.php?page=npmp_pro_setup_wizard',
	// The rest of WordPress.
	'edit.php',
	'post-new.php',
	'edit.php?post_type=page',
	'upload.php',
	'media-new.php',
	'edit-comments.php',
	'users.php',
	'user-new.php',
	'plugins.php',
	'themes.php',
	'options-general.php',
	'tools.php',
	'export.php',
	'site-health.php',
	'edit-tags.php?taxonomy=npmp_newsletter_topic&post_type=npmp_newsletter',
);
foreach ( $forbidden as $path ) {
	$r = rig_http( $jar, 'GET', admin_url( $path ) );
	rig_check( "403 $path", 403, $r['code'] );
}

// POSTs at settings screens die at WordPress's menu check, before any handler.
foreach ( array( 'npmp_payment_settings', 'npmp_email_settings', 'npmp_donation_settings', 'npmp_general_settings' ) as $page ) {
	$r = rig_http( $jar, 'POST', admin_url( 'admin.php?page=' . $page ), http_build_query( array( 'npmp_stripe_live_secret_key' => 'sk_live_evil' ) ) );
	rig_check( "POST $page refused", 403, $r['code'] );
}
$r = rig_http( $jar, 'POST', admin_url( 'options.php' ), http_build_query( array( 'option_page' => 'npmp_newsletter_settings', 'action' => 'update' ) ) );
rig_check( 'POST options.php refused', 403, $r['code'] );

// What the screens show.
$list = $bodies['admin.php?page=npmp_members'];
rig_check( 'member list has no "Give a volunteer access" panel', false, false !== strpos( $list, 'npmp-staff-access' ) );
rig_check( 'admin bar has no New menu', false, false !== strpos( $list, 'wp-admin-bar-new-content' ) );
rig_check( 'admin menu has no Posts', false, false !== strpos( $list, 'id="menu-posts"' ) );
rig_check( 'admin menu has no Settings', false, false !== strpos( $list, 'id="menu-settings"' ) );
rig_check( 'admin menu shows Membership', true, false !== strpos( $list, 'toplevel_page_npmp_membership' ) );
rig_check( 'no setup tour banner', false, false !== strpos( $list, 'npmp-tour-banner' ) );
rig_check( 'membership dashboard hides tier editing', false, false !== strpos( $bodies['admin.php?page=npmp_membership'], 'name="new_level"' ) );
rig_check( 'membership dashboard hides the settings button', false, false !== strpos( $bodies['admin.php?page=npmp_membership'], 'page=npmp_membership_forms' ) );
rig_check( 'import hides Mailchimp', false, false !== strpos( $bodies['admin.php?page=npmp_import'], 'value="mailchimp"' ) );
rig_check( 'import offers CSV', true, false !== strpos( $bodies['admin.php?page=npmp_import'], 'value="csv"' ) );
rig_check( 'dues screen hides pricing', false, false !== strpos( $bodies['admin.php?page=npmp_membership_dues'], 'npmp_dues_save_settings' ) );
rig_check( 'recurring screen lists the subscription', true, false !== strpos( $bodies['admin.php?page=npmp_recurring_donations'], 'Rig Subscriber' ) );
rig_check( 'recurring screen hides cancel/pause', false, false !== strpos( $bodies['admin.php?page=npmp_recurring_donations'], 'npmp_action=' ) );
rig_check( 'dashboard keeps the member summary widget', true, false !== strpos( $bodies['index.php'], 'npmp_summary_widget' ) );
rig_check( 'dashboard drops Activity', false, false !== strpos( $bodies['index.php'], 'id="dashboard_activity"' ) );
rig_check( 'newsletter menu shows no extra entries', false, false !== strpos( $list, "href='edit.php?post_type=npmp_newsletter'" ) || false !== strpos( $list, "href='post-new.php?post_type=npmp_newsletter'" ) );
rig_check( 'newsletter editor offers Send to Selected Members', true, false !== strpos( $bodies[ 'post.php?post=' . $nl_id . '&action=edit' ], 'id="npmp-send-newsletter"' ) );

// Member writes over HTTP: create, update, delete, each redirecting.
$new_page = $bodies['admin.php?page=npmp_members&action=new'];
preg_match( '/name="npmp_members_nonce" value="([^"]+)"/', $new_page, $m );
$nonce = $m[1] ?? '';
$email = 'rig-http-' . wp_rand() . '@example.org';
$r = rig_http( $jar, 'POST', admin_url( 'admin.php?page=npmp_members&action=new' ), http_build_query( array( 'npmp_members_action' => 'create', 'npmp_members_nonce' => $nonce, 'member' => array( 'name' => 'Rig Http', 'email' => $email, 'status' => 'subscribed', 'membership_level' => 'RigLevel' ) ) ) );
rig_check( 'create contact redirects to it', array( 302, true ), array( $r['code'], false !== strpos( $r['location'], 'message=created' ) ) );
wp_cache_flush();
$created = $members->get_member_by_email( $email );
rig_check( 'contact exists', true, (bool) $created );
$r = rig_http( $jar, 'POST', admin_url( 'admin.php?page=npmp_members&action=view&id=' . (int) $created->id ), http_build_query( array( 'npmp_members_action' => 'update', 'npmp_members_nonce' => $nonce, 'member_id' => (int) $created->id, 'member' => array( 'name' => 'Rig Http', 'email' => $email, 'status' => 'unsubscribed', 'membership_level' => 'Gold' ) ) ) );
rig_check( 'change status and level redirects', array( 302, true ), array( $r['code'], false !== strpos( $r['location'], 'message=updated' ) ) );
wp_cache_flush();
$updated = $members->get_member_by_email( $email );
rig_check( 'status and level saved', array( 'unsubscribed', 'Gold' ), array( $updated->status ?? null, $updated->membership_level ?? null ) );
$view = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_members&action=view&id=' . (int) $created->id ) );
preg_match( '/href="([^"]*action=delete[^"]*)"/', $view['body'], $m );
$r = rig_http( $jar, 'GET', html_entity_decode( $m[1] ?? '' ) );
rig_check( 'delete contact redirects', array( 302, true ), array( $r['code'], false !== strpos( $r['location'], 'message=deleted' ) ) );
wp_cache_flush();
rig_check( 'contact deleted', null, $members->get_member_by_email( $email ) );

// CSV import over admin-ajax.
preg_match( '/name="npmp_import_nonce" value="([^"]+)"/', $bodies['admin.php?page=npmp_import'], $m );
$import_nonce = $m[1] ?? '';
$csv = tempnam( sys_get_temp_dir(), 'npmp-rig' ) . '.csv';
file_put_contents( $csv, "email,name\nrig-import@example.org,Rig Import\n" );
$r = rig_http( $jar, 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'npmp_import_preview', 'nonce' => $import_nonce, 'source' => 'csv', 'import_file' => new CURLFile( $csv, 'text/csv', 'members.csv' ) ) );
$j = json_decode( $r['body'], true );
rig_check( 'CSV import preview works', true, $j['success'] ?? null );
@unlink( $csv );
$r = rig_http( $jar, 'POST', admin_url( 'admin-ajax.php' ), http_build_query( array( 'action' => 'npmp_import_mailchimp_lists', 'nonce' => $import_nonce, 'api_key' => 'x-us1' ) ) );
rig_check( 'Mailchimp list fetch refused over HTTP', false, json_decode( $r['body'], true )['success'] ?? null );

// The block editor's REST calls, with the nonce the editor page carries.
preg_match( '/createNonceMiddleware\(\s*"([^"]+)"/', $bodies['post-new.php?post_type=npmp_newsletter'], $m );
$rest_nonce = $m[1] ?? '';
$r = rig_http( $jar, 'POST', $base . '/?rest_route=/wp/v2/npmp_newsletter', wp_json_encode( array( 'title' => 'Rig HTTP newsletter', 'status' => 'draft' ) ), array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $rest_nonce ) );
rig_check( 'block editor can save a newsletter', 201, $r['code'] );
$r = rig_http( $jar, 'POST', $base . '/?rest_route=/wp/v2/posts', wp_json_encode( array( 'title' => 'Rig post', 'status' => 'draft' ) ), array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $rest_nonce ) );
rig_check( 'but cannot write a blog post', 403, $r['code'] );

echo "== HTTP: Author (unchanged) ==\n";
list( $ajar, $login ) = rig_login( $base, 'rig_author', $password );
rig_check( 'login keeps the normal destination', admin_url(), $login['location'] );
rig_check( 'Author still opens the newsletter editor', 200, rig_http( $ajar, 'GET', admin_url( 'post-new.php?post_type=npmp_newsletter' ) )['code'] );
rig_check( 'Author still opens Posts', 200, rig_http( $ajar, 'GET', admin_url( 'edit.php' ) )['code'] );
rig_check( 'Author still refused the member list', true, rig_denied( rig_http( $ajar, 'GET', admin_url( 'admin.php?page=npmp_members' ) ) ) );

echo "== HTTP: administrator (unchanged) ==\n";
list( $djar, $login ) = rig_login( $base, 'rig_admin', $password );
rig_check( 'login keeps the normal destination', admin_url(), $login['location'] );
foreach ( array( 'admin.php?page=npmp_general_settings', 'admin.php?page=npmp_payment_settings', 'admin.php?page=npmp_members', 'admin.php?page=npmp_license', 'admin.php?page=npmp_membership_dues' ) as $path ) {
	rig_check( "admin 200 $path", 200, rig_http( $djar, 'GET', admin_url( $path ) )['code'] );
}
$list = rig_http( $djar, 'GET', admin_url( 'admin.php?page=npmp_members' ) )['body'];
rig_check( 'admin sees "Give a volunteer access"', true, false !== strpos( $list, 'npmp-staff-access' ) );
$user_new = rig_http( $djar, 'GET', admin_url( 'user-new.php?npmp_role=npmp_membership_manager' ) )['body'];
rig_check( 'Add User preselects Membership Manager', true, (bool) preg_match( '/<option selected=[\'"]selected[\'"] value=[\'"]npmp_membership_manager[\'"]/', $user_new ) );
rig_check( 'stored default role untouched', 'subscriber', get_option( 'default_role' ) );
$dues = rig_http( $djar, 'GET', admin_url( 'admin.php?page=npmp_membership_dues' ) )['body'];
rig_check( 'admin dues screen keeps pricing', true, false !== strpos( $dues, 'npmp_dues_save_settings' ) );
$rec = rig_http( $djar, 'GET', admin_url( 'admin.php?page=npmp_recurring_donations' ) )['body'];
rig_check( 'admin recurring screen keeps cancel', true, false !== strpos( $rec, 'npmp_action=cancel' ) );

// Cleanup.
foreach ( array( $jar, $ajar, $djar ) as $f ) { @unlink( $f ); }
$wpdb->delete( $wpdb->prefix . 'npmp_recurring_donations', array( 'id' => $sub_id ) );
$leftover = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d", $first_new_post ) );
foreach ( $leftover as $pid ) { wp_delete_post( (int) $pid, true ); }
if ( $topic_id ) { wp_delete_term( $topic_id, 'npmp_newsletter_topic' ); }
foreach ( array( $mm_id, $author_id, $admin_id ) as $uid ) { wp_delete_user( $uid ); }

if ( $GLOBALS['rig_errors'] ) { echo "\nPHP notices from the plugins:\n  " . implode( "\n  ", array_unique( $GLOBALS['rig_errors'] ) ) . "\n"; }
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
