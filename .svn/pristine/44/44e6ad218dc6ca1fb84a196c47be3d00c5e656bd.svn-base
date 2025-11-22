<?php
/**
 * File path: includes/npmp-email-settings-new.php
 *
 * Simplified Email Settings page for Nonprofit Manager
 */
defined( 'ABSPATH' ) || exit;

/**
 * Render the Email Settings page
 */
function npmp_render_email_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$settings         = npmp_email_get_settings();
	$notices          = array();
	$is_pro           = npmp_is_pro();
	$provider_choices = npmp_email_get_provider_choices( true ); // Always show all providers
	$aws_regions      = npmp_email_get_aws_regions();

	// Ensure settings have default values to prevent null errors
	$settings['from_name']  = $settings['from_name'] ?? get_option( 'blogname' );
	$settings['from_email'] = $settings['from_email'] ?? get_option( 'admin_email' );
	$settings['smtp']['host'] = $settings['smtp']['host'] ?? '';
	$settings['smtp']['port'] = $settings['smtp']['port'] ?? 587;
	$settings['smtp']['username'] = $settings['smtp']['username'] ?? '';
	$settings['smtp']['password'] = $settings['smtp']['password'] ?? '';
	$settings['smtp']['encryption'] = $settings['smtp']['encryption'] ?? 'tls';
	$settings['smtp']['auth'] = $settings['smtp']['auth'] ?? 1;

	// Handle settings save
	if (
		isset( $_POST['npmp_email_settings_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_email_settings_nonce'] ) ), 'npmp_email_settings' )
	) {
		$new_settings = $settings;
		$errors       = array();

		$new_settings['from_name']       = sanitize_text_field( wp_unslash( $_POST['npmp_email_from_name'] ?? '' ) );
		$new_settings['from_email']      = sanitize_email( wp_unslash( $_POST['npmp_email_from_email'] ?? '' ) );
		$new_settings['force_from']      = isset( $_POST['npmp_email_force_from'] ) ? 1 : 0;
		$new_settings['set_return_path'] = isset( $_POST['npmp_email_return_path'] ) ? 1 : 0;

		$provider = sanitize_key( wp_unslash( $_POST['npmp_email_provider'] ?? 'wordpress' ) );

		// Check if trying to use Pro provider without Pro version
		if ( ! $is_pro && ! in_array( $provider, array( 'wordpress', 'smtp' ), true ) ) {
			$errors[] = __( 'The selected email provider requires Nonprofit Manager Pro.', 'nonprofit-manager' );
			$provider = 'wordpress';
		}

		if ( ! array_key_exists( $provider, $provider_choices ) ) {
			$provider = 'wordpress';
		}
		$new_settings['provider'] = $provider;

		$new_settings['smtp']['host']       = sanitize_text_field( wp_unslash( $_POST['npmp_smtp_host'] ?? '' ) );
		$new_settings['smtp']['port']       = absint( wp_unslash( $_POST['npmp_smtp_port'] ?? $new_settings['smtp']['port'] ) );
		$new_settings['smtp']['encryption'] = sanitize_key( wp_unslash( $_POST['npmp_smtp_encryption'] ?? $new_settings['smtp']['encryption'] ) );
		if ( ! in_array( $new_settings['smtp']['encryption'], array( 'none', 'ssl', 'tls' ), true ) ) {
			$new_settings['smtp']['encryption'] = 'tls';
		}
		$new_settings['smtp']['auth']     = isset( $_POST['npmp_smtp_auth'] ) ? 1 : 0;
		$new_settings['smtp']['auto_tls'] = isset( $_POST['npmp_smtp_auto_tls'] ) ? 1 : 0;
		$new_settings['smtp']['username'] = sanitize_text_field( wp_unslash( $_POST['npmp_smtp_username'] ?? '' ) );

		$password_input = isset( $_POST['npmp_smtp_password'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_smtp_password'] ) ) : '';
		if ( '' !== $password_input ) {
			$new_settings['smtp']['password'] = $password_input;
		}

		// Handle Pro provider API keys
		if ( $is_pro ) {
			// AWS SES credentials
			$aws_access = isset( $_POST['npmp_aws_ses_access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_aws_ses_access_key'] ) ) : '';
			if ( '' !== $aws_access ) {
				update_option( 'npmp_aws_ses_access_key', $aws_access );
			}
			$aws_secret = isset( $_POST['npmp_aws_ses_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_aws_ses_secret_key'] ) ) : '';
			if ( '' !== $aws_secret ) {
				update_option( 'npmp_aws_ses_secret_key', $aws_secret );
			}

			// Brevo API key
			$brevo_key = isset( $_POST['npmp_brevo_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_brevo_api_key'] ) ) : '';
			if ( '' !== $brevo_key ) {
				update_option( 'npmp_brevo_api_key', $brevo_key );
			}

			// SendGrid API key
			$sendgrid_key = isset( $_POST['npmp_sendgrid_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_sendgrid_api_key'] ) ) : '';
			if ( '' !== $sendgrid_key ) {
				update_option( 'npmp_sendgrid_api_key', $sendgrid_key );
			}

			// Mailgun API key and domain
			$mailgun_key = isset( $_POST['npmp_mailgun_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_mailgun_api_key'] ) ) : '';
			if ( '' !== $mailgun_key ) {
				update_option( 'npmp_mailgun_api_key', $mailgun_key );
			}
			$mailgun_domain = isset( $_POST['npmp_mailgun_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_mailgun_domain'] ) ) : '';
			if ( '' !== $mailgun_domain ) {
				update_option( 'npmp_mailgun_domain', $mailgun_domain );
			}

			// Postmark API key
			$postmark_key = isset( $_POST['npmp_postmark_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_postmark_api_key'] ) ) : '';
			if ( '' !== $postmark_key ) {
				update_option( 'npmp_postmark_api_key', $postmark_key );
			}

			// SparkPost API key
			$sparkpost_key = isset( $_POST['npmp_sparkpost_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_sparkpost_api_key'] ) ) : '';
			if ( '' !== $sparkpost_key ) {
				update_option( 'npmp_sparkpost_api_key', $sparkpost_key );
			}
		}

		$region = sanitize_key( wp_unslash( $_POST['npmp_aws_region'] ?? $new_settings['aws']['region'] ?? 'us-east-1' ) );
		if ( ! array_key_exists( $region, $aws_regions ) ) {
			$region = 'us-east-1';
		}
		$new_settings['aws']['region'] = $region;

		if ( empty( $new_settings['smtp']['port'] ) ) {
			$new_settings['smtp']['port'] = ( 'ssl' === $new_settings['smtp']['encryption'] ) ? 465 : 587;
		}

		if ( 'aws_ses' === $new_settings['provider'] && empty( $new_settings['smtp']['host'] ) ) {
			$new_settings['smtp']['host'] = npmp_email_get_aws_smtp_host( $region );
		}

		if ( empty( $new_settings['from_email'] ) || ! is_email( $new_settings['from_email'] ) ) {
			$errors[] = __( 'Please provide a valid "From" email address.', 'nonprofit-manager' );
		}

		if ( npmp_email_provider_requires_smtp( $new_settings ) ) {
			if ( '' === trim( $new_settings['smtp']['host'] ) ) {
				$errors[] = __( 'SMTP host is required for this email provider.', 'nonprofit-manager' );
			}
			if ( $new_settings['smtp']['port'] <= 0 ) {
				$errors[] = __( 'SMTP port must be a positive number.', 'nonprofit-manager' );
			}
			if ( $new_settings['smtp']['auth'] ) {
				if ( '' === trim( $new_settings['smtp']['username'] ) ) {
					$errors[] = __( 'SMTP username is required when authentication is enabled.', 'nonprofit-manager' );
				}
				if ( '' === trim( $new_settings['smtp']['password'] ) ) {
					$errors[] = __( 'SMTP password is required when authentication is enabled.', 'nonprofit-manager' );
				}
			}
		}

		if ( empty( $errors ) ) {
			npmp_email_update_settings( $new_settings );

			update_option( 'npmp_email_method', npmp_email_provider_requires_smtp( $new_settings ) ? 'smtp' : 'wp_mail' );
			update_option( 'npmp_email_from_email', $new_settings['from_email'] );
			update_option( 'npmp_email_from_name', $new_settings['from_name'] );
			update_option( 'npmp_smtp_host', $new_settings['smtp']['host'] );
			update_option( 'npmp_smtp_port', $new_settings['smtp']['port'] );
			update_option( 'npmp_smtp_encryption', $new_settings['smtp']['encryption'] );
			update_option( 'npmp_smtp_auth', $new_settings['smtp']['auth'] );
			update_option( 'npmp_smtp_username', $new_settings['smtp']['username'] );
			update_option( 'npmp_smtp_password', $new_settings['smtp']['password'] );

			$settings  = $new_settings;
			$notices[] = array(
				'type'    => 'success',
				'message' => __( 'Email settings saved successfully.', 'nonprofit-manager' ),
			);
		} else {
			$settings  = $new_settings;
			$notices[] = array(
				'type'    => 'error',
				'message' => implode( '<br>', array_map( 'esc_html', $errors ) ),
			);
		}
	}

	// Handle provider credential tests
	if ( $is_pro ) {
		// AWS SES test
		if (
			isset( $_POST['npmp_test_aws_ses_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_aws_ses_nonce'] ) ), 'npmp_test_aws_ses' )
		) {
			$result = function_exists( 'npmp_pro_test_aws_ses' ) ? npmp_pro_test_aws_ses() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=Amazon SES' ) );
			}
			exit;
		}

		// Brevo test
		if (
			isset( $_POST['npmp_test_brevo_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_brevo_nonce'] ) ), 'npmp_test_brevo' )
		) {
			$result = function_exists( 'npmp_pro_test_brevo' ) ? npmp_pro_test_brevo() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=Brevo' ) );
			}
			exit;
		}

		// SendGrid test
		if (
			isset( $_POST['npmp_test_sendgrid_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_sendgrid_nonce'] ) ), 'npmp_test_sendgrid' )
		) {
			$result = function_exists( 'npmp_pro_test_sendgrid' ) ? npmp_pro_test_sendgrid() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=SendGrid' ) );
			}
			exit;
		}

		// Mailgun test
		if (
			isset( $_POST['npmp_test_mailgun_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_mailgun_nonce'] ) ), 'npmp_test_mailgun' )
		) {
			$result = function_exists( 'npmp_pro_test_mailgun' ) ? npmp_pro_test_mailgun() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=Mailgun' ) );
			}
			exit;
		}

		// Postmark test
		if (
			isset( $_POST['npmp_test_postmark_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_postmark_nonce'] ) ), 'npmp_test_postmark' )
		) {
			$result = function_exists( 'npmp_pro_test_postmark' ) ? npmp_pro_test_postmark() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=Postmark' ) );
			}
			exit;
		}

		// SparkPost test
		if (
			isset( $_POST['npmp_test_sparkpost_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_sparkpost_nonce'] ) ), 'npmp_test_sparkpost' )
		) {
			$result = function_exists( 'npmp_pro_test_sparkpost' ) ? npmp_pro_test_sparkpost() : false;
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=error&provider_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_email_settings&provider_test=success&provider_name=SparkPost' ) );
			}
			exit;
		}
	}

	// Handle test email
	$test_result = '';
	if (
		isset( $_POST['npmp_test_email_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_email_nonce'] ) ), 'npmp_test_email' )
	) {
		$to      = sanitize_email( wp_unslash( $_POST['npmp_test_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['npmp_test_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['npmp_test_message'] ?? '' ) );

		if ( $to && $subject && $message ) {
			delete_option( 'npmp_email_last_error' );
			add_action(
				'wp_mail_failed',
				static function ( $wp_error ) {
					update_option( 'npmp_email_last_error', $wp_error );
				}
			);

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$success = wp_mail( $to, $subject, nl2br( $message ), $headers );

			if ( $success ) {
				$test_result = '<div class="notice notice-success"><p>' . esc_html__( 'Test email sent successfully!', 'nonprofit-manager' ) . '</p></div>';
			} else {
				$error_msg = esc_html__( 'Failed to send test email.', 'nonprofit-manager' );
				if ( $err = get_option( 'npmp_email_last_error' ) ) {
					$err_details = is_wp_error( $err ) ? $err->get_error_message() : wp_json_encode( $err );
					$error_msg  .= '<br><code style="display:block;margin-top:10px;background:#f5f5f5;padding:10px;border-left:3px solid #dc3232;">' . esc_html( $err_details ) . '</code>';
				}
				$test_result = '<div class="notice notice-error"><p>' . wp_kses_post( $error_msg ) . '</p></div>';
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Email Settings', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Configure how Nonprofit Manager sends emails.', 'nonprofit-manager' ); ?></p>

		<?php foreach ( $notices as $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
				<p><?php echo wp_kses_post( $notice['message'] ); ?></p>
			</div>
		<?php endforeach; ?>

		<?php
		// Display provider test results
		if ( isset( $_GET['provider_test'] ) && 'success' === $_GET['provider_test'] && isset( $_GET['provider_name'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html( sprintf( __( '%s credentials are valid and working!', 'nonprofit-manager' ), sanitize_text_field( wp_unslash( $_GET['provider_name'] ) ) ) ); ?></p>
			</div>
			<?php
		}
		if ( isset( $_GET['provider_test'] ) && 'error' === $_GET['provider_test'] && isset( $_GET['provider_message'] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['provider_message'] ) ) ); ?></p>
			</div>
			<?php
		}
		?>

		<style>
			.npmp-email-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
				max-width: 800px;
			}
			.npmp-email-card h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #c3c4c7;
				font-size: 18px;
			}
			.npmp-email-card .form-table {
				margin-top: 15px;
			}
			.npmp-email-card .form-table th {
				width: 180px;
				padding: 10px 10px 10px 0;
				font-weight: 600;
			}
			.npmp-email-card .form-table td {
				padding: 10px 10px;
			}
			.npmp-email-card hr {
				margin: 20px 0;
				border: none;
				border-top: 1px solid #e0e0e0;
			}
			.npmp-provider-help {
				background: #f0f6fc;
				border-left: 3px solid #2271b1;
				padding: 12px 15px;
				margin-top: 15px;
			}
			.npmp-provider-help a {
				font-weight: 600;
			}
			.npmp-upgrade-notice {
				background: #fff9e6;
				border-left: 3px solid #ffb900;
				padding: 12px 15px;
				margin: 15px 0;
			}
			@media screen and (max-width: 782px) {
				.npmp-email-card .form-table th {
					width: 100%;
					padding-bottom: 0;
				}
			}
		</style>

		<form method="post">
			<?php wp_nonce_field( 'npmp_email_settings', 'npmp_email_settings_nonce' ); ?>

			<!-- Public Email Sender Settings -->
			<div class="npmp-email-card">
				<h2><?php esc_html_e( 'Public Email Sender Settings', 'nonprofit-manager' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="npmp_email_from_name"><?php esc_html_e( 'Email "From" Name', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp_email_from_name" name="npmp_email_from_name" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>">
							<p class="description"><?php esc_html_e( 'This name appears in the "From" field of outgoing emails.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="npmp_email_from_email"><?php esc_html_e( 'Email "From" Address', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="email" id="npmp_email_from_email" name="npmp_email_from_email" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'Use an email address authorized by your mail provider.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Email System Settings -->
			<div class="npmp-email-card">
				<h2><?php esc_html_e( 'Email System Settings', 'nonprofit-manager' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="npmp_email_provider"><?php esc_html_e( 'Delivery Provider', 'nonprofit-manager' ); ?></label></th>
						<td>
							<select name="npmp_email_provider" id="npmp_email_provider" <?php disabled( ! $is_pro ); ?>>
								<?php foreach ( $provider_choices as $value => $label ) : ?>
									<?php $is_pro_provider = ( 'wordpress' !== $value && ! $is_pro ); ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['provider'], $value ); ?> <?php disabled( $is_pro_provider ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( ! $is_pro ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to upgrade page */
										wp_kses_post( __( 'Want to use a custom email service? <a href="%s" target="_blank">Upgrade to Nonprofit Manager Pro</a> to unlock all email providers.', 'nonprofit-manager' ) ),
										esc_url( npmp_get_upgrade_url() )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<!-- WordPress Default Provider -->
				<div class="npmp-provider-block npmp-provider-wordpress" <?php echo 'wordpress' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
					<div class="npmp-upgrade-notice">
						<p style="margin: 0;">
							<strong><?php esc_html_e( 'Using WordPress Default (wp_mail)', 'nonprofit-manager' ); ?></strong><br>
							<?php esc_html_e( 'Emails are being sent using the default WordPress wp_mail function, which is not recommended, particularly if you\'re on a shared website hosting server, as the emails may be flagged as spam and not make it to your users.', 'nonprofit-manager' ); ?>
							<?php if ( ! $is_pro ) : ?>
								<?php
								printf(
									/* translators: %s: URL to upgrade page */
									wp_kses_post( __( ' Upgrading to <a href="%s" target="_blank">Nonprofit Manager Pro</a> allows you to use a custom SMTP server or link directly to popular email services.', 'nonprofit-manager' ) ),
									esc_url( npmp_get_upgrade_url() )
								);
								?>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<!-- Custom SMTP Provider -->
				<div class="npmp-provider-block npmp-provider-smtp" <?php echo 'smtp' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
					<hr>
					<h3 style="margin-top: 20px;"><?php esc_html_e( 'Custom SMTP Configuration', 'nonprofit-manager' ); ?></h3>

					<table class="form-table" style="margin-top: 15px;">
						<tr>
							<th><label for="npmp_smtp_host"><?php esc_html_e( 'SMTP Host', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_smtp_host" name="npmp_smtp_host" class="regular-text" value="<?php echo esc_attr( $settings['smtp']['host'] ); ?>" placeholder="smtp.example.com">
							</td>
						</tr>
						<tr>
							<th><label for="npmp_smtp_port"><?php esc_html_e( 'SMTP Port', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="number" id="npmp_smtp_port" name="npmp_smtp_port" class="small-text" value="<?php echo esc_attr( $settings['smtp']['port'] ); ?>">
								<p class="description"><?php esc_html_e( 'Common: 587 (TLS) or 465 (SSL)', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Encryption', 'nonprofit-manager' ); ?></th>
							<td>
								<select name="npmp_smtp_encryption">
									<option value="tls" <?php selected( $settings['smtp']['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS (recommended)', 'nonprofit-manager' ); ?></option>
									<option value="ssl" <?php selected( $settings['smtp']['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'nonprofit-manager' ); ?></option>
									<option value="none" <?php selected( $settings['smtp']['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'nonprofit-manager' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="npmp_smtp_username"><?php esc_html_e( 'Username', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_smtp_username" name="npmp_smtp_username" class="regular-text" value="<?php echo esc_attr( $settings['smtp']['username'] ); ?>">
							</td>
						</tr>
						<tr>
							<th><label for="npmp_smtp_password"><?php esc_html_e( 'Password', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_smtp_password" name="npmp_smtp_password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['smtp']['password'] ? '••••••••' : ''; ?>">
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Authentication', 'nonprofit-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="npmp_smtp_auth" value="1" <?php checked( ! empty( $settings['smtp']['auth'] ) ); ?>>
									<?php esc_html_e( 'My SMTP server requires authentication (usually yes)', 'nonprofit-manager' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<!-- Pro API Providers -->
				<?php if ( $is_pro ) : ?>
					<!-- AWS SES -->
					<div class="npmp-provider-block npmp-provider-aws_ses" <?php echo 'aws_ses' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'Amazon SES API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'aws_ses' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_aws_region"><?php esc_html_e( 'AWS Region', 'nonprofit-manager' ); ?></label></th>
								<td>
									<select name="npmp_aws_region" id="npmp_aws_region" class="regular-text">
										<?php foreach ( $aws_regions as $region_key => $region_name ) : ?>
											<option value="<?php echo esc_attr( $region_key ); ?>" <?php selected( $settings['aws']['region'], $region_key ); ?>>
												<?php echo esc_html( $region_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Select the AWS region where your SES service is configured.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="npmp_aws_ses_access_key"><?php esc_html_e( 'Access Key ID', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_aws_ses_access_key" name="npmp_aws_ses_access_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_aws_ses_access_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your AWS Access Key ID. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="npmp_aws_ses_secret_key"><?php esc_html_e( 'Secret Access Key', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_aws_ses_secret_key" name="npmp_aws_ses_secret_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_aws_ses_secret_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your AWS Secret Access Key. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>

					<!-- Brevo -->
					<div class="npmp-provider-block npmp-provider-brevo" <?php echo 'brevo' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'Brevo (Sendinblue) API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'brevo' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_brevo_api_key"><?php esc_html_e( 'API Key', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_brevo_api_key" name="npmp_brevo_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_brevo_api_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your Brevo v3 API key. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>

					<!-- SendGrid -->
					<div class="npmp-provider-block npmp-provider-sendgrid" <?php echo 'sendgrid' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'SendGrid API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'sendgrid' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_sendgrid_api_key"><?php esc_html_e( 'API Key', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_sendgrid_api_key" name="npmp_sendgrid_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_sendgrid_api_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your SendGrid API key with Mail Send permission. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>

					<!-- Mailgun -->
					<div class="npmp-provider-block npmp-provider-mailgun" <?php echo 'mailgun' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'Mailgun API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'mailgun' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_mailgun_api_key"><?php esc_html_e( 'API Key', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_mailgun_api_key" name="npmp_mailgun_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_mailgun_api_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your Mailgun API key. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="npmp_mailgun_domain"><?php esc_html_e( 'Domain', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="text" id="npmp_mailgun_domain" name="npmp_mailgun_domain" class="regular-text" value="<?php echo esc_attr( get_option( 'npmp_mailgun_domain', '' ) ); ?>" placeholder="mg.yourdomain.com">
									<p class="description"><?php esc_html_e( 'Enter your Mailgun sending domain.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>

					<!-- Postmark -->
					<div class="npmp-provider-block npmp-provider-postmark" <?php echo 'postmark' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'Postmark API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'postmark' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_postmark_api_key"><?php esc_html_e( 'Server API Token', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_postmark_api_key" name="npmp_postmark_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_postmark_api_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your Postmark Server API Token. Leave blank to keep existing token.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>

					<!-- SparkPost -->
					<div class="npmp-provider-block npmp-provider-sparkpost" <?php echo 'sparkpost' === $settings['provider'] ? '' : 'style="display:none;"'; ?>>
						<hr>
						<h3 style="margin-top: 20px;"><?php esc_html_e( 'SparkPost API Configuration', 'nonprofit-manager' ); ?></h3>
						<?php
						if ( function_exists( 'npmp_pro_get_provider_help' ) ) {
							$help_url = npmp_pro_get_provider_help( 'sparkpost' );
							if ( ! empty( $help_url ) ) {
								?>
								<div class="npmp-provider-help">
									<p style="margin: 0;">
										<a href="<?php echo esc_url( $help_url ); ?>" target="_blank"><?php esc_html_e( 'Get your credentials here', 'nonprofit-manager' ); ?> &rarr;</a>
									</p>
								</div>
								<?php
							}
						}
						?>
						<table class="form-table" style="margin-top: 15px;">
							<tr>
								<th><label for="npmp_sparkpost_api_key"><?php esc_html_e( 'API Key', 'nonprofit-manager' ); ?></label></th>
								<td>
									<input type="password" id="npmp_sparkpost_api_key" name="npmp_sparkpost_api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_sparkpost_api_key', '' ) ? '••••••••' : ''; ?>">
									<p class="description"><?php esc_html_e( 'Enter your SparkPost API key with Transmissions permission. Leave blank to keep existing key.', 'nonprofit-manager' ); ?></p>
								</td>
							</tr>
						</table>
</div>
				<?php endif; ?>

				<?php submit_button( __( 'Save Email Settings', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
			</div>
		</form>

		<!-- Test Email Section -->
		<div class="npmp-email-card">
			<h2><?php esc_html_e( 'Send Test Email', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Send a test email to verify your settings are working correctly.', 'nonprofit-manager' ); ?></p>

			<?php echo wp_kses_post( $test_result ); ?>

			<form method="post">
				<?php wp_nonce_field( 'npmp_test_email', 'npmp_test_email_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="npmp_test_email"><?php esc_html_e( 'Recipient Email', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="email" id="npmp_test_email" name="npmp_test_email" class="regular-text" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
						</td>
					</tr>
					<tr>
						<th><label for="npmp_test_subject"><?php esc_html_e( 'Subject', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp_test_subject" name="npmp_test_subject" class="regular-text" value="<?php echo esc_attr__( 'Test Email from Nonprofit Manager', 'nonprofit-manager' ); ?>" required>
						</td>
					</tr>
					<tr>
						<th><label for="npmp_test_message"><?php esc_html_e( 'Message', 'nonprofit-manager' ); ?></label></th>
						<td>
							<textarea id="npmp_test_message" name="npmp_test_message" rows="4" class="large-text" required><?php echo esc_textarea( __( "This is a test email sent from Nonprofit Manager.\n\nIf you're receiving this, your email settings are working correctly!", 'nonprofit-manager' ) ); ?></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Send Test Email', 'nonprofit-manager' ), 'secondary' ); ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#npmp_email_provider').on('change', function() {
				var provider = $(this).val();
				$('.npmp-provider-block').hide();

				if (provider === 'wordpress') {
					$('.npmp-provider-wordpress').show();
				} else if (provider === 'smtp') {
					$('.npmp-provider-smtp').show();
				} else {
					// Show provider-specific API configuration
					$('.npmp-provider-' + provider).show();
				}
			});
		});
		</script>
	</div>
	<?php
}
class NPMP_Member_Manager {

	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function get_instance() {
		return self::$instance ?: ( self::$instance = new self() );
	}

	/**
	 * Map logical member fields to stored post meta keys.
	 *
	 * @return array
	 */
	private function meta_keys() {
		return array(
			'email'            => 'npmp_email',
			'membership_level' => 'npmp_membership_level',
			'status'           => 'npmp_status',
			'phone'            => 'npmp_phone',
			'mobile'           => 'npmp_mobile',
			'address_line1'    => 'npmp_address_line1',
			'address_line2'    => 'npmp_address_line2',
			'city'             => 'npmp_city',
			'state'            => 'npmp_state',
			'postal_code'      => 'npmp_postal_code',
			'country'          => 'npmp_country',
			'tags'             => 'npmp_tags',
			'source'           => 'npmp_source',
			'last_contacted'   => 'npmp_last_contacted',
			'notes'            => 'npmp_notes',
			'last_donation_at' => 'npmp_last_donation_at',
			'donation_count'   => 'npmp_donation_count',
			'donation_total'   => 'npmp_donation_total',
		);
	}

	/**
	 * Allowed columns for updates.
	 *
	 * @return array
	 */
	private function allowed_columns() {
		return array(
			'name',
			'email',
			'membership_level',
			'status',
			'phone',
			'mobile',
			'address_line1',
			'address_line2',
			'city',
			'state',
			'postal_code',
			'country',
			'tags',
			'source',
			'last_contacted',
			'notes',
			'last_donation_at',
			'donation_count',
			'donation_total',
		);
	}

	/**
	 * Available member statuses.
	 *
	 * @return array
	 */
	public function get_statuses() {
		return array(
			'subscribed'   => __( 'Active', 'nonprofit-manager' ),
			'pending'      => __( 'Pending', 'nonprofit-manager' ),
			'donor'        => __( 'Donor', 'nonprofit-manager' ),
			'volunteer'    => __( 'Volunteer', 'nonprofit-manager' ),
			'partner'      => __( 'Partner', 'nonprofit-manager' ),
			'inactive'     => __( 'Inactive', 'nonprofit-manager' ),
			'unsubscribed' => __( 'Unsubscribed', 'nonprofit-manager' ),
		);
	}

	/**
	 * Normalise a status value.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function normalize_status( $status ) {
		$status  = sanitize_key( $status );
		$choices = $this->get_statuses();
		if ( array_key_exists( $status, $choices ) ) {
			return $status;
		}
		return 'subscribed';
	}

	/**
	 * Normalise a tag string.
	 *
	 * @param string|array $tags Raw tags.
	 * @return string
	 */
	private function normalize_tags( $tags ) {
		if ( is_array( $tags ) ) {
			$tags = implode( ',', $tags );
		}
		$list = array_filter(
			array_map(
				static function ( $tag ) {
					$tag = trim( $tag );
					if ( '' === $tag ) {
						return '';
					}
					return sanitize_text_field( $tag );
				},
				preg_split( '/[,;]/', (string) $tags )
			)
		);

		$list = array_unique( $list );
		return implode( ',', $list );
	}

	/**
	 * Sanitise incoming member data.
	 *
	 * @param array  $data    Raw data.
	 * @param string $context create|update|system.
	 * @return array
	 */
	private function sanitize_member_data( $data, $context = 'update' ) {
		$clean   = array();
		$allowed = $this->allowed_columns();

		foreach ( (array) $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			switch ( $key ) {
				case 'email':
					$value = sanitize_email( $value );
					if ( empty( $value ) ) {
						continue 2;
					}
					break;
				case 'notes':
					$value = wp_kses_post( $value );
					break;
				case 'tags':
					$value = $this->normalize_tags( $value );
					break;
				case 'status':
					$value = $this->normalize_status( $value );
					break;
				case 'donation_total':
					$value = floatval( $value );
					break;
				case 'donation_count':
					$value = intval( $value );
					break;
				case 'last_contacted':
				case 'last_donation_at':
					$value = $value ? gmdate( 'Y-m-d H:i:s', strtotime( $value ) ) : null;
					break;
				default:
					$value = sanitize_text_field( $value );
					break;
			}

			$clean[ $key ] = $value;
		}

		if ( 'create' === $context ) {
			if ( ! isset( $clean['status'] ) ) {
				$clean['status'] = 'subscribed';
			}
		}

		return $clean;
	}

	/**
	 * Build a member object from a post.
	 *
	 * @param WP_Post $post Contact post.
	 * @return object
	 */
	private function hydrate_member_from_post( $post ) {
		$meta_keys = $this->meta_keys();
		$meta      = array();

		foreach ( $meta_keys as $property => $meta_key ) {
			$meta[ $property ] = get_post_meta( $post->ID, $meta_key, true );
		}

		$member = (object) array(
			'id'               => $post->ID,
			'name'             => $post->post_title,
			'email'            => isset( $meta['email'] ) ? sanitize_email( $meta['email'] ) : '',
			'membership_level' => isset( $meta['membership_level'] ) ? sanitize_text_field( $meta['membership_level'] ) : '',
			'status'           => isset( $meta['status'] ) ? $this->normalize_status( $meta['status'] ) : 'subscribed',
			'phone'            => isset( $meta['phone'] ) ? $meta['phone'] : '',
			'mobile'           => isset( $meta['mobile'] ) ? $meta['mobile'] : '',
			'address_line1'    => isset( $meta['address_line1'] ) ? $meta['address_line1'] : '',
			'address_line2'    => isset( $meta['address_line2'] ) ? $meta['address_line2'] : '',
			'city'             => isset( $meta['city'] ) ? $meta['city'] : '',
			'state'            => isset( $meta['state'] ) ? $meta['state'] : '',
			'postal_code'      => isset( $meta['postal_code'] ) ? $meta['postal_code'] : '',
			'country'          => isset( $meta['country'] ) ? $meta['country'] : '',
			'tags'             => isset( $meta['tags'] ) ? $meta['tags'] : '',
			'source'           => isset( $meta['source'] ) ? $meta['source'] : '',
			'last_contacted'   => isset( $meta['last_contacted'] ) ? $meta['last_contacted'] : '',
			'notes'            => isset( $meta['notes'] ) ? $meta['notes'] : '',
			'last_donation_at' => isset( $meta['last_donation_at'] ) ? $meta['last_donation_at'] : '',
			'donation_count'   => isset( $meta['donation_count'] ) ? (int) $meta['donation_count'] : 0,
			'donation_total'   => isset( $meta['donation_total'] ) ? (float) $meta['donation_total'] : 0.0,
			'created_at'       => $post->post_date,
			'updated_at'       => $post->post_modified,
		);

		if ( empty( $member->name ) && $member->email ) {
			$member->name = $member->email;
		}

		return $member;
	}

	/**
	 * Store a member in the object cache.
	 *
	 * @param object $member Member object.
	 * @return void
	 */
	private function cache_member( $member ) {
		if ( ! $member || empty( $member->id ) ) {
			return;
		}

		wp_cache_set( 'npmp_member_' . $member->id, $member, 'npmp_members', 300 );

		if ( ! empty( $member->email ) ) {
			wp_cache_set( 'npmp_member_email_' . strtolower( $member->email ), $member, 'npmp_members', 300 );
		}
	}

	/**
	 * Clear cached member data.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $email     Email address.
	 * @return void
	 */
	private function clear_cache( $member_id = 0, $email = '' ) {
		if ( $member_id ) {
			wp_cache_delete( 'npmp_member_' . $member_id, 'npmp_members' );
		}
		if ( $email ) {
			wp_cache_delete( 'npmp_member_email_' . strtolower( $email ), 'npmp_members' );
		}
		wp_cache_delete( 'npmp_members_all', 'npmp_members' );
	}

	/**
	 * Build WP_Query arguments for member lookups.
	 *
	 * @param array $args Raw arguments.
	 * @return array
	 */
	private function build_query_args( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'level'    => '',
				'tag'      => '',
				'search'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'paged'    => 1,
				'per_page' => 20,
			)
		);

		$per_page = (int) $args['per_page'];
		if ( 0 === $per_page ) {
			$per_page = 20;
		}

		$query = array(
			'post_type'      => 'npmp_contact',
			'post_status'    => array( 'publish' ),
			'paged'          => max( 1, absint( $args['paged'] ) ),
			'posts_per_page' => $per_page,
			'no_found_rows'  => false,
		);

		if ( -1 === $per_page ) {
			$query['posts_per_page'] = -1;
			$query['no_found_rows']  = true;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CRM export filters rely on post meta values.
		$meta_query = array( 'relation' => 'AND' );

		if ( $args['status'] && 'all' !== $args['status'] ) {
			$meta_query[] = array(
				'key'   => 'npmp_status',
				'value' => $this->normalize_status( $args['status'] ),
				'compare' => '=',
			);
		}

		if ( $args['level'] ) {
			$meta_query[] = array(
				'key'   => 'npmp_membership_level',
				'value' => sanitize_text_field( $args['level'] ),
				'compare' => '=',
			);
		}

		if ( $args['tag'] ) {
			$meta_query[] = array(
				'key'     => 'npmp_tags',
				'value'   => sanitize_text_field( $args['tag'] ),
				'compare' => 'LIKE',
			);
		}

		if ( $args['search'] ) {
			$query['s'] = $args['search'];
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'npmp_email',
					'value'   => $args['search'],
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'npmp_tags',
					'value'   => $args['search'],
					'compare' => 'LIKE',
				),
			);
		}

		if ( count( $meta_query ) > 1 ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CRM export filters rely on post meta values.
			$query['meta_query'] = $meta_query;
		}

		$orderby = sanitize_key( $args['orderby'] );
		$order   = strtoupper( $args['order'] );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		switch ( $orderby ) {
			case 'name':
				$query['orderby'] = 'title';
				break;
			case 'status':
				$query['orderby']  = 'meta_value';
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by status relies on member metadata.
				$query['meta_key'] = 'npmp_status';
				break;
			case 'membership_level':
				$query['orderby']  = 'meta_value';
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by membership level relies on member metadata.
				$query['meta_key'] = 'npmp_membership_level';
				break;
			case 'donation_total':
				$query['orderby']  = 'meta_value_num';
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by donation total relies on member metadata.
				$query['meta_key'] = 'npmp_donation_total';
				break;
			case 'last_donation_at':
				$query['orderby']   = 'meta_value';
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting by last donation timestamp relies on member metadata.
				$query['meta_key']  = 'npmp_last_donation_at';
				$query['meta_type'] = 'DATETIME';
				break;
			case 'created_at':
			default:
				$query['orderby'] = 'date';
				break;
		}

		$query['order'] = $order;

		return $query;
	}

	/**
	 * Retrieve a member by ID.
	 *
	 * @param int $id Member ID.
	 * @return object|null
	 */
	public function get_member_by_id( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$cache_key = 'npmp_member_' . $id;
		$member    = wp_cache_get( $cache_key, 'npmp_members' );

		if ( false !== $member ) {
			return $member;
		}

		$post = get_post( $id );
		if ( ! $post || 'npmp_contact' !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$member = $this->hydrate_member_from_post( $post );
		$this->cache_member( $member );

		return $member;
	}

	/**
	 * Retrieve a member by email address.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public function get_member_by_email( $email ) {
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return null;
		}

		$cache_key = 'npmp_member_email_' . strtolower( $email );
		$member    = wp_cache_get( $cache_key, 'npmp_members' );

		if ( false !== $member ) {
			return $member;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'npmp_contact',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Member lookup by email depends on meta storage.
				'meta_query'     => array(
					array(
						'key'   => 'npmp_email',
						'value' => $email,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$member = $this->hydrate_member_from_post( $query->posts[0] );
		$this->cache_member( $member );

		return $member;
	}

	/**
	 * Determine if an email already exists.
	 *
	 * @param string $email Email address.
	 * @return bool
	 */
	public function email_exists( $email ) {
		return (bool) $this->get_member_by_email( $email );
	}

	/**
	 * Add a new member.
	 *
	 * @param array $data Member data.
	 * @return int|WP_Error
	 */
	public function add_member( $data ) {
		$clean = $this->sanitize_member_data( $data, 'create' );

		 if ( empty( $clean['email'] ) ) {
			return new WP_Error( 'npmp_missing_email', __( 'An email address is required for every contact.', 'nonprofit-manager' ) );
		 }

		if ( $this->email_exists( $clean['email'] ) ) {
			return new WP_Error( 'npmp_member_exists', __( 'A contact with this email already exists.', 'nonprofit-manager' ) );
		}

		$clean = wp_parse_args(
			$clean,
			array(
				'name'             => '',
				'membership_level' => '',
				'status'           => 'subscribed',
				'source'           => 'manual',
			)
		);

		$name = $clean['name'];
		unset( $clean['name'] );

		$meta_keys  = $this->meta_keys();
		$meta_input = array();

		foreach ( $clean as $property => $value ) {
			if ( ! array_key_exists( $property, $meta_keys ) ) {
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			$meta_input[ $meta_keys[ $property ] ] = $value;
		}

		$postarr = array(
			'post_type'   => 'npmp_contact',
			'post_status' => 'publish',
			'post_title'  => $name ? $name : $clean['email'],
			'meta_input'  => $meta_input,
		);

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$member = $this->get_member_by_id( $post_id );
		$this->cache_member( $member );

		return $post_id;
	}

	/**
	 * Update an existing member.
	 *
	 * @param int   $id   Member ID.
	 * @param array $data Data to update.
	 * @return bool|WP_Error
	 */
	public function update_member( $id, $data ) {
		$member = $this->get_member_by_id( $id );
		if ( ! $member ) {
			return new WP_Error( 'npmp_member_not_found', __( 'Contact not found.', 'nonprofit-manager' ) );
		}

		$clean = $this->sanitize_member_data( $data, 'update' );

		if ( isset( $clean['email'] ) && $clean['email'] && $clean['email'] !== $member->email && $this->email_exists( $clean['email'] ) ) {
			return new WP_Error( 'npmp_member_exists', __( 'Another contact already uses that email address.', 'nonprofit-manager' ) );
		}

		if ( empty( $clean ) ) {
			return true;
		}

		$dirty_email = $member->email;
		$post_update = array( 'ID' => $member->id );

		if ( isset( $clean['name'] ) ) {
			$post_update['post_title'] = $clean['name'] ? $clean['name'] : ( $clean['email'] ?? $member->email );
			unset( $clean['name'] );
		}

		if ( count( $post_update ) > 1 ) {
			$result = wp_update_post( $post_update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$meta_keys = $this->meta_keys();

		foreach ( $meta_keys as $property => $meta_key ) {
			if ( ! array_key_exists( $property, $clean ) ) {
				continue;
			}

			$value = $clean[ $property ];

			if ( null === $value || '' === $value ) {
				delete_post_meta( $member->id, $meta_key );
			} else {
				update_post_meta( $member->id, $meta_key, $value );
			}
		}

		$new_email = $clean['email'] ?? $member->email;
		$this->clear_cache( $member->id, $dirty_email );
		$this->cache_member( $this->get_member_by_id( $member->id ) );

		return true;
	}

	/**
	 * Upsert a member record using the email address.
	 *
	 * @param array $data Member data.
	 * @return int|WP_Error
	 */
	public function upsert_member( $data ) {
		$email = sanitize_email( $data['email'] ?? '' );
		if ( ! $email ) {
			return new WP_Error( 'npmp_missing_email', __( 'An email address is required.', 'nonprofit-manager' ) );
		}

		$existing = $this->get_member_by_email( $email );
		if ( $existing ) {
			$result = $this->update_member( $existing->id, $data );
			return is_wp_error( $result ) ? $result : $existing->id;
		}

		return $this->add_member( $data );
	}

	/**
	 * Delete a member permanently.
	 *
	 * @param int $id Member ID.
	 * @return int|false
	 */
	public function delete_member( $id ) {
		$member = $this->get_member_by_id( $id );
		if ( ! $member ) {
			return false;
		}

		$result = wp_delete_post( $member->id, true );
		if ( ! $result ) {
			return false;
		}

		$this->clear_cache( $member->id, $member->email );

		return 1;
	}

	/**
	 * Delete multiple members.
	 *
	 * @param array $ids Member IDs.
	 * @return int
	 */
	public function delete_members( $ids ) {
		$deleted = 0;
		foreach ( (array) $ids as $id ) {
			$deleted += (int) $this->delete_member( $id );
		}
		return $deleted;
	}

	/**
	 * Return all members.
	 *
	 * @return array
	 */
	public function get_all_members() {
		return $this->get_members( array( 'per_page' => -1 ) );
	}

	/**
	 * Retrieve members with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function get_members( $args = array() ) {
		$query_args = $this->build_query_args( $args );
		$query      = new WP_Query( $query_args );
		$members    = array();

		foreach ( $query->posts as $post ) {
			$member = $this->hydrate_member_from_post( $post );
			$this->cache_member( $member );
			$members[] = $member;
		}

		return $members;
	}

	/**
	 * Count members matching the specified filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public function count_members( $args = array() ) {
		$query_args = $this->build_query_args( $args );
		$query_args['posts_per_page'] = 1;
		$query_args['no_found_rows']  = false;
		$query_args['fields']         = 'ids';

		$query = new WP_Query( $query_args );

		return (int) $query->found_posts;
	}

	/**
	 * Count members per segment.
	 *
	 * @return array
	 */
	public function count_by_level() {
		$members = $this->get_members(
			array(
				'per_page' => -1,
			)
		);

		$count = array();

		foreach ( $members as $member ) {
			$level = isset( $member->membership_level ) ? $member->membership_level : '';
			$key   = $level ? $level : '';
			if ( ! isset( $count[ $key ] ) ) {
				$count[ $key ] = 0;
			}
			$count[ $key ] ++;
		}

		return $count;
	}

	/**
	 * Status summary used on the dashboard.
	 *
	 * @return array
	 */
	public function get_status_counts() {
		$query = new WP_Query(
			array(
				'post_type'      => 'npmp_contact',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$stats = array();

		foreach ( $query->posts as $post_id ) {
			$status = get_post_meta( $post_id, 'npmp_status', true );
			if ( ! $status ) {
				$status = 'subscribed';
			}
			if ( ! isset( $stats[ $status ] ) ) {
				$stats[ $status ] = 0;
			}
			$stats[ $status ] ++;
		}

		foreach ( $this->get_statuses() as $key => $label ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			if ( ! isset( $stats[ $key ] ) ) {
				$stats[ $key ] = 0;
			}
		}

		return $stats;
	}

	/**
	 * Count members by tier/level.
	 *
	 * @param string $tier Tier name.
	 * @return int
	 */
	public function count_members_by_tier( $tier ) {
		$args = array(
			'post_type'      => 'npmp_contact',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for filtering by membership level.
			'meta_query'     => array(
				array(
					'key'   => 'npmp_membership_level',
					'value' => $tier,
				),
			),
		);

		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Retrieve a list of tags.
	 *
	 * @return array
	 */
	public function get_tags_list() {
		$members = $this->get_members(
			array(
				'per_page' => -1,
			)
		);

		$tags = array();

		foreach ( $members as $member ) {
			if ( empty( $member->tags ) ) {
				continue;
			}

			foreach ( explode( ',', (string) $member->tags ) as $tag ) {
				$tag = trim( $tag );
				if ( '' !== $tag ) {
					$tags[ $tag ] = $tag;
				}
			}
		}

		ksort( $tags );

		return array_values( $tags );
	}

	/**
	 * Update the last contacted timestamp.
	 *
	 * @param int         $member_id Member ID.
	 * @param string|null $timestamp Timestamp.
	 * @return void
	 */
	public function set_last_contacted( $member_id, $timestamp = null ) {
		$timestamp = $timestamp ? gmdate( 'Y-m-d H:i:s', strtotime( $timestamp ) ) : current_time( 'mysql' );
		$this->update_member(
			$member_id,
			array(
				'last_contacted' => $timestamp,
			)
		);
	}

	/**
	 * Retrieve high-level financial metrics from the donations table.
	 *
	 * @return array
	 */
	public function get_financial_overview() {
		$manager    = class_exists( 'NPMP_Donation_Manager' ) ? NPMP_Donation_Manager::get_instance() : null;
		$donations  = $manager ? $manager->get_all_donations() : array();
		$total      = 0.0;
		$count      = 0;
		$recent     = 0.0;
		$cutoff     = strtotime( '-30 days' );

		foreach ( $donations as $donation_post ) {
			$amount = (float) get_post_meta( $donation_post->ID, NPMP_Donation_Manager::META_AMOUNT, true );
			if ( $amount <= 0 ) {
				continue;
			}

			$count ++;
			$total += $amount;

			$timestamp = get_post_time( 'U', true, $donation_post );
			if ( $timestamp >= $cutoff ) {
				$recent += $amount;
			}
		}

		return array(
			'total_amount'       => $total,
			'total_transactions' => $count,
			'thirty_day_amount'  => $recent,
		);
	}

	/**
	 * Count members who have recorded donations.
	 *
	 * @return int
	 */
	public function count_donors() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT COUNT(*)
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND CAST(pm.meta_value AS UNSIGNED) > 0
			   AND p.post_type = %s
			   AND p.post_status = %s",
			'npmp_donation_count',
			'npmp_contact',
			'publish'
		 );

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Retrieve recently active donors.
	 *
	 * @param int $limit Number of donors.
	 * @return array
	 */
	public function get_recent_donors( $limit = 5 ) {
		$limit = absint( $limit );
		if ( ! $limit ) {
			$limit = 5;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'npmp_contact',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Recent donors list is ordered by donation timestamp meta.
				'meta_key'       => 'npmp_last_donation_at',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering donors by donation count relies on meta data.
				'meta_query'     => array(
					array(
						'key'     => 'npmp_donation_count',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$donors = array();

		foreach ( $query->posts as $post ) {
			$member = $this->hydrate_member_from_post( $post );
			$this->cache_member( $member );
			$donors[] = $member;
		}

		return $donors;
	}

	/**
	 * Record a donation against the CRM.
	 *
	 * @param array $donation Donation data.
	 * @return void
	 */
	public function record_donation( $donation ) {
		$email = sanitize_email( $donation['email'] ?? '' );
		if ( ! $email ) {
			return;
		}

		$member      = $this->get_member_by_email( $email );
		$member_id   = $member ? $member->id : 0;
		$donor_name  = sanitize_text_field( $donation['name'] ?? '' );
		$created_at  = ! empty( $donation['created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $donation['created_at'] ) ) : current_time( 'mysql' );
		$gateway     = sanitize_text_field( $donation['gateway'] ?? 'donation' );

		if ( ! $member ) {
			$result = $this->add_member(
				array(
					'email'            => $email,
					'name'             => $donor_name,
					'status'           => 'donor',
					'source'           => $gateway,
					'membership_level' => '',
				)
			);

			if ( is_wp_error( $result ) ) {
				return;
			}

			$member_id = $result;
		} else {
			$update = array();

			if ( $donor_name && empty( $member->name ) ) {
				$update['name'] = $donor_name;
			}

			if ( ! in_array( $member->status, array( 'unsubscribed', 'inactive' ), true ) ) {
				$update['status'] = 'donor';
			}

			if ( $gateway && empty( $member->source ) ) {
				$update['source'] = $gateway;
			}

			if ( $update ) {
				$this->update_member( $member_id, $update );
			}
		}

		$this->update_donation_totals( $member_id, $email, $created_at );
	}

	/**
	 * Update donation statistics for a member.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $email     Email.
	 * @param string $latest    Timestamp of most recent donation.
	 * @return void
	 */
	private function update_donation_totals( $member_id, $email, $latest ) {
		if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
			return;
		}

		$totals = NPMP_Donation_Manager::get_instance()->get_totals_for_email( $email );

		$this->update_member(
			$member_id,
			array(
				'donation_count'   => (int) $totals['count'],
				'donation_total'   => (float) $totals['total'],
				'last_donation_at' => $totals['last'] ? gmdate( 'Y-m-d H:i:s', strtotime( $totals['last'] ) ) : $latest,
			)
		);
	}

	/**
	 * Retrieve donation records for a member.
	 *
	 * @param object $member Member object.
	 * @param int    $limit  Number of donations to fetch.
	 * @return array
	 */
	public function get_member_donations( $member, $limit = 20 ) {
		if ( ! $member || empty( $member->email ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => NPMP_Donation_Manager::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => absint( $limit ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Donation records are keyed by donor email in post meta.
				'meta_query'     => array(
					array(
						'key'   => NPMP_Donation_Manager::META_EMAIL,
						'value' => sanitize_email( $member->email ),
					),
				),
			)
		);

		$donations = array();

		foreach ( $query->posts as $post ) {
			$donations[] = (object) array(
				'id'        => $post->ID,
				'amount'    => (float) get_post_meta( $post->ID, NPMP_Donation_Manager::META_AMOUNT, true ),
				'frequency' => get_post_meta( $post->ID, NPMP_Donation_Manager::META_FREQUENCY, true ),
				'gateway'   => get_post_meta( $post->ID, NPMP_Donation_Manager::META_GATEWAY, true ),
				'created_at'=> get_post_time( 'Y-m-d H:i:s', true, $post ),
			);
		}

		return $donations;
	}
}
