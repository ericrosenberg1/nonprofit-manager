<?php
/**
 * The donation forms carry their JavaScript inline. On a block theme (Twenty
 * Twenty-Five and every other FSE theme) WordPress runs the finished page
 * through wptexturize(), which skips <script> contents only while it can tell
 * it is inside one. A less-than sign in the script ("amount < 1) { ... >")
 * reads to it as an HTML tag, it loses its place, and every && after that
 * point becomes &#038;&#038;. That is a syntax error: the whole script dies
 * and the Stripe and PayPal buttons do nothing.
 *
 * Verified on the rig (WordPress 7.0, Twenty Twenty-Five): with the
 * less-than comparisons the Stripe-only form's script had 4 mangled
 * ampersands and the multi-gateway one 8. With "min > amount" both have 0.
 *
 * This keeps the less-than sign out of every inline script the front-end
 * donation forms print.
 *
 * Run: php tests/test-inline-scripts.php
 */

require_once __DIR__ . '/bootstrap.php';

$files = array(
	'includes/payments/npmp-payment-gateways.php',
);

foreach ( $files as $file ) {
	$src = file_get_contents( __DIR__ . '/../' . $file );
	preg_match_all( '#<script>(.*?)</script>#s', $src, $scripts, PREG_OFFSET_CAPTURE );

	echo "\n== {$file} ==\n";
	check( 'has inline scripts to check', true, count( $scripts[1] ) > 0 );

	foreach ( $scripts[1] as $script ) {
		list( $js, $offset ) = $script;
		$line = substr_count( substr( $src, 0, $offset ), "\n" ) + 1;
		// PHP inside the script prints values, not markup. Drop it. JavaScript
		// comments stay in: wptexturize() reads them like any other text.
		$js   = preg_replace( '/<\?php.*?\?>/s', '', $js );
		$hits = preg_match_all( '/</', $js );
		check( "script at line {$line} has no less-than sign", 0, $hits );
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
