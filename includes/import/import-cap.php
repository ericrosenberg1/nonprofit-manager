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
