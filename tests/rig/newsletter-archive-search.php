<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/newsletter-archive-search.php
//
// Real-WordPress check of the Newsletter Archive screen's search and sort
// (npmp_render_newsletter_archive() in includes/email-newsletter/editor.php),
// the bulk actions that keep the view, and the "Newsletter" entry under + New
// in the admin bar.
//
// It seeds 45 newsletters with repeated sent dates, recipient counts and
// public flags (so ties are common), renders the screen as an administrator,
// and compares every sort, both directions, across all three pages with an
// order worked out here in PHP. Needs the newsletters module on and the
// members module on (npmp_enabled_features). Free only, Pro may be on or off.
// Deletes what it made. Not run by the pre-push gate (needs MySQL).
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
$GLOBALS['rig_errors'] = array();
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-76s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

class Rig_Stop extends Error { public $kind; public $payload; }
function rig_stop( $kind, $payload = null ) {
	$e = new Rig_Stop( $kind );
	$e->kind = $kind;
	$e->payload = $payload;
	throw $e;
}
add_filter( 'wp_die_handler', function () { return function ( $msg ) { rig_stop( 'die', $msg ); }; } );
add_filter( 'wp_redirect', function ( $location ) { rig_stop( 'redirect', $location ); }, 1 );

/** Run a callable with request superglobals set. Returns kind, output and redirect. */
function rig_run( $callable, $post = array(), $get = array() ) {
	$_POST    = wp_slash( $post );
	$_GET     = wp_slash( $get );
	$_REQUEST = array_merge( $_GET, $_POST );
	$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
	ob_start();
	$kind = 'returned';
	$to   = null;
	try {
		call_user_func( $callable );
	} catch ( Rig_Stop $e ) {
		$kind = $e->kind;
		$to   = 'redirect' === $kind ? $e->payload : null;
	}
	$out = ob_get_clean();
	$_POST = $_GET = $_REQUEST = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	return array( 'kind' => $kind, 'out' => $out, 'to' => $to );
}

function rig_user( $login, $role ) {
	$id = username_exists( $login );
	if ( ! $id ) {
		$id = wp_create_user( $login, 'rig-pass-' . wp_generate_password( 8 ), $login . '@example.org' );
	}
	( new WP_User( $id ) )->set_role( $role );
	return (int) $id;
}

/** Query args of a redirect URL, as an array. */
function rig_query( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}

/** The screen, as an administrator. Returns the newsletter IDs in the order shown, and the HTML. */
function rig_screen( array $get ) {
	$r = rig_run( 'npmp_render_newsletter_archive', array(), array_merge( array( 'page' => 'npmp_newsletter_archive' ), $get ) );
	preg_match_all( '/name="npmp_public_ids\[\]" value="(\d+)"/', $r['out'], $m );
	return array( array_map( 'intval', $m[1] ), $r['out'] );
}

/** Every page of a view, in order. */
function rig_all_pages( array $get ) {
	$ids = array();
	for ( $p = 1; $p <= 4; $p++ ) {
		list( $page_ids ) = rig_screen( $p > 1 ? array_merge( $get, array( 'paged' => $p ) ) : $get );
		$ids = array_merge( $ids, $page_ids );
	}
	return $ids;
}

if ( ! function_exists( 'npmp_render_newsletter_archive' ) ) {
	echo "  FAIL  the newsletters module must be on (npmp_enabled_features)\n";
	exit( 1 );
}

global $wpdb;
// phpcs:disable WordPress.DB -- Throwaway rig.
foreach ( get_posts( array( 'post_type' => 'npmp_newsletter', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $old ) {
	wp_delete_post( $old, true );
}
$admin = rig_user( 'rig_nl_admin', 'administrator' );
$staff = rig_user( 'rig_nl_staff', 'npmp_membership_manager' );
$auth  = rig_user( 'rig_nl_author', 'author' );
$subs  = rig_user( 'rig_nl_subscriber', 'subscriber' );
wp_set_current_user( $admin );

// ---------------------------------------------------------------------------
// Seed 45 newsletters.
// ---------------------------------------------------------------------------
echo "Seeding\n";
$words = array( 'Autumn', 'spring', 'Winter', 'summer', 'Harvest', 'budget', 'Gala', 'annual', 'Volunteer' );
$seed  = array();
for ( $i = 1; $i <= 45; $i++ ) {
	$sent  = 0 !== $i % 5;
	$title = $words[ $i % 9 ] . ' ' . sprintf( '%02d', $i ) . ( 0 === $i % 9 ? ' Giraffe' : '' );
	$id    = wp_insert_post(
		array(
			'post_type'    => 'npmp_newsletter',
			'post_status'  => $sent ? 'publish' : 'draft',
			'post_title'   => $title,
			'post_content' => 'Body ' . $i . ( 1 === $i % 10 ? ' mentions a zebra crossing' : '' ),
			'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 09:00:00' ) + $i * 86400 ),
		)
	);
	$row = array( 'id' => $id, 'title' => $title, 'date' => get_post_field( 'post_date', $id ), 'sent' => '', 'rec' => 0, 'public' => 0 );
	if ( $sent ) {
		$row['sent'] = gmdate( 'Y-m-d H:i:s', strtotime( '2025-03-01 08:00:00' ) + ( ( $i * 7 ) % 40 ) * 86400 );
		$row['rec']  = ( $i * 37 ) % 6 * 100;
		update_post_meta( $id, '_npmp_newsletter_status', 'sent' );
		update_post_meta( $id, '_npmp_newsletter_queued_at', $row['sent'] );
		update_post_meta( $id, '_npmp_newsletter_recipient_total', $row['rec'] );
		if ( 0 === $i % 3 ) {
			update_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META, '1' );
			$row['public'] = 1;
		} elseif ( 0 === $i % 7 ) {
			update_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META, '0' );
		}
	}
	$seed[ $id ] = $row;
}
rig_check( 'seeded 45 newsletters', 45, count( $seed ) );

/** The order a sort should give, with the screen's tie-break (newest, then highest ID). */
function rig_expected( array $seed, $key, $dir, $cmp ) {
	$rows = array_values( $seed );
	usort( $rows, function ( $a, $b ) use ( $key, $dir, $cmp ) {
		$c = $cmp( $a[ $key ], $b[ $key ] );
		if ( 'desc' === $dir ) {
			$c = -$c;
		}
		return $c ?: ( strcmp( $b['date'], $a['date'] ) ?: $b['id'] <=> $a['id'] );
	} );
	return array_map( function ( $r ) { return $r['id']; }, $rows );
}
$cmp_str = function ( $a, $b ) { return strcasecmp( $a, $b ); };
$cmp_num = function ( $a, $b ) { return $a <=> $b; };

// ---------------------------------------------------------------------------
// Default view, and pages.
// ---------------------------------------------------------------------------
echo "Default view\n";
list( $page1, $html ) = rig_screen( array() );
rig_check( 'page 1 shows 20 rows', 20, count( $page1 ) );
rig_check( 'default order is newest first', rig_expected( $seed, 'date', 'desc', $cmp_str ), rig_all_pages( array() ) );
rig_check( 'page 3 holds the last 5', 5, count( rig_screen( array( 'paged' => 3 ) )[0] ) );
rig_check( 'search box prints', true, false !== strpos( $html, 'id="npmp-archive-search"' ) );
rig_check( 'Clear link hidden on the plain view', false, false !== strpos( $html, '>Clear<' ) );
rig_check( 'no column marked sorted on the plain view', false, false !== strpos( $html, 'sorted ' ) );

// ---------------------------------------------------------------------------
// Every sortable column, both directions, across all pages.
// ---------------------------------------------------------------------------
echo "Sort\n";
$cols = array(
	'title'      => array( 'title', $cmp_str ),
	'sent'       => array( 'sent', $cmp_str ),
	'recipients' => array( 'rec', $cmp_num ),
	'public'     => array( 'public', $cmp_num ),
);
foreach ( $cols as $col => $def ) {
	foreach ( array( 'asc', 'desc' ) as $dir ) {
		$got = rig_all_pages( array( 'orderby' => $col, 'order' => $dir ) );
		rig_check( "$col $dir: 45 rows, none lost or doubled", 45, count( array_unique( $got ) ) );
		rig_check( "$col $dir: order matches", rig_expected( $seed, $def[0], $dir, $def[1] ), $got );
	}
}
rig_check( 'no SQL error after the sorts', '', $wpdb->last_error );

list( , $html ) = rig_screen( array( 'orderby' => 'title', 'order' => 'asc' ) );
rig_check( 'sorted column carries sorted asc and aria-sort', true, false !== strpos( $html, 'sorted asc' ) && false !== strpos( $html, 'aria-sort="ascending"' ) );
rig_check( 'its link flips to desc', true, false !== strpos( $html, 'orderby=title&#038;order=desc' ) );
rig_check( 'another column starts on its own first direction (sent: desc)', true, false !== strpos( $html, 'orderby=sent&#038;order=desc' ) );
rig_check( 'Clear link shows on a sorted view', true, false !== strpos( $html, '>Clear<' ) );

echo "Bad input\n";
foreach ( array(
	array( 'orderby' => 'post_content', 'order' => 'asc' ),
	array( 'orderby' => 'title; DROP TABLE wp_posts', 'order' => 'asc' ),
	array( 'orderby' => 'title', 'order' => 'sideways' ),
	array( 'orderby' => array( 'title' ), 'order' => array( 'asc' ) ),
	array( 's' => array( 'zebra' ), 'npmp_topic' => array( 'x' ), 'paged' => 'abc' ),
) as $i => $bad ) {
	list( $ids ) = rig_screen( $bad );
	rig_check( "bad input #$i renders 20 rows with no SQL error", true, 20 === count( $ids ) && '' === $wpdb->last_error );
}
rig_check( 'order=sideways falls back to the column default (asc)', rig_expected( $seed, 'title', 'asc', $cmp_str ), rig_all_pages( array( 'orderby' => 'title', 'order' => 'sideways' ) ) );
rig_check( 'posts table still there', true, (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->posts}'" ) );

// ---------------------------------------------------------------------------
// Search.
// ---------------------------------------------------------------------------
echo "Search\n";
$zebra = array_keys( array_filter( $seed, function ( $r ) { return false !== strpos( get_post_field( 'post_content', $r['id'] ), 'zebra' ); } ) );
list( $ids, $html ) = rig_screen( array( 's' => 'zebra' ) );
sort( $ids );
sort( $zebra );
rig_check( 'search finds a word that is only in the body', $zebra, $ids );
rig_check( 'the match count prints', true, false !== strpos( $html, count( $zebra ) . ' newsletters match' ) );
$giraffe = array_keys( array_filter( $seed, function ( $r ) { return false !== stripos( $r['title'], 'giraffe' ); } ) );
list( $ids ) = rig_screen( array( 's' => 'GIRAFFE' ) );
sort( $ids );
sort( $giraffe );
rig_check( 'search is not case sensitive and reads titles', $giraffe, $ids );
list( $ids, $html ) = rig_screen( array( 's' => 'nothing-here-xyz' ) );
rig_check( 'no match shows the empty message', true, array() === $ids && false !== strpos( $html, 'No newsletters match these filters.' ) );
list( $ids, $html ) = rig_screen( array( 's' => '<script>alert(1)</script>' ) );
rig_check( 'a script in the search box is escaped on screen', false, false !== strpos( $html, '<script>alert(1)</script>' ) );

$want = array_values( array_intersect( rig_expected( $seed, 'rec', 'desc', $cmp_num ), $zebra ) );
list( $ids ) = rig_screen( array( 's' => 'zebra', 'orderby' => 'recipients', 'order' => 'desc' ) );
rig_check( 'search and sort together', $want, $ids );
list( $ids, $html ) = rig_screen( array( 's' => 'zebra', 'orderby' => 'recipients', 'order' => 'desc' ) );
rig_check( 'the sort links keep the search', true, false !== strpos( $html, 's=zebra&#038;orderby=title' ) || false !== strpos( $html, 'orderby=title&#038;order=asc&#038;s=zebra' ) );
rig_check( 'the search form keeps the sort', true, false !== strpos( $html, 'name="orderby" value="recipients"' ) );

// Paged search keeps the search in the pager.
$many = array_keys( array_filter( $seed, function ( $r ) { return false !== stripos( $r['title'], 'a' ); } ) );
list( $ids, $html ) = rig_screen( array( 's' => 'Body', 'orderby' => 'title', 'order' => 'asc' ) );
rig_check( 'a search that spans pages shows 20 then pages on', 20, count( $ids ) );
rig_check( 'the pager keeps search and sort', true, false !== strpos( $html, 'paged=2' ) && false !== strpos( $html, 's=Body' ) && false !== strpos( $html, 'orderby=title' ) );

// Topic and status filters still work with a search and a sort.
$topic = wp_insert_term( 'Rig Topic', 'npmp_newsletter_topic' );
$tid   = is_wp_error( $topic ) ? get_term_by( 'name', 'Rig Topic', 'npmp_newsletter_topic' )->term_id : $topic['term_id'];
$tagged = array_slice( array_keys( $seed ), 0, 6 );
foreach ( $tagged as $pid ) {
	wp_set_object_terms( $pid, array( (int) $tid ), 'npmp_newsletter_topic' );
}
list( $ids ) = rig_screen( array( 'npmp_topic' => 'rig-topic', 'orderby' => 'title', 'order' => 'desc' ) );
rig_check( 'topic filter with a sort', array_values( array_intersect( rig_expected( $seed, 'title', 'desc', $cmp_str ), $tagged ) ), $ids );
list( $ids ) = rig_screen( array( 'npmp_status' => 'draft' ) );
rig_check( 'status filter: 9 drafts', 9, count( $ids ) );

// ---------------------------------------------------------------------------
// Bulk actions keep the view.
// ---------------------------------------------------------------------------
echo "Bulk actions from a searched and sorted view\n";
$view = array( 's' => 'Body', 'orderby' => 'recipients', 'order' => 'desc', 'paged' => '2' );
list( $shown ) = rig_screen( $view );
$pick = array_slice( $shown, 0, 3 );
list( , $html ) = rig_screen( $view );
preg_match_all( '/name="npmp_view\[(\w+)\]" value="([^"]*)"/', $html, $m );
$fields = array_combine( $m[1], $m[2] );
rig_check( 'the bulk form carries search, sort and page', array( 's' => 'Body', 'orderby' => 'recipients', 'order' => 'desc', 'paged' => '2' ), $fields );
preg_match( '/name="npmp_public_nonce" value="([^"]+)"/', $html, $nm );
$nonce = $nm[1];

// Hide.
foreach ( $pick as $pid ) {
	update_post_meta( $pid, NPMP_NEWSLETTER_PUBLIC_META, '1' );
}
$r = rig_run(
	'npmp_newsletter_public_bulk_post',
	array( 'action' => 'npmp_newsletter_public_bulk', 'npmp_public_nonce' => $nonce, '_wp_http_referer' => '/', 'npmp_public_action' => 'npmp_public_hide', 'npmp_public_ids' => $pick, 'npmp_view' => $fields )
);
$q = rig_query( $r['to'] );
rig_check( 'Hide redirects back to the archive screen', true, false !== strpos( (string) $r['to'], 'page=npmp_newsletter_archive' ) );
rig_check( 'Hide keeps search, sort and page in the redirect', array( 'Body', 'recipients', 'desc', '2', 'hide', '3' ), array( $q['s'] ?? null, $q['orderby'] ?? null, $q['order'] ?? null, $q['paged'] ?? null, $q['npmp_public_done'] ?? null, $q['npmp_public_n'] ?? null ) );
$still = 0;
foreach ( $pick as $pid ) {
	$still += '1' === get_post_meta( $pid, NPMP_NEWSLETTER_PUBLIC_META, true ) ? 1 : 0;
}
rig_check( 'Hide changed the three picked newsletters', 0, $still );

// Show, with the confirmation step.
$to_show = array_values( array_filter( $pick, function ( $pid ) use ( $seed ) { return '' !== $seed[ $pid ]['sent']; } ) );
$r = rig_run(
	'npmp_newsletter_public_bulk_post',
	array( 'action' => 'npmp_newsletter_public_bulk', 'npmp_public_nonce' => $nonce, '_wp_http_referer' => '/', 'npmp_public_action' => 'npmp_public_show', 'npmp_public_ids' => $to_show, 'npmp_view' => $fields )
);
$q = rig_query( $r['to'] );
rig_check( 'Show asks for confirmation and keeps the view', array( '1', 'Body', 'recipients', 'desc', '2' ), array( $q['npmp_public_confirm'] ?? null, $q['s'] ?? null, $q['orderby'] ?? null, $q['order'] ?? null, $q['paged'] ?? null ) );
rig_check( 'nothing changed before the confirmation', 0, count( array_filter( $to_show, function ( $pid ) { return '1' === get_post_meta( $pid, NPMP_NEWSLETTER_PUBLIC_META, true ); } ) ) );
$confirm = rig_run( 'npmp_newsletter_public_bulk_notices', array(), $q );
preg_match_all( '/name="npmp_view\[(\w+)\]" value="([^"]*)"/', $confirm['out'], $m2 );
rig_check( 'the confirmation form carries the view', array( 's' => 'Body', 'orderby' => 'recipients', 'order' => 'desc', 'paged' => '2' ), array_combine( $m2[1], $m2[2] ) );
rig_check( 'its Cancel link returns to the same view', true, false !== strpos( $confirm['out'], 'orderby=recipients' ) && false !== strpos( $confirm['out'], 's=Body' ) );
preg_match( '/name="npmp_public_nonce" value="([^"]+)"/', $confirm['out'], $nm2 );
$r = rig_run(
	'npmp_newsletter_public_bulk_post',
	array( 'action' => 'npmp_newsletter_public_bulk', 'npmp_public_nonce' => $nm2[1], '_wp_http_referer' => '/', 'npmp_public_confirmed' => '1', 'npmp_view' => array_combine( $m2[1], $m2[2] ) )
);
$q = rig_query( $r['to'] );
rig_check( 'Confirmed Show returns to the view with the done notice', array( 'show', 'Body', 'recipients', 'desc', '2' ), array( $q['npmp_public_done'] ?? null, $q['s'] ?? null, $q['orderby'] ?? null, $q['order'] ?? null, $q['paged'] ?? null ) );
rig_check( 'Show changed the sent ones', count( $to_show ), count( array_filter( $to_show, function ( $pid ) { return '1' === get_post_meta( $pid, NPMP_NEWSLETTER_PUBLIC_META, true ); } ) ) );

// A post with a bad nonce, and junk in npmp_view.
$r = rig_run( 'npmp_newsletter_public_bulk_post', array( 'npmp_public_nonce' => 'bad', 'npmp_public_action' => 'npmp_public_hide', 'npmp_public_ids' => $pick ) );
rig_check( 'a bad nonce is refused', 'die', $r['kind'] );
$r = rig_run(
	'npmp_newsletter_public_bulk_post',
	array( 'action' => 'npmp_newsletter_public_bulk', 'npmp_public_nonce' => $nonce, '_wp_http_referer' => '/', 'npmp_public_action' => 'npmp_public_hide', 'npmp_public_ids' => array( $pick[0] ), 'npmp_view' => array( 'orderby' => 'x;y', 'order' => 'q', 's' => '<b>hi</b>', 'evil' => 'http://evil.example', 'paged' => '-5' ) )
);
$q = rig_query( $r['to'] );
rig_check( 'junk in npmp_view is dropped or cleaned', array( null, null, null, 'hi' ), array( $q['orderby'] ?? null, $q['order'] ?? null, $q['evil'] ?? null, $q['s'] ?? null ) );

// A user without the send capability gets no checkboxes and no handler.
wp_set_current_user( $staff );
$r = rig_run( 'npmp_newsletter_public_bulk_post', array( 'npmp_public_nonce' => $nonce, 'npmp_public_action' => 'npmp_public_hide', 'npmp_public_ids' => $pick ) );
wp_set_current_user( $admin );

// ---------------------------------------------------------------------------
// Admin bar: + New.
// ---------------------------------------------------------------------------
echo "Admin bar\n";
function rig_bar( $user_id ) {
	wp_set_current_user( $user_id );
	$GLOBALS['wp_admin_bar'] = null;
	$bar = new WP_Admin_Bar();
	$bar->initialize();
	$bar->add_menus();
	do_action_ref_array( 'admin_bar_menu', array( &$bar ) );
	return $bar;
}
$new_url = admin_url( 'post-new.php?post_type=npmp_newsletter' );

$bar  = rig_bar( $admin );
$node = $bar->get_node( 'new-npmp_newsletter' );
rig_check( 'administrator: + New lists Newsletter', 'Newsletter', $node ? wp_strip_all_tags( $node->title ) : null );
rig_check( 'administrator: it opens the new newsletter screen', $new_url, $node ? $node->href : null );
rig_check( 'administrator: Event is in the menu (it already was)', true, null !== $bar->get_node( 'new-npmp_event' ) );
rig_check( 'administrator: Newsletter sits under + New', 'new-content', $node ? $node->parent : null );

$bar  = rig_bar( $staff );
$node = $bar->get_node( 'new-npmp_newsletter' );
rig_check( 'Membership Manager: + New lists Newsletter', 'Newsletter', $node ? wp_strip_all_tags( $node->title ) : null );
rig_check( 'Membership Manager: same link', $new_url, $node ? $node->href : null );
rig_check( 'Membership Manager: no Event (calendar work needs edit_posts)', null, $bar->get_node( 'new-npmp_event' ) );
rig_check( 'Membership Manager: no Post, Page, Media, or User', array( null, null, null, null ), array( $bar->get_node( 'new-post' ), $bar->get_node( 'new-page' ), $bar->get_node( 'new-media' ), $bar->get_node( 'new-user' ) ) );
rig_check( 'Membership Manager: the rest of the bar is still trimmed (WordPress logo gone)', null, $bar->get_node( 'wp-logo' ) );

$bar  = rig_bar( $auth );
rig_check( 'Author: Newsletter and Event both listed', array( true, true ), array( null !== $bar->get_node( 'new-npmp_newsletter' ), null !== $bar->get_node( 'new-npmp_event' ) ) );
$bar  = rig_bar( $subs );
rig_check( 'Subscriber: no + New at all', null, $bar->get_node( 'new-content' ) );

// The link works for the staff role: the new-newsletter screen allows them.
wp_set_current_user( $staff );
rig_check( 'Membership Manager may create a newsletter', true, current_user_can( get_post_type_object( 'npmp_newsletter' )->cap->create_posts ) );
wp_set_current_user( $admin );

// ---------------------------------------------------------------------------
// Clean up.
// ---------------------------------------------------------------------------
foreach ( array_keys( $seed ) as $pid ) {
	wp_delete_post( $pid, true );
}
wp_delete_term( (int) $tid, 'npmp_newsletter_topic' );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $staff, $auth, $subs, $admin ) as $uid ) {
	wp_delete_user( $uid );
}

echo "\nPHP notices from this plugin: " . ( $GLOBALS['rig_errors'] ? implode( ' | ', array_unique( $GLOBALS['rig_errors'] ) ) : 'none' ) . "\n";
printf( "%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] || $GLOBALS['rig_errors'] ? 1 : 0 );
