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
			'[address]'         => esc_html( (string) get_option( 'admin_email' ) ), // Replace with a real mailing address setting if desired.
			'[unsubscribe_url]' => esc_url( site_url( '/unsubscribe' ) ), // Replace with your unsubscribe page.
		);

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			wp_kses_post( $footer )
		);
	}
}

add_shortcode( 'npmp_can_spam', 'npmp_can_spam_shortcode' );
