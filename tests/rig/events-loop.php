<?php
/**
 * Real-WordPress check of the events loop: order, layouts, image and excerpt,
 * the block's appearance supports, and the settings screen rows.
 *
 * Run inside a throwaway WordPress with the plugin active (CLAUDE.md, "Testing
 * beyond unit tests"):  wp eval-file tests/rig/events-loop.php
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

delete_option( 'npmp_events_loop' );
foreach ( get_posts( array( 'post_type' => 'npmp_event', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
if ( ! term_exists( 'meetings', 'npmp_event_category' ) ) {
	wp_insert_term( 'Meetings', 'npmp_event_category', array( 'slug' => 'meetings' ) );
}

$make = function ( $title, $start, $content, $excerpt = '' ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'npmp_event',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
		)
	);
	update_post_meta( $id, '_npmp_event_start', $start );
	update_post_meta( $id, '_npmp_event_end', $start );
	wp_set_object_terms( $id, 'meetings', 'npmp_event_category' );
	return $id;
};

$body = "<!-- wp:heading {\"level\":3} --><h3>Wednesday, September 25, 2024</h3><!-- /wp:heading --><!-- wp:heading --><h3>Agenda</h3><!-- /wp:heading --><!-- wp:list --><ul><li>Opening remarks from the vice chair</li><li>Police report</li></ul><!-- /wp:list -->";
$a    = $make( 'Meeting A', '2024-08-28 19:00:00', $body );
$b    = $make( 'Meeting B', '2024-09-25 19:00:00', $body, 'A written summary for B.' );
$c    = $make( 'Meeting C', '2024-10-23 19:00:00', $body );
$d    = $make( 'Meeting D', '2099-01-27 19:00:00', $body );

// A real attachment for B so one tile has a featured image.
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
$tmp = wp_tempnam( 'tile.png' );
file_put_contents( $tmp, $png );
$att = media_handle_sideload( array( 'name' => 'tile.png', 'tmp_name' => $tmp ), $b );
if ( ! is_wp_error( $att ) ) {
	set_post_thumbnail( $b, $att );
}

echo "Default shortcode (an unsaved site)\n";
$html = do_shortcode( '[npmp_events past="true" category="meetings" limit="-1"]' );
preg_match_all( '#npmp-event-title"><a [^>]*>([^<]+)#', $html, $m );
$ok( 'archive lists newest first: D, C, B, A', array( 'Meeting D', 'Meeting C', 'Meeting B', 'Meeting A' ) === $m[1] );
$ok( 'plain list layout', false !== strpos( $html, 'npmp-events--list' ) );
$ok( 'no image markup by default', false === strpos( $html, 'npmp-event-media' ) );
$ok( 'manual excerpt used for B', false !== strpos( $html, 'A written summary for B.' ) );
$ok( 'auto excerpt skips the headings', false !== strpos( $html, 'Opening remarks from the vice chair' ) && false !== strpos( $html, 'Police report' ) && false === strpos( $html, 'Wednesday, September' ) );
// ROS-4428: the two list items stay on their own lines instead of running together.
$ok( 'auto excerpt keeps the line break between list items', false !== strpos( $html, "Opening remarks from the vice chair<br />\nPolice report" ) );
$ok( 'excerpt text sits in its own <p>', (bool) preg_match( '#<div class="npmp-event-excerpt"><p>#', $html ) );

echo "Upcoming list stays soonest first\n";
$html = do_shortcode( '[npmp_events category="meetings"]' );
preg_match_all( '#npmp-event-title"><a [^>]*>([^<]+)#', $html, $m );
$ok( 'only the future event, no past ones', array( 'Meeting D' ) === $m[1] );
$html = do_shortcode( '[npmp_events past="true" order="asc" category="meetings"]' );
preg_match_all( '#npmp-event-title"><a [^>]*>([^<]+)#', $html, $m );
$ok( 'order="asc" overrides the archive default', 'Meeting A' === $m[1][0] );

echo "Layouts, images, tiles\n";
$html = do_shortcode( '[npmp_events past="true" layout="grid" columns="4" image="yes" readmore="yes" category="meetings"]' );
$ok( 'grid root class', false !== strpos( $html, 'npmp-events--grid npmp-events--cols-4 npmp-events--with-image' ) );
$ok( 'featured image on B', 1 === preg_match( '#npmp-event--has-image.*?npmp-event-image#s', $html ) );
$ok( 'date tile on the others', substr_count( $html, 'npmp-event-media-fallback' ) === 3 );
$ok( 'read more link has screen reader title', false !== strpos( $html, 'screen-reader-text"> Meeting A' ) );
foreach ( array( 'cards', 'mosaic' ) as $layout ) {
	$html = do_shortcode( '[npmp_events past="true" layout="' . $layout . '" category="meetings"]' );
	$ok( $layout . ' renders four events', 4 === substr_count( $html, '<article' ) );
}

echo "Saved settings drive the shortcode\n";
update_option( 'npmp_events_loop', array( 'layout' => 'cards', 'columns' => 2, 'image' => 1, 'excerpt' => 0, 'location' => 0 ) );
$html = do_shortcode( '[npmp_events past="true" category="meetings"]' );
$ok( 'saved layout applies', false !== strpos( $html, 'npmp-events--cards npmp-events--cols-2' ) );
$ok( 'saved excerpt off applies', false === strpos( $html, 'npmp-event-excerpt' ) );
$html = do_shortcode( '[npmp_events past="true" excerpt="yes" layout="list" category="meetings"]' );
$ok( 'shortcode attribute beats saved setting', false !== strpos( $html, 'npmp-event-excerpt' ) && false !== strpos( $html, 'npmp-events--list' ) );

echo "Calendar list view stays a plain list in date order\n";
update_option( 'npmp_calendar_list_show_past', 1 );
update_option( 'npmp_calendar_list_limit', 10 );
$html = npmp_calendar_render_list_view( 'meetings' );
preg_match_all( '#npmp-event-title"><a [^>]*>([^<]+)#', $html, $m );
$ok( 'list view ascending', 'Meeting A' === $m[1][0] );
$ok( 'list view ignores saved grid and image', false !== strpos( $html, 'npmp-events--list' ) && false === strpos( $html, 'npmp-event-media' ) );
delete_option( 'npmp_calendar_list_show_past' );

echo "Block: appearance supports reach the wrapper\n";
delete_option( 'npmp_events_loop' );
$registry = WP_Block_Type_Registry::get_instance()->get_registered( 'nonprofit-manager/events' );
$s        = $registry->supports;
$ok( 'color supports declared', ! empty( $s['color']['background'] ) && ! empty( $s['color']['text'] ) && ! empty( $s['color']['link'] ) );
$ok( 'typography supports declared', ! empty( $s['typography']['fontSize'] ) );
$ok( 'spacing supports declared', ! empty( $s['spacing']['padding'] ) && ! empty( $s['spacing']['blockGap'] ) );
$ok( 'border supports declared', ! empty( $s['__experimentalBorder']['radius'] ) );
$GLOBALS['wp_query'] = new WP_Query();
$markup = '<!-- wp:nonprofit-manager/events {"limit":0,"past":true,"category":"meetings","layout":"mosaic","columns":3,"backgroundColor":"contrast","style":{"spacing":{"padding":{"top":"20px"},"blockGap":"2rem"},"border":{"radius":"12px"}}} /-->';
$html   = do_blocks( $markup );
$ok( 'wrapper class from the block', false !== strpos( $html, 'wp-block-nonprofit-manager-events' ) );
$ok( 'loop classes on the same element', 1 === preg_match( '#<div[^>]*class="[^"]*npmp-events--mosaic[^"]*wp-block-nonprofit-manager-events|<div[^>]*class="[^"]*wp-block-nonprofit-manager-events[^"]*npmp-events--mosaic#', $html ) );
$ok( 'background color class', false !== strpos( $html, 'has-contrast-background-color' ) && false !== strpos( $html, 'has-background' ) );
$ok( 'padding emitted', false !== strpos( $html, 'padding-top:20px' ) );
$ok( 'radius emitted', false !== strpos( $html, 'border-radius:12px' ) );
$ok( 'block gap emitted', 1 === preg_match( '#style="[^"]*gap:2rem#', $html ) );
$ok( 'limit 0 shows every event', 4 === substr_count( $html, '<article' ) );
$ok( 'archive default order inside the block', 1 === preg_match( '#Meeting D.*Meeting C.*Meeting B.*Meeting A#s', $html ) );

echo "Same-day events show the date once\n";
update_option( 'date_format', 'F j, Y' );
update_option( 'time_format', 'g:i a' );
$same = npmp_format_event_datetime( array( 'start' => '2026-10-28 19:00:00', 'end' => '2026-10-28 21:00:00' ) );
$ok( 'same day repeats only the time', 'October 28, 2026 7:00 pm &ndash; 9:00 pm' === $same );
$span = npmp_format_event_datetime( array( 'start' => '2026-10-28 19:00:00', 'end' => '2026-10-29 09:00:00' ) );
$ok( 'multi-day keeps both dates', 'October 28, 2026 7:00 pm &ndash; October 29, 2026 9:00 am' === $span );
$none = npmp_format_event_datetime( array( 'start' => '2026-10-28 19:00:00', 'end' => '' ) );
$ok( 'no end time prints the start only', 'October 28, 2026 7:00 pm' === $none );

echo "Settings screen rows\n";
ob_start();
npmp_events_loop_render_settings_rows();
$rows = ob_get_clean();
foreach ( array( 'npmp_events_loop[layout]', 'npmp_events_loop[columns]', 'npmp_events_loop[order]', 'npmp_events_loop[image]', 'npmp_events_loop[ratio]', 'npmp_events_loop[excerpt]', 'npmp_events_loop[excerpt_length]', 'npmp_events_loop[date]', 'npmp_events_loop[location]', 'npmp_events_loop[readmore]' ) as $name ) {
	$ok( 'field ' . $name, false !== strpos( $rows, 'name="' . $name . '"' ) );
}

echo "The theme's /events/ archive keeps an event's paragraphs\n";
// Pete at U3A, 2026-11-13: a classic theme's archive prints the_excerpt(),
// and core's excerpt ran every paragraph together.
$walk = $make( 'Rig Walk', '2099-02-10 10:00:00', "<!-- wp:paragraph -->\n<p>We meet at the hall at 10am.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Bring stout shoes &amp; a packed lunch.</p>\n<!-- /wp:paragraph -->" );
$saved_query      = $GLOBALS['wp_query'];
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'npmp_event', 'p' => 0, 'posts_per_page' => 20 ) );
$ok( 'the query reads as the event archive', is_post_type_archive( 'npmp_event' ) );
$archive_html = '';
while ( have_posts() ) {
	the_post();
	if ( get_the_ID() === $walk ) {
		ob_start();
		the_excerpt();
		$archive_html = ob_get_clean();
	}
}
wp_reset_postdata();
$ok( 'the_excerpt() prints two paragraphs on the archive', false !== strpos( $archive_html, "<p>We meet at the hall at 10am.</p>\n<p>Bring stout shoes &amp; a packed lunch.</p>" ) );
$ok( 'the archive excerpt still skips the headings', false === strpos( (string) get_the_excerpt( $a ), 'Wednesday, September' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $walk, 'post_type' => 'npmp_event' ) );
$ok( 'outside the archive, core\'s excerpt stays as it was', false === strpos( get_the_excerpt( $walk ), "\n" ) );
$GLOBALS['wp_query'] = $saved_query;

foreach ( array( $a, $b, $c, $d, $walk ) as $id ) {
	wp_delete_post( $id, true );
}
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
