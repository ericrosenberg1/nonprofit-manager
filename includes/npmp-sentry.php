<?php
/**
 * Nonprofit Manager — Sentry tagging integration
 *
 * Does NOT bundle the Sentry SDK. Instead, detects whether the WP-Sentry
 * Integration plugin (https://wordpress.org/plugins/wp-sentry-integration/)
 * is active and, if so, adds NPMP-specific tags/context to outgoing events.
 *
 * Means:
 *  - End-users who don't run WP-Sentry: zero footprint, zero data sent.
 *  - End-users who DO run WP-Sentry: NPMP errors are tagged so they can
 *    filter / route them in their own Sentry org.
 *  - Eric's own NPMP-using sites: tag with project=nonprofit-manager-wp so
 *    NPMP errors funnel into the rosenberg-digital/nonprofit-manager-wp
 *    Sentry project regardless of which DSN the host site uses.
 *
 * File path: includes/npmp-sentry.php
 */

defined( 'ABSPATH' ) || exit;

/**
 * If WP-Sentry-Integration is active, add NPMP tags/context to every event.
 * `wp_sentry_safe()` is the plugin's public entrypoint that runs a callback
 * only if the Sentry SDK is loaded — safe no-op otherwise.
 */
add_action( 'init', static function () {
	if ( ! function_exists( 'wp_sentry_safe' ) ) {
		return;
	}

	wp_sentry_safe( static function ( $client ) {
		\Sentry\configureScope( static function ( \Sentry\State\Scope $scope ) {
			$scope->setTag( 'plugin', 'nonprofit-manager' );
			$scope->setContext( 'nonprofit_manager', array(
				'version'  => defined( 'NPMP_VERSION' ) ? NPMP_VERSION : 'unknown',
				'features' => get_option( 'npmp_enabled_features', array() ),
			) );
		} );
	} );
} );

/**
 * Helper for NPMP code paths to send a Sentry event with full NPMP context.
 * Safe to call even when WP-Sentry isn't installed — silently no-ops.
 *
 * @param string|\Throwable $message_or_exception
 * @param string            $level info|warning|error|fatal (Sentry severity)
 * @param array             $extra optional extra context
 */
function npmp_sentry_capture( $message_or_exception, $level = 'error', array $extra = array() ) {
	if ( ! function_exists( 'wp_sentry_safe' ) ) {
		return;
	}
	wp_sentry_safe( static function ( $client ) use ( $message_or_exception, $level, $extra ) {
		if ( ! empty( $extra ) ) {
			\Sentry\configureScope( static function ( \Sentry\State\Scope $scope ) use ( $extra ) {
				foreach ( $extra as $k => $v ) {
					$scope->setExtra( (string) $k, $v );
				}
			} );
		}
		if ( $message_or_exception instanceof \Throwable ) {
			\Sentry\captureException( $message_or_exception );
		} else {
			$severity_map = array(
				'fatal'   => \Sentry\Severity::fatal(),
				'error'   => \Sentry\Severity::error(),
				'warning' => \Sentry\Severity::warning(),
				'info'    => \Sentry\Severity::info(),
				'debug'   => \Sentry\Severity::debug(),
			);
			$severity = $severity_map[ $level ] ?? \Sentry\Severity::error();
			\Sentry\captureMessage( (string) $message_or_exception, $severity );
		}
	} );
}
