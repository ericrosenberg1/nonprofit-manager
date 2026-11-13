<?php
/**
 * File path: includes/import/extra-fields.php
 *
 * Extra fields: data an import source holds that has no home among the
 * contact's own fields (a CSV column nobody mapped, a Mailchimp merge field,
 * a MailPoet or FluentCRM custom field, a Brevo attribute). With Pro, each one
 * can land in a Pro custom field. Without Pro, the import screens list them as
 * needing Pro custom fields and nothing is written.
 *
 * Free owns the plumbing and Pro owns the fields:
 *
 *   npmp_import_extra_fields_supported   filter, bool. Pro returns true.
 *   npmp_import_extra_field_targets      filter, field ID => array{label,type,options}.
 *                                        Pro lists its contact custom fields.
 *   npmp_import_extra_fields_prepare     filter on a plan, key => array{field}|array{create:{label,type,options}}.
 *                                        Pro creates each `create` field and returns
 *                                        key => array{field:string,created:bool}.
 *   npmp_import_extra_fields_write       filter on a result, with the contact ID,
 *                                        field ID => raw value, and a context. Pro
 *                                        validates and saves through its own manager,
 *                                        and returns array{written:int,unchanged:int,skipped:array<string,int>}.
 *
 * Values travel two ways:
 *
 * - The import wizard (CSV, XLSX, Google Sheets, Mailchimp, Constant Contact,
 *   Brevo) maps a column to `cf:<field ID>`, so the value rides in the record
 *   that npmp_import_row_imported carries. The step runner turns `cf_new`
 *   columns into fields before the first row (npmp_import_extra_prepare_mapping()).
 * - The staged sources (MailPoet, FluentCRM) register a reader on
 *   npmp_import_extra_field_sources, and the owner ticks fields on their review
 *   screen. The listener below reads each contact's values from the old
 *   plugin's tables when the shared contact writer fires npmp_import_row_imported.
 *
 * Bad values (a date nobody can read, a dropdown choice the field doesn't
 * have) are skipped and counted, never fatal. An empty value never clears a
 * field. Pure helpers at the top: tests/test-import-extra-fields.php.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

/** Most choices an import adds to a dropdown or checkbox field it created. */
if ( ! defined( 'NPMP_IMPORT_EXTRA_MAX_OPTIONS' ) ) {
	define( 'NPMP_IMPORT_EXTRA_MAX_OPTIONS', 50 );
}

// =====================================================================
// Pure helpers, covered by tests/test-import-extra-fields.php
// =====================================================================

/**
 * Pro's custom field types.
 *
 * @return string[]
 */
function npmp_import_extra_types() {
	return array( 'text', 'textarea', 'dropdown', 'checkbox', 'date', 'number', 'url', 'email' );
}

/**
 * Words for each type, for the screens.
 *
 * @return array<string,string>
 */
function npmp_import_extra_type_labels() {
	return array(
		'text'     => __( 'Text', 'nonprofit-manager' ),
		'textarea' => __( 'Long text', 'nonprofit-manager' ),
		'dropdown' => __( 'Dropdown', 'nonprofit-manager' ),
		'checkbox' => __( 'Checkbox', 'nonprofit-manager' ),
		'date'     => __( 'Date', 'nonprofit-manager' ),
		'number'   => __( 'Number', 'nonprofit-manager' ),
		'url'      => __( 'Web address', 'nonprofit-manager' ),
		'email'    => __( 'Email', 'nonprofit-manager' ),
	);
}

/**
 * Words that mean yes and no in a yes-or-no column.
 *
 * @return array{yes:string[],no:string[]}
 */
function npmp_import_extra_bool_words() {
	return array(
		'yes' => array( '1', 'yes', 'y', 'true', 'on', 'x', 'checked', 'agreed' ),
		'no'  => array( '0', 'no', 'n', 'false', 'off', 'unchecked' ),
	);
}

/**
 * Read a date in any shape an import source writes one.
 *
 * Takes Y-m-d (with or without a time), Y/m/d, a slash date in the order the
 * source says (US month first by default), a Unix time of ten digits, and any
 * written date PHP can read ("July 4, 1990"). A date with no year can't be
 * one, so it fails.
 *
 * @param mixed  $raw   Value.
 * @param string $order 'mdy' or 'dmy' for slash dates.
 * @return string Y-m-d, or '' when it isn't a date.
 */
function npmp_import_extra_parse_date( $raw, $order = 'mdy' ) {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $raw ) {
		return '';
	}
	$ymd = static function ( $y, $m, $d ) {
		$y = (int) $y;
		$m = (int) $m;
		$d = (int) $d;
		return ( $y >= 1000 && checkdate( $m, $d, $y ) ) ? sprintf( '%04d-%02d-%02d', $y, $m, $d ) : '';
	};
	if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[T ].*)?$/', $raw, $m ) ) {
		return $ymd( $m[1], $m[2], $m[3] );
	}
	if ( preg_match( '/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})(?:[T ].*)?$/', $raw, $m ) ) {
		return 'dmy' === $order ? $ymd( $m[3], $m[2], $m[1] ) : $ymd( $m[3], $m[1], $m[2] );
	}
	if ( preg_match( '/^\d{10}$/', $raw ) ) {
		return gmdate( 'Y-m-d', (int) $raw );
	}
	if ( ! preg_match( '/[a-z]/i', $raw ) || ! preg_match( '/\d{4}/', $raw ) ) {
		return '';
	}
	$time = strtotime( $raw . ' UTC' );
	return false === $time ? '' : gmdate( 'Y-m-d', $time );
}

/**
 * Guess a field type from a column's sample values.
 *
 * Every sample has to fit, so one stray word keeps a column as text. A
 * column of zip codes or phone numbers with leading zeros stays text too.
 *
 * @param array $samples Values.
 * @return string One of npmp_import_extra_types().
 */
function npmp_import_extra_guess_type( array $samples ) {
	$values = array();
	foreach ( $samples as $value ) {
		if ( is_array( $value ) ) {
			return 'checkbox';
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' !== $value ) {
			$values[] = $value;
		}
	}
	if ( ! $values ) {
		return 'text';
	}
	$all = static function ( $test ) use ( $values ) {
		foreach ( $values as $value ) {
			if ( ! $test( $value ) ) {
				return false;
			}
		}
		return true;
	};
	foreach ( $values as $value ) {
		if ( false !== strpos( $value, "\n" ) || strlen( $value ) > 200 ) {
			return 'textarea';
		}
	}
	$words = npmp_import_extra_bool_words();
	$bools = array_merge( $words['yes'], $words['no'] );
	if ( $all(
		static function ( $v ) use ( $bools ) {
			return in_array( strtolower( $v ), $bools, true );
		}
	) ) {
		return 'checkbox';
	}
	if ( $all(
		static function ( $v ) {
			return (bool) preg_match( '/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v );
		}
	) ) {
		return 'number';
	}
	if ( $all(
		static function ( $v ) {
			return (bool) preg_match( '/^(\d{4}[-\/]\d{1,2}[-\/]\d{1,2}([T ][\d:.Z+-]+)?|\d{1,2}\/\d{1,2}\/\d{4})$/', $v ) && '' !== npmp_import_extra_parse_date( $v );
		}
	) ) {
		return 'date';
	}
	if ( $all(
		static function ( $v ) {
			return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $v );
		}
	) ) {
		return 'email';
	}
	if ( $all(
		static function ( $v ) {
			return (bool) preg_match( '#^https?://\S+$#i', $v );
		}
	) ) {
		return 'url';
	}
	return 'text';
}

/**
 * Match a choice against a field's options, ignoring letter case.
 *
 * @param string   $value   Choice.
 * @param string[] $options Options.
 * @return string The option as the field spells it, or ''.
 */
function npmp_import_extra_match_option( $value, array $options ) {
	foreach ( $options as $option ) {
		if ( 0 === strcasecmp( trim( (string) $option ), $value ) ) {
			return (string) $option;
		}
	}
	return '';
}

/**
 * Turn one imported value into what a custom field of this type stores.
 *
 * A dropdown or checkbox field this import created ($grow) takes new choices
 * as they appear, up to NPMP_IMPORT_EXTRA_MAX_OPTIONS. A field the owner
 * already had keeps its choices, and a value outside them is bad.
 *
 * @param mixed  $raw     Value from the source (a string, a list for multi-choice, or a bool).
 * @param string $type    Field type.
 * @param array  $options Field options (dropdown, checkbox).
 * @param bool   $grow    The import created this field, so new choices join it.
 * @param array  $hints   date_order ('mdy' or 'dmy').
 * @return array{status:string,value:string,options:string[]} status ok, empty, or bad.
 *               options holds the field's options after any new ones.
 */
function npmp_import_extra_normalize( $raw, $type, array $options = array(), $grow = false, array $hints = array() ) {
	$out = array(
		'status'  => 'empty',
		'value'   => '',
		'options' => array_values( array_map( 'strval', $options ) ),
	);
	if ( is_bool( $raw ) ) {
		$raw = $raw ? '1' : '0';
	}
	$parts = array();
	if ( is_array( $raw ) ) {
		foreach ( $raw as $piece ) {
			if ( is_scalar( $piece ) && '' !== trim( (string) $piece ) ) {
				$parts[] = trim( (string) $piece );
			}
		}
		$raw = implode( ', ', $parts );
	} elseif ( ! is_scalar( $raw ) ) {
		$raw = '';
	}
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return $out;
	}
	$bad = static function () use ( $out ) {
		$out['status'] = 'bad';
		return $out;
	};
	$ok = static function ( $value, $opts = null ) use ( $out ) {
		$out['status'] = 'ok';
		$out['value']  = (string) $value;
		if ( null !== $opts ) {
			$out['options'] = array_values( $opts );
		}
		return $out;
	};

	switch ( $type ) {
		case 'textarea':
			return $ok( sanitize_textarea_field( $raw ) );

		case 'number':
			$clean = str_replace( array( ',', ' ', '$', "\u{20AC}", "\u{00A3}" ), '', $raw );
			return is_numeric( $clean ) ? $ok( (string) ( $clean + 0 ) ) : $bad();

		case 'email':
			$email = sanitize_email( $raw );
			return ( '' !== $email && is_email( $email ) ) ? $ok( $email ) : $bad();

		case 'url':
			$url = $raw;
			if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) && preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i', $url ) ) {
				$url = 'https://' . $url;
			}
			if ( ! preg_match( '#^https?://#i', $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return $bad();
			}
			return $ok( esc_url_raw( $url ) );

		case 'date':
			$date = npmp_import_extra_parse_date( $raw, isset( $hints['date_order'] ) && 'dmy' === $hints['date_order'] ? 'dmy' : 'mdy' );
			return '' === $date ? $bad() : $ok( $date );

		case 'checkbox':
			// A checkbox with no choices is one yes-or-no box. Choices only grow on a list that has some.
			if ( ! $options ) {
				$words = npmp_import_extra_bool_words();
				$low   = strtolower( $raw );
				if ( in_array( $low, $words['yes'], true ) ) {
					return $ok( '1' );
				}
				if ( in_array( $low, $words['no'], true ) ) {
					return $out; // An unticked box is empty, and empty never clears a field.
				}
				return $bad();
			}
			$pieces = $parts ? $parts : array_filter( array_map( 'trim', preg_split( '/[,;|]/', $raw ) ), 'strlen' );
			$opts   = $out['options'];
			$picked = array();
			foreach ( $pieces as $piece ) {
				$piece = sanitize_text_field( $piece );
				$match = npmp_import_extra_match_option( $piece, $opts );
				if ( '' === $match ) {
					if ( ! $grow || count( $opts ) >= NPMP_IMPORT_EXTRA_MAX_OPTIONS || '' === $piece ) {
						return $bad();
					}
					$opts[] = $piece;
					$match  = $piece;
				}
				$picked[ strtolower( $match ) ] = $match;
			}
			return $picked ? $ok( implode( ', ', $picked ), $opts ) : $out;

		case 'dropdown':
			$value = sanitize_text_field( $raw );
			$match = npmp_import_extra_match_option( $value, $out['options'] );
			if ( '' !== $match ) {
				return $ok( $match );
			}
			if ( ! $grow || count( $out['options'] ) >= NPMP_IMPORT_EXTRA_MAX_OPTIONS ) {
				return $bad();
			}
			$opts   = $out['options'];
			$opts[] = $value;
			return $ok( $value, $opts );

		default:
			return $ok( sanitize_text_field( $raw ) );
	}
}

/**
 * Make a plan from what the owner picked: key => target.
 *
 * A target is a field ID the owner already has, or 'new' to create one from
 * the source's label and type. Anything else, and any key the source didn't
 * offer, drops out.
 *
 * @param array $offered key => array{label,type,options} the source offered.
 * @param array $picked  key => target.
 * @param array $types   key => type the owner chose for a new field.
 * @param array $targets Field ID => array{label,type,options} (Pro's fields).
 * @return array key => array{field:string}|array{create:array{label:string,type:string,options:array}}
 */
function npmp_import_extra_build_plan( array $offered, array $picked, array $types, array $targets ) {
	$plan = array();
	foreach ( $picked as $key => $target ) {
		$key    = (string) $key;
		$target = is_scalar( $target ) ? (string) $target : '';
		if ( ! isset( $offered[ $key ] ) || '' === $target ) {
			continue;
		}
		if ( 'new' === $target ) {
			$type = isset( $types[ $key ] ) && in_array( $types[ $key ], npmp_import_extra_types(), true ) ? $types[ $key ] : (string) ( $offered[ $key ]['type'] ?? 'text' );
			$type = in_array( $type, npmp_import_extra_types(), true ) ? $type : 'text';
			$plan[ $key ] = array(
				'create' => array(
					'label'   => (string) ( $offered[ $key ]['label'] ?? $key ),
					'type'    => $type,
					'options' => in_array( $type, array( 'dropdown', 'checkbox' ), true ) ? array_values( array_map( 'strval', (array) ( $offered[ $key ]['options'] ?? array() ) ) ) : array(),
				),
			);
		} elseif ( isset( $targets[ $target ] ) ) {
			$plan[ $key ] = array( 'field' => $target );
		}
	}
	return $plan;
}

/**
 * Add one write result to running totals.
 *
 * @param array $totals written, unchanged, skipped (field ID => count).
 * @param array $result The same shape.
 * @return array
 */
function npmp_import_extra_add_counts( array $totals, array $result ) {
	$totals = array_merge(
		array(
			'written'   => 0,
			'unchanged' => 0,
			'skipped'   => array(),
		),
		$totals
	);
	$totals['written']   += (int) ( $result['written'] ?? 0 );
	$totals['unchanged'] += (int) ( $result['unchanged'] ?? 0 );
	foreach ( (array) ( $result['skipped'] ?? array() ) as $field => $n ) {
		$totals['skipped'][ (string) $field ] = (int) ( $totals['skipped'][ (string) $field ] ?? 0 ) + (int) $n;
	}
	return $totals;
}

/**
 * Pull `cf:<field ID>` values out of a mapped record.
 *
 * @param array $record Mapped row.
 * @return array<string,mixed> Field ID => value.
 */
function npmp_import_extra_values_from_record( array $record ) {
	$out = array();
	foreach ( $record as $key => $value ) {
		if ( is_string( $key ) && 0 === strpos( $key, 'cf:' ) && strlen( $key ) > 3 ) {
			$out[ substr( $key, 3 ) ] = $value;
		}
	}
	return $out;
}

// =====================================================================
// Pro's side, asked through filters
// =====================================================================

/**
 * Can imports write custom fields here? True with Pro's custom fields.
 *
 * @return bool
 */
function npmp_import_extra_fields_supported() {
	return (bool) apply_filters( 'npmp_import_extra_fields_supported', false );
}

/**
 * The contact custom fields an import can write to.
 *
 * @return array<string,array{label:string,type:string,options:array}>
 */
function npmp_import_extra_targets() {
	if ( ! npmp_import_extra_fields_supported() ) {
		return array();
	}
	$targets = apply_filters( 'npmp_import_extra_field_targets', array() );
	return is_array( $targets ) ? $targets : array();
}

/**
 * Create the plan's new fields (Pro) and return key => field ID.
 *
 * @param array $plan npmp_import_extra_build_plan() output.
 * @return array{fields:array<string,string>,created:string[]} created lists
 *               the field IDs this import made, which take new choices.
 */
function npmp_import_extra_prepare( array $plan ) {
	$out = array(
		'fields'  => array(),
		'created' => array(),
	);
	if ( ! $plan || ! npmp_import_extra_fields_supported() ) {
		return $out;
	}
	$ready = apply_filters( 'npmp_import_extra_fields_prepare', $plan );
	foreach ( is_array( $ready ) ? $ready : array() as $key => $row ) {
		if ( ! empty( $row['field'] ) && is_string( $row['field'] ) ) {
			$out['fields'][ (string) $key ] = $row['field'];
			if ( ! empty( $row['created'] ) ) {
				$out['created'][] = $row['field'];
			}
		}
	}
	$out['created'] = array_values( array_unique( $out['created'] ) );
	return $out;
}

/**
 * Write one contact's values through Pro.
 *
 * @param int   $member_id Contact ID.
 * @param array $values    Field ID => raw value.
 * @param array $context   grow (field IDs that take new choices), hints (field ID => hints), source.
 * @return array{written:int,unchanged:int,skipped:array<string,int>}
 */
function npmp_import_extra_write( $member_id, array $values, array $context = array() ) {
	$empty = array(
		'written'   => 0,
		'unchanged' => 0,
		'skipped'   => array(),
	);
	if ( ! $values || (int) $member_id < 1 || ! npmp_import_extra_fields_supported() ) {
		return $empty;
	}
	$result = apply_filters( 'npmp_import_extra_fields_write', $empty, (int) $member_id, $values, $context );
	return is_array( $result ) ? npmp_import_extra_add_counts( $empty, $result ) : $empty;
}

// =====================================================================
// Running totals for one request, and saved totals for staged sources
// =====================================================================

/**
 * This request's custom field counts.
 *
 * @param array|null $add   A result to add.
 * @param bool       $reset Hand back the totals and start over.
 * @return array
 */
function npmp_import_extra_request_counts( $add = null, $reset = false ) {
	static $totals = array();
	if ( is_array( $add ) ) {
		$totals = npmp_import_extra_add_counts( $totals, $add );
	}
	$now = npmp_import_extra_add_counts( array(), $totals );
	if ( $reset ) {
		$totals = array();
	}
	return $now;
}

/**
 * Saved counts for a staged source's last run.
 *
 * @param string $source Source key.
 * @return array
 */
function npmp_import_extra_saved_counts( $source ) {
	$saved = get_option( 'npmp_import_extra_counts_' . sanitize_key( $source ), array() );
	return npmp_import_extra_add_counts( array(), is_array( $saved ) ? $saved : array() );
}

/**
 * Forget a staged source's counts, at the start of a run.
 *
 * @param string $source Source key.
 * @return void
 */
function npmp_import_extra_reset_counts( $source ) {
	delete_option( 'npmp_import_extra_counts_' . sanitize_key( $source ) );
}

// =====================================================================
// The listener: every imported row
// =====================================================================

add_action( 'npmp_import_row_imported', 'npmp_import_extra_on_row', 20, 6 );

/**
 * Write a row's custom field values after the contact write.
 *
 * Wizard rows carry `cf:<field ID>` keys in the record, with the fields this
 * job created in $options['extra_grow']. Staged rows carry the source's own
 * subscriber ID, and the source's reader fetches the ticked fields. A row the
 * import skipped as a duplicate stays as it was.
 *
 * @param int    $member_id Contact ID.
 * @param array  $record    Mapped row.
 * @param array  $row       Source row.
 * @param array  $mapping   Mapping.
 * @param string $status    imported, updated, or skipped.
 * @param array  $options   Import options.
 * @return void
 */
function npmp_import_extra_on_row( $member_id, $record = array(), $row = array(), $mapping = array(), $status = '', $options = array() ) {
	if ( 'skipped' === $status || ! npmp_import_extra_fields_supported() ) {
		return;
	}
	$record  = is_array( $record ) ? $record : array();
	$options = is_array( $options ) ? $options : array();
	$values  = npmp_import_extra_values_from_record( $record );
	$context = array(
		'grow'   => array_map( 'strval', (array) ( $options['extra_grow'] ?? array() ) ),
		'hints'  => (array) ( $options['extra_hints'] ?? array() ),
		'source' => (string) ( $options['source'] ?? '' ),
	);

	$staged = '';
	$source = sanitize_key( (string) ( $options['source'] ?? '' ) );
	// Only the shared staged writer passes the old plugin's own subscriber ID.
	if ( ! $values && '' !== $source && is_array( $row ) && ( isset( $row['mailpoet_id'] ) || isset( $row['source_id'] ) ) ) {
		$sources = npmp_import_extra_sources();
		$plan    = npmp_import_extra_staged_plan( $source );
		$id      = (int) ( $row['mailpoet_id'] ?? ( $row['source_id'] ?? 0 ) );
		if ( isset( $sources[ $source ] ) && $plan['fields'] && $id > 0 ) {
			$raw = call_user_func( $sources[ $source ]['values'], $id, array_keys( $plan['fields'] ) );
			foreach ( (array) $raw as $key => $value ) {
				if ( isset( $plan['fields'][ $key ] ) ) {
					$values[ $plan['fields'][ $key ] ] = $value;
				}
			}
			$context['grow']  = $plan['created'];
			$context['hints'] = $plan['hints'];
			$staged           = $source;
		}
	}
	if ( ! $values ) {
		return;
	}
	$result = npmp_import_extra_write( (int) $member_id, $values, $context );
	npmp_import_extra_request_counts( $result );
	if ( '' !== $staged ) {
		npmp_import_extra_pending_staged( $staged, $result );
	}
}

/**
 * Add to a staged source's saved counts once, when the request ends.
 *
 * @param string $source Source key.
 * @param array  $result Counts to add.
 * @return void
 */
function npmp_import_extra_pending_staged( $source, array $result ) {
	static $pending = array();
	static $hooked  = false;
	$pending[ $source ] = npmp_import_extra_add_counts( $pending[ $source ] ?? array(), $result );
	if ( ! $hooked ) {
		$hooked = true;
		add_action(
			'shutdown',
			static function () use ( &$pending ) {
				foreach ( $pending as $key => $counts ) {
					update_option( 'npmp_import_extra_counts_' . $key, npmp_import_extra_add_counts( npmp_import_extra_saved_counts( $key ), $counts ), false );
				}
				$pending = array();
			}
		);
	}
}

// =====================================================================
// Staged sources: MailPoet, FluentCRM
// =====================================================================

/**
 * Staged sources that offer extra fields.
 *
 * Each entry: name, fields (callable returning key => array{label,type,options,
 * samples,hints,note}), values (callable taking the source's subscriber ID and
 * the wanted keys, returning key => raw value).
 *
 * @return array<string,array>
 */
function npmp_import_extra_sources() {
	$sources = apply_filters( 'npmp_import_extra_field_sources', array() );
	$out     = array();
	foreach ( is_array( $sources ) ? $sources : array() as $key => $source ) {
		if ( is_array( $source ) && isset( $source['fields'], $source['values'] ) && is_callable( $source['fields'] ) && is_callable( $source['values'] ) ) {
			$out[ sanitize_key( (string) $key ) ] = array_merge( array( 'name' => (string) $key ), $source );
		}
	}
	return $out;
}

/**
 * The fields a staged source offers right now.
 *
 * @param string $source Source key.
 * @return array
 */
function npmp_import_extra_offered( $source ) {
	$sources = npmp_import_extra_sources();
	if ( ! isset( $sources[ $source ] ) ) {
		return array();
	}
	$fields = call_user_func( $sources[ $source ]['fields'] );
	return is_array( $fields ) ? $fields : array();
}

/**
 * The saved plan for a staged source, with fields created.
 *
 * The owner's picks are saved on the review screen. The fields themselves
 * are made on the first imported row, so going back to review creates
 * nothing.
 *
 * @param string $source Source key.
 * @return array{fields:array<string,string>,created:string[],hints:array}
 */
function npmp_import_extra_staged_plan( $source ) {
	static $ready = array();
	$source = sanitize_key( $source );
	if ( isset( $ready[ $source ] ) ) {
		return $ready[ $source ];
	}
	$saved = get_option( 'npmp_import_extra_plan_' . $source, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$plan  = (array) ( $saved['plan'] ?? array() );
	$out   = array(
		'fields'  => array(),
		'created' => array_values( array_map( 'strval', (array) ( $saved['created'] ?? array() ) ) ),
		'hints'   => array(),
	);
	if ( $plan && npmp_import_extra_fields_supported() ) {
		$made = npmp_import_extra_prepare( $plan );
		foreach ( $made['fields'] as $key => $field ) {
			$out['fields'][ $key ] = $field;
			// A field made on an earlier run is a 'field' now, so it keeps taking choices.
			$plan[ $key ] = array( 'field' => $field );
			if ( ! empty( $saved['hints'][ $key ] ) ) {
				$out['hints'][ $field ] = (array) $saved['hints'][ $key ];
			}
		}
		$out['created'] = array_values( array_unique( array_merge( $out['created'], $made['created'] ) ) );
		if ( $made['created'] ) {
			$saved['plan']    = $plan;
			$saved['created'] = $out['created'];
			update_option( 'npmp_import_extra_plan_' . $source, $saved, false );
		}
	}
	$ready[ $source ] = $out;
	return $out;
}

add_action( 'npmp_import_extra_fields_choices', 'npmp_import_extra_render_choices', 10, 1 );
add_action( 'npmp_import_extra_fields_save', 'npmp_import_extra_save_choices', 10, 1 );
add_action( 'npmp_import_extra_fields_results', 'npmp_import_extra_render_results', 10, 1 );

/**
 * The checklist of custom fields on a staged source's review screen.
 *
 * With Pro each field gets a box and a menu: a new custom field (named and
 * typed as shown) or one you already have. Without Pro the list is greyed
 * with the upgrade badge, and nothing comes over.
 *
 * @param string $source Source key.
 * @return void
 */
function npmp_import_extra_render_choices( $source ) {
	$source  = sanitize_key( (string) $source );
	$offered = npmp_import_extra_offered( $source );
	if ( ! $offered ) {
		return;
	}
	$sources   = npmp_import_extra_sources();
	$name      = $sources[ $source ]['name'] ?? $source;
	$supported = npmp_import_extra_fields_supported();
	$targets   = npmp_import_extra_targets();
	$labels    = npmp_import_extra_type_labels();
	$saved     = get_option( 'npmp_import_extra_plan_' . $source, array() );
	$picked    = is_array( $saved ) ? (array) ( $saved['picked'] ?? array() ) : array();

	echo '<h2>' . esc_html__( 'Custom fields', 'nonprofit-manager' ) . '</h2>';
	if ( ! $supported ) {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %1$s: plugin name, such as MailPoet. */
				__( 'You have custom fields in %1$s. With Pro, you can turn each one you tick into a custom field on your contacts. Without Pro, we leave those fields in %1$s.', 'nonprofit-manager' ),
				$name
			)
		) . ' ' . npmp_import_extra_pro_badge() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
	} else {
		echo '<p>' . esc_html__( 'Tick each field you want to bring over and pick where it goes. We give a new field the name and type shown and make the field when the import starts. We skip and count any value that doesn\'t fit its field.', 'nonprofit-manager' ) . '</p>';
	}
	echo '<div class="npmp-tec-table-wrap"><table class="widefat striped npmp-tec-table npmp-extra-fields"><thead><tr>';
	echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Bring over', 'nonprofit-manager' ) . '</span></td>';
	echo '<th scope="col">' . esc_html__( 'Field', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Examples', 'nonprofit-manager' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Goes to', 'nonprofit-manager' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $offered as $key => $field ) {
		$key     = (string) $key;
		$type    = in_array( $field['type'] ?? '', npmp_import_extra_types(), true ) ? $field['type'] : 'text';
		$current = (string) ( $picked[ $key ] ?? '' );
		$on      = $supported && '' !== $current;
		$samples = array_slice( array_filter( array_map( 'strval', (array) ( $field['samples'] ?? array() ) ), 'strlen' ), 0, 3 );
		$box_id  = 'npmp-extra-' . md5( $source . $key );
		echo '<tr><th scope="row" class="check-column">';
		printf(
			'<input type="checkbox" id="%1$s" name="npmp_extra_on[]" value="%2$s"%3$s%4$s>',
			esc_attr( $box_id ),
			esc_attr( $key ),
			checked( $on, true, false ),
			disabled( $supported, false, false )
		);
		echo '</th><td><label for="' . esc_attr( $box_id ) . '"><strong>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</strong></label>';
		if ( ! empty( $field['note'] ) ) {
			echo '<br><span class="description">' . esc_html( (string) $field['note'] ) . '</span>';
		}
		echo '</td><td>' . esc_html( $samples ? implode( ', ', $samples ) : '-' ) . '</td><td>';
		printf( '<select name="npmp_extra_target[%1$s]" aria-label="%2$s"%3$s>', esc_attr( $key ), esc_attr( sprintf( /* translators: %s: field name. */ __( 'Where %s goes', 'nonprofit-manager' ), (string) ( $field['label'] ?? $key ) ) ), disabled( $supported, false, false ) );
		printf(
			'<option value="new"%1$s>%2$s</option>',
			selected( '' === $current || 'new' === $current, true, false ),
			esc_html(
				sprintf(
					/* translators: 1: field name, 2: field type, such as Date. */
					__( 'New field: %1$s (%2$s)', 'nonprofit-manager' ),
					(string) ( $field['label'] ?? $key ),
					$labels[ $type ]
				)
			)
		);
		foreach ( $targets as $id => $target ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $id ),
				selected( $current, (string) $id, false ),
				esc_html( sprintf( /* translators: 1: field name, 2: field type. */ __( 'Your field: %1$s (%2$s)', 'nonprofit-manager' ), (string) $target['label'], $labels[ $target['type'] ] ?? $target['type'] ) )
			);
		}
		echo '</select>';
		printf( '<input type="hidden" name="npmp_extra_type[%1$s]" value="%2$s">', esc_attr( $key ), esc_attr( $type ) );
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * Save a staged source's custom field picks from the review form.
 *
 * Reads only what the source offers. Nothing is created here.
 *
 * @param string $source Source key.
 * @return void
 */
function npmp_import_extra_save_choices( $source ) {
	$source = sanitize_key( (string) $source );
	npmp_import_extra_reset_counts( $source );
	$offered = npmp_import_extra_offered( $source );
	if ( ! $offered || ! npmp_import_extra_fields_supported() ) {
		delete_option( 'npmp_import_extra_plan_' . $source );
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller checked the form's nonce.
	$on      = array_map( 'strval', (array) wp_unslash( $_POST['npmp_extra_on'] ?? array() ) );
	$targets = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['npmp_extra_target'] ?? array() ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$picked = array();
	$types  = array();
	$hints  = array();
	foreach ( $on as $key ) {
		if ( isset( $offered[ $key ] ) ) {
			$picked[ $key ] = isset( $targets[ $key ] ) && '' !== $targets[ $key ] ? sanitize_key( $targets[ $key ] ) : 'new';
			$types[ $key ]  = (string) ( $offered[ $key ]['type'] ?? 'text' );
			if ( ! empty( $offered[ $key ]['hints'] ) ) {
				$hints[ $key ] = (array) $offered[ $key ]['hints'];
			}
		}
	}
	$plan = npmp_import_extra_build_plan( $offered, $picked, $types, npmp_import_extra_targets() );
	update_option(
		'npmp_import_extra_plan_' . $source,
		array(
			'picked'  => $picked,
			'plan'    => $plan,
			'hints'   => $hints,
			'created' => array(),
		),
		false
	);
}

/**
 * Lines for the results screen: values saved, and bad ones per field.
 *
 * @param array $counts npmp_import_extra_add_counts() shape.
 * @return string[]
 */
function npmp_import_extra_result_lines( array $counts ) {
	$counts  = npmp_import_extra_add_counts( array(), $counts );
	$lines   = array();
	$targets = npmp_import_extra_targets();
	if ( $counts['written'] ) {
		$lines[] = sprintf(
			/* translators: %s: number of values. */
			_n( 'Custom field values saved: %s', 'Custom field values saved: %s', $counts['written'], 'nonprofit-manager' ),
			number_format_i18n( $counts['written'] )
		);
	}
	foreach ( $counts['skipped'] as $field => $n ) {
		$lines[] = sprintf(
			/* translators: 1: number of values, 2: field name. */
			_n( '%1$s value skipped for %2$s, since it doesn\'t fit the field', '%1$s values skipped for %2$s, since they don\'t fit the field', (int) $n, 'nonprofit-manager' ),
			number_format_i18n( (int) $n ),
			isset( $targets[ $field ] ) ? $targets[ $field ]['label'] : $field
		);
	}
	return $lines;
}

/**
 * The custom field lines on a staged source's results screen.
 *
 * @param string $source Source key.
 * @return void
 */
function npmp_import_extra_render_results( $source ) {
	$lines = npmp_import_extra_result_lines( npmp_import_extra_saved_counts( sanitize_key( (string) $source ) ) );
	if ( ! $lines ) {
		return;
	}
	echo '<ul class="ul-disc npmp-extra-results">';
	foreach ( $lines as $line ) {
		echo '<li>' . esc_html( $line ) . '</li>';
	}
	echo '</ul>';
}

/**
 * The Pro badge for custom fields on the import screens, or nothing when the
 * helper isn't loaded.
 *
 * @return string HTML.
 */
function npmp_import_extra_pro_badge() {
	if ( ! function_exists( 'npmp_pro_lock_badge' ) || ! function_exists( 'npmp_get_upgrade_url' ) ) {
		return '';
	}
	return npmp_pro_lock_badge( npmp_get_upgrade_url( 'import_custom_fields' ), __( 'Custom fields come with Nonprofit Manager Pro', 'nonprofit-manager' ) );
}

// =====================================================================
// The import wizard
// =====================================================================

/**
 * Describe the wizard's extra columns for the mapping screen: every column
 * the auto-map left empty, with a type guessed from the preview rows.
 *
 * @param array $headers Column headers.
 * @param array $rows    Preview rows (cells by column).
 * @param array $map     Column => auto-mapped field.
 * @param array $known   Column => array{type,options} a source knows (Mailchimp merge field types).
 * @return array<int,array{label:string,type:string,options:array}>
 */
function npmp_import_extra_wizard_columns( array $headers, array $rows, array $map, array $known = array() ) {
	$out = array();
	foreach ( $headers as $i => $header ) {
		if ( '' !== (string) ( $map[ $i ] ?? '' ) ) {
			continue;
		}
		$label = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $known[ $i ]['label'] ?? $header ) ) ) );
		if ( '' === $label ) {
			continue;
		}
		$samples = array();
		foreach ( $rows as $row ) {
			$samples[] = $row[ $i ] ?? '';
		}
		$type = isset( $known[ $i ]['type'] ) && in_array( $known[ $i ]['type'], npmp_import_extra_types(), true ) ? $known[ $i ]['type'] : npmp_import_extra_guess_type( $samples );
		$out[ $i ] = array(
			'label'   => $label,
			'type'    => $type,
			'options' => array_values( array_map( 'strval', (array) ( $known[ $i ]['options'] ?? array() ) ) ),
		);
	}
	return $out;
}

/**
 * Everything the mapping screen needs to offer custom fields.
 *
 * @param array $columns npmp_import_extra_wizard_columns() output.
 * @return array{supported:bool,targets:array,columns:array,types:array,badge:string}
 */
function npmp_import_extra_wizard_payload( array $columns ) {
	$supported = npmp_import_extra_fields_supported();
	return array(
		'supported' => $supported,
		'targets'   => $supported ? npmp_import_extra_targets() : array(),
		'columns'   => (object) $columns,
		'types'     => npmp_import_extra_type_labels(),
		'badge'     => $supported ? '' : npmp_import_extra_pro_badge(),
	);
}

/**
 * Turn a job's `cf_new` columns into fields and the mapping into `cf:<ID>`.
 *
 * Runs once, when the first step of a job starts. Without Pro every custom
 * field column drops out of the mapping, so nothing is written.
 *
 * @param array $mapping Column => field.
 * @param array $labels  Column => header (from the browser, the owner's own words).
 * @param array $types   Column => type for a new field.
 * @param array $known   Column => array{options,hints} the source knows.
 * @return array{mapping:array,grow:string[],hints:array} hints is field ID => hints.
 */
function npmp_import_extra_prepare_mapping( array $mapping, array $labels, array $types, array $known = array() ) {
	$supported = npmp_import_extra_fields_supported();
	$targets   = $supported ? npmp_import_extra_targets() : array();
	$offered   = array();
	$picked    = array();
	foreach ( $mapping as $col => $field ) {
		$field = (string) $field;
		if ( 'cf_new' === $field ) {
			$offered[ (string) $col ] = array(
				'label'   => sanitize_text_field( (string) ( $labels[ $col ] ?? '' ) ),
				'type'    => (string) ( $types[ $col ] ?? 'text' ),
				'options' => (array) ( $known[ $col ]['options'] ?? array() ),
			);
			if ( '' === $offered[ (string) $col ]['label'] ) {
				/* translators: %s: column number. */
				$offered[ (string) $col ]['label'] = sprintf( __( 'Column %s', 'nonprofit-manager' ), (int) $col + 1 );
			}
			$picked[ (string) $col ] = 'new';
		} elseif ( 0 === strpos( $field, 'cf:' ) ) {
			$offered[ (string) $col ] = array( 'label' => '' );
			$picked[ (string) $col ]  = substr( $field, 3 );
		}
	}
	$made  = npmp_import_extra_prepare( npmp_import_extra_build_plan( $offered, $picked, $types, $targets ) );
	$hints = array();
	foreach ( $mapping as $col => $field ) {
		$field = (string) $field;
		if ( 'cf_new' === $field || 0 === strpos( $field, 'cf:' ) ) {
			$mapping[ $col ] = isset( $made['fields'][ (string) $col ] ) ? 'cf:' . $made['fields'][ (string) $col ] : '';
			if ( '' !== $mapping[ $col ] && ! empty( $known[ $col ]['hints'] ) ) {
				$hints[ $made['fields'][ (string) $col ] ] = (array) $known[ $col ]['hints'];
			}
		}
	}
	return array(
		'mapping' => $mapping,
		'grow'    => $made['created'],
		'hints'   => $hints,
	);
}

/**
 * Start a wizard job's custom fields: read the owner's new-field names and
 * types from the request, create the fields, and fold the plan into the job.
 *
 * @param array $mapping Column => field from the request.
 * @param array $options Job options.
 * @param array $known   Column => array{type,options,hints} the source knows.
 * @return array{0:array,1:array} The mapping and options to store.
 */
function npmp_import_extra_start_job( array $mapping, array $options, array $known = array() ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- The step handler checked the nonce.
	$labels = isset( $_POST['extra_label'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['extra_label'] ) ) : array();
	$types  = isset( $_POST['extra_type'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['extra_type'] ) ) : array();
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$ready                  = npmp_import_extra_prepare_mapping( $mapping, $labels, $types, $known );
	$options['extra_grow']  = $ready['grow'];
	$options['extra_hints'] = $ready['hints'];
	npmp_import_extra_request_counts( null, true );
	return array( $ready['mapping'], $options );
}

/**
 * Fold this request's custom field counts into a wizard job's totals.
 *
 * @param array $totals Job totals.
 * @return array
 */
function npmp_import_extra_merge_job_totals( array $totals ) {
	$now = npmp_import_extra_request_counts( null, true );
	if ( $now['written'] || $now['unchanged'] || $now['skipped'] || isset( $totals['extra'] ) ) {
		$totals['extra'] = npmp_import_extra_add_counts( (array) ( $totals['extra'] ?? array() ), $now );
		$totals['extra_lines'] = npmp_import_extra_result_lines( $totals['extra'] );
	}
	return $totals;
}
