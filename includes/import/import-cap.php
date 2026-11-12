<?php
/**
 * File path: includes/import/import-cap.php
 *
 * Free-plugin row cap for imports.
 *
 * The Free plugin imports up to 100 contacts from any email or CRM source
 * (CSV, Mailchimp, Constant Contact, MailPoet, and every importer that calls
 * npmp_import_max_rows()). Larger imports need Pro, which removes the cap by
 * hooking the npmp_import_max_rows filter (see Pro's pro-import.php). The cap
 * was 50 until 2026.11.6. Eric raised it on 2026-11-12 so a small club can
 * finish a switch from another tool in free.
 *
 * The 100 is a total for the site, not a number per job (Eric, 2026-11-12).
 * Every contact an import creates while the cap is on gets the meta key
 * NPMP_IMPORT_FREE_META, and the contacts that still carry it are the ones
 * that count. Deleting an imported contact gives its place back, so a wrong
 * first import doesn't lock anyone out. Updating a contact that already
 * exists never counts. Contacts imported before this release carry no mark
 * and don't count. One job still reads at most npmp_import_max_rows() rows.
 *
 * An importer that creates contacts itself asks npmp_import_free_remaining()
 * before each new one and fires npmp_import_row_imported with the status
 * 'imported' afterwards, which is where the mark gets written.
 *
 * Tests + Pro can lift the cap at runtime:
 *   add_filter( 'npmp_import_max_rows', function() { return PHP_INT_MAX; } );
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/** Contacts the free plugin imports in one job. */
if ( ! defined( 'NPMP_IMPORT_FREE_MAX_ROWS' ) ) {
	define( 'NPMP_IMPORT_FREE_MAX_ROWS', 100 );
}

/**
 * The maximum number of rows the Free plugin will import in a single job.
 *
 * @return int
 */
function npmp_import_max_rows() {
	$max = (int) apply_filters( 'npmp_import_max_rows', NPMP_IMPORT_FREE_MAX_ROWS );
	// Clamp anything weird coming back from a filter (e.g. a string, a negative).
	if ( $max < 1 ) {
		$max = 1;
	}
	return $max;
}

/** Meta key on a contact that counts toward the free import total. */
if ( ! defined( 'NPMP_IMPORT_FREE_META' ) ) {
	define( 'NPMP_IMPORT_FREE_META', '_npmp_free_import' );
}

/**
 * Is the free import limit on? Pro lifts it through npmp_import_max_rows.
 *
 * @return bool
 */
function npmp_import_is_capped() {
	return npmp_import_max_rows() < PHP_INT_MAX;
}

/**
 * Places left out of a total, never below zero.
 *
 * @param int $total Free total.
 * @param int $used  Contacts already counted.
 * @return int
 */
function npmp_import_places_left( $total, $used ) {
	return max( 0, (int) $total - max( 0, (int) $used ) );
}

/**
 * Contacts on this site that count toward the free import total.
 *
 * One COUNT on the indexed meta_key, kept for the request and refreshed when
 * an import creates a contact or WordPress deletes a post.
 *
 * @param bool $refresh Read the database again.
 * @return int
 */
function npmp_import_free_used( $refresh = false ) {
	static $used = null;
	if ( null === $used || $refresh ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count, kept in a static for the request.
		$used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s",
				NPMP_IMPORT_FREE_META,
				'npmp_contact'
			)
		);
	}
	return $used;
}

/**
 * New contacts this site can still import for free. PHP_INT_MAX with Pro.
 *
 * @return int
 */
function npmp_import_free_remaining() {
	if ( ! npmp_import_is_capped() ) {
		return PHP_INT_MAX;
	}
	return npmp_import_places_left( NPMP_IMPORT_FREE_MAX_ROWS, npmp_import_free_used() );
}

/**
 * The sentence an import shows when it skips new contacts at the limit.
 *
 * @return string
 */
function npmp_import_free_limit_message() {
	return sprintf(
		/* translators: %d: the free import limit, 100. */
		__( 'You have imported your %d free contacts, so we skipped the new contacts in this import. We still updated the contacts you already had. Upgrade to Pro to import the rest.', 'nonprofit-manager' ),
		NPMP_IMPORT_FREE_MAX_ROWS
	);
}

/**
 * Mark a contact an import just created, so it counts toward the free total.
 *
 * @param int    $member_id Contact post ID.
 * @param array  $record    Mapped row.
 * @param array  $row       Source row.
 * @param array  $mapping   Column mapping.
 * @param string $status    'imported', 'updated', or 'skipped'.
 * @return void
 */
function npmp_import_mark_free_import( $member_id, $record = array(), $row = array(), $mapping = array(), $status = '' ) {
	if ( 'imported' !== $status || ! npmp_import_is_capped() || (int) $member_id < 1 ) {
		return;
	}
	update_post_meta( (int) $member_id, NPMP_IMPORT_FREE_META, 1 );
	npmp_import_free_used( true );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'npmp_import_row_imported', 'npmp_import_mark_free_import', 5, 5 );
	// A deleted contact gives its place back on this same request.
	add_action(
		'deleted_post',
		static function () {
			npmp_import_free_used( true );
		}
	);
	add_action( 'npmp_import_page_notices', 'npmp_import_free_total_notice' );
}

/**
 * On the Import screen, how much of the free total is left.
 *
 * @return void
 */
function npmp_import_free_total_notice() {
	if ( ! npmp_import_is_capped() ) {
		return;
	}
	$left = npmp_import_free_remaining();
	$text = $left > 0
		? sprintf(
			/* translators: 1: contacts left, 2: the free limit, 100. */
			_n(
				'You can import %1$d more contact for free, out of %2$d in total.',
				'You can import %1$d more contacts for free, out of %2$d in total.',
				$left,
				'nonprofit-manager'
			),
			$left,
			NPMP_IMPORT_FREE_MAX_ROWS
		)
		: sprintf(
			/* translators: %d: the free limit, 100. */
			__( 'You have imported your %d free contacts. An import can still update contacts you already have. To import more, upgrade to Pro.', 'nonprofit-manager' ),
			NPMP_IMPORT_FREE_MAX_ROWS
		);
	echo '<div class="notice notice-info inline"><p>' . esc_html( $text );
	if ( function_exists( 'npmp_get_upgrade_url' ) ) {
		echo ' <a href="' . esc_url( npmp_get_upgrade_url( 'import_cap' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'nonprofit-manager' ) . '</a>';
	}
	echo '</p></div>';
}
