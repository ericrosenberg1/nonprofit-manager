<?php
/**
 * Newsletter Reports show clicks (includes/email-newsletter/reports.php).
 *
 * Click tracking wrote one row per click to wp_npmp_newsletter_clicks, but no
 * screen read them. The report has a Clicks column now, counted from that
 * table for the one newsletter.
 *
 * Run: php tests/test-newsletter-reports.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
$GLOBALS['t_cache'] = array();
function wp_cache_get( $key, $group ) {
	return $GLOBALS['t_cache'][ $group . ':' . $key ] ?? false;
}
function wp_cache_set( $key, $value, $group, $ttl = 0 ) {
	$GLOBALS['t_cache'][ $group . ':' . $key ] = $value;
}
function absint( $v ) {
	return abs( (int) $v );
}

class T_Wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array( 7 => 3, 8 => 0 );
	public function prepare( $sql, ...$args ) {
		return vsprintf( str_replace( '%d', '%d', $sql ), $args );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/FROM wp_npmp_newsletter_clicks WHERE newsletter_id = (\d+)/', $sql, $m ) ) {
			return $this->rows[ (int) $m[1] ] ?? 0;
		}
		return 99;
	}
}
$GLOBALS['wpdb'] = new T_Wpdb();

require_once dirname( __DIR__ ) . '/includes/email-newsletter/reports.php';

$stats = NPMP_Newsletter_Stats::get_instance();
check( 'clicks for newsletter 7 come from the clicks table', 3, $stats->get_clicks_count( 7 ) );
check( 'query reads wp_npmp_newsletter_clicks for that newsletter', 'SELECT COUNT(*) FROM wp_npmp_newsletter_clicks WHERE newsletter_id = 7', end( $GLOBALS['wpdb']->queries ) );
check( 'a newsletter with no clicks counts 0', 0, $stats->get_clicks_count( 8 ) );
$before = count( $GLOBALS['wpdb']->queries );
check( 'second read comes from cache', 3, $stats->get_clicks_count( 7 ) );
check( 'no second query', $before, count( $GLOBALS['wpdb']->queries ) );

$src = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/reports.php' );
check( 'report table has a Clicks header', true, false !== strpos( $src, "esc_html__('Clicks', 'nonprofit-manager')" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
