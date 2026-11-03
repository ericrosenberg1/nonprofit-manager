<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_can_spam_shortcode' ) ) {
	/**
	 * Render the CAN-SPAM footer content.
	 *
	 * @param array|string $atts newsletter_id and email of the message being
	 *                           built. Blank in the [npmp_can_spam] shortcode.
	 * @return string
	 */
	function npmp_can_spam_shortcode( $atts = array() ) {
		$atts          = is_array( $atts ) ? $atts : array();
		$newsletter_id = isset( $atts['newsletter_id'] ) ? absint( $atts['newsletter_id'] ) : 0;
		$email         = isset( $atts['email'] ) ? sanitize_email( (string) $atts['email'] ) : '';
		$footer        = get_option( 'npmp_newsletter_can_spam_footer' );

		$replacements = array(
			'[organization]'    => esc_html( get_bloginfo( 'name' ) ),
			'[address]'         => npmp_can_spam_get_address(),
			'[unsubscribe_url]' => esc_url( npmp_can_spam_get_unsubscribe_url() ),
		);

		$footer_html = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			wp_kses_post( $footer )
		);

		/**
		 * Filter extra links that sit right after the footer's unsubscribe
		 * line. Pro adds "Leave [group] emails" for a group newsletter.
		 * Return HTML (it goes through wp_kses_post()).
		 *
		 * @param string $html          Extra footer HTML ('' in free).
		 * @param int    $newsletter_id Newsletter ID (0 outside a send).
		 * @param string $email         Recipient address ('' outside a send).
		 */
		$links = (string) apply_filters( 'npmp_newsletter_footer_links', '', $newsletter_id, $email );
		if ( '' !== trim( $links ) ) {
			$footer_html .= '<p class="npmp-footer-links">' . wp_kses_post( $links ) . '</p>';
		}

		// Attribution billboard. Rides the newsletter footer so every send
		// seeds discovery. Free always shows it. Pro can remove it.
		if ( function_exists( 'npmp_powered_by_html' ) ) {
			$footer_html .= npmp_powered_by_html( 'email' );
		}

		return $footer_html;
	}
}

if ( ! function_exists( 'npmp_can_spam_get_address' ) ) {
	/**
	 * Resolve the [address] token to a real postal mailing address.
	 *
	 * CAN-SPAM requires a valid physical postal address in commercial email.
	 * Falls back to the site admin email only when no address is configured,
	 * which is not compliant. Set one on the Newsletter Settings screen.
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
