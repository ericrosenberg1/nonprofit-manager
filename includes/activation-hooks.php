<?php
defined('ABSPATH') || exit;

// Register plugin hooks
register_activation_hook(plugin_dir_path(__DIR__) . 'nonprofit-manager.php', 'npmp_run_plugin_activation_tasks');
register_deactivation_hook(plugin_dir_path(__DIR__) . 'nonprofit-manager.php', 'npmp_clear_newsletter_cron');

// Run on plugin activation
function npmp_run_plugin_activation_tasks() {
    ob_start(); // Prevent output during activation

    npmp_create_members_table();
    npmp_create_donations_table();
    npmp_create_contacts_table();
    npmp_create_newsletter_queue_table();
    npmp_create_newsletter_opens_table();
    npmp_initialize_default_newsletter_settings();
    npmp_schedule_newsletter_cron();

    ob_end_clean(); // Discard any accidental output
}

// Create members table
function npmp_create_members_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'npmp_members';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        membership_level VARCHAR(100) DEFAULT '',
        status VARCHAR(50) DEFAULT 'subscribed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Create donations table
function npmp_create_donations_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'npmp_donations';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        frequency VARCHAR(20) DEFAULT 'one-time',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Create contacts table
function npmp_create_contacts_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'npmp_contacts';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL UNIQUE,
        name VARCHAR(255),
        status ENUM('subscribed', 'unsubscribed', 'pending') DEFAULT 'pending',
        token VARCHAR(64),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Create newsletter queue table
function npmp_create_newsletter_queue_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'npmp_newsletter_queue';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        newsletter_id BIGINT NOT NULL,
        user_id BIGINT,
        email VARCHAR(255),
        status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
        queued_at DATETIME,
        sent_at DATETIME NULL
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Create newsletter opens table
function npmp_create_newsletter_opens_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'npmp_newsletter_opens';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        newsletter_id BIGINT NOT NULL,
        opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_newsletter (user_id, newsletter_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Set default options
function npmp_initialize_default_newsletter_settings() {
    if (!get_option('npmp_newsletter_can_spam_footer')) {
        update_option('npmp_newsletter_can_spam_footer', __(
            "You're receiving this email from [organization] at [address].\nTo unsubscribe, click here: [unsubscribe_url]",
            'nonprofit-manager'
        ));
    }

    if (!get_option('npmp_newsletter_rate_limit')) {
        update_option('npmp_newsletter_rate_limit', 10);
    }
}

// Set up cron job
function npmp_schedule_newsletter_cron() {
    if (!wp_next_scheduled('npmp_process_queued_newsletters')) {
        wp_schedule_event(time(), 'every_minute', 'npmp_process_queued_newsletters');
    }
}

// Clear cron job on deactivation
function npmp_clear_newsletter_cron() {
    wp_clear_scheduled_hook('npmp_process_queued_newsletters');
}

// Bind processing function to cron
add_action('npmp_process_queued_newsletters', ['NPMP_Newsletter_Manager', 'process_queue']);

// Add custom cron interval
add_filter('cron_schedules', function ($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => __('Every Minute', 'nonprofit-manager')
    ];
    return $schedules;
});
