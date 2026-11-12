/**
 * Switch Plugins screen: keep the delete button off until the word is typed,
 * and carry an unfinished data removal on by itself. The server checks the
 * word and every step again, so this is only a convenience.
 */
( function () {
	'use strict';

	var inputs = document.querySelectorAll( '[data-npmp-hub-word]' );
	Array.prototype.forEach.call( inputs, function ( input ) {
		var form = input.form;
		var button = form ? form.querySelector( '[data-npmp-hub-needs-word]' ) : null;
		if ( ! button ) {
			return;
		}
		var word = String( input.getAttribute( 'data-npmp-hub-word' ) || '' ).toLowerCase();
		var sync = function () {
			button.disabled = String( input.value || '' ).trim().toLowerCase() !== word;
		};
		input.addEventListener( 'input', sync );
		sync();
	} );

	var cont = document.querySelector( 'form[data-npmp-hub-continue]' );
	if ( cont ) {
		window.setTimeout( function () {
			cont.submit();
		}, 800 );
	}
}() );
