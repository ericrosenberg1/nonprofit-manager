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
 * One sentence naming the mismatch, for a notice or a Site Health row.
 *
 * @return string Empty when the versions agree.
 */
function npmp_version_mismatch_message() {
	$status = npmp_version_status();
	if ( ! empty( $status['matched'] ) ) {
		return '';
	}

	return sprintf(
		/* translators: 1: free plugin version, 2: Pro version. */
		__( 'Nonprofit Manager is on %1$s and Nonprofit Manager Pro is on %2$s.', 'nonprofit-manager' ),
		$status['free'],
		$status['pro']
	);
}

/**
 * The one click that brings free and Pro back to the same version, or null.
 *
 * Free and Pro go live together (since 2026.11.6), and the licence server hands
 * Pro the release that matches the free version a site has or is being offered,
 * so a mismatch is short and both plugins keep running through it. Until
 * 2026.11.6 the notice said "some features may not work as intended" and
 * pointed at the Plugins screen, which offered nothing while wordpress.org held
 * the free release. Now the notice appears only when there's something to
 * click:
 *   - the plugin that's behind has an update waiting: "Update ... to match",
 *     straight to WordPress's own one-plugin updater, or
 *   - Pro is behind with no update listed yet: "Check for the Pro update",
 *     which re-asks every update source now.
 * Free behind with nothing offered means wordpress.org is still holding the
 * release. Both plugins work then, so nothing shows.
 *
 * @return array|null { label: string, url: string } or null.
 */
function npmp_version_match_action() {
	$status = npmp_version_status();
	if ( ! empty( $status['matched'] ) || empty( $status['pro_active'] ) ) {
		return null;
	}

	$free_file = 'nonprofit-manager/nonprofit-manager.php';
	$pro_file  = defined( 'NPMP_PRO_FILE' ) ? plugin_basename( NPMP_PRO_FILE ) : 'nonprofit-manager-pro/nonprofit-manager-pro.php';
	$pro_behind = ( 'pro' === $status['behind'] );
	$file       = $pro_behind ? $pro_file : $free_file;
	$target     = $pro_behind ? $status['free'] : $status['pro'];

	$updates = get_site_transient( 'update_plugins' );
	$offered = ( is_object( $updates ) && isset( $updates->response[ $file ]->new_version ) )
		? (string) $updates->response[ $file ]->new_version
		: '';

	if ( '' !== $offered && version_compare( $offered, $target, '>=' ) ) {
		return array(
			'label' => $pro_behind
				? __( 'Update Pro to match', 'nonprofit-manager' )
				: __( 'Update Nonprofit Manager to match', 'nonprofit-manager' ),
			'url'   => wp_nonce_url(
				self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ),
				'upgrade-plugin_' . $file
			),
		);
	}

	if ( $pro_behind ) {
		return array(
			'label' => __( 'Check for the Pro update', 'nonprofit-manager' ),
			'url'   => wp_nonce_url( self_admin_url( 'update-core.php?force-check=1' ), 'upgrade-core' ),
		);
	}

	return null;
}

function npmp_version_lockstep_notice() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$action = npmp_version_match_action();
	if ( null === $action ) {
		return;
	}

	// Below Pro's minimum, Pro has switched its features off and says so in its
	// own notice. Otherwise both plugins run every feature.
	$pro_running = ! function_exists( 'npmp_pro_free_version_supported' ) || npmp_pro_free_version_supported();
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'Nonprofit Manager', 'nonprofit-manager' ); ?>:</strong>
			<?php echo esc_html( npmp_version_mismatch_message() ); ?>
			<?php if ( $pro_running ) : ?>
				<?php esc_html_e( 'Both keep working in the meantime.', 'nonprofit-manager' ); ?>
			<?php endif; ?>
			<a class="button button-small" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
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
		$action                = npmp_version_match_action();
		$pro_running           = ! function_exists( 'npmp_pro_free_version_supported' ) || npmp_pro_free_version_supported();
		$result['status']      = $pro_running ? 'recommended' : 'critical';
		$result['label']       = __( 'Nonprofit Manager and Pro are on different versions', 'nonprofit-manager' );
		$result['description'] = '<p>' . esc_html( npmp_version_mismatch_message() ) . ' '
			. ( $pro_running
				? esc_html__( 'Both keep working until you update the one that is behind.', 'nonprofit-manager' )
				: esc_html__( 'Pro features are off until Nonprofit Manager is updated.', 'nonprofit-manager' ) )
			. '</p>';
		if ( null !== $action ) {
			$result['actions'] = '<a href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
		}
	}

	return $result;
}

/**
 * The pricing page, tagged with the screen that sent the visitor.
 *
 * Every "Upgrade to Pro" link in the plugin goes through here so GA4 can say
 * which screen sells: utm_source and utm_medium are fixed, utm_campaign names
 * the screen (payment_gateways, membership_dues, automations, ...). Callers
 * that pass nothing land in the "general" campaign, so older call sites keep
 * working.
 *
 * @param string $campaign Screen or gate the link sits on, lowercase letters,
 *                         digits, underscores and hyphens. Anything else is
 *                         stripped, and an empty result falls back to general.
 * @return string URL to the pricing page.
 */
function npmp_get_upgrade_url( $campaign = 'general' ) {
	$campaign = strtolower( trim( (string) $campaign ) );
	$campaign = (string) preg_replace( '/[^a-z0-9_-]/', '', $campaign );
	if ( '' === $campaign ) {
		$campaign = 'general';
	}

	return 'https://nonprofitmanager.app/pricing?utm_source=plugin&utm_medium=upsell&utm_campaign=' . rawurlencode( $campaign );
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
