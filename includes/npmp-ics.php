<?php
/**
 * File path: includes/npmp-ics.php
 *
 * iCalendar (RFC 5545) text with no WordPress in it, so tests/test-ics.php can
 * run it on plain PHP. The site feed (npmp_maybe_render_ical_feed() in
 * includes/npmp-calendar.php) and the .ics file on event emails
 * (includes/npmp-event-ics.php) both build their events here.
 *
 * - TEXT values escape backslash, semicolon, comma, and line breaks (3.3.11).
 * - URL holds a URI, which takes no TEXT escaping (3.8.4.6), so only an
 *   http or https address with no spaces or control characters goes in.
 * - Every content line folds at 75 octets, never inside a UTF-8 character,
 *   and each continuation line starts with one space (3.1).
 *
 * Meeting details: the join link goes in LOCATION after any physical place,
 * in URL, and in DESCRIPTION with the meeting ID and passcode. The caller
 * decides which details a reader may see: the public feed passes only what
 * npmp_get_event_details() returns without the private flag, so an event that
 * keeps its link for emails never puts it in the feed.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_ics_escape_text' ) ) {
	/**
	 * Escape a TEXT value.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	function npmp_ics_escape_text( $text ) {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text ) : trim( strip_tags( (string) $text ) );
		// Backslashes first. Escaping them after the newlines turned every
		// line break into a literal "\\n" in calendar apps.
		$text = strtr(
			$text,
			array(
				'\\' => '\\\\',
				','  => '\,',
				';'  => '\;',
			)
		);

		return str_replace( array( "\r\n", "\r", "\n" ), '\n', $text );
	}
}

/**
 * Fold one content line at 75 octets. A continuation line starts with a
 * space, which counts toward its 75.
 *
 * @param string $line One unfolded line, no line break.
 * @return string Folded line, no trailing line break.
 */
function npmp_ics_fold( $line ) {
	$line = str_replace( array( "\r", "\n" ), '', (string) $line );
	if ( strlen( $line ) <= 75 ) {
		return $line;
	}
	$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $chars ) {
		// Not valid UTF-8, so octets are all we can count.
		$chars = str_split( $line );
	}
	$out = '';
	$len = 0;
	foreach ( $chars as $char ) {
		$bytes = strlen( $char );
		if ( $len + $bytes > 75 ) {
			$out .= "\r\n ";
			$len  = 1;
		}
		$out .= $char;
		$len += $bytes;
	}
	return $out;
}

/**
 * One folded content line with its CRLF.
 *
 * @param string $name  Property name, with any parameters.
 * @param string $value Value, already escaped for its type.
 * @return string
 */
function npmp_ics_line( $name, $value ) {
	return npmp_ics_fold( $name . ':' . $value ) . "\r\n";
}

/**
 * An http or https address fit for a URI value, or ''.
 *
 * @param string $url Address.
 * @return string
 */
function npmp_ics_uri( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || ! preg_match( '#^https?://[^\s"<>\\\\\x00-\x1f\x7f]+$#i', $url ) ) {
		return '';
	}
	return $url;
}

/**
 * The plain-text meeting lines for DESCRIPTION.
 *
 * @param string $url        Join link (already checked with npmp_ics_uri()).
 * @param string $meeting_id Meeting ID.
 * @param string $passcode   Passcode.
 * @param array  $labels     'join', 'meeting_id', 'passcode' labels.
 * @return string Lines joined by "\n", or ''.
 */
function npmp_ics_meeting_text( $url, $meeting_id, $passcode, $labels = array() ) {
	$labels = array_merge(
		array(
			'join'       => 'Join link',
			'meeting_id' => 'Meeting ID',
			'passcode'   => 'Passcode',
		),
		(array) $labels
	);
	$lines  = array();
	if ( '' !== (string) $url ) {
		$lines[] = $labels['join'] . ': ' . $url;
	}
	if ( '' !== trim( (string) $meeting_id ) ) {
		$lines[] = $labels['meeting_id'] . ': ' . trim( (string) $meeting_id );
	}
	if ( '' !== trim( (string) $passcode ) ) {
		$lines[] = $labels['passcode'] . ': ' . trim( (string) $passcode );
	}
	return implode( "\n", $lines );
}

/**
 * One VEVENT.
 *
 * @param array $event {
 *     @type string $uid        Unique ID (ID@host).
 *     @type int    $stamp      DTSTAMP, Unix time.
 *     @type int    $start      Start, Unix time.
 *     @type int    $end        End, Unix time (0 for none).
 *     @type string $summary    Title.
 *     @type string $location   Physical place.
 *     @type string $url        Join link.
 *     @type string $meeting_id Meeting ID.
 *     @type string $passcode   Passcode.
 *     @type array  $labels     Labels for npmp_ics_meeting_text().
 * }
 * @return string '' when there's no start.
 */
function npmp_ics_vevent( $event ) {
	$e = array_merge(
		array(
			'uid'        => '',
			'stamp'      => 0,
			'start'      => 0,
			'end'        => 0,
			'summary'    => '',
			'location'   => '',
			'url'        => '',
			'meeting_id' => '',
			'passcode'   => '',
			'labels'     => array(),
		),
		(array) $event
	);
	$start = (int) $e['start'];
	if ( $start <= 0 ) {
		return '';
	}
	$end   = (int) $e['end'] > 0 ? (int) $e['end'] : $start;
	$stamp = (int) $e['stamp'] > 0 ? (int) $e['stamp'] : $start;
	$url   = npmp_ics_uri( $e['url'] );
	$place = trim( (string) $e['location'] );
	$where = implode( ', ', array_filter( array( $place, $url ), 'strlen' ) );
	$notes = npmp_ics_meeting_text( $url, (string) $e['meeting_id'], (string) $e['passcode'], (array) $e['labels'] );

	$out  = "BEGIN:VEVENT\r\n";
	$out .= npmp_ics_line( 'UID', (string) $e['uid'] );
	// DTSTAMP is when this entry last changed. Calendar apps compare it to
	// spot an edited event, and the start date never moves on an edit.
	$out .= npmp_ics_line( 'DTSTAMP', gmdate( 'Ymd\THis\Z', $stamp ) );
	$out .= npmp_ics_line( 'DTSTART', gmdate( 'Ymd\THis\Z', $start ) );
	$out .= npmp_ics_line( 'DTEND', gmdate( 'Ymd\THis\Z', $end ) );
	$out .= npmp_ics_line( 'SUMMARY', npmp_ics_escape_text( $e['summary'] ) );
	if ( '' !== $where ) {
		$out .= npmp_ics_line( 'LOCATION', npmp_ics_escape_text( $where ) );
	}
	if ( '' !== $url ) {
		$out .= npmp_ics_line( 'URL', $url );
	}
	if ( '' !== $notes ) {
		$out .= npmp_ics_line( 'DESCRIPTION', npmp_ics_escape_text( $notes ) );
	}
	$out .= "END:VEVENT\r\n";
	return $out;
}

/**
 * Wrap VEVENTs in a VCALENDAR.
 *
 * @param string $vevents Joined VEVENT blocks.
 * @param string $method  '' for a feed, 'PUBLISH' for a file on an email.
 * @return string
 */
function npmp_ics_calendar( $vevents, $method = '' ) {
	$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nonprofit Manager//EN\r\n";
	if ( '' !== $method ) {
		$out .= npmp_ics_line( 'METHOD', strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $method ) ) );
	}
	return $out . (string) $vevents . "END:VCALENDAR\r\n";
}
