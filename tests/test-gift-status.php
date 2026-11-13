<?php
/**
 * A gift keeps a contact's status (NPMP_Member_Manager::status_after_gift()).
 *
 * Newsletters go to Active (subscribed) contacts only. Until this test a gift
 * turned an Active contact into a Donor, so importing past gifts from GiveWP or
 * Charitable took every giving subscriber off the newsletter, and a current
 * member who gave lost Active.
 *
 * Run: php tests/test-gift-status.php
 */

require_once __DIR__ . '/bootstrap.php';

$src = file_get_contents( __DIR__ . '/../includes/npmp-email-settings.php' );
if ( ! preg_match( '/\tpublic static function status_after_gift\(.*?\n\t}\n/s', $src, $m ) ) {
	fwrite( STDERR, "could not find status_after_gift() in includes/npmp-email-settings.php\n" );
	exit( 1 );
}
eval( "class NPMP_Gift_Status_Extract {\n{$m[0]}\n}" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test harness: one method, no WordPress.

check( 'an Active subscriber stays Active', 'subscribed', NPMP_Gift_Status_Extract::status_after_gift( 'subscribed' ) );
check( 'Pending stays Pending', 'pending', NPMP_Gift_Status_Extract::status_after_gift( 'pending' ) );
check( 'Unsubscribed stays Unsubscribed', 'unsubscribed', NPMP_Gift_Status_Extract::status_after_gift( 'unsubscribed' ) );
check( 'Inactive stays Inactive', 'inactive', NPMP_Gift_Status_Extract::status_after_gift( 'inactive' ) );
check( 'Volunteer stays Volunteer', 'volunteer', NPMP_Gift_Status_Extract::status_after_gift( 'volunteer' ) );
check( 'Donor stays Donor', 'donor', NPMP_Gift_Status_Extract::status_after_gift( 'donor' ) );
check( 'no status becomes Donor', 'donor', NPMP_Gift_Status_Extract::status_after_gift( '' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
