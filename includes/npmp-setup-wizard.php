<?php
/**
 * File path: includes/npmp-setup-wizard.php
 *
 * Setup wizard for first-time Nonprofit Manager users
 */
defined( 'ABSPATH' ) || exit;

/*
 * Activation redirect.
 *
 * Activating the plugin on a fresh site leaves a flag (the option below). The
 * next admin page an administrator opens sends them to the wizard once, then
 * the flag is gone. The flag is an option rather than a 30-second transient
 * so a site set up by a host's installer still gets the wizard the first time
 * someone signs in.
 *
 * The flag used to be a transient that the redirect handler deleted before
 * asking npmp_should_show_setup_wizard(), which read that same transient, so
 * the redirect never fired. The decision is now a pure function of its inputs
 * (npmp_setup_redirect_decision) and tests/test-setup-wizard-redirect.php pins
 * every case.
 */
const NPMP_SETUP_REDIRECT_FLAG = 'npmp_setup_wizard_redirect';

/**
 * Options any earlier run of the plugin leaves behind. Each is written by an
 * activation or the first page load after one, or by the owner saving a
 * setting, so finding one means this site has run Nonprofit Manager before.
 */
const NPMP_PRIOR_INSTALL_OPTIONS = array(
	'npmp_roles_version',
	'npmp_newsletter_rate_limit',
	'npmp_newsletter_queue_table_created',
	'npmp_payment_log_table_created',
	'npmp_enabled_features',
	'npmp_membership_levels',
	'npmp_members_migrated_to_cpt',
	'npmp_donations_migrated_to_cpt',
);

/**
 * Whether the owner has finished or skipped the setup wizard.
 *
 * @return bool
 */
function npmp_setup_wizard_is_done() {
	return (bool) get_option( 'npmp_setup_completed', false );
}

/**
 * Whether this site ran Nonprofit Manager before the current activation.
 *
 * Call it before the activation tasks run, because those write the same
 * options. A previous install shows up as any option in
 * NPMP_PRIOR_INSTALL_OPTIONS, or as stored contacts or donations (which
 * outlive a cleared options table), or as rows in the legacy members table.
 *
 * @return bool
 */
function npmp_site_was_configured_before() {
	foreach ( NPMP_PRIOR_INSTALL_OPTIONS as $option ) {
		if ( false !== get_option( $option, false ) ) {
			return true;
		}
	}

	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off check at activation, nothing to cache.
	if ( $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'npmp_contact', 'npmp_donation' ) LIMIT 1" ) ) {
		return true;
	}

	$legacy = $wpdb->prefix . 'npmp_members';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off check at activation, nothing to cache.
	if ( $legacy === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name built from $wpdb->prefix.
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$legacy} LIMIT 1" );
	}

	return false;
}

/**
 * Whether an activation should leave the one-time wizard redirect behind.
 *
 * Only a fresh, single-site activation does. A network activation has no one
 * site to send anyone to, WP-CLI has no browser, and a site that finished the
 * wizard or ran the plugin before already knows its way around.
 *
 * @param array $ctx Booleans: network_wide (network-wide activation),
 *                   is_cli (WP-CLI), setup_completed (wizard finished or
 *                   skipped), configured_before (npmp_site_was_configured_before()).
 * @return bool
 */
function npmp_should_queue_setup_wizard( array $ctx ) {
	return empty( $ctx['network_wide'] )
		&& empty( $ctx['is_cli'] )
		&& empty( $ctx['setup_completed'] )
		&& empty( $ctx['configured_before'] );
}

/**
 * Set or clear the redirect flag for this activation. Runs first in the
 * activation tasks, before they write the options the prior-install check
 * looks for.
 *
 * @param bool $network_wide Network-wide activation on multisite.
 * @return void
 */
function npmp_queue_setup_wizard_on_activation( $network_wide = false ) {
	$queue = npmp_should_queue_setup_wizard(
		array(
			'network_wide'      => (bool) $network_wide,
			'is_cli'            => defined( 'WP_CLI' ) && WP_CLI,
			'setup_completed'   => npmp_setup_wizard_is_done(),
			'configured_before' => npmp_site_was_configured_before(),
		)
	);

	if ( $queue ) {
		update_option( NPMP_SETUP_REDIRECT_FLAG, 1, true );
	} else {
		delete_option( NPMP_SETUP_REDIRECT_FLAG );
	}
}

/**
 * Describe the current request for npmp_setup_redirect_decision().
 *
 * @return array
 */
function npmp_setup_redirect_request_context() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing checks, nothing is saved.
	$ctx = array(
		'can_manage'       => current_user_can( 'manage_options' ),
		'is_ajax'          => wp_doing_ajax(),
		'is_rest'          => defined( 'REST_REQUEST' ) && REST_REQUEST,
		'is_cron'          => wp_doing_cron(),
		'is_cli'           => defined( 'WP_CLI' ) && WP_CLI,
		'is_xmlrpc'        => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
		'is_iframe'        => defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST,
		'headers_sent'     => headers_sent(),
		'method'           => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '',
		'is_bulk'          => isset( $_GET['activate-multi'] ),
		'is_network_admin' => is_network_admin() || is_user_admin(),
		'page'             => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '',
	);
	// phpcs:enable

	return $ctx;
}

/**
 * Decide what to do with a pending wizard redirect on this request.
 *
 * Returns one of:
 *   'none'     no redirect is pending.
 *   'wait'     leave the flag for a later request: this one is a background
 *              call (AJAX, REST, cron, WP-CLI, XML-RPC), a form post, an
 *              iframe, a response already under way, or a user who can't
 *              open the wizard (a Membership Manager signing in first would
 *              otherwise use the flag up on a page they can't open).
 *   'clear'    drop the flag without redirecting: bulk activation, network
 *              or user admin, setup already done, or already on the wizard.
 *   'redirect' drop the flag and send the administrator to the wizard.
 *
 * @param bool   $pending     The redirect flag is set.
 * @param bool   $done        Setup was finished or skipped.
 * @param array  $ctx         npmp_setup_redirect_request_context().
 * @param string $wizard_slug The wizard's admin page slug.
 * @return string
 */
function npmp_setup_redirect_decision( $pending, $done, array $ctx, $wizard_slug = 'npmp_setup_wizard' ) {
	if ( ! $pending ) {
		return 'none';
	}

	$ctx = array_merge(
		array(
			'can_manage'       => false,
			'is_ajax'          => false,
			'is_rest'          => false,
			'is_cron'          => false,
			'is_cli'           => false,
			'is_xmlrpc'        => false,
			'is_iframe'        => false,
			'headers_sent'     => false,
			'method'           => 'GET',
			'is_bulk'          => false,
			'is_network_admin' => false,
			'page'             => '',
		),
		$ctx
	);

	if ( ! $ctx['can_manage'] ) {
		return 'wait';
	}
	if ( $ctx['is_ajax'] || $ctx['is_rest'] || $ctx['is_cron'] || $ctx['is_cli'] || $ctx['is_xmlrpc'] ) {
		return 'wait';
	}
	if ( $ctx['is_iframe'] || $ctx['headers_sent'] || 'GET' !== $ctx['method'] ) {
		return 'wait';
	}
	if ( $ctx['is_bulk'] || $ctx['is_network_admin'] ) {
		return 'clear';
	}
	if ( $done || $wizard_slug === $ctx['page'] ) {
		return 'clear';
	}

	return 'redirect';
}

/**
 * Whether the free wizard redirect is still waiting to happen.
 *
 * @return bool
 */
function npmp_setup_wizard_redirect_pending() {
	return (bool) get_option( NPMP_SETUP_REDIRECT_FLAG, false ) && ! npmp_setup_wizard_is_done();
}

/**
 * Whether the free wizard owns the screen right now: its redirect is pending
 * or the administrator is on it and hasn't finished or skipped it. Pro's
 * wizard waits on this, so activating both shows the free wizard first.
 *
 * @param string $page Current admin page slug. Read from the request when null.
 * @return bool
 */
function npmp_setup_wizard_in_progress( $page = null ) {
	if ( npmp_setup_wizard_redirect_pending() ) {
		return true;
	}
	if ( null === $page ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}
	return 'npmp_setup_wizard' === $page && ! npmp_setup_wizard_is_done();
}

/**
 * Redirect to the setup wizard once after a fresh activation.
 *
 * Runs at priority 1 so it acts before Pro's wizard redirect.
 *
 * @return void
 */
function npmp_maybe_redirect_to_setup_wizard() {
	$pending = (bool) get_option( NPMP_SETUP_REDIRECT_FLAG, false );
	if ( ! $pending ) {
		return;
	}

	$decision = npmp_setup_redirect_decision( $pending, npmp_setup_wizard_is_done(), npmp_setup_redirect_request_context() );
	if ( 'clear' !== $decision && 'redirect' !== $decision ) {
		return;
	}

	delete_option( NPMP_SETUP_REDIRECT_FLAG );

	if ( 'redirect' === $decision ) {
		wp_safe_redirect( admin_url( 'admin.php?page=npmp_setup_wizard' ) );
		exit;
	}
}
add_action( 'admin_init', 'npmp_maybe_redirect_to_setup_wizard', 1 );

/**
 * Register setup wizard page
 */
add_action(
	'admin_menu',
	static function () {
		$hook = add_submenu_page(
			null, // Hidden from menu
			__( 'Nonprofit Manager Setup', 'nonprofit-manager' ),
			__( 'Setup', 'nonprofit-manager' ),
			'manage_options',
			'npmp_setup_wizard',
			'npmp_render_setup_wizard'
		);

		// A null-parent page never lands in the $submenu array, so core's
		// get_admin_page_title() leaves the global $title null and PHP 8.1+
		// warns on strip_tags( null ) in admin-header.php. Set it ourselves
		// before the header renders.
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () {
					$GLOBALS['title'] = __( 'Nonprofit Manager Setup', 'nonprofit-manager' );
				}
			);
		}
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
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_setup_nonce'] ) ), 'npmp_setup_wizard' ) &&
			current_user_can( 'manage_options' )
		) {
			$step = isset( $_POST['npmp_setup_step'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_setup_step'] ) ) : '';

			// Skip leaves every setting as it is and counts as done, so the
			// wizard never comes back on its own. It can still be opened from
			// admin.php?page=npmp_setup_wizard.
			if ( isset( $_POST['npmp_setup_skip'] ) ) {
				update_option( 'npmp_setup_completed', true );
				delete_option( NPMP_SETUP_REDIRECT_FLAG );
				wp_safe_redirect( admin_url( 'admin.php?page=npmp_main' ) );
				exit;
			}

			if ( 'complete' === $step ) {
				// Save selected features
				$enabled = array(
					'members'     => isset( $_POST['npmp_feature_members'] ),
					'newsletters' => isset( $_POST['npmp_feature_newsletters'] ),
					'donations'   => isset( $_POST['npmp_feature_donations'] ),
					'calendar'    => isset( $_POST['npmp_feature_calendar'] ),
					'social'      => isset( $_POST['npmp_feature_social'] ),
				);

				// Ensure newsletter dependency
				if ( ! $enabled['members'] ) {
					$enabled['newsletters'] = false;
				}

				update_option( 'npmp_enabled_features', $enabled );
				update_option( 'npmp_setup_completed', true );
				delete_option( NPMP_SETUP_REDIRECT_FLAG );

				// The newsletter opt-in below is unchecked by default and only
				// acts on a checkbox the site owner ticked themselves
				// (WordPress.org requires any data leaving the site to be
				// opt-in). The "Powered by" link is no longer offered here. It
				// is asked once after the first donation (npmp-credit-ask.php).
				if ( isset( $_POST['npmp_setup_email_optin'] ) && isset( $_POST['npmp_setup_email'] ) ) {
					$wizard_email = sanitize_email( wp_unslash( $_POST['npmp_setup_email'] ) );
					if ( is_email( $wizard_email ) && function_exists( 'npmp_subscribe_to_newsletter' ) ) {
						$current_user = wp_get_current_user();
						npmp_subscribe_to_newsletter( $wizard_email, $current_user ? $current_user->display_name : '' );
					}
				}

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
			.npmp-setup-wizard .npmp-setup-skip {
				text-align: center;
				margin: 16px 0 0;
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
							<p><?php esc_html_e( 'Send newsletters to your members. Requires Member Tracking.', 'nonprofit-manager' ); ?></p>
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
							<?php esc_html_e( 'Nonprofit Manager Pro adds recurring donations, dues billing, email automations, custom fields and segments.', 'nonprofit-manager' ); ?>
							<a href="<?php echo esc_url( npmp_get_upgrade_url( 'setup_wizard' ) ); ?>" target="_blank">
								<?php esc_html_e( 'Learn more', 'nonprofit-manager' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Optional', 'nonprofit-manager' ); ?></h2>
				<p class="npmp-setup-optin">
					<label>
						<input type="checkbox" name="npmp_setup_email_optin" value="1" onchange="document.getElementById('npmp-setup-email').disabled = !this.checked;">
						<?php esc_html_e( 'Email me setup tips and Nonprofit Manager product updates.', 'nonprofit-manager' ); ?>
					</label>
					<br>
					<input
						type="email"
						id="npmp-setup-email"
						name="npmp_setup_email"
						value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
						disabled
						style="margin-top: 6px; max-width: 320px;"
					>
					<br>
					<span class="description"><?php esc_html_e( 'Off unless checked. Double opt-in, unsubscribe any time.', 'nonprofit-manager' ); ?></span>
				</p>

				<?php submit_button( __( 'Complete Setup', 'nonprofit-manager' ), 'primary', 'submit', false ); ?>
				<p class="npmp-setup-skip">
					<button type="submit" name="npmp_setup_skip" value="1" class="button-link" formnovalidate>
						<?php esc_html_e( 'Skip setup for now', 'nonprofit-manager' ); ?>
					</button>
					<br>
					<span class="description"><?php esc_html_e( 'Keeps the default features. You can change them any time in Settings.', 'nonprofit-manager' ); ?></span>
				</p>
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
