<?php
defined( 'ABSPATH' ) || exit;

class NPMP_Newsletter_Manager {

	const MAX_EMAILS_PER_SECOND = 10;
	const QUEUE_POST_TYPE       = 'npmp_nl_queue';
	const EVENT_POST_TYPE       = 'npmp_nl_event';
	const QUEUE_STATUS_META     = '_npmp_queue_status';
	const QUEUE_EMAIL_META      = '_npmp_queue_email';
	const QUEUE_NEWSLETTER_META = '_npmp_queue_newsletter';
	const QUEUE_USER_META       = '_npmp_queue_user';
	const QUEUE_QUEUED_META     = '_npmp_queue_queued_at';
	const QUEUE_SENT_META       = '_npmp_queue_sent_at';
	const EVENT_TYPE_META       = '_npmp_event_type';
	const EVENT_URL_META        = '_npmp_event_url';
	const EVENT_NEWSLETTER_META = '_npmp_event_newsletter';
	const EVENT_USER_META       = '_npmp_event_user';
	const EVENT_TIME_META       = '_npmp_event_timestamp';
	const ACTION_OPEN           = 'open';
	const ACTION_CLICK          = 'click';

	/**
	 * Convert a stored audience array into a readable label.
	 *
	 * @param array $levels Membership level keys.
	 * @return string
	 */
	public static function describe_audience( $levels ) {
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return __( 'All subscribed members', 'nonprofit-manager' );
		}

		// Check if "__all__" is explicitly selected
		if ( in_array( '__all__', $levels, true ) ) {
			return __( 'All subscribed members', 'nonprofit-manager' );
		}

		$labels = array();
		foreach ( $levels as $level ) {
			$level = sanitize_text_field( $level );
			if ( '__none__' === $level ) {
				$labels[] = __( 'Members without a level', 'nonprofit-manager' );
			} elseif ( '__all__' !== $level && '' !== $level ) {
				$labels[] = $level;
			}
		}

		if ( empty( $labels ) ) {
			return __( 'All subscribed members', 'nonprofit-manager' );
		}

		return implode( ', ', array_unique( $labels ) );
	}

	/* ===================================================================
	 * Test email
	 * =================================================================== */
	public static function send_test_email( $newsletter_id, $user_email ) {
		$content = self::get_newsletter_content( $newsletter_id, true );
		$subject = get_the_title( $newsletter_id );
		return self::send_email( $user_email, $subject, $content );
	}

	/* ===================================================================
	 * Queue helpers
	 * =================================================================== */
	public static function queue_newsletter( $newsletter_id ) {
		$recipients = self::get_recipient_list( $newsletter_id );
		$levels     = get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true );
		if ( ! is_array( $levels ) ) {
			$levels = array();
		}
		$queued     = 0;

		$existing = get_posts(
			array(
				'post_type'      => self::QUEUE_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue cleanup requires locating entries by stored metadata.
				'meta_query'     => array(
					array(
						'key'   => self::QUEUE_NEWSLETTER_META,
						'value' => absint( $newsletter_id ),
					),
				),
			)
		);

		foreach ( $existing as $queue_id ) {
			wp_delete_post( $queue_id, true );
		}

		foreach ( $recipients as $recipient ) {
			$email = sanitize_email( $recipient->user_email ?? '' );
			if ( ! $email ) {
				continue;
			}

			$queue_id = wp_insert_post(
				array(
					'post_type'   => self::QUEUE_POST_TYPE,
					'post_status' => 'publish',
					/* translators: %s: Recipient email address. */
					'post_title'  => sprintf( __( 'Queue for %s', 'nonprofit-manager' ), $email ),
					'meta_input'  => array(
						self::QUEUE_NEWSLETTER_META => $newsletter_id,
						self::QUEUE_USER_META       => isset( $recipient->ID ) ? (int) $recipient->ID : 0,
						self::QUEUE_EMAIL_META      => $email,
						self::QUEUE_STATUS_META     => 'pending',
						self::QUEUE_QUEUED_META     => current_time( 'mysql' ),
					),
				),
				true
			);

			if ( ! is_wp_error( $queue_id ) ) {
				$queued ++;
			}
		}

		update_post_meta( $newsletter_id, '_npmp_newsletter_status', $queued ? 'queued' : 'no_recipients' );
		update_post_meta( $newsletter_id, '_npmp_newsletter_queued_at', current_time( 'mysql' ) );
		update_post_meta( $newsletter_id, '_npmp_newsletter_recipient_total', $queued );

		$levels = get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true );
		update_post_meta( $newsletter_id, '_npmp_newsletter_audience_label', self::describe_audience( $levels ) );

		return $queued;
	}

	public static function process_queue() {
		$limit = intval( get_option( 'npmp_newsletter_rate_limit', self::MAX_EMAILS_PER_SECOND ) );
		if ( $limit <= 0 ) {
			$limit = self::MAX_EMAILS_PER_SECOND;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::QUEUE_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'meta_value',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Queue processing is ordered by meta timestamps.
				'meta_key'       => self::QUEUE_QUEUED_META,
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Selecting pending queue items is metadata-driven.
				'meta_query'     => array(
					array(
						'key'   => self::QUEUE_STATUS_META,
						'value' => 'pending',
					),
				),
			)
		);

		foreach ( $query->posts as $queue_id ) {
			$newsletter_id = (int) get_post_meta( $queue_id, self::QUEUE_NEWSLETTER_META, true );
			$user_id       = (int) get_post_meta( $queue_id, self::QUEUE_USER_META, true );
			$email         = sanitize_email( get_post_meta( $queue_id, self::QUEUE_EMAIL_META, true ) );

			if ( ! $newsletter_id || ! $email ) {
				update_post_meta( $queue_id, self::QUEUE_STATUS_META, 'failed' );
				continue;
			}

			$content = self::get_newsletter_content( $newsletter_id, false, $user_id );
			$subject = get_the_title( $newsletter_id );
			$sent    = self::send_email( $email, $subject, $content );

			update_post_meta( $queue_id, self::QUEUE_STATUS_META, $sent ? 'sent' : 'failed' );
			update_post_meta( $queue_id, self::QUEUE_SENT_META, current_time( 'mysql' ) );

			if ( $sent ) {
				$pending = new WP_Query(
					array(
						'post_type'      => self::QUEUE_POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Counts remaining queue entries via metadata.
						'meta_query'     => array(
							array(
								'key'   => self::QUEUE_NEWSLETTER_META,
								'value' => $newsletter_id,
							),
							array(
								'key'   => self::QUEUE_STATUS_META,
								'value' => 'pending',
							),
						),
					)
				);

				if ( 0 === $pending->post_count ) {
					update_post_meta( $newsletter_id, '_npmp_newsletter_status', 'sent' );
				}
			}
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

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Use core 'the_content' filters so newsletter blocks/shortcodes render correctly.
			$content = apply_filters( 'the_content', $post->post_content );

		// Apply newsletter template if configured
		$selected_template = get_post_meta( $newsletter_id, '_npmp_newsletter_template', true );
		if ( function_exists( 'npmp_apply_newsletter_template' ) && $selected_template !== 'none' ) {
			if ( empty( $selected_template ) || '' === $selected_template ) {
				// Use default template
				$content = npmp_apply_newsletter_template( $content );
			} elseif ( is_numeric( $selected_template ) ) {
				// Use specific template
				$content = npmp_apply_newsletter_template( $content, absint( $selected_template ) );
			}
		}

		$preheader = trim( (string) get_post_meta( $newsletter_id, '_npmp_newsletter_preheader', true ) );
		if ( '' !== $preheader ) {
			$hidden  = esc_html( $preheader ) . str_repeat( '&nbsp;', 10 );
			$content = '<span style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $hidden . '</span>' . $content;
		}
		$track_opens  = (bool) get_option( 'npmp_newsletter_track_opens', true );
		$track_clicks = (bool) get_option( 'npmp_newsletter_track_clicks', false );

		if ( ! $is_test && $user_id ) {

			if ( $track_opens ) {
				$pixel_url = NPMP_Newsletter_Tracker::get_instance()->get_open_pixel_url( $newsletter_id, $user_id );
				if ( $pixel_url ) {
					$content .= sprintf(
						'<img src="%s" alt="" width="1" height="1" style="display:none;" />',
						esc_url( $pixel_url )
					);
				}
			}

			if ( $track_clicks ) {
				$tracker = NPMP_Newsletter_Tracker::get_instance();
				$dom     = new DOMDocument();

				$libxml_previous_state = libxml_use_internal_errors( true );
				$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) );
				libxml_clear_errors();
				libxml_use_internal_errors( $libxml_previous_state );

				$replacements = array();
				foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
					$href = $a->getAttribute( 'href' );
					if ( $href && false === strpos( $href, 'npmp_track=' ) ) {
						$replacements[ $href ] = $tracker->create_tracked_url( $href, $newsletter_id, $user_id );
					}
				}

				foreach ( $replacements as $orig => $tracked ) {
					$content = str_replace( 'href="' . $orig . '"', 'href="' . esc_url( $tracked ) . '"', $content );
				}
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
	public static function get_recipient_list( $newsletter_id ) {
		$levels = get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true );
		if ( ! is_array( $levels ) ) {
			$levels = array();
		}
		$levels = array_map( 'sanitize_text_field', $levels );

		// Check if "__all__" is explicitly selected
		$send_to_all = in_array( '__all__', $levels, true );

		if ( class_exists( 'NPMP_Member_Manager' ) ) {
			$manager    = NPMP_Member_Manager::get_instance();
			$recipients = array();

			$collect = static function ( $member ) use ( &$recipients ) {
				$email = sanitize_email( $member->email ?? '' );
				if ( ! $email ) {
					return;
				}

				$key = strtolower( $email );
				$recipients[ $key ] = (object) array(
					'ID'         => isset( $member->id ) ? (int) $member->id : 0,
					'user_email' => $email,
					'name'       => isset( $member->name ) ? $member->name : '',
				);
			};

			if ( empty( $levels ) || $send_to_all ) {
				$members = $manager->get_members(
					array(
						'per_page' => -1,
						'status'   => 'subscribed',
					)
				);
				foreach ( $members as $member ) {
					$collect( $member );
				}
			} else {
				$handled_none = false;
				foreach ( $levels as $level ) {
					if ( '__none__' === $level ) {
						if ( ! $handled_none ) {
							$handled_none = true;
							$members = $manager->get_members(
								array(
									'per_page' => -1,
									'status'   => 'subscribed',
								)
							);
							foreach ( $members as $member ) {
								$current_level = isset( $member->membership_level ) ? trim( (string) $member->membership_level ) : '';
								if ( '' === $current_level ) {
									$collect( $member );
								}
							}
						}
						continue;
					}

					$members = $manager->get_members(
						array(
							'per_page' => -1,
							'status'   => 'subscribed',
							'level'    => $level,
						)
					);
					foreach ( $members as $member ) {
						$collect( $member );
					}
				}
			}

			return array_values( $recipients );
		}

		$args = array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Subscriber opt-out status is stored in user meta.
			'meta_query' => array(
				array(
					'key'     => 'npmp_unsubscribed',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields' => array( 'ID', 'user_email' ),
		);

		return get_users( $args );
	}
}
