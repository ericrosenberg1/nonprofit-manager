<?php
/**
 * Real-WordPress run of the GiveWP and Charitable gift importers on the real
 * plugins: seed gifts through each plugin's own API, import, and check every
 * gift, contact, total, and skip, then run it again, past the free limit,
 * and with the old plugin off.
 *
 * Seeds per plugin (one domain each):
 *   ada   two paid one-time gifts in USD (one in 2023), one test gift, one refunded gift
 *   ben   a monthly plan: first payment and one renewal, plan still running
 *   cara  one paid gift in EUR, already a contact before the import
 *   dan   one paid gift
 *   eve   one paid gift, past the free limit on the first run
 *
 * Needs a throwaway WordPress with this plugin, GiveWP, and Charitable
 * active, Pro inactive (Pro lifts the free limit this rig checks), `wp` on
 * PATH. Verified on SQLite with GiveWP 4.19.1.1 and Charitable 1.8.15.1:
 *   wp plugin install give charitable --activate
 *   wp eval-file tests/rig/gift-sources.php
 *
 * It turns GiveWP and Charitable off at the end. Turn them on again for
 * another run. A second run reuses the seeded gifts.
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

$domains = array(
	'givewp'     => 'rig-give.example.org',
	'charitable' => 'rig-char.example.org',
);

// ---------------------------------------------------------------------
// Child phase: one import run, with counters for side effects.
// ---------------------------------------------------------------------
if ( 0 === strpos( $phase, 'import:' ) ) {
	$slug     = substr( $phase, 7 );
	$recorded = 0;
	$mails    = 0;
	add_action( 'npmp_donation_recorded', function () use ( &$recorded ) {
		++$recorded;
	} );
	add_filter( 'pre_wp_mail', function ( $r ) use ( &$mails ) {
		++$mails;
		return true;
	} );
	$state = npmp_gift_import_state( $slug );
	$state['running'] = false;
	npmp_gift_import_save_state( $slug, $state );
	$state = npmp_gift_import_run( $slug, 60, 2 );
	echo 'JSON ', wp_json_encode(
		array(
			'state'    => $state,
			'recorded' => $recorded,
			'mails'    => $mails,
			'left'     => npmp_import_free_remaining(),
			'active'   => is_plugin_active( 'givewp' === $slug ? 'give/give.php' : 'charitable/charitable.php' ),
		)
	), "\n";
	return;
}

if ( 'hub:deactivate' === substr( $phase, 0, 14 ) ) {
	$slug = substr( $phase, 15 );
	$r    = npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) );
	echo 'JSON ', wp_json_encode( array( 'verify' => is_wp_error( $r ) ? $r->get_error_code() : $r, 'deactivate' => ( function () use ( $slug ) {
		$r = npmp_import_hub_run_step( $slug, 'deactivate' );
		return is_wp_error( $r ) ? $r->get_error_code() : $r;
	} )() ) ), "\n";
	return;
}

$child = function ( $phase, $before = '' ) {
	$cmd = sprintf(
		'wp --path=%s %s eval-file %s %s 2>&1',
		escapeshellarg( untrailingslashit( ABSPATH ) ),
		'' !== $before ? '--exec=' . escapeshellarg( $before ) : '',
		escapeshellarg( __FILE__ ),
		escapeshellarg( $phase )
	);
	$out = array();
	exec( $cmd, $out );
	foreach ( $out as $line ) {
		if ( 0 === strpos( $line, 'JSON ' ) ) {
			return json_decode( substr( $line, 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === strpos( $l, 'Deprecated' );
	} ) ) );
};

$ok( 'GiveWP is active', is_plugin_active( 'give/give.php' ) );
$ok( 'Charitable is active', is_plugin_active( 'charitable/charitable.php' ) );
$ok( 'Pro is inactive', ! is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' ) );
$ok( 'both gift sources registered', isset( npmp_gift_import_sources()['givewp'], npmp_gift_import_sources()['charitable'] ) );

// ---------------------------------------------------------------------
// Seed through each plugin's own API (once).
// ---------------------------------------------------------------------
$seed_give = function () use ( $wpdb, $domains ) {
	$d = $domains['givewp'];
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}give_donors WHERE email = %s", 'ada@' . $d ) ) ) {
		return;
	}
	$money  = function ( $v, $c = 'USD' ) {
		return \Give\Framework\Support\ValueObjects\Money::fromDecimal( $v, $c );
	};
	$donors = array();
	foreach ( array( 'ada' => 'Ada Give', 'ben' => 'Ben Give', 'cara' => 'Cara Give', 'dan' => 'Dan Give', 'eve' => 'Eve Give' ) as $key => $name ) {
		list( $first, $last ) = explode( ' ', $name );
		$donors[ $key ] = \Give\Donors\Models\Donor::create( array( 'firstName' => $first, 'lastName' => $last, 'name' => $name, 'email' => $key . '@' . $d ) );
	}
	$gift = function ( $key, $amount, array $more = array() ) use ( $donors, $money, $d ) {
		$donor = $donors[ $key ];
		return \Give\Donations\Models\Donation::create(
			array_merge(
				array(
					'status'    => \Give\Donations\ValueObjects\DonationStatus::COMPLETE(),
					'type'      => \Give\Donations\ValueObjects\DonationType::SINGLE(),
					'gatewayId' => 'stripe_payment_element',
					'mode'      => \Give\Donations\ValueObjects\DonationMode::LIVE(),
					'amount'    => $money( $amount, $more['currency'] ?? 'USD' ),
					'donorId'   => $donor->id,
					'firstName' => $donor->firstName,
					'lastName'  => $donor->lastName,
					'email'     => $key . '@' . $d,
					'formId'    => 1,
					'formTitle' => 'Rig form',
				),
				array_diff_key( $more, array( 'currency' => 1 ) )
			)
		);
	};
	$gift( 'ada', '25.00', array( 'createdAt' => new DateTime( '2023-05-01 15:00:00', new DateTimeZone( 'UTC' ) ), 'gatewayTransactionId' => 'pi_ada_1' ) );
	$gift( 'ada', '40.00', array( 'createdAt' => new DateTime( '2025-02-10 12:00:00', new DateTimeZone( 'UTC' ) ) ) );
	$gift( 'ada', '99.00', array( 'mode' => \Give\Donations\ValueObjects\DonationMode::TEST() ) );
	$gift( 'ada', '15.00', array( 'status' => \Give\Donations\ValueObjects\DonationStatus::REFUNDED() ) );
	$plan = \Give\Subscriptions\Models\Subscription::create(
		array(
			'donorId'        => $donors['ben']->id,
			'donationFormId' => 1,
			'period'         => \Give\Subscriptions\ValueObjects\SubscriptionPeriod::MONTH(),
			'frequency'      => 1,
			'installments'   => 0,
			'amount'         => $money( '10.00' ),
			'status'         => \Give\Subscriptions\ValueObjects\SubscriptionStatus::ACTIVE(),
			'mode'           => \Give\Subscriptions\ValueObjects\SubscriptionMode::LIVE(),
			'gatewayId'      => 'stripe_payment_element',
		)
	);
	$first = $gift( 'ben', '10.00', array( 'type' => \Give\Donations\ValueObjects\DonationType::SUBSCRIPTION(), 'subscriptionId' => $plan->id, 'createdAt' => new DateTime( '-40 days', new DateTimeZone( 'UTC' ) ) ) );
	$plan->transactionId = 'sub_ben';
	$wpdb->update( $wpdb->prefix . 'give_subscriptions', array( 'parent_payment_id' => $first->id ), array( 'id' => $plan->id ) );
	$gift( 'ben', '10.00', array( 'type' => \Give\Donations\ValueObjects\DonationType::RENEWAL(), 'status' => \Give\Donations\ValueObjects\DonationStatus::RENEWAL(), 'subscriptionId' => $plan->id, 'createdAt' => new DateTime( '-10 days', new DateTimeZone( 'UTC' ) ) ) );
	$gift( 'cara', '50.00', array( 'currency' => 'EUR' ) );
	$gift( 'dan', '5.00' );
	$gift( 'eve', '7.00' );
};

$seed_char = function () use ( $wpdb, $domains ) {
	$d = $domains['charitable'];
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT donor_id FROM {$wpdb->prefix}charitable_donors WHERE email = %s", 'ada@' . $d ) ) ) {
		return;
	}
	$campaign = wp_insert_post( array( 'post_type' => 'campaign', 'post_status' => 'publish', 'post_title' => 'Rig campaign' ) );
	$second   = wp_insert_post( array( 'post_type' => 'campaign', 'post_status' => 'publish', 'post_title' => 'Rig campaign two' ) );
	$gift     = function ( $key, array $campaigns, array $more = array() ) use ( $d ) {
		$id = charitable_create_donation(
			array_merge(
				array(
					'campaigns' => $campaigns,
					'user'      => array( 'email' => $key . '@' . $d, 'first_name' => ucfirst( $key ), 'last_name' => 'Char' ),
					'status'    => 'charitable-completed',
					'gateway'   => 'stripe',
				),
				$more
			)
		);
		if ( $id ) {
			update_post_meta( $id, 'test_mode', ! empty( $more['_test'] ) ? 1 : 0 );
		}
		return $id;
	};
	$one = function ( $amount ) use ( $campaign ) {
		return array( array( 'campaign_id' => $campaign, 'campaign_name' => 'Rig campaign', 'amount' => $amount ) );
	};
	// Ada: one gift split across two campaigns (20 + 5), one in 2023, a test gift, a refunded gift, and a partial refund.
	$gift( 'ada', array( array( 'campaign_id' => $campaign, 'campaign_name' => 'Rig campaign', 'amount' => 20 ), array( 'campaign_id' => $second, 'campaign_name' => 'Rig campaign two', 'amount' => 5 ) ), array( 'date_gmt' => '2023-05-01 15:00:00' ) );
	$partial = $gift( 'ada', $one( 40 ), array( 'date_gmt' => '2025-02-10 12:00:00' ) );
	update_post_meta( $partial, 'donation_refund', array( 'time' => time(), 'message' => '', 'campaign_refunds' => array(), 'total_refund' => 10 ) );
	$gift( 'ada', $one( 99 ), array( '_test' => 1 ) );
	$gift( 'ada', $one( 15 ), array( 'status' => 'charitable-refunded' ) );
	// Ben: a running plan with two payments.
	$plan = wp_insert_post( array( 'post_type' => 'recurring_donation', 'post_status' => 'charitable-active', 'post_title' => 'Ben plan' ) );
	$gift( 'ben', $one( 10 ), array( 'donation_plan' => $plan, 'date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ) );
	$gift( 'ben', $one( 10 ), array( 'donation_plan' => $plan, 'date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ) ) );
	$cara = $gift( 'cara', $one( 50 ) );
	update_post_meta( $cara, 'currency', 'EUR' );
	$gift( 'dan', $one( 5 ) );
	$gift( 'eve', $one( 7 ) );
};

try {
	$seed_give();
} catch ( Throwable $e ) {
	$ok( 'GiveWP seeds through its own models', false, $e->getMessage() );
}
$seed_char();

// ---------------------------------------------------------------------
// Start clean: drop what an earlier run imported.
// ---------------------------------------------------------------------
$members = NPMP_Member_Manager::get_instance();
foreach ( $domains as $slug => $domain ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_import_source' AND meta_value = %s", $slug ) ) as $pid ) {
		wp_delete_post( (int) $pid, true );
	}
	foreach ( array( 'ada', 'ben', 'cara', 'dan', 'eve' ) as $key ) {
		$m = $members->get_member_by_email( $key . '@' . $domain );
		if ( $m ) {
			$members->delete_member( (int) $m->id );
		}
	}
	delete_option( 'npmp_gift_import_' . $slug );
	delete_option( 'npmp_import_hub_' . $slug );
}

// A past gift says nothing about your own form, so the import never sets the
// first-donation milestone behind the "Your donation form is working!" notice.
$first_donation = get_option( 'npmp_first_donation_at', false );
delete_option( 'npmp_first_donation_at' );

foreach ( $domains as $slug => $domain ) {
	echo "\n== {$slug} ==\n";
	$source = npmp_gift_import_sources()[ $slug ];
	$counts = call_user_func( $source['counts'] );
	$ok( "{$slug}: old gifts received count 7", 7 === $counts['gifts'], $counts );
	$ok( "{$slug}: one test gift", 1 === $counts['test'], $counts );
	$ok( "{$slug}: one refunded gift", 1 === $counts['not_received'], $counts );
	$ok( "{$slug}: five donors", 5 === $counts['donors'], $counts );
	$ok( "{$slug}: one running plan", 1 === $counts['active_plans'], $counts );

	// Cara is a contact already, with no free mark.
	$cara_id = $members->add_member( array( 'email' => 'cara@' . $domain, 'name' => 'Cara Before', 'status' => 'subscribed' ) );
	$ok( "{$slug}: Cara made before the import", is_numeric( $cara_id ) && $cara_id > 0, $cara_id );

	// Three free places left: Ada, Ben, and Dan come over, Eve stays behind.
	$used = npmp_import_free_used( true );
	$run  = $child( 'import:' . $slug, sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 3 ) );
	$r    = $run['state']['results'] ?? array();
	$ok( "{$slug}: run finished", ! empty( $run['state']['finished_at'] ) && empty( $run['state']['running'] ), $run );
	$ok( "{$slug}: three new contacts", 3 === ( $r['donors_created'] ?? -1 ), $r );
	$ok( "{$slug}: Cara counted as existing", 1 === ( $r['donors_existing'] ?? -1 ), $r );
	$ok( "{$slug}: Eve past the limit with one gift", 1 === ( $r['over_limit'] ?? -1 ) && 1 === ( $r['over_limit_gifts'] ?? -1 ), $r );
	$expect_gifts = 6;
	$ok( "{$slug}: six gifts written", $expect_gifts === ( $r['gifts_created'] ?? -1 ), $r );
	$ok( "{$slug}: no gift skipped as invalid", 0 === ( $r['gifts_invalid'] ?? -1 ), $r );
	$ok( "{$slug}: no npmp_donation_recorded fired", 0 === ( $run['recorded'] ?? -1 ), $run );
	$ok( "{$slug}: no email sent", 0 === ( $run['mails'] ?? -1 ), $run );
	$ok( "{$slug}: free places used up", 0 === ( $run['left'] ?? -1 ), $run );

	wp_cache_flush();
	$gifts = get_posts(
		array(
			'post_type'   => 'npmp_donation',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_key'    => '_npmp_import_source', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	$by = array();
	foreach ( $gifts as $p ) {
		$email          = get_post_meta( $p->ID, '_npmp_donation_email', true );
		$by[ $email ][] = array(
			'amount'    => (float) get_post_meta( $p->ID, '_npmp_donation_amount', true ),
			'currency'  => get_post_meta( $p->ID, '_npmp_donation_currency', true ),
			'frequency' => get_post_meta( $p->ID, '_npmp_donation_frequency', true ),
			'gateway'   => get_post_meta( $p->ID, '_npmp_donation_gateway', true ),
			'txn'       => get_post_meta( $p->ID, '_npmp_donation_txn_id', true ),
			'date'      => $p->post_date_gmt,
		);
	}
	$ada = $by[ 'ada@' . $domain ] ?? array();
	usort( $ada, function ( $a, $b ) {
		return strcmp( $a['date'], $b['date'] );
	} );
	$ok( "{$slug}: Ada has two gifts (test and refunded left behind)", 2 === count( $ada ), $ada );
	// GiveWP saves createdAt as the site's local time and works out the UTC date
	// itself, so the expected date is the one GiveWP stored, not the seed's.
	$old_date = 'givewp' === $slug
		? $wpdb->get_var( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'give_payment' AND post_date LIKE '2023-05-01%' ORDER BY ID ASC LIMIT 1" )
		: $wpdb->get_var( "SELECT post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'donation' AND post_date_gmt LIKE '2023-05-01%' ORDER BY ID ASC LIMIT 1" );
	$ok( "{$slug}: Ada's 2023 gift keeps its UTC date", isset( $ada[0] ) && $old_date && $old_date === $ada[0]['date'], array( $old_date, $ada ) );
	$ok( "{$slug}: Ada's first gift is 25", isset( $ada[0] ) && abs( $ada[0]['amount'] - 25 ) < 0.001, $ada );
	$second = 'charitable' === $slug ? 30.0 : 40.0; // Charitable's second gift had 10 refunded.
	$ok( "{$slug}: Ada's second gift is {$second}", isset( $ada[1] ) && abs( $ada[1]['amount'] - $second ) < 0.001, $ada );
	$ok( "{$slug}: Ada's gateway is stripe", isset( $ada[0] ) && 'stripe' === $ada[0]['gateway'], $ada );
	if ( 'givewp' === $slug ) {
		$ok( 'givewp: Ada keeps the transaction ID', isset( $ada[0] ) && 'pi_ada_1' === $ada[0]['txn'], $ada );
	}
	$ben  = $by[ 'ben@' . $domain ] ?? array();
	$freq = 'givewp' === $slug ? 'monthly' : 'recurring';
	$ok( "{$slug}: Ben has two {$freq} gifts", 2 === count( $ben ) && array( $freq ) === array_values( array_unique( wp_list_pluck( $ben, 'frequency' ) ) ), $ben );
	$cara = $by[ 'cara@' . $domain ] ?? array();
	$ok( "{$slug}: Cara's gift is in EUR", 1 === count( $cara ) && 'EUR' === $cara[0]['currency'], $cara );
	$ok( "{$slug}: Eve has no gift here", empty( $by[ 'eve@' . $domain ] ) );
	$ok( "{$slug}: Eve isn't a contact", ! $members->get_member_by_email( 'eve@' . $domain ) );

	$ada_c = (array) $members->get_member_by_email( 'ada@' . $domain );
	$ok( "{$slug}: Ada is a donor contact", 'donor' === ( $ada_c['status'] ?? '' ), $ada_c );
	$ok( "{$slug}: Ada's contact source", $slug === ( $ada_c['source'] ?? '' ), $ada_c );
	$ok( "{$slug}: Ada's giving count is 2", 2 === (int) ( $ada_c['donation_count'] ?? 0 ), $ada_c );
	$ok( "{$slug}: Ada's total", abs( (float) ( $ada_c['donation_total'] ?? 0 ) - ( 25 + $second ) ) < 0.001, $ada_c );
	$ok( "{$slug}: Ada's last gift date is her 2025 gift", 0 === strpos( (string) ( $ada_c['last_donation_at'] ?? '' ), '2025-02-10' ), $ada_c );
	$ok( "{$slug}: Ada carries the free import mark", '1' === (string) get_post_meta( (int) $ada_c['id'], NPMP_IMPORT_FREE_META, true ) || '' !== (string) get_post_meta( (int) $ada_c['id'], NPMP_IMPORT_FREE_META, true ) );
	$cara_c = (array) $members->get_member_by_email( 'cara@' . $domain );
	$ok( "{$slug}: Cara keeps her name", 'Cara Before' === ( $cara_c['name'] ?? '' ), $cara_c );
	$ok( "{$slug}: Cara uses no free place", '' === (string) get_post_meta( (int) $cara_c['id'], NPMP_IMPORT_FREE_META, true ) );

	// Second run, same limit: nothing new.
	$run = $child( 'import:' . $slug, sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 3 ) );
	$r   = $run['state']['results'] ?? array();
	$ok( "{$slug}: rerun writes no gift", 0 === ( $r['gifts_created'] ?? -1 ), $r );
	$ok( "{$slug}: rerun finds the six", 6 === ( $r['gifts_exists'] ?? -1 ), $r );
	$ok( "{$slug}: rerun still leaves Eve behind", 1 === ( $r['over_limit'] ?? -1 ), $r );

	// Room for one more: Eve comes over.
	$run = $child( 'import:' . $slug, sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 4 ) );
	$r   = $run['state']['results'] ?? array();
	$ok( "{$slug}: Eve comes over with one more place", 1 === ( $r['donors_created'] ?? -1 ) && 1 === ( $r['gifts_created'] ?? -1 ), $r );

	// Hub.
	$hub = npmp_import_hub_source( $slug );
	$ok( "{$slug}: hub source registered", is_array( $hub ) );
	$facts = npmp_import_hub_facts( $hub );
	$ok( "{$slug}: hub sees the import", ! empty( $facts['imported'] ) && ! empty( $facts['active'] ), $facts );
	$ok( "{$slug}: hub step 5 has no batch", empty( $facts['removal_available'] ) );
	$rows = npmp_import_hub_call( $hub['counts'], array() );
	$ok( "{$slug}: verify counts gifts 7 old, 7 new", 7 === $rows[0]['old'] && 7 === $rows[0]['new'], $rows );
	$ok( "{$slug}: verify counts donors 5 old, 5 new", 5 === $rows[1]['old'] && 5 === $rows[1]['new'], $rows );

	// Verify and deactivate through the hub in their own request, then import again with the plugin off.
	$hubrun = $child( 'hub:deactivate:' . $slug );
	$ok( "{$slug}: hub verify and deactivate", 'verified' === ( $hubrun['verify']['message'] ?? '' ) && 'deactivated' === ( $hubrun['deactivate']['message'] ?? '' ), $hubrun );
	$run = $child( 'import:' . $slug, sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d );', $used + 4 ) );
	$r   = $run['state']['results'] ?? array();
	$ok( "{$slug}: import reads with the plugin off", false === ( $run['active'] ?? null ) && 7 === ( $r['gifts_exists'] ?? -1 ) && 0 === ( $r['gifts_created'] ?? -1 ), $run );
}

wp_cache_delete( 'npmp_first_donation_at', 'options' );
wp_cache_delete( 'alloptions', 'options' );
$ok( 'no import set the first-donation milestone', false === get_option( 'npmp_first_donation_at', false ) );
$own_first = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_npmp_import_key' WHERE p.post_type = 'npmp_donation' AND p.post_status = 'publish' AND k.meta_id IS NULL ORDER BY p.post_date ASC LIMIT 1" );
$expect    = $own_first ? (int) get_post_time( 'U', true, $own_first ) : 0;
$ok( 'the Powered-by ask backfill skips imported gifts', $expect === npmp_credit_ask_first_donation_at(), array( $expect, get_option( 'npmp_first_donation_at' ) ) );
delete_option( 'npmp_first_donation_at' );
if ( false !== $first_donation ) {
	update_option( 'npmp_first_donation_at', $first_donation, false );
}

// No secret option ever got read into ours.
$ours = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'npmp\\_gift\\_import\\_%'" );
$ok( 'saved state holds no secret words', 0 === preg_match( '/secret|token|password|license/i', implode( ' ', $ours ) ) );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
