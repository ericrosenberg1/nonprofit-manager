<?php
/**
 * FluentCRM importer: the pure parts (includes/import/fluentcrm-importer.php).
 *
 * Run: php tests/test-fluentcrm-importer.php
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
require_once $root . '/includes/import/fluentcrm-importer.php';

echo "Statuses\n";
foreach ( array(
	'subscribed'    => array( 'subscribed', 'subscribed', 'subscribed' ),
	'pending'       => array( 'pending', 'unconfirmed', 'pending' ),
	'unsubscribed'  => array( 'unsubscribed', 'unsubscribed', 'unsubscribed' ),
	'transactional' => array( 'transactional', 'inactive', 'inactive' ),
	'bounced'       => array( 'bounced', 'bounced', 'unsubscribed' ),
	'complained'    => array( 'bounced', 'bounced', 'unsubscribed' ),
	'spammed'       => array( 'bounced', 'bounced', 'unsubscribed' ),
	'Subscribed '   => array( 'subscribed', 'subscribed', 'subscribed' ),
	'mystery'       => array( 'pending', 'unconfirmed', 'pending' ),
) as $raw => $want ) {
	check( "$raw group", $want[0], npmp_fcrm_status_group( $raw ) );
	check( "$raw as MailPoet", $want[1], npmp_fcrm_mailpoet_status( $raw ) );
	check( "$raw as a contact", $want[2], npmp_mailpoet_map_status( npmp_fcrm_mailpoet_status( $raw ) ) );
}
check( 'no status ever comes over as subscribed except subscribed', array( 'subscribed' ), array_values( array_unique( array_filter( array_map( function ( $s ) {
	return 'subscribed' === npmp_mailpoet_map_status( npmp_fcrm_mailpoet_status( $s ) ) ? $s : '';
}, array( 'subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed', '' ) ) ) ) ) );

echo "\nSettings\n";
check(
	'reads the three sender keys',
	array( 'from_name' => 'Club', 'from_email' => 'a@b.org', 'reply_to' => 'r@b.org', 'double_optin' => null ),
	npmp_fcrm_read_settings( array( 'email_settings' => array( 'from_name' => " Club\n", 'from_email' => 'a@b.org', 'reply_to_email' => 'r@b.org', 'email_footer' => 'x', 'smtp_password' => 'never' ) ) )
);
check( 'nothing saved', array( 'from_name' => '', 'from_email' => '', 'reply_to' => '', 'double_optin' => null ), npmp_fcrm_read_settings( false ) );

echo "\nCustom field types\n";
check( 'select-one', array( 'dropdown', array( 'S', 'M' ) ), array_values( array_slice( npmp_fcrm_extra_field_shape( array( 'type' => 'select-one', 'options' => array( 'S', ' M ', '' ) ) ), 0, 2 ) ) );
check( 'radio', 'dropdown', npmp_fcrm_extra_field_shape( array( 'type' => 'radio' ) )['type'] );
check( 'select-multi', 'checkbox', npmp_fcrm_extra_field_shape( array( 'type' => 'select-multi', 'options' => array( 'A' ) ) )['type'] );
check( 'checkbox keeps choices', array( 'A', 'B' ), npmp_fcrm_extra_field_shape( array( 'type' => 'checkbox', 'options' => array( 'A', 'B' ) ) )['options'] );
check( 'date', 'date', npmp_fcrm_extra_field_shape( array( 'type' => 'date' ) )['type'] );
check( 'date_time is text with a note', array( 'text', true ), array( npmp_fcrm_extra_field_shape( array( 'type' => 'date_time' ) )['type'], '' !== npmp_fcrm_extra_field_shape( array( 'type' => 'date_time' ) )['note'] ) );
check( 'number', 'number', npmp_fcrm_extra_field_shape( array( 'type' => 'number' ) )['type'] );
check( 'textarea', 'textarea', npmp_fcrm_extra_field_shape( array( 'type' => 'textarea' ) )['type'] );
check( 'unknown is text', 'text', npmp_fcrm_extra_field_shape( array( 'type' => 'map' ) )['type'] );
check( 'text keeps no options', array(), npmp_fcrm_extra_field_shape( array( 'type' => 'text', 'options' => array( 'x' ) ) )['options'] );

echo "\nTags\n";
check( 'adds new tags after yours', 'Local,Board,VIP', npmp_fcrm_merge_tags( 'Local', array( 'Board', 'VIP' ) ) );
check( 'letter case makes no new tag, and yours wins', 'Local,board', npmp_fcrm_merge_tags( 'Local,board', array( 'Board', 'LOCAL' ) ) );
check( 'commas inside a tag name never split it', 'Friends and Family', npmp_fcrm_merge_tags( '', array( 'Friends and Family' ) ) );
check( 'a comma in a FluentCRM tag becomes a space', 'A B', npmp_fcrm_merge_tags( '', array( 'A,B' ) ) );
check( 'empty stays empty', '', npmp_fcrm_merge_tags( '', array( '', ' ' ) ) );
check( 'semicolons in yours split', 'a,b,c', npmp_fcrm_merge_tags( 'a;b', array( 'c' ) ) );

echo "\nWiring\n";
$code = file_get_contents( $root . '/includes/import/fluentcrm-importer.php' );
check( 'the shared writer runs with the source', 1, substr_count( $code, "npmp_mailpoet_import_subscriber( \$row, \$known, \$row->lists, 'fluent-crm' )" ) );
check( 'the free total is asked before each new contact', 1, substr_count( $code, 'npmp_import_free_remaining() < 1' ) );
check( 'runs take the lock', 1, substr_count( $code, "npmp_mail_queue_lock( 'import_fluent-crm', 120 )" ) );
check( 'no wp_mail', 0, substr_count( $code, 'wp_mail(' ) );
check( 'never reads the WordPress user', 0, preg_match( '/get_user(data|_by)|wp_update_user|\{\$wpdb->users\}/', $code ) );
check( 'data removal is unavailable', false, (bool) preg_match( "/'batch'\s*=>/", $code ) );
$main = file_get_contents( $root . '/nonprofit-manager.php' );
check( 'loaded after the MailPoet importer', true, strpos( $main, 'fluentcrm-importer.php' ) > strpos( $main, 'mailpoet-importer.php' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
