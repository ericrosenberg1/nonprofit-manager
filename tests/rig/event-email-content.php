<?php
// Usage:
//   python3 tests/rig/smtp-sink.py 10026 /tmp/npmp-ec-sink.jsonl &
//   NPMP_RIG_WP=/path/to/wp NPMP_RIG_SINK=/tmp/npmp-ec-sink.jsonl NPMP_RIG_SINK_PORT=10026 php tests/rig/event-email-content.php
//
// The four event email reports from Pete at U3A (wordpress.org, 2026-11-12
// and 2026-11-13), on a real WordPress with mail going out over SMTP to
// smtp-sink.py, which keeps every message whole (headers and all parts):
//
// 1. An event with three paragraphs, a heading, a list, an embed, a form,
//    a script, and a search box. [event_excerpt] keeps the three paragraphs,
//    and [event_description] brings the formatting with the web-only parts
//    left out, through the real sweep and queue.
// 2. "Send a test to me" (the AJAX button, free's or Pro's) fills
//    [first_name], the subject, [group_name], and [leave_group_url] the way
//    the real send fills them.
// 3. The test and the queued email of the same event, to the same person,
//    compared: calendar parts, headers, the [ical_link] address, the date
//    words, and the body. The run prints what differs.
// 4. The event box on a new event lists the planned emails, and its
//    refresh after a save shows the send time.
//
// Run it with Pro active and with Pro inactive. Needs the members,
// newsletters and calendar modules on (option npmp_enabled_features).
// Removes what it made.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$sink_file = getenv( 'NPMP_RIG_SINK' ) ?: '/tmp/npmp-ec-sink.jsonl';
$sink_port = (int) ( getenv( 'NPMP_RIG_SINK_PORT' ) ?: 10026 );
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
require_once ABSPATH . 'wp-admin/includes/post.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function rig_has( $label, $needle, $haystack, $want = true ) {
	rig_check( $label, $want, false !== strpos( (string) $haystack, $needle ) );
}
if ( ! class_exists( 'NPMP_Newsletter_Manager' ) || ! post_type_exists( 'npmp_event' ) ) {
	fwrite( STDERR, "Turn on the members, newsletters and calendar modules first.\n" );
	exit( 2 );
}
$pro = function_exists( 'npmp_pro_sched_render_event_box' );
echo $pro ? "Pro active\n" : "Pro inactive\n";

/* ---------------------------------------------------------------------------
 * Mail over SMTP to the sink, and AJAX handlers called in-process.
 * ------------------------------------------------------------------------ */

class Rig_Ajax_Done extends Exception {}
function rig_ajax( $callback, $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	$doing    = function () { return true; };
	$die      = function () { return function () { throw new Rig_Ajax_Done(); }; };
	add_filter( 'wp_doing_ajax', $doing );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		call_user_func( $callback );
	} catch ( Rig_Ajax_Done $e ) {
		unset( $e );
	}
	$raw = (string) ob_get_clean();
	remove_filter( 'wp_doing_ajax', $doing );
	remove_filter( 'wp_die_ajax_handler', $die );
	$_POST    = array();
	$_REQUEST = array();
	return json_decode( $raw, true );
}

function rig_clear( $sink_file ) {
	if ( file_exists( $sink_file ) ) {
		unlink( $sink_file );
	}
}
function rig_sink_messages( $sink_file ) {
	clearstatcache();
	$out = array();
	foreach ( file_exists( $sink_file ) ? file( $sink_file, FILE_IGNORE_NEW_LINES ) : array() as $line ) {
		$msg = json_decode( $line, true );
		if ( $msg ) {
			$out[] = rig_parse_message( (string) $msg['data'], (array) $msg['rcpt_to'] );
		}
	}
	return $out;
}
function rig_parse_message( $raw, $rcpt ) {
	$raw   = str_replace( "\r\n", "\n", $raw );
	$parts = explode( "\n\n", $raw, 2 );
	$head  = preg_replace( "/\n[ \t]+/", ' ', $parts[0] );
	$body  = $parts[1] ?? '';
	$h     = array();
	foreach ( explode( "\n", $head ) as $l ) {
		if ( false !== strpos( $l, ':' ) ) {
			list( $k, $v ) = explode( ':', $l, 2 );
			$h[ strtolower( trim( $k ) ) ][] = trim( $v );
		}
	}
	$cte = strtolower( $h['content-transfer-encoding'][0] ?? '' );
	if ( 'quoted-printable' === $cte ) {
		$body = quoted_printable_decode( $body );
	} elseif ( 'base64' === $cte ) {
		$body = base64_decode( $body );
	}
	return array(
		'to'      => strtolower( implode( ',', $rcpt ) ),
		'headers' => $h,
		'subject' => iconv_mime_decode( $h['subject'][0] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ),
		'body'    => $body,
		'raw'     => $raw,
	);
}

// Without the sink every send fails, so say so before touching any setting.
$rig_sock = @fsockopen( '127.0.0.1', $sink_port, $rig_errno, $rig_errstr, 2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A closed port is the answer we look for.
if ( ! $rig_sock ) {
	fwrite( STDERR, "Start the SMTP sink first: python3 tests/rig/smtp-sink.py {$sink_port} {$sink_file}\n" );
	exit( 2 );
}
fclose( $rig_sock );

// Put the site's mail settings back however the run ends, so a failure
// never leaves the site sending to the sink.
$saved_mail = get_option( 'npmp_email_settings' );
register_shutdown_function(
	static function () use ( $saved_mail ) {
		false === $saved_mail ? delete_option( 'npmp_email_settings' ) : update_option( 'npmp_email_settings', $saved_mail );
	}
);
update_option(
	'npmp_email_settings',
	array(
		'provider'        => 'smtp',
		'from_email'      => 'hello@npmp-rig.test',
		'from_name'       => 'Rig Club',
		'force_from'      => 0,
		'set_return_path' => 0,
		'smtp'            => array(
			'host'       => '127.0.0.1',
			'port'       => $sink_port,
			'encryption' => 'none',
			'auth'       => 1,
			// smtp-sink.py takes any login. The plugin sends nothing over
			// SMTP without one.
			'username'   => 'rig-user',
			'password'   => 'rig-pass',
			'auto_tls'   => 0,
		),
		'aws'             => array( 'region' => 'us-west-2', 'port' => 587 ),
	)
);

/* ---------------------------------------------------------------------------
 * People, a body, an event.
 * ------------------------------------------------------------------------ */

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$owner_email = strtolower( $admin->user_email );
$old_first   = get_user_meta( $admin->ID, 'first_name', true );
$old_last    = get_user_meta( $admin->ID, 'last_name', true );
update_user_meta( $admin->ID, 'first_name', 'Olive' );
update_user_meta( $admin->ID, 'last_name', 'Owner' );

$mm = NPMP_Member_Manager::get_instance();
foreach ( array( $owner_email, 'rig-ec-ann@example.org' ) as $email ) {
	$old = $mm->get_member_by_email( $email );
	if ( $old ) {
		$mm->delete_member( $old->id );
	}
}
$ann = $mm->add_member( array( 'email' => 'rig-ec-ann@example.org', 'name' => 'Ann Able', 'status' => 'subscribed' ) );
add_filter(
	'npmp_newsletter_recipients',
	function ( $recipients ) use ( $owner_email ) {
		return array_values(
			array_filter(
				(array) $recipients,
				function ( $r ) use ( $owner_email ) {
					$r = (array) $r;
					return in_array( strtolower( (string) ( $r['user_email'] ?? $r['email'] ?? '' ) ), array( $owner_email, 'rig-ec-ann@example.org' ), true );
				}
			)
		);
	},
	99
);

add_shortcode( 'rig_ec_form', function () { return '<form action="/join"><label>Your name</label><input name="n"><button>Join now</button></form>'; } );
add_shortcode( 'rig_ec_echo', function () { return 'RAN-TWICE'; } );
$details_hooked_during_render = array();
add_filter(
	'the_content',
	function ( $c ) use ( &$details_hooked_during_render ) {
		$details_hooked_during_render[] = false !== has_filter( 'the_content', 'npmp_append_event_details_to_content' );
		return $c;
	},
	1
);

$event_content = implode(
	"\n\n",
	array(
		'<!-- wp:paragraph --><p>Description of the event</p><!-- /wp:paragraph -->',
		'<!-- wp:paragraph --><p>New line with a <a href="/talks/more">link</a></p><!-- /wp:paragraph -->',
		'<!-- wp:paragraph --><p>Another new line</p><!-- /wp:paragraph -->',
		'<!-- wp:heading --><h2 class="wp-block-heading">Agenda</h2><!-- /wp:heading -->',
		'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Tea</li><!-- /wp:list-item --><!-- wp:list-item --><li>Talk</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
		'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=rigvideo1","type":"video","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\nhttps://www.youtube.com/watch?v=rigvideo1\n" . '</div></figure><!-- /wp:embed -->',
		'<!-- wp:html --><script>alert("rig")</script><p>From the HTML block</p><!-- /wp:html -->',
		'<!-- wp:shortcode -->[rig_ec_form]<!-- /wp:shortcode -->',
		'<!-- wp:search {"label":"Search","buttonText":"Search"} /-->',
		'<!-- wp:paragraph --><p>Say [[rig_ec_echo]] at the door.</p><!-- /wp:paragraph -->',
	)
);
$start_local = wp_date( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) . ' 19:00:00';
$event       = wp_insert_post(
	array(
		'post_type'    => 'npmp_event',
		'post_status'  => 'draft',
		'post_title'   => "RigEC Pete's Talk",
		'post_content' => $event_content,
		'meta_input'   => array(
			'_npmp_event_start'    => $start_local,
			'_npmp_event_location' => 'Church Hall',
		),
	)
);
$body = wp_insert_post(
	array(
		'post_type'    => 'npmp_email_body',
		'post_status'  => 'publish',
		'post_title'   => 'RigEC body',
		'post_content' => implode(
			"\n\n",
			array(
				'<!-- wp:paragraph --><p>Hi [first_name default="Member"],</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>Our next event is <strong>[event_title]</strong>, on <strong>[event_date] at [event_time]</strong>.</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>[event_excerpt words="200"]</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>Full details:</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>[event_description]</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>From [group_name]. <a href="[event_url]">Read the details</a> and <a href="[ical_link]">add it to your calendar</a>. <a href="http://[leave_group_url]">Leave these emails</a></p><!-- /wp:paragraph -->',
			)
		),
	)
);
update_post_meta( $body, '_npmp_body_subject', 'Next week: [event_title] for [first_name default="you"]' );
update_post_meta( $body, '_npmp_newsletter_template', 'none' );

$saved_defaults = get_option( 'npmp_scheduled_email_defaults' );
update_option( 'npmp_scheduled_email_defaults', npmp_scheduled_email_sanitize_defaults( array_merge( npmp_scheduled_email_defaults(), array( 'enabled' => 1, 'minutes_before' => 1440, 'body_id' => $body, 'subject' => '' ) ) ) );
$group = 0;
if ( $pro ) {
	$group = npmp_pro_ensure_group( 'RigEC Art' );
	npmp_pro_update_group_settings( $group, array( 'sender_name' => 'RigEC Art Group', 'reply_to' => 'art@npmp-rig.test', 'footer' => 'RigEC Art meets on Fridays.' ) );
	npmp_pro_set_member_groups( $ann, array( $group ) );
	wp_set_object_terms( $event, array( $group ), 'npmp_group' );
	npmp_pro_sched_update_event_settings( $event, 'own', array( array( 'minutes_before' => 1440, 'body_id' => $body, 'subject' => '' ) ) );
}

/* ---------------------------------------------------------------------------
 * 4. The box on a new event, before and after a save.
 * ------------------------------------------------------------------------ */

echo "== 4. Event box before the first save ==\n";
$draft = get_default_post_to_edit( 'npmp_event', true );
ob_start();
if ( $pro ) {
	npmp_pro_sched_render_event_box( $draft );
} else {
	npmp_scheduled_emails_render_event_box( $draft );
}
$box = (string) ob_get_clean();
rig_has( 'No "Nothing scheduled yet" on a new event', 'Nothing scheduled yet', $box, false );
if ( $pro ) {
	rig_has( 'Lists the week-before email', '1 week before the event, to every subscribed member', $box );
	rig_has( 'Lists the day-before email', '1 day before the event, to every subscribed member', $box );
} else {
	rig_has( 'Names the planned email', 'Planned for 1 day before the event, to every subscribed member.', $box );
}
rig_has( 'Says what the event still needs', 'Add a start date and publish the event to put it on the schedule.', $box );
rig_has( 'Links to every scheduled email', 'page=npmp_scheduled_emails', $box );
rig_has( 'Carries the refresh container', 'class="npmp-sched-live" data-event="' . $draft->ID . '"', $box );

// The block editor saves the post, then the boxes, and the page stays. The
// script then asks for the box's changing part again.
update_post_meta( $draft->ID, '_npmp_event_start', $start_local );
wp_update_post( array( 'ID' => $draft->ID, 'post_status' => 'publish', 'post_title' => 'RigEC new event' ) );
$res      = rig_ajax( 'npmp_scheduled_emails_ajax_box_status', array( 'event_id' => $draft->ID, 'nonce' => wp_create_nonce( 'npmp_sched_email_' . $draft->ID ) ) );
$fresh    = (string) ( $res['data']['html'] ?? '' );
$send_day = wp_date( (string) get_option( 'date_format' ), ( new DateTimeImmutable( $start_local, wp_timezone() ) )->modify( '-1 day' )->getTimestamp() );
rig_check( 'Refresh answers', true, ! empty( $res['success'] ) );
rig_has( 'Refresh shows the send day', $send_day, $fresh );
rig_has( 'Refresh drops the plan', 'Planned', $fresh, false );
$res = rig_ajax( 'npmp_scheduled_emails_ajax_box_status', array( 'event_id' => $draft->ID, 'nonce' => 'nope' ) );
rig_check( 'Refresh refuses a bad nonce', false, ! empty( $res['success'] ) );
wp_delete_post( $draft->ID, true );

/* ---------------------------------------------------------------------------
 * 2. "Send a test to me", owner with no contact, then with one.
 * ------------------------------------------------------------------------ */

wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
npmp_scheduled_emails_sync_event( $event );
$rows = array_values( array_filter( npmp_scheduled_emails_get_rows( 'event', $event ), function ( $r ) { return 'pending' === $r->status; } ) );
rig_check( 'The event has one pending email', 1, count( $rows ) );
$row = $rows[0] ?? null;

$send_test = function () use ( $pro, $event, $row ) {
	if ( $pro ) {
		return rig_ajax( 'npmp_pro_sched_ajax_event', array( 'event_id' => $event, 'row_id' => $row ? $row->id : 0, 'op' => 'test', 'nonce' => wp_create_nonce( 'npmp_pro_sched_' . $event ) ) );
	}
	return rig_ajax( 'npmp_scheduled_emails_ajax_event', array( 'event_id' => $event, 'op' => 'test', 'nonce' => wp_create_nonce( 'npmp_sched_email_' . $event ) ) );
};

echo "== 2. Test send, the owner has no contact ==\n";
rig_clear( $sink_file );
$res  = $send_test();
$msgs = rig_sink_messages( $sink_file );
rig_check( 'Test sent', true, ! empty( $res['success'] ) && 1 === count( $msgs ) );
$t0 = $msgs[0] ?? array( 'body' => '', 'subject' => '' );
rig_has( 'Greets the owner by the profile first name', 'Hi Olive,', $t0['body'] );
rig_check( 'Subject filled for the owner', "[Test] Next week: RigEC Pete\u{2019}s Talk for Olive", $t0['subject'] );

echo "== 2. Test send, the owner has a contact ==\n";
$owner_contact = $mm->add_member( array( 'email' => $owner_email, 'name' => 'Pat Rig', 'status' => 'subscribed' ) );
if ( $pro ) {
	npmp_pro_set_member_groups( $owner_contact, array( $group ) );
}
rig_clear( $sink_file );
$res  = $send_test();
$msgs = rig_sink_messages( $sink_file );
$test = $msgs[0] ?? array( 'body' => '', 'subject' => '', 'headers' => array(), 'raw' => '' );
rig_check( 'Test sent', true, ! empty( $res['success'] ) && 1 === count( $msgs ) );
rig_has( 'Greets the contact by first name', 'Hi Pat,', $test['body'] );
rig_check( 'No recipient tag left in the test', 0, preg_match( '/\[(first_name|last_name|name)\b/', $test['body'] . $test['subject'] ) );
if ( $pro ) {
	rig_has( '[group_name] names the row\'s group', 'From RigEC Art.', $test['body'] );
	rig_has( '[leave_group_url] is a working leave link', 'npmp_leave_group', $test['body'] . $test['raw'] );
	rig_has( 'No leave marker left', '#npmp-leave-group', $test['body'], false );
	rig_has( 'Group footer line', 'RigEC Art meets on Fridays.', $test['body'] );
	rig_has( 'Group footer leave link', 'Leave RigEC Art emails', $test['body'] );
	rig_has( 'Group sender name', 'RigEC Art Group', implode( ' ', $test['headers']['from'] ?? array() ) );
	rig_has( 'Group Reply-To', 'art@npmp-rig.test', implode( ' ', $test['headers']['reply-to'] ?? array() ) );
}

/* ---------------------------------------------------------------------------
 * 1. The real send: sweep, queue, SMTP.
 * ------------------------------------------------------------------------ */

echo "== 1. Real send through the sweep and queue ==\n";
rig_clear( $sink_file );
$due = npmp_scheduled_emails_utc_to_ts( $row ? $row->send_at : gmdate( 'Y-m-d H:i:s' ) ) + 60;
add_filter( 'npmp_scheduled_emails_now', function () use ( $due ) { return $due; } );
$sent = npmp_scheduled_emails_sweep();
// One run sends the oldest rows first, up to the rate limit, so rows another
// rig left pending can push this email past the first run. Drain the queue.
for ( $rig_i = 0; $rig_i < 50 && (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}npmp_newsletter_queue WHERE status = 'pending'" ) > 0; $rig_i++ ) {
	NPMP_Newsletter_Manager::process_queue();
}
$msgs = rig_sink_messages( $sink_file );
$real = null;
$ann_msg = null;
foreach ( $msgs as $m ) {
	if ( $owner_email === $m['to'] ) {
		$real = $m;
	} elseif ( 'rig-ec-ann@example.org' === $m['to'] ) {
		$ann_msg = $m;
	}
}
// The sweep is site-wide, so with Pro's default list another event left on the
// site can be due too. Check that this event's row went, not the total.
$row_now = $row ? npmp_scheduled_emails_get_row( (int) $row->id ) : null;
rig_check( 'Sweep sent the email', true, $sent >= 1 && $row_now && 'sent' === $row_now->status );
rig_check( 'Owner and Ann each got one', true, $real && $ann_msg );
$real = $real ?? array( 'body' => '', 'subject' => '', 'headers' => array(), 'raw' => '' );
$b    = $real['body'];
rig_has( 'Excerpt: one <p> per paragraph', 'Description of the event</p><p>New line with a link</p><p>Another new line</p>', $b );
rig_has( 'Description: the paragraph keeps its link, now absolute', '>New line with a <a href="' . home_url( '/talks/more' ) . '">link</a></p>', $b );
rig_has( 'Description: the heading', '<h2 class="wp-block-heading">Agenda</h2>', $b );
rig_has( 'Description: the list', '<li>Tea</li>', $b );
rig_has( 'Description: the embed is a link', '<a href="https://www.youtube.com/watch?v=rigvideo1">', $b );
rig_has( 'Description: the HTML block\'s text', 'From the HTML block', $b );
rig_check( 'No script, form, field, frame, or search box', 0, preg_match( '/<script|<form|<input|<button|<iframe|wp-block-search|alert\(/i', $b ) );
rig_has( 'No form label text either', 'Your name', $b, false );
rig_has( 'No editor comments', '<!--', $b, false );
rig_has( 'A shortcode shown as text stays text', 'rig_ec_echo', $b );
rig_has( 'and never runs on the second pass', 'RAN-TWICE', $b, false );
rig_has( 'No Event Details box', 'npmp-event-details', $b, false );
rig_check( 'The details box is off the_content during every email render', array(), array_values( array_filter( $details_hooked_during_render ) ) );
rig_has( 'Ann gets her own name', 'Hi Ann,', $ann_msg['body'] ?? '' );

/* ---------------------------------------------------------------------------
 * 3. Test against real, same event, same person.
 * ------------------------------------------------------------------------ */

echo "== 3. Test and real send compared (owner, same event) ==\n";
$calendar = function ( $m ) {
	return array(
		'multipart'     => (bool) preg_match( '/multipart\//i', implode( ' ', $m['headers']['content-type'] ?? array() ) ),
		'text/calendar' => false !== stripos( $m['raw'], 'text/calendar' ),
		'.ics'          => (bool) preg_match( '/\.ics\b/i', $m['raw'] ),
		'VCALENDAR'     => false !== strpos( $m['raw'], 'BEGIN:VCALENDAR' ),
	);
};
$ical = function ( $m ) {
	return preg_match( '/<a href="([^"]*)">add it to your calendar/', $m['body'], $x ) ? html_entity_decode( $x[1] ) : '';
};
$date_words = function ( $m ) {
	return preg_match( '/on <strong>([^<]*)<\/strong>/', $m['body'], $x ) ? $x[1] : '';
};
printf( "  calendar parts: test %s | real %s\n", json_encode( $calendar( $test ) ), json_encode( $calendar( $real ) ) );
rig_check( 'Neither message carries a calendar part', array( false, false ), array( in_array( true, $calendar( $test ), true ), in_array( true, $calendar( $real ), true ) ) );
rig_check( 'Same Content-Type', $test['headers']['content-type'] ?? null, $real['headers']['content-type'] ?? null );
rig_check( 'Same [ical_link] address', $ical( $test ), $ical( $real ) );
rig_check( 'Same date words', $date_words( $test ), $date_words( $real ) );
printf( "  date words: \"%s\"\n", $date_words( $real ) );
rig_check( 'Subjects differ only by [Test]', $test['subject'], '[Test] ' . $real['subject'] );
$names_t = array_keys( $test['headers'] );
$names_r = array_keys( $real['headers'] );
sort( $names_t );
sort( $names_r );
printf( "  headers only in the test: %s\n", implode( ', ', array_diff( $names_t, $names_r ) ) ?: 'none' );
printf( "  headers only in the real send: %s\n", implode( ', ', array_diff( $names_r, $names_t ) ) ?: 'none' );
foreach ( array( 'from', 'reply-to', 'list-unsubscribe', 'list-unsubscribe-post', 'mime-version', 'content-transfer-encoding' ) as $hname ) {
	$tv = implode( ' | ', $test['headers'][ $hname ] ?? array() );
	$rv = implode( ' | ', $real['headers'][ $hname ] ?? array() );
	printf( "  %-26s %s\n", $hname, $tv === $rv ? 'same' : "test: {$tv}  real: {$rv}" );
}
// The real send adds an open pixel (and, with click tracking on, tracked
// links). Leave those out, then the bodies should match.
$norm = function ( $html ) {
	$html = preg_replace( '/<img src="[^"]*npmp_track[^"]*"[^>]*>|<img src="[^"]*npmp-open[^"]*"[^>]*>|<img[^>]*width="1" height="1"[^>]*>/', '', (string) $html );
	// Signed links carry the time they were made.
	$html = preg_replace( '/\b(expires|token)=[^&"\s]+/', '$1=x', (string) $html );
	return trim( preg_replace( '/\s+/', ' ', $html ) );
};
$tb = $norm( $test['body'] );
$rb = $norm( $real['body'] );
if ( $tb !== $rb ) {
	$tl = preg_split( '/(?=<p|<h2|<ul|<li)/', $tb );
	$rl = preg_split( '/(?=<p|<h2|<ul|<li)/', $rb );
	foreach ( array_diff( $tl, $rl ) as $l ) {
		echo '  only in test: ', substr( $l, 0, 160 ), "\n";
	}
	foreach ( array_diff( $rl, $tl ) as $l ) {
		echo '  only in real: ', substr( $l, 0, 160 ), "\n";
	}
}
rig_check( 'Bodies match once the open pixel is out', $tb, $rb );

/* ---------------------------------------------------------------------------
 * Clean up.
 * ------------------------------------------------------------------------ */

global $wpdb;
$table = npmp_scheduled_emails_table();
foreach ( get_posts( array( 'post_type' => 'npmp_newsletter', 'numberposts' => -1, 'post_status' => 'any', 'meta_key' => '_npmp_newsletter_scheduled_event', 'meta_value' => $event, 'fields' => 'ids' ) ) as $nl ) { // phpcs:ignore WordPress.DB.SlowDBQuery
	wp_delete_post( $nl, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE target_type = 'event' AND target_id = %d", $event ) ); // phpcs:ignore WordPress.DB
wp_delete_post( $event, true );
wp_delete_post( $body, true );
$mm->delete_member( $ann );
$mm->delete_member( $owner_contact );
if ( $group ) {
	wp_delete_term( $group, 'npmp_group' );
}
update_user_meta( $admin->ID, 'first_name', $old_first );
update_user_meta( $admin->ID, 'last_name', $old_last );
false === $saved_mail ? delete_option( 'npmp_email_settings' ) : update_option( 'npmp_email_settings', $saved_mail );
if ( false === $saved_defaults ) {
	delete_option( 'npmp_scheduled_email_defaults' );
} else {
	update_option( 'npmp_scheduled_email_defaults', $saved_defaults );
}

rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
