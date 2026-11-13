<?php
/**
 * File path: includes/import/past-gifts.php
 *
 * Shared writer for gifts brought over from another donation plugin (GiveWP,
 * Charitable).
 *
 * A past gift becomes an npmp_donation post with the same meta a live gift
 * gets (NPMP_Donation_Manager), plus _npmp_import_key so a second run skips
 * it. It never goes through NPMP_Donation_Manager::log_donation(), because
 * that fires npmp_donation_recorded, and Pro's automations would mail a
 * receipt or a welcome for a gift someone made years ago.
 *
 * Donors follow the free import total (import-cap.php): a donor who isn't a
 * contact yet needs a free place, and npmp_import_row_imported fires with the
 * status 'imported' so the place gets counted. A donor with no place left
 * stays in the old plugin with every one of their gifts, so a contact's
 * totals never show part of their giving.
 *
 * Nothing here reads or copies a payment key, a webhook secret, or a gateway
 * setting.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Meta key holding "<source>:<id>" on an imported gift. */
if ( ! defined( 'NPMP_PAST_GIFT_KEY' ) ) {
	define( 'NPMP_PAST_GIFT_KEY', '_npmp_import_key' );
}

// =====================================================================
// Pure helpers, covered by tests/test-past-gifts.php
// =====================================================================

/**
 * The import key for one gift.
 *
 * @param string $source Source slug, such as givewp.
 * @param mixed  $id     The gift's ID in the old plugin.
 * @return string
 */
function npmp_past_gift_key( $source, $id ) {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $source ) ) . ':' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id );
}

/**
 * Turn a billing period into the frequency words free stores.
 *
 * @param string $period    day, week, month, quarter, or year (GiveWP), or
 *                          the same words Charitable Recurring uses.
 * @param int    $frequency Every how many periods (2 with month is every two months).
 * @return string one_time, weekly, monthly, quarterly, annual, or '' when it
 *                doesn't fit one of those.
 */
function npmp_past_gift_frequency( $period, $frequency = 1 ) {
	$period    = strtolower( trim( (string) $period ) );
	$frequency = max( 1, (int) $frequency );
	if ( '' === $period ) {
		return 'one_time';
	}
	$map = array(
		'week'    => array( 1 => 'weekly' ),
		'weekly'  => array( 1 => 'weekly' ),
		'month'   => array( 1 => 'monthly', 3 => 'quarterly', 12 => 'annual' ),
		'monthly' => array( 1 => 'monthly' ),
		'quarter' => array( 1 => 'quarterly' ),
		'quarterly' => array( 1 => 'quarterly' ),
		'year'    => array( 1 => 'annual' ),
		'yearly'  => array( 1 => 'annual' ),
		'annual'  => array( 1 => 'annual' ),
	);
	return $map[ $period ][ $frequency ] ?? '';
}

/**
 * Clean a gateway ID for display. Free shows it through npmp_gateway_label().
 *
 * @param string $gateway Gateway ID from the old plugin.
 * @return string
 */
function npmp_past_gift_gateway( $gateway ) {
	$gateway = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $gateway ) );
	$gateway = trim( $gateway, '_' );
	if ( '' === $gateway ) {
		return 'manual';
	}
	foreach ( array( 'stripe', 'paypal' ) as $known ) {
		if ( 0 === strpos( $gateway, $known ) ) {
			return $known;
		}
	}
	return $gateway;
}

/**
 * Parse an amount stored as a decimal string, never trusting float formatting.
 *
 * @param mixed $amount Such as "25.00" or 25.
 * @return float 0.0 when it isn't a positive number.
 */
function npmp_past_gift_amount( $amount ) {
	if ( is_string( $amount ) ) {
		$amount = trim( $amount );
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			return 0.0;
		}
	}
	$value = round( (float) $amount, 3 );
	return $value > 0 ? $value : 0.0;
}

// =====================================================================
// Writing
// =====================================================================

/**
 * Find a gift you already brought over.
 *
 * @param string $key Import key.
 * @return int Donation post ID, or 0.
 */
function npmp_past_gift_find( $key ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup per gift.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", NPMP_PAST_GIFT_KEY, (string) $key ) );
}

/**
 * Find the donor's contact, or make one while free places are left.
 *
 * @param string $email  Email.
 * @param string $name   Name.
 * @param string $source Source slug, saved as the contact's source.
 * @param array  $extra  Contact fields to fill on a new contact (address and so on).
 * @return array{result:string,id:int} result is existing, created, over_limit, or invalid.
 */
function npmp_past_gift_donor( $email, $name, $source, array $extra = array() ) {
	$email = sanitize_email( (string) $email );
	if ( '' === $email || ! is_email( $email ) || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return array( 'result' => 'invalid', 'id' => 0 );
	}
	$members = NPMP_Member_Manager::get_instance();
	$member  = $members->get_member_by_email( $email );
	if ( $member ) {
		return array( 'result' => 'existing', 'id' => (int) $member->id );
	}
	if ( function_exists( 'npmp_import_free_remaining' ) && npmp_import_free_remaining() < 1 ) {
		return array( 'result' => 'over_limit', 'id' => 0 );
	}

	$record = array_merge(
		array_intersect_key( $extra, array_flip( array( 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country' ) ) ),
		array(
			'name'   => sanitize_text_field( (string) $name ),
			'email'  => $email,
			'status' => 'donor',
			'source' => sanitize_key( $source ),
		)
	);
	// Written as an import, so Pro's welcome automation skips the donor.
	$id = function_exists( 'npmp_import_run_as_import' )
		? npmp_import_run_as_import(
			static function () use ( $members, $record ) {
				return $members->add_member( $record );
			}
		)
		: $members->add_member( $record );
	if ( is_wp_error( $id ) || ! $id ) {
		return array( 'result' => 'invalid', 'id' => 0 );
	}

	/** This action is documented in includes/import/class-import-manager.php. */
	do_action(
		'npmp_import_row_imported',
		(int) $id,
		$record,
		$extra,
		array(),
		'imported',
		array(
			'source'             => sanitize_key( $source ),
			'duplicate_handling' => 'update',
		)
	);
	return array( 'result' => 'created', 'id' => (int) $id );
}

/**
 * Record one past gift, or skip it when you brought it over before.
 *
 * @param array $gift {
 *     @type string $source         Source slug, such as givewp.
 *     @type mixed  $source_id      The gift's ID in the old plugin.
 *     @type string $email          Donor email.
 *     @type string $name           Donor name.
 *     @type mixed  $amount         Amount as a decimal string.
 *     @type string $currency       ISO 4217 code.
 *     @type string $date_gmt       When the gift was made, UTC, Y-m-d H:i:s.
 *     @type string $gateway        Gateway ID in the old plugin.
 *     @type string $frequency      From npmp_past_gift_frequency().
 *     @type string $transaction_id Gateway transaction ID, if any.
 * }
 * @return string created, exists, or invalid.
 */
function npmp_past_gift_record( array $gift ) {
	$key = npmp_past_gift_key( $gift['source'] ?? '', $gift['source_id'] ?? '' );
	if ( npmp_past_gift_find( $key ) ) {
		return 'exists';
	}

	$email    = sanitize_email( (string) ( $gift['email'] ?? '' ) );
	$amount   = npmp_past_gift_amount( $gift['amount'] ?? 0 );
	$currency = npmp_normalize_currency_code( $gift['currency'] ?? '' );
	$date_gmt = (string) ( $gift['date_gmt'] ?? '' );
	$stamp    = strtotime( $date_gmt . ' UTC' );
	if ( '' === $email || $amount <= 0 || '' === $currency || false === $stamp ) {
		return 'invalid';
	}
	$name      = sanitize_text_field( (string) ( $gift['name'] ?? '' ) );
	$frequency = (string) ( $gift['frequency'] ?? 'one_time' );
	$meta      = array(
		NPMP_Donation_Manager::META_EMAIL     => $email,
		NPMP_Donation_Manager::META_AMOUNT    => $amount,
		NPMP_Donation_Manager::META_FREQUENCY => '' === $frequency ? 'one_time' : $frequency,
		NPMP_Donation_Manager::META_GATEWAY   => npmp_past_gift_gateway( $gift['gateway'] ?? '' ),
		NPMP_Donation_Manager::META_CURRENCY  => $currency,
		NPMP_PAST_GIFT_KEY                    => $key,
		'_npmp_import_source'                 => sanitize_key( (string) ( $gift['source'] ?? '' ) ),
	);
	$txn = sanitize_text_field( (string) ( $gift['transaction_id'] ?? '' ) );
	if ( '' !== $txn ) {
		$meta[ NPMP_Donation_Manager::META_TXN_ID ] = $txn;
	}

	$post_id = wp_insert_post(
		array(
			'post_type'     => NPMP_Donation_Manager::POST_TYPE,
			'post_status'   => 'publish',
			/* translators: 1: Donor name, 2: Donor email address. */
			'post_title'    => '' !== $name ? sprintf( __( '%1$s (%2$s)', 'nonprofit-manager' ), $name, $email ) : $email,
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $stamp ) ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $stamp ),
			'meta_input'    => $meta,
		),
		true
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 'invalid';
	}
	if ( function_exists( 'npmp_flush_donation_currencies' ) ) {
		$known = get_option( 'npmp_donation_currencies_cache', false );
		if ( ! is_array( $known ) || ! in_array( $currency, $known, true ) ) {
			npmp_flush_donation_currencies();
		}
	}
	return 'created';
}

/**
 * Bring the contact's giving totals up to date after their gifts came over.
 *
 * NPMP_Member_Manager::record_donation() counts every gift on file for the
 * email, so the last gift date comes from the gifts, never from today.
 *
 * @param string $email   Donor email.
 * @param string $name    Donor name.
 * @param string $gateway Gateway of their latest gift.
 * @return void
 */
function npmp_past_gift_refresh_donor( $email, $name, $gateway ) {
	if ( class_exists( 'NPMP_Member_Manager' ) ) {
		NPMP_Member_Manager::get_instance()->record_donation(
			array(
				'email'   => $email,
				'name'    => $name,
				'gateway' => npmp_past_gift_gateway( $gateway ),
			)
		);
	}
}
