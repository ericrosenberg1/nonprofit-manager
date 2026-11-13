<?php
/**
 * File path: includes/email-newsletter/archive-theme-template.php
 *
 * The template a public newsletter page loads (see
 * npmp_newsletter_archive_template_include()). It runs the theme's own
 * template into a buffer, then prints it, or the standalone page when the
 * theme's template never printed the newsletter content.
 *
 * Included by WordPress's template loader in global scope, the same as the
 * theme template it wraps.
 *
 * @package NonprofitManager
 */

defined( 'ABSPATH' ) || exit;

$npmp_nlv_view = npmp_newsletter_archive_view();
ob_start();
if ( ! empty( $npmp_nlv_view['template'] ) && is_readable( $npmp_nlv_view['template'] ) ) {
	include $npmp_nlv_view['template'];
}
npmp_newsletter_archive_finish( (string) ob_get_clean() );
