<?php
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/npmp-mb-compat.php';

class NPMP_Newsletter_Manager {

	// Emails sent per cron run. The queue processor runs on an every-minute
	// schedule, so default throughput is 10/minute (600/hour), not 10/second
	// as the old constant name claimed.
	const EMAILS_PER_RUN = 10;

	// A batch stops starting sends after this many seconds, so it ends well
	// inside the run lock's 300 seconds (npmp_mail_queue_batch_continues()).
	const BATCH_SECONDS = 240;

	// A batch stops after this many failed sends in a row. When the mail
	// server is down, every row tried would use up a try, so we leave the
	// rest of the queue waiting until the server answers again.
	const FAILURES_IN_A_ROW = 3;

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
	 * Readers the last queue_newsletter() call left out because they already
	 * got that newsletter.
	 *
	 * @var int
	 */
	private static $last_already_sent = 0;

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
		$subject = self::fill_test_recipient_tags( html_entity_decode( (string) get_the_title( $newsletter_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $user_email, false );
		return self::send_email( $user_email, $subject, $content, $newsletter_id );
	}

	/* ===================================================================
	 * Queue helpers
	 * =================================================================== */
	public static function queue_newsletter( $newsletter_id ) {
		global $wpdb;

		$newsletter_id           = absint( $newsletter_id );
		self::$last_already_sent = 0;
		// A newsletter imported from another plugin already went out from there.
		if ( function_exists( 'npmp_newsletter_is_imported' ) && npmp_newsletter_is_imported( $newsletter_id ) ) {
			return 0;
		}
		$recipients    = self::apply_recipient_filter( self::get_recipient_list( $newsletter_id ), $newsletter_id );
		$table         = $wpdb->prefix . 'npmp_newsletter_queue';

		// Send now pressed again, or a Send later after Send now, while the
		// queue still drains. Readers we already mailed keep their sent rows
		// and never get a second copy, and readers still waiting keep their
		// row, so a batch that's mailing right now can't mail them twice.
		// Failed rows start over, so a failed address gets another try, and a
		// waiting row for someone the new audience leaves out goes away.
		$audience = array();
		foreach ( $recipients as $recipient ) {
			$email = sanitize_email( $recipient->user_email ?? '' );
			if ( $email ) {
				$audience[ strtolower( $email ) ] = true;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, read right before the writes below.
		$existing = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, email, status FROM {$table} WHERE newsletter_id = %d", $newsletter_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
		$sent     = array();
		$waiting  = array();
		$drop     = array();
		foreach ( $existing as $row ) {
			$key = strtolower( (string) $row->email );
			if ( 'sent' === $row->status ) {
				$sent[ $key ] = true;
			} elseif ( 'pending' === $row->status && isset( $audience[ $key ] ) && ! isset( $waiting[ $key ] ) ) {
				$waiting[ $key ] = true;
			} else {
				$drop[] = (int) $row->id;
			}
		}
		foreach ( array_chunk( $drop, 500 ) as $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, no caching layer needed for a write.
			$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name and integer IDs.
		}
		$queued = count( $waiting );

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
			$key = strtolower( $email );
			if ( isset( $sent[ $key ] ) ) {
				++self::$last_already_sent;
				continue;
			}
			if ( isset( $waiting[ $key ] ) ) {
				continue;
			}
			// Twice in the list (two contacts with one address) is one email.
			$waiting[ $key ] = true;

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

		if ( $queued ) {
			$status = 'queued';
		} elseif ( self::$last_already_sent ) {
			// Everyone in the audience already got it, so nothing waits.
			$status = 'sent';
		} else {
			$status = 'no_recipients';
		}
		update_post_meta( $newsletter_id, '_npmp_newsletter_status', $status );
		// Reports and the public archive show this as the send date, so a
		// queue run that adds no one leaves it alone.
		if ( 'sent' !== $status ) {
			update_post_meta( $newsletter_id, '_npmp_newsletter_queued_at', $now );
		}
		// Every row the newsletter has, the sent rows kept above included, so
		// this matches Reports' Total Recipients.
		update_post_meta( $newsletter_id, '_npmp_newsletter_recipient_total', $queued + count( $sent ) );

		update_post_meta( $newsletter_id, '_npmp_newsletter_audience_label', self::audience_label( $newsletter_id ) );

		return $queued;
	}

	/**
	 * Whether a queued reader is still a subscribed contact.
	 *
	 * @param array  $contacts From NPMP_Member_Manager::lookup_emails().
	 * @param string $email    Queued address.
	 * @return bool
	 */
	public static function still_subscribed( $contacts, $email ) {
		$key = strtolower( (string) $email );
		return isset( $contacts[ $key ] ) && 'subscribed' === ( $contacts[ $key ]['status'] ?? '' );
	}

	/**
	 * How many readers the last queue_newsletter() call left out because
	 * they already got that newsletter.
	 *
	 * @return int
	 */
	public static function last_queue_already_sent() {
		return (int) self::$last_already_sent;
	}

	/**
	 * Who a newsletter goes to, in words, from its saved levels plus what
	 * an add-on adds through the filter (Pro names its groups).
	 *
	 * @param int $newsletter_id Newsletter ID.
	 * @return string
	 */
	public static function audience_label( $newsletter_id ) {
		$levels = get_post_meta( $newsletter_id, '_npmp_newsletter_levels', true );
		/**
		 * Filter a newsletter's audience label. The editor's Current
		 * selection, the Newsletter Archive screen, Reports, and the public
		 * archive box show it. Pro names the groups for a group newsletter.
		 *
		 * @param string $label         Label built from the levels.
		 * @param int    $newsletter_id Newsletter ID.
		 */
		return (string) apply_filters( 'npmp_newsletter_audience_label', self::describe_audience( $levels ), (int) $newsletter_id );
	}

	public static function process_queue() {
		// One run at a time. Two overlapping runs read the same pending rows
		// and mailed each recipient twice.
		if ( ! function_exists( 'npmp_mail_queue_lock' ) || ! npmp_mail_queue_lock( 'newsletter' ) ) {
			return;
		}
		try {
			// The schema upgrade runs here and on admin screens, never on a
			// visitor's page load. Inside the lock, so a second cron runner
			// that starts meanwhile leaves the table alone.
			if ( function_exists( 'npmp_maybe_create_newsletter_queue_table' ) ) {
				npmp_maybe_create_newsletter_queue_table();
			}
			self::drain_queue_batch();
		} finally {
			npmp_mail_queue_unlock( 'newsletter' );
		}
	}

	/**
	 * Seconds to wait before each new try of a send that failed, in order.
	 * The count is the number of retries, so a row gets one try plus one per
	 * entry before we mark it failed. The default waits 5, 15, and 45
	 * minutes, which carries a send through about an hour of mail server
	 * trouble.
	 *
	 * @return int[]
	 */
	public static function retry_delays() {
		/**
		 * Filter the waits between tries of a newsletter send that failed.
		 * Return an empty array to mark a failed send failed at once.
		 *
		 * @param int[] $delays Seconds before each retry, in order.
		 */
		$delays = apply_filters( 'npmp_newsletter_retry_delays', array( 5 * 60, 15 * 60, 45 * 60 ) );
		return is_array( $delays ) ? array_slice( array_map( 'absint', array_values( $delays ) ), 0, 10 ) : array();
	}

	/**
	 * What a queue row becomes after a send that failed.
	 *
	 * @param int   $attempts Sends tried for the row before this one.
	 * @param int[] $delays   From retry_delays().
	 * @return array{status:string,attempts:int,delay:int} status 'pending'
	 *         with the seconds to wait, or 'failed' after the last try.
	 */
	public static function after_failed_send( $attempts, $delays ) {
		$attempts = max( 0, (int) $attempts ) + 1;
		$delays   = array_values( (array) $delays );
		if ( $attempts > count( $delays ) ) {
			return array(
				'status'   => 'failed',
				'attempts' => $attempts,
				'delay'    => 0,
			);
		}
		return array(
			'status'   => 'pending',
			'attempts' => $attempts,
			'delay'    => max( 0, (int) $delays[ $attempts - 1 ] ),
		);
	}

	/**
	 * Send one batch of pending queue rows. Call process_queue(), which holds
	 * the run lock around this.
	 *
	 * We put a send that fails back in the queue with its next try in
	 * retry_delays(), and mark the row failed only after the last try.
	 * Through 2026.11.26 we marked it failed at once, so one minute of mail
	 * server trouble cost that run's whole batch, and we called the newsletter
	 * sent once nothing else waited.
	 *
	 * @return void
	 */
	private static function drain_queue_batch() {
		global $wpdb;
		$table   = $wpdb->prefix . 'npmp_newsletter_queue';
		$started = microtime( true );

		// Until the table has attempts and retry_at, a failed send is final,
		// the way it was through 2026.11.26.
		$retries = function_exists( 'npmp_newsletter_queue_has_retries' ) && npmp_newsletter_queue_has_retries();

		$limit = intval( get_option( 'npmp_newsletter_rate_limit', self::EMAILS_PER_RUN ) );
		if ( $limit <= 0 ) {
			$limit = self::EMAILS_PER_RUN;
		}

		// id is a deterministic tiebreaker: a whole newsletter's rows share the
		// same queued_at (one queue_newsletter() call), so ordering by
		// queued_at alone would drain them in an undefined order across ticks.
		// The status_queued index serves both queries.
		if ( $retries ) {
			$batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, drained on a per-minute cron.
				$wpdb->prepare(
					"SELECT id, newsletter_id, user_id, email, attempts FROM {$table} WHERE status = 'pending' AND ( retry_at IS NULL OR retry_at <= %s ) ORDER BY queued_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, the time and the LIMIT are placeholders.
					gmdate( 'Y-m-d H:i:s' ),
					$limit
				)
			);
		} else {
			$batch = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table, drained on a per-minute cron.
				$wpdb->prepare(
					"SELECT id, newsletter_id, user_id, email FROM {$table} WHERE status = 'pending' ORDER BY queued_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, only the LIMIT value is a placeholder.
					$limit
				)
			);
		}

		if ( empty( $batch ) ) {
			return;
		}

		// newsletter_id => whether any of its rows sent successfully this run,
		// used below to decide the completion milestone.
		$touched = array();
		$bodies  = array();
		$gone    = array();

		/**
		 * Filter how many seconds one queue batch may work before it stops
		 * starting sends. Keep it under 300, the run lock's life.
		 *
		 * @param int    $seconds Seconds of work.
		 * @param string $queue   'newsletter' or 'digest'.
		 */
		$budget          = (float) apply_filters( 'npmp_mail_queue_batch_seconds', self::BATCH_SECONDS, 'newsletter' );
		$delays          = $retries ? self::retry_delays() : array();
		$failed_in_a_row = 0;

		// Someone can unsubscribe, or an admin can delete or change a contact,
		// after the newsletter was queued. Look the batch up once and mail
		// only contacts who are still subscribed.
		$contacts = class_exists( 'NPMP_Member_Manager' ) ? NPMP_Member_Manager::get_instance()->lookup_emails( wp_list_pluck( $batch, 'email' ) ) : null;

		foreach ( $batch as $row ) {
			// Rows we don't reach keep waiting, untouched, for the next run.
			if ( $failed_in_a_row >= self::FAILURES_IN_A_ROW || ! npmp_mail_queue_batch_continues( 'newsletter', $started, $budget ) ) {
				break;
			}

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

			if ( null !== $contacts && ! self::still_subscribed( $contacts, $email ) ) {
				// Their row goes away, so Reports counts only the readers we
				// mailed or tried to, and a later Send now reaches them only if
				// they subscribe again.
				$wpdb->delete( $table, array( 'id' => $queue_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
				if ( ! isset( $touched[ $newsletter_id ] ) ) {
					$touched[ $newsletter_id ] = false;
				}
				continue;
			}

			// Render the shared body once per newsletter per batch. It used to
			// run the_content filters and the template for every recipient.
			if ( ! array_key_exists( $newsletter_id, $bodies ) ) {
				// An imported newsletter never mails, even from a row someone added by hand.
				$imported                 = function_exists( 'npmp_newsletter_is_imported' ) && npmp_newsletter_is_imported( $newsletter_id );
				$bodies[ $newsletter_id ] = $imported ? null : self::render_base_content( $newsletter_id );
			}
			if ( null === $bodies[ $newsletter_id ] ) {
				// The newsletter was deleted after it was queued. Mailing an
				// empty body with only the footer helps nobody, and writing its
				// status would leave meta on a post that no longer exists. Clear
				// every row it still has waiting, this one included.
				if ( ! isset( $gone[ $newsletter_id ] ) ) {
					$wpdb->delete( $table, array( 'newsletter_id' => $newsletter_id, 'status' => 'pending' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
					$gone[ $newsletter_id ] = true;
				}
				continue;
			} else {
				// The batch and the contacts were read before the first send, and
				// this run can last 240 seconds. Read this row and its contact
				// once more, so a reader erased or unsubscribed since then, or a
				// row another request settled, gets nothing.
				$contact_id = $user_id;
				$key        = strtolower( $email );
				if ( null !== $contacts && isset( $contacts[ $key ]['id'] ) ) {
					$contact_id = (int) $contacts[ $key ]['id'];
				}
				$fresh = npmp_queue_recheck_send( $table, $queue_id, $contact_id );
				if ( null === $fresh['row'] || 'pending' !== $fresh['row'] ) {
					// Gone, or sent or failed by another request: leave it alone.
					continue;
				}
				if ( null !== $fresh['contact'] && ( ! $fresh['contact']['exists'] || ! self::still_subscribed( array( $key => array( 'status' => $fresh['contact']['status'] ) ), $email ) ) ) {
					$wpdb->delete( $table, array( 'id' => $queue_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
					if ( ! isset( $touched[ $newsletter_id ] ) ) {
						$touched[ $newsletter_id ] = false;
					}
					continue;
				}

				$content = self::personalize_content( $bodies[ $newsletter_id ], $newsletter_id, false, $user_id, $email );
				$subject = self::personalize_subject( get_the_title( $newsletter_id ), $user_id, $email );
				$sent    = self::send_email( $email, $subject, $content, $newsletter_id );
			}

			$tries = isset( $row->attempts ) ? (int) $row->attempts : 0;
			if ( $sent ) {
				$failed_in_a_row = 0;
				$data            = array(
					'status'  => 'sent',
					'sent_at' => current_time( 'mysql' ),
				);
				if ( $retries ) {
					$data['attempts'] = min( 255, $tries + 1 );
				}
			} else {
				++$failed_in_a_row;
				$next = self::after_failed_send( $tries, $delays );
				$data = array( 'status' => $next['status'] );
				if ( 'failed' === $next['status'] ) {
					$data['sent_at'] = current_time( 'mysql' );
				}
				if ( $retries ) {
					$data['attempts'] = min( 255, $next['attempts'] );
					if ( 'pending' === $next['status'] ) {
						$data['retry_at'] = gmdate( 'Y-m-d H:i:s', time() + $next['delay'] );
					}
				}
			}

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated queue table.
				$table,
				$data,
				array( 'id' => $queue_id ),
				array_map( static fn( $column ) => 'attempts' === $column ? '%d' : '%s', array_keys( $data ) ),
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
		// left pending for it. A row waiting for a retry is still pending, so
		// its newsletter reads queued until the last try. Checked once per newsletter after the batch, not
		// once per email as the old code did. Marking on drain (rather than
		// only inside a successful send, as before) also stops a newsletter
		// whose final recipient fails from being stuck in the "queued" state.
		foreach ( $touched as $newsletter_id => $any_sent ) {
			if ( isset( $gone[ $newsletter_id ] ) || 'npmp_newsletter' !== get_post_type( $newsletter_id ) ) {
				continue;
			}
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
	public static function render_base_content( $newsletter_id ) {
		$post = get_post( $newsletter_id );
		if ( ! $post || 'npmp_newsletter' !== $post->post_type ) {
			return null;
		}

		// The email gets a link in place of each embed, to the web version
		// when there is one. The web version (npmp_newsletter_archive_body())
		// keeps the players (includes/email-newsletter/email-embeds.php).
		$for_email = function_exists( 'npmp_email_embed_target' ) && ! npmp_newsletter_web_render();
		if ( $for_email ) {
			npmp_email_embed_target( npmp_newsletter_web_version_url( $newsletter_id ) );
		}

		// the_content, so newsletter blocks and shortcodes render, minus what
		// other plugins add for a browser only.
		$content = npmp_email_the_content( $post->post_content );

		// Apply newsletter template if configured
		$selected_template = get_post_meta( $newsletter_id, '_npmp_newsletter_template', true );
		// A saved template someone deleted or trashed counts as no choice: the
		// picker reopens on "Use Default Template", so the email gets the
		// default too, the way an event's email does
		// (npmp_scheduled_emails_pick_template()).
		if ( is_numeric( $selected_template ) && function_exists( 'npmp_newsletter_template_exists' ) && ! npmp_newsletter_template_exists( (int) $selected_template ) ) {
			$selected_template = '';
		}
		if ( function_exists( 'npmp_apply_newsletter_template' ) && $selected_template !== 'none' ) {
			if ( empty( $selected_template ) || '' === $selected_template ) {
				// Use default template
				$content = npmp_apply_newsletter_template( $content );
			} elseif ( is_numeric( $selected_template ) ) {
				// Use specific template
				$content = npmp_apply_newsletter_template( $content, absint( $selected_template ) );
			}
		}

		if ( $for_email ) {
			$content = npmp_email_embed_swap_iframes( $content, npmp_email_embed_target() );
			npmp_email_embed_target( false );
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
		$content = ( $is_test && ! $user_id )
			? self::fill_test_recipient_tags( $content, (string) $email, true )
			: self::fill_recipient_tags( $content, $user_id, $email, true );

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
		return self::fill_tags_for( $text, $user_id, $email, $html, false );
	}

	/**
	 * Fill the per-recipient tags of a test message for the address it goes
	 * to: that contact's name, or, for a site user with no contact, the first
	 * and last name on their WordPress profile. A real send fills them the
	 * same way for each member, so a test reads like what members get.
	 *
	 * @param string $text  Body or subject.
	 * @param string $email Address the test goes to.
	 * @param bool   $html  True for the body.
	 * @return string
	 */
	public static function fill_test_recipient_tags( $text, $email, $html ) {
		return self::fill_tags_for( $text, null, $email, $html, true );
	}

	/**
	 * Shared by fill_recipient_tags() and fill_test_recipient_tags().
	 *
	 * @param string   $text      Body or subject.
	 * @param int|null $user_id   Recipient contact ID.
	 * @param string   $email     Recipient address.
	 * @param bool     $html      True for the body.
	 * @param bool     $site_user Fall back to the site user with this address.
	 * @return string
	 */
	private static function fill_tags_for( $text, $user_id, $email, $html, $site_user ) {
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

		$name = $contact ? (string) $contact->name : '';
		if ( ! $contact && $site_user && $email && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'email', (string) $email );
			if ( $user ) {
				$name = trim( get_user_meta( $user->ID, 'first_name', true ) . ' ' . get_user_meta( $user->ID, 'last_name', true ) );
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
			npmp_email_recipient_tag_values( $name ),
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
		// npmp_encode_numeric_entities() turns every non-ASCII character into
		// a numeric HTML entity without the deprecation, and also on a host
		// without mbstring.
		$libxml_previous_state = libxml_use_internal_errors( true );
		$dom->loadHTML( npmp_encode_numeric_entities( $content ) );
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

		/**
		 * Filter the files on one newsletter message. Event emails get the
		 * event's .ics here (includes/npmp-event-ics.php) when the site's
		 * sending path carries attachments.
		 *
		 * @param string[] $attachments   File paths.
		 * @param int      $newsletter_id Newsletter ID (0 for a one-off test).
		 * @param string   $email         Recipient address.
		 */
		$attachments = apply_filters( 'npmp_newsletter_mail_attachments', array(), (int) $newsletter_id, (string) $to );
		$attachments = is_array( $attachments ) ? array_values( array_filter( $attachments, 'is_string' ) ) : array();

		return npmp_send_mail( $to, $subject, $content, $headers, $attachments );
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
