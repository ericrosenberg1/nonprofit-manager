<?php
/**
 * Double opt-in on the public signup form (includes/npmp-membership-forms.php).
 *
 * Until 2026.11.3 a signup was subscribed the moment the form was sent, so
 * anyone could put a stranger's address on a list (wordpress.org thread "No
 * confirmation email when users subscribe"). Now a new address waits as
 * Pending until its owner presses the button behind the emailed link. The
 * flow itself is checked on a real WordPress by tests/rig/double-optin.php.
 * This guards the wiring in the pre-push gate.
 *
 * Run: php tests/test-double-optin.php
 */

require_once __DIR__ . '/bootstrap.php';

$root  = dirname( __DIR__ );
$forms = file_get_contents( $root . '/includes/npmp-membership-forms.php' );
$prefs = file_get_contents( $root . '/includes/npmp-subscription-preferences.php' );

function section( $src, $start, $end ) {
	$a = strpos( $src, $start );
	if ( false === $a ) {
		return '';
	}
	$b = strpos( $src, $end, $a + strlen( $start ) );
	return substr( $src, $a, false === $b ? 4000 : $b - $a );
}

echo "Setting\n";
check( 'On by default, so existing sites get it too', 1, preg_match( "/'double_optin'\s*=>\s*1,/", section( $forms, 'function npmp_membership_form_default_settings', 'function npmp_double_optin_enabled' ) ) );
check( 'Unticked box saves 0', 1, substr_count( $forms, "\$settings['double_optin'] = empty( \$_POST['double_optin'] ) ? 0 : 1;" ) );
check( 'Checkbox on the settings screen', 1, substr_count( $forms, 'name="double_optin" value="1"' ) );

echo "Signup\n";
$signup = section( $forms, "'email_signup' === \$action", '/* ---------------- UNSUBSCRIBE' );
check( 'Double opt-in branch runs before the old subscribe paths', true, strpos( $signup, 'npmp_double_optin_enabled()' ) < strpos( $signup, "'status'           => 'subscribed'" ) );
check( 'New address is saved as pending', 1, substr_count( $signup, "'status'           => 'pending'" ) );
check( 'Unsubscribed contacts keep the resubscribe path', true, false !== strpos( $signup, "'unsubscribed' !== ( \$existing->status ?? '' ) ) && npmp_double_optin_enabled()" ) );
check( 'Visitor is told to check their inbox', 1, substr_count( $signup, "'npmp_signup', 'check_email'" ) );

echo "Confirm link\n";
$confirm = section( $forms, 'function npmp_handle_confirm_signup', 'function npmp_render_email_link_confirm_page' );
check( 'Key checked with hash_equals', 1, substr_count( $confirm, 'hash_equals( (string) ( $pending[\'key\'] ?? \'\' ), $token )' ) );
check( 'GET only shows a button', true, strpos( $confirm, "'POST' !== \$method" ) < strpos( $confirm, "'status' => 'subscribed'" ) );
check( 'Key is single use', 1, substr_count( $confirm, "delete_post_meta( \$contact_id, '_npmp_signup_confirm' );" ) );
check( 'Signup listeners run on confirm', 1, substr_count( $confirm, "do_action( 'npmp_after_email_signup'" ) );
check( 'Pro can start its automation on confirm', 1, substr_count( $confirm, "do_action( 'npmp_signup_confirmed', \$contact_id, \$is_new );" ) );
check( 'Both logged-in and logged-out visitors', 2, preg_match_all( "/add_action\( 'admin_post_(nopriv_)?npmp_confirm_signup', 'npmp_handle_confirm_signup' \);/", $forms ) );
check( 'Mail goes through npmp_send_mail', 1, substr_count( section( $forms, 'function npmp_send_signup_confirmation', 'function npmp_handle_confirm_signup' ), 'npmp_send_mail( $email, $subject, $body );' ) );

echo "What the email says you signed up for\n";
$mail = section( $forms, 'function npmp_send_signup_confirmation', 'add_action( \'admin_post_nopriv_npmp_confirm_signup\'' );
check( 'Add-ons can name what the subscriber chose', 1, substr_count( $mail, "apply_filters( 'npmp_signup_confirmation_details'" ) );
check( 'The filter gets the same fields the confirm link will replay', 1, substr_count( $mail, '$kept, npmp_signup_atts_from_token( (string) $atts_token )' ) );
check( 'Detail paragraphs land between the opening line and the link', true, strpos( $mail, "apply_filters( 'npmp_signup_confirmation_details'" ) < strpos( $mail, 'To confirm, open this link' ) );
check( 'Only non-empty strings are used, so a bad filter cannot blank the body', 1, substr_count( $mail, "if ( is_string( \$detail ) && '' !== trim( \$detail ) ) {" ) );
check( 'The link and the ignore-it line are always last', true, strpos( $mail, 'To confirm, open this link' ) < strpos( $mail, "If you didn't sign up" ) );
check( 'Paragraphs are joined, not concatenated', 1, substr_count( $mail, '$body = implode( "\\n\\n", $lines );' ) );

echo "Attributes nothing accepts\n";
$note = section( $forms, 'function npmp_signup_form_unknown_atts_note', 'function npmp_email_signup_shortcode' );
check( 'Only an administrator sees the note', 1, substr_count( $note, "if ( ! \$names || ! current_user_can( 'manage_options' ) ) {" ) );
check( 'Attribute names are escaped and keyed', true, false !== strpos( $note, 'sanitize_key( $name )' ) && false !== strpos( $note, 'esc_html(' ) );
$shortcode = section( $forms, 'function npmp_email_signup_shortcode', 'add_shortcode( \'npmp_email_signup\'' );
check( 'The note names what shortcode_atts dropped', 1, substr_count( $shortcode, 'npmp_signup_form_unknown_atts_note( array_keys( array_diff_key( $raw, $defaults ) ) )' ) );

echo "Kept fields\n";
// Load the one pure helper and run it.
eval( '?>' . '<?php ' . section( $forms, 'function npmp_signup_fields_to_keep', '/**' ) );
$kept = npmp_signup_fields_to_keep(
	array(
		'npmp_email_signup_nonce' => 'n',
		'npmp_signup_website'     => '',
		'npmp_signup_atts'        => 'a.b',
		'npmp_notify_events'      => '1',
		'npmp_signup_groups'      => array( '3', '4', array( 'x' ) ),
		'cf-turnstile-response'   => 'token',
		'action'                  => 'npmp_handle_form',
	)
);
check( 'Keeps the form\'s own choices, drops nonce, honeypot and captcha', array( 'npmp_notify_events' => '1', 'npmp_signup_groups' => array( '3', '4' ) ), $kept );

echo "Who gets mail\n";
check( 'Post and event notifications skip Pending', 2, substr_count( $prefs, "array( 'key' => 'npmp_status', 'value' => array( 'unsubscribed', 'pending' ), 'compare' => 'NOT IN' )" ) );
check( 'No query left that only skips unsubscribed', 0, substr_count( $prefs, "'value' => 'unsubscribed', 'compare' => '!='" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
