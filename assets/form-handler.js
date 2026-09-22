/**
 * Inline submit for a [wp-form] handed to another form plugin (Contact Form 7,
 * Fluent Forms).
 *
 * Such a form is rendered like every connected form — by this plugin, or by a
 * converted theme's own runtime — and either one's submit script would send
 * it. This one sends it instead, because the plugin's answer needs two things
 * the general scripts do not do: a token from the captcha the plugin checks
 * (reCAPTCHA or hCaptcha — whichever the server named in
 * data-cve-captcha), and its errors shown under the fields they are about
 * rather than one line under the form.
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

	var kinds = config.kinds || [ 'cf7' ];

	function handled( form ) {
		var type = form.querySelector( 'input[name="form_type"]' );
		return !! form.querySelector( 'input[name="clara_ve_nonce"]' ) && !! type && kinds.indexOf( type.value ) >= 0;
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

	// The plugin's captcha (data-cve-captcha: provider and public site key,
	// from the server). Its script is loaded — or reused when the plugin
	// already loads it on every page, as CF7 does — a widget that needs a place
	// goes before the submit button, and each submit carries a fresh token as
	// cve_captcha; the server hands it to the plugin, which verifies it with
	// its own secret. Same providers as the html2wp Gutenberg runtime.
	var scripts = {};
	var captchas = [];
	var PROVIDERS = {
		'recaptcha-v3': { api: 'grecaptcha', src: function ( c ) { return 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent( c.siteKey ); } },
		'recaptcha-v2': { api: 'grecaptcha', widget: true, src: function () { return 'https://www.google.com/recaptcha/api.js?render=explicit'; } },
		'recaptcha-v2-invisible': { api: 'grecaptcha', widget: true, src: function () { return 'https://www.google.com/recaptcha/api.js?render=explicit'; } },
		hcaptcha: { api: 'hcaptcha', widget: true, src: function () { return 'https://js.hcaptcha.com/1/api.js?render=explicit'; } },
	};

	function load( src, api ) {
		var same = src.split( '?' )[ 0 ];
		if ( ! scripts[ src ] && window[ api ] ) {
			scripts[ src ] = Promise.resolve();
		}
		if ( ! scripts[ src ] && Array.prototype.some.call( document.scripts, function ( el ) { return el.src.split( '?' )[ 0 ] === same; } ) ) {
			scripts[ src ] = Promise.resolve();
		}
		if ( ! scripts[ src ] ) {
			scripts[ src ] = new Promise( function ( ok, no ) {
				var tag = document.createElement( 'script' );
				tag.src = src;
				tag.async = true;
				tag.onload = ok;
				tag.onerror = no;
				document.head.appendChild( tag );
			} );
		}
		return scripts[ src ];
	}

	function ready( api ) {
		return new Promise( function ( ok ) {
			var tries = 0;
			( function wait() {
				var g = window[ api ];
				if ( g && g.render && ( 'grecaptcha' !== api || g.ready ) ) {
					return 'grecaptcha' === api ? g.ready( function () { ok( g ); } ) : ok( g );
				}
				if ( ++tries > 200 ) {
					return ok( null );
				}
				window.setTimeout( wait, 50 );
			}() );
		} );
	}

	function captchaOf( form ) {
		for ( var i = 0; i < captchas.length; i++ ) {
			if ( captchas[ i ].form === form ) {
				return captchas[ i ];
			}
		}
		return null;
	}

	function setupCaptcha( form ) {
		var spec = null;
		try {
			spec = JSON.parse( form.getAttribute( 'data-cve-captcha' ) || 'null' );
		} catch ( e ) {
			spec = null;
		}
		var provider = spec && PROVIDERS[ spec.provider ];
		if ( ! provider || ! spec.siteKey || captchaOf( form ) ) {
			return;
		}
		var state = { form: form, spec: spec, provider: provider };
		state.api = load( provider.src( spec ), provider.api ).then( function () { return ready( provider.api ); }, function () { return null; } );
		if ( provider.widget ) {
			var box = document.createElement( 'div' );
			box.setAttribute( 'data-cve-captcha-box', '' );
			box.style.cssText = 'margin:.75em 0';
			var button = submitButton( form );
			if ( button && button.parentNode ) {
				button.parentNode.insertBefore( box, button );
			} else {
				form.appendChild( box );
			}
			state.widget = state.api.then( function ( g ) {
				if ( ! g ) {
					return null;
				}
				var options = { sitekey: spec.siteKey };
				if ( 'recaptcha-v2-invisible' === spec.provider ) {
					options.size = 'invisible';
					options.callback = function ( t ) {
						if ( state.pending ) {
							state.pending( t );
							state.pending = null;
						}
					};
				}
				return g.render( box, options );
			} );
		}
		captchas.push( state );
	}

	// A token for this submit: '' when there is no captcha or its provider
	// could not load (the plugin then refuses, and says so), null when a
	// visible check has not been done yet.
	function captcha( form ) {
		var state = captchaOf( form );
		if ( ! state ) {
			return Promise.resolve( '' );
		}
		return state.api.then( function ( g ) {
			if ( ! g ) {
				return '';
			}
			if ( ! state.provider.widget ) {
				return g.execute( state.spec.siteKey, { action: state.spec.action || 'submit' } );
			}
			return state.widget.then( function ( id ) {
				if ( null === id || undefined === id ) {
					return '';
				}
				if ( 'recaptcha-v2-invisible' === state.spec.provider ) {
					return new Promise( function ( ok ) {
						state.pending = ok;
						g.execute( id );
					} );
				}
				return g.getResponse( id ) || null;
			} );
		} ).catch( function () {
			return '';
		} );
	}

	// Tokens are single-use: after a verdict the widget asks again.
	function resetCaptcha( form ) {
		var state = captchaOf( form );
		if ( state && state.widget ) {
			state.api.then( function ( g ) {
				return state.widget.then( function ( id ) {
					if ( g && null !== id && undefined !== id && g.reset ) {
						g.reset( id );
					}
				} );
			} );
		}
	}

	function setupAll() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-cve-captcha]' ), function ( form ) {
			if ( handled( form ) ) {
				setupCaptcha( form );
			}
		} );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', setupAll );
	} else {
		setupAll();
	}

	// The design's recorded thank-you (data-spa-success: a toast, a line after
	// the form, or a block in its place), as the design showed it. False when
	// the design recorded none.
	function recordedSuccess( form ) {
		var rec = null;
		try {
			rec = JSON.parse( form.getAttribute( 'data-spa-success' ) || 'null' );
		} catch ( e ) {
			rec = null;
		}
		if ( ! rec || ! rec.html ) {
			return false;
		}
		var box = document.createElement( 'div' );
		box.innerHTML = rec.html;
		var node = box.firstElementChild || box;
		node.setAttribute( 'data-cve-handler-note', '' );
		if ( 'replace' === rec.kind ) {
			form.parentNode.replaceChild( node, form );
			return true;
		}
		if ( 'toast' !== rec.kind ) {
			form.parentNode.insertBefore( node, form.nextSibling );
			return true;
		}
		// A toast: the source's list (and region) around it, gone after its time.
		var wrap = function ( open, fallbackTag ) {
			var m = /^<(ol|ul|div|section)([^>]*)>/.exec( open || '' );
			var el = document.createElement( m ? m[ 1 ] : fallbackTag );
			if ( m ) {
				var probe = document.createElement( 'div' );
				probe.innerHTML = '<' + m[ 1 ] + m[ 2 ] + '></' + m[ 1 ] + '>';
				var src = probe.firstElementChild;
				for ( var i = 0; i < src.attributes.length; i++ ) {
					el.setAttribute( src.attributes[ i ].name, src.attributes[ i ].value );
				}
			}
			return el;
		};
		var list = wrap( rec.list, 'ol' );
		var outer = rec.region ? wrap( rec.region, 'section' ) : null;
		list.appendChild( node );
		if ( outer ) {
			outer.appendChild( list );
		}
		document.body.appendChild( outer || list );
		if ( rec.ms > 0 ) {
			window.setTimeout( function () {
				node.setAttribute( 'data-state', 'closed' );
				window.setTimeout( function () {
					( outer || list ).remove();
				}, 400 );
			}, rec.ms );
		}
		return true;
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

		captcha( form )
			.then( function ( token ) {
				if ( null === token ) {
					throw new Error( config.verify || 'Please complete the verification first.' );
				}
				var body = new FormData( form );
				if ( token ) {
					body.set( 'cve_captcha', token );
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
				resetCaptcha( form );
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
				form.reset();
				// The design's recorded thank-you first, then the form's own
				// sentence, then the plugin's message, then the site-wide one.
				if ( ! recordedSuccess( form ) ) {
					summary( form, form.getAttribute( 'data-cve-thanks' ) || result.data.message || config.thanks || 'Thanks — check your inbox.', false );
				}
			} )
			.catch( function ( err ) {
				if ( button ) {
					button.disabled = false;
				}
				setLabel( button, original );
				summary( form, ( err && err.message && config.verify === err.message && err.message ) || config.failed || 'Something went wrong — please try again.', true );
			} );
	}
}() );
