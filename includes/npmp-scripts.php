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

/**
 * Decide if the donation form assets should load on the current request.
 *
 * @return bool
 */
function npmp_should_enqueue_donation_script() {
	if ( is_page( (int) get_option( 'npmp_donation_page_id' ) ) ) {
		return true;
	}

	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'npmp_donation_form' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Register and enqueue front-end assets.
 *
 * @return void
 */
function npmp_register_frontend_scripts() {
	$paypal_enabled = (int) get_option( 'npmp_enable_paypal', 0 );
	$paypal_method  = get_option( 'npmp_paypal_method', 'sdk' );

	if ( $paypal_enabled && 'sdk' === $paypal_method ) {
		$mode      = get_option( 'npmp_paypal_mode', 'live' );
		$client_id = rawurlencode( (string) ( 'sandbox' === $mode ? get_option( 'npmp_paypal_sandbox_client_id', '' ) : get_option( 'npmp_paypal_live_client_id', '' ) ) );
		$sdk_url   = 'https://www.paypal.com/sdk/js?client-id=' . $client_id . '&currency=USD';

		if ( 'sandbox' === $mode ) {
			$sdk_url .= '&debug=true';
		}

		wp_enqueue_script( 'npmp-paypal-sdk', $sdk_url, array(), '1.0.0', true );
	}

	$script_handle = 'npmp-donation-form';
	$script_path   = 'assets/js/donation-form.js';

	wp_register_script(
		$script_handle,
		plugins_url( $script_path, dirname( __FILE__ ) ),
		array( 'jquery' ),
		npmp_get_asset_version( $script_path ),
		true
	);

	wp_localize_script(
		$script_handle,
		'npmpDonationData',
		array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'min_amount' => (float) get_option( 'npmp_paypal_minimum', 1 ),
		)
	);

	if ( npmp_should_enqueue_donation_script() ) {
		wp_enqueue_script( $script_handle );
	}
}
add_action( 'wp_enqueue_scripts', 'npmp_register_frontend_scripts' );

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
