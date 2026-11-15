<?php
/**
 * Wording on the Overview, Social Sharing, and Convert to Event screens, and
 * translator comments in the Powered by line and Calendar Settings.
 *
 * - The Overview's Donations line promised a record of each gift. Free
 *   records Stripe gifts only, so it now says what the setup wizard says.
 * - The Pro upsell offered custom fields for donations. Pro's custom fields
 *   cover members and events.
 * - make-pot attaches a translators comment only when it sits right before
 *   the gettext call. The Powered by comments sat two lines up, so
 *   translators never saw them.
 * - "View your calendar" used two bare %s for one URL. It uses %1$s twice.
 * - Success notices drop "successfully" and the exclamation points.
 *
 * Run: php tests/test-core-copy.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ ) . '/includes/';
$read = function ( $file ) use ( $root ) {
	return file_get_contents( $root . $file );
};

echo "Overview\n";
$overview = $read( 'npmp-admin-settings.php' );
check( 'no "record of each one" promise', false, strpos( $overview, 'keep a record of each one' ) );
check( 'Donations line matches the wizard', true, false !== strpos( $overview, "'Take gifts online through Stripe, PayPal, or Venmo. Stripe gifts land in your records.'" ) );
check( 'wizard still says the same', true, false !== strpos( $read( 'npmp-setup-wizard.php' ), "'Take gifts online through Stripe, PayPal, or Venmo. Stripe gifts land in your records.'" ) );
check( 'upsell: custom fields for members and events', true, false !== strpos( $overview, "__( 'Custom fields for %s and events', 'nonprofit-manager' ), npmp_label( 'members_lc' )" ) );
check( 'upsell: no custom fields for donations', false, strpos( $overview, "'Custom fields for %1\$s and %2\$s'" ) );

echo "Powered by\n";
$powered = $read( 'npmp-powered-by.php' );
check( 'two Powered by calls', 2, substr_count( $powered, "esc_html__( 'Powered by %s', 'nonprofit-manager' )" ) );
check( 'each has its translators comment right before it', 2, substr_count( $powered, "/* translators: %s: linked plugin name. */ esc_html__( 'Powered by %s'" ) );

echo "Calendar Settings\n";
$cal = $read( 'npmp-calendar.php' );
check( 'calendar link uses %1$s twice', true, false !== strpos( $cal, "'View your calendar: <a href=\"%1\$s\" target=\"_blank\" rel=\"noopener\">%1\$s</a>'" ) );

echo "Notices\n";
$social = $read( 'social-sharing/admin-social.php' );
check( 'no "successfully" on Social Sharing', false, stripos( $social, 'successfully' ) );
check( 'Network connected.', true, false !== strpos( $social, "'Network connected.'" ) );
check( 'Test share sent.', true, false !== strpos( $social, "'Test share sent.'" ) );
check( 'Event created. without the exclamation point', true, false !== strpos( $read( 'npmp-convert-to-event.php' ), "__( 'Event created.', 'nonprofit-manager' )" ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
