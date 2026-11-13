<?php
/**
 * Real-WordPress check of the public newsletter archive
 * (includes/email-newsletter/archive.php), with Pro active or inactive.
 *
 * It sends a newsletter through the real queue (mail caught by pre_wp_mail)
 * to prove the email carries a recipient token, tracking, and a name, then
 * checks that the list and the public page carry none of them. It covers
 * sent, draft, scheduled, opted-in, not-opted-in, event email, and
 * group-only newsletters, the shortcode and block output, the opt-in box's
 * save, and the public page over HTTP (200, noindex, 404).
 *
 * Run inside a throwaway WordPress with members and newsletters on, pretty
 * permalinks, and the site served at its home URL (php -S works):
 *   wp eval-file tests/rig/newsletter-archive.php
 * Run it twice, with Pro active and inactive. It deletes every newsletter and
 * contact first.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

if ( ! class_exists( 'NPMP_Member_Manager' ) || ! class_exists( 'NPMP_Newsletter_Manager' ) || ! function_exists( 'npmp_newsletter_archive_render' ) ) {
	echo "  FAIL  members and newsletters must be on (npmp_enabled_features)\n";
	exit( 1 );
}
$pro = function_exists( 'npmp_pro_get_groups' );

/**
 * A response's headers and body as one string.
 *
 * @param array|WP_Error $res Response.
 * @return string
 */
function npmp_rig_headers_and_body( $res ) {
	return (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ) . "\n" . (string) wp_remote_retrieve_body( $res );
}
echo $pro ? "Pro is active\n" : "Pro is inactive\n";

global $wpdb;
// phpcs:disable WordPress.DB -- Throwaway rig.
$wpdb->query( "DELETE FROM {$wpdb->prefix}npmp_newsletter_queue" );
foreach ( get_posts( array( 'post_type' => array( 'npmp_newsletter', 'npmp_contact', 'page' ), 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids' ) ) as $rig_id ) {
	wp_delete_post( $rig_id, true );
}
wp_cache_flush();
update_option( 'npmp_newsletter_track_opens', 1 );
update_option( 'npmp_newsletter_track_clicks', 1 );
delete_option( 'npmp_newsletter_archive_index' );

// An unsubscribe page, so the footer link carries npmp_email and npmp_token.
$unsub_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Unsubscribe', 'post_content' => '[npmp_email_unsubscribe]' ) );
$form       = (array) get_option( 'npmp_membership_form_settings', array() );
update_option( 'npmp_membership_form_settings', array_merge( $form, array( 'unsubscribe_page_id' => $unsub_page ) ) );

$members = NPMP_Member_Manager::get_instance();
$pat     = $members->add_member( array( 'email' => 'pat.reader@example.org', 'name' => 'Patricia Reader', 'status' => 'subscribed' ) );
$ok( 'made a subscribed contact', is_int( $pat ) && $pat > 0 );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

$body = '<!-- wp:paragraph --><p>Hi [first_name default="Friend"], our walk is on Friday. <a href="https://example.org/walks?a=1">See the route</a>.</p><!-- /wp:paragraph -->';
$make = function ( $title, $status = 'publish', $content = null ) use ( $body ) {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'npmp_newsletter',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => null === $content ? $body : $content,
		)
	);
};
$mark_sent = function ( $id, $when ) {
	update_post_meta( $id, '_npmp_newsletter_status', 'sent' );
	update_post_meta( $id, '_npmp_newsletter_queued_at', $when );
};

echo "A real send carries per-recipient parts\n";
$a = $make( 'March news for [first_name default="members"]', 'draft' );
update_post_meta( $a, '_npmp_newsletter_template', 'none' );
$caught = array();
$catch  = static function ( $null, $atts ) use ( &$caught ) {
	$caught[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $catch, 10, 2 );
NPMP_Newsletter_Manager::queue_newsletter( $a );
NPMP_Newsletter_Manager::process_queue();
remove_filter( 'pre_wp_mail', $catch, 10 );
$mail = $caught ? (string) $caught[0]['message'] : '';
$ok( 'the queue mailed it', 1 === count( $caught ) );
$ok( 'the email has the reader\'s name', false !== strpos( $mail, 'Hi Patricia' ) );
$ok( 'the email has an unsubscribe token', false !== strpos( $mail, 'npmp_token=' ) );
$ok( 'the email has the reader\'s address', false !== strpos( $mail, 'pat.reader%40example.org' ) || false !== strpos( $mail, 'pat.reader@example.org' ) );
$ok( 'the email has an open pixel and tracked links', false !== strpos( $mail, 'npmp_track=open' ) && false !== strpos( $mail, 'npmp_track=click' ) );
$ok( 'the queue marked it sent (it stayed a draft)', 'sent' === get_post_meta( $a, '_npmp_newsletter_status', true ) && 'draft' === get_post_status( $a ) );
$ok( 'a sent newsletter is not public until ticked', ! npmp_newsletter_archive_is_public( $a ) );

echo "The opt-in box\n";
$_POST = array(
	'npmp_newsletter_public_nonce' => wp_create_nonce( 'npmp_newsletter_public_' . $a ),
	'npmp_newsletter_public'       => '1',
);
npmp_newsletter_archive_save( $a );
$ok( 'an administrator ticks it', '1' === get_post_meta( $a, '_npmp_newsletter_public', true ) );
$ok( 'now it is public', npmp_newsletter_archive_is_public( $a ) );
$author = wp_insert_user( array( 'user_login' => 'rigauthor' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'author' . wp_rand() . '@example.org' ) );
wp_set_current_user( $author );
$_POST = array( 'npmp_newsletter_public_nonce' => wp_create_nonce( 'npmp_newsletter_public_' . $a ) );
npmp_newsletter_archive_save( $a );
$ok( 'an author can\'t untick it', '1' === get_post_meta( $a, '_npmp_newsletter_public', true ) );
wp_set_current_user( $admin[0]->ID );
$_POST = array();
npmp_newsletter_archive_save( $a );
$ok( 'a save without the box leaves it alone', '1' === get_post_meta( $a, '_npmp_newsletter_public', true ) );
ob_start();
npmp_newsletter_archive_meta_box_html( get_post( $a ) );
$box = ob_get_clean();
$ok( 'the box shows the checkbox ticked', false !== strpos( $box, 'name="npmp_newsletter_public" value="1" checked' ) );
$ok( 'the box links the public page', false !== strpos( $box, '/newsletter/' . $a . '/' ) );

echo "Who else is in the archive\n";
$b = $make( 'Sent, not ticked' );
$mark_sent( $b, '2026-10-01 10:00:00' );
$c = $make( 'Draft, ticked, never sent', 'draft' );
update_post_meta( $c, '_npmp_newsletter_public', '1' );
$d = $make( 'Send later, ticked' );
update_post_meta( $d, '_npmp_newsletter_public', '1' );
$e = $make( 'Old news', 'publish', '<p>From last year.</p>' );
$mark_sent( $e, '2025-11-01 09:00:00' );
update_post_meta( $e, '_npmp_newsletter_public', '1' );
$f = $make( 'Reminder: Walk on Friday' );
$mark_sent( $f, '2026-10-20 08:00:00' );
update_post_meta( $f, '_npmp_newsletter_public', '1' );
update_post_meta( $f, '_npmp_newsletter_scheduled_event', 123 );
$t = $make( 'Trashed', 'publish' );
$mark_sent( $t, '2026-10-02 08:00:00' );
update_post_meta( $t, '_npmp_newsletter_public', '1' );
wp_trash_post( $t );

$group_id = 999999;
if ( $pro ) {
	$group_id = (int) npmp_pro_ensure_group( 'Computing', 'computing' );
	npmp_pro_ensure_group( 'Walking', 'walking' );
}
$g = $make( 'Computing group news' );
$mark_sent( $g, '2026-10-15 12:00:00' );
update_post_meta( $g, '_npmp_newsletter_groups', array( $group_id ) );
update_post_meta( $g, '_npmp_newsletter_public', '1' );
$g2 = $make( 'Computing, not ticked' );
$mark_sent( $g2, '2026-10-16 12:00:00' );
update_post_meta( $g2, '_npmp_newsletter_groups', array( $group_id ) );

$titles = function ( $html ) {
	preg_match_all( '#npmp-newsletter-title"><a [^>]*>([^<]+)#', $html, $m );
	return array_map( 'html_entity_decode', $m[1] );
};

$html = do_shortcode( '[npmp_newsletters]' );
$list = $titles( $html );
$ok( 'default list: newest first, ticked and sent only', array( 'March news for members', 'Computing group news', 'Old news' ) === $list );
$ok( 'never a draft that was not sent, a Send later, an unticked one, or a trashed one', ! array_intersect( $list, array( 'Draft, ticked, never sent', 'Send later, ticked', 'Sent, not ticked', 'Trashed', 'Computing, not ticked' ) ) );
$ok( 'event emails stay out by default', ! in_array( 'Reminder: Walk on Friday', $list, true ) );
$ok( 'event_emails="yes" adds them', in_array( 'Reminder: Walk on Friday', $titles( do_shortcode( '[npmp_newsletters event_emails="yes"]' ) ), true ) );
$ok( 'order="asc" is oldest first', 'Old news' === $titles( do_shortcode( '[npmp_newsletters order="asc"]' ) )[0] );
$ok( 'the list shows the send date', false !== strpos( $html, '<time datetime="2025-11-01">' ) );
$ok( 'the excerpt fills the tag default', false !== strpos( $html, 'Hi Friend, our walk is on Friday.' ) );
$ok( 'no name, address, token, or tracking in the list', false === strpos( $html, 'Patricia' ) && false === strpos( $html, 'pat.reader' ) && false === strpos( $html, 'npmp_token' ) && false === strpos( $html, 'npmp_track' ) );
$ok( 'list layout classes', false !== strpos( $html, 'class="npmp-newsletters npmp-newsletters--list npmp-newsletters--cols-3"' ) );
$ok( 'layout="cards" columns="2"', false !== strpos( do_shortcode( '[npmp_newsletters layout="cards" columns="2"]' ), 'npmp-newsletters--cards npmp-newsletters--cols-2' ) );
$ok( 'date="no" excerpt="no" leaves titles only', false === strpos( do_shortcode( '[npmp_newsletters date="no" excerpt="no"]' ), 'npmp-newsletter-excerpt' ) );
$paged = do_shortcode( '[npmp_newsletters limit="2"]' );
$ok( 'limit="2" shows two and links older ones', 2 === count( $titles( $paged ) ) && false !== strpos( $paged, 'npmp_nl_page=2' ) && false !== strpos( $paged, 'Older newsletters' ) );
$_GET['npmp_nl_page'] = '2';
$ok( 'page 2 holds the third', array( 'Old news' ) === $titles( do_shortcode( '[npmp_newsletters limit="2"]' ) ) );
unset( $_GET['npmp_nl_page'] );
$ok( 'pagination="no" drops the pager', false === strpos( do_shortcode( '[npmp_newsletters limit="2" pagination="no"]' ), 'npmp-newsletters-pager' ) );
wp_set_object_terms( $e, 'Events', 'npmp_newsletter_topic' );
$ok( 'topic="events" filters by topic', array( 'Old news' ) === $titles( do_shortcode( '[npmp_newsletters topic="events"]' ) ) );

echo "Groups\n";
$group_list = $titles( do_shortcode( '[npmp_newsletters group="computing"]' ) );
if ( $pro ) {
	$ok( 'group="computing" lists only that group\'s public newsletters', array( 'Computing group news' ) === $group_list );
	$ok( 'group="Computing" by name works too', array( 'Computing group news' ) === $titles( do_shortcode( '[npmp_newsletters group="Computing"]' ) ) );
	$ok( 'a group with no newsletters lists nothing', '' === do_shortcode( '[npmp_newsletters group="walking"]' ) );
	$ok( 'a group that doesn\'t exist lists nothing', '' === do_shortcode( '[npmp_newsletters group="nope"]' ) );
	$ok( 'the block passes its group through', array( 'Computing group news' ) === $titles( render_block( array( 'blockName' => 'nonprofit-manager/newsletters', 'attrs' => array( 'group' => 'computing' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) ) );
} else {
	$ok( 'without Pro, group="computing" lists nothing (never every newsletter)', '' === do_shortcode( '[npmp_newsletters group="computing"]' ) );
	$ok( 'without Pro, a ticked group newsletter is still in the full list', in_array( 'Computing group news', $list, true ) );
}

echo "Block\n";
$block = render_block( array( 'blockName' => 'nonprofit-manager/newsletters', 'attrs' => array( 'limit' => 1, 'layout' => 'grid' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
$ok( 'the block renders through the same list', array( 'March news for members' ) === $titles( $block ) );
$ok( 'the block wrapper has its class and the layout', false !== strpos( $block, 'wp-block-nonprofit-manager-newsletters' ) && false !== strpos( $block, 'npmp-newsletters--grid' ) );

echo "Public page in process\n";
$page = npmp_newsletter_archive_page_html( $a );
$ok( 'title with the tag default', false !== strpos( $page, '<h1 class="npmp-nlv-title">March news for members</h1>' ) );
$ok( 'send date', false !== strpos( $page, 'Sent ' ) );
$ok( 'body with the tag default', false !== strpos( $page, 'Hi Friend' ) );
$ok( 'noindex by default', false !== strpos( $page, 'content="noindex, follow"' ) );
$ok( 'no recipient name', false === strpos( $page, 'Patricia' ) );
$ok( 'no recipient address', false === strpos( $page, 'pat.reader' ) );
$ok( 'no unsubscribe token', false === strpos( $page, 'npmp_token' ) && 0 === preg_match( '/[?&;]token=/', $page ) );
$ok( 'no tracking URL', false === strpos( $page, 'npmp_track' ) && false === strpos( $page, '_npmp=' ) );
$ok( 'the plain link is there', false !== strpos( $page, 'https://example.org/walks?a=1' ) );
update_option( 'npmp_newsletter_archive_index', 1 );
$ok( 'the owner can let search engines in', false !== strpos( npmp_newsletter_archive_page_html( $a ), 'content="index, follow"' ) );
delete_option( 'npmp_newsletter_archive_index' );

echo "Public page over HTTP\n";
// php -S serves one request at a time, and the first front-end hit on a new
// site also spawns wp-cron on that same server, so every request gets a long
// timeout (5 seconds, the default, timed out on a brand-new rig).
$get = function ( $url, $args = array() ) {
	return wp_remote_get( $url, array_merge( array( 'timeout' => 60 ), $args ) );
};

// A stand-in for the plugins and themes that hang things off the_content:
// share buttons, related posts, comment prompts. Like the real ones it adds
// them on a singular page in the main loop, and it counts how many times
// the_content saw the newsletter's body.
$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
if ( ! is_dir( $mu_dir ) ) {
	wp_mkdir_p( $mu_dir );
}
$mu = $mu_dir . '/npmp-rig-content-extras.php';
file_put_contents(
	$mu,
	'<?php
$GLOBALS["npmp_rig_body_runs"] = 0;
add_filter( "the_content", function ( $c ) {
	if ( false !== strpos( $c, "our walk is on Friday" ) ) {
		$GLOBALS["npmp_rig_body_runs"]++;
	}
	if ( is_singular() && in_the_loop() && is_main_query() ) {
		$c .= "<div class=\"rig-share\">RIG-SHARE-BUTTONS</div><div class=\"rig-related\">RIG-RELATED-POSTS</div>";
	}
	return $c;
}, 20 );
add_action( "wp_footer", function () {
	echo "<!-- rig-body-runs:" . (int) $GLOBALS["npmp_rig_body_runs"] . " -->";
} );
add_filter( "template_include", function ( $t ) {
	if ( get_option( "npmp_rig_contentless_theme" ) ) {
		return __FILE__ . ".blank";
	}
	return $t;
}, 99 );
'
);
file_put_contents( $mu . '.blank', '<?php echo "<html><body>A template that never prints the content</body></html>";' );

// The plugin's own page injections, pointed at this newsletter's ID.
$form_before = (array) get_option( 'npmp_membership_form_settings', array() );
update_option( 'npmp_membership_form_settings', array_merge( $form_before, array( 'signup_page_id' => $a, 'unsubscribe_page_id' => $a ) ) );
update_option( 'npmp_calendar_page_id', $a );
update_option( 'npmp_donation_page_id', $a );

$url    = npmp_newsletter_archive_url( $a );
$themes = array_values( array_filter( array( 'twentytwentyfive', 'twentytwentyone' ), function ( $s ) {
	return wp_get_theme( $s )->exists();
} ) );
$ok( 'a block theme and a classic theme to test with (' . implode( ', ', $themes ) . ')', 2 === count( $themes ) );
$theme_before = get_stylesheet();

foreach ( $themes as $theme ) {
	switch_theme( $theme );
	$kind = wp_is_block_theme() ? 'block theme' : 'classic theme';
	echo "  -- $theme ($kind)\n";
	$res  = $get( $url );
	$code = (int) wp_remote_retrieve_response_code( $res );
	$http = (string) wp_remote_retrieve_body( $res );
	$ok( "$theme: the pretty URL answers 200", 200 === $code );
	$ok( "$theme: X-Robots-Tag noindex header", false !== stripos( (string) wp_remote_retrieve_header( $res, 'x-robots-tag' ), 'noindex' ) );
	$ok( "$theme: robots meta from wp_robots says noindex", 1 === preg_match( "#<meta name=['\"]robots['\"] content=['\"][^'\"]*noindex#", $http ) && 1 === substr_count( $http, 'name=\'robots\'' ) + substr_count( $http, 'name="robots"' ) );
	$ok( "$theme: inside the theme (its stylesheet and the site title)", false !== strpos( $http, 'themes/' . $theme . '/' ) && false !== strpos( $http, get_bloginfo( 'name' ) ) && false === strpos( (string) wp_remote_retrieve_header( $res, 'x-npmp-newsletter-view' ), 'standalone' ) );
	$ok( "$theme: the newsletter title is the page title", false !== strpos( $http, '<title>March news for members' ) && 1 === preg_match( '#<h1[^>]*>\s*March news for members\s*</h1>#', $http ) );
	$ok( "$theme: send date, body, and back link in the content", false !== strpos( $http, 'npmp-nlv-date' ) && false !== strpos( $http, 'Hi Friend, our walk is on Friday.' ) && false !== strpos( $http, 'class="npmp-nlv-back"' ) );
	$ok( "$theme: the page stylesheet is enqueued", false !== strpos( $http, 'npmp-newsletter-archive.css' ) );
	$ok( "$theme: one canonical, the newsletter's URL", 1 === substr_count( $http, 'rel="canonical"' ) && false !== strpos( $http, 'rel="canonical" href="' . esc_url( $url ) . '"' ) );
	$ok( "$theme: no token, address, name, or tracking", false === strpos( $http, 'npmp_token' ) && false === strpos( $http, 'pat.reader' ) && false === strpos( $http, 'Patricia' ) && false === strpos( $http, 'npmp_track' ) && false === strpos( $http, '_npmp=' ) );
	$ok( "$theme: no share buttons or related posts", false === strpos( $http, 'RIG-SHARE-BUTTONS' ) && false === strpos( $http, 'RIG-RELATED-POSTS' ) );
	$ok( "$theme: no comment form or comments", false === stripos( $http, 'id="respond"' ) && false === stripos( $http, 'comment-form' ) && false === stripos( $http, 'wp-block-comments' ) );
	$injected = array_filter( array( 'npmp-email-signup', 'npmp-unsubscribe', 'npmp-calendar-wrapper', 'npmp-donation-form' ), function ( $needle ) use ( $http ) {
		return false !== strpos( $http, $needle );
	} );
	$ok( "$theme: no signup, unsubscribe, calendar, or donation form injected" . ( $injected ? ' (found ' . implode( ', ', $injected ) . ')' : '' ), ! $injected );
	$ok( "$theme: the_content ran over the body once (the email render), never again", false !== strpos( $http, '<!-- rig-body-runs:1 -->' ) );
	$ok( "$theme: no shortlink or REST link for the stored newsletter", false === strpos( $http, "rel='shortlink'" ) && false === strpos( $http, 'wp-json/wp/v2/pages/' . $a ) && false === strpos( $http, 'wp-json/wp/v2/npmp_newsletter' ) );
	$res = $get( add_query_arg( array( 'npmp_email' => 'pat.reader@example.org', 'npmp_token' => 'feedface', 'uid' => '9' ), $url ) );
	$ok( "$theme: a visitor's own token in the URL never reaches the page", 200 === (int) wp_remote_retrieve_response_code( $res ) && false === strpos( (string) wp_remote_retrieve_body( $res ), 'feedface' ) && false === strpos( (string) wp_remote_retrieve_body( $res ), 'pat.reader' ) );
	$ok( "$theme: ?npmp_newsletter= works too", 200 === (int) wp_remote_retrieve_response_code( $get( add_query_arg( 'npmp_newsletter', $a, home_url( '/' ) ) ) ) );
	foreach ( array( 'not ticked' => $b, 'draft never sent' => $c, 'Send later' => $d, 'trashed' => $t, 'group, not ticked' => $g2 ) as $why => $id ) {
		$r = $get( home_url( '/newsletter/' . $id . '/x/' ), array( 'redirection' => 0 ) );
		$ok( "$theme: a $why newsletter answers 404", 404 === (int) wp_remote_retrieve_response_code( $r ) && false === strpos( (string) wp_remote_retrieve_body( $r ), 'our walk is on Friday' ) );
	}
	$ok( "$theme: a made-up ID answers 404", 404 === (int) wp_remote_retrieve_response_code( $get( home_url( '/newsletter/987654/' ), array( 'redirection' => 0 ) ) ) );
	$ok( "$theme: a ticked group newsletter has a page", 200 === (int) wp_remote_retrieve_response_code( $get( npmp_newsletter_archive_url( $g ) ) ) );
	update_option( 'npmp_newsletter_archive_index', 1 );
	$res = $get( $url );
	$ok( "$theme: the owner can let search engines in (no noindex anywhere)", false === stripos( (string) npmp_rig_headers_and_body( $res ), 'noindex' ) );
	delete_option( 'npmp_newsletter_archive_index' );
}

echo "  -- a theme whose template never prints the content\n";
update_option( 'npmp_rig_contentless_theme', 1 );
$res  = $get( $url );
$http = (string) wp_remote_retrieve_body( $res );
$ok( 'falls back to the standalone page', 200 === (int) wp_remote_retrieve_response_code( $res ) && 'standalone' === wp_remote_retrieve_header( $res, 'x-npmp-newsletter-view' ) && false !== strpos( $http, '<h1 class="npmp-nlv-title">March news for members</h1>' ) && false === strpos( $http, 'never prints the content' ) );
$ok( 'the fallback keeps noindex, and no token or tracking', false !== strpos( $http, 'content="noindex, follow"' ) && false === strpos( $http, 'npmp_token' ) && false === strpos( $http, 'npmp_track' ) );
delete_option( 'npmp_rig_contentless_theme' );

add_filter( 'npmp_newsletter_archive_use_theme', '__return_false' );
$ok( 'the npmp_newsletter_archive_use_theme filter is read', false === npmp_newsletter_archive_use_theme( $a ) );
remove_filter( 'npmp_newsletter_archive_use_theme', '__return_false' );

switch_theme( $theme_before );
update_option( 'npmp_membership_form_settings', $form_before );
delete_option( 'npmp_calendar_page_id' );
delete_option( 'npmp_donation_page_id' );
wp_delete_file( $mu );
wp_delete_file( $mu . '.blank' );

$list_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Computing Group', 'post_content' => '[npmp_newsletters]' ) );
$res       = $get( get_permalink( $list_page ) );
$ok( 'a page with the shortcode lists them over HTTP', false !== strpos( (string) wp_remote_retrieve_body( $res ), 'March news for members' ) );
$ok( 'the stylesheet is on that page', false !== strpos( (string) wp_remote_retrieve_body( $res ), 'npmp-newsletter-archive.css' ) );
$ok( 'links carry the back-link page', false !== strpos( (string) wp_remote_retrieve_body( $res ), 'npmp_from=' . $list_page ) );
$back = (string) wp_remote_retrieve_body( $get( npmp_newsletter_archive_url( $a, $list_page ) ) );
$ok( 'the public page links back to the list page', false !== strpos( $back, 'Back to Computing Group' ) );

echo "Empty site\n";
foreach ( array( $a, $e, $g ) as $id ) {
	delete_post_meta( $id, '_npmp_newsletter_public' );
}
$ok( 'no public newsletters renders nothing', '' === do_shortcode( '[npmp_newsletters]' ) );

printf( "\n%s: %d failed\n", $pro ? 'Pro active' : 'Pro inactive', $fails );
if ( $fails ) {
	exit( 1 );
}
