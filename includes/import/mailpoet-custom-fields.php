<?php
/**
 * File path: includes/import/mailpoet-custom-fields.php
 *
 * MailPoet's custom fields as extra fields for the MailPoet importer
 * (extra-fields.php). Read straight from the database, so they come over with
 * MailPoet on or off:
 *
 * - {prefix}mailpoet_custom_fields: id, name, type (text, textarea, radio,
 *   checkbox, select, date), params (serialized: label, values[{value}],
 *   date_type, date_format, validate), deleted_at.
 * - {prefix}mailpoet_subscriber_custom_field: subscriber_id, custom_field_id,
 *   value, one row per subscriber and field.
 *
 * Types: text that MailPoet checks as a number becomes Number, select and
 * radio become Dropdown with MailPoet's choices, a checkbox is one yes-or-no
 * box (MailPoet stores 1 when ticked), and a full date becomes Date. A date
 * with no day or no year (MailPoet's month, year, and month-and-year types)
 * becomes Text, since it isn't a calendar date. Checked in MailPoet 5.45.2.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Subscribers whose values one read fetches, by ID from the one asked for. */
if ( ! defined( 'NPMP_MAILPOET_EXTRA_WINDOW' ) ) {
	define( 'NPMP_MAILPOET_EXTRA_WINDOW', 500 );
}

add_filter( 'npmp_import_extra_field_sources', 'npmp_mailpoet_extra_field_source' );

/**
 * Register MailPoet as a staged source of extra fields.
 *
 * @param array $sources Sources.
 * @return array
 */
function npmp_mailpoet_extra_field_source( $sources ) {
	$sources             = is_array( $sources ) ? $sources : array();
	$sources['mailpoet'] = array(
		'name'   => 'MailPoet',
		'fields' => 'npmp_mailpoet_extra_fields',
		'values' => 'npmp_mailpoet_extra_values',
	);
	return $sources;
}

/**
 * Turn one MailPoet custom field row into an extra field. Pure.
 *
 * @param string $type   MailPoet type.
 * @param array  $params Unserialized params.
 * @return array{type:string,options:array,hints:array,note:string}
 */
function npmp_mailpoet_extra_field_shape( $type, array $params ) {
	$choices = array();
	foreach ( (array) ( $params['values'] ?? array() ) as $value ) {
		$label = is_array( $value ) ? (string) ( $value['value'] ?? '' ) : (string) $value;
		if ( '' !== trim( $label ) ) {
			$choices[] = trim( $label );
		}
	}
	$out = array(
		'type'    => 'text',
		'options' => array(),
		'hints'   => array(),
		'note'    => '',
	);
	switch ( (string) $type ) {
		case 'textarea':
			$out['type'] = 'textarea';
			break;
		case 'select':
		case 'radio':
			$out['type']    = 'dropdown';
			$out['options'] = $choices;
			break;
		case 'checkbox':
			$out['type'] = 'checkbox';
			break;
		case 'date':
			$date_type = (string) ( $params['date_type'] ?? 'year_month_day' );
			if ( 'year_month_day' === $date_type ) {
				$out['type'] = 'date';
				if ( 0 === stripos( (string) ( $params['date_format'] ?? '' ), 'DD' ) ) {
					$out['hints']['date_order'] = 'dmy';
				}
			} else {
				$out['note'] = __( 'A partial date in MailPoet, so it comes over as text.', 'nonprofit-manager' );
			}
			break;
		default:
			if ( 'number' === ( $params['validate'] ?? '' ) ) {
				$out['type'] = 'number';
			}
	}
	return $out;
}

/**
 * MailPoet's custom fields, with three sample values each.
 *
 * @return array key => array{label,type,options,samples,hints,note}
 */
function npmp_mailpoet_extra_fields() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array();
	if ( ! function_exists( 'npmp_mailpoet_has_table' ) || ! npmp_mailpoet_has_table( 'custom_fields' ) || ! npmp_mailpoet_has_table( 'subscriber_custom_field' ) ) {
		return $cache;
	}
	global $wpdb;
	$fields = npmp_mailpoet_table( 'custom_fields' );
	$values = npmp_mailpoet_table( 'subscriber_custom_field' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, type, params FROM {$fields} WHERE deleted_at IS NULL ORDER BY id ASC LIMIT %d", 200 ) );
	foreach ( (array) $rows as $row ) {
		$params  = maybe_unserialize( (string) $row->params );
		$params  = is_array( $params ) ? $params : array();
		$shape   = npmp_mailpoet_extra_field_shape( (string) $row->type, $params );
		$samples = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$values} WHERE custom_field_id = %d AND value <> '' ORDER BY subscriber_id ASC LIMIT %d", (int) $row->id, 3 ) );
		$label   = trim( (string) ( ! empty( $params['label'] ) ? $params['label'] : $row->name ) );
		$cache[ 'mp_' . (int) $row->id ] = array_merge(
			$shape,
			array(
				'label'   => '' !== $label ? $label : sprintf( 'MailPoet field %d', (int) $row->id ),
				'samples' => array_map( 'strval', (array) $samples ),
			)
		);
	}
	// phpcs:enable
	return $cache;
}

/**
 * One subscriber's values for the wanted fields.
 *
 * Reads a window of subscribers at a time, so a batch of 500 contacts costs
 * one query, not 500.
 *
 * @param int   $subscriber_id MailPoet subscriber ID.
 * @param array $keys          Wanted keys (mp_<id>).
 * @return array key => value
 */
function npmp_mailpoet_extra_values( $subscriber_id, array $keys ) {
	static $window = array(
		'from'   => 0,
		'to'     => -1,
		'keys'   => '',
		'values' => array(),
	);
	$subscriber_id = (int) $subscriber_id;
	$ids           = array();
	foreach ( $keys as $key ) {
		if ( preg_match( '/^mp_(\d+)$/', (string) $key, $m ) ) {
			$ids[] = (int) $m[1];
		}
	}
	if ( ! $ids || $subscriber_id < 1 || ! function_exists( 'npmp_mailpoet_has_table' ) || ! npmp_mailpoet_has_table( 'subscriber_custom_field' ) ) {
		return array();
	}
	sort( $ids );
	$sig = implode( ',', $ids );
	if ( $subscriber_id < $window['from'] || $subscriber_id > $window['to'] || $sig !== $window['keys'] ) {
		global $wpdb;
		$table  = npmp_mailpoet_table( 'subscriber_custom_field' );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$to     = $subscriber_id + NPMP_MAILPOET_EXTRA_WINDOW - 1;
		$args   = array_merge( array( $subscriber_id, $to ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from $wpdb->prefix, placeholders built above.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, custom_field_id, value FROM {$table} WHERE subscriber_id BETWEEN %d AND %d AND custom_field_id IN ($in)", $args ) );
		$values = array();
		foreach ( (array) $rows as $row ) {
			$values[ (int) $row->subscriber_id ][ 'mp_' . (int) $row->custom_field_id ] = (string) $row->value;
		}
		$window = array(
			'from'   => $subscriber_id,
			'to'     => $to,
			'keys'   => $sig,
			'values' => $values,
		);
	}
	return $window['values'][ $subscriber_id ] ?? array();
}
