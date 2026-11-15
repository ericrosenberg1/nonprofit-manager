<?php
/**
 * File path: includes/npmp-editor-boxes.php
 *
 * Keeps our editor boxes in the sidebar for people who reordered them back
 * when they sat under the content.
 *
 * WordPress saves the order you put boxes in (the up and down arrows, or a
 * drag) as the user option meta-box-order_<screen>, one comma list per area.
 * do_meta_boxes() (wp-admin/includes/template.php) re-adds every saved ID in
 * its saved area with add_meta_box( $id, null, null, $screen, $area,
 * 'sorted' ), which moves a box out of the area its plugin asked for. Event
 * Details and Pro's Scheduled Emails asked for `normal` until 2026.11.8, so
 * anyone who reordered them then still got them in the block editor's
 * "Meta Boxes" panel under the content after we moved them to `side`. Pete at
 * U3A saw exactly that on 2026.11.12: both boxes at the bottom, and free's
 * own Scheduled Email box, never in his saved order, in the sidebar with Pro
 * off.
 *
 * So we take our own box IDs out of every area but `side` whenever WordPress
 * reads that option. Each box then lands in the area it asks for, and an
 * order saved within the sidebar still holds. Other plugins' boxes keep
 * whatever order the user gave them.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Our box IDs that belong in the sidebar, per editor screen. Pro adds its
 * own through the npmp_sidebar_box_ids filter.
 *
 * @param string $screen Screen ID (the post type for an editor).
 * @return string[]
 */
function npmp_sidebar_box_ids( $screen ) {
	$ids = array(
		'npmp_event'      => array(
			'npmp-event-details',
			'npmp-event-scheduled-email',
			'npmp_event_email_template',
			'npmp_social_share_box',
		),
		'npmp_newsletter' => array(
			'npmp_newsletter_send_controls',
			'npmp_newsletter_template',
			'npmp_newsletter_public_archive',
		),
	);
	$list = isset( $ids[ $screen ] ) ? $ids[ $screen ] : array();
	return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'npmp_sidebar_box_ids', $list, $screen ) ) ) );
}

/**
 * Take the given box IDs out of every area of a saved order except `side`.
 *
 * @param mixed    $order Saved order: area => comma list of box IDs, or false.
 * @param string[] $ids   Box IDs that belong in the sidebar.
 * @return mixed The order, with those IDs only in `side`. Anything that isn't an array comes back as it was.
 */
function npmp_sidebar_box_order( $order, $ids ) {
	if ( ! is_array( $order ) || ! $ids ) {
		return $order;
	}
	foreach ( $order as $area => $list ) {
		if ( 'side' === $area || ! is_string( $list ) || '' === $list ) {
			continue;
		}
		$kept           = array_diff( explode( ',', $list ), $ids );
		$order[ $area ] = implode( ',', $kept );
	}
	return $order;
}

/**
 * The get_user_option_meta-box-order_<screen> filter.
 *
 * @param mixed  $order  Saved order.
 * @param string $option Option name, meta-box-order_<screen>.
 * @return mixed
 */
function npmp_filter_sidebar_box_order( $order, $option = '' ) {
	$screen = 0 === strpos( (string) $option, 'meta-box-order_' ) ? substr( (string) $option, strlen( 'meta-box-order_' ) ) : '';
	return npmp_sidebar_box_order( $order, npmp_sidebar_box_ids( $screen ) );
}
add_filter( 'get_user_option_meta-box-order_npmp_event', 'npmp_filter_sidebar_box_order', 10, 2 );
add_filter( 'get_user_option_meta-box-order_npmp_newsletter', 'npmp_filter_sidebar_box_order', 10, 2 );
