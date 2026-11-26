<?php
/**
 * Real-WordPress check of the contact form's Reply-To: a sender whose name
 * has a comma keeps one reply address and their whole name through
 * wp_mail(), which splits the header on commas.
 *
 * Run inside a throwaway WordPress with the plugin active (CLAUDE.md,
 * "Testing beyond unit tests"):  wp eval-file tests/rig/contact-form.php
 * Nothing leaves the machine: phpmailer_init clears the recipients.
 *
 * The visitor cap posts the form over HTTP, so serve the site at its home
 * URL (php -S) and, to catch the mail, run tests/rig/smtp-sink.py on
 * NPMP_RIG_SINK_PORT (default 10426). Without a server it skips that part.
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

echo "Messages per visitor over HTTP (sweep 2026-11-21, F7)\n";
// The form posts to admin-post.php as a logged-out visitor. Mail goes to
// tests/rig/smtp-sink.py on NPMP_RIG_SINK_PORT, or fails at a closed port,
// so nothing leaves the machine either way.
$rl_keys = function () {
	$keys = array();
	foreach ( array( 'contact_form|127.0.0.1', 'contact_form|::1', 'contact_form_site|site' ) as $slot ) {
		$keys[] = 'npmp_rl_' . substr( md5( $slot . '|' . wp_salt( 'nonce' ) ), 0, 24 );
	}
	return $keys;
};
array_map( 'delete_transient', $rl_keys() );
$saved_mail = get_option( 'npmp_email_settings' );
update_option(
	'npmp_email_settings',
	array(
		'provider'        => 'smtp',
		'from_email'      => 'hello@npmp-rig.test',
		'from_name'       => 'Rig',
		'force_from'      => 0,
		'from_all_mail'   => 0,
		'set_return_path' => 0,
		'smtp'            => array(
			'host'       => '127.0.0.1',
			'port'       => (int) ( getenv( 'NPMP_RIG_SINK_PORT' ) ?: 10426 ),
			'encryption' => 'none',
			'auth'       => 1,
			'username'   => 'rig-user',
			'password'   => 'rig-pass',
			'auto_tls'   => 0,
		),
	)
);
wp_set_current_user( 0 );
$post = function () {
	$r = wp_remote_post(
		admin_url( 'admin-post.php' ),
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'body'        => array(
				'action'                => 'npmp_contact_form',
				'npmp_contact_nonce'    => wp_create_nonce( 'npmp_contact_form' ),
				'npmp_contact_redirect' => home_url( '/' ),
				'npmp_contact_name'     => 'Rig Sender',
				'npmp_contact_email'    => 'rig-sender@example.org',
				'npmp_contact_message'  => 'Rig message',
			),
		)
	);
	if ( is_wp_error( $r ) ) {
		return null;
	}
	parse_str( (string) wp_parse_url( (string) wp_remote_retrieve_header( $r, 'location' ), PHP_URL_QUERY ), $q );
	return 'success' === ( $q['npmp_contact'] ?? '' ) ? 'success' : (string) ( $q['npmp_contact_reason'] ?? '?' );
};
try {
	$first_post = $post();
	if ( null === $first_post ) {
		echo '  SKIP  no web server answers at ' . esc_url_raw( admin_url( 'admin-post.php' ) ) . "\n";
	} else {
		$got = array( $first_post );
		for ( $i = 2; $i <= 11; $i++ ) {
			$got[] = $post();
		}
		$ok( 'the first 10 posts reach the mailer (sent, or refused by a closed port)', 10 === count( array_intersect( array_slice( $got, 0, 10 ), array( 'success', 'send' ) ) ) );
		$ok( 'the 11th gets the busy message', 'busy' === $got[10] );
		$_GET = array( 'npmp_contact' => 'error', 'npmp_contact_reason' => 'busy' );
		$page = do_shortcode( '[npmp_contact_form]' );
		$_GET = array();
		$ok( 'the form tells the visitor to try again in an hour', false !== strpos( $page, 'Please try again in an hour.' ) );
	}
} finally {
	array_map( 'delete_transient', $rl_keys() );
	if ( false === $saved_mail ) {
		delete_option( 'npmp_email_settings' );
	} else {
		update_option( 'npmp_email_settings', $saved_mail );
	}
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
