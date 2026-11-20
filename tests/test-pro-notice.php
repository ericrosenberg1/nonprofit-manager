<?php
/**
 * The post-setup Pro notice (includes/npmp-pro-notice.php).
 *
 * wordpress.org sent 610 downloads in the week to 2026-11-20 and no Pro order
 * came back (ROS-4454). A free install that never opens Payment Settings used
 * to meet Pro once, inside the setup wizard. This notice is the second
 * meeting, so these checks pin who sees it: an administrator, on the free
 * plugin, on one of our screens, after the wizard, with neither of the two
 * older asks on screen. They also pin the copy, which carries the price from
 * one place and the word this site uses for donations.
 *
 * Run: php tests/test-pro-notice.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_can']          = true;
$GLOBALS['t_pro']          = false;
$GLOBALS['t_setup_done']   = true;
$GLOBALS['t_screen']       = 'toplevel_page_npmp_main';
$GLOBALS['t_usermeta']     = array();
$GLOBALS['t_credit_ask']   = false;
$GLOBALS['t_review_nudge'] = false;
$GLOBALS['t_gift_word']    = 'donations';

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function current_user_can( $cap ) {
	return $GLOBALS['t_can'];
}
function npmp_is_pro() {
	return $GLOBALS['t_pro'];
}
function npmp_setup_wizard_is_done() {
	return $GLOBALS['t_setup_done'];
}
function get_current_user_id() {
	return 1;
}
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['t_usermeta'][ $key ] ) ? $GLOBALS['t_usermeta'][ $key ] : '';
}
function get_current_screen() {
	return $GLOBALS['t_screen'] ? (object) array( 'id' => $GLOBALS['t_screen'] ) : null;
}
function npmp_credit_ask_should_show() {
	return $GLOBALS['t_credit_ask'];
}
function npmp_review_nudge_should_show() {
	return $GLOBALS['t_review_nudge'];
}
function npmp_label( $key ) {
	return 'donations_lc' === $key ? $GLOBALS['t_gift_word'] : $key;
}
function npmp_get_pro_starting_price() {
	return '$47 a year';
}

require_once dirname( __DIR__ ) . '/includes/npmp-pro-notice.php';

/**
 * Reset the fake site: an administrator on our dashboard, free plugin, wizard
 * finished, nothing dismissed, neither older ask on screen.
 */
function t_reset() {
	$GLOBALS['t_can']          = true;
	$GLOBALS['t_pro']          = false;
	$GLOBALS['t_setup_done']   = true;
	$GLOBALS['t_screen']       = 'toplevel_page_npmp_main';
	$GLOBALS['t_usermeta']     = array();
	$GLOBALS['t_credit_ask']   = false;
	$GLOBALS['t_review_nudge'] = false;
	$GLOBALS['t_gift_word']    = 'donations';
}

echo "Who sees it\n";
t_reset();
check( 'a free admin after the wizard', true, npmp_pro_notice_should_show() );

t_reset();
$GLOBALS['t_can'] = false;
check( 'not an editor or a subscriber', false, npmp_pro_notice_should_show() );

t_reset();
$GLOBALS['t_pro'] = true;
check( 'not a site that already bought Pro', false, npmp_pro_notice_should_show() );

t_reset();
$GLOBALS['t_setup_done'] = false;
check( 'not before the wizard is finished or skipped', false, npmp_pro_notice_should_show() );

t_reset();
$GLOBALS['t_usermeta']['npmp_pro_notice_dismissed'] = 1;
check( 'not after this user dismissed it', false, npmp_pro_notice_should_show() );

echo "\nWhere it shows\n";
t_reset();
$GLOBALS['t_screen'] = 'edit-post';
check( 'not on a screen of WordPress own', false, npmp_pro_notice_should_show() );
$GLOBALS['t_screen'] = 'nonprofit-manager_page_npmp_general_settings';
check( 'on a settings screen of ours', true, npmp_pro_notice_should_show() );
$GLOBALS['t_screen'] = 'nonprofit-manager_page_npmp_setup_wizard';
check( 'not over the wizard, which pitches Pro itself', false, npmp_pro_notice_should_show() );
$GLOBALS['t_screen'] = '';
check( 'not when WordPress has no screen yet', false, npmp_pro_notice_should_show() );

echo "\nOne ask at a time\n";
t_reset();
$GLOBALS['t_credit_ask'] = true;
check( 'waits for the Powered by ask', false, npmp_pro_notice_should_show() );
t_reset();
$GLOBALS['t_review_nudge'] = true;
check( 'waits for the review nudge', false, npmp_pro_notice_should_show() );
t_reset();
check( 'and shows once neither is up', true, npmp_pro_notice_should_show() );

echo "\nThe copy\n";
t_reset();
$lines = npmp_pro_notice_lines();
check( 'the lead asks about monthly giving and dues', 'Taking monthly donations or charging dues?', $lines['lead'] );
check( 'the body names Pro', true, false !== strpos( $lines['body'], 'Nonprofit Manager Pro' ) );
check( 'the body carries the price from npmp_get_pro_starting_price()', true, false !== strpos( $lines['body'], '$47 a year' ) );
check( 'the body names the money-back window', true, false !== strpos( $lines['body'], '30 days' ) );
check( 'the body names recurring giving, dues and Smart Buttons', true, false !== strpos( $lines['body'], 'Recurring donations' ) && false !== strpos( $lines['body'], 'dues billing' ) && false !== strpos( $lines['body'], 'PayPal Smart Buttons' ) );
check( 'the button says what it opens', 'See Pro plans', $lines['cta'] );
$GLOBALS['t_gift_word'] = 'gifts';
$lines                  = npmp_pro_notice_lines();
check( 'a church sees its own word in the lead', 'Taking monthly gifts or charging dues?', $lines['lead'] );
check( 'and in the body', true, false !== strpos( $lines['body'], 'Recurring gifts' ) );
$GLOBALS['t_gift_word'] = 'donations';
foreach ( npmp_pro_notice_lines() as $key => $line ) {
	check( "$key has no em dash or semicolon", false, false !== strpos( $line, "\xE2\x80\x94" ) || false !== strpos( $line, ';' ) );
}

echo "\nThe link\n";
$src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-pro-notice.php' );
check( 'the notice asks for the admin-notice medium', 1, preg_match( "/npmp_get_upgrade_url\(\s*'post_setup',\s*'admin-notice'\s*\)/", $src ) );
check( 'and hard-codes no pricing URL', false, strpos( $src, 'nonprofitmanager.app' ) );
check( 'the Pro link opens in a new tab', true, false !== strpos( $src, 'target="_blank" rel="noopener"' ) );
check( 'the dismissal is nonce-guarded', true, false !== strpos( $src, "check_admin_referer( 'npmp_pro_notice' )" ) );

echo "\nThe two asks it yields to still answer to those names\n";
$credit = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-credit-ask.php' );
$review = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-review-nudge.php' );
check( 'npmp_credit_ask_should_show', true, false !== strpos( $credit, 'function npmp_credit_ask_should_show' ) );
check( 'npmp_review_nudge_should_show', true, false !== strpos( $review, 'function npmp_review_nudge_should_show' ) );
check( 'and the plugin loads the notice', true, false !== strpos( file_get_contents( dirname( __DIR__ ) . '/nonprofit-manager.php' ), "includes/npmp-pro-notice.php" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
