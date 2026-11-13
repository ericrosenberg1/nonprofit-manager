<?php
/**
 * Event Organiser, My Calendar, and WP Event Manager sources: the pure parts
 * (includes/import/event-organiser-source.php, my-calendar-source.php,
 * wp-event-manager-source.php, and the engine helpers they added to
 * event-importer.php).
 *
 * Run: php tests/test-more-event-sources.php
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
require_once $root . '/includes/import/event-organiser-source.php';
require_once $root . '/includes/import/my-calendar-source.php';
require_once $root . '/includes/import/wp-event-manager-source.php';

// Event Organiser.
check( 'EO published stays published', 'publish', npmp_eo_status( 'publish' ) );
check( 'EO draft stays draft', 'draft', npmp_eo_status( 'draft' ) );
check( 'EO private stays private', 'private', npmp_eo_status( 'private' ) );
check( 'EO trash is skipped', '', npmp_eo_status( 'trash' ) );
check( 'EO auto-draft is skipped', '', npmp_eo_status( 'auto-draft' ) );
check( 'EO all day as int', true, npmp_eo_all_day( array( 'all_day' => 1 ) ) );
check( 'EO all day as string', true, npmp_eo_all_day( array( 'all_day' => '1' ) ) );
check( 'EO not all day', false, npmp_eo_all_day( array( 'all_day' => 0 ) ) );
check( 'EO not all day as "0"', false, npmp_eo_all_day( array( 'all_day' => '0' ) ) );
check( 'EO no schedule', false, npmp_eo_all_day( '' ) );
check( 'EO default slug', 'events/event', npmp_eo_event_slug( array() ) );
check( 'EO slug from options', 'whats-on', npmp_eo_event_slug( array( 'url_event' => '/whats-on/' ) ) );
check( 'EO empty slug falls back', 'events/event', npmp_eo_event_slug( array( 'url_event' => '' ) ) );
check( 'EO old path', '/events/event/spring-gala', npmp_event_import_old_path( npmp_eo_event_slug( false ), 'spring-gala' ) );

// My Calendar.
check( 'MC published', 'publish', npmp_mc_status( 1, 0 ) );
check( 'MC draft', 'draft', npmp_mc_status( 0, 0 ) );
check( 'MC trash is skipped', '', npmp_mc_status( 2, 0 ) );
check( 'MC cancelled becomes a draft', 'draft', npmp_mc_status( 3, 0 ) );
check( 'MC private', 'private', npmp_mc_status( 4, 0 ) );
check( 'MC personal is private', 'private', npmp_mc_status( '5', 0 ) );
check( 'MC scheduled becomes a draft', 'draft', npmp_mc_status( 6, 0 ) );
check( 'MC spam is skipped', '', npmp_mc_status( 1, 1 ) );
check( 'MC all day', true, npmp_mc_all_day( '00:00:00', '23:59:59' ) );
check( 'MC midnight start alone is not all day', false, npmp_mc_all_day( '00:00:00', '01:00:00' ) );
check( 'MC evening is not all day', false, npmp_mc_all_day( '19:00:00', '21:00:00' ) );
check( 'MC default slug', 'mc-events', npmp_mc_slug( false ) );
check( 'MC slug from options', 'calendar', npmp_mc_slug( array( 'cpt_base' => 'calendar' ) ) );
check( 'MC drops tables only on "true"', true, npmp_mc_drops_tables( array( 'drop_tables' => 'true' ) ) );
check( 'MC keeps tables on "false"', false, npmp_mc_drops_tables( array( 'drop_tables' => 'false' ) ) );
check( 'MC keeps tables with no options', false, npmp_mc_drops_tables( false ) );

// WP Event Manager.
check( 'WPEM published', 'publish', npmp_wpem_status( 'publish', 0 ) );
check( 'WPEM expired is a past event', 'publish', npmp_wpem_status( 'expired', 0 ) );
check( 'WPEM preview becomes a draft', 'draft', npmp_wpem_status( 'preview', 0 ) );
check( 'WPEM pending payment is pending', 'pending', npmp_wpem_status( 'pending_payment', 0 ) );
check( 'WPEM cancelled becomes a draft', 'draft', npmp_wpem_status( 'publish', '1' ) );
check( 'WPEM trash is skipped', '', npmp_wpem_status( 'trash', 0 ) );
check( 'WPEM trash stays skipped when cancelled', '', npmp_wpem_status( 'trash', 1 ) );
check( 'WPEM date and time together', '2027-03-10 18:00:00', npmp_wpem_datetime( '2027-03-10 18:00:00', '18:00', '00:00:00' ) );
check( 'WPEM date alone takes the time field', '2027-03-10 18:30:00', npmp_wpem_datetime( '2027-03-10', '18:30', '00:00:00' ) );
check( 'WPEM date with no time takes the fallback', '2027-03-10 23:59:00', npmp_wpem_datetime( '2027-03-10', '', '23:59:00' ) );
check( 'WPEM single-digit hour', '2027-03-10 09:05:00', npmp_wpem_datetime( '2027-03-10 9:05', '', '00:00:00' ) );
check( 'WPEM unreadable date', '', npmp_wpem_datetime( '10/03/2027', '18:00', '00:00:00' ) );
check( 'WPEM default slug', 'event', npmp_wpem_slug( array() ) );
check( 'WPEM slug from permalinks', 'whats-on', npmp_wpem_slug( array( 'event_base' => 'whats-on' ) ) );
check( 'WPEM per page maps to list length', array( 'list_limit' => array( 'tec' => '25', 'value' => 25 ) ), npmp_wpem_read_settings( '25' ) );
check( 'WPEM per page over 100 caps', 100, npmp_wpem_read_settings( 500 )['list_limit']['value'] );
check( 'WPEM unset per page maps nothing', array(), npmp_wpem_read_settings( null ) );
check( 'WPEM zero per page maps nothing', array(), npmp_wpem_read_settings( '0' ) );

// Engine: links that pick one date.
check( 'path with mc_id', '/mc-events/gala/?mc_id=12', npmp_event_import_path_with_query( '/mc-events/gala', 'mc_id', '12' ) );
check( 'path with query strips odd characters', '/x/?mc_id=12', npmp_event_import_path_with_query( '/x', 'mc_id', '12"><' ) );
check( 'no path, no query path', '', npmp_event_import_path_with_query( '', 'mc_id', '12' ) );
check( 'request with mc_id looks up the date first', array( '/mc-events/gala/?mc_id=12', '/mc-events/gala' ), npmp_event_import_request_paths( '/mc-events/gala/?mc_id=12&utm_source=x', '', array( 'mc_id' ) ) );
check( 'request without the argument looks up the path', array( '/mc-events/gala' ), npmp_event_import_request_paths( '/mc-events/gala/?utm_source=x', '', array( 'mc_id' ) ) );
check( 'request with no registered argument', array( '/mc-events/gala' ), npmp_event_import_request_paths( '/mc-events/gala/?mc_id=12', '', array() ) );
check( 'request under a subdirectory', array( '/mc-events/gala/?mc_id=3', '/mc-events/gala' ), npmp_event_import_request_paths( '/blog/mc-events/gala/?mc_id=3', '/blog', array( 'mc_id' ) ) );
check( 'stored and requested forms match', npmp_event_import_path_with_query( npmp_event_import_old_path( 'mc-events', 'Gala' ), 'mc_id', '7' ), npmp_event_import_request_paths( '/mc-events/gala/?mc_id=7', '', array( 'mc_id' ) )[0] );

// Secrets stay put.
$secret = array( 'google_api_key', 'api_key', 'license', 'licence', 'smtp', 'password', 'stripe', 'woocommerce_' );
foreach ( array( 'event-organiser-source.php', 'my-calendar-source.php', 'wp-event-manager-source.php' ) as $file ) {
	$code = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( $root . '/includes/import/' . $file ) );
	$hit  = '';
	foreach ( $secret as $needle ) {
		if ( false !== stripos( $code, $needle ) ) {
			$hit = $needle;
		}
	}
	check( "{$file} code names no secret", '', $hit );
	check( "{$file} reads tables only through \$wpdb->prefix", 0, preg_match( '/base_prefix/', $code ) );
	check( "{$file} sends no mail", 0, preg_match( '/wp_mail|npmp_send_mail/', $code ) );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
