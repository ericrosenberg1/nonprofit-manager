<?php
/**
 * The "Show in the public archive" and "Hide from the public archive" bulk
 * actions on the newsletter list (npmp_newsletter_public_bulk_plan() in
 * includes/email-newsletter/archive-pure.php). The WordPress side runs in
 * tests/rig/past-newsletters.php.
 *
 * Run: php tests/test-newsletter-public-bulk.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/archive-pure.php';

$rows = array(
	array( 'id' => 1, 'send_status' => 'sent', 'public' => '' ),
	array( 'id' => 2, 'send_status' => 'sent', 'public' => '1' ),
	array( 'id' => 3, 'send_status' => '', 'public' => '' ),
	array( 'id' => 4, 'send_status' => 'queued', 'public' => '' ),
	array( 'id' => 5, 'send_status' => 'sent', 'public' => '0' ),
	array( 'id' => 0, 'send_status' => 'sent', 'public' => '' ),
	array( 'id' => 6, 'send_status' => '', 'public' => '1' ),
);

echo "Show\n";
$p = npmp_newsletter_public_bulk_plan( $rows, 'show' );
check( 'ticks the sent ones not yet public', array( 1, 5 ), $p['change'] );
check( 'skips drafts and newsletters still sending', array( 3, 4, 6 ), $p['unsent'] );
check( 'leaves the public one alone', array( 2 ), $p['already'] );

echo "Hide\n";
$p = npmp_newsletter_public_bulk_plan( $rows, 'hide' );
check( 'unticks every public one, sent or not', array( 2, 6 ), $p['change'] );
check( 'nothing counts as unsent', array(), $p['unsent'] );
check( 'already hidden', array( 1, 3, 4, 5 ), $p['already'] );

echo "Edge cases\n";
check( 'no rows', array( 'change' => array(), 'unsent' => array(), 'already' => array() ), npmp_newsletter_public_bulk_plan( array(), 'show' ) );
check( 'an unknown action hides', array( 2, 6 ), npmp_newsletter_public_bulk_plan( $rows, 'nope' )['change'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
