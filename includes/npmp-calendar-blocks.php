<?php
/**
 * File path: includes/npmp-calendar-blocks.php
 *
 * Gutenberg blocks for the calendar and upcoming-events list. Both are dynamic
 * blocks rendered through the existing shortcodes, so the block output and the
 * shortcode output are identical. Loaded only when the calendar feature is on
 * (its render callbacks depend on the calendar shortcodes).
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the editor script, the shared stylesheet, and the two blocks.
 *
 * @return void
 */
function npmp_calendar_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return; // WordPress < 5.0.
	}

	$plugin_file = dirname( __DIR__ ) . '/nonprofit-manager.php';
	$script_rel  = 'assets/js/npmp-calendar-blocks.js';
	$style_rel   = 'assets/css/npmp-calendar.css';
	$handle      = 'npmp-calendar-blocks';
	$style       = 'npmp-calendar';
	$version     = function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $script_rel ) : null;

	wp_register_script(
		$handle,
		plugins_url( $script_rel, $plugin_file ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
		$version,
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'nonprofit-manager' );
	}

	// Shared front-end stylesheet (same one the shortcodes use). Register once;
	// setting it as each block's "style" lets WordPress load it wherever a block
	// appears, including block themes and widget areas.
	if ( ! wp_style_is( $style, 'registered' ) ) {
		wp_register_style(
			$style,
			plugins_url( $style_rel, $plugin_file ),
			array(),
			function_exists( 'npmp_get_asset_version' ) ? npmp_get_asset_version( $style_rel ) : null
		);
	}

	register_block_type(
		'nonprofit-manager/calendar',
		array(
			'editor_script'   => $handle,
			'style'           => $style,
			'render_callback' => 'npmp_calendar_block_render',
			'attributes'      => array(
				'view'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'category' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);

	register_block_type(
		'nonprofit-manager/events',
		array(
			'editor_script'   => $handle,
			'style'           => $style,
			'render_callback' => 'npmp_events_block_render',
			'attributes'      => array(
				'limit'    => array(
					'type'    => 'number',
					'default' => 10,
				),
				'category' => array(
					'type'    => 'string',
					'default' => '',
				),
				'past'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
		)
	);
}
add_action( 'init', 'npmp_calendar_register_blocks' );

/**
 * Render the calendar block via the [npmp_calendar] shortcode.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_calendar_block_render( $attributes ) {
	if ( ! function_exists( 'npmp_calendar_shortcode' ) ) {
		return '';
	}

	$atts = array();
	$view = isset( $attributes['view'] ) ? sanitize_key( $attributes['view'] ) : '';
	if ( in_array( $view, array( 'month', 'week', 'list' ), true ) ) {
		$atts['view'] = $view;
	}
	if ( ! empty( $attributes['category'] ) ) {
		$atts['category'] = sanitize_text_field( $attributes['category'] );
	}

	return npmp_calendar_shortcode( $atts );
}

/**
 * Render the upcoming-events block via the [npmp_events] shortcode.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_events_block_render( $attributes ) {
	if ( ! function_exists( 'npmp_events_shortcode' ) ) {
		return '';
	}

	$limit = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10;
	$limit = max( 1, min( 50, $limit ) );

	$atts = array(
		'limit' => $limit,
		'past'  => empty( $attributes['past'] ) ? 'false' : 'true',
	);
	if ( ! empty( $attributes['category'] ) ) {
		$atts['category'] = sanitize_text_field( $attributes['category'] );
	}

	return npmp_events_shortcode( $atts );
}
