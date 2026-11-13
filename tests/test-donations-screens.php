<?php
/**
 * Donations dashboard, Donation Settings, and Payment Settings.
 *
 * - An empty Donations table names the next step: set up a payment method
 *   when none is on, or pick a page for the form when one is. It used to
 *   stop at "No donations found."
 * - The thank-you email defaults are translated, and Donation Settings and
 *   the sender read the same pair.
 * - The screens use the site's date format, a labelled page picker, and
 *   fieldsets for the gateway and mode choices.
 *
 * Run: php tests/test-donations-screens.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . $path;
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html__( $s ) {
	return esc_html( $s );
}
function npmp_label( $key ) {
	return array( 'donation_lc' => 'contribution' )[ $key ] ?? $key;
}

/**
 * Pull one function out of a plugin file without loading WordPress.
 */
function t_extract( $file, $fn ) {
	$src   = file_get_contents( __DIR__ . '/../' . $file );
	$start = strpos( $src, "function $fn(" );
	if ( false === $start ) {
		echo "  FAIL  $fn not found in $file\n";
		exit( 1 );
	}
	$end = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness.
}

t_extract( 'includes/npmp-payments-settings.php', 'npmp_donations_empty_next_step' );
t_extract( 'includes/payments/npmp-payment-gateways.php', 'npmp_thank_you_defaults' );

echo "Empty Donations table\n";
$GLOBALS['t_options'] = array();
check( 'no gateway: link to Payment Settings', true, false !== strpos( npmp_donations_empty_next_step(), 'page=npmp_payment_settings' ) );
$GLOBALS['t_options'] = array( 'npmp_payment_gateway' => 'none' );
check( 'legacy "none": still Payment Settings', true, false !== strpos( npmp_donations_empty_next_step(), 'page=npmp_payment_settings' ) );
$GLOBALS['t_options'] = array( 'npmp_payment_gateway' => 'stripe' );
check( 'legacy single gateway counts as set up', true, false !== strpos( npmp_donations_empty_next_step(), 'page=npmp_donation_settings' ) );
$GLOBALS['t_options'] = array( 'npmp_enabled_payment_gateways' => array( 'stripe' ) );
$html = npmp_donations_empty_next_step();
check( 'gateway on: link to the form page setting', true, false !== strpos( $html, 'page=npmp_donation_settings' ) );
check( 'uses the site\'s word for a donation', true, false !== strpos( $html, 'contribution form' ) );

echo "Thank-you defaults\n";
$d = npmp_thank_you_defaults();
check( 'subject unchanged for sites that never saved one', 'Thank You for Your Donation!', $d['subject'] );
check( 'message keeps its placeholders', true, false !== strpos( $d['message'], '{donation_amount}' ) && false !== strpos( $d['message'], '{site_name}' ) );

echo "Markup\n";
$src = file_get_contents( __DIR__ . '/../includes/npmp-payments-settings.php' );
$gw  = file_get_contents( __DIR__ . '/../includes/payments/npmp-payment-gateways.php' );
check( 'no hard-coded US date format', false, strpos( $src, "'M j, Y g:i A'" ) );
check( 'one default thank-you subject in the code', 1, substr_count( $src . $gw, "'Thank You for Your Donation!'" ) );
check( 'page picker has an id and a label', true, false !== strpos( $src, "'id'               => 'npmp_donation_page'" ) && false !== strpos( $src, '<label for="npmp_donation_page">' ) );
check( 'dashboard h1 says Dashboard', true, false !== strpos( $src, "__( '%s Dashboard', 'nonprofit-manager' )" ) && false === strpos( $src, "__( '%s Summary', 'nonprofit-manager' )" ) );
check( 'no legacy "updated" notices', false, strpos( $src, 'class="updated"' ) );
check( 'gateway checkboxes in a fieldset', true, false !== strpos( $src, '<legend class="screen-reader-text"><?php esc_html_e( \'Payment gateways\'' ) );
foreach ( array( 'npmp_donation_form_title', 'npmp_donation_form_intro', 'npmp_donation_amount_label', 'npmp_donation_email_label', 'npmp_donation_button_label', 'npmp_thank_you_subject', 'npmp_thank_you_message' ) as $field ) {
	check( "$field has a label", true, false !== strpos( $src, '<label for="' . $field . '">' ) && false !== strpos( $src, 'id="' . $field . '"' ) );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
