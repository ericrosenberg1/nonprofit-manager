<?php
/**
 * Event email content and the event box's plan (includes/npmp-scheduled-emails.php).
 *
 * Pete at U3A, wordpress.org thread "Can you add more template shortcodes ?"
 * (2026-11-12): an event with three paragraphs reached members as one,
 * since [event_excerpt] squeezed the description into one line, and no tag
 * gave the whole description. Thread "Email schedule on event page"
 * (2026-11-13): a new event's box said only "Save the event to schedule its
 * emails", so nothing said when they'd go.
 *
 * The full send path runs on a real WordPress in tests/rig/event-email-content.php.
 *
 * Run: php tests/test-event-email-content.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array(
	'date_format' => 'l j F Y',
	'time_format' => 'g:i a',
);
$GLOBALS['t_meta']    = array();
$GLOBALS['t_fields']  = array();
$GLOBALS['t_status']  = array();

function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function get_post_field( $field, $id, $context = 'display' ) {
	return $GLOBALS['t_fields'][ $id ][ $field ] ?? '';
}
function get_post_status( $id ) {
	return $GLOBALS['t_status'][ $id ] ?? 'publish';
}
function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	return trim( strip_tags( $text ) );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return preg_match( '#^https?://#', (string) $url ) ? str_replace( array( '"', "'", ' ' ), array( '%22', '%27', '%20' ), (string) $url ) : '';
}
function _n( $single, $plural, $n, $domain = null ) {
	return 1 === (int) $n ? $single : $plural;
}
function absint( $n ) {
	return abs( (int) $n );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/London' );
}
function wp_timezone_string() {
	return 'Europe/London';
}
function wp_date( $format, $ts, $tz = null ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
}
function strip_shortcodes( $text ) {
	return preg_replace( '/\[[^\]]+\]/', '', (string) $text );
}
function npmp_label( $key ) {
	return 'members';
}

require_once dirname( __DIR__ ) . '/includes/npmp-scheduled-emails.php';

echo "Excerpt keeps the description's paragraphs\n";
$three = "<!-- wp:paragraph -->\n<p>Description of the event</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>New line</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Another new line</p>\n<!-- /wp:paragraph -->";
check( 'three blocks, three paragraphs', "Description of the event\n\nNew line\n\nAnother new line", npmp_scheduled_emails_text_with_breaks( $three ) );
check( 'classic editor blank lines stay paragraphs', "One.\n\nTwo.", npmp_scheduled_emails_text_with_breaks( "One.\n\nTwo." ) );
check( 'a <br> stays a line break', "Hall A\nUpstairs", npmp_scheduled_emails_text_with_breaks( '<p>Hall A<br />Upstairs</p>' ) );
check( 'list items one per line', "Bring:\n\nTea\nCake", npmp_scheduled_emails_text_with_breaks( "<p>Bring:</p>\n<ul>\n<li>Tea</li>\n<li>Cake</li>\n</ul>" ) );
check( 'spaces inside a line collapse', 'A & B', npmp_scheduled_emails_text_with_breaks( "<p>A  &amp;\tB</p>" ) );
check( 'no stray space between inline tags', 'Bold and italic', npmp_scheduled_emails_text_with_breaks( '<p><strong>Bold</strong> <em>and</em> italic</p>' ) );

$GLOBALS['t_fields'][20] = array( 'post_content' => $three );
check( 'excerpt source of a block event', "Description of the event\n\nNew line\n\nAnother new line", npmp_scheduled_emails_excerpt_source( 20 ) );
$GLOBALS['t_fields'][21] = array( 'post_excerpt' => "Hand-written.\n\nSecond part.", 'post_content' => 'ignored' );
check( 'a hand-written excerpt wins', "Hand-written.\n\nSecond part.", npmp_scheduled_emails_excerpt_source( 21 ) );

check( 'trim keeps breaks', "One two\n\nthree\u{2026}", npmp_scheduled_emails_trim_words( "One two\n\nthree four", 3 ) );
check( 'trim under the limit keeps everything', "One\n\nTwo", npmp_scheduled_emails_trim_words( "One\n\nTwo", 55 ) );
check( 'trim at a paragraph end leaves no blank line', "One two\u{2026}", npmp_scheduled_emails_trim_words( "One two.\n\nThree", 2 ) );

$map = array( '[event_excerpt]' => npmp_scheduled_emails_excerpt_source( 20 ) );
check( 'tag alone in a paragraph block: one <p> each', "<!-- wp:paragraph -->\n<p>Description of the event</p><p>New line</p><p>Another new line</p>\n<!-- /wp:paragraph -->", npmp_scheduled_emails_fill( "<!-- wp:paragraph -->\n<p>[event_excerpt words=\"200\"]</p>\n<!-- /wp:paragraph -->", $map, true ) );
check( 'the <p> keeps its class', '<p class="lead">Description of the event</p><p class="lead">New line</p><p class="lead">Another new line</p>', npmp_scheduled_emails_fill( '<p class="lead">[event_excerpt]</p>', $map, true ) );
check( 'inside a sentence: <br> tags', '<p>About: Description of the event<br><br>New line<br><br>Another new line</p>', npmp_scheduled_emails_fill( '<p>About: [event_excerpt]</p>', $map, true ) );
check( 'in a subject: one line', 'Description of the event New line Another new line', npmp_scheduled_emails_fill( '[event_excerpt]', $map, false ) );
check( 'cut excerpt keeps its first paragraphs', '<p>Description of the event</p><p>New&#8230;</p>', str_replace( "\u{2026}", '&#8230;', npmp_scheduled_emails_fill( '<p>[event_excerpt words="5"]</p>', $map, true ) ) );
check( 'still escaped', '<p>A &amp; B &lt;b&gt;</p>', npmp_scheduled_emails_fill( '<p>[event_excerpt]</p>', array( '[event_excerpt]' => 'A & B <b>' ), true ) );

echo "\n[event_description]\n";
$desc = '<h2>Agenda</h2><p>First &#91;bit&#93;</p>';
check( 'a paragraph block holding the tag gives way to the description', "<p>Hi</p>\n" . $desc . "\n<p>Bye</p>", npmp_scheduled_emails_fill( "<p>Hi</p>\n<!-- wp:paragraph -->\n<p>[event_description]</p>\n<!-- /wp:paragraph -->\n<p>Bye</p>", array( '[event_description]' => $desc ), true ) );
check( 'a bare <p> holding the tag too', $desc, npmp_scheduled_emails_fill( '<p>[event_description]</p>', array( '[event_description]' => $desc ), true ) );
check( 'anywhere else, in place', '<div>' . $desc . '</div>', npmp_scheduled_emails_fill( '<div>[event_description]</div>', array( '[event_description]' => $desc ), true ) );
check( 'in a subject, plain text', 'About: Agenda First [bit]', npmp_scheduled_emails_fill( 'About: [event_description]', array( '[event_description]' => $desc ), false ) );
check( 'no tag, no render', '<p>Hi</p>', npmp_scheduled_emails_fill_description( '<p>Hi</p>', array( 'event_id' => 99999 ), true ) );
check( 'the help lists it', true, isset( npmp_scheduled_emails_placeholder_help()['[event_description]'] ) );
check( 'the help says the excerpt keeps paragraph breaks', true, false !== strpos( npmp_scheduled_emails_placeholder_help()['[event_excerpt]'], 'paragraph breaks' ) );

echo "\nWhat a mail app can't use stays out\n";
$dirty = '<!-- wp:paragraph --><p onclick="x()">Keep</p><!-- /wp:paragraph -->'
	. '<script>alert(1)</script><style>p{}</style>'
	. '<form action="/join"><label>Name</label><input name="n"><button>Join</button></form>'
	. '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"><iframe src="https://www.youtube.com/embed/x"></iframe></div></figure>'
	. '<svg viewBox="0 0 1 1"><path d="M0"/></svg><video src="a.mp4"></video>'
	. "<p>Line\nwrap [shortcode]</p>";
$clean = npmp_scheduled_emails_email_safe_html( $dirty );
check( 'scripts and styles go', false, (bool) preg_match( '/alert|p\{\}/', $clean ) );
check( 'forms go with their fields and words', false, (bool) preg_match( '/form|input|Join|Name/', $clean ) );
check( 'frames and the empty embed figure go', false, (bool) preg_match( '/iframe|figure|youtube/', $clean ) );
check( 'svg and video go', false, (bool) preg_match( '/svg|path|video/', $clean ) );
check( 'editor comments go', false, strpos( $clean, '<!--' ) );
check( 'text stays', true, false !== strpos( $clean, 'Keep' ) );
check( 'no newline left for wpautop', false, strpos( $clean, "\n" ) );
check( 'brackets come back as entities, so no shortcode runs later', true, false !== strpos( $clean, '&#91;shortcode&#93;' ) );

$embed  = array(
	'blockName'    => 'core/embed',
	'attrs'        => array( 'url' => 'https://www.youtube.com/watch?v=abc' ),
	'innerBlocks'  => array(),
	'innerHTML'    => '',
	'innerContent' => array(),
);
$para   = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array(),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p>Hi</p>',
	'innerContent' => array( '<p>Hi</p>' ),
);
$search = array(
	'blockName'    => 'core/search',
	'attrs'        => array(),
	'innerBlocks'  => array(),
	'innerHTML'    => '',
	'innerContent' => array(),
);
$group  = array(
	'blockName'    => 'core/group',
	'attrs'        => array(),
	'innerBlocks'  => array( $search, $para ),
	'innerHTML'    => '<div></div>',
	'innerContent' => array( '<div>', null, null, '</div>' ),
);
$out = npmp_scheduled_emails_email_blocks( array( $embed, $search, $group ) );
check( 'an embed becomes a link to what it showed', '<p><a href="https://www.youtube.com/watch?v=abc">https://www.youtube.com/watch?v=abc</a></p>', $out[0]['innerHTML'] );
check( 'a search box goes', 2, count( $out ) );
check( 'inside a group, the search box goes', array( 'core/paragraph' ), array_column( $out[1]['innerBlocks'], 'blockName' ) );
check( 'and takes its place marker with it', array( '<div>', null, '</div>' ), $out[1]['innerContent'] );

echo "\nThe event box before the email is on the schedule\n";
$GLOBALS['t_options']['npmp_scheduled_email_defaults'] = array( 'enabled' => 1, 'minutes_before' => 1440 );
$GLOBALS['t_status'][30] = 'auto-draft';
check( 'new event: when, who, and what it needs', 'Planned for 1 day before the event, to every subscribed member. Add a start date and publish the event to put it on the schedule. You\'ll see the send time here after you save.', npmp_scheduled_emails_planned_summary( 30 ) );
$GLOBALS['t_status'][31] = 'draft';
$GLOBALS['t_meta'][31]   = array( '_npmp_event_start' => '2026-11-20 19:00:00' );
check( 'draft with a date: the send time', 'Planned for 1 day before the event, Thursday 19 November 2026 7:00 pm (Europe/London), to every subscribed member. Publish the event to put it on the schedule.', npmp_scheduled_emails_planned_summary( 31 ) );
$GLOBALS['t_meta'][32] = array( '_npmp_event_start' => '' );
check( 'published with no date', 'Planned for 1 day before the event, to every subscribed member. Add a start date to put it on the schedule.', npmp_scheduled_emails_planned_summary( 32 ) );
$GLOBALS['t_meta'][33] = array( '_npmp_sched_email' => array( 'mode' => 'off' ) );
check( 'emails off for the event', 'Emails for this event are off.', npmp_scheduled_emails_planned_summary( 33 ) );
check( 'a whole-week offset keeps the wall clock', '1 week before the event, Friday 6 November 2026 7:00 pm (Europe/London)', npmp_scheduled_emails_planned_when( 10080, '2026-11-13 19:00:00' ) );
check( 'the box asks for the plan when no row exists', 'Planned for 1 day before the event, Thursday 19 November 2026 7:00 pm (Europe/London), to every subscribed member. Publish the event to put it on the schedule.', npmp_scheduled_emails_row_summary( null, 31 ) );
check( 'the old words stay for a caller with no event', 'Nothing scheduled yet. Save the event to schedule its email.', npmp_scheduled_emails_row_summary( null ) );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-scheduled-emails.php' );
check( 'the box no longer opens on "Nothing scheduled yet"', false, (bool) preg_match( '/esc_html\( npmp_scheduled_emails_row_summary\( \$row \) \)/', $src ) );
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/npmp-scheduled-emails.js' );
check( 'the box refreshes after the block editor saves', true, false !== strpos( $js, 'isSavingMetaBoxes' ) && false !== strpos( $js, "action: 'npmp_sched_email_box_status'" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
