<?php
/**
 * File path: includes/npmp-setup-wizard.php
 *
 * Setup wizard for first-time Nonprofit Manager users
 */
defined( 'ABSPATH' ) || exit;

/**
 * Check if setup wizard should be displayed
 *
 * @return bool True if wizard should run
 */
function npmp_should_show_setup_wizard() {
	$setup_completed = get_option( 'npmp_setup_completed', false );
	$is_activation   = get_transient( 'npmp_activation_redirect' );

	return ! $setup_completed && $is_activation;
}

/**
 * Redirect to setup wizard on activation
 */
add_action(
	'admin_init',
	static function () {
		if ( get_transient( 'npmp_activation_redirect' ) ) {
			delete_transient( 'npmp_activation_redirect' );

			if ( ! npmp_should_show_setup_wizard() ) {
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=npmp_setup_wizard' ) );
			exit;
		}
	}
);

/**
 * Register setup wizard page
 */
add_action(
	'admin_menu',
	static function () {
		add_submenu_page(
			null, // Hidden from menu
			__( 'Nonprofit Manager Setup', 'nonprofit-manager' ),
			__( 'Setup', 'nonprofit-manager' ),
			'manage_options',
			'npmp_setup_wizard',
			'npmp_render_setup_wizard'
		);
	}
);

/**
 * Handle setup wizard form submission
 */
add_action(
	'admin_init',
	static function () {
		if (
			isset( $_POST['npmp_setup_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_setup_nonce'] ) ), 'npmp_setup_wizard' )
		) {
			$step = isset( $_POST['npmp_setup_step'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_setup_step'] ) ) : '';

			if ( 'complete' === $step ) {
				// Save selected features
				$enabled = array(
					'members'     => isset( $_POST['npmp_feature_members'] ),
					'newsletters' => isset( $_POST['npmp_feature_newsletters'] ),
					'donations'   => isset( $_POST['npmp_feature_donations'] ),
					'calendar'    => isset( $_POST['npmp_feature_calendar'] ),
				);

				// Ensure newsletter dependency
				if ( ! $enabled['members'] ) {
					$enabled['newsletters'] = false;
				}

				update_option( 'npmp_enabled_features', $enabled );
				update_option( 'npmp_setup_completed', true );

				wp_safe_redirect( admin_url( 'admin.php?page=npmp_main&setup_complete=1' ) );
				exit;
			}
		}
	}
);

/**
 * Render the setup wizard
 */
function npmp_render_setup_wizard() {
	$version = npmp_get_version();
	?>
	<div class="wrap">
		<style>
			.npmp-setup-wizard {
				max-width: 800px;
				margin: 50px auto;
				background: #fff;
				padding: 40px;
				border-radius: 8px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.1);
			}
			.npmp-setup-wizard h1 {
				text-align: center;
				margin-bottom: 10px;
			}
			.npmp-setup-wizard .subtitle {
				text-align: center;
				font-size: 16px;
				color: #666;
				margin-bottom: 40px;
			}
			.npmp-setup-wizard .feature-grid {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 20px;
				margin: 30px 0;
			}
			.npmp-setup-wizard .feature-card {
				border: 2px solid #ddd;
				padding: 20px;
				border-radius: 4px;
				transition: all 0.2s;
			}
			.npmp-setup-wizard .feature-card:hover {
				border-color: #2271b1;
			}
			.npmp-setup-wizard .feature-card.selected {
				border-color: #2271b1;
				background-color: #f0f6fc;
			}
			.npmp-setup-wizard .feature-card input[type="checkbox"] {
				margin-right: 10px;
			}
			.npmp-setup-wizard .feature-card h3 {
				margin: 0 0 10px 0;
				font-size: 18px;
			}
			.npmp-setup-wizard .feature-card p {
				margin: 0;
				color: #666;
			}
			.npmp-setup-wizard .button-primary {
				display: block;
				margin: 30px auto 0;
				padding: 12px 40px;
				height: auto;
				font-size: 16px;
			}
			.npmp-version-badge {
				display: inline-block;
				background: #2271b1;
				color: #fff;
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
			}
		</style>

		<div class="npmp-setup-wizard">
			<h1>
				<?php esc_html_e( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ); ?>
				<?php if ( 'pro' === $version ) : ?>
					<span class="npmp-version-badge"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span>
				<?php endif; ?>
			</h1>
			<p class="subtitle">
				<?php
				if ( 'pro' === $version ) {
					esc_html_e( 'Thank you for upgrading to Pro! Let\'s set up your nonprofit management system.', 'nonprofit-manager' );
				} else {
					esc_html_e( 'Let\'s get started by choosing which features you need.', 'nonprofit-manager' );
				}
				?>
			</p>

			<form method="post" id="npmp-setup-form">
				<?php wp_nonce_field( 'npmp_setup_wizard', 'npmp_setup_nonce' ); ?>
				<input type="hidden" name="npmp_setup_step" value="complete">

				<h2><?php esc_html_e( 'Select Your Features', 'nonprofit-manager' ); ?></h2>
				<p><?php esc_html_e( 'Choose the features you want to activate. You can change these later.', 'nonprofit-manager' ); ?></p>

				<div class="feature-grid">
					<div class="feature-card selected">
						<label>
							<h3>
								<input type="checkbox" name="npmp_feature_members" checked onchange="npmpToggleFeatureCard(this)">
								<?php esc_html_e( 'Member Tracking', 'nonprofit-manager' ); ?>
							</h3>
							<p><?php esc_html_e( 'Track members, manage contacts, and capture signups through customizable forms.', 'nonprofit-manager' ); ?></p>
						</label>
					</div>

					<div class="feature-card">
						<label>
							<h3>
								<input type="checkbox" name="npmp_feature_newsletters" onchange="npmpToggleFeatureCard(this)">
								<?php esc_html_e( 'Email Newsletters', 'nonprofit-manager' ); ?>
							</h3>
							<p><?php esc_html_e( 'Send beautiful newsletters to your members. Requires Member Tracking.', 'nonprofit-manager' ); ?></p>
						</label>
					</div>

					<div class="feature-card selected">
						<label>
							<h3>
								<input type="checkbox" name="npmp_feature_donations" checked onchange="npmpToggleFeatureCard(this)">
								<?php esc_html_e( 'Donations', 'nonprofit-manager' ); ?>
							</h3>
							<p><?php esc_html_e( 'Accept and track donations with integrated payment processing.', 'nonprofit-manager' ); ?></p>
						</label>
					</div>

					<div class="feature-card">
						<label>
							<h3>
								<input type="checkbox" name="npmp_feature_calendar" onchange="npmpToggleFeatureCard(this)">
								<?php esc_html_e( 'Event Calendar', 'nonprofit-manager' ); ?>
							</h3>
							<p><?php esc_html_e( 'Manage events with a public calendar and iCal feed.', 'nonprofit-manager' ); ?></p>
						</label>
					</div>
				</div>

				<?php if ( 'free' === $version ) : ?>
					<div class="notice notice-info inline" style="margin: 30px 0;">
						<p>
							<strong><?php esc_html_e( 'Want more features?', 'nonprofit-manager' ); ?></strong>
							<?php esc_html_e( 'Upgrade to Nonprofit Manager Pro for advanced reporting, recurring donations, custom fields, and more.', 'nonprofit-manager' ); ?>
							<a href="<?php echo esc_url( npmp_get_upgrade_url() ); ?>" target="_blank">
								<?php esc_html_e( 'Learn more', 'nonprofit-manager' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<?php submit_button( __( 'Complete Setup', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
			</form>
		</div>

		<script>
		function npmpToggleFeatureCard(checkbox) {
			const card = checkbox.closest('.feature-card');
			if (checkbox.checked) {
				card.classList.add('selected');
			} else {
				card.classList.remove('selected');
			}
		}
		</script>
	</div>
	<?php
}
