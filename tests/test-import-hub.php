<?php
/**
 * Switch Plugins hub (includes/import/import-hub.php) and The Events Calendar
 * as its first source (includes/import/tec-hub-source.php): source
 * normalising, step order and reruns, detection words, grouping, the Pro
 * lock, the typed confirmation, and which TEC options, cron hooks, and plugins
 * count as TEC's for data removal.
 *
 * The database half runs on a real WordPress with TEC: tests/rig/import-hub.php.
 *
 * Run: php tests/test-import-hub.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][] = array( $hook, $cb, $prio );
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][] = array( $hook, $cb, $prio );
}
function is_admin() {
	return false;
}
function _x( $text, $context, $domain = null ) {
	return $text;
}

require_once dirname( __DIR__ ) . '/includes/import/tec-importer.php';
$before = count( $GLOBALS['t_hooks'] );
require_once dirname( __DIR__ ) . '/includes/import/import-hub.php';
require_once dirname( __DIR__ ) . '/includes/import/tec-hub-source.php';

echo "Front end loads no hub hooks, only the source filter\n";
$added = array_slice( $GLOBALS['t_hooks'], $before );
check( 'one hook added outside admin', 1, count( $added ) );
check( 'it is the sources filter', 'npmp_import_sources', $added[0][0] );

echo "Normalising a source\n";
check( 'no name, dropped', null, npmp_import_hub_normalize_source( 'x', array( 'covers' => 'y' ) ) );
check( 'bad slug, dropped', null, npmp_import_hub_normalize_source( 'bad slug!', array( 'name' => 'X' ) ) );
check( 'not an array, dropped', null, npmp_import_hub_normalize_source( 'x', 'X' ) );
$n = npmp_import_hub_normalize_source( 'MailPoet', array( 'name' => 'MailPoet', 'tier' => 'gold', 'plugin_folders' => array( 'mailpoet', '../etc', 'mailpoet-premium' ) ) );
check( 'slug lowercased', 'mailpoet', $n['slug'] );
check( 'unknown tier becomes free', 'free', $n['tier'] );
check( 'unsafe folder dropped', array( 'mailpoet', 'mailpoet-premium' ), $n['plugin_folders'] );
check( 'no batch means data removal unavailable', false, $n['data_removal']['available'] );
$p = npmp_import_hub_normalize_source( 'givewp', array( 'name' => 'GiveWP', 'tier' => 'pro', 'data_removal' => array( 'batch' => 'strlen' ) ) );
check( 'pro tier kept', 'pro', $p['tier'] );
check( 'a callable batch makes data removal available', true, $p['data_removal']['available'] );

echo "Detection words\n";
check( 'active wins', 'active', npmp_import_hub_detect_state( array( 'active' => true, 'installed' => true, 'has_data' => true ) ) );
check( 'installed and off', 'inactive', npmp_import_hub_detect_state( array( 'installed' => true ) ) );
check( 'plugin gone, data left', 'data', npmp_import_hub_detect_state( array( 'has_data' => true ) ) );
check( 'nothing', 'none', npmp_import_hub_detect_state( array() ) );

echo "Step order on a site still running the old plugin\n";
$fresh = array( 'active' => true, 'installed' => true, 'removal_available' => true );
$s     = npmp_import_hub_step_states( $fresh );
check( 'import ready', 'ready', $s['import'] );
check( 'verify locked', 'locked', $s['verify'] );
check( 'deactivate locked', 'locked', $s['deactivate'] );
check( 'remove plugin locked', 'locked', $s['remove_plugin'] );
check( 'remove data locked', 'locked', $s['remove_data'] );
check( 'current is import', 'import', npmp_import_hub_current_step( $s ) );
check( 'verify refused out of order', false, npmp_import_hub_can_run( 'verify', $s ) );
check( 'remove data refused out of order', false, npmp_import_hub_can_run( 'remove_data', $s ) );
check( 'unknown step refused', false, npmp_import_hub_can_run( 'nuke', $s ) );

$s = npmp_import_hub_step_states( array_merge( $fresh, array( 'imported' => true ) ) );
check( 'imported: verify ready', 'ready', $s['verify'] );
check( 'imported: deactivate still locked', 'locked', $s['deactivate'] );
check( 'import can run again', true, npmp_import_hub_can_run( 'import', $s ) );
check( 'deactivate refused before verify', false, npmp_import_hub_can_run( 'deactivate', $s ) );

$s = npmp_import_hub_step_states( array_merge( $fresh, array( 'imported' => true, 'verified' => true ) ) );
check( 'verified: deactivate ready', 'ready', $s['deactivate'] );
check( 'verify can run again', true, npmp_import_hub_can_run( 'verify', $s ) );
check( 'remove plugin refused while active', false, npmp_import_hub_can_run( 'remove_plugin', $s ) );

$s = npmp_import_hub_step_states( array( 'imported' => true, 'verified' => true, 'installed' => true, 'removal_available' => true ) );
check( 'off: deactivate done', 'done', $s['deactivate'] );
check( 'off: remove plugin ready', 'ready', $s['remove_plugin'] );
check( 'deactivate can\'t run again once done', false, npmp_import_hub_can_run( 'deactivate', $s ) );
check( 'remove data refused before the plugin goes', false, npmp_import_hub_can_run( 'remove_data', $s ) );

$s = npmp_import_hub_step_states( array( 'imported' => true, 'verified' => true, 'removal_available' => true ) );
check( 'deleted: remove plugin done', 'done', $s['remove_plugin'] );
check( 'deleted: remove data ready', 'ready', $s['remove_data'] );
check( 'remove data runs now', true, npmp_import_hub_can_run( 'remove_data', $s ) );
check( 'not finished yet', false, npmp_import_hub_finished( $s ) );

$s = npmp_import_hub_step_states( array( 'imported' => true, 'verified' => true, 'data_removed' => true, 'removal_available' => true ) );
check( 'everything done', true, npmp_import_hub_finished( $s ) );
check( 'remove data can\'t run twice', false, npmp_import_hub_can_run( 'remove_data', $s ) );
check( 'current falls on the last step', 'remove_data', npmp_import_hub_current_step( $s ) );

$s = npmp_import_hub_step_states( array( 'imported' => true, 'verified' => true ) );
check( 'no batch: remove data unavailable', 'unavailable', $s['remove_data'] );
check( 'unavailable never runs', false, npmp_import_hub_can_run( 'remove_data', $s ) );

$s = npmp_import_hub_step_states( array( 'has_data' => true, 'can_import' => 'Turn it back on.' ) );
check( 'importer needs the plugin on: blocked', 'blocked', $s['import'] );
check( 'blocked never runs', false, npmp_import_hub_can_run( 'import', $s ) );

$s = npmp_import_hub_step_states( array( 'installed' => true, 'active' => false ) );
check( 'an old plugin already off still waits on the import', 'locked', $s['deactivate'] );

echo "Grouping sources\n";
$g = npmp_import_hub_partition(
	array(
		'tec'      => array( 'installed' => true ),
		'mailpoet' => array(),
		'givewp'   => array( 'has_data' => true ),
		'done'     => array( 'touched' => true ),
	)
);
check( 'installed, data left, or started go on top', array( 'tec', 'givewp', 'done' ), $g['here'] );
check( 'the rest fold away', array( 'mailpoet' ), $g['other'] );

echo "Pro lock\n";
check( 'pro source in free', true, npmp_import_hub_tier_locked( 'pro', false ) );
check( 'pro source in Pro', false, npmp_import_hub_tier_locked( 'pro', true ) );
check( 'free source in free', false, npmp_import_hub_tier_locked( 'free', false ) );

echo "Typed confirmation\n";
check( 'exact', true, npmp_import_hub_confirm_matches( 'DELETE', 'DELETE' ) );
check( 'lowercase with spaces', true, npmp_import_hub_confirm_matches( '  delete ', 'DELETE' ) );
check( 'near miss', false, npmp_import_hub_confirm_matches( 'DELET', 'DELETE' ) );
check( 'empty', false, npmp_import_hub_confirm_matches( '', 'DELETE' ) );
check( 'array', false, npmp_import_hub_confirm_matches( array( 'DELETE' ), 'DELETE' ) );
check( 'empty word never matches', false, npmp_import_hub_confirm_matches( '', '' ) );
check( 'the word is DELETE', 'DELETE', npmp_import_hub_confirm_word() );

echo "Plugins by folder\n";
$plugins = array(
	'mailpoet/mailpoet.php'         => array( 'Name' => 'MailPoet' ),
	'mailpoet-premium/premium.php'  => array( 'Name' => 'MailPoet Premium' ),
	'mailpoetish/x.php'             => array( 'Name' => 'Other' ),
	'mailpoet.php'                  => array( 'Name' => 'Single file' ),
);
check( 'matches folders only', array( 'mailpoet/mailpoet.php' => 'MailPoet', 'mailpoet-premium/premium.php' => 'MailPoet Premium' ), npmp_import_hub_plugins_in_folders( $plugins, array( 'mailpoet', 'mailpoet-premium' ) ) );

echo "TEC options that data removal deletes\n";
$seen_on_tec_6_18 = array(
	'stellar_schema_version_stellarwp-migrations-tec-migration-executions',
	'stellar_schema_version_stellarwp-migrations-tec-migration-logs',
	'stellar_schema_version_stellarwp-shepherd-tec-task-logs',
	'stellar_schema_version_stellarwp-shepherd-tec-tasks',
	'stellar_schema_version_tec-kv-cache',
	'stellarwp_telemetry_the-events-calendar_show_optin',
	'tec_ct1_events_table_schema_version',
	'tec_ct1_migration_state',
	'tec_ct1_occurrences_table_schema_version',
	'tec_events_category_color_css',
	'tec_timed_tec_custom_tables_v1_initialized',
	'tribe_events_calendar_options',
	'tribe_events_cat_children',
	'tribe_last_generate_rewrite_rules',
	'tribe_last_save_post',
	'tribe_last_updated_option',
	'widget_tribe-widget-events-list',
	'widget_tribe-widget-events-qr-code',
	'tribe_customizer',
	'_transient_tribe_events_x',
	'_transient_timeout_tec_foo',
);
check( 'every option TEC 6.18 wrote matches', $seen_on_tec_6_18, array_values( array_filter( $seen_on_tec_6_18, 'npmp_tec_hub_option_is_tec' ) ) );
$ours = array(
	'npmp_tec_imported',
	'npmp_tec_stage_1',
	'npmp_import_hub',
	'npmp_calendar_accent',
	'protected_settings',
	'stellar_schema_version_other-plugin',
	'stellarwp_telemetry_givewp_show_optin',
	'tec',
	'technology_options',
	'tribe',
	'tribes_plugin_options',
	'_transient_npmp_tec_cache',
	'_transient_feed_tribe',
	'widget_text',
	'siteurl',
);
check( 'nothing of ours or anyone else\'s matches', array(), array_values( array_filter( $ours, 'npmp_tec_hub_option_is_tec' ) ) );

echo "TEC cron hooks\n";
check( 'TEC hooks match', 3, count( array_filter( array( 'tribe_daily_cron', 'tribe_schedule_transient_purge', 'tribe_common_log_cleanup' ), 'npmp_tec_hub_cron_is_tec' ) ) );
check( 'others don\'t', 0, count( array_filter( array( 'npmp_tec_cleanup', 'wp_version_check', 'technical_debt', 'tribeca' ), 'npmp_tec_hub_cron_is_tec' ) ) );

echo "Plugins that share TEC's data\n";
check( 'TEC itself', true, npmp_tec_hub_shares_data( 'the-events-calendar/the-events-calendar.php', array( 'Name' => 'The Events Calendar' ) ) );
check( 'Event Tickets', true, npmp_tec_hub_shares_data( 'event-tickets/event-tickets.php', array( 'Name' => 'Event Tickets' ) ) );
check( 'Event Tickets Plus', true, npmp_tec_hub_shares_data( 'event-tickets-plus/event-tickets-plus.php', array( 'Name' => 'Event Tickets Plus' ) ) );
check( 'TEC Pro, an add-on', true, npmp_tec_hub_shares_data( 'events-calendar-pro/events-calendar-pro.php', array( 'Name' => 'The Events Calendar Pro' ) ) );
check( 'an unrelated plugin', false, npmp_tec_hub_shares_data( 'akismet/akismet.php', array( 'Name' => 'Akismet' ) ) );
check( 'Nonprofit Manager', false, npmp_tec_hub_shares_data( 'nonprofit-manager/nonprofit-manager.php', array( 'Name' => 'Nonprofit Manager' ) ) );

echo "TEC's declared tables and post types\n";
check( 'no npmp table', array(), array_values( array_filter( npmp_tec_hub_tables(), static function ( $t ) { return 0 === strpos( $t, 'npmp' ); } ) ) );
check( 'npmp_event is never a TEC post type', false, in_array( 'npmp_event', npmp_tec_hub_post_types(), true ) );
check( 'TEC events are', true, in_array( 'tribe_events', npmp_tec_hub_post_types(), true ) );

echo "Data that comes back after step 5\n";
check( 'finished removal, data back: reset', true, npmp_import_hub_data_returned( array( 'removal' => array( 'started' => 1, 'done' => 2 ) ), true ) );
check( 'finished removal, no data: keep', false, npmp_import_hub_data_returned( array( 'removal' => array( 'started' => 1, 'done' => 2 ) ), false ) );
check( 'removal part way: keep, so the batches carry on', false, npmp_import_hub_data_returned( array( 'removal' => array( 'started' => 1, 'done' => false ) ), true ) );
check( 'never removed: nothing to reset', false, npmp_import_hub_data_returned( array(), true ) );

require_once dirname( __DIR__ ) . '/includes/import/mailpoet-importer.php';
$before = count( $GLOBALS['t_hooks'] );
require_once dirname( __DIR__ ) . '/includes/import/mailpoet-hub-source.php';
$added = array_slice( $GLOBALS['t_hooks'], $before );

echo "MailPoet source\n";
check( 'MailPoet registers one filter', array( 'npmp_import_sources' ), array_column( $added, 0 ) );
$mp = npmp_import_hub_normalize_source( 'mailpoet', npmp_mailpoet_hub_register( array() )['mailpoet'] );
check( 'it normalises', 'mailpoet', $mp['slug'] );
check( 'it is free', 'free', $mp['tier'] );
check( 'one Pro part', 1, count( $mp['pro_parts'] ) );
check( 'data removal is available', true, $mp['data_removal']['available'] );
foreach ( array( 'has_data', 'is_imported', 'can_import', 'counts', 'deactivate', 'active_plugins', 'installed_plugins', 'import_summary' ) as $key ) {
	check( "$key names a real function", true, is_callable( $mp[ $key ] ) );
}
foreach ( array( 'describe', 'blockers', 'batch' ) as $key ) {
	check( "removal $key names a real function", true, is_callable( $mp['data_removal'][ $key ] ) );
}

echo "MailPoet options\n";
// Every option name MailPoet 5.45.2's source writes to wp_options.
$seen_on_mailpoet = array(
	'mailpoet_deferred_admin_notices',
	'mailpoet_admin_footer_text_rated',
	'mailpoet_plugin_activated',
	'mailpoet_cron_deactivating',
	'mailpoet_pages_repair_lock',
	'mailpoet_woo_attribution_writes_started_at',
	'widget_mailpoet_form',
);
check( 'every MailPoet option matches', $seen_on_mailpoet, array_values( array_filter( $seen_on_mailpoet, 'npmp_mailpoet_hub_option_is_mailpoet' ) ) );
// Made-up names in the shapes the matcher covers: its caches and a later version's flags.
$shapes = array( '_transient_mailpoet_x', '_transient_timeout_mailpoet_x', '_site_transient_mailpoet_x', 'mailpoet_new_flag', 'mailpoet-new-flag' );
check( 'transients and flags in MailPoet\'s shape match', $shapes, array_values( array_filter( $shapes, 'npmp_mailpoet_hub_option_is_mailpoet' ) ) );
$not_mailpoet = array(
	'npmp_mailpoet_imported',
	'npmp_mailpoet_stage_1',
	'npmp_mailpoet_notice',
	'npmp_email_settings',
	'npmp_membership_form_settings',
	'npmp_email_from_name',
	'npmp_email_from_email',
	'_transient_npmp_mailpoet_cache',
	'_transient_feed_mailpoet',
	'widget_text',
	'widget_block',
	'sidebars_widgets',
	'my_mailpoet_bridge',
	'mailpoetish',
	'action_scheduler_lock_async-request-runner',
	'',
);
check( 'nothing of ours or anyone else\'s matches', array(), array_values( array_filter( $not_mailpoet, 'npmp_mailpoet_hub_option_is_mailpoet' ) ) );

echo "MailPoet tables\n";
check( 'subscribers', true, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoet_subscribers', 'wp_' ) );
check( 'subscriber_segment', true, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoet_subscriber_segment', 'wp_' ) );
check( 'automation_run_logs', true, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoet_automation_run_logs', 'wp_' ) );
check( 'another site\'s table on multisite', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_2_mailpoet_subscribers', 'wp_' ) );
check( 'this subsite\'s own table', true, npmp_mailpoet_hub_table_is_mailpoet( 'wp_2_mailpoet_subscribers', 'wp_2_' ) );
check( 'Action Scheduler', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_actionscheduler_actions', 'wp_' ) );
check( 'our tables', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_npmp_donations', 'wp_' ) );
check( 'a look-alike without the underscore', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoetx', 'wp_' ) );
check( 'the bare prefix', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoet_', 'wp_' ) );
check( 'odd characters', false, npmp_mailpoet_hub_table_is_mailpoet( 'wp_mailpoet_x`; DROP', 'wp_' ) );
check( 'an empty prefix', false, npmp_mailpoet_hub_table_is_mailpoet( 'mailpoet_subscribers', '' ) );

echo "MailPoet post types\n";
check( 'no npmp post type', array(), array_values( array_filter( npmp_mailpoet_hub_post_types(), static function ( $t ) { return 0 === strpos( $t, 'npmp' ); } ) ) );
check( 'pages and emails', array( 'mailpoet_page', 'mailpoet_email' ), npmp_mailpoet_hub_post_types() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
