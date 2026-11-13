<?php
/**
 * Real-WordPress run of the Brevo import (includes/import/brevo-api.php and
 * the wizard's step handler in admin-import.php), against a fake Brevo.
 *
 * Nobody has run this against the live Brevo API. A pre_http_request filter
 * answers for api.brevo.com from the shapes in Brevo's reference pages (cited
 * at the top of brevo-api.php): 51 lists over two pages, eight attributes,
 * and 70 contacts on list 7 over three pages of 25. The fake turns down
 * a wrong key with 401, a key that lacks permission with 403, the second page's
 * first ask with 429, and the third page's first ask with a timeout.
 *
 * It drives the real AJAX handlers: list fetch, preview, then step until done,
 * resuming after the 429 and the timeout with the same job token. Then:
 * statuses (blocklisted and left-the-list come over as unsubscribed, an
 * existing unsubscribe stays), Added dates, names and phone, lists as Pro
 * groups, attributes as Pro custom fields with a bad date skipped and counted,
 * no email sent, the key in no option, transient, or answer, and a rerun with
 * Update existing contacts that makes no new contact. A second job with a
 * wrong key stops and keeps nothing. Run it with Pro on and off:
 *   wp eval-file tests/rig/brevo-import.php
 *
 * It deletes every contact, group, and field it made and puts the options it
 * touched back.
 */

$fails = 0;
$ok    = function ( $label, $cond, $detail = '' ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s%s\n", $cond ? 'PASS' : 'FAIL', $label, ( ! $cond && '' !== $detail ) ? '  [' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) . ']' : '' );
};

global $wpdb;
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( (int) $admins[0]->ID );
$pro    = npmp_import_extra_fields_supported();
$groups = defined( 'NPMP_PRO_GROUP_TAXONOMY' ) && function_exists( 'npmp_pro_groups_import_row' );
echo $pro ? "Pro active\n" : "Pro inactive\n";

$domain   = 'rig-brevo.example.org';
$good_key = 'xkeysib-' . str_repeat( 'b7', 32 ) . '-RIGSECRETTAIL';
$mails    = 0;
add_filter(
	'pre_wp_mail',
	function () use ( &$mails ) {
		++$mails;
		return true;
	}
);

$saved_options = array();
foreach ( array( 'npmp_custom_field_definitions', 'npmp_pro_import_made_fields' ) as $name ) {
	$saved_options[ $name ] = get_option( $name, null );
}
$contacts_before = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'npmp_contact'" ) );
$terms_before    = $groups ? array_map( 'intval', get_terms( array( 'taxonomy' => NPMP_PRO_GROUP_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ) ) : array();

// ---------------------------------------------------------------------
// The fake Brevo
// ---------------------------------------------------------------------
$lists = array();
foreach ( range( 1, 51 ) as $i ) {
	$lists[] = array( 'id' => $i, 'name' => 7 === $i ? 'Rig Board' : ( 8 === $i ? 'Rig Volunteers' : "Rig List $i" ), 'uniqueSubscribers' => 7 === $i ? 70 : 0, 'totalBlacklisted' => 0, 'folderId' => 1 );
}
$attributes = array(
	array( 'name' => 'FIRSTNAME', 'category' => 'normal', 'type' => 'text' ),
	array( 'name' => 'LASTNAME', 'category' => 'normal', 'type' => 'text' ),
	array( 'name' => 'SMS', 'category' => 'normal', 'type' => 'text' ),
	array( 'name' => 'RIG_CHAPTER', 'category' => 'category', 'type' => 'category', 'enumeration' => array( array( 'value' => 1, 'label' => 'North', 'valueStr' => '1' ), array( 'value' => 2, 'label' => 'South', 'valueStr' => '2' ) ) ),
	array( 'name' => 'RIG_VOLUNTEER', 'category' => 'normal', 'type' => 'boolean' ),
	array( 'name' => 'RIG_JOINED', 'category' => 'normal', 'type' => 'date' ),
	array( 'name' => 'RIG_DUES', 'category' => 'normal', 'type' => 'float' ),
	array( 'name' => 'ORDER_TOTAL', 'category' => 'transactional', 'type' => 'float' ),
);
$contacts = array();
for ( $i = 1; $i <= 70; $i++ ) {
	$contacts[] = array(
		'email'            => sprintf( 'c%02d@%s', $i, $domain ),
		'id'               => 1000 + $i,
		'emailBlacklisted' => 0 === $i % 10,
		'smsBlacklisted'   => false,
		'createdAt'        => gmdate( 'Y-m-d\TH:i:s.000\Z', gmmktime( 9, 0, 0, 1, $i, 2018 ) ),
		'modifiedAt'       => '2026-01-01T00:00:00.000Z',
		'listIds'          => 0 === $i % 3 ? array( 7, 8 ) : array( 7 ),
		'listUnsubscribed' => 0 === $i % 7 && 0 !== $i % 10 ? array( 7 ) : array(),
		'attributes'       => array(
			'FIRSTNAME'     => 'First' . $i,
			'LASTNAME'      => 'Last' . $i,
			'SMS'           => '+1555000' . sprintf( '%04d', $i ),
			'RIG_CHAPTER'   => 1 + $i % 2,
			'RIG_VOLUNTEER' => 0 === $i % 2,
			'RIG_JOINED'    => 5 === $i ? 'sometime in spring' : sprintf( '2019-02-%02d', 1 + $i % 28 ),
			'RIG_DUES'      => 12.5,
			'ORDER_TOTAL'   => 99,
		),
	);
}
$calls     = array();
$fail_once = array( 'offset=25' => 429, 'offset=50' => 'timeout' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$calls, &$fail_once, $lists, $attributes, $contacts, $good_key ) {
		if ( false === strpos( $url, 'api.brevo.com/v3' ) ) {
			return $pre;
		}
		$calls[] = $url;
		$reply   = function ( $code, $body, $headers = array() ) {
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'headers' => $headers, 'cookies' => array(), 'body' => wp_json_encode( $body ) );
		};
		$key = $args['headers']['api-key'] ?? '';
		if ( 'xkeysib-noperm' === $key ) {
			return $reply( 403, array( 'code' => 'permission_denied', 'message' => 'Permission denied' ) );
		}
		if ( $good_key !== $key ) {
			return $reply( 401, array( 'code' => 'unauthorized', 'message' => 'Key not found' ) );
		}
		$q = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/v3/contacts/lists' === $path ) {
			return $reply( 200, array( 'lists' => array_slice( $lists, (int) $q['offset'], min( 50, (int) $q['limit'] ) ), 'count' => count( $lists ) ) );
		}
		if ( '/v3/contacts/attributes' === $path ) {
			return $reply( 200, array( 'attributes' => $attributes ) );
		}
		if ( '/v3/contacts/lists/7/contacts' === $path ) {
			foreach ( $fail_once as $needle => $what ) {
				if ( false !== strpos( $url, $needle ) && (int) $q['limit'] > 5 ) {
					unset( $fail_once[ $needle ] );
					if ( 'timeout' === $what ) {
						return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
					}
					return $reply( 429, array( 'message' => 'Too many requests' ), array( 'x-sib-ratelimit-reset' => '3' ) );
				}
			}
			if ( 'asc' !== ( $q['sort'] ?? '' ) ) {
				return $reply( 400, array( 'code' => 'invalid_parameter', 'message' => 'rig wants asc' ) );
			}
			return $reply( 200, array( 'contacts' => array_slice( $contacts, (int) $q['offset'], min( 500, (int) $q['limit'] ) ), 'count' => count( $contacts ) ) );
		}
		return $reply( 404, array( 'message' => 'List ID not found' ) );
	},
	10,
	3
);
add_filter( 'npmp_import_brevo_page_size', function () {
	return 25;
} );

// ---------------------------------------------------------------------
// Run the real AJAX handlers in this process
// ---------------------------------------------------------------------
class NPMP_Rig_Brevo_Done extends Exception {}
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	function () {
		return function () {
			throw new NPMP_Rig_Brevo_Done();
		};
	}
);
$answers = '';
$ajax    = function ( $handler, array $post ) use ( &$answers ) {
	$_POST    = $post + array( 'nonce' => wp_create_nonce( 'npmp_import_nonce' ) );
	$_REQUEST = $_POST;
	ob_start();
	try {
		call_user_func( $handler );
	} catch ( NPMP_Rig_Brevo_Done $e ) {
		unset( $e );
	}
	$out      = (string) ob_get_clean();
	$answers .= $out;
	if ( ! empty( $post['job_token'] ) ) {
		// The handler lets go of its lock at shutdown, which one process never reaches.
		delete_transient( 'npmp_import_lock_' . $post['job_token'] );
	}
	return json_decode( $out, true );
};

// The Brevo card needs an administrator.
$ok( 'the source needs manage_options', npmp_import_user_can_source( 'brevo' ) && ! in_array( 'brevo', array( 'csv', 'xlsx', 'google_sheet' ), true ) );

echo "Keys Brevo turns down\n";
$r = $ajax( 'npmp_import_ajax_brevo_lists', array( 'api_key' => 'xkeysib-wrong' ) );
$ok( 'a wrong key gets a plain message', false === $r['success'] && false !== strpos( $r['data'], 'didn\'t accept that API key' ), $r );
$r = $ajax( 'npmp_import_ajax_brevo_lists', array( 'api_key' => 'xkeysib-noperm' ) );
$ok( 'a key without permission says so', false === $r['success'] && false !== strpos( $r['data'], 'won\'t let that key read contacts' ), $r );
$r = $ajax( 'npmp_import_ajax_brevo_lists', array( 'api_key' => '' ) );
$ok( 'no key, no request', false === $r['success'] );

echo "Lists and preview\n";
$r = $ajax( 'npmp_import_ajax_brevo_lists', array( 'api_key' => $good_key ) );
$ok( 'all 51 lists over two pages', true === $r['success'] && 51 === count( $r['data'] ) && 'Rig Board' === $r['data'][6]['name'], is_array( $r['data'] ) ? count( $r['data'] ) : $r );

// Someone who unsubscribed here already, and someone Brevo blocklisted who is subscribed here.
$members = NPMP_Member_Manager::get_instance();
$members->add_member( array( 'name' => 'Was Out', 'email' => 'c01@' . $domain, 'status' => 'unsubscribed', 'source' => 'manual' ) );
$members->add_member( array( 'name' => 'Still In', 'email' => 'c10@' . $domain, 'status' => 'subscribed', 'source' => 'manual' ) );

$preview = $ajax( 'npmp_import_ajax_preview', array( 'source' => 'brevo', 'brevo_api_key' => $good_key, 'brevo_list_id' => '7' ) );
$p       = $preview['data'] ?? array();
$ok( 'preview works', true === ( $preview['success'] ?? false ), $preview );
$ok( 'preview counts 70 and shows five', 70 === (int) ( $p['total_rows'] ?? 0 ) && 5 === count( $p['rows'] ?? array() ) );
$ok( 'transactional attributes stay behind', ! in_array( 'ORDER_TOTAL', (array) ( $p['headers'] ?? array() ), true ), $p['headers'] ?? '' );
$ok( 'names and phone map themselves', 'first_name' === $p['auto_map'][4] && 'last_name' === $p['auto_map'][5] && 'phone' === $p['auto_map'][6], $p['auto_map'] ?? '' );
$ok( 'Lists maps to Groups only with Pro', ( $groups ? 'groups' : '' ) === $p['auto_map'][3], $p['auto_map'][3] ?? '' );
$ok( 'four custom-field columns offered', array( 7, 8, 9, 10 ) === array_map( 'intval', array_keys( (array) $p['extra']['columns'] ) ), array_keys( (array) ( $p['extra']['columns'] ?? array() ) ) );
$ok( 'the browser gets the secret, not the key', ! empty( $p['brevo_secret'] ) && false === strpos( wp_json_encode( $p ), 'RIGSECRETTAIL' ) );
$job = get_transient( npmp_brevo_job_key( $p['file_token'] ) );
$ok( 'the saved job holds a sealed key', is_array( $job ) && ! empty( $job['box'] ) && false === strpos( serialize( $job ), 'RIGSECRETTAIL' ) );
$ok( 'and the secret opens it', $good_key === npmp_brevo_unseal( $job['box'], $p['brevo_secret'] ) );

echo "Import, with a 429 and a timeout on the way\n";
$mapping = array();
foreach ( $p['auto_map'] as $i => $f ) {
	$mapping[ $i ] = (string) $f;
}
$mapping[7] = 'cf_new';  // RIG_CHAPTER.
$mapping[8] = 'cf_new';  // RIG_VOLUNTEER.
$mapping[9] = 'cf_new';  // RIG_JOINED.
$mapping[1] = '';        // The owner skips Status. Brevo's blocklist still decides it.
$labels     = array();
$types      = array();
foreach ( array( 7, 8, 9 ) as $i ) {
	$labels[ $i ] = $p['extra']['columns'][ $i ]['label'];
	$types[ $i ]  = $p['extra']['columns'][ $i ]['type'];
}
$base = array(
	'source'             => 'brevo',
	'job_token'          => $p['file_token'],
	'mapping'            => $mapping,
	'duplicate_handling' => 'update',
	'default_level'      => '',
	'default_status'     => 'subscribed',
	'import_source_tag'  => 'brevo',
	'extra_label'        => $labels,
	'extra_type'         => $types,
	'brevo_secret'       => $p['brevo_secret'],
);
$steps = array();
for ( $n = 0; $n < 12; $n++ ) {
	$r       = $ajax( 'npmp_import_ajax_step', $base );
	$steps[] = $r;
	if ( ! empty( $r['data']['done'] ) || ( false === $r['success'] && 'retry' !== ( $r['data']['code'] ?? '' ) ) ) {
		break;
	}
}
$codes = array_map(
	function ( $s ) {
		return $s['success'] ? ( $s['data']['done'] ? 'done' : 'page' ) : $s['data']['code'];
	},
	$steps
);
$ok( 'page, wait, page, timeout, done', array( 'page', 'retry', 'page', 'retry', 'done' ) === $codes, $codes );
$ok( 'the wait says how long', 3 === (int) ( $steps[1]['data']['retry_after'] ?? 0 ) && false !== strpos( $steps[1]['data']['message'], 'slow down' ), $steps[1]['data'] ?? '' );
$ok( 'the timeout says it can resume', false !== strpos( $steps[3]['data']['message'] ?? '', 'resume' ) );
$done  = end( $steps );
$stats = $done['data']['stats'] ?? array();
$ok( '68 new, the two you had updated', 68 === (int) ( $stats['imported'] ?? -1 ) && 2 === (int) ( $stats['updated'] ?? -1 ), $stats );
$ok( 'every page asked once after its retry', 3 === count( preg_grep( '#/lists/7/contacts\?limit=25#', $calls ) ) - 2, array_values( preg_grep( '#/lists/7/contacts#', $calls ) ) );
$ok( 'the job is gone', false === get_transient( npmp_brevo_job_key( $p['file_token'] ) ) && false === get_transient( 'npmp_import_job_' . $p['file_token'] ) );

$get = function ( $i ) use ( $members, $domain ) {
	return $members->get_member_by_email( sprintf( 'c%02d@%s', $i, $domain ) );
};
$ok( 'subscribed comes over subscribed', 'subscribed' === $get( 2 )->status, $get( 2 )->status );
$ok( 'blocklisted comes over unsubscribed', 'unsubscribed' === $get( 20 )->status, $get( 20 )->status );
$ok( 'blocklisted turns your subscribed contact off', 'unsubscribed' === $get( 10 )->status, $get( 10 )->status );
$ok( 'left list 7 comes over unsubscribed', 'unsubscribed' === $get( 7 )->status && 'unsubscribed' === $get( 14 )->status );
$ok( 'your unsubscribe stays though Brevo says subscribed', 'unsubscribed' === $get( 1 )->status, $get( 1 )->status );
$unsub = 0;
for ( $i = 1; $i <= 70; $i++ ) {
	$unsub += 'unsubscribed' === $get( $i )->status ? 1 : 0;
}
$ok( '17 unsubscribed: 7 blocklisted, 9 left the list, and yours', 17 === $unsub, $unsub );
$ok( 'names', 'First3 Last3' === $get( 3 )->name, $get( 3 )->name );
$ok( 'phone', '+15550000003' === preg_replace( '/[^+0-9]/', '', (string) $get( 3 )->phone ), $get( 3 )->phone );
$ok( 'Added is Brevo\'s createdAt', '2018-01-03' === gmdate( 'Y-m-d', strtotime( get_post( (int) $get( 3 )->id )->post_date_gmt . ' UTC' ) ), get_post( (int) $get( 3 )->id )->post_date_gmt );
$ok( 'no email sent', 0 === $mails, $mails );

if ( $groups ) {
	$names = wp_get_object_terms( (int) $get( 3 )->id, NPMP_PRO_GROUP_TAXONOMY, array( 'fields' => 'names' ) );
	sort( $names );
	$ok( 'two lists, two groups', array( 'Rig Board', 'Rig Volunteers' ) === $names, $names );
	$ok( 'a blocklisted contact joins no group', array() === wp_get_object_terms( (int) $get( 20 )->id, NPMP_PRO_GROUP_TAXONOMY, array( 'fields' => 'names' ) ) );
}
if ( $pro ) {
	$manager = NPMP_Custom_Fields_Manager::get_instance();
	$fields  = array();
	foreach ( $manager->get_field_definitions( 'member' ) as $f ) {
		$fields[ $f['label'] ] = $f;
	}
	$ok( 'three fields made', isset( $fields[ $labels[7] ], $fields[ $labels[8] ], $fields[ $labels[9] ] ), array_keys( $fields ) );
	$ok( 'Rig Chapter is a dropdown of the labels', 'dropdown' === ( $fields[ $labels[7] ]['type'] ?? '' ), $fields[ $labels[7] ] ?? '' );
	$v3 = $manager->get_field_values( 'member', (int) $get( 3 )->id );
	$ok( 'category label written', 'South' === ( $v3[ $fields[ $labels[7] ]['id'] ] ?? '' ), $v3 );
	$ok( 'date written', '2019-02-04' === ( $v3[ $fields[ $labels[9] ]['id'] ] ?? '' ), $v3 );
	$v5 = $manager->get_field_values( 'member', (int) $get( 5 )->id );
	$ok( 'the bad date is skipped', '' === (string) ( $v5[ $fields[ $labels[9] ]['id'] ] ?? '' ), $v5 );
	$ok( 'and counted', (bool) preg_grep( '/1 .*skipped|skipped 1/i', (array) ( $stats['extra_lines'] ?? array() ) ), $stats['extra_lines'] ?? '' );
} else {
	$ok( 'without Pro no custom field is made', get_option( 'npmp_pro_import_made_fields', null ) === $saved_options['npmp_pro_import_made_fields'] );
}

echo "Rerun\n";
$again = $ajax( 'npmp_import_ajax_preview', array( 'source' => 'brevo', 'brevo_api_key' => $good_key, 'brevo_list_id' => '7' ) );
$base2 = array_merge( $base, array( 'job_token' => $again['data']['file_token'], 'brevo_secret' => $again['data']['brevo_secret'] ) );
for ( $n = 0; $n < 6; $n++ ) {
	$r = $ajax( 'npmp_import_ajax_step', $base2 );
	if ( ! empty( $r['data']['done'] ) || ! $r['success'] ) {
		break;
	}
}
$ok( 'a second run makes no new contact', 0 === (int) ( $r['data']['stats']['imported'] ?? -1 ) && 70 === (int) ( $r['data']['stats']['updated'] ?? -1 ), $r['data']['stats'] ?? $r );
$rig_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $domain ) ) );
$ok( '70 contacts, no duplicates', 70 === count( $rig_ids ), count( $rig_ids ) );
if ( $pro ) {
	$count = 0;
	foreach ( NPMP_Custom_Fields_Manager::get_instance()->get_field_definitions( 'member' ) as $f ) {
		$count += in_array( $f['label'], $labels, true ) ? 1 : 0;
	}
	$ok( 'and no second copy of a field', 3 === $count, $count );
}

echo "A job that stops\n";
$stop = $ajax( 'npmp_import_ajax_preview', array( 'source' => 'brevo', 'brevo_api_key' => $good_key, 'brevo_list_id' => '7' ) );
$r    = $ajax( 'npmp_import_ajax_step', array_merge( $base, array( 'job_token' => $stop['data']['file_token'], 'brevo_secret' => str_repeat( '0', 64 ) ) ) );
$ok( 'a wrong secret stops the job', false === $r['success'] && 'stop' === $r['data']['code'], $r );
$ok( 'and keeps nothing', false === get_transient( npmp_brevo_job_key( $stop['data']['file_token'] ) ) );

echo "The key\n";
$where = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s OR option_name LIKE %s", '%RIGSECRETTAIL%', '%RIGSECRETTAIL%' ) );
$ok( 'in no option or transient', 0 === $where, $where );
$ok( 'in no answer', false === strpos( $answers, 'RIGSECRETTAIL' ) );
$left = 0;
foreach ( array( $p['file_token'], $again['data']['file_token'], $stop['data']['file_token'] ) as $t ) {
	$left += false === get_transient( npmp_brevo_job_key( $t ) ) && false === get_transient( 'npmp_import_job_' . $t ) ? 0 : 1;
}
$ok( 'no Brevo job left behind', 0 === $left, $left );

// ---------------------------------------------------------------------
// Put the site back
// ---------------------------------------------------------------------
foreach ( $rig_ids as $id ) {
	if ( ! in_array( $id, $contacts_before, true ) ) {
		wp_delete_post( $id, true );
	}
}
if ( $groups ) {
	foreach ( get_terms( array( 'taxonomy' => NPMP_PRO_GROUP_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) ) as $tid ) {
		if ( ! in_array( (int) $tid, $terms_before, true ) ) {
			wp_delete_term( (int) $tid, NPMP_PRO_GROUP_TAXONOMY );
		}
	}
}
foreach ( $saved_options as $name => $value ) {
	if ( null === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value );
	}
}
delete_option( 'npmp_import_extra_counts_wizard' );
$ok( 'site put back', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'npmp_email' AND meta_value LIKE %s", '%@' . $domain ) ) );

printf( "\n%s: %d failed\n", $fails ? 'FAILED' : 'OK', $fails );
