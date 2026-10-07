<?php
/**
 * Site currency: the one place amounts are formatted, converted to and from
 * gateway minor units, and checked against gateway minimums.
 *
 * Donations used to be hard-coded to US dollars, which shut out UK, Canadian,
 * Australian and every other non-US nonprofit. The site now picks one ISO 4217
 * code (option npmp_currency, default USD) from a list both Stripe and PayPal
 * accept. Every amount shown or sent to a gateway goes through these helpers,
 * and every donation stores the currency it was taken in, so history stays
 * right when the setting changes later. A donation with no stored currency
 * predates this and is US dollars.
 *
 * Pure PHP apart from a few guarded WordPress calls, so tests/test-currency.php
 * runs it without WordPress. No dependency on the intl extension: symbols,
 * placement and decimals come from the table below.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currencies a site can choose. Each is accepted by both Stripe and PayPal.
 * Sanitizing the setting against this list is what keeps a crafted POST from
 * storing anything else.
 *
 * @return array<string,string> ISO code => English name.
 */
function npmp_supported_currencies() {
	return array(
		'USD' => __( 'US dollar', 'nonprofit-manager' ),
		'CAD' => __( 'Canadian dollar', 'nonprofit-manager' ),
		'GBP' => __( 'British pound', 'nonprofit-manager' ),
		'EUR' => __( 'Euro', 'nonprofit-manager' ),
		'AUD' => __( 'Australian dollar', 'nonprofit-manager' ),
		'NZD' => __( 'New Zealand dollar', 'nonprofit-manager' ),
		'CHF' => __( 'Swiss franc', 'nonprofit-manager' ),
		'SEK' => __( 'Swedish krona', 'nonprofit-manager' ),
		'NOK' => __( 'Norwegian krone', 'nonprofit-manager' ),
		'DKK' => __( 'Danish krone', 'nonprofit-manager' ),
		'JPY' => __( 'Japanese yen', 'nonprofit-manager' ),
		'MXN' => __( 'Mexican peso', 'nonprofit-manager' ),
		'SGD' => __( 'Singapore dollar', 'nonprofit-manager' ),
		'HKD' => __( 'Hong Kong dollar', 'nonprofit-manager' ),
		'PLN' => __( 'Polish zloty', 'nonprofit-manager' ),
		'CZK' => __( 'Czech koruna', 'nonprofit-manager' ),
	);
}

/**
 * How each supported currency is written.
 *
 * symbol, position (before|after), space between symbol and number, decimals,
 * and the decimal and thousands separators. A null separator means "use the
 * site's WordPress locale" (number_format_i18n), which is what US-dollar
 * amounts have always used on the dashboard. Currencies whose home market
 * writes them a fixed way carry their own separators.
 *
 * Filter npmp_currency_format to change any entry (for example an Irish site
 * that writes euros as €1,234.56).
 *
 * @return array<string,array{symbol:string,position:string,space:bool,decimals:int,dec:?string,thousands:?string}>
 */
function npmp_currency_formats() {
	$dollar = array( 'position' => 'before', 'space' => false, 'decimals' => 2, 'dec' => null, 'thousands' => null );

	return array(
		'USD' => array( 'symbol' => '$' ) + $dollar,
		'CAD' => array( 'symbol' => '$' ) + $dollar,
		'AUD' => array( 'symbol' => '$' ) + $dollar,
		'NZD' => array( 'symbol' => '$' ) + $dollar,
		'SGD' => array( 'symbol' => '$' ) + $dollar,
		'MXN' => array( 'symbol' => '$' ) + $dollar,
		'HKD' => array( 'symbol' => 'HK$' ) + $dollar,
		'GBP' => array( 'symbol' => '£' ) + $dollar,
		'JPY' => array( 'symbol' => '¥', 'position' => 'before', 'space' => false, 'decimals' => 0, 'dec' => null, 'thousands' => null ),
		'EUR' => array( 'symbol' => '€', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => '.' ),
		'CHF' => array( 'symbol' => 'CHF', 'position' => 'before', 'space' => true, 'decimals' => 2, 'dec' => '.', 'thousands' => "'" ),
		'SEK' => array( 'symbol' => 'kr', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => ' ' ),
		'NOK' => array( 'symbol' => 'kr', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => ' ' ),
		'DKK' => array( 'symbol' => 'kr.', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => '.' ),
		'PLN' => array( 'symbol' => 'zł', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => ' ' ),
		'CZK' => array( 'symbol' => 'Kč', 'position' => 'after', 'space' => true, 'decimals' => 2, 'dec' => ',', 'thousands' => ' ' ),
	);
}

/**
 * Stripe's zero-decimal currencies: the amount is sent in whole units, not
 * hundredths. https://docs.stripe.com/currencies#zero-decimal
 *
 * @return string[]
 */
function npmp_zero_decimal_currencies() {
	return array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
}

/**
 * Stripe's three-decimal currencies. https://docs.stripe.com/currencies#three-decimal
 *
 * @return string[]
 */
function npmp_three_decimal_currencies() {
	return array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );
}

/**
 * Normalize any currency code a record or gateway carries: three letters,
 * upper case. Not limited to the supported list, because a stored donation
 * keeps whatever currency it was actually taken in.
 *
 * @param mixed $code Raw code.
 * @return string Upper-case code, or '' when it isn't one.
 */
function npmp_normalize_currency_code( $code ) {
	if ( ! is_scalar( $code ) ) {
		return '';
	}
	$code = strtoupper( trim( (string) $code ) );
	return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
}

/**
 * Currency of a stored record. Records written before currency support have
 * none, and every one of those was taken in US dollars.
 *
 * @param mixed $code Stored code, possibly null or ''.
 * @return string
 */
function npmp_record_currency( $code ) {
	$code = npmp_normalize_currency_code( $code );
	return '' === $code ? 'USD' : $code;
}

/**
 * Sanitize a submitted site currency to the supported list.
 *
 * @param mixed $code Submitted value.
 * @return string A supported code, USD when the value isn't one.
 */
function npmp_sanitize_currency( $code ) {
	$code = npmp_normalize_currency_code( $code );
	return isset( npmp_supported_currencies()[ $code ] ) ? $code : 'USD';
}

/**
 * The site's currency for new donations, dues and subscriptions.
 *
 * @return string ISO 4217 code.
 */
function npmp_currency() {
	$stored = function_exists( 'get_option' ) ? get_option( 'npmp_currency', 'USD' ) : 'USD';
	return npmp_sanitize_currency( $stored );
}

/**
 * Resolve an optional currency argument: null means the site currency.
 *
 * @param string|null $currency Code or null.
 * @return string
 */
function npmp_resolve_currency( $currency = null ) {
	if ( null === $currency || '' === $currency ) {
		return npmp_currency();
	}
	return npmp_record_currency( $currency );
}

/**
 * Format entry for a currency, falling back to "CODE 1,234.56" for a code the
 * table doesn't know (an old record in a currency no longer offered).
 *
 * @param string $currency Code.
 * @return array
 */
function npmp_currency_format( $currency ) {
	$formats = npmp_currency_formats();
	if ( isset( $formats[ $currency ] ) ) {
		$format = $formats[ $currency ];
	} else {
		$format = array(
			'symbol'    => $currency,
			'position'  => 'before',
			'space'     => true,
			'decimals'  => npmp_currency_decimals( $currency ),
			'dec'       => null,
			'thousands' => null,
		);
	}

	if ( function_exists( 'apply_filters' ) ) {
		$format = (array) apply_filters( 'npmp_currency_format', $format, $currency );
	}

	return $format;
}

/**
 * Digits after the decimal point.
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return int
 */
function npmp_currency_decimals( $currency = null ) {
	$currency = npmp_resolve_currency( $currency );
	if ( in_array( $currency, npmp_zero_decimal_currencies(), true ) ) {
		return 0;
	}
	if ( in_array( $currency, npmp_three_decimal_currencies(), true ) ) {
		return 3;
	}
	return 2;
}

/**
 * Symbol for a currency.
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return string
 */
function npmp_currency_symbol( $currency = null ) {
	$currency = npmp_resolve_currency( $currency );
	$format   = npmp_currency_format( $currency );
	return (string) $format['symbol'];
}

/**
 * Format an amount for display: "$1,234.56", "£20.00", "¥5,000", "1.234,56 €".
 *
 * @param float|int|string $amount   Amount in major units.
 * @param string|null      $currency Code, or null for the site currency.
 * @return string Plain text. Escape it for the context it goes into.
 */
function npmp_format_amount( $amount, $currency = null ) {
	$currency = npmp_resolve_currency( $currency );
	$format   = npmp_currency_format( $currency );
	$decimals = isset( $format['decimals'] ) ? (int) $format['decimals'] : npmp_currency_decimals( $currency );
	$amount   = (float) $amount;
	$negative = $amount < 0;
	$amount   = abs( $amount );

	$dec_point = $format['dec'] ?? null;
	$thousands = $format['thousands'] ?? null;

	if ( null === $dec_point || null === $thousands ) {
		$number = function_exists( 'number_format_i18n' )
			? number_format_i18n( $amount, $decimals )
			: number_format( $amount, $decimals );
	} else {
		$number = number_format( $amount, $decimals, (string) $dec_point, (string) $thousands );
	}

	$gap    = ! empty( $format['space'] ) ? ' ' : '';
	$symbol = (string) ( $format['symbol'] ?? $currency );
	$text   = 'after' === ( $format['position'] ?? 'before' ) ? $number . $gap . $symbol : $symbol . $gap . $number;

	return ( $negative ? '-' : '' ) . $text;
}

/**
 * Convert an amount to the smallest unit a gateway charges in: cents for USD,
 * whole yen for JPY.
 *
 * round() before the cast: 19.99 * 100 is 1998.9999999999998 in IEEE 754,
 * and a bare cast truncates that to a one-cent undercharge.
 *
 * @param float|int|string $amount   Major units.
 * @param string|null      $currency Code, or null for the site currency.
 * @return int
 */
function npmp_currency_to_minor( $amount, $currency = null ) {
	$decimals = npmp_currency_decimals( $currency );
	return (int) round( (float) $amount * pow( 10, $decimals ) );
}

/**
 * Convert a gateway minor-unit amount back to major units.
 *
 * @param int|string  $minor    Minor units, as Stripe sends them.
 * @param string|null $currency Code, or null for the site currency.
 * @return float
 */
function npmp_currency_from_minor( $minor, $currency = null ) {
	if ( ! is_numeric( $minor ) ) {
		return 0.0;
	}
	$decimals = npmp_currency_decimals( $currency );
	return round( (float) $minor / pow( 10, $decimals ), $decimals );
}

/**
 * Plain decimal string a gateway API expects ("25.00", "5000" for JPY): no
 * symbol, no thousands separator, a dot for the decimal point.
 *
 * @param float|int|string $amount   Major units.
 * @param string|null      $currency Code, or null for the site currency.
 * @return string
 */
function npmp_currency_api_amount( $amount, $currency = null ) {
	return number_format( (float) $amount, npmp_currency_decimals( $currency ), '.', '' );
}

/**
 * Lowest amount Stripe will charge in a currency, in major units.
 * https://docs.stripe.com/currencies#minimum-and-maximum-charge-amounts
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return float
 */
function npmp_currency_stripe_minimum( $currency = null ) {
	$currency = npmp_resolve_currency( $currency );
	$minimums = array(
		'USD' => 0.50,
		'CAD' => 0.50,
		'GBP' => 0.30,
		'EUR' => 0.50,
		'AUD' => 0.50,
		'NZD' => 0.50,
		'CHF' => 0.50,
		'SEK' => 3.00,
		'NOK' => 3.00,
		'DKK' => 2.50,
		'JPY' => 50,
		'MXN' => 10.00,
		'SGD' => 0.50,
		'HKD' => 4.00,
		'PLN' => 2.00,
		'CZK' => 15.00,
	);
	return isset( $minimums[ $currency ] ) ? (float) $minimums[ $currency ] : 0.50;
}

/**
 * Smallest gift the donation forms accept: one whole unit (the long-standing
 * $1 floor), raised to Stripe's minimum where that is higher (¥50, 10 MXN).
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return float
 */
function npmp_currency_min_donation( $currency = null ) {
	return max( 1.0, npmp_currency_stripe_minimum( $currency ) );
}

/**
 * Whether an amount meets the donation minimum for its currency.
 *
 * @param float       $amount   Major units.
 * @param string|null $currency Code, or null for the site currency.
 * @return bool
 */
function npmp_currency_meets_minimum( $amount, $currency = null ) {
	// Compare in minor units so float noise (0.1 + 0.2) can't flip the answer.
	return npmp_currency_to_minor( $amount, $currency ) >= npmp_currency_to_minor( npmp_currency_min_donation( $currency ), $currency );
}

/**
 * The "below the minimum" message. US-dollar sites keep the exact sentence
 * they always had.
 *
 * @param string      $verb     'enter' for the browser-side check, 'provide' for the server.
 * @param string|null $currency Code, or null for the site currency.
 * @return string
 */
function npmp_currency_min_amount_message( $verb = 'enter', $currency = null ) {
	$currency = npmp_resolve_currency( $currency );

	if ( 'USD' === $currency ) {
		return 'provide' === $verb
			? __( 'Please provide a valid donation amount (minimum $1).', 'nonprofit-manager' )
			: __( 'Please enter a valid donation amount (minimum $1).', 'nonprofit-manager' );
	}

	$minimum = npmp_format_amount( npmp_currency_min_donation( $currency ), $currency );

	return 'provide' === $verb
		/* translators: %s: smallest accepted donation, formatted with its currency (e.g. £1.00). */
		? sprintf( __( 'Please provide a valid donation amount (minimum %s).', 'nonprofit-manager' ), $minimum )
		/* translators: %s: smallest accepted donation, formatted with its currency (e.g. £1.00). */
		: sprintf( __( 'Please enter a valid donation amount (minimum %s).', 'nonprofit-manager' ), $minimum );
}

/**
 * HTML number-input attributes for an amount field: step and min.
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return array{step:string,min:string}
 */
function npmp_currency_input_attrs( $currency = null ) {
	$decimals = npmp_currency_decimals( $currency );
	$min      = number_format( npmp_currency_min_donation( $currency ), $decimals, '.', '' );
	if ( $decimals > 0 ) {
		// "1.00" → "1", "2.50" → "2.5". Only trailing decimal zeros go.
		$min = rtrim( rtrim( $min, '0' ), '.' );
	}
	return array(
		'step' => 0 === $decimals ? '1' : '0.' . str_repeat( '0', $decimals - 1 ) . '1',
		'min'  => $min,
	);
}

/**
 * Venmo moves US dollars between US accounts only. The Venmo button is hidden
 * on a site taking any other currency.
 *
 * @param string|null $currency Code, or null for the site currency.
 * @return bool
 */
function npmp_venmo_available( $currency = null ) {
	return 'USD' === npmp_resolve_currency( $currency );
}

/**
 * Drop gateways the site currency can't use (Venmo outside USD).
 *
 * @param array       $gateways Enabled gateway slugs.
 * @param string|null $currency Code, or null for the site currency.
 * @return array Re-indexed list.
 */
function npmp_filter_gateways_for_currency( $gateways, $currency = null ) {
	$gateways = is_array( $gateways ) ? $gateways : array();
	if ( npmp_venmo_available( $currency ) ) {
		return array_values( $gateways );
	}
	return array_values( array_diff( $gateways, array( 'venmo_link' ) ) );
}

/**
 * Order a currency => amount map with the site currency first, then by code.
 *
 * @param array<string,float> $totals Totals keyed by currency.
 * @return array<string,float>
 */
function npmp_sort_currency_totals( $totals ) {
	$site   = npmp_currency();
	$sorted = array();
	if ( isset( $totals[ $site ] ) ) {
		$sorted[ $site ] = $totals[ $site ];
	}
	$rest = $totals;
	unset( $rest[ $site ] );
	ksort( $rest );
	return $sorted + $rest;
}

/**
 * Collapse rows of { currency, total } into a currency => total map. Rows with
 * no currency count as USD. Amounts are only ever added to amounts in the
 * same currency.
 *
 * @param array $rows Rows (arrays or objects) with currency and total keys.
 * @param string $amount_key Key holding the amount.
 * @return array<string,float>
 */
function npmp_group_totals_by_currency( $rows, $amount_key = 'total' ) {
	$totals = array();
	foreach ( (array) $rows as $row ) {
		$row      = (array) $row;
		$currency = npmp_record_currency( $row['currency'] ?? '' );
		$totals[ $currency ] = ( $totals[ $currency ] ?? 0.0 ) + (float) ( $row[ $amount_key ] ?? 0 );
	}
	return npmp_sort_currency_totals( $totals );
}

/**
 * Format a set of per-currency totals for one table cell.
 *
 * One currency reads exactly as a single amount always has. Several are
 * listed side by side with their codes ("$1,200.00 USD · £300.00 GBP"),
 * never added together.
 *
 * @param array<string,float> $totals   Totals keyed by currency.
 * @param string|null         $fallback Currency to show 0 in when $totals is empty.
 * @return string
 */
function npmp_format_amounts_by_currency( $totals, $fallback = null ) {
	$totals = is_array( $totals ) ? $totals : array();
	if ( ! $totals ) {
		return npmp_format_amount( 0, $fallback );
	}
	if ( 1 === count( $totals ) ) {
		$currency = (string) array_key_first( $totals );
		return npmp_format_amount( reset( $totals ), $currency );
	}

	$parts = array();
	foreach ( npmp_sort_currency_totals( $totals ) as $currency => $total ) {
		$formatted = npmp_format_amount( $total, $currency );
		$parts[]   = false === strpos( $formatted, $currency ) ? $formatted . ' ' . $currency : $formatted;
	}
	return implode( ' · ', $parts );
}

/**
 * Every currency the site's donation records are in, site currency first.
 *
 * Cached in an option and cleared whenever a donation is logged or removed,
 * because the dashboards ask on every load and the answer rarely changes.
 *
 * @return string[]
 */
function npmp_donation_currencies() {
	$cached = function_exists( 'get_option' ) ? get_option( 'npmp_donation_currencies_cache', false ) : false;
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$found = array();
	global $wpdb;
	if ( isset( $wpdb ) && is_object( $wpdb ) && class_exists( 'NPMP_Donation_Manager' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in an option right below.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT COALESCE( c.meta_value, '' )
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = 'publish'",
				NPMP_Donation_Manager::META_CURRENCY,
				NPMP_Donation_Manager::POST_TYPE
			)
		);
	}

	$codes = array();
	foreach ( (array) $found as $code ) {
		$codes[ npmp_record_currency( $code ) ] = 0.0;
	}
	$codes = array_keys( npmp_sort_currency_totals( $codes ) );

	if ( function_exists( 'update_option' ) ) {
		update_option( 'npmp_donation_currencies_cache', $codes, false );
	}

	return $codes;
}

/**
 * Forget the cached currency list. Called after a donation is logged,
 * trashed or deleted.
 *
 * @return void
 */
function npmp_flush_donation_currencies() {
	if ( function_exists( 'delete_option' ) ) {
		delete_option( 'npmp_donation_currencies_cache' );
	}
}

/**
 * Clear the cache when a donation post goes away.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function npmp_flush_donation_currencies_on_delete( $post_id ) {
	if ( function_exists( 'get_post_type' ) && 'npmp_donation' === get_post_type( $post_id ) ) {
		npmp_flush_donation_currencies();
	}
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'before_delete_post', 'npmp_flush_donation_currencies_on_delete' );
	add_action( 'wp_trash_post', 'npmp_flush_donation_currencies_on_delete' );
	add_action( 'untrashed_post', 'npmp_flush_donation_currencies_on_delete' );
}
