<?php
/**
 * Custom post types used internally by the Nonprofit Manager plugin.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NPMP_NEWSLETTER_QUEUE_POST_TYPE' ) ) {
	define( 'NPMP_NEWSLETTER_QUEUE_POST_TYPE', 'npmp_nl_queue' );
}

if ( ! defined( 'NPMP_NEWSLETTER_EVENT_POST_TYPE' ) ) {
	define( 'NPMP_NEWSLETTER_EVENT_POST_TYPE', 'npmp_nl_event' );
}

/**
 * Register internal post types for donations and newsletter tracking.
 *
 * @return void
 */
function npmp_register_internal_post_types() {

	register_post_type(
		'npmp_donation',
		array(
			'labels' => array(
				'name'          => __( 'Donations', 'nonprofit-manager' ),
				'singular_name' => __( 'Donation', 'nonprofit-manager' ),
			),
			'public'             => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'has_archive'        => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'exclude_from_search'=> true,
		)
	);

	register_post_type(
		NPMP_NEWSLETTER_QUEUE_POST_TYPE,
		array(
			'labels' => array(
				'name'          => __( 'Newsletter Queue', 'nonprofit-manager' ),
				'singular_name' => __( 'Newsletter Queue Item', 'nonprofit-manager' ),
			),
			'public'             => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'has_archive'        => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'exclude_from_search'=> true,
		)
	);

	register_post_type(
		NPMP_NEWSLETTER_EVENT_POST_TYPE,
		array(
			'labels' => array(
				'name'          => __( 'Newsletter Events', 'nonprofit-manager' ),
				'singular_name' => __( 'Newsletter Event', 'nonprofit-manager' ),
			),
			'public'             => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'has_archive'        => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'exclude_from_search'=> true,
		)
	);
}

/**
 * Rename legacy newsletter CPT slugs that exceeded the 20-character limit.
 *
 * @return void
 */
function npmp_maybe_migrate_newsletter_post_types() {
	static $ran = false;

	if ( $ran ) {
		return;
	}

	$ran = true;

	if ( get_option( 'npmp_newsletter_post_types_migrated', false ) ) {
		return;
	}

	global $wpdb;

	$mappings = array(
		'npmp_newsletter_queue' => NPMP_NEWSLETTER_QUEUE_POST_TYPE,
		'npmp_newsletter_event' => NPMP_NEWSLETTER_EVENT_POST_TYPE,
	);

	$errors = false;

	foreach ( $mappings as $legacy => $current ) {
		if ( $legacy === $current ) {
			continue;
		}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration needs to update legacy post types in bulk.
			$result = $wpdb->update(
			$wpdb->posts,
			array(
				'post_type' => $current,
			),
			array(
				'post_type' => $legacy,
			),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $result ) {
			$errors = true;
		}
	}

	if ( ! $errors ) {
		update_option( 'npmp_newsletter_post_types_migrated', 1 );
	}
}
add_action( 'plugins_loaded', 'npmp_maybe_migrate_newsletter_post_types', 5 );

add_action( 'init', 'npmp_register_internal_post_types', 5 );
