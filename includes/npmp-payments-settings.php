<?php
// includes/npmp-payments-settings.php
defined( 'ABSPATH' ) || exit;

/* --------------------------------------------------------------
 * 1. Donations Summary (top‑level “Donations” page)
 * --------------------------------------------------------------*/
function npmp_render_donations_dashboard() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nonprofit-manager' ) );
	}

	$dm = NPMP_Donation_Manager::get_instance();

	/* ---------- filters ---------- */
	$current_year  = isset( $_GET['year'] )  ? intval( $_GET['year'] )  : date( 'Y' );
	$current_month = isset( $_GET['month'] ) ? intval( $_GET['month'] ) : 0;

	$years = $dm->years_with_donations();

	$summary = $dm->summary( $current_year, $current_month ?: null ); // [ [ 'period'=>'Jan 2025', 'count'=>5, 'total'=>123.45 ], … ]

	echo '<div class="wrap"><h1>' . esc_html__( 'Donations Summary', 'nonprofit-manager' ) . '</h1>';

	/* ---------- filter form ---------- */
	echo '<form method="get" style="margin-bottom:1.5em;">';
	echo '<input type="hidden" name="page" value="npmp_donations_group">';
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
		echo '<option value="' . $m . '"' . selected( $m, $current_month, false ) . '>' . esc_html( date( 'F', mktime( 0, 0, 0, $m, 1 ) ) ) . '</option>';
	}
	echo '</select></label> ';
	submit_button( __( 'Filter', 'nonprofit-manager' ), 'secondary', '', false );
	echo '</form>';

	/* ---------- table ---------- */
	echo '<h2>' . esc_html__( 'Historic Donations', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="widefat fixed striped" style="max-width:500px">';
	echo '<thead><tr><th>' . esc_html__( 'Period', 'nonprofit-manager' ) . '</th><th style="text-align:right">' . esc_html__( '#', 'nonprofit-manager' ) . '</th><th style="text-align:right">' . esc_html__( 'Total (USD)', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';

	foreach ( $summary as $row ) {
		echo '<tr><td>' . esc_html( $row['period'] ) . '</td><td style="text-align:right">' . esc_html( $row['count'] ) . '</td><td style="text-align:right">$' . number_format_i18n( $row['total'], 2 ) . '</td></tr>';
	}
	if ( ! $summary ) {
		echo '<tr><td colspan="3">' . esc_html__( 'No donations found for this period.', 'nonprofit-manager' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* --------------------------------------------------------------
 * 2. Conditionally load PayPal logic
 * --------------------------------------------------------------*/
if ( get_option( 'npmp_enable_paypal' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'payments/npmp-paypal.php';
}

/* --------------------------------------------------------------
 * 3. Payment‑Settings page (submenu “Payment Settings”)
 * --------------------------------------------------------------*/
function npmp_render_payment_settings_page() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	/* ---------- Save ---------- */
	if (
		'POST' === $_SERVER['REQUEST_METHOD'] &&
		isset( $_POST['npmp_payment_settings_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_payment_settings_nonce'] ) ), 'npmp_payment_settings' )
	) {
		$txt = array(
			'npmp_donation_form_title',
			'npmp_donation_amount_label',
			'npmp_donation_email_label',
			'npmp_donation_button_label',
		);
		foreach ( $txt as $k ) {
			update_option( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		update_option( 'npmp_donation_form_intro', sanitize_textarea_field( wp_unslash( $_POST['npmp_donation_form_intro'] ?? '' ) ) );
		update_option( 'npmp_donation_page_id', intval( $_POST['npmp_donation_page_id'] ?? 0 ) );
		update_option( 'npmp_enable_one_time',  ! empty( $_POST['npmp_enable_one_time'] ) ? 1 : 0 );
		update_option( 'npmp_enable_monthly',   ! empty( $_POST['npmp_enable_monthly'] )  ? 1 : 0 );
		update_option( 'npmp_enable_annual',    ! empty( $_POST['npmp_enable_annual'] )   ? 1 : 0 );

		$gateway = sanitize_text_field( wp_unslash( $_POST['npmp_gateway'] ?? '' ) );
		update_option( 'npmp_enable_paypal', $gateway === 'paypal' ? 1 : 0 );

		if ( 'paypal' === $gateway ) {
			update_option( 'npmp_paypal_method',   sanitize_text_field( wp_unslash( $_POST['npmp_paypal_method'] ?? 'sdk' ) ) );
			update_option( 'npmp_paypal_email',    sanitize_email( wp_unslash( $_POST['npmp_paypal_email'] ?? '' ) ) );
			update_option( 'npmp_paypal_client_id',sanitize_text_field( wp_unslash( $_POST['npmp_paypal_client_id'] ?? '' ) ) );
			update_option( 'npmp_paypal_secret',   sanitize_text_field( wp_unslash( $_POST['npmp_paypal_secret'] ?? '' ) ) );
			update_option( 'npmp_paypal_mode',     sanitize_text_field( wp_unslash( $_POST['npmp_paypal_mode'] ?? 'live' ) ) );
			update_option( 'npmp_paypal_minimum',  floatval( $_POST['npmp_paypal_minimum'] ?? 1 ) );
		}
		echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	/* ---------- Current opts ---------- */
	$paypal_enabled  = get_option( 'npmp_enable_paypal', 0 );
	$one_time_enabled= get_option( 'npmp_enable_one_time', 1 );
	$monthly_enabled = get_option( 'npmp_enable_monthly', 0 );
	$annual_enabled  = get_option( 'npmp_enable_annual', 0 );

	/* ---------- Mark‑up ---------- */
	echo '<div class="wrap"><h1>' . esc_html__( 'Payment Gateway Settings', 'nonprofit-manager' ) . '</h1>';
	echo '<p>' . esc_html__( 'Customize your public donation form and configure gateways.', 'nonprofit-manager' ) . '</p>';

	echo '<form method="post">';
	wp_nonce_field( 'npmp_payment_settings', 'npmp_payment_settings_nonce' );

	/* Donation page */
	echo '<h2>' . esc_html__( 'Donation Page', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Choose a default page (form will be auto‑inserted) or use the shortcode', 'nonprofit-manager' ) . ' <code>[npmp_donation_form]</code>.</p>';
	wp_dropdown_pages(
		array(
			'name'             => 'npmp_donation_page_id',
			'selected'         => absint( get_option( 'npmp_donation_page_id' ) ),
			'show_option_none' => __( 'None (no default donation page)', 'nonprofit-manager' ),
		)
	);

	/* Gateway */
	echo '<h2>' . esc_html__( 'Choose Payment Gateway', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Only one gateway can be enabled at a time.', 'nonprofit-manager' ) . '</p>';
	echo '<label><input type="radio" name="npmp_gateway" value="" ' . checked( $paypal_enabled, 0, false ) . '> ' . esc_html__( 'None', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="radio" name="npmp_gateway" value="paypal" ' . checked( $paypal_enabled, 1, false ) . ' id="npmp-gw-paypal"> PayPal</label><br>';

	/* PayPal settings (toggled by JS) */
	echo '<div id="npmp-paypal-settings" style="' . ( $paypal_enabled ? '' : 'display:none' ) . ';margin-top:15px;">';
	do_action( 'npmp_render_paypal_settings_section' );
	echo '</div>';

	/* Form labels */
	echo '<h2>' . esc_html__( 'Customize Donation Form', 'nonprofit-manager' ) . '</h2>';
	echo '<table class="form-table">';
	$fields = array(
		'npmp_donation_form_title'    => __( 'Form Title', 'nonprofit-manager' ),
		'npmp_donation_form_intro'    => __( 'Intro Text', 'nonprofit-manager' ),
		'npmp_donation_amount_label'  => __( 'Amount Label', 'nonprofit-manager' ),
		'npmp_donation_email_label'   => __( 'Email Label', 'nonprofit-manager' ),
		'npmp_donation_button_label'  => __( 'Submit Button Label', 'nonprofit-manager' ),
	);
	foreach ( $fields as $key => $label ) {
		if ( 'npmp_donation_form_intro' === $key ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><textarea name="' . esc_attr( $key ) . '" rows="3" class="large-text">' . esc_textarea( get_option( $key ) ) . '</textarea></td></tr>';
		} else {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( get_option( $key ) ) . '" class="regular-text"></td></tr>';
		}
	}
	echo '</table>';

	/* Frequencies */
	echo '<h2>' . esc_html__( 'Donation Frequencies', 'nonprofit-manager' ) . '</h2>';
	echo '<label><input type="checkbox" name="npmp_enable_one_time" value="1" ' . checked( $one_time_enabled, 1, false ) . '> ' . esc_html__( 'One‑Time', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="checkbox" name="npmp_enable_monthly" value="1" ' . checked( $monthly_enabled, 1, false ) . '> ' . esc_html__( 'Monthly', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="checkbox" name="npmp_enable_annual" value="1" ' . checked( $annual_enabled, 1, false ) . '> ' . esc_html__( 'Annual', 'nonprofit-manager' ) . '</label><br>';

	submit_button( __( 'Save Payment Settings', 'nonprofit-manager' ) );
	echo '</form></div>';

	/* ---------- UI toggles ---------- */
	echo '<script>
	(function(d){
		const paypalRadio      = d.getElementById("npmp-gw-paypal");
		const paypalSettings   = d.getElementById("npmp-paypal-settings");
		const methodRows       = paypalSettings.querySelectorAll("[data-method]");
		function refresh(){
			paypalSettings.style.display = paypalRadio.checked ? "" : "none";
			methodRows.forEach(function(tr){
				const wanted = paypalSettings.querySelector("input[name=npmp_paypal_method]:checked").value;
				tr.style.display = tr.getAttribute("data-method") === wanted ? "" : "none";
			});
		}
		d.addEventListener("change",function(e){ if(e.target.name==="npmp_gateway" || e.target.name==="npmp_paypal_method"){ refresh(); }});
		refresh();
	})(document);
	</script>';
}

/* Auto‑inject donation form on selected page */
add_filter( 'the_content', function ( $content ) {
	if ( is_page() && get_the_ID() == get_option( 'npmp_donation_page_id' ) ) {
		return $content . do_shortcode( '[npmp_donation_form]' );
	}
	return $content;
} );
