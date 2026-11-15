<?php
/**
 * Real-WordPress check of four newsletter and event editor fixes (2026.11.14):
 *
 * 1. The admin Preview button (npmp_newsletter_preview_url()) opens a draft, a
 *    sent, and an imported newsletter. Core's get_preview_post_link() returns
 *    nothing for this non-public post type, so the button had an empty href
 *    and the editor's "Preview in new tab" never printed (what Pete saw).
 * 2. The event editor's "Send a test to me" uses the template picked in the
 *    editor now, not only the saved one, and refuses a template the user can't
 *    read.
 * 3. A newsletter whose saved template was deleted or trashed goes out in the
 *    default template, the way the picker shows it.
 * 4. The editor script's group list reads like wp_sprintf( '%l' ), which Pro
 *    saves the label with.
 *
 * Run inside a throwaway WordPress with members, newsletters and the calendar
 * on, pretty permalinks, and the site served at its home URL (php -S with
 * PHP_CLI_SERVER_WORKERS=4):
 *   wp eval-file tests/rig/newsletter-editor-fixes.php
 * Needs node on the PATH for check 4. It deletes what it makes.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_newsletter_preview_url' ) || ! function_exists( 'npmp_scheduled_emails_readable_template_choice' ) || ! post_type_exists( 'npmp_event' ) ) {
	echo "  FAIL  newsletters and the calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
$GLOBALS['rig_made'] = array();
register_shutdown_function(
	static function () {
		foreach ( $GLOBALS['rig_made'] as $rig_id ) {
			wp_delete_post( $rig_id, true );
		}
	}
);
$rig_post = static function ( $args ) {
	$id                    = (int) wp_insert_post( $args );
	$GLOBALS['rig_made'][] = $id;
	return $id;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

// Signs a user in: a real session token in the cookies, and $_COOKIE set to
// match, so wp_create_nonce() here makes the nonce that session checks.
$rig_tokens = array();
$rig_login  = static function ( $user_id ) use ( &$rig_tokens ) {
	$exp = time() + 600;
	if ( empty( $rig_tokens[ $user_id ] ) ) {
		$rig_tokens[ $user_id ] = WP_Session_Tokens::get_instance( $user_id )->create( $exp );
	}
	$token                       = $rig_tokens[ $user_id ];
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $exp, 'logged_in', $token );
	wp_set_current_user( $user_id );
	return array(
		AUTH_COOKIE      => wp_generate_auth_cookie( $user_id, $exp, 'auth', $token ),
		LOGGED_IN_COOKIE => $_COOKIE[ LOGGED_IN_COOKIE ],
	);
};
// A request as that user, over HTTP.
$rig_get = static function ( $url, $user_id ) use ( $rig_login ) {
	$header = array();
	foreach ( $rig_login( $user_id ) as $k => $v ) {
		$header[] = $k . '=' . rawurlencode( $v );
	}
	return wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'Cookie' => implode( '; ', $header ) ) ) );
};

echo "1. Preview\n";
$body_html = '<!-- wp:paragraph --><p>Rig body text for the preview.</p><!-- /wp:paragraph -->';
$draft     = $rig_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig draft newsletter', 'post_content' => $body_html ) );
$sent      = $rig_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Rig sent newsletter', 'post_content' => $body_html ) );
update_post_meta( $sent, '_npmp_newsletter_status', 'sent' );
update_post_meta( $sent, '_npmp_newsletter_queued_at', current_time( 'mysql' ) );
$imported = $rig_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Rig imported newsletter', 'post_content' => $body_html ) );
update_post_meta( $imported, '_npmp_newsletter_status', 'sent' );
update_post_meta( $imported, '_npmp_import_key', 'mailpoet:rig' );
update_post_meta( $imported, '_npmp_import_source', 'mailpoet' );

$rig_login( $admin->ID );
foreach ( array( 'draft' => $draft, 'sent' => $sent, 'imported MailPoet' => $imported ) as $kind => $id ) {
	$res = $rig_get( npmp_newsletter_preview_url( $id ), $admin->ID );
	$txt = (string) wp_remote_retrieve_body( $res );
	$ok( "$kind: core's get_preview_post_link() is empty, so the old button had no URL", ! get_preview_post_link( $id ) );
	$ok( "$kind: the new preview answers 200", 200 === (int) wp_remote_retrieve_response_code( $res ) );
	$ok( "$kind: it shows the body", false !== strpos( $txt, 'Rig body text for the preview.' ) );
	$ok( "$kind: it is noindex", false !== strpos( $txt, 'noindex' ) && false === strpos( $txt, 'rel="canonical"' ) );
}
$ok( 'the list row links to the new preview', false !== strpos( file_get_contents( dirname( __DIR__, 2 ) . '/includes/email-newsletter/editor.php' ), 'npmp_newsletter_preview_url($post_id)' ) );

$bad = $rig_get( add_query_arg( array( 'action' => 'npmp_preview_newsletter', 'newsletter_id' => $draft, '_wpnonce' => 'nope' ), admin_url( 'admin.php' ) ), $admin->ID );
$ok( 'a bad nonce is refused', 403 === (int) wp_remote_retrieve_response_code( $bad ) && false === strpos( (string) wp_remote_retrieve_body( $bad ), 'Rig body text' ) );
$sub  = (int) wp_insert_user( array( 'user_login' => 'rigsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'rigsub' . wp_rand() . '@example.org', 'role' => 'subscriber' ) );
$rig_login( $admin->ID );
$res  = $rig_get( npmp_newsletter_preview_url( $draft ), $sub );
$ok( 'a nonce from another user is refused', 403 === (int) wp_remote_retrieve_response_code( $res ) );
// The same user's own nonce, but no right to edit.
$rig_login( $sub );
$url = npmp_newsletter_preview_url( $draft );
$res = $rig_get( $url, $sub );
$ok( 'a subscriber with a valid nonce cannot read it', in_array( (int) wp_remote_retrieve_response_code( $res ), array( 403 ), true ) && false === strpos( (string) wp_remote_retrieve_body( $res ), 'Rig body text' ) );
wp_delete_user( $sub );
$rig_login( $admin->ID );
$res = $rig_get( npmp_newsletter_preview_url( 999999 ), $admin->ID );
$ok( 'a missing newsletter answers 404', 404 === (int) wp_remote_retrieve_response_code( $res ) );
$post_id = $rig_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rig plain post' ) );
$res     = $rig_get( npmp_newsletter_preview_url( $post_id ), $admin->ID );
$ok( 'an ordinary post is not served by it', 404 === (int) wp_remote_retrieve_response_code( $res ) );

echo "\n2. Send a test to me uses the picked template\n";
$wrapper = $rig_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig wrapper', 'post_content' => '<div class="rig-wrap">[email_content]</div>' ) );
update_post_meta( $wrapper, '_npmp_template_type', 'wrapper' );
$other = $rig_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig other', 'post_content' => '<div class="rig-other">[email_content]</div>' ) );
update_post_meta( $other, '_npmp_template_type', 'wrapper' );
$default = $rig_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig default', 'post_content' => '<div class="rig-default">[email_content]</div>' ) );
update_post_meta( $default, '_npmp_template_type', 'wrapper' );
update_post_meta( $default, '_npmp_is_default_template', '1' );
$trashed = $rig_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'trash', 'post_title' => 'Rig trashed', 'post_content' => '<div class="rig-trashed">[email_content]</div>' ) );
$event   = $rig_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig AGM', 'post_content' => '<p>The AGM is in the hall.</p>' ) );
update_post_meta( $event, '_npmp_event_start', gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );
update_post_meta( $event, '_npmp_event_email_template', $wrapper ); // Saved: wrapper.

$render = static function ( $picked ) use ( $event ) {
	return npmp_scheduled_emails_render_test( $event, 0, '', 'rig@example.org', null, $picked )['html'];
};
$html = $render( null );
$ok( 'no pick: the saved template wraps it', false !== strpos( $html, 'rig-wrap' ) );
$html = $render( (string) $other );
$ok( 'picked, unsaved: the picked template wraps it', false !== strpos( $html, 'rig-other' ) && false === strpos( $html, 'rig-wrap' ) );
$html = $render( 'none' );
$ok( 'picked No Template: no wrapper', false === strpos( $html, 'class="rig-' ) && false !== strpos( $html, 'Rig AGM' ) );
$html = $render( '' );
$ok( 'picked Use Default: the site default wraps it', false !== strpos( $html, 'rig-default' ) && false === strpos( $html, 'rig-wrap' ) );
$ok( 'and the saved choice is untouched', (int) $wrapper === (int) get_post_meta( $event, '_npmp_event_email_template', true ) );

$sub = (int) wp_insert_user( array( 'user_login' => 'rigsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'rigsub' . wp_rand() . '@example.org', 'role' => 'subscriber' ) );
$ok( 'readable: a live template ID passes', (string) $other === npmp_scheduled_emails_readable_template_choice( (string) $other ) );
$ok( 'readable: "" and "none" pass', '' === npmp_scheduled_emails_readable_template_choice( '' ) && 'none' === npmp_scheduled_emails_readable_template_choice( 'none' ) );
$ok( 'readable: a trashed template is refused', null === npmp_scheduled_emails_readable_template_choice( (string) $trashed ) );
$ok( 'readable: an ordinary post is refused', null === npmp_scheduled_emails_readable_template_choice( (string) $post_id ) );
$ok( 'readable: an ID that does not exist is refused', null === npmp_scheduled_emails_readable_template_choice( '999999' ) );
$ok( 'readable: junk is refused', null === npmp_scheduled_emails_readable_template_choice( '12abc' ) && null === npmp_scheduled_emails_readable_template_choice( '-3' ) );
$private_tpl = $rig_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'private', 'post_title' => 'Rig private', 'post_content' => '[email_content]' ) );
wp_set_current_user( $sub );
$ok( 'readable: a user who cannot read a private template is refused', null === npmp_scheduled_emails_readable_template_choice( (string) $private_tpl ) );
wp_set_current_user( $admin->ID );
$ok( 'readable: an admin can pick the private one', (string) $private_tpl === npmp_scheduled_emails_readable_template_choice( (string) $private_tpl ) );
wp_delete_user( $sub );

// The real AJAX handler, over HTTP, posting the unsaved choice.
$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/npmp-scheduled-emails.js' );
$ok( 'the script posts the picker value', false !== strpos( $js, "#npmp_event_email_template" ) && false !== strpos( $js, 'data.template' ) );

echo "\n3. A deleted template on a newsletter falls back to the default\n";
$nl = $rig_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig stale template', 'post_content' => '<p>Stale body.</p>' ) );
update_post_meta( $nl, '_npmp_newsletter_template', $wrapper );
$html = (string) NPMP_Newsletter_Manager::render_base_content( $nl );
$ok( 'a live saved template wraps it', false !== strpos( $html, 'rig-wrap' ) );
update_post_meta( $nl, '_npmp_newsletter_template', 999999 );
$html = (string) NPMP_Newsletter_Manager::render_base_content( $nl );
$ok( 'a template that never existed: the default wraps it', false !== strpos( $html, 'rig-default' ) && false !== strpos( $html, 'Stale body.' ) );
update_post_meta( $nl, '_npmp_newsletter_template', $trashed );
$html = (string) NPMP_Newsletter_Manager::render_base_content( $nl );
$ok( 'a trashed template: the default wraps it', false !== strpos( $html, 'rig-default' ) && false === strpos( $html, 'rig-trashed' ) );
update_post_meta( $nl, '_npmp_newsletter_template', 'none' );
$html = (string) NPMP_Newsletter_Manager::render_base_content( $nl );
$ok( 'No Template still means none', false === strpos( $html, 'rig-default' ) && false !== strpos( $html, 'Stale body.' ) );
ob_start();
update_post_meta( $nl, '_npmp_newsletter_template', 999999 );
npmp_newsletter_template_meta_box( get_post( $nl ) );
$picker = ob_get_clean();
$ok( 'and the picker shows no live template as selected (reads Use Default)', 0 === preg_match( '#<option value="\d+"\s+selected=#', $picker ) );

echo "\n4. The group list reads the same live and saved\n";
$words = npmp_newsletter_list_words();
$ok( 'the PHP words are the ones wp_sprintf( %l ) uses', wp_sprintf( '%l', array( 'A', 'B' ) ) === 'A' . $words['between_only_two'] . 'B' && wp_sprintf( '%l', array( 'A', 'B', 'C' ) ) === 'A' . $words['between'] . 'B' . $words['between_last_two'] . 'C' );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( '' === $node ) {
	echo "  SKIP  node not found, script join not compared\n";
} else {
	$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/email-newsletter/assets/newsletter-editor.js' );
	preg_match( '#const joinList = \(items\) => \{.*?\n    \};#s', $src, $m );
	$ok( 'the script has joinList', ! empty( $m[0] ) );
	$sets = array( array( 'Art' ), array( 'Art', 'History' ), array( 'Art', 'History', 'Walking' ), array( 'Art', 'History', 'Walking', 'Chess' ) );
	foreach ( array( 'en', 'fr-style' ) as $mode ) {
		if ( 'fr-style' === $mode ) {
			add_filter( 'wp_sprintf_l', static function () {
				return array( 'between' => ' / ', 'between_last_two' => ' et ', 'between_only_two' => ' et ' );
			} );
			$words = npmp_newsletter_list_words();
		}
		$harness = 'const $audienceLabel = { data: (k) => ({ listBetween: ' . wp_json_encode( $words['between'] ) . ', listLast: ' . wp_json_encode( $words['between_last_two'] ) . ', listTwo: ' . wp_json_encode( $words['between_only_two'] ) . ' })[k] };' . $m[0] . 'console.log(JSON.stringify(' . wp_json_encode( $sets ) . '.map(joinList)));';
		$out     = json_decode( (string) shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $harness ) ), true );
		$want    = array_map( static function ( $s ) {
			return wp_sprintf( '%l', $s );
		}, $sets );
		$ok( "script and PHP agree ($mode): " . wp_json_encode( $want ), $out === $want );
	}
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit( $fails ? 1 : 0 );
