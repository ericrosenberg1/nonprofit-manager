<?php
/**
 * Scheduled emails (includes/npmp-scheduled-emails.php): send-time math
 * across both 2026 London clock changes, the late rule in all three modes,
 * the send window, the claim guard, the free one-email limit, and
 * placeholder replacement.
 *
 * Run: php tests/test-scheduled-emails.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_meta']    = array();
$GLOBALS['t_filters'] = array();

function add_action() {}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
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
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return preg_match( '#^https?://#', (string) $url ) ? str_replace( array( '"', "'", ' ' ), array( '%22', '%27', '%20' ), (string) $url ) : '';
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
function get_permalink( $id ) {
	return 'https://example.org/events/meeting-' . $id . '/';
}
function get_post_field( $field, $id, $context = 'display' ) {
	return 'Art & History <Group>';
}
function wp_date( $format, $ts, $tz = null ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
}
function home_url( $path = '' ) {
	return 'https://example.org' . $path;
}
function add_query_arg( $key, $value, $url ) {
	return $url . '?' . $key . '=' . $value;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function strip_shortcodes( $text ) {
	return preg_replace( '/\[[^\]]+\]/', '', (string) $text );
}
// Event 12 has a 1200px featured image, event 14 a 320px one, others none.
function get_post_thumbnail_id( $id ) {
	return array( 12 => 501, 14 => 502 )[ $id ] ?? 0;
}
function wp_get_attachment_image_src( $id, $size ) {
	$srcs = array(
		501 => array( 'https://example.org/wp-content/uploads/hall-1024x683.jpg', 1024, 683 ),
		502 => array( 'https://example.org/wp-content/uploads/logo.png', 320, 120 ),
	);
	return $srcs[ $id ] ?? false;
}

/**
 * Enough of $wpdb for the claim: one table of rows keyed by id. query()
 * honours "WHERE id = N AND status = 'pending'" and returns how many rows
 * it changed, as MySQL does.
 */
class T_Wpdb {
	public $prefix = 'wp_';
	public $rows   = array();
	public $last   = '';
	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function query( $sql ) {
		$this->last = $sql;
		if ( ! preg_match( "/SET status = 'sending'.*WHERE id = (\d+) AND status = 'pending'/", $sql, $m ) ) {
			return 0;
		}
		$id = (int) $m[1];
		if ( isset( $this->rows[ $id ] ) && 'pending' === $this->rows[ $id ] ) {
			$this->rows[ $id ] = 'sending';
			return 1;
		}
		return 0;
	}
}
$GLOBALS['wpdb'] = new T_Wpdb();

require_once dirname( __DIR__ ) . '/includes/npmp-scheduled-emails.php';

$london = new DateTimeZone( 'Europe/London' );

echo "Send time: whole-day offsets keep the London wall clock\n";
// Clocks go forward 29 March 2026 (01:00 UTC). A 7 pm BST meeting on
// 2 April is 18:00 UTC. A week earlier, 26 March at 7 pm, is GMT, so 19:00 UTC.
check( 'week before 2 Apr 7pm BST is 26 Mar 19:00 UTC', '2026-03-26 19:00:00', npmp_scheduled_emails_send_at_utc( '2026-04-02 19:00:00', 10080, $london ) );
// Clocks go back 25 October 2026 (01:00 UTC). 29 Oct 7 pm is GMT (19:00 UTC).
// A week earlier, 22 Oct at 7 pm, is BST, so 18:00 UTC.
check( 'week before 29 Oct 7pm GMT is 22 Oct 18:00 UTC', '2026-10-22 18:00:00', npmp_scheduled_emails_send_at_utc( '2026-10-29 19:00:00', 10080, $london ) );
check( 'day before Mon 30 Mar 7pm BST is Sun 29 Mar 18:00 UTC (7pm BST)', '2026-03-29 18:00:00', npmp_scheduled_emails_send_at_utc( '2026-03-30 19:00:00', 1440, $london ) );
check( 'day before Sun 29 Mar noon BST is Sat 28 Mar noon GMT', '2026-03-28 12:00:00', npmp_scheduled_emails_send_at_utc( '2026-03-29 12:00:00', 1440, $london ) );
check( 'day before Mon 26 Oct 10am GMT is Sun 25 Oct 10am GMT', '2026-10-25 10:00:00', npmp_scheduled_emails_send_at_utc( '2026-10-26 10:00:00', 1440, $london ) );
check( 'day before Sun 25 Oct noon GMT is Sat 24 Oct noon BST', '2026-10-24 11:00:00', npmp_scheduled_emails_send_at_utc( '2026-10-25 12:00:00', 1440, $london ) );
check( '3 days before 1 Apr 9am BST is 29 Mar 9am BST', '2026-03-29 08:00:00', npmp_scheduled_emails_send_at_utc( '2026-04-01 09:00:00', 4320, $london ) );

echo "Send time: hour offsets count real time\n";
// 29 Mar noon BST = 11:00 UTC. 12 real hours earlier is 23:00 UTC the day
// before, which is 11 pm GMT on the wall.
check( '12 hours before 29 Mar noon BST is 28 Mar 23:00 UTC', '2026-03-28 23:00:00', npmp_scheduled_emails_send_at_utc( '2026-03-29 12:00:00', 720, $london ) );
check( '2 hours before 29 Mar 3am BST is 29 Mar 00:00 UTC', '2026-03-29 00:00:00', npmp_scheduled_emails_send_at_utc( '2026-03-29 03:00:00', 120, $london ) );
check( '25 hours before 25 Oct noon GMT is 24 Oct 11:00 UTC', '2026-10-24 11:00:00', npmp_scheduled_emails_send_at_utc( '2026-10-25 12:00:00', 1500, $london ) );
check( '2 hours before 25 Oct 2am GMT is 25 Oct 00:00 UTC', '2026-10-25 00:00:00', npmp_scheduled_emails_send_at_utc( '2026-10-25 02:00:00', 120, $london ) );
check( 'empty start gives empty send time', '', npmp_scheduled_emails_send_at_utc( '', 1440, $london ) );
check( 'unreadable start gives empty send time', '', npmp_scheduled_emails_send_at_utc( 'not a date', 1440, $london ) );

echo "Late rule\n";
$now = 1_800_000_000;
check( 'send_if: 72 hours out sends', 'send', npmp_scheduled_emails_late_decision( 'send_if', 48, $now + 72 * 3600, $now ) );
check( 'send_if: 24 hours out skips', 'skip', npmp_scheduled_emails_late_decision( 'send_if', 48, $now + 24 * 3600, $now ) );
check( 'send_if: exactly 48 hours out skips', 'skip', npmp_scheduled_emails_late_decision( 'send_if', 48, $now + 48 * 3600, $now ) );
check( 'send_if: custom 12 hours, 13 out sends', 'send', npmp_scheduled_emails_late_decision( 'send_if', 12, $now + 13 * 3600, $now ) );
check( 'skip: 30 days out still skips', 'skip', npmp_scheduled_emails_late_decision( 'skip', 48, $now + 30 * 86400, $now ) );
check( 'send: 2 hours out still sends', 'send', npmp_scheduled_emails_late_decision( 'send', 48, $now + 2 * 3600, $now ) );
check( 'unknown mode acts as send_if', 'skip', npmp_scheduled_emails_late_decision( 'bogus', 48, $now + 3600, $now ) );

echo "Send window\n";
check( 'two hours out can go', '', npmp_scheduled_emails_window_block( $now + 7200, $now ) );
check( '59 minutes out is the last hour', 'last_hour', npmp_scheduled_emails_window_block( $now + 3540, $now ) );
check( 'exactly an hour out is the last hour', 'last_hour', npmp_scheduled_emails_window_block( $now + 3600, $now ) );
check( 'after the start is too late', 'started', npmp_scheduled_emails_window_block( $now - 60, $now ) );

echo "Claim guard: two sweeps, one row, one winner\n";
$GLOBALS['wpdb']->rows = array( 7 => 'pending' );
$first  = npmp_scheduled_emails_claim( 7 );
$second = npmp_scheduled_emails_claim( 7 );
check( 'first claim wins', true, $first );
check( 'second claim loses', false, $second );
check( 'row is sending', 'sending', $GLOBALS['wpdb']->rows[7] );
check( 'claim UPDATE is guarded on pending', true, false !== strpos( $GLOBALS['wpdb']->last, "WHERE id = 7 AND status = 'pending'" ) );
$GLOBALS['wpdb']->rows = array( 8 => 'sent' );
check( 'a sent row cannot be claimed', false, npmp_scheduled_emails_claim( 8 ) );
check( 'a missing row cannot be claimed', false, npmp_scheduled_emails_claim( 99 ) );

echo "Defaults: off on every site until an admin turns them on\n";
$d = npmp_scheduled_email_defaults();
check( 'enabled is off', 0, $d['enabled'] );
check( 'one day before', 1440, $d['minutes_before'] );
check( 'built-in body', 0, $d['body_id'] );
check( 'late mode send_if', 'send_if', $d['late_mode'] );
check( 'late hours 48', 48, $d['late_min_hours'] );
$d = npmp_scheduled_email_sanitize_defaults( array( 'late_mode' => 'nope', 'minutes_before' => -5, 'late_min_hours' => 0 ) );
check( 'bad late mode falls back', 'send_if', $d['late_mode'] );
check( 'minutes floor at 1', 1, $d['minutes_before'] );
check( 'late hours floor at 1', 1, $d['late_min_hours'] );

echo "Rules: free sends at most one email per event\n";
check( 'no rule while the site default is off', array(), npmp_scheduled_emails_event_rules( 5 ) );
$GLOBALS['t_meta'][5]['_npmp_sched_email'] = array( 'mode' => 'on', 'minutes_before' => 120, 'body_id' => 3, 'subject' => 'Hi [event_title]' );
$r = npmp_scheduled_emails_event_rules( 5 );
check( 'event turned on by itself gets one rule', 1, count( $r ) );
check( 'its own timing', 120, $r[0]['minutes_before'] );
check( 'its own body', 3, $r[0]['body_id'] );
check( 'audience is everyone', 'all', $r[0]['audience_type'] );
$GLOBALS['t_options']['npmp_scheduled_email_defaults'] = array( 'enabled' => 1, 'minutes_before' => 10080 );
$GLOBALS['t_meta'][6]['_npmp_sched_email'] = array( 'mode' => 'off' );
check( 'event turned off gets none', array(), npmp_scheduled_emails_event_rules( 6 ) );
$r = npmp_scheduled_emails_event_rules( 9 );
check( 'event on the site default takes its timing', 10080, $r[0]['minutes_before'] );
add_filter(
	'npmp_event_email_rules',
	function ( $rules ) {
		$rules[] = array( 'key' => 'week', 'minutes_before' => 10080, 'audience' => array( 'type' => 'group', 'id' => 4 ) );
		$rules[] = array( 'key' => 'Day Before!', 'minutes_before' => 1440 );
		return $rules;
	}
);
check( 'extra rules are cut to the free limit of one', 1, count( npmp_scheduled_emails_event_rules( 9 ) ) );
add_filter(
	'npmp_event_email_max',
	function () {
		return 0;
	}
);
$r = npmp_scheduled_emails_event_rules( 9 );
check( 'no limit when an add-on returns 0', 3, count( $r ) );
check( 'group audience kept', 'group', $r[1]['audience_type'] );
check( 'group id kept', 4, $r[1]['audience_id'] );
check( 'rule key cleaned', 'daybefore', $r[2]['key'] );
$GLOBALS['t_filters'] = array();

echo "Placeholders\n";
$GLOBALS['t_options']['date_format'] = 'l j F Y';
$GLOBALS['t_options']['time_format'] = 'g:i a';
$GLOBALS['t_meta'][12] = array(
	'_npmp_event_start'    => '2026-11-12 19:30:00',
	'_npmp_event_location' => 'Church Hall, 4 High St',
	'_npmp_event_url'      => 'https://zoom.example/j/123',
);
$map = npmp_scheduled_emails_placeholders( 12 );
check( 'title is raw', 'Art & History <Group>', $map['[event_title]'] );
check( 'date in site format', 'Thursday 12 November 2026', $map['[event_date]'] );
check( 'time in site format', '7:30 pm', $map['[event_time]'] );
check( 'location', 'Church Hall, 4 High St', $map['[event_location]'] );
check( 'event url is the page', 'https://example.org/events/meeting-12/', $map['[event_url]'] );
check( 'join link is the event link', 'https://zoom.example/j/123', $map['[join_link]'] );
check( 'ical falls back to the site feed', 'https://example.org/?npmp-ical=1', $map['[ical_link]'] );

$GLOBALS['t_meta'][13] = array( '_npmp_event_start' => '2026-11-12 00:00:00' );
$map13 = npmp_scheduled_emails_placeholders( 13 );
check( 'no event link falls back to the page', 'https://example.org/events/meeting-13/', $map13['[join_link]'] );
check( 'midnight start reads as all day', 'All day', $map13['[event_time]'] );
check( 'no location says where to look', 'See the event page', $map13['[event_location]'] );

$html = npmp_scheduled_emails_fill( '<p>[event_title] on [event_date] at [event_time].</p><p><a href="[join_link]">Join</a> <a href="http://[ical_link]">Calendar</a> [unknown]</p>', $map, true );
check( 'title escaped in HTML', true, false !== strpos( $html, 'Art &amp; History &lt;Group&gt; on Thursday 12 November 2026 at 7:30 pm.' ) );
check( 'link filled', true, false !== strpos( $html, 'href="https://zoom.example/j/123"' ) );
check( 'editor-prefixed link filled', true, false !== strpos( $html, 'href="https://example.org/?npmp-ical=1"' ) );
check( 'unknown placeholder left alone', true, false !== strpos( $html, '[unknown]' ) );
check( 'no known placeholder left', 0, preg_match( '/\[(event_|join_|ical_)/', $html ) );
check( 'subject is plain text', 'Tomorrow: Art & History', npmp_scheduled_emails_fill( 'Tomorrow: [event_title]', array( '[event_title]' => 'Art & <b>History</b>' ), false ) );
check( 'javascript link dropped', 'href=""', npmp_scheduled_emails_fill( 'href="[event_url]"', array( '[event_url]' => 'javascript:alert(1)' ), true ) );

echo "Event image and excerpt (wordpress.org thread \"more template shortcodes\")\n";
check( 'image placeholder carries url and width', array( 'url' => 'https://example.org/wp-content/uploads/hall-1024x683.jpg', 'width' => 1024 ), $map['[event_image]'] );
$html = npmp_scheduled_emails_fill( '<p>[event_image]</p>', $map, true );
check( 'image tag capped at 600 wide', true, false !== strpos( $html, '<img src="https://example.org/wp-content/uploads/hall-1024x683.jpg" alt="Art &amp; History &lt;Group&gt;" width="600"' ) );
check( 'image in a subject is dropped', 'See ', npmp_scheduled_emails_fill( 'See [event_image]', $map, false ) );
$GLOBALS['t_meta'][14] = array( '_npmp_event_start' => '2026-11-12 19:30:00' );
$html = npmp_scheduled_emails_fill( '[event_image]', npmp_scheduled_emails_placeholders( 14 ), true );
check( 'small image keeps its own width', true, false !== strpos( $html, 'width="320" style="display:block;width:100%;max-width:320px;' ) );
check( 'no featured image, no tag', '<p></p>', npmp_scheduled_emails_fill( '<p>[event_image]</p>', $map13, true ) );

$long = array( '[event_excerpt]' => trim( str_repeat( 'word ', 80 ) ), '[event_title]' => 'T' );
check( 'excerpt defaults to 55 words', 55, str_word_count( rtrim( npmp_scheduled_emails_fill( '[event_excerpt]', $long, true ), "\u{2026}" ) ) );
check( 'excerpt ends in an ellipsis when cut', true, "\u{2026}" === mb_substr( npmp_scheduled_emails_fill( '[event_excerpt]', $long, true ), -1 ) );
check( 'words="10" sets the length', 10, str_word_count( rtrim( npmp_scheduled_emails_fill( '[event_excerpt words="10"]', $long, true ), "\u{2026}" ) ) );
check( 'curly quotes from the editor still work', 12, str_word_count( rtrim( npmp_scheduled_emails_fill( "[event_excerpt words=\u{201D}12\u{201D}]", $long, true ), "\u{2026}" ) ) );
check( 'entity quotes from the_content still work', 7, str_word_count( rtrim( npmp_scheduled_emails_fill( '[event_excerpt words=&#8221;7&#8221;]', $long, true ), "\u{2026}" ) ) );
check( 'a words value that is not a number leaves the tag', '[event_excerpt words="ten"]', npmp_scheduled_emails_fill( '[event_excerpt words="ten"]', $long, true ) );
check( 'short excerpt has no ellipsis', 'Bring a friend.', npmp_scheduled_emails_fill( '[event_excerpt words="200"]', array( '[event_excerpt]' => 'Bring a friend.' ), true ) );
check( 'excerpt escaped in HTML', 'A &amp; B &lt;b&gt;', npmp_scheduled_emails_fill( '[event_excerpt]', array( '[event_excerpt]' => 'A & B <b>' ), true ) );
check( 'excerpt source drops markup and shortcodes', 'Art & History', npmp_scheduled_emails_excerpt_source( 12 ) );
check( 'help lists the new tags', true, isset( npmp_scheduled_emails_placeholder_help()['[event_image]'], npmp_scheduled_emails_placeholder_help()['[event_excerpt]'], npmp_scheduled_emails_placeholder_help()['[first_name]'] ) );
check( '[first_name] is left for the send to fill', '[first_name default="Member"]', npmp_scheduled_emails_fill( '[first_name default="Member"]', $map, true ) );

add_filter(
	'npmp_scheduled_email_placeholders',
	function ( $m ) {
		$m['[group_name]'] = 'Art';
		return $m;
	}
);
check( 'add-ons can add placeholders', 'Art', npmp_scheduled_emails_placeholders( 12 )['[group_name]'] );
$GLOBALS['t_filters'] = array();

echo "Subject choice\n";
check( 'own subject first', 'Mine', npmp_scheduled_emails_pick_subject( 'Mine', array( 'subject' => 'Body' ) ) );
check( 'then the body subject', 'Body', npmp_scheduled_emails_pick_subject( ' ', array( 'subject' => 'Body' ) ) );
check( 'then the event title', '[event_title]', npmp_scheduled_emails_pick_subject( '', array( 'subject' => '' ) ) );

echo "Built-in bodies use only known placeholders\n";
$known = array_keys( npmp_scheduled_emails_placeholder_help() );
foreach ( npmp_scheduled_emails_builtin_bodies() as $key => $body ) {
	preg_match_all( '/\[[a-z_]+\]/', $body['content'] . $body['subject'], $m );
	check( "{$key} has no unknown placeholder", array(), array_values( array_diff( array_unique( $m[0] ), $known ) ) );
	check( "{$key} has no em dash or semicolon", 0, preg_match( '/\x{2014}|;/u', wp_strip_all_tags( $body['content'] ) ) );
}

echo "Timing words\n";
check( '1440 minutes', '1 day before', npmp_scheduled_emails_describe_minutes( 1440 ) );
check( '300 minutes', '5 hours before', npmp_scheduled_emails_describe_minutes( 300 ) );
check( '60 minutes', '1 hour before', npmp_scheduled_emails_describe_minutes( 60 ) );
check( 'custom hours read back', 300, npmp_scheduled_emails_read_timing( array( 'timing' => 'custom', 'custom_hours' => '5' ) ) );
check( 'blank timing means site default', 0, npmp_scheduled_emails_read_timing( array( 'timing' => '' ) ) );
check( 'preset timing', 4320, npmp_scheduled_emails_read_timing( array( 'timing' => '4320' ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
