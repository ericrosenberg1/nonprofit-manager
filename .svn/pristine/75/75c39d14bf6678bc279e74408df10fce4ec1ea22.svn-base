<?php
/**
 * Email transport bootstrap for Nonprofit Manager.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configure PHPMailer with the selected transport.
 *
 * @param PHPMailer $phpmailer PHPMailer instance.
 * @return void
 */
function npmp_email_apply_transport( $phpmailer ) {
	$settings = npmp_email_get_settings();
	$provider = $settings['provider'];

	if ( ! npmp_email_provider_requires_smtp( $settings ) ) {
		return;
	}

	$smtp = $settings['smtp'];

	if ( 'aws_ses' === $provider ) {
		$region = $settings['aws']['region'] ?? 'us-east-1';
		if ( empty( $smtp['host'] ) ) {
			$smtp['host'] = npmp_email_get_aws_smtp_host( $region );
		}
		if ( empty( $smtp['port'] ) ) {
			$smtp['port'] = 587;
		}
		if ( empty( $smtp['encryption'] ) ) {
			$smtp['encryption'] = 'tls';
		}
	}

	$host     = trim( $smtp['host'] );
	$username = trim( $smtp['username'] );
	$password = trim( $smtp['password'] );
	$port     = (int) $smtp['port'];

	if ( '' === $host || '' === $username || '' === $password || $port <= 0 ) {
		npmp_email_record_result(
			'warning',
			array(
				'message'  => __( 'SMTP provider selected but credentials are incomplete. Falling back to wp_mail.', 'nonprofit-manager' ),
				'provider' => $provider,
			)
		);
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host       = $host;
	$phpmailer->Port       = $port;
	$phpmailer->SMTPAuth   = (bool) $smtp['auth'];
	$phpmailer->Username   = $username;
	$phpmailer->Password   = $password;
	$phpmailer->SMTPSecure = in_array( $smtp['encryption'], array( 'ssl', 'tls' ), true ) ? $smtp['encryption'] : '';
	$phpmailer->SMTPAutoTLS = ! empty( $smtp['auto_tls'] );

	if ( ! $phpmailer->SMTPAuth ) {
		$phpmailer->SMTPAuth = false;
		$phpmailer->Username = '';
		$phpmailer->Password = '';
	}

	$from_email = $settings['from_email'];
	$from_name  = $settings['from_name'];

	if ( $from_email && $from_name ) {
		$phpmailer->setFrom( $from_email, $from_name, false );
	} elseif ( $from_email ) {
		$phpmailer->setFrom( $from_email, $from_email, false );
	}

	if ( ! empty( $settings['set_return_path'] ) && $from_email ) {
		$phpmailer->Sender = $from_email;
	}
}
add_action( 'phpmailer_init', 'npmp_email_apply_transport' );

/**
 * Enforce From email address if configured.
 *
 * @param string $value Original email.
 * @return string
 */
function npmp_email_filter_from_address( $value ) {
	$settings = npmp_email_get_settings();

	if ( empty( $settings['force_from'] ) ) {
		return $value;
	}

	$from = sanitize_email( $settings['from_email'] );
	return $from ?: $value;
}
add_filter( 'wp_mail_from', 'npmp_email_filter_from_address' );

/**
 * Enforce From name if configured.
 *
 * @param string $value Original name.
 * @return string
 */
function npmp_email_filter_from_name( $value ) {
	$settings = npmp_email_get_settings();

	if ( empty( $settings['force_from'] ) ) {
		return $value;
	}

	$name = trim( wp_strip_all_tags( $settings['from_name'] ) );
	return $name ?: $value;
}
add_filter( 'wp_mail_from_name', 'npmp_email_filter_from_name' );

/**
 * Log failed emails.
 *
 * @param WP_Error $error Error object.
 * @return void
 */
function npmp_email_capture_failure( $error ) {
	$data = $error->get_error_data();
	$message = $error->get_error_message();

	if ( is_array( $data ) ) {
		unset( $data['phpmailer_exception_code'] );
	}

	npmp_email_record_result(
		'error',
		array(
			'message' => $message,
			'data'    => $data,
		)
	);
}
add_action( 'wp_mail_failed', 'npmp_email_capture_failure' );

/**
 * Log successful emails (WordPress 5.5+).
 *
 * @param array $mail_data Mail context.
 * @return void
 */
function npmp_email_capture_success( $mail_data ) {
	$settings = npmp_email_get_settings();

	npmp_email_record_result(
		'success',
		array(
			'provider' => $settings['provider'],
			'to'       => $mail_data['to'],
			'subject'  => $mail_data['subject'],
		)
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_mail_succeeded', 'npmp_email_capture_success' );
}

/**
 * Surface admin notice for incomplete SMTP configuration.
 *
 * @return void
 */
function npmp_email_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	$settings = npmp_email_get_settings();

	if ( ! npmp_email_provider_requires_smtp( $settings ) ) {
		return;
	}

	if ( npmp_email_smtp_is_configured( $settings ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Nonprofit Manager email is set to SMTP, but the connection details are incomplete. Messages will fall back to wp_mail until you finish configuring the SMTP settings.', 'nonprofit-manager' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'npmp_email_admin_notices' );
