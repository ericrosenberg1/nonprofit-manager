<?php
/**
 * Event management module.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register event post type and taxonomy on init.
 *
 * @return void
 */
function npmp_register_events_module() {
	npmp_register_event_post_type();
	npmp_register_event_taxonomy();
}
add_action( 'init', 'npmp_register_events_module' );

/**
 * Register the Events admin menu alongside other plugin modules.
 *
 * @return void
 */
function npmp_register_events_admin_menu() {
	add_menu_page(
		__( 'Events', 'nonprofit-manager' ),
		__( 'Events', 'nonprofit-manager' ),
		'edit_posts',
		'npmp-events',
		'npmp_render_events_dashboard',
		'dashicons-calendar-alt',
		3.15
	);

	add_submenu_page(
		'npmp-events',
		__( 'Overview', 'nonprofit-manager' ),
		__( 'Overview', 'nonprofit-manager' ),
		'edit_posts',
		'npmp-events',
		'npmp_render_events_dashboard'
	);

	add_submenu_page(
		'npmp-events',
		__( 'All Events', 'nonprofit-manager' ),
		__( 'All Events', 'nonprofit-manager' ),
		'edit_posts',
		'edit.php?post_type=npmp_event'
	);

	add_submenu_page(
		'npmp-events',
		__( 'Add New', 'nonprofit-manager' ),
		__( 'Add New', 'nonprofit-manager' ),
		'edit_posts',
		'post-new.php?post_type=npmp_event'
	);

	add_submenu_page(
		'npmp-events',
		__( 'Calendar Settings', 'nonprofit-manager' ),
		__( 'Settings', 'nonprofit-manager' ),
		'manage_options',
		'npmp_event_settings',
		'npmp_render_event_settings_page'
	);
}
add_action( 'admin_menu', 'npmp_register_events_admin_menu', 9 );

/**
 * Register the custom post type for events.
 *
 * @return void
 */
function npmp_register_event_post_type() {
	if ( post_type_exists( 'npmp_event' ) ) {
		return;
	}

	$labels = array(
		'name'                  => __( 'Events', 'nonprofit-manager' ),
		'singular_name'         => __( 'Event', 'nonprofit-manager' ),
		'add_new'               => __( 'Add New', 'nonprofit-manager' ),
		'add_new_item'          => __( 'Add New Event', 'nonprofit-manager' ),
		'edit_item'             => __( 'Edit Event', 'nonprofit-manager' ),
		'new_item'              => __( 'New Event', 'nonprofit-manager' ),
		'view_item'             => __( 'View Event', 'nonprofit-manager' ),
		'search_items'          => __( 'Search Events', 'nonprofit-manager' ),
		'not_found'             => __( 'No events found.', 'nonprofit-manager' ),
		'not_found_in_trash'    => __( 'No events found in Trash.', 'nonprofit-manager' ),
		'all_items'             => __( 'All Events', 'nonprofit-manager' ),
		'archives'              => __( 'Event Archives', 'nonprofit-manager' ),
		'attributes'            => __( 'Event Attributes', 'nonprofit-manager' ),
		'insert_into_item'      => __( 'Insert into event', 'nonprofit-manager' ),
		'uploaded_to_this_item' => __( 'Uploaded to this event', 'nonprofit-manager' ),
		'item_updated'          => __( 'Event updated.', 'nonprofit-manager' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'show_in_menu'       => false,
		'show_in_rest'       => true,
		'has_archive'        => true,
		'rewrite'            => array( 'slug' => 'events' ),
		'menu_icon'          => 'dashicons-calendar-alt',
		'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
		'capability_type'    => 'post',
		'map_meta_cap'       => true,
	);

	register_post_type( 'npmp_event', $args );
}

/**
 * Register event categories taxonomy.
 *
 * @return void
 */
function npmp_register_event_taxonomy() {
	if ( taxonomy_exists( 'npmp_event_category' ) ) {
		return;
	}

	$labels = array(
		'name'              => __( 'Event Categories', 'nonprofit-manager' ),
		'singular_name'     => __( 'Event Category', 'nonprofit-manager' ),
		'search_items'      => __( 'Search Event Categories', 'nonprofit-manager' ),
		'all_items'         => __( 'All Event Categories', 'nonprofit-manager' ),
		'parent_item'       => __( 'Parent Event Category', 'nonprofit-manager' ),
		'parent_item_colon' => __( 'Parent Event Category:', 'nonprofit-manager' ),
		'edit_item'         => __( 'Edit Event Category', 'nonprofit-manager' ),
		'update_item'       => __( 'Update Event Category', 'nonprofit-manager' ),
		'add_new_item'      => __( 'Add New Event Category', 'nonprofit-manager' ),
		'new_item_name'     => __( 'New Event Category Name', 'nonprofit-manager' ),
		'menu_name'         => __( 'Event Categories', 'nonprofit-manager' ),
	);

	$args = array(
		'labels'            => $labels,
		'hierarchical'      => true,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => array( 'slug' => 'event-category' ),
	);

	register_taxonomy( 'npmp_event_category', array( 'npmp_event' ), $args );
}

/**
 * Events module dashboard page.
 *
 * @return void
 */
function npmp_render_events_dashboard() {

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$is_pro       = npmp_is_pro();
	$current_time = current_time( 'mysql' );

	// Query upcoming events
	$upcoming_query = new WP_Query(
		array(
			'post_type'      => 'npmp_event',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_npmp_event_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Event scheduling relies on an indexed meta key.
			'order'          => 'ASC',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Event scheduling relies on indexed meta keys.
			'meta_query'     => array(
				array(
					'key'     => '_npmp_event_start',
					'value'   => $current_time,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			),
		)
	);

	// Query past events
	$past_query = new WP_Query(
		array(
			'post_type'      => 'npmp_event',
			'posts_per_page' => 10,
			'orderby'        => 'meta_value',
			'meta_key'       => '_npmp_event_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Event scheduling relies on an indexed meta key.
			'order'          => 'DESC',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Event scheduling relies on indexed meta keys.
			'meta_query'     => array(
				array(
					'key'     => '_npmp_event_start',
					'value'   => $current_time,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
		)
	);

	$calendar_page_id = absint( get_option( 'npmp_calendar_page_id', 0 ) );
	$calendar_link    = $calendar_page_id ? get_permalink( $calendar_page_id ) : '';

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Events Overview', 'nonprofit-manager' ) . '</h1>';
	echo '<p>' . esc_html__( 'Create in-person or virtual events, promote them on the calendar, and keep your community up to date.', 'nonprofit-manager' ) . '</p>';

	echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=npmp_event' ) ) . '">' . esc_html__( 'Add New Event', 'nonprofit-manager' ) . '</a> ';
	echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=npmp_event' ) ) . '">' . esc_html__( 'Manage All Events', 'nonprofit-manager' ) . '</a></p>';

	if ( $calendar_link ) {
		echo '<p>' . sprintf(
			wp_kses_post(
				/* translators: %1$s: Public calendar page URL. */
				__( 'Your public calendar page: <a href="%1$s" target="_blank" rel="noopener">%1$s</a>', 'nonprofit-manager' )
			),
			esc_url( $calendar_link )
		) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'Tip: Assign a default calendar page so visitors can always find your schedule.', 'nonprofit-manager' ) . '</p>';
	}

	// Upcoming Events Card
	echo '<div class="card" style="max-width:900px; margin-bottom: 20px;">';
	echo '<h2 class="title">' . esc_html__( 'Upcoming Events', 'nonprofit-manager' ) . '</h2>';

	if ( $upcoming_query->have_posts() ) {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Event', 'nonprofit-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'nonprofit-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'nonprofit-manager' ) . '</th>';
		if ( $is_pro ) {
			echo '<th>' . esc_html__( 'Registrations', 'nonprofit-manager' ) . '</th>';
		} else {
			echo '<th style="color: #999;">' . esc_html__( 'Registrations', 'nonprofit-manager' ) . ' <span style="font-weight:normal; font-size:11px;">(Pro)</span></th>';
		}
		echo '</tr></thead><tbody>';

		while ( $upcoming_query->have_posts() ) {
			$upcoming_query->the_post();
			$details = npmp_get_event_details( get_the_ID() );
			$location = get_post_meta( get_the_ID(), '_npmp_event_location', true );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link() ) . '">' . esc_html( get_the_title() ) . '</a></td>';
			echo '<td>' . wp_kses_post( npmp_format_event_datetime( $details ) ) . '</td>';
			echo '<td>' . esc_html( $location ? $location : '—' ) . '</td>';

			if ( $is_pro && function_exists( 'npmp_get_event_registration_count' ) ) {
				$reg_count = npmp_get_event_registration_count( get_the_ID() );
				$reg_link  = admin_url( 'admin.php?page=npmp-event-registrations&event_id=' . get_the_ID() );
				if ( $reg_count > 0 ) {
					echo '<td><a href="' . esc_url( $reg_link ) . '">' . esc_html( $reg_count ) . '</a></td>';
				} else {
					echo '<td>0</td>';
				}
			} else {
				echo '<td style="color: #999;">—</td>';
			}

			echo '</tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>' . esc_html__( 'No upcoming events scheduled yet. Start by creating one above.', 'nonprofit-manager' ) . '</p>';
	}
	wp_reset_postdata();
	echo '</div>';

	// Past Events Card
	echo '<div class="card" style="max-width:900px; margin-bottom: 20px;">';
	echo '<h2 class="title">' . esc_html__( 'Recent Past Events', 'nonprofit-manager' ) . '</h2>';

	if ( $past_query->have_posts() ) {
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Event', 'nonprofit-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'nonprofit-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'nonprofit-manager' ) . '</th>';
		if ( $is_pro ) {
			echo '<th>' . esc_html__( 'Registrations', 'nonprofit-manager' ) . '</th>';
		} else {
			echo '<th style="color: #999;">' . esc_html__( 'Registrations', 'nonprofit-manager' ) . ' <span style="font-weight:normal; font-size:11px;">(Pro)</span></th>';
		}
		echo '</tr></thead><tbody>';

		while ( $past_query->have_posts() ) {
			$past_query->the_post();
			$details = npmp_get_event_details( get_the_ID() );
			$location = get_post_meta( get_the_ID(), '_npmp_event_location', true );

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link() ) . '">' . esc_html( get_the_title() ) . '</a></td>';
			echo '<td>' . wp_kses_post( npmp_format_event_datetime( $details ) ) . '</td>';
			echo '<td>' . esc_html( $location ? $location : '—' ) . '</td>';

			if ( $is_pro && function_exists( 'npmp_get_event_registration_count' ) ) {
				$reg_count = npmp_get_event_registration_count( get_the_ID() );
				$reg_link  = admin_url( 'admin.php?page=npmp-event-registrations&event_id=' . get_the_ID() );
				if ( $reg_count > 0 ) {
					echo '<td><a href="' . esc_url( $reg_link ) . '">' . esc_html( $reg_count ) . '</a></td>';
				} else {
					echo '<td>0</td>';
				}
			} else {
				echo '<td style="color: #999;">—</td>';
			}

			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Showing up to 10 recent past events.', 'nonprofit-manager' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'No past events found.', 'nonprofit-manager' ) . '</p>';
	}
	wp_reset_postdata();
	echo '</div>';

	if ( ! $is_pro ) {
		echo '<div class="card" style="max-width:900px; background-color: #f0f6fc; border-left: 4px solid #0073aa;">';
		echo '<h2 class="title">' . esc_html__( 'Event Registration', 'nonprofit-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upgrade to the Pro version to enable event registrations. Visitors can sign up for events, and you can track attendees right from your dashboard.', 'nonprofit-manager' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Pro Features:', 'nonprofit-manager' ) . '</strong></p>';
		echo '<ul style="list-style: disc; margin-left: 20px;">';
		echo '<li>' . esc_html__( 'Enable registration for any event', 'nonprofit-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Track attendees and registration counts', 'nonprofit-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Automatic subscriber list integration', 'nonprofit-manager' ) . '</li>';
		echo '<li>' . esc_html__( 'Export attendee lists', 'nonprofit-manager' ) . '</li>';
		echo '</ul>';
		echo '</div>';
	}

	echo '</div>';
}

/**
 * Register event details meta box.
 *
 * @return void
 */
function npmp_register_event_meta_boxes() {
	add_meta_box(
		'npmp-event-details',
		__( 'Event Details', 'nonprofit-manager' ),
		'npmp_render_event_meta_box',
		'npmp_event',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes_npmp_event', 'npmp_register_event_meta_boxes' );

/**
 * Render the event details meta box.
 *
 * @param WP_Post $post Current post object.
 * @return void
 */
function npmp_render_event_meta_box( $post ) {
	wp_nonce_field( 'npmp_save_event_details', 'npmp_event_details_nonce' );

	$start        = get_post_meta( $post->ID, '_npmp_event_start', true );
	$end          = get_post_meta( $post->ID, '_npmp_event_end', true );
	$location     = get_post_meta( $post->ID, '_npmp_event_location', true );
	$external_url = get_post_meta( $post->ID, '_npmp_event_url', true );

	// Back-compat with legacy meta.
	if ( empty( $start ) ) {
		$legacy_date = get_post_meta( $post->ID, '_npmp_event_date', true );
		if ( $legacy_date ) {
			$start = $legacy_date . ' 00:00:00';
		}
	}

	$start_date = $start ? substr( $start, 0, 10 ) : '';
	$start_time = $start && strlen( $start ) > 10 ? substr( $start, 11, 5 ) : '';
	$end_date   = $end ? substr( $end, 0, 10 ) : '';
	$end_time   = $end && strlen( $end ) > 10 ? substr( $end, 11, 5 ) : '';

	echo '<p><label for="npmp_event_start_date"><strong>' . esc_html__( 'Start Date', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="date" id="npmp_event_start_date" name="npmp_event_start_date" value="' . esc_attr( $start_date ) . '" class="regular-text" required></p>';

	echo '<p><label for="npmp_event_start_time">' . esc_html__( 'Start Time', 'nonprofit-manager' ) . '</label><br>';
	echo '<input type="time" id="npmp_event_start_time" name="npmp_event_start_time" value="' . esc_attr( $start_time ) . '" class="regular-text"></p>';

	echo '<hr>';

	echo '<p><label for="npmp_event_end_date"><strong>' . esc_html__( 'End Date (optional)', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="date" id="npmp_event_end_date" name="npmp_event_end_date" value="' . esc_attr( $end_date ) . '" class="regular-text"></p>';

	echo '<p><label for="npmp_event_end_time">' . esc_html__( 'End Time (optional)', 'nonprofit-manager' ) . '</label><br>';
	echo '<input type="time" id="npmp_event_end_time" name="npmp_event_end_time" value="' . esc_attr( $end_time ) . '" class="regular-text"></p>';

	echo '<hr>';

	echo '<p><label for="npmp_event_location"><strong>' . esc_html__( 'Location', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="text" id="npmp_event_location" name="npmp_event_location" value="' . esc_attr( $location ) . '" class="widefat" placeholder="' . esc_attr__( 'Venue name or address', 'nonprofit-manager' ) . '"></p>';

	echo '<p><label for="npmp_event_url"><strong>' . esc_html__( 'Event Link', 'nonprofit-manager' ) . '</strong></label><br>';
	echo '<input type="url" id="npmp_event_url" name="npmp_event_url" value="' . esc_attr( $external_url ) . '" class="widefat" placeholder="' . esc_attr__( 'https://example.org', 'nonprofit-manager' ) . '"></p>';
}

/**
 * Persist event meta data.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function npmp_save_event_meta( $post_id ) {
	if ( ! isset( $_POST['npmp_event_details_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_event_details_nonce'] ) ), 'npmp_save_event_details' )
	) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$start_date = sanitize_text_field( wp_unslash( $_POST['npmp_event_start_date'] ?? '' ) );
	$start_time = sanitize_text_field( wp_unslash( $_POST['npmp_event_start_time'] ?? '' ) );
	$end_date   = sanitize_text_field( wp_unslash( $_POST['npmp_event_end_date'] ?? '' ) );
	$end_time   = sanitize_text_field( wp_unslash( $_POST['npmp_event_end_time'] ?? '' ) );
	$location   = sanitize_text_field( wp_unslash( $_POST['npmp_event_location'] ?? '' ) );
	$url        = esc_url_raw( wp_unslash( $_POST['npmp_event_url'] ?? '' ) );

	$start = '';
	if ( $start_date ) {
		$start_time = $start_time ?: '00:00';
		$start      = $start_date . ' ' . $start_time . ':00';
	}

	$end = '';
	if ( $end_date ) {
		$end_time = $end_time ?: '23:59';
		$end      = $end_date . ' ' . $end_time . ':00';
	}

	if ( $start ) {
		update_post_meta( $post_id, '_npmp_event_start', $start );
	} else {
		delete_post_meta( $post_id, '_npmp_event_start' );
	}

	if ( $end ) {
		update_post_meta( $post_id, '_npmp_event_end', $end );
	} else {
		delete_post_meta( $post_id, '_npmp_event_end' );
	}

	if ( $location ) {
		update_post_meta( $post_id, '_npmp_event_location', $location );
	} else {
		delete_post_meta( $post_id, '_npmp_event_location' );
	}

	if ( $url ) {
		update_post_meta( $post_id, '_npmp_event_url', $url );
	} else {
		delete_post_meta( $post_id, '_npmp_event_url' );
	}
}
add_action( 'save_post_npmp_event', 'npmp_save_event_meta' );

/**
 * Retrieve structured event details.
 *
 * @param int $post_id Event ID.
 * @return array<string,string>
 */
function npmp_get_event_details( $post_id ) {
	$details = array(
		'start'    => get_post_meta( $post_id, '_npmp_event_start', true ),
		'end'      => get_post_meta( $post_id, '_npmp_event_end', true ),
		'location' => get_post_meta( $post_id, '_npmp_event_location', true ),
		'url'      => get_post_meta( $post_id, '_npmp_event_url', true ),
	);

	if ( empty( $details['start'] ) ) {
		$legacy_date = get_post_meta( $post_id, '_npmp_event_date', true );
		if ( $legacy_date ) {
			$details['start'] = $legacy_date . ' 00:00:00';
		}
	}

	return $details;
}

/**
 * Render formatted event date/time for lists.
 *
 * @param array $details Event details.
 * @return string
 */
function npmp_format_event_datetime( $details ) {
	$start = ! empty( $details['start'] ) ? strtotime( $details['start'] ) : false;
	$end   = ! empty( $details['end'] ) ? strtotime( $details['end'] ) : false;

	if ( ! $start ) {
		return '';
	}

	$date_format = get_option( 'date_format' );
	$time_format = get_option( 'time_format' );

	$formatted = date_i18n( $date_format, $start );

	if ( $time_format ) {
		$formatted .= ' ' . date_i18n( $time_format, $start );
	}

	if ( $end ) {
		$same_day = gmdate( 'Y-m-d', $start ) === gmdate( 'Y-m-d', $end );
		$formatted .= $same_day ? ' &ndash; ' : ' &ndash; ';
		$formatted .= date_i18n( $date_format, $end );
		if ( $time_format ) {
			$formatted .= ' ' . date_i18n( $time_format, $end );
		}
	}

	return $formatted;
}

/**
 * Shortcode handler for the calendar grid.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function npmp_calendar_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'month'    => '',
			'year'     => '',
			'category' => '',
		),
		$atts,
		'npmp_calendar'
	);

	$timezone  = wp_timezone();
	$target    = new DateTimeImmutable( 'first day of this month', $timezone );
	$month_arg = '';

	$has_nonce = isset( $_GET['npmp_calendar_nonce'] ) && wp_verify_nonce(
		sanitize_text_field( wp_unslash( $_GET['npmp_calendar_nonce'] ) ),
		'npmp_calendar_nav'
	);

	if ( $has_nonce && isset( $_GET['npmp_month'] ) ) {
		$month_arg = sanitize_text_field( wp_unslash( $_GET['npmp_month'] ) );
	}

	if ( $month_arg && preg_match( '/^\d{4}-\d{2}$/', $month_arg ) ) {
		$maybe = DateTimeImmutable::createFromFormat( 'Y-m-d', $month_arg . '-01', $timezone );
		if ( $maybe instanceof DateTimeImmutable ) {
			$target = $maybe;
		}
	} elseif ( '' !== $atts['month'] && '' !== $atts['year'] ) {
		$month = absint( $atts['month'] );
		$year  = absint( $atts['year'] );
		if ( $month >= 1 && $month <= 12 && $year >= 1970 ) {
			$maybe = DateTimeImmutable::createFromFormat( 'Y-m-d', sprintf( '%04d-%02d-01', $year, $month ), $timezone );
			if ( $maybe instanceof DateTimeImmutable ) {
				$target = $maybe;
			}
		}
	}

	$month_start = $target->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
	$month_end   = $target->modify( 'last day of this month' )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );

	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Calendar filtering requires date range meta comparisons.
	$meta_query = array(
		'relation' => 'OR',
		array(
			'key'     => '_npmp_event_start',
			'value'   => array( $month_start, $month_end ),
			'compare' => 'BETWEEN',
			'type'    => 'DATETIME',
		),
		array(
			'relation' => 'AND',
			array(
				'key'     => '_npmp_event_start',
				'value'   => $month_start,
				'compare' => '<',
				'type'    => 'DATETIME',
			),
			array(
				'key'     => '_npmp_event_end',
				'value'   => $month_end,
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		),
	);

		$query_args = array(
			'post_type'      => 'npmp_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_npmp_event_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Calendar view ordering relies on event start timestamps.
			'order'          => 'ASC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Calendar view needs to filter by date ranges stored in meta.
		);

	if ( ! empty( $atts['category'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Event categories are taxonomy-based filters.
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'npmp_event_category',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
			),
		);
	}

	$events_query   = new WP_Query( $query_args );
	$events_by_day  = array();
	$month_key      = $target->format( 'Y-m' );
	$time_format    = get_option( 'time_format' );
	$today_key      = wp_date( 'Y-m-d', time(), $timezone );
	$start_of_week  = (int) get_option( 'start_of_week', 0 );
	$days_in_month  = (int) $target->format( 't' );
	$weekday_labels = array(
		__( 'Sunday', 'nonprofit-manager' ),
		__( 'Monday', 'nonprofit-manager' ),
		__( 'Tuesday', 'nonprofit-manager' ),
		__( 'Wednesday', 'nonprofit-manager' ),
		__( 'Thursday', 'nonprofit-manager' ),
		__( 'Friday', 'nonprofit-manager' ),
		__( 'Saturday', 'nonprofit-manager' ),
	);

	if ( $events_query->have_posts() ) {
		while ( $events_query->have_posts() ) {
			$events_query->the_post();
			$details  = npmp_get_event_details( get_the_ID() );
			$start_ts = ! empty( $details['start'] ) ? strtotime( $details['start'] ) : 0;
			$end_ts   = ! empty( $details['end'] ) ? strtotime( $details['end'] ) : $start_ts;

			if ( ! $start_ts ) {
				continue;
			}

			if ( $end_ts < $start_ts ) {
				$end_ts = $start_ts;
			}

			$loop_ts    = $start_ts;
			$start_key  = wp_date( 'Y-m-d', $start_ts, $timezone );
			$event_data = array(
				'id'        => get_the_ID(),
				'title'     => get_the_title(),
				'permalink' => get_permalink(),
				'start_ts'  => $start_ts,
				'end_ts'    => $end_ts,
				'start_key' => $start_key,
			);

			while ( $loop_ts <= $end_ts ) {
				$loop_key = wp_date( 'Y-m-d', $loop_ts, $timezone );
				if ( strpos( $loop_key, $month_key ) === 0 ) {
					$events_by_day[ $loop_key ][] = $event_data;
				}
				$loop_ts = strtotime( '+1 day', $loop_ts );
			}
		}
	}
	wp_reset_postdata();

	foreach ( $events_by_day as &$day_events ) {
		usort(
			$day_events,
			static function ( $a, $b ) {
				return $a['start_ts'] <=> $b['start_ts'];
			}
		);
	}
	unset( $day_events );

	$offset = ( 7 + (int) $target->format( 'w' ) - $start_of_week ) % 7;

	$headings = array();
	for ( $i = 0; $i < 7; $i++ ) {
		$headings[] = $weekday_labels[ ( $start_of_week + $i ) % 7 ];
	}

	$prev_month = $target->modify( '-1 month' );
	$next_month = $target->modify( '+1 month' );
	$base_url   = remove_query_arg( array( 'npmp_month', 'npmp_calendar_nonce' ) );
	$nav_nonce  = wp_create_nonce( 'npmp_calendar_nav' );
	$prev_link  = add_query_arg(
		array(
			'npmp_month'          => $prev_month->format( 'Y-m' ),
			'npmp_calendar_nonce' => $nav_nonce,
		),
		$base_url
	);
	$next_link  = add_query_arg(
		array(
			'npmp_month'          => $next_month->format( 'Y-m' ),
			'npmp_calendar_nonce' => $nav_nonce,
		),
		$base_url
	);

	ob_start();

	echo '<div class="npmp-calendar-wrapper">';
	echo '<div class="npmp-calendar-header">';
	echo '<a class="npmp-calendar-nav npmp-calendar-nav--prev" href="' . esc_url( $prev_link ) . '">&larr; ' . esc_html__( 'Previous', 'nonprofit-manager' ) . '</a>';
	echo '<h2 class="npmp-calendar-title">' . esc_html( wp_date( 'F Y', $target->getTimestamp(), $timezone ) ) . '</h2>';
	echo '<a class="npmp-calendar-nav npmp-calendar-nav--next" href="' . esc_url( $next_link ) . '">' . esc_html__( 'Next', 'nonprofit-manager' ) . ' &rarr;</a>';
	echo '</div>';

	echo '<table class="npmp-calendar-table"><thead><tr>';
	foreach ( $headings as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody><tr>';

	for ( $blank = 0; $blank < $offset; $blank++ ) {
		echo '<td class="npmp-calendar-day npmp-calendar-day--pad"></td>';
	}

	$cell_position = $offset;
	for ( $day = 1; $day <= $days_in_month; $day++ ) {
		$current = DateTimeImmutable::createFromFormat( 'Y-m-d', sprintf( '%s-%02d', $month_key, $day ), $timezone );
		$day_key = $current ? $current->format( 'Y-m-d' ) : sprintf( '%s-%02d', $month_key, $day );
		$classes = array( 'npmp-calendar-day' );

		if ( isset( $events_by_day[ $day_key ] ) ) {
			$classes[] = 'npmp-calendar-day--has-events';
		}

		if ( $day_key === $today_key ) {
			$classes[] = 'npmp-calendar-day--today';
		}

		echo '<td class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<div class="npmp-calendar-day-number">' . esc_html( $day ) . '</div>';

		if ( ! empty( $events_by_day[ $day_key ] ) ) {
			echo '<ul class="npmp-calendar-events">';
			foreach ( $events_by_day[ $day_key ] as $event ) {
				$is_start_day = ( $event['start_key'] === $day_key );
				$time_label   = '';

				if ( $is_start_day && $time_format ) {
					$time_label = wp_date( $time_format, $event['start_ts'], $timezone );
				} elseif ( ! $is_start_day && $event['start_ts'] !== $event['end_ts'] ) {
					$time_label = __( 'Continues', 'nonprofit-manager' );
				}

				echo '<li>';
				if ( $time_label ) {
					echo '<span class="npmp-calendar-event-time">' . esc_html( $time_label ) . '</span> ';
				}
				echo '<a href="' . esc_url( $event['permalink'] ) . '">' . esc_html( $event['title'] ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '</td>';

		$cell_position++;
		if ( 0 === $cell_position % 7 && $day < $days_in_month ) {
			echo '</tr><tr>';
		}
	}

	if ( 0 !== $cell_position % 7 ) {
		for ( $pad = $cell_position % 7; $pad < 7; $pad++ ) {
			echo '<td class="npmp-calendar-day npmp-calendar-day--pad"></td>';
		}
	}

	echo '</tr></tbody></table>';
	echo '</div>';

	return ob_get_clean();
}

/**
 * Shortcode handler for displaying events.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function npmp_events_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'category' => '',
			'limit'    => -1,
			'past'     => 'false',
		),
		$atts,
		'npmp_events'
	);

	$query_args = array(
		'post_type'      => 'npmp_event',
		'posts_per_page' => intval( $atts['limit'] ),
		'orderby'        => 'meta_value',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Event listings are sorted by a meta key.
		'meta_key'       => '_npmp_event_start',
		'order'          => 'ASC',
		'post_status'    => 'publish',
	);

	$now = current_time( 'mysql' );
	if ( 'true' !== strtolower( $atts['past'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Upcoming event filtering requires meta comparisons.
		$query_args['meta_query'] = array(
			array(
				'key'     => '_npmp_event_start',
				'value'   => $now,
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		);
	}

	if ( ! empty( $atts['category'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Event categories rely on taxonomy queries.
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'npmp_event_category',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
			),
		);
	}

	$events = new WP_Query( $query_args );

	if ( ! $events->have_posts() ) {
		return '<div class="npmp-events-list npmp-events-empty">' . esc_html__( 'No events found.', 'nonprofit-manager' ) . '</div>';
	}

	ob_start();

	echo '<div class="npmp-events-list">';

	while ( $events->have_posts() ) {
		$events->the_post();
		$details    = npmp_get_event_details( get_the_ID() );
		$date_label = npmp_format_event_datetime( $details );

		echo '<article class="npmp-event">';
		echo '<h3 class="npmp-event-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';

		if ( $date_label ) {
			echo '<p class="npmp-event-date">' . wp_kses_post( $date_label ) . '</p>';
		}

		$location = $details['location'] ?? '';
		if ( $location ) {
			echo '<p class="npmp-event-location">' . esc_html( $location ) . '</p>';
		}

		echo '<div class="npmp-event-excerpt">' . wp_kses_post( wpautop( get_the_excerpt() ) ) . '</div>';
		echo '</article>';
	}

	wp_reset_postdata();

	echo '</div>';

	return ob_get_clean();
}
add_shortcode( 'npmp_events', 'npmp_events_shortcode' );
add_shortcode( 'npmp_calendar', 'npmp_calendar_shortcode' );

/**
 * Append event details on single event pages.
 *
 * @param string $content Post content.
 * @return string
 */
function npmp_append_event_details_to_content( $content ) {
	if ( ! is_singular( 'npmp_event' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$details = npmp_get_event_details( get_the_ID() );

	$date_label = npmp_format_event_datetime( $details );
	$location   = $details['location'] ?? '';
	$url        = $details['url'] ?? '';

	$meta = '<section class="npmp-event-details">';
	$meta .= '<h2>' . esc_html__( 'Event Details', 'nonprofit-manager' ) . '</h2><ul>';

	if ( $date_label ) {
		$meta .= '<li><strong>' . esc_html__( 'When:', 'nonprofit-manager' ) . '</strong> ' . wp_kses_post( $date_label ) . '</li>';
	}

	if ( $location ) {
		$meta .= '<li><strong>' . esc_html__( 'Where:', 'nonprofit-manager' ) . '</strong> ' . esc_html( $location ) . '</li>';
	}

	if ( $url ) {
		$meta .= '<li><strong>' . esc_html__( 'More information:', 'nonprofit-manager' ) . '</strong> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></li>';
	}

	$meta .= '</ul></section>';

	return $content . $meta;
}
add_filter( 'the_content', 'npmp_append_event_details_to_content' );

/**
 * Output an iCal feed when requested via ?npmp-ical=1.
 *
 * @return void
 */
function npmp_maybe_render_ical_feed() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public iCal feed intentionally responds to a query parameter.
	if ( empty( $_GET['npmp-ical'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['npmp-ical'] ) ) ) {
		return;
	}

	$args = array(
		'post_type'      => 'npmp_event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'meta_value',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- iCal feed ordering relies on event start meta.
		'meta_key'       => '_npmp_event_start',
		'order'          => 'ASC',
	);

	$events = get_posts( $args );

	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=events.ics' );

	$output = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Nonprofit Manager//EN\r\n";

	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$host = preg_replace( '/[^A-Za-z0-9\.\-]/', '', $host );

	foreach ( $events as $event ) {
		$details = npmp_get_event_details( $event->ID );
		$start   = ! empty( $details['start'] ) ? strtotime( $details['start'] ) : false;
		$end     = ! empty( $details['end'] ) ? strtotime( $details['end'] ) : $start;

		if ( ! $start ) {
			continue;
		}

		$output .= "BEGIN:VEVENT\r\n";
		$output .= 'UID:' . absint( $event->ID ) . '@' . $host . "\r\n";
		$output .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', $start ) . "\r\n";
		$output .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start ) . "\r\n";
		if ( $end ) {
			$output .= 'DTEND:' . gmdate( 'Ymd\THis\Z', $end ) . "\r\n";
		}
		$output .= 'SUMMARY:' . npmp_ics_escape_text( get_the_title( $event ) ) . "\r\n";
		if ( ! empty( $details['location'] ) ) {
			$output .= 'LOCATION:' . npmp_ics_escape_text( $details['location'] ) . "\r\n";
		}
		$output .= "END:VEVENT\r\n";
	}

	$output .= "END:VCALENDAR\r\n";

	echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}
add_action( 'init', 'npmp_maybe_render_ical_feed' );

/**
 * Render the Calendar settings page.
 *
 * @return void
 */
function npmp_render_event_settings_page() {

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nonprofit-manager' ) );
	}

	$notices             = array();
	$current_calendar_id = absint( get_option( 'npmp_calendar_page_id', 0 ) );

	if (
		isset( $_POST['npmp_save_calendar_settings'] ) &&
		isset( $_POST['npmp_event_settings_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_event_settings_nonce'] ) ), 'npmp_save_calendar_settings' )
	) {
		$selected = isset( $_POST['npmp_calendar_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['npmp_calendar_page_id'] ) ) : '0';

		// Check if user selected "create_new"
		if ( 'create_new' === $selected ) {
			// Create a new page
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Events Calendar', 'nonprofit-manager' ),
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => "[npmp_calendar]\n\n[npmp_events limit=\"10\"]",
				)
			);

			if ( is_wp_error( $page_id ) ) {
				$notices[] = array(
					'type'    => 'error',
					'message' => $page_id->get_error_message(),
				);
			} else {
				update_option( 'npmp_calendar_page_id', absint( $page_id ) );
				$current_calendar_id = absint( $page_id );
				$notices[]           = array(
					'type'    => 'success',
					'message' => __( 'A new calendar page was created and set as the default.', 'nonprofit-manager' ),
				);
			}
		} else {
			update_option( 'npmp_calendar_page_id', absint( $selected ) );
			$current_calendar_id = absint( $selected );
			$notices[]           = array(
				'type'    => 'success',
				'message' => __( 'Calendar settings saved.', 'nonprofit-manager' ),
			);
		}
	}

	if (
		isset( $_POST['npmp_create_calendar_page'] ) &&
		isset( $_POST['npmp_event_create_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_event_create_nonce'] ) ), 'npmp_create_calendar_page' )
	) {
		$title = sanitize_text_field( wp_unslash( $_POST['npmp_calendar_page_title'] ?? __( 'Events Calendar', 'nonprofit-manager' ) ) );
		if ( '' === $title ) {
			$title = __( 'Events Calendar', 'nonprofit-manager' );
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => "[npmp_calendar]\n\n[npmp_events limit=\"10\"]",
			)
		);

		if ( is_wp_error( $page_id ) ) {
			$notices[] = array(
				'type'    => 'error',
				'message' => $page_id->get_error_message(),
			);
		} else {
			update_option( 'npmp_calendar_page_id', absint( $page_id ) );
			$current_calendar_id = absint( $page_id );
			$notices[]           = array(
				'type'    => 'success',
				'message' => __( 'A calendar page was created and set as the default destination.', 'nonprofit-manager' ),
			);
		}
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Calendar Settings', 'nonprofit-manager' ) . '</h1>';

	foreach ( $notices as $notice ) {
		$class = 'notice';
		if ( 'success' === $notice['type'] ) {
			$class .= ' notice-success';
		} elseif ( 'error' === $notice['type'] ) {
			$class .= ' notice-error';
		} else {
			$class .= ' notice-info';
		}
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	// Calendar Settings Card
	echo '<div class="card" style="max-width:720px; margin-bottom: 20px;">';
	echo '<h2 class="title">' . esc_html__( 'Calendar Settings', 'nonprofit-manager' ) . '</h2>';

	echo '<form method="post">';
	wp_nonce_field( 'npmp_save_calendar_settings', 'npmp_event_settings_nonce' );

	echo '<table class="form-table"><tbody>';
	echo '<tr>';
	echo '<th scope="row"><label for="npmp_calendar_page_id">' . esc_html__( 'Default Calendar Page', 'nonprofit-manager' ) . '</label></th>';
	echo '<td>';
	echo '<p class="description" style="margin-top: 0;">' . esc_html__( 'Select the WordPress page that should display your public events calendar.', 'nonprofit-manager' ) . '</p>';

	echo '<select name="npmp_calendar_page_id" id="npmp_calendar_page_id">';
	echo '<option value="0">' . esc_html__( '— Select a page —', 'nonprofit-manager' ) . '</option>';
	echo '<option value="create_new" style="font-weight: bold;">' . esc_html__( '+ New Page', 'nonprofit-manager' ) . '</option>';

	$pages = get_pages();
	foreach ( $pages as $page ) {
		$selected = ( absint( $current_calendar_id ) === $page->ID ) ? ' selected="selected"' : '';
		echo '<option value="' . esc_attr( $page->ID ) . '"' . $selected . '>' . esc_html( $page->post_title ) . '</option>';
	}
	echo '</select>';

	if ( $current_calendar_id ) {
		$calendar_url = get_permalink( $current_calendar_id );
		if ( $calendar_url ) {
			echo '<p class="description">' . sprintf(
				/* translators: %s: Calendar page URL */
				wp_kses_post( __( 'View your calendar: <a href="%s" target="_blank">%s</a>', 'nonprofit-manager' ) ),
				esc_url( $calendar_url ),
				esc_url( $calendar_url )
			) . '</p>';
		}
	}

	echo '</td>';
	echo '</tr>';
	echo '</tbody></table>';

	submit_button( esc_html__( 'Save Settings', 'nonprofit-manager' ), 'primary', 'npmp_save_calendar_settings' );
	echo '</form>';
	echo '</div>';

	// Embed Instructions Section
	echo '<div class="card" style="max-width:720px;">';
	echo '<h2 class="title">' . esc_html__( 'Embed Instructions', 'nonprofit-manager' ) . '</h2>';
	echo '<p>' . esc_html__( 'Use these shortcodes to display your calendar and events on any page or post:', 'nonprofit-manager' ) . '</p>';

	echo '<table class="widefat striped">';
	echo '<thead><tr><th>' . esc_html__( 'Shortcode', 'nonprofit-manager' ) . '</th><th>' . esc_html__( 'Description', 'nonprofit-manager' ) . '</th></tr></thead>';
	echo '<tbody>';

	echo '<tr>';
	echo '<td><code>[npmp_calendar]</code></td>';
	echo '<td>' . esc_html__( 'Displays the monthly calendar grid with navigation.', 'nonprofit-manager' ) . '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<td><code>[npmp_events limit="5"]</code></td>';
	echo '<td>' . esc_html__( 'Shows a list of upcoming events. Change the limit to display more or fewer events.', 'nonprofit-manager' ) . '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<td><code>[npmp_events past="true"]</code></td>';
	echo '<td>' . esc_html__( 'Lists past events for an archive page.', 'nonprofit-manager' ) . '</td>';
	echo '</tr>';

	echo '<tr>';
	echo '<td><code>[npmp_events category="fundraisers"]</code></td>';
	echo '<td>' . esc_html__( 'Filter events by category slug (replace "fundraisers" with your category).', 'nonprofit-manager' ) . '</td>';
	echo '</tr>';

	echo '</tbody></table>';
	echo '</div>';

	echo '</div>';
}

/**
 * Keep the Events menu highlighted when managing event content.
 *
 * @param string $parent_file Parent menu slug.
 * @return string
 */
function npmp_events_parent_menu( $parent_file ) {
	$screen = get_current_screen();

	if ( $screen && 'npmp_event' === $screen->post_type ) {
		return 'npmp-events';
	}

	if ( isset( $screen->id ) && 'events_page_npmp_event_settings' === $screen->id ) {
		return 'npmp-events';
	}

	return $parent_file;
}
add_filter( 'parent_file', 'npmp_events_parent_menu' );

/**
 * Match the correct submenu entry for Events pages.
 *
 * @param string $submenu_file Current submenu slug.
 * @return string
 */
function npmp_events_submenu_highlight( $submenu_file ) {
	global $plugin_page;
	$screen = get_current_screen();

	if ( $screen && 'npmp_event' === $screen->post_type ) {
		if ( 'post-new.php' === $screen->base ) {
			return 'post-new.php?post_type=npmp_event';
		}

		return 'edit.php?post_type=npmp_event';
	}

	if ( 'npmp_event_settings' === $plugin_page ) {
		return 'npmp_event_settings';
	}

	return $submenu_file;
}
add_filter( 'submenu_file', 'npmp_events_submenu_highlight' );

/**
 * Automatically embed the calendar on the configured page.
 *
 * @param string $content Original post content.
 * @return string
 */
function npmp_auto_inject_calendar_page( $content ) {
	if ( ! is_main_query() || ! in_the_loop() || ! is_page() ) {
		return $content;
	}

	$page_id = (int) get_option( 'npmp_calendar_page_id', 0 );
	if ( ! $page_id || get_the_ID() !== $page_id ) {
		return $content;
	}

	if ( false !== strpos( $content, '[npmp_calendar' ) ) {
		return $content;
	}

	return $content . "\n\n" . do_shortcode( '[npmp_calendar]' );
}
add_filter( 'the_content', 'npmp_auto_inject_calendar_page', 12 );

/**
 * Escape ICS text.
 *
 * @param string $text Raw text.
 * @return string
 */
function npmp_ics_escape_text( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = str_replace( array( "\r\n", "\r", "\n" ), '\n', $text );

	return strtr(
		$text,
		array(
			'\\' => '\\\\',
			','  => '\,',
			';'  => '\;',
		)
	);
}
