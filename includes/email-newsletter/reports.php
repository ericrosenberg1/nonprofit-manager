<?php
defined('ABSPATH') || exit;

/**
 * Class to handle newsletter stats
 */
class NPMP_Newsletter_Stats {
    private static $instance = null;

    /**
     * Count queue rows for a newsletter, optionally filtered by status, with
     * an hour of caching. Backed by the dedicated wp_npmp_newsletter_queue
     * table (see NPMP_Newsletter_Manager::process_queue()) rather than a
     * WP_Query/meta_query scan over wp_posts.
     *
     * @param string $cache_key     Cache key suffix.
     * @param int    $newsletter_id Newsletter ID.
     * @param string $status        Optional status filter ('' for any).
     * @return int
     */
    private function get_cached_queue_count( $cache_key, $newsletter_id, $status = '' ) {
        $cached = wp_cache_get( $cache_key, 'npmp_newsletters' );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'npmp_newsletter_queue';

        if ( '' !== $status ) {
            $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached via wp_cache_set() below; dedicated queue table.
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE newsletter_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
                    absint( $newsletter_id ),
                    $status
                )
            );
        } else {
            $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached via wp_cache_set() below; dedicated queue table.
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE newsletter_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
                    absint( $newsletter_id )
                )
            );
        }

        wp_cache_set( $cache_key, $count, 'npmp_newsletters', HOUR_IN_SECONDS );

        return $count;
    }

    // Get the singleton instance
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Get total recipients for a newsletter
    public function get_total_recipients($newsletter_id) {
        $cache_key = 'npmp_total_' . $newsletter_id;
        return $this->get_cached_queue_count( $cache_key, $newsletter_id );
    }
    
    // Get opens count for a newsletter. Backed by the dedicated
    // wp_npmp_newsletter_opens table (see NPMP_Newsletter_Tracker::track_open())
    // instead of a WP_Query/meta_query scan over wp_posts.
    public function get_opens_count($newsletter_id) {
        $cache_key = 'npmp_opens_' . $newsletter_id;
        $cached    = wp_cache_get( $cache_key, 'npmp_newsletters' );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'npmp_newsletter_opens';
        $count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached via wp_cache_set() below; dedicated tracking table.
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE newsletter_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
                absint( $newsletter_id )
            )
        );

        wp_cache_set( $cache_key, $count, 'npmp_newsletters', HOUR_IN_SECONDS );

        return $count;
    }
    
    // Get failure count for a newsletter
    public function get_failed_count($newsletter_id) {
        $cache_key = 'npmp_failed_' . $newsletter_id;
        return $this->get_cached_queue_count( $cache_key, $newsletter_id, 'failed' );
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
                <th>' . esc_html__('Audience', 'nonprofit-manager') . '</th>
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
        $audience  = get_post_meta($newsletter_id, '_npmp_newsletter_audience_label', true);
        if (!$audience) {
            $levels   = get_post_meta($newsletter_id, '_npmp_newsletter_levels', true);
            $audience = NPMP_Newsletter_Manager::describe_audience($levels);
        }

        echo '<tr>';
        echo '<td><a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a></td>';
        echo '<td>' . esc_html($audience) . '</td>';
        echo '<td>' . esc_html($sent_date ?: '-') . '</td>';
        echo '<td>' . esc_html(intval($total)) . '</td>';
        echo '<td>' . esc_html(intval($opens)) . '</td>';
        echo '<td>' . esc_html(intval($failed)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
