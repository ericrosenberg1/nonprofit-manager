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
		return '<p>' . esc_html__( 'This page needs the link from one of our emails. Open the "Manage your email preferences" link at the bottom of any email we sent you.', 'nonprofit-manager' ) . '</p>';
	}

	// Verify HMAC token.
	$expected = npmp_generate_preferences_token( $email );
	if ( ! hash_equals( $expected, $token ) ) {
		return '<p>' . esc_html__( 'That preferences link doesn\'t work. Open the "Manage your email preferences" link in a more recent email from us.', 'nonprofit-manager' ) . '</p>';
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
		return '<p>' . esc_html__( 'We couldn\'t find that address on our list.', 'nonprofit-manager' ) . '</p>';
	}

	$contact_id = $contacts[0];

	// Handle form submission.
	if ( isset( $_POST['npmp_save_preferences'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'npmp_manage_prefs_' . $contact_id ) ) {
		npmp_save_subscriber_preferences( $contact_id, array(
			'notify_posts'  => ! empty( $_POST['npmp_notify_posts'] ),
			'notify_events' => ! empty( $_POST['npmp_notify_events'] ),
			'weekly_digest' => ! empty( $_POST['npmp_weekly_digest'] ),
		) );
		/**
		 * Fires after a member saves the preferences page (signed link and
		 * nonce already checked). Pro saves the member's group choices here:
		 * it joins checked groups and records an opt-out for unchecked ones.
		 *
		 * @param int    $contact_id Contact post ID.
		 * @param array  $request    The whole unslashed POST. Sanitize what you read.
		 * @param string $email      The member's address from the signed link.
		 */
		do_action( 'npmp_preferences_saved', (int) $contact_id, wp_unslash( $_POST ), $email ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Listeners sanitize the fields they read.
		return '<div style="background:#d4edda;border:1px solid #c3e6cb;padding:12px;border-radius:4px;margin-bottom:16px;"><p style="color:#155724;margin:0;">' . esc_html__( 'Preferences saved!', 'nonprofit-manager' ) . '</p></div>';
	}

	$prefs       = npmp_get_subscriber_preferences( $contact_id );
	$show_posts  = get_option( 'npmp_enable_post_notifications', false );
	$show_events = get_option( 'npmp_enable_event_notifications', false );
	$show_digest = get_option( 'npmp_enable_weekly_digest', false );
	$has_choices = $show_posts || $show_events || $show_digest || has_action( 'npmp_preferences_form_fields' );
	$unsub_url   = function_exists( 'npmp_get_one_click_unsubscribe_url' ) ? npmp_get_one_click_unsubscribe_url( $email ) : '';

	// No checkboxes switched on: a form with only a Save button left people
	// guessing. Say so, and keep the way off the list.
	if ( ! $has_choices ) {
		$html = '<div class="npmp-manage-preferences" style="max-width:500px;"><p>' . esc_html__( 'There are no email choices to set here. You get our newsletters while you stay subscribed.', 'nonprofit-manager' ) . '</p>';
		if ( $unsub_url ) {
			$html .= '<p><a href="' . esc_url( $unsub_url ) . '">' . esc_html__( 'Unsubscribe from all our emails', 'nonprofit-manager' ) . '</a></p>';
		}
		return $html . '</div>';
	}

	ob_start();
	?>
	<form method="post" class="npmp-manage-preferences" style="max-width:500px;">
		<h3><?php esc_html_e( 'Email Notification Preferences', 'nonprofit-manager' ); ?></h3>
		<p style="color:#666;"><?php /* translators: %s: subscriber's email address */ echo esc_html( sprintf( __( 'Choices for %s', 'nonprofit-manager' ), $email ) ); ?></p>

		<?php if ( $show_posts ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_notify_posts" value="1" <?php checked( $prefs['notify_posts'] ); ?> /> <?php esc_html_e( 'Email me when new posts are published', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php if ( $show_events ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_notify_events" value="1" <?php checked( $prefs['notify_events'] ); ?> /> <?php esc_html_e( 'Email me when new events are created', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php if ( $show_digest ) : ?>
		<label style="display:block;margin:12px 0;"><input type="checkbox" name="npmp_weekly_digest" value="1" <?php checked( $prefs['weekly_digest'] ); ?> /> <?php esc_html_e( 'Send me a weekly digest instead of individual emails', 'nonprofit-manager' ); ?></label>
		<?php endif; ?>

		<?php
		/**
		 * Fires inside the preferences form, after the notification
		 * checkboxes and before Save. Echo your fields. Pro prints a checkbox
		 * for each group the member can join or leave.
		 *
		 * @param int    $contact_id Contact post ID.
		 * @param string $email      The member's address from the signed link.
		 */
		do_action( 'npmp_preferences_form_fields', (int) $contact_id, $email );
		?>

		<?php wp_nonce_field( 'npmp_manage_prefs_' . $contact_id ); ?>
		<p><button type="submit" name="npmp_save_preferences" class="button"><?php esc_html_e( 'Save Preferences', 'nonprofit-manager' ); ?></button></p>
		<?php if ( $unsub_url ) : ?>
		<p style="font-size:0.9em;"><a href="<?php echo esc_url( $unsub_url ); ?>"><?php esc_html_e( 'Unsubscribe from all our emails', 'nonprofit-manager' ); ?></a></p>
		<?php endif; ?>
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

/**
 * The published page that holds [npmp_manage_preferences].
 *
 * Nothing in the plugin ever saved npmp_preferences_page_id, so every "Manage
 * your email preferences" link pointed at the home page. Find the page by its
 * shortcode once and remember it. A page that stops qualifying is looked up
 * again.
 *
 * @return int Page ID, or 0 when there is none.
 */
function npmp_get_preferences_page_id() {
	$page_id = absint( get_option( 'npmp_preferences_page_id', 0 ) );
	if ( $page_id && 'publish' === get_post_status( $page_id ) && has_shortcode( (string) get_post_field( 'post_content', $page_id ), 'npmp_manage_preferences' ) ) {
		return $page_id;
	}
	if ( false !== get_transient( 'npmp_prefs_page_lookup' ) ) {
		return 0;
	}
	$found = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			's'              => '[npmp_manage_preferences',
		)
	);
	$page_id = $found ? (int) $found[0] : 0;
	if ( $page_id ) {
		update_option( 'npmp_preferences_page_id', $page_id );
	} else {
		set_transient( 'npmp_prefs_page_lookup', 1, HOUR_IN_SECONDS );
	}
	return $page_id;
}

function npmp_get_preferences_url( $email ) {
	$prefs_page = npmp_get_preferences_page_id();
	if ( ! $prefs_page && function_exists( 'npmp_get_one_click_unsubscribe_url' ) ) {
		// No preferences page on this site: the unsubscribe link is the one
		// preference a reader can act on.
		return npmp_get_one_click_unsubscribe_url( $email );
	}
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

	// Mark as sent BEFORE dispatching. The flag used to be written after the
	// send loop, so a mid-loop failure re-blasted every subscriber on retry.
	update_post_meta( $post->ID, '_npmp_notification_sent', '1' );

	// Hand the actual sending to cron. Sending synchronously here meant the
	// editor's Publish click waited on one SMTP round trip per subscriber,
	// which hangs or times out with a few hundred opted-in contacts.
	wp_schedule_single_event( time() + 10, 'npmp_async_post_notification', array( $post->ID, $meta_key ) );
}

add_action( 'npmp_async_post_notification', 'npmp_process_post_notification', 10, 3 );

/**
 * Send the new post/event notification blast (cron context).
 *
 * Runs in batches: each pass emails up to npmp_post_notification_batch_size
 * subscribers and reschedules itself for the rest, so a large list cannot time
 * out partway through and silently skip everyone after the cutoff.
 *
 * @param int    $post_id  Published post ID.
 * @param string $meta_key Subscriber opt-in meta key.
 * @param int    $after_id Contact ID to resume after. 0 starts from the top.
 */
function npmp_process_post_notification( $post_id, $meta_key, $after_id = 0 ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	$meta_key = in_array( $meta_key, array( '_npmp_notify_posts', '_npmp_notify_events' ), true ) ? $meta_key : '_npmp_notify_posts';

	/**
	 * How many subscribers to email per cron run.
	 *
	 * This used to send the whole list in a single run. A site with a few
	 * thousand subscribers hit max_execution_time partway through, and because
	 * nothing recorded how far it got, the remainder were silently never
	 * emailed. The weekly digest already drains in throttled batches for
	 * exactly this reason. This brings post notifications in line.
	 *
	 * @param int $size Recipients handled per run.
	 */
	$batch_size = (int) apply_filters( 'npmp_post_notification_batch_size', 100 );
	if ( $batch_size < 1 ) {
		$batch_size = 100;
	}

	$after_id = (int) $after_id;

	// Page by ascending contact ID rather than by offset. The cursor is stable:
	// a contact added or deleted mid-run cannot shift rows past the reader, so
	// nobody is skipped and nobody is emailed twice.
	//
	// The cursor is passed as a query var, not captured in the closure, and that
	// detail matters. WordPress caches query results under a key built from the
	// query vars, and a posts_where filter does not change that key. Reading the
	// cursor from the closure instead meant run two asked for a different WHERE
	// under run one's cache key and got run one's rows straight back, re-emailing
	// the same people and never advancing. Passing it as a query var puts it in
	// the key, and doubles as the marker that identifies our own query below.
	$cursor = static function ( $where, $query ) {
		global $wpdb;
		$after = (int) $query->get( 'npmp_after_id' );
		if ( $after > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		}
		return $where;
	};
	add_filter( 'posts_where', $cursor, 10, 2 );

	// Find subscribers who opted in (and don't prefer digest).
	$subscribers = get_posts( array(
		'post_type'        => 'npmp_contact',
		'post_status'      => 'publish',
		'posts_per_page'   => $batch_size,
		'orderby'          => 'ID',
		'order'            => 'ASC',
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'npmp_after_id'    => $after_id,
		// get_posts() suppresses posts_* filters by default, which silently
		// dropped the cursor above and made every run re-read the first batch.
		'suppress_filters' => false,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Batch notification query.
		'meta_query'       => array(
			'relation' => 'AND',
			array( 'key' => $meta_key, 'value' => '1' ),
			array(
				'relation' => 'OR',
				array( 'key' => '_npmp_weekly_digest', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_npmp_weekly_digest', 'value' => '' ),
			),
			// A contact an admin or an import marked unsubscribed gets nothing,
			// whatever their notification checkboxes still say, and neither
			// does one who hasn't confirmed their signup yet (Pending).
			array(
				'relation' => 'OR',
				array( 'key' => 'npmp_status', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'npmp_status', 'value' => array( 'unsubscribed', 'pending' ), 'compare' => 'NOT IN' ),
			),
		),
	) );

	remove_filter( 'posts_where', $cursor, 10 );

	if ( empty( $subscribers ) ) {
		return;
	}

	// One meta-cache prime instead of a query per subscriber in the loop.
	update_meta_cache( 'post', $subscribers );

	// Two whole strings, so a translator never has to fit a bare English
	// "post" or "event" into their sentence.
	$subject = 'npmp_event' === $post->post_type
		/* translators: %s: event title */
		? sprintf( __( 'New event: %s', 'nonprofit-manager' ), $post->post_title )
		/* translators: %s: post title */
		: sprintf( __( 'New post: %s', 'nonprofit-manager' ), $post->post_title );

	// strip_shortcodes() first, or a [gallery] or [npmp_calendar] in the post
	// showed up word for word in the email.
	$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 50 );
	$url     = get_permalink( $post->ID );

	foreach ( $subscribers as $contact_id ) {
		$email = get_post_meta( $contact_id, 'npmp_email', true );
		if ( ! $email || ! is_email( $email ) ) {
			continue;
		}

		$name = trim( (string) get_the_title( $contact_id ) );

		// A contact saved without a name has the address as its title. "Hi
		// there" reads better than "Hi jane@example.org" or "Hi ,".
		$greeting = ( '' === $name || $name === $email )
			? esc_html__( 'Hi there,', 'nonprofit-manager' )
			/* translators: %s: subscriber's name */
			: sprintf( esc_html__( 'Hi %s,', 'nonprofit-manager' ), esc_html( $name ) );

		$body  = '<p>' . $greeting . '</p>';
		$body .= '<h2>' . esc_html( $post->post_title ) . '</h2>';
		$body .= '<p>' . esc_html( $excerpt ) . '</p>';
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Read more', 'nonprofit-manager' ) . '</a></p>';
		$body .= '<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">';
		// The newsletter footer with this reader's own links: postal address,
		// unsubscribe, and preferences. Until the release after 2026.11.8 a
		// notification carried only the preferences link.
		$body .= '<div style="font-size:12px;color:#6b7280;">' . npmp_email_recipient_footer( $email ) . '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( function_exists( 'npmp_get_list_unsubscribe_headers' ) ) {
			$headers = array_merge( $headers, npmp_get_list_unsubscribe_headers( $email ) );
		}

		npmp_send_mail( $email, $subject, $body, $headers );
	}

	// A full batch means there are probably more subscribers behind it, so pick
	// up from the last ID handled. A short batch is the end of the list.
	if ( count( $subscribers ) >= $batch_size ) {
		$last = (int) $subscribers[ count( $subscribers ) - 1 ];
		wp_schedule_single_event(
			time() + 60,
			'npmp_async_post_notification',
			array( (int) $post_id, $meta_key, $last )
		);
	}
}

/* =====================================================================
 * Weekly digest cron
 * ===================================================================== */

add_action( 'init', function () {
	if ( ! get_option( 'npmp_enable_weekly_digest', false ) ) {
		// Unschedule when the feature is switched off. The event used to
		// stay scheduled forever, firing a no-op handler weekly.
		if ( wp_next_scheduled( 'npmp_send_weekly_digest' ) ) {
			wp_clear_scheduled_hook( 'npmp_send_weekly_digest' );
		}
		return;
	}
	if ( ! wp_next_scheduled( 'npmp_send_weekly_digest' ) ) {
		// Schedule for Monday mornings at 9 AM site time. wp_schedule_event()
		// needs a true Unix (UTC) timestamp, but current_time( 'timestamp' )
		// returns time() shifted by the site's UTC offset (WordPress's
		// "local" pseudo-timestamp convention). Feeding that shifted value
		// straight in made the digest fire at 9:00 UTC-offset-from-9AM
		// instead of 9 AM local. Building the target moment directly in the
		// site's real timezone gives wp_schedule_event() a correct UTC
		// timestamp regardless of the site's configured offset.
		$next_monday = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( 'next Monday 9:00' )->getTimestamp();
		wp_schedule_event( $next_monday, 'weekly', 'npmp_send_weekly_digest' );
	}
} );

add_action( 'npmp_send_weekly_digest', 'npmp_process_weekly_digest' );

function npmp_process_weekly_digest() {
	if ( ! get_option( 'npmp_enable_weekly_digest', false ) ) {
		return;
	}

	// Get posts and events from the last 7 days. date_query's 'after' value
	// is compared against the post_date column, which WordPress stores as
	// site-local time. gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
	// produced a UTC-labeled string instead, shifting the 7-day cutoff by
	// the site's UTC offset. Building it directly in the site's timezone
	// keeps the window aligned to local time.
	$week_ago = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-7 days' )->format( 'Y-m-d H:i:s' );

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
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_npmp_weekly_digest', 'value' => '1' ),
			array(
				'relation' => 'OR',
				array( 'key' => 'npmp_status', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'npmp_status', 'value' => array( 'unsubscribed', 'pending' ), 'compare' => 'NOT IN' ),
			),
		),
	) );

	if ( empty( $subscribers ) ) {
		return;
	}

	// One meta-cache prime instead of one query per subscriber in the
	// get_post_meta() loop below (same fix already applied to the sibling
	// per-post notification path and the dashboard donation totals).
	update_meta_cache( 'post', $subscribers );

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
			$content .= ': ' . esc_html( wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 20 ) ) . '</li>';
		}
		$content .= '</ul>';
	}

	if ( ! empty( $recent_events ) ) {
		// These are events added this week, not events coming up, so the
		// heading says so.
		$content .= '<h3>' . esc_html__( 'New Events', 'nonprofit-manager' ) . '</h3><ul>';
		foreach ( $recent_events as $e ) {
			$start = get_post_meta( $e->ID, '_npmp_event_start', true );
			$content .= '<li><a href="' . esc_url( get_permalink( $e ) ) . '">' . esc_html( $e->post_title ) . '</a>';
			if ( $start ) {
				// _npmp_event_start is a naive "Y-m-d H:i:s" string entered
				// as site-local wall-clock time. WordPress forces PHP's
				// default timezone to UTC, so a bare strtotime() here parsed
				// it as UTC instead of the site's real timezone, shifting
				// the displayed time by the site's UTC offset. Parsing
				// explicitly against wp_timezone() keeps it correct.
				$start_ts = false;
				try {
					$start_ts = ( new DateTimeImmutable( $start, wp_timezone() ) )->getTimestamp();
				} catch ( Exception $ex ) {
					$start_ts = false;
				}
				if ( $start_ts ) {
					$content .= ', ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start_ts ) );
				}
			}
			$content .= '</li>';
		}
		$content .= '</ul>';
	}

	$emails = array();
	foreach ( $subscribers as $contact_id ) {
		$email = get_post_meta( $contact_id, 'npmp_email', true );
		if ( $email && is_email( $email ) ) {
			$emails[] = sanitize_email( $email );
		}
	}

	if ( empty( $emails ) ) {
		return;
	}

	// Enqueue instead of mailing inline. The subject/content are the same for
	// every recipient (only the preferences-link footer is personalized, at
	// send time in npmp_process_digest_queue()), so they're stored once here
	// rather than duplicated per queue row.
	update_option(
		'npmp_digest_pending_content',
		array(
			'subject' => $subject,
			'content' => $content,
		),
		false
	);

	global $wpdb;
	$table = $wpdb->prefix . 'npmp_digest_queue';

	// Clear any stale rows from a prior run that never finished draining, so
	// this week's send doesn't mix with leftover queue entries.
	$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name, no user input.

	// 500 rows per INSERT instead of one round trip per subscriber, the same
	// batching the newsletter queue uses.
	$now = current_time( 'mysql' );
	foreach ( array_chunk( array_values( array_unique( $emails ) ), 500 ) as $chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), "(%s, 'pending', %s)" ) );
		$values       = array();
		foreach ( $chunk as $email ) {
			$values[] = $email;
			$values[] = $now;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, no caching layer needed for a write.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (email, status, queued_at) VALUES {$placeholders}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name and a generated run of (%s, 'pending', %s) placeholders, one per row.
				$values
			)
		);
	}

	if ( ! wp_next_scheduled( 'npmp_process_digest_queue' ) ) {
		wp_schedule_event( time(), 'every_minute', 'npmp_process_digest_queue' );
	}
}
add_action( 'npmp_process_digest_queue', 'npmp_process_digest_queue' );

/**
 * Drain a throttled batch of the weekly digest queue. Runs on a per-minute
 * cron tick (scheduled by npmp_process_weekly_digest() above) instead of
 * mailing every subscriber inline inside one wp-cron run, so a large list
 * can't time out partway through and silently skip the rest. Unschedules
 * itself once the queue is empty, so the per-minute tick doesn't keep firing
 * between weekly runs.
 *
 * @return void
 */
function npmp_process_digest_queue() {
	// One run at a time, so an overlapping tick can't mail the same rows twice.
	if ( ! function_exists( 'npmp_mail_queue_lock' ) || ! npmp_mail_queue_lock( 'digest' ) ) {
		return;
	}
	try {
		npmp_drain_digest_batch();
	} finally {
		npmp_mail_queue_unlock( 'digest' );
	}
}

/**
 * Send one batch of the weekly digest queue. npmp_process_digest_queue()
 * holds the run lock around this.
 *
 * @return void
 */
function npmp_drain_digest_batch() {
	global $wpdb;
	$table = $wpdb->prefix . 'npmp_digest_queue';

	$limit = intval( get_option( 'npmp_digest_rate_limit', 10 ) );
	if ( $limit <= 0 ) {
		$limit = 10;
	}

	$batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $limit is cast to int above. Fixed table name.
		$wpdb->prepare(
			"SELECT id, email FROM {$table} WHERE status = 'pending' ORDER BY queued_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, only the LIMIT value is a placeholder.
			$limit
		)
	);

	if ( empty( $batch ) ) {
		wp_clear_scheduled_hook( 'npmp_process_digest_queue' );
		return;
	}

	$pending_content = get_option( 'npmp_digest_pending_content' );
	if ( empty( $pending_content['subject'] ) || ! isset( $pending_content['content'] ) ) {
		// Nothing to send (option missing/cleared): drop the queue rather
		// than loop forever failing every minute.
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name, no user input.
		wp_clear_scheduled_hook( 'npmp_process_digest_queue' );
		return;
	}

	$subject = $pending_content['subject'];
	$content = $pending_content['content'];

	foreach ( $batch as $row ) {
		$email = sanitize_email( $row->email );

		$personal_body = $content;
		$personal_body .= '<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">';
		$personal_body .= '<div style="font-size:12px;color:#6b7280;">' . npmp_email_recipient_footer( $email ) . '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( function_exists( 'npmp_get_list_unsubscribe_headers' ) ) {
			$headers = array_merge( $headers, npmp_get_list_unsubscribe_headers( $email ) );
		}

		$sent = npmp_send_mail( $email, $subject, $personal_body, $headers );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, no caching layer needed for a write.
			$table,
			array(
				'status'  => $sent ? 'sent' : 'failed',
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// If that was the last batch, clean up so the option doesn't linger
	// until next week and the cron stops polling an empty queue.
	$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name, no user input.
	if ( 0 === $remaining ) {
		delete_option( 'npmp_digest_pending_content' );
		wp_clear_scheduled_hook( 'npmp_process_digest_queue' );
	}
}
