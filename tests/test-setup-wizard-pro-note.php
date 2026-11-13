<?php
/**
 * The Pro note the free setup wizard renders
 * (includes/npmp-setup-wizard.php) and how visible its link is.
 *
 * The activation redirect (2026.10.2) makes this note the first and only Pro
 * offer most new installs ever see, and GA4 recorded no click on its
 * utm_campaign=setup_wizard link while it was the word "Learn more" inside a
 * paragraph. These checks render the real wizard and pin what the note is
 * made of: its own card, a heading, and a link that looks like a button, so
 * the markup cannot quietly go back to body text. The one thing it must not
 * become is a second button-primary, which would compete with Complete Setup.
 *
 * Run: php tests/test-setup-wizard-pro-note.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();

// WordPress pieces the wizard reaches for while it renders.
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['t_options'][ $name ] );
	return true;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_html_e( $text, $domain = null ) {
	echo htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_url( $url ) {
	// WordPress encodes a bare ampersand in an attribute. Keep that here, so
	// a check written against the rendered page matches the real page.
	return str_replace( '&', '&#038;', (string) $url );
}
function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) {
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $out;
}
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">';
}
function wp_get_current_user() {
	return (object) array( 'user_email' => 'admin@example.org' );
}
function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	printf( '<input type="submit" name="%s" class="button button-%s" value="%s">', esc_attr( $name ), esc_attr( $type ), esc_attr( $text ) );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}
require_once dirname( __DIR__ ) . '/includes/npmp-org-type.php';
require_once dirname( __DIR__ ) . '/includes/npmp-version.php';
require_once dirname( __DIR__ ) . '/includes/npmp-admin-helpers.php';
require_once dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php';

/**
 * The wizard's HTML as the plugin renders it right now.
 *
 * npmp_is_pro() reads a constant, and a constant cannot be taken back, so the
 * free page has to be rendered before NPMP_PRO_VERSION is defined below.
 *
 * @return string
 */
function t_wizard_html() {
	$GLOBALS['t_options'] = array();
	ob_start();
	npmp_render_setup_wizard();
	return (string) ob_get_clean();
}

/**
 * The whole <a ...>...</a> for a href, or '' when there is no such link.
 *
 * @param string $html Rendered page.
 * @param string $href Link target, already escaped the way the page has it.
 * @return string
 */
function t_anchor( $html, $href ) {
	$found = preg_match( '#<a\b[^>]*href="' . preg_quote( $href, '#' ) . '"[^>]*>.*?</a>#s', $html, $m );
	return $found ? $m[0] : '';
}

$free   = t_wizard_html();
$href   = esc_url( npmp_get_upgrade_url( 'setup_wizard' ) );
$anchor = t_anchor( $free, $href );
define( 'NPMP_PRO_VERSION', '2026.10.11' );
$pro = t_wizard_html();

echo "The note is on the free wizard\n";
check( 'the note keeps its heading', true, false !== strpos( $free, 'Want more features?' ) );
check( 'the note keeps what Pro adds', true, false !== strpos( $free, 'With Nonprofit Manager Pro, you also get groups, recurring gifts, dues billing, email automations, custom fields, and segments.' ) );
check( 'the note is its own card, not an admin notice', true, false !== strpos( $free, 'class="npmp-pro-note"' ) );
check( 'the card is styled', true, false !== strpos( $free, '.npmp-setup-wizard .npmp-pro-note {' ) );
check( 'Pro installs are not sold Pro', '', t_anchor( $pro, $href ) );
check( 'and Pro sees no note', false, strpos( $pro, 'Want more features?' ) );

echo "\nThe link\n";
check( 'the note links to the pricing page', true, '' !== $anchor );
check( 'the link carries this screen\'s campaign', true, false !== strpos( $anchor, 'utm_campaign=setup_wizard' ) );
check( 'the link looks like a button', 1, preg_match( '/class="[^"]*\bbutton\b[^"]*"/', $anchor ) );
check( 'but not a second primary button', 0, preg_match( '/class="[^"]*\bbutton-primary\b/', $anchor ) );
check( 'the link opens in a new tab', true, false !== strpos( $anchor, 'target="_blank"' ) );
check( 'a new tab cannot reach back into the admin', true, false !== strpos( $anchor, 'rel="noopener"' ) );
check( 'the label says what is on the other side', true, false !== strpos( $anchor, 'See Pro' ) );
// It used to live inside the note's own paragraph, which is how it ended up
// reading as body text. Nothing on the page may wrap it in a <p> again.
check( 'the link is not buried in a paragraph', 0, preg_match( '#<p\b[^>]*>(?:(?!</p>).)*href="' . preg_quote( $href, '#' ) . '#s', $free ) );

echo "\nComplete Setup is still the main action\n";
// Count the class on elements only. The page also carries a stylesheet and
// a comment that name button-primary.
check( 'exactly one element is a button-primary', 1, preg_match_all( '/class="[^"]*\bbutton-primary\b[^"]*"/', $free ) );
check( 'and it is Complete Setup', true, false !== strpos( $free, 'value="Complete Setup"' ) );
check( 'Skip is still there', true, false !== strpos( $free, 'name="npmp_setup_skip"' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
