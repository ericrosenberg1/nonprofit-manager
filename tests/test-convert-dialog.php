<?php
/**
 * The Convert to Event modal is a dialog to assistive tech and the keyboard.
 *
 * It used to be a plain div: no role, focus stayed behind it, Escape did
 * nothing, and its labels weren't tied to their inputs. The rig check in a
 * real editor is manual (open a post, click Convert to Event, press Escape).
 *
 * Run: php tests/test-convert-dialog.php
 */

require_once __DIR__ . '/bootstrap.php';

$src = file_get_contents( __DIR__ . '/../includes/npmp-convert-to-event.php' );

check( 'role dialog', true, false !== strpos( $src, "modal.setAttribute('role', 'dialog');" ) );
check( 'aria-modal', true, false !== strpos( $src, "modal.setAttribute('aria-modal', 'true');" ) );
check( 'labelled by its heading', true, false !== strpos( $src, "modal.setAttribute('aria-labelledby', 'npmp-convert-title');" ) && false !== strpos( $src, '<h2 id="npmp-convert-title"' ) );
foreach ( array( 'npmp-convert-start', 'npmp-convert-end', 'npmp-convert-location' ) as $id ) {
	check( "label for $id", true, false !== strpos( $src, '<label for="' . $id . '"' ) );
}
check( 'focus moves into the dialog', true, false !== strpos( $src, "document.getElementById('npmp-convert-start').focus();" ) );
check( 'Escape closes it', true, false !== strpos( $src, "if (e.key === 'Escape')" ) );
check( 'closing gives focus back', true, false !== strpos( $src, 'opener.focus();' ) );
check( 'status is announced', true, false !== strpos( $src, 'id="npmp-convert-status" role="status"' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
