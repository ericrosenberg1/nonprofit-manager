<?php
/**
 * Regression checks from the second pass of the 2026-11 free sweep.
 *
 * - Mailchimp and Constant Contact imports keep the key sealed in their
 *   transient (only the browser holds the secret), the way Brevo's does.
 * - reCAPTCHA checks the action, fetches a fresh token on every submit, marks
 *   every form on the page, and sends npmp_client_ip(). Turnstile sends
 *   npmp_client_ip() too.
 * - The Facebook share calls a Graph API version Meta still supports.
 * - User-facing strings say "canceled", never "cancelled".
 * - Email Settings saves the Pro provider secrets and keys as typed.
 * - An empty events list says which events it looked for.
 *
 * The real-WordPress half (newsletter and digest sends, confirmation links,
 * the calendar's list view, Email Settings with Pro) is tests/rig/free-pass2.php.
 *
 * Run: php tests/test-free-pass2.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function wp_unslash( $value ) {
	return $value;
}

/**
 * Body of one top-level function in a file, by name.
 *
 * @param string $src  File contents.
 * @param string $name Function name.
 * @return string
 */
function t_function_source( $src, $name ) {
	$start = strpos( $src, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$open  = strpos( $src, '{', $start );
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $open; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		} elseif ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $start, $i - $start + 1 );
			}
		}
	}
	return '';
}

echo "Mailchimp and Constant Contact keys stay sealed\n";
$import_src = (string) file_get_contents( $root . '/includes/import/admin-import.php' );
foreach ( array( 'npmp_import_mc_' => 'api_key', 'npmp_import_cc_' => 'access_token' ) as $prefix => $plain ) {
	$at    = strpos( $import_src, "'" . $prefix . "' . \$token," );
	$block = false === $at ? '' : substr( $import_src, $at, 400 );
	check( $prefix . ' transient is written', true, '' !== $block );
	check( $prefix . ' transient keeps no plain ' . $plain, false, (bool) preg_match( "/'" . $plain . "'\s*=>/", $block ) );
	check( $prefix . ' transient keeps a sealed box', true, (bool) preg_match( "/'box'\s*=>/", $block ) );
}
check( 'the wizard sends the secret on each step', true, false !== strpos( $import_src, 'import_secret:      previewData.import_secret' ) );

require_once $root . '/includes/import/brevo-api.php';
$open_src = t_function_source( $import_src, 'npmp_import_open_creds' );
check( 'npmp_import_open_creds() exists', true, '' !== $open_src );
if ( '' !== $open_src ) {
	eval( $open_src ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Loads one function from a file too big to include here.
	$sealed = npmp_brevo_seal( 'us6-0123456789abcdef' );
	$_POST  = array( 'import_secret' => $sealed['secret'] );
	check( 'the right secret opens the key', 'us6-0123456789abcdef', npmp_import_open_creds( array( 'box' => $sealed['box'] ), 'api_key' ) );
	$_POST = array( 'import_secret' => str_repeat( '0', 64 ) );
	check( 'a wrong secret opens nothing', '', npmp_import_open_creds( array( 'box' => $sealed['box'] ), 'api_key' ) );
	$_POST = array();
	check( 'no secret opens nothing', '', npmp_import_open_creds( array( 'box' => $sealed['box'] ), 'api_key' ) );
	check( 'a job from before sealing still reads its key', 'old-key', npmp_import_open_creds( array( 'api_key' => 'old-key' ), 'api_key' ) );
}

echo "\nreCAPTCHA and Turnstile\n";
$recaptcha_src = (string) file_get_contents( $root . '/includes/npmp-recaptcha.php' );
$turnstile_src = (string) file_get_contents( $root . '/includes/npmp-turnstile.php' );
check( 'reCAPTCHA sends npmp_client_ip(), not REMOTE_ADDR', false, false !== strpos( $recaptcha_src, "\$_SERVER['REMOTE_ADDR']" ) );
check( 'Turnstile sends npmp_client_ip(), not REMOTE_ADDR', false, false !== strpos( $turnstile_src, "\$_SERVER['REMOTE_ADDR']" ) );
check( 'reCAPTCHA sends npmp_client_ip()', true, false !== strpos( $recaptcha_src, "'remoteip' => function_exists( 'npmp_client_ip' ) ? npmp_client_ip()" ) );
check( 'Turnstile sends npmp_client_ip()', true, false !== strpos( $turnstile_src, "'remoteip' => function_exists( 'npmp_client_ip' ) ? npmp_client_ip()" ) );
check( 'no submit goes out on a token already in the form', false, false !== strpos( $recaptcha_src, 'Already has token' ) );
check( 'every submit drops the old token first', true, false !== strpos( $recaptcha_src, 'input.parentNode.removeChild(input)' ) );
check( 'every form on the page gets marked', true, false !== strpos( $recaptcha_src, 'document.querySelectorAll("form input[name=\"recaptcha-enabled\"]")' ) );

require_once $root . '/includes/npmp-recaptcha.php';
if ( function_exists( 'npmp_recaptcha_answer_passes' ) ) {
	check( 'a good answer passes', true, npmp_recaptcha_answer_passes( array( 'success' => true, 'action' => 'submit', 'score' => 0.9 ) ) );
	check( 'another action fails', false, npmp_recaptcha_answer_passes( array( 'success' => true, 'action' => 'login', 'score' => 0.9 ) ) );
	check( 'no action fails', false, npmp_recaptcha_answer_passes( array( 'success' => true, 'score' => 0.9 ) ) );
	check( 'a low score fails', false, npmp_recaptcha_answer_passes( array( 'success' => true, 'action' => 'submit', 'score' => 0.2 ) ) );
	check( 'no success fails', false, npmp_recaptcha_answer_passes( array( 'success' => false, 'action' => 'submit', 'score' => 0.9 ) ) );
	check( 'a non-array fails', false, npmp_recaptcha_answer_passes( null ) );
} else {
	check( 'npmp_recaptcha_answer_passes() exists', true, false );
}

echo "\nFacebook Graph API version\n";
$fb_src = (string) file_get_contents( $root . '/includes/social-sharing/networks/facebook.php' );
preg_match_all( '#graph\.facebook\.com/v(\d+)\.\d+#', $fb_src, $hard );
check( 'no hard-coded Graph API version in the URL', array(), $hard[1] );
require_once $root . '/includes/social-sharing/networks/facebook.php';
$version = function_exists( 'npmp_social_facebook_graph_version' ) ? npmp_social_facebook_graph_version() : '';
// Meta's table (developers.facebook.com/docs/graph-api/changelog/versions,
// read 2026-11-15): v19.0 ended May 21, 2026, v20.0 September 24, 2026, v24.0
// runs to February 18, 2028, and v26.0 is current.
check( 'the version looks like vNN.0', true, (bool) preg_match( '/^v\d+\.0$/', $version ) );
check( 'the version runs past 2027', true, (int) substr( $version, 1 ) >= 24 );

echo "\nUS spelling in user-facing strings\n";
$british = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
$i18n    = array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', 'esc_html_x', 'esc_attr_x' );
foreach ( $it as $info ) {
	if ( 'php' !== $info->getExtension() ) {
		continue;
	}
	$tokens = token_get_all( (string) file_get_contents( $info->getPathname() ) );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], $i18n, true ) ) {
			continue;
		}
		// Every string argument up to the closing parenthesis.
		$depth = 0;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t ) {
				$depth++;
			} elseif ( ')' === $t ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			} elseif ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && preg_match( '/cancell(ed|ing)/i', $t[1] ) ) {
				$british[] = str_replace( $root . '/', '', $info->getPathname() ) . ':' . $t[2] . ' ' . $t[1];
			}
		}
	}
}
check( 'no "cancelled" or "cancelling" in a translated string', array(), $british );

echo "\nEmail Settings keeps provider secrets as typed\n";
$settings_src = (string) file_get_contents( $root . '/includes/npmp-email-settings.php' );
foreach ( array( 'npmp_aws_ses_secret_key', 'npmp_brevo_api_key', 'npmp_sendgrid_api_key', 'npmp_mailgun_api_key', 'npmp_postmark_api_key' ) as $field ) {
	check( $field . ' skips sanitize_text_field()', false, (bool) preg_match( "/sanitize_text_field\(\s*wp_unslash\(\s*\\\$_POST\['" . $field . "'\]/", $settings_src ) );
	check( $field . ' goes through npmp_email_clean_secret()', true, (bool) preg_match( "/npmp_email_clean_secret\(\s*wp_unslash\(\s*\\\$_POST\['" . $field . "'\]/", $settings_src ) );
}
require_once $root . '/includes/email/helpers.php';
check( 'the cleaner keeps %41, a tag shape, and two spaces', 'ab%41cd<ef>  gh', npmp_email_clean_secret( " ab%41cd<ef>  gh\n" ) );

echo "\nEmpty events lists\n";
require_once $root . '/includes/npmp-events-loop.php';
if ( function_exists( 'npmp_events_loop_empty_message' ) ) {
	check( 'upcoming list', 'No upcoming events.', npmp_events_loop_empty_message( npmp_events_loop_past_mode( 'false' ) ) );
	check( 'past list', 'No past events.', npmp_events_loop_empty_message( npmp_events_loop_past_mode( 'true' ) ) );
	check( 'past and upcoming list', 'No events found.', npmp_events_loop_empty_message( npmp_events_loop_past_mode( 'all' ) ) );
} else {
	check( 'npmp_events_loop_empty_message() exists', true, false );
}
$loop_src = (string) file_get_contents( $root . '/includes/npmp-events-loop.php' );
check( 'the render prints the mode\'s message', true, false !== strpos( $loop_src, 'esc_html( npmp_events_loop_empty_message( $mode ) )' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
