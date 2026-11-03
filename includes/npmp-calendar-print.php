<?php
/**
 * File path: includes/npmp-calendar-print.php
 *
 * "Print or save as PDF" for the front-end calendar (month, week and list
 * views), the [npmp_events] shortcode and the Events block. One button covers
 * both, since every current browser offers "Save as PDF" in its print dialog.
 * No PDF library.
 *
 * How it works: each calendar or list renders a hidden print header (the
 * organization name and the month or date range) and, unless switched off, a
 * button. assets/js/npmp-calendar-print.js marks the clicked container and its
 * ancestors and calls window.print(). assets/css/npmp-calendar-print.css then
 * hides every sibling along that path, so the theme's header, menus, sidebars
 * and footer stay off the paper. A visitor pressing Ctrl+P without the button
 * still gets a clean calendar with the toolbar hidden.
 *
 * The button shows when option `npmp_calendar_print_button` is on (an unset
 * option means on). A `print="no"` attribute hides it on one calendar or list,
 * and `print="yes"` shows it on one even when the site setting is off.
 *
 * Loaded by includes/npmp-calendar.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site-wide setting shows the button. An unset option means on.
 *
 * @return bool
 */
function npmp_calendar_print_site_enabled() {
	return (bool) (int) get_option( 'npmp_calendar_print_button', 1 );
}

/**
 * Whether one calendar or list shows the button. A blank attribute follows the
 * site setting, "no" hides it, "yes" shows it.
 *
 * @param mixed $attr The `print` shortcode or block attribute.
 * @return bool
 */
function npmp_calendar_print_wants_button( $attr ) {
	if ( is_bool( $attr ) ) {
		return $attr;
	}
	if ( is_int( $attr ) ) {
		return (bool) $attr;
	}
	$value = is_string( $attr ) ? strtolower( trim( $attr ) ) : '';
	if ( in_array( $value, array( 'no', 'false', '0', 'off', 'hide' ), true ) ) {
		return false;
	}
	if ( in_array( $value, array( 'yes', 'true', '1', 'on', 'show' ), true ) ) {
		return true;
	}
	return npmp_calendar_print_site_enabled();
}

/**
 * The organization name for the printout header, from the organization
 * settings, falling back to the site title.
 *
 * @return string
 */
function npmp_calendar_print_org_name() {
	$name = '';
	if ( class_exists( 'NPMP_Org_Settings' ) ) {
		$org  = NPMP_Org_Settings::get();
		$name = isset( $org['name'] ) ? trim( (string) $org['name'] ) : '';
	}
	if ( '' === $name ) {
		$name = (string) get_option( 'blogname', '' );
	}
	return $name;
}

/**
 * A readable date range: "November 3, 2026", "November 3 to November 9, 2026"
 * or "December 28, 2026 to January 3, 2027".
 *
 * @param int               $start_ts Unix start, 0 for none.
 * @param int               $end_ts   Unix end, 0 for the start day only.
 * @param DateTimeZone|null $timezone Site timezone.
 * @return string
 */
function npmp_calendar_print_range_label( $start_ts, $end_ts = 0, $timezone = null ) {
	$start_ts = (int) $start_ts;
	$end_ts   = (int) $end_ts;
	if ( $start_ts <= 0 ) {
		return '';
	}
	if ( null === $timezone ) {
		$timezone = wp_timezone();
	}

	$start_day = wp_date( 'Y-m-d', $start_ts, $timezone );
	if ( $end_ts <= $start_ts || wp_date( 'Y-m-d', $end_ts, $timezone ) === $start_day ) {
		return wp_date( 'F j, Y', $start_ts, $timezone );
	}

	$same_year = wp_date( 'Y', $start_ts, $timezone ) === wp_date( 'Y', $end_ts, $timezone );

	return sprintf(
		/* translators: 1: first date, 2: last date */
		__( '%1$s to %2$s', 'nonprofit-manager' ),
		wp_date( $same_year ? 'F j' : 'F j, Y', $start_ts, $timezone ),
		wp_date( 'F j, Y', $end_ts, $timezone )
	);
}

/**
 * The hidden header that heads a printout. It carries the `hidden` attribute,
 * so it never shows on screen even where the calendar stylesheet is missing,
 * and the print stylesheet shows it on paper.
 *
 * @param string $title   Main line: the month, the week's dates, or "Upcoming events".
 * @param string $range   Optional second line, such as the list's date range.
 * @param array  $context What is printing: source (calendar|events), view,
 *                        category, start and end (Unix), and the raw atts.
 * @return string
 */
function npmp_calendar_print_header_html( $title, $range = '', $context = array() ) {
	$heading = array(
		'org'   => npmp_calendar_print_org_name(),
		'title' => (string) $title,
		'range' => (string) $range,
		'lines' => array(),
	);

	/**
	 * Filter the printout header of a front-end calendar or event list. Add a
	 * string to `lines` for an extra line, such as "Showing: Art, History".
	 *
	 * @param array $heading { org, title, range, lines[] }.
	 * @param array $context { source: 'calendar'|'events', view, category,
	 *                       start, end (Unix), atts }.
	 */
	$heading = apply_filters( 'npmp_calendar_print_heading', $heading, $context );
	if ( ! is_array( $heading ) ) {
		return '';
	}

	$html = '<div class="npmp-print-header" hidden>';
	if ( ! empty( $heading['org'] ) ) {
		$html .= '<p class="npmp-print-org">' . esc_html( $heading['org'] ) . '</p>';
	}
	if ( ! empty( $heading['title'] ) ) {
		$html .= '<p class="npmp-print-title">' . esc_html( $heading['title'] ) . '</p>';
	}
	if ( ! empty( $heading['range'] ) ) {
		$html .= '<p class="npmp-print-range">' . esc_html( $heading['range'] ) . '</p>';
	}
	if ( ! empty( $heading['lines'] ) && is_array( $heading['lines'] ) ) {
		foreach ( $heading['lines'] as $line ) {
			if ( is_scalar( $line ) && '' !== (string) $line ) {
				$html .= '<p class="npmp-print-line">' . esc_html( (string) $line ) . '</p>';
			}
		}
	}
	$html .= '</div>';

	return $html;
}

/**
 * The button. Its bar stays hidden until the print script loads, so a visitor
 * without JavaScript never sees a button that does nothing.
 *
 * @return string
 */
function npmp_calendar_print_button_html() {
	$icon = '<svg class="npmp-print-icon" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>';

	return '<div class="npmp-print-bar" hidden><button type="button" class="npmp-print-button">'
		. $icon
		. '<span>' . esc_html__( 'Print or save as PDF', 'nonprofit-manager' ) . '</span>'
		. '</button></div>';
}

/**
 * Register the print stylesheet and script.
 *
 * @return void
 */
function npmp_calendar_print_register_assets() {
	if ( wp_style_is( 'npmp-calendar-print', 'registered' ) ) {
		return;
	}

	$plugin_file = dirname( __DIR__ ) . '/nonprofit-manager.php';
	$css_rel     = 'assets/css/npmp-calendar-print.css';
	$js_rel      = 'assets/js/npmp-calendar-print.js';
	$has_version = function_exists( 'npmp_get_asset_version' );

	// Every rule sits inside @media print, so "all" is safe and survives
	// optimisation plugins that merge stylesheets.
	wp_register_style(
		'npmp-calendar-print',
		plugins_url( $css_rel, $plugin_file ),
		array(),
		$has_version ? npmp_get_asset_version( $css_rel ) : null,
		'all'
	);

	wp_register_script(
		'npmp-calendar-print',
		plugins_url( $js_rel, $plugin_file ),
		array(),
		$has_version ? npmp_get_asset_version( $js_rel ) : null,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', 'npmp_calendar_print_register_assets' );

/**
 * Load the print assets for a calendar or list that is rendering now. The
 * stylesheet loads on every calendar, so Ctrl+P looks right too. The script
 * loads only where a button shows.
 *
 * @param bool $with_button Whether this calendar shows the button.
 * @return void
 */
function npmp_calendar_print_enqueue( $with_button ) {
	if ( is_admin() || ! did_action( 'init' ) ) {
		return;
	}
	npmp_calendar_print_register_assets();

	// The screen stylesheet normally loads in the head on pages that hold a
	// calendar. A calendar in a widget or a theme template gets it here.
	if ( wp_style_is( 'npmp-calendar', 'registered' ) ) {
		wp_enqueue_style( 'npmp-calendar' );
	}
	wp_enqueue_style( 'npmp-calendar-print' );

	if ( $with_button ) {
		wp_enqueue_script( 'npmp-calendar-print' );
	}
}
