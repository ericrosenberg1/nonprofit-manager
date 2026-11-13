<?php
/**
 * File path: includes/import/date-added.php
 *
 * Reads the "Date Added" column of an import, so a contact moved from
 * MailPoet, Mailchimp or a spreadsheet keeps the day it first joined instead
 * of the day it was imported.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_import_parse_added_date' ) ) {
	/**
	 * Turn an imported "date added" value into the contact's post dates.
	 *
	 * Accepts a Unix timestamp, an ISO 8601 date with its own offset (Mailchimp's
	 * timestamp_opt), or a plain date or date and time, which is read in the
	 * site's time zone (MailPoet's export). A slash date whose first part is
	 * over 12 is read day first (31/01/2024). Returns null for an empty value,
	 * one it can't read, one before 1970, or one in the future, so a bad cell
	 * leaves the contact's date alone.
	 *
	 * @param mixed                   $raw Cell value.
	 * @param DateTimeZone|null       $tz  Site time zone. wp_timezone() when null.
	 * @param DateTimeImmutable|null  $now The current time, for tests.
	 * @return array{local:string,gmt:string}|null post_date and post_date_gmt.
	 */
	function npmp_import_parse_added_date( $raw, $tz = null, $now = null ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		$tz  = $tz instanceof DateTimeZone ? $tz : ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		$utc = new DateTimeZone( 'UTC' );
		$now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', $utc );

		try {
			if ( preg_match( '/^\d{9,10}$/', $raw ) ) {
				$date = new DateTimeImmutable( '@' . $raw );
			} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})(.*)$#', $raw, $m ) && (int) $m[1] > 12 ) {
				$date = new DateTimeImmutable( sprintf( '%04d-%02d-%02d%s', $m[3], $m[2], $m[1], $m[4] ), $tz );
			} else {
				$date = new DateTimeImmutable( $raw, $tz );
			}
		} catch ( Exception $e ) {
			return null;
		}

		$errors = DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return null;
		}

		$ts = $date->getTimestamp();
		// A day of slack covers a sheet saved in a time zone ahead of the site.
		if ( $ts < 0 || $ts > $now->getTimestamp() + DAY_IN_SECONDS ) {
			return null;
		}

		return array(
			'local' => $date->setTimezone( $tz )->format( 'Y-m-d H:i:s' ),
			'gmt'   => $date->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}
}
