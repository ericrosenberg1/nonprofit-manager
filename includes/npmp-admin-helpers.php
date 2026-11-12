<?php
/**
 * Admin helper functions for consistent UI and security
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Print a screen title the way core lays it out in WordPress 7.1.
 *
 * An h1.wp-heading-inline, then each page-title-action link, then
 * hr.wp-header-end. Core's common.js moves admin notices to just after that
 * hr, so a screen without it gets notices above its title.
 *
 * @param string $title   Screen title, plain text.
 * @param array  $actions Optional ['Label' => 'url'] links shown beside the title.
 * @param string $badge   Optional short badge text shown inside the h1 (e.g. "Pro").
 * @return void
 */
function npmp_admin_page_title( $title, $actions = array(), $badge = '' ) {
	echo '<h1 class="wp-heading-inline">' . esc_html( $title );
	if ( '' !== (string) $badge ) {
		echo ' <span class="npmp-pro-badge">' . esc_html( $badge ) . '</span>';
	}
	echo '</h1>';

	if ( is_array( $actions ) ) {
		foreach ( $actions as $label => $url ) {
			echo ' <a href="' . esc_url( $url ) . '" class="page-title-action">' . esc_html( $label ) . '</a>';
		}
	}

	echo '<hr class="wp-header-end">';
}

/**
 * Open a plugin admin screen: the wrap and the title.
 *
 * Pro calls this with one, two, or three arguments, so the first three keep
 * their meaning. Close the screen with npmp_admin_page_footer().
 *
 * @param string $title       Page title.
 * @param string $description Optional page description.
 * @param array  $actions     Optional array of action buttons ['label' => 'url'].
 * @param string $badge       Optional badge text inside the h1.
 */
function npmp_admin_page_header( $title, $description = '', $actions = array(), $badge = '' ) {
	echo '<div class="wrap npmp-admin-page">';
	npmp_admin_page_title( $title, $actions, $badge );

	if ( ! empty( $description ) ) {
		echo '<p class="description npmp-page-description">' . esc_html( $description ) . '</p>';
	}
}

/**
 * The name of each feature on the Overview screen and in the setup wizard.
 *
 * One list, so the two screens can't drift apart again.
 *
 * @return array feature key => name.
 */
function npmp_feature_names() {
	return array(
		'members'     => __( 'Member Tracking', 'nonprofit-manager' ),
		'newsletters' => __( 'Email Newsletters', 'nonprofit-manager' ),
		'donations'   => function_exists( 'npmp_label' ) ? npmp_label( 'donations' ) : __( 'Donations', 'nonprofit-manager' ),
		'calendar'    => __( 'Event Calendar', 'nonprofit-manager' ),
		'social'      => __( 'Social Sharing', 'nonprofit-manager' ),
	);
}

/**
 * Render admin page footer
 */
function npmp_admin_page_footer() {
	echo '</div><!-- .wrap -->';
}

/**
 * Render success notice
 *
 * @param string $message Notice message.
 * @param bool   $dismissible Whether notice is dismissible.
 */
function npmp_admin_notice_success( $message, $dismissible = true ) {
	$class = 'notice notice-success' . ( $dismissible ? ' is-dismissible' : '' );
	echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Verify admin page access
 *
 * @param string $capability Required capability (default: 'manage_options').
 */
function npmp_verify_admin_access( $capability = 'manage_options' ) {
	if ( ! current_user_can( $capability ) ) {
		wp_die(
			esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ),
			esc_html__( 'Permission Denied', 'nonprofit-manager' ),
			array( 'response' => 403 )
		);
	}
}

/**
 * Check if current screen is a plugin admin page
 *
 * @return bool True if on plugin admin page.
 */
function npmp_is_plugin_admin_page() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return false;
	}
	return strpos( $screen->id, 'npmp' ) !== false;
}

/**
 * Load the shared admin stylesheet on the plugin's screens and the Dashboard
 * (its widgets use the same cards and badges).
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function npmp_admin_enqueue_styles( $hook_suffix = '' ) {
	if ( 'index.php' !== $hook_suffix && ! npmp_is_plugin_admin_page() ) {
		return;
	}
	$file = dirname( __DIR__ ) . '/assets/css/npmp-admin.css';
	wp_enqueue_style(
		'npmp-admin',
		plugins_url( 'assets/css/npmp-admin.css', dirname( __DIR__ ) . '/nonprofit-manager.php' ),
		array(),
		file_exists( $file ) ? (string) filemtime( $file ) : '1'
	);
}
add_action( 'admin_enqueue_scripts', 'npmp_admin_enqueue_styles' );
