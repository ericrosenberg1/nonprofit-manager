<?php
/**
 * MailPoet importer (includes/import/mailpoet-importer.php): status mapping,
 * how an existing contact's status and name merge, the signup date pick,
 * MailPoet's serialized settings, the settings plan behind the approve
 * screen's checkboxes, form detection, the groups cell, and which plugins
 * need MailPoet.
 *
 * The database half runs on a real WordPress with MailPoet's tables:
 * tests/rig/mailpoet-importer.php.
 *
 * Run: php tests/test-mailpoet-importer.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_hooks'] = array();
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][] = array( $hook, $cb, $prio );
}
function is_admin() {
	return false;
}

require_once dirname( __DIR__ ) . '/includes/import/mailpoet-importer.php';

$src = file_get_contents( dirname( __DIR__ ) . '/includes/import/mailpoet-importer.php' );

echo "Loading\n";
check( 'no hooks outside admin', 0, count( $GLOBALS['t_hooks'] ) );
check( 'batch size matches The Events Calendar importer', 100, NPMP_MAILPOET_BATCH );
check( 'loaded with the members module', 1, substr_count( file_get_contents( dirname( __DIR__ ) . '/nonprofit-manager.php' ), "includes/import/mailpoet-importer.php" ) );

echo "Status mapping\n";
check( 'subscribed stays subscribed', 'subscribed', npmp_mailpoet_map_status( 'subscribed' ) );
check( 'unconfirmed waits as pending', 'pending', npmp_mailpoet_map_status( 'unconfirmed' ) );
check( 'unsubscribed stays unsubscribed', 'unsubscribed', npmp_mailpoet_map_status( 'unsubscribed' ) );
check( 'bounced becomes unsubscribed', 'unsubscribed', npmp_mailpoet_map_status( 'bounced' ) );
check( 'inactive stays inactive', 'inactive', npmp_mailpoet_map_status( 'inactive' ) );
check( 'case and spaces', 'unsubscribed', npmp_mailpoet_map_status( ' Bounced ' ) );
check( 'unknown status gets no mail', 'pending', npmp_mailpoet_map_status( 'vip' ) );
check( 'empty status gets no mail', 'pending', npmp_mailpoet_map_status( '' ) );
check( 'null status gets no mail', 'pending', npmp_mailpoet_map_status( null ) );
check( 'unknown key is other', 'other', npmp_mailpoet_status_key( 'vip' ) );
$subscribed_sources = array();
foreach ( npmp_mailpoet_status_keys() as $key ) {
	if ( 'subscribed' === npmp_mailpoet_map_status( $key ) ) {
		$subscribed_sources[] = $key;
	}
}
check( 'only MailPoet subscribed maps to subscribed', array( 'subscribed' ), $subscribed_sources );

echo "Existing contacts: status\n";
check( 'unsubscribed here stays, MailPoet subscribed', '', npmp_mailpoet_merge_status( 'unsubscribed', 'subscribed' ) );
check( 'unsubscribed here stays, MailPoet pending', '', npmp_mailpoet_merge_status( 'unsubscribed', 'pending' ) );
check( 'unsubscribed here stays, MailPoet unsubscribed', '', npmp_mailpoet_merge_status( 'unsubscribed', 'unsubscribed' ) );
check( 'MailPoet unsubscribe wins over subscribed', 'unsubscribed', npmp_mailpoet_merge_status( 'subscribed', 'unsubscribed' ) );
check( 'MailPoet unsubscribe wins over donor', 'unsubscribed', npmp_mailpoet_merge_status( 'donor', 'unsubscribed' ) );
check( 'MailPoet unsubscribe wins over pending', 'unsubscribed', npmp_mailpoet_merge_status( 'pending', 'unsubscribed' ) );
check( 'pending here, confirmed in MailPoet', 'subscribed', npmp_mailpoet_merge_status( 'pending', 'subscribed' ) );
check( 'subscribed here, unconfirmed in MailPoet: no downgrade', '', npmp_mailpoet_merge_status( 'subscribed', 'pending' ) );
check( 'donor here, confirmed in MailPoet: subscribed, so newsletters reach them', 'subscribed', npmp_mailpoet_merge_status( 'donor', 'subscribed' ) );
check( 'donor here, unconfirmed in MailPoet: stays donor', '', npmp_mailpoet_merge_status( 'donor', 'pending' ) );
check( 'volunteer here stays volunteer', '', npmp_mailpoet_merge_status( 'volunteer', 'subscribed' ) );
check( 'inactive here stays inactive', '', npmp_mailpoet_merge_status( 'inactive', 'subscribed' ) );
check( 'subscribed here, inactive in MailPoet: no change', '', npmp_mailpoet_merge_status( 'subscribed', 'inactive' ) );
check( 'no status row reads as subscribed', 'unsubscribed', npmp_mailpoet_merge_status( '', 'unsubscribed' ) );
$resubscribed = array();
foreach ( array( 'subscribed', 'pending', 'inactive', 'unsubscribed' ) as $incoming ) {
	if ( '' !== npmp_mailpoet_merge_status( 'unsubscribed', $incoming ) ) {
		$resubscribed[] = $incoming;
	}
}
check( 'nothing moves a contact off unsubscribed', array(), $resubscribed );

echo "Names\n";
check( 'first and last', 'Ada Lovelace', npmp_mailpoet_full_name( 'Ada', 'Lovelace' ) );
check( 'first only', 'Ada', npmp_mailpoet_full_name( 'Ada', '' ) );
check( 'last only', 'Lovelace', npmp_mailpoet_full_name( '', 'Lovelace' ) );
check( 'extra spaces', 'Ada King Lovelace', npmp_mailpoet_full_name( '  Ada  King ', ' Lovelace ' ) );
check( 'both empty', '', npmp_mailpoet_full_name( '', null ) );
check( 'a typed name stays', '', npmp_mailpoet_merge_name( 'A. Lovelace', 'ada@example.org', 'Ada Lovelace' ) );
check( 'a blank name fills', 'Ada Lovelace', npmp_mailpoet_merge_name( '', 'ada@example.org', 'Ada Lovelace' ) );
check( 'a name that is the email fills', 'Ada Lovelace', npmp_mailpoet_merge_name( 'Ada@Example.org', 'ada@example.org', 'Ada Lovelace' ) );
check( 'no MailPoet name changes nothing', '', npmp_mailpoet_merge_name( '', 'ada@example.org', '' ) );

echo "Signup date\n";
check( 'created before confirmed', '1554196500', npmp_mailpoet_pick_added( '1554196500', '1554200000' ) );
check( 'older confirmed wins', '1400000000', npmp_mailpoet_pick_added( '1700000000', '1400000000' ) );
check( 'no confirmed', '1554196500', npmp_mailpoet_pick_added( 1554196500, 0 ) );
check( 'no created', '1554200000', npmp_mailpoet_pick_added( null, '1554200000' ) );
check( 'neither', '', npmp_mailpoet_pick_added( 0, '0' ) );
check( 'junk', '', npmp_mailpoet_pick_added( 'soon', '' ) );
check( 'negative', '', npmp_mailpoet_pick_added( -5, null ) );
check( 'ten digits, so the Added date parser reads it as Unix time', 1, preg_match( '/^\d{9,10}$/', npmp_mailpoet_pick_added( 1554196500, 0 ) ) );

echo "Settings from serialized rows\n";
$rows = array(
	'sender'              => serialize( array( 'name' => 'Ventura U3A', 'address' => 'news@u3a.example.org' ) ),
	'reply_to'            => serialize( array( 'name' => 'Membership', 'address' => 'members@u3a.example.org' ) ),
	'signup_confirmation' => serialize( array( 'enabled' => '1', 'subject' => 'Confirm' ) ),
	'mta'                 => serialize( array( 'method' => 'SMTP', 'password' => 'hunter2' ) ),
);
$read = npmp_mailpoet_read_settings( $rows );
check( 'sender name', 'Ventura U3A', $read['from_name'] );
check( 'sender address', 'news@u3a.example.org', $read['from_email'] );
check( 'reply-to with a name', 'Membership <members@u3a.example.org>', $read['reply_to'] );
check( 'confirmation on', true, $read['double_optin'] );
check( 'only four keys come out, so no SMTP password', array( 'from_name', 'from_email', 'reply_to', 'double_optin' ), array_keys( $read ) );
check( 'the importer never asks for the mta row', 0, preg_match( "/'mta'|smtp_|api_key/i", $src ) );
check( 'confirmation off as empty string', false, npmp_mailpoet_read_settings( array( 'signup_confirmation' => serialize( array( 'enabled' => '' ) ) ) )['double_optin'] );
check( 'confirmation off as false', false, npmp_mailpoet_read_settings( array( 'signup_confirmation' => serialize( array( 'enabled' => false ) ) ) )['double_optin'] );
check( 'confirmation off as "0"', false, npmp_mailpoet_read_settings( array( 'signup_confirmation' => serialize( array( 'enabled' => '0' ) ) ) )['double_optin'] );
check( 'confirmation on as true', true, npmp_mailpoet_read_settings( array( 'signup_confirmation' => serialize( array( 'enabled' => true ) ) ) )['double_optin'] );
check( 'no confirmation row is unknown', null, npmp_mailpoet_read_settings( array() )['double_optin'] );
check( 'no rows, no sender', '', npmp_mailpoet_read_settings( array() )['from_email'] );
check( 'reply-to without a name', 'members@u3a.example.org', npmp_mailpoet_read_settings( array( 'reply_to' => serialize( array( 'address' => 'members@u3a.example.org' ) ) ) )['reply_to'] );
check( 'reply-to saved as null', '', npmp_mailpoet_read_settings( array( 'reply_to' => null ) )['reply_to'] );
check( 'header injection in a name is flattened', 'Club Bcc: x@example.org', npmp_mailpoet_read_settings( array( 'sender' => serialize( array( 'name' => "Club\r\nBcc: x@example.org", 'address' => 'a@example.org' ) ) ) )['from_name'] );
check( 'broken serialized value reads as no sender', '', npmp_mailpoet_read_settings( array( 'sender' => 'a:2:{s:4:"name";' ) )['from_name'] );
check( 'plain string stays a string', 'full', npmp_mailpoet_parse_setting( 'full' ) );
check( 'serialized false', false, npmp_mailpoet_parse_setting( 'b:0;' ) );
check( 'an object is never rebuilt', false, is_object( npmp_mailpoet_parse_setting( 'O:8:"stdClass":0:{}' ) ) );
$with_object = npmp_mailpoet_parse_setting( 'a:1:{s:1:"x";O:8:"stdClass":0:{}}' );
check( 'an object inside an array comes back incomplete, not as its class', '__PHP_Incomplete_Class', is_array( $with_object ) ? get_class( $with_object['x'] ) : 'not an array' );

echo "Settings plan\n";
$unset = array(
	'from_name'    => array( 'value' => 'My Site', 'set' => false ),
	'from_email'   => array( 'value' => 'admin@site.example.org', 'set' => false ),
	'double_optin' => array( 'value' => true, 'set' => false ),
);
$plan = npmp_mailpoet_settings_plan( $read, $unset );
check( 'never saved here: From name ticked', true, $plan['from_name']['checked'] );
check( 'never saved here: From address ticked', true, $plan['from_email']['checked'] );
check( 'reply-to has no home', 'unsupported', $plan['reply_to']['state'] );
check( 'reply-to never ticked', false, $plan['reply_to']['checked'] );
check( 'confirmation already matches', 'same', $plan['double_optin']['state'] );

$saved = array(
	'from_name'    => array( 'value' => 'The Club', 'set' => true ),
	'from_email'   => array( 'value' => 'NEWS@u3a.example.org', 'set' => true ),
	'double_optin' => array( 'value' => true, 'set' => true ),
);
$plan = npmp_mailpoet_settings_plan( $read, $saved );
check( 'saved here and different: a change', 'change', $plan['from_name']['state'] );
check( 'saved here and different: starts unticked', false, $plan['from_name']['checked'] );
check( 'same address in another case matches', 'same', $plan['from_email']['state'] );

$off  = array_merge( $read, array( 'double_optin' => false ) );
$plan = npmp_mailpoet_settings_plan( $off, $unset );
check( 'MailPoet confirmation off: offered', 'change', $plan['double_optin']['state'] );
check( 'switching confirmation off never starts ticked', false, $plan['double_optin']['checked'] );
$plan = npmp_mailpoet_settings_plan( $read, array_merge( $unset, array( 'double_optin' => array( 'value' => false, 'set' => false ) ) ) );
check( 'switching confirmation on starts ticked when nobody saved it', true, $plan['double_optin']['checked'] );
$plan = npmp_mailpoet_settings_plan( $read, array_merge( $unset, array( 'double_optin' => array( 'value' => false, 'set' => true ) ) ) );
check( 'someone switched it off here: starts unticked', false, $plan['double_optin']['checked'] );
check( 'MailPoet with nothing saved offers nothing', array(), npmp_mailpoet_settings_plan( npmp_mailpoet_read_settings( array() ), $unset ) );

echo "Form detection\n";
check( 'shortcode', array( 'shortcode' ), npmp_mailpoet_form_kinds( 'Join us: [mailpoet_form id="1"]' ) );
check( 'shortcode in capitals', array( 'shortcode' ), npmp_mailpoet_form_kinds( '[MAILPOET_FORM id=2]' ) );
check( 'form block', array( 'block' ), npmp_mailpoet_form_kinds( '<!-- wp:mailpoet/subscription-form-block {"formId":1} /-->' ) );
check( 'both', array( 'shortcode', 'block' ), npmp_mailpoet_form_kinds( "[mailpoet_form id=1]\n<!-- wp:mailpoet/subscription-form-block-render /-->" ) );
check( 'another shortcode with the same start', array(), npmp_mailpoet_form_kinds( '[mailpoet_forms_list]' ) );
check( 'MailPoet archive shortcode is not a form', array(), npmp_mailpoet_form_kinds( '[mailpoet_archive]' ) );
check( 'our own signup form', array(), npmp_mailpoet_form_kinds( '[npmp_email_signup] <!-- wp:nonprofit-manager/email-signup /-->' ) );
check( 'plain text', array(), npmp_mailpoet_form_kinds( 'We used to use MailPoet.' ) );
check( 'not a string', array(), npmp_mailpoet_form_kinds( array( 'x' ) ) );

echo "Groups cell\n";
check( 'two lists', 'Walking Group; History;', npmp_mailpoet_groups_cell( array( 'Walking Group', 'History' ) ) );
check( 'a comma in a name survives the semicolon split', 'Arts, Crafts;', npmp_mailpoet_groups_cell( array( 'Arts, Crafts' ) ) );
check( 'a semicolon in a name becomes a comma', 'Arts, Crafts;', npmp_mailpoet_groups_cell( array( 'Arts; Crafts' ) ) );
check( 'repeats and blanks drop', 'History;', npmp_mailpoet_groups_cell( array( 'History', ' history ', '' ) ) );
check( 'no lists, no cell', '', npmp_mailpoet_groups_cell( array() ) );

echo "IDs\n";
check( 'keeps whole numbers once', array( 3, 12 ), npmp_mailpoet_clean_ids( array( '3', '12', '3', 'x', '-1', '0', '4;DROP' ) ) );

echo "Plugins that need MailPoet\n";
check( 'MailPoet itself is not an add-on', false, npmp_mailpoet_plugin_needs_mailpoet( 'mailpoet/mailpoet.php', array( 'Name' => 'MailPoet' ) ) );
check( 'MailPoet Premium', true, npmp_mailpoet_plugin_needs_mailpoet( 'mailpoet-premium/mailpoet-premium.php', array( 'Name' => 'MailPoet Premium' ) ) );
check( 'Requires Plugins header', true, npmp_mailpoet_plugin_needs_mailpoet( 'my-addon/my-addon.php', array( 'Name' => 'My Addon', 'RequiresPlugins' => 'woocommerce, mailpoet' ) ) );
check( 'add-on folder named after MailPoet', true, npmp_mailpoet_plugin_needs_mailpoet( 'add-on-contact-form-7-mailpoet/add-on.php', array( 'Name' => 'Add-on Contact Form 7' ) ) );
check( 'name starts with MailPoet', true, npmp_mailpoet_plugin_needs_mailpoet( 'some-folder/x.php', array( 'Name' => 'MailPoet Blocks' ) ) );
check( 'unrelated plugin', false, npmp_mailpoet_plugin_needs_mailpoet( 'akismet/akismet.php', array( 'Name' => 'Akismet' ) ) );
check( 'this plugin', false, npmp_mailpoet_plugin_needs_mailpoet( 'nonprofit-manager/nonprofit-manager.php', array( 'Name' => 'Nonprofit Manager' ) ) );

echo "Wiring\n";
check( 'every request checks the nonce', 6, substr_count( $src, 'npmp_mailpoet_check_request(' ) - 1 );
check( 'both ajax handlers check the nonce', 2, substr_count( $src, "check_ajax_referer( 'npmp_mailpoet_import', 'npmp_mailpoet_nonce' )" ) );
check( 'both ajax handlers check the capability', 2, substr_count( $src, "wp_send_json_error( array( 'message' => __( 'You don\\'t have permission to run the importer.', 'nonprofit-manager' ) ), 403 )" ) );
check( 'deactivating needs activate_plugins', 1, substr_count( $src, "npmp_mailpoet_check_request( 'activate_plugins' )" ) );
check( 'lists reach groups through the import row hook', 1, preg_match_all( "/do_action\\(\\s*'npmp_import_row_imported',/", $src ) );
check( 'nothing is deleted from MailPoet', 0, preg_match( '/DELETE FROM|DROP TABLE|UPDATE \{\$(table|segments|links|subscribers)\}|delete_plugins/i', $src ) );
check( 'post content is never rewritten', 0, preg_match( '/wp_update_post|wp_insert_post/', $src ) );
check( 'the free import cap applies', 1, substr_count( $src, '(int) $stage[\'handled\'] >= $max' ) );

echo "Free limit words\n";
$mp_src = file_get_contents( dirname( __DIR__ ) . '/includes/import/mailpoet-importer.php' );
check( 'after the move, the limit reads as a total, not one import', false, strpos( $mp_src, 'subscribers in one import, so the rest are still in MailPoet' ) );
check( 'approve names how many stay in MailPoet', true, false !== strpos( $mp_src, 'so %3$s of the %4$s new contacts here stay in MailPoet' ) );
check( 'free step 5 points at Pro, not at moving the rest', true, false !== strpos( $mp_src, 'past the free limit. Add Pro and run the import again' ) );

echo "Tags\n";
check( 'a new contact gets every tag', 'Board,Volunteers', npmp_mailpoet_merge_tags( '', array( 'Board', 'Volunteers' ) ) );
check( 'an update adds after the tags already there', 'Donor,Board', npmp_mailpoet_merge_tags( 'Donor', array( 'Board' ) ) );
check( 'a tag already there in another case adds nothing', '', npmp_mailpoet_merge_tags( 'board, Donor', array( 'Board' ) ) );
check( 'no tags from MailPoet: leave the field alone', '', npmp_mailpoet_merge_tags( 'Donor', array() ) );
check( 'a comma inside a tag name cannot split it in two', 'Donor,Friends of the Park', npmp_mailpoet_merge_tags( 'Donor', array( 'Friends, of the Park' ) ) );
check( 'blank names drop out', 'Board', npmp_mailpoet_merge_tags( '', array( ' ', '', 'Board' ) ) );
check( 'existing tags never get dropped', 'A,B,C,D', npmp_mailpoet_merge_tags( 'A;B,C', array( 'D', 'a' ) ) );
check( 'the writer reads only MailPoet\'s own tags, so FluentCRM\'s $row->tags stays its own', true, false !== strpos( $src, 'isset( $row->mailpoet_tags )' ) && false === strpos( $src, 'isset( $row->tags )' ) );
check( 'the migrate step reads tags per batch', true, false !== strpos( $src, '$tags        = npmp_mailpoet_read_tags(' ) );
check( 'tags come from mailpoet_tags and mailpoet_subscriber_tag', true, false !== strpos( $src, "npmp_mailpoet_table( 'subscriber_tag' )" ) && false !== strpos( $src, "npmp_mailpoet_table( 'tags' )" ) );
check( 'the review step shows the tag count', true, false !== strpos( $src, "MailPoet tags into each contact\\'s Tags field" ) );

echo "Empty lists\n";
$lists = array(
	3 => array( 'name' => 'Newsletter', 'count' => 40 ),
	4 => array( 'name' => 'Old list', 'count' => 0 ),
	5 => array( 'name' => 'Board', 'count' => 2 ),
	6 => array( 'name' => '', 'count' => 9 ),
);
$split = npmp_mailpoet_split_lists( $lists, array( 5 ) );
check( 'lists with subscribed members become groups', array( 3 => 'Newsletter' ), $split['keep'] );
check( 'a list with no subscribed member stays behind', array( 4 => 'Old list' ), $split['empty'] );
check( 'an unchecked list is neither', false, isset( $split['keep'][5] ) || isset( $split['empty'][5] ) );
check( 'the list count leaves out people who left or bounced', true, false !== strpos( $src, "AND s.status NOT IN ( %s, %s ) GROUP BY ss.segment_id\", 'subscribed', 'unsubscribed', 'bounced'" ) );

echo "Fill order\n";
check( 'the migrate step reads one pass at a time', true, false !== strpos( $src, 'npmp_mailpoet_read_subscribers( (int) $stage[\'migrate_cursor\'], $limit, $pass )' ) );
check( 'subscribed first, unconfirmed second, then the rest', true, false !== strpos( $src, '\'subscribed\' === $pass ) {' . "\n\t\t" . '$where = " AND status = \'subscribed\'";' ) && false !== strpos( $src, '" AND status = \'unconfirmed\'"' ) && false !== strpos( $src, "status NOT IN ( 'subscribed', 'unconfirmed' )" ) );
check( 'the approve step says the order', true, false !== strpos( $src, 'npmp_import_fill_order_message()' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
