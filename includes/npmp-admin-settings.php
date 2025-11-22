<?php
/**
 * File path: includes/npmp-admin-settings.php
 *
 * "Features" toggle page for Nonprofit Manager.
 */
defined( 'ABSPATH' ) || exit;

/*--------------------------------------------------------------------
 * 1. Save handler (runs before any output)
 *------------------------------------------------------------------*/
add_action(
	'admin_init',
	static function () {

		if (
			isset( $_POST['npmp_features_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_features_nonce'] ) ), 'npmp_save_features' )
		) {
			$enabled = array(
				'members'     => isset( $_POST['npmp_feature_members'] ),
				'newsletters' => isset( $_POST['npmp_feature_newsletters'] ),
				'donations'   => isset( $_POST['npmp_feature_donations'] ),
				'calendar'    => isset( $_POST['npmp_feature_calendar'] ),
			);

			/* dependency: newsletters require members */
			if ( ! $enabled['members'] ) {
				$enabled['newsletters'] = false;
			}

			update_option( 'npmp_enabled_features', $enabled );

			wp_safe_redirect( admin_url( 'admin.php?page=npmp_main&updated=1' ) );
			exit;
		}
	}
);

/*--------------------------------------------------------------------
 * 2. Main "Overview / Features" page
 *------------------------------------------------------------------*/
function npmp_render_main_plugin_page() {

	$features = get_option(
		'npmp_enabled_features',
		array(
			'members'     => true,
			'newsletters' => false,
			'donations'   => true,
			'calendar'    => false,
		)
	);

	$is_pro               = npmp_is_pro();
	$setup_complete       = isset( $_GET['setup_complete'] ) ? sanitize_key( wp_unslash( $_GET['setup_complete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pro_setup_complete   = isset( $_GET['pro_setup_complete'] ) ? sanitize_key( wp_unslash( $_GET['pro_setup_complete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$features_updated     = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1>
			<?php esc_html_e( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ); ?>
			<?php if ( $is_pro ) : ?>
				<span class="npmp-pro-badge"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span>
			<?php endif; ?>
		</h1>
		<p><?php esc_html_e( 'Manage donations, memberships, email lists, and events from a single plugin.', 'nonprofit-manager' ); ?></p>

		<?php if ( $setup_complete ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Setup complete! Your Nonprofit Manager is ready to use.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $pro_setup_complete ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Pro setup complete! Your premium features are now active.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $features_updated ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Features updated.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<style>
			.npmp-main-container {
				display: flex;
				gap: 20px;
				margin-top: 20px;
			}
			.npmp-features-card {
				flex: 0 0 auto;
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
			}
			.npmp-sidebar-card {
				flex: 0 0 calc(33.333% - 10px);
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
			}
			.npmp-summary-container {
				display: flex;
				gap: 20px;
				margin-top: 20px;
			}
			.npmp-summary-card {
				flex: 1;
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
			}
			.npmp-summary-card h3 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #c3c4c7;
			}
			.npmp-summary-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 15px;
			}
			.npmp-summary-table th,
			.npmp-summary-table td {
				padding: 8px;
				text-align: left;
				border-bottom: 1px solid #ddd;
			}
			.npmp-summary-table th {
				font-weight: 600;
				color: #1d2327;
			}
			.npmp-summary-table td {
				color: #50575e;
			}
			.npmp-summary-table tr:last-child td,
			.npmp-summary-table tr:last-child th {
				border-bottom: none;
			}
			.npmp-summary-value {
				font-weight: 600;
				color: #2271b1;
			}
			.npmp-features-card h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #c3c4c7;
			}
			.npmp-features-card .form-table {
				margin-top: 0;
			}
			.npmp-features-card .form-table th {
				width: 200px;
				padding: 8px 10px 8px 0;
			}
			.npmp-features-card .form-table td {
				padding: 8px 10px;
			}
			.npmp-sidebar-card h2 {
				margin-top: 0;
				font-size: 16px;
				padding-bottom: 10px;
				border-bottom: 1px solid #c3c4c7;
			}
			.npmp-sidebar-card ul {
				margin: 10px 0 0 0;
			}
			.npmp-sidebar-card li {
				margin-bottom: 8px;
			}
			.npmp-upgrade-section {
				margin-top: 20px;
				background: #fff;
				border: 1px solid #c3c4c7;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
			}
			.npmp-upgrade-section h2 {
				margin-top: 0;
			}
			.npmp-upgrade-box {
				background: #f0f6fc;
				border: 1px solid #2271b1;
				border-radius: 4px;
				padding: 20px;
				margin-top: 15px;
			}
			.npmp-upgrade-box h3 {
				margin-top: 0;
				color: #2271b1;
			}
			.npmp-upgrade-box .button-primary {
				margin-top: 10px;
			}
			.npmp-pro-badge {
				display: inline-block;
				background: #2271b1;
				color: #fff;
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 14px;
				font-weight: 600;
				text-transform: uppercase;
				vertical-align: middle;
				margin-left: 10px;
			}
			@media screen and (max-width: 782px) {
				.npmp-main-container {
					flex-direction: column;
				}
				.npmp-features-card,
				.npmp-sidebar-card {
					flex: 0 0 100%;
				}
			}
		</style>

		<div class="npmp-main-container">
			<div class="npmp-features-card">
				<h2><?php esc_html_e( 'Activate Nonprofit Manager Features', 'nonprofit-manager' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'npmp_save_features', 'npmp_features_nonce' ); ?>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Member Tracking', 'nonprofit-manager' ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_members" <?php checked( $features['members'] ); ?>>
								<?php esc_html_e( 'Membership tracking and signup forms.', 'nonprofit-manager' ); ?></label></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Email Newsletters', 'nonprofit-manager' ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_newsletters" <?php checked( $features['newsletters'] ); ?> <?php disabled( ! $features['members'] ); ?>>
								<?php esc_html_e( 'Send newsletters to your members. (requires Member Tracking)', 'nonprofit-manager' ); ?></label></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Donations', 'nonprofit-manager' ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_donations" <?php checked( $features['donations'] ); ?>>
								<?php esc_html_e( 'Collect donations and manage payment methods.', 'nonprofit-manager' ); ?></label></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Calendar', 'nonprofit-manager' ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_calendar" <?php checked( $features['calendar'] ); ?>>
								<?php esc_html_e( 'Enable public event calendar & iCal feed.', 'nonprofit-manager' ); ?></label></td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Features', 'nonprofit-manager' ) ); ?>
				</form>
			</div>

			<div class="npmp-sidebar-card">
				<h2><?php esc_html_e( 'Quick Links', 'nonprofit-manager' ); ?></h2>
				<ul>
					<li><strong><?php esc_html_e( 'Settings', 'nonprofit-manager' ); ?></strong></li>
					<li><a href="admin.php?page=npmp_general_settings"><?php esc_html_e( 'General Settings', 'nonprofit-manager' ); ?></a></li>
					<?php if ( ! empty( $features['members'] ) ) : ?>
						<li><a href="admin.php?page=npmp_email_settings"><?php esc_html_e( 'Email Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['members'] ) ) : ?>
						<li style="margin-top: 15px;"><strong><?php esc_html_e( 'Membership', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="admin.php?page=npmp_members"><?php esc_html_e( 'Member List', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_membership_forms"><?php esc_html_e( 'Membership Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['newsletters'] ) ) : ?>
						<li style="margin-top: 15px;"><strong><?php esc_html_e( 'Email Newsletters', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="admin.php?page=npmp-newsletters"><?php esc_html_e( 'New Newsletter', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_newsletter_templates"><?php esc_html_e( 'Newsletter Templates', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_newsletter_archive"><?php esc_html_e( 'Newsletter Archive', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_newsletter_reports"><?php esc_html_e( 'Newsletter Reports', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_newsletter_settings"><?php esc_html_e( 'Newsletter Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['donations'] ) ) : ?>
						<li style="margin-top: 15px;"><strong><?php esc_html_e( 'Donations', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="admin.php?page=npmp_donations_group"><?php esc_html_e( 'Donations Dashboard', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_payment_settings"><?php esc_html_e( 'Payment Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['calendar'] ) ) : ?>
						<li style="margin-top: 15px;"><strong><?php esc_html_e( 'Events', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="admin.php?page=npmp-events"><?php esc_html_e( 'Events Overview', 'nonprofit-manager' ); ?></a></li>
						<li><a href="edit.php?post_type=npmp_event"><?php esc_html_e( 'All Events', 'nonprofit-manager' ); ?></a></li>
						<li><a href="post-new.php?post_type=npmp_event"><?php esc_html_e( 'Add New Event', 'nonprofit-manager' ); ?></a></li>
						<li><a href="admin.php?page=npmp_event_settings"><?php esc_html_e( 'Calendar Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<!-- Summary Tables -->
		<div class="npmp-summary-container">
			<!-- Membership Summary -->
			<?php if ( ! empty( $features['members'] ) ) : ?>
				<div class="npmp-summary-card">
					<h3><?php esc_html_e( 'Membership Summary', 'nonprofit-manager' ); ?></h3>
					<table class="npmp-summary-table">
						<?php
						// Get members by tier
						$tiers = npmp_get_membership_tiers();
						if ( ! empty( $tiers ) ) {
							foreach ( $tiers as $tier ) {
								$count = npmp_count_members_by_tier( $tier );
								?>
								<tr>
									<th><?php echo esc_html( $tier ); ?></th>
									<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
								</tr>
								<?php
							}
						} else {
							// No tiers defined, show total members
							$total_members = npmp_count_total_members();
							?>
							<tr>
								<th><?php esc_html_e( 'Total Members', 'nonprofit-manager' ); ?></th>
								<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></td>
							</tr>
							<?php
						}
						?>
					</table>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>" class="button"><?php esc_html_e( 'View All Members', 'nonprofit-manager' ); ?></a></p>
				</div>
			<?php endif; ?>

			<!-- Donations Summary -->
			<?php if ( ! empty( $features['donations'] ) && class_exists( 'NPMP_Donation_Manager' ) ) : ?>
				<div class="npmp-summary-card">
					<h3><?php esc_html_e( 'Donations Summary', 'nonprofit-manager' ); ?></h3>
					<table class="npmp-summary-table">
						<?php
						// Year-to-date donations
						$ytd_total = npmp_get_ytd_donation_total();
						?>
						<tr>
							<th><?php esc_html_e( 'Year-to-Date Donations', 'nonprofit-manager' ); ?></th>
							<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_currency( $ytd_total ) ); ?></td>
						</tr>

						<?php
						// Annual recurring donations
						$recurring_total = npmp_get_annual_recurring_total();
						?>
						<tr>
							<th><?php esc_html_e( 'Annual Recurring Donations', 'nonprofit-manager' ); ?></th>
							<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_currency( $recurring_total ) ); ?></td>
						</tr>
					</table>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_donations_group' ) ); ?>" class="button"><?php esc_html_e( 'View Donations Dashboard', 'nonprofit-manager' ); ?></a></p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $is_pro ) : ?>
			<div class="npmp-upgrade-section">
				<h2><?php esc_html_e( 'Thank You for Using Nonprofit Manager', 'nonprofit-manager' ); ?></h2>
				<p><?php esc_html_e( 'The free edition is designed to meet the needs of a wide range of nonprofits. If you need more powerful features and customizations, you can upgrade to Nonprofit Manager Pro for $17 per year.', 'nonprofit-manager' ); ?></p>
				<p><?php esc_html_e( 'We only offer special nonprofit pricing! That\'s less than $2 per month for a CRM, donation manager, newsletter platform, and event calendar/manager all-in-one inside of your existing WordPress site.', 'nonprofit-manager' ); ?></p>

				<div class="npmp-upgrade-box">
					<h3><?php esc_html_e( 'Upgrade to Nonprofit Manager Pro', 'nonprofit-manager' ); ?></h3>
					<p><?php esc_html_e( 'Get access to advanced features including:', 'nonprofit-manager' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Advanced reporting and analytics', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Recurring donation support', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Custom fields for members and donations', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Email automation workflows', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Advanced email list segmentation', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Priority support', 'nonprofit-manager' ); ?></li>
					</ul>
					<a href="<?php echo esc_url( npmp_get_upgrade_url() ); ?>" class="button button-primary" target="_blank">
						<?php esc_html_e( 'Learn More and Upgrade', 'nonprofit-manager' ); ?>
					</a>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
