<?php
/**
 * Constant Contact v3 contact records, flattened for the import wizard.
 *
 * Pure PHP with no WordPress calls, so tests/test-cc-contact-map.php can pin it.
 * The v3 API returns one `email_address` object per contact, with the opt-in
 * state in `email_address.permission_to_send`. The importer used to read an
 * `email_addresses` array (the v2 shape), so every contact arrived with an
 * empty email and failed validation, and opt-outs were never read.
 *
 * File path: includes/import/cc-contact-map.php
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_cc_contact_email' ) ) {
	/**
	 * The contact's email address.
	 *
	 * @param array $contact One contact from GET /v3/contacts.
	 * @return string Address, or '' when the record has none.
	 */
	function npmp_cc_contact_email( $contact ) {
		if ( isset( $contact['email_address']['address'] ) && is_string( $contact['email_address']['address'] ) ) {
			return trim( $contact['email_address']['address'] );
		}
		// v2-shaped records, kept so an older payload still imports.
		if ( isset( $contact['email_addresses'][0]['address'] ) && is_string( $contact['email_addresses'][0]['address'] ) ) {
			return trim( $contact['email_addresses'][0]['address'] );
		}
		return '';
	}
}

if ( ! function_exists( 'npmp_cc_permission_to_status' ) ) {
	/**
	 * Map Constant Contact's permission_to_send to a member status.
	 *
	 * Mirrors the Mailchimp mapping: consent becomes subscribed, anything that
	 * means "don't email them" becomes unsubscribed, and contacts still waiting
	 * to confirm stay pending. Unknown values return '' so the import falls back
	 * to the status the site owner picked.
	 *
	 * @param string $permission permission_to_send value.
	 * @return string subscribed, unsubscribed, pending, or ''.
	 */
	function npmp_cc_permission_to_status( $permission ) {
		$permission = is_string( $permission ) ? strtolower( trim( $permission ) ) : '';
		$map        = array(
			'explicit'             => 'subscribed',
			'implicit'             => 'subscribed',
			'unsubscribed'         => 'unsubscribed',
			'not_set'              => 'unsubscribed',
			'pending_confirmation' => 'pending',
			'temp_hold'            => 'pending',
		);
		return isset( $map[ $permission ] ) ? $map[ $permission ] : '';
	}
}

if ( ! function_exists( 'npmp_cc_contact_row' ) ) {
	/**
	 * Flatten one contact into the named-row shape the import manager expects.
	 *
	 * @param array $contact One contact from GET /v3/contacts.
	 * @return array Named row.
	 */
	function npmp_cc_contact_row( $contact ) {
		$permission = isset( $contact['email_address']['permission_to_send'] ) ? $contact['email_address']['permission_to_send'] : '';

		$row = array(
			'email_address' => npmp_cc_contact_email( $contact ),
			'first_name'    => isset( $contact['first_name'] ) ? (string) $contact['first_name'] : '',
			'last_name'     => isset( $contact['last_name'] ) ? (string) $contact['last_name'] : '',
			'phone'         => '',
			'status'        => npmp_cc_permission_to_status( $permission ),
			'tags'          => '',
		);

		if ( isset( $contact['phone_numbers'][0]['phone_number'] ) ) {
			$row['phone'] = (string) $contact['phone_numbers'][0]['phone_number'];
		}

		if ( ! empty( $contact['street_addresses'][0] ) && is_array( $contact['street_addresses'][0] ) ) {
			$addr                 = $contact['street_addresses'][0];
			$row['address_line1'] = isset( $addr['street'] ) ? (string) $addr['street'] : '';
			$row['city']          = isset( $addr['city'] ) ? (string) $addr['city'] : '';
			$row['state']         = isset( $addr['state'] ) ? (string) $addr['state'] : '';
			$row['postal_code']   = isset( $addr['postal_code'] ) ? (string) $addr['postal_code'] : '';
			$row['country']       = isset( $addr['country'] ) ? (string) $addr['country'] : '';
		}

		return $row;
	}
}
