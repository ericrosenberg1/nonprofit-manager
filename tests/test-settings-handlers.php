<?php
/**
 * Settings form handlers act only on their own screen.
 *
 * General Settings and Membership Settings both posted membership level forms
 * with the same nonce names (npmp_add_level_nonce and friends). General
 * Settings' admin_init handler ran first, saved the level, and redirected to
 * General Settings, so adding a level on Membership Settings landed you on
 * the wrong screen and its own handler never ran. Levels now live on
 * Membership Settings alone, and each handler checks the page it serves.
 *
 * Run: php tests/test-settings-handlers.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options']  = array();
$GLOBALS['t_can']      = true;
$GLOBALS['t_redirect'] = null;

class T_Redirect extends Exception {}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function wp_verify_nonce( $nonce, $action ) {
	return 'nonce-' . $action === $nonce ? 1 : false;
}
function sanitize_text_field( $v ) {
	return trim( (string) $v );
}
function sanitize_key( $v ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) );
}
function wp_unslash( $v ) {
	return $v;
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . $path;
}
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}
function wp_safe_redirect( $url ) {
	$GLOBALS['t_redirect'] = $url;
	throw new T_Redirect( $url );
}
function npmp_is_pro() {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/npmp-general-settings.php';
require_once dirname( __DIR__ ) . '/includes/npmp-membership-forms.php';

/**
 * Run one admin_init handler with a request, and report what it did.
 *
 * @param callable $handler Handler.
 * @param array    $get     $_GET.
 * @param array    $post    $_POST.
 * @return string|null Redirect URL, or null when the handler returned.
 */
function t_run( $handler, $get, $post ) {
	$_GET                  = $get;
	$_POST                 = $post;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$GLOBALS['t_redirect'] = null;
	try {
		$handler();
	} catch ( T_Redirect $e ) {
		return $e->getMessage();
	}
	return null;
}

$add = array(
	'npmp_add_level_nonce' => 'nonce-npmp_add_membership_level',
	'npmp_new_level'       => 'Gold',
);

echo "A level added on Membership Settings\n";
$GLOBALS['t_options'] = array( 'npmp_membership_levels' => array( 'Member' ) );
check( 'General Settings handler ignores it', null, t_run( 'npmp_general_settings_handle_post', array( 'page' => 'npmp_membership_forms' ), $add ) );
check( 'nothing saved by General Settings', array( 'Member' ), get_option( 'npmp_membership_levels' ) );
$to = t_run( 'npmp_membership_levels_handle_post', array( 'page' => 'npmp_membership_forms' ), $add );
check( 'Membership Settings handler saves it', array( 'Member', 'Gold' ), get_option( 'npmp_membership_levels' ) );
check( 'and comes back to Membership Settings', 0, strpos( (string) $to, 'https://example.org/wp-admin/admin.php?page=npmp_membership_forms&npmp_levels=added' ) );

echo "General Settings no longer edits levels\n";
$GLOBALS['t_options'] = array( 'npmp_membership_levels' => array( 'Member' ) );
check( 'level post on General Settings does nothing', null, t_run( 'npmp_general_settings_handle_post', array( 'page' => 'npmp_general_settings' ), $add ) );
check( 'levels unchanged', array( 'Member' ), get_option( 'npmp_membership_levels' ) );

echo "Membership Settings handler stays on its own page\n";
check( 'level post on General Settings: Membership handler returns', null, t_run( 'npmp_membership_levels_handle_post', array( 'page' => 'npmp_general_settings' ), $add ) );
check( 'no page at all: returns', null, t_run( 'npmp_membership_levels_handle_post', array(), $add ) );
check( 'levels still unchanged', array( 'Member' ), get_option( 'npmp_membership_levels' ) );

echo "Remove and default\n";
$GLOBALS['t_options'] = array(
	'npmp_membership_levels'        => array( 'Member', 'Gold' ),
	'npmp_default_membership_level' => 'Gold',
);
$to = t_run(
	'npmp_membership_levels_handle_post',
	array( 'page' => 'npmp_membership_forms' ),
	array(
		'npmp_remove_level_nonce' => 'nonce-npmp_remove_membership_level',
		'npmp_level_to_remove'    => 'Gold',
	)
);
check( 'removed', array( 'Member' ), get_option( 'npmp_membership_levels' ) );
check( 'removed default cleared', '', get_option( 'npmp_default_membership_level' ) );
check( 'removed notice flag', true, false !== strpos( (string) $to, 'npmp_levels=removed' ) );
$to = t_run(
	'npmp_membership_levels_handle_post',
	array( 'page' => 'npmp_membership_forms' ),
	array(
		'npmp_default_level_nonce'      => 'nonce-npmp_save_default_level',
		'npmp_default_membership_level' => 'Member',
	)
);
check( 'default saved', 'Member', get_option( 'npmp_default_membership_level' ) );
check( 'default notice flag', true, false !== strpos( (string) $to, 'npmp_levels=default' ) );

echo "Guards\n";
$GLOBALS['t_options'] = array( 'npmp_membership_levels' => array( 'Member' ) );
check( 'bad nonce: returns', null, t_run( 'npmp_membership_levels_handle_post', array( 'page' => 'npmp_membership_forms' ), array( 'npmp_add_level_nonce' => 'wrong', 'npmp_new_level' => 'Gold' ) ) );
$GLOBALS['t_can'] = false;
check( 'no manage_options: returns', null, t_run( 'npmp_membership_levels_handle_post', array( 'page' => 'npmp_membership_forms' ), $add ) );
check( 'no manage_options: nothing saved', array( 'Member' ), get_option( 'npmp_membership_levels' ) );
$GLOBALS['t_can'] = true;

echo "Starter levels\n";
$GLOBALS['t_options'] = array();
npmp_maybe_seed_membership_levels();
check( 'never-saved option gets the four starters', array( 'Subscriber', 'Member', 'Donor', 'Director' ), get_option( 'npmp_membership_levels' ) );
$GLOBALS['t_options'] = array( 'npmp_membership_levels' => array() );
npmp_maybe_seed_membership_levels();
check( 'an emptied list stays empty', array(), get_option( 'npmp_membership_levels' ) );

$gs_src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-general-settings.php' );
check( 'General Settings never re-seeds an emptied list', false, strpos( $gs_src, "update_option( 'npmp_membership_levels'" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
