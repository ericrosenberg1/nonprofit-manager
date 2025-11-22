<?php
/**
 * Shared helpers for Nonprofit Manager email handling.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default email settings.
 *
 * @return array
 */
function npmp_email_default_settings() {
	return array(
		'provider'        => 'wordpress',
		'from_email'      => get_option( 'admin_email' ) ?: 'noreply@example.com',
		'from_name'       => get_option( 'blogname' ) ?: 'Nonprofit Manager',
		'force_from'      => 1,
		'set_return_path' => 1,
		'smtp'            => array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => 1,
			'username'   => '',
			'password'   => '',
			'auto_tls'   => 1,
		),
		'aws'             => array(
			'region' => 'us-east-1',
		),
	);
}

/**
 * Retrieve email settings (with migration from legacy options).
 *
 * @return array
 */
function npmp_email_get_settings() {
	$settings = get_option( 'npmp_email_settings' );

	if ( ! is_array( $settings ) ) {
		$settings = npmp_email_default_settings();
		npmp_email_migrate_legacy_settings( $settings );
	} else {
		$defaults           = npmp_email_default_settings();
		$settings           = wp_parse_args( $settings, $defaults );
		$settings['smtp']   = wp_parse_args( $settings['smtp'] ?? array(), $defaults['smtp'] );
		$settings['aws']    = wp_parse_args( $settings['aws'] ?? array(), $defaults['aws'] );

		// Validate provider - include all valid providers
		$valid_providers = array( 'wordpress', 'smtp', 'aws_ses', 'brevo', 'sendgrid', 'mailgun', 'postmark', 'sparkpost' );
		$settings['provider'] = in_array( $settings['provider'] ?? '', $valid_providers, true )
			? $settings['provider']
			: 'wordpress';
	}

	// Ensure all values have proper defaults to prevent null errors
	$settings['from_name']  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : ( get_option( 'blogname' ) ?: 'Nonprofit Manager' );
	$settings['from_email'] = ! empty( $settings['from_email'] ) ? $settings['from_email'] : ( get_option( 'admin_email' ) ?: 'noreply@example.com' );
	$settings['provider']   = ! empty( $settings['provider'] ) ? $settings['provider'] : 'wordpress';

	// Ensure nested arrays have defaults
	if ( ! isset( $settings['smtp'] ) || ! is_array( $settings['smtp'] ) ) {
		$settings['smtp'] = array();
	}
	$smtp_defaults = npmp_email_default_settings()['smtp'];
	foreach ( $smtp_defaults as $key => $default_value ) {
		// Ensure each SMTP value is never null
		if ( ! isset( $settings['smtp'][ $key ] ) || is_null( $settings['smtp'][ $key ] ) ) {
			$settings['smtp'][ $key ] = $default_value;
		}
		// Ensure string values are actually strings, not null
		if ( is_string( $default_value ) && ! is_string( $settings['smtp'][ $key ] ) ) {
			$settings['smtp'][ $key ] = (string) $settings['smtp'][ $key ];
		}
	}

	if ( ! isset( $settings['aws'] ) || ! is_array( $settings['aws'] ) ) {
		$settings['aws'] = array();
	}
	$settings['aws']['region'] = $settings['aws']['region'] ?? 'us-east-1';

	return $settings;
}

/**
 * Persist settings to the database.
 *
 * @param array $settings Sanitised settings.
 * @return void
 */
function npmp_email_update_settings( $settings ) {
	update_option( 'npmp_email_settings', $settings );
}

/**
 * Attempt to migrate legacy option values.
 *
 * @param array &$settings Current settings array (passed by reference).
 * @return void
 */
function npmp_email_migrate_legacy_settings( &$settings ) {
	$method     = get_option( 'npmp_email_method', 'wp_mail' );
	$from_email = get_option( 'npmp_email_from_email', get_option( 'admin_email' ) );
	$from_name  = get_option( 'npmp_email_from_name', get_option( 'blogname' ) );

	$settings['from_email'] = $from_email;
	$settings['from_name']  = $from_name;

	if ( 'smtp' === $method ) {
		$settings['provider']          = 'smtp';
		$settings['smtp']['host']      = get_option( 'npmp_smtp_host', '' );
		$settings['smtp']['port']      = (int) get_option( 'npmp_smtp_port', 587 );
		$settings['smtp']['encryption'] = get_option( 'npmp_smtp_encryption', 'tls' );
		$settings['smtp']['auth']      = (int) get_option( 'npmp_smtp_auth', 1 );
		$settings['smtp']['username']  = get_option( 'npmp_smtp_username', '' );
		$settings['smtp']['password']  = get_option( 'npmp_smtp_password', '' );
	}
}

/**
 * Provider choices for the settings select.
 *
 * @param bool $include_pro Whether to include Pro providers.
 * @return array
 */
function npmp_email_get_provider_choices( $include_pro = false ) {
	$choices = array(
		'wordpress' => __( 'WordPress Default', 'nonprofit-manager' ),
		'smtp'      => __( 'Custom SMTP', 'nonprofit-manager' ),
	);

	// Pro providers (API-based integrations) - alphabetized
	if ( npmp_is_pro() || $include_pro ) {
		$pro_providers = array(
			'aws_ses'   => __( 'Amazon SES', 'nonprofit-manager' ),
			'brevo'     => __( 'Brevo (Sendinblue)', 'nonprofit-manager' ),
			'mailgun'   => __( 'Mailgun', 'nonprofit-manager' ),
			'postmark'  => __( 'Postmark', 'nonprofit-manager' ),
			'sendgrid'  => __( 'SendGrid', 'nonprofit-manager' ),
			'sparkpost' => __( 'SparkPost', 'nonprofit-manager' ),
		);

		// Add "(Pro)" suffix for free version users
		if ( ! npmp_is_pro() ) {
			foreach ( $pro_providers as $key => $label ) {
				$pro_providers[ $key ] = $label . ' ' . __( '(Pro)', 'nonprofit-manager' );
			}
		}

		$choices = array_merge( $choices, $pro_providers );
	}

	return $choices;
}

/**
 * Get provider documentation URLs
 *
 * @param string $provider Provider key.
 * @return string
 */
function npmp_email_get_provider_docs_url( $provider ) {
	$urls = array(
		'smtp'      => 'https://wordpress.org/support/article/settings-email-screen/',
		'aws_ses'   => 'https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html',
		'brevo'     => 'https://help.brevo.com/hc/en-us/articles/209467485-Configure-your-SMTP-settings',
		'sendgrid'  => 'https://docs.sendgrid.com/for-developers/sending-email/integrating-with-the-smtp-api',
		'mailgun'   => 'https://documentation.mailgun.com/en/latest/user_manual.html#smtp',
		'postmark'  => 'https://postmarkapp.com/support/article/1008-what-are-the-smtp-settings',
		'sparkpost' => 'https://developers.sparkpost.com/api/smtp/',
	);

	return isset( $urls[ $provider ] ) ? $urls[ $provider ] : '';
}

/**
 * Retrieve available AWS regions.
 *
 * @return array
 */
function npmp_email_get_aws_regions() {
	return array(
		'us-east-1'      => __( 'US East (N. Virginia)', 'nonprofit-manager' ),
		'us-east-2'      => __( 'US East (Ohio)', 'nonprofit-manager' ),
		'us-west-1'      => __( 'US West (N. California)', 'nonprofit-manager' ),
		'us-west-2'      => __( 'US West (Oregon)', 'nonprofit-manager' ),
		'ca-central-1'   => __( 'Canada (Central)', 'nonprofit-manager' ),
		'eu-central-1'   => __( 'Europe (Frankfurt)', 'nonprofit-manager' ),
		'eu-west-1'      => __( 'Europe (Ireland)', 'nonprofit-manager' ),
		'eu-west-2'      => __( 'Europe (London)', 'nonprofit-manager' ),
		'eu-west-3'      => __( 'Europe (Paris)', 'nonprofit-manager' ),
		'eu-north-1'     => __( 'Europe (Stockholm)', 'nonprofit-manager' ),
		'ap-south-1'     => __( 'Asia Pacific (Mumbai)', 'nonprofit-manager' ),
		'ap-northeast-1' => __( 'Asia Pacific (Tokyo)', 'nonprofit-manager' ),
		'ap-northeast-2' => __( 'Asia Pacific (Seoul)', 'nonprofit-manager' ),
		'ap-southeast-1' => __( 'Asia Pacific (Singapore)', 'nonprofit-manager' ),
		'ap-southeast-2' => __( 'Asia Pacific (Sydney)', 'nonprofit-manager' ),
		'sa-east-1'      => __( 'South America (Sao Paulo)', 'nonprofit-manager' ),
	);
}

/**
 * Generate the Amazon SES SMTP host for a region.
 *
 * @param string $region AWS region.
 * @return string
 */
function npmp_email_get_aws_smtp_host( $region ) {
	$region = sanitize_key( $region );
	return sprintf( 'email-smtp.%s.amazonaws.com', $region );
}

/**
 * Store the latest email result.
 *
 * @param string $status  success|error|warning.
 * @param array  $details Additional context.
 * @return void
 */
function npmp_email_record_result( $status, $details = array() ) {
	$log = array(
		'status'    => sanitize_key( $status ),
		'details'   => $details,
		'timestamp' => time(),
	);

	update_option( 'npmp_email_last_result', $log );
}

/**
 * Retrieve the most recent email result.
 *
 * @return array
 */
function npmp_email_get_last_result() {
	$log = get_option( 'npmp_email_last_result', array() );
	if ( ! isset( $log['status'] ) ) {
		return array();
	}
	return $log;
}

/**
 * Determine if the current provider requires SMTP credentials.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function npmp_email_provider_requires_smtp( $settings ) {
	// Only Custom SMTP uses the SMTP configuration
	return 'smtp' === $settings['provider'];
}

/**
 * Helper to detect if SMTP configuration appears complete.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function npmp_email_smtp_is_configured( $settings ) {
	if ( ! npmp_email_provider_requires_smtp( $settings ) ) {
		return true;
	}

	$host     = trim( $settings['smtp']['host'] );
	$username = trim( $settings['smtp']['username'] );
	$password = trim( $settings['smtp']['password'] );
	$port     = (int) $settings['smtp']['port'];

	return ( '' !== $host && '' !== $username && '' !== $password && $port > 0 );
}
