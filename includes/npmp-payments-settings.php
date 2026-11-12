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
 * Donations Dashboard
 * ============================================================= */
function npmp_render_donations_dashboard() {
	if ( ! current_user_can( npmp_staff_cap() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}

	$dm = NPMP_Donation_Manager::get_instance();

	/* Read & validate filter params */
	// The year and month on the site's clock, the same one post_date uses.
	$current_year  = (int) wp_date( 'Y' );
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

	// A per-period donation summary used to be computed here and never shown.
	// Dropping it saved a grouped query on every page load, and the method
	// itself was removed once nothing called it.
	$years = $dm->years_with_donations();
	// A new year with no gifts yet still needs its own option, or the menu
	// showed last year while the tables below showed this one.
	if ( ! in_array( $current_year, $years, true ) ) {
		$years[] = $current_year;
		rsort( $years );
	}

	// Label the amount column with the currency when every donation is in
	// one. A site that has taken more than one shows each amount with its own.
	$currencies   = npmp_donation_currencies() ?: array( npmp_currency() );
	$amount_label = __( 'Amount', 'nonprofit-manager' );
	if ( 1 === count( $currencies ) ) {
		$amount_label = 'USD' === $currencies[0]
			? __( 'Amount (USD)', 'nonprofit-manager' )
			/* translators: %s: ISO currency code, e.g. GBP. */
			: sprintf( __( 'Amount (%s)', 'nonprofit-manager' ), $currencies[0] );
	}

	echo '<div class="wrap npmp-admin-page">';
	npmp_admin_page_title( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( '%s Dashboard', 'nonprofit-manager' ), npmp_label( 'donations' ) ) );

	/* Filter form */
	echo '<form method="get" class="npmp-filter-bar">';
	echo '<input type="hidden" name="page" value="npmp_donations_group">';
	wp_nonce_field( 'npmp_donations_filter' );
	echo '<label for="npmp-donations-year">' . esc_html__( 'Year', 'nonprofit-manager' ) . '</label> ';
	echo '<select name="year" id="npmp-donations-year">';
	foreach ( $years as $y ) {
		echo '<option value="' . esc_attr( $y ) . '"' . selected( $y, $current_year, false ) . '>' . esc_html( $y ) . '</option>';
	}
	echo '</select> ';

	echo '<label for="npmp-donations-month">' . esc_html__( 'Month', 'nonprofit-manager' ) . '</label> ';
	echo '<select name="month" id="npmp-donations-month">';
	echo '<option value="0">' . esc_html__( 'All', 'nonprofit-manager' ) . '</option>';
	for ( $m = 1; $m <= 12; $m ++ ) {
		echo '<option value="' . esc_attr( $m ) . '"' . selected( $m, $current_month, false ) . '>' . esc_html( $GLOBALS['wp_locale']->get_month( $m ) ) . '</option>';
	}
	echo '</select> ';
	submit_button( esc_html__( 'Filter', 'nonprofit-manager' ), 'secondary', '', false );
	echo '</form>';

	/** This action is documented in includes/npmp-pro-feature-pages.php. */
	do_action(
		'npmp_admin_table_toolbar',
		'donations',
		array(
			'year'  => $current_year,
			'month' => $current_month,
		)
	);

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

	$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$empty_next  = npmp_donations_empty_next_step();

	/* One-Time Donations Table */
	echo '<h2>' . esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'One-Time %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ) . '</h2>';
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html( sprintf( /* translators: %s: singular word for a donor, such as Donor or Supporter. */ __( '%s Name', 'nonprofit-manager' ), npmp_label( 'donor' ) ) ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col" class="npmp-col-amount">' . esc_html( $amount_label ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Gateway', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( $one_time_donations ) {
		foreach ( $one_time_donations as $donation ) {
			$amount   = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_AMOUNT, true );
			$email    = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_EMAIL, true );
			$gateway  = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_GATEWAY, true );
			$donor_name = $donation->post_title;

			// Extract name from title if it contains email in parentheses
			$paren_pos = strpos( $donor_name, '(' );
			if ( false !== $paren_pos ) {
				$donor_name = trim( substr( $donor_name, 0, $paren_pos ) );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $donor_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td class="npmp-col-amount">' . esc_html( npmp_format_amount( (float) $amount, $dm->get_donation_currency( $donation->ID ) ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( $date_format, $donation ) ) . '</td>';
			echo '<td>' . esc_html( npmp_gateway_label( $gateway ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="5">' . esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'No one-time %s found for this period.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ) . $empty_next . '</td></tr>';
	}
	echo '</tbody></table>';

	/* Recurring Donations Table */
	echo '<h2 class="npmp-section-gap">' . esc_html( sprintf( /* translators: %s: plural word for donations, such as Donations or Contributions. */ __( 'Recurring %s', 'nonprofit-manager' ), npmp_label( 'donations' ) ) ) . '</h2>';
	echo '<table class="widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th scope="col">' . esc_html( sprintf( /* translators: %s: singular word for a donor, such as Donor or Supporter. */ __( '%s Name', 'nonprofit-manager' ), npmp_label( 'donor' ) ) ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Email', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col" class="npmp-col-amount">' . esc_html( $amount_label ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Frequency', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Gateway', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( $recurring_donations ) {
		foreach ( $recurring_donations as $donation ) {
			$amount    = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_AMOUNT, true );
			$email     = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_EMAIL, true );
			$frequency = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_FREQUENCY, true );
			$gateway   = get_post_meta( $donation->ID, NPMP_Donation_Manager::META_GATEWAY, true );
			$donor_name = $donation->post_title;

			// Extract name from title if it contains email in parentheses
			$paren_pos = strpos( $donor_name, '(' );
			if ( false !== $paren_pos ) {
				$donor_name = trim( substr( $donor_name, 0, $paren_pos ) );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $donor_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td class="npmp-col-amount">' . esc_html( npmp_format_amount( (float) $amount, $dm->get_donation_currency( $donation->ID ) ) ) . '</td>';
			echo '<td>' . esc_html( npmp_frequency_label( $frequency ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( $date_format, $donation ) ) . '</td>';
			echo '<td>' . esc_html( npmp_gateway_label( $gateway ) ) . '</td>';
			echo '</tr>';
		}
	} else {
		echo '<tr><td colspan="6">' . esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'No recurring %s found for this period.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ) . $empty_next . '</td></tr>';
	}
	echo '</tbody></table>';

	if ( ! npmp_is_pro() ) {
		echo '<p class="description npmp-field-gap">';
		printf(
			/* translators: %s: URL to upgrade page */
			wp_kses_post( __( 'Weekly, monthly, quarterly, and annual giving through Stripe or the PayPal API comes with <a href="%s" target="_blank" rel="noopener">Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
			esc_url( npmp_get_upgrade_url( 'recurring_donations' ) )
		);
		echo '</p>';
	}

	echo '</div>';
}

/**
 * The next step after an empty donations table: set up a gateway, or add the
 * form to a page.
 *
 * @return string HTML, starting with a space.
 */
function npmp_donations_empty_next_step() {
	$gateways = (array) get_option( 'npmp_enabled_payment_gateways', array() );
	$legacy   = get_option( 'npmp_payment_gateway', '' );
	if ( ! $gateways && ( ! $legacy || 'none' === $legacy ) ) {
		return ' <a href="' . esc_url( admin_url( 'admin.php?page=npmp_payment_settings' ) ) . '">' . esc_html__( 'Set up a payment method', 'nonprofit-manager' ) . '</a>';
	}
	return ' <a href="' . esc_url( admin_url( 'admin.php?page=npmp_donation_settings' ) ) . '">' . esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'Pick a page for your %s form', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ) . '</a>';
}

/**
 * The name a person reads for a stored gateway slug.
 *
 * @param string $gateway Gateway slug saved with the donation.
 * @return string
 */
function npmp_gateway_label( $gateway ) {
	$labels = array(
		'stripe'      => __( 'Stripe', 'nonprofit-manager' ),
		'paypal_api'  => __( 'PayPal', 'nonprofit-manager' ),
		'paypal'      => __( 'PayPal', 'nonprofit-manager' ),
		'paypal_link' => __( 'PayPal', 'nonprofit-manager' ),
		'venmo_link'  => __( 'Venmo', 'nonprofit-manager' ),
		'venmo'       => __( 'Venmo', 'nonprofit-manager' ),
	);
	$gateway = (string) $gateway;
	return $labels[ $gateway ] ?? ucfirst( str_replace( '_', ' ', $gateway ) );
}

/**
 * The name a person reads for a stored donation frequency.
 *
 * @param string $frequency Frequency saved with the donation ('annual' and 'yearly' both occur).
 * @return string
 */
function npmp_frequency_label( $frequency ) {
	$labels = array(
		'one_time'  => __( 'One-Time', 'nonprofit-manager' ),
		'weekly'    => __( 'Weekly', 'nonprofit-manager' ),
		'monthly'   => __( 'Monthly', 'nonprofit-manager' ),
		'quarterly' => __( 'Quarterly', 'nonprofit-manager' ),
		'annual'    => __( 'Annual', 'nonprofit-manager' ),
		'yearly'    => __( 'Annual', 'nonprofit-manager' ),
	);
	$frequency = (string) $frequency;
	return $labels[ $frequency ] ?? ucfirst( str_replace( '_', ' ', $frequency ) );
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

		$saved = true;
	}

	$is_pro = npmp_is_pro();

	/* Current options */
	$thank_you_enabled = get_option( 'npmp_enable_thank_you_email', 1 );
	$thank_you_default = npmp_thank_you_defaults();
	$thank_you_subject = get_option( 'npmp_thank_you_subject', $thank_you_default['subject'] );
	$thank_you_message = get_option( 'npmp_thank_you_message', $thank_you_default['message'] );
	$form_labels       = npmp_donation_form_default_labels();

	?>
	<div class="wrap npmp-admin-page">
		<?php npmp_admin_page_title( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( '%s Settings', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?>
		<?php if ( ! empty( $saved ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'Customize your public %1$s form and its options.', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'npmp_donation_settings', 'npmp_donation_settings_nonce' ); ?>

			<!-- Donation Page -->
			<h2><?php echo esc_html( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( '%s page', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'Pick a page, and you\'ll see the %s form at the bottom of that page. You can also put the form anywhere with the Donation block or this shortcode:', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ); ?> <code>[npmp_donation_form]</code></p>
			<label for="npmp_donation_page"><?php echo esc_html( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( '%s page', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?></label>
			<?php
			wp_dropdown_pages(
				array(
					'name'             => 'npmp_donation_page_id',
					'id'               => 'npmp_donation_page',
					'selected'         => absint( get_option( 'npmp_donation_page_id' ) ),
					'show_option_none' => esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'None (no default %s page)', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ),
				)
			);
			?>

			<!-- Form Labels -->
			<h2><?php echo esc_html( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( 'Customize the %s form', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="npmp_donation_form_title"><?php esc_html_e( 'Form title', 'nonprofit-manager' ); ?></label></th>
					<td><input type="text" name="npmp_donation_form_title" id="npmp_donation_form_title" value="<?php echo esc_attr( get_option( 'npmp_donation_form_title', $form_labels['title'] ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_donation_form_intro"><?php esc_html_e( 'Intro text', 'nonprofit-manager' ); ?></label></th>
					<td><textarea name="npmp_donation_form_intro" id="npmp_donation_form_intro" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'npmp_donation_form_intro', $form_labels['intro'] ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_donation_amount_label"><?php esc_html_e( 'Amount label', 'nonprofit-manager' ); ?></label></th>
					<td><input type="text" name="npmp_donation_amount_label" id="npmp_donation_amount_label" value="<?php echo esc_attr( get_option( 'npmp_donation_amount_label', $form_labels['amount_lbl'] ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_donation_email_label"><?php esc_html_e( 'Email label', 'nonprofit-manager' ); ?></label></th>
					<td><input type="text" name="npmp_donation_email_label" id="npmp_donation_email_label" value="<?php echo esc_attr( get_option( 'npmp_donation_email_label', $form_labels['email_lbl'] ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="npmp_donation_button_label"><?php esc_html_e( 'Submit button label', 'nonprofit-manager' ); ?></label></th>
					<td><input type="text" name="npmp_donation_button_label" id="npmp_donation_button_label" value="<?php echo esc_attr( get_option( 'npmp_donation_button_label', $form_labels['btn_lbl'] ) ); ?>" class="regular-text"></td>
				</tr>
			</table>

			<!-- Thank You Email Section -->
			<h2><?php esc_html_e( 'Thank-you email', 'nonprofit-manager' ); ?></h2>

			<?php if ( $is_pro ) : ?>
				<!-- Pro: Full functionality -->
				<p><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for donors, such as donors or supporters. */ __( 'You can send each of your %s a thank-you email every time they give.', 'nonprofit-manager' ), npmp_label( 'donors_lc' ) ) ); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Thank-you emails', 'nonprofit-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="npmp_enable_thank_you_email" value="1" <?php checked( $thank_you_enabled, 1 ); ?>>
								<?php esc_html_e( 'Send thank-you emails', 'nonprofit-manager' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp_thank_you_subject"><?php esc_html_e( 'Email subject', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" name="npmp_thank_you_subject" id="npmp_thank_you_subject" value="<?php echo esc_attr( $thank_you_subject ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'The subject line of the thank-you email.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="npmp_thank_you_message"><?php esc_html_e( 'Email message', 'nonprofit-manager' ); ?></label></th>
						<td>
							<textarea name="npmp_thank_you_message" id="npmp_thank_you_message" rows="8" class="large-text"><?php echo esc_textarea( $thank_you_message ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Placeholders you can use:', 'nonprofit-manager' ); ?>
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
				<!-- Free: one line and one upgrade link. -->
				<p class="npmp-pro-locked">
					<?php
					printf(
						/* translators: 1: lowercase plural word for donors, such as donors or supporters, 2: opening upgrade link tag, 3: closing link tag. */
						esc_html__( 'With Pro, you can send %1$s a thank-you in your own words, with the amount and date, each time they give. %2$sSee Pro%3$s', 'nonprofit-manager' ),
						esc_html( npmp_label( 'donors_lc' ) ),
						'<a href="' . esc_url( npmp_get_upgrade_url( 'thank_you_email' ) ) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php submit_button( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( 'Save %s settings', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?>
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
			// Free sites take one-time gifts through the PayPal link, the
			// Venmo link and Stripe. The PayPal API gateway needs Pro.
			if ( ! in_array( $gateway, npmp_allowed_gateways( $is_pro ), true ) ) {
				continue;
			}
			$enabled_gateways[] = $gateway;
		}

		// Save enabled gateways as array
		update_option( 'npmp_enabled_payment_gateways', $enabled_gateways );

		// Site currency, limited to the codes both Stripe and PayPal accept.
		if ( isset( $_POST['npmp_currency'] ) ) {
			$previous_currency = npmp_currency();
			$new_currency      = npmp_sanitize_currency( sanitize_text_field( wp_unslash( $_POST['npmp_currency'] ) ) );
			update_option( 'npmp_currency', $new_currency );

			$active_subscriptions = (int) apply_filters( 'npmp_active_subscription_count', 0 );
			if ( $new_currency !== $previous_currency && $active_subscriptions > 0 ) {
				echo '<div class="notice notice-warning"><p>' . esc_html(
					sprintf(
						/* translators: 1: number of subscriptions, 2: old currency code, 3: new currency code. */
						_n(
							'Currency changed from %2$s to %3$s. %1$d active subscription keeps billing in the currency it started in. New gifts and dues use %3$s.',
							'Currency changed from %2$s to %3$s. %1$d active subscriptions keep billing in the currency they started in. New gifts and dues use %3$s.',
							$active_subscriptions,
							'nonprofit-manager'
						),
						$active_subscriptions,
						$previous_currency,
						$new_currency
					)
				) . '</p></div>';
			}
		}

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
				update_option( 'npmp_paypal_mode', 'sandbox' === sanitize_key( wp_unslash( $_POST['npmp_paypal_mode'] ) ) ? 'sandbox' : 'live' );
			}

			// Save PayPal Live keys.
			// Secret fields render blank with a masked placeholder (the stored
			// value never goes back into the page HTML), so an empty submission
			// means "keep the existing secret", not "erase it".
			if ( isset( $_POST['npmp_paypal_live_client_id'] ) ) {
				update_option( 'npmp_paypal_live_client_id', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_live_client_id'] ) ) );
			}
			if ( ! empty( $_POST['npmp_paypal_live_secret'] ) ) {
				update_option( 'npmp_paypal_live_secret', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_live_secret'] ) ) );
			}

			// Save PayPal Sandbox keys
			if ( isset( $_POST['npmp_paypal_sandbox_client_id'] ) ) {
				update_option( 'npmp_paypal_sandbox_client_id', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_sandbox_client_id'] ) ) );
			}
			if ( ! empty( $_POST['npmp_paypal_sandbox_secret'] ) ) {
				update_option( 'npmp_paypal_sandbox_secret', sanitize_text_field( wp_unslash( $_POST['npmp_paypal_sandbox_secret'] ) ) );
			}

			/**
			 * Save Pro's own PayPal API settings. Fires only after this form's
			 * nonce and the manage_options check have passed.
			 */
			do_action( 'npmp_paypal_api_settings_save' );
		}

		// Stripe keys save on every site: one-time Stripe gifts are part of
		// the free plugin. Recurring gifts and dues (the webhook) need Pro.
		// Save Stripe mode
		if ( isset( $_POST['npmp_stripe_mode'] ) ) {
			update_option( 'npmp_stripe_mode', 'test' === sanitize_key( wp_unslash( $_POST['npmp_stripe_mode'] ) ) ? 'test' : 'live' );
		}

		// Save Stripe Live keys
		if ( isset( $_POST['npmp_stripe_live_publishable_key'] ) ) {
			update_option( 'npmp_stripe_live_publishable_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_live_publishable_key'] ) ) );
		}
		if ( ! empty( $_POST['npmp_stripe_live_secret_key'] ) ) {
			update_option( 'npmp_stripe_live_secret_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_live_secret_key'] ) ) );
		}

		// Save Stripe Test keys
		if ( isset( $_POST['npmp_stripe_test_publishable_key'] ) ) {
			update_option( 'npmp_stripe_test_publishable_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_test_publishable_key'] ) ) );
		}
		if ( ! empty( $_POST['npmp_stripe_test_secret_key'] ) ) {
			update_option( 'npmp_stripe_test_secret_key', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_test_secret_key'] ) ) );
		}

		// Stripe webhook signing secret (whsec_...). Used by the Pro
		// recurring/dues webhook to verify Stripe signatures. This option
		// was consumed by the webhook handler but had no admin field
		// anywhere, so it could only be set with update_option() by hand.
		if ( ! empty( $_POST['npmp_stripe_webhook_secret'] ) ) {
			update_option( 'npmp_stripe_webhook_secret', sanitize_text_field( wp_unslash( $_POST['npmp_stripe_webhook_secret'] ) ) );
		}

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

		$saved = true;
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

	$site_currency        = npmp_currency();
	$active_subscriptions = (int) apply_filters( 'npmp_active_subscription_count', 0 );

	?>
	<div class="wrap npmp-admin-page">
		<?php npmp_admin_page_title( __( 'Payment Settings', 'nonprofit-manager' ) ); ?>
		<?php if ( ! empty( $saved ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Payment settings saved.', 'nonprofit-manager' ); ?></p></div>
		<?php endif; ?>
		<p><?php echo esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Pick your currency and the ways people can send you %s.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ); ?></p>

		<?php
		foreach ( npmp_payment_setup_gaps( $enabled_gateways, $is_pro ) as $gap ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $gap ) . '</p></div>';
		}
		?>

		<form method="post">
			<?php wp_nonce_field( 'npmp_payment_gateway', 'npmp_payment_gateway_nonce' ); ?>

			<h2><?php esc_html_e( 'Currency', 'nonprofit-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="npmp_currency"><?php echo esc_html( sprintf( /* translators: %s: singular word for a donation, such as Donation or Contribution. */ __( '%s Currency', 'nonprofit-manager' ), npmp_label( 'donation' ) ) ); ?></label></th>
					<td>
						<select id="npmp_currency" name="npmp_currency">
							<?php foreach ( npmp_supported_currencies() as $code => $currency_name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $site_currency, $code ); ?>><?php echo esc_html( $code . ' · ' . $currency_name . ' (' . npmp_currency_symbol( $code ) . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Every donation form, membership dues price, and recurring gift uses this currency. Gifts you\'ve already recorded keep their original currency.', 'nonprofit-manager' ); ?></p>
						<?php if ( $active_subscriptions > 0 ) : ?>
							<p class="description npmp-text-warning">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of active recurring subscriptions. */
										_n(
											'You have %d active recurring subscription. Changing the currency does not touch it: it keeps billing in the currency it started in. Only new gifts and dues use the new currency.',
											'You have %d active recurring subscriptions. Changing the currency does not touch them: they keep billing in the currency they started in. Only new gifts and dues use the new currency.',
											$active_subscriptions,
											'nonprofit-manager'
										),
										$active_subscriptions
									)
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Payment methods', 'nonprofit-manager' ); ?></h2>
			<p><?php esc_html_e( 'Turn on one or more. With more than one on, donors pick a button on the form.', 'nonprofit-manager' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Payment gateways', 'nonprofit-manager' ); ?></th>
					<td>
						<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Payment gateways', 'nonprofit-manager' ); ?></legend>
						<!-- Free Options -->
						<label><input type="checkbox" name="npmp_gateways[]" value="paypal_link" <?php checked( in_array( 'paypal_link', $enabled_gateways, true ) ); ?> class="npmp-gateway-checkbox" data-gateway="paypal_link"> <?php esc_html_e( 'PayPal (Link)', 'nonprofit-manager' ); ?></label><br>

						<label><input type="checkbox" name="npmp_gateways[]" value="venmo_link" <?php checked( in_array( 'venmo_link', $enabled_gateways, true ) ); ?> class="npmp-gateway-checkbox" data-gateway="venmo_link"> <?php esc_html_e( 'Venmo (Link)', 'nonprofit-manager' ); ?></label><br>
						<?php if ( ! npmp_venmo_available( $site_currency ) && in_array( 'venmo_link', $enabled_gateways, true ) ) : ?>
							<p class="description npmp-text-error">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: ISO currency code, e.g. GBP. */
										__( 'Venmo only takes US dollars from US accounts, so the Venmo button is hidden while your currency is %s.', 'nonprofit-manager' ),
										$site_currency
									)
								);
								?>
							</p>
						<?php endif; ?>

						<!-- Pro Options -->
						<label>
							<input type="checkbox" name="npmp_gateways[]" value="paypal_api" <?php checked( in_array( 'paypal_api', $enabled_gateways, true ) ); ?> <?php disabled( ! $is_pro ); ?> class="npmp-gateway-checkbox" data-gateway="paypal_api">
							<?php
							if ( $is_pro ) {
								esc_html_e( 'PayPal API', 'nonprofit-manager' );
							} else {
								esc_html_e( 'PayPal API (Pro)', 'nonprofit-manager' );
							}
							?>
						</label><br>

						<label>
							<input type="checkbox" name="npmp_gateways[]" value="stripe" <?php checked( in_array( 'stripe', $enabled_gateways, true ) ); ?> class="npmp-gateway-checkbox" data-gateway="stripe">
							<?php esc_html_e( 'Stripe (card payments)', 'nonprofit-manager' ); ?>
						</label><br>
						</fieldset>

						<?php if ( ! $is_pro ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to upgrade page */
									wp_kses_post( __( 'Monthly giving and the PayPal API, which records each PayPal gift here, come with <a href="%s" target="_blank" rel="noopener">Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
									esc_url( npmp_get_upgrade_url( 'payment_gateways' ) )
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
				<p><?php esc_html_e( 'The PayPal button opens PayPal in a new tab with the amount filled in, and the donor finishes there. These gifts show in your PayPal account, not in your Donations list here.', 'nonprofit-manager' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="npmp_paypal_email"><?php esc_html_e( 'PayPal Email Address', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="email" id="npmp_paypal_email" name="npmp_paypal_email" value="<?php echo esc_attr( get_option( 'npmp_paypal_email', '' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'The email address on your PayPal account.', 'nonprofit-manager' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Venmo Link Settings -->
			<div class="npmp-gateway-settings npmp-gateway-venmo_link" style="<?php echo ! in_array( 'venmo_link', $enabled_gateways, true ) ? 'display:none;' : ''; ?>">
				<hr>
				<h3><?php esc_html_e( 'Venmo Link Configuration', 'nonprofit-manager' ); ?></h3>
				<p><?php esc_html_e( 'The Venmo button opens the Venmo app or website with the amount filled in, and the donor finishes there. These gifts show in your Venmo account, not in your Donations list here.', 'nonprofit-manager' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="npmp_venmo_handle"><?php esc_html_e( 'Venmo Username', 'nonprofit-manager' ); ?></label></th>
						<td>
							<input type="text" id="npmp_venmo_handle" name="npmp_venmo_handle" value="<?php echo esc_attr( get_option( 'npmp_venmo_handle', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( '@username', 'nonprofit-manager' ); ?>">
							<p class="description"><?php esc_html_e( 'Your Venmo username, with or without the @.', 'nonprofit-manager' ); ?></p>
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
							<th scope="row"><?php esc_html_e( 'Mode', 'nonprofit-manager' ); ?></th>
							<td>
								<?php $paypal_mode = get_option( 'npmp_paypal_mode', 'live' ); ?>
								<fieldset><legend class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: payment processor name, such as PayPal or Stripe. */ __( '%s mode', 'nonprofit-manager' ), 'PayPal' ) ); ?></legend>
								<label><input type="radio" name="npmp_paypal_mode" value="live" <?php checked( $paypal_mode, 'live' ); ?>> <?php esc_html_e( 'Live', 'nonprofit-manager' ); ?></label>
								<label><input type="radio" name="npmp_paypal_mode" value="sandbox" <?php checked( $paypal_mode, 'sandbox' ); ?>> <?php esc_html_e( 'Sandbox (Testing)', 'nonprofit-manager' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Use Sandbox with PayPal\'s test accounts, and Live to take donations.', 'nonprofit-manager' ); ?></p>
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
								<input type="password" id="npmp_paypal_live_secret" name="npmp_paypal_live_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_paypal_live_secret', '' ) ? esc_attr( '••••••••' . __( ' (saved)', 'nonprofit-manager' ) ) : ''; ?>">
								<p class="description"><?php esc_html_e( 'Your PayPal Live REST API Secret Key. Leave blank to keep the saved key.', 'nonprofit-manager' ); ?></p>
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
								<input type="password" id="npmp_paypal_sandbox_secret" name="npmp_paypal_sandbox_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_paypal_sandbox_secret', '' ) ? esc_attr( '••••••••' . __( ' (saved)', 'nonprofit-manager' ) ) : ''; ?>">
								<p class="description"><?php esc_html_e( 'Your PayPal Sandbox REST API Secret Key. Leave blank to keep the saved key.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<?php
					/** Pro's own PayPal API settings render here, inside the same form. */
					do_action( 'npmp_paypal_api_settings_fields' );
					?>

					<h4><?php esc_html_e( 'PayPal Donation Frequencies', 'nonprofit-manager' ); ?></h4>
					<p><?php esc_html_e( 'Pick the schedules donors can choose for PayPal gifts. One-time gifts are always on.', 'nonprofit-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Recurring Donation Options:', 'nonprofit-manager' ); ?></strong></p>
					<label><input type="checkbox" name="npmp_paypal_enable_weekly" value="1" <?php checked( get_option( 'npmp_paypal_enable_weekly', 0 ), 1 ); ?>> <?php esc_html_e( 'Weekly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_monthly" value="1" <?php checked( get_option( 'npmp_paypal_enable_monthly', 0 ), 1 ); ?>> <?php esc_html_e( 'Monthly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_quarterly" value="1" <?php checked( get_option( 'npmp_paypal_enable_quarterly', 0 ), 1 ); ?>> <?php esc_html_e( 'Quarterly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_paypal_enable_annual" value="1" <?php checked( get_option( 'npmp_paypal_enable_annual', 0 ), 1 ); ?>> <?php esc_html_e( 'Annual', 'nonprofit-manager' ); ?></label><br>
					<p class="description"><?php esc_html_e( 'PayPal API supports recurring subscriptions for all frequency types.', 'nonprofit-manager' ); ?></p>
				</div>

			<?php endif; ?>

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
							<th scope="row"><?php esc_html_e( 'Mode', 'nonprofit-manager' ); ?></th>
							<td>
								<?php $stripe_mode = get_option( 'npmp_stripe_mode', 'live' ); ?>
								<fieldset><legend class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: payment processor name, such as PayPal or Stripe. */ __( '%s mode', 'nonprofit-manager' ), 'Stripe' ) ); ?></legend>
								<label><input type="radio" name="npmp_stripe_mode" value="live" <?php checked( $stripe_mode, 'live' ); ?>> <?php esc_html_e( 'Live', 'nonprofit-manager' ); ?></label>
								<label><input type="radio" name="npmp_stripe_mode" value="test" <?php checked( $stripe_mode, 'test' ); ?>> <?php esc_html_e( 'Test', 'nonprofit-manager' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Use Test with your Stripe test keys, and Live to take donations. The form uses the keys for the mode you pick.', 'nonprofit-manager' ); ?></p>
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
								<input type="password" id="npmp_stripe_live_secret_key" name="npmp_stripe_live_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_stripe_live_secret_key', '' ) ? esc_attr( '••••••••' . __( ' (saved)', 'nonprofit-manager' ) ) : 'sk_live_...'; ?>">
								<p class="description"><?php esc_html_e( 'Your Stripe Live Secret Key (starts with sk_live_). Leave blank to keep the saved key.', 'nonprofit-manager' ); ?></p>
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
								<input type="password" id="npmp_stripe_test_secret_key" name="npmp_stripe_test_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_stripe_test_secret_key', '' ) ? esc_attr( '••••••••' . __( ' (saved)', 'nonprofit-manager' ) ) : 'sk_test_...'; ?>">
								<p class="description"><?php esc_html_e( 'Your Stripe Test Secret Key (starts with sk_test_). Leave blank to keep the saved key.', 'nonprofit-manager' ); ?></p>
							</td>
						</tr>
					</table>

					<?php if ( $is_pro ) : ?>
					<h4><?php esc_html_e( 'Webhook Signing Secret', 'nonprofit-manager' ); ?></h4>
					<table class="form-table">
						<tr>
							<th><label for="npmp_stripe_webhook_secret"><?php esc_html_e( 'Signing Secret', 'nonprofit-manager' ); ?></label></th>
							<td>
								<input type="password" id="npmp_stripe_webhook_secret" name="npmp_stripe_webhook_secret" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_option( 'npmp_stripe_webhook_secret', '' ) ? esc_attr( '••••••••' . __( ' (saved)', 'nonprofit-manager' ) ) : 'whsec_...'; ?>">
								<p class="description">
									<?php esc_html_e( 'Required for recurring donations and membership dues. In the Stripe Dashboard, add a webhook endpoint pointing to the URL below, then paste its signing secret here. Leave blank to keep the saved secret.', 'nonprofit-manager' ); ?><br>
									<code><?php echo esc_html( admin_url( 'admin-ajax.php?action=npmp_stripe_recurring_webhook' ) ); ?></code>
								</p>
							</td>
						</tr>
					</table>

					<h4><?php esc_html_e( 'Stripe Donation Frequencies', 'nonprofit-manager' ); ?></h4>
					<p><?php esc_html_e( 'Pick the schedules donors can choose for Stripe gifts. One-time gifts are always on.', 'nonprofit-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Recurring Donation Options:', 'nonprofit-manager' ); ?></strong></p>
					<label><input type="checkbox" name="npmp_stripe_enable_weekly" value="1" <?php checked( get_option( 'npmp_stripe_enable_weekly', 0 ), 1 ); ?>> <?php esc_html_e( 'Weekly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_monthly" value="1" <?php checked( get_option( 'npmp_stripe_enable_monthly', 0 ), 1 ); ?>> <?php esc_html_e( 'Monthly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_quarterly" value="1" <?php checked( get_option( 'npmp_stripe_enable_quarterly', 0 ), 1 ); ?>> <?php esc_html_e( 'Quarterly', 'nonprofit-manager' ); ?></label><br>
					<label><input type="checkbox" name="npmp_stripe_enable_annual" value="1" <?php checked( get_option( 'npmp_stripe_enable_annual', 0 ), 1 ); ?>> <?php esc_html_e( 'Annual', 'nonprofit-manager' ); ?></label><br>
					<p class="description"><?php esc_html_e( 'Stripe supports recurring subscriptions for all frequency types.', 'nonprofit-manager' ); ?></p>
					<?php else : ?>
					<h4><?php esc_html_e( 'Stripe Donation Frequencies', 'nonprofit-manager' ); ?></h4>
					<p><?php esc_html_e( 'One-time gifts are always on and need no webhook.', 'nonprofit-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Recurring Donation Options:', 'nonprofit-manager' ); ?></strong></p>
					<label style="opacity:.6;"><input type="checkbox" disabled> <?php esc_html_e( 'Weekly', 'nonprofit-manager' ); ?></label><br>
					<label style="opacity:.6;"><input type="checkbox" disabled> <?php esc_html_e( 'Monthly', 'nonprofit-manager' ); ?></label><br>
					<label style="opacity:.6;"><input type="checkbox" disabled> <?php esc_html_e( 'Quarterly', 'nonprofit-manager' ); ?></label><br>
					<label style="opacity:.6;"><input type="checkbox" disabled> <?php esc_html_e( 'Annual', 'nonprofit-manager' ); ?></label><br>
					<p class="description">
						<?php
						printf(
							/* translators: %s: URL to upgrade page */
							wp_kses_post( __( 'Recurring gifts and membership dues billing through Stripe come with <a href="%s" target="_blank" rel="noopener">Nonprofit Manager Pro</a>.', 'nonprofit-manager' ) ),
							esc_url( npmp_get_upgrade_url( 'stripe_recurring' ) )
						);
						?>
					</p>
					<?php endif; ?>
				</div>

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

/**
 * What an enabled payment method still needs before donors can use it.
 *
 * A method switched on with no account details showed donors "not
 * configured" on the live form, and nothing on this screen said so.
 *
 * @param string[] $enabled Enabled gateway slugs.
 * @param bool     $is_pro  Whether Pro is active.
 * @return string[] One message per gap.
 */
function npmp_payment_setup_gaps( $enabled, $is_pro ) {
	$enabled = is_array( $enabled ) ? $enabled : array();
	$gaps    = array();

	if ( in_array( 'paypal_link', $enabled, true ) && '' === trim( (string) get_option( 'npmp_paypal_email', '' ) ) ) {
		$gaps[] = __( 'PayPal is on, but you haven\'t saved your PayPal email address, so the PayPal button won\'t show. Add it below.', 'nonprofit-manager' );
	}
	if ( in_array( 'venmo_link', $enabled, true ) && '' === trim( (string) get_option( 'npmp_venmo_handle', '' ) ) ) {
		$gaps[] = __( 'Venmo is on, but you haven\'t saved your Venmo username, so the Venmo button won\'t show. Add it below.', 'nonprofit-manager' );
	}
	if ( in_array( 'stripe', $enabled, true ) ) {
		$test = 'test' === get_option( 'npmp_stripe_mode', 'live' );
		$pk   = get_option( $test ? 'npmp_stripe_test_publishable_key' : 'npmp_stripe_live_publishable_key', '' );
		$sk   = get_option( $test ? 'npmp_stripe_test_secret_key' : 'npmp_stripe_live_secret_key', '' );
		if ( '' === trim( (string) $pk ) || '' === trim( (string) $sk ) ) {
			$gaps[] = $test
				? __( 'Stripe is on in Test mode, but you haven\'t saved both test keys, so donors can\'t give by card yet. Add your publishable and secret test keys below.', 'nonprofit-manager' )
				: __( 'Stripe is on in Live mode, but you haven\'t saved both live keys, so donors can\'t give by card yet. Add your publishable and secret live keys below.', 'nonprofit-manager' );
		}
	}
	if ( $is_pro && in_array( 'paypal_api', $enabled, true ) ) {
		$sandbox = 'sandbox' === get_option( 'npmp_paypal_mode', 'live' );
		$client  = get_option( $sandbox ? 'npmp_paypal_sandbox_client_id' : 'npmp_paypal_live_client_id', '' );
		if ( '' === trim( (string) $client ) ) {
			$gaps[] = __( 'The PayPal API is on, but you haven\'t saved a client ID for the mode you picked, so its buttons won\'t show. Add it below.', 'nonprofit-manager' );
		}
	}

	return $gaps;
}

/* ==============================================================
 * Donation Form Shortcode
 * ============================================================= */
add_shortcode( 'npmp_donation_form', 'npmp_render_donation_form' );

function npmp_render_donation_form() {
	// A donor coming back from Stripe lands on this same page with a status
	// flag. Without this banner, people who just paid real money got no
	// acknowledgment of any kind.
	$status_banner = '';
	if ( isset( $_GET['npmp_donation'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag, fixed strings only.
		$donation_status = sanitize_key( wp_unslash( $_GET['npmp_donation'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'success' === $donation_status ) {
			$status_banner = '<div class="npmp-form-banner npmp-status-success" role="status"><p>' . esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'Thank you! We received your %s.', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ) . '</p></div>';
		} elseif ( 'cancelled' === $donation_status ) {
			$status_banner = '<div class="npmp-form-banner npmp-status-error" role="status"><p>' . esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'You cancelled your %s. We didn\'t charge you.', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ) . '</p></div>';
		}
		if ( $status_banner && wp_style_is( 'npmp-forms', 'registered' ) ) {
			wp_enqueue_style( 'npmp-forms' );
		}
	}

	// Get enabled gateways (new checkbox-based system)
	$enabled_gateways = get_option( 'npmp_enabled_payment_gateways', array() );

	// Backward compatibility: check old single gateway option
	if ( empty( $enabled_gateways ) ) {
		$old_gateway = get_option( 'npmp_payment_gateway', '' );
		if ( ! empty( $old_gateway ) && 'none' !== $old_gateway ) {
			$enabled_gateways = array( $old_gateway );
		}
	}

	// Venmo only moves US dollars, so it drops out on any other currency.
	$enabled_gateways = npmp_filter_gateways_for_currency( $enabled_gateways );

	// If no gateways are enabled, show message
	if ( empty( $enabled_gateways ) ) {
		return $status_banner . '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html( sprintf( /* translators: %s: lowercase plural word for donations, such as donations or contributions. */ __( 'Online %s are not set up yet. Please contact the site administrator.', 'nonprofit-manager' ), npmp_label( 'donations_lc' ) ) ) . '</p></div>';
	}

	// If only one gateway is enabled, render it directly
	if ( count( $enabled_gateways ) === 1 && function_exists( 'npmp_render_gateway_donation_form' ) ) {
		return $status_banner . npmp_render_gateway_donation_form( $enabled_gateways[0] ) . npmp_donation_form_attribution();
	}

	// If multiple gateways are enabled, render a tabbed interface
	if ( function_exists( 'npmp_render_multi_gateway_donation_form' ) ) {
		return $status_banner . npmp_render_multi_gateway_donation_form( $enabled_gateways ) . npmp_donation_form_attribution();
	}

	return $status_banner . '<div class="npmp-donation-form npmp-donation-form--inactive"><p>' . esc_html( sprintf( /* translators: %s: lowercase singular word for a donation, such as donation or contribution. */ __( 'The %s form is loading. Please refresh the page.', 'nonprofit-manager' ), npmp_label( 'donation_lc' ) ) ) . '</p></div>';
}

/**
 * "Powered by Nonprofit Manager" attribution appended below the donation form.
 * Empty unless the site owner opted in (npmp-powered-by.php).
 *
 * @return string
 */
function npmp_donation_form_attribution() {
	return function_exists( 'npmp_powered_by_html' ) ? npmp_powered_by_html( 'form' ) : '';
}

/* ==============================================================
 * Inject donation form on selected page
 * ============================================================= */
add_filter( 'the_content', function ( $content ) {
	if ( is_page() && get_the_ID() === absint( get_option( 'npmp_donation_page_id' ) ) ) {
		// The page may already carry the form as a shortcode or block. A second
		// copy duplicated element IDs, and its script bound to the first form.
		$raw = (string) get_post_field( 'post_content', get_the_ID() );
		if ( has_shortcode( $raw, 'npmp_donation_form' ) || false !== strpos( $raw, 'wp:nonprofit-manager/donation' ) || false !== strpos( $content, 'npmp-donation-form' ) ) {
			return $content;
		}
		return $content . do_shortcode( '[npmp_donation_form]' );
	}
	return $content;
} );
