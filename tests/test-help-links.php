<?php
/**
 * Help center links (includes/npmp-help-links.php).
 *
 * Pinned here: every mapped screen points at a slug-shaped article, the
 * screen lookup reads the `page` value first and then the post type and
 * taxonomy, unmapped screens get no link, the title link opens the right
 * article in a new tab with escaped markup, the Help panel tab goes only on
 * mapped screens, and npmp_admin_page_title() prints the link before
 * hr.wp-header-end. When the site repo sits beside this one, every slug in
 * the map must have an article in wordpress/help/articles/, so a renamed
 * article can't leave a dead link in the plugin.
 *
 * Run: php tests/test-help-links.php
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['t_screen'] = null;

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
function apply_filters( $hook, $value ) {
	return $value;
}
function sanitize_title( $s ) {
	return strtolower( preg_replace( '/[^A-Za-z0-9-]+/', '-', trim( (string) $s ) ) );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function wp_unslash( $s ) {
	return $s;
}
function esc_url( $u ) {
	return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function get_current_screen() {
	return $GLOBALS['t_screen'];
}

/** Records what the Help panel receives. */
class T_Screen {
	public $post_type = '';
	public $taxonomy  = '';
	public $tabs      = array();
	public $sidebar   = '';
	public function add_help_tab( $tab ) {
		$this->tabs[] = $tab;
	}
	public function set_help_sidebar( $html ) {
		$this->sidebar = $html;
	}
}

require_once dirname( __DIR__ ) . '/includes/npmp-help-links.php';
require_once dirname( __DIR__ ) . '/includes/npmp-admin-helpers.php';

echo "Map\n";
$links = npmp_help_links();
check( 'map has entries', true, count( $links ) > 20 );
foreach ( $links as $key => $slug ) {
	check( "$key maps to a slug-shaped article", 1, preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) );
}

echo "\nURLs\n";
check( 'article url', 'https://nonprofitmanager.app/help/setup-wizard/?utm_source=plugin&utm_medium=wp-admin&utm_campaign=help', npmp_help_url( 'setup-wizard' ) );
check( 'home url', 'https://nonprofitmanager.app/help/?utm_source=plugin&utm_medium=wp-admin&utm_campaign=help', npmp_help_url() );

echo "\nScreen lookup\n";
$_GET = array( 'page' => 'npmp_email_settings' );
check( 'page value wins', 'email-delivery-settings', npmp_help_slug_for_screen() );
$_GET = array( 'page' => 'someone_elses_page' );
check( 'unmapped page, no screen', '', npmp_help_slug_for_screen() );
$_GET                 = array();
$s                    = new T_Screen();
$s->post_type         = 'npmp_event';
$GLOBALS['t_screen']  = $s;
check( 'event screens', 'create-an-event', npmp_help_slug_for_screen() );
$s->taxonomy = 'npmp_event_tag';
check( 'taxonomy beats post type', 'event-categories-and-tags', npmp_help_slug_for_screen() );
$s2            = new T_Screen();
$s2->post_type = 'post';
$GLOBALS['t_screen'] = $s2;
check( 'core screens get nothing', '', npmp_help_slug_for_screen() );

echo "\nTitle link\n";
$_GET = array( 'page' => 'npmp_license' );
$link = npmp_help_title_link();
check( 'link points at the article', true, false !== strpos( $link, 'help/activate-pro-license/' ) );
check( 'link opens a new tab safely', true, false !== strpos( $link, 'target="_blank" rel="noopener"' ) );
check( 'link says so to screen readers', true, false !== strpos( $link, 'opens in a new tab' ) );
$_GET                = array( 'page' => 'not_ours' );
$GLOBALS['t_screen'] = null;
check( 'no link on an unmapped screen', '', npmp_help_title_link() );

$_GET = array( 'page' => 'npmp_general_settings' );
ob_start();
npmp_admin_page_title( 'General Settings' );
$html = ob_get_clean();
check( 'title helper prints the link before hr.wp-header-end', true, strpos( $html, 'npmp-help-link' ) < strpos( $html, 'wp-header-end' ) && false !== strpos( $html, 'npmp-help-link' ) );

echo "\nHelp panel\n";
$_GET  = array( 'page' => 'npmp_groups' );
$panel = new T_Screen();
npmp_help_add_tab( $panel );
check( 'mapped screen gets one tab', 1, count( $panel->tabs ) );
check( 'tab links the article', true, false !== strpos( $panel->tabs[0]['content'], 'help/contact-groups/' ) );
check( 'sidebar links the help center', true, false !== strpos( $panel->sidebar, 'nonprofitmanager.app/help/?' ) );
$_GET  = array( 'page' => 'not_ours' );
$panel = new T_Screen();
npmp_help_add_tab( $panel );
check( 'unmapped screen gets no tab', 0, count( $panel->tabs ) );

$articles = dirname( __DIR__ ) . '/nonprofit-manager-site/wordpress/help/articles';
if ( is_dir( $articles ) ) {
	echo "\nEvery slug has an article\n";
	$have = array();
	foreach ( glob( $articles . '/*/*.md' ) as $file ) {
		$have[ basename( $file, '.md' ) ] = true;
	}
	foreach ( array_unique( array_merge( array_values( $links ), array( 'shortcodes' ) ) ) as $slug ) {
		check( "article exists: $slug", true, isset( $have[ $slug ] ) );
	}
} else {
	echo "\n(site repo not beside this one, article check skipped)\n";
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
