<?php
/**
 * File path: includes/npmp-date-input.php
 *
 * Reading a date that came from somewhere else: a spreadsheet cell, a CSV
 * column, another tool's export. Nothing in here calls WordPress, so
 * tests/test-date-input.php can pin it without booting WordPress. The caller
 * passes the site timezone, and a test passes a fixed "now".
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'npmp_date_input_formats' ) ) {
	/**
	 * Date formats tried in order, before PHP's own parser gets the string.
	 *
	 * Order settles the one real ambiguity. A slash date is read month first,
	 * which is what PHP does and what US exports mean, so 03/04/2019 is
	 * 4 March 2019. A slash date that can only be day first, like 14/03/2019,
	 * fails every month-first format and lands on the day-first ones at the
	 * end of the list. A dash date that is not ISO (14-03-2019) falls through
	 * to PHP, which reads dashes day first.
	 *
	 * @return string[] DateTimeImmutable::createFromFormat() format strings.
	 */
	function npmp_date_input_formats() {
		return array(
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d',
			'Y/m/d H:i:s',
			'Y/m/d H:i',
			'Y/m/d',
			'm/d/Y H:i:s',
			'm/d/Y H:i',
			'm/d/Y',
			'd.m.Y H:i:s',
			'd.m.Y H:i',
			'd.m.Y',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd/m/Y',
		);
	}
}

if ( ! function_exists( 'npmp_parse_date_input' ) ) {
	/**
	 * Turn a date someone else wrote into a MySQL datetime in the site's
	 * timezone, or null when the value can't be trusted.
	 *
	 * Accepts the formats in npmp_date_input_formats(), anything PHP's own
	 * parser understands (ISO 8601 with an offset, "14 March 2019"), a Unix
	 * timestamp, and an Excel date serial, which is what a date-formatted
	 * XLSX cell holds. Excel serials are read on the 1900 date system; a
	 * workbook saved on the old Mac 1904 system would come out four years
	 * early.
	 *
	 * A value with no timezone in it is read as the site's local time, which
	 * is the time the site owner sees in the admin. A value carrying an
	 * offset or a Z is converted to site time.
	 *
	 * Returns null, never a guess, for: an empty cell, a string no parser
	 * understands, a date before 1970, and a date in the future. The caller
	 * treats null as "no date given", so a bad cell falls back to the time of
	 * the import instead of writing a wrong date. Future dates are refused
	 * because WordPress turns a published post dated more than a minute ahead
	 * into a scheduled one, and a scheduled contact post disappears from
	 * every contact query.
	 *
	 * @param mixed                $value    Raw cell value.
	 * @param DateTimeZone|string  $timezone Site timezone. Default UTC.
	 * @param int|null             $now      Unix time to compare against, for tests. Default time().
	 * @param string[]|null        $formats  Formats to try. Default npmp_date_input_formats().
	 * @return string|null 'Y-m-d H:i:s' in $timezone, or null.
	 */
	function npmp_parse_date_input( $value, $timezone = 'UTC', $now = null, $formats = null ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		if ( $timezone instanceof DateTimeZone ) {
			$tz = $timezone;
		} else {
			try {
				$tz = new DateTimeZone( (string) $timezone );
			} catch ( Exception $e ) {
				$tz = new DateTimeZone( 'UTC' );
			}
		}

		$now  = ( null === $now ) ? time() : (int) $now;
		$date = null;

		if ( preg_match( '/^\d{9,11}$/', $value ) ) {
			// A bare second count: a Unix timestamp.
			$date = ( new DateTimeImmutable( '@' . $value ) )->setTimezone( $tz );
		} elseif ( preg_match( '/^\d{5}(\.\d+)?$/', $value ) && (float) $value >= 20000 && (float) $value <= 60000 ) {
			// An Excel date serial. Day 1 is 1900-01-02 on Excel's clock,
			// which counts a 1900-02-29 that never happened, so day 0 is
			// 1899-12-30 for every date from 1900-03-01 on. Whole days move
			// by calendar day, so a daylight-saving change between 1899 and
			// the date itself can't shift it by an hour.
			$days    = (int) floor( (float) $value );
			$seconds = (int) round( ( (float) $value - $days ) * 86400 );
			$date    = DateTimeImmutable::createFromFormat( '!Y-m-d', '1899-12-30', $tz )->modify( '+' . $days . ' days' );
			if ( $seconds > 0 ) {
				$date = $date->modify( '+' . $seconds . ' seconds' );
			}
		} elseif ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			// Some other bare number. A year on its own has no day in it, and
			// PHP would read "2019" as the time 20:19 today. Refuse instead.
			return null;
		} else {
			$formats = ( null === $formats ) ? npmp_date_input_formats() : (array) $formats;
			foreach ( $formats as $format ) {
				// The leading "!" zeroes every field the format does not set,
				// so a date with no time in it means midnight, not now.
				$try = DateTimeImmutable::createFromFormat( '!' . $format, $value, $tz );
				if ( false === $try ) {
					continue;
				}
				// Trailing junk and impossible dates (month 14, 31 February)
				// come back as warnings with an object, not as false.
				$errors = DateTimeImmutable::getLastErrors();
				if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
					continue;
				}
				$date = $try;
				break;
			}
		}

		if ( null === $date ) {
			// PHP's own parser, which handles ISO 8601 with an offset and
			// written-out months. It also rolls a date that does not exist
			// forward without complaining, turning 2019-02-31 into March 3,
			// so ask date_parse() what it thought of the string first and
			// refuse anything it had a warning about.
			$info = date_parse( $value );
			if ( ! empty( $info['error_count'] ) || ! empty( $info['warning_count'] ) ) {
				return null;
			}
			try {
				$date = new DateTimeImmutable( $value, $tz );
			} catch ( Exception $e ) {
				return null;
			}
		}

		$timestamp = $date->getTimestamp();
		if ( $timestamp <= 0 || $timestamp > $now ) {
			return null;
		}

		return $date->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
	}
}
