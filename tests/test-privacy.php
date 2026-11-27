<?php
/**
 * WordPress's personal data tools: the exporters and the eraser
 * (includes/npmp-privacy.php).
 *
 * Runs the real rules on an in-memory store that stands in for
 * NPMP_Privacy_Store. The real store's SQL runs in the rig,
 * tests/rig/privacy.php, on a real WordPress with Pro off and on.
 *
 * Run: php tests/test-privacy.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'NPMP_PRIVACY_PAGE_SIZE', 3 );

$GLOBALS['t_filters'] = array();
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $tag ][] = array( $cb, $priority );
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $tag ][] = array( $cb, $priority );
}
$GLOBALS['t_holds']       = array();
$GLOBALS['t_keep_unsub']  = true;
$GLOBALS['t_notes']       = true;
$GLOBALS['t_pro_eraser']  = false;
function apply_filters( $tag, $value, ...$args ) {
	if ( 'npmp_privacy_erase_holds' === $tag ) {
		if ( ! empty( $GLOBALS['t_holds_clear'] ) ) {
			return array();
		}
		return array_merge( (array) $value, $GLOBALS['t_holds'] );
	}
	if ( 'npmp_privacy_keep_unsubscribed' === $tag ) {
		return $GLOBALS['t_keep_unsub'];
	}
	if ( 'npmp_privacy_export_notes' === $tag ) {
		return $GLOBALS['t_notes'];
	}
	if ( 'wp_privacy_personal_data_erasers' === $tag && $GLOBALS['t_pro_eraser'] ) {
		$value['nonprofit-manager-pro'] = array( 'callback' => 'npmp_pro_privacy_erase' );
	}
	return $value;
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		static function ( $row ) use ( $field ) {
			return is_array( $row ) ? $row[ $field ] : $row->$field;
		},
		(array) $list
	);
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function admin_url( $path = '' ) {
	return 'https://site.test/wp-admin/' . $path;
}
function esc_url( $url ) {
	return str_replace( '&', '&#038;', (string) $url );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}

/** In-memory stand-in for NPMP_Privacy_Store, same methods and answers. */
class T_Store {
	public $contacts = array(); // id => [email, status, post_status, title, meta]
	public $gifts    = array(); // id => [email, amount, currency, frequency, gateway, date, title, erased]
	public $queue    = array(); // [email, status]
	public $tracking = array(); // [contact_id, kind]
	public $legacy   = array( 'members' => array(), 'donations' => array() );
	public $plans    = array(); // [member_id, status, frequency]
	public $leftover = 0;       // Pro rows only Pro's eraser clears

	public function plans( array $ids ) {
		return array_values(
			array_filter(
				$this->plans,
				static function ( $p ) use ( $ids ) {
					return in_array( $p['member_id'], $ids, true );
				}
			)
		);
	}
	public function pro_leftovers( $email, array $ids ) {
		return $ids ? $this->leftover : 0;
	}
	public function legacy_rows( $email ) {
		$out = array();
		foreach ( $this->legacy['members'] as $m ) {
			if ( strtolower( $m ) === strtolower( $email ) ) {
				$out['npmp_members'][] = array( 'email' => $m, 'first_name' => 'Old' );
			}
		}
		foreach ( $this->legacy['donations'] as $d ) {
			if ( '' !== $d && strtolower( $d ) === strtolower( $email ) ) {
				$out['npmp_donations'][] = array( 'email' => $d, 'amount' => '9.00' );
			}
		}
		return $out;
	}

	public function contacts( $email ) {
		$out = array();
		ksort( $this->contacts );
		foreach ( $this->contacts as $id => $c ) {
			if ( strtolower( $c['email'] ) === strtolower( $email ) ) {
				$out[] = array(
					'id'          => $id,
					'status'      => $c['status'],
					'post_status' => $c['post_status'],
				);
			}
		}
		return $out;
	}
	public function contact_record( $id ) {
		$c = $this->contacts[ $id ] ?? null;
		if ( ! $c ) {
			return null;
		}
		return array(
			'title'    => $c['title'],
			'content'  => '',
			'date'     => '2026-01-02 03:04:05',
			'modified' => '2026-02-03 04:05:06',
			'meta'     => array_merge(
				array(
					'npmp_email'  => $c['email'],
					'npmp_status' => $c['status'],
				),
				$c['meta']
			),
		);
	}
	public function delete_contact( $id, $email ) {
		if ( ! isset( $this->contacts[ $id ] ) ) {
			return false;
		}
		unset( $this->contacts[ $id ] );
		return true;
	}
	public function strip_contact( $id, $email ) {
		$c       = $this->contacts[ $id ];
		$changed = ! empty( $c['meta'] ) || $c['title'] !== $c['email'];
		$this->contacts[ $id ]['meta']  = array();
		$this->contacts[ $id ]['title'] = $c['email'];
		return $changed;
	}
	public function gifts( $email, $limit, $offset ) {
		$rows = array();
		ksort( $this->gifts );
		foreach ( $this->gifts as $id => $g ) {
			if ( '' !== $g['email'] && strtolower( $g['email'] ) === strtolower( $email ) ) {
				$rows[] = array(
					'id'        => $id,
					'date'      => $g['date'],
					'amount'    => $g['amount'],
					'currency'  => $g['currency'],
					'frequency' => $g['frequency'],
					'gateway'   => $g['gateway'],
				);
			}
		}
		return array_slice( $rows, $offset, $limit );
	}
	public function gift_ids_to_strip( $email, $limit ) {
		return wp_list_pluck( $this->gifts( $email, $limit, 0 ), 'id' );
	}
	public function strip_gift( $id ) {
		$this->gifts[ $id ]['email']  = '';
		$this->gifts[ $id ]['title']  = 'Erased on request';
		$this->gifts[ $id ]['erased'] = true;
	}
	public function count_sent( $email ) {
		$n = 0;
		foreach ( $this->queue as $q ) {
			$n += ( 'sent' === $q['status'] && strtolower( $q['email'] ) === strtolower( $email ) ) ? 1 : 0;
		}
		return $n;
	}
	public function delete_queue( $email ) {
		$before      = count( $this->queue );
		$this->queue = array_values(
			array_filter(
				$this->queue,
				static function ( $q ) use ( $email ) {
					return strtolower( $q['email'] ) !== strtolower( $email );
				}
			)
		);
		return $before - count( $this->queue );
	}
	public function count_tracking( array $ids ) {
		$out = array(
			'opens'  => 0,
			'clicks' => 0,
		);
		foreach ( $this->tracking as $t ) {
			if ( in_array( $t[0], $ids, true ) ) {
				$out[ $t[1] ]++;
			}
		}
		return $out;
	}
	public function delete_tracking( array $ids ) {
		$before         = count( $this->tracking );
		$this->tracking = array_values(
			array_filter(
				$this->tracking,
				static function ( $t ) use ( $ids ) {
					return ! in_array( $t[0], $ids, true );
				}
			)
		);
		return $before - count( $this->tracking );
	}
	public function erase_legacy( $email ) {
		$out = array(
			'removed'  => 0,
			'retained' => 0,
		);
		foreach ( $this->legacy['members'] as $i => $m ) {
			if ( strtolower( $m ) === strtolower( $email ) ) {
				unset( $this->legacy['members'][ $i ] );
				$out['removed']++;
			}
		}
		foreach ( $this->legacy['donations'] as $i => $d ) {
			if ( strtolower( $d ) === strtolower( $email ) ) {
				$this->legacy['donations'][ $i ] = '';
				$out['retained']++;
			}
		}
		return $out;
	}
	public function snapshot() {
		return json_encode( array( $this->contacts, $this->gifts, $this->queue, $this->tracking, $this->legacy ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/npmp-privacy.php';

/**
 * A fresh store with one site's worth of people.
 *
 * @return T_Store
 */
function t_seed() {
	$s           = new T_Store();
	$s->contacts = array(
		10 => array(
			'email'       => 'plain@example.org',
			'status'      => 'subscribed',
			'post_status' => 'publish',
			'title'       => 'Pat Plain',
			'meta'        => array(
				'npmp_city'            => 'Leeds',
				'npmp_tags'            => 'choir',
				'_npmp_weekly_digest'  => '1',
				'_npmp_signup_confirm' => array( 'key' => 'secret-key' ),
			),
		),
		20 => array(
			'email'       => 'Donor@Example.org',
			'status'      => 'donor',
			'post_status' => 'publish',
			'title'       => 'Dee Donor',
			'meta'        => array( 'npmp_donation_total' => '70', 'npmp_donation_currency' => 'GBP' ),
		),
		30 => array(
			'email'       => 'gone@example.org',
			'status'      => 'unsubscribed',
			'post_status' => 'publish',
			'title'       => 'Una Gone',
			'meta'        => array( 'npmp_phone' => '0113 000', 'npmp_notes' => '<b>Asked to stop</b>' ),
		),
		40 => array(
			'email'       => 'other@example.org',
			'status'      => 'subscribed',
			'post_status' => 'publish',
			'title'       => 'Otto Other',
			'meta'        => array(),
		),
	);
	for ( $i = 1; $i <= 7; $i++ ) {
		$s->gifts[ 100 + $i ] = array(
			'email'     => 1 === $i % 2 ? 'donor@example.org' : 'DONOR@example.org',
			'amount'    => (string) ( 10 * $i ),
			'currency'  => 'GBP',
			'frequency' => 'one_time',
			'gateway'   => 'stripe',
			'date'      => '2026-0' . $i . '-01 10:00:00',
			'title'     => 'Dee Donor (donor@example.org)',
			'erased'    => false,
		);
	}
	$s->gifts[200]     = array(
		'email'     => 'other@example.org',
		'amount'    => '5',
		'currency'  => 'GBP',
		'frequency' => 'monthly',
		'gateway'   => 'stripe',
		'date'      => '2026-01-01 10:00:00',
		'title'     => 'Otto (other@example.org)',
		'erased'    => false,
	);
	$s->queue          = array(
		array( 'email' => 'plain@example.org', 'status' => 'pending' ),
		array( 'email' => 'Plain@Example.org', 'status' => 'sent' ),
		array( 'email' => 'other@example.org', 'status' => 'pending' ),
		array( 'email' => 'donor@example.org', 'status' => 'sent' ),
	);
	$s->tracking       = array( array( 10, 'opens' ), array( 10, 'clicks' ), array( 40, 'opens' ) );
	$s->legacy         = array(
		'members'   => array( 'plain@example.org', 'other@example.org' ),
		'donations' => array( 'donor@example.org', 'other@example.org' ),
	);
	npmp_privacy_store( $s );
	$GLOBALS['t_holds']      = array();
	$GLOBALS['t_keep_unsub'] = true;
	$GLOBALS['t_notes']      = true;
	$GLOBALS['t_pro_eraser'] = false;
	return $s;
}

/**
 * Run the eraser the way core does: page 1, 2, ... until done.
 *
 * @param string $email Address.
 * @return array{pages:int,removed:bool,retained:bool,messages:array,shape:bool}
 */
function t_erase_all( $email ) {
	$out  = array(
		'pages'    => 0,
		'removed'  => false,
		'retained' => false,
		'messages' => array(),
		'shape'    => true,
	);
	$page = 1;
	npmp_privacy_pro_eraser_present( true );
	do {
		$r = npmp_privacy_erase( $email, $page );
		$out['shape'] = $out['shape'] && is_bool( $r['items_removed'] ) && is_bool( $r['items_retained'] ) && is_array( $r['messages'] ) && is_bool( $r['done'] );
		$out['pages']++;
		$out['removed']  = $out['removed'] || $r['items_removed'];
		$out['retained'] = $out['retained'] || $r['items_retained'];
		$out['messages'] = array_merge( $out['messages'], $r['messages'] );
		$page++;
	} while ( ! $r['done'] && $page < 50 );
	return $out;
}

echo "Registration\n";
$exporters = npmp_privacy_register_exporters( array( 'core' => array() ) );
check( 'contact exporter registered', 'npmp_privacy_export_contact', $exporters['nonprofit-manager-contact']['callback'] ?? '' );
check( 'gift exporter registered', 'npmp_privacy_export_gifts', $exporters['nonprofit-manager-gifts']['callback'] ?? '' );
check( 'core exporters kept', true, isset( $exporters['core'] ) );
$erasers = npmp_privacy_register_erasers( array() );
check( 'eraser registered', 'npmp_privacy_erase', $erasers['nonprofit-manager']['callback'] ?? '' );
check( 'eraser has a friendly name', true, '' !== ( $erasers['nonprofit-manager']['eraser_friendly_name'] ?? '' ) );
check( 'exporters hooked on core filter', true, isset( $GLOBALS['t_filters']['wp_privacy_personal_data_exporters'] ) );
check( 'erasers hooked on core filter', true, isset( $GLOBALS['t_filters']['wp_privacy_personal_data_erasers'] ) );
check( 'policy text hooked on admin_init', true, isset( $GLOBALS['t_filters']['admin_init'] ) );

echo "\nExport: contact\n";
$s = t_seed();
$r = npmp_privacy_export_contact( 'PLAIN@example.org', 1 );
check( 'export done in one page', true, $r['done'] );
$groups = array_count_values( wp_list_pluck( $r['data'], 'group_id' ) );
check( 'one contact item', 1, $groups['npmp-contact'] ?? 0 );
check( 'subscription item plus activity item', 2, $groups['npmp-subscription'] ?? 0 );
$shape_ok = true;
foreach ( $r['data'] as $item ) {
	$shape_ok = $shape_ok && isset( $item['group_id'], $item['group_label'], $item['item_id'], $item['data'] ) && is_array( $item['data'] );
	foreach ( $item['data'] as $pair ) {
		$shape_ok = $shape_ok && array( 'name', 'value' ) === array_keys( $pair ) && is_string( $pair['value'] ) && '' !== $pair['value'];
	}
}
check( 'every item has core\'s shape, no empty values', true, $shape_ok );
$flat = array();
foreach ( $r['data'] as $item ) {
	foreach ( $item['data'] as $pair ) {
		$flat[ $item['group_id'] . ':' . $pair['name'] ] = $pair['value'];
	}
}
check( 'name exported', 'Pat Plain', $flat['npmp-contact:Name'] ?? '' );
check( 'city exported', 'Leeds', $flat['npmp-contact:City'] ?? '' );
check( 'status exported as words', 'Active', $flat['npmp-contact:Status'] ?? '' );
check( 'digest choice exported', 'Yes', $flat['npmp-subscription:Weekly digest'] ?? '' );
check( 'pending confirmation shown as Yes', 'Yes', $flat['npmp-subscription:Waiting for email confirmation'] ?? '' );
check( 'confirmation key never exported', false, false !== strpos( json_encode( $r ), 'secret-key' ) );
check( 'newsletters sent counted', '1', $flat['npmp-subscription:Newsletters sent to you'] ?? '' );
check( 'opens counted', '1', $flat['npmp-subscription:Newsletter opens recorded'] ?? '' );
$notes = npmp_privacy_export_contact( 'gone@example.org', 1 );
check( 'notes exported without tags', true, false !== strpos( json_encode( $notes ), 'Asked to stop' ) && false === strpos( json_encode( $notes ), '<b>' ) );
$GLOBALS['t_notes'] = false;
$notes              = npmp_privacy_export_contact( 'gone@example.org', 1 );
check( 'notes filter set to false leaves Notes out', false, false !== strpos( json_encode( $notes ), 'Asked to stop' ) );
$GLOBALS['t_notes'] = true;
$legacy             = npmp_privacy_export_contact( 'plain@example.org', 1 );
$legacy_items       = array_values( array_filter( $legacy['data'], static function ( $i ) { return 'npmp-legacy' === $i['group_id']; } ) );
check( 'older members table row exported', 1, count( $legacy_items ) );
check( 'older row names its kind', true, false !== strpos( json_encode( $legacy_items ), 'Older contact record' ) );
check( 'older row carries its columns', true, false !== strpos( json_encode( $legacy_items ), '"First name","value":"Old"' ) );
$none = npmp_privacy_export_contact( 'nobody@example.org', 1 );
check( 'unknown email exports nothing', array(), $none['data'] );
check( 'unknown email done', true, $none['done'] );

echo "\nExport: gifts, paged\n";
$p1 = npmp_privacy_export_gifts( 'Donor@example.org', 1 );
$p2 = npmp_privacy_export_gifts( 'Donor@example.org', 2 );
$p3 = npmp_privacy_export_gifts( 'Donor@example.org', 3 );
check( 'page 1 holds 3 gifts', 3, count( $p1['data'] ) );
check( 'page 1 not done', false, $p1['done'] );
check( 'page 2 holds 3 gifts', 3, count( $p2['data'] ) );
check( 'page 3 holds the last gift', 1, count( $p3['data'] ) );
check( 'page 3 done', true, $p3['done'] );
check( 'pages never repeat a gift', 7, count( array_unique( array_merge( wp_list_pluck( $p1['data'], 'item_id' ), wp_list_pluck( $p2['data'], 'item_id' ), wp_list_pluck( $p3['data'], 'item_id' ) ) ) ) );
$g = array();
foreach ( $p1['data'][0]['data'] as $pair ) {
	$g[ $pair['name'] ] = $pair['value'];
}
check( 'gift amount', '10.00', $g['Amount'] ?? '' );
check( 'gift currency', 'GBP', $g['Currency'] ?? '' );
check( 'gift date', '2026-01-01 10:00:00', $g['Date'] ?? '' );
check( 'gift fields are date, amount, currency, frequency, method', array( 'Date', 'Amount', 'Currency', 'Frequency', 'Payment method' ), array_keys( $g ) );
check( 'other donor\'s gift not exported', false, false !== strpos( json_encode( array( $p1, $p2, $p3 ) ), 'npmp-gift-200' ) );
$exact = npmp_privacy_export_gifts( 'nobody@example.org', 1 );
check( 'no gifts: empty and done', array( array(), true ), array( $exact['data'], $exact['done'] ) );

echo "\nErase: plain contact\n";
$s = t_seed();
$r = t_erase_all( 'plain@example.org' );
check( 'answers in core\'s shape', true, $r['shape'] );
check( 'one page', 1, $r['pages'] );
check( 'items removed', true, $r['removed'] );
check( 'nothing retained', false, $r['retained'] );
check( 'contact deleted', false, isset( $s->contacts[10] ) );
check( 'queued and sent rows to the address deleted (any case)', 0, count( array_filter( $s->queue, static function ( $q ) { return 'plain@example.org' === strtolower( $q['email'] ); } ) ) );
check( 'other people\'s queue rows kept', 2, count( $s->queue ) );
check( 'opens and clicks deleted', array( 'opens' => 0, 'clicks' => 0 ), $s->count_tracking( array( 10 ) ) );
check( 'other contact\'s opens kept', 1, count( $s->tracking ) );
check( 'old members table row deleted', array( 'other@example.org' ), array_values( $s->legacy['members'] ) );
check( 'other contacts untouched', true, isset( $s->contacts[20], $s->contacts[30], $s->contacts[40] ) );

echo "\nErase: donor with gifts\n";
$s = t_seed();
$r = t_erase_all( 'donor@EXAMPLE.org' );
check( 'three pages for 7 gifts at 3 a page', 3, $r['pages'] );
check( 'items removed', true, $r['removed'] );
check( 'items retained', true, $r['retained'] );
check( 'one message', 1, count( $r['messages'] ) );
check( 'message says the gifts stay for accounting', true, false !== strpos( $r['messages'][0] ?? '', 'accounting records' ) );
$kept = 0;
foreach ( $s->gifts as $id => $gift ) {
	if ( $id > 100 && $id < 200 ) {
		$kept += ( '' === $gift['email'] && $gift['erased'] && 'Erased on request' === $gift['title'] && '' !== $gift['amount'] ) ? 1 : 0;
	}
}
check( 'all 7 gifts kept, stripped of name and email', 7, $kept );
check( 'gift amounts kept', '70', $s->gifts[107]['amount'] );
check( 'other donor\'s gift untouched', 'other@example.org', $s->gifts[200]['email'] );
check( 'donor contact deleted', false, isset( $s->contacts[20] ) );
check( 'old donations table row blanked', array( '', 'other@example.org' ), $s->legacy['donations'] );
check( 'gift export now finds nothing', array(), npmp_privacy_export_gifts( 'donor@example.org', 1 )['data'] );

echo "\nErase: unsubscribed contact\n";
$s = t_seed();
$r = t_erase_all( 'gone@example.org' );
check( 'contact kept', true, isset( $s->contacts[30] ) );
check( 'only email and status left', array( 'gone@example.org', 'unsubscribed', array(), 'gone@example.org' ), array( $s->contacts[30]['email'], $s->contacts[30]['status'], $s->contacts[30]['meta'], $s->contacts[30]['title'] ) );
check( 'removed and retained both reported', array( true, true ), array( $r['removed'], $r['retained'] ) );
check( 'message says the address stays unsubscribed', true, false !== strpos( implode( ' ', $r['messages'] ), 'Unsubscribed status' ) );
$s = t_seed();
$GLOBALS['t_keep_unsub'] = false;
$r = t_erase_all( 'gone@example.org' );
check( 'filter set to false deletes the contact', false, isset( $s->contacts[30] ) );
check( 'filter false: nothing retained', false, $r['retained'] );
$s = t_seed();
$s->contacts[30]['post_status'] = 'trash';
t_erase_all( 'gone@example.org' );
check( 'trashed unsubscribed contact deleted, not kept', false, isset( $s->contacts[30] ) );

echo "\nErase: active plan holds the person\n";
$s                  = t_seed();
$GLOBALS['t_holds'] = array( 'This person has an active dues or recurring plan. Cancel the plan first, then run this request again.' );
$before_gifts       = json_encode( $s->gifts );
$r                  = t_erase_all( 'donor@example.org' );
check( 'one page', 1, $r['pages'] );
check( 'retained', true, $r['retained'] );
check( 'hold message shown', $GLOBALS['t_holds'], $r['messages'] );
check( 'contact kept whole', 'Dee Donor', $s->contacts[20]['title'] ?? '' );
check( 'gifts untouched', $before_gifts, json_encode( $s->gifts ) );
check( 'old donations row untouched', 'donor@example.org', $s->legacy['donations'][0] );
check( 'queue rows still deleted', 3, count( $s->queue ) );
check( 'queue deletion reported as removed', true, $r['removed'] );

echo "\nErase: plan statuses\n";
$cases = array(
	'active'    => 'an active recurring plan',
	'failed'    => 'a failed payment that Stripe may still retry',
	'lapsed'    => 'a lapsed plan that Stripe may still retry',
	'paused'    => 'a paused plan that Stripe bills again if anyone resumes the plan',
	'cancelled' => '',
);
foreach ( $cases as $status => $words ) {
	$s          = t_seed();
	$s->plans[] = array( 'member_id' => 20, 'status' => $status, 'frequency' => 'monthly' );
	$r          = t_erase_all( 'donor@example.org' );
	if ( $words ) {
		check( "$status plan holds: contact kept whole", 'Dee Donor', $s->contacts[20]['title'] ?? '' );
		check( "$status plan holds: gifts untouched", 'donor@example.org', $s->gifts[101]['email'] );
		check( "$status plan: message words the status", true, false !== strpos( implode( ' ', $r['messages'] ), $words ) );
		check( "$status plan: Pro off sends the admin to Stripe and the card", true, false !== strpos( implode( ' ', $r['messages'] ), 'Nonprofit Manager Pro is off, so you have no Cancel button here. Cancel the plan in your Stripe dashboard' ) );
		check( "$status plan: Pro off links the card", true, false !== strpos( implode( ' ', $r['messages'] ), '<a href="https://site.test/wp-admin/admin.php?page=npmp_general_settings#npmp-privacy-requests">Personal data requests</a>' ) );
	} else {
		check( "$status plan does not hold: contact deleted", false, isset( $s->contacts[20] ) );
		check( "$status plan does not hold: gifts stripped", '', $s->gifts[101]['email'] );
	}
}
$s          = t_seed();
$s->plans[] = array( 'member_id' => 20, 'status' => 'active', 'frequency' => 'one_time' );
t_erase_all( 'donor@example.org' );
check( 'a one-time row never holds', false, isset( $s->contacts[20] ) );
$s          = t_seed();
$s->plans[] = array( 'member_id' => 20, 'status' => 'active', 'frequency' => 'monthly' );
$s->plans[] = array( 'member_id' => 20, 'status' => 'lapsed', 'frequency' => 'yearly' );
$r          = t_erase_all( 'donor@example.org' );
check( 'two statuses give two messages', 2, count( $r['messages'] ) );

echo "\nErase: Pro data and Pro's eraser\n";
$s           = t_seed();
$s->leftover = 3;
$before      = json_encode( array( $s->contacts, $s->gifts ) );
$r           = t_erase_all( 'donor@example.org' );
check( 'Pro data with no Pro eraser holds', $before, json_encode( array( $s->contacts, $s->gifts ) ) );
check( 'message says to activate or update Pro', true, false !== strpos( implode( ' ', $r['messages'] ), 'Activate or update Nonprofit Manager Pro' ) );
check( 'message says no licence is needed', true, false !== strpos( implode( ' ', $r['messages'] ), 'this step needs no licence' ) );
$s                       = t_seed();
$s->leftover             = 3;
$GLOBALS['t_pro_eraser'] = true;
$before                  = json_encode( array( $s->contacts, $s->gifts ) );
$r                       = t_erase_all( 'donor@example.org' );
check( 'Pro data left after Pro\'s eraser ran still holds', $before, json_encode( array( $s->contacts, $s->gifts ) ) );
check( 'and says Pro left data', true, false !== strpos( implode( ' ', $r['messages'] ), 'Nonprofit Manager Pro left data about this person' ) );
check( 'Pro\'s own question skips leftovers', array(), npmp_privacy_erase_holds_for( 'donor@example.org', 'pro' ) );
$s->leftover = 0;
t_erase_all( 'donor@example.org' );
check( 'Pro data gone, Pro\'s eraser on the list: contact deleted', false, isset( $s->contacts[20] ) );
$GLOBALS['t_pro_eraser'] = false;
$s = t_seed();
t_erase_all( 'donor@example.org' );
check( 'no Pro data, no Pro eraser: contact deleted', false, isset( $s->contacts[20] ) );

echo "\nErase: queue and tracking once a request\n";
$s = new class() extends T_Store {
	public $queue_calls = 0;
	public function delete_queue( $email, array $ids = array() ) {
		$this->queue_calls++;
		return parent::delete_queue( $email );
	}
};
foreach ( get_object_vars( t_seed() ) as $k => $v ) {
	$s->$k = $v;
}
npmp_privacy_store( $s );
t_erase_all( 'donor@example.org' );
check( 'three gift pages, one queue pass', 1, $s->queue_calls );

echo "\nErase: unknown email\n";
$s      = t_seed();
$before = $s->snapshot();
$r      = t_erase_all( 'nobody@example.org' );
check( 'nothing removed', false, $r['removed'] );
check( 'nothing retained', false, $r['retained'] );
check( 'no messages', array(), $r['messages'] );
check( 'store unchanged', $before, $s->snapshot() );
$r = npmp_privacy_erase( '', 1 );
check( 'blank email: done, nothing', array( false, false, true ), array( $r['items_removed'], $r['items_retained'], $r['done'] ) );

echo "\nErase: mixed-case request\n";
$s = t_seed();
t_erase_all( 'PLAIN@EXAMPLE.ORG' );
check( 'upper-case request finds the contact', false, isset( $s->contacts[10] ) );

echo "\nErase: a second run changes nothing\n";
foreach ( array( 'plain@example.org', 'donor@example.org', 'gone@example.org' ) as $email ) {
	$s = t_seed();
	t_erase_all( $email );
	$after_first = $s->snapshot();
	$r           = t_erase_all( $email );
	check( $email . ': store unchanged by second run', $after_first, $s->snapshot() );
	check( $email . ': second run removes nothing', false, $r['removed'] );
}

echo "\nHolds: one answer for both erasers\n";
$s                  = t_seed();
$GLOBALS['t_holds'] = array( 'Legal hold <b>until</b> 2027' );
check( 'a filter-only hold reaches Pro\'s question', array( 'Legal hold &lt;b&gt;until&lt;/b&gt; 2027' ), npmp_privacy_erase_holds_for( 'donor@example.org', 'pro' ) );
$r = t_erase_all( 'donor@example.org' );
check( 'a filter hold prints as text', array( 'Legal hold &lt;b&gt;until&lt;/b&gt; 2027' ), $r['messages'] );
$s->leftover = 2;
$r           = t_erase_all( 'donor@example.org' );
check( 'a filter hold with Pro rows left: one message, no leftovers note', array( 'Legal hold &lt;b&gt;until&lt;/b&gt; 2027' ), $r['messages'] );
$GLOBALS['t_holds'] = array();
$s                  = t_seed();
$s->plans[]         = array( 'member_id' => 20, 'status' => 'active', 'frequency' => 'monthly' );
check( 'a plan hold reaches Pro\'s question', 1, count( npmp_privacy_erase_holds_for( 'DONOR@example.org', 'pro' ) ) );
check( 'billing statuses', array( 'active', 'failed', 'lapsed', 'paused' ), npmp_privacy_billing_statuses() );

echo "\nHolds: words for each way to cancel\n";
$s          = t_seed();
$s->plans[] = array( 'member_id' => 20, 'status' => 'active', 'frequency' => 'monthly' );
check( 'Pro not running reads off', 'off', npmp_privacy_cancel_reach() );
define( 'NPMP_PRO_VERSION', '2026.11.28' );
check( 'Pro with no answer reads old', 'old', npmp_privacy_cancel_reach() );
$r = t_erase_all( 'donor@example.org' );
check( 'old Pro: card link', true, false !== strpos( implode( ' ', $r['messages'] ), 'may not reach Stripe from its Cancel button' ) && false !== strpos( implode( ' ', $r['messages'] ), '#npmp-privacy-requests' ) );
$GLOBALS['t_cancel_state'] = 'ready';
if ( ! function_exists( 'npmp_pro_privacy_cancel_state' ) ) {
	// Defined here, at run time, so the checks above see no function.
	function npmp_pro_privacy_cancel_state() {
		return $GLOBALS['t_cancel_state'];
	}
}
$words = array(
	'ready'      => 'Cancel the plan on the Recurring Donations or Membership Dues screen, then run this request a second time.',
	'unlicensed' => 'Cancel button needs an active licence. Cancel the plan in your Stripe dashboard',
	'no_keys'    => 'needs your Stripe secret key for the current mode, and Payment Settings has none. Cancel the plan in your Stripe dashboard',
);
foreach ( $words as $state => $text ) {
	$GLOBALS['t_cancel_state'] = $state;
	$s                         = t_seed();
	$s->plans[]                = array( 'member_id' => 20, 'status' => 'lapsed', 'frequency' => 'monthly' );
	$r                         = t_erase_all( 'donor@example.org' );
	$all                       = implode( ' ', $r['messages'] );
	check( "$state: the words fit", true, false !== strpos( $all, $text ) );
	check( "$state: " . ( 'ready' === $state ? 'no card link' : 'card link' ), 'ready' !== $state, false !== strpos( $all, '#npmp-privacy-requests' ) );
}

echo "\nHolds: a filter that clears every hold keeps the plan hold (L2)\n";
$GLOBALS['t_holds_clear'] = true;
$s                        = t_seed();
$s->plans[]               = array( 'member_id' => 20, 'status' => 'active', 'frequency' => 'monthly' );
$rig_free_holds           = npmp_privacy_erase_holds_for( 'donor@example.org', 'free' );
$rig_pro_holds            = npmp_privacy_erase_holds_for( 'donor@example.org', 'pro' );
check( 'Pro\'s eraser still sees the plan hold', 1, count( $rig_pro_holds ) );
check( 'free\'s eraser sees the same hold', $rig_pro_holds, $rig_free_holds );
$before = json_encode( array( $s->contacts, $s->gifts ) );
t_erase_all( 'donor@example.org' );
check( 'the contact and gifts stay', $before, json_encode( array( $s->contacts, $s->gifts ) ) );
$s = t_seed();
t_erase_all( 'donor@example.org' );
check( 'with no plan, the cleared filter lets the erase run', false, isset( $s->contacts[20] ) );
$s              = t_seed();
$s->leftover    = 2;
$GLOBALS['t_pro_eraser'] = true;
$before         = json_encode( array( $s->contacts, $s->gifts ) );
t_erase_all( 'donor@example.org' );
check( 'with no plan, Pro rows left still hold free\'s eraser', $before, json_encode( array( $s->contacts, $s->gifts ) ) );
$GLOBALS['t_pro_eraser']  = false;
$GLOBALS['t_holds_clear'] = false;

echo "\nMarking a plan canceled: the status the admin saw (L1)\n";
$plan = array( 'id' => 7, 'member_id' => 20, 'status' => 'lapsed', 'frequency' => 'monthly', 'payment_method' => 'pm_x' );
check( 'same status, open request: go ahead', '', npmp_privacy_mark_refusal( $plan, 'lapsed', true ) );
check( 'the row went active after the admin saw lapsed: refused', 'changed', npmp_privacy_mark_refusal( array_merge( $plan, array( 'status' => 'active' ) ), 'lapsed', true ) );
check( 'no status posted (an old form): refused', 'changed', npmp_privacy_mark_refusal( $plan, '', true ) );
check( 'no open request: held', 'held', npmp_privacy_mark_refusal( $plan, 'lapsed', false ) );
check( 'closed since: held', 'held', npmp_privacy_mark_refusal( array_merge( $plan, array( 'status' => 'cancelled' ) ), 'lapsed', true ) );
check( 'one-time row: held', 'held', npmp_privacy_mark_refusal( array_merge( $plan, array( 'frequency' => 'one_time' ) ), 'lapsed', true ) );
check( 'missing row: held', 'held', npmp_privacy_mark_refusal( null, 'lapsed', true ) );

echo "\nOpen erase requests: every page, not the newest 100 (L4)\n";
$GLOBALS['t_requests'] = array();
for ( $i = 1; $i <= 1203; $i++ ) {
	$GLOBALS['t_requests'][ $i ] = 'person' . $i . '@example.org';
}
$GLOBALS['t_requests'][1204] = 'PERSON1@example.org';
$GLOBALS['t_get_posts']      = array();
function get_posts( $args ) {
	$GLOBALS['t_get_posts'][] = $args;
	$ids                      = array_keys( $GLOBALS['t_requests'] );
	rsort( $ids );
	return array_slice( $ids, ( $args['paged'] - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
}
function get_post_field( $field, $id ) {
	return $GLOBALS['t_requests'][ $id ] ?? '';
}
$open = ( new NPMP_Privacy_Store() )->open_erase_emails();
check( 'all 1,203 addresses, one per person', 1203, count( $open ) );
check( 'the oldest request is on the list', true, in_array( 'person1@example.org', array_map( 'strtolower', $open ), true ) );
check( 'newest first', 'PERSON1@example.org', $open[0] );
check( 'three pages of 500', array( 1, 2, 3 ), array_column( $GLOBALS['t_get_posts'], 'paged' ) );
check( 'open statuses only', array( 'request-pending', 'request-confirmed' ), $GLOBALS['t_get_posts'][0]['post_status'] );

echo "\nEmail match: exact letters, any case (L3)\n";
$GLOBALS['wpdb'] = new class() {
	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $args );
	}
};
$match = ( new ReflectionMethod( 'NPMP_Privacy_Store', 'match_email' ) )->invoke( null, 'e.meta_value', ' José@Example.org ' );
check( 'compares lowercase bytes, never the collation', "HEX( LOWER( e.meta_value ) ) = HEX( LOWER( 'José@Example.org' ) )", $match );
unset( $GLOBALS['wpdb'] );

echo "\nPolicy text\n";
$text = npmp_privacy_policy_text();
check( 'names Stripe, PayPal, and Venmo', true, false !== strpos( $text, 'Stripe, PayPal, or Venmo' ) );
check( 'names the captcha services', true, false !== strpos( $text, 'Cloudflare Turnstile or Google reCAPTCHA' ) );
check( 'marks the guidance for core', true, false !== strpos( $text, 'privacy-policy-tutorial' ) );
check( 'open tracking reads as on unless turned off', true, false !== strpos( $text, 'Unless we turn it off, we record when you open a newsletter.' ) );
check( 'link tracking reads as off unless turned on', true, false !== strpos( $text, 'If we turn on link tracking, we also record which links' ) );
check( 'a paused plan keeps the record', true, false !== strpos( $text, 'including a paused plan we could resume' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
