<?php
// includes/npmp-blocks.php

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		$script_handle = 'npmp-blocks-editor';
		$script_path   = 'assets/js/np-blocks.js';
		$plugin_file   = dirname( __DIR__ ) . '/nonprofit-manager.php';

		if ( function_exists( 'wp_register_script' ) ) {
			wp_register_script(
				$script_handle,
				plugins_url( $script_path, $plugin_file ),
				array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-editor' ),
				function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $script_path ) : null,
				true
			);
		}

		$blocks = array(
			'donation-form' => array(
				'title'       => __( 'Donation Form', 'nonprofit-manager' ),
				'description' => __( 'Displays a donation form with payment gateway options.', 'nonprofit-manager' ),
				'shortcode'   => 'npmp_donation_form',
				'icon'        => 'money-alt',
				'keywords'    => array( 'donation', 'give', 'support' ),
			),
			'email-signup'  => array(
				'title'       => __( 'Email Signup Form', 'nonprofit-manager' ),
				'description' => __( 'Displays a form for visitors to join your email list.', 'nonprofit-manager' ),
				'shortcode'   => 'npmp_email_signup',
				'icon'        => 'email',
				'keywords'    => array( 'email', 'newsletter', 'subscribe' ),
			),
			'email-unsubscribe' => array(
				'title'       => __( 'Email Unsubscribe Form', 'nonprofit-manager' ),
				'description' => __( 'Displays a form for subscribers to remove themselves from the email list.', 'nonprofit-manager' ),
				'shortcode'   => 'npmp_email_unsubscribe',
				'icon'        => 'email-alt',
				'keywords'    => array( 'unsubscribe', 'email', 'opt-out' ),
			),
		);

		foreach ( $blocks as $slug => $data ) {
			register_block_type(
				'nonprofit-manager/' . $slug,
				array(
					'editor_script'   => $script_handle,
					'render_callback' => static function () use ( $data ) {
						return do_shortcode( '[' . $data['shortcode'] . ']' );
					},
					'attributes'      => array(),
					'title'           => $data['title'],
					'description'     => $data['description'],
					'category'        => 'widgets',
					'icon'            => $data['icon'],
					'keywords'        => $data['keywords'],
					'supports'        => array( 'html' => false ),
				)
			);
		}

		register_block_type(
			'nonprofit-manager/email-composer',
			array(
				'editor_script'   => $script_handle,
				'render_callback' => static function () {
					return ''; // Server-rendered via PHP.
				},
			)
		);
	}
);
