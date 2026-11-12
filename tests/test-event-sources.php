<?php
/**
 * Events Manager and Sugar Calendar sources: the pure parts
 * (includes/import/events-manager-source.php, event-importer.php).
 *
 * Run: php tests/test-event-sources.php
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( array( 'add_filter', 'add_action' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function {$fn}() { return true; }" );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/tec-importer.php';
require_once $root . '/includes/import/event-importer.php';
require_once $root . '/includes/import/events-manager-source.php';
require_once $root . '/includes/import/sugar-calendar-source.php';

// Status: post status, event_status, private, active status, status feature on.
check( 'published post stays published', 'publish', npmp_em_status( 'publish', 1, 0, 1, false ) );
check( 'trashed post is skipped', '', npmp_em_status( 'trash', -1, 0, 1, false ) );
check( 'post-less trashed date is skipped', '', npmp_em_status( '', -1, 0, 1, false ) );
check( 'post-less live date is published', 'publish', npmp_em_status( '', 1, 0, 1, false ) );
check( 'post-less private date is private', 'private', npmp_em_status( '', 1, 1, 1, false ) );
check( 'post-less pending date is pending', 'pending', npmp_em_status( '', 0, 0, 1, false ) );
check( 'post-less draft date is draft', 'draft', npmp_em_status( '', null, 0, 1, false ) );
check( 'cancelled event becomes a draft', 'draft', npmp_em_status( 'publish', 1, 0, 0, true ) );
check( 'cancel flag ignored with the feature off', 'publish', npmp_em_status( 'publish', 1, 0, 0, false ) );
check( 'draft post stays draft', 'draft', npmp_em_status( 'draft', null, 0, 1, false ) );

// Old paths.
check( 'default events slug', '/events/spring-gala', npmp_event_import_old_path( 'events', 'spring-gala' ) );
check( 'custom slug with slashes', '/whats-on/spring-gala', npmp_event_import_old_path( '/whats-on/', 'spring-gala' ) );
check( 'empty slug falls back to events', '/events/x', npmp_event_import_old_path( '', 'x' ) );
check( 'empty post name gives no path', '', npmp_event_import_old_path( 'events', '' ) );
check( 'encoded slug decodes and lowercases', '/events/café-night', npmp_event_import_old_path( 'events', 'Caf%C3%A9-Night' ) );

// Secrets stay put.
$secret = array( 'google_maps', 'maps_google_api_key', 'smtp_password', 'smtp_username', 'pro_api_key', 'dbem_oauth', 'license', 'sc_stripe', 'connect_token', 'oauth_connections', 'meeting_password' );
foreach ( array( 'events-manager-source.php', 'sugar-calendar-source.php', 'event-importer.php' ) as $file ) {
	$src = file_get_contents( $root . '/includes/import/' . $file );
	// Code only: drop comments, which name the secrets they avoid.
	$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $src );
	$hit  = '';
	foreach ( $secret as $needle ) {
		if ( false !== stripos( $code, $needle ) ) {
			$hit = $needle;
		}
	}
	check( "{$file} code names no secret", '', $hit );
	check( "{$file} never reads the sugar_calendar option", 0, preg_match( "/get_option\(\s*'sugar_calendar'/", $code ) );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
