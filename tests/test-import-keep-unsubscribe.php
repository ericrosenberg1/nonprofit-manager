<?php
/**
 * The import wizard never resubscribes a contact who unsubscribed here
 * (NPMP_Import_Manager::keep_unsubscribe()).
 *
 * Run: php tests/test-import-keep-unsubscribe.php
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/includes/import/class-import-manager.php';

$row = array( 'email' => 'a@b.org', 'status' => 'subscribed', 'first_name' => 'Ann' );

check( 'an unsubscribed contact keeps the unsubscribe', array( 'email' => 'a@b.org', 'first_name' => 'Ann' ), NPMP_Import_Manager::keep_unsubscribe( 'unsubscribed', $row ) );
check( 'pending in the file also stays out', false, isset( NPMP_Import_Manager::keep_unsubscribe( 'unsubscribed', array( 'status' => 'pending' ) )['status'] ) );
check( 'an unsubscribe in the file still lands', 'unsubscribed', NPMP_Import_Manager::keep_unsubscribe( 'subscribed', array( 'status' => 'unsubscribed' ) )['status'] );
check( 'a subscribed contact takes the file status', $row, NPMP_Import_Manager::keep_unsubscribe( 'subscribed', $row ) );
check( 'a row with no status is left alone', array( 'email' => 'a@b.org' ), NPMP_Import_Manager::keep_unsubscribe( 'unsubscribed', array( 'email' => 'a@b.org' ) ) );

$code = file_get_contents( dirname( __DIR__ ) . '/includes/import/class-import-manager.php' );
check( 'the update branch runs the guard before update_member', true, (bool) preg_match( '/keep_unsubscribe\([^;]+;\s*\$result = \$this->member_manager->update_member/', $code ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
