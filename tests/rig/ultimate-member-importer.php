<?php
/**
 * Real-WordPress run of the Ultimate Member importer on the real plugin:
 * register members through Ultimate Member's own registration action and move
 * them through every account status with its own API, add ordinary WordPress
 * users who must stay behind, then walk Review, Approve, and Import with a
 * narrow free total, check every contact, import again with room, with the
 * newsletter choice both ways, turn Ultimate Member off through Switch
 * Plugins, check what visitors see, and import once more with it off.
 *
 * Seeds (one domain, rig-um.example.org, roles named "Rig ..."):
 *   ann  Rig Member, approved, phone, mobile, country, and a custom field
 *   bob  Rig Member and Rig Board (higher priority), approved
 *   cat  Subscriber, waiting to confirm their email
 *   dan  Subscriber, confirmed and approved
 *   eve  Rig Applicant, waiting for review
 *   fay  Rig Applicant, rejected
 *   gus  Rig Member, deactivated
 *   hal  Rig Member, approved, already a contact who unsubscribed here, level set by hand
 *   ivy  Subscriber, approved, already a contact waiting as Pending (from a CSV)
 *   jon  Subscriber with no account status (a user from before Ultimate Member)
 *   kim  Rig Member, approved, registered 2023-03-01 20:00 UTC
 * And five who stay behind: an administrator, an editor, an author, a shop
 * customer, and a Rig Moderator (an Ultimate Member role that edits posts).
 *
 * Needs a throwaway WordPress with this plugin and Ultimate Member
 * (2.14.2 from wordpress.org verified) active, `wp` on PATH, and the site
 * served at its home URL for the visitor checks (php -S works):
 *   wp plugin activate ultimate-member
 *   wp eval-file tests/rig/ultimate-member-importer.php
 * Run it with Nonprofit Manager Pro active and inactive. With Pro, it also
 * makes a "member created" automation and checks no welcome gets queued.
 *
 * It deletes its users, contacts, roles, pages, and levels, turns Ultimate
 * Member back on, and puts your time zone back. Pass "bulk" to time 20,000
 * members instead (Pro active, or the free total stops it at 100). Set
 * RIG_STOP=review to leave the seeded site on the Approve step.
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
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
wp_set_current_user( (int) $admins[0]->ID );
$domain  = 'rig-um.example.org';
$um_file = 'ultimate-member/ultimate-member.php';
$pro_on  = is_plugin_active( 'nonprofit-manager-pro/nonprofit-manager-pro.php' );

$queued = function () {
	$n = 0;
	foreach ( (array) _get_cron_array() as $hooks ) {
		if ( isset( $hooks['npmp_send_automation_email'] ) ) {
			$n += count( $hooks['npmp_send_automation_email'] );
		}
	}
	return $n;
};

// ---------------------------------------------------------------------
// Child phases, each in its own process.
// ---------------------------------------------------------------------
if ( 'import' === $phase ) {
	$hook    = array();
	$profile = array();
	$mails   = 0;
	$before  = $queued();
	add_action( 'npmp_import_row_imported', function ( $id, $record, $raw, $x, $status ) use ( &$hook ) {
		$hook[ $record['email'] ] = array( $record['status'], $raw['account_status'] ?? null, $status, $raw['source_id'] ?? null, $raw['profile_fields'] ?? null );
	}, 5, 5 );
	add_action( 'npmp_import_profile_fields', function ( $id, $fields, $source, $labels ) use ( &$profile ) {
		$profile[] = array( $id, $fields, $source, $labels );
	}, 10, 4 );
	add_filter( 'pre_wp_mail', function () use ( &$mails ) {
		++$mails;
		return true;
	} );
	$plan = get_option( 'npmp_um_import', array() );
	npmp_um_start( 'yes' === getenv( 'RIG_NEWSLETTERS' ) );
	$state = npmp_um_run( 60, 3 );
	echo 'JSON ', wp_json_encode(
		array(
			'state'   => $state,
			'hook'    => $hook,
			'profile' => $profile,
			'mails'   => $mails,
			'queued'  => $queued() - $before,
			'left'    => npmp_import_free_remaining(),
			'active'  => is_plugin_active( 'ultimate-member/ultimate-member.php' ),
		)
	), "\n";
	return;
}

if ( 'screens' === $phase ) {
	// The import screen's four steps as the owner sees them.
	define( 'WP_ADMIN', true );
	$out   = array();
	$state = npmp_um_state();
	foreach ( array( 'review', 'approve', 'finish' ) as $step ) {
		ob_start();
		$fn = 'npmp_um_render_' . $step;
		$fn( $state );
		$out[ $step ] = (string) ob_get_clean();
	}
	echo 'JSON ', wp_json_encode( $out ), "\n";
	return;
}

if ( 'hub' === $phase ) {
	$sources  = npmp_import_sources();
	$src      = $sources['ultimate_member'] ?? array();
	$step4    = function () use ( $src ) {
		ob_start();
		npmp_import_hub_render_step_remove_plugin( $src, array(), 'next', true );
		return wp_strip_all_tags( (string) ob_get_clean() );
	};
	$opts     = get_option( 'um_options', array() );
	$saved    = $opts['uninstall_on_delete'] ?? null;
	$opts['uninstall_on_delete'] = 0;
	update_option( 'um_options', $opts );
	$step4_off = $step4();
	$opts['uninstall_on_delete'] = 1;
	update_option( 'um_options', $opts );
	$step4_on = $step4();
	$opts['uninstall_on_delete'] = $saved;
	update_option( 'um_options', $opts );
	$early   = npmp_import_hub_run_step( 'ultimate_member', 'deactivate' );
	$verify  = npmp_import_hub_run_step( 'ultimate_member', 'verify', array( 'verified' => 1 ) );
	$data    = npmp_import_hub_run_step( 'ultimate_member', 'remove_data', array( 'confirm' => 'delete' ) );
	$off     = npmp_import_hub_run_step( 'ultimate_member', 'deactivate' );
	echo 'JSON ', wp_json_encode(
		array(
			'registered' => isset( $sources['ultimate_member'] ),
			'has_batch'  => isset( $src['data_removal']['batch'] ),
			'reason'     => (string) ( $src['data_removal']['reason'] ?? '' ),
			'step4_off'  => $step4_off,
			'step4_on'   => $step4_on,
			'pro_parts'  => $src['pro_parts'] ?? array(),
			'tier'       => $src['tier'] ?? '',
			'installed'  => npmp_import_hub_call( $src['installed_plugins'] ?? null, array() ),
			'rows'       => npmp_import_hub_call( $src['counts'] ?? null, null ),
			'summary'    => npmp_import_hub_call( $src['import_summary'] ?? null, '' ),
			'early'      => is_wp_error( $early ) ? $early->get_error_code() : 'ok',
			'verify'     => is_wp_error( $verify ) ? $verify->get_error_code() : 'ok',
			'data'       => is_wp_error( $data ) ? $data->get_error_code() : 'ok',
			'deactivate' => is_wp_error( $off ) ? $off->get_error_code() : 'ok',
			'active'     => is_plugin_active( 'ultimate-member/ultimate-member.php' ),
		)
	), "\n";
	return;
}

if ( 'roles' === $phase ) {
	echo 'JSON ', wp_json_encode( array( 'roles' => npmp_um_roles(), 'active' => is_plugin_active( 'ultimate-member/ultimate-member.php' ) ) ), "\n";
	return;
}

$child = function ( $phase, $env = '', $before = '' ) {
	$cmd = sprintf(
		'%s wp --path=%s %s eval-file %s %s 2>&1',
		$env,
		escapeshellarg( untrailingslashit( ABSPATH ) ),
		'' !== $before ? '--exec=' . escapeshellarg( $before ) : '',
		escapeshellarg( __FILE__ ),
		escapeshellarg( $phase )
	);
	$out = array();
	exec( $cmd, $out );
	wp_cache_flush();
	foreach ( $out as $line ) {
		if ( 0 === strpos( $line, 'JSON ' ) ) {
			return json_decode( substr( $line, 5 ), true );
		}
	}
	return array( 'error' => implode( "\n", array_filter( $out, function ( $l ) {
		return false === stripos( $l, 'deprecated' );
	} ) ) );
};

$fetch = function ( $page_id ) {
	// With pretty permalinks WordPress answers ?page_id= with a 301 to the
	// page's own address, so follow it the way a browser does.
	$r = wp_remote_get( add_query_arg( 'page_id', (int) $page_id, home_url( '/' ) ), array( 'timeout' => 20, 'redirection' => 5, 'cookies' => array() ) );
	return is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : (string) wp_remote_retrieve_body( $r );
};

$clean = function () use ( $wpdb, $domain ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND ( meta_value LIKE %s OR meta_value LIKE %s )", '%@' . $wpdb->esc_like( $domain ), '%@bulk.' . $wpdb->esc_like( $domain ) ) ) as $old_id ) {
		wp_delete_post( (int) $old_id, true );
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s OR user_email LIKE %s", '%@' . $wpdb->esc_like( $domain ), '%@bulk.' . $wpdb->esc_like( $domain ) ) ) as $uid ) {
		wp_delete_user( (int) $uid );
	}
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'Rig UM' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	$keys = (array) get_option( 'um_roles', array() );
	foreach ( $keys as $i => $key ) {
		if ( 0 === strpos( (string) $key, 'rig-' ) ) {
			delete_option( "um_role_{$key}_meta" );
			unset( $keys[ $i ] );
		}
	}
	update_option( 'um_roles', array_values( $keys ) );
	$fields = (array) get_option( 'um_fields', array() );
	unset( $fields['rig_favorite_tree'] );
	update_option( 'um_fields', $fields );
	remove_role( 'customer_rig' );
	delete_option( 'npmp_um_import' );
	delete_option( 'npmp_um_imported' );
	$hub = get_option( 'npmp_import_hub', array() );
	unset( $hub['ultimate_member'] );
	update_option( 'npmp_import_hub', $hub );
	if ( class_exists( 'NPMP_Automation_Engine' ) ) {
		$all = get_option( NPMP_Automation_Engine::OPTION_KEY, array() );
		unset( $all['rig_um_welcome'] );
		update_option( NPMP_Automation_Engine::OPTION_KEY, $all );
	}
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( $hooks['npmp_send_automation_email'] ?? array() as $ev ) {
			wp_unschedule_event( $ts, 'npmp_send_automation_email', $ev['args'] );
		}
	}
};

// ---------------------------------------------------------------------
// 20,000 members, for timing.
// ---------------------------------------------------------------------
if ( 'bulk' === $phase ) {
	echo "Bulk: 20,000 members\n";
	$clean();
	$saved_levels = get_option( 'npmp_membership_levels' );
	$n     = (int) ( getenv( 'RIG_BULK' ) ?: 20000 );
	$caps  = $wpdb->get_blog_prefix() . 'capabilities';
	$start = microtime( true );
	$wpdb->query( 'START TRANSACTION' );
	for ( $i = 1; $i <= $n; $i++ ) {
		$wpdb->insert( $wpdb->users, array( 'user_login' => 'rigbulk' . $i, 'user_pass' => '', 'user_nicename' => 'rigbulk' . $i, 'user_email' => 'm' . $i . '@bulk.' . $domain, 'user_registered' => gmdate( 'Y-m-d H:i:s', 1600000000 + $i * 60 ), 'display_name' => 'Bulk ' . $i ) );
		$uid = (int) $wpdb->insert_id;
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %s), (%d, 'account_status', %s), (%d, 'first_name', %s)", $uid, $caps, serialize( array( 'subscriber' => true ) ), $uid, 0 === $i % 10 ? 'awaiting_email_confirmation' : 'approved', $uid, 'Bulk' . $i ) );
	}
	$wpdb->query( 'COMMIT' );
	printf( "  seeded %d users in %.1fs\n", $n, microtime( true ) - $start );
	npmp_um_set_review( array( 'subscriber' ), array( 'subscriber' => '' ) );
	$t0     = microtime( true );
	$counts = npmp_um_status_counts( array( 'subscriber' ) );
	$roles  = npmp_um_roles();
	printf( "  Review and Approve counts in %.2fs\n", microtime( true ) - $t0 );
	$ok( 'subscriber count', $n <= (int) $roles['subscriber']['count'], $roles['subscriber']['count'] );
	$t0       = microtime( true );
	$requests = 0;
	npmp_um_start( false );
	do {
		$state = npmp_um_run();
		++$requests;
		wp_cache_flush();
	} while ( $state['running'] && $requests < 500 );
	$took = microtime( true ) - $t0;
	printf( "  imported in %.1fs over %d requests of up to %ds, peak memory %.0f MB\n", $took, $requests, NPMP_UM_SECONDS, memory_get_peak_usage( true ) / 1048576 );
	$res = $state['results'];
	if ( $pro_on ) {
		$ok( 'every bulk member came over', $n <= (int) $res['created'] + (int) $res['updated'] + (int) $res['unchanged'], $res );
	} else {
		$ok( 'free stops at the total', 0 === npmp_import_free_remaining() && $res['over_limit'] > 0, $res );
	}
	$ok( 'one in ten waits as Pending', ! $pro_on || (int) ( $res['by_status']['pending'] ?? 0 ) === (int) floor( $n / 10 ), $res['by_status'] );
	$t0 = microtime( true );
	npmp_um_hub_counts();
	printf( "  Verify counts in %.2fs\n", microtime( true ) - $t0 );
	$clean();
	false === $saved_levels ? delete_option( 'npmp_membership_levels' ) : update_option( 'npmp_membership_levels', $saved_levels );
	printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
	return;
}

// ---------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------
echo "Setup\n";
$ok( 'Ultimate Member is active', is_plugin_active( $um_file ) );
printf( "  Pro is %s\n", $pro_on ? 'active' : 'inactive' );
$ok( 'Ultimate Member is on Switch Plugins', isset( npmp_import_sources()['ultimate_member'] ) );

$saved_tz     = get_option( 'timezone_string' );
$saved_offset = get_option( 'gmt_offset' );
$saved_levels = get_option( 'npmp_membership_levels' );
update_option( 'timezone_string', 'America/Los_Angeles' );
// Page loads spawn WP-Cron, and Ultimate Member's own hourly job approves
// every user with no account status (common/actions/class-users.php). Hold
// the cron lock while the rig runs so jon keeps no status.
$saved_cron_lock = get_transient( 'doing_cron' );
set_transient( 'doing_cron', sprintf( '%.22F', microtime( true ) + 500 ) );
$clean();
$base_levels = array_values( array_filter( (array) ( is_array( $saved_levels ) ? $saved_levels : array() ), function ( $l ) {
	return 0 !== stripos( (string) $l, 'Rig ' );
} ) );
update_option( 'npmp_membership_levels', $base_levels );

// No email leaves the site while seeding (Ultimate Member sends its own).
$seed_mail = function () {
	return false;
};
add_filter( 'pre_wp_mail', $seed_mail );

UM()->setup()->install_default_forms();
$form_id = (int) ( get_option( 'um_core_forms' )['register'] ?? 0 );
$ok( 'Ultimate Member has its registration form', $form_id > 0 && 'um_form' === get_post_type( $form_id ) );

// Roles, stored the way Ultimate Member's Add Role screen stores them
// (includes/admin/templates/role/role-edit.php).
$um_role = function ( $name, $priority, $status, array $caps = array( 'read' => true ) ) {
	$id    = sanitize_title( $name );
	$roles = (array) get_option( 'um_roles', array() );
	$roles[] = $id;
	update_option( 'um_roles', array_values( array_unique( $roles ) ) );
	update_option(
		"um_role_{$id}_meta",
		array(
			'name'            => $name,
			'_um_priority'    => $priority,
			'_um_status'      => $status,
			'_um_can_access_wpadmin' => 0,
			'wp_capabilities' => $caps,
		)
	);
	return 'um_' . $id;
};
$r_member    = $um_role( 'Rig Member', 1, 'approved' );
$r_board     = $um_role( 'Rig Board', 5, 'approved' );
$r_applicant = $um_role( 'Rig Applicant', 2, 'pending' );
$r_mod       = $um_role( 'Rig Moderator', 9, 'approved', array( 'read' => true, 'edit_posts' => true, 'moderate_comments' => true ) );
add_role( 'customer_rig', 'Rig Customer', array( 'read' => true ) );
$sub_meta = (array) get_option( 'um_role_subscriber_meta', array() );
$saved_sub_status = $sub_meta['_um_status'] ?? null;
$sub_meta['_um_status'] = 'checkmail';
update_option( 'um_role_subscriber_meta', $sub_meta );
unset( $GLOBALS['wp_roles'] );
wp_roles();
$ok( 'Ultimate Member adds the Rig roles to WordPress', null !== get_role( $r_member ) && null !== get_role( $r_applicant ) );

// A custom profile field from its form builder.
UM()->fields()->globally_update_field( 'rig_favorite_tree', array( 'title' => 'Favorite tree', 'metakey' => 'rig_favorite_tree', 'type' => 'text', 'label' => 'Favorite tree', 'public' => 1, 'editable' => 1 ) );

// Members through Ultimate Member's own registration action, the one its
// form posts to. With no form data it skips the redirect and the log-in.
// The nonce belongs to a browser form, so turn its check off the way its
// own docblock shows (class-register.php).
add_filter( 'um_register_allow_nonce_verification', '__return_false' );
$users    = array();
$register = function ( $key, $first, $last, $role, array $extra = array() ) use ( $domain, $form_id, &$users, $admins ) {
	$args = array_merge(
		array(
			'user_login'            => 'rigum_' . $key,
			'user_email'            => $key . '@' . $domain,
			'user_password'         => 'Rig-' . wp_generate_password( 16, false ) . '-9a',
			'first_name'            => $first,
			'last_name'             => $last,
			'role'                  => $role,
			'form_id'               => $form_id,
		),
		$extra
	);
	$args['confirm_user_password'] = $args['user_password'];
	$args['submitted']             = $args;
	UM()->form()->form_id          = $form_id;
	unset( UM()->form()->errors );
	// A form post fills this list from the form's fields (class-form.php).
	// These are the fields of a registration form that asks for them.
	UM()->form()->usermeta_whitelist = array( 'user_login', 'user_email', 'first_name', 'last_name', 'phone_number', 'mobile_number', 'country', 'rig_favorite_tree' );
	do_action( 'um_submit_form_register', $args, null );
	wp_set_current_user( (int) $admins[0]->ID );
	$user          = get_user_by( 'email', $key . '@' . $domain );
	$users[ $key ] = $user ? (int) $user->ID : 0;
	return $users[ $key ];
};
$um_users = UM()->common()->users();
$register( 'ann', 'Ann', 'Arbor', $r_member, array( 'phone_number' => '805-555-0101', 'mobile_number' => '805-555-0199', 'country' => 'United States', 'rig_favorite_tree' => 'Oak' ) );
$register( 'bob', 'Bob', 'Board', $r_member );
get_user_by( 'id', $users['bob'] )->add_role( $r_board );
$register( 'cat', 'Cat', 'Check', 'subscriber' );
$register( 'dan', 'Dan', 'Done', 'subscriber' );
$um_users->approve( $users['dan'], true );
$register( 'eve', 'Eve', 'Wait', $r_applicant );
$register( 'fay', 'Fay', 'No', $r_applicant );
$um_users->reject( $users['fay'] );
$register( 'gus', 'Gus', 'Gone', $r_member );
$um_users->deactivate( $users['gus'] );
$register( 'hal', 'Hal', 'Out', $r_member );
$register( 'ivy', 'Ivy', 'Vine', 'subscriber' );
$um_users->approve( $users['ivy'], true );
$register( 'kim', 'Kim', 'Kept', $r_member );
$wpdb->update( $wpdb->users, array( 'user_registered' => '2023-03-01 20:00:00' ), array( 'ID' => $users['kim'] ) );
clean_user_cache( $users['kim'] );
// jon predates Ultimate Member: a WordPress user with no account status.
$users['jon'] = wp_insert_user( array( 'user_login' => 'rigum_jon', 'user_email' => 'jon@' . $domain, 'user_pass' => wp_generate_password( 24 ), 'first_name' => 'Jon', 'last_name' => 'Early', 'role' => 'subscriber' ) );
$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $users['jon'], 'meta_key' => 'account_status' ) );
clean_user_cache( $users['jon'] );
// People who stay behind.
foreach ( array( 'adm' => 'administrator', 'edi' => 'editor', 'aut' => 'author', 'cus' => 'customer_rig', 'mod' => $r_mod ) as $key => $role ) {
	$users[ $key ] = wp_insert_user( array( 'user_login' => 'rigum_' . $key, 'user_email' => $key . '@' . $domain, 'user_pass' => wp_generate_password( 24 ), 'first_name' => ucfirst( $key ), 'role' => $role ) );
}
$ok( 'sixteen users', 16 === count( array_filter( $users ) ), $users );
$status = function ( $key ) use ( $users ) {
	return (string) get_user_meta( $users[ $key ], 'account_status', true );
};
$ok( 'ann approved', 'approved' === $status( 'ann' ), $status( 'ann' ) );
$ok( 'cat waits for her email', 'awaiting_email_confirmation' === $status( 'cat' ), $status( 'cat' ) );
$ok( 'dan approved after confirming', 'approved' === $status( 'dan' ), $status( 'dan' ) );
$ok( 'eve waits for review', 'awaiting_admin_review' === $status( 'eve' ), $status( 'eve' ) );
$ok( 'fay rejected', 'rejected' === $status( 'fay' ), $status( 'fay' ) );
$ok( 'gus deactivated', 'inactive' === $status( 'gus' ), $status( 'gus' ) );
$ok( 'jon has no status', '' === $status( 'jon' ) );
$ok( 'ann has her phone and custom field', '805-555-0101' === get_user_meta( $users['ann'], 'phone_number', true ) && 'Oak' === get_user_meta( $users['ann'], 'rig_favorite_tree', true ) );
$ok( 'bob holds two Rig roles', 2 === count( array_intersect( array( $r_member, $r_board ), get_userdata( $users['bob'] )->roles ) ) );
remove_filter( 'pre_wp_mail', $seed_mail );

$members = NPMP_Member_Manager::get_instance();
$ok( 'hal is a contact who unsubscribed, with a level set by hand', is_int( $members->add_member( array( 'name' => 'Hal Out', 'email' => 'hal@' . $domain, 'status' => 'unsubscribed', 'source' => 'manual', 'membership_level' => 'Hand Level' ) ) ) );
$ok( 'ivy is a contact waiting as Pending', is_int( $members->add_member( array( 'name' => '', 'email' => 'ivy@' . $domain, 'status' => 'pending', 'source' => 'csv' ) ) ) );

// Pages and posts with Ultimate Member pieces.
$join_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig UM Join', 'post_content' => "<p>Rig public text.</p>\n[ultimatemember form_id=\"{$form_id}\"]\n[um_loggedin]Rig secret for members[/um_loggedin]\n<!-- wp:um-block/um-forms {\"form_id\":\"{$form_id}\"} /-->" ) );
$private   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig UM Private', 'post_content' => '<p>Rig members-only page body.</p>' ) );
update_post_meta( $private, 'um_content_restriction', array( '_um_custom_access_settings' => '1', '_um_accessible' => '2', '_um_noaccess_action' => '0', '_um_restrict_by_custom_message' => '1', '_um_restrict_custom_message' => 'Rig please log in' ) );

// A user snapshot, to prove the import changes no user and no user meta.
$snapshot = function () use ( $wpdb, $domain ) {
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s ORDER BY ID", '%@' . $wpdb->esc_like( $domain ) ) );
	$in  = implode( ',', array_map( 'intval', $ids ) );
	$rows = array();
	foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$in}) ORDER BY ID", ARRAY_A ) as $u ) {
		$rows[ 'user ' . $u['ID'] ] = wp_json_encode( $u );
	}
	foreach ( $wpdb->get_results( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ({$in}) ORDER BY umeta_id", ARRAY_A ) as $m ) {
		$rows[ 'meta ' . $m['umeta_id'] . ' ' . $m['user_id'] . ' ' . $m['meta_key'] ] = $m['meta_value'];
	}
	return $rows;
};
$changed = function ( array $before ) use ( $snapshot ) {
	$after = $snapshot();
	return array_keys( array_merge( array_diff_assoc( $before, $after ), array_diff_assoc( $after, $before ) ) );
};
$users_before = $snapshot();

if ( $pro_on && class_exists( 'NPMP_Automation_Engine' ) ) {
	NPMP_Automation_Engine::get_instance()->save_automation( array( 'id' => 'rig_um_welcome', 'name' => 'Rig UM welcome', 'trigger_type' => 'member_created', 'conditions' => array(), 'email_template_id' => 0, 'custom_subject' => 'Welcome', 'custom_body' => 'Hi', 'delay_minutes' => 0, 'status' => 'active' ) );
	$before  = $queued();
	$control = $members->add_member( array( 'name' => 'Rig Control', 'email' => 'control@' . $domain, 'status' => 'subscribed' ) );
	$ok( 'control: a contact added by hand queues the welcome', $queued() === $before + 1, (string) ( $queued() - $before ) );
	wp_delete_post( (int) $control, true );
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( $hooks['npmp_send_automation_email'] ?? array() as $ev ) {
			wp_unschedule_event( $ts, 'npmp_send_automation_email', $ev['args'] );
		}
	}
}

// ---------------------------------------------------------------------
// Review.
// ---------------------------------------------------------------------
echo "Review\n";
$roles = npmp_um_roles();
$ok( 'Rig Member: 5 people, ticked', 5 === $roles[ $r_member ]['count'] && $roles[ $r_member ]['ticked'], $roles[ $r_member ] );
$ok( 'Rig Board: 1, ticked', 1 === $roles[ $r_board ]['count'] && $roles[ $r_board ]['ticked'], $roles[ $r_board ] );
$ok( 'Rig Applicant: 2, ticked', 2 === $roles[ $r_applicant ]['count'] && $roles[ $r_applicant ]['ticked'], $roles[ $r_applicant ] );
$ok( 'Rig Moderator is staff, unticked', 1 === $roles[ $r_mod ]['count'] && ! $roles[ $r_mod ]['ticked'] && $roles[ $r_mod ]['staff'], $roles[ $r_mod ] );
$ok( 'Subscriber gets registrations, ticked', $roles['subscriber']['ticked'] && $roles['subscriber']['registers'] && $roles['subscriber']['count'] >= 4, $roles['subscriber'] );
$ok( 'Rig Customer unticked', isset( $roles['customer_rig'] ) && ! $roles['customer_rig']['ticked'] && 1 === $roles['customer_rig']['count'], $roles['customer_rig'] ?? '' );
foreach ( array( 'administrator', 'editor', 'author' ) as $staff ) {
	$ok( "{$staff} unticked", ! $roles[ $staff ]['ticked'] );
}
$ticked = array_keys( array_filter( $roles, function ( $r ) {
	return $r['ticked'];
} ) );
$state  = npmp_um_set_review( $ticked, array( $r_member => 'Rig Member', $r_board => 'Rig Directors', $r_applicant => '', 'subscriber' => '' ) );
$ok( 'the plan holds the ticked roles', array_values( $ticked ) === $state['plan']['roles'], $state['plan'] );
$ok( 'Board comes first for levels', $r_board === ( $state['plan']['order'][0] ?? '' ), $state['plan']['order'] );
$subs_outside = (int) $roles['subscriber']['count'] - 4;
$ok( 'no subscribers outside the rig', 0 === $subs_outside, (string) $subs_outside );
$counts = npmp_um_status_counts( $ticked );
$ok( 'status counts for the ticked roles', 6 === $counts['approved'] && 1 === $counts['awaiting_email_confirmation'] && 1 === $counts['awaiting_admin_review'] && 1 === $counts['rejected'] && 1 === $counts['inactive'] && 1 === $counts['none'] && 11 === $counts['total'], $counts );

$sc = $child( 'screens' );
$ok( 'Review screen lists Rig Moderator unticked', false !== strpos( (string) ( $sc['review'] ?? '' ), 'value="' . $r_mod . '">' ), substr( (string) ( $sc['review'] ?? wp_json_encode( $sc ) ), 0, 300 ) );
$ok( 'Approve screen defaults to Inactive', (bool) preg_match( '/name="newsletters" value="no" checked/', (string) ( $sc['approve'] ?? '' ) ) && ! preg_match( '/name="newsletters" value="yes" checked/', (string) ( $sc['approve'] ?? '' ) ) );
$ok( 'Approve screen names the custom field that stays', false !== strpos( (string) ( $sc['approve'] ?? '' ), 'Favorite tree' ) );

// RIG_STOP=review leaves the seeded site on the Approve step, for a look in
// a browser. Run the rig again to clean up.
if ( 'review' === getenv( 'RIG_STOP' ) ) {
	echo "Stopped after Review. Run the rig again to clean up.\n";
	return;
}

// ---------------------------------------------------------------------
// First run, free with four places (Pro lifts the total).
// ---------------------------------------------------------------------
$used = npmp_import_free_used( true );
echo $pro_on ? "First run (Pro)\n" : "First run (4 places left)\n";
$r   = $child( 'import', '', sprintf( 'define( "NPMP_IMPORT_FREE_MAX_ROWS", %d ); $GLOBALS["wp_filter"]["npmp_import_max_rows"][10][] = array( "function" => function () { return 1000; }, "accepted_args" => 1 );', $used + 4 ) );
$res = $r['state']['results'] ?? array();
$ok( 'run finished', isset( $r['state']['running'] ) && false === $r['state']['running'], $r );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ), (string) ( $r['mails'] ?? '' ) );
$ok( 'no automation email queued', 0 === (int) ( $r['queued'] ?? -1 ), (string) ( $r['queued'] ?? '' ) );
$ok( 'ivy and hal updated', 2 === (int) ( $res['updated'] ?? -1 ), $res );
$ok( 'hal kept unsubscribed', 1 === (int) ( $res['kept_unsubscribed'] ?? -1 ), $res );
$ok( 'Rig Member and Rig Directors added', array( 'Rig Member', 'Rig Directors' ) === ( $res['levels_added'] ?? null ), $res['levels_added'] ?? '' );
if ( $pro_on ) {
	$ok( '9 new contacts', 9 === (int) ( $res['created'] ?? -1 ) && 0 === (int) ( $res['over_limit'] ?? -1 ), $res );
} else {
	$ok( '4 new contacts', 4 === (int) ( $res['created'] ?? -1 ), $res );
	$ok( '5 past the free limit', 5 === (int) ( $res['over_limit'] ?? -1 ), $res );
	$ok( 'no places left', 0 === (int) ( $r['left'] ?? -1 ), (string) ( $r['left'] ?? '' ) );
}
$ok( 'the hook marks ann as imported with her status', array( 'inactive', 'approved', 'imported' ) === array_slice( (array) ( $r['hook'][ 'ann@' . $domain ] ?? array() ), 0, 3 ), $r['hook'] ?? '' );
$ok( 'the hook carries ann\'s custom field', array( 'rig_favorite_tree' => 'Oak' ) === ( $r['hook'][ 'ann@' . $domain ][4] ?? null ), $r['hook'][ 'ann@' . $domain ] ?? '' );
$ok( 'the profile-fields action fires for ann', 1 === count( (array) ( $r['profile'] ?? array() ) ) && 'ultimate_member' === ( $r['profile'][0][2] ?? '' ) && 'Favorite tree' === ( $r['profile'][0][3]['rig_favorite_tree'] ?? '' ), $r['profile'] ?? '' );

echo "Second run with room\n";
$r   = $child( 'import' );
$res = $r['state']['results'] ?? array();
$ok( 'the rest come over', ( $pro_on ? 0 : 5 ) === (int) ( $res['created'] ?? -1 ) && 0 === (int) ( $res['over_limit'] ?? -1 ), $res );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ) && 0 === (int) ( $r['queued'] ?? -1 ) );

$get = function ( $key ) use ( $members, $domain ) {
	return $members->get_member_by_email( $key . '@' . $domain );
};
$expect = array(
	'ann' => array( 'inactive', 'Rig Member' ),
	'bob' => array( 'inactive', 'Rig Directors' ),
	'cat' => array( 'pending', '' ),
	'dan' => array( 'inactive', '' ),
	'eve' => array( 'pending', '' ),
	'fay' => array( 'unsubscribed', '' ),
	'gus' => array( 'inactive', 'Rig Member' ),
	'hal' => array( 'unsubscribed', 'Hand Level' ),
	'ivy' => array( 'pending', '' ),
	'jon' => array( 'inactive', '' ),
	'kim' => array( 'inactive', 'Rig Member' ),
);
foreach ( $expect as $key => $e ) {
	$m = $get( $key );
	$ok( "{$key} is " . $e[0] . ( '' !== $e[1] ? ', ' . $e[1] : ', no level' ), $m && $e[0] === $m->status && $e[1] === (string) $m->membership_level, $m ? array( $m->status, $m->membership_level ) : 'missing' );
}
foreach ( array( 'adm', 'edi', 'aut', 'cus', 'mod' ) as $key ) {
	$ok( "{$key} stays behind", ! $get( $key ) );
}
$ann = $get( 'ann' );
$ok( 'ann has her name, phone, mobile, and country', $ann && 'Ann Arbor' === $ann->name && '805-555-0101' === $ann->phone && '805-555-0199' === $ann->mobile && 'United States' === $ann->country, $ann );
$ok( 'ann came from Ultimate Member', $ann && 'ultimate_member' === $ann->source );
$ok( 'ann counts toward the free total', $pro_on || ( $ann && '' !== (string) get_post_meta( $ann->id, '_npmp_free_import', true ) ) );
$kim = $get( 'kim' );
$ok( 'kim\'s Added date is her registration in site time', $kim && 0 === strpos( (string) get_post_field( 'post_date', $kim->id ), '2023-03-01 12:00' ) && 0 === strpos( (string) get_post_field( 'post_date_gmt', $kim->id ), '2023-03-01 20:00' ), $kim ? array( get_post_field( 'post_date', $kim->id ), get_post_field( 'post_date_gmt', $kim->id ) ) : 'missing' );
$hal = $get( 'hal' );
$ok( 'hal keeps where he came from', $hal && 'manual' === $hal->source );
$ivy = $get( 'ivy' );
$ok( 'ivy gets her name', $ivy && 'Ivy Vine' === $ivy->name, $ivy ? $ivy->name : '' );
$ok( 'users and user meta unchanged', array() === $changed( $users_before ), $changed( $users_before ) );

echo "Third run: approved members agreed to newsletters\n";
$r   = $child( 'import', 'RIG_NEWSLETTERS=yes' );
$res = $r['state']['results'] ?? array();
$ok( 'no new contacts', 0 === (int) ( $res['created'] ?? -1 ), $res );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ) && 0 === (int) ( $r['queued'] ?? -1 ) );
foreach ( array( 'ann' => 'subscribed', 'bob' => 'subscribed', 'dan' => 'subscribed', 'jon' => 'subscribed', 'kim' => 'subscribed', 'ivy' => 'subscribed', 'cat' => 'pending', 'eve' => 'pending', 'fay' => 'unsubscribed', 'gus' => 'inactive', 'hal' => 'unsubscribed' ) as $key => $want ) {
	$m = $get( $key );
	$ok( "{$key} is {$want}", $m && $want === $m->status, $m ? $m->status : 'missing' );
}

echo "Fourth run: back to the safer choice\n";
$r   = $child( 'import' );
$res = $r['state']['results'] ?? array();
$ok( 'nothing changes', 0 === (int) ( $res['created'] ?? -1 ) && 0 === (int) ( $res['updated'] ?? -1 ), $res );
$ok( 'ann stays Active', 'subscribed' === $get( 'ann' )->status );
$ok( 'nobody doubled', 11 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND pm.meta_value LIKE %s AND p.post_type = 'npmp_contact'", '%@' . $wpdb->esc_like( $domain ) ) ) );

// A status change in Ultimate Member follows on the next run.
$um_users->deactivate( $users['kim'] );
$r = $child( 'import', 'RIG_NEWSLETTERS=yes' );
$ok( 'kim, deactivated in Ultimate Member, stops getting newsletters', 'inactive' === $get( 'kim' )->status, $get( 'kim' )->status );
$um_users->reactivate( $users['kim'] );
$users_before = $snapshot();

echo "Finish step and visitors with Ultimate Member on\n";
$sc = $child( 'screens' );
$ok( 'finish lists the join page', false !== strpos( (string) ( $sc['finish'] ?? '' ), 'Rig UM Join' ), substr( (string) ( $sc['finish'] ?? '' ), 0, 400 ) );
$ok( 'finish warns about logins', false !== strpos( (string) ( $sc['finish'] ?? '' ), 'WordPress lets them log in' ) );
$ok( 'finish counts the restricted page', false !== strpos( (string) ( $sc['finish'] ?? '' ), 'You kept 1 posts and pages' ) );
$join_on    = $fetch( $join_page );
$private_on = $fetch( $private );
$served     = false !== strpos( $join_on, 'Rig public text.' );
$ok( 'the site answers at its home URL', $served, substr( $join_on, 0, 200 ) );
if ( $served ) {
	$ok( 'with it on, a visitor sees its form, not the shortcode', false === strpos( $join_on, '[ultimatemember' ) && false !== strpos( $join_on, 'um-form' ) );
	$ok( 'with it on, the members-only line is hidden', false === strpos( $join_on, 'Rig secret for members' ) );
	$ok( 'with it on, the private page is hidden', false === strpos( $private_on, 'Rig members-only page body.' ) );
}

echo "Switch Plugins\n";
$h = $child( 'hub' );
$ok( 'registered, free', ! empty( $h['registered'] ) && 'free' === ( $h['tier'] ?? '' ), $h );
$ok( 'no Pro parts', array() === ( $h['pro_parts'] ?? null ) );
$ok( 'step 5 unavailable with a reason', empty( $h['has_batch'] ) && false !== strpos( (string) ( $h['reason'] ?? '' ), 'account_status' ), $h['reason'] ?? $h );
$ok( 'remove data refused', 'ok' !== ( $h['data'] ?? 'ok' ), $h['data'] ?? '' );
$ok( 'turning it off before checking is refused', 'ok' !== ( $h['early'] ?? 'ok' ), $h['early'] ?? '' );
$ok( 'step 4: data stays while its setting is off', false !== strpos( (string) ( $h['step4_off'] ?? '' ), 'stays in your database' ), $h['step4_off'] ?? '' );
$ok( 'step 4: warns while its setting is on', false !== strpos( (string) ( $h['step4_on'] ?? '' ), 'takes its roles off your users' ) && false === strpos( (string) ( $h['step4_on'] ?? '' ), 'stays in your database' ), $h['step4_on'] ?? '' );
$ok( 'installed plugins name Ultimate Member', isset( $h['installed'][ $um_file ] ), $h['installed'] ?? '' );
$rows = array();
foreach ( (array) ( $h['rows'] ?? array() ) as $row ) {
	$rows[ $row['label'] ] = $row;
}
$ok( 'counts: 11 members, 9 new contacts', 11 === (int) ( $rows['Members in the roles you picked']['old'] ?? -1 ) && 9 === (int) ( $rows['Members in the roles you picked']['new'] ?? -1 ), $rows );
$ok( 'counts: nobody left out', 0 === (int) ( $rows['Members not in your contacts']['old'] ?? -1 ) );
$ok( 'counts: 6 approved', 6 === (int) ( $rows['Approved']['old'] ?? -1 ), $rows['Approved'] ?? '' );
$ok( 'counts: 1 rejected, as Unsubscribed', 1 === (int) ( $rows['Rejected']['old'] ?? -1 ) && false !== strpos( (string) $rows['Rejected']['note'], 'Unsubscribed' ), $rows['Rejected'] ?? '' );
$ok( 'counts: levels', 2 === (int) ( $rows['Membership levels from your roles']['old'] ?? -1 ) && 2 === (int) ( $rows['Membership levels from your roles']['new'] ?? -1 ) );
$ok( 'counts: 1 page with its pieces, 1 restricted', 1 === (int) ( $rows['Pages with an Ultimate Member form, shortcode, or block']['old'] ?? -1 ) && 1 === (int) ( $rows['Posts and pages kept for some visitors only']['old'] ?? -1 ), $rows );
$ok( 'verified', 'ok' === ( $h['verify'] ?? '' ), $h );
$ok( 'Ultimate Member turned off', 'ok' === ( $h['deactivate'] ?? '' ) && false === ( $h['active'] ?? true ), $h );
$ok( 'users and user meta unchanged after turning it off', array() === $changed( $users_before ), $changed( $users_before ) );

echo "Visitors with Ultimate Member off\n";
if ( $served ) {
	$join_off    = $fetch( $join_page );
	$private_off = $fetch( $private );
	$ok( 'its shortcode shows as plain text', false !== strpos( $join_off, '[ultimatemember form_id=' ), substr( wp_strip_all_tags( $join_off ), 0, 300 ) );
	$ok( 'the members-only line shows to everyone', false !== strpos( $join_off, 'Rig secret for members' ) );
	$ok( 'its block shows nothing', false === strpos( $join_off, 'um-form' ) && false === strpos( $join_off, 'wp:um-block' ) );
	$ok( 'the private page is public', false !== strpos( $private_off, 'Rig members-only page body.' ) );
}

echo "Import with Ultimate Member off\n";
$roles_off = $child( 'roles' );
$ok( 'roles read with it off, Rig names and counts kept', false === ( $roles_off['active'] ?? true ) && 'Rig Member' === ( $roles_off['roles'][ $r_member ]['name'] ?? '' ) && 5 === (int) ( $roles_off['roles'][ $r_member ]['count'] ?? -1 ), $roles_off );
$r   = $child( 'import', 'RIG_NEWSLETTERS=yes' );
$res = $r['state']['results'] ?? array();
$ok( 'reads with it off and adds nobody', false === ( $r['active'] ?? true ) && 0 === (int) ( $res['created'] ?? -1 ) && 11 === (int) ( $res['updated'] ?? 0 ) + (int) ( $res['unchanged'] ?? 0 ), $res );
$ok( 'nobody got an email', 0 === (int) ( $r['mails'] ?? -1 ) && 0 === (int) ( $r['queued'] ?? -1 ) );
$ok( 'kim, reactivated, is Active again', 'subscribed' === $get( 'kim' )->status, $get( 'kim' )->status );

// Put the site back.
activate_plugin( $um_file );
$clean();
$sub_meta = (array) get_option( 'um_role_subscriber_meta', array() );
if ( null === $saved_sub_status ) {
	unset( $sub_meta['_um_status'] );
} else {
	$sub_meta['_um_status'] = $saved_sub_status;
}
update_option( 'um_role_subscriber_meta', $sub_meta );
false === $saved_levels ? delete_option( 'npmp_membership_levels' ) : update_option( 'npmp_membership_levels', $saved_levels );
update_option( 'timezone_string', (string) $saved_tz );
update_option( 'gmt_offset', $saved_offset );
false === $saved_cron_lock ? delete_transient( 'doing_cron' ) : set_transient( 'doing_cron', $saved_cron_lock );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
