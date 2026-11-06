<?php
/**
 * Real-WordPress check of the hooks Pro's groups and admin print build on,
 * and of the greyed Pro controls a free site shows in their place.
 *
 * With Pro inactive it renders each admin screen in an admin context and
 * checks the greyed control and its one upgrade link. Then it hooks each new
 * action and filter from this script and drives the real code path (member
 * save, bulk action, event save, signup, preferences save, import rows, the
 * calendar and events queries, a newsletter queued and sent with mail caught
 * by pre_wp_mail) to prove each fires with the documented arguments.
 *
 * Run inside a throwaway WordPress with members, newsletters, donations and
 * the calendar on, Pro inactive (CLAUDE.md, "Testing beyond unit tests"):
 *   wp eval-file tests/rig/groups-hooks.php
 * It deletes every event, newsletter and contact first.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( npmp_is_pro() ) {
	echo "  FAIL  Pro must be inactive for this rig\n";
	exit( 1 );
}
if ( ! class_exists( 'NPMP_Member_Manager' ) || ! post_type_exists( 'npmp_event' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) || ! function_exists( 'npmp_render_donations_dashboard' ) ) {
	echo "  FAIL  members, newsletters, donations and calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}

// An admin context, as the screens expect.
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
// WP-CLI warns with a backtrace on every redirect. This rig catches them.
remove_all_filters( 'wp_redirect' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

global $wpdb;
$queue = $wpdb->prefix . 'npmp_newsletter_queue';
// phpcs:disable WordPress.DB -- Throwaway rig.
$wpdb->query( "DELETE FROM {$queue}" );
foreach ( get_posts( array( 'post_type' => array( 'npmp_event', 'npmp_newsletter', 'npmp_contact' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $rig_id ) {
	wp_delete_post( $rig_id, true );
}
wp_cache_flush();

/**
 * Thrown from wp_redirect so a handler that redirects and exits returns here.
 */
class NPMP_Rig_Redirect extends Exception {}
$catch_redirect = static function ( $location ) {
	throw new NPMP_Rig_Redirect( (string) $location );
};
$run = static function ( callable $fn ) use ( $catch_redirect ) {
	add_filter( 'wp_redirect', $catch_redirect, 999 );
	$where = '';
	try {
		$fn();
	} catch ( NPMP_Rig_Redirect $e ) {
		$where = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $catch_redirect, 999 );
	return $where;
};
$capture = static function ( callable $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
};
$reset_request = static function () {
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
};
// One upgrade link per greyed control, with the campaign named.
$links = static function ( $html, $campaign ) {
	return substr_count( $html, 'utm_campaign=' . $campaign . '"' ) + substr_count( $html, 'utm_campaign=' . $campaign . '&' );
};

$members = NPMP_Member_Manager::get_instance();
$ann     = $members->add_member( array( 'email' => 'ann@example.org', 'name' => 'Ann', 'status' => 'subscribed' ) );
$bob     = $members->add_member( array( 'email' => 'bob@example.org', 'name' => 'Bob', 'status' => 'subscribed' ) );

echo "Greyed Pro controls on a free site\n";

$reset_request();
$_GET = array( 'page' => 'npmp_members', 'action' => 'new' );
set_current_screen( 'membership_page_npmp_members' );
$html = $capture( 'npmp_render_members_page' );
$ok( 'contact screen shows a greyed Groups row', false !== strpos( $html, 'id="npmp-member-groups-locked" disabled' ) );
$ok( 'the Groups row has one upgrade link (campaign groups)', 1 === $links( $html, 'groups' ) );
$ok( 'the Groups row sits between Tags and Source', strpos( $html, 'npmp-member-tags' ) < strpos( $html, 'npmp-member-groups-locked' ) && strpos( $html, 'npmp-member-groups-locked' ) < strpos( $html, 'npmp-member-source' ) );

$reset_request();
$_GET = array( 'page' => 'npmp_members' );
$html = $capture( 'npmp_render_members_page' );
$ok( 'Member List shows a greyed Group filter', false !== strpos( $html, 'id="npmp-member-group-locked" disabled' ) );
$ok( 'the Group filter has one upgrade link', 1 === $links( $html, 'groups' ) );
$ok( 'Member List shows the greyed print button', false !== strpos( $html, 'data-source="members"' ) );
$ok( 'the print button has one upgrade link (campaign admin_print)', 1 === $links( $html, 'admin_print' ) );
$ok( 'no Bulk actions menu while nothing adds one', false === strpos( $html, 'npmp-members-bulk-action' ) );

$event = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Art Night' ) );
update_post_meta( $event, '_npmp_event_start', wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) . ' 19:00:00' );
$html = $capture(
	static function () use ( $event ) {
		npmp_render_event_meta_box( get_post( $event ) );
	}
);
$ok( 'Event Details shows a greyed Group picker', false !== strpos( $html, 'id="npmp-event-group-locked" disabled' ) );
$ok( 'the Group picker comes after the Event Link field', strpos( $html, 'npmp_event_url' ) < strpos( $html, 'npmp-event-group-locked' ) );
$ok( 'the Group picker has one upgrade link', 1 === $links( $html, 'groups' ) );

$nl   = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Hello', 'post_content' => '<p>News for you.</p>' ) );
$html = $capture(
	static function () use ( $nl ) {
		npmp_newsletter_send_controls_html( get_post( $nl ) );
	}
);
$ok( 'newsletter Audience box shows a greyed Groups picker', (bool) preg_match( '#npmp-newsletter-audience-extra.*type="checkbox" disabled.*Send to one or more groups#s', $html ) );
$ok( 'the Groups picker is inside the Audience fieldset', strpos( $html, 'npmp-newsletter-audience-extra' ) < strpos( $html, '</fieldset>' ) );
$ok( 'the Groups picker has one upgrade link', 1 === $links( $html, 'groups' ) );

$screens = array(
	'donations'          => 'npmp_render_donations_dashboard',
	'events'             => 'npmp_render_events_dashboard',
	'newsletter_reports' => 'npmp_render_newsletter_reports',
	'overview'           => 'npmp_render_main_plugin_page',
	'scheduled_emails'   => 'npmp_render_scheduled_emails_page',
);
foreach ( $screens as $source => $render ) {
	$reset_request();
	$html = $capture( $render );
	$ok( "$source screen shows the greyed print button", false !== strpos( $html, 'data-source="' . $source . '"' ) && 1 === $links( $html, 'admin_print' ) );
}
// The reports screen draws its table once a newsletter exists, and the one
// above does, so the button sits above a real table.
$GLOBALS['typenow'] = 'npmp_event';
$html               = $capture(
	static function () {
		do_action( 'manage_posts_extra_tablenav', 'top' );
		do_action( 'manage_posts_extra_tablenav', 'bottom' );
	}
);
$ok( 'All Events list shows the print button once, in the top tablenav', 1 === substr_count( $html, 'data-source="events"' ) );
$GLOBALS['typenow'] = 'post';
$html               = $capture(
	static function () {
		do_action( 'manage_posts_extra_tablenav', 'top' );
	}
);
$ok( 'the Posts list gets no print button', false === strpos( $html, 'npmp-admin-print-locked' ) );

$html = $capture(
	static function () {
		do_action( 'admin_head' );
	}
);
$ok( 'admin stylesheet carries the greyed-control rules', false !== strpos( $html, '.npmp-pro-locked' ) && false !== strpos( $html, 'a.npmp-pro-badge:hover' ) );

global $submenu;
$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rig builds the menu fresh.
do_action( 'admin_menu', '' );
$under = array();
foreach ( (array) ( $submenu['npmp_membership'] ?? array() ) as $item ) {
	$under[] = $item[2];
}
$ok( 'a Groups entry sits under the Membership menu', in_array( 'npmp_groups', $under, true ) );
$ok( 'Groups is not under the main menu', ! in_array( 'npmp_groups', wp_list_pluck( (array) ( $submenu['npmp_main'] ?? array() ), 2 ), true ) );
$reset_request();
$_GET = array( 'page' => 'npmp_groups' );
$html = $capture( 'npmp_render_pro_feature_page' );
$ok( 'the Groups page says what groups do in one line', 1 === substr_count( $html, '<p style="font-size:14px;margin:0 0 1em;">' ) && false !== strpos( $html, 'Groups come with Nonprofit Manager Pro.' ) );
$ok( 'the Groups page links to pricing with campaign groups', 1 === $links( $html, 'groups' ) );

echo "\nMember screens\n";

$seen = array();
add_action(
	'npmp_member_edit_rows',
	static function ( $id, $member ) use ( &$seen ) {
		$seen['rows'] = array( $id, is_object( $member ) ? $member->email : null );
		echo '<tr><th>RIG</th><td>rig-row</td></tr>';
	},
	10,
	2
);
add_action(
	'npmp_member_saved',
	static function ( $type, $id, $data = null, $request = null ) use ( &$seen ) {
		$seen['saved'][] = array( $type, $id, $data, $request );
	},
	10,
	4
);
$reset_request();
$_GET = array( 'page' => 'npmp_members', 'action' => 'view', 'id' => $ann );
$html = $capture( 'npmp_render_members_page' );
$ok( 'npmp_member_edit_rows gets the contact ID and record', array( $ann, 'ann@example.org' ) === ( $seen['rows'] ?? null ) );
$ok( 'its rows render inside the edit table', false !== strpos( $html, 'rig-row' ) && strpos( $html, 'rig-row' ) < strpos( $html, '</table>' ) );

$reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET                      = array( 'page' => 'npmp_members' );
$_POST                     = array(
	'npmp_members_action' => 'create',
	'npmp_members_nonce'  => wp_create_nonce( 'npmp_manage_members' ),
	'member'              => array( 'name' => 'Cat', 'email' => 'cat@example.org', 'status' => 'subscribed' ),
	'npmp_groups'         => array( '7', '9' ),
);
$where = $run( 'npmp_handle_member_list_actions' );
$cat   = $members->get_member_by_email( 'cat@example.org' );
$last  = end( $seen['saved'] );
$ok( 'creating a contact redirects to it', false !== strpos( $where, 'message=created' ) );
$ok( 'npmp_member_saved gets the new contact ID', $cat && (int) $cat->id === $last[1] && 'member' === $last[0] );
$ok( 'npmp_member_saved gets the saved fields', is_array( $last[2] ) && 'cat@example.org' === $last[2]['email'] );
$ok( 'npmp_member_saved gets the request with Pro inputs', is_array( $last[3] ) && array( '7', '9' ) === $last[3]['npmp_groups'] );

$_POST['npmp_members_action'] = 'update';
$_POST['member_id']           = (string) $cat->id;
$_POST['member']['name']      = 'Cat B';
$run( 'npmp_handle_member_list_actions' );
$last = end( $seen['saved'] );
$ok( 'updating fires npmp_member_saved with the data too', (int) $cat->id === $last[1] && 'Cat B' === $last[2]['name'] );

add_filter(
	'npmp_members_list_query_args',
	static function ( $args, $request ) use ( &$seen ) {
		$seen['list_args'] = array( $args, $request );
		if ( ! empty( $request['group'] ) ) {
			$args['group'] = sanitize_key( $request['group'] );
		}
		return $args;
	},
	10,
	2
);
add_filter(
	'npmp_members_query_args',
	static function ( $query, $args ) use ( &$seen, $ann ) {
		$seen['query_args'][] = $args;
		if ( 'art' === ( $args['group'] ?? '' ) ) {
			$query['post__in'] = array( $ann );
		}
		return $query;
	},
	10,
	2
);
add_filter(
	'npmp_members_list_url_args',
	static function ( $base ) use ( &$seen ) {
		$base['group'] = 'art';
		return $base;
	}
);
add_action(
	'npmp_members_filter_bar',
	static function ( $request ) use ( &$seen ) {
		$seen['filter_bar'] = $request;
		echo '<select name="group" id="rig-group"></select>';
	}
);
add_filter(
	'npmp_members_list_columns',
	static function ( $cols ) {
		$cols['groups'] = 'Rig Groups';
		return $cols;
	}
);
add_action(
	'npmp_members_list_column',
	static function ( $key, $member ) {
		echo esc_html( 'cell-' . $key . '-' . $member->id );
	},
	10,
	2
);
add_filter(
	'npmp_members_bulk_actions',
	static function ( $actions ) {
		$actions['add_to_group'] = 'Add to group';
		return $actions;
	}
);
add_action(
	'npmp_members_bulk_controls',
	static function () {
		echo '<select name="rig_bulk_group"></select>';
	}
);
add_action(
	'npmp_admin_table_toolbar',
	static function ( $source, $context = array() ) use ( &$seen ) {
		$seen['toolbar'][ $source ] = $context;
	},
	10,
	2
);
$reset_request();
$_GET = array( 'page' => 'npmp_members', 'group' => 'art' );
$html = $capture( 'npmp_render_members_page' );
$ok( 'npmp_members_list_query_args gets the list args and the request', isset( $seen['list_args'][0]['per_page'] ) && 'art' === $seen['list_args'][1]['group'] );
$ok( 'npmp_members_query_args receives group from the list', 'art' === ( end( $seen['query_args'] )['group'] ?? '' ) );
$ok( 'a group filter narrows the list to that group', false !== strpos( $html, 'ann@example.org' ) && false === strpos( $html, 'bob@example.org' ) );
$ok( 'npmp_members_filter_bar renders inside the filter form', false !== strpos( $html, 'id="rig-group"' ) && strpos( $html, 'rig-group' ) < strpos( $html, 'npmp-members-select-all' ) && 'art' === $seen['filter_bar']['group'] );
$ok( 'an extra column gets a header and a cell per row', false !== strpos( $html, '<th>Rig Groups</th>' ) && false !== strpos( $html, 'cell-groups-' . $ann ) );
$ok( 'a bulk action adds the menu, its controls and Apply', false !== strpos( $html, '<option value="add_to_group">Add to group</option>' ) && false !== strpos( $html, 'rig_bulk_group' ) && false !== strpos( $html, 'name="npmp_members_bulk_apply"' ) );
$ok( 'the members toolbar gets the query args and request', isset( $seen['toolbar']['members']['query_args']['group'] ) && 'art' === $seen['toolbar']['members']['request']['group'] );
$ok( 'members of a group come back from get_members()', array( $ann ) === wp_list_pluck( $members->get_members( array( 'group' => 'art', 'per_page' => -1 ) ), 'id' ) );
$ok( 'and count_members() counts them', 1 === $members->count_members( array( 'group' => 'art' ) ) );

add_filter(
	'npmp_members_handle_bulk_action',
	static function ( $count, $action, $ids, $request ) use ( &$seen ) {
		$seen['bulk'] = array( $action, $ids, $request['rig_bulk_group'] ?? '' );
		return count( $ids );
	},
	10,
	4
);
$reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET                      = array( 'page' => 'npmp_members' );
$_POST                     = array(
	'npmp_members_action'      => 'bulk_delete',
	'npmp_members_nonce'       => wp_create_nonce( 'npmp_manage_members' ),
	'npmp_members_bulk_action' => 'add_to_group',
	'npmp_members_bulk_apply'  => '1',
	'member_ids'               => array( (string) $ann, (string) $bob ),
	'rig_bulk_group'           => '7',
);
$where = $run( 'npmp_handle_member_list_actions' );
$ok( 'Apply runs the picked bulk action with the IDs and request', array( 'add_to_group', array( $ann, $bob ), '7' ) === ( $seen['bulk'] ?? null ) );
$ok( 'Apply deletes nobody', null !== $members->get_member_by_id( $ann ) && null !== $members->get_member_by_id( $bob ) );
$ok( 'Apply comes back with "2 contacts updated"', false !== strpos( $where, 'message=bulk_applied' ) && false !== strpos( $where, 'deleted=2' ) );
$_POST['npmp_members_bulk_action'] = 'bulk_delete';
$seen['bulk']                      = null;
$run( 'npmp_handle_member_list_actions' );
$ok( 'Apply with an unknown action does nothing', null === $seen['bulk'] && null !== $members->get_member_by_id( $ann ) );

echo "\nEvents\n";

add_action(
	'npmp_event_details_fields',
	static function ( $post ) use ( &$seen ) {
		$seen['event_fields'] = $post->ID;
	}
);
add_action(
	'npmp_event_meta_saved',
	static function ( $id, $details, $request ) use ( &$seen ) {
		$seen['event_saved'] = array( $id, $details, $request['npmp_event_groups'] ?? null );
	},
	10,
	3
);
$capture(
	static function () use ( $event ) {
		npmp_render_event_meta_box( get_post( $event ) );
	}
);
$ok( 'npmp_event_details_fields gets the event', $event === ( $seen['event_fields'] ?? 0 ) );
$reset_request();
$day   = wp_date( 'Y-m-d', time() + 4 * DAY_IN_SECONDS );
$_POST = array(
	'npmp_event_details_nonce' => wp_create_nonce( 'npmp_save_event_details' ),
	'npmp_event_start_date'    => $day,
	'npmp_event_start_time'    => '18:30',
	'npmp_event_location'      => 'Hall',
	'npmp_event_url'           => 'https://example.org/e',
	'npmp_event_groups'        => array( '3' ),
);
npmp_save_event_meta( $event );
$ok(
	'npmp_event_meta_saved gets the ID, saved details and request',
	array( $event, array( 'start' => $day . ' 18:30:00', 'end' => '', 'location' => 'Hall', 'url' => 'https://example.org/e' ), array( '3' ) ) === ( $seen['event_saved'] ?? null )
);
$_POST['npmp_event_details_nonce'] = 'bad';
$seen['event_saved']               = null;
npmp_save_event_meta( $event );
$ok( 'a bad nonce saves nothing and fires nothing', null === $seen['event_saved'] );
$reset_request();

$other = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Book Club' ) );
update_post_meta( $other, '_npmp_event_start', $day . ' 20:00:00' );

add_filter(
	'npmp_calendar_shortcode_defaults',
	static function ( $d ) {
		$d['group'] = '';
		return $d;
	}
);
add_filter(
	'npmp_events_shortcode_defaults',
	static function ( $d ) {
		$d['group'] = '';
		return $d;
	}
);
$seen['queries'] = array();
add_filter(
	'npmp_events_query_args',
	static function ( $args, $context ) use ( &$seen, $event ) {
		$seen['queries'][] = $context;
		if ( 'art' === ( $context['atts']['group'] ?? '' ) ) {
			$args['post__in'] = array( $event );
		}
		return $args;
	},
	10,
	2
);
add_action(
	'npmp_calendar_before_grid',
	static function ( $context ) use ( &$seen ) {
		$seen['before_grid'][] = $context;
		echo '<div class="rig-group-boxes"></div>';
	}
);
$_GET = array( 'npmp_date' => $day );
$html = do_shortcode( '[npmp_calendar group="art"]' );
$ctx  = end( $seen['queries'] );
$ok( 'month query gets source calendar, view month, and the group attribute', 'calendar' === $ctx['source'] && 'month' === $ctx['view'] && 'art' === $ctx['atts']['group'] );
$ok( 'the filter narrows the month grid', false !== strpos( $html, 'Art Night' ) && false === strpos( $html, 'Book Club' ) );
$bg = end( $seen['before_grid'] );
$ok( 'npmp_calendar_before_grid gets the context with start and end', 'calendar' === $bg['source'] && 'art' === $bg['atts']['group'] && $bg['start'] > 0 && $bg['end'] > $bg['start'] );
$ok( 'its markup sits between the toolbar and the grid', strpos( $html, 'npmp-calendar-toolbar' ) < strpos( $html, 'rig-group-boxes' ) && strpos( $html, 'rig-group-boxes' ) < strpos( $html, 'npmp-calendar-table' ) );

$html = do_shortcode( '[npmp_calendar view="week" group="art"]' );
$ctx  = end( $seen['queries'] );
$ok( 'week query says view week', 'week' === $ctx['view'] && 'art' === $ctx['atts']['group'] && false === strpos( $html, 'Book Club' ) );

$html = do_shortcode( '[npmp_calendar view="list" group="art"]' );
$ctx  = end( $seen['queries'] );
$ok( 'list view query says calendar, view list, with the group', 'calendar' === $ctx['source'] && 'list' === $ctx['view'] && 'art' === $ctx['atts']['group'] );
$ok( 'the calendar list view is narrowed too', false !== strpos( $html, 'Art Night' ) && false === strpos( $html, 'Book Club' ) && false !== strpos( $html, 'rig-group-boxes' ) );

$html = do_shortcode( '[npmp_events group="art"]' );
$ctx  = end( $seen['queries'] );
$ok( '[npmp_events] query says events and carries the group', 'events' === $ctx['source'] && 'art' === $ctx['atts']['group'] && false === $ctx['block'] );
$ok( '[npmp_events group] is narrowed', false !== strpos( $html, 'Art Night' ) && false === strpos( $html, 'Book Club' ) );
$html = do_shortcode( '[npmp_events]' );
$ok( 'without the attribute both events show', false !== strpos( $html, 'Art Night' ) && false !== strpos( $html, 'Book Club' ) );
$html = do_shortcode( '[npmp_calendar nonsense="x"]' );
$ctx  = end( $seen['queries'] );
$ok( 'an attribute nobody registered is still dropped', ! isset( $ctx['atts']['nonsense'] ) );

add_filter(
	'npmp_calendar_block_atts',
	static function ( $atts, $attributes ) {
		if ( ! empty( $attributes['group'] ) ) {
			$atts['group'] = $attributes['group'];
		}
		return $atts;
	},
	10,
	2
);
add_filter(
	'npmp_events_block_atts',
	static function ( $atts, $attributes ) {
		if ( ! empty( $attributes['group'] ) ) {
			$atts['group'] = $attributes['group'];
		}
		return $atts;
	},
	10,
	2
);
$html = npmp_calendar_block_render( array( 'group' => 'art' ) );
$ok( 'the Calendar block passes a group through npmp_calendar_block_atts', 'art' === end( $seen['queries'] )['atts']['group'] && false === strpos( $html, 'Book Club' ) );
$html = npmp_events_block_render( array( 'group' => 'art' ) );
$ctx  = end( $seen['queries'] );
$ok( 'the Events block passes it through npmp_events_block_atts', 'art' === $ctx['atts']['group'] && true === $ctx['block'] && false === strpos( $html, 'Book Club' ) );
$_GET = array();

echo "\nSignup form\n";

add_filter(
	'npmp_signup_form_default_atts',
	static function ( $d ) {
		$d['groups'] = '';
		return $d;
	}
);
add_filter(
	'npmp_signup_form_after_fields',
	static function ( $fields, $atts = array() ) use ( &$seen ) {
		$seen['signup_fields'] = $atts;
		return $fields;
	},
	10,
	2
);
add_action(
	'npmp_after_email_signup',
	static function ( $id, $post, $atts = null, $is_new = null ) use ( &$seen ) {
		$seen['signup'][] = array( $id, $post['npmp_email'] ?? '', $atts, $is_new );
	},
	10,
	4
);
$html = do_shortcode( '[npmp_email_signup groups="art,history"]' );
preg_match( '#name="npmp_signup_atts" value="([^"]+)"#', $html, $m );
$token = $m[1] ?? '';
$ok( 'the form carries its attributes in a signed hidden field', '' !== $token && false !== strpos( $token, '.' ) );
$ok( 'npmp_signup_form_after_fields gets the attributes', array( 'groups' => 'art,history' ) === ( $seen['signup_fields'] ?? null ) );
$ok( 'a form without attributes has no hidden field', false === strpos( do_shortcode( '[npmp_email_signup]' ), 'npmp_signup_atts' ) );
$block = npmp_email_signup_block_render( array( 'groups' => 'art' ) );
$ok( 'the Email Signup block adds nothing without npmp_email_signup_block_atts', false === strpos( $block, 'npmp_signup_atts' ) );
add_filter(
	'npmp_email_signup_block_atts',
	static function ( $atts, $attributes ) {
		$atts['groups'] = $attributes['groups'] ?? '';
		return $atts;
	},
	10,
	2
);
$block = npmp_email_signup_block_render( array( 'groups' => 'art' ) );
$ok( 'and passes its attributes through it', false !== strpos( $block, 'npmp_signup_atts' ) );

$signup = static function ( $email, $atts_token ) use ( $run, $reset_request ) {
	$reset_request();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['REMOTE_ADDR']    = '203.0.113.' . wp_rand( 2, 250 );
	$_POST                     = array(
		'npmp_action'             => 'email_signup',
		'npmp_email_signup_nonce' => wp_create_nonce( 'npmp_email_signup' ),
		'npmp_name'               => 'Dee',
		'npmp_email'              => $email,
		'npmp_signup_atts'        => $atts_token,
		'npmp_group_choice'       => array( 'art' ),
	);
	return $run( 'npmp_handle_membership_form' );
};
$where = $signup( 'dee@example.org', $token );
$dee   = $members->get_member_by_email( 'dee@example.org' );
$last  = end( $seen['signup'] );
$ok( 'signup succeeds', false !== strpos( $where, 'npmp_signup=success' ) && $dee );
$ok( 'npmp_after_email_signup gets the new contact ID and the request', $dee && (int) $dee->id === $last[0] && 'dee@example.org' === $last[1] );
$ok( 'and the form attributes back from the signed field', array( 'groups' => 'art,history' ) === $last[2] );
$ok( 'and is_new true for a new contact', true === $last[3] );
$signup( 'dee@example.org', $token );
$last = end( $seen['signup'] );
$ok( 'a returning subscriber fires it with is_new false', (int) $dee->id === $last[0] && false === $last[3] );
$tampered = preg_replace( '/.$/', '0' === substr( $token, -1 ) ? '1' : '0', $token );
$signup( 'eve@example.org', $tampered );
$last = end( $seen['signup'] );
$ok( 'a tampered field gives the defaults, not the visitor\'s groups', array( 'groups' => '' ) === $last[2] );

echo "\nPreferences page\n";

add_action(
	'npmp_preferences_form_fields',
	static function ( $id, $email ) use ( &$seen ) {
		$seen['pref_fields'] = array( $id, $email );
		echo '<input type="checkbox" name="rig_pref_group" value="1">';
	},
	10,
	2
);
add_action(
	'npmp_preferences_saved',
	static function ( $id, $request, $email ) use ( &$seen ) {
		$seen['pref_saved'] = array( $id, $request['rig_pref_group'] ?? null, $email );
	},
	10,
	3
);
$reset_request();
$_GET = array(
	'email' => 'ann@example.org',
	'token' => npmp_generate_preferences_token( 'ann@example.org' ),
);
$html = do_shortcode( '[npmp_manage_preferences]' );
$ok( 'npmp_preferences_form_fields gets the contact and email', array( $ann, 'ann@example.org' ) === ( $seen['pref_fields'] ?? null ) );
$ok( 'its fields render inside the form, before Save', strpos( $html, 'rig_pref_group' ) < strpos( $html, 'npmp_save_preferences' ) );
$_POST = array(
	'npmp_save_preferences' => '1',
	'_wpnonce'              => wp_create_nonce( 'npmp_manage_prefs_' . $ann ),
	'rig_pref_group'        => '1',
);
do_shortcode( '[npmp_manage_preferences]' );
$ok( 'npmp_preferences_saved gets the contact, request and email', array( $ann, '1', 'ann@example.org' ) === ( $seen['pref_saved'] ?? null ) );
$reset_request();

echo "\nImport\n";

$import = NPMP_Import_Manager::get_instance();
$ok( 'free maps a Groups header to Tags', array( 0 => 'email', 1 => 'tags' ) === $import->detect_columns( array( 'Email', 'Groups' ) ) );
add_filter(
	'npmp_import_field_labels',
	static function ( $labels ) {
		$labels['groups'] = 'Groups';
		return $labels;
	}
);
add_filter(
	'npmp_import_header_synonyms',
	static function ( $syn ) {
		$syn['tags']   = array_values( array_diff( $syn['tags'], array( 'groups', 'group' ) ) );
		$syn['groups'] = array( 'groups', 'group' );
		return $syn;
	}
);
$ok( 'npmp_import_field_labels adds a mappable field', 'Groups' === ( $import->get_field_labels()['groups'] ?? '' ) );
$ok( 'npmp_import_header_synonyms moves the Groups header', array( 0 => 'email', 1 => 'groups' ) === $import->detect_columns( array( 'Email', 'Groups' ) ) );
add_action(
	'npmp_import_row_imported',
	static function ( $id, $record, $row, $mapping, $status, $options ) use ( &$seen ) {
		$seen['import'][] = array( $id, $record['groups'] ?? null, $row, $mapping, $status, $options['duplicate_handling'] ?? '' );
	},
	10,
	6
);
$mapping = array( 0 => 'email', 1 => 'name', 2 => 'groups' );
$rows    = array( array( 'fay@example.org', 'Fay', 'art, history' ), array( 'not-an-email', 'Bad', 'art' ) );
$stats   = $import->process_rows( $rows, $mapping, array( 'duplicate_handling' => 'skip' ) );
$fay     = $members->get_member_by_email( 'fay@example.org' );
$ok( 'the import still counts one imported and one error', 1 === (int) $stats['imported'] && 1 === count( (array) $stats['errors'] ) );
$ok( 'npmp_import_row_imported fires once, for the good row only', 1 === count( $seen['import'] ) );
$first = $seen['import'][0];
$ok( 'it gets the contact ID, the groups value, the row and the mapping', $fay && array( (int) $fay->id, 'art, history', $rows[0], $mapping, 'imported', 'skip' ) === $first );
$import->process_rows( array( $rows[0] ), $mapping, array( 'duplicate_handling' => 'skip' ) );
$ok( 'a skipped duplicate fires with status skipped', 'skipped' === end( $seen['import'] )[4] && (int) $fay->id === end( $seen['import'] )[0] );
$import->process_rows( array( $rows[0] ), $mapping, array( 'duplicate_handling' => 'update' ) );
$ok( 'an updated duplicate fires with status updated', 'updated' === end( $seen['import'] )[4] );

echo "\nNewsletter audience and send path\n";

add_action(
	'npmp_newsletter_audience_fields',
	static function ( $post ) use ( &$seen ) {
		$seen['aud_fields'] = $post->ID;
	}
);
add_action(
	'npmp_newsletter_audience_saved',
	static function ( $id, $levels, $request ) use ( &$seen ) {
		$seen['aud_saved'] = array( $id, $levels, $request['npmp_newsletter_groups'] ?? null );
	},
	10,
	3
);
$capture(
	static function () use ( $nl ) {
		npmp_newsletter_send_controls_html( get_post( $nl ) );
	}
);
$ok( 'npmp_newsletter_audience_fields gets the newsletter', $nl === ( $seen['aud_fields'] ?? 0 ) );
$_POST = array( 'npmp_newsletter_groups' => array( '4' ) );
npmp_newsletter_store_meta( $nl, array( '__all__' ), '' );
// "All members" saves as an empty level list.
$ok( 'npmp_newsletter_audience_saved gets the ID, levels and request', array( $nl, array(), array( '4' ) ) === ( $seen['aud_saved'] ?? null ) );
$reset_request();

add_filter(
	'npmp_newsletter_mail_headers',
	static function ( $headers, $id, $email ) use ( &$seen ) {
		$seen['headers'][] = array( $id, $email );
		$headers[]         = 'Reply-To: Art Group <art@example.org>';
		return $headers;
	},
	10,
	3
);
add_filter(
	'npmp_newsletter_footer_links',
	static function ( $html, $id, $email ) use ( &$seen ) {
		$seen['footer'][] = array( $id, $email );
		return $html . '<a href="https://example.org/leave?e=' . rawurlencode( $email ) . '">Leave Art Group emails</a>';
	},
	10,
	3
);
$caught = array();
$catch  = static function ( $null, $atts ) use ( &$caught ) {
	$caught[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $catch, 10, 2 );
$queued = NPMP_Newsletter_Manager::queue_newsletter( $nl );
NPMP_Newsletter_Manager::process_queue();
remove_filter( 'pre_wp_mail', $catch, 10 );

$to = array();
foreach ( $caught as $mail ) {
	$to[] = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : $mail['to'];
}
sort( $to );
$expected = array( 'ann@example.org', 'bob@example.org', 'cat@example.org', 'dee@example.org', 'eve@example.org', 'fay@example.org' );
$ok( 'queue_newsletter() plus process_queue() mails every subscribed member', $expected === $to && count( $expected ) === $queued );
$pairs = array();
foreach ( (array) ( $seen['headers'] ?? array() ) as $pair ) {
	$pairs[] = $pair[1];
}
sort( $pairs );
$ok( 'npmp_newsletter_mail_headers runs once per message with the newsletter ID and recipient', $expected === $pairs && array( $nl ) === array_values( array_unique( wp_list_pluck( $seen['headers'], 0 ) ) ) );
$ann_mail = null;
foreach ( $caught as $mail ) {
	if ( 'ann@example.org' === ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ) ) {
		$ann_mail = $mail;
	}
}
$headers = $ann_mail ? implode( "\n", (array) $ann_mail['headers'] ) : '';
$ok( 'the added Reply-To header goes out', false !== strpos( $headers, 'Reply-To: Art Group <art@example.org>' ) );
$ok( 'List-Unsubscribe is still there', false !== strpos( $headers, 'List-Unsubscribe:' ) );
$ok( 'npmp_newsletter_footer_links gets the newsletter ID and recipient', in_array( array( $nl, 'ann@example.org' ), (array) ( $seen['footer'] ?? array() ), true ) );
$ok( 'the footer link lands in that recipient\'s message', $ann_mail && false !== strpos( $ann_mail['message'], 'leave?e=ann%40example.org' ) && false !== strpos( $ann_mail['message'], 'npmp-footer-links' ) );
$ok( 'the extra link sits after the footer text', $ann_mail && strrpos( $ann_mail['message'], 'npmp-footer-links' ) > (int) strpos( $ann_mail['message'], 'News for you.' ) );

$caught         = array();
$seen['footer'] = array();
add_filter( 'pre_wp_mail', $catch, 10, 2 );
NPMP_Newsletter_Manager::send_test_email( $nl, 'tester@example.org' );
remove_filter( 'pre_wp_mail', $catch, 10 );
$ok( 'a test send passes the newsletter ID and the tester\'s address too', array( $nl, 'tester@example.org' ) === end( $seen['headers'] ) && array( $nl, 'tester@example.org' ) === end( $seen['footer'] ) );

echo "\nToolbar context\n";
$reset_request();
$capture( 'npmp_render_donations_dashboard' );
$ok( 'donations toolbar gets year and month', isset( $seen['toolbar']['donations']['year'], $seen['toolbar']['donations']['month'] ) );
$capture( 'npmp_render_events_dashboard' );
$ok( 'events overview toolbar says view overview', 'overview' === ( $seen['toolbar']['events']['view'] ?? '' ) );
$GLOBALS['typenow'] = 'npmp_event';
$_GET               = array( 'post_status' => 'publish' );
$capture(
	static function () {
		do_action( 'manage_posts_extra_tablenav', 'top' );
	}
);
$ok( 'All Events toolbar says view all with the request', 'all' === ( $seen['toolbar']['events']['view'] ?? '' ) && 'publish' === $seen['toolbar']['events']['request']['post_status'] );
$reset_request();
$capture( 'npmp_render_newsletter_reports' );
$ok( 'newsletter_reports toolbar fires', isset( $seen['toolbar']['newsletter_reports'] ) );
$capture( 'npmp_render_main_plugin_page' );
$ok( 'overview toolbar gets the enabled features', ! empty( $seen['toolbar']['overview']['features']['members'] ) );
$capture( 'npmp_render_scheduled_emails_page' );
$ok( 'scheduled_emails toolbar fires with a context array', isset( $seen['toolbar']['scheduled_emails'] ) && is_array( $seen['toolbar']['scheduled_emails'] ) );

echo "\nGroup-only newsletters without Pro\n";
$group_nl = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Art group news' ) );
update_post_meta( $group_nl, '_npmp_newsletter_groups', array( 999 ) );
$ok( 'a group-only newsletter has no recipients when Pro can\'t resolve groups', array() === NPMP_Newsletter_Manager::get_recipient_list( $group_nl ) );
add_filter( 'npmp_newsletter_groups_supported', '__return_true' );
$ok( 'with group support declared, free hands Pro the subscribed list to filter', count( NPMP_Newsletter_Manager::get_recipient_list( $group_nl ) ) > 0 );
remove_filter( 'npmp_newsletter_groups_supported', '__return_true' );
wp_delete_post( $group_nl, true );

printf( "\n%d failed\n", $fails );
exit( $fails ? 1 : 0 );
