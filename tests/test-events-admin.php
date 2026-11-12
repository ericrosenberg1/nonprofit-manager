<?php
/**
 * Events Overview and Calendar Settings markup.
 *
 * - Events Overview uses core's header: h1, then Add Event and All Events
 *   as page-title actions. The empty state links to Add Event.
 * - Calendar Settings has one "Calendar Settings" heading, not an h1 and an
 *   h2 with the same words, and its notices can be dismissed.
 * - "Event lists and archives" is a heading between two form tables. It used
 *   to be an h2 inside a th, which isn't a heading to a screen reader.
 *
 * Run: php tests/test-events-admin.php
 */

require_once __DIR__ . '/bootstrap.php';

$cal  = file_get_contents( __DIR__ . '/../includes/npmp-calendar.php' );
$loop = file_get_contents( __DIR__ . '/../includes/npmp-events-loop.php' );

echo "Events Overview\n";
check( 'header through the shared helper', true, false !== strpos( $cal, "npmp_admin_page_title(\n\t\t__( 'Events Overview', 'nonprofit-manager' )," ) );
check( 'Add Event is a page-title action', true, false !== strpos( $cal, "__( 'Add Event', 'nonprofit-manager' )  => admin_url( 'post-new.php?post_type=npmp_event' )," ) );
check( 'no "Add New Event" button', false, strpos( $cal, "'Add New Event'" ) );
check( 'empty state links to Add Event', true, false !== strpos( $cal, "'No upcoming events yet.', 'nonprofit-manager' ) . ' <a href=\"' . esc_url( admin_url( 'post-new.php?post_type=npmp_event' ) )" ) );

echo "Calendar Settings\n";
check( 'h1 through the shared helper', true, false !== strpos( $cal, "npmp_admin_page_title( __( 'Calendar Settings', 'nonprofit-manager' ) );" ) );
check( 'no h2 repeating "Calendar Settings"', false, strpos( $cal, "<h2 class=\"title\">' . esc_html__( 'Calendar Settings'" ) );
check( 'notices dismissible', true, false !== strpos( $cal, "esc_attr( \$class . ' is-dismissible' )" ) );
check( 'new page option in words', true, false !== strpos( $cal, "'Create a new page'" ) && false === strpos( $cal, "'+ New Page'" ) );
check( 'embed table headers scoped', true, false !== strpos( $cal, "<th scope=\"col\">' . esc_html__( 'Shortcode'" ) );
check( 'no inline card widths', false, strpos( $cal, 'style="max-width:' ) );

echo "Event lists heading\n";
check( 'heading is not inside a th', false, strpos( $loop, 'colspan="2"><h2' ) );
check( 'heading between two form tables', true, false !== strpos( $loop, "'</tbody></table><h2 class=\"npmp-settings-subhead\">'" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
