<?php
/**
 * Admin helper functions for consistent UI and security
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render admin page header with consistent styling
 *
 * @param string $title Page title.
 * @param string $description Optional page description.
 * @param array  $actions Optional array of action buttons ['label' => 'url'].
 */
function npmp_admin_page_header( $title, $description = '', $actions = array() ) {
	echo '<div class="wrap npmp-admin-page">';
	echo '<h1>' . esc_html( $title );

	if ( ! empty( $actions ) ) {
		foreach ( $actions as $label => $url ) {
			echo ' <a href="' . esc_url( $url ) . '" class="page-title-action">' . esc_html( $label ) . '</a>';
		}
	}

	echo '</h1>';

	if ( ! empty( $description ) ) {
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}
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
 * Add consistent admin page styles
 */
function npmp_admin_styles() {
	if ( ! npmp_is_plugin_admin_page() ) {
		return;
	}
	?>
	<style>
		.npmp-admin-page .card {
			max-width: none;
			margin-bottom: 20px;
		}
		.npmp-admin-page .card h2.title {
			margin: 0 0 15px 0;
			font-size: 18px;
			font-weight: 600;
		}
		.npmp-admin-page .description {
			color: #646970;
			font-size: 13px;
		}
		.npmp-upsell-card {
			background-color: #f0f6fc;
			border-left: 4px solid #0073aa;
		}
		.npmp-upsell-card ul {
			list-style: disc;
			margin-left: 20px;
		}
	</style>
	<?php
}
add_action( 'admin_head', 'npmp_admin_styles' );
