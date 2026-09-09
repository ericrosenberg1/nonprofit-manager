<?php
/**
 * The Members screen's two totals, get_tags_list() and get_financial_overview()
 * in NPMP_Member_Manager, moved from "load every row and add it up in PHP" to
 * one SQL aggregate each in 2026.09.3. The SQL itself is verified against the
 * old loops on real MySQL 8 on the WP test site: identical output over an
 * empty database, zero, negative, missing, non-numeric and padded amounts,
 * draft, pending, trashed and private posts, another post type carrying the
 * same meta keys, a zeroed post_date_gmt, an empty 30-day period, case and
 * accent variants of a tag, and 4,000 seeded rows (~/Code/MASTER-HANDOFF.md 3.17).
 *
 * What is tested here is what stayed in PHP, and the shape of the SQL that the
 * verification depends on: turning the grouped tag strings into one sorted
 * list, the empty-result and Pro-absent paths, the casts on the way out, and
 * the two traps the queries avoid (SELECT DISTINCT folding case through the
 * collation, and a cutoff that reads the database session timezone).
 *
 * Run: php tests/test-members-aggregates.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// WordPress forces UTC at bootstrap, and the cutoff maths below assumes it.
date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set

/**
 * A $wpdb stand-in. It records every query it is handed and answers with
 * canned rows. prepare() substitutes placeholders the way WordPress does for
 * these two queries (strings quoted, %% collapsed to %) so the SQL that would
 * reach MySQL can be inspected.
 */
class NPMP_Test_WPDB {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
	public $calls    = array();
	public $col      = array();
	public $row      = null;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%(%|[sdfi])/',
			static function ( $m ) use ( &$i, $args ) {
				if ( '%' === $m[1] ) {
					return '%';
				}
				$v = isset( $args[ $i ] ) ? $args[ $i ] : null;
				$i++;
				if ( 's' === $m[1] ) {
					return "'" . addslashes( (string) $v ) . "'";
				}
				return ( 'f' === $m[1] ) ? (string) (float) $v : (string) (int) $v;
			},
			$sql
		);
	}

	public function get_col( $sql ) {
		$this->calls[] = array( 'get_col', $sql );
		return $this->col;
	}

	public function get_row( $sql, $mode = null ) {
		$this->calls[] = array( 'get_row', $sql );
		return $this->row;
	}

	public function get_results( $sql, $mode = null ) {
		$this->calls[] = array( 'get_results', $sql );
		return array();
	}

	public function get_var( $sql ) {
		$this->calls[] = array( 'get_var', $sql );
		return null;
	}

	/** Collapse whitespace so assertions do not depend on indentation. */
	public function last_sql() {
		$last = end( $this->calls );
		return $last ? preg_replace( '/\s+/', ' ', $last[1] ) : '';
	}
}

/**
 * Lift one method out of the class source. The file pulls in WordPress on
 * load, so the two methods are taken as text and wrapped in a class of their
 * own. A rename or a signature change fails here, which is the point.
 *
 * @param string $src  Class file source.
 * @param string $name Method name.
 * @return string
 */
function extract_method( $src, $name ) {
	$start = strpos( $src, "\tpublic function {$name}(" );
	if ( false === $start ) {
		fwrite( STDERR, "could not find {$name}() in includes/npmp-email-settings.php\n" );
		exit( 1 );
	}
	$end = strpos( $src, "\n\t}\n", $start );
	return substr( $src, $start, $end - $start + 4 );
}

$src        = file_get_contents( __DIR__ . '/../includes/npmp-email-settings.php' );
$tags_src   = extract_method( $src, 'get_tags_list' );
$fin_src    = extract_method( $src, 'get_financial_overview' );
eval( "class NPMP_Member_Manager_Extract {\n{$tags_src}\n{$fin_src}\n}" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: extracting two methods rather than booting WordPress.

$mm = new NPMP_Member_Manager_Extract();

echo "\n== 1. Neither method loads rows to count them ==\n";
// The whole reason these exist. A regression back to a WP_Query or
// get_members() loop is a bounded-query bug even if the numbers still agree.
// Comments are stripped first: the methods describe the loop they replaced.
foreach ( array( 'get_tags_list' => $tags_src, 'get_financial_overview' => $fin_src ) as $name => $body ) {
	$code = preg_replace( '#/\*.*?\*/#s', '', $body );
	$code = preg_replace( '#^\s*//.*$#m', '', $code );
	foreach ( array( 'WP_Query', 'get_posts(', 'get_members(', 'get_all_donations(', 'posts_per_page', 'get_post_meta(' ) as $needle ) {
		check( "{$name}() does not use {$needle}", false, strpos( $code, $needle ) );
	}
}

echo "\n== 2. Without the donation module there is nothing to total ==\n";
// NPMP_Donation_Manager is only loaded when the donations feature is on. The
// method has to answer with zeros and never reach the database. This runs
// before the stub class below is declared.
$wpdb            = new NPMP_Test_WPDB();
$GLOBALS['wpdb'] = $wpdb;
$out             = $mm->get_financial_overview();
check( 'NPMP_Donation_Manager is absent for this check', false, class_exists( 'NPMP_Donation_Manager' ) );
check( 'returns the zero shape', array( 'total_amount' => 0.0, 'total_transactions' => 0, 'thirty_day_amount' => 0.0 ), $out );
check( 'total_amount is a float', true, is_float( $out['total_amount'] ) );
check( 'total_transactions is an int', true, is_int( $out['total_transactions'] ) );
check( 'no query was issued', 0, count( $wpdb->calls ) );

if ( ! class_exists( 'NPMP_Donation_Manager' ) ) {
	class NPMP_Donation_Manager { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		const POST_TYPE   = 'npmp_donation';
		const META_AMOUNT = '_npmp_donation_amount';
	}
}

echo "\n== 3. Grouped tag strings become one sorted, de-duplicated list ==\n";
// One row per byte-distinct meta_value, exactly what GROUP BY CAST(... AS
// BINARY) hands back. The PHP tail is unchanged from the loop it replaced.
$wpdb->col = array( 'Donor, Volunteer', 'donor', 'Café', 'Cafe', '0', '   ', ',,a,, b ,c', 'Donor', 'Volunteer ', 'DONOR' );
$out       = $mm->get_tags_list();
check( 'result is a list', array_keys( $out ), range( 0, count( $out ) - 1 ) );
check( "the literal tag string '0' is skipped, as empty() always did", false, in_array( '0', $out, true ) );
check( 'a whitespace-only value contributes nothing', false, in_array( '', $out, true ) );
check( 'empty segments between commas are dropped', 3, count( array_intersect( array( 'a', 'b', 'c' ), $out ) ) );
check( "'Volunteer ' trims and de-duplicates with 'Volunteer'", 1, count( array_keys( $out, 'Volunteer', true ) ) );
check( "'Donor' from two contacts appears once", 1, count( array_keys( $out, 'Donor', true ) ) );
check( "'Donor', 'donor' and 'DONOR' stay three tags", 3, count( array_intersect( array( 'Donor', 'donor', 'DONOR' ), $out ) ) );
check( "'Café' and 'Cafe' stay two tags", 2, count( array_intersect( array( 'Café', 'Cafe' ), $out ) ) );
check( 'ksort order, uppercase before lowercase, byte order for accents', array( 'Cafe', 'Café', 'DONOR', 'Donor', 'Volunteer', 'a', 'b', 'c', 'donor' ), $out );

$wpdb->col = array( '123, 45', 'b' );
$out       = $mm->get_tags_list();
check( 'numeric tags sort first and come back as strings', array( '45', '123', 'b' ), $out );
check( 'a numeric tag is a string, not an int', true, is_string( $out[0] ) );

$wpdb->col = array();
check( 'no contacts gives an empty list', array(), $mm->get_tags_list() );
$wpdb->col = null;
check( 'a failed query gives an empty list, not a warning', array(), $mm->get_tags_list() );

echo "\n== 4. The tag query is one bounded aggregate grouped on bytes ==\n";
// meta_value collates case- and accent-insensitively (utf8mb4_unicode_520_ci
// on the test site), so SELECT DISTINCT would fold 'donor' into 'Donor' and
// 'Cafe' into 'Café' and silently drop tags the old loop returned.
$wpdb->calls = array();
$wpdb->col   = array();
$mm->get_tags_list();
$sql = $wpdb->last_sql();
check( 'exactly one query', 1, count( $wpdb->calls ) );
check( 'groups on CAST(meta_value AS BINARY)', true, false !== stripos( $sql, 'GROUP BY CAST( pm.meta_value AS BINARY )' ) );
check( 'selects MIN() of the byte-identical group', true, false !== stripos( $sql, 'MIN( pm.meta_value )' ) );
check( 'does not use SELECT DISTINCT', false, stripos( $sql, 'DISTINCT' ) );
check( 'reads the npmp_tags key', true, false !== strpos( $sql, "meta_key = 'npmp_tags'" ) );
check( 'only contacts', true, false !== strpos( $sql, "post_type = 'npmp_contact'" ) );
check( 'only published contacts', true, false !== strpos( $sql, "post_status = 'publish'" ) );
check( 'skips empty tag strings in SQL', true, false !== strpos( $sql, "meta_value <> ''" ) );
check( 'no LIMIT, the group set is the bound', false, stripos( $sql, 'LIMIT' ) );

echo "\n== 5. The financial row is cast on the way out ==\n";
$wpdb->row = array( 'total_transactions' => '3', 'total_amount' => '175.5000', 'thirty_day_amount' => '150.0000' );
$out       = $mm->get_financial_overview();
check( 'keys and order match the old return shape', array( 'total_amount', 'total_transactions', 'thirty_day_amount' ), array_keys( $out ) );
check( 'total_amount is a float', true, is_float( $out['total_amount'] ) );
check( 'total_amount value', 175.5, $out['total_amount'] );
check( 'total_transactions is an int', true, is_int( $out['total_transactions'] ) );
check( 'total_transactions value', 3, $out['total_transactions'] );
check( 'thirty_day_amount is a float', true, is_float( $out['thirty_day_amount'] ) );
check( 'thirty_day_amount value', 150.0, $out['thirty_day_amount'] );

$wpdb->row = null;
check( 'a failed query gives the zero shape', array( 'total_amount' => 0.0, 'total_transactions' => 0, 'thirty_day_amount' => 0.0 ), $mm->get_financial_overview() );

echo "\n== 6. The financial query is one aggregate with one row filter ==\n";
// COUNT(*), SUM() and the 30-day SUM() must all see the same rows, so the
// amount > 0 test lives in WHERE, not in a CASE inside each aggregate.
$wpdb->calls = array();
$wpdb->row   = array( 'total_transactions' => '0', 'total_amount' => '0', 'thirty_day_amount' => '0' );
$mm->get_financial_overview();
$sql = $wpdb->last_sql();
check( 'exactly one query', 1, count( $wpdb->calls ) );
check( 'counts rows', true, false !== stripos( $sql, 'COUNT(*)' ) );
check( 'positive amounts only, filtered once in WHERE', true, false !== strpos( $sql, 'AND CAST( pm.meta_value AS DECIMAL(20,4) ) > 0' ) );
check( 'empty result sums to 0, not NULL', 2, substr_count( strtoupper( $sql ), 'COALESCE(' ) );
check( 'reads the amount key', true, false !== strpos( $sql, "meta_key = '_npmp_donation_amount'" ) );
check( 'only donations', true, false !== strpos( $sql, "post_type = 'npmp_donation'" ) );
check( 'only published donations', true, false !== strpos( $sql, "post_status = 'publish'" ) );
check( 'no LIMIT, one row comes back regardless', false, stripos( $sql, 'LIMIT' ) );

echo "\n== 7. The 30-day cutoff is a GMT string built in PHP ==\n";
// post_date_gmt is UTC. FROM_UNIXTIME() or NOW() would read the MySQL
// session timezone, which on the test site is SYSTEM (Pacific), and every
// donation in the last seven hours of the window would drop out of the total.
check( 'compares post_date_gmt', true, false !== strpos( $sql, "p.post_date_gmt >= '" ) );
check( 'does not compare local post_date', false, strpos( $sql, 'p.post_date >=' ) );
check( 'does not use FROM_UNIXTIME()', false, stripos( $sql, 'FROM_UNIXTIME' ) );
check( 'does not use NOW()', false, stripos( $sql, 'NOW()' ) );
preg_match( "/p\.post_date_gmt >= '([^']+)'/", $sql, $m );
$cutoff = isset( $m[1] ) ? $m[1] : '';
check( 'cutoff is a MySQL DATETIME string', 1, preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff ) );
$expected = time() - 30 * 86400;
$actual   = strtotime( $cutoff . ' UTC' );
check( 'cutoff is 30 days ago in UTC (within 5 s)', true, abs( $actual - $expected ) <= 5 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
