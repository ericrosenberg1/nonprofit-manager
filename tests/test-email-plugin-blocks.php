<?php
/**
 * Other plugins' blocks in a newsletter's email, and the [preferences_url]
 * footer tag (Pete on wordpress.org, topics "more-plugins" and "subscribe_url").
 *
 * ONLYOFFICE's embedded viewer left a gap in the email, so the email gets its
 * link view. A TablePress table printed every row, so the email keeps the
 * first page and says how many rows there are. The footer takes
 * [preferences_url] (and [subscribe_url]) for the page where a reader picks
 * their emails and groups.
 *
 * Run: php tests/test-email-plugin-blocks.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npmp_t_filters'] = array();
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['npmp_t_filters'][ $hook ] = $cb;
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function shortcode_parse_atts( $text ) {
	$out = array();
	preg_match_all( '/(\w+)=("[^"]*"|\S+)/', (string) $text, $m, PREG_SET_ORDER );
	foreach ( $m as $p ) {
		$out[ strtolower( $p[1] ) ] = trim( $p[2], '"' );
	}
	return $out;
}
$GLOBALS['npmp_t_email']   = true;
$GLOBALS['npmp_t_web']     = false;
$GLOBALS['npmp_t_target']  = '';
function npmp_email_is_rendering() {
	return $GLOBALS['npmp_t_email'];
}
function npmp_newsletter_web_render() {
	return $GLOBALS['npmp_t_web'];
}
function npmp_email_embed_target() {
	return $GLOBALS['npmp_t_target'];
}
function npmp_email_embed_href( $url ) {
	return esc_url( $url );
}

require_once dirname( __DIR__ ) . '/includes/email-newsletter/email-plugin-blocks.php';

echo "TablePress page length\n";
check( 'TablePress default is 10', 10, npmp_email_tablepress_limit( array(), array() ) );
check( 'the table\'s own setting', 20, npmp_email_tablepress_limit( array(), array( 'datatables_paginate_entries' => 20 ) ) );
check( 'the block parameter wins', 5, npmp_email_tablepress_limit( array( 'datatables_paginate_entries' => '5' ), array( 'datatables_paginate_entries' => 20 ) ) );
check( 'pagination off shows every row', 0, npmp_email_tablepress_limit( array(), array( 'datatables_paginate' => false ) ) );
check( 'pagination off on the shortcode', 0, npmp_email_tablepress_limit( array( 'datatables_paginate' => 'false' ), array( 'datatables_paginate' => true ) ) );
check( 'no DataTables shows every row', 0, npmp_email_tablepress_limit( array( 'use_datatables' => 'false' ), array() ) );
check( 'a bad number falls back to 10', 10, npmp_email_tablepress_limit( array( 'datatables_paginate_entries' => '0' ), array() ) );

echo "Trimming rows\n";
$rows  = '';
for ( $i = 1; $i <= 25; $i++ ) {
	$rows .= '<tr class="row-' . ( $i + 1 ) . '"><td>Row ' . $i . '</td></tr>';
}
$table = '<h2>Events</h2><table id="tablepress-8"><thead><tr class="row-1"><th>Date</th></tr></thead><tbody class="row-striping">' . $rows . '</tbody><tfoot><tr><th>Foot</th></tr></tfoot></table>';
$trim  = npmp_email_table_trim( $table, 10 );
check( 'kept 10', 10, $trim['kept'] );
check( 'counted 25', 25, $trim['total'] );
check( 'row 10 stays', true, false !== strpos( $trim['html'], 'Row 10<' ) );
check( 'row 11 goes', false, strpos( $trim['html'], 'Row 11<' ) );
check( 'the header stays', true, false !== strpos( $trim['html'], '<th>Date</th>' ) );
check( 'the footer stays', true, false !== strpos( $trim['html'], '<th>Foot</th>' ) );
check( 'the name above stays', 0, strpos( $trim['html'], '<h2>Events</h2>' ) );
check( 'a short table is left alone', $table, npmp_email_table_trim( $table, 30 )['html'] );
check( 'no limit leaves it alone', $table, npmp_email_table_trim( $table, 0 )['html'] );
check( 'no tbody is left alone', '<p>x</p>', npmp_email_table_trim( '<p>x</p>', 5 )['html'] );

echo "The line under a trimmed table\n";
$note = npmp_email_table_note( 10, 25 );
check( 'says how many', true, false !== strpos( $note, 'Showing the first 10 of 25 rows.' ) );
check( 'no web version, no link', false, strpos( $note, '<a ' ) );
$note = npmp_email_table_note( 10, 25, 'https://example.org/newsletter/3/may/' );
check( 'links the web version', true, false !== strpos( $note, '<a href="https://example.org/newsletter/3/may/">See the full table</a>' ) );

echo "TablePress block\n";
$block = array( 'blockName' => 'tablepress/table', 'attrs' => array( 'id' => '8', 'parameters' => 'datatables_paginate_entries=5' ) );
$out   = npmp_email_plugin_blocks_render_block( $table, $block );
check( 'the email keeps the parameter\'s 5 rows', true, false !== strpos( $out, 'Row 5<' ) && false === strpos( $out, 'Row 6<' ) );
check( 'and adds the note', true, false !== strpos( $out, 'Showing the first 5 of 25 rows.' ) );
$GLOBALS['npmp_t_web'] = true;
check( 'the web version keeps every row', $table, npmp_email_plugin_blocks_render_block( $table, $block ) );
$GLOBALS['npmp_t_web']   = false;
$GLOBALS['npmp_t_email'] = false;
check( 'a page keeps every row', $table, npmp_email_plugin_blocks_render_block( $table, $block ) );
check( 'TablePress shows its Edit link on a page', true, npmp_email_plugin_blocks_tablepress_edit_link( true ) );
$GLOBALS['npmp_t_email'] = true;
check( 'no Edit link in an email', false, npmp_email_plugin_blocks_tablepress_edit_link( true ) );
check( 'other blocks pass through', '<p>x</p>', npmp_email_plugin_blocks_render_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ) ) );

echo "ONLYOFFICE\n";
$GLOBALS['npmp_t_oo'] = array();
$GLOBALS['shortcode_tags'] = array(
	'onlyoffice' => static function ( $atts ) {
		$GLOBALS['npmp_t_oo'] = $atts;
		if ( 'link' === ( $atts['documentView'] ?? 'embedded' ) ) {
			return '<div class="wp-block-onlyoffice-wordpress-onlyoffice"><a href="https://example.org/wp-content/plugins/onlyoffice/editor.php?attachment_id=' . $atts['id'] . '">' . ( $atts['fileName'] ?? '' ) . '</a></div>';
		}
		return '<div style="height: 500px;"><div id="editorOnlyoffice-1" data-config="{}"></div></div>';
	},
);
$embedded = '<div style="height: 500px;"><div id="editorOnlyoffice-2"></div></div>';
$block    = array( 'blockName' => 'onlyoffice-wordpress/onlyoffice', 'attrs' => array( 'id' => 94642, 'fileName' => 'Fediverse.pptx' ) );
$out      = npmp_email_plugin_blocks_render_block( $embedded, $block );
check( 'the embedded viewer becomes the link view', true, false !== strpos( $out, 'editor.php?attachment_id=94642">Fediverse.pptx</a>' ) );
check( 'no gap left', false, strpos( $out, 'height: 500px' ) );
$link  = '<div><a href="x">Fediverse.pptx</a></div>';
$block['attrs']['documentView'] = 'link';
check( 'a link view stays as it is', $link, npmp_email_plugin_blocks_render_block( $link, $block ) );
$GLOBALS['npmp_t_email'] = false;
unset( $block['attrs']['documentView'] );
check( 'a page keeps the viewer', $embedded, npmp_email_plugin_blocks_render_block( $embedded, $block ) );
$GLOBALS['npmp_t_email'] = true;
$out = npmp_email_plugin_blocks_pre_shortcode( false, 'onlyoffice', array( 'id' => '7', 'filename' => 'Minutes.docx', 'documentview' => 'embedded' ) );
check( 'a bare shortcode becomes the link view too', true, false !== strpos( (string) $out, '>Minutes.docx</a>' ) );
check( 'with the file name in camelCase', 'Minutes.docx', $GLOBALS['npmp_t_oo']['fileName'] ?? '' );
check( 'other shortcodes run as normal', false, npmp_email_plugin_blocks_pre_shortcode( false, 'gallery', array() ) );
$GLOBALS['shortcode_tags'] = array();
check( 'with ONLYOFFICE gone, the gap goes too', '', npmp_email_plugin_blocks_render_block( $embedded, $block ) );

echo "Footer [preferences_url]\n";
function get_option( $name, $default = false ) {
	return 'npmp_newsletter_can_spam_footer' === $name ? $GLOBALS['npmp_t_footer'] : $default;
}
function get_bloginfo( $what ) {
	return 'U3A';
}
function wp_kses_post( $html ) {
	return $html;
}
function sanitize_email( $email ) {
	return trim( (string) $email );
}
function is_email( $email ) {
	return false !== strpos( (string) $email, '@' );
}
function get_permalink( $id ) {
	return 'https://example.org/preferences/';
}
function add_shortcode() {
	return true;
}
function add_action() {
	return true;
}
function npmp_can_spam_get_address() {
	return '1 Main St';
}
function npmp_can_spam_get_unsubscribe_url( $email = '' ) {
	return 'https://example.org/unsubscribe/';
}
$GLOBALS['npmp_t_prefs_page'] = 42;
function npmp_get_preferences_page_id() {
	return $GLOBALS['npmp_t_prefs_page'];
}
function npmp_get_preferences_url( $email ) {
	return 'https://example.org/preferences/?email=' . rawurlencode( $email ) . '&token=abc';
}
require_once dirname( __DIR__ ) . '/includes/email-newsletter/can-spam-shortcode.php';

$GLOBALS['npmp_t_footer'] = 'From [organization]. <a href="[unsubscribe_url]">Unsubscribe</a> | <a href="[subscribe_url]">Choose your groups</a> [preferences_url]';
$out = npmp_can_spam_shortcode( array( 'email' => 'pete@example.org' ) );
check( '[subscribe_url] gets the reader\'s signed preferences link', true, false !== strpos( $out, '<a href="https://example.org/preferences/?email=pete%40example.org&amp;token=abc">Choose your groups</a>' ) );
check( '[preferences_url] too', true, false !== strpos( $out, '</a> https://example.org/preferences/?email=' ) );
check( '[unsubscribe_url] still works', true, false !== strpos( $out, 'href="https://example.org/unsubscribe/"' ) );
$out = npmp_can_spam_shortcode( array() );
check( 'with no reader, the bare page', true, false !== strpos( $out, 'href="https://example.org/preferences/"' ) );
$GLOBALS['npmp_t_prefs_page'] = 0;
$out = npmp_can_spam_shortcode( array( 'email' => 'pete@example.org' ) );
check( 'no preferences page drops the link', false, strpos( $out, 'Choose your groups' ) );
check( 'and the bare tag', false, strpos( $out, '[preferences_url]' ) );
check( 'and keeps the unsubscribe link', true, false !== strpos( $out, 'Unsubscribe</a>' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
