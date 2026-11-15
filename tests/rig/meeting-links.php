<?php
/**
 * Real-WordPress check of an event's meeting ID, passcode, and "Show the
 * meeting link only in emails" (includes/npmp-event-meeting.php).
 *
 * Pete on wordpress.org (topic "meeting-links") asked to keep a Zoom link,
 * ID, and passcode off the public site and still send them to members.
 *
 * - The Event Details box shows the new fields in the block editor's sidebar
 *   and saves them over HTTP the way the editor posts its boxes.
 * - With the box ticked, no public page, list, feed, or API answer anyone can
 *   reach without logging in carries the link, ID, or passcode: the event
 *   page, the /events/ archive and its RSS feed, search, [npmp_events], the
 *   Events block, the calendar's month and list views, the iCal feed, the REST
 *   API, oEmbed and the embed page, and the newsletter archive.
 * - The event's Tomorrow email arrives with all three, and an event email from
 *   such an event never gets a public newsletter page.
 * - An event with the box unticked shows them on its page.
 * - The built-in Tomorrow body picks up the new lines once, and only while
 *   nobody changed it.
 *
 * Needs the site served at its home URL (php -S), pretty permalinks, and
 * members, newsletters, and the calendar on (npmp_enabled_features).
 *
 * Old events must look exactly as before. Run the "before" phase on the
 * released free (origin/main) first, then this phase on the branch:
 *   NPMP_RIG_PHASE=before NPMP_RIG_OUT=<dir> wp eval-file tests/rig/meeting-links.php   (free at origin/main)
 *   NPMP_RIG_OUT=<dir> wp eval-file tests/rig/meeting-links.php                         (this branch)
 * The second run compares the old event's page and Tomorrow email with the
 * first run's copies in <dir>. Without NPMP_RIG_OUT it skips that comparison.
 * Delete the npmp_email_body posts and the npmp_scheduled_email_bodies option
 * before the "before" run, so the released free seeds the old Tomorrow body.
 * Run it with Pro active and with Pro inactive. It deletes what it makes,
 * except the old event and its email, which the second run needs.
 */

$fails  = 0;
$passes = 0;
$ok     = function ( $label, $cond, $detail = '' ) use ( &$fails, &$passes ) {
	$cond ? ++$passes : ++$fails;
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  (' . $detail . ')' : '' );
};

if ( ! post_type_exists( 'npmp_event' ) || ! post_type_exists( 'npmp_newsletter' ) || ! class_exists( 'NPMP_Member_Manager' ) || ! function_exists( 'npmp_scheduled_emails_sweep' ) ) {
	echo "  FAIL  members, newsletters, and the calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}

global $wpdb;
$rig_phase = getenv( 'NPMP_RIG_PHASE' ) ?: 'check';
$rig_out   = (string) getenv( 'NPMP_RIG_OUT' );
$rig_pro   = defined( 'NPMP_PRO_VERSION' );
echo 'Phase ' . $rig_phase . ( $rig_pro ? ', Pro active' : ', Pro inactive' ) . "\n";

$rig_uid = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
wp_set_current_user( $rig_uid );

// One login session for the whole run: a nonce from one page only checks
// out under the same session's cookies.
$rig_cookie_jar = null;
$rig_cookies    = function () use ( $rig_uid, &$rig_cookie_jar ) {
	if ( null !== $rig_cookie_jar ) {
		return $rig_cookie_jar;
	}
	$exp = time() + 600;
	$tok = WP_Session_Tokens::get_instance( $rig_uid )->create( $exp );
	return $rig_cookie_jar = array(
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $rig_uid, $exp, 'auth', $tok ) ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $rig_uid, $exp, 'logged_in', $tok ) ) ),
	);
};
// A visitor who isn't logged in.
$rig_get = function ( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 0 ) );
	return array( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ) );
};

$rig_queue = $wpdb->prefix . 'npmp_newsletter_queue';
$rig_table = npmp_scheduled_emails_table();
$rig_local = function ( $ts ) {
	return wp_date( 'Y-m-d H:i:00', $ts );
};

// Send every due event email and catch what wp_mail() hands over, by address.
$rig_send = function ( $event_ids ) use ( $wpdb, $rig_queue, $rig_table ) {
	foreach ( (array) $event_ids as $event_id ) {
		// phpcs:ignore WordPress.DB -- Throwaway rig.
		$wpdb->query( $wpdb->prepare( "UPDATE {$rig_table} SET status = 'pending', newsletter_id = 0, send_at = %s WHERE target_type = 'event' AND target_id = %d", gmdate( 'Y-m-d H:i:s', time() - 300 ), $event_id ) );
	}
	$mails = array();
	$catch = function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $catch, 10, 2 );
	$due = time() + 120;
	$now = function () use ( $due ) {
		return $due;
	};
	add_filter( 'npmp_scheduled_emails_now', $now );
	npmp_scheduled_emails_sweep();
	// phpcs:ignore WordPress.DB -- Throwaway rig.
	for ( $i = 0; $i < 50 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rig_queue} WHERE status = 'pending'" ) > 0; $i++ ) {
		NPMP_Newsletter_Manager::process_queue();
	}
	remove_filter( 'npmp_scheduled_emails_now', $now );
	remove_filter( 'pre_wp_mail', $catch, 10 );
	return $mails;
};
// With Pro, the schedule also sends "Next week", so match the whole subject.
$rig_mail_for = function ( $mails, $to, $subject ) {
	foreach ( $mails as $m ) {
		$addr = is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'];
		if ( false !== strpos( $addr, $to ) && $subject === (string) $m['subject'] ) {
			return $m;
		}
	}
	return null;
};
// The part of an email or page that changes from run to run: tokens, IDs in
// links, nonces, and asset versions.
$rig_norm = function ( $html ) {
	$html = preg_replace( '/((?:[?&]|&#0?38;|&amp;)(ver|_wpnonce|nid|uid|_npmp|npmp_token|npmp_email|token)=)[^&"\'\s<]*/i', '$1X', (string) $html );
	$html = preg_replace( '/\b(newsletter|npmp_newsletter|post|page-id|postid)[-_]\d+/i', '$1-X', $html );
	$html = preg_replace( '/"nonce":"[^"]*"/', '"nonce":"X"', $html );
	return $html;
};
$rig_old_option = 'npmp_rig_meeting_old_event';
$rig_member     = 'rig-ml-ann@example.org';

$mm = NPMP_Member_Manager::get_instance();
if ( ! $mm->get_member_by_email( $rig_member ) ) {
	$mm->add_member( array( 'email' => $rig_member, 'name' => 'Ann Rig', 'status' => 'subscribed' ) );
}
update_option( 'npmp_scheduled_email_defaults', npmp_scheduled_email_sanitize_defaults( array_merge( npmp_scheduled_email_defaults(), array( 'enabled' => 1 ) ) ) );

/* ---------------------------------------------------------------------------
 * Before: the released free, one old event with a link only.
 * ------------------------------------------------------------------------ */
if ( 'before' === $rig_phase ) {
	if ( '' === $rig_out || ! is_dir( $rig_out ) ) {
		echo "  FAIL  NPMP_RIG_OUT must name a folder\n";
		exit( 1 );
	}
	$old = (int) get_option( $rig_old_option );
	if ( $old ) {
		wp_delete_post( $old, true );
	}
	$old = wp_insert_post(
		array(
			'post_type'    => 'npmp_event',
			'post_status'  => 'publish',
			'post_title'   => 'Rig ML Old Event',
			'post_content' => '<!-- wp:paragraph --><p>An event saved before meeting IDs.</p><!-- /wp:paragraph -->',
			'meta_input'   => array(
				'_npmp_event_start'    => $rig_local( strtotime( '+2 days 19:00', current_time( 'timestamp' ) ) ),
				'_npmp_event_location' => 'Church Hall',
				'_npmp_event_url'      => 'https://zoom.example/j/rigml-old-1',
			),
		)
	);
	update_option( $rig_old_option, $old );
	npmp_scheduled_emails_sync_event( $old );
	list( $code, $page ) = $rig_get( get_permalink( $old ) );
	$ok( 'old event page answers 200', 200 === $code, (string) $code );
	file_put_contents( $rig_out . '/old-page.html', $page );
	$mails = $rig_send( array( $old ) );
	$mail  = $rig_mail_for( $mails, $rig_member, 'Tomorrow: Rig ML Old Event' );
	$ok( 'old event Tomorrow email sent', null !== $mail, count( $mails ) . ' mails' );
	file_put_contents( $rig_out . '/old-email.html', $mail ? $mail['subject'] . "\n\n" . $mail['message'] : '' );
	$body_ids = (array) get_option( 'npmp_scheduled_email_bodies' );
	$tomorrow = get_post( (int) ( $body_ids['tomorrow'] ?? 0 ) );
	$ok( 'the released free seeded the old Tomorrow body', $tomorrow && false === strpos( $tomorrow->post_content, '[meeting_id]' ) );
	echo "\n$passes passed, $fails failed\n";
	exit( $fails ? 1 : 0 );
}

/* ---------------------------------------------------------------------------
 * Check phase.
 * ------------------------------------------------------------------------ */
if ( ! function_exists( 'npmp_event_meeting_details' ) ) {
	echo "  FAIL  this free has no meeting fields (run the check phase on the branch)\n";
	exit( 1 );
}

// The old event's page and Tomorrow email, on this code.
$old = (int) get_option( $rig_old_option );
if ( '' !== $rig_out && file_exists( $rig_out . '/old-page.html' ) ) {
	// First, before this run adds pages and events that the theme would list.
	echo "\nAn old event looks exactly as before\n";
	list( $code, $page ) = $rig_get( get_permalink( $old ) );
	$before = $rig_norm( file_get_contents( $rig_out . '/old-page.html' ) );
	$after  = $rig_norm( $page );
	if ( $before !== $after ) {
		file_put_contents( $rig_out . '/old-page-after.html', $page );
	}
	$ok( 'its page matches the released free\'s, byte for byte after tokens', $before === $after, 'diff ' . $rig_out . '/old-page.html old-page-after.html' );
	$mails = $rig_send( array( $old ) );
	$om    = $rig_mail_for( $mails, $rig_member, 'Tomorrow: Rig ML Old Event' );
	$after = $om ? $om['subject'] . "\n\n" . $om['message'] : '';
	if ( $rig_norm( file_get_contents( $rig_out . '/old-email.html' ) ) !== $rig_norm( $after ) ) {
		file_put_contents( $rig_out . '/old-email-after.html', $after );
	}
	$ok( 'its Tomorrow email matches too, though the body now has the meeting lines', '' !== $after && $rig_norm( file_get_contents( $rig_out . '/old-email.html' ) ) === $rig_norm( $after ), 'diff ' . $rig_out . '/old-email.html old-email-after.html' );
}


$rig_made  = array();
$rig_start = strtotime( '+3 days 18:30', current_time( 'timestamp' ) );
$rig_event = function ( $title, $meta ) use ( &$rig_made, $rig_local, $rig_start ) {
	$id         = wp_insert_post(
		array(
			'post_type'    => 'npmp_event',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_excerpt' => 'Rig ML excerpt for ' . $title,
			'post_content' => '<!-- wp:paragraph --><p>Rig ML body for ' . $title . '.</p><!-- /wp:paragraph -->',
			'meta_input'   => array_merge(
				array(
					'_npmp_event_start'    => $rig_local( $rig_start ),
					'_npmp_event_end'      => $rig_local( $rig_start + HOUR_IN_SECONDS ),
					'_npmp_event_location' => 'Online',
				),
				$meta
			),
		)
	);
	$rig_made[] = $id;
	return $id;
};

$priv_url  = 'https://zoom.example/j/rigml-secret-771';
$priv_id   = 'rigml-id-8675';
$priv_pass = 'rigml-pass-4242';
$open_url  = 'https://zoom.example/j/rigml-open-55';
$open_id   = 'rigml-openid-31';
$open_pass = 'rigml-openpass-9';
$secrets   = array( $priv_url, 'rigml-secret-771', $priv_id, $priv_pass );
$leaks     = function ( $html ) use ( $secrets ) {
	$found = array();
	foreach ( $secrets as $s ) {
		if ( false !== stripos( $html, $s ) || false !== stripos( $html, rawurlencode( $s ) ) || false !== stripos( $html, str_replace( '/', '\/', $s ) ) ) {
			$found[] = $s;
		}
	}
	return implode( ', ', $found );
};

echo "\nThe event editor (block editor sidebar, over HTTP)\n";
$priv = $rig_event( 'Rig ML Private Talk', array() );
$res  = wp_remote_get( admin_url( 'post.php?post=' . $priv . '&action=edit' ), array( 'cookies' => $rig_cookies(), 'timeout' => 60 ) );
$edit = (string) wp_remote_retrieve_body( $res );
$ok( 'edit screen answers 200', 200 === (int) wp_remote_retrieve_response_code( $res ) );
$areas = array();
if ( preg_match( '/setAvailableMetaBoxesPerLocation\(\s*(\{.*?\})\s*\);/s', $edit, $m ) ) {
	foreach ( (array) json_decode( $m[1], true ) as $area => $boxes ) {
		foreach ( (array) $boxes as $box ) {
			$areas[ $box['id'] ] = $area;
		}
	}
}
$ok( 'Event Details sits in the sidebar', 'side' === ( $areas['npmp-event-details'] ?? '' ), wp_json_encode( $areas ) );
$ok( 'Meeting ID field', (bool) preg_match( '/<label[^>]*for="npmp_event_meeting_id"[^>]*>Meeting ID<\/label>/', $edit ) && false !== strpos( $edit, 'name="npmp_event_meeting_id"' ) );
$ok( 'Passcode field', (bool) preg_match( '/<label[^>]*for="npmp_event_passcode"[^>]*>Passcode<\/label>/', $edit ) && false !== strpos( $edit, 'name="npmp_event_passcode"' ) );
$ok( 'the checkbox and its label', (bool) preg_match( '/<input type="checkbox" id="npmp_event_link_email_only" name="npmp_event_link_email_only" value="1"> Show the meeting link only in emails/', $edit ) );
$ok( 'the help line under it', false !== strpos( $edit, 'Visitors to your site won&#039;t see the link, meeting ID, or passcode. Your members still get all three in event emails.' ) );
$ok( 'the fields come right after Event Link', (bool) preg_match( '/name="npmp_event_url".*?name="npmp_event_meeting_id".*?name="npmp_event_passcode".*?name="npmp_event_link_email_only"/s', $edit ) );

$rig_field = function ( $html, $name ) {
	return preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $mm ) ? html_entity_decode( $mm[1] ) : '';
};
// The block editor posts its boxes to this URL, with its own nonce.
$rig_loader = function ( $html ) use ( &$priv ) {
	$nonce = preg_match( '/meta-box-loader-nonce=([0-9a-f]+)/', $html, $lm ) ? $lm[1] : '';
	return admin_url( 'post.php?post=' . $priv . '&action=edit&meta-box-loader=1&meta-box-loader-nonce=' . $nonce );
};
$base_form = preg_match( '/<form[^>]*class="metabox-base-form".*?<\/form>/s', $edit, $bm ) ? $bm[0] : '';
$rig_post  = array(
	'action'                   => 'editpost',
	'post_ID'                  => $priv,
	'post_type'                => 'npmp_event',
	'_wpnonce'                 => $rig_field( $base_form, '_wpnonce' ),
	'npmp_event_details_nonce' => $rig_field( $edit, 'npmp_event_details_nonce' ),
	'npmp_event_start_date'    => $rig_field( $edit, 'npmp_event_start_date' ),
	'npmp_event_start_time'    => $rig_field( $edit, 'npmp_event_start_time' ),
	'npmp_event_end_date'      => $rig_field( $edit, 'npmp_event_end_date' ),
	'npmp_event_end_time'      => $rig_field( $edit, 'npmp_event_end_time' ),
	'npmp_event_location'      => $rig_field( $edit, 'npmp_event_location' ),
	'npmp_event_url'           => $priv_url,
	'npmp_event_meeting_id'    => $priv_id . "\n<b>x</b>",
	'npmp_event_passcode'      => $priv_pass,
	'npmp_event_link_email_only' => '1',
);
$ok( 'found both nonces on the page', '' !== $rig_post['_wpnonce'] && '' !== $rig_post['npmp_event_details_nonce'] );
$res = wp_remote_post( $rig_loader( $edit ), array( 'cookies' => $rig_cookies(), 'body' => $rig_post, 'timeout' => 60, 'redirection' => 0 ) );
$ok( 'saving the boxes answers like the editor expects (302 or 200)', in_array( (int) wp_remote_retrieve_response_code( $res ), array( 200, 302 ), true ), wp_remote_retrieve_response_code( $res ) . ' ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_remote_retrieve_body( $res ) ) ), 0, 300 ) );
wp_cache_flush();
$ok( 'link saved', $priv_url === get_post_meta( $priv, '_npmp_event_url', true ) );
$ok( 'meeting ID saved as clean text', $priv_id . ' x' === get_post_meta( $priv, '_npmp_event_meeting_id', true ), (string) get_post_meta( $priv, '_npmp_event_meeting_id', true ) );
update_post_meta( $priv, '_npmp_event_meeting_id', $priv_id );
$ok( 'passcode saved', $priv_pass === get_post_meta( $priv, '_npmp_event_passcode', true ) );
$ok( 'email-only saved', '1' === get_post_meta( $priv, '_npmp_event_link_email_only', true ) );
$ok( 'start date kept', $rig_local( $rig_start ) === get_post_meta( $priv, '_npmp_event_start', true ) );
$edit = (string) wp_remote_retrieve_body( wp_remote_get( admin_url( 'post.php?post=' . $priv . '&action=edit' ), array( 'cookies' => $rig_cookies(), 'timeout' => 60 ) ) );
$ok( 'the box shows the saved values', $priv_id === $rig_field( $edit, 'npmp_event_meeting_id' ) && $priv_pass === $rig_field( $edit, 'npmp_event_passcode' ) && $priv_url === $rig_field( $edit, 'npmp_event_url' ) );
$ok( 'the box shows the tick', false !== strpos( $edit, 'name="npmp_event_link_email_only" value="1" checked=\'checked\'' ) );
// Unticking and clearing go through the same save.
$untick = $rig_post;
unset( $untick['npmp_event_link_email_only'] );
$untick['npmp_event_meeting_id']    = '';
$untick['_wpnonce']                 = $rig_field( preg_match( '/<form[^>]*class="metabox-base-form".*?<\/form>/s', $edit, $bm ) ? $bm[0] : '', '_wpnonce' );
$untick['npmp_event_details_nonce'] = $rig_field( $edit, 'npmp_event_details_nonce' );
wp_remote_post( $rig_loader( $edit ), array( 'cookies' => $rig_cookies(), 'body' => $untick, 'timeout' => 60, 'redirection' => 0 ) );
wp_cache_flush();
$ok( 'unticking deletes the setting', '' === get_post_meta( $priv, '_npmp_event_link_email_only', true ) && ! metadata_exists( 'post', $priv, '_npmp_event_link_email_only' ) );
$ok( 'an empty meeting ID deletes it', ! metadata_exists( 'post', $priv, '_npmp_event_meeting_id' ) );
update_post_meta( $priv, '_npmp_event_meeting_id', $priv_id );
update_post_meta( $priv, '_npmp_event_link_email_only', '1' );

$open = $rig_event(
	'Rig ML Open Talk',
	array(
		'_npmp_event_url'        => $open_url,
		'_npmp_event_meeting_id' => $open_id,
		'_npmp_event_passcode'   => $open_pass,
	)
);
npmp_calendar_flush_ical_cache( $priv );

echo "\nThe event's own page\n";
list( $code, $page ) = $rig_get( get_permalink( $priv ) );
$ok( 'private event page answers 200 and names the event', 200 === $code && false !== strpos( $page, 'Rig ML Private Talk' ) );
$ok( 'no link, ID, or passcode on it', '' === $leaks( $page ), $leaks( $page ) );
$ok( 'no empty Meeting ID or Passcode label either', false === strpos( $page, 'Meeting ID:' ) && false === strpos( $page, 'Passcode:' ) );
$ok( 'date and place still show', false !== strpos( $page, 'Online' ) );
list( $code, $page ) = $rig_get( get_permalink( $open ) );
$ok( 'an event with the box unticked shows its link', false !== strpos( $page, $open_url ) );
$ok( 'and its meeting ID and passcode, labeled', (bool) preg_match( '/Meeting ID:\s*<\/strong>\s*' . preg_quote( $open_id, '/' ) . '/', $page ) && (bool) preg_match( '/Passcode:\s*<\/strong>\s*' . preg_quote( $open_pass, '/' ) . '/', $page ), substr( strip_tags( $page ), 0, 0 ) );
$ok( 'the visitor-facing details call skips private values', '' === npmp_get_event_details( $priv )['url'] && '' === npmp_get_event_details( $priv )['passcode'] && $priv_pass === npmp_get_event_details( $priv, true )['passcode'] );

echo "\nLists, feeds, and APIs (nobody logged in)\n";
$list_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Rig ML Lists',
		'post_content' => "[npmp_events past=\"all\" limit=\"50\"]\n\n<!-- wp:nonprofit-manager/events {\"past\":true} /-->",
	)
);
$cal_page  = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Rig ML Calendar',
		'post_content' => '[npmp_calendar]',
	)
);
$rig_made[] = $list_page;
$rig_made[] = $cal_page;
$day        = wp_date( 'Y-m-d', $rig_start );
$surfaces   = array(
	'[npmp_events] and the Events block' => get_permalink( $list_page ),
	'calendar month view'               => add_query_arg( array( 'npmp_view' => 'month', 'npmp_date' => $day ), get_permalink( $cal_page ) ),
	'calendar week view'                => add_query_arg( array( 'npmp_view' => 'week', 'npmp_date' => $day ), get_permalink( $cal_page ) ),
	'calendar list view'                => add_query_arg( array( 'npmp_view' => 'list', 'npmp_date' => $day ), get_permalink( $cal_page ) ),
	'iCal feed'                         => home_url( '/?npmp-ical=1' ),
	'/events/ archive'                  => get_post_type_archive_link( 'npmp_event' ),
	'/events/ RSS feed'                 => get_post_type_archive_feed_link( 'npmp_event' ),
	'site search'                       => home_url( '/?s=Rig+ML' ),
	'REST event list'                   => rest_url( 'wp/v2/npmp_event?per_page=100' ),
	'REST event'                        => rest_url( 'wp/v2/npmp_event/' . $priv ),
	'REST search'                       => rest_url( 'wp/v2/search?search=Rig%20ML&subtype=any' ),
	'oEmbed JSON'                       => rest_url( 'oembed/1.0/embed?url=' . rawurlencode( get_permalink( $priv ) ) ),
	'embed page'                        => trailingslashit( get_permalink( $priv ) ) . 'embed/',
);
foreach ( $surfaces as $label => $url ) {
	list( $code, $body ) = $rig_get( $url );
	$names = false !== strpos( $body, 'Rig ML Private Talk' ) || false !== strpos( $body, 'Rig ML Private Talk' ) || false !== strpos( $body, 'Rig ML excerpt for Rig ML Private Talk' );
	$ok( $label . ': lists the event, no link, ID, or passcode', 200 === $code && $names && '' === $leaks( $body ), $code . ( $names ? '' : ', event missing' ) . ( $leaks( $body ) ? ', found ' . $leaks( $body ) : '' ) );
}
list( $code, $body ) = $rig_get( rest_url( 'wp/v2/npmp_event/' . $priv . '?context=edit' ) );
$ok( 'REST edit context refuses a visitor', 401 === $code, (string) $code );

echo "\nThe Tomorrow body\n";
$body_ids = (array) get_option( 'npmp_scheduled_email_bodies' );
$tomorrow = get_post( (int) ( $body_ids['tomorrow'] ?? 0 ) );
$ok( 'the seeded Tomorrow body now has the meeting lines', $tomorrow && false !== strpos( $tomorrow->post_content, '<strong>Meeting ID:</strong> [meeting_id]<br><strong>Passcode:</strong> [passcode]' ) );
$ok( 'the upgrade ran once', '1' === (string) get_option( 'npmp_scheduled_email_bodies_meeting' ) );
if ( $tomorrow ) {
	$custom = '<!-- wp:paragraph --><p>Our own words. [join_link]</p><!-- /wp:paragraph -->';
	wp_update_post( wp_slash( array( 'ID' => $tomorrow->ID, 'post_content' => npmp_scheduled_emails_builtin_bodies( false )['tomorrow']['content'] ) ) );
	delete_option( 'npmp_scheduled_email_bodies_meeting' );
	wp_update_post( wp_slash( array( 'ID' => $tomorrow->ID, 'post_content' => $custom ) ) );
	npmp_scheduled_emails_upgrade_bodies();
	$ok( 'a body someone changed stays as they left it', $custom === get_post( $tomorrow->ID )->post_content );
	wp_update_post( wp_slash( array( 'ID' => $tomorrow->ID, 'post_content' => npmp_scheduled_emails_builtin_bodies()['tomorrow']['content'] ) ) );
}

echo "\nThe emails\n";
foreach ( array( $priv, $open ) as $rig_id ) {
	npmp_scheduled_emails_sync_event( $rig_id );
}
$mails = $rig_send( array( $priv, $open ) );
$pm    = $rig_mail_for( $mails, $rig_member, 'Tomorrow: Rig ML Private Talk' );
$ok( 'the private event\'s Tomorrow email went out', null !== $pm, count( $mails ) . ' mails' );
$pmsg = $pm ? (string) $pm['message'] : '';
if ( '' !== $rig_out ) {
	file_put_contents( $rig_out . '/private-email.html', ( $pm ? $pm['subject'] : '' ) . "\n\n" . $pmsg );
}
$ok( 'with the link', false !== strpos( $pmsg, $priv_url ) );
$ok( 'with the meeting ID, labeled', (bool) preg_match( '/Meeting ID:<\/strong> ' . preg_quote( $priv_id, '/' ) . '/', $pmsg ) );
$ok( 'with the passcode, labeled', (bool) preg_match( '/Passcode:<\/strong> ' . preg_quote( $priv_pass, '/' ) . '/', $pmsg ) );
$ok( 'no tag left', ! preg_match( '/\[(meeting_id|passcode|join_link)\]/', $pmsg . ( $pm ? $pm['subject'] : '' ) ) );
$om = $rig_mail_for( $mails, $rig_member, 'Tomorrow: Rig ML Open Talk' );
$ok( 'the open event\'s email has its three values too', $om && false !== strpos( $om['message'], $open_url ) && false !== strpos( $om['message'], $open_id ) && false !== strpos( $om['message'], $open_pass ) );

$nl_of = function ( $event_id ) {
	$found = get_posts( array( 'post_type' => 'npmp_newsletter', 'numberposts' => 1, 'post_status' => 'any', 'meta_key' => '_npmp_newsletter_scheduled_event', 'meta_value' => $event_id, 'orderby' => 'ID', 'order' => 'DESC' ) );
	return $found ? (int) $found[0]->ID : 0;
};
$pnl = $nl_of( $priv );
$onl = $nl_of( $open );
foreach ( array( $pnl, $onl ) as $rig_id ) {
	$rig_made[] = $rig_id;
}
$ok( 'the private event\'s newsletter carries the private mark', $pnl && '1' === (string) get_post_meta( $pnl, '_npmp_newsletter_meeting_private', true ) );
$ok( 'the open event\'s newsletter doesn\'t', $onl && ! metadata_exists( 'post', $onl, '_npmp_newsletter_meeting_private' ) );

echo "\nThe newsletter archive\n";
foreach ( array( $pnl, $onl ) as $rig_id ) {
	update_post_meta( $rig_id, '_npmp_newsletter_public', '1' );
}
$nl_page    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig ML Newsletters', 'post_content' => '[npmp_newsletters event_emails="yes" limit="50"]' ) );
$rig_made[] = $nl_page;
$ok( 'a ticked private event email still isn\'t public', ! npmp_newsletter_archive_is_public( get_post( $pnl ) ) );
$ok( 'a ticked open event email is', npmp_newsletter_archive_is_public( get_post( $onl ) ) );
list( $code, $body ) = $rig_get( npmp_newsletter_archive_url( $pnl ) );
$ok( 'its archive page answers 404 with nothing in it', 404 === $code && '' === $leaks( $body ), $code . ' ' . $leaks( $body ) );
list( $code, $body ) = $rig_get( npmp_newsletter_archive_url( $onl ) );
$ok( 'the open one\'s page answers 200 with its link', 200 === $code && false !== strpos( $body, $open_url ), (string) $code );
list( $code, $body ) = $rig_get( get_permalink( $nl_page ) );
$ok( 'the list shows the open one and leaves out the private one', 200 === $code && false !== strpos( $body, 'Rig ML Open Talk' ) && false === strpos( $body, 'Rig ML Private Talk' ) && '' === $leaks( $body ) );
// The event changes its mind after the email went out.
delete_post_meta( $pnl, '_npmp_newsletter_meeting_private' );
$ok( 'without the mark, the live event still keeps it private', ! npmp_newsletter_archive_is_public( get_post( $pnl ) ) );
update_post_meta( $pnl, '_npmp_newsletter_meeting_private', '1' );

foreach ( $rig_made as $rig_id ) {
	if ( $rig_id ) {
		wp_delete_post( $rig_id, true );
	}
}
// phpcs:ignore WordPress.DB -- Throwaway rig.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$rig_table} WHERE target_type = 'event' AND target_id IN (%d, %d)", $priv, $open ) );

echo "\n$passes passed, $fails failed\n";
exit( $fails ? 1 : 0 );
