<?php
/**
 * Post type labels (includes/npmp-post-type-labels.php).
 *
 * WordPress fills a missing label with the word "Post", so a new newsletter
 * said "Add Post" and "Post published." Every plugin post type now sets the
 * whole set, and the add-new wording follows core 7.1 ("Add Newsletter").
 *
 * Run: php tests/test-post-type-labels.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/npmp-post-type-labels.php';

$expected_add = array(
	'npmp_newsletter'  => 'Add Newsletter',
	'npmp_nl_template' => 'Add Template',
	'npmp_event'       => 'Add Event',
	'npmp_email_body'  => 'Add Email Body',
);

foreach ( $expected_add as $type => $add ) {
	echo "$type\n";
	$labels  = npmp_post_type_labels( $type );
	$missing = array_values( array_diff( npmp_post_type_label_keys(), array_keys( $labels ) ) );
	check( 'every label key is set', array(), $missing );
	check( 'add_new', $add, $labels['add_new'] );
	$posty = array();
	foreach ( $labels as $key => $value ) {
		if ( preg_match( '/\bposts?\b/i', $value ) ) {
			$posty[] = $key;
		}
	}
	check( 'no label says "post"', array(), $posty );
	check( 'no label says "Add New"', false, (bool) preg_grep( '/Add New\b/', $labels ) );
}

echo "Every post type with a screen uses the shared labels (internal ones have show_ui false)\n";
$root = dirname( __DIR__ ) . '/includes';
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
foreach ( $it as $f ) {
	if ( '.php' !== substr( $f, -4 ) ) {
		continue;
	}
	$src = file_get_contents( $f );
	if ( ! preg_match_all( "/register_post_type\(\s*'(npmp_[a-z_]+)'/", $src, $m ) ) {
		continue;
	}
	foreach ( $m[1] as $type ) {
		if ( ! isset( $expected_add[ $type ] ) ) {
			continue;
		}
		check( "$type labels come from npmp_post_type_labels()", true, (bool) preg_match( "/npmp_post_type_labels\(\s*'" . $type . "'\s*\)/", $src ) );
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
