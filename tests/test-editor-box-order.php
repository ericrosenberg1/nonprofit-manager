<?php
/**
 * Our editor boxes stay in the sidebar for a user whose saved box order puts
 * them under the content (includes/npmp-editor-boxes.php).
 *
 * do_meta_boxes() re-adds each ID in meta-box-order_<screen> in its saved
 * area, so an order saved before 2026.11.8, when Event Details and Pro's
 * Scheduled Emails sat in `normal`, kept them in the block editor's "Meta
 * Boxes" panel (Pete at U3A, 2026-11-13). Rig: tests/rig/editor-box-order.php.
 *
 * Run: php tests/test-editor-box-order.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_filters'] = array();
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
}
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$rest );
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/npmp-editor-boxes.php';

echo "The filter is on both editors\n";
check( 'event editor', true, in_array( 'npmp_filter_sidebar_box_order', $GLOBALS['t_filters']['get_user_option_meta-box-order_npmp_event'] ?? array(), true ) );
check( 'newsletter editor', true, in_array( 'npmp_filter_sidebar_box_order', $GLOBALS['t_filters']['get_user_option_meta-box-order_npmp_newsletter'] ?? array(), true ) );

echo "\nPete's order from before 2026.11.8\n";
$stale = array(
	'side'     => 'npmp_social_share_box,cookie_banner_box',
	'normal'   => 'npmp-pro-event-scheduled-emails,npmp-event-details,other_plugin_box',
	'advanced' => 'npmp-event-scheduled-email',
);
// Pro adds its own ID through the filter.
add_filter(
	'npmp_sidebar_box_ids',
	function ( $ids, $screen ) {
		if ( 'npmp_event' === $screen ) {
			$ids[] = 'npmp-pro-event-scheduled-emails';
		}
		return $ids;
	}
);
$fixed = npmp_filter_sidebar_box_order( $stale, 'meta-box-order_npmp_event' );
check( 'our boxes leave normal, another plugin\'s stays', 'other_plugin_box', $fixed['normal'] );
check( 'our boxes leave advanced', '', $fixed['advanced'] );
check( 'the sidebar order is untouched', $stale['side'], $fixed['side'] );

echo "\nAnything else passes through\n";
check( 'no saved order', false, npmp_filter_sidebar_box_order( false, 'meta-box-order_npmp_event' ) );
check( 'another screen', $stale, npmp_filter_sidebar_box_order( $stale, 'meta-box-order_post' ) );
check( 'an order with none of ours', array( 'normal' => 'a,b' ), npmp_filter_sidebar_box_order( array( 'normal' => 'a,b' ), 'meta-box-order_npmp_event' ) );
$nl = npmp_filter_sidebar_box_order( array( 'side' => '', 'normal' => 'npmp_newsletter_template,x' ), 'meta-box-order_npmp_newsletter' );
check( 'newsletter: our template box leaves normal', 'x', $nl['normal'] );

echo "\nEvery sidebar box we add to these editors is on the list\n";
// A new box that misses the list would land under the content again for
// anyone with an old saved order.
$root  = dirname( __DIR__ ) . '/includes';
$found = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $path ) {
	if ( '.php' !== substr( (string) $path, -4 ) ) {
		continue;
	}
	$src = (string) file_get_contents( (string) $path );
	if ( preg_match_all( "/add_meta_box\(\s*'([^']+)'[^;]*?'(npmp_event|npmp_newsletter)'/s", $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$found[ $hit[2] ][] = $hit[1];
		}
	}
}
check( 'the scan finds event boxes', true, ! empty( $found['npmp_event'] ) );
check( 'the scan finds newsletter boxes', true, ! empty( $found['npmp_newsletter'] ) );
foreach ( $found as $screen => $ids ) {
	foreach ( $ids as $id ) {
		check( "$screen: $id", true, in_array( $id, npmp_sidebar_box_ids( $screen ), true ) );
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
