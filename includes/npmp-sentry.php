<?php
/**
 * Nonprofit Manager Sentry tagging integration
 *
 * Does NOT bundle the Sentry SDK. Instead, detects whether the WP-Sentry
 * Integration plugin (https://wordpress.org/plugins/wp-sentry-integration/)
 * is active and, if so, adds NPMP-specific tags/context to outgoing events.
 *
 * Means:
 *  - End-users who don't run WP-Sentry: zero footprint, zero data sent.
 *  - End-users who DO run WP-Sentry: NPMP errors are tagged so they can
 *    filter / route them in their own Sentry org.
 *
 * File path: includes/npmp-sentry.php
 */

defined( 'ABSPATH' ) || exit;

/**
 * If WP-Sentry-Integration is active, add NPMP tags/context to every event.
 * `wp_sentry_safe()` is the plugin's public entrypoint that runs a callback
 * only if the Sentry SDK is loaded. Safe no-op otherwise.
 */
add_action( 'init', static function () {
	if ( ! function_exists( 'wp_sentry_safe' ) ) {
		return;
	}

	wp_sentry_safe( static function ( $client ) {
		\Sentry\configureScope( static function ( \Sentry\State\Scope $scope ) {
			$scope->setTag( 'plugin', 'nonprofit-manager' );
			$scope->setContext( 'nonprofit_manager', array(
				'version'  => function_exists( 'npmp_get_version_number' ) ? npmp_get_version_number() : 'unknown',
				'features' => get_option( 'npmp_enabled_features', array() ),
			) );
		} );
	} );
} );
