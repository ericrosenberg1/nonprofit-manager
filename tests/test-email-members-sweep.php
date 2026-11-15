<?php
/**
 * Regression checks from the 2026-11 email and members sweep.
 *
 * - [npmp_can_spam] ignores its attributes, so a post can't print another
 *   address's signed unsubscribe link.
 * - The SMTP password keeps %XX, <, and runs of spaces (sanitize_text_field()
 *   changed all three).
 * - The open pixel sends the GIF's real length.
 * - Imported posts and events never queue a "new post" notification.
 * - A duplicated newsletter keeps its template.
 * - A template that includes itself renders once instead of forever.
 *
 * The real-WordPress half is tests/rig/email-members-sweep.php.
 *
 * Run: php tests/test-email-members-sweep.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

$GLOBALS['t_shortcodes'] = array();
$GLOBALS['t_meta']       = array();
$GLOBALS['t_posts']      = array();
$GLOBALS['t_importing']  = false;

function add_action() {}
function add_filter() {}
function add_shortcode( $tag, $cb ) {
	$GLOBALS['t_shortcodes'][ $tag ] = $cb;
}
function shortcode_atts( $pairs, $atts ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $k => $v ) {
		$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
	}
	return $out;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function get_post( $id ) {
	return $GLOBALS['t_posts'][ (int) $id ] ?? null;
}
// A stand-in for the_content: it runs nested shortcodes, the way core's
// do_shortcode at priority 11 does.
function apply_filters( $hook, $value ) {
	if ( 'the_content' !== $hook ) {
		return $value;
	}
	return preg_replace_callback(
		'/\[npmp_nl_template id="(\d+)"\]/',
		function ( $m ) {
			return call_user_func( $GLOBALS['t_shortcodes']['npmp_nl_template'], array( 'id' => $m[1] ) );
		},
		$value
	);
}
function npmp_is_importing() {
	return $GLOBALS['t_importing'];
}

// [npmp_can_spam]: a stand-in for the footer builder records what the tag
// passes it. The shortcode file defines the real one only when it's missing.
$GLOBALS['t_can_spam_args'] = 'never called';
function npmp_can_spam_shortcode( $atts = array() ) {
	$GLOBALS['t_can_spam_args'] = $atts;
	return 'footer';
}

require_once $root . '/includes/email-newsletter/can-spam-shortcode.php';
require_once $root . '/includes/email/helpers.php';
require_once $root . '/includes/npmp-subscription-preferences.php';
require_once $root . '/includes/email-newsletter/templates.php';

echo "== [npmp_can_spam] ignores its attributes ==\n";
$cb = $GLOBALS['t_shortcodes']['npmp_can_spam'] ?? null;
check( 'tag registered to the wrapper', 'npmp_can_spam_shortcode_tag', $cb );
$out = call_user_func( $cb, array( 'email' => 'someone@example.org', 'newsletter_id' => '9' ), '', 'npmp_can_spam' );
check( 'tag prints the footer', 'footer', $out );
check( 'footer builder gets no address from the tag', array(), $GLOBALS['t_can_spam_args'] );

echo "== SMTP password keeps every character ==\n";
check( 'percent, angle brackets, and double space kept', 'ab%41cd<ef>  gh', npmp_email_clean_secret( 'ab%41cd<ef>  gh' ) );
check( 'line breaks and NUL dropped', 'abcd', npmp_email_clean_secret( "ab\r\nc\0d" ) );
check( 'outer spaces trimmed', 'p w', npmp_email_clean_secret( '  p w  ' ) );
check( 'an array gives an empty string', '', npmp_email_clean_secret( array( 'x' ) ) );
$settings_src = (string) file_get_contents( $root . '/includes/npmp-email-settings.php' );
check( 'settings save runs the password through the cleaner', 1, preg_match( "/npmp_email_clean_secret\(\s*wp_unslash\(\s*\\\$_POST\['npmp_smtp_password'\]/", $settings_src ) );
check( 'settings save no longer runs the password through sanitize_text_field', 0, preg_match( "/sanitize_text_field\(\s*wp_unslash\(\s*\\\$_POST\['npmp_smtp_password'\]/", $settings_src ) );

echo "== Open pixel length ==\n";
$tracker_src = (string) file_get_contents( $root . '/includes/email-newsletter/class-newsletter-tracker.php' );
check( 'no hard-coded Content-Length', false, strpos( $tracker_src, "Content-Length: 43" ) !== false );
check( 'Content-Length counted from the bytes', true, strpos( $tracker_src, "header( 'Content-Length: ' . strlen( \$pixel ) );" ) !== false );
$pixel = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
check( 'pixel is a GIF', 'GIF89a', substr( $pixel, 0, 6 ) );
check( 'pixel is 34 bytes, not 43', 34, strlen( $pixel ) );

echo "== Imports never notify ==\n";
$post = (object) array( 'ID' => 5, 'post_type' => 'npmp_event' );
check( 'a hand-published event notifies', false, npmp_post_notification_is_import( $post, 'draft' ) );
check( 'a new post with no import marks notifies', false, npmp_post_notification_is_import( $post, 'new' ) );
$GLOBALS['t_meta'][5] = array( '_npmp_import_key' => 'events-manager:12' );
check( 'a new event with _npmp_import_key is an import', true, npmp_post_notification_is_import( $post, 'new' ) );
check( 'the same event published later from draft notifies', false, npmp_post_notification_is_import( $post, 'draft' ) );
$GLOBALS['t_meta'][5] = array( '_npmp_tec_source_id' => '881' );
check( 'a new event with _npmp_tec_source_id is an import', true, npmp_post_notification_is_import( $post, 'new' ) );
$GLOBALS['t_meta'][5] = array();
$GLOBALS['t_importing'] = true;
check( 'a new post during npmp_import_run_as_import() is an import', true, npmp_post_notification_is_import( $post, 'new' ) );
$GLOBALS['t_importing'] = false;
$prefs_src = (string) file_get_contents( $root . '/includes/npmp-subscription-preferences.php' );
$hook_body = substr( $prefs_src, strpos( $prefs_src, 'function npmp_maybe_send_post_notification' ), 1500 );
check( 'the notification hook asks before it schedules', true, strpos( $hook_body, 'npmp_post_notification_is_import( $post, $old_status )' ) < strpos( $hook_body, 'wp_schedule_single_event' ) && false !== strpos( $hook_body, 'npmp_post_notification_is_import( $post, $old_status )' ) );
define( 'WP_IMPORTING', true );
check( 'anything during the WordPress importer is an import', true, npmp_post_notification_is_import( $post, 'draft' ) );

echo "== Duplicates keep the template ==\n";
$editor_src = (string) file_get_contents( $root . '/includes/email-newsletter/editor.php' );
preg_match( '/function npmp_newsletter_duplicate_meta_keys\(\)\s*\{\s*return\s*(\[[^\]]*\]);/', $editor_src, $m );
check( 'duplicate meta keys include _npmp_newsletter_template', true, isset( $m[1] ) && false !== strpos( $m[1], "'_npmp_newsletter_template'" ) );

echo "== Newsletter AJAX answers are plain text ==\n";
$ajax = substr( $editor_src, strpos( $editor_src, "add_action('wp_ajax_npmp_send_test_newsletter'" ) );
$ajax = substr( $ajax, 0, strpos( $ajax, '* Duplicate' ) ?: 4000 );
check( 'no esc_html in the send and test answers', 0, preg_match_all( '/esc_html/', $ajax ) );

echo "== A template that includes itself ==\n";
$GLOBALS['t_posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'npmp_nl_template', 'post_content' => 'A[npmp_nl_template id="40"]B' );
$GLOBALS['t_posts'][41] = (object) array( 'ID' => 41, 'post_type' => 'npmp_nl_template', 'post_content' => 'X[npmp_nl_template id="42"]' );
$GLOBALS['t_posts'][42] = (object) array( 'ID' => 42, 'post_type' => 'npmp_nl_template', 'post_content' => 'Y[npmp_nl_template id="41"]' );
$GLOBALS['t_posts'][43] = (object) array( 'ID' => 43, 'post_type' => 'post', 'post_content' => 'secret' );
check( 'self-include renders once', 'AB', npmp_nl_template_shortcode( array( 'id' => 40 ) ) );
check( 'two templates that include each other stop', 'XY', npmp_nl_template_shortcode( array( 'id' => 41 ) ) );
check( 'the same template still renders twice side by side', 'ABAB', npmp_nl_template_shortcode( array( 'id' => 40 ) ) . npmp_nl_template_shortcode( array( 'id' => 40 ) ) );
check( 'a post that is not a template prints nothing', '', npmp_nl_template_shortcode( array( 'id' => 43 ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
