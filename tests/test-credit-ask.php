<?php
/**
 * The one-time "Powered by" ask after a site's first donation
 * (includes/npmp-credit-ask.php) and how it hands off to the review nudge
 * (includes/npmp-review-nudge.php).
 *
 * WordPress.org guideline 10 is the constraint here: the credit link may only
 * appear after the owner explicitly asks for it. So the things pinned below
 * are that nothing but "Save" with the box ticked turns the link on, that the
 * ask never shows before a donation exists, and that it stops for good once
 * answered. The rendered notice and the POST round trip are checked on a real
 * WordPress install before release (~/Code/MASTER-HANDOFF.md 3.17.7).
 *
 * Run: php tests/test-credit-ask.php
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// In-memory stand-ins for the WordPress functions these files call.
$GLOBALS['t_options']   = array();
$GLOBALS['t_can']       = true;
$GLOBALS['t_screen']    = 'toplevel_page_npmp_main';
$GLOBALS['t_posts']     = array();
$GLOBALS['t_post_time'] = 0;
$GLOBALS['t_queries']   = 0;
$GLOBALS['t_usermeta']  = array();

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
function get_posts( $args ) {
	$GLOBALS['t_queries']++;
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
 * Reset the fake site to a fresh install with an admin on our dashboard.
 */
function t_reset() {
	$GLOBALS['t_options']   = array();
	$GLOBALS['t_can']       = true;
	$GLOBALS['t_screen']    = 'toplevel_page_npmp_main';
	$GLOBALS['t_posts']     = array();
	$GLOBALS['t_post_time'] = 0;
	$GLOBALS['t_queries']   = 0;
	$GLOBALS['t_usermeta']  = array();
}

echo "No donation yet\n";
t_reset();
check( 'fresh site: ask hidden', false, npmp_credit_ask_should_show() );
check( 'fresh site: looked for an old donation once', 1, $GLOBALS['t_queries'] );
check( 'fresh site: stored 0 so it never looks again', '0', $GLOBALS['t_options']['npmp_first_donation_at'] );
npmp_credit_ask_should_show();
check( 'second check runs no query', 1, $GLOBALS['t_queries'] );

echo "\nFirst donation arrives\n";
npmp_mark_milestone( 'donation' );
check( 'first donation time recorded over the stored 0', true, (int) $GLOBALS['t_options']['npmp_first_donation_at'] > 0 );
check( 'ask shows to an admin on our screen', true, npmp_credit_ask_should_show() );
$first = $GLOBALS['t_options']['npmp_first_donation_at'];
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
npmp_mark_milestone( 'donation' );
check( 'a later donation keeps the first time', '1000', $GLOBALS['t_options']['npmp_first_donation_at'] );

echo "\nWho and where\n";
$GLOBALS['t_can'] = false;
check( 'hidden from non-admins', false, npmp_credit_ask_should_show() );
$GLOBALS['t_can']    = true;
$GLOBALS['t_screen'] = 'dashboard';
check( 'hidden off our screens', false, npmp_credit_ask_should_show() );
$GLOBALS['t_screen'] = null;
check( 'hidden with no screen', false, npmp_credit_ask_should_show() );

echo "\nA newsletter is not a donation\n";
t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '0';
npmp_mark_milestone( 'newsletter' );
check( 'newsletter milestone leaves the donation time at 0', '0', $GLOBALS['t_options']['npmp_first_donation_at'] );
check( 'newsletter milestone still counts for the review nudge', true, isset( $GLOBALS['t_options']['npmp_first_milestone_at'] ) );
check( 'no ask after a newsletter only', false, npmp_credit_ask_should_show() );

echo "\nSites that took donations before this release\n";
t_reset();
$GLOBALS['t_posts']     = array( 42 );
$GLOBALS['t_post_time'] = 1754000000;
check( 'existing donation found, ask shows', true, npmp_credit_ask_should_show() );
check( 'stored the oldest donation time', '1754000000', $GLOBALS['t_options']['npmp_first_donation_at'] );

echo "\nAnswers\n";
t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
npmp_credit_ask_apply( 'save', true );
check( 'save with the box ticked turns the link on', '1', get_option( 'npmp_powered_by_optin' ) );
check( 'and ends the ask', false, npmp_credit_ask_should_show() );

t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
npmp_credit_ask_apply( 'save', false );
check( 'save with the box empty keeps the link off', false, get_option( 'npmp_powered_by_optin' ) );
check( 'and ends the ask', false, npmp_credit_ask_should_show() );

t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
npmp_credit_ask_apply( 'no', true );
check( '"No thanks" ignores a ticked box', false, get_option( 'npmp_powered_by_optin' ) );
check( 'and ends the ask', false, npmp_credit_ask_should_show() );

t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
npmp_credit_ask_apply( 'anything-else', true );
check( 'an unknown choice keeps the link off', false, get_option( 'npmp_powered_by_optin' ) );

t_reset();
$GLOBALS['t_options']['npmp_first_donation_at'] = '1000';
$GLOBALS['t_options']['npmp_powered_by_optin']  = '1';
check( 'never asks a site that already opted in', false, npmp_credit_ask_should_show() );

echo "\nOne ask at a time\n";
t_reset();
$GLOBALS['t_options']['npmp_first_milestone_at'] = '1000';
$GLOBALS['t_options']['npmp_first_donation_at']  = '1000';
check( 'review nudge waits while the credit ask is open', false, npmp_review_nudge_should_show() );
npmp_credit_ask_apply( 'no', false );
check( 'review nudge waits right after the answer', false, npmp_review_nudge_should_show() );
$GLOBALS['t_options']['npmp_credit_ask_done'] = (string) ( time() - 2 * DAY_IN_SECONDS );
check( 'still waiting two days later', false, npmp_review_nudge_should_show() );
$GLOBALS['t_options']['npmp_credit_ask_done'] = (string) ( time() - 3 * DAY_IN_SECONDS - 60 );
check( 'review nudge shows after three days', true, npmp_review_nudge_should_show() );

t_reset();
$GLOBALS['t_options']['npmp_first_milestone_at'] = '1000';
$GLOBALS['t_options']['npmp_first_donation_at']  = '0';
check( 'newsletter-only site: review nudge unaffected', true, npmp_review_nudge_should_show() );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
