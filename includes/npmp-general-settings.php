<?php
/**
 * File path: includes/npmp-general-settings.php
 *
 * General Settings page for Nonprofit Manager
 */
defined( 'ABSPATH' ) || exit;

/**
 * Handle the General Settings forms.
 *
 * Acts only on a POST to admin.php?page=npmp_general_settings. Membership
 * levels used to be edited here too, with the same nonce names as Membership
 * Settings, so a level added on Membership Settings ran this handler first and
 * landed on General Settings. Levels now live on Membership Settings alone
 * (npmp_membership_levels_handle_post()), and this handler checks the page.
 * tests/test-settings-handlers.php pins both.
 *
 * @return void
 */
function npmp_general_settings_handle_post() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only. Each action below checks its own nonce.
	if ( ! isset( $_GET['page'] ) || 'npmp_general_settings' !== $_GET['page'] ) {
		return;
	}

	// Nonce verification alone isn't a capability check: gate every
	// action below on manage_options too, defense-in-depth alongside
	// the nonce, matching the render function of this same page.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
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
		// Secret fields render blank with a masked placeholder, so an
		// empty submission means "keep the saved secret".
		if ( '' !== $turnstile_secret ) {
			update_option( 'npmp_turnstile_secret_key', $turnstile_secret );
		}
		update_option( 'npmp_recaptcha_site_key', $recaptcha_site );
		if ( '' !== $recaptcha_secret ) {
			update_option( 'npmp_recaptcha_secret_key', $recaptcha_secret );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=npmp_general_settings&updated=1' ) );
		exit;
	}

	// Handle the "Powered by" attribution opt-in toggle
	if (
		isset( $_POST['npmp_powered_by_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_powered_by_nonce'] ) ), 'npmp_save_powered_by' )
	) {
		update_option( 'npmp_powered_by_optin', isset( $_POST['npmp_powered_by_optin'] ) ? 1 : 0 );
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
add_action( 'admin_init', 'npmp_general_settings_handle_post' );

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
	<div class="wrap npmp-admin-page">
		<?php npmp_admin_page_title( __( 'General Settings', 'nonprofit-manager' ) ); ?>
		<p class="npmp-page-description"><?php esc_html_e( 'Your organization details, form spam protection, and the attribution link.', 'nonprofit-manager' ); ?></p>

		<?php if ( $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php
		// Org identity section (onboarding module). The guided tour highlights
		// #npmp_org_name here as the user's first stop. Guarded so the page
		// still works if the onboarding module is ever absent.
		if ( class_exists( 'NPMP_Org_Settings' ) ) :
		?>
		<form method="post" id="npmp-org-settings-form">
			<?php NPMP_Org_Settings::render_form_section(); ?>
			<?php submit_button( __( 'Save organization details', 'nonprofit-manager' ), 'primary', 'npmp_save_org_only', false ); ?>
		</form>
		<?php endif; ?>

		<?php if ( 'success' === $turnstile_test ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Turnstile accepted your keys.', 'nonprofit-manager' ); ?></p></div>
		<?php elseif ( 'error' === $turnstile_test ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: why the test failed. */ __( 'Turnstile test failed: %s', 'nonprofit-manager' ), $turnstile_message ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'success' === $recaptcha_test ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'reCAPTCHA accepted your keys.', 'nonprofit-manager' ); ?></p></div>
		<?php elseif ( 'error' === $recaptcha_test ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: why the test failed. */ __( 'reCAPTCHA test failed: %s', 'nonprofit-manager' ), $recaptcha_message ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( function_exists( 'npmp_render_membership_forms_page' ) ) : ?>
		<div class="npmp-settings-card">
			<h2><?php echo esc_html( npmp_label( 'levels' ) ); ?></h2>
			<p>
				<?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for membership levels, such as membership levels or dues levels. */ __( 'You can add, remove, and pick a default for %s on Membership Settings.', 'nonprofit-manager' ), npmp_label( 'levels_lc' ) ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_membership_forms#npmp-levels' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for membership levels, such as membership levels or dues levels. */ __( 'Manage %s', 'nonprofit-manager' ), npmp_label( 'levels_lc' ) ) ); ?></a>
			</p>
		</div>
		<?php endif; ?>

		<!-- Form Security Section -->
		<div class="npmp-settings-card">
			<h2><?php esc_html_e( 'Form security (CAPTCHA)', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'A CAPTCHA keeps bots off your public signup and contact forms.', 'nonprofit-manager' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'npmp_save_captcha_settings', 'npmp_captcha_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="npmp_captcha_provider"><?php esc_html_e( 'CAPTCHA provider', 'nonprofit-manager' ); ?></label>
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
										esc_html_e( 'Google reCAPTCHA v3 (Pro)', 'nonprofit-manager' );
									}
									?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Cloudflare Turnstile is free and asks most visitors nothing.', 'nonprofit-manager' ); ?>
								<?php if ( ! $is_pro ) : ?>
									<br>
									<?php
									printf(
										/* translators: %s: URL to upgrade page */
										wp_kses_post( __( 'Google reCAPTCHA comes with <a href="%s" target="_blank" rel="noopener">Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
										esc_url( npmp_get_upgrade_url( 'captcha' ) )
									);
									?>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr class="captcha-turnstile" style="<?php echo 'turnstile' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'Turnstile keys', 'nonprofit-manager' ); ?></th>
						<td>
							<p><label for="npmp_turnstile_site_key"><strong><?php esc_html_e( 'Site key', 'nonprofit-manager' ); ?></strong></label></p>
							<input type="text" class="regular-text" id="npmp_turnstile_site_key" name="npmp_turnstile_site_key" value="<?php echo esc_attr( $current_turnstile_site ); ?>" placeholder="<?php esc_attr_e( 'Enter your Turnstile site key', 'nonprofit-manager' ); ?>">

							<p class="npmp-field-gap"><label for="npmp_turnstile_secret_key"><strong><?php esc_html_e( 'Secret key', 'nonprofit-manager' ); ?></strong></label></p>
							<input type="password" class="regular-text" id="npmp_turnstile_secret_key" name="npmp_turnstile_secret_key" value="" autocomplete="new-password" placeholder="<?php echo $current_turnstile_secret ? esc_attr( sprintf( /* translators: %s: a masked secret, shown as dots. */ __( '%s (saved)', 'nonprofit-manager' ), '••••••••' ) ) : esc_attr__( 'Enter your Turnstile secret key', 'nonprofit-manager' ); ?>">

							<p class="description npmp-field-gap">
								<?php
								printf(
									/* translators: %s: URL to Cloudflare Turnstile */
									wp_kses_post( __( 'Get your free Turnstile keys from <a href="%s" target="_blank" rel="noopener">Cloudflare Turnstile</a>.', 'nonprofit-manager' ) ),
									'https://dash.cloudflare.com/?to=/:account/turnstile'
								);
								?>
							</p>
						</td>
					</tr>

					<tr class="captcha-turnstile" style="<?php echo 'turnstile' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"></th>
						<td>
							<button type="submit" form="npmp-test-turnstile-form" class="button button-secondary">
								<?php esc_html_e( 'Test Turnstile keys', 'nonprofit-manager' ); ?>
							</button>
							<p class="description npmp-field-gap">
								<?php esc_html_e( 'Save your keys first, then click Test.', 'nonprofit-manager' ); ?>
							</p>
						</td>
					</tr>

					<tr class="captcha-recaptcha" style="<?php echo 'recaptcha' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'reCAPTCHA v3 keys', 'nonprofit-manager' ); ?></th>
						<td>
							<p><label for="npmp_recaptcha_site_key"><strong><?php esc_html_e( 'Site key', 'nonprofit-manager' ); ?></strong></label></p>
							<input type="text" class="regular-text" id="npmp_recaptcha_site_key" name="npmp_recaptcha_site_key" value="<?php echo esc_attr( $current_recaptcha_site ); ?>" placeholder="<?php esc_attr_e( 'Enter your reCAPTCHA v3 site key', 'nonprofit-manager' ); ?>">

							<p class="npmp-field-gap"><label for="npmp_recaptcha_secret_key"><strong><?php esc_html_e( 'Secret key', 'nonprofit-manager' ); ?></strong></label></p>
							<input type="password" class="regular-text" id="npmp_recaptcha_secret_key" name="npmp_recaptcha_secret_key" value="" autocomplete="new-password" placeholder="<?php echo $current_recaptcha_secret ? esc_attr( sprintf( /* translators: %s: a masked secret, shown as dots. */ __( '%s (saved)', 'nonprofit-manager' ), '••••••••' ) ) : esc_attr__( 'Enter your reCAPTCHA v3 secret key', 'nonprofit-manager' ); ?>">

							<p class="description npmp-field-gap">
								<?php
								printf(
									/* translators: %s: URL to Google reCAPTCHA */
									wp_kses_post( __( 'Get your free keys from the <a href="%s" target="_blank" rel="noopener">Google reCAPTCHA Admin Console</a>, and pick reCAPTCHA v3 when it asks.', 'nonprofit-manager' ) ),
									'https://www.google.com/recaptcha/admin/create'
								);
								?>
							</p>
						</td>
					</tr>

					<tr class="captcha-recaptcha" style="<?php echo 'recaptcha' !== $current_captcha_provider ? 'display:none;' : ''; ?>">
						<th scope="row"></th>
						<td>
							<button type="submit" form="npmp-test-recaptcha-form" class="button button-secondary">
								<?php esc_html_e( 'Test reCAPTCHA keys', 'nonprofit-manager' ); ?>
							</button>
							<p class="description npmp-field-gap">
								<?php esc_html_e( 'Save your keys first, then click Test.', 'nonprofit-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save security settings', 'nonprofit-manager' ), 'primary' ); ?>
			</form>

			<?php
			// The Test buttons above submit these through their form
			// attribute. A form can't sit inside another form, so they live
			// out here.
			?>
			<form method="post" id="npmp-test-turnstile-form" hidden>
				<?php wp_nonce_field( 'npmp_test_turnstile', 'npmp_test_turnstile_nonce' ); ?>
			</form>
			<form method="post" id="npmp-test-recaptcha-form" hidden>
				<?php wp_nonce_field( 'npmp_test_recaptcha', 'npmp_test_recaptcha_nonce' ); ?>
			</form>
		</div>

		<!-- Support / Attribution Section -->
		<div class="npmp-settings-card">
			<h2><?php esc_html_e( 'Support Nonprofit Manager', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Nonprofit Manager is free. If you\'d like to help other groups find it, you can show a small "Powered by Nonprofit Manager" link on your public donation forms and newsletter emails. It stays off unless you turn it on.', 'nonprofit-manager' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'npmp_save_powered_by', 'npmp_powered_by_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Attribution link', 'nonprofit-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="npmp_powered_by_optin" value="1" <?php checked( (bool) get_option( 'npmp_powered_by_optin', false ) ); ?>>
								<?php esc_html_e( 'Show a "Powered by Nonprofit Manager" link on donation forms and newsletter emails', 'nonprofit-manager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'You can turn it off any time.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save attribution setting', 'nonprofit-manager' ), 'primary' ); ?>
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

		});
		</script>
	</div>
	<?php
}
