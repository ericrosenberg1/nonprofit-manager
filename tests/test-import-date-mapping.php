<?php
/**
 * The import wizard's column auto-mapping, for the "Added" date field
 * (includes/import/class-import-manager.php).
 *
 * A list moved over from another tool should keep the day each contact first
 * joined. The wizard can only do that if "Added (signup date)" is on the list
 * of fields a column can map to, and if the date column each popular export
 * ships with lands on it by itself.
 *
 * Run: php tests/test-import-date-mapping.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

/** The import manager's constructor asks for the member manager. */
class NPMP_Member_Manager {
	private static $instance;
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

require_once dirname( __DIR__ ) . '/includes/import/class-import-manager.php';

$import = NPMP_Import_Manager::get_instance();
$labels = $import->get_field_labels();

check( 'the Added date is a field a column can map to', true, isset( $labels['created_at'] ) );
check( 'and it says what it is', 'Added (signup date)', $labels['created_at'] );

$maps = function ( $header ) use ( $import ) {
	$mapping = $import->detect_columns( array( $header ) );
	return $mapping[0];
};

// Column headers real exports ship with.
check( 'MailPoet: Confirmation time', 'created_at', $maps( 'Confirmation time' ) );
check( 'Mailchimp: OPTIN_TIME', 'created_at', $maps( 'OPTIN_TIME' ) );
check( 'Mailchimp: CONFIRM_TIME', 'created_at', $maps( 'CONFIRM_TIME' ) );
check( 'Mailchimp API: timestamp_opt', 'created_at', $maps( 'timestamp_opt' ) );
check( 'a spreadsheet: Date Added', 'created_at', $maps( 'Date Added' ) );
check( 'a spreadsheet: Signup Date', 'created_at', $maps( 'Signup Date' ) );
check( 'a spreadsheet: Member Since', 'created_at', $maps( 'Member Since' ) );
check( 'punctuation is ignored: Opt-in Date', 'created_at', $maps( 'Opt-in Date' ) );
check( 'our own wording: Added', 'created_at', $maps( 'Added' ) );
check( 'our own wording: First added', 'created_at', $maps( 'First added' ) );
check( 'Subscribed On', 'created_at', $maps( 'Subscribed On' ) );
check( 'Date Joined', 'created_at', $maps( 'Date Joined' ) );
check( 'created_at straight from a database', 'created_at', $maps( 'created_at' ) );

// Columns that must not be read as a date. "Status" and "Subscribed to lists"
// sit next to the date column in a MailPoet export, and "Signup Source" next
// to it in others.
check( 'Status stays the status', 'status', $maps( 'Status' ) );
check( 'Subscription Status stays the status', 'status', $maps( 'Subscription Status' ) );
check( 'Signup Source stays the source', 'source', $maps( 'Signup Source' ) );
check( 'Email stays the email', 'email', $maps( 'Email' ) );
check( 'an unknown column maps to nothing', '', $maps( 'Favourite biscuit' ) );

// A whole MailPoet export header row, mapped in one pass.
$mailpoet = $import->detect_columns(
	array( 'Email', 'First name', 'Last name', 'Status', 'Subscribed to lists', 'Confirmation time' )
);
check(
	'a MailPoet header row maps end to end',
	array( 'email', 'first_name', 'last_name', 'status', '', 'created_at' ),
	array_values( $mailpoet )
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
