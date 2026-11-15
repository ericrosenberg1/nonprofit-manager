<?php
/**
 * Each admin script loads under one handle.
 *
 * newsletter-editor.js loaded twice on the newsletter editor, once as
 * npmp-newsletter-editor (includes/npmp-scripts.php) and once as
 * npmp-newsletter-admin (includes/npmp-email-newsletter.php). WordPress
 * dedupes by handle, not by file, so the page ran the file twice, bound
 * "Send test" twice, and sent two test emails with two alerts (Pete at U3A,
 * 2026-11-14).
 *
 * This reads every wp_enqueue_script() and wp_register_script() call in
 * includes/, works out the .js file it loads (a literal in the call, or a
 * variable set to one in the 15 lines before it), and fails when one file
 * has two handles.
 *
 * Run: php tests/test-script-handles.php
 */

require_once __DIR__ . '/bootstrap.php';

$root  = dirname( __DIR__ );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) );
$seen  = array();

foreach ( $files as $file ) {
	if ( '.php' !== substr( (string) $file, -4 ) ) {
		continue;
	}
	$src   = file_get_contents( (string) $file );
	$lines = explode( "\n", $src );
	if ( ! preg_match_all( '/wp_(?:enqueue|register)_script\s*\(\s*([\'"])([^\'"]+)\1(.{0,600}?)\)\s*;/s', $src, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $calls as $call ) {
		$handle = $call[2][0];
		$args   = $call[3][0];
		$script = '';
		if ( preg_match( '/([A-Za-z0-9_\-]+\.js)/', $args, $m ) ) {
			$script = $m[1];
		} elseif ( preg_match( '/\$([A-Za-z_][A-Za-z0-9_]*)/', $args, $var ) ) {
			$line_no = substr_count( substr( $src, 0, $call[0][1] ), "\n" );
			$before  = implode( "\n", array_slice( $lines, max( 0, $line_no - 15 ), 15 ) );
			if ( preg_match( '/\$' . preg_quote( $var[1], '/' ) . '\s*=\s*[^;]*?([A-Za-z0-9_\-]+\.js)/', $before, $m ) ) {
				$script = $m[1];
			}
		}
		if ( '' !== $script ) {
			$seen[ $script ][ $handle ] = str_replace( $root . '/', '', (string) $file );
		}
	}
}

check( 'newsletter-editor.js found by the scan', true, isset( $seen['newsletter-editor.js'] ) );

foreach ( $seen as $script => $handles ) {
	check( $script . ' loads under one handle', 1, count( $handles ) );
	if ( count( $handles ) > 1 ) {
		echo '        ' . json_encode( $handles ) . "\n";
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
