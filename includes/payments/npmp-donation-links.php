<?php
/**
 * File path: includes/payments/npmp-donation-links.php
 *
 * Three donation pieces that live outside a payment gateway:
 *
 * - The "Payment link" gateway. The site owner pastes one or more URL
 *   templates with {amount}, {name}, {email}, {currency} and {note} in them,
 *   and the form sends the donor to that URL with each placeholder filled in
 *   and URL-encoded. Like the PayPal and Venmo links, it records nothing.
 * - The donation form inside an email. Mail apps don't run forms, so a
 *   donation form block or shortcode in a newsletter, event email, or test
 *   send prints a Donate button that links to the site's donation page.
 * - Prefill. A logged-in user, or a reader who came from that newsletter
 *   button, finds their email (and name, where the form asks) filled in.
 *   The newsletter link carries a signed, expiring token, never a bare
 *   address, and a token that fails its check fills in nothing.
 *
 * Unit test tests/test-donation-links.php, rig tests/rig/donation-links.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/* ==============================================================
 * Payment link gateway
 * ============================================================= */

/**
 * Most payment links a site can save.
 */
const NPMP_PAYMENT_LINKS_MAX = 5;

/**
 * Placeholders a payment link template can use.
 *
 * @return string[]
 */
function npmp_payment_link_placeholders() {
	return array( 'amount', 'name', 'email', 'currency', 'note' );
}

/**
 * Check one pasted URL template and clean it for saving.
 *
 * The template must be an https URL with a host and no user name or
 * password, and no placeholder may sit in the host, so what a donor types
 * can never change where the link goes. esc_url_raw() strips braces, so the
 * placeholders are swapped for plain markers around that call and put back
 * after it.
 *
 * @param string $raw Template as typed.
 * @return array{url:string,error:string} url is '' when error is set.
 */
function npmp_payment_link_validate_template( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return array( 'url' => '', 'error' => '' );
	}

	$fail = static function ( $message ) {
		return array( 'url' => '', 'error' => $message );
	};

	if ( 0 !== stripos( $raw, 'https://' ) ) {
		return $fail( __( 'The link must start with https://.', 'nonprofit-manager' ) );
	}

	$known = npmp_payment_link_placeholders();
	if ( preg_match_all( '/\{([^{}]*)\}/', $raw, $found ) ) {
		foreach ( $found[1] as $name ) {
			if ( ! in_array( strtolower( $name ), $known, true ) ) {
				/* translators: %s: a placeholder name the site owner typed, such as {amt}. */
				return $fail( sprintf( __( '%s isn\'t a placeholder we know. Use {amount}, {name}, {email}, {currency}, or {note}.', 'nonprofit-manager' ), '{' . $name . '}' ) );
			}
		}
	}

	$marked = preg_replace_callback(
		'/\{(' . implode( '|', $known ) . ')\}/i',
		static function ( $m ) {
			return 'npmpph' . strtolower( $m[1] ) . 'npmpph';
		},
		$raw
	);

	$parts = wp_parse_url( $marked );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
		return $fail( __( 'That doesn\'t look like a full web address.', 'nonprofit-manager' ) );
	}
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return $fail( __( 'The link can\'t hold a user name or password.', 'nonprofit-manager' ) );
	}
	if ( false !== strpos( strtolower( $parts['host'] ), 'npmpph' ) || ( isset( $parts['port'] ) && false !== strpos( (string) $parts['port'], 'npmpph' ) ) ) {
		return $fail( __( 'A placeholder can\'t go in the site name part of the link.', 'nonprofit-manager' ) );
	}

	$clean = esc_url_raw( $marked, array( 'https' ) );
	if ( '' === $clean ) {
		return $fail( __( 'That doesn\'t look like a full web address.', 'nonprofit-manager' ) );
	}

	$clean = preg_replace( '/npmpph(' . implode( '|', $known ) . ')npmpph/', '{$1}', $clean );

	return array( 'url' => $clean, 'error' => '' );
}

/**
 * Fill a saved template the way the form's script does: each value
 * URL-encoded, unknown text left alone.
 *
 * @param string $template Saved template.
 * @param array  $values   Placeholder name => raw value.
 * @return string
 */
function npmp_payment_link_fill( $template, $values ) {
	return (string) preg_replace_callback(
		'/\{(' . implode( '|', npmp_payment_link_placeholders() ) . ')\}/',
		static function ( $m ) use ( $values ) {
			return rawurlencode( (string) ( $values[ $m[1] ] ?? '' ) );
		},
		(string) $template
	);
}

/**
 * Clean the posted payment link rows for saving.
 *
 * @param array $labels Posted labels.
 * @param array $urls   Posted URL templates.
 * @param array $notes  Posted notes.
 * @return array{links:array,errors:string[]}
 */
function npmp_payment_links_from_post( $labels, $urls, $notes ) {
	$labels = is_array( $labels ) ? array_values( $labels ) : array();
	$urls   = is_array( $urls ) ? array_values( $urls ) : array();
	$notes  = is_array( $notes ) ? array_values( $notes ) : array();
	$links  = array();
	$errors = array();

	foreach ( $urls as $i => $url ) {
		$label = sanitize_text_field( (string) ( $labels[ $i ] ?? '' ) );
		$note  = sanitize_text_field( (string) ( $notes[ $i ] ?? '' ) );
		$check = npmp_payment_link_validate_template( (string) $url );
		if ( '' !== $check['error'] ) {
			$errors[] = ( '' !== $label ? $label . ': ' : '' ) . $check['error'];
			continue;
		}
		if ( '' === $check['url'] ) {
			continue;
		}
		if ( count( $links ) >= NPMP_PAYMENT_LINKS_MAX ) {
			/* translators: %d: most payment links a site can save. */
			$errors[] = sprintf( __( 'You can save up to %d payment links.', 'nonprofit-manager' ), NPMP_PAYMENT_LINKS_MAX );
			break;
		}
		$links[] = array(
			'label' => '' !== $label ? $label : __( 'Pay online', 'nonprofit-manager' ),
			'url'   => $check['url'],
			'note'  => $note,
		);
	}

	return array(
		'links'  => $links,
		'errors' => $errors,
	);
}

/**
 * The saved payment links, each one checked again on the way out.
 *
 * @return array[] Each with label, url and note.
 */
function npmp_get_payment_links() {
	$saved = get_option( 'npmp_payment_links', array() );
	$links = array();
	foreach ( is_array( $saved ) ? $saved : array() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$check = npmp_payment_link_validate_template( (string) ( $row['url'] ?? '' ) );
		if ( '' === $check['url'] ) {
			continue;
		}
		$links[] = array(
			'label' => (string) ( $row['label'] ?? '' ),
			'url'   => $check['url'],
			'note'  => (string) ( $row['note'] ?? '' ),
		);
	}
	return array_slice( $links, 0, NPMP_PAYMENT_LINKS_MAX );
}

/* ==============================================================
 * Prefill
 * ============================================================= */

/**
 * Days a newsletter's prefill token works.
 */
const NPMP_DONOR_TOKEN_DAYS = 90;

/**
 * Base64 for a URL, without padding.
 *
 * @param string $data Bytes.
 * @return string
 */
function npmp_donor_b64url( $data ) {
	return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token, not obfuscation.
}

/**
 * The signature over one token payload. Scoped to this use, so no other
 * token the plugin makes (unsubscribe, preferences) works here, or the
 * reverse.
 *
 * @param string $payload Address part and time part.
 * @return string
 */
function npmp_donor_token_sig( $payload ) {
	return substr( hash_hmac( 'sha256', 'donor-prefill|' . $payload, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Signed token naming one recipient, for the newsletter's Donate button.
 *
 * @param string   $email Recipient address.
 * @param int|null $now   Unix time, for tests.
 * @return string '' for a bad address.
 */
function npmp_donor_prefill_token( $email, $now = null ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email || ! is_email( $email ) ) {
		return '';
	}
	$payload = npmp_donor_b64url( $email ) . '.' . base_convert( (string) ( null === $now ? time() : (int) $now ), 10, 36 );
	return $payload . '.' . npmp_donor_token_sig( $payload );
}

/**
 * The address a prefill token names, or '' when the token is forged,
 * changed, malformed, or older than NPMP_DONOR_TOKEN_DAYS.
 *
 * @param string   $token Token from the URL.
 * @param int|null $now   Unix time, for tests.
 * @return string
 */
function npmp_donor_prefill_email( $token, $now = null ) {
	$token = (string) $token;
	if ( strlen( $token ) > 600 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([0-9a-z]+)\.([0-9a-f]{32})$/', $token, $m ) ) {
		return '';
	}
	if ( ! hash_equals( npmp_donor_token_sig( $m[1] . '.' . $m[2] ), $m[3] ) ) {
		return '';
	}
	$now    = null === $now ? time() : (int) $now;
	$issued = (int) base_convert( $m[2], 36, 10 );
	if ( $issued > $now + HOUR_IN_SECONDS || $now - $issued > NPMP_DONOR_TOKEN_DAYS * DAY_IN_SECONDS ) {
		return '';
	}
	$email = base64_decode( strtr( $m[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token, not obfuscation.
	return ( is_string( $email ) && is_email( $email ) ) ? $email : '';
}

/**
 * Name and email to fill in on a donation form.
 *
 * A logged-in user gets their own. Anyone else gets them only from a
 * npmp_donor token that passes npmp_donor_prefill_email(). An email address
 * in the URL on its own is never read.
 *
 * @return array{name:string,email:string}
 */
function npmp_donation_prefill() {
	static $cached = null;
	if ( ! empty( $GLOBALS['npmp_donation_prefill_reset'] ) ) {
		$cached = null;
		unset( $GLOBALS['npmp_donation_prefill_reset'] );
	}
	if ( null !== $cached ) {
		return $cached;
	}
	$cached = array(
		'name'  => '',
		'email' => '',
	);

	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		$user = wp_get_current_user();
		$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		if ( '' === $name ) {
			$name = (string) $user->display_name;
		}
		$cached = array(
			'name'  => $name,
			'email' => (string) $user->user_email,
		);
		return $cached;
	}

	if ( ! isset( $_GET['npmp_donor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token, checked below.
		return $cached;
	}
	$email = npmp_donor_prefill_email( sanitize_text_field( wp_unslash( $_GET['npmp_donor'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $email ) {
		return $cached;
	}

	// This page now carries one reader's address. Keep it out of page caches.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	$name = '';
	if ( class_exists( 'NPMP_Member_Manager' ) ) {
		$contact = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
		$name    = $contact && isset( $contact->name ) ? (string) $contact->name : '';
	}
	$cached = array(
		'name'  => $name,
		'email' => $email,
	);
	return $cached;
}

/**
 * Forget the cached prefill. For the rig, which changes users mid-request.
 */
function npmp_donation_prefill_reset() {
	// The static lives in npmp_donation_prefill(), so call it with a flag.
	$GLOBALS['npmp_donation_prefill_reset'] = true;
}

/* ==============================================================
 * Donation form inside an email
 * ============================================================= */

/**
 * The page an email's Donate button opens: the donation page picked on the
 * Donation Settings screen, or else the first published page that holds the
 * donation form. A page named for giving (npmp_form_page_is_clear()) wins
 * over one that only holds the form. A page that only mentions the shortcode
 * in its text never counts. Until 2026.11.21 the first page
 * whose text mentioned it won.
 *
 * @return string '' when the site has no such page.
 */
function npmp_donation_email_target_url() {
	$page_id = absint( get_option( 'npmp_donation_page_id' ) );
	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		return (string) get_permalink( $page_id );
	}

	$candidates = npmp_form_page_candidates( 'donation' );
	$found      = npmp_form_page_pick( $candidates, 'donation' );
	if ( ! $found ) {
		// No page named for giving: the first page that holds the real form.
		foreach ( $candidates as $candidate ) {
			if ( npmp_form_page_has_form( $candidate->post_content, 'donation' ) ) {
				$found = (int) $candidate->ID;
				break;
			}
		}
	}
	return $found ? (string) get_permalink( (int) $found ) : '';
}

/**
 * A Donate button for an email: a table cell with inline styles, which every
 * mail app draws, including Apple Mail and Thunderbird.
 *
 * @param string $url     Where the button goes.
 * @param string $label   Button text.
 * @param bool   $escaped True when $url is already escaped for an attribute.
 * @return string
 */
function npmp_email_donate_button_html( $url, $label, $escaped = false ) {
	return '<table role="presentation" class="npmp-email-donate" border="0" cellpadding="0" cellspacing="0" style="margin:16px 0;border-collapse:separate;">'
		. '<tr><td align="center" bgcolor="#0f766e" style="background-color:#0f766e;border-radius:8px;">'
		. '<a href="' . ( $escaped ? $url : esc_url( $url ) ) . '" target="_blank" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;line-height:1.2;color:#ffffff;background-color:#0f766e;border-radius:8px;text-decoration:none;">'
		. esc_html( $label )
		. '</a></td></tr></table>';
}

/**
 * The donation form block or shortcode as it renders inside an email.
 *
 * The link carries [npmp_donor_key], which a newsletter send fills with the
 * reader's signed token (npmp_email_recipient_tags), so the form opens with
 * their email filled in. Mail that never fills tags has the leftover taken
 * out at send time.
 *
 * @return string '' when the site has no donation page to link to.
 */
function npmp_donation_email_button() {
	$url = npmp_donation_email_target_url();
	if ( '' === $url ) {
		return '';
	}
	$opts = npmp_get_donation_form_options();
	// esc_url() percent-encodes the brackets, and a newsletter would then
	// never find the tag. The tag holds only letters and an underscore, and
	// the token that replaces it only URL-safe characters, so it goes on
	// after the escape.
	$href = esc_url( $url ) . ( false === strpos( $url, '?' ) ? '?' : '&#038;' ) . 'npmp_donor=[npmp_donor_key]';
	return npmp_email_donate_button_html( $href, $opts['btn_lbl'], true );
}

/**
 * Fill [npmp_donor_key] for each newsletter reader.
 *
 * @param array  $values  Tag => value.
 * @param mixed  $contact Contact record or null.
 * @param string $email   Recipient address, '' on the public archive.
 * @return array
 */
function npmp_donation_recipient_tag( $values, $contact = null, $email = '' ) {
	$values                   = is_array( $values ) ? $values : array();
	$values['npmp_donor_key'] = '' !== (string) $email ? npmp_donor_prefill_token( $email ) : '';
	return $values;
}
add_filter( 'npmp_email_recipient_tags', 'npmp_donation_recipient_tag', 10, 3 );

/**
 * Take an unfilled [npmp_donor_key] out of plugin mail that never fills
 * newsletter tags (an event email's test send, a Pro automation), so the
 * link ends in a plain npmp_donor= instead of the tag.
 *
 * @param string $html Message body.
 * @return string
 */
function npmp_donation_strip_unfilled_key( $html ) {
	return str_replace( array( 'npmp_donor=[npmp_donor_key]', 'npmp_donor=%5Bnpmp_donor_key%5D', 'npmp_donor=%5bnpmp_donor_key%5d' ), 'npmp_donor=', (string) $html );
}

/**
 * Run npmp_donation_strip_unfilled_key() on mail the plugin sends.
 *
 * @param array $args wp_mail() arguments.
 * @return array
 */
function npmp_donation_mail_strip_key( $args ) {
	if ( function_exists( 'npmp_is_internal_mail_send' ) && npmp_is_internal_mail_send() && isset( $args['message'] ) && is_string( $args['message'] ) ) {
		$args['message'] = npmp_donation_strip_unfilled_key( $args['message'] );
	}
	return $args;
}
add_filter( 'wp_mail', 'npmp_donation_mail_strip_key' );
