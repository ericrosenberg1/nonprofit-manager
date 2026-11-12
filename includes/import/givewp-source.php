<?php
/**
 * File path: includes/import/givewp-source.php
 *
 * GiveWP as a gift source (gift-importer.php) and a Switch Plugins source
 * (import-hub.php). Read from GiveWP 4.19.1.1's own source:
 *
 * - A gift is a give_payment post. Money received is post_status publish
 *   (a one-time gift or a plan's first payment) or give_subscription (a
 *   renewal). Every GiveWP total counts only those two.
 * - Gift meta sits in {prefix}give_donationmeta (donation_id), or on older
 *   sites in give_paymentmeta (payment_id) or wp_postmeta, by the upgrades in
 *   option give_completed_upgrades.
 * - _give_payment_mode 'test' marks a test gift. Amounts are decimal strings.
 * - Donors: {prefix}give_donors (legacy give_customers), one row per email.
 *   Their billing address: give_donormeta, keys ..._billing_line1_0 and so on.
 * - Plans: {prefix}give_subscriptions, period and frequency. A renewal carries
 *   subscription_id meta. A first payment carries _give_subscription_payment
 *   and is the plan's parent_payment_id.
 *
 * Never read: give_settings beyond its 'currency' and 'uninstall_on_delete' keys (it holds the Stripe
 * keys), the PayPal Commerce options, or anyone's API keys.
 *
 * Data removal stays with GiveWP: its uninstall.php deletes forms, gifts, and
 * every give_ table when "Remove Data on Uninstall" is on.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

// =====================================================================
// Pure helpers, covered by tests/test-givewp-source.php
// =====================================================================

/**
 * Which table holds gift meta, from GiveWP's upgrade flags.
 *
 * @param array $upgrades     Option give_completed_upgrades.
 * @param bool  $fresh        True when give_version is missing (GiveWP treats that as every upgrade done).
 * @param array $tables_found Short names that exist: give_donationmeta, give_paymentmeta.
 * @return string give_donationmeta, give_paymentmeta, or postmeta.
 */
function npmp_givewp_pick_meta_table( array $upgrades, $fresh, array $tables_found ) {
	$done = function ( $name ) use ( $upgrades, $fresh ) {
		return $fresh || in_array( $name, $upgrades, true );
	};
	if ( ! $done( 'v20_move_metadata_into_new_table' ) ) {
		return 'postmeta';
	}
	if ( $done( 'v220_rename_donation_meta_type' ) && in_array( 'give_donationmeta', $tables_found, true ) ) {
		return 'give_donationmeta';
	}
	if ( in_array( 'give_paymentmeta', $tables_found, true ) ) {
		return 'give_paymentmeta';
	}
	return in_array( 'give_donationmeta', $tables_found, true ) ? 'give_donationmeta' : 'postmeta';
}

/**
 * Turn one GiveWP gift into the shape npmp_past_gift_record() takes.
 *
 * @param array $post  ID, post_date_gmt, post_date.
 * @param array $meta  Gift meta, key => value.
 * @param array $plans Plan rows by ID: period, frequency.
 * @param array $first Plan rows by first payment ID.
 * @param string $site_currency GiveWP's site currency.
 * @return array|null Null for a test gift.
 */
function npmp_givewp_gift_from_row( array $post, array $meta, array $plans, array $first, $site_currency ) {
	if ( 'test' === strtolower( (string) ( $meta['_give_payment_mode'] ?? '' ) ) ) {
		return null;
	}
	$id   = (int) $post['ID'];
	$plan = null;
	if ( ! empty( $meta['subscription_id'] ) && isset( $plans[ (int) $meta['subscription_id'] ] ) ) {
		$plan = $plans[ (int) $meta['subscription_id'] ];
	} elseif ( isset( $first[ $id ] ) ) {
		$plan = $first[ $id ];
	}
	$frequency = 'one_time';
	if ( $plan ) {
		$frequency = npmp_past_gift_frequency( (string) $plan['period'], (int) $plan['frequency'] );
		if ( '' === $frequency || 'one_time' === $frequency ) {
			$frequency = 'recurring';
		}
	} elseif ( ! empty( $meta['subscription_id'] ) || ! empty( $meta['_give_subscription_payment'] ) ) {
		$frequency = 'recurring';
	}

	$date = (string) ( $post['post_date_gmt'] ?? '' );
	if ( '' === $date || 0 === strpos( $date, '0000' ) ) {
		$date = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( (string) $post['post_date'] ) : (string) $post['post_date'];
	}
	$currency = (string) ( $meta['_give_payment_currency'] ?? '' );
	$name     = trim( (string) ( $meta['_give_donor_billing_first_name'] ?? '' ) . ' ' . (string) ( $meta['_give_donor_billing_last_name'] ?? '' ) );

	return array(
		'source_id'      => $id,
		'name'           => $name,
		'amount'         => (string) ( $meta['_give_payment_total'] ?? '' ),
		'currency'       => '' !== $currency ? $currency : $site_currency,
		'date_gmt'       => $date,
		'gateway'        => (string) ( $meta['_give_payment_gateway'] ?? '' ),
		'frequency'      => $frequency,
		'transaction_id' => (string) ( $meta['_give_payment_transaction_id'] ?? '' ),
	);
}

// =====================================================================
// Database
// =====================================================================

/** Statuses GiveWP counts as money received. */
function npmp_givewp_received_statuses() {
	return array( 'publish', 'give_subscription' );
}

/**
 * Full table name when it exists.
 *
 * @param string $short Name without the prefix.
 * @return string '' when missing.
 */
function npmp_givewp_table( $short ) {
	global $wpdb;
	$name = $wpdb->prefix . $short;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) ? $name : '';
}

/**
 * Gift meta table and its ID column.
 *
 * @return array{0:string,1:string}
 */
function npmp_givewp_meta_table() {
	global $wpdb;
	$found = array();
	foreach ( array( 'give_donationmeta', 'give_paymentmeta' ) as $short ) {
		if ( npmp_givewp_table( $short ) ) {
			$found[] = $short;
		}
	}
	$pick = npmp_givewp_pick_meta_table( (array) get_option( 'give_completed_upgrades', array() ), false === get_option( 'give_version', false ), $found );
	if ( 'give_donationmeta' === $pick ) {
		return array( $wpdb->prefix . 'give_donationmeta', 'donation_id' );
	}
	if ( 'give_paymentmeta' === $pick ) {
		return array( $wpdb->prefix . 'give_paymentmeta', 'payment_id' );
	}
	return array( $wpdb->postmeta, 'post_id' );
}

/**
 * The donors table.
 *
 * @return string '' when GiveWP never made one.
 */
function npmp_givewp_donors_table() {
	$table = npmp_givewp_table( 'give_donors' );
	return $table ? $table : npmp_givewp_table( 'give_customers' );
}

/**
 * GiveWP's site currency. Reads only the currency key.
 *
 * @return string
 */
function npmp_givewp_site_currency() {
	$settings = get_option( 'give_settings', array() );
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
function npmp_givewp_donor_batch( $after, $limit ) {
	global $wpdb;
	$donors_table = npmp_givewp_donors_table();
	if ( ! $donors_table ) {
		return array();
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, name FROM {$donors_table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	if ( ! $rows ) {
		return array();
	}
	$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );

	list( $meta_table, $meta_id ) = npmp_givewp_meta_table();
	$in_ids      = implode( ',', $ids );
	$in_statuses = "'" . implode( "','", npmp_givewp_received_statuses() ) . "'";
	$posts       = $wpdb->get_results(
		"SELECT p.ID, p.post_date, p.post_date_gmt, CAST(dm.meta_value AS UNSIGNED) AS donor_id
		 FROM {$wpdb->posts} p
		 INNER JOIN {$meta_table} dm ON dm.{$meta_id} = p.ID AND dm.meta_key = '_give_payment_donor_id'
		 WHERE p.post_type = 'give_payment' AND p.post_status IN ({$in_statuses}) AND dm.meta_value IN ('" . implode( "','", $ids ) . "')
		 ORDER BY p.ID ASC",
		ARRAY_A
	);

	$meta = array();
	$subs = array();
	if ( $posts ) {
		$gift_ids = implode( ',', array_map( 'intval', wp_list_pluck( $posts, 'ID' ) ) );
		$keys     = array( '_give_payment_total', '_give_payment_currency', '_give_payment_gateway', '_give_payment_mode', '_give_payment_transaction_id', 'subscription_id', '_give_subscription_payment', '_give_donor_billing_first_name', '_give_donor_billing_last_name' );
		$meta_rows = $wpdb->get_results( "SELECT {$meta_id} AS id, meta_key, meta_value FROM {$meta_table} WHERE {$meta_id} IN ({$gift_ids}) AND meta_key IN ('" . implode( "','", $keys ) . "')", ARRAY_A );
		foreach ( (array) $meta_rows as $m ) {
			$meta[ (int) $m['id'] ][ $m['meta_key'] ] = $m['meta_value'];
		}

		$plans = array();
		$first = array();
		$table = npmp_givewp_table( 'give_subscriptions' );
		if ( $table ) {
			$plan_ids = array();
			foreach ( $meta as $m ) {
				if ( ! empty( $m['subscription_id'] ) ) {
					$plan_ids[] = (int) $m['subscription_id'];
				}
			}
			$where = "parent_payment_id IN ({$gift_ids})";
			if ( $plan_ids ) {
				$where .= ' OR id IN (' . implode( ',', array_unique( $plan_ids ) ) . ')';
			}
			foreach ( (array) $wpdb->get_results( "SELECT id, period, frequency, parent_payment_id FROM {$table} WHERE {$where}", ARRAY_A ) as $plan ) {
				$plans[ (int) $plan['id'] ]                = $plan;
				$first[ (int) $plan['parent_payment_id'] ] = $plan;
			}
		}
		$subs = array( $plans, $first );
	}

	$address = array();
	$dmeta   = npmp_givewp_table( 'give_donormeta' );
	if ( $dmeta ) {
		$map = array(
			'_give_donor_address_billing_line1_0'   => 'address_line1',
			'_give_donor_address_billing_line2_0'   => 'address_line2',
			'_give_donor_address_billing_city_0'    => 'city',
			'_give_donor_address_billing_state_0'   => 'state',
			'_give_donor_address_billing_zip_0'     => 'postal_code',
			'_give_donor_address_billing_country_0' => 'country',
		);
		$arows = $wpdb->get_results( "SELECT donor_id, meta_key, meta_value FROM {$dmeta} WHERE donor_id IN ({$in_ids}) AND meta_key IN ('" . implode( "','", array_keys( $map ) ) . "')", ARRAY_A );
		foreach ( (array) $arows as $a ) {
			$address[ (int) $a['donor_id'] ][ $map[ $a['meta_key'] ] ] = sanitize_text_field( (string) $a['meta_value'] );
		}
	}
	// phpcs:enable

	$by_donor = array();
	$currency = npmp_givewp_site_currency();
	foreach ( (array) $posts as $post ) {
		$gift = npmp_givewp_gift_from_row( $post, $meta[ (int) $post['ID'] ] ?? array(), $subs[0] ?? array(), $subs[1] ?? array(), $currency );
		if ( $gift ) {
			$by_donor[ (int) $post['donor_id'] ][] = $gift;
		}
	}

	$out = array();
	foreach ( $rows as $row ) {
		$id    = (int) $row['id'];
		$out[] = array(
			'cursor' => $id,
			'email'  => (string) $row['email'],
			'name'   => (string) $row['name'],
			'extra'  => $address[ $id ] ?? array(),
			'gifts'  => $by_donor[ $id ] ?? array(),
		);
	}
	return $out;
}

/**
 * Counts for the importer screen and the Verify step.
 *
 * @return array{gifts:int,donors:int,test:int,not_received:int,active_plans:int}
 */
function npmp_givewp_counts() {
	global $wpdb;
	list( $meta_table, $meta_id ) = npmp_givewp_meta_table();
	$in = "'" . implode( "','", npmp_givewp_received_statuses() ) . "'";
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin-only counts, table names from $wpdb->prefix.
	$mode  = "LEFT JOIN {$meta_table} mo ON mo.{$meta_id} = p.ID AND mo.meta_key = '_give_payment_mode'";
	$gifts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$mode} WHERE p.post_type = 'give_payment' AND p.post_status IN ({$in}) AND COALESCE(mo.meta_value, '') <> 'test'" );
	$test  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$mode} WHERE p.post_type = 'give_payment' AND p.post_status IN ({$in}) AND mo.meta_value = 'test'" );
	$other = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'give_payment' AND p.post_status NOT IN ({$in}, 'trash', 'auto-draft')" );
	$donor = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT dm.meta_value) FROM {$wpdb->posts} p {$mode} INNER JOIN {$meta_table} dm ON dm.{$meta_id} = p.ID AND dm.meta_key = '_give_payment_donor_id' WHERE p.post_type = 'give_payment' AND p.post_status IN ({$in}) AND COALESCE(mo.meta_value, '') <> 'test'" );
	$plans = 0;
	$table = npmp_givewp_table( 'give_subscriptions' );
	if ( $table ) {
		$plans = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$table} WHERE status IN ('active','failing')" );
	}
	// phpcs:enable
	return array(
		'gifts'        => $gifts,
		'donors'       => $donor,
		'test'         => $test,
		'not_received' => $other,
		'active_plans' => $plans,
	);
}

/**
 * Anything of GiveWP's left in the database?
 *
 * @return bool
 */
function npmp_givewp_has_data() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only check.
	$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('give_payment','give_forms')" );
	return $posts > 0 || '' !== npmp_givewp_donors_table();
}

// =====================================================================
// Registration
// =====================================================================

add_filter( 'npmp_gift_import_sources', 'npmp_givewp_register_gift_source' );
add_filter( 'npmp_import_sources', 'npmp_givewp_register_hub_source' );

/**
 * Add GiveWP to the gift importer.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_givewp_register_gift_source( $sources ) {
	$sources['givewp'] = array(
		'name'        => 'GiveWP',
		'has_data'    => 'npmp_givewp_has_data',
		'counts'      => 'npmp_givewp_counts',
		'donor_batch' => 'npmp_givewp_donor_batch',
	);
	return $sources;
}

/**
 * GiveWP and its add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_givewp_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$folder = strtok( (string) $file, '/' );
		$name   = (string) ( $data['Name'] ?? '' );
		if ( 'give' === $folder || ( 0 === strpos( $folder, 'give-' ) && 0 === stripos( $name, 'Give' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Add GiveWP to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_givewp_register_hub_source( $sources ) {
	if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
		return $sources;
	}
	$sources['givewp'] = npmp_gift_hub_source(
		'givewp',
		'GiveWP',
		array( 'give' ),
		'npmp_givewp_installed_plugins',
		array(
			__( 'Open three donors under Contacts and compare their giving totals with GiveWP\'s Donors screen.', 'nonprofit-manager' ),
			__( 'Check that donors on a monthly plan have a new gift set up on your form before you turn GiveWP off.', 'nonprofit-manager' ),
			__( 'Look at Remove Data on Uninstall on GiveWP\'s Advanced settings tab. If it\'s on, you delete every GiveWP form, donation, and table when you delete GiveWP in step 4.', 'nonprofit-manager' ),
		),
		__( 'GiveWP deletes its own forms, donations, and tables when you turn on Remove Data on Uninstall on its Advanced settings tab and then delete GiveWP. We leave that choice with GiveWP.', 'nonprofit-manager' ),
		array( 'delete_note' => 'npmp_givewp_delete_note' )
	);
	return $sources;
}

/**
 * Whether GiveWP's "Remove Data on Uninstall" is on. GiveWP's uninstall.php
 * checks give_is_setting_enabled( give_get_option( 'uninstall_on_delete' ) ),
 * which counts 'enabled', 'on', and 'yes'.
 *
 * @param mixed $settings give_settings.
 * @return bool
 */
function npmp_givewp_removes_data_on_delete( $settings ) {
	$value = is_array( $settings ) ? ( $settings['uninstall_on_delete'] ?? '' ) : '';
	return is_string( $value ) && in_array( $value, array( 'enabled', 'on', 'yes' ), true );
}

/**
 * A warning for step 4 when GiveWP will delete its data along with its files.
 *
 * @return string
 */
function npmp_givewp_delete_note() {
	if ( ! npmp_givewp_removes_data_on_delete( get_option( 'give_settings', array() ) ) ) {
		return '';
	}
	return __( 'GiveWP\'s Remove Data on Uninstall setting is on, so deleting GiveWP also deletes its forms, donations, donors, and tables. To keep them, set it to "No, keep my GiveWP settings and donation data" under Donations, Settings, Advanced first.', 'nonprofit-manager' );
}
