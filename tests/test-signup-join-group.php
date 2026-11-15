<?php
/**
 * An existing contact joins groups through the signup form
 * (includes/npmp-membership-forms.php).
 *
 * Pete at U3A re-used [npmp_email_signup groups="..." choose="yes"] for an
 * existing subscriber and saw no new groups. With double opt-in on, nothing
 * changed until a "please confirm your subscription" email was clicked, and
 * it read as a mistake to someone already subscribed. With it off, the
 * groups were added at once, so anyone could put a stranger in a group by
 * typing their address. An unsubscribed contact lost the groups altogether.
 *
 * Now a contact we already have (other than Pending) gets "Confirm you want
 * to join ..." and only the button behind its link adds the groups, the
 * resubscribe link carries them for an unsubscribed contact, and every
 * address gets the same banner. The flow runs on a real WordPress in
 * tests/rig/signup-join-group.php. This guards the wiring in the pre-push
 * gate.
 *
 * Run: php tests/test-signup-join-group.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = null ) {
		return $text;
	}
}

$root  = dirname( __DIR__ );
$forms = file_get_contents( $root . '/includes/npmp-membership-forms.php' );

function npmp_sjg_section( $src, $start, $end ) {
	$a = strpos( $src, $start );
	if ( false === $a ) {
		return '';
	}
	$b = strpos( $src, $end, $a + strlen( $start ) );
	return substr( $src, $a, false === $b ? 4000 : $b - $a );
}

echo "Names as a sentence\n";
eval( '?>' . '<?php ' . npmp_sjg_section( $forms, 'function npmp_signup_names_sentence', '/**' ) );
check( 'None', '', npmp_signup_names_sentence( array() ) );
check( 'One', 'Art', npmp_signup_names_sentence( array( 'Art' ) ) );
check( 'Two', 'Art and History', npmp_signup_names_sentence( array( 'Art', 'History' ) ) );
check( 'Three, with the Oxford comma', 'Art, History, and Walking', npmp_signup_names_sentence( array( 'Art', 'History', 'Walking' ) ) );

echo "Signup\n";
$signup = npmp_sjg_section( $forms, "'email_signup' === \$action", '/* ---------------- UNSUBSCRIBE' );
$lookup = strpos( $signup, '$member_manager->get_member_by_email( $email )' );
check( 'Captcha runs before the address is looked up', true, false !== $lookup && strpos( $signup, 'npmp_captcha_verify( \'email_signup\' )' ) < $lookup );
check( 'Rate limit runs before the address is looked up', true, strpos( $signup, "npmp_rate_limit_allows( 'signup'" ) < $lookup );
check( 'One answer for every address', 1, substr_count( $signup, "\$answer = npmp_double_optin_enabled() ? 'check_email' : 'success';" ) );
check( 'Unsubscribed and join both redirect with that answer', 2, substr_count( $signup, "npmp_membership_add_banner_arg( \$redirect, 'npmp_signup', \$answer )" ) );
$join_at = strpos( $signup, "'pending' !== \$status && npmp_signup_join_names(" );
check( 'Existing contacts asking for groups are caught', true, false !== $join_at );
check( 'Before double opt-in and before the instant paths', true, $join_at < strpos( $signup, 'if ( npmp_double_optin_enabled() ) {' ) && $join_at < strpos( $signup, "'status'           => 'subscribed'" ) );
check( 'They get the join email when subscribed', 1, substr_count( $signup, "'subscribed' === \$status ? 'join' : 'signup'" ) );
check( 'Unsubscribed contacts save the form for the resubscribe link', 1, substr_count( $signup, "npmp_signup_save_pending( (int) \$existing->id, \$name, \$posted, \$atts_token, false, 'resubscribe' )" ) );

echo "Join email\n";
$send = npmp_sjg_section( $forms, 'function npmp_send_signup_confirmation', 'function npmp_handle_confirm_signup' );
check( 'Subject names the groups', 1, substr_count( $send, "__( 'Confirm you want to join %s', 'nonprofit-manager' )" ) );
check( 'Names come from the filter', 1, substr_count( $send, "'join' === \$kind ? npmp_signup_join_names(" ) );
check( 'Mail goes through npmp_send_mail', 1, substr_count( $send, 'npmp_send_mail( $email, $subject, $body );' ) );
$pending = npmp_sjg_section( $forms, 'function npmp_signup_save_pending', 'function npmp_signup_join_names' );
check( 'A fresh single-use key each time', 1, substr_count( $pending, 'wp_generate_password( 32, false )' ) );
check( 'Only known kinds are saved', 1, substr_count( $pending, "in_array( \$kind, array( 'join', 'resubscribe' ), true ) ? \$kind : 'signup'" ) );

echo "Confirm link\n";
$confirm = npmp_sjg_section( $forms, 'function npmp_handle_confirm_signup', 'function npmp_render_email_link_confirm_page' );
check( 'A resubscribe key never works here', 1, substr_count( $confirm, "'resubscribe' !== ( \$pending['kind'] ?? '' ) && hash_equals( (string) ( \$pending['key'] ?? '' ), \$token )" ) );
check( 'GET only shows a button', true, strpos( $confirm, "'POST' !== \$method" ) < strpos( $confirm, "do_action( 'npmp_after_email_signup'" ) );
check( 'A join keeps the contact\'s status', 1, substr_count( $confirm, "\$update = \$join ? array() : array( 'status' => 'subscribed' );" ) );
check( 'The key is deleted before listeners run', true, strpos( $confirm, "delete_post_meta( \$contact_id, '_npmp_signup_confirm' );" ) < strpos( $confirm, "do_action( 'npmp_after_email_signup'" ) );

echo "Resubscribe link\n";
$resub = npmp_sjg_section( $forms, 'function npmp_handle_confirm_resubscribe', 'Double opt-in: confirm a new signup' );
check( 'The signed token is still checked first', true, strpos( $resub, 'npmp_generate_resubscribe_token( $email ), $token' ) < strpos( $resub, "'POST' !== \$method" ) );
check( 'The join key goes to the button', 1, substr_count( $resub, "'' !== \$join ? array( 'join' => \$join ) : array()" ) );
check( 'The join key is checked with hash_equals and its kind', 1, substr_count( $resub, "'resubscribe' === ( \$pending['kind'] ?? '' ) && hash_equals( (string) ( \$pending['key'] ?? '' ), \$join )" ) );
check( 'The key is deleted before listeners run', true, strpos( $resub, "delete_post_meta( (int) \$existing->id, '_npmp_signup_confirm' );" ) < strpos( $resub, "do_action( 'npmp_after_email_signup'" ) );

echo "Copy\n";
$copy = array(
	"Confirm you want to join %s",
	"We got a request to add you to %1\\\$s at %2\\\$s.",
	"If you didn't ask for this, ignore this email and nothing will change.",
	'Confirm your groups',
	'Press the button to join %s.',
	"Thanks for confirming. You\\'re in your new groups.",
	'Thanks! Check your inbox for an email from us and click the link in it to confirm.',
);
foreach ( $copy as $line ) {
	check( 'Present: ' . substr( $line, 0, 40 ), true, false !== strpos( $forms, $line ) );
	check( 'No em dash or semicolon: ' . substr( $line, 0, 40 ), 0, preg_match( '/\x{2014}|;/u', $line ) );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
