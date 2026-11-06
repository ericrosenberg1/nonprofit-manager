<?php
/**
 * Real-WordPress check of scheduled emails: off by default, the row an event
 * save makes, moving an event, the sweep sending once to the right members
 * with placeholders filled, the late rule, the last-hour guard, trash and
 * untrash, admin skips, "Send an update", "Send later", group rows waiting
 * for Pro, and the late-send warning.
 *
 * Run inside a throwaway WordPress with members, newsletters and the
 * calendar on (CLAUDE.md, "Testing beyond unit tests"):
 *   wp eval-file tests/rig/scheduled-emails.php
 * It deletes every event, newsletter, contact and scheduled email first.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_scheduled_emails_sync_event' ) || ! post_type_exists( 'npmp_event' ) || ! class_exists( 'NPMP_Member_Manager' ) ) {
	echo "  FAIL  members, newsletters and calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}

global $wpdb;
$table = npmp_scheduled_emails_table();
$queue = $wpdb->prefix . 'npmp_newsletter_queue';

// phpcs:disable WordPress.DB -- Throwaway rig.
$wpdb->query( "DELETE FROM {$table}" );
$wpdb->query( "DELETE FROM {$queue}" );
foreach ( get_posts( array( 'post_type' => array( 'npmp_event', 'npmp_newsletter', 'npmp_contact' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $rig_id ) {
	wp_delete_post( $rig_id, true );
}
$wpdb->query( "DELETE FROM {$table}" );
delete_option( 'npmp_scheduled_email_defaults' );
delete_option( 'npmp_scheduled_email_late' );
wp_cache_flush();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

$members = NPMP_Member_Manager::get_instance();
foreach ( array( 'ann@example.org', 'bob@example.org', 'cat@example.org' ) as $rig_email ) {
	$members->add_member( array( 'email' => $rig_email, 'name' => ucfirst( strtok( $rig_email, '@' ) ), 'status' => 'subscribed' ) );
}
$members->add_member( array( 'email' => 'dan@example.org', 'name' => 'Dan', 'status' => 'unsubscribed' ) );

$now   = time();
$local = function ( $ts ) {
	return wp_date( 'Y-m-d H:i:00', $ts );
};
$make  = function ( $title, $start_ts, $status = 'publish' ) use ( $local ) {
	return wp_insert_post(
		array(
			'post_type'   => 'npmp_event',
			'post_status' => $status,
			'post_title'  => $title,
			'meta_input'  => array(
				'_npmp_event_start'    => $local( $start_ts ),
				'_npmp_event_location' => 'Church Hall',
				'_npmp_event_url'      => 'https://meet.example/abc',
			),
		)
	);
};
$row   = function ( $event_id, $rule = 'default', $type = 'event' ) use ( $wpdb, $table ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_type = %s AND target_id = %d AND rule_key = %s", $type, $event_id, $rule ) );
};
$set_defaults = function ( $values ) {
	update_option( 'npmp_scheduled_email_defaults', npmp_scheduled_email_sanitize_defaults( array_merge( npmp_scheduled_email_defaults(), $values ) ) );
};
$scheduled_newsletters = function ( $event_id ) {
	return get_posts(
		array(
			'post_type'   => 'npmp_newsletter',
			'numberposts' => -1,
			'post_status' => 'any',
			'meta_key'    => '_npmp_newsletter_scheduled_event',
			'meta_value'  => $event_id,
		)
	);
};

echo "Off by default\n";
$a = $make( 'Rig Meeting', $now + 8 * DAY_IN_SECONDS );
$ok( 'a new site has event emails off', 0 === npmp_scheduled_email_defaults()['enabled'] );
$ok( 'saving an event while off makes no row', null === $row( $a ) );

echo "Turning defaults on makes the row\n";
$set_defaults( array( 'enabled' => 1 ) );
npmp_scheduled_emails_sync_upcoming_events();
$r = $row( $a );
$expected = npmp_scheduled_emails_send_at_utc( get_post_meta( $a, '_npmp_event_start', true ), 1440, wp_timezone() );
$ok( 'row is pending', $r && 'pending' === $r->status );
$ok( 'send time is a day before, in UTC', $r && $expected === $r->send_at );
$ok( 'audience is everyone', $r && 'all' === $r->audience_type && 0 === (int) $r->audience_id );
$first_id = $r ? (int) $r->id : 0;

echo "Moving the event moves the row\n";
update_post_meta( $a, '_npmp_event_start', $local( $now + 10 * DAY_IN_SECONDS ) );
wp_update_post( array( 'ID' => $a ) );
$r = $row( $a );
$ok( 'same row', $r && $first_id === (int) $r->id );
$ok( 'send time moved two days', $r && 2 * DAY_IN_SECONDS === npmp_scheduled_emails_utc_to_ts( $r->send_at ) - npmp_scheduled_emails_utc_to_ts( $expected ) );
$ok( 'still pending', $r && 'pending' === $r->status );

echo "The sweep sends once\n";
$wpdb->update( $table, array( 'send_at' => gmdate( 'Y-m-d H:i:s', $now - 300 ) ), array( 'id' => $first_id ) );
do_action( 'npmp_scheduled_emails_sweep' );
do_action( 'npmp_scheduled_emails_sweep' );
$r    = $row( $a );
$made = $scheduled_newsletters( $a );
$ok( 'row is sent', $r && 'sent' === $r->status );
$ok( 'exactly one newsletter made', 1 === count( $made ) );
$nl = $made ? $made[0] : null;
$ok( 'row points at it', $r && $nl && (int) $r->newsletter_id === $nl->ID );
$emails = $nl ? $wpdb->get_col( $wpdb->prepare( "SELECT email FROM {$queue} WHERE newsletter_id = %d ORDER BY email", $nl->ID ) ) : array();
$ok( 'queued to the three subscribed members only', array( 'ann@example.org', 'bob@example.org', 'cat@example.org' ) === $emails );
$ok( 'recipient count is 3', $r && 3 === (int) $r->recipient_count );
$ok( 'subject is the Tomorrow body subject, filled', $nl && 'Tomorrow: Rig Meeting' === $nl->post_title );
$ok( 'body has the title, place and link', $nl && false !== strpos( $nl->post_content, 'Rig Meeting' ) && false !== strpos( $nl->post_content, 'Church Hall' ) && false !== strpos( $nl->post_content, 'https://meet.example/abc' ) );
$ok( 'no placeholder left in body', $nl && ! preg_match( '/\[(event_|join_link|ical_link)/', $nl->post_content ) );
$ok( 'audience meta is everyone', $nl && array( '__all__' ) === get_post_meta( $nl->ID, '_npmp_newsletter_levels', true ) );
$ok( 'archive and reports label it Scheduled', $nl && false !== strpos( npmp_newsletter_scheduled_badge( $nl->ID ), 'Scheduled' ) );
$html = $nl ? NPMP_Newsletter_Manager::get_newsletter_content( $nl->ID, true ) : '';
$ok( 'renders through the newsletter pipeline', false !== strpos( $html, 'Rig Meeting' ) );
wp_update_post( array( 'ID' => $a ) );
$ok( 'saving again never resends', 'sent' === $row( $a )->status && 1 === count( $scheduled_newsletters( $a ) ) );

echo "Trash, untrash, publish\n";
$b = $make( 'Rig Social', $now + 8 * DAY_IN_SECONDS );
$ok( 'second event pending', 'pending' === $row( $b )->status );
wp_trash_post( $b );
$ok( 'trash cancels', 'cancelled' === $row( $b )->status );
wp_untrash_post( $b );
$ok( 'untrash (back to draft) stays cancelled', 'cancelled' === $row( $b )->status );
wp_publish_post( $b );
$ok( 'publishing again puts it back', 'pending' === $row( $b )->status );
wp_update_post( array( 'ID' => $b, 'post_status' => 'draft' ) );
$ok( 'unpublish cancels', 'cancelled' === $row( $b )->status );
wp_publish_post( $b );
update_post_meta( $b, '_npmp_event_status', 'cancelled' );
npmp_scheduled_emails_flush_dirty();
$ok( 'a cancelled event cancels its email', 'cancelled' === $row( $b )->status );
delete_post_meta( $b, '_npmp_event_status' );
npmp_scheduled_emails_flush_dirty();

echo "Admin skip\n";
$rb = $row( $b );
npmp_scheduled_emails_event_op( $b, $rb, npmp_scheduled_emails_row_key( 'default', 'all', 0 ), 'skip' );
$ok( 'skip marks it skipped', 'skipped' === $row( $b )->status );
wp_update_post( array( 'ID' => $b ) );
$ok( 'a save keeps the skip', 'skipped' === $row( $b )->status );
npmp_scheduled_emails_event_op( $b, $row( $b ), npmp_scheduled_emails_row_key( 'default', 'all', 0 ), 'unskip' );
$ok( 'unskip puts it back', 'pending' === $row( $b )->status );

echo "Event box save\n";
$_POST = array(
	'npmp_sched_email_box_nonce' => wp_create_nonce( 'npmp_sched_email_box_' . $b ),
	'npmp_sched_email'           => array( 'mode' => 'on', 'timing' => 'custom', 'custom_hours' => '5', 'body_id' => '', 'subject' => 'Soon: [event_title]' ),
);
wp_update_post( array( 'ID' => $b ) );
$rb = $row( $b );
$ok( 'custom 5 hours saves and reschedules', $rb && npmp_scheduled_emails_send_at_utc( get_post_meta( $b, '_npmp_event_start', true ), 300, wp_timezone() ) === $rb->send_at );
$ok( 'own subject saved on the row', $rb && 'Soon: [event_title]' === $rb->subject );
$_POST['npmp_sched_email']['mode'] = 'off';
wp_update_post( array( 'ID' => $b ) );
$ok( 'turning it off for the event cancels', 'cancelled' === $row( $b )->status );
$_POST = array();

echo "Late rule\n";
$set_defaults( array( 'minutes_before' => 10080, 'late_mode' => 'send_if' ) );
$c = $make( 'Late but far', $now + 3 * DAY_IN_SECONDS );
$rc = $row( $c );
$ok( 'send_if, 3 days out: goes right away', $rc && 'pending' === $rc->status && npmp_scheduled_emails_utc_to_ts( $rc->send_at ) <= time() && '' !== $rc->note );
$d = $make( 'Late and close', $now + DAY_IN_SECONDS );
$ok( 'send_if, 1 day out: skipped with a note', 'skipped' === $row( $d )->status && '' !== $row( $d )->note );
$set_defaults( array( 'late_mode' => 'skip' ) );
$e = $make( 'Late, skip mode', $now + 5 * DAY_IN_SECONDS );
$ok( 'skip mode, 5 days out: skipped', 'skipped' === $row( $e )->status );
$set_defaults( array( 'late_mode' => 'send' ) );
$f = $make( 'Late, send mode', $now + DAY_IN_SECONDS );
$ok( 'send mode, 1 day out: goes right away', 'pending' === $row( $f )->status );

echo "Never in the last hour\n";
$g = $make( 'Starts soon', $now + 30 * MINUTE_IN_SECONDS );
$ok( 'send mode queues it', 'pending' === $row( $g )->status );
do_action( 'npmp_scheduled_emails_sweep' );
$ok( 'the sweep skips it with a note', 'skipped' === $row( $g )->status && false !== strpos( $row( $g )->note, 'hour' ) );
$ok( 'and makes no newsletter', 0 === count( $scheduled_newsletters( $g ) ) );
$ok( 'the far late one went out', 'sent' === $row( $c )->status );
$set_defaults( array( 'minutes_before' => 1440, 'late_mode' => 'send_if' ) );

echo "Group rows wait for Pro\n";
npmp_scheduled_emails_insert_row(
	array(
		'target_type'   => 'event',
		'target_id'     => $a,
		'rule_key'      => 'week',
		'audience_type' => 'group',
		'audience_id'   => 4,
		'send_at'       => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	)
);
do_action( 'npmp_scheduled_emails_sweep' );
$rg = $row( $a, 'week' );
$ok( 'a group row stays pending on free', $rg && 'pending' === $rg->status );
$ok( 'and says why', $rg && false !== strpos( $rg->note, 'Pro' ) );
$wpdb->delete( $table, array( 'id' => $rg->id ) );

echo "Send an update\n";
$msg = npmp_scheduled_emails_event_op( $a, $row( $a ), npmp_scheduled_emails_row_key( 'default', 'all', 0 ), 'update' );
$ok( 'update accepted', ! is_wp_error( $msg ) && 'pending' === $row( $a )->status );
do_action( 'npmp_scheduled_emails_sweep' );
$made = $scheduled_newsletters( $a );
$titles = wp_list_pluck( $made, 'post_title' );
$ok( 'a second newsletter went out marked as an update', 2 === count( $made ) && in_array( 'Update: Tomorrow: Rig Meeting', $titles, true ) );
$ok( 'row is sent again', 'sent' === $row( $a )->status );

echo "Send later\n";
$n = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'November news', 'post_content' => '<p>Hello</p>' ) );
npmp_scheduled_emails_insert_row(
	array(
		'target_type' => 'list',
		'target_id'   => $n,
		'rule_key'    => 'send_later',
		'send_at'     => gmdate( 'Y-m-d H:i:s', $now - 40 * MINUTE_IN_SECONDS ),
	)
);
do_action( 'npmp_scheduled_emails_sweep' );
$rn = $row( $n, 'send_later', 'list' );
$ok( 'the newsletter went out', $rn && 'sent' === $rn->status && 3 === (int) $rn->recipient_count );
$ok( 'queued once', 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = %d", $n ) ) );
$late = get_option( 'npmp_scheduled_email_late' );
$ok( '40 minutes late is recorded', is_array( $late ) && $late['minutes'] >= 39 );
$health = npmp_scheduled_emails_health_test();
$ok( 'Site Health recommends the system cron', 'recommended' === $health['status'] && false !== strpos( $health['description'], 'DISABLE_WP_CRON' ) );
npmp_scheduled_emails_insert_row(
	array(
		'target_type' => 'list',
		'target_id'   => $n + 100000,
		'rule_key'    => 'send_later',
		'send_at'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	)
);
do_action( 'npmp_scheduled_emails_sweep' );
$ok( 'a missing newsletter is cancelled, not sent', 'cancelled' === $row( $n + 100000, 'send_later', 'list' )->status );

echo "Delete cancels\n";
$h = $make( 'Deleted soon', $now + 9 * DAY_IN_SECONDS );
$ok( 'pending before delete', 'pending' === $row( $h )->status );
wp_delete_post( $h, true );
$ok( 'deleted event cancels its row', 'cancelled' === $row( $h )->status );

echo "A save never skips an email that's already due\n";
$set_defaults( array( 'minutes_before' => 1440, 'late_mode' => 'send_if' ) );
$k = $make( 'Due, cron is behind', $now + 20 * HOUR_IN_SECONDS );
$rk = $row( $k );
$ok( 'made 20 hours out: the late rule skips it', $rk && 'skipped' === $rk->status );
$due_at = gmdate( 'Y-m-d H:i:s', $now - 120 );
$wpdb->update( $table, array( 'status' => 'pending', 'send_at' => $due_at, 'note' => '' ), array( 'id' => $rk->id ) );
wp_update_post( array( 'ID' => $k, 'post_title' => 'Due, cron is behind (fixed typo)' ) );
$rk = $row( $k );
$ok( 'a due row stays pending through a save', $rk && 'pending' === $rk->status );
$ok( 'and keeps its send time', $rk && $due_at === $rk->send_at );
update_post_meta( $k, '_npmp_event_start', $local( $now + 30 * DAY_IN_SECONDS ) );
wp_update_post( array( 'ID' => $k ) );
$rk = $row( $k );
$ok( 'moving it a month out reschedules it', $rk && 'pending' === $rk->status && npmp_scheduled_emails_utc_to_ts( $rk->send_at ) > $now + 28 * DAY_IN_SECONDS );
$wpdb->update( $table, array( 'status' => 'sent' ), array( 'id' => $rk->id ) );
update_post_meta( $k, '_npmp_event_start', $local( $now + 20 * HOUR_IN_SECONDS ) );
npmp_scheduled_emails_flush_dirty();
npmp_scheduled_emails_event_op( $k, $row( $k ), npmp_scheduled_emails_row_key( 'default', 'all', 0 ), 'update' );
wp_update_post( array( 'ID' => $k ) );
$ok( 'a requested update survives a save', 'pending' === $row( $k )->status );
wp_delete_post( $k, true );

echo "Rows that can't go yet don't hold up the rest\n";
$wpdb->query( "UPDATE {$table} SET status = 'cancelled' WHERE status = 'pending'" );
$m = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Group news', 'post_content' => '<p>Hi</p>' ) );
for ( $rig_i = 0; $rig_i < 55; $rig_i++ ) {
	npmp_scheduled_emails_insert_row(
		array(
			'target_type'   => 'list',
			'target_id'     => $m,
			'rule_key'      => 'g' . $rig_i,
			'audience_type' => 'group',
			'audience_id'   => 7,
			'send_at'       => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
		)
	);
}
$o = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Everyone news', 'post_content' => '<p>Hi</p>' ) );
npmp_scheduled_emails_insert_row(
	array(
		'target_type' => 'list',
		'target_id'   => $o,
		'rule_key'    => 'send_later',
		'send_at'     => gmdate( 'Y-m-d H:i:s', $now - 60 ),
	)
);
do_action( 'npmp_scheduled_emails_sweep' );
$ok( 'an email behind 55 waiting group rows still goes out', 'sent' === $row( $o, 'send_later', 'list' )->status );
$ok( 'the group rows still wait', 55 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE target_type = 'list' AND target_id = %d AND status = 'pending'", $m ) ) );
$wpdb->delete( $table, array( 'target_type' => 'list', 'target_id' => $m ) );

echo "A run that died mid-queue never mails anyone twice\n";
$q = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Half queued', 'post_content' => '<p>Hi</p>' ) );
update_post_meta( $q, '_npmp_newsletter_levels', array( '__all__' ) );
$wpdb->insert( $queue, array( 'newsletter_id' => $q, 'user_id' => 0, 'email' => 'ann@example.org', 'status' => 'sent', 'queued_at' => current_time( 'mysql' ) ) );
$wpdb->insert( $queue, array( 'newsletter_id' => $q, 'user_id' => 0, 'email' => 'bob@example.org', 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ) );
$stuck_id = npmp_scheduled_emails_insert_row(
	array(
		'target_type'   => 'list',
		'target_id'     => $q,
		'rule_key'      => 'send_later',
		'status'        => 'sending',
		'newsletter_id' => $q,
		'send_at'       => gmdate( 'Y-m-d H:i:s', $now - 3600 ),
	)
);
$wpdb->update( $table, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 20 * MINUTE_IN_SECONDS ) ), array( 'id' => $stuck_id ) );
do_action( 'npmp_scheduled_emails_sweep' );
$rq = npmp_scheduled_emails_get_row( $stuck_id );
$ok( 'the stuck row counts as sent', $rq && 'sent' === $rq->status && 2 === (int) $rq->recipient_count );
$ok( 'the queue keeps its two rows', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = %d", $q ) ) );
$ok( 'and Ann stays sent', 'sent' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$queue} WHERE newsletter_id = %d AND email = %s", $q, 'ann@example.org' ) ) );

echo "Deleting another post leaves the rows alone\n";
$plain   = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Plain post' ) );
$plain_r = npmp_scheduled_emails_insert_row(
	array(
		'target_type' => 'event',
		'target_id'   => $plain,
		'rule_key'    => 'stray',
		'send_at'     => gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ),
	)
);
wp_delete_post( $plain, true );
$ok( 'a deleted blog post cancels nothing', 'pending' === npmp_scheduled_emails_get_row( $plain_r )->status );
$wpdb->delete( $table, array( 'id' => $plain_r ) );

echo "Bodies stay out of the newsletter lists\n";
$seeded = get_option( 'npmp_scheduled_email_bodies' );
$ok( 'two bodies seeded', is_array( $seeded ) && 2 === count( array_filter( $seeded ) ) );
$ok( 'they are not newsletters', $seeded && 'npmp_email_body' === get_post_type( $seeded['tomorrow'] ) && 'npmp_email_body' === get_post_type( $seeded['next_week'] ) );
$ok( 'subject saved on the body', $seeded && 'Next week: [event_title]' === get_post_meta( $seeded['next_week'], '_npmp_body_subject', true ) );
$titles = wp_list_pluck( get_posts( array( 'post_type' => 'npmp_newsletter', 'numberposts' => -1, 'post_status' => 'any' ) ), 'post_title' );
$ok( 'no body appears among newsletters', ! in_array( 'Tomorrow', $titles, true ) && ! in_array( 'Coming up next week', $titles, true ) );

echo "Screens render\n";
ob_start();
npmp_render_scheduled_emails_page();
$page = ob_get_clean();
$ok( 'Scheduled Emails screen lists rows', false !== strpos( $page, 'Rig Meeting' ) && false !== strpos( $page, 'November news' ) );
$ok( 'free screen shows the Pro teaser', npmp_is_pro() || false !== strpos( $page, 'utm_campaign=scheduled_emails' ) );
ob_start();
npmp_scheduled_emails_render_event_box( get_post( $a ) );
$box = ob_get_clean();
$ok( 'event box shows status and Send an update', false !== strpos( $box, 'Sent to 3 members' ) && false !== strpos( $box, 'Send an update' ) );
ob_start();
npmp_scheduled_emails_render_send_later( get_post( $n ) );
$later = ob_get_clean();
$ok( 'newsletter box has Send later', false !== strpos( $later, 'datetime-local' ) );
// phpcs:enable WordPress.DB

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'PASS', $fails );
exit( $fails ? 1 : 0 );
