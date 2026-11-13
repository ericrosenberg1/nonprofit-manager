<?php
/**
 * Every import that creates a contact runs as an import.
 *
 * Pro's "someone signs up" automation and the Nonprofit Mailer's member_joined
 * event skip a new contact only while npmp_is_importing() is true. In
 * 2026.11.10 the MailPoet, Newsletter, Paid Memberships Pro, and past-gift
 * importers called add_member() without it, so a site with Pro and a welcome
 * automation queued a welcome for every imported contact (Pro rig
 * tests/rig/import-no-welcome.php).
 *
 * This scans includes/import/ for every function that creates a contact
 * (add_member(), or wp_insert_post() of an npmp_contact) and wants each one
 * either to set the flag itself (npmp_import_run_as_import(),
 * NPMP_Import_Manager::run_as_import(), or self::$importing = true) or to be
 * called only from functions that do. A new source that writes contacts
 * without the flag fails here.
 *
 * Run: php tests/test-import-as-import.php
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Functions and methods in a set of sources, name => body.
 *
 * @param array<string,string> $sources file => source.
 * @return array<string,array{file:string,body:string}>
 */
function npmp_test_functions( array $sources ) {
	$out = array();
	foreach ( $sources as $file => $src ) {
		preg_match_all( '/^function (\w+)\(.*?^}$/sm', $src, $top, PREG_SET_ORDER );
		preg_match_all( '/^\t(?:(?:public|private|protected|static|final) )*function (\w+)\(.*?^\t}$/sm', $src, $methods, PREG_SET_ORDER );
		foreach ( array_merge( $top, $methods ) as $m ) {
			$out[ $m[1] ] = array(
				'file' => $file,
				'body' => $m[0],
			);
		}
	}
	return $out;
}

/**
 * Whether a function body creates a contact.
 *
 * @param string $body Function source.
 * @return bool
 */
function npmp_test_creates_contact( $body ) {
	return false !== strpos( $body, '->add_member(' )
		|| ( false !== strpos( $body, 'wp_insert_post(' ) && false !== strpos( $body, "'npmp_contact'" ) );
}

/**
 * Whether a function body turns the importing flag on.
 *
 * @param string $body Function source.
 * @return bool
 */
function npmp_test_sets_flag( $body ) {
	return false !== strpos( $body, 'npmp_import_run_as_import(' )
		|| false !== strpos( $body, 'NPMP_Import_Manager::run_as_import(' )
		|| false !== strpos( $body, 'self::$importing = true' );
}

/**
 * Whether a function runs with the flag on: it sets it, or every function that
 * calls it does.
 *
 * @param string $name      Function name.
 * @param array  $functions npmp_test_functions() output.
 * @param array  $seen      Names on the current path.
 * @return bool
 */
function npmp_test_runs_as_import( $name, array $functions, array $seen = array() ) {
	if ( isset( $seen[ $name ] ) || ! isset( $functions[ $name ] ) ) {
		return false;
	}
	if ( npmp_test_sets_flag( $functions[ $name ]['body'] ) ) {
		return true;
	}
	$seen[ $name ] = true;
	$callers       = array();
	foreach ( $functions as $caller => $f ) {
		if ( $caller !== $name && preg_match( '/(?<![\w$>:])' . preg_quote( $name, '/' ) . '\(|->' . preg_quote( $name, '/' ) . '\(/', $f['body'] ) ) {
			$callers[] = $caller;
		}
	}
	if ( ! $callers ) {
		return false;
	}
	foreach ( $callers as $caller ) {
		if ( ! npmp_test_runs_as_import( $caller, $functions, $seen ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Contact writers in a set of sources that don't run as an import.
 *
 * @param array<string,string> $sources file => source.
 * @return array{writers:string[],bad:string[]}
 */
function npmp_test_unflagged_writers( array $sources ) {
	$functions = npmp_test_functions( $sources );
	$writers   = array();
	$bad       = array();
	foreach ( $functions as $name => $f ) {
		if ( ! npmp_test_creates_contact( $f['body'] ) ) {
			continue;
		}
		$writers[] = $name;
		if ( ! npmp_test_runs_as_import( $name, $functions ) ) {
			$bad[] = $f['file'] . ': ' . $name . '()';
		}
	}
	sort( $writers );
	return array(
		'writers' => $writers,
		'bad'     => $bad,
	);
}

$root    = dirname( __DIR__ );
$sources = array();
foreach ( glob( $root . '/includes/import/*.php' ) as $path ) {
	$sources[ basename( $path ) ] = file_get_contents( $path );
}
$scan = npmp_test_unflagged_writers( $sources );

// The scan has to find the writers we know about, or it proves nothing.
foreach ( array( 'npmp_mailpoet_import_subscriber', 'npmp_pmpro_write_member', 'npmp_past_gift_donor', 'write_single_record' ) as $known ) {
	check( "the scan finds the contact writer $known()", true, in_array( $known, $scan['writers'], true ) );
}
check( 'every contact writer in includes/import/ runs as an import', array(), $scan['bad'] );

// The scan catches a source that writes contacts without the flag.
$fake = array(
	'rig-source.php' => "<?php\nfunction npmp_rig_run() {\n\tforeach ( array( 1 ) as \$row ) {\n\t\tnpmp_rig_write( \$row );\n\t}\n}\n\nfunction npmp_rig_write( \$row ) {\n\treturn NPMP_Member_Manager::get_instance()->add_member( array( 'email' => 'a@example.org' ) );\n}\n",
	'rig-ok.php'     => "<?php\nfunction npmp_rig_ok( \$row ) {\n\treturn npmp_import_run_as_import(\n\t\tstatic function () use ( \$row ) {\n\t\t\treturn npmp_rig_ok_write( \$row );\n\t\t}\n\t);\n}\n\nfunction npmp_rig_ok_write( \$row ) {\n\treturn NPMP_Member_Manager::get_instance()->add_member( \$row );\n}\n",
);
$fake_scan = npmp_test_unflagged_writers( $fake );
check( 'a writer called from an unflagged runner is caught', array( 'rig-source.php: npmp_rig_write()' ), $fake_scan['bad'] );
check( 'a writer called only inside npmp_import_run_as_import() passes', true, in_array( 'npmp_rig_ok_write', $fake_scan['writers'], true ) && ! in_array( 'rig-ok.php: npmp_rig_ok_write()', $fake_scan['bad'], true ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
