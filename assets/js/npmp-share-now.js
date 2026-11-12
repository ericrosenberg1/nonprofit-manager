/**
 * File path: assets/js/npmp-share-now.js
 *
 * The Social Sharing meta box's "Share now" button. It posts to admin-ajax
 * (npmp_social_ajax_share_now) because the block editor saves meta boxes
 * without their submit buttons, so a submit button never reached the server.
 */
( function () {
	'use strict';

	var cfg = window.npmpShareNow || {};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '.npmp-share-now' ) : null;
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();

		var box = btn.closest( '.inside' ) || btn.parentNode;
		var message = box.querySelector( '.npmp-share-now-message' );
		var status = box.querySelector( '.npmp-share-status' );
		var label = btn.textContent;

		btn.disabled = true;
		btn.textContent = cfg.sharing || label;

		var body = new FormData();
		body.append( 'action', 'npmp_social_share_now' );
		body.append( 'post_id', btn.getAttribute( 'data-post' ) );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				var data = ( r && r.data ) || {};
				if ( message ) {
					message.textContent = data.message || ( r && r.success ? '' : cfg.failed );
				}
				if ( r && r.success && status && data.html ) {
					status.innerHTML = data.html;
				}
			} )
			.catch( function () {
				if ( message ) {
					message.textContent = cfg.failed || '';
				}
			} )
			.then( function () {
				btn.disabled = false;
				btn.textContent = label;
			} );
	} );
} )();
