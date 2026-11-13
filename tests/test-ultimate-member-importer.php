<?php
/**
 * Ultimate Member importer: the pure parts
 * (includes/import/ultimate-member-importer.php).
 *
 * Run: php tests/test-ultimate-member-importer.php
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
require_once $root . '/includes/import/pmpro-importer.php';
require_once $root . '/includes/import/ultimate-member-importer.php';

echo "Account status\n";
check( 'approved', 'approved', npmp_um_status_key( 'approved' ) );
check( 'case and spaces', 'awaiting_admin_review', npmp_um_status_key( ' Awaiting_Admin_Review ' ) );
check( 'no status', 'none', npmp_um_status_key( '' ) );
check( 'an extension status', 'other', npmp_um_status_key( 'pending_payment' ) );
check( 'every key has a label', npmp_um_status_keys(), array_keys( array_intersect_key( array_flip( npmp_um_status_keys() ), npmp_um_status_labels() ) ) );

echo "Status mapping, the safe choice\n";
check( 'approved comes over as Inactive by default', 'inactive', npmp_um_contact_status( 'approved' ) );
check( 'no status follows approved', 'inactive', npmp_um_contact_status( 'none' ) );
check( 'awaiting email confirmation is Pending', 'pending', npmp_um_contact_status( 'awaiting_email_confirmation', true ) );
check( 'awaiting review is Pending', 'pending', npmp_um_contact_status( 'awaiting_admin_review', true ) );
check( 'deactivated is Inactive even with newsletters on', 'inactive', npmp_um_contact_status( 'inactive', true ) );
check( 'rejected is Unsubscribed even with newsletters on', 'unsubscribed', npmp_um_contact_status( 'rejected', true ) );
check( 'an unknown status waits as Pending', 'pending', npmp_um_contact_status( 'other', true ) );
check( 'approved becomes Active only when you say so', 'subscribed', npmp_um_contact_status( 'approved', true ) );
foreach ( npmp_um_status_keys() as $key ) {
	if ( ! in_array( $key, array( 'approved', 'none' ), true ) ) {
		check( "{$key} never becomes Active", true, 'subscribed' !== npmp_um_contact_status( $key, true ) );
	}
}

echo "Merging with a contact you have\n";
check( 'an unsubscribe here always wins', '', npmp_um_merge_status( 'unsubscribed', 'ultimate_member', 'approved', 'subscribed' ) );
check( 'an unsubscribe wins on someone else\'s contact', '', npmp_um_merge_status( 'unsubscribed', 'csv', 'approved', 'subscribed' ) );
check( 'someone else\'s Active contact stays Active when rejected', '', npmp_um_merge_status( 'subscribed', 'mailpoet', 'rejected', 'unsubscribed' ) );
check( 'someone else\'s Pending contact becomes Active when you said so', 'subscribed', npmp_um_merge_status( 'pending', 'csv', 'approved', 'subscribed' ) );
check( 'someone else\'s Inactive contact stays', '', npmp_um_merge_status( 'inactive', 'csv', 'approved', 'subscribed' ) );
check( 'someone else\'s Donor stays', '', npmp_um_merge_status( 'donor', 'givewp', 'approved', 'inactive' ) );
check( 'our contact, rejected later, stops getting email', 'unsubscribed', npmp_um_merge_status( 'subscribed', 'ultimate_member', 'rejected', 'unsubscribed' ) );
check( 'our contact, deactivated later, goes Inactive', 'inactive', npmp_um_merge_status( 'subscribed', 'ultimate_member', 'inactive', 'inactive' ) );
check( 'our Pending contact, approved, safe choice: Inactive', 'inactive', npmp_um_merge_status( 'pending', 'ultimate_member', 'approved', 'inactive' ) );
check( 'our Pending contact, approved, newsletters: Active', 'subscribed', npmp_um_merge_status( 'pending', 'ultimate_member', 'approved', 'subscribed' ) );
check( 'our Inactive contact, newsletters on this run: Active', 'subscribed', npmp_um_merge_status( 'inactive', 'ultimate_member', 'approved', 'subscribed' ) );
check( 'a rerun with the safe choice never takes Active away', '', npmp_um_merge_status( 'subscribed', 'ultimate_member', 'approved', 'inactive' ) );
check( 'our Active contact back under review waits', 'pending', npmp_um_merge_status( 'subscribed', 'ultimate_member', 'awaiting_admin_review', 'pending' ) );
check( 'our Inactive contact under review stays', '', npmp_um_merge_status( 'inactive', 'ultimate_member', 'awaiting_admin_review', 'pending' ) );
check( 'our Volunteer stays when approved', '', npmp_um_merge_status( 'volunteer', 'ultimate_member', 'approved', 'subscribed' ) );
check( 'same status, no change', '', npmp_um_merge_status( 'pending', 'ultimate_member', 'awaiting_email_confirmation', 'pending' ) );

echo "Roles\n";
check( 'roles from capabilities', array( 'subscriber', 'um_member' ), npmp_um_caps_roles( array( 'subscriber' => true, 'um_member' => true, 'old' => false ) ) );
check( 'junk capabilities', array(), npmp_um_caps_roles( 'a:1:{}' ) );
check( 'administrator is staff', true, npmp_um_role_is_staff( 'administrator', array() ) );
check( 'a role that edits posts is staff', true, npmp_um_role_is_staff( 'um_moderator', array( 'edit_posts' => true ) ) );
check( 'shop manager is staff', true, npmp_um_role_is_staff( 'shop_manager', array() ) );
check( 'Membership Manager is staff', true, npmp_um_role_is_staff( 'npmp_membership_manager', array( 'read' => true ) ) );
check( 'subscriber is not staff', false, npmp_um_role_is_staff( 'subscriber', array( 'read' => true ) ) );
check( 'customer is not staff', false, npmp_um_role_is_staff( 'customer', array( 'read' => true ) ) );
check( 'an Ultimate Member role starts ticked', true, npmp_um_default_ticked( array( 'is_um' => true ) ) );
check( 'the registration role starts ticked', true, npmp_um_default_ticked( array( 'registers' => true ) ) );
check( 'a staff Ultimate Member role starts unticked', false, npmp_um_default_ticked( array( 'is_um' => true, 'staff' => true ) ) );
check( 'a shop role starts unticked', false, npmp_um_default_ticked( array( 'is_um' => false, 'registers' => false ) ) );
check( 'a registration role with staff powers starts unticked', false, npmp_um_default_ticked( array( 'registers' => true, 'staff' => true ) ) );

$roles = array(
	'subscriber'  => array( 'is_um' => false, 'priority' => 0 ),
	'um_member'   => array( 'is_um' => true, 'priority' => 1 ),
	'um_board'    => array( 'is_um' => true, 'priority' => 5 ),
	'customer'    => array( 'is_um' => false, 'priority' => 0 ),
	'um_friend'   => array( 'is_um' => true, 'priority' => 1 ),
);
check( 'level order: UM roles by priority, then the rest', array( 'um_board', 'um_member', 'um_friend', 'subscriber', 'customer' ), npmp_um_level_order( $roles ) );
$order  = array( 'um_board', 'um_member', 'subscriber' );
$levels = array( 'um_board' => 'Board', 'um_member' => 'Member', 'subscriber' => '' );
check( 'the highest role with a level wins', 'Board', npmp_um_pick_level( array( 'subscriber', 'um_member', 'um_board' ), $order, $levels ) );
check( 'a role with no level falls through', 'Member', npmp_um_pick_level( array( 'subscriber', 'um_member' ), $order, $levels ) );
check( 'only a role with no level gives none', '', npmp_um_pick_level( array( 'subscriber' ), $order, $levels ) );
check( 'an unticked role never gives a level', '', npmp_um_pick_level( array( 'administrator' ), $order, array( 'administrator' => 'Boss' ) ) );
check( 'holds a ticked role', true, npmp_um_has_ticked_role( array( 'editor', 'um_member' ), array( 'um_member' ) ) );
check( 'holds no ticked role', false, npmp_um_has_ticked_role( array( 'administrator' ), array( 'um_member', 'subscriber' ) ) );
check( 'role needle', '"um_member";b:1;', npmp_um_role_needle( 'um_member' ) );
check( 'needle never matches a longer role', false, false !== strpos( 'a:1:{s:13:"um_subscriber";b:1;}', npmp_um_role_needle( 'subscriber' ) ) );
check( 'level text is trimmed and stripped', 'Gold Member', npmp_um_clean_level( "  <b>Gold</b>   Member " ) );
check( 'level text has a length limit', 100, strlen( npmp_um_clean_level( str_repeat( 'a', 300 ) ) ) );

echo "Profile fields\n";
$fields = array(
	'favorite_tree' => array( 'metakey' => 'favorite_tree', 'type' => 'text', 'title' => 'Favorite tree' ),
	'secret'        => array( 'metakey' => 'secret', 'type' => 'password', 'title' => 'Secret' ),
	'resume'        => array( 'metakey' => 'resume', 'type' => 'file', 'title' => 'Resume' ),
	'phone_number'  => array( 'metakey' => 'phone_number', 'type' => 'tel', 'title' => 'Phone' ),
	'first_name'    => array( 'metakey' => 'first_name', 'type' => 'text', 'title' => 'First' ),
	'bad key'       => array( 'metakey' => 'bad key', 'type' => 'text' ),
	'shirt'         => array( 'metakey' => 'shirt', 'type' => 'select' ),
);
check( 'custom fields only, no passwords or files', array( 'favorite_tree' => 'Favorite tree', 'shirt' => 'shirt' ), npmp_um_profile_fields( $fields ) );
check( 'no fields option', array(), npmp_um_profile_fields( false ) );
check( 'mapped fields', array( 'phone' => 'phone_number', 'mobile' => 'mobile_number', 'country' => 'country' ), npmp_um_field_keys() );
check( 'fill fills only empty fields', array( 'mobile' => '555-2', 'country' => 'Canada' ), npmp_pmpro_fill_fields( array( 'phone' => '555-0', 'mobile' => '', 'country' => '' ), array( 'phone' => '555-1', 'mobile' => '555-2', 'country' => 'Canada' ) ) );
check( 'fill skips empty incoming', array(), npmp_pmpro_fill_fields( array(), array( 'phone' => '', 'mobile' => '  ' ) ) );
check( 'PMPro levels still follow PMPro', 'Gold', npmp_pmpro_level_update( 'Silver', 'pmpro', 'Gold' ) );
check( 'a level on our contact follows Ultimate Member', 'Gold', npmp_pmpro_level_update( 'Silver', 'ultimate_member', 'Gold', 'ultimate_member' ) );
check( 'a level set on a PMPro contact stays for Ultimate Member', '', npmp_pmpro_level_update( 'Silver', 'pmpro', 'Gold', 'ultimate_member' ) );

echo "Content\n";
check( 'a form shortcode', array( 'form' ), npmp_um_content_kinds( 'Join [ultimatemember form_id="7"]' ) );
check( 'the account shortcode', array( 'form' ), npmp_um_content_kinds( '[ultimatemember_account]' ) );
check( 'a block', array( 'form' ), npmp_um_content_kinds( '<!-- wp:um-block/um-forms {"form_id":"7"} /-->' ) );
check( 'logged-in content', array( 'visibility' ), npmp_um_content_kinds( '[um_loggedin]Secret[/um_loggedin]' ) );
check( 'a restricted block', array( 'visibility' ), npmp_um_content_kinds( '<!-- wp:paragraph {"um_is_restrict":true,"um_who_access":"1"} -->' ) );
check( 'a profile link', array( 'link' ), npmp_um_content_kinds( '[um_author_profile_link]' ) );
check( 'a lookalike shortcode is not one', array(), npmp_um_content_kinds( '[ultimatemembers] and [um_loggedinx]' ) );
check( 'restriction on', true, npmp_um_is_restricted( array( '_um_custom_access_settings' => '1', '_um_accessible' => '2' ) ) );
check( 'restriction off', false, npmp_um_is_restricted( array( '_um_custom_access_settings' => 0 ) ) );

echo "Writer runs as an import\n";
$src = file_get_contents( $root . '/includes/import/pmpro-importer.php' );
check( 'the shared writer wraps its write as an import', true, false !== strpos( $src, 'return npmp_import_run_as_import(' ) );
$mp = file_get_contents( $root . '/includes/import/mailpoet-importer.php' );
check( 'the MailPoet writer wraps its write as an import', true, false !== strpos( $mp, 'return npmp_import_run_as_import(' ) );
$pg = file_get_contents( $root . '/includes/import/past-gifts.php' );
check( 'past-gift donors are written as an import', true, false !== strpos( $pg, 'npmp_import_run_as_import(' ) );
$um = file_get_contents( $root . '/includes/import/ultimate-member-importer.php' );
check( 'no wp_mail in the importer', false, false !== strpos( $um, 'wp_mail' ) );
check( 'never reads the submitted registration copy', false, false !== strpos( $um, "'submitted', true" ) );
check( 'never deletes users', false, (bool) preg_match( '/wp_delete_user|delete_user_meta|update_user_meta|wp_update_user/', $um ) );

check( 'step 5 has a reason and no batch', 1, preg_match( "/'data_removal'\s*=>\s*array\(\s*'reason'/", $um ) );
$main = file_get_contents( $root . '/nonprofit-manager.php' );
check( 'loaded after the Paid Memberships Pro importer', true, strpos( $main, 'ultimate-member-importer.php' ) > strpos( $main, 'pmpro-importer.php' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
