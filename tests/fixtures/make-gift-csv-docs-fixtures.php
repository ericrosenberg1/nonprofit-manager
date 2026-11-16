<?php
/**
 * Writes tests/fixtures/gift-csv-*-docs.csv. Run: php tests/fixtures/make-gift-csv-docs-fixtures.php
 *
 * Headers come only from vendor pages, never from memory:
 * - Givebutter, all 88 named columns of the default "All Transactions" export plus one
 *   custom-field column, from https://help.givebutter.com/en/articles/2219206
 *   (read 2026-11-16). The page says custom fields add a varying number of columns,
 *   names unpublished, so "T-Shirt Size" is a stand-in. Cell formats are not
 *   published either; the dates below use two shapes to cover both.
 * - Zeffy, the Payments export order Amount, Payment method, Payment status,
 *   Payout date, Donation, Refunded amount, Discount, then buyer details, from
 *   https://support.zeffy.com/export-or-download-all-payments-or-tickets-associated-with-a-specific-form-u0tyj
 *   (read 2026-11-16). The page names no buyer column and no payment date column,
 *   so the buyer columns use the names of Zeffy's own import template
 *   (https://support.zeffy.com/importing-historical-payments-into-zeffy-9jqar:
 *   First name, Last name, Email, Date, Address, Postal code, City, Region, Country).
 * - Donorbox publishes no export header list. The Donorbox file uses the import
 *   template fields its CRM help names (first and last name, amount, currency,
 *   donation date, donation type, campaign, https://donorbox.zendesk.com/hc/en-us/articles/45496871970836)
 *   plus the Converted Amount and Converted Currency columns its blog names
 *   (https://donorbox.org/nonprofit-blog/new-features-pdf-receipt).
 */
$dir = __DIR__ . '/';
$gb_headers = array( 'Campaign Title', 'Campaign Slug', 'Team', 'Team Member', 'Reference Number', 'First Name', 'Last Name', 'Email', 'Phone', 'Address Line 1', 'Address Line 2', 'City', 'State', 'Postal Code', 'Country', 'Company', 'Status Friendly', 'Offline', 'Fund ID', 'Fund Code', 'Fund Name', 'Appeal ID', 'Appeal Name', 'Appeal Code', 'Dedication Type', 'Dedication Name', 'Dedication Recipient Name', 'Dedication Recipient Email', 'Method', 'Method Subtype', 'CC Last Four', 'CC Expiration Date', 'Discount Code', 'Amount', 'Fee', 'Fee Covered', 'Givebutter Fee Covered', 'Donated', 'Payout', 'Payout Ref', 'Currency', 'Plan ID', 'Frequency', 'Plan Start Date (UTC)', 'Check Number', 'Check Deposited (UTC)', 'Dispute Status', 'Payment Captured (UTC)', 'Refund Date (UTC)', 'Transaction Date (UTC)', 'Acknowledged', 'Hide Name', 'Hide Amount', 'Public Name', 'Public Message', "Donor's Local Timezone", "Payment Captured (Donor's Local Timezone)", 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term', 'UTM Content', 'Referrer', 'Widget Id', 'Match Name', 'Match Amount', 'External Label', 'Transaction External ID', 'Contact ID', 'Contact External ID', 'Contact First Name', 'Contact Last Name', 'Contact Preferred First Name', 'Contact Type', 'Contact Company Name', 'Contact Email', 'Contact Phone', 'Contact Address Line 1', 'Contact Address Line 2', 'Contact City', 'Contact State', 'Contact Postal Code', 'Contact Country', 'Household ID', 'Household Name', 'Soft Credits', 'Total Soft Credits', 'Internal Note', 'T-Shirt Size' );
$gb = function ( array $set ) use ( $gb_headers ) {
	$row = array_fill( 0, count( $gb_headers ), '' );
	foreach ( $set as $name => $value ) {
		$i = array_search( $name, $gb_headers, true );
		if ( false === $i ) {
			throw new Exception( 'no column ' . $name );
		}
		$row[ $i ] = $value;
	}
	return $row;
};
$base = array( 'Campaign Title' => 'Annual Fund', 'Campaign Slug' => 'annual-fund', 'Currency' => 'USD', 'Offline' => 'FALSE', 'Method' => 'Card', 'Status Friendly' => 'succeeded' );
$gb_rows = array(
	$gb( $base + array( 'Reference Number' => 'GBREF0001', 'First Name' => 'Gia', 'Last Name' => 'Fakedoc', 'Email' => 'gia@example.org', 'Amount' => '50.00', 'Fee' => '1.75', 'Donated' => '48.25', 'Transaction Date (UTC)' => '2025-03-14 14:22:09', 'Frequency' => 'single' ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0002', 'First Name' => 'Gia', 'Last Name' => 'Fakedoc', 'Email' => 'gia@example.org', 'Amount' => '25.00', 'Transaction Date (UTC)' => '04/14/2025 2:22 PM', 'Frequency' => 'monthly', 'Plan ID' => 'plan_0001', 'Plan Start Date (UTC)' => '2025-04-14' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0003', 'First Name' => 'Hal', 'Last Name' => 'Fakedoc', 'Email' => 'hal@example.org', 'Amount' => '80.00', 'Transaction Date (UTC)' => '2025-03-20 09:00:00', 'Refund Date (UTC)' => '2025-03-25 09:00:00' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0004', 'First Name' => 'Ina', 'Last Name' => 'Fakedoc', 'Email' => 'ina@example.org', 'Amount' => '30.00', 'Transaction Date (UTC)' => '2025-03-21 09:00:00', 'Status Friendly' => 'authorized' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0005', 'First Name' => 'Jo', 'Last Name' => 'Fakedoc', 'Email' => 'jo@example.org', 'Amount' => '30.00', 'Transaction Date (UTC)' => '2025-03-22 09:00:00', 'Status Friendly' => 'failed' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0006', 'First Name' => 'Kit', 'Last Name' => 'Fakedoc', 'Email' => 'kit@example.org', 'Amount' => '100.00', 'Transaction Date (UTC)' => '2025-03-23 09:00:00', 'Method' => 'Check', 'Offline' => 'TRUE', 'Check Number' => '1042', 'Check Deposited (UTC)' => '2025-03-25 00:00:00' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0007', 'First Name' => 'Lee', 'Last Name' => 'Fakedoc', 'Email' => 'lee@example.org', 'Amount' => '60.00', 'Transaction Date (UTC)' => '2025-03-24 09:00:00', 'Dispute Status' => 'lost' ) ) ),
	$gb( array_merge( $base, array( 'Reference Number' => 'GBREF0008', 'First Name' => '', 'Last Name' => '', 'Email' => '', 'Amount' => '40.00', 'Transaction Date (UTC)' => '2025-03-26 09:00:00', 'Method' => 'Cash', 'Offline' => 'TRUE', 'Contact First Name' => 'Mo', 'Contact Last Name' => 'Fakedoc', 'Contact Email' => 'mo@example.org' ) ) ),
);
$write = function ( $name, array $headers, array $rows ) use ( $dir ) {
	$h = fopen( $dir . $name, 'w' );
	fputcsv( $h, $headers, ',', '"', '' );
	foreach ( $rows as $r ) {
		fputcsv( $h, $r, ',', '"', '' );
	}
	fclose( $h );
};
$write( 'gift-csv-givebutter-docs.csv', $gb_headers, $gb_rows );

$z_headers = array( 'Amount', 'Payment method', 'Payment status', 'Payout date', 'Donation', 'Refunded amount', 'Discount', 'First name', 'Last name', 'Email', 'Date', 'Address', 'Postal code', 'City', 'Region', 'Country' );
$write( 'gift-csv-zeffy-docs.csv', $z_headers, array(
	array( '$100.00', 'Card', 'Succeeded', '03/20/2025', '$100.00', '$0.00', '$0.00', 'Ned', 'Fakedoc', 'ned@example.org', '03/14/2025', '2 Test Road', '05401', 'Burlington', 'VT', 'US' ),
	array( '$50.00', 'Card', 'Refunded', '03/21/2025', '$50.00', '$50.00', '$0.00', 'Oz', 'Fakedoc', 'oz@example.org', '03/15/2025', '', '', '', '', '' ),
	array( '$75.00', 'Bank transfer', 'Processing', '', '$75.00', '$0.00', '$0.00', 'Pam', 'Fakedoc', 'pam@example.org', '03/16/2025', '', '', '', '', '' ),
	array( '$20.00', 'Card', 'Stopped', '', '$20.00', '$0.00', '$0.00', 'Quin', 'Fakedoc', 'quin@example.org', '03/17/2025', '', '', '', '', '' ),
	array( '$1,250.00', 'Card', 'Succeeded', '03/25/2025', '$1,250.00', '$0.00', '$0.00', 'Rue', 'Fakedoc', 'rue@example.org', '03/18/2025', '', '', '', '', '' ),
) );

$d_headers = array( 'First Name', 'Last Name', 'Email', 'Amount', 'Currency', 'Converted Amount', 'Converted Currency', 'Donation Date', 'Donation Type', 'Campaign' );
$write( 'gift-csv-donorbox-docs.csv', $d_headers, array(
	array( 'Sol', 'Fakedoc', 'sol@example.org', '25.00', 'USD', '', '', '2025-03-14', 'one_time', 'General' ),
	array( 'Tam', 'Fakedoc', 'tam@example.org', '30.00', 'CAD', '22.10', 'USD', '2025-03-15', 'recurring', 'General' ),
) );
