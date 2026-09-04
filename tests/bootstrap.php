<?php
/**
 * Minimal harness for testing the free plugin's pure logic without WordPress.
 *
 * The free plugin had no tests at all. This covers the pieces that can be
 * reasoned about on their own: aggregation, formatting, version comparison.
 * Anything needing a real $wpdb is verified against the WP test site instead
 * (see HANDOFF.md), because faking MySQL's collation and date handling well
 * enough to trust the result is more work than running the real thing.
 *
 * Excluded from the distributed zip via .gitattributes export-ignore.
 */

$pass = 0;
$fail = 0;

/**
 * Assert an actual value matches an expected one.
 *
 * Floats compare within a cent so money maths does not fail on representation.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function check( $label, $expected, $actual ) {
	global $pass, $fail;

	$ok = ( is_float( $expected ) || is_float( $actual ) )
		? abs( (float) $expected - (float) $actual ) < 0.005
		: $expected === $actual;

	if ( $ok ) {
		$pass++;
		printf( "  PASS  %-60s = %s\n", $label, var_export( $actual, true ) );
	} else {
		$fail++;
		printf( "  FAIL  %-60s expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

// WordPress pieces the functions under test reach for.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}
