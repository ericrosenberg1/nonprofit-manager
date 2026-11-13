<?php
/**
 * File path: includes/import/ultimate-member-importer.php
 *
 * Importer and Switch Plugins source for Ultimate Member (folder
 * ultimate-member). Read from Ultimate Member 2.14.2's own source:
 *
 * - Members are WordPress users. Ultimate Member adds no table of its own.
 * - Roles: core roles live in the {prefix}user_roles option as usual. Ultimate
 *   Member's own roles are keys in the um_roles option, each with its name,
 *   capabilities, and settings in um_role_{key}_meta. It adds them to WordPress
 *   as um_{key} on every page load (wp_roles_init), so with the plugin off those
 *   roles exist only in each user's {prefix}capabilities meta. The importer
 *   reads that meta, never get_users( role ), so it works with the plugin off.
 * - Account status is user meta account_status: approved,
 *   awaiting_email_confirmation, awaiting_admin_review, inactive, or rejected
 *   (common/class-users.php statuses_list(), filter um_user_statuses for more).
 *   A user with none is one Ultimate Member never saw. Its hourly job
 *   (common/actions/class-users.php) approves those.
 * - Its login check (core/um-filters-login.php) refuses every status except
 *   approved. Once the plugin is off, WordPress no longer checks, so those
 *   people can log in.
 * - Profile fields are user meta named after each field's metakey. The
 *   built-in ones we use are first_name, last_name, phone_number, mobile_number,
 *   and country. Fields made in its form builder are listed in the um_fields
 *   option.
 * - Deactivating only unschedules its jobs (class-init.php deactivation()).
 *   uninstall.php deletes nothing unless "Remove Data on Uninstall?" (um_options
 *   uninstall_on_delete) is on. Then it takes Ultimate Member's roles off every
 *   user, deletes every user meta key starting with um, account_status,
 *   submitted, full_name, and profile_photo, its forms, directories, and roles,
 *   and every option starting with um_.
 *
 * Who comes over is up to you: the Review step lists every role with its
 * count, ticks Ultimate Member's own roles and the roles its registration forms
 * give, and leaves staff roles (anyone who can edit posts or manage the site)
 * unticked. A person comes over when they hold a role you ticked.
 *
 * Never read or changed: passwords, the WordPress users themselves, the
 * submitted registration copy, or any Ultimate Member setting except the role
 * names, the registration roles, Global Site Access, and the uninstall
 * setting. No email goes out.
 *
 * Data removal stays unavailable: its data sits in user meta and options with
 * generic names (account_status, submitted, full_name) that other plugins can
 * use too, and its paid extensions share the um_ prefix.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_UM_BATCH' ) ) {
	define( 'NPMP_UM_BATCH', 200 );
}
if ( ! defined( 'NPMP_UM_SECONDS' ) ) {
	define( 'NPMP_UM_SECONDS', 20 );
}

// =====================================================================
// Pure helpers, covered by tests/test-ultimate-member-importer.php
// =====================================================================

/**
 * Ultimate Member's account statuses, plus none (no status saved) and other
 * (a status an extension added), in the order the screens show them.
 *
 * @return string[]
 */
function npmp_um_status_keys() {
	return array( 'approved', 'none', 'awaiting_email_confirmation', 'awaiting_admin_review', 'inactive', 'rejected', 'other' );
}

/**
 * Normalise a stored account_status to one of npmp_um_status_keys().
 *
 * @param mixed $raw account_status user meta.
 * @return string
 */
function npmp_um_status_key( $raw ) {
	$raw = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
	if ( '' === $raw ) {
		return 'none';
	}
	return in_array( $raw, array( 'approved', 'awaiting_email_confirmation', 'awaiting_admin_review', 'inactive', 'rejected' ), true ) ? $raw : 'other';
}

/**
 * The contact status an account status becomes.
 *
 * Only an approved member (or one with no status, which Ultimate Member
 * approves) can become Active, and only when you say they agreed to your
 * newsletters. Otherwise they come over as Inactive: on file, and no
 * newsletter reaches them. Someone waiting on their confirmation email or on
 * your review waits as Pending. A deactivated account comes over as Inactive.
 * A rejected application comes over as Unsubscribed, so no newsletter or
 * automation ever reaches someone you turned down. A status this importer
 * doesn't know waits as Pending.
 *
 * @param string $key         From npmp_um_status_key().
 * @param bool   $newsletters You said approved members agreed to newsletters.
 * @return string subscribed|inactive|pending|unsubscribed
 */
function npmp_um_contact_status( $key, $newsletters = false ) {
	switch ( (string) $key ) {
		case 'approved':
		case 'none':
			return $newsletters ? 'subscribed' : 'inactive';
		case 'inactive':
			return 'inactive';
		case 'rejected':
			return 'unsubscribed';
		default:
			return 'pending';
	}
}

/**
 * The status an existing contact should change to, or '' to leave it.
 *
 * An unsubscribe here always stays. A contact someone else made keeps its
 * status, except that a Pending contact becomes Active when you said approved
 * members agreed to your newsletters. A contact this importer made follows
 * Ultimate Member on the next run: a rejected or deactivated account stops
 * getting email, a member back under review waits as Pending, and an approved
 * member becomes Active only when you said so again. Running again with the
 * safer choice never takes an Active contact off your newsletters.
 *
 * @param string $existing The contact's status.
 * @param string $source   The contact's source.
 * @param string $key      Account status key (npmp_um_status_key()).
 * @param string $incoming npmp_um_contact_status() for this run.
 * @return string
 */
function npmp_um_merge_status( $existing, $source, $key, $incoming ) {
	$existing = '' === (string) $existing ? 'subscribed' : (string) $existing;
	$incoming = (string) $incoming;
	if ( 'unsubscribed' === $existing || $existing === $incoming ) {
		return '';
	}
	if ( 'ultimate_member' !== (string) $source ) {
		return ( 'pending' === $existing && 'subscribed' === $incoming ) ? 'subscribed' : '';
	}
	switch ( (string) $key ) {
		case 'rejected':
		case 'inactive':
			return $incoming;
		case 'approved':
		case 'none':
			if ( 'subscribed' === $incoming ) {
				return in_array( $existing, array( 'pending', 'inactive' ), true ) ? 'subscribed' : '';
			}
			return 'pending' === $existing ? 'inactive' : '';
		default:
			return 'subscribed' === $existing ? 'pending' : '';
	}
}

/**
 * Role IDs a user holds, from their capabilities meta.
 *
 * @param mixed $caps Unserialized {prefix}capabilities.
 * @return string[]
 */
function npmp_um_caps_roles( $caps ) {
	$out = array();
	foreach ( is_array( $caps ) ? $caps : array() as $role => $on ) {
		if ( $on && is_string( $role ) && '' !== $role ) {
			$out[] = $role;
		}
	}
	return $out;
}

/**
 * Is this a staff role (someone who runs the site, not a member)?
 *
 * Anyone who can edit posts or pages, manage options, users, or a shop, or
 * the Membership Manager role, counts as staff.
 *
 * @param string $role_id Role ID.
 * @param array  $caps    Capability => granted.
 * @return bool
 */
function npmp_um_role_is_staff( $role_id, array $caps ) {
	if ( in_array( (string) $role_id, array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager', 'npmp_membership_manager' ), true ) ) {
		return true;
	}
	foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'list_users', 'promote_users', 'edit_users', 'manage_woocommerce', 'moderate_comments', 'npmp_manage_members' ) as $cap ) {
		if ( ! empty( $caps[ $cap ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Should the Review step tick this role to start with?
 *
 * Ultimate Member's own roles and the roles its registration forms give are
 * ticked. Staff roles never are.
 *
 * @param array $role is_um, registers, staff.
 * @return bool
 */
function npmp_um_default_ticked( array $role ) {
	if ( ! empty( $role['staff'] ) ) {
		return false;
	}
	return ! empty( $role['is_um'] ) || ! empty( $role['registers'] );
}

/**
 * Order roles for picking a level: Ultimate Member's roles by its own
 * priority (highest first), then the rest in the order given.
 *
 * @param array $roles Role ID => array with is_um and priority.
 * @return string[] Role IDs.
 */
function npmp_um_level_order( array $roles ) {
	$ids = array_keys( $roles );
	$pos = array_flip( $ids );
	usort(
		$ids,
		static function ( $a, $b ) use ( $roles, $pos ) {
			$ua = ! empty( $roles[ $a ]['is_um'] );
			$ub = ! empty( $roles[ $b ]['is_um'] );
			if ( $ua !== $ub ) {
				return $ua ? -1 : 1;
			}
			$pa = (int) ( $roles[ $a ]['priority'] ?? 0 );
			$pb = (int) ( $roles[ $b ]['priority'] ?? 0 );
			if ( $pa !== $pb ) {
				return $pb <=> $pa;
			}
			return $pos[ $a ] <=> $pos[ $b ];
		}
	);
	return $ids;
}

/**
 * The level for a person: the first of their ticked roles, in level order,
 * that you gave a level. '' when none.
 *
 * @param string[]             $user_roles Roles the person holds.
 * @param string[]             $order      Ticked roles in level order.
 * @param array<string,string> $levels     Role ID => level name ('' for none).
 * @return string
 */
function npmp_um_pick_level( array $user_roles, array $order, array $levels ) {
	$held = array_flip( $user_roles );
	foreach ( $order as $role ) {
		if ( isset( $held[ $role ] ) && '' !== trim( (string) ( $levels[ $role ] ?? '' ) ) ) {
			return trim( (string) $levels[ $role ] );
		}
	}
	return '';
}

/**
 * Does the person hold a role you ticked?
 *
 * @param string[] $user_roles Roles held.
 * @param string[] $ticked     Ticked roles.
 * @return bool
 */
function npmp_um_has_ticked_role( array $user_roles, array $ticked ) {
	return (bool) array_intersect( $user_roles, $ticked );
}

/**
 * A level name as typed on the Review step.
 *
 * @param mixed $raw Input.
 * @return string
 */
function npmp_um_clean_level( $raw ) {
	$raw = is_scalar( $raw ) ? trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $raw ) ) ) : '';
	return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 100 ) : substr( $raw, 0, 100 );
}

/**
 * The text that marks a role in serialized capabilities meta, as in
 * a:1:{s:9:"um_member";b:1;}.
 *
 * @param string $role Role ID.
 * @return string
 */
function npmp_um_role_needle( $role ) {
	return '"' . (string) $role . '";b:1;';
}

/**
 * Contact field => Ultimate Member meta key for the fields that have a home
 * on a contact. They fill only fields the contact has empty.
 *
 * @return array<string,string>
 */
function npmp_um_field_keys() {
	return array(
		'phone'   => 'phone_number',
		'mobile'  => 'mobile_number',
		'country' => 'country',
	);
}

/**
 * Custom profile fields from Ultimate Member's um_fields option: metakey =>
 * title. Leaves out the fields a contact already has, passwords, files,
 * images, and layout pieces.
 *
 * @param mixed $fields um_fields.
 * @return array<string,string>
 */
function npmp_um_profile_fields( $fields ) {
	$skip_keys  = array_merge( array( 'user_login', 'username', 'user_email', 'user_password', 'confirm_user_password', 'first_name', 'last_name', 'role_select', 'role_radio' ), array_values( npmp_um_field_keys() ) );
	$skip_types = array( 'password', 'file', 'image', 'row', 'block', 'shortcode', 'spacing', 'divider', 'group' );
	$out        = array();
	foreach ( is_array( $fields ) ? $fields : array() as $key => $field ) {
		$key   = is_string( $key ) ? $key : '';
		$field = is_array( $field ) ? $field : array();
		$meta  = isset( $field['metakey'] ) && is_string( $field['metakey'] ) ? $field['metakey'] : $key;
		$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
		if ( '' === $meta || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $meta ) || in_array( $meta, $skip_keys, true ) || in_array( $type, $skip_types, true ) || 0 === strpos( $meta, '_um_' ) ) {
			continue;
		}
		$title        = trim( (string) ( $field['title'] ?? $field['label'] ?? '' ) );
		$out[ $meta ] = '' !== $title ? $title : $meta;
	}
	return $out;
}

/**
 * What Ultimate Member pieces a post's content holds.
 *
 * @param string $content Post content.
 * @return string[] form (its forms, account, directory, or password blocks or
 *                  shortcodes), visibility (content it shows to some people),
 *                  link (its author profile link).
 */
function npmp_um_content_kinds( $content ) {
	$content = (string) $content;
	$kinds   = array();
	if ( preg_match( '/\[ultimatemember(_[a-z]+)?[\s\]]/i', $content ) || false !== strpos( $content, '<!-- wp:um-block/' ) ) {
		$kinds[] = 'form';
	}
	if ( preg_match( '/\[(um_loggedin|um_loggedout|um_show_content)[\s\]]/i', $content ) || preg_match( '/"um_is_restrict"\s*:\s*true/', $content ) ) {
		$kinds[] = 'visibility';
	}
	if ( preg_match( '/\[um_author_profile_link[\s\]]/i', $content ) ) {
		$kinds[] = 'link';
	}
	return $kinds;
}

/**
 * Does a saved um_content_restriction turn restriction on?
 *
 * @param mixed $restriction Unserialized meta.
 * @return bool
 */
function npmp_um_is_restricted( $restriction ) {
	return is_array( $restriction ) && ! empty( $restriction['_um_custom_access_settings'] );
}

// =====================================================================
// Reading Ultimate Member's data
// =====================================================================

/**
 * The capabilities meta key for this site.
 *
 * @return string
 */
function npmp_um_caps_key() {
	global $wpdb;
	return $wpdb->get_blog_prefix() . 'capabilities';
}

/**
 * Ultimate Member's settings array.
 *
 * @return array
 */
function npmp_um_settings() {
	$options = get_option( 'um_options', array() );
	return is_array( $options ) ? $options : array();
}

/**
 * Is there Ultimate Member data on this site?
 *
 * @return bool
 */
function npmp_um_has_data() {
	return false !== get_option( 'um_version', false ) || false !== get_option( 'um_options', false );
}

/**
 * Roles UM's registration forms give.
 *
 * @return string[]
 */
function npmp_um_register_roles() {
	global $wpdb;
	$settings = npmp_um_settings();
	$roles    = array();
	$default  = ! empty( $settings['register_role'] ) ? (string) $settings['register_role'] : (string) get_option( 'default_role', 'subscriber' );
	$roles[]  = $default;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only read of form settings.
	$rows = $wpdb->get_results( "SELECT p.ID, r.meta_value AS role, c.meta_value AS custom FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_um_mode' AND m.meta_value = 'register' LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_um_register_use_custom_settings' LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_um_register_role' WHERE p.post_type = 'um_form' AND p.post_status = 'publish' LIMIT 200" );
	foreach ( (array) $rows as $row ) {
		if ( ! empty( $row->custom ) && '' !== (string) $row->role && '0' !== (string) $row->role ) {
			$roles[] = (string) $row->role;
		}
	}
	return array_values( array_unique( array_filter( $roles ) ) );
}

/**
 * Every role on the site, Ultimate Member's own included, with what the
 * Review step needs. Counts come from each user's capabilities meta.
 *
 * @return array<string,array{name:string,is_um:bool,staff:bool,registers:bool,priority:int,count:int,ticked:bool}>
 */
function npmp_um_roles() {
	global $wpdb;
	$roles     = array();
	$core      = get_option( $wpdb->get_blog_prefix() . 'user_roles', array() );
	$um_keys   = get_option( 'um_roles', array() );
	$registers = array_flip( npmp_um_register_roles() );

	foreach ( is_array( $um_keys ) ? $um_keys : array() as $key ) {
		$key  = is_scalar( $key ) ? (string) $key : '';
		$meta = '' !== $key ? get_option( "um_role_{$key}_meta", array() ) : array();
		if ( '' === $key || ! is_array( $meta ) ) {
			continue;
		}
		$id           = 'um_' . $key;
		$roles[ $id ] = array(
			'name'     => trim( wp_strip_all_tags( (string) ( $meta['name'] ?? $key ) ) ),
			'is_um'    => true,
			'caps'     => (array) ( $meta['wp_capabilities'] ?? array() ),
			'priority' => (int) ( $meta['_um_priority'] ?? 0 ),
		);
	}
	foreach ( is_array( $core ) ? $core : array() as $id => $data ) {
		if ( isset( $roles[ $id ] ) || ! is_string( $id ) ) {
			continue;
		}
		$roles[ $id ] = array(
			'name'     => trim( wp_strip_all_tags( (string) ( $data['name'] ?? $id ) ) ),
			'is_um'    => false,
			'caps'     => (array) ( $data['capabilities'] ?? array() ),
			'priority' => 0,
		);
	}

	$counts = npmp_um_role_counts( array_keys( $roles ) );
	foreach ( $roles as $id => $role ) {
		$role['staff']     = npmp_um_role_is_staff( $id, $role['caps'] );
		$role['registers'] = isset( $registers[ $id ] );
		$role['count']     = (int) ( $counts[ $id ] ?? 0 );
		$role['ticked']    = npmp_um_default_ticked( $role );
		unset( $role['caps'] );
		$roles[ $id ] = $role;
	}
	return $roles;
}

/**
 * Users per role, in one query.
 *
 * @param string[] $ids Role IDs.
 * @return array<string,int>
 */
function npmp_um_role_counts( array $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	$cols = array();
	$args = array();
	foreach ( array_values( $ids ) as $i => $id ) {
		$cols[] = "SUM(CASE WHEN c.meta_value LIKE %s THEN 1 ELSE 0 END) AS r{$i}";
		$args[] = '%' . $wpdb->esc_like( npmp_um_role_needle( $id ) ) . '%';
	}
	$args[] = npmp_um_caps_key();
	$sql    = 'SELECT ' . implode( ', ', $cols ) . " FROM {$wpdb->usermeta} c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.meta_key = %s";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders.
	$row = (array) $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
	$out = array();
	foreach ( array_values( $ids ) as $i => $id ) {
		$out[ $id ] = (int) ( $row[ "r{$i}" ] ?? 0 );
	}
	return $out;
}

/**
 * A WHERE fragment and its arguments matching users who hold any of these
 * roles. Reads the capabilities row aliased c.
 *
 * @param string[] $roles Role IDs.
 * @return array{0:string,1:array}
 */
function npmp_um_roles_where( array $roles ) {
	global $wpdb;
	$like = array();
	$args = array();
	foreach ( array_values( array_unique( $roles ) ) as $role ) {
		$like[] = 'c.meta_value LIKE %s';
		$args[] = '%' . $wpdb->esc_like( npmp_um_role_needle( $role ) ) . '%';
	}
	return array( $like ? '( ' . implode( ' OR ', $like ) . ' )' : '1 = 0', $args );
}

/**
 * Users who hold none of the site's roles (a role left by a plugin you
 * removed, or no role at all).
 *
 * @param string[] $roles Every role ID.
 * @return int
 */
function npmp_um_count_other_users( array $roles ) {
	global $wpdb;
	list( $where, $args ) = npmp_um_roles_where( $roles );
	array_unshift( $args, npmp_um_caps_key() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.meta_key = %s AND NOT {$where}", $args ) );
}

/**
 * People in these roles by account status.
 *
 * @param string[] $roles Role IDs.
 * @return array<string,int> Every key of npmp_um_status_keys(), plus total.
 */
function npmp_um_status_counts( array $roles ) {
	global $wpdb;
	$out = array_fill_keys( npmp_um_status_keys(), 0 );
	$out['total'] = 0;
	if ( ! $roles ) {
		return $out;
	}
	list( $where, $args ) = npmp_um_roles_where( $roles );
	array_unshift( $args, npmp_um_caps_key() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(s.meta_value, '') AS st, COUNT(DISTINCT c.user_id) AS n FROM {$wpdb->usermeta} c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id LEFT JOIN {$wpdb->usermeta} s ON s.user_id = c.user_id AND s.meta_key = 'account_status' WHERE c.meta_key = %s AND {$where} GROUP BY st", $args ), ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$key          = npmp_um_status_key( $row['st'] );
		$out[ $key ] += (int) $row['n'];
		$out['total'] += (int) $row['n'];
	}
	return $out;
}

/**
 * One batch of people in the ticked roles, lowest user ID first.
 *
 * @param int   $after Last user ID read.
 * @param int   $limit People.
 * @param array $plan  From npmp_um_state()['plan'].
 * @return object[] id, email, first_name, last_name, status_key, roles, level, created, fields, profile.
 */
function npmp_um_read_rows( $after, $limit, array $plan ) {
	global $wpdb;
	$ticked = (array) $plan['roles'];
	if ( ! $ticked ) {
		return array();
	}
	list( $where, $args ) = npmp_um_roles_where( $ticked );
	$args = array_merge( array( npmp_um_caps_key(), (int) $after ), $args, array( max( 1, (int) $limit ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders.
	$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT c.user_id FROM {$wpdb->usermeta} c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.meta_key = %s AND c.user_id > %d AND {$where} ORDER BY c.user_id ASC LIMIT %d", $args ) ) );
	if ( ! $ids ) {
		return array();
	}
	if ( function_exists( 'cache_users' ) ) {
		cache_users( $ids );
	}

	$caps_key = npmp_um_caps_key();
	$profile  = (array) ( $plan['profile_fields'] ?? array() );
	$utc      = new DateTimeZone( 'UTC' );
	$out      = array();
	foreach ( $ids as $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			continue;
		}
		$roles = npmp_um_caps_roles( get_user_meta( $user_id, $caps_key, true ) );
		if ( ! npmp_um_has_ticked_role( $roles, $ticked ) ) {
			// A LIKE match on a value WordPress didn't write. Skip it.
			continue;
		}
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );
		if ( '' === trim( $first . $last ) ) {
			$first = (string) get_user_meta( $user_id, 'full_name', true );
		}
		if ( '' === trim( $first . $last ) && (string) $user->display_name !== (string) $user->user_login ) {
			$first = (string) $user->display_name;
		}
		$fields = array();
		foreach ( npmp_um_field_keys() as $field => $meta_key ) {
			$value            = get_user_meta( $user_id, $meta_key, true );
			$fields[ $field ] = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';
		}
		$custom = array();
		foreach ( $profile as $meta_key => $title ) {
			$value = get_user_meta( $user_id, (string) $meta_key, true );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
			}
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$custom[ (string) $meta_key ] = trim( (string) $value );
			}
		}
		$out[] = (object) array(
			'id'         => $user_id,
			'email'      => (string) $user->user_email,
			'first_name' => $first,
			'last_name'  => $last,
			'status_key' => npmp_um_status_key( get_user_meta( $user_id, 'account_status', true ) ),
			'roles'      => $roles,
			'level'      => npmp_um_pick_level( $roles, (array) $plan['order'], (array) $plan['levels'] ),
			'created'    => npmp_pmpro_date_to_ts( $user->user_registered, $utc ),
			'fields'     => $fields,
			'profile'    => $custom,
		);
	}
	return $out;
}

/**
 * Published posts, pages, and templates that hold an Ultimate Member form,
 * shortcode, or block. Lists them only. Your content stays as it is.
 *
 * @param int $limit Most rows.
 * @return array<int,array{id:int,title:string,type:string,kinds:string[]}>
 */
function npmp_um_find_content_posts( $limit = 200 ) {
	global $wpdb;
	$types = array_values( array_diff( array_merge( get_post_types( array( 'public' => true ) ), array( 'wp_block', 'wp_template', 'wp_template_part' ) ), array( 'attachment' ) ) );
	if ( ! $types ) {
		return array();
	}
	$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$needles = array( '[ultimatemember', '[um_loggedin', '[um_loggedout', '[um_show_content', '[um_author_profile_link', '<!-- wp:um-block/', '"um_is_restrict":true' );
	$likes   = implode( ' OR ', array_fill( 0, count( $needles ), 'post_content LIKE %s' ) );
	$args    = $types;
	foreach ( $needles as $needle ) {
		$args[] = '%' . $wpdb->esc_like( $needle ) . '%';
	}
	$args[] = max( 1, (int) $limit );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ( {$likes} ) ORDER BY post_type ASC, post_title ASC LIMIT %d", $args ) );

	$out = array();
	foreach ( (array) $rows as $row ) {
		$kinds = npmp_um_content_kinds( $row->post_content );
		if ( $kinds ) {
			$out[] = array(
				'id'    => (int) $row->ID,
				'title' => '' !== trim( (string) $row->post_title ) ? (string) $row->post_title : '#' . (int) $row->ID,
				'type'  => (string) $row->post_type,
				'kinds' => $kinds,
			);
		}
	}
	return $out;
}

/**
 * Published posts and pages, categories and tags, and menu items Ultimate
 * Member shows to some people only.
 *
 * @return array{posts:int,terms:int,menu:int,site:bool}
 */
function npmp_um_restricted_counts() {
	global $wpdb;
	$on   = array( '%' . $wpdb->esc_like( '"_um_custom_access_settings";b:1' ) . '%', '%' . $wpdb->esc_like( '"_um_custom_access_settings";s:1:"1"' ) . '%' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only counts.
	$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'um_content_restriction' AND p.post_status = 'publish' AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )", $on[0], $on[1] ) );
	$terms = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key = 'um_content_restriction' AND ( meta_value LIKE %s OR meta_value LIKE %s )", $on[0], $on[1] ) );
	$menu  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'menu-item-um_nav_public' AND m.meta_value IN ('1','2') AND p.post_type = 'nav_menu_item'" );
	// phpcs:enable
	$settings = npmp_um_settings();
	return array(
		'posts' => $posts,
		'terms' => $terms,
		'menu'  => $menu,
		'site'  => 2 === (int) ( $settings['accessible'] ?? 0 ),
	);
}

// =====================================================================
// The import
// =====================================================================

/**
 * Empty results.
 *
 * @return array
 */
function npmp_um_empty_results() {
	return array(
		'created'           => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'invalid'           => 0,
		'over_limit'        => 0,
		'cap_reached'       => false,
		'kept_unsubscribed' => 0,
		'levels_added'      => array(),
		'by_status'         => array(),
		'failed'            => array(),
	);
}

/**
 * Saved plan and progress.
 *
 * @return array
 */
function npmp_um_state() {
	$state = get_option( 'npmp_um_import', array() );
	$state = is_array( $state ) ? $state : array();
	$plan  = array_merge(
		array(
			'reviewed'       => false,
			'roles'          => array(),
			'order'          => array(),
			'levels'         => array(),
			'newsletters'    => false,
			'profile_fields' => array(),
		),
		(array) ( $state['plan'] ?? array() )
	);
	return array(
		'plan'        => $plan,
		'cursor'      => (int) ( $state['cursor'] ?? 0 ),
		'running'     => ! empty( $state['running'] ),
		'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
		'handled'     => (int) ( $state['handled'] ?? 0 ),
		'results'     => array_merge( npmp_um_empty_results(), (array) ( $state['results'] ?? array() ) ),
	);
}

/**
 * Save progress.
 *
 * @param array $state State.
 * @return void
 */
function npmp_um_save_state( array $state ) {
	update_option( 'npmp_um_import', $state, false );
}

/**
 * Save the Review step: which roles come over and each one's level.
 *
 * @param string[]             $ticked Role IDs.
 * @param array<string,string> $levels Role ID => level name.
 * @return array State.
 */
function npmp_um_set_review( array $ticked, array $levels ) {
	$roles  = npmp_um_roles();
	$ticked = array_values( array_intersect( array_map( 'strval', $ticked ), array_keys( $roles ) ) );
	$clean  = array();
	foreach ( $ticked as $role ) {
		$clean[ $role ] = npmp_um_clean_level( $levels[ $role ] ?? '' );
	}
	$state                  = npmp_um_state();
	$state['plan']['roles'] = $ticked;
	$state['plan']['order'] = array_values( array_intersect( npmp_um_level_order( $roles ), $ticked ) );
	$state['plan']['levels']   = $clean;
	$state['plan']['reviewed'] = true;
	npmp_um_save_state( $state );
	return $state;
}

/**
 * Approve and start a run. Levels come over first.
 *
 * @param bool $newsletters Approved members agreed to your newsletters.
 * @return array State.
 */
function npmp_um_start( $newsletters ) {
	$state                           = npmp_um_state();
	$state['plan']['newsletters']    = (bool) $newsletters;
	$state['plan']['profile_fields'] = npmp_um_profile_fields( get_option( 'um_fields', array() ) );
	$state['cursor']                 = 0;
	$state['running']                = true;
	$state['handled']                = 0;
	$state['results']                = npmp_um_empty_results();

	$current = function_exists( 'npmp_get_membership_levels_array' ) ? npmp_get_membership_levels_array() : (array) get_option( 'npmp_membership_levels', array() );
	$merged  = npmp_pmpro_merge_levels( $current, array_values( array_filter( (array) $state['plan']['levels'], 'strlen' ) ) );
	if ( $merged['added'] ) {
		update_option( 'npmp_membership_levels', $merged['levels'] );
	}
	$state['results']['levels_added'] = $merged['added'];
	npmp_um_save_state( $state );
	return $state;
}

/**
 * Create or update one contact from a member, through the Paid Memberships
 * Pro writer.
 *
 * @param object     $row         Row from npmp_um_read_rows().
 * @param array|null $existing    array{id:int,status:string}, or null.
 * @param bool       $newsletters The Approve step's choice.
 * @param array      $labels      Profile field meta key => title.
 * @return array{result:string,member_id:int,message:string,kept_unsubscribed:bool,status:string}
 */
function npmp_um_import_member( $row, $existing, $newsletters, array $labels = array() ) {
	$incoming = npmp_um_contact_status( $row->status_key, $newsletters );
	$key      = $row->status_key;
	$writer   = (object) array(
		'id'         => (int) $row->id,
		'email'      => (string) $row->email,
		'first_name' => (string) $row->first_name,
		'last_name'  => (string) $row->last_name,
		'group'      => $key,
		'level'      => (string) $row->level,
		'created'    => (int) $row->created,
		'fields'     => (array) $row->fields,
	);
	$result = npmp_pmpro_import_member(
		$writer,
		$existing,
		array(
			'source'       => 'ultimate_member',
			'status'       => $incoming,
			'merge_status' => static function ( $member ) use ( $key, $incoming ) {
				return npmp_um_merge_status( $member->status ?? '', $member->source ?? '', $key, $incoming );
			},
			'raw'          => array(
				'account_status' => $key,
				'roles'          => (array) $row->roles,
				'profile_fields' => (array) $row->profile,
			),
		)
	);
	$result['status'] = $incoming;

	if ( $result['member_id'] && $row->profile ) {
		/**
		 * Custom profile fields from another plugin, for a contact an import
		 * just wrote. Free has no place for them. Pro's custom fields can take
		 * them here, and should return true from
		 * npmp_import_profile_fields_supported so the import screen says so.
		 *
		 * @param int    $member_id Contact post ID.
		 * @param array  $fields    Meta key => value (arrays joined with commas).
		 * @param string $source    Import source, ultimate_member here.
		 * @param array  $labels    Meta key => field title.
		 */
		do_action( 'npmp_import_profile_fields', (int) $result['member_id'], (array) $row->profile, 'ultimate_member', array_intersect_key( $labels, (array) $row->profile ) );
	}
	return $result;
}

/**
 * Can another plugin take custom profile fields?
 *
 * @return bool
 */
function npmp_um_profile_fields_supported() {
	return (bool) apply_filters( 'npmp_import_profile_fields_supported', false, 'ultimate_member' );
}

/**
 * Import batches until the members run out or the time is up.
 *
 * Free adds new contacts only while the site has places left under its total
 * (npmp_import_free_remaining()), and still updates contacts you already have.
 * Pro lifts the limit.
 *
 * @param int $seconds Time budget.
 * @param int $limit   People per batch.
 * @return array State after the run.
 */
function npmp_um_run( $seconds = NPMP_UM_SECONDS, $limit = NPMP_UM_BATCH ) {
	$state = npmp_um_state();
	if ( ! $state['running'] || ! class_exists( 'NPMP_Member_Manager' ) ) {
		return $state;
	}
	$max   = function_exists( 'npmp_import_max_rows' ) ? npmp_import_max_rows() : PHP_INT_MAX;
	$start = microtime( true );
	$done  = false;

	do {
		$rows     = npmp_um_read_rows( $state['cursor'], $limit, $state['plan'] );
		$existing = $rows ? npmp_mailpoet_find_contacts( wp_list_pluck( $rows, 'email' ) ) : array();
		foreach ( $rows as $row ) {
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
			$result = npmp_um_import_member( $row, $known, ! empty( $state['plan']['newsletters'] ), (array) $state['plan']['profile_fields'] );
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
				if ( 'created' === $result['result'] ) {
					$state['results']['by_status'][ $result['status'] ] = (int) ( $state['results']['by_status'][ $result['status'] ] ?? 0 ) + 1;
					// A contact this run made can't come up again in this run.
					$existing[ $email ] = array(
						'id'     => (int) $result['member_id'],
						'status' => $result['status'],
					);
				}
				if ( $result['kept_unsubscribed'] ) {
					++$state['results']['kept_unsubscribed'];
				}
			}
			$state['cursor'] = $row->id;
		}
		if ( count( $rows ) < $limit ) {
			$done = true;
		}
		npmp_um_save_state( $state );
	} while ( ! $done && ( microtime( true ) - $start ) < $seconds );

	if ( $done ) {
		$state['running']     = false;
		$state['finished_at'] = time();
		npmp_um_save_state( $state );
		update_option( 'npmp_um_imported', 1, true );
	}
	return $state;
}

/**
 * Contacts the importer made.
 *
 * @return int
 */
function npmp_um_count_contacts() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_source' AND pm.meta_value = 'ultimate_member' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
}

/**
 * People in the ticked roles whose email isn't on any contact.
 *
 * @param string[] $roles Ticked roles.
 * @return int
 */
function npmp_um_count_left_behind( array $roles ) {
	global $wpdb;
	if ( ! $roles ) {
		return 0;
	}
	// Two flat reads and a lookup in PHP. A correlated subquery on the
	// unindexed meta_value took a minute at 20,000 members.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only count.
	$known = $wpdb->get_col( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND p.post_type = 'npmp_contact' AND p.post_status = 'publish'" );
	$known = array_flip( array_map( 'strtolower', $known ) );
	list( $where, $args ) = npmp_um_roles_where( $roles );
	array_unshift( $args, npmp_um_caps_key() );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders.
	$emails = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT u.user_email FROM {$wpdb->usermeta} c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE c.meta_key = %s AND {$where}", $args ) );
	$left   = 0;
	foreach ( $emails as $email ) {
		if ( ! isset( $known[ strtolower( (string) $email ) ] ) ) {
			++$left;
		}
	}
	return $left;
}

/**
 * How each account status reads on screen.
 *
 * @return array<string,string>
 */
function npmp_um_status_labels() {
	return array(
		'approved'                    => __( 'Approved', 'nonprofit-manager' ),
		'none'                        => __( 'No account status (Ultimate Member approves these on its own)', 'nonprofit-manager' ),
		'awaiting_email_confirmation' => __( 'Waiting to confirm their email', 'nonprofit-manager' ),
		'awaiting_admin_review'       => __( 'Waiting for your review', 'nonprofit-manager' ),
		'inactive'                    => __( 'Deactivated', 'nonprofit-manager' ),
		'rejected'                    => __( 'Rejected', 'nonprofit-manager' ),
		'other'                       => __( 'Another status from an Ultimate Member extension', 'nonprofit-manager' ),
	);
}

/**
 * The contact status names.
 *
 * @param string $status Contact status.
 * @return string
 */
function npmp_um_contact_status_label( $status ) {
	$labels = array(
		'subscribed'   => __( 'Active', 'nonprofit-manager' ),
		'pending'      => __( 'Pending', 'nonprofit-manager' ),
		'inactive'     => __( 'Inactive', 'nonprofit-manager' ),
		'unsubscribed' => __( 'Unsubscribed', 'nonprofit-manager' ),
	);
	return $labels[ $status ] ?? $status;
}

// =====================================================================
// Switch Plugins source
// =====================================================================

add_filter( 'npmp_import_sources', 'npmp_um_register_hub_source' );

/**
 * Ultimate Member and its installed extensions, basename => name.
 *
 * @return array<string,string>
 */
function npmp_um_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$out = array();
	foreach ( get_plugins() as $file => $data ) {
		$requires = array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) );
		$folder   = (string) strtok( $file, '/' );
		$name     = (string) ( $data['Name'] ?? '' );
		if ( 'ultimate-member/ultimate-member.php' === $file || in_array( 'ultimate-member', $requires, true ) || ( 0 === strpos( $folder, 'um-' ) && false !== stripos( $name, 'Ultimate Member' ) ) ) {
			$out[ $file ] = $name;
		}
	}
	return $out;
}

/**
 * Add Ultimate Member to Switch Plugins.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_um_register_hub_source( $sources ) {
	$sources['ultimate_member'] = array(
		'name'              => 'Ultimate Member',
		'covers'            => __( 'Members by role, account status, join dates, phone numbers, and countries', 'nonprofit-manager' ),
		'feature'           => __( 'Contacts and membership levels', 'nonprofit-manager' ),
		'tier'              => 'free',
		'pro_parts'         => array(),
		'plugin_folders'    => array( 'ultimate-member' ),
		'has_data'          => 'npmp_um_has_data',
		'is_imported'       => function () {
			return (bool) get_option( 'npmp_um_imported', 0 );
		},
		'can_import'        => function () {
			return npmp_um_has_data() ? true : __( 'This site has no Ultimate Member data to read. Install Ultimate Member, turn it on once, and come back to this screen.', 'nonprofit-manager' );
		},
		'import_url'        => 'npmp_um_url',
		'import_summary'    => function () {
			$made = npmp_um_count_contacts();
			if ( ! $made ) {
				return __( 'You brought over your members\' details and membership levels.', 'nonprofit-manager' );
			}
			return sprintf(
				/* translators: %s: number of contacts. */
				_n( 'You have %s new contact from Ultimate Member.', 'You have %s new contacts from Ultimate Member.', $made, 'nonprofit-manager' ),
				number_format_i18n( $made )
			);
		},
		'counts'            => 'npmp_um_hub_counts',
		'checklist'         => array(
			__( 'Open a few of your new contacts and check their names, emails, statuses, levels, and join dates.', 'nonprofit-manager' ),
			__( 'Put a new signup form on every page that had an Ultimate Member registration form, and pick a new login page.', 'nonprofit-manager' ),
			__( 'Check every page you kept for members only, and set up another way to protect each one before you turn Ultimate Member off.', 'nonprofit-manager' ),
			__( 'Delete the WordPress accounts of anyone you rejected or deactivated, or change their roles, if they shouldn\'t log in. Once Ultimate Member is off, anyone with an account can log in.', 'nonprofit-manager' ),
		),
		'installed_plugins' => 'npmp_um_installed_plugins',
		'delete_note'       => 'npmp_um_delete_note',
		'data_removal'      => array(
			'reason' => __( 'Ultimate Member keeps its data in your users\' profiles and in settings with common names, like account_status, that other plugins can use too, and its extensions share its um_ names. We can\'t tell its data apart safely, so we leave it in your database. Your WordPress users keep working either way.', 'nonprofit-manager' ),
		),
	);
	return $sources;
}

/**
 * A warning for step 4 when Ultimate Member's uninstall setting is on.
 *
 * @return string
 */
function npmp_um_delete_note() {
	$settings = npmp_um_settings();
	if ( empty( $settings['uninstall_on_delete'] ) ) {
		return '';
	}
	return __( 'Ultimate Member\'s "Remove Data on Uninstall?" setting is on, so deleting the plugin also takes its roles off your users, deletes their account statuses, profile photos, and every profile detail whose name starts with um, and deletes its forms, member directories, and settings. Your WordPress users stay. To keep the rest, turn that setting off under Ultimate Member, Settings, Advanced first.', 'nonprofit-manager' );
}

/**
 * Old and new counts for the Verify step.
 *
 * @return array[]
 */
function npmp_um_hub_counts() {
	$state  = npmp_um_state();
	$ticked = (array) $state['plan']['roles'];
	$counts = npmp_um_status_counts( $ticked );
	$left   = npmp_um_count_left_behind( $ticked );
	$capped = function_exists( 'npmp_import_is_capped' ) ? npmp_import_is_capped() : false;
	$places = function_exists( 'npmp_import_free_remaining' ) ? npmp_import_free_remaining() : PHP_INT_MAX;
	$note   = __( 'People in roles you didn\'t tick stay WordPress users only.', 'nonprofit-manager' );
	if ( $left && $capped && $places < $left ) {
		$note = sprintf(
			/* translators: %s: free import limit, such as 100. */
			__( 'In the free version you can import %s contacts in all, so the rest are still only WordPress users.', 'nonprofit-manager' ),
			number_format_i18n( defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ? NPMP_IMPORT_FREE_MAX_ROWS : 100 )
		);
	}
	$rows = array(
		array(
			'label' => __( 'Members in the roles you picked', 'nonprofit-manager' ),
			'old'   => $counts['total'],
			'new'   => npmp_um_count_contacts(),
			'note'  => __( 'Someone who was already your contact keeps where they first came from, so they don\'t count here.', 'nonprofit-manager' ),
		),
		array(
			'label' => __( 'Members not in your contacts', 'nonprofit-manager' ),
			'old'   => $left,
			'new'   => null,
			'note'  => $left ? $note : '',
		),
	);
	$labels = npmp_um_status_labels();
	foreach ( npmp_um_status_keys() as $key ) {
		if ( empty( $counts[ $key ] ) ) {
			continue;
		}
		$rows[] = array(
			'label' => $labels[ $key ],
			'old'   => $counts[ $key ],
			'new'   => null,
			'note'  => sprintf(
				/* translators: %s: contact status, such as Pending. */
				__( 'New contacts came over as %s.', 'nonprofit-manager' ),
				npmp_um_contact_status_label( npmp_um_contact_status( $key, ! empty( $state['plan']['newsletters'] ) ) )
			),
		);
	}
	$levels = array_values( array_filter( (array) $state['plan']['levels'], 'strlen' ) );
	if ( $levels ) {
		$here = array();
		foreach ( function_exists( 'npmp_get_membership_levels_array' ) ? npmp_get_membership_levels_array() : array() as $level ) {
			$here[ strtolower( trim( (string) $level ) ) ] = true;
		}
		$found = 0;
		foreach ( array_unique( $levels ) as $level ) {
			$found += isset( $here[ strtolower( $level ) ] ) ? 1 : 0;
		}
		$rows[] = array(
			'label' => __( 'Membership levels from your roles', 'nonprofit-manager' ),
			'old'   => count( array_unique( $levels ) ),
			'new'   => $found,
			'note'  => '',
		);
	}
	$pages      = count( npmp_um_find_content_posts() );
	$restricted = npmp_um_restricted_counts();
	$rows[]     = array(
		'label' => __( 'Pages with an Ultimate Member form, shortcode, or block', 'nonprofit-manager' ),
		'old'   => $pages,
		'new'   => null,
		'note'  => $pages ? __( 'Once you turn Ultimate Member off, visitors see its shortcodes as plain text and see nothing where its blocks were.', 'nonprofit-manager' ) : '',
	);
	$rows[]     = array(
		'label' => __( 'Posts and pages kept for some visitors only', 'nonprofit-manager' ),
		'old'   => $restricted['posts'],
		'new'   => null,
		'note'  => $restricted['posts'] ? __( 'Once Ultimate Member is off, everyone can see these.', 'nonprofit-manager' ) : '',
	);
	return $rows;
}

// =====================================================================
// Screen
// =====================================================================

add_action( 'admin_menu', 'npmp_um_register_menu', 30 );
add_action( 'admin_head', 'npmp_um_hide_menu' );
add_action( 'admin_post_npmp_um_review', 'npmp_um_post_review' );
add_action( 'admin_post_npmp_um_import', 'npmp_um_post_import' );
add_action( 'admin_enqueue_scripts', 'npmp_um_enqueue' );

/**
 * URL of the screen.
 *
 * @param string $step review, approve, or empty for where you are.
 * @return string
 */
function npmp_um_url( $step = '' ) {
	$url = admin_url( 'admin.php?page=npmp_um_import' );
	return '' !== (string) $step ? add_query_arg( 'step', sanitize_key( $step ), $url ) : $url;
}

/**
 * Register the screen. It opens from Switch Plugins.
 *
 * @return void
 */
function npmp_um_register_menu() {
	add_submenu_page(
		'npmp_main',
		__( 'Import from Ultimate Member', 'nonprofit-manager' ),
		__( 'Import from Ultimate Member', 'nonprofit-manager' ),
		'manage_options',
		'npmp_um_import',
		'npmp_um_render_page'
	);
}

/**
 * Take the screen out of the menu on admin_head.
 *
 * @return void
 */
function npmp_um_hide_menu() {
	remove_submenu_page( 'npmp_main', 'npmp_um_import' );
}

/**
 * The hub's styles and carry-on script, plus the step list's styles.
 *
 * @param string $hook Admin hook suffix.
 * @return void
 */
function npmp_um_enqueue( $hook ) {
	if ( false === strpos( (string) $hook, 'npmp_um_import' ) ) {
		return;
	}
	$base = plugin_dir_url( dirname( __DIR__ ) );
	$ver  = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( 'assets/js/npmp-import-hub.js' ) : '1';
	wp_enqueue_style( 'npmp-import-hub', $base . 'assets/css/npmp-import-hub.css', array(), $ver );
	wp_enqueue_style( 'npmp-tec-import', $base . 'assets/css/npmp-tec-import.css', array(), $ver );
	// The roles table keeps its level boxes inside a phone-width screen.
	wp_add_inline_style( 'npmp-import-hub', '.npmp-um-roles th label{display:flex;gap:6px;align-items:flex-start}.npmp-um-roles th input{margin-top:0}.npmp-um-roles input[type=text]{width:100%;max-width:25em;box-sizing:border-box}' );
	wp_enqueue_script( 'npmp-import-hub', $base . 'assets/js/npmp-import-hub.js', array(), $ver, true );
}

/**
 * Stop unless the user can import and the nonce checks out.
 *
 * @return void
 */
function npmp_um_check_request() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ), 403 );
	}
	check_admin_referer( 'npmp_um_import', 'npmp_um_nonce' );
}

/**
 * Save the Review step.
 *
 * @return void
 */
function npmp_um_post_review() {
	npmp_um_check_request();
	$ticked = isset( $_POST['roles'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['roles'] ) ) : array();
	$levels = array();
	$posted = isset( $_POST['levels'] ) && is_array( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is cleaned below.
	foreach ( $posted as $role => $level ) {
		$levels[ sanitize_text_field( (string) $role ) ] = sanitize_text_field( (string) $level );
	}
	if ( ! $ticked ) {
		wp_safe_redirect( add_query_arg( 'none', '1', npmp_um_url( 'review' ) ) );
		exit;
	}
	npmp_um_set_review( $ticked, $levels );
	wp_safe_redirect( npmp_um_url( 'approve' ) );
	exit;
}

/**
 * Approve, then each carry-on request.
 *
 * @return void
 */
function npmp_um_post_import() {
	npmp_um_check_request();
	$state = npmp_um_state();
	if ( ! empty( $_POST['restart'] ) ) {
		if ( ! $state['plan']['reviewed'] || ! $state['plan']['roles'] ) {
			wp_safe_redirect( npmp_um_url( 'review' ) );
			exit;
		}
		npmp_um_start( isset( $_POST['newsletters'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['newsletters'] ) ) );
	}
	npmp_um_run();
	wp_safe_redirect( npmp_um_url() );
	exit;
}

/**
 * Hidden fields for the forms.
 *
 * @param string $action admin-post action.
 * @return void
 */
function npmp_um_form_fields( $action ) {
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( 'npmp_um_import', 'npmp_um_nonce' );
}

/**
 * The screen.
 *
 * @return void
 */
function npmp_um_render_page() {
	npmp_verify_admin_access( 'manage_options' );
	$state = npmp_um_state();
	$asked = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

	if ( $state['running'] ) {
		$step = 'import';
	} elseif ( 'review' === $asked || ! $state['plan']['reviewed'] ) {
		$step = 'review';
	} elseif ( 'approve' === $asked || ! $state['finished_at'] ) {
		$step = 'approve';
	} else {
		$step = 'finish';
	}

	$labels = array(
		'review'  => __( 'Review', 'nonprofit-manager' ),
		'approve' => __( 'Approve', 'nonprofit-manager' ),
		'import'  => __( 'Import', 'nonprofit-manager' ),
		'finish'  => __( 'Before you turn it off', 'nonprofit-manager' ),
	);
	$keys   = array_keys( $labels );
	$at     = array_search( $step, $keys, true );

	echo '<div class="wrap npmp-hub-wrap npmp-tec-wrap npmp-um-wrap">';
	echo '<h1>' . esc_html__( 'Import from Ultimate Member', 'nonprofit-manager' ) . '</h1>';
	echo '<hr class="wp-header-end">';
	echo '<p><a href="' . esc_url( npmp_import_hub_url( 'ultimate_member' ) ) . '">&larr; ' . esc_html__( 'Back to Switch Plugins', 'nonprofit-manager' ) . '</a></p>';

	if ( ! npmp_um_has_data() ) {
		echo '<p>' . esc_html__( 'This site has no Ultimate Member data to read.', 'nonprofit-manager' ) . '</p></div>';
		return;
	}

	/* translators: 1: step number, 2: total steps, 3: step name. */
	echo '<p class="npmp-tec-progress-line">' . esc_html( sprintf( __( 'Step %1$d of %2$d: %3$s', 'nonprofit-manager' ), $at + 1, count( $keys ), $labels[ $step ] ) ) . '</p>';
	echo '<ol class="npmp-tec-steps">';
	foreach ( $keys as $n => $key ) {
		$class = $n === $at ? 'is-current' : ( $n < $at ? 'is-done' : '' );
		echo '<li class="' . esc_attr( $class ) . '">' . esc_html( $labels[ $key ] ) . '</li>';
	}
	echo '</ol>';

	switch ( $step ) {
		case 'review':
			npmp_um_render_review( $state );
			break;
		case 'approve':
			npmp_um_render_approve( $state );
			break;
		case 'import':
			npmp_um_render_results( $state['results'] );
			echo '<p>' . esc_html__( 'Importing in batches. Keep this page open.', 'nonprofit-manager' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-npmp-hub-continue="1">';
			npmp_um_form_fields( 'npmp_um_import' );
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Keep going', 'nonprofit-manager' ) . '</button></p></form>';
			break;
		default:
			npmp_um_render_finish( $state );
	}
	echo '</div>';
}

/**
 * Step 1: pick the roles and their levels.
 *
 * @param array $state State.
 * @return void
 */
function npmp_um_render_review( array $state ) {
	$roles  = npmp_um_roles();
	$picked = $state['plan']['reviewed'] ? array_flip( (array) $state['plan']['roles'] ) : null;
	$other  = npmp_um_count_other_users( array_keys( $roles ) );

	if ( isset( $_GET['none'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Tick at least one role so we know who your members are.', 'nonprofit-manager' ) . '</p></div>';
	}

	echo '<p>' . esc_html__( 'In Ultimate Member, every member is a WordPress user. Your site\'s users also include your staff, and maybe shop customers or authors. Tick the roles whose people are members. Someone comes over when they hold a role you ticked.', 'nonprofit-manager' ) . '</p>';
	echo '<p>' . esc_html__( 'We ticked Ultimate Member\'s own roles and the roles new members get when they register, and left staff roles unticked. Give each role a membership level, or leave the box empty for none.', 'nonprofit-manager' ) . '</p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_um_form_fields( 'npmp_um_review' );
	echo '<div class="npmp-hub-table-wrap"><table class="widefat striped npmp-hub-counts npmp-um-roles"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Bring over', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col" class="num">' . esc_html__( 'People', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Membership level', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $roles as $id => $role ) {
		$ticked = null === $picked ? $role['ticked'] : isset( $picked[ $id ] );
		$level  = $state['plan']['reviewed'] && isset( $state['plan']['levels'][ $id ] ) ? (string) $state['plan']['levels'][ $id ] : ( $role['is_um'] ? $role['name'] : '' );
		$tags   = array();
		if ( $role['is_um'] ) {
			$tags[] = __( 'Ultimate Member role', 'nonprofit-manager' );
		}
		if ( $role['registers'] ) {
			$tags[] = __( 'given at registration', 'nonprofit-manager' );
		}
		if ( $role['staff'] ) {
			$tags[] = __( 'staff', 'nonprofit-manager' );
		}
		$field = 'npmp-um-level-' . sanitize_html_class( $id );
		echo '<tr><th scope="row"><label><input type="checkbox" name="roles[]" value="' . esc_attr( $id ) . '"' . checked( $ticked, true, false ) . '> ' . esc_html( $role['name'] ) . '</label>';
		if ( $tags ) {
			echo '<br><span class="description">' . esc_html( implode( ', ', $tags ) ) . '</span>';
		}
		echo '</th><td class="num">' . esc_html( number_format_i18n( $role['count'] ) ) . '</td>';
		echo '<td><label class="screen-reader-text" for="' . esc_attr( $field ) . '">' . esc_html(
			sprintf(
				/* translators: %s: role name. */
				__( 'Membership level for %s', 'nonprofit-manager' ),
				$role['name']
			)
		) . '</label><input type="text" class="regular-text" id="' . esc_attr( $field ) . '" name="levels[' . esc_attr( $id ) . ']" value="' . esc_attr( $level ) . '" placeholder="' . esc_attr__( 'No level', 'nonprofit-manager' ) . '"></td></tr>';
	}
	echo '</tbody></table></div>';
	echo '<p class="description">' . esc_html__( 'Someone in several roles counts in each one, and gets the level of their highest Ultimate Member role.', 'nonprofit-manager' ) . '</p>';
	if ( $other ) {
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of users. */
				_n( '%s user has no role, or a role this site no longer has, so we leave that user out.', '%s users have no role, or a role this site no longer has, so we leave those users out.', $other, 'nonprofit-manager' ),
				number_format_i18n( $other )
			)
		) . '</p>';
	}
	echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Next: approve', 'nonprofit-manager' ) . '</button></p></form>';
}

/**
 * Step 2: what comes over, the newsletter choice, and what stays behind.
 *
 * @param array $state State.
 * @return void
 */
function npmp_um_render_approve( array $state ) {
	$roles  = npmp_um_roles();
	$ticked = array_values( array_intersect( (array) $state['plan']['roles'], array_keys( $roles ) ) );
	$counts = npmp_um_status_counts( $ticked );
	$labels = npmp_um_status_labels();
	$names  = array();
	foreach ( $ticked as $id ) {
		$names[] = $roles[ $id ]['name'];
	}

	if ( $state['finished_at'] ) {
		echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your last import is done. Running it again updates the contacts you have and adds anyone new.', 'nonprofit-manager' ) . '</p></div>';
	}

	echo '<h2>' . esc_html__( 'Who comes over', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html(
		sprintf(
			/* translators: 1: number of people, 2: role names. */
			_n( '%1$s person in these roles: %2$s.', '%1$s people in these roles: %2$s.', $counts['total'], 'nonprofit-manager' ),
			number_format_i18n( $counts['total'] ),
			implode( ', ', $names )
		)
	) . ' <a href="' . esc_url( npmp_um_url( 'review' ) ) . '">' . esc_html__( 'Change roles or levels', 'nonprofit-manager' ) . '</a></p>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	npmp_um_form_fields( 'npmp_um_import' );
	echo '<input type="hidden" name="restart" value="1">';

	echo '<fieldset class="npmp-um-consent"><legend><h2>' . esc_html__( 'Newsletters', 'nonprofit-manager' ) . '</h2></legend>';
	echo '<p>' . esc_html__( 'Joining your site isn\'t the same as asking for your newsletter. You send newsletters only to Active contacts.', 'nonprofit-manager' ) . '</p>';
	$yes = ! empty( $state['plan']['newsletters'] ) && $state['finished_at'];
	echo '<p><label><input type="radio" name="newsletters" value="no"' . checked( ! $yes, true, false ) . '> <strong>' . esc_html__( 'Bring approved members over as Inactive.', 'nonprofit-manager' ) . '</strong> ' . esc_html__( 'They\'re on file with their level, and they get no newsletters until you or they change their status. Choose this unless they agreed to get email from you.', 'nonprofit-manager' ) . '</label></p>';
	echo '<p><label><input type="radio" name="newsletters" value="yes"' . checked( $yes, true, false ) . '> <strong>' . esc_html__( 'Approved members agreed to get my newsletters. Bring them over as Active.', 'nonprofit-manager' ) . '</strong> ' . esc_html__( 'You send your next newsletter to every approved member.', 'nonprofit-manager' ) . '</label></p>';
	echo '</fieldset>';

	echo '<div class="npmp-hub-table-wrap"><table class="widefat striped npmp-hub-counts"><thead><tr><th scope="col">' . esc_html__( 'Ultimate Member status', 'nonprofit-manager' ) . '</th><th scope="col" class="num">' . esc_html__( 'People', 'nonprofit-manager' ) . '</th><th scope="col">' . esc_html__( 'New contacts come over as', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';
	foreach ( npmp_um_status_keys() as $key ) {
		if ( empty( $counts[ $key ] ) ) {
			continue;
		}
		$became = in_array( $key, array( 'approved', 'none' ), true )
			? __( 'Inactive, or Active if you chose that above', 'nonprofit-manager' )
			: npmp_um_contact_status_label( npmp_um_contact_status( $key, false ) );
		echo '<tr><th scope="row">' . esc_html( $labels[ $key ] ) . '</th><td class="num">' . esc_html( number_format_i18n( $counts[ $key ] ) ) . '</td><td>' . esc_html( $became ) . '</td></tr>';
	}
	echo '</tbody></table></div>';

	echo '<ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Each member becomes a contact with their name, email, and the day they registered, or joins the contact you already have with that email. Their phone, mobile, and country fill only empty fields.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Someone waiting to confirm their email or waiting for your review comes over as Pending, and Pending contacts get no newsletters. A deactivated member comes over as Inactive. A rejected member comes over as Unsubscribed, so you never send that person email.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'A contact who unsubscribed here stays unsubscribed, a status you set on a contact stays, and a level you set by hand stays.', 'nonprofit-manager' ) . '</li>';
	$levels = array_values( array_unique( array_filter( (array) $state['plan']['levels'], 'strlen' ) ) );
	if ( $levels ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: level names. */
				__( 'These become membership levels: %s.', 'nonprofit-manager' ),
				implode( ', ', $levels )
			)
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'We send no member an email, and we change nothing in their WordPress accounts.', 'nonprofit-manager' ) . '</li>';
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

	npmp_um_render_left_behind();

	echo '<p><button type="submit" class="button button-primary">' . esc_html( $state['finished_at'] ? __( 'Import again', 'nonprofit-manager' ) : __( 'Import members', 'nonprofit-manager' ) ) . '</button></p>';
	echo '</form>';
}

/**
 * What stays in Ultimate Member.
 *
 * @return void
 */
function npmp_um_render_left_behind() {
	$profile = npmp_um_profile_fields( get_option( 'um_fields', array() ) );
	echo '<h2>' . esc_html__( 'What we leave in WordPress', 'nonprofit-manager' ) . '</h2><ul class="npmp-hub-checklist">';
	echo '<li>' . esc_html__( 'Passwords and logins. We leave every WordPress account alone, and people keep logging in with the same password.', 'nonprofit-manager' ) . '</li>';
	if ( $profile ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: field names. */
				npmp_um_profile_fields_supported()
					? __( 'We copy your custom profile fields into your custom fields: %s.', 'nonprofit-manager' )
					: __( 'Your custom profile fields, which have no place on a contact: %s. They stay in your users\' profiles.', 'nonprofit-manager' ),
				implode( ', ', array_slice( array_values( $profile ), 0, 20 ) )
			)
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'Profile pages, profile photos, member directories, and account pages.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Which pages, posts, and menu items you kept for members only.', 'nonprofit-manager' ) . '</li>';
	echo '<li>' . esc_html__( 'Ultimate Member\'s settings, emails, and forms, and everything its extensions keep, like messages, followers, groups, and reviews.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';
}

/**
 * Step 4: results, and what changes when Ultimate Member turns off.
 *
 * @param array $state State.
 * @return void
 */
function npmp_um_render_finish( array $state ) {
	echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your import is done.', 'nonprofit-manager' ) . '</p></div>';
	npmp_um_render_results( $state['results'] );

	$restricted = npmp_um_restricted_counts();
	$posts      = npmp_um_find_content_posts();
	$waiting    = npmp_um_status_counts( array_keys( npmp_um_roles() ) );
	$locked     = $waiting['awaiting_email_confirmation'] + $waiting['awaiting_admin_review'] + $waiting['inactive'] + $waiting['rejected'];

	echo '<h2>' . esc_html__( 'Before you turn off Ultimate Member', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Turning it off deletes nothing, and you can turn it back on from Plugins. You\'ll see these changes the moment you turn it off:', 'nonprofit-manager' ) . '</p><ul class="npmp-hub-checklist">';
	if ( $restricted['site'] ) {
		echo '<li><strong>' . esc_html__( 'Your whole site is for logged-in users only (Global Site Access in Ultimate Member). Once it\'s off, anyone can see every page.', 'nonprofit-manager' ) . '</strong></li>';
	}
	if ( $restricted['posts'] || $restricted['terms'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: 1: number of posts and pages, 2: number of categories and tags. */
				__( 'You kept %1$s posts and pages and %2$s categories or tags for some visitors only. Once you turn it off, everyone can see that content, and visitors find the posts you hid from lists and search.', 'nonprofit-manager' ),
				number_format_i18n( $restricted['posts'] ),
				number_format_i18n( $restricted['terms'] )
			)
		) . '</li>';
	}
	if ( $restricted['menu'] ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of menu items. */
				_n( 'You show %s menu item to logged-in or logged-out visitors only. Once you turn it off, every visitor sees that item.', 'You show %s menu items to logged-in or logged-out visitors only. Once you turn it off, every visitor sees those items.', $restricted['menu'], 'nonprofit-manager' ),
				number_format_i18n( $restricted['menu'] )
			)
		) . '</li>';
	}
	if ( $locked ) {
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: number of users. */
				_n( '%s user is waiting for confirmation or review, deactivated, or rejected, and Ultimate Member keeps them from logging in. Once it\'s off, WordPress lets them log in like anyone else, so delete those accounts or change their roles first.', '%s users are waiting for confirmation or review, deactivated, or rejected, and Ultimate Member keeps them from logging in. Once it\'s off, WordPress lets them log in like anyone else, so delete those accounts or change their roles first.', $locked, 'nonprofit-manager' ),
				number_format_i18n( $locked )
			)
		) . '</li>';
	}
	echo '<li>' . esc_html__( 'People in an Ultimate Member role (not a WordPress one like Subscriber) can still log in, but their role disappears with the plugin, so give them a WordPress role if they need one.', 'nonprofit-manager' ) . '</li>';
	echo '</ul>';

	echo '<h3>' . esc_html__( 'Pages with Ultimate Member pieces', 'nonprofit-manager' ) . '</h3>';
	if ( ! $posts ) {
		echo '<p>' . esc_html__( 'None of your published pages has an Ultimate Member form, shortcode, or block.', 'nonprofit-manager' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'Once you turn Ultimate Member off, visitors see its shortcodes as plain text and nothing where its blocks were, and everyone sees content inside its "logged in" or "logged out" shortcodes or its restricted blocks. Swap each form for a Nonprofit Manager signup form, and move anything private.', 'nonprofit-manager' ) . '</p>';
		$kind_labels = array(
			'form'       => __( 'form, account page, or member directory', 'nonprofit-manager' ),
			'visibility' => __( 'content for some visitors only', 'nonprofit-manager' ),
			'link'       => __( 'profile link', 'nonprofit-manager' ),
		);
		echo '<ul class="npmp-hub-checklist">';
		foreach ( $posts as $post ) {
			$kinds = array();
			foreach ( $post['kinds'] as $kind ) {
				$kinds[] = $kind_labels[ $kind ] ?? $kind;
			}
			$edit = get_edit_post_link( $post['id'] );
			echo '<li>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $post['title'] ) . '</a>' : esc_html( $post['title'] ) ) . ' (' . esc_html( implode( ', ', $kinds ) ) . ')</li>';
		}
		echo '</ul>';
	}

	echo '<p><a class="button button-primary" href="' . esc_url( npmp_import_hub_url( 'ultimate_member' ) ) . '">' . esc_html__( 'Finish on Switch Plugins', 'nonprofit-manager' ) . '</a> <a class="button" href="' . esc_url( npmp_um_url( 'approve' ) ) . '">' . esc_html__( 'Import again', 'nonprofit-manager' ) . '</a></p>';
}

/**
 * Results of the last run.
 *
 * @param array $r Results.
 * @return void
 */
function npmp_um_render_results( array $r ) {
	$lines = array(
		/* translators: %s: number of contacts. */
		sprintf( __( 'New contacts: %s', 'nonprofit-manager' ), number_format_i18n( $r['created'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts updated: %s', 'nonprofit-manager' ), number_format_i18n( $r['updated'] ) ),
		/* translators: %s: number of contacts. */
		sprintf( __( 'Contacts with nothing to change: %s', 'nonprofit-manager' ), number_format_i18n( $r['unchanged'] ) ),
	);
	foreach ( (array) $r['by_status'] as $status => $n ) {
		/* translators: 1: contact status, 2: number of contacts. */
		$lines[] = sprintf( __( 'New contacts as %1$s: %2$s', 'nonprofit-manager' ), npmp_um_contact_status_label( (string) $status ), number_format_i18n( (int) $n ) );
	}
	if ( $r['levels_added'] ) {
		/* translators: %s: level names. */
		$lines[] = sprintf( __( 'Membership levels added: %s', 'nonprofit-manager' ), implode( ', ', (array) $r['levels_added'] ) );
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
