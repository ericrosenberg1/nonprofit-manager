<?php
defined('ABSPATH') || exit;

/**
 * Register the Newsletter Template CPT
 */
add_action('init', function () {
    register_post_type('npmp_nl_template', [
        'labels' => [
            'name' => 'Newsletter Templates',
            'singular_name' => 'Newsletter Template',
            'add_new_item' => 'Add New Template',
            'edit_item' => 'Edit Template',
            'new_item' => 'New Template',
            'view_item' => 'View Template',
            'search_items' => 'Search Templates',
            'not_found' => 'No templates found',
            'not_found_in_trash' => 'No templates found in trash',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // We'll manually register the menu
        'supports' => ['title', 'editor'],
        'show_in_rest' => true, // Enable Gutenberg
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'edit_posts'],
        'map_meta_cap' => true,
    ]);
});

/**
 * Add meta box for template settings
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'npmp_template_settings',
        esc_html__('Template Settings', 'nonprofit-manager'),
        'npmp_template_settings_meta_box',
        'npmp_nl_template',
        'side',
        'high'
    );
});

function npmp_template_settings_meta_box($post) {
    $template_type = get_post_meta($post->ID, '_npmp_template_type', true);
    $is_default = get_post_meta($post->ID, '_npmp_is_default_template', true);

    wp_nonce_field('npmp_template_settings', 'npmp_template_settings_nonce');

    ?>
    <p>
        <label for="npmp_template_type"><strong><?php esc_html_e('Template Type', 'nonprofit-manager'); ?></strong></label><br>
        <select name="npmp_template_type" id="npmp_template_type" class="widefat">
            <option value="wrapper" <?php selected($template_type, 'wrapper'); ?>><?php esc_html_e('Full Email Wrapper (Header + Footer)', 'nonprofit-manager'); ?></option>
            <option value="header" <?php selected($template_type, 'header'); ?>><?php esc_html_e('Header Only', 'nonprofit-manager'); ?></option>
            <option value="footer" <?php selected($template_type, 'footer'); ?>><?php esc_html_e('Footer Only', 'nonprofit-manager'); ?></option>
            <option value="block" <?php selected($template_type, 'block'); ?>><?php esc_html_e('Reusable Block', 'nonprofit-manager'); ?></option>
        </select>
    </p>

    <div id="npmp-wrapper-notice" style="<?php echo $template_type === 'wrapper' || $template_type === 'header' || empty($template_type) ? '' : 'display:none;'; ?>">
        <p class="description">
            <?php esc_html_e('Use the placeholder', 'nonprofit-manager'); ?>
            <code style="user-select:all;">[email_content]</code>
            <?php esc_html_e('where the newsletter content should be inserted.', 'nonprofit-manager'); ?>
        </p>
    </div>

    <p>
        <label>
            <input type="checkbox" name="npmp_is_default_template" value="1" <?php checked($is_default, '1'); ?>>
            <?php esc_html_e('Set as default template', 'nonprofit-manager'); ?>
        </label>
        <br>
        <span class="description"><?php esc_html_e('This template will be automatically applied to new newsletters.', 'nonprofit-manager'); ?></span>
    </p>

    <script>
    jQuery(document).ready(function($) {
        $('#npmp_template_type').on('change', function() {
            var type = $(this).val();
            if (type === 'wrapper' || type === 'header' || type === '') {
                $('#npmp-wrapper-notice').show();
            } else {
                $('#npmp-wrapper-notice').hide();
            }
        });
    });
    </script>
    <?php
}

/**
 * Save template meta
 */
add_action('save_post_npmp_nl_template', function ($post_id) {
    if (!isset($_POST['npmp_template_settings_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_template_settings_nonce'])), 'npmp_template_settings')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save template type
    if (isset($_POST['npmp_template_type'])) {
        update_post_meta($post_id, '_npmp_template_type', sanitize_text_field(wp_unslash($_POST['npmp_template_type'])));
    }

    // Handle default template setting
    if (isset($_POST['npmp_is_default_template']) && $_POST['npmp_is_default_template'] === '1') {
        // Unset any other default templates
        $current_defaults = get_posts([
            'post_type' => 'npmp_nl_template',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_npmp_is_default_template',
                    'value' => '1',
                ],
            ],
        ]);

        foreach ($current_defaults as $default_id) {
            if ($default_id != $post_id) {
                delete_post_meta($default_id, '_npmp_is_default_template');
            }
        }

        update_post_meta($post_id, '_npmp_is_default_template', '1');
    } else {
        delete_post_meta($post_id, '_npmp_is_default_template');
    }
});

/**
 * Render the Template Manager page under submenu
 */
function npmp_render_newsletter_templates() {
    npmp_verify_admin_access('edit_posts');

    npmp_admin_page_header(
        __('Newsletter Templates', 'nonprofit-manager'),
        __('Create reusable email templates with headers and footers. Templates use Gutenberg blocks for easy visual editing.', 'nonprofit-manager'),
        array(
            __('Create New Template', 'nonprofit-manager') => admin_url('post-new.php?post_type=npmp_nl_template')
        )
    );

    echo '<div class="notice notice-info inline" style="margin: 20px 0;"><p>';
    echo '<strong>' . esc_html__('Template Types:', 'nonprofit-manager') . '</strong><br>';
    echo '<strong>' . esc_html__('Full Email Wrapper:', 'nonprofit-manager') . '</strong> ' . esc_html__('Contains both header and footer with [email_content] placeholder', 'nonprofit-manager') . '<br>';
    echo '<strong>' . esc_html__('Header Only:', 'nonprofit-manager') . '</strong> ' . esc_html__('Top section with [email_content] placeholder at the end', 'nonprofit-manager') . '<br>';
    echo '<strong>' . esc_html__('Footer Only:', 'nonprofit-manager') . '</strong> ' . esc_html__('Bottom section appended after content', 'nonprofit-manager') . '<br>';
    echo '<strong>' . esc_html__('Reusable Block:', 'nonprofit-manager') . '</strong> ' . esc_html__('Insert anywhere using [npmp_nl_template id="123"]', 'nonprofit-manager');
    echo '</p></div>';

    $templates = get_posts([
        'post_type' => 'npmp_nl_template',
        'posts_per_page' => -1,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($templates) {
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Template Name', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Type', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Default', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Last Modified', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Actions', 'nonprofit-manager') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($templates as $template) {
            $type = get_post_meta($template->ID, '_npmp_template_type', true);
            $is_default = get_post_meta($template->ID, '_npmp_is_default_template', true);

            $type_labels = [
                'wrapper' => __('Full Email Wrapper', 'nonprofit-manager'),
                'header' => __('Header Only', 'nonprofit-manager'),
                'footer' => __('Footer Only', 'nonprofit-manager'),
                'block' => __('Reusable Block', 'nonprofit-manager'),
            ];

            echo '<tr>';
            echo '<td><strong>' . esc_html($template->post_title) . '</strong></td>';
            echo '<td>' . esc_html($type_labels[$type] ?? __('Not Set', 'nonprofit-manager')) . '</td>';
            echo '<td>' . ($is_default === '1' ? '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>' : '—') . '</td>';
            echo '<td>' . esc_html(get_the_date('M j, Y g:i A', $template)) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url(get_edit_post_link($template->ID)) . '" class="button button-small">' . esc_html__('Edit', 'nonprofit-manager') . '</a> ';
            echo '<a href="' . esc_url(get_delete_post_link($template->ID)) . '" class="button button-small">' . esc_html__('Delete', 'nonprofit-manager') . '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('No templates found. Create your first template to get started!', 'nonprofit-manager') . '</p>';
    }

    npmp_admin_page_footer();
}

/**
 * Get the default template
 */
function npmp_get_default_template() {
    $templates = get_posts([
        'post_type' => 'npmp_nl_template',
        'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => '_npmp_is_default_template',
                'value' => '1',
            ],
        ],
    ]);

    return !empty($templates) ? $templates[0] : null;
}

/**
 * Apply template to newsletter content
 *
 * @param string $content Newsletter content
 * @param int|null $template_id Optional template ID, uses default if not provided
 * @return string Wrapped content
 */
function npmp_apply_newsletter_template($content, $template_id = null) {
    // If no template specified, try to get default
    if (!$template_id) {
        $default_template = npmp_get_default_template();
        if ($default_template) {
            $template_id = $default_template->ID;
        } else {
            // No template, return content as-is
            return $content;
        }
    }

    $template = get_post($template_id);
    if (!$template || $template->post_type !== 'npmp_nl_template') {
        return $content;
    }

    $template_type = get_post_meta($template_id, '_npmp_template_type', true);
    $template_content = apply_filters('the_content', $template->post_content);

    // Handle different template types
    switch ($template_type) {
        case 'wrapper':
            // Replace [email_content] placeholder with actual content
            return str_replace('[email_content]', $content, $template_content);

        case 'header':
            // Add content after header
            return str_replace('[email_content]', $content, $template_content);

        case 'footer':
            // Add footer after content
            return $content . $template_content;

        case 'block':
        default:
            // Don't auto-apply blocks, they use shortcodes
            return $content;
    }
}

/**
 * Shortcode to insert a newsletter template
 * Usage: [npmp_nl_template id="123"]
 */
add_shortcode('npmp_nl_template', function ($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);

    $post = get_post($atts['id']);
    if (!$post || $post->post_type !== 'npmp_nl_template') return '';

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reuse core 'the_content' filters to match post formatting.
    return apply_filters('the_content', $post->post_content);
});

/**
 * Add [email_content] shortcode for template preview
 */
add_shortcode('email_content', function () {
    return '<div style="background: #f0f0f0; border: 2px dashed #666; padding: 20px; text-align: center; margin: 20px 0;">' .
           '<strong>' . esc_html__('Newsletter content will appear here', 'nonprofit-manager') . '</strong><br>' .
           '<span style="color: #666;">' . esc_html__('This placeholder is replaced with your newsletter content when sent.', 'nonprofit-manager') . '</span>' .
           '</div>';
});

/**
 * Add template selector to newsletter editor
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'npmp_newsletter_template',
        esc_html__('Email Template', 'nonprofit-manager'),
        'npmp_newsletter_template_meta_box',
        'npmp_newsletter',
        'side',
        'default'
    );
});

function npmp_newsletter_template_meta_box($post) {
    $selected_template = get_post_meta($post->ID, '_npmp_newsletter_template', true);
    $default_template = npmp_get_default_template();

    wp_nonce_field('npmp_newsletter_template', 'npmp_newsletter_template_nonce');

    $templates = get_posts([
        'post_type' => 'npmp_nl_template',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => [
            [
                'key' => '_npmp_template_type',
                'value' => 'block',
                'compare' => '!=',
            ],
        ],
    ]);

    ?>
    <p>
        <label for="npmp_newsletter_template"><strong><?php esc_html_e('Select Template', 'nonprofit-manager'); ?></strong></label><br>
        <select name="npmp_newsletter_template" id="npmp_newsletter_template" class="widefat">
            <option value=""><?php esc_html_e('Use Default Template', 'nonprofit-manager'); ?></option>
            <option value="none"><?php esc_html_e('No Template (Plain Content)', 'nonprofit-manager'); ?></option>
            <?php foreach ($templates as $template) : ?>
                <option value="<?php echo esc_attr($template->ID); ?>" <?php selected($selected_template, $template->ID); ?>>
                    <?php echo esc_html($template->post_title); ?>
                    <?php if ($default_template && $default_template->ID === $template->ID) : ?>
                        (<?php esc_html_e('Default', 'nonprofit-manager'); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <?php if ($default_template) : ?>
        <p class="description">
            <?php
            printf(
                /* translators: %s: Default template name */
                esc_html__('Current default template: %s', 'nonprofit-manager'),
                '<strong>' . esc_html($default_template->post_title) . '</strong>'
            );
            ?>
        </p>
    <?php else : ?>
        <p class="description" style="color: #d63638;">
            <?php esc_html_e('No default template set. Create one in Newsletter Templates.', 'nonprofit-manager'); ?>
        </p>
    <?php endif; ?>

    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=npmp_newsletter_templates')); ?>" class="button button-small">
            <?php esc_html_e('Manage Templates', 'nonprofit-manager'); ?>
        </a>
    </p>
    <?php
}

/**
 * Save newsletter template selection
 */
add_action('save_post_npmp_newsletter', function ($post_id) {
    if (!isset($_POST['npmp_newsletter_template_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_newsletter_template_nonce'])), 'npmp_newsletter_template')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['npmp_newsletter_template'])) {
        $template_value = sanitize_text_field(wp_unslash($_POST['npmp_newsletter_template']));
        if ($template_value === 'none' || $template_value === '') {
            update_post_meta($post_id, '_npmp_newsletter_template', $template_value);
        } else {
            update_post_meta($post_id, '_npmp_newsletter_template', absint($template_value));
        }
    }
});
