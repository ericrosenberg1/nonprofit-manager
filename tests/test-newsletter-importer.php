<?php
/**
 * The Newsletter Plugin importer: the pure parts
 * (includes/import/newsletter-importer.php).
 *
 * Run: php tests/test-newsletter-importer.php
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( array( 'add_filter', 'add_action' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/mailpoet-importer.php';
require_once $root . '/includes/import/newsletter-importer.php';

echo "Status\n";
check( 'C is confirmed', 'confirmed', npmp_tnp_status_group( 'C' ) );
check( 'S is not confirmed', 'not_confirmed', npmp_tnp_status_group( 'S' ) );
check( 'U is unsubscribed', 'unsubscribed', npmp_tnp_status_group( 'U' ) );
check( 'B is bounced', 'bounced', npmp_tnp_status_group( 'B' ) );
check( 'P (complained) counts with bounced', 'bounced', npmp_tnp_status_group( 'P' ) );
check( 'T (temporary) stays behind', '', npmp_tnp_status_group( 'T' ) );
check( 'lower case and spaces', 'confirmed', npmp_tnp_status_group( ' c ' ) );
check( 'unknown letter waits as not confirmed', 'not_confirmed', npmp_tnp_status_group( 'X' ) );
check( 'confirmed becomes subscribed', 'subscribed', npmp_mailpoet_map_status( npmp_tnp_mailpoet_status( 'C' ) ) );
check( 'not confirmed becomes pending', 'pending', npmp_mailpoet_map_status( npmp_tnp_mailpoet_status( 'S' ) ) );
check( 'unsubscribed stays unsubscribed', 'unsubscribed', npmp_mailpoet_map_status( npmp_tnp_mailpoet_status( 'U' ) ) );
check( 'bounced becomes unsubscribed', 'unsubscribed', npmp_mailpoet_map_status( npmp_tnp_mailpoet_status( 'B' ) ) );
check( 'complained becomes unsubscribed', 'unsubscribed', npmp_mailpoet_map_status( npmp_tnp_mailpoet_status( 'P' ) ) );
check( 'temporary has no status', '', npmp_tnp_mailpoet_status( 'T' ) );

echo "Lists\n";
check( 'named lists only', array( 1 => 'Members', 3 => 'Volunteers' ), npmp_tnp_list_names( array( 'list_1' => 'Members', 'list_2' => '', 'list_3' => ' Volunteers ', 'list_3_status' => 1 ) ) );
check( 'tags stripped', array( 2 => 'Board' ), npmp_tnp_list_names( array( 'list_2' => '<b>Board</b>' ) ) );
check( 'stops at the max', array(), npmp_tnp_list_names( array( 'list_41' => 'Late' ), 40 ) );
check( 'not an array', array(), npmp_tnp_list_names( 'x' ) );

echo "Settings\n";
$read = npmp_tnp_read_settings( array( 'sender_name' => 'Our Club', 'sender_email' => 'news@club.org', 'reply_to' => 'help@club.org', 'smtp_pass' => 'nope' ), array( 'noconfirmation' => '0' ) );
check( 'sender name', 'Our Club', $read['from_name'] );
check( 'sender email', 'news@club.org', $read['from_email'] );
check( 'reply-to', 'help@club.org', $read['reply_to'] );
check( 'noconfirmation 0 means double opt-in', true, $read['double_optin'] );
check( 'only four keys come back', array( 'from_name', 'from_email', 'reply_to', 'double_optin' ), array_keys( $read ) );
check( 'noconfirmation 1 means single opt-in', false, npmp_tnp_read_settings( array(), array( 'noconfirmation' => 1 ) )['double_optin'] );
check( 'unsaved opt-in reads as unknown', null, npmp_tnp_read_settings( array(), array() )['double_optin'] );
check( 'control characters cleaned', 'A B', npmp_tnp_read_settings( array( 'sender_name' => "A\nB" ), array() )['from_name'] );

$plan = npmp_mailpoet_settings_plan(
	$read,
	array(
		'from_name'    => array( 'value' => '', 'set' => false ),
		'from_email'   => array( 'value' => 'me@site.org', 'set' => true ),
		'double_optin' => array( 'value' => false, 'set' => false ),
	)
);
check( 'sender name starts checked when yours is unset', true, $plan['from_name']['checked'] );
check( 'sender email starts unchecked when you saved one', false, $plan['from_email']['checked'] );
check( 'reply-to has no match here', 'unsupported', $plan['reply_to']['state'] );
check( 'double opt-in on starts checked', true, $plan['double_optin']['checked'] );

echo "Secrets\n";
$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( $root . '/includes/import/newsletter-importer.php' ) );
check( 'reads only three options by name', array( 'newsletter_lists', 'newsletter_main', 'newsletter_subscription' ), ( function () use ( $code ) {
	preg_match_all( "/get_option\(\s*'([a-z_]+)'/", $code, $m );
	$names = array_values( array_unique( array_filter( $m[1], function ( $n ) {
		return 0 === strpos( $n, 'newsletter' );
	} ) ) );
	sort( $names );
	return $names;
} )() );
check( 'no password or key names', 0, preg_match( '/smtp|password|api_key|licen[cs]e_key/i', $code ) );

echo "Shared writer\n";
$mp = file_get_contents( $root . '/includes/import/mailpoet-importer.php' );
check( 'writer takes a source', 1, preg_match( "/function npmp_mailpoet_import_subscriber\( \\\$row, \\\$existing = null, array \\\$lists = array\(\), \\\$source = 'mailpoet' \)/", $mp ) );
check( 'newsletter importer passes its source', 1, substr_count( $code, "npmp_mailpoet_import_subscriber( \$row, \$known, \$row->lists, 'newsletter' )" ) );
check( 'loaded after the MailPoet importer', true, strpos( file_get_contents( $root . '/nonprofit-manager.php' ), 'newsletter-importer.php' ) > strpos( file_get_contents( $root . '/nonprofit-manager.php' ), 'mailpoet-importer.php' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
