<?php
// Usage: NPMP_RIG_WP=/path/to/wp [NPMP_RIG_URL=http://127.0.0.1:<port>] php tests/rig/stripe-webhook.php [rounds] [returns per round]
//
// The Stripe webhook for one-time gifts (includes/payments/npmp-stripe-webhook.php)
// against the donor's return, on a real WordPress with a real database.
//
// Until this, a one-time Stripe gift was recorded only when the donor's browser
// came back to the success URL. A donor who paid and closed the tab left a
// paid gift with no record and no thank-you. Now Stripe's
// checkout.session.completed records the gift too, at free's address
// (action=npmp_stripe_webhook) and, with Pro, at Pro's older address
// (action=npmp_stripe_recurring_webhook). The donor's return, either address,
// and Stripe's repeat deliveries can all land at once, and the donor must get
// one row and one thank-you.
//
// Every delivery and every return is its own PHP process, as each is its own
// request on a live site. All of them load WordPress, wait for one shared
// start time, and go. Webhook processes sign the event the way Stripe does
// (HMAC-SHA256 over "<t>.<body>", sent as "t=...,v1=...") and call
// npmp_stripe_webhook_receive(), or for Pro's address the same steps as
// npmp_handle_stripe_recurring_webhook() past reading php://input. Return
// processes call npmp_maybe_finalize_stripe_donation() with a pre_http_request
// fake that answers api.stripe.com with the paid session after 150 ms.
//
// Each process logs what it did to one shared file: every email (pre_wp_mail),
// every npmp_donation_recorded, every npmp_send_automation_email it asked
// WP-Cron for (pre_schedule_event, which runs before core drops a duplicate
// event), and every Stripe call a return made.
//
// Covers: a webhook alone records the gift (the closed tab), the thank-you
// goes once (Pro sends it, so with Pro off the count is zero), Pro's Donation
// Received automation is queued once, the race of returns and repeat deliveries
// at both addresses writes one row, a return after the webhook makes no Stripe
// call, a webhook after the return answers "already recorded", no saved secret
// answers 503 and records nothing while the return still records, a wrong
// secret or a stale or future timestamp answers 403, unpaid or foreign
// sessions record nothing, another Nonprofit Manager site's session on the
// same Stripe account records nothing here (gifts, and with Pro dues joins),
// a gift the owner trashed stays trashed when Stripe sends it again, and two
// saved secrets (one per Stripe endpoint) each pass. With NPMP_RIG_URL set
// (the site served by PHP_CLI_SERVER_WORKERS=4 php -S at its home URL), the
// same over HTTP through admin-ajax, four deliveries at once included. With
// NPMP_RIG_OTHER_WP and NPMP_RIG_OTHER_URL set to a second served site, two
// sites on one Stripe account over HTTP: each event goes to both sites, and
// only the site that made the session records it. Leaves no donations,
// members, claims, cron events, automations, options or files behind, on
// either site. Not run by the pre-push gate (it needs WordPress). Run it on
// MySQL and SQLite, with Pro on and off.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

/** Stripe-Signature for a payload, as Stripe builds it. */
function rig_sign( $payload, $secret, $t ) {
	return 't=' . (int) $t . ',v1=' . hash_hmac( 'sha256', (int) $t . '.' . $payload, $secret );
}

/** Log every email, recorded gift, and queued automation email to $log. */
function rig_watch( $log ) {
	add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) use ( $log ) {
			$to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
			foreach ( $to as $addr ) {
				file_put_contents( $log, 'mail ' . strtolower( trim( $addr ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			return true;
		},
		10,
		2
	);
	add_action(
		'npmp_donation_recorded',
		function ( $id, $donation ) use ( $log ) {
			file_put_contents( $log, 'recorded ' . strtolower( (string) ( $donation['email'] ?? '' ) ) . "\n", FILE_APPEND | LOCK_EX );
		},
		1,
		2
	);
	add_filter(
		'pre_schedule_event',
		function ( $pre, $event ) use ( $log ) {
			if ( isset( $event->hook ) && 'npmp_send_automation_email' === $event->hook ) {
				file_put_contents( $log, 'queue ' . strtolower( (string) ( $event->args[1] ?? '' ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			return $pre;
		},
		10,
		2
	);
}

/**
 * A mu-plugin for a served site: logs every email (and sends none), recorded
 * gift, and queued automation email to $log, as rig_watch() does in-process.
 */
function rig_mu_source( $log ) {
	return '<?php' . "\n// Written by tests/rig/stripe-webhook.php and deleted at the end of its run.\n" .
		'$rig_wh_log = ' . var_export( $log, true ) . ";\n" .
		'add_filter( "pre_wp_mail", function ( $n, $a ) use ( $rig_wh_log ) { foreach ( ( is_array( $a["to"] ) ? $a["to"] : explode( ",", (string) $a["to"] ) ) as $t ) { file_put_contents( $rig_wh_log, "mail " . strtolower( trim( $t ) ) . "\n", FILE_APPEND | LOCK_EX ); } return true; }, 10, 2 );' . "\n" .
		'add_action( "npmp_donation_recorded", function ( $id, $d ) use ( $rig_wh_log ) { file_put_contents( $rig_wh_log, "recorded " . strtolower( (string) ( $d["email"] ?? "" ) ) . "\n", FILE_APPEND | LOCK_EX ); }, 1, 2 );' . "\n" .
		'add_filter( "pre_schedule_event", function ( $p, $e ) use ( $rig_wh_log ) { if ( isset( $e->hook ) && "npmp_send_automation_email" === $e->hook ) { file_put_contents( $rig_wh_log, "queue " . strtolower( (string) ( $e->args[1] ?? "" ) ) . "\n", FILE_APPEND | LOCK_EX ); } return $p; }, 10, 2 );' . "\n";
}

/**
 * Pro's address in-process: npmp_handle_stripe_recurring_webhook() past
 * reading php://input and before wp_send_json_*. The HTTP section runs the
 * real handler.
 */
function rig_pro_address( $payload, $sig ) {
	$secrets = npmp_stripe_webhook_secret_list();
	if ( ! $secrets ) {
		return array( 503, 'Webhook secret not configured.' );
	}
	$verified = false;
	foreach ( $secrets as $secret ) {
		$verified = $verified || npmp_verify_stripe_signature( $payload, $sig, $secret );
	}
	if ( ! $verified ) {
		return array( 403, 'Invalid signature.' );
	}
	$result = npmp_stripe_process_event( json_decode( $payload, false ) );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return array( ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 500, $result->get_error_message() );
	}
	return false === $result ? array( 200, 'Event already processed.' ) : array( 200, 'Webhook processed.' );
}

// --- One donor return (child process) --------------------------------------
if ( isset( $argv[1] ) && 'return' === $argv[1] ) {
	list( , , $session_path, $start_at, $log ) = $argv;
	require $wp_root . '/wp-load.php';
	rig_watch( $log );
	$session_json = file_get_contents( $session_path );
	$session      = json_decode( $session_json, true );
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $session_json, $session, $log ) {
			if ( false === strpos( $url, 'api.stripe.com' ) ) {
				return $pre;
			}
			file_put_contents( $log, 'stripe ' . $session['id'] . "\n", FILE_APPEND | LOCK_EX );
			usleep( 150000 );
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => $session_json,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		},
		10,
		3
	);
	while ( microtime( true ) < (float) $start_at ) {
		usleep( 100 );
	}
	$_GET = array( 'npmp_donation' => 'success', 'npmp_session_id' => $session['id'] );
	npmp_maybe_finalize_stripe_donation();
	echo 'return';
	exit( 0 );
}

// --- One webhook delivery (child process) -----------------------------------
if ( isset( $argv[1] ) && 'hook' === $argv[1] ) {
	list( , , $address, $payload_path, $start_at, $log, $secret, $t_offset ) = $argv;
	require $wp_root . '/wp-load.php';
	rig_watch( $log );
	$payload = file_get_contents( $payload_path );
	while ( microtime( true ) < (float) $start_at ) {
		usleep( 100 );
	}
	$sig = rig_sign( $payload, $secret, time() + (int) $t_offset );
	if ( 'pro' === $address ) {
		list( $status, $message ) = rig_pro_address( $payload, $sig );
	} else {
		list( $status, $message ) = npmp_stripe_webhook_receive( $payload, $sig );
	}
	echo $status . ' ' . $message;
	exit( 0 );
}

// --- The second site (child process, NPMP_RIG_WP set to that site) ----------
if ( isset( $argv[1] ) && 'other' === $argv[1] ) {
	$what = $argv[2] ?? '';
	$arg  = $argv[3] ?? '';
	require $wp_root . '/wp-load.php';
	global $wpdb;
	$other_mu = WP_CONTENT_DIR . '/mu-plugins/rig-stripe-webhook-other.php';
	if ( 'setup' === $what ) {
		if ( ! is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			mkdir( WP_CONTENT_DIR . '/mu-plugins', 0755, true );
		}
		file_put_contents( $other_mu, rig_mu_source( $argv[4] ) );
		$prev = array();
		foreach ( array( 'npmp_stripe_mode', 'npmp_stripe_test_secret_key', 'npmp_stripe_webhook_secret' ) as $opt ) {
			$prev[ $opt ] = get_option( $opt, null );
		}
		update_option( 'npmp_stripe_mode', 'test' );
		update_option( 'npmp_stripe_test_secret_key', 'sk_test_rigdummy' );
		update_option( 'npmp_stripe_webhook_secret', $arg );
		$pro = function_exists( 'npmp_is_pro' ) && npmp_is_pro() && function_exists( 'npmp_stripe_process_event' );
		echo wp_json_encode( array( 'tag' => npmp_stripe_site_tag(), 'home' => get_option( 'home' ), 'pro' => $pro, 'prev' => $prev ) );
	} elseif ( 'rows' === $what ) {
		echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $arg ) );
	} elseif ( 'member' === $what ) {
		$member = NPMP_Member_Manager::get_instance()->get_member_by_email( $arg );
		echo ( $member && ! empty( $member->id ) ) ? 'yes' : 'no';
	} elseif ( 'cleanup' === $what ) {
		$plan = json_decode( (string) file_get_contents( $arg ), true );
		foreach ( $plan['sids'] as $sid ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $sid ) ) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
		}
		$want = array();
		foreach ( $plan['emails'] as $email ) {
			$want[ strtolower( $email ) ] = true;
			$member = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
			if ( $member && ! empty( $member->id ) ) {
				NPMP_Member_Manager::get_instance()->delete_member( (int) $member->id );
			}
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'npmp_stripe_events' ) ) ) {
			foreach ( $plan['events'] as $evt ) {
				$wpdb->delete( $wpdb->prefix . 'npmp_stripe_events', array( 'event_id' => $evt ), array( '%s' ) );
			}
		}
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			foreach ( $hooks['npmp_send_automation_email'] ?? array() as $ev ) {
				if ( isset( $want[ strtolower( (string) ( $ev['args'][1] ?? '' ) ) ] ) ) {
					wp_unschedule_event( $ts, 'npmp_send_automation_email', $ev['args'] );
				}
			}
		}
		foreach ( $plan['prev'] as $opt => $value ) {
			null === $value ? delete_option( $opt ) : update_option( $opt, $value );
		}
		if ( file_exists( $other_mu ) ) {
			unlink( $other_mu );
		}
		echo 'clean';
	}
	exit( 0 );
}

// --- The run (parent) ------------------------------------------------------
$rounds  = isset( $argv[1] ) ? max( 1, (int) $argv[1] ) : 3;
$returns = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 4;

$GLOBALS['rig_errors'] = array();
set_error_handler(
	function ( $no, $str, $errfile, $line ) {
		if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
			$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
		}
		return false;
	}
);
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-70s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

global $wpdb;
$run       = wp_rand( 1000, 9999 );
$rig_php   = PHP_BINARY;
$rig_self  = __FILE__;
$rig_dir   = sys_get_temp_dir() . "/npmp-wh-$run";
$rig_log   = "$rig_dir/log.txt";
$secret    = "whsec_rig{$run}secret";
$pro_on    = function_exists( 'npmp_is_pro' ) && npmp_is_pro() && function_exists( 'npmp_stripe_process_event' );
$autos_on  = $pro_on && class_exists( 'NPMP_Automation_Engine' );
$thanks    = $pro_on ? 1 : 0;
$queued    = $autos_on ? 1 : 0;
$dm        = NPMP_Donation_Manager::get_instance();
$mm        = NPMP_Member_Manager::get_instance();
$sids      = array();
$emails    = array();
$event_ids = array();
$debug_log = WP_CONTENT_DIR . '/debug.log';
$debug_at  = file_exists( $debug_log ) ? filesize( $debug_log ) : 0;
mkdir( $rig_dir, 0700, true );

/** Rows on file for a session id, read straight from the database. */
function rig_rows( $sid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $sid ) );
}

/** Lock rows left behind by log_donation(). */
function rig_locks() {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'npmp_queue_lock_donation_txn_%'" );
}

/** Lines in the shared log that read "<kind> <what>". */
function rig_count( $kind, $what ) {
	$n = 0;
	foreach ( (array) @file( $GLOBALS['rig_log'], FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( $line === $kind . ' ' . strtolower( $what ) ) {
			++$n;
		}
	}
	return $n;
}

/** A paid Checkout Session from the donation form. */
function rig_session( $sid, $email, $extra = array() ) {
	return array_merge(
		array(
			'id'               => $sid,
			'object'           => 'checkout.session',
			'amount_total'     => 2500,
			'currency'         => 'usd',
			'customer_details' => array( 'email' => $email, 'name' => 'Webhook Donor' ),
			'customer_email'   => $email,
			'livemode'         => false,
			'metadata'         => array( 'gateway' => 'stripe', 'frequency' => 'one_time', 'npmp_site' => npmp_stripe_site_tag() ),
			'mode'             => 'payment',
			'payment_status'   => 'paid',
			'status'           => 'complete',
			'success_url'      => home_url( '/donate/?npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}' ),
		),
		$extra
	);
}

/** A new gift: session id, email, event id, and the files the children read. */
function rig_gift( $tag, $extra = array() ) {
	global $run, $rig_dir, $sids, $emails, $event_ids;
	$sid         = "cs_test_rigwh{$run}{$tag}";
	$email       = "rig-wh{$run}{$tag}@example.org";
	$evt         = "evt_rigwh{$run}{$tag}";
	$sids[]      = $sid;
	$emails[]    = $email;
	$event_ids[] = $evt;
	$session     = rig_session( $sid, $email, $extra );
	file_put_contents( "$rig_dir/$tag-session.json", wp_json_encode( $session ) );
	file_put_contents(
		"$rig_dir/$tag-event.json",
		wp_json_encode(
			array(
				'id'       => $evt,
				'object'   => 'event',
				'type'     => 'checkout.session.completed',
				'created'  => time(),
				'livemode' => false,
				'data'     => array( 'object' => $session ),
			)
		)
	);
	return array(
		'sid'     => $sid,
		'email'   => $email,
		'evt'     => $evt,
		'session' => "$rig_dir/$tag-session.json",
		'payload' => "$rig_dir/$tag-event.json",
	);
}

function rig_spawn( array $args, $site = null ) {
	global $rig_php, $rig_self, $wp_root;
	$proc = proc_open(
		array_merge( array( $rig_php, $rig_self ), $args ),
		array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', $GLOBALS['rig_dir'] . '/stderr.txt', 'a' ) ),
		$pipes,
		null,
		array( 'NPMP_RIG_WP' => null === $site ? $wp_root : $site ) + getenv()
	);
	return array( $proc, $pipes[1] );
}

/** Run one command on the second site and return what it printed. */
function rig_other( $what, $arg = '' ) {
	$out = rig_wait( array( rig_spawn( array( 'other', $what, $arg, $GLOBALS['rig_log'] ), $GLOBALS['rig_other_wp'] ) ) );
	return $out[0];
}

function rig_wait( array $children ) {
	$out = array();
	foreach ( $children as $child ) {
		$out[] = trim( (string) stream_get_contents( $child[1] ) );
		fclose( $child[1] );
		proc_close( $child[0] );
	}
	return $out;
}

/** A webhook delivery: returns "<status> <message>". */
function rig_hook( $gift, $address = 'free', $sign_with = null, $t_offset = 0, $start_at = null ) {
	$start_at = null === $start_at ? microtime( true ) : $start_at;
	$out      = rig_wait( array( rig_spawn( array( 'hook', $address, $gift['payload'], sprintf( '%.4f', $start_at ), $GLOBALS['rig_log'], null === $sign_with ? $GLOBALS['secret'] : $sign_with, (string) (int) $t_offset ) ) ) );
	return $out[0];
}

function rig_return( $gift ) {
	return rig_wait( array( rig_spawn( array( 'return', $gift['session'], sprintf( '%.4f', microtime( true ) ), $GLOBALS['rig_log'] ) ) ) );
}

function rig_member( $email ) {
	$member = NPMP_Member_Manager::get_instance()->get_member_by_email( $email );
	return $member && ! empty( $member->id );
}

// --- Setup -----------------------------------------------------------------
$prev = array();
foreach ( array( 'npmp_stripe_mode', 'npmp_stripe_test_secret_key', 'npmp_stripe_webhook_secret' ) as $opt ) {
	$prev[ $opt ] = get_option( $opt, null );
}
update_option( 'npmp_stripe_mode', 'test' );
update_option( 'npmp_stripe_test_secret_key', 'sk_test_rigdummy' );
update_option( 'npmp_stripe_webhook_secret', $secret );
file_put_contents( $rig_log, '' );
if ( $autos_on ) {
	NPMP_Automation_Engine::get_instance()->save_automation(
		array(
			'id'                => 'rigwh_gift',
			'name'              => 'Rig gift thanks',
			'trigger_type'      => 'donation_received',
			'conditions'        => array(),
			'email_template_id' => 0,
			'custom_subject'    => 'Thanks',
			'custom_body'       => 'Thanks',
			'delay_minutes'     => 0,
			'status'            => 'active',
		)
	);
}
$free_ok = '200 Gift recorded.';
$pro_ok  = '200 Webhook processed.';

echo "== Setup: Pro " . ( $pro_on ? 'on' : 'off' ) . ', automations ' . ( $autos_on ? 'on' : 'off' ) . ", database {$wpdb->db_server_info()} ==\n";

// --- 1. The closed tab -----------------------------------------------------
echo "\n== 1. The donor pays and closes the tab: the webhook alone records the gift ==\n";
foreach ( $pro_on ? array( 'free', 'pro' ) : array( 'free' ) as $address ) {
	$gift = rig_gift( "tab$address" );
	$said = rig_hook( $gift, $address );
	rig_check( "$address address answers 200", 'free' === $address ? $free_ok : $pro_ok, $said );
	rig_check( "$address address: one donation row", 1, rig_rows( $gift['sid'] ) );
	$post_id = (int) $dm->find_by_transaction_id( $gift['sid'] );
	rig_check( "$address address: amount from the session", 25.0, (float) get_post_meta( $post_id, NPMP_Donation_Manager::META_AMOUNT, true ) );
	rig_check( "$address address: currency from the session", 'USD', strtoupper( (string) get_post_meta( $post_id, NPMP_Donation_Manager::META_CURRENCY, true ) ) );
	rig_check( "$address address: gateway stripe", 'stripe', (string) get_post_meta( $post_id, NPMP_Donation_Manager::META_GATEWAY, true ) );
	rig_check( "$address address: npmp_donation_recorded once", 1, rig_count( 'recorded', $gift['email'] ) );
	rig_check( "$address address: the donor is thanked " . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $thanks, rig_count( 'mail', $gift['email'] ) );
	rig_check( "$address address: Donation Received automation queued " . ( $autos_on ? 'once' : 'never (Pro off)' ), $queued, rig_count( 'queue', $gift['email'] ) );
	rig_check( "$address address: the donor is a contact", true, rig_member( $gift['email'] ) );
}

// --- 2. The race -----------------------------------------------------------
$addresses = $pro_on ? array( 'free', 'free', 'pro', 'pro' ) : array( 'free', 'free' );
echo "\n== 2. $returns returns and " . count( $addresses ) . ' deliveries (' . implode( ', ', $addresses ) . ") in the same instant, $rounds rounds ==\n";
for ( $round = 1; $round <= $rounds; $round++ ) {
	$gift     = rig_gift( "race$round" );
	$start_at = sprintf( '%.4f', microtime( true ) + 2.5 );
	$children = array();
	foreach ( $addresses as $address ) {
		$children[] = rig_spawn( array( 'hook', $address, $gift['payload'], $start_at, $rig_log, $secret, '0' ) );
	}
	for ( $i = 0; $i < $returns; $i++ ) {
		$children[] = rig_spawn( array( 'return', $gift['session'], $start_at, $rig_log ) );
	}
	$out   = rig_wait( $children );
	$hooks = array_slice( $out, 0, count( $addresses ) );
	rig_check( "round $round: every delivery answers 200", count( $addresses ), count( array_filter( $hooks, function ( $s ) { return 0 === strpos( $s, '200 ' ); } ) ) );
	rig_check( "round $round: every return finished", $returns, count( array_filter( array_slice( $out, count( $addresses ) ), function ( $s ) { return 'return' === $s; } ) ) );
	rig_check( "round $round: one donation row", 1, rig_rows( $gift['sid'] ) );
	rig_check( "round $round: npmp_donation_recorded once", 1, rig_count( 'recorded', $gift['email'] ) );
	rig_check( "round $round: the donor is thanked " . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $thanks, rig_count( 'mail', $gift['email'] ) );
	rig_check( "round $round: automation queued " . ( $autos_on ? 'once' : 'never' ), $queued, rig_count( 'queue', $gift['email'] ) );
	if ( 1 === $round ) {
		echo '        deliveries said: ' . implode( ' | ', $hooks ) . "\n";
	}
}
rig_check( 'no lock row left in the options table', 0, rig_locks() );

// --- 3. One after the other ------------------------------------------------
echo "\n== 3. Webhook first, then the return; return first, then the webhook ==\n";
$gift = rig_gift( 'hookfirst' );
rig_check( 'webhook records the gift', $free_ok, rig_hook( $gift ) );
rig_return( $gift );
rig_check( 'the return after it asks Stripe nothing', 0, rig_count( 'stripe', $gift['sid'] ) );
rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
rig_check( 'still thanked ' . ( $pro_on ? 'once' : 'by Pro only' ), $thanks, rig_count( 'mail', $gift['email'] ) );

$gift = rig_gift( 'returnfirst' );
rig_return( $gift );
rig_check( 'the return records the gift', 1, rig_rows( $gift['sid'] ) );
rig_check( 'the return asked Stripe once', 1, rig_count( 'stripe', $gift['sid'] ) );
rig_check( 'the webhook after it answers already recorded', $pro_on ? '200 Gift already recorded.' : '200 Gift already recorded.', rig_hook( $gift ) );
rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
rig_check( 'still thanked ' . ( $pro_on ? 'once' : 'by Pro only' ), $thanks, rig_count( 'mail', $gift['email'] ) );
rig_check( 'npmp_donation_recorded once', 1, rig_count( 'recorded', $gift['email'] ) );
if ( $pro_on ) {
	rig_check( "Stripe's delivery to Pro's address as well is a duplicate event", '200 Event already processed.', rig_hook( $gift, 'pro' ) );
	rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
}

// --- 4. No secret saved ----------------------------------------------------
echo "\n== 4. No signing secret saved: the webhook records nothing, the return still works ==\n";
delete_option( 'npmp_stripe_webhook_secret' );
$gift = rig_gift( 'nosecret' );
rig_check( 'free address answers 503', '503 Webhook secret not configured.', rig_hook( $gift ) );
if ( $pro_on ) {
	rig_check( "Pro's address answers 503", '503 Webhook secret not configured.', rig_hook( $gift, 'pro' ) );
}
rig_check( 'no donation row', 0, rig_rows( $gift['sid'] ) );
rig_check( 'nothing recorded', 0, rig_count( 'recorded', $gift['email'] ) );
rig_return( $gift );
rig_check( 'the return records the gift', 1, rig_rows( $gift['sid'] ) );
rig_check( 'the donor is thanked ' . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $thanks, rig_count( 'mail', $gift['email'] ) );
update_option( 'npmp_stripe_webhook_secret', $secret );

// --- 5. Refusals -----------------------------------------------------------
echo "\n== 5. Forged, replayed, unpaid and foreign events record nothing ==\n";
$gift = rig_gift( 'forged' );
rig_check( 'wrong secret → 403', '403 Invalid signature.', rig_hook( $gift, 'free', 'whsec_wrong' ) );
rig_check( 'timestamp 10 minutes old → 403', '403 Invalid signature.', rig_hook( $gift, 'free', null, -600 ) );
rig_check( 'timestamp 10 minutes ahead → 403', '403 Invalid signature.', rig_hook( $gift, 'free', null, 600 ) );
if ( $pro_on ) {
	rig_check( "Pro's address, wrong secret → 403", '403 Invalid signature.', rig_hook( $gift, 'pro', 'whsec_wrong' ) );
}
rig_check( 'no donation row', 0, rig_rows( $gift['sid'] ) );
rig_check( 'nothing recorded', 0, rig_count( 'recorded', $gift['email'] ) );
rig_check( 'no email', 0, rig_count( 'mail', $gift['email'] ) );

$gift = rig_gift( 'unpaid', array( 'payment_status' => 'unpaid' ) );
rig_check( 'unpaid session → 200, nothing to record', '200 Nothing to record.', rig_hook( $gift ) );
rig_check( 'no donation row', 0, rig_rows( $gift['sid'] ) );
$gift = rig_gift( 'foreign', array( 'metadata' => array( 'app' => 'another-store' ) ) );
rig_check( "another product's session → 200, nothing to record", '200 Nothing to record.', rig_hook( $gift ) );
rig_check( 'no donation row', 0, rig_rows( $gift['sid'] ) );
rig_check( 'no contact made', false, rig_member( $gift['email'] ) );

// --- 5b. Another site on the same Stripe account --------------------------
echo "\n== 5b. Another Nonprofit Manager site's session on the same Stripe account records nothing here ==\n";
$their_tag = substr( hash( 'sha256', 'npmp_site:other.example.org' ), 0, 16 );
$theirs    = array(
	'metadata'    => array( 'gateway' => 'stripe', 'frequency' => 'one_time', 'npmp_site' => $their_tag ),
	'success_url' => 'https://other.example.org/donate/?npmp_donation=success',
);
$gift = rig_gift( 'theirs', $theirs );
rig_check( "free address: 200, another site's gift", "200 Another site's gift. Nothing to record.", rig_hook( $gift ) );
if ( $pro_on ) {
	rig_check( "Pro's address: 200", $pro_ok, rig_hook( rig_gift( 'theirspro', $theirs ), 'pro' ) );
	rig_check( "Pro's address: no donation row", 0, rig_rows( "cs_test_rigwh{$run}theirspro" ) );
}
rig_return( $gift );
rig_check( 'a hand-made return with their session id: Stripe asked once', 1, rig_count( 'stripe', $gift['sid'] ) );
rig_check( 'no donation row', 0, rig_rows( $gift['sid'] ) );
rig_check( 'no contact made', false, rig_member( $gift['email'] ) );
rig_check( 'nobody thanked', 0, rig_count( 'mail', $gift['email'] ) );
$sub = rig_gift( 'theirsub', $theirs + array( 'mode' => 'subscription' ) );
rig_return( $sub );
rig_check( "their recurring gift's return: nobody thanked", 0, rig_count( 'mail', $sub['email'] ) );
rig_check( "their recurring gift's return: no contact made", false, rig_member( $sub['email'] ) );

$old_mine = rig_gift( 'oldmine', array( 'metadata' => array( 'gateway' => 'stripe', 'frequency' => 'one_time' ) ) );
rig_check( 'made before the tag, success_url here: recorded', $free_ok, rig_hook( $old_mine ) );
rig_check( 'one donation row', 1, rig_rows( $old_mine['sid'] ) );
$old_theirs = rig_gift( 'oldtheirs', array( 'metadata' => array( 'gateway' => 'stripe', 'frequency' => 'one_time' ), 'success_url' => 'https://other.example.org/donate/' ) );
rig_check( "made before the tag, success_url on another site: another site's gift", "200 Another site's gift. Nothing to record.", rig_hook( $old_theirs ) );
rig_check( 'no donation row', 0, rig_rows( $old_theirs['sid'] ) );

if ( $pro_on ) {
	$dues = rig_gift(
		'theirdues',
		array(
			'metadata'       => array( 'npmp_source' => 'dues', 'npmp_level' => 'Rig Gold', 'npmp_name' => 'Rig Joiner', 'npmp_frequency' => 'one_time', 'npmp_amount' => 2500, 'npmp_site' => $their_tag ),
			'payment_intent' => "pi_rigwh{$run}theirdues",
			'success_url'    => 'https://other.example.org/join/?npmp_join=success',
		)
	);
	rig_check( "their dues join at Pro's address: 200", $pro_ok, rig_hook( $dues, 'pro' ) );
	rig_check( 'their dues join: no member made here', false, rig_member( $dues['email'] ) );
	rig_check( 'their dues join: no dues row here', null, NPMP_Recurring_Manager::get_instance()->get_by_external_id( "pi_rigwh{$run}theirdues" ) );
	rig_check( 'their dues join: no welcome email', 0, rig_count( 'mail', $dues['email'] ) );
}

// --- 5c. A trashed gift stays trashed -------------------------------------
echo "\n== 5c. The owner trashed the gift, then Stripe sent it again ==\n";
$gift = rig_gift( 'trashed' );
rig_check( 'webhook records the gift', $free_ok, rig_hook( $gift ) );
$post_id = (int) $dm->find_by_transaction_id( $gift['sid'] );
wp_trash_post( $post_id );
rig_check( 'the owner trashed the gift', 'trash', get_post_status( $post_id ) );
$mails = rig_count( 'mail', $gift['email'] );
rig_check( "Stripe's late resend: 200", '200 ', substr( rig_hook( $gift ), 0, 4 ) );
rig_return( $gift );
rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
rig_check( 'still in the trash', 'trash', get_post_status( $post_id ) );
rig_check( 'no second thank-you', $mails, rig_count( 'mail', $gift['email'] ) );
rig_check( 'npmp_donation_recorded once', 1, rig_count( 'recorded', $gift['email'] ) );

// --- 5d. One signing secret per Stripe endpoint ----------------------------
echo "\n== 5d. Two saved secrets, one per Stripe endpoint ==\n";
$older = "whsec_rig{$run}olderendpoint";
update_option( 'npmp_stripe_webhook_secret', "$older $secret" );
$gift = rig_gift( 'twosecrets' );
rig_check( "free address, the older endpoint's secret: recorded", $free_ok, rig_hook( $gift, 'free', $older ) );
rig_check( "free address, the newer endpoint's secret: 200", '200 ', substr( rig_hook( $gift, 'free', $secret ), 0, 4 ) );
if ( $pro_on ) {
	rig_check( "Pro's address, the older endpoint's secret: 200", $pro_ok, rig_hook( rig_gift( 'twosecretspro' ), 'pro', $older ) );
	rig_check( "Pro's address, the newer endpoint's secret: 200", $pro_ok, rig_hook( rig_gift( 'twosecretspro2' ), 'pro', $secret ) );
}
rig_check( 'a third secret: 403', '403 Invalid signature.', rig_hook( $gift, 'free', "whsec_rig{$run}third" ) );
rig_check( 'one donation row', 1, rig_rows( $gift['sid'] ) );
update_option( 'npmp_stripe_webhook_secret', $secret );

// --- 6. Over HTTP ----------------------------------------------------------
$rig_url = rtrim( (string) getenv( 'NPMP_RIG_URL' ), '/' );
$rig_mu  = WP_CONTENT_DIR . '/mu-plugins/rig-stripe-webhook.php';
if ( '' !== $rig_url ) {
	echo "\n== 6. Over HTTP through admin-ajax ($rig_url) ==\n";
	if ( ! is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
		mkdir( WP_CONTENT_DIR . '/mu-plugins', 0755, true );
	}
	// The served site logs to the same file. rig_watch() lives in this file,
	// so the mu-plugin carries its own copy.
	file_put_contents( $rig_mu, rig_mu_source( $rig_log ) );

	/** POST a body with headers, return array( status, decoded body ). */
	$rig_post = function ( $action, $body, $headers ) use ( $rig_url ) {
		$ch = curl_init( "$rig_url/wp-admin/admin-ajax.php?action=$action" );
		curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
		$resp = (string) curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		return array( $code, json_decode( $resp, true ), $resp );
	};
	$said = function ( $r ) {
		return $r[0] . ' ' . ( $r[1]['data']['message'] ?? $r[2] );
	};

	$gift    = rig_gift( 'http' );
	$payload = file_get_contents( $gift['payload'] );
	$json    = array( 'Content-Type: application/json' );
	rig_check( 'free address answers 200', $free_ok, $said( $rig_post( 'npmp_stripe_webhook', $payload, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ) ) ) );
	rig_check( 'one donation row', 1, rig_rows( $gift['sid'] ) );
	rig_check( 'the donor is thanked ' . ( $pro_on ? 'once' : 'by Pro only, so not here' ), $thanks, rig_count( 'mail', $gift['email'] ) );
	rig_check( 'automation queued ' . ( $autos_on ? 'once' : 'never' ), $queued, rig_count( 'queue', $gift['email'] ) );
	rig_check(
		"Stripe's repeat delivery answers 200 and records nothing",
		$pro_on ? '200 Event already processed.' : '200 Gift already recorded.',
		$said( $rig_post( 'npmp_stripe_webhook', $payload, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ) ) )
	);
	rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
	rig_check( 'still thanked ' . ( $pro_on ? 'once' : 'by Pro only' ), $thanks, rig_count( 'mail', $gift['email'] ) );
	rig_check( 'wrong secret → 403', 403, $rig_post( 'npmp_stripe_webhook', $payload, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, 'whsec_wrong', time() ) ) ) )[0] );
	rig_check( 'no signature → 400', 400, $rig_post( 'npmp_stripe_webhook', $payload, $json )[0] );
	rig_check( 'empty body → 400', 400, $rig_post( 'npmp_stripe_webhook', '', $json )[0] );
	$tampered = str_replace( '2500', '250000', $payload );
	rig_check( 'body changed after signing → 403', 403, $rig_post( 'npmp_stripe_webhook', $tampered, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ) )[0] );
	rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );

	if ( $pro_on ) {
		$gift    = rig_gift( 'httppro' );
		$payload = file_get_contents( $gift['payload'] );
		rig_check( "Pro's address records a gift", $pro_ok, $said( $rig_post( 'npmp_stripe_recurring_webhook', $payload, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ) ) ) );
		rig_check( "Pro's address: one donation row", 1, rig_rows( $gift['sid'] ) );
		rig_check( "Pro's address: thanked once", 1, rig_count( 'mail', $gift['email'] ) );
		rig_check( "free address after Pro's: duplicate event", '200 Event already processed.', $said( $rig_post( 'npmp_stripe_webhook', $payload, array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ) ) ) );
		rig_check( 'still one donation row', 1, rig_rows( $gift['sid'] ) );
	}

	// Four deliveries of one event at once, across both addresses with Pro.
	$gift    = rig_gift( 'httprace' );
	$payload = file_get_contents( $gift['payload'] );
	$multi   = curl_multi_init();
	$handles = array();
	foreach ( $pro_on ? array( 'npmp_stripe_webhook', 'npmp_stripe_recurring_webhook', 'npmp_stripe_webhook', 'npmp_stripe_recurring_webhook' ) : array_fill( 0, 4, 'npmp_stripe_webhook' ) as $action ) {
		$ch = curl_init( "$rig_url/wp-admin/admin-ajax.php?action=$action" );
		curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => array_merge( $json, array( 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ) ), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
		curl_multi_add_handle( $multi, $ch );
		$handles[] = $ch;
	}
	do {
		$status = curl_multi_exec( $multi, $active );
		if ( $active ) {
			curl_multi_select( $multi, 1.0 );
		}
	} while ( $active && CURLM_OK === $status );
	$codes = array();
	foreach ( $handles as $ch ) {
		$codes[] = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_multi_remove_handle( $multi, $ch );
	}
	curl_multi_close( $multi );
	rig_check( 'four deliveries at once: all answer 200', array( 200, 200, 200, 200 ), $codes );
	rig_check( 'four deliveries at once: one donation row', 1, rig_rows( $gift['sid'] ) );
	rig_check( 'four deliveries at once: npmp_donation_recorded once', 1, rig_count( 'recorded', $gift['email'] ) );
	rig_check( 'four deliveries at once: thanked ' . ( $pro_on ? 'once' : 'by Pro only' ), $thanks, rig_count( 'mail', $gift['email'] ) );
	rig_check( 'four deliveries at once: automation queued ' . ( $autos_on ? 'once' : 'never' ), $queued, rig_count( 'queue', $gift['email'] ) );
} else {
	echo "\n== 6. Over HTTP: skipped (set NPMP_RIG_URL to the served site) ==\n";
}

// --- 7. Two sites, one Stripe account, over HTTP ----------------------------
$rig_other_wp  = (string) getenv( 'NPMP_RIG_OTHER_WP' );
$rig_other_url = rtrim( (string) getenv( 'NPMP_RIG_OTHER_URL' ), '/' );
if ( '' !== $rig_url && '' !== $rig_other_wp && '' !== $rig_other_url ) {
	echo "\n== 7. Two sites on one Stripe account: every event goes to both ($rig_url and $rig_other_url) ==\n";
	// Stripe sends every event on the account to every endpoint, so each
	// site gets the other's sessions. Both sites save the same secret here,
	// so the signature passes at both, and only the site tag decides.
	$other = json_decode( rig_other( 'setup', $secret ), true );
	rig_check( 'the second site has its own tag', true, is_array( $other ) && '' !== $other['tag'] && npmp_stripe_site_tag() !== $other['tag'] );
	$other_plan = array( 'sids' => array(), 'emails' => array(), 'events' => array(), 'prev' => $other['prev'] ?? array() );

	$post_to = function ( $base, $action, $payload ) use ( $secret ) {
		$ch = curl_init( "$base/wp-admin/admin-ajax.php?action=$action" );
		curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'Stripe-Signature: ' . rig_sign( $payload, $secret, time() ) ), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
		$resp = (string) curl_exec( $ch );
		$data = json_decode( $resp, true );
		return (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ) . ' ' . ( $data['data']['message'] ?? $resp );
	};
	$foreign = "200 Another site's gift. Nothing to record.";

	// A gift from each site's form.
	$mine   = rig_gift( 'twosite_a' );
	$theirs = rig_gift(
		'twosite_b',
		array(
			'metadata'    => array( 'gateway' => 'stripe', 'frequency' => 'one_time', 'npmp_site' => $other['tag'] ),
			'success_url' => $other['home'] . '/?npmp_donation=success&npmp_session_id={CHECKOUT_SESSION_ID}',
		)
	);
	$other_plan['sids']   = array( $mine['sid'], $theirs['sid'] );
	$other_plan['emails'] = array( $mine['email'], $theirs['email'] );
	$other_plan['events'] = array( $mine['evt'], $theirs['evt'] );
	foreach ( array( $mine, $theirs ) as $gift ) {
		$payload = file_get_contents( $gift['payload'] );
		$here    = $post_to( $rig_url, 'npmp_stripe_webhook', $payload );
		$there   = $post_to( $rig_other_url, 'npmp_stripe_webhook', $payload );
		if ( $gift === $mine ) {
			rig_check( 'this site\'s gift, at this site: recorded', $free_ok, $here );
			rig_check( 'this site\'s gift, at the second site: another site\'s gift', $foreign, $there );
		} else {
			rig_check( 'the second site\'s gift, at this site: another site\'s gift', $foreign, $here );
			rig_check( 'the second site\'s gift, at the second site: recorded', $free_ok, $there );
		}
	}
	rig_check( 'this site: one row for its own gift', 1, rig_rows( $mine['sid'] ) );
	rig_check( 'this site: no row for the second site\'s gift', 0, rig_rows( $theirs['sid'] ) );
	rig_check( 'the second site: one row for its own gift', '1', rig_other( 'rows', $theirs['sid'] ) );
	rig_check( 'the second site: no row for this site\'s gift', '0', rig_other( 'rows', $mine['sid'] ) );
	rig_check( 'this site: the second site\'s donor is not a contact here', false, rig_member( $theirs['email'] ) );
	rig_check( 'the second site: this site\'s donor is not a contact there', 'no', rig_other( 'member', $mine['email'] ) );
	// Both sites log to the same file, so each donor's count covers both.
	rig_check( 'this site\'s donor thanked ' . ( $pro_on ? 'once, from one site' : 'by Pro only, so not here' ), $thanks, rig_count( 'mail', $mine['email'] ) );
	rig_check( 'the second site\'s donor thanked ' . ( ! empty( $other['pro'] ) ? 'once, from one site' : 'by Pro only, so not here' ), ! empty( $other['pro'] ) ? 1 : 0, rig_count( 'mail', $theirs['email'] ) );
	rig_check( 'each gift recorded once across both sites', array( 1, 1 ), array( rig_count( 'recorded', $mine['email'] ), rig_count( 'recorded', $theirs['email'] ) ) );

	if ( $pro_on && ! empty( $other['pro'] ) ) {
		// Pro's older address on both sites, and a dues join.
		$mine2  = rig_gift( 'twosite_a2' );
		$dues_b = rig_gift(
			'twosite_bdues',
			array(
				'metadata'       => array( 'npmp_source' => 'dues', 'npmp_level' => 'Rig Gold', 'npmp_name' => 'Rig Joiner', 'npmp_frequency' => 'one_time', 'npmp_amount' => 2500, 'npmp_site' => $other['tag'] ),
				'payment_intent' => "pi_rigwh{$run}twosite_bdues",
				'success_url'    => $other['home'] . '/join/?npmp_join=success',
			)
		);
		$other_plan['sids'][]   = $mine2['sid'];
		$other_plan['emails'][] = $mine2['email'];
		$other_plan['emails'][] = $dues_b['email'];
		$other_plan['events'][] = $mine2['evt'];
		$other_plan['events'][] = $dues_b['evt'];
		$payload                = file_get_contents( $mine2['payload'] );
		rig_check( "this site's gift at the second site's older address: 200", $pro_ok, $post_to( $rig_other_url, 'npmp_stripe_recurring_webhook', $payload ) );
		rig_check( 'the second site: no row for it', '0', rig_other( 'rows', $mine2['sid'] ) );
		rig_check( "this site's gift at this site's older address: 200", $pro_ok, $post_to( $rig_url, 'npmp_stripe_recurring_webhook', $payload ) );
		rig_check( 'this site: one row for it', 1, rig_rows( $mine2['sid'] ) );
		$payload = file_get_contents( $dues_b['payload'] );
		rig_check( "the second site's dues join at this site's older address: 200", $pro_ok, $post_to( $rig_url, 'npmp_stripe_recurring_webhook', $payload ) );
		rig_check( "the second site's dues join: no member made here", false, rig_member( $dues_b['email'] ) );
		rig_check( "the second site's dues join: no welcome email from here", 0, rig_count( 'mail', $dues_b['email'] ) );
	}

	$plan_file = "$rig_dir/other-cleanup.json";
	file_put_contents( $plan_file, wp_json_encode( $other_plan ) );
	rig_check( 'the second site: cleaned up', 'clean', rig_other( 'cleanup', $plan_file ) );
	foreach ( $other_plan['sids'] as $sid ) {
		rig_check( "the second site: no rig row left for $sid", '0', rig_other( 'rows', $sid ) );
	}
	foreach ( $other_plan['emails'] as $email ) {
		rig_check( "the second site: no rig contact left for $email", 'no', rig_other( 'member', $email ) );
	}
} else {
	echo "\n== 7. Two sites over HTTP: skipped (set NPMP_RIG_URL, NPMP_RIG_OTHER_WP, and NPMP_RIG_OTHER_URL) ==\n";
}
if ( file_exists( $rig_mu ) ) {
	unlink( $rig_mu );
}
rig_check( 'no lock row left in the options table', 0, rig_locks() );

// --- Clean up --------------------------------------------------------------
// This process loaded the options before the children queued anything, so
// its copy of the cron option is stale. Read the database again.
wp_cache_flush();
foreach ( $sids as $sid ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", NPMP_Donation_Manager::META_TXN_ID, $sid ) ) as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	delete_transient( 'npmp_stripe_fin_' . md5( $sid ) );
}
foreach ( $emails as $email ) {
	$member = $mm->get_member_by_email( $email );
	if ( $member && ! empty( $member->id ) ) {
		$mm->delete_member( (int) $member->id );
	}
}
if ( $pro_on ) {
	$claims = $wpdb->prefix . 'npmp_stripe_events';
	foreach ( $event_ids as $evt ) {
		$wpdb->delete( $claims, array( 'event_id' => $evt ), array( '%s' ) );
	}
}
$want = array_flip( $emails );
foreach ( (array) _get_cron_array() as $ts => $hooks ) {
	foreach ( $hooks['npmp_send_automation_email'] ?? array() as $ev ) {
		if ( isset( $want[ strtolower( (string) ( $ev['args'][1] ?? '' ) ) ] ) ) {
			wp_unschedule_event( $ts, 'npmp_send_automation_email', $ev['args'] );
		}
	}
}
if ( $autos_on ) {
	NPMP_Automation_Engine::get_instance()->delete_automation( 'rigwh_gift' );
}
foreach ( $prev as $opt => $value ) {
	null === $value ? delete_option( $opt ) : update_option( $opt, $value );
}
$child_stderr = file_exists( "$rig_dir/stderr.txt" ) ? trim( (string) file_get_contents( "$rig_dir/stderr.txt" ) ) : '';
array_map( 'unlink', glob( "$rig_dir/*" ) );
rmdir( $rig_dir );

$left = 0;
foreach ( $sids as $sid ) {
	$left += rig_rows( $sid );
}
rig_check( 'clean up: no rig donations left', 0, $left );
rig_check( 'clean up: no rig contacts left', 0, count( array_filter( $emails, 'rig_member' ) ) );
wp_cache_flush();
$left = 0;
foreach ( (array) _get_cron_array() as $hooks ) {
	foreach ( $hooks['npmp_send_automation_email'] ?? array() as $ev ) {
		$left += isset( $want[ strtolower( (string) ( $ev['args'][1] ?? '' ) ) ] ) ? 1 : 0;
	}
}
rig_check( 'clean up: no rig automation emails left in WP-Cron', 0, $left );
rig_check( 'child processes wrote nothing to stderr', '', $child_stderr );
$debug_new = file_exists( $debug_log ) ? (string) file_get_contents( $debug_log, false, null, $debug_at ) : '';
rig_check( 'no plugin lines in debug.log from this run', array(), array_values( array_filter( explode( "\n", $debug_new ), function ( $l ) { return false !== stripos( $l, 'nonprofit-manager' ); } ) ) );
rig_check( 'no PHP notices from the plugins', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
