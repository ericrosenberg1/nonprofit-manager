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
 * The first and last calendar day of an all-day event, or null for an event
 * with times.
 *
 * Events have no all-day box. The event editor saves an empty start time as
 * 00:00 and an empty end time as 23:59, an event from the old date-only field
 * reads as 00:00, and every importer writes an all-day event as 00:00 to
 * 23:59. The emails' [event_time] already says "All day" for a 00:00 start. So
 * a start at 00:00 with no end, or with an end at 23:59, is all day.
 *
 * @param string $start Local start, "Y-m-d H:i:s" or "Y-m-d".
 * @param string $end   Local end in the same forms, or ''.
 * @return string[]|null Array( first Y-m-d, last Y-m-d ), or null.
 */
function npmp_ics_all_day_span( $start, $end ) {
	if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T]00:00(?::00)?)?$/', trim( (string) $start ), $s ) ) {
		return null;
	}
	$end = trim( (string) $end );
	if ( '' === $end ) {
		return array( $s[1], $s[1] );
	}
	if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T]23:59(?::\d{2})?)?$/', $end, $e ) ) {
		return null;
	}
	// An end day before the start day is a typo. The event keeps its one day.
	return array( $s[1], max( $s[1], $e[1] ) );
}

/**
 * A Y-m-d day as an iCalendar DATE, moved by whole days.
 *
 * @param string $day    Y-m-d.
 * @param int    $offset Days to add.
 * @return string Ymd, or '' for a day that doesn't exist.
 */
function npmp_ics_date( $day, $offset = 0 ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $day, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return '';
	}
	return gmdate( 'Ymd', gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3] + (int) $offset, (int) $m[1] ) );
}

/**
 * One VEVENT.
 *
 * @param array $event {
 *     @type string $uid        Unique ID (ID@host).
 *     @type int    $stamp      DTSTAMP, Unix time.
 *     @type int    $start      Start, Unix time.
 *     @type int    $end        End, Unix time. With none, or one that isn't
 *                              after the start, the event runs one hour.
 *     @type array  $all_day    First and last day (Y-m-d) of an all-day
 *                              event, from npmp_ics_all_day_span(), else empty.
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
			'all_day'    => null,
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
	// An end equal to the start showed in calendar apps as a zero-minute
	// entry. With no end, or one that isn't after the start, we give the
	// event an hour.
	$end   = (int) $e['end'] > $start ? (int) $e['end'] : $start + 3600;
	$stamp = (int) $e['stamp'] > 0 ? (int) $e['stamp'] : $start;
	// An all-day event gets DATE values, with DTEND the day after its last
	// day (RFC 5545 3.6.1), so every calendar shows it on those days in any
	// time zone.
	$days     = is_array( $e['all_day'] ) ? array_values( $e['all_day'] ) : array();
	$first    = isset( $days[0] ) ? npmp_ics_date( $days[0] ) : '';
	$last     = isset( $days[1] ) ? npmp_ics_date( $days[1], 1 ) : '';
	$is_all_day = '' !== $first && '' !== $last && $last > $first;
	$url   = npmp_ics_uri( $e['url'] );
	$place = trim( (string) $e['location'] );
	$where = implode( ', ', array_filter( array( $place, $url ), 'strlen' ) );
	$notes = npmp_ics_meeting_text( $url, (string) $e['meeting_id'], (string) $e['passcode'], (array) $e['labels'] );

	$out  = "BEGIN:VEVENT\r\n";
	$out .= npmp_ics_line( 'UID', (string) $e['uid'] );
	// DTSTAMP is when this entry last changed. Calendar apps compare it to
	// spot an edited event, and the start date never moves on an edit.
	$out .= npmp_ics_line( 'DTSTAMP', gmdate( 'Ymd\THis\Z', $stamp ) );
	if ( $is_all_day ) {
		$out .= npmp_ics_line( 'DTSTART;VALUE=DATE', $first );
		$out .= npmp_ics_line( 'DTEND;VALUE=DATE', $last );
	} else {
		$out .= npmp_ics_line( 'DTSTART', gmdate( 'Ymd\THis\Z', $start ) );
		$out .= npmp_ics_line( 'DTEND', gmdate( 'Ymd\THis\Z', $end ) );
	}
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
