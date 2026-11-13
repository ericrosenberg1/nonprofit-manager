/**
 * Nonprofit Manager · "Print or save as PDF" for calendars and event lists.
 *
 * A click marks the button's calendar or list (.npmp-print-target) and every
 * element above it (.npmp-print-path), adds .npmp-printing to the body, and
 * opens the print dialog. assets/css/npmp-calendar-print.css hides everything
 * off that path, so only this one calendar reaches the paper or the PDF. All
 * of those rules sit inside @media print, so nothing changes on screen.
 *
 * Loaded as an enqueued file, never inline: wptexturize mangles inline
 * scripts on block themes.
 */
( function () {
	'use strict';

	var BODY_CLASS   = 'npmp-printing';
	var PATH_CLASS   = 'npmp-print-path';
	var TARGET_CLASS = 'npmp-print-target';

	function clear() {
		var marked = document.querySelectorAll( '.' + PATH_CLASS + ', .' + TARGET_CLASS );
		for ( var i = 0; i !== marked.length; i++ ) {
			marked[ i ].classList.remove( PATH_CLASS, TARGET_CLASS );
		}
		document.body.classList.remove( BODY_CLASS );
	}

	function printRegion( region ) {
		clear();
		region.classList.add( TARGET_CLASS );
		var node = region.parentElement;
		while ( node && node !== document.documentElement ) {
			node.classList.add( PATH_CLASS );
			node = node.parentElement;
		}
		document.body.classList.add( BODY_CLASS );
		window.print();
	}

	function onClick( event ) {
		var region = event.currentTarget.closest( '.npmp-print-region' );
		if ( region ) {
			printRegion( region );
		} else {
			window.print();
		}
	}

	function init() {
		var buttons = document.querySelectorAll( '.npmp-print-button' );
		for ( var i = 0; i !== buttons.length; i++ ) {
			var bar = buttons[ i ].closest( '.npmp-print-bar' );
			if ( bar ) {
				bar.hidden = false;
			}
			buttons[ i ].addEventListener( 'click', onClick );
		}
	}

	window.addEventListener( 'afterprint', clear );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
