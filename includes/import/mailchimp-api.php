<?php
/**
 * File path: includes/import/mailchimp-api.php
 *
 * Minimal Mailchimp v3 API client for importing members.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get available Mailchimp lists (audiences).
 *
 * @param string $api_key Mailchimp API key.
 * @return array|WP_Error Array of lists: [ { id, name, member_count } ].
 */
function npmp_mailchimp_get_lists( $api_key ) {
	$dc = npmp_mailchimp_datacenter( $api_key );
	if ( is_wp_error( $dc ) ) {
		return $dc;
	}

	$url = sprintf( 'https://%s.api.mailchimp.com/3.0/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count', $dc );

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'npmp_mc_request', __( 'Could not connect to Mailchimp. Check your API key and try again.', 'nonprofit-manager' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$detail = isset( $body['detail'] ) ? $body['detail'] : __( 'Unknown error', 'nonprofit-manager' );
		return new WP_Error(
			'npmp_mc_api',
			sprintf(
				/* translators: %s: API error detail */
				__( 'Mailchimp API error: %s', 'nonprofit-manager' ),
				$detail
			)
		);
	}

	$lists = array();
	if ( ! empty( $body['lists'] ) ) {
		foreach ( $body['lists'] as $list ) {
			$lists[] = array(
				'id'           => $list['id'],
				'name'         => $list['name'],
				'member_count' => isset( $list['stats']['member_count'] ) ? (int) $list['stats']['member_count'] : 0,
			);
		}
	}

	return $lists;
}

/**
 * Get members from a Mailchimp list with pagination.
 *
 * @param string $api_key Mailchimp API key.
 * @param string $list_id List/audience ID.
 * @param int    $offset  Pagination offset.
 * @param int    $count   Number of members per page (max 1000).
 * @return array|WP_Error { members: [], total_items: int }
 */
function npmp_mailchimp_get_members( $api_key, $list_id, $offset = 0, $count = 100 ) {
	$dc = npmp_mailchimp_datacenter( $api_key );
	if ( is_wp_error( $dc ) ) {
		return $dc;
	}

	$url = sprintf(
		'https://%s.api.mailchimp.com/3.0/lists/%s/members?offset=%d&count=%d&fields=members.email_address,members.merge_fields,members.status,members.tags,total_items',
		$dc,
		rawurlencode( $list_id ),
		$offset,
		min( $count, 1000 )
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'npmp_mc_request', __( 'Could not fetch members from Mailchimp.', 'nonprofit-manager' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$detail = isset( $body['detail'] ) ? $body['detail'] : __( 'Unknown error', 'nonprofit-manager' );
		return new WP_Error(
			'npmp_mc_api',
			sprintf(
				/* translators: %s: API error detail */
				__( 'Mailchimp API error: %s', 'nonprofit-manager' ),
				$detail
			)
		);
	}

	return array(
		'members'     => isset( $body['members'] ) ? $body['members'] : array(),
		'total_items' => isset( $body['total_items'] ) ? (int) $body['total_items'] : 0,
	);
}

/**
 * Get the merge-field schema for a Mailchimp list (audience).
 *
 * Standard fields (FNAME / LNAME / PHONE / ADDRESS / BIRTHDAY) ship on every
 * audience; the org may also have any number of custom merge tags. The preview
 * step calls this so the column-to-NPM-field mapping dropdowns can list real
 * field names instead of guessed-from-data positions.
 *
 * @param string $api_key Mailchimp API key.
 * @param string $list_id Audience ID.
 * @return array|WP_Error Array of { tag, name, type, required } or WP_Error.
 */
function npmp_mailchimp_get_merge_fields( $api_key, $list_id ) {
	$dc = npmp_mailchimp_datacenter( $api_key );
	if ( is_wp_error( $dc ) ) {
		return $dc;
	}

	$url = sprintf(
		'https://%s.api.mailchimp.com/3.0/lists/%s/merge-fields?count=100&fields=merge_fields.tag,merge_fields.name,merge_fields.type,merge_fields.required',
		$dc,
		rawurlencode( $list_id )
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'npmp_mc_request', __( 'Could not load Mailchimp merge fields.', 'nonprofit-manager' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$detail = isset( $body['detail'] ) ? $body['detail'] : __( 'Unknown error', 'nonprofit-manager' );
		return new WP_Error(
			'npmp_mc_api',
			sprintf(
				/* translators: %s: API error detail */
				__( 'Mailchimp merge-fields error: %s', 'nonprofit-manager' ),
				$detail
			)
		);
	}

	$fields = array();
	if ( ! empty( $body['merge_fields'] ) && is_array( $body['merge_fields'] ) ) {
		foreach ( $body['merge_fields'] as $mf ) {
			$fields[] = array(
				'tag'      => isset( $mf['tag'] ) ? (string) $mf['tag'] : '',
				'name'     => isset( $mf['name'] ) ? (string) $mf['name'] : '',
				'type'     => isset( $mf['type'] ) ? (string) $mf['type'] : 'text',
				'required' => ! empty( $mf['required'] ),
			);
		}
	}

	return $fields;
}

/**
 * Suggest an NPM field for a Mailchimp merge tag, when the tag name is a
 * recognized standard. Returns '' for unknown tags; the UI defaults those to
 * "Skip" and lets the user pick.
 *
 * @param string $tag  Merge tag (e.g. "FNAME").
 * @param string $name Human label, unused today but kept for future heuristics.
 * @return string NPM field key, or '' for no confident mapping.
 */
function npmp_mailchimp_suggest_field( $tag, $name = '' ) {
	$probe = strtoupper( trim( $tag ) );

	$first_name = array( 'FNAME', 'FIRSTNAME', 'FIRST_NAME', 'GIVENNAME', 'GIVEN_NAME', 'FIRST' );
	$last_name  = array( 'LNAME', 'LASTNAME', 'LAST_NAME', 'SURNAME', 'FAMILYNAME', 'FAMILY_NAME', 'LAST' );
	$phone      = array( 'PHONE', 'MOBILE', 'CELL', 'TEL', 'TELEPHONE' );

	if ( in_array( $probe, $first_name, true ) ) {
		return 'first_name';
	}
	if ( in_array( $probe, $last_name, true ) ) {
		return 'last_name';
	}
	if ( in_array( $probe, $phone, true ) ) {
		return 'phone';
	}
	if ( 'ADDRESS' === $probe ) {
		return 'address_line1';
	}

	return '';
}

/**
 * Extract the data center from a Mailchimp API key.
 *
 * A valid key ends with a dash and the data center slug, e.g. ...-us21.
 *
 * @param string $api_key API key.
 * @return string|WP_Error Data center slug (e.g. "us21").
 */
function npmp_mailchimp_datacenter( $api_key ) {
	$api_key = trim( $api_key );
	if ( empty( $api_key ) || false === strpos( $api_key, '-' ) ) {
		return new WP_Error( 'npmp_mc_key', __( 'Invalid Mailchimp API key format. The key should end with a dash and data center (e.g., -us21).', 'nonprofit-manager' ) );
	}

	$parts = explode( '-', $api_key );
	return sanitize_key( end( $parts ) );
}
