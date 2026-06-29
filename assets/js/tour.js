/**
 * File path: assets/js/tour.js
 *
 * Vanilla-JS guided-tour engine. ~250 lines, no dependencies beyond
 * @wordpress/api-fetch (already loaded by WP for the REST nonce).
 *
 * The engine:
 *   - reads `npmpTour` (localized by PHP) for the step list + progress
 *   - finds the step that matches the current admin screen (or 'any')
 *   - mounts a backdrop + spotlight + tooltip into #npmp-tour-root
 *   - handles Next / Back / End / navigate-between-pages
 *   - persists progress via REST so closing the tab resumes correctly
 *   - re-positions on scroll/resize via ResizeObserver + scroll listener
 *
 * Trigger flow:
 *   1. PHP renders banner (always when tour not done) + maybe modal (first visit only).
 *   2. JS attaches click handlers to banner buttons + banner dismissal.
 *   3. JS shows the first-visit modal automatically if `showModal=true`.
 *   4. When user clicks "Start the tour", JS advances state to step 1
 *      and begins rendering tooltips on the matching admin screens.
 */

( function () {
	'use strict';

	if ( typeof window.npmpTour === 'undefined' ) {
		return;
	}

	var T = window.npmpTour;
	var rootId = 'npmp-tour-root';

	// ---- Utilities -------------------------------------------------------

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'class' ) node.className = attrs[ k ];
				else if ( k === 'text' ) node.textContent = attrs[ k ];
				else if ( k === 'html' ) node.innerHTML = attrs[ k ];
				else if ( k.indexOf( 'on' ) === 0 ) node.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] );
				else node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( children ) {
			children.forEach( function ( c ) {
				if ( c ) node.appendChild( c );
			} );
		}
		return node;
	}

	function getRoot() {
		var root = document.getElementById( rootId );
		if ( ! root ) {
			root = el( 'div', { id: rootId, class: 'npmp-tour-root' } );
			document.body.appendChild( root );
		} else if ( ! root.classList.contains( 'npmp-tour-root' ) ) {
			root.classList.add( 'npmp-tour-root' );
		}
		return root;
	}

	function saveProgress( patch, cb ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			// No apiFetch — fall back to a fetch() call manually.
			fetch( T.restRoot, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': T.restNonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( patch ),
			} ).then( function ( r ) { return r.json(); } )
			   .then( function ( p ) { T.progress = p; if ( cb ) cb( p ); } );
			return;
		}
		window.wp.apiFetch( {
			path: '/npmp/v1/tour',
			method: 'POST',
			data: patch,
		} ).then( function ( p ) {
			T.progress = p;
			if ( cb ) cb( p );
		} );
	}

	// Match a step's `page` value against the current admin screen ID.
	// Supports literal match, wildcard '*' segments, and 'any'.
	function pageMatches( pageSpec, currentScreen ) {
		if ( ! pageSpec ) return false;
		if ( pageSpec === 'any' ) return true;
		if ( pageSpec === currentScreen ) return true;
		// Wildcard suffix support: 'nonprofit-manager_page_*'
		if ( pageSpec.indexOf( '*' ) !== -1 ) {
			var re = new RegExp( '^' + pageSpec.replace( /\*/g, '.*' ) + '$' );
			return re.test( currentScreen );
		}
		return false;
	}

	// Locate the step the user should see right now: first non-skipped
	// step at or after the saved progress index whose `page` matches
	// the current admin screen.
	function findCurrentStep() {
		var steps = T.steps || [];
		var idx   = ( T.progress && T.progress.step ) ? T.progress.step : 0;
		var screen = T.currentScreen || '';

		// Step 0 is the welcome — only counts as visible if not started yet.
		// If progress.step > 0 we're past welcome.
		for ( var i = idx; i < steps.length; i++ ) {
			var s = steps[ i ];
			if ( s._resolved_skip ) continue;
			if ( pageMatches( s.page, screen ) ) {
				return { step: s, index: i };
			}
		}
		return null;
	}

	// ---- Rendering -------------------------------------------------------

	var state = {
		backdrop: null,
		spotlight: null,
		tooltip: null,
		modal: null,
		rafId: null,
		currentIndex: -1,
	};

	function clearOverlay() {
		[ 'backdrop', 'spotlight', 'tooltip' ].forEach( function ( k ) {
			if ( state[ k ] ) {
				state[ k ].remove();
				state[ k ] = null;
			}
		} );
		window.removeEventListener( 'resize', repositionSpotlight );
		window.removeEventListener( 'scroll', repositionSpotlight, true );
	}

	function clearModal() {
		if ( state.modal ) {
			state.modal.remove();
			state.modal = null;
		}
	}

	function endTour( opts ) {
		clearOverlay();
		clearModal();
		var patch = { dismissed: true };
		if ( opts && opts.completed ) {
			patch.completed = true;
		}
		saveProgress( patch );
	}

	function advance( delta ) {
		var steps = T.steps || [];
		var idx = ( T.progress && T.progress.step ) ? T.progress.step : 0;
		var target = idx + delta;

		// Find the next non-skipped step in the requested direction.
		var step = steps[ target ];
		while ( step && step._resolved_skip ) {
			target += delta > 0 ? 1 : -1;
			step = steps[ target ];
		}

		if ( ! step ) {
			// Reached the end.
			endTour( { completed: true } );
			renderFinishedToast();
			return;
		}

		// If the step's advance type is 'navigate' AND we have a next_url,
		// we treat 'advance(+1)' from the previous step as the trigger.
		// The current step (whose target=='navigate') was what told us
		// where to go.
		// Simpler model: when calling advance(+1) on a 'navigate' step,
		// persist progress THEN navigate.
		var current = steps[ idx ];
		if ( current && current.advance === 'navigate' && current.next_url && delta > 0 ) {
			saveProgress( { step: target }, function () {
				var url = current.next_url;
				if ( url.indexOf( 'http' ) !== 0 ) {
					url = T.adminUrl + url.replace( /^\/+/, '' );
				}
				window.location.href = url;
			} );
			return;
		}

		saveProgress( { step: target }, function () {
			// If the new step's page doesn't match the current screen,
			// silently wait for the user to navigate there. The banner
			// stays visible so they can re-start if they get lost.
			if ( step.page !== 'any' && step.page !== T.currentScreen && step.page.indexOf( '*' ) === -1 ) {
				clearOverlay();
				return;
			}
			renderStep( step, target );
		} );
	}

	function renderStep( step, index ) {
		clearOverlay();
		state.currentIndex = index;

		var root = getRoot();
		var totalVisible = ( T.steps || [] ).filter( function ( s ) { return ! s._resolved_skip; } ).length;
		var visibleIndex = ( T.steps || [] ).slice( 0, index + 1 ).filter( function ( s ) { return ! s._resolved_skip; } ).length;

		// Backdrop
		state.backdrop = el( 'div', { class: 'npmp-tour-backdrop' + ( ! step.target ? ' is-fullscreen' : '' ) } );
		root.appendChild( state.backdrop );

		// Spotlight (only when we have a target)
		if ( step.target ) {
			var targetEl = $( step.target );
			if ( targetEl ) {
				state.spotlight = el( 'div', { class: 'npmp-tour-spotlight' } );
				root.appendChild( state.spotlight );
				positionSpotlight( targetEl );
				window.addEventListener( 'resize', repositionSpotlight );
				window.addEventListener( 'scroll', repositionSpotlight, true );
				// If the user clicks the spotlighted element (e.g. a menu
				// link that navigates), let the browser handle it. The
				// next page's tour engine picks up the saved step.
			} else {
				// Target wasn't found in the DOM. Fall back to a
				// center-placed tooltip (still useful copy).
				step = Object.assign( {}, step, { target: null, placement: 'center' } );
			}
		}

		// Tooltip
		var placement = step.placement || ( step.target ? 'right' : 'center' );
		state.tooltip = el( 'div', {
			class: 'npmp-tour-tooltip',
			'data-placement': placement,
			role: 'dialog',
			'aria-modal': 'true',
			'aria-labelledby': 'npmp-tour-tooltip-title',
		} );

		var stepLabel = T.i18n.stepLabel.replace( '%1$d', visibleIndex ).replace( '%2$d', totalVisible );
		state.tooltip.appendChild( el( 'span', { class: 'npmp-tour-tooltip__step', text: stepLabel } ) );
		state.tooltip.appendChild( el( 'h3', { class: 'npmp-tour-tooltip__title', id: 'npmp-tour-tooltip-title', text: step.title } ) );
		state.tooltip.appendChild( el( 'div', { class: 'npmp-tour-tooltip__body', html: step.body } ) );

		var actions = el( 'div', { class: 'npmp-tour-tooltip__actions' } );

		// Back button (not on first step)
		if ( index > 0 ) {
			actions.appendChild( el( 'button', {
				type: 'button',
				class: 'npmp-tour-btn',
				text: T.i18n.back,
				onclick: function () { advance( -1 ); },
			} ) );
		}

		// Primary action
		var primaryLabel = step.primary || ( step.advance === 'finish' ? T.i18n.end : T.i18n.next );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'npmp-tour-btn npmp-tour-btn--primary',
			text: primaryLabel,
			onclick: function () {
				if ( step.advance === 'finish' ) {
					endTour( { completed: true } );
					return;
				}
				advance( 1 );
			},
		} ) );

		// End-tour link (always available per Eric's spec)
		if ( step.advance !== 'finish' ) {
			actions.appendChild( el( 'button', {
				type: 'button',
				class: 'npmp-tour-btn npmp-tour-btn-end',
				text: T.i18n.end,
				onclick: function () { endTour( {} ); },
			} ) );
		}

		state.tooltip.appendChild( actions );

		// Arrow
		var arrow = el( 'div', { class: 'npmp-tour-tooltip__arrow' } );
		state.tooltip.appendChild( arrow );

		root.appendChild( state.tooltip );

		// Position tooltip after one rAF so the browser has measured it.
		requestAnimationFrame( function () {
			positionTooltip();
			state.backdrop.classList.add( 'is-visible' );
			state.tooltip.classList.add( 'is-visible' );
		} );
	}

	function positionSpotlight( targetEl ) {
		if ( ! state.spotlight ) return;
		var rect = targetEl.getBoundingClientRect();
		var pad = 6;
		state.spotlight.style.top    = ( rect.top - pad ) + 'px';
		state.spotlight.style.left   = ( rect.left - pad ) + 'px';
		state.spotlight.style.width  = ( rect.width + pad * 2 ) + 'px';
		state.spotlight.style.height = ( rect.height + pad * 2 ) + 'px';
	}

	function repositionSpotlight() {
		var step = ( T.steps || [] )[ state.currentIndex ];
		if ( ! step || ! step.target ) return;
		var targetEl = $( step.target );
		if ( targetEl ) positionSpotlight( targetEl );
		positionTooltip();
	}

	function positionTooltip() {
		if ( ! state.tooltip || ! state.spotlight ) return;
		var sRect = state.spotlight.getBoundingClientRect();
		var tRect = state.tooltip.getBoundingClientRect();
		var gap = 14;
		var placement = state.tooltip.getAttribute( 'data-placement' );
		var top, left;

		switch ( placement ) {
			case 'top':
				top = sRect.top - tRect.height - gap;
				left = sRect.left + sRect.width / 2 - tRect.width / 2;
				break;
			case 'bottom':
				top = sRect.bottom + gap;
				left = sRect.left + sRect.width / 2 - tRect.width / 2;
				break;
			case 'left':
				top = sRect.top + sRect.height / 2 - tRect.height / 2;
				left = sRect.left - tRect.width - gap;
				break;
			case 'right':
			default:
				top = sRect.top + sRect.height / 2 - tRect.height / 2;
				left = sRect.right + gap;
				break;
		}

		// Clamp to viewport with a small margin.
		var margin = 12;
		var vw = window.innerWidth, vh = window.innerHeight;
		left = Math.max( margin, Math.min( left, vw - tRect.width - margin ) );
		top  = Math.max( margin, Math.min( top,  vh - tRect.height - margin ) );

		state.tooltip.style.top  = top + 'px';
		state.tooltip.style.left = left + 'px';
	}

	// ---- Modal (first visit) --------------------------------------------

	function renderModal() {
		var root = getRoot();
		state.modal = el( 'div', { class: 'npmp-tour-modal-overlay' } );
		var card = el( 'div', { class: 'npmp-tour-modal', role: 'dialog', 'aria-modal': 'true' } );
		card.appendChild( el( 'div', { class: 'npmp-tour-modal__icon', text: '🎯' } ) );
		card.appendChild( el( 'h2', { class: 'npmp-tour-modal__title', text: T.i18n.modalTitle } ) );
		card.appendChild( el( 'p', { class: 'npmp-tour-modal__body', text: T.i18n.modalBody } ) );

		var actions = el( 'div', { class: 'npmp-tour-modal__actions' } );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'npmp-tour-btn npmp-tour-btn--primary',
			text: T.i18n.start,
			onclick: function () {
				clearModal();
				// Advance to step 1 (welcome already shown via modal).
				saveProgress( { step: 1, started_at: Math.floor( Date.now() / 1000 ) }, function () {
					var step = ( T.steps || [] )[ 1 ];
					if ( step && pageMatches( step.page, T.currentScreen ) ) {
						renderStep( step, 1 );
					} else if ( step && step.advance === 'navigate' && step.next_url ) {
						var url = step.next_url;
						if ( url.indexOf( 'http' ) !== 0 ) {
							url = T.adminUrl + url.replace( /^\/+/, '' );
						}
						window.location.href = url;
					}
				} );
			},
		} ) );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'npmp-tour-btn',
			text: T.i18n.dismiss,
			onclick: function () {
				clearModal();
				saveProgress( { dismissed: true } );
			},
		} ) );
		card.appendChild( actions );

		state.modal.appendChild( card );
		root.appendChild( state.modal );

		requestAnimationFrame( function () {
			state.modal.classList.add( 'is-visible' );
		} );
	}

	function renderFinishedToast() {
		var root = getRoot();
		clearModal();
		state.modal = el( 'div', { class: 'npmp-tour-modal-overlay' } );
		var card = el( 'div', { class: 'npmp-tour-modal', role: 'dialog' } );
		card.appendChild( el( 'div', { class: 'npmp-tour-modal__icon', text: '✅' } ) );
		card.appendChild( el( 'h2', { class: 'npmp-tour-modal__title', text: T.i18n.finishedTitle } ) );
		card.appendChild( el( 'p', { class: 'npmp-tour-modal__body', text: T.i18n.finishedBody } ) );
		var actions = el( 'div', { class: 'npmp-tour-modal__actions' } );
		actions.appendChild( el( 'button', {
			type: 'button',
			class: 'npmp-tour-btn npmp-tour-btn--primary',
			text: 'OK',
			onclick: clearModal,
		} ) );
		card.appendChild( actions );
		state.modal.appendChild( card );
		root.appendChild( state.modal );
		requestAnimationFrame( function () {
			state.modal.classList.add( 'is-visible' );
		} );
	}

	// ---- Banner wiring + bootstrap --------------------------------------

	function wireBanner() {
		document.querySelectorAll( '[data-npmp-tour-action]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var action = btn.getAttribute( 'data-npmp-tour-action' );
				if ( action === 'start' ) {
					// Banner "Start the tour" → kick off step 1.
					saveProgress( { step: 1, started_at: Math.floor( Date.now() / 1000 ) }, function () {
						var step = ( T.steps || [] )[ 1 ];
						if ( step && pageMatches( step.page, T.currentScreen ) ) {
							renderStep( step, 1 );
						} else if ( step && step.advance === 'navigate' && step.next_url ) {
							var url = step.next_url;
							if ( url.indexOf( 'http' ) !== 0 ) {
								url = T.adminUrl + url.replace( /^\/+/, '' );
							}
							window.location.href = url;
						}
					} );
					// Hide the banner (it'll come back on next page load only
					// if the user re-dismisses progress).
					var banner = btn.closest( '.npmp-tour-banner' );
					if ( banner ) banner.style.display = 'none';
				}
				if ( action === 'dismiss-banner' ) {
					saveProgress( { dismissed: true }, function () {
						var banner = btn.closest( '.npmp-tour-banner' );
						if ( banner ) banner.style.display = 'none';
					} );
				}
			} );
		} );
	}

	// Bootstrap on DOM ready.
	function boot() {
		wireBanner();

		// Modal on first visit?
		if ( T.showModal ) {
			renderModal();
			return;
		}

		// If we're mid-tour, find the current step for this page and render.
		var p = T.progress || {};
		if ( p.completed || p.dismissed ) {
			return;
		}
		if ( ! p.step || p.step < 1 ) {
			// Tour not started yet, but no modal showing. The banner
			// handles the next interaction.
			return;
		}
		var found = findCurrentStep();
		if ( found ) {
			renderStep( found.step, found.index );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
