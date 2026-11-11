<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/email-tags.php
//
// Per-recipient and event tags on a real WordPress, through the real queue.
// A newsletter with [first_name default="Member"] in its subject, preheader,
// body and template reaches each contact with their own name, and an event
// email built from a body with [event_image] and [event_excerpt words="8"]
// carries the featured image and the cut description. The subject arrives as
// plain text, not with HTML codes for its apostrophe. Needs the newsletter and
// calendar modules on (option npmp_enabled_features). Leaves nothing behind.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
if ( ! class_exists( 'NPMP_Newsletter_Manager' ) || ! post_type_exists( 'npmp_event' ) ) {
	fwrite( STDERR, "Turn on the newsletters and calendar modules first.\n" );
	exit( 2 );
}

$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[ $atts['to'] ] = $atts; return true; }, 10, 2 );

$mm      = NPMP_Member_Manager::get_instance();
$people  = array( 'rig-tags-ada@example.org' => 'Ada Lovelace', 'rig-tags-anon@example.org' => '' );
$cleanup = array();
foreach ( $people as $email => $name ) {
	$old = $mm->get_member_by_email( $email );
	if ( $old ) {
		$mm->delete_member( $old->id );
	}
	$cleanup[] = $mm->add_member( array( 'email' => $email, 'name' => $name, 'status' => 'subscribed' ) );
}
// Only these two get the newsletter, whatever else the rig site holds.
add_filter( 'npmp_newsletter_recipients', function ( $recipients ) use ( $people ) {
	return array_values( array_filter( $recipients, function ( $r ) use ( $people ) { return isset( $people[ $r->user_email ?? '' ] ); } ) );
}, 99 );

echo "== Newsletter with name tags ==\n";
$template = wp_insert_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig wrapper', 'post_content' => '<p>Dear [first_name default="Member"],</p>[email_content]<p>Thanks, [name default="friend"]!</p>' ) );
update_post_meta( $template, '_npmp_template_type', 'wrapper' );
$nl = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => "Don't miss this, [first_name default=\"Member\"]", 'post_content' => '<!-- wp:paragraph --><p>Your surname on file is [last_name default="not set"].</p><!-- /wp:paragraph -->' ) );
update_post_meta( $nl, '_npmp_newsletter_template', (string) $template );
update_post_meta( $nl, '_npmp_newsletter_levels', array( '__all__' ) );
update_post_meta( $nl, '_npmp_newsletter_preheader', 'News for [first_name default="you"]' );

NPMP_Newsletter_Manager::queue_newsletter( $nl );
NPMP_Newsletter_Manager::process_queue();

$ada  = $mails['rig-tags-ada@example.org'] ?? null;
$anon = $mails['rig-tags-anon@example.org'] ?? null;
rig_check( 'Both contacts were mailed', true, $ada && $anon );
rig_check( 'Subject has the name, plain apostrophe', "Don\u{2019}t miss this, Ada", $ada['subject'] ?? null );
rig_check( 'Subject default for a contact with no name', "Don\u{2019}t miss this, Member", $anon['subject'] ?? null );
rig_check( 'Template greeting has the first name', true, false !== strpos( $ada['message'] ?? '', 'Dear Ada,' ) );
rig_check( 'Body has the last name', true, false !== strpos( $ada['message'] ?? '', 'Your surname on file is Lovelace.' ) );
rig_check( 'Template sign-off has the full name', true, false !== strpos( $ada['message'] ?? '', 'Thanks, Ada Lovelace!' ) );
rig_check( 'Preheader has the first name', true, false !== strpos( $ada['message'] ?? '', 'News for Ada' ) );
rig_check( 'No name: greeting default', true, false !== strpos( $anon['message'] ?? '', 'Dear Member,' ) );
rig_check( 'No name: body default', true, false !== strpos( $anon['message'] ?? '', 'Your surname on file is not set.' ) );
rig_check( 'No tag is left in either message', 0, preg_match( '/\[(first_name|last_name|name)\b/', ( $ada['message'] ?? '' ) . ( $anon['message'] ?? '' ) ) );

echo "== Event email with image and excerpt ==\n";
$uploads = wp_upload_dir();
$img     = imagecreatetruecolor( 1200, 800 );
$path    = trailingslashit( $uploads['path'] ) . 'rig-event-hall.jpg';
imagejpeg( $img, $path );
$attach = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Hall', 'post_status' => 'inherit' ), $path );
wp_update_attachment_metadata( $attach, wp_generate_attachment_metadata( $attach, $path ) );
$event = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig Talk', 'post_content' => '<!-- wp:paragraph --><p>Join us for an evening talk about the history of the old town hall and its clock.</p><!-- /wp:paragraph -->', 'meta_input' => array( '_npmp_event_start' => gmdate( 'Y-m-d 19:30:00', time() + WEEK_IN_SECONDS ) ) ) );
set_post_thumbnail( $event, $attach );
$body = wp_insert_post( array( 'post_type' => 'npmp_email_body', 'post_status' => 'publish', 'post_title' => 'Rig body', 'post_content' => '<p>Hi [first_name default="Member"],</p><p>[event_image]</p><p>[event_excerpt words="8"]</p>' ) );
update_post_meta( $body, '_npmp_body_subject', '[event_title] next week' );
$row  = (object) array( 'id' => 0, 'body_id' => $body, 'subject' => '', 'audience_type' => 'all', 'audience_id' => 0 );
$built = npmp_scheduled_emails_build_newsletter( $row, $event );
$html  = (string) get_post_field( 'post_content', $built );
$large = wp_get_attachment_image_src( $attach, 'large' );
rig_check( 'Built a newsletter', true, $built > 0 );
rig_check( 'Featured image is in the email', true, false !== strpos( $html, '<img src="' . $large[0] . '"' ) );
rig_check( 'Image capped at 600 wide', true, false !== strpos( $html, 'width="600"' ) );
if ( getenv( 'RIG_DEBUG' ) ) { echo $html, "\n"; }
rig_check( 'Excerpt cut to 8 words', true, false !== strpos( $html, "Join us for an evening talk about the\u{2026}" ) );
rig_check( '[first_name] is left for the send', true, false !== strpos( $html, '[first_name default="Member"]' ) );
$mails = array();
update_post_meta( $built, '_npmp_newsletter_template', 'none' );
NPMP_Newsletter_Manager::queue_newsletter( $built );
NPMP_Newsletter_Manager::process_queue();
rig_check( 'Event email greets Ada by name', true, false !== strpos( $mails['rig-tags-ada@example.org']['message'] ?? '', 'Hi Ada,' ) );
rig_check( 'Event email subject', 'Rig Talk next week', $mails['rig-tags-ada@example.org']['subject'] ?? null );

foreach ( array( $nl, $template, $built, $body, $event ) as $id ) {
	wp_delete_post( $id, true );
}
wp_delete_attachment( $attach, true );
foreach ( $cleanup as $id ) {
	$mm->delete_member( $id );
}
rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
