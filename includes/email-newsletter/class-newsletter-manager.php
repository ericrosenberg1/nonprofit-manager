<?php
defined( 'ABSPATH' ) || exit;

class NPMP_Newsletter_Manager {

	const MAX_EMAILS_PER_SECOND = 10;

	/* ===================================================================
	 * Test email
	 * =================================================================== */
	public static function send_test_email( $newsletter_id, $user_email ) {
		$content = self::get_newsletter_content( $newsletter_id, true );
		$subject = get_the_title( $newsletter_id );
		return self::send_email( $user_email, $subject, $content );
	}

	/* ===================================================================
	 * Queue‑data manager (anonymous class)
	 * =================================================================== */
	private static function get_queue_data_manager() {
		return new class {
			public function add_to_queue( $newsletter_id, $user ) {
				global $wpdb;
				$table = esc_sql( $wpdb->prefix . 'npmp_newsletter_queue' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->insert(
					$table,
					array(
						'newsletter_id' => $newsletter_id,
						'user_id'       => $user->ID,
						'email'         => $user->user_email,
						'status'        => 'pending',
						'queued_at'     => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s' )
				);
			}

			public function get_pending_emails( $limit ) {
				global $wpdb;
				$table   = esc_sql( $wpdb->prefix . 'npmp_newsletter_queue' );
				$status  = 'pending';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE status = %s ORDER BY queued_at ASC LIMIT %d",
						$status,
						$limit
					)
				);
			}

			public function update_status( $id, $status ) {
				global $wpdb;
				$table = esc_sql( $wpdb->prefix . 'npmp_newsletter_queue' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				return $wpdb->update(
					$table,
					array(
						'status'  => $status,
						'sent_at' => current_time( 'mysql' ),
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		};
	}

	/* ===================================================================
	 * Queue helpers
	 * =================================================================== */
	public static function queue_newsletter( $newsletter_id ) {
		$recipients    = self::get_recipient_list( $newsletter_id );
		$qm            = self::get_queue_data_manager();

		foreach ( $recipients as $user ) {
			$qm->add_to_queue( $newsletter_id, $user );
		}

		update_post_meta( $newsletter_id, '_npmp_newsletter_status', 'queued' );
		update_post_meta( $newsletter_id, '_npmp_newsletter_queued_at', current_time( 'mysql' ) );
	}

	public static function process_queue() {
		$limit = intval( get_option( 'npmp_newsletter_rate_limit', self::MAX_EMAILS_PER_SECOND ) );
		$qm    = self::get_queue_data_manager();
		$rows  = $qm->get_pending_emails( $limit );

		foreach ( $rows as $row ) {
			$content = self::get_newsletter_content( $row->newsletter_id, false, $row->user_id );
			$subject = get_the_title( $row->newsletter_id );
			$sent    = self::send_email( $row->email, $subject, $content );
			$qm->update_status( $row->id, $sent ? 'sent' : 'failed' );
		}
	}

	/* ===================================================================
	 * Build newsletter HTML
	 * =================================================================== */
	public static function get_newsletter_content( $newsletter_id, $is_test = false, $user_id = null ) {
		$post = get_post( $newsletter_id );
		if ( ! $post || 'npmp_newsletter' !== $post->post_type ) {
			return '';
		}

		$content = apply_filters( 'the_content', $post->post_content );

		if ( ! $is_test && $user_id ) {

			/* ---------- lightweight tracking “pixel” (as CSS background) ---------- */
			$pixel_url = esc_url_raw(
				add_query_arg(
					array(
						'uid' => intval( $user_id ),
						'nid' => intval( $newsletter_id ),
					),
					site_url( '/npmp-track/open' )
				)
			);
			$content .= sprintf(
				'<span class="npmp-track-open" style="display:inline-block;width:1px;height:1px;background:url(%s) no-repeat 0 0;"></span>',
				esc_url( $pixel_url )
			);

			/* ---------- link tracking ---------- */
			$tracker = NPMP_Newsletter_Tracker::get_instance();
			$dom     = new DOMDocument();
			@$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) );

			$replacements = array();
			foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
				$href = $a->getAttribute( 'href' );
				if ( $href && false === strpos( $href, 'npmp-track' ) ) {
					$replacements[ $href ] = $tracker->create_tracked_url( $href, $newsletter_id, $user_id );
				}
			}
			foreach ( $replacements as $orig => $tracked ) {
				$content = str_replace( 'href="' . $orig . '"', 'href="' . esc_url( $tracked ) . '"', $content );
			}
		}

		$content .= do_shortcode( '[npmp_can_spam]' );
		return $content;
	}

	/* ===================================================================
	 * Email send wrapper
	 * =================================================================== */
	public static function send_email( $to, $subject, $content ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return wp_mail( $to, $subject, $content, $headers );
	}

	/* ===================================================================
	 * Recipients
	 * =================================================================== */
	public static function get_recipient_list( $newsletter_id ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$args = array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'     => 'npmp_unsubscribed',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields'     => array( 'ID', 'user_email' ),
		);

		return get_users( $args );
	}
}
