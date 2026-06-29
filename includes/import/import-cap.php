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
