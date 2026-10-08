<?php
/**
 * File path: includes/npmp-review-nudge.php
 *
 * Post-milestone review nudge.
 *
 * Once the site reaches a real usage milestone, a dismissible notice on
 * Nonprofit Manager admin screens invites a WordPress.org review. Unhappy
 * admins are routed to private feedback instead of a public rating, so
 * problems reach the author first.
 *
 * Milestones, whichever comes first:
 *   donation   the first donation is recorded (npmp_mark_milestone from the
 *              donation manager)
 *   newsletter the first newsletter goes out (the newsletter manager)
 *   events     10 or more published events
 *   contacts   25 or more contacts
 *   tenure     the plugin has run for 30 days on a site with at least one
 *              event or contact
 *
 * The first two are pushed by the code that records them. The other three
 * are counted on the plugin's own admin screens, for administrators who have
 * not dismissed the nudge, until one lands. A site that only runs events (a
 * neighborhood council, say) used to never be asked.
 *
 * Options:
 *   npmp_first_milestone_at    Unix time the first milestone landed.
 *   npmp_first_milestone_type  Which one it was, for the notice's first line.
 *   npmp_activated_at          Unix time the plugin was first activated. Written
 *                              on activation since 2026.10.5. Sites that
 *                              predate it get the date of their oldest record.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_REVIEW_NUDGE_EVENTS' ) ) {
	define( 'NPMP_REVIEW_NUDGE_EVENTS', 10 );
}
if ( ! defined( 'NPMP_REVIEW_NUDGE_CONTACTS' ) ) {
	define( 'NPMP_REVIEW_NUDGE_CONTACTS', 25 );
}
if ( ! defined( 'NPMP_REVIEW_NUDGE_TENURE_DAYS' ) ) {
	define( 'NPMP_REVIEW_NUDGE_TENURE_DAYS', 30 );
}

if ( ! function_exists( 'npmp_mark_milestone' ) ) {
	/**
	 * Record the first real usage milestone. Runs once, then no-ops cheaply.
	 *
	 * @param string $type Milestone source: donation, newsletter, events,
	 *                     contacts or tenure.
	 */
	function npmp_mark_milestone( $type = '' ) {
		// The "Powered by" ask (npmp-credit-ask.php) waits for a donation
		// specifically, not just any milestone.
		if ( 'donation' === $type && ! (int) get_option( 'npmp_first_donation_at', 0 ) ) {
			update_option( 'npmp_first_donation_at', time(), false );
		}
		if ( get_option( 'npmp_first_milestone_at' ) ) {
			return;
		}
		update_option( 'npmp_first_milestone_at', time(), false );
		update_option( 'npmp_first_milestone_type', (string) $type, false );
	}
}

if ( ! function_exists( 'npmp_review_nudge_count_published' ) ) {
	/**
	 * Published posts of one of our types, or 0 when the type is not
	 * registered (its module is switched off).
	 *
	 * @param string $post_type Post type, e.g. npmp_event.
	 * @return int
	 */
	function npmp_review_nudge_count_published( $post_type ) {
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return 0;
		}
		$counts = wp_count_posts( $post_type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
}

if ( ! function_exists( 'npmp_review_nudge_active_since' ) ) {
	/**
	 * When the plugin was first activated on this site.
	 *
	 * Activation writes npmp_activated_at since 2026.10.5. A site that was
	 * already running before that has no such option, so the first call dates
	 * the install from its oldest contact, event or donation, once, and stores
	 * the answer. A site with no records yet counts from now.
	 *
	 * @return int Unix timestamp.
	 */
	function npmp_review_nudge_active_since() {
		$at = (int) get_option( 'npmp_activated_at', 0 );
		if ( $at > 0 ) {
			return $at;
		}

		$at     = time();
		$oldest = get_posts(
			array(
				'post_type'      => array( 'npmp_contact', 'npmp_event', 'npmp_donation' ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $oldest ) {
			$first = (int) get_post_time( 'U', true, $oldest[0] );
			if ( $first > 0 && $first < $at ) {
				$at = $first;
			}
		}
		update_option( 'npmp_activated_at', $at, false );

		return $at;
	}
}

if ( ! function_exists( 'npmp_review_nudge_check_milestones' ) ) {
	/**
	 * Count the site's events and contacts and record the first milestone
	 * they reach. No-op once any milestone is set.
	 *
	 * @return string The milestone recorded on this call, or '' for none.
	 */
	function npmp_review_nudge_check_milestones() {
		if ( get_option( 'npmp_first_milestone_at' ) ) {
			return '';
		}

		$events   = npmp_review_nudge_count_published( 'npmp_event' );
		$contacts = npmp_review_nudge_count_published( 'npmp_contact' );

		if ( $events >= NPMP_REVIEW_NUDGE_EVENTS ) {
			npmp_mark_milestone( 'events' );
			return 'events';
		}
		if ( $contacts >= NPMP_REVIEW_NUDGE_CONTACTS ) {
			npmp_mark_milestone( 'contacts' );
			return 'contacts';
		}
		if ( $events < 1 && $contacts < 1 ) {
			return '';
		}
		if ( ( time() - npmp_review_nudge_active_since() ) >= NPMP_REVIEW_NUDGE_TENURE_DAYS * DAY_IN_SECONDS ) {
			npmp_mark_milestone( 'tenure' );
			return 'tenure';
		}

		return '';
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
			'mailto:support@nonprofitmanager.app?subject=' . rawurlencode( 'Nonprofit Manager feedback' )
		);
	}
}

if ( ! function_exists( 'npmp_review_nudge_lead' ) ) {
	/**
	 * The notice's first sentence, naming what the site just did.
	 *
	 * @param string $type Milestone type, see npmp_mark_milestone().
	 * @return string Plain text, or '' for an unknown type.
	 */
	function npmp_review_nudge_lead( $type ) {
		switch ( (string) $type ) {
			case 'donation':
				return __( 'Your first donation came in through Nonprofit Manager.', 'nonprofit-manager' );
			case 'newsletter':
				return __( 'Your first newsletter went out through Nonprofit Manager.', 'nonprofit-manager' );
			case 'events':
				return sprintf(
					/* translators: %d: number of published events. */
					__( 'You\'ve published %d events with Nonprofit Manager.', 'nonprofit-manager' ),
					NPMP_REVIEW_NUDGE_EVENTS
				);
			case 'contacts':
				return sprintf(
					/* translators: %d: number of contacts. */
					__( 'You\'re keeping %d contacts in Nonprofit Manager.', 'nonprofit-manager' ),
					NPMP_REVIEW_NUDGE_CONTACTS
				);
			case 'tenure':
				return __( 'You\'ve been running Nonprofit Manager for a month.', 'nonprofit-manager' );
		}
		return '';
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
		if ( get_user_meta( get_current_user_id(), 'npmp_review_nudge_dismissed', true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'npmp' ) ) {
			return false;
		}
		// Donations and newsletters report themselves. Events, contacts and
		// tenure are counted here, only while no milestone is set yet.
		if ( ! get_option( 'npmp_first_milestone_at' ) ) {
			npmp_review_nudge_check_milestones();
			if ( ! get_option( 'npmp_first_milestone_at' ) ) {
				return false;
			}
		}
		// One ask at a time. The "Powered by" ask after the first donation
		// goes first, and this one waits a few days after it's answered.
		if ( function_exists( 'npmp_credit_ask_should_show' ) && npmp_credit_ask_should_show() ) {
			return false;
		}
		$credit_done = (int) get_option( 'npmp_credit_ask_done', 0 );
		if ( $credit_done && ( time() - $credit_done ) < 3 * DAY_IN_SECONDS ) {
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
		$lead         = npmp_review_nudge_lead( get_option( 'npmp_first_milestone_type', '' ) );

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
				<?php if ( '' !== $lead ) : ?>
					<strong><?php echo esc_html( $lead ); ?></strong>
				<?php endif; ?>
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
