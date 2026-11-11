<?php
/**
 * "Date Added" on a contact import (includes/import/date-added.php).
 *
 * Until 2026.11.3 an import had nowhere to put the day a contact joined, so a
 * list moved from MailPoet showed every contact as added on import day
 * (wordpress.org thread "Support Added when importing csv").
 *
 * Run: php tests/test-import-added-date.php
 */

require_once __DIR__ . '/bootstrap.php';
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/date-added.php';

$london = new DateTimeZone( 'Europe/London' );
$now    = new DateTimeImmutable( '2026-11-11 12:00:00', new DateTimeZone( 'UTC' ) );
$parse  = function ( $raw ) use ( $london, $now ) {
	return npmp_import_parse_added_date( $raw, $london, $now );
};

// MailPoet's "Confirmation time": site-local, no offset.
$d = $parse( '2024-07-15 09:30:00' );
check( 'MailPoet local time keeps its wall clock', '2024-07-15 09:30:00', $d['local'] ?? null );
check( 'MailPoet local time, GMT is an hour behind in BST', '2024-07-15 08:30:00', $d['gmt'] ?? null );

// Mailchimp's timestamp_opt: ISO 8601 with its own offset.
$d = $parse( '2023-01-05T18:00:00+00:00' );
check( 'ISO 8601 with offset, local in GMT winter', '2023-01-05 18:00:00', $d['local'] ?? null );
$d = $parse( '2023-07-05T18:00:00-07:00' );
check( 'ISO 8601 from Pacific time converts to the site zone', '2023-07-06 02:00:00', $d['local'] ?? null );
check( 'ISO 8601 from Pacific time, GMT', '2023-07-06 01:00:00', $d['gmt'] ?? null );

// Date only.
$d = $parse( '2020-03-01' );
check( 'Date only is midnight local', '2020-03-01 00:00:00', $d['local'] ?? null );

// Unix timestamp.
$d = $parse( '1700000000' );
check( 'Unix timestamp', '2023-11-14 22:13:20', $d['gmt'] ?? null );

// Slash dates: day first when the first part can't be a month.
$d = $parse( '31/01/2024' );
check( 'Day-first slash date', '2024-01-31 00:00:00', $d['local'] ?? null );
$d = $parse( '01/31/2024' );
check( 'Month-first slash date', '2024-01-31 00:00:00', $d['local'] ?? null );

// Values that must leave the contact's date alone.
check( 'Empty cell', null, $parse( '' ) );
check( 'Whitespace cell', null, $parse( '   ' ) );
check( 'Garbage', null, $parse( 'not a date' ) );
check( 'Impossible date', null, $parse( '2024-02-31' ) );
check( 'Future date', null, $parse( '2027-01-01' ) );
check( 'Array value', null, $parse( array( '2024-01-01' ) ) );
$d = $parse( '2026-11-11 20:00:00' );
check( 'Later today is allowed (sheet saved a zone ahead)', '2026-11-11 20:00:00', $d['local'] ?? null );

// Wiring: the importer offers the field and auto-maps the headers Pete's
// MailPoet export and Mailchimp's CSV use, and the member manager applies it.
$manager = file_get_contents( $root . '/includes/import/class-import-manager.php' );
check( 'Import offers a Date Added field', 1, preg_match( "/'created_at'\s*=>\s*'Date Added'/", $manager ) );
foreach ( array( 'confirmation time', 'date added', 'optin_time', 'created at' ) as $header ) {
	check( "Header '$header' maps to Date Added", 1, preg_match( "/'created_at'\s*=>\s*array\([^)]*'" . preg_quote( $header, '/' ) . "'/", $manager ) );
}
check( 'Mailchimp rows carry timestamp_opt', 1, preg_match( "/'timestamp_opt'\s*=>\s*isset\( \\\$mc_member\['timestamp_opt'\] \)/", $manager ) );
check( 'Mailchimp API asks for timestamp_opt', 1, substr_count( file_get_contents( $root . '/includes/import/mailchimp-api.php' ), 'members.timestamp_opt' ) );

$members = file_get_contents( $root . '/includes/npmp-email-settings.php' );
check( 'add_member sets post_date from Date Added', 1, preg_match( "/\\\$postarr\['post_date'\]\s*=\s*\\\$added\['local'\]/", $members ) );
check( 'update_member only moves Added earlier', 1, preg_match( "/strtotime\( \\\$added\['local'\] \) < strtotime\( \\\$member->created_at \)/", $members ) );
check( 'Loader requires date-added.php', 1, substr_count( file_get_contents( $root . '/nonprofit-manager.php' ), "includes/import/date-added.php'" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
