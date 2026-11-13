<?php
/**
 * Extra fields as Pro custom fields: the pure parts
 * (includes/import/extra-fields.php, mailpoet-custom-fields.php, and the
 * Mailchimp merge field types).
 *
 * Run: php tests/test-import-extra-fields.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['npmp_test_filters'] = array();
function add_filter( $hook, $cb ) {
	$GLOBALS['npmp_test_filters'][ $hook ][] = $cb;
	return true;
}
function add_action( $hook, $cb ) {
	return add_filter( $hook, $cb );
}
function apply_filters( $hook, $value ) {
	$args = func_get_args();
	foreach ( $GLOBALS['npmp_test_filters'][ $hook ] ?? array() as $cb ) {
		$args[1] = call_user_func_array( $cb, array_slice( $args, 1 ) );
	}
	return $args[1];
}
function _n( $one, $many, $n ) {
	return 1 === (int) $n ? $one : $many;
}
function number_format_i18n( $n ) {
	return number_format( (float) $n );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $s ) {
	return trim( (string) $s );
}
function is_email( $s ) {
	return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL );
}
function esc_url_raw( $s ) {
	return (string) $s;
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function get_option( $k, $d = false ) {
	return $GLOBALS['npmp_test_options'][ $k ] ?? $d;
}

$root = dirname( __DIR__ );
require_once $root . '/includes/import/extra-fields.php';
require_once $root . '/includes/import/mailpoet-custom-fields.php';

echo "Dates\n";
check( 'Y-m-d', '1990-07-04', npmp_import_extra_parse_date( '1990-07-04' ) );
check( 'Y-m-d with time', '1990-07-04', npmp_import_extra_parse_date( '1990-07-04 00:00:00' ) );
check( 'ISO with T', '2024-01-15', npmp_import_extra_parse_date( '2024-01-15T10:00:00Z' ) );
check( 'US slash', '1990-07-04', npmp_import_extra_parse_date( '07/04/1990' ) );
check( 'day-first slash', '1990-04-07', npmp_import_extra_parse_date( '07/04/1990', 'dmy' ) );
check( 'Y/m/d', '1990-07-04', npmp_import_extra_parse_date( '1990/07/04' ) );
check( 'written date', '1990-07-04', npmp_import_extra_parse_date( 'July 4, 1990' ) );
check( 'unix time', '2021-01-01', npmp_import_extra_parse_date( '1609459200' ) );
check( 'no year fails', '', npmp_import_extra_parse_date( '07/04' ) );
check( 'month 13 fails', '', npmp_import_extra_parse_date( '13/01/2020' ) );
check( 'Feb 30 fails', '', npmp_import_extra_parse_date( '2020-02-30' ) );
check( 'a word fails', '', npmp_import_extra_parse_date( 'soon' ) );

echo "\nType guesses\n";
check( 'empty is text', 'text', npmp_import_extra_guess_type( array( '', ' ' ) ) );
check( 'dates', 'date', npmp_import_extra_guess_type( array( '2020-01-02', '03/04/2021', '' ) ) );
check( 'yes and no', 'checkbox', npmp_import_extra_guess_type( array( 'Yes', 'no', 'Y' ) ) );
check( 'numbers', 'number', npmp_import_extra_guess_type( array( '12', '3.5', '-4' ) ) );
check( 'zip codes stay text', 'text', npmp_import_extra_guess_type( array( '02134', '90210' ) ) );
check( 'emails', 'email', npmp_import_extra_guess_type( array( 'a@b.org', 'c@d.com' ) ) );
check( 'urls', 'url', npmp_import_extra_guess_type( array( 'https://a.org', 'http://b.com/x' ) ) );
check( 'newline is long text', 'textarea', npmp_import_extra_guess_type( array( "one\ntwo" ) ) );
check( 'long is long text', 'textarea', npmp_import_extra_guess_type( array( str_repeat( 'a', 201 ) ) ) );
check( 'one stray word keeps text', 'text', npmp_import_extra_guess_type( array( '12', 'twelve' ) ) );
check( '1 and 0 read as yes-or-no', 'checkbox', npmp_import_extra_guess_type( array( '1', '0' ) ) );

echo "\nNormalize\n";
$n = function ( $raw, $type, $opts = array(), $grow = false, $hints = array() ) {
	$r = npmp_import_extra_normalize( $raw, $type, $opts, $grow, $hints );
	return $r['status'] . ':' . $r['value'];
};
check( 'empty stays empty', 'empty:', $n( '  ', 'text' ) );
check( 'text', 'ok:Hello there', $n( " Hello\tthere ", 'text' ) );
check( 'long text keeps newlines', "ok:a\nb", $n( "a\nb", 'textarea' ) );
check( 'number drops commas and signs', 'ok:1234.5', $n( '$1,234.50', 'number' ) );
check( 'number word is bad', 'bad:', $n( 'many', 'number' ) );
check( 'email', 'ok:a@b.org', $n( 'a@b.org', 'email' ) );
check( 'bad email', 'bad:', $n( 'nope', 'email' ) );
check( 'url gets https', 'ok:https://example.org', $n( 'example.org', 'url' ) );
check( 'javascript url is bad', 'bad:', $n( 'javascript:alert(1)', 'url' ) );
check( 'date', 'ok:1990-07-04', $n( '07/04/1990', 'date' ) );
check( 'date day first by hint', 'ok:1990-04-07', $n( '07/04/1990', 'date', array(), false, array( 'date_order' => 'dmy' ) ) );
check( 'bad date', 'bad:', $n( 'someday', 'date' ) );
check( 'yes box', 'ok:1', $n( 'Yes', 'checkbox' ) );
check( 'true bool', 'ok:1', $n( true, 'checkbox' ) );
check( 'no box is empty', 'empty:', $n( 'no', 'checkbox' ) );
check( 'odd box is bad', 'bad:', $n( 'maybe', 'checkbox' ) );
check( 'box with no choices never grows', 'bad:', $n( 'Red', 'checkbox', array(), true ) );
check( 'dropdown match ignores case', 'ok:Red', $n( 'red', 'dropdown', array( 'Red', 'Blue' ) ) );
check( 'dropdown miss on your field is bad', 'bad:', $n( 'Green', 'dropdown', array( 'Red' ) ) );
$r = npmp_import_extra_normalize( 'Green', 'dropdown', array( 'Red' ), true );
check( 'dropdown grows on a new field', 'ok:Green', $r['status'] . ':' . $r['value'] );
check( 'dropdown grows its options', array( 'Red', 'Green' ), $r['options'] );
check( 'dropdown stops growing at the cap', 'bad', npmp_import_extra_normalize( 'New', 'dropdown', array_map( 'strval', range( 1, NPMP_IMPORT_EXTRA_MAX_OPTIONS ) ), true )['status'] );
check( 'multi choice from text', 'ok:Red, Blue', $n( 'red; blue', 'checkbox', array( 'Red', 'Blue' ) ) );
check( 'multi choice from a list', 'ok:Red, Blue', $n( array( 'Red', 'Blue' ), 'checkbox', array( 'Red', 'Blue' ) ) );
check( 'multi choice miss is bad', 'bad:', $n( 'Red|Pink', 'checkbox', array( 'Red' ) ) );
$r = npmp_import_extra_normalize( 'Red|Pink', 'checkbox', array( 'Red' ), true );
check( 'multi choice grows a new field', array( 'ok:Red, Pink', array( 'Red', 'Pink' ) ), array( $r['status'] . ':' . $r['value'], $r['options'] ) );
check( 'multi choice drops repeats', 'ok:Red', $n( 'Red, red', 'checkbox', array( 'Red' ) ) );

echo "\nPlans\n";
$offered = array(
	'a' => array( 'label' => 'Shirt size', 'type' => 'dropdown', 'options' => array( 'S', 'M' ) ),
	'b' => array( 'label' => 'Birthday', 'type' => 'date' ),
	'c' => array( 'label' => 'Notes', 'type' => 'textarea' ),
);
$targets = array( 'npmp_cf_bday' => array( 'label' => 'Birthday', 'type' => 'date', 'options' => array() ) );
$plan    = npmp_import_extra_build_plan( $offered, array( 'a' => 'new', 'b' => 'npmp_cf_bday', 'c' => 'npmp_cf_gone', 'z' => 'new' ), array( 'a' => 'checkbox' ), $targets );
check( 'new field keeps label and owner type', array( 'label' => 'Shirt size', 'type' => 'checkbox', 'options' => array( 'S', 'M' ) ), $plan['a']['create'] );
check( 'existing field maps', array( 'field' => 'npmp_cf_bday' ), $plan['b'] );
check( 'missing field drops', false, isset( $plan['c'] ) );
check( 'unoffered key drops', false, isset( $plan['z'] ) );
$plan = npmp_import_extra_build_plan( $offered, array( 'c' => 'new' ), array( 'c' => 'evil' ), $targets );
check( 'unknown type falls back to the offered one', 'textarea', $plan['c']['create']['type'] );

echo "\nCounts\n";
$t = npmp_import_extra_add_counts( array(), array( 'written' => 2, 'skipped' => array( 'f' => 1 ) ) );
$t = npmp_import_extra_add_counts( $t, array( 'written' => 1, 'unchanged' => 3, 'skipped' => array( 'f' => 2, 'g' => 1 ) ) );
check( 'counts add', array( 'written' => 3, 'unchanged' => 3, 'skipped' => array( 'f' => 3, 'g' => 1 ) ), $t );
check( 'record values', array( 'npmp_cf_x' => 'A' ), npmp_import_extra_values_from_record( array( 'email' => 'a@b.org', 'cf:npmp_cf_x' => 'A', 'cf:' => 'no' ) ) );

echo "\nWithout Pro\n";
check( 'not supported', false, npmp_import_extra_fields_supported() );
check( 'no targets', array(), npmp_import_extra_targets() );
check( 'prepare makes nothing', array( 'fields' => array(), 'created' => array() ), npmp_import_extra_prepare( array( 'a' => array( 'create' => array( 'label' => 'X', 'type' => 'text' ) ) ) ) );
check( 'write writes nothing', 0, npmp_import_extra_write( 5, array( 'npmp_cf_x' => 'A' ) )['written'] );
$ready = npmp_import_extra_prepare_mapping( array( 0 => 'email', 1 => 'cf_new', 2 => 'cf:npmp_cf_x' ), array( 1 => 'Size' ), array( 1 => 'text' ) );
check( 'custom field columns drop from the mapping', array( 0 => 'email', 1 => '', 2 => '' ), $ready['mapping'] );

echo "\nWith a fake Pro\n";
add_filter( 'npmp_import_extra_fields_supported', function () { return true; } );
add_filter( 'npmp_import_extra_field_targets', function () { return array( 'npmp_cf_x' => array( 'label' => 'X', 'type' => 'text', 'options' => array() ) ); } );
add_filter(
	'npmp_import_extra_fields_prepare',
	function ( $plan ) {
		$out = array();
		foreach ( $plan as $k => $row ) {
			$out[ $k ] = isset( $row['field'] ) ? array( 'field' => $row['field'], 'created' => false ) : array( 'field' => 'npmp_cf_' . strtolower( $row['create']['label'] ), 'created' => true );
		}
		return $out;
	}
);
$ready = npmp_import_extra_prepare_mapping( array( 0 => 'email', 1 => 'cf_new', 2 => 'cf:npmp_cf_x', 3 => 'cf:npmp_cf_gone' ), array( 1 => 'Size' ), array( 1 => 'dropdown' ), array( 1 => array( 'hints' => array( 'date_order' => 'dmy' ) ) ) );
check( 'new column maps to the made field', 'cf:npmp_cf_size', $ready['mapping'][1] );
check( 'your field stays', 'cf:npmp_cf_x', $ready['mapping'][2] );
check( 'a field that is gone drops', '', $ready['mapping'][3] );
check( 'made field grows', array( 'npmp_cf_size' ), $ready['grow'] );
check( 'hints follow the field', array( 'npmp_cf_size' => array( 'date_order' => 'dmy' ) ), $ready['hints'] );
$cols = npmp_import_extra_wizard_columns( array( 'Email', 'Shirt', '' ), array( array( 'a@b.org', 'M', 'x' ) ), array( 0 => 'email' ) );
check( 'unmapped named columns are offered', array( 1 ), array_keys( $cols ) );
check( 'offered column label', 'Shirt', $cols[1]['label'] );

echo "\nMailPoet field shapes\n";
check( 'select', array( 'dropdown', array( 'A', 'B' ) ), array_values( array_slice( npmp_mailpoet_extra_field_shape( 'select', array( 'values' => array( array( 'value' => 'A' ), array( 'value' => 'B' ) ) ) ), 0, 2 ) ) );
check( 'radio', 'dropdown', npmp_mailpoet_extra_field_shape( 'radio', array() )['type'] );
check( 'checkbox', 'checkbox', npmp_mailpoet_extra_field_shape( 'checkbox', array( 'values' => array( array( 'value' => 'I agree' ) ) ) )['type'] );
check( 'checkbox has no choices', array(), npmp_mailpoet_extra_field_shape( 'checkbox', array( 'values' => array( array( 'value' => 'I agree' ) ) ) )['options'] );
check( 'full date', 'date', npmp_mailpoet_extra_field_shape( 'date', array( 'date_type' => 'year_month_day', 'date_format' => 'MM/DD/YYYY' ) )['type'] );
check( 'day-first date hint', array( 'date_order' => 'dmy' ), npmp_mailpoet_extra_field_shape( 'date', array( 'date_type' => 'year_month_day', 'date_format' => 'DD/MM/YYYY' ) )['hints'] );
check( 'month-only date is text', 'text', npmp_mailpoet_extra_field_shape( 'date', array( 'date_type' => 'month' ) )['type'] );
check( 'number text', 'number', npmp_mailpoet_extra_field_shape( 'text', array( 'validate' => 'number' ) )['type'] );
check( 'phone text stays text', 'text', npmp_mailpoet_extra_field_shape( 'text', array( 'validate' => 'phone' ) )['type'] );
check( 'textarea', 'textarea', npmp_mailpoet_extra_field_shape( 'textarea', array() )['type'] );

echo "\nWiring\n";
$mp = file_get_contents( $root . '/includes/import/mailpoet-importer.php' );
foreach ( array( 'choices', 'save', 'results' ) as $hook ) {
	check( "MailPoet fires npmp_import_extra_fields_{$hook}", 1, substr_count( $mp, "do_action( 'npmp_import_extra_fields_{$hook}', 'mailpoet' )" ) );
}
$main = file_get_contents( $root . '/nonprofit-manager.php' );
check( 'extra fields load before the importers', true, strpos( $main, 'extra-fields.php' ) < strpos( $main, 'class-import-manager.php' ) );
$admin = file_get_contents( $root . '/includes/import/admin-import.php' );
check( 'every chunked source folds the counts in (Mailchimp, files, Brevo, Constant Contact)', 4, substr_count( $admin, 'npmp_import_extra_merge_job_totals(' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
