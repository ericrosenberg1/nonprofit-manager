<?php
/**
 * File path: includes/import/pmpro-importer.php
 *
 * Importer and Switch Plugins source for Paid Memberships Pro (folder
 * paid-memberships-pro). Read from Paid Memberships Pro 3.9.1's own source:
 *
 * - Levels live in {prefix}pmpro_membership_levels (id, name, and the price
 *   and billing columns). We read id and name and nothing else.
 * - Every membership a person ever held is a row in
 *   {prefix}pmpro_memberships_users: user_id, membership_id, status, startdate,
 *   enddate. A current membership has status 'active', the same test
 *   pmpro_getMembershipLevelForUser() uses. Every other status (inactive,
 *   cancelled, admin_cancelled, expired, changed, admin_changed) is history.
 *   Checkout writes startdate with current_time( 'mysql' ), so it holds the
 *   site's local time. PMPro's own reads wrap it in CONVERT_TZ() and then
 *   date_i18n(), which cancel out and show the stored value as it is.
 * - Members are WordPress users. Name and email come from the user, and the
 *   billing address and phone from the pmpro_b* user meta that
 *   includes/privacy.php lists.
 * - uninstall.php drops every PMPro table and pmpro_ option when you delete the
 *   plugin, but only with "Uninstall PMPro on deletion?" set to Yes
 *   (option pmpro_uninstall) under Memberships, Settings, Advanced Settings.
 *
 * Never read: orders, subscriptions, discount codes, payment gateway settings,
 * card details, or any API key. Members' recurring payments stay with the
 * payment processor, and dues billing here is Pro.
 *
 * Data removal stays unavailable: Paid Memberships Pro's own uninstall setting
 * deletes its data when you delete the plugin, and those tables hold your
 * order history.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_PMPRO_BATCH' ) ) {
	define( 'NPMP_PMPRO_BATCH', 100 );
}
if ( ! defined( 'NPMP_PMPRO_SECONDS' ) ) {
	define( 'NPMP_PMPRO_SECONDS', 20 );
}

// =====================================================================
// Pure helpers, covered by tests/test-pmpro-importer.php
// =====================================================================

/**
 * Member groups you can keep or leave out.
 *
 * @return string[]
 */
function npmp_pmpro_groups() {
	return array( 'current', 'past' );
}

/**
 * Current when any membership row is active, past otherwise.
 *
 * @param string[] $statuses pmpro_memberships_users.status for one person.
 * @return string current|past
 */
function npmp_pmpro_member_group( array $statuses ) {
	foreach ( $statuses as $status ) {
		if ( 'active' === strtolower( trim( (string) $status ) ) ) {
			return 'current';
		}
	}
	return 'past';
}

/**
 * The contact status for a member group. Current members come over as
 * Active, past members as Inactive.
 *
 * @param string $group current|past.
 * @return string
 */
function npmp_pmpro_contact_status( $group ) {
	return 'current' === $group ? 'subscribed' : 'inactive';
}

/**
 * A stored datetime as a Unix timestamp, or 0 when empty.
 *
 * @param mixed             $value pmpro_memberships_users.startdate (site time) or users.user_registered (UTC).
 * @param DateTimeZone|null $tz    Zone the value is in. Defaults to the site's.
 * @return int
 */
function npmp_pmpro_date_to_ts( $value, $tz = null ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
		return 0;
	}
	if ( ! $tz instanceof DateTimeZone ) {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
	try {
		$date = new DateTimeImmutable( $value, $tz );
	} catch ( Exception $e ) {
		return 0;
	}
	$ts = $date->getTimestamp();
	return $ts > 0 ? $ts : 0;
}

/**
 * The level name for a person: the newest active membership, or '' with none.
 *
 * @param array[] $rows Each with status, name, start (timestamp), id.
 * @return string
 */
function npmp_pmpro_pick_level( array $rows ) {
	$best = null;
	foreach ( $rows as $row ) {
		if ( 'active' !== strtolower( trim( (string) ( $row['status'] ?? '' ) ) ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			continue;
		}
		if ( null === $best
			|| (int) $row['start'] > (int) $best['start']
			|| ( (int) $row['start'] === (int) $best['start'] && (int) $row['id'] > (int) $best['id'] ) ) {
			$best = $row;
		}
	}
	return $best ? trim( (string) $best['name'] ) : '';
}

/**
 * The earliest start date across a person's memberships, 0 with none.
 *
 * @param array[] $rows Each with start (timestamp).
 * @return int
 */
function npmp_pmpro_first_start( array $rows ) {
	$first = 0;
	foreach ( $rows as $row ) {
		$start = (int) ( $row['start'] ?? 0 );
		if ( $start > 0 && ( 0 === $first || $start < $first ) ) {
			$first = $start;
		}
	}
	return $first;
}

/**
 * Level names added to a list of levels, matched without regard to case.
 *
 * @param string[] $existing Levels you have.
 * @param string[] $incoming Paid Memberships Pro level names.
 * @return array{levels:string[],added:string[]}
 */
function npmp_pmpro_merge_levels( array $existing, array $incoming ) {
	$levels = array_values( array_filter( array_map( 'strval', $existing ), 'strlen' ) );
	$seen   = array();
	foreach ( $levels as $level ) {
		$seen[ strtolower( trim( $level ) ) ] = true;
	}
	$added = array();
	foreach ( $incoming as $name ) {
		$name = is_scalar( $name ) ? trim( wp_strip_all_tags( (string) $name ) ) : '';
		$key  = strtolower( $name );
		if ( '' === $name || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$levels[]     = $name;
		$added[]      = $name;
	}
	return array(
		'levels' => $levels,
		'added'  => $added,
	);
}

/**
 * The level an existing contact should get, or '' to leave it alone.
 *
 * A contact with no level takes the member's level. A contact this importer
 * made follows Paid Memberships Pro on a second run. A level someone set by
 * hand on any other contact stays.
 *
 * @param string $current  The contact's level.
 * @param string $source   The contact's source.
 * @param string $incoming The member's level, '' for a past member.
 * @return string
 */
function npmp_pmpro_level_update( $current, $source, $incoming ) {
	$current  = trim( (string) $current );
	$incoming = trim( (string) $incoming );
	if ( '' === $incoming || $current === $incoming ) {
		return '';
	}
	if ( '' === $current || 'pmpro' === (string) $source ) {
		return $incoming;
	}
	return '';
}

/**
 * Contact fields to fill from the member's billing details. Only fields the
 * contact has empty.
 *
 * @param object|array $member   The contact (phone, address_line1, ...).
 * @param array        $incoming The same keys from Paid Memberships Pro.
 * @return array
 */
function npmp_pmpro_fill_fields( $member, array $incoming ) {
	$member = (array) $member;
	$out    = array();
	foreach ( npmp_pmpro_address_keys() as $field => $meta_key ) {
		$value = is_scalar( $incoming[ $field ] ?? null ) ? trim( (string) $incoming[ $field ] ) : '';
		if ( '' !== $value && '' === trim( (string) ( $member[ $field ] ?? '' ) ) ) {
			$out[ $field ] = $value;
		}
	}
	return $out;
}

/**
 * Contact field => Paid Memberships Pro user meta key.
 *
 * @return array<string,string>
 */
function npmp_pmpro_address_keys() {
	return array(
		'phone'         => 'pmpro_bphone',
		'address_line1' => 'pmpro_baddress1',
		'address_line2' => 'pmpro_baddress2',
		'city'          => 'pmpro_bcity',
		'state'         => 'pmpro_bstate',
		'postal_code'   => 'pmpro_bzipcode',
		'country'       => 'pmpro_bcountry',
	);
}

// =====================================================================
// Reading Paid Memberships Pro's data
// =====================================================================

/**
 * A Paid Memberships Pro table, when it exists.
 *
 * @param string $name membership_levels or memberships_users.
 * @return string
 */
function npmp_pmpro_table( $name ) {
	global $wpdb;
	static $found = array();
	$table = $wpdb->prefix . 'pmpro_' . $name;
	if ( ! isset( $found[ $table ] ) || ! $found[ $table ] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table check.
		$found[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
	return $found[ $table ] ? $table : '';
}

/**
 * Whether both tables we read exist.
 *
 * @return bool
 */
function npmp_pmpro_has_tables() {
	return '' !== npmp_pmpro_table( 'membership_levels' ) && '' !== npmp_pmpro_table( 'memberships_users' );
}

/**
 * Levels, id => name.
 *
 * @return array<int,string>
 */
function npmp_pmpro_levels() {
	global $wpdb;
	$table = npmp_pmpro_table( 'membership_levels' );
	if ( ! $table ) {
		return array();
	}
	$out = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY id ASC", ARRAY_A ) as $row ) {
		$name = trim( wp_strip_all_tags( (string) $row['name'] ) );
		if ( '' !== $name ) {
			$out[ (int) $row['id'] ] = $name;
		}
	}
	return $out;
}

/**
 * People with a membership row and a WordPress account, by group.
 *
 * @return array{current:int,past:int}
 */
function npmp_pmpro_counts() {
	global $wpdb;
	$out   = array(
		'current' => 0,
		'past'    => 0,
	);
	$table = npmp_pmpro_table( 'memberships_users' );
	if ( ! $table ) {
		return $out;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb.
	$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(CASE WHEN a.active > 0 THEN 1 ELSE 0 END) AS current_count FROM ( SELECT mu.user_id, SUM(CASE WHEN mu.status = 'active' THEN 1 ELSE 0 END) AS active FROM {$table} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id GROUP BY mu.user_id ) a", ARRAY_A );
	$out['current'] = (int) ( $row['current_count'] ?? 0 );
	$out['past']    = (int) ( $row['total'] ?? 0 ) - $out['current'];
	return $out;
}

/**
 * Current members on a plan that renews and charges again.
 *
 * @return int
 */
function npmp_pmpro_recurring_count() {
	global $wpdb;
	$table = npmp_pmpro_table( 'memberships_users' );
	if ( ! $table ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb. A row whose user was deleted doesn't count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT mu.user_id) FROM {$table} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE mu.status = 'active' AND mu.billing_amount > 0 AND mu.cycle_number > 0" );
}

/**
 * Current members who hold more than one level at once.
 *
 * @return int
 */
function npmp_pmpro_multi_level_count() {
	global $wpdb;
	$table = npmp_pmpro_table( 'memberships_users' );
	if ( ! $table ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT mu.user_id FROM {$table} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE mu.status = 'active' GROUP BY mu.user_id HAVING COUNT(*) > 1 ) m" );
}

/**
 * One batch of members, lowest user ID first.
 *
 * @param int $after Last user ID read.
 * @param int $limit People.
 * @return object[] id, email, first_name, last_name, group, level, created, fields.
 */
function npmp_pmpro_read_rows( $after, $limit ) {
	global $wpdb;
	$table = npmp_pmpro_table( 'memberships_users' );
	if ( ! $table ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb, values prepared.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT mu.user_id FROM {$table} mu INNER JOIN {$wpdb->users} u ON u.ID = mu.user_id WHERE mu.user_id > %d ORDER BY mu.user_id ASC LIMIT %d", (int) $after, max( 1, (int) $limit ) ) ) );
	if ( ! $ids ) {
		return array();
	}
	$levels = npmp_pmpro_levels();
	$in     = implode( ',', $ids );
	$by     = array();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of integers.
	foreach ( (array) $wpdb->get_results( "SELECT id, user_id, membership_id, status, startdate FROM {$table} WHERE user_id IN ({$in})", ARRAY_A ) as $row ) {
		$by[ (int) $row['user_id'] ][] = array(
			'id'     => (int) $row['id'],
			'status' => (string) $row['status'],
			'name'   => $levels[ (int) $row['membership_id'] ] ?? '',
			'start'  => npmp_pmpro_date_to_ts( $row['startdate'] ),
		);
	}
	if ( function_exists( 'cache_users' ) ) {
		cache_users( $ids );
	}

	$out = array();
	foreach ( $ids as $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			continue;
		}
		$rows   = $by[ $user_id ] ?? array();
		$first  = (string) get_user_meta( $user_id, 'first_name', true );
		$last   = (string) get_user_meta( $user_id, 'last_name', true );
		$fields = array();
		foreach ( npmp_pmpro_address_keys() as $field => $meta_key ) {
			$fields[ $field ] = (string) get_user_meta( $user_id, $meta_key, true );
		}
		if ( '' === trim( $first . $last ) ) {
			$first = (string) get_user_meta( $user_id, 'pmpro_bfirstname', true );
			$last  = (string) get_user_meta( $user_id, 'pmpro_blastname', true );
		}
		if ( '' === trim( $first . $last ) && (string) $user->display_name !== (string) $user->user_login ) {
			$first = (string) $user->display_name;
		}
		$start = npmp_pmpro_first_start( $rows );
		$out[] = (object) array(
			'id'         => $user_id,
			'email'      => (string) $user->user_email,
			'first_name' => $first,
			'last_name'  => $last,
			'group'      => npmp_pmpro_member_group( wp_list_pluck( $rows, 'status' ) ),
			'level'      => npmp_pmpro_pick_level( $rows ),
			'created'    => $start ? $start : npmp_pmpro_date_to_ts( $user->user_registered, new DateTimeZone( 'UTC' ) ),
			'fields'     => $fields,
		);
	}
	return $out;
}

// =====================================================================
// The import
// =====================================================================

/**
 * Add every Paid Memberships Pro level to your membership levels.
 *
 * @return string[] Names added.
 */
function npmp_pmpro_add_levels() {
	$current = function_exists( 'npmp_get_membership_levels_array' ) ? npmp_get_membership_levels_array() : (array) get_option( 'npmp_membership_levels', array() );
	$merged  = npmp_pmpro_merge_levels( $current, array_values( npmp_pmpro_levels() ) );
	if ( $merged['added'] ) {
		update_option( 'npmp_membership_levels', $merged['levels'] );
	}
	return $merged['added'];
}

/**
 * Create or update one contact from a member.
 *
 * @param object     $row      Row from npmp_pmpro_read_rows().
 * @param array|null $existing array{id:int,status:string} from npmp_mailpoet_find_contacts(), or null.
 * @return array{result:string,member_id:int,message:string,kept_unsubscribed:bool}
 */
function npmp_pmpro_import_member( $row, $existing = null ) {
	$out = array(
		'result'            => 'error',
		'member_id'         => 0,
		'message'           => '',
		'kept_unsubscribed' => false,
	);

	$email = sanitize_email( (string) $row->email );
	if ( '' === $email || ! is_email( $email ) ) {
		$out['result'] = 'invalid';
		return $out;
	}

	$members  = NPMP_Member_Manager::get_instance();
	$name     = npmp_mailpoet_full_name( $row->first_name, $row->last_name );
	$incoming = npmp_pmpro_contact_status( $row->group );
	$added    = $row->created > 0 ? (string) (int) $row->created : '';
	$member   = $existing ? $members->get_member_by_id( (int) $existing['id'] ) : null;

	if ( ! $member ) {
		$data = array_merge(
			array_filter( array_map( 'trim', array_map( 'strval', (array) $row->fields ) ), 'strlen' ),
			array(
				'name'             => $name,
				'email'            => $email,
				'status'           => $incoming,
				'source'           => 'pmpro',
				'membership_level' => (string) $row->level,
			)
		);
		if ( '' !== $added ) {
			$data['created_at'] = $added;
		}
		$new_id = $members->add_member( $data );
		if ( is_wp_error( $new_id ) && 'npmp_member_exists' === $new_id->get_error_code() ) {
			$member = $members->get_member_by_email( $email );
		} elseif ( is_wp_error( $new_id ) ) {
			$out['message'] = $new_id->get_error_message();
			return $out;
		} else {
			$out['result']    = 'created';
			$out['member_id'] = (int) $new_id;
			$final_status     = $incoming;
			$final_level      = (string) $row->level;
		}
	}

	if ( $member ) {
		$update     = npmp_pmpro_fill_fields( $member, (array) $row->fields );
		$new_status = npmp_mailpoet_merge_status( $member->status, $incoming );
		$new_name   = npmp_mailpoet_merge_name( $member->name, $member->email, $name );
		$new_level  = npmp_pmpro_level_update( $member->membership_level ?? '', $member->source ?? '', $row->level );
		if ( '' !== $new_status ) {
			$update['status'] = $new_status;
		}
		if ( '' !== $new_name ) {
			$update['name'] = $new_name;
		}
		if ( '' !== $new_level ) {
			$update['membership_level'] = $new_level;
		}
		$parsed = '' !== $added && function_exists( 'npmp_import_parse_added_date' ) ? npmp_import_parse_added_date( $added ) : null;
		if ( $parsed && ( empty( $member->created_at ) || strtotime( $parsed['local'] ) < strtotime( $member->created_at ) ) ) {
			$update['created_at'] = $added;
		}
		if ( $update ) {
			$result = $members->update_member( (int) $member->id, $update );
			if ( is_wp_error( $result ) ) {
				$out['message'] = $result->get_error_message();
				return $out;
			}
		}
		$out['result']            = $update ? 'updated' : 'unchanged';
		$out['member_id']         = (int) $member->id;
		$out['kept_unsubscribed'] = 'unsubscribed' === $member->status;
		$final_status             = '' !== $new_status ? $new_status : $member->status;
		$final_level              = '' !== $new_level ? $new_level : (string) ( $member->membership_level ?? '' );
	}

	if ( ! $out['member_id'] ) {
		return $out;
	}

	/** The CSV import's own hook, so the free total and Pro's add-ons follow a member too. */
	do_action(
		'npmp_import_row_imported',
		(int) $out['member_id'],
		array(
			'name'             => $name,
			'email'            => $email,
			'status'           => $final_status,
			'source'           => 'pmpro',
			'membership_level' => $final_level,
		),
		array(
			'source_id' => (int) $row->id,
			'group'     => (string) $row->group,
		),
		array(),
		'created' === $out['result'] ? 'imported' : 'updated',
		array(
			'source'             => 'pmpro',
			'duplicate_handling' => 'update',
		)
	);

	return $out;
}

/**
 * Empty results.
 *
 * @return array
 */
function npmp_pmpro_empty_results() {
	return array(
		'created'           => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'invalid'           => 0,
		'left'              => 0,
		'over_limit'        => 0,
		'cap_reached'       => false,
		'kept_unsubscribed' => 0,
		'levels_added'      => array(),
		'failed'            => array(),
	);
}

/**
 * Saved progress.
 *
 * @return array{cursor:int,running:bool,finished_at:int,handled:int,skip:string[],results:array}
 */
function npmp_pmpro_state() {
	$state = get_option( 'npmp_pmpro_import', array() );
	$state = is_array( $state ) ? $state : array();
	return array(
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'handled'     => (int) ( $state['handled'] ?? 0 ),
		'skip'        => array_values( array_intersect( (array) ( $state['skip'] ?? array() ), npmp_pmpro_groups() ) ),
		'results'     => array_merge( npmp_pmpro_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param array $state State.
 * @return void
 */
function npmp_pmpro_save_state( array $state ) {
	update_option( 'npmp_pmpro_import', $state, false );
}

/**
 * Start a fresh run. Levels come over first.
 *
 * @param string[] $skip Groups to leave out.
 * @return void
 */
function npmp_pmpro_start( array $skip ) {
	$state            = npmp_pmpro_state();
	$state['cursor']  = 0;
	$state['running'] = true;
	$state['handled'] = 0;
	$state['skip']    = array_values( array_intersect( array_map( 'strval', $skip ), npmp_pmpro_groups() ) );
	$state['results'] = npmp_pmpro_empty_results();

	$state['results']['levels_added'] = npmp_pmpro_add_levels();
	npmp_pmpro_save_state( $state );
}

/**
 * Import batches until the members run out or the time is up.
 *
 * The free plugin reads up to npmp_import_max_rows() members in one run and
 * adds new contacts only while the site has free places left
 * (npmp_import_free_remaining(), shared with every import). Past that it still
 * updates contacts you already have. Pro lifts both limits.
 *
 * @param int $seconds Time budget.
 * @param int $limit   People per batch.
 * @return array State after the run.
 */
function npmp_pmpro_run( $seconds = NPMP_PMPRO_SECONDS, $limit = NPMP_PMPRO_BATCH ) {
	$state = npmp_pmpro_state();
	if ( ! $state['running'] || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return $state;
	}
	$max   = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
	$skip  = array_flip( $state['skip'] );
	$start = microtime( true );
	$done  = false;

	do {
		$rows     = npmp_pmpro_read_rows( $state['cursor'], $limit );
		$existing = $rows ? npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) ) : array();
		foreach ( $rows as $row ) {
			if ( isset( $skip[ $row->group ] ) ) {
				++$state['results']['left'];
				$state['cursor'] = $row->id;
				continue;
			}
			if ( $state['handled'] >= $max ) {
				$state['results']['cap_reached'] = true;
				$done                            = true;
				break;
			}
			$email = strtolower( sanitize_email( $row->email ) );
			$known = $existing[ $email ] ?? null;
			if ( ! $known && is_email( $email ) && function_exists( 'npmp_import_free_remaining' ) && npmp_import_free_remaining() < 1 ) {
				$state['results']['cap_reached'] = true;
				++$state['results']['over_limit'];
				$state['cursor'] = $row->id;
				continue;
			}
			$result = npmp_pmpro_import_member( $row, $known );
			if ( 'invalid' === $result['result'] ) {
				++$state['results']['invalid'];
			} elseif ( 'error' === $result['result'] ) {
				if ( count( $state['results']['failed'] ) < 50 ) {
					$state['results']['failed'][] = array(
						'email' => $row->email,
						'error' => $result['message'],
					);
				}
			} else {
				++$state['results'][ $result['result'] ];
				++$state['handled'];
				if ( $result['kept_unsubscribed'] ) {
					++$state['results']['kept_unsubscribed'];
				}
			}
			$state['cursor'] = $row->id;
		}
		if ( count( $rows ) < $limit ) {
			$done = true;
		}
		npmp_pmpro_save_state( $state );
	} while ( ! $done && ( microtime( true ) - $start ) < $seconds );

	if ( $done ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		npmp_pmpro_save_state( $state );
		update_option( 'npmp_pmpro_imported', 1, true );
	}
	return $state;
}

/**
 * Contacts the importer made.
 *
 * @return int
 */
function npmp_pmpro_count_contacts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_source' AND pm.meta_value = 'pmpro' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
}

/**
 * Members whose email isn't on any contact.
 *
 * @return int
 */
function npmp_pmpro_count_left_behind() {
	global $wpdb;
	$table = npmp_pmpro_table( 'memberships_users' );
	if ( ! $table ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb, no input.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.ID IN ( SELECT user_id FROM {$table} ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND LOWER(pm.meta_value) = LOWER(u.user_email) AND p.post_type = 'npmp_contact' AND p.post_status = 'publish' )" );
}

/**
 * Paid Memberships Pro levels you have as membership levels.
 *
 * @return int
 */
function npmp_pmpro_count_levels_here() {
	$here = array();
	foreach ( function_exists( 'npmp_get_membership_levels_array' ) ? npmp_get_membership_levels_array() : array() as $level ) {
		$here[ strtolower( trim( (string) $level ) ) ] = true;
	}
	$n = 0;
	foreach ( npmp_pmpro_levels() as $name ) {
		if ( isset( $here[ strtolower( $name ) ] ) ) {
			++$n;
		}
	}
	return $n;
}

// =====================================================================
// Switch Plugins source
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_pmpro_register_hub_source' );

/**
 * Paid Memberships Pro and its installed add-ons, basename => name.
 *
 * @return array<string,string>
 */
function npmp_pmpro_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$requires = array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) );
		$folder   = strtok( $file, '/' );
		if ( 'paid-memberships-pro/paid-memberships-pro.php' === $file || in_array( 'paid-memberships-pro', $requires, true ) || 0 === strpos( (string) $folder, 'pmpro-' ) ) {
			$out[ $file ] = (string) $data['Name'];
		}
	}
	return $out;
}

/**
 * Add Paid Memberships Pro to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_pmpro_register_hub_source( $sources ) {
	$sources['pmpro'] = array(
		'name'              => 'Paid Memberships Pro',
		'covers'            => __( 'Members, membership levels, join dates, and billing addresses', 'nonprofit-manager' ),
		'feature'           => __( 'Contacts and membership levels', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array( __( 'Bill membership dues automatically', 'nonprofit-manager' ) ),
		'plugin_folders'    => array( 'paid-memberships-pro' ),
		'has_data'          => 'npmp_pmpro_has_tables',
		'is_imported'       => function () {
			return (bool) get_option( 'npmp_pmpro_imported', 0 );
		},
		'can_import'        => function () {
			return npmp_pmpro_has_tables() ? true : __( 'You need Paid Memberships Pro\'s membership tables to import, and this site doesn\'t have those tables. Install Paid Memberships Pro, turn it on once so it sets up its tables, and come back to this screen.', 'nonprofit-manager' );
		},
		'import_url'        => 'npmp_pmpro_url',
		'import_summary'    => function () {
			$made = npmp_pmpro_count_contacts();
			if ( ! $made ) {
				return __( 'You brought over your membership levels and your members\' details.', 'nonprofit-manager' );
			}
			return sprintf(
				/* translators: %s: number of contacts. */
				_n( 'You have %s new contact from Paid Memberships Pro.', 'You have %s new contacts from Paid Memberships Pro.', $made, 'nonprofit-manager' ),
				number_format_i18n( $made )
			);
		},
		'counts'            => 'npmp_pmpro_hub_counts',
		'checklist'         => array(
			__( 'Open a few of your new contacts and check their names, emails, levels, and join dates.', 'nonprofit-manager' ),
			__( 'Cancel or move every automatic renewal at your payment processor before you turn Paid Memberships Pro off.', 'nonprofit-manager' ),
			__( 'Put a new signup form on every page that had a Paid Memberships Pro checkout or levels page.', 'nonprofit-manager' ),
			__( 'Check which pages you kept for members only, and set up another way to protect those pages.', 'nonprofit-manager' ),
		),
		'installed_plugins' => 'npmp_pmpro_installed_plugins',
		'delete_note'       => 'npmp_pmpro_delete_note',
		'data_removal'      => array(
			'reason' => __( 'Paid Memberships Pro\'s tables hold your order history, so we leave them in your database. To delete them when you delete the plugin, go to Memberships, Settings, Advanced Settings in Paid Memberships Pro and set "Uninstall PMPro on deletion?" to Yes first.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * A warning for step 4 when Paid Memberships Pro's uninstall setting is on.
 * Its uninstall.php then drops every PMPro table and option as WordPress
 * deletes the files.
 *
 * @return string
 */
function npmp_pmpro_delete_note() {
	if ( ! get_option( 'pmpro_uninstall', 0 ) ) {
		return '';
	}
	return __( 'Paid Memberships Pro\'s "Uninstall PMPro on deletion?" setting is on, so deleting the plugin also deletes its tables, settings, and order history. To keep them, set it to No under Memberships, Settings, Advanced Settings first.', 'nonprofit-manager' );
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_pmpro_hub_counts() {
	$counts    = npmp_pmpro_counts();
	$left      = npmp_pmpro_count_left_behind();
	$capped    = function_exists( 'npmp_import_is_capped' ) ? npmp_import_is_capped() : ( function_exists( 'npmp_import_max_rows' ) && npmp_import_max_rows() < PHP_INT_MAX );
	$places    = function_exists( 'npmp_import_free_remaining' ) ? npmp_import_free_remaining() : PHP_INT_MAX;
	$recurring = npmp_pmpro_recurring_count();
	$multi     = npmp_pmpro_multi_level_count();
	$left_note = __( 'Members you chose to leave out stay in Paid Memberships Pro.', 'nonprofit-manager' );
	if ( $left && $capped && $places < $left ) {
		$left_note = sprintf(
			/* translators: %s: free import limit, such as 100. */
			__( 'In the free version you can import %s contacts in all, so the rest are still only in Paid Memberships Pro.', 'nonprofit-manager' ),
			number_format_i18n( defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ? NPMP_IMPORT_FREE_MAX_ROWS : npmp_import_max_rows() )
		);
	}
	$rows = array(
		array(
			'label' => __( 'Current and past members', 'nonprofit-manager' ),
			'old'   => $counts['current'] + $counts['past'],
			'new'   => npmp_pmpro_count_contacts(),
			'note'  => __( 'Someone who was already your contact keeps where they first came from, so they don\'t count here.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Members not in your contacts', 'nonprofit-manager' ),
			'old'   => $left,
			'new'   => null,
			'note'  => $left ? $left_note : '',
		),
		array(
			'label' => __( 'Membership levels', 'nonprofit-manager' ),
			'old'   => count( npmp_pmpro_levels() ),
			'new'   => npmp_pmpro_count_levels_here(),
			'note'  => $multi
				? sprintf(
					/* translators: %s: number of members. */
					_n( '%s member holds more than one level, and the contact got the newest one.', '%s members hold more than one level, and each contact got the newest one.', $multi, 'nonprofit-manager' ),
					number_format_i18n( $multi )
				)
				: '',
		),
	);
	if ( $recurring ) {
		$rows[] = array(
			'label' => __( 'Members with automatic renewals', 'nonprofit-manager' ),
			'old'   => $recurring,
			'new'   => null,
			'note'  => __( 'These members keep paying through your payment processor after you turn Paid Memberships Pro off, and you won\'t see those payments in Nonprofit Manager. Cancel or move each renewal at your payment processor first.', 'nonprofit-manager' ),
		);
	}
	return $rows;
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_pmpro_register_menu', 30 );
add_action( 'admin_head', 'npmp_pmpro_hide_menu' );
add_action( 'admin_post_npmp_pmpro_import', 'npmp_pmpro_post' );
add_action( 'admin_enqueue_scripts', 'npmp_pmpro_enqueue' );

/**
 * URL of the screen.
 *
 * @return string
 */
function npmp_pmpro_url() {
	return admin_url( 'admin.php?page=npmp_pmpro_import' );
}

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_pmpro_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import from Paid Memberships Pro', 'nonprofit-manager' ),
		__( 'Import from Paid Memberships Pro', 'nonprofit-manager' ),
		'manage_options',
		'npmp_pmpro_import',
		'npmp_pmpro_render_page'
	);
}

/**
 * Take the screen out of the menu on admin_head (removing it on admin_menu
 * makes WordPress refuse the screen).
 *
 * @return void
 */
function npmp_pmpro_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_pmpro_import' );
}

/**
 * The hub's styles and its carry-on script.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_pmpro_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_pmpro_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Handle Import and each carry-on request.
 *
 * @return void
 */
function npmp_pmpro_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	check_admin_referer( 'npmp_pmpro_import', 'npmp_pmpro_nonce' );
	if ( ! empty( $_POST['restart'] ) ) {
		$keep = isset( $_POST['keep'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['keep'] ) ) : array();
		npmp_pmpro_start( array_diff( npmp_pmpro_groups(), $keep ) );
	}
	npmp_pmpro_run();
	wp_safe_redirect( npmp_pmpro_url() );
	exit;
}

/**
 * Hidden fields for the forms.
 *
 * @return void
 */
function npmp_pmpro_form_fields() {
	echo '<input type="hidden" name="action" value="npmp_pmpro_import">';
	wp_nonce_field( 'npmp_pmpro_import', 'npmp_pmpro_nonce' );
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_pmpro_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	$state = npmp_pmpro_state();
	echo '<div class="wrap npmp-hub-wrap">';
	echo '<h1>' . esc_html__( 'Import from Paid Memberships Pro', 'nonprofit-manager' ) . '</h1>';
	echo '<hr class="wp-header-end">';
	echo '<p><a href="' . esc_url( npmp_import_hub_url( 'pmpro' ) ) . '">&larr; ' . esc_html__( 'Back to Switch Plugins', 'nonprofit-manager' ) . '</a></p>';

	if ( ! npmp_pmpro_has_tables() ) {
		echo '<p>' . esc_html__( 'You need Paid Memberships Pro\'s membership tables to import, and this site doesn\'t have those tables.', 'nonprofit-manager' ) . '</p></div>';
		return;
	}

	if ( $state['running'] ) {
		npmp_pmpro_render_results( $state['results'] );
		echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
		npmp_pmpro_form_fields();
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form></div>';
		return;
	}

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
		npmp_pmpro_render_results( $state['results'] );
		echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( 'pmpro' ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a></p>';
	}

	$counts    = npmp_pmpro_counts();
	$levels    = npmp_pmpro_levels();
	$recurring = npmp_pmpro_recurring_count();
	$labels    = array(
		'current' => __( 'Current members (they come over as Active and get your newsletters)', 'nonprofit-manager' ),
		'past'    => __( 'Past members (they come over as Inactive)', 'nonprofit-manager' ),
	);

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_pmpro_form_fields();
	echo '<input type="hidden" name="restart" value="1">';

	echo '<h2>' . esc_html__( 'Who comes over', 'nonprofit-manager' ) . '</h2><fieldset class="npmp-hub-checklist">';
	foreach ( $labels as $key => $label ) {
		printf(
			'<p><label><input type="checkbox" name="keep[]" value="%1$s"%2$s> %3$s: %4$s</label></p>',
			esc_attr( $key ),
			checked( ! in_array( $key, $state['skip'], true ), true, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $counts[ $key ] ) )
		);
	}
	echo '</fieldset>';
	echo '<ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Each member becomes a contact with their name, email, billing address, phone, and the date they first joined, or joins the contact you already have with that email.', 'nonprofit-manager' ) . '</li>';
	if ( $levels ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: level names. */
				__( 'Your levels become membership levels, and each current member gets their level: %s.', 'nonprofit-manager' ),
				implode( ', ', $levels )
			)
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'A contact who unsubscribed here stays unsubscribed, and a level you set by hand stays.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'No member gets an email, and their WordPress accounts stay as they are.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	if ( function_exists( 'npmp_is_pro' ) && ! npmp_is_pro() && function_exists( 'npmp_import_free_remaining' ) ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: free total of new contacts, 2: places left. */
				__( 'With the free plugin, you can add up to %1$s new contacts through imports, and you have %2$s places left. A member who\'s already a contact doesn\'t use a place.', 'nonprofit-manager' ),
				number_format_i18n( NPMP_IMPORT_FREE_MAX_ROWS ),
				number_format_i18n( npmp_import_free_remaining() )
			)
		) . '</p>';
	}

	if ( $recurring ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: %s: number of members. */
				_n(
					'%s current member pays on a plan that renews. This member keeps paying through your payment processor after you turn Paid Memberships Pro off, and you won\'t see those payments in Nonprofit Manager. Cancel or move the renewal at your payment processor before you switch.',
					'%s current members pay on a plan that renews. These members keep paying through your payment processor after you turn Paid Memberships Pro off, and you won\'t see those payments in Nonprofit Manager. Cancel or move each renewal at your payment processor before you switch.',
					$recurring,
					'nonprofit-manager'
				),
				number_format_i18n( $recurring )
			)
		) . '</p></div>';
	}

	echo '<h2>' . esc_html__( 'What you leave in the old plugin', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Orders, payment plans, discount codes, and level prices.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Payment gateway settings, license keys, and add-on settings.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Which pages and posts you kept for members only.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	$label = $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import members', 'nonprofit-manager' );
	echo '<p><button type="submit" class="button button-primary">' . esc_html( $label ) . '</button></p>';
	if ( $state['finished_at'] ) {
		echo '<p class="description">' . esc_html__( 'Running it again updates the contacts you have and adds anyone new.', 'nonprofit-manager' ) . '</p>';
	}
	echo '</form></div>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_pmpro_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of contacts. */
		sprintf( __( 'New contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['created'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts updated: %s', 'nonprofit-manager' ), number_format_i18n( $r['updated'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts with nothing to change: %s', 'nonprofit-manager' ), number_format_i18n( $r['unchanged'] ) ),
	);
	if ( $r['levels_added'] ) {
		/* translators: %s: level names. */
		$lines[] = sprintf( __( 'Membership levels added: %s', 'nonprofit-manager' ), implode( ', ', (array) $r['levels_added'] ) );
	}
	if ( $r['left'] ) {
		/* translators: %s: number of members. */
		$lines[] = sprintf( __( 'Members you left out: %s', 'nonprofit-manager' ), number_format_i18n( $r['left'] ) );
	}
	if ( $r['over_limit'] ) {
		/* translators: %s: number of members. */
		$lines[] = sprintf( __( 'Members past the free limit: %s. Pro brings them over.', 'nonprofit-manager' ), number_format_i18n( $r['over_limit'] ) );
	}
	if ( $r['kept_unsubscribed'] ) {
		/* translators: %s: number of contacts. */
		$lines[] = sprintf( __( 'Contacts who stay unsubscribed here: %s', 'nonprofit-manager' ), number_format_i18n( $r['kept_unsubscribed'] ) );
	}
	if ( $r['invalid'] ) {
		/* translators: %s: number of members. */
		$lines[] = sprintf( __( 'Members we skipped for a missing or broken email: %s', 'nonprofit-manager' ), number_format_i18n( $r['invalid'] ) );
	}
	echo '<ul class="npmp-hub-checklist">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	foreach ( (array) $r['failed'] as $fail ) {
		echo '<li>' . esc_html( $fail['email'] . ': ' . $fail['error'] ) . '</li>';
	}
	echo '</ul>';
}
