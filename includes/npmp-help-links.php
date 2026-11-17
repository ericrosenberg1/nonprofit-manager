<?php
/**
 * Links from plugin screens to the help center at nonprofitmanager.app/help/.
 *
 * One map of screen => article. A screen whose title goes through
 * npmp_admin_page_title() gets a "Help" link beside the title, and every
 * mapped screen (the event and newsletter editors and lists too) gets a
 * Nonprofit Manager tab in WordPress's Help panel. Pro's screens are in the
 * same map, so Pro needs no code of its own. Article slugs live in the site
 * repo, wordpress/help/articles/<topic>/<slug>.md, and
 * tests/test-help-links.php fails on a slug that has no article there when
 * that folder is present.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The help center's address for one article, or the home page with no slug.
 *
 * @param string $slug Article slug, or '' for the help center home.
 * @return string
 */
function npmp_help_url( $slug = '' ) {
	$slug = sanitize_title( (string) $slug );
	$url  = 'https://nonprofitmanager.app/help/' . ( '' !== $slug ? $slug . '/' : '' );
	return add_query_arg(
		array(
			'utm_source'   => 'plugin',
			'utm_medium'   => 'wp-admin',
			'utm_campaign' => 'help',
		),
		$url
	);
}

/**
 * Screen => help article. Keys are an admin.php `page` value, or
 * `post_type:<type>` for a post type's list and editor screens, or
 * `taxonomy:<taxonomy>` for its terms screen.
 *
 * @return array<string,string>
 */
function npmp_help_links() {
	$links = array(
		// Free.
		'npmp_main'                         => 'admin-dashboard',
		'npmp_setup_wizard'                 => 'setup-wizard',
		'npmp_general_settings'             => 'general-settings',
		'npmp_members'                      => 'manage-contacts',
		'npmp_membership'                   => 'manage-contacts',
		'npmp_membership_forms'             => 'membership-levels',
		'npmp_import'                       => 'import-overview',
		'npmp_import_hub'                   => 'switch-plugins',
		'npmp_mailpoet_import'              => 'import-from-mailpoet',
		'npmp_tnp_import'                   => 'import-from-the-newsletter-plugin',
		'npmp_fcrm_import'                  => 'import-from-fluentcrm',
		'npmp_pmpro_import'                 => 'import-from-paid-memberships-pro',
		'npmp_um_import'                    => 'import-from-ultimate-member',
		'npmp_gift_import'                  => 'import-past-gifts',
		'npmp_gift_csv_import'              => 'import-past-gifts',
		'npmp_tec_import'                   => 'import-events',
		'npmp_event_import'                 => 'import-events',
		'npmp_donations_group'              => 'view-donations',
		'npmp_payment_settings'             => 'stripe-donations',
		'npmp_donation_settings'            => 'donation-form',
		'npmp_email_settings'               => 'email-delivery-settings',
		'npmp-newsletters'                  => 'send-a-newsletter',
		'npmp_newsletter_templates'         => 'newsletter-templates',
		'npmp_newsletter_archive'           => 'public-newsletter-archive',
		'npmp_newsletter_settings'          => 'unsubscribe-and-preferences',
		'npmp_newsletter_reports'           => 'send-a-newsletter',
		'npmp_scheduled_emails'             => 'scheduled-event-emails',
		'npmp-events'                       => 'create-an-event',
		'npmp_event_settings'               => 'event-calendar',
		'npmp_social_sharing'               => 'social-sharing-setup',
		'post_type:npmp_event'              => 'create-an-event',
		'post_type:npmp_newsletter'         => 'send-a-newsletter',
		'taxonomy:npmp_event_category'      => 'event-categories-and-tags',
		'taxonomy:npmp_event_tag'           => 'event-categories-and-tags',
		// Pro.
		'npmp_license'                      => 'activate-pro-license',
		'npmp_pro_setup_wizard'             => 'install-nonprofit-manager-pro',
		'npmp_custom_fields'                => 'custom-fields',
		'npmp_groups'                       => 'contact-groups',
		'npmp_segments'                     => 'contact-segments',
		'npmp_membership_dues'              => 'membership-dues',
		'npmp_recurring_donations'          => 'recurring-donations',
		'npmp_automations'                  => 'email-automations',
		'npmp_mailer'                       => 'nonprofit-mailer-connector',
	);

	/**
	 * Screen => help article slug.
	 *
	 * @param array<string,string> $links Map, see npmp_help_links().
	 */
	return (array) apply_filters( 'npmp_help_links', $links );
}

/**
 * The help article for the screen being drawn, or ''.
 *
 * @param WP_Screen|null $screen Screen, or null for the current one.
 * @return string
 */
function npmp_help_slug_for_screen( $screen = null ) {
	$links = npmp_help_links();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, picks a link.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( '' !== $page && isset( $links[ $page ] ) ) {
		return (string) $links[ $page ];
	}

	if ( null === $screen && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
	}
	if ( ! is_object( $screen ) ) {
		return '';
	}
	if ( ! empty( $screen->taxonomy ) && isset( $links[ 'taxonomy:' . $screen->taxonomy ] ) ) {
		return (string) $links[ 'taxonomy:' . $screen->taxonomy ];
	}
	if ( ! empty( $screen->post_type ) && isset( $links[ 'post_type:' . $screen->post_type ] ) ) {
		return (string) $links[ 'post_type:' . $screen->post_type ];
	}
	return '';
}

/**
 * The "Help" link printed beside a screen title. Empty when the screen has
 * no article.
 *
 * @return string HTML.
 */
function npmp_help_title_link() {
	$slug = npmp_help_slug_for_screen();
	if ( '' === $slug ) {
		return '';
	}
	return ' <a href="' . esc_url( npmp_help_url( $slug ) ) . '" class="page-title-action npmp-help-link" target="_blank" rel="noopener">'
		. esc_html__( 'Help', 'nonprofit-manager' )
		. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'nonprofit-manager' ) . '</span></a>';
}

/**
 * Add a Nonprofit Manager tab to WordPress's Help panel on every mapped screen.
 *
 * @param WP_Screen $screen Current screen.
 * @return void
 */
function npmp_help_add_tab( $screen ) {
	if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
		return;
	}
	$slug = npmp_help_slug_for_screen( $screen );
	if ( '' === $slug ) {
		return;
	}
	$screen->add_help_tab(
		array(
			'id'      => 'npmp-help',
			'title'   => __( 'Nonprofit Manager', 'nonprofit-manager' ),
			'content' => '<p>' . esc_html__( 'Step-by-step instructions for this screen are in the Nonprofit Manager help center.', 'nonprofit-manager' ) . '</p>'
				. '<p><a href="' . esc_url( npmp_help_url( $slug ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Read the help article for this screen', 'nonprofit-manager' ) . '</a></p>',
		)
	);
	$screen->set_help_sidebar(
		'<p><strong>' . esc_html__( 'More help', 'nonprofit-manager' ) . '</strong></p>'
		. '<p><a href="' . esc_url( npmp_help_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help center', 'nonprofit-manager' ) . '</a></p>'
		. '<p><a href="' . esc_url( npmp_help_url( 'shortcodes' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Shortcodes', 'nonprofit-manager' ) . '</a></p>'
	);
}
add_action( 'current_screen', 'npmp_help_add_tab' );
