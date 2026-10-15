<?php
/**
 * Which mail takes the configured From, and which mail rides the SMTP
 * connection (includes/email/smtp.php, includes/email/helpers.php).
 *
 * Until 2026.10.8 the SMTP transport called setFrom() in phpmailer_init for
 * every wp_mail() on the site, so WordPress's own notices and other plugins'
 * mail left as the Nonprofit Manager sender even though the From filters
 * beside it only touched the plugin's own mail. Pinned here: a message sent
 * through npmp_send_mail() takes the configured From, a plain wp_mail() keeps
 * WordPress's From (or the sender's own), both go out over the configured SMTP
 * server, and the "Send all site email from this address" setting (off by
 * default) puts the configured From on everything. Amazon SES builds its SMTP
 * connection from the IAM key pair, and the derived SMTP password matches
 * AWS's documented algorithm.
 *
 * wp_mail() below copies the order core follows: From header or WordPress's
 * default, then the wp_mail_from filters, then phpmailer_init. A real
 * WordPress run with a local SMTP server is in tests/rig/mail-from-scope.php.
 *
 * Run: php tests/test-mail-from-scope.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_hooks']   = array();
$GLOBALS['t_sent']    = array();
$GLOBALS['t_is_pro']  = true;

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][ $hook ][ $priority ][] = array( $callback, $args );
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}
function apply_filters( $hook, $value, ...$rest ) {
	if ( empty( $GLOBALS['t_hooks'][ $hook ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['t_hooks'][ $hook ] );
	foreach ( $GLOBALS['t_hooks'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $rest ), 0, $cb[1] ) );
		}
	}
	return $value;
}
function do_action_ref_array( $hook, $args ) {
	if ( empty( $GLOBALS['t_hooks'][ $hook ] ) ) {
		return;
	}
	foreach ( $GLOBALS['t_hooks'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			call_user_func_array( $cb[0], $args );
		}
	}
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function npmp_is_pro() {
	return $GLOBALS['t_is_pro'];
}

/**
 * Stands in for PHPMailer: records what the plugin sets on it.
 */
class T_Mailer {
	public $Mailer     = 'mail';
	public $From       = '';
	public $FromName   = '';
	public $Sender     = '';
	public $Host       = '';
	public $Port       = 25;
	public $SMTPAuth   = false;
	public $Username   = '';
	public $Password   = '';
	public $SMTPSecure = '';
	public $SMTPAutoTLS = true;

	public function isSMTP() {
		$this->Mailer = 'smtp';
	}
	public function setFrom( $address, $name = '', $auto = true ) {
		$this->From     = $address;
		$this->FromName = $name;
		return true;
	}
}

/**
 * Core's wp_mail(), reduced to the From handling and the phpmailer_init call.
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$from_email = 'wordpress@example.org';
	$from_name  = 'WordPress';
	foreach ( (array) $headers as $header ) {
		if ( preg_match( '/^From:\s*(.*?)\s*<([^>]+)>\s*$/i', (string) $header, $m ) ) {
			$from_name  = $m[1];
			$from_email = $m[2];
		}
	}
	$mailer = new T_Mailer();
	$mailer->setFrom( apply_filters( 'wp_mail_from', $from_email ), apply_filters( 'wp_mail_from_name', $from_name ), false );
	do_action_ref_array( 'phpmailer_init', array( &$mailer ) );
	$GLOBALS['t_sent'][] = $mailer;
	return true;
}

require dirname( __DIR__ ) . '/includes/email/helpers.php';
require dirname( __DIR__ ) . '/includes/email/smtp.php';

/**
 * Send one message and return the mailer it went through.
 */
function t_send( $as_plugin, $headers = '' ) {
	$GLOBALS['t_sent'] = array();
	if ( $as_plugin ) {
		npmp_send_mail( 'donor@example.org', 'Thanks', 'Body', $headers );
	} else {
		wp_mail( 'admin@example.org', 'Core notice', 'Body', $headers );
	}
	return $GLOBALS['t_sent'][0];
}

$smtp_settings = array(
	'provider'        => 'smtp',
	'from_email'      => 'hello@example.org',
	'from_name'       => 'Example Council',
	'force_from'      => 1,
	'set_return_path' => 1,
	'smtp'            => array(
		'host'       => 'email-smtp.us-west-2.amazonaws.com',
		'port'       => 2587,
		'encryption' => 'tls',
		'auth'       => 1,
		'username'   => 'AKIAEXAMPLE',
		'password'   => 'smtp-secret',
		'auto_tls'   => 1,
	),
	'aws'             => array( 'region' => 'us-west-2' ),
);
update_option( 'npmp_email_settings', $smtp_settings );

echo "Custom SMTP, default settings\n";
check( 'from_all_mail defaults to off', 0, npmp_email_get_settings()['from_all_mail'] );
$m = t_send( true );
check( 'plugin mail: From address', 'hello@example.org', $m->From );
check( 'plugin mail: From name', 'Example Council', $m->FromName );
check( 'plugin mail: return path', 'hello@example.org', $m->Sender );
check( 'plugin mail: sent over SMTP', 'smtp', $m->Mailer );
check( 'plugin mail: SMTP host', 'email-smtp.us-west-2.amazonaws.com', $m->Host );
check( 'plugin mail: SMTP port', 2587, $m->Port );
check( 'plugin mail: SMTP login', 'AKIAEXAMPLE', $m->Username );

$m = t_send( false );
check( 'core mail: keeps WordPress From address', 'wordpress@example.org', $m->From );
check( 'core mail: keeps WordPress From name', 'WordPress', $m->FromName );
check( 'core mail: no return path rewrite', '', $m->Sender );
check( 'core mail: still sent over SMTP', 'smtp', $m->Mailer );
check( 'core mail: same SMTP host', 'email-smtp.us-west-2.amazonaws.com', $m->Host );
check( 'core mail: same SMTP login', 'AKIAEXAMPLE', $m->Username );

$m = t_send( false, array( 'From: Forms <forms@example.org>' ) );
check( 'other plugin mail: keeps its own From', 'forms@example.org', $m->From );
check( 'other plugin mail: keeps its own name', 'Forms', $m->FromName );
check( 'other plugin mail: still sent over SMTP', 'smtp', $m->Mailer );

check( 'outside a send, From does not apply', false, npmp_email_from_applies() );
$m = t_send( true );
check( 'after a plugin send, the next core mail keeps its From', 'wordpress@example.org', t_send( false )->From );

echo "\n\"Send all site email from this address\" on\n";
update_option( 'npmp_email_settings', array_merge( $smtp_settings, array( 'from_all_mail' => 1 ) ) );
$m = t_send( false );
check( 'core mail: takes the configured From', 'hello@example.org', $m->From );
check( 'core mail: takes the configured name', 'Example Council', $m->FromName );
check( 'core mail: return path follows', 'hello@example.org', $m->Sender );
$m = t_send( false, array( 'From: Forms <forms@example.org>' ) );
check( 'other plugin mail: takes the configured From', 'hello@example.org', $m->From );
update_option( 'npmp_email_settings', array_merge( $smtp_settings, array( 'from_all_mail' => 1, 'force_from' => 0, 'provider' => 'wordpress' ) ) );
$m = t_send( false );
check( 'default mailer: core mail takes the configured From', 'hello@example.org', $m->From );
check( 'default mailer: no SMTP', 'mail', $m->Mailer );

echo "\nWordPress default mailer\n";
update_option( 'npmp_email_settings', array_merge( $smtp_settings, array( 'provider' => 'wordpress' ) ) );
$m = t_send( true );
check( 'plugin mail: From from the filter', 'hello@example.org', $m->From );
check( 'plugin mail: no SMTP', 'mail', $m->Mailer );
$m = t_send( false );
check( 'core mail: keeps WordPress From', 'wordpress@example.org', $m->From );

echo "\nAmazon SES with an IAM key pair\n";
update_option( 'npmp_aws_ses_access_key', 'AKIAIOSFODNN7EXAMPLE' );
update_option( 'npmp_aws_ses_secret_key', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
check( 'SES SMTP password, us-east-1 (AWS algorithm)', 'BLBM/9hSUELfq8Gw+rU1YcBjkOxGbhT2XG763xVLGWL9', npmp_email_ses_smtp_password( 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1' ) );
check( 'SES SMTP password, us-west-2 (AWS algorithm)', 'BF2PynzbSCAjX08zhZZnP/kW+T9P5zs/1Er0pi5vTEmd', npmp_email_ses_smtp_password( 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-west-2' ) );
check( 'SES SMTP password, no key', '', npmp_email_ses_smtp_password( '', 'us-west-2' ) );

$ses = array_merge( $smtp_settings, array( 'provider' => 'aws_ses', 'aws' => array( 'region' => 'us-west-2', 'port' => 2587 ) ) );
$ses['smtp'] = array_merge( $ses['smtp'], array( 'host' => '', 'username' => '', 'password' => '' ) );
update_option( 'npmp_email_settings', $ses );
$m = t_send( false );
check( 'SES core mail: sent over SMTP', 'smtp', $m->Mailer );
check( 'SES core mail: regional host', 'email-smtp.us-west-2.amazonaws.com', $m->Host );
check( 'SES core mail: chosen port', 2587, $m->Port );
check( 'SES core mail: STARTTLS on 2587', 'tls', $m->SMTPSecure );
check( 'SES core mail: login is the access key ID', 'AKIAIOSFODNN7EXAMPLE', $m->Username );
check( 'SES core mail: derived SMTP password', 'BF2PynzbSCAjX08zhZZnP/kW+T9P5zs/1Er0pi5vTEmd', $m->Password );
check( 'SES core mail: keeps WordPress From', 'wordpress@example.org', $m->From );
$m = t_send( true );
check( 'SES plugin mail: configured From', 'hello@example.org', $m->From );

$ses['aws']['port'] = 2465;
update_option( 'npmp_email_settings', $ses );
check( 'SES on 2465 wraps the connection in TLS', 'ssl', t_send( false )->SMTPSecure );
$ses['aws']['port'] = 25;
update_option( 'npmp_email_settings', $ses );
check( 'SES on an unsupported port falls back to 587', 587, t_send( false )->Port );

$GLOBALS['t_is_pro'] = false;
check( 'SES without Pro: default mailer', 'mail', t_send( false )->Mailer );
$GLOBALS['t_is_pro'] = true;
update_option( 'npmp_aws_ses_secret_key', '' );
check( 'SES with no secret key: default mailer', 'mail', t_send( false )->Mailer );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
