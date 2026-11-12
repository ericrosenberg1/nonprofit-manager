<?php
/**
 * File path: includes/import/charitable-source.php
 *
 * Charitable as a gift source (gift-importer.php) and a Switch Plugins source
 * (import-hub.php). Read from Charitable 1.8.15.1's own source:
 *
 * - A gift is a 'donation' post. Money received is post_status
 *   charitable-completed only (charitable_get_approval_statuses()).
 * - The amount isn't post meta. It's the sum of the gift's rows in
 *   {prefix}charitable_campaign_donations (one row per campaign), and the
 *   donor is that table's donor_id.
 * - Donors: {prefix}charitable_donors, email, first_name, last_name.
 *   data_erased is set once the privacy eraser ran, so those stay behind.
 * - Gift meta: test_mode (1 for a test gift), currency, donation_gateway,
 *   _gateway_transaction_id, and donation_refund (array with total_refund
 *   for a partial refund).
 * - A renewal has post_parent set to a recurring_donation post. Those come
 *   from the paid Charitable Recurring add-on, whose source we can't read,
 *   so the billing period is unknown and the gift is stored as 'recurring'.
 *
 * Never read: charitable_settings beyond its 'currency' key (it holds the
 * Stripe, PayPal, and Square keys and the licence), or any standalone secret
 * option.
 *
 * Data removal stays with Charitable: on deactivation it deletes campaigns,
 * donations, and its tables when its "Reset Data" setting
 * (delete_data_on_uninstall) is on.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-charitable-source.php
// =====================================================================

/**
 * Turn one Charitable gift into the shape npmp_past_gift_record() takes.
 *
 * @param array  $row           ID, post_date_gmt, post_date, amount, parent_type.
 * @param array  $meta          Gift meta, key => value (donation_refund unserialized).
 * @param string $site_currency Charitable's site currency.
 * @return array|null Null for a test gift or one refunded in full.
 */
function npmp_charitable_gift_from_row( array $row, array $meta, $site_currency ) {
	if ( ! empty( $meta['test_mode'] ) && 0 !== (int) $meta['test_mode'] ) {
		return null;
	}
	$amount = npmp_past_gift_amount( (string) ( $row['amount'] ?? '' ) );
	$refund = $meta['donation_refund'] ?? null;
	if ( is_array( $refund ) && isset( $refund['total_refund'] ) && is_numeric( $refund['total_refund'] ) ) {
		$amount = round( $amount - (float) $refund['total_refund'], 3 );
	}
	if ( $amount <= 0 ) {
		return null;
	}

	$date = (string) ( $row['post_date_gmt'] ?? '' );
	if ( '' === $date || 0 === strpos( $date, '0000' ) ) {
		$date = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( (string) $row['post_date'] ) : (string) $row['post_date'];
	}
	$currency = (string) ( $meta['currency'] ?? '' );

	return array(
		'source_id'      => (int) $row['ID'],
		'amount'         => number_format( $amount, 3, '.', '' ),
		'currency'       => '' !== $currency ? $currency : $site_currency,
		'date_gmt'       => $date,
		'gateway'        => (string) ( $meta['donation_gateway'] ?? '' ),
		'frequency'      => 'recurring_donation' === (string) ( $row['parent_type'] ?? '' ) ? 'recurring' : 'one_time',
		'transaction_id' => (string) ( $meta['_gateway_transaction_id'] ?? '' ),
	);
}

// =====================================================================
// Database
// =====================================================================

/**
 * Full table name when it exists.
 *
 * @param string $short Name without the prefix.
 * @return string '' when missing.
 */
function npmp_charitable_table( $short ) {
	global $wpdb;
	$name = $wpdb->prefix . $short;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ? $name : '';
}

/**
 * Charitable's site currency. Reads only the currency key.
 *
 * @return string
 */
function npmp_charitable_site_currency() {
	$settings = get_option( 'charitable_settings', array() );
	$code     = is_array( $settings ) ? npmp_normalize_currency_code( $settings['currency'] ?? '' ) : '';
	return '' !== $code ? $code : 'USD';
}

/**
 * The next donors, each with their received gifts.
 *
 * @param int $after Last donor ID done.
 * @param int $limit How many donors.
 * @return array[]
 */
function npmp_charitable_donor_batch( $after, $limit ) {
	global $wpdb;
	$donors = npmp_charitable_table( 'charitable_donors' );
	$cd     = npmp_charitable_table( 'charitable_campaign_donations' );
	if ( ! $donors || ! $cd ) {
		return array();
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT donor_id, email, first_name, last_name, data_erased FROM {$donors} WHERE donor_id > %d ORDER BY donor_id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	if ( ! $rows ) {
		return array();
	}
	$ids   = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'donor_id' ) ) );
	$gifts = $wpdb->get_results(
		"SELECT p.ID, p.post_date, p.post_date_gmt, pp.post_type AS parent_type, cd.donor_id, SUM(cd.amount) AS amount
		 FROM {$cd} cd
		 INNER JOIN {$wpdb->posts} p ON p.ID = cd.donation_id
		 LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent AND p.post_parent > 0
		 WHERE cd.donor_id IN ({$ids}) AND p.post_type = 'donation' AND p.post_status = 'charitable-completed'
		 GROUP BY p.ID, p.post_date, p.post_date_gmt, pp.post_type, cd.donor_id
		 ORDER BY p.ID ASC",
		ARRAY_A
	);
	$meta = array();
	if ( $gifts ) {
		$gift_ids = implode( ',', array_map( 'intval', wp_list_pluck( $gifts, 'ID' ) ) );
		$keys     = array( 'test_mode', 'currency', 'donation_gateway', '_gateway_transaction_id', 'donation_refund' );
		foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$gift_ids}) AND meta_key IN ('" . implode( "','", $keys ) . "')", ARRAY_A ) as $m ) {
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = 'donation_refund' === $m['meta_key'] ? maybe_unserialize( $m['meta_value'] ) : $m['meta_value'];
		}
	}
	// phpcs:enable

	$currency = npmp_charitable_site_currency();
	$by_donor = array();
	foreach ( (array) $gifts as $g ) {
		$gift = npmp_charitable_gift_from_row( $g, $meta[ (int) $g['ID'] ] ?? array(), $currency );
		if ( $gift ) {
			$by_donor[ (int) $g['donor_id'] ][] = $gift;
		}
	}

	$out = array();
	foreach ( $rows as $row ) {
		$id     = (int) $row['donor_id'];
		$erased = ! empty( $row['data_erased'] ) && 0 !== strpos( (string) $row['data_erased'], '0000' );
		$out[]  = array(
			'cursor' => $id,
			'email'  => (string) $row['email'],
			'name'   => trim( $row['first_name'] . ' ' . $row['last_name'] ),
			'extra'  => array(),
			'gifts'  => $erased ? array() : ( $by_donor[ $id ] ?? array() ),
		);
	}
	return $out;
}

/**
 * Counts for the importer screen and the Verify step.
 *
 * @return array{gifts:int,donors:int,test:int,not_received:int,active_plans:int}
 */
function npmp_charitable_counts() {
	global $wpdb;
	$cd = npmp_charitable_table( 'charitable_campaign_donations' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts, table names from $wpdb->prefix.
	$test_join = "LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'test_mode'";
	$live      = "p.post_type = 'donation' AND p.post_status = 'charitable-completed' AND COALESCE(t.meta_value, '0') IN ('', '0')";
	$gifts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$test_join} WHERE {$live}" );
	$test      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$test_join} WHERE p.post_type = 'donation' AND p.post_status = 'charitable-completed' AND COALESCE(t.meta_value, '0') NOT IN ('', '0')" );
	$other     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'donation' AND p.post_status NOT IN ('charitable-completed', 'trash', 'auto-draft')" );
	$donors    = $cd ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cd.donor_id) FROM {$cd} cd INNER JOIN {$wpdb->posts} p ON p.ID = cd.donation_id {$test_join} WHERE {$live}" ) : 0;
	$plans     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'recurring_donation' AND post_status = 'charitable-active'" );
	// phpcs:enable
	return array(
		'gifts'        => $gifts,
		'donors'       => $donors,
		'test'         => $test,
		'not_received' => $other,
		'active_plans' => $plans,
	);
}

/**
 * Anything of Charitable's left in the database?
 *
 * @return bool
 */
function npmp_charitable_has_data() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('donation','campaign')" );
	return $posts > 0 || '' !== npmp_charitable_table( 'charitable_donors' );
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_gift_import_sources', 'npmp_charitable_register_gift_source' );
add_filter( 'npmp_import_sources', 'npmp_charitable_register_hub_source' );

/**
 * Add Charitable to the gift importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_charitable_register_gift_source( $sources ) {
	$sources['charitable'] = array(
		'name'        => 'Charitable',
		'has_data'    => 'npmp_charitable_has_data',
		'counts'      => 'npmp_charitable_counts',
		'donor_batch' => 'npmp_charitable_donor_batch',
	);
	return $sources;
}

/**
 * Charitable and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_charitable_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 'charitable' === $folder || ( 0 === strpos( $folder, 'charitable-' ) && 0 === stripos( $name, 'Charitable' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Add Charitable to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_charitable_register_hub_source( $sources ) {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return $sources;
	}
	$sources['charitable'] = npmp_gift_hub_source(
		'charitable',
		'Charitable',
		array( 'charitable' ),
		'npmp_charitable_installed_plugins',
		array(
			__( 'Open three donors under Contacts and compare their giving totals with Charitable\'s Donors screen.', 'nonprofit-manager' ),
			__( 'Check that donors on a monthly plan have a new gift set up on your form before you turn Charitable off.', 'nonprofit-manager' ),
			__( 'Look at Reset Data on Charitable\'s Advanced settings tab. If it\'s on, you delete every Charitable campaign, donation, and donor table when you turn Charitable off in step 3.', 'nonprofit-manager' ),
		),
		__( 'Charitable deletes its own campaigns, donations, and tables when you turn on Reset Data on its Advanced settings tab and then turn Charitable off. We leave that choice with Charitable.', 'nonprofit-manager' )
	);
	return $sources;
}
