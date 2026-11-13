<?php
/**
 * Email Settings screen and the SMTP warning.
 *
 * - The SMTP warning links to Email Settings. It used to name the screen
 *   with no way to get there.
 * - The Encryption select has a label and an id, so a screen reader names it.
 *
 * Run: php tests/test-email-settings-ui.php
 */

require_once __DIR__ . '/bootstrap.php';

function current_user_can() {
	return true;
}
function get_current_screen() {
	return (object) array( 'id' => 'dashboard' );
}
function npmp_email_get_settings() {
	return array( 'provider' => 'smtp' );
}
function npmp_email_provider_requires_smtp() {
	return true;
}
function npmp_email_smtp_is_configured() {
	return false;
}
function npmp_label( $key ) {
	return 'club' === ( $GLOBALS['t_kind'] ?? '' ) ? 'Club Manager' : 'Nonprofit Manager';
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . $path;
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html__( $s ) {
	return esc_html( $s );
}

$src   = file_get_contents( __DIR__ . '/../includes/email/smtp.php' );
$start = strpos( $src, 'function npmp_email_admin_notices(' );
$end   = strpos( $src, "\n}", $start ) + 2;
eval( substr( $src, $start, $end - $start ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness.

echo "SMTP warning\n";
ob_start();
npmp_email_admin_notices();
$html = ob_get_clean();
check( 'links to Email Settings', true, false !== strpos( $html, '<a href="https://example.org/wp-admin/admin.php?page=npmp_email_settings">Email Settings</a>' ) );
$GLOBALS['t_kind'] = 'club';
ob_start();
npmp_email_admin_notices();
check( 'names the plugin by its menu name', true, false !== strpos( ob_get_clean(), '<div class="notice notice-warning"><p>Club Manager is set' ) );

echo "Email Settings markup\n";
$es = file_get_contents( __DIR__ . '/../includes/npmp-email-settings.php' );
check( 'Encryption has a label', true, false !== strpos( $es, '<label for="npmp_smtp_encryption">' ) );
check( 'Encryption select has the id', true, false !== strpos( $es, '<select id="npmp_smtp_encryption" name="npmp_smtp_encryption">' ) );
check( 'no inline style block', false, strpos( $es, '<style>' ) );
check( 'h1 uses the shared header', true, false !== strpos( $es, "npmp_admin_page_title( __( 'Email Settings', 'nonprofit-manager' ) )" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
