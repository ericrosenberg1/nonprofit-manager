<?php
/**
 * Wording and labels on Social Sharing, the Pro feature pages, staff access,
 * and the importers.
 *
 * - Screen names match the menu: "Payment Settings", "Switch Plugins", and
 *   "The Events Calendar" spelled out.
 * - US "license" on screen. A few importer lists said "licence".
 * - Core 7.1 calls it Users > Add User, not Add New.
 * - The MailPoet and TEC importers link back to Switch Plugins like the
 *   other importers do.
 * - Member and donation words go through npmp_label().
 * - Social Sharing's settings fields have labels and scoped headers.
 *
 * Run: php tests/test-admin-copy.php
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ ) . '/includes/';
$read = function ( $file ) use ( $root ) {
	return file_get_contents( $root . $file );
};

echo "Spelling and screen names\n";
foreach ( array( 'import/gift-importer.php', 'import/event-importer.php', 'import/import-hub.php', 'import/newsletter-importer.php', 'import/pmpro-importer.php' ) as $file ) {
	$strings = array();
	preg_match_all( "/(?:__|esc_html__|esc_html_e)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $read( $file ), $strings );
	check( "$file: no \"licence\" on screen", array(), preg_grep( '/licence/i', $strings[1] ) );
}
check( 'gift importer names Payment Settings', true, false !== strpos( $read( 'import/gift-importer.php' ), 'on the Payment Settings screen' ) );
check( 'hub h1 matches its menu name', true, false !== strpos( $read( 'import/import-hub.php' ), "echo '<h1>' . esc_html__( 'Switch Plugins', 'nonprofit-manager' ) . '</h1>';" ) );
check( 'TEC spelled out in the menu', false, strpos( $read( 'import/tec-importer.php' ), "'Import from TEC'" ) );
check( 'staff access says Users > Add User', true, false !== strpos( $read( 'npmp-staff-access.php' ), 'Users > Add User' ) && false === strpos( $read( 'npmp-staff-access.php' ), 'Users > Add New' ) );

echo "Back links\n";
check( 'MailPoet importer links back to its hub card', true, false !== strpos( $read( 'import/mailpoet-importer.php' ), "npmp_import_hub_url( 'mailpoet' )" ) );
check( 'TEC importer links back to its hub card', true, false !== strpos( $read( 'import/tec-importer.php' ), "npmp_import_hub_url( 'the-events-calendar' )" ) );
check( 'TEC hub source key matches the link', true, false !== strpos( $read( 'import/tec-hub-source.php' ), "\$sources['the-events-calendar']" ) );

echo "Label map\n";
check( 'Pro feature pages: no bare "members" in Groups', false, strpos( $read( 'npmp-pro-feature-pages.php' ), "'Put members in" ) );
check( 'Pro feature pages: badge through the shared header', true, false !== strpos( $read( 'npmp-pro-feature-pages.php' ), "npmp_admin_page_title( \$page['title'], array(), __( 'Pro', 'nonprofit-manager' ) );" ) );
check( 'staff access: member words from the map', true, false !== strpos( $read( 'npmp-staff-access.php' ), "npmp_label( 'members_lc' )" ) );
check( 'import h1 uses the member word', true, false !== strpos( $read( 'import/admin-import.php' ), "__( 'Import %s', 'nonprofit-manager' ), npmp_label( 'members' ) ) ); ?></h1>" ) );
check( 'import file input has a label', true, false !== strpos( $read( 'import/admin-import.php' ), '<label for="npmp-import-file">' ) );

echo "Social Sharing\n";
$social = $read( 'social-sharing/admin-social.php' );
check( 'share text has a label', true, false !== strpos( $social, '<label for="npmp_share_template">' ) && false !== strpos( $social, 'id="npmp_share_template"' ) );
check( 'row headers scoped', false, strpos( $social, '<th><' ) );
check( 'no inline style block', false, strpos( $social, '<style>' ) );

echo "Organization identity card\n";
$org = $read( 'onboarding/class-org-settings.php' );
foreach ( array( 'npmp_org_address_line2', 'npmp_org_city', 'npmp_org_state', 'npmp_org_postal_code' ) as $id ) {
	check( "$id has a label", true, false !== strpos( $org, '<label for="' . $id . '"' ) && false !== strpos( $org, 'id="' . $id . '"' ) );
}
check( 'required mark has words for screen readers', true, false !== strpos( $org, '<span class="npmp-required" aria-hidden="true">*</span><span class="screen-reader-text">' ) );
check( 'card uses the shared settings card', true, false !== strpos( $org, 'class="npmp-org-settings npmp-settings-card"' ) );
check( 'no inline styles', false, strpos( $org, 'style="' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
