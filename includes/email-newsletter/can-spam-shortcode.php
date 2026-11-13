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
			'[unsubscribe_url]' => esc_url( npmp_can_spam_get_unsubscribe_url( $email ) ),
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

if ( ! function_exists( 'npmp_email_recipient_footer' ) ) {
	/**
	 * The footer for one contact's copy of an email that isn't a newsletter:
	 * the newsletter footer (organization, postal address, and that contact's
	 * own unsubscribe link), plus a link to their preferences page when the
	 * site has one.
	 *
	 * Post and event notifications, the weekly digest, and Pro's automation
	 * emails use it. Before this, the notifications and the digest carried a
	 * preferences link and no postal address, and automation emails carried
	 * no footer at all.
	 *
	 * @param string $email       Recipient address.
	 * @param bool   $preferences True to add the preferences link.
	 * @return string HTML.
	 */
	function npmp_email_recipient_footer( $email, $preferences = true ) {
		$email = sanitize_email( (string) $email );
		$html  = npmp_can_spam_shortcode( array( 'email' => $email ) );

		$has_page = function_exists( 'npmp_get_preferences_page_id' ) && npmp_get_preferences_page_id();
		if ( $preferences && $has_page && $email && function_exists( 'npmp_get_preferences_url' ) ) {
			$html .= '<p class="npmp-footer-preferences"><a href="' . esc_url( npmp_get_preferences_url( $email ) ) . '">' . esc_html__( 'Manage your email preferences', 'nonprofit-manager' ) . '</a></p>';
		}

		return $html;
	}
}

if ( ! function_exists( 'npmp_format_postal_address' ) ) {
	/**
	 * Turn the organization address from General Settings into postal lines.
	 *
	 * @param array $org NPMP_Org_Settings::get() values.
	 * @return string One line per row, "" when there's no street address.
	 */
	function npmp_format_postal_address( $org ) {
		$org   = is_array( $org ) ? $org : array();
		$part  = static function ( $key ) use ( $org ) {
			return trim( (string) ( $org[ $key ] ?? '' ) );
		};
		$line1 = $part( 'address_line1' );
		if ( '' === $line1 ) {
			return '';
		}

		$lines    = array( $line1 );
		$lines[]  = $part( 'address_line2' );
		$region   = trim( $part( 'state' ) . ' ' . $part( 'postal_code' ) );
		$lines[]  = implode( ', ', array_filter( array( $part( 'city' ), $region ), 'strlen' ) );
		$country  = $part( 'country' );
		$lines[]  = ( '' !== $country && 'US' !== strtoupper( $country ) ) ? $country : '';

		return implode( "\n", array_filter( $lines, 'strlen' ) );
	}
}

if ( ! function_exists( 'npmp_can_spam_address_text' ) ) {
	/**
	 * The postal address for the CAN-SPAM footer, as plain text.
	 *
	 * The Newsletter Settings address wins. When it's empty, the organization
	 * address from General Settings fills in, so nobody has to type the
	 * address twice. Before this, an empty newsletter address put the admin
	 * email in the footer even when General Settings had a street address.
	 *
	 * @return string "" when neither is set.
	 */
	function npmp_can_spam_address_text() {
		$address = trim( (string) get_option( 'npmp_org_mailing_address', '' ) );
		if ( '' !== $address ) {
			return $address;
		}
		$org = get_option( 'npmp_org_settings', array() );
		return npmp_format_postal_address( is_array( $org ) ? $org : array() );
	}
}

if ( ! function_exists( 'npmp_can_spam_get_address' ) ) {
	/**
	 * Resolve the [address] token to a real postal mailing address.
	 *
	 * CAN-SPAM requires a valid physical postal address in commercial email.
	 * Falls back to the site admin email only when neither Newsletter Settings
	 * nor General Settings has an address, which is not compliant.
	 *
	 * @return string Escaped, ready to drop into the footer markup.
	 */
	function npmp_can_spam_get_address() {
		$address = npmp_can_spam_address_text();

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
	 * With a recipient's address (every newsletter send), the link carries
	 * that address and its signed unsubscribe token, so the page offers one
	 * button and the reader types nothing. Before 2026.11.3 everyone got the
	 * bare page and had to type their address. With no page set, it's the
	 * one-click link, which always works.
	 *
	 * @param string $email Recipient address. Blank for the bare page.
	 * @return string Unescaped URL.
	 */
	function npmp_can_spam_get_unsubscribe_url( $email = '' ) {
		$fallback = site_url( '/unsubscribe' );
		$email    = sanitize_email( (string) $email );
		$signed   = $email && is_email( $email ) && function_exists( 'npmp_generate_unsubscribe_token' );

		$permalink = '';
		if ( function_exists( 'npmp_get_membership_form_settings' ) ) {
			$settings = npmp_get_membership_form_settings();
			$page_id  = absint( $settings['unsubscribe_page_id'] ?? 0 );
			if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
				$permalink = (string) get_permalink( $page_id );
			}
		}

		if ( ! $signed ) {
			return '' !== $permalink ? $permalink : $fallback;
		}

		if ( '' === $permalink ) {
			return function_exists( 'npmp_get_one_click_unsubscribe_url' ) ? npmp_get_one_click_unsubscribe_url( $email ) : $fallback;
		}

		return add_query_arg(
			array(
				'npmp_email' => rawurlencode( $email ),
				'npmp_token' => npmp_generate_unsubscribe_token( $email ),
			),
			$permalink
		);
	}
}

add_shortcode( 'npmp_can_spam', 'npmp_can_spam_shortcode' );
