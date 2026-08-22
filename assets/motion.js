/**
 * Reveal blocks as they scroll into view.
 *
 * Small on purpose. One IntersectionObserver, one class, no dependencies, and
 * it only ever runs on a page that actually has something to reveal — the
 * server does not enqueue it otherwise.
 *
 * The important part is the first line of work: `cve-motion` goes on the root
 * element HERE, from script, and the stylesheet hides nothing without it. So a
 * page whose JavaScript is blocked, broken or still loading shows its content
 * rather than a column of invisible blocks.
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var targets = document.querySelectorAll( '[class*="cve-anim-"]' );
	if ( ! targets.length ) {
		return;
	}

	// Somebody who has asked their machine to stop moving things has asked for
	// that. Nothing is hidden, nothing is observed, and the class is never
	// added — the page simply arrives.
	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}

	// Without the observer the starting state would never be undone, so the
	// hidden state is not applied at all.
	if ( ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	root.classList.add( 'cve-motion' );

	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}
				entry.target.classList.add( 'is-revealed' );
				// Once revealed, a block stays revealed: scrolling back up and
				// down again should not replay it.
				observer.unobserve( entry.target );
			} );
		},
		{
			// A little before the block's top edge arrives, so the movement
			// finishes about when it is properly in view.
			rootMargin: '0px 0px -12% 0px',
			threshold: 0.01,
		}
	);

	Array.prototype.forEach.call( targets, function ( node ) {
		observer.observe( node );
	} );
} )();
