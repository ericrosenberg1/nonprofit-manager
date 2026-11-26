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
 * It also covers the queue's retries and its table upgrade (the release after
 * 2026.11.26). A send that fails waits and goes again, and only the last try
 * marks it failed. A 2026.11.26 table with live rows gets attempts, retry_at,
 * and the status_queued index once, from the drain or an admin screen and
 * never a visitor's page, even with two requests at it, and keeps every row
 * as it was. A slow batch stops before its run lock goes stale and
 * keeps the lock fresh, so a second run can't mail the same readers. A reader
 * erased or unsubscribed, or a row deleted, after the drain read its batch
 * gets no mail, and the trackers write no row for an erased contact.
 *
 * Run inside a throwaway WordPress with members, newsletters and the calendar
 * on (CLAUDE.md, "Testing beyond unit tests"), with tests/rig/smtp-sink.py
 * listening and refusing mail while the file NPMP_RIG_SINK_FAIL exists:
 *   python3 tests/rig/smtp-sink.py 10379 /tmp/npmp-queue-sink.jsonl --fail-while /tmp/npmp-queue-sink.fail &
 *   NPMP_RIG_SINK=/tmp/npmp-queue-sink.jsonl NPMP_RIG_SINK_PORT=10379 \
 *   NPMP_RIG_SINK_FAIL=/tmp/npmp-queue-sink.fail wp eval-file tests/rig/newsletter-queue-cron.php
 * It points Email Settings at the sink and puts them back at the end. It
 * deletes every event, newsletter, contact and queue row before it starts,
 * and it drops and remakes the queue table.
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

$sink_file = (string) getenv( 'NPMP_RIG_SINK' );
$sink_port = (int) getenv( 'NPMP_RIG_SINK_PORT' );
$sink_fail = (string) getenv( 'NPMP_RIG_SINK_FAIL' );
if ( '' === $sink_file || ! $sink_port || '' === $sink_fail ) {
	echo "  FAIL  start tests/rig/smtp-sink.py with --fail-while, then set NPMP_RIG_SINK, NPMP_RIG_SINK_PORT and NPMP_RIG_SINK_FAIL\n";
	exit( 1 );
}

global $wpdb;
$table = npmp_scheduled_emails_table();
$queue = $wpdb->prefix . 'npmp_newsletter_queue';

$saved_mail = get_option( 'npmp_email_settings' );
update_option(
	'npmp_email_settings',
	array(
		'provider'        => 'smtp',
		'from_email'      => 'hello@npmp-rig.test',
		'from_name'       => 'Rig Council',
		'force_from'      => 0,
		'set_return_path' => 0,
		'smtp'            => array(
			'host'       => '127.0.0.1',
			'port'       => $sink_port,
			'encryption' => 'none',
			'auth'       => 1,
			'username'   => 'rig-user',
			'password'   => 'rig-pass',
			'auto_tls'   => 0,
		),
		'aws'             => array( 'region' => 'us-west-2', 'port' => 587 ),
	)
);
if ( ! npmp_email_smtp_is_configured( npmp_email_get_settings() ) ) {
	echo "  FAIL  Email Settings didn't take the sink\n";
	exit( 1 );
}
// Never let a message fall back to this machine's own mail program.
add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		if ( 'smtp' !== $mailer->Mailer || '127.0.0.1' !== $mailer->Host ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's names.
			throw new PHPMailer\PHPMailer\Exception( 'rig: mail is not going to the sink' );
		}
	},
	PHP_INT_MAX
);
@unlink( $sink_fail ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Start with the sink taking mail.
@unlink( $sink_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Count only this run's messages.

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

// ---------------------------------------------------------------------------
// Helpers for the retry and upgrade checks.

$sink_sends = static function ( $email = null ) use ( $sink_file ) {
	$count = 0;
	clearstatcache();
	foreach ( file_exists( $sink_file ) ? file( $sink_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array() as $line ) {
		$message = json_decode( $line, true );
		if ( null === $email ) {
			$count++;
		} elseif ( in_array( $email, (array) ( $message['rcpt_to'] ?? array() ), true ) ) {
			$count++;
		}
	}
	return $count;
};
$queue_row = static function ( $email ) use ( $wpdb, $queue ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue} WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) );
};
$drain = static function () {
	wp_cache_delete( 'npmp_queue_lock_newsletter', 'options' );
	do_action( 'npmp_process_queued_newsletters' );
};
// Only these readers subscribed, an empty queue, and a newsletter queued to them.
$send_to = static function ( $emails, $title ) use ( $wpdb, $queue ) {
	$wpdb->query( "DELETE FROM {$queue}" );
	foreach ( get_posts( array( 'post_type' => array( 'npmp_newsletter', 'npmp_contact' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $rig_id ) {
		wp_delete_post( $rig_id, true );
	}
	wp_cache_flush();
	foreach ( $emails as $rig_email ) {
		NPMP_Member_Manager::get_instance()->add_member( array( 'email' => $rig_email, 'name' => ucfirst( strtok( $rig_email, '@' ) ), 'status' => 'subscribed' ) );
	}
	$id = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>Rig</p>' ) );
	NPMP_Newsletter_Manager::queue_newsletter( $id );
	return $id;
};
$snapshot = static function () use ( $wpdb, $queue ) {
	return md5( wp_json_encode( $wpdb->get_results( "SELECT id, newsletter_id, user_id, email, status, queued_at, sent_at FROM {$queue} ORDER BY id", ARRAY_A ) ) );
};
// Load WordPress in another PHP process, the way a second request would, and
// note every ALTER TABLE it sends the queue table. With $hold set to a file
// path it holds its first ALTER until that file exists (20 seconds at most),
// so another request runs start to finish mid-upgrade.
$request = static function ( $hold, $then = '' ) {
	$code  = '$GLOBALS["rig_alters"] = array();';
	$code .= '$GLOBALS["wp_filter"] = array( "query" => array( 10 => array( array( "accepted_args" => 1, "function" => function ( $q ) {';
	$code .= ' if ( false !== stripos( $q, "ALTER TABLE" ) && false !== strpos( $q, "npmp_newsletter_queue" ) ) { $GLOBALS["rig_alters"][] = $q; if ( ' . ( $hold ? 'true' : 'false' ) . ' && 1 === count( $GLOBALS["rig_alters"] ) ) { for ( $w = 0; $w < 200 && ! file_exists( ' . var_export( (string) $hold, true ) . ' ); $w++ ) { usleep( 100000 ); } } }';
	$code .= ' return $q; } ) ) ) );';
	$code .= 'define( "WP_USE_THEMES", false ); require ' . var_export( ABSPATH . 'wp-load.php', true ) . ';';
	$code .= $then;
	$code .= 'echo "\nRIG:" . wp_json_encode( array( "alters" => $GLOBALS["rig_alters"], "current" => npmp_newsletter_queue_has_retries() ) );';
	$pipes = array();
	$proc  = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	return array( $proc, $pipes );
};
// What a cron runner does: the drain, with no time to send, so the rows stay
// as they were and only the upgrade shows.
$upgrade_by_cron = 'add_filter( "npmp_mail_queue_batch_seconds", "__return_zero" ); do_action( "npmp_process_queued_newsletters" );';
// What an admin's screen load does.
$upgrade_by_admin = 'wp_set_current_user( ' . (int) $admin[0]->ID . ' ); npmp_maybe_upgrade_newsletter_queue_in_admin();';
$finish = static function ( $started ) {
	list( $proc, $pipes ) = $started;
	$out = stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	$at = strrpos( $out, 'RIG:' );
	return false === $at ? array( 'alters' => array( 'no answer: ' . trim( $out ) ), 'current' => null ) : json_decode( substr( $out, $at + 4 ), true );
};

echo "A 2026.11.26 table with live rows upgrades once, even with two requests at it\n";
$wpdb->query( "DROP TABLE IF EXISTS {$queue}" );
// The table exactly as 2026.11.26 made it.
$wpdb->query(
	"CREATE TABLE {$queue} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		newsletter_id BIGINT NOT NULL,
		user_id BIGINT,
		email VARCHAR(255),
		status ENUM('pending','sent','failed') DEFAULT 'pending',
		queued_at DATETIME,
		sent_at DATETIME NULL,
		PRIMARY KEY (id),
		KEY newsletter_status (newsletter_id,status)
	) " . $wpdb->get_charset_collate()
);
$old_nl = $send_to( array( 'up-ann@example.org', 'up-bob@example.org', 'up-cy@example.org', 'up-dee@example.org' ), 'Rig Upgrade Letter' );
$ok( 'the old table takes the old queue', 4 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending'" ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'sent', sent_at = %s WHERE email = 'up-cy@example.org'", current_time( 'mysql' ) ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'failed', sent_at = %s WHERE email = 'up-dee@example.org'", current_time( 'mysql' ) ) );
delete_option( 'npmp_newsletter_queue_db_version' );
update_option( 'npmp_newsletter_queue_table_created', 1 );
delete_transient( 'npmp_newsletter_queue_db_retry_later' );
$before = $snapshot();
$ok( 'a 2026.11.26 site has the old flag and no schema version', ! npmp_newsletter_queue_has_retries() && ! npmp_newsletter_queue_schema_complete() );
$ok( 'admin screens upgrade, and plugins_loaded no longer does', false !== has_action( 'admin_init', 'npmp_maybe_upgrade_newsletter_queue_in_admin' ) && false === has_action( 'plugins_loaded', 'npmp_maybe_create_newsletter_queue_table' ) );

$visitor = $finish( $request( false ) );
wp_cache_flush();
$ok( "a visitor's page load leaves the table alone", array() === $visitor['alters'] && false === $visitor['current'] && ! npmp_newsletter_queue_schema_complete() );

$gate = $sink_file . '.gate';
wp_delete_file( $gate );
$first = $request( $gate, $upgrade_by_cron );
// Start the others once the first holds the upgrade lock, and let the first
// go on only after they finish.
for ( $rig_i = 0; $rig_i < 200 && null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'npmp_queue_lock_newsletter_queue_schema'" ); $rig_i++ ) {
	usleep( 50000 );
}
$second = $finish( $request( false, $upgrade_by_cron ) );
$third  = $finish( $request( false, $upgrade_by_admin ) );
touch( $gate );
$first = $finish( $first );
wp_delete_file( $gate );
wp_cache_flush();
$alters = array_merge( $first['alters'], $second['alters'], $third['alters'] );
$ok( 'the first cron runner altered the table', count( $first['alters'] ) >= 1 && true === $first['current'] );
$ok( 'a second cron runner, mid-upgrade, left it alone', array() === $second['alters'] && false === $second['current'] );
$ok( 'an admin screen, mid-upgrade, left it alone', array() === $third['alters'] && false === $third['current'] );
$ok( 'no ALTER changed or dropped a column that was there', array() === array_filter( $alters, static fn( $q ) => preg_match( '/\b(CHANGE|MODIFY|DROP)\b/i', $q ) ) );
$ok( 'the table has attempts, retry_at, and status_queued', npmp_newsletter_queue_schema_complete() );
$ok( 'the version is recorded', npmp_newsletter_queue_has_retries() );
$ok( 'the schema lock is free', null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'npmp_queue_lock_newsletter_queue_schema'" ) );
$ok( 'every row kept its values', $before === $snapshot() );
$ok( 'and starts with no tries and no wait', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE attempts <> 0 OR retry_at IS NOT NULL" ) );
$later = $finish( $request( false, $upgrade_by_cron ) );
$ok( 'a later drain has nothing to do', array() === $later['alters'] && true === $later['current'] );
$later = $finish( $request( false, $upgrade_by_admin ) );
$ok( 'nor does a later admin screen', array() === $later['alters'] && true === $later['current'] );
$columns = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM {$queue}" ), 'Type', 'Field' );
$ok( 'attempts is a small number that starts at 0', 0 === strpos( strtolower( $columns['attempts'] ?? '' ), 'tinyint' ) );
$ok( 'retry_at is a date', 'datetime' === strtolower( $columns['retry_at'] ?? '' ) );
$index = array();
foreach ( $wpdb->get_results( "SHOW INDEX FROM {$queue}" ) as $rig_key ) {
	if ( 'status_queued' === $rig_key->Key_name ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's name.
		$index[ (int) $rig_key->Seq_in_index ] = $rig_key->Column_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's names.
	}
}
ksort( $index );
$ok( 'status_queued runs status, queued_at, id, retry_at', array( 'status', 'queued_at', 'id', 'retry_at' ) === array_values( $index ) );

$drain();
$ok( 'the waiting readers go out once each', 1 === $sink_sends( 'up-ann@example.org' ) && 1 === $sink_sends( 'up-bob@example.org' ) );
$ok( 'the reader 2026.11.26 already mailed gets nothing', 0 === $sink_sends( 'up-cy@example.org' ) );
$ok( 'the old failure stays failed and untried', 'failed' === $queue_row( 'up-dee@example.org' )->status && 0 === $sink_sends( 'up-dee@example.org' ) );
$ok( 'the newsletter reads sent', 'sent' === get_post_meta( $old_nl, '_npmp_newsletter_status', true ) );

echo "A site with no queue table gets the whole table from its first drain\n";
$wpdb->query( "DROP TABLE IF EXISTS {$queue}" );
delete_option( 'npmp_newsletter_queue_db_version' );
delete_option( 'npmp_newsletter_queue_table_created' );
$fresh = $finish( $request( false ) );
wp_cache_flush();
$wpdb->suppress_errors( true );
$ok( "a visitor's page load makes nothing", false === $fresh['current'] && ! npmp_newsletter_queue_schema_complete() );
$wpdb->suppress_errors( false );
$fresh = $finish( $request( false, $upgrade_by_cron ) );
wp_cache_flush();
$ok( 'the first drain made it', true === $fresh['current'] && npmp_newsletter_queue_schema_complete() );
$ok( 'and set the flag the setup wizard reads', '1' === (string) get_option( 'npmp_newsletter_queue_table_created' ) );
$wpdb->query( "DROP TABLE IF EXISTS {$queue}" );
delete_option( 'npmp_newsletter_queue_db_version' );
$ok( 'activation makes it too', npmp_upgrade_newsletter_queue_table() && npmp_newsletter_queue_schema_complete() );

echo "A send that fails goes again once the mail server answers\n";
$short = static fn() => array( 2, 2, 2 );
add_filter( 'npmp_newsletter_retry_delays', $short );
$sent_before = $sink_sends();
$retry_nl    = $send_to( array( 'rt-ann@example.org', 'rt-bob@example.org', 'rt-cy@example.org', 'rt-dee@example.org' ), 'Rig Retry Letter' );
touch( $sink_fail );
$drain();
$ok( 'with the server refusing mail, nothing reached it', $sent_before === $sink_sends() );
$ok( 'three tries, then the batch stopped', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE attempts = 1 AND status = 'pending' AND retry_at IS NOT NULL" ) );
$untried = $wpdb->get_col( "SELECT email FROM {$queue} WHERE attempts = 0 AND status = 'pending' AND retry_at IS NULL" );
$tried   = $wpdb->get_col( "SELECT email FROM {$queue} WHERE attempts = 1" );
$ok( 'the fourth reader was never tried', 1 === count( $untried ) );
$ok( 'nobody is marked failed', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'failed'" ) );
$ok( 'the newsletter still reads queued', 'queued' === get_post_meta( $retry_nl, '_npmp_newsletter_status', true ) );
$ok( 'Site Health counts three readers tried', 3 === npmp_newsletter_queue_state()['retrying'] );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET queued_at = %s", gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 2 * HOUR_IN_SECONDS ) ) );
$health = npmp_newsletter_queue_health_test();
$ok( 'and, once they wait two hours, sends you to Email Settings', 'critical' === $health['status'] && false !== strpos( $health['description'], 'Email Settings' ) && false === strpos( $health['description'], 'cron' ) );

unlink( $sink_fail );
$drain();
$ok( 'the server is back, and the reader never tried goes out', 1 === count( $untried ) && 1 === $sink_sends( $untried[0] ) );
$ok( 'the three keep waiting out their 2 seconds', 3 === count( $tried ) && 0 === array_sum( array_map( $sink_sends, $tried ) ) && 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending'" ) );
sleep( 3 );
$drain();
$ok( 'after the wait all three go out, once each', array( 1, 1, 1 ) === array_map( $sink_sends, $tried ) );
$ok( 'on their second try', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'sent' AND attempts = 2" ) );
$ok( 'the first to go out still has one copy', 1 === $sink_sends( $untried[0] ) );
$ok( 'and the newsletter reads sent', 'sent' === get_post_meta( $retry_nl, '_npmp_newsletter_status', true ) );
remove_filter( 'npmp_newsletter_retry_delays', $short );

echo "A send that never goes through is failed after the last try\n";
$now_filter = static fn() => array( 0, 0, 0 );
add_filter( 'npmp_newsletter_retry_delays', $now_filter );
$fail_nl = $send_to( array( 'eve@example.org' ), 'Rig Failing Letter' );
touch( $sink_fail );
for ( $rig_i = 1; $rig_i <= 3; $rig_i++ ) {
	$drain();
	$ok( "try {$rig_i} leaves the row waiting", 'pending' === $queue_row( 'eve@example.org' )->status && $rig_i === (int) $queue_row( 'eve@example.org' )->attempts );
}
$ok( 'with the newsletter still queued', 'queued' === get_post_meta( $fail_nl, '_npmp_newsletter_status', true ) );
$drain();
$eve = $queue_row( 'eve@example.org' );
$ok( 'the fourth failure marks it failed', 'failed' === $eve->status && 4 === (int) $eve->attempts && null !== $eve->sent_at );
$ok( 'and the newsletter reads sent, with the failure counted', 'sent' === get_post_meta( $fail_nl, '_npmp_newsletter_status', true ) && 1 === (int) NPMP_Newsletter_Stats::get_instance()->get_failed_count( $fail_nl ) );
$drain();
$ok( 'nobody tries a failed row again', 4 === (int) $queue_row( 'eve@example.org' )->attempts );
unlink( $sink_fail );
NPMP_Newsletter_Manager::queue_newsletter( $fail_nl );
$eve2 = $queue_row( 'eve@example.org' );
$ok( 'Send now again gives the failed reader a fresh row', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = " . (int) $fail_nl ) && 'pending' === $eve2->status && 0 === (int) $eve2->attempts );
$drain();
$ok( 'and that one goes out', 1 === $sink_sends( 'eve@example.org' ) );
remove_filter( 'npmp_newsletter_retry_delays', $now_filter );

echo "Send now again while a reader waits for a retry mails nobody twice\n";
$again_nl = $send_to( array( 'fay@example.org', 'gus@example.org' ), 'Rig Again Letter' );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'sent', sent_at = %s, attempts = 1 WHERE email = 'fay@example.org'", current_time( 'mysql' ) ) );
$wait = gmdate( 'Y-m-d H:i:s', time() + 600 );
$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET attempts = 1, retry_at = %s WHERE email = 'gus@example.org'", $wait ) );
$gus_id = (int) $queue_row( 'gus@example.org' )->id;
NPMP_Newsletter_Manager::queue_newsletter( $again_nl );
$ok( 'two rows still, no copy added', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = " . (int) $again_nl ) );
$ok( 'the waiting reader keeps the same row, try count, and wait', $gus_id === (int) $queue_row( 'gus@example.org' )->id && 1 === (int) $queue_row( 'gus@example.org' )->attempts && $wait === $queue_row( 'gus@example.org' )->retry_at );
$ok( 'the reader already mailed is counted, not queued', 1 === NPMP_Newsletter_Manager::last_queue_already_sent() );
$drain();
$ok( 'a run inside the wait sends nothing', 0 === $sink_sends( 'gus@example.org' ) && 0 === $sink_sends( 'fay@example.org' ) );

echo "A slow batch stops before its lock goes stale\n";
$budget_nl = $send_to( array( 'h1@example.org', 'h2@example.org', 'h3@example.org', 'h4@example.org', 'h5@example.org', 'h6@example.org' ), 'Rig Budget Letter' );
$short_budget = static fn( $seconds, $name ) => 'newsletter' === $name ? 1.5 : $seconds;
$slow_send    = static function () {
	usleep( 600000 );
};
add_filter( 'npmp_mail_queue_batch_seconds', $short_budget, 10, 2 );
add_action( 'phpmailer_init', $slow_send );
$rig_t = microtime( true );
$drain();
$rig_t = microtime( true ) - $rig_t;
remove_action( 'phpmailer_init', $slow_send );
remove_filter( 'npmp_mail_queue_batch_seconds', $short_budget, 10 );
$went = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'sent'" );
$ok( "with a 1.5-second budget and 0.6-second sends, it stopped after {$went} of 6", $went >= 2 && $went <= 4 );
$ok( sprintf( 'and finished in %.1f seconds, inside the budget plus one send', $rig_t ), $rig_t < 1.5 + 0.6 + 1.0 );
$ok( 'the rest wait untouched for the next run', 6 - $went === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending' AND attempts = 0" ) );
$drain();
$ok( 'and the next run sends them, each reader once', array( 1, 1, 1, 1, 1, 1 ) === array_map( $sink_sends, array( 'h1@example.org', 'h2@example.org', 'h3@example.org', 'h4@example.org', 'h5@example.org', 'h6@example.org' ) ) );

echo "A run that outlives the lock's 300 seconds keeps it, so a second run can't mail the same readers\n";
$lock_nl = $send_to( array( 'k1@example.org', 'k2@example.org', 'k3@example.org', 'k4@example.org' ), 'Rig Lock Letter' );
$rig_seen = 0;
$second_run = null;
$age_lock = static function () use ( &$rig_seen, &$second_run, $wpdb, $request, $finish ) {
	$rig_seen++;
	if ( 1 === $rig_seen ) {
		// As if this run took the lock 310 seconds ago and never wrote it since.
		$old = (string) ( time() - 310 );
		$wpdb->update( $wpdb->options, array( 'option_value' => $old ), array( 'option_name' => 'npmp_queue_lock_newsletter' ) );
		npmp_mail_queue_lock_stamp( 'newsletter', $old );
	} elseif ( 2 === $rig_seen ) {
		// The next cron tick, in its own process, while this run still works.
		$second_run = $finish( $request( false, 'do_action( "npmp_process_queued_newsletters" );' ) );
	}
};
add_action( 'phpmailer_init', $age_lock );
$drain();
remove_action( 'phpmailer_init', $age_lock );
$ok( 'the second run started and found the lock fresh', is_array( $second_run ) && true === $second_run['current'] );
$ok( 'every reader got one copy', 1 === $sink_sends( 'k1@example.org' ) && 1 === $sink_sends( 'k2@example.org' ) && 1 === $sink_sends( 'k3@example.org' ) && 1 === $sink_sends( 'k4@example.org' ) );
$ok( 'and the run lock is free', null === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'npmp_queue_lock_newsletter'" ) );

if ( false === stripos( (string) $wpdb->db_server_info(), 'sqlite' ) && ! defined( 'SQLITE_DB_DROPIN_VERSION' ) ) {
	echo "MySQL reads the drain's rows through status_queued\n";
	$wpdb->query( "DELETE FROM {$queue}" );
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$queue} ( newsletter_id, user_id, email, status, queued_at, sent_at ) VALUES ( 1, 0, 'x@example.org', 'sent', %s, %s )", current_time( 'mysql' ), current_time( 'mysql' ) ) );
	for ( $rig_i = 0; $rig_i < 12; $rig_i++ ) {
		$wpdb->query( "INSERT INTO {$queue} ( newsletter_id, user_id, email, status, queued_at, sent_at ) SELECT newsletter_id, user_id, email, status, queued_at, sent_at FROM {$queue}" );
	}
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$queue} ( newsletter_id, user_id, email, status, queued_at ) VALUES ( 2, 0, 'y@example.org', 'pending', %s )", current_time( 'mysql' ) ) );
	$wpdb->query( "ANALYZE TABLE {$queue}" );
	$plan = $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT id, newsletter_id, user_id, email, attempts FROM {$queue} WHERE status = 'pending' AND ( retry_at IS NULL OR retry_at <= %s ) ORDER BY queued_at ASC, id ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), 10 ) );
	$ok( sprintf( 'with %d sent rows the plan uses %s and reads %d rows, no filesort', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue}" ), $plan->key ?? 'nothing', (int) ( $plan->rows ?? 0 ) ), 'status_queued' === ( $plan->key ?? '' ) && false === stripos( (string) ( $plan->Extra ?? '' ), 'filesort' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL's name.
}

echo "A reader who leaves after the drain read its batch gets no mail\n";
// A second request changes the data while the first message is out, the way a
// privacy erase or an unsubscribe link does during a 240-second run.
$wpdb->query( "DROP TABLE IF EXISTS {$queue}" );
npmp_create_newsletter_queue_table();
delete_option( 'npmp_queue_lock_newsletter' );
wp_cache_flush();
$leave_emails = array();
for ( $leave_n = 1; $leave_n <= 8; $leave_n++ ) {
	$leave_emails[] = sprintf( 'leave%d@example.org', $leave_n );
}
$leave_nl = $send_to( $leave_emails, 'Rig Leave Letter' );
$members  = NPMP_Member_Manager::get_instance();
$erased   = $members->get_member_by_email( 'leave3@example.org' );
$quit     = $members->get_member_by_email( 'leave5@example.org' );
$erased_id = (int) $erased->id;
$changed   = false;
add_action(
	'phpmailer_init',
	$change_mid_batch = static function () use ( &$changed, $wpdb, $queue, $members, $erased_id, $quit ) {
		if ( $changed ) {
			return;
		}
		$changed = true;
		wp_delete_post( $erased_id, true );
		$wpdb->delete( $queue, array( 'email' => 'leave4@example.org' ) ); // A privacy erase deleted this row.
		$members->update_member( $quit->id, array( 'status' => 'unsubscribed' ) );
	},
	1
);
$sink_before = $sink_sends();
$drain();
remove_action( 'phpmailer_init', $change_mid_batch, 1 );
$ok( 'the batch mailed the 5 readers who stayed', 5 === $sink_sends() - $sink_before );
$ok( 'the erased contact got nothing', 0 === $sink_sends( 'leave3@example.org' ) );
$ok( 'the reader whose row was deleted got nothing', 0 === $sink_sends( 'leave4@example.org' ) );
$ok( 'the reader who unsubscribed got nothing', 0 === $sink_sends( 'leave5@example.org' ) );
$ok( 'nobody got two copies', 1 === $sink_sends( 'leave1@example.org' ) && 1 === $sink_sends( 'leave8@example.org' ) );
$ok( 'the erased and unsubscribed readers have no queue row left', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE email IN ('leave3@example.org','leave5@example.org')" ) );
$ok( 'the newsletter reads sent', 'sent' === get_post_meta( $leave_nl, '_npmp_newsletter_status', true ) );

echo "Open and click tracking write nothing for a contact that does not exist\n";
$opens  = $wpdb->prefix . 'npmp_newsletter_opens';
$clicks = $wpdb->prefix . 'npmp_newsletter_clicks';
$wpdb->query( "DELETE FROM {$opens}" );
$wpdb->query( "DELETE FROM {$clicks}" );
$tracker = NPMP_Newsletter_Tracker::get_instance();
$live    = $members->get_member_by_email( 'leave1@example.org' );
$tracker->track_open( $leave_nl, (int) $live->id );
$tracker->track_click( $leave_nl, (int) $live->id, 'https://example.com/x' );
$ok( 'a real contact\'s open and click are recorded', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$opens}" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks}" ) );
$tracker->track_open( $leave_nl, $erased_id );
$tracker->track_click( $leave_nl, $erased_id, 'https://example.com/x' );
$ok( 'the erased contact\'s open and click add no row', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$opens}" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks}" ) );
$wpdb->query( "DELETE FROM {$opens}" );
$wpdb->query( "DELETE FROM {$clicks}" );

update_option( 'npmp_email_settings', $saved_mail );
if ( false === $saved_mail ) {
	delete_option( 'npmp_email_settings' );
}
@unlink( $sink_fail ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

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
