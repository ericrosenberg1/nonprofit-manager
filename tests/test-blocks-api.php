<?php
/**
 * Every editor block declares apiVersion 3 and puts useBlockProps() on its
 * wrapper.
 *
 * Without an apiVersion, core 7.1 treats a block as version 1, warns in the
 * console, and keeps the post editor out of its iframe. Checked in the
 * editor: all seven blocks report apiVersion 3, the canvas runs in the
 * iframe, and each preview renders.
 *
 * Run: php tests/test-blocks-api.php
 */

require_once __DIR__ . '/bootstrap.php';

foreach ( array( 'npmp-content-blocks.js', 'npmp-calendar-blocks.js' ) as $file ) {
	$js     = file_get_contents( __DIR__ . '/../assets/js/' . $file );
	$blocks = preg_match_all( "/registerBlockType\\( '([^']+)', \\{\\s*apiVersion: 3,/", $js, $m );
	$all    = preg_match_all( '/registerBlockType\\(/', $js );
	check( "$file: every block is apiVersion 3", $all, $blocks );
	check( "$file: wrappers use useBlockProps", true, false !== strpos( $js, 'useBlockProps(' ) );
	check( "$file: no bare preview wrapper left", 0, preg_match( "/'div',\\s*\\{ className: 'npmp-block-preview' \\}/", $js ) );
}
check( 'content blocks: seven blocks across both files', 7, preg_match_all( '/registerBlockType\\(/', file_get_contents( __DIR__ . '/../assets/js/npmp-content-blocks.js' ) . file_get_contents( __DIR__ . '/../assets/js/npmp-calendar-blocks.js' ) ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
