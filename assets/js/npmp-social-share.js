/**
 * Nonprofit Manager — front-end copy-to-clipboard for the social-share block.
 * Delegated click handler so it works no matter how many share bars are on a page.
 */
( function () {
	function feedback( btn ) {
		if ( ! btn.getAttribute( 'data-npmp-label' ) ) {
			btn.setAttribute( 'data-npmp-label', btn.textContent );
		}
		btn.textContent = btn.getAttribute( 'data-npmp-copied' ) || 'Copied';
		setTimeout( function () {
			btn.textContent = btn.getAttribute( 'data-npmp-label' );
		}, 2000 );
	}

	function fallbackCopy( text, done ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); done(); } catch ( e ) { /* no-op */ }
		document.body.removeChild( ta );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '.npmp-share-copy' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var url = btn.getAttribute( 'data-npmp-share-url' ) || window.location.href;
		var done = function () { feedback( btn ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( done, function () { fallbackCopy( url, done ); } );
		} else {
			fallbackCopy( url, done );
		}
	} );
} )();
