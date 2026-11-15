<?php
/**
 * npmp_event_import_table() asks the database once per table that exists
 * (includes/import/event-importer.php).
 *
 * Event Organiser and My Calendar look a table up for every imported date,
 * so a 2,000-date calendar ran 2,000 to 6,000 SHOW TABLES queries.
 *
 * Run: php tests/test-event-import-table.php
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

/**
 * $wpdb stand-in that knows which tables exist and counts SHOW TABLES.
 */
class NPMP_Test_Table_Db {
	public $prefix = 'wp_';
	public $tables = array( 'wp_eo_events' );
	public $asked  = 0;
	public function esc_like( $s ) {
		return $s;
	}
	public function prepare( $sql, $arg ) {
		return str_replace( '%s', $arg, $sql );
	}
	public function get_var( $sql ) {
		++$this->asked;
		$name = trim( substr( $sql, strlen( 'SHOW TABLES LIKE ' ) ) );
		return in_array( $name, $this->tables, true ) ? $name : null;
	}
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/tec-importer.php';
require_once $root . '/includes/import/event-importer.php';

$GLOBALS['wpdb'] = new NPMP_Test_Table_Db();

for ( $i = 0; $i < 50; $i++ ) {
	$table = npmp_event_import_table( 'eo_events' );
}
check( 'a table that exists answers with its name', 'wp_eo_events', $table );
check( 'and the database is asked once', 1, $GLOBALS['wpdb']->asked );

check( 'a missing table answers empty', '', npmp_event_import_table( 'my_calendar_locations' ) );
$GLOBALS['wpdb']->tables[] = 'wp_my_calendar_locations';
check( 'a table made later in the request is found', 'wp_my_calendar_locations', npmp_event_import_table( 'my_calendar_locations' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
