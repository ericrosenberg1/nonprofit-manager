<?php
defined('ABSPATH') || exit;

/**
 * Register Newsletter CPT
 */
add_action('init', 'npmp_register_newsletter_cpt');
function npmp_register_newsletter_cpt() {
    register_post_type('npmp_newsletter', [
        'labels' => [
            'name' => 'Newsletters',
            'singular_name' => 'Newsletter',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // We add it manually via our submenu
        'menu_position' => 56,
        'supports' => ['title', 'editor'],
        'show_in_rest' => true, // enables Gutenberg
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'edit_posts'], // allow Editor+ roles
        'map_meta_cap' => true,
    ]);
}

add_action('init', 'npmp_register_newsletter_taxonomy');
function npmp_register_newsletter_taxonomy() {
    register_taxonomy(
        'npmp_newsletter_topic',
        ['npmp_newsletter'],
        [
            'labels' => [
                'name' => __('Newsletter Topics', 'nonprofit-manager'),
                'singular_name' => __('Newsletter Topic', 'nonprofit-manager'),
                'search_items' => __('Search Topics', 'nonprofit-manager'),
                'all_items' => __('All Topics', 'nonprofit-manager'),
                'edit_item' => __('Edit Topic', 'nonprofit-manager'),
                'update_item' => __('Update Topic', 'nonprofit-manager'),
                'add_new_item' => __('Add New Topic', 'nonprofit-manager'),
                'new_item_name' => __('New Topic Name', 'nonprofit-manager'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => true,
            'rewrite' => false,
            'hierarchical' => false,
        ]
    );
}

/**
 * Render New Newsletter Page
 */
function npmp_render_newsletter_editor() {
    npmp_verify_admin_access('edit_posts');
    echo '<div class="wrap"><h1>' . esc_html__('New Newsletter', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Use the editor below to create a newsletter. You can send a test email or queue it for delivery.', 'nonprofit-manager') . '</p>';
    echo '<a href="' . esc_url(admin_url('post-new.php?post_type=npmp_newsletter')) . '" class="button button-primary">' . esc_html__('Create New Newsletter', 'nonprofit-manager') . '</a>';
    echo '<hr>';

    // List past newsletters
    $newsletters = get_posts([
        'post_type' => 'npmp_newsletter',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($newsletters) {
        echo '<h2>' . esc_html__('Recent Newsletters', 'nonprofit-manager') . '</h2><ul>';
        foreach ($newsletters as $post) {
            echo '<li><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html($post->post_title) . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__('No newsletters created yet.', 'nonprofit-manager') . '</p>';
    }

    echo '</div>';
}

/**
 * Render the Newsletter Archive admin page.
 */
function npmp_render_newsletter_archive() {
    npmp_verify_admin_access('edit_posts');

    $per_page       = 20;
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query parameters drive archive filtering and are sanitized.
    $paged          = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $selected_topic = isset($_GET['npmp_topic']) ? sanitize_text_field(wp_unslash($_GET['npmp_topic'])) : '';
    $selected_status = isset($_GET['npmp_status']) ? sanitize_key(wp_unslash($_GET['npmp_status'])) : 'any';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    $query_args = [
        'post_type'      => 'npmp_newsletter',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    if ('any' !== $selected_status && '' !== $selected_status) {
        $query_args['post_status'] = $selected_status;
    } else {
        $query_args['post_status'] = ['publish', 'draft', 'pending', 'future'];
    }

    if ($selected_topic) {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Topic filtering relies on taxonomy queries.
        $query_args['tax_query'] = [
            [
                'taxonomy' => 'npmp_newsletter_topic',
                'field'    => 'slug',
                'terms'    => $selected_topic,
            ],
        ];
    }

    $query = new WP_Query($query_args);

    $topics = get_terms(
        [
            'taxonomy'   => 'npmp_newsletter_topic',
            'hide_empty' => false,
        ]
    );

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Newsletter Archive', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Browse past newsletters, duplicate successful campaigns, and keep track of who received each message.', 'nonprofit-manager') . '</p>';

    echo '<form method="get" class="tablenav top" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="npmp_newsletter_archive">';
    echo '<div class="alignleft actions">';

    echo '<label class="screen-reader-text" for="npmp_topic">' . esc_html__('Filter by topic', 'nonprofit-manager') . '</label>';
    echo '<select name="npmp_topic" id="npmp_topic">';
    echo '<option value="">' . esc_html__('All topics', 'nonprofit-manager') . '</option>';
    foreach ($topics as $topic) {
        echo '<option value="' . esc_attr($topic->slug) . '"' . selected($selected_topic, $topic->slug, false) . '>' . esc_html($topic->name) . '</option>';
    }
    echo '</select> ';

    echo '<label class="screen-reader-text" for="npmp_status">' . esc_html__('Filter by status', 'nonprofit-manager') . '</label>';
    echo '<select name="npmp_status" id="npmp_status">';
    $statuses = [
        'any'     => __('All statuses', 'nonprofit-manager'),
        'publish' => __('Published', 'nonprofit-manager'),
        'draft'   => __('Draft', 'nonprofit-manager'),
        'pending' => __('Pending', 'nonprofit-manager'),
    ];
    foreach ($statuses as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($selected_status, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> ';

    submit_button(esc_html__('Filter', 'nonprofit-manager'), 'secondary', '', false);
    echo '</div>';
    echo '</form>';

    if ($query->have_posts()) {
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Title', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Topics', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Audience', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Last Sent', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Recipients', 'nonprofit-manager') . '</th>';
        echo '<th>' . esc_html__('Actions', 'nonprofit-manager') . '</th>';
        echo '</tr></thead><tbody>';

        while ($query->have_posts()) {
            $query->the_post();
            $post_id       = get_the_ID();
            $topic_terms   = get_the_terms($post_id, 'npmp_newsletter_topic');
            $topic_labels  = [];
            if (!is_wp_error($topic_terms) && $topic_terms) {
                foreach ($topic_terms as $term) {
                    $topic_labels[] = $term->name;
                }
            }

            $audience = npmp_newsletter_get_audience_label($post_id);
            $sent_at  = get_post_meta($post_id, '_npmp_newsletter_queued_at', true);
            $recipient_total = get_post_meta($post_id, '_npmp_newsletter_recipient_total', true);
            $recipient_total = $recipient_total ? intval($recipient_total) : '—';

            $duplicate_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'        => 'npmp_duplicate_newsletter',
                        'newsletter_id' => $post_id,
                    ],
                    admin_url('admin.php')
                ),
                'npmp_duplicate_newsletter_' . $post_id
            );

            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html(get_the_title()) . '</a></td>';
            echo '<td>' . esc_html($topic_labels ? implode(', ', $topic_labels) : '—') . '</td>';
            echo '<td>' . esc_html($audience) . '</td>';
            echo '<td>' . esc_html($sent_at ? $sent_at : '—') . '</td>';
            echo '<td>' . esc_html($recipient_total) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(get_preview_post_link($post_id)) . '" target="_blank" rel="noopener">' . esc_html__('Preview', 'nonprofit-manager') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url($duplicate_url) . '">' . esc_html__('Duplicate', 'nonprofit-manager') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $pagination = paginate_links(
            [
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => __('&laquo;', 'nonprofit-manager'),
                'next_text' => __('&raquo;', 'nonprofit-manager'),
                'total'     => $query->max_num_pages,
                'current'   => $paged,
            ]
        );

        if ($pagination) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
        }
    } else {
        echo '<p>' . esc_html__('No newsletters found for the selected filters.', 'nonprofit-manager') . '</p>';
    }

    wp_reset_postdata();
    echo '</div>';
}

/**
 * Add Sidebar Metabox to Editor
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'npmp_newsletter_send_controls',
        esc_html__('Send Newsletter', 'nonprofit-manager'),
        'npmp_newsletter_send_controls_html',
        'npmp_newsletter',
        'side',
        'high'
    );
});

function npmp_newsletter_send_controls_html($post) {
    $send_nonce        = wp_create_nonce('npmp_send_newsletter_' . $post->ID);
    $test_nonce        = wp_create_nonce('npmp_send_test_' . $post->ID);
    $meta_nonce        = wp_create_nonce('npmp_save_newsletter_meta_' . $post->ID);
    $levels_option     = array_map('sanitize_text_field', (array) get_option('npmp_membership_levels', []));
    sort($levels_option);
    $selected_levels   = get_post_meta($post->ID, '_npmp_newsletter_levels', true);
    $selected_levels   = is_array($selected_levels) ? array_map('sanitize_text_field', $selected_levels) : [];
    $preheader         = get_post_meta($post->ID, '_npmp_newsletter_preheader', true);
    $queued_at         = get_post_meta($post->ID, '_npmp_newsletter_queued_at', true);
    $recipient_total   = get_post_meta($post->ID, '_npmp_newsletter_recipient_total', true);
    $audience_label    = npmp_newsletter_get_audience_label($post->ID);
    $level_counts      = class_exists('NPMP_Member_Manager') ? NPMP_Member_Manager::get_instance()->count_by_level() : [];
    $count_all         = class_exists('NPMP_Member_Manager') ? NPMP_Member_Manager::get_instance()->count_members(['status' => 'subscribed', 'per_page' => -1]) : 0;

    echo '<input type="hidden" name="npmp_newsletter_meta_nonce" value="' . esc_attr($meta_nonce) . '">';

    if ($queued_at) {
        echo '<p><strong>' . esc_html__('Last queued:', 'nonprofit-manager') . '</strong> ' . esc_html($queued_at) . '</p>';
        if ($recipient_total) {
            echo '<p><strong>' . esc_html__('Recipients queued:', 'nonprofit-manager') . '</strong> ' . esc_html((int) $recipient_total) . '</p>';
        }
    }

    echo '<p><label for="npmp-newsletter-preheader"><strong>' . esc_html__('Preheader', 'nonprofit-manager') . '</strong></label>';
    echo '<input type="text" id="npmp-newsletter-preheader" name="npmp_newsletter_preheader" class="widefat" value="' . esc_attr($preheader) . '" placeholder="' . esc_attr__('Short summary that appears next to the subject line.', 'nonprofit-manager') . '"></p>';

    echo '<fieldset class="npmp-newsletter-audience">';
    echo '<legend><strong>' . esc_html__('Audience', 'nonprofit-manager') . '</strong></legend>';
    echo '<p>' . esc_html__('Select who should receive this newsletter.', 'nonprofit-manager') . '</p>';

    // All Members option
    $all_checked = empty($selected_levels);
    echo '<label style="display:block;margin-bottom:8px;font-weight:600;"><input type="checkbox" class="npmp-newsletter-level npmp-newsletter-all" data-label="' . esc_attr__('All Members', 'nonprofit-manager') . '" name="npmp_newsletter_levels[]" value="__all__"' . checked(true, $all_checked, false) . '> ' . esc_html__('All Members', 'nonprofit-manager');
    if ($count_all) {
        echo ' <span class="description">(' . esc_html($count_all) . ')</span>';
    }
    echo '</label>';

    echo '<div class="npmp-newsletter-specific-levels" style="' . ($all_checked ? 'opacity:0.5;pointer-events:none;' : '') . '">';
    echo '<p class="description" style="margin-top:0;">' . esc_html__('Or select specific groups:', 'nonprofit-manager') . '</p>';

    foreach ($levels_option as $level_label) {
        $value   = $level_label;
        $checked = in_array($value, $selected_levels, true);
        $count   = isset($level_counts[$value]) ? (int) $level_counts[$value] : 0;
        echo '<label style="display:block;margin-bottom:4px;margin-left:20px;"><input type="checkbox" class="npmp-newsletter-level npmp-newsletter-specific" data-label="' . esc_attr($level_label) . '" name="npmp_newsletter_levels[]" value="' . esc_attr($value) . '"' . checked(true, $checked, false) . ' ' . disabled($all_checked, true, false) . '> ' . esc_html($level_label);
        if ($count) {
            echo ' <span class="description">(' . esc_html($count) . ')</span>';
        }
        echo '</label>';
    }

    $none_checked = in_array('__none__', $selected_levels, true);
    $none_count   = isset($level_counts['']) ? (int) $level_counts[''] : 0;
    echo '<label style="display:block;margin-bottom:4px;margin-left:20px;"><input type="checkbox" class="npmp-newsletter-level npmp-newsletter-specific" data-label="' . esc_attr__('Members without a level', 'nonprofit-manager') . '" name="npmp_newsletter_levels[]" value="__none__"' . checked(true, $none_checked, false) . ' ' . disabled($all_checked, true, false) . '> ' . esc_html__('Members without a level', 'nonprofit-manager');
    if ($none_count) {
        echo ' <span class="description">(' . esc_html($none_count) . ')</span>';
    }
    echo '</label>';
    echo '</div>';

    if ($count_all) {
        echo '<p class="description">' . esc_html(
            sprintf(
                /* translators: %d: Total number of subscribed members. */
                __('Total subscribed members: %d', 'nonprofit-manager'),
                $count_all
            )
        ) . '</p>';
    }

    echo '</fieldset>';

    echo '<p><strong>' . esc_html__('Current selection:', 'nonprofit-manager') . '</strong> <span class="npmp-newsletter-audience-label" data-default="' . esc_attr__('All subscribed members', 'nonprofit-manager') . '">' . esc_html($audience_label) . '</span></p>';

    $preview_link = get_preview_post_link($post);
    if ($preview_link) {
        echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($preview_link) . '">' . esc_html__('Preview in new tab', 'nonprofit-manager') . '</a></p>';
    }

    echo '<h4>' . esc_html__('Pre-send checklist', 'nonprofit-manager') . '</h4>';
    echo '<ul class="npmp-newsletter-checklist">';
    echo '<li>' . esc_html__('Review the preview link above for formatting issues.', 'nonprofit-manager') . '</li>';
    echo '<li>' . esc_html__('Confirm the audience matches your intent.', 'nonprofit-manager') . '</li>';
    echo '<li>' . esc_html__('Send a test email to yourself before mailing members.', 'nonprofit-manager') . '</li>';
    echo '</ul>';

    echo '<p><button type="button" class="button" id="npmp-send-test" data-postid="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($test_nonce) . '" data-default="' . esc_attr__('Send Test Email', 'nonprofit-manager') . '" data-working="' . esc_attr__('Sending…', 'nonprofit-manager') . '">' . esc_html__('Send Test Email', 'nonprofit-manager') . '</button></p>';
    echo '<p><button type="button" class="button button-primary" id="npmp-send-newsletter" data-postid="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($send_nonce) . '" data-confirm="' . esc_attr__('Queue this newsletter for delivery to the selected members?', 'nonprofit-manager') . '" data-default="' . esc_attr__('Send to Selected Members', 'nonprofit-manager') . '" data-working="' . esc_attr__('Queuing…', 'nonprofit-manager') . '">' . esc_html__('Send to Selected Members', 'nonprofit-manager') . '</button></p>';
}

/**
 * Enqueue Admin JS - Handled by npmp-scripts.php
 */

/**
 * AJAX: Send Test Email
 */
add_action('wp_ajax_npmp_send_test_newsletter', function () {
    $post_id = isset($_POST['post_id']) ? (int) sanitize_text_field(wp_unslash($_POST['post_id'])) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (
        empty($post_id) ||
        !current_user_can('edit_post', $post_id) ||
        !wp_verify_nonce($nonce, 'npmp_send_test_' . $post_id)
    ) {
        wp_send_json_error(esc_html__('Permission denied', 'nonprofit-manager'));
    }

    $levels    = array_key_exists('levels', $_POST) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['levels'])) : null;
    $preheader = array_key_exists('preheader', $_POST) ? sanitize_textarea_field(wp_unslash($_POST['preheader'])) : null;

    npmp_newsletter_store_meta($post_id, $levels, $preheader);

    $user = wp_get_current_user();
    $sent = NPMP_Newsletter_Manager::send_test_email($post_id, $user->user_email);

    if ($sent) {
        wp_send_json_success(
            sprintf(
                /* translators: %s: Email address the test email was sent to. */
                esc_html__('Sent a test email to %s.', 'nonprofit-manager'),
                esc_html($user->user_email)
            )
        );
    } else {
        wp_send_json_error(esc_html__('Failed to send test email.', 'nonprofit-manager'));
    }
});

/**
     * AJAX: Queue Newsletter for Delivery
     */
add_action('wp_ajax_npmp_send_newsletter_now', function () {
    $post_id = isset($_POST['post_id']) ? (int) sanitize_text_field(wp_unslash($_POST['post_id'])) : 0;
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

    if (
        empty($post_id) ||
        !current_user_can('edit_post', $post_id) ||
        !wp_verify_nonce($nonce, 'npmp_send_newsletter_' . $post_id)
    ) {
        wp_send_json_error(esc_html__('Permission denied', 'nonprofit-manager'));
    }

    $levels    = array_key_exists('levels', $_POST) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['levels'])) : null;
    $preheader = array_key_exists('preheader', $_POST) ? sanitize_textarea_field(wp_unslash($_POST['preheader'])) : null;

    npmp_newsletter_store_meta($post_id, $levels, $preheader);

    $queued = NPMP_Newsletter_Manager::queue_newsletter($post_id);

    if ($queued > 0) {
        $message = sprintf(
            /* translators: 1: Number of queued recipients. 2: Audience description. */
            esc_html__('Queued for delivery to %1$d recipients (%2$s).', 'nonprofit-manager'),
            (int) $queued,
            esc_html(npmp_newsletter_get_audience_label($post_id))
        );
        wp_send_json_success($message);
    }

    wp_send_json_error(esc_html__('No subscribed members matched your selected audience.', 'nonprofit-manager'));
});

/**
 * Human-readable audience label.
 *
 * @param int $post_id Newsletter ID.
 * @return string
 */
function npmp_newsletter_get_audience_label($post_id) {
    $levels = get_post_meta($post_id, '_npmp_newsletter_levels', true);
    return NPMP_Newsletter_Manager::describe_audience($levels);
}

/**
 * Normalise level selections from the editor UI.
 *
 * @param array $levels Raw levels.
 * @return array
 */
function npmp_newsletter_normalize_levels($levels) {
    $levels = array_map('sanitize_text_field', (array) $levels);
    if (in_array('__npmp_all__', $levels, true)) {
        return [];
    }
    $levels = array_filter(
        $levels,
        static function ($level) {
            return '' !== $level;
        }
    );

    return array_values(array_unique($levels));
}

/**
 * Persist newsletter targeting meta data.
 *
 * @param int        $post_id   Newsletter ID.
 * @param array|null $levels    Membership levels (null to skip).
 * @param string|null $preheader Preheader text (null to skip).
 * @return void
 */
function npmp_newsletter_store_meta($post_id, $levels = null, $preheader = null) {
    if (null !== $levels) {
        $normalized = npmp_newsletter_normalize_levels($levels);
        update_post_meta($post_id, '_npmp_newsletter_levels', $normalized);
        update_post_meta($post_id, '_npmp_newsletter_audience_label', NPMP_Newsletter_Manager::describe_audience($normalized));
    }

    if (null !== $preheader) {
        $preheader = sanitize_textarea_field($preheader);
        if ('' === $preheader) {
            delete_post_meta($post_id, '_npmp_newsletter_preheader');
        } else {
            update_post_meta($post_id, '_npmp_newsletter_preheader', $preheader);
        }
    }
}

/**
 * Save newsletter meta on post save.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function npmp_save_newsletter_meta($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (!isset($_POST['npmp_newsletter_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['npmp_newsletter_meta_nonce'])), 'npmp_save_newsletter_meta_' . $post_id)) {
        return;
    }

    $levels    = isset($_POST['npmp_newsletter_levels']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['npmp_newsletter_levels'])) : [];
    $preheader = isset($_POST['npmp_newsletter_preheader']) ? sanitize_textarea_field(wp_unslash($_POST['npmp_newsletter_preheader'])) : '';

    npmp_newsletter_store_meta($post_id, $levels, $preheader);
}
add_action('save_post_npmp_newsletter', 'npmp_save_newsletter_meta');

/**
 * Add duplicate action to newsletter rows.
 *
 * @param array   $actions Existing actions.
 * @param WP_Post $post    Current post.
 * @return array
 */
function npmp_newsletter_row_actions($actions, $post) {
    if ('npmp_newsletter' !== $post->post_type) {
        return $actions;
    }

    $duplicate_url = wp_nonce_url(
        add_query_arg(
            [
                'action'        => 'npmp_duplicate_newsletter',
                'newsletter_id' => $post->ID,
            ],
            admin_url('admin.php')
        ),
        'npmp_duplicate_newsletter_' . $post->ID
    );

    $actions['npmp_duplicate'] = '<a href="' . esc_url($duplicate_url) . '">' . esc_html__('Duplicate to Draft', 'nonprofit-manager') . '</a>';
    return $actions;
}
add_filter('post_row_actions', 'npmp_newsletter_row_actions', 10, 2);

/**
 * Handle duplication requests.
 *
 * @return void
 */
function npmp_handle_duplicate_newsletter() {
    $newsletter_id = isset($_GET['newsletter_id']) ? absint($_GET['newsletter_id']) : 0;
    if (!$newsletter_id) {
        wp_safe_redirect(admin_url('edit.php?post_type=npmp_newsletter'));
        exit;
    }

    if (!current_user_can('edit_post', $newsletter_id)) {
        wp_die(esc_html__('You are not allowed to duplicate this newsletter.', 'nonprofit-manager'));
    }

    check_admin_referer('npmp_duplicate_newsletter_' . $newsletter_id);

    $source = get_post($newsletter_id);
    if (!$source || 'npmp_newsletter' !== $source->post_type) {
        wp_safe_redirect(admin_url('edit.php?post_type=npmp_newsletter'));
        exit;
    }

    $new_post = [
        'post_type'    => 'npmp_newsletter',
        'post_status'  => 'draft',
        /* translators: %s: Source newsletter title. */
        'post_title'   => sprintf(__('%s (Copy)', 'nonprofit-manager'), $source->post_title),
        'post_content' => $source->post_content,
    ];

    $new_id = wp_insert_post($new_post);

    if (is_wp_error($new_id)) {
        wp_die(esc_html($new_id->get_error_message()));
    }

    $meta_to_copy = ['_npmp_newsletter_preheader', '_npmp_newsletter_levels'];
    foreach ($meta_to_copy as $meta_key) {
        $value = get_post_meta($newsletter_id, $meta_key, true);
        if (!empty($value)) {
            update_post_meta($new_id, $meta_key, $value);
        }
    }

    $terms = wp_get_object_terms($newsletter_id, 'npmp_newsletter_topic', ['fields' => 'ids']);
    if (!is_wp_error($terms) && $terms) {
        wp_set_object_terms($new_id, $terms, 'npmp_newsletter_topic');
    }

    $edit_link = get_edit_post_link($new_id, 'redirect');
    if ($edit_link) {
        wp_safe_redirect(esc_url_raw($edit_link));
    } else {
        wp_safe_redirect(admin_url('edit.php?post_type=npmp_newsletter'));
    }
    exit;
}
add_action('admin_action_npmp_duplicate_newsletter', 'npmp_handle_duplicate_newsletter');
