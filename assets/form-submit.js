/**
 * Inline submit for a connected [wp-form].
 *
 * Progressive enhancement, strictly: the form already works as plain HTML —
 * POST, redirect, done — and everything here is an upgrade layered on top. If
 * this file fails to load, fails to parse, or throws, the browser's own submit
 * takes over and the visitor still gets through. That is why the submit is
 * intercepted rather than the button being rewired: an intercept that never
 * runs leaves a working form behind.
 *
 * What it upgrades: the answer arrives without the page going white, the button
 * says what happened, and a form set to "Stay on this page" can finally stay on
 * it — which is the option that made no sense while every submit was a
 * navigation.
 */
( function () {
	var config = window.claraVeForm || {};

	// Only forms this plugin connected. The marker is the nonce field the token
	// injects — a form the owner has not connected is left completely alone.
	function isConnected( form ) {
		return !! form.querySelector( 'input[name="clara_ve_nonce"]' );
	}

	function submitButton( form ) {
		return form.querySelector( 'button[type="submit"], input[type="submit"], button:not([type])' );
	}

	function setLabel( button, text ) {
		if ( ! button ) {
			return;
		}
		if ( 'INPUT' === button.tagName ) {
			button.value = text;
		} else {
			button.textContent = text;
		}
	}

	function labelOf( button ) {
		if ( ! button ) {
			return '';
		}
		return 'INPUT' === button.tagName ? button.value : button.textContent;
	}

	// The message goes AFTER the form for the same reason the consent note
	// does: a designed form is usually a flex row that does not wrap, and an
	// element added inside it becomes a column that breaks the layout.
	function message( form, text, isError ) {
		var note = form.nextElementSibling;
		if ( ! note || ! note.classList || ! note.classList.contains( 'cve-form-message' ) ) {
			note = document.createElement( 'p' );
			note.className = 'cve-form-message';
			note.setAttribute( 'role', 'status' );
			note.setAttribute( 'aria-live', 'polite' );
			form.parentNode.insertBefore( note, form.nextSibling );
		}
		// An attribute, not an inline style: assets/forms.css owns the look and
		// a theme can take it over.
		if ( isError ) {
			note.setAttribute( 'data-cve-error', '1' );
		} else {
			note.removeAttribute( 'data-cve-error' );
		}
		note.textContent = text;
	}

	document.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;
			if ( ! form || 'FORM' !== form.tagName || ! isConnected( form ) || ! window.fetch || ! window.FormData ) {
				return;
			}
			event.preventDefault();

			var button = submitButton( form );
			var original = labelOf( button );
			if ( button ) {
				button.disabled = true;
			}
			setLabel( button, config.sending || 'Sending…' );

			window
				.fetch( form.action, {
					method: 'POST',
					credentials: 'same-origin',
					// The header is what tells the endpoint to answer with data
					// instead of a redirect. Nothing else about the request
					// differs from the browser's own.
					headers: { 'X-Clara-VE-Inline': '1' },
					body: new FormData( form ),
				} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						throw new Error( ( result.data && result.data.message ) || 'error' );
					}
					// A configured destination is still the owner's choice —
					// honour it, just without the round trip through a POST.
					if ( result.data && result.data.redirect ) {
						window.location.href = result.data.redirect;
						return;
					}
					setLabel( button, config.sent || 'Sent!' );
					message( form, config.thanks || 'Thanks — check your inbox.', false );
					// Left disabled: the work is done, and a re-enabled button
					// under a "Sent!" label invites a second submission that
					// only trips the rate limit.
					form.reset();
				} )
				.catch( function ( err ) {
					if ( button ) {
						button.disabled = false;
					}
					setLabel( button, original );
					message( form, ( err && err.message ) || ( config.failed || 'Something went wrong — please try again.' ), true );
				} );
		},
		// Capture, so a theme's own submit handler cannot navigate first.
		true
	);
}() );
