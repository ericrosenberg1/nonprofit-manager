<?php
/**
 * Real-WordPress run of the Switch Plugins hub with real MailPoet: all five
 * steps in order, the refusals (a step out of order, a missing capability,
 * DISALLOW_FILE_MODS, an add-on that shares MailPoet's tables, a missing or
 * wrong typed confirmation), the free import limit worded from
 * npmp_import_max_rows(), and proof that every contact and the copied
 * settings survive once MailPoet's data is gone.
 *
 * Each step after the import runs in its own `wp` process, the way each form
 * post is its own request, so MailPoet's code isn't loaded in a request that
 * runs after MailPoet is off or deleted.
 *
 * Needs a throwaway WordPress with this plugin and MailPoet active, `wp` on
 * PATH. Verified on SQLite with MailPoet 5.45.2, where MailPoet's own API
 * builds its 40 tables and adds subscribers:
 *   wp plugin install mailpoet --activate
 *   wp eval-file tests/rig/mailpoet-hub.php
 *
 * Run it with Nonprofit Manager Pro inactive, since Pro lifts the free total
 * this rig fills up. It deletes MailPoet's files and data. Install MailPoet
 * again before another run.
 */

$phase = isset( $args[0] ) ? (string) $args[0] : 'main';

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};
$code = function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : ( is_array( $result ) ? (string) ( $result['message'] ?? 'ok' ) : 'ok' );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin  = (int) $admins[0]->ID;
wp_set_current_user( $admin );

$slug   = 'mailpoet';
$domain = 'rig-mp.example.org';

/**
 * Run a phase of this file in a fresh wp process and pass its output through.
 *
 * @param string $phase  Phase name.
 * @param string $before PHP for --exec, run before WordPress loads.
 * @return int Exit code.
 */
$run_phase = function ( $phase, $before = '' ) {
	$cmd = sprintf(
		'wp --path=%s %s eval-file %s %s 2>&1',
		escapeshellarg( untrailingslashit( ABSPATH ) ),
		'' !== $before ? '--exec=' . escapeshellarg( $before ) : '',
		escapeshellarg( __FILE__ ),
		escapeshellarg( $phase )
	);
	$out  = array();
	$exit = 0;
	exec( $cmd, $out, $exit );
	foreach ( $out as $line ) {
		if ( false === strpos( $line, 'Deprecated' ) ) {
			echo $line, "\n";
		}
	}
	return $exit;
};

/**
 * Contact IDs for the rig's addresses, email => ID.
 *
 * @return array<string,int>
 */
$rig_contacts = function () use ( $wpdb, $domain ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'npmp_email' AND p.post_type = 'npmp_contact' AND pm.meta_value LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ strtolower( $row->meta_value ) ] = (int) $row->post_id;
	}
	return $out;
};

if ( 'main' === $phase && ! defined( 'NPMP_RIG_MP_BASE' ) ) {
	// The free total is a constant set at load, so run again with 3 places
	// on top of the free imports other rigs left on this site. The rig's own
	// contacts from an earlier run don't count, since the run deletes them.
	$base = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT f.post_id) FROM {$wpdb->postmeta} f INNER JOIN {$wpdb->posts} p ON p.ID = f.post_id AND p.post_type = 'npmp_contact' AND p.post_status = 'publish' LEFT JOIN {$wpdb->postmeta} e ON e.post_id = f.post_id AND e.meta_key = 'npmp_email' WHERE f.meta_key = %s AND ( e.meta_value IS NULL OR e.meta_value NOT LIKE %s )", NPMP_IMPORT_FREE_META, '%@' . $wpdb->esc_like( $domain ) ) );
	$code = $run_phase( 'main', sprintf( "define( 'NPMP_RIG_MP_BASE', %d ); define( 'NPMP_IMPORT_FREE_MAX_ROWS', %d );", $base, $base + 3 ) );
	remove_all_actions( 'shutdown' );
	exit( $code );
}

if ( 'main' === $phase ) {
	if ( ! defined( 'MAILPOET_VERSION' ) || ! class_exists( '\MailPoet\API\API' ) ) {
		echo "MailPoet isn't active. Run: wp plugin install mailpoet --activate\n";
		exit( 1 );
	}
	printf( "MailPoet %s, Pro groups %s\n", MAILPOET_VERSION, npmp_mailpoet_groups_supported() ? 'on' : 'off' );

	echo "Clean slate\n";
	foreach ( $rig_contacts() as $id ) {
		wp_delete_post( $id, true );
	}
	$subs = npmp_mailpoet_table( 'subscribers' );
	$segs = npmp_mailpoet_table( 'segments' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$subs} WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) );
	$wpdb->query( "DELETE FROM {$segs} WHERE type = 'default' AND name LIKE 'Rig %'" );
	npmp_mailpoet_reset_stage();
	delete_option( 'npmp_mailpoet_imported' );
	delete_option( 'npmp_import_hub' );
	delete_option( 'npmp_rig_mp_hub' );

	echo "Seed MailPoet through its own API, an add-on, and things that must survive\n";
	$keep_settings = array();
	foreach ( array( 'npmp_email_settings', 'npmp_email_from_name', 'npmp_email_from_email', 'npmp_membership_form_settings' ) as $option ) {
		$keep_settings[ $option ] = get_option( $option, null );
	}
	\MailPoet\Settings\SettingsController::getInstance()->set( 'sender', array( 'name' => 'Rig Club', 'address' => 'news@' . $domain ) );
	$api   = \MailPoet\API\API::MP( 'v1' );
	// MailPoet makes its own "Newsletter mailing list" on activation.
	$lists_before = count( npmp_mailpoet_read_lists() );
	$board = $api->addList( array( 'name' => 'Rig Board' ) );
	$vols  = $api->addList( array( 'name' => 'Rig Volunteers' ) );
	$emails = array();
	foreach ( array( 'ana', 'ben', 'cal', 'dee', 'eve' ) as $i => $who ) {
		$email    = "$who@$domain";
		$emails[] = $email;
		$api->addSubscriber(
			array( 'email' => $email, 'first_name' => ucfirst( $who ), 'last_name' => 'Rig', 'status' => 'subscribed' ),
			array( $i % 2 ? $vols['id'] : $board['id'] ),
			array( 'send_confirmation_email' => false, 'skip_subscriber_notification' => true, 'schedule_welcome_email' => false )
		);
	}
	$ok( 'five subscribers in MailPoet', 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$subs} WHERE email LIKE %s AND deleted_at IS NULL", '%@' . $wpdb->esc_like( $domain ) ) ) );

	// Ben was already a contact, so the import updates him and keeps his source.
	$mm  = NPMP_Member_Manager::get_instance();
	$ben = $mm->add_member( array( 'name' => 'Ben Rig', 'email' => "ben@$domain", 'status' => 'subscribed', 'source' => 'manual' ) );
	$ok( 'Ben is already a contact', is_numeric( $ben ) && $ben > 0, $ben );
	// Zed comes in through a CSV import first, so 2 of the 3 free places are left.
	$csv = NPMP_Import_Manager::get_instance()->process_named_rows( array( array( 'email' => "zed@$domain", 'name' => 'Zed Rig' ) ), array( 'email' => 'email', 'name' => 'name' ), array( 'duplicate_handling' => 'update' ) );
	$ok( 'a CSV import brings in Zed', 1 === (int) $csv['imported'], $csv );
	$zed = (int) ( $mm->get_member_by_email( "zed@$domain" )->id ?? 0 );
	$ok( 'the CSV import marks Zed as a free import', '1' === (string) get_post_meta( $zed, NPMP_IMPORT_FREE_META, true ) );
	$ok( 'two free places left', 2 === npmp_import_free_remaining(), npmp_import_free_remaining() );

	$form_page = wp_insert_post( array( 'post_title' => 'Rig Join Us', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[mailpoet_form id="1"]' ) );
	update_option( 'my_mailpoet_bridge', 'keep' );
	update_option( 'mailpoetish', 'keep' );
	$as_table = $wpdb->prefix . 'actionscheduler_actions';
	$as_rows  = $as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as_table ) ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$as_table}" ) : -1;

	$addon = 'npmp-rig-mp-addon/npmp-rig-mp-addon.php';
	wp_mkdir_p( WP_PLUGIN_DIR . '/npmp-rig-mp-addon' );
	file_put_contents( WP_PLUGIN_DIR . '/' . $addon, "<?php\n/**\n * Plugin Name: Rig MailPoet Add-on\n * Version: 1.0\n * Requires Plugins: mailpoet\n */\n" );
	wp_clean_plugins_cache( false );
	activate_plugin( $addon );
	$ok( 'the add-on is active', is_plugin_active( $addon ) );

	$tables_before = npmp_mailpoet_hub_existing_tables();
	$ok( 'MailPoet built its tables', count( $tables_before ) >= 30, count( $tables_before ) );
	$ok( 'MailPoet wrote options the matcher finds', in_array( 'mailpoet_plugin_activated', npmp_mailpoet_hub_option_names(), true ), npmp_mailpoet_hub_option_names() );
	$ok( 'look-alike options are not on the list', ! array_intersect( array( 'my_mailpoet_bridge', 'mailpoetish' ), npmp_mailpoet_hub_option_names() ) );

	echo "Registry and first look\n";
	$source = npmp_import_hub_source( $slug );
	$ok( 'MailPoet is registered', is_array( $source ) && 'MailPoet' === $source['name'] );
	$ok( 'MailPoet is a free source with one Pro part', 'free' === $source['tier'] && 1 === count( $source['pro_parts'] ) );
	$ok( 'MailPoet declares data removal', true === $source['data_removal']['available'] );
	$facts  = npmp_import_hub_facts( $source );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'detected as active', 'active' === npmp_import_hub_detect_state( $facts ) );
	$ok( 'MailPoet goes on top, not in the folded group', in_array( $slug, npmp_import_hub_partition( array( $slug => $facts ) )['here'], true ) );
	$ok( 'import is the next step', 'ready' === $states['import'] && 'locked' === $states['verify'] && 'locked' === $states['remove_data'], $states );

	echo "Refusals before the import\n";
	$ok( 'verify out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
	$ok( 'deactivate out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	$ok( 'remove data out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$sub = wp_insert_user( array( 'user_login' => 'mphubsub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $sub );
	$ok( 'a subscriber gets the capability refusal', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'import' ) ) );
	wp_set_current_user( $admin );
	wp_delete_user( $sub );

	echo "Step 1: import through the MailPoet importer, with 2 free places left\n";
	$res = npmp_import_hub_run_step( $slug, 'import' );
	$ok( 'the step hands back the importer screen', is_array( $res ) && false !== strpos( $res['url'], 'page=npmp_mailpoet_import' ), $res );
	// One run may read 50 rows, so the site total is what stops new people.
	$cap = function () {
		return 50;
	};
	add_filter( 'npmp_import_max_rows', $cap, PHP_INT_MAX );
	$guard = 0;
	do {
		$r = npmp_mailpoet_stage_batch( 0 === $guard, 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	npmp_mailpoet_set_review( npmp_mailpoet_status_keys(), array() );
	ob_start();
	npmp_mailpoet_render_approve( npmp_mailpoet_get_stage() );
	$approve = html_entity_decode( wp_strip_all_tags( ob_get_clean() ), ENT_QUOTES );
	$ok( 'the Approve screen names the total and the places left', false !== strpos( $approve, 'you can import ' . NPMP_IMPORT_FREE_MAX_ROWS . ' contacts in total, and you have 2 places left' ), $approve );
	npmp_mailpoet_approve( array( 'from_name', 'from_email' ) );
	$guard = 0;
	do {
		$r = npmp_mailpoet_migrate_batch( 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	$stage = npmp_mailpoet_get_stage();
	$ok( 'the free total stopped new people', ! empty( $stage['results']['cap_reached'] ) && 2 === (int) $stage['results']['over_limit'], $stage['results'] );
	$ok( 'the import read every subscriber', ! empty( $stage['migrated'] ) && 2 === (int) $stage['results']['created'] && 1 === (int) $stage['results']['updated'] + (int) $stage['results']['unchanged'], $stage['results'] );
	$ok( 'no free places left', 0 === npmp_import_free_remaining() );
	$contacts = $rig_contacts();
	unset( $contacts[ "zed@$domain" ] );
	$ok( 'Ana and Cal are new contacts, Ben is still one', isset( $contacts[ "ana@$domain" ], $contacts[ "ben@$domain" ], $contacts[ "cal@$domain" ] ) && 3 === count( $contacts ), array_keys( $contacts ) );
	ob_start();
	npmp_mailpoet_render_migrate( $stage );
	$ok( 'the migrate screen says the free contacts are used up', false !== strpos( html_entity_decode( ob_get_clean(), ENT_QUOTES ), 'You have imported your ' . NPMP_IMPORT_FREE_MAX_ROWS . ' free contacts' ) );
	$ok( 'MailPoet marked the 2 contacts it made', NPMP_RIG_MP_BASE + 3 === npmp_import_free_used( true ), npmp_import_free_used() );

	echo "Run the import again at the limit\n";
	// A name that's only the email counts as blank, so the rerun fills it in.
	wp_update_post( array( 'ID' => $contacts[ "ana@$domain" ], 'post_title' => "ana@$domain" ) );
	$guard = 0;
	do {
		$r = npmp_mailpoet_stage_batch( 0 === $guard, 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	npmp_mailpoet_set_review( npmp_mailpoet_status_keys(), array() );
	npmp_mailpoet_approve( array() );
	$guard = 0;
	do {
		$r = npmp_mailpoet_migrate_batch( 2 );
	} while ( empty( $r['done'] ) && ++$guard < 20 );
	$again = npmp_mailpoet_get_stage()['results'];
	$ok( 'the rerun makes no new contact', 0 === (int) $again['created'] && 2 === (int) $again['over_limit'], $again );
	$ok( 'the rerun still updates Ana', (int) $again['updated'] >= 1, $again );
	$now_rerun = $rig_contacts();
	$ok( 'still 4 contacts from the rig', 4 === count( $now_rerun ), array_keys( $now_rerun ) );
	$ok( 'Ana has her name back', 'Ana Rig' === get_post( $contacts[ "ana@$domain" ] )->post_title, get_post( $contacts[ "ana@$domain" ] )->post_title );
	$ok( 'still no free places left', 0 === npmp_import_free_remaining() );
	$ok( 'sender name copied', 'Rig Club' === ( get_option( 'npmp_email_settings', array() )['from_name'] ?? '' ), get_option( 'npmp_email_settings' ) );
	$states = npmp_import_hub_step_states( npmp_import_hub_facts( $source ) );
	$ok( 'import done, verify next', 'done' === $states['import'] && 'ready' === $states['verify'], $states );

	echo "Step 2: verify, with the free total used up\n";
	$counts = npmp_mailpoet_hub_counts();
	$ok( 'subscribers: 5 in MailPoet, 2 new contacts', 5 === $counts[0]['old'] && 2 === $counts[0]['new'], $counts[0] );
	$ok( 'two subscribers left behind', 2 === $counts[1]['old'], $counts[1] );
	$ok( 'the note names the free total from NPMP_IMPORT_FREE_MAX_ROWS', false !== strpos( $counts[1]['note'], 'import ' . NPMP_IMPORT_FREE_MAX_ROWS . ' contacts in all' ), $counts[1]['note'] );
	$ok( 'two more lists than MailPoet started with', $lists_before + 2 === $counts[2]['old'], array( $lists_before, $counts[2] ) );
	if ( npmp_mailpoet_groups_supported() ) {
		$ok( 'with Pro groups, lists say they became groups', false !== strpos( $counts[2]['note'], 'became a group' ), $counts[2]['note'] );
	} else {
		$ok( 'without Pro, lists say they need Pro', false !== strpos( $counts[2]['note'], 'Pro' ), $counts[2]['note'] );
	}
	$ok( 'one page with a MailPoet form', 1 === $counts[3]['old'], $counts[3] );
	$lines = implode( ' | ', npmp_mailpoet_hub_data_lines() );
	$ok( 'step 5 says who would be lost', false !== strpos( $lines, "2 of them aren't in your contacts" ), $lines );
	$ok( 'no box ticked, refused', 'npmp_hub_unchecked' === $code( npmp_import_hub_run_step( $slug, 'verify', array() ) ) );
	$ok( 'box ticked, verified', 'verified' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );

	echo "The screen renders\n";
	set_current_screen( 'dashboard' );
	ob_start();
	npmp_import_hub_render_page();
	$html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
	remove_filter( 'npmp_import_max_rows', $cap, PHP_INT_MAX );
	$ok( 'shows MailPoet with its status', false !== strpos( $html, 'id="npmp-hub-mailpoet"' ) && false !== strpos( $html, 'Active on this site' ) );
	$ok( 'shows the counts table', false !== strpos( $html, 'npmp-hub-counts' ) && false !== strpos( $html, 'Subscribers not in your contacts' ) );
	$ok( 'the total shows on the screen', false !== strpos( $html, 'In the free version you can import ' . NPMP_IMPORT_FREE_MAX_ROWS . ' contacts in all' ) );
	$ok( 'the Pro part shows', false !== strpos( $html, 'Turn each list into a group' ) );
	$ok( 'deactivate form is next', false !== strpos( $html, 'value="deactivate"' ) );
	// Pro lifts the limit the same way.
	$lift = function () {
		return PHP_INT_MAX;
	};
	add_filter( 'npmp_import_max_rows', $lift, PHP_INT_MAX );
	$counts = npmp_mailpoet_hub_counts();
	remove_filter( 'npmp_import_max_rows', $lift, PHP_INT_MAX );
	$ok( 'with the limit lifted, the note no longer names one', false === strpos( $counts[1]['note'], 'free version' ), $counts[1]['note'] );

	echo "Step 3: deactivate\n";
	add_role( 'npmp_rig_manager', 'Rig manager', array( 'read' => true, 'manage_options' => true ) );
	$mgr = wp_insert_user( array( 'user_login' => 'mphubmgr' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'npmp_rig_manager' ) );
	wp_set_current_user( $mgr );
	$ok( 'without activate_plugins, refused', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	wp_set_current_user( $admin );
	wp_delete_user( $mgr );
	remove_role( 'npmp_rig_manager' );
	$ok( 'MailPoet still on', is_plugin_active( npmp_mailpoet_plugin_file() ) );
	$ok( 'deactivated', 'deactivated' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
	$ok( 'MailPoet off', ! is_plugin_active( npmp_mailpoet_plugin_file() ) );
	$ok( 'the add-on off too', ! is_plugin_active( $addon ) );

	update_option(
		'npmp_rig_mp_hub',
		array(
			'contacts'  => $contacts,
			'zed'       => (int) $zed,
			'ben'       => (int) $ben,
			'form_page' => (int) $form_page,
			'addon'     => $addon,
			'as_rows'   => $as_rows,
			'settings'  => $keep_settings,
		),
		false
	);

	echo "--- next request, MailPoet off ---\n";
	$fails += $run_phase( 'remove' ) ? 1 : 0;
	echo "--- DISALLOW_FILE_MODS ---\n";
	$fails += $run_phase( 'filemods', "define( 'DISALLOW_FILE_MODS', true );" ) ? 1 : 0;
	echo "--- next request ---\n";
	$fails += $run_phase( 'remove2' ) ? 1 : 0;
	echo "--- next request, MailPoet deleted ---\n";
	$fails += $run_phase( 'data' ) ? 1 : 0;
	echo "--- next request, after data removal ---\n";
	$fails += $run_phase( 'after' ) ? 1 : 0;

	// This process loaded MailPoet before a child process deleted its files.
	remove_all_actions( 'shutdown' );
	printf( "\n%s\n", $fails ? "$fails failed" : 'all passed' );
	exit( $fails ? 1 : 0 );
}

$rig    = get_option( 'npmp_rig_mp_hub', array() );
$source = npmp_import_hub_source( $slug );

if ( 'remove' === $phase ) {
	echo "Step 4: refusals while MailPoet is off but installed\n";
	$ok( 'MailPoet not loaded in this request', ! defined( 'MAILPOET_VERSION' ) );
	$facts = npmp_import_hub_facts( $source );
	$ok( 'detected as installed and off', 'inactive' === npmp_import_hub_detect_state( $facts ) );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'remove plugin is next', 'done' === $states['deactivate'] && 'ready' === $states['remove_plugin'] && 'locked' === $states['remove_data'], $states );
	$ok( 'the importer still reads the tables with MailPoet off', true === npmp_mailpoet_hub_can_import() );
	$strip = function ( $caps ) {
		unset( $caps['delete_plugins'] );
		return $caps;
	};
	add_filter( 'user_has_cap', $strip );
	$ok( 'without delete_plugins, refused', 'npmp_hub_cap' === $code( npmp_import_hub_run_step( $slug, 'remove_plugin' ) ) );
	remove_filter( 'user_has_cap', $strip );
	$ok( 'MailPoet files still there', '' !== npmp_mailpoet_plugin_file() );
	exit( $fails ? 1 : 0 );
}

if ( 'filemods' === $phase ) {
	$ok( 'DISALLOW_FILE_MODS is on in this request', defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
	$ok( 'remove plugin refused', 'npmp_hub_file_mods' === $code( npmp_import_hub_run_step( $slug, 'remove_plugin' ) ) );
	$ok( 'MailPoet files still there', '' !== npmp_mailpoet_plugin_file() );
	exit( $fails ? 1 : 0 );
}

if ( 'remove2' === $phase ) {
	echo "Step 4: delete the plugin\n";
	$installed = npmp_mailpoet_hub_installed_plugins();
	$ok( 'MailPoet and the add-on are what gets deleted', 2 === count( $installed ) && isset( $installed[ $rig['addon'] ] ), $installed );
	$res = npmp_import_hub_run_step( $slug, 'remove_plugin' );
	$ok( 'deleted', 'removed_plugin' === $code( $res ), $code( $res ) );
	wp_clean_plugins_cache( false );
	$ok( 'MailPoet folder gone', ! is_dir( WP_PLUGIN_DIR . '/mailpoet' ) );
	$ok( 'add-on folder gone', ! is_dir( WP_PLUGIN_DIR . '/npmp-rig-mp-addon' ) );
	$ok( 'this plugin still here', is_dir( WP_PLUGIN_DIR . '/nonprofit-manager' ) );
	$ok( 'MailPoet data still in the database after deleting files', npmp_mailpoet_hub_has_data() );
	exit( $fails ? 1 : 0 );
}

if ( 'data' === $phase ) {
	echo "Step 5: delete MailPoet's data\n";
	$facts = npmp_import_hub_facts( $source );
	$ok( 'detected as data left behind', 'data' === npmp_import_hub_detect_state( $facts ) );
	$ok( 'remove data is next', 'ready' === npmp_import_hub_step_states( $facts )['remove_data'] );

	// MailPoet Premium reads the same tables, so its presence blocks the step.
	wp_mkdir_p( WP_PLUGIN_DIR . '/mailpoet-premium' );
	file_put_contents( WP_PLUGIN_DIR . '/mailpoet-premium/mailpoet-premium.php', "<?php\n/**\n * Plugin Name: MailPoet Premium\n * Version: 1.0\n */\n" );
	wp_clean_plugins_cache( false );
	// It counts as a MailPoet add-on, so step 4 reopens to delete it first.
	$res = npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) );
	$ok( 'MailPoet Premium installed: refused', in_array( $code( $res ), array( 'npmp_hub_order', 'npmp_hub_blocked' ), true ), $code( $res ) );
	$ok( 'MailPoet Premium is named as a blocker', in_array( 'MailPoet Premium', npmp_mailpoet_hub_blockers(), true ) );
	unlink( WP_PLUGIN_DIR . '/mailpoet-premium/mailpoet-premium.php' );
	rmdir( WP_PLUGIN_DIR . '/mailpoet-premium' );
	wp_clean_plugins_cache( false );

	$tables = count( npmp_mailpoet_hub_existing_tables() );
	$ok( 'no confirmation: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array() ) ) );
	$ok( 'wrong word: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELET' ) ) ) );
	$ok( 'the plugin name is not the word: refused', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'MailPoet' ) ) ) );
	$ok( 'nothing deleted after the refusals', $tables === count( npmp_mailpoet_hub_existing_tables() ) && $tables >= 30, $tables );

	$res = npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => ' delete ', 'one_batch' => 1 ) );
	$ok( 'first batch runs', in_array( $code( $res ), array( 'removing', 'removed_data' ), true ), $res );
	$guard = 0;
	while ( 'removing' === $code( $res ) && ++$guard < 20 ) {
		$res = npmp_import_hub_run_step( $slug, 'remove_data', array( 'one_batch' => 1 ) );
	}
	$ok( 'later batches need no retyping and finish', 'removed_data' === $code( $res ), $res );
	exit( $fails ? 1 : 0 );
}

if ( 'after' === $phase ) {
	echo "What's gone\n";
	$ok( 'no MailPoet tables', array() === npmp_mailpoet_hub_existing_tables(), npmp_mailpoet_hub_existing_tables() );
	$ok( 'no MailPoet options', array() === npmp_mailpoet_hub_option_names(), npmp_mailpoet_hub_option_names() );
	$ok( 'no MailPoet posts', 0 === npmp_mailpoet_hub_count_posts() );
	$ok( 'no orphaned post meta', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" ) );
	$ok( 'has_data is false now', ! npmp_mailpoet_hub_has_data() );

	echo "What stays\n";
	$now = $rig_contacts();
	unset( $now[ 'zed@' . $domain ] );
	$ok( 'all three contacts still here', count( $rig['contacts'] ) === count( $now ) && ! array_diff_assoc( $rig['contacts'], $now ), array_keys( $now ) );
	$mm = NPMP_Member_Manager::get_instance();
	$ana = (array) $mm->get_member_by_id( $now[ 'ana@' . $domain ] ?? 0 );
	$ben = (array) $mm->get_member_by_id( $rig['ben'] );
	$ok( 'Ana still says she came from MailPoet', $ana && 'mailpoet' === ( $ana['source'] ?? '' ), $ana );
	$ok( 'Ben keeps his own source', $ben && 'manual' === ( $ben['source'] ?? '' ), $ben );
	$ok( 'sender name kept', 'Rig Club' === ( get_option( 'npmp_email_settings', array() )['from_name'] ?? '' ) );
	$ok( 'import counter kept', (int) get_option( 'npmp_mailpoet_imported' ) > 0 );
	$ok( 'look-alike options kept', 'keep' === get_option( 'my_mailpoet_bridge' ) && 'keep' === get_option( 'mailpoetish' ) );
	$page = get_post( $rig['form_page'] );
	$ok( 'the page that held a MailPoet form is untouched', $page && 'publish' === $page->post_status && false !== strpos( $page->post_content, 'mailpoet_form' ) );
	if ( $rig['as_rows'] >= 0 ) {
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		$ok( 'Action Scheduler table kept', $as_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as_table ) ) ) );
	}

	echo "The hub after the switch\n";
	$facts  = npmp_import_hub_facts( $source );
	$states = npmp_import_hub_step_states( $facts );
	$ok( 'every step done', npmp_import_hub_finished( $states ), $states );
	$ok( 'remove data can\'t run twice', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$ok( 'MailPoet importer screen still registered', npmp_mailpoet_importer_visible() );

	echo "MailPoet's data comes back (installed again)\n";
	update_option( 'mailpoet_plugin_activated', 1 );
	$facts = npmp_import_hub_facts( $source );
	$state = npmp_import_hub_get_state( $slug );
	$ok( 'the old check and removal are forgotten', empty( $state['removal'] ) && empty( $state['verified'] ), $state );
	$ok( 'verify is open again', 'ready' === npmp_import_hub_step_states( $facts )['verify'] );
	$ok( 'remove data refuses until you check again', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array() ) ) );
	$ok( 'verified again', 'verified' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
	$ok( 'remove data wants the word again', 'npmp_hub_confirm' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array() ) ) );
	$ok( 'and clears it with the word', 'removed_data' === $code( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
	$ok( 'gone again', false === get_option( 'mailpoet_plugin_activated' ) );

	echo "Clean up\n";
	foreach ( $now as $id ) {
		wp_delete_post( $id, true );
	}
	wp_delete_post( $rig['zed'], true );
	wp_delete_post( $rig['form_page'], true );
	delete_option( 'my_mailpoet_bridge' );
	delete_option( 'mailpoetish' );
	foreach ( (array) $rig['settings'] as $option => $value ) {
		null === $value ? delete_option( $option ) : update_option( $option, $value );
	}
	delete_option( 'npmp_rig_mp_hub' );
	exit( $fails ? 1 : 0 );
}
