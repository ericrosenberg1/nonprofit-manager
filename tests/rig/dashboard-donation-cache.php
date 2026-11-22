<?php
/**
 * Real-WordPress check of the Dashboard's donation cache across requests
 * (sweep 2026-11-21, F13): the year-to-date and recurring figures come from
 * the cache on a second load, and every gift added, edited, trashed,
 * restored, or deleted in another request shows on the very next load.
 *
 * Run inside a throwaway WordPress with the plugin active and donations on:
 *   wp eval-file tests/rig/dashboard-donation-cache.php
 * Each step runs in its own `wp` process (NPMP_RIG_WP_BIN, default `wp`), so
 * each is a separate request the way a real Dashboard load and a real
 * Stripe return are. Unit test: tests/test-dashboard-donation-cache.php.
 *
 * Creates and deletes its own gifts and contacts.
 */

global $wpdb;

if ( ! function_exists( 'npmp_get_ytd_donation_totals_by_currency' ) || ! class_exists( 'NPMP_Donation_Manager' ) ) {
	echo "  FAIL  dashboard widgets or donations module not loaded (turn donations on)\n";
	exit( 1 );
}

$step = isset( $args[0] ) ? (string) $args[0] : '';

// One step, in its own process.
if ( '' !== $step ) {
	$out = array();
	if ( 'read' === $step ) {
		$ran = array(
			'ytd'       => 0,
			'recurring' => 0,
		);
		add_filter(
			'query',
			static function ( $sql ) use ( &$ran ) {
				if ( false !== strpos( $sql, 'SUM(m.meta_value + 0)' ) ) {
					$ran['ytd']++;
				} elseif ( false !== strpos( $sql, 'MAX( CONCAT(' ) ) {
					$ran['recurring']++;
				}
				return $sql;
			}
		);
		$ytd = npmp_get_ytd_donation_totals_by_currency();
		$rec = npmp_get_annual_recurring_totals_by_currency();
		// Cents as whole numbers, so JSON hands back the same type every time.
		$out = array(
			'ytd'   => (int) round( 100 * (float) ( $ytd['USD'] ?? 0 ) ),
			'rec'   => (int) round( 100 * (float) ( $rec['USD'] ?? 0 ) ),
			'ran'   => $ran,
			'token' => (string) get_option( 'npmp_dashboard_donations_token', '' ),
		);
	} elseif ( 'gift' === $step ) {
		$out['id'] = (int) NPMP_Donation_Manager::get_instance()->log_donation(
			array(
				'email'          => 'monthly' === ( $args[2] ?? '' ) ? 'rig-cache-monthly@example.org' : 'rig-cache@example.org',
				'name'           => 'Rig Cache',
				'amount'         => (float) $args[1],
				'frequency'      => (string) ( $args[2] ?? 'one_time' ),
				'gateway'        => 'stripe',
				'transaction_id' => 'rig_cache_' . uniqid(),
				'currency'       => 'USD',
			)
		);
	} elseif ( 'trash' === $step ) {
		$out['done'] = (bool) wp_trash_post( (int) $args[1] );
	} elseif ( 'untrash' === $step ) {
		$out['done'] = (bool) wp_untrash_post( (int) $args[1] );
		// WordPress 5.6+ restores a trashed post as a draft. Publish it again, as
		// an admin who restores a gift would.
		wp_publish_post( (int) $args[1] );
	} elseif ( 'amount' === $step ) {
		$out['done'] = (bool) update_post_meta( (int) $args[1], NPMP_Donation_Manager::META_AMOUNT, (float) $args[2] );
	} elseif ( 'delete' === $step ) {
		$out['done'] = (bool) wp_delete_post( (int) $args[1], true );
	}
	echo "\n" . wp_json_encode( $out ) . "\n";
	return;
}

$fails  = 0;
$passes = 0;
$ok     = function ( $label, $cond ) use ( &$fails, &$passes ) {
	if ( $cond ) {
		$passes++;
	} else {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

$wp  = getenv( 'NPMP_RIG_WP_BIN' ) ?: 'wp';
$run = function ( ...$step ) use ( $wp ) {
	$cmd = escapeshellarg( $wp ) . ' --path=' . escapeshellarg( ABSPATH ) . ' eval-file ' . escapeshellarg( __FILE__ );
	foreach ( $step as $part ) {
		$cmd .= ' ' . escapeshellarg( (string) $part );
	}
	$lines = preg_split( '/\R/', (string) shell_exec( $cmd . ' 2>&1' ) );
	foreach ( array_reverse( $lines ) as $line ) {
		if ( 0 === strpos( trim( $line ), '{' ) ) {
			return (array) json_decode( trim( $line ), true );
		}
	}
	return array( 'error' => implode( "\n", $lines ) );
};

$cleanup = function () use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", NPMP_Donation_Manager::META_TXN_ID, 'rig\_cache\_%' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	if ( class_exists( 'NPMP_Member_Manager' ) ) {
		foreach ( array( 'rig-cache@example.org', 'rig-cache-monthly@example.org' ) as $email ) {
			$member = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
			if ( $member && ! empty( $member->id ) ) {
				NPMP_Member_Manager::get_instance()->delete_member( (int) $member->id );
			}
		}
	}
};

try {
	$cleanup();
	delete_transient( 'npmp_dash_ytd' );
	delete_transient( 'npmp_dash_recurring' );

	echo "Two Dashboard loads\n";
	$base = $run( 'read' );
	$ok( 'the first load runs both queries', array( 'ytd' => 1, 'recurring' => 1 ) === ( $base['ran'] ?? null ) );
	$again = $run( 'read' );
	$ok( 'the second load runs neither', array( 'ytd' => 0, 'recurring' => 0 ) === ( $again['ran'] ?? null ) );
	$ok( 'and shows the same figures', $base['ytd'] === $again['ytd'] && $base['rec'] === $again['rec'] );

	echo "A gift in another request\n";
	$gift = $run( 'gift', 50 );
	$ok( 'log_donation() recorded the gift', ! empty( $gift['id'] ) );
	$after = $run( 'read' );
	$ok( 'the next load shows it', $base['ytd'] + 5000 === $after['ytd'] );
	$ok( 'the token changed', $after['token'] !== $base['token'] );
	$ok( 'the load after that reads the cache again', array( 'ytd' => 0, 'recurring' => 0 ) === ( $run( 'read' )['ran'] ?? null ) );

	echo "Edits, trash, restore, delete\n";
	$run( 'amount', $gift['id'], 70 );
	$ok( 'a new amount shows on the next load', $base['ytd'] + 7000 === $run( 'read' )['ytd'] );
	$run( 'trash', $gift['id'] );
	$ok( 'a trashed gift leaves the total on the next load', $base['ytd'] === $run( 'read' )['ytd'] );
	$run( 'untrash', $gift['id'] );
	$ok( 'a restored gift comes back on the next load', $base['ytd'] + 7000 === $run( 'read' )['ytd'] );
	$run( 'delete', $gift['id'] );
	$ok( 'a deleted gift leaves the total on the next load', $base['ytd'] === $run( 'read' )['ytd'] );

	echo "Recurring\n";
	$run( 'gift', 10, 'monthly' );
	$rec = $run( 'read' );
	$ok( 'a new monthly gift adds $120 a year on the next load', $base['rec'] + 12000 === $rec['rec'] );
	$ok( 'and the year to date adds $10', $base['ytd'] + 1000 === $rec['ytd'] );
} finally {
	$cleanup();
}

echo "\n{$passes} passed, {$fails} failed\n";
exit( $fails ? 1 : 0 );
