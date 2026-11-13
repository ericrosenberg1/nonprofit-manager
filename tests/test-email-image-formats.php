<?php
/**
 * Image formats and other plugins' image boxes in sent email.
 *
 * A reader in Gmail or Outlook never sees an SVG, and desktop Outlook drops
 * WebP and AVIF, which is what WordPress and most image plugins now store.
 * npmp_email_safe_image_url() in includes/email/helpers.php swaps those for a
 * PNG or JPG copy. This file covers the parts that need no image library:
 * which addresses count, how transparency is read from a file header, and how
 * a browser-only `the_content` callback (Creative Commons' attribution box)
 * is recognised. The conversion and the real Creative Commons plugin run in
 * tests/rig/email-image-formats.php on a real WordPress.
 *
 * Run: php tests/test-email-image-formats.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

require_once dirname( __DIR__ ) . '/includes/email/helpers.php';

/* ---------------------------------------------------------------------------
 * Which addresses count
 * ------------------------------------------------------------------------ */

check( 'extension of a plain address', 'webp', npmp_email_image_extension( 'https://example.org/wp-content/uploads/logo.webp' ) );
check( 'extension ignores the query', 'avif', npmp_email_image_extension( 'https://example.org/a/photo.AVIF?ver=3#x' ) );
check( 'no extension', '', npmp_email_image_extension( 'https://example.org/image' ) );
check( 'a dot in the host is not an extension', '', npmp_email_image_extension( 'https://example.org' ) );

foreach ( array( 'png', 'jpg', 'jpeg', 'gif' ) as $safe ) {
	check(
		$safe . ' is sent as it is',
		'https://example.org/a.' . $safe,
		npmp_email_safe_image_url( 'https://example.org/a.' . $safe )
	);
}
check( 'nothing safe went on the unconverted list', array(), npmp_email_unconverted_images() );
check( 'no notice when every image is fine', '', npmp_email_unconverted_images_notice() );

/* ---------------------------------------------------------------------------
 * What can't be converted is left alone and reported
 * ------------------------------------------------------------------------ */

check(
	'an SVG keeps its address',
	'https://example.org/wp-content/uploads/logo.svg',
	npmp_email_safe_image_url( 'https://example.org/wp-content/uploads/logo.svg' )
);
check(
	'a WebP with no WordPress behind it keeps its address',
	'https://cdn.example.net/photo.webp',
	npmp_email_safe_image_url( 'https://cdn.example.net/photo.webp' )
);
npmp_email_safe_image_url( 'https://example.org/wp-content/uploads/logo.svg' );
check(
	'both are on the unconverted list, once each',
	array( 'https://example.org/wp-content/uploads/logo.svg', 'https://cdn.example.net/photo.webp' ),
	npmp_email_unconverted_images()
);
check( 'the notice counts them', true, 0 === strpos( npmp_email_unconverted_images_notice(), '2 images are' ) );
check( 'the list empties on reset', array(), npmp_email_unconverted_images( null, true ) );

$tag = npmp_email_prepare_image_tag( '<img class="wp-image-7" src="/wp-content/uploads/logo.svg" alt="Logo">', 'https://example.org' );
check(
	'prepare_image_tag still resolves an SVG src',
	'<img class="wp-image-7" src="https://example.org/wp-content/uploads/logo.svg" alt="Logo">',
	$tag
);
check( 'and reports it', 1, count( npmp_email_unconverted_images() ) );
check( 'singular notice', true, 0 === strpos( npmp_email_unconverted_images_notice(), '1 image is' ) );
npmp_email_unconverted_images( null, true );

/* ---------------------------------------------------------------------------
 * Transparency, read from the header
 * ------------------------------------------------------------------------ */

$riff = static function ( $chunk, $payload ) {
	return 'RIFF' . pack( 'V', 4 + 8 + strlen( $payload ) ) . 'WEBP' . $chunk . pack( 'V', strlen( $payload ) ) . $payload;
};

check( 'lossy WebP has no alpha', false, npmp_email_image_bytes_have_alpha( $riff( 'VP8 ', str_repeat( "\0", 20 ) ), 'webp' ) );
check( 'extended WebP, alpha flag off', false, npmp_email_image_bytes_have_alpha( $riff( 'VP8X', "\x00" . str_repeat( "\0", 9 ) ), 'webp' ) );
check( 'extended WebP, alpha flag on', true, npmp_email_image_bytes_have_alpha( $riff( 'VP8X', "\x10" . str_repeat( "\0", 9 ) ), 'webp' ) );
check( 'extended WebP, animation flag is not alpha', false, npmp_email_image_bytes_have_alpha( $riff( 'VP8X', "\x02" . str_repeat( "\0", 9 ) ), 'webp' ) );
check( 'lossless WebP, alpha bit off', false, npmp_email_image_bytes_have_alpha( $riff( 'VP8L', "\x2f\x00\x00\x00\x00" . str_repeat( "\0", 6 ) ), 'webp' ) );
check( 'lossless WebP, alpha bit on', true, npmp_email_image_bytes_have_alpha( $riff( 'VP8L', "\x2f\x00\x00\x00\x10" . str_repeat( "\0", 6 ) ), 'webp' ) );
check( 'a short or wrong file is not alpha', false, npmp_email_image_bytes_have_alpha( 'not an image', 'webp' ) );
check( 'empty bytes are not alpha', false, npmp_email_image_bytes_have_alpha( '', 'webp' ) );
check( 'AVIF without an alpha plane', false, npmp_email_image_bytes_have_alpha( "\0\0\0\x1cftypavif" . str_repeat( 'x', 80 ), 'avif' ) );
check( 'AVIF with an alpha plane', true, npmp_email_image_bytes_have_alpha( "\0\0\0\x1cftypavif....urn:mpeg:mpegB:cicp:systems:auxiliary:alpha\0", 'avif' ) );
check( 'HEIC with an alpha plane', true, npmp_email_image_bytes_have_alpha( "\0\0\0\x18ftypheic....urn:mpeg:hevc:2015:auxid:1\0", 'heic' ) );

if ( function_exists( 'imagewebp' ) ) {
	$make = static function ( $transparent ) {
		$im = imagecreatetruecolor( 8, 8 );
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 16, 185, 129, $transparent ? 127 : 0 ) );
		ob_start();
		imagewebp( $im, null, 80 );
		return (string) ob_get_clean();
	};
	check( 'a WebP GD wrote with a clear background reads as alpha', true, npmp_email_image_bytes_have_alpha( $make( true ), 'webp' ) );
	check( 'a WebP GD wrote with a solid background does not', false, npmp_email_image_bytes_have_alpha( $make( false ), 'webp' ) );
}

/* ---------------------------------------------------------------------------
 * Recognising a browser-only callback
 * ------------------------------------------------------------------------ */

class CreativeCommonsImage {
	public function add_attribution_boxes( $content ) {
		return $content;
	}
	public function license_shortcode() {
		return '';
	}
}
class Rig_Child_Of_CC extends CreativeCommonsImage {}
class Rig_Unrelated {
	public function add_attribution_boxes( $content ) {
		return $content;
	}
}

$listed = npmp_email_browser_only_content_filters();
check( 'the default list names Creative Commons and the Event Details box', array( array( 'CreativeCommonsImage', 'add_attribution_boxes' ), 'npmp_append_event_details_to_content' ), $listed );

check( 'object callback matches', true, npmp_email_callback_is_listed( array( new CreativeCommonsImage(), 'add_attribution_boxes' ), $listed ) );
check( 'static-style callback matches', true, npmp_email_callback_is_listed( array( 'CreativeCommonsImage', 'add_attribution_boxes' ), $listed ) );
check( 'class and method match in any case', true, npmp_email_callback_is_listed( array( 'creativecommonsimage', 'Add_Attribution_Boxes' ), $listed ) );
check( 'a subclass matches', true, npmp_email_callback_is_listed( array( new Rig_Child_Of_CC(), 'add_attribution_boxes' ), $listed ) );
check( 'another method on the class does not', false, npmp_email_callback_is_listed( array( new CreativeCommonsImage(), 'license_shortcode' ), $listed ) );
check( 'the same method on another class does not', false, npmp_email_callback_is_listed( array( new Rig_Unrelated(), 'add_attribution_boxes' ), $listed ) );
check( 'a plain function does not', false, npmp_email_callback_is_listed( 'wpautop', $listed ) );
check( 'a closure does not', false, npmp_email_callback_is_listed( static function ( $c ) { return $c; }, $listed ) );
check( 'a listed function name matches', true, npmp_email_callback_is_listed( 'some_share_buttons', array( 'Some_Share_Buttons' ) ) );
check( 'the calendar\'s Event Details box matches', true, npmp_email_callback_is_listed( 'npmp_append_event_details_to_content', $listed ) );
check( 'an empty list matches nothing', false, npmp_email_callback_is_listed( array( new CreativeCommonsImage(), 'add_attribution_boxes' ), array() ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
