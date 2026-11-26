<?php
/**
 * iCalendar text (includes/npmp-ics.php): TEXT escaping, URI values, line
 * folding at 75 octets without splitting a UTF-8 character, and the meeting
 * details in LOCATION, URL, and DESCRIPTION.
 *
 * Pete on wordpress.org (topic "meeting-links") found the feed had no join
 * link and asked for it in LOCATION. A public event now carries it there, in
 * URL, and in DESCRIPTION with the meeting ID and passcode. Which details a
 * reader may see is the caller's choice (the feed passes only public ones),
 * checked on a real WordPress in tests/rig/event-ics.php.
 *
 * Run: php tests/test-ics.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/npmp-ics.php';

/**
 * Undo folding (RFC 5545 3.1).
 *
 * @param string $text Folded text.
 * @return string
 */
function t_unfold( $text ) {
	return str_replace( "\r\n ", '', $text );
}

/**
 * Every physical line's length in octets.
 *
 * @param string $text Folded text.
 * @return int[]
 */
function t_lengths( $text ) {
	return array_map( 'strlen', explode( "\r\n", rtrim( $text, "\r\n" ) ) );
}

echo "TEXT escaping\n";
check( 'a line break becomes \\n', 'Line one\\nLine two', npmp_ics_escape_text( "Line one\nLine two" ) );
check( 'a backslash doubles', 'C:\\\\path', npmp_ics_escape_text( 'C:\\path' ) );
check( 'commas and semicolons are escaped', 'Hall\\, Room 2\\; upstairs', npmp_ics_escape_text( 'Hall, Room 2; upstairs' ) );
check( 'tags are stripped', 'Big night', npmp_ics_escape_text( '<b>Big</b> night' ) );

echo "URI values\n";
check( 'https passes', 'https://zoom.example/j/123?pwd=a,b;c', npmp_ics_uri( ' https://zoom.example/j/123?pwd=a,b;c ' ) );
check( 'http passes', 'http://example.org/x', npmp_ics_uri( 'http://example.org/x' ) );
check( 'javascript is refused', '', npmp_ics_uri( 'javascript:alert(1)' ) );
check( 'a space inside is refused', '', npmp_ics_uri( 'https://example.org/a b' ) );
check( 'a line break inside is refused', '', npmp_ics_uri( "https://example.org/a\r\nX-INJECT:1" ) );
check( 'empty stays empty', '', npmp_ics_uri( '' ) );

echo "Folding\n";
check( 'a short line stays whole', 'SUMMARY:Hi', npmp_ics_fold( 'SUMMARY:Hi' ) );
$long   = 'DESCRIPTION:' . str_repeat( 'abcdefghij', 20 );
$folded = npmp_ics_fold( $long );
check( 'a long line unfolds back to itself', $long, t_unfold( $folded ) );
check( 'no physical line passes 75 octets', true, max( t_lengths( $folded ) ) <= 75 );
check( 'the first line is exactly 75 octets', 75, t_lengths( $folded )[0] );
check( 'continuation lines start with one space', true, (bool) preg_match( "/\r\n [^ ]/", $folded ) );
$utf    = 'SUMMARY:' . str_repeat( 'Café ünd 東京 ', 12 );
$folded = npmp_ics_fold( $utf );
check( 'UTF-8 unfolds back to itself', $utf, t_unfold( $folded ) );
check( 'UTF-8 lines stay within 75 octets', true, max( t_lengths( $folded ) ) <= 75 );
$whole = true;
foreach ( explode( "\r\n", $folded ) as $physical ) {
	$whole = $whole && 1 === preg_match( '//u', $physical );
}
check( 'no line breaks a UTF-8 character in two', true, $whole );
check( 'a stray line break never reaches the output', 'SUMMARY:ab', npmp_ics_fold( "SUMMARY:a\r\nb" ) );

echo "A public event with a meeting\n";
$event = array(
	'uid'        => '42@example.org',
	'stamp'      => 1767225600,
	'start'      => 1767268800,
	'end'        => 1767272400,
	'summary'    => 'Computing, monthly',
	'location'   => 'Hall A',
	'url'        => 'https://zoom.example/j/8675309?pwd=abc',
	'meeting_id' => '867 5309',
	'passcode'   => 'tuesday',
);
$v = npmp_ics_vevent( $event );
$u = t_unfold( $v );
check( 'starts and ends the event', true, 0 === strpos( $v, "BEGIN:VEVENT\r\n" ) && "END:VEVENT\r\n" === substr( $v, -12 ) );
check( 'UID and times', true, false !== strpos( $u, "UID:42@example.org\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T120000Z\r\nDTEND:20260101T130000Z\r\n" ) );
check( 'the title escapes its comma', true, false !== strpos( $u, "SUMMARY:Computing\\, monthly\r\n" ) );
check( 'LOCATION has the place, then the join link', true, false !== strpos( $u, "LOCATION:Hall A\\, https://zoom.example/j/8675309?pwd=abc\r\n" ) );
check( 'URL holds the bare link', true, false !== strpos( $u, "URL:https://zoom.example/j/8675309?pwd=abc\r\n" ) );
check( 'DESCRIPTION has link, ID, and passcode', true, false !== strpos( $u, "DESCRIPTION:Join link: https://zoom.example/j/8675309?pwd=abc\\nMeeting ID: 867 5309\\nPasscode: tuesday\r\n" ) );
check( 'every line ends in CRLF', 0, preg_match( "/[^\r]\n/", $v ) );
check( 'no physical line passes 75 octets', true, max( t_lengths( $v ) ) <= 75 );

echo "Without a meeting\n";
$plain = npmp_ics_vevent( array_merge( $event, array( 'url' => '', 'meeting_id' => '', 'passcode' => '' ) ) );
check( 'LOCATION is the place alone', true, false !== strpos( $plain, "LOCATION:Hall A\r\n" ) );
check( 'no URL line', false, strpos( $plain, "\r\nURL:" ) );
check( 'no DESCRIPTION line', false, strpos( $plain, 'DESCRIPTION:' ) );
$online = t_unfold( npmp_ics_vevent( array_merge( $event, array( 'location' => '' ) ) ) );
check( 'an online-only event puts the link alone in LOCATION', true, false !== strpos( $online, "LOCATION:https://zoom.example/j/8675309?pwd=abc\r\n" ) );
$id_only = npmp_ics_vevent( array_merge( $event, array( 'url' => '' ) ) );
check( 'an ID with no link still reaches DESCRIPTION', true, false !== strpos( $id_only, "DESCRIPTION:Meeting ID: 867 5309\\nPasscode: tuesday\r\n" ) );
$bad = npmp_ics_vevent( array_merge( $event, array( 'url' => "javascript:x\r\nX-EVIL:1" ) ) );
check( 'a bad link is dropped everywhere', true, false === strpos( $bad, 'javascript' ) && false === strpos( $bad, 'X-EVIL' ) );
check( 'no start, no event', '', npmp_ics_vevent( array_merge( $event, array( 'start' => 0 ) ) ) );

echo "No end time (sweep 2026-11-21, F12)\n";
// DTEND used to equal DTSTART, and calendar apps showed a zero-minute entry.
$no_end = npmp_ics_vevent( array_merge( $event, array( 'end' => 0 ) ) );
check( 'no end runs one hour', true, false !== strpos( $no_end, "DTSTART:20260101T120000Z\r\nDTEND:20260101T130000Z\r\n" ) );
check( 'an end equal to the start runs one hour', true, false !== strpos( npmp_ics_vevent( array_merge( $event, array( 'end' => 1767268800 ) ) ), "DTEND:20260101T130000Z\r\n" ) );
check( 'an end before the start runs one hour', true, false !== strpos( npmp_ics_vevent( array_merge( $event, array( 'end' => 1767265200 ) ) ), "DTEND:20260101T130000Z\r\n" ) );
check( 'a real end stays', true, false !== strpos( npmp_ics_vevent( array_merge( $event, array( 'end' => 1767279600 ) ) ), "DTEND:20260101T150000Z\r\n" ) );
check( 'a timed event has no DATE values', false, strpos( $no_end, 'VALUE=DATE' ) );

echo "All-day events\n";
check( 'a 00:00 start with no end is one day', array( '2026-12-05', '2026-12-05' ), npmp_ics_all_day_span( '2026-12-05 00:00:00', '' ) );
check( 'a date with no time is one day', array( '2026-12-05', '2026-12-05' ), npmp_ics_all_day_span( '2026-12-05', '' ) );
check( 'the editor\'s empty times (00:00 to 23:59) span the days', array( '2026-12-05', '2026-12-07' ), npmp_ics_all_day_span( '2026-12-05 00:00:00', '2026-12-07 23:59:00' ) );
check( 'an importer\'s 23:59:59 end counts', array( '2026-12-05', '2026-12-05' ), npmp_ics_all_day_span( '2026-12-05 00:00:00', '2026-12-05 23:59:59' ) );
check( 'an end day before the start keeps one day', array( '2026-12-05', '2026-12-05' ), npmp_ics_all_day_span( '2026-12-05 00:00:00', '2026-12-04 23:59:00' ) );
check( 'a start with a time is timed', null, npmp_ics_all_day_span( '2026-12-05 18:30:00', '' ) );
check( 'a 00:00 start with a real end time is timed', null, npmp_ics_all_day_span( '2026-12-05 00:00:00', '2026-12-05 02:00:00' ) );
check( 'no start is timed', null, npmp_ics_all_day_span( '', '' ) );
check( 'npmp_ics_date moves across a month end', '20270101', npmp_ics_date( '2026-12-31', 1 ) );
check( 'npmp_ics_date across February in a leap year', '20280301', npmp_ics_date( '2028-02-29', 1 ) );
check( 'npmp_ics_date refuses a day that does not exist', '', npmp_ics_date( '2026-02-30' ) );

$one_day = npmp_ics_vevent( array_merge( $event, array( 'end' => 0, 'all_day' => array( '2026-12-05', '2026-12-05' ) ) ) );
check( 'one day: DATE start', true, false !== strpos( $one_day, "DTSTART;VALUE=DATE:20261205\r\n" ) );
check( 'one day: DTEND is the next day (RFC 5545 3.6.1)', true, false !== strpos( $one_day, "DTEND;VALUE=DATE:20261206\r\n" ) );
check( 'one day: no UTC times', false, strpos( $one_day, 'DTSTART:' ) );
$three = npmp_ics_vevent( array_merge( $event, array( 'all_day' => array( '2026-12-30', '2027-01-01' ) ) ) );
check( 'three days across New Year', true, false !== strpos( $three, "DTSTART;VALUE=DATE:20261230\r\nDTEND;VALUE=DATE:20270102\r\n" ) );
$junk = npmp_ics_vevent( array_merge( $event, array( 'end' => 0, 'all_day' => array( '2026-02-30', '2026-02-30' ) ) ) );
check( 'a bad day falls back to times', true, false !== strpos( $junk, "DTSTART:20260101T120000Z\r\nDTEND:20260101T130000Z\r\n" ) );
check( 'an all-day event keeps its other lines', true, false !== strpos( t_unfold( $one_day ), "LOCATION:Hall A\\, https://zoom.example/j/8675309?pwd=abc\r\n" ) );
$labels = npmp_ics_vevent( array_merge( $event, array( 'labels' => array( 'join' => 'Rejoindre' ) ) ) );
check( 'labels translate', true, false !== strpos( t_unfold( $labels ), 'DESCRIPTION:Rejoindre: https' ) );

echo "Calendar\n";
$cal = npmp_ics_calendar( $v );
check( 'a feed has no METHOD', false, strpos( $cal, 'METHOD' ) );
check( 'a feed wraps the events', true, 0 === strpos( $cal, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nonprofit Manager//EN\r\nBEGIN:VEVENT" ) && "END:VCALENDAR\r\n" === substr( $cal, -15 ) );
check( 'an email file says PUBLISH', true, false !== strpos( npmp_ics_calendar( $v, 'publish' ), "PRODID:-//Nonprofit Manager//EN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
