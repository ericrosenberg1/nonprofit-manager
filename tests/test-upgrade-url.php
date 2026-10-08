<?php
/**
 * The campaign-tagged upgrade link (npmp_get_upgrade_url() in
 * includes/npmp-version.php) and the Pro menu previews that use it
 * (includes/npmp-pro-feature-pages.php).
 *
 * Every "Upgrade to Pro" link in the plugin carries utm_source=plugin,
 * utm_medium=upsell and a utm_campaign naming the screen, so GA4 can say
 * which screen sells. Pinned here: the default for callers that pass
 * nothing, how a campaign is cleaned, that the three preview pages are the
 * Pro screens by slug, and a source scan proving no call site in the plugin
 * still asks for the untagged link or hard-codes the pricing URL.
 *
 * Run: php tests/test-upgrade-url.php
 */

require_once __DIR__ . '/bootstrap.php';

function add_action( ...$args ) {}
function add_filter( ...$args ) {}

require_once dirname( __DIR__ ) . '/includes/npmp-version.php';
require_once dirname( __DIR__ ) . '/includes/npmp-pro-feature-pages.php';

$base = 'https://nonprofitmanager.app/pricing?utm_source=plugin&utm_medium=upsell&utm_campaign=';

echo "Default and pass-through\n";
check( 'no argument lands in the general campaign', $base . 'general', npmp_get_upgrade_url() );
check( 'a screen name passes through', $base . 'payment_gateways', npmp_get_upgrade_url( 'payment_gateways' ) );
check( 'hyphens and digits are kept', $base . 'wp-7', npmp_get_upgrade_url( 'wp-7' ) );

echo "\nCleaning\n";
check( 'upper case is lowered', $base . 'overview', npmp_get_upgrade_url( 'Overview' ) );
check( 'spaces and punctuation are stripped', $base . 'importcap', npmp_get_upgrade_url( ' import cap! ' ) );
check( 'an empty string falls back to general', $base . 'general', npmp_get_upgrade_url( '' ) );
check( 'null falls back to general', $base . 'general', npmp_get_upgrade_url( null ) );
check( 'nothing but punctuation falls back to general', $base . 'general', npmp_get_upgrade_url( '???' ) );
check( 'a number works', $base . '42', npmp_get_upgrade_url( 42 ) );

echo "\nThe URL itself\n";
$parts = wp_parse_url_lite( npmp_get_upgrade_url( 'segments' ) );
check( 'https', 'https', $parts['scheme'] );
check( 'pricing page on the product site', 'nonprofitmanager.app/pricing', $parts['host'] . $parts['path'] );
parse_str( $parts['query'], $q );
check( 'utm_source is plugin', 'plugin', $q['utm_source'] );
check( 'utm_medium is upsell', 'upsell', $q['utm_medium'] );
check( 'utm_campaign is the screen', 'segments', $q['utm_campaign'] );
check( 'exactly three query parameters', 3, count( $q ) );

echo "\nPro menu previews\n";
$pages = npmp_pro_feature_pages();
check( 'three previews', 3, count( $pages ) );
check( 'slugs are the Pro screens', array( 'npmp_automations', 'npmp_custom_fields', 'npmp_segments' ), array_keys( $pages ) );
foreach ( $pages as $slug => $page ) {
	check( "$slug has a menu label", true, '' !== trim( $page['menu'] ) );
	check( "$slug has a heading", true, '' !== trim( $page['title'] ) );
	check( "$slug line names Pro", true, false !== strpos( $page['line'], 'Nonprofit Manager Pro' ) );
	check( "$slug line is one short paragraph", true, strlen( $page['line'] ) < 320 );
	check( "$slug campaign is already clean", $base . $page['campaign'], npmp_get_upgrade_url( $page['campaign'] ) );
	check( "$slug campaign is not the default", true, 'general' !== $page['campaign'] );
}
$campaigns = array_column( $pages, 'campaign' );
check( 'each preview has its own campaign', count( $campaigns ), count( array_unique( $campaigns ) ) );

echo "\nSource scan: every call site names its screen\n";
$root  = dirname( __DIR__ );
$files = array_merge( array( $root . '/nonprofit-manager.php' ), php_files( $root . '/includes' ) );
$bare  = array();
$named = array();
$hard  = array();
foreach ( $files as $f ) {
	$src = file_get_contents( $f );
	$rel = substr( $f, strlen( $root ) + 1 );
	if ( preg_match_all( '/npmp_get_upgrade_url\(\s*\)/', $src, $m ) ) {
		$bare[] = $rel . ' x' . count( $m[0] );
	}
	if ( preg_match_all( "/npmp_get_upgrade_url\(\s*'([a-z0-9_-]+)'\s*\)/", $src, $m ) ) {
		foreach ( $m[1] as $c ) {
			$named[ $c ] = true;
		}
	}
	// The pricing URL belongs in the helper and nowhere else.
	if ( 'includes/npmp-version.php' !== $rel && false !== strpos( $src, 'nonprofitmanager.app/pricing' ) ) {
		$hard[] = $rel;
	}
	// A dynamic campaign (a variable) is fine in the preview renderer only.
	// The helper's own signature is the other place a $ follows the name.
	if ( ! in_array( $rel, array( 'includes/npmp-pro-feature-pages.php', 'includes/npmp-version.php' ), true ) && preg_match( '/npmp_get_upgrade_url\(\s*\$/', $src ) ) {
		$bare[] = $rel . ' (variable campaign)';
	}
}
check( 'no untagged upgrade link remains', array(), $bare );
check( 'no screen hard-codes the pricing URL', array(), $hard );
$expected = array(
	'plugins_list', 'overview', 'setup_wizard', 'thank_you_email', 'payment_gateways', 'stripe_recurring',
	'recurring_donations', 'membership_dues', 'email_provider', 'captcha', 'social_sharing', 'import_cap',
);
sort( $expected );
$found = array_keys( $named );
sort( $found );
check( 'the gates each carry their campaign', $expected, $found );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );

/**
 * parse_url() with the keys this test reads always present.
 *
 * @param string $url URL.
 * @return array
 */
function wp_parse_url_lite( $url ) {
	return array_merge( array( 'scheme' => '', 'host' => '', 'path' => '', 'query' => '' ), (array) parse_url( $url ) );
}

/**
 * Every .php file under a directory.
 *
 * @param string $dir Directory.
 * @return string[]
 */
function php_files( $dir ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$out[] = $file->getPathname();
		}
	}
	sort( $out );
	return $out;
}
