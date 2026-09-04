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
	 * @param float $amount Amount to format.
	 * @return string
	 */
	function npmp_crm_format_currency( $amount ) {
		$symbol = apply_filters( 'npmp_crm_currency_symbol', '$' );
		return sprintf( '%s%s', $symbol, number_format_i18n( (float) $amount, 2 ) );
	}
}

/**
 * Register dashboard widgets.
 */
function npmp_register_dashboard_widgets() {
	if ( ! current_user_can( 'manage_options' ) ) {
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
			__( 'Nonprofit Manager Summary', 'nonprofit-manager' ),
			'npmp_render_summary_widget'
		);
	}

	// Quick Add Member widget
	if ( ! empty( $features['members'] ) ) {
		wp_add_dashboard_widget(
			'npmp_quick_add_member_widget',
			__( 'Quick Add Member', 'nonprofit-manager' ),
			'npmp_render_quick_add_member_widget'
		);
	}

	// Quick Add Event widget
	if ( ! empty( $features['calendar'] ) ) {
		wp_add_dashboard_widget(
			'npmp_quick_add_event_widget',
			__( 'Quick Add Event', 'nonprofit-manager' ),
			'npmp_render_quick_add_event_widget'
		);
	}
}
add_action( 'wp_dashboard_setup', 'npmp_register_dashboard_widgets' );

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
	<style>
		.npmp-summary-table {
			width: 100%;
			border-collapse: collapse;
		}
		.npmp-summary-table th,
		.npmp-summary-table td {
			padding: 8px;
			text-align: left;
			border-bottom: 1px solid #ddd;
		}
		.npmp-summary-table th {
			font-weight: 600;
			color: #1d2327;
		}
		.npmp-summary-table td {
			color: #50575e;
		}
		.npmp-summary-table tr:last-child td {
			border-bottom: none;
		}
		.npmp-summary-value {
			font-weight: 600;
			color: #2271b1;
		}
	</style>
	<table class="npmp-summary-table">
		<?php if ( ! empty( $features['members'] ) ) : ?>
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
					<th><?php esc_html_e( 'Total Members', 'nonprofit-manager' ); ?></th>
					<td class="npmp-summary-value"><?php echo esc_html( number_format_i18n( $total_members ) ); ?></td>
				</tr>
				<?php
			}
			?>
		<?php endif; ?>

		<?php if ( ! empty( $features['donations'] ) && class_exists( 'NPMP_Donation_Manager' ) ) : ?>
			<?php
			// Year-to-date donations
			$ytd_total = npmp_get_ytd_donation_total();
			?>
			<tr>
				<th><?php esc_html_e( 'Year-to-Date Donations', 'nonprofit-manager' ); ?></th>
				<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_currency( $ytd_total ) ); ?></td>
			</tr>

			<?php
			// Annual recurring donations
			$recurring_total = npmp_get_annual_recurring_total();
			?>
			<tr>
				<th><?php esc_html_e( 'Annual Recurring Donations', 'nonprofit-manager' ); ?></th>
				<td class="npmp-summary-value"><?php echo esc_html( npmp_crm_format_currency( $recurring_total ) ); ?></td>
			</tr>
		<?php endif; ?>
	</table>
	<?php
}

/**
 * Render Quick Add Member widget.
 */
function npmp_render_quick_add_member_widget() {
	// Check if form was submitted
	if ( isset( $_POST['npmp_quick_add_member_nonce'] ) &&
	     wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_quick_add_member_nonce'] ) ), 'npmp_quick_add_member' ) ) {

		$email = sanitize_email( wp_unslash( $_POST['npmp_member_email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['npmp_member_name'] ?? '' ) );
		$level = sanitize_text_field( wp_unslash( $_POST['npmp_member_level'] ?? '' ) );

		if ( ! $email ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Email is required.', 'nonprofit-manager' ) . '</p></div>';
		} elseif ( ! class_exists( 'NPMP_Member_Manager' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member Manager class not found.', 'nonprofit-manager' ) . '</p></div>';
		} else {
			$manager = NPMP_Member_Manager::get_instance();
			$existing = $manager->get_member_by_email( $email );

			if ( $existing ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'A member with this email already exists.', 'nonprofit-manager' ) . '</p></div>';
			} else {
				$result = $manager->add_member(
					array(
						'email'            => $email,
						'name'             => $name,
						'membership_level' => $level,
						'status'           => 'subscribed',
					)
				);

				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p>' . esc_html__( 'Member added successfully!', 'nonprofit-manager' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=npmp_members' ) ) . '">' . esc_html__( 'View all members', 'nonprofit-manager' ) . '</a></p></div>';
				}
			}
		}
	}

	$tiers = npmp_get_membership_tiers();
	?>
	<style>
		.npmp-quick-form {
			margin: 10px 0;
		}
		.npmp-quick-form label {
			display: block;
			margin-bottom: 5px;
			font-weight: 600;
		}
		.npmp-quick-form input[type="text"],
		.npmp-quick-form input[type="email"],
		.npmp-quick-form select {
			width: 100%;
			padding: 6px 8px;
			margin-bottom: 12px;
		}
		.npmp-quick-form .button {
			margin-top: 5px;
		}
	</style>
	<form method="post" class="npmp-quick-form">
		<?php wp_nonce_field( 'npmp_quick_add_member', 'npmp_quick_add_member_nonce' ); ?>

		<label for="npmp_member_email"><?php esc_html_e( 'Email', 'nonprofit-manager' ); ?> <span style="color: #d63638;">*</span></label>
		<input type="email" id="npmp_member_email" name="npmp_member_email" required>

		<label for="npmp_member_name"><?php esc_html_e( 'Name', 'nonprofit-manager' ); ?></label>
		<input type="text" id="npmp_member_name" name="npmp_member_name">

		<label for="npmp_member_level"><?php esc_html_e( 'Membership Level', 'nonprofit-manager' ); ?></label>
		<select id="npmp_member_level" name="npmp_member_level">
			<option value=""><?php esc_html_e( '— Select —', 'nonprofit-manager' ); ?></option>
			<?php
			if ( ! empty( $tiers ) ) {
				foreach ( $tiers as $tier ) {
					echo '<option value="' . esc_attr( $tier ) . '">' . esc_html( $tier ) . '</option>';
				}
			}
			?>
		</select>

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Member', 'nonprofit-manager' ); ?></button>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=npmp_members' ) ); ?>" class="button"><?php esc_html_e( 'View All', 'nonprofit-manager' ); ?></a>
	</form>
	<?php
}

/**
 * Render Quick Add Event widget.
 */
function npmp_render_quick_add_event_widget() {
	// Check if form was submitted
	if ( isset( $_POST['npmp_quick_add_event_nonce'] ) &&
	     wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_quick_add_event_nonce'] ) ), 'npmp_quick_add_event' ) ) {

		$title    = sanitize_text_field( wp_unslash( $_POST['npmp_event_title'] ?? '' ) );
		$datetime = sanitize_text_field( wp_unslash( $_POST['npmp_event_datetime'] ?? '' ) );
		$location = sanitize_text_field( wp_unslash( $_POST['npmp_event_location'] ?? '' ) );

		if ( ! $title ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Event title is required.', 'nonprofit-manager' ) . '</p></div>';
		} else {
			// Create the event post
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'npmp_event',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $post_id->get_error_message() ) . '</p></div>';
			} else {
				// Save event meta
				if ( $datetime ) {
					$timestamp = strtotime( $datetime );
					if ( $timestamp ) {
						update_post_meta( $post_id, '_npmp_event_start', gmdate( 'Y-m-d H:i:s', $timestamp ) );
					}
				}

				if ( $location ) {
					update_post_meta( $post_id, '_npmp_event_location', $location );
				}

				echo '<div class="notice notice-success"><p>' . esc_html__( 'Event created successfully!', 'nonprofit-manager' ) . ' <a href="' . esc_url( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">' . esc_html__( 'Edit event', 'nonprofit-manager' ) . '</a></p></div>';
			}
		}
	}

	?>
	<style>
		.npmp-quick-form {
			margin: 10px 0;
		}
		.npmp-quick-form label {
			display: block;
			margin-bottom: 5px;
			font-weight: 600;
		}
		.npmp-quick-form input[type="text"],
		.npmp-quick-form input[type="datetime-local"] {
			width: 100%;
			padding: 6px 8px;
			margin-bottom: 12px;
		}
		.npmp-quick-form .button {
			margin-top: 5px;
		}
	</style>
	<form method="post" class="npmp-quick-form">
		<?php wp_nonce_field( 'npmp_quick_add_event', 'npmp_quick_add_event_nonce' ); ?>

		<label for="npmp_event_title"><?php esc_html_e( 'Event Title', 'nonprofit-manager' ); ?> <span style="color: #d63638;">*</span></label>
		<input type="text" id="npmp_event_title" name="npmp_event_title" required>

		<label for="npmp_event_datetime"><?php esc_html_e( 'Date & Time', 'nonprofit-manager' ); ?></label>
		<input type="datetime-local" id="npmp_event_datetime" name="npmp_event_datetime">

		<label for="npmp_event_location"><?php esc_html_e( 'Location', 'nonprofit-manager' ); ?></label>
		<input type="text" id="npmp_event_location" name="npmp_event_location">

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Event', 'nonprofit-manager' ); ?></button>
		<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ); ?>" class="button"><?php esc_html_e( 'View All', 'nonprofit-manager' ); ?></a>
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over a joined meta table; cached per request in the static above.
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
 * Helper: Get year-to-date donation total.
 *
 * @return float
 */
function npmp_get_ytd_donation_total() {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return 0.0;
	}

	global $wpdb;

	$current_year = (int) gmdate( 'Y' );

	// Sum in SQL rather than loading every donation ID and its meta into PHP.
	// This runs on every wp-admin Dashboard load, and the old version returned
	// the whole year's donations to add them up one at a time. The result is
	// identical: a donation with no stored amount contributed 0 before and is
	// excluded by the join now.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single aggregate, no row set to cache.
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM(m.meta_value + 0)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND YEAR(p.post_date) = %d",
			NPMP_Donation_Manager::META_AMOUNT,
			NPMP_Donation_Manager::POST_TYPE,
			$current_year
		)
	);

	return (float) $total;
}

/**
 * Helper: Get annual recurring donation total.
 *
 * @return float
 */
function npmp_get_annual_recurring_total() {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return 0.0;
	}

	global $wpdb;

	// Group the sum by frequency in SQL and convert the handful of resulting
	// rows in PHP, instead of loading every recurring donation ever recorded
	// and its meta to add them one at a time on each Dashboard load.
	//
	// The INNER JOIN on the frequency meta reproduces what the meta_query did:
	// WordPress's "!=" compare only matches posts that actually have the key,
	// so a donation with no frequency stored was excluded before and still is.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Small grouped aggregate, nothing worth caching.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT f.meta_value AS frequency, SUM(a.meta_value + 0) AS total
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = %s
			 INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
			 WHERE p.post_type = %s AND p.post_status = 'publish' AND f.meta_value != %s
			 GROUP BY f.meta_value",
			NPMP_Donation_Manager::META_FREQUENCY,
			NPMP_Donation_Manager::META_AMOUNT,
			NPMP_Donation_Manager::POST_TYPE,
			'one_time'
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return 0.0;
	}

	$total = 0.0;
	foreach ( $rows as $row ) {
		$total += npmp_annualize_donation_amount( (float) $row['total'], (string) $row['frequency'] );
	}

	return $total;
}

/**
 * Convert an amount at a given billing frequency to its yearly equivalent.
 *
 * Split out of npmp_get_annual_recurring_total() so the frequency vocabulary
 * lives in one place and can be tested on its own.
 *
 * Two vocabularies reach this meta field and both mean once a year. The free
 * donation form writes 'annual'; Pro's Stripe subscription sync maps Stripe's
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
