<?php
/**
 * Real-WordPress check of newsletter featured images
 * (includes/email-newsletter/editor.php and archive.php).
 *
 * A sent, public newsletter with a featured image: the image shows in
 * [npmp_newsletters] and the block, on the public page inside the theme
 * (over HTTP, once, from the theme's own featured image), and on the
 * standalone page with og:image. The email never carries it. A newsletter
 * with no image renders as before.
 *
 * Run inside a throwaway WordPress with members and newsletters on, a block
 * theme active, and the site served at its home URL (php -S works):
 *   wp eval-file tests/rig/newsletter-image.php
 * It switches to post-name links for the run and puts every setting and post
 * back on exit.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! class_exists( 'NPMP_Newsletter_Manager' ) || ! function_exists( 'npmp_newsletter_archive_render' ) ) {
	echo "  FAIL  members and newsletters must be on (npmp_enabled_features)\n";
	exit( 1 );
}

$rig_made = array();
$rig_perm = get_option( 'permalink_structure' );
register_shutdown_function(
	static function () use ( &$rig_made, $rig_perm ) {
		foreach ( $rig_made as $rig_id ) {
			wp_delete_post( $rig_id, true );
		}
		update_option( 'permalink_structure', $rig_perm );
		flush_rewrite_rules();
	}
);
update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

echo "Editor\n";
$ok( 'Newsletters support featured images', post_type_supports( 'npmp_newsletter', 'thumbnail' ) );

// A real image in the uploads folder, attached as a featured image.
$uploads = wp_upload_dir();
$path    = trailingslashit( $uploads['path'] ) . 'npmp-rig-newsletter-image.png';
$img     = imagecreatetruecolor( 1200, 630 );
imagefill( $img, 0, 0, imagecolorallocate( $img, 15, 118, 110 ) );
imagepng( $img, $path );
$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Rig image', 'post_status' => 'inherit' ), $path );
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $path ) );
$rig_made[] = $att;
$large      = (string) wp_get_attachment_image_url( $att, 'large' );
$file_stem  = 'npmp-rig-newsletter-image';

$make = function ( $title ) use ( &$rig_made ) {
	$id = (int) wp_insert_post(
		array(
			'post_type'    => 'npmp_newsletter',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>Walk on Friday.</p><!-- /wp:paragraph -->',
		)
	);
	update_post_meta( $id, '_npmp_newsletter_template', 'none' );
	$rig_made[] = $id;
	return $id;
};
$with    = $make( 'Rig with image' );
$without = $make( 'Rig without image' );
set_post_thumbnail( $with, $att );

echo "The email leaves the image out\n";
$mail = NPMP_Newsletter_Manager::get_newsletter_content( $with, false, null, 'rig-reader@example.org' );
$ok( 'The email body has the text', false !== strpos( $mail, 'Walk on Friday.' ) );
$ok( 'The email body has no featured image', false === strpos( $mail, $file_stem ) );

foreach ( array( $with, $without ) as $id ) {
	update_post_meta( $id, '_npmp_newsletter_status', 'sent' );
	update_post_meta( $id, '_npmp_newsletter_queued_at', current_time( 'mysql' ) );
	update_post_meta( $id, NPMP_NEWSLETTER_PUBLIC_META, '1' );
}

echo "List\n";
$list = do_shortcode( '[npmp_newsletters]' );
$ok( 'Lists both newsletters', false !== strpos( $list, 'Rig with image' ) && false !== strpos( $list, 'Rig without image' ) );
$ok( 'One image, the featured one', 1 === substr_count( $list, 'class="npmp-newsletter-media"' ) && false !== strpos( $list, $file_stem ) );
$ok( 'The image links to the newsletter page', (bool) preg_match( '#<a class="npmp-newsletter-media" href="' . preg_quote( esc_url( npmp_newsletter_archive_url( $with ) ), '#' ) . '"#', $list ) );
preg_match( '#<a class="npmp-newsletter-media"[^>]*>(<img[^>]*>)</a>#', $list, $m );
$ok( 'Lazy, decorative image', isset( $m[1] ) && false !== strpos( $m[1], 'alt=""' ) && false !== strpos( $m[1], 'loading="lazy"' ) );
$ok( 'image="no" hides it', false === strpos( do_shortcode( '[npmp_newsletters image="no"]' ), 'npmp-newsletter-media' ) );
$block = render_block( array( 'blockName' => 'nonprofit-manager/newsletters', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
$ok( 'The block shows it by default', false !== strpos( $block, 'npmp-newsletter-media' ) );
$block = render_block( array( 'blockName' => 'nonprofit-manager/newsletters', 'attrs' => array( 'image' => 'no' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
$ok( 'The block can hide it', false === strpos( $block, 'npmp-newsletter-media' ) );

echo "Standalone page\n";
$page = npmp_newsletter_archive_page_html( $with );
$ok( 'Shows the featured image', 1 === substr_count( $page, '<figure class="npmp-nlv-image">' ) );
$ok( 'og:image is the large image', false !== strpos( $page, '<meta property="og:image" content="' . esc_url( $large ) . '">' ) );
$ok( 'og:url is the public page', false !== strpos( $page, '<meta property="og:url" content="' . esc_url( npmp_newsletter_archive_url( $with ) ) . '">' ) );
$plain = npmp_newsletter_archive_page_html( $without );
$ok( 'No image: no figure and no og:image', false === strpos( $plain, '<figure class="npmp-nlv-image">' ) && false === strpos( $plain, 'og:image' ) );
$preview = npmp_newsletter_archive_page_html( $with, true );
$ok( 'Admin preview: image, no share tags', false !== strpos( $preview, '<figure class="npmp-nlv-image">' ) && false === strpos( $preview, 'og:title' ) );

echo "Inside the theme (" . get_stylesheet() . "), over HTTP\n";
$res  = wp_remote_get( npmp_newsletter_archive_url( $with ), array( 'timeout' => 30 ) );
$html = (string) wp_remote_retrieve_body( $res );
$ok( 'Page answers 200', 200 === (int) wp_remote_retrieve_response_code( $res ) );
$ok( 'Served inside the theme', '' === (string) wp_remote_retrieve_header( $res, 'x-npmp-newsletter-view' ) );
$ok( 'Shows the body', false !== strpos( $html, 'Walk on Friday.' ) );
$ok( 'The theme shows the featured image once', 1 === substr_count( $html, 'wp-post-image' ) && false !== strpos( $html, $file_stem ) );
$res  = wp_remote_get( npmp_newsletter_archive_url( $without ), array( 'timeout' => 30 ) );
$html = (string) wp_remote_retrieve_body( $res );
$ok( 'No image: none on the page', false === strpos( $html, 'wp-post-image' ) );

wp_delete_file( $path );
printf( "\n%s\n", $fails ? "$fails FAILED" : 'ALL PASSED' );
exit( $fails ? 1 : 0 );
