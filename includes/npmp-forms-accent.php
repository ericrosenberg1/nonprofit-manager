<?php
/**
 * File path: includes/npmp-forms-accent.php
 *
 * One button color for every front-end form: signup, unsubscribe,
 * preferences, contact, and Pro's membership join form. The owner picks it
 * on Membership Settings (option `npmp_forms_accent`). The default is the
 * brand teal #0f766e, and with the default saved, or nothing saved, the
 * site prints no extra CSS, so a site that never touches the setting looks
 * the same as before.
 *
 * The forms already read `--npmp-accent` and `--npmp-accent-dark`
 * (assets/css/npmp-forms.css). A chosen color sets both, plus
 * `--npmp-accent-text` for the button label. White text needs a contrast of
 * 4.5:1 (WCAG AA). On a color too light for that, the label turns dark and
 * the hover shade gets lighter instead of darker, and the settings screen
 * says so.
 *
 * Pure helpers first (tests/test-forms-accent.php loads them without
 * WordPress), then the hooks.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The brand teal the forms use when nobody picks a color.
 *
 * @return string
 */
function npmp_forms_accent_default() {
	return '#0f766e';
}

/**
 * A six-digit lowercase hex color, or '' for anything else. Accepts #abc.
 *
 * @param mixed $color Input.
 * @return string
 */
function npmp_forms_accent_normalize( $color ) {
	$color = strtolower( trim( (string) ( is_scalar( $color ) ? $color : '' ) ) );
	if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m ) ) {
		return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
	}
	return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '';
}

/**
 * Relative luminance of a hex color (WCAG 2.x formula).
 *
 * @param string $hex Six-digit hex color.
 * @return float 0 (black) to 1 (white).
 */
function npmp_forms_accent_luminance( $hex ) {
	$hex = ltrim( npmp_forms_accent_normalize( $hex ), '#' );
	if ( '' === $hex ) {
		return 0.0;
	}
	$out = array();
	foreach ( str_split( $hex, 2 ) as $pair ) {
		$c     = hexdec( $pair ) / 255;
		$out[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}
	return 0.2126 * $out[0] + 0.7152 * $out[1] + 0.0722 * $out[2];
}

/**
 * Contrast ratio between two colors, 1 to 21.
 *
 * @param string $a Hex color.
 * @param string $b Hex color.
 * @return float
 */
function npmp_forms_accent_contrast( $a, $b ) {
	$la = npmp_forms_accent_luminance( $a );
	$lb = npmp_forms_accent_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/**
 * Mix a color toward black (negative amount) or white (positive amount).
 *
 * @param string $hex    Hex color.
 * @param float  $amount -1 to 1.
 * @return string Hex color.
 */
function npmp_forms_accent_mix( $hex, $amount ) {
	$hex    = ltrim( npmp_forms_accent_normalize( $hex ), '#' );
	$target = $amount < 0 ? 0 : 255;
	$amount = min( 1, abs( (float) $amount ) );
	$out    = '#';
	foreach ( str_split( $hex, 2 ) as $pair ) {
		$c    = hexdec( $pair );
		$out .= str_pad( dechex( (int) round( $c + ( $target - $c ) * $amount ) ), 2, '0', STR_PAD_LEFT );
	}
	return $out;
}

/**
 * Everything the forms need from one chosen color.
 *
 * @param string $color Hex color.
 * @return array{accent:string,dark:string,text:string,white_text:bool,on_white:float}
 *         accent and dark (the hover shade) as hex, text for the button
 *         label, white_text false when white failed 4.5:1 and the label
 *         turned dark, and on_white the color's contrast against a white
 *         page (the button edge and the focus ring).
 */
function npmp_forms_accent_palette( $color ) {
	$accent = npmp_forms_accent_normalize( $color );
	if ( '' === $accent ) {
		$accent = npmp_forms_accent_default();
	}

	$white = npmp_forms_accent_contrast( '#ffffff', $accent ) >= 4.5;
	if ( $white ) {
		$text = '#ffffff';
		$dark = npmp_forms_accent_mix( $accent, -0.2 );
	} else {
		// The brand's heading color when it passes, black when it doesn't.
		// Black always passes here: a color white text fails on is light
		// enough for black text to clear 4.5:1.
		$text = npmp_forms_accent_contrast( '#111827', $accent ) >= 4.5 ? '#111827' : '#000000';
		$dark = npmp_forms_accent_mix( $accent, 0.2 );
	}

	return array(
		'accent'     => $accent,
		'dark'       => $dark,
		'text'       => $text,
		'white_text' => $white,
		'on_white'   => round( npmp_forms_accent_contrast( '#ffffff', $accent ), 2 ),
	);
}

/**
 * The CSS for a chosen color, or '' for the default.
 *
 * @param string $color Hex color.
 * @return string
 */
function npmp_forms_accent_css( $color ) {
	$accent = npmp_forms_accent_normalize( $color );
	if ( '' === $accent || npmp_forms_accent_default() === $accent ) {
		return '';
	}
	$p = npmp_forms_accent_palette( $accent );

	// The contact form's button has no color of its own and follows the
	// theme, so it takes the chosen color only once an owner picks one.
	return ':root{--npmp-accent:' . $p['accent'] . ';--npmp-accent-dark:' . $p['dark'] . ';--npmp-accent-text:' . $p['text'] . ';}'
		. '.npmp-contact-form button[type="submit"]{color:var(--npmp-accent-text);background:var(--npmp-accent);border:1px solid var(--npmp-accent);border-radius:8px;padding:.65rem 1.4rem;font-weight:600;cursor:pointer;}'
		. '.npmp-contact-form button[type="submit"]:hover{background:var(--npmp-accent-dark);border-color:var(--npmp-accent-dark);}'
		. '.npmp-contact-form button[type="submit"]:focus-visible{outline:2px solid var(--npmp-accent-dark);outline-offset:2px;}'
		. '.npmp-contact-form input:focus,.npmp-contact-form textarea:focus{outline:2px solid var(--npmp-accent);outline-offset:1px;}';
}

/**
 * A note for the settings screen about a chosen color, or ''.
 *
 * @param string $color Hex color.
 * @return string Plain text.
 */
function npmp_forms_accent_notice( $color ) {
	$p = npmp_forms_accent_palette( $color );
	if ( $p['white_text'] ) {
		return '';
	}
	$note = __( 'White text is hard to read on that color, so your form buttons use dark text.', 'nonprofit-manager' );
	if ( $p['on_white'] < 3 ) {
		$note .= ' ' . __( 'The buttons may also be hard to see on a white page, so pick a darker color if your pages are white.', 'nonprofit-manager' );
	}
	return $note;
}

/**
 * The saved color, or the default.
 *
 * @return string
 */
function npmp_forms_accent() {
	$saved = npmp_forms_accent_normalize( get_option( 'npmp_forms_accent', '' ) );
	return '' !== $saved ? $saved : npmp_forms_accent_default();
}

/**
 * Put the chosen color on the form stylesheet. Runs after
 * npmp_register_form_styles() registers it, and prints wherever the
 * stylesheet prints, including a block that enqueues it late.
 *
 * @return void
 */
function npmp_forms_accent_add_inline_style() {
	$css = npmp_forms_accent_css( npmp_forms_accent() );
	if ( '' !== $css && wp_style_is( 'npmp-forms', 'registered' ) ) {
		wp_add_inline_style( 'npmp-forms', $css );
	}
}
add_action( 'wp_enqueue_scripts', 'npmp_forms_accent_add_inline_style', 20 );
