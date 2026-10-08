<?php
/**
 * Script and block registration.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Helper for asset versioning (mtime with graceful fallback).
 *
 * @param string $relative_path Path relative to the plugin root.
 * @return string
 */
function npmp_get_asset_version( $relative_path ) {
	$absolute_path = plugin_dir_path( dirname( __FILE__ ) ) . ltrim( $relative_path, '/' );

	if ( file_exists( $absolute_path ) ) {
		return (string) filemtime( $absolute_path );
	}

	return '1.0.0';
}

// No front-end donation script is enqueued from here. Each donation form
// carries its own inline script, and the PayPal forms enqueue the PayPal SDK
// themselves with a real client id. The v1 options this file once read
// (npmp_enable_paypal, npmp_paypal_method) have had no writer since 2026-06-29,
// and on sites that still carry them it loaded sdk/js with an empty client id.
// tests/test-legacy-paypal-sdk.php pins this.

/**
 * Decide whether the default front-end form styles should load on this request.
 *
 * @return bool
 */
function npmp_should_enqueue_form_styles() {
	/**
	 * Filter whether the bundled default form styles load at all.
	 *
	 * @param bool $enabled Default true.
	 */
	if ( ! apply_filters( 'npmp_enable_default_form_styles', true ) ) {
		return false;
	}

	// Pages explicitly configured to host a form (covers auto-injected forms).
	$page_ids = array();
	if ( function_exists( 'npmp_get_membership_form_settings' ) ) {
		$settings   = npmp_get_membership_form_settings();
		$page_ids[] = absint( $settings['signup_page_id'] ?? 0 );
		$page_ids[] = absint( $settings['unsubscribe_page_id'] ?? 0 );
	}
	$page_ids[] = absint( get_option( 'npmp_preferences_page_id', 0 ) );
	$page_ids[] = absint( get_option( 'npmp_donation_page_id', 0 ) );
	$page_ids   = array_filter( $page_ids );

	if ( $page_ids && is_page( $page_ids ) ) {
		return true;
	}

	// Forms placed via shortcode on any singular content.
	if ( is_singular() ) {
		$post = get_post();
		if ( $post instanceof WP_Post ) {
			/**
			 * Shortcodes whose presence loads the bundled form styles.
			 *
			 * Pro registers its own public forms (the membership join form),
			 * so the list is filterable rather than a fixed list this plugin
			 * has to keep in step with an add-on.
			 *
			 * @param string[] $shortcodes Shortcode tags.
			 */
			$shortcodes = apply_filters(
				'npmp_form_style_shortcodes',
				array( 'npmp_email_signup', 'npmp_email_unsubscribe', 'npmp_manage_preferences', 'npmp_donation_form', 'npmp_join_form' )
			);
			foreach ( $shortcodes as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Register and conditionally enqueue the default front-end form stylesheet.
 *
 * @return void
 */
function npmp_register_form_styles() {
	$style_path = 'assets/css/npmp-forms.css';

	wp_register_style(
		'npmp-forms',
		plugins_url( $style_path, dirname( __FILE__ ) ),
		array(),
		npmp_get_asset_version( $style_path )
	);

	if ( npmp_should_enqueue_form_styles() ) {
		wp_enqueue_style( 'npmp-forms' );
	}
}
add_action( 'wp_enqueue_scripts', 'npmp_register_form_styles' );

/**
 * Enqueue admin-specific assets.
 *
 * @param string $hook Current admin hook suffix.
 * @return void
 */
function npmp_register_admin_scripts( $hook ) {
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'npmp_newsletter' === get_post_type() ) {
		$editor_path = 'includes/email-newsletter/assets/newsletter-editor.js';

		wp_enqueue_script(
			'npmp-newsletter-editor',
			plugins_url( $editor_path, dirname( __FILE__ ) ),
			array( 'jquery', 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor' ),
			npmp_get_asset_version( $editor_path ),
			true
		);
	}

	if ( false !== strpos( (string) $hook, 'npmp_payment_settings' ) ) {
		wp_enqueue_script( 'jquery' );

		$inline  = "jQuery(function () {\n";
		$inline .= "\tvar doc = document;\n";
		$inline .= "\tvar paypalSection = doc.getElementById('npmp-paypal-settings');\n";
		$inline .= "\tif (!paypalSection) {\n";
		$inline .= "\t\treturn;\n";
		$inline .= "\t}\n\n";
		$inline .= "\tvar methodRows = paypalSection.querySelectorAll('[data-method]');\n\n";
		$inline .= "\tfunction toggleGatewaySection() {\n";
		$inline .= "\t\tvar selectedGateway = doc.querySelector(\"input[name='npmp_gateway']:checked\");\n";
		$inline .= "\t\tpaypalSection.style.display = selectedGateway && selectedGateway.value === 'paypal' ? '' : 'none';\n";
		$inline .= "\t}\n\n";
		$inline .= "\tfunction toggleMethodFields() {\n";
		$inline .= "\t\tvar selected = paypalSection.querySelector(\"input[name='npmp_paypal_method']:checked\");\n";
		$inline .= "\t\tvar current = selected ? selected.value : '';\n";
		$inline .= "\t\tmethodRows.forEach(function (row) {\n";
		$inline .= "\t\t\trow.style.display = row.getAttribute('data-method') === current ? '' : 'none';\n";
		$inline .= "\t\t});\n";
		$inline .= "\t}\n\n";
		$inline .= "\tdoc.addEventListener('change', function (event) {\n";
		$inline .= "\t\tif (event.target.name === 'npmp_gateway') {\n";
		$inline .= "\t\t\ttoggleGatewaySection();\n";
		$inline .= "\t\t}\n";
		$inline .= "\t\tif (event.target.name === 'npmp_paypal_method') {\n";
		$inline .= "\t\t\ttoggleMethodFields();\n";
		$inline .= "\t\t}\n";
		$inline .= "\t});\n\n";
		$inline .= "\ttoggleGatewaySection();\n";
		$inline .= "\ttoggleMethodFields();\n";
		$inline .= "});\n";

		wp_add_inline_script( 'jquery', $inline );
	}

	if ( false !== strpos( (string) $hook, 'npmp_email_settings' ) ) {
		wp_enqueue_script( 'jquery' );

		$email_inline  = "jQuery(function ($) {\n";
		$email_inline .= "\tvar \$providerSelect = $('#npmp_email_provider');\n";
		$email_inline .= "\tvar \$captchaSelect = $('#npmp_captcha_provider');\n\n";
		$email_inline .= "\tfunction toggleProviders() {\n";
		$email_inline .= "\t\tvar provider = \$providerSelect.val();\n";
		$email_inline .= "\t\t$('.npmp-provider-block').hide();\n";
		$email_inline .= "\t\t$('.npmp-provider-' + provider).show();\n";
		$email_inline .= "\t\tif (provider === 'smtp' || provider === 'aws_ses') {\n";
		$email_inline .= "\t\t\t$('.npmp-provider-smtp-common').show();\n";
		$email_inline .= "\t\t}\n";
		$email_inline .= "\t}\n\n";
		$email_inline .= "\tfunction toggleCaptcha() {\n";
		$email_inline .= "\t\tvar provider = \$captchaSelect.val();\n";
		$email_inline .= "\t\t$('.captcha-turnstile').toggle(provider === 'turnstile');\n";
		$email_inline .= "\t\t$('.captcha-recaptcha').toggle(provider === 'recaptcha');\n";
		$email_inline .= "\t}\n\n";
		$email_inline .= "\t\$providerSelect.on('change', toggleProviders);\n";
		$email_inline .= "\t\$captchaSelect.on('change', toggleCaptcha);\n";
		$email_inline .= "\ttoggleProviders();\n";
		$email_inline .= "\ttoggleCaptcha();\n";
		$email_inline .= "});\n";

		wp_add_inline_script( 'jquery', $email_inline );
	}

	if ( false !== strpos( (string) $hook, 'npmp_members' ) ) {
		$bulk_script  = "jQuery(function ($) {\n";
		$bulk_script .= "\tconst toggle = document.getElementById('npmp-members-select-all');\n";
		$bulk_script .= "\tif (!toggle) {\n";
		$bulk_script .= "\t\treturn;\n";
		$bulk_script .= "\t}\n";
		$bulk_script .= "\ttoggle.addEventListener('click', function (event) {\n";
		$bulk_script .= "\t\tconst checkboxes = document.querySelectorAll(\"input[name='member_ids[]']\");\n";
		$bulk_script .= "\t\tcheckboxes.forEach(function (checkbox) {\n";
		$bulk_script .= "\t\t\tcheckbox.checked = event.target.checked;\n";
		$bulk_script .= "\t\t});\n";
		$bulk_script .= "\t});\n";
		$bulk_script .= "});\n";

		wp_add_inline_script( 'jquery', $bulk_script );
	}
}
add_action( 'admin_enqueue_scripts', 'npmp_register_admin_scripts' );
