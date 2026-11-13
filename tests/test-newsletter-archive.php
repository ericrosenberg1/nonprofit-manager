<?php
/**
 * Public newsletter archive, the parts with no database
 * (includes/email-newsletter/archive-pure.php): which newsletters may be
 * listed, attribute resolution, the excerpt text, the pager, and the scrub
 * that keeps tokens, addresses, and tracking out of a public page.
 *
 * The WordPress side runs in tests/rig/newsletter-archive.php.
 *
 * Run: php tests/test-newsletter-archive.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/email-newsletter/archive-pure.php';

echo "Only sent, opted-in newsletters are listable\n";
check( 'sent, published, ticked', true, npmp_newsletter_archive_listable( 'publish', 'sent', '1' ) );
check( 'sent from a draft (Send never publishes), ticked', true, npmp_newsletter_archive_listable( 'draft', 'sent', '1' ) );
check( 'sent but not ticked', false, npmp_newsletter_archive_listable( 'publish', 'sent', '' ) );
check( 'ticked "0" is not ticked', false, npmp_newsletter_archive_listable( 'publish', 'sent', '0' ) );
check( 'a draft never sent (test sends set no status)', false, npmp_newsletter_archive_listable( 'draft', '', '1' ) );
check( 'still sending', false, npmp_newsletter_archive_listable( 'publish', 'queued', '1' ) );
check( 'no recipients matched', false, npmp_newsletter_archive_listable( 'publish', 'no_recipients', '1' ) );
check( 'in the trash', false, npmp_newsletter_archive_listable( 'trash', 'sent', '1' ) );
check( 'an auto-draft', false, npmp_newsletter_archive_listable( 'auto-draft', 'sent', '1' ) );
check( 'scheduled to publish later', false, npmp_newsletter_archive_listable( 'future', 'sent', '1' ) );
check( 'a boolean true reads as ticked', true, npmp_newsletter_archive_listable( 'publish', 'sent', true ) );

echo "Attributes\n";
$s = npmp_newsletter_archive_settings( array() );
check( 'default limit 10', 10, $s['limit'] );
check( 'default newest first', 'desc', $s['order'] );
check( 'default list layout', 'list', $s['layout'] );
check( 'default shows date', 1, $s['date'] );
check( 'default shows excerpt', 1, $s['excerpt'] );
check( 'default has pagination', 1, $s['pagination'] );
check( 'event emails off by default', 0, $s['event_emails'] );
$s = npmp_newsletter_archive_settings( array( 'limit' => '5', 'order' => 'ASC', 'layout' => 'cards', 'columns' => '2', 'date' => 'no', 'excerpt' => 'false', 'readmore' => 'yes', 'event_emails' => 'yes', 'excerpt_length' => '12' ) );
check( 'limit 5', 5, $s['limit'] );
check( 'order asc', 'asc', $s['order'] );
check( 'cards', 'cards', $s['layout'] );
check( 'two columns', 2, $s['columns'] );
check( 'date hidden', 0, $s['date'] );
check( 'excerpt hidden', 0, $s['excerpt'] );
check( 'read more on', 1, $s['readmore'] );
check( 'event emails on', 1, $s['event_emails'] );
check( 'excerpt words', 12, $s['excerpt_length'] );
$s = npmp_newsletter_archive_settings( array( 'limit' => '-1' ) );
check( 'limit -1 means all', -1, $s['limit'] );
check( 'all on one page turns the pager off', 0, $s['pagination'] );
check( 'limit 0 means all', -1, npmp_newsletter_archive_settings( array( 'limit' => '0' ) )['limit'] );
check( 'limit caps at 100', 100, npmp_newsletter_archive_settings( array( 'limit' => '5000' ) )['limit'] );
check( 'order="auto" (the events spelling) is newest first', 'desc', npmp_newsletter_archive_settings( array( 'order' => 'auto' ) )['order'] );
check( 'unknown layout falls back to list', 'list', npmp_newsletter_archive_settings( array( 'layout' => 'mosaic' ) )['layout'] );
check( 'columns out of range fall back', 3, npmp_newsletter_archive_settings( array( 'columns' => '9' ) )['columns'] );
check( 'blank values keep the defaults', npmp_newsletter_archive_defaults(), npmp_newsletter_archive_settings( array( 'limit' => '', 'order' => '', 'date' => '', 'columns' => '' ) ) );
check( 'pagination="no"', 0, npmp_newsletter_archive_settings( array( 'pagination' => 'no' ) )['pagination'] );
check( 'not an array', npmp_newsletter_archive_defaults(), npmp_newsletter_archive_settings( 'junk' ) );

echo "Excerpt text\n";
$body = '<!-- wp:heading --><h2>March news</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Hi [first_name default="friend"], the &amp; club meets <strong>Tuesday</strong>.</p><!-- /wp:paragraph --><figure><img src="x.png"></figure><table><tr><td>Walks on Friday</td></tr></table>[npmp_can_spam]<style>p{color:red}</style>';
$text = npmp_newsletter_archive_text( $body );
check( 'drops headings, figures, styles, and every [tag]', 'Hi , the & club meets Tuesday. Walks on Friday', $text );
check( 'keeps table text', true, false !== strpos( $text, 'Walks on Friday' ) );
check( 'no bracket tag survives', false, (bool) preg_match( '/\[[a-z_]/i', npmp_newsletter_archive_text( '[name] [last_name default="x"] [/caption] text' ) ) );
check( 'trims to a word count with an ellipsis', 'one two three four five…', npmp_newsletter_archive_trim_words( 'one two three four five, six seven', 5 ) );
check( 'short text stays whole', 'one two', npmp_newsletter_archive_trim_words( 'one   two', 30 ) );

echo "Pager\n";
check( 'page 1 of 3', array( 'prev' => 0, 'next' => 2, 'pages' => 3, 'current' => 1 ), npmp_newsletter_archive_pager( 25, 10, 1 ) );
check( 'page 3 of 3', array( 'prev' => 2, 'next' => 0, 'pages' => 3, 'current' => 3 ), npmp_newsletter_archive_pager( 25, 10, 3 ) );
check( 'a page past the end clamps', 3, npmp_newsletter_archive_pager( 25, 10, 99 )['current'] );
check( 'everything on one page', array( 'prev' => 0, 'next' => 0, 'pages' => 1, 'current' => 1 ), npmp_newsletter_archive_pager( 25, -1, 2 ) );
check( 'nothing at all', 1, npmp_newsletter_archive_pager( 0, 10, 1 )['pages'] );

echo "Scrub keeps recipients off a public page\n";
$html = '<p>Hello</p>'
	. '<img src="https://club.test/?npmp_track=open&amp;nid=4&amp;uid=9&amp;_npmp=abc123" alt="" width="1" height="1" style="display:none;" />'
	. '<a href="https://club.test/?npmp_track=click&amp;nid=4&amp;uid=9&amp;url=https%253A%252F%252Fexample.org%252Fwalks%253Fa%253D1&amp;_npmp=def">Walks</a>'
	. '<a href="https://club.test/unsubscribe/?npmp_email=pat%40example.com&amp;npmp_token=0123456789abcdef">Unsubscribe</a>'
	. '<a href="https://club.test/wp-admin/admin-post.php?action=npmp_one_click_unsubscribe&amp;email=pat%40example.com&amp;token=ffff">Leave</a>'
	. '<a href="https://example.org/page?email=keep%40org.test">Partner link</a>'
	. '<a href="mailto:info@club.test">Write to us</a>';
$out = npmp_newsletter_archive_scrub( $html );
check( 'open pixel gone', false, false !== strpos( $out, 'npmp_track=open' ) );
check( 'no tracked link left', false, false !== strpos( $out, 'npmp_track' ) );
check( 'tracked click unwrapped to its target', true, false !== strpos( $out, 'href="https://example.org/walks?a=1"' ) );
check( 'no unsubscribe token', false, (bool) preg_match( '/npmp_token|[?&;]token=/', $out ) );
check( 'no recipient address', false, false !== strpos( $out, 'pat%40example.com' ) || false !== strpos( $out, 'pat@example.com' ) );
check( 'unsubscribe page link kept, bare', true, false !== strpos( $out, 'href="https://club.test/unsubscribe/"' ) );
check( 'one-click link keeps only its action', true, false !== strpos( $out, 'admin-post.php?action=npmp_one_click_unsubscribe"' ) );
check( 'a link to another site keeps its query', true, false !== strpos( $out, 'page?email=keep%40org.test' ) );
check( 'mailto link kept', true, false !== strpos( $out, 'mailto:info@club.test' ) );
check( 'recipient ID gone', false, false !== strpos( $out, 'uid=' ) );
check( 'a click link with a script target goes nowhere', true, false !== strpos( npmp_newsletter_archive_scrub( '<a href="https://club.test/?npmp_track=click&url=javascript%3Aalert(1)">x</a>' ), 'href="#"' ) );
check( 'HTML without plugin links is unchanged', '<p><a href="https://a.test/?x=1&amp;y=2#z">a</a></p>', npmp_newsletter_archive_scrub( '<p><a href="https://a.test/?x=1&amp;y=2#z">a</a></p>' ) );
check( 'a hash survives the strip', 'https://c.test/u/?npmp_page=1#top', npmp_newsletter_archive_strip_private_args( 'https://c.test/u/?npmp_page=1&npmp_token=zz#top' ) );

echo "Path\n";
check( 'id and slug', 'newsletter/42/march-news/', npmp_newsletter_archive_path( 42, 'march-news' ) );
check( 'no slug', 'newsletter/42/', npmp_newsletter_archive_path( 42, '' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
