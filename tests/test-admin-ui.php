<?php
/**
 * Shared admin UI pieces.
 *
 * - npmp_admin_page_title() prints core's layout: h1.wp-heading-inline, the
 *   page-title-action links, then hr.wp-header-end. The links used to sit
 *   inside the h1 with no wp-header-end, so core moved notices above titles.
 * - npmp_admin_page_header() keeps the three-argument signature Pro calls.
 * - The tour shows only on the plugin's own admin.php?page= screens. It used
 *   to match any screen ID with "npmp" in it, so the modal covered the block
 *   editor for events and newsletters.
 * - The CAN-SPAM [address] falls back to the organization address from
 *   General Settings when the newsletter mailing address is empty. It used to
 *   fall straight to the admin email.
 * - The Overview and the setup wizard read one list of feature names.
 *
 * Run: php tests/test-admin-ui.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function npmp_label( $key ) {
	return array( 'donations' => 'Donations' )[ $key ] ?? $key;
}

require_once dirname( __DIR__ ) . '/includes/npmp-admin-helpers.php';
require_once dirname( __DIR__ ) . '/includes/onboarding/class-tour.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/can-spam-shortcode.php';

echo "Page title markup\n";
ob_start();
npmp_admin_page_title( 'Contacts', array( 'Add Contact' => 'admin.php?page=npmp_members&action=new' ) );
$html = ob_get_clean();
check(
	'h1, then the action, then wp-header-end',
	'<h1 class="wp-heading-inline">Contacts</h1> <a href="admin.php?page=npmp_members&amp;action=new" class="page-title-action">Add Contact</a><hr class="wp-header-end">',
	$html
);
ob_start();
npmp_admin_page_title( 'Welcome', array(), 'Pro' );
check( 'badge sits inside the h1', '<h1 class="wp-heading-inline">Welcome <span class="npmp-pro-badge">Pro</span></h1><hr class="wp-header-end">', ob_get_clean() );

ob_start();
npmp_admin_page_header( 'Segments' );
$html = ob_get_clean();
check( 'one-argument header (Pro) opens the wrap', 0, strpos( $html, '<div class="wrap npmp-admin-page"><h1 class="wp-heading-inline">Segments</h1>' ) );
check( 'one-argument header ends with wp-header-end', true, substr( $html, -strlen( '<hr class="wp-header-end">' ) ) === '<hr class="wp-header-end">' );
ob_start();
npmp_admin_page_header( 'Templates', 'Reusable layouts.', array( 'Add Template' => 'post-new.php?post_type=npmp_nl_template' ) );
$html = ob_get_clean();
check( 'action link is outside the h1', true, false !== strpos( $html, '</h1> <a href="post-new.php?post_type=npmp_nl_template" class="page-title-action">Add Template</a><hr class="wp-header-end">' ) );
check( 'description after the header end', true, strpos( $html, 'wp-header-end' ) < strpos( $html, 'Reusable layouts.' ) );

echo "Tour screens\n";
check( 'Overview', true, NPMP_Tour::screen_is_ours( 'toplevel_page_npmp_main' ) );
check( 'General Settings', true, NPMP_Tour::screen_is_ours( 'nonprofit-manager_page_npmp_general_settings' ) );
check( 'Contacts', true, NPMP_Tour::screen_is_ours( 'membership_page_npmp_members' ) );
check( 'Newsletters top level', true, NPMP_Tour::screen_is_ours( 'toplevel_page_npmp-newsletters' ) );
check( 'event editor (block editor)', false, NPMP_Tour::screen_is_ours( 'npmp_event', 'post', true ) );
check( 'newsletter editor', false, NPMP_Tour::screen_is_ours( 'npmp_newsletter', 'post', true ) );
check( 'email body editor, classic', false, NPMP_Tour::screen_is_ours( 'npmp_email_body', 'post', false ) );
check( 'event list', false, NPMP_Tour::screen_is_ours( 'edit-npmp_event', 'edit' ) );
check( 'event categories', false, NPMP_Tour::screen_is_ours( 'edit-npmp_event_category', 'edit-tags' ) );
check( 'setup wizard', false, NPMP_Tour::screen_is_ours( 'admin_page_npmp_setup_wizard' ) );
check( 'core Dashboard', false, NPMP_Tour::screen_is_ours( 'dashboard', 'dashboard' ) );
check( 'someone else\'s page', false, NPMP_Tour::screen_is_ours( 'toplevel_page_other_plugin' ) );

echo "CAN-SPAM address\n";
$org = array(
	'address_line1' => '123 Main St',
	'address_line2' => 'Suite 4',
	'city'          => 'Ventura',
	'state'         => 'CA',
	'postal_code'   => '93001',
	'country'       => 'US',
);
check( 'org address formats as postal lines', "123 Main St\nSuite 4\nVentura, CA 93001", npmp_format_postal_address( $org ) );
check( 'no street means no address', '', npmp_format_postal_address( array( 'city' => 'Ventura' ) ) );
check( 'country shows outside the US', "1 High St\nLeeds, LS1\nUK", npmp_format_postal_address( array( 'address_line1' => '1 High St', 'city' => 'Leeds', 'postal_code' => 'LS1', 'country' => 'UK' ) ) );

$GLOBALS['t_options'] = array( 'npmp_org_settings' => $org, 'admin_email' => 'admin@example.org' );
check( 'empty newsletter address falls back to the org address', "123 Main St\nSuite 4\nVentura, CA 93001", npmp_can_spam_address_text() );
$GLOBALS['t_options']['npmp_org_mailing_address'] = 'PO Box 9, Ojai, CA';
check( 'newsletter address wins when set', 'PO Box 9, Ojai, CA', npmp_can_spam_address_text() );
$GLOBALS['t_options'] = array( 'admin_email' => 'admin@example.org' );
check( 'neither set: empty, so the footer uses the admin email', '', npmp_can_spam_address_text() );
check( 'footer token with neither set', 'admin@example.org', npmp_can_spam_get_address() );

echo "Feature names\n";
check( 'one list for the Overview and the wizard', array( 'members', 'newsletters', 'donations', 'calendar', 'social' ), array_keys( npmp_feature_names() ) );
check( 'donations name follows the label map', 'Donations', npmp_feature_names()['donations'] );
check( 'calendar name matches the wizard', 'Event Calendar', npmp_feature_names()['calendar'] );

echo "Every admin h1 ends its header\n";
// Core moves notices to just after hr.wp-header-end. A screen without one puts them above the
// title. Every h1 printed under includes/ needs the rule within the next two lines.
$npmp_root = dirname( __DIR__ ) . '/includes';
$npmp_missing = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $npmp_root ) ) as $npmp_f ) {
	if ( 'php' !== $npmp_f->getExtension() ) {
		continue;
	}
	$npmp_lines = file( $npmp_f->getPathname() );
	foreach ( $npmp_lines as $npmp_i => $npmp_line ) {
		if ( ! preg_match( '/<h1[ >]/', $npmp_line ) || false === strpos( $npmp_line, '</h1>' ) ) {
			continue;
		}
		$npmp_next = implode( '', array_slice( $npmp_lines, $npmp_i, 3 ) );
		if ( false === strpos( $npmp_next, 'wp-header-end' ) && false === strpos( $npmp_next, 'npmp_admin_page' ) ) {
			$npmp_missing[] = substr( $npmp_f->getPathname(), strlen( $npmp_root ) + 1 ) . ':' . ( $npmp_i + 1 );
		}
	}
}
check( 'no h1 without wp-header-end', array(), $npmp_missing );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
