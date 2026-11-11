<?php
/**
 * Real-WordPress check that a CSV import can carry the day each contact first
 * joined into the "Added" column, which is the contact post's own date.
 *
 *   wp eval-file tests/rig/import-added-date.php
 *
 * It writes a CSV to the system temp dir, imports it, and deletes every
 * contact it made (addresses under @added.test) and the CSV.
 *
 * Three things only a real WordPress can show:
 *  - post_date and post_date_gmt both land, an hour apart or five depending on
 *    the site's timezone, so the Added column and any GMT query agree.
 *  - a contact dated in the future stays "publish". WordPress turns a
 *    published post dated more than a minute ahead into a scheduled one, and
 *    every contact query asks for publish only, so that contact would vanish.
 *  - re-importing with a blank date column leaves the dates already stored.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

$tz = wp_timezone_string();
printf( "site timezone: %s\n", $tz );

$csv_path = get_temp_dir() . 'npmp-rig-added-date.csv';

$write_csv = function ( $rows ) use ( $csv_path ) {
	$fh = fopen( $csv_path, 'w' );
	fputcsv( $fh, array( 'Email', 'First name', 'Last name', 'Status', 'Confirmation time' ) );
	foreach ( $rows as $row ) {
		fputcsv( $fh, $row );
	}
	fclose( $fh );
	return $csv_path;
};

// Column order above. The wizard would auto-detect this mapping.
$mapping = array(
	0 => 'email',
	1 => 'first_name',
	2 => 'last_name',
	3 => 'status',
	4 => 'created_at',
);

$import = NPMP_Import_Manager::get_instance();
$mm     = NPMP_Member_Manager::get_instance();

$detected = $import->detect_columns( array( 'Email', 'First name', 'Last name', 'Status', 'Confirmation time' ) );
$ok( 'the wizard auto-maps a MailPoet header row to this mapping', $mapping === $detected );

$future = gmdate( 'Y-m-d H:i:s', time() + ( 40 * DAY_IN_SECONDS ) );

$write_csv(
	array(
		array( 'pete@added.test', 'Pete', 'Mailpoet', 'subscribed', '2019-03-14 11:22:33' ),
		array( 'blank@added.test', 'Blanche', 'Nodate', 'subscribed', '' ),
		array( 'junk@added.test', 'Jun', 'Kcell', 'subscribed', 'sometime in 2019' ),
		array( 'future@added.test', 'Fu', 'Ture', 'subscribed', $future ),
		array( 'slash@added.test', 'Sal', 'Ash', 'subscribed', '03/04/2019' ),
	)
);

$before = time();
$stats  = $import->import_csv( $csv_path, $mapping, array( 'duplicate_handling' => 'skip', 'source' => 'rig' ) );
$after  = time();

$ok( 'five rows imported', is_array( $stats ) && 5 === (int) $stats['imported'] );
$ok( 'no errors', is_array( $stats ) && 0 === (int) $stats['errors'] );

$member = function ( $email ) use ( $mm ) {
	return $mm->get_member_by_email( $email );
};

$pete = $member( 'pete@added.test' );
$ok( 'the MailPoet date is the contact\'s Added date', $pete && '2019-03-14 11:22:33' === $pete->created_at );
$ok(
	'and post_date_gmt matches it in UTC',
	$pete && get_gmt_from_date( '2019-03-14 11:22:33' ) === get_post_field( 'post_date_gmt', $pete->id, 'raw' )
);
$ok( 'the contact is published, so every contact query finds it', $pete && 'publish' === get_post_status( $pete->id ) );

$slash = $member( 'slash@added.test' );
$ok( 'a slash date is read month first', $slash && '2019-03-04 00:00:00' === $slash->created_at );

// A blank or unreadable cell falls back to the time of the import, which is
// what a contact added by hand has always had.
$at_import_time = function ( $member ) use ( $before, $after ) {
	if ( ! $member ) {
		return false;
	}
	$stamp = strtotime( get_post_field( 'post_date_gmt', $member->id, 'raw' ) . ' UTC' );
	return $stamp >= $before - 2 && $stamp <= $after + 2;
};

$ok( 'a blank date cell falls back to the time of the import', $at_import_time( $member( 'blank@added.test' ) ) );
$ok( 'so does a cell no date reader understands', $at_import_time( $member( 'junk@added.test' ) ) );

$fut = $member( 'future@added.test' );
$ok( 'a date in the future falls back to the time of the import', $at_import_time( $fut ) );
$ok( 'and that contact stays published rather than scheduled', $fut && 'publish' === get_post_status( $fut->id ) );

// Re-import the same list with "update existing", a corrected date for Pete
// and nothing for the others. Only Pete's date moves.
$blank_pete_date = $member( 'blank@added.test' )->created_at;
$write_csv(
	array(
		array( 'pete@added.test', 'Pete', 'Mailpoet', 'subscribed', '2017-06-01 09:00:00' ),
		array( 'blank@added.test', 'Blanche', 'Nodate', 'subscribed', '' ),
	)
);
$stats2 = $import->import_csv( $csv_path, $mapping, array( 'duplicate_handling' => 'update', 'source' => 'rig' ) );

$ok( 'two rows updated', is_array( $stats2 ) && 2 === (int) $stats2['updated'] );
$ok( 'a corrected date moves the Added date', '2017-06-01 09:00:00' === $member( 'pete@added.test' )->created_at );
$ok( 'a blank cell leaves the date already stored', $blank_pete_date === $member( 'blank@added.test' )->created_at );

// The members list sorts on this column, so the oldest contact has to come
// first when the list is ordered by Added.
$sorted = $mm->get_members(
	array(
		'search'   => '@added.test',
		'per_page' => -1,
		'orderby'  => 'created_at',
		'order'    => 'ASC',
	)
);
$first = $sorted ? (object) reset( $sorted ) : null;
$ok( 'the members list sorted by Added puts the oldest signup first', $first && 'pete@added.test' === $first->email );

foreach ( array( 'pete', 'blank', 'junk', 'future', 'slash' ) as $who ) {
	$m = $member( $who . '@added.test' );
	if ( $m ) {
		wp_delete_post( $m->id, true );
	}
}
@unlink( $csv_path );

printf( "\n%d failed\n", $fails );
exit( $fails ? 1 : 0 );
