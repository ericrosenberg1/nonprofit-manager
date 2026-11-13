<?php
/**
 * File path: includes/social-sharing/share-hooks.php
 *
 * Hooks that trigger automatic sharing and provide a manual "Share Now" meta box.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * 1. Auto-share on publish
 * ----------------------------------------------------------------*/

/**
 * Fire sharing when a post or event transitions to 'publish' for the first time.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post object.
 */
function npmp_social_maybe_share( $new_status, $old_status, $post ) {
	// Only trigger on first publish.
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// Supported post types.
	$types = apply_filters( 'npmp_social_post_types', array( 'post', 'npmp_event' ) );
	if ( ! in_array( $post->post_type, $types, true ) ) {
		return;
	}

	$manager  = NPMP_Social_Share_Manager::get_instance();
	$settings = $manager->get_settings();

	if ( empty( $settings['auto_share'] ) ) {
		return;
	}

	// Prevent double-sharing.
	if ( get_post_meta( $post->ID, '_npmp_shared_on', true ) ) {
		return;
	}

	$results = $manager->share_post( $post->ID );

	// Record timestamp so we never re-share on update.
	update_post_meta( $post->ID, '_npmp_shared_on', current_time( 'mysql' ) );

	// Store individual results for display in the meta box.
	update_post_meta( $post->ID, '_npmp_share_results', $results );
}
add_action( 'transition_post_status', 'npmp_social_maybe_share', 10, 3 );

/* ------------------------------------------------------------------
 * 2. Meta box: share status & manual share button
 * ----------------------------------------------------------------*/

/**
 * Register the Social Sharing meta box on supported post types.
 */
function npmp_social_add_meta_box() {
	$types = apply_filters( 'npmp_social_post_types', array( 'post', 'npmp_event' ) );
	foreach ( $types as $type ) {
		add_meta_box(
			'npmp_social_share_box',
			__( 'Social Sharing', 'nonprofit-manager' ),
			'npmp_social_render_meta_box',
			$type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'npmp_social_add_meta_box' );

/**
 * The share history for one post, as HTML for the meta box.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function npmp_social_share_status_html( $post ) {
	$shared_on = get_post_meta( $post->ID, '_npmp_shared_on', true );
	$results   = get_post_meta( $post->ID, '_npmp_share_results', true );
	$networks  = NPMP_Social_Share_Manager::get_instance()->get_registered_networks();

	if ( ! $shared_on ) {
		$type_object = get_post_type_object( $post->post_type );
		$singular    = $type_object && ! empty( $type_object->labels->singular_name ) ? $type_object->labels->singular_name : __( 'Post', 'nonprofit-manager' );
		/* translators: %s: singular name of the post type, such as Post or Event. */
		return '<p>' . esc_html( sprintf( __( 'This %s hasn\'t been shared yet.', 'nonprofit-manager' ), strtolower( $singular ) ) ) . '</p>';
	}

	$when = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $shared_on );
	$html = '<p><strong>' . esc_html__( 'Shared on:', 'nonprofit-manager' ) . '</strong> ' . esc_html( $when ) . '</p>';
	if ( is_array( $results ) && $results ) {
		$html .= '<ul class="npmp-share-results">';
		foreach ( $results as $net => $res ) {
			$label = isset( $networks[ $net ] ) ? $networks[ $net ]['label'] : $net;
			if ( is_wp_error( $res ) ) {
				$state = '<span aria-hidden="true">&#10060;</span> <span class="screen-reader-text">' . esc_html__( 'Not shared:', 'nonprofit-manager' ) . '</span> ' . esc_html( $res->get_error_message() );
			} else {
				$state = '<span aria-hidden="true">&#9989;</span> <span class="screen-reader-text">' . esc_html__( 'Shared', 'nonprofit-manager' ) . '</span>';
			}
			$html .= '<li>' . esc_html( $label ) . ': ' . $state . '</li>';
		}
		$html .= '</ul>';
	}
	return $html;
}

/**
 * Render the Social Sharing meta box.
 *
 * Share Now is a plain button that posts to admin-ajax. The block editor
 * saves meta boxes with the form's submit button left out, so the old
 * submit button never reached save_post and did nothing there.
 *
 * @param WP_Post $post Current post.
 */
function npmp_social_render_meta_box( $post ) {
	$accounts = NPMP_Social_Share_Manager::get_instance()->get_connected_accounts();

	echo '<div class="npmp-share-status">' . wp_kses_post( npmp_social_share_status_html( $post ) ) . '</div>';

	if ( ! empty( $accounts ) && 'publish' === $post->post_status ) {
		printf(
			'<button type="button" class="button button-secondary npmp-share-now" data-post="%1$d" data-nonce="%2$s">%3$s</button>',
			(int) $post->ID,
			esc_attr( wp_create_nonce( 'npmp_social_share_now_' . $post->ID ) ),
			esc_html__( 'Share now', 'nonprofit-manager' )
		);
		echo '<p class="npmp-share-now-message" role="status" aria-live="polite"></p>';
	} elseif ( empty( $accounts ) ) {
		echo '<p class="description">';
		printf(
			/* translators: %s: URL to social sharing settings */
			wp_kses_post( __( 'No networks connected. <a href="%s">Set up Social Sharing</a>.', 'nonprofit-manager' ) ),
			esc_url( admin_url( 'admin.php?page=npmp_social_sharing' ) )
		);
		echo '</p>';
	}
}

/**
 * Whether the current user may share a post right now.
 *
 * Only published posts go out, and only from someone who may publish them.
 * The button is hidden on drafts, but the request can still be forged, and a
 * Contributor could otherwise post a draft to the organization's accounts.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error
 */
function npmp_social_can_share_now( $post_id ) {
	$post_id = (int) $post_id;
	$types   = apply_filters( 'npmp_social_post_types', array( 'post', 'npmp_event' ) );
	if ( ! $post_id || ! in_array( get_post_type( $post_id ), $types, true ) ) {
		return new WP_Error( 'npmp_share_type', __( 'This item can\'t be shared.', 'nonprofit-manager' ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'npmp_share_cap', __( 'You don\'t have permission to share this.', 'nonprofit-manager' ) );
	}
	if ( 'publish' !== get_post_status( $post_id ) ) {
		return new WP_Error( 'npmp_share_status', __( 'Publish it first, then share it.', 'nonprofit-manager' ) );
	}
	$type_object = get_post_type_object( get_post_type( $post_id ) );
	if ( ! $type_object || ! current_user_can( $type_object->cap->publish_posts ) ) {
		return new WP_Error( 'npmp_share_cap', __( 'You don\'t have permission to share this.', 'nonprofit-manager' ) );
	}
	return true;
}

/**
 * AJAX: share a published post now (the meta box's Share now button).
 *
 * @return void
 */
function npmp_social_ajax_share_now() {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! check_ajax_referer( 'npmp_social_share_now_' . $post_id, 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'nonprofit-manager' ) ), 403 );
	}

	$allowed = npmp_social_can_share_now( $post_id );
	if ( is_wp_error( $allowed ) ) {
		wp_send_json_error( array( 'message' => $allowed->get_error_message() ), 403 );
	}

	$manager = NPMP_Social_Share_Manager::get_instance();
	$results = $manager->share_post( $post_id );

	update_post_meta( $post_id, '_npmp_shared_on', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_npmp_share_results', $results );

	$failed = 0;
	foreach ( (array) $results as $res ) {
		if ( is_wp_error( $res ) ) {
			++$failed;
		}
	}

	wp_send_json_success(
		array(
			'message' => $failed ? __( 'Some networks didn\'t take the post. You can see which below.', 'nonprofit-manager' ) : __( 'Shared.', 'nonprofit-manager' ),
			'html'    => npmp_social_share_status_html( get_post( $post_id ) ),
		)
	);
}
add_action( 'wp_ajax_npmp_social_share_now', 'npmp_social_ajax_share_now' );

/**
 * Load the Share now script on the editors that show the meta box.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function npmp_social_enqueue_share_now( $hook_suffix ) {
	if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$types  = apply_filters( 'npmp_social_post_types', array( 'post', 'npmp_event' ) );
	if ( ! $screen || ! in_array( $screen->post_type, $types, true ) ) {
		return;
	}
	$file = dirname( __DIR__, 2 ) . '/assets/js/npmp-share-now.js';
	wp_enqueue_script(
		'npmp-share-now',
		plugins_url( 'assets/js/npmp-share-now.js', dirname( __DIR__, 2 ) . '/nonprofit-manager.php' ),
		array(),
		file_exists( $file ) ? (string) filemtime( $file ) : '1',
		true
	);
	wp_localize_script(
		'npmp-share-now',
		'npmpShareNow',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'sharing' => __( 'Sharing…', 'nonprofit-manager' ),
			'failed'  => __( 'Sharing didn\'t go through. Please try again.', 'nonprofit-manager' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'npmp_social_enqueue_share_now' );
