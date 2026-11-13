<?php
/**
 * File path: includes/import/gift-csv-parse.php
 *
 * Reading a file of past gifts: the column presets, the parsing rules for
 * amounts, currencies, dates, and statuses, and the two passes over the file
 * that the gift CSV source (gift-csv-source.php) runs. Nothing here touches
 * the database, so tests/test-gift-csv.php covers all of it.
 *
 * How the file is read:
 *
 * 1. The head: delimiter, a UTF-8 byte order mark, the header row, and a few
 *    rows for the preview.
 * 2. The check pass reads every row once, sorts each into ready or a skip
 *    reason, and writes an index file with one line per donor:
 *    "email TAB offset,offset,...". The offsets point at the start of each of
 *    that donor's rows, so a quoted newline inside a cell never breaks a row.
 *    Memory holds one short string per donor and one per gift identity, so a
 *    file of 50,000 rows stays small.
 * 3. The import reads the index a few donors at a time from a byte cursor and
 *    parses only their rows again, with the same rules.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Source slug for the gift engine, the import key, and a new contact's source. */
if ( ! defined( 'NPMP_GIFT_CSV_SOURCE' ) ) {
	define( 'NPMP_GIFT_CSV_SOURCE', 'gift_csv' );
}

// =====================================================================
// Fields and presets
// =====================================================================

/**
 * The fields a column can fill, in the order the mapping screen shows them.
 *
 * @return array<string,array{label:string,required:bool}>
 */
function npmp_gift_csv_fields() {
	return array(
		'email'           => array( 'label' => __( 'Donor email', 'nonprofit-manager' ), 'required' => true ),
		'name'            => array( 'label' => __( 'Donor full name', 'nonprofit-manager' ), 'required' => false ),
		'first_name'      => array( 'label' => __( 'Donor first name', 'nonprofit-manager' ), 'required' => false ),
		'last_name'       => array( 'label' => __( 'Donor last name', 'nonprofit-manager' ), 'required' => false ),
		'amount'          => array( 'label' => __( 'Amount', 'nonprofit-manager' ), 'required' => true ),
		'currency'        => array( 'label' => __( 'Currency', 'nonprofit-manager' ), 'required' => false ),
		'date'            => array( 'label' => __( 'Date of the gift', 'nonprofit-manager' ), 'required' => true ),
		'status'          => array( 'label' => __( 'Payment status', 'nonprofit-manager' ), 'required' => false ),
		'refunded_amount' => array( 'label' => __( 'Refunded amount', 'nonprofit-manager' ), 'required' => false ),
		'refund_date'     => array( 'label' => __( 'Refund date', 'nonprofit-manager' ), 'required' => false ),
		'transaction_id'  => array( 'label' => __( 'Transaction ID', 'nonprofit-manager' ), 'required' => false ),
		'gateway'         => array( 'label' => __( 'Payment method', 'nonprofit-manager' ), 'required' => false ),
		'offline'         => array( 'label' => __( 'Offline gift (yes or no)', 'nonprofit-manager' ), 'required' => false ),
		'frequency'       => array( 'label' => __( 'Frequency', 'nonprofit-manager' ), 'required' => false ),
		'plan_id'         => array( 'label' => __( 'Recurring plan ID', 'nonprofit-manager' ), 'required' => false ),
		'phone'           => array( 'label' => __( 'Phone', 'nonprofit-manager' ), 'required' => false ),
		'address_line1'   => array( 'label' => __( 'Address line 1', 'nonprofit-manager' ), 'required' => false ),
		'address_line2'   => array( 'label' => __( 'Address line 2', 'nonprofit-manager' ), 'required' => false ),
		'city'            => array( 'label' => __( 'City', 'nonprofit-manager' ), 'required' => false ),
		'state'           => array( 'label' => __( 'State or province', 'nonprofit-manager' ), 'required' => false ),
		'postal_code'     => array( 'label' => __( 'ZIP or postal code', 'nonprofit-manager' ), 'required' => false ),
		'country'         => array( 'label' => __( 'Country', 'nonprofit-manager' ), 'required' => false ),
	);
}

/**
 * Export formats we recognise from their headers.
 *
 * Each preset maps only columns the tool's own help center names. A field the
 * help center doesn't name falls to the generic header match, and the owner
 * checks every match on the mapping screen.
 *
 * - Givebutter, "Export transactions to CSV", full list of export data:
 *   https://help.givebutter.com/en/articles/2219206-export-transactions-to-csv
 *   Its dates are UTC, and the headers say so ("Transaction Date (UTC)").
 *   Status Friendly holds succeeded, authorized, canceled, or failed.
 * - Zeffy, "Export or download all payments or tickets associated with a
 *   specific form", which names Amount, Payment method, Payment status,
 *   Payout date, Donation, Refunded amount, and Discount, and calls the rest
 *   "buyer details" without naming them:
 *   https://support.zeffy.com/export-or-download-all-payments-or-tickets-associated-with-a-specific-form-u0tyj
 *   Statuses from "Understanding transaction statuses":
 *   https://support.zeffy.com/understanding-transaction-statuses
 *   So the Zeffy preset maps those four columns, and email, name, and date
 *   come from the generic match.
 * - Donorbox has no preset. Its help center explains how to export
 *   (https://donorbox.zendesk.com/hc/en-us/articles/360020294192) but never
 *   lists the donation export's columns, so the generic match handles it.
 *
 * @return array<string,array{name:string,detect:string[],map:array<string,string>,timezone:string}>
 */
function npmp_gift_csv_presets() {
	return array(
		'givebutter' => array(
			'name'     => 'Givebutter',
			'detect'   => array( 'Reference Number', 'Status Friendly', 'Transaction Date (UTC)' ),
			'map'      => array(
				'email'          => 'Email',
				'first_name'     => 'First Name',
				'last_name'      => 'Last Name',
				'amount'         => 'Amount',
				'currency'       => 'Currency',
				'date'           => 'Transaction Date (UTC)',
				'status'         => 'Status Friendly',
				'refund_date'    => 'Refund Date (UTC)',
				'transaction_id' => 'Reference Number',
				'gateway'        => 'Method',
				'offline'        => 'Offline',
				'frequency'      => 'Frequency',
				'plan_id'        => 'Plan ID',
				'phone'          => 'Phone',
				'address_line1'  => 'Address Line 1',
				'address_line2'  => 'Address Line 2',
				'city'           => 'City',
				'state'          => 'State',
				'postal_code'    => 'Postal Code',
				'country'        => 'Country',
			),
			'timezone' => 'utc',
		),
		'zeffy'      => array(
			'name'     => 'Zeffy',
			'detect'   => array( 'Payment status', 'Refunded amount', 'Payout date' ),
			'map'      => array(
				'amount'          => 'Amount',
				'gateway'         => 'Payment method',
				'status'          => 'Payment status',
				'refunded_amount' => 'Refunded amount',
			),
			'timezone' => 'site',
		),
	);
}

/**
 * Header words each field matches when no preset names a column. Compared
 * after npmp_gift_csv_header_key().
 *
 * @return array<string,string[]>
 */
function npmp_gift_csv_synonyms() {
	return array(
		'email'           => array( 'email', 'email address', 'e mail', 'donor email', 'donor email address', 'contact email', 'supporter email', 'buyer email', 'payer email', 'customer email' ),
		'name'            => array( 'name', 'full name', 'donor name', 'donor full name', 'donor', 'supporter name', 'buyer name', 'payer name', 'customer name' ),
		'first_name'      => array( 'first name', 'firstname', 'donor first name', 'given name', 'buyer first name', 'contact first name' ),
		'last_name'       => array( 'last name', 'lastname', 'donor last name', 'surname', 'family name', 'buyer last name', 'contact last name' ),
		'amount'          => array( 'amount', 'donation amount', 'gift amount', 'amount paid', 'total amount', 'total', 'payment amount', 'gross amount' ),
		'currency'        => array( 'currency', 'currency code', 'donation currency' ),
		'date'            => array( 'date', 'donation date', 'gift date', 'payment date', 'transaction date', 'transaction date utc', 'date utc', 'created at', 'created', 'date created', 'paid at', 'payment captured utc' ),
		'status'          => array( 'status', 'payment status', 'transaction status', 'donation status', 'status friendly' ),
		'refunded_amount' => array( 'refunded amount', 'refund amount', 'amount refunded' ),
		'refund_date'     => array( 'refund date', 'refund date utc', 'refunded at', 'refunded on', 'date refunded' ),
		'transaction_id'  => array( 'transaction id', 'reference number', 'reference', 'payment id', 'charge id', 'stripe charge id', 'donation id', 'gift id', 'transaction number' ),
		'gateway'         => array( 'payment method', 'method', 'gateway', 'payment gateway', 'processor', 'payment processor', 'payment type' ),
		'offline'         => array( 'offline' ),
		'frequency'       => array( 'frequency', 'recurring frequency', 'interval', 'donation frequency', 'recurring interval', 'recurring', 'recurring donation', 'is recurring' ),
		'plan_id'         => array( 'plan id', 'recurring plan id', 'subscription id', 'recurring id' ),
		'phone'           => array( 'phone', 'phone number', 'donor phone', 'telephone', 'mobile' ),
		'address_line1'   => array( 'address', 'address line 1', 'address 1', 'street', 'street address', 'donor address' ),
		'address_line2'   => array( 'address line 2', 'address 2', 'apt', 'suite' ),
		'city'            => array( 'city', 'town', 'donor city' ),
		'state'           => array( 'state', 'province', 'region', 'state province', 'donor state' ),
		'postal_code'     => array( 'postal code', 'zip', 'zip code', 'postcode', 'zip postal code', 'donor zip code' ),
		'country'         => array( 'country', 'donor country', 'country code' ),
	);
}

/**
 * A header in the form matching uses: lower case, letters and digits, single spaces.
 *
 * @param string $header Raw header.
 * @return string
 */
function npmp_gift_csv_header_key( $header ) {
	$header = strtolower( trim( (string) $header ) );
	$header = preg_replace( "/['’]/u", '', $header );
	$header = preg_replace( '/[^a-z0-9]+/', ' ', $header );
	return trim( (string) $header );
}

/**
 * Which preset the headers fit.
 *
 * @param string[] $headers Header row.
 * @return string Preset slug, or '' for the generic mapper.
 */
function npmp_gift_csv_detect_preset( array $headers ) {
	$have = array_flip( array_map( 'npmp_gift_csv_header_key', $headers ) );
	foreach ( npmp_gift_csv_presets() as $slug => $preset ) {
		$all = true;
		foreach ( $preset['detect'] as $needed ) {
			if ( ! isset( $have[ npmp_gift_csv_header_key( $needed ) ] ) ) {
				$all = false;
				break;
			}
		}
		if ( $all ) {
			return $slug;
		}
	}
	return '';
}

/**
 * Match fields to columns: the preset first, then the generic headers.
 *
 * @param string[] $headers Header row.
 * @param string   $preset  Preset slug or ''.
 * @return array<string,int> Field => column index, -1 when nothing matched.
 */
function npmp_gift_csv_auto_map( array $headers, $preset = '' ) {
	$keys    = array_map( 'npmp_gift_csv_header_key', array_values( $headers ) );
	$by_key  = array();
	foreach ( $keys as $i => $key ) {
		if ( '' !== $key && ! isset( $by_key[ $key ] ) ) {
			$by_key[ $key ] = $i;
		}
	}
	$presets = npmp_gift_csv_presets();
	$used    = array();
	$map     = array();
	foreach ( array_keys( npmp_gift_csv_fields() ) as $field ) {
		$map[ $field ] = -1;
	}
	if ( isset( $presets[ $preset ] ) ) {
		foreach ( $presets[ $preset ]['map'] as $field => $header ) {
			$key = npmp_gift_csv_header_key( $header );
			if ( isset( $by_key[ $key ] ) && ! isset( $used[ $by_key[ $key ] ] ) ) {
				$map[ $field ]           = $by_key[ $key ];
				$used[ $by_key[ $key ] ] = true;
			}
		}
	}
	foreach ( npmp_gift_csv_synonyms() as $field => $words ) {
		if ( $map[ $field ] >= 0 ) {
			continue;
		}
		foreach ( $words as $word ) {
			if ( isset( $by_key[ $word ] ) && ! isset( $used[ $by_key[ $word ] ] ) ) {
				$map[ $field ]           = $by_key[ $word ];
				$used[ $by_key[ $word ] ] = true;
				break;
			}
		}
	}
	return $map;
}

/**
 * The time zone a file's dates use when a date carries no offset: UTC when
 * the preset says so or the date column's header ends in "UTC", else the site's.
 *
 * @param string[] $headers Header row.
 * @param array    $map     Field => column.
 * @param string   $preset  Preset slug.
 * @return string 'utc' or 'site'.
 */
function npmp_gift_csv_default_timezone( array $headers, array $map, $preset = '' ) {
	$presets = npmp_gift_csv_presets();
	if ( isset( $presets[ $preset ] ) && 'utc' === $presets[ $preset ]['timezone'] ) {
		return 'utc';
	}
	$col = (int) ( $map['date'] ?? -1 );
	if ( $col >= 0 && isset( $headers[ $col ] ) && preg_match( '/\b(utc|gmt)\b/', npmp_gift_csv_header_key( $headers[ $col ] ) ) ) {
		return 'utc';
	}
	return 'site';
}

// =====================================================================
// Cells
// =====================================================================

/**
 * A cell as clean UTF-8. Excel on Windows saves CSV in Windows-1252.
 *
 * @param mixed $value Cell.
 * @return string
 */
function npmp_gift_csv_utf8( $value ) {
	$value = (string) $value;
	if ( '' === $value || preg_match( '//u', $value ) ) {
		return trim( $value );
	}
	if ( function_exists( 'mb_convert_encoding' ) ) {
		$value = mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
	} elseif ( function_exists( 'iconv' ) ) {
		$value = (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $value );
	}
	return trim( (string) $value );
}

/**
 * Is the currency one of the 16 the plugin supports?
 *
 * @param string $code ISO code.
 * @return bool
 */
function npmp_gift_csv_currency_supported( $code ) {
	return '' !== $code && isset( npmp_supported_currencies()[ $code ] );
}

/**
 * Currency symbols that name one currency. A bare "$" or "kr" names several,
 * so those leave the currency to the column or the default.
 *
 * @return array<string,string> Symbol => ISO code, longest first.
 */
function npmp_gift_csv_symbols() {
	return array(
		'US$' => 'USD',
		'CA$' => 'CAD',
		'C$'  => 'CAD',
		'AU$' => 'AUD',
		'A$'  => 'AUD',
		'NZ$' => 'NZD',
		'HK$' => 'HKD',
		'MX$' => 'MXN',
		'S$'  => 'SGD',
		'R$'  => 'BRL',
		'€'   => 'EUR',
		'£'   => 'GBP',
		'¥'   => 'JPY',
		'￥'  => 'JPY',
		'zł'  => 'PLN',
		'Kč'  => 'CZK',
		'₹'   => 'INR',
		'₩'   => 'KRW',
		'₪'   => 'ILS',
		'₱'   => 'PHP',
		'₦'   => 'NGN',
	);
}

/**
 * Read an amount as it appears in an export.
 *
 * Handles currency symbols and codes, thousands separators (comma, point,
 * space, no-break space, apostrophe), and decimal commas. With one separator
 * and three digits after it ("1,234" or "1.234"), the currency decides: a
 * currency written with a decimal comma (EUR, SEK, PLN, and the rest of
 * npmp_currency_formats()) or none (JPY) reads it as thousands when the
 * separator isn't its decimal mark, and "1,234" in dollars is 1234.
 *
 * @param mixed  $raw  Cell.
 * @param string $hint Currency the row is in, when known, to settle "1.234".
 * @return array{amount:string,currency:string,negative:bool} amount is a plain
 *               decimal string, '' when the cell holds no number.
 */
function npmp_gift_csv_parse_amount( $raw, $hint = '' ) {
	$out = array( 'amount' => '', 'currency' => '', 'negative' => false );
	$s   = npmp_gift_csv_utf8( $raw );
	if ( '' === $s ) {
		return $out;
	}
	if ( preg_match( '/^\((.*)\)$/u', $s, $m ) ) {
		$out['negative'] = true;
		$s               = trim( $m[1] );
	}
	foreach ( npmp_gift_csv_symbols() as $symbol => $code ) {
		if ( false !== strpos( $s, $symbol ) ) {
			$out['currency'] = $code;
			$s               = str_replace( $symbol, ' ', $s );
			break;
		}
	}
	if ( preg_match( '/(?<![A-Za-z])([A-Za-z]{3})(?![A-Za-z])/', $s, $m ) ) {
		$out['currency'] = strtoupper( $m[1] );
	}
	if ( false !== strpos( $s, '-' ) || false !== strpos( $s, '−' ) ) {
		$out['negative'] = true;
	}
	// Spaces of every width and apostrophes only ever group thousands.
	$s = preg_replace( "/[\s\x{00A0}\x{202F}\x{2009}'’]+/u", '', $s );
	$s = preg_replace( '/[^0-9.,]/', '', (string) $s );
	if ( '' === $s || ! preg_match( '/\d/', $s ) ) {
		return $out;
	}

	$hint     = '' !== $out['currency'] ? $out['currency'] : strtoupper( (string) $hint );
	$formats  = npmp_currency_formats();
	$dec_mark = isset( $formats[ $hint ]['dec'] ) && null !== $formats[ $hint ]['dec'] ? $formats[ $hint ]['dec'] : '.';
	$no_cents = in_array( $hint, npmp_zero_decimal_currencies(), true );

	$dot   = strrpos( $s, '.' );
	$comma = strrpos( $s, ',' );
	if ( false !== $dot && false !== $comma ) {
		$decimal = $dot > $comma ? '.' : ',';
	} elseif ( false !== $dot || false !== $comma ) {
		$sep     = false !== $dot ? '.' : ',';
		$parts   = explode( $sep, $s );
		$after   = strlen( (string) end( $parts ) );
		$decimal = $sep;
		if ( count( $parts ) > 2 ) {
			$decimal = '';
		} elseif ( 3 === $after && ( $no_cents || $sep !== $dec_mark ) ) {
			$decimal = '';
		}
	} else {
		$decimal = '';
	}

	if ( '' === $decimal ) {
		$number = str_replace( array( '.', ',' ), '', $s );
	} else {
		$other  = '.' === $decimal ? ',' : '.';
		$number = str_replace( $other, '', $s );
		$pieces = explode( $decimal, $number );
		if ( count( $pieces ) > 2 ) {
			return $out;
		}
		$number = $pieces[0] . ( isset( $pieces[1] ) && '' !== $pieces[1] ? '.' . $pieces[1] : '' );
	}
	$number = ltrim( $number, '0' );
	if ( '' === $number || '.' === $number[0] ) {
		$number = '0' . $number;
	}
	if ( ! preg_match( '/^\d+(\.\d+)?$/', $number ) ) {
		return $out;
	}
	$out['amount'] = $number;
	return $out;
}

/**
 * Pick the row's currency.
 *
 * @param string $column   The currency cell, a code or a symbol.
 * @param string $symbol   Currency read from the amount cell.
 * @param string $fallback Default for the file.
 * @return string Upper-case code, possibly one the plugin doesn't support.
 */
function npmp_gift_csv_pick_currency( $column, $symbol, $fallback ) {
	$column = npmp_gift_csv_utf8( $column );
	if ( '' !== $column ) {
		$code = npmp_normalize_currency_code( $column );
		if ( '' !== $code ) {
			return $code;
		}
		$read = npmp_gift_csv_parse_amount( '1 ' . $column );
		if ( '' !== $read['currency'] ) {
			return $read['currency'];
		}
	}
	if ( '' !== $symbol ) {
		return $symbol;
	}
	return npmp_normalize_currency_code( $fallback );
}

// =====================================================================
// Dates
// =====================================================================

/**
 * What a slashed date says about the file's order.
 *
 * @param string $raw Cell.
 * @return string 'dmy', 'mdy', or '' when this date fits both.
 */
function npmp_gift_csv_date_order_vote( $raw ) {
	if ( preg_match( '#^\s*(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})\b#', (string) $raw, $m ) ) {
		$a = (int) $m[1];
		$b = (int) $m[2];
		if ( $a > 12 && $b <= 12 ) {
			return 'dmy';
		}
		if ( $b > 12 && $a <= 12 ) {
			return 'mdy';
		}
	}
	return '';
}

/**
 * Read a date as it appears in an export and give it back in UTC.
 *
 * Takes ISO 8601 with or without a time and offset, year first with slashes
 * or points, day and month with slashes, points, or dashes in the given
 * order, written month names ("Mar 14, 2025 3:05 PM"), Unix timestamps, and
 * Excel serial days. A date with no offset is in $tz.
 *
 * @param mixed  $raw   Cell.
 * @param string $tz    Time zone name for dates with no offset, such as America/New_York or UTC.
 * @param string $order 'mdy' or 'dmy' for dates like 03/04/2025.
 * @return string Y-m-d H:i:s in UTC, '' when it isn't a date.
 */
function npmp_gift_csv_parse_date( $raw, $tz = 'UTC', $order = 'mdy' ) {
	$s = npmp_gift_csv_utf8( $raw );
	if ( '' === $s ) {
		return '';
	}
	try {
		$zone = new DateTimeZone( '' !== (string) $tz ? (string) $tz : 'UTC' );
	} catch ( Exception $e ) {
		$zone = new DateTimeZone( 'UTC' );
	}
	$utc = new DateTimeZone( 'UTC' );

	if ( preg_match( '/^\d{9,10}$/', $s ) ) {
		return gmdate( 'Y-m-d H:i:s', (int) $s );
	}
	if ( preg_match( '/^\d{5}(\.\d+)?$/', $s ) && (float) $s > 20000 && (float) $s < 80000 ) {
		// Excel's day count from 1899-12-30, in local time.
		$seconds = (int) round( ( (float) $s - 25569 ) * 86400 );
		$local   = gmdate( 'Y-m-d H:i:s', $seconds );
		return npmp_gift_csv_build_date( $local, $zone, $utc );
	}

	$time   = '(?:[T\s]+(\d{1,2}):(\d{2})(?::(\d{2})(?:[.,]\d+)?)?\s*([AaPp]\.?[Mm]\.?)?)?';
	$offset = '\s*(Z|[+-]\d{2}(?::?\d{2})?|UTC|GMT)?';
	$y = $mo = $d = 0;
	$m = array();
	if ( preg_match( '#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})' . $time . $offset . '$#i', $s, $m ) ) {
		$y  = (int) $m[1];
		$mo = (int) $m[2];
		$d  = (int) $m[3];
	} elseif ( preg_match( '#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4}|\d{2})' . $time . $offset . '$#i', $s, $m ) ) {
		$y = (int) $m[3];
		if ( $y < 100 ) {
			$y += 2000;
		}
		if ( 'dmy' === $order ) {
			$d  = (int) $m[1];
			$mo = (int) $m[2];
		} else {
			$mo = (int) $m[1];
			$d  = (int) $m[2];
		}
	} elseif ( preg_match( '/[A-Za-z]{3,}/', $s ) ) {
		return npmp_gift_csv_parse_written_date( $s, $zone, $utc );
	} else {
		return '';
	}
	if ( ! checkdate( $mo, $d, $y ) ) {
		return '';
	}
	$h   = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
	$min = isset( $m[5] ) && '' !== $m[5] ? (int) $m[5] : 0;
	$sec = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
	$ap  = isset( $m[7] ) ? strtolower( str_replace( '.', '', $m[7] ) ) : '';
	if ( '' !== $ap ) {
		if ( $h < 1 || $h > 12 ) {
			return '';
		}
		$h = ( 12 === $h ? 0 : $h ) + ( 'pm' === $ap ? 12 : 0 );
	}
	if ( $h > 23 || $min > 59 || $sec > 59 ) {
		return '';
	}
	$local = sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $min, $sec );
	$off   = isset( $m[8] ) ? strtoupper( $m[8] ) : '';
	if ( '' !== $off ) {
		if ( 'Z' === $off || 'UTC' === $off || 'GMT' === $off ) {
			$zone = $utc;
		} else {
			$digits = str_replace( ':', '', $off );
			if ( 3 === strlen( $digits ) ) {
				$digits .= '00';
			}
			$zone = new DateTimeZone( substr( $digits, 0, 3 ) . ':' . substr( $digits, 3, 2 ) );
		}
	}
	return npmp_gift_csv_build_date( $local, $zone, $utc );
}

/**
 * Local wall time in a zone, as UTC.
 *
 * @param string       $local Y-m-d H:i:s.
 * @param DateTimeZone $zone  Zone of $local.
 * @param DateTimeZone $utc   UTC.
 * @return string
 */
function npmp_gift_csv_build_date( $local, DateTimeZone $zone, DateTimeZone $utc ) {
	$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $local, $zone );
	return $dt ? $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '';
}

/**
 * A date with the month written out, such as "March 14, 2025 at 3:05 PM".
 *
 * @param string       $s    Cell.
 * @param DateTimeZone $zone Zone for a date with no offset.
 * @param DateTimeZone $utc  UTC.
 * @return string
 */
function npmp_gift_csv_parse_written_date( $s, DateTimeZone $zone, DateTimeZone $utc ) {
	$months = '(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
	if ( ! preg_match( '/\b' . $months . '\b/i', $s ) || ! preg_match( '/\b\d{4}\b/', $s ) ) {
		return '';
	}
	$clean = preg_replace( '/\s+at\s+/i', ' ', $s );
	$clean = preg_replace( '/(\d)(st|nd|rd|th)\b/i', '$1', (string) $clean );
	$clean = preg_replace( '/\bsept\b/i', 'Sep', (string) $clean );
	try {
		$dt = new DateTimeImmutable( (string) $clean, $zone );
	} catch ( Exception $e ) {
		return '';
	}
	$errors = DateTimeImmutable::getLastErrors();
	if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
		return '';
	}
	return $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
}

// =====================================================================
// Status, frequency, payment method
// =====================================================================

/**
 * Sort a payment status into what the import does with it.
 *
 * received comes over. refunded and not_received stay behind: the gift
 * engine stores a gift as money received and has no refunded state, so a
 * refund never counts as a gift. partial comes over as the amount kept.
 *
 * @param mixed    $raw    Cell.
 * @param string[] $accept Lower-case statuses the owner chose to count as received.
 * @return string received, partial, refunded, not_received, or unknown.
 */
function npmp_gift_csv_status( $raw, array $accept = array() ) {
	$s = strtolower( trim( preg_replace( '/[\s_-]+/', ' ', npmp_gift_csv_utf8( $raw ) ) ) );
	if ( in_array( $s, array( '', 'succeeded', 'success', 'successful', 'paid', 'complete', 'completed', 'captured', 'approved', 'settled', 'received', 'processed', 'cleared', 'deposited' ), true ) ) {
		return 'received';
	}
	if ( in_array( $s, array( 'partial refund', 'partially refunded', 'partly refunded' ), true ) ) {
		return 'partial';
	}
	if ( in_array( $s, array( 'refunded', 'refund', 'fully refunded', 'disputed', 'dispute', 'dispute lost', 'lost dispute', 'chargeback', 'charged back', 'reversed', 'returned' ), true ) ) {
		return 'refunded';
	}
	if ( in_array( $s, array( 'failed', 'failure', 'declined', 'canceled', 'cancelled', 'abandoned', 'voided', 'void', 'pending', 'processing', 'incomplete', 'authorized', 'authorised', 'expired', 'unpaid', 'past due', 'requires payment method', 'requires action', 'error' ), true ) ) {
		return 'not_received';
	}
	if ( in_array( $s, $accept, true ) ) {
		return 'received';
	}
	return 'unknown';
}

/**
 * The status as the review screen lists it, so "Stopped" and "stopped" count once.
 *
 * @param mixed $raw Cell.
 * @return string
 */
function npmp_gift_csv_status_key( $raw ) {
	return strtolower( trim( preg_replace( '/[\s_-]+/', ' ', npmp_gift_csv_utf8( $raw ) ) ) );
}

/**
 * Frequency words free stores, from a frequency cell and a plan ID.
 *
 * @param mixed $raw  Frequency cell.
 * @param mixed $plan Plan ID cell.
 * @return string one_time, weekly, monthly, quarterly, annual, or recurring.
 */
function npmp_gift_csv_frequency( $raw, $plan = '' ) {
	$s    = strtolower( trim( preg_replace( '/[\s_-]+/', ' ', npmp_gift_csv_utf8( $raw ) ) ) );
	$plan = trim( (string) $plan );
	$map  = array(
		'weekly'    => array( 'week', 'weekly', 'every week' ),
		'monthly'   => array( 'month', 'monthly', 'every month', 'recurring monthly', 'monthly recurring' ),
		'quarterly' => array( 'quarter', 'quarterly', 'every 3 months', 'every three months' ),
		'annual'    => array( 'year', 'yearly', 'annual', 'annually', 'every year', 'recurring yearly', 'yearly recurring' ),
		'one_time'  => array( 'once', 'one time', 'onetime', 'single', 'one off', 'none', 'no', 'false', '0' ),
	);
	foreach ( $map as $frequency => $words ) {
		if ( in_array( $s, $words, true ) ) {
			return ( 'one_time' === $frequency && '' !== $plan ) ? 'recurring' : $frequency;
		}
	}
	if ( '' === $s ) {
		return '' !== $plan ? 'recurring' : 'one_time';
	}
	return 'recurring';
}

/**
 * Is a yes-or-no cell a yes?
 *
 * @param mixed $raw Cell.
 * @return bool
 */
function npmp_gift_csv_truthy( $raw ) {
	return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'y', 'x', 'offline' ), true );
}

/**
 * The payment method to store.
 *
 * @param mixed  $method  Payment method cell.
 * @param bool   $offline Offline flag.
 * @param string $preset  Preset slug, used when the file names no method.
 * @return string
 */
function npmp_gift_csv_gateway( $method, $offline, $preset = '' ) {
	$method = npmp_gift_csv_utf8( $method );
	if ( '' !== $method ) {
		return npmp_past_gift_gateway( $method );
	}
	if ( $offline ) {
		return 'offline';
	}
	return '' !== $preset ? npmp_past_gift_gateway( $preset ) : 'manual';
}

// =====================================================================
// One row
// =====================================================================

/**
 * Settings the file is read with.
 *
 * @param array $settings Saved settings.
 * @return array{timezone:string,date_order:string,currency:string,accept:string[],preset:string}
 */
function npmp_gift_csv_settings( array $settings ) {
	$order = (string) ( $settings['date_order'] ?? 'mdy' );
	return array(
		'timezone'   => (string) ( $settings['timezone'] ?? 'UTC' ),
		'date_order' => 'dmy' === $order ? 'dmy' : 'mdy',
		'currency'   => npmp_normalize_currency_code( $settings['currency'] ?? 'USD' ),
		'accept'     => array_values( array_filter( array_map( 'strval', (array) ( $settings['accept'] ?? array() ) ) ) ),
		'preset'     => (string) ( $settings['preset'] ?? '' ),
	);
}

/**
 * The identity of a gift, so a duplicate row or a second run adds nothing:
 * the transaction ID when the file has one, else email, date, amount, and currency.
 *
 * @param string $txn      Transaction ID.
 * @param string $email    Lower-case email.
 * @param string $date_gmt UTC date.
 * @param string $amount   Amount.
 * @param string $currency Currency.
 * @return string Short ID for npmp_past_gift_key().
 */
function npmp_gift_csv_identity( $txn, $email, $date_gmt, $amount, $currency ) {
	$txn = strtolower( trim( (string) $txn ) );
	if ( '' !== $txn ) {
		return 't' . substr( sha1( $txn ), 0, 24 );
	}
	return 'r' . substr( sha1( $email . '|' . $date_gmt . '|' . number_format( (float) $amount, 3, '.', '' ) . '|' . $currency ), 0, 24 );
}

/**
 * Read one row into a gift, or say why it stays behind.
 *
 * Skip reasons: email (none, or not an address), amount (none, zero, or
 * negative), date, currency (one the plugin doesn't support), refunded
 * (refunded, disputed, or refunded in full), not_received (failed, pending,
 * canceled), unknown_status.
 *
 * @param array $cells    Row cells by index.
 * @param array $map      Field => column index (-1 for none).
 * @param array $settings From npmp_gift_csv_settings().
 * @return array{ok:bool,reason:string,detail:string,email:string,name:string,extra:array,gift:array}
 */
function npmp_gift_csv_read_row( array $cells, array $map, array $settings ) {
	$cell = function ( $field ) use ( $cells, $map ) {
		$col = (int) ( $map[ $field ] ?? -1 );
		return $col >= 0 && isset( $cells[ $col ] ) ? npmp_gift_csv_utf8( $cells[ $col ] ) : '';
	};
	$out = array( 'ok' => false, 'reason' => '', 'detail' => '', 'email' => '', 'name' => '', 'extra' => array(), 'gift' => array() );

	$status = npmp_gift_csv_status( $cell( 'status' ), $settings['accept'] );
	if ( 'refunded' === $status || 'not_received' === $status ) {
		$out['reason'] = $status;
		return $out;
	}
	if ( 'unknown' === $status ) {
		$out['reason'] = 'unknown_status';
		$out['detail'] = npmp_gift_csv_status_key( $cell( 'status' ) );
		return $out;
	}
	if ( '' !== $cell( 'refund_date' ) ) {
		$out['reason'] = 'refunded';
		return $out;
	}

	$currency_cell = $cell( 'currency' );
	$hint          = npmp_normalize_currency_code( $currency_cell );
	$read          = npmp_gift_csv_parse_amount( $cell( 'amount' ), '' !== $hint ? $hint : $settings['currency'] );
	$currency      = npmp_gift_csv_pick_currency( $currency_cell, $read['currency'], $settings['currency'] );
	if ( '' === $read['amount'] || $read['negative'] || (float) $read['amount'] <= 0 ) {
		$out['reason'] = 'amount';
		return $out;
	}
	$amount = $read['amount'];

	$refunded = npmp_gift_csv_parse_amount( $cell( 'refunded_amount' ), $currency );
	if ( '' !== $refunded['amount'] && (float) $refunded['amount'] > 0 ) {
		$kept = round( (float) $amount - (float) $refunded['amount'], 3 );
		if ( $kept <= 0 ) {
			$out['reason'] = 'refunded';
			return $out;
		}
		$amount = rtrim( rtrim( number_format( $kept, 3, '.', '' ), '0' ), '.' );
	} elseif ( 'partial' === $status ) {
		// A partial refund with no refunded amount: we can't tell what was kept.
		$out['reason'] = 'refunded';
		return $out;
	}

	if ( '' === $currency || ! npmp_gift_csv_currency_supported( $currency ) ) {
		$out['reason'] = 'currency';
		$out['detail'] = '' !== $currency ? $currency : '?';
		return $out;
	}

	$date = npmp_gift_csv_parse_date( $cell( 'date' ), $settings['timezone'], $settings['date_order'] );
	if ( '' === $date ) {
		$out['reason'] = 'date';
		return $out;
	}

	$email = strtolower( $cell( 'email' ) );
	if ( '' === $email || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		$out['reason'] = 'email';
		return $out;
	}

	$name = $cell( 'name' );
	if ( '' === $name ) {
		$name = trim( $cell( 'first_name' ) . ' ' . $cell( 'last_name' ) );
	}
	if ( in_array( strtolower( $name ), array( 'anonymous', 'anonymous donor', 'anon' ), true ) ) {
		$name = '';
	}

	$extra = array();
	foreach ( array( 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country' ) as $field ) {
		$value = $cell( $field );
		if ( '' !== $value ) {
			$extra[ $field ] = $value;
		}
	}

	$txn = $cell( 'transaction_id' );
	$out = array(
		'ok'     => true,
		'reason' => '',
		'detail' => '',
		'email'  => $email,
		'name'   => $name,
		'extra'  => $extra,
		'gift'   => array(
			'source_id'      => npmp_gift_csv_identity( $txn, $email, $date, $amount, $currency ),
			'name'           => $name,
			'amount'         => $amount,
			'currency'       => $currency,
			'date_gmt'       => $date,
			'gateway'        => npmp_gift_csv_gateway( $cell( 'gateway' ), npmp_gift_csv_truthy( $cell( 'offline' ) ), $settings['preset'] ),
			'frequency'      => npmp_gift_csv_frequency( $cell( 'frequency' ), $cell( 'plan_id' ) ),
			'transaction_id' => $txn,
		),
	);
	return $out;
}

// =====================================================================
// The file
// =====================================================================

/**
 * Open a file for reading.
 *
 * @param string $path Path.
 * @return resource|false
 */
function npmp_gift_csv_open( $path ) {
	if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of a private upload.
	return fopen( $path, 'rb' );
}

/**
 * Read one CSV record. An empty escape character follows RFC 4180, so a
 * backslash before a quote is just a backslash.
 *
 * @param resource $handle    File.
 * @param string   $delimiter Delimiter.
 * @return array|false
 */
function npmp_gift_csv_record( $handle, $delimiter ) {
	return fgetcsv( $handle, 0, $delimiter, '"', '' );
}

/**
 * Is a record blank (a trailing empty line)?
 *
 * @param array $row Record.
 * @return bool
 */
function npmp_gift_csv_blank( array $row ) {
	foreach ( $row as $value ) {
		if ( null !== $value && '' !== trim( (string) $value ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The delimiter a header line uses: comma, semicolon (Excel in much of
 * Europe), or tab.
 *
 * @param string $line First line.
 * @return string
 */
function npmp_gift_csv_sniff_delimiter( $line ) {
	$line   = preg_replace( '/"[^"]*"/', '', (string) $line );
	$counts = array(
		','  => substr_count( (string) $line, ',' ),
		';'  => substr_count( (string) $line, ';' ),
		"\t" => substr_count( (string) $line, "\t" ),
	);
	arsort( $counts );
	$best = (string) key( $counts );
	return $counts[ $best ] > 0 ? $best : ',';
}

/**
 * Read the head of the file.
 *
 * @param string $path    File.
 * @param int    $preview Rows to keep for the preview.
 * @return array{headers:string[],delimiter:string,data_start:int,preview:array[]}|null Null when unreadable or empty.
 */
function npmp_gift_csv_read_head( $path, $preview = 5 ) {
	$handle = npmp_gift_csv_open( $path );
	if ( ! $handle ) {
		return null;
	}
	$start = 0;
	if ( "\xEF\xBB\xBF" === fread( $handle, 3 ) ) {
		$start = 3;
	}
	fseek( $handle, $start );
	$first     = (string) fgets( $handle );
	$delimiter = npmp_gift_csv_sniff_delimiter( $first );
	fseek( $handle, $start );
	$headers = npmp_gift_csv_record( $handle, $delimiter );
	if ( ! is_array( $headers ) || npmp_gift_csv_blank( $headers ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return null;
	}
	$data_start = (int) ftell( $handle );
	$rows       = array();
	while ( count( $rows ) < $preview ) {
		$row = npmp_gift_csv_record( $handle, $delimiter );
		if ( false === $row ) {
			break;
		}
		if ( ! npmp_gift_csv_blank( $row ) ) {
			$rows[] = array_map( 'npmp_gift_csv_utf8', $row );
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return array(
		'headers'    => array_map( 'npmp_gift_csv_utf8', $headers ),
		'delimiter'  => $delimiter,
		'data_start' => $data_start,
		'preview'    => $rows,
	);
}

/**
 * Settle the date order for the whole file from its dates: day first when
 * any date can only be day first, else month first.
 *
 * @param string $path       File.
 * @param array  $head       From npmp_gift_csv_read_head().
 * @param int    $date_col   Column of the date.
 * @return string 'dmy' or 'mdy'.
 */
function npmp_gift_csv_detect_date_order( $path, array $head, $date_col ) {
	$date_col = (int) $date_col;
	$handle   = npmp_gift_csv_open( $path );
	if ( ! $handle || $date_col < 0 ) {
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return 'mdy';
	}
	fseek( $handle, (int) $head['data_start'] );
	$votes = array( 'dmy' => 0, 'mdy' => 0 );
	while ( false !== ( $row = npmp_gift_csv_record( $handle, $head['delimiter'] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		$vote = npmp_gift_csv_date_order_vote( (string) ( $row[ $date_col ] ?? '' ) );
		if ( '' !== $vote ) {
			++$votes[ $vote ];
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $votes['dmy'] > $votes['mdy'] ? 'dmy' : 'mdy';
}

/**
 * Empty summary of a check pass.
 *
 * @return array
 */
function npmp_gift_csv_empty_summary() {
	return array(
		'rows'       => 0,
		'ready'      => 0,
		'donors'     => 0,
		'recurring'  => 0,
		'duplicates' => 0,
		'skipped'    => array(
			'email'          => 0,
			'amount'         => 0,
			'date'           => 0,
			'currency'       => 0,
			'refunded'       => 0,
			'not_received'   => 0,
			'unknown_status' => 0,
		),
		'statuses'   => array(),
		'currencies' => array(),
		'totals'     => array(),
		'first'      => '',
		'last'       => '',
	);
}

/**
 * The check pass: read every row, count what comes over and why the rest
 * stays behind, and write the donor index.
 *
 * @param string $path       File.
 * @param array  $head       From npmp_gift_csv_read_head().
 * @param array  $map        Field => column.
 * @param array  $settings   From npmp_gift_csv_settings().
 * @param string $index_path Where to write the index.
 * @return array|null Summary, null when a file can't be opened.
 */
function npmp_gift_csv_build_index( $path, array $head, array $map, array $settings, $index_path ) {
	$handle = npmp_gift_csv_open( $path );
	if ( ! $handle ) {
		return null;
	}
	fseek( $handle, (int) $head['data_start'] );
	$summary = npmp_gift_csv_empty_summary();
	$donors  = array();
	$seen    = array();
	while ( true ) {
		$offset = (int) ftell( $handle );
		$row    = npmp_gift_csv_record( $handle, $head['delimiter'] );
		if ( false === $row ) {
			break;
		}
		if ( npmp_gift_csv_blank( $row ) ) {
			continue;
		}
		++$summary['rows'];
		$read = npmp_gift_csv_read_row( $row, $map, $settings );
		if ( ! $read['ok'] ) {
			++$summary['skipped'][ $read['reason'] ];
			if ( 'unknown_status' === $read['reason'] ) {
				$summary['statuses'][ $read['detail'] ] = ( $summary['statuses'][ $read['detail'] ] ?? 0 ) + 1;
			} elseif ( 'currency' === $read['reason'] ) {
				$summary['currencies'][ $read['detail'] ] = ( $summary['currencies'][ $read['detail'] ] ?? 0 ) + 1;
			}
			continue;
		}
		$id = $read['gift']['source_id'];
		if ( isset( $seen[ $id ] ) ) {
			++$summary['duplicates'];
			continue;
		}
		$seen[ $id ] = true;
		++$summary['ready'];
		if ( 'one_time' !== $read['gift']['frequency'] ) {
			++$summary['recurring'];
		}
		$cur                        = $read['gift']['currency'];
		$summary['totals'][ $cur ]  = round( ( $summary['totals'][ $cur ] ?? 0 ) + (float) $read['gift']['amount'], 3 );
		$date                       = $read['gift']['date_gmt'];
		if ( '' === $summary['first'] || $date < $summary['first'] ) {
			$summary['first'] = $date;
		}
		if ( $date > $summary['last'] ) {
			$summary['last'] = $date;
		}
		$email = $read['email'];
		if ( isset( $donors[ $email ] ) ) {
			$donors[ $email ] .= ',' . $offset;
		} else {
			$donors[ $email ] = (string) $offset;
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	unset( $seen );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private index file.
	$out = fopen( $index_path, 'wb' );
	if ( ! $out ) {
		return null;
	}
	foreach ( $donors as $email => $offsets ) {
		fwrite( $out, $email . "\t" . $offsets . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	$summary['donors'] = count( $donors );
	arsort( $summary['statuses'] );
	arsort( $summary['currencies'] );
	ksort( $summary['totals'] );
	return $summary;
}

/**
 * The next donors from the index, each with their gifts, in the shape the
 * gift engine's donor_batch hands back.
 *
 * @param string $path       File.
 * @param string $index_path Index.
 * @param array  $head       From npmp_gift_csv_read_head().
 * @param array  $map        Field => column.
 * @param array  $settings   From npmp_gift_csv_settings().
 * @param int    $after      Byte cursor in the index.
 * @param int    $limit      Donors.
 * @return array[]
 */
function npmp_gift_csv_read_donors( $path, $index_path, array $head, array $map, array $settings, $after, $limit ) {
	$index = npmp_gift_csv_open( $index_path );
	$csv   = npmp_gift_csv_open( $path );
	$out   = array();
	if ( ! $index || ! $csv ) {
		foreach ( array( $index, $csv ) as $h ) {
			if ( $h ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
		return $out;
	}
	fseek( $index, max( 0, (int) $after ) );
	while ( count( $out ) < $limit ) {
		$line = fgets( $index );
		if ( false === $line ) {
			break;
		}
		$cursor = (int) ftell( $index );
		$parts  = explode( "\t", rtrim( $line, "\r\n" ), 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		$donor = array( 'cursor' => $cursor, 'email' => $parts[0], 'name' => '', 'extra' => array(), 'gifts' => array() );
		foreach ( explode( ',', $parts[1] ) as $offset ) {
			fseek( $csv, (int) $offset );
			$row = npmp_gift_csv_record( $csv, $head['delimiter'] );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$read = npmp_gift_csv_read_row( $row, $map, $settings );
			if ( ! $read['ok'] || $read['email'] !== $donor['email'] ) {
				continue;
			}
			// The last row with a name or address speaks for the donor.
			if ( '' !== $read['name'] ) {
				$donor['name'] = $read['name'];
			}
			$donor['extra'] = array_merge( $donor['extra'], $read['extra'] );
			$donor['gifts'][ $read['gift']['source_id'] ] = $read['gift'];
		}
		$donor['gifts'] = array_values( $donor['gifts'] );
		$out[]          = $donor;
	}
	fclose( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $out;
}
