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
 * The free plugin's own version, read from its header.
 *
 * @return string Version string, or '' if it could not be read.
 */
function npmp_get_free_version() {
	static $version = null;
	if ( null !== $version ) {
		return $version;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$main = dirname( __DIR__ ) . '/nonprofit-manager.php';
	$data = file_exists( $main ) ? get_plugin_data( $main, false, false ) : array();
	$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

	return $version;
}

/**
 * Free and Pro ship as one product on one version number. Pro calls into the
 * free plugin's classes and helpers, so a site running two different versions
 * is running combinations that were never built or tested together: at best a
 * feature misbehaves, at worst Pro calls something the installed free plugin
 * does not have.
 *
 * This is the shared answer to "are the two halves in step?", used by the
 * admin notices in both plugins, by Pro's load-time compatibility gate, and by
 * the Site Health check.
 *
 * @return array {
 *     @type bool   $pro_active Whether Pro is present at all.
 *     @type string $free       Free version.
 *     @type string $pro        Pro version, '' when Pro is inactive.
 *     @type bool   $matched    True when the two agree (or Pro is absent).
 *     @type string $behind     'pro', 'free', or '' when matched/not applicable.
 * }
 */
function npmp_version_status() {
	$free = npmp_get_free_version();
	$pro  = defined( 'NPMP_PRO_VERSION' ) ? (string) NPMP_PRO_VERSION : '';

	if ( '' === $pro ) {
		return array(
			'pro_active' => false,
			'free'       => $free,
			'pro'        => '',
			'matched'    => true,
			'behind'     => '',
		);
	}

	$compare = ( '' !== $free ) ? version_compare( $pro, $free ) : 0;

	return array(
		'pro_active' => true,
		'free'       => $free,
		'pro'        => $pro,
		'matched'    => ( 0 === $compare ),
		'behind'     => ( 0 === $compare ) ? '' : ( $compare < 0 ? 'pro' : 'free' ),
	);
}

/**
 * Whether the two plugins are on the same version. True when Pro is not
 * installed, since there is nothing to keep in step.
 *
 * @return bool
 */
function npmp_versions_in_lockstep() {
	$status = npmp_version_status();
	return ! empty( $status['matched'] );
}

/**
 * One sentence naming the mismatch, for a notice or a Site Health row.
 *
 * @return string Empty when the versions agree.
 */
function npmp_version_mismatch_message() {
	$status = npmp_version_status();
	if ( ! empty( $status['matched'] ) ) {
		return '';
	}

	if ( 'pro' === $status['behind'] ) {
		return sprintf(
			/* translators: 1: Pro version, 2: free plugin version. */
			__( 'Nonprofit Manager Pro (%1$s) is older than Nonprofit Manager (%2$s). Update Pro so both are on the same version.', 'nonprofit-manager' ),
			$status['pro'],
			$status['free']
		);
	}

	return sprintf(
		/* translators: 1: free plugin version, 2: Pro version. */
		__( 'Nonprofit Manager (%1$s) is older than Nonprofit Manager Pro (%2$s). Update Nonprofit Manager so both are on the same version.', 'nonprofit-manager' ),
		$status['free'],
		$status['pro']
	);
}

/**
 * Warn on every admin page while the two halves disagree.
 *
 * Not dismissible on purpose: the site is running an untested combination
 * until it is fixed, and a dismissed notice would hide that indefinitely.
 *
 * @return void
 */
function npmp_version_lockstep_notice() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$message = npmp_version_mismatch_message();
	if ( '' === $message ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Nonprofit Manager', 'nonprofit-manager' ); ?>:</strong>
			<?php echo esc_html( $message ); ?>
			<?php esc_html_e( 'Until then, some features may not work as intended.', 'nonprofit-manager' ); ?>
			<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
				<?php esc_html_e( 'Go to Plugins', 'nonprofit-manager' ); ?>
			</a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'npmp_version_lockstep_notice' );
add_action( 'network_admin_notices', 'npmp_version_lockstep_notice' );

/**
 * Surface the same check in Site Health, so it shows up in a support export
 * and for anyone who checks there before the admin notices.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function npmp_register_lockstep_health_test( $tests ) {
	$tests['direct']['npmp_lockstep'] = array(
		'label' => __( 'Nonprofit Manager plugin versions', 'nonprofit-manager' ),
		'test'  => 'npmp_lockstep_health_test',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'npmp_register_lockstep_health_test' );

/**
 * Site Health test body.
 *
 * @return array
 */
function npmp_lockstep_health_test() {
	$status = npmp_version_status();

	$result = array(
		'label'       => __( 'Nonprofit Manager and Pro are on the same version', 'nonprofit-manager' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Nonprofit Manager', 'nonprofit-manager' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Both halves of the plugin are in step.', 'nonprofit-manager' ) . '</p>',
		'test'        => 'npmp_lockstep',
	);

	if ( empty( $status['pro_active'] ) ) {
		$result['label']       = __( 'Nonprofit Manager Pro is not installed', 'nonprofit-manager' );
		$result['description'] = '<p>' . esc_html__( 'Only the free plugin is active, so there is nothing to keep in step.', 'nonprofit-manager' ) . '</p>';
		return $result;
	}

	if ( empty( $status['matched'] ) ) {
		$result['status']      = 'critical';
		$result['label']       = __( 'Nonprofit Manager and Pro are on different versions', 'nonprofit-manager' );
		$result['description'] = '<p>' . esc_html( npmp_version_mismatch_message() ) . '</p>';
	}

	return $result;
}

/**
 * Get upgrade URL
 *
 * @return string URL to upgrade page
 */
function npmp_get_upgrade_url() {
	return 'https://nonprofitmanager.app/pricing';
}

/**
 * Pro's starting price, for in-plugin upsell copy.
 *
 * One place to update when pricing changes, instead of a string baked into
 * each upsell screen. Matches the Single Site annual price on the pricing
 * page (nonprofit-manager-site/src/pages/pricing.astro).
 *
 * @return string Human-readable price, e.g. "$47 a year".
 */
function npmp_get_pro_starting_price() {
	return __( '$47 a year', 'nonprofit-manager' );
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
