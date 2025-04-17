<?php
defined('ABSPATH') || exit;

class NPMP_Newsletter_Manager {
    const MAX_EMAILS_PER_SECOND = 10;

    public static function send_test_email($newsletter_id, $user_email) {
        $content = self::get_newsletter_content($newsletter_id, true);
        $subject = get_the_title($newsletter_id);
        return self::send_email($user_email, $subject, $content);
    }

    // Newsletter queue data access class
    private static function get_queue_data_manager() {
        return new class {
            public function add_to_queue($newsletter_id, $user) {
                global $wpdb;
                $table = $wpdb->prefix . 'npmp_newsletter_queue';
                
                return $wpdb->insert($table, [
                    'newsletter_id' => $newsletter_id,
                    'user_id'       => $user->ID,
                    'email'         => $user->user_email,
                    'status'        => 'pending',
                    'queued_at'     => current_time('mysql'),
                ]);
            }
            
            public function get_pending_emails($limit) {
                global $wpdb;
                
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}npmp_newsletter_queue WHERE status = %s ORDER BY queued_at ASC LIMIT %d",
                    'pending', $limit
                ));
            }
            
            public function update_status($id, $status) {
                global $wpdb;
                $table = $wpdb->prefix . 'npmp_newsletter_queue';
                
                return $wpdb->update(
                    $table,
                    [
                        'status'   => $status,
                        'sent_at'  => current_time('mysql'),
                    ],
                    ['id' => $id]
                );
            }
        };
    }
    
    public static function queue_newsletter($newsletter_id) {
        $recipients = self::get_recipient_list($newsletter_id);
        $queue_manager = self::get_queue_data_manager();

        foreach ($recipients as $user) {
            $queue_manager->add_to_queue($newsletter_id, $user);
        }

        update_post_meta($newsletter_id, '_npmp_newsletter_status', 'queued');
        update_post_meta($newsletter_id, '_npmp_newsletter_queued_at', current_time('mysql'));
    }

    public static function process_queue() {
        $limit = intval(get_option('npmp_newsletter_rate_limit', self::MAX_EMAILS_PER_SECOND));
        $queue_manager = self::get_queue_data_manager();
        
        $rows = $queue_manager->get_pending_emails($limit);

        foreach ($rows as $row) {
            $content = self::get_newsletter_content($row->newsletter_id, false, $row->user_id);
            $subject = get_the_title($row->newsletter_id);
            $sent = self::send_email($row->email, $subject, $content);

            $queue_manager->update_status(
                $row->id, 
                $sent ? 'sent' : 'failed'
            );
        }
    }

    public static function get_newsletter_content($newsletter_id, $is_test = false, $user_id = null) {
        $post = get_post($newsletter_id);
        if (!$post || $post->post_type !== 'npmp_newsletter') {
            return '';
        }

        $content = apply_filters('the_content', $post->post_content);

        if (!$is_test && $user_id) {
            // Add tracking pixel for opens
            $tracking_pixel = sprintf(
                '<img src="%s?uid=%d&nid=%d" alt="" width="1" height="1" style="display:none;" />',
                site_url('/npmp-track/open'),
                $user_id,
                $newsletter_id
            );
            $content .= $tracking_pixel;

            // Add tracking to all links
            $tracker = NPMP_Newsletter_Tracker::get_instance();
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
            $links = $dom->getElementsByTagName('a');
            
            // Create a list of replacements
            $replacements = [];
            foreach ($links as $link) {
                $original_url = $link->getAttribute('href');
                if (!empty($original_url) && strpos($original_url, 'npmp-track') === false) {
                    $tracked_url = $tracker->create_tracked_url($original_url, $newsletter_id, $user_id);
                    $replacements[$original_url] = $tracked_url;
                }
            }
            
            // Apply replacements 
            foreach ($replacements as $original => $tracked) {
                $content = str_replace(
                    'href="' . $original . '"', 
                    'href="' . $tracked . '"', 
                    $content
                );
            }
        }

        $content .= do_shortcode('[npmp_can_spam]');
        return $content;
    }

    public static function send_email($to, $subject, $content) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $method = get_option('np_email_method', 'wp_mail');

        switch ($method) {
            case 'smtp':
            case 'ses':
            case 'sendgrid':
            case 'mailgun':
                return wp_mail($to, $subject, $content, $headers); // Delivery layer handles it
            default:
                return wp_mail($to, $subject, $content, $headers);
        }
    }

    public static function get_recipient_list($newsletter_id) {
        // Future support: segment by meta or group
        $args = [
            'meta_query' => [
                [
                    'key'     => 'np_unsubscribed',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        // TODO: optionally exclude bounced users
        return get_users($args);
    }
}
