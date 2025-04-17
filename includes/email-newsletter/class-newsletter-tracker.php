<?php
defined('ABSPATH') || exit;

class NPMP_Newsletter_Tracker {
    private static $instance = null;
    
    // Get the singleton instance
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Track email open
     * 
     * @param int $newsletter_id Newsletter ID
     * @param int $user_id User ID
     * @return bool Success status
     */
    public function track_open($newsletter_id, $user_id) {
        global $wpdb;
        
        // Check if we've already recorded this open
        $cache_key = 'npmp_open_' . $newsletter_id . '_' . $user_id;
        $tracked = wp_cache_get($cache_key);
        
        if ($tracked) {
            return true; // Already tracked
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'npmp_newsletter_opens',
            [
                'newsletter_id' => $newsletter_id,
                'user_id'       => $user_id,
                'opened_at'     => current_time('mysql'),
                'ip_address'    => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                'user_agent'    => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ]
        );
        
        if ($result) {
            wp_cache_set($cache_key, true, '', 3600); // Cache for 1 hour
        }
        
        return (bool) $result;
    }

    /**
     * Track link click
     * 
     * @param int $newsletter_id Newsletter ID
     * @param int $user_id User ID
     * @param string $url The URL that was clicked
     * @return bool Success status
     */
    public function track_click($newsletter_id, $user_id, $url) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'npmp_newsletter_clicks',
            [
                'newsletter_id' => $newsletter_id,
                'user_id'       => $user_id,
                'url'           => esc_url_raw($url),
                'clicked_at'    => current_time('mysql'),
                'ip_address'    => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                'user_agent'    => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ]
        );
        
        return (bool) $result;
    }
    
    /**
     * Create tracked URL
     * 
     * @param string $original_url Original URL
     * @param int $newsletter_id Newsletter ID
     * @param int $user_id User ID
     * @return string Tracking URL
     */
    public function create_tracked_url($original_url, $newsletter_id, $user_id) {
        $args = [
            'nid' => $newsletter_id,
            'uid' => $user_id,
            'url' => rawurlencode($original_url),
        ];
        
        return add_query_arg($args, site_url('/npmp-track/click'));
    }
    
    /**
     * Process click tracking and redirect
     */
    public function process_click() {
        $newsletter_id = isset($_GET['nid']) ? absint($_GET['nid']) : 0;
        $user_id = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
        $url = isset($_GET['url']) ? esc_url_raw(rawurldecode(wp_unslash($_GET['url']))) : '';
        
        if ($newsletter_id && $user_id && $url) {
            $this->track_click($newsletter_id, $user_id, $url);
        }
        
        if ($url) {
            wp_redirect($url);
            exit;
        }
        
        wp_safe_redirect(home_url());
        exit;
    }
}
