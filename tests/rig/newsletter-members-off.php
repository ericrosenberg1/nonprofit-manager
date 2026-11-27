<?php
/**
 * Real-WordPress check that a newsletter reaches its readers with the
 * members module off. Without members, a newsletter goes to WordPress users,
 * and the queue's user_id holds a user ID. The per-send recheck (#107) read
 * that ID as a contact, found none, and deleted every row unsent.
 *
 * Run inside a throwaway WordPress with newsletters on and members off:
 *   wp option update npmp_enabled_features '{"members":false,"newsletters":true}' --format=json
 *   wp eval-file tests/rig/newsletter-members-off.php
 * We catch mail with pre_wp_mail and send none. We delete the users,
 * newsletter, and queue rows we made.
 */

global $wpdb;

$GLOBALS['rig_mo_fails'] = 0;
$rig_mo_ok               = static function ( $label, $cond ) {
	if ( ! $cond ) {
		++$GLOBALS['rig_mo_fails'];
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( class_exists( 'NPMP_Member_Manager' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) ) {
	echo "  FAIL  needs newsletters on and members off (npmp_enabled_features)\n";
	exit( 1 );
}

echo "\nA newsletter with the members module off\n";
$rig_mo_table = $wpdb->prefix . 'npmp_newsletter_queue';
$rig_mo_users = array();
// A contact post whose ID matches no user, and users whose IDs name no contact.
foreach ( array( 'rig-mo-a', 'rig-mo-b' ) as $rig_mo_login ) {
	$rig_mo_users[] = (int) wp_insert_user(
		array(
			'user_login' => $rig_mo_login,
			'user_email' => $rig_mo_login . '@example.org',
			'user_pass'  => wp_generate_password(),
		)
	);
}
$rig_mo_gone = (int) wp_insert_user(
	array(
		'user_login' => 'rig-mo-gone',
		'user_email' => 'rig-mo-gone@example.org',
		'user_pass'  => wp_generate_password(),
	)
);
update_user_meta( $rig_mo_gone, 'npmp_unsubscribed', 1 );

$rig_mo_nl = (int) wp_insert_post(
	array(
		'post_type'    => 'npmp_newsletter',
		'post_status'  => 'publish',
		'post_title'   => 'Rig members off',
		'post_content' => '<p>Hello from the rig.</p>',
	)
);
$wpdb->query( "DELETE FROM {$rig_mo_table}" ); // phpcs:ignore

$rig_mo_mail = array();
$rig_mo_trap = static function ( $pre, $atts ) use ( &$rig_mo_mail ) {
	$to            = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
	$rig_mo_mail[] = $to;
	return true;
};
add_filter( 'pre_wp_mail', $rig_mo_trap, 10, 2 );

$rig_mo_queued = NPMP_Newsletter_Manager::queue_newsletter( $rig_mo_nl );
$rig_mo_rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, email FROM {$rig_mo_table} WHERE newsletter_id = %d", $rig_mo_nl ) ); // phpcs:ignore
$rig_mo_emails = wp_list_pluck( $rig_mo_rows, 'email' );
$rig_mo_ok( 'both readers queued', in_array( 'rig-mo-a@example.org', $rig_mo_emails, true ) && in_array( 'rig-mo-b@example.org', $rig_mo_emails, true ) );
$rig_mo_ok( 'the opted-out user is not queued', ! in_array( 'rig-mo-gone@example.org', $rig_mo_emails, true ) );
$rig_mo_ok( 'user_id holds the WordPress user ID', in_array( (string) $rig_mo_users[0], wp_list_pluck( $rig_mo_rows, 'user_id' ), true ) );

NPMP_Newsletter_Manager::process_queue();
remove_filter( 'pre_wp_mail', $rig_mo_trap, 10 );

$rig_mo_after = (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, status FROM {$rig_mo_table} WHERE newsletter_id = %d", $rig_mo_nl ) ); // phpcs:ignore
$rig_mo_sent  = array();
foreach ( $rig_mo_after as $rig_mo_row ) {
	$rig_mo_sent[ $rig_mo_row->email ] = $rig_mo_row->status;
}
printf( "  note  queued %d, rows after the drain: %s, mail to: %s\n", (int) $rig_mo_queued, wp_json_encode( $rig_mo_sent ), implode( ' ', $rig_mo_mail ) );
$rig_mo_ok( 'reader a got the newsletter', in_array( 'rig-mo-a@example.org', $rig_mo_mail, true ) );
$rig_mo_ok( 'reader b got the newsletter', in_array( 'rig-mo-b@example.org', $rig_mo_mail, true ) );
$rig_mo_ok( 'reader a row reads sent', 'sent' === ( $rig_mo_sent['rig-mo-a@example.org'] ?? '' ) );
$rig_mo_ok( 'reader b row reads sent', 'sent' === ( $rig_mo_sent['rig-mo-b@example.org'] ?? '' ) );

// Clean up.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$rig_mo_table} WHERE newsletter_id = %d", $rig_mo_nl ) ); // phpcs:ignore
wp_delete_post( $rig_mo_nl, true );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array_merge( $rig_mo_users, array( $rig_mo_gone ) ) as $rig_mo_uid ) {
	wp_delete_user( $rig_mo_uid );
}
delete_option( 'npmp_queue_lock_newsletter' );

printf( "\n%d failed\n", $GLOBALS['rig_mo_fails'] );
exit( $GLOBALS['rig_mo_fails'] > 0 ? 1 : 0 );
