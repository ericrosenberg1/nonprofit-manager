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
// Priority 11 so this runs AFTER the main-menu scaffold registers the
// npmp_main parent (priority 10 in nonprofit-manager.php). This file is
// require'd before that scaffold's hook is added, so at the default priority
// 10 the two hooks fire in registration order and add_submenu_page() would run
// before add_menu_page('npmp_main') exists. When that happens WordPress
// computes this page's hookname as "admin_page_npmp_social_sharing" at
// registration but "nonprofit-manager_page_npmp_social_sharing" at access
// time (once the parent exists), so the $_registered_pages lookup in
// user_can_access_admin_page() misses and the page 403s even though the
// submenu still shows. The import module (admin-import.php) uses priority 11
// for the same reason.
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
	},
	11
);

/* ------------------------------------------------------------------
 * 2. Handle form submissions
 * ----------------------------------------------------------------*/
add_action(
	'admin_init',
	static function () {
		// Every branch below persists settings or stores network credentials.
		// A valid nonce alone doesn't check the requesting user's role, so
		// gate on the same capability the admin menu item itself requires.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

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
	<div class="wrap npmp-admin-page">
		<?php npmp_admin_page_title( __( 'Social Sharing', 'nonprofit-manager' ) ); ?>
		<p><?php esc_html_e( 'Connect your social networks, and new posts and events can go out to them when you publish.', 'nonprofit-manager' ); ?></p>

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

		<!-- Settings -->
		<div class="npmp-social-settings">
			<h2><?php esc_html_e( 'Sharing settings', 'nonprofit-manager' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'npmp_save_social_settings', 'npmp_social_settings_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Share on publish', 'nonprofit-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="npmp_auto_share" value="1" <?php checked( $settings['auto_share'] ); ?>>
								<?php esc_html_e( 'Share to your connected networks when you publish a post or event.', 'nonprofit-manager' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp_share_template"><?php esc_html_e( 'Share text', 'nonprofit-manager' ); ?></label></th>
						<td>
							<textarea name="npmp_share_template" id="npmp_share_template" rows="4" cols="60" class="large-text"><?php echo esc_textarea( $settings['share_template'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Placeholders you can use: {title}, {excerpt}, and {url}.', 'nonprofit-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'nonprofit-manager' ) ); ?>
			</form>
		</div>

		<!-- Network cards -->
		<h2 class="npmp-social-networks-title"><?php esc_html_e( 'Networks', 'nonprofit-manager' ); ?></h2>
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
							<span class="npmp-social-badge npmp-social-badge--disconnected"><?php esc_html_e( 'Not connected', 'nonprofit-manager' ); ?></span>
						<?php else : ?>
							<span class="npmp-social-badge npmp-social-badge--pro"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span>
						<?php endif; ?>
					</h3>

					<?php if ( ! $is_available ) : ?>
						<p>
							<?php esc_html_e( 'Sharing to this network comes with Nonprofit Manager Pro.', 'nonprofit-manager' ); ?>
							<a href="<?php echo esc_url( npmp_get_upgrade_url( 'social_sharing' ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'See Pro', 'nonprofit-manager' ); ?>
							</a>
						</p>
					<?php elseif ( $connected_now ) : ?>
						<!-- Disconnect + Test -->
						<form method="post" class="npmp-inline-form" onsubmit="return window.confirm( '<?php echo esc_js( sprintf( /* translators: %s: social network name, such as Facebook. */ __( 'Disconnect %s? You\'ll need to enter its keys again to reconnect.', 'nonprofit-manager' ), $network['label'] ) ); ?>' );">
							<?php wp_nonce_field( 'npmp_social_disconnect', 'npmp_social_disconnect_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<?php submit_button( __( 'Disconnect', 'nonprofit-manager' ), 'delete small', 'submit', false ); ?>
						</form>
						<form method="post" class="npmp-inline-form">
							<?php wp_nonce_field( 'npmp_social_test', 'npmp_social_test_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<?php submit_button( __( 'Test share', 'nonprofit-manager' ), 'secondary small', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<!-- Connect form -->
						<form method="post">
							<?php wp_nonce_field( 'npmp_social_connect', 'npmp_social_connect_nonce' ); ?>
							<input type="hidden" name="npmp_network" value="<?php echo esc_attr( $slug ); ?>">
							<table class="form-table">
								<?php foreach ( $fields as $field ) : ?>
									<tr>
										<th scope="row"><label for="npmp_cred_<?php echo esc_attr( $field['key'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
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

			<?php
			// The networks Pro adds, shown greyed on a free site so the menu
			// item and this screen say what the upgrade buys. Pro registers
			// the real cards through npmp_social_networks, so these never
			// double up with a connected network.
			if ( ! $is_pro ) :
				$pro_networks = array(
					'bluesky'  => __( 'Bluesky', 'nonprofit-manager' ),
					'threads'  => __( 'Threads', 'nonprofit-manager' ),
					'mastodon' => __( 'Mastodon', 'nonprofit-manager' ),
					'nextdoor' => __( 'Nextdoor', 'nonprofit-manager' ),
					'reddit'   => __( 'Reddit', 'nonprofit-manager' ),
				);
				// One card for all of them: one line and one link, rather than
				// five cards each asking for the upgrade.
				$pro_networks = array_diff_key( $pro_networks, $networks );
				if ( $pro_networks ) :
					?>
					<div class="npmp-social-card" style="opacity:.75;">
						<h3>
							<?php esc_html_e( 'More networks', 'nonprofit-manager' ); ?>
							<span class="npmp-social-badge npmp-social-badge--pro"><?php esc_html_e( 'Pro', 'nonprofit-manager' ); ?></span>
						</h3>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: list of social networks, such as Bluesky, Threads, and Reddit. */
									__( 'Pro also shares to %s.', 'nonprofit-manager' ),
									wp_sprintf( '%l', array_values( $pro_networks ) )
								)
							);
							?>
							<a href="<?php echo esc_url( npmp_get_upgrade_url( 'social_sharing' ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'See Pro', 'nonprofit-manager' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
