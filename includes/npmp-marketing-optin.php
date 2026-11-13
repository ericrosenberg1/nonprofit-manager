<?php
/**
 * File path: includes/npmp-marketing-optin.php
 *
 * Newsletter opt-in for the setup wizard. Off by default, same posture as
 * the "Powered by" attribution in npmp-powered-by.php: the site owner must
 * check a box before their email leaves the site.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_NEWSLETTER_LIST_UUID' ) ) {
	define( 'NPMP_NEWSLETTER_LIST_UUID', '29c25183-c498-4cf0-8c52-2a7c976a6984' );
}

// Everything Nonprofit Manager runs on the nonprofitmanager.app domain. Until
// 2026.11.4 it used listmonk.ericrosenberg.com, a host that never resolved,
// so every opt-in failed.
if ( ! defined( 'NPMP_NEWSLETTER_SUBSCRIBE_URL' ) ) {
	define( 'NPMP_NEWSLETTER_SUBSCRIBE_URL', 'https://news.nonprofitmanager.app/api/public/subscription' );
}

if ( ! function_exists( 'npmp_newsletter_subscribe_request' ) ) {
	/**
	 * Build the JSON body for listmonk's public subscription endpoint.
	 *
	 * Listmonk wants a name. When the site owner has no display name, the
	 * part of the address before the @ stands in, the same fallback
	 * listmonk's own form uses.
	 *
	 * @param string $email Sanitized, valid address.
	 * @param string $name  Display name, may be empty.
	 * @return array
	 */
	function npmp_newsletter_subscribe_request( $email, $name = '' ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			$name = (string) strstr( $email, '@', true );
		}

		return array(
			'email'      => $email,
			'name'       => $name,
			'list_uuids' => array( NPMP_NEWSLETTER_LIST_UUID ),
		);
	}
}

if ( ! function_exists( 'npmp_newsletter_subscribe_outcome' ) ) {
	/**
	 * Read listmonk's answer. Only a 2xx JSON reply with a truthy "data"
	 * counts as accepted, so a parked domain, captive portal, or proxy
	 * error page answering 200 with HTML reads as the failure it is.
	 *
	 * @param array|WP_Error $response Result of wp_remote_post().
	 * @return true|string True when accepted, otherwise a short reason for the log.
	 */
	function npmp_newsletter_subscribe_outcome( $response ) {
		if ( is_wp_error( $response ) ) {
			return 'request failed: ' . $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ) ? ': ' . $body['message'] : '';
			return 'HTTP ' . $code . $message;
		}

		if ( ! is_array( $body ) || empty( $body['data'] ) ) {
			return 'HTTP ' . $code . ' without a listmonk reply';
		}

		return true;
	}
}

if ( ! function_exists( 'npmp_subscribe_to_newsletter' ) ) {
	/**
	 * Subscribe an email to the Nonprofit Manager newsletter list.
	 *
	 * Hits listmonk's public (unauthenticated) subscription endpoint, so no
	 * API credential lives in this plugin. Double opt-in on the list means
	 * the address only ever receives one confirmation email unless the site
	 * owner clicks it, so a mistaken or throwaway email costs nothing.
	 *
	 * Best-effort: a network failure here must never block the setup wizard
	 * or any other caller. The caller gets the result so it can tell the
	 * site owner, and failures also go to the PHP error log.
	 *
	 * @param string $email Address to subscribe.
	 * @param string $name  Optional display name.
	 * @return bool True if listmonk accepted the signup, false otherwise.
	 */
	function npmp_subscribe_to_newsletter( $email, $name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$response = wp_remote_post(
			NPMP_NEWSLETTER_SUBSCRIBE_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( npmp_newsletter_subscribe_request( $email, $name ) ),
			)
		);

		$outcome = npmp_newsletter_subscribe_outcome( $response );
		if ( true !== $outcome ) {
			error_log( 'Nonprofit Manager: newsletter opt-in to ' . wp_parse_url( NPMP_NEWSLETTER_SUBSCRIBE_URL, PHP_URL_HOST ) . ' failed, ' . $outcome ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}
}
