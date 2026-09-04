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

if ( ! defined( 'NPMP_NEWSLETTER_SUBSCRIBE_URL' ) ) {
	define( 'NPMP_NEWSLETTER_SUBSCRIBE_URL', 'https://listmonk.ericrosenberg.com/api/public/subscription' );
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
	 * or any other caller. Failures are logged, not surfaced to the user.
	 *
	 * @param string $email Address to subscribe.
	 * @param string $name  Optional display name.
	 * @return bool True if the request was accepted (2xx), false otherwise.
	 */
	function npmp_subscribe_to_newsletter( $email, $name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}

		$response = wp_remote_post(
			NPMP_NEWSLETTER_SUBSCRIBE_URL,
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'email'      => $email,
						'name'       => $name,
						'list_uuids' => array( NPMP_NEWSLETTER_LIST_UUID ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'Nonprofit Manager: newsletter opt-in request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'Nonprofit Manager: newsletter opt-in returned HTTP ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}
}
