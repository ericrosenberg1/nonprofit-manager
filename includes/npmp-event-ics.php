<?php
/**
 * File path: includes/npmp-event-ics.php
 *
 * An event as iCalendar data, for the site feed and for the .ics file on event
 * emails. The text itself comes from includes/npmp-ics.php.
 *
 * Event emails (the scheduled emails free and Pro send for an event, and their
 * "Send a test to me") carry the event as an .ics file with the full meeting
 * details, the same ones [join_link], [meeting_id], and [passcode] fill,
 * Pro's group defaults included. Someone who opens it gets the link in their
 * calendar even when the event keeps the link off the site.
 *
 * We attach the file only on a sending path that carries it. WordPress's own
 * mailer, custom SMTP, and Amazon SES (free's SMTP transport) do, through
 * PHPMailer. Pro's SendGrid, Brevo, Mailgun, and Postmark senders hand any
 * message with an attachment back to WordPress's own mailer
 * (npmp_pro_handle_email()), which would take the email off the service the
 * site chose, so on those four we leave the file off and the email goes as it
 * did before. A later Pro can say yes through `npmp_mail_attachments_supported`.
 *
 * Loaded on every request, beside includes/npmp-event-meeting.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Unix time for a stored "Y-m-d H:i:s" in the site's time zone.
 *
 * @param string $local Stored value.
 * @return int 0 when it doesn't parse.
 */
function npmp_event_ics_time( $local ) {
	if ( '' === (string) $local ) {
		return 0;
	}
	if ( function_exists( 'npmp_event_datetime_to_timestamp' ) ) {
		return (int) npmp_event_datetime_to_timestamp( $local );
	}
	try {
		$date = new DateTimeImmutable( (string) $local, wp_timezone() );
	} catch ( Exception $e ) {
		return 0;
	}
	return (int) $date->getTimestamp();
}

/**
 * The fields npmp_ics_vevent() takes, for one event and the details the
 * caller may show.
 *
 * @param WP_Post|int $event   Event.
 * @param array       $details start, end, location, url, meeting_id, passcode.
 * @return array
 */
function npmp_event_ics_data( $event, $details ) {
	$event   = get_post( $event );
	$details = (array) $details;
	if ( ! $event ) {
		return array();
	}
	// The site's own host, not the request's: the feed is cached for everyone,
	// so a Host header baked into the UIDs changed them between cache fills
	// and calendar apps showed every event twice. The email file uses the
	// same UID, so a calendar that has both treats them as one event.
	$host  = preg_replace( '/[^A-Za-z0-9\.\-]/', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$stamp = 0 === strpos( (string) $event->post_modified_gmt, '0000' ) ? 0 : (int) strtotime( $event->post_modified_gmt . ' UTC' );
	return array(
		'uid'        => absint( $event->ID ) . '@' . $host,
		'stamp'      => $stamp,
		'start'      => npmp_event_ics_time( (string) ( $details['start'] ?? '' ) ),
		'end'        => npmp_event_ics_time( (string) ( $details['end'] ?? '' ) ),
		'summary'    => get_the_title( $event ),
		'location'   => (string) ( $details['location'] ?? '' ),
		'url'        => (string) ( $details['url'] ?? '' ),
		'meeting_id' => (string) ( $details['meeting_id'] ?? '' ),
		'passcode'   => (string) ( $details['passcode'] ?? '' ),
		'labels'     => array(
			'join'       => __( 'Join link', 'nonprofit-manager' ),
			'meeting_id' => __( 'Meeting ID', 'nonprofit-manager' ),
			'passcode'   => __( 'Passcode', 'nonprofit-manager' ),
		),
	);
}

/**
 * Every detail an event email may carry, link and all.
 *
 * @param int $event_id Event ID.
 * @return array
 */
function npmp_event_ics_private_details( $event_id ) {
	$event_id = (int) $event_id;
	if ( function_exists( 'npmp_get_event_details' ) ) {
		$details = npmp_get_event_details( $event_id, true );
	} else {
		$details = array(
			'start'    => (string) get_post_meta( $event_id, '_npmp_event_start', true ),
			'end'      => (string) get_post_meta( $event_id, '_npmp_event_end', true ),
			'location' => (string) get_post_meta( $event_id, '_npmp_event_location', true ),
		);
	}
	// The values the email's tags use, with Pro's group defaults.
	$meeting = npmp_event_meeting_details( $event_id );
	foreach ( array( 'url', 'meeting_id', 'passcode' ) as $key ) {
		$details[ $key ] = $meeting[ $key ];
	}
	return $details;
}

/**
 * The .ics text for one event's emails, or '' when it has no start.
 *
 * @param int $event_id Event ID.
 * @return string
 */
function npmp_event_ics_for_email( $event_id ) {
	if ( 'npmp_event' !== get_post_type( (int) $event_id ) ) {
		return '';
	}
	$vevent = npmp_ics_vevent( npmp_event_ics_data( (int) $event_id, npmp_event_ics_private_details( (int) $event_id ) ) );
	return '' === $vevent ? '' : npmp_ics_calendar( $vevent, 'PUBLISH' );
}

/**
 * Whether the way this site sends mail carries an attachment without
 * changing how the email leaves.
 *
 * @return bool
 */
function npmp_mail_attachments_supported() {
	$provider = function_exists( 'npmp_email_get_settings' ) ? (string) ( npmp_email_get_settings()['provider'] ?? 'wordpress' ) : 'wordpress';
	$api      = in_array( $provider, array( 'brevo', 'sendgrid', 'mailgun', 'postmark' ), true );
	// Pro's API senders only run with Pro on. Without it the email goes
	// through WordPress's own mailer anyway.
	$supported = ! ( $api && function_exists( 'npmp_pro_handle_email' ) );
	/**
	 * Whether event emails may carry an .ics file on this site's sending path.
	 *
	 * @param bool   $supported Our answer.
	 * @param string $provider  The saved email provider.
	 */
	return (bool) apply_filters( 'npmp_mail_attachments_supported', $supported, $provider );
}

/**
 * The event a test send is about. npmp_scheduled_emails_render_test() sets it
 * and the next newsletter-mail send with no newsletter reads and clears it, so
 * "Send a test to me" (free's and Pro's) carries the same file a real send does.
 *
 * @param int|null $event_id Event to set, 0 to clear, null to read and clear.
 * @return int
 */
function npmp_event_ics_test_event( $event_id = null ) {
	static $current = 0;
	if ( null === $event_id ) {
		$out     = $current;
		$current = 0;
		return $out;
	}
	$current = (int) $event_id;
	return $current;
}

/**
 * Write an event's .ics once per request into a private temp folder, named
 * after the event, and delete it when the request ends.
 *
 * @param int $event_id Event ID.
 * @return string File path, or '' when there's nothing to attach.
 */
function npmp_event_ics_file( $event_id ) {
	static $files = array();
	$event_id     = (int) $event_id;
	if ( isset( $files[ $event_id ] ) ) {
		return $files[ $event_id ];
	}
	$files[ $event_id ] = '';
	$ics                = npmp_event_ics_for_email( $event_id );
	if ( '' === $ics ) {
		return '';
	}
	$dir = trailingslashit( get_temp_dir() ) . 'npmp-ics-' . wp_generate_password( 16, false ) . '/';
	if ( ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	$name = sanitize_file_name( (string) get_post_field( 'post_name', $event_id ) );
	$name = '' !== $name ? $name : 'event-' . $event_id;
	$path = $dir . $name . '.ics';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temp file for one request, deleted at shutdown.
	if ( false === file_put_contents( $path, $ics ) ) {
		return '';
	}
	$files[ $event_id ] = $path;
	if ( 1 === count( array_filter( $files ) ) ) {
		add_action(
			'shutdown',
			static function () use ( &$files ) {
				foreach ( array_filter( $files ) as $file ) {
					wp_delete_file( $file );
					@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own empty temp folder.
				}
			}
		);
	}
	return $path;
}

/**
 * Put the event's .ics on an event email.
 *
 * @param string[] $attachments   File paths.
 * @param int      $newsletter_id Newsletter ID, 0 for a test.
 * @return string[]
 */
function npmp_event_ics_mail_attachments( $attachments, $newsletter_id = 0 ) {
	$attachments = (array) $attachments;
	$event_id    = $newsletter_id ? (int) get_post_meta( (int) $newsletter_id, '_npmp_newsletter_scheduled_event', true ) : npmp_event_ics_test_event();
	if ( ! $event_id || ! npmp_mail_attachments_supported() ) {
		return $attachments;
	}
	/**
	 * Whether an event's emails carry its .ics file.
	 *
	 * @param bool $attach   Default true.
	 * @param int  $event_id Event ID.
	 */
	if ( ! apply_filters( 'npmp_event_email_ics', true, $event_id ) ) {
		return $attachments;
	}
	$file = npmp_event_ics_file( $event_id );
	if ( '' !== $file ) {
		$attachments[] = $file;
	}
	return $attachments;
}
add_filter( 'npmp_newsletter_mail_attachments', 'npmp_event_ics_mail_attachments', 10, 2 );
