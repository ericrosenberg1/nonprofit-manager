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

	// Shared front-end stylesheet (same one the shortcodes use). Register once.
	// Setting it as each block's "style" lets WordPress load it wherever a block
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
				// '' follows Calendar Settings, 'yes' or 'no' overrides it.
				'print'    => array(
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
			// These hook the block into the native appearance tools: the block
			// sidebar's Styles tab, and Appearance > Editor > Styles > Blocks on
			// block themes (theme.json styles.blocks["nonprofit-manager/events"]).
			'supports'        => array(
				'html'       => false,
				'align'      => array( 'wide', 'full' ),
				'anchor'     => true,
				'color'      => array(
					'background' => true,
					'text'       => true,
					'link'       => true,
				),
				'typography' => array(
					'fontSize'   => true,
					'lineHeight' => true,
				),
				'spacing'    => array(
					'margin'   => true,
					'padding'  => true,
					'blockGap' => true,
				),
				'__experimentalBorder' => array(
					'color'  => true,
					'radius' => true,
					'style'  => true,
					'width'  => true,
				),
				'shadow'     => true,
			),
			'attributes'      => array(
				'limit'          => array(
					'type'    => 'number',
					'default' => 10,
				),
				'category'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'past'           => array(
					'type'    => 'boolean',
					'default' => false,
				),
				// Display options. An empty value means "use the Events setting".
				'layout'         => array(
					'type'    => 'string',
					'default' => '',
				),
				'columns'        => array(
					'type'    => 'number',
					'default' => 0,
				),
				'order'          => array(
					'type'    => 'string',
					'default' => '',
				),
				'ratio'          => array(
					'type'    => 'string',
					'default' => '',
				),
				'image'          => array(
					'type'    => 'string',
					'default' => '',
				),
				'excerpt'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'excerptLength'  => array(
					'type'    => 'number',
					'default' => 0,
				),
				'date'           => array(
					'type'    => 'string',
					'default' => '',
				),
				'location'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'readmore'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'print'          => array(
					'type'    => 'string',
					'default' => '',
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
	if ( ! empty( $attributes['print'] ) ) {
		$atts['print'] = sanitize_key( $attributes['print'] );
	}

	/**
	 * Filter the shortcode attributes the Calendar block hands to
	 * [npmp_calendar]. Pro registers a `group` block attribute (through
	 * core's `register_block_type_args`) and copies it across here.
	 *
	 * @param array $atts       Attributes for npmp_calendar_shortcode().
	 * @param array $attributes The block's own attributes.
	 */
	$atts = (array) apply_filters( 'npmp_calendar_block_atts', $atts, (array) $attributes );

	return npmp_calendar_shortcode( $atts );
}

/**
 * Render the events block. It shares its renderer with [npmp_events], so the
 * block and the shortcode always agree.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function npmp_events_block_render( $attributes ) {
	if ( ! function_exists( 'npmp_events_loop_render' ) ) {
		return '';
	}

	// 0 shows every event, otherwise 1 to 200.
	$limit = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10;
	$limit = $limit < 1 ? -1 : min( 200, $limit );

	$atts = array(
		'limit'    => $limit,
		'past'     => empty( $attributes['past'] ) ? 'false' : 'true',
		'category' => ! empty( $attributes['category'] ) ? sanitize_text_field( $attributes['category'] ) : '',
	);

	foreach ( array( 'layout', 'columns', 'order', 'ratio', 'image', 'excerpt', 'date', 'location', 'readmore', 'print' ) as $key ) {
		if ( isset( $attributes[ $key ] ) && '' !== $attributes[ $key ] && 0 !== $attributes[ $key ] ) {
			$atts[ $key ] = $attributes[ $key ];
		}
	}
	if ( isset( $attributes['style']['spacing']['blockGap'] ) ) {
		$atts['gap'] = $attributes['style']['spacing']['blockGap'];
	}
	if ( ! empty( $attributes['excerptLength'] ) ) {
		$atts['excerpt_length'] = $attributes['excerptLength'];
	}

	/**
	 * Filter the attributes the Events block hands to the events loop. Pro
	 * registers a `group` block attribute and copies it across here.
	 *
	 * @param array $atts       Attributes for npmp_events_loop_render().
	 * @param array $attributes The block's own attributes.
	 */
	$atts = (array) apply_filters( 'npmp_events_block_atts', $atts, (array) $attributes );

	return npmp_events_loop_render( $atts, true );
}
