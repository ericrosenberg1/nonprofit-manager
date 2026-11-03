<?php
/**
 * The hooks Pro's groups and admin print build on (the list in the build
 * notes, npm-groups-hooks.md). Free ships no group logic, so these hooks are
 * the whole contract, and Pro breaks without a word if a refactor drops one.
 *
 * Pinned here: each hook is still fired with do_action() or apply_filters()
 * inside the function the list names, the greyed Pro controls still hang off
 * the hooks Pro uses, and each greyed control still links to pricing with its
 * own campaign. The real-WordPress check that each one fires with its
 * documented arguments is tests/rig/groups-hooks.php.
 *
 * Run: php tests/test-groups-hooks.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

// hook => [ 'action'|'filter', file, function that fires it ].
$hooks = array(
	'npmp_member_edit_rows'            => array( 'action', 'includes/npmp-members-settings.php', 'npmp_render_member_detail_view' ),
	'npmp_member_saved'                => array( 'action', 'includes/npmp-members-settings.php', 'npmp_handle_member_list_actions' ),
	'npmp_members_handle_bulk_action'  => array( 'filter', 'includes/npmp-members-settings.php', 'npmp_handle_member_list_actions' ),
	'npmp_members_list_query_args'     => array( 'filter', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_list_url_args'       => array( 'filter', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_filter_bar'          => array( 'action', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_list_columns'        => array( 'filter', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_list_column'         => array( 'action', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_bulk_controls'       => array( 'action', 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'npmp_members_bulk_actions'        => array( 'filter', 'includes/npmp-members-settings.php', 'npmp_members_bulk_actions' ),
	'npmp_members_query_args'          => array( 'filter', 'includes/npmp-email-settings.php', 'build_query_args' ),
	'npmp_event_details_fields'        => array( 'action', 'includes/npmp-calendar.php', 'npmp_render_event_meta_box' ),
	'npmp_event_meta_saved'            => array( 'action', 'includes/npmp-calendar.php', 'npmp_save_event_meta' ),
	'npmp_calendar_shortcode_defaults' => array( 'filter', 'includes/npmp-calendar.php', 'npmp_calendar_shortcode_defaults' ),
	'npmp_events_shortcode_defaults'   => array( 'filter', 'includes/npmp-calendar.php', 'npmp_events_shortcode_defaults' ),
	'npmp_events_query_args'           => array( 'filter', 'includes/npmp-calendar.php', 'npmp_events_filter_query_args' ),
	'npmp_calendar_before_grid'        => array( 'action', 'includes/npmp-calendar.php', 'npmp_calendar_shortcode' ),
	'npmp_calendar_block_atts'         => array( 'filter', 'includes/npmp-calendar-blocks.php', 'npmp_calendar_block_render' ),
	'npmp_events_block_atts'           => array( 'filter', 'includes/npmp-calendar-blocks.php', 'npmp_events_block_render' ),
	'npmp_newsletter_audience_fields'  => array( 'action', 'includes/email-newsletter/editor.php', 'npmp_newsletter_send_controls_html' ),
	'npmp_newsletter_audience_saved'   => array( 'action', 'includes/email-newsletter/editor.php', 'npmp_newsletter_store_meta' ),
	'npmp_newsletter_mail_headers'     => array( 'filter', 'includes/email-newsletter/class-newsletter-manager.php', 'send_email' ),
	'npmp_newsletter_footer_links'     => array( 'filter', 'includes/email-newsletter/can-spam-shortcode.php', 'npmp_can_spam_shortcode' ),
	'npmp_signup_form_default_atts'    => array( 'filter', 'includes/npmp-membership-forms.php', 'npmp_signup_form_default_atts' ),
	'npmp_signup_form_after_fields'    => array( 'filter', 'includes/npmp-membership-forms.php', 'npmp_email_signup_shortcode' ),
	'npmp_after_email_signup'          => array( 'action', 'includes/npmp-membership-forms.php', 'npmp_handle_membership_form' ),
	'npmp_email_signup_block_atts'     => array( 'filter', 'includes/npmp-content-blocks.php', 'npmp_email_signup_block_render' ),
	'npmp_preferences_form_fields'     => array( 'action', 'includes/npmp-subscription-preferences.php', 'npmp_manage_preferences_shortcode' ),
	'npmp_preferences_saved'           => array( 'action', 'includes/npmp-subscription-preferences.php', 'npmp_manage_preferences_shortcode' ),
	'npmp_import_field_labels'         => array( 'filter', 'includes/import/class-import-manager.php', 'get_field_labels' ),
	'npmp_import_header_synonyms'      => array( 'filter', 'includes/import/class-import-manager.php', 'header_synonyms' ),
	'npmp_import_row_imported'         => array( 'action', 'includes/import/class-import-manager.php', 'import_single_record' ),
);

// The admin table toolbar fires once per screen, each with its source key.
$toolbars = array(
	'members'            => array( 'includes/npmp-members-settings.php', 'npmp_render_member_list_table' ),
	'donations'          => array( 'includes/npmp-payments-settings.php', 'npmp_render_donations_dashboard' ),
	'events'             => array( 'includes/npmp-calendar.php', 'npmp_render_events_dashboard' ),
	'newsletter_reports' => array( 'includes/email-newsletter/reports.php', 'npmp_render_newsletter_reports' ),
	'overview'           => array( 'includes/npmp-admin-settings.php', 'npmp_render_main_plugin_page' ),
	'scheduled_emails'   => array( 'includes/npmp-scheduled-emails.php', 'npmp_render_scheduled_emails_page' ),
);

// Queries that must run through npmp_events_filter_query_args().
$event_queries = array(
	array( 'includes/npmp-calendar.php', 'npmp_calendar_query_events_by_day' ),
	array( 'includes/npmp-calendar.php', 'npmp_maybe_render_ical_feed' ),
	array( 'includes/npmp-events-loop.php', 'npmp_events_loop_query_args' ),
);

// Greyed control => [ hook it hangs off, campaign ].
$teasers = array(
	'npmp_pro_teaser_member_groups_row'   => array( 'npmp_member_edit_rows', 'groups' ),
	'npmp_pro_teaser_members_group_filter' => array( 'npmp_members_filter_bar', 'groups' ),
	'npmp_pro_teaser_newsletter_groups'   => array( 'npmp_newsletter_audience_fields', 'groups' ),
	'npmp_pro_teaser_event_group'         => array( 'npmp_event_details_fields', 'groups' ),
	'npmp_pro_teaser_admin_print'         => array( 'npmp_admin_table_toolbar', 'admin_print' ),
);

/**
 * Source of one named function or method, found with the tokenizer so braces
 * inside strings don't count.
 *
 * @param string $path File.
 * @param string $name Function or method name.
 * @return string '' when the file has no such function.
 */
function t_function_source( $path, $name ) {
	static $cache = array();
	if ( ! isset( $cache[ $path ] ) ) {
		$cache[ $path ] = is_readable( $path ) ? token_get_all( file_get_contents( $path ) ) : array();
	}
	$tokens = $cache[ $path ];
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || $name !== $tokens[ $j ][1] ) {
			continue;
		}
		$out   = '';
		$depth = 0;
		$open  = false;
		for ( $k = $j; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			$out .= $text;
			if ( '{' === $text || ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$depth++;
				$open = true;
			} elseif ( '}' === $text ) {
				$depth--;
				if ( $open && 0 === $depth ) {
					return $out;
				}
			}
		}
		return $out;
	}
	return '';
}

/**
 * Whether a source fires a hook by its literal name.
 *
 * @param string $src  Source.
 * @param string $kind 'action' or 'filter'.
 * @param string $hook Hook name.
 * @return bool
 */
function t_fires( $src, $kind, $hook ) {
	$fn = 'action' === $kind ? 'do_action' : 'apply_filters';
	return (bool) preg_match( '/\b' . $fn . '\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $src );
}

echo "Each hook fires where the list says\n";
foreach ( $hooks as $hook => $where ) {
	list( $kind, $rel, $function ) = $where;
	$src = t_function_source( $root . '/' . $rel, $function );
	check( "$hook ($kind) in $function()", true, '' !== $src && t_fires( $src, $kind, $hook ) );
}

echo "\nThe admin table toolbar\n";
foreach ( $toolbars as $source => $where ) {
	$src = t_function_source( $root . '/' . $where[0], $where[1] );
	check( "npmp_admin_table_toolbar '$source' in {$where[1]}()", true, (bool) preg_match( "/do_action\(\s*'npmp_admin_table_toolbar',\s*'" . $source . "'/", $src ) );
}
$all_events = t_function_source( $root . '/includes/npmp-calendar.php', 'npmp_events_list_table_toolbar' );
check( "npmp_admin_table_toolbar 'events' above the All Events list", true, (bool) preg_match( "/do_action\(\s*'npmp_admin_table_toolbar',\s*'events'/", $all_events ) );
check( 'the All Events toolbar hangs off manage_posts_extra_tablenav', true, false !== strpos( file_get_contents( $root . '/includes/npmp-calendar.php' ), "add_action( 'manage_posts_extra_tablenav', 'npmp_events_list_table_toolbar' )" ) );

echo "\nEvery front-end event query goes through npmp_events_query_args\n";
foreach ( $event_queries as $where ) {
	$src = t_function_source( $root . '/' . $where[0], $where[1] );
	check( "{$where[1]}() calls npmp_events_filter_query_args()", true, false !== strpos( $src, 'npmp_events_filter_query_args(' ) );
}

echo "\nGreyed Pro controls\n";
$teaser_src = file_get_contents( $root . '/includes/npmp-pro-feature-pages.php' );
foreach ( $teasers as $function => $info ) {
	list( $hook, $campaign ) = $info;
	$src = t_function_source( $root . '/includes/npmp-pro-feature-pages.php', $function );
	check( "$function hangs off $hook", true, false !== strpos( $teaser_src, "add_action( '$hook', '$function' )" ) );
	check( "$function steps aside when Pro is active", true, (bool) preg_match( '/if \( npmp_is_pro\(\) \) \{\s*return;/', $src ) );
	check( "$function links once, with campaign $campaign", 1, substr_count( $src, "npmp_get_upgrade_url( '$campaign' )" ) );
	check( "$function prints a disabled control", true, false !== strpos( $src, 'disabled' ) );
}
check( 'the shared greyed-control styles are in the admin stylesheet', true, false !== strpos( $teaser_src, '.npmp-pro-locked {' ) && false !== strpos( $teaser_src, 'a.npmp-pro-badge:hover' ) );

echo "\nThe hook list in the notes matches\n";
$notes = getenv( 'NPMP_GROUPS_HOOKS_MD' );
if ( $notes && is_readable( $notes ) ) {
	$md = file_get_contents( $notes );
	foreach ( array_keys( $hooks ) as $hook ) {
		check( "$hook is in the notes", true, false !== strpos( $md, '`' . $hook . '`' ) );
	}
} else {
	echo "  (skipped: set NPMP_GROUPS_HOOKS_MD to the notes file to compare)\n";
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
