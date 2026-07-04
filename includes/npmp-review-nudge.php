<?php
/**
 * File path: includes/npmp-review-nudge.php
 *
 * Post-milestone review nudge.
 *
 * Once the site reaches a real usage milestone (first donation recorded or
 * first newsletter sent), a dismissible notice on Nonprofit Manager admin
 * screens invites a WordPress.org review. Unhappy admins are routed to private
 * feedback instead of a public rating, so problems reach the author first.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_mark_milestone' ) ) {
	/**
	 * Record the first real usage milestone. Runs once, then no-ops cheaply.
	 *
	 * @param string $type Milestone source, e.g. 'donation' or 'newsletter'.
	 */
	function npmp_mark_milestone( $type = '' ) {
		if ( get_option( 'npmp_first_milestone_at' ) ) {
			return;
		}
		update_option( 'npmp_first_milestone_at', time(), false );
	}
}

if ( ! function_exists( 'npmp_review_nudge_review_url' ) ) {
	/**
	 * WordPress.org "leave a review" destination.
	 *
	 * @return string
	 */
	function npmp_review_nudge_review_url() {
		return apply_filters(
			'npmp_review_nudge_review_url',
			'https://wordpress.org/support/plugin/nonprofit-manager/reviews/#new-post'
		);
	}
}

if ( ! function_exists( 'npmp_review_nudge_feedback_url' ) ) {
	/**
	 * Private feedback destination for admins who are not ready to give 5 stars.
	 *
	 * @return string
	 */
	function npmp_review_nudge_feedback_url() {
		return apply_filters(
			'npmp_review_nudge_feedback_url',
			'mailto:support@ericrosenberg.com?subject=' . rawurlencode( 'Nonprofit Manager feedback' )
		);
	}
}

if ( ! function_exists( 'npmp_review_nudge_should_show' ) ) {
	/**
	 * Show only to admins, on our screens, after a milestone, until dismissed.
	 *
	 * @return bool
	 */
	function npmp_review_nudge_should_show() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! get_option( 'npmp_first_milestone_at' ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), 'npmp_review_nudge_dismissed', true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'npmp' ) ) {
			return false;
		}
		return true;
	}
}

/*
 * Handle the review click and the dismissal, nonce-guarded, before notices
 * render. Any action marks the nudge dismissed so it stops after one choice.
 */
add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['npmp_review_nudge'] ) ) {
			return;
		}
		check_admin_referer( 'npmp_review_nudge' );

		$action = sanitize_key( wp_unslash( $_GET['npmp_review_nudge'] ) );
		update_user_meta( get_current_user_id(), 'npmp_review_nudge_dismissed', 1 );

		if ( 'review' === $action ) {
			wp_redirect( npmp_review_nudge_review_url() );
			exit;
		}

		$back = wp_get_referer();
		$back = $back ? remove_query_arg( array( 'npmp_review_nudge', '_wpnonce' ), $back ) : admin_url( 'admin.php?page=npmp_main' );
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
		if ( ! npmp_review_nudge_should_show() ) {
			return;
		}

		$review_link  = wp_nonce_url(
			add_query_arg( 'npmp_review_nudge', 'review', admin_url( 'admin.php?page=npmp_main' ) ),
			'npmp_review_nudge'
		);
		$dismiss_link = wp_nonce_url(
			add_query_arg( 'npmp_review_nudge', 'dismiss', admin_url( 'admin.php?page=npmp_main' ) ),
			'npmp_review_nudge'
		);
		$feedback_url = npmp_review_nudge_feedback_url();

		$msg1 = sprintf(
			/* translators: 1: opening link tag to the review form, 2: closing link tag. */
			__( 'Think Nonprofit Manager deserves a 5-star rating? Please take a moment to %1$srate it here%2$s. It\'s a huge help for us and doesn\'t cost you a cent!', 'nonprofit-manager' ),
			'<a href="' . esc_url( $review_link ) . '">',
			'</a>'
		);
		$msg2 = sprintf(
			/* translators: 1: opening link tag to the feedback channel, 2: closing link tag. */
			__( 'Think we\'ve earned less than 5 stars? Please %1$ssend feedback here%2$s so we can earn your 5-star review.', 'nonprofit-manager' ),
			'<a href="' . esc_url( $feedback_url ) . '">',
			'</a>'
		);
		?>
		<div class="notice notice-info npmp-review-nudge" style="border-left-color:#16a34a;">
			<p style="font-size:14px;margin:.75em 0;">
				<?php echo wp_kses_post( $msg1 . ' ' . $msg2 ); ?>
			</p>
			<p style="margin:.75em 0;">
				<a href="<?php echo esc_url( $review_link ); ?>" class="button button-primary"><?php esc_html_e( 'Leave a 5-star review', 'nonprofit-manager' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_link ); ?>" class="button-link" style="margin-left:8px;color:#64748b;"><?php esc_html_e( 'Dismiss', 'nonprofit-manager' ); ?></a>
			</p>
		</div>
		<?php
	}
);
