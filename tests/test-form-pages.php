<?php
/**
 * Which page counts as the unsubscribe, preferences, signup, or donation page.
 *
 * Until the release after 2026.11.20 the setup searched page text for
 * [npmp_email_unsubscribe] and took the first hit, so a notes page that only
 * mentioned the shortcode became the unsubscribe page and every email footer
 * linked to it (Pete at U3A, page "Thoughts on u3acommunities hosting"). The
 * WordPress side runs in tests/rig/form-pages.php.
 *
 * Run: php tests/test-form-pages.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
require_once dirname( __DIR__ ) . '/includes/npmp-form-pages.php';

function fp_page( $id, $title, $slug, $content, $status = 'publish' ) {
	return array(
		'ID'           => $id,
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'post_status'  => $status,
		'post_type'    => 'page',
	);
}

$notes = fp_page( 92895, 'Thoughts on u3acommunities hosting', 'thoughts-on-u3acommunities-hosting', '<p>Our unsubscribe page uses [npmp_email_unsubscribe] and the prefs page uses [npmp_manage_preferences].</p>' );
$unsub = fp_page( 120, 'Unsubscribe', 'unsubscribe', '<!-- wp:shortcode -->[npmp_email_unsubscribe]<!-- /wp:shortcode -->' );
$block = fp_page( 130, 'Leave the list', 'unsubscribe-2', '<!-- wp:nonprofit-manager/email-unsubscribe /-->' );

echo "== The form itself, never a mention ==\n";
check( 'Shortcode alone', true, npmp_form_page_has_form( '[npmp_email_unsubscribe]', 'unsubscribe' ) );
check( 'Shortcode with attributes', true, npmp_form_page_has_form( '[npmp_email_unsubscribe heading="Bye"]', 'unsubscribe' ) );
check( 'Self-closing shortcode', true, npmp_form_page_has_form( '[npmp_email_unsubscribe /]', 'unsubscribe' ) );
check( 'Escaped [[shortcode]] prints as text', false, npmp_form_page_has_form( 'Type [[npmp_email_unsubscribe]] on the page', 'unsubscribe' ) );
check( 'Code block entity &#91; is text', false, npmp_form_page_has_form( '<code>&#91;npmp_email_unsubscribe]</code>', 'unsubscribe' ) );
check( 'A longer tag name is another shortcode', false, npmp_form_page_has_form( '[npmp_email_unsubscribe_link]', 'unsubscribe' ) );
check( 'The block counts', true, npmp_form_page_has_form( $block['post_content'], 'unsubscribe' ) );
check( 'A block with attributes counts', true, npmp_form_page_has_form( '<!-- wp:nonprofit-manager/email-unsubscribe {"a":1} /-->', 'unsubscribe' ) );
check( 'Signup shortcode is not the unsubscribe form', false, npmp_form_page_has_form( '[npmp_email_signup]', 'unsubscribe' ) );
check( 'Donation block counts for donation', true, npmp_form_page_has_form( '<!-- wp:nonprofit-manager/donation-form /-->', 'donation' ) );

echo "== Named for the job ==\n";
check( 'Title Unsubscribe', true, npmp_form_page_named( 'Unsubscribe', 'x', 'unsubscribe' ) );
check( 'Slug unsubscribe-2', true, npmp_form_page_named( 'Leave the list', 'unsubscribe-2', 'unsubscribe' ) );
check( 'Notes page is not named for it', false, npmp_form_page_named( $notes['post_title'], $notes['post_name'], 'unsubscribe' ) );
check( 'Email preferences page', true, npmp_form_page_named( 'Email preferences', 'email-preferences', 'preferences' ) );
check( 'Notes page is not a preferences page', false, npmp_form_page_named( $notes['post_title'], $notes['post_name'], 'preferences' ) );
check( 'Donate page', true, npmp_form_page_named( 'Donate', 'donate', 'donation' ) );

echo "== Clearly the page ==\n";
check( 'Unsubscribe page with the shortcode', true, npmp_form_page_is_clear( $unsub, 'unsubscribe' ) );
check( 'Notes page that mentions it', false, npmp_form_page_is_clear( $notes, 'unsubscribe' ) );
check( 'Draft Unsubscribe page', false, npmp_form_page_is_clear( fp_page( 5, 'Unsubscribe', 'unsubscribe', '[npmp_email_unsubscribe]', 'draft' ), 'unsubscribe' ) );
check( 'Unsubscribe page without the form', false, npmp_form_page_is_clear( fp_page( 6, 'Unsubscribe', 'unsubscribe', '<p>Write to us.</p>' ), 'unsubscribe' ) );
check( 'A post, not a page', false, npmp_form_page_is_clear( array_merge( $unsub, array( 'post_type' => 'post' ) ), 'unsubscribe' ) );

echo "== Setup picks ==\n";
check( 'Notes page first, Unsubscribe page wins', 120, npmp_form_page_pick( array( $notes, $unsub ), 'unsubscribe' ) );
check( 'Only the notes page: none, so setup creates one', 0, npmp_form_page_pick( array( $notes ), 'unsubscribe' ) );
check( 'Oldest clear page wins', 120, npmp_form_page_pick( array( $block, $unsub ), 'unsubscribe' ) );
check( 'Excluded page skipped', 130, npmp_form_page_pick( array( $block, $unsub ), 'unsubscribe', 120 ) );

echo "== One-time repair ==\n";
check( 'Notes page saved, Unsubscribe page exists: switch', 120, npmp_form_page_repair_target( $notes, array( $notes, $unsub ), 'unsubscribe' ) );
check( 'Notes page saved, no clear page: leave it', 0, npmp_form_page_repair_target( $notes, array( $notes ), 'unsubscribe' ) );
check( 'Clear page saved: leave it', 0, npmp_form_page_repair_target( $block, array( $unsub, $block ), 'unsubscribe' ) );
$picked = fp_page( 77, 'Goodbye', 'goodbye', '<p>Sorry to see you go.</p>' );
check( 'Hand-picked page without the shortcode: leave it', 0, npmp_form_page_repair_target( $picked, array( $unsub ), 'unsubscribe' ) );
check( 'Missing saved page: leave it', 0, npmp_form_page_repair_target( null, array( $unsub ), 'unsubscribe' ) );
check( 'Unpublished saved page: leave it', 0, npmp_form_page_repair_target( array_merge( $notes, array( 'post_status' => 'draft' ) ), array( $unsub ), 'unsubscribe' ) );
$prefs = fp_page( 140, 'Email preferences', 'email-preferences', '[npmp_manage_preferences]' );
check( 'Preferences: notes page saved, real page exists: switch', 140, npmp_form_page_repair_target( $notes, array( $notes, $prefs ), 'preferences' ) );
check( 'Preferences: notes page saved, nothing else: leave it', 0, npmp_form_page_repair_target( $notes, array( $notes ), 'preferences' ) );

echo "== Wiring ==\n";
$root  = dirname( __DIR__ );
$act   = file_get_contents( $root . '/includes/activation-hooks.php' );
$prefs = file_get_contents( $root . '/includes/npmp-subscription-preferences.php' );
$forms = file_get_contents( $root . '/includes/npmp-membership-forms.php' );
$don   = file_get_contents( $root . '/includes/payments/npmp-donation-links.php' );
$main  = file_get_contents( $root . '/nonprofit-manager.php' );
check( 'Unsubscribe setup asks for a clear page', 1, substr_count( $act, "npmp_form_page_find( 'unsubscribe' )" ) );
check( 'Preferences lookup asks for a clear page', 1, substr_count( $prefs, "npmp_form_page_find( 'preferences' )" ) );
check( 'Donation fallback ranks clear pages first', 1, substr_count( $don, "npmp_form_page_pick( \$candidates, 'donation' )" ) );
check( 'No shortcode text search left in includes', 0, preg_match_all( "/'s'\s*=>\s*'\[npmp_/", $act . $prefs . $forms . $don ) );
check( 'Setup status checks the real form', 0, substr_count( $forms, "strpos( (string) get_post_field( 'post_content', \$unsub_id ), '[npmp_email_unsubscribe]' )" ) );
check( 'Status and page injection check the real form', 3, substr_count( $forms, 'npmp_form_page_has_form( $content, ' ) );
check( 'Helpers load before activation hooks', true, strpos( $main, 'npmp-form-pages.php' ) < strpos( $main, 'activation-hooks.php' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
