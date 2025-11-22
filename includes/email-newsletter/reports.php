<?php
defined('ABSPATH') || exit;

/**
 * Class to handle newsletter stats
 */
class NPMP_Newsletter_Stats {
    private static $instance = null;

    /**
     * Run a lightweight count query with caching.
     *
     * @param string $cache_key  Cache key suffix.
     * @param array  $query_args Args passed to WP_Query.
     * @return int
     */
    private function get_cached_count( $cache_key, $query_args ) {
        $cached = wp_cache_get( $cache_key, 'npmp_newsletters' );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        $query = new WP_Query(
            wp_parse_args(
                $query_args,
                array(
                    'fields'                 => 'ids',
                    'posts_per_page'         => 1,
                    'no_found_rows'          => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            )
        );

        $count = (int) $query->found_posts;
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
        return $this->get_cached_count(
            $cache_key,
            array(
                'post_type'   => NPMP_Newsletter_Manager::QUEUE_POST_TYPE,
                'post_status' => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reporting filters newsletter events via metadata.
				'meta_query'  => array(
                    array(
                        'key'   => NPMP_Newsletter_Manager::QUEUE_NEWSLETTER_META,
                        'value' => absint( $newsletter_id ),
                    ),
                ),
            )
        );
    }
    
    // Get opens count for a newsletter
    public function get_opens_count($newsletter_id) {
        $cache_key = 'npmp_opens_' . $newsletter_id;
        return $this->get_cached_count(
            $cache_key,
            array(
                'post_type'   => NPMP_Newsletter_Manager::EVENT_POST_TYPE,
                'post_status' => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reporting filters newsletter events via metadata.
				'meta_query'  => array(
                    array(
                        'key'   => NPMP_Newsletter_Manager::EVENT_NEWSLETTER_META,
                        'value' => absint( $newsletter_id ),
                    ),
                    array(
                        'key'   => NPMP_Newsletter_Manager::EVENT_TYPE_META,
                        'value' => NPMP_Newsletter_Manager::ACTION_OPEN,
                    ),
                ),
            )
        );
    }
    
    // Get failure count for a newsletter
    public function get_failed_count($newsletter_id) {
        $cache_key = 'npmp_failed_' . $newsletter_id;
        return $this->get_cached_count(
            $cache_key,
            array(
                'post_type'   => NPMP_Newsletter_Manager::QUEUE_POST_TYPE,
                'post_status' => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reporting filters newsletter events via metadata.
				'meta_query'  => array(
                    array(
                        'key'   => NPMP_Newsletter_Manager::QUEUE_NEWSLETTER_META,
                        'value' => absint( $newsletter_id ),
                    ),
                    array(
                        'key'   => NPMP_Newsletter_Manager::QUEUE_STATUS_META,
                        'value' => 'failed',
                    ),
                ),
            )
        );
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
