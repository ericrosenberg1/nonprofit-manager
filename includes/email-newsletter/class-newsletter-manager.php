<?php
defined('ABSPATH') || exit;

class NP_Newsletter_Manager {
    const MAX_EMAILS_PER_SECOND = 10;

    public static function send_test_email($newsletter_id, $user_email) {
        $content = self::get_newsletter_content($newsletter_id, true);
        $subject = get_the_title($newsletter_id);
        return self::send_email($user_email, $subject, $content);
    }

    public static function queue_newsletter($newsletter_id) {
        $recipients = self::get_recipient_list($newsletter_id);

        global $wpdb;
        foreach ($recipients as $user) {
            $wpdb->insert("{$wpdb->prefix}np_newsletter_queue", [
                'newsletter_id' => $newsletter_id,
                'user_id'       => $user->ID,
                'email'         => $user->user_email,
                'status'        => 'pending',
                'queued_at'     => current_time('mysql'),
            ]);
        }

        update_post_meta($newsletter_id, '_np_newsletter_status', 'queued');
        update_post_meta($newsletter_id, '_np_newsletter_queued_at', current_time('mysql'));
    }

    public static function process_queue() {
        global $wpdb;
        $limit = intval(get_option('np_newsletter_rate_limit', self::MAX_EMAILS_PER_SECOND));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}np_newsletter_queue WHERE status = %s ORDER BY queued_at ASC LIMIT %d",
            'pending',
            $limit
        ));

        foreach ($rows as $row) {
            $content = self::get_newsletter_content($row->newsletter_id, false, $row->user_id);
            $subject = get_the_title($row->newsletter_id);
            $sent = self::send_email($row->email, $subject, $content);

            $wpdb->update(
                "{$wpdb->prefix}np_newsletter_queue",
                [
                    'status'   => $sent ? 'sent' : 'failed',
                    'sent_at'  => current_time('mysql'),
                ],
                ['id' => $row->id]
            );
        }
    }

    public static function get_newsletter_content($newsletter_id, $is_test = false, $user_id = null) {
        $post = get_post($newsletter_id);
        if (!$post || $post->post_type !== 'np_newsletter') {
            return '';
        }

        $content = apply_filters('the_content', $post->post_content);

        if (!$is_test && $user_id) {
            $tracking_pixel = sprintf(
                '<img src="%s?uid=%d&nid=%d" alt="" width="1" height="1" style="display:none;" />',
                site_url('/np-track/open'),
                $user_id,
                $newsletter_id
            );
            $content .= $tracking_pixel;

            // TODO: Wrap links in content with tracking redirect URLs
        }

        $content .= do_shortcode('[np_can_spam]');
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
