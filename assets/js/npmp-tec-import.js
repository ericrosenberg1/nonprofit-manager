/**
 * The Events Calendar importer: batch runner with a progress bar, select all
 * and none on the review table, and a compact skip list on submit.
 *
 * Loaded only on the importer screen (includes/import/tec-importer.php). The
 * MailPoet importer (includes/import/mailpoet-importer.php) uses the same
 * batch runner on its own screen.
 */
( function () {
	'use strict';

	function format( template, a, b ) {
		return template.replace( '%1$s', a ).replace( '%2$s', b );
	}

	function initRunner( root ) {
		var form = root.querySelector( '.npmp-tec-runner-form' );
		var bar = root.querySelector( '.npmp-tec-bar' );
		var fill = root.querySelector( '.npmp-tec-bar-fill' );
		var text = root.querySelector( '.npmp-tec-bar-text' );
		var restart = '1' === root.getAttribute( 'data-restart' );
		var running = false;

		function show( done, total ) {
			var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 100;
			fill.style.width = pct + '%';
			bar.setAttribute( 'aria-valuenow', String( pct ) );
			text.textContent = format( root.getAttribute( 'data-label-progress' ), done, total );
		}

		function fail( message ) {
			running = false;
			text.textContent = message || root.getAttribute( 'data-label-error' );
			text.classList.add( 'is-error' );
			form.hidden = false;
		}

		function step() {
			var body = new FormData();
			body.append( 'action', root.getAttribute( 'data-action' ) );
			// The MailPoet importer shares this runner and names its own nonce field.
			body.append( root.getAttribute( 'data-nonce-name' ) || 'npmp_tec_nonce', root.getAttribute( 'data-nonce' ) );
			if ( restart ) {
				body.append( 'restart', '1' );
				restart = false;
			}
			fetch( root.getAttribute( 'data-ajax-url' ), { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						fail( json && json.data && json.data.message ? json.data.message : '' );
						return;
					}
					var d = json.data;
					if ( 'position' in d ) {
						show( d.position, d.total );
					} else {
						show( d.read, d.total );
					}
					if ( d.done ) {
						window.location.href = root.getAttribute( 'data-next' );
						return;
					}
					step();
				} )
				.catch( function () {
					fail( '' );
				} );
		}

		function start( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( running ) {
				return;
			}
			running = true;
			form.hidden = true;
			bar.hidden = false;
			bar.setAttribute( 'role', 'progressbar' );
			bar.setAttribute( 'aria-valuemin', '0' );
			bar.setAttribute( 'aria-valuemax', '100' );
			text.classList.remove( 'is-error' );
			step();
		}

		form.addEventListener( 'submit', start );
		if ( '1' === root.getAttribute( 'data-autostart' ) ) {
			start();
		}
	}

	function initReview( form ) {
		var boxes = form.querySelectorAll( 'input.npmp-tec-pick' );

		form.querySelectorAll( '[data-npmp-tec-select]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var on = 'all' === button.getAttribute( 'data-npmp-tec-select' );
				boxes.forEach( function ( box ) {
					box.checked = on;
				} );
			} );
		} );

		// One field instead of one per checkbox keeps big lists under max_input_vars.
		form.addEventListener( 'submit', function () {
			var skip = [];
			boxes.forEach( function ( box ) {
				if ( ! box.checked ) {
					skip.push( box.value );
				}
				box.removeAttribute( 'name' );
			} );
			form.querySelector( 'input[name="npmp_tec_skip"]' ).value = skip.join( ',' );
			form.querySelector( 'input[name="npmp_tec_mode"]' ).value = 'skip';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.npmp-tec-runner' ).forEach( initRunner );
		document.querySelectorAll( '.npmp-tec-review-form' ).forEach( initReview );
	} );
}() );
