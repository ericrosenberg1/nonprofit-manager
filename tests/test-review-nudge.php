<?php
/**
 * The review nudge's milestones (includes/npmp-review-nudge.php).
 *
 * The nudge used to fire only after a first donation or newsletter, so a
 * site that runs on events and members alone was never asked. Pinned here:
 * the events, contacts and tenure milestones and their thresholds, that the
 * counting runs only for an administrator on a plugin screen who has not
 * dismissed the nudge, that it stops once any milestone lands, how an
 * install that predates the activation timestamp is dated, and that the
 * one-at-a-time rule with the "Powered by" ask still holds. The rendered
 * notice is checked on a real WordPress before release.
 *
 * Run: php tests/test-review-nudge.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// In-memory stand-ins for the WordPress functions these files call.
$GLOBALS['t_options']     = array();
$GLOBALS['t_can']         = true;
$GLOBALS['t_screen']      = 'toplevel_page_npmp_main';
$GLOBALS['t_counts']      = array(); // post type => published count. Missing type = unregistered.
$GLOBALS['t_count_calls'] = 0;
$GLOBALS['t_posts']       = array();
$GLOBALS['t_post_time']   = 0;
$GLOBALS['t_queries']     = 0;
$GLOBALS['t_usermeta']    = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = (string) $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['t_options'] ) ) {
		return false;
	}
	$GLOBALS['t_options'][ $name ] = (string) $value;
	return true;
}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function get_current_screen() {
	return $GLOBALS['t_screen'] ? (object) array( 'id' => $GLOBALS['t_screen'] ) : null;
}
function wp_count_posts( $type ) {
	$GLOBALS['t_count_calls']++;
	if ( ! array_key_exists( $type, $GLOBALS['t_counts'] ) ) {
		return new stdClass(); // what WordPress returns for an unregistered type
	}
	return (object) array( 'publish' => $GLOBALS['t_counts'][ $type ], 'draft' => 3 );
}
function get_posts( $args ) {
	$GLOBALS['t_queries']++;
	$GLOBALS['t_last_query'] = $args;
	return $GLOBALS['t_posts'];
}
function get_post_time( $format, $gmt, $post ) {
	return $GLOBALS['t_post_time'];
}
function get_current_user_id() {
	return 1;
}
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['t_usermeta'][ $key ] ) ? $GLOBALS['t_usermeta'][ $key ] : '';
}
function apply_filters( $hook, $value ) {
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/npmp-review-nudge.php';
require_once dirname( __DIR__ ) . '/includes/npmp-credit-ask.php';

/**
 * Reset the fake site: an administrator on our dashboard, the credit ask
 * already settled long ago so it never interferes unless a test says so.
 */
function t_reset( $events = 0, $contacts = 0 ) {
	$GLOBALS['t_options']     = array(
		'npmp_first_donation_at' => '0',
		'npmp_credit_ask_done'   => (string) ( time() - 30 * DAY_IN_SECONDS ),
	);
	$GLOBALS['t_can']         = true;
	$GLOBALS['t_screen']      = 'toplevel_page_npmp_main';
	$GLOBALS['t_counts']      = array(
		'npmp_event'   => $events,
		'npmp_contact' => $contacts,
	);
	$GLOBALS['t_count_calls'] = 0;
	$GLOBALS['t_posts']       = array();
	$GLOBALS['t_post_time']   = 0;
	$GLOBALS['t_queries']     = 0;
	$GLOBALS['t_usermeta']    = array();
}

echo "Thresholds\n";
check( 'events threshold', 10, NPMP_REVIEW_NUDGE_EVENTS );
check( 'contacts threshold', 25, NPMP_REVIEW_NUDGE_CONTACTS );
check( 'tenure days', 30, NPMP_REVIEW_NUDGE_TENURE_DAYS );

echo "\nA fresh site\n";
t_reset( 0, 0 );
check( 'nothing to ask yet', false, npmp_review_nudge_should_show() );
check( 'both types were counted', 2, $GLOBALS['t_count_calls'] );
check( 'no milestone recorded', false, isset( $GLOBALS['t_options']['npmp_first_milestone_at'] ) );
check( 'an empty site is not dated (no tenure clock yet)', false, isset( $GLOBALS['t_options']['npmp_activated_at'] ) );
check( 'no oldest-record query on an empty site', 0, $GLOBALS['t_queries'] );

echo "\nJust under every line\n";
t_reset( 9, 24 );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 29 * DAY_IN_SECONDS );
check( 'nine events and 24 contacts for 29 days: hidden', false, npmp_review_nudge_should_show() );
check( 'no milestone recorded', false, isset( $GLOBALS['t_options']['npmp_first_milestone_at'] ) );

echo "\nTen events\n";
t_reset( 10, 0 );
check( 'the nudge shows', true, npmp_review_nudge_should_show() );
check( 'milestone type is events', 'events', $GLOBALS['t_options']['npmp_first_milestone_type'] );
check( 'milestone time recorded', true, (int) $GLOBALS['t_options']['npmp_first_milestone_at'] > 0 );
check( 'the lead names the count', "You've published 10 events with Nonprofit Manager.", npmp_review_nudge_lead( 'events' ) );
check( 'the donation time stays 0, so the credit ask stays quiet', '0', $GLOBALS['t_options']['npmp_first_donation_at'] );
check( 'and the credit ask does not open', false, npmp_credit_ask_should_show() );
$calls = $GLOBALS['t_count_calls'];
npmp_review_nudge_should_show();
check( 'once a milestone is set, nothing is counted again', $calls, $GLOBALS['t_count_calls'] );
check( 'check_milestones is a no-op after that', '', npmp_review_nudge_check_milestones() );

echo "\nTwenty-five contacts\n";
t_reset( 0, 25 );
check( 'the nudge shows', true, npmp_review_nudge_should_show() );
check( 'milestone type is contacts', 'contacts', $GLOBALS['t_options']['npmp_first_milestone_type'] );
check( 'the lead names the count', "You're keeping 25 contacts in Nonprofit Manager.", npmp_review_nudge_lead( 'contacts' ) );

echo "\nBoth at once\n";
t_reset( 40, 300 );
check( 'events is recorded first', 'events', npmp_review_nudge_check_milestones() );
check( 'only one milestone', 'events', $GLOBALS['t_options']['npmp_first_milestone_type'] );

echo "\nTenure\n";
t_reset( 1, 0 );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 31 * DAY_IN_SECONDS );
check( 'one event, 31 days: tenure', 'tenure', npmp_review_nudge_check_milestones() );
check( 'the lead', "You've been running Nonprofit Manager for a month.", npmp_review_nudge_lead( 'tenure' ) );

t_reset( 0, 1 );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 31 * DAY_IN_SECONDS );
check( 'one contact, 31 days: tenure', 'tenure', npmp_review_nudge_check_milestones() );

t_reset( 0, 0 );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 400 * DAY_IN_SECONDS );
check( 'a year with nothing in it is not a milestone', '', npmp_review_nudge_check_milestones() );

t_reset( 3, 3 );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 30 * DAY_IN_SECONDS + 60 );
check( 'a minute short of 30 days: not yet', '', npmp_review_nudge_check_milestones() );
$GLOBALS['t_options']['npmp_activated_at'] = (string) ( time() - 30 * DAY_IN_SECONDS - 60 );
check( 'a minute past 30 days: tenure', 'tenure', npmp_review_nudge_check_milestones() );

echo "\nDating an install that predates npmp_activated_at\n";
t_reset( 1, 0 );
$GLOBALS['t_posts']     = array( 77 );
$GLOBALS['t_post_time'] = time() - 40 * DAY_IN_SECONDS;
check( 'oldest record 40 days back: tenure fires', 'tenure', npmp_review_nudge_check_milestones() );
check( 'activation dated from that record', (string) $GLOBALS['t_post_time'], $GLOBALS['t_options']['npmp_activated_at'] );
check( 'one query', 1, $GLOBALS['t_queries'] );
check( 'it looked at contacts, events and donations', array( 'npmp_contact', 'npmp_event', 'npmp_donation' ), $GLOBALS['t_last_query']['post_type'] );
check( 'oldest first', 'ASC', $GLOBALS['t_last_query']['order'] );

t_reset( 1, 0 );
$GLOBALS['t_posts']     = array( 78 );
$GLOBALS['t_post_time'] = time() - 2 * DAY_IN_SECONDS;
check( 'oldest record two days back: not yet', '', npmp_review_nudge_check_milestones() );
check( 'but the date is stored', (string) $GLOBALS['t_post_time'], $GLOBALS['t_options']['npmp_activated_at'] );
npmp_review_nudge_check_milestones();
check( 'and never queried again', 1, $GLOBALS['t_queries'] );

t_reset( 1, 0 );
$GLOBALS['t_posts']     = array( 79 );
$GLOBALS['t_post_time'] = time() + 5 * DAY_IN_SECONDS;
$before                 = time();
npmp_review_nudge_check_milestones();
check( 'a record dated in the future counts from now', true, (int) $GLOBALS['t_options']['npmp_activated_at'] >= $before );

t_reset( 1, 0 );
$before = time();
npmp_review_nudge_check_milestones();
check( 'a site with a count but no datable record counts from now', true, (int) $GLOBALS['t_options']['npmp_activated_at'] >= $before );

echo "\nActivation stamps the date once\n";
t_reset();
check( 'first activation writes the date', true, add_option( 'npmp_activated_at', 1000, '', false ) );
check( 'reactivation keeps it', false, add_option( 'npmp_activated_at', 2000, '', false ) );
check( 'stored value is the first', 1000, npmp_review_nudge_active_since() );

echo "\nWho gets counted\n";
t_reset( 50, 50 );
$GLOBALS['t_can'] = false;
check( 'a non-admin sees nothing', false, npmp_review_nudge_should_show() );
check( 'and nothing was counted for them', 0, $GLOBALS['t_count_calls'] );

t_reset( 50, 50 );
$GLOBALS['t_screen'] = 'dashboard';
check( 'off our screens: nothing', false, npmp_review_nudge_should_show() );
check( 'and nothing was counted', 0, $GLOBALS['t_count_calls'] );

t_reset( 50, 50 );
$GLOBALS['t_screen'] = null;
check( 'no screen object: nothing', false, npmp_review_nudge_should_show() );
check( 'and nothing was counted', 0, $GLOBALS['t_count_calls'] );

t_reset( 50, 50 );
$GLOBALS['t_usermeta']['npmp_review_nudge_dismissed'] = 1;
check( 'dismissed: nothing', false, npmp_review_nudge_should_show() );
check( 'and nothing was counted', 0, $GLOBALS['t_count_calls'] );

t_reset( 50, 50 );
$GLOBALS['t_screen'] = 'nonprofit-manager_page_npmp_automations';
check( 'the Pro preview pages count as our screens', true, npmp_review_nudge_should_show() );

echo "\nModules switched off\n";
t_reset();
$GLOBALS['t_counts'] = array( 'npmp_contact' => 30 ); // calendar module off: npmp_event unregistered
check( 'an unregistered type counts as zero', 0, npmp_review_nudge_count_published( 'npmp_event' ) );
check( 'contacts still reach their milestone', 'contacts', npmp_review_nudge_check_milestones() );

echo "\nOne ask at a time\n";
t_reset( 10, 0 );
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
unset( $GLOBALS['t_options']['npmp_credit_ask_done'] );
check( 'events milestone lands', 'events', npmp_review_nudge_check_milestones() );
check( 'but the review nudge waits while the credit ask is open', false, npmp_review_nudge_should_show() );
check( 'the credit ask is the one showing', true, npmp_credit_ask_should_show() );
npmp_credit_ask_apply( 'no', false );
check( 'still waiting right after the answer', false, npmp_review_nudge_should_show() );
$GLOBALS['t_options']['npmp_credit_ask_done'] = (string) ( time() - 2 * DAY_IN_SECONDS );
check( 'and two days later', false, npmp_review_nudge_should_show() );
$GLOBALS['t_options']['npmp_credit_ask_done'] = (string) ( time() - 3 * DAY_IN_SECONDS - 60 );
check( 'shows after three days', true, npmp_review_nudge_should_show() );

echo "\nThe pushed milestones still work and keep their type\n";
t_reset();
npmp_mark_milestone( 'donation' );
check( 'donation sets the type', 'donation', $GLOBALS['t_options']['npmp_first_milestone_type'] );
check( 'and the donation time', true, (int) $GLOBALS['t_options']['npmp_first_donation_at'] > 0 );
npmp_mark_milestone( 'events' );
check( 'a later milestone does not overwrite the type', 'donation', $GLOBALS['t_options']['npmp_first_milestone_type'] );
t_reset();
npmp_mark_milestone( 'newsletter' );
check( 'newsletter sets the type', 'newsletter', $GLOBALS['t_options']['npmp_first_milestone_type'] );
check( 'newsletter leaves the donation time alone', '0', $GLOBALS['t_options']['npmp_first_donation_at'] );

echo "\nLead lines\n";
check( 'donation', 'Your first donation came in through Nonprofit Manager.', npmp_review_nudge_lead( 'donation' ) );
check( 'newsletter', 'Your first newsletter went out through Nonprofit Manager.', npmp_review_nudge_lead( 'newsletter' ) );
check( 'unknown type: no lead', '', npmp_review_nudge_lead( 'something-else' ) );
check( 'older sites with no type: no lead', '', npmp_review_nudge_lead( '' ) );
foreach ( array( 'donation', 'newsletter', 'events', 'contacts', 'tenure' ) as $type ) {
	$lead = npmp_review_nudge_lead( $type );
	check( "$type lead has no em dash or semicolon", false, false !== strpos( $lead, "\xE2\x80\x94" ) || false !== strpos( $lead, ';' ) );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
