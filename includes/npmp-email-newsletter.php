<?php
/**
 * Plugin Component: Email Newsletter
 * Enables Gutenberg-based newsletter creation, templates, tracking, and delivery queueing.
 */

defined('ABSPATH') || exit;

// Define constants if not already set
if (!defined('NPMP_NEWSLETTER_DIR')) {
    define('NPMP_NEWSLETTER_DIR', __DIR__ . '/email-newsletter/');
}
if (!defined('NPMP_NEWSLETTER_URL')) {
    define('NPMP_NEWSLETTER_URL', plugins_url('includes/email-newsletter/', dirname(__FILE__)));
}

// Load newsletter core components
$npmp_newsletter_files = [
    'class-newsletter-manager.php',
    'class-newsletter-tracker.php',
    'editor.php',
    'templates.php',
    'reports.php',
    'settings.php',
    'can-spam-shortcode.php',
];

foreach ($npmp_newsletter_files as $npmp_newsletter_file) {
    $path = NPMP_NEWSLETTER_DIR . $npmp_newsletter_file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// Highlight correct parent/submenu on CPT edit screens
add_filter('parent_file', 'npmp_newsletter_parent_file');
add_filter('submenu_file', 'npmp_newsletter_submenu_file');

function npmp_newsletter_parent_file($parent_file) {
    global $post_type;
    $screen = get_current_screen();

    if (in_array($post_type, ['npmp_newsletter', 'npmp_nl_template'])) {
        return 'npmp-newsletters';
    }

    if ($screen && 'toplevel_page_npmp-newsletters' === $screen->id) {
        return 'npmp-newsletters';
    }

    if ($screen && false !== strpos($screen->id, 'npmp-newsletter')) {
        return 'npmp-newsletters';
    }
    return $parent_file;
}

function npmp_newsletter_submenu_file($submenu_file) {
    global $post_type, $plugin_page;

    if ($post_type === 'npmp_newsletter') {
        return 'npmp-newsletters';
    }

    if ($post_type === 'npmp_nl_template') {
        return 'npmp_newsletter_templates';
    }

    if ('npmp_newsletter_archive' === $plugin_page) {
        return 'npmp_newsletter_archive';
    }

    return $submenu_file;
}

// Enqueue newsletter admin assets only when needed
add_action('admin_enqueue_scripts', 'npmp_enqueue_newsletter_assets');
function npmp_enqueue_newsletter_assets() {
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $valid_screens = [
        'toplevel_page_npmp-newsletters',
        'npmp-newsletters_page_npmp_newsletter_templates',
        'npmp-newsletters_page_npmp_newsletter_reports',
        'npmp-newsletters_page_npmp_newsletter_settings',
    ];

    if (
        in_array($screen->id, $valid_screens, true) ||
        in_array($screen->post_type, ['npmp_newsletter', 'npmp_nl_template'], true)
    ) {
        $editor_version = function_exists('npmp_get_asset_version') ? npmp_get_asset_version('includes/email-newsletter/assets/newsletter-editor.js') : null;
        $style_version  = function_exists('npmp_get_asset_version') ? npmp_get_asset_version('includes/email-newsletter/assets/newsletter-admin.css') : null;

        wp_enqueue_script(
            'npmp-newsletter-admin',
            NPMP_NEWSLETTER_URL . 'assets/newsletter-editor.js',
            ['jquery'],
            $editor_version,
            true
        );

        wp_enqueue_style(
            'npmp-newsletter-admin-css',
            NPMP_NEWSLETTER_URL . 'assets/newsletter-admin.css',
            [],
            $style_version
        );
    }
}
