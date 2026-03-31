<?php
/**
 * File path: includes/social-sharing/networks/facebook.php
 *
 * Facebook Page sharing via the Graph API.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Share a post to a Facebook Page.
 *
 * Credentials required:
 *   - page_access_token : Long-lived Page Access Token.
 *
 * @param null|true|WP_Error $result      Previous result (unused).
 * @param array              $share_data  Post data including 'text', 'url', 'image_url'.
 * @param array              $credentials Stored credentials.
 * @return true|WP_Error
 */
function npmp_social_share_facebook( $result, $share_data, $credentials ) {
	if ( empty( $credentials['page_access_token'] ) ) {
		return new WP_Error( 'npmp_fb_no_token', __( 'Facebook Page Access Token is missing.', 'nonprofit-manager' ) );
	}

	$body = array(
		'message'      => $share_data['text'],
		'link'         => $share_data['url'],
		'access_token' => $credentials['page_access_token'],
	);

	$response = wp_remote_post(
		'https://graph.facebook.com/v19.0/me/feed',
		array(
			'timeout' => 30,
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown Facebook API error.', 'nonprofit-manager' );
		return new WP_Error( 'npmp_fb_api_error', $msg );
	}

	return true;
}
add_filter( 'npmp_social_share_facebook_page', 'npmp_social_share_facebook', 10, 3 );

/**
 * Return the credential fields needed by the Facebook Page network.
 *
 * @param array $fields Existing fields.
 * @return array
 */
function npmp_social_facebook_fields( $fields ) {
	$fields['facebook_page'] = array(
		array(
			'key'         => 'page_access_token',
			'label'       => __( 'Page Access Token', 'nonprofit-manager' ),
			'type'        => 'password',
			'description' => __( 'A long-lived Page Access Token from the Facebook Developer Console.', 'nonprofit-manager' ),
		),
	);
	return $fields;
}
add_filter( 'npmp_social_credential_fields', 'npmp_social_facebook_fields' );
