<?php
/**
 * Meeting details on an event (includes/npmp-event-meeting.php, the Event
 * Details box and npmp_get_event_details() in includes/npmp-calendar.php, and
 * the [meeting_id] and [passcode] tags in includes/npmp-scheduled-emails.php).
 *
 * Pete on wordpress.org (topic "meeting-links") asked for a meeting ID and
 * passcode beside the link, and for a way to keep the link off the website so
 * a meeting can't be zoom-bombed. Checks: the saved values, the email-only
 * box blanking all three for web pages and never for emails, the add-on
 * filter, the box fields, the event page, the two tags and their help, a line
 * with an empty tag leaving the email cleanly, and the "Tomorrow" body reading
 * exactly as before for an event with no meeting ID or passcode.
 *
 * Run: php tests/test-event-meeting.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

$GLOBALS['t_options'] = array();
$GLOBALS['t_meta']    = array();
$GLOBALS['t_filters'] = array();
$GLOBALS['t_singular'] = false;

function add_action() {
	return true;
}
function add_shortcode() {
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action() {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['t_meta'][ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['t_meta'][ $id ][ $key ] );
	return true;
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}
function wp_unslash( $v ) {
	return $v;
}
function esc_url_raw( $url ) {
	return preg_match( '#^https?://#', (string) $url ) ? (string) $url : '';
}
function wp_verify_nonce() {
	return true;
}
function current_user_can() {
	return true;
}
function wp_nonce_field() {}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( $text ) {
	return $text;
}
function esc_url( $url ) {
	return preg_match( '#^https?://#', (string) $url ) ? str_replace( array( '"', "'", ' ' ), array( '%22', '%27', '%20' ), (string) $url ) : '';
}
function checked( $a, $b = true, $echo = true ) {
	return (string) $a === (string) $b ? ' checked=\'checked\'' : '';
}
function wp_kses_post( $html ) {
	return $html;
}
function _n( $single, $plural, $n, $domain = null ) {
	return 1 === (int) $n ? $single : $plural;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/London' );
}
function wp_date( $format, $ts, $tz = null ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
}
function get_permalink( $id = 0 ) {
	return 'https://example.org/events/meeting-' . (int) $id . '/';
}
function get_post_field( $field, $id, $context = 'display' ) {
	return 'Book Club';
}
function home_url( $path = '' ) {
	return 'https://example.org' . $path;
}
function add_query_arg( $key, $value = null, $url = null ) {
	return $url . '?' . $key . '=' . $value;
}
function strip_shortcodes( $text ) {
	return preg_replace( '/\[[^\]]+\]/', '', (string) $text );
}
function get_post_thumbnail_id( $id ) {
	return 0;
}
function is_singular( $type = '' ) {
	return $GLOBALS['t_singular'];
}
function in_the_loop() {
	return true;
}
function is_main_query() {
	return true;
}
function get_the_ID() {
	return $GLOBALS['t_current'];
}

require_once dirname( __DIR__ ) . '/includes/npmp-org-type.php';
require_once dirname( __DIR__ ) . '/includes/npmp-event-meeting.php';
require_once dirname( __DIR__ ) . '/includes/npmp-scheduled-emails.php';
require_once dirname( __DIR__ ) . '/includes/npmp-calendar.php';

$GLOBALS['t_options']['date_format'] = 'l j F Y';
$GLOBALS['t_options']['time_format'] = 'g:i a';

// 21: Zoom details, kept for emails. 22: the same, shown on the site.
// 23: no meeting details at all (an event from before this release).
$GLOBALS['t_meta'][21] = array(
	'_npmp_event_start'          => '2026-11-20 19:30:00',
	'_npmp_event_location'       => 'Online',
	'_npmp_event_url'            => 'https://zoom.example/j/8675309',
	'_npmp_event_meeting_id'     => '867 5309 1234',
	'_npmp_event_passcode'       => 'tuesday',
	'_npmp_event_link_email_only' => '1',
);
$GLOBALS['t_meta'][22]                                = $GLOBALS['t_meta'][21];
$GLOBALS['t_meta'][22]['_npmp_event_link_email_only'] = '';
$GLOBALS['t_meta'][23]                                = array(
	'_npmp_event_start'    => '2026-11-20 19:30:00',
	'_npmp_event_location' => 'Hall',
	'_npmp_event_url'      => 'https://example.org/tickets',
);

echo "Saved values\n";
check( 'meta key names', array( '_npmp_event_meeting_id', '_npmp_event_passcode', '_npmp_event_link_email_only' ), array( NPMP_EVENT_MEETING_ID_META, NPMP_EVENT_PASSCODE_META, NPMP_EVENT_EMAIL_ONLY_META ) );
check( 'own details', array( 'url' => 'https://zoom.example/j/8675309', 'meeting_id' => '867 5309 1234', 'passcode' => 'tuesday', 'email_only' => true ), npmp_event_meeting_own( 21 ) );
check( 'an old event has none', array( 'url' => 'https://example.org/tickets', 'meeting_id' => '', 'passcode' => '', 'email_only' => false ), npmp_event_meeting_own( 23 ) );
check( 'clean keeps one line', 'abc def', npmp_event_meeting_clean( " abc\n<b>def</b> " ) );
check( 'clean caps at 100', 100, strlen( npmp_event_meeting_clean( str_repeat( 'x', 300 ) ) ) );
check( 'clean refuses an array', '', npmp_event_meeting_clean( array( 'x' ) ) );

echo "Web pages get nothing from an email-only event\n";
$pub = npmp_get_event_details( 21 );
check( 'link blank', '', $pub['url'] );
check( 'meeting ID blank', '', $pub['meeting_id'] );
check( 'passcode blank', '', $pub['passcode'] );
check( 'location stays', 'Online', $pub['location'] );
check( 'start stays', '2026-11-20 19:30:00', $pub['start'] );
$all = npmp_get_event_details( 21, true );
check( 'asked for: link', 'https://zoom.example/j/8675309', $all['url'] );
check( 'asked for: passcode', 'tuesday', $all['passcode'] );
$shown = npmp_get_event_details( 22 );
check( 'box off: link shows', 'https://zoom.example/j/8675309', $shown['url'] );
check( 'box off: meeting ID shows', '867 5309 1234', $shown['meeting_id'] );
check( 'an old event: link as before', 'https://example.org/tickets', npmp_get_event_details( 23 )['url'] );

echo "Event page\n";
$GLOBALS['t_singular'] = true;
$GLOBALS['t_current']  = 21;
$page = npmp_append_event_details_to_content( '<p>Body</p>' );
foreach ( array( 'zoom.example', '867 5309 1234', 'tuesday', 'Meeting ID', 'Passcode' ) as $needle ) {
	check( "email-only page has no \"$needle\"", false, strpos( $page, $needle ) );
}
check( 'email-only page still says where', true, false !== strpos( $page, '<strong>Where:</strong> Online' ) );
$GLOBALS['t_current'] = 22;
$page = npmp_append_event_details_to_content( '<p>Body</p>' );
check( 'shown page has the link', true, false !== strpos( $page, 'href="https://zoom.example/j/8675309"' ) );
check( 'shown page has the meeting ID', true, false !== strpos( $page, '<li><strong>Meeting ID:</strong> 867 5309 1234</li>' ) );
check( 'shown page has the passcode', true, false !== strpos( $page, '<li><strong>Passcode:</strong> tuesday</li>' ) );
$GLOBALS['t_current'] = 23;
$page = npmp_append_event_details_to_content( '<p>Body</p>' );
check(
	'an old event renders exactly as before',
	'<p>Body</p><section class="npmp-event-details"><h2>Event Details</h2><ul><li><strong>Where:</strong> Hall</li><li><strong>More information:</strong> <a href="https://example.org/tickets" target="_blank" rel="noopener">https://example.org/tickets</a></li></ul></section>',
	(string) preg_replace( '#<li><strong>When:</strong>.*?</li>#', '', $page )
);
$GLOBALS['t_singular'] = false;

echo "Event Details box\n";
ob_start();
npmp_render_event_meta_box( (object) array( 'ID' => 21 ) );
$box = (string) ob_get_clean();
check( 'meeting ID field after the link', true, strpos( $box, 'name="npmp_event_url"' ) < strpos( $box, 'name="npmp_event_meeting_id" value="867 5309 1234"' ) );
check( 'passcode field', true, false !== strpos( $box, 'name="npmp_event_passcode" value="tuesday"' ) );
check( 'email-only box ticked', true, false !== strpos( $box, 'name="npmp_event_link_email_only" value="1" checked' ) );
check( 'box label', true, false !== strpos( $box, 'Show the meeting link only in emails' ) );

echo "Saving the box\n";
$GLOBALS['t_meta'][30] = array( '_npmp_event_meeting_id' => 'old', '_npmp_event_link_email_only' => '1' );
$_POST                 = array(
	'npmp_event_details_nonce'   => 'x',
	'npmp_event_start_date'      => '2026-12-01',
	'npmp_event_url'             => 'https://zoom.example/j/1',
	'npmp_event_meeting_id'      => " 111 222\n333 ",
	'npmp_event_passcode'        => '<i>pass</i>',
	'npmp_event_link_email_only' => '1',
);
npmp_save_event_meta( 30 );
check( 'meeting ID saved clean', '111 222 333', get_post_meta( 30, '_npmp_event_meeting_id' ) );
check( 'passcode saved clean', 'pass', get_post_meta( 30, '_npmp_event_passcode' ) );
check( 'box saved as 1', '1', get_post_meta( 30, '_npmp_event_link_email_only' ) );
$_POST = array(
	'npmp_event_details_nonce' => 'x',
	'npmp_event_start_date'    => '2026-12-01',
);
npmp_save_event_meta( 30 );
check( 'empty fields delete the meta', array( '_npmp_event_start' ), array_keys( $GLOBALS['t_meta'][30] ) );
$_POST = array();

echo "Tags\n";
$help = npmp_scheduled_emails_placeholder_help();
$keys = array_keys( $help );
check( '[meeting_id] right after [join_link]', array_search( '[join_link]', $keys, true ) + 1, array_search( '[meeting_id]', $keys, true ) );
check( '[passcode] right after [meeting_id]', array_search( '[meeting_id]', $keys, true ) + 1, array_search( '[passcode]', $keys, true ) );
foreach ( array( '[meeting_id]', '[passcode]' ) as $tag ) {
	check( "$tag help has no em dash or semicolon", 0, preg_match( '/\x{2014}|;/u', $help[ $tag ] ) );
}
$map = npmp_scheduled_emails_placeholders( 21 );
check( 'emails get the link of an email-only event', 'https://zoom.example/j/8675309', $map['[join_link]'] );
check( 'emails get the meeting ID', '867 5309 1234', $map['[meeting_id]'] );
check( 'emails get the passcode', 'tuesday', $map['[passcode]'] );
$map23 = npmp_scheduled_emails_placeholders( 23 );
check( 'an old event: join link as before', 'https://example.org/tickets', $map23['[join_link]'] );
check( 'an old event: no meeting ID', '', $map23['[meeting_id]'] );

echo "An add-on fills empty fields for emails only\n";
add_filter(
	'npmp_event_meeting_details',
	function ( $d, $id ) {
		if ( 24 === $id && '' === $d['url'] ) {
			$d['url']        = 'https://zoom.example/j/group';
			$d['meeting_id'] = '999';
			$d['email_only'] = true;
		}
		return $d;
	},
	10,
	2
);
$GLOBALS['t_meta'][24] = array( '_npmp_event_start' => '2026-11-20 19:30:00' );
$map24                 = npmp_scheduled_emails_placeholders( 24 );
check( 'filled link reaches [join_link]', 'https://zoom.example/j/group', $map24['[join_link]'] );
check( 'filled ID reaches [meeting_id]', '999', $map24['[meeting_id]'] );
check( 'filled values make the event private', true, npmp_event_meeting_is_private( 24 ) );
check( 'the event page never sees filled values', '', npmp_get_event_details( 24 )['url'] );
add_filter(
	'npmp_event_meeting_details',
	function ( $d ) {
		$d['email_only'] = false;
		return $d;
	}
);
check( 'a filter can\'t clear the event\'s own box', true, npmp_event_meeting_is_private( 21 ) );
check( 'a filter returning junk keeps the own values', 'tuesday', ( function () {
	add_filter( 'npmp_event_meeting_details', '__return_null_for_test' );
	$d = npmp_event_meeting_details( 21 );
	$GLOBALS['t_filters']['npmp_event_meeting_details'] = array();
	return $d['passcode'];
} )() );
function __return_null_for_test() {
	return null;
}

echo "Empty tags leave the email cleanly\n";
$lines = '<!-- wp:paragraph --><p><strong>Meeting ID:</strong> [meeting_id]<br><strong>Passcode:</strong> [passcode]</p><!-- /wp:paragraph -->';
check( 'both set', '<!-- wp:paragraph --><p><strong>Meeting ID:</strong> 1 2 3<br><strong>Passcode:</strong> abc</p><!-- /wp:paragraph -->', npmp_scheduled_emails_fill( $lines, array( '[meeting_id]' => '1 2 3', '[passcode]' => 'abc' ) ) );
check( 'no ID: its line goes', '<!-- wp:paragraph --><p><strong>Passcode:</strong> abc</p><!-- /wp:paragraph -->', npmp_scheduled_emails_fill( $lines, array( '[meeting_id]' => '', '[passcode]' => 'abc' ) ) );
check( 'no passcode: its line goes', '<!-- wp:paragraph --><p><strong>Meeting ID:</strong> 1 2 3</p><!-- /wp:paragraph -->', npmp_scheduled_emails_fill( $lines, array( '[meeting_id]' => '1 2 3', '[passcode]' => '' ) ) );
check( 'neither: the whole block goes', 'A<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->', npmp_scheduled_emails_fill( 'A' . $lines . "\n\n" . '<!-- wp:paragraph --><p>B</p><!-- /wp:paragraph -->', array( '[meeting_id]' => '', '[passcode]' => '' ) ) );
check( 'two paragraphs, one empty', '<p>Passcode: abc</p>', npmp_scheduled_emails_fill( '<p>ID: [meeting_id]</p><p>Passcode: [passcode]</p>', array( '[meeting_id]' => ' ', '[passcode]' => 'abc' ) ) );
check( 'a list item goes', '<ul><li>Link</li></ul>', npmp_scheduled_emails_fill( '<ul><li>Link</li><li>ID [meeting_id]</li></ul>', array( '[meeting_id]' => '' ) ) );
check( 'a heading just loses the tag', '<h3>Meeting </h3>', npmp_scheduled_emails_fill( '<h3>Meeting [meeting_id]</h3>', array( '[meeting_id]' => '' ) ) );
check( 'plain text lines', "Hi\nSee you", npmp_scheduled_emails_fill( "Hi\nMeeting ID: [meeting_id]\nSee you", array( '[meeting_id]' => '' ) ) );
check( 'subject loses the tag and its space', 'Book Club', npmp_scheduled_emails_fill( 'Book Club [meeting_id]', array( '[meeting_id]' => '' ), false ) );
check( 'value escaped in HTML', '<p>ID &lt;b&gt;</p>', npmp_scheduled_emails_fill( '<p>ID [meeting_id]</p>', array( '[meeting_id]' => '<b>' ) ) );
check( 'other empty tags untouched', '<p>Where: </p>', npmp_scheduled_emails_fill( '<p>Where: [event_location]</p>', array( '[event_location]' => '' ) ) );
check( 'a body with no meeting tag is untouched', '<p>x</p>', npmp_scheduled_emails_fill( '<p>x</p>', array( '[meeting_id]' => '' ) ) );

echo "The Tomorrow body\n";
$new = npmp_scheduled_emails_builtin_bodies()['tomorrow']['content'];
$old = npmp_scheduled_emails_builtin_bodies( false )['tomorrow']['content'];
check( 'new body has both tags', true, false !== strpos( $new, '[meeting_id]' ) && false !== strpos( $new, '[passcode]' ) );
check( 'old body has neither', false, strpos( $old, '[meeting' ) );
check( 'tags sit after the join link', true, strpos( $new, '[join_link]' ) < strpos( $new, '[meeting_id]' ) );
check( 'next week body unchanged', npmp_scheduled_emails_builtin_bodies( false )['next_week'], npmp_scheduled_emails_builtin_bodies()['next_week'] );
check( 'an event with no ID or passcode gets the same email as before', npmp_scheduled_emails_fill( $old, $map23 ), npmp_scheduled_emails_fill( $new, $map23 ) );
$filled = npmp_scheduled_emails_fill( $new, $map );
check( 'an email-only event\'s email has the link', true, false !== strpos( $filled, 'href="https://zoom.example/j/8675309"' ) );
check( 'and the meeting ID', true, false !== strpos( $filled, '<strong>Meeting ID:</strong> 867 5309 1234' ) );
check( 'and the passcode', true, false !== strpos( $filled, '<strong>Passcode:</strong> tuesday' ) );
check( 'no tag left', 0, preg_match( '/\[(meeting_id|passcode|join_link)\]/', $filled ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
