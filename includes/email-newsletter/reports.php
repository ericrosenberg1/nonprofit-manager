<?php
defined('ABSPATH') || exit;

/**
 * Render the Newsletter Reports Page
 * File: includes/email-newsletter/reports.php
 */

function npmp_render_newsletter_reports() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Newsletter Reports', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Track opens, failures, and engagement for each newsletter.', 'nonprofit-manager') . '</p><hr>';

    global $wpdb;

    $newsletters = get_posts([
        'post_type' => 'npmp_newsletter',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if (!$newsletters) {
        echo '<p>' . esc_html__('No newsletters sent yet.', 'nonprofit-manager') . '</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead>
            <tr>
                <th>' . esc_html__('Title', 'nonprofit-manager') . '</th>
                <th>' . esc_html__('Sent On', 'nonprofit-manager') . '</th>
                <th>' . esc_html__('Total Recipients', 'nonprofit-manager') . '</th>
                <th>' . esc_html__('Opens', 'nonprofit-manager') . '</th>
                <th>' . esc_html__('Failed', 'nonprofit-manager') . '</th>
            </tr>
          </thead><tbody>';

    foreach ($newsletters as $newsletter) {
        $newsletter_id = $newsletter->ID;

        // Use cache key per query
        $cache_key_total  = 'npmp_total_' . $newsletter_id;
        $cache_key_opens  = 'npmp_opens_' . $newsletter_id;
        $cache_key_failed = 'npmp_failed_' . $newsletter_id;

        $total = wp_cache_get($cache_key_total);
        if ($total === false) {
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE newsletter_id = %d",
                $newsletter_id
            ));
            wp_cache_set($cache_key_total, $total);
        }

        $opens = wp_cache_get($cache_key_opens);
        if ($opens === false) {
            $opens = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_opens WHERE newsletter_id = %d",
                $newsletter_id
            ));
            wp_cache_set($cache_key_opens, $opens);
        }

        $failed = wp_cache_get($cache_key_failed);
        if ($failed === false) {
            $failed = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE newsletter_id = %d AND status = %s",
                $newsletter_id, 'failed'
            ));
            wp_cache_set($cache_key_failed, $failed);
        }

        $sent_date = get_post_meta($newsletter_id, '_npmp_newsletter_queued_at', true);
        $edit_link = get_edit_post_link($newsletter_id);
        $title     = get_the_title($newsletter_id);

        echo '<tr>';
        echo '<td><a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a></td>';
        echo '<td>' . esc_html($sent_date ?: '—') . '</td>';
        echo '<td>' . esc_html(intval($total)) . '</td>';
        echo '<td>' . esc_html(intval($opens)) . '</td>';
        echo '<td>' . esc_html(intval($failed)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
