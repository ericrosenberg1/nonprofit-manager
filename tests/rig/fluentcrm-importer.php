<?php
/**
 * Real-WordPress run of the FluentCRM importer on the real plugin: seed
 * contacts, lists, tags, and custom fields through FluentCRM's own API, import,
 * check every status, list, tag, date, and setting, walk all five Switch
 * Plugins steps with their refusals, and import again with FluentCRM off to
 * prove nothing doubles.
 *
 * Seeds (one domain, rig-fcrm.example.org), one contact per FluentCRM status:
 *   sub  subscribed, on Rig Board and Rig Members, tags Rig Donor and Rig VIP,
 *        joined 2024-01-15 10:00 site time, every custom field filled
 *   pen  pending
 *   uns  unsubscribed
 *   tra  transactional
 *   bou  bounced
 *   com  complained
 *   spa  spammed
 *   wpu  subscribed, tied to a WordPress user
 *   old  subscribed, already a contact here who unsubscribed
 *   tag  subscribed with tag Rig Board Tag, already a contact here tagged Local
 *
 * Needs a throwaway WordPress with this plugin and FluentCRM (3.3.1 verified)
 * active, `wp` on PATH. Run it with Pro on and with Pro off:
 *   wp eval-file tests/rig/fluentcrm-importer.php
 *
 * Step 4 deletes FluentCRM's files through WordPress, so the rig moves a copy
 * aside first and moves it back after, then turns FluentCRM on again. It
 * deletes the contacts, FluentCRM rows, fields, and user it made and puts
 * the options it touched back.
 */

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};
$code = function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : ( is_array( $r ) ? (string) ( $r['message'] ?? 'ok' ) : (string) $r );
};

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );
$domain = 'rig-fcrm.example.org';
$slug   = 'fluent-crm';
$plugin_file = 'fluent-crm/fluent-crm.php';
$pro    = function_exists( 'npmp_is_pro' ) && npmp_is_pro();
$mails  = 0;
add_filter(
	'pre_wp_mail',
	function () use ( &$mails ) {
		++$mails;
		return true;
	}
);
echo $pro ? "Pro active\n" : "Pro inactive\n";

echo "Setup\n";
$ok( 'FluentCRM is active', is_plugin_active( $plugin_file ) );
$ok( 'contact table exists', '' !== npmp_fcrm_table() );
$ok( 'FluentCRM is on Switch Plugins', isset( npmp_import_sources()[ $slug ] ) );
if ( ! is_plugin_active( $plugin_file ) || ! function_exists( 'FluentCrmApi' ) ) {
	echo "FluentCRM must be active.\n";
	return;
}

$saved = array();
foreach ( array( 'npmp_email_settings', 'npmp_email_from_name', 'npmp_email_from_email', 'fluentcrm-global-settings', 'timezone_string', 'gmt_offset', 'npmp_import_hub', 'npmp_fcrm_import', 'npmp_fcrm_imported', 'npmp_custom_field_definitions', 'npmp_pro_import_made_fields', 'npmp_import_extra_plan_fluent-crm', 'npmp_import_extra_counts_fluent-crm' ) as $name ) {
	$saved[ $name ] = get_option( $name, null );
}
$fc_meta    = $wpdb->prefix . 'fc_meta';
$saved_defs = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$fc_meta} WHERE object_type = %s AND `key` = %s", 'option', 'contact_custom_fields' ) );
$contacts_before = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) );
$groups_before   = defined( 'NPMP_PRO_GROUP_TAXONOMY' ) && taxonomy_exists( NPMP_PRO_GROUP_TAXONOMY ) ? wp_count_terms( array( 'taxonomy' => NPMP_PRO_GROUP_TAXONOMY, 'hide_empty' => false ) ) : 0;

// Site time is Los Angeles for this run, so the signup date shows the conversion.
update_option( 'timezone_string', 'America/Los_Angeles' );
$email = is_array( $saved['npmp_email_settings'] ) ? $saved['npmp_email_settings'] : array();
unset( $email['from_name'], $email['from_email'] );
update_option( 'npmp_email_settings', $email );
delete_option( 'npmp_email_from_name' );
delete_option( 'npmp_email_from_email' );
delete_option( 'npmp_fcrm_import' );
delete_option( 'npmp_fcrm_imported' );
$hub = get_option( 'npmp_import_hub', array() );
unset( $hub[ $slug ] );
update_option( 'npmp_import_hub', $hub );

$global                   = is_array( $saved['fluentcrm-global-settings'] ) ? $saved['fluentcrm-global-settings'] : array();
$global['email_settings'] = array_merge( (array) ( $global['email_settings'] ?? array() ), array( 'from_name' => 'Rig Club', 'from_email' => 'news@' . $domain, 'reply_to_email' => 'help@' . $domain, 'reply_to_name' => 'Rig Help' ) );
update_option( 'fluentcrm-global-settings', $global );

// ---------------------------------------------------------------------
// Seed through FluentCRM's own code.
// ---------------------------------------------------------------------
$members = NPMP_Member_Manager::get_instance();
$board   = \FluentCrm\App\Models\Lists::create( array( 'title' => 'Rig Board', 'slug' => 'rig-board' ) );
$mlist   = \FluentCrm\App\Models\Lists::create( array( 'title' => 'Rig Members', 'slug' => 'rig-members' ) );
$donor   = \FluentCrm\App\Models\Tag::create( array( 'title' => 'Rig Donor', 'slug' => 'rig-donor' ) );
$vip     = \FluentCrm\App\Models\Tag::create( array( 'title' => 'Rig VIP', 'slug' => 'rig-vip' ) );
$btag    = \FluentCrm\App\Models\Tag::create( array( 'title' => 'Rig Board Tag', 'slug' => 'rig-board-tag' ) );
$fields  = new \FluentCrm\App\Models\CustomContactField();
$prior   = $fields->getGlobalFields()['fields'];
$fields->saveGlobalFields(
	array_merge(
		(array) $prior,
		array(
			array( 'label' => 'Rig Shirt', 'slug' => 'rig_shirt', 'type' => 'select-one', 'options' => array( 'S', 'M', 'L' ), 'group' => 'default' ),
			array( 'label' => 'Rig Skills', 'slug' => 'rig_skills', 'type' => 'select-multi', 'options' => array( 'Cooking', 'Driving', 'Tech' ), 'group' => 'default' ),
			array( 'label' => 'Rig Birthday', 'slug' => 'rig_birthday', 'type' => 'date', 'group' => 'default' ),
			array( 'label' => 'Rig Last Call', 'slug' => 'rig_last_call', 'type' => 'date_time', 'group' => 'default' ),
			array( 'label' => 'Rig Years', 'slug' => 'rig_years', 'type' => 'number', 'group' => 'default' ),
			array( 'label' => 'Rig Bio', 'slug' => 'rig_bio', 'type' => 'textarea', 'group' => 'default' ),
		)
	)
);
$user_id = wp_insert_user( array( 'user_login' => 'rig_fcrm_user', 'user_email' => 'wpu@' . $domain, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
$user_before = get_userdata( $user_id );

$api  = FluentCrmApi( 'contacts' );
$seed = array(
	'sub' => array( 'subscribed', 'Sue', 'Sub', array( $board->id, $mlist->id ), array( $donor->id, $vip->id ) ),
	'pen' => array( 'pending', 'Pen', '', array( $mlist->id ), array() ),
	'uns' => array( 'unsubscribed', 'Una', '', array( $mlist->id ), array( $donor->id ) ),
	'tra' => array( 'transactional', 'Tra', '', array(), array() ),
	'bou' => array( 'bounced', 'Bo', '', array(), array() ),
	'com' => array( 'complained', 'Com', '', array(), array() ),
	'spa' => array( 'spammed', 'Spa', '', array(), array() ),
	'wpu' => array( 'subscribed', 'Will', 'User', array(), array() ),
	'old' => array( 'subscribed', 'Old', 'Friend', array(), array() ),
	'tag' => array( 'subscribed', 'Tag', 'Along', array(), array( $btag->id ) ),
);
$fc_ids = array();
foreach ( $seed as $key => $s ) {
	$data = array(
		'email'      => $key . '@' . $domain,
		'first_name' => $s[1],
		'last_name'  => $s[2],
		'status'     => 'subscribed',
		'lists'      => $s[3],
		'tags'       => $s[4],
	);
	if ( 'wpu' === $key ) {
		$data['user_id'] = $user_id;
	}
	if ( 'sub' === $key ) {
		$data = array_merge( $data, array( 'rig_shirt' => 'M', 'rig_skills' => array( 'Cooking', 'Tech' ), 'rig_birthday' => '1990-07-04', 'rig_last_call' => '2024-05-01 09:30:00', 'rig_years' => '12', 'rig_bio' => "Line one\nLine two" ) );
	}
	$contact        = $api->createOrUpdate( $data );
	$fc_ids[ $key ] = (int) $contact->id;
	// FluentCRM's API checks statuses on the way in, so set each one the way its own status change does.
	$wpdb->update( $wpdb->prefix . 'fc_subscribers', array( 'status' => $s[0] ), array( 'id' => $fc_ids[ $key ] ) );
}
$wpdb->update( $wpdb->prefix . 'fc_subscribers', array( 'created_at' => '2024-01-15 10:00:00' ), array( 'id' => $fc_ids['sub'] ) );
// Old data FluentCRM's form would refuse: a shirt size the field doesn't list.
$wpdb->insert( $wpdb->prefix . 'fc_subscriber_meta', array( 'subscriber_id' => $fc_ids['pen'], 'object_type' => 'custom_field', 'key' => 'rig_shirt', 'value' => 'XXL', 'created_by' => 0 ) );
$wpdb->insert( $wpdb->prefix . 'fc_subscriber_meta', array( 'subscriber_id' => $fc_ids['pen'], 'object_type' => 'custom_field', 'key' => 'rig_birthday', 'value' => 'not a date', 'created_by' => 0 ) );

$statuses = $wpdb->get_col( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE %s ORDER BY id ASC", '%@' . $wpdb->esc_like( $domain ) ) );
$ok( 'FluentCRM holds every status', array( 'subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed', 'subscribed', 'subscribed', 'subscribed' ) === $statuses, $statuses );
$pivot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot WHERE subscriber_id = %d", $fc_ids['sub'] ) );
$ok( 'Sue is on two lists with two tags', 4 === $pivot, $pivot );
$ok( 'the WordPress user is tied to Will', $user_id === (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}fc_subscribers WHERE id = %d", $fc_ids['wpu'] ) ) );

$ok( 'old is a contact who unsubscribed', is_int( $members->add_member( array( 'name' => 'Old Friend', 'email' => 'old@' . $domain, 'status' => 'unsubscribed', 'source' => 'manual' ) ) ) );
$ok( 'tag is a contact tagged Local', is_int( $members->add_member( array( 'name' => 'Tag Along', 'email' => 'tag@' . $domain, 'status' => 'subscribed', 'source' => 'manual', 'tags' => 'Local' ) ) ) );

// ---------------------------------------------------------------------
echo "\nPure reads\n";
// ---------------------------------------------------------------------
$counts = npmp_fcrm_counts();
$ok( 'counts by group', 4 <= $counts['subscribed'] && 1 <= $counts['pending'] && 1 <= $counts['unsubscribed'] && 1 <= $counts['transactional'] && 3 <= $counts['bounced'], $counts );
$plan = npmp_fcrm_settings_plan();
$ok( 'sender name and email can come over', 'change' === ( $plan['from_name']['state'] ?? '' ) && 'change' === ( $plan['from_email']['state'] ?? '' ), $plan );
$ok( 'reply-to has no match', 'unsupported' === ( $plan['reply_to']['state'] ?? '' ), $plan['reply_to'] ?? null );
$ok( 'no double opt-in row', ! isset( $plan['double_optin'] ) || 'unsupported' === $plan['double_optin']['state'], $plan['double_optin'] ?? null );
$offered = npmp_fcrm_extra_fields();
$by      = array();
foreach ( $offered as $key => $f ) {
	$by[ $f['slug'] ] = array( $key, $f['type'], $f['options'] );
}
$ok( 'custom fields offered with types', 'dropdown' === ( $by['rig_shirt'][1] ?? '' ) && 'checkbox' === ( $by['rig_skills'][1] ?? '' ) && 'date' === ( $by['rig_birthday'][1] ?? '' ) && 'text' === ( $by['rig_last_call'][1] ?? '' ) && 'number' === ( $by['rig_years'][1] ?? '' ) && 'textarea' === ( $by['rig_bio'][1] ?? '' ), $by );
$ok( 'choices come with them', array( 'Cooking', 'Driving', 'Tech' ) === ( $by['rig_skills'][2] ?? null ) );

ob_start();
npmp_fcrm_render_page();
$html = ob_get_clean();
$ok( 'screen lists what stays behind', false !== strpos( $html, 'Automations and funnels, email sequences, and forms' ) && false !== strpos( $html, 'Campaigns, email history' ) );
$ok( 'screen names the tags', false !== strpos( $html, 'Rig Donor' ) );
$ok( 'screen offers the custom fields', false !== strpos( $html, 'Rig Skills' ) );
$ok( 'screen explains double opt-in', false !== strpos( $html, 'FluentCRM sets double opt-in for each form and list' ) );

// ---------------------------------------------------------------------
echo "\nHub refusals before import\n";
// ---------------------------------------------------------------------
$ok( 'verify out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
$ok( 'deactivate out of order', 'npmp_hub_order' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
$res = npmp_import_hub_run_step( $slug, 'import' );
$ok( 'import step hands back the screen', is_array( $res ) && false !== strpos( (string) ( $res['url'] ?? '' ), 'page=npmp_fcrm_import' ), $res );

// ---------------------------------------------------------------------
echo "\nImport\n";
// ---------------------------------------------------------------------
$run = function ( $settings = array() ) use ( $by ) {
	npmp_fcrm_start( array(), $settings );
	$_POST['npmp_extra_on']     = array( $by['rig_shirt'][0], $by['rig_skills'][0], $by['rig_birthday'][0], $by['rig_last_call'][0], $by['rig_years'][0], $by['rig_bio'][0] );
	$_POST['npmp_extra_target'] = array();
	do_action( 'npmp_import_extra_fields_save', 'fluent-crm' );
	unset( $_POST['npmp_extra_on'], $_POST['npmp_extra_target'] );
	npmp_import_extra_request_counts( null, true );
	$guard = 0;
	do {
		$state = npmp_fcrm_run( 60, 3 );
		++$guard;
	} while ( $state['running'] && $guard < 30 );
	return array( $state, npmp_import_extra_request_counts( null, true ) );
};
list( $state, $cf ) = $run( array( 'from_name', 'from_email' ) );
$r = $state['results'];
$ok( 'finished', ! $state['running'] && $state['finished_at'] > 0, $state );
$ok( 'eight new, two joined contacts you had', 8 === (int) $r['created'] && 2 === (int) ( $r['updated'] + $r['unchanged'] ), $r );
$ok( 'no email sent', 0 === $mails, $mails );
$want = array(
	'sub' => 'subscribed',
	'pen' => 'pending',
	'uns' => 'unsubscribed',
	'tra' => 'inactive',
	'bou' => 'unsubscribed',
	'com' => 'unsubscribed',
	'spa' => 'unsubscribed',
	'wpu' => 'subscribed',
	'old' => 'unsubscribed',
	'tag' => 'subscribed',
);
$got = array();
foreach ( array_keys( $want ) as $key ) {
	$m          = $members->get_member_by_email( $key . '@' . $domain );
	$got[ $key ] = $m ? $m->status : null;
}
$ok( 'every status mapped, and old stays unsubscribed', $want === $got, $got );
$sue = $members->get_member_by_email( 'sub@' . $domain );
$ok( 'Sue keeps her name', $sue && 'Sue Sub' === $sue->name, $sue ? $sue->name : '' );
$ok( 'Sue keeps her signup date in site time', $sue && 0 === strpos( (string) $sue->created_at, '2024-01-15 10:00' ), $sue ? $sue->created_at : '' );
$ok( 'Sue has both tags', $sue && 'Rig Donor,Rig VIP' === $sue->tags, $sue ? $sue->tags : '' );
$tag = $members->get_member_by_email( 'tag@' . $domain );
$ok( 'tag keeps Local and gains Rig Board Tag', $tag && 'Local,Rig Board Tag' === $tag->tags, $tag ? $tag->tags : '' );
$una = $members->get_member_by_email( 'uns@' . $domain );
$ok( 'an unsubscribed contact still gets their tags', $una && 'Rig Donor' === $una->tags, $una ? $una->tags : '' );
$ok( 'contacts that gained tags counted', 3 === (int) $r['tagged'], $r );
$will_after = get_userdata( $user_id );
$ok( 'the WordPress user is untouched', $will_after && $will_after->user_email === $user_before->user_email && $will_after->roles === $user_before->roles && $will_after->display_name === $user_before->display_name );
$settings = npmp_mailpoet_current_settings();
$ok( 'sender name and email copied', 'Rig Club' === ( $settings['from_name']['value'] ?? '' ) && 'news@' . $domain === ( $settings['from_email']['value'] ?? '' ), $settings );

if ( $pro && function_exists( 'npmp_pro_groups_import_row' ) ) {
	$names = wp_get_object_terms( (int) $sue->id, NPMP_PRO_GROUP_TAXONOMY, array( 'fields' => 'names' ) );
	sort( $names );
	$ok( 'Sue is in both groups', array( 'Rig Board', 'Rig Members' ) === $names, $names );
	$una_groups = wp_get_object_terms( (int) $una->id, NPMP_PRO_GROUP_TAXONOMY, array( 'fields' => 'names' ) );
	$ok( 'an unsubscribed contact joins no group', array() === $una_groups, $una_groups );
}
if ( $pro ) {
	$manager = NPMP_Custom_Fields_Manager::get_instance();
	$ids     = array();
	foreach ( $manager->get_field_definitions( 'member' ) as $def ) {
		$ids[ $def['label'] ] = $def;
	}
	$vals = $manager->get_field_values( 'member', (int) $sue->id );
	$v    = function ( $label ) use ( $ids, $vals ) {
		return isset( $ids[ $label ] ) ? ( $vals[ $ids[ $label ]['id'] ] ?? null ) : 'no field';
	};
	$ok( 'Sue: shirt, skills, birthday, last call, years, bio', array( 'M', 'Cooking, Tech', '1990-07-04', '2024-05-01 09:30:00', '12', "Line one\nLine two" ) === array( $v( 'Rig Shirt' ), $v( 'Rig Skills' ), $v( 'Rig Birthday' ), $v( 'Rig Last Call' ), $v( 'Rig Years' ), $v( 'Rig Bio' ) ), $vals );
	$ok( 'skills field keeps FluentCRM\'s choices', array( 'Cooking', 'Driving', 'Tech' ) === $ids['Rig Skills']['options'] );
	$ok( 'Pen\'s XXL joins the new Shirt field, the bad date is skipped', 'XXL' === ( $manager->get_field_values( 'member', (int) $members->get_member_by_email( 'pen@' . $domain )->id )[ $ids['Rig Shirt']['id'] ] ?? '' ) && 1 === (int) ( $cf['skipped'][ $ids['Rig Birthday']['id'] ] ?? 0 ), $cf );
	$ok( 'custom field values counted', 7 === (int) $cf['written'], $cf );
} else {
	$ok( 'without Pro no custom field value is written', 0 === (int) $cf['written'], $cf );
}

echo "\nImport again\n";
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" );
list( $state2, $cf2 ) = $run();
$ok( 'no new contact', 0 === (int) $state2['results']['created'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) === $count, $state2['results'] );
$ok( 'no tag added twice', 0 === (int) $state2['results']['tagged'] && 'Rig Donor,Rig VIP' === $members->get_member_by_email( 'sub@' . $domain )->tags );
$ok( 'no custom field value written twice', 0 === (int) $cf2['written'], $cf2 );

// ---------------------------------------------------------------------
echo "\nHub steps\n";
// ---------------------------------------------------------------------
$counts = npmp_fcrm_hub_counts();
$ok( 'verify counts FluentCRM contacts', 10 <= (int) $counts[0]['old'] && 8 <= (int) $counts[0]['new'], $counts[0] );
$ok( 'no contact left behind', 0 === (int) $counts[1]['old'], $counts[1] );
$ok( 'no box ticked, refused', 'npmp_hub_unchecked' === $code( npmp_import_hub_run_step( $slug, 'verify', array() ) ) );
$ok( 'box ticked, verified', 'verified' === $code( npmp_import_hub_run_step( $slug, 'verify', array( 'verified' => 1 ) ) ) );
$ok( 'remove plugin before deactivate, refused', is_wp_error( npmp_import_hub_run_step( $slug, 'remove_plugin' ) ) );
$ok( 'deactivated', 'deactivated' === $code( npmp_import_hub_run_step( $slug, 'deactivate' ) ) );
$ok( 'FluentCRM is off', ! is_plugin_active( $plugin_file ) );
$ok( 'FluentCRM kept its contacts', 10 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );

echo "\nImport with FluentCRM off\n";
list( $state3 ) = $run();
$ok( 'runs from the tables alone, nothing doubles', 0 === (int) $state3['results']['created'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) === $count, $state3['results'] );

// Step 4 deletes the plugin's files, so keep a copy to move back.
$dir    = WP_PLUGIN_DIR . '/fluent-crm';
$backup = WP_PLUGIN_DIR . '/../npmp-rig-fluent-crm-' . getmypid();
exec( 'cp -R ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $backup ), $o, $copied );
$ok( 'copied FluentCRM aside', 0 === $copied && is_dir( $backup ) );
$res = npmp_import_hub_run_step( $slug, 'remove_plugin' );
$ok( 'removed FluentCRM\'s files', ! is_wp_error( $res ) && ! is_dir( $dir ), $code( $res ) );
$ok( 'its tables stay', '' !== npmp_fcrm_table() && 10 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );
$src = npmp_import_hub_source( $slug );
$ok( 'remove data shows unavailable with a reason', empty( $src['data_removal']['batch'] ) && false !== strpos( (string) ( $src['data_removal']['reason'] ?? '' ), 'FluentCRM Pro' ) );
$ok( 'remove data refused', is_wp_error( npmp_import_hub_run_step( $slug, 'remove_data', array( 'confirm' => 'DELETE' ) ) ) );
if ( ! is_dir( $dir ) && is_dir( $backup ) ) {
	rename( $backup, $dir );
}
wp_clean_plugins_cache();
$ok( 'FluentCRM is back', is_dir( $dir ) && ! is_dir( $backup ) );
$ok( 'FluentCRM turns on again', null === activate_plugin( $plugin_file ) );

// ---------------------------------------------------------------------
echo "\nCleanup\n";
// ---------------------------------------------------------------------
$fcin = implode( ',', array_map( 'intval', $fc_ids ) );
$wpdb->query( "DELETE FROM {$wpdb->prefix}fc_subscriber_pivot WHERE subscriber_id IN ($fcin)" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->prefix}fc_subscriber_meta WHERE subscriber_id IN ($fcin)" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->prefix}fc_subscribers WHERE id IN ($fcin)" ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fc_lists WHERE id IN (%d, %d)", $board->id, $mlist->id ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fc_tags WHERE id IN (%d, %d, %d)", $donor->id, $vip->id, $btag->id ) );
if ( null === $saved_defs ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$fc_meta} WHERE object_type = %s AND `key` = %s", 'option', 'contact_custom_fields' ) );
} else {
	$wpdb->update( $fc_meta, array( 'value' => $saved_defs ), array( 'object_type' => 'option', 'key' => 'contact_custom_fields' ) );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user_id );
foreach ( array_diff( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) ), $contacts_before ) as $id ) {
	wp_delete_post( $id, true );
}
if ( $pro ) {
	$keep = wp_list_pluck( (array) $saved['npmp_custom_field_definitions'], 'id' );
	foreach ( NPMP_Custom_Fields_Manager::get_instance()->get_field_definitions() as $def ) {
		if ( ! in_array( $def['id'], $keep, true ) ) {
			NPMP_Custom_Fields_Manager::get_instance()->delete_field_definition( $def['id'] );
		}
	}
	foreach ( array( 'Rig Board', 'Rig Members' ) as $group ) {
		$term = get_term_by( 'name', $group, NPMP_PRO_GROUP_TAXONOMY );
		if ( $term ) {
			wp_delete_term( $term->term_id, NPMP_PRO_GROUP_TAXONOMY );
		}
	}
}
foreach ( $saved as $name => $value ) {
	null === $value ? delete_option( $name ) : update_option( $name, $value );
}
$ok( 'contacts back to before', array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) ) == $contacts_before ); // phpcs:ignore Universal.Operators.StrictComparisons
$ok( 'no rig rows left in FluentCRM', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE %s", '%@' . $wpdb->esc_like( $domain ) ) ) );
$ok( 'FluentCRM active as before', is_plugin_active( $plugin_file ) );

printf( "\n%s: %d failed\n", $fails ? 'FAIL' : 'OK', $fails );
