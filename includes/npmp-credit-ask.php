<?php
/**
 * File path: includes/npmp-credit-ask.php
 *
 * One-time "Powered by" ask after the site's first donation.
 *
 * The attribution link in npmp-powered-by.php stays off until the site owner
 * turns it on. The setup wizard used to offer that checkbox before anything
 * had happened on the site. This file offers it once instead, right after the
 * first donation comes in, when the owner has seen the plugin work. The
 * checkbox starts unchecked (WordPress.org guideline 10: credit links need
 * the owner's explicit permission), the notice shows only on Nonprofit
 * Manager screens, and saving or declining ends the ask for the whole site.
 *
 * Options:
 *   npmp_first_donation_at  Unix time of the first recorded donation, 0 when none.
 *   npmp_credit_ask_done    Unix time an admin saved or declined the ask.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_credit_ask_first_donation_at' ) ) {
	/**
	 * When the site recorded its first donation, or 0 if it hasn't.
	 *
	 * Sites that took donations before this ask existed never had the time
	 * written, so the first check looks for the oldest donation, once, and
	 * stores the answer either way. npmp_mark_milestone() fills it in after
	 * that.
	 *
	 * @return int Unix timestamp, or 0.
	 */
	function npmp_credit_ask_first_donation_at() {
		$at = get_option( 'npmp_first_donation_at', null );
		if ( null !== $at ) {
			return (int) $at;
		}

		$at    = 0;
		$first = get_posts(
			array(
				'post_type'      => 'npmp_donation',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $first ) {
			$at = (int) get_post_time( 'U', true, $first[0] );
		}
		add_option( 'npmp_first_donation_at', $at, '', false );

		return $at;
	}
}

if ( ! function_exists( 'npmp_credit_ask_should_show' ) ) {
	/**
	 * Show to admins, on our screens, after a donation, until answered.
	 *
	 * @return bool
	 */
	function npmp_credit_ask_should_show() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( get_option( 'npmp_credit_ask_done' ) || get_option( 'npmp_powered_by_optin' ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'npmp' ) ) {
			return false;
		}
		return npmp_credit_ask_first_donation_at() > 0;
	}
}

if ( ! function_exists( 'npmp_credit_ask_apply' ) ) {
	/**
	 * Record the owner's answer.
	 *
	 * Only "save" with the box ticked turns the link on. "No thanks", or
	 * saving with the box left empty, keeps it off. Either way the ask is done.
	 *
	 * @param string $choice  'save' or 'no'.
	 * @param bool   $opt_in  Whether the checkbox was ticked.
	 * @return void
	 */
	function npmp_credit_ask_apply( $choice, $opt_in ) {
		if ( 'save' === $choice && $opt_in ) {
			update_option( 'npmp_powered_by_optin', 1 );
		}
		update_option( 'npmp_credit_ask_done', time(), false );
	}
}

/*
 * Handle the answer before any screen renders. Keyed on this form's own nonce
 * field, so the General Settings form (which reuses the checkbox name) never
 * lands here.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! isset( $_POST['npmp_credit_ask_nonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'npmp_credit_ask', 'npmp_credit_ask_nonce' );

		$choice = isset( $_POST['npmp_credit_ask_choice'] ) ? sanitize_key( wp_unslash( $_POST['npmp_credit_ask_choice'] ) ) : 'no';
		npmp_credit_ask_apply( $choice, ! empty( $_POST['npmp_powered_by_optin'] ) );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=npmp_main' ) );
		exit;
	}
);

/*
 * Render the notice.
 */
add_action(
	'admin_notices',
	static function () {
		if ( ! npmp_credit_ask_should_show() ) {
			return;
		}

		$settings_link = sprintf(
			/* translators: 1: opening link tag to General Settings, 2: closing link tag. */
			__( 'You can change this any time in %1$sGeneral Settings%2$s.', 'nonprofit-manager' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=npmp_general_settings' ) ) . '">',
			'</a>'
		);
		?>
		<div class="notice notice-success npmp-credit-ask">
			<p style="font-size:14px;margin:.75em 0 .25em;"><strong><?php esc_html_e( 'Your donation form is working!', 'nonprofit-manager' ); ?></strong></p>
			<p style="margin:.25em 0 .75em;"><?php esc_html_e( 'Nonprofit Manager is free. If you\'d like to help other nonprofits find it, you can add a small "Powered by Nonprofit Manager" link under your donation forms and in your newsletter footers.', 'nonprofit-manager' ); ?></p>
			<form method="post" style="margin:0 0 .75em;">
				<?php wp_nonce_field( 'npmp_credit_ask', 'npmp_credit_ask_nonce' ); ?>
				<p style="margin:.5em 0;">
					<label>
						<input type="checkbox" name="npmp_powered_by_optin" value="1">
						<?php esc_html_e( 'Show the "Powered by Nonprofit Manager" link', 'nonprofit-manager' ); ?>
					</label>
				</p>
				<p style="margin:.5em 0;">
					<button type="submit" name="npmp_credit_ask_choice" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'nonprofit-manager' ); ?></button>
					<button type="submit" name="npmp_credit_ask_choice" value="no" class="button-link" style="margin-left:8px;color:#64748b;"><?php esc_html_e( 'No thanks', 'nonprofit-manager' ); ?></button>
				</p>
				<p class="description" style="margin:.5em 0 0;"><?php echo wp_kses_post( $settings_link ); ?></p>
			</form>
		</div>
		<?php
	}
);
