<?php
/**
 * Real-WordPress check of the Dashboard widgets' money figures and Quick Add
 * Event: the recurring run rate counts each donor once, the year-to-date
 * total follows the site's year, and a quick-added event has its start
 * before save_post fires.
 *
 * Run inside a throwaway WordPress with the plugin active and donations on:
 *   wp eval-file tests/rig/payments-dashboard.php
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! function_exists( 'npmp_get_annual_recurring_totals_by_currency' ) || ! class_exists( 'NPMP_Donation_Manager' ) ) {
	echo "  FAIL  dashboard widgets or donations module not loaded (turn donations on)\n";
	exit( 1 );
}

$made = array();
$gift = function ( $email, $amount, $frequency, $days_ago, $currency = 'USD' ) use ( &$made ) {
	$gmt = gmdate( 'Y-m-d H:i:s', time() - (int) ( $days_ago * DAY_IN_SECONDS ) );
	$id  = wp_insert_post(
		array(
			'post_type'     => NPMP_Donation_Manager::POST_TYPE,
			'post_status'   => 'publish',
			'post_title'    => 'rig gift',
			'post_date'     => get_date_from_gmt( $gmt ),
			'post_date_gmt' => $gmt,
		)
	);
	update_post_meta( $id, NPMP_Donation_Manager::META_EMAIL, $email );
	update_post_meta( $id, NPMP_Donation_Manager::META_AMOUNT, $amount );
	update_post_meta( $id, NPMP_Donation_Manager::META_FREQUENCY, $frequency );
	update_post_meta( $id, NPMP_Donation_Manager::META_CURRENCY, $currency );
	$made[] = $id;
	return $id;
};

// Clear old rig rows so the totals start from zero.
foreach ( get_posts( array( 'post_type' => NPMP_Donation_Manager::POST_TYPE, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $old ) {
	wp_delete_post( $old, true );
}

echo "Recurring run rate\n";
// A monthly donor with 12 renewals: $10 a month is $120 a year, not $1,440.
for ( $i = 0; $i < 12; $i++ ) {
	$gift( 'monthly@example.org', 10, 'monthly', 2 + $i * 30 );
}
// Same donor, email in another case, still one donor.
$gift( 'Monthly@Example.org', 10, 'monthly', 400 );
// An annual donor who renewed last month: $100.
$gift( 'annual@example.org', 100, 'annual', 30 );
$gift( 'annual@example.org', 90, 'yearly', 400 );
// A weekly donor who stopped two months ago counts for nothing.
$gift( 'lapsed@example.org', 5, 'weekly', 60 );
// One-time gifts never count.
$gift( 'once@example.org', 500, 'one_time', 1 );
// Pounds stay apart.
$gift( 'uk@example.org', 20, 'quarterly', 10, 'GBP' );

$totals = npmp_get_annual_recurring_totals_by_currency();
$ok( 'USD run rate is 120 + 100 = 220 (got ' . ( $totals['USD'] ?? 'none' ) . ')', isset( $totals['USD'] ) && abs( $totals['USD'] - 220.0 ) < 0.001 );
$ok( 'GBP run rate is 80 (got ' . ( $totals['GBP'] ?? 'none' ) . ')', isset( $totals['GBP'] ) && abs( $totals['GBP'] - 80.0 ) < 0.001 );

add_filter(
	'npmp_annual_recurring_totals',
	$double = static function ( $t ) {
		return array_map(
			static function ( $v ) {
				return $v * 2;
			},
			$t
		);
	}
);
$filtered = npmp_get_annual_recurring_totals_by_currency();
remove_filter( 'npmp_annual_recurring_totals', $double );
$ok( 'npmp_annual_recurring_totals filter runs', isset( $filtered['USD'] ) && abs( $filtered['USD'] - 440.0 ) < 0.001 );

echo "Year to date\n";
$ytd = npmp_get_ytd_donation_totals_by_currency();
$ok( 'YTD counts this year in the site timezone', isset( $ytd['USD'] ) && $ytd['USD'] > 0 );
$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/npmp-dashboard-widgets.php' );
$ok( 'YTD year comes from wp_date, not gmdate', false !== strpos( $src, "(int) wp_date( 'Y' )" ) && false === strpos( $src, "(int) gmdate( 'Y' )" ) );

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}

echo "Quick Add Event\n";
wp_set_current_user( 1 );
$seen = null;
$spy  = static function ( $post_id ) use ( &$seen ) {
	$seen = get_post_meta( $post_id, '_npmp_event_start', true );
};
add_action( 'save_post_npmp_event', $spy, 1 );
$_POST = array(
	'npmp_quick_add_event_nonce' => wp_create_nonce( 'npmp_quick_add_event' ),
	'npmp_event_title'           => 'Rig quick event',
	'npmp_event_datetime'        => '2031-05-06T18:30',
	'npmp_event_location'        => 'Library',
);
ob_start();
npmp_render_quick_add_event_widget();
$html = ob_get_clean();
$_POST = array();
remove_action( 'save_post_npmp_event', $spy, 1 );

$event = get_posts( array( 'post_type' => 'npmp_event', 'title' => 'Rig quick event', 'numberposts' => 1, 'post_status' => 'any' ) );
$eid   = $event ? $event[0]->ID : 0;
$ok( 'event created', $eid > 0 && false !== strpos( $html, 'Event created.' ) );
$ok( 'start is there when save_post fires (got ' . var_export( $seen, true ) . ')', '2031-05-06 18:30:00' === $seen );
$ok( 'start stored as the site wall-clock time', '2031-05-06 18:30:00' === get_post_meta( $eid, '_npmp_event_start', true ) );
$ok( 'location stored', 'Library' === get_post_meta( $eid, '_npmp_event_location', true ) );
if ( $eid ) {
	wp_delete_post( $eid, true );
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
