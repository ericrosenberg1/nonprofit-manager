<?php
/**
 * File path: includes/email-newsletter/email-plugin-blocks.php
 *
 * Other plugins' blocks in the email version of a newsletter (Pete,
 * wordpress.org topic "more-plugins").
 *
 * ONLYOFFICE: the embedded viewer is a script that mail apps never run, so the
 * reader saw a 500-pixel gap. In the email we print ONLYOFFICE's own link view
 * instead (the file name and its Open button), which Pete found works.
 *
 * TablePress: the page shows a table a page of rows at a time, and the email
 * printed every row. In the email we keep the rows of the first page (the
 * table's "entries per page", or datatables_paginate_entries on the block or
 * shortcode), say how many rows the table has, and link the web version when
 * the newsletter has one. We also drop TablePress's "Edit" link, which shows
 * when the person who sends is logged in.
 *
 * The web version keeps both as they are. Checked against ONLYOFFICE 2.3.0 and
 * TablePress 3.4.1. The pure parts run in tests/test-email-plugin-blocks.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the render in progress is an email (and not the web version).
 *
 * @return bool
 */
function npmp_email_plugin_blocks_active() {
	return function_exists( 'npmp_email_is_rendering' ) && npmp_email_is_rendering()
		&& ! ( function_exists( 'npmp_newsletter_web_render' ) && npmp_newsletter_web_render() );
}

/**
 * Read a TablePress yes-or-no option the way TablePress does: a shortcode
 * value is the string "true" or "false", a saved option is a bool.
 *
 * @param mixed $value Value.
 * @return bool
 */
function npmp_email_tablepress_bool( $value ) {
	if ( is_string( $value ) ) {
		return 'true' === strtolower( trim( $value ) );
	}
	return (bool) $value;
}

/**
 * How many rows a TablePress table shows on its first page, or 0 when the
 * page shows every row.
 *
 * @param array $atts    Block parameters or shortcode attributes.
 * @param array $options The table's saved options.
 * @return int
 */
function npmp_email_tablepress_limit( $atts, $options ) {
	$atts    = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();
	$options = is_array( $options ) ? $options : array();
	$pick    = static function ( $key, $fallback ) use ( $atts, $options ) {
		if ( isset( $atts[ $key ] ) && '' !== $atts[ $key ] ) {
			return $atts[ $key ];
		}
		return array_key_exists( $key, $options ) ? $options[ $key ] : $fallback;
	};
	if ( ! npmp_email_tablepress_bool( $pick( 'use_datatables', true ) ) || ! npmp_email_tablepress_bool( $pick( 'datatables_paginate', true ) ) ) {
		return 0;
	}
	$entries = (int) $pick( 'datatables_paginate_entries', 10 );
	return $entries > 0 ? $entries : 10;
}

/**
 * Keep the first $limit rows of a table's body. The header and footer stay.
 *
 * @param string $html  Table HTML.
 * @param int    $limit Rows to keep, 0 for all.
 * @return array{html:string,kept:int,total:int}
 */
function npmp_email_table_trim( $html, $limit ) {
	$html  = (string) $html;
	$limit = (int) $limit;
	if ( ! preg_match( '#(<tbody\b[^>]*>)(.*?)(</tbody\s*>)#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return array( 'html' => $html, 'kept' => 0, 'total' => 0 );
	}
	preg_match_all( '#<tr\b.*?</tr\s*>#is', $m[2][0], $rows );
	$rows  = $rows[0];
	$total = count( $rows );
	if ( $limit < 1 || $total <= $limit ) {
		return array( 'html' => $html, 'kept' => $total, 'total' => $total );
	}
	$body = $m[1][0] . implode( "\n", array_slice( $rows, 0, $limit ) ) . $m[3][0];
	$html = substr( $html, 0, $m[0][1] ) . $body . substr( $html, $m[0][1] + strlen( $m[0][0] ) );
	return array( 'html' => $html, 'kept' => $limit, 'total' => $total );
}

/**
 * The line under a trimmed table.
 *
 * @param int    $kept    Rows in the email.
 * @param int    $total   Rows in the table.
 * @param string $web_url The newsletter's web version, or ''.
 * @return string HTML.
 */
function npmp_email_table_note( $kept, $total, $web_url = '' ) {
	$text = sprintf(
		/* translators: 1: rows in the email, 2: rows in the table. */
		__( 'Showing the first %1$d of %2$d rows.', 'nonprofit-manager' ),
		(int) $kept,
		(int) $total
	);
	$out  = esc_html( $text );
	$href = '' !== (string) $web_url && function_exists( 'npmp_email_embed_href' ) ? npmp_email_embed_href( $web_url ) : '';
	if ( '' !== $href ) {
		$out .= ' <a href="' . $href . '">' . esc_html__( 'See the full table', 'nonprofit-manager' ) . '</a>';
	}
	return '<p class="npmp-email-table-note">' . $out . '</p>';
}

/**
 * Trim a rendered TablePress table for the email.
 *
 * @param string $html     TablePress output.
 * @param string $table_id Table ID.
 * @param array  $atts     Block parameters or shortcode attributes.
 * @return string
 */
function npmp_email_tablepress_html( $html, $table_id, $atts ) {
	$options = array();
	if ( class_exists( 'TablePress' ) && isset( TablePress::$model_table ) && is_object( TablePress::$model_table ) && '' !== (string) $table_id ) {
		$table = TablePress::$model_table->load( (string) $table_id, false, true );
		if ( is_array( $table ) && isset( $table['options'] ) && is_array( $table['options'] ) ) {
			$options = $table['options'];
		}
	}
	$trim = npmp_email_table_trim( $html, npmp_email_tablepress_limit( $atts, $options ) );
	if ( $trim['kept'] >= $trim['total'] ) {
		return $html;
	}
	$web = function_exists( 'npmp_email_embed_target' ) ? npmp_email_embed_target() : '';
	return $trim['html'] . npmp_email_table_note( $trim['kept'], $trim['total'], $web );
}

/**
 * ONLYOFFICE's link view for a file, or '' when ONLYOFFICE isn't running.
 *
 * @param array $atts Block or shortcode attributes.
 * @return string
 */
function npmp_email_onlyoffice_link( $atts ) {
	global $shortcode_tags;
	$atts = is_array( $atts ) ? $atts : array();
	// A shortcode's names arrive in lower case and ONLYOFFICE reads camelCase.
	foreach ( array( 'fileName', 'inNewTab', 'showOpenButton', 'openButtonText' ) as $key ) {
		$lower = strtolower( $key );
		if ( ! isset( $atts[ $key ] ) && isset( $atts[ $lower ] ) ) {
			$atts[ $key ] = $atts[ $lower ];
		}
	}
	unset( $atts['documentview'] );
	$atts['documentView'] = 'link';
	if ( empty( $atts['id'] ) || empty( $shortcode_tags['onlyoffice'] ) || ! is_callable( $shortcode_tags['onlyoffice'] ) ) {
		return '';
	}
	$html = (string) call_user_func( $shortcode_tags['onlyoffice'], $atts, '', 'onlyoffice' );
	return false !== stripos( $html, '<a ' ) ? $html : '';
}

/**
 * In an email, swap an embedded ONLYOFFICE block for its link view and trim a
 * TablePress block.
 *
 * @param string $html  Block output.
 * @param array  $block Parsed block.
 * @return string
 */
function npmp_email_plugin_blocks_render_block( $html, $block ) {
	$name = (string) ( $block['blockName'] ?? '' );
	if ( 'onlyoffice-wordpress/onlyoffice' !== $name && 'tablepress/table' !== $name ) {
		return $html;
	}
	if ( ! npmp_email_plugin_blocks_active() ) {
		return $html;
	}
	$attrs = (array) ( $block['attrs'] ?? array() );
	if ( 'tablepress/table' === $name ) {
		$params = function_exists( 'shortcode_parse_atts' ) ? shortcode_parse_atts( (string) ( $attrs['parameters'] ?? '' ) ) : array();
		return npmp_email_tablepress_html( $html, (string) ( $attrs['id'] ?? '' ), is_array( $params ) ? $params : array() );
	}
	if ( 'link' === (string) ( $attrs['documentView'] ?? '' ) ) {
		return $html;
	}
	return npmp_email_onlyoffice_link( $attrs );
}
add_filter( 'render_block', 'npmp_email_plugin_blocks_render_block', 20, 2 );

/**
 * The same for the [onlyoffice] shortcode outside a block. ONLYOFFICE reads
 * its shortcode's documentView in camelCase and shortcodes arrive in lower
 * case, so a bare shortcode always renders the embedded viewer.
 *
 * @param false|string $out  Output so far.
 * @param string       $tag  Shortcode name.
 * @param array|string $attr Attributes.
 * @return false|string
 */
function npmp_email_plugin_blocks_pre_shortcode( $out, $tag, $attr ) {
	if ( 'onlyoffice' !== $tag || false !== $out || ! npmp_email_plugin_blocks_active() ) {
		return $out;
	}
	return npmp_email_onlyoffice_link( is_array( $attr ) ? $attr : array() );
}
add_filter( 'pre_do_shortcode_tag', 'npmp_email_plugin_blocks_pre_shortcode', 10, 3 );

/**
 * The same for TablePress's [table] shortcode outside a block.
 *
 * @param string       $out  Shortcode output.
 * @param string       $tag  Shortcode name.
 * @param array|string $attr Attributes.
 * @return string
 */
function npmp_email_plugin_blocks_shortcode_output( $out, $tag, $attr ) {
	$table_tag = class_exists( 'TablePress' ) && isset( TablePress::$shortcode ) ? (string) TablePress::$shortcode : 'table';
	if ( $table_tag !== $tag || ! class_exists( 'TablePress' ) || ! npmp_email_plugin_blocks_active() ) {
		return $out;
	}
	$attr = is_array( $attr ) ? $attr : array();
	return npmp_email_tablepress_html( (string) $out, (string) ( $attr['id'] ?? '' ), $attr );
}
add_filter( 'do_shortcode_tag', 'npmp_email_plugin_blocks_shortcode_output', 10, 3 );

/**
 * No TablePress "Edit" link in an email.
 *
 * @param bool $show Whether TablePress shows the link.
 * @return bool
 */
function npmp_email_plugin_blocks_tablepress_edit_link( $show ) {
	return npmp_email_plugin_blocks_active() ? false : $show;
}
add_filter( 'tablepress_edit_link_below_table', 'npmp_email_plugin_blocks_tablepress_edit_link' );
