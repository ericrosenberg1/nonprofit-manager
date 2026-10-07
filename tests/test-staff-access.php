<?php
/**
 * The Membership Manager role and the screens it can reach.
 *
 * Covers the role's capabilities, the administrator grant and the upgrade
 * routine for sites that update without re-activating, how the staff and
 * newsletter capabilities resolve for each core role, the login redirect,
 * the import source split, and a source scan pinning which admin screens
 * use which capability so a settings screen can't drift open to staff.
 *
 * The same rules are checked on a real WordPress, over HTTP, by
 * tests/rig/membership-manager-role.php.
 *
 * Run: php tests/test-staff-access.php
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// WordPress stand-ins: an options table, a roles registry and users.
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = array();
$GLOBALS['test_roles']   = array();
$GLOBALS['test_user']    = null;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $key ] : $default;
}
function update_option( $key, $value ) {
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . ltrim( (string) $path, '/' );
}
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

class Test_Role {
	public $name;
	public $capabilities;
	public function __construct( $name, $caps ) {
		$this->name         = $name;
		$this->capabilities = $caps;
	}
	public function add_cap( $cap ) {
		$this->capabilities[ $cap ] = true;
	}
	public function remove_cap( $cap ) {
		unset( $this->capabilities[ $cap ] );
	}
}
function get_role( $name ) {
	return $GLOBALS['test_roles'][ $name ] ?? null;
}
function add_role( $name, $label, $caps ) {
	$GLOBALS['test_roles'][ $name ] = new Test_Role( $name, $caps );
	$GLOBALS['test_role_labels'][ $name ] = $label;
	return $GLOBALS['test_roles'][ $name ];
}

class WP_User {
	public $ID;
	public $roles;
	public function __construct( $id, $roles ) {
		$this->ID    = $id;
		$this->roles = $roles;
	}
	public function exists() {
		return $this->ID > 0;
	}
	/** Role caps merged, then run through the plugin's user_has_cap filter, like WP_User::has_cap(). */
	public function allcaps() {
		$caps = array();
		foreach ( $this->roles as $role ) {
			$r = get_role( $role );
			if ( $r ) {
				$caps = array_merge( $caps, array_filter( $r->capabilities ) );
			}
		}
		return npmp_filter_staff_caps( $caps );
	}
}
function user_can( $user, $cap ) {
	$all = $user->allcaps();
	return ! empty( $all[ $cap ] );
}
function wp_get_current_user() {
	return $GLOBALS['test_user'] ?? new WP_User( 0, array() );
}
function current_user_can( $cap ) {
	return user_can( wp_get_current_user(), $cap );
}

/** Core's default roles, as WordPress creates them. */
function test_seed_core_roles() {
	$GLOBALS['test_roles'] = array();
	$author = array( 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'delete_published_posts' => true, 'upload_files' => true );
	$editor = array_merge( $author, array( 'edit_others_posts' => true, 'delete_others_posts' => true, 'edit_private_posts' => true, 'read_private_posts' => true, 'delete_private_posts' => true, 'manage_categories' => true, 'edit_pages' => true, 'unfiltered_html' => true, 'moderate_comments' => true ) );
	$admin  = array_merge( $editor, array( 'manage_options' => true, 'list_users' => true, 'create_users' => true, 'promote_users' => true, 'edit_users' => true, 'activate_plugins' => true, 'edit_theme_options' => true ) );
	add_role( 'administrator', 'Administrator', $admin );
	add_role( 'editor', 'Editor', $editor );
	add_role( 'author', 'Author', $author );
	add_role( 'contributor', 'Contributor', array( 'read' => true, 'edit_posts' => true, 'delete_posts' => true ) );
	add_role( 'subscriber', 'Subscriber', array( 'read' => true ) );
}

require_once dirname( __DIR__ ) . '/includes/npmp-staff-access.php';

// ---------------------------------------------------------------------------
echo "Role install\n";
test_seed_core_roles();
$GLOBALS['test_options'] = array();
npmp_maybe_upgrade_roles(); // A site that updated without re-activating.

$role = get_role( 'npmp_membership_manager' );
check( 'upgrade routine creates the role', true, null !== $role );
$caps = array_keys( array_filter( $role->capabilities ) );
sort( $caps );
check( 'role holds exactly read + npmp_manage_members', array( 'npmp_manage_members', 'read' ), $caps );
check( 'role label', 'Membership Manager', $GLOBALS['test_role_labels']['npmp_membership_manager'] );
check( 'administrator role gains the cap', true, ! empty( get_role( 'administrator' )->capabilities['npmp_manage_members'] ) );
check( 'editor role does not', false, ! empty( get_role( 'editor' )->capabilities['npmp_manage_members'] ) );
check( 'version stored', NPMP_ROLES_VERSION, get_option( 'npmp_roles_version' ) );
check( 'npmp_staff_cap()', 'npmp_manage_members', npmp_staff_cap() );

// Up to date: the routine leaves the roles alone.
get_role( 'administrator' )->remove_cap( 'npmp_manage_members' );
npmp_maybe_upgrade_roles();
check( 'no re-run while the stored version is current', false, ! empty( get_role( 'administrator' )->capabilities['npmp_manage_members'] ) );

// Stale version: re-run repairs, and keeps caps a site owner added on purpose.
update_option( 'npmp_roles_version', '0' );
get_role( 'npmp_membership_manager' )->remove_cap( 'npmp_manage_members' );
get_role( 'npmp_membership_manager' )->add_cap( 'site_owner_extra' );
npmp_maybe_upgrade_roles();
check( 'stale version re-runs: admin cap restored', true, ! empty( get_role( 'administrator' )->capabilities['npmp_manage_members'] ) );
check( 'stale version re-runs: role cap restored', true, ! empty( get_role( 'npmp_membership_manager' )->capabilities['npmp_manage_members'] ) );
check( 'a cap the site owner added is kept', true, ! empty( get_role( 'npmp_membership_manager' )->capabilities['site_owner_extra'] ) );
get_role( 'npmp_membership_manager' )->remove_cap( 'site_owner_extra' );

// Activation path is the same function.
test_seed_core_roles();
npmp_install_roles();
npmp_install_roles();
check( 'install is idempotent', array( 'read' => true, 'npmp_manage_members' => true ), get_role( 'npmp_membership_manager' )->capabilities );

// ---------------------------------------------------------------------------
echo "\nCapabilities by role\n";
$mm     = new WP_User( 10, array( 'npmp_membership_manager' ) );
$admin  = new WP_User( 11, array( 'administrator' ) );
$editor = new WP_User( 12, array( 'editor' ) );
$author = new WP_User( 13, array( 'author' ) );
$contrib = new WP_User( 14, array( 'contributor' ) );
$sub    = new WP_User( 15, array( 'subscriber' ) );

foreach ( array( 'npmp_manage_members', 'read', 'edit_npmp_newsletters', 'edit_others_npmp_newsletters', 'publish_npmp_newsletters', 'edit_published_npmp_newsletters', 'delete_npmp_newsletters' ) as $cap ) {
	check( "Membership Manager: $cap", true, user_can( $mm, $cap ) );
}
foreach ( array( 'manage_options', 'edit_posts', 'edit_others_posts', 'publish_posts', 'upload_files', 'list_users', 'unfiltered_html', 'manage_categories', 'edit_pages' ) as $cap ) {
	check( "Membership Manager: no $cap", false, user_can( $mm, $cap ) );
}
check( 'administrator: staff cap', true, user_can( $admin, 'npmp_manage_members' ) );
get_role( 'administrator' )->remove_cap( 'npmp_manage_members' );
check( 'administrator: staff cap even when stripped from the role', true, user_can( $admin, 'npmp_manage_members' ) );
get_role( 'administrator' )->add_cap( 'npmp_manage_members' );
check( 'editor: no staff cap', false, user_can( $editor, 'npmp_manage_members' ) );
check( 'editor: still sends newsletters (edit_others_posts)', true, user_can( $editor, 'edit_others_npmp_newsletters' ) );
check( 'author: still writes newsletters', true, user_can( $author, 'edit_npmp_newsletters' ) );
check( 'author: still cannot send', false, user_can( $author, 'edit_others_npmp_newsletters' ) );
check( 'contributor: still writes newsletters', true, user_can( $contrib, 'edit_npmp_newsletters' ) );
check( 'contributor: still cannot publish', false, user_can( $contrib, 'publish_npmp_newsletters' ) );
check( 'subscriber: no newsletters', false, user_can( $sub, 'edit_npmp_newsletters' ) );
check( 'subscriber: no staff cap', false, user_can( $sub, 'npmp_manage_members' ) );

check( 'limited staff: Membership Manager', true, npmp_is_limited_staff( $mm ) );
check( 'limited staff: not an administrator', false, npmp_is_limited_staff( $admin ) );
check( 'limited staff: not an editor who also has the role', false, npmp_is_limited_staff( new WP_User( 16, array( 'editor', 'npmp_membership_manager' ) ) ) );
check( 'limited staff: not a logged-out visitor', false, npmp_is_limited_staff( new WP_User( 0, array() ) ) );

// ---------------------------------------------------------------------------
echo "\nLogin redirect\n";
$members = admin_url( 'admin.php?page=npmp_members' );
check( 'Membership Manager, no destination: member list', $members, npmp_staff_login_redirect( admin_url(), '', $mm ) );
check( 'Membership Manager, sent to wp-admin/: member list', $members, npmp_staff_login_redirect( admin_url(), admin_url(), $mm ) );
check( 'Membership Manager, sent to the dashboard: member list', $members, npmp_staff_login_redirect( admin_url( 'index.php' ), admin_url( 'index.php' ), $mm ) );
$draft = admin_url( 'post.php?post=5&action=edit' );
check( 'Membership Manager, following a link: kept', $draft, npmp_staff_login_redirect( $draft, $draft, $mm ) );
check( 'administrator: untouched', admin_url(), npmp_staff_login_redirect( admin_url(), '', $admin ) );
check( 'editor: untouched', admin_url(), npmp_staff_login_redirect( admin_url(), '', $editor ) );
check( 'failed login (WP_Error): untouched', 'x', npmp_staff_login_redirect( 'x', '', new stdClass() ) );
update_option( 'npmp_enabled_features', array( 'members' => false, 'donations' => true ) );
check( 'members module off: donations dashboard', admin_url( 'admin.php?page=npmp_donations_group' ), npmp_staff_login_redirect( admin_url(), '', $mm ) );
update_option( 'npmp_enabled_features', array( 'members' => false, 'donations' => false, 'newsletters' => false ) );
check( 'nothing enabled: profile', admin_url( 'profile.php' ), npmp_staff_login_redirect( admin_url(), '', $mm ) );
update_option( 'npmp_enabled_features', array( 'members' => true ) );

// ---------------------------------------------------------------------------
echo "\nImport sources\n";
require_once dirname( __DIR__ ) . '/includes/import/admin-import.php';
$GLOBALS['test_user'] = $mm;
foreach ( array( 'csv', 'xlsx', 'google_sheet' ) as $source ) {
	check( "Membership Manager may import $source", true, npmp_import_user_can_source( $source ) );
}
foreach ( array( 'mailchimp', 'constant_contact', '', 'bogus' ) as $source ) {
	check( "Membership Manager may not import '$source'", false, npmp_import_user_can_source( $source ) );
}
$GLOBALS['test_user'] = $admin;
check( 'administrator may import Mailchimp', true, npmp_import_user_can_source( 'mailchimp' ) );
$GLOBALS['test_user'] = $author;
check( 'author may not import CSV', false, npmp_import_user_can_source( 'csv' ) );
$GLOBALS['test_user'] = null;

// ---------------------------------------------------------------------------
echo "\nWhich capability each admin screen uses (source scan)\n";

/**
 * Split a call's argument list at top-level commas.
 *
 * @param array $tokens Tokens.
 * @param int   $i      Index of the opening parenthesis.
 * @return array Argument source strings.
 */
function test_call_args( $tokens, $i ) {
	$args  = array();
	$depth = 0;
	$cur   = '';
	for ( $n = count( $tokens ); $i < $n; $i++ ) {
		$t    = $tokens[ $i ];
		$text = is_array( $t ) ? $t[1] : $t;
		if ( '(' === $text || '[' === $text ) {
			$depth++;
			if ( 1 === $depth ) {
				continue;
			}
		} elseif ( ')' === $text || ']' === $text ) {
			$depth--;
			if ( 0 === $depth ) {
				$args[] = trim( $cur );
				return $args;
			}
		} elseif ( ',' === $text && 1 === $depth ) {
			$args[] = trim( $cur );
			$cur    = '';
			continue;
		}
		if ( ! is_array( $t ) || ! in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$cur .= $text;
		}
	}
	return $args;
}

/** Map of menu slug => capability expression, for every literal slug. */
function test_menu_caps( $root ) {
	$files = array( $root . '/nonprofit-manager.php' );
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
	foreach ( $it as $f ) {
		if ( '.php' === substr( $f->getFilename(), -4 ) ) {
			$files[] = $f->getPathname();
		}
	}
	$map = array();
	foreach ( $files as $file ) {
		$tokens = token_get_all( file_get_contents( $file ) );
		foreach ( $tokens as $i => $t ) {
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( $t[1], array( 'add_menu_page', 'add_submenu_page' ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				$j++;
			}
			if ( '(' !== $tokens[ $j ] ) {
				continue;
			}
			$args = test_call_args( $tokens, $j );
			$off  = 'add_menu_page' === $t[1] ? 0 : 1;
			$cap  = $args[ 2 + $off ] ?? '';
			$slug = $args[ 3 + $off ] ?? '';
			if ( preg_match( "/^'([^']+)'$/", $slug, $m ) ) {
				$map[ $m[1] ][] = $cap;
			}
		}
	}
	return array_map( 'array_unique', $map );
}

$caps = test_menu_caps( dirname( __DIR__ ) );

$staff_screens = array( 'npmp_membership', 'npmp_members', 'npmp_import', 'npmp_donations_group' );
foreach ( $staff_screens as $slug ) {
	check( "$slug uses npmp_staff_cap()", array( 'npmp_staff_cap()' ), array_values( $caps[ $slug ] ?? array() ) );
}
$newsletter_screens = array( 'npmp-newsletters', 'npmp_newsletter_templates', 'npmp_newsletter_archive', 'npmp_newsletter_reports' );
foreach ( $newsletter_screens as $slug ) {
	check( "$slug uses edit_npmp_newsletters", array( "'edit_npmp_newsletters'" ), array_values( $caps[ $slug ] ?? array() ) );
}
$settings_screens = array( 'npmp_main', 'npmp_general_settings', 'npmp_membership_forms', 'npmp_email_settings', 'npmp_newsletter_settings', 'npmp_donation_settings', 'npmp_payment_settings', 'npmp_setup_wizard', 'npmp_social_sharing', 'npmp_event_settings' );
foreach ( $settings_screens as $slug ) {
	check( "$slug stays manage_options", array( "'manage_options'" ), array_values( $caps[ $slug ] ?? array() ) );
}
$opened = array();
foreach ( $caps as $slug => $list ) {
	if ( in_array( 'npmp_staff_cap()', $list, true ) ) {
		$opened[] = $slug;
	}
}
sort( $opened );
$expected = $staff_screens;
sort( $expected );
check( 'no other screen is open to the staff capability', $expected, $opened );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
