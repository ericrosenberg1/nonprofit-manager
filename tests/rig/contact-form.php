<?php
/**
 * Real-WordPress check of the contact form's Reply-To: a sender whose name
 * has a comma keeps one reply address and their whole name through
 * wp_mail(), which splits the header on commas.
 *
 * Run inside a throwaway WordPress with the plugin active (CLAUDE.md,
 * "Testing beyond unit tests"):  wp eval-file tests/rig/contact-form.php
 * Nothing leaves the machine: phpmailer_init clears the recipients.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_contact_form_reply_to' ) ) {
	echo "  FAIL  npmp_contact_form_reply_to() is missing\n";
	exit( 1 );
}

echo "Reply-To header\n";
$ok( 'a plain name', 'Reply-To: Jo Smith <jo@example.org>' === npmp_contact_form_reply_to( 'Jo Smith', 'jo@example.org' ) );
$ok( 'a comma becomes a space', 'Reply-To: Smith Jo <jo@example.org>' === npmp_contact_form_reply_to( 'Smith, Jo', 'jo@example.org' ) );
$ok( 'no name, just the address', 'Reply-To: <jo@example.org>' === npmp_contact_form_reply_to( '', 'jo@example.org' ) );

echo "Through wp_mail()\n";
$seen = null;
$grab = function ( $mailer ) use ( &$seen ) {
	$seen = $mailer->getReplyToAddresses();
	// No recipients left, so PHPMailer refuses and wp_mail() returns false.
	$mailer->clearAllRecipients();
};
add_action( 'phpmailer_init', $grab );
wp_mail( 'office@example.org', 'Rig', 'Body', array( npmp_contact_form_reply_to( 'Smith, Jo "JJ"', 'jo@example.org' ) ) );
remove_action( 'phpmailer_init', $grab );
$ok( 'one reply address', is_array( $seen ) && 1 === count( $seen ) );
$first = is_array( $seen ) ? reset( $seen ) : null;
$ok( 'it is the sender', $first && 'jo@example.org' === $first[0] );
$ok( 'with the whole name, not just what followed the comma', $first && 'Smith Jo JJ' === $first[1] );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
