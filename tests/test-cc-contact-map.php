<?php
/**
 * Constant Contact v3 records flattened for the import wizard
 * (includes/import/cc-contact-map.php).
 *
 * Until 2026.09.20 the importer read the v2 `email_addresses` array, so every
 * v3 contact arrived with an empty email and failed validation, and nothing
 * read permission_to_send, so opt-outs would have imported as subscribed.
 *
 * Run: php tests/test-cc-contact-map.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/import/cc-contact-map.php';

// Shape from GET /v3/contacts?include=phone_numbers,street_addresses.
$v3 = array(
	'contact_id'       => '1618ae62-4752-11e9-9c8a-fa163e6b01c1',
	'email_address'    => array(
		'address'            => ' dlang@example.com ',
		'permission_to_send' => 'implicit',
	),
	'first_name'       => 'David',
	'last_name'        => 'Lang',
	'phone_numbers'    => array( array( 'phone_number' => '555-555-0100', 'kind' => 'home' ) ),
	'street_addresses' => array(
		array(
			'kind'        => 'home',
			'street'      => '123 Kashmir Valley Road',
			'city'        => 'Ventura',
			'state'       => 'CA',
			'postal_code' => '93003',
			'country'     => 'United States',
		),
	),
);

$row = npmp_cc_contact_row( $v3 );
check( 'v3: email read from email_address object, trimmed', 'dlang@example.com', $row['email_address'] );
check( 'v3: first name', 'David', $row['first_name'] );
check( 'v3: last name', 'Lang', $row['last_name'] );
check( 'v3: phone', '555-555-0100', $row['phone'] );
check( 'v3: street', '123 Kashmir Valley Road', $row['address_line1'] );
check( 'v3: city', 'Ventura', $row['city'] );
check( 'v3: postal code', '93003', $row['postal_code'] );
check( 'v3: implicit consent imports as subscribed', 'subscribed', $row['status'] );

$statuses = array(
	'explicit'             => 'subscribed',
	'implicit'             => 'subscribed',
	'unsubscribed'         => 'unsubscribed',
	'not_set'              => 'unsubscribed',
	'pending_confirmation' => 'pending',
	'temp_hold'            => 'pending',
	'Unsubscribed'         => 'unsubscribed',
	'something_new'        => '',
	''                     => '',
);
foreach ( $statuses as $permission => $expected ) {
	check( "permission_to_send '$permission'", $expected, npmp_cc_permission_to_status( $permission ) );
}

$unsub                                       = $v3;
$unsub['email_address']['permission_to_send'] = 'unsubscribed';
check( 'an opted-out contact never imports as subscribed', 'unsubscribed', npmp_cc_contact_row( $unsub )['status'] );

$v2 = array( 'email_addresses' => array( array( 'address' => 'old@example.com' ) ) );
check( 'v2-shaped record still yields its email', 'old@example.com', npmp_cc_contact_email( $v2 ) );

$bare = npmp_cc_contact_row( array() );
check( 'empty record: no email', '', $bare['email_address'] );
check( 'empty record: status left to the default', '', $bare['status'] );
check( 'empty record: no address keys', false, isset( $bare['address_line1'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
