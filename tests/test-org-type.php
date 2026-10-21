<?php
/**
 * Kind of group and its label map (includes/npmp-org-type.php).
 *
 * Pins the option default, the sanitizer, the words for each kind, that the
 * nonprofit words match what the plugin showed before the map existed, and
 * that every npmp_label() key used anywhere in the plugin exists in the map.
 *
 * Run: php tests/test-org-type.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_options'] = array();
$GLOBALS['t_filters'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['t_filters'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['t_filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/npmp-org-type.php';

echo "Option default\n";
check( 'option name', 'npmp_org_type', NPMP_ORG_TYPE_OPTION );
check( 'unset option reads as nonprofit', 'nonprofit', npmp_get_org_type() );
$GLOBALS['t_options']['npmp_org_type'] = 'faith';
check( 'stored faith reads as faith', 'faith', npmp_get_org_type() );
$GLOBALS['t_options']['npmp_org_type'] = 'pirates';
check( 'unknown stored value reads as nonprofit', 'nonprofit', npmp_get_org_type() );
$GLOBALS['t_options']['npmp_org_type'] = array( 'club' );
check( 'non-string stored value reads as nonprofit', 'nonprofit', npmp_get_org_type() );
$GLOBALS['t_options']['npmp_org_type'] = ' Club ';
check( 'stored value is trimmed and lowercased', 'club', npmp_get_org_type() );

echo "\nSaving\n";
$GLOBALS['t_options'] = array();
check( 'set returns the slug saved', 'sports', npmp_set_org_type( 'sports' ) );
check( 'set stores the slug', 'sports', $GLOBALS['t_options']['npmp_org_type'] );
check( 'set with junk stores nonprofit', 'nonprofit', npmp_set_org_type( '<script>' ) );
check( 'set with empty stores nonprofit', 'nonprofit', npmp_set_org_type( '' ) );

echo "\nWizard choices\n";
$types = npmp_org_types();
check( 'six kinds, in wizard order', array( 'nonprofit', 'club', 'school', 'sports', 'faith', 'hoa' ), array_keys( $types ) );
check( 'nonprofit label', 'Nonprofit', $types['nonprofit'] );
check( 'club label', 'Club or association', $types['club'] );
check( 'school label', 'School group (PTO, PTA, booster club)', $types['school'] );
check( 'sports label', 'Sports team or league', $types['sports'] );
check( 'faith label', 'Faith community', $types['faith'] );
check( 'hoa label', 'Neighborhood or HOA', $types['hoa'] );
foreach ( array_keys( $types ) as $slug ) {
	check( "every slug survives the sanitizer: $slug", $slug, npmp_sanitize_org_type( $slug ) );
}

echo "\nLabel map\n";
$keys = array_keys( npmp_org_labels( 'nonprofit' ) );
sort( $keys );
foreach ( array_keys( $types ) as $slug ) {
	$labels = npmp_org_labels( $slug );
	$these  = array_keys( $labels );
	sort( $these );
	check( "$slug has the same keys as nonprofit", $keys, $these );
	$empty = array_filter( $labels, static function ( $v ) { return ! is_string( $v ) || '' === trim( $v ); } );
	check( "$slug has no empty words", array(), $empty );
	check( "$slug lowercase forms are lowercase", true, $labels['donations_lc'] === strtolower( $labels['donations'] ) && $labels['levels_lc'] === strtolower( $labels['levels'] ) && $labels['org_lc'] === strtolower( $labels['org'] ) );
	check( "$slug never says nonprofit unless it is one", 'nonprofit' === $slug, false !== stripos( implode( ' ', $labels ), 'nonprofit' ) );
}

// The nonprofit column is what every site showed before the setting existed.
$np = npmp_org_labels( 'nonprofit' );
check( 'nonprofit: menu name', 'Nonprofit Manager', $np['app_name'] );
check( 'nonprofit: Donors', 'Donors', $np['donors'] );
check( 'nonprofit: Donations', 'Donations', $np['donations'] );
check( 'nonprofit: Donation', 'Donation', $np['donation'] );
check( 'nonprofit: Members', 'Members', $np['members'] );
check( 'nonprofit: Membership Levels', 'Membership Levels', $np['levels'] );

$club = npmp_org_labels( 'club' );
check( 'club: menu name', 'Club Manager', $club['app_name'] );
check( 'club: Supporters', 'Supporters', $club['donors'] );
check( 'club: Contributions', 'Contributions', $club['donations'] );
check( 'club: Dues Levels', 'Dues Levels', $club['levels'] );
check( 'faith: Gifts', 'Gifts', npmp_org_labels( 'faith' )['donations'] );
check( 'faith: Givers', 'Givers', npmp_org_labels( 'faith' )['donors'] );
check( 'school keeps Donations', 'Donations', npmp_org_labels( 'school' )['donations'] );
check( 'hoa: Association Manager', 'Association Manager', npmp_org_labels( 'hoa' )['app_name'] );
check( 'unknown type gets the nonprofit words', $np, npmp_org_labels( 'pirates' ) );

echo "\nnpmp_label\n";
$GLOBALS['t_options'] = array();
check( 'default site: donors', 'Donors', npmp_label( 'donors' ) );
$GLOBALS['t_options']['npmp_org_type'] = 'club';
check( 'club site: donors', 'Supporters', npmp_label( 'donors' ) );
check( 'club site: donation_lc', 'contribution', npmp_label( 'donation_lc' ) );
check( 'unknown key comes back as itself', 'no_such_key', npmp_label( 'no_such_key' ) );

add_filter(
	'npmp_org_labels',
	static function ( $labels, $type ) {
		if ( 'club' === $type ) {
			$labels['donors'] = 'Patrons';
		}
		return $labels;
	},
	10,
	2
);
check( 'filter can change a word', 'Patrons', npmp_label( 'donors' ) );
check( 'filter leaves other words alone', 'Contributions', npmp_label( 'donations' ) );
$GLOBALS['t_filters']['npmp_org_labels'] = array( static function () { return 'junk'; } );
check( 'a filter that returns junk is ignored', 'Supporters', npmp_label( 'donors' ) );
$GLOBALS['t_filters'] = array();

echo "\nMenu screen IDs stay fixed\n";
$GLOBALS['admin_page_hooks'] = array( 'npmp_main' => 'club-manager' );
npmp_pin_menu_hook( 'npmp_main', 'nonprofit-manager' );
check( 'pin restores the old hook base', 'nonprofit-manager', $GLOBALS['admin_page_hooks']['npmp_main'] );
$main = file_get_contents( dirname( __DIR__ ) . '/nonprofit-manager.php' );
foreach ( array(
	'npmp_main'            => 'nonprofit-manager',
	'npmp_membership'      => 'membership',
	'npmp-newsletters'     => 'email-newsletters',
	'npmp_donations_group' => 'donations',
) as $slug => $base ) {
	check( "menu $slug is pinned to $base", 1, substr_count( $main, "npmp_pin_menu_hook( '$slug', '$base' );" ) );
}

echo "\nEvery npmp_label key in use exists\n";
$used = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes' ) );
$srcs = array( dirname( __DIR__ ) . '/nonprofit-manager.php' );
foreach ( $it as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() ) {
		$srcs[] = $f->getPathname();
	}
}
$pro = dirname( __DIR__, 2 ) . '/nonprofit-manager-pro/includes';
if ( is_dir( $pro ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pro ) ) as $f ) {
		if ( $f->isFile() && 'php' === $f->getExtension() ) {
			$srcs[] = $f->getPathname();
		}
	}
}
foreach ( $srcs as $path ) {
	if ( preg_match_all( "/npmp_(?:pro_)?label\\(\\s*'([a-z_]+)'/", file_get_contents( $path ), $m ) ) {
		foreach ( $m[1] as $k ) {
			$used[ $k ][] = basename( $path );
		}
	}
}
$missing = array_diff( array_keys( $used ), $keys );
check( 'keys used across free and Pro', array(), array_values( $missing ) );
check( 'the map is used in more than a handful of places', true, array_sum( array_map( 'count', $used ) ) > 40 );

echo "\nNothing internal renamed\n";
$types_src = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-content-types.php' );
check( 'donation post type is still npmp_donation', 1, preg_match( "/register_post_type\\(\\s*'npmp_donation',/", $types_src ) );
foreach ( array( 'npmp_main', 'npmp_membership', 'npmp_members', 'npmp_donations_group', 'npmp_donation_settings' ) as $slug ) {
	check( "menu slug $slug kept", true, false !== strpos( $main, "'$slug'" ) );
}
$wizard = file_get_contents( dirname( __DIR__ ) . '/includes/npmp-setup-wizard.php' );
check( 'wizard still saves npmp_enabled_features', 1, preg_match( "/update_option\\( 'npmp_enabled_features'/", $wizard ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
