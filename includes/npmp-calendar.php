<?php
/**
 * Event‑calendar module for Nonprofit Manager
 */
defined( 'ABSPATH' ) || exit;

/* ----------------------------------------------------------------------
 * 1. Post‑type registration
 * ------------------------------------------------------------------- */
add_action(
	'init',
	static function () {
		if ( post_type_exists( 'npmp_event' ) ) {
			return;
		}
		register_post_type(
			'npmp_event',
			array(
				'labels' => array(
					'name'          => __( 'Events', 'nonprofit-manager' ),
					'singular_name' => __( 'Event',  'nonprofit-manager' ),
				),
				'public'           => false,
				'show_ui'          => false, // we provide our own screens
				'supports'         => array( 'title', 'editor' ),
				'capability_type'  => 'post',
				'map_meta_cap'     => true,
			)
		);
	}
);

/* ----------------------------------------------------------------------
 * 2. Admin‑menu (Calendar group)
 * ------------------------------------------------------------------- */
add_action(
	'admin_menu',
	static function () {

		add_menu_page(
			'Calendar',
			'Calendar',
			'manage_options',
			'npmp_calendar',
			'npmp_render_calendar_dashboard',
			'dashicons-calendar-alt',
			3.25
		);

		add_submenu_page(
			'npmp_calendar',
			__( 'Add Event', 'nonprofit-manager' ),
			__( 'Add Event', 'nonprofit-manager' ),
			'manage_options',
			'npmp_calendar_add',
			'npmp_render_event_edit_page'
		);
	}
);

/* ----------------------------------------------------------------------
 * 3. Dashboard list
 * ------------------------------------------------------------------- */
if ( ! function_exists( 'npmp_render_calendar_dashboard' ) ) :
function npmp_render_calendar_dashboard() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die();
	}

	$today = gmdate( 'Y-m-d' );

	$query_args = array(
		'post_type'      => 'npmp_event',
		'posts_per_page' => -1,
		'meta_key'       => '_npmp_event_date',
		'orderby'        => 'meta_value',
		'order'          => 'ASC',
	);

	$all_events = get_posts( $query_args );
	$upcoming   = array();
	$past       = array();

	foreach ( $all_events as $ev ) {
		$date = get_post_meta( $ev->ID, '_npmp_event_date', true );
		( $date >= $today ) ? $upcoming[] = $ev : $past[] = $ev;
	}

	echo '<div class="wrap"><h1>' . esc_html__( 'Event Calendar', 'nonprofit-manager' ) . '</h1>';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=npmp_calendar_add' ) ) . '" class="page-title-action" style="margin-bottom:15px;">' . esc_html__( 'Add Event', 'nonprofit-manager' ) . '</a>';

	echo '<h2>' . esc_html__( 'Upcoming Events', 'nonprofit-manager' ) . '</h2>';
	npmp_events_table( $upcoming );

	echo '<h2 style="margin-top:35px;">' . esc_html__( 'Past Events', 'nonprofit-manager' ) . '</h2>';
	npmp_events_table( $past );

	$feed_url = esc_url( add_query_arg( 'npmp-ical', '1', home_url( '/' ) ) );
	echo '<p style="margin-top:25px;"><strong>' . esc_html__( 'iCal feed:', 'nonprofit-manager' ) . '</strong> <code>' . $feed_url . '</code></p>';
	echo '</div>';
}
endif;

/* helper table renderer */
if ( ! function_exists( 'npmp_events_table' ) ) :
function npmp_events_table( $events ) {

	echo '<table class="widefat striped" style="max-width:800px;"><thead><tr>';
	echo '<th>' . esc_html__( 'Date', 'nonprofit-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Event', 'nonprofit-manager' ) . '</th></tr></thead><tbody>';

	if ( $events ) {
		foreach ( $events as $ev ) {
			$date = esc_html( date_i18n( get_option( 'date_format' ), strtotime( get_post_meta( $ev->ID, '_npmp_event_date', true ) ) ) );
			echo '<tr><td>' . $date . '</td><td>' . esc_html( $ev->post_title ) . '</td></tr>';
		}
	} else {
		echo '<tr><td colspan="2">' . esc_html__( 'None', 'nonprofit-manager' ) . '</td></tr>';
	}

	echo '</tbody></table>';
}
endif;

/* ----------------------------------------------------------------------
 * 4. Add / Edit event
 * ------------------------------------------------------------------- */
if ( ! function_exists( 'npmp_render_event_edit_page' ) ) :
function npmp_render_event_edit_page() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die();
	}

	$event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
	$event    = $event_id ? get_post( $event_id ) : null;

	/* ─── Save ─────────────────────────────────────────────────── */
	if (
		isset( $_POST['npmp_event_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_event_nonce'] ) ), 'npmp_save_event' )
	) {
		$base = array(
			'post_type'   => 'npmp_event',
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( wp_unslash( $_POST['npmp_event_title'] ?? '' ) ),
			'post_content'=> wp_kses_post( wp_unslash( $_POST['npmp_event_desc'] ?? '' ) ),
		);

		$event_id = $event_id
			? wp_update_post( array_merge( $base, array( 'ID' => $event_id ) ) )
			: wp_insert_post( $base );

		$date = sanitize_text_field( wp_unslash( $_POST['npmp_event_date'] ?? '' ) );
		update_post_meta( $event_id, '_npmp_event_date', $date );

		wp_safe_redirect( admin_url( 'admin.php?page=npmp_calendar&saved=1' ) );
		exit;
	}

	/* ─── Defaults for new event ───────────────────────────────── */
	$title   = $event ? $event->post_title            : '';
	$content = $event ? $event->post_content          : '';
	$date    = $event ? get_post_meta( $event->ID, '_npmp_event_date', true ) : '';

	echo '<div class="wrap"><h1>' . esc_html( $event ? __( 'Edit Event', 'nonprofit-manager' ) : __( 'Add Event', 'nonprofit-manager' ) ) . '</h1>';

	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="updated notice is-dismissible"><p>' . esc_html__( 'Event saved.', 'nonprofit-manager' ) . '</p></div>';
	}

	echo '<form method="post">';
	wp_nonce_field( 'npmp_save_event', 'npmp_event_nonce' );

	echo '<table class="form-table">';
	echo '<tr><th><label for="npmp_event_title">' . esc_html__( 'Event Name', 'nonprofit-manager' ) . '</label></th>';
	echo '<td><input class="regular-text" type="text" id="npmp_event_title" name="npmp_event_title" value="' . esc_attr( $title ) . '" required></td></tr>';

	echo '<tr><th><label for="npmp_event_date">' . esc_html__( 'Event Date', 'nonprofit-manager' ) . '</label></th>';
	echo '<td><input type="date" id="npmp_event_date" name="npmp_event_date" value="' . esc_attr( $date ) . '" required></td></tr>';

	echo '<tr><th><label for="npmp_event_desc">' . esc_html__( 'Description', 'nonprofit-manager' ) . '</label></th><td>';
	wp_editor( $content, 'npmp_event_desc', array( 'textarea_rows' => 8 ) );
	echo '</td></tr>';
	echo '</table>';

	submit_button( $event ? __( 'Update Event', 'nonprofit-manager' ) : __( 'Add Event', 'nonprofit-manager' ) );
	echo '</form></div>';
}
endif;

/* ----------------------------------------------------------------------
 * 5. Shortcode [npmp_calendar]
 * ------------------------------------------------------------------- */
add_shortcode(
	'npmp_calendar',
	static function () {

		$events = get_posts(
			array(
				'post_type'      => 'npmp_event',
				'posts_per_page' => -1,
				'meta_key'       => '_npmp_event_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);

		ob_start();
		echo '<div class="npmp-calendar">';
		foreach ( $events as $ev ) {
			$date = esc_html( date_i18n( get_option( 'date_format' ), strtotime( get_post_meta( $ev->ID, '_npmp_event_date', true ) ) ) );
			echo '<div class="npmp-calendar-event"><strong>' . $date . '</strong> — ' . esc_html( $ev->post_title ) . '</div>';
		}
		$feed = esc_url( add_query_arg( 'npmp-ical', '1', home_url( '/' ) ) );
		echo '<p><a href="' . $feed . '" onclick="navigator.clipboard.writeText(this.href);return false;">' . esc_html__( 'Subscribe to calendar', 'nonprofit-manager' ) . '</a></p>';
		echo '</div>';
		return ob_get_clean();
	}
);

/* ----------------------------------------------------------------------
 * 6. Public iCal feed (?npmp-ical=1)
 * ------------------------------------------------------------------- */
add_action(
	'init',
	static function () {

		if ( ! isset( $_GET['npmp-ical'] ) ) {
			return;
		}

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=events.ics' );

		$output = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nonprofit Manager//EN\r\n";

		$events = get_posts(
			array(
				'post_type'      => 'npmp_event',
				'posts_per_page' => -1,
			)
		);

		foreach ( $events as $ev ) {
			$stamp = strtotime( get_post_meta( $ev->ID, '_npmp_event_date', true ) );
			$date  = gmdate( 'Ymd', $stamp );

			$output .= "BEGIN:VEVENT\r\n";
			$output .= 'UID:' . $ev->ID . '@' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . "\r\n";
			$output .= "DTSTAMP:{$date}T000000Z\r\n";
			$output .= "DTSTART;VALUE=DATE:{$date}\r\n";
			$output .= 'SUMMARY:' . str_replace( array( "\r", "\n" ), '', sanitize_text_field( $ev->post_title ) ) . "\r\n";
			$output .= "END:VEVENT\r\n";
		}

		$output .= "END:VCALENDAR\r\n";
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
);
