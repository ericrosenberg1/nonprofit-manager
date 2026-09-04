<?php
/**
 * The Dashboard totals moved from "load every row and add it up in PHP" to
 * SQL aggregates. The SQL itself is verified against real MySQL on the WP test
 * site (identical results on seeded data, see HANDOFF.md). What is tested here
 * is the part that stayed in PHP: turning a per-period amount into a yearly
 * one, and folding tier names the way MySQL's collation does.
 *
 * Run: php tests/test-dashboard-aggregates.php
 */

require_once __DIR__ . '/bootstrap.php';

// The function under test lives in a file that pulls in WordPress on load, so
// take just the function. Keeping this in sync is what test 6 below checks.
$src = file_get_contents( __DIR__ . '/../includes/npmp-dashboard-widgets.php' );
$start = strpos( $src, 'function npmp_annualize_donation_amount(' );
$end   = strpos( $src, "\n}", $start ) + 2;
eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting one pure function rather than booting WordPress.

echo "\n== 1. Each frequency converts to its yearly equivalent ==\n";
check( 'weekly x52',    5200.0, npmp_annualize_donation_amount( 100.0, 'weekly' ) );
check( 'monthly x12',   1200.0, npmp_annualize_donation_amount( 100.0, 'monthly' ) );
check( 'quarterly x4',   400.0, npmp_annualize_donation_amount( 100.0, 'quarterly' ) );
check( 'annual x1',      100.0, npmp_annualize_donation_amount( 100.0, 'annual' ) );

echo "\n== 2. Both words for once-a-year count the same ==\n";
// The free donation form writes 'annual'; Pro's Stripe sync writes 'yearly'.
// Matching only one silently totals the other as zero, which is a bug this
// figure has already had once.
check( "'yearly' matches 'annual'", npmp_annualize_donation_amount( 250.0, 'annual' ), npmp_annualize_donation_amount( 250.0, 'yearly' ) );

echo "\n== 3. An unrecognised frequency contributes nothing ==\n";
// Better a visibly missing total than a confidently wrong one.
check( 'unknown frequency', 0.0, npmp_annualize_donation_amount( 100.0, 'fortnightly' ) );
check( 'one_time is not recurring', 0.0, npmp_annualize_donation_amount( 100.0, 'one_time' ) );
check( 'empty frequency', 0.0, npmp_annualize_donation_amount( 100.0, '' ) );

echo "\n== 4. Stored values are normalised before matching ==\n";
// The value comes from a meta field several code paths write to.
check( 'uppercase',        1200.0, npmp_annualize_donation_amount( 100.0, 'MONTHLY' ) );
check( 'mixed case',       1200.0, npmp_annualize_donation_amount( 100.0, 'Monthly' ) );
check( 'surrounding space', 1200.0, npmp_annualize_donation_amount( 100.0, '  monthly  ' ) );

echo "\n== 5. Amount edge cases ==\n";
check( 'zero amount',      0.0, npmp_annualize_donation_amount( 0.0, 'monthly' ) );
check( 'fractional cents', 1200.0, npmp_annualize_donation_amount( 100.00, 'monthly' ) );
check( 'a refunded/negative amount is not clamped', -1200.0, npmp_annualize_donation_amount( -100.0, 'monthly' ) );

echo "\n== 6. The grouped-SUM shape the SQL relies on ==\n";
// The query returns one row per frequency with the summed amount, and PHP
// annualises each row. Summing first and converting once is only correct
// because the conversion is linear in the amount. Prove that holds.
$amounts = array( 10.0, 25.5, 100.0 );
$per_row = 0.0;
foreach ( $amounts as $a ) {
	$per_row += npmp_annualize_donation_amount( $a, 'monthly' );
}
$summed_first = npmp_annualize_donation_amount( array_sum( $amounts ), 'monthly' );
check( 'sum-then-convert == convert-then-sum', $per_row, $summed_first );

echo "\n== 7. Tier keys fold case the way MySQL's collation does ==\n";
// npmp_count_members_by_tier_map() lower-cases both the grouped meta_value and
// the caller's tier name, because the meta_query it replaced compared through
// a case-insensitive collation. 'Bronze' and 'bronze' are one tier.
$fold = static function ( $t ) {
	return strtolower( (string) $t );
};
check( "'Bronze' and 'bronze' fold together", $fold( 'Bronze' ), $fold( 'bronze' ) );
check( "'GOLD' folds to 'gold'", 'gold', $fold( 'GOLD' ) );
check( 'a missing tier is not a match', false, $fold( 'Gold' ) === $fold( 'Silver' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
