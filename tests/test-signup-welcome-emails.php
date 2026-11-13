<?php
/**
 * Signup and welcome email fixes from Pete Lord's two wordpress.org threads
 * ("Some minors in subscribing / unsubscribing" and "Web subscribe &
 * unsubscribe forms", 2026-11-13).
 *
 * - Post and event notifications and the weekly digest carry the newsletter
 *   footer (postal address and the reader's own unsubscribe link), not only a
 *   preferences link. npmp_email_recipient_footer() builds it, and Pro's
 *   automation emails use it too.
 * - The "please confirm" email takes extra lines from
 *   npmp_signup_confirmation_lines, so Pro can list the groups. Free adds none.
 * - The newsletter editor's tag help takes extra sentences from
 *   npmp_email_recipient_tag_help, so Pro can name [groups].
 * - One button color for the front-end forms (option npmp_forms_accent), with
 *   the hover shade and a label color that passes WCAG AA.
 *
 * Behaviour on a real WordPress: tests/rig/signup-welcome-emails.php.
 *
 * Run: php tests/test-signup-welcome-emails.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}

function npmp_swe_section( $src, $start, $end ) {
	$a = strpos( $src, $start );
	if ( false === $a ) {
		return '';
	}
	$b = strpos( $src, $end, $a + strlen( $start ) );
	return substr( $src, $a, false === $b ? 4000 : $b - $a );
}

echo "Footer on notifications and the digest\n";
$prefs  = file_get_contents( $root . '/includes/npmp-subscription-preferences.php' );
$spam   = file_get_contents( $root . '/includes/email-newsletter/can-spam-shortcode.php' );
check( 'Notification body ends with the shared footer', 1, substr_count( $prefs, "\$body .= '<div style=\"font-size:12px;color:#6b7280;\">' . npmp_email_recipient_footer( \$email ) . '</div>';" ) );
check( 'Digest body ends with the shared footer', 1, substr_count( $prefs, "\$personal_body .= '<div style=\"font-size:12px;color:#6b7280;\">' . npmp_email_recipient_footer( \$email ) . '</div>';" ) );
check( 'No preferences-only footer left behind', 0, substr_count( $prefs, "<p style=\"font-size:12px;color:#999;\"><a href=\"' . esc_url( \$prefs_url )" ) );
$footer = npmp_swe_section( $spam, 'function npmp_email_recipient_footer', "\n\t}\n}" );
check( 'Footer helper uses the newsletter footer with the reader\'s address', 1, substr_count( $footer, "npmp_can_spam_shortcode( array( 'email' => \$email ) )" ) );
check( 'Preferences link only when the site has a preferences page', 1, substr_count( $footer, 'npmp_get_preferences_page_id()' ) );

echo "Confirm email lines\n";
$forms   = file_get_contents( $root . '/includes/npmp-membership-forms.php' );
$confirm = npmp_swe_section( $forms, 'function npmp_send_signup_confirmation', 'function npmp_handle_confirm_signup' );
check( 'Filter offers extra lines with the signup fields and signed attributes', 1, substr_count( $confirm, "apply_filters( 'npmp_signup_confirmation_lines', array(), (int) \$contact_id, npmp_signup_fields_to_keep( \$post ), npmp_signup_atts_from_token( (string) \$atts_token ) )" ) );
check( 'Lines are stripped to plain text', 1, substr_count( $confirm, "array_map( 'wp_strip_all_tags'" ) );
check( 'Lines go after the thank-you paragraph', 1, substr_count( $confirm, "explode( \"\\n\\n\", \$body, 2 )" ) );

echo "Tag help\n";
$editor = file_get_contents( $root . '/includes/email-newsletter/editor.php' );
check( 'Newsletter editor appends add-on tag help', 1, substr_count( $editor, '[last_name] and [name] work too.\', \'nonprofit-manager\') . npmp_email_recipient_tag_help_suffix()' ) );
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
}
$GLOBALS['npmp_swe_help'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return 'npmp_email_recipient_tag_help' === $tag ? array_merge( (array) $value, $GLOBALS['npmp_swe_help'] ) : $value;
	}
}
$helpers = file_get_contents( $root . '/includes/email/helpers.php' );
eval( '?>' . '<?php ' . npmp_swe_section( $helpers, 'function npmp_email_recipient_tag_help_suffix', "\n}\n" ) . "\n}\n" );
check( 'Free alone adds nothing', '', npmp_email_recipient_tag_help_suffix() );
$GLOBALS['npmp_swe_help'] = array( '[groups] lists <groups>.', '', 7 );
check( 'An add-on sentence is escaped and joined', ' [groups] lists &lt;groups&gt;. 7', npmp_email_recipient_tag_help_suffix() );

echo "Form color\n";
require_once $root . '/includes/npmp-forms-accent.php';
check( 'Default is the brand teal', '#0f766e', npmp_forms_accent_default() );
check( 'Short hex widens', '#aabbcc', npmp_forms_accent_normalize( '#ABC' ) );
check( 'Junk is refused', '', npmp_forms_accent_normalize( 'red;}body{display:none' ) );
check( 'Default prints no CSS, so existing sites look the same', '', npmp_forms_accent_css( '#0F766E' ) );
check( 'Junk prints no CSS', '', npmp_forms_accent_css( 'url(x)' ) );
check( 'Brand teal on white is 5.47:1', 5.47, round( npmp_forms_accent_contrast( '#ffffff', '#0f766e' ), 2 ) );
check( 'Black on white is 21:1', 21.0, npmp_forms_accent_contrast( '#000000', '#ffffff' ) );

$navy = npmp_forms_accent_palette( '#1e3a8a' );
check( 'Dark color keeps white text', '#ffffff', $navy['text'] );
check( 'Dark color hovers darker', '#182e6e', $navy['dark'] );
check( 'Dark color needs no note', '', npmp_forms_accent_notice( '#1e3a8a' ) );
$css = npmp_forms_accent_css( '#1e3a8a' );
check( 'CSS sets all three variables on :root', 1, substr_count( $css, ':root{--npmp-accent:#1e3a8a;--npmp-accent-dark:#182e6e;--npmp-accent-text:#ffffff;}' ) );
check( 'CSS colors the contact form button too', 1, substr_count( $css, '.npmp-contact-form button[type="submit"]{color:var(--npmp-accent-text);background:var(--npmp-accent)' ) );
check( 'CSS has no way to close the style tag', false, false !== strpos( $css, '<' ) );

$yellow = npmp_forms_accent_palette( '#facc15' );
check( 'Light color fails white text', false, $yellow['white_text'] );
check( 'Light color gets dark text', '#111827', $yellow['text'] );
check( 'Dark text passes AA on it', true, npmp_forms_accent_contrast( $yellow['text'], $yellow['accent'] ) >= 4.5 );
check( 'Dark text passes AA on its hover shade', true, npmp_forms_accent_contrast( $yellow['text'], $yellow['dark'] ) >= 4.5 );
check( 'Light color hovers lighter', true, npmp_forms_accent_luminance( $yellow['dark'] ) > npmp_forms_accent_luminance( $yellow['accent'] ) );
check( 'Settings note names the dark text', 1, substr_count( npmp_forms_accent_notice( '#facc15' ), 'your form buttons use dark text' ) );
check( 'Very light color also warns about a white page', 1, substr_count( npmp_forms_accent_notice( '#fef9c3' ), 'hard to see on a white page' ) );

// Every color: the label passes 4.5:1 on the button and on its hover shade.
$worst = 99.0;
for ( $r = 0; $r < 256; $r += 17 ) {
	for ( $g = 0; $g < 256; $g += 17 ) {
		for ( $b = 0; $b < 256; $b += 17 ) {
			$p     = npmp_forms_accent_palette( sprintf( '#%02x%02x%02x', $r, $g, $b ) );
			$worst = min( $worst, npmp_forms_accent_contrast( $p['text'], $p['accent'] ), npmp_forms_accent_contrast( $p['text'], $p['dark'] ) );
		}
	}
}
check( 'Across 4,096 colors the label never drops below 4.5:1', true, $worst >= 4.5 );

$css_file = file_get_contents( $root . '/assets/css/npmp-forms.css' );
check( 'Both filled buttons read the label variable, white by default', 2, substr_count( $css_file, 'color: var(--npmp-accent-text, #fff);' ) );
check( 'Settings screen has the color field', 1, substr_count( $forms, 'name="npmp_forms_accent"' ) );
check( 'Save goes through the normalizer', 1, substr_count( $forms, "npmp_forms_accent_normalize( sanitize_text_field( wp_unslash( \$_POST['npmp_forms_accent'] ) ) )" ) );
check( 'Module loads', 1, substr_count( file_get_contents( $root . '/nonprofit-manager.php' ), "includes/npmp-forms-accent.php'" ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
