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
				'_npmp'               => wp_create_nonce( 'npmp_track_open_' . $newsletter_id . '_' . $user_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Record an open event.
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

		$existing = get_posts(
			array(
				'post_type'      => NPMP_Newsletter_Manager::EVENT_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Tracking events requires matching metadata.
				'meta_query'     => array(
					array(
						'key'   => NPMP_Newsletter_Manager::EVENT_NEWSLETTER_META,
						'value' => absint( $newsletter_id ),
					),
					array(
						'key'   => NPMP_Newsletter_Manager::EVENT_USER_META,
						'value' => absint( $user_id ),
					),
					array(
						'key'   => NPMP_Newsletter_Manager::EVENT_TYPE_META,
						'value' => NPMP_Newsletter_Manager::ACTION_OPEN,
					),
				),
			)
		);

		if ( $existing ) {
			wp_cache_set( $cache_key, true, 'npmp_newsletters', HOUR_IN_SECONDS );
			return true;
		}

		$event_id = wp_insert_post(
			array(
				'post_type'   => NPMP_Newsletter_Manager::EVENT_POST_TYPE,
				'post_status' => 'publish',
				/* translators: %d: Newsletter ID. */
				'post_title'  => sprintf( __( 'Open: newsletter %d', 'nonprofit-manager' ), $newsletter_id ),
				'meta_input'  => array(
					NPMP_Newsletter_Manager::EVENT_NEWSLETTER_META => absint( $newsletter_id ),
					NPMP_Newsletter_Manager::EVENT_USER_META       => absint( $user_id ),
					NPMP_Newsletter_Manager::EVENT_TYPE_META       => NPMP_Newsletter_Manager::ACTION_OPEN,
					NPMP_Newsletter_Manager::EVENT_TIME_META       => current_time( 'mysql' ),
				),
			),
			true
		);

		if ( is_wp_error( $event_id ) ) {
			return false;
		}

		wp_cache_set( $cache_key, true, 'npmp_newsletters', HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Record a link click event.
	 *
	 * @param int    $newsletter_id Newsletter ID.
	 * @param int    $user_id       User ID.
	 * @param string $url           Destination URL.
	 * @return bool
	 */
	public function track_click( $newsletter_id, $user_id, $url ) {
		$event_id = wp_insert_post(
			array(
				'post_type'   => NPMP_Newsletter_Manager::EVENT_POST_TYPE,
				'post_status' => 'publish',
				/* translators: %d: Newsletter ID. */
				'post_title'  => sprintf( __( 'Click: newsletter %d', 'nonprofit-manager' ), $newsletter_id ),
				'meta_input'  => array(
					NPMP_Newsletter_Manager::EVENT_NEWSLETTER_META => absint( $newsletter_id ),
					NPMP_Newsletter_Manager::EVENT_USER_META       => absint( $user_id ),
					NPMP_Newsletter_Manager::EVENT_TYPE_META       => NPMP_Newsletter_Manager::ACTION_CLICK,
					NPMP_Newsletter_Manager::EVENT_URL_META        => esc_url_raw( $url ),
					NPMP_Newsletter_Manager::EVENT_TIME_META       => current_time( 'mysql' ),
				),
			),
			true
		);

		return ! is_wp_error( $event_id );
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
				'_npmp'                           => wp_create_nonce( 'npmp_track_click_' . $newsletter_id . '_' . $user_id ),
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

		if ( ! $newsletter_id || ! $user_id || ! $nonce || ! wp_verify_nonce( $nonce, 'npmp_track_open_' . $newsletter_id . '_' . $user_id ) ) {
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
		$url           = $raw_url ? esc_url_raw( rawurldecode( $raw_url ) ) : '';
		$nonce         = isset( $_GET['_npmp'] ) ? sanitize_text_field( wp_unslash( $_GET['_npmp'] ) ) : '';

		if ( ! $newsletter_id || ! $user_id || ! $url || ! $nonce ) {
			wp_safe_redirect( $destination );
			exit;
		}

		if ( ! wp_verify_nonce( $nonce, 'npmp_track_click_' . $newsletter_id . '_' . $user_id ) ) {
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
