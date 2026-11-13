<?php
/**
 * Real-WordPress check of the event editor's Email Template box
 * (includes/email-newsletter/templates.php) and the wrapper an event's email
 * comes out in (npmp_scheduled_emails_pick_template).
 *
 * Peter Lord compared the two editors and asked for the newsletter editor's
 * template picker on the event editor too (ROS-4473, 2026-11-13). Stub tests
 * can't see which column WordPress puts a box in, nor whether the editor's
 * own POST saves it, so this drives both against the real meta box registry
 * and the real save_post chain, then renders a test email to prove the choice
 * reaches the wrapper.
 *
 * Run inside a throwaway WordPress with members, newsletters and the calendar
 * on (option npmp_enabled_features):
 *   wp eval-file tests/rig/event-template-picker.php
 * Run it with Pro active and with Pro inactive: Pro draws its own Scheduled
 * Emails box, and this box has to survive that. It deletes what it makes.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_render_template_picker' ) || ! function_exists( 'npmp_scheduled_emails_pick_template' ) || ! post_type_exists( 'npmp_event' ) ) {
	echo "  FAIL  newsletters and the calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}
$pro = function_exists( 'npmp_pro_get_groups' );
echo $pro ? "Pro is active\n" : "Pro is inactive\n";

// An admin context, or add_meta_boxes never fires and current_user_can fails.
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

$GLOBALS['rig_made'] = array();

$wrapper = wp_insert_post(
	array(
		'post_type'    => 'npmp_nl_template',
		'post_status'  => 'publish',
		'post_title'   => 'Rig wrapper',
		'post_content' => '<div class="rig-wrap">[email_content]</div>',
	)
);
update_post_meta( $wrapper, '_npmp_template_type', 'wrapper' );
$GLOBALS['rig_made'][] = $wrapper;

$fallback = wp_insert_post(
	array(
		'post_type'    => 'npmp_nl_template',
		'post_status'  => 'publish',
		'post_title'   => 'Rig default',
		'post_content' => '<div class="rig-default">[email_content]</div>',
	)
);
update_post_meta( $fallback, '_npmp_template_type', 'wrapper' );
update_post_meta( $fallback, '_npmp_is_default_template', '1' );
$GLOBALS['rig_made'][] = $fallback;

$event = wp_insert_post(
	array(
		'post_type'    => 'npmp_event',
		'post_status'  => 'publish',
		'post_title'   => 'Rig AGM',
		'post_content' => '<p>The AGM is in the hall.</p>',
	)
);
update_post_meta( $event, '_npmp_event_start', gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );
$GLOBALS['rig_made'][] = $event;

// Read at exit, not now, so anything made later is deleted too.
register_shutdown_function(
	static function () {
		foreach ( $GLOBALS['rig_made'] as $rig_id ) {
			wp_delete_post( $rig_id, true );
		}
	}
);

echo "\nWhere WordPress puts the box\n";
set_current_screen( 'npmp_event' );
$GLOBALS['wp_meta_boxes'] = array();
$event_post               = get_post( $event );
do_action( 'add_meta_boxes', 'npmp_event', $event_post );
do_action( 'add_meta_boxes_npmp_event', $event_post );
$side = array_merge(
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['side']['high'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['side']['default'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['side']['low'] ?? array() )
);
$below = array_merge(
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['normal']['high'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['normal']['default'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['normal']['low'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_event']['advanced']['default'] ?? array() )
);
printf( "  sidebar: %s\n", implode( ', ', $side ) );
printf( "  below the content: %s\n", $below ? implode( ', ', $below ) : '(none)' );
$ok( 'Email Template is in the sidebar', in_array( 'npmp_event_email_template', $side, true ) );
$ok( 'Event Details is in the sidebar too', in_array( 'npmp-event-details', $side, true ) );
$ok( 'no settings box is left below the content', ! array_intersect( array( 'npmp_event_email_template', 'npmp-event-details' ), $below ) );

// The newsletter editor is what Peter compared it against.
$GLOBALS['wp_meta_boxes'] = array();
set_current_screen( 'npmp_newsletter' );
$newsletter = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig newsletter' ) );
$GLOBALS['rig_made'][] = $newsletter;
do_action( 'add_meta_boxes', 'npmp_newsletter', get_post( $newsletter ) );
do_action( 'add_meta_boxes_npmp_newsletter', get_post( $newsletter ) );
$nl_side = array_merge(
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_newsletter']['side']['high'] ?? array() ),
	array_keys( $GLOBALS['wp_meta_boxes']['npmp_newsletter']['side']['default'] ?? array() )
);
$ok( 'the newsletter editor still has its own picker', in_array( 'npmp_newsletter_template', $nl_side, true ) );

// Both editors now print one shared picker, so this is the check that the
// newsletter editor did not change while the event editor gained one.
update_post_meta( $newsletter, '_npmp_newsletter_template', $wrapper );
ob_start();
npmp_newsletter_template_meta_box( get_post( $newsletter ) );
$nl_html = ob_get_clean();
$ok( 'it still lists the templates', false !== strpos( $nl_html, 'Rig wrapper' ) && false !== strpos( $nl_html, 'Rig default' ) );
$ok( 'it still posts its own field name', false !== strpos( $nl_html, 'name="npmp_newsletter_template"' ) );
// WordPress prints selected='selected' in single quotes, so match either.
$ok( 'it still reopens on the saved template', 1 === preg_match( '#<option value="' . $wrapper . '"\s+selected=.selected.#', $nl_html ) );
$ok( 'and it carries no event wording', false === strpos( $nl_html, 'this event' ) );

echo "\nWhat the box offers\n";
set_current_screen( 'npmp_event' );
ob_start();
npmp_event_email_template_meta_box( $event_post );
$html = ob_get_clean();
$ok( 'both rig templates are listed', false !== strpos( $html, '<option value="' . $wrapper . '"' ) && false !== strpos( $html, '<option value="' . $fallback . '"' ) );
$ok( 'the default is named', false !== strpos( $html, 'Rig default' ) && false !== strpos( $html, 'Current default template:' ) );
$ok( 'the box carries its nonce', false !== strpos( $html, 'name="npmp_event_email_template_nonce"' ) );
$ok( 'no reusable block is offered', false === strpos( $html, 'Rig block' ) );

echo "\nThe editor's own POST saves it\n";
$_POST                                          = array();
$_POST['npmp_event_email_template_nonce']       = wp_create_nonce( 'npmp_event_email_template' );
$_POST['npmp_event_email_template']             = (string) $wrapper;
// A block-editor save posts the meta boxes to post.php, which runs save_post.
do_action( 'save_post_npmp_event', $event, $event_post, true );
$ok( 'the chosen template is saved', (int) $wrapper === (int) get_post_meta( $event, '_npmp_event_email_template', true ) );

echo "\nThe choice reaches the email\n";
$body = npmp_scheduled_emails_get_body( 0 );
$ok( 'the event choice is what the send will use', (string) $wrapper === npmp_scheduled_emails_pick_template( $event, $body ) );
$rendered = npmp_scheduled_emails_render_test( $event, 0, '', 'rig@example.org' );
$ok( 'the test email comes out in the chosen wrapper', false !== strpos( $rendered['html'], 'rig-wrap' ) );
$ok( 'and not in the site default', false === strpos( $rendered['html'], 'rig-default' ) );
$ok( 'the body is still in there', false !== strpos( $rendered['html'], 'Rig AGM' ) || false !== strpos( $rendered['html'], 'the hall' ) );

// "No Template (Plain Content)" has to beat the site default, or an event
// could never opt out of a wrapper every other email gets.
$_POST['npmp_event_email_template'] = 'none';
do_action( 'save_post_npmp_event', $event, $event_post, true );
$rendered = npmp_scheduled_emails_render_test( $event, 0, '', 'rig@example.org' );
$ok( '"No Template" sends with no wrapper at all', false === strpos( $rendered['html'], 'rig-wrap' ) && false === strpos( $rendered['html'], 'rig-default' ) );

// Back to "Use Default Template": the site default has to come back.
$_POST['npmp_event_email_template'] = '';
do_action( 'save_post_npmp_event', $event, $event_post, true );
$ok( 'the default reads as no choice', '' === npmp_scheduled_emails_pick_template( $event, $body ) );
$rendered = npmp_scheduled_emails_render_test( $event, 0, '', 'rig@example.org' );
$ok( 'and the site default wraps it again', false !== strpos( $rendered['html'], 'rig-default' ) );

// A stale ID, from a template someone deleted, must not drop the body. The
// body here is the built-in "Tomorrow" one, which carries the event title
// rather than its text, so that is what proves the body survived.
update_post_meta( $event, '_npmp_event_email_template', 999999 );
$rendered = npmp_scheduled_emails_render_test( $event, 0, '', 'rig@example.org' );
$ok( 'a deleted template still sends the body', false !== strpos( $rendered['html'], 'Rig AGM' ) );
$ok( 'and adds no wrapper it cannot find', false === strpos( $rendered['html'], 'rig-wrap' ) );

// A save with no nonce (an autosave, a REST write) leaves the meta alone.
update_post_meta( $event, '_npmp_event_email_template', $wrapper );
$_POST = array();
do_action( 'save_post_npmp_event', $event, $event_post, true );
$ok( 'a POST without the nonce changes nothing', (int) $wrapper === (int) get_post_meta( $event, '_npmp_event_email_template', true ) );

printf( "\n%s\n", $fails ? "$fails failed" : 'all passed' );
