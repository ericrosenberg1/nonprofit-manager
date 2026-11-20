<?php
/**
 * File path: includes/npmp-pro-notice.php
 *
 * The post-setup Pro notice.
 *
 * A free install meets Pro inside the setup wizard and then on the settings
 * screens that gate a Pro feature. Someone who finishes the wizard and never
 * opens Payment Settings never meets it at all, and wordpress.org sent 610
 * downloads in the week to 2026-11-20 with no Pro order behind them
 * (ROS-4454). This adds one dismissible notice on Nonprofit Manager's own
 * admin screens, for an administrator on the free plugin who has finished or
 * skipped the wizard.
 *
 * It takes its turn behind the two asks that already exist, so a site owner
 * never faces two of our notices at once: the "Powered by" ask after a first
 * donation (npmp-credit-ask.php), then the review nudge after a usage
 * milestone (npmp-review-nudge.php). A new install has neither, so this one
 * has the screen to itself until the site does some real work.
 *
 * The link is the admin-notice medium in GA4, campaign post_setup, so the
 * notice can be told apart from the in-screen upsells (utm_medium=upsell).
 *
 * User meta:
 *   npmp_pro_notice_dismissed  Set when this user dismisses the notice. Per
 *                              user, like the review nudge, so one admin
 *                              dismissing it does not decide for another.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_pro_notice_should_show' ) ) {
	/**
	 * Whether to show the Pro notice on this request.
	 *
	 * @return bool
	 */
	function npmp_pro_notice_should_show() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( function_exists( 'npmp_is_pro' ) && npmp_is_pro() ) {
			return false;
		}
		if ( ! function_exists( 'npmp_setup_wizard_is_done' ) || ! npmp_setup_wizard_is_done() ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), 'npmp_pro_notice_dismissed', true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'npmp' ) ) {
			return false;
		}
		// Not over the wizard, which carries its own Pro note.
		if ( false !== strpos( (string) $screen->id, 'npmp_setup_wizard' ) ) {
			return false;
		}
		// One ask at a time. Both of these answer something the site owner
		// just did, so they go first.
		if ( function_exists( 'npmp_credit_ask_should_show' ) && npmp_credit_ask_should_show() ) {
			return false;
		}
		if ( function_exists( 'npmp_review_nudge_should_show' ) && npmp_review_nudge_should_show() ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'npmp_pro_notice_lines' ) ) {
	/**
	 * The notice copy, with the words this site uses for donations.
	 *
	 * Split out so a test can read it without rendering the notice. The
	 * price comes from npmp_get_pro_starting_price(), the one place it is
	 * written down.
	 *
	 * @return array {
	 *     @type string $lead Opening question.
	 *     @type string $body What Pro adds, and the price.
	 *     @type string $cta  Button label.
	 * }
	 */
	function npmp_pro_notice_lines() {
		$gifts = function_exists( 'npmp_label' ) ? npmp_label( 'donations_lc' ) : 'donations';
		$price = function_exists( 'npmp_get_pro_starting_price' ) ? npmp_get_pro_starting_price() : '$47 a year';

		return array(
			'lead' => sprintf(
				/* translators: %s: lowercase plural word for donations, such as donations, gifts or contributions. */
				__( 'Taking monthly %s or charging dues?', 'nonprofit-manager' ),
				$gifts
			),
			'body' => sprintf(
				/* translators: 1: lowercase plural word for donations, such as donations, gifts or contributions. 2: Pro's starting price, such as "$47 a year". */
				__( 'Recurring %1$s, automatic dues billing and PayPal Smart Buttons come with Nonprofit Manager Pro. You can buy Pro from %2$s, and you have 30 days to ask for your money back.', 'nonprofit-manager' ),
				$gifts,
				$price
			),
			'cta'  => __( 'See Pro plans', 'nonprofit-manager' ),
		);
	}
}

/*
 * Handle the dismissal, nonce-guarded, before notices render.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['npmp_pro_notice'] ) ) {
			return;
		}
		check_admin_referer( 'npmp_pro_notice' );

		update_user_meta( get_current_user_id(), 'npmp_pro_notice_dismissed', 1 );

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'npmp_pro_notice', '_wpnonce' ), $back ) : admin_url( 'admin.php?page=npmp_main' );
		wp_safe_redirect( $back );
		exit;
	}
);

/*
 * Render the notice.
 */
add_action(
	'admin_notices',
	static function () {
		if ( ! npmp_pro_notice_should_show() ) {
			return;
		}

		$lines        = npmp_pro_notice_lines();
		$pricing      = npmp_get_upgrade_url( 'post_setup', 'admin-notice' );
		$dismiss_link = wp_nonce_url(
			add_query_arg( 'npmp_pro_notice', 'dismiss', admin_url( 'admin.php?page=npmp_main' ) ),
			'npmp_pro_notice'
		);
		?>
		<div class="notice notice-info npmp-pro-notice" style="border-left-color:#10B981;">
			<p style="font-size:14px;margin:.75em 0;">
				<strong><?php echo esc_html( $lines['lead'] ); ?></strong>
				<?php echo esc_html( $lines['body'] ); ?>
			</p>
			<p style="margin:.75em 0;">
				<a href="<?php echo esc_url( $pricing ); ?>" class="button button-primary" target="_blank" rel="noopener"><?php echo esc_html( $lines['cta'] ); ?></a>
				<a href="<?php echo esc_url( $dismiss_link ); ?>" class="button-link" style="margin-left:8px;color:#64748b;"><?php esc_html_e( 'Dismiss', 'nonprofit-manager' ); ?></a>
			</p>
		</div>
		<?php
	}
);
