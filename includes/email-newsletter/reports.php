<?php
defined('ABSPATH') || exit;

/**
 * Class to handle newsletter stats
 */
class NPMP_Newsletter_Stats {
    private static $instance = null;
    
    // Get the singleton instance
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Get total recipients for a newsletter
    public function get_total_recipients($newsletter_id) {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = 'npmp_total_' . $newsletter_id;
        $total = wp_cache_get($cache_key);
        
        if ($total === false) {
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE newsletter_id = %d",
                $newsletter_id
            ));
            wp_cache_set($cache_key, $total);
        }
        
        return $total;
    }
    
    // Get opens count for a newsletter
    public function get_opens_count($newsletter_id) {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = 'npmp_opens_' . $newsletter_id;
        $opens = wp_cache_get($cache_key);
        
        if ($opens === false) {
            $opens = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_opens WHERE newsletter_id = %d",
                $newsletter_id
            ));
            wp_cache_set($cache_key, $opens);
        }
        
        return $opens;
    }
    
    // Get failure count for a newsletter
    public function get_failed_count($newsletter_id) {
        global $wpdb;
        
        // Try to get from cache
        $cache_key = 'npmp_failed_' . $newsletter_id;
        $failed = wp_cache_get($cache_key);
        
        if ($failed === false) {
            $failed = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}npmp_newsletter_queue WHERE newsletter_id = %d AND status = %s",
                $newsletter_id, 'failed'
            ));
            wp_cache_set($cache_key, $failed);
        }
        
        return $failed;
    }
}

/**
 * Render the Newsletter Reports Page
 * File: includes/email-newsletter/reports.php
 */

function npmp_render_newsletter_reports() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Newsletter Reports', 'nonprofit-manager') . '</h1>';
    echo '<p>' . esc_html__('Track opens, failures, and engagement for each newsletter.', 'nonprofit-manager') . '</p><hr>';

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
          
    // Get stats instance
    $stats = NPMP_Newsletter_Stats::get_instance();

    foreach ($newsletters as $newsletter) {
        $newsletter_id = $newsletter->ID;

        // Get stats using the manager class
        $total = $stats->get_total_recipients($newsletter_id);
        $opens = $stats->get_opens_count($newsletter_id);
        $failed = $stats->get_failed_count($newsletter_id);

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
