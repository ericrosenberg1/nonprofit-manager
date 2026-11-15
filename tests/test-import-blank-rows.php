<?php
/**
 * Blank lines and runaway XLSX columns in a contact file
 * (includes/import/class-import-manager.php).
 *
 * A blank CSV line came back from fgetcsv() as array( null ), raised a PHP 8.1
 * deprecation in trim(), and was reported as "Row N: Invalid email format".
 * An XLSX cell reference past Excel's last column made the gap filler build an
 * array of hundreds of millions of cells.
 *
 * Run: php tests/test-import-blank-rows.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/import/class-import-manager.php';

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

/**
 * Member manager stand-in: counts lookups and refuses every write.
 */
class NPMP_Test_Blank_Rows_Members {
	public $looked_up = array();
	public function lookup_emails( $emails ) {
		$this->looked_up = array_merge( $this->looked_up, (array) $emails );
	}
	public function get_member_by_email( $email ) {
		return null;
	}
	public function add_member( $data ) {
		return 0;
	}
}

$deprecations = array();
set_error_handler(
	static function ( $no, $str ) use ( &$deprecations ) {
		if ( E_DEPRECATED === $no ) {
			$deprecations[] = $str;
			return true;
		}
		return false;
	}
);

$class   = new ReflectionClass( 'NPMP_Import_Manager' );
$manager = $class->newInstanceWithoutConstructor();
$members = new NPMP_Test_Blank_Rows_Members();
$prop    = $class->getProperty( 'member_manager' );
if ( PHP_VERSION_ID < 80100 ) {
	$prop->setAccessible( true );
}
$prop->setValue( $manager, $members );

echo "Blank rows\n";
check( 'an empty row is blank', true, NPMP_Import_Manager::row_is_blank( array( '', ' ', '' ) ) );
check( 'fgetcsv blank line is blank', true, NPMP_Import_Manager::row_is_blank( array( null ) ) );
check( 'a row with one value is not blank', false, NPMP_Import_Manager::row_is_blank( array( '', 'x' ) ) );
check( 'a row with a zero is not blank', false, NPMP_Import_Manager::row_is_blank( array( '0' ) ) );

$stats = $manager->process_rows(
	array(
		array( '', '', '' ),
		array( '', '' ),
		array( 'not-an-email', 'Ann' ),
	),
	array( 0 => 'email', 1 => 'first_name' )
);
check( 'blank rows make no error', 1, $stats['errors'] );
check( 'only the real row reports, with its own row number', array( 'Row 4: Invalid email format "not-an-email"' ), $stats['error_messages'] );
check( 'blank rows are never looked up', array( 'not-an-email' ), $members->looked_up );

$csv = tempnam( sys_get_temp_dir(), 'npmp-blank' );
file_put_contents( $csv, "Email,Name\n\na@example.org,Ann\n\n" );
$parse = $class->getMethod( 'parse_csv' );
if ( PHP_VERSION_ID < 80100 ) {
	$parse->setAccessible( true );
}
$parsed = $parse->invoke( $manager, $csv );
unlink( $csv );
check( 'a blank CSV line parses to an empty cell', array( '' ), $parsed['rows'][1] );
check( 'no deprecation from a blank line', array(), $deprecations );

echo "\nXLSX columns\n";
if ( class_exists( 'ZipArchive' ) ) {
	$xlsx = tempnam( sys_get_temp_dir(), 'npmp-xlsx' ) . '.xlsx';
	$zip  = new ZipArchive();
	$zip->open( $xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString(
		'xl/worksheets/sheet1.xml',
		'<?xml version="1.0"?><worksheet><sheetData>'
		. '<row r="1"><c r="A1" t="inlineStr"><is><t>Email</t></is></c></row>'
		. '<row r="2"><c r="A2" t="inlineStr"><is><t>a@example.org</t></is></c><c r="ZZZZZZ2" t="inlineStr"><is><t>x</t></is></c></row>'
		. '</sheetData></worksheet>'
	);
	$zip->close();
	$parse_xlsx = $class->getMethod( 'parse_xlsx' );
	$parse_xlsx->setAccessible( true );
	$before = memory_get_usage();
	$sheet  = $parse_xlsx->invoke( $manager, $xlsx );
	unlink( $xlsx );
	check( 'a cell past column XFD is dropped', array( 'a@example.org' ), $sheet['rows'][1] ?? null );
	check( 'memory stays small', true, memory_get_usage() - $before < 10 * 1024 * 1024 );
} else {
	echo "  SKIP  ZipArchive is not installed\n";
}

echo "\nFile picker\n";
$screen = (string) file_get_contents( dirname( __DIR__ ) . '/includes/import/admin-import.php' );
// The upload takes .csv and .xlsx only (wp_handle_upload mimes), so an
// offered .xls file failed with WordPress's file type error.
check( 'the picker never offers .xls', 0, preg_match( '/accept[^\n]*\.xls\b/', $screen ) );

restore_error_handler();
printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
