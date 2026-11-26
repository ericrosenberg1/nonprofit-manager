<?php
/**
 * A remembered "no preferences page" miss clears when pages change.
 *
 * npmp_get_preferences_page_id() cached a miss for an hour and nothing cleared
 * it, so a preferences page created after a miss stayed unseen and the email
 * footer lost "Manage your email preferences" (Pete, wordpress.org, 11-19).
 * The WordPress side runs in tests/rig/form-pages.php.
 *
 * Run: php tests/test-preferences-page-miss.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_hooks']      = array();
$GLOBALS['t_transients'] = array();
$GLOBALS['t_types']      = array( 10 => 'page', 11 => 'post' );
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb ) { $GLOBALS['t_hooks'][ $hook ][] = $cb; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) { unset( $GLOBALS['t_transients'][ $key ] ); return true; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) { return $GLOBALS['t_types'][ $id ] ?? false; }
}
require_once dirname( __DIR__ ) . '/includes/npmp-form-pages.php';

function t_run( $hook, $id ) {
	foreach ( $GLOBALS['t_hooks'][ $hook ] ?? array() as $cb ) {
		call_user_func( $cb, $id );
	}
}
function t_miss() { $GLOBALS['t_transients']['npmp_prefs_page_lookup'] = 1; }

echo "== Page changes clear the miss ==\n";
foreach ( array( 'save_post_page', 'wp_trash_post', 'untrashed_post', 'before_delete_post' ) as $hook ) {
	t_miss();
	t_run( $hook, 10 );
	check( "$hook on a page", false, isset( $GLOBALS['t_transients']['npmp_prefs_page_lookup'] ) );
}
t_miss();
t_run( 'wp_trash_post', 11 );
check( 'A post being trashed keeps the miss', true, isset( $GLOBALS['t_transients']['npmp_prefs_page_lookup'] ) );

echo "== Membership Settings save clears it ==\n";
$forms = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-membership-forms.php' );
check( 'Save handler calls the clearer after saving', 1, preg_match( '/update_option\( \$option_key, \$settings \);\s*npmp_forget_preferences_page_miss\(\);/', $forms ) );
t_miss();
npmp_forget_preferences_page_miss();
check( 'The clearer deletes the transient', false, isset( $GLOBALS['t_transients']['npmp_prefs_page_lookup'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
