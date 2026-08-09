/**
 * "Load more" for a [wp-posts] listing.
 *
 * The button and the grid are both ordinary markup in the page's own source —
 * this file only moves cards from the server into the grid the button points
 * at. Nothing here knows what a card looks like; that stays the page's business
 * (and the visual editor's), which is why a redesigned card needs no change
 * here.
 *
 * Markup contract:
 *   <button data-cve-load-more="blog" data-cve-target=".journal-grid">Load more</button>
 * where the value is the page key whose listing to page through, and the target
 * is a selector for the container the new cards join.
 */
( function () {
	var config = window.claraVeLoadMore || {};

	function setBusy( button, busy ) {
		if ( busy ) {
			button.setAttribute( 'aria-busy', 'true' );
			button.disabled = true;
		} else {
			button.removeAttribute( 'aria-busy' );
			button.disabled = false;
		}
	}

	function findGrid( button ) {
		var selector = button.getAttribute( 'data-cve-target' );
		if ( ! selector ) {
			return null;
		}
		// Nearest matching grid within the same section when there is one, so
		// two listings on one page can each page independently; otherwise the
		// first on the page.
		var scope = button.closest( 'section' ) || document;
		return scope.querySelector( selector ) || document.querySelector( selector );
	}

	function append( grid, html ) {
		var staging = document.createElement( 'div' );
		staging.innerHTML = html;
		var added = [];
		while ( staging.firstChild ) {
			var node = staging.firstChild;
			grid.appendChild( node );
			if ( node.nodeType === 1 ) {
				added.push( node );
			}
		}
		return added;
	}

	function load( button ) {
		// disabled already blocks a second click on a <button>; an <a> styled
		// as one would happily fire twice and append the same page twice.
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		var key = button.getAttribute( 'data-cve-load-more' );
		var grid = findGrid( button );
		if ( ! key || ! grid || ! config.endpoint ) {
			return;
		}
		button.removeAttribute( 'data-cve-error' );

		var page = ( parseInt( button.getAttribute( 'data-cve-page' ), 10 ) || 1 ) + 1;
		var url = config.endpoint + ( config.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'key=' + encodeURIComponent( key ) + '&page=' + page;

		setBusy( button, true );
		window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				var added = append( grid, data.html || '' );

				// The site reveals cards on scroll by adding .is-visible to
				// .reveal elements once they intersect. Cards appended below
				// the fold would sit invisible forever, so anything the
				// observer will not get to is shown outright.
				added.forEach( function ( node ) {
					if ( node.classList && node.classList.contains( 'reveal' ) ) {
						node.classList.add( 'is-visible' );
					}
				} );

				button.setAttribute( 'data-cve-page', String( page ) );
				setBusy( button, false );
				if ( ! data.has_more ) {
					button.hidden = true;
				}

				// Move focus to the first new card so a keyboard user is not
				// left at a button that just disappeared.
				if ( added.length ) {
					var focusable = added[ 0 ].querySelector( 'a, button' );
					if ( focusable ) {
						focusable.focus( { preventScroll: true } );
					}
				}
			} )
			.catch( function () {
				setBusy( button, false );
				// Leave the button in place and say so, rather than failing
				// silently: a listing that stops loading with no explanation
				// reads as a broken site.
				button.setAttribute( 'data-cve-error', '1' );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-cve-load-more]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		load( button );
	} );
}() );
