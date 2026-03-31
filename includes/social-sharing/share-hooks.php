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
 * Render the Social Sharing meta box.
 *
 * @param WP_Post $post Current post.
 */
function npmp_social_render_meta_box( $post ) {
	$shared_on = get_post_meta( $post->ID, '_npmp_shared_on', true );
	$results   = get_post_meta( $post->ID, '_npmp_share_results', true );
	$manager   = NPMP_Social_Share_Manager::get_instance();
	$networks  = $manager->get_registered_networks();
	$accounts  = $manager->get_connected_accounts();

	wp_nonce_field( 'npmp_social_manual_share', 'npmp_social_manual_nonce' );

	if ( $shared_on ) {
		echo '<p><strong>' . esc_html__( 'Shared on:', 'nonprofit-manager' ) . '</strong> ' . esc_html( $shared_on ) . '</p>';
		if ( is_array( $results ) ) {
			echo '<ul style="margin:0 0 10px;">';
			foreach ( $results as $net => $res ) {
				$label  = isset( $networks[ $net ] ) ? $networks[ $net ]['label'] : $net;
				$status = is_wp_error( $res ) ? '&#10060; ' . $res->get_error_message() : '&#9989;';
				echo '<li>' . esc_html( $label ) . ': ' . wp_kses_post( $status ) . '</li>';
			}
			echo '</ul>';
		}
	} else {
		echo '<p>' . esc_html__( 'This post has not been shared yet.', 'nonprofit-manager' ) . '</p>';
	}

	if ( ! empty( $accounts ) && 'publish' === $post->post_status ) {
		echo '<button type="submit" name="npmp_share_now" value="1" class="button button-secondary">';
		echo esc_html__( 'Share Now', 'nonprofit-manager' );
		echo '</button>';
	} elseif ( empty( $accounts ) ) {
		echo '<p class="description">';
		printf(
			/* translators: %s: URL to social sharing settings */
			wp_kses_post( __( 'No networks connected. <a href="%s">Configure Social Sharing</a>.', 'nonprofit-manager' ) ),
			esc_url( admin_url( 'admin.php?page=npmp_social_sharing' ) )
		);
		echo '</p>';
	}
}

/**
 * Handle the manual "Share Now" button from the meta box.
 *
 * @param int $post_id Post ID.
 */
function npmp_social_handle_manual_share( $post_id ) {
	if (
		! isset( $_POST['npmp_share_now'] ) ||
		! isset( $_POST['npmp_social_manual_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['npmp_social_manual_nonce'] ) ), 'npmp_social_manual_share' )
	) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$manager = NPMP_Social_Share_Manager::get_instance();
	$results = $manager->share_post( $post_id );

	update_post_meta( $post_id, '_npmp_shared_on', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_npmp_share_results', $results );
}
add_action( 'save_post', 'npmp_social_handle_manual_share' );
