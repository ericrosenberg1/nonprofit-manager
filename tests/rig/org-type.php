<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/org-type.php
//
// End-to-end check of the kind-of-group setting (includes/npmp-org-type.php)
// on a real WordPress with free and Pro active and the site served over HTTP
// at its siteurl (php -S, see the Pro repo's tests/rig/README.md).
//
// Signs in as an administrator and submits the real setup wizard form with
// "Club or association" picked, then reads the Members screens for the club
// words. Switches to "Faith community" through the General Settings form and
// back to Nonprofit, checking the words each time, that the admin screen IDs
// never change with the menu title, and that a Membership Manager can't
// change the setting. Fails on any PHP notice from either plugin in debug.log.
//
// Creates its own staff user and contact and deletes them at the end.
// Not run by the pre-push gate (it needs MySQL and a running WordPress).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-74s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function rig_has( $label, $needle, $haystack ) {
	rig_check( $label, true, false !== strpos( $haystack, $needle ) );
}
function rig_lacks( $label, $needle, $haystack ) {
	rig_check( $label, false, false !== strpos( $haystack, $needle ) );
}
function rig_http( $jar, $method, $url, $data = null ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_COOKIEJAR      => $jar,
		CURLOPT_COOKIEFILE     => $jar,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_CUSTOMREQUEST  => $method,
	) );
	if ( null !== $data ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
	}
	$raw    = curl_exec( $ch );
	$hsize  = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$header = substr( (string) $raw, 0, $hsize );
	return array(
		'code'     => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
		'body'     => substr( (string) $raw, $hsize ),
		'location' => preg_match( '/^Location:\s*(\S+)/mi', $header, $m ) ? $m[1] : '',
	);
}
function rig_login( $base, $login, $password ) {
	$jar = tempnam( sys_get_temp_dir(), 'npmp-rig-jar' );
	file_put_contents( $jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\twordpress_test_cookie\tWP%20Cookie%20check\n" );
	$r = rig_http( $jar, 'POST', $base . '/wp-login.php', http_build_query( array( 'log' => $login, 'pwd' => $password, 'wp-submit' => 'Log In', 'testcookie' => '1' ) ) );
	return array( $jar, $r );
}
function rig_field( $html, $name ) {
	return preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]+)"/', $html, $m ) ? $m[1] : '';
}
function rig_option( $name ) {
	// The HTTP requests write the option in another process, so skip the
	// object cache this process filled at load.
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}
function rig_body_class( $html ) {
	return preg_match( '/<body class="([^"]*)"/', $html, $m ) ? $m[1] : '';
}

foreach ( array( 'nonprofit-manager/nonprofit-manager.php', 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) as $plugin ) {
	if ( ! is_plugin_active( $plugin ) ) {
		activate_plugin( $plugin );
	}
}
rig_check( 'free active', true, is_plugin_active( 'nonprofit-manager/nonprofit-manager.php' ) );
rig_check( 'Pro active', true, is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) );

// A fresh site: no choice saved, every feature on, wizard not yet done.
delete_option( 'npmp_org_type' );
delete_option( 'npmp_setup_completed' );
update_option( 'npmp_pro_setup_completed', true );
update_option( 'npmp_enabled_features', array( 'members' => true, 'newsletters' => true, 'donations' => true, 'calendar' => true, 'social' => false ) );
rig_check( 'no saved choice reads as nonprofit', 'nonprofit', npmp_get_org_type() );
rig_check( 'no saved choice shows Donors', 'Donors', npmp_label( 'donors' ) );

$password = 'Rig-OrgType-' . wp_generate_password( 12, false );
$admin_id = (int) username_exists( 'rig_org_admin' );
if ( ! $admin_id ) {
	$admin_id = wp_create_user( 'rig_org_admin', $password, 'rig_org_admin@example.test' );
}
wp_set_password( $password, $admin_id );
( new WP_User( $admin_id ) )->set_role( 'administrator' );
$staff_id = (int) username_exists( 'rig_org_staff' );
if ( ! $staff_id ) {
	$staff_id = wp_create_user( 'rig_org_staff', $password, 'rig_org_staff@example.test' );
}
wp_set_password( $password, $staff_id );
( new WP_User( $staff_id ) )->set_role( 'npmp_membership_manager' );

// One contact with a donation, so the member list has a row.
$contact_id = wp_insert_post( array( 'post_type' => 'npmp_contact', 'post_status' => 'publish', 'post_title' => 'Rig Org Contact' ) );
update_post_meta( $contact_id, 'npmp_email', 'rig_org_contact@example.test' );

$base     = untrailingslashit( site_url() );
$log_file = WP_CONTENT_DIR . '/debug.log';
$log_from = file_exists( $log_file ) ? filesize( $log_file ) : 0;

echo "== Setup wizard: pick Club or association ==\n";
list( $jar, $login ) = rig_login( $base, 'rig_org_admin', $password );
rig_check( 'admin login redirects', 302, $login['code'] );
$wizard = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_setup_wizard' ) );
rig_check( 'wizard page 200', 200, $wizard['code'] );
rig_has( 'wizard asks the question', 'What kind of group is this?', $wizard['body'] );
foreach ( array( 'nonprofit', 'club', 'school', 'sports', 'faith', 'hoa' ) as $slug ) {
	rig_has( "wizard offers $slug", 'name="npmp_org_type" value="' . $slug . '"', $wizard['body'] );
}
rig_check( 'nonprofit is checked by default', 1, preg_match( '/value="nonprofit"\s+checked/', $wizard['body'] ) );
rig_has( 'club card previews its words', 'Supporters, Contributions, Dues Levels', $wizard['body'] );
$nonce = rig_field( $wizard['body'], 'npmp_setup_nonce' );
rig_check( 'wizard nonce found', true, '' !== $nonce );

$saved = rig_http( $jar, 'POST', admin_url( 'admin.php?page=npmp_setup_wizard' ), http_build_query( array(
	'npmp_setup_nonce'         => $nonce,
	'npmp_setup_step'          => 'complete',
	'npmp_org_type'            => 'club',
	'npmp_feature_members'     => 'on',
	'npmp_feature_newsletters' => 'on',
	'npmp_feature_donations'   => 'on',
	'npmp_feature_calendar'    => 'on',
) ) );
rig_check( 'wizard submit redirects to the Overview', admin_url( 'admin.php?page=npmp_main&setup_complete=1' ), $saved['location'] );
rig_check( 'wizard saved club', 'club', rig_option( 'npmp_org_type' ) );
rig_check( 'wizard marked setup done', '1', rig_option( 'npmp_setup_completed' ) );

echo "== Club words on screen ==\n";
$overview = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_main&setup_complete=1' ) );
rig_has( 'Overview welcomes to Club Manager', 'Welcome to Club Manager', $overview['body'] );
rig_has( 'setup notice names Club Manager', 'Setup complete! Club Manager is ready to use.', $overview['body'] );

$members = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_members' ) );
rig_check( 'Members screen 200', 200, $members['code'] );
rig_has( 'Members screen column says Last Contribution', 'Last Contribution', $members['body'] );
rig_lacks( 'Members screen has no Last Donation', 'Last Donation', $members['body'] );
rig_has( 'Members screen heading', 'Contacts &amp; Members', $members['body'] );
rig_has( 'sidebar says Club Manager', '>Club Manager <span', $members['body'] );
rig_has( 'sidebar says Contributions', '>Contributions<', $members['body'] );
rig_has( 'sidebar says Contribution Settings', '>Contribution Settings<', $members['body'] );
rig_has( 'Pro sidebar says Recurring Contributions', '>Recurring Contributions<', $members['body'] );
rig_lacks( 'sidebar has no Nonprofit Manager', '>Nonprofit Manager <span', $members['body'] );
rig_has( 'Members screen ID unchanged', 'membership_page_npmp_members', rig_body_class( $members['body'] ) );

$dash = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_membership' ) );
rig_has( 'Membership dashboard: Recent Supporters', 'Recent Supporters', $dash['body'] );
rig_has( 'Membership dashboard: Dues Levels', 'Dues Levels', $dash['body'] );

$forms = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_membership_forms' ) );
rig_has( 'Membership Settings: Dues Levels heading', '<h2>Dues Levels</h2>', $forms['body'] );
rig_has( 'Membership Settings screen ID unchanged', 'membership_page_npmp_membership_forms', rig_body_class( $forms['body'] ) );

$don = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_donations_group' ) );
rig_has( 'Donations dashboard: Contributions Summary', 'Contributions Summary', $don['body'] );
$don_settings = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_donation_settings' ) );
rig_has( 'Donation settings screen ID unchanged', 'donations_page_npmp_donation_settings', rig_body_class( $don_settings['body'] ) );

echo "== General Settings: switch to Faith community ==\n";
$gen = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_general_settings' ) );
rig_check( 'General Settings 200', 200, $gen['code'] );
rig_has( 'General Settings screen ID unchanged under Club Manager', 'nonprofit-manager_page_npmp_general_settings', rig_body_class( $gen['body'] ) );
rig_check( 'General Settings shows club selected', 1, preg_match( '/<option value="club"\s+selected/', $gen['body'] ) );
$org_nonce = rig_field( $gen['body'], 'npmp_org_settings_nonce' );
rig_check( 'org settings nonce found', true, '' !== $org_nonce );
$post = http_build_query( array(
	'npmp_org_settings_nonce' => $org_nonce,
	'npmp_org_type'           => 'faith',
	'npmp_org_settings'       => array( 'name' => 'Rig Congregation', 'country' => 'US' ),
	'npmp_save_org_only'      => 'Save organization details',
) );
$switch = rig_http( $jar, 'POST', admin_url( 'admin.php?page=npmp_general_settings' ), $post );
rig_check( 'save redirects back to General Settings', admin_url( 'admin.php?page=npmp_general_settings&updated=1' ), $switch['location'] );
rig_check( 'General Settings saved faith', 'faith', rig_option( 'npmp_org_type' ) );

$members = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_members' ) );
rig_has( 'Members screen column says Last Gift', 'Last Gift', $members['body'] );
rig_has( 'sidebar says Congregation Manager', '>Congregation Manager <span', $members['body'] );
rig_has( 'Pro sidebar says Recurring Gifts', '>Recurring Gifts<', $members['body'] );
$dash = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_membership' ) );
rig_has( 'Membership dashboard: Recent Givers', 'Recent Givers', $dash['body'] );

echo "== Membership Manager can't change it ==\n";
list( $staff_jar ) = rig_login( $base, 'rig_org_staff', $password );
$staff_post = http_build_query( array(
	'npmp_org_settings_nonce' => wp_create_nonce( 'npmp_save_org_settings' ),
	'npmp_org_type'           => 'hoa',
	'npmp_org_settings'       => array( 'name' => 'Nope' ),
) );
rig_http( $staff_jar, 'POST', admin_url( 'admin.php?page=npmp_members' ), $staff_post );
rig_check( 'staff post left faith in place', 'faith', rig_option( 'npmp_org_type' ) );
$staff_members = rig_http( $staff_jar, 'GET', admin_url( 'admin.php?page=npmp_members' ) );
rig_check( 'staff still opens the Members screen', 200, $staff_members['code'] );
rig_has( 'staff sees the faith words too', 'Last Gift', $staff_members['body'] );

echo "== Back to Nonprofit ==\n";
$gen      = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_general_settings' ) );
$org_post = http_build_query( array(
	'npmp_org_settings_nonce' => rig_field( $gen['body'], 'npmp_org_settings_nonce' ),
	'npmp_org_type'           => 'nonprofit',
	'npmp_org_settings'       => array( 'name' => 'Rig Nonprofit', 'country' => 'US' ),
) );
rig_http( $jar, 'POST', admin_url( 'admin.php?page=npmp_general_settings' ), $org_post );
rig_check( 'saved nonprofit', 'nonprofit', rig_option( 'npmp_org_type' ) );
$members = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_members' ) );
rig_has( 'Members screen column says Last Donation again', 'Last Donation', $members['body'] );
rig_has( 'sidebar says Nonprofit Manager again', '>Nonprofit Manager <span', $members['body'] );
rig_has( 'Pro sidebar says Recurring Donations again', '>Recurring Donations<', $members['body'] );
$dash = rig_http( $jar, 'GET', admin_url( 'admin.php?page=npmp_membership' ) );
rig_has( 'Membership dashboard: Recent Donors again', 'Recent Donors', $dash['body'] );

echo "== Front end ==\n";
update_option( 'npmp_org_type', 'club' );
$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Give', 'post_content' => '[npmp_donation_form]' ) );
$front   = rig_http( $jar, 'GET', add_query_arg( 'npmp_donation', 'success', get_permalink( $page_id ) ) );
rig_check( 'donation page 200', 200, $front['code'] );
rig_has( 'thank-you banner uses the club word', 'We received your contribution.', $front['body'] );
update_option( 'npmp_org_type', 'nonprofit' );

echo "== PHP notices ==\n";
$log   = file_exists( $log_file ) ? (string) file_get_contents( $log_file, false, null, $log_from ) : '';
$lines = array_filter( explode( "\n", $log ), static function ( $l ) {
	return false !== strpos( $l, 'nonprofit-manager' ) || false !== stripos( $l, 'too early' );
} );
rig_check( 'no notices from either plugin in debug.log', array(), array_values( $lines ) );

wp_delete_post( $page_id, true );
wp_delete_post( $contact_id, true );
wp_delete_user( $staff_id );
wp_delete_user( $admin_id );

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
