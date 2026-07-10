<?php
/**
 * File path: includes/npmp-content-blocks.php
 *
 * Gutenberg blocks and shortcodes for the member and donor facing widgets: the
 * email signup, unsubscribe, and donation forms (dynamic blocks that wrap the
 * existing shortcodes), plus two new self-contained widgets, a visitor social
 * share bar and a general contact form. Every block is dynamic (server rendered)
 * so block output matches shortcode output, and the plugin needs no JS build step.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * New shortcode 1: [npmp_social_share] — share-this-page buttons
 * ====================================================================== */

/**
 * Share networks and their intent URLs. {url} and {title} are rawurlencoded
 * before substitution.
 *
 * @return array<string,array<string,mixed>>
 */
function npmp_social_share_networks() {
	return array(
		'facebook' => array( 'label' => 'Facebook', 'intent' => 'https://www.facebook.com/sharer/sharer.php?u={url}',       'newtab' => true ),
		'x'        => array( 'label' => 'X',        'intent' => 'https://twitter.com/intent/tweet?url={url}&text={title}',   'newtab' => true ),
		'linkedin' => array( 'label' => 'LinkedIn', 'intent' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}', 'newtab' => true ),
		'reddit'   => array( 'label' => 'Reddit',   'intent' => 'https://www.reddit.com/submit?url={url}&title={title}',     'newtab' => true ),
		'email'    => array( 'label' => 'Email',    'intent' => 'mailto:?subject={title}&body={url}',                       'newtab' => false ),
	);
}

/**
 * Render the [npmp_social_share] shortcode.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function npmp_social_share_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'networks' => 'facebook,x,linkedin,reddit,email,copy',
			'label'    => '',
			'url'      => '',
			'title'    => '',
		),
		$atts,
		'npmp_social_share'
	);

	$url = trim( (string) $atts['url'] );
	if ( '' === $url ) {
		$url = is_singular() ? get_permalink() : home_url( '/' );
	}
	$url = esc_url_raw( $url );
	if ( '' === $url ) {
		return '';
	}

	$title = trim( (string) $atts['title'] );
	if ( '' === $title ) {
		$title = is_singular() ? get_the_title() : get_bloginfo( 'name' );
	}

	$enc_url   = rawurlencode( $url );
	$enc_title = rawurlencode( wp_strip_all_tags( $title ) );
	$defs      = npmp_social_share_networks();

	$wanted = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $atts['networks'] ) ) ) );
	if ( empty( $wanted ) ) {
		$wanted = array( 'facebook', 'x', 'linkedin', 'reddit', 'email', 'copy' );
	}

	if ( wp_style_is( 'npmp-forms', 'registered' ) ) {
		wp_enqueue_style( 'npmp-forms' );
	}
	if ( in_array( 'copy', $wanted, true ) && wp_script_is( 'npmp-social-share', 'registered' ) ) {
		wp_enqueue_script( 'npmp-social-share' );
	}

	$items = '';
	foreach ( $wanted as $key ) {
		if ( 'copy' === $key ) {
			$items .= '<li><button type="button" class="npmp-share-btn npmp-share-copy" data-npmp-share-url="' . esc_attr( $url ) . '">'
				. esc_html__( 'Copy link', 'nonprofit-manager' ) . '</button></li>';
			continue;
		}
		if ( ! isset( $defs[ $key ] ) ) {
			continue;
		}
		$href   = str_replace( array( '{url}', '{title}' ), array( $enc_url, $enc_title ), $defs[ $key ]['intent'] );
		$newtab = $defs[ $key ]['newtab'] ? ' target="_blank" rel="noopener nofollow"' : '';
		$items .= '<li><a class="npmp-share-btn npmp-share-' . esc_attr( $key ) . '" href="' . esc_url( $href ) . '"' . $newtab . '>'
			. esc_html( $defs[ $key ]['label'] ) . '</a></li>';
	}

	if ( '' === $items ) {
		return '';
	}

	$label_html = '';
	if ( '' !== trim( (string) $atts['label'] ) ) {
		$label_html = '<span class="npmp-social-share-label">' . esc_html( $atts['label'] ) . '</span>';
	}

	return '<div class="npmp-social-share">' . $label_html . '<ul class="npmp-social-share-buttons">' . $items . '</ul></div>';
}
add_shortcode( 'npmp_social_share', 'npmp_social_share_shortcode' );

/* =========================================================================
 * New shortcode 2: [npmp_contact_form] — general contact form
 * ====================================================================== */

/**
 * Recipient for contact-form messages. Never read from the request (that would
 * make the form an open relay); defaults to the site admin and can be overridden
 * server-side with the npmp_contact_form_recipient filter.
 *
 * @return string
 */
function npmp_contact_form_recipient() {
	/** This filter is documented above its use. */
	$recipient = apply_filters( 'npmp_contact_form_recipient', get_option( 'admin_email' ) );
	return is_email( $recipient ) ? $recipient : get_option( 'admin_email' );
}

/**
 * Render the [npmp_contact_form] shortcode.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function npmp_contact_form_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'heading' => __( 'Contact us', 'nonprofit-manager' ),
			'button'  => __( 'Send message', 'nonprofit-manager' ),
			'subject' => 'true',
		),
		$atts,
		'npmp_contact_form'
	);

	if ( wp_style_is( 'npmp-forms', 'registered' ) ) {
		wp_enqueue_style( 'npmp-forms' );
	}

	$banner = '';
	if ( isset( $_GET['npmp_contact'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own PRG redirect.
		$status = sanitize_key( wp_unslash( $_GET['npmp_contact'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'success' === $status ) {
			$banner = '<div class="npmp-form-banner npmp-status-success"><p>' . esc_html__( 'Thanks. Your message has been sent.', 'nonprofit-manager' ) . '</p></div>';
		} elseif ( 'error' === $status ) {
			$banner = '<div class="npmp-form-banner npmp-status-error"><p>' . esc_html__( 'Sorry, your message could not be sent. Please check the fields and try again.', 'nonprofit-manager' ) . '</p></div>';
		}
	}

	$show_subject = ( 'false' !== (string) $atts['subject'] );
	$redirect_to  = is_singular() ? get_permalink() : home_url( '/' );

	$html  = '<div class="npmp-contact-form-wrap">' . $banner;
	$html .= '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="npmp-contact-form">';
	$html .= '<h3>' . esc_html( $atts['heading'] ) . '</h3>';
	$html .= '<p><label>' . esc_html__( 'Name', 'nonprofit-manager' ) . '<br><input type="text" name="npmp_contact_name" required></label></p>';
	$html .= '<p><label>' . esc_html__( 'Email', 'nonprofit-manager' ) . '<br><input type="email" name="npmp_contact_email" required></label></p>';
	if ( $show_subject ) {
		$html .= '<p><label>' . esc_html__( 'Subject', 'nonprofit-manager' ) . '<br><input type="text" name="npmp_contact_subject"></label></p>';
	}
	$html .= '<p><label>' . esc_html__( 'Message', 'nonprofit-manager' ) . '<br><textarea name="npmp_contact_message" rows="5" required></textarea></label></p>';
	$html .= '<p class="npmp-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'nonprofit-manager' )
		. '<input type="text" name="npmp_contact_website" tabindex="-1" autocomplete="off"></label></p>';
	if ( function_exists( 'npmp_captcha_render_widget' ) ) {
		$html .= npmp_captcha_render_widget( 'contact_form' );
	}
	$html .= wp_nonce_field( 'npmp_contact_form', 'npmp_contact_nonce', true, false );
	$html .= '<input type="hidden" name="action" value="npmp_contact_form">';
	$html .= '<input type="hidden" name="npmp_contact_redirect" value="' . esc_url( $redirect_to ) . '">';
	$html .= '<p><button type="submit">' . esc_html( $atts['button'] ) . '</button></p>';
	$html .= '</form></div>';

	return $html;
}
add_shortcode( 'npmp_contact_form', 'npmp_contact_form_shortcode' );

/**
 * Handle a contact-form submission, then redirect back with a status flag.
 *
 * @return void
 */
function npmp_contact_form_handle() {
	$redirect = isset( $_POST['npmp_contact_redirect'] )
		? esc_url_raw( wp_unslash( $_POST['npmp_contact_redirect'] ) )
		: home_url( '/' );

	if ( ! isset( $_POST['npmp_contact_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_contact_nonce'] ) ), 'npmp_contact_form' ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_contact', 'error', $redirect ) );
		exit;
	}

	// Honeypot: silently accept so bots do not learn they were caught.
	if ( ! empty( $_POST['npmp_contact_website'] ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_contact', 'success', $redirect ) );
		exit;
	}

	if ( function_exists( 'npmp_captcha_verify' ) && ! npmp_captcha_verify( 'contact_form' ) ) {
		wp_safe_redirect( add_query_arg( 'npmp_contact', 'error', $redirect ) );
		exit;
	}

	$name    = isset( $_POST['npmp_contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_contact_name'] ) ) : '';
	$email   = isset( $_POST['npmp_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['npmp_contact_email'] ) ) : '';
	$subject = isset( $_POST['npmp_contact_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_contact_subject'] ) ) : '';
	$message = isset( $_POST['npmp_contact_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['npmp_contact_message'] ) ) : '';

	if ( '' === $name || ! is_email( $email ) || '' === $message ) {
		wp_safe_redirect( add_query_arg( 'npmp_contact', 'error', $redirect ) );
		exit;
	}

	$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$recipient = npmp_contact_form_recipient();
	$headline  = '' !== $subject ? $subject : sprintf(
		/* translators: %s: sender name. */
		__( 'New contact message from %s', 'nonprofit-manager' ),
		$name
	);
	$body = sprintf(
		"%s\n\n%s: %s\n%s: %s",
		$message,
		__( 'Name', 'nonprofit-manager' ),
		$name,
		__( 'Email', 'nonprofit-manager' ),
		$email
	);
	$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

	$sent = wp_mail( $recipient, '[' . $site . '] ' . $headline, $body, $headers );

	wp_safe_redirect( add_query_arg( 'npmp_contact', $sent ? 'success' : 'error', $redirect ) );
	exit;
}
add_action( 'admin_post_npmp_contact_form', 'npmp_contact_form_handle' );
add_action( 'admin_post_nopriv_npmp_contact_form', 'npmp_contact_form_handle' );

/* =========================================================================
 * Block registration (dynamic blocks wrapping the shortcodes above)
 * ====================================================================== */

/**
 * Register the editor script and the five content blocks.
 *
 * @return void
 */
function npmp_content_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return; // WordPress < 5.0.
	}

	$plugin_file = dirname( __DIR__ ) . '/nonprofit-manager.php';
	$editor_rel  = 'assets/js/npmp-content-blocks.js';
	$copy_rel    = 'assets/js/npmp-social-share.js';
	$handle      = 'npmp-content-blocks';

	wp_register_script(
		$handle,
		plugins_url( $editor_rel, $plugin_file ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
		function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $editor_rel ) : null,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'nonprofit-manager' );
	}

	wp_register_script(
		'npmp-social-share',
		plugins_url( $copy_rel, $plugin_file ),
		array(),
		function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $copy_rel ) : null,
		true
	);

	$common = array(
		'editor_script' => $handle,
		'style'         => 'npmp-forms',
	);

	register_block_type(
		'nonprofit-manager/social-share',
		array_merge(
			$common,
			array(
				'render_callback' => 'npmp_social_share_block_render',
				'attributes'      => array(
					'networks' => array( 'type' => 'string', 'default' => 'facebook,x,linkedin,reddit,email,copy' ),
					'label'    => array( 'type' => 'string', 'default' => '' ),
				),
			)
		)
	);

	register_block_type(
		'nonprofit-manager/contact-form',
		array_merge(
			$common,
			array(
				'render_callback' => 'npmp_contact_form_block_render',
				'attributes'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
					'button'  => array( 'type' => 'string', 'default' => '' ),
					'subject' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		)
	);

	// Wrapper blocks — only where the underlying feature (and its shortcode) is loaded.
	if ( function_exists( 'npmp_email_signup_shortcode' ) ) {
		register_block_type( 'nonprofit-manager/email-signup', array_merge( $common, array( 'render_callback' => 'npmp_email_signup_block_render' ) ) );
	}
	if ( function_exists( 'npmp_email_unsubscribe_shortcode' ) ) {
		register_block_type( 'nonprofit-manager/email-unsubscribe', array_merge( $common, array( 'render_callback' => 'npmp_email_unsubscribe_block_render' ) ) );
	}
	if ( function_exists( 'npmp_render_donation_form' ) ) {
		register_block_type( 'nonprofit-manager/donation-form', array_merge( $common, array( 'render_callback' => 'npmp_donation_form_block_render' ) ) );
	}

	// Tell the editor which wrapper blocks are available this request, so the JS
	// only registers blocks that PHP registered.
	wp_add_inline_script(
		$handle,
		'window.npmpContentBlocks=' . wp_json_encode(
			array(
				'signup'      => function_exists( 'npmp_email_signup_shortcode' ),
				'unsubscribe' => function_exists( 'npmp_email_unsubscribe_shortcode' ),
				'donation'    => function_exists( 'npmp_render_donation_form' ),
			)
		) . ';',
		'before'
	);
}
add_action( 'init', 'npmp_content_register_blocks' );

/**
 * Render the email-signup block via its shortcode.
 *
 * @return string
 */
function npmp_email_signup_block_render() {
	return function_exists( 'npmp_email_signup_shortcode' ) ? npmp_email_signup_shortcode() : '';
}

/**
 * Render the unsubscribe block via its shortcode.
 *
 * @return string
 */
function npmp_email_unsubscribe_block_render() {
	return function_exists( 'npmp_email_unsubscribe_shortcode' ) ? npmp_email_unsubscribe_shortcode() : '';
}

/**
 * Render the donation-form block via its shortcode.
 *
 * @return string
 */
function npmp_donation_form_block_render() {
	return function_exists( 'npmp_render_donation_form' ) ? npmp_render_donation_form() : '';
}

/**
 * Render the social-share block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_social_share_block_render( $attributes ) {
	return npmp_social_share_shortcode(
		array(
			'networks' => isset( $attributes['networks'] ) ? sanitize_text_field( $attributes['networks'] ) : '',
			'label'    => isset( $attributes['label'] ) ? sanitize_text_field( $attributes['label'] ) : '',
		)
	);
}

/**
 * Render the contact-form block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_contact_form_block_render( $attributes ) {
	$atts = array( 'subject' => ( isset( $attributes['subject'] ) && ! $attributes['subject'] ) ? 'false' : 'true' );
	if ( ! empty( $attributes['heading'] ) ) {
		$atts['heading'] = sanitize_text_field( $attributes['heading'] );
	}
	if ( ! empty( $attributes['button'] ) ) {
		$atts['button'] = sanitize_text_field( $attributes['button'] );
	}
	return npmp_contact_form_shortcode( $atts );
}
