<?php
defined( 'ABSPATH' ) || exit;

class NPMP_Newsletter_Manager {

	// Emails sent per cron run. The queue processor runs on an every-minute
	// schedule, so default throughput is 10/minute (600/hour), not 10/second
	// as the old constant name claimed.
	const EMAILS_PER_RUN = 10;
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
		$content = self::get_newsletter_content( $newsletter_id, true, null, $user_email );
		$subject = self::personalize_subject( get_the_title( $newsletter_id ), null, $user_email );
		return self::send_email( $user_email, $subject, $content, $newsletter_id );
	}

	/* ===================================================================
	 * Queue helpers
	 * =================================================================== */
	public static function queue_newsletter( $newsletter_id ) {
		global $wpdb;

		$newsletter_id = absint( $newsletter_id );
		$recipients    = self::apply_recipient_filter( self::get_recipient_list( $newsletter_id ), $newsletter_id );
		$table         = $wpdb->prefix . 'npmp_newsletter_queue';
		$queued        = 0;

		// Clear any prior queue rows for this newsletter so a re-send starts
		// clean instead of stacking on a previous run's entries.
		$wpdb->delete( $table, array( 'newsletter_id' => $newsletter_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, no caching layer needed for a write.

		$now = current_time( 'mysql' );

		// Gather valid recipient rows first, then insert in batches instead of
		// one $wpdb->insert() per recipient. A nonprofit with a few thousand
		// subscribed members previously meant a few thousand round-trips here.
		// Batching in groups of 500 cuts that to a handful of multi-row INSERTs
		// while inserting the exact same rows. The queue table has no unique
		// constraint beyond its auto-increment id (see activation-hooks.php),
		// so grouping rows into one statement can't turn an individual
		// duplicate-row skip into a whole-batch failure the way it might on a
		// uniquely-keyed table.
		$rows = array();
		foreach ( $recipients as $recipient ) {
			$email = sanitize_email( $recipient->user_email ?? '' );
			if ( ! $email ) {
				continue;
			}

			$rows[] = array(
				$newsletter_id,
				isset( $recipient->ID ) ? (int) $recipient->ID : 0,
				$email,
				'pending',
				$now,
			);
		}

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '(%d, %d, %s, %s, %s)' ) );
			$values       = array();
			foreach ( $chunk as $row ) {
				array_push( $values, ...$row );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, no caching layer needed for a write.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (newsletter_id, user_id, email, status, queued_at) VALUES {$placeholders}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name and a generated run of (%d,%d,%s,%s,%s) placeholders, one per row.
					$values
				)
			);

			if ( false !== $result ) {
				$queued += count( $chunk );
			}
		}

		update_post_meta( $newsletter_id, '_npmp_newsletter_status', $queued ? 'queued' : 'no_recipients' );
		update_post_meta( $newsletter_id, '_npmp_newsletter_queued_at', $now );
		update_post_meta( $newsletter_id, '_npmp_newsletter_recipient_total', $queued );

		$levels = get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true );
		/**
		 * Filter the audience label saved with a queued newsletter (Reports
		 * and the archive show it). Pro names the groups for a group newsletter.
		 *
		 * @param string $label         Label built from the levels.
		 * @param int    $newsletter_id Newsletter ID.
		 */
		$label = apply_filters( 'npmp_newsletter_audience_label', self::describe_audience( $levels ), $newsletter_id );
		update_post_meta( $newsletter_id, '_npmp_newsletter_audience_label', (string) $label );

		return $queued;
	}

	public static function process_queue() {
		// One run at a time. Two overlapping runs read the same pending rows
		// and mailed each recipient twice.
		if ( ! function_exists( 'npmp_mail_queue_lock' ) || ! npmp_mail_queue_lock( 'newsletter' ) ) {
			return;
		}
		try {
			self::drain_queue_batch();
		} finally {
			npmp_mail_queue_unlock( 'newsletter' );
		}
	}

	/**
	 * Send one batch of pending queue rows. Call process_queue(), which holds
	 * the run lock around this.
	 *
	 * @return void
	 */
	private static function drain_queue_batch() {
		global $wpdb;
		$table = $wpdb->prefix . 'npmp_newsletter_queue';

		$limit = intval( get_option( 'npmp_newsletter_rate_limit', self::EMAILS_PER_RUN ) );
		if ( $limit <= 0 ) {
			$limit = self::EMAILS_PER_RUN;
		}

		// id is a deterministic tiebreaker: a whole newsletter's rows share the
		// same queued_at (one queue_newsletter() call), so ordering by
		// queued_at alone would drain them in an undefined order across ticks.
		$batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, drained on a per-minute cron.
			$wpdb->prepare(
				"SELECT id, newsletter_id, user_id, email FROM {$table} WHERE status = 'pending' ORDER BY queued_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, only the LIMIT value is a placeholder.
				$limit
			)
		);

		if ( empty( $batch ) ) {
			return;
		}

		// newsletter_id => whether any of its rows sent successfully this run,
		// used below to decide the completion milestone.
		$touched = array();
		$bodies  = array();

		foreach ( $batch as $row ) {
			$queue_id      = (int) $row->id;
			$newsletter_id = (int) $row->newsletter_id;
			$user_id       = (int) $row->user_id;
			$email         = sanitize_email( $row->email );

			if ( ! $newsletter_id || ! $email ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
					$table,
					array(
						'status'  => 'failed',
						'sent_at' => current_time( 'mysql' ),
					),
					array( 'id' => $queue_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( $newsletter_id && ! isset( $touched[ $newsletter_id ] ) ) {
					$touched[ $newsletter_id ] = false;
				}
				continue;
			}

			// Render the shared body once per newsletter per batch. It used to
			// run the_content filters and the template for every recipient.
			if ( ! array_key_exists( $newsletter_id, $bodies ) ) {
				$bodies[ $newsletter_id ] = self::render_base_content( $newsletter_id );
			}
			if ( null === $bodies[ $newsletter_id ] ) {
				// The newsletter was deleted after it was queued. Mailing an
				// empty body with only the footer helps nobody.
				$sent = false;
			} else {
				$content = self::personalize_content( $bodies[ $newsletter_id ], $newsletter_id, false, $user_id, $email );
				$subject = self::personalize_subject( get_the_title( $newsletter_id ), $user_id, $email );
				$sent    = self::send_email( $email, $subject, $content, $newsletter_id );
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
				$table,
				array(
					'status'  => $sent ? 'sent' : 'failed',
					'sent_at' => current_time( 'mysql' ),
				),
				array( 'id' => $queue_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( ! isset( $touched[ $newsletter_id ] ) ) {
				$touched[ $newsletter_id ] = false;
			}
			if ( $sent ) {
				$touched[ $newsletter_id ] = true;
			}
		}

		// Mark each newsletter drained this run as fully sent once nothing is
		// left pending for it. Checked once per newsletter after the batch, not
		// once per email as the old code did. Marking on drain (rather than
		// only inside a successful send, as before) also stops a newsletter
		// whose final recipient fails from being stuck in the "queued" state.
		foreach ( $touched as $newsletter_id => $any_sent ) {
			$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE newsletter_id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
					$newsletter_id
				)
			);

			if ( 0 === $pending ) {
				update_post_meta( $newsletter_id, '_npmp_newsletter_status', 'sent' );

				// A fully sent newsletter is a real usage milestone.
				if ( $any_sent && function_exists( 'npmp_mark_milestone' ) ) {
					npmp_mark_milestone( 'newsletter' );
				}
			}
		}
	}

	/* ===================================================================
	 * Build newsletter HTML
	 * =================================================================== */
	/**
	 * The finished HTML of one newsletter for one recipient.
	 *
	 * @param int         $newsletter_id Newsletter ID.
	 * @param bool        $is_test       True for "Send test" (no tracking).
	 * @param int|null    $user_id       Recipient contact ID, for tracking.
	 * @param string      $email         Recipient address, for the footer links.
	 * @return string
	 */
	public static function get_newsletter_content( $newsletter_id, $is_test = false, $user_id = null, $email = '' ) {
		$base = self::render_base_content( $newsletter_id );
		if ( null === $base ) {
			return '';
		}
		return self::personalize_content( $base, $newsletter_id, $is_test, $user_id, $email );
	}

	/**
	 * The part of a newsletter that is the same for every recipient: the
	 * rendered content, the template, and the preheader.
	 *
	 * @param int $newsletter_id Newsletter ID.
	 * @return string|null Null when the post isn't a newsletter.
	 */
	private static function render_base_content( $newsletter_id ) {
		$post = get_post( $newsletter_id );
		if ( ! $post || 'npmp_newsletter' !== $post->post_type ) {
			return null;
		}

		// the_content, so newsletter blocks and shortcodes render, minus what
		// other plugins add for a browser only.
		$content = npmp_email_the_content( $post->post_content );

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

		// the_content leaves every image dressed for a browser (srcset, sizes,
		// loading, decoding) and leaves a relative src relative, which no mail
		// client can resolve. Both lose the image in the inbox (ROS-4261).
		if ( function_exists( 'npmp_email_prepare_images' ) ) {
			$content = npmp_email_prepare_images( $content );
		}

		// A link is stored exactly as it was typed, so the link on an image, or
		// on anything else, can be relative too and a mail client has no page to
		// resolve it against. Runs before click tracking, which only follows an
		// http or https link (ROS-4272).
		if ( function_exists( 'npmp_email_prepare_links' ) ) {
			$content = npmp_email_prepare_links( $content );
		}

		$preheader = trim( (string) get_post_meta( $newsletter_id, '_npmp_newsletter_preheader', true ) );
		if ( '' !== $preheader ) {
			$hidden  = esc_html( $preheader ) . str_repeat( '&nbsp;', 10 );
			$content = '<span style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $hidden . '</span>' . $content;
		}

		return $content;
	}

	/**
	 * Add one recipient's tracking and footer links to the shared body.
	 *
	 * @param string   $content       Output of render_base_content().
	 * @param int      $newsletter_id Newsletter ID.
	 * @param bool     $is_test       True for "Send test" (no tracking).
	 * @param int|null $user_id       Recipient contact ID, for tracking.
	 * @param string   $email         Recipient address, for the footer links.
	 * @return string
	 */
	private static function personalize_content( $content, $newsletter_id, $is_test, $user_id, $email ) {
		$content = self::fill_recipient_tags( $content, $user_id, $email, true );

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
				$content = self::track_links( $content, $newsletter_id, $user_id );
			}
		}

		$content .= function_exists( 'npmp_can_spam_shortcode' )
			? npmp_can_spam_shortcode(
				array(
					'newsletter_id' => (int) $newsletter_id,
					'email'         => (string) $email,
				)
			)
			: do_shortcode( '[npmp_can_spam]' );
		return $content;
	}

	/**
	 * Fill a recipient's tags in a newsletter subject.
	 *
	 * @param string   $subject Subject, from get_the_title().
	 * @param int|null $user_id Recipient contact ID.
	 * @param string   $email   Recipient address.
	 * @return string
	 */
	public static function personalize_subject( $subject, $user_id, $email ) {
		// get_the_title() escapes for HTML. Mail headers want the plain text.
		$subject = html_entity_decode( (string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::fill_recipient_tags( $subject, $user_id, $email, false );
	}

	/**
	 * Swap [first_name], [last_name], [name] and any tag Pro adds for one
	 * recipient's values. The contact is looked up only when the text holds a
	 * tag, so a newsletter without one costs nothing per recipient.
	 *
	 * @param string   $text    Body or subject.
	 * @param int|null $user_id Recipient contact ID.
	 * @param string   $email   Recipient address.
	 * @param bool     $html    True for the body.
	 * @return string
	 */
	private static function fill_recipient_tags( $text, $user_id, $email, $html ) {
		if ( ! function_exists( 'npmp_email_fill_recipient_tags' ) || false === strpos( (string) $text, '[' ) ) {
			return (string) $text;
		}

		$contact = null;
		if ( class_exists( 'NPMP_Member_Manager' ) ) {
			$manager = NPMP_Member_Manager::get_instance();
			$contact = $user_id ? $manager->get_member_by_id( $user_id ) : null;
			if ( ! $contact && $email ) {
				$contact = $manager->get_member_by_email( $email );
			}
		}

		/**
		 * Filter the per-recipient tags a newsletter body and subject can use.
		 * Free fills first_name, last_name and name. A key added here works as
		 * [key] and [key default="..."].
		 *
		 * @param array<string,string> $values  Tag (without brackets) => raw value.
		 * @param object|null          $contact Contact record, null when not found.
		 * @param string               $email   Recipient address.
		 */
		$values = (array) apply_filters(
			'npmp_email_recipient_tags',
			npmp_email_recipient_tag_values( $contact ? (string) $contact->name : '' ),
			$contact,
			(string) $email
		);

		return npmp_email_fill_recipient_tags( $text, $values, $html );
	}

	/**
	 * Point every web link in a body at the click tracker.
	 *
	 * Only http and https links are wrapped. A mailto:, tel:, or #anchor link
	 * sent through the tracker redirect stopped working. A link whose href holds
	 * an entity (&amp; in a query string) is matched in its written form too,
	 * since DOMDocument hands back the decoded value.
	 *
	 * @param string $content       HTML body.
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id       Recipient contact ID.
	 * @return string
	 */
	public static function track_links( $content, $newsletter_id, $user_id ) {
		$tracker = NPMP_Newsletter_Tracker::get_instance();
		$dom     = new DOMDocument();

		// DOMDocument::loadHTML() assumes ISO-8859-1 without an explicit
		// charset, which mangles multi-byte UTF-8 characters. The old fix
		// was mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ), but
		// PHP 8.2 deprecated passing 'HTML-ENTITIES' to mb_convert_encoding().
		// mb_encode_numericentity() with this convmap (every code point from
		// U+0080 up) produces the same effect (every non-ASCII character
		// becomes a numeric HTML entity) without the deprecation.
		$convmap               = array( 0x80, 0x10FFFF, 0, 0x1FFFFF );
		$libxml_previous_state = libxml_use_internal_errors( true );
		$dom->loadHTML( mb_encode_numericentity( $content, $convmap, 'UTF-8' ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous_state );

		$replacements = array();
		foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( '' === $href || false !== strpos( $href, 'npmp_track=' ) || ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$replacements[ $href ] = $tracker->create_tracked_url( $href, $newsletter_id, $user_id );
		}

		foreach ( $replacements as $orig => $tracked ) {
			$new     = 'href="' . esc_url( $tracked ) . '"';
			$content = str_replace( 'href="' . $orig . '"', $new, $content );
			$encoded = str_replace( '&', '&amp;', $orig );
			if ( $encoded !== $orig ) {
				$content = str_replace( 'href="' . $encoded . '"', $new, $content );
			}
		}

		return $content;
	}

	/* ===================================================================
	 * Email send wrapper
	 * =================================================================== */
	/**
	 * Send one newsletter message through npmp_send_mail().
	 *
	 * @param string $to            Recipient address.
	 * @param string $subject       Subject line.
	 * @param string $content       HTML body.
	 * @param int    $newsletter_id Newsletter ID, or 0 for a one-off (an event email test).
	 * @return bool
	 */
	public static function send_email( $to, $subject, $content, $newsletter_id = 0 ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Add one-click List-Unsubscribe headers (Gmail/Yahoo bulk-sender rules).
		if ( function_exists( 'npmp_get_list_unsubscribe_headers' ) ) {
			$headers = array_merge( $headers, npmp_get_list_unsubscribe_headers( $to ) );
		}

		/**
		 * Filter the headers of one newsletter message. Pro adds a group's
		 * sender name (`From: Art Group <site-from@example.org>`, keeping the
		 * site's From address for SPF and DKIM) and its `Reply-To`. Keep
		 * List-Unsubscribe as it is, since Gmail and Yahoo treat it as global.
		 *
		 * @param string[] $headers       Header lines.
		 * @param int      $newsletter_id Newsletter ID (0 for a one-off test).
		 * @param string   $email         Recipient address.
		 */
		$filtered = apply_filters( 'npmp_newsletter_mail_headers', $headers, (int) $newsletter_id, (string) $to );
		if ( is_array( $filtered ) ) {
			$headers = $filtered;
		}

		return npmp_send_mail( $to, $subject, $content, $headers );
	}

	/* ===================================================================
	 * Recipients
	 * =================================================================== */
	/**
	 * Let add-ons replace the audience (Pro's segments do), then keep only
	 * subscribed contacts.
	 *
	 * Pro hooked npmp_newsletter_recipients, but the filter was never applied,
	 * so a newsletter sent to a 20-person segment went to the whole audience.
	 * Filtered lists may come back as objects (ID, user_email) or arrays
	 * (user_id, email). Either way nobody unsubscribed is mailed.
	 *
	 * @param array $recipients    Recipient objects from get_recipient_list().
	 * @param int   $newsletter_id Newsletter ID.
	 * @return array Recipient objects.
	 */
	public static function apply_recipient_filter( $recipients, $newsletter_id ) {
		if ( ! has_filter( 'npmp_newsletter_recipients' ) ) {
			return $recipients;
		}
		$filtered = apply_filters( 'npmp_newsletter_recipients', $recipients, $newsletter_id );
		if ( $filtered === $recipients || ! is_array( $filtered ) ) {
			return $recipients;
		}

		$subscribed = null;
		if ( class_exists( 'NPMP_Member_Manager' ) ) {
			$subscribed = array();
			foreach ( NPMP_Member_Manager::get_instance()->get_members( array( 'per_page' => -1, 'status' => 'subscribed' ) ) as $member ) {
				$subscribed[ strtolower( (string) ( $member->email ?? '' ) ) ] = true;
			}
		}

		$out = array();
		foreach ( $filtered as $row ) {
			$row   = (array) $row;
			$email = sanitize_email( (string) ( $row['user_email'] ?? $row['email'] ?? '' ) );
			if ( ! $email ) {
				continue;
			}
			$key = strtolower( $email );
			if ( null !== $subscribed && ! isset( $subscribed[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = (object) array(
				'ID'         => (int) ( $row['ID'] ?? $row['user_id'] ?? $row['id'] ?? 0 ),
				'user_email' => $email,
				'name'       => (string) ( $row['name'] ?? '' ),
			);
		}
		return array_values( $out );
	}

	public static function get_recipient_list( $newsletter_id ) {
		// A newsletter aimed at groups goes to no one unless Pro is here to
		// resolve the groups. Without this, a group-only newsletter on a site
		// that turned Pro off reads as "no levels picked" and mails everyone.
		$groups = get_post_meta( $newsletter_id, '_npmp_newsletter_groups', true );
		if ( ! empty( $groups ) && ! apply_filters( 'npmp_newsletter_groups_supported', false, (int) $newsletter_id ) ) {
			return array();
		}

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
