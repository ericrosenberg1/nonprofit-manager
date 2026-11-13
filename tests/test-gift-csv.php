<?php
/**
 * Past gifts from a file (includes/import/gift-csv-parse.php): presets,
 * amounts, currencies, dates, statuses, refunds, recurring rows, anonymous
 * donors, duplicates, a byte order mark, quoted newlines, other delimiters
 * and encodings, the check pass on the fixtures in tests/fixtures/, and a
 * 50,000-row file.
 *
 * Run: php tests/test-gift-csv.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );
require_once $root . '/includes/npmp-currency.php';
require_once $root . '/includes/import/past-gifts.php';
require_once $root . '/includes/import/gift-csv-parse.php';

$fixtures = __DIR__ . '/fixtures/';
$tmp      = sys_get_temp_dir() . '/npmp-gift-csv-test-' . getmypid() . '/';
@mkdir( $tmp ); // phpcs:ignore

// ---------------------------------------------------------------------
// Amounts
// ---------------------------------------------------------------------
$amount = function ( $raw, $hint = '' ) {
	return npmp_gift_csv_parse_amount( $raw, $hint )['amount'];
};
check( 'plain amount', '25.00', $amount( '25.00' ) );
check( 'dollar sign', '25.00', $amount( '$25.00' ) );
check( 'thousands comma', '1250.00', $amount( '1,250.00' ) );
check( 'dollar and thousands', '2500.00', $amount( '$2,500.00' ) );
check( 'millions', '1234567.89', $amount( '1,234,567.89' ) );
check( 'euro decimal comma', '1234.56', $amount( '€1.234,56' ) );
check( 'euro after with space', '1234.56', $amount( '1.234,56 €' ) );
check( 'decimal comma alone', '25.50', $amount( '25,50' ) );
check( 'space thousands, decimal comma', '1000.00', $amount( '1 000,00' ) );
check( 'no-break space thousands', '1000.00', $amount( "1\u{00A0}000,00" ) );
check( 'narrow no-break space thousands', '1000.00', $amount( "1\u{202F}000,00 kr" ) );
check( 'Swiss apostrophe', '1234.50', $amount( "CHF 1'234.50" ) );
check( '1,234 in dollars is thousands', '1234', $amount( '1,234', 'USD' ) );
check( '1.234 in euros is thousands', '1234', $amount( '1.234', 'EUR' ) );
check( '1,234 in euros is a decimal', '1.234', $amount( '1,234', 'EUR' ) );
check( 'yen comma is thousands', '5000', $amount( '¥5,000' ) );
check( 'yen point is thousands', '5000', $amount( '5.000', 'JPY' ) );
check( 'many points is thousands', '1234567', $amount( '1.234.567' ) );
check( 'trailing code', '25.00', $amount( '25.00 USD' ) );
check( 'empty is empty', '', $amount( '' ) );
check( 'text is empty', '', $amount( 'n/a' ) );
check( 'negative is flagged', true, npmp_gift_csv_parse_amount( '-25.00' )['negative'] );
check( 'brackets are negative', true, npmp_gift_csv_parse_amount( '(25.00)' )['negative'] );
check( 'positive is not flagged', false, npmp_gift_csv_parse_amount( '25.00' )['negative'] );
check( 'leading zeros go', '5.00', $amount( '005.00' ) );
check( 'point only', '0.50', $amount( '.50' ) );

// ---------------------------------------------------------------------
// Currencies
// ---------------------------------------------------------------------
$cur = function ( $raw ) {
	return npmp_gift_csv_parse_amount( $raw )['currency'];
};
check( 'euro sign', 'EUR', $cur( '€5' ) );
check( 'pound sign', 'GBP', $cur( '£12.50' ) );
check( 'yen sign', 'JPY', $cur( '¥500' ) );
check( 'zloty', 'PLN', $cur( '12,00 zł' ) );
check( 'koruna', 'CZK', $cur( '120 Kč' ) );
check( 'HK dollar', 'HKD', $cur( 'HK$100' ) );
check( 'CA dollar', 'CAD', $cur( 'CA$40' ) );
check( 'bare dollar names no currency', '', $cur( '$40' ) );
check( 'bare kr names no currency', '', $cur( '40 kr' ) );
check( 'code in the amount', 'CHF', $cur( 'CHF 10' ) );
check( 'real sign is BRL', 'BRL', $cur( 'R$ 10' ) );
check( 'column code wins', 'CAD', npmp_gift_csv_pick_currency( 'cad', 'EUR', 'USD' ) );
check( 'column symbol', 'EUR', npmp_gift_csv_pick_currency( '€', '', 'USD' ) );
check( 'amount symbol next', 'EUR', npmp_gift_csv_pick_currency( '', 'EUR', 'USD' ) );
check( 'then the default', 'USD', npmp_gift_csv_pick_currency( '', '', 'usd' ) );
$all16 = array( 'USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'JPY', 'MXN', 'SGD', 'HKD', 'PLN', 'CZK' );
$ok16  = 0;
foreach ( $all16 as $code ) {
	$ok16 += npmp_gift_csv_currency_supported( $code ) ? 1 : 0;
}
check( 'all 16 supported currencies pass', 16, $ok16 );
check( 'BRL is not supported', false, npmp_gift_csv_currency_supported( 'BRL' ) );
check( 'empty is not supported', false, npmp_gift_csv_currency_supported( '' ) );

// ---------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------
$ny = 'America/New_York';
check( 'ISO in UTC', '2025-01-15 14:30:00', npmp_gift_csv_parse_date( '2025-01-15 14:30:00', 'UTC' ) );
check( 'ISO in New York winter', '2025-01-15 19:30:00', npmp_gift_csv_parse_date( '2025-01-15 14:30:00', $ny ) );
check( 'ISO in New York summer', '2025-07-01 04:30:00', npmp_gift_csv_parse_date( '2025-07-01 00:30:00', $ny ) );
check( 'ISO with Z ignores the site zone', '2025-01-15 14:30:00', npmp_gift_csv_parse_date( '2025-01-15T14:30:00Z', $ny ) );
check( 'ISO with offset', '2025-01-15 19:30:00', npmp_gift_csv_parse_date( '2025-01-15T14:30:00-05:00', 'UTC' ) );
check( 'ISO with half-hour offset', '2025-01-15 09:00:00', npmp_gift_csv_parse_date( '2025-01-15T14:30:00+0530', 'UTC' ) );
check( 'ISO with milliseconds', '2025-01-15 14:30:00', npmp_gift_csv_parse_date( '2025-01-15T14:30:00.123Z', 'UTC' ) );
check( 'date only is local midnight', '2025-03-14 04:00:00', npmp_gift_csv_parse_date( '2025-03-14', $ny ) );
check( 'month first with time', '2025-03-14 14:22:00', npmp_gift_csv_parse_date( '03/14/2025 10:22', $ny, 'mdy' ) );
check( 'month first with PM', '2025-03-14 19:05:00', npmp_gift_csv_parse_date( '3/14/2025 3:05 PM', $ny, 'mdy' ) );
check( '12 AM is midnight', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( '3/14/2025 12:00 AM', 'UTC', 'mdy' ) );
check( '12 PM is noon', '2025-03-14 12:00:00', npmp_gift_csv_parse_date( '3/14/2025 12:00 PM', 'UTC', 'mdy' ) );
check( 'day first with points', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( '14.03.2025', 'UTC', 'dmy' ) );
check( 'day first with slashes', '2025-04-03 00:00:00', npmp_gift_csv_parse_date( '03/04/2025', 'UTC', 'dmy' ) );
check( 'month first reads the same as March 4', '2025-03-04 00:00:00', npmp_gift_csv_parse_date( '03/04/2025', 'UTC', 'mdy' ) );
check( 'impossible month is no date', '', npmp_gift_csv_parse_date( '14/03/2025', 'UTC', 'mdy' ) );
check( 'two-digit year', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( '3/14/25', 'UTC', 'mdy' ) );
check( 'year first with slashes', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( '2025/03/14', 'UTC' ) );
check( 'written month', '2025-03-14 19:05:00', npmp_gift_csv_parse_date( 'Mar 14, 2025 3:05 PM', $ny ) );
check( 'written month with at', '2025-03-14 19:05:00', npmp_gift_csv_parse_date( 'March 14, 2025 at 3:05 PM', $ny ) );
check( 'day before month name', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( '14 March 2025', 'UTC' ) );
check( 'ordinal day', '2025-03-14 00:00:00', npmp_gift_csv_parse_date( 'March 14th, 2025', 'UTC' ) );
check( 'Unix timestamp', '2025-01-15 14:30:00', npmp_gift_csv_parse_date( '1736951400', $ny ) );
check( 'Excel serial day', '2025-03-14 12:00:00', npmp_gift_csv_parse_date( '45730.5', 'UTC' ) );
check( 'February 30 is no date', '', npmp_gift_csv_parse_date( '2025-02-30', 'UTC' ) );
check( 'hour 25 is no date', '', npmp_gift_csv_parse_date( '2025-02-03 25:00', 'UTC' ) );
check( 'text is no date', '', npmp_gift_csv_parse_date( 'soon', 'UTC' ) );
check( 'empty is no date', '', npmp_gift_csv_parse_date( '', 'UTC' ) );
check( 'bad zone falls back to UTC', '2025-01-15 14:30:00', npmp_gift_csv_parse_date( '2025-01-15 14:30', 'Nowhere/Fake' ) );
check( 'site offset zone', '2025-01-15 12:30:00', npmp_gift_csv_parse_date( '2025-01-15 14:30', '+02:00' ) );
check( 'vote day first', 'dmy', npmp_gift_csv_date_order_vote( '25/03/2025' ) );
check( 'vote month first', 'mdy', npmp_gift_csv_date_order_vote( '03/25/2025' ) );
check( 'vote neither', '', npmp_gift_csv_date_order_vote( '03/04/2025' ) );
check( 'vote ignores ISO', '', npmp_gift_csv_date_order_vote( '2025-03-25' ) );

// ---------------------------------------------------------------------
// Statuses, frequency, payment method
// ---------------------------------------------------------------------
foreach ( array( 'succeeded', 'Succeeded', 'paid', 'Completed', '' ) as $s ) {
	check( "status '{$s}' is received", 'received', npmp_gift_csv_status( $s ) );
}
foreach ( array( 'Refunded', 'Disputed', 'chargeback' ) as $s ) {
	check( "status '{$s}' is refunded", 'refunded', npmp_gift_csv_status( $s ) );
}
foreach ( array( 'failed', 'canceled', 'Cancelled', 'Processing', 'Abandoned', 'authorized', 'pending' ) as $s ) {
	check( "status '{$s}' is not received", 'not_received', npmp_gift_csv_status( $s ) );
}
check( 'partial refund', 'partial', npmp_gift_csv_status( 'Partial refund' ) );
check( 'partially_refunded', 'partial', npmp_gift_csv_status( 'partially_refunded' ) );
check( 'Stopped is unknown', 'unknown', npmp_gift_csv_status( 'Stopped' ) );
check( 'an accepted status counts', 'received', npmp_gift_csv_status( 'Stopped', array( 'stopped' ) ) );
check( 'status key folds case and spaces', 'partial refund', npmp_gift_csv_status_key( ' Partial_Refund ' ) );

check( 'no frequency is one-time', 'one_time', npmp_gift_csv_frequency( '' ) );
check( 'monthly', 'monthly', npmp_gift_csv_frequency( 'Monthly' ) );
check( 'yearly is annual', 'annual', npmp_gift_csv_frequency( 'yearly' ) );
check( 'quarterly', 'quarterly', npmp_gift_csv_frequency( 'quarter' ) );
check( 'weekly', 'weekly', npmp_gift_csv_frequency( 'week' ) );
check( 'one-time words', 'one_time', npmp_gift_csv_frequency( 'One-Time' ) );
check( 'false is one-time', 'one_time', npmp_gift_csv_frequency( 'false' ) );
check( 'true is recurring', 'recurring', npmp_gift_csv_frequency( 'true' ) );
check( 'plan with no frequency is recurring', 'recurring', npmp_gift_csv_frequency( '', 'plan_1' ) );
check( 'odd words with a plan are recurring', 'recurring', npmp_gift_csv_frequency( 'every 2 months', 'plan_1' ) );

check( 'method becomes the gateway', 'paypal', npmp_gift_csv_gateway( 'PayPal', false ) );
check( 'offline with no method', 'offline', npmp_gift_csv_gateway( '', true ) );
check( 'method wins over offline', 'check', npmp_gift_csv_gateway( 'check', true ) );
check( 'preset when nothing else', 'zeffy', npmp_gift_csv_gateway( '', false, 'zeffy' ) );
check( 'manual when nothing at all', 'manual', npmp_gift_csv_gateway( '', false ) );
check( 'TRUE is truthy', true, npmp_gift_csv_truthy( 'TRUE' ) );
check( 'FALSE is not', false, npmp_gift_csv_truthy( 'FALSE' ) );

// ---------------------------------------------------------------------
// Headers and presets
// ---------------------------------------------------------------------
check( 'header key', 'transaction date utc', npmp_gift_csv_header_key( ' Transaction Date (UTC) ' ) );
check( 'header key drops apostrophes', 'donors local timezone', npmp_gift_csv_header_key( "Donor's Local Timezone" ) );
check( 'delimiter comma', ',', npmp_gift_csv_sniff_delimiter( 'a,b,c' ) );
check( 'delimiter semicolon', ';', npmp_gift_csv_sniff_delimiter( 'a;b;"c,d"' ) );
check( 'delimiter tab', "\t", npmp_gift_csv_sniff_delimiter( "a\tb\tc" ) );
check( 'Windows-1252 becomes UTF-8', 'José', npmp_gift_csv_utf8( "Jos\xE9" ) );
check( 'UTF-8 stays', 'Zoë', npmp_gift_csv_utf8( 'Zoë' ) );

$gb_head = npmp_gift_csv_read_head( $fixtures . 'gift-csv-givebutter.csv' );
$zf_head = npmp_gift_csv_read_head( $fixtures . 'gift-csv-zeffy.csv' );
$db_head = npmp_gift_csv_read_head( $fixtures . 'gift-csv-donorbox.csv' );
$eu_head = npmp_gift_csv_read_head( $fixtures . 'gift-csv-spreadsheet-eu.csv' );
check( 'Givebutter file is Givebutter', 'givebutter', npmp_gift_csv_detect_preset( $gb_head['headers'] ) );
check( 'Zeffy file is Zeffy', 'zeffy', npmp_gift_csv_detect_preset( $zf_head['headers'] ) );
check( 'Donorbox file has no preset', '', npmp_gift_csv_detect_preset( $db_head['headers'] ) );
check( 'BOM is gone from the first header', 'Payment date', $zf_head['headers'][0] );
check( 'semicolon file read', ';', $eu_head['delimiter'] );
check( 'semicolon file headers in UTF-8', 'Währung', $eu_head['headers'][4] );

$gb_map = npmp_gift_csv_auto_map( $gb_head['headers'], 'givebutter' );
$col    = function ( $head, $map, $field ) {
	return $map[ $field ] >= 0 ? $head['headers'][ $map[ $field ] ] : '';
};
check( 'Givebutter email', 'Email', $col( $gb_head, $gb_map, 'email' ) );
check( 'Givebutter date', 'Transaction Date (UTC)', $col( $gb_head, $gb_map, 'date' ) );
check( 'Givebutter status', 'Status Friendly', $col( $gb_head, $gb_map, 'status' ) );
check( 'Givebutter transaction ID', 'Reference Number', $col( $gb_head, $gb_map, 'transaction_id' ) );
check( 'Givebutter refund date', 'Refund Date (UTC)', $col( $gb_head, $gb_map, 'refund_date' ) );
check( 'Givebutter plan', 'Plan ID', $col( $gb_head, $gb_map, 'plan_id' ) );
check( 'Givebutter times are UTC', 'utc', npmp_gift_csv_default_timezone( $gb_head['headers'], $gb_map, 'givebutter' ) );
$zf_map = npmp_gift_csv_auto_map( $zf_head['headers'], 'zeffy' );
check( 'Zeffy status', 'Payment status', $col( $zf_head, $zf_map, 'status' ) );
check( 'Zeffy refunded amount', 'Refunded amount', $col( $zf_head, $zf_map, 'refunded_amount' ) );
check( 'Zeffy email from the generic match', 'Email', $col( $zf_head, $zf_map, 'email' ) );
check( 'Zeffy date from the generic match', 'Payment date', $col( $zf_head, $zf_map, 'date' ) );
check( 'Zeffy times are the site zone', 'site', npmp_gift_csv_default_timezone( $zf_head['headers'], $zf_map, 'zeffy' ) );
$db_map = npmp_gift_csv_auto_map( $db_head['headers'], '' );
check( 'generic email', 'Donor Email', $col( $db_head, $db_map, 'email' ) );
check( 'generic date', 'Donation Date', $col( $db_head, $db_map, 'date' ) );
check( 'generic frequency', 'Recurring Donation', $col( $db_head, $db_map, 'frequency' ) );
check( 'generic one column per field', 'Donor Address', $col( $db_head, $db_map, 'address_line1' ) );
$eu_map = npmp_gift_csv_auto_map( $eu_head['headers'], '' );
check( 'E-mail matches email', 'E-mail', $col( $eu_head, $eu_map, 'email' ) );
check( 'Betrag matches nothing', -1, $eu_map['amount'] );

// ---------------------------------------------------------------------
// Rows
// ---------------------------------------------------------------------
$settings = npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'USD' ) );
$map      = array( 'email' => 0, 'amount' => 1, 'date' => 2, 'status' => 3, 'currency' => 4, 'refunded_amount' => 5, 'transaction_id' => 6, 'name' => 7 );
$row      = function ( $cells ) use ( $map, $settings ) {
	return npmp_gift_csv_read_row( $cells, $map, $settings );
};
$good = $row( array( 'Ada@Example.org', '$25.00', '2025-01-15', 'paid', '', '', 'T1', 'Ada' ) );
check( 'a paid row is ready', true, $good['ok'] );
check( 'email is lower case', 'ada@example.org', $good['email'] );
check( 'currency falls to the default', 'USD', $good['gift']['currency'] );
check( 'the identity uses the transaction ID', npmp_gift_csv_identity( 'T1', '', '', 0, '' ), $good['gift']['source_id'] );
check( 'a different row with the same ID is the same gift', $good['gift']['source_id'], $row( array( 'other@example.org', '9', '2024-01-01', '', '', '', 't1', '' ) )['gift']['source_id'] );
$no_id_a = $row( array( 'a@example.org', '10', '2025-01-01 10:00', '', '', '', '', '' ) );
$no_id_b = $row( array( 'A@example.org', '10.00', '2025-01-01 10:00', '', '', '', '', '' ) );
$no_id_c = $row( array( 'a@example.org', '10', '2025-01-01 11:00', '', '', '', '', '' ) );
check( 'without an ID, the same email, time, and amount is one gift', $no_id_a['gift']['source_id'], $no_id_b['gift']['source_id'] );
check( 'a different time is another gift', true, $no_id_a['gift']['source_id'] !== $no_id_c['gift']['source_id'] );
check( 'identity fits the import key', 'gift_csv:' . $no_id_a['gift']['source_id'], npmp_past_gift_key( NPMP_GIFT_CSV_SOURCE, $no_id_a['gift']['source_id'] ) );
check( 'refunded stays', 'refunded', $row( array( 'a@example.org', '10', '2025-01-01', 'refunded', '', '', '', '' ) )['reason'] );
check( 'failed stays', 'not_received', $row( array( 'a@example.org', '10', '2025-01-01', 'failed', '', '', '', '' ) )['reason'] );
$stopped = $row( array( 'a@example.org', '10', '2025-01-01', 'Stopped', '', '', '', '' ) );
check( 'unknown status stays', 'unknown_status', $stopped['reason'] );
check( 'unknown status is named', 'stopped', $stopped['detail'] );
check( 'refund in full stays', 'refunded', $row( array( 'a@example.org', '10', '2025-01-01', 'succeeded', '', '10.00', '', '' ) )['reason'] );
$partial = $row( array( 'a@example.org', '$100.00', '2025-01-01', 'Partial refund', '', '$40.00', '', '' ) );
check( 'partial refund keeps the rest', '60', $partial['gift']['amount'] );
check( 'partial refund with no amount stays', 'refunded', $row( array( 'a@example.org', '100', '2025-01-01', 'Partial refund', '', '', '', '' ) )['reason'] );
check( 'negative amount stays', 'amount', $row( array( 'a@example.org', '-25', '2025-01-01', '', '', '', '', '' ) )['reason'] );
check( 'zero amount stays', 'amount', $row( array( 'a@example.org', '0.00', '2025-01-01', '', '', '', '', '' ) )['reason'] );
$brl = $row( array( 'a@example.org', '10', '2025-01-01', '', 'BRL', '', '', '' ) );
check( 'unsupported currency stays', 'currency', $brl['reason'] );
check( 'unsupported currency is named', 'BRL', $brl['detail'] );
check( 'bad date stays', 'date', $row( array( 'a@example.org', '10', 'someday', '', '', '', '', '' ) )['reason'] );
check( 'no email stays', 'email', $row( array( '', '10', '2025-01-01', '', '', '', '', '' ) )['reason'] );
check( 'not an email stays', 'email', $row( array( 'not-an-email', '10', '2025-01-01', '', '', '', '', '' ) )['reason'] );
check( 'Anonymous as a name is dropped', '', $row( array( 'a@example.org', '10', '2025-01-01', '', '', '', '', 'Anonymous' ) )['name'] );
check( 'a missing cell is empty', true, $row( array( 'a@example.org', '10', '2025-01-01' ) )['ok'] );

// ---------------------------------------------------------------------
// The fixtures, end to end through the check pass and the donor reader
// ---------------------------------------------------------------------
$pass_file = function ( $file, $head, $map, $settings ) use ( $tmp ) {
	$index   = $tmp . basename( $file ) . '.idx';
	$summary = npmp_gift_csv_build_index( $file, $head, $map, $settings, $index );
	$donors  = npmp_gift_csv_read_donors( $file, $index, $head, $map, $settings, 0, 1000 );
	$by      = array();
	foreach ( $donors as $d ) {
		$by[ $d['email'] ] = $d;
	}
	return array( $summary, $by, $index );
};

list( $s, $by ) = $pass_file( $fixtures . 'gift-csv-givebutter.csv', $gb_head, $gb_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'USD', 'preset' => 'givebutter' ) ) );
check( 'Givebutter rows', 13, $s['rows'] );
check( 'Givebutter ready', 6, $s['ready'] );
check( 'Givebutter donors', 4, $s['donors'] );
check( 'Givebutter repeats', 1, $s['duplicates'] );
check( 'Givebutter refunded by refund date', 1, $s['skipped']['refunded'] );
check( 'Givebutter failed, canceled, authorized', 3, $s['skipped']['not_received'] );
check( 'Givebutter no email', 1, $s['skipped']['email'] );
check( 'Givebutter BRL', array( 'BRL' => 1 ), $s['currencies'] );
check( 'Givebutter recurring rows', 2, $s['recurring'] );
check( 'Givebutter totals per currency', array( 'CAD' => 40.0, 'EUR' => 15.5, 'USD' => 2625.0 ), $s['totals'] );
check( 'Ada has three gifts, case folded', 3, count( $by['ada@example.org']['gifts'] ?? array() ) );
check( 'Ada keeps her address', 'Springfield', $by['ada@example.org']['extra']['city'] ?? '' );
check( 'Ada plan gift is monthly', 'monthly', $by['ada@example.org']['gifts'][1]['frequency'] ?? '' );
check( 'Ada amount with thousands', '1250.00', $by['ada@example.org']['gifts'][1]['amount'] ?? '' );
check( 'Eve row with a quoted newline reads', 'check', $by['eve@example.org']['gifts'][0]['gateway'] ?? '' );
check( 'Hana hidden name still comes over', 'Hana Example', $by['hana@example.org']['name'] ?? '' );
check( 'Hana in euros', 'EUR', $by['hana@example.org']['gifts'][0]['currency'] ?? '' );
check( 'Ben only the paid gift', 1, count( $by['ben@example.org']['gifts'] ?? array() ) );

list( $s, $by ) = $pass_file( $fixtures . 'gift-csv-zeffy.csv', $zf_head, $zf_map, npmp_gift_csv_settings( array( 'timezone' => $ny, 'currency' => 'USD', 'preset' => 'zeffy' ) ) );
check( 'Zeffy rows', 9, $s['rows'] );
check( 'Zeffy ready', 3, $s['ready'] );
check( 'Zeffy refunded and disputed', 2, $s['skipped']['refunded'] );
check( 'Zeffy failed, processing, abandoned', 3, $s['skipped']['not_received'] );
check( 'Zeffy Stopped listed', array( 'stopped' => 1 ), $s['statuses'] );
check( 'Zeffy partial refund kept 60', '60', $by['ivy@example.org']['gifts'][1]['amount'] ?? '' );
check( 'Zeffy date in New York', '2025-03-14 14:22:00', $by['ivy@example.org']['gifts'][0]['date_gmt'] ?? '' );
check( 'Zeffy cheque', 'cheque', $by['pat@example.org']['gifts'][0]['gateway'] ?? '' );
check( 'Zeffy region is the state', 'QC', $by['pat@example.org']['extra']['state'] ?? '' );
list( $s ) = $pass_file( $fixtures . 'gift-csv-zeffy.csv', $zf_head, $zf_map, npmp_gift_csv_settings( array( 'timezone' => $ny, 'currency' => 'USD', 'preset' => 'zeffy', 'accept' => array( 'stopped' ) ) ) );
check( 'Zeffy Stopped ticked comes over', 4, $s['ready'] );

list( $s, $by ) = $pass_file( $fixtures . 'gift-csv-donorbox.csv', $db_head, $db_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'USD' ) ) );
check( 'Donorbox ready', 4, $s['ready'] );
check( 'Donorbox negative', 1, $s['skipped']['amount'] );
check( 'Donorbox bad email', 1, $s['skipped']['email'] );
check( 'Donorbox euros', '1234.56', $by['quinn@example.org']['gifts'][0]['amount'] ?? '' );
check( 'Donorbox pounds', 'GBP', $by['quinn@example.org']['gifts'][1]['currency'] ?? '' );
check( 'Donorbox recurring true', 'recurring', $by['quinn@example.org']['gifts'][1]['frequency'] ?? '' );
check( 'Donorbox yen', '5000', $by['rae@example.org']['gifts'][0]['amount'] ?? '' );
check( 'Donorbox written date', '2025-05-02 23:45:00', $by['rae@example.org']['gifts'][0]['date_gmt'] ?? '' );
check( 'Donorbox Anonymous has no name', '', $by['tia@example.org']['name'] ?? 'x' );

$eu_map = array_merge( $eu_map, array( 'date' => 2, 'amount' => 3, 'currency' => 4 ) );
$order  = npmp_gift_csv_detect_date_order( $fixtures . 'gift-csv-spreadsheet-eu.csv', $eu_head, 2 );
check( 'EU spreadsheet votes day first', 'dmy', $order );
list( $s, $by ) = $pass_file( $fixtures . 'gift-csv-spreadsheet-eu.csv', $eu_head, $eu_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'EUR', 'date_order' => $order ) ) );
check( 'EU spreadsheet ready', 3, $s['ready'] );
check( 'EU 1.234,50', '1234.50', $by['jose@example.org']['gifts'][0]['amount'] ?? '' );
check( 'EU 14.03.2025', '2025-03-14 00:00:00', $by['jose@example.org']['gifts'][0]['date_gmt'] ?? '' );
check( 'EU 02.04.2025 is April', '2025-04-02 00:00:00', $by['jose@example.org']['gifts'][1]['date_gmt'] ?? '' );
check( 'EU Windows-1252 name', 'José Fakename', $by['jose@example.org']['name'] ?? '' );
check( 'EU krona with space thousands', '1000.00', $by['zoe@example.org']['gifts'][0]['amount'] ?? '' );

// The cursor reads the index in pieces and loses no donor.
list( $s, , $gb_index ) = $pass_file( $fixtures . 'gift-csv-givebutter.csv', $gb_head, $gb_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'USD' ) ) );
$cursor = 0;
$seen   = array();
do {
	$batch = npmp_gift_csv_read_donors( $fixtures . 'gift-csv-givebutter.csv', $gb_index, $gb_head, $gb_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC', 'currency' => 'USD' ) ), $cursor, 1 );
	foreach ( $batch as $d ) {
		$seen[] = $d['email'];
		$cursor = $d['cursor'];
	}
} while ( $batch );
check( 'one donor a batch reaches every donor once', array( 'ada@example.org', 'ben@example.org', 'eve@example.org', 'hana@example.org' ), $seen );

// ---------------------------------------------------------------------
// 50,000 rows
// ---------------------------------------------------------------------
$big = $tmp . 'big.csv';
$fh  = fopen( $big, 'wb' );
fwrite( $fh, "\xEF\xBB\xBF" );
fputcsv( $fh, $gb_head['headers'], ',', '"', '' );
$template = array_fill( 0, count( $gb_head['headers'] ), '' );
$cols     = array_flip( $gb_head['headers'] );
for ( $i = 1; $i <= 50000; $i++ ) {
	$r                                       = $template;
	$r[ $cols['Reference Number'] ]          = 'GB' . $i;
	$r[ $cols['Email'] ]                     = 'donor' . ( $i % 20000 ) . '@example.org';
	$r[ $cols['First Name'] ]                = 'Donor';
	$r[ $cols['Last Name'] ]                 = 'Number ' . ( $i % 20000 );
	$r[ $cols['Status Friendly'] ]           = 0 === $i % 50 ? 'failed' : 'succeeded';
	$r[ $cols['Amount'] ]                    = number_format( 5 + ( $i % 997 ), 2 );
	$r[ $cols['Currency'] ]                  = 'USD';
	$r[ $cols['Transaction Date (UTC)'] ]    = gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 600 );
	$r[ $cols['Internal Note'] ]             = 0 === $i % 1000 ? "line one\nline two" : '';
	fputcsv( $fh, $r, ',', '"', '' );
}
fclose( $fh );
$start   = microtime( true );
$mem0    = memory_get_usage();
$head    = npmp_gift_csv_read_head( $big );
$summary = npmp_gift_csv_build_index( $big, $head, $gb_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC' ) ), $tmp . 'big.idx' );
$secs    = microtime( true ) - $start;
$peak    = ( memory_get_peak_usage() - $mem0 ) / 1048576;
printf( "  INFO  50,000 rows checked in %.2fs, %.1f MB file, peak memory up %.1f MB\n", $secs, filesize( $big ) / 1048576, $peak );
check( '50,000 rows read', 50000, $summary['rows'] );
check( '50,000 rows: failed every 50th', 1000, $summary['skipped']['not_received'] );
check( '50,000 rows: ready', 49000, $summary['ready'] );
// Donors 0, 50, 100 and so on have only failed rows, so 400 of 20,000 stay out.
check( '50,000 rows: 19,600 donors with a paid gift', 19600, $summary['donors'] );
check( '50,000 rows in under 20 seconds', true, $secs < 20 );
check( '50,000 rows under 64 MB more memory', true, $peak < 64 );
$start = microtime( true );
$n     = 0;
$cur   = 0;
do {
	$batch = npmp_gift_csv_read_donors( $big, $tmp . 'big.idx', $head, $gb_map, npmp_gift_csv_settings( array( 'timezone' => 'UTC' ) ), $cur, 25 );
	foreach ( $batch as $d ) {
		$n  += count( $d['gifts'] );
		$cur = $d['cursor'];
	}
} while ( count( $batch ) === 25 );
printf( "  INFO  every donor read back in batches of 25 in %.2fs\n", microtime( true ) - $start );
check( '50,000 rows: every ready gift read back', 49000, $n );

foreach ( glob( $tmp . '*' ) as $f ) {
	unlink( $f );
}
rmdir( $tmp );

// The source never reads a payment key and never logs a live donation.
$src = file_get_contents( $root . '/includes/import/gift-csv-source.php' ) . file_get_contents( $root . '/includes/import/gift-csv-parse.php' );
check( 'never calls log_donation()', 0, preg_match( '/->log_donation\s*\(/', $src ) );
check( 'never sends mail', 0, preg_match( '/\bwp_mail\s*\(|npmp_send_mail\s*\(/', $src ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
