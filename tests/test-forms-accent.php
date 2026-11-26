<?php
/**
 * The owner's Form color (option npmp_forms_accent, includes/npmp-forms-accent.php).
 *
 * The file's own header has named this test since the setting shipped, and
 * the test never existed. What it has to hold:
 * - A site that never picks a color, or picks the brand teal, prints no CSS.
 * - Every label on a filled button clears WCAG AA (4.5:1), whatever color
 *   the owner picks. On a color too light for white text, the label turns
 *   dark and the hover shade gets lighter.
 * - Only a hex color reaches the stylesheet. The value lands inside a
 *   <style> block, so anything else is dropped.
 *
 * The contrast numbers below are WCAG's own worked values (black on white is
 * 21:1, #767676 on white is 4.54:1), not numbers read back from the code.
 *
 * Run: php tests/test-forms-accent.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npmp_test_inline_styles'] = array();
$GLOBALS['npmp_test_registered']    = array( 'npmp-forms' );
$GLOBALS['npmp_test_options']       = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['npmp_test_options'] ) ? $GLOBALS['npmp_test_options'][ $key ] : $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) { $GLOBALS['npmp_test_options'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) { return in_array( $handle, $GLOBALS['npmp_test_registered'], true ); }
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $css ) { $GLOBALS['npmp_test_inline_styles'][] = array( $handle, $css ); return true; }
}

require_once __DIR__ . '/../includes/npmp-forms-accent.php';

echo "\n== 1. Only a hex color gets through ==\n";
check( 'six digits pass, lowercased', '#0f766e', npmp_forms_accent_normalize( '#0F766E' ) );
check( 'three digits widen', '#aabbcc', npmp_forms_accent_normalize( '#abc' ) );
check( 'spaces around it are trimmed', '#112233', npmp_forms_accent_normalize( "  #112233\n" ) );
check( 'no hash: dropped', '', npmp_forms_accent_normalize( '0f766e' ) );
check( 'a color name: dropped', '', npmp_forms_accent_normalize( 'red' ) );
check( 'eight digits: dropped', '', npmp_forms_accent_normalize( '#0f766eff' ) );
check( 'CSS after a color: dropped', '', npmp_forms_accent_normalize( '#0f766e;}body{display:none' ) );
check( 'a closing style tag: dropped', '', npmp_forms_accent_normalize( '</style><script>alert(1)</script>' ) );
check( 'an array: dropped', '', npmp_forms_accent_normalize( array( '#0f766e' ) ) );
check( 'null: dropped', '', npmp_forms_accent_normalize( null ) );

echo "\n== 2. Contrast matches WCAG's own numbers ==\n";
check( 'black on white is 21:1', 21.0, round( npmp_forms_accent_contrast( '#000000', '#ffffff' ), 2 ) );
check( 'the order does not matter', 21.0, round( npmp_forms_accent_contrast( '#ffffff', '#000000' ), 2 ) );
check( 'a color against itself is 1:1', 1.0, round( npmp_forms_accent_contrast( '#0f766e', '#0f766e' ), 2 ) );
check( '#767676 on white is 4.54:1, the lightest gray that passes AA', 4.54, round( npmp_forms_accent_contrast( '#767676', '#ffffff' ), 2 ) );
check( '#777777 on white is 4.48:1 and fails', 4.48, round( npmp_forms_accent_contrast( '#777777', '#ffffff' ), 2 ) );
check( 'the brand teal carries white text (CLAUDE.md says 5.5:1)', 5.5, round( npmp_forms_accent_contrast( '#ffffff', '#0f766e' ), 1 ) );
check( 'the decorative green does not (CLAUDE.md says 2.5:1)', 2.5, round( npmp_forms_accent_contrast( '#ffffff', '#10b981' ), 1 ) );

echo "\n== 3. A dark color keeps white text and a darker hover ==\n";
$p = npmp_forms_accent_palette( '#0F766E' );
check( 'accent, normalized', '#0f766e', $p['accent'] );
check( 'white label', '#ffffff', $p['text'] );
check( 'white_text flag', true, $p['white_text'] );
check( 'hover is darker than the color', true, npmp_forms_accent_luminance( $p['dark'] ) < npmp_forms_accent_luminance( $p['accent'] ) );
check( 'white still clears 4.5:1 on the hover shade', true, npmp_forms_accent_contrast( '#ffffff', $p['dark'] ) >= 4.5 );
check( 'no note for the owner', '', npmp_forms_accent_notice( '#0f766e' ) );

echo "\n== 4. A light color turns the label dark and the hover lighter ==\n";
$p = npmp_forms_accent_palette( '#10b981' );
check( 'white_text flag off', false, $p['white_text'] );
check( 'label is the heading color', '#111827', $p['text'] );
check( 'the label clears 4.5:1', true, npmp_forms_accent_contrast( $p['text'], $p['accent'] ) >= 4.5 );
check( 'hover is lighter than the color', true, npmp_forms_accent_luminance( $p['dark'] ) > npmp_forms_accent_luminance( $p['accent'] ) );
check( 'the label clears 4.5:1 on the hover shade too', true, npmp_forms_accent_contrast( $p['text'], $p['dark'] ) >= 4.5 );
check( 'the owner is told about the dark text', true, false !== strpos( npmp_forms_accent_notice( '#10b981' ), 'dark text' ) );
check( 'a mid green is still visible on white, so no second warning', false, strpos( npmp_forms_accent_notice( '#059669' ), 'white page' ) );
check( 'pale yellow is under 3:1 on white, so the owner hears that too', true, false !== strpos( npmp_forms_accent_notice( '#fef08a' ), 'white page' ) );

echo "\n== 5. Every color the picker can send ends with a readable label ==\n";
$worst      = 99.0;
$worst_at   = '';
$worst_dark = 99.0;
$dark_at    = '';
foreach ( array( 0, 51, 102, 119, 128, 153, 204, 255 ) as $r ) {
	foreach ( array( 0, 51, 102, 119, 128, 153, 204, 255 ) as $g ) {
		foreach ( array( 0, 51, 102, 119, 128, 153, 204, 255 ) as $b ) {
			$hex  = sprintf( '#%02x%02x%02x', $r, $g, $b );
			$p    = npmp_forms_accent_palette( $hex );
			$rest = npmp_forms_accent_contrast( $p['text'], $p['accent'] );
			if ( $rest < $worst ) {
				$worst    = $rest;
				$worst_at = $hex;
			}
			$hover = npmp_forms_accent_contrast( $p['text'], $p['dark'] );
			if ( $hover < $worst_dark ) {
				$worst_dark = $hover;
				$dark_at    = $hex;
			}
		}
	}
}
check( "512 colors: the label never drops under 4.5:1 (lowest at $worst_at)", true, $worst >= 4.5 );
check( "512 colors: nor on the hover shade (lowest at $dark_at)", true, $worst_dark >= 4.5 );

echo "\n== 6. The default prints nothing, a chosen color prints its three variables ==\n";
check( 'the default: no CSS', '', npmp_forms_accent_css( '#0f766e' ) );
check( 'the default in capitals: no CSS', '', npmp_forms_accent_css( '#0F766E' ) );
check( 'junk: no CSS', '', npmp_forms_accent_css( 'red;}' ) );
$css = npmp_forms_accent_css( '#1d4ed8' );
check( 'sets the accent', true, false !== strpos( $css, '--npmp-accent:#1d4ed8;' ) );
check( 'sets the hover shade', true, (bool) preg_match( '/--npmp-accent-dark:#[0-9a-f]{6};/', $css ) );
check( 'sets the label color', true, false !== strpos( $css, '--npmp-accent-text:#ffffff;' ) );
check( 'styles the contact form button, which has no color of its own', true, false !== strpos( $css, '.npmp-contact-form button[type="submit"]' ) );
check( 'no tag can ride along', false, strpos( $css, '<' ) );

echo "\n== 7. The saved option reaches the form stylesheet, and only when it should ==\n";
update_option( 'npmp_forms_accent', '' );
check( 'nothing saved: the default', '#0f766e', npmp_forms_accent() );
npmp_forms_accent_add_inline_style();
check( 'nothing saved: nothing printed', 0, count( $GLOBALS['npmp_test_inline_styles'] ) );

update_option( 'npmp_forms_accent', '#1D4ED8' );
check( 'a saved color comes back normalized', '#1d4ed8', npmp_forms_accent() );
npmp_forms_accent_add_inline_style();
check( 'printed once', 1, count( $GLOBALS['npmp_test_inline_styles'] ) );
check( 'on the form stylesheet', 'npmp-forms', $GLOBALS['npmp_test_inline_styles'][0][0] ?? '' );
check( 'with the saved color', true, false !== strpos( $GLOBALS['npmp_test_inline_styles'][0][1] ?? '', '--npmp-accent:#1d4ed8;' ) );

$GLOBALS['npmp_test_inline_styles'] = array();
update_option( 'npmp_forms_accent', '#1d4ed8;}</style><script>alert(1)</script>' );
check( 'a bad value in the database: the default', '#0f766e', npmp_forms_accent() );
npmp_forms_accent_add_inline_style();
check( 'and nothing printed', 0, count( $GLOBALS['npmp_test_inline_styles'] ) );

update_option( 'npmp_forms_accent', '#1d4ed8' );
$GLOBALS['npmp_test_registered'] = array();
npmp_forms_accent_add_inline_style();
check( 'stylesheet not registered: nothing printed', 0, count( $GLOBALS['npmp_test_inline_styles'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
