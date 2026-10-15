<?php
// Usage:
//   python3 tests/rig/smtp-sink.py 10025 /tmp/npmp-sink.jsonl &
//   NPMP_RIG_WP=/path/to/wp NPMP_RIG_SINK=/tmp/npmp-sink.jsonl php tests/rig/mail-from-scope.php
//
// The From scope on a real WordPress with core's wp_mail() and PHPMailer,
// sending to a local SMTP server (smtp-sink.py) that records each message.
// Checks that Nonprofit Manager's own mail carries the configured From, that
// WordPress's own mail keeps WordPress's From, that both travel over the
// configured SMTP server, and that "Send all site email from this address"
// puts the configured From on everything. Restores the email settings it
// found. Not run by the pre-push gate (it needs MySQL and WordPress).
$wp_root   = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$sink_file = getenv( 'NPMP_RIG_SINK' ) ?: '/tmp/npmp-sink.jsonl';
$sink_port = (int) ( getenv( 'NPMP_RIG_SINK_PORT' ) ?: 10025 );
$_SERVER['HTTP_HOST']       = 'npmp-rig.test';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? var_export( $actual, true ) : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

/**
 * Send one message and return what the SMTP server received for it.
 */
function rig_send( $as_plugin, $sink_file, $subject ) {
	@unlink( $sink_file );
	$sent = $as_plugin
		? npmp_send_mail( 'donor@example.org', $subject, 'Body' )
		: wp_mail( 'admin@example.org', $subject, 'Body' );
	clearstatcache();
	$lines = file_exists( $sink_file ) ? file( $sink_file, FILE_IGNORE_NEW_LINES ) : array();
	$msg   = $lines ? json_decode( end( $lines ), true ) : null;
	$from  = '';
	if ( $msg && preg_match( '/^From:\s*(.+)$/mi', $msg['data'], $m ) ) {
		$from = trim( $m[1] );
	}
	return array(
		'sent'      => $sent,
		'received'  => (bool) $msg,
		'auth'      => $msg['auth'] ?? null,
		'envelope'  => $msg['mail_from'] ?? '',
		'from'      => $from,
	);
}

$saved = get_option( 'npmp_email_settings' );
$base  = array(
	'provider'        => 'smtp',
	'from_email'      => 'hello@npmp-rig.test',
	'from_name'       => 'Rig Council',
	'force_from'      => 1,
	'set_return_path' => 0,
	'smtp'            => array(
		'host'       => '127.0.0.1',
		'port'       => $sink_port,
		'encryption' => 'none',
		'auth'       => 1,
		'username'   => 'rig-user',
		'password'   => 'rig-pass',
		'auto_tls'   => 0,
	),
	'aws'             => array( 'region' => 'us-west-2', 'port' => 587 ),
);
$core_from = 'WordPress <wordpress@npmp-rig.test>';

try {
	echo "== Custom SMTP, defaults ==\n";
	update_option( 'npmp_email_settings', $base );
	rig_check( 'from_all_mail defaults to off', 0, npmp_email_get_settings()['from_all_mail'] );

	$r = rig_send( true, $sink_file, 'Plugin mail' );
	rig_check( 'plugin mail: wp_mail() reports sent', true, $r['sent'] );
	rig_check( 'plugin mail: reached the SMTP server', true, $r['received'] );
	rig_check( 'plugin mail: logged in to the SMTP server', true, $r['auth'] );
	rig_check( 'plugin mail: From header', 'Rig Council <hello@npmp-rig.test>', $r['from'] );
	rig_check( 'plugin mail: envelope sender', 'hello@npmp-rig.test', $r['envelope'] );

	$r = rig_send( false, $sink_file, 'Core notice' );
	rig_check( 'core mail: wp_mail() reports sent', true, $r['sent'] );
	rig_check( 'core mail: reached the same SMTP server', true, $r['received'] );
	rig_check( 'core mail: logged in to the SMTP server', true, $r['auth'] );
	rig_check( 'core mail: keeps WordPress From header', $core_from, $r['from'] );
	rig_check( 'core mail: envelope sender is WordPress From', 'wordpress@npmp-rig.test', $r['envelope'] );

	echo "\n== Send all site email from this address ==\n";
	update_option( 'npmp_email_settings', array_merge( $base, array( 'from_all_mail' => 1 ) ) );
	$r = rig_send( false, $sink_file, 'Core notice, site-wide From' );
	rig_check( 'core mail: takes the configured From', 'Rig Council <hello@npmp-rig.test>', $r['from'] );
	rig_check( 'core mail: still over SMTP', true, $r['received'] );
} finally {
	update_option( 'npmp_email_settings', $saved );
}

printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
