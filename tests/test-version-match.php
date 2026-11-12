<?php
/**
 * The free/Pro version notice offers the click that fixes the mismatch, and
 * shows nothing when there's nothing to click (since 2026.11.6).
 *
 * Until then a site on free 2026.11.5 and Pro 2026.11.4 got a red banner from
 * each plugin saying features "may not work", while wordpress.org was still
 * holding the update that would have fixed it. A customer took it to mean his
 * events had lost a button.
 *
 * Free and Pro versions are constants and a static cache, so each scenario
 * runs in its own PHP process.
 *
 * Run: php tests/test-version-match.php
 */

if ( getenv( 'NPMP_T_SCENARIO' ) ) {
	$t = json_decode( getenv( 'NPMP_T_SCENARIO' ), true );
	define( 'ABSPATH', '/tmp/' );
	if ( '' !== $t['pro'] ) {
		define( 'NPMP_PRO_VERSION', $t['pro'] );
	}
	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function esc_html_e( $s, $d = null ) { echo $s; }
	function esc_html( $s ) { return $s; }
	function esc_url( $s ) { return $s; }
	function add_action() {}
	function add_filter() {}
	function current_user_can( $c ) { return true; }
	function plugin_basename( $f ) { return 'nonprofit-manager-pro/nonprofit-manager-pro.php'; }
	function self_admin_url( $p ) { return 'https://club.example.org/wp-admin/' . $p; }
	function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=' . md5( $a ); }
	function get_plugin_data( $f ) { return array( 'Version' => $GLOBALS['t']['free'] ); }
	function get_site_transient( $k ) {
		return (object) array( 'response' => array_map( function ( $v ) { return (object) array( 'new_version' => $v ); }, $GLOBALS['t']['offers'] ) );
	}
	if ( null !== $t['pro_supported'] ) {
		function npmp_pro_free_version_supported() { return $GLOBALS['t']['pro_supported']; }
	}
	$GLOBALS['t'] = $t;
	require __DIR__ . '/../includes/npmp-version.php';
	ob_start();
	npmp_version_lockstep_notice();
	$html   = ob_get_clean();
	$health = npmp_lockstep_health_test();
	echo json_encode( array( 'action' => npmp_version_match_action(), 'html' => $html, 'health' => $health['status'] ) );
	exit;
}

require_once __DIR__ . '/bootstrap.php';

$FREE = 'nonprofit-manager/nonprofit-manager.php';
$PRO  = 'nonprofit-manager-pro/nonprofit-manager-pro.php';

function run( $free, $pro, $offers = array(), $pro_supported = true ) {
	$env = json_encode( compact( 'free', 'pro', 'offers', 'pro_supported' ) );
	$out = shell_exec( 'NPMP_T_SCENARIO=' . escapeshellarg( $env ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1' );
	$res = json_decode( (string) $out, true );
	if ( ! is_array( $res ) ) {
		echo "  scenario output: $out\n";
		return array( 'action' => 'ERROR', 'html' => '', 'health' => '' );
	}
	return $res;
}

echo "\n== 1. Matched, or no Pro: nothing shows ==\n";
$r = run( '2026.11.6', '2026.11.6' );
check( 'matched: no action', null, $r['action'] );
check( 'matched: no notice', '', trim( $r['html'] ) );
$r = run( '2026.11.6', '' );
check( 'free alone: no notice', '', trim( $r['html'] ) );

echo "\n== 2. Pro behind with its update waiting: Update Pro to match ==\n";
$r = run( '2026.11.5', '2026.11.4', array( $PRO => '2026.11.5' ) );
check( 'label', 'Update Pro to match', $r['action']['label'] ?? null );
check( 'goes to the one-plugin updater for Pro', true, false !== strpos( $r['action']['url'] ?? '', 'update.php?action=upgrade-plugin&plugin=nonprofit-manager-pro%2Fnonprofit-manager-pro.php' ) );
check( 'notice shows the button', true, false !== strpos( $r['html'], 'Update Pro to match' ) );
check( 'notice says both keep working', true, false !== strpos( $r['html'], 'Both keep working' ) );
check( 'never says features may not work', false, (bool) preg_match( '/may not work/i', $r['html'] ) );
check( 'a warning, not an error', true, false !== strpos( $r['html'], 'notice-warning' ) );
check( 'Site Health: recommended, not critical', 'recommended', $r['health'] );

echo "\n== 3. Pro behind, nothing listed yet: check for it ==\n";
$r = run( '2026.11.5', '2026.11.4' );
check( 'label', 'Check for the Pro update', $r['action']['label'] ?? null );
check( 'forces an update check, with its nonce', true, false !== strpos( $r['action']['url'] ?? '', 'update-core.php?force-check=1&_wpnonce=' ) );
$r = run( '2026.11.6', '2026.11.4', array( $PRO => '2026.11.5' ) );
check( 'an offer that still falls short is not "to match"', 'Check for the Pro update', $r['action']['label'] ?? null );

echo "\n== 4. Free behind with its update waiting: Update Nonprofit Manager to match ==\n";
$r = run( '2026.11.5', '2026.11.6', array( $FREE => '2026.11.6' ) );
check( 'label', 'Update Nonprofit Manager to match', $r['action']['label'] ?? null );
check( 'goes to the one-plugin updater for free', true, false !== strpos( $r['action']['url'] ?? '', 'plugin=nonprofit-manager%2Fnonprofit-manager.php' ) );

echo "\n== 5. Free behind during wordpress.org's hold: nothing to click, nothing shows ==\n";
$r = run( '2026.11.5', '2026.11.6' );
check( 'no action', null, $r['action'] );
check( 'no notice', '', trim( $r['html'] ) );

echo "\n== 6. Below Pro's minimum: no claim that both keep working ==\n";
$r = run( '2026.11.1', '2026.11.6', array( $FREE => '2026.11.6' ), false );
check( 'still offers the update', 'Update Nonprofit Manager to match', $r['action']['label'] ?? null );
check( 'does not say both keep working', false, false !== strpos( $r['html'], 'Both keep working' ) );
check( 'Site Health: critical', 'critical', $r['health'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
