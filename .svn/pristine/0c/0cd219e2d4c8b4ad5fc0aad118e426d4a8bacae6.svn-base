<?php
/**
 * Donation and Payment Settings
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'payments/class-donation-manager.php';
require_once plugin_dir_path( __FILE__ ) . 'payments/npmp-payment-gateways.php';

/* ==============================================================
 * Donations Summary Dashboard
 * ============================================================= */
function npmp_render_donations_dashboard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nonprofit-manager' ) );
	}

	$dm = NPMP_Donation_Manager::get_instance();

	/* Read & validate filter params */
	$current_year  = (int) gmdate( 'Y' );
	$current_month = 0;

	if (
		isset( $_GET['_wpnonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'npmp_donations_filter' )
	) {
		if ( isset( $_GET['year'] ) ) {
			$current_year = absint( wp_unslash( $_GET['year'] ) );
		}
		if ( isset( $_GET['month'] ) ) {
			$current_month = absint( wp_unslash( $_GET['month'] ) );
		}
	}

	$years   = $dm->years_with_donations();
	$summary = $dm->summary( $current_year, $current_month ?: null );

	echo '<div class="wrap"><h1>' . esc_html__( 'Donations Summary', 'nonprofit-manager' ) . '</h1>';

	/* Filter form */
	echo '<form method="get" style="margin-bottom:1.5em;">';
	echo '<input type="hidden" name="page" value="npmp_donations_group">';
	wp_nonce_field( 'npmp_donations_filter' );
	echo '<label>' . esc_html__( 'Year', 'nonprofit-manager' ) . ' ';
	echo '<select name="year">';
	foreach ( $years as $y ) {
		echo '<option value="' . esc_attr( $y ) . '"' . selected( $y, $current_year, false ) . '>' . esc_html( $y ) . '</option>';
	}
	echo '</select></label> ';

	echo '<label>' . esc_html__( 'Month', 'nonprofit-manager' ) . ' ';
	echo '<select name="month">';
	echo '<option value="0">' . esc_html__( 'All', 'nonprofit-manager' ) . '</option>';
	for ( $m = 1; $m <= 12; $m ++ ) {
		echo '<option value="' . esc_attr( $m ) . '"' . selected( $m, $current_month, false ) . '>' . esc_html( gmdate( 'F', gmmktime( 0, 0, 0, $m, 1 ) ) ) . '</option>';
	}
	echo '</select></label> ';
	submit_button( esc_html__( 'Filter', 'nonprofit-manager' ), 'secondary', '', false );
	echo '</form>';

	/* Get detailed donations */
	$args = array(
		'post_type'      => NPMP_Donation_Manager::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'date_query'     => array(
			array(
				'year'     => absint( $current_year ),
			),
		),
	);

	if ( $current_month ) {
		$args['date_query'][0]['monthnum'] = absint( $current_month );
	}

	$donations = get_posts( $args );

	/* Separate one-time and recurring donations */
	$one_time_donations = array();
	$recurring_donations = array();

	foreach ( $donations as $donation ) {
		$frequency = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_FREQUENCY, true );
		if ( 'one_time' === $frequency || empty( $frequency ) ) {
			$one_time_donations[] = $donation;
		} else {
			$recurring_donations[] = $donation;
		}
	}

	/* One-Time Donations Table */
	echo '<h2>' . esc_html__( 'One-Time Donations', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Donor Name', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th>';
	echo '<th style="text-align:right">' . esc_html__( 'Amount (USD)', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Gateway', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( $one_time_donations ) {
		foreach ( $one_time_donations as $donation ) {
			$amount   = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_AMOUNT, true );
			$email    = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_EMAIL, true );
			$gateway  = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_GATEWAY, true );
			$donor_name = $donation->post_title;

			// Extract name from title if it contains email in parentheses
			if ( strpos( $donor_name, '(' ) !== false ) {
				$donor_name = trim( substr( $donor_name, 0, strpos( $donor_name, '(' ) ) );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $donor_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td style="text-align:right">$' . esc_html( number_format_i18n( $amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( 'M j, Y g:i A', $donation ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', $gateway ) ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5">' . esc_html__( 'No one-time donations found for this period.', 'nonprofit-manager' ) . '</td></tr>';
	}
	echo '</tbody></table>';

	/* Recurring Donations Table */
	echo '<h2 style="margin-top:2em;">' . esc_html__( 'Recurring Donations', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Donor Name', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th>';
	echo '<th style="text-align:right">' . esc_html__( 'Amount (USD)', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Frequency', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Gateway', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( $recurring_donations ) {
		foreach ( $recurring_donations as $donation ) {
			$amount    = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_AMOUNT, true );
			$email     = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_EMAIL, true );
			$frequency = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_FREQUENCY, true );
			$gateway   = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_GATEWAY, true );
			$donor_name = $donation->post_title;

			// Extract name from title if it contains email in parentheses
			if ( strpos( $donor_name, '(' ) !== false ) {
				$donor_name = trim( substr( $donor_name, 0, strpos( $donor_name, '(' ) ) );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $donor_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td style="text-align:right">$' . esc_html( number_format_i18n( $amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', $frequency ) ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( 'M j, Y g:i A', $donation ) ) . '</td>';
			echo '<td>' . esc_html( ucfirst( str_replace( '_', ' ', $gateway ) ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="6">' . esc_html__( 'No recurring donations found for this period.', 'nonprofit-manager' ) . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '</div>';
}

/* ==============================================================
 * Donation Settings Page (Form customization)
 * ============================================================= */
function npmp_render_donation_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	/* Handle form submission */
	if (
		isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
		isset( $_POST['npmp_donation_settings_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_donation_settings_nonce'] ) ), 'npmp_donation_settings' )
	) {
		$text_fields = array(
			'npmp_donation_form_title',
			'npmp_donation_amount_label',
			'npmp_donation_email_label',
			'npmp_donation_button_label',
		);
		foreach ( $text_fields as $k ) {
			update_option( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		update_option( 'npmp_donation_form_intro', sanitize_textarea_field( wp_unslash( $_POST['npmp_donation_form_intro'] ?? '' ) ) );
		update_option( 'npmp_donation_page_id', absint( wp_unslash( $_POST['npmp_donation_page_id'] ?? 0 ) ) );

		// Pro-only thank you email settings
		if ( npmp_is_pro() ) {
			// Thank you email settings
			update_option( 'npmp_enable_thank_you_email', ! empty( $_POST['npmp_enable_thank_you_email'] ) ? 1 : 0 );
			update_option( 'npmp_thank_you_subject', sanitize_text_field( wp_unslash( $_POST['npmp_thank_you_subject'] ?? '' ) ) );
			update_option( 'npmp_thank_you_message', wp_kses_post( wp_unslash( $_POST['npmp_thank_you_message'] ?? '' ) ) );
		}

		echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	$is_pro = npmp_is_pro();

	/* Current options */
	$thank_you_enabled = get_option( 'npmp_enable_thank_you_email', 1 );
	$thank_you_subject = get_option( 'npmp_thank_you_subject', 'Thank You for Your Donation!' );
	$thank_you_message = get_option( 'npmp_thank_you_message', "Dear {donor_name},\n\nThank you for your generous donation of {donation_amount} on {donation_date}.\n\nYour support helps us make a difference.\n\nBest regards,\n{site_name}" );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Donation Settings', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Customize your public donation form and configure donation options.', 'nonprofit-manager' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'npmp_donation_settings', 'npmp_donation_settings_nonce' ); ?>

			<!-- Donation Page -->
			<h2><?php esc_html_e( 'Donation Page', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Choose a default page (form will be auto-inserted) or use the shortcode', 'nonprofit-manager' ); ?> <code>[npmp_donation_form]</code>.</p>
			<?php
			wp_dropdown_pages(
				array(
					'name'             => 'npmp_donation_page_id',
					'selected'         => absint( get_option( 'npmp_donation_page_id' ) ),
					'show_option_none' => esc_html__( 'None (no default donation page)', 'nonprofit-manager' ),
				)
			);
			?>

			<!-- Form Labels -->
			<h2><?php esc_html_e( 'Customize Donation Form', 'nonprofit-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Form Title', 'nonprofit-manager' ); ?></th>
					<td><input type="text" name="npmp_donation_form_title" value="<?php echo esc_attr( get_option( 'npmp_donation_form_title', 'Support Our Mission' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Intro Text', 'nonprofit-manager' ); ?></th>
					<td><textarea name="npmp_donation_form_intro" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'npmp_donation_form_intro', 'Your contribution helps us make a difference.' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Amount Label', 'nonprofit-manager' ); ?></th>
					<td><input type="text" name="npmp_donation_amount_label" value="<?php echo esc_attr( get_option( 'npmp_donation_amount_label', 'Donation Amount' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email Label', 'nonprofit-manager' ); ?></th>
					<td><input type="text" name="npmp_donation_email_label" value="<?php echo esc_attr( get_option( 'npmp_donation_email_label', 'Your Email' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Submit Button Label', 'nonprofit-manager' ); ?></th>
					<td><input type="text" name="npmp_donation_button_label" value="<?php echo esc_attr( get_option( 'npmp_donation_button_label', 'Donate Now' ) ); ?>" class="regular-text"></td>
				</tr>
			</table>

			<!-- Thank You Email Section -->
			<h2><?php esc_html_e( 'Automated Thank You Email', 'nonprofit-manager' ); ?></h2>

			<?php if ( $is_pro ) : ?>
				<!-- Pro: Full functionality -->
				<p><?php esc_html_e( 'Send an automated thank you email to donors after successful donations.', 'nonprofit-manager' ); ?></p>

				<label>
					<input type="checkbox" name="npmp_enable_thank_you_email" value="1" <?php checked( $thank_you_enabled, 1 ); ?>>
					<?php esc_html_e( 'Enable Thank You Emails', 'nonprofit-manager' ); ?>
				</label>

				<table class="form-table" style="margin-top: 15px;">
					<tr>
						<th><?php esc_html_e( 'Email Subject', 'nonprofit-manager' ); ?></th>
						<td>
							<input type="text" name="npmp_thank_you_subject" value="<?php echo esc_attr( $thank_you_subject ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Subject line for the thank you email.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email Message', 'nonprofit-manager' ); ?></th>
						<td>
							<textarea name="npmp_thank_you_message" rows="8" class="large-text"><?php echo esc_textarea( $thank_you_message ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Available shortcodes:', 'nonprofit-manager' ); ?>
								<code>{donor_name}</code>,
								<code>{donor_email}</code>,
								<code>{donation_amount}</code>,
								<code>{donation_date}</code>,
								<code>{donation_frequency}</code>,
								<code>{site_name}</code>
							</p>
						</td>
					</tr>
				</table>
			<?php else : ?>
				<!-- Free: Upsell for Pro -->
				<div style="background: #f0f6fc; border: 1px solid #2271b1; border-radius: 4px; padding: 20px; margin-top: 10px;">
					<p><strong><?php esc_html_e( 'Automated Thank You Emails are a Pro Feature', 'nonprofit-manager' ); ?></strong></p>
					<p><?php esc_html_e( 'Upgrade to Nonprofit Manager Pro to automatically send personalized thank you emails to donors after they complete a donation.', 'nonprofit-manager' ); ?></p>

					<p><strong><?php esc_html_e( 'Pro Features Include:', 'nonprofit-manager' ); ?></strong></p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Automated thank you emails sent immediately after donation', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Customizable email subject and message templates', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Dynamic shortcodes for personalization (donor name, amount, date, etc.)', 'nonprofit-manager' ); ?></li>
						<li><?php esc_html_e( 'Strengthens donor relationships and improves retention', 'nonprofit-manager' ); ?></li>
					</ul>

					<p style="margin-top: 15px;">
						<a href="<?php echo esc_url( npmp_get_upgrade_url() ); ?>" class="button button-primary" target="_blank">
							<?php esc_html_e( 'Upgrade to Pro', 'nonprofit-manager' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php submit_button( __( 'Save Donation Settings', 'nonprofit-manager' ) ); ?>
		</form>
	</div>
	<?php
}

/* ==============================================================
 * Payment Settings Page (Gateway configuration)
 * ============================================================= */
function npmp_render_payment_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$is_pro = npmp_is_pro();

	/* Handle form submission */
	if (
		isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
		isset( $_POST['npmp_payment_gateway_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_payment_gateway_nonce'] ) ), 'npmp_payment_gateway' )
	) {
		// Handle multiple gateway selection (checkboxes)
		$enabled_gateways = array();

		// Check which gateways are enabled
		$posted_gateways = isset( $_POST['npmp_gateways'] ) && is_array( $_POST['npmp_gateways'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['npmp_gateways'] ) )
			: array();

		// Validate each gateway selection
		foreach ( $posted_gateways as $gateway ) {
			// Free users can only enable free-tier gateways
			if ( ! $is_pro && ! in_array( $gateway, array( 'paypal_link', 'venmo_link' ), true ) ) {
				continue;
			}
			$enabled_gateways[] = $gateway;
		}

		// Save enabled gateways as array
		update_option( 'npmp_enabled_payment_gateways', $enabled_gateways );

		// Save gateway-specific settings (these are saved regardless of whether gateway is enabled)
		// PayPal Link
		if ( isset( $_POST['npmp_paypal_email'] ) ) {
			update_option( 'npmp_paypal_email', sanitize_email( wp_unslash( $_POST['npmp_paypal_email'] ) ) );
		}

		// Venmo Link
		if ( isset( $_POST['npmp_venmo_handle'] ) ) {
			update_option( 'npmp_venmo_handle', sanitize_text_field( wp_unslash( $_POST['npmp_venmo_handle'] ) ) );
		}

		// PayPal API (Pro only)
		if ( $is_pro ) {
			// Save PayPal mode
			if ( isset( $_POST['npmp_paypal_mode'] ) ) {
				update_option( 'npmp_paypal_mode', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_mode'] ) ) );
			}

			// Save PayPal Live keys
			if ( isset( $_POST['npmp_paypal_live_client_id'] ) ) {
				update_option( 'npmp_paypal_live_client_id', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_live_client_id'] ) ) );
			}
			if ( isset( $_POST['npmp_paypal_live_secret'] ) ) {
				update_option( 'npmp_paypal_live_secret', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_live_secret'] ) ) );
			}

			// Save PayPal Sandbox keys
			if ( isset( $_POST['npmp_paypal_sandbox_client_id'] ) ) {
				update_option( 'npmp_paypal_sandbox_client_id', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_sandbox_client_id'] ) ) );
			}
			if ( isset( $_POST['npmp_paypal_sandbox_secret'] ) ) {
				update_option( 'npmp_paypal_sandbox_secret', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_sandbox_secret'] ) ) );
			}

			// Stripe (Pro only)
			// Save Stripe mode
			if ( isset( $_POST['npmp_stripe_mode'] ) ) {
				update_option( 'npmp_stripe_mode', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_mode'] ) ) );
			}

			// Save Stripe Live keys
			if ( isset( $_POST['npmp_stripe_live_publishable_key'] ) ) {
				update_option( 'npmp_stripe_live_publishable_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_live_publishable_key'] ) ) );
			}
			if ( isset( $_POST['npmp_stripe_live_secret_key'] ) ) {
				update_option( 'npmp_stripe_live_secret_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_live_secret_key'] ) ) );
			}

			// Save Stripe Test keys
			if ( isset( $_POST['npmp_stripe_test_publishable_key'] ) ) {
				update_option( 'npmp_stripe_test_publishable_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_test_publishable_key'] ) ) );
			}
			if ( isset( $_POST['npmp_stripe_test_secret_key'] ) ) {
				update_option( 'npmp_stripe_test_secret_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_test_secret_key'] ) ) );
			}
		}

		// Always enable one-time for free users
		update_option( 'npmp_enable_one_time', 1 );

		// Pro-only gateway-specific recurring frequencies
		if ( $is_pro ) {
			// PayPal API frequencies
			update_option( 'npmp_paypal_enable_weekly',     ! empty( $_POST['npmp_paypal_enable_weekly'] ) ? 1 : 0 );
			update_option( 'npmp_paypal_enable_monthly',    ! empty( $_POST['npmp_paypal_enable_monthly'] ) ? 1 : 0 );
			update_option( 'npmp_paypal_enable_quarterly',  ! empty( $_POST['npmp_paypal_enable_quarterly'] ) ? 1 : 0 );
			update_option( 'npmp_paypal_enable_annual',     ! empty( $_POST['npmp_paypal_enable_annual'] ) ? 1 : 0 );

			// Stripe frequencies
			update_option( 'npmp_stripe_enable_weekly',     ! empty( $_POST['npmp_stripe_enable_weekly'] ) ? 1 : 0 );
			update_option( 'npmp_stripe_enable_monthly',    ! empty( $_POST['npmp_stripe_enable_monthly'] ) ? 1 : 0 );
			update_option( 'npmp_stripe_enable_quarterly',  ! empty( $_POST['npmp_stripe_enable_quarterly'] ) ? 1 : 0 );
			update_option( 'npmp_stripe_enable_annual',     ! empty( $_POST['npmp_stripe_enable_annual'] ) ? 1 : 0 );
		}

		echo '<div class="updated"><p>' . esc_html__( 'Payment settings saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	$enabled_gateways = get_option( 'npmp_enabled_payment_gateways', array() );
	if ( ! is_array( $enabled_gateways ) ) {
		$enabled_gateways = array();
	}

	// Backward compatibility: migrate old single gateway to new array format
	$old_gateway = get_option( 'npmp_payment_gateway', '' );
	if ( empty( $enabled_gateways ) && ! empty( $old_gateway ) && 'none' !== $old_gateway ) {
		$enabled_gateways = array( $old_gateway );
		update_option( 'npmp_enabled_payment_gateways', $enabled_gateways );
	}

	// For backward compatibility with UI that still uses $current_gateway
	$current_gateway = ! empty( $enabled_gateways ) ? $enabled_gateways[0] : 'none';

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Payment Gateway Settings', 'nonprofit-manager' ); ?></h1>
		<p><?php esc_html_e( 'Configure how you want to accept donations.', 'nonprofit-manager' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'npmp_payment_gateway', 'npmp_payment_gateway_nonce' ); ?>

			<h2><?php esc_html_e( 'Choose Payment Gateways', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Select one or more payment gateways to accept donations. You can enable multiple payment methods.', 'nonprofit-manager' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Payment Gateways', 'nonprofit-manager' ); ?></th>
					<td>
						<!-- Free Options -->
						<label><input type="checkbox" name="npmp_gateways[]" value="paypal_link" <?php checked( in_array( 'paypal_link', $enabled_gateways, true ) ); ?> class="npmp-gateway-checkbox" data-gateway="paypal_link"> <?php esc_html_e( 'PayPal (Link)', 'nonprofit-manager' ); ?></label><br>

						<label><input type="checkbox" name="npmp_gateways[]" value="venmo_link" <?php checked( in_array( 'venmo_link', $enabled_gateways, true ) ); ?> class="npmp-gateway-checkbox" data-gateway="venmo_link"> <?php esc_html_e( 'Venmo (Link)', 'nonprofit-manager' ); ?></label><br>

						<!-- Pro Options -->
						<label>
							<input type="checkbox" name="npmp_gateways[]" value="paypal_api" <?php checked( in_array( 'paypal_api', $enabled_gateways, true ) ); ?> <?php disabled( ! $is_pro ); ?> class="npmp-gateway-checkbox" data-gateway="paypal_api">
							<?php
							if ( $is_pro ) {
								esc_html_e( 'PayPal API', 'nonprofit-manager' );
							} else {
								esc_html_e( 'PayPal API (Pro Upgrade Required)', 'nonprofit-manager' );
							}
							?>
						</label><br>

						<label>
							<input type="checkbox" name="npmp_gateways[]" value="stripe" <?php checked( in_array( 'stripe', $enabled_gateways, true ) ); ?> <?php disabled( ! $is_pro ); ?> class="npmp-gateway-checkbox" data-gateway="stripe">
							<?php
							if ( $is_pro ) {
								esc_html_e( 'Stripe', 'nonprofit-manager' );
							} else {
								esc_html_e( 'Stripe (Pro Upgrade Required)', 'nonprofit-manager' );
							}
							?>
						</label><br>

						<?php if ( ! $is_pro ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to upgrade page */
									wp_kses_post( __( 'Want to use PayPal API or Stripe? <a href="%s" target="_blank">Upgrade to Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
									esc_url( npmp_get_upgrade_url() )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<!-- PayPal Link Settings -->
			<div class="npmp-gateway-settings npmp-gateway-paypal_link" style="<?php echo ! in_array( 'paypal_link', $enabled_gateways, true ) ? 'display:none;' : ''; ?>">
				<hr>
				<h3><?php esc_html_e( 'PayPal Link Configuration', 'nonprofit-manager' ); ?></h3>
				<p><?php esc_html_e( 'When donors click the PayPal logo, they will be redirected to PayPal with a pre-filled payment form.', 'nonprofit-manager' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="npmp_paypal_email"><?php esc_html_e( 'PayPal Email Address', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="email" id="npmp_paypal_email" name="npmp_paypal_email" value="<?php echo esc_attr( get_option( 'npmp_paypal_email', '' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'The email address associated with your PayPal account.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Venmo Link Settings -->
			<div class="npmp-gateway-settings npmp-gateway-venmo_link" style="<?php echo ! in_array( 'venmo_link', $enabled_gateways, true ) ? 'display:none;' : ''; ?>">
				<hr>
				<h3><?php esc_html_e( 'Venmo Link Configuration', 'nonprofit-manager' ); ?></h3>
				<p><?php esc_html_e( 'When donors click the Venmo logo, they will be redirected to Venmo with a pre-filled payment form.', 'nonprofit-manager' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="npmp_venmo_handle"><?php esc_html_e( 'Venmo Username/Phone', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp_venmo_handle" name="npmp_venmo_handle" value="<?php echo esc_attr( get_option( 'npmp_venmo_handle', '' ) ); ?>" class="regular-text" placeholder="@username or phone number">
							<p class="description"><?php esc_html_e( 'Your Venmo username (with @) or phone number.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php if ( $is_pro ) : ?>
				<!-- PayPal API Settings -->
				<div class="npmp-gateway-settings npmp-gateway-paypal_api" style="<?php echo ! in_array( 'paypal_api', $enabled_gateways, true ) ? 'display:none;' : ''; ?>">
					<hr>
					<h3><?php esc_html_e( 'PayPal API Configuration', 'nonprofit-manager' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: URL to PayPal developer dashboard */
							wp_kses_post( __( 'Get your API credentials from the <a href="%s" target="_blank">PayPal Developer Dashboard</a>.', 'nonprofit-manager' ) ),
							'https://developer.paypal.com/dashboard/applications'
						);
						?>
					</p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Mode', 'nonprofit-manager' ); ?></th>
							<td>
								<?php $paypal_mode = get_option( 'npmp_paypal_mode', 'live' ); ?>
								<label><input type="radio" name="npmp_paypal_mode" value="live" <?php checked( $paypal_mode, 'live' ); ?>> <?php esc_html_e( 'Live', 'nonprofit-manager' ); ?></label>
								<label><input type="radio" name="npmp_paypal_mode" value="sandbox" <?php checked( $paypal_mode, 'sandbox' ); ?>> <?php esc_html_e( 'Sandbox (Testing)', 'nonprofit-manager' ); ?></label>
								<p class="description"><?php esc_html_e( 'Select Live for production or Sandbox for testing.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Live API Credentials', 'nonprofit-manager' ); ?></h4>
					<table class="form-table">
						<tr>
							<th><label for="npmp_paypal_live_client_id"><?php esc_html_e( 'Live Client ID', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_paypal_live_client_id" name="npmp_paypal_live_client_id" value="<?php echo esc_attr( get_option( 'npmp_paypal_live_client_id', '' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Your PayPal Live REST API Client ID.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="npmp_paypal_live_secret"><?php esc_html_e( 'Live Secret Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_paypal_live_secret" name="npmp_paypal_live_secret" value="<?php echo esc_attr( get_option( 'npmp_paypal_live_secret', '' ) ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Your PayPal Live REST API Secret Key.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Sandbox API Credentials', 'nonprofit-manager' ); ?></h4>
					<table class="form-table">
						<tr>
							<th><label for="npmp_paypal_sandbox_client_id"><?php esc_html_e( 'Sandbox Client ID', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_paypal_sandbox_client_id" name="npmp_paypal_sandbox_client_id" value="<?php echo esc_attr( get_option( 'npmp_paypal_sandbox_client_id', '' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Your PayPal Sandbox REST API Client ID.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="npmp_paypal_sandbox_secret"><?php esc_html_e( 'Sandbox Secret Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_paypal_sandbox_secret" name="npmp_paypal_sandbox_secret" value="<?php echo esc_attr( get_option( 'npmp_paypal_sandbox_secret', '' ) ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Your PayPal Sandbox REST API Secret Key.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'PayPal Donation Frequencies', 'nonprofit-manager' ); ?></h4>
					<p><?php esc_html_e( 'Select which donation frequencies are available for PayPal donations. One-time donations are always enabled.', 'nonprofit-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Recurring Donation Options:', 'nonprofit-manager' ); ?></strong></p>
					<label><input type="checkbox" name="npmp_paypal_enable_weekly" value="1" <?php checked( get_option( 'npmp_paypal_enable_weekly', 0 ), 1 ); ?>> <?php esc_html_e( 'Weekly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_monthly" value="1" <?php checked( get_option( 'npmp_paypal_enable_monthly', 0 ), 1 ); ?>> <?php esc_html_e( 'Monthly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_quarterly" value="1" <?php checked( get_option( 'npmp_paypal_enable_quarterly', 0 ), 1 ); ?>> <?php esc_html_e( 'Quarterly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_annual" value="1" <?php checked( get_option( 'npmp_paypal_enable_annual', 0 ), 1 ); ?>> <?php esc_html_e( 'Annual', 'nonprofit-manager' ); ?></label><br>
					<p class="description"><?php esc_html_e( 'PayPal API supports recurring subscriptions for all frequency types.', 'nonprofit-manager' ); ?></p>
				</div>

				<!-- Stripe Settings -->
				<div class="npmp-gateway-settings npmp-gateway-stripe" style="<?php echo ! in_array( 'stripe', $enabled_gateways, true ) ? 'display:none;' : ''; ?>">
					<hr>
					<h3><?php esc_html_e( 'Stripe Configuration', 'nonprofit-manager' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: %s: URL to Stripe dashboard */
							wp_kses_post( __( 'Get your API keys from the <a href="%s" target="_blank">Stripe Dashboard</a>.', 'nonprofit-manager' ) ),
							'https://dashboard.stripe.com/apikeys'
						);
						?>
					</p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Mode', 'nonprofit-manager' ); ?></th>
							<td>
								<?php $stripe_mode = get_option( 'npmp_stripe_mode', 'live' ); ?>
								<label><input type="radio" name="npmp_stripe_mode" value="live" <?php checked( $stripe_mode, 'live' ); ?>> <?php esc_html_e( 'Live', 'nonprofit-manager' ); ?></label>
								<label><input type="radio" name="npmp_stripe_mode" value="test" <?php checked( $stripe_mode, 'test' ); ?>> <?php esc_html_e( 'Test', 'nonprofit-manager' ); ?></label>
								<p class="description"><?php esc_html_e( 'Use test mode for testing with Stripe test keys. Switch to live mode for production.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Live API Keys', 'nonprofit-manager' ); ?></h4>
					<table class="form-table">
						<tr>
							<th><label for="npmp_stripe_live_publishable_key"><?php esc_html_e( 'Live Publishable Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_stripe_live_publishable_key" name="npmp_stripe_live_publishable_key" value="<?php echo esc_attr( get_option( 'npmp_stripe_live_publishable_key', '' ) ); ?>" class="regular-text" placeholder="pk_live_...">
								<p class="description"><?php esc_html_e( 'Your Stripe Live Publishable Key (starts with pk_live_).', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="npmp_stripe_live_secret_key"><?php esc_html_e( 'Live Secret Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_stripe_live_secret_key" name="npmp_stripe_live_secret_key" value="<?php echo esc_attr( get_option( 'npmp_stripe_live_secret_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" placeholder="sk_live_...">
								<p class="description"><?php esc_html_e( 'Your Stripe Live Secret Key (starts with sk_live_).', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Test API Keys', 'nonprofit-manager' ); ?></h4>
					<table class="form-table">
						<tr>
							<th><label for="npmp_stripe_test_publishable_key"><?php esc_html_e( 'Test Publishable Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="text" id="npmp_stripe_test_publishable_key" name="npmp_stripe_test_publishable_key" value="<?php echo esc_attr( get_option( 'npmp_stripe_test_publishable_key', '' ) ); ?>" class="regular-text" placeholder="pk_test_...">
								<p class="description"><?php esc_html_e( 'Your Stripe Test Publishable Key (starts with pk_test_).', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="npmp_stripe_test_secret_key"><?php esc_html_e( 'Test Secret Key', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_stripe_test_secret_key" name="npmp_stripe_test_secret_key" value="<?php echo esc_attr( get_option( 'npmp_stripe_test_secret_key', '' ) ); ?>" class="regular-text" autocomplete="new-password" placeholder="sk_test_...">
								<p class="description"><?php esc_html_e( 'Your Stripe Test Secret Key (starts with sk_test_).', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Stripe Donation Frequencies', 'nonprofit-manager' ); ?></h4>
					<p><?php esc_html_e( 'Select which donation frequencies are available for Stripe donations. One-time donations are always enabled.', 'nonprofit-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Recurring Donation Options:', 'nonprofit-manager' ); ?></strong></p>
					<label><input type="checkbox" name="npmp_stripe_enable_weekly" value="1" <?php checked( get_option( 'npmp_stripe_enable_weekly', 0 ), 1 ); ?>> <?php esc_html_e( 'Weekly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_monthly" value="1" <?php checked( get_option( 'npmp_stripe_enable_monthly', 0 ), 1 ); ?>> <?php esc_html_e( 'Monthly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_quarterly" value="1" <?php checked( get_option( 'npmp_stripe_enable_quarterly', 0 ), 1 ); ?>> <?php esc_html_e( 'Quarterly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_annual" value="1" <?php checked( get_option( 'npmp_stripe_enable_annual', 0 ), 1 ); ?>> <?php esc_html_e( 'Annual', 'nonprofit-manager' ); ?></label><br>
					<p class="description"><?php esc_html_e( 'Stripe supports recurring subscriptions for all frequency types.', 'nonprofit-manager' ); ?></p>
				</div>
			<?php endif; ?>

			<?php submit_button( __( 'Save Payment Settings', 'nonprofit-manager' ) ); ?>
		</form>

		<script>
		jQuery(document).ready(function($) {
			// Show/hide gateway settings based on checkbox selection
			$('.npmp-gateway-checkbox').on('change', function() {
				var gateway = $(this).attr('data-gateway');
				if ($(this).is(':checked')) {
					$('.npmp-gateway-' + gateway).show();
				} else {
					$('.npmp-gateway-' + gateway).hide();
				}
			});
		});
		</script>
	</div>
	<?php
}

/* ==============================================================
 * Donation Form Shortcode
 * ============================================================= */
add_shortcode( 'npmp_donation_form', 'npmp_render_donation_form' );

function npmp_render_donation_form() {
	// Get enabled gateways (new checkbox-based system)
	$enabled_gateways = get_option( 'npmp_enabled_payment_gateways', array() );

	// Backward compatibility: check old single gateway option
	if ( empty( $enabled_gateways ) ) {
		$old_gateway = get_option( 'npmp_payment_gateway', '' );
		if ( ! empty( $old_gateway ) && 'none' !== $old_gateway ) {
			$enabled_gateways = array( $old_gateway );
		}
	}

	// If no gateways are enabled, show message
	if ( empty( $enabled_gateways ) ) {
		return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'Online donations are not configured yet. Please contact the site administrator.', 'nonprofit-manager' ) . '</p></div>';
	}

	// If only one gateway is enabled, render it directly
	if ( count( $enabled_gateways ) === 1 && function_exists( 'npmp_render_gateway_donation_form' ) ) {
		return npmp_render_gateway_donation_form( $enabled_gateways[0] );
	}

	// If multiple gateways are enabled, render a tabbed interface
	if ( function_exists( 'npmp_render_multi_gateway_donation_form' ) ) {
		return npmp_render_multi_gateway_donation_form( $enabled_gateways );
	}

	return '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html__( 'Donation form is loading. Please refresh the page.', 'nonprofit-manager' ) . '</p></div>';
}

/* ==============================================================
 * Inject donation form on selected page
 * ============================================================= */
add_filter( 'the_content', function ( $content ) {
	if ( is_page() && get_the_ID() === absint( get_option( 'npmp_donation_page_id' ) ) ) {
		return $content . do_shortcode( '[npmp_donation_form]' );
	}
	return $content;
} );
