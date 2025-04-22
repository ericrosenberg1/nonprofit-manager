<?php
/**
 * File path: includes/npmp-admin-settings.php
 *
 * “Features” toggle page for Nonprofit Manager.
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

			/* dependency: newsletters ⇒ members */
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
 * 2. Main “Overview / Features” page
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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Manage donations, memberships, email lists, and events from a single plugin.', 'nonprofit-manager' ); ?></p>

		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="updated notice is-dismissible"><p><?php esc_html_e( 'Features updated.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Choose the modules you need', 'nonprofit-manager' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'npmp_save_features', 'npmp_features_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Member Tracking', 'nonprofit-manager' ); ?></th>
					<td><label><input type="checkbox" name="npmp_feature_members" <?php checked( $features['members'] ); ?>>
						<?php esc_html_e( 'Membership tracking and signup forms.', 'nonprofit-manager' ); ?></label></td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Email Newsletters', 'nonprofit-manager' ); ?></th>
					<td><label><input type="checkbox" name="npmp_feature_newsletters" <?php checked( $features['newsletters'] ); ?> <?php disabled( ! $features['members'] ); ?>>
						<?php esc_html_e( 'Send newsletters to your members. (requires Member Tracking)', 'nonprofit-manager' ); ?></label></td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Donations', 'nonprofit-manager' ); ?></th>
					<td><label><input type="checkbox" name="npmp_feature_donations" <?php checked( $features['donations'] ); ?>>
						<?php esc_html_e( 'Collect donations and manage payment methods.', 'nonprofit-manager' ); ?></label></td>
				</tr>

				<tr>
					<th><?php esc_html_e( 'Calendar', 'nonprofit-manager' ); ?></th>
					<td><label><input type="checkbox" name="npmp_feature_calendar" <?php checked( $features['calendar'] ); ?>>
						<?php esc_html_e( 'Enable public event calendar & iCal feed.', 'nonprofit-manager' ); ?></label></td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Features', 'nonprofit-manager' ) ); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Quick Access', 'nonprofit-manager' ); ?></h2>
		<ul>
			<?php if ( ! empty( $features['members'] ) ) : ?>
				<li><a href="admin.php?page=npmp_members"><?php esc_html_e( 'Member List', 'nonprofit-manager' ); ?></a></li>
				<li><a href="admin.php?page=npmp_email_settings"><?php esc_html_e( 'Email Settings', 'nonprofit-manager' ); ?></a></li>
			<?php endif; ?>
			<?php if ( ! empty( $features['donations'] ) ) : ?>
				<li><a href="admin.php?page=npmp_payment_settings"><?php esc_html_e( 'Payment Settings', 'nonprofit-manager' ); ?></a></li>
			<?php endif; ?>
		</ul>
	</div>
	<?php
}
