<?php
/**
 * The Dashboard's donation figures stay cached until a donation changes
 * (sweep 2026-11-21, F13).
 *
 * The year-to-date and recurring figures each ran a grouped query over every
 * donation on every wp-admin Dashboard load. We now keep each query's rows
 * for 10 minutes under a token that changes at the end of any request that
 * adds, edits, trashes, or deletes a donation. These load the real
 * includes/npmp-dashboard-widgets.php with WordPress and $wpdb stubbed. Each
 * hook case runs in its own PHP process, since "this request changed a
 * donation" lasts for the whole process.
 *
 * The real-WordPress check across requests is tests/rig/payments-dashboard.php.
 *
 * Run: php tests/test-dashboard-donation-cache.php
 */

require_once __DIR__ . '/bootstrap.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['t_actions']    = array();
$GLOBALS['t_options']    = array();
$GLOBALS['t_transients'] = array();
$GLOBALS['t_ttls']       = array();
$GLOBALS['t_year']       = '2026';
$GLOBALS['t_filtered']   = 0;
$GLOBALS['t_types']      = array(
	11 => 'npmp_donation',
	12 => 'page',
	13 => 'npmp_event',
);

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $hook ][] = $cb;
}
function get_option( $key, $default = false ) {
	return $GLOBALS['t_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $key ] = $value;
	return true;
}
function get_transient( $key ) {
	return $GLOBALS['t_transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['t_transients'][ $key ] = $value;
	$GLOBALS['t_ttls'][ $key ]       = $ttl;
	return true;
}
function get_post_type( $post ) {
	return $GLOBALS['t_types'][ (int) $post ] ?? false;
}
function wp_date( $format ) {
	return $GLOBALS['t_year'];
}
function apply_filters( $tag, $value, ...$args ) {
	if ( 'npmp_annual_recurring_totals' === $tag ) {
		$GLOBALS['t_filtered']++;
	}
	return $value;
}

class NPMP_Donation_Manager {
	const POST_TYPE      = 'npmp_donation';
	const META_EMAIL     = '_npmp_donation_email';
	const META_AMOUNT    = '_npmp_donation_amount';
	const META_FREQUENCY = '_npmp_donation_frequency';
	const META_CURRENCY  = '_npmp_donation_currency';
}

/** Counts queries and hands back whatever rows the test sets. */
class T_Wpdb {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries  = 0;
	public $ytd      = array( array( 'currency' => 'USD', 'total' => '120' ) );
	public $recur    = array();
	public function prepare( $sql, ...$args ) {
		return $sql;
	}
	public function get_results( $sql, $output = null ) {
		$this->queries++;
		return false !== strpos( $sql, 'latest' ) ? $this->recur : $this->ytd;
	}
}
$GLOBALS['wpdb'] = new T_Wpdb();

require_once dirname( __DIR__ ) . '/includes/npmp-currency.php';
require_once dirname( __DIR__ ) . '/includes/npmp-dashboard-widgets.php';

/**
 * Fire one WordPress hook the way core does.
 *
 * @param string $hook Hook name.
 * @param array  $args Arguments.
 * @return void
 */
function t_fire( $hook, $args ) {
	foreach ( $GLOBALS['t_actions'][ $hook ] ?? array() as $cb ) {
		call_user_func_array( $cb, $args );
	}
}

// Child mode: fire one hook and report whether the request counts as
// having changed a donation.
if ( isset( $argv[1] ) && 'hook' === $argv[1] ) {
	$hook = $argv[2];
	$args = json_decode( $argv[3], true );
	foreach ( $args as $i => $arg ) {
		if ( is_array( $arg ) && isset( $arg['post_type'] ) ) {
			$args[ $i ] = (object) $arg;
		}
	}
	t_fire( $hook, $args );
	t_fire( $hook, $args );
	echo json_encode(
		array(
			'dirty'    => npmp_dashboard_donations_dirty(),
			'shutdown' => count( $GLOBALS['t_actions']['shutdown'] ?? array() ),
		)
	);
	exit( 0 );
}

/**
 * Run one hook in a fresh PHP process.
 *
 * @param string $hook Hook name.
 * @param array  $args Arguments.
 * @return array dirty, shutdown.
 */
function t_hook_in_child( $hook, $args ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' hook ' . escapeshellarg( $hook ) . ' ' . escapeshellarg( json_encode( $args ) );
	$out = (string) shell_exec( $cmd );
	return (array) json_decode( $out, true );
}

$wpdb = $GLOBALS['wpdb'];

echo "\n== 1. Year to date ==\n";
check( 'first load runs the query', array( 'USD' => 120.0 ), npmp_get_ytd_donation_totals_by_currency() );
check( 'one query', 1, $wpdb->queries );
check( 'the rows wait 10 minutes', 600, $GLOBALS['t_ttls']['npmp_dash_ytd'] ?? 0 );
check( 'second load reads the cache', array( 'USD' => 120.0 ), npmp_get_ytd_donation_totals_by_currency() );
check( 'still one query', 1, $wpdb->queries );

$GLOBALS['t_year'] = '2027';
$wpdb->ytd         = array();
check( 'a new year runs the query again', array(), npmp_get_ytd_donation_totals_by_currency() );
check( 'two queries', 2, $wpdb->queries );
$GLOBALS['t_year'] = '2026';
$wpdb->ytd         = array( array( 'currency' => 'USD', 'total' => '120' ) );
npmp_get_ytd_donation_totals_by_currency();
check( 'back in 2026, the 2027 rows never answer', 3, $wpdb->queries );

// Another request saved a gift and changed the token at its shutdown.
$wpdb->ytd = array( array( 'currency' => 'USD', 'total' => '170' ) );
update_option( 'npmp_dashboard_donations_token', 'after-a-gift' );
check( 'a new token shows the new gift at once', array( 'USD' => 170.0 ), npmp_get_ytd_donation_totals_by_currency() );
check( 'four queries', 4, $wpdb->queries );
npmp_get_ytd_donation_totals_by_currency();
check( 'the new rows cache under the new token', 4, $wpdb->queries );

// A failed query returns null. Caching that would show $0 for 10 minutes.
update_option( 'npmp_dashboard_donations_token', 'db-hiccup' );
$wpdb->ytd = null;
check( 'a failed query shows nothing', array(), npmp_get_ytd_donation_totals_by_currency() );
$wpdb->ytd = array( array( 'currency' => 'USD', 'total' => '170' ) );
check( 'and the next load asks again', array( 'USD' => 170.0 ), npmp_get_ytd_donation_totals_by_currency() );
check( 'six queries', 6, $wpdb->queries );

echo "\n== 2. Recurring ==\n";
$wpdb->queries = 0;
$wpdb->recur   = array(
	array( 'frequency' => 'monthly', 'currency' => 'USD', 'latest' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) . ' 1000000000011|10', 'gift_date' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
);
check( 'first load runs the query', array( 'USD' => 120.0 ), npmp_get_annual_recurring_totals_by_currency() );
check( 'second load reads the cache', array( 'USD' => 120.0 ), npmp_get_annual_recurring_totals_by_currency() );
check( 'one query', 1, $wpdb->queries );
check( 'the totals filter runs on every load', 2, $GLOBALS['t_filtered'] );
$wpdb->recur = null;
update_option( 'npmp_dashboard_donations_token', 'db-hiccup-2' );
check( 'a failed query shows nothing', array(), npmp_get_annual_recurring_totals_by_currency() );
$wpdb->recur = array();
npmp_get_annual_recurring_totals_by_currency();
check( 'and the next load asks again', 3, $wpdb->queries );

echo "\n== 3. What changes the figures ==\n";
$donation = array( 'ID' => 11, 'post_type' => 'npmp_donation' );
$page     = array( 'ID' => 12, 'post_type' => 'page' );
$cases    = array(
	'a new or edited donation'              => array( 'save_post_npmp_donation', array( 11, $donation, false ), true ),
	'a donation published'                  => array( 'transition_post_status', array( 'publish', 'draft', $donation ), true ),
	'a donation trashed'                    => array( 'transition_post_status', array( 'trash', 'publish', $donation ), true ),
	'a donation restored'                   => array( 'transition_post_status', array( 'draft', 'trash', $donation ), true ),
	'a donation deleted (before)'           => array( 'before_delete_post', array( 11, $donation ), true ),
	'a donation deleted (after, ID only)'   => array( 'deleted_post', array( 11 ), true ),
	'a new amount'                          => array( 'added_post_meta', array( 5, 11, '_npmp_donation_amount', '25' ), true ),
	'an edited amount'                      => array( 'updated_post_meta', array( 5, 11, '_npmp_donation_amount', '30' ), true ),
	'an edited currency'                    => array( 'updated_post_meta', array( 6, 11, '_npmp_donation_currency', 'GBP' ), true ),
	'an edited frequency'                   => array( 'updated_post_meta', array( 7, 11, '_npmp_donation_frequency', 'monthly' ), true ),
	'an edited donor email'                 => array( 'updated_post_meta', array( 8, 11, '_npmp_donation_email', 'a@example.org' ), true ),
	'a removed amount'                      => array( 'deleted_post_meta', array( array( 5 ), 11, '_npmp_donation_amount', '' ), true ),
	'a page published'                      => array( 'transition_post_status', array( 'publish', 'draft', $page ), false ),
	'a donation saved with the same status' => array( 'transition_post_status', array( 'publish', 'publish', $donation ), false ),
	'a page deleted'                        => array( 'before_delete_post', array( 12, $page ), false ),
	'an event deleted (ID only)'            => array( 'deleted_post', array( 13 ), false ),
	'other meta on a donation'              => array( 'updated_post_meta', array( 9, 11, '_npmp_donation_txn_id', 'x' ), false ),
	'an amount key on a page'               => array( 'updated_post_meta', array( 9, 12, '_npmp_donation_amount', '5' ), false ),
);
foreach ( $cases as $label => $case ) {
	$r = t_hook_in_child( $case[0], $case[1] );
	if ( $case[2] ) {
		check( "$label: changes the token once, at shutdown", array( 'dirty' => true, 'shutdown' => 1 ), $r );
	} else {
		check( "$label: leaves the cache", array( 'dirty' => false, 'shutdown' => 0 ), $r );
	}
}

echo "\n== 4. The request that saved the gift ==\n";
// This process now saves a gift, as the Stripe return or the PayPal logger does.
$wpdb->queries = 0;
$wpdb->ytd     = array( array( 'currency' => 'USD', 'total' => '170' ) );
update_option( 'npmp_dashboard_donations_token', 'steady' );
npmp_get_ytd_donation_totals_by_currency();
npmp_get_ytd_donation_totals_by_currency();
check( 'before the gift: one query', 1, $wpdb->queries );
t_fire( 'added_post_meta', array( 5, 11, '_npmp_donation_amount', '50' ) );
$wpdb->ytd = array( array( 'currency' => 'USD', 'total' => '220' ) );
check( 'the same request shows the new gift', array( 'USD' => 220.0 ), npmp_get_ytd_donation_totals_by_currency() );
check( 'it skipped the cache', 2, $wpdb->queries );
check( 'and stored nothing under the old token', array( 'USD' => 170.0 ), npmp_group_totals_by_currency( $GLOBALS['t_transients']['npmp_dash_ytd']['rows'] ) );
check( 'the token holds until shutdown', 'steady', get_option( 'npmp_dashboard_donations_token' ) );
t_fire( 'shutdown', array() );
$after = get_option( 'npmp_dashboard_donations_token' );
check( 'shutdown changes the token', true, is_string( $after ) && '' !== $after && 'steady' !== $after );
check( 'so the next request misses the old rows', true, $after !== $GLOBALS['t_transients']['npmp_dash_ytd']['token'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
