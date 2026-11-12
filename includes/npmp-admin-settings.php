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

		// Nonce verification alone isn't a capability check: gate on
		// manage_options too, defense-in-depth alongside the nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if (
			isset( $_POST['npmp_features_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_features_nonce'] ) ), 'npmp_save_features' )
		) {
			$enabled = array(
				'members'     => isset( $_POST['npmp_feature_members'] ),
				'newsletters' => isset( $_POST['npmp_feature_newsletters'] ),
				'donations'   => isset( $_POST['npmp_feature_donations'] ),
				'calendar'    => isset( $_POST['npmp_feature_calendar'] ),
				'social'      => isset( $_POST['npmp_feature_social'] ),
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
			'social'      => false,
		)
	);

	$is_pro               = npmp_is_pro();
	$setup_complete       = isset( $_GET['setup_complete'] ) ? sanitize_key( wp_unslash( $_GET['setup_complete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pro_setup_complete   = isset( $_GET['pro_setup_complete'] ) ? sanitize_key( wp_unslash( $_GET['pro_setup_complete'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$features_updated     = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$optin_result         = isset( $_GET['npmp_optin'] ) ? sanitize_key( wp_unslash( $_GET['npmp_optin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap npmp-admin-page">
		<?php
		$npmp_title_actions = array();
		// The tour link is guarded so the header survives a build that
		// excludes onboarding.
		if ( class_exists( 'NPMP_Tour' ) ) {
			$npmp_title_actions[ __( 'Rerun the setup tour', 'nonprofit-manager' ) ] = admin_url( 'admin.php?page=npmp_main&npmp_tour_restart=1' );
		}
		npmp_admin_page_title(
			sprintf( /* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */ __( 'Welcome to %s', 'nonprofit-manager' ), npmp_label( 'app_name' ) ),
			$npmp_title_actions,
			$is_pro ? __( 'Pro', 'nonprofit-manager' ) : ''
		);
		?>
		<p><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Manage %s, memberships, email lists, and events from one plugin.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ); ?></p>

		<?php if ( $setup_complete ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */ __( 'Setup complete! %s is ready to use.', 'nonprofit-manager' ), npmp_label( 'app_name' ) ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'sent' === $optin_result ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Check your inbox for an email to confirm your Nonprofit Manager newsletter signup.', 'nonprofit-manager' ); ?></p></div>
		<?php elseif ( 'failed' === $optin_result ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'We couldn\'t reach the Nonprofit Manager newsletter, so you aren\'t signed up yet. Your setup is saved.', 'nonprofit-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_setup_wizard' ) ); ?>"><?php esc_html_e( 'Try again from setup', 'nonprofit-manager' ); ?></a></p></div>
		<?php elseif ( 'invalid' === $optin_result ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'That email address didn\'t look right, so we didn\'t sign you up for the newsletter. Your setup is saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $pro_setup_complete ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pro setup complete. Your Pro features are active.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $features_updated ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Features updated.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>


		<div class="npmp-main-container">
			<div class="npmp-features-card">
				<h2><?php echo esc_html( sprintf( /* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */ __( 'Turn %s Features On or Off', 'nonprofit-manager' ), npmp_label( 'app_name' ) ) ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'npmp_save_features', 'npmp_features_nonce' ); ?>

					<?php $npmp_feature_names = npmp_feature_names(); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html( $npmp_feature_names['members'] ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_members" id="npmp_feature_members" <?php checked( $features['members'] ); ?>>
								<?php esc_html_e( 'A contact list, membership levels, and signup forms.', 'nonprofit-manager' ); ?></label></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html( $npmp_feature_names['newsletters'] ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_newsletters" id="npmp_feature_newsletters" <?php checked( $features['newsletters'] ); ?> <?php disabled( ! $features['members'] ); ?>>
								<?php echo esc_html( sprintf( /* translators: 1: lowercase plural word for members, such as members, 2: the Member Tracking feature name. */ __( 'Send newsletters and scheduled event emails to your %1$s. Needs %2$s.', 'nonprofit-manager' ), npmp_label( 'members_lc' ), $npmp_feature_names['members'] ) ); ?></label></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html( $npmp_feature_names['donations'] ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_donations" <?php checked( $features['donations'] ); ?>>
								<?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Take %s online and keep a record of each one.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ); ?></label></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html( $npmp_feature_names['calendar'] ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_calendar" <?php checked( $features['calendar'] ); ?>>
								<?php esc_html_e( 'A public event calendar with an iCal feed and a print button.', 'nonprofit-manager' ); ?></label></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html( $npmp_feature_names['social'] ); ?></th>
							<td><label><input type="checkbox" name="npmp_feature_social" <?php checked( ! empty( $features['social'] ) ); ?>>
								<?php esc_html_e( 'Share each new post and event to Facebook and X as you publish.', 'nonprofit-manager' ); ?></label></td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Features', 'nonprofit-manager' ) ); ?>
				</form>
				<script>
				// Newsletters need Member Tracking. The box used to stay greyed
				// until you saved Member Tracking on, so turning both on took
				// two saves.
				( function () {
					var members = document.getElementById( 'npmp_feature_members' );
					var letters = document.getElementById( 'npmp_feature_newsletters' );
					if ( ! members || ! letters ) {
						return;
					}
					members.addEventListener( 'change', function () {
						letters.disabled = ! members.checked;
						if ( ! members.checked ) {
							letters.checked = false;
						}
					} );
				} )();
				</script>
			</div>

			<div class="npmp-sidebar-card">
				<h2><?php esc_html_e( 'Quick Links', 'nonprofit-manager' ); ?></h2>
				<ul>
					<li><strong><?php esc_html_e( 'Settings', 'nonprofit-manager' ); ?></strong></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_general_settings' ) ); ?>"><?php esc_html_e( 'General Settings', 'nonprofit-manager' ); ?></a></li>
					<?php if ( ! empty( $features['members'] ) ) : ?>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_email_settings' ) ); ?>"><?php esc_html_e( 'Email Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['members'] ) ) : ?>
						<li class="npmp-quick-links__group"><strong><?php echo esc_html( $npmp_feature_names['members'] ); ?></strong></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_membership' ) ); ?>"><?php esc_html_e( 'Membership Dashboard', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>"><?php esc_html_e( 'Contacts', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_membership_forms' ) ); ?>"><?php esc_html_e( 'Membership Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['newsletters'] ) ) : ?>
						<li class="npmp-quick-links__group"><strong><?php esc_html_e( 'Email Newsletters', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=npmp_newsletter' ) ); ?>"><?php esc_html_e( 'Add Newsletter', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_newsletter_templates' ) ); ?>"><?php esc_html_e( 'Newsletter Templates', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_newsletter_archive' ) ); ?>"><?php esc_html_e( 'Newsletter Archive', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_newsletter_reports' ) ); ?>"><?php esc_html_e( 'Newsletter Reports', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_newsletter_settings' ) ); ?>"><?php esc_html_e( 'Newsletter Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['donations'] ) ) : ?>
						<li class="npmp-quick-links__group"><strong><?php echo esc_html( npmp_label( 'donations' ) ); ?></strong></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_donations_group' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( '%s Dashboard', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_payment_settings' ) ); ?>"><?php esc_html_e( 'Payment Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['calendar'] ) ) : ?>
						<li class="npmp-quick-links__group"><strong><?php echo esc_html( $npmp_feature_names['calendar'] ); ?></strong></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp-events' ) ); ?>"><?php esc_html_e( 'Events Overview', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ); ?>"><?php esc_html_e( 'All Events', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=npmp_event' ) ); ?>"><?php esc_html_e( 'Add Event', 'nonprofit-manager' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_event_settings' ) ); ?>"><?php esc_html_e( 'Calendar Settings', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>

					<?php if ( ! empty( $features['social'] ) ) : ?>
						<li class="npmp-quick-links__group"><strong><?php esc_html_e( 'Social Sharing', 'nonprofit-manager' ); ?></strong></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_social_sharing' ) ); ?>"><?php esc_html_e( 'Social Sharing', 'nonprofit-manager' ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<!-- Summary Tables -->
		<?php
		/** This action is documented in includes/npmp-pro-feature-pages.php. */
		do_action( 'npmp_admin_table_toolbar', 'overview', array( 'features' => $features ) );
		?>
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
								<th><?php echo esc_html( sprintf( /* translators: %s: plural word for members, such as Members. */ __( 'Total %s', 'nonprofit-manager' ), npmp_label( 'members' ) ) ); ?></th>
								<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></td>
							</tr>
							<?php
						}
						?>
					</table>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>" class="button"><?php esc_html_e( 'View contacts', 'nonprofit-manager' ); ?></a></p>
				</div>
			<?php endif; ?>

			<!-- Donations Summary -->
			<?php if ( ! empty( $features['donations'] ) && class_exists( 'NPMP_Donation_Manager' ) ) : ?>
				<div class="npmp-summary-card">
					<h3><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( '%s Summary', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></h3>
					<table class="npmp-summary-table">
						<?php
						// Year-to-date donations
						$ytd_totals = npmp_get_ytd_donation_totals_by_currency();
						?>
						<tr>
							<th><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'Year-to-Date %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></th>
							<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_totals( $ytd_totals ) ); ?></td>
						</tr>

						<?php
						// Annual recurring donations
						$recurring_totals = npmp_get_annual_recurring_totals_by_currency();
						?>
						<tr>
							<th><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'Annual Recurring %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></th>
							<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_totals( $recurring_totals ) ); ?></td>
						</tr>
					</table>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_donations_group' ) ); ?>" class="button"><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'View %s Dashboard', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></a></p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $is_pro ) : ?>
			<div class="npmp-upgrade-section">
				<h2><?php echo esc_html( sprintf( /* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */ __( 'Thank you for using %s', 'nonprofit-manager' ), npmp_label( 'app_name' ) ) ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: lowercase plural word for groups like this one, such as nonprofits or clubs, 2: Pro's starting price, e.g. "$47 a year". */
						esc_html__( 'The free plugin covers what most %1$s need. Nonprofit Manager Pro starts at %2$s when you want more.', 'nonprofit-manager' ),
						esc_html( npmp_label( 'orgs_lc' ) ),
						esc_html( npmp_get_pro_starting_price() )
					);
					?>
				</p>

				<div class="npmp-upgrade-box">
					<h3><?php esc_html_e( 'Upgrade to Nonprofit Manager Pro', 'nonprofit-manager' ); ?></h3>
					<ul>
						<li><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Recurring %s and membership dues billing', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ); ?></li>
						<li><?php esc_html_e( 'Groups with their own newsletters, events, calendars, and signup forms', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'As many scheduled emails as you want for each event and group', 'nonprofit-manager' ); ?></li>
						<li><?php echo esc_html( sprintf( /* translators: 1: lowercase plural word for members, such as members, 2: lowercase plural word for donations, such as donations or contributions. */ __( 'Custom fields for %1$s and %2$s', 'nonprofit-manager' ), npmp_label( 'members_lc' ), npmp_label( 'donations_lc' ) ) ); ?></li>
						<li><?php esc_html_e( 'Email automations and saved segments', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Print or save as PDF for every admin list and report', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Email support', 'nonprofit-manager' ); ?></li>
					</ul>
					<a href="<?php echo esc_url( npmp_get_upgrade_url( 'overview' ) ); ?>" class="button button-primary" target="_blank" rel="noopener">
						<?php esc_html_e( 'See Pro', 'nonprofit-manager' ); ?>
					</a>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
