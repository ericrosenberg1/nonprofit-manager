<?php
/**
 * Real-WordPress check that queued email leaves the queue: the per-minute
 * drain is on the schedule however newsletters were turned on, a scheduled
 * email reads "Sending" until the queue empties, the drain empties it, and
 * Site Health flags a queue that stops moving.
 *
 * The bug behind it: `update_option()` on an option with no row hands off to
 * `add_option()`, so the first save of the Features screen never fired
 * `update_option_npmp_enabled_features`, and `npmp_process_queued_newsletters`
 * was never scheduled. Every newsletter queued, none sent, and the Scheduled
 * Emails screen read "Sent to 2 members" with an empty mail log (plord12 on
 * wordpress.org, 2026-11-19).
 *
 * Run inside a throwaway WordPress with members, newsletters and the calendar
 * on (CLAUDE.md, "Testing beyond unit tests"):
 *   wp eval-file tests/rig/newsletter-queue-cron.php
 * To watch the messages as well, point Email Settings at
 * tests/rig/smtp-sink.py first. This deletes every event, newsletter, contact
 * and queue row before it starts.
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
$saved_features = get_option( 'npmp_enabled_features' );
$saved_defaults = get_option( 'npmp_scheduled_email_defaults' );
wp_cache_flush();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

echo "The drain is armed on every request, not only by a settings write\n";
$ok( 'npmp_schedule_newsletter_cron runs on init', false !== has_action( 'init', 'npmp_schedule_newsletter_cron' ) );

// The hole, as a new install hits it: no option row, so update_option() is an
// add_option() and the update_option_ hook never fires.
wp_clear_scheduled_hook( 'npmp_process_queued_newsletters' );
delete_option( 'npmp_enabled_features' );
update_option( 'npmp_enabled_features', array( 'members' => true, 'newsletters' => true, 'donations' => true, 'calendar' => true, 'social' => false ) );
$ok( 'turning newsletters on with no option row schedules nothing by itself', false === wp_next_scheduled( 'npmp_process_queued_newsletters' ) );
npmp_schedule_newsletter_cron();
$ok( 'the init arming puts the drain back', false !== wp_next_scheduled( 'npmp_process_queued_newsletters' ) );
$ok( 'and it runs every minute', 'every_minute' === wp_get_schedule( 'npmp_process_queued_newsletters' ) );
npmp_schedule_newsletter_cron();
$ok( 'a second call adds no duplicate', 1 === count( array_filter( (array) _get_cron_array(), function ( $hooks ) {
	return isset( $hooks['npmp_process_queued_newsletters'] );
} ) ) );

echo "A scheduled email says Sending until the queue empties\n";
update_option( 'npmp_scheduled_email_defaults', npmp_scheduled_email_sanitize_defaults( array( 'enabled' => 1, 'minutes_before' => 1440 ) ) );
$members = NPMP_Member_Manager::get_instance();
foreach ( array( 'ann@example.org', 'bob@example.org' ) as $rig_email ) {
	$members->add_member( array( 'email' => $rig_email, 'name' => ucfirst( strtok( $rig_email, '@' ) ), 'status' => 'subscribed' ) );
}
$event_id = wp_insert_post(
	array(
		'post_type'   => 'npmp_event',
		'post_status' => 'publish',
		'post_title'  => 'Rig Queue Meeting',
		'meta_input'  => array(
			'_npmp_event_start'    => wp_date( 'Y-m-d H:i:00', time() + 3 * DAY_IN_SECONDS ),
			'_npmp_event_location' => 'Church Hall',
		),
	)
);
npmp_scheduled_emails_sync_upcoming_events();
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET send_at = %s WHERE target_type = 'event'", gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
do_action( 'npmp_scheduled_emails_sweep' );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_type = 'event' AND target_id = %d", $event_id ) );
$ok( 'the sweep marks the row sent and counts two readers', $row && 'sent' === $row->status && 2 === (int) $row->recipient_count );
$ok( 'both readers are waiting in the queue', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending'" ) );
$ok( 'the screen says Sending, not Sent', $row && 'Sending to 2 members' === npmp_scheduled_emails_status_label( $row ) );
$ok( 'the queue is not stalled yet', false === npmp_newsletter_queue_is_stalled( 2, 0, null ) );

echo "The drain empties it\n";
do_action( 'npmp_process_queued_newsletters' );
$ok( 'nothing is left pending', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending'" ) );
$ok( 'both rows went out', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'sent'" ) );
$ok( 'the newsletter reads sent', 'sent' === get_post_meta( (int) $row->newsletter_id, '_npmp_newsletter_status', true ) );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $row->id ) );
$ok( 'and now the screen says Sent', 'Sent to 2 members' === npmp_scheduled_emails_status_label( $row ) );

echo "Site Health flags a queue that stopped moving\n";
$state = npmp_newsletter_queue_state();
$ok( 'a drained queue reads good', 'good' === npmp_newsletter_queue_health_test()['status'] );
$ok( 'and counts nobody waiting', 0 === $state['waiting'] );
$wpdb->query( "DELETE FROM {$queue}" );
$stale = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 3 * HOUR_IN_SECONDS );
$wpdb->insert( $queue, array( 'newsletter_id' => (int) $row->newsletter_id, 'user_id' => 0, 'email' => 'ann@example.org', 'status' => 'pending', 'queued_at' => $stale ), array( '%d', '%d', '%s', '%s', '%s' ) );
$state = npmp_newsletter_queue_state();
$ok( 'three hours waiting with nothing sent reads as stalled', npmp_newsletter_queue_is_stalled( $state['waiting'], $state['oldest'], $state['since_send'] ) );
$ok( 'the test goes critical', 'critical' === npmp_newsletter_queue_health_test()['status'] );
$ok( 'and the wait is in the words', false !== strpos( npmp_newsletter_queue_health_test()['description'], '3 hours' ) );

// Put the site back.
$wpdb->query( "DELETE FROM {$queue}" );
$wpdb->query( "DELETE FROM {$table}" );
foreach ( get_posts( array( 'post_type' => array( 'npmp_event', 'npmp_newsletter', 'npmp_contact' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $rig_id ) {
	wp_delete_post( $rig_id, true );
}
if ( false === $saved_features ) {
	delete_option( 'npmp_enabled_features' );
} else {
	update_option( 'npmp_enabled_features', $saved_features );
}
if ( false === $saved_defaults ) {
	delete_option( 'npmp_scheduled_email_defaults' );
} else {
	update_option( 'npmp_scheduled_email_defaults', $saved_defaults );
}
// phpcs:enable WordPress.DB

printf( "\n%s\n", $fails ? "{$fails} failed" : 'all passed' );
exit( $fails ? 1 : 0 );
