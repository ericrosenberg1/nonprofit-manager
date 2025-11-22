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

		$email = sanitize_email( $_POST['npmp_member_email'] ?? '' );
		$name  = sanitize_text_field( $_POST['npmp_member_name'] ?? '' );
		$level = sanitize_text_field( $_POST['npmp_member_level'] ?? '' );

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
						'status'           => 'active',
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

		$title    = sanitize_text_field( $_POST['npmp_event_title'] ?? '' );
		$datetime = sanitize_text_field( $_POST['npmp_event_datetime'] ?? '' );
		$location = sanitize_text_field( $_POST['npmp_event_location'] ?? '' );

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
						update_post_meta( $post_id, '_npmp_event_start', $timestamp );
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
	$args = array(
		'post_type'      => 'npmp_contact',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dashboard widget query, acceptable performance trade-off.
		'meta_query'     => array(
			array(
				'key'   => 'npmp_membership_level',
				'value' => $tier,
			),
		),
	);

	$query = new WP_Query( $args );
	return count( $query->posts );
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

	$current_year = (int) gmdate( 'Y' );

	$args = array(
		'post_type'      => NPMP_Donation_Manager::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'date_query'     => array(
			array(
				'year' => $current_year,
			),
		),
	);

	$query = new WP_Query( $args );
	$total = 0.0;

	foreach ( $query->posts as $post_id ) {
		$amount = (float) get_post_meta( $post_id, NPMP_Donation_Manager::META_AMOUNT, true );
		$total += $amount;
	}

	return $total;
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

	$args = array(
		'post_type'      => NPMP_Donation_Manager::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Dashboard widget query, acceptable performance trade-off.
		'meta_query'     => array(
			array(
				'key'     => NPMP_Donation_Manager::META_FREQUENCY,
				'value'   => 'one_time',
				'compare' => '!=',
			),
		),
	);

	$query = new WP_Query( $args );
	$total = 0.0;

	foreach ( $query->posts as $post_id ) {
		$amount    = (float) get_post_meta( $post_id, NPMP_Donation_Manager::META_AMOUNT, true );
		$frequency = get_post_meta( $post_id, NPMP_Donation_Manager::META_FREQUENCY, true );

		// Convert to annual amount
		switch ( $frequency ) {
			case 'weekly':
				$annual = $amount * 52;
				break;
			case 'monthly':
				$annual = $amount * 12;
				break;
			case 'quarterly':
				$annual = $amount * 4;
				break;
			case 'yearly':
				$annual = $amount;
				break;
			default:
				$annual = 0;
		}

		$total += $annual;
	}

	return $total;
}
