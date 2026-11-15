<?php
/**
 * Share bar Copy link button (includes/npmp-content-blocks.php and
 * assets/js/npmp-social-share.js).
 *
 * After a click the script swaps the button text for its data-npmp-copied
 * attribute. The shortcode never printed that attribute, so every site saw
 * the script's English fallback "Copied" whatever its language.
 *
 * Run: php tests/test-social-share-copy.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function add_action() {
	return true;
}
function add_shortcode() {
	return true;
}
function shortcode_atts( $pairs, $atts ) {
	return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) );
}
function esc_url_raw( $url ) {
	return $url;
}
function esc_url( $url ) {
	return $url;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( $text ) {
	return 'Copié';
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function wp_style_is() {
	return false;
}
function wp_script_is() {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/npmp-content-blocks.php';

$html = npmp_social_share_shortcode(
	array(
		'networks' => 'copy',
		'url'      => 'https://example.org/gala/',
		'title'    => 'Gala',
	)
);

echo "Copy link button\n";
check( 'button prints the translated Copied label', true, false !== strpos( $html, 'data-npmp-copied="Copié"' ) );
check( 'button keeps its share URL', true, false !== strpos( $html, 'data-npmp-share-url="https://example.org/gala/"' ) );

echo "Script\n";
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/npmp-social-share.js' );
check( 'script reads data-npmp-copied', true, false !== strpos( $js, "getAttribute( 'data-npmp-copied' )" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
