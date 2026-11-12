<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/email-image-formats.php
//
// Image formats in sent email, on a real WordPress with its real image
// library. A newsletter holding a WebP photo, a WebP logo with a clear
// background, an AVIF photo, an SVG and a PNG goes out as a test send. The
// WebP and AVIF files arrive as JPG or PNG copies that exist on disk, the PNG
// and the SVG keep their addresses, and the SVG is named in the notice the
// author gets. A second send makes no second copy.
//
// With the Creative Commons plugin active (copy it into wp-content/plugins and
// activate it), the same send carries no attribution box, and a normal page
// still gets one. Without the plugin that part prints SKIP.
//
// Needs the members and newsletters modules on (option npmp_enabled_features).
// Leaves nothing behind.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) || false !== strpos( $errfile, 'npm-' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
if ( ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
	fwrite( STDERR, "Turn on the members and newsletters modules first.\n" );
	exit( 2 );
}

$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );

$uploads = wp_get_upload_dir();
$dir     = $uploads['basedir'] . '/rig-formats';
wp_mkdir_p( $dir );
$canvas = function ( $w, $h, $clear ) {
	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 16, 185, 129, $clear ? 127 : 0 ) );
	return $im;
};
imagewebp( $canvas( 1600, 400, false ), $dir . '/photo.webp', 80 );
imagewebp( $canvas( 200, 80, true ), $dir . '/logo.webp', 80 );
$has_avif = function_exists( 'imageavif' ) && @imageavif( $canvas( 300, 200, false ), $dir . '/photo.avif' ) && filesize( $dir . '/photo.avif' ) > 0;
imagepng( $canvas( 120, 60, false ), $dir . '/plain.png' );
file_put_contents( $dir . '/mark.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="18" fill="#10B981"/></svg>' );

$png_id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Rig plain', 'post_status' => 'inherit' ), $dir . '/plain.png' );
update_post_meta( $png_id, 'license_url', 'https://creativecommons.org/licenses/by/4.0/' );
update_post_meta( $png_id, 'attribution_name', 'Rig Author' );

$base = $uploads['baseurl'] . '/rig-formats';
$body = '<!-- wp:html -->'
	. '<p><img class="wp-image-' . $png_id . '" src="' . $base . '/plain.png" alt="Plain"></p>'
	. '<p><img src="' . $base . '/photo.webp?ver=2" alt="Photo" width="600"></p>'
	. '<p><img src="/wp-content/uploads/rig-formats/logo.webp" alt="Logo"></p>'
	. ( $has_avif ? '<p><img src="' . $base . '/photo.avif" alt="Avif"></p>' : '' )
	. '<p><img src="' . $base . '/mark.svg" alt="Mark"></p>'
	. '<p><img src="https://cdn.example.net/far.webp" alt="Far"></p>'
	. '<p>After the images.</p>'
	. '<!-- /wp:html -->';
$nl = wp_insert_post( array( 'post_type' => 'npmp_newsletter', 'post_status' => 'publish', 'post_title' => 'Rig formats', 'post_content' => $body ) );
update_post_meta( $nl, '_npmp_newsletter_template', 'none' );

$has_cc = class_exists( 'CreativeCommonsImage' );
$had_box_option = get_option( 'enable_attribution_box' );
if ( $has_cc && ! has_filter( 'the_content', array( CreativeCommonsImage::get_instance(), 'add_attribution_boxes' ) ) ) {
	// The plugin hooks its box at load when its option is on. Hook it the same way here.
	update_option( 'enable_attribution_box', 1 );
	add_filter( 'the_content', array( CreativeCommonsImage::get_instance(), 'add_attribution_boxes' ) );
}

echo "== Test send ==\n";
npmp_email_unconverted_images( null, true );
rig_check( 'Test send went out', true, (bool) NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-formats@example.org' ) );
$html = (string) ( $mails[0]['message'] ?? '' );

$src_of = function ( $alt ) use ( $html ) {
	return preg_match( '/<img\b[^>]*\balt="' . preg_quote( $alt, '/' ) . '"[^>]*>/', $html, $m ) && preg_match( '/\ssrc="([^"]*)"/', $m[0], $s ) ? $s[1] : null;
};
$file_of = function ( $src ) use ( $uploads ) {
	return $src && 0 === strpos( $src, $uploads['baseurl'] ) ? $uploads['basedir'] . substr( $src, strlen( $uploads['baseurl'] ) ) : '';
};
$magic = function ( $path ) {
	$head = $path && is_file( $path ) ? (string) file_get_contents( $path, false, null, 0, 8 ) : '';
	return "\xFF\xD8" === substr( $head, 0, 2 ) ? 'jpg' : ( "\x89PNG" === substr( $head, 0, 4 ) ? 'png' : 'none' );
};

rig_check( 'PNG keeps its address', $base . '/plain.png', $src_of( 'Plain' ) );

$photo = $src_of( 'Photo' );
rig_check( 'WebP photo is sent as a JPG', 1, preg_match( '#/npmp-email-images/photo-[0-9a-f]{10}\.jpg$#', (string) $photo ) );
rig_check( 'the JPG copy is a real JPG file', 'jpg', $magic( $file_of( $photo ) ) );
$dims = $file_of( $photo ) ? getimagesize( $file_of( $photo ) ) : array( 0, 0 );
rig_check( 'a 1600px photo is cut to 1200px wide', array( 1200, 300 ), array( $dims[0], $dims[1] ) );

$logo = $src_of( 'Logo' );
rig_check( 'clear-background WebP, typed as a relative address, is sent as a PNG', 1, preg_match( '#^' . preg_quote( $uploads['baseurl'], '#' ) . '/npmp-email-images/logo-[0-9a-f]{10}\.png$#', (string) $logo ) );
rig_check( 'the PNG copy is a real PNG file', 'png', $magic( $file_of( $logo ) ) );
if ( $file_of( $logo ) && is_file( $file_of( $logo ) ) ) {
	$im = imagecreatefrompng( $file_of( $logo ) );
	rig_check( 'the PNG copy kept its clear background', 127, ( imagecolorat( $im, 5, 5 ) >> 24 ) & 0x7F );
}

if ( $has_avif ) {
	$avif = $src_of( 'Avif' );
	rig_check( 'AVIF photo is sent as a JPG', 1, preg_match( '#/npmp-email-images/photo-[0-9a-f]{10}\.jpg$#', (string) $avif ) );
	rig_check( 'the AVIF copy is a real JPG file', 'jpg', $magic( $file_of( $avif ) ) );
	rig_check( 'photo.webp and photo.avif got separate copies', true, $avif !== $photo );
} else {
	echo "  SKIP  AVIF: this PHP's GD can't write one\n";
}

rig_check( 'SVG keeps its address', $base . '/mark.svg', $src_of( 'Mark' ) );
rig_check( 'an image on another server keeps its address', 'https://cdn.example.net/far.webp', $src_of( 'Far' ) );
rig_check( 'the SVG and the far image are the two left unconverted', array( $base . '/mark.svg', 'https://cdn.example.net/far.webp' ), npmp_email_unconverted_images() );
rig_check( 'the author gets a notice about two images', true, 0 === strpos( npmp_email_unconverted_images_notice(), '2 images are' ) );
rig_check( 'the text after the images arrived', true, false !== strpos( $html, 'After the images.' ) );

echo "== Second send ==\n";
$copy  = $file_of( $photo );
$mtime = $copy && is_file( $copy ) ? filemtime( $copy ) : 0;
$count = count( glob( $uploads['basedir'] . '/npmp-email-images/*' ) );
sleep( 1 );
clearstatcache();
NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-formats@example.org' );
clearstatcache();
rig_check( 'no new copies on the second send', $count, count( glob( $uploads['basedir'] . '/npmp-email-images/*' ) ) );
rig_check( 'the first copy was reused, not rewritten', $mtime, $copy && is_file( $copy ) ? filemtime( $copy ) : -1 );
rig_check( 'the second email points at the same copy', true, false !== strpos( (string) ( $mails[1]['message'] ?? '' ), (string) $photo ) );

echo "== Turned off by filter ==\n";
add_filter( 'npmp_email_convert_images', '__return_false' );
NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-formats@example.org' );
rig_check( 'npmp_email_convert_images false sends the WebP as it is', true, false !== strpos( (string) ( $mails[2]['message'] ?? '' ), $base . '/photo.webp?ver=2' ) );
remove_filter( 'npmp_email_convert_images', '__return_false' );

echo "== Creative Commons attribution box ==\n";
if ( $has_cc ) {
	$page = apply_filters( 'the_content', '<p><img class="wp-image-' . $png_id . '" src="' . $base . '/plain.png" alt="Plain"></p>' );
	rig_check( 'a web page gets the attribution box (the rig is set up right)', true, false !== strpos( $page, 'cc-attribution-box' ) );
	rig_check( 'the sent email has no attribution box', false, strpos( $html, 'cc-attribution-box' ) );
	rig_check( 'the sent email has no licence button', false, strpos( $html, 'attribution-box/by.svg' ) );
	rig_check( 'the box is back on the filter after the send', true, false !== has_filter( 'the_content', array( CreativeCommonsImage::get_instance(), 'add_attribution_boxes' ) ) );
	$wrapper = wp_insert_post( array( 'post_type' => 'npmp_nl_template', 'post_status' => 'publish', 'post_title' => 'Rig formats wrapper', 'post_content' => '<p><img class="wp-image-' . $png_id . '" src="' . $base . '/plain.png" alt="Header"></p>[email_content]' ) );
	update_post_meta( $wrapper, '_npmp_template_type', 'wrapper' );
	update_post_meta( $nl, '_npmp_newsletter_template', (string) $wrapper );
	NPMP_Newsletter_Manager::send_test_email( $nl, 'rig-formats@example.org' );
	$wrapped = (string) ( end( $mails )['message'] ?? '' );
	rig_check( 'an image in the template arrives', true, false !== strpos( $wrapped, 'alt="Header"' ) );
	rig_check( 'and the template adds no attribution box either', false, strpos( $wrapped, 'cc-attribution-box' ) );
	wp_delete_post( $wrapper, true );
} else {
	echo "  SKIP  the Creative Commons plugin isn't active on this rig\n";
}

// Clean up.
wp_delete_post( $nl, true );
wp_delete_post( $png_id, true );
foreach ( array_merge( glob( $dir . '/*' ), glob( $uploads['basedir'] . '/npmp-email-images/*' ) ) as $leftover ) {
	unlink( $leftover );
}
rmdir( $dir );
rmdir( $uploads['basedir'] . '/npmp-email-images' );
if ( false === $had_box_option ) {
	delete_option( 'enable_attribution_box' );
}

rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
