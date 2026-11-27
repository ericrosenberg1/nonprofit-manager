<?php
/**
 * $wpdb->prepare() reads % as the start of a placeholder. A SQL format string
 * such as DATE_FORMAT(d, '%Y-%m-%d') therefore has its %d eaten as an integer
 * placeholder, the remaining arguments shift by one, and the query silently
 * returns nothing rather than erroring. That shipped once in the donation summary query (since removed) and the
 * only reason it was caught is that the result was compared against the old
 * implementation on real data.
 *
 * Percent signs meant literally must be written %%. This scans both plugins for
 * the mistake so it cannot come back somewhere less well covered.
 *
 * Run: php tests/test-prepare-placeholders.php
 */

require_once __DIR__ . '/bootstrap.php';

/** Placeholders $wpdb->prepare() legitimately understands. */
const REAL_PLACEHOLDERS = array( 's', 'd', 'f', 'i' );

/**
 * Find literal percent signs inside a prepare() call's SQL.
 *
 * @param string $file Path to scan.
 * @return array List of "line: snippet" problems.
 */
function scan_for_bare_percents( $file ) {
	$src      = file_get_contents( $file );
	$lines    = explode( "\n", $src );
	$problems = array();
	$depth    = 0;

	foreach ( $lines as $i => $line ) {
		if ( false !== strpos( $line, '->prepare(' ) ) {
			$depth = 1;
		}
		if ( 0 === $depth ) {
			continue;
		}

		// Comments inside a prepare() block are prose, not SQL. This very file
		// and the fix it guards both describe the bug in words containing a
		// percent sign, which would otherwise flag itself.
		$trimmed = ltrim( $line );
		if ( '' === $trimmed
			|| 0 === strpos( $trimmed, '//' )
			|| 0 === strpos( $trimmed, '*' )
			|| 0 === strpos( $trimmed, '/*' ) ) {
			continue;
		}

		// A LIKE value built as '%' . $wpdb->esc_like( $x ) . '%' is a bound
		// argument, not SQL, and the wildcards there are meant literally. Drop
		// those concatenated '%' literals before scanning so the canonical
		// WordPress idiom does not read as a bare percent (ROS-4871).
		$sql = $line;
		if ( false !== strpos( $line, 'esc_like(' ) ) {
			$sql = preg_replace( '/([\'"])%\1\s*\.|\.\s*([\'"])%\2/', '', $line );
		}

		// A prepare() call ends when we stop seeing SQL. Keep it simple: scan
		// the statement until a line closing the call.
		if ( preg_match_all( '/%(.)/', $sql, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$next = $match[1];
				if ( '%' === $next ) {
					continue; // Correctly escaped.
				}
				if ( in_array( $next, REAL_PLACEHOLDERS, true ) ) {
					continue; // A genuine placeholder.
				}
				$problems[] = sprintf( '%s:%d  %%%s in %s', basename( $file ), $i + 1, $next, trim( $line ) );
			}
		}

		if ( preg_match( '/\);\s*$/', $line ) || preg_match( '/^\s*\),?\s*$/', $line ) ) {
			$depth = 0;
		}
	}

	return $problems;
}

$roots = array(
	__DIR__ . '/../includes',
	__DIR__ . '/../../nonprofit-manager-pro/includes',
);

$all = array();
$scanned = 0;
foreach ( $roots as $root ) {
	if ( ! is_dir( $root ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
	foreach ( $it as $f ) {
		if ( 'php' !== strtolower( $f->getExtension() ) ) {
			continue;
		}
		$scanned++;
		$all = array_merge( $all, scan_for_bare_percents( $f->getPathname() ) );
	}
}

echo "\n== Literal % inside \$wpdb->prepare() must be written %% ==\n";
printf( "  scanned %d files across both plugins\n", $scanned );

check( 'no unescaped percent signs in any prepare() call', 0, count( $all ) );
foreach ( $all as $p ) {
	echo "      $p\n";
}

echo "\n== The scanner itself catches the bug that shipped ==\n";
$tmp = sys_get_temp_dir() . '/npmp-prepare-probe.php';
file_put_contents(
	$tmp,
	"<?php\n\$wpdb->prepare(\n\t\"SELECT DATE_FORMAT(d, '%Y-%m-%d') FROM t WHERE x = %s\",\n\t\$v\n);\n"
);
$caught = scan_for_bare_percents( $tmp );
unlink( $tmp );
// %Y and %m are bare. %d is worse still because prepare consumes it.
check( 'flags a bare %Y/%m format string', true, count( $caught ) >= 2 );

$tmp2 = sys_get_temp_dir() . '/npmp-prepare-ok.php';
file_put_contents(
	$tmp2,
	"<?php\n\$wpdb->prepare(\n\t\"SELECT DATE_FORMAT(d, '%%Y-%%m-%%d') FROM t WHERE x = %s\",\n\t\$v\n);\n"
);
$clean = scan_for_bare_percents( $tmp2 );
unlink( $tmp2 );
check( 'passes the correctly escaped version', 0, count( $clean ) );

// The esc_like() LIKE idiom puts its wildcards in the argument, not the SQL.
$tmp3 = sys_get_temp_dir() . '/npmp-prepare-like.php';
file_put_contents(
	$tmp3,
	"<?php\n\$wpdb->prepare(\n\t\"SELECT id FROM t WHERE p LIKE %s\",\n\t'%' . \$wpdb->esc_like( \$v ) . '%'\n);\n"
);
$like = scan_for_bare_percents( $tmp3 );
unlink( $tmp3 );
check( 'passes a LIKE value built with esc_like()', 0, count( $like ) );

// The esc_like() carve-out must not hide a bare format string on the same line.
$tmp4 = sys_get_temp_dir() . '/npmp-prepare-like-bad.php';
file_put_contents(
	$tmp4,
	"<?php\n\$wpdb->prepare( \"SELECT DATE_FORMAT(d, '%Y') FROM t WHERE p LIKE %s\", '%' . \$wpdb->esc_like( \$v ) . '%' );\n"
);
$like_bad = scan_for_bare_percents( $tmp4 );
unlink( $tmp4 );
check( 'still flags a bare %Y on an esc_like() line', 1, count( $like_bad ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
