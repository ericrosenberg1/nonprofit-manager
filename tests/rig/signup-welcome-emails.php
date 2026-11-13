<?php
/**
 * Real-WordPress check of the signup and welcome email fixes from Pete
 * Lord's two wordpress.org threads (2026-11-13). Runs with Pro active or
 * inactive and checks what each should do:
 *
 * - The "please confirm" email: unchanged in free, with the groups the signup
 *   asked for when Pro is on (named groups, ticked boxes, nothing ticked).
 * - [npmp_email_signup groups="" choose="yes"]: what it renders, what a
 *   submit with nothing ticked does, and checked="yes|no" (Pro on).
 * - Post notifications and the weekly digest: the newsletter footer with the
 *   reader's postal address and own unsubscribe link, plus List-Unsubscribe.
 * - [groups] in a newsletter (Pro on fills it, Pro off leaves free's tags).
 * - The tag help lines, free and Pro.
 * - The form color: no CSS by default, the variables and a dark label for a
 *   light color, the setting saved from Membership Settings.
 *
 * Mail is captured through pre_wp_mail, nothing leaves the machine.
 *
 *   wp eval-file tests/rig/signup-welcome-emails.php                                  (Pro active)
 *   wp --skip-plugins=nonprofit-manager-pro eval-file tests/rig/signup-welcome-emails.php  (Pro off)
 *
 * Leaves no contacts, pages, groups, or settings behind.
 */

$pro = defined( 'NPMP_PRO_VERSION' ) && function_exists( 'npmp_pro_get_groups' );
echo $pro ? "== Pro active ==\n" : "== Pro inactive ==\n";

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $cond ) {
	$GLOBALS[ $cond ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
}
function rig_has( $haystack, $needle ) {
	return false !== strpos( (string) $haystack, (string) $needle );
}
class Rig_Stop extends Error { public $payload = ''; }
add_filter( 'wp_redirect', function ( $location ) { $e = new Rig_Stop( 'redirect' ); $e->payload = $location; throw $e; }, -1000 );
add_filter( 'wp_die_handler', function () { return function ( $msg ) { $e = new Rig_Stop( 'die' ); $e->payload = is_string( $msg ) ? $msg : ''; throw $e; }; } );
add_filter( 'npmp_rate_limit_max', function () { return 1000; } );
$GLOBALS['rig_mails'] = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['rig_mails'][] = $atts; return true; }, 10, 2 );
function rig_run( $callable ) {
	ob_start();
	try { call_user_func( $callable ); $r = ''; } catch ( Rig_Stop $e ) { $r = (string) $e->payload; }
	ob_end_clean();
	return $r;
}
function rig_mail_to( $email ) {
	foreach ( array_reverse( $GLOBALS['rig_mails'] ) as $m ) {
		if ( strtolower( (string) ( is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ) ) === strtolower( $email ) ) {
			return $m;
		}
	}
	return null;
}
function rig_headers( $mail ) {
	return implode( "\n", (array) ( $mail['headers'] ?? array() ) );
}
function rig_signup( $email, $name, $atts, $extra = array() ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge(
		array(
			'action'                  => 'npmp_handle_form',
			'npmp_action'             => 'email_signup',
			'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
			'npmp_name'               => $name,
			'npmp_email'              => $email,
			'npmp_signup_atts'        => npmp_signup_atts_token( array_merge( npmp_signup_form_default_atts(), $atts ) ),
		),
		$extra
	);
	$_REQUEST = $_POST;
	$where = rig_run( 'npmp_handle_membership_form' );
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $where;
}
function rig_confirm_from( $mail ) {
	if ( ! preg_match( '#(https?://\S+action=npmp_confirm_signup\S*)#', (string) ( $mail['message'] ?? '' ), $m ) ) {
		return '';
	}
	parse_str( (string) wp_parse_url( $m[1], PHP_URL_QUERY ), $q );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = $_REQUEST = $q + array( 'npmp_confirm' => '1' );
	$r = rig_run( 'npmp_handle_confirm_signup' );
	$_POST = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return $r;
}

$mm      = NPMP_Member_Manager::get_instance();
$emails  = array( 'rig-swe-plain@example.org', 'rig-swe-named@example.org', 'rig-swe-ticked@example.org', 'rig-swe-none@example.org', 'rig-swe-none2@example.org', 'rig-swe-reader@example.org', 'rig-swe-lone@example.org' );
$sweep   = function () use ( $mm, $emails ) {
	foreach ( $emails as $e ) {
		$m = $mm->get_member_by_email( $e );
		if ( $m ) {
			$mm->delete_member( $m->id );
		}
	}
};
$sweep();
$keep_options = array();
foreach ( array( 'npmp_membership_form_settings', 'npmp_org_mailing_address', 'npmp_newsletter_can_spam_footer', 'npmp_forms_accent', 'npmp_preferences_page_id', 'npmp_digest_pending_content' ) as $opt ) {
	$keep_options[ $opt ] = get_option( $opt, null );
}
update_option( 'npmp_org_mailing_address', "12 Rig Lane\nTestville, CA 90000" );
update_option( 'npmp_newsletter_can_spam_footer', "You're receiving this email from [organization] at [address].\nTo unsubscribe, click here: [unsubscribe_url]" );
$settings                 = (array) get_option( 'npmp_membership_form_settings', array() );
$settings['double_optin'] = 1;
$settings['signup_page_id'] = 0;
update_option( 'npmp_membership_form_settings', $settings );
npmp_get_membership_form_settings( true );

$group_ids = array();
if ( $pro ) {
	foreach ( array( 'Rig Art', 'Rig History' ) as $g ) {
		$t = term_exists( $g, NPMP_PRO_GROUP_TAXONOMY );
		$t = $t ? $t : wp_insert_term( $g, NPMP_PRO_GROUP_TAXONOMY );
		$group_ids[ $g ] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
		npmp_pro_update_group_settings( $group_ids[ $g ], array( 'open' => '1' ) );
	}
	// Only the rig's groups count for "every group" below.
	foreach ( npmp_pro_get_groups() as $t ) {
		if ( ! in_array( (int) $t->term_id, $group_ids, true ) ) {
			npmp_pro_update_group_settings( (int) $t->term_id, array( 'open' => '' ) );
		}
	}
}

echo "-- The please-confirm email\n";
$GLOBALS['rig_mails'] = array();
rig_signup( 'rig-swe-plain@example.org', 'Pat Plain', array() );
$mail = rig_mail_to( 'rig-swe-plain@example.org' );
$body = (string) ( $mail['message'] ?? '' );
rig_check( 'A confirm email went out', null !== $mail );
rig_check( 'It opens with the thank-you line', 0 === strpos( $body, 'Thanks for signing up for email from ' ) );
rig_check( 'A signup with no groups gets no group line', ! rig_has( $body, 'You asked to join' ) );
rig_check( 'The link section follows the thank-you line', (bool) preg_match( "/^Thanks for signing up[^\n]*\n\nTo confirm, open this link/", $body ) );

if ( $pro ) {
	$GLOBALS['rig_mails'] = array();
	rig_signup( 'rig-swe-named@example.org', 'Nina Named', array( 'groups' => 'Rig Art, Rig History' ) );
	$body = (string) ( rig_mail_to( 'rig-swe-named@example.org' )['message'] ?? '' );
	rig_check( 'Named groups: "You asked to join these groups: Rig Art and Rig History."', rig_has( $body, 'You asked to join these groups: Rig Art and Rig History.' ) );
	rig_check( 'Group line sits between the thank-you and the link', (bool) preg_match( "/^Thanks for signing up[^\n]*\n\nYou asked to join these groups[^\n]*\n\nTo confirm/", $body ) );

	$GLOBALS['rig_mails'] = array();
	rig_signup( 'rig-swe-ticked@example.org', 'Tia Ticked', array( 'choose' => 'yes' ), array( 'npmp_signup_groups' => array( (string) $group_ids['Rig History'] ) ) );
	$body = (string) ( rig_mail_to( 'rig-swe-ticked@example.org' )['message'] ?? '' );
	rig_check( 'One ticked box: "You asked to join this group: Rig History."', rig_has( $body, 'You asked to join this group: Rig History.' ) );
	rig_confirm_from( rig_mail_to( 'rig-swe-ticked@example.org' ) );
	$c = $mm->get_member_by_email( 'rig-swe-ticked@example.org' );
	rig_check( 'After confirming, Tia is in Rig History only', $c && array( $group_ids['Rig History'] ) === npmp_pro_get_member_groups( (int) $c->id ) );

	echo "-- [npmp_email_signup groups=\"\" choose=\"yes\"]\n";
	$html = do_shortcode( '[npmp_email_signup groups="" choose="yes"]' );
	preg_match_all( '#<input type="checkbox" name="npmp_signup_groups\[\]"[^>]*>#', $html, $m );
	rig_check( 'One checkbox per open group (2)', 2 === count( $m[0] ) );
	rig_check( 'Every box starts empty (today\'s default)', 0 === substr_count( implode( '', $m[0] ), 'checked' ) );
	$html = do_shortcode( '[npmp_email_signup groups="" choose="yes" checked="yes"]' );
	preg_match_all( '#<input type="checkbox" name="npmp_signup_groups\[\]"[^>]*>#', $html, $m );
	rig_check( 'checked="yes": every box starts ticked', 2 === substr_count( implode( '', $m[0] ), ' checked' ) );
	$html = do_shortcode( '[npmp_email_signup groups="Rig Art" choose="yes" checked="no"]' );
	rig_check( 'checked="no": a named group starts empty', rig_has( $html, 'value="' . $group_ids['Rig Art'] . '">' ) );
	$html = do_shortcode( '[npmp_email_signup groups="Rig Art" choose="yes"]' );
	rig_check( 'No checked attribute: a named group still starts ticked', rig_has( $html, 'value="' . $group_ids['Rig Art'] . '" checked>' ) );
	$html = do_shortcode( '[npmp_email_signup groups=""]' );
	rig_check( 'No choose attribute: no checkboxes at all', ! rig_has( $html, 'npmp_signup_groups' ) );
	$block = render_block( array( 'blockName' => 'nonprofit-manager/email-signup', 'attrs' => array( 'choose' => 'yes', 'checked' => 'yes' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
	rig_check( 'The Email Signup block takes checked too', 2 === substr_count( $block, 'npmp_signup_groups[]" value=' ) && 2 === substr_count( $block, '" checked>' ) );

	// Nothing ticked, double opt-in on.
	$GLOBALS['rig_mails'] = array();
	$where = rig_signup( 'rig-swe-none@example.org', 'Ned None', array( 'choose' => 'yes' ) );
	$body  = (string) ( rig_mail_to( 'rig-swe-none@example.org' )['message'] ?? '' );
	rig_check( 'Nothing ticked: the visitor is told to check their inbox', rig_has( $where, 'npmp_signup=check_email' ) );
	rig_check( 'Nothing ticked: the confirm email names no group', '' !== $body && ! rig_has( $body, 'You asked to join' ) );
	rig_confirm_from( rig_mail_to( 'rig-swe-none@example.org' ) );
	$c = $mm->get_member_by_email( 'rig-swe-none@example.org' );
	rig_check( 'Nothing ticked: after confirming, subscribed to the list', $c && 'subscribed' === $c->status );
	rig_check( 'Nothing ticked: in no group', $c && array() === npmp_pro_get_member_groups( (int) $c->id ) );

	// Nothing ticked, double opt-in off.
	$settings['double_optin'] = 0;
	update_option( 'npmp_membership_form_settings', $settings );
	npmp_get_membership_form_settings( true );
	$where = rig_signup( 'rig-swe-none2@example.org', 'Nell None', array( 'choose' => 'yes' ) );
	$c     = $mm->get_member_by_email( 'rig-swe-none2@example.org' );
	rig_check( 'Opt-in off, nothing ticked: success, no error', rig_has( $where, 'npmp_signup=success' ) );
	rig_check( 'Opt-in off, nothing ticked: subscribed, in no group', $c && 'subscribed' === $c->status && array() === npmp_pro_get_member_groups( (int) $c->id ) );
	$settings['double_optin'] = 1;
	update_option( 'npmp_membership_form_settings', $settings );
	npmp_get_membership_form_settings( true );
}

echo "-- Footer on notifications and the digest\n";
$prefs_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig Preferences', 'post_content' => '[npmp_manage_preferences]' ) );
update_option( 'npmp_preferences_page_id', $prefs_page );
delete_transient( 'npmp_prefs_page_lookup' );
$reader = $mm->add_member( array( 'name' => 'Rae Reader', 'email' => 'rig-swe-reader@example.org', 'status' => 'subscribed', 'membership_level' => 'member' ) );
if ( $pro ) {
	npmp_pro_add_member_to_groups( (int) $reader, array( $group_ids['Rig Art'], $group_ids['Rig History'] ), false );
}
npmp_save_subscriber_preferences( (int) $reader, array( 'notify_posts' => true, 'notify_events' => false, 'weekly_digest' => false ) );
$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Rig news item', 'post_content' => 'Rig body text.' ) );
$GLOBALS['rig_mails'] = array();
npmp_process_post_notification( $post, '_npmp_notify_posts', (int) $reader - 1 );
$mail  = rig_mail_to( 'rig-swe-reader@example.org' );
$body  = (string) ( $mail['message'] ?? '' );
$token = npmp_generate_unsubscribe_token( 'rig-swe-reader@example.org' );
rig_check( 'Notification went out', null !== $mail );
rig_check( 'Notification footer has the postal address', rig_has( $body, '12 Rig Lane' ) );
rig_check( 'Notification footer has the reader\'s own unsubscribe link', rig_has( $body, $token ) );
rig_check( 'Notification keeps the preferences link', rig_has( $body, 'Manage your email preferences' ) );
rig_check( 'Notification carries List-Unsubscribe', rig_has( rig_headers( $mail ), 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );

global $wpdb;
$queue = $wpdb->prefix . 'npmp_digest_queue';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) === $queue || $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='{$queue}'" ) ) {
	$wpdb->query( "DELETE FROM {$queue}" ); // phpcs:ignore
	$wpdb->insert( $queue, array( 'email' => 'rig-swe-reader@example.org', 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ) );
	update_option( 'npmp_digest_pending_content', array( 'subject' => 'Rig digest', 'content' => '<p>Rig digest body</p>' ) );
	$GLOBALS['rig_mails'] = array();
	npmp_drain_digest_batch();
	$mail = rig_mail_to( 'rig-swe-reader@example.org' );
	$body = (string) ( $mail['message'] ?? '' );
	rig_check( 'Digest went out', null !== $mail );
	rig_check( 'Digest footer has the postal address and own unsubscribe link', rig_has( $body, '12 Rig Lane' ) && rig_has( $body, $token ) );
	rig_check( 'Digest keeps the preferences link and List-Unsubscribe', rig_has( $body, 'Manage your email preferences' ) && rig_has( rig_headers( $mail ), 'List-Unsubscribe:' ) );
	$wpdb->query( "DELETE FROM {$queue}" ); // phpcs:ignore
	wp_clear_scheduled_hook( 'npmp_process_digest_queue' );
} else {
	rig_check( 'Digest queue table exists', false );
}

echo "-- [groups] in a newsletter\n";
$nl = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Hi [first_name]', 'post_content' => '<p>Your groups: [groups default="none yet"].</p>' ) );
$GLOBALS['rig_mails'] = array();
NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-swe-reader@example.org' );
$body = (string) ( rig_mail_to( 'rig-swe-reader@example.org' )['message'] ?? '' );
if ( $pro ) {
	rig_check( 'Pro fills [groups] with the reader\'s groups', rig_has( $body, 'Your groups: Rig Art and Rig History.' ) );
	$lone = $mm->add_member( array( 'name' => 'Lou Lone', 'email' => 'rig-swe-lone@example.org', 'status' => 'subscribed', 'membership_level' => 'member' ) );
	$GLOBALS['rig_mails'] = array();
	NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-swe-lone@example.org' );
	rig_check( 'A reader in no group gets the default', rig_has( (string) ( rig_mail_to( 'rig-swe-lone@example.org' )['message'] ?? '' ), 'Your groups: none yet.' ) );
} else {
	rig_check( 'Without Pro, free leaves [groups] alone', rig_has( $body, '[groups' ) );
}
rig_check( 'The newsletter still has its footer', rig_has( $body, '12 Rig Lane' ) );
wp_delete_post( $nl, true );
wp_delete_post( $post, true );

echo "-- Tag help\n";
$suffix = npmp_email_recipient_tag_help_suffix();
rig_check( $pro ? 'Newsletter editor help names [groups]' : 'Newsletter editor help adds nothing in free', $pro ? rig_has( $suffix, '[groups]' ) : '' === $suffix );
if ( function_exists( 'npmp_scheduled_emails_placeholder_help' ) ) {
	$help = npmp_scheduled_emails_placeholder_help();
	rig_check( $pro ? 'Event email placeholders list [groups] and keep [group_name]' : 'Event email placeholders have no [groups] in free', $pro ? ( isset( $help['[groups]'], $help['[group_name]'] ) ) : ! isset( $help['[groups]'] ) );
}

echo "-- Form color\n";
global $wp_styles;
$inline = function () {
	global $wp_styles;
	$wp_styles = new WP_Styles();
	npmp_register_form_styles();
	npmp_forms_accent_add_inline_style();
	return implode( '', (array) $wp_styles->get_data( 'npmp-forms', 'after' ) );
};
delete_option( 'npmp_forms_accent' );
rig_check( 'Nothing saved: no extra CSS', '' === $inline() );
update_option( 'npmp_forms_accent', '#0f766e' );
rig_check( 'Default saved: no extra CSS', '' === $inline() );
// Save through the settings screen as an admin.
wp_set_current_user( 1 );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'npmp_membership_form_save' ), 'npmp_forms_accent' => '#FACC15', 'double_optin' => '1' );
ob_start();
try { npmp_render_membership_forms_page(); } catch ( Throwable $e ) { echo 'ERR ' . $e->getMessage(); }
$screen = ob_get_clean();
$_POST = $_REQUEST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
rig_check( 'Membership Settings saves the color, normalized', '#facc15' === get_option( 'npmp_forms_accent' ) );
rig_check( 'The screen warns that the buttons use dark text', rig_has( $screen, 'your form buttons use dark text' ) );
rig_check( 'The color field shows the saved color', rig_has( $screen, 'name="npmp_forms_accent" id="npmp_forms_accent" value="#facc15"' ) );
$css = $inline();
rig_check( 'Yellow sets the accent and a dark label', rig_has( $css, '--npmp-accent:#facc15;' ) && rig_has( $css, '--npmp-accent-text:#111827;' ) );
$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'npmp_membership_form_save' ), 'npmp_forms_accent' => 'red;}body{x', 'double_optin' => '1' );
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
npmp_render_membership_forms_page();
ob_end_clean();
$_POST = $_REQUEST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
rig_check( 'Junk saves the brand default', '#0f766e' === get_option( 'npmp_forms_accent' ) );
update_option( 'npmp_forms_accent', '#1e3a8a' );
$css = $inline();
rig_check( 'Navy keeps white text and a darker hover', rig_has( $css, '--npmp-accent-text:#ffffff;' ) && rig_has( $css, '--npmp-accent-dark:#182e6e;' ) );

// Clean up.
$sweep();
if ( $pro ) {
	foreach ( $group_ids as $gid ) {
		wp_delete_term( $gid, NPMP_PRO_GROUP_TAXONOMY );
	}
}
echo "-- Signup attributes only Pro supports\n";
// Pete at U3A pasted groups="" choose="yes" onto a form and got a plain form
// with no word about why. Without Pro an admin now reads which attributes the
// form left out. A visitor never does, and with Pro on there is nothing to say.
wp_set_current_user( 1 );
$pro_atts = do_shortcode( '[npmp_email_signup groups="" choose="yes"]' );
if ( $pro ) {
	rig_check( 'Pro on: no note about Pro attributes', ! rig_has( $pro_atts, 'Only administrators see this note.' ) );
	rig_check( 'Pro on: a misspelled attribute still gets named', rig_has( do_shortcode( '[npmp_email_signup grups="art"]' ), 'without the attribute grups.' ) );
} else {
	rig_check( 'Pro off: the admin reads that groups and choose were left out', rig_has( $pro_atts, 'Visitors get this form without the attributes groups, choose.' ) );
	rig_check( 'Pro off: the note sits before the form', strpos( $pro_atts, 'Only administrators see this note.' ) < strpos( $pro_atts, '<form' ) );
}
rig_check( 'a plain form has no note', ! rig_has( do_shortcode( '[npmp_email_signup]' ), 'Only administrators see this note.' ) );
wp_set_current_user( 0 );
rig_check( 'a visitor never sees the note', ! rig_has( do_shortcode( '[npmp_email_signup groups="" choose="yes" grups="x"]' ), 'Only administrators' ) );
wp_set_current_user( 1 );

wp_delete_post( $prefs_page, true );
foreach ( $keep_options as $opt => $value ) {
	if ( null === $value ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $value );
	}
}
delete_transient( 'npmp_prefs_page_lookup' );

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
if ( $GLOBALS['rig_fail'] ) {
	exit( 1 );
}
