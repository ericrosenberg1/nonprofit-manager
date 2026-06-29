<?php
/**
 * File path: includes/onboarding/class-tour.php
 *
 * Guided product tour engine for Nonprofit Manager.
 *
 * Drives a multi-step onboarding flow that overlays the WordPress admin
 * with a dark backdrop, highlights one element at a time via a spotlight
 * cutout, and shows a tooltip popover with copy + Next/Back/End buttons.
 *
 * Architecture:
 *
 *   - Step definitions live in `tour-data.php` (free) and Pro's
 *     `includes/onboarding/pro-tour-data.php` — both go through the
 *     `npmp_tour_steps` filter so Pro can append.
 *   - Per-user state lives in user-meta `npmp_tour_progress` (a JSON
 *     blob: step, dismissed, completed, started_at).
 *   - The JS engine reads the localized step list, finds the step
 *     matching the current admin screen, and renders. Step navigation
 *     between admin pages uses `window.location` with a continuation
 *     param so the next page picks up where we left off.
 *   - A modal triggers on the first admin pageview after activation; a
 *     dismissible banner shows on every NPM admin screen until the user
 *     completes or explicitly dismisses the tour.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/tour-data.php';

class NPMP_Tour {

	const META_KEY        = 'npmp_tour_progress';
	const SCRIPT_HANDLE   = 'npmp-tour';
	const STYLE_HANDLE    = 'npmp-tour';
	const REST_NAMESPACE  = 'npmp/v1';

	/**
	 * Boot the controller. Idempotent — safe to call multiple times.
	 */
	public static function init() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_banner' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal_container' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// "Re-run setup tour" trigger via query arg (admin link).
		add_action(
			'admin_init',
			static function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Idempotent UI reset, gated by manage_options.
				if ( isset( $_GET['npmp_tour_restart'] ) && '1' === $_GET['npmp_tour_restart'] ) {
					self::set_progress(
						array(
							'step'       => 0,
							'dismissed'  => false,
							'completed'  => false,
							'started_at' => time(),
						)
					);
					wp_safe_redirect( admin_url( 'admin.php?page=npmp_main' ) );
					exit;
				}
			}
		);
	}

	/**
	 * Current user's tour progress, with defaults applied.
	 *
	 * @return array
	 */
	public static function get_progress() {
		$raw = get_user_meta( get_current_user_id(), self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return wp_parse_args(
			$raw,
			array(
				'step'       => 0,
				'dismissed'  => false,
				'completed'  => false,
				'started_at' => 0,
			)
		);
	}

	/**
	 * Persist progress to user-meta. Caller is responsible for current_user check.
	 *
	 * @param array $progress
	 */
	public static function set_progress( $progress ) {
		$current = self::get_progress();
		$merged  = wp_parse_args( $progress, $current );

		// Defensive type coercion — never trust client JSON values.
		$merged['step']       = max( 0, (int) $merged['step'] );
		$merged['dismissed']  = (bool) $merged['dismissed'];
		$merged['completed']  = (bool) $merged['completed'];
		$merged['started_at'] = max( 0, (int) $merged['started_at'] );

		update_user_meta( get_current_user_id(), self::META_KEY, $merged );
	}

	/**
	 * Should the modal show on this request?
	 *
	 * Logic: NPM-admin page + tour not started + not dismissed + not completed.
	 *
	 * @return bool
	 */
	public static function should_show_modal() {
		if ( ! self::is_npmp_admin_screen() ) {
			return false;
		}
		$p = self::get_progress();
		return ( 0 === $p['step'] ) && ! $p['dismissed'] && ! $p['completed'] && ! $p['started_at'];
	}

	/**
	 * Should the dismissible banner show?
	 *
	 * Logic: NPM-admin page + tour incomplete + user hasn't dismissed.
	 *
	 * @return bool
	 */
	public static function should_show_banner() {
		if ( ! self::is_npmp_admin_screen() ) {
			return false;
		}
		$p = self::get_progress();
		if ( $p['completed'] || $p['dismissed'] ) {
			return false;
		}
		// If the modal would fire on this request, the banner is redundant.
		if ( self::should_show_modal() ) {
			return false;
		}
		return true;
	}

	/**
	 * Test whether the current admin screen is one of ours.
	 *
	 * @return bool
	 */
	public static function is_npmp_admin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$id = (string) $screen->id;
		return ( false !== strpos( $id, 'npmp' ) ) || ( false !== strpos( $id, 'npmp-' ) );
	}

	/**
	 * Compiled list of tour steps for the current user (Free + Pro merged
	 * via filter). Each step gets a `_resolved_skip` flag set if its
	 * `skip_if` predicate already returns true — the JS engine skips
	 * those without ever rendering them.
	 *
	 * @return array[]
	 */
	public static function get_steps() {
		$steps = npmp_tour_get_free_steps();
		/**
		 * Filter the tour step list. Pro hooks this to append its own
		 * steps; themes can hook it to inject custom steps too.
		 *
		 * @param array $steps Ordered step array.
		 */
		$steps = apply_filters( 'npmp_tour_steps', $steps );

		// Pre-evaluate skip predicates so the JS engine doesn't need to.
		foreach ( $steps as $idx => $step ) {
			$steps[ $idx ]['_resolved_skip'] = ! empty( $step['skip_if'] )
				? (bool) call_user_func( array( __CLASS__, 'resolve_skip_predicate' ), $step['skip_if'] )
				: false;
		}

		return array_values( $steps );
	}

	/**
	 * Resolve a `skip_if` predicate. Three forms supported:
	 *   - string callable: 'my_function_name'
	 *   - 'option:KEY[.path]' -> skip if option is non-empty (dot-paths supported one level)
	 *   - 'usermeta:KEY'       -> skip if current-user meta is non-empty
	 *
	 * @param string $predicate
	 * @return bool
	 */
	private static function resolve_skip_predicate( $predicate ) {
		$predicate = (string) $predicate;
		if ( '' === $predicate ) {
			return false;
		}
		if ( strpos( $predicate, 'option:' ) === 0 ) {
			$path = substr( $predicate, strlen( 'option:' ) );
			$dot  = strpos( $path, '.' );
			if ( false !== $dot ) {
				$opt = get_option( substr( $path, 0, $dot ), '' );
				$sub = substr( $path, $dot + 1 );
				return is_array( $opt ) && ! empty( $opt[ $sub ] );
			}
			$opt = get_option( $path, '' );
			return ! empty( $opt );
		}
		if ( strpos( $predicate, 'usermeta:' ) === 0 ) {
			$key = substr( $predicate, strlen( 'usermeta:' ) );
			$val = get_user_meta( get_current_user_id(), $key, true );
			return ! empty( $val );
		}
		if ( is_callable( $predicate ) ) {
			return (bool) call_user_func( $predicate );
		}
		return false;
	}

	/**
	 * Enqueue the engine JS + CSS on every NPM admin screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		// Don't waste bytes on non-NPM admin pages.
		if ( ! self::is_npmp_admin_screen() ) {
			// Exception: enqueue on the WP plugins screen too so the
			// post-activation modal fires (the redirect lands first on a
			// non-npmp screen sometimes).
			if ( 'plugins.php' !== $hook_suffix ) {
				return;
			}
			$p = self::get_progress();
			if ( $p['completed'] || $p['dismissed'] || $p['started_at'] ) {
				return;
			}
		}

		$plugin_file = dirname( __DIR__, 2 ) . '/nonprofit-manager.php';
		$version     = '2026.05.8';
		if ( function_exists( 'get_file_data' ) ) {
			$data = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
			if ( ! empty( $data['Version'] ) ) {
				$version = $data['Version'];
			}
		}

		$base_url = plugins_url( '', $plugin_file );

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base_url . '/assets/css/tour.css',
			array(),
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base_url . '/assets/js/tour.js',
			array( 'wp-api-fetch' ),
			$version,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'npmpTour',
			array(
				'steps'       => self::get_steps(),
				'progress'    => self::get_progress(),
				'showModal'   => self::should_show_modal(),
				'showBanner'  => self::should_show_banner(),
				'restRoot'    => esc_url_raw( rest_url( self::REST_NAMESPACE . '/tour' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'adminUrl'    => admin_url(),
				'currentScreen' => ( function_exists( 'get_current_screen' ) && get_current_screen() ) ? get_current_screen()->id : '',
				'restartUrl'  => admin_url( 'admin.php?page=npmp_main&npmp_tour_restart=1' ),
				'i18n'        => array(
					'next'      => __( 'Next', 'nonprofit-manager' ),
					'back'      => __( 'Back', 'nonprofit-manager' ),
					'end'       => __( 'End tour', 'nonprofit-manager' ),
					'skip'      => __( 'Skip', 'nonprofit-manager' ),
					'start'     => __( 'Start the tour', 'nonprofit-manager' ),
					'dismiss'   => __( 'Not right now', 'nonprofit-manager' ),
					'rerun'     => __( 'Re-run setup tour', 'nonprofit-manager' ),
					'stepLabel' => __( 'Step %1$d of %2$d', 'nonprofit-manager' ),
					'modalTitle'   => __( 'Welcome to Nonprofit Manager', 'nonprofit-manager' ),
					'modalBody'    => __( "Take a 3-minute tour to set up your org, configure email, and learn where everything lives. You can leave the tour at any step.", 'nonprofit-manager' ),
					'bannerTitle'  => __( 'Set up Nonprofit Manager', 'nonprofit-manager' ),
					'bannerBody'   => __( 'Walk through a short tour to get your org configured and learn the basics.', 'nonprofit-manager' ),
					'finishedTitle' => __( "You're set up!", 'nonprofit-manager' ),
					'finishedBody'  => __( "Everything is ready. You can rerun the tour any time from the Overview page.", 'nonprofit-manager' ),
				),
			)
		);
	}

	/**
	 * Render the dismissible banner. Appears on every NPM admin screen
	 * until the user completes or explicitly dismisses.
	 */
	public static function render_banner() {
		if ( ! self::should_show_banner() ) {
			return;
		}
		?>
		<div class="notice notice-info npmp-tour-banner" style="border-left-color:#2563eb;display:flex;align-items:center;gap:12px;padding:12px 16px;">
			<span style="font-size:22px;">🎯</span>
			<div style="flex:1;">
				<p style="margin:0 0 4px;font-size:14px;"><strong><?php esc_html_e( 'Set up Nonprofit Manager', 'nonprofit-manager' ); ?></strong></p>
				<p style="margin:0;color:#475569;font-size:13px;"><?php esc_html_e( 'Take a short tour to configure your org identity, email delivery, and learn the admin layout.', 'nonprofit-manager' ); ?></p>
			</div>
			<button type="button" class="button button-primary" data-npmp-tour-action="start" style="white-space:nowrap;"><?php esc_html_e( 'Start the tour', 'nonprofit-manager' ); ?></button>
			<button type="button" class="button-link" data-npmp-tour-action="dismiss-banner" aria-label="<?php esc_attr_e( 'Dismiss banner', 'nonprofit-manager' ); ?>" style="color:#64748b;text-decoration:none;font-size:18px;">×</button>
		</div>
		<?php
	}

	/**
	 * Empty container the JS mounts the modal + overlay into.
	 */
	public static function render_modal_container() {
		if ( ! self::is_npmp_admin_screen() && ! self::should_show_modal() ) {
			return;
		}
		echo '<div id="npmp-tour-root" aria-live="polite"></div>';
	}

	/**
	 * REST: GET /npmp/v1/tour, POST /npmp/v1/tour (state save).
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/tour',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_get_progress' ),
					'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_set_progress' ),
					'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
					'args'                => array(
						'step'      => array( 'type' => 'integer' ),
						'dismissed' => array( 'type' => 'boolean' ),
						'completed' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	public static function rest_permission_check() {
		return current_user_can( 'edit_posts' );
	}

	public static function rest_get_progress() {
		return rest_ensure_response( self::get_progress() );
	}

	public static function rest_set_progress( WP_REST_Request $request ) {
		$incoming = array();
		foreach ( array( 'step', 'dismissed', 'completed', 'started_at' ) as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val ) {
				$incoming[ $key ] = $val;
			}
		}
		// If `started_at` is not provided but step > 0 and was 0, stamp it.
		$current = self::get_progress();
		if ( ! isset( $incoming['started_at'] ) && isset( $incoming['step'] ) && (int) $incoming['step'] > 0 && empty( $current['started_at'] ) ) {
			$incoming['started_at'] = time();
		}

		self::set_progress( $incoming );
		return rest_ensure_response( self::get_progress() );
	}
}

NPMP_Tour::init();
