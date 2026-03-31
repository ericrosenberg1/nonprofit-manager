<?php
/**
 * File path: includes/social-sharing/class-social-share-manager.php
 *
 * Core social-sharing engine.
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton manager for connecting social accounts and sharing posts.
 */
class NPMP_Social_Share_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var NPMP_Social_Share_Manager|null
	 */
	private static $instance = null;

	/**
	 * Option key that stores connected account credentials.
	 *
	 * @var string
	 */
	const ACCOUNTS_OPTION = 'npmp_social_accounts';

	/**
	 * Option key for social-sharing settings.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'npmp_social_settings';

	/**
	 * Get the singleton instance.
	 *
	 * @return NPMP_Social_Share_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/* ------------------------------------------------------------------
	 * Account management
	 * ----------------------------------------------------------------*/

	/**
	 * Return every connected social account.
	 *
	 * @return array Keyed by network slug, each value is an array of credentials.
	 */
	public function get_connected_accounts() {
		return get_option( self::ACCOUNTS_OPTION, array() );
	}

	/**
	 * Store credentials for a network.
	 *
	 * @param string $network    Network slug (e.g. facebook_page).
	 * @param array  $credentials Associative array of tokens / keys.
	 * @return bool
	 */
	public function connect_account( $network, $credentials ) {
		$accounts              = $this->get_connected_accounts();
		$accounts[ $network ]  = $credentials;
		return update_option( self::ACCOUNTS_OPTION, $accounts );
	}

	/**
	 * Remove credentials for a network.
	 *
	 * @param string $network Network slug.
	 * @return bool
	 */
	public function disconnect_account( $network ) {
		$accounts = $this->get_connected_accounts();
		if ( isset( $accounts[ $network ] ) ) {
			unset( $accounts[ $network ] );
			return update_option( self::ACCOUNTS_OPTION, $accounts );
		}
		return false;
	}

	/**
	 * Check whether a network has stored credentials.
	 *
	 * @param string $network Network slug.
	 * @return bool
	 */
	public function is_connected( $network ) {
		$accounts = $this->get_connected_accounts();
		return ! empty( $accounts[ $network ] );
	}

	/* ------------------------------------------------------------------
	 * Settings helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Retrieve all social-sharing settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return wp_parse_args(
			get_option( self::SETTINGS_OPTION, array() ),
			array(
				'auto_share'     => false,
				'share_template' => "{title}\n\n{excerpt}\n\n{url}",
			)
		);
	}

	/**
	 * Save social-sharing settings.
	 *
	 * @param array $settings Associative array.
	 * @return bool
	 */
	public function save_settings( $settings ) {
		return update_option( self::SETTINGS_OPTION, $settings );
	}

	/* ------------------------------------------------------------------
	 * Network registry (extensible via filter)
	 * ----------------------------------------------------------------*/

	/**
	 * Get all registered networks.
	 *
	 * Free tier ships with facebook_page and x_twitter.
	 * Pro adds more via the `npmp_social_networks` filter.
	 *
	 * @return array Keyed by slug. Each value has 'label' and optionally 'file'.
	 */
	public function get_registered_networks() {
		$networks = array(
			'facebook_page' => array(
				'label' => __( 'Facebook Page', 'nonprofit-manager' ),
			),
			'x_twitter'     => array(
				'label' => __( 'X (Twitter)', 'nonprofit-manager' ),
			),
		);

		/**
		 * Filter the list of available social networks.
		 *
		 * @param array $networks Keyed by network slug.
		 */
		return apply_filters( 'npmp_social_networks', $networks );
	}

	/* ------------------------------------------------------------------
	 * Post data helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Build a post-data array suitable for sharing.
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Array with title, excerpt, url, image_url; false on failure.
	 */
	public function get_post_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$excerpt = $post->post_excerpt;
		if ( empty( $excerpt ) ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 45, '...' );
		}

		return array(
			'post_id'   => $post_id,
			'title'     => get_the_title( $post_id ),
			'excerpt'   => $excerpt,
			'url'       => get_permalink( $post_id ),
			'image_url' => $this->get_share_image( $post_id ),
		);
	}

	/**
	 * Get the best image for sharing.
	 *
	 * Tries featured image first, then falls back to the first image in content.
	 *
	 * @param int $post_id Post ID.
	 * @return string URL or empty string.
	 */
	public function get_share_image( $post_id ) {
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_url( $thumb_id, 'large' );
			if ( $src ) {
				return $src;
			}
		}

		// Fallback: first image in content.
		$post = get_post( $post_id );
		if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/* ------------------------------------------------------------------
	 * Formatting
	 * ----------------------------------------------------------------*/

	/**
	 * Format share text for a specific network.
	 *
	 * @param string $network   Network slug.
	 * @param array  $post_data Post data from get_post_data().
	 * @return string
	 */
	public function format_for_network( $network, $post_data ) {
		$settings = $this->get_settings();
		$template = $settings['share_template'];

		$text = str_replace(
			array( '{title}', '{excerpt}', '{url}' ),
			array( $post_data['title'], $post_data['excerpt'], $post_data['url'] ),
			$template
		);

		// Apply character limits per network.
		$limits = array(
			'x_twitter' => 280,
			'bluesky'   => 300,
			'mastodon'  => 500,
		);

		/**
		 * Filter character limits per network.
		 *
		 * @param array $limits Keyed by network slug.
		 */
		$limits = apply_filters( 'npmp_social_char_limits', $limits );

		if ( isset( $limits[ $network ] ) ) {
			$limit = (int) $limits[ $network ];
			// Reserve space for the URL which should always be included.
			$url_length = strlen( $post_data['url'] );
			if ( strlen( $text ) > $limit ) {
				// Rebuild with truncated excerpt to fit within limit.
				$available = $limit - $url_length - strlen( $post_data['title'] ) - 4; // 4 = newlines.
				$short     = ( $available > 10 ) ? mb_substr( $post_data['excerpt'], 0, $available - 3 ) . '...' : '';
				$text      = $post_data['title'] . "\n\n" . $short . "\n\n" . $post_data['url'];
				$text      = mb_substr( $text, 0, $limit );
			}
		}

		return $text;
	}

	/* ------------------------------------------------------------------
	 * Sharing
	 * ----------------------------------------------------------------*/

	/**
	 * Share a post to every connected network.
	 *
	 * @param int $post_id Post ID.
	 * @return array Keyed by network slug, value is true on success or WP_Error.
	 */
	public function share_post( $post_id ) {
		$post_data = $this->get_post_data( $post_id );
		if ( ! $post_data ) {
			return array();
		}

		$accounts = $this->get_connected_accounts();
		$results  = array();

		foreach ( $accounts as $network => $credentials ) {
			$text = $this->format_for_network( $network, $post_data );

			$share_data = array_merge(
				$post_data,
				array( 'text' => $text )
			);

			/**
			 * Share to a specific network.
			 *
			 * Each network file hooks into this filter to perform the API call.
			 *
			 * @param null|true|WP_Error $result      Null by default; handler returns true or WP_Error.
			 * @param array              $share_data  Merged post data including formatted text.
			 * @param array              $credentials Stored credentials for this network.
			 */
			$result = apply_filters( "npmp_social_share_{$network}", null, $share_data, $credentials );

			$results[ $network ] = $result;
		}

		return $results;
	}
}
