<?php
/**
 * Newsletter Archive screen: the sort whitelist, the direction a header link
 * asks for, and source checks that pin the admin bar entry, the bulk form's
 * view fields, and the SQL sort. The WordPress side (45 newsletters, every
 * sort both ways, bulk actions, the admin bar for four roles) runs in
 * tests/rig/newsletter-archive-search.php.
 *
 * Run: php tests/test-newsletter-archive-search.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/archive-pure.php';

echo "Sort whitelist\n";
check( 'no sort gives the default', array( 'orderby' => '', 'order' => 'DESC' ), npmp_newsletter_archive_sort( '', '' ) );
check( 'title with no order uses its first direction', array( 'orderby' => 'title', 'order' => 'ASC' ), npmp_newsletter_archive_sort( 'title', '' ) );
check( 'sent with no order uses desc', array( 'orderby' => 'sent', 'order' => 'DESC' ), npmp_newsletter_archive_sort( 'sent', '' ) );
check( 'order is case blind', array( 'orderby' => 'recipients', 'order' => 'ASC' ), npmp_newsletter_archive_sort( 'RECIPIENTS', 'Asc' ) );
check( 'public desc', array( 'orderby' => 'public', 'order' => 'DESC' ), npmp_newsletter_archive_sort( 'public', 'desc' ) );
check( 'an unknown column is dropped', array( 'orderby' => '', 'order' => 'DESC' ), npmp_newsletter_archive_sort( 'post_content', 'asc' ) );
check( 'SQL in orderby is dropped', array( 'orderby' => '', 'order' => 'DESC' ), npmp_newsletter_archive_sort( 'title; DROP TABLE x', 'asc' ) );
check( 'a bad order falls back to the column default', array( 'orderby' => 'title', 'order' => 'ASC' ), npmp_newsletter_archive_sort( 'title', 'sideways' ) );
check( 'an array is dropped', array( 'orderby' => '', 'order' => 'DESC' ), npmp_newsletter_archive_sort( array( 'title' ), array( 'asc' ) ) );

echo "Header link direction\n";
$sort = npmp_newsletter_archive_sort( 'title', 'asc' );
check( 'the sorted column flips to desc', 'desc', npmp_newsletter_archive_sort_next( $sort, 'title' ) );
check( 'a descending column flips to asc', 'asc', npmp_newsletter_archive_sort_next( npmp_newsletter_archive_sort( 'title', 'desc' ), 'title' ) );
check( 'another column starts on its own direction (sent: desc)', 'desc', npmp_newsletter_archive_sort_next( $sort, 'sent' ) );
check( 'another column (title) from the default view starts asc', 'asc', npmp_newsletter_archive_sort_next( npmp_newsletter_archive_sort( '', '' ), 'title' ) );

echo "Source checks\n";
$editor = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/editor.php' );
$bulk   = file_get_contents( dirname( __DIR__ ) . '/includes/email-newsletter/imported.php' );
$staff  = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-staff-access.php' );
check( 'the newsletter post type shows in the admin bar', true, (bool) preg_match( "/register_post_type\\('npmp_newsletter'.*?'show_in_admin_bar' => true/s", $editor ) );
check( 'the staff admin bar trim keeps + New', false, (bool) preg_match( "/foreach \\( array\\([^)]*'new-content'/", $staff ) );
check( 'the sort runs in SQL on a whitelisted column', true, false !== strpos( $editor, "add_filter('posts_clauses', 'npmp_newsletter_archive_sort_clauses'" ) );
check( 'the screen never sorts in PHP', false, (bool) preg_match( '/npmp_render_newsletter_archive.*?\b(usort|uasort|array_multisort)\(/s', $editor ) );
check( 'the bulk form posts the view back', true, false !== strpos( $bulk, 'npmp_newsletter_public_bulk_view_fields( $view )' ) );
check( 'the none redirect keeps the view', true, false !== strpos( $bulk, '\'npmp_public_done\' => \'none\' ), $view )' ) );
check( 'the Archive form route takes the view', true, false !== strpos( $bulk, ', $ids, $view )' ) );
check( 'the confirmed Show redirect keeps the view', true, (bool) preg_match( '/\'show\',\s+\'npmp_public_n\'\s+=> \$done,\s+\),\s+\$view/', $bulk ) );
check( 'the Hide redirect keeps the view', true, (bool) preg_match( '/\'hide\',\s+\'npmp_public_n\'\s+=> \$done,\s+\),\s+\$view/', $bulk ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
