<?php
/**
 * Events loop (includes/npmp-events-loop.php): defaults, layering, sort
 * direction, query arguments, root element, and the auto-excerpt.
 *
 * Run: php tests/test-events-loop.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function wp_unslash( $v ) {
	return $v;
}
function update_option( $n, $v ) {
	$GLOBALS['t_options'][ $n ] = $v;
	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function current_time( $type ) {
	return '2026-11-01 12:00:00';
}
function sanitize_title( $value ) {
	return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) );
}
function sanitize_hex_color( $color ) {
	return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : '';
}
function strip_shortcodes( $content ) {
	return preg_replace( '/\[[a-z_]+[^\]]*\]/i', '', $content );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) );
}
function wp_trim_words( $text, $num_words = 55, $more = null ) {
	$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
	return count( $words ) > $num_words ? implode( ' ', array_slice( $words, 0, $num_words ) ) . $more : implode( ' ', $words );
}

require_once dirname( __DIR__ ) . '/includes/npmp-events-loop.php';

echo "Defaults keep an unsaved site as it was\n";
$d = npmp_events_loop_settings();
check( 'layout is list', 'list', $d['layout'] );
check( 'image is off', 0, $d['image'] );
check( 'excerpt is on', 1, $d['excerpt'] );
check( 'excerpt is 55 words', 55, $d['excerpt_length'] );
check( 'order is automatic', 'auto', $d['order'] );

echo "Layers: saved option, then per-list overrides\n";
$GLOBALS['t_options']['npmp_events_loop'] = array( 'layout' => 'grid', 'columns' => 4, 'image' => 1, 'excerpt_length' => 20 );
$s = npmp_events_loop_settings();
check( 'saved layout wins over default', 'grid', $s['layout'] );
check( 'saved columns', 4, $s['columns'] );
check( 'saved image', 1, $s['image'] );
$s = npmp_events_loop_settings( array( 'layout' => 'mosaic', 'image' => 'no', 'columns' => '' ) );
check( 'override layout', 'mosaic', $s['layout'] );
check( 'override image no', 0, $s['image'] );
check( 'blank override keeps saved columns', 4, $s['columns'] );

echo "Sanitising drops bad values\n";
$c = npmp_events_loop_sanitize( array( 'layout' => 'carousel', 'columns' => 9, 'order' => 'DESC', 'ratio' => '16-9', 'excerpt_length' => 9999, 'image' => 'maybe', 'date' => 'off' ) );
check( 'unknown layout dropped', false, isset( $c['layout'] ) );
check( 'columns out of range dropped', false, isset( $c['columns'] ) );
check( 'order is case-insensitive', 'desc', $c['order'] );
check( 'excerpt length capped', 200, $c['excerpt_length'] );
check( 'unrecognised boolean dropped', false, isset( $c['image'] ) );
check( 'off reads as 0', 0, $c['date'] );
check( 'non-array input', array(), npmp_events_loop_sanitize( 'x' ) );

echo "Sort direction\n";
check( 'auto + past archive is newest first', 'DESC', npmp_events_loop_direction( 'auto', true ) );
check( 'auto + upcoming is soonest first', 'ASC', npmp_events_loop_direction( 'auto', false ) );
check( 'desc forced', 'DESC', npmp_events_loop_direction( 'desc', false ) );
check( 'asc forced on an archive', 'ASC', npmp_events_loop_direction( 'asc', true ) );

echo "Query arguments\n";
$GLOBALS['t_options'] = array();
$q = npmp_events_loop_query_args( array( 'category' => 'Meetings', 'limit' => -1, 'past' => 'true' ), npmp_events_loop_settings() );
check( 'past archive sorts DESC', 'DESC', $q['order'] );
check( 'past archive has no date filter', false, isset( $q['meta_query'] ) );
check( 'all events', -1, $q['posts_per_page'] );
check( 'category slug', array( 'meetings' ), $q['tax_query'][0]['terms'] );
$q = npmp_events_loop_query_args( array( 'category' => '', 'limit' => 5, 'past' => 'false' ), npmp_events_loop_settings() );
check( 'upcoming sorts ASC', 'ASC', $q['order'] );
check( 'upcoming filters from now', '2026-11-01 12:00:00', $q['meta_query'][0]['value'] );
check( 'no row count for a list with no pager', true, $q['no_found_rows'] );
check( 'no category, no tax query', false, isset( $q['tax_query'] ) );

echo "Root element\n";
$r = npmp_events_loop_root( npmp_events_loop_settings( array( 'layout' => 'mosaic', 'columns' => 2, 'image' => 1, 'ratio' => '4-3' ) ) );
check( 'classes', 'npmp-events-list npmp-events--mosaic npmp-events--cols-2 npmp-events--with-image', $r['class'] );
check( 'ratio var', true, false !== strpos( $r['style'], '--npmp-events-ratio:4 / 3' ) );
check( 'accent var', true, false !== strpos( $r['style'], '--npmp-cal-accent:#0f766e' ) );
$GLOBALS['t_options']['npmp_calendar_accent'] = 'javascript:alert(1)';
$r = npmp_events_loop_root( npmp_events_loop_settings() );
check( 'bad accent never reaches the style', false, strpos( $r['style'], 'javascript' ) );

echo "Block gap\n";
check( 'length passes', '2rem', npmp_events_loop_gap_css( '2rem' ) );
check( 'preset becomes a var', 'var(--wp--preset--spacing--40)', npmp_events_loop_gap_css( 'var:preset|spacing|40' ) );
check( 'split gap is row then column', '1rem 2rem', npmp_events_loop_gap_css( array( 'top' => '1rem', 'left' => '2rem' ) ) );
check( 'one side fills both', '1rem 1rem', npmp_events_loop_gap_css( array( 'top' => '1rem' ) ) );
check( 'CSS injection refused', '', npmp_events_loop_gap_css( '1rem;background:url(x)' ) );
check( 'junk refused', '', npmp_events_loop_gap_css( array( 'top' => 'calc(1px)' ) ) );
check( 'gap lands in the root style', true, false !== strpos( npmp_events_loop_root( npmp_events_loop_settings(), '2rem' )['style'], ';gap:2rem' ) );

echo "Auto-excerpt skips the date heading and the Agenda heading\n";
$body = "<!-- wp:heading {\"level\":3} -->\n<h3><strong>Wednesday, September 25, 2024</strong></h3>\n<!-- /wp:heading -->\n<!-- wp:heading --><h3>Agenda</h3><!-- /wp:heading -->\n<!-- wp:list --><ul><li>Opening, Rob Corley</li><li>Police report &amp; crime stats</li></ul><!-- /wp:list -->";
check( 'text comes from the list', 'Opening, Rob Corley Police report & crime stats', npmp_events_loop_text_from_content( $body ) );
check( 'iframe and shortcode dropped', 'Hello world', npmp_events_loop_text_from_content( '<figure><iframe src="x"></iframe></figure>[npmp_events]<p>Hello</p><p>world</p>' ) );
$post = (object) array( 'post_excerpt' => '', 'post_content' => $body );
check( 'trimmed to the word limit', 'Opening, Rob Corley Police report…', npmp_events_loop_excerpt( $post, 5 ) );
$post = (object) array( 'post_excerpt' => '<b>Manual</b> words here', 'post_content' => $body );
check( 'manual excerpt wins and is stripped', 'Manual words here', npmp_events_loop_excerpt( $post, 55 ) );
$post = (object) array( 'post_excerpt' => '', 'post_content' => '<h2>Only a heading</h2>' );
check( 'nothing left means empty', '', npmp_events_loop_excerpt( $post, 55 ) );

echo "Settings save leaves unchecked boxes off\n";
$GLOBALS['t_options'] = array();
$_POST = array( 'npmp_events_loop' => array( 'layout' => 'cards', 'columns' => '2', 'excerpt_length' => '30', 'image' => '1', 'junk' => 'x' ) );
npmp_events_loop_save_from_post();
$saved = $GLOBALS['t_options']['npmp_events_loop'];
check( 'layout saved', 'cards', $saved['layout'] );
check( 'checked box saved on', 1, $saved['image'] );
check( 'unchecked excerpt saved off', 0, $saved['excerpt'] );
check( 'unchecked location saved off', 0, $saved['location'] );
check( 'unknown keys dropped', false, isset( $saved['junk'] ) );
check( 'saved settings feed the loop', 'cards', npmp_events_loop_settings()['layout'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
