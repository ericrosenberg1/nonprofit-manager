<?php
/**
 * One import run at a time.
 *
 * A reload during a 47,501-subscriber MailPoet import left the old batch
 * request running while the page started a new one. Both read the same rows,
 * and the site ended with 82 contacts twice. Every runner the Switch Plugins
 * screens call now takes npmp_mail_queue_lock() first and hands back its
 * current state, unchanged, while another run holds it.
 *
 * Run: php tests/test-import-run-lock.php
 */

require_once __DIR__ . '/bootstrap.php';

$root    = dirname( __DIR__ );
$runners = array(
	'mailpoet-importer.php'   => array( 'npmp_mailpoet_migrate_batch', "'import_mailpoet'" ),
	'tec-importer.php'        => array( 'npmp_tec_migrate_batch', "'import_tec'" ),
	'gift-importer.php'       => array( 'npmp_gift_import_run', "'import_gift_' . \$slug" ),
	'event-importer.php'      => array( 'npmp_event_import_run', "'import_event_' . \$slug" ),
	'newsletter-importer.php' => array( 'npmp_tnp_run', "'import_newsletter'" ),
	'pmpro-importer.php'      => array( 'npmp_pmpro_run', "'import_pmpro'" ),
);

foreach ( $runners as $file => $spec ) {
	list( $fn, $lock ) = $spec;
	$src = file_get_contents( $root . '/includes/import/' . $file );
	if ( ! preg_match( '/function ' . $fn . '\(.*?\n}\n/s', $src, $m ) ) {
		check( "$fn() exists", true, false );
		continue;
	}
	$body = $m[0];
	check( "$fn() takes the lock before any work", true, false !== strpos( $body, "if ( ! npmp_mail_queue_lock( $lock, 120 ) )" ) );
	check( "$fn() releases it in finally", true, false !== strpos( $body, "} finally {\n\t\tnpmp_mail_queue_unlock( $lock );" ) );
	check( "$fn() hands the work to {$fn}_locked()", true, false !== strpos( $body, "return {$fn}_locked(" ) );
}

$js = file_get_contents( $root . '/assets/js/npmp-tec-import.js' );
check( 'the batch page waits when another tab holds the run', true, false !== strpos( $js, "if ( d.busy ) {\n\t\t\t\t\t\twindow.setTimeout( step, 2000 );" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
