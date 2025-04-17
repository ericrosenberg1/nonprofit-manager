<?php
/**
 * Plugin Component: Email Newsletter
 * Enables Gutenberg-based newsletter creation, templates, tracking, and delivery queueing.
 */

defined('ABSPATH') || exit;

// Define constants if not already set
if (!defined('NP_NEWSLETTER_DIR')) {
    define('NP_NEWSLETTER_DIR', __DIR__ . '/email-newsletter/');
}
if (!defined('NP_NEWSLETTER_URL')) {
    define('NP_NEWSLETTER_URL', plugins_url('includes/email-newsletter/', dirname(__FILE__)));
}

// Load newsletter core components
$newsletter_files = [
    'class-newsletter-manager.php',
    'class-newsletter-tracker.php',
    'editor.php',
    'templates.php',
    'reports.php',
    'settings.php',
    'can-spam-shortcode.php',
];

foreach ($newsletter_files as $file) {
    $path = NP_NEWSLETTER_DIR . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// Highlight correct parent/submenu on CPT edit screens
add_filter('parent_file', 'np_newsletter_parent_file');
add_filter('submenu_file', 'np_newsletter_submenu_file');

function np_newsletter_parent_file($parent_file) {
    global $post_type;
    if (in_array($post_type, ['np_newsletter', 'np_nl_template'])) {
        return 'np-newsletters';
    }
    return $parent_file;
}

function np_newsletter_submenu_file($submenu_file) {
    global $post_type;

    if ($post_type === 'np_newsletter') {
        return 'np-newsletters';
    }

    if ($post_type === 'np_nl_template') {
        return 'np-newsletter-templates';
    }

    return $submenu_file;
}

// Enqueue newsletter admin assets only when needed
add_action('admin_enqueue_scripts', 'np_enqueue_newsletter_assets');
function np_enqueue_newsletter_assets() {
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    $valid_screens = [
        'toplevel_page_np-newsletters',
        'np-newsletters_page_np-newsletter-templates',
        'np-newsletters_page_np-newsletter-reports',
        'np-newsletters_page_np-newsletter-settings',
    ];

    if (
        in_array($screen->id, $valid_screens, true) ||
        in_array($screen->post_type, ['np_newsletter', 'np_nl_template'], true)
    ) {
        wp_enqueue_script(
            'np-newsletter-admin',
            NP_NEWSLETTER_URL . 'assets/newsletter-editor.js',
            ['jquery'],
            null,
            true
        );

        wp_enqueue_style(
            'np-newsletter-admin-css',
            NP_NEWSLETTER_URL . 'assets/newsletter-admin.css'
        );
    }
}
