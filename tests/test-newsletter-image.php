<?php
/**
 * Newsletter featured images (Pete at U3A asked for them).
 *
 * A newsletter takes a featured image like an event. We show it on the
 * public web version and in [npmp_newsletters] lists, and the standalone page
 * carries it as og:image. The email leaves it out: event emails show an
 * event's image only where the author puts [event_image], and a newsletter's
 * author places images in the body. The WordPress side runs in
 * tests/rig/newsletter-image.php.
 *
 * Run: php tests/test-newsletter-image.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/archive-pure.php';

echo "== List setting ==\n";
$s = npmp_newsletter_archive_settings( array() );
check( 'Images show by default', 1, $s['image'] );
check( 'image="no" hides them', 0, npmp_newsletter_archive_settings( array( 'image' => 'no' ) )['image'] );
check( 'image="yes" shows them', 1, npmp_newsletter_archive_settings( array( 'image' => 'yes' ) )['image'] );
check( 'A blank value keeps the default', 1, npmp_newsletter_archive_settings( array( 'image' => '' ) )['image'] );

echo "== Wiring ==\n";
$root    = dirname( __DIR__ );
$editor  = file_get_contents( $root . '/includes/email-newsletter/editor.php' );
$archive = file_get_contents( $root . '/includes/email-newsletter/archive.php' );
$manager = file_get_contents( $root . '/includes/email-newsletter/class-newsletter-manager.php' );
$block   = file_get_contents( $root . '/assets/js/npmp-newsletter-archive-block.js' );
$css     = file_get_contents( $root . '/assets/css/npmp-newsletter-archive.css' );

check( 'Newsletters support thumbnails', 1, preg_match( "/'supports' => \['title', 'editor', 'thumbnail'\]/", $editor ) );
check( 'Shortcode accepts image=""', 1, preg_match( "/'image'\s*=> '',/", $archive ) );
check( 'Block attribute image', 1, preg_match( "/'image'\s*=> array\(\s*'type'\s*=> 'string',/", $archive ) );
check( 'Block passes image to the list', 1, substr_count( $archive, "'readmore', 'pagination', 'image' ) as \$key" ) );
check( 'Block editor offers the toggle', 1, substr_count( $block, "set( 'image' )" ) );
check( 'List shows the thumbnail only when set', 1, substr_count( $archive, "\$settings['image'] && has_post_thumbnail( \$post->ID )" ) );
check( 'List image matches the events list markup', 1, substr_count( $archive, "get_the_post_thumbnail( \$post->ID, 'medium_large', array( 'class' => 'npmp-newsletter-image', 'loading' => 'lazy', 'alt' => '' ) )" ) );
check( 'List image CSS', 1, substr_count( $css, '.npmp-newsletter-media {' ) );

$page_fn  = substr( $archive, strpos( $archive, 'function npmp_newsletter_archive_page_html' ), 3000 );
$page_fn  = substr( $page_fn, 0, strpos( $page_fn, "\n}\n" ) );
$theme_fn = substr( $archive, strpos( $archive, 'function npmp_newsletter_archive_theme_content' ), 2000 );
$theme_fn = substr( $theme_fn, 0, strpos( $theme_fn, "\n}\n" ) );
check( 'Standalone page prints the image', 1, substr_count( $page_fn, 'npmp_newsletter_archive_image_html( $id )' ) );
check( 'Standalone page prints share tags, not in the admin preview', 1, preg_match( '/if \( ! \$admin_preview \) \{[^}]*npmp_newsletter_archive_share_meta\( \$id \)/s', $page_fn ) );
check( 'Theme page leaves the image to the theme', 0, substr_count( $theme_fn, 'thumbnail' ) + substr_count( $theme_fn, 'image_html' ) );
check( 'og:image from the featured image', 1, substr_count( $archive, '<meta property="og:image" content="' ) );
check( 'The email body never adds the featured image', 0, substr_count( $manager, 'thumbnail' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
