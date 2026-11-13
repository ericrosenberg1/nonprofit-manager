<?php
/**
 * File path: includes/import/constant-contact-api.php
 *
 * Minimal Constant Contact v3 API client for importing contacts.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base URL for the Constant Contact v3 API.
 */
define( 'NPMP_CC_API_BASE', 'https://api.cc.email/v3' );

/**
 * Get available Constant Contact lists.
 *
 * @param string $access_token Bearer access token.
 * @return array|WP_Error Array of lists: [ { id, name, member_count } ].
 */
function npmp_cc_get_lists( $access_token ) {
	$url = NPMP_CC_API_BASE . '/contact_lists?include_count=true&limit=50';

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'npmp_cc_request', __( 'Could not connect to Constant Contact. Check your access token and try again.', 'nonprofit-manager' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$detail = '';
		if ( isset( $body['error_message'] ) ) {
			$detail = $body['error_message'];
		} elseif ( 401 === $code ) {
			$detail = __( 'Invalid or expired access token.', 'nonprofit-manager' );
		} else {
			$detail = __( 'Unknown error', 'nonprofit-manager' );
		}
		return new WP_Error(
			'npmp_cc_api',
			sprintf(
				/* translators: %s: API error detail */
				__( 'Constant Contact API error: %s', 'nonprofit-manager' ),
				$detail
			)
		);
	}

	$lists = array();
	if ( ! empty( $body['lists'] ) ) {
		foreach ( $body['lists'] as $list ) {
			$lists[] = array(
				'id'           => $list['list_id'],
				'name'         => $list['name'],
				'member_count' => isset( $list['membership_count'] ) ? (int) $list['membership_count'] : 0,
			);
		}
	}

	return $lists;
}

/**
 * Get contacts from a Constant Contact list with cursor-based pagination.
 *
 * @param string      $access_token Bearer access token.
 * @param string      $list_id      Contact list ID.
 * @param string|null $cursor       Pagination cursor from a previous call.
 * @return array|WP_Error { contacts: [], cursor: string|null }
 */
function npmp_cc_get_contacts( $access_token, $list_id, $cursor = null ) {
	$url = NPMP_CC_API_BASE . '/contacts';
	$url = add_query_arg(
		array(
			// No status filter: the default returns every contact except deleted
			// ones. status=all adds deleted contacts. Unsubscribed contacts still
			// come through and import as unsubscribed (cc-contact-map.php).
			'limit'         => 100,
			'include'       => 'phone_numbers,street_addresses,custom_fields',
			'lists'         => rawurlencode( $list_id ),
		),
		$url
	);

	if ( ! empty( $cursor ) ) {
		$url = add_query_arg( 'cursor', $cursor, $url );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'npmp_cc_request', __( 'Could not fetch contacts from Constant Contact.', 'nonprofit-manager' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$detail = isset( $body['error_message'] ) ? $body['error_message'] : __( 'Unknown error', 'nonprofit-manager' );
		return new WP_Error(
			'npmp_cc_api',
			sprintf(
				/* translators: %s: API error detail */
				__( 'Constant Contact API error: %s', 'nonprofit-manager' ),
				$detail
			)
		);
	}

	$next_cursor = null;
	if ( ! empty( $body['_links']['next']['href'] ) ) {
		$parsed = wp_parse_url( $body['_links']['next']['href'] );
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_args );
			$next_cursor = isset( $query_args['cursor'] ) ? $query_args['cursor'] : null;
		}
	}

	return array(
		'contacts' => isset( $body['contacts'] ) ? $body['contacts'] : array(),
		'cursor'   => $next_cursor,
	);
}

/**
 * The account's contact custom fields, for the wizard's extra columns.
 *
 * GET /contact_custom_fields (https://developer.constantcontact.com/api_guide/custom_fields_get.html)
 * returns custom_fields: [{custom_field_id, label, name, type}], where type is
 * string or date. Constant Contact allows 100 custom fields, so one page of
 * 100 holds them all. A failure here returns no fields rather than stopping
 * the import, since the contact fields still come over.
 *
 * @param string $access_token OAuth access token.
 * @return array<int,array{id:string,label:string,type:string}>
 */
function npmp_cc_get_custom_fields( $access_token ) {
	$response = wp_remote_get(
		NPMP_CC_API_BASE . '/contact_custom_fields?limit=100',
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$fields = array();
	foreach ( isset( $body['custom_fields'] ) && is_array( $body['custom_fields'] ) ? $body['custom_fields'] : array() as $cf ) {
		if ( empty( $cf['custom_field_id'] ) ) {
			continue;
		}
		$fields[] = array(
			'id'    => (string) $cf['custom_field_id'],
			'label' => (string) ( ! empty( $cf['label'] ) ? $cf['label'] : ( $cf['name'] ?? $cf['custom_field_id'] ) ),
			'type'  => 'date' === strtolower( (string) ( $cf['type'] ?? '' ) ) ? 'date' : 'text',
		);
	}
	return $fields;
}
