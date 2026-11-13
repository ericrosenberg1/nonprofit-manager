<?php
/**
 * The event editor's Email Template box (includes/email-newsletter/templates.php)
 * and the template an event's email ends up wrapped in
 * (npmp_scheduled_emails_pick_template in includes/npmp-scheduled-emails.php).
 *
 * Peter Lord asked for the newsletter editor's template picker on the event
 * editor too (ROS-4473, 2026-11-13). Both screens print one shared picker, so
 * these checks pin the shared markup and the precedence the send path reads.
 *
 * Run: php tests/test-event-email-template.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_meta']       = array();
$GLOBALS['t_boxes']      = array();
$GLOBALS['t_actions']    = array();
$GLOBALS['t_templates']  = array();
$GLOBALS['t_options']    = array();

function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $hook ][] = $cb;
}
function add_filter() {}
function apply_filters( $hook, $value ) {
	return $value;
}
function do_action() {}
function add_shortcode() {}
function remove_shortcode() {}
function shortcode_exists() {
	return false;
}
function register_post_type() {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['t_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['t_meta'][ $id ][ $key ] = $value;
}
function add_meta_box( $id, $title, $cb, $screen, $context = 'advanced', $priority = 'default' ) {
	$GLOBALS['t_boxes'][ $screen ][ $id ] = array(
		'title'    => $title,
		'callback' => $cb,
		'context'  => $context,
		'priority' => $priority,
	);
}
function wp_nonce_field() {}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function admin_url( $path = '' ) {
	return 'https://example.org/wp-admin/' . $path;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}
function selected( $a, $b, $echo = true ) {
	$out = (string) $a === (string) $b ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}
/**
 * Templates come from $GLOBALS['t_templates']. The default one is whichever
 * carries _npmp_is_default_template, as npmp_get_default_template() asks.
 */
function get_posts( $args ) {
	$posts = $GLOBALS['t_templates'];
	if ( isset( $args['meta_query'][0]['key'] ) && '_npmp_is_default_template' === $args['meta_query'][0]['key'] ) {
		$posts = array_values(
			array_filter(
				$posts,
				function ( $p ) {
					return '1' === ( $GLOBALS['t_meta'][ $p->ID ]['_npmp_is_default_template'] ?? '' );
				}
			)
		);
	}
	if ( isset( $args['posts_per_page'] ) && $args['posts_per_page'] > 0 ) {
		$posts = array_slice( $posts, 0, (int) $args['posts_per_page'] );
	}
	return $posts;
}

require_once dirname( __DIR__ ) . '/includes/email-newsletter/templates.php';

/**
 * A stand-in template post. Only ID and post_title are read.
 */
function t_template( $id, $title, $is_default = false ) {
	$p             = new stdClass();
	$p->ID         = $id;
	$p->post_title = $title;
	if ( $is_default ) {
		$GLOBALS['t_meta'][ $id ]['_npmp_is_default_template'] = '1';
	}
	return $p;
}

$GLOBALS['t_templates'] = array(
	t_template( 31, 'House style', true ),
	t_template( 32, 'Plain text' ),
);

echo "The box is registered where the newsletter's boxes are\n";
// Both pickers register from inside an add_meta_boxes callback, so fire the
// callbacks the stub above collected.
foreach ( array( 'add_meta_boxes', 'add_meta_boxes_npmp_event' ) as $hook ) {
	foreach ( $GLOBALS['t_actions'][ $hook ] ?? array() as $cb ) {
		$cb();
	}
}
$event_boxes = $GLOBALS['t_boxes']['npmp_event'] ?? array();
check( 'event editor gets an Email Template box', true, isset( $event_boxes['npmp_event_email_template'] ) );
check( 'it sits in the sidebar', 'side', $event_boxes['npmp_event_email_template']['context'] ?? '' );
$nl_boxes = $GLOBALS['t_boxes']['npmp_newsletter'] ?? array();
check( 'same title as the newsletter box', $nl_boxes['npmp_newsletter_template']['title'] ?? 'missing', $event_boxes['npmp_event_email_template']['title'] ?? '' );
check( 'same sidebar slot as the newsletter box', $nl_boxes['npmp_newsletter_template']['context'] ?? 'missing', $event_boxes['npmp_event_email_template']['context'] ?? '' );

echo "\nThe picker offers the same choices on both screens\n";
$event              = new stdClass();
$event->ID          = 7;
$newsletter         = new stdClass();
$newsletter->ID     = 8;

ob_start();
npmp_event_email_template_meta_box( $event );
$event_html = ob_get_clean();
ob_start();
npmp_newsletter_template_meta_box( $newsletter );
$newsletter_html = ob_get_clean();

check( 'event select carries the id its label points at', true, false !== strpos( $event_html, '<label for="npmp_event_email_template">' ) && false !== strpos( $event_html, 'id="npmp_event_email_template"' ) );
check( 'event select posts its own field name', true, false !== strpos( $event_html, 'name="npmp_event_email_template"' ) );
check( 'event box offers the default', true, false !== strpos( $event_html, '>Use Default Template<' ) );
check( 'event box offers no template', true, false !== strpos( $event_html, '>No Template (Plain Content)<' ) );
check( 'event box lists every pickable template', 2, substr_count( $event_html, '<option value="3' ) );
check( 'event box names the current default', true, false !== strpos( $event_html, 'Current default template:' ) );
check( 'event box says what the template wraps', true, false !== strpos( $event_html, 'Wraps the email this event sends.' ) );
check( 'newsletter box is unchanged, with no event note', false, strpos( $newsletter_html, 'Wraps the email this event sends.' ) );
// The point of the shared picker: strip each screen's own field name and the
// rest of the markup has to match, or the two editors have drifted again.
$normalise = function ( $html, $field ) {
	// Drop the event box's extra note, swap in a neutral field name, and
	// collapse whitespace, so only real markup differences can fail this.
	$html = preg_replace( '#<p class="description">Wraps the email this event sends\.</p>#', '', $html );
	// Quoted, so this swaps the for/name/id attributes and leaves the Manage
	// Templates page slug (page=npmp_newsletter_templates) alone.
	return trim( preg_replace( '/\s+/', ' ', str_replace( '"' . $field . '"', '"FIELD"', $html ) ) );
};
check(
	'both screens print the same picker',
	$normalise( $newsletter_html, 'npmp_newsletter_template' ),
	$normalise( $event_html, 'npmp_event_email_template' )
);

echo "\nA saved choice reopens selected\n";
$GLOBALS['t_meta'][7]['_npmp_event_email_template'] = 32;
ob_start();
npmp_event_email_template_meta_box( $event );
$event_html = ob_get_clean();
check( 'saved template is selected', 1, preg_match( '#<option value="32"\s+selected=.selected.#', $event_html ) );
// A saved "none" that reopened on "Use Default Template" looked unsaved, which
// is why both of those options need selected() (same trap as the newsletter).
$GLOBALS['t_meta'][7]['_npmp_event_email_template'] = 'none';
ob_start();
npmp_event_email_template_meta_box( $event );
$event_html = ob_get_clean();
check( 'saved "none" reopens on No Template', 1, preg_match( '#<option value="none"\s+selected=.selected.#', $event_html ) );
check( 'and not on Use Default Template', 0, preg_match( '#<option value=""\s+selected=.selected.#', $event_html ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
