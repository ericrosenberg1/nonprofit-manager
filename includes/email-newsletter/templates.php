<?php
defined('ABSPATH') || exit;

/**
 * Register the Newsletter Template CPT
 */
add_action('init', function () {
    register_post_type('np_nl_template', [
        'labels' => [
            'name' => 'Newsletter Templates',
            'singular_name' => 'Newsletter Template',
            'add_new_item' => 'Add New Template',
            'edit_item' => 'Edit Template',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // We'll manually register the menu
        'supports' => ['title', 'editor'],
        'show_in_rest' => true,
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'edit_posts'],
        'map_meta_cap' => true,
    ]);
});

/**
 * Render the Template Manager page under submenu
 */
function np_render_newsletter_templates() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Newsletter Templates', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Create reusable blocks of content for headers, footers, or body sections. Insert them using the shortcode:', 'nonprofit-manager') . ' <code>[np_nl_template id="123"]</code>.</p>';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=np_nl_template')) . '" class="button button-primary">' . esc_html__('Create New Template', 'nonprofit-manager') . '</a>';
    echo '<hr>';

    $templates = get_posts([
        'post_type' => 'np_nl_template',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($templates) {
        echo '<ul>';
        foreach ($templates as $template) {
            echo '<li><strong>' . esc_html($template->post_title) . '</strong> – ID: ' . esc_html($template->ID) . ' – <a href="' . esc_url(get_edit_post_link($template->ID)) . '">' . esc_html__('Edit', 'nonprofit-manager') . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__('No templates found.', 'nonprofit-manager') . '</p>';
    }

    echo '</div>';
}

/**
 * Shortcode to insert a newsletter template
 * Usage: [np_nl_template id="123"]
 */
add_shortcode('np_nl_template', function ($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);

    $post = get_post($atts['id']);
    if (!$post || $post->post_type !== 'np_nl_template') return '';

    return apply_filters('the_content', $post->post_content);
});
