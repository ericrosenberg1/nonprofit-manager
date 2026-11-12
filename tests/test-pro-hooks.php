<?php
/**
 * Hooks Pro listens for, fired from the free screens.
 *
 * - npmp_email_settings_before_form: Pro's email provider picker. Free never
 *   fired it, so the picker never showed.
 * - npmp_calendar_settings_fields, npmp_calendar_save_appearance, and
 *   npmp_calendar_inline_css: Pro's calendar custom CSS and category colors.
 *   Free fired none of them.
 * - Category color rows hang off core's own npmp_event_category_add_form_fields
 *   and _edit_form_fields, which WordPress fires for any taxonomy with a UI,
 *   so free needs the taxonomy name to stay npmp_event_category.
 * - Pro's custom fields cover contacts and events, and Pro names the annual
 *   schedule "Yearly".
 *
 * Run: php tests/test-pro-hooks.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_filters'] = array();
$GLOBALS['t_actions'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $cb;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {
	if ( $cb ) {
		$GLOBALS['t_actions'][ $hook ][] = $cb;
	}
}
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['t_actions'][ $hook ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}

$src = file_get_contents( __DIR__ . '/../includes/npmp-calendar.php' );
foreach ( array( 'npmp_calendar_appearance', 'npmp_calendar_inline_css', 'npmp_calendar_save_appearance' ) as $fn ) {
	$start = strpos( $src, "function $fn(" );
	$end   = strpos( $src, "\n}", $start ) + 2;
	eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness.
}

echo "Calendar appearance\n";
check( 'free alone prints no inline CSS', '', npmp_calendar_inline_css() );
npmp_calendar_save_appearance( array( 'custom_css' => '.x{}' ) );
check( 'free alone saves nothing it does not know', array(), get_option( 'npmp_calendar_appearance' ) );

// Pro's filters, with its signatures (includes/pro-calendar.php).
add_filter(
	'npmp_calendar_save_appearance',
	function ( $clean, $incoming ) {
		$clean['custom_css'] = str_ireplace( '</style>', '', (string) ( $incoming['custom_css'] ?? '' ) );
		return $clean;
	}
);
add_filter(
	'npmp_calendar_inline_css',
	function ( $inline, $settings ) {
		return $inline . ( $settings['custom_css'] ?? '' );
	}
);
npmp_calendar_save_appearance( array( 'custom_css' => '.npmp-calendar-wrapper{color:red}</style>' ) );
check( 'Pro gets the raw post and sanitizes its key', array( 'custom_css' => '.npmp-calendar-wrapper{color:red}' ), get_option( 'npmp_calendar_appearance' ) );
check( 'Pro CSS comes back through the inline filter', '.npmp-calendar-wrapper{color:red}', npmp_calendar_inline_css() );
$GLOBALS['t_filters'] = array();
npmp_calendar_save_appearance( array() );
check( 'saving with Pro off keeps Pro\'s CSS', '.npmp-calendar-wrapper{color:red}', get_option( 'npmp_calendar_appearance' )['custom_css'] );

echo "Hooks fired on the screens\n";
check( 'Calendar Settings fires the fields action with the appearance', true, false !== strpos( $src, "do_action( 'npmp_calendar_settings_fields', npmp_calendar_appearance() );" ) );
check( 'the calendar stylesheet gets the inline CSS', true, false !== strpos( $src, "wp_add_inline_style( 'npmp-calendar', \$inline );" ) );
check( 'the save handler passes the posted appearance', true, false !== strpos( $src, "npmp_calendar_save_appearance( isset( \$_POST['npmp_calendar_appearance'] )" ) );
check( 'taxonomy name core builds the category hooks from', true, false !== strpos( $src, "register_taxonomy( 'npmp_event_category'" ) );
$es   = file_get_contents( __DIR__ . '/../includes/npmp-email-settings.php' );
$hook = strpos( $es, "do_action( 'npmp_email_settings_before_form' );" );
check( 'Email Settings fires the before-form action', true, false !== $hook );
check( 'and fires it outside the settings form', true, false !== $hook && $hook < strpos( $es, "wp_nonce_field( 'npmp_email_settings', 'npmp_email_settings_nonce' )" ) );

echo "Copy that matches Pro\n";
$pages = file_get_contents( __DIR__ . '/../includes/npmp-pro-feature-pages.php' );
check( 'custom fields: contacts and events only', true, false !== strpos( $pages, "'Add your own fields to %s and events" ) );
check( 'custom fields: no imports or donations claim', false, (bool) preg_match( '/Add your own fields[^\']*(imports|%2\$s)/', $pages ) );
$pay = file_get_contents( __DIR__ . '/../includes/npmp-payments-settings.php' );
check( 'annual schedule reads Yearly', false, strpos( $pay, "esc_html_e( 'Annual', 'nonprofit-manager' )" ) );
check( 'frequency label reads Yearly', 2, substr_count( $pay, "=> __( 'Yearly', 'nonprofit-manager' )," ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
