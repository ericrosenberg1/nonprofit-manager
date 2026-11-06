<?php
/**
 * File path: includes/npmp-scheduled-emails.php
 *
 * Scheduled emails: one email per event, sent a set time before it starts,
 * and "Send later" for newsletters to the membership list. Pro adds more
 * emails per event and group audiences through the hooks below.
 *
 * How it fits together:
 * - Each scheduled email is a row in {prefix}npmp_scheduled_emails with a
 *   UTC send time. Event rows come from the rules (npmp_event_email_rules)
 *   and npmp_scheduled_emails_sync_event() keeps them in step with the event.
 * - A per-minute WP-Cron sweep claims each due row with a guarded UPDATE, so
 *   two overlapping runs can never send the same row twice.
 * - A due event row becomes a normal npmp_newsletter (placeholders filled in)
 *   and goes through NPMP_Newsletter_Manager::queue_newsletter(), so it gets
 *   the rate limit, tracking, unsubscribe links and a line in Reports.
 * - Bodies live in their own post type (npmp_email_body) that the block
 *   editor edits like a newsletter. They are never newsletters themselves,
 *   so nothing lists or sends them by accident.
 *
 * Off by default on every site (option npmp_scheduled_email_defaults,
 * enabled = 0), so an upgrade never starts emailing a live site's members.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_SCHEDULED_EMAILS_DB_VERSION' ) ) {
	define( 'NPMP_SCHEDULED_EMAILS_DB_VERSION', '1' );
}

/* =========================================================================
 * Table
 * ====================================================================== */

/**
 * Scheduled emails table name.
 *
 * @return string
 */
function npmp_scheduled_emails_table() {
	global $wpdb;
	return $wpdb->prefix . 'npmp_scheduled_emails';
}

/**
 * Create or update the scheduled emails table.
 *
 * @return void
 */
function npmp_create_scheduled_emails_table() {
	global $wpdb;

	$table   = npmp_scheduled_emails_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		target_type VARCHAR(20) NOT NULL DEFAULT 'event',
		target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		rule_key VARCHAR(64) NOT NULL DEFAULT '',
		audience_type VARCHAR(20) NOT NULL DEFAULT 'all',
		audience_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		send_at DATETIME NOT NULL,
		body_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		subject VARCHAR(255) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		newsletter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
		note VARCHAR(255) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY target_rule (target_type,target_id,rule_key,audience_type,audience_id),
		KEY status_send (status,send_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Create the table once per schema version. CREATE TABLE IF NOT EXISTS
 * would stop dbDelta from adding columns later, so the version option
 * decides instead.
 *
 * @return void
 */
function npmp_maybe_create_scheduled_emails_table() {
	if ( NPMP_SCHEDULED_EMAILS_DB_VERSION === get_option( 'npmp_scheduled_emails_db_version' ) ) {
		return;
	}
	npmp_create_scheduled_emails_table();
	update_option( 'npmp_scheduled_emails_db_version', NPMP_SCHEDULED_EMAILS_DB_VERSION );
}
add_action( 'plugins_loaded', 'npmp_maybe_create_scheduled_emails_table', 40 );

/* =========================================================================
 * Settings
 * ====================================================================== */

/**
 * Site defaults for event emails, merged over the built-in values.
 *
 * @return array{enabled:int,minutes_before:int,body_id:int,subject:string,late_mode:string,late_min_hours:int}
 */
function npmp_scheduled_email_defaults() {
	$saved = get_option( 'npmp_scheduled_email_defaults', array() );
	return npmp_scheduled_email_sanitize_defaults( is_array( $saved ) ? $saved : array() );
}

/**
 * Clean a defaults array. Missing keys take the built-in values.
 *
 * @param array $in Raw values.
 * @return array
 */
function npmp_scheduled_email_sanitize_defaults( $in ) {
	$out = array(
		'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
		'minutes_before' => isset( $in['minutes_before'] ) ? npmp_scheduled_emails_clamp_minutes( $in['minutes_before'] ) : 1440,
		'body_id'        => isset( $in['body_id'] ) ? max( 0, (int) $in['body_id'] ) : 0,
		'subject'        => isset( $in['subject'] ) ? substr( trim( wp_strip_all_tags( (string) $in['subject'] ) ), 0, 255 ) : '',
		'late_mode'      => isset( $in['late_mode'] ) && in_array( $in['late_mode'], array( 'send_if', 'skip', 'send' ), true ) ? $in['late_mode'] : 'send_if',
		'late_min_hours' => isset( $in['late_min_hours'] ) ? min( 24 * 60, max( 1, (int) $in['late_min_hours'] ) ) : 48,
	);
	return $out;
}

/**
 * Keep an offset between one minute and 60 days.
 *
 * @param mixed $minutes Minutes before the event.
 * @return int
 */
function npmp_scheduled_emails_clamp_minutes( $minutes ) {
	return min( 60 * 24 * 60, max( 1, (int) $minutes ) );
}

/**
 * The timing choices both screens offer, in minutes.
 *
 * @return array<int,string>
 */
function npmp_scheduled_emails_timing_choices() {
	return array(
		10080 => __( '1 week before', 'nonprofit-manager' ),
		4320  => __( '3 days before', 'nonprofit-manager' ),
		1440  => __( '1 day before', 'nonprofit-manager' ),
		120   => __( '2 hours before', 'nonprofit-manager' ),
	);
}

/**
 * Words for an offset: "1 day before", "5 hours before".
 *
 * @param int $minutes Minutes before the event.
 * @return string
 */
function npmp_scheduled_emails_describe_minutes( $minutes ) {
	$minutes = (int) $minutes;
	$choices = npmp_scheduled_emails_timing_choices();
	if ( isset( $choices[ $minutes ] ) ) {
		return $choices[ $minutes ];
	}
	if ( 0 === $minutes % 60 ) {
		$hours = (int) ( $minutes / 60 );
		/* translators: %d: number of hours. */
		return sprintf( _n( '%d hour before', '%d hours before', $hours, 'nonprofit-manager' ), $hours );
	}
	/* translators: %d: number of minutes. */
	return sprintf( _n( '%d minute before', '%d minutes before', $minutes, 'nonprofit-manager' ), $minutes );
}

/* =========================================================================
 * Time math
 * ====================================================================== */

/**
 * The current Unix time. Tests and the rig move the clock with the
 * npmp_scheduled_emails_now filter.
 *
 * @return int
 */
function npmp_scheduled_emails_now() {
	return (int) apply_filters( 'npmp_scheduled_emails_now', time() );
}

/**
 * When an email goes out, in UTC.
 *
 * Whole-day offsets keep the wall-clock time in the site timezone, so "a
 * week before" a 7 pm meeting is 7 pm a week earlier even across a clock
 * change. Other offsets count real minutes back from the start.
 *
 * @param string       $start_local    Event start, "Y-m-d H:i:s" in the site timezone.
 * @param int          $minutes_before Offset in minutes.
 * @param DateTimeZone $tz             Site timezone (wp_timezone()).
 * @return string "Y-m-d H:i:s" in UTC, or '' when the start can't be read.
 */
function npmp_scheduled_emails_send_at_utc( $start_local, $minutes_before, $tz ) {
	if ( empty( $start_local ) ) {
		return '';
	}
	try {
		$start = new DateTimeImmutable( (string) $start_local, $tz );
	} catch ( Exception $e ) {
		return '';
	}

	$minutes_before = (int) $minutes_before;
	if ( $minutes_before > 0 && 0 === $minutes_before % 1440 ) {
		$send = $start->modify( '-' . (int) ( $minutes_before / 1440 ) . ' days' );
	} else {
		$send = $start->setTimestamp( $start->getTimestamp() - ( $minutes_before * 60 ) );
	}

	return $send->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

/**
 * Unix time for a stored UTC datetime.
 *
 * @param string $utc "Y-m-d H:i:s" in UTC.
 * @return int
 */
function npmp_scheduled_emails_utc_to_ts( $utc ) {
	$ts = strtotime( (string) $utc . ' UTC' );
	return false === $ts ? 0 : (int) $ts;
}

/**
 * What to do with an email whose send time already passed when someone
 * saved the event.
 *
 * @param string $mode      'send_if' (send when the event is far enough out), 'skip', or 'send'.
 * @param int    $min_hours How far out the event must be for 'send_if'.
 * @param int    $start_ts  Event start, Unix time.
 * @param int    $now_ts    Now, Unix time.
 * @return string 'send' or 'skip'.
 */
function npmp_scheduled_emails_late_decision( $mode, $min_hours, $start_ts, $now_ts ) {
	if ( 'skip' === $mode ) {
		return 'skip';
	}
	if ( 'send' === $mode ) {
		return 'send';
	}
	return ( (int) $start_ts - (int) $now_ts ) > ( (int) $min_hours * 3600 ) ? 'send' : 'skip';
}

/**
 * Whether the send window has closed: no event email in the last hour
 * before the start, and nothing once it has started.
 *
 * @param int $start_ts Event start, Unix time.
 * @param int $now_ts   Now, Unix time.
 * @return string '' when it can go, 'started' or 'last_hour' when it can't.
 */
function npmp_scheduled_emails_window_block( $start_ts, $now_ts ) {
	if ( $now_ts >= $start_ts ) {
		return 'started';
	}
	if ( $now_ts >= $start_ts - 3600 ) {
		return 'last_hour';
	}
	return '';
}

/* =========================================================================
 * Placeholders
 * ====================================================================== */

/**
 * The placeholders an email body can use, with what each one becomes.
 *
 * @return array<string,string>
 */
function npmp_scheduled_emails_placeholder_help() {
	$help = array(
		'[event_title]'    => __( 'The event name', 'nonprofit-manager' ),
		'[event_date]'     => __( 'The date it starts', 'nonprofit-manager' ),
		'[event_time]'     => __( 'The time it starts', 'nonprofit-manager' ),
		'[event_location]' => __( 'Where it is', 'nonprofit-manager' ),
		'[event_url]'      => __( 'The event page on your site', 'nonprofit-manager' ),
		'[join_link]'      => __( 'The event link (a meeting link, say), or the event page', 'nonprofit-manager' ),
		'[ical_link]'      => __( 'A calendar file members can add to their calendar', 'nonprofit-manager' ),
	);
	/**
	 * Filter the placeholder list the body editor shows. Pro adds
	 * [group_name] and [leave_group_url].
	 *
	 * @param array<string,string> $help '[placeholder]' => what it becomes.
	 */
	return (array) apply_filters( 'npmp_scheduled_email_placeholder_help', $help );
}

/**
 * Swap placeholders for their values.
 *
 * Values arrive raw. In HTML, link placeholders (names ending in _url or
 * _link) go through esc_url() and the rest through esc_html(). The block
 * editor's link box turns "[join_link]" into "http://[join_link]", so those
 * prefixed forms are swapped too.
 *
 * @param string $text Body or subject.
 * @param array  $map  '[placeholder]' => raw value.
 * @param bool   $html True for the body, false for a plain-text subject.
 * @return string
 */
function npmp_scheduled_emails_fill( $text, $map, $html = true ) {
	$pairs = array();
	foreach ( (array) $map as $tag => $value ) {
		$tag   = (string) $tag;
		$value = (string) $value;
		if ( $html ) {
			$is_link = (bool) preg_match( '/(_url|_link)\]$/', $tag );
			$value   = $is_link ? esc_url( $value ) : esc_html( $value );
			if ( $is_link ) {
				$pairs[ 'http://' . $tag ]  = $value;
				$pairs[ 'https://' . $tag ] = $value;
			}
		} else {
			$value = trim( wp_strip_all_tags( $value ) );
		}
		$pairs[ $tag ] = $value;
	}
	return strtr( (string) $text, $pairs );
}

/**
 * The calendar file link for one event: the event's own iCal URL when the
 * calendar provides one, otherwise the site feed.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function npmp_scheduled_emails_ical_url( $event_id ) {
	$url = '';
	if ( function_exists( 'npmp_event_ical_url' ) ) {
		$url = (string) npmp_event_ical_url( $event_id );
	}
	if ( '' === $url ) {
		$url = add_query_arg( 'npmp-ical', '1', home_url( '/' ) );
	}
	return (string) apply_filters( 'npmp_scheduled_email_ical_url', $url, $event_id );
}

/**
 * Raw placeholder values for one event.
 *
 * @param int         $event_id Event ID.
 * @param object|null $row      Scheduled email row, when there is one.
 * @return array<string,string>
 */
function npmp_scheduled_emails_placeholders( $event_id, $row = null ) {
	$event_id = (int) $event_id;
	$details  = npmp_scheduled_emails_event_details( $event_id );
	$tz       = wp_timezone();
	$start_ts = $details['start'] ? npmp_scheduled_emails_local_to_ts( $details['start'] ) : 0;
	$all_day  = $details['start'] && '00:00:00' === substr( $details['start'], 11 );
	$page     = (string) get_permalink( $event_id );

	$map = array(
		'[event_title]'    => (string) get_post_field( 'post_title', $event_id, 'raw' ),
		'[event_date]'     => $start_ts ? (string) wp_date( (string) get_option( 'date_format' ), $start_ts, $tz ) : '',
		'[event_time]'     => $start_ts ? ( $all_day ? __( 'All day', 'nonprofit-manager' ) : (string) wp_date( (string) get_option( 'time_format' ), $start_ts, $tz ) ) : '',
		'[event_location]' => '' !== $details['location'] ? $details['location'] : __( 'See the event page', 'nonprofit-manager' ),
		'[event_url]'      => $page,
		'[join_link]'      => '' !== $details['url'] ? $details['url'] : $page,
		'[ical_link]'      => npmp_scheduled_emails_ical_url( $event_id ),
	);

	return (array) apply_filters( 'npmp_scheduled_email_placeholders', $map, $event_id, $row );
}

/* =========================================================================
 * Events
 * ====================================================================== */

/**
 * Start, location and link for an event, read the way the calendar reads
 * them, and safe when the calendar module is off.
 *
 * @param int $event_id Event ID.
 * @return array{start:string,location:string,url:string}
 */
function npmp_scheduled_emails_event_details( $event_id ) {
	if ( function_exists( 'npmp_get_event_details' ) ) {
		$d = npmp_get_event_details( $event_id );
	} else {
		$d = array(
			'start'    => get_post_meta( $event_id, '_npmp_event_start', true ),
			'location' => get_post_meta( $event_id, '_npmp_event_location', true ),
			'url'      => get_post_meta( $event_id, '_npmp_event_url', true ),
		);
	}
	return array(
		'start'    => (string) ( $d['start'] ?? '' ),
		'location' => (string) ( $d['location'] ?? '' ),
		'url'      => (string) ( $d['url'] ?? '' ),
	);
}

/**
 * Unix time for a stored event datetime (site timezone).
 *
 * @param string $local "Y-m-d H:i:s" in the site timezone.
 * @return int
 */
function npmp_scheduled_emails_local_to_ts( $local ) {
	try {
		return ( new DateTimeImmutable( (string) $local, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

/**
 * Whether an event is cancelled. Events have no cancelled state of their
 * own yet, so this reads the two meta keys importers use and lets add-ons
 * decide through npmp_scheduled_email_event_cancelled.
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function npmp_scheduled_emails_event_is_cancelled( $event_id ) {
	$cancelled = 'cancelled' === get_post_meta( $event_id, '_npmp_event_status', true )
		|| (bool) get_post_meta( $event_id, '_npmp_event_cancelled', true );
	return (bool) apply_filters( 'npmp_scheduled_email_event_cancelled', $cancelled, $event_id );
}

/**
 * This event's own email settings, as saved from its Scheduled Email box.
 *
 * @param int $event_id Event ID.
 * @return array{mode:string,minutes_before:int,body_id:int|string,subject:string} minutes_before 0
 *         and body_id '' mean "use the site default".
 */
function npmp_scheduled_emails_event_settings( $event_id ) {
	$saved = get_post_meta( $event_id, '_npmp_sched_email', true );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'mode'           => isset( $saved['mode'] ) && in_array( $saved['mode'], array( 'default', 'on', 'off' ), true ) ? $saved['mode'] : 'default',
		'minutes_before' => ! empty( $saved['minutes_before'] ) ? npmp_scheduled_emails_clamp_minutes( $saved['minutes_before'] ) : 0,
		'body_id'        => isset( $saved['body_id'] ) && '' !== $saved['body_id'] ? max( 0, (int) $saved['body_id'] ) : '',
		'subject'        => isset( $saved['subject'] ) ? (string) $saved['subject'] : '',
	);
}

/**
 * Free's rule for one event: at most one email, from the site defaults and
 * the event's own settings.
 *
 * @param int $event_id Event ID.
 * @return array[]
 */
function npmp_scheduled_emails_free_rules( $event_id ) {
	$defaults = npmp_scheduled_email_defaults();
	$own      = npmp_scheduled_emails_event_settings( $event_id );
	$on       = 'on' === $own['mode'] || ( 'default' === $own['mode'] && $defaults['enabled'] );
	if ( ! $on ) {
		return array();
	}
	return array(
		array(
			'key'            => 'default',
			'minutes_before' => $own['minutes_before'] ? $own['minutes_before'] : $defaults['minutes_before'],
			'body_id'        => '' !== $own['body_id'] ? (int) $own['body_id'] : (int) $defaults['body_id'],
			'subject'        => '' !== $own['subject'] ? $own['subject'] : $defaults['subject'],
			'audience'       => array(
				'type' => 'all',
				'id'   => 0,
			),
		),
	);
}

/**
 * Every rule for an event, after add-ons and the per-event limit.
 *
 * @param int $event_id Event ID.
 * @return array[] Normalised rules.
 */
function npmp_scheduled_emails_event_rules( $event_id ) {
	$rules = apply_filters( 'npmp_event_email_rules', npmp_scheduled_emails_free_rules( $event_id ), $event_id );
	$max   = (int) apply_filters( 'npmp_event_email_max', 1 );
	$out   = array();
	foreach ( (array) $rules as $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['key'] ) ) {
			continue;
		}
		$audience = isset( $rule['audience'] ) && is_array( $rule['audience'] ) ? $rule['audience'] : array();
		$type     = isset( $audience['type'] ) && 'group' === $audience['type'] ? 'group' : 'all';
		$out[]    = array(
			'key'            => substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $rule['key'] ) ), 0, 64 ),
			'minutes_before' => npmp_scheduled_emails_clamp_minutes( $rule['minutes_before'] ?? 1440 ),
			'body_id'        => max( 0, (int) ( $rule['body_id'] ?? 0 ) ),
			'subject'        => substr( (string) ( $rule['subject'] ?? '' ), 0, 255 ),
			'audience_type'  => $type,
			'audience_id'    => 'group' === $type ? absint( $audience['id'] ?? 0 ) : 0,
		);
	}
	if ( $max > 0 ) {
		$out = array_slice( $out, 0, $max );
	}
	return $out;
}

/**
 * The key that names one row of an event: rule plus audience.
 *
 * @param string $rule_key      Rule key.
 * @param string $audience_type 'all' or 'group'.
 * @param int    $audience_id   Group ID, 0 for all.
 * @return string
 */
function npmp_scheduled_emails_row_key( $rule_key, $audience_type, $audience_id ) {
	return $rule_key . '|' . $audience_type . '|' . (int) $audience_id;
}

/**
 * Rows for one target, oldest send time first.
 *
 * @param string $target_type 'event' or 'list'.
 * @param int    $target_id   Event or newsletter ID.
 * @return object[]
 */
function npmp_scheduled_emails_get_rows( $target_type, $target_id ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table, read fresh every time.
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE target_type = %s AND target_id = %d ORDER BY send_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			$target_type,
			$target_id
		)
	);
}

/**
 * One row by id.
 *
 * @param int $id Row ID.
 * @return object|null
 */
function npmp_scheduled_emails_get_row( $id ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
	return $row ? $row : null;
}

/**
 * Update one row's fields and stamp updated_at.
 *
 * @param int   $id     Row ID.
 * @param array $fields Column => value.
 * @return void
 */
function npmp_scheduled_emails_update_row( $id, $fields ) {
	global $wpdb;
	$fields['updated_at'] = gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() );
	if ( isset( $fields['note'] ) ) {
		$fields['note'] = substr( (string) $fields['note'], 0, 255 );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$wpdb->update( npmp_scheduled_emails_table(), $fields, array( 'id' => (int) $id ) );
}

/**
 * Insert a row.
 *
 * @param array $fields Column => value.
 * @return int New row ID.
 */
function npmp_scheduled_emails_insert_row( $fields ) {
	global $wpdb;
	$now    = gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() );
	$fields = array_merge(
		array(
			'audience_type'   => 'all',
			'audience_id'     => 0,
			'body_id'         => 0,
			'subject'         => '',
			'status'          => 'pending',
			'newsletter_id'   => 0,
			'recipient_count' => 0,
			'note'            => '',
			'created_at'      => $now,
			'updated_at'      => $now,
		),
		$fields
	);
	$fields['note'] = substr( (string) $fields['note'], 0, 255 );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$wpdb->insert( npmp_scheduled_emails_table(), $fields );
	return (int) $wpdb->insert_id;
}

/**
 * Cancel every pending row of one target.
 *
 * @param string $target_type 'event' or 'list'.
 * @param int    $target_id   Event or newsletter ID.
 * @param string $note        Why.
 * @return void
 */
function npmp_scheduled_emails_cancel_target( $target_type, $target_id, $note = '' ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'cancelled', note = %s, updated_at = %s WHERE target_type = %s AND target_id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			substr( (string) $note, 0, 255 ),
			gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() ),
			$target_type,
			$target_id
		)
	);
}

/**
 * Rule keys an admin skipped by hand on this event. Those rows stay
 * skipped through later saves until the admin turns them back on.
 *
 * @param int $event_id Event ID.
 * @return string[] Row keys.
 */
function npmp_scheduled_emails_admin_skips( $event_id ) {
	$skips = get_post_meta( $event_id, '_npmp_sched_email_skips', true );
	return is_array( $skips ) ? array_values( array_map( 'strval', $skips ) ) : array();
}

/**
 * Whether an event belongs to a Pro group. Reads the term tables directly,
 * since the group taxonomy isn't registered without Pro.
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function npmp_scheduled_emails_event_has_groups( $event_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup per event save.
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = 'npmp_group' LIMIT 1", (int) $event_id ) );
}

/**
 * Recompute an event's scheduled emails from its rules.
 *
 * Pending rows follow the event to its new time. Sending and sent rows are
 * never touched, so a sent email never goes out twice. A row whose rule
 * went away, or whose event is no longer published, is cancelled. A send
 * time that already passed follows the late rule. Pro calls this after it
 * saves its own rules.
 *
 * @param int $event_id Event ID.
 * @return int Number of rows now pending.
 */
function npmp_scheduled_emails_sync_event( $event_id ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );
	if ( ! $post || 'npmp_event' !== $post->post_type ) {
		return 0;
	}
	unset( $GLOBALS['npmp_scheduled_emails_dirty'][ $event_id ] );

	$details  = npmp_scheduled_emails_event_details( $event_id );
	$start_ts = $details['start'] ? npmp_scheduled_emails_local_to_ts( $details['start'] ) : 0;
	$reason   = '';
	if ( 'trash' === $post->post_status ) {
		$reason = __( 'The event is in the trash.', 'nonprofit-manager' );
	} elseif ( 'publish' !== $post->post_status ) {
		$reason = __( 'The event isn\'t live on the site.', 'nonprofit-manager' );
	} elseif ( npmp_scheduled_emails_event_is_cancelled( $event_id ) ) {
		$reason = __( 'Someone cancelled the event.', 'nonprofit-manager' );
	} elseif ( ! $start_ts ) {
		$reason = __( 'The event has no start date.', 'nonprofit-manager' );
	}

	// A group event's emails belong to Pro. Without Pro, leave its rows as
	// they are (group rows stay pending, and nothing goes to everyone) until
	// Pro comes back. Trashing or cancelling still cancels them below.
	if ( '' === $reason && npmp_scheduled_emails_event_has_groups( $event_id ) && ! apply_filters( 'npmp_scheduled_email_groups_supported', false ) ) {
		return count( array_filter( npmp_scheduled_emails_get_rows( 'event', $event_id ), function ( $row ) {
			return 'pending' === $row->status;
		} ) );
	}

	$rules    = '' === $reason ? npmp_scheduled_emails_event_rules( $event_id ) : array();
	$existing = array();
	foreach ( npmp_scheduled_emails_get_rows( 'event', $event_id ) as $row ) {
		$existing[ npmp_scheduled_emails_row_key( $row->rule_key, $row->audience_type, $row->audience_id ) ] = $row;
	}

	$defaults = npmp_scheduled_email_defaults();
	$skips    = npmp_scheduled_emails_admin_skips( $event_id );
	$now      = npmp_scheduled_emails_now();
	$seen     = array();
	$pending  = 0;

	foreach ( $rules as $rule ) {
		$key          = npmp_scheduled_emails_row_key( $rule['key'], $rule['audience_type'], $rule['audience_id'] );
		$seen[ $key ] = true;
		$send_at      = npmp_scheduled_emails_send_at_utc( $details['start'], $rule['minutes_before'], wp_timezone() );
		$row          = $existing[ $key ] ?? null;
		$fields       = array(
			'send_at' => $send_at,
			'body_id' => $rule['body_id'],
			'subject' => $rule['subject'],
		);

		if ( $row && in_array( $row->status, array( 'sending', 'sent' ), true ) ) {
			continue;
		}
		if ( $row && 'skipped' === $row->status && in_array( $key, $skips, true ) ) {
			npmp_scheduled_emails_update_row( $row->id, $fields );
			continue;
		}

		$fields['status'] = 'pending';
		$fields['note']   = '';
		// A pending row that's already due (the sweep hasn't reached it yet,
		// the late rule sent it, or an admin asked for an update) stays on its
		// way. Judging it again by the late rule skipped it whenever someone
		// saved the event in the minutes before the next cron run. The sweep
		// still never sends after the start or in the last hour.
		$already_due = $row && 'pending' === $row->status && npmp_scheduled_emails_utc_to_ts( $row->send_at ) <= $now;
		if ( $already_due && npmp_scheduled_emails_utc_to_ts( $send_at ) <= $now ) {
			$fields['send_at'] = $row->send_at;
			$fields['note']    = (string) $row->note;
		} elseif ( npmp_scheduled_emails_utc_to_ts( $send_at ) <= $now ) {
			if ( 'send' === npmp_scheduled_emails_late_decision( $defaults['late_mode'], $defaults['late_min_hours'], $start_ts, $now ) ) {
				$fields['send_at'] = gmdate( 'Y-m-d H:i:s', $now );
				$fields['note']    = __( 'Someone saved the event after its send time, so the late rule sends this email right away.', 'nonprofit-manager' );
			} else {
				$fields['status'] = 'skipped';
				$fields['note']   = __( 'Someone saved the event after its send time, and the late rule skipped this email.', 'nonprofit-manager' );
			}
		}
		if ( 'pending' === $fields['status'] ) {
			++$pending;
		}

		if ( $row ) {
			npmp_scheduled_emails_update_row( $row->id, $fields );
		} else {
			npmp_scheduled_emails_insert_row(
				array_merge(
					$fields,
					array(
						'target_type'   => 'event',
						'target_id'     => $event_id,
						'rule_key'      => $rule['key'],
						'audience_type' => $rule['audience_type'],
						'audience_id'   => $rule['audience_id'],
					)
				)
			);
		}
	}

	foreach ( $existing as $key => $row ) {
		if ( isset( $seen[ $key ] ) || 'pending' !== $row->status ) {
			continue;
		}
		npmp_scheduled_emails_update_row(
			$row->id,
			array(
				'status' => 'cancelled',
				'note'   => '' !== $reason ? $reason : __( 'This email is off for this event.', 'nonprofit-manager' ),
			)
		);
	}

	return $pending;
}

/**
 * Sync after the event's own meta box saved (priority 10), and save this
 * file's box first when it was on the form.
 *
 * @param int $post_id Event ID.
 * @return void
 */
function npmp_scheduled_emails_on_event_save( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	npmp_scheduled_emails_save_event_box( $post_id );
	npmp_scheduled_emails_sync_event( $post_id );
}
add_action( 'save_post_npmp_event', 'npmp_scheduled_emails_on_event_save', 20 );

/**
 * Trash, untrash and unpublish arrive as status changes. save_post covers
 * most of them, but not every code path fires it.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function npmp_scheduled_emails_on_transition( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ! $post || 'npmp_event' !== $post->post_type ) {
		return;
	}
	npmp_scheduled_emails_mark_dirty( $post->ID );
}
add_action( 'transition_post_status', 'npmp_scheduled_emails_on_transition', 10, 3 );

/**
 * Trashed, untrashed: sync right away so the screen after the redirect
 * shows the right status.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function npmp_scheduled_emails_on_trash_change( $post_id ) {
	if ( 'npmp_event' === get_post_type( $post_id ) ) {
		npmp_scheduled_emails_sync_event( $post_id );
	} elseif ( 'npmp_newsletter' === get_post_type( $post_id ) && 'trash' === get_post_status( $post_id ) ) {
		npmp_scheduled_emails_cancel_target( 'list', $post_id, __( 'The newsletter is in the trash.', 'nonprofit-manager' ) );
	}
}
add_action( 'trashed_post', 'npmp_scheduled_emails_on_trash_change' );
add_action( 'untrashed_post', 'npmp_scheduled_emails_on_trash_change' );

/**
 * A deleted event or newsletter cancels its pending rows. The rows stay as
 * a record of what went out.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    The deleted post (WordPress 5.5 and later).
 * @return void
 */
function npmp_scheduled_emails_on_delete( $post_id, $post = null ) {
	// Every deleted post and revision fires this, so skip the two UPDATEs
	// for anything that can't have a row.
	$type = $post instanceof WP_Post ? $post->post_type : '';
	if ( '' === $type || 'npmp_event' === $type ) {
		npmp_scheduled_emails_cancel_target( 'event', $post_id, __( 'Someone deleted the event.', 'nonprofit-manager' ) );
	}
	if ( '' === $type || 'npmp_newsletter' === $type ) {
		npmp_scheduled_emails_cancel_target( 'list', $post_id, __( 'Someone deleted the newsletter.', 'nonprofit-manager' ) );
	}
}
add_action( 'deleted_post', 'npmp_scheduled_emails_on_delete', 10, 2 );

/**
 * A start date changed outside the editor (an import, a script): sync that
 * event at the end of the request.
 *
 * @param int    $meta_id   Meta ID.
 * @param int    $object_id Post ID.
 * @param string $meta_key  Meta key.
 * @return void
 */
function npmp_scheduled_emails_on_meta_change( $meta_id, $object_id, $meta_key ) {
	if ( in_array( $meta_key, array( '_npmp_event_start', '_npmp_event_status', '_npmp_event_cancelled' ), true ) ) {
		npmp_scheduled_emails_mark_dirty( $object_id );
	}
}
add_action( 'added_post_meta', 'npmp_scheduled_emails_on_meta_change', 10, 3 );
add_action( 'updated_post_meta', 'npmp_scheduled_emails_on_meta_change', 10, 3 );
add_action( 'deleted_post_meta', 'npmp_scheduled_emails_on_meta_change', 10, 3 );

/**
 * Queue an event for a sync at shutdown.
 *
 * @param int $event_id Event ID.
 * @return void
 */
function npmp_scheduled_emails_mark_dirty( $event_id ) {
	if ( empty( $GLOBALS['npmp_scheduled_emails_dirty'] ) ) {
		$GLOBALS['npmp_scheduled_emails_dirty'] = array();
		add_action( 'shutdown', 'npmp_scheduled_emails_flush_dirty' );
	}
	$GLOBALS['npmp_scheduled_emails_dirty'][ (int) $event_id ] = true;
}

/**
 * Sync every event queued by npmp_scheduled_emails_mark_dirty().
 *
 * @return void
 */
function npmp_scheduled_emails_flush_dirty() {
	$ids = array_keys( (array) ( $GLOBALS['npmp_scheduled_emails_dirty'] ?? array() ) );
	foreach ( $ids as $id ) {
		npmp_scheduled_emails_sync_event( $id );
	}
}

/**
 * Re-sync every upcoming event, after the site defaults change.
 *
 * @return void
 */
function npmp_scheduled_emails_sync_upcoming_events() {
	global $wpdb;
	$ids = get_posts(
		array(
			'post_type'      => 'npmp_event',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Event start is the only date an event has.
			'meta_query'     => array(
				array(
					'key'     => '_npmp_event_start',
					'value'   => wp_date( 'Y-m-d H:i:s', npmp_scheduled_emails_now() ),
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		)
	);
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$pending = $wpdb->get_col( "SELECT DISTINCT target_id FROM {$table} WHERE target_type = 'event' AND status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no input.
	foreach ( array_unique( array_merge( array_map( 'intval', (array) $ids ), array_map( 'intval', (array) $pending ) ) ) as $id ) {
		npmp_scheduled_emails_sync_event( $id );
	}
}

/* =========================================================================
 * Bodies
 * ====================================================================== */

/**
 * Register the email body post type. Bodies use the block editor and the
 * newsletter capabilities, but they are not newsletters: the archive,
 * Reports and the send queue only ever read npmp_newsletter, so a body
 * can't show up as an unsent newsletter or go out by itself.
 *
 * @return void
 */
function npmp_register_email_body_post_type() {
	register_post_type(
		'npmp_email_body',
		array(
			'labels'          => array(
				'name'          => __( 'Scheduled Email Bodies', 'nonprofit-manager' ),
				'singular_name' => __( 'Scheduled Email Body', 'nonprofit-manager' ),
				'add_new_item'  => __( 'Write a Scheduled Email Body', 'nonprofit-manager' ),
				'edit_item'     => __( 'Edit Scheduled Email Body', 'nonprofit-manager' ),
				'not_found'     => __( 'No scheduled email bodies yet.', 'nonprofit-manager' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => true,
			'supports'        => array( 'title', 'editor' ),
			'capability_type' => array( 'npmp_newsletter', 'npmp_newsletters' ),
			'map_meta_cap'    => true,
		)
	);
}
add_action( 'init', 'npmp_register_email_body_post_type' );

/**
 * The two built-in bodies, with their subjects.
 *
 * @return array<string,array{title:string,subject:string,content:string}>
 */
function npmp_scheduled_emails_builtin_bodies() {
	$p = static function ( $html ) {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->' . "\n\n";
	};
	return array(
		'next_week' => array(
			'title'   => __( 'Coming up next week', 'nonprofit-manager' ),
			'subject' => __( 'Next week: [event_title]', 'nonprofit-manager' ),
			'content' => $p( __( 'Hi there,', 'nonprofit-manager' ) )
				. $p( __( '[event_title] is coming up next week, on [event_date] at [event_time]. We\'d love to have you join us.', 'nonprofit-manager' ) )
				. $p( '<strong>' . __( 'Where:', 'nonprofit-manager' ) . '</strong> [event_location]' )
				. $p( sprintf( /* translators: 1: link to the event page, 2: link to the calendar file. */ __( 'You can %1$s and %2$s so it doesn\'t sneak up on you.', 'nonprofit-manager' ), '<a href="[event_url]">' . __( 'read the details', 'nonprofit-manager' ) . '</a>', '<a href="[ical_link]">' . __( 'add it to your calendar', 'nonprofit-manager' ) . '</a>' ) ),
		),
		'tomorrow'  => array(
			'title'   => __( 'Tomorrow', 'nonprofit-manager' ),
			'subject' => __( 'Tomorrow: [event_title]', 'nonprofit-manager' ),
			'content' => $p( __( 'Hi there,', 'nonprofit-manager' ) )
				. $p( __( 'A quick reminder that [event_title] is tomorrow, [event_date], at [event_time].', 'nonprofit-manager' ) )
				. $p( '<strong>' . __( 'Where:', 'nonprofit-manager' ) . '</strong> [event_location]' )
				. $p( sprintf( /* translators: 1: link to the event, 2: link to the calendar file. */ __( 'Here\'s %1$s, and you can %2$s if you haven\'t yet.', 'nonprofit-manager' ), '<a href="[join_link]">' . __( 'the link to join', 'nonprofit-manager' ) . '</a>', '<a href="[ical_link]">' . __( 'add it to your calendar', 'nonprofit-manager' ) . '</a>' ) )
				. $p( __( 'See you tomorrow.', 'nonprofit-manager' ) ),
		),
	);
}

/**
 * Seed the two built-in bodies once. Deleting one later doesn't bring it
 * back: body 0 then falls back to the built-in "Tomorrow" text.
 *
 * @return void
 */
function npmp_scheduled_emails_seed_bodies() {
	if ( false !== get_option( 'npmp_scheduled_email_bodies' ) ) {
		return;
	}
	// Claim the option first so two requests can't both seed.
	if ( ! add_option( 'npmp_scheduled_email_bodies', array(), '', true ) ) {
		return;
	}
	$ids = array();
	foreach ( npmp_scheduled_emails_builtin_bodies() as $key => $body ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'npmp_email_body',
					'post_status'  => 'publish',
					'post_title'   => $body['title'],
					'post_content' => $body['content'],
				)
			),
			true
		);
		if ( ! is_wp_error( $id ) && $id ) {
			update_post_meta( $id, '_npmp_body_subject', $body['subject'] );
			$ids[ $key ] = (int) $id;
		}
	}
	update_option( 'npmp_scheduled_email_bodies', $ids );
}
add_action( 'init', 'npmp_scheduled_emails_seed_bodies', 20 );

/**
 * Published bodies for the pickers.
 *
 * @return WP_Post[]
 */
function npmp_scheduled_emails_bodies() {
	return get_posts(
		array(
			'post_type'      => 'npmp_email_body',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
}

/**
 * A body's content, subject and template. Body 0, or a body that's gone,
 * means the built-in "Tomorrow".
 *
 * @param int $body_id Body ID.
 * @return array{id:int,title:string,content:string,subject:string,template:string}
 */
function npmp_scheduled_emails_get_body( $body_id ) {
	$body_id = (int) $body_id;
	if ( ! $body_id ) {
		$seeded  = get_option( 'npmp_scheduled_email_bodies', array() );
		$body_id = is_array( $seeded ) && ! empty( $seeded['tomorrow'] ) ? (int) $seeded['tomorrow'] : 0;
	}
	$post = $body_id ? get_post( $body_id ) : null;
	if ( $post && 'npmp_email_body' === $post->post_type && 'trash' !== $post->post_status ) {
		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'content'  => (string) $post->post_content,
			'subject'  => (string) get_post_meta( $post->ID, '_npmp_body_subject', true ),
			'template' => (string) get_post_meta( $post->ID, '_npmp_newsletter_template', true ),
		);
	}
	$builtin = npmp_scheduled_emails_builtin_bodies()['tomorrow'];
	return array(
		'id'       => 0,
		'title'    => $builtin['title'],
		'content'  => $builtin['content'],
		'subject'  => $builtin['subject'],
		'template' => '',
	);
}

/**
 * The subject for a row: its own, then the body's, then the event title.
 *
 * @param string $own  Row or form subject.
 * @param array  $body Body from npmp_scheduled_emails_get_body().
 * @return string Subject with placeholders still in it.
 */
function npmp_scheduled_emails_pick_subject( $own, $body ) {
	if ( '' !== trim( (string) $own ) ) {
		return (string) $own;
	}
	if ( '' !== trim( (string) $body['subject'] ) ) {
		return (string) $body['subject'];
	}
	return '[event_title]';
}

/**
 * Subject box on the body editor, with the placeholder list.
 *
 * @return void
 */
function npmp_scheduled_emails_body_meta_box() {
	add_meta_box( 'npmp-email-body-subject', __( 'Subject and placeholders', 'nonprofit-manager' ), 'npmp_scheduled_emails_render_body_box', 'npmp_email_body', 'side', 'high' );
}
add_action( 'add_meta_boxes_npmp_email_body', 'npmp_scheduled_emails_body_meta_box' );

/**
 * Render the body's subject box.
 *
 * @param WP_Post $post Body.
 * @return void
 */
function npmp_scheduled_emails_render_body_box( $post ) {
	wp_nonce_field( 'npmp_save_email_body_' . $post->ID, 'npmp_email_body_nonce' );
	$subject = (string) get_post_meta( $post->ID, '_npmp_body_subject', true );
	echo '<p><label for="npmp-body-subject"><strong>' . esc_html__( 'Subject', 'nonprofit-manager' ) . '</strong></label>';
	echo '<input type="text" class="widefat" id="npmp-body-subject" name="npmp_body_subject" value="' . esc_attr( $subject ) . '" placeholder="[event_title]"></p>';
	echo '<p class="description">' . esc_html__( 'Publish this body to make it available on events. Publishing doesn\'t email anyone.', 'nonprofit-manager' ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Placeholders', 'nonprofit-manager' ) . '</strong></p><ul class="npmp-sched-placeholders">';
	foreach ( npmp_scheduled_emails_placeholder_help() as $tag => $help ) {
		echo '<li><code>' . esc_html( $tag ) . '</code> ' . esc_html( $help ) . '</li>';
	}
	echo '</ul>';
}

/**
 * Save the body's subject.
 *
 * @param int $post_id Body ID.
 * @return void
 */
function npmp_scheduled_emails_save_body_box( $post_id ) {
	if ( ! isset( $_POST['npmp_email_body_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_email_body_nonce'] ) ), 'npmp_save_email_body_' . $post_id ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$subject = isset( $_POST['npmp_body_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_body_subject'] ) ) : '';
	update_post_meta( $post_id, '_npmp_body_subject', substr( $subject, 0, 255 ) );
}
add_action( 'save_post_npmp_email_body', 'npmp_scheduled_emails_save_body_box' );

/* =========================================================================
 * Building and sending
 * ====================================================================== */

/**
 * Turn a due event row into a normal newsletter, ready to queue.
 *
 * @param object $row      Scheduled email row.
 * @param int    $event_id Event ID.
 * @param string $prefix   Optional subject prefix ("Update: ").
 * @return int Newsletter ID, 0 on failure.
 */
function npmp_scheduled_emails_build_newsletter( $row, $event_id, $prefix = '' ) {
	$body    = npmp_scheduled_emails_get_body( (int) $row->body_id );
	$map     = npmp_scheduled_emails_placeholders( $event_id, $row );
	$subject = npmp_scheduled_emails_fill( npmp_scheduled_emails_pick_subject( $row->subject, $body ), $map, false );
	$content = npmp_scheduled_emails_fill( $body['content'], $map, true );

	// Cron runs with no user, so kses would strip markup an editor already
	// approved when they saved the body. Placeholder values are escaped above.
	$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $kses ) {
		kses_remove_filters();
	}
	$newsletter_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'npmp_newsletter',
				'post_status'  => 'publish',
				'post_title'   => $prefix . $subject,
				'post_content' => $content,
			)
		),
		true
	);
	if ( $kses ) {
		kses_init_filters();
	}
	if ( is_wp_error( $newsletter_id ) || ! $newsletter_id ) {
		return 0;
	}

	if ( 'group' === $row->audience_type ) {
		update_post_meta( $newsletter_id, '_npmp_newsletter_groups', array( (int) $row->audience_id ) );
	} else {
		update_post_meta( $newsletter_id, '_npmp_newsletter_levels', array( '__all__' ) );
	}
	if ( '' !== $body['template'] ) {
		update_post_meta( $newsletter_id, '_npmp_newsletter_template', $body['template'] );
	}
	update_post_meta( $newsletter_id, '_npmp_newsletter_scheduled_row', (int) $row->id );
	update_post_meta( $newsletter_id, '_npmp_newsletter_scheduled_event', (int) $event_id );

	return (int) $newsletter_id;
}

/**
 * The finished HTML of an event email, for "Send a test to me". It goes
 * through the same content filters and template wrapper as a newsletter.
 *
 * @param int    $event_id Event ID.
 * @param int    $body_id  Body ID.
 * @param string $subject  Subject override.
 * @return array{subject:string,html:string}
 */
function npmp_scheduled_emails_render_test( $event_id, $body_id, $subject ) {
	$body = npmp_scheduled_emails_get_body( $body_id );
	$map  = npmp_scheduled_emails_placeholders( $event_id );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, as NPMP_Newsletter_Manager::get_newsletter_content() uses.
	$html = apply_filters( 'the_content', npmp_scheduled_emails_fill( $body['content'], $map, true ) );
	if ( function_exists( 'npmp_apply_newsletter_template' ) && 'none' !== $body['template'] ) {
		$html = is_numeric( $body['template'] ) ? npmp_apply_newsletter_template( $html, absint( $body['template'] ) ) : npmp_apply_newsletter_template( $html );
	}
	$html .= do_shortcode( '[npmp_can_spam]' );
	return array(
		'subject' => npmp_scheduled_emails_fill( npmp_scheduled_emails_pick_subject( $subject, $body ), $map, false ),
		'html'    => $html,
	);
}

/**
 * Claim one due row. Only the request whose UPDATE changed exactly one row
 * goes on to send, so overlapping sweeps can't both send it.
 *
 * @param int $id Row ID.
 * @return bool
 */
function npmp_scheduled_emails_claim( $id ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on a dedicated table.
	$changed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() ),
			$id
		)
	);
	return 1 === (int) $changed;
}

/**
 * Put a claimed row back to pending (the audience can't be mailed yet).
 *
 * @param int    $id   Row ID.
 * @param string $note Why it waits.
 * @return void
 */
function npmp_scheduled_emails_release( $id, $note = '' ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', note = %s, updated_at = %s WHERE id = %d AND status = 'sending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			substr( (string) $note, 0, 255 ),
			gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() ),
			$id
		)
	);
}

/**
 * The sweep: send every due row, a few per run.
 *
 * @return int Rows sent this run.
 */
function npmp_scheduled_emails_sweep() {
	global $wpdb;
	if ( ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
		return 0;
	}
	$table = npmp_scheduled_emails_table();
	$now   = npmp_scheduled_emails_now();

	npmp_scheduled_emails_recover_stuck( $now );

	$per_run   = (int) apply_filters( 'npmp_scheduled_emails_per_run', 10 );
	$now_sql   = gmdate( 'Y-m-d H:i:s', $now );
	$after_at  = '';
	$after_id  = 0;
	$looked_at = 0;
	$sent      = 0;

	// Rows that can't go yet (a group email while Pro is off) go back to
	// pending and stay due. Page past them in send-time order, so 50 of them
	// can't hold back every email behind them.
	do {
		if ( '' === $after_at ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table, read fresh by a per-minute cron.
			$due = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' AND send_at <= %s ORDER BY send_at ASC, id ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
					$now_sql
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table, read fresh by a per-minute cron.
			$due = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' AND send_at <= %s AND ( send_at > %s OR ( send_at = %s AND id > %d ) ) ORDER BY send_at ASC, id ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
					$now_sql,
					$after_at,
					$after_at,
					$after_id
				)
			);
		}
		$due = (array) $due;

		foreach ( $due as $row ) {
			$after_at = (string) $row->send_at;
			$after_id = (int) $row->id;
			++$looked_at;
			if ( $sent >= $per_run ) {
				break 2;
			}
			if ( ! npmp_scheduled_emails_claim( $row->id ) ) {
				continue;
			}
			if ( 'sent' === npmp_scheduled_emails_process_row( $row, $now ) ) {
				++$sent;
			}
		}
	} while ( 50 === count( $due ) && $looked_at < 500 );

	return $sent;
}
add_action( 'npmp_scheduled_emails_sweep', 'npmp_scheduled_emails_sweep' );

/**
 * Keep the sweep on the per-minute schedule the newsletter queue uses.
 *
 * @return void
 */
function npmp_scheduled_emails_schedule_sweep() {
	if ( ! wp_next_scheduled( 'npmp_scheduled_emails_sweep' ) ) {
		wp_schedule_event( time() + 60, 'every_minute', 'npmp_scheduled_emails_sweep' );
	}
}
add_action( 'init', 'npmp_scheduled_emails_schedule_sweep' );

/**
 * A row left in 'sending' for 15 minutes means a run died partway. With no
 * newsletter made yet, nothing went out, so it goes back to pending. With a
 * newsletter that was queued, it counts as sent. A newsletter that was made
 * but never queued gets queued now.
 *
 * @param int $now Unix time.
 * @return void
 */
function npmp_scheduled_emails_recover_stuck( $now ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated table.
	$stuck = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'sending' AND updated_at <= %s LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
			gmdate( 'Y-m-d H:i:s', $now - 15 * 60 )
		)
	);
	foreach ( (array) $stuck as $row ) {
		if ( ! (int) $row->newsletter_id ) {
			npmp_scheduled_emails_release( $row->id );
			continue;
		}
		if ( '' === (string) get_post_meta( $row->newsletter_id, '_npmp_newsletter_status', true ) ) {
			// A run that died partway through queue_newsletter() may have
			// left rows the mail queue already sent from. Queuing again
			// deletes and re-adds them, which mails those members twice, so
			// count what's there and let the mail queue finish it.
			$queue_table = $wpdb->prefix . 'npmp_newsletter_queue';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dedicated queue table, fixed name.
			$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE newsletter_id = %d", $row->newsletter_id ) );
			if ( $queued ) {
				$count = $queued;
				update_post_meta( $row->newsletter_id, '_npmp_newsletter_status', 'queued' );
				update_post_meta( $row->newsletter_id, '_npmp_newsletter_recipient_total', $queued );
			} else {
				$count = NPMP_Newsletter_Manager::queue_newsletter( $row->newsletter_id );
			}
		} else {
			$count = (int) get_post_meta( $row->newsletter_id, '_npmp_newsletter_recipient_total', true );
		}
		npmp_scheduled_emails_update_row(
			$row->id,
			array(
				'status'          => 'sent',
				'recipient_count' => (int) $count,
			)
		);
	}
}

/**
 * Send one claimed row.
 *
 * @param object $row Row as claimed (status was pending).
 * @param int    $now Unix time.
 * @return string Final status.
 */
function npmp_scheduled_emails_process_row( $row, $now ) {
	if ( 'list' === $row->target_type ) {
		return npmp_scheduled_emails_process_list_row( $row, $now );
	}

	$event_id = (int) $row->target_id;
	$post     = get_post( $event_id );
	if ( ! post_type_exists( 'npmp_event' ) ) {
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'cancelled', 'note' => __( 'The calendar module is off.', 'nonprofit-manager' ) ) );
		return 'cancelled';
	}
	if ( ! $post || 'npmp_event' !== $post->post_type || 'publish' !== $post->post_status || npmp_scheduled_emails_event_is_cancelled( $event_id ) ) {
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'cancelled', 'note' => __( 'The event is no longer live on the site.', 'nonprofit-manager' ) ) );
		return 'cancelled';
	}

	$details  = npmp_scheduled_emails_event_details( $event_id );
	$start_ts = $details['start'] ? npmp_scheduled_emails_local_to_ts( $details['start'] ) : 0;
	$block    = $start_ts ? npmp_scheduled_emails_window_block( $start_ts, $now ) : 'started';
	if ( 'started' === $block ) {
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'skipped', 'note' => __( 'The event had already started, so this email didn\'t go out.', 'nonprofit-manager' ) ) );
		return 'skipped';
	}
	if ( 'last_hour' === $block ) {
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'skipped', 'note' => __( 'The event was less than an hour away, so this email didn\'t go out.', 'nonprofit-manager' ) ) );
		return 'skipped';
	}

	if ( ! apply_filters( 'npmp_scheduled_email_can_send', 'all' === $row->audience_type, $row ) ) {
		npmp_scheduled_emails_release( $row->id, 'all' === $row->audience_type ? '' : __( 'Sending to a group takes Nonprofit Manager Pro.', 'nonprofit-manager' ) );
		return 'pending';
	}

	$key     = npmp_scheduled_emails_row_key( $row->rule_key, $row->audience_type, $row->audience_id );
	$updates = get_post_meta( $event_id, '_npmp_sched_email_updates', true );
	$updates = is_array( $updates ) ? $updates : array();
	$prefix  = in_array( $key, $updates, true ) ? __( 'Update: ', 'nonprofit-manager' ) : '';

	$newsletter_id = npmp_scheduled_emails_build_newsletter( $row, $event_id, $prefix );
	if ( ! $newsletter_id ) {
		npmp_scheduled_emails_release( $row->id, __( 'WordPress couldn\'t create the newsletter. The next run tries again.', 'nonprofit-manager' ) );
		return 'pending';
	}
	npmp_scheduled_emails_update_row( $row->id, array( 'newsletter_id' => $newsletter_id ) );

	do_action( 'npmp_scheduled_email_newsletter_created', $newsletter_id, $row );
	$count = NPMP_Newsletter_Manager::queue_newsletter( $newsletter_id );

	if ( $prefix ) {
		update_post_meta( $event_id, '_npmp_sched_email_updates', array_values( array_diff( $updates, array( $key ) ) ) );
	}
	npmp_scheduled_emails_update_row(
		$row->id,
		array(
			'status'          => 'sent',
			'recipient_count' => (int) $count,
			'note'            => $count ? '' : __( 'No subscribed members matched.', 'nonprofit-manager' ),
		)
	);
	npmp_scheduled_emails_note_lateness( $row, $now );
	return 'sent';
}

/**
 * Send a "Send later" newsletter.
 *
 * @param object $row Row as claimed.
 * @param int    $now Unix time.
 * @return string Final status.
 */
function npmp_scheduled_emails_process_list_row( $row, $now ) {
	$newsletter_id = (int) $row->target_id;
	$post          = get_post( $newsletter_id );
	if ( ! $post || 'npmp_newsletter' !== $post->post_type || 'trash' === $post->post_status ) {
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'cancelled', 'note' => __( 'The newsletter is gone or in the trash.', 'nonprofit-manager' ) ) );
		return 'cancelled';
	}
	if ( ! apply_filters( 'npmp_scheduled_email_can_send', 'all' === $row->audience_type, $row ) ) {
		npmp_scheduled_emails_release( $row->id, 'all' === $row->audience_type ? '' : __( 'Sending to a group takes Nonprofit Manager Pro.', 'nonprofit-manager' ) );
		return 'pending';
	}
	npmp_scheduled_emails_update_row( $row->id, array( 'newsletter_id' => $newsletter_id ) );
	do_action( 'npmp_scheduled_email_newsletter_created', $newsletter_id, $row );
	$count = NPMP_Newsletter_Manager::queue_newsletter( $newsletter_id );
	npmp_scheduled_emails_update_row(
		$row->id,
		array(
			'status'          => 'sent',
			'recipient_count' => (int) $count,
			'note'            => $count ? '' : __( 'No subscribed members matched.', 'nonprofit-manager' ),
		)
	);
	npmp_scheduled_emails_note_lateness( $row, $now );
	return 'sent';
}

/**
 * Remember an email that went out more than 30 minutes late, for the Site
 * Health test and the admin notice.
 *
 * @param object $row Row.
 * @param int    $now Unix time it went out.
 * @return void
 */
function npmp_scheduled_emails_note_lateness( $row, $now ) {
	$late = $now - npmp_scheduled_emails_utc_to_ts( $row->send_at );
	if ( $late <= 30 * 60 ) {
		return;
	}
	update_option(
		'npmp_scheduled_email_late',
		array(
			'at'      => (int) $now,
			'minutes' => (int) round( $late / 60 ),
			'row'     => (int) $row->id,
		),
		false
	);
}

/**
 * The last late send, when it happened within $days days.
 *
 * @param int $days Look-back window.
 * @return array|null
 */
function npmp_scheduled_emails_recent_lateness( $days = 30 ) {
	$late = get_option( 'npmp_scheduled_email_late' );
	if ( ! is_array( $late ) || empty( $late['at'] ) ) {
		return null;
	}
	return ( npmp_scheduled_emails_now() - (int) $late['at'] ) <= $days * DAY_IN_SECONDS ? $late : null;
}

/**
 * The system-cron fix, in one sentence.
 *
 * @return string
 */
function npmp_scheduled_emails_cron_fix_text() {
	return sprintf(
		/* translators: 1: the wp-config.php line, 2: the wp-cron.php URL. */
		__( 'WordPress only runs its scheduler when someone visits the site, so quiet hours delay emails. You can fix it by adding %1$s to wp-config.php and asking your host to load %2$s every minute from a real cron job.', 'nonprofit-manager' ),
		"define( 'DISABLE_WP_CRON', true );",
		site_url( 'wp-cron.php' )
	);
}

/* =========================================================================
 * Site Health and notices
 * ====================================================================== */

/**
 * Register the Site Health test.
 *
 * @param array $tests Tests.
 * @return array
 */
function npmp_scheduled_emails_health_tests( $tests ) {
	$tests['direct']['npmp_scheduled_email_timing'] = array(
		'label' => __( 'Nonprofit Manager scheduled emails go out on time', 'nonprofit-manager' ),
		'test'  => 'npmp_scheduled_emails_health_test',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'npmp_scheduled_emails_health_tests' );

/**
 * Site Health test body.
 *
 * @return array
 */
function npmp_scheduled_emails_health_test() {
	$result = array(
		'label'       => __( 'Scheduled emails go out on time', 'nonprofit-manager' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Nonprofit Manager', 'nonprofit-manager' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'No scheduled email went out more than 30 minutes late in the last 30 days.', 'nonprofit-manager' ) . '</p>',
		'test'        => 'npmp_scheduled_email_timing',
	);
	$late = npmp_scheduled_emails_recent_lateness( 30 );
	if ( $late ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'A scheduled email went out late', 'nonprofit-manager' );
		$result['description'] = '<p>' . esc_html(
			sprintf(
				/* translators: %d: minutes late. */
				__( 'A scheduled email went out %d minutes after its send time.', 'nonprofit-manager' ),
				(int) $late['minutes']
			)
		) . '</p><p>' . esc_html( npmp_scheduled_emails_cron_fix_text() ) . '</p>';
	}
	return $result;
}

/**
 * Late-send notice for admins, until they dismiss it.
 *
 * @return void
 */
function npmp_scheduled_emails_late_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$late = npmp_scheduled_emails_recent_lateness( 14 );
	if ( ! $late || (int) get_option( 'npmp_scheduled_email_late_dismissed', 0 ) >= (int) $late['at'] ) {
		return;
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=npmp_dismiss_scheduled_late' ), 'npmp_dismiss_scheduled_late' );
	echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Nonprofit Manager:', 'nonprofit-manager' ) . '</strong> ';
	echo esc_html(
		sprintf(
			/* translators: %d: minutes late. */
			__( 'A scheduled email went out %d minutes late.', 'nonprofit-manager' ),
			(int) $late['minutes']
		)
	) . ' ' . esc_html( npmp_scheduled_emails_cron_fix_text() );
	echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'nonprofit-manager' ) . '</a></p></div>';
}
add_action( 'admin_notices', 'npmp_scheduled_emails_late_notice' );

/**
 * Dismiss the late-send notice.
 *
 * @return void
 */
function npmp_scheduled_emails_dismiss_late() {
	check_admin_referer( 'npmp_dismiss_scheduled_late' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	update_option( 'npmp_scheduled_email_late_dismissed', time(), false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_npmp_dismiss_scheduled_late', 'npmp_scheduled_emails_dismiss_late' );

/**
 * One-line prompt on the Events list while event emails are off.
 *
 * @return void
 */
function npmp_scheduled_emails_events_prompt() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-npmp_event' !== $screen->id || ! current_user_can( npmp_scheduled_emails_send_cap() ) ) {
		return;
	}
	if ( npmp_scheduled_email_defaults()['enabled'] || get_user_meta( get_current_user_id(), 'npmp_sched_prompt_dismissed', true ) ) {
		return;
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=npmp_dismiss_sched_prompt' ), 'npmp_dismiss_sched_prompt' );
	echo '<div class="notice notice-info"><p>';
	echo esc_html__( 'You can email your members before each event, a day ahead by default.', 'nonprofit-manager' ) . ' ';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=npmp_scheduled_emails' ) ) . '">' . esc_html__( 'Turn on event emails', 'nonprofit-manager' ) . '</a> &middot; ';
	echo '<a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Not now', 'nonprofit-manager' ) . '</a></p></div>';
}
add_action( 'admin_notices', 'npmp_scheduled_emails_events_prompt' );

/**
 * Hide the Events list prompt for this user.
 *
 * @return void
 */
function npmp_scheduled_emails_dismiss_prompt() {
	check_admin_referer( 'npmp_dismiss_sched_prompt' );
	update_user_meta( get_current_user_id(), 'npmp_sched_prompt_dismissed', 1 );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=npmp_event' ) );
	exit;
}
add_action( 'admin_post_npmp_dismiss_sched_prompt', 'npmp_scheduled_emails_dismiss_prompt' );

/* =========================================================================
 * Shared admin bits
 * ====================================================================== */

/**
 * The capability that sends email to members: the newsletter send
 * capability, so a Contributor who can write a newsletter can't schedule
 * one to the whole list.
 *
 * @return string
 */
function npmp_scheduled_emails_send_cap() {
	return function_exists( 'npmp_newsletter_send_capability' ) ? npmp_newsletter_send_capability() : 'edit_others_npmp_newsletters';
}

/**
 * A stored UTC time shown in the site timezone.
 *
 * @param string $utc "Y-m-d H:i:s" UTC.
 * @return string
 */
function npmp_scheduled_emails_format_utc( $utc ) {
	$ts = npmp_scheduled_emails_utc_to_ts( $utc );
	if ( ! $ts ) {
		return '';
	}
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
}

/**
 * Words for a row's status.
 *
 * @param object $row Row.
 * @return string
 */
function npmp_scheduled_emails_status_label( $row ) {
	switch ( $row->status ) {
		case 'pending':
			return __( 'Scheduled', 'nonprofit-manager' );
		case 'sending':
			return __( 'Sending', 'nonprofit-manager' );
		case 'sent':
			/* translators: %s: number of recipients. */
			return sprintf( _n( 'Sent to %s member', 'Sent to %s members', (int) $row->recipient_count, 'nonprofit-manager' ), number_format_i18n( (int) $row->recipient_count ) );
		case 'skipped':
			return __( 'Skipped', 'nonprofit-manager' );
		case 'cancelled':
			return __( 'Cancelled', 'nonprofit-manager' );
	}
	return (string) $row->status;
}

/**
 * Words for a row's audience.
 *
 * @param object $row Row.
 * @return string
 */
function npmp_scheduled_emails_audience_label( $row ) {
	if ( 'list' === $row->target_type && function_exists( 'npmp_newsletter_get_audience_label' ) ) {
		$label = npmp_newsletter_get_audience_label( $row->target_id );
	} elseif ( 'group' === $row->audience_type ) {
		/* translators: %d: group ID. */
		$label = sprintf( __( 'Group %d', 'nonprofit-manager' ), (int) $row->audience_id );
	} else {
		$label = __( 'All subscribed members', 'nonprofit-manager' );
	}
	return (string) apply_filters( 'npmp_scheduled_email_audience_label', $label, $row );
}

/**
 * Timing select plus custom hours, shared by both forms.
 *
 * @param string $name        Field name prefix (npmp_sched_email or npmp_sched_defaults).
 * @param int    $minutes     Current minutes, 0 for "use the site default".
 * @param bool   $use_default Offer the "use the site default" choice.
 * @param int    $default     The site default, for its label.
 * @return void
 */
function npmp_scheduled_emails_timing_fields( $name, $minutes, $use_default, $default = 1440 ) {
	$choices = npmp_scheduled_emails_timing_choices();
	$current = $minutes && ! isset( $choices[ $minutes ] ) ? 'custom' : (string) $minutes;
	$hours   = 'custom' === $current ? max( 1, (int) round( $minutes / 60 ) ) : 24;
	echo '<select name="' . esc_attr( $name ) . '[timing]" class="npmp-sched-timing">';
	if ( $use_default ) {
		/* translators: %s: the site default, such as "1 day before". */
		echo '<option value=""' . selected( $current, '0', false ) . '>' . esc_html( sprintf( __( 'Site default (%s)', 'nonprofit-manager' ), npmp_scheduled_emails_describe_minutes( $default ) ) ) . '</option>';
	}
	foreach ( $choices as $value => $label ) {
		echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '<option value="custom"' . selected( $current, 'custom', false ) . '>' . esc_html__( 'Custom', 'nonprofit-manager' ) . '</option>';
	echo '</select> <span class="npmp-sched-custom"><input type="number" min="1" max="1440" step="1" name="' . esc_attr( $name ) . '[custom_hours]" value="' . esc_attr( (string) $hours ) . '" class="small-text"> ' . esc_html__( 'hours before', 'nonprofit-manager' ) . '</span>';
}

/**
 * Read the timing fields back into minutes. 0 means "use the site default".
 *
 * @param array $in Posted array.
 * @return int
 */
function npmp_scheduled_emails_read_timing( $in ) {
	$timing = isset( $in['timing'] ) ? sanitize_key( (string) $in['timing'] ) : '';
	if ( 'custom' === $timing ) {
		$hours = isset( $in['custom_hours'] ) ? absint( $in['custom_hours'] ) : 0;
		return $hours ? npmp_scheduled_emails_clamp_minutes( min( 1440, $hours ) * 60 ) : 0;
	}
	return '' === $timing ? 0 : npmp_scheduled_emails_clamp_minutes( absint( $timing ) );
}

/**
 * Body select, shared by both forms.
 *
 * @param string     $name        Field name.
 * @param int|string $current     Selected body ID, '' for the site default.
 * @param bool       $use_default Offer "use the site default".
 * @return void
 */
function npmp_scheduled_emails_body_select( $name, $current, $use_default ) {
	echo '<select name="' . esc_attr( $name ) . '" class="npmp-sched-body">';
	if ( $use_default ) {
		echo '<option value=""' . selected( (string) $current, '', false ) . '>' . esc_html__( 'Site default', 'nonprofit-manager' ) . '</option>';
	}
	$bodies = npmp_scheduled_emails_bodies();
	$seeded = get_option( 'npmp_scheduled_email_bodies', array() );
	if ( empty( $seeded['tomorrow'] ) || ! get_post( (int) $seeded['tomorrow'] ) ) {
		echo '<option value="0"' . selected( (string) $current, '0', false ) . '>' . esc_html__( 'Tomorrow (built in)', 'nonprofit-manager' ) . '</option>';
	}
	foreach ( $bodies as $body ) {
		$selected = (string) $current === (string) $body->ID || ( '0' === (string) $current && ! empty( $seeded['tomorrow'] ) && (int) $seeded['tomorrow'] === (int) $body->ID );
		echo '<option value="' . esc_attr( (string) $body->ID ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $body->post_title ) . '</option>';
	}
	echo '</select>';
}

/**
 * The greyed "Add another scheduled email" control on free sites.
 *
 * @return void
 */
function npmp_scheduled_emails_pro_teaser() {
	if ( npmp_is_pro() ) {
		return;
	}
	echo '<p class="npmp-sched-pro"><button type="button" class="button" disabled aria-disabled="true">' . esc_html__( 'Add another scheduled email', 'nonprofit-manager' ) . '</button> <span class="npmp-pro-badge">' . esc_html__( 'Pro', 'nonprofit-manager' ) . '</span> ';
	echo '<a href="' . esc_url( npmp_get_upgrade_url( 'scheduled_emails' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Send a week-ahead email and a reminder with Pro', 'nonprofit-manager' ) . '</a></p>';
}

/**
 * "Scheduled" label for newsletters this file generated. The archive and
 * Reports print it after the title.
 *
 * @param int $newsletter_id Newsletter ID.
 * @return string Escaped HTML, or ''.
 */
function npmp_newsletter_scheduled_badge( $newsletter_id ) {
	if ( ! get_post_meta( $newsletter_id, '_npmp_newsletter_scheduled_row', true ) ) {
		return '';
	}
	return ' <span class="npmp-scheduled-badge" style="display:inline-block;margin-left:6px;padding:1px 6px;border-radius:3px;border:1px solid #a7f3d0;background:#ecfdf5;color:#0f766e;font-size:11px;line-height:1.6;">' . esc_html__( 'Scheduled', 'nonprofit-manager' ) . '</span>';
}

/* =========================================================================
 * Event screen
 * ====================================================================== */

/**
 * Add the Scheduled Email box to events, unless Pro drew its own.
 *
 * @param WP_Post $post Event.
 * @return void
 */
function npmp_scheduled_emails_add_event_box( $post ) {
	if ( apply_filters( 'npmp_event_emails_box_handled', false, $post ) ) {
		return;
	}
	add_meta_box( 'npmp-event-scheduled-email', __( 'Scheduled Email', 'nonprofit-manager' ), 'npmp_scheduled_emails_render_event_box', 'npmp_event', 'side', 'default' );
}
add_action( 'add_meta_boxes_npmp_event', 'npmp_scheduled_emails_add_event_box', 20 );

/**
 * The row free's box shows: the 'default' rule to everyone.
 *
 * @param int $event_id Event ID.
 * @return object|null
 */
function npmp_scheduled_emails_event_default_row( $event_id ) {
	foreach ( npmp_scheduled_emails_get_rows( 'event', $event_id ) as $row ) {
		if ( 'default' === $row->rule_key && 'all' === $row->audience_type ) {
			return $row;
		}
	}
	return null;
}

/**
 * One line about a row: when it goes, and its status.
 *
 * @param object|null $row Row.
 * @return string Plain text.
 */
function npmp_scheduled_emails_row_summary( $row ) {
	if ( ! $row ) {
		return __( 'Nothing scheduled yet. Save the event to schedule its email.', 'nonprofit-manager' );
	}
	$text = sprintf(
		/* translators: 1: send date and time, 2: timezone, 3: status. */
		__( '%1$s (%2$s). %3$s.', 'nonprofit-manager' ),
		npmp_scheduled_emails_format_utc( $row->send_at ),
		wp_timezone_string(),
		npmp_scheduled_emails_status_label( $row )
	);
	if ( '' !== (string) $row->note ) {
		$text .= ' ' . $row->note;
	}
	return $text;
}

/**
 * Render the event's Scheduled Email box.
 *
 * @param WP_Post $post Event.
 * @return void
 */
function npmp_scheduled_emails_render_event_box( $post ) {
	$defaults = npmp_scheduled_email_defaults();
	$own      = npmp_scheduled_emails_event_settings( $post->ID );
	$row      = npmp_scheduled_emails_event_default_row( $post->ID );
	$key      = npmp_scheduled_emails_row_key( 'default', 'all', 0 );
	$skipped  = $row && 'skipped' === $row->status && in_array( $key, npmp_scheduled_emails_admin_skips( $post->ID ), true );
	$can_send = current_user_can( npmp_scheduled_emails_send_cap() );

	wp_nonce_field( 'npmp_sched_email_box_' . $post->ID, 'npmp_sched_email_box_nonce' );

	if ( ! $defaults['enabled'] && 'default' === $own['mode'] ) {
		echo '<p class="description">' . esc_html__( 'Event emails are off for the site. You can turn this one on below, or turn them on for every event under Scheduled Emails.', 'nonprofit-manager' ) . '</p>';
	}

	echo '<p><label for="npmp-sched-mode"><strong>' . esc_html__( 'Email members before this event', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<select id="npmp-sched-mode" name="npmp_sched_email[mode]">';
	$modes = array(
		/* translators: %s: on or off. */
		'default' => sprintf( __( 'Site default (%s)', 'nonprofit-manager' ), $defaults['enabled'] ? __( 'on', 'nonprofit-manager' ) : __( 'off', 'nonprofit-manager' ) ),
		'on'      => __( 'On', 'nonprofit-manager' ),
		'off'     => __( 'Off', 'nonprofit-manager' ),
	);
	foreach ( $modes as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $own['mode'], $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select></p>';

	echo '<p><strong>' . esc_html__( 'When', 'nonprofit-manager' ) . '</strong><br>';
	npmp_scheduled_emails_timing_fields( 'npmp_sched_email', $own['minutes_before'], true, $defaults['minutes_before'] );
	echo '</p>';

	echo '<p><strong>' . esc_html__( 'Body', 'nonprofit-manager' ) . '</strong><br>';
	npmp_scheduled_emails_body_select( 'npmp_sched_email[body_id]', $own['body_id'], true );
	echo '</p>';

	echo '<p><label for="npmp-sched-subject"><strong>' . esc_html__( 'Subject (optional)', 'nonprofit-manager' ) . '</strong></label>';
	echo '<input type="text" class="widefat" id="npmp-sched-subject" name="npmp_sched_email[subject]" value="' . esc_attr( $own['subject'] ) . '" placeholder="' . esc_attr__( 'Uses the body\'s subject', 'nonprofit-manager' ) . '"></p>';

	echo '<p class="npmp-sched-status"><strong>' . esc_html__( 'Send time', 'nonprofit-manager' ) . '</strong><br><span class="npmp-sched-summary">' . esc_html( npmp_scheduled_emails_row_summary( $row ) ) . '</span></p>';

	$nonce = wp_create_nonce( 'npmp_sched_email_' . $post->ID );
	echo '<p class="npmp-sched-actions" data-event="' . esc_attr( (string) $post->ID ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
	echo '<button type="button" class="button npmp-sched-action" data-op="test">' . esc_html__( 'Send a test to me', 'nonprofit-manager' ) . '</button> ';
	if ( $can_send && $row && 'pending' === $row->status ) {
		echo '<button type="button" class="button npmp-sched-action" data-op="skip">' . esc_html__( 'Skip this email', 'nonprofit-manager' ) . '</button> ';
	}
	if ( $can_send && $skipped ) {
		echo '<button type="button" class="button npmp-sched-action" data-op="unskip">' . esc_html__( 'Send it after all', 'nonprofit-manager' ) . '</button> ';
	}
	if ( $can_send && $row && 'sent' === $row->status ) {
		echo '<button type="button" class="button npmp-sched-action" data-op="update" data-confirm="' . esc_attr__( 'Email everyone this event\'s details again, marked as an update?', 'nonprofit-manager' ) . '">' . esc_html__( 'Send an update', 'nonprofit-manager' ) . '</button> ';
	}
	echo '</p><p class="npmp-sched-message" role="status" aria-live="polite"></p>';

	npmp_scheduled_emails_pro_teaser();
}

/**
 * Save the event's box. Runs inside the save_post handler, before the sync.
 *
 * @param int $post_id Event ID.
 * @return void
 */
function npmp_scheduled_emails_save_event_box( $post_id ) {
	if ( ! isset( $_POST['npmp_sched_email_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_sched_email_box_nonce'] ) ), 'npmp_sched_email_box_' . $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['npmp_sched_email'] ) && is_array( $_POST['npmp_sched_email'] ) ? map_deep( wp_unslash( $_POST['npmp_sched_email'] ), 'sanitize_text_field' ) : array();

	$mode = isset( $in['mode'] ) && in_array( $in['mode'], array( 'default', 'on', 'off' ), true ) ? $in['mode'] : 'default';
	// Only someone who can send to the list can switch an event's email on.
	if ( 'on' === $mode && ! current_user_can( npmp_scheduled_emails_send_cap() ) ) {
		$mode = npmp_scheduled_emails_event_settings( $post_id )['mode'];
	}
	$body = isset( $in['body_id'] ) && '' !== $in['body_id'] ? absint( $in['body_id'] ) : '';

	update_post_meta(
		$post_id,
		'_npmp_sched_email',
		array(
			'mode'           => $mode,
			'minutes_before' => npmp_scheduled_emails_read_timing( $in ),
			'body_id'        => $body,
			'subject'        => substr( (string) ( $in['subject'] ?? '' ), 0, 255 ),
		)
	);
}

/**
 * AJAX for the event box: test, skip, unskip, update.
 *
 * @return void
 */
function npmp_scheduled_emails_ajax_event() {
	$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
	$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	$op       = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

	if ( ! $event_id || 'npmp_event' !== get_post_type( $event_id ) || ! wp_verify_nonce( $nonce, 'npmp_sched_email_' . $event_id ) || ! current_user_can( 'edit_post', $event_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'nonprofit-manager' ) ) );
	}

	if ( 'test' === $op ) {
		$defaults = npmp_scheduled_email_defaults();
		$own      = npmp_scheduled_emails_event_settings( $event_id );
		$body_id  = isset( $_POST['body_id'] ) && '' !== $_POST['body_id'] ? absint( $_POST['body_id'] ) : ( '' !== $own['body_id'] ? (int) $own['body_id'] : (int) $defaults['body_id'] );
		$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$subject  = '' !== $subject ? $subject : ( '' !== $own['subject'] ? $own['subject'] : $defaults['subject'] );
		$email    = npmp_scheduled_emails_render_test( $event_id, $body_id, $subject );
		$user     = wp_get_current_user();
		/* translators: %s: email subject. */
		$sent = NPMP_Newsletter_Manager::send_email( $user->user_email, sprintf( __( '[Test] %s', 'nonprofit-manager' ), $email['subject'] ), $email['html'] );
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The test email didn\'t send. Check Email Settings.', 'nonprofit-manager' ) ) );
		}
		/* translators: %s: email address. */
		wp_send_json_success( array( 'message' => sprintf( __( 'Sent a test to %s.', 'nonprofit-manager' ), $user->user_email ) ) );
	}

	if ( ! current_user_can( npmp_scheduled_emails_send_cap() ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'nonprofit-manager' ) ) );
	}

	$row = npmp_scheduled_emails_event_default_row( $event_id );
	$key = npmp_scheduled_emails_row_key( 'default', 'all', 0 );
	if ( ! $row ) {
		wp_send_json_error( array( 'message' => __( 'Save the event first.', 'nonprofit-manager' ) ) );
	}
	$message = npmp_scheduled_emails_event_op( $event_id, $row, $key, $op );
	if ( is_wp_error( $message ) ) {
		wp_send_json_error( array( 'message' => $message->get_error_message() ) );
	}
	wp_send_json_success(
		array(
			'message' => $message,
			'summary' => npmp_scheduled_emails_row_summary( npmp_scheduled_emails_get_row( $row->id ) ),
		)
	);
}
add_action( 'wp_ajax_npmp_sched_email_event', 'npmp_scheduled_emails_ajax_event' );

/**
 * Skip, unskip or send an update for one event row. Pro's box can call
 * this for any of its rows.
 *
 * @param int    $event_id Event ID.
 * @param object $row      Row.
 * @param string $key      Row key (npmp_scheduled_emails_row_key()).
 * @param string $op       'skip', 'unskip' or 'update'.
 * @return string|WP_Error Message for the admin.
 */
function npmp_scheduled_emails_event_op( $event_id, $row, $key, $op ) {
	$skips = npmp_scheduled_emails_admin_skips( $event_id );
	if ( 'skip' === $op ) {
		if ( 'pending' !== $row->status ) {
			return new WP_Error( 'npmp_sched', __( 'You can only skip an email that hasn\'t gone out.', 'nonprofit-manager' ) );
		}
		update_post_meta( $event_id, '_npmp_sched_email_skips', array_values( array_unique( array_merge( $skips, array( $key ) ) ) ) );
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'skipped', 'note' => __( 'You skipped this email.', 'nonprofit-manager' ) ) );
		return __( 'Skipped. This email won\'t go out for this event.', 'nonprofit-manager' );
	}
	if ( 'unskip' === $op ) {
		update_post_meta( $event_id, '_npmp_sched_email_skips', array_values( array_diff( $skips, array( $key ) ) ) );
		npmp_scheduled_emails_update_row( $row->id, array( 'status' => 'cancelled' ) );
		npmp_scheduled_emails_sync_event( $event_id );
		return __( 'Back on the schedule.', 'nonprofit-manager' );
	}
	if ( 'update' === $op ) {
		if ( 'sent' !== $row->status ) {
			return new WP_Error( 'npmp_sched', __( 'This email hasn\'t gone out yet, so there\'s nothing to update.', 'nonprofit-manager' ) );
		}
		$details  = npmp_scheduled_emails_event_details( $event_id );
		$start_ts = $details['start'] ? npmp_scheduled_emails_local_to_ts( $details['start'] ) : 0;
		if ( ! $start_ts || '' !== npmp_scheduled_emails_window_block( $start_ts, npmp_scheduled_emails_now() ) ) {
			return new WP_Error( 'npmp_sched', __( 'The event has started or starts within the hour, so an update can\'t go out now.', 'nonprofit-manager' ) );
		}
		$updates = get_post_meta( $event_id, '_npmp_sched_email_updates', true );
		$updates = is_array( $updates ) ? $updates : array();
		update_post_meta( $event_id, '_npmp_sched_email_updates', array_values( array_unique( array_merge( $updates, array( $key ) ) ) ) );
		npmp_scheduled_emails_update_row(
			$row->id,
			array(
				'status'  => 'pending',
				'send_at' => gmdate( 'Y-m-d H:i:s', npmp_scheduled_emails_now() ),
				'note'    => __( 'You asked for an update. It goes out within a minute or two.', 'nonprofit-manager' ),
			)
		);
		return __( 'The update goes out within a minute or two.', 'nonprofit-manager' );
	}
	return new WP_Error( 'npmp_sched', __( 'Unknown action.', 'nonprofit-manager' ) );
}

/* =========================================================================
 * Newsletter "Send later"
 * ====================================================================== */

/**
 * The pending "Send later" row of a newsletter.
 *
 * @param int $newsletter_id Newsletter ID.
 * @return object|null
 */
function npmp_scheduled_emails_list_row( $newsletter_id ) {
	foreach ( npmp_scheduled_emails_get_rows( 'list', $newsletter_id ) as $row ) {
		if ( 'send_later' === $row->rule_key ) {
			return $row;
		}
	}
	return null;
}

/**
 * "Send later" controls, printed under the newsletter's Send button.
 *
 * @param WP_Post $post Newsletter.
 * @return void
 */
function npmp_scheduled_emails_render_send_later( $post ) {
	$row     = npmp_scheduled_emails_list_row( $post->ID );
	$pending = $row && 'pending' === $row->status;
	$value   = $pending ? wp_date( 'Y-m-d\TH:i', npmp_scheduled_emails_utc_to_ts( $row->send_at ) ) : '';
	$nonce   = wp_create_nonce( 'npmp_send_later_' . $post->ID );

	echo '<div class="npmp-send-later" data-postid="' . esc_attr( (string) $post->ID ) . '" data-nonce="' . esc_attr( $nonce ) . '">';
	echo '<p><label for="npmp-send-later-at"><strong>' . esc_html__( 'Send later', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="datetime-local" id="npmp-send-later-at" value="' . esc_attr( $value ) . '" min="' . esc_attr( wp_date( 'Y-m-d\TH:i' ) ) . '"> ';
	/* translators: %s: site timezone, such as Europe/London. */
	echo '<span class="description">' . esc_html( sprintf( __( 'Site time (%s)', 'nonprofit-manager' ), wp_timezone_string() ) ) . '</span></p>';
	echo '<p><button type="button" class="button" id="npmp-send-later-save" data-confirm="' . esc_attr__( 'Send this newsletter to the selected members at that time?', 'nonprofit-manager' ) . '">' . esc_html__( 'Schedule', 'nonprofit-manager' ) . '</button> ';
	echo '<button type="button" class="button-link" id="npmp-send-later-cancel"' . ( $pending ? '' : ' hidden' ) . '>' . esc_html__( 'Cancel the schedule', 'nonprofit-manager' ) . '</button></p>';
	echo '<p class="npmp-send-later-status" role="status" aria-live="polite">';
	if ( $pending ) {
		/* translators: %s: date and time. */
		echo esc_html( sprintf( __( 'Scheduled for %s.', 'nonprofit-manager' ), npmp_scheduled_emails_format_utc( $row->send_at ) ) );
	}
	echo '</p></div>';
}

/**
 * Parse a datetime-local value in the site timezone.
 *
 * @param string $value "Y-m-d\TH:i".
 * @return int Unix time, 0 when it doesn't parse.
 */
function npmp_scheduled_emails_parse_local_input( $value ) {
	$date = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', (string) $value, wp_timezone() );
	return $date ? $date->getTimestamp() : 0;
}

/**
 * AJAX: schedule or unschedule a newsletter.
 *
 * @return void
 */
function npmp_scheduled_emails_ajax_send_later() {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! $post_id || 'npmp_newsletter' !== get_post_type( $post_id ) || ! wp_verify_nonce( $nonce, 'npmp_send_later_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( npmp_scheduled_emails_send_cap() ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'nonprofit-manager' ) ) );
	}
	$row = npmp_scheduled_emails_list_row( $post_id );

	if ( ! empty( $_POST['cancel'] ) ) {
		npmp_scheduled_emails_cancel_target( 'list', $post_id, __( 'You cancelled the schedule.', 'nonprofit-manager' ) );
		wp_send_json_success( array( 'message' => __( 'You cancelled the schedule, so nothing goes out.', 'nonprofit-manager' ) ) );
	}

	$at = isset( $_POST['at'] ) ? npmp_scheduled_emails_parse_local_input( sanitize_text_field( wp_unslash( $_POST['at'] ) ) ) : 0;
	if ( ! $at || $at <= npmp_scheduled_emails_now() ) {
		wp_send_json_error( array( 'message' => __( 'Pick a date and time in the future.', 'nonprofit-manager' ) ) );
	}

	if ( function_exists( 'npmp_newsletter_store_meta' ) ) {
		$levels    = array_key_exists( 'levels', $_POST ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['levels'] ) ) : null;
		$preheader = array_key_exists( 'preheader', $_POST ) ? sanitize_textarea_field( wp_unslash( $_POST['preheader'] ) ) : null;
		npmp_newsletter_store_meta( $post_id, $levels, $preheader );
	}

	$fields = array(
		'send_at' => gmdate( 'Y-m-d H:i:s', $at ),
		'status'  => 'pending',
		'note'    => '',
	);
	if ( $row ) {
		if ( 'sending' === $row->status ) {
			wp_send_json_error( array( 'message' => __( 'This newsletter is going out right now.', 'nonprofit-manager' ) ) );
		}
		npmp_scheduled_emails_update_row( $row->id, $fields );
	} else {
		npmp_scheduled_emails_insert_row(
			array_merge(
				$fields,
				array(
					'target_type' => 'list',
					'target_id'   => $post_id,
					'rule_key'    => 'send_later',
					'subject'     => substr( (string) get_post_field( 'post_title', $post_id, 'raw' ), 0, 255 ),
				)
			)
		);
	}
	wp_send_json_success(
		array(
			/* translators: %s: date and time. */
			'message' => sprintf( __( 'Scheduled for %s.', 'nonprofit-manager' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) ),
		)
	);
}
add_action( 'wp_ajax_npmp_send_later', 'npmp_scheduled_emails_ajax_send_later' );

/* =========================================================================
 * Scheduled Emails screen
 * ====================================================================== */

/**
 * Add the screen under Email Newsletters.
 *
 * @return void
 */
function npmp_scheduled_emails_menu() {
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- edit_npmp_newsletters is the newsletter post types' own capability, resolved in npmp_filter_staff_caps().
	$GLOBALS['npmp_scheduled_emails_hook'] = add_submenu_page( 'npmp-newsletters', __( 'Scheduled Emails', 'nonprofit-manager' ), __( 'Scheduled Emails', 'nonprofit-manager' ), 'edit_npmp_newsletters', 'npmp_scheduled_emails', 'npmp_render_scheduled_emails_page' );
}
add_action( 'admin_menu', 'npmp_scheduled_emails_menu', 11 );

/**
 * Keep Email Newsletters open on the body editor.
 *
 * @param string $parent_file Parent menu.
 * @return string
 */
function npmp_scheduled_emails_parent_file( $parent_file ) {
	global $post_type;
	return 'npmp_email_body' === $post_type ? 'npmp-newsletters' : $parent_file;
}
add_filter( 'parent_file', 'npmp_scheduled_emails_parent_file', 20 );

/**
 * Highlight Scheduled Emails on the body editor.
 *
 * @param string|null $submenu_file Submenu.
 * @return string|null
 */
function npmp_scheduled_emails_submenu_file( $submenu_file ) {
	global $post_type;
	return 'npmp_email_body' === $post_type ? 'npmp_scheduled_emails' : $submenu_file;
}
add_filter( 'submenu_file', 'npmp_scheduled_emails_submenu_file', 20 );

/**
 * Upcoming rows, then recent ones, for the screen (and Pro's print page).
 *
 * @param int $recent How many finished rows to include.
 * @return object[]
 */
function npmp_scheduled_emails_table_rows( $recent = 50 ) {
	global $wpdb;
	$table = npmp_scheduled_emails_table();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dedicated table, fixed table name.
	$upcoming = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('pending','sending') ORDER BY send_at ASC, id ASC LIMIT 200" );
	$done     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('sent','skipped','cancelled') ORDER BY updated_at DESC, id DESC LIMIT %d", $recent ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_merge( (array) $upcoming, (array) $done );
}

/**
 * Render the Scheduled Emails screen.
 *
 * @return void
 */
function npmp_render_scheduled_emails_page() {
	npmp_verify_admin_access( 'edit_npmp_newsletters' );
	$defaults = npmp_scheduled_email_defaults();
	$can_edit = current_user_can( npmp_scheduled_emails_send_cap() );

	echo '<div class="wrap npmp-admin-page npmp-sched-page">';
	echo '<h1>' . esc_html__( 'Scheduled Emails', 'nonprofit-manager' ) . '</h1>';
	echo '<p>' . esc_html__( 'Email your members before each event, and see every email waiting to go out.', 'nonprofit-manager' ) . '</p>';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag after a redirect.
	if ( isset( $_GET['updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved. Upcoming events now follow these settings.', 'nonprofit-manager' ) . '</p></div>';
	}
	if ( ! $defaults['enabled'] ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Event emails are off. Tick "Email members before each event" and save to turn them on.', 'nonprofit-manager' ) . '</p></div>';
	}

	echo '<h2>' . esc_html__( 'Event emails', 'nonprofit-manager' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="npmp_save_scheduled_email_defaults">';
	wp_nonce_field( 'npmp_save_scheduled_email_defaults' );
	echo '<fieldset' . disabled( $can_edit, false, false ) . '><table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row">' . esc_html__( 'Turn on', 'nonprofit-manager' ) . '</th><td><label><input type="checkbox" name="npmp_sched_defaults[enabled]" value="1"' . checked( $defaults['enabled'], 1, false ) . '> ' . esc_html__( 'Email members before each event', 'nonprofit-manager' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'You can still turn it on or off for one event from the event\'s Scheduled Email box.', 'nonprofit-manager' ) . '</p></td></tr>';

	/**
	 * Whether an add-on draws its own list of emails in place of the one
	 * email's When, Body and Subject rows. Pro returns true and prints its
	 * rows on npmp_scheduled_email_settings_rules.
	 *
	 * @param bool $handled False.
	 */
	if ( apply_filters( 'npmp_scheduled_email_settings_rules_handled', false ) ) {
		/**
		 * Print the add-on's rows inside the settings form-table.
		 *
		 * @param array $defaults Saved defaults (npmp_scheduled_email_defaults()).
		 */
		do_action( 'npmp_scheduled_email_settings_rules', $defaults );
	} else {
		echo '<tr><th scope="row">' . esc_html__( 'When', 'nonprofit-manager' ) . '</th><td>';
		npmp_scheduled_emails_timing_fields( 'npmp_sched_defaults', $defaults['minutes_before'], false );
		echo '<p class="description">' . esc_html__( 'Day and week offsets keep the same clock time in your site timezone, even across a clock change.', 'nonprofit-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Body', 'nonprofit-manager' ) . '</th><td>';
		npmp_scheduled_emails_body_select( 'npmp_sched_defaults[body_id]', (string) $defaults['body_id'], false );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="npmp-sched-default-subject">' . esc_html__( 'Subject', 'nonprofit-manager' ) . '</label></th><td><input type="text" class="regular-text" id="npmp-sched-default-subject" name="npmp_sched_defaults[subject]" value="' . esc_attr( $defaults['subject'] ) . '" placeholder="' . esc_attr__( 'Uses the body\'s subject', 'nonprofit-manager' ) . '"></td></tr>';
	}

	echo '<tr><th scope="row">' . esc_html__( 'Saved too late', 'nonprofit-manager' ) . '</th><td><fieldset>';
	echo '<p class="description">' . esc_html__( 'When someone saves an event after its email\'s send time has passed:', 'nonprofit-manager' ) . '</p>';
	echo '<label><input type="radio" name="npmp_sched_defaults[late_mode]" value="send_if"' . checked( $defaults['late_mode'], 'send_if', false ) . '> ';
	echo wp_kses(
		sprintf(
			/* translators: %s: number input for hours. */
			__( 'Send the email right away when the event is more than %s hours out, and skip the email when it\'s closer', 'nonprofit-manager' ),
			'<input type="number" min="1" max="1440" class="small-text" name="npmp_sched_defaults[late_min_hours]" value="' . esc_attr( (string) $defaults['late_min_hours'] ) . '">'
		),
		array(
			'input' => array(
				'type'  => true,
				'min'   => true,
				'max'   => true,
				'class' => true,
				'name'  => true,
				'value' => true,
			),
		)
	);
	echo '</label><br>';
	echo '<label><input type="radio" name="npmp_sched_defaults[late_mode]" value="skip"' . checked( $defaults['late_mode'], 'skip', false ) . '> ' . esc_html__( 'Always skip it', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="radio" name="npmp_sched_defaults[late_mode]" value="send"' . checked( $defaults['late_mode'], 'send', false ) . '> ' . esc_html__( 'Always send it right away', 'nonprofit-manager' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Either way, no event email goes out in the last hour before the event starts.', 'nonprofit-manager' ) . '</p>';
	echo '</fieldset></td></tr>';

	echo '</tbody></table></fieldset>';
	npmp_scheduled_emails_pro_teaser();
	if ( $can_edit ) {
		submit_button( __( 'Save', 'nonprofit-manager' ) );
	}
	echo '</form>';

	echo '<h2>' . esc_html__( 'Email bodies', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Write a body once with placeholders, and each event fills in its own details.', 'nonprofit-manager' ) . ' ';
	echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=npmp_email_body' ) ) . '">' . esc_html__( 'Write a new body', 'nonprofit-manager' ) . '</a></p><ul class="npmp-sched-bodies">';
	foreach ( npmp_scheduled_emails_bodies() as $body ) {
		$edit = get_edit_post_link( $body->ID );
		echo '<li>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $body->post_title ) . '</a>' : esc_html( $body->post_title ) ) . '</li>';
	}
	echo '</ul>';

	echo '<h2>' . esc_html__( 'Upcoming and recent', 'nonprofit-manager' ) . '</h2>';
	/** This action is documented in includes/npmp-pro-feature-pages.php. */
	do_action( 'npmp_admin_table_toolbar', 'scheduled_emails', array() );
	$rows = npmp_scheduled_emails_table_rows();
	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'Nothing scheduled yet.', 'nonprofit-manager' ) . '</p></div>';
		return;
	}
	echo '<table class="widefat striped npmp-sched-table"><thead><tr>';
	foreach ( array( __( 'Email', 'nonprofit-manager' ), __( 'Audience', 'nonprofit-manager' ), __( 'Send time', 'nonprofit-manager' ), __( 'Status', 'nonprofit-manager' ), __( 'Note', 'nonprofit-manager' ) ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	// One query for every row's event or newsletter, not one per row.
	_prime_post_caches( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'target_id' ) ) ), false, false );
	foreach ( $rows as $row ) {
		$title = get_post_field( 'post_title', (int) $row->target_id, 'raw' );
		$title = '' !== (string) $title ? $title : __( '(deleted)', 'nonprofit-manager' );
		$edit  = get_edit_post_link( (int) $row->target_id );
		$kind  = 'list' === $row->target_type ? __( 'Newsletter, sent later', 'nonprofit-manager' ) : __( 'Event email', 'nonprofit-manager' );
		echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '<br><span class="description">' . esc_html( $kind ) . '</span></td>';
		echo '<td>' . esc_html( npmp_scheduled_emails_audience_label( $row ) ) . '</td>';
		$send_ts = npmp_scheduled_emails_utc_to_ts( $row->send_at );
		echo '<td><span class="npmp-nowrap">' . esc_html( wp_date( get_option( 'date_format' ), $send_ts ) ) . '</span> <span class="npmp-nowrap">' . esc_html( wp_date( get_option( 'time_format' ), $send_ts ) ) . '</span></td>';
		echo '<td>' . esc_html( npmp_scheduled_emails_status_label( $row ) ) . '</td>';
		echo '<td>' . esc_html( (string) $row->note ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Save the site defaults, then re-sync upcoming events.
 *
 * @return void
 */
function npmp_scheduled_emails_save_defaults() {
	check_admin_referer( 'npmp_save_scheduled_email_defaults' );
	if ( ! current_user_can( npmp_scheduled_emails_send_cap() ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), '', array( 'response' => 403 ) );
	}
	$in = isset( $_POST['npmp_sched_defaults'] ) && is_array( $_POST['npmp_sched_defaults'] ) ? map_deep( wp_unslash( $_POST['npmp_sched_defaults'] ), 'sanitize_text_field' ) : array();

	// When an add-on drew its own rows, the one email's fields weren't on
	// the form, so keep what's saved instead of resetting it.
	$current = npmp_scheduled_email_defaults();
	$minutes = isset( $in['timing'] ) ? npmp_scheduled_emails_read_timing( $in ) : $current['minutes_before'];
	update_option(
		'npmp_scheduled_email_defaults',
		npmp_scheduled_email_sanitize_defaults(
			array(
				'enabled'        => ! empty( $in['enabled'] ),
				'minutes_before' => $minutes ? $minutes : 1440,
				'body_id'        => isset( $in['body_id'] ) ? absint( $in['body_id'] ) : $current['body_id'],
				'subject'        => isset( $in['subject'] ) ? (string) $in['subject'] : $current['subject'],
				'late_mode'      => (string) ( $in['late_mode'] ?? 'send_if' ),
				'late_min_hours' => absint( $in['late_min_hours'] ?? 48 ),
			)
		)
	);
	/**
	 * Save an add-on's rows from the settings form, before upcoming events
	 * re-sync. The nonce and the send capability have been checked.
	 *
	 * @param array $request The whole unslashed POST. Sanitize what you read.
	 */
	do_action( 'npmp_scheduled_email_defaults_saved', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callbacks sanitize the fields they read.
	npmp_scheduled_emails_sync_upcoming_events();
	wp_safe_redirect( admin_url( 'admin.php?page=npmp_scheduled_emails&updated=1' ) );
	exit;
}
add_action( 'admin_post_npmp_save_scheduled_email_defaults', 'npmp_scheduled_emails_save_defaults' );

/**
 * Load the script and styles on the screens that use them.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function npmp_scheduled_emails_enqueue( $hook_suffix ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$types  = array( 'npmp_event', 'npmp_newsletter', 'npmp_email_body' );
	$ours   = ( ! empty( $GLOBALS['npmp_scheduled_emails_hook'] ) && $hook_suffix === $GLOBALS['npmp_scheduled_emails_hook'] )
		|| ( $screen && 'post' === $screen->base && in_array( $screen->post_type, $types, true ) );
	if ( ! $ours ) {
		return;
	}
	$base = plugin_dir_url( __DIR__ );
	wp_enqueue_style( 'npmp-scheduled-emails', $base . 'assets/css/npmp-scheduled-emails.css', array(), npmp_get_asset_version( 'assets/css/npmp-scheduled-emails.css' ) );
	wp_enqueue_script( 'npmp-scheduled-emails', $base . 'assets/js/npmp-scheduled-emails.js', array( 'jquery' ), npmp_get_asset_version( 'assets/js/npmp-scheduled-emails.js' ), true );
	wp_localize_script(
		'npmp-scheduled-emails',
		'npmpScheduledEmails',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'working' => __( 'Working…', 'nonprofit-manager' ),
			'failed'  => __( 'That didn\'t work. Try again.', 'nonprofit-manager' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'npmp_scheduled_emails_enqueue' );
