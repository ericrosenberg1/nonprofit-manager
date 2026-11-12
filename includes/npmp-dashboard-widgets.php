<?php
/**
 * WordPress Dashboard Widgets for Nonprofit Manager
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currency formatting helper (fallback if not already defined).
 */
if ( ! function_exists( 'npmp_crm_format_currency' ) ) {
	/**
	 * Format amount as currency.
	 *
	 * @param float       $amount   Amount to format.
	 * @param string|null $currency Currency the amount is in, or null for the site currency.
	 * @return string
	 */
	function npmp_crm_format_currency( $amount, $currency = null ) {
		$currency = npmp_resolve_currency( $currency );

		// A site that customised the dollar sign through this filter keeps it.
		if ( 'USD' === $currency && has_filter( 'npmp_crm_currency_symbol' ) ) {
			$symbol = apply_filters( 'npmp_crm_currency_symbol', '$' );
			return sprintf( '%s%s', $symbol, number_format_i18n( (float) $amount, 2 ) );
		}

		return npmp_format_amount( $amount, $currency );
	}
}

/**
 * A member's lifetime donation value, in the currency (or currencies) given.
 *
 * @param object $member Member object from NPMP_Member_Manager.
 * @return string
 */
function npmp_member_lifetime_value( $member ) {
	$totals = isset( $member->donation_totals ) && is_array( $member->donation_totals ) ? $member->donation_totals : array();
	if ( count( $totals ) > 1 ) {
		return npmp_format_amounts_by_currency( $totals );
	}
	return npmp_crm_format_currency( (float) ( $member->donation_total ?? 0 ), npmp_record_currency( $member->donation_currency ?? '' ) );
}

/**
 * Format per-currency totals for a dashboard cell, honouring the legacy
 * npmp_crm_currency_symbol filter when everything is in US dollars.
 *
 * @param array<string,float> $totals Totals keyed by currency.
 * @return string
 */
function npmp_crm_format_totals( $totals ) {
	$totals = is_array( $totals ) ? $totals : array();
	if ( count( $totals ) <= 1 ) {
		$currency = $totals ? (string) array_key_first( $totals ) : npmp_currency();
		return npmp_crm_format_currency( $totals ? (float) reset( $totals ) : 0.0, $currency );
	}
	return npmp_format_amounts_by_currency( $totals );
}

/**
 * Register dashboard widgets.
 */
function npmp_register_dashboard_widgets() {
	if ( ! current_user_can( npmp_staff_cap() ) ) {
		return;
	}

	$features = get_option(
		'npmp_enabled_features',
		array(
			'members'   => true,
			'donations' => true,
			'calendar'  => false,
		)
	);

	// Nonprofit Manager Summary widget
	if ( ! empty( $features['members'] ) || ! empty( $features['donations'] ) ) {
		wp_add_dashboard_widget(
			'npmp_summary_widget',
			sprintf( /* translators: %s: admin menu name, such as Nonprofit Manager or Club Manager. */ __( '%s Summary', 'nonprofit-manager' ), npmp_label( 'app_name' ) ),
			'npmp_render_summary_widget'
		);
	}

	// Quick Add Member widget
	if ( ! empty( $features['members'] ) ) {
		wp_add_dashboard_widget(
			'npmp_quick_add_member_widget',
			sprintf( /* translators: %s: singular word for member, such as Member. */ __( 'Quick Add %s', 'nonprofit-manager' ), npmp_label( 'member' ) ),
			'npmp_render_quick_add_member_widget'
		);
	}

	// Quick Add Event widget. Events are calendar content, not member work,
	// so this one stays with administrators.
	if ( ! empty( $features['calendar'] ) && current_user_can( 'manage_options' ) ) {
		wp_add_dashboard_widget(
			'npmp_quick_add_event_widget',
			__( 'Quick Add Event', 'nonprofit-manager' ),
			'npmp_render_quick_add_event_widget'
		);
	}
}
add_action( 'wp_dashboard_setup', 'npmp_register_dashboard_widgets' );

/**
 * One notice inside a dashboard widget.
 *
 * Without "inline", core's common.js lifts every notice to the top of the
 * Dashboard, far from the widget that printed it.
 *
 * @param string $type success, warning, or error.
 * @param string $html Message, already escaped.
 * @return string
 */
function npmp_dashboard_notice_html( $type, $html ) {
	return '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . $html . '</p></div>';
}

/**
 * The post Quick Add Event creates.
 *
 * It's a draft, so nothing reaches the public calendar, the event feeds, or
 * the scheduled emails until someone opens it and publishes. It used to go
 * live straight from the Dashboard with no description.
 *
 * @param string $title    Event title.
 * @param string $datetime Value from the datetime-local field, site time.
 * @param string $location Location.
 * @return array wp_insert_post() arguments.
 */
function npmp_quick_add_event_args( $title, $datetime, $location ) {
	// The start and location go in through meta_input so they exist before
	// save_post fires, where the scheduled-email sync reads them.
	$meta = array();
	if ( $datetime ) {
		// datetime-local sends the site's wall-clock time, which is how the
		// Event Details box stores it too.
		$start = date_create_immutable_from_format( 'Y-m-d\TH:i', $datetime, wp_timezone() );
		if ( $start ) {
			$meta['_npmp_event_start'] = $start->format( 'Y-m-d H:i:00' );
		}
	}
	if ( $location ) {
		$meta['_npmp_event_location'] = $location;
	}

	return array(
		'post_type'   => 'npmp_event',
		'post_title'  => $title,
		'post_status' => 'draft',
		'meta_input'  => $meta,
	);
}

/**
 * Save the Quick Add forms before the Dashboard prints, then redirect.
 *
 * The forms used to save while the widget rendered, so reloading the
 * Dashboard posted them again and made a second event.
 */
function npmp_quick_add_handle_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}

	$result = '';
	$args   = array();

	if ( isset( $_POST['npmp_quick_add_member_nonce'] ) ) {
		if ( ! current_user_can( npmp_staff_cap() ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_quick_add_member_nonce'] ) ), 'npmp_quick_add_member' ) ) {
			return;
		}
		$email = sanitize_email( wp_unslash( $_POST['npmp_member_email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['npmp_member_name'] ?? '' ) );
		$level = sanitize_text_field( wp_unslash( $_POST['npmp_member_level'] ?? '' ) );

		if ( ! $email ) {
			$result = 'member_no_email';
		} elseif ( ! class_exists( 'NPMP_Member_Manager' ) ) {
			error_log( 'Nonprofit Manager: Quick Add Member could not load NPMP_Member_Manager.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = 'member_error';
		} else {
			$manager = NPMP_Member_Manager::get_instance();
			if ( $manager->get_member_by_email( $email ) ) {
				$result = 'member_exists';
			} else {
				$added = $manager->add_member(
					array(
						'email'            => $email,
						'name'             => $name,
						'membership_level' => $level,
						'status'           => 'subscribed',
					)
				);
				if ( is_wp_error( $added ) ) {
					error_log( 'Nonprofit Manager: Quick Add Member failed: ' . $added->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$result = 'member_error';
				} else {
					$result = 'member_added';
				}
			}
		}
	} elseif ( isset( $_POST['npmp_quick_add_event_nonce'] ) ) {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_quick_add_event_nonce'] ) ), 'npmp_quick_add_event' ) ) {
			return;
		}
		$title    = sanitize_text_field( wp_unslash( $_POST['npmp_event_title'] ?? '' ) );
		$datetime = sanitize_text_field( wp_unslash( $_POST['npmp_event_datetime'] ?? '' ) );
		$location = sanitize_text_field( wp_unslash( $_POST['npmp_event_location'] ?? '' ) );

		if ( ! $title ) {
			$result = 'event_no_title';
		} else {
			$post_id = wp_insert_post( npmp_quick_add_event_args( $title, $datetime, $location ), true );
			if ( is_wp_error( $post_id ) ) {
				error_log( 'Nonprofit Manager: Quick Add Event failed: ' . $post_id->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$result = 'event_error';
			} else {
				$result             = 'event_added';
				$args['npmp_event'] = (int) $post_id;
			}
		}
	} else {
		return;
	}

	$args['npmp_quick'] = $result;
	wp_safe_redirect( add_query_arg( $args, admin_url( 'index.php' ) ) );
	exit;
}
add_action( 'load-index.php', 'npmp_quick_add_handle_post' );

/**
 * The notice for one Quick Add widget after its redirect, or ''.
 *
 * @param string $form member or event.
 * @return string
 */
function npmp_quick_add_notice( $form ) {
	$result = isset( $_GET['npmp_quick'] ) ? sanitize_key( wp_unslash( $_GET['npmp_quick'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 0 !== strpos( $result, $form . '_' ) ) {
		return '';
	}

	switch ( $result ) {
		case 'member_added':
			return npmp_dashboard_notice_html(
				'success',
				esc_html( sprintf( /* translators: %s: singular word for member, such as Member. */ __( '%s added.', 'nonprofit-manager' ), npmp_label( 'member' ) ) )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=npmp_members' ) ) . '">' . esc_html__( 'View contacts', 'nonprofit-manager' ) . '</a>'
			);
		case 'member_exists':
			return npmp_dashboard_notice_html( 'warning', esc_html__( 'A contact with this email already exists.', 'nonprofit-manager' ) );
		case 'member_no_email':
			return npmp_dashboard_notice_html( 'error', esc_html__( 'Enter an email address.', 'nonprofit-manager' ) );
		case 'member_error':
			return npmp_dashboard_notice_html( 'error', esc_html__( "Couldn't save this contact. Please try again.", 'nonprofit-manager' ) );
		case 'event_added':
			$post_id = isset( $_GET['npmp_event'] ) ? absint( $_GET['npmp_event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$link    = $post_id ? ' <a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">' . esc_html__( 'Finish the draft', 'nonprofit-manager' ) . '</a>' : '';
			return npmp_dashboard_notice_html( 'success', esc_html__( 'Event saved as a draft. Add the details and publish it when it\'s ready.', 'nonprofit-manager' ) . $link );
		case 'event_no_title':
			return npmp_dashboard_notice_html( 'error', esc_html__( 'Give the event a title.', 'nonprofit-manager' ) );
		case 'event_error':
			return npmp_dashboard_notice_html( 'error', esc_html__( "Couldn't save this event. Please try again.", 'nonprofit-manager' ) );
	}
	return '';
}

/**
 * The "required" mark after a field label, with words for screen readers.
 *
 * @return string
 */
function npmp_required_mark_html() {
	return ' <span class="npmp-required" aria-hidden="true">*</span><span class="screen-reader-text">' . esc_html__( '(required)', 'nonprofit-manager' ) . '</span>';
}

/**
 * Render Nonprofit Manager Summary widget.
 */
function npmp_render_summary_widget() {
	$features = get_option(
		'npmp_enabled_features',
		array(
			'members'   => true,
			'donations' => true,
		)
	);

	?>
	<table class="npmp-summary-table">
		<?php if ( ! empty( $features['members'] ) ) : ?>
			<?php
			$tiers = npmp_get_membership_tiers();
			if ( ! empty( $tiers ) ) {
				foreach ( $tiers as $tier ) {
					$count = npmp_count_members_by_tier( $tier );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $tier ); ?></th>
						<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
					</tr>
					<?php
				}
			} else {
				$total_members = npmp_count_total_members();
				?>
				<tr>
					<th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plural word for members, such as Members. */ __( 'Total %s', 'nonprofit-manager' ), npmp_label( 'members' ) ) ); ?></th>
					<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></td>
				</tr>
				<?php
			}
			?>
		<?php endif; ?>

		<?php if ( ! empty( $features['donations'] ) && class_exists( 'NPMP_Donation_Manager' ) ) : ?>
			<?php $ytd_totals = npmp_get_ytd_donation_totals_by_currency(); ?>
			<tr>
				<th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'Year-to-Date %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></th>
				<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_totals( $ytd_totals ) ); ?></td>
			</tr>

			<?php $recurring_totals = npmp_get_annual_recurring_totals_by_currency(); ?>
			<tr>
				<th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'Annual Recurring %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ); ?></th>
				<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_totals( $recurring_totals ) ); ?></td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
}

/**
 * Render Quick Add Member widget.
 */
function npmp_render_quick_add_member_widget() {
	echo npmp_quick_add_notice( 'member' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in npmp_quick_add_notice().

	$tiers = npmp_get_membership_tiers();
	?>
	<form method="post" class="npmp-quick-form">
		<?php wp_nonce_field( 'npmp_quick_add_member', 'npmp_quick_add_member_nonce' ); ?>

		<label for="npmp_member_email"><?php esc_html_e( 'Email', 'nonprofit-manager' ); ?><?php echo npmp_required_mark_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<input type="email" id="npmp_member_email" name="npmp_member_email" required>

		<label for="npmp_member_name"><?php esc_html_e( 'Name', 'nonprofit-manager' ); ?></label>
		<input type="text" id="npmp_member_name" name="npmp_member_name">

		<label for="npmp_member_level"><?php echo esc_html( npmp_label( 'level' ) ); ?></label>
		<select id="npmp_member_level" name="npmp_member_level">
			<option value=""><?php esc_html_e( 'None', 'nonprofit-manager' ); ?></option>
			<?php
			foreach ( $tiers as $tier ) {
				echo '<option value="' . esc_attr( $tier ) . '">' . esc_html( $tier ) . '</option>';
			}
			?>
		</select>

		<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: singular word for member, such as Member. */ __( 'Add %s', 'nonprofit-manager' ), npmp_label( 'member' ) ) ); ?></button>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>" class="button"><?php esc_html_e( 'View contacts', 'nonprofit-manager' ); ?></a>
	</form>
	<?php
}

/**
 * Render Quick Add Event widget.
 */
function npmp_render_quick_add_event_widget() {
	echo npmp_quick_add_notice( 'event' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in npmp_quick_add_notice().
	?>
	<form method="post" class="npmp-quick-form">
		<?php wp_nonce_field( 'npmp_quick_add_event', 'npmp_quick_add_event_nonce' ); ?>

		<label for="npmp_event_title"><?php esc_html_e( 'Event title', 'nonprofit-manager' ); ?><?php echo npmp_required_mark_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
		<input type="text" id="npmp_event_title" name="npmp_event_title" required>

		<label for="npmp_event_datetime"><?php esc_html_e( 'Date and time', 'nonprofit-manager' ); ?></label>
		<input type="datetime-local" id="npmp_event_datetime" name="npmp_event_datetime">

		<label for="npmp_event_location"><?php esc_html_e( 'Location', 'nonprofit-manager' ); ?></label>
		<input type="text" id="npmp_event_location" name="npmp_event_location">

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save draft', 'nonprofit-manager' ); ?></button>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ); ?>" class="button"><?php esc_html_e( 'All events', 'nonprofit-manager' ); ?></a>
	</form>
	<?php
}

/**
 * Helper: Get membership tiers.
 *
 * @return array
 */
function npmp_get_membership_tiers() {
	$levels = get_option( 'npmp_membership_levels', '' );
	if ( ! $levels ) {
		return array();
	}

	// Handle both array and string formats
	if ( is_array( $levels ) ) {
		return array_filter( array_map( 'trim', $levels ) );
	}

	$tiers = array_filter( array_map( 'trim', explode( "\n", $levels ) ) );
	return $tiers;
}

/**
 * Helper: Count members by tier.
 *
 * @param string $tier Tier name.
 * @return int
 */
function npmp_count_members_by_tier( $tier ) {
	$counts = npmp_count_members_by_tier_map();
	$key    = strtolower( (string) $tier );

	return isset( $counts[ $key ] ) ? $counts[ $key ] : 0;
}

/**
 * Count contacts per membership tier in one grouped query.
 *
 * Both callers of npmp_count_members_by_tier() loop over every tier, and the
 * old implementation ran a separate unbounded query per tier that pulled every
 * matching post ID into PHP just to count() the array. On a site with real
 * membership numbers that is one full table scan per tier on each dashboard
 * load, returning tens of thousands of rows to discard them.
 *
 * One GROUP BY returns every tier's count in a single row set, cached for the
 * request since the dashboard and the Overview screen both ask for it.
 *
 * Keys are lower-cased because the tier comparison this replaces ran through
 * MySQL's case-insensitive collation, and callers pass the tier name as the
 * admin typed it.
 *
 * @return array<string,int> Lower-cased tier name => member count.
 */
function npmp_count_members_by_tier_map() {
	static $counts = null;

	if ( null !== $counts ) {
		return $counts;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over a joined meta table. Cached per request in the static above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.meta_value AS tier, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			 GROUP BY m.meta_value",
			'npmp_membership_level',
			'npmp_contact'
		),
		ARRAY_A
	);

	$counts = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$key = strtolower( (string) $row['tier'] );
			// Collation folds case in the GROUP BY on some setups and not
			// others, so add rather than assign.
			$counts[ $key ] = ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) + (int) $row['total'];
		}
	}

	return $counts;
}

/**
 * Helper: Count total members.
 *
 * @return int
 */
function npmp_count_total_members() {
	$counts = wp_count_posts( 'npmp_contact' );
	return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

/**
 * Helper: Get year-to-date donation total in the site currency.
 *
 * Kept for callers outside this file. Amounts in other currencies are left
 * out rather than added in. npmp_get_ytd_donation_totals_by_currency() has
 * every currency.
 *
 * @return float
 */
function npmp_get_ytd_donation_total() {
	$totals = npmp_get_ytd_donation_totals_by_currency();
	return (float) ( $totals[ npmp_currency() ] ?? ( 1 === count( $totals ) ? reset( $totals ) : 0.0 ) );
}

/**
 * Helper: Year-to-date donation totals, one per currency.
 *
 * @return array<string,float> Currency => total. Empty when nothing was given this year.
 */
function npmp_get_ytd_donation_totals_by_currency() {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return array();
	}

	global $wpdb;

	// post_date is the site's local time, so the year has to be too. On
	// New Year's Eve in Los Angeles, gmdate() had already moved on.
	$current_year = (int) wp_date( 'Y' );

	// Sum in SQL rather than loading every donation ID and its meta into PHP.
	// This runs on every wp-admin Dashboard load, and the old version returned
	// the whole year's donations to add them up one at a time. A donation with
	// no stored amount contributed 0 before and is excluded by the join now.
	// Grouped by currency so a site that changed currency never adds pounds
	// to dollars. A donation with no currency stored is USD.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small grouped aggregate, no row set to cache.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COALESCE( c.meta_value, '' ) AS currency, SUM(m.meta_value + 0) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND YEAR(p.post_date) = %d
			 GROUP BY COALESCE( c.meta_value, '' )",
			NPMP_Donation_Manager::META_AMOUNT,
			NPMP_Donation_Manager::META_CURRENCY,
			NPMP_Donation_Manager::POST_TYPE,
			$current_year
		),
		ARRAY_A
	);

	return npmp_group_totals_by_currency( is_array( $rows ) ? $rows : array() );
}

/**
 * Helper: Get annual recurring donation total in the site currency.
 *
 * Kept for callers outside this file. See
 * npmp_get_annual_recurring_totals_by_currency() for every currency.
 *
 * @return float
 */
function npmp_get_annual_recurring_total() {
	$totals = npmp_get_annual_recurring_totals_by_currency();
	return (float) ( $totals[ npmp_currency() ] ?? ( 1 === count( $totals ) ? reset( $totals ) : 0.0 ) );
}

/**
 * Helper: Annualised recurring donation totals, one per currency.
 *
 * @return array<string,float> Currency => yearly total.
 */
function npmp_get_annual_recurring_totals_by_currency() {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return array();
	}

	global $wpdb;

	// Pro records every renewal as its own donation, so summing every
	// recurring gift ever made counted a monthly donor of two years 24
	// times over. The figure is a run rate instead: each donor's latest
	// gift at each frequency and currency, kept only while it's still due
	// to renew (npmp_recurring_gift_is_current()), annualised once.
	//
	// One row per donor, frequency, and currency, with the amount of that
	// donor's most recent gift by date (an import can add old gifts with
	// new IDs, so the highest ID isn't always the latest). A gift with no
	// email counts on its own.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small grouped aggregate, nothing worth caching.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.meta_value AS frequency, COALESCE( c.meta_value, '' ) AS currency,
				SUBSTRING_INDEX( GROUP_CONCAT( a.meta_value ORDER BY p.post_date_gmt DESC, p.ID DESC SEPARATOR '|' ), '|', 1 ) AS amount,
				MAX( p.post_date_gmt ) AS gift_date
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
			 INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND f.meta_value != %s
			 GROUP BY COALESCE( NULLIF( LOWER( e.meta_value ), '' ), CONCAT( '#', p.ID ) ), f.meta_value, COALESCE( c.meta_value, '' )",
			NPMP_Donation_Manager::META_FREQUENCY,
			NPMP_Donation_Manager::META_AMOUNT,
			NPMP_Donation_Manager::META_EMAIL,
			NPMP_Donation_Manager::META_CURRENCY,
			NPMP_Donation_Manager::POST_TYPE,
			'one_time'
		),
		ARRAY_A
	);

	$totals = npmp_recurring_run_rate_totals( is_array( $rows ) ? $rows : array(), time() );

	/**
	 * Filters the Dashboard's annual recurring totals.
	 *
	 * @param array<string,float> $totals Currency => yearly run rate.
	 * @param array               $rows   Each donor's latest recurring gift:
	 *                                    frequency, currency, amount, gift_date (UTC).
	 */
	return (array) apply_filters( 'npmp_annual_recurring_totals', $totals, $rows );
}

/**
 * Whether a recurring gift is recent enough that its donor is still giving.
 *
 * A gift counts until one billing period plus some grace has passed, so a
 * monthly donor whose card failed last week still counts and one who
 * cancelled in the spring doesn't.
 *
 * @param string $frequency Stored frequency value.
 * @param int    $gift_ts   When the gift was recorded, Unix time.
 * @param int    $now       Current Unix time.
 * @return bool
 */
function npmp_recurring_gift_is_current( $frequency, $gift_ts, $now ) {
	$days = array(
		'weekly'    => 14,
		'monthly'   => 45,
		'quarterly' => 100,
		'annual'    => 380,
		'yearly'    => 380,
	);
	$key  = strtolower( trim( (string) $frequency ) );
	if ( ! isset( $days[ $key ] ) || $gift_ts <= 0 ) {
		return false;
	}
	return ( $now - $gift_ts ) <= $days[ $key ] * 86400;
}

/**
 * Turn each donor's latest recurring gift into yearly totals per currency.
 *
 * @param array $rows Rows with frequency, currency, amount, and gift_date
 *                    (UTC, Y-m-d H:i:s).
 * @param int   $now  Current Unix time.
 * @return array<string,float> Currency => yearly total.
 */
function npmp_recurring_run_rate_totals( array $rows, $now ) {
	$annualised = array();
	foreach ( $rows as $row ) {
		$row     = (array) $row;
		$gift_ts = isset( $row['gift_date'] ) ? strtotime( $row['gift_date'] . ' UTC' ) : false;
		if ( ! $gift_ts || ! npmp_recurring_gift_is_current( (string) ( $row['frequency'] ?? '' ), (int) $gift_ts, (int) $now ) ) {
			continue;
		}
		$annualised[] = array(
			'currency' => (string) ( $row['currency'] ?? '' ),
			'total'    => npmp_annualize_donation_amount( (float) ( $row['amount'] ?? 0 ), (string) $row['frequency'] ),
		);
	}

	return npmp_group_totals_by_currency( $annualised );
}

/**
 * Convert an amount at a given billing frequency to its yearly equivalent.
 *
 * Split out of npmp_get_annual_recurring_total() so the frequency vocabulary
 * lives in one place and can be tested on its own.
 *
 * Two vocabularies reach this meta field and both mean once a year. The free
 * donation form writes 'annual'. Pro's Stripe subscription sync maps Stripe's
 * year interval to 'yearly' and passes that straight through to log_donation()
 * on every renewal. Matching only one of them silently totals the other as $0,
 * which is how this figure was wrong before.
 *
 * An unrecognised frequency returns 0 rather than guessing, so a new value
 * added elsewhere shows up as a missing total rather than a wrong one.
 *
 * @param float  $amount    Amount charged each period.
 * @param string $frequency Stored frequency value.
 * @return float Yearly equivalent.
 */
function npmp_annualize_donation_amount( $amount, $frequency ) {
	switch ( strtolower( trim( (string) $frequency ) ) ) {
		case 'weekly':
			return $amount * 52;
		case 'monthly':
			return $amount * 12;
		case 'quarterly':
			return $amount * 4;
		case 'annual':
		case 'yearly':
			return $amount;
		default:
			return 0.0;
	}
}
