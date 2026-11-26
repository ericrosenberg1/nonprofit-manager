<?php
/**
 * Real-WordPress check of the personal data tools (includes/npmp-privacy.php,
 * and Pro's includes/pro-privacy.php when Pro is active).
 *
 * Seeds people, gifts, queued and sent newsletter rows, opens, and (with Pro)
 * plans, custom fields, groups, the automation log, and a queued automation
 * email. Then it runs WordPress's own request flow end to end:
 * wp_create_user_request(), the confirmation core records when the person
 * clicks the link (_wp_privacy_account_request_confirmed()), and core's own
 * AJAX handlers for every exporter and eraser page, the way Tools, Export
 * Personal Data and Erase Personal Data call them. Last it reads the export
 * file core wrote and the database.
 *
 * Run inside a throwaway WordPress with the free plugin active and the
 * members, newsletters, and donations modules on, once with Pro off and once
 * with Pro on:
 *   wp eval-file tests/rig/privacy.php
 * Never against a live site: it deletes people.
 *
 * Unit tests: tests/test-privacy.php (free), Pro's tests/test-privacy.php.
 */

global $wpdb;

// wp eval-file runs this file inside a function, so plain top-level names aren't globals.
$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;

/**
 * Record one check.
 *
 * @param string $label    What we checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return void
 */
function rig_privacy_check( $label, $expected, $actual ) {
	global $rig_pass, $rig_fail;
	if ( $expected === $actual ) {
		$rig_pass++;
		printf( "  PASS  %s\n", $label );
	} else {
		$rig_fail++;
		printf( "  FAIL  %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

if ( ! function_exists( 'npmp_privacy_erase' ) || ! class_exists( 'NPMP_Member_Manager' ) || ! class_exists( 'NPMP_Donation_Manager' ) ) {
	echo "  FAIL  free plugin, members, or donations module not loaded\n";
	exit( 1 );
}

$rig_pro = function_exists( 'npmp_pro_privacy_erase' );
printf( "Pro %s\n", $rig_pro ? 'on' : 'off' );

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
wp_set_current_user( $admins[0]->ID );

// core's AJAX handlers end in wp_die(). Turn that into an exception we catch.
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	static function () {
		return static function () {
			throw new RuntimeException( 'rig-ajax-done' );
		};
	}
);

/**
 * Call one of core's privacy AJAX handlers and hand back its JSON.
 *
 * @param string $handler Function name.
 * @param array  $post    $_POST.
 * @return array
 */
function rig_privacy_ajax( $handler, array $post ) {
	$_POST    = $post;
	$_REQUEST = $post;
	ob_start();
	try {
		$handler();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$out = (string) ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : array(
		'success' => false,
		'raw'     => $out,
	);
}

/**
 * Make and confirm a request the way core does.
 *
 * @param string $email  Address.
 * @param string $action export_personal_data or remove_personal_data.
 * @return int Request ID.
 */
function rig_privacy_request( $email, $action ) {
	// One open request per address and action, so clear any from a run before.
	foreach ( get_posts(
		array(
			'post_type'   => 'user_request',
			'post_status' => 'any',
			'post_name__in' => array( $action ),
			'title'       => $email,
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	) as $old ) {
		wp_delete_post( $old, true );
	}
	$id = wp_create_user_request( $email, $action );
	if ( is_wp_error( $id ) ) {
		echo '  FAIL  wp_create_user_request: ' . $id->get_error_message() . "\n";
		exit( 1 );
	}
	_wp_privacy_account_request_confirmed( $id );
	return (int) $id;
}

/**
 * Run every eraser, every page, through core's AJAX handler.
 *
 * @param string $email Address.
 * @return array{ok:bool,removed:bool,retained:bool,messages:string[],pages:int,status:string}
 */
function rig_privacy_erase( $email ) {
	$id      = rig_privacy_request( $email, 'remove_personal_data' );
	$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$out     = array(
		'ok'       => true,
		'removed'  => false,
		'retained' => false,
		'messages' => array(),
		'pages'    => 0,
		'order'    => array_keys( $erasers ),
	);
	$index   = 0;
	foreach ( $erasers as $key => $eraser ) {
		$index++;
		$page = 1;
		do {
			$r = rig_privacy_ajax(
				'wp_ajax_wp_privacy_erase_personal_data',
				array(
					'id'       => $id,
					'eraser'   => $index,
					'page'     => $page,
					'security' => wp_create_nonce( 'wp-privacy-erase-personal-data-' . $id ),
				)
			);
			if ( empty( $r['success'] ) ) {
				$out['ok'] = false;
				echo '  note  eraser ' . $key . ' answered: ' . wp_json_encode( $r ) . "\n";
				break;
			}
			$data = $r['data'];
			if ( 0 === strpos( (string) $key, 'nonprofit-manager' ) ) {
				$out['pages']   += 1;
				$out['removed']  = $out['removed'] || ! empty( $data['items_removed'] );
				$out['retained'] = $out['retained'] || ! empty( $data['items_retained'] );
				$out['messages'] = array_merge( $out['messages'], (array) $data['messages'] );
			}
			$page++;
		} while ( empty( $data['done'] ) && $page < 100 );
	}
	clean_post_cache( $id );
	$out['status'] = (string) get_post_status( $id );
	return $out;
}

/**
 * Run every exporter, every page, through core's AJAX handler, and read the
 * export.json core wrote into its zip.
 *
 * @param string $email Address.
 * @return array{ok:bool,json:array,pages:array}
 */
function rig_privacy_export( $email ) {
	$id        = rig_privacy_request( $email, 'export_personal_data' );
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$out       = array(
		'ok'    => true,
		'json'  => array(),
		'pages' => array(),
	);
	$index     = 0;
	$url       = '';
	foreach ( $exporters as $key => $exporter ) {
		$index++;
		$page = 1;
		do {
			$r = rig_privacy_ajax(
				'wp_ajax_wp_privacy_export_personal_data',
				array(
					'id'          => $id,
					'exporter'    => $index,
					'page'        => $page,
					'security'    => wp_create_nonce( 'wp-privacy-export-personal-data-' . $id ),
					'sendAsEmail' => 'false',
				)
			);
			if ( empty( $r['success'] ) ) {
				$out['ok'] = false;
				echo '  note  exporter ' . $key . ' answered: ' . wp_json_encode( $r ) . "\n";
				break;
			}
			$out['pages'][ $key ] = $page;
			if ( ! empty( $r['data']['url'] ) ) {
				$url = (string) $r['data']['url'];
			}
			$page++;
		} while ( empty( $r['data']['done'] ) && $page < 100 );
	}
	$dir  = wp_privacy_exports_dir();
	$path = $url ? $dir . basename( wp_parse_url( $url, PHP_URL_PATH ) ) : '';
	if ( $path && is_readable( $path ) ) {
		$zip = new ZipArchive();
		if ( true === $zip->open( $path ) ) {
			$json        = $zip->getFromName( 'export.json' );
			$out['json'] = is_string( $json ) ? (array) json_decode( $json, true ) : array();
			$zip->close();
		}
	} else {
		$out['ok'] = false;
		echo "  note  no export file at {$path}\n";
	}
	return $out;
}

/**
 * Group items in an export.json for one group.
 *
 * @param array  $json   Decoded export.json.
 * @param string $group  Group ID.
 * @return array
 */
function rig_privacy_group( array $json, $group ) {
	foreach ( $json as $person ) {
		if ( is_array( $person ) && isset( $person[ $group ] ) ) {
			return (array) ( $person[ $group ]['items'] ?? array() );
		}
	}
	return array();
}

/**
 * A fingerprint of every row our tools touch, to prove a second run changes
 * nothing.
 *
 * @return string
 */
function rig_privacy_fingerprint() {
	global $wpdb;
	$parts   = array();
	$parts[] = $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type IN ('npmp_contact','npmp_donation') ORDER BY ID", ARRAY_N );
	$parts[] = $wpdb->get_results( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ('npmp_contact','npmp_donation') AND m.meta_key NOT IN ('_npmp_privacy_erased','_edit_lock') ORDER BY m.meta_id", ARRAY_N );
	foreach ( array( 'npmp_newsletter_queue', 'npmp_digest_queue', 'npmp_newsletter_opens', 'npmp_payment_log', 'npmp_recurring_donations', 'npmp_custom_fields', 'npmp_automation_log' ) as $name ) {
		$table = $wpdb->prefix . $name;
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$parts[] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_N );
		}
	}
	$parts[] = $wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_N );
	return md5( wp_json_encode( $parts ) );
}

// ---------------------------------------------------------------------------
// Clean up a run before, then seed.
// ---------------------------------------------------------------------------
$rig_domain = '@privacy-rig.example';
foreach ( get_posts(
	array(
		'post_type'   => array( 'npmp_contact', 'npmp_donation' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'meta_query'  => array(
			'relation' => 'OR',
			array(
				'key'     => 'npmp_email',
				'value'   => $rig_domain,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_npmp_donation_email',
				'value'   => $rig_domain,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_npmp_donation_txn_id',
				'value'   => 'rig_privacy_',
				'compare' => 'LIKE',
			),
		),
	)
) as $old ) {
	wp_delete_post( $old, true );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email LIKE %s", '%' . $rig_domain ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_digest_queue WHERE email LIKE %s", '%' . $rig_domain ) );

$mm      = NPMP_Member_Manager::get_instance();
$donor   = (int) $mm->add_member(
	array(
		'name'          => 'Rig Donor',
		'email'         => 'rig.donor' . $rig_domain,
		'status'        => 'subscribed',
		'address_line1' => '1 High Street',
		'city'          => 'Leeds',
		'country'       => 'United Kingdom',
		'tags'          => 'choir',
		'notes'         => 'Met at the summer fair',
	)
);
$unsub   = (int) $mm->add_member(
	array(
		'name'   => 'Una Gone',
		'email'  => 'gone' . $rig_domain,
		'status' => 'unsubscribed',
		'phone'  => '0113 496 0000',
		'tags'   => 'walkers',
	)
);
$other   = (int) $mm->add_member(
	array(
		'name'   => 'Otto Other',
		'email'  => 'other' . $rig_domain,
		'status' => 'subscribed',
		'city'   => 'York',
	)
);
$held    = $rig_pro ? (int) $mm->add_member(
	array(
		'name'             => 'Hal Held',
		'email'            => 'held' . $rig_domain,
		'status'           => 'subscribed',
		'membership_level' => 'Gold',
	)
) : 0;
update_post_meta( $donor, '_npmp_weekly_digest', '1' );
update_post_meta( $unsub, '_npmp_weekly_digest', '1' );
if ( defined( 'NPMP_IMPORT_FREE_META' ) ) {
	update_post_meta( $donor, NPMP_IMPORT_FREE_META, 1 );
	update_post_meta( $unsub, NPMP_IMPORT_FREE_META, 1 );
}
rig_privacy_check( 'seeded three contacts', true, $donor > 0 && $unsub > 0 && $other > 0 );

// 105 gifts: more than one page of NPMP_PRIVACY_PAGE_SIZE (100). Mixed case on purpose.
$dm    = NPMP_Donation_Manager::get_instance();
$gifts = array();
for ( $i = 1; $i <= 105; $i++ ) {
	$gifts[] = (int) $dm->log_donation(
		array(
			'email'          => 0 === $i % 2 ? 'Rig.Donor' . $rig_domain : 'rig.donor' . $rig_domain,
			'name'           => 'Rig Donor',
			'amount'         => $i,
			'frequency'      => 'one_time',
			'gateway'        => 'stripe',
			'currency'       => 'GBP',
			'transaction_id' => 'rig_privacy_' . $i . '_' . uniqid(),
		)
	);
}
$other_gift = (int) $dm->log_donation(
	array(
		'email'          => 'other' . $rig_domain,
		'name'           => 'Otto Other',
		'amount'         => 7,
		'gateway'        => 'stripe',
		'currency'       => 'GBP',
		'transaction_id' => 'rig_privacy_other_' . uniqid(),
	)
);
$held_gift  = $rig_pro ? (int) $dm->log_donation(
	array(
		'email'          => 'held' . $rig_domain,
		'name'           => 'Hal Held',
		'amount'         => 30,
		'gateway'        => 'stripe',
		'currency'       => 'GBP',
		'transaction_id' => 'rig_privacy_held_' . uniqid(),
	)
) : 0;
rig_privacy_check( '105 gifts recorded for the donor', 105, count( array_filter( $gifts ) ) );
$dm->log_payment_verification(
	array(
		'donation_id'      => $gifts[0],
		'gateway'          => 'paypal_api',
		'gateway_order_id' => 'RIGORDER1',
		'status'           => 'COMPLETED',
		'verified'         => true,
		'raw_response'     => array( 'payer' => array( 'email_address' => 'rig.donor' . $rig_domain ) ),
	)
);

$now = current_time( 'mysql' );
foreach ( array( array( 'rig.donor', 'pending' ), array( 'RIG.DONOR', 'sent' ), array( 'other', 'pending' ), array( 'gone', 'sent' ) ) as $row ) {
	$wpdb->insert(
		$wpdb->prefix . 'npmp_newsletter_queue',
		array(
			'newsletter_id' => 1,
			'user_id'       => 0,
			'email'         => $row[0] . $rig_domain,
			'status'        => $row[1],
			'queued_at'     => $now,
		)
	);
}
$wpdb->insert( $wpdb->prefix . 'npmp_digest_queue', array( 'email' => 'rig.donor' . $rig_domain, 'status' => 'pending' ) );
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}npmp_newsletter_opens (user_id, newsletter_id, opened_at) VALUES (%d, 1, %s), (%d, 1, %s)", $donor, $now, $other, $now ) );

if ( $rig_pro ) {
	$plans = $wpdb->prefix . 'npmp_recurring_donations';
	$wpdb->insert( $plans, array( 'member_id' => $donor, 'external_id' => 'sub_rig_cancelled_' . uniqid(), 'amount' => 5, 'frequency' => 'monthly', 'status' => 'cancelled', 'source' => 'donation', 'currency' => 'GBP' ) );
	$wpdb->insert( $plans, array( 'member_id' => $held, 'external_id' => 'sub_rig_active_' . uniqid(), 'amount' => 40, 'frequency' => 'yearly', 'status' => 'active', 'source' => 'dues', 'membership_level' => 'Gold', 'currency' => 'GBP' ) );
	$cf = NPMP_Custom_Fields_Manager::get_instance();
	$cf->save_field_definition(
		array(
			'label'        => 'Rig Allergy',
			'type'         => 'text',
			'entity_types' => array( 'member' ),
		)
	);
	$field_id = '';
	foreach ( $cf->get_field_definitions( 'member' ) as $def ) {
		if ( 'Rig Allergy' === $def['label'] ) {
			$field_id = $def['id'];
		}
	}
	$cf->save_field_value( 'member', $donor, $field_id, 'Peanuts' );
	$cf->save_field_value( 'member', $held, $field_id, 'Shellfish' );
	$term = term_exists( 'Rig Choir', 'npmp_group' );
	$term = $term ? $term : wp_insert_term( 'Rig Choir', 'npmp_group' );
	wp_set_object_terms( $donor, array( (int) $term['term_id'] ), 'npmp_group' );
	wp_set_object_terms( $held, array( (int) $term['term_id'] ), 'npmp_group' );
	wp_set_object_terms( $unsub, array( (int) $term['term_id'] ), 'npmp_group' );
	$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-welcome', 'recipient_email' => 'Rig.Donor' . $rig_domain, 'status' => 'sent' ) );
	$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-welcome', 'recipient_email' => 'held' . $rig_domain, 'status' => 'sent' ) );
	wp_schedule_single_event( time() + 3600, 'npmp_send_automation_email', array( 'rig-welcome', 'rig.donor' . $rig_domain, 'Rig Donor', 0 ) );
	wp_schedule_single_event( time() + 3600, 'npmp_send_automation_email', array( 'rig-welcome', 'other' . $rig_domain, 'Otto Other', 0 ) );
}

// ---------------------------------------------------------------------------
// Export.
// ---------------------------------------------------------------------------
echo "\nExport through core's request flow\n";
$export = rig_privacy_export( 'Rig.Donor' . $rig_domain );
rig_privacy_check( 'every exporter answered success', true, $export['ok'] );
rig_privacy_check( 'gift exporter ran 2 pages for 105 gifts', 2, $export['pages']['nonprofit-manager-gifts'] ?? 0 );
$contact_items = rig_privacy_group( $export['json'], 'npmp-contact' );
$gift_items    = rig_privacy_group( $export['json'], 'npmp-gifts' );
rig_privacy_check( 'export file holds one contact record', 1, count( $contact_items ) );
rig_privacy_check( 'export file holds 105 gifts', 105, count( $gift_items ) );
$contact_json = wp_json_encode( $contact_items );
rig_privacy_check( 'contact record names the city', true, false !== strpos( $contact_json, 'Leeds' ) );
rig_privacy_check( 'contact record names the person', true, false !== strpos( $contact_json, 'Rig Donor' ) );
rig_privacy_check( 'subscription group present', true, count( rig_privacy_group( $export['json'], 'npmp-subscription' ) ) >= 1 );
rig_privacy_check( 'no gateway payload in the export', false, false !== strpos( wp_json_encode( $export['json'] ), 'RIGORDER1' ) );
if ( $rig_pro ) {
	rig_privacy_check( 'Pro: plan exported', 1, count( rig_privacy_group( $export['json'], 'npmp-pro-plans' ) ) );
	rig_privacy_check( 'Pro: custom field exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-pro-fields' ) ), 'Peanuts' ) );
	rig_privacy_check( 'Pro: group exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-pro-groups' ) ), 'Rig Choir' ) );
	rig_privacy_check( 'Pro: automation log exported', 1, count( rig_privacy_group( $export['json'], 'npmp-pro-automations' ) ) );
	rig_privacy_check( 'Pro: no Stripe subscription ID in the export', false, false !== strpos( wp_json_encode( $export['json'] ), 'sub_rig_' ) );
}

// ---------------------------------------------------------------------------
// Erase the donor.
// ---------------------------------------------------------------------------
echo "\nErase the donor through core's request flow\n";
$token_before = (string) get_option( 'npmp_dashboard_donations_token', '' );
$used_before  = function_exists( 'npmp_import_free_used' ) ? npmp_import_free_used( true ) : 0;
$erase        = rig_privacy_erase( 'RIG.donor' . $rig_domain );
rig_privacy_check( 'every eraser answered success', true, $erase['ok'] );
rig_privacy_check( 'core marked the request completed', 'request-completed', $erase['status'] );
rig_privacy_check( 'removed and retained reported', array( true, true ), array( $erase['removed'], $erase['retained'] ) );
rig_privacy_check( 'free eraser ran 2 pages for 105 gifts', $rig_pro ? 3 : 2, $erase['pages'] );
rig_privacy_check( 'gift message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'accounting records' ) );
if ( $rig_pro ) {
	rig_privacy_check( 'Pro eraser runs before free', true, array_search( 'nonprofit-manager-pro', $erase['order'], true ) < array_search( 'nonprofit-manager', $erase['order'], true ) );
}
clean_post_cache( $donor );
rig_privacy_check( 'donor contact deleted', null, get_post( $donor ) );
rig_privacy_check( 'request-scoped index forgets the donor', array(), $mm->lookup_emails( array( 'rig.donor' . $rig_domain ) ) );
rig_privacy_check( 'manager finds no donor', null, $mm->get_member_by_email( 'rig.donor' . $rig_domain ) );
$left_email = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_npmp_donation_email' AND LOWER( meta_value ) = %s", 'rig.donor' . $rig_domain ) );
rig_privacy_check( 'no gift keeps the email', 0, $left_email );
$kept = 0;
$sum  = 0.0;
foreach ( $gifts as $gift_id ) {
	clean_post_cache( $gift_id );
	$post = get_post( $gift_id );
	if ( $post && 'publish' === $post->post_status && false === strpos( $post->post_title, 'Rig Donor' ) && false === strpos( $post->post_title, $rig_domain ) ) {
		$kept++;
		$sum += (float) get_post_meta( $gift_id, '_npmp_donation_amount', true );
	}
}
rig_privacy_check( 'all 105 gifts kept without name or email', 105, $kept );
rig_privacy_check( 'gift amounts add up as before (1 to 105)', 5565.0, $sum );
rig_privacy_check( 'gift currency kept', 'GBP', get_post_meta( $gifts[0], '_npmp_donation_currency', true ) );
rig_privacy_check( 'gift transaction ID kept for dedupe', true, 0 === strpos( (string) get_post_meta( $gifts[0], '_npmp_donation_txn_id', true ), 'rig_privacy_1_' ) );
rig_privacy_check( 'payer details gone from the payment log', null, $wpdb->get_var( $wpdb->prepare( "SELECT raw_response FROM {$wpdb->prefix}npmp_payment_log WHERE donation_id = %d", $gifts[0] ) ) );
rig_privacy_check( 'payment log order ID kept', 'RIGORDER1', $wpdb->get_var( $wpdb->prepare( "SELECT gateway_order_id FROM {$wpdb->prefix}npmp_payment_log WHERE donation_id = %d", $gifts[0] ) ) );
rig_privacy_check( 'queue rows to the donor gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE LOWER( email ) = %s", 'rig.donor' . $rig_domain ) ) );
rig_privacy_check( 'digest row to the donor gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_digest_queue WHERE LOWER( email ) = %s", 'rig.donor' . $rig_domain ) ) );
rig_privacy_check( 'donor opens gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_opens WHERE user_id = %d", $donor ) ) );
rig_privacy_check( 'other reader\'s queue row kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'other' . $rig_domain ) ) );
rig_privacy_check( 'other reader\'s opens kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_opens WHERE user_id = %d", $other ) ) );
rig_privacy_check( 'other contact untouched', 'York', get_post_meta( $other, 'npmp_city', true ) );
rig_privacy_check( 'other gift untouched', 'other' . $rig_domain, get_post_meta( $other_gift, '_npmp_donation_email', true ) );
// npmp_dashboard_donations_changed() writes the new token at shutdown.
do_action( 'shutdown' );
rig_privacy_check( 'dashboard donation cache token changed', true, $token_before !== (string) get_option( 'npmp_dashboard_donations_token', '' ) );
if ( function_exists( 'npmp_import_free_used' ) ) {
	rig_privacy_check( 'deleted contact gives its free import place back', $used_before - 1, npmp_import_free_used( true ) );
}
if ( $rig_pro ) {
	rig_privacy_check( 'Pro: donor custom field values gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_type = 'member' AND entity_id = %d", $donor ) ) );
	rig_privacy_check( 'Pro: donor automation log gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_automation_log WHERE LOWER( recipient_email ) = %s", 'rig.donor' . $rig_domain ) ) );
	rig_privacy_check( 'Pro: donor queued automation email gone', false, (bool) wp_next_scheduled( 'npmp_send_automation_email', array( 'rig-welcome', 'rig.donor' . $rig_domain, 'Rig Donor', 0 ) ) );
	rig_privacy_check( 'Pro: other reader\'s automation email kept', true, (bool) wp_next_scheduled( 'npmp_send_automation_email', array( 'rig-welcome', 'other' . $rig_domain, 'Otto Other', 0 ) ) );
	rig_privacy_check( 'Pro: cancelled plan row kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d AND status = 'cancelled'", $donor ) ) );
	rig_privacy_check( 'Pro: plan message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'ended dues and recurring plans' ) );
}

echo "\nA second erase of the donor changes nothing\n";
$before = rig_privacy_fingerprint();
$again  = rig_privacy_erase( 'rig.donor' . $rig_domain );
rig_privacy_check( 'second run answered success', true, $again['ok'] );
rig_privacy_check( 'second run removes nothing', false, $again['removed'] );
rig_privacy_check( 'database unchanged by the second run', $before, rig_privacy_fingerprint() );

// ---------------------------------------------------------------------------
// Erase the unsubscribed contact.
// ---------------------------------------------------------------------------
echo "\nErase the unsubscribed contact\n";
$erase = rig_privacy_erase( 'gone' . $rig_domain );
clean_post_cache( $unsub );
rig_privacy_check( 'answered success', true, $erase['ok'] );
rig_privacy_check( 'contact kept', 'publish', get_post_status( $unsub ) );
$meta_keys = array_keys( get_post_meta( $unsub ) );
sort( $meta_keys );
$expect = defined( 'NPMP_IMPORT_FREE_META' ) ? array( '_npmp_free_import', 'npmp_email', 'npmp_status' ) : array( 'npmp_email', 'npmp_status' );
rig_privacy_check( 'only email, status, and the import mark left', $expect, $meta_keys );
rig_privacy_check( 'name replaced by the address', 'gone' . $rig_domain, get_the_title( $unsub ) );
rig_privacy_check( 'still unsubscribed through the manager', 'unsubscribed', $mm->get_member_by_email( 'gone' . $rig_domain )->status ?? '' );
rig_privacy_check( 'retained message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'Unsubscribed status' ) );
rig_privacy_check( 'sent newsletter row to the address gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'gone' . $rig_domain ) ) );
if ( $rig_pro ) {
	rig_privacy_check( 'Pro: groups removed from the kept contact', array(), wp_get_object_terms( $unsub, 'npmp_group', array( 'fields' => 'ids' ) ) );
}
$add = $mm->add_member( array( 'email' => 'GONE' . $rig_domain, 'name' => 'Una Again' ) );
rig_privacy_check( 'a later signup finds the kept address', true, is_wp_error( $add ) );
$before = rig_privacy_fingerprint();
rig_privacy_erase( 'gone' . $rig_domain );
rig_privacy_check( 'second run changes nothing', $before, rig_privacy_fingerprint() );

// ---------------------------------------------------------------------------
// Pro: an active plan holds the person.
// ---------------------------------------------------------------------------
if ( $rig_pro ) {
	echo "\nPro: erase a member with active dues\n";
	$erase = rig_privacy_erase( 'held' . $rig_domain );
	clean_post_cache( $held );
	rig_privacy_check( 'answered success', true, $erase['ok'] );
	rig_privacy_check( 'retained', true, $erase['retained'] );
	rig_privacy_check( 'hold message shown once', 1, count( array_filter( $erase['messages'], static function ( $m ) { return false !== strpos( $m, 'Cancel the plan first' ); } ) ) );
	rig_privacy_check( 'contact kept whole', 'Gold', get_post_meta( $held, 'npmp_membership_level', true ) );
	rig_privacy_check( 'gift kept with email', 'held' . $rig_domain, get_post_meta( $held_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'active plan untouched', 'active', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $held ) ) );
	rig_privacy_check( 'custom field kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_id = %d", $held ) ) );
	rig_privacy_check( 'group kept', 1, count( wp_get_object_terms( $held, 'npmp_group', array( 'fields' => 'ids' ) ) ) );
	rig_privacy_check( 'automation log row gone (a log, bills nothing)', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_automation_log WHERE recipient_email = %s", 'held' . $rig_domain ) ) );
	$wpdb->update( $wpdb->prefix . 'npmp_recurring_donations', array( 'status' => 'cancelled' ), array( 'member_id' => $held ) );
	$erase = rig_privacy_erase( 'held' . $rig_domain );
	clean_post_cache( $held );
	rig_privacy_check( 'after the admin cancels: contact deleted', null, get_post( $held ) );
	rig_privacy_check( 'after the admin cancels: gift stripped', '', get_post_meta( $held_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'after the admin cancels: plan row kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $held ) ) );
}

// ---------------------------------------------------------------------------
// An address nobody has.
// ---------------------------------------------------------------------------
echo "\nErase an unknown address\n";
$before = rig_privacy_fingerprint();
$erase  = rig_privacy_erase( 'nobody' . $rig_domain );
rig_privacy_check( 'answered success', true, $erase['ok'] );
rig_privacy_check( 'nothing removed or retained', array( false, false, array() ), array( $erase['removed'], $erase['retained'], $erase['messages'] ) );
rig_privacy_check( 'database unchanged', $before, rig_privacy_fingerprint() );

// Never touches WordPress users.
rig_privacy_check( 'the admin user still exists', true, (bool) get_userdata( $admins[0]->ID ) );

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
