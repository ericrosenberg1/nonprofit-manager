<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_can_spam_shortcode' ) ) {
	/**
	 * Render the CAN-SPAM footer content.
	 *
	 * @return string
	 */
	function npmp_can_spam_shortcode() {
		$footer = get_option( 'npmp_newsletter_can_spam_footer' );

		$replacements = array(
			'[organization]'    => esc_html( get_bloginfo( 'name' ) ),
			'[address]'         => npmp_can_spam_get_address(),
			'[unsubscribe_url]' => esc_url( npmp_can_spam_get_unsubscribe_url() ),
		);

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			wp_kses_post( $footer )
		);
	}
}

if ( ! function_exists( 'npmp_can_spam_get_address' ) ) {
	/**
	 * Resolve the [address] token to a real postal mailing address.
	 *
	 * CAN-SPAM requires a valid physical postal address in commercial email.
	 * Falls back to the site admin email only when no address is configured,
	 * which is not compliant — set one on the Newsletter Settings screen.
	 *
	 * @return string Escaped, ready to drop into the footer markup.
	 */
	function npmp_can_spam_get_address() {
		$address = trim( (string) get_option( 'npmp_org_mailing_address', '' ) );

		if ( '' === $address ) {
			return esc_html( (string) get_option( 'admin_email' ) );
		}

		return nl2br( esc_html( $address ) );
	}
}

if ( ! function_exists( 'npmp_can_spam_get_unsubscribe_url' ) ) {
	/**
	 * Resolve the [unsubscribe_url] token to the configured unsubscribe page.
	 *
	 * Honors the page chosen on the Membership Settings screen and falls back
	 * to /unsubscribe only when none is set or the page is missing.
	 *
	 * @return string Unescaped URL.
	 */
	function npmp_can_spam_get_unsubscribe_url() {
		$fallback = site_url( '/unsubscribe' );

		if ( ! function_exists( 'npmp_get_membership_form_settings' ) ) {
			return $fallback;
		}

		$settings = npmp_get_membership_form_settings();
		$page_id  = absint( $settings['unsubscribe_page_id'] ?? 0 );

		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return $fallback;
		}

		$permalink = get_permalink( $page_id );

		return $permalink ? $permalink : $fallback;
	}
}

add_shortcode( 'npmp_can_spam', 'npmp_can_spam_shortcode' );
