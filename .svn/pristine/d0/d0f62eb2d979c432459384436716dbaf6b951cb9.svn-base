<?php
/**
 * File path: includes/npmp-version.php
 *
 * Version management for Nonprofit Manager (Free vs Pro)
 */
defined( 'ABSPATH' ) || exit;

/**
 * Check if Nonprofit Manager Pro is installed and active
 *
 * @return bool True if Pro version is active
 */
function npmp_is_pro() {
	return defined( 'NPMP_PRO_VERSION' );
}

/**
 * Get the current version string
 *
 * @return string Version identifier ('free' or 'pro')
 */
function npmp_get_version() {
	return npmp_is_pro() ? 'pro' : 'free';
}

/**
 * Check if a feature is available in the current version
 *
 * @param string $feature Feature identifier
 * @return bool True if feature is available
 */
function npmp_feature_available( $feature ) {
	$pro_features = array(
		'recurring_donations',
		'custom_fields',
		'email_automation',
		'advanced_segmentation',
		'priority_support',
	);

	if ( in_array( $feature, $pro_features, true ) ) {
		return npmp_is_pro();
	}

	return true; // All other features available in free version
}

/**
 * Get upgrade URL
 *
 * @return string URL to upgrade page
 */
function npmp_get_upgrade_url() {
	return 'https://ericrosenberg.com/nonprofit-manager/';
}

/**
 * Display upgrade notice for pro features
 *
 * @param string $feature_name Name of the feature
 */
function npmp_show_upgrade_notice( $feature_name = '' ) {
	$message = $feature_name
		? sprintf(
			/* translators: %s: feature name */
			__( '%s is a Pro feature. Upgrade to Nonprofit Manager Pro to unlock this feature.', 'nonprofit-manager' ),
			$feature_name
		)
		: __( 'This is a Pro feature. Upgrade to Nonprofit Manager Pro to unlock additional features.', 'nonprofit-manager' );

	?>
	<div class="notice notice-info">
		<p>
			<?php echo esc_html( $message ); ?>
			<a href="<?php echo esc_url( npmp_get_upgrade_url() ); ?>" target="_blank">
				<?php esc_html_e( 'Learn More', 'nonprofit-manager' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * Get plugin version number
 *
 * @return string Version number
 */
function npmp_get_version_number() {
	if ( npmp_is_pro() && defined( 'NPMP_PRO_VERSION' ) ) {
		return NPMP_PRO_VERSION;
	}

	// Get version from free plugin header
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file = dirname( __DIR__ ) . '/nonprofit-manager.php';
	if ( file_exists( $plugin_file ) ) {
		$plugin_data = get_plugin_data( $plugin_file, false, false );
		return $plugin_data['Version'] ?? '1.0.0';
	}

	return '1.0.0';
}

/**
 * Display plugin version in admin footer
 *
 * @param string $text Footer text
 * @return string Modified footer text
 */
function npmp_admin_footer_version( $text ) {
	// Only show on plugin pages
	$screen = get_current_screen();
	if ( ! $screen ) {
		return $text;
	}

	// Check if this is a plugin page
	$plugin_pages = array(
		'toplevel_page_npmp_main',
		'nonprofit-manager_page_npmp_general_settings',
		'toplevel_page_npmp_membership',
		'membership_page_npmp_members',
		'membership_page_npmp_membership_forms',
		'nonprofit-manager_page_npmp_email_settings',
		'toplevel_page_npmp-newsletters',
		'email-newsletters_page_npmp_newsletter_templates',
		'email-newsletters_page_npmp_newsletter_archive',
		'email-newsletters_page_npmp_newsletter_reports',
		'email-newsletters_page_npmp_newsletter_settings',
		'toplevel_page_npmp_donations_group',
		'donations_page_npmp_donation_settings',
		'donations_page_npmp_payment_settings',
		'toplevel_page_npmp_calendar',
		'calendar_page_npmp_calendar_settings',
	);

	// Also check if page slug starts with 'npmp'
	$is_plugin_page = in_array( $screen->id, $plugin_pages, true ) || strpos( $screen->id, 'npmp' ) !== false;

	if ( ! $is_plugin_page ) {
		return $text;
	}

	$version_text = sprintf(
		/* translators: %s: version number */
		__( 'Nonprofit Manager %s', 'nonprofit-manager' ),
		npmp_get_version_number()
	);

	return $text . ' | ' . $version_text;
}
add_filter( 'admin_footer_text', 'npmp_admin_footer_version' );
