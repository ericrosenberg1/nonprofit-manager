<?php
/**
 * File path: includes/np-admin-settings.php
 */

defined('ABSPATH') || exit;

// Save and redirect BEFORE any output
add_action('admin_init', function () {
    if (
        isset($_POST['np_features_nonce']) &&
        wp_verify_nonce($_POST['np_features_nonce'], 'np_save_features')
    ) {
        $enabled_features = [
            'members'     => isset($_POST['np_feature_members']),
            'newsletters' => isset($_POST['np_feature_newsletters']),
            'donations'   => isset($_POST['np_feature_donations']),
            'calendar'    => isset($_POST['np_feature_calendar']),
        ];

        // Enforce dependency
        if (!$enabled_features['members']) {
            $enabled_features['newsletters'] = false;
        }

        update_option('np_enabled_features', $enabled_features);

        // Redirect to avoid resubmission
        wp_safe_redirect(admin_url('admin.php?page=np_main&updated=1'));
        exit;
    }
});

// Main dashboard overview page
function np_render_main_plugin_page() {
    $features = get_option('np_enabled_features', [
        'members'     => true,
        'newsletters' => false,
        'donations'   => true,
        'calendar'    => false,
    ]);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Welcome to Nonprofit Manager', 'nonprofit-manager'); ?></h1>
        <p><?php esc_html_e('This plugin helps you manage donations, email lists, members, and nonprofit operations from your WordPress site.', 'nonprofit-manager'); ?></p>

        <?php if (isset($_GET['updated'])): ?>
            <div class="updated notice is-dismissible"><p><?php esc_html_e('Features updated.', 'nonprofit-manager'); ?></p></div>
        <?php endif; ?>

        <h2><?php esc_html_e('What Does Your Nonprofit Need?', 'nonprofit-manager'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('np_save_features', 'np_features_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Member Tracking', 'nonprofit-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="np_feature_members" <?php checked($features['members']); ?>>
                            <?php esc_html_e('Membership tracking and signup forms.', 'nonprofit-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email Newsletters', 'nonprofit-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="np_feature_newsletters" <?php checked($features['newsletters']); ?> <?php disabled(!$features['members']); ?>>
                            <?php esc_html_e('Send email newsletters to your members. (Requires Member Tracking.)', 'nonprofit-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Donations', 'nonprofit-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="np_feature_donations" <?php checked($features['donations']); ?>>
                            <?php esc_html_e('Collect donations and manage payment methods.', 'nonprofit-manager'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Calendar', 'nonprofit-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="np_feature_calendar" <?php checked($features['calendar']); ?> disabled>
                            <?php esc_html_e('Enable event calendar and RSVPs (coming soon)', 'nonprofit-manager'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Save Features', 'nonprofit-manager'); ?>">
            </p>
        </form>

        <hr>

        <h2><?php esc_html_e('Quick Access', 'nonprofit-manager'); ?></h2>
        <ul>
            <?php if (!empty($features['members'])): ?>
                <li><a href="admin.php?page=np_members"><?php esc_html_e('Members Page', 'nonprofit-manager'); ?></a> – View, add, and manage members and subscribers.</li>
                <li><a href="admin.php?page=np_email_settings"><?php esc_html_e('Email Settings', 'nonprofit-manager'); ?></a> – Connect your email service and customize membership forms.</li>
            <?php endif; ?>
            <?php if (!empty($features['donations'])): ?>
                <li><a href="admin.php?page=np_payment_settings"><?php esc_html_e('Donation Settings', 'nonprofit-manager'); ?></a> – Set up PayPal for donation collection.</li>
            <?php endif; ?>
        </ul>

        <h2><?php esc_html_e('Getting Started Checklist', 'nonprofit-manager'); ?></h2>
        <ol>
            <?php if (!empty($features['members'])): ?>
                <li><strong><?php esc_html_e('Email Setup:', 'nonprofit-manager'); ?></strong> Go to <a href="admin.php?page=np_email_settings">Email Settings</a> and connect your transactional email provider.</li>
            <?php endif; ?>
            <?php if (!empty($features['donations'])): ?>
                <li><strong><?php esc_html_e('Payment Gateway:', 'nonprofit-manager'); ?></strong> Configure <a href="admin.php?page=np_payment_settings">Payment Settings</a> to enable online donations.</li>
            <?php endif; ?>
            <li><strong><?php esc_html_e('Embed Forms:', 'nonprofit-manager'); ?></strong> Use the shortcodes below to add forms to any page or post.</li>
            <li><strong><?php esc_html_e('Test Everything:', 'nonprofit-manager'); ?></strong> Visit your opt-in and donation pages as a guest to verify form submission and database updates.</li>
        </ol>

        <h2><?php esc_html_e('Need Help?', 'nonprofit-manager'); ?></h2>
        <p>
            <?php esc_html_e('View full documentation and support at', 'nonprofit-manager'); ?>
            <a href="https://ericrosenberg.com/nonprofit-manager/" target="_blank">Nonprofit Manager for WordPress by Eric Rosenberg</a>.
            <?php esc_html_e('You can also check error logs by enabling', 'nonprofit-manager'); ?>
            <code>WP_DEBUG_LOG</code> <?php esc_html_e('in your', 'nonprofit-manager'); ?> <code>wp-config.php</code>.
        </p>
    </div>
    <?php
}
