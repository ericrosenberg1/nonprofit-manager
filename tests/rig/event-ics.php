<?php
/**
 * Real-WordPress check of the meeting details in iCal (Pete, topic
 * "meeting-links"): the public feed and the .ics file on event emails.
 *
 * Usage, with free (and optionally Pro) active and the calendar and
 * newsletters on:
 *   python3 tests/rig/smtp-sink.py 10426 /tmp/npmp-ics-sink.jsonl &
 *   php -S <siteurl host:port> -t <wp dir> &        (for the feed over HTTP)
 *   NPMP_RIG_SINK=/tmp/npmp-ics-sink.jsonl NPMP_RIG_SINK_PORT=10426 wp eval-file tests/rig/event-ics.php
 *
 * Checks:
 * - The feed over HTTP: a public event's link in LOCATION, URL, and
 *   DESCRIPTION. An email-only event keeps its link, ID, and passcode out.
 *   With Pro, a group's default link stays out too. Lines fold at 75 octets.
 * - An event email over custom SMTP and over WordPress's own mailer arrives
 *   with the .ics attached, link and all, even for an email-only event.
 * - "Send a test to me" carries the file.
 * - A plain newsletter carries no file.
 * - With Pro on SendGrid (API faked), the email still goes through SendGrid,
 *   with no file, and never falls back to WordPress's mailer.
 *
 * Creates and deletes its own events and newsletters. Restores the email
 * settings it found.
 */

global $wpdb;

$fails  = 0;
$passes = 0;
$ok     = function ( $label, $cond ) use ( &$fails, &$passes ) {
	if ( ! $cond ) {
		$fails++;
	} else {
		$passes++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_event_ics_for_email' ) || ! function_exists( 'npmp_maybe_render_ical_feed' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
	update_option( 'npmp_enabled_features', array_merge( (array) get_option( 'npmp_enabled_features', array() ), array( 'members' => true, 'newsletters' => true, 'calendar' => true ) ) );
	echo "  NOTE  turned on members, newsletters, and calendar, run the rig again\n";
	exit( 1 );
}

$sink_file = getenv( 'NPMP_RIG_SINK' ) ?: '/tmp/npmp-ics-sink.jsonl';
$sink_port = (int) ( getenv( 'NPMP_RIG_SINK_PORT' ) ?: 10426 );
$pro       = function_exists( 'npmp_pro_handle_email' );
wp_set_current_user( 1 );

$made  = array();
$event = function ( $title, $meta, $groups = array() ) use ( &$made ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'npmp_event',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => sanitize_title( $title ),
		)
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	if ( $groups && taxonomy_exists( 'npmp_group' ) ) {
		wp_set_object_terms( $id, array_map( 'intval', $groups ), 'npmp_group' );
	}
	$made[] = (int) $id;
	return (int) $id;
};
$start = wp_date( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
$end   = wp_date( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS + HOUR_IN_SECONDS );

$public = $event(
	'Computing, monthly meeting with a long title that folds past seventy-five octets',
	array(
		'_npmp_event_start'      => $start,
		'_npmp_event_end'        => $end,
		'_npmp_event_location'   => 'Hall A',
		'_npmp_event_url'        => 'https://zoom.example/j/1111111?pwd=public',
		'_npmp_event_meeting_id' => '111 1111',
		'_npmp_event_passcode'   => 'opensesame',
	)
);
$private = $event(
	'Board meeting',
	array(
		'_npmp_event_start'           => $start,
		'_npmp_event_end'             => $end,
		'_npmp_event_location'        => 'Library',
		'_npmp_event_url'             => 'https://zoom.example/j/2222222?pwd=secret',
		'_npmp_event_meeting_id'      => '222 2222',
		'_npmp_event_passcode'        => 'hushhush',
		'_npmp_event_link_email_only' => '1',
	)
);
$group_id = 0;
$grouped  = 0;
if ( $pro && taxonomy_exists( 'npmp_group' ) ) {
	$group_id = (int) npmp_pro_ensure_group( 'Rig ICS Group' );
	update_term_meta( $group_id, 'npmp_group_meeting_url', 'https://zoom.example/j/3333333?pwd=group' );
	update_term_meta( $group_id, 'npmp_group_meeting_id', '333 3333' );
	update_term_meta( $group_id, 'npmp_group_link_email_only', '1' );
	$grouped = $event( 'Group night', array( '_npmp_event_start' => $start, '_npmp_event_location' => 'Room 3' ), array( $group_id ) );
}

echo "The feed over HTTP\n";
delete_transient( 'npmp_ical_feed' );
update_option( 'npmp_ical_feed_gen', absint( get_option( 'npmp_ical_feed_gen', 0 ) ) + 1, false );
$r = wp_remote_get( add_query_arg( 'npmp-ical', '1', home_url( '/' ) ), array( 'timeout' => 20 ) );
if ( is_wp_error( $r ) ) {
	echo '  SKIP  no web server answers at ' . esc_url_raw( home_url( '/' ) ) . "\n";
} else {
	$feed   = wp_remote_retrieve_body( $r );
	$unfold = str_replace( "\r\n ", '', $feed );
	$ok( 'the feed answers as text/calendar', 200 === wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'text/calendar' ) );
	$ok( 'a public event has its link after the place in LOCATION', false !== strpos( $unfold, "LOCATION:Hall A\\, https://zoom.example/j/1111111?pwd=public\r\n" ) );
	$ok( 'and in URL', false !== strpos( $unfold, "URL:https://zoom.example/j/1111111?pwd=public\r\n" ) );
	$ok( 'and in DESCRIPTION with the ID and passcode', false !== strpos( $unfold, "DESCRIPTION:Join link: https://zoom.example/j/1111111?pwd=public\\nMeeting ID: 111 1111\\nPasscode: opensesame\r\n" ) );
	$ok( 'the email-only event is in the feed', false !== strpos( $unfold, 'SUMMARY:Board meeting' ) && false !== strpos( $unfold, "LOCATION:Library\r\n" ) );
	$ok( 'its link, ID, and passcode never are', false === strpos( $feed, '2222222' ) && false === strpos( $feed, '222 2222' ) && false === strpos( $feed, 'hushhush' ) && false === strpos( $unfold, 'pwd=secret' ) );
	if ( $grouped ) {
		$ok( 'a group\'s default link stays out of the feed', false !== strpos( $unfold, 'SUMMARY:Group night' ) && false === strpos( $unfold, '3333333' ) && false === strpos( $unfold, '333 3333' ) );
	}
	$long = 0;
	foreach ( explode( "\r\n", $feed ) as $line ) {
		$long = max( $long, strlen( $line ) );
	}
	$ok( 'no feed line passes 75 octets', $long <= 75 );
	$ok( 'the long title folded and unfolds whole', false !== strpos( $feed, "\r\n " ) && false !== strpos( $unfold, 'SUMMARY:Computing\\, monthly meeting with a long title that folds past seventy-five octets' ) );
}

echo "The .ics for emails\n";
$ics = str_replace( "\r\n ", '', npmp_event_ics_for_email( $private ) );
$ok( 'an email-only event\'s email file has the link, ID, and passcode', false !== strpos( $ics, "URL:https://zoom.example/j/2222222?pwd=secret\r\n" ) && false !== strpos( $ics, 'Meeting ID: 222 2222' ) && false !== strpos( $ics, 'Passcode: hushhush' ) );
$ok( 'the file says METHOD:PUBLISH and matches the feed UID', false !== strpos( $ics, "METHOD:PUBLISH\r\n" ) && false !== strpos( $ics, 'UID:' . $private . '@' ) );
if ( $grouped ) {
	$gics = str_replace( "\r\n ", '', npmp_event_ics_for_email( $grouped ) );
	$ok( 'a group event\'s email file has the group\'s link', false !== strpos( $gics, 'URL:https://zoom.example/j/3333333?pwd=group' ) && false !== strpos( $gics, "LOCATION:Room 3\\, https://zoom.example/j/3333333?pwd=group\r\n" ) );
}
$ok( 'a post that isn\'t an event has no file', '' === npmp_event_ics_for_email( 1 ) );

// Sends, recorded by the SMTP sink.
$saved = get_option( 'npmp_email_settings' );
$smtp  = array(
	'provider'        => 'smtp',
	'from_email'      => 'hello@npmp-rig.test',
	'from_name'       => 'Rig Council',
	'force_from'      => 0,
	'from_all_mail'   => 0,
	'set_return_path' => 0,
	'smtp'            => array(
		'host'       => '127.0.0.1',
		'port'       => $sink_port,
		'encryption' => 'none',
		// The sink takes any login, and free's transport wants one.
		'auth'       => 1,
		'username'   => 'rig-user',
		'password'   => 'rig-pass',
		'auto_tls'   => 0,
	),
);
$newsletter = function ( $event_id ) use ( &$made ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'npmp_newsletter',
			'post_status'  => 'publish',
			'post_title'   => 'Rig ICS email',
			'post_content' => '<p>See you there.</p>',
		)
	);
	if ( $event_id ) {
		update_post_meta( $id, '_npmp_newsletter_scheduled_event', (int) $event_id );
	}
	$made[] = (int) $id;
	return (int) $id;
};
$last = function () use ( $sink_file ) {
	clearstatcache();
	$lines = file_exists( $sink_file ) ? file( $sink_file, FILE_IGNORE_NEW_LINES ) : array();
	$msg   = $lines ? json_decode( end( $lines ), true ) : null;
	return $msg ? (string) $msg['data'] : '';
};
$attached = function ( $raw, $name ) {
	return (bool) preg_match( '/Content-Type: text\/calendar; name=' . preg_quote( $name, '/' ) . '/i', $raw );
};
$decoded = function ( $raw ) {
	if ( preg_match( '/Content-Type: text\/calendar;.*?\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+?)\r?\n\r?\n?--/s', $raw, $m ) ) {
		return (string) base64_decode( preg_replace( '/\s+/', '', $m[1] ) );
	}
	return '';
};
$ev_email  = $newsletter( $private );
$plain     = $newsletter( 0 );
$test_once = null;

try {
	echo "Custom SMTP\n";
	update_option( 'npmp_email_settings', $smtp );
	$ok( 'custom SMTP carries attachments', npmp_mail_attachments_supported() );
	@unlink( $sink_file );
	$sent = NPMP_Newsletter_Manager::send_email( 'member@example.org', 'Board meeting tomorrow', '<p>See you there.</p>', $ev_email );
	$raw  = $last();
	$ok( 'the event email sends', $sent && '' !== $raw );
	$ok( 'with board-meeting.ics attached', $attached( $raw, 'board-meeting.ics' ) );
	$file = str_replace( "\r\n ", '', $decoded( $raw ) );
	$ok( 'the file in the email has the private link', false !== strpos( $file, 'pwd=secret' ) && false !== strpos( $file, 'Passcode: hushhush' ) );
	@unlink( $sink_file );
	NPMP_Newsletter_Manager::send_email( 'member@example.org', 'News', '<p>News</p>', $plain );
	$raw = $last();
	$ok( 'a plain newsletter has no file', '' !== $raw && ! preg_match( '/text\/calendar/i', $raw ) );

	echo "Send a test to me\n";
	@unlink( $sink_file );
	$body = function_exists( 'npmp_scheduled_emails_bodies' ) ? npmp_scheduled_emails_bodies() : array();
	$body = $body ? (int) ( is_object( reset( $body ) ) ? reset( $body )->ID : key( $body ) ) : 0;
	$test = npmp_scheduled_emails_render_test( $public, $body, 'Rig test', 'admin@example.test' );
	$sent = NPMP_Newsletter_Manager::send_email( 'admin@example.test', '[Test] ' . $test['subject'], $test['html'] );
	$raw  = $last();
	$ok( 'the test carries the event\'s file', $sent && $attached( $raw, sanitize_title( get_the_title( $public ) ) . '.ics' ) );
	@unlink( $sink_file );
	NPMP_Newsletter_Manager::send_email( 'admin@example.test', 'Another one-off', '<p>x</p>' );
	$ok( 'the next one-off send has no file', ! preg_match( '/text\/calendar/i', $last() ) );

	echo "WordPress's own mailer\n";
	update_option( 'npmp_email_settings', array_merge( $smtp, array( 'provider' => 'wordpress' ) ) );
	// Point PHPMailer at the sink after everything else, so nothing leaves the machine.
	$to_sink = static function ( $phpmailer ) use ( $sink_port ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = '127.0.0.1';
		$phpmailer->Port       = $sink_port;
		$phpmailer->SMTPAuth   = false;
		$phpmailer->SMTPSecure = '';
		$phpmailer->SMTPAutoTLS = false;
	};
	add_action( 'phpmailer_init', $to_sink, PHP_INT_MAX );
	$ok( 'WordPress\'s mailer carries attachments', npmp_mail_attachments_supported() );
	@unlink( $sink_file );
	$sent = NPMP_Newsletter_Manager::send_email( 'member@example.org', 'Board meeting tomorrow', '<p>See you there.</p>', $ev_email );
	$ok( 'the event email sends with the file', $sent && $attached( $last(), 'board-meeting.ics' ) );
	remove_action( 'phpmailer_init', $to_sink, PHP_INT_MAX );

	if ( $pro ) {
		echo "Pro on SendGrid (API faked)\n";
		update_option( 'npmp_email_settings', array_merge( $smtp, array( 'provider' => 'sendgrid' ) ) );
		$had_key = get_option( 'npmp_sendgrid_api_key', '' );
		update_option( 'npmp_sendgrid_api_key', 'SG.rig-fake-key' );
		$calls = array();
		$fake  = static function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false !== strpos( $url, 'api.sendgrid.com' ) ) {
				$calls[] = json_decode( (string) $args['body'], true );
				return array( 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'body' => '', 'headers' => array(), 'cookies' => array() );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		$ok( 'SendGrid leaves the file off', ! npmp_mail_attachments_supported() );
		@unlink( $sink_file );
		$sent = NPMP_Newsletter_Manager::send_email( 'member@example.org', 'Board meeting tomorrow', '<p>See you there.</p>', $ev_email );
		$ok( 'the event email still goes through SendGrid', $sent && 1 === count( $calls ) && 'member@example.org' === ( $calls[0]['personalizations'][0]['to'][0]['email'] ?? '' ) );
		$ok( 'with no attachment and nothing through WordPress\'s mailer', empty( $calls[0]['attachments'] ) && '' === $last() );
		remove_filter( 'pre_http_request', $fake, 10 );
		update_option( 'npmp_sendgrid_api_key', $had_key );
	} else {
		echo "  SKIP  Pro is off, so no API sender to check\n";
	}
} finally {
	if ( false === $saved ) {
		delete_option( 'npmp_email_settings' );
	} else {
		update_option( 'npmp_email_settings', $saved );
	}
	foreach ( $made as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $group_id ) {
		wp_delete_term( $group_id, 'npmp_group' );
	}
}

echo "\n{$passes} passed, {$fails} failed\n";
exit( $fails ? 1 : 0 );
