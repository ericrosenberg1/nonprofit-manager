<?php
/**
 * Real-WordPress check of other plugins' blocks in a newsletter's email version
 * (includes/email-newsletter/email-plugin-blocks.php) and of the [preferences_url]
 * and [subscribe_url] footer tags (npmp_can_spam_get_preferences_url()).
 *
 * Usage: a throwaway WordPress (SQLite recipe in CLAUDE.md, "Testing beyond unit tests")
 * with TablePress 3.4.1, ONLYOFFICE 2.3.0, and this repo symlinked into wp-content/plugins
 * and active, with the newsletters module on. Then, from that WordPress's folder:
 *   wp eval-file /path/to/nonprofit-manager/tests/rig/email-plugin-blocks.php
 * Prints PASS or FAIL for each check and exits 1 on any failure.
 *
 * Builds a TablePress table with 25 body rows (through TablePress's own model), a fake
 * .docx attachment, and a newsletter holding every case. Renders it as email, as the
 * web version, and as a normal page, logged in as an admin (so TablePress would print
 * its Edit link). Deletes what it makes and restores the footer option.
 */

$fails  = 0;
$passes = 0;
$check  = function ( $label, $cond, $detail = '' ) use ( &$fails, &$passes ) {
	if ( $cond ) {
		$passes++;
	} else {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
	if ( ! $cond && '' !== $detail ) {
		echo '        ' . $detail . "\n";
	}
};
$rows_in = function ( $html ) {
	$tables = array();
	preg_match_all( '#<table\b.*?</table>#is', $html, $t );
	foreach ( $t[0] as $table ) {
		if ( preg_match( '#<tbody\b[^>]*>(.*?)</tbody>#is', $table, $b ) ) {
			$tables[] = preg_match_all( '#<tr\b#i', $b[1] );
		}
	}
	return $tables;
};

foreach ( array( 'NPMP_Newsletter_Manager', 'TablePress' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "  NOTE  $class is missing. Activate TablePress, ONLYOFFICE, and the plugin, and turn on the newsletters module.\n";
		exit( 1 );
	}
}
if ( ! function_exists( 'npmp_email_plugin_blocks_render_block' ) ) {
	echo "  NOTE  email-plugin-blocks.php is not loaded.\n";
	exit( 1 );
}
global $shortcode_tags;
if ( empty( $shortcode_tags['onlyoffice'] ) || empty( $shortcode_tags['table'] ) ) {
	echo "  NOTE  the onlyoffice or table shortcode is not registered.\n";
	exit( 1 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );

$old_footer = get_option( 'npmp_newsletter_can_spam_footer', null );
$made_posts = array();

// TablePress table with 25 body rows.
$table         = TablePress::$model_table->get_table_template();
$table['name'] = 'Rig table';
$data          = array( array( 'Name', 'Number' ) );
for ( $i = 1; $i <= 25; $i++ ) {
	$data[] = array( 'Row ' . $i, (string) $i );
}
$table['data']       = $data;
$table['visibility'] = array(
	'rows'    => array_fill( 0, 26, 1 ),
	'columns' => array( 1, 1 ),
);
$tid = TablePress::$model_table->add( $table );
$check( 'TablePress table made', is_string( $tid ) && '' !== $tid, is_wp_error( $tid ) ? $tid->get_error_message() : '' );

// A fake .docx for ONLYOFFICE.
$up = wp_upload_bits( 'rig-doc.docx', null, 'not really a docx' );
$aid = wp_insert_attachment(
	array(
		'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'post_title'     => 'rig-doc',
		'post_status'    => 'inherit',
	),
	$up['file']
);
$made_posts[] = $aid;
$check( 'attachment made', $aid > 0 );

$oo_embed = '<!-- wp:onlyoffice-wordpress/onlyoffice {"id":' . $aid . ',"fileName":"rig-doc.docx"} /-->';
$oo_link  = '<!-- wp:onlyoffice-wordpress/onlyoffice {"id":' . $aid . ',"fileName":"rig-doc.docx","documentView":"link"} /-->';
$oo_short = '<!-- wp:shortcode -->[onlyoffice id=' . $aid . ']<!-- /wp:shortcode -->';
$tp_plain = '<!-- wp:tablepress/table {"id":"' . $tid . '"} /-->';
$tp_five  = '<!-- wp:tablepress/table {"id":"' . $tid . '","parameters":"datatables_paginate_entries=5"} /-->';
$tp_short = '<!-- wp:shortcode -->[table id=' . $tid . ' /]<!-- /wp:shortcode -->';
$body     = '<!-- wp:paragraph --><p>Intro text.</p><!-- /wp:paragraph -->'
	. $oo_embed . $oo_link . $oo_short . $tp_plain . $tp_five . $tp_short
	. '<!-- wp:paragraph --><p>Closing text.</p><!-- /wp:paragraph -->';

$nid = wp_insert_post(
	array(
		'post_type'    => 'npmp_newsletter',
		'post_status'  => 'publish',
		'post_title'   => 'Rig plugin blocks',
		'post_content' => $body,
	)
);
$made_posts[] = $nid;
update_post_meta( $nid, '_npmp_newsletter_template', 'none' );
update_post_meta( $nid, '_npmp_newsletter_public', '1' );
update_post_meta( $nid, '_npmp_newsletter_status', 'sent' );
$web_url = npmp_newsletter_web_version_url( $nid );
$check( 'newsletter has a web version URL', '' !== $web_url );

$pid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Rig page', 'post_content' => $body ) );
$made_posts[] = $pid;

$editor_link = 'editor.php?attachment_id=' . $aid;

echo "Email version\n";
$email = (string) NPMP_Newsletter_Manager::render_base_content( $nid );
$check( 'no editorOnlyoffice div', false === strpos( $email, 'editorOnlyoffice' ), substr( $email, max( 0, (int) strpos( $email, 'editorOnlyoffice' ) - 80 ), 300 ) );
$check( 'no data-config embed target', false === strpos( $email, 'wp-block-onlyoffice-wordpress-onlyoffice__target' ) );
// ONLYOFFICE's link view prints two anchors per file (a hidden one and the Open button).
$n_links = substr_count( $email, $editor_link );
$n_views = substr_count( $email, 'id="linkToOnlyofficeEditor-' );
$check( 'editor.php?attachment_id link for each of 3 ONLYOFFICE cases', 3 === $n_views && 6 === $n_links, "link views $n_views, links $n_links" );
$counts = $rows_in( $email );
$check( 'three tables with 10 / 5 / 10 body rows', array( 10, 5, 10 ) === $counts, 'found ' . wp_json_encode( $counts ) );
$check( 'note "Showing the first 10 of 25 rows." twice', 2 === substr_count( $email, 'Showing the first 10 of 25 rows.' ) );
$check( 'note "Showing the first 5 of 25 rows." once', 1 === substr_count( $email, 'Showing the first 5 of 25 rows.' ) );
$check( '"See the full table" link to the web version x3', 3 === substr_count( $email, '>See the full table</a>' ) && 3 === substr_count( $email, 'href="' . esc_url( $web_url ) . '">See the full table' ), 'web url ' . $web_url );
$check( 'no Edit link (page=tablepress)', false === strpos( $email, 'page=tablepress' ) );
$check( 'row 11 gone, row 10 kept, row 5 kept', false === strpos( $email, 'Row 11<' ) && false !== strpos( $email, 'Row 10<' ) );
$check( 'text around the blocks stays', false !== strpos( $email, 'Intro text.' ) && false !== strpos( $email, 'Closing text.' ) );

echo "Web version\n";
$web = (string) npmp_newsletter_archive_body( $nid );
$counts = $rows_in( $web );
$check( 'all three tables keep 25 rows', array( 25, 25, 25 ) === $counts, 'found ' . wp_json_encode( $counts ) );
$check( 'no trim note', false === strpos( $web, 'Showing the first' ) );
$check( 'embedded ONLYOFFICE div kept (2 embedded cases)', 2 === substr_count( $web, 'id="editorOnlyoffice-' ), 'found ' . substr_count( $web, 'id="editorOnlyoffice-' ) );
$check( 'ONLYOFFICE link-view block still a link', 1 <= substr_count( $web, $editor_link ) );

echo "Normal page\n";
$GLOBALS['post'] = get_post( $pid );
setup_postdata( $GLOBALS['post'] );
$page = (string) apply_filters( 'the_content', get_post_field( 'post_content', $pid ) );
$counts = $rows_in( $page );
$check( 'all three tables keep 25 rows', array( 25, 25, 25 ) === $counts, 'found ' . wp_json_encode( $counts ) );
$check( 'no trim note', false === strpos( $page, 'Showing the first' ) );
$check( 'embedded ONLYOFFICE div kept (2 embedded cases)', 2 === substr_count( $page, 'id="editorOnlyoffice-' ) );
$check( 'TablePress prints its Edit link here (so the email check means something)', false !== strpos( $page, 'page=tablepress' ) );
wp_reset_postdata();

echo "Email render flag is back off afterwards\n";
$check( 'npmp_email_is_rendering() is false', ! npmp_email_is_rendering() );

echo "Footer tags\n";
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Email preferences',
		'post_name'    => 'email-preferences',
		'post_content' => '[npmp_manage_preferences]',
	)
);
$made_posts[] = $page_id;
delete_option( 'npmp_preferences_page_id' );
delete_transient( 'npmp_prefs_page_lookup' );
update_option( 'npmp_newsletter_can_spam_footer', '<p>Hello <a href="[subscribe_url]">Groups</a> and <a href="[preferences_url]">Prefs</a> and [unsubscribe_url]</p>' );
$footer = npmp_can_spam_shortcode( array( 'email' => 'x@example.org' ) );
$base   = get_permalink( $page_id );
$check( 'preferences page found', (int) get_option( 'npmp_preferences_page_id' ) === $page_id );
$m = preg_match_all( '#<a href="([^"]+)">(Groups|Prefs)</a>#', $footer, $mm, PREG_SET_ORDER );
$check( 'both links print', 2 === $m, $footer );
$urls_ok = 2 === $m;
foreach ( $mm as $one ) {
	$u = html_entity_decode( $one[1] );
	$urls_ok = $urls_ok && 0 === strpos( $u, $base ) && preg_match( '#email=x%(25)?40example\.org#', $u ) && preg_match( '#token=[A-Za-z0-9._%-]+#', $u );
}
$check( 'both go to the page URL with email and token', $urls_ok, $footer );
$check( 'no raw tag left', false === strpos( $footer, '[preferences_url]' ) && false === strpos( $footer, '[subscribe_url]' ) );
if ( $m ) {
	echo '        link: ' . html_entity_decode( $mm[0][1] ) . "\n";
}

wp_trash_post( $page_id );
delete_transient( 'npmp_prefs_page_lookup' );
delete_option( 'npmp_preferences_page_id' );
$footer = npmp_can_spam_shortcode( array( 'email' => 'x@example.org' ) );
$check( 'Groups link gone with no page', false === strpos( $footer, '>Groups</a>' ) && false === strpos( $footer, '[subscribe_url]' ), $footer );
$check( 'Prefs link gone with no page', false === strpos( $footer, '>Prefs</a>' ) && false === strpos( $footer, '[preferences_url]' ), $footer );

// Clean up.
foreach ( $made_posts as $p ) {
	wp_delete_post( $p, true );
}
if ( is_string( $tid ) ) {
	TablePress::$model_table->delete( $tid );
}
if ( null === $old_footer ) {
	delete_option( 'npmp_newsletter_can_spam_footer' );
} else {
	update_option( 'npmp_newsletter_can_spam_footer', $old_footer );
}
delete_transient( 'npmp_prefs_page_lookup' );
delete_option( 'npmp_preferences_page_id' );

printf( "\n%d passed, %d failed\n", $passes, $fails );
exit( $fails ? 1 : 0 );
