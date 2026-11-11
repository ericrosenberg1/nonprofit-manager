<?php
/**
 * Reading a date out of someone else's export (includes/npmp-date-input.php).
 *
 * The import wizard maps a column to the contact's "Added" date, so a list
 * moved over from MailPoet, Mailchimp or a spreadsheet keeps the day each
 * person signed up. A wrong date is worse than no date here: the fallback is
 * the time of the import, which the site owner can see is the import, while a
 * misread date looks like history.
 *
 * Run: php tests/test-date-input.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/npmp-date-input.php';

// 2026-11-11 12:00:00 UTC, so "the future" is fixed and the suite never
// starts failing on its own.
$now = 1794398400;

$utc = function ( $value ) use ( $now ) {
	return npmp_parse_date_input( $value, 'UTC', $now );
};
$ny = function ( $value ) use ( $now ) {
	return npmp_parse_date_input( $value, 'America/New_York', $now );
};

// MailPoet's "Confirmation time" column, which is what the forum request asked
// about. MailPoet writes a MySQL datetime.
check( 'MailPoet confirmation time', '2019-03-14 11:22:33', $utc( '2019-03-14 11:22:33' ) );
check( 'whitespace around the cell', '2019-03-14 11:22:33', $utc( "  2019-03-14 11:22:33\t" ) );
check( 'date with no time is midnight, not now', '2019-03-14 00:00:00', $utc( '2019-03-14' ) );
check( 'date and minutes', '2019-03-14 11:22:00', $utc( '2019-03-14 11:22' ) );

// Other shapes an export can use.
check( 'slash date is read month first', '2019-03-04 00:00:00', $utc( '03/04/2019' ) );
check( 'slash date that can only be day first', '2019-03-14 00:00:00', $utc( '14/03/2019' ) );
check( 'US slash date with a time', '2019-03-04 11:22:33', $utc( '03/04/2019 11:22:33' ) );
check( 'year first with slashes', '2019-03-14 00:00:00', $utc( '2019/03/14' ) );
check( 'dotted European date', '2019-03-14 00:00:00', $utc( '14.03.2019' ) );
check( 'written-out month', '2019-03-14 00:00:00', $utc( '14 March 2019' ) );
check( 'month then day, written out', '2019-03-14 00:00:00', $utc( 'March 14, 2019' ) );
check( 'Unix timestamp', '2019-03-14 11:22:33', $utc( '1552562553' ) );
check( 'Excel date serial, whole day', '2019-03-14 00:00:00', $utc( '43538' ) );
check( 'Excel date serial with a time', '2019-03-14 12:00:00', $utc( '43538.5' ) );

// Timezones. A value with no offset is the site's own clock, because that is
// the clock the Added column is shown on.
check( 'naked value stays on the site clock', '2019-03-14 11:22:33', $ny( '2019-03-14 11:22:33' ) );
check( 'ISO 8601 with Z converts to site time', '2019-03-14 07:22:33', $ny( '2019-03-14T11:22:33Z' ) );
check( 'ISO 8601 with an offset converts too', '2019-03-14 06:22:33', $ny( '2019-03-14T11:22:33+01:00' ) );

// Refusals. Every one of these leaves the caller with no date, which means
// the contact gets the time of the import.
check( 'empty cell', null, $utc( '' ) );
check( 'whitespace-only cell', null, $utc( "  \t " ) );
check( 'a word that is not a date', null, $utc( 'sometime in 2019' ) );
check( 'a status value in the wrong column', null, $utc( 'subscribed' ) );
check( 'impossible day', null, $utc( '2019-02-31' ) );
check( 'month 14', null, $utc( '14/32/2019' ) );
check( 'zero date from a MySQL dump', null, $utc( '0000-00-00 00:00:00' ) );
check( 'before 1970', null, $utc( '1965-04-02' ) );
check( 'the Unix epoch itself', null, $utc( '1970-01-01 00:00:00' ) );
check( 'tomorrow', null, npmp_parse_date_input( '2026-11-12 00:00:00', 'UTC', $now ) );
check( 'a year out', null, npmp_parse_date_input( '2027-06-01', 'UTC', $now ) );
check( 'a year on its own', null, $utc( '2019' ) );
check( 'a bare zero', null, $utc( '0' ) );
check( 'a number too big for a date serial', null, $utc( '999999' ) );
check( 'an array', null, npmp_parse_date_input( array( '2019-03-14' ), 'UTC', $now ) );
check( 'null', null, npmp_parse_date_input( null, 'UTC', $now ) );

// Right up to now is fine: an import of a list exported minutes ago.
check( 'this second', '2026-11-11 12:00:00', $utc( '2026-11-11 12:00:00' ) );
check( 'a minute ago', '2026-11-11 11:59:00', $utc( '2026-11-11 11:59:00' ) );

// An unreadable timezone name falls back to UTC rather than throwing.
check( 'nonsense timezone', '2019-03-14 11:22:33', npmp_parse_date_input( '2019-03-14 11:22:33', 'Mars/Olympus', $now ) );

// The caller can replace the format list, which is how a day-first site could
// read 03/04/2019 as 3 April.
check(
	'caller-supplied format wins',
	'2019-04-03 00:00:00',
	npmp_parse_date_input( '03/04/2019', 'UTC', $now, array( 'd/m/Y' ) )
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
