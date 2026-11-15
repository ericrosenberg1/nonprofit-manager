<?php
/**
 * File path: includes/npmp-event-meeting.php
 *
 * Meeting details on an event: the event link (`_npmp_event_url`, unchanged),
 * a meeting ID, a passcode, and "Show the meeting link only in emails".
 *
 * With that box ticked, the public event page leaves out the link, the ID, and
 * the passcode, and npmp_get_event_details() hands them back blank unless the
 * caller asks for them. The calendar, the event lists and blocks, the iCal feed,
 * and the REST API never print any of the three. Event emails always get all
 * three ([join_link], [meeting_id], [passcode]), and an event email from such an
 * event never gets a public newsletter page.
 *
 * Pro fills fields an event leaves empty from its groups through the
 * `npmp_event_meeting_details` filter (emails only, never web pages).
 *
 * Loaded on every request, so the scheduler and the newsletter archive can ask
 * even with the calendar module off.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_EVENT_MEETING_ID_META' ) ) {
	define( 'NPMP_EVENT_MEETING_ID_META', '_npmp_event_meeting_id' );
}
if ( ! defined( 'NPMP_EVENT_PASSCODE_META' ) ) {
	define( 'NPMP_EVENT_PASSCODE_META', '_npmp_event_passcode' );
}
if ( ! defined( 'NPMP_EVENT_EMAIL_ONLY_META' ) ) {
	define( 'NPMP_EVENT_EMAIL_ONLY_META', '_npmp_event_link_email_only' );
}
if ( ! defined( 'NPMP_NEWSLETTER_MEETING_PRIVATE_META' ) ) {
	// Set on an event email built from an event whose meeting details stay off the site.
	define( 'NPMP_NEWSLETTER_MEETING_PRIVATE_META', '_npmp_newsletter_meeting_private' );
}

/**
 * Clean a meeting ID or passcode: one line of plain text, at most 100
 * characters.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function npmp_event_meeting_clean( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 100 ) : substr( $value, 0, 100 );
}

/**
 * The meeting details saved on the event itself.
 *
 * @param int $event_id Event ID.
 * @return array{url:string,meeting_id:string,passcode:string,email_only:bool}
 */
function npmp_event_meeting_own( $event_id ) {
	$event_id = (int) $event_id;
	return array(
		'url'        => (string) get_post_meta( $event_id, '_npmp_event_url', true ),
		'meeting_id' => (string) get_post_meta( $event_id, NPMP_EVENT_MEETING_ID_META, true ),
		'passcode'   => (string) get_post_meta( $event_id, NPMP_EVENT_PASSCODE_META, true ),
		'email_only' => '1' === (string) get_post_meta( $event_id, NPMP_EVENT_EMAIL_ONLY_META, true ),
	);
}

/**
 * The meeting details an event's emails use: the event's own, then whatever
 * an add-on fills in for the fields it leaves empty.
 *
 * @param int $event_id Event ID.
 * @return array{url:string,meeting_id:string,passcode:string,email_only:bool}
 */
function npmp_event_meeting_details( $event_id ) {
	$own = npmp_event_meeting_own( $event_id );
	/**
	 * Filter the meeting details an event's emails use. Pro fills the fields
	 * the event leaves empty from the event's groups. Set `email_only` to true
	 * when a value you add must stay off web pages, so an email that carries it
	 * never gets a public newsletter page. Never clear the event's own
	 * `email_only`.
	 *
	 * @param array $details  url, meeting_id, passcode (strings), email_only (bool).
	 * @param int   $event_id Event ID.
	 */
	$out = apply_filters( 'npmp_event_meeting_details', $own, (int) $event_id );
	$out = is_array( $out ) ? $out : array();
	return array(
		'url'        => isset( $out['url'] ) && is_scalar( $out['url'] ) ? (string) $out['url'] : $own['url'],
		'meeting_id' => isset( $out['meeting_id'] ) && is_scalar( $out['meeting_id'] ) ? (string) $out['meeting_id'] : $own['meeting_id'],
		'passcode'   => isset( $out['passcode'] ) && is_scalar( $out['passcode'] ) ? (string) $out['passcode'] : $own['passcode'],
		'email_only' => $own['email_only'] || ! empty( $out['email_only'] ),
	);
}

/**
 * Whether an event's meeting details must stay off web pages, counting
 * values an add-on fills in for its emails.
 *
 * @param int $event_id Event ID.
 * @return bool
 */
function npmp_event_meeting_is_private( $event_id ) {
	return (bool) npmp_event_meeting_details( $event_id )['email_only'];
}

/**
 * Blank the link, meeting ID, and passcode of a details array when they're
 * only for emails.
 *
 * @param array $details Details with url, meeting_id, passcode, email_only.
 * @return array
 */
function npmp_event_meeting_public( $details ) {
	$details = (array) $details;
	if ( ! empty( $details['email_only'] ) ) {
		$details['url']        = '';
		$details['meeting_id'] = '';
		$details['passcode']   = '';
	}
	return $details;
}
