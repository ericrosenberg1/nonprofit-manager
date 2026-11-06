<?php
/**
 * Real-WordPress check of the Member List search: a search matches the name,
 * the email, or the tags, each on its own. It used to need the name and the
 * email or tags to match the same text.
 *
 *   wp eval-file tests/rig/member-search.php
 * It adds three contacts with ".search.test" addresses and deletes them after.
 */

$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	if ( ! $cond ) {
		$fails++;
	}
	printf( "  %s  %s\n", $cond ? 'PASS' : 'FAIL', $label );
};

$mm  = NPMP_Member_Manager::get_instance();
$ids = array();
foreach ( array(
	array( 'name' => 'Jane Smith', 'email' => 'jane.doe@search.test', 'tags' => 'gardening' ),
	array( 'name' => 'Raj Patel', 'email' => 'raj@search.test', 'tags' => 'choir, smithing' ),
	array( 'name' => 'Lee Wong', 'email' => 'lee@search.test', 'tags' => '' ),
) as $row ) {
	$id = $mm->add_member( $row );
	if ( ! is_wp_error( $id ) ) {
		$ids[] = (int) $id;
	}
}
$ok( 'three contacts', 3 === count( $ids ) );

$emails = function ( $search ) use ( $mm ) {
	$out = array();
	foreach ( (array) $mm->get_members( array( 'search' => $search, 'per_page' => -1 ) ) as $m ) {
		$m = (object) $m;
		if ( false !== strpos( (string) $m->email, '@search.test' ) ) {
			$out[] = (string) $m->email;
		}
	}
	sort( $out );
	return $out;
};

$ok( 'a name finds Jane, whose email has no "Smith"', in_array( 'jane.doe@search.test', $emails( 'Smith' ), true ) );
$ok( 'and a tag finds Raj in the same search', array( 'jane.doe@search.test', 'raj@search.test' ) === $emails( 'smith' ) );
$ok( 'an email finds Lee, whose name has no match', array( 'lee@search.test' ) === $emails( 'lee@search' ) );
$ok( 'a tag alone finds Raj', array( 'raj@search.test' ) === $emails( 'choir' ) );
$ok( 'no match finds nobody', array() === $emails( 'zzqq-nobody' ) );
$ok( 'count_members agrees with the list', 3 === (int) $mm->count_members( array( 'search' => '@search.test' ) ) );
$ok( 'a percent sign is literal', array() === $emails( '%' ) );

foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
printf( "%d passed, %d failed\n", 8 - $fails, $fails );
exit( $fails ? 1 : 0 );
