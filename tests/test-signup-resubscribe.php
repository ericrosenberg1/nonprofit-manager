<?php
/**
 * Only the owner of an address puts it back on the list
 * (includes/npmp-membership-forms.php).
 *
 * With double opt-in off, a stranger who typed an Inactive contact's address
 * into [npmp_email_signup] made them Active at once. Now an Unsubscribed or
 * Inactive contact gets the "subscribe again" email whatever the setting,
 * the visitor sees the usual answer, and each address gets at most 3
 * confirmation emails an hour. The flow runs on a real WordPress in
 * tests/rig/signup-resubscribe.php. This guards the wiring in the pre-push
 * gate.
 *
 * Run: php tests/test-signup-resubscribe.php
 */

require_once __DIR__ . '/bootstrap.php';

$forms = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-membership-forms.php' );

function npmp_sr_section( $src, $start, $end ) {
	$a = strpos( $src, $start );
	if ( false === $a ) {
		return '';
	}
	$b = strpos( $src, $end, $a + strlen( $start ) );
	return substr( $src, $a, false === $b ? 4000 : $b - $a );
}

echo "Who left the list\n";
eval( '?>' . '<?php ' . npmp_sr_section( $forms, 'function npmp_signup_status_left_list', '/**' ) );
check( 'Unsubscribed', true, npmp_signup_status_left_list( 'unsubscribed' ) );
check( 'Inactive', true, npmp_signup_status_left_list( 'inactive' ) );
foreach ( array( 'subscribed', 'pending', 'donor', 'volunteer', 'partner', '' ) as $s ) {
	check( "Not: '$s'", false, npmp_signup_status_left_list( $s ) );
}

echo "Per-address cap\n";
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
$GLOBALS['sr_calls'] = array();
if ( ! function_exists( 'npmp_rate_limit_allows' ) ) {
	function npmp_rate_limit_allows( $bucket, $max, $window, $key = '' ) {
		$GLOBALS['sr_calls'][] = array( $bucket, $max, $window, $key );
		return true;
	}
}
eval( '?>' . '<?php ' . npmp_sr_section( $forms, 'function npmp_signup_address_mail_allows', '/**' ) );
npmp_signup_address_mail_allows( ' Ina@Example.org ' );
check( 'Counts on its own bucket, 3 an hour', array( 'signup_address', 3, 3600, 'address:ina@example.org' ), $GLOBALS['sr_calls'][0] ?? null );

echo "Signup wiring\n";
$signup = npmp_sr_section( $forms, "'email_signup' === \$action", '/* ---------------- UNSUBSCRIBE' );
$left   = strpos( $signup, 'if ( $existing && npmp_signup_status_left_list( $status ) ) {' );
check( 'Contacts who left the list are caught', true, false !== $left );
check( 'No other unsubscribed-only check remains', 0, substr_count( $signup, "'unsubscribed' === \$status" ) );
check( 'Before double opt-in and the instant paths', true, $left < strpos( $signup, 'if ( npmp_double_optin_enabled() ) {' ) && $left < strpos( $signup, "'status'           => 'subscribed'" ) );
$branch = substr( $signup, $left, strpos( $signup, 'exit;', $left ) - $left );
check( 'They get the resubscribe email', 1, substr_count( $branch, 'npmp_send_resubscribe_confirmation( $email, $key );' ) );
check( 'Never a status change on submit', 0, substr_count( $branch, 'update_member' ) );
check( 'The visitor gets the usual answer', 1, substr_count( $branch, "npmp_membership_add_banner_arg( \$redirect, 'npmp_signup', \$answer )" ) );
check( 'Every confirmation send is capped per address', 3, substr_count( $signup, 'if ( npmp_signup_address_mail_allows( $email ) ) {' ) );
check( 'The visitor and site caps still run first', true, strpos( $signup, "npmp_rate_limit_allows( 'signup'" ) < $left );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
