<?php
/**
 * File path: includes/social-sharing/networks/x-twitter.php
 *
 * X (Twitter) sharing via the v2 API with OAuth 1.0a.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Share a post to X (Twitter).
 *
 * Credentials required:
 *   - api_key            : Consumer / API Key.
 *   - api_secret         : Consumer / API Secret.
 *   - access_token       : User Access Token.
 *   - access_token_secret: User Access Token Secret.
 *
 * @param null|true|WP_Error $result      Previous result (unused).
 * @param array              $share_data  Post data including 'text'.
 * @param array              $credentials Stored credentials.
 * @return true|WP_Error
 */
function npmp_social_share_x( $result, $share_data, $credentials ) {
	$required = array( 'api_key', 'api_secret', 'access_token', 'access_token_secret' );
	foreach ( $required as $key ) {
		if ( empty( $credentials[ $key ] ) ) {
			/* translators: %s: credential key name */
			return new WP_Error( 'npmp_x_missing_cred', sprintf( __( 'X (Twitter) credential "%s" is missing.', 'nonprofit-manager' ), $key ) );
		}
	}

	$url    = 'https://api.x.com/2/tweets';
	$method = 'POST';
	$text   = mb_substr( $share_data['text'], 0, 280 );

	$oauth_params = array(
		'oauth_consumer_key'     => $credentials['api_key'],
		'oauth_nonce'            => wp_generate_password( 32, false ),
		'oauth_signature_method' => 'HMAC-SHA256',
		'oauth_timestamp'        => (string) time(),
		'oauth_token'            => $credentials['access_token'],
		'oauth_version'          => '1.0',
	);

	// Build signature base string.
	$base_params = $oauth_params;
	ksort( $base_params );

	$param_string = '';
	foreach ( $base_params as $k => $v ) {
		$param_string .= rawurlencode( $k ) . '=' . rawurlencode( $v ) . '&';
	}
	$param_string = rtrim( $param_string, '&' );

	$base_string = $method . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );

	$signing_key = rawurlencode( $credentials['api_secret'] ) . '&' . rawurlencode( $credentials['access_token_secret'] );
	$signature   = base64_encode( hash_hmac( 'sha256', $base_string, $signing_key, true ) );

	$oauth_params['oauth_signature'] = $signature;

	// Build Authorization header.
	$header_parts = array();
	foreach ( $oauth_params as $k => $v ) {
		$header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
	}
	$auth_header = 'OAuth ' . implode( ', ', $header_parts );

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => $auth_header,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'text' => $text ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 201 !== $code && 200 !== $code ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = isset( $data['detail'] ) ? $data['detail'] : __( 'Unknown X API error.', 'nonprofit-manager' );
		return new WP_Error( 'npmp_x_api_error', $msg );
	}

	return true;
}
add_filter( 'npmp_social_share_x_twitter', 'npmp_social_share_x', 10, 3 );

/**
 * Return the credential fields needed by the X (Twitter) network.
 *
 * @param array $fields Existing fields.
 * @return array
 */
function npmp_social_x_fields( $fields ) {
	$fields['x_twitter'] = array(
		array(
			'key'         => 'api_key',
			'label'       => __( 'API Key', 'nonprofit-manager' ),
			'type'        => 'password',
			'description' => __( 'Also called Consumer Key.', 'nonprofit-manager' ),
		),
		array(
			'key'         => 'api_secret',
			'label'       => __( 'API Secret', 'nonprofit-manager' ),
			'type'        => 'password',
			'description' => __( 'Also called Consumer Secret.', 'nonprofit-manager' ),
		),
		array(
			'key'         => 'access_token',
			'label'       => __( 'Access Token', 'nonprofit-manager' ),
			'type'        => 'password',
			'description' => '',
		),
		array(
			'key'         => 'access_token_secret',
			'label'       => __( 'Access Token Secret', 'nonprofit-manager' ),
			'type'        => 'password',
			'description' => '',
		),
	);
	return $fields;
}
add_filter( 'npmp_social_credential_fields', 'npmp_social_x_fields' );
