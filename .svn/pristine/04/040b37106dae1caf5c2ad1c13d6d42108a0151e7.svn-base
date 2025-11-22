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
 * Render a settings section card
 *
 * @param string $title Section title.
 * @param string $content Section content (HTML allowed).
 * @param string $style Optional inline styles.
 */
function npmp_settings_card( $title, $content, $style = '' ) {
	$style_attr = $style ? ' style="' . esc_attr( $style ) . '"' : '';
	echo '<div class="card"' . $style_attr . '>';
	if ( $title ) {
		echo '<h2 class="title">' . esc_html( $title ) . '</h2>';
	}
	echo wp_kses_post( $content );
	echo '</div>';
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
 * Render error notice
 *
 * @param string $message Notice message.
 * @param bool   $dismissible Whether notice is dismissible.
 */
function npmp_admin_notice_error( $message, $dismissible = true ) {
	$class = 'notice notice-error' . ( $dismissible ? ' is-dismissible' : '' );
	echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Render info notice
 *
 * @param string $message Notice message.
 * @param bool   $dismissible Whether notice is dismissible.
 */
function npmp_admin_notice_info( $message, $dismissible = true ) {
	$class = 'notice notice-info' . ( $dismissible ? ' is-dismissible' : '' );
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
 * Sanitize and validate email address
 *
 * @param string $email Email to sanitize.
 * @return string|false Sanitized email or false if invalid.
 */
function npmp_sanitize_email( $email ) {
	$email = sanitize_email( $email );
	return is_email( $email ) ? $email : false;
}

/**
 * Sanitize array of text fields
 *
 * @param array $array Array to sanitize.
 * @return array Sanitized array.
 */
function npmp_sanitize_text_array( $array ) {
	if ( ! is_array( $array ) ) {
		return array();
	}
	return array_map( 'sanitize_text_field', $array );
}

/**
 * Render pro feature badge
 */
function npmp_pro_badge() {
	return '<span class="npmp-pro-badge" style="display: inline-block; background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-left: 5px; vertical-align: middle;">Pro</span>';
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
 * Get formatted date for display
 *
 * @param string $date Date string or timestamp.
 * @param string $format Date format (default: WordPress date format).
 * @return string Formatted date.
 */
function npmp_format_date( $date, $format = '' ) {
	if ( empty( $format ) ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	if ( is_numeric( $date ) ) {
		return date_i18n( $format, $date );
	}

	return date_i18n( $format, strtotime( $date ) );
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
		.npmp-pro-badge {
			display: inline-block;
			background: #2271b1;
			color: #fff;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
			margin-left: 5px;
			vertical-align: middle;
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
