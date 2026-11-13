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

$GLOBALS['t_hooks'] = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['t_hooks'][] = array( $hook, $callback, $priority, $args );
	return true;
}
// Which page WordPress is rendering, for the archive excerpt.
$GLOBALS['t_view'] = array( 'archive' => true, 'tax' => false, 'admin' => false, 'feed' => false, 'protected' => false );
function is_post_type_archive( $type = '' ) {
	return $GLOBALS['t_view']['archive'] && 'npmp_event' === $type;
}
function is_tax( $tax = '' ) {
	return $GLOBALS['t_view']['tax'] && 'npmp_event_category' === $tax;
}
function is_admin() {
	return $GLOBALS['t_view']['admin'];
}
function is_feed() {
	return $GLOBALS['t_view']['feed'];
}
function post_password_required( $post = null ) {
	return $GLOBALS['t_view']['protected'];
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
check( 'list items come back one per line', "Opening, Rob Corley\nPolice report & crime stats", npmp_events_loop_text_from_content( $body ) );
check( 'iframe and shortcode dropped, paragraphs kept', "Hello\n\nworld", npmp_events_loop_text_from_content( '<figure><iframe src="x"></iframe></figure>[npmp_events]<p>Hello</p><p>world</p>' ) );
$post = (object) array( 'post_excerpt' => '', 'post_content' => $body );
check( 'trimmed to the word limit', "Opening, Rob Corley\nPolice report…", npmp_events_loop_excerpt( $post, 5 ) );
$post = (object) array( 'post_excerpt' => '<b>Manual</b> words here', 'post_content' => $body );
check( 'manual excerpt wins and is stripped', 'Manual words here', npmp_events_loop_excerpt( $post, 55 ) );
$post = (object) array( 'post_excerpt' => '', 'post_content' => '<h2>Only a heading</h2>' );
check( 'nothing left means empty', '', npmp_events_loop_excerpt( $post, 55 ) );

echo "An event body keeps its paragraph breaks in the list (ROS-4428, Pete at U3A)\n";
$walk = "<!-- wp:paragraph -->\n<p>We meet at the hall at 10am.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Bring stout shoes and a packed lunch.</p>\n<!-- /wp:paragraph -->";
$post = (object) array( 'post_excerpt' => '', 'post_content' => $walk );
check( 'two paragraphs stay two', "We meet at the hall at 10am.\n\nBring stout shoes and a packed lunch.", npmp_events_loop_excerpt( $post, 55 ) );
check( 'rendered as two <p>', '<p>We meet at the hall at 10am.</p><p>Bring stout shoes and a packed lunch.</p>', npmp_events_loop_excerpt_html( npmp_events_loop_excerpt( $post, 55 ) ) );
$post = (object) array( 'post_excerpt' => "Line one.\n\nLine two.", 'post_content' => $walk );
check( 'a manual excerpt keeps its break too', "Line one.\n\nLine two.", npmp_events_loop_excerpt( $post, 55 ) );
check( 'a single newline becomes a <br>', "<p>a<br />\nb</p>", npmp_events_loop_excerpt_html( "a\nb" ) );
check( 'empty text renders nothing', '', npmp_events_loop_excerpt_html( '   ' ) );
check( 'the text is escaped before any tag is added', '<p>&lt;script&gt;x&lt;/script&gt;</p>', npmp_events_loop_excerpt_html( '<script>x</script>' ) );
check( 'horizontal whitespace still collapses', 'a b', npmp_events_loop_normalize_text( "a  \t b" ) );
check( 'three blank lines become one paragraph break', "a\n\nb", npmp_events_loop_normalize_text( "a\n\n\n\nb" ) );
check( 'the word limit has a floor of five', "one two three four five…", npmp_events_loop_trim_words( 'one two three four five six', 1 ) );

echo "The theme's event archive keeps the paragraphs too\n";
check( 'the archive excerpt runs after core trims it', array( 'get_the_excerpt', 'npmp_events_archive_excerpt', 11, 2 ), $GLOBALS['t_hooks'][0] );
$event = (object) array( 'post_type' => 'npmp_event', 'post_excerpt' => '', 'post_content' => $walk );
check( 'two paragraphs come back with a blank line between them', "We meet at the hall at 10am.\n\nBring stout shoes and a packed lunch.", npmp_events_archive_excerpt( 'core flat text', $event ) );
$event->post_content = '<p>One &lt;b&gt; two & three</p><p>four</p>';
check( 'the text is escaped', "One &lt;b&gt; two &amp; three\n\nfour", npmp_events_archive_excerpt( 'core', $event ) );
$event->post_content = '<p>' . str_repeat( 'word ', 60 ) . '</p>';
check( 'a long body is cut at 55 words with core\'s more text', str_repeat( 'word ', 54 ) . 'word [&hellip;]', npmp_events_archive_excerpt( 'core', $event ) );
$event->post_content = $walk;
$event->post_excerpt = 'Written by hand.';
check( 'a manual excerpt keeps core\'s excerpt', 'core', npmp_events_archive_excerpt( 'core', $event ) );
$event->post_excerpt = '';
check( 'a post that is not an event is left alone', 'core', npmp_events_archive_excerpt( 'core', (object) array( 'post_type' => 'post', 'post_excerpt' => '', 'post_content' => $walk ) ) );
check( 'no post object is left alone', 'core', npmp_events_archive_excerpt( 'core', null ) );
$GLOBALS['t_view']['archive'] = false;
check( 'outside the event archive core\'s excerpt stays', 'core', npmp_events_archive_excerpt( 'core', $event ) );
$GLOBALS['t_view']['tax'] = true;
check( 'an event category page counts as the archive', true, 'core' !== npmp_events_archive_excerpt( 'core', $event ) );
$GLOBALS['t_view'] = array( 'archive' => true, 'tax' => false, 'admin' => false, 'feed' => true, 'protected' => false );
check( 'a feed keeps core\'s excerpt', 'core', npmp_events_archive_excerpt( 'core', $event ) );
$GLOBALS['t_view']['feed']      = false;
$GLOBALS['t_view']['protected'] = true;
check( 'a protected event keeps core\'s excerpt', 'core', npmp_events_archive_excerpt( 'core', $event ) );
$GLOBALS['t_view']['protected'] = false;
$GLOBALS['t_view']['admin']     = true;
check( 'wp-admin keeps core\'s excerpt', 'core', npmp_events_archive_excerpt( 'core', $event ) );
$GLOBALS['t_view']['admin'] = false;
$event->post_content        = '<h2>Only a heading</h2>';
check( 'nothing left after the headings keeps core\'s excerpt', 'core', npmp_events_archive_excerpt( 'core', $event ) );

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
