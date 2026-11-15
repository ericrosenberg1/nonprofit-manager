<?php
/**
 * Real-WordPress check that our editor boxes land in the sidebar even for a
 * user whose saved box order (user option meta-box-order_<screen>) still puts
 * them under the content (includes/npmp-editor-boxes.php).
 *
 * Pete at U3A reordered the event boxes while they sat in `normal`, before
 * 2026.11.8. do_meta_boxes() re-adds every saved ID in its saved area, so on
 * 2026.11.12 his Event Details and Pro's Scheduled Emails still showed in the
 * block editor's "Meta Boxes" panel (2026-11-13). This loads the real edit
 * screen over HTTP as an admin and reads the box areas the block editor gets
 * from setAvailableMetaBoxesPerLocation().
 *
 * Needs the site served at its home URL (php -S), members, newsletters, the
 * calendar and social sharing on (npmp_enabled_features), then:
 *   wp eval-file tests/rig/editor-box-order.php
 * Run it with Pro active and with Pro inactive. It deletes what it makes and
 * puts the admin's saved orders back.
 */

$fails  = 0;
$passes = 0;
$ok     = function ( $label, $cond, $detail = '' ) use ( &$fails, &$passes ) {
	$cond ? ++$passes : ++$fails;
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  (' . $detail . ')' : '' );
};

if ( ! post_type_exists( 'npmp_event' ) || ! post_type_exists( 'npmp_newsletter' ) ) {
	echo "  FAIL  newsletters and the calendar must be on (npmp_enabled_features)\n";
	exit( 1 );
}
$pro = defined( 'NPMP_PRO_VERSION' );
echo $pro ? "Pro is active\n" : "Pro is inactive\n";

$rig_uid  = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$rig_keep = array();
foreach ( array( 'npmp_event', 'npmp_newsletter' ) as $rig_screen ) {
	$rig_keep[ $rig_screen ] = get_user_meta( $rig_uid, 'meta-box-order_' . $rig_screen, true );
}

// The areas the block editor gets for one post, by box ID.
$rig_areas = function ( $post_id ) use ( $rig_uid ) {
	$exp     = time() + 600;
	$cookies = array(
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $rig_uid, $exp, 'auth' ) ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $rig_uid, $exp, 'logged_in' ) ) ),
	);
	$res  = wp_remote_get( admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ), array( 'cookies' => $cookies, 'timeout' => 60 ) );
	$html = wp_remote_retrieve_body( $res );
	$out  = array();
	if ( preg_match( '/setAvailableMetaBoxesPerLocation\(\s*(\{.*?\})\s*\);/s', $html, $m ) ) {
		foreach ( (array) json_decode( $m[1], true ) as $area => $boxes ) {
			foreach ( (array) $boxes as $i => $box ) {
				$out[ $box['id'] ] = array( $area, $i );
			}
		}
	}
	return $out;
};
$rig_where = function ( $areas, $id ) {
	return isset( $areas[ $id ] ) ? $areas[ $id ][0] : 'missing';
};

$rig_event = wp_insert_post( array( 'post_type' => 'npmp_event', 'post_status' => 'publish', 'post_title' => 'Rig box order event' ) );
$rig_nl    = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'draft', 'post_title' => 'Rig box order newsletter' ) );

$rig_event_ids = array( 'npmp-event-details', 'npmp_social_share_box', 'npmp_event_email_template', $pro ? 'npmp-pro-event-scheduled-emails' : 'npmp-event-scheduled-email' );

echo "\nNo saved order\n";
delete_user_meta( $rig_uid, 'meta-box-order_npmp_event' );
$areas = $rig_areas( $rig_event );
$ok( 'the edit screen loads with its boxes', count( $areas ) > 0 );
foreach ( $rig_event_ids as $id ) {
	$ok( "$id is in the sidebar", 'side' === $rig_where( $areas, $id ), $rig_where( $areas, $id ) );
}

echo "\nPete's order from before 2026.11.8: both event boxes under the content\n";
update_user_meta(
	$rig_uid,
	'meta-box-order_npmp_event',
	array(
		'side'     => 'npmp_social_share_box,rig_other_plugin_side',
		'normal'   => 'npmp-pro-event-scheduled-emails,npmp-event-details,rig_other_plugin_box',
		'advanced' => 'npmp-event-scheduled-email',
	)
);
// Another plugin's box, saved under the content, has to stay there. The
// edit screen is its own request, so it comes from a throwaway mu-plugin.
$rig_mu = WP_CONTENT_DIR . '/mu-plugins/rig-editor-box-order.php';
wp_mkdir_p( dirname( $rig_mu ) );
file_put_contents( $rig_mu, "<?php\nadd_action( 'add_meta_boxes_npmp_event', function () { add_meta_box( 'rig_other_plugin_box', 'Rig other plugin', '__return_null', 'npmp_event', 'side' ); } );\n" );
$areas = $rig_areas( $rig_event );
foreach ( $rig_event_ids as $id ) {
	$ok( "$id is in the sidebar", 'side' === $rig_where( $areas, $id ), $rig_where( $areas, $id ) );
}
$ok( 'another plugin\'s box keeps the order its user saved', 'normal' === $rig_where( $areas, 'rig_other_plugin_box' ), $rig_where( $areas, 'rig_other_plugin_box' ) );
$ok( 'nothing of ours is left under the content', ! array_intersect( $rig_event_ids, array_keys( array_filter( $areas, function ( $a ) { return 'side' !== $a[0]; } ) ) ) );

echo "\nAn order saved inside the sidebar still holds\n";
$rig_last = $pro ? 'npmp-pro-event-scheduled-emails' : 'npmp-event-scheduled-email';
update_user_meta(
	$rig_uid,
	'meta-box-order_npmp_event',
	array(
		'side'     => $rig_last . ',npmp_social_share_box,npmp-event-details',
		'normal'   => '',
		'advanced' => '',
	)
);
$areas = $rig_areas( $rig_event );
$ok( 'the email box comes first, as saved', isset( $areas[ $rig_last ], $areas['npmp-event-details'] ) && $areas[ $rig_last ][1] < $areas['npmp-event-details'][1] );
$ok( 'Social Sharing comes before Event Details, as saved', isset( $areas['npmp_social_share_box'], $areas['npmp-event-details'] ) && $areas['npmp_social_share_box'][1] < $areas['npmp-event-details'][1] );

echo "\nThe newsletter editor, with its boxes saved under the content\n";
update_user_meta(
	$rig_uid,
	'meta-box-order_npmp_newsletter',
	array(
		'side'     => '',
		'normal'   => 'npmp_newsletter_send_controls,npmp_newsletter_template',
		'advanced' => 'npmp_newsletter_public_archive',
	)
);
$areas = $rig_areas( $rig_nl );
foreach ( array( 'npmp_newsletter_send_controls', 'npmp_newsletter_template', 'npmp_newsletter_public_archive' ) as $id ) {
	$ok( "$id is in the sidebar", 'side' === $rig_where( $areas, $id ), $rig_where( $areas, $id ) );
}

// Clean up.
unlink( $rig_mu );
wp_delete_post( $rig_event, true );
wp_delete_post( $rig_nl, true );
foreach ( $rig_keep as $rig_screen => $rig_value ) {
	if ( '' === $rig_value || false === $rig_value ) {
		delete_user_meta( $rig_uid, 'meta-box-order_' . $rig_screen );
	} else {
		update_user_meta( $rig_uid, 'meta-box-order_' . $rig_screen, $rig_value );
	}
}

printf( "\n%d passed, %d failed\n", $passes, $fails );
exit( $fails ? 1 : 0 );
