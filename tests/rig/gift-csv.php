<?php
/**
 * Real-WordPress run of past gifts from a file (includes/import/gift-csv-source.php)
 * on the fixtures in tests/fixtures/: Zeffy, Donorbox, Givebutter, and a
 * European spreadsheet. Each import request runs in its own `wp` process, the
 * way the screen's carry-on form sends them.
 *
 * It checks every gift's amount, currency, date, and frequency, that a contact
 * who unsubscribed stays unsubscribed, that no receipt or automation fires,
 * that a second run adds nothing, the free total when the file holds more new
 * donors than places left (Pro inactive), and that the source stays off the
 * Switch Plugins hub. `big` adds a 50,000-row file and times it.
 *
 * Needs a throwaway WordPress with this plugin active, members and donations
 * on, and `wp` on PATH. Run it with Pro inactive and again with Pro active:
 *   wp eval-file tests/rig/gift-csv.php
 *   wp eval-file tests/rig/gift-csv.php big
 *
 * It runs on New York time, since Zeffy's times read in the site's zone, and
 * puts the site's zone back at the end. It deletes every contact, gift, option,
 * and file it made, so the site ends as it started.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';
$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );

// ---------------------------------------------------------------------
// Child phase: one import request, with counters for side effects.
// ---------------------------------------------------------------------
if ( 0 === strpos( $phase, 'step' ) ) {
	$recorded = 0;
	$mails    = 0;
	add_action( 'npmp_donation_recorded', function () use ( &$recorded ) {
		++$recorded;
	} );
	add_filter( 'pre_wp_mail', function () use ( &$mails ) {
		++$mails;
		return true;
	} );
	$t0  = microtime( true );
	$msg = npmp_gift_csv_handle_run( 'step:restart' === $phase );
	echo 'JSON ', wp_json_encode(
		array(
			'msg'      => $msg,
			'state'    => npmp_gift_import_state( NPMP_GIFT_CSV_SOURCE ),
			'recorded' => $recorded,
			'mails'    => $mails,
			'seconds'  => round( microtime( true ) - $t0, 2 ),
			'peak_mb'  => round( memory_get_peak_usage() / 1048576, 1 ),
		)
	), "\n";
	return;
}

$child = function ( $phase ) {
	$cmd = sprintf( 'wp --path=%s eval-file %s %s 2>&1', escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( __FILE__ ), escapeshellarg( $phase ) );
	$out = array();
	exec( $cmd, $out );
	foreach ( $out as $line ) {
		$at = strpos( $line, 'JSON {' );
		if ( false !== $at ) {
			return json_decode( substr( $line, $at + 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === strpos( $l, 'Deprecated' );
	} ) ) );
};

// Run the import to the end, one process per request like the screen.
$import = function () use ( $child ) {
	$steps    = array();
	$recorded = 0;
	$mails    = 0;
	$r        = $child( 'step:restart' );
	while ( true ) {
		if ( isset( $r['error'] ) ) {
			return array( 'error' => $r['error'] );
		}
		$steps[]   = $r;
		$recorded += (int) $r['recorded'];
		$mails    += (int) $r['mails'];
		if ( empty( $r['state']['running'] ) || count( $steps ) > 500 ) {
			break;
		}
		$r = $child( 'step' );
	}
	$last = end( $steps );
	return array(
		'results'  => $last['state']['results'],
		'steps'    => count( $steps ),
		'recorded' => $recorded,
		'mails'    => $mails,
		'max_secs' => max( array_column( $steps, 'seconds' ) ),
		'peak_mb'  => max( array_column( $steps, 'peak_mb' ) ),
		'secs'     => array_sum( array_column( $steps, 'seconds' ) ),
	);
};

$fixtures = dirname( __DIR__ ) . '/fixtures/';
$pro      = function_exists( 'npmp_is_pro' ) && npmp_is_pro();
$members  = NPMP_Member_Manager::get_instance();
printf( "Pro %s, free total %s\n", $pro ? 'active' : 'inactive', npmp_import_is_capped() ? 'on' : 'off' );

// Zeffy's times read in the site's zone, and the checks below expect New York.
// Each import step runs in its own `wp` process, so set the option, and put
// the site's zone back at the end.
$saved_tz     = get_option( 'timezone_string' );
$saved_offset = get_option( 'gmt_offset' );
update_option( 'timezone_string', 'America/New_York' );

// Copy a fixture into the private folder the way an upload lands, then start.
$start = function ( $fixture ) {
	$dir    = npmp_import_private_dir();
	$target = $dir . 'import-rig-' . wp_generate_password( 12, false ) . '.csv';
	copy( $fixture, $target );
	return npmp_gift_csv_start_job( $target, basename( $fixture ) );
};
$gifts_for = function ( $email ) use ( $wpdb ) {
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_date_gmt, p.post_date,
			MAX(CASE WHEN m.meta_key = '_npmp_donation_amount' THEN m.meta_value END) AS amount,
			MAX(CASE WHEN m.meta_key = '_npmp_donation_currency' THEN m.meta_value END) AS currency,
			MAX(CASE WHEN m.meta_key = '_npmp_donation_frequency' THEN m.meta_value END) AS frequency,
			MAX(CASE WHEN m.meta_key = '_npmp_donation_gateway' THEN m.meta_value END) AS gateway,
			MAX(CASE WHEN m.meta_key = '_npmp_import_key' THEN m.meta_value END) AS import_key
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_npmp_donation_email' AND e.meta_value = %s
		 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
		 WHERE p.post_type = 'npmp_donation'
		 GROUP BY p.ID ORDER BY p.post_date_gmt ASC",
		$email
	), ARRAY_A );
};
$csv_gift_count = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_source' AND meta_value = 'gift_csv'" );
};
$cleanup = function ( array $emails ) use ( $wpdb, $members ) {
	$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_source' AND meta_value = 'gift_csv'" );
	foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
		$in = implode( ',', $chunk );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
	}
	$contact_ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'npmp_email' WHERE p.post_type = 'npmp_contact' AND ( m.meta_value LIKE '%@example.org' )" );
	foreach ( array_chunk( array_map( 'intval', $contact_ids ), 500 ) as $chunk ) {
		$in = implode( ',', $chunk );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
	}
	unset( $emails );
	npmp_gift_csv_forget_job();
	wp_cache_flush();
	if ( function_exists( 'npmp_flush_donation_currencies' ) ) {
		npmp_flush_donation_currencies();
	}
};

$before = array(
	'contacts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ),
	'donations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_donation'" ),
);
$ok( 'members and donations are on', function_exists( 'npmp_gift_csv_start_job' ) && class_exists( 'NPMP_Donation_Manager' ) );
$ok( 'no example.org contacts before the run', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE '%@example.org'" ), 'run the cleanup first' );

// ---------------------------------------------------------------------
// 50,000 rows
// ---------------------------------------------------------------------
if ( 'big' === $phase ) {
	$head  = npmp_gift_csv_read_head( $fixtures . 'gift-csv-givebutter.csv' );
	$cols  = array_flip( $head['headers'] );
	$dir   = npmp_import_private_dir();
	$path  = $dir . 'import-rig-big-' . wp_generate_password( 12, false ) . '.csv';
	$fh    = fopen( $path, 'wb' );
	fputcsv( $fh, $head['headers'], ',', '"', '' );
	$blank = array_fill( 0, count( $head['headers'] ), '' );
	for ( $i = 1; $i <= 50000; $i++ ) {
		$r                                    = $blank;
		$r[ $cols['Reference Number'] ]       = 'BIG' . $i;
		$r[ $cols['Email'] ]                  = 'big' . ( $i % 20000 ) . '@example.org';
		$r[ $cols['First Name'] ]             = 'Big';
		$r[ $cols['Last Name'] ]              = 'Donor ' . ( $i % 20000 );
		$r[ $cols['Status Friendly'] ]        = 0 === $i % 50 ? 'failed' : 'succeeded';
		$r[ $cols['Amount'] ]                 = number_format( 5 + ( $i % 997 ), 2 );
		$r[ $cols['Currency'] ]               = 'USD';
		$r[ $cols['Transaction Date (UTC)'] ] = gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 600 );
		fputcsv( $fh, $r, ',', '"', '' );
	}
	fclose( $fh );
	$ok( 'big file starts', '' === npmp_gift_csv_start_job( $path, 'big.csv' ) );
	$t0  = microtime( true );
	$msg = npmp_gift_csv_handle_map( array() );
	$job = npmp_gift_csv_job();
	printf( "  INFO  check pass on %s rows took %.2fs\n", number_format( $job['summary']['rows'] ?? 0 ), microtime( true ) - $t0 );
	$ok( 'big file checks', '' === $msg && 49000 === (int) $job['summary']['ready'], $msg );
	$run = $import();
	$r   = $run['results'] ?? array();
	printf( "  INFO  %d requests, %.1fs in all, longest %.1fs, peak memory %.1f MB\n", $run['steps'] ?? 0, $run['secs'] ?? 0, $run['max_secs'] ?? 0, $run['peak_mb'] ?? 0 );
	$ok( 'no request runs past 30 seconds', ( $run['max_secs'] ?? 99 ) < 30, $run );
	$ok( 'no request uses more than 128 MB', ( $run['peak_mb'] ?? 999 ) < 128, $run );
	if ( $pro ) {
		$ok( 'Pro: every gift comes over', 49000 === (int) ( $r['gifts_created'] ?? 0 ), $r );
		$ok( 'Pro: every donor', 19600 === (int) ( $r['donors_created'] ?? 0 ), $r );
	} else {
		$left = (int) $before['contacts'] >= 0 ? $r : array();
		$ok( 'free: new donors stop at the places left', (int) ( $r['donors_created'] ?? 0 ) <= NPMP_IMPORT_FREE_MAX_ROWS && (int) ( $r['over_limit'] ?? 0 ) > 0, $left );
		$ok( 'free: donors past it keep every gift out', (int) ( $r['gifts_created'] ?? 0 ) + (int) ( $r['over_limit_gifts'] ?? 0 ) === 49000, $r );
	}
	$ok( 'big: no receipt or automation', 0 === ( $run['recorded'] ?? 1 ) && 0 === ( $run['mails'] ?? 1 ), $run );
	$ok( 'big: gifts on file match', (int) ( $r['gifts_created'] ?? -1 ) === $csv_gift_count() );
	$cleanup( array() );
	$ok( 'big: site as it was', $before['donations'] === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_donation'" ) );
	printf( "\n%s\n", $fails ? "{$fails} FAILED" : 'ALL PASSED' );
	return;
}

// ---------------------------------------------------------------------
// Off the hub, and off the plugin-switch gift screen
// ---------------------------------------------------------------------
$ok( 'not a Switch Plugins source', ! isset( npmp_import_sources()[ NPMP_GIFT_CSV_SOURCE ] ) );
$ok( 'not on the plugin-switch gift screen', ! isset( npmp_gift_import_sources()[ NPMP_GIFT_CSV_SOURCE ] ) );
ob_start();
npmp_gift_csv_import_card();
$card = ob_get_clean();
$ok( 'the Import screen card links to the screen', false !== strpos( $card, 'page=npmp_gift_csv_import' ) );
$ok( 'the Import screen prints the cards hook', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/import/admin-import.php' ), "do_action( 'npmp_import_source_cards' )" ) );
$mixed_totals = npmp_gift_csv_totals_text( array( 'USD' => 2625, 'CAD' => 40 ) );
$ok( 'mixed totals name each currency, since USD and CAD share $', false !== strpos( $mixed_totals, 'USD' ) && false !== strpos( $mixed_totals, 'CAD' ) );
$ok( 'one currency prints no code', false === strpos( npmp_gift_csv_totals_text( array( 'USD' => 25 ) ), 'USD' ) );

// ---------------------------------------------------------------------
// Zeffy, with one donor already a contact who unsubscribed
// ---------------------------------------------------------------------
$ivy_id = $members->add_member( array( 'email' => 'ivy@example.org', 'name' => 'Ivy Fakename', 'status' => 'unsubscribed' ) );
$ok( 'Zeffy starts', '' === $start( $fixtures . 'gift-csv-zeffy.csv' ) );
$job = npmp_gift_csv_job();
$ok( 'Zeffy preset found', 'zeffy' === $job['preset'] );
$ok( 'Zeffy reads in the site zone', 'site' === $job['settings']['timezone'] );
$ok( 'Zeffy checks', '' === npmp_gift_csv_handle_map( array() ) );
$job = npmp_gift_csv_job();
$ok( 'Zeffy: 3 ready, Stopped listed', 3 === (int) $job['summary']['ready'] && isset( $job['summary']['statuses']['stopped'] ), $job['summary'] );
$run = $import();
wp_cache_flush();
$r   = $run['results'];
$ok( 'Zeffy: 3 gifts', 3 === (int) $r['gifts_created'], $r );
$ok( 'Zeffy: ivy existing, pat new', 1 === (int) $r['donors_existing'] && 1 === (int) $r['donors_created'], $r );
$ivy = $members->get_member_by_email( 'ivy@example.org' );
$ok( 'ivy stays unsubscribed', 'unsubscribed' === $ivy->status, $ivy->status );
$g = $gifts_for( 'ivy@example.org' );
$ok( 'ivy: $100 and the $60 kept from a partial refund', array( '100', '60' ) === array_map( function ( $x ) {
	return rtrim( rtrim( number_format( (float) $x['amount'], 3, '.', '' ), '0' ), '.' );
}, $g ), $g );
$ok( 'ivy: 03/14/2025 10:22 New York is 14:22 UTC', '2025-03-14 14:22:00' === ( $g[0]['post_date_gmt'] ?? '' ) && '2025-03-14 10:22:00' === ( $g[0]['post_date'] ?? '' ), $g );
$ok( 'ivy: totals on the contact', 2 === (int) $ivy->donation_count && abs( 160 - (float) $ivy->donation_total ) < 0.01, array( $ivy->donation_count, $ivy->donation_total ) );
$ok( 'no receipt or automation', 0 === $run['recorded'] && 0 === $run['mails'], $run );
// Tick Stopped, check again, import again: only Nia's gift is new.
$ok( 'Stopped ticked', '' === npmp_gift_csv_handle_map( array( 'accept_sent' => '1', 'accept' => array( 'stopped' ) ) ) );
$run = $import();
$r   = $run['results'];
$ok( 'second run: one new gift, three skipped', 1 === (int) $r['gifts_created'] && 3 === (int) $r['gifts_exists'], $r );
$run = $import();
$ok( 'third run adds nothing', 0 === (int) $run['results']['gifts_created'] && 4 === (int) $run['results']['gifts_exists'], $run['results'] );

// ---------------------------------------------------------------------
// Donorbox (generic mapper)
// ---------------------------------------------------------------------
$ok( 'Donorbox starts', '' === $start( $fixtures . 'gift-csv-donorbox.csv' ) );
$ok( 'Donorbox has no preset', '' === npmp_gift_csv_job()['preset'] );
$ok( 'Donorbox checks in UTC', '' === npmp_gift_csv_handle_map( array( 'timezone' => 'utc' ) ) );
$run = $import();
wp_cache_flush();
$ok( 'Donorbox: 4 gifts', 4 === (int) $run['results']['gifts_created'], $run['results'] );
$q = $gifts_for( 'quinn@example.org' );
$ok( 'quinn: EUR 1234.56 one-time', 'EUR' === ( $q[0]['currency'] ?? '' ) && abs( 1234.56 - (float) $q[0]['amount'] ) < 0.001 && 'one_time' === $q[0]['frequency'], $q );
$ok( 'quinn: GBP 12.50 recurring, PayPal', 'GBP' === ( $q[1]['currency'] ?? '' ) && 'recurring' === $q[1]['frequency'] && 'paypal' === $q[1]['gateway'], $q );
$rae = $gifts_for( 'rae@example.org' );
$ok( 'rae: JPY 5000 at 23:45 UTC', 'JPY' === ( $rae[0]['currency'] ?? '' ) && 5000 === (int) $rae[0]['amount'] && '2025-05-02 23:45:00' === $rae[0]['post_date_gmt'], $rae );
$tia = $members->get_member_by_email( 'tia@example.org' );
$ok( 'tia: Anonymous is not saved as a name', $tia && 'Anonymous' !== (string) $tia->name, $tia ? $tia->name : 'missing' );
$ok( 'sam: negative row stays out', ! $members->get_member_by_email( 'sam@example.org' ) );
$ok( 'currency list knows JPY and GBP now', in_array( 'JPY', npmp_donation_currencies(), true ) && in_array( 'GBP', npmp_donation_currencies(), true ), npmp_donation_currencies() );

// ---------------------------------------------------------------------
// A European spreadsheet, mapped by hand
// ---------------------------------------------------------------------
$ok( 'spreadsheet starts', '' === $start( $fixtures . 'gift-csv-spreadsheet-eu.csv' ) );
$ok( 'spreadsheet refuses with no amount column', 'missing_amount' === npmp_gift_csv_handle_map( array() ) );
$ok( 'spreadsheet checks', '' === npmp_gift_csv_handle_map( array( 'map' => array( 'email' => 1, 'name' => 0, 'date' => 2, 'amount' => 3, 'currency' => 4 ) ) ) );
$job = npmp_gift_csv_job();
$ok( 'spreadsheet works out day first', 'dmy' === $job['settings']['date_order_used'] );
$run = $import();
wp_cache_flush();
$ok( 'spreadsheet: 3 gifts', 3 === (int) $run['results']['gifts_created'], $run['results'] );
$jose = $members->get_member_by_email( 'jose@example.org' );
$ok( 'José from Windows-1252', $jose && 'José Fakename' === $jose->name, $jose ? $jose->name : '' );
$jg = $gifts_for( 'jose@example.org' );
$ok( 'José: 1234.50 EUR on 14 March in New York', abs( 1234.5 - (float) ( $jg[0]['amount'] ?? 0 ) ) < 0.001 && '2025-03-14 00:00:00' === ( $jg[0]['post_date'] ?? '' ), $jg );

// ---------------------------------------------------------------------
// Givebutter, with fewer free places than new donors
// ---------------------------------------------------------------------
$fillers = array();
if ( ! $pro ) {
	$need = npmp_import_free_remaining() - 2;
	for ( $i = 0; $i < $need; $i++ ) {
		$id = $members->add_member( array( 'email' => "filler{$i}@example.org", 'name' => 'Filler', 'status' => 'subscribed' ) );
		update_post_meta( (int) $id, NPMP_IMPORT_FREE_META, 1 );
		$fillers[] = (int) $id;
	}
	npmp_import_free_used( true );
	$ok( 'two free places left', 2 === npmp_import_free_remaining(), npmp_import_free_remaining() );
}
$ok( 'Givebutter starts', '' === $start( $fixtures . 'gift-csv-givebutter.csv' ) );
$job = npmp_gift_csv_job();
$ok( 'Givebutter preset, UTC', 'givebutter' === $job['preset'] && 'utc' === $job['settings']['timezone'] );
$ok( 'Givebutter checks', '' === npmp_gift_csv_handle_map( array() ) );
$run = $import();
$r   = $run['results'];
if ( ! $pro ) {
	$ok( 'free: two donors in, two past the limit', 2 === (int) $r['donors_created'] && 2 === (int) $r['over_limit'], $r );
	$ok( 'free: ada and ben in with all their gifts', 4 === (int) $r['gifts_created'] && 2 === (int) $r['over_limit_gifts'], $r );
	wp_cache_flush();
	$ok( 'free: eve stays out with her gift', ! $members->get_member_by_email( 'eve@example.org' ) && ! $gifts_for( 'eve@example.org' ) );
	foreach ( array_slice( $fillers, 0, 2 ) as $id ) {
		wp_delete_post( $id, true );
	}
	$run = $import();
	$r   = $run['results'];
	$ok( 'two places back: eve and hana come in, nothing doubles', 2 === (int) $r['donors_created'] && 2 === (int) $r['gifts_created'] && 4 === (int) $r['gifts_exists'], $r );
} else {
	$ok( 'Pro: all four donors and six gifts', 4 === (int) $r['donors_created'] && 6 === (int) $r['gifts_created'] && 0 === (int) $r['over_limit'], $r );
}
wp_cache_flush();
$ada = $members->get_member_by_email( 'ada@example.org' );
$ok( 'ada: three gifts, $2,525', $ada && 3 === (int) $ada->donation_count && abs( 2525 - (float) $ada->donation_total ) < 0.01, $ada ? array( $ada->donation_count, $ada->donation_total ) : '' );
$ag = $gifts_for( 'ada@example.org' );
$ok( 'ada: plan payments monthly, first one-time', array( 'one_time', 'monthly', 'monthly' ) === array_column( $ag, 'frequency' ), $ag );
$ok( 'ada: UTC date kept', '2025-01-15 14:30:00' === ( $ag[0]['post_date_gmt'] ?? '' ) );
$ok( 'ada: city from the file', 'Springfield' === (string) $ada->city );
$eve = $gifts_for( 'eve@example.org' );
$ok( 'eve: offline check', 'check' === ( $eve[0]['gateway'] ?? '' ) );
$ok( 'cara refunded, dan canceled, gus authorized: not here', ! $gifts_for( 'cara@example.org' ) && ! $gifts_for( 'dan@example.org' ) && ! $gifts_for( 'gus@example.org' ) );
$ok( 'fay in BRL: not here', ! $members->get_member_by_email( 'fay@example.org' ) );
$run = $import();
$ok( 'Givebutter rerun adds nothing', 0 === (int) $run['results']['gifts_created'] && 6 === (int) $run['results']['gifts_exists'], $run['results'] );
$ok( 'no receipt or automation in any Givebutter run', 0 === $run['recorded'] && 0 === $run['mails'] );
$ok( 'every gift from the four files is here once (4 Zeffy, 4 Donorbox, 3 spreadsheet, 6 Givebutter)', 17 === $csv_gift_count(), $csv_gift_count() );

// A file whose upload was deleted: the screen asks for it again, and the run refuses.
$job = npmp_gift_csv_job();
@unlink( $job['file'] );
$ok( 'a deleted file stops the run', 'gone' === npmp_gift_csv_handle_run( true ) );

// ---------------------------------------------------------------------
// Leave the site as it was
// ---------------------------------------------------------------------
$cleanup( array() );
$after = array(
	'contacts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ),
	'donations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_donation'" ),
);
$ok( 'site as it was', $before === $after, array( $before, $after ) );
$ok( 'job and progress gone', false === get_option( NPMP_GIFT_CSV_JOB ) && false === get_option( 'npmp_gift_import_gift_csv' ) );
$left = glob( npmp_import_private_dir() . '{import-rig-*,gift-index-*}', GLOB_BRACE );
$ok( 'no rig files left', ! $left, $left );
unset( $ivy_id );
update_option( 'timezone_string', (string) $saved_tz );
update_option( 'gmt_offset', $saved_offset );

printf( "\n%s\n", $fails ? "{$fails} FAILED" : 'ALL PASSED' );
