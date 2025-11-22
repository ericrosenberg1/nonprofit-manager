<?php
/**
 * File path: includes/npmp-general-settings.php
 *
 * General Settings page for Nonprofit Manager
 */
defined( 'ABSPATH' ) || exit;

/**
 * Handle form submissions
 */
add_action(
	'admin_init',
	static function () {
		// Handle adding a membership level
		if (
			isset( $_POST['npmp_add_level_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_add_level_nonce'] ) ), 'npmp_add_membership_level' )
		) {
			$new_level = isset( $_POST['npmp_new_level'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_new_level'] ) ) : '';
			if ( ! empty( $new_level ) ) {
				$levels = get_option( 'npmp_membership_levels', array() );
				if ( ! in_array( $new_level, $levels, true ) ) {
					$levels[] = $new_level;
					update_option( 'npmp_membership_levels', $levels );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&updated=1' ) );
			exit;
		}

		// Handle removing a membership level
		if (
			isset( $_POST['npmp_remove_level_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_remove_level_nonce'] ) ), 'npmp_remove_membership_level' )
		) {
			$level_to_remove = isset( $_POST['npmp_level_to_remove'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_level_to_remove'] ) ) : '';
			if ( ! empty( $level_to_remove ) ) {
				$levels = get_option( 'npmp_membership_levels', array() );
				$levels = array_values( array_diff( $levels, array( $level_to_remove ) ) );
				update_option( 'npmp_membership_levels', $levels );

				// If the removed level was the default, clear the default
				$default_level = get_option( 'npmp_default_membership_level', '' );
				if ( $default_level === $level_to_remove ) {
					update_option( 'npmp_default_membership_level', '' );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&updated=1' ) );
			exit;
		}

		// Handle updating default level
		if (
			isset( $_POST['npmp_default_level_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_default_level_nonce'] ) ), 'npmp_save_default_level' )
		) {
			$default_level = isset( $_POST['npmp_default_membership_level'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_default_membership_level'] ) ) : '';
			update_option( 'npmp_default_membership_level', $default_level );
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&updated=1' ) );
			exit;
		}

		// Handle CAPTCHA settings save
		if (
			isset( $_POST['npmp_captcha_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_captcha_settings_nonce'] ) ), 'npmp_save_captcha_settings' )
		) {
			$captcha_provider = sanitize_key( wp_unslash( $_POST['npmp_captcha_provider'] ?? 'none' ) );
			if ( ! in_array( $captcha_provider, array( 'none', 'turnstile', 'recaptcha' ), true ) ) {
				$captcha_provider = 'none';
			}

			// Prevent free users from selecting reCAPTCHA
			if ( 'recaptcha' === $captcha_provider && ! npmp_is_pro() ) {
				$captcha_provider = 'none';
			}

			// Automatically enable Turnstile if it's selected as the provider
			$turnstile_enabled = ( 'turnstile' === $captcha_provider ) ? 1 : 0;
			$turnstile_site    = sanitize_text_field( wp_unslash( $_POST['npmp_turnstile_site_key'] ?? '' ) );
			$turnstile_secret  = sanitize_text_field( wp_unslash( $_POST['npmp_turnstile_secret_key'] ?? '' ) );
			$recaptcha_site    = sanitize_text_field( wp_unslash( $_POST['npmp_recaptcha_site_key'] ?? '' ) );
			$recaptcha_secret  = sanitize_text_field( wp_unslash( $_POST['npmp_recaptcha_secret_key'] ?? '' ) );

			update_option( 'npmp_captcha_provider', $captcha_provider );
			update_option( 'npmp_turnstile_enabled', $turnstile_enabled );
			update_option( 'npmp_turnstile_site_key', $turnstile_site );
			update_option( 'npmp_turnstile_secret_key', $turnstile_secret );
			update_option( 'npmp_recaptcha_site_key', $recaptcha_site );
			update_option( 'npmp_recaptcha_secret_key', $recaptcha_secret );

			wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&updated=1' ) );
			exit;
		}

		// Handle Turnstile test
		if (
			isset( $_POST['npmp_test_turnstile_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_turnstile_nonce'] ) ), 'npmp_test_turnstile' )
		) {
			$result = npmp_turnstile_test_keys();
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&turnstile_test=error&turnstile_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&turnstile_test=success' ) );
			}
			exit;
		}

		// Handle reCAPTCHA test
		if (
			isset( $_POST['npmp_test_recaptcha_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_test_recaptcha_nonce'] ) ), 'npmp_test_recaptcha' )
		) {
			$result = npmp_recaptcha_test_keys();
			if ( is_wp_error( $result ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&recaptcha_test=error&recaptcha_message=' . urlencode( $result->get_error_message() ) ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&recaptcha_test=success' ) );
			}
			exit;
		}
	}
);

/**
 * Render the General Settings page
 */
function npmp_render_general_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	// Set default membership levels if none exist
	$default_levels = array( 'Subscriber', 'Member', 'Donor', 'Director' );
	$membership_levels = get_option( 'npmp_membership_levels', array() );
	if ( empty( $membership_levels ) ) {
		$membership_levels = $default_levels;
		update_option( 'npmp_membership_levels', $membership_levels );
	}
	$default_membership_level = get_option( 'npmp_default_membership_level', 'Subscriber' );
	$current_captcha_provider = npmp_captcha_get_provider();
	$current_turnstile_enabled = (int) get_option( 'npmp_turnstile_enabled', 0 );
	$current_turnstile_site   = get_option( 'npmp_turnstile_site_key', '' );
	$current_turnstile_secret = get_option( 'npmp_turnstile_secret_key', '' );
	$current_recaptcha_site   = get_option( 'npmp_recaptcha_site_key', '' );
	$current_recaptcha_secret = get_option( 'npmp_recaptcha_secret_key', '' );
	$updated                  = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Get test result parameters
	$turnstile_test = isset( $_GET['turnstile_test'] ) ? sanitize_key( wp_unslash( $_GET['turnstile_test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$turnstile_message = isset( $_GET['turnstile_message'] ) ? sanitize_text_field( wp_unslash( $_GET['turnstile_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$recaptcha_test = isset( $_GET['recaptcha_test'] ) ? sanitize_key( wp_unslash( $_GET['recaptcha_test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$recaptcha_message = isset( $_GET['recaptcha_message'] ) ? sanitize_text_field( wp_unslash( $_GET['recaptcha_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'General Settings', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Configure membership levels and form security settings.', 'nonprofit-manager' ); ?></p>

		<?php if ( $updated ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'success' === $turnstile_test ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Turnstile configuration test passed! Your keys are valid.', 'nonprofit-manager' ); ?></p></div>
		<?php elseif ( 'error' === $turnstile_test ) : ?>
			<div class="error notice is-dismissible"><p><?php echo esc_html( sprintf( __( 'Turnstile test failed: %s', 'nonprofit-manager' ), $turnstile_message ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'success' === $recaptcha_test ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'reCAPTCHA configuration test passed! Your keys are valid.', 'nonprofit-manager' ); ?></p></div>
		<?php elseif ( 'error' === $recaptcha_test ) : ?>
			<div class="error notice is-dismissible"><p><?php echo esc_html( sprintf( __( 'reCAPTCHA test failed: %s', 'nonprofit-manager' ), $recaptcha_message ) ); ?></p></div>
		<?php endif; ?>

		<style>
			.npmp-settings-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
				max-width: 800px;
			}
			.npmp-settings-card h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #c3c4c7;
			}
			.npmp-settings-card .form-table {
				margin-top: 10px;
			}
			.npmp-settings-card .form-table th {
				padding: 12px 10px 12px 0;
			}
			.npmp-settings-card .form-table td {
				padding: 12px 10px;
			}
			.npmp-levels-table {
				width: 100%;
				border-collapse: collapse;
				margin: 15px 0;
			}
			.npmp-levels-table th {
				text-align: left;
				padding: 10px;
				background: #f6f7f7;
				border: 1px solid #c3c4c7;
				font-weight: 600;
			}
			.npmp-levels-table td {
				padding: 10px;
				border: 1px solid #dcdcde;
			}
			.npmp-levels-table tr:hover {
				background: #f6f7f7;
			}
			.npmp-delete-level {
				color: #b32d2e;
				text-decoration: none;
				cursor: pointer;
			}
			.npmp-delete-level:hover {
				color: #dc3232;
			}
			.npmp-add-level-form {
				display: flex;
				gap: 10px;
				align-items: center;
				margin-top: 15px;
			}
			.npmp-add-level-form input[type="text"] {
				flex: 1;
				max-width: 300px;
			}
		</style>

		<!-- Membership Levels Section -->
		<div class="npmp-settings-card">
			<h2><?php esc_html_e( 'Membership Levels', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Define membership levels for your organization. These will be available when adding or editing members.', 'nonprofit-manager' ); ?></p>

			<!-- Current Levels Table -->
			<?php if ( ! empty( $membership_levels ) ) : ?>
				<table class="npmp-levels-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level Name', 'nonprofit-manager' ); ?></th>
							<th style="width: 100px; text-align: center;"><?php esc_html_e( 'Action', 'nonprofit-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $membership_levels as $level ) : ?>
							<tr>
								<td><?php echo esc_html( $level ); ?></td>
								<td style="text-align: center;">
									<a href="#" class="npmp-delete-level" data-level="<?php echo esc_attr( $level ); ?>" title="<?php esc_attr_e( 'Delete this level', 'nonprofit-manager' ); ?>">
										<span class="dashicons dashicons-no-alt"></span>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No membership levels defined yet.', 'nonprofit-manager' ); ?></em></p>
			<?php endif; ?>

			<!-- Add New Level Form -->
			<form method="post" class="npmp-add-level-form">
				<?php wp_nonce_field( 'npmp_add_membership_level', 'npmp_add_level_nonce' ); ?>
				<input
					type="text"
					name="npmp_new_level"
					placeholder="<?php esc_attr_e( 'Enter new membership level name', 'nonprofit-manager' ); ?>"
					required
				>
				<?php submit_button( __( 'Add Level', 'nonprofit-manager' ), 'secondary', 'submit', false ); ?>
			</form>

			<!-- Hidden form for deletion -->
			<form method="post" id="npmp-delete-level-form" style="display: none;">
				<?php wp_nonce_field( 'npmp_remove_membership_level', 'npmp_remove_level_nonce' ); ?>
				<input type="hidden" name="npmp_level_to_remove" id="npmp_level_to_remove">
			</form>

			<hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">

			<!-- Default Level Selection -->
			<form method="post">
				<?php wp_nonce_field( 'npmp_save_default_level', 'npmp_default_level_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="npmp_default_membership_level"><?php esc_html_e( 'Default Level', 'nonprofit-manager' ); ?></label>
						</th>
						<td>
							<select name="npmp_default_membership_level" id="npmp_default_membership_level" class="regular-text">
								<option value=""><?php esc_html_e( '-- Select Default Level --', 'nonprofit-manager' ); ?></option>
								<?php foreach ( $membership_levels as $level ) : ?>
									<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $default_membership_level, $level ); ?>>
										<?php echo esc_html( $level ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'This level will be automatically assigned to new members who sign up via email forms.', 'nonprofit-manager' ); ?>
							</p>
							<?php submit_button( __( 'Save Default Level', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
						</td>
					</tr>
				</table>
			</form>
		</div>

		<!-- Form Security Section -->
		<div class="npmp-settings-card">
			<h2><?php esc_html_e( 'Form Security (CAPTCHA)', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Protect your public signup and donation forms from spam and abuse.', 'nonprofit-manager' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'npmp_save_captcha_settings', 'npmp_captcha_settings_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="npmp_captcha_provider"><?php esc_html_e( 'CAPTCHA Provider', 'nonprofit-manager' ); ?></label>
						</th>
						<td>
							<select name="npmp_captcha_provider" id="npmp_captcha_provider">
								<option value="none" <?php selected( $current_captcha_provider, 'none' ); ?>>
									<?php esc_html_e( 'None (not recommended)', 'nonprofit-manager' ); ?>
								</option>
								<option value="turnstile" <?php selected( $current_captcha_provider, 'turnstile' ); ?>>
									<?php esc_html_e( 'Cloudflare Turnstile', 'nonprofit-manager' ); ?>
								</option>
								<?php
								$is_pro = npmp_is_pro();
								$is_recaptcha_pro = ! $is_pro;
								?>
								<option value="recaptcha" <?php selected( $current_captcha_provider, 'recaptcha' ); ?> <?php disabled( $is_recaptcha_pro ); ?>>
									<?php
									if ( $is_pro ) {
										esc_html_e( 'Google reCAPTCHA v3', 'nonprofit-manager' );
									} else {
										esc_html_e( 'Google reCAPTCHA v3 (Pro Upgrade Required)', 'nonprofit-manager' );
									}
									?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Select a CAPTCHA service to protect your forms from bots.', 'nonprofit-manager' ); ?>
								<?php if ( ! $is_pro ) : ?>
									<br>
									<?php
									printf(
										/* translators: %s: URL to upgrade page */
										wp_kses_post( __( 'Want to use Google reCAPTCHA? <a href="%s" target="_blank">Upgrade to Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
										esc_url( npmp_get_upgrade_url() )
									);
									?>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr class="captcha-turnstile" style="<?php echo 'turnstile' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'Turnstile Configuration', 'nonprofit-manager' ); ?></th>
						<td>
							<p><strong><?php esc_html_e( 'Site Key:', 'nonprofit-manager' ); ?></strong></p>
							<input type="text" class="regular-text" name="npmp_turnstile_site_key" value="<?php echo esc_attr( $current_turnstile_site ); ?>" placeholder="<?php esc_attr_e( 'Enter your Turnstile site key', 'nonprofit-manager' ); ?>">

							<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Secret Key:', 'nonprofit-manager' ); ?></strong></p>
							<input type="text" class="regular-text" name="npmp_turnstile_secret_key" value="<?php echo esc_attr( $current_turnstile_secret ); ?>" placeholder="<?php esc_attr_e( 'Enter your Turnstile secret key', 'nonprofit-manager' ); ?>">

							<p class="description" style="margin-top: 10px;">
								<?php
								printf(
									/* translators: %s: URL to Cloudflare Turnstile */
									wp_kses_post( __( 'Get your free Turnstile keys from <a href="%s" target="_blank">Cloudflare Turnstile</a>.', 'nonprofit-manager' ) ),
									'https://dash.cloudflare.com/?to=/:account/turnstile'
								);
								?>
							</p>
						</td>
					</tr>

					<tr class="captcha-turnstile" style="<?php echo 'turnstile' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"></th>
						<td>
							<form method="post" style="display: inline;">
								<?php wp_nonce_field( 'npmp_test_turnstile', 'npmp_test_turnstile_nonce' ); ?>
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Test Turnstile Configuration', 'nonprofit-manager' ); ?>
								</button>
								<p class="description" style="margin-top: 10px;">
									<?php esc_html_e( 'Save your keys first, then test to verify they are working correctly.', 'nonprofit-manager' ); ?>
								</p>
							</form>
						</td>
					</tr>

					<tr class="captcha-recaptcha" style="<?php echo 'recaptcha' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'reCAPTCHA v3 Configuration', 'nonprofit-manager' ); ?></th>
						<td>
							<p><strong><?php esc_html_e( 'Site Key:', 'nonprofit-manager' ); ?></strong></p>
							<input type="text" class="regular-text" name="npmp_recaptcha_site_key" value="<?php echo esc_attr( $current_recaptcha_site ); ?>" placeholder="<?php esc_attr_e( 'Enter your reCAPTCHA v3 site key', 'nonprofit-manager' ); ?>">

							<p style="margin-top: 10px;"><strong><?php esc_html_e( 'Secret Key:', 'nonprofit-manager' ); ?></strong></p>
							<input type="text" class="regular-text" name="npmp_recaptcha_secret_key" value="<?php echo esc_attr( $current_recaptcha_secret ); ?>" placeholder="<?php esc_attr_e( 'Enter your reCAPTCHA v3 secret key', 'nonprofit-manager' ); ?>">

							<p class="description" style="margin-top: 10px;">
								<?php
								printf(
									/* translators: %s: URL to Google reCAPTCHA */
									wp_kses_post( __( 'Get your free reCAPTCHA v3 keys from <a href="%s" target="_blank">Google reCAPTCHA Admin Console</a>. Make sure to select reCAPTCHA v3.', 'nonprofit-manager' ) ),
									'https://www.google.com/recaptcha/admin/create'
								);
								?>
							</p>
						</td>
					</tr>

					<tr class="captcha-recaptcha" style="<?php echo 'recaptcha' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"></th>
						<td>
							<form method="post" style="display: inline;">
								<?php wp_nonce_field( 'npmp_test_recaptcha', 'npmp_test_recaptcha_nonce' ); ?>
								<button type="submit" class="button button-secondary">
									<?php esc_html_e( 'Test reCAPTCHA Configuration', 'nonprofit-manager' ); ?>
								</button>
								<p class="description" style="margin-top: 10px;">
									<?php esc_html_e( 'Save your keys first, then test to verify they are working correctly.', 'nonprofit-manager' ); ?>
								</p>
							</form>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Security Settings', 'nonprofit-manager' ), 'primary' ); ?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// CAPTCHA provider toggle
			$('#npmp_captcha_provider').on('change', function() {
				var provider = $(this).val();
				$('.captcha-turnstile, .captcha-recaptcha').hide();
				if (provider === 'turnstile') {
					$('.captcha-turnstile').show();
				} else if (provider === 'recaptcha') {
					$('.captcha-recaptcha').show();
				}
			});

			// Delete membership level with confirmation
			$('.npmp-delete-level').on('click', function(e) {
				e.preventDefault();
				var level = $(this).data('level');
				var confirmMessage = '<?php echo esc_js( __( 'Are you sure you want to delete the membership level', 'nonprofit-manager' ) ); ?> "' + level + '"?\n\n<?php echo esc_js( __( 'This action cannot be undone.', 'nonprofit-manager' ) ); ?>';

				if (confirm(confirmMessage)) {
					$('#npmp_level_to_remove').val(level);
					$('#npmp-delete-level-form').submit();
				}
			});
		});
		</script>
	</div>
	<?php
}
