<?php
// Usage: NPMP_RIG_WP=/path/to/wp php tests/rig/form-pages.php
//
// The unsubscribe, preferences, and donation pages on a real WordPress. A
// notes page that only mentions [npmp_email_unsubscribe] never becomes the
// unsubscribe page (Pete at U3A: his footers linked to "Thoughts on
// u3acommunities hosting"). The one-time repair moves a saved notes page to a
// real Unsubscribe page when one exists and leaves every other pick alone.
// The footer's visible URL decodes to the plain link. Leaves no pages or
// settings behind. Run with the members, newsletters, and donations modules
// on. Not run by the pre-push gate.
$wp_root = getenv( 'NPMP_RIG_WP' ) ?: '/tmp/npmp-rig/wp';
$_SERVER['HTTP_HOST']       = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$GLOBALS['rig_errors'] = array();
set_error_handler( function ( $no, $str, $errfile, $line ) {
	if ( false !== strpos( $errfile, 'nonprofit-manager' ) ) {
		$GLOBALS['rig_errors'][] = "$str @ " . basename( $errfile ) . ":$line";
	}
	return false;
} );
require $wp_root . '/wp-load.php';

$GLOBALS['rig_pass'] = 0;
$GLOBALS['rig_fail'] = 0;
function rig_check( $label, $expected, $actual ) {
	$ok = $expected === $actual;
	$GLOBALS[ $ok ? 'rig_pass' : 'rig_fail' ]++;
	printf( "  %s  %-66s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? '' : 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}
function rig_page( $title, $content, $slug = '' ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content ) );
	$GLOBALS['rig_pages'][] = $id;
	return $id;
}
function rig_unsub_setting() {
	$s = get_option( 'npmp_membership_form_settings', array() );
	return (int) ( $s['unsubscribe_page_id'] ?? 0 );
}
function rig_set_unsub( $id ) {
	$s = get_option( 'npmp_membership_form_settings', array() );
	$s = is_array( $s ) ? $s : array();
	$s['unsubscribe_page_id'] = (int) $id;
	update_option( 'npmp_membership_form_settings', $s );
	npmp_get_membership_form_settings( true );
}
function rig_repair() {
	delete_option( 'npmp_form_pages_repaired' );
	npmp_maybe_repair_form_pages();
	npmp_get_membership_form_settings( true );
}
function rig_unsub_status() {
	foreach ( npmp_get_membership_setup_status() as $row ) {
		if ( 'Unsubscribe page' === $row['label'] ) {
			return $row;
		}
	}
	return array();
}

if ( ! function_exists( 'npmp_donation_email_target_url' ) || ! function_exists( 'npmp_get_preferences_page_id' ) || ! function_exists( 'npmp_get_membership_setup_status' ) ) {
	echo "  FAIL  members, newsletters, or donations module not loaded (turn all three on)\n";
	exit( 1 );
}

$GLOBALS['rig_pages'] = array();
$keep = array();
foreach ( array( 'npmp_membership_form_settings', 'npmp_preferences_page_id', 'npmp_form_pages_repaired', 'npmp_donation_page_id', 'npmp_newsletter_can_spam_footer' ) as $opt ) {
	$keep[ $opt ] = get_option( $opt, null );
}
// Every published page this site already had goes to draft for the run, so
// the rig sees only its own pages. The shutdown handler puts them back, and
// removes the rig's pages, even after a fatal error.
$hidden = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
foreach ( $hidden as $hid ) {
	wp_update_post( array( 'ID' => $hid, 'post_status' => 'draft' ) );
}
register_shutdown_function(
	function () use ( $hidden, $keep ) {
		foreach ( array_unique( array_filter( $GLOBALS['rig_pages'] ) ) as $pid ) {
			wp_delete_post( $pid, true );
		}
		foreach ( $hidden as $hid ) {
			wp_update_post( array( 'ID' => $hid, 'post_status' => 'publish' ) );
		}
		foreach ( $keep as $opt => $val ) {
			if ( null === $val ) {
				delete_option( $opt );
			} else {
				update_option( $opt, $val );
			}
		}
		delete_transient( 'npmp_prefs_page_lookup' );
	}
);
delete_transient( 'npmp_prefs_page_lookup' );

$notes_text = '<!-- wp:paragraph --><p>Our unsubscribe page uses [npmp_email_unsubscribe], prefs use [npmp_manage_preferences], and giving uses [npmp_donation_form].</p><!-- /wp:paragraph -->';

echo "== The repair runs once, on the first load after the update ==\n";
rig_check( 'Hooked on init', 30, has_action( 'init', 'npmp_maybe_repair_form_pages' ) );
rig_check( 'Done flag set on this site', true, (bool) get_option( 'npmp_form_pages_repaired' ) );

echo "== Fresh setup with only a notes page ==\n";
$notes = rig_page( 'Thoughts on u3acommunities hosting', $notes_text );
rig_set_unsub( 0 );
npmp_maybe_create_unsubscribe_page();
$made = rig_unsub_setting();
$GLOBALS['rig_pages'][] = $made;
rig_check( 'Notes page is not picked', true, $made !== $notes );
rig_check( 'A new Unsubscribe page is made', 'Unsubscribe', get_the_title( $made ) );
rig_check( 'The new page holds the form', true, npmp_form_page_has_form( get_post_field( 'post_content', $made ), 'unsubscribe' ) );

echo "== Fresh setup reuses a real Unsubscribe page ==\n";
rig_set_unsub( 0 );
npmp_maybe_create_unsubscribe_page();
rig_check( 'Reuses the Unsubscribe page', $made, rig_unsub_setting() );

echo "== Repair: Pete's site, notes page saved, no Unsubscribe page ==\n";
wp_update_post( array( 'ID' => $made, 'post_status' => 'draft' ) );
rig_set_unsub( $notes );
rig_repair();
rig_check( 'Setting left alone', $notes, rig_unsub_setting() );
rig_check( 'No page created', 'draft', get_post_status( $made ) );
$row = rig_unsub_status();
rig_check( 'Setup status warns', 'warning', $row['status'] ?? '' );
rig_check( 'Warning names the page', true, false !== strpos( $row['message'] ?? '', 'Thoughts on u3acommunities hosting' ) );
rig_check( 'No more "the form is on the page"', false, false !== strpos( $row['message'] ?? '', 'the form is on the page' ) );

echo "== Repair: notes page saved, Unsubscribe page exists ==\n";
wp_update_post( array( 'ID' => $made, 'post_status' => 'publish' ) );
rig_repair();
rig_check( 'Switched to the Unsubscribe page', $made, rig_unsub_setting() );
$row = rig_unsub_status();
rig_check( 'Setup status is fine', 'ok', $row['status'] ?? '' );
rig_check( 'Says the form is on the page', true, false !== strpos( $row['message'] ?? '', 'the form is on the page' ) );
rig_check( 'Footer link goes to the Unsubscribe page', 0, strpos( npmp_can_spam_get_unsubscribe_url(), get_permalink( $made ) ) );

echo "== Repair runs once ==\n";
rig_set_unsub( $notes );
npmp_maybe_repair_form_pages();
rig_check( 'A second load changes nothing', $notes, rig_unsub_setting() );

echo "== Repair leaves deliberate picks alone ==\n";
$goodbye = rig_page( 'Goodbye', '<p>Sorry to see you go.</p>' );
rig_set_unsub( $goodbye );
rig_repair();
rig_check( 'Hand-picked page without the shortcode stays', $goodbye, rig_unsub_setting() );
$row = rig_unsub_status();
rig_check( 'Status: form added automatically', true, false !== strpos( $row['message'] ?? '', 'added to the page automatically' ) );
$leave = rig_page( 'Leave the list', '<!-- wp:nonprofit-manager/email-unsubscribe /-->', 'unsubscribe-me' );
rig_set_unsub( $leave );
rig_repair();
rig_check( 'Clear page with the block stays', $leave, rig_unsub_setting() );

echo "== The form is added once ==\n";
$GLOBALS['post'] = get_post( $leave );
$GLOBALS['wp_query']->is_singular = true;
$GLOBALS['wp_query']->is_page     = true;
$GLOBALS['wp_query']->queried_object    = $GLOBALS['post'];
$GLOBALS['wp_query']->queried_object_id = $leave;
setup_postdata( $GLOBALS['post'] );
$html = apply_filters( 'the_content', get_post_field( 'post_content', $leave ) );
rig_check( 'Block page: one unsubscribe form', 1, substr_count( $html, 'class="npmp-email-unsubscribe' ) );
rig_set_unsub( $goodbye );
$GLOBALS['post'] = get_post( $goodbye );
$GLOBALS['wp_query']->queried_object    = $GLOBALS['post'];
$GLOBALS['wp_query']->queried_object_id = $goodbye;
setup_postdata( $GLOBALS['post'] );
$html = apply_filters( 'the_content', get_post_field( 'post_content', $goodbye ) );
rig_check( 'Page without it: the form is added', 1, substr_count( $html, 'class="npmp-email-unsubscribe' ) );
wp_reset_postdata();

echo "== Preferences page ==\n";
update_option( 'npmp_preferences_page_id', 0 );
delete_transient( 'npmp_prefs_page_lookup' );
rig_check( 'Only the notes page: no preferences page', 0, npmp_get_preferences_page_id() );
rig_check( 'The miss is remembered', true, false !== get_transient( 'npmp_prefs_page_lookup' ) );
$prefs = rig_page( 'Email preferences', '[npmp_manage_preferences]' );
rig_check( 'Creating the page clears the remembered miss', false, get_transient( 'npmp_prefs_page_lookup' ) );
rig_check( 'Finds the Email preferences page', $prefs, npmp_get_preferences_page_id() );
update_option( 'npmp_preferences_page_id', $notes );
rig_repair();
rig_check( 'Repair moves a saved notes page to it', $prefs, (int) get_option( 'npmp_preferences_page_id' ) );

echo "== Donate button target ==\n";
delete_option( 'npmp_donation_page_id' );
$donate = rig_page( 'Donate', '<!-- wp:nonprofit-manager/donation-form /-->' );
rig_check( 'Donate page wins over an older notes page', get_permalink( $donate ), npmp_donation_email_target_url() );
wp_update_post( array( 'ID' => $donate, 'post_status' => 'draft' ) );
$support = rig_page( 'Our work', '<p>Thanks.</p>[npmp_donation_form]' );
rig_check( 'No page named for giving: the oldest page holding the form', get_permalink( $notes ), npmp_donation_email_target_url() );
wp_update_post( array( 'ID' => $notes, 'post_content' => '<p>Type [[npmp_donation_form]] to show the form.</p>' ) );
rig_check( 'An escaped mention never counts', get_permalink( $support ), npmp_donation_email_target_url() );

echo "== Footer URL text ==\n";
rig_set_unsub( $made );
update_option( 'npmp_newsletter_can_spam_footer', "From [organization]. To unsubscribe: [unsubscribe_url] or <a href=\"[unsubscribe_url]\">click here</a>" );
$footer = npmp_can_spam_shortcode( array( 'email' => 'rig-reader@example.org' ) );
$raw    = npmp_can_spam_get_unsubscribe_url( 'rig-reader@example.org' );
rig_check( 'The link carries two query args', true, false !== strpos( $raw, '&npmp_token=' ) );
rig_check( 'HTML-decoded visible text holds the plain URL', true, false !== strpos( html_entity_decode( wp_strip_all_tags( $footer ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $raw ) );
preg_match( '/href="([^"]+)"/', $footer, $m );
rig_check( 'HTML-decoded href is the plain URL', $raw, html_entity_decode( $m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

rig_check( 'No PHP notices from the plugin', array(), $GLOBALS['rig_errors'] );
printf( "\n%d passed, %d failed\n", $GLOBALS['rig_pass'], $GLOBALS['rig_fail'] );
exit( $GLOBALS['rig_fail'] ? 1 : 0 );
