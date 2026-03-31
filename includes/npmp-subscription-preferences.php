<?php
/**
 * File path: includes/npmp-subscription-preferences.php
 *
 * Email subscription preference management. Subscribers can opt in/out of:
 * - New post notifications (immediate)
 * - New event notifications (immediate)
 * - Weekly digest email
 *
 * Admins can toggle which preference options are available.
 */
defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * Admin settings (toggle available preferences)
 * ===================================================================== */

add_action( 'admin_init', 'npmp_register_subscription_pref_settings' );

function npmp_register_subscription_pref_settings() {
	register_setting( 'npmp_newsletter_settings', 'npmp_enable_post_notifications', array(
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => 'rest_sanitize_boolean',
	) );
	register_setting( 'npmp_newsletter_settings', 'npmp_enable_event_notifications', array(
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => 'rest_sanitize_boolean',
	) );
	register_setting( 'npmp_newsletter_settings', 'npmp_enable_weekly_digest', array(
		'type'              => 'boolean',
		'default'           => false,
		'sanitize_callback' => 'rest_sanitize_boolean',
	) );

	add_settings_section(
		'npmp_subscription_prefs',
		__( 'Subscriber Notification Preferences', 'nonprofit-manager' ),
		function () {
			echo '<p class="description">' . esc_html__( 'Allow subscribers to choose which automatic emails they receive. These options appear on the signup form and in a manage-preferences link in every email.', 'nonprofit-manager' ) . '</p>';
		},
		'npmp_newsletter_settings'
	);

	add_settings_field( 'npmp_enable_post_notifications', __( 'New Post Notifications', 'nonprofit-manager' ), function () {
		$val = get_option( 'npmp_enable_post_notifications', false );
		echo '<label><input type="checkbox" name="npmp_enable_post_notifications" value="1" ' . checked( $val, true, false ) . ' /> ';
		esc_html_e( 'Let subscribers opt in to get an email when you publish a new post', 'nonprofit-manager' );
		echo '</label>';
	}, 'npmp_newsletter_settings', 'npmp_subscription_prefs' );

	add_settings_field( 'npmp_enable_event_notifications', __( 'New Event Notifications', 'nonprofit-manager' ), function () {
		$val = get_option( 'npmp_enable_event_notifications', false );
		echo '<label><input type="checkbox" name="npmp_enable_event_notifications" value="1" ' . checked( $val, true, false ) . ' /> ';
		esc_html_e( 'Let subscribers opt in to get an email when you create a new event', 'nonprofit-manager' );
		echo '</label>';
	}, 'npmp_newsletter_settings', 'npmp_subscription_prefs' );

	add_settings_field( 'npmp_enable_weekly_digest', __( 'Weekly Digest', 'nonprofit-manager' ), function () {
		$val = get_option( 'npmp_enable_weekly_digest', false );
		echo '<label><input type="checkbox" name="npmp_enable_weekly_digest" value="1" ' . checked( $val, true, false ) . ' /> ';
		esc_html_e( 'Let subscribers opt in to a weekly summary email of new posts and events', 'nonprofit-manager' );
		echo '</label>';
	}, 'npmp_newsletter_settings', 'npmp_subscription_prefs' );
}

/* =====================================================================
 * Subscriber preference storage (post meta on npmp_contact)
 * ===================================================================== */

/**
 * Get a subscriber's notification preferences.
 *
 * @param int $contact_id The npmp_contact post ID.
 * @return array { notify_posts: bool, notify_events: bool, weekly_digest: bool }
 */
function npmp_get_subscriber_preferences( $contact_id ) {
	return array(
		'notify_posts'  => (bool) get_post_meta( $contact_id, '_npmp_notify_posts', true ),
		'notify_events' => (bool) get_post_meta( $contact_id, '_npmp_notify_events', true ),
		'weekly_digest' => (bool) get_post_meta( $contact_id, '_npmp_weekly_digest', true ),
	);
}

/**
 * Save a subscriber's notification preferences.
 *
 * @param int   $contact_id The npmp_contact post ID.
 * @param array $prefs      Preferences to save.
 */
function npmp_save_subscriber_preferences( $contact_id, $prefs ) {
	update_post_meta( $contact_id, '_npmp_notify_posts', ! empty( $prefs['notify_posts'] ) ? '1' : '' );
	update_post_meta( $contact_id, '_npmp_notify_events', ! empty( $prefs['notify_events'] ) ? '1' : '' );
	update_post_meta( $contact_id, '_npmp_weekly_digest', ! empty( $prefs['weekly_digest'] ) ? '1' : '' );
}

/* =====================================================================
 * Add preference checkboxes to signup form
 * ===================================================================== */

add_filter( 'npmp_signup_form_after_fields', 'npmp_render_preference_checkboxes' );

function npmp_render_preference_checkboxes( $html ) {
	$show_posts  = get_option( 'npmp_enable_post_notifications', false );
	$show_events = get_option( 'npmp_enable_event_notifications', false );
	$show_digest = get_option( 'npmp_enable_weekly_digest', false );

	if ( ! $show_posts && ! $show_events && ! $show_digest ) {
		return $html;
	}

	$prefs_html = '<fieldset class="npmp-notification-prefs" style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:4px;">';
	$prefs_html .= '<legend style="font-weight:600;padding:0 4px;">' . esc_html__( 'Email notifications', 'nonprofit-manager' ) . '</legend>';

	if ( $show_posts ) {
		$prefs_html .= '<label style="display:block;margin:6px 0;"><input type="checkbox" name="npmp_notify_posts" value="1" checked /> ' . esc_html__( 'Notify me about new posts', 'nonprofit-manager' ) . '</label>';
	}
	if ( $show_events ) {
		$prefs_html .= '<label style="display:block;margin:6px 0;"><input type="checkbox" name="npmp_notify_events" value="1" checked /> ' . esc_html__( 'Notify me about new events', 'nonprofit-manager' ) . '</label>';
	}
	if ( $show_digest ) {
		$prefs_html .= '<label style="display:block;margin:6px 0;"><input type="checkbox" name="npmp_weekly_digest" value="1" /> ' . esc_html__( 'Send me a weekly digest instead', 'nonprofit-manager' ) . '</label>';
	}
	$prefs_html .= '</fieldset>';

	return $html . $prefs_html;
}

/* =====================================================================
 * Save preferences on signup
 * ===================================================================== */

add_action( 'npmp_after_email_signup', 'npmp_save_signup_preferences', 10, 2 );

function npmp_save_signup_preferences( $contact_id, $post_data ) {
	npmp_save_subscriber_preferences( $contact_id, array(
		'notify_posts'  => ! empty( $post_data['npmp_notify_posts'] ),
		'notify_events' => ! empty( $post_data['npmp_notify_events'] ),
		'weekly_digest' => ! empty( $post_data['npmp_weekly_digest'] ),
	) );
}

/* =====================================================================
 * Manage preferences page (shortcode + form handler)
 * ===================================================================== */

add_shortcode( 'npmp_manage_preferences', 'npmp_manage_preferences_shortcode' );

function npmp_manage_preferences_shortcode() {
	$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-based auth for email links.
	$email = sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );     // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( empty( $token ) || empty( $email ) ) {
		return '<p>' . esc_html__( 'Invalid or missing preference link. Please use the link from your email.', 'nonprofit-manager' ) . '</p>';
	}

	// Verify HMAC token.
	$expected = npmp_generate_preferences_token( $email );
	if ( ! hash_equals( $expected, $token ) ) {
		return '<p>' . esc_html__( 'Invalid preference link.', 'nonprofit-manager' ) . '</p>';
	}

	// Find the contact.
	$contacts = get_posts( array(
		'post_type'      => 'npmp_contact',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookup by email.
		'meta_query'     => array( array( 'key' => 'npmp_email', 'value' => $email ) ),
	) );

	if ( empty( $contacts ) ) {
		return '<p>' . esc_html__( 'Email not found in our records.', 'nonprofit-manager' ) . '</p>';
	}

	$contact_id = $contacts[0];

	// Handle form submission.
	if ( isset( $_POST['npmp_save_preferences'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'npmp_manage_prefs_' . $contact_id ) ) {
		npmp_save_subscriber_preferences( $contact_id, array(
			'notify_posts'  => ! empty( $_POST['npmp_notify_posts'] ),
			'notify_events' => ! empty( $_POST['npmp_notify_events'] ),
			'weekly_digest' => ! empty( $_POST['npmp_weekly_digest'] ),
		) );
		return '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:12px;border-radius:4px;margin-bottom:16px;"><p style="color:#155724;margin:0;">' . esc_html__( 'Preferences saved!', 'nonprofit-manager' ) . '</p></div>';
	}

	$prefs       = npmp_get_subscriber_preferences( $contact_id );
	$show_posts  = get_option( 'npmp_enable_post_notifications', false );
	$show_events = get_option( 'npmp_enable_event_notifications', false );
	$show_digest = get_option( 'npmp_enable_weekly_digest', false );

	ob_start();
	?>
	<form method="post" class="npmp-manage-preferences" style="max-width:500px;">
		<h3><?php esc_html_e( 'Email Notification Preferences', 'nonprofit-manager' ); ?></h3>
		<p style="color:#666;"><?php echo esc_html( sprintf( __( 'Managing preferences for %s', 'nonprofit-manager' ), $email ) ); ?></p>

		<?php if ( $show_posts ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_notify_posts" value="1" <?php checked( $prefs['notify_posts'] ); ?> /> <?php esc_html_e( 'Email me when new posts are published', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php if ( $show_events ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_notify_events" value="1" <?php checked( $prefs['notify_events'] ); ?> /> <?php esc_html_e( 'Email me when new events are created', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php if ( $show_digest ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_weekly_digest" value="1" <?php checked( $prefs['weekly_digest'] ); ?> /> <?php esc_html_e( 'Send me a weekly digest instead of individual emails', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php wp_nonce_field( 'npmp_manage_prefs_' . $contact_id ); ?>
		<p><button type="submit" name="npmp_save_preferences" class="button"><?php esc_html_e( 'Save Preferences', 'nonprofit-manager' ); ?></button></p>
	</form>
	<?php
	return ob_get_clean();
}

/* =====================================================================
 * Token generation for preference management links
 * ===================================================================== */

function npmp_generate_preferences_token( $email ) {
	return substr( hash_hmac( 'sha256', 'prefs|' . $email, wp_salt( 'auth' ) ), 0, 20 );
}

function npmp_get_preferences_url( $email ) {
	$prefs_page = get_option( 'npmp_preferences_page_id', 0 );
	$base_url   = $prefs_page ? get_permalink( $prefs_page ) : home_url( '/' );
	return add_query_arg( array(
		'email' => rawurlencode( $email ),
		'token' => npmp_generate_preferences_token( $email ),
	), $base_url );
}

/* =====================================================================
 * Auto-send notifications on new posts/events
 * ===================================================================== */

add_action( 'transition_post_status', 'npmp_maybe_send_post_notification', 10, 3 );

function npmp_maybe_send_post_notification( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// Prevent double-sending.
	if ( get_post_meta( $post->ID, '_npmp_notification_sent', true ) ) {
		return;
	}

	$meta_key = null;
	if ( 'post' === $post->post_type && get_option( 'npmp_enable_post_notifications', false ) ) {
		$meta_key = '_npmp_notify_posts';
	} elseif ( 'npmp_event' === $post->post_type && get_option( 'npmp_enable_event_notifications', false ) ) {
		$meta_key = '_npmp_notify_events';
	}

	if ( ! $meta_key ) {
		return;
	}

	// Find all subscribers who opted in (and don't prefer digest).
	$subscribers = get_posts( array(
		'post_type'      => 'npmp_contact',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Batch notification query.
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => $meta_key, 'value' => '1' ),
			array(
				'relation' => 'OR',
				array( 'key' => '_npmp_weekly_digest', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_npmp_weekly_digest', 'value' => '' ),
			),
		),
	) );

	if ( empty( $subscribers ) ) {
		return;
	}

	$type    = 'npmp_event' === $post->post_type ? 'event' : 'post';
	$subject = sprintf(
		/* translators: %1$s: post type label, %2$s: post title */
		__( 'New %1$s: %2$s', 'nonprofit-manager' ),
		$type,
		$post->post_title
	);

	$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 );
	$url     = get_permalink( $post->ID );

	foreach ( $subscribers as $contact_id ) {
		$email = get_post_meta( $contact_id, 'npmp_email', true );
		if ( ! $email || ! is_email( $email ) ) {
			continue;
		}

		$name      = get_the_title( $contact_id );
		$prefs_url = npmp_get_preferences_url( $email );

		$body  = '<p>' . sprintf( esc_html__( 'Hi %s,', 'nonprofit-manager' ), esc_html( $name ) ) . '</p>';
		$body .= '<h2>' . esc_html( $post->post_title ) . '</h2>';
		$body .= '<p>' . esc_html( $excerpt ) . '</p>';
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Read more', 'nonprofit-manager' ) . '</a></p>';
		$body .= '<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">';
		$body .= '<p style="font-size:12px;color:#999;"><a href="' . esc_url( $prefs_url ) . '">' . esc_html__( 'Manage your email preferences', 'nonprofit-manager' ) . '</a></p>';

		wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	update_post_meta( $post->ID, '_npmp_notification_sent', '1' );
}

/* =====================================================================
 * Weekly digest cron
 * ===================================================================== */

add_action( 'init', function () {
	if ( ! get_option( 'npmp_enable_weekly_digest', false ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'npmp_send_weekly_digest' ) ) {
		// Schedule for Monday mornings at 9 AM site time.
		$next_monday = strtotime( 'next Monday 9:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Need timestamp for wp_schedule_event.
		wp_schedule_event( $next_monday, 'weekly', 'npmp_send_weekly_digest' );
	}
} );

add_action( 'npmp_send_weekly_digest', 'npmp_process_weekly_digest' );

function npmp_process_weekly_digest() {
	if ( ! get_option( 'npmp_enable_weekly_digest', false ) ) {
		return;
	}

	// Get posts and events from the last 7 days.
	$week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

	$recent_posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'date_query'     => array( array( 'after' => $week_ago ) ),
	) );

	$recent_events = get_posts( array(
		'post_type'      => 'npmp_event',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'date_query'     => array( array( 'after' => $week_ago ) ),
	) );

	if ( empty( $recent_posts ) && empty( $recent_events ) ) {
		return; // Nothing to digest.
	}

	// Find digest subscribers.
	$subscribers = get_posts( array(
		'post_type'      => 'npmp_contact',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Weekly digest batch query.
		'meta_query'     => array( array( 'key' => '_npmp_weekly_digest', 'value' => '1' ) ),
	) );

	if ( empty( $subscribers ) ) {
		return;
	}

	$site_name = get_bloginfo( 'name' );
	$subject   = sprintf(
		/* translators: %s: site name */
		__( 'Weekly Digest from %s', 'nonprofit-manager' ),
		$site_name
	);

	// Build digest content.
	$content = '<h2>' . esc_html( $subject ) . '</h2>';

	if ( ! empty( $recent_posts ) ) {
		$content .= '<h3>' . esc_html__( 'New Posts', 'nonprofit-manager' ) . '</h3><ul>';
		foreach ( $recent_posts as $p ) {
			$content .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $p->post_title ) . '</a>';
			$content .= ' &mdash; ' . esc_html( wp_trim_words( wp_strip_all_tags( $p->post_content ), 20 ) ) . '</li>';
		}
		$content .= '</ul>';
	}

	if ( ! empty( $recent_events ) ) {
		$content .= '<h3>' . esc_html__( 'Upcoming Events', 'nonprofit-manager' ) . '</h3><ul>';
		foreach ( $recent_events as $e ) {
			$start = get_post_meta( $e->ID, '_npmp_event_start', true );
			$content .= '<li><a href="' . esc_url( get_permalink( $e ) ) . '">' . esc_html( $e->post_title ) . '</a>';
			if ( $start ) {
				$content .= ' &mdash; ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) ) );
			}
			$content .= '</li>';
		}
		$content .= '</ul>';
	}

	foreach ( $subscribers as $contact_id ) {
		$email = get_post_meta( $contact_id, 'npmp_email', true );
		if ( ! $email || ! is_email( $email ) ) {
			continue;
		}

		$prefs_url     = npmp_get_preferences_url( $email );
		$personal_body = $content;
		$personal_body .= '<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">';
		$personal_body .= '<p style="font-size:12px;color:#999;"><a href="' . esc_url( $prefs_url ) . '">' . esc_html__( 'Manage your email preferences', 'nonprofit-manager' ) . '</a></p>';

		wp_mail( $email, $subject, $personal_body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
