<?php
/**
 * File path: includes/social-sharing/admin-social.php
 *
 * Admin page for configuring social-sharing networks and settings.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * 1. Register the admin menu item
 * ----------------------------------------------------------------*/
add_action(
	'admin_menu',
	static function () {
		add_submenu_page(
			'npmp_main',
			__( 'Social Sharing', 'nonprofit-manager' ),
			__( 'Social Sharing', 'nonprofit-manager' ),
			'manage_options',
			'npmp_social_sharing',
			'npmp_render_social_sharing_page'
		);
	}
);

/* ------------------------------------------------------------------
 * 2. Handle form submissions
 * ----------------------------------------------------------------*/
add_action(
	'admin_init',
	static function () {
		$manager = NPMP_Social_Share_Manager::get_instance();

		// Save settings.
		if (
			isset( $_POST['npmp_social_settings_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_social_settings_nonce'] ) ), 'npmp_save_social_settings' )
		) {
			$settings = array(
				'auto_share'     => ! empty( $_POST['npmp_auto_share'] ),
				'share_template' => isset( $_POST['npmp_share_template'] )
					? sanitize_textarea_field( wp_unslash( $_POST['npmp_share_template'] ) )
					: "{title}\n\n{excerpt}\n\n{url}",
			);
			$manager->save_settings( $settings );
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_social_sharing&updated=1' ) );
			exit;
		}

		// Connect a network.
		if (
			isset( $_POST['npmp_social_connect_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_social_connect_nonce'] ) ), 'npmp_social_connect' )
		) {
			$network = isset( $_POST['npmp_network'] ) ? sanitize_key( wp_unslash( $_POST['npmp_network'] ) ) : '';
			if ( $network ) {
				$all_fields  = apply_filters( 'npmp_social_credential_fields', array() );
				$fields      = isset( $all_fields[ $network ] ) ? $all_fields[ $network ] : array();
				$credentials = array();
				foreach ( $fields as $field ) {
					$key                  = $field['key'];
					$credentials[ $key ]  = isset( $_POST[ 'npmp_cred_' . $key ] )
						? sanitize_text_field( wp_unslash( $_POST[ 'npmp_cred_' . $key ] ) )
						: '';
				}
				$manager->connect_account( $network, $credentials );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_social_sharing&connected=' . $network ) );
			exit;
		}

		// Disconnect a network.
		if (
			isset( $_POST['npmp_social_disconnect_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_social_disconnect_nonce'] ) ), 'npmp_social_disconnect' )
		) {
			$network = isset( $_POST['npmp_network'] ) ? sanitize_key( wp_unslash( $_POST['npmp_network'] ) ) : '';
			if ( $network ) {
				$manager->disconnect_account( $network );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_social_sharing&disconnected=' . $network ) );
			exit;
		}

		// Test share.
		if (
			isset( $_POST['npmp_social_test_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_social_test_nonce'] ) ), 'npmp_social_test' )
		) {
			$network = isset( $_POST['npmp_network'] ) ? sanitize_key( wp_unslash( $_POST['npmp_network'] ) ) : '';
			if ( $network && $manager->is_connected( $network ) ) {
				// Use the latest published post for testing.
				$latest = get_posts(
					array(
						'numberposts' => 1,
						'post_status' => 'publish',
						'post_type'   => array( 'post', 'npmp_event' ),
					)
				);

				if ( ! empty( $latest ) ) {
					$post_data   = $manager->get_post_data( $latest[0]->ID );
					$text        = $manager->format_for_network( $network, $post_data );
					$share_data  = array_merge( $post_data, array( 'text' => $text ) );
					$credentials = $manager->get_connected_accounts()[ $network ];

					$test_result = apply_filters( "npmp_social_share_{$network}", null, $share_data, $credentials );

					if ( is_wp_error( $test_result ) ) {
						set_transient( 'npmp_social_test_error', $test_result->get_error_message(), 30 );
					} else {
						set_transient( 'npmp_social_test_success', $network, 30 );
					}
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=npmp_social_sharing&tested=' . $network ) );
			exit;
		}
	}
);

/* ------------------------------------------------------------------
 * 3. Render the admin page
 * ----------------------------------------------------------------*/

/**
 * Render the Social Sharing settings page.
 */
function npmp_render_social_sharing_page() {
	$manager   = NPMP_Social_Share_Manager::get_instance();
	$settings  = $manager->get_settings();
	$networks  = $manager->get_registered_networks();
	$accounts  = $manager->get_connected_accounts();
	$is_pro    = npmp_is_pro();

	$all_fields = apply_filters( 'npmp_social_credential_fields', array() );

	// Free-tier networks.
	$free_networks = array( 'facebook_page', 'x_twitter' );

	// Flash messages.
	$connected   = isset( $_GET['connected'] ) ? sanitize_key( wp_unslash( $_GET['connected'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$disconnected = isset( $_GET['disconnected'] ) ? sanitize_key( wp_unslash( $_GET['disconnected'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$updated     = isset( $_GET['updated'] ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tested      = isset( $_GET['tested'] ) ? sanitize_key( wp_unslash( $_GET['tested'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$test_error   = get_transient( 'npmp_social_test_error' );
	$test_success = get_transient( 'npmp_social_test_success' );
	delete_transient( 'npmp_social_test_error' );
	delete_transient( 'npmp_social_test_success' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Social Sharing', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Connect your social networks to automatically share new posts and events.', 'nonprofit-manager' ); ?></p>

		<?php if ( $connected ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network connected successfully.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $disconnected ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Network disconnected.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>
		<?php if ( $test_error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>
					<?php esc_html_e( 'Test share failed:', 'nonprofit-manager' ); ?>
					<?php echo esc_html( $test_error ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( $test_success ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test share sent successfully!', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>

		<style>
			.npmp-social-grid { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px; }
			.npmp-social-card { flex: 0 0 calc(50% - 10px); background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; box-sizing: border-box; }
			.npmp-social-card h3 { margin-top: 0; display: flex; align-items: center; gap: 8px; }
			.npmp-social-badge { display: inline-block; padding: 2px 8px; border-radius: 8px; font-size: 11px; font-weight: 600; }
			.npmp-social-badge--connected { background: #d4edda; color: #155724; }
			.npmp-social-badge--disconnected { background: #f8d7da; color: #721c24; }
			.npmp-social-badge--pro { background: #e8f0fe; color: #2271b1; }
			.npmp-social-settings { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin-top: 20px; }
			.npmp-social-settings h2 { margin-top: 0; }
			.npmp-social-card .form-table th { width: 160px; padding: 6px 10px 6px 0; }
			.npmp-social-card .form-table td { padding: 6px 10px; }
			@media screen and (max-width: 782px) {
				.npmp-social-card { flex: 0 0 100%; }
			}
		</style>

		<!-- Settings -->
		<div class="npmp-social-settings">
			<h2><?php esc_html_e( 'Sharing Settings', 'nonprofit-manager' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'npmp_save_social_settings', 'npmp_social_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Auto-Share on Publish', 'nonprofit-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="npmp_auto_share" value="1" <?php checked( $settings['auto_share'] ); ?>>
								<?php esc_html_e( 'Automatically share to connected networks when a post or event is published.', 'nonprofit-manager' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Share Template', 'nonprofit-manager' ); ?></th>
						<td>
							<textarea name="npmp_share_template" rows="4" cols="60" class="large-text"><?php echo esc_textarea( $settings['share_template'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Available placeholders: {title}, {excerpt}, {url}', 'nonprofit-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'nonprofit-manager' ) ); ?>
			</form>
		</div>

		<!-- Network cards -->
		<h2 style="margin-top: 30px;"><?php esc_html_e( 'Connected Networks', 'nonprofit-manager' ); ?></h2>
		<div class="npmp-social-grid">
			<?php foreach ( $networks as $slug => $network ) :
				$is_free      = in_array( $slug, $free_networks, true );
				$is_available = $is_free || $is_pro;
				$connected_now = isset( $accounts[ $slug ] );
				$fields       = isset( $all_fields[ $slug ] ) ? $all_fields[ $slug ] : array();
			?>
				<div class="npmp-social-card">
					<h3>
						<?php echo esc_html( $network['label'] ); ?>
						<?php if ( $connected_now ) : ?>
							<span class="npmp-social-badge npmp-social-badge--connected"><?php esc_html_e( 'Connected', 'nonprofit-manager' ); ?></span>
						<?php elseif ( $is_available ) : ?>
							<span class="npmp-social-badge npmp-social-badge--disconnected"><?php esc_html_e( 'Not Connected', 'nonprofit-manager' ); ?></span>
						<?php else : ?>
							<span class="npmp-social-badge npmp-social-badge--pro"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span>
						<?php endif; ?>
					</h3>

					<?php if ( ! $is_available ) : ?>
						<p>
							<?php esc_html_e( 'This network is available with Nonprofit Manager Pro.', 'nonprofit-manager' ); ?>
							<a href="<?php echo esc_url( npmp_get_upgrade_url() ); ?>" target="_blank">
								<?php esc_html_e( 'Upgrade to Pro', 'nonprofit-manager' ); ?>
							</a>
						</p>
					<?php elseif ( $connected_now ) : ?>
						<!-- Disconnect + Test -->
						<form method="post" style="display: inline-block; margin-right: 8px;">
							<?php wp_nonce_field( 'npmp_social_disconnect', 'npmp_social_disconnect_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<?php submit_button( __( 'Disconnect', 'nonprofit-manager' ), 'delete small', 'submit', false ); ?>
						</form>
						<form method="post" style="display: inline-block;">
							<?php wp_nonce_field( 'npmp_social_test', 'npmp_social_test_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<?php submit_button( __( 'Test Share', 'nonprofit-manager' ), 'secondary small', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<!-- Connect form -->
						<form method="post">
							<?php wp_nonce_field( 'npmp_social_connect', 'npmp_social_connect_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<table class="form-table">
								<?php foreach ( $fields as $field ) : ?>
									<tr>
										<th><label for="npmp_cred_<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
										<td>
											<input
												type="<?php echo esc_attr( $field['type'] ); ?>"
												id="npmp_cred_<?php echo esc_attr( $field['key'] ); ?>"
												name="npmp_cred_<?php echo esc_attr( $field['key'] ); ?>"
												class="regular-text"
												autocomplete="off"
											>
											<?php if ( ! empty( $field['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</table>
							<?php submit_button( __( 'Connect', 'nonprofit-manager' ), 'primary small', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}
