<?php
/**
 * [npmp_email_signup] attributes that no active plugin accepts
 * (npmp_signup_form_unknown_atts_note() in includes/npmp-membership-forms.php).
 *
 * Pete at U3A pasted Pro's groups="" choose="yes" onto a form and got a plain
 * form with no word about why (wordpress.org thread "Some minors in
 * subscribing / unsubscribing"). An administrator now sees a note naming the
 * attributes the form left out, and a visitor sees nothing.
 *
 * Run: php tests/test-signup-unknown-atts.php
 */

require_once __DIR__ . '/bootstrap.php';

$forms = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-membership-forms.php' );

function section( $src, $start, $end ) {
	$a = strpos( $src, $start );
	if ( false === $a ) {
		return '';
	}
	$b = strpos( $src, $end, $a + strlen( $start ) );
	return substr( $src, $a, false === $b ? 4000 : $b - $a );
}

$GLOBALS['t_user'] = array( 'in' => true, 'caps' => array( 'manage_options' ) );
function is_user_logged_in() {
	return $GLOBALS['t_user']['in'];
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['t_user']['caps'], true );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

$note = section( $forms, 'function npmp_signup_form_unknown_atts_note', "\n}\n" ) . "\n}\n";
eval( '?>' . '<?php ' . $note );

echo "Administrators see which attributes the form left out\n";
$out = npmp_signup_form_unknown_atts_note( array( 'groups', 'choose' ) );
check( 'both names are in the note', true, false !== strpos( $out, 'the attributes groups, choose.' ) );
check( 'the note says who sees it', true, false !== strpos( $out, 'Only administrators see this note.' ) );
check( 'it points at Pro and the spelling', true, false !== strpos( $out, 'you need Nonprofit Manager Pro with an active license, or check the spelling' ) );
check( 'one name reads as one attribute', true, false !== strpos( npmp_signup_form_unknown_atts_note( array( 'checked' ) ), 'without the attribute checked. To use it,' ) );
check( 'a name repeated once is listed once', 1, substr_count( npmp_signup_form_unknown_atts_note( array( 'groups', 'GROUPS' ) ), 'groups' ) );
check( 'markup in a name never reaches the page', false, strpos( npmp_signup_form_unknown_atts_note( array( '<script>x' ) ), '<script>' ) );
check( 'positional values (numeric keys) are not attribute names', '', npmp_signup_form_unknown_atts_note( array( 0, 1 ) ) );
check( 'nothing unknown means no note', '', npmp_signup_form_unknown_atts_note( array() ) );

echo "Visitors and staff without manage_options see nothing\n";
$GLOBALS['t_user'] = array( 'in' => false, 'caps' => array() );
check( 'a visitor sees no note', '', npmp_signup_form_unknown_atts_note( array( 'groups' ) ) );
$GLOBALS['t_user'] = array( 'in' => true, 'caps' => array( 'edit_posts' ) );
check( 'an editor sees no note', '', npmp_signup_form_unknown_atts_note( array( 'groups' ) ) );

echo "The shortcode passes what shortcode_atts() drops\n";
$sc = section( $forms, 'function npmp_email_signup_shortcode', "add_shortcode( 'npmp_email_signup'" );
check( 'unknown names come from the raw attributes minus the defaults', 1, substr_count( $sc, 'npmp_signup_form_unknown_atts_note( array_keys( array_diff_key( $raw, $defaults ) ) )' ) );
check( 'the note sits above the form', true, strpos( $sc, 'npmp_signup_form_unknown_atts_note(' ) < strpos( $sc, '<form action=' ) );
check( 'the form still reads its attributes through the add-on defaults', 1, substr_count( $sc, "shortcode_atts( \$defaults, \$raw, 'npmp_email_signup' )" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
