<?php
/**
 * Brevo import: the pure parts (includes/import/brevo-api.php), with a fake
 * wp_remote_get so the client's error handling runs too.
 *
 * Run: php tests/test-brevo-api.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( '_n' ) ) {
	function _n( $one, $many, $n, $domain = null ) {
		return 1 === (int) $n ? $one : $many;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
$GLOBALS['fake'] = array();
$GLOBALS['sent'] = array();
function wp_remote_get( $url, $args ) {
	$GLOBALS['sent'][] = array( $url, $args );
	return array_shift( $GLOBALS['fake'] );
}
function wp_remote_retrieve_response_code( $r ) {
	return $r['code'];
}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];
}
function wp_remote_retrieve_header( $r, $h ) {
	return $r['headers'][ $h ] ?? '';
}

require_once dirname( __DIR__ ) . '/includes/import/brevo-api.php';

$key = 'xkeysib-' . str_repeat( 'a1', 30 ) . '-SECRETTAIL';

echo "Errors\n";
foreach ( array(
	array( 401, 'Key not found', 'npmp_brevo_key', false ),
	array( 401, 'Unable to authenticate: unrecognised IP address 1.2.3.4', 'npmp_brevo_ip', false ),
	array( 403, 'permission_denied', 'npmp_brevo_permission', false ),
	array( 404, '', 'npmp_brevo_missing', false ),
	array( 429, '', 'npmp_brevo_rate', true ),
	array( 503, '', 'npmp_brevo_server', true ),
	array( 0, '', 'npmp_brevo_timeout', true ),
	array( 418, '', 'npmp_brevo_error', false ),
) as $case ) {
	$e = npmp_brevo_error( $case[0], $case[1], 30 );
	check( "{$case[0]} {$case[1]} code", $case[2], $e['code'] );
	check( "{$case[0]} resumes", $case[3], $e['retry'] );
	check( "{$case[0]} message never echoes Brevo", false, '' !== $case[1] && false !== strpos( $e['message'], $case[1] ) );
}
check( '429 waits as long as Brevo says', 45, npmp_brevo_error( 429, '', 45 )['retry_after'] );
check( '429 with no header waits a second', 1, npmp_brevo_error( 429 )['retry_after'] );

echo "\nClient\n";
$GLOBALS['fake'] = array( array( 'code' => 401, 'body' => '{"code":"unauthorized","message":"Key not found"}', 'headers' => array() ) );
$r               = npmp_brevo_get( $key, '/contacts/lists' );
check( 'wrong key is an error', 'npmp_brevo_key', $r->get_error_code() );
check( 'the key went only in the header', array( $key, false ), array( $GLOBALS['sent'][0][1]['headers']['api-key'], strpos( $GLOBALS['sent'][0][0], 'SECRETTAIL' ) ) );
check( 'no redirects, so the header never leaves Brevo', 0, $GLOBALS['sent'][0][1]['redirection'] );
check( 'the error never carries the key', false, strpos( serialize( $r ), 'SECRETTAIL' ) );
$GLOBALS['fake'] = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20000 ms for https://api.brevo.com/v3/contacts' ) );
$r               = npmp_brevo_get( $key, '/contacts' );
check( 'timeout', array( 'npmp_brevo_timeout', true ), array( $r->get_error_code(), $r->get_error_data()['retry'] ) );
check( 'timeout message is ours', false, strpos( $r->get_error_message(), 'cURL' ) );
$GLOBALS['fake'] = array( array( 'code' => 429, 'body' => '{"message":"Too many requests"}', 'headers' => array( 'x-sib-ratelimit-reset' => '12' ) ) );
check( 'rate limit reads the reset header', 12, npmp_brevo_get( $key, '/contacts' )->get_error_data()['retry_after'] );
$GLOBALS['fake'] = array( array( 'code' => 200, 'body' => 'not json', 'headers' => array() ) );
check( 'a garbled answer resumes', true, npmp_brevo_get( $key, '/contacts' )->get_error_data()['retry'] );
check( 'an empty key never reaches Brevo', array( 'npmp_brevo_key', 4 ), array( npmp_brevo_get( '  ', '/contacts' )->get_error_code(), count( $GLOBALS['sent'] ) ) );

$GLOBALS['sent'] = array();
$GLOBALS['fake'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'count' => 51, 'lists' => array_map( function ( $i ) {
		return array( 'id' => $i, 'name' => "L$i", 'uniqueSubscribers' => $i );
	}, range( 1, 50 ) ) ) ), 'headers' => array() ),
	array( 'code' => 200, 'body' => json_encode( array( 'count' => 51, 'lists' => array( array( 'id' => 51, 'name' => 'Last', 'totalSubscribers' => 9 ) ) ) ), 'headers' => array() ),
);
$lists = npmp_brevo_get_lists( $key );
check( 'lists page through 50 at a time', array( 51, 2, 'Last', 9 ), array( count( $lists ), count( $GLOBALS['sent'] ), $lists[50]['name'], $lists[50]['member_count'] ) );
check( 'second page asks from offset 50', true, false !== strpos( $GLOBALS['sent'][1][0], 'offset=50' ) );

$GLOBALS['sent'] = array();
$GLOBALS['fake'] = array( array( 'code' => 200, 'body' => '{"contacts":[],"count":0}', 'headers' => array() ) );
npmp_brevo_get_contacts( $key, 7, 100, 9999 );
check( 'list contacts: path, oldest first, 500 at most', true, (bool) preg_match( '#/v3/contacts/lists/7/contacts\?limit=500&offset=100&sort=asc$#', $GLOBALS['sent'][0][0] ) );
$GLOBALS['fake'] = array( array( 'code' => 200, 'body' => '{"contacts":[],"count":0}', 'headers' => array() ) );
npmp_brevo_get_contacts( $key, 0, 0, 100 );
check( 'every contact uses /contacts', true, (bool) preg_match( '#/v3/contacts\?limit=100&offset=0&sort=asc$#', $GLOBALS['sent'][1][0] ) );

echo "\nAttributes\n";
$attrs = npmp_brevo_pick_attributes(
	array(
		array( 'name' => 'FIRSTNAME', 'category' => 'normal', 'type' => 'text' ),
		array( 'name' => 'lastname', 'category' => 'normal', 'type' => 'text' ),
		array( 'name' => 'SMS', 'category' => 'normal', 'type' => 'text' ),
		array( 'name' => 'BIRTHDAY', 'category' => 'normal', 'type' => 'date' ),
		array( 'name' => 'DUES_PAID', 'category' => 'normal', 'type' => 'float' ),
		array( 'name' => 'VOLUNTEER', 'category' => 'normal', 'type' => 'boolean' ),
		array( 'name' => 'SKILLS', 'category' => 'normal', 'type' => 'multiple-choice', 'multiCategoryOptions' => array( 'Cooking', 'Driving', '' ) ),
		array( 'name' => 'CHAPTER', 'category' => 'category', 'type' => 'category', 'enumeration' => array( array( 'value' => 1, 'label' => 'North', 'valueStr' => '1' ), array( 'value' => 2, 'label' => 'South', 'valueStr' => '2' ) ) ),
		array( 'name' => 'ORDER_ID', 'category' => 'transactional', 'type' => 'id' ),
		array( 'name' => 'SCORE', 'category' => 'calculated', 'calculatedValue' => 'x' ),
		array( 'name' => 'BRAND', 'category' => 'global' ),
	)
);
check( 'transactional, calculated, and global stay behind', array( 'FIRSTNAME', 'LASTNAME', 'SMS', 'BIRTHDAY', 'DUES_PAID', 'VOLUNTEER', 'SKILLS', 'CHAPTER' ), array_column( $attrs, 'name' ) );
$byname = array_column( $attrs, null, 'name' );
check( 'date', 'date', npmp_brevo_attribute_shape( $byname['BIRTHDAY'] )['type'] );
check( 'float is a number', 'number', npmp_brevo_attribute_shape( $byname['DUES_PAID'] )['type'] );
check( 'boolean is one box', array( 'checkbox', array() ), array_values( array_slice( npmp_brevo_attribute_shape( $byname['VOLUNTEER'] ), 0, 2 ) ) );
check( 'multiple choice keeps its choices', array( 'checkbox', array( 'Cooking', 'Driving' ) ), array_values( array_slice( npmp_brevo_attribute_shape( $byname['SKILLS'] ), 0, 2 ) ) );
check( 'category is a dropdown of labels', array( 'dropdown', array( 'North', 'South' ) ), array_values( array_slice( npmp_brevo_attribute_shape( $byname['CHAPTER'] ), 0, 2 ) ) );
check( 'label reads like a name', 'Dues Paid', npmp_brevo_attribute_shape( $byname['DUES_PAID'] )['label'] );

$cols = npmp_brevo_columns( $attrs, true );
check( 'columns', array( 'email', 'status', 'created_at', 'lists', 'attr:FIRSTNAME', 'attr:LASTNAME', 'attr:SMS', 'attr:BIRTHDAY' ), array_slice( $cols['column_order'], 0, 8 ) );
check( 'names and phone map themselves, lists go to groups with Pro', array( 'email', 'status', 'created_at', 'groups', 'first_name', 'last_name', 'phone', '' ), array_slice( $cols['auto_map'], 0, 8 ) );
check( 'only the others become custom fields', array( 7, 8, 9, 10, 11 ), array_keys( $cols['extra_known'] ) );
check( 'without Pro the Lists column waits for the owner', '', npmp_brevo_columns( $attrs, false )['auto_map'][3] );

echo "\nContacts\n";
$names = array( 7 => 'Board', 8 => 'Volunteers, North', 9 => 'Old' );
$c     = array(
	'email'            => 'ann@example.org',
	'id'               => 3,
	'emailBlacklisted' => false,
	'createdAt'        => '2019-04-02T10:11:12.000+02:00',
	'listIds'          => array( 7, 8, 9 ),
	'listUnsubscribed' => array( 9 ),
	'attributes'       => array( 'FIRSTNAME' => 'Ann', 'lastname' => 'Lee', 'CHAPTER' => 2, 'VOLUNTEER' => true, 'SKILLS' => array( 'Driving' ), 'DUES_PAID' => 12.5 ),
);
$row   = npmp_brevo_contact_row( $c, 7, $attrs, $names );
check( 'subscribed', 'subscribed', $row['status'] );
check( 'Added is Brevo\'s createdAt', '2019-04-02T10:11:12.000+02:00', $row['created_at'] );
check( 'lists the contact left stay behind, commas never split a name', 'Board,Volunteers  North', $row['lists'] );
check( 'names, case-blind', array( 'Ann', 'Lee' ), array( $row['attr:FIRSTNAME'], $row['attr:LASTNAME'] ) );
check( 'category value becomes its label', 'South', $row['attr:CHAPTER'] );
check( 'boolean', 'yes', $row['attr:VOLUNTEER'] );
check( 'multiple choice stays a list', array( 'Driving' ), $row['attr:SKILLS'] );
check( 'missing attribute is empty', '', $row['attr:BIRTHDAY'] );
check( 'blocklisted is unsubscribed', 'unsubscribed', npmp_brevo_status( array( 'emailBlacklisted' => true ), 0 ) );
check( 'left the list being imported is unsubscribed', 'unsubscribed', npmp_brevo_status( array( 'emailBlacklisted' => false, 'listUnsubscribed' => array( 7 ) ), 7 ) );
check( 'left another list is still subscribed', 'subscribed', npmp_brevo_status( array( 'listUnsubscribed' => array( 9 ) ), 7 ) );
check( 'blocklisted never comes over as subscribed, any list', array( 'unsubscribed' ), array_values( array_unique( array_map( function ( $l ) {
	return npmp_brevo_status( array( 'emailBlacklisted' => true, 'listUnsubscribed' => array() ), $l );
}, array( 0, 7, 8 ) ) ) ) );
check( 'a blocklisted contact joins no group', '', npmp_brevo_contact_row( array_merge( $c, array( 'emailBlacklisted' => true ) ), 7, $attrs, $names )['lists'] );
check( 'preview cells', array( 'ann@example.org', 'subscribed' ), array_slice( npmp_brevo_preview_cells( $row, $cols['column_order'] ), 0, 2 ) );
check( 'preview joins a list value', 'Driving', npmp_brevo_preview_cells( $row, $cols['column_order'] )[10] );

echo "\nThe key between requests\n";
$sealed = npmp_brevo_seal( $key );
check( 'the box never holds the key in the clear', false, strpos( sodium_hex2bin( $sealed['box'] ), 'SECRETTAIL' ) );
check( 'the right secret opens it', $key, npmp_brevo_unseal( $sealed['box'], $sealed['secret'] ) );
check( 'another secret opens nothing', '', npmp_brevo_unseal( $sealed['box'], npmp_brevo_seal( $key )['secret'] ) );
check( 'junk opens nothing', '', npmp_brevo_unseal( 'zz', 'zz' ) );
check( 'a fresh box each time', true, npmp_brevo_seal( $key )['box'] !== $sealed['box'] );

echo "\nWiring\n";
$admin = file_get_contents( dirname( __DIR__ ) . '/includes/import/admin-import.php' );
check( 'the step forces the status column', 1, substr_count( $admin, "\$named_map['status'] = 'status';" ) );
check( 'the key is never put in a transient as is', 0, preg_match( "/'api_key'\s*=>\s*\\\$api_key[^\n]*\n[^\n]*brevo/i", $admin ) );
check( 'nothing in the Brevo code logs', 0, preg_match( '/error_log|wp_debug_log|var_export|print_r/', file_get_contents( dirname( __DIR__ ) . '/includes/import/brevo-api.php' ) ) );
check( 'the job transient lasts 15 minutes', 900, NPMP_BREVO_JOB_TTL );
// esc_js() turns " into &quot;, and .text() then shows the entity on screen.
preg_match_all( "/esc_js\(\s*__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $admin, $m );
check( 'no wizard message shows &quot; on screen', array(), array_values( array_filter( $m[1], function ( $s ) {
	return false !== strpos( $s, '"' );
} ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
