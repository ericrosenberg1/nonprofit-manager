<?php
/**
 * Membership Manager role.
 *
 * Lets a nonprofit hand a volunteer (membership chair, treasurer) a login that
 * reaches the day-to-day Nonprofit Manager screens and nothing else in
 * WordPress: the member list, member import, the donations dashboard, and
 * newsletters. Every settings screen, the setup wizard, API keys and payment
 * credentials stay on manage_options.
 *
 * The role holds exactly two capabilities, `read` (to sign in to wp-admin) and
 * npmp_staff_cap(). Newsletter editing is granted through the newsletter post
 * types' own capabilities (see npmp_newsletter_cap_map()), never edit_posts,
 * so a Membership Manager can't touch posts, pages or media.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_STAFF_CAP' ) ) {
	define( 'NPMP_STAFF_CAP', 'npmp_manage_members' );
}
if ( ! defined( 'NPMP_STAFF_ROLE' ) ) {
	define( 'NPMP_STAFF_ROLE', 'npmp_membership_manager' );
}
if ( ! defined( 'NPMP_ROLES_VERSION' ) ) {
	// Bump when the role or the administrator grant changes, so existing sites
	// pick it up on their next page load without being re-activated.
	define( 'NPMP_ROLES_VERSION', '1' );
}

/**
 * The capability every day-to-day Nonprofit Manager screen checks.
 *
 * @return string
 */
function npmp_staff_cap() {
	return NPMP_STAFF_CAP;
}

/**
 * Capabilities the Membership Manager role holds. Nothing else from WordPress.
 *
 * @return array<string,bool>
 */
function npmp_staff_role_caps() {
	return array(
		'read'         => true,
		NPMP_STAFF_CAP => true,
	);
}

/**
 * Create the role and give administrators the capability.
 *
 * Runs on activation and from npmp_maybe_upgrade_roles(). Idempotent: an
 * existing role keeps any capability a site owner added to it on purpose, it
 * only gains the ones it's missing.
 *
 * @return void
 */
function npmp_install_roles() {
	$role = get_role( NPMP_STAFF_ROLE );
	if ( ! $role ) {
		add_role( NPMP_STAFF_ROLE, 'Membership Manager', npmp_staff_role_caps() );
	} else {
		foreach ( array_keys( npmp_staff_role_caps() ) as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	$admin = get_role( 'administrator' );
	if ( $admin && empty( $admin->capabilities[ NPMP_STAFF_CAP ] ) ) {
		$admin->add_cap( NPMP_STAFF_CAP );
	}

	update_option( 'npmp_roles_version', NPMP_ROLES_VERSION );
}

/**
 * Install the role on sites that updated without re-activating the plugin.
 *
 * @return void
 */
function npmp_maybe_upgrade_roles() {
	if ( NPMP_ROLES_VERSION !== (string) get_option( 'npmp_roles_version', '' ) ) {
		npmp_install_roles();
	}
}
add_action( 'init', 'npmp_maybe_upgrade_roles', 5 );

/**
 * Newsletter primitive capabilities, each mapped to the post capability that
 * granted the same thing before newsletters had their own capability type.
 *
 * Users who could edit newsletters before still can (an Author's edit_posts
 * still means edit_npmp_newsletters), and the staff capability adds all of
 * them without adding edit_posts.
 *
 * @return array<string,string>
 */
function npmp_newsletter_cap_map() {
	return array(
		'edit_npmp_newsletters'             => 'edit_posts',
		'edit_others_npmp_newsletters'      => 'edit_others_posts',
		'edit_private_npmp_newsletters'     => 'edit_private_posts',
		'edit_published_npmp_newsletters'   => 'edit_published_posts',
		'publish_npmp_newsletters'          => 'publish_posts',
		'read_private_npmp_newsletters'     => 'read_private_posts',
		'delete_npmp_newsletters'           => 'delete_posts',
		'delete_others_npmp_newsletters'    => 'delete_others_posts',
		'delete_private_npmp_newsletters'   => 'delete_private_posts',
		'delete_published_npmp_newsletters' => 'delete_published_posts',
	);
}

/**
 * Resolve the staff and newsletter capabilities for a user.
 *
 * - manage_options always implies the staff capability, so an administrator
 *   whose role was reset by a role-editor plugin still reaches every screen
 *   they reached before the screens moved to npmp_staff_cap().
 * - Newsletter capabilities follow the matching post capability, or the staff
 *   capability.
 *
 * @param array<string,bool> $allcaps All capabilities the user has.
 * @return array<string,bool>
 */
function npmp_filter_staff_caps( $allcaps ) {
	if ( ! empty( $allcaps['manage_options'] ) ) {
		$allcaps[ NPMP_STAFF_CAP ] = true;
	}

	$is_staff = ! empty( $allcaps[ NPMP_STAFF_CAP ] );
	foreach ( npmp_newsletter_cap_map() as $newsletter_cap => $post_cap ) {
		if ( $is_staff || ! empty( $allcaps[ $post_cap ] ) ) {
			$allcaps[ $newsletter_cap ] = true;
		}
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'npmp_filter_staff_caps', 10, 1 );

/**
 * Is this user a Membership Manager and nothing more?
 *
 * The decluttering and login redirect apply only to them. Someone who also
 * holds an editor or administrator role keeps the normal admin.
 *
 * @param WP_User|null $user User, or null for the current user.
 * @return bool
 */
function npmp_is_limited_staff( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	}
	if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
		return false;
	}
	if ( ! in_array( NPMP_STAFF_ROLE, (array) $user->roles, true ) ) {
		return false;
	}
	return ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'edit_posts' );
}

/**
 * Where a Membership Manager starts: the member list, or the first enabled
 * screen they can use when the members module is off.
 *
 * @return string
 */
function npmp_staff_home_url() {
	$features = get_option( 'npmp_enabled_features', array() );
	$features = is_array( $features ) ? $features : array();

	if ( ! isset( $features['members'] ) || ! empty( $features['members'] ) ) {
		return admin_url( 'admin.php?page=npmp_members' );
	}
	if ( ! isset( $features['donations'] ) || ! empty( $features['donations'] ) ) {
		return admin_url( 'admin.php?page=npmp_donations_group' );
	}
	if ( ! empty( $features['newsletters'] ) ) {
		return admin_url( 'admin.php?page=npmp-newsletters' );
	}
	return admin_url( 'profile.php' );
}

/**
 * Send a Membership Manager to the member list after login.
 *
 * Only replaces the generic destination (no redirect asked for, or the
 * dashboard). A link they followed to a specific screen, such as a newsletter
 * draft, still wins.
 *
 * @param string           $redirect_to           Destination WordPress chose.
 * @param string           $requested_redirect_to Destination the login form asked for.
 * @param WP_User|WP_Error $user                  User that logged in.
 * @return string
 */
function npmp_staff_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! ( $user instanceof WP_User ) || ! npmp_is_limited_staff( $user ) ) {
		return $redirect_to;
	}

	$requested = untrailingslashit( (string) $requested_redirect_to );
	$generic   = array(
		'',
		untrailingslashit( admin_url() ),
		untrailingslashit( admin_url( 'index.php' ) ),
	);
	if ( in_array( $requested, $generic, true ) ) {
		return npmp_staff_home_url();
	}

	return $redirect_to;
}
add_filter( 'login_redirect', 'npmp_staff_login_redirect', 10, 3 );

/**
 * Drop the core dashboard widgets a Membership Manager has no use for. The
 * Nonprofit Manager summary and Quick Add Member widgets stay.
 *
 * @return void
 */
function npmp_staff_declutter_dashboard() {
	if ( ! npmp_is_limited_staff() ) {
		return;
	}
	remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
	remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
	remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_action( 'welcome_panel', 'wp_welcome_panel' );
}
add_action( 'wp_dashboard_setup', 'npmp_staff_declutter_dashboard', 999 );

/**
 * Trim the admin bar to what a Membership Manager can use.
 *
 * @param WP_Admin_Bar $admin_bar Admin bar.
 * @return void
 */
function npmp_staff_declutter_admin_bar( $admin_bar ) {
	if ( ! npmp_is_limited_staff() ) {
		return;
	}
	foreach ( array( 'wp-logo', 'new-content', 'comments', 'updates', 'customize' ) as $node ) {
		$admin_bar->remove_node( $node );
	}
}
add_action( 'admin_bar_menu', 'npmp_staff_declutter_admin_bar', 999 );

/**
 * Preselect the Membership Manager role on Users > Add New when an admin
 * follows the "Give a volunteer access" link. Only changes which option the
 * Role dropdown starts on. The stored default role is untouched, and the
 * submitted form decides the role as usual.
 *
 * @return void
 */
function npmp_staff_preselect_role_on_user_new() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display hint, changes nothing on its own.
	$wanted = isset( $_GET['npmp_role'] ) ? sanitize_key( wp_unslash( $_GET['npmp_role'] ) ) : '';
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( NPMP_STAFF_ROLE !== $wanted || 'GET' !== $method || ! get_role( NPMP_STAFF_ROLE ) ) {
		return;
	}
	if ( ! current_user_can( 'create_users' ) || ! current_user_can( 'promote_users' ) ) {
		return;
	}

	add_filter(
		'pre_option_default_role',
		static function () {
			return NPMP_STAFF_ROLE;
		}
	);
}
add_action( 'load-user-new.php', 'npmp_staff_preselect_role_on_user_new' );

/**
 * "Give a volunteer access" panel, shown to administrators on the member list.
 *
 * @return void
 */
function npmp_render_staff_access_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="card npmp-staff-access" style="max-width:820px;margin-top:24px;">';
	echo '<h2 class="title">' . esc_html__( 'Give a volunteer access', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Add your membership chair or treasurer as a WordPress user with the Membership Manager role. They can work with members, donations and newsletters, and can\'t reach settings, payment keys, pages, plugins or anything else in WordPress.', 'nonprofit-manager' ) . '</p>';

	if ( current_user_can( 'create_users' ) && current_user_can( 'promote_users' ) ) {
		$url = add_query_arg( 'npmp_role', NPMP_STAFF_ROLE, admin_url( 'user-new.php' ) );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Add a Membership Manager', 'nonprofit-manager' ) . '</a></p>';
	} else {
		echo '<p>' . esc_html__( 'Ask a site administrator to add the user under Users > Add New and choose the Membership Manager role.', 'nonprofit-manager' ) . '</p>';
	}

	echo '</div>';
}
