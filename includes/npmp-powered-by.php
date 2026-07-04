<?php
/**
 * File path: includes/npmp-powered-by.php
 *
 * "Powered by Nonprofit Manager" attribution.
 *
 * Renders a small attribution on public-facing output (donation forms and
 * newsletter footers). Off by default and opt-in, per WordPress.org
 * guidelines: credit links must default to hidden and require the site owner
 * to turn them on (under General Settings). Pro or a theme can override the
 * state via the npmp_show_powered_by filter.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_powered_by_enabled' ) ) {
	/**
	 * Whether the attribution should render in the given context.
	 *
	 * @param string $context Render context ('form' or 'email').
	 * @return bool
	 */
	function npmp_powered_by_enabled( $context = '' ) {
		// Opt-in and off by default. WordPress.org requires credit links to
		// default to hidden, so the site owner turns this on under General
		// Settings (npmp_powered_by_optin).
		$enabled = (bool) get_option( 'npmp_powered_by_optin', false );

		/**
		 * Toggle the "Powered by Nonprofit Manager" attribution.
		 *
		 * Defaults to the site's opt-in setting. Pro or a theme can hook this
		 * to force it on or off.
		 *
		 * @param bool   $enabled Current opt-in state.
		 * @param string $context Render context ('form' or 'email').
		 */
		return (bool) apply_filters( 'npmp_show_powered_by', $enabled, $context );
	}
}

if ( ! function_exists( 'npmp_powered_by_url' ) ) {
	/**
	 * Destination for the attribution link, tagged per context for analytics.
	 *
	 * @param string $context Render context ('form' or 'email').
	 * @return string
	 */
	function npmp_powered_by_url( $context = '' ) {
		$medium = ( 'email' === $context ) ? 'newsletter' : 'donation_form';
		$url    = add_query_arg(
			array(
				'utm_source'   => 'powered_by',
				'utm_medium'   => $medium,
				'utm_campaign' => 'free_plugin',
			),
			'https://nonprofitmanager.ericrosenberg.com/'
		);

		/**
		 * Filter the attribution link destination.
		 *
		 * @param string $url     Default sales-site URL with UTM tags.
		 * @param string $context Render context ('form' or 'email').
		 */
		return apply_filters( 'npmp_powered_by_url', $url, $context );
	}
}

if ( ! function_exists( 'npmp_powered_by_html' ) ) {
	/**
	 * Attribution markup for a context, or '' when disabled.
	 *
	 * The 'email' variant is fully inline-styled because email clients strip
	 * external and embedded CSS. The 'form' variant carries a class plus a
	 * light inline fallback so it looks right without the form stylesheet.
	 *
	 * @param string $context 'form' or 'email'.
	 * @return string
	 */
	function npmp_powered_by_html( $context = 'form' ) {
		if ( ! npmp_powered_by_enabled( $context ) ) {
			return '';
		}

		$url   = esc_url( npmp_powered_by_url( $context ) );
		$label = esc_html__( 'Nonprofit Manager', 'nonprofit-manager' );

		if ( 'email' === $context ) {
			$link = '<a href="' . $url . '" style="color:#8a8a8a;text-decoration:underline;" target="_blank" rel="noopener">' . $label . '</a>';
			/* translators: %s: linked plugin name. */
			return '<p style="margin:16px 0 0;padding:0;font-size:12px;line-height:1.5;color:#8a8a8a;text-align:center;">'
				. sprintf( esc_html__( 'Powered by %s', 'nonprofit-manager' ), $link )
				. '</p>';
		}

		$link = '<a href="' . $url . '" target="_blank" rel="noopener">' . $label . '</a>';
		/* translators: %s: linked plugin name. */
		return '<p class="npmp-powered-by" style="margin:12px 0 0;font-size:12px;color:#8a8a8a;text-align:center;">'
			. sprintf( esc_html__( 'Powered by %s', 'nonprofit-manager' ), $link )
			. '</p>';
	}
}
