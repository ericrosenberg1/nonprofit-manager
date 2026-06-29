<?php
/**
 * File path: includes/import/import-cap.php
 *
 * Free-plugin row cap for imports.
 *
 * The Free plugin imports up to 50 supporters from any source. Larger imports
 * require Pro, which removes the cap by hooking the npmp_import_max_rows filter
 * (see Pro's pro-import.php). Keeping the cap in Free means the Free experience
 * is genuinely useful (someone running a 30-member synagogue can do the whole
 * import in one shot) without giving away the 5,000-member workflow.
 *
 * Tests + Pro can lift the cap at runtime:
 *   add_filter( 'npmp_import_max_rows', function() { return PHP_INT_MAX; } );
 *
 * @package Nonprofit_Manager
 */

defined( 'ABSPATH' ) || exit;

/**
 * The maximum number of rows the Free plugin will import in a single job.
 *
 * @return int
 */
function npmp_import_max_rows() {
	$max = (int) apply_filters( 'npmp_import_max_rows', 50 );
	// Clamp anything weird coming back from a filter (e.g. a string, a negative).
	if ( $max < 1 ) {
		$max = 1;
	}
	return $max;
}

/**
 * Is this install operating at the Free cap (i.e., Pro is NOT lifting it)?
 *
 * Used to decide whether to surface an "upgrade to Pro" banner when an import
 * hits the row limit. Compares the live filtered value against the unfiltered
 * default; any custom site-level filter that raises the cap also suppresses
 * the upgrade nag, which is correct.
 *
 * @return bool
 */
function npmp_import_is_capped() {
	return npmp_import_max_rows() < 1000;
}

/**
 * Build the user-facing message shown after a capped import truncates a list.
 *
 * @param int $imported Number of rows actually imported.
 * @param int $total    The full source row count (e.g., Mailchimp audience size).
 * @return string Plain text suitable for echo via esc_html.
 */
function npmp_import_cap_message( $imported, $total ) {
	return sprintf(
		/* translators: 1: rows imported, 2: total in source, 3: cap value */
		__( 'Imported the first %1$d of %2$d records. The free version imports up to %3$d at a time. Upgrade to Pro to import the rest in one pass.', 'nonprofit-manager' ),
		(int) $imported,
		(int) $total,
		npmp_import_max_rows()
	);
}
