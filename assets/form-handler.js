/**
 * Inline submit for a [wp-form] handed to another form plugin (Contact Form 7).
 *
 * Such a form is rendered like every connected form — by this plugin, or by a
 * converted theme's own runtime — and either one's submit script would send
 * it. This one sends it instead, because the plugin's answer needs two things
 * the general scripts do not do: a reCAPTCHA v3 token the plugin checks, and
 * its errors shown under the fields they are about rather than one line under
 * the form.
 *
 * The order matters. A converted design keeps its own validation (the
 * messages it recorded, shown on an empty submit), and that runs as a
 * document capture listener which cancels the submit; the general submit
 * scripts are document capture listeners too. So: on the WINDOW, before all
 * of them, the connection marker (the origin token's name) is held back for
 * the length of this one dispatch — the general scripts see an unconnected
 * form and leave it, the design's validation runs as it always does — and on
 * the FORM itself, after them, the marker is put back and, unless the
 * validation cancelled it, the form is sent from here. Stopping it there
 * also keeps the theme's "this form isn't connected" notice (a document
 * bubble listener) from seeing a connected form as unconnected.
 *
 * Progressive enhancement, like form-submit.js: without this file the form
 * still posts and the plugin still processes it.
 */
( function () {
	var config = window.claraVeFormHandler || {};

	function handled( form ) {
		return !! form.querySelector( 'input[name="clara_ve_nonce"]' ) && !! form.querySelector( 'input[name="form_type"][value="cf7"]' );
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

	// The name the server keys a field by: sanitize_key() of what the browser
	// sends, a checkbox group's "[]" left off.
	function key( name ) {
		return String( name || '' ).replace( /\[\]$/, '' ).toLowerCase().replace( /[^a-z0-9_\-]/g, '' );
	}

	// An error in the design's own look when it recorded one (the validation
	// message a converted form keeps, [data-spa-invalid]); otherwise — and for
	// every success — the same element form-submit.js writes, which
	// assets/forms.css and the theme style.
	function line( form, text, isError ) {
		var look = isError ? form.querySelector( '[data-spa-invalid]' ) : null;
		var note = document.createElement( look ? look.tagName : 'p' );
		note.className = look && look.getAttribute( 'class' ) ? look.getAttribute( 'class' ) : 'cve-form-message';
		note.setAttribute( 'data-cve-handler-note', '' );
		note.setAttribute( 'role', isError ? 'alert' : 'status' );
		if ( isError ) {
			note.setAttribute( 'data-cve-error', '1' );
		}
		note.textContent = text;
		return note;
	}

	function clear( form ) {
		var scope = form.parentNode || form;
		Array.prototype.forEach.call( scope.querySelectorAll( '[data-cve-handler-note]' ), function ( note ) {
			note.parentNode.removeChild( note );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid="true"]' ), function ( control ) {
			control.removeAttribute( 'aria-invalid' );
		} );
	}

	// After the form, not inside it: a designed form is often a row that does
	// not wrap, and anything added inside becomes a column.
	function summary( form, text, isError ) {
		form.parentNode.insertBefore( line( form, text, isError ), form.nextSibling );
	}

	// Under the field, inside the field's own wrapper. A control that sits
	// directly in the form (the one-row newsletter shape) has no wrapper to
	// hold a line without breaking the row, so its reason joins the summary.
	function fieldErrors( form, errors ) {
		var left = [];
		Object.keys( errors || {} ).forEach( function ( name ) {
			var text = errors[ name ];
			if ( ! text ) {
				return;
			}
			var control = null;
			for ( var i = 0; i < form.elements.length; i++ ) {
				if ( form.elements[ i ].name && key( form.elements[ i ].name ) === name && 'hidden' !== form.elements[ i ].type ) {
					control = form.elements[ i ];
					break;
				}
			}
			// A group of boxes answers as one: the reason goes under the group;
			// a control wrapped in its label, under the label.
			var group = control && ( ( ( 'checkbox' === control.type || 'radio' === control.type ) && control.closest( 'fieldset' ) ) || control );
			if ( group && group.parentNode && 'LABEL' === group.parentNode.tagName ) {
				group = group.parentNode;
			}
			if ( ! group || group.parentNode === form ) {
				left.push( text );
				return;
			}
			var note = line( form, text, true );
			if ( control.id ) {
				note.id = control.id + '-cve-error';
				control.setAttribute( 'aria-describedby', note.id );
			}
			control.setAttribute( 'aria-invalid', 'true' );
			group.parentNode.insertBefore( note, group.nextSibling );
		} );
		return left;
	}

	// CF7's own reCAPTCHA v3 script only fills the forms CF7 rendered; this
	// asks for a token the same way for ours. No reCAPTCHA on the site: none.
	function captcha() {
		var recaptcha = window.wpcf7_recaptcha;
		var g = window.grecaptcha;
		if ( ! recaptcha || ! recaptcha.sitekey || ! g || ! g.execute ) {
			return Promise.resolve( '' );
		}
		var action = ( recaptcha.actions && recaptcha.actions.contactform ) || 'contactform';
		return new Promise( function ( resolve ) {
			g.ready( function () {
				g.execute( recaptcha.sitekey, { action: action } ).then( resolve, function () {
					resolve( '' );
				} );
			} );
		} );
	}

	var HELD = 'clara_ve_nonce__held';

	function release( form ) {
		var held = form.querySelector( 'input[name="' + HELD + '"]' );
		if ( held ) {
			held.name = 'clara_ve_nonce';
		}
	}

	window.addEventListener(
		'submit',
		function ( event ) {
			var form = event.target;
			if ( ! form || 'FORM' !== form.tagName || ! handled( form ) || ! window.fetch || ! window.FormData || ! window.Promise ) {
				return;
			}
			if ( ! form.__cveHandler ) {
				form.__cveHandler = true;
				form.addEventListener( 'submit', send );
			}
			var marker = form.querySelector( 'input[name="clara_ve_nonce"]' );
			if ( marker ) {
				marker.name = HELD;
			}
			// A listener that stopped the event before it reached the form
			// (the design's validation does) never gives it back otherwise.
			window.setTimeout( function () {
				release( form );
			}, 0 );
		},
		true
	);

	function send( event ) {
		var form = event.currentTarget;
		release( form );
		if ( event.defaultPrevented ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();

		var button = submitButton( form );
		var original = labelOf( button );
		if ( button ) {
			button.disabled = true;
		}
		setLabel( button, config.sending || 'Sending…' );
		clear( form );

		captcha()
			.then( function ( token ) {
				var body = new FormData( form );
				if ( token ) {
					body.set( '_wpcf7_recaptcha_response', token );
				}
				// getAttribute: a field named "action" would shadow form.action.
				return window.fetch( form.getAttribute( 'action' ), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-Clara-VE-Inline': '1' },
					body: body,
				} );
			} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data || {} };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					var errors = ( result.data.data && result.data.data.errors ) || {};
					var left = fieldErrors( form, errors );
					var text = [ result.data.message || config.failed || 'Something went wrong — please try again.' ].concat( left ).join( ' ' );
					if ( button ) {
						button.disabled = false;
					}
					setLabel( button, original );
					summary( form, text, true );
					return;
				}
				if ( result.data.redirect ) {
					window.location.href = result.data.redirect;
					return;
				}
				setLabel( button, config.sent || 'Sent!' );
				// The design's recorded thank-you first, then the plugin's
				// own message, then the site-wide sentence.
				summary( form, form.getAttribute( 'data-cve-thanks' ) || result.data.message || config.thanks || 'Thanks — check your inbox.', false );
				form.reset();
			} )
			.catch( function () {
				if ( button ) {
					button.disabled = false;
				}
				setLabel( button, original );
				summary( form, config.failed || 'Something went wrong — please try again.', true );
			} );
	}
}() );
