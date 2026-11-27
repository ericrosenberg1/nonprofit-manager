<?php
/**
 * Real-WordPress check of the personal data tools (includes/npmp-privacy.php,
 * and Pro's includes/pro-privacy.php when Pro is active).
 *
 * Seeds people, 250 gifts, queued and sent newsletter rows, opens, similar
 * addresses, plan rows in each status, and (with Pro) custom fields, groups,
 * group opt-outs, the automation log, a queued automation email, and a
 * configured Nonprofit Mailer queue. With Pro off it also runs as a site that
 * kept Pro's tables (Pro data, no Pro eraser) and as a site that never had
 * Pro (Pro's tables renamed away). With Pro on it also runs as an older Pro
 * (Pro's eraser unhooked) and as a newer Pro beside an older free (free's
 * eraser unhooked). Then it runs WordPress's own request flow end to end:
 * wp_create_user_request(), the confirmation core records when the person
 * clicks the link (_wp_privacy_account_request_confirmed()), and core's own
 * AJAX handlers for every exporter and eraser page, the way Tools, Export
 * Personal Data and Erase Personal Data call them. Last it reads the export
 * file core wrote and the database.
 *
 * Run inside a throwaway WordPress with the free plugin active and the
 * members, newsletters, and donations modules on, three times: Pro off, Pro
 * on, and Pro on but unlicensed (an mu-plugin that adds
 * add_filter( 'npmp_pro_should_load_features', '__return_false' ) when
 * NPMP_RIG_UNLICENSED is set, so Pro's eraser loads and its features don't):
 *   wp eval-file tests/rig/privacy.php
 *   NPMP_RIG_UNLICENSED=1 wp eval-file tests/rig/privacy.php
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

// Pro's eraser loads ahead of the licence gate. Its features (custom fields,
// groups, the Recurring Donations screen) load only with a licence.
$rig_pro_eraser = function_exists( 'npmp_pro_privacy_erase' );
$rig_pro        = $rig_pro_eraser && taxonomy_exists( 'npmp_group' ) && class_exists( 'NPMP_Custom_Fields_Manager' );
$rig_unlicensed = $rig_pro_eraser && ! $rig_pro;
printf( "Pro %s\n", $rig_pro ? 'on' : ( $rig_unlicensed ? 'on, unlicensed' : 'off' ) );
$GLOBALS['rig_page_ms'] = array();

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
	rig_privacy_forget_memos();
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
			$t0 = microtime( true );
			$r  = rig_privacy_ajax(
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
			if ( 'nonprofit-manager' === $key ) {
				$GLOBALS['rig_page_ms'][] = ( microtime( true ) - $t0 ) * 1000;
			}
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
 * Each eraser asks once a request whether the other one is on core's list.
 * This file runs many requests in one process and moves erasers on and off
 * the list, so it asks again before each run.
 *
 * @return void
 */
function rig_privacy_forget_memos() {
	npmp_privacy_pro_eraser_present( true );
	if ( function_exists( 'npmp_pro_privacy_free_eraser_present' ) ) {
		npmp_pro_privacy_free_eraser_present( true );
	}
}

/**
 * Run our erasers directly, Pro's first, every page, the way core would. Core's
 * own request flow runs sanitize_email(), which drops accented letters, so an
 * accented address reaches our erasers only through a direct call.
 *
 * @param string $email Address.
 * @return array{messages:string[]}
 */
function rig_privacy_erase_direct( $email ) {
	rig_privacy_forget_memos();
	$messages = array();
	if ( function_exists( 'npmp_pro_privacy_erase' ) ) {
		$messages = array_merge( $messages, npmp_pro_privacy_erase( $email, 1 )['messages'] );
	}
	$page = 1;
	do {
		$r        = npmp_privacy_erase( $email, $page );
		$messages = array_merge( $messages, $r['messages'] );
		$page++;
	} while ( empty( $r['done'] ) && $page < 100 );
	return array( 'messages' => $messages );
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
$rig_like = '%' . $wpdb->esc_like( $rig_domain ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email LIKE %s", $rig_like ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_digest_queue WHERE email LIKE %s", $rig_like ) );
foreach ( array( 'npmp_automation_log' => 'recipient_email', 'npmp_mailer_queue' => 'payload', 'npmp_members' => 'email', 'npmp_contacts' => 'email', 'npmp_donations' => 'email' ) as $rig_table => $rig_col ) {
	if ( $wpdb->prefix . $rig_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $rig_table ) ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$rig_table} WHERE {$rig_col} LIKE %s", $rig_like ) );
	}
}
foreach ( (array) _get_cron_array() as $rig_ts => $rig_hooks ) {
	foreach ( (array) ( $rig_hooks['npmp_send_automation_email'] ?? array() ) as $rig_event ) {
		if ( false !== strpos( (string) ( $rig_event['args'][1] ?? '' ), $rig_domain ) ) {
			wp_unschedule_event( $rig_ts, 'npmp_send_automation_email', $rig_event['args'] );
		}
	}
}
delete_option( 'npmp_mailer_api_key' );
delete_option( 'npmp_mailer_api_url' );

/**
 * Whether a table exists.
 *
 * @param string $name Table name without the prefix.
 * @return bool
 */
function rig_privacy_has_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $name ) );
}

/**
 * Add a contact with one gift and one queued newsletter row.
 *
 * @param string $email Address.
 * @param string $name  Name.
 * @return array{0:int,1:int} Contact ID and gift ID.
 */
function rig_privacy_person( $email, $name ) {
	global $wpdb;
	$id   = (int) NPMP_Member_Manager::get_instance()->add_member(
		array(
			'name'   => $name,
			'email'  => $email,
			'status' => 'subscribed',
		)
	);
	$gift = (int) NPMP_Donation_Manager::get_instance()->log_donation(
		array(
			'email'          => $email,
			'name'           => $name,
			'amount'         => 3,
			'gateway'        => 'stripe',
			'currency'       => 'GBP',
			'transaction_id' => 'rig_privacy_p_' . uniqid(),
		)
	);
	$wpdb->insert(
		$wpdb->prefix . 'npmp_newsletter_queue',
		array(
			'newsletter_id' => 1,
			'user_id'       => 0,
			'email'         => $email,
			'status'        => 'pending',
			'queued_at'     => current_time( 'mysql' ),
		)
	);
	return array( $id, $gift );
}

/**
 * Add a plan row straight into Pro's table, the way Pro writes one.
 *
 * @param int    $member_id Contact ID.
 * @param string $status    Plan status.
 * @param string $method    Payment method.
 * @return void
 */
function rig_privacy_plan( $member_id, $status, $method = 'pm_rig_card' ) {
	global $wpdb;
	$wpdb->insert(
		$wpdb->prefix . 'npmp_recurring_donations',
		array(
			'member_id'      => $member_id,
			'external_id'    => 'sub_rig_' . $status . '_' . uniqid(),
			'amount'         => 12,
			'frequency'      => 'monthly',
			'status'         => $status,
			'payment_method' => $method,
			'source'         => 'dues',
			'currency'       => 'GBP',
		)
	);
}
$rig_pro_tables = rig_privacy_has_table( 'npmp_recurring_donations' ) && rig_privacy_has_table( 'npmp_custom_fields' );
printf( "Pro tables %s\n", $rig_pro_tables ? 'present' : 'absent' );

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

// 250 gifts: three pages of NPMP_PRIVACY_PAGE_SIZE (100). Mixed case on purpose.
$dm    = NPMP_Donation_Manager::get_instance();
$gifts = array();
for ( $i = 1; $i <= 250; $i++ ) {
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
rig_privacy_check( '250 gifts recorded for the donor', 250, count( array_filter( $gifts ) ) );
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
	$wpdb->insert( $plans, array( 'member_id' => $donor, 'external_id' => 'sub_rig_cancelled_' . uniqid(), 'amount' => 5, 'frequency' => 'monthly', 'status' => 'cancelled', 'payment_method' => 'pm_rig_donor', 'source' => 'donation', 'currency' => 'GBP' ) );
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
	update_post_meta( $donor, 'npmp_group_optouts', array( (int) $term['term_id'] => 1767225600 ) );
	$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-welcome', 'recipient_email' => 'Rig.Donor' . $rig_domain, 'status' => 'sent' ) );
	$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-welcome', 'recipient_email' => 'held' . $rig_domain, 'status' => 'sent' ) );
	wp_schedule_single_event( time() + 3600, 'npmp_send_automation_email', array( 'rig-welcome', 'rig.donor' . $rig_domain, 'Rig Donor', 0 ) );
	wp_schedule_single_event( time() + 3600, 'npmp_send_automation_email', array( 'rig-welcome', 'other' . $rig_domain, 'Otto Other', 0 ) );
}

// A row the one-time migration left in the old members and donations tables.
$wpdb->insert( $wpdb->prefix . 'npmp_members', array( 'name' => 'Rig Donor', 'email' => 'Rig.Donor' . $rig_domain, 'city' => 'Leeds' ) );
$wpdb->insert( $wpdb->prefix . 'npmp_donations', array( 'name' => 'Rig Donor', 'email' => 'rig.donor' . $rig_domain, 'amount' => 4, 'frequency' => 'one_time', 'gateway' => 'stripe' ) );
$legacy_gift = (int) $wpdb->insert_id;

// Text the strip must sweep: a comment and an autosave on a gift and on the
// unsubscribed contact, an excerpt, and ping lists.
foreach ( array( $gifts[0], $unsub ) as $rig_post ) {
	wp_insert_comment(
		array(
			'comment_post_ID'      => $rig_post,
			'comment_author'       => 'Rig Commenter',
			'comment_author_email' => 'commenter' . $rig_domain,
			'comment_content'      => 'Note about Rig Donor',
			'comment_approved'     => 1,
		)
	);
	wp_insert_post(
		array(
			'post_type'   => 'revision',
			'post_status' => 'inherit',
			'post_parent' => $rig_post,
			'post_name'   => $rig_post . '-autosave-v1',
			'post_title'  => 'Rig Donor draft',
		)
	);
	$wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'Rig Donor excerpt', 'post_content_filtered' => 'Rig Donor filtered', 'to_ping' => 'https://ping.invalid/rig', 'pinged' => 'https://pinged.invalid/rig' ), array( 'ID' => $rig_post ) );
	clean_post_cache( $rig_post );
}
$rig_slug_before = array( get_post( $gifts[0] )->post_name, get_post( $unsub )->post_name );
$rig_guid_before = array( get_post( $gifts[0] )->guid, get_post( $unsub )->guid );
printf( "  note  before: gift slug %s, guid %s, contact slug %s, guid %s\n", $rig_slug_before[0], $rig_guid_before[0], $rig_slug_before[1], $rig_guid_before[1] );

// An old signup row with its token, which the export must leave out.
$wpdb->insert( $wpdb->prefix . 'npmp_contacts', array( 'email' => 'rig.donor' . $rig_domain, 'name' => 'Rig Donor', 'status' => 'subscribed', 'token' => 'tok_rig_secret' ) );

// Similar addresses: the erase of a@ must leave each of these alone.
$similar = array();
foreach ( array( 'a', 'aa', 'a+news' ) as $local ) {
	$similar[ $local . $rig_domain ] = rig_privacy_person( $local . $rig_domain, 'Sim ' . $local );
}
$similar[ 'a' . $rig_domain . '.uk' ] = rig_privacy_person( 'a' . $rig_domain . '.uk', 'Sim uk' );

// ---------------------------------------------------------------------------
// Export.
// ---------------------------------------------------------------------------
echo "\nExport through core's request flow\n";
$export = rig_privacy_export( 'Rig.Donor' . $rig_domain );
rig_privacy_check( 'every exporter answered success', true, $export['ok'] );
rig_privacy_check( 'gift exporter ran 3 pages for 250 gifts', 3, $export['pages']['nonprofit-manager-gifts'] ?? 0 );
$contact_items = rig_privacy_group( $export['json'], 'npmp-contact' );
$gift_items    = rig_privacy_group( $export['json'], 'npmp-gifts' );
rig_privacy_check( 'export file holds one contact record', 1, count( $contact_items ) );
rig_privacy_check( 'export file holds 250 gifts', 250, count( $gift_items ) );
rig_privacy_check( 'export file names no other gift', false, false !== strpos( wp_json_encode( $gift_items ), 'npmp-gift-' . $other_gift . '"' ) );
rig_privacy_check( 'older members table row exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-legacy' ) ), 'Older contact record' ) );
rig_privacy_check( 'older signup row exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-legacy' ) ), 'Older email signup' ) );
rig_privacy_check( 'older signup token never exported', false, false !== strpos( wp_json_encode( $export['json'] ), 'tok_rig_secret' ) );
$rig_legacy_names = array();
foreach ( rig_privacy_group( $export['json'], 'npmp-legacy' ) as $rig_item ) {
	foreach ( (array) ( $rig_item['data'] ?? array() ) as $rig_pair ) {
		$rig_legacy_names[ $rig_pair['name'] ] = true;
	}
}
$rig_allowed = array( 'Kind' => true );
foreach ( NPMP_Privacy_Store::LEGACY_COLUMNS as $rig_cols ) {
	foreach ( $rig_cols as $rig_col ) {
		$rig_allowed[ ucfirst( str_replace( '_', ' ', $rig_col ) ) ] = true;
	}
}
rig_privacy_check( 'older rows export only allowed columns', array(), array_keys( array_diff_key( $rig_legacy_names, $rig_allowed ) ) );
$contact_json = wp_json_encode( $contact_items );
rig_privacy_check( 'contact record names the city', true, false !== strpos( $contact_json, 'Leeds' ) );
rig_privacy_check( 'contact record names the person', true, false !== strpos( $contact_json, 'Rig Donor' ) );
rig_privacy_check( 'subscription group present', true, count( rig_privacy_group( $export['json'], 'npmp-subscription' ) ) >= 1 );
rig_privacy_check( 'no gateway payload in the export', false, false !== strpos( wp_json_encode( $export['json'] ), 'RIGORDER1' ) );
if ( $rig_pro ) {
	rig_privacy_check( 'Pro: plan exported', 1, count( rig_privacy_group( $export['json'], 'npmp-pro-plans' ) ) );
	rig_privacy_check( 'Pro: custom field exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-pro-fields' ) ), 'Peanuts' ) );
	rig_privacy_check( 'Pro: group exported', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-pro-groups' ) ), 'Rig Choir' ) );
	rig_privacy_check( 'Pro: group opt-out exported with its date', true, false !== strpos( wp_json_encode( rig_privacy_group( $export['json'], 'npmp-pro-groups' ) ), 'Rig Choir (2026-01-01)' ) );
	rig_privacy_check( 'Pro: no payment method in the export', false, false !== strpos( wp_json_encode( $export['json'] ), 'pm_rig_donor' ) );
	rig_privacy_check( 'Pro: automation log exported', 1, count( rig_privacy_group( $export['json'], 'npmp-pro-automations' ) ) );
	rig_privacy_check( 'Pro: no Stripe subscription ID in the export', false, false !== strpos( wp_json_encode( $export['json'] ), 'sub_rig_' ) );
}

// ---------------------------------------------------------------------------
// Erase the donor.
// ---------------------------------------------------------------------------
echo "\nErase the donor through core's request flow\n";
if ( $rig_pro ) {
	NPMP_Mailer_Connector::install_table();
	update_option( 'npmp_mailer_api_url', 'https://mailer.invalid' );
	update_option( 'npmp_mailer_api_key', 'npm_rig_' . wp_generate_password( 12, false ) );
	NPMP_Mailer_Connector::enqueue_contact( $donor, 'rig.donor' . $rig_domain );
	NPMP_Mailer_Connector::enqueue_event( 'rig-donation-1', 'donation_received', 'rig.donor' . $rig_domain, array( 'amount' => 1 ) );
}
$token_before = (string) get_option( 'npmp_dashboard_donations_token', '' );
$used_before  = function_exists( 'npmp_import_free_used' ) ? npmp_import_free_used( true ) : 0;
$erase        = rig_privacy_erase( 'RIG.donor' . $rig_domain );
rig_privacy_check( 'every eraser answered success', true, $erase['ok'] );
rig_privacy_check( 'core marked the request completed', 'request-completed', $erase['status'] );
rig_privacy_check( 'removed and retained reported', array( true, true ), array( $erase['removed'], $erase['retained'] ) );
rig_privacy_check( 'our erasers ran 3 pages for 250 gifts (plus 1 for Pro)', $rig_pro_eraser ? 4 : 3, $erase['pages'] );
printf( "  note  free eraser pages (ms): %s\n", implode( ', ', array_map( static function ( $ms ) { return number_format( $ms, 1 ); }, $GLOBALS['rig_page_ms'] ) ) );
rig_privacy_check( 'a 100-gift page runs under 5 seconds', true, max( $GLOBALS['rig_page_ms'] ) < 5000 );
rig_privacy_check( 'gift message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'accounting records' ) );
if ( $rig_pro_eraser ) {
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
rig_privacy_check( 'all 250 gifts kept without name or email', 250, $kept );
rig_privacy_check( 'gift amounts add up as before (1 to 250)', 31375.0, $sum );
rig_privacy_check( 'gift title stored empty', '', get_post( $gifts[0] )->post_title );
$rig_g = get_post( $gifts[0] );
rig_privacy_check( 'gift slug read the name before', true, false !== strpos( $rig_slug_before[0], 'rig-donor' ) );
rig_privacy_check( 'gift slug now erased-<id>', 'erased-' . $gifts[0], $rig_g->post_name );
rig_privacy_check( 'gift GUID names nobody', false, false !== stripos( $rig_g->guid, 'rig-donor' ) );
rig_privacy_check( 'gift excerpt, filtered content, and ping lists empty', array( '', '', '', '' ), array( $rig_g->post_excerpt, $rig_g->post_content_filtered, $rig_g->to_ping, $rig_g->pinged ) );
rig_privacy_check( 'gift keeps no old slug', array(), get_post_meta( $gifts[0], '_wp_old_slug' ) );
rig_privacy_check( 'gift comment gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $gifts[0] ) ) );
rig_privacy_check( 'gift autosave gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $gifts[0] ) ) );
rig_privacy_check( 'no gift of the 250 keeps a name in its slug', 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_map( 'intval', $gifts ) ) . ") AND post_name LIKE '%rig%'" ) );
rig_privacy_check( 'Donations screen reads Erased on request', 'Erased on request', npmp_donation_donor_name( get_post( $gifts[0] )->post_title, '', $gifts[0] ) );
rig_privacy_check( 'older members row deleted', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_members WHERE LOWER( email ) = %s", 'rig.donor' . $rig_domain ) ) );
rig_privacy_check( 'older donations row blanked, amount kept', array( '', '', '4.00' ), array_values( (array) $wpdb->get_row( $wpdb->prepare( "SELECT name, email, amount FROM {$wpdb->prefix}npmp_donations WHERE id = %d", $legacy_gift ), ARRAY_A ) ) );
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
	rig_privacy_check( 'Pro: plan message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'canceled and one-time dues and recurring plans' ) );
	rig_privacy_check( 'Pro: ended plan payment method blanked', '', (string) $wpdb->get_var( $wpdb->prepare( "SELECT payment_method FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $donor ) ) );
	$printed = wp_list_pluck( npmp_pro_print_donations_rows( array(), 5000 ), 'name' );
	rig_privacy_check( 'Pro: printout reads Erased on request for all 250 gifts', 250, count( array_keys( $printed, 'Erased on request', true ) ) );
	rig_privacy_check( 'Pro: printout never names the donor', false, in_array( 'Rig Donor', $printed, true ) );
	$mailer = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}npmp_mailer_queue WHERE LOWER( payload ) LIKE %s", '%' . $wpdb->esc_like( 'rig.donor' . $rig_domain ) . '%' ) );
	rig_privacy_check( 'Mailer: one queued row for the donor', 1, count( $mailer ) );
	$mailer_row = json_decode( (string) ( $mailer[0] ?? '' ), true );
	rig_privacy_check( 'Mailer: that row is the delete', true, ! empty( $mailer_row['deleted'] ) );
	rig_privacy_check( 'Mailer: the delete carries only the ID, address, and flag', array( 'deleted', 'email', 'post_id' ), ( static function ( $k ) { sort( $k ); return $k; } )( array_keys( (array) $mailer_row ) ) );
	rig_privacy_check( 'Mailer: message says to erase in Nonprofit Mailer', true, false !== strpos( implode( ' ', $erase['messages'] ), 'erase this person in Nonprofit Mailer' ) );
}

echo "\nA second erase of the donor changes nothing\n";
$before = rig_privacy_fingerprint();
$again  = rig_privacy_erase( 'rig.donor' . $rig_domain );
rig_privacy_check( 'second run answered success', true, $again['ok'] );
rig_privacy_check( 'second run removes nothing', false, $again['removed'] );
if ( $rig_pro ) {
	rig_privacy_check( 'second run keeps the pending Mailer delete', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_mailer_queue WHERE LOWER( payload ) LIKE %s", '%' . $wpdb->esc_like( 'rig.donor' . $rig_domain ) . '%' ) ) );
}
rig_privacy_check( 'database unchanged by the second run', $before, rig_privacy_fingerprint() );

// ---------------------------------------------------------------------------
// Erase the unsubscribed contact.
// ---------------------------------------------------------------------------
echo "\nErase the unsubscribed contact\n";
$unsub_modified = get_post( $unsub )->post_modified_gmt;
$erase          = rig_privacy_erase( 'gone' . $rig_domain );
clean_post_cache( $unsub );
rig_privacy_check( 'answered success', true, $erase['ok'] );
rig_privacy_check( 'contact kept', 'publish', get_post_status( $unsub ) );
$meta_keys = array_keys( get_post_meta( $unsub ) );
sort( $meta_keys );
$expect = defined( 'NPMP_IMPORT_FREE_META' ) ? array( '_npmp_free_import', '_npmp_privacy_erased', 'npmp_email', 'npmp_status' ) : array( '_npmp_privacy_erased', 'npmp_email', 'npmp_status' );
rig_privacy_check( 'only email, status, the import mark, and the erase date left', $expect, $meta_keys );
rig_privacy_check( 'last-changed date kept (nearest record of the unsubscribe)', $unsub_modified, get_post( $unsub )->post_modified_gmt );
rig_privacy_check( 'name replaced by the address', 'gone' . $rig_domain, get_the_title( $unsub ) );
$rig_c = get_post( $unsub );
rig_privacy_check( 'contact slug read the name before', true, false !== strpos( $rig_slug_before[1], 'una-gone' ) );
rig_privacy_check( 'contact slug now erased-<id>', 'erased-' . $unsub, $rig_c->post_name );
rig_privacy_check( 'contact GUID names nobody', false, false !== stripos( $rig_c->guid, 'una-gone' ) );
rig_privacy_check( 'contact excerpt, filtered content, and ping lists empty', array( '', '', '', '' ), array( $rig_c->post_excerpt, $rig_c->post_content_filtered, $rig_c->to_ping, $rig_c->pinged ) );
rig_privacy_check( 'contact keeps no old slug', array(), get_post_meta( $unsub, '_wp_old_slug' ) );
rig_privacy_check( 'contact comment gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $unsub ) ) );
rig_privacy_check( 'contact autosave gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $unsub ) ) );
rig_privacy_check( 'still unsubscribed through the manager', 'unsubscribed', $mm->get_member_by_email( 'gone' . $rig_domain )->status ?? '' );
rig_privacy_check( 'retained message shown', true, false !== strpos( implode( ' ', $erase['messages'] ), 'Unsubscribed status' ) );
rig_privacy_check( 'sent newsletter row to the address gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'gone' . $rig_domain ) ) );
if ( $rig_pro ) {
	rig_privacy_check( 'Pro: groups removed from the kept contact', array(), wp_get_object_terms( $unsub, 'npmp_group', array( 'fields' => 'ids' ) ) );
	$mailer     = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}npmp_mailer_queue WHERE LOWER( payload ) LIKE %s", '%' . $wpdb->esc_like( 'gone' . $rig_domain ) . '%' ) );
	$mailer_row = json_decode( (string) ( $mailer[0] ?? '' ), true );
	rig_privacy_check( 'Mailer: one row for the kept address, not a delete', array( 1, false ), array( count( $mailer ), ! empty( $mailer_row['deleted'] ) ) );
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
	rig_privacy_check( 'hold message shown once, no key: card link', 1, count( array_filter( $erase['messages'], static function ( $m ) { return false !== strpos( $m, 'needs your Stripe secret key' ) && false !== strpos( $m, '#npmp-privacy-requests' ); } ) ) );
	update_option( 'npmp_stripe_mode', 'test' );
	update_option( 'npmp_stripe_test_secret_key', 'sk_test_rig_only' );
	$rig_keyed = rig_privacy_erase( 'held' . $rig_domain );
	delete_option( 'npmp_stripe_test_secret_key' );
	delete_option( 'npmp_stripe_mode' );
	rig_privacy_check( 'with a key: hold sends the admin to Pro\'s Cancel', 1, count( array_filter( $rig_keyed['messages'], static function ( $m ) { return false !== strpos( $m, 'Cancel the plan on the Recurring Donations' ) && false === strpos( $m, '#npmp-privacy-requests' ); } ) ) );
	rig_privacy_check( 'hold message words the active status', true, false !== strpos( implode( ' ', $erase['messages'] ), 'an active recurring plan' ) );
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
// Similar addresses.
// ---------------------------------------------------------------------------
echo "\nErase a@ beside aa@, a+news@, and a@ at a longer domain\n";
$erase = rig_privacy_erase( 'a' . $rig_domain );
rig_privacy_check( 'answered success', true, $erase['ok'] );
clean_post_cache( $similar[ 'a' . $rig_domain ][0] );
rig_privacy_check( 'a@ contact deleted', null, get_post( $similar[ 'a' . $rig_domain ][0] ) );
rig_privacy_check( 'a@ gift stripped', '', get_post_meta( $similar[ 'a' . $rig_domain ][1], '_npmp_donation_email', true ) );
foreach ( $similar as $address => $pair ) {
	if ( 'a' . $rig_domain === $address ) {
		continue;
	}
	clean_post_cache( $pair[0] );
	rig_privacy_check( "$address contact kept", 'publish', get_post_status( $pair[0] ) );
	rig_privacy_check( "$address gift kept with its email", $address, get_post_meta( $pair[1], '_npmp_donation_email', true ) );
	rig_privacy_check( "$address queue row kept", 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", $address ) ) );
}

// ---------------------------------------------------------------------------
// Plan statuses, read from Pro's table with Pro on or off.
// ---------------------------------------------------------------------------
if ( $rig_pro_tables ) {
	echo "\nA lapsed plan and a paused plan both hold\n";
	$rig_way   = array(
		'off'        => 'Nonprofit Manager Pro is off, so you have no Cancel button here.',
		'unlicensed' => 'Cancel button needs an active licence.',
		'no_keys'    => 'needs your Stripe secret key for the current mode',
	);
	$rig_reach = $rig_pro ? 'no_keys' : ( $rig_unlicensed ? 'unlicensed' : 'off' );
	rig_privacy_check( 'Cancel reach reads ' . $rig_reach, $rig_reach, npmp_privacy_cancel_reach() );
	list( $lapsed, $lapsed_gift ) = rig_privacy_person( 'lapsed' . $rig_domain, 'Lee Lapsed' );
	list( $paused, $paused_gift ) = rig_privacy_person( 'paused' . $rig_domain, 'Pat Paused' );
	rig_privacy_plan( $lapsed, 'lapsed' );
	rig_privacy_plan( $paused, 'paused' );
	$erase = rig_privacy_erase( 'lapsed' . $rig_domain );
	clean_post_cache( $lapsed );
	rig_privacy_check( 'lapsed: contact kept', 'Lee Lapsed', get_the_title( $lapsed ) );
	rig_privacy_check( 'lapsed: gift kept with email', 'lapsed' . $rig_domain, get_post_meta( $lapsed_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'lapsed: message words the status', true, false !== strpos( implode( ' ', $erase['messages'] ), 'a lapsed plan that Stripe may still retry' ) );
	rig_privacy_check( 'lapsed: message names the way out', true, false !== strpos( implode( ' ', $erase['messages'] ), $rig_way[ $rig_reach ] ) );
	rig_privacy_check( 'lapsed: message links the card', true, false !== strpos( implode( ' ', $erase['messages'] ), 'page=npmp_general_settings#npmp-privacy-requests">Personal data requests</a>' ) );
	rig_privacy_check( 'lapsed: payment method kept', 'pm_rig_card', $wpdb->get_var( $wpdb->prepare( "SELECT payment_method FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $lapsed ) ) );
	$erase = rig_privacy_erase( 'paused' . $rig_domain );
	clean_post_cache( $paused );
	rig_privacy_check( 'paused: contact kept', 'Pat Paused', get_the_title( $paused ) );
	rig_privacy_check( 'paused: message words the status', true, false !== strpos( implode( ' ', $erase['messages'] ), 'a paused plan that Stripe bills again if anyone resumes the plan' ) );
	rig_privacy_check( 'paused: payment method kept', 'pm_rig_card', (string) $wpdb->get_var( $wpdb->prepare( "SELECT payment_method FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $paused ) ) );
	rig_privacy_check( 'paused: plan row kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $paused ) ) );
}

// ---------------------------------------------------------------------------
// Pro off, with Pro's data still in the database.
// ---------------------------------------------------------------------------
if ( ! $rig_pro_eraser && $rig_pro_tables ) {
	echo "\nPro off: a contact with a Pro custom field\n";
	list( $prodata, $prodata_gift ) = rig_privacy_person( 'prodata' . $rig_domain, 'Pru Data' );
	$wpdb->insert( $wpdb->prefix . 'npmp_custom_fields', array( 'entity_type' => 'member', 'entity_id' => $prodata, 'field_key' => 'rig_field', 'field_value' => 'rig-value' ) );
	$erase = rig_privacy_erase( 'prodata' . $rig_domain );
	clean_post_cache( $prodata );
	rig_privacy_check( 'retained', true, $erase['retained'] );
	rig_privacy_check( 'contact kept whole', 'Pru Data', get_the_title( $prodata ) );
	rig_privacy_check( 'gift kept with email', 'prodata' . $rig_domain, get_post_meta( $prodata_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'custom field kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_id = %d", $prodata ) ) );
	rig_privacy_check( 'message says to activate or update Pro', true, false !== strpos( implode( ' ', $erase['messages'] ), 'Activate or update Nonprofit Manager Pro' ) );
	rig_privacy_check( 'queue row still gone (bills nothing)', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'prodata' . $rig_domain ) ) );
	$wpdb->delete( $wpdb->prefix . 'npmp_custom_fields', array( 'entity_id' => $prodata ) );
	if ( rig_privacy_has_table( 'npmp_mailer_queue' ) ) {
		// Pro queued the Mailer's delete before someone turned Pro off. That
		// row is Pro's request to the Mailer, so it holds nobody.
		$wpdb->insert( $wpdb->prefix . 'npmp_mailer_queue', array( 'item_key' => 'contact:rig' . $prodata, 'kind' => 'contact', 'payload' => wp_json_encode( array( 'post_id' => $prodata, 'email' => 'prodata' . $rig_domain, 'deleted' => true ) ), 'attempts' => 0 ) );
	}
	$erase = rig_privacy_erase( 'prodata' . $rig_domain );
	clean_post_cache( $prodata );
	rig_privacy_check( 'with the field gone: contact deleted', null, get_post( $prodata ) );
}

// ---------------------------------------------------------------------------
// A site that never had Pro: Pro's tables renamed away for this one request.
// ---------------------------------------------------------------------------
if ( ! $rig_pro_eraser ) {
	echo "\nNo Pro ever: no Pro tables\n";
	list( $nopro, $nopro_gift ) = rig_privacy_person( 'nopro' . $rig_domain, 'Nora None' );
	$renamed = array();
	foreach ( array( 'npmp_recurring_donations', 'npmp_custom_fields', 'npmp_automation_log', 'npmp_mailer_queue' ) as $name ) {
		if ( rig_privacy_has_table( $name ) ) {
			$wpdb->query( "RENAME TABLE {$wpdb->prefix}{$name} TO {$wpdb->prefix}rigoff_{$name}" );
			$renamed[] = $name;
		}
	}
	npmp_privacy_store( new NPMP_Privacy_Store() );
	try {
		$erase = rig_privacy_erase( 'nopro' . $rig_domain );
	} finally {
		foreach ( $renamed as $name ) {
			$wpdb->query( "RENAME TABLE {$wpdb->prefix}rigoff_{$name} TO {$wpdb->prefix}{$name}" );
		}
		npmp_privacy_store( new NPMP_Privacy_Store() );
	}
	clean_post_cache( $nopro );
	rig_privacy_check( 'answered success', true, $erase['ok'] );
	rig_privacy_check( 'no Pro tables seen', false, rig_privacy_has_table( 'rigoff_npmp_custom_fields' ) );
	rig_privacy_check( 'contact deleted', null, get_post( $nopro ) );
	rig_privacy_check( 'gift stripped', '', get_post_meta( $nopro_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'no hold message', false, false !== strpos( implode( ' ', $erase['messages'] ), 'Pro' ) );
}

// ---------------------------------------------------------------------------
// An older Pro: Pro on, Pro's eraser not on core's list.
// ---------------------------------------------------------------------------
if ( $rig_pro ) {
	echo "\nOlder Pro: Pro on without its eraser\n";
	list( $oldpro, $oldpro_gift ) = rig_privacy_person( 'oldpro' . $rig_domain, 'Olga Old' );
	$cf->save_field_value( 'member', $oldpro, $field_id, 'Gluten' );
	remove_filter( 'wp_privacy_personal_data_erasers', 'npmp_pro_privacy_register_erasers', 9 );
	$erase = rig_privacy_erase( 'oldpro' . $rig_domain );
	add_filter( 'wp_privacy_personal_data_erasers', 'npmp_pro_privacy_register_erasers', 9 );
	clean_post_cache( $oldpro );
	rig_privacy_check( 'only free\'s eraser ran', false, in_array( 'nonprofit-manager-pro', $erase['order'], true ) );
	rig_privacy_check( 'contact kept whole', 'Olga Old', get_the_title( $oldpro ) );
	rig_privacy_check( 'gift kept with email', 'oldpro' . $rig_domain, get_post_meta( $oldpro_gift, '_npmp_donation_email', true ) );
	rig_privacy_check( 'message says to activate or update Pro', true, false !== strpos( implode( ' ', $erase['messages'] ), 'Activate or update Nonprofit Manager Pro' ) );
	$erase = rig_privacy_erase( 'oldpro' . $rig_domain );
	clean_post_cache( $oldpro );
	rig_privacy_check( 'after the update: contact deleted', null, get_post( $oldpro ) );
	rig_privacy_check( 'after the update: custom field gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_type = 'member' AND entity_id = %d", $oldpro ) ) );

	echo "\nNewer Pro beside an older free: free's eraser not on core's list\n";
	list( $newpro, $newpro_gift ) = rig_privacy_person( 'newpro' . $rig_domain, 'Ned New' );
	$cf->save_field_value( 'member', $newpro, $field_id, 'Dairy' );
	$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-welcome', 'recipient_email' => 'newpro' . $rig_domain, 'status' => 'sent' ) );
	$before = rig_privacy_fingerprint();
	remove_filter( 'wp_privacy_personal_data_erasers', 'npmp_privacy_register_erasers' );
	$erase = rig_privacy_erase( 'newpro' . $rig_domain );
	add_filter( 'wp_privacy_personal_data_erasers', 'npmp_privacy_register_erasers' );
	clean_post_cache( $newpro );
	rig_privacy_check( 'only Pro\'s eraser ran', array( false, true ), array( in_array( 'nonprofit-manager', $erase['order'], true ), in_array( 'nonprofit-manager-pro', $erase['order'], true ) ) );
	rig_privacy_check( 'Pro erased nothing, automation log included', $before, rig_privacy_fingerprint() );
	rig_privacy_check( 'retained', array( false, true ), array( $erase['removed'], $erase['retained'] ) );
	rig_privacy_check( 'message says to update Nonprofit Manager', true, false !== strpos( implode( ' ', $erase['messages'] ), 'Update Nonprofit Manager' ) );
	$erase = rig_privacy_erase( 'newpro' . $rig_domain );
	clean_post_cache( $newpro );
	rig_privacy_check( 'after the update: contact deleted', null, get_post( $newpro ) );
	rig_privacy_check( 'after the update: automation log gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_automation_log WHERE recipient_email = %s", 'newpro' . $rig_domain ) ) );
}

// ---------------------------------------------------------------------------
// Accented capitals: stored one way, asked the other (MySQL folds both sides).
// ---------------------------------------------------------------------------
echo "\nAccented capitals: stored one way, asked the other (direct calls)\n";
foreach ( array( 'JOSÉ' => 'josé', 'zoë' => 'ZOË' ) as $rig_stored_local => $rig_asked_local ) {
	$rig_stored = $rig_stored_local . $rig_domain;
	$rig_asked  = $rig_asked_local . $rig_domain;
	// sanitize_email() drops accented letters, so we write the meta directly,
	// the way an import from another tool can.
	$rig_cid = (int) wp_insert_post( array( 'post_type' => 'npmp_contact', 'post_status' => 'publish', 'post_title' => 'Acc ' . $rig_stored_local ) );
	update_post_meta( $rig_cid, 'npmp_email', $rig_stored );
	update_post_meta( $rig_cid, 'npmp_status', 'subscribed' );
	$rig_gid = (int) wp_insert_post( array( 'post_type' => 'npmp_donation', 'post_status' => 'publish', 'post_title' => 'Acc gift' ) );
	update_post_meta( $rig_gid, '_npmp_donation_email', $rig_stored );
	update_post_meta( $rig_gid, '_npmp_donation_amount', '5' );
	update_post_meta( $rig_gid, '_npmp_donation_txn_id', 'rig_privacy_acc_' . uniqid() );
	$wpdb->insert( $wpdb->prefix . 'npmp_newsletter_queue', array( 'newsletter_id' => 1, 'user_id' => 0, 'email' => $rig_stored, 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ) );
	if ( $rig_pro_eraser ) {
		$wpdb->insert( $wpdb->prefix . 'npmp_automation_log', array( 'automation_id' => 'rig-acc', 'recipient_email' => $rig_stored, 'status' => 'sent' ) );
	}
	$erase = rig_privacy_erase_direct( $rig_asked );
	clean_post_cache( $rig_cid );
	rig_privacy_check( "$rig_stored_local asked as $rig_asked_local: contact deleted", null, get_post( $rig_cid ) );
	rig_privacy_check( "$rig_stored_local asked as $rig_asked_local: gift stripped", '', get_post_meta( $rig_gid, '_npmp_donation_email', true ) );
	rig_privacy_check( "$rig_stored_local asked as $rig_asked_local: queue row gone", 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", $rig_stored ) ) );
	if ( $rig_pro_eraser ) {
		rig_privacy_check( "$rig_stored_local asked as $rig_asked_local: automation log gone", 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_automation_log WHERE recipient_email = %s", $rig_stored ) ) );
	}
}

echo "\nAn accented address is a different mailbox (josé is not jose)\n";
$rig_acc_cid = (int) wp_insert_post( array( 'post_type' => 'npmp_contact', 'post_status' => 'publish', 'post_title' => 'Acc jose-exact' ) );
update_post_meta( $rig_acc_cid, 'npmp_email', 'josé-exact' . $rig_domain );
update_post_meta( $rig_acc_cid, 'npmp_status', 'subscribed' );
$wpdb->insert( $wpdb->prefix . 'npmp_newsletter_queue', array( 'newsletter_id' => 1, 'user_id' => 0, 'email' => 'josé-exact' . $rig_domain, 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ) );
rig_privacy_check( 'the store finds no contact for jose-exact@', array(), npmp_privacy_store()->contacts( 'jose-exact' . $rig_domain ) );
$erase = rig_privacy_erase_direct( 'jose-exact' . $rig_domain );
clean_post_cache( $rig_acc_cid );
rig_privacy_check( 'erasing jose-exact@ keeps josé-exact@\'s contact', 'Acc jose-exact', get_the_title( $rig_acc_cid ) );
rig_privacy_check( 'and keeps josé-exact@\'s queue row', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'josé-exact' . $rig_domain ) ) );
$erase = rig_privacy_erase_direct( 'JOSÉ-EXACT' . $rig_domain );
clean_post_cache( $rig_acc_cid );
rig_privacy_check( 'JOSÉ-EXACT@ still finds josé-exact@', null, get_post( $rig_acc_cid ) );
rig_privacy_check( 'and its queue row goes', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE email = %s", 'josé-exact' . $rig_domain ) ) );

/**
 * Put a contact in a Pro group with SQL, which works with the taxonomy
 * registered or not.
 *
 * @param int    $contact Contact ID.
 * @param string $name    Group name.
 * @return int term_taxonomy_id
 */
function rig_privacy_sql_group( $contact, $name ) {
	global $wpdb;
	$wpdb->insert( $wpdb->terms, array( 'name' => $name, 'slug' => sanitize_title( $name . '-' . uniqid() ) ) );
	$term = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term, 'taxonomy' => 'npmp_group', 'description' => '', 'parent' => 0, 'count' => 1 ) );
	$tt = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $contact, 'term_taxonomy_id' => $tt ) );
	wp_cache_flush();
	return $tt;
}

// ---------------------------------------------------------------------------
// A hold only another plugin adds keeps every Pro row.
// ---------------------------------------------------------------------------
if ( $rig_pro_eraser ) {
	echo "\nA hold another plugin adds keeps Pro's rows\n";
	list( $legal, $legal_gift ) = rig_privacy_person( 'legal' . $rig_domain, 'Lex Legal' );
	$wpdb->insert( $wpdb->prefix . 'npmp_custom_fields', array( 'entity_type' => 'member', 'entity_id' => $legal, 'field_key' => 'rig_field', 'field_value' => 'rig-legal' ) );
	$legal_tt = rig_privacy_sql_group( $legal, 'Rig Legal Group' );
	$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => $legal, 'external_id' => 'sub_rig_legal_' . uniqid(), 'amount' => 9, 'frequency' => 'monthly', 'status' => 'cancelled', 'payment_method' => 'pm_rig_legal', 'source' => 'donation', 'currency' => 'GBP' ) );
	$rig_legal_hold = static function ( $holds ) {
		$holds[] = 'Legal hold <b>until</b> 2027';
		return $holds;
	};
	add_filter( 'npmp_privacy_erase_holds', $rig_legal_hold );
	$erase = rig_privacy_erase( 'legal' . $rig_domain );
	clean_post_cache( $legal );
	rig_privacy_check( 'contact kept whole', 'Lex Legal', get_the_title( $legal ) );
	rig_privacy_check( 'custom field kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_id = %d", $legal ) ) );
	rig_privacy_check( 'group row kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d", $legal, $legal_tt ) ) );
	rig_privacy_check( 'payment method kept', 'pm_rig_legal', $wpdb->get_var( $wpdb->prepare( "SELECT payment_method FROM {$wpdb->prefix}npmp_recurring_donations WHERE member_id = %d", $legal ) ) );
	rig_privacy_check( 'the hold prints once, as text', array( 'Legal hold &lt;b&gt;until&lt;/b&gt; 2027' ), $erase['messages'] );
	remove_filter( 'npmp_privacy_erase_holds', $rig_legal_hold );
	$erase = rig_privacy_erase( 'legal' . $rig_domain );
	clean_post_cache( $legal );
	rig_privacy_check( 'hold lifted: contact deleted', null, get_post( $legal ) );
	rig_privacy_check( 'hold lifted: group row gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $legal_tt ) ) );
}

// ---------------------------------------------------------------------------
// Group rows with no registered taxonomy.
// ---------------------------------------------------------------------------
if ( $rig_unlicensed ) {
	echo "\nUnlicensed Pro: group rows with no registered taxonomy\n";
	list( $orphan, $orphan_gift ) = rig_privacy_person( 'orphan' . $rig_domain, 'Ola Orphan' );
	$orphan_tt = rig_privacy_sql_group( $orphan, 'Rig Orphan Group' );
	rig_privacy_check( 'npmp_group is not registered', false, taxonomy_exists( 'npmp_group' ) );
	$erase = rig_privacy_erase( 'orphan' . $rig_domain );
	clean_post_cache( $orphan );
	rig_privacy_check( 'contact deleted', null, get_post( $orphan ) );
	rig_privacy_check( 'group row deleted by taxonomy name', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $orphan_tt ) ) );
	rig_privacy_check( 'group counted again', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $orphan_tt ) ) );
	rig_privacy_check( 'cancel reach reads unlicensed', 'unlicensed', npmp_privacy_cancel_reach() );
}
if ( ! $rig_pro_eraser && $rig_pro_tables ) {
	echo "\nPro off: a contact whose only Pro data is a group row\n";
	list( $orphan, $orphan_gift ) = rig_privacy_person( 'orphan' . $rig_domain, 'Ola Orphan' );
	$orphan_tt = rig_privacy_sql_group( $orphan, 'Rig Orphan Group' );
	$erase     = rig_privacy_erase( 'orphan' . $rig_domain );
	clean_post_cache( $orphan );
	rig_privacy_check( 'held for the group row', 'Ola Orphan', get_the_title( $orphan ) );
	rig_privacy_check( 'message says no licence is needed', true, false !== strpos( implode( ' ', $erase['messages'] ), 'this step needs no licence' ) );
	$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $orphan ) );
	$erase = rig_privacy_erase( 'orphan' . $rig_domain );
	clean_post_cache( $orphan );
	rig_privacy_check( 'with the row gone: contact deleted', null, get_post( $orphan ) );
}

// ---------------------------------------------------------------------------
// Pro's Cancel when Stripe answers 404 for a key in the wrong mode.
// ---------------------------------------------------------------------------
if ( $rig_pro_eraser && class_exists( 'NPMP_Recurring_Manager' ) ) {
	echo "\nPro's Cancel: a wrong-mode 404 keeps a failed plan open\n";
	update_option( 'npmp_stripe_mode', 'test' );
	update_option( 'npmp_stripe_test_secret_key', 'sk_test_rig_only' );
	$GLOBALS['rig_stripe'] = array();
	$GLOBALS['rig_sent']   = array();
	$rig_fake_stripe       = static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'api.stripe.com' ) ) {
			return $pre;
		}
		$GLOBALS['rig_sent'][] = $args['method'] ?? 'GET';
		$next                  = array_shift( $GLOBALS['rig_stripe'] ) ?: array( 500, array() );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => '',
			),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $rig_fake_stripe, 10, 3 );
	list( $wrong, $wrong_gift ) = rig_privacy_person( 'wrongmode' . $rig_domain, 'Wren Wrong' );
	$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => $wrong, 'external_id' => 'sub_rigwrong' . uniqid(), 'amount' => 15, 'frequency' => 'monthly', 'status' => 'failed', 'payment_method' => 'pm_rig_wrong', 'source' => 'donation', 'currency' => 'GBP' ) );
	$wrong_plan = (int) $wpdb->insert_id;
	$rig_404    = array( 404, array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such subscription: 'sub_rigwrong'; a similar object exists in live mode, but a test mode key was used to make this request." ) ) );
	$GLOBALS['rig_stripe'] = array( $rig_404, $rig_404 );
	$res = NPMP_Recurring_Manager::get_instance()->cancel_subscription( $wrong_plan );
	rig_privacy_check( 'Cancel answers an error', true, is_wp_error( $res ) );
	rig_privacy_check( 'we sent a DELETE, then a GET', array( 'DELETE', 'GET' ), $GLOBALS['rig_sent'] );
	rig_privacy_check( 'the failed row stays failed', 'failed', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $wrong_plan ) ) );
	$erase = rig_privacy_erase( 'wrongmode' . $rig_domain );
	clean_post_cache( $wrong );
	rig_privacy_check( 'the erase still holds', 'Wren Wrong', get_the_title( $wrong ) );
	$GLOBALS['rig_sent']   = array();
	$GLOBALS['rig_stripe'] = array(
		array( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'This subscription is already canceled.' ) ) ),
		array( 200, array( 'id' => 'sub_rigwrong', 'object' => 'subscription', 'status' => 'canceled' ) ),
	);
	$res = NPMP_Recurring_Manager::get_instance()->cancel_subscription( $wrong_plan );
	rig_privacy_check( 'Stripe shows it canceled: Cancel succeeds', true, true === $res );
	rig_privacy_check( 'the failed row closes', 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $wrong_plan ) ) );
	remove_filter( 'pre_http_request', $rig_fake_stripe, 10 );
	delete_option( 'npmp_stripe_test_secret_key' );
	delete_option( 'npmp_stripe_mode' );
	$erase = rig_privacy_erase( 'wrongmode' . $rig_domain );
	clean_post_cache( $wrong );
	rig_privacy_check( 'then the erase finishes', null, get_post( $wrong ) );
}

// ---------------------------------------------------------------------------
// "I canceled this plan in Stripe" on General Settings.
// ---------------------------------------------------------------------------
if ( rig_privacy_has_table( 'npmp_recurring_donations' ) ) {
	echo "\nI canceled this plan in Stripe\n";
	list( $mark, $mark_gift )     = rig_privacy_person( 'mark' . $rig_domain, 'Mia Mark' );
	list( $nomark, $nomark_gift ) = rig_privacy_person( 'nomark' . $rig_domain, 'Nia Nomark' );
	$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => $mark, 'external_id' => 'sub_rig_mark_' . uniqid(), 'amount' => 11, 'frequency' => 'monthly', 'status' => 'lapsed', 'payment_method' => 'pm_rig_mark', 'source' => 'dues', 'currency' => 'GBP' ) );
	$mark_plan = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => $nomark, 'external_id' => 'sub_rig_nomark_' . uniqid(), 'amount' => 11, 'frequency' => 'monthly', 'status' => 'active', 'payment_method' => 'pm_rig_nomark', 'source' => 'dues', 'currency' => 'GBP' ) );
	$nomark_plan = (int) $wpdb->insert_id;
	delete_option( 'npmp_privacy_plan_overrides' );
	rig_privacy_request( 'mark' . $rig_domain, 'remove_personal_data' );
	// 600 newer open requests, so the card has to page past the newest 500
	// (before 2026.11.31 it read the newest 100).
	$rig_filler = array();
	for ( $i = 0; $i < 600; $i++ ) {
		$rig_filler[] = (int) wp_insert_post( array( 'post_type' => 'user_request', 'post_name' => 'remove_personal_data', 'post_status' => 'request-pending', 'post_title' => 'filler' . $i . $rig_domain ) );
	}
	rig_privacy_check( '600 newer open requests seeded', 600, count( array_filter( $rig_filler ) ) );
	rig_privacy_check( 'the store reads all 601 open requests', true, 601 <= count( npmp_privacy_store()->open_erase_emails() ) );
	ob_start();
	npmp_privacy_settings_card();
	$card = (string) ob_get_clean();
	rig_privacy_check( 'card lists the held plan with an open request', true, false !== strpos( $card, 'name="plan_id" value="' . $mark_plan . '"' ) );
	rig_privacy_check( 'card leaves out a plan with no request', false, false !== strpos( $card, 'name="plan_id" value="' . $nomark_plan . '"' ) );
	rig_privacy_check( 'card posts the status the admin saw', true, false !== strpos( $card, 'name="plan_status" value="lapsed"' ) );
	rig_privacy_check( 'card says marking cancels nothing at Stripe', true, false !== strpos( $card, 'Marking a plan here cancels nothing at Stripe.' ) );
	rig_privacy_check( 'card posts to admin-post.php with a nonce', true, false !== strpos( $card, 'admin-post.php' ) && false !== strpos( $card, '_wpnonce' ) );

	$rig_redirect = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	add_filter( 'wp_redirect', $rig_redirect, PHP_INT_MAX );
	$rig_mark = static function ( $plan, $nonce_plan, $confirm, $method = 'POST', $seen = null ) use ( $wpdb ) {
		if ( null === $seen ) {
			// What the card would show now.
			$seen = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $plan ) );
		}
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = array(
			'action'      => 'npmp_privacy_mark_plan_cancelled',
			'plan_id'     => (string) $plan,
			'plan_status' => $seen,
			'_wpnonce'    => wp_create_nonce( 'npmp_privacy_mark_plan_' . $nonce_plan ),
		);
		if ( $confirm ) {
			$_POST['npmp_cancelled_in_stripe'] = '1';
		}
		$_REQUEST = $_POST;
		try {
			npmp_privacy_mark_plan_cancelled();
			return 'returned';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
	};
	$rig_status = static function ( $plan ) use ( $wpdb ) {
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT( status, '/', payment_method ) FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $plan ) );
	};
	rig_privacy_check( 'a GET is refused', 'rig-ajax-done', $rig_mark( $mark_plan, $mark_plan, true, 'GET' ) );
	$rig_editor = get_user_by( 'login', 'rigeditor' );
	$rig_editor = $rig_editor ? $rig_editor->ID : wp_insert_user( array( 'user_login' => 'rigeditor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'rigeditor@example.invalid' ) );
	wp_set_current_user( $rig_editor );
	rig_privacy_check( 'an editor is refused', 'rig-ajax-done', $rig_mark( $mark_plan, $mark_plan, true ) );
	wp_set_current_user( $admins[0]->ID );
	rig_privacy_check( 'another row\'s nonce is refused', 'rig-ajax-done', $rig_mark( $mark_plan, $nomark_plan, true ) );
	rig_privacy_check( 'no tick: sent back with an error', true, false !== strpos( $rig_mark( $mark_plan, $mark_plan, false ), 'npmp_privacy_error=confirm' ) );
	rig_privacy_check( 'refusals changed nothing', 'lapsed/pm_rig_mark', $rig_status( $mark_plan ) );
	rig_privacy_check( 'a plan with no erase request: refused', true, false !== strpos( $rig_mark( $nomark_plan, $nomark_plan, true ), 'npmp_privacy_error=held' ) );
	rig_privacy_check( 'that plan unchanged', 'active/pm_rig_nomark', $rig_status( $nomark_plan ) );
	// Stripe recovers the plan after the admin loaded the card as Lapsed.
	$wpdb->update( $wpdb->prefix . 'npmp_recurring_donations', array( 'status' => 'active' ), array( 'id' => $mark_plan ) );
	rig_privacy_check( 'the row went active after the card showed Lapsed: refused', true, false !== strpos( $rig_mark( $mark_plan, $mark_plan, true, 'POST', 'lapsed' ), 'npmp_privacy_error=changed' ) );
	rig_privacy_check( 'the active row stays open with its payment method', 'active/pm_rig_mark', $rig_status( $mark_plan ) );
	rig_privacy_check( 'no status posted (an older form): refused', true, false !== strpos( $rig_mark( $mark_plan, $mark_plan, true, 'POST', '' ), 'npmp_privacy_error=changed' ) );
	rig_privacy_check( 'no audit entry for a refusal', array(), (array) get_option( 'npmp_privacy_plan_overrides', array() ) );
	$wpdb->update( $wpdb->prefix . 'npmp_recurring_donations', array( 'status' => 'lapsed' ), array( 'id' => $mark_plan ) );
	rig_privacy_check( 'confirmed: sent back with the plan marked', true, false !== strpos( $rig_mark( $mark_plan, $mark_plan, true ), 'npmp_privacy_marked=' . $mark_plan ) );
	rig_privacy_check( 'the row reads cancelled with no payment method', 'cancelled/', $rig_status( $mark_plan ) );
	$rig_log = (array) get_option( 'npmp_privacy_plan_overrides', array() );
	rig_privacy_check( 'one audit entry', 1, count( $rig_log ) );
	rig_privacy_check( 'audit entry: plan, user, time, old status', array( $mark_plan, $admins[0]->ID, 'lapsed' ), array( $rig_log[0]['plan_id'] ?? 0, $rig_log[0]['user_id'] ?? 0, $rig_log[0]['from_status'] ?? '' ) );
	rig_privacy_check( 'audit entry keys', array( 'from_status', 'plan_id', 'time', 'user_id' ), ( static function ( $k ) { sort( $k ); return $k; } )( array_keys( (array) ( $rig_log[0] ?? array() ) ) ) );
	rig_privacy_check( 'a second click on a closed row: refused', true, false !== strpos( $rig_mark( $mark_plan, $mark_plan, true ), 'npmp_privacy_error=held' ) );
	rig_privacy_check( 'still one audit entry', 1, count( (array) get_option( 'npmp_privacy_plan_overrides', array() ) ) );
	remove_filter( 'wp_redirect', $rig_redirect, PHP_INT_MAX );
	unset( $_SERVER['REQUEST_METHOD'] );
	$_POST    = array();
	$_REQUEST = array();
	$erase    = rig_privacy_erase( 'mark' . $rig_domain );
	clean_post_cache( $mark );
	rig_privacy_check( 'then the erase finishes', null, get_post( $mark ) );
	rig_privacy_check( 'the audit trail never names the person', false, false !== strpos( wp_json_encode( get_option( 'npmp_privacy_plan_overrides' ) ), 'mark' . $rig_domain ) );
	ob_start();
	npmp_privacy_settings_card();
	$card = (string) ob_get_clean();
	rig_privacy_check( 'card lists who marked the plan', true, false !== strpos( $card, 'Plan #' . $mark_plan . ', Lapsed before, marked by' ) );
	delete_option( 'npmp_privacy_plan_overrides' );
	foreach ( $rig_filler as $rig_filler_id ) {
		wp_delete_post( $rig_filler_id, true );
	}
}

// ---------------------------------------------------------------------------
// A filter that clears every hold, with an active plan (both erasers agree).
// ---------------------------------------------------------------------------
if ( rig_privacy_has_table( 'npmp_recurring_donations' ) ) {
	echo "\nA filter that clears every hold keeps an active plan's hold\n";
	list( $clear, $clear_gift ) = rig_privacy_person( 'clear' . $rig_domain, 'Cleo Clear' );
	$wpdb->insert( $wpdb->prefix . 'npmp_recurring_donations', array( 'member_id' => $clear, 'external_id' => 'sub_rig_clear_' . uniqid(), 'amount' => 9, 'frequency' => 'monthly', 'status' => 'active', 'payment_method' => 'pm_rig_clear', 'source' => 'donation', 'currency' => 'GBP' ) );
	$clear_plan = (int) $wpdb->insert_id;
	if ( rig_privacy_has_table( 'npmp_custom_fields' ) ) {
		$wpdb->insert( $wpdb->prefix . 'npmp_custom_fields', array( 'entity_type' => 'member', 'entity_id' => $clear, 'field_key' => 'rig_field', 'field_value' => 'rig-clear' ) );
	}
	add_filter( 'npmp_privacy_erase_holds', '__return_empty_array' );
	rig_privacy_check( 'free and Pro get the same holds', npmp_privacy_erase_holds_for( 'clear' . $rig_domain, 'free' ), npmp_privacy_erase_holds_for( 'clear' . $rig_domain, 'pro' ) );
	rig_privacy_check( 'one plan hold each', 1, count( npmp_privacy_erase_holds_for( 'clear' . $rig_domain, 'pro' ) ) );
	$erase = rig_privacy_erase( 'clear' . $rig_domain );
	clean_post_cache( $clear );
	rig_privacy_check( 'contact kept whole', 'Cleo Clear', get_the_title( $clear ) );
	rig_privacy_check( 'active plan keeps its payment method', 'active/pm_rig_clear', (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT( status, '/', payment_method ) FROM {$wpdb->prefix}npmp_recurring_donations WHERE id = %d", $clear_plan ) ) );
	if ( rig_privacy_has_table( 'npmp_custom_fields' ) ) {
		rig_privacy_check( 'custom field kept', 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_custom_fields WHERE entity_id = %d", $clear ) ) );
	}
	rig_privacy_check( 'no message says we kept only canceled plans', false, false !== strpos( implode( ' ', $erase['messages'] ), 'canceled and one-time' ) );
	remove_filter( 'npmp_privacy_erase_holds', '__return_empty_array' );
	$wpdb->update( $wpdb->prefix . 'npmp_recurring_donations', array( 'status' => 'cancelled' ), array( 'id' => $clear_plan ) );
	if ( ! $rig_pro_eraser ) {
		// Pro off: only Pro's eraser clears these, so they would hold.
		$wpdb->update( $wpdb->prefix . 'npmp_recurring_donations', array( 'payment_method' => '' ), array( 'id' => $clear_plan ) );
		if ( rig_privacy_has_table( 'npmp_custom_fields' ) ) {
			$wpdb->delete( $wpdb->prefix . 'npmp_custom_fields', array( 'entity_id' => $clear ) );
		}
	}
	$erase = rig_privacy_erase( 'clear' . $rig_domain );
	clean_post_cache( $clear );
	rig_privacy_check( 'plan closed: the erase finishes', null, get_post( $clear ) );
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

if ( $rig_pro ) {
	delete_option( 'npmp_mailer_api_key' );
	delete_option( 'npmp_mailer_api_url' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}npmp_mailer_queue WHERE payload LIKE %s", $rig_like ) );
}

// Never touches WordPress users.
rig_privacy_check( 'the admin user still exists', true, (bool) get_userdata( $admins[0]->ID ) );

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] > 0 ? 1 : 0 );
