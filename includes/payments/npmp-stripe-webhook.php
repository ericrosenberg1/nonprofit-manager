<?php
/**
 * File path: includes/payments/npmp-stripe-webhook.php
 *
 * Stripe webhook for one-time gifts: admin-ajax.php?action=npmp_stripe_webhook.
 *
 * Before this, a one-time Stripe gift was recorded only when the donor's
 * browser came back to the success URL (npmp_maybe_finalize_stripe_donation()).
 * A donor who paid and closed the tab first left a paid gift with no record
 * and no thank-you. Stripe sends checkout.session.completed here whether or
 * not the donor comes back.
 *
 * One gift, one row, one thank-you. The donor's return, this endpoint, and
 * Pro's older address (action=npmp_stripe_recurring_webhook) can land in any
 * order or at the same moment. All three record through
 * npmp_stripe_record_checkout_session(), which writes with log_donation()
 * (one row per Checkout Session id, under a lock) and thanks the donor only
 * when its own call wrote the row.
 *
 * Every event must carry a valid Stripe-Signature made with the signing
 * secret saved in Payment Settings (option npmp_stripe_webhook_secret, the
 * same option Pro's address checks). With no secret saved, the endpoint
 * answers 503 and records nothing, and the donor's return records the gift
 * as before.
 *
 * With Pro active, every verified event also goes to Pro's dispatcher
 * (npmp_stripe_process_event()), so this one address covers recurring gifts
 * and dues as well as Pro's own address does. Pro's dispatcher claims each
 * event id once, so a site with both addresses set up in Stripe processes
 * each event once.
 *
 * Loaded on every request, outside the Donations module, so Pro's events
 * still reach Pro on a site that switched Donations off.
 *
 * Unit tests: tests/test-stripe-webhook.php. Real WordPress, real processes,
 * MySQL and SQLite: tests/rig/donation-race.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The address the site owner gives Stripe.
 *
 * @return string
 */
function npmp_stripe_webhook_url() {
	return admin_url( 'admin-ajax.php?action=npmp_stripe_webhook' );
}

/**
 * A site address reduced to host and path, for telling this site's Stripe
 * sessions from another site's.
 *
 * Reads the saved Site Address (the home option), not home_url(), so the
 * scheme a request came in on and a translation plugin's language prefix
 * leave the answer alone. Drops the scheme, a leading www., and a trailing
 * slash, and lowercases the rest.
 *
 * @param string|null $url Any URL. Null means this site's address.
 * @return string host[:port][/path], or '' for a URL with no host.
 */
function npmp_stripe_site_address( $url = null ) {
	$url   = null === $url ? get_option( 'home', '' ) : $url;
	$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	if ( 0 === strpos( $host, 'www.' ) ) {
		$host = substr( $host, 4 );
	}
	if ( ! empty( $parts['port'] ) ) {
		$host .= ':' . (int) $parts['port'];
	}

	return $host . strtolower( rtrim( (string) ( $parts['path'] ?? '' ), '/' ) );
}

/**
 * The tag this site stamps on each Stripe Checkout Session and subscription
 * it creates, as metadata npmp_site.
 *
 * Stripe sends every event on an account to every endpoint on the account.
 * Two sites on one Stripe account each got the other's
 * checkout.session.completed, and without the tag each recorded the other's
 * gifts and dues joins, added the other's donors, and thanked them in its
 * own name. A hash keeps the address out of the metadata.
 *
 * @return string 16 hex characters, or '' when the site has no address.
 */
function npmp_stripe_site_tag() {
	$address = npmp_stripe_site_address();
	return '' === $address ? '' : substr( hash( 'sha256', 'npmp_site:' . $address ), 0, 16 );
}

/**
 * Whether a URL points into this site: the same host, under this site's
 * path.
 *
 * @param string $url URL to check.
 * @return bool
 */
function npmp_stripe_url_is_this_site( $url ) {
	$home    = npmp_stripe_site_address();
	$address = npmp_stripe_site_address( $url );
	return '' !== $home && '' !== $address && ( $address === $home || 0 === strpos( $address, $home . '/' ) );
}

/**
 * Whether this site created a Stripe Checkout Session.
 *
 * A session stamped with npmp_site must carry this site's tag. A session
 * from before the tag carries none, and counts as this site's when its
 * success_url comes back here: every session free and Pro create sends the
 * donor back to the site that made it.
 *
 * @param array|object $session Checkout Session from Stripe.
 * @return bool
 */
function npmp_stripe_session_is_this_site( $session ) {
	if ( is_object( $session ) ) {
		$session = json_decode( wp_json_encode( $session ), true );
	}
	if ( ! is_array( $session ) ) {
		return false;
	}

	$tag = $session['metadata']['npmp_site'] ?? '';
	if ( is_string( $tag ) && '' !== $tag ) {
		$mine = npmp_stripe_site_tag();
		return '' !== $mine && hash_equals( $mine, $tag );
	}

	$success_url = $session['success_url'] ?? '';
	return is_string( $success_url ) && npmp_stripe_url_is_this_site( $success_url );
}

/**
 * The signing secrets saved in Payment Settings.
 *
 * Each Stripe endpoint has its own signing secret, and a Pro site can point
 * two endpoints here (this address and Pro's older one), so the box takes
 * more than one. Spaces, commas, and new lines separate them. A browser
 * drops the new lines from a one-line box, so two secrets pasted on two
 * lines arrive run together, and a new secret starts at each whsec_.
 * Anything that doesn't start with whsec_ isn't a Stripe signing secret,
 * and gets dropped.
 *
 * @param string|null $raw The box's text. Null reads the saved option.
 * @return string[] Each secret once, in the order given.
 */
function npmp_stripe_webhook_secrets( $raw = null ) {
	$raw = null === $raw ? get_option( 'npmp_stripe_webhook_secret', '' ) : $raw;
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}

	$secrets = array();
	foreach ( preg_split( '/[\s,;]+/', str_replace( 'whsec_', ' whsec_', $raw ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
		if ( 0 === strpos( $token, 'whsec_' ) && strlen( $token ) > 6 && ! in_array( $token, $secrets, true ) ) {
			$secrets[] = $token;
		}
	}

	return $secrets;
}

/**
 * Check a Stripe-Signature header against the raw request body.
 *
 * Stripe signs "<timestamp>.<raw body>" with HMAC-SHA256 under the
 * endpoint's signing secret and sends "t=<timestamp>,v1=<hex>". While a
 * secret is being rolled, Stripe sends one v1 per active secret, so any one
 * that matches passes. A v0 or any other scheme never counts. The timestamp
 * must sit within the tolerance of the server clock either way, which stops
 * a captured request from being replayed later.
 *
 * With more than one secret saved (one per endpoint), a match under any of
 * them passes. Every comparison runs through hash_equals().
 *
 * @param string          $payload    Raw request body, byte for byte.
 * @param string          $sig_header Stripe-Signature header value.
 * @param string|string[] $secret     Endpoint signing secret (whsec_...), or
 *                                    a list of them.
 * @param int|null        $now        Unix time to check against. Null means time().
 * @param int             $tolerance  Seconds of allowed clock difference.
 *                                    Stripe's own libraries use 300.
 * @return bool
 */
function npmp_stripe_webhook_signature_valid( $payload, $sig_header, $secret, $now = null, $tolerance = 300 ) {
	$secrets = array_values( array_filter( (array) $secret, static function ( $s ) {
		return is_string( $s ) && '' !== $s;
	} ) );
	if ( ! is_string( $payload ) || ! is_string( $sig_header ) || ! $secrets ) {
		return false;
	}

	$timestamp  = '';
	$signatures = array();
	foreach ( explode( ',', $sig_header ) as $element ) {
		$parts = explode( '=', $element, 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		$key   = trim( $parts[0] );
		$value = trim( $parts[1] );
		if ( 't' === $key ) {
			$timestamp = $value;
		} elseif ( 'v1' === $key && '' !== $value ) {
			$signatures[] = $value;
		}
	}

	if ( '' === $timestamp || ! ctype_digit( $timestamp ) || ! $signatures ) {
		return false;
	}

	$now = null === $now ? time() : (int) $now;
	if ( abs( $now - (int) $timestamp ) > (int) $tolerance ) {
		return false;
	}

	foreach ( $secrets as $one_secret ) {
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $one_secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Check one delivery and act on it. Everything but the HTTP layer.
 *
 * @param string $payload    Raw request body.
 * @param string $sig_header Stripe-Signature header value.
 * @return array{0:int,1:string} HTTP status and a short message for Stripe's
 *                               delivery log. 2xx tells Stripe the event is
 *                               done. Anything else makes Stripe retry it
 *                               for about three days.
 */
function npmp_stripe_webhook_receive( $payload, $sig_header ) {
	if ( ! is_string( $payload ) || '' === $payload ) {
		return array( 400, 'Empty payload.' );
	}

	// No secret, no trust. Answering 503 keeps the event in Stripe's retry
	// queue, so a gift sent before the owner pasted the secret still gets
	// recorded once the secret is saved.
	$secrets = npmp_stripe_webhook_secrets();
	if ( ! $secrets ) {
		return array( 503, 'Webhook secret not configured.' );
	}

	if ( ! is_string( $sig_header ) || '' === $sig_header ) {
		return array( 400, 'Missing Stripe signature.' );
	}

	if ( ! npmp_stripe_webhook_signature_valid( $payload, $sig_header, $secrets ) ) {
		return array( 403, 'Invalid signature.' );
	}

	$event = json_decode( $payload, true );
	if ( ! is_array( $event ) || empty( $event['type'] ) || ! is_string( $event['type'] ) || ! isset( $event['data']['object'] ) || ! is_array( $event['data']['object'] ) ) {
		return array( 400, 'Invalid event payload.' );
	}

	return npmp_stripe_webhook_dispatch( $event, $payload );
}

/**
 * Act on a verified event.
 *
 * checkout.session.completed for a one-time gift from this site's form gets
 * recorded here. With Pro active, the event then goes to Pro's dispatcher,
 * which handles dues, recurring gifts, and everything else Pro listens for.
 * A gift that fails to save answers 500 before Pro sees the event, so
 * Stripe's retry runs both again and Pro's claim on the event id stays free.
 *
 * @param array  $event   Verified event, decoded to arrays.
 * @param string $payload The same event as raw JSON, for Pro's dispatcher,
 *                        which reads objects.
 * @return array{0:int,1:string} HTTP status and message.
 */
function npmp_stripe_webhook_dispatch( $event, $payload ) {
	$message = 'Event received.';

	if ( 'checkout.session.completed' === $event['type'] ) {
		$result = function_exists( 'npmp_stripe_record_checkout_session' )
			? npmp_stripe_record_checkout_session( $event['data']['object'] )
			: 'skipped'; // Donations are switched off on this site.

		if ( 'failed' === $result ) {
			if ( function_exists( 'npmp_payment_debug_log' ) ) {
				npmp_payment_debug_log( 'stripe webhook: donation insert failed, Stripe will retry' );
			}
			return array( 500, 'Could not record the gift. Retry.' );
		}

		// A 200 for another site's session too, so Stripe stops sending it
		// here. That site records the gift from its own endpoint.
		$messages = array(
			'recorded'  => 'Gift recorded.',
			'duplicate' => 'Gift already recorded.',
			'skipped'   => 'Nothing to record.',
			'foreign'   => 'Another site\'s gift. Nothing to record.',
		);
		$message  = $messages[ $result ] ?? $message;
	}

	// Pro handles the rest. Its dispatcher claims the event id, so the same
	// event arriving at Pro's own address as well is processed once.
	if ( function_exists( 'npmp_stripe_process_event' ) ) {
		$pro = npmp_stripe_process_event( json_decode( $payload, false ) );
		if ( is_wp_error( $pro ) ) {
			$data   = $pro->get_error_data();
			$status = ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 500;
			return array( $status, $pro->get_error_message() );
		}
		if ( false === $pro ) {
			return array( 200, 'Event already processed.' );
		}
	}

	return array( 200, $message );
}

/**
 * The HTTP side: read the request, answer Stripe, exit.
 *
 * @return void
 */
function npmp_stripe_webhook_handle() {
	$payload    = file_get_contents( 'php://input' );
	$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

	list( $status, $message ) = npmp_stripe_webhook_receive( is_string( $payload ) ? $payload : '', $sig_header );

	if ( $status >= 200 && $status < 300 ) {
		wp_send_json_success( array( 'message' => $message ), $status );
	}
	wp_send_json_error( array( 'message' => $message ), $status );
}
add_action( 'wp_ajax_nopriv_npmp_stripe_webhook', 'npmp_stripe_webhook_handle' );
