<?php
defined( 'ABSPATH' ) || exit;

class NPMP_Newsletter_Tracker {

	const TRACK_QUERY_KEY = 'npmp_track';
	const ACTION_OPEN     = 'open';
	const ACTION_CLICK    = 'click';

	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register front-end handlers.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'template_redirect', array( self::get_instance(), 'maybe_handle_request' ) );
	}

	/**
	 * Maybe process a tracking request.
	 *
	 * @return void
	 */
	public function maybe_handle_request() {
		$action = isset( $_GET[ self::TRACK_QUERY_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::TRACK_QUERY_KEY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce validation happens in the specific handlers.

		if ( '' === $action ) {
			return;
		}

		if ( self::ACTION_OPEN === $action ) {
			$this->process_open_request();
		} elseif ( self::ACTION_CLICK === $action ) {
			$this->process_click();
		}
	}

	/**
	 * Build the tracking pixel URL.
	 *
	 * @param int $newsletter_id Newsletter ID.
	 * @param int $user_id       User ID.
	 * @return string
	 */
	public function get_open_pixel_url( $newsletter_id, $user_id ) {
		return add_query_arg(
			array(
				self::TRACK_QUERY_KEY => self::ACTION_OPEN,
				'nid'                 => absint( $newsletter_id ),
				'uid'                 => absint( $user_id ),
				'_npmp'               => self::generate_hmac( 'open', $newsletter_id, $user_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Record an open event.
	 *
	 * Stored in the dedicated wp_npmp_newsletter_opens table rather than as a
	 * wp_posts row (the table already existed, created on activation, but
	 * was never actually written to: every open was logging a full post +
	 * postmeta rows instead). The table's UNIQUE KEY on (user_id,
	 * newsletter_id) does the dedup that the old code did with a get_posts()
	 * existence check beforehand.
	 *
	 * @param int $newsletter_id Newsletter ID.
	 * @param int $user_id       User ID.
	 * @return bool
	 */
	public function track_open( $newsletter_id, $user_id ) {
		$cache_key = 'open_' . $newsletter_id . '_' . $user_id;
		if ( wp_cache_get( $cache_key, 'npmp_newsletters' ) ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'npmp_newsletter_opens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated tracking table; the wp_cache_set() below covers repeat requests.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, values are all placeholders.
				"INSERT IGNORE INTO {$table} (user_id, newsletter_id, opened_at) VALUES (%d, %d, %s)",
				absint( $user_id ),
				absint( $newsletter_id ),
				current_time( 'mysql' )
			)
		);

		wp_cache_set( $cache_key, true, 'npmp_newsletters', HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Record a link click event. Stored in wp_npmp_newsletter_clicks, see
	 * the note on track_open() above for why this moved off wp_posts.
	 *
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id       User ID.
	 * @param string $url           Destination URL.
	 * @return bool
	 */
	public function track_click( $newsletter_id, $user_id, $url ) {
		global $wpdb;
		$table = $wpdb->prefix . 'npmp_newsletter_clicks';

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated tracking table, no caching layer needed for a write.
			$table,
			array(
				'newsletter_id' => absint( $newsletter_id ),
				'user_id'       => absint( $user_id ),
				'url'           => esc_url_raw( $url ),
				'clicked_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Create a tracked URL for click events.
	 *
	 * @param string $original_url Original URL.
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function create_tracked_url( $original_url, $newsletter_id, $user_id ) {
		return add_query_arg(
			array(
				self::TRACK_QUERY_KEY             => self::ACTION_CLICK,
				'nid'                             => absint( $newsletter_id ),
				'uid'                             => absint( $user_id ),
				'url'                             => rawurlencode( $original_url ),
				'_npmp'                           => self::generate_hmac( 'click', $newsletter_id, $user_id, $original_url ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Process an incoming open request and output a tracking pixel.
	 *
	 * @return void
	 */
	private function process_open_request() {
		if ( ! get_option( 'npmp_newsletter_track_opens', true ) ) {
			return;
		}

		if ( empty( $_GET['_npmp'] ) || empty( $_GET['nid'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$newsletter_id = isset( $_GET['nid'] ) ? absint( $_GET['nid'] ) : 0;
		$user_id       = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		$nonce         = isset( $_GET['_npmp'] ) ? sanitize_text_field( wp_unslash( $_GET['_npmp'] ) ) : '';

		if ( ! $newsletter_id || ! $user_id || ! $nonce || ! self::verify_hmac( $nonce, 'open', $newsletter_id, $user_id ) ) {
			return;
		}

		$this->track_open( $newsletter_id, $user_id );
		$this->render_tracking_pixel();
	}

	/**
	 * Process click tracking and redirect.
	 *
	 * @return void
	 */
	public function process_click() {
		$destination   = home_url();
		$newsletter_id = isset( $_GET['nid'] ) ? absint( $_GET['nid'] ) : 0;
		$user_id       = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		$raw_url       = isset( $_GET['url'] ) ? wp_unslash( $_GET['url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- URL is decoded then validated with esc_url_raw.
		$decoded_url   = $raw_url ? rawurldecode( $raw_url ) : '';
		$url           = $decoded_url ? esc_url_raw( $decoded_url ) : '';
		$nonce         = isset( $_GET['_npmp'] ) ? sanitize_text_field( wp_unslash( $_GET['_npmp'] ) ) : '';

		if ( ! $newsletter_id || ! $user_id || ! $url || ! $nonce ) {
			wp_safe_redirect( $destination );
			exit;
		}

		// The destination URL is part of the signed payload, so a valid click
		// token can't be reused with a swapped url= parameter. Links from
		// newsletters sent before the URL was signed still verify through the
		// legacy check, which excludes the URL.
		$valid = self::verify_hmac( $nonce, 'click', $newsletter_id, $user_id, $decoded_url )
			|| self::verify_hmac( $nonce, 'click', $newsletter_id, $user_id );

		if ( ! $valid ) {
			wp_safe_redirect( $destination );
			exit;
		}

		if ( $newsletter_id && $user_id && $url && get_option( 'npmp_newsletter_track_clicks', false ) ) {
			$this->track_click( $newsletter_id, $user_id, $url );
		}

		if ( $url ) {
			wp_safe_redirect( $url );
			exit;
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Generate a non-expiring HMAC token for tracking URLs.
	 *
	 * Unlike WordPress nonces which expire after 24 hours, these tokens
	 * remain valid indefinitely so newsletter tracking links keep working.
	 *
	 * @param string $action        Track action (open or click).
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id       User ID.
	 * @return string 16-char hex HMAC.
	 */
	private static function generate_hmac( $action, $newsletter_id, $user_id, $url = '' ) {
		$data = $action . '|' . absint( $newsletter_id ) . '|' . absint( $user_id );
		if ( '' !== $url ) {
			// Click tokens sign the destination too, otherwise one valid link
			// authorizes any url= value.
			$data .= '|' . $url;
		}
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Verify an HMAC token.
	 *
	 * @param string $token         Token to verify.
	 * @param string $action        Track action.
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id       User ID.
	 * @param string $url           Destination URL for click tokens ('' for the legacy/open format).
	 * @return bool
	 */
	private static function verify_hmac( $token, $action, $newsletter_id, $user_id, $url = '' ) {
		$expected = self::generate_hmac( $action, $newsletter_id, $user_id, $url );
		return hash_equals( $expected, $token );
	}

	/**
	 * Output a 1x1 transparent GIF and exit.
	 *
	 * @return void
	 */
	private function render_tracking_pixel() {
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' ); // Length of the decoded pixel data.

		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}

NPMP_Newsletter_Tracker::bootstrap();
