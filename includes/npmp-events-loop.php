<?php
/**
 * Events loop: order, layout, featured image and excerpt options for the
 * [npmp_events] shortcode and the Events block.
 *
 * Site-wide defaults live on the Events settings screen (option
 * `npmp_events_loop`). The shortcode attributes and the block's sidebar
 * override them for one list. Anything left unset falls back to the saved
 * setting, and an unsaved site renders exactly what 2026.10.11 rendered
 * (a plain list with the excerpt, soonest first).
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Built-in defaults. Image off and layout list keep older sites unchanged.
 *
 * @return array
 */
function npmp_events_loop_defaults() {
	return array(
		'layout'         => 'list',
		'columns'        => 3,
		'order'          => 'auto',
		'image'          => 0,
		'ratio'          => '16-9',
		'excerpt'        => 1,
		'excerpt_length' => 55,
		'date'           => 1,
		'location'       => 1,
		'readmore'       => 0,
	);
}

/**
 * Allowed values for the choice fields.
 *
 * @return array
 */
function npmp_events_loop_choices() {
	return array(
		'layout' => array(
			'list'   => __( 'List', 'nonprofit-manager' ),
			'grid'   => __( 'Grid', 'nonprofit-manager' ),
			'cards'  => __( 'Cards', 'nonprofit-manager' ),
			'mosaic' => __( 'Mosaic', 'nonprofit-manager' ),
		),
		'order'  => array(
			'auto' => __( 'Automatic (newest first for past events, soonest first for upcoming)', 'nonprofit-manager' ),
			'desc' => __( 'Newest to oldest', 'nonprofit-manager' ),
			'asc'  => __( 'Oldest to newest', 'nonprofit-manager' ),
		),
		'ratio'  => array(
			'16-9' => __( 'Wide (16:9)', 'nonprofit-manager' ),
			'3-2'  => __( 'Landscape (3:2)', 'nonprofit-manager' ),
			'4-3'  => __( 'Classic (4:3)', 'nonprofit-manager' ),
			'1-1'  => __( 'Square (1:1)', 'nonprofit-manager' ),
		),
	);
}

/**
 * Read a loose yes/no value (checkbox, shortcode attribute, block select).
 *
 * @param mixed $value Raw value.
 * @return bool|null True, false, or null when unset or unrecognised.
 */
function npmp_events_loop_bool( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (bool) $value;
	}
	if ( ! is_string( $value ) ) {
		return null;
	}
	$value = strtolower( trim( $value ) );
	if ( in_array( $value, array( '1', 'true', 'yes', 'on', 'show' ), true ) ) {
		return true;
	}
	if ( in_array( $value, array( '0', 'false', 'no', 'off', 'hide' ), true ) ) {
		return false;
	}
	return null;
}

/**
 * Keep only valid, known keys. Unset or invalid values are dropped so the
 * caller can fall back to the next layer.
 *
 * @param mixed $raw Partial settings from an option, attributes, or a form.
 * @return array
 */
function npmp_events_loop_sanitize( $raw ) {
	$clean = array();
	if ( ! is_array( $raw ) ) {
		return $clean;
	}
	$choices = npmp_events_loop_choices();

	foreach ( array( 'layout', 'order', 'ratio' ) as $key ) {
		if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
			$value = strtolower( trim( $raw[ $key ] ) );
			if ( isset( $choices[ $key ][ $value ] ) ) {
				$clean[ $key ] = $value;
			}
		}
	}

	if ( isset( $raw['columns'] ) && is_numeric( $raw['columns'] ) ) {
		$columns = (int) $raw['columns'];
		if ( $columns >= 2 && $columns <= 4 ) {
			$clean['columns'] = $columns;
		}
	}

	if ( isset( $raw['excerpt_length'] ) && is_numeric( $raw['excerpt_length'] ) ) {
		$clean['excerpt_length'] = max( 5, min( 200, (int) $raw['excerpt_length'] ) );
	}

	foreach ( array( 'image', 'excerpt', 'date', 'location', 'readmore' ) as $key ) {
		if ( array_key_exists( $key, $raw ) ) {
			$flag = npmp_events_loop_bool( $raw[ $key ] );
			if ( null !== $flag ) {
				$clean[ $key ] = $flag ? 1 : 0;
			}
		}
	}

	return $clean;
}

/**
 * Settings for one list: defaults, then the saved option, then overrides.
 *
 * @param array $overrides Per-list overrides (shortcode or block).
 * @return array
 */
function npmp_events_loop_settings( $overrides = array() ) {
	$saved = get_option( 'npmp_events_loop', array() );

	return array_merge(
		npmp_events_loop_defaults(),
		npmp_events_loop_sanitize( $saved ),
		npmp_events_loop_sanitize( $overrides )
	);
}

/**
 * Resolve the sort direction. Automatic sorts past archives newest first and
 * upcoming lists soonest first.
 *
 * @param string $order 'auto', 'asc' or 'desc'.
 * @param bool   $past  Whether past events are included.
 * @return string 'ASC' or 'DESC'.
 */
function npmp_events_loop_direction( $order, $past ) {
	if ( 'desc' === $order ) {
		return 'DESC';
	}
	if ( 'asc' === $order ) {
		return 'ASC';
	}
	return $past ? 'DESC' : 'ASC';
}

/**
 * Plain text of an event's body for an auto-excerpt. Headings, media, forms
 * and shortcodes are dropped, so a meeting that opens with its date and an
 * "Agenda" heading excerpts from the first paragraph or list item instead.
 *
 * @param string $content Post content.
 * @return string
 */
function npmp_events_loop_text_from_content( $content ) {
	$content = (string) $content;
	$content = preg_replace( '/<!--.*?-->/s', '', $content );
	$content = preg_replace( '#<(h[1-6]|figure|iframe|script|style|table|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $content );
	$content = strip_shortcodes( $content );
	$content = preg_replace( '#</(p|li|div|blockquote)>|<br\s*/?>#i', ' ', $content );
	$text    = wp_strip_all_tags( $content );
	$text    = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Excerpt for one event: the manual excerpt when there is one, otherwise the
 * first words of the body.
 *
 * @param WP_Post|object $post  Event post.
 * @param int            $words Word limit.
 * @return string Plain text.
 */
function npmp_events_loop_excerpt( $post, $words ) {
	$manual = isset( $post->post_excerpt ) ? trim( (string) $post->post_excerpt ) : '';
	$text   = '' !== $manual
		? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $manual ) ) )
		: npmp_events_loop_text_from_content( isset( $post->post_content ) ? $post->post_content : '' );

	$text = wp_trim_words( $text, max( 5, (int) $words ), '…' );

	/**
	 * Filter an event's excerpt in the events loop.
	 *
	 * @param string $text Plain text excerpt.
	 * @param object $post Event post.
	 */
	return (string) apply_filters( 'npmp_events_loop_excerpt', $text, $post );
}

/**
 * Query arguments for the loop.
 *
 * @param array $atts     Normalised attributes (category, limit, past).
 * @param array $settings Resolved settings.
 * @param array $context  Query context for npmp_events_query_args (source,
 *                        view, category, atts, block). Defaults to an
 *                        [npmp_events] list.
 * @return array
 */
function npmp_events_loop_query_args( $atts, $settings, $context = array() ) {
	$past = 'true' === strtolower( (string) $atts['past'] );

	$args = array(
		'post_type'      => 'npmp_event',
		'posts_per_page' => intval( $atts['limit'] ),
		// One page of events and no pager, so skip counting every match.
		'no_found_rows'  => true,
		'orderby'        => 'meta_value',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Event listings are sorted by a meta key.
		'meta_key'       => '_npmp_event_start',
		'order'          => npmp_events_loop_direction( $settings['order'], $past ),
		'post_status'    => 'publish',
	);

	if ( ! $past ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Upcoming event filtering requires meta comparisons.
		$args['meta_query'] = array(
			array(
				'key'     => '_npmp_event_start',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			),
		);
	}

	if ( ! empty( $atts['category'] ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Event categories rely on taxonomy queries.
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'npmp_event_category',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_title', explode( ',', $atts['category'] ) ),
			),
		);
	}

	if ( function_exists( 'npmp_events_filter_query_args' ) ) {
		$args = npmp_events_filter_query_args(
			$args,
			array_merge(
				array(
					'source'   => 'events',
					'view'     => 'list',
					'category' => (string) ( $atts['category'] ?? '' ),
					'atts'     => $atts,
					'block'    => false,
				),
				(array) $context
			)
		);
	}

	return $args;
}

/**
 * Turn a block-gap value from the editor into CSS. Core only writes block gap
 * for blocks with a layout, so the loop writes its own. Accepts a length
 * ("2rem"), a spacing preset ("var:preset|spacing|40"), or the split
 * { top, left } form.
 *
 * @param mixed $gap Raw value from the block's style attribute.
 * @return string CSS value for the gap property, or '' when unusable.
 */
function npmp_events_loop_gap_css( $gap ) {
	$one = static function ( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( preg_match( '/^var:preset\|spacing\|([a-z0-9-]+)$/i', $value, $m ) ) {
			return 'var(--wp--preset--spacing--' . strtolower( $m[1] ) . ')';
		}
		if ( preg_match( '/^\d*\.?\d+(px|rem|em|%|vw|vh)?$/', $value ) ) {
			return $value;
		}
		return '';
	};

	if ( is_array( $gap ) ) {
		$row    = $one( $gap['top'] ?? '' );
		$column = $one( $gap['left'] ?? '' );
		if ( '' === $row && '' === $column ) {
			return '';
		}
		return ( '' === $row ? $column : $row ) . ' ' . ( '' === $column ? $row : $column );
	}

	return $one( $gap );
}

/**
 * Class and style for the list's root element.
 *
 * @param array $settings Resolved settings.
 * @param mixed $gap      Optional block-gap value (see npmp_events_loop_gap_css()).
 * @return array { class: string, style: string }
 */
function npmp_events_loop_root( $settings, $gap = '' ) {
	$classes = array(
		'npmp-events-list',
		'npmp-events--' . $settings['layout'],
		'npmp-events--cols-' . (int) $settings['columns'],
	);
	if ( $settings['image'] ) {
		$classes[] = 'npmp-events--with-image';
	}

	$vars = array(
		'--npmp-events-ratio:' . str_replace( '-', ' / ', $settings['ratio'] ),
	);

	$accent = sanitize_hex_color( (string) get_option( 'npmp_calendar_accent', '#0f766e' ) );
	if ( $accent ) {
		$vars[] = '--npmp-cal-accent:' . $accent;
	}

	$gap_css = npmp_events_loop_gap_css( $gap );
	if ( '' !== $gap_css ) {
		$vars[] = 'gap:' . $gap_css;
	}

	return array(
		'class' => implode( ' ', $classes ),
		'style' => implode( ';', $vars ),
	);
}

/**
 * First and last start time (Unix) of the most recently rendered loop. The
 * calendar's list view reads it for its printout header.
 *
 * @param array|null $set New range to store, or null to read.
 * @return array { 0: first start, 1: last start }, zeros when nothing rendered.
 */
function npmp_events_loop_last_range( $set = null ) {
	static $range = array( 0, 0 );
	if ( is_array( $set ) ) {
		$range = array( (int) ( $set[0] ?? 0 ), (int) ( $set[1] ?? 0 ) );
	}
	return $range;
}

/**
 * Render the events loop.
 *
 * @param array $atts      Normalised attributes: category, limit, past, plus
 *                         optional display overrides (see npmp_events_loop_sanitize()),
 *                         a block `gap`, and `print` (blank, yes or no).
 * @param bool  $for_block True when called from the block, so the block's
 *                         color, type, spacing and border supports reach the root element.
 * @param bool  $embedded  True inside the calendar's list view, which carries its
 *                         own print button and header.
 * @param array $context   Query context for npmp_events_query_args. The
 *                         calendar's list view passes its own.
 * @return string
 */
function npmp_events_loop_render( $atts, $for_block = false, $embedded = false, $context = array() ) {
	$settings = npmp_events_loop_settings( $atts );
	$events   = new WP_Query( npmp_events_loop_query_args( $atts, $settings, array_merge( array( 'block' => (bool) $for_block ), (array) $context ) ) );

	npmp_events_loop_last_range( array( 0, 0 ) );

	if ( ! $events->have_posts() ) {
		return '<div class="npmp-events-list npmp-events-empty">' . esc_html__( 'No events found.', 'nonprofit-manager' ) . '</div>';
	}

	$has_print  = ! $embedded && function_exists( 'npmp_calendar_print_wants_button' );
	$show_print = $has_print && npmp_calendar_print_wants_button( isset( $atts['print'] ) ? $atts['print'] : '' );
	if ( $has_print ) {
		npmp_calendar_print_enqueue( $show_print );
	}

	$root = npmp_events_loop_root( $settings, isset( $atts['gap'] ) ? $atts['gap'] : '' );
	if ( $has_print ) {
		$root['class'] .= ' npmp-print-region';
	}
	if ( $show_print ) {
		$root['class'] .= ' npmp-events--has-print';
	}

	// In the editor preview (a REST request) the editor already puts the block's
	// own styles on its wrapper, so the preview skips them to avoid doubling.
	$in_editor = defined( 'REST_REQUEST' ) && REST_REQUEST;
	if ( $for_block && ! $in_editor && function_exists( 'get_block_wrapper_attributes' ) ) {
		$root_attrs = get_block_wrapper_attributes( $root );
	} else {
		$root_attrs = 'class="' . esc_attr( $root['class'] ) . '" style="' . esc_attr( $root['style'] ) . '"';
	}

	$show_image  = (bool) $settings['image'];
	$first_start = 0;
	$last_start  = 0;

	ob_start();

	while ( $events->have_posts() ) {
		$events->the_post();
		$post       = get_post();
		$title      = get_the_title();
		$permalink  = get_permalink();
		$details    = npmp_get_event_details( $post->ID );
		$start_ts   = ! empty( $details['start'] ) ? (int) npmp_event_datetime_to_timestamp( $details['start'] ) : 0;
		if ( $start_ts > 0 ) {
			$first_start = $first_start ? min( $first_start, $start_ts ) : $start_ts;
			$last_start  = max( $last_start, $start_ts );
		}
		$date_label = $settings['date'] ? npmp_format_event_datetime( $details ) : '';
		$location   = $settings['location'] ? ( $details['location'] ?? '' ) : '';
		$has_thumb  = $show_image && has_post_thumbnail( $post->ID );

		$cat_class = function_exists( 'npmp_calendar_event_category_classes' ) ? npmp_calendar_event_category_classes( $post->ID ) : '';
		echo '<article class="' . esc_attr( trim( 'npmp-event' . ( $has_thumb ? ' npmp-event--has-image' : '' ) . ' ' . $cat_class ) ) . '">';

		if ( $show_image ) {
			echo '<a class="npmp-event-media" href="' . esc_url( $permalink ) . '" tabindex="-1" aria-hidden="true">';
			if ( $has_thumb ) {
				echo get_the_post_thumbnail( $post->ID, 'medium_large', array( 'class' => 'npmp-event-image', 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			} else {
				$stamp = ! empty( $details['start'] ) ? npmp_event_datetime_to_timestamp( $details['start'] ) : false;
				echo '<span class="npmp-event-media-fallback">';
				if ( $stamp ) {
					echo '<span class="npmp-event-media-month">' . esc_html( wp_date( 'M', $stamp ) ) . '</span>';
					echo '<span class="npmp-event-media-day">' . esc_html( wp_date( 'j', $stamp ) ) . '</span>';
					echo '<span class="npmp-event-media-year">' . esc_html( wp_date( 'Y', $stamp ) ) . '</span>';
				}
				echo '</span>';
			}
			echo '</a>';
		}

		echo '<div class="npmp-event-body">';
		echo '<h3 class="npmp-event-title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';

		if ( $date_label ) {
			echo '<p class="npmp-event-date">' . wp_kses_post( $date_label ) . '</p>';
		}

		if ( $location ) {
			echo '<p class="npmp-event-location">' . esc_html( $location ) . '</p>';
		}

		if ( $settings['excerpt'] ) {
			$excerpt = npmp_events_loop_excerpt( $post, $settings['excerpt_length'] );
			if ( '' !== $excerpt ) {
				echo '<div class="npmp-event-excerpt"><p>' . esc_html( $excerpt ) . '</p></div>';
			}
		}

		if ( $settings['readmore'] ) {
			echo '<p class="npmp-event-more"><a class="npmp-event-more-link" href="' . esc_url( $permalink ) . '">' . esc_html__( 'Read more', 'nonprofit-manager' ) . '<span class="screen-reader-text"> ' . esc_html( $title ) . '</span></a></p>';
		}

		echo '</div>';
		echo '</article>';
	}

	wp_reset_postdata();

	$items = ob_get_clean();

	npmp_events_loop_last_range( array( $first_start, $last_start ) );

	$print = '';
	if ( $has_print ) {
		$past   = 'true' === strtolower( (string) ( $atts['past'] ?? 'false' ) );
		$print .= npmp_calendar_print_header_html(
			$past ? __( 'Events', 'nonprofit-manager' ) : __( 'Upcoming events', 'nonprofit-manager' ),
			npmp_calendar_print_range_label( $first_start, $last_start ),
			array(
				'source'   => 'events',
				'view'     => 'list',
				'category' => (string) ( $atts['category'] ?? '' ),
				'start'    => $first_start,
				'end'      => $last_start,
				'atts'     => $atts,
			)
		);
		if ( $show_print ) {
			$print .= npmp_calendar_print_button_html();
		}
	}

	return '<div ' . $root_attrs . '>' . $print . $items . '</div>';
}

/**
 * Settings screen rows for the loop. Called from the Events settings form.
 *
 * @return void
 */
function npmp_events_loop_render_settings_rows() {
	$s       = npmp_events_loop_settings();
	$choices = npmp_events_loop_choices();

	echo '<tr><th scope="row" colspan="2"><h2 style="margin:1.5em 0 0;">' . esc_html__( 'Event lists and archives', 'nonprofit-manager' ) . '</h2>';
	echo '<p class="description" style="font-weight:400;">' . esc_html__( 'How the Events block and the [npmp_events] shortcode look by default. You can override these for one block in the editor sidebar, where you also set colors, type, spacing, and borders. On a block theme, you can style every Events block at once under Appearance → Editor → Styles → Blocks.', 'nonprofit-manager' ) . '</p></th></tr>';

	$select = static function ( $key, $id, $label ) use ( $s, $choices ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select name="npmp_events_loop[' . esc_attr( $key ) . ']" id="' . esc_attr( $id ) . '">';
		foreach ( $choices[ $key ] as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s[ $key ], $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	};

	$select( 'layout', 'npmp_events_loop_layout', __( 'Layout', 'nonprofit-manager' ) );

	echo '<tr><th scope="row"><label for="npmp_events_loop_columns">' . esc_html__( 'Columns', 'nonprofit-manager' ) . '</label></th><td>';
	echo '<select name="npmp_events_loop[columns]" id="npmp_events_loop_columns">';
	foreach ( array( 2, 3, 4 ) as $n ) {
		echo '<option value="' . esc_attr( $n ) . '"' . selected( (int) $s['columns'], $n, false ) . '>' . esc_html( (string) $n ) . '</option>';
	}
	echo '</select><p class="description">' . esc_html__( 'For grid, cards, and mosaic. They drop to fewer columns on tablets and one on phones.', 'nonprofit-manager' ) . '</p></td></tr>';

	$select( 'order', 'npmp_events_loop_order', __( 'Order', 'nonprofit-manager' ) );

	echo '<tr><th scope="row">' . esc_html__( 'Featured image', 'nonprofit-manager' ) . '</th><td>';
	echo '<label><input type="checkbox" name="npmp_events_loop[image]" value="1"' . checked( (int) $s['image'], 1, false ) . '> ' . esc_html__( 'Show each event\'s featured image', 'nonprofit-manager' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'Events without one show a date tile.', 'nonprofit-manager' ) . '</p></td></tr>';

	$select( 'ratio', 'npmp_events_loop_ratio', __( 'Image shape', 'nonprofit-manager' ) );

	echo '<tr><th scope="row">' . esc_html__( 'Excerpt', 'nonprofit-manager' ) . '</th><td>';
	echo '<label><input type="checkbox" name="npmp_events_loop[excerpt]" value="1"' . checked( (int) $s['excerpt'], 1, false ) . '> ' . esc_html__( 'Show an excerpt', 'nonprofit-manager' ) . '</label><br>';
	echo '<label for="npmp_events_loop_excerpt_length">' . esc_html__( 'Words:', 'nonprofit-manager' ) . '</label> ';
	echo '<input type="number" min="5" max="200" name="npmp_events_loop[excerpt_length]" id="npmp_events_loop_excerpt_length" value="' . esc_attr( $s['excerpt_length'] ) . '" class="small-text">';
	echo '<p class="description">' . esc_html__( 'Uses the event\'s Excerpt field, or the first words of its text when that is blank.', 'nonprofit-manager' ) . '</p></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Details', 'nonprofit-manager' ) . '</th><td>';
	echo '<label><input type="checkbox" name="npmp_events_loop[date]" value="1"' . checked( (int) $s['date'], 1, false ) . '> ' . esc_html__( 'Show the date and time', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="checkbox" name="npmp_events_loop[location]" value="1"' . checked( (int) $s['location'], 1, false ) . '> ' . esc_html__( 'Show the location', 'nonprofit-manager' ) . '</label><br>';
	echo '<label><input type="checkbox" name="npmp_events_loop[readmore]" value="1"' . checked( (int) $s['readmore'], 1, false ) . '> ' . esc_html__( 'Show a "Read more" link', 'nonprofit-manager' ) . '</label>';
	echo '</td></tr>';
}

/**
 * Save the loop settings from the Events settings form. The caller has
 * already verified the nonce and capability.
 *
 * @return void
 */
function npmp_events_loop_save_from_post() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller.
	$raw = isset( $_POST['npmp_events_loop'] ) && is_array( $_POST['npmp_events_loop'] ) ? wp_unslash( $_POST['npmp_events_loop'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by npmp_events_loop_sanitize().

	// Unchecked boxes are absent from the POST, so they mean "off".
	foreach ( array( 'image', 'excerpt', 'date', 'location', 'readmore' ) as $key ) {
		if ( ! isset( $raw[ $key ] ) ) {
			$raw[ $key ] = 0;
		}
	}

	update_option( 'npmp_events_loop', npmp_events_loop_sanitize( $raw ) );
}
