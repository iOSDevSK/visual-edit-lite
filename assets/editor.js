/**
 * Visual editor host — the open-design manual-edit UX:
 * permanent dashed guides in the canvas, a draggable dark floating inspector
 * anchored to the selection with TYPOGRAPHY controls (font, size, weight,
 * color, align, line, tracking), image swap via the WP Media Library, link
 * fields, per-element delete/reset/Cancel/Save, and a global Save that
 * patches the canonical source (DOMParser round-trip, open-design style).
 */
( function () {
	'use strict';

	var config = window.claraVeConfig || {};
	var frame = document.getElementById( 'clara-ve-frame' );
	var body = document.querySelector( '.clara-ve-body' );
	var saveBtn = document.getElementById( 'clara-ve-save' );
	var discardBtn = document.getElementById( 'clara-ve-discard' );
	var statusEl = document.getElementById( 'clara-ve-status' );
	var toggleBtn = document.getElementById( 'clara-ve-toggle' );
	var currentFrameUrl = '';
	var previewBtn = document.getElementById( 'clara-ve-preview' );
	var pagePicker = document.getElementById( 'clara-ve-page-picker' );
	var toolbarTitle = document.querySelector( '.clara-ve-toolbar > strong' );

	if ( ! frame ) {
		return;
	}

	// ---- Multi-page support: which page is currently open ----
	//
	// DEFAULT_KEY mirrors CLARA_VE_DEFAULT_KEY (PHP) — the front page, which
	// isn't a real WP Page but the plugin's original pattern-override
	// mechanism. Any other key is a WP Page tagged via the Pages-list
	// "Visual Editor" column.

	var DEFAULT_KEY = 'front-page';
	// The header/footer template parts have no page/URL of their own — they
	// preview on a real tagged page's URL instead (borrowed via /pages, see
	// class-rest.php::list_visual_pages()), with this override param telling
	// the PHP side which part of THAT page is actually being edited.
	// Keys with no page of their own — they borrow another URL to preview on,
	// so the URL alone can't say which one is being edited and the key has to
	// ride along explicitly. 'article' belongs here for the opposite reason to
	// header/footer: it has too MANY pages, one per post, all rendering the
	// same template.
	// From the server, because only the theme contract knows how many chrome
	// parts this theme has — a hardcoded trio here predated variants and cost
	// every variant part its editability (school-007).
	var CHROME_KEYS = {};
	( config.chromeKeys || [ 'header', 'footer', 'article' ] ).forEach( function ( k ) {
		CHROME_KEYS[ k ] = 1;
	} );
	var currentKey = config.initialKey || DEFAULT_KEY;
	var visualPages = []; // [{key,label,url}], populated from /clara-ve/v1/pages

	// Do two URLs address the same page? Compared on path alone, ignoring a
	// trailing slash, so a permalink and a link written in the markup match
	// even when they differ in those incidentals.
	function samePagePath( a, b ) {
		function norm( u ) {
			try {
				var p = new URL( u, window.location.origin ).pathname;
				return p.replace( /\/+$/, '' ) || '/';
			} catch ( e ) {
				return '';
			}
		}
		var na = norm( a );
		return '' !== na && na === norm( b );
	}

	function previewUrlForKey( key ) {
		var match = visualPages.filter( function ( p ) {
			return p.key === key;
		} )[ 0 ];
		var base = match ? match.url : config.homeUrl;
		var sep = base.indexOf( '?' ) === -1 ? '?' : '&';
		var url = base + sep + 'clara_edit=1&_clara_ve=' + encodeURIComponent( config.previewNonce );
		if ( CHROME_KEYS[ key ] ) {
			url += '&clara_ve_key=' + encodeURIComponent( key );
		}
		return url;
	}

	// Turn any same-site URL into an edit-preview URL. Used when following a
	// link the page list has no entry for.
	function editPreviewUrl( href ) {
		var sep = href.indexOf( '?' ) === -1 ? '?' : '&';
		return href + sep + 'clara_edit=1&_clara_ve=' + encodeURIComponent( config.previewNonce );
	}

	// Switch the editor onto a key the FRAME has already navigated to. Same
	// bookkeeping as switchToKey (source, history, toolbar) minus
	// the navigation — the frame is already showing the right page, and
	// re-pointing it would throw away the very article the user clicked.
	function adoptKey( key ) {
		currentKey = key;
		if ( pagePicker && pagePicker.value !== key ) {
			pagePicker.value = key;
		}
		patches = [];
		setDirty();
		closePanelSilent();
		updateToolbarTitle();
		window.wp.apiFetch( { path: '/clara-ve/v1/source?key=' + encodeURIComponent( currentKey ) } ).then( function ( res ) {
			source = res.source;
		} );
		if ( historyPanel && historyPanel.classList.contains( 'is-open' ) ) {
			loadHistory();
		}
		if ( seoPanel && seoPanel.classList.contains( 'is-open' ) ) {
			loadSeo(); // per page, so a stale panel would edit the page just left
		}
		renderAiThread();
	}

	// The plain, real URL a logged-out visitor sees — no `clara_edit`/nonce
	// params, so no editor bridge loads there at all. Chrome keys (header/
	// footer) have no page of their own; this just opens whatever tagged
	// page's URL they're currently borrowing for preview, showing the real
	// deployed header/footer in context on a real page.
	function liveUrlForKey( key ) {
		// Prefer the page the frame is actually showing over the key's nominal
		// preview URL. They differ for the article template: it is edited on
		// whichever post you opened, so "preview" must open THAT article, not
		// whichever post the page list happened to nominate. Only the edit
		// parameters are removed — everything else about the URL is kept.
		if ( currentFrameUrl ) {
			var stripped = currentFrameUrl
				.replace( /([?&])clara_edit=1(&|$)/, '$1' )
				.replace( /([?&])_clara_ve=[^&]*(&|$)/, '$1' )
				.replace( /([?&])clara_ve_key=[^&]*(&|$)/, '$1' )
				.replace( /[?&]$/, '' );
			if ( stripped ) {
				return stripped;
			}
		}
		var match = visualPages.filter( function ( p ) {
			return p.key === key;
		} )[ 0 ];
		return match ? match.url : config.homeUrl;
	}

	// Reload target for "same key, fresh copy" — after a save, a history
	// restore, a discard, or coming back from a page that couldn't be edited.
	// It must return the page currently OPEN, not the key's nominal preview
	// URL: the article template is edited on whichever post you opened, and
	// bouncing you to a different article after every save would be its own
	// small betrayal.
	function reloadUrlForCurrentKey() {
		var live = liveUrlForKey( currentKey );
		var match = visualPages.filter( function ( p ) {
			return p.key === currentKey;
		} )[ 0 ];
		if ( ! currentFrameUrl || ( match && live === match.url ) ) {
			return previewUrlForKey( currentKey );
		}
		var url = editPreviewUrl( live );
		if ( CHROME_KEYS[ currentKey ] ) {
			url += '&clara_ve_key=' + encodeURIComponent( currentKey );
		}
		return url;
	}

	function updateToolbarTitle() {
		if ( ! toolbarTitle ) {
			return;
		}
		var match = visualPages.filter( function ( p ) {
			return p.key === currentKey;
		} )[ 0 ];
		toolbarTitle.textContent = 'Visual Edit Lite — ' + ( match ? match.label : currentKey );
	}

	function switchToKey( key ) {
		if ( key === currentKey ) {
			return;
		}
		if ( patches.length && ! window.confirm( 'Switch pages and discard ' + patches.length + ' unsaved change' + ( patches.length > 1 ? 's' : '' ) + '?' ) ) {
			if ( pagePicker ) {
				pagePicker.value = currentKey; // revert the select
			}
			return;
		}
		currentKey = key;
		// Until now this only ever ran from the picker's own change event, so
		// the select already held the new value; it can also be driven by
		// following a link in the preview, which leaves it showing the page
		// we just left.
		if ( pagePicker && pagePicker.value !== key ) {
			pagePicker.value = key;
		}
		patches = [];
		setDirty();
		closePanelSilent();
		updateToolbarTitle();
		// Cleared before navigating: until the new page announces itself,
		// "preview" and any reload must fall back to the page list rather than
		// point at the page we just left.
		currentFrameUrl = '';
		frame.src = previewUrlForKey( currentKey );
		window.wp.apiFetch( { path: '/clara-ve/v1/source?key=' + encodeURIComponent( currentKey ) } ).then( function ( res ) {
			source = res.source;
		} );
		if ( historyPanel && historyPanel.classList.contains( 'is-open' ) ) {
			loadHistory();
		}
		if ( seoPanel && seoPanel.classList.contains( 'is-open' ) ) {
			loadSeo(); // per page, so a stale panel would edit the page just left
		}
		renderAiThread();
	}

	function loadVisualPages() {
		window.wp.apiFetch( { path: '/clara-ve/v1/pages' } ).then( function ( pages ) {
			visualPages = pages || [];
			if ( pagePicker ) {
				pagePicker.innerHTML = '';
				visualPages.forEach( function ( p ) {
					var opt = document.createElement( 'option' );
					opt.value = p.key;
					opt.textContent = p.label;
					pagePicker.appendChild( opt );
				} );
				pagePicker.value = currentKey;
			}
			updateToolbarTitle();
			if ( currentKey !== DEFAULT_KEY ) {
				// The module-top fast path below only covers the front page —
				// an initialKey arriving via the Pages-list column link needs
				// the fetched page list to resolve its real permalink first.
				frame.src = previewUrlForKey( currentKey );
			}
		} );
	}

	if ( pagePicker ) {
		pagePicker.addEventListener( 'change', function () {
			switchToKey( pagePicker.value );
		} );
	}

	loadVisualPages();

	if ( currentKey === DEFAULT_KEY ) {
		frame.src = config.previewUrl;
	}

	// ---- Device / viewport preview (ported from open-design FileViewer) ----
	// The iframe is given a REAL device pixel width so the front page's own
	// media queries and vw units re-evaluate (true responsive preview), then the
	// shell is transform:scale()'d to fit the canvas; the clip box is sized to
	// the scaled dimensions and centred. Desktop = fill, no scaling.
	var canvas = document.getElementById( 'clara-ve-canvas' );
	var clip = document.getElementById( 'clara-ve-clip' );
	var shell = document.getElementById( 'clara-ve-shell' );
	var deviceBtns = document.querySelectorAll( '.clara-ve-device' );
	var DEVICE_PRESETS = { desktop: null, tablet: { w: 820, h: 1180 }, mobile: { w: 390, h: 844 } };
	var currentDevice = 'desktop';

	function applyDevice( device ) {
		currentDevice = DEVICE_PRESETS.hasOwnProperty( device ) ? device : 'desktop';
		for ( var i = 0; i < deviceBtns.length; i++ ) {
			var on = deviceBtns[ i ].getAttribute( 'data-device' ) === currentDevice;
			deviceBtns[ i ].classList.toggle( 'is-active', on );
			deviceBtns[ i ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		}
		if ( ! canvas || ! clip || ! shell ) {
			return;
		}
		var preset = DEVICE_PRESETS[ currentDevice ];
		if ( ! preset ) {
			canvas.classList.remove( 'is-device', 'is-mobile' );
			clip.removeAttribute( 'style' );
			shell.removeAttribute( 'style' );
			return;
		}
		canvas.classList.add( 'is-device' );
		canvas.classList.toggle( 'is-mobile', currentDevice === 'mobile' );
		var pad = 48;
		var availW = Math.max( 1, canvas.clientWidth - pad );
		var availH = Math.max( 1, canvas.clientHeight - pad );
		var scale = Math.min( 1, availW / preset.w, availH / preset.h );
		shell.style.width = preset.w + 'px';
		shell.style.height = preset.h + 'px';
		shell.style.transform = 'scale(' + scale + ')';
		shell.style.transformOrigin = '0 0';
		clip.style.width = Math.round( preset.w * scale ) + 'px';
		clip.style.height = Math.round( preset.h * scale ) + 'px';
	}

	for ( var dv = 0; dv < deviceBtns.length; dv++ ) {
		deviceBtns[ dv ].addEventListener( 'click', function () {
			closePanelSilent(); // panel anchors to unscaled coords — close on switch
			applyDevice( this.getAttribute( 'data-device' ) );
		} );
	}
	window.addEventListener( 'resize', function () {
		if ( currentDevice !== 'desktop' ) {
			applyDevice( currentDevice );
		}
	} );

	var source = null;
	var patches = [];
	// Off by default for every page, on every site — the user must opt in via
	// the toggle button. Persists across page switches within this session
	// (see the 'ready' handler below, which is what actually re-applies this
	// to each freshly (re)loaded iframe — bridge.js never assumes ON itself).
	var editModeOn = false;

	var FONT_OPTIONS = [
		'inherit',
		'Noto Serif Display',
		'Overpass',
		'DM Sans',
		'Caveat',
		'Georgia',
		'Times',
		'Arial',
		'Helvetica',
		'Inter',
		'Roboto',
		'monospace',
	];
	var FONT_FALLBACKS = {
		'Noto Serif Display': "'Noto Serif Display', Georgia, serif",
		Overpass: "'Overpass', 'Helvetica Neue', Arial, sans-serif",
		'DM Sans': "'DM Sans', 'Helvetica Neue', Arial, sans-serif",
		Caveat: "'Caveat', cursive",
		Georgia: 'Georgia, serif',
		Times: "'Times New Roman', Times, serif",
		Arial: 'Arial, sans-serif',
		Helvetica: "'Helvetica Neue', Helvetica, Arial, sans-serif",
		Inter: "'Inter', sans-serif",
		Roboto: "'Roboto', sans-serif",
		monospace: 'monospace',
	};

	// ---- Google fonts the owner has added (see includes/class-fonts.php) ----
	// They join the built-in list so every element's Font dropdown offers them,
	// and the same stylesheet the public site loads is mirrored into this
	// screen and the preview so what's chosen renders in its real face.

	var googleFonts = ( config.googleFonts || [] ).slice();

	function fontStack( entry ) {
		return "'" + entry.family + "', " + ( entry.category || 'sans-serif' );
	}

	function fontOptions() {
		// Added families sit right after 'inherit', where they're easiest to
		// find; the built-ins keep their existing order below.
		return [ FONT_OPTIONS[ 0 ] ]
			.concat( googleFonts.map( function ( g ) { return g.family; } ) )
			.concat( FONT_OPTIONS.slice( 1 ) );
	}

	function fontValue( name ) {
		if ( FONT_FALLBACKS[ name ] ) {
			return FONT_FALLBACKS[ name ];
		}
		for ( var i = 0; i < googleFonts.length; i++ ) {
			if ( googleFonts[ i ].family === name ) {
				return fontStack( googleFonts[ i ] );
			}
		}
		return name;
	}

	// One <link> per document, replaced whenever the kept set changes. Applied
	// to the editor screen (panel previews) and, same-origin, to the preview
	// iframe — so an added font is usable immediately, without a reload.
	function applyGoogleFontCss( url, doc ) {
		try {
			var d = doc || document;
			if ( ! d ) {
				return;
			}
			var link = d.getElementById( 'clara-ve-google-fonts-css' );
			if ( ! url ) {
				if ( link ) {
					link.remove();
				}
				return;
			}
			if ( ! link ) {
				link = d.createElement( 'link' );
				link.id = 'clara-ve-google-fonts-css';
				link.rel = 'stylesheet';
				( d.head || d.documentElement ).appendChild( link );
			}
			if ( link.href !== url ) {
				link.href = url;
			}
		} catch ( e ) {
			// Cross-origin frame — the server-side enqueue covers it on reload.
		}
	}

	function refreshGoogleFontCss( url ) {
		applyGoogleFontCss( url, document );
		applyGoogleFontCss( url, frame.contentDocument );
	}

	refreshGoogleFontCss( config.googleFontsCss || '' );

	window.wp.apiFetch( { path: '/clara-ve/v1/source?key=' + encodeURIComponent( currentKey ) } ).then( function ( res ) {
		source = res.source;
	} );

	function setDirty() {
		saveBtn.disabled = patches.length === 0;
		discardBtn.disabled = patches.length === 0;
		statusEl.textContent = patches.length ? patches.length + ' unsaved change' + ( patches.length > 1 ? 's' : '' ) : '';
	}

	function postToFrame( message ) {
		frame.contentWindow.postMessage( Object.assign( { ns: 'clara-ve' }, message ), '*' );
	}

	// ---- Source patching (port of open-design source-patches.ts) ----

	function findByPath( doc, id ) {
		if ( ! id || id.indexOf( 'path-' ) !== 0 ) {
			return null;
		}
		var indexes = id.slice( 5 ).split( '-' ).map( Number );
		var node = doc.body;
		for ( var i = 0; i < indexes.length; i++ ) {
			if ( ! node || ! Number.isInteger( indexes[ i ] ) || indexes[ i ] < 0 ) {
				return null;
			}
			node = node.children[ indexes[ i ] ] || null;
		}
		return node;
	}

	/**
	 * Remove the [wp-form]…[/wp-form] text either side of an element, leaving
	 * any other text in those nodes (indentation, a stray comment) intact.
	 */
	function stripFormToken( el ) {
		var before = el.previousSibling;
		if ( before && 3 === before.nodeType && FORM_OPEN_RE.test( before.nodeValue ) ) {
			before.nodeValue = before.nodeValue.replace( FORM_OPEN_RE, '' );
			if ( '' === before.nodeValue.trim() ) {
				before.parentNode.removeChild( before );
			}
		}
		var after = el.nextSibling;
		if ( after && 3 === after.nodeType && FORM_CLOSE_RE.test( after.nodeValue ) ) {
			after.nodeValue = after.nodeValue.replace( FORM_CLOSE_RE, '' );
			if ( '' === after.nodeValue.trim() ) {
				after.parentNode.removeChild( after );
			}
		}
	}

	// ---- Custom Content Collections: host-side helpers for set-collection-list ----
	// Mirror bridge.js's shapeSignature()/resolveCollectionSlotNode() exactly —
	// two independent copies (this frame vs. the preview iframe), same rule,
	// because patch.shape was computed by the bridge and must match here for
	// membership re-derivation to find the same elements.

	function collectionShapeSignature( el ) {
		var classes = el.className && 'string' === typeof el.className ? el.className.trim() : '';
		var sortedClasses = classes ? classes.split( /\s+/ ).sort().join( ',' ) : '';
		var childTags = [];
		for ( var i = 0; i < el.children.length; i++ ) {
			childTags.push( el.children[ i ].tagName.toLowerCase() );
		}
		return el.tagName.toLowerCase() + '|' + sortedClasses + '|' + childTags.join( ',' );
	}

	function resolveCollectionSlotPath( root, path ) {
		var node = root;
		for ( var i = 0; i < path.length; i++ ) {
			if ( ! node || ! node.children ) {
				return null;
			}
			node = node.children[ path[ i ] ];
		}
		return node || null;
	}

	/**
	 * Saved-source twin of applyImageLink() in bridge.js — the preview shows
	 * the change, this writes it. Kept in step with that function: a plain <a>
	 * with no styling (an inline wrapper leaves percentage heights resolving
	 * against the same box, so .frame img{height:100%} and its kin are
	 * untouched), a wrapper the design shipped is reused and never taken
	 * apart, and only one this plugin added is removed again.
	 */
	function applyImageLinkToDoc( doc, img, href, linkTarget ) {
		var parent = img.parentNode;
		var anchor = parent && 'A' === parent.tagName ? parent : null;
		href = typeof href === 'string' ? href.trim() : '';

		if ( ! href ) {
			if ( anchor && anchor.hasAttribute( 'data-cve-link' ) ) {
				anchor.parentNode.insertBefore( img, anchor );
				anchor.remove();
			} else if ( anchor ) {
				anchor.removeAttribute( 'href' );
				anchor.removeAttribute( 'target' );
				anchor.removeAttribute( 'rel' );
			}
			return;
		}

		if ( ! anchor ) {
			anchor = doc.createElement( 'a' );
			anchor.setAttribute( 'data-cve-link', '1' );
			parent.insertBefore( anchor, img );
			anchor.appendChild( img );
		}
		anchor.setAttribute( 'href', href );
		if ( '_blank' === linkTarget ) {
			anchor.setAttribute( 'target', '_blank' );
			anchor.setAttribute( 'rel', 'noopener noreferrer' );
		} else {
			anchor.removeAttribute( 'target' );
			if ( 'noopener noreferrer' === ( anchor.getAttribute( 'rel' ) || '' ) ) {
				anchor.removeAttribute( 'rel' );
			}
		}
	}

	function applyPatch( doc, patch ) {
		var el = findByPath( doc, patch.id );
		if ( ! el ) {
			return { ok: false, error: 'Target not found: ' + patch.id };
		}
		if ( patch.kind === 'set-text' ) {
			if ( el.children.length > 0 ) {
				return { ok: false, error: 'Element has nested markup.' };
			}
			el.textContent = patch.value;
		} else if ( patch.kind === 'set-inner-html' ) {
			el.innerHTML = patch.value;
		} else if ( patch.kind === 'set-link' ) {
			el.setAttribute( 'href', patch.href );
			if ( patch.target ) {
				el.setAttribute( 'target', patch.target );
				el.setAttribute( 'rel', 'noopener noreferrer' );
			} else {
				el.removeAttribute( 'target' );
				if ( ( el.getAttribute( 'rel' ) || '' ) === 'noopener noreferrer' ) {
					el.removeAttribute( 'rel' );
				}
			}
		} else if ( patch.kind === 'set-form-token' ) {
			// Always strip first, so switching type replaces the token rather
			// than nesting a second one around the first.
			stripFormToken( el );
			if ( patch.atts ) {
				var parent = el.parentNode;
				parent.insertBefore( doc.createTextNode( '\n' + patch.open + '\n' ), el );
				parent.insertBefore( doc.createTextNode( '\n[/wp-form]\n' ), el.nextSibling );
			}
		} else if ( patch.kind === 'set-image' ) {
			el.setAttribute( 'src', patch.src );
			if ( typeof patch.alt === 'string' ) {
				el.setAttribute( 'alt', patch.alt );
			}
			// Where the picture leads travels on this same patch rather than a
			// second one of its own. Wrapping the image in a link makes its own
			// path one level deeper, so a later patch addressing the old path
			// would land on the wrapper instead; keeping both in one patch —
			// recordPatch holds a single one per element and kind — means the
			// path is only ever resolved before anything has moved.
			if ( typeof patch.link === 'string' ) {
				applyImageLinkToDoc( doc, el, patch.link, patch.linkTarget );
			}
		} else if ( patch.kind === 'set-video' ) {
			if ( typeof patch.poster === 'string' ) {
				if ( patch.poster ) {
					el.setAttribute( 'poster', patch.poster );
				} else {
					el.removeAttribute( 'poster' );
				}
			}
			if ( Array.isArray( patch.sources ) ) {
				// Normalize away from a direct src= attribute (if the original
				// markup used one) so <source> children are the single source of
				// truth. Only <source> children are replaced — <track> children
				// (captions/subtitles) are left untouched.
				el.removeAttribute( 'src' );
				Array.prototype.slice.call( el.querySelectorAll( 'source' ) ).forEach( function ( s ) {
					s.remove();
				} );
				var frag = doc.createDocumentFragment();
				patch.sources.forEach( function ( s ) {
					if ( ! s.src ) {
						return;
					}
					var se = doc.createElement( 'source' );
					se.setAttribute( 'src', s.src );
					if ( s.type ) {
						se.setAttribute( 'type', s.type );
					}
					if ( s.media ) {
						se.setAttribute( 'media', s.media );
					}
					frag.appendChild( se );
				} );
				el.insertBefore( frag, el.firstChild ); // sources before any <track>
			}
		} else if ( patch.kind === 'convert-to-video' ) {
			// Video replacement, source-side: swap the <img> for a
			// real <video> at the same tree position — mirrors bridge.js's
			// live-DOM version of this same conversion exactly.
			var video = doc.createElement( 'video' );
			if ( el.className ) {
				video.className = el.className;
			}
			if ( el.getAttribute( 'style' ) ) {
				video.setAttribute( 'style', el.getAttribute( 'style' ) );
			}
			video.setAttribute( 'controls', '' );
			video.setAttribute( 'preload', 'metadata' );
			video.setAttribute( 'muted', '' ); // silent by policy — mirrors bridge.js

			// Force the box the replaced image occupied (see mediaBoxStyle) so
			// the saved markup keeps the layout — img-targeted sizing CSS won't
			// apply to <video>. Applied after the copied style attr so it wins.
			if ( patch.boxStyle ) {
				Object.keys( patch.boxStyle ).forEach( function ( prop ) {
					video.style.setProperty( prop, patch.boxStyle[ prop ] );
				} );
			}

			if ( patch.poster ) {
				video.setAttribute( 'poster', patch.poster );
			}
			( patch.sources || [] ).forEach( function ( s ) {
				if ( ! s.src ) {
					return;
				}
				var se = doc.createElement( 'source' );
				se.setAttribute( 'src', s.src );
				if ( s.type ) {
					se.setAttribute( 'type', s.type );
				}
				video.appendChild( se );
			} );
			el.replaceWith( video );
		} else if ( patch.kind === 'convert-to-image' ) {
			// The reverse of convert-to-video, source-side: swap a <video> back
			// for an <img> at the same tree position — mirrors bridge.js.
			var newImg = doc.createElement( 'img' );
			if ( el.className ) {
				newImg.className = el.className;
			}
			newImg.classList.remove( 'scroll-video' ); // an image can't scroll-play.
			if ( el.getAttribute( 'style' ) ) {
				newImg.setAttribute( 'style', el.getAttribute( 'style' ) );
			}
			// Keep the box the video occupied (see mediaBoxStyle) — video-
			// targeted sizing CSS won't apply to <img>. After the copied style.
			if ( patch.boxStyle ) {
				Object.keys( patch.boxStyle ).forEach( function ( prop ) {
					newImg.style.setProperty( prop, patch.boxStyle[ prop ] );
				} );
			}
			newImg.setAttribute( 'src', patch.src );
			if ( typeof patch.alt === 'string' ) {
				newImg.setAttribute( 'alt', patch.alt );
			}
			el.replaceWith( newImg );
		} else if ( patch.kind === 'set-scroll-video' ) {
			// Mirrors bridge.js applyScrollVideo, source-side: the `scroll-video`
			// class + muted/playsinline/preload and no controls (the plugin's
			// front-end script plays it once on scroll), or an ordinary
			// controllable video when off.
			if ( patch.enabled ) {
				el.classList.add( 'scroll-video' );
				el.setAttribute( 'muted', '' );
				el.setAttribute( 'playsinline', '' );
				el.setAttribute( 'preload', 'auto' );
				el.removeAttribute( 'controls' );
				el.removeAttribute( 'loop' );
				el.removeAttribute( 'autoplay' );
			} else {
				el.classList.remove( 'scroll-video' );
				el.setAttribute( 'controls', '' );
			}
		} else if ( patch.kind === 'set-style' ) {
			Object.keys( patch.styles || {} ).forEach( function ( key ) {
				var cssName = key.replace( /[A-Z]/g, function ( m ) {
					return '-' + m.toLowerCase();
				} );
				var value = patch.styles[ key ];
				if ( typeof value !== 'string' || value.trim() === '' ) {
					el.style.removeProperty( cssName );
				} else {
					el.style.setProperty( cssName, value.trim() );
				}
			} );
		} else if ( patch.kind === 'remove-element' ) {
			if ( ! el.parentElement ) {
				return { ok: false, error: 'Cannot remove the root element.' };
			}
			el.remove();
		} else if ( patch.kind === 'set-faq-list' ) {
			// Rebuild the whole list of questions in one go.
			//
			// One patch rather than a patch per edit, because the operations the
			// panel offers do not compose: reordering, inserting and deleting all
			// renumber the positional paths that every OTHER pending patch is
			// holding. Expressing the end state once sidesteps that entirely —
			// there is nothing to apply in the right order because there is only
			// one thing to apply.
			//
			// Every row is built by CLONING an element that is already on the
			// page, never by generating markup: a row that maps to an existing
			// question clones that question, so per-item differences survive
			// reordering, and a new row clones the first one. Nothing here
			// invents a class name or a structure.
			var originals = [];
			for ( var c = 0; c < el.children.length; c++ ) {
				if ( el.children[ c ].querySelector( patch.question ) ) {
					originals.push( el.children[ c ] );
				}
			}
			if ( ! originals.length ) {
				return { ok: false, error: 'No questions found to rebuild.' };
			}
			var built = [];
			for ( var r = 0; r < patch.rows.length; r++ ) {
				var row  = patch.rows[ r ];
				var from = ( row.from >= 0 && originals[ row.from ] ) ? originals[ row.from ] : originals[ 0 ];
				var made = from.cloneNode( true );
				made.removeAttribute( 'data-cve-path' );
				var inner = made.querySelectorAll( '[data-cve-path]' );
				for ( var q = 0; q < inner.length; q++ ) {
					inner[ q ].removeAttribute( 'data-cve-path' );
				}
				if ( 'DETAILS' === made.tagName ) {
					made.removeAttribute( 'open' );
				}
				var qSlot = made.querySelector( patch.question );
				if ( qSlot ) {
					qSlot.textContent = row.question;
				}
				var aSlot = patch.answer ? made.querySelector( patch.answer ) : null;
				if ( aSlot ) {
					aSlot.textContent = row.answer;
				}
				built.push( made );
			}
			// Only the question elements are replaced; anything else the list
			// holds stays exactly where it was.
			var anchorNode = originals[ 0 ];
			for ( var o = 0; o < originals.length; o++ ) {
				if ( originals[ o ] !== anchorNode ) {
					originals[ o ].remove();
				}
			}
			for ( var b = 0; b < built.length; b++ ) {
				anchorNode.parentNode.insertBefore( built[ b ], anchorNode );
			}
			anchorNode.remove();
		} else if ( patch.kind === 'set-collection-list' ) {
			// The generic form of set-faq-list above — same one-atomic-patch,
			// clone-existing-nodes approach (see that branch's comment for why),
			// generalized from a fixed question/answer pair to an arbitrary
			// per-item field schema.
			//
			// Slots are addressed by POSITIONAL child-index path from the item
			// root, never a selector: unlike FAQ's single <summary>, a generic
			// card commonly has more than one slot sharing a tag (e.g. two <p>
			// elements — price, description), and "the first thing matching a
			// selector" would silently write the wrong one.
			var origItems = [];
			for ( var cc = 0; cc < el.children.length; cc++ ) {
				if ( collectionShapeSignature( el.children[ cc ] ) === patch.shape ) {
					origItems.push( el.children[ cc ] );
				}
			}
			if ( ! origItems.length ) {
				return { ok: false, error: 'No items found to rebuild.' };
			}
			var builtItems = [];
			for ( var rr = 0; rr < patch.rows.length; rr++ ) {
				var itemRow = patch.rows[ rr ];
				var fromEl = ( itemRow.from >= 0 && origItems[ itemRow.from ] ) ? origItems[ itemRow.from ] : origItems[ 0 ];
				var madeItem = fromEl.cloneNode( true );
				madeItem.removeAttribute( 'data-cve-path' );
				var innerPaths = madeItem.querySelectorAll( '[data-cve-path]' );
				for ( var pp = 0; pp < innerPaths.length; pp++ ) {
					innerPaths[ pp ].removeAttribute( 'data-cve-path' );
				}
				( patch.slotSchema || [] ).forEach( function ( slot, si ) {
					var slotNode = resolveCollectionSlotPath( madeItem, slot.path );
					if ( ! slotNode ) {
						return;
					}
					var v = itemRow.values ? itemRow.values[ si ] : null;
					if ( 'image' === slot.type ) {
						if ( v && 'object' === typeof v ) {
							if ( null != v.src ) { slotNode.setAttribute( 'src', v.src ); }
							if ( null != v.alt ) { slotNode.setAttribute( 'alt', v.alt ); }
						}
					} else if ( 'link' === slot.type ) {
						if ( v && 'object' === typeof v ) {
							if ( null != v.text ) { slotNode.textContent = v.text; }
							if ( null != v.href ) { slotNode.setAttribute( 'href', v.href ); }
						}
					} else {
						slotNode.textContent = null == v ? '' : v;
					}
				} );
				builtItems.push( madeItem );
			}
			var anchorItem = origItems[ 0 ];
			for ( var oo = 0; oo < origItems.length; oo++ ) {
				if ( origItems[ oo ] !== anchorItem ) {
					origItems[ oo ].remove();
				}
			}
			for ( var bb = 0; bb < builtItems.length; bb++ ) {
				anchorItem.parentNode.insertBefore( builtItems[ bb ], anchorItem );
			}
			anchorItem.remove();
		}
		return { ok: true };
	}

	function patchedSource() {
		var doc = new DOMParser().parseFromString( '<body>' + source + '</body>', 'text/html' );
		// Anything that changes how many siblings there are goes last, and among
		// themselves deepest-path first. Removing an element shifts every later
		// sibling DOWN an index; inserting one shifts them UP — either way a path
		// resolved afterwards points at the wrong element. Processing them in
		// reverse path order means each target is still where its path says it is
		// at the moment it is used, because nothing before it has moved yet.
		var STRUCTURAL = { 'remove-element': 1, 'set-faq-list': 1, 'set-collection-list': 1 };
		var ordered = patches
			.filter( function ( p ) {
				return ! STRUCTURAL[ p.kind ] && p.kind !== 'set-pseudo';
			} )
			.concat(
				patches
					.filter( function ( p ) {
						return !! STRUCTURAL[ p.kind ];
					} )
					.sort( function ( a, b ) {
						return b.id.localeCompare( a.id, undefined, { numeric: true } );
					} )
			);
		for ( var i = 0; i < ordered.length; i++ ) {
			var result = applyPatch( doc, ordered[ i ] );
			if ( ! result.ok ) {
				return { ok: false, error: result.error };
			}
		}
		return { ok: true, source: doc.body.innerHTML };
	}

	// ---- Questions editor (a second popup, over the element panel) ----
	//
	// The element panel edits ONE thing — the text you clicked. Questions are not
	// one thing: an answer lives inside a collapsed <details> that cannot be
	// clicked while it is shut, and the order of the set is a property of no
	// single item. Doing this with buttons on the element panel meant a save and
	// a canvas reload per question, which threw the page back to the top after
	// every edit. So the whole list gets its own window, everything is edited in
	// it at once, and it is written back one time.

	var faqEditor = null;

	function closeFaqEditor() {
		if ( faqEditor ) {
			faqEditor.remove();
			faqEditor = null;
		}
	}

	/**
	 * @param {Object} unit The `faq` descriptor from bridge.js's select payload.
	 */
	function openFaqEditor( unit ) {
		closeFaqEditor();

		// Working copy. `from` remembers which original each row was built out
		// of, so reordering keeps each question's own markup with it instead of
		// stamping the first item's over everything.
		var rows = unit.items.map( function ( item, i ) {
			return { from: i, question: item.question, answer: item.answer };
		} );

		var box = el( 'div', 'cve-faq-modal' );

		// Same header furniture as the element panel — grip, title, ✕ — and the
		// same makeDraggable(), which already solves the part that is not
		// obvious: an iframe swallows mouse events, so a drag whose cursor
		// crosses the preview would freeze without its pointer-events trick.
		var head = el( 'div', 'cve-faq-head' );
		head.appendChild( el( 'span', 'cve-grip', '⠿' ) );
		head.appendChild( el( 'strong', 'cve-title', 'Questions' ) );
		var close = el( 'button', 'cve-close', '✕' );
		close.type = 'button';
		close.title = 'Close without saving';
		close.addEventListener( 'click', closeFaqEditor );
		head.appendChild( close );
		box.appendChild( head );

		var list = el( 'div', 'cve-faq-rows' );
		box.appendChild( list );

		function render() {
			list.innerHTML = '';
			rows.forEach( function ( row, i ) {
				var item = el( 'div', 'cve-faq-row' );

				var bar = el( 'div', 'cve-faq-bar' );
				bar.appendChild( el( 'span', 'cve-faq-num', String( i + 1 ) ) );

				var up = el( 'button', 'cve-icon', '↑' );
				up.type = 'button';
				up.title = 'Move up';
				up.disabled = 0 === i;
				up.addEventListener( 'click', function () {
					rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] );
					render();
				} );

				var down = el( 'button', 'cve-icon', '↓' );
				down.type = 'button';
				down.title = 'Move down';
				down.disabled = i === rows.length - 1;
				down.addEventListener( 'click', function () {
					rows.splice( i + 1, 0, rows.splice( i, 1 )[ 0 ] );
					render();
				} );

				var del = el( 'button', 'cve-icon', '🗑' );
				del.type = 'button';
				del.title = 'Remove this question and its answer';
				del.addEventListener( 'click', function () {
					rows.splice( i, 1 );
					render();
				} );

				bar.appendChild( up );
				bar.appendChild( down );
				bar.appendChild( del );
				item.appendChild( bar );

				var q = el( 'input' );
				q.type = 'text';
				q.className = 'cve-faq-q';
				q.value = row.question;
				q.placeholder = 'The question';
				q.addEventListener( 'input', function () {
					row.question = q.value;
				} );
				item.appendChild( q );

				var a = el( 'textarea' );
				a.className = 'cve-faq-a';
				a.rows = 3;
				a.value = row.answer;
				a.placeholder = 'The answer';
				a.addEventListener( 'input', function () {
					row.answer = a.value;
				} );
				item.appendChild( a );

				list.appendChild( item );
			} );

			// Said plainly rather than enforced. Below two questions the set stops
			// being published as an FAQ at all, and that is a surprising thing to
			// discover later — but it is still the owner's page to arrange.
			warn.hidden = rows.length >= 2;
		}

		var warn = el(
			'p',
			'cve-faq-warn',
			'With fewer than two questions this stops being published as an FAQ, so it will no longer appear in search results or AI answers.'
		);

		var addBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block', '+ Add a question' );
		addBtn.type = 'button';
		addBtn.addEventListener( 'click', function () {
			rows.push( { from: -1, question: '', answer: '' } );
			render();
			var inputs = list.querySelectorAll( '.cve-faq-q' );
			if ( inputs.length ) {
				inputs[ inputs.length - 1 ].focus();
			}
		} );

		var foot = el( 'div', 'cve-faq-foot' );
		var cancel = el( 'button', 'cve-btn cve-btn-light', 'Cancel' );
		cancel.type = 'button';
		cancel.addEventListener( 'click', closeFaqEditor );
		var save = el( 'button', 'cve-btn cve-btn-save', 'Save questions' );
		save.type = 'button';
		save.addEventListener( 'click', function () {
			var clean = rows.filter( function ( r ) {
				return r.question.trim() !== '' || r.answer.trim() !== '';
			} );
			if ( ! clean.length ) {
				window.alert( 'Leave at least one question, or close this window to change nothing.' );
				return;
			}
			recordPatch( {
				id: unit.listPath,
				kind: 'set-faq-list',
				question: unit.question,
				answer: unit.answer,
				rows: clean.map( function ( r ) {
					return { from: r.from, question: r.question.trim(), answer: r.answer.trim() };
				} ),
			} );
			closeFaqEditor();
			closePanelSilent();

			// A brand new question is an element the site's own scripts have
			// never seen — this theme binds the accordion per <summary> at load,
			// so a fresh one would sit there refusing to open. That case needs
			// the page's scripts to run again, which means a reload. Rewording,
			// reordering and removing move or edit elements that already exist,
			// keep whatever is bound to them, and cost nothing.
			var hasNew = clean.some( function ( r ) {
				return r.from < 0;
			} );
			saveNow(
				'Questions saved',
				hasNew
					? null
					: function () {
						postToFrame( {
							type: 'faq-apply',
							id: unit.listPath,
							question: unit.question,
							answer: unit.answer,
							rows: clean.map( function ( r ) {
								return { from: r.from, question: r.question.trim(), answer: r.answer.trim() };
							} ),
						} );
					}
			);
		} );
		foot.appendChild( cancel );
		foot.appendChild( save );

		box.appendChild( warn );
		box.appendChild( addBtn );
		box.appendChild( foot );

		document.body.appendChild( box );
		faqEditor = box;
		// Centred by measurement rather than by transform: makeDraggable() writes
		// style.left/top from offsetLeft/offsetTop, and a translate(-50%,-50%)
		// would make the first drag jump by half the box.
		var host = document.body.getBoundingClientRect();
		box.style.left = Math.max( 12, Math.round( ( host.width - box.offsetWidth ) / 2 ) ) + 'px';
		box.style.top = Math.max( 12, Math.round( ( host.height - box.offsetHeight ) / 2 ) ) + 'px';
		makeDraggable( box, head );
		render();

		var firstQ = box.querySelector( '.cve-faq-q' );
		if ( firstQ ) {
			firstQ.focus();
		}
	}

	// ---- Custom Content Collections editor (a second popup, generalizing
	// the questions editor above to any repeating card/list — service cards,
	// team members, portfolio tiles) ----
	//
	// Detection is bridge.js's collectionUnitFor(): a click-driven, generic
	// form of faqUnitFor() that finds any set of >=2 structurally congruent,
	// contiguous siblings and diffs out their varying leaf content as a
	// field schema (image / link / text-short / text-long). This popup is
	// where that schema becomes an editable row per item; saving reuses the
	// exact one-atomic-patch, clone-existing-nodes approach set-faq-list
	// already established (see that patch's comment for why it must be one
	// patch, not one per edit).

	var collectionEditor = null;
	var collectionMediaFrame = null;

	function closeCollectionEditor() {
		if ( collectionEditor ) {
			collectionEditor.remove();
			collectionEditor = null;
			postToFrame( { type: 'collection-unhighlight' } );
		}
	}

	function cloneCollectionValue( v ) {
		return v && 'object' === typeof v ? Object.assign( {}, v ) : v;
	}

	function trimCollectionValue( v ) {
		if ( v && 'object' === typeof v ) {
			var out = {};
			Object.keys( v ).forEach( function ( k ) {
				out[ k ] = 'string' === typeof v[ k ] ? v[ k ].trim() : v[ k ];
			} );
			return out;
		}
		return 'string' === typeof v ? v.trim() : v;
	}

	function collectionValueIsEmpty( v ) {
		if ( v && 'object' === typeof v ) {
			return ! ( v.src || v.alt || v.text || v.href );
		}
		return ! v || ! String( v ).trim();
	}

	function emptyCollectionValues( slotSchema ) {
		return slotSchema.map( function ( slot ) {
			if ( 'image' === slot.type ) {
				return { src: '', alt: '' };
			}
			if ( 'link' === slot.type ) {
				return { text: '', href: '' };
			}
			return '';
		} );
	}

	/**
	 * @param {Object} unit The `collection` descriptor from bridge.js's select payload.
	 */
	function openCollectionEditor( unit ) {
		closeCollectionEditor();

		// Working copy — `from` remembers which original each row was built
		// out of, exactly as the questions editor's rows do, so reordering
		// keeps each item's own (possibly opaque, unedited) markup with it.
		var rows = unit.items.map( function ( item, i ) {
			return { from: i, values: item.values.map( cloneCollectionValue ) };
		} );

		var box = el( 'div', 'cve-faq-modal cve-collection-modal' );

		var head = el( 'div', 'cve-faq-head' );
		head.appendChild( el( 'span', 'cve-grip', '⠿' ) );
		head.appendChild( el( 'strong', 'cve-title', 'Items' ) );
		var close = el( 'button', 'cve-close', '✕' );
		close.type = 'button';
		close.title = 'Close without saving';
		close.addEventListener( 'click', closeCollectionEditor );
		head.appendChild( close );
		box.appendChild( head );

		var list = el( 'div', 'cve-faq-rows cve-collection-rows' );
		box.appendChild( list );

		function fieldInput( slot, si, row ) {
			var wrap = el( 'div', 'cve-collection-field cve-collection-field--' + slot.type );
			if ( 'image' === slot.type ) {
				var val = row.values[ si ] || { src: '', alt: '' };
				if ( val.src ) {
					var thumb = el( 'img', 'cve-collection-thumb' );
					thumb.src = val.src;
					wrap.appendChild( thumb );
				}
				var pickBtn = el( 'button', 'cve-btn cve-btn-light', val.src ? 'Replace image' : 'Choose image' );
				pickBtn.type = 'button';
				pickBtn.addEventListener( 'click', function () {
					// A fresh frame per pick — simpler than rebinding one shared
					// frame's 'select' handler to whichever row was clicked.
					collectionMediaFrame = window.wp.media( {
						title: 'Image',
						library: { type: 'image' },
						button: { text: 'Use this image' },
						multiple: false,
					} );
					collectionMediaFrame.on( 'select', function () {
						var picked = collectionMediaFrame.state().get( 'selection' ).first();
						if ( picked ) {
							var prev = row.values[ si ] || {};
							row.values[ si ] = { src: picked.get( 'url' ), alt: prev.alt || '' };
							render();
						}
					} );
					collectionMediaFrame.open();
				} );
				wrap.appendChild( pickBtn );
			} else if ( 'link' === slot.type ) {
				var lv = row.values[ si ] || { text: '', href: '' };
				var textInput = el( 'input' );
				textInput.type = 'text';
				textInput.className = 'cve-faq-q';
				textInput.placeholder = 'Link text';
				textInput.value = lv.text || '';
				textInput.addEventListener( 'input', function () {
					var prev = row.values[ si ] || lv;
					row.values[ si ] = { text: textInput.value, href: prev.href || '' };
				} );
				var hrefInput = el( 'input' );
				hrefInput.type = 'text';
				hrefInput.className = 'cve-faq-q';
				hrefInput.placeholder = 'Link URL';
				hrefInput.value = lv.href || '';
				hrefInput.addEventListener( 'input', function () {
					var prev = row.values[ si ] || lv;
					row.values[ si ] = { text: prev.text || '', href: hrefInput.value };
				} );
				wrap.appendChild( textInput );
				wrap.appendChild( hrefInput );
			} else if ( 'text-long' === slot.type ) {
				var ta = el( 'textarea' );
				ta.className = 'cve-faq-a';
				ta.rows = 3;
				ta.value = row.values[ si ] || '';
				ta.placeholder = 'Text';
				ta.addEventListener( 'input', function () {
					row.values[ si ] = ta.value;
				} );
				wrap.appendChild( ta );
			} else {
				var inp = el( 'input' );
				inp.type = 'text';
				inp.className = 'cve-faq-q';
				inp.value = row.values[ si ] || '';
				inp.placeholder = 'Text';
				inp.addEventListener( 'input', function () {
					row.values[ si ] = inp.value;
				} );
				wrap.appendChild( inp );
			}
			return wrap;
		}

		function render() {
			list.innerHTML = '';
			rows.forEach( function ( row, i ) {
				var item = el( 'div', 'cve-faq-row cve-collection-row' );

				var bar = el( 'div', 'cve-faq-bar' );
				bar.appendChild( el( 'span', 'cve-faq-num', String( i + 1 ) ) );

				var up = el( 'button', 'cve-icon', '↑' );
				up.type = 'button';
				up.title = 'Move up';
				up.disabled = 0 === i;
				up.addEventListener( 'click', function () {
					rows.splice( i - 1, 0, rows.splice( i, 1 )[ 0 ] );
					render();
				} );

				var down = el( 'button', 'cve-icon', '↓' );
				down.type = 'button';
				down.title = 'Move down';
				down.disabled = i === rows.length - 1;
				down.addEventListener( 'click', function () {
					rows.splice( i + 1, 0, rows.splice( i, 1 )[ 0 ] );
					render();
				} );

				var del = el( 'button', 'cve-icon', '🗑' );
				del.type = 'button';
				del.title = 'Remove this item';
				del.addEventListener( 'click', function () {
					rows.splice( i, 1 );
					render();
				} );

				bar.appendChild( up );
				bar.appendChild( down );
				bar.appendChild( del );
				item.appendChild( bar );

				var fields = el( 'div', 'cve-collection-fields' );
				unit.slotSchema.forEach( function ( slot, si ) {
					fields.appendChild( fieldInput( slot, si, row ) );
				} );
				item.appendChild( fields );

				list.appendChild( item );
			} );
		}

		var addBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block', '+ Add item' );
		addBtn.type = 'button';
		addBtn.addEventListener( 'click', function () {
			rows.push( { from: -1, values: emptyCollectionValues( unit.slotSchema ) } );
			render();
		} );

		var foot = el( 'div', 'cve-faq-foot' );
		var cancel = el( 'button', 'cve-btn cve-btn-light', 'Cancel' );
		cancel.type = 'button';
		cancel.addEventListener( 'click', closeCollectionEditor );
		var save = el( 'button', 'cve-btn cve-btn-save', 'Save items' );
		save.type = 'button';
		save.addEventListener( 'click', function () {
			var clean = rows.filter( function ( r ) {
				return ! r.values.every( collectionValueIsEmpty );
			} );
			if ( ! clean.length ) {
				window.alert( 'Leave at least one item, or close this window to change nothing.' );
				return;
			}
			var patchRows = clean.map( function ( r ) {
				return { from: r.from, values: r.values.map( trimCollectionValue ) };
			} );
			recordPatch( {
				id: unit.listPath,
				kind: 'set-collection-list',
				shape: unit.shape,
				slotSchema: unit.slotSchema,
				rows: patchRows,
			} );
			closeCollectionEditor();
			closePanelSilent();

			// Same rule as the questions editor: a brand-new item is an element
			// nothing on the page has ever bound behaviour to and has no
			// source-index yet, so that case needs a reload. Reordering,
			// rewording and removing move or edit elements that already exist.
			var hasNew = clean.some( function ( r ) {
				return r.from < 0;
			} );
			saveNow(
				'Items saved',
				hasNew
					? null
					: function () {
						postToFrame( {
							type: 'collection-apply',
							id: unit.listPath,
							shape: unit.shape,
							slotSchema: unit.slotSchema,
							rows: patchRows,
						} );
					}
			);
		} );
		foot.appendChild( cancel );
		foot.appendChild( save );

		box.appendChild( addBtn );
		box.appendChild( foot );

		document.body.appendChild( box );
		collectionEditor = box;
		// Rendered BEFORE measuring for center position, unlike the questions
		// editor above: a row here can hold six fields (an image picker, three
		// text inputs, a textarea, a two-part link) against FAQ's fixed two,
		// so the box's pre-render height (head only) is a poor stand-in for
		// its actual, CSS-capped height once rows exist — centering on the
		// smaller number left the footer's Save button below the viewport.
		render();
		var host = document.body.getBoundingClientRect();
		box.style.left = Math.max( 12, Math.round( ( host.width - box.offsetWidth ) / 2 ) ) + 'px';
		box.style.top = Math.max( 12, Math.round( ( host.height - box.offsetHeight ) / 2 ) ) + 'px';
		makeDraggable( box, head );

		// Show the owner exactly which elements "this list" refers to before
		// anything can be saved — see the CSS comment on [data-cve-collection-hl].
		postToFrame( { type: 'collection-highlight', id: unit.listPath, shape: unit.shape } );
	}

	/**
	 * Commit the pending patches, then refresh just the container that changed.
	 *
	 * For structural edits — adding, removing or reordering whole elements.
	 * Those cannot simply be queued like a text change: the positional paths the
	 * panel holds are indices into the SOURCE, and a change in how many siblings
	 * exist makes them describe a different document than the one on screen.
	 *
	 * The first version of this reloaded the whole frame to make them agree
	 * again. It worked and it was the wrong trade: reloading throws the reader
	 * back to the top of the page, so editing the questions near the foot of a
	 * long front page meant scrolling back down after every save. And restoring
	 * the scroll afterwards only papers over it — the position still visibly
	 * moves and comes back.
	 *
	 * Nothing needs to move. The save already produced the authoritative markup,
	 * so the frame is handed exactly that for the one container involved and
	 * re-stamps it in place. Ordering matters and is the whole safety argument:
	 * the DOM is only touched AFTER the server has the new source, so the
	 * stamps, the source and the server never disagree.
	 *
	 * @param {string}   label      Status message on success.
	 * @param {Function} [inPlace]  Refreshes the canvas without a reload. Called
	 *                              only after the save succeeds. Omit when the
	 *                              change cannot be reflected without re-running
	 *                              the page's own scripts.
	 */
	function saveNow( label, inPlace ) {
		var result = patchedSource();
		if ( ! result.ok ) {
			statusEl.textContent = 'Error: ' + result.error;
			return;
		}

		statusEl.textContent = 'Saving…';
		window.wp
			.apiFetch( { path: '/clara-ve/v1/source', method: 'POST', data: { key: currentKey, source: result.source, pseudo: [] } } )
			.then( function () {
				source = result.source;
				patches = [];
				setDirty();
				statusEl.textContent = label + ' ✓';
				if ( inPlace ) {
					inPlace();
				} else {
					// Nothing can refresh this in place, so the page is reloaded
					// and its own scripts run again. Costs the scroll position,
					// which the bridge then restores — visible, but correct, and
					// better than leaving an element on screen that the site's
					// own behaviour is not attached to.
					currentFrameUrl = '';
					frame.src = reloadUrlForCurrentKey();
				}
				if ( historyPanel && historyPanel.classList.contains( 'is-open' ) ) {
					loadHistory();
				}
				window.setTimeout( function () {
					if ( statusEl.textContent === label + ' ✓' ) {
						statusEl.textContent = '';
					}
				}, 2500 );
			} )
			.catch( function ( err ) {
				statusEl.textContent = 'Save failed: ' + ( err.message || 'unknown' );
				setDirty();
			} );
	}

	// ---- [wp-form] token, read and written from the panel ----
	// The token is TEXT in the source, sitting either side of the <form>
	// element. That is what makes connecting a form safe to do by clicking:
	// text nodes don't occupy a child index, so wrapping or unwrapping a form
	// leaves every other element's path exactly where it was.

	var FORM_OPEN_RE = /\[wp-form\b[^\]]*\]\s*$/;
	var FORM_CLOSE_RE = /^\s*\[\/wp-form\]/;

	/**
	 * The token wrapping the element at this path, as an attribute map, or null
	 * when the form is not connected. Read from the STORED source rather than
	 * the preview, which shows the token already hydrated away.
	 */
	function formTokenAt( id ) {
		var doc = new DOMParser().parseFromString( '<body>' + source + '</body>', 'text/html' );
		var el = findByPath( doc, id );
		if ( ! el ) {
			return null;
		}
		var before = el.previousSibling;
		if ( ! before || 3 !== before.nodeType ) {
			return null;
		}
		var open = before.nodeValue.match( /\[wp-form\b([^\]]*)\]\s*$/ );
		if ( ! open ) {
			return null;
		}
		var atts = {};
		var re = /(\w+)\s*=\s*"([^"]*)"/g;
		var m;
		while ( ( m = re.exec( open[ 1 ] ) ) ) {
			atts[ m[ 1 ] ] = m[ 2 ];
		}
		return atts;
	}

	function formTokenString( atts ) {
		var out = '[wp-form';
		Object.keys( atts ).forEach( function ( k ) {
			if ( '' !== atts[ k ] && null !== atts[ k ] && undefined !== atts[ k ] ) {
				out += ' ' + k + '="' + String( atts[ k ] ).replace( /"/g, '' ) + '"';
			}
		} );
		return out + ']';
	}

	/**
	 * The FORM section: what this form does when someone submits it. Appended
	 * to the ordinary panel for a bare <form>, and to the zone panel for one
	 * already connected — the token hydrates into a zone, and a zone panel that
	 * only said "managed by WordPress" would leave no way to change or undo the
	 * connection that was made by clicking in the first place.
	 */
	function pagePath( url ) {
		try {
			var u = new URL( url, window.location.origin );
			return u.pathname;
		} catch ( e ) {
			return url;
		}
	}

	function appendFormSection( panel, formPath ) {
			// looks, so its section comes first. Connecting one writes a [wp-form]
			// token around it in the source — the design's own markup is never
			// touched, which is the whole reason a hand-built form can be wired up
			// by clicking instead of rebuilt in someone else's form plugin.
			var atts = formTokenAt( formPath ) || {};
			var type = atts.type ? atts.type : ( Object.keys( atts ).length ? 'contact' : 'none' );

			panel.appendChild( el( 'div', 'cve-section', 'FORM' ) );

			var formNote = el( 'p', 'cve-note', '' );
			panel.appendChild( formNote );

			var fields = el( 'div', 'cve-form-fields' );

			var write = function () {
				if ( 'none' === type ) {
					recordPatch( { id: formPath, kind: 'set-form-token', atts: null } );
					return;
				}
				atts.type = type;
				if ( ! atts.id ) {
					// A stable id per form: submissions are grouped by it, so it
					// must not change when the type or the redirect does.
					atts.id = 'form-' + formPath.replace( /^path-/, '' );
				}
				recordPatch( {
					id: formPath,
					kind: 'set-form-token',
					atts: atts,
					open: formTokenString( atts ),
				} );
			};

			// A page picker, not a text field: the destination is one of this site's
		// own pages, and a typo here is a form that silently ends on a 404 —
		// which is exactly what the free-text version produced the first time
		// this was tried (/form-submitted/ did not exist as a page at all).
		// "Somewhere else" keeps the escape hatch for an external URL.
		var redirectRow = function () {
			var row = el( 'div', 'cve-field' );
			row.appendChild( el( 'span', 'cve-field-label', 'Then go to' ) );
			var wrap = el( 'div', 'cve-field-stack' );
			var select = document.createElement( 'select' );
			select.className = 'cve-select';
			var custom = document.createElement( 'input' );
			custom.type = 'text';
			custom.className = 'cve-text';
			custom.placeholder = 'https://…';

			// The shared chrome keys are in the page list because they are
			// editable, not because they are places — the header is not
			// somewhere a form can send anyone.
			var NOT_A_DESTINATION = { header: 1, footer: 1, article: 1 };
			var options = [ [ '', 'Stay on this page' ] ].concat(
				visualPages
					.filter( function ( page ) {
						return ! NOT_A_DESTINATION[ page.key ];
					} )
					.map( function ( page ) {
						return [ pagePath( page.url ), page.label ];
					} )
			);
			var known = options.some( function ( o ) {
				return o[ 0 ] === ( atts.redirect || '' );
			} );
			options.push( [ '__custom', 'Somewhere else…' ] );
			options.forEach( function ( pair ) {
				var o = document.createElement( 'option' );
				o.value = pair[ 0 ];
				o.textContent = pair[ 1 ];
				select.appendChild( o );
			} );
			select.value = known ? ( atts.redirect || '' ) : '__custom';
			custom.value = known ? '' : ( atts.redirect || '' );
			custom.style.display = known ? 'none' : '';

			select.addEventListener( 'change', function () {
				if ( '__custom' === select.value ) {
					custom.style.display = '';
					custom.focus();
					return;
				}
				custom.style.display = 'none';
				atts.redirect = select.value;
				write();
			} );
			custom.addEventListener( 'change', function () {
				atts.redirect = custom.value.trim();
				write();
			} );

			wrap.appendChild( select );
			wrap.appendChild( custom );
			row.appendChild( wrap );
			return row;
		};

		// The provider's own lists, fetched once per panel. A text field here
		// would ask the owner to know a numeric list id, which is the sort of
		// thing this editor exists to stop asking.
		var listRow = function () {
			var row = el( 'div', 'cve-field' );
			row.appendChild( el( 'span', 'cve-field-label', 'List' ) );
			var select = document.createElement( 'select' );
			select.className = 'cve-select';
			var loading = document.createElement( 'option' );
			loading.textContent = 'Loading…';
			select.appendChild( loading );
			row.appendChild( select );

			window.wp
				.apiFetch( { path: '/clara-ve/v1/lists' } )
				.then( function ( data ) {
					select.innerHTML = '';
					if ( ! data.ready ) {
						formNote.textContent =
							'No mailing list provider is connected yet — open Form Settings and pick one. Until then this form has nowhere to put an address.';
						var none = document.createElement( 'option' );
						none.textContent = 'Not available';
						select.appendChild( none );
						select.disabled = true;
						return;
					}
					if ( data.error ) {
						formNote.textContent = data.error;
					}
					( data.lists || [] ).forEach( function ( list ) {
						var o = document.createElement( 'option' );
						o.value = list.id;
						o.textContent = list.name + ' (' + list.count + ')';
						select.appendChild( o );
					} );
					if ( atts.list ) {
						select.value = atts.list;
					} else if ( select.options.length ) {
						atts.list = select.value;
						write();
					}
				} )
				.catch( function ( err ) {
					select.innerHTML = '';
					formNote.textContent = 'Could not load lists: ' + ( ( err && err.message ) || 'error' );
				} );

			select.addEventListener( 'change', function () {
				atts.list = select.value;
				write();
			} );
			return row;
		};

		var render = function () {
				fields.innerHTML = '';
				if ( 'contact' === type ) {
					formNote.textContent = 'Submissions are stored under Form Submissions and emailed to you. The sender gets a confirmation.';
					// This box is a per-form OVERRIDE, not the address itself.
					// Empty means "use the site-wide one from Form Settings" —
					// which an empty box does not say, so the address that will
					// actually be used stands in it as the placeholder. Without
					// it the row reads as "this form goes nowhere".
					fields.appendChild(
						textRow( 'Send to', atts.to || '', function ( v ) {
							atts.to = v.trim();
							write();
						}, false, config.formRecipient || '' )
					);
					fields.appendChild( redirectRow() );
				} else if ( 'list' === type ) {
					formNote.textContent = 'The address is added to a mailing list at your provider, which sends the confirmation and the download.';
					fields.appendChild( listRow() );
					fields.appendChild( redirectRow() );
				} else {
					formNote.textContent = 'This form is not connected — submitting it does nothing. Pick what it should do.';
				}
			};

			var typeRow = el( 'div', 'cve-field' );
			typeRow.appendChild( el( 'span', 'cve-field-label', 'Does' ) );
			var typeSelect = document.createElement( 'select' );
			typeSelect.className = 'cve-select';
			[
				[ 'none', 'Nothing (not connected)' ],
				[ 'contact', 'Contact form' ],
				[ 'list', 'Mailing list' ],
			].forEach( function ( pair ) {
				var o = document.createElement( 'option' );
				o.value = pair[ 0 ];
				o.textContent = pair[ 1 ];
				typeSelect.appendChild( o );
			} );
			typeSelect.value = type;
			typeSelect.addEventListener( 'change', function () {
				type = typeSelect.value;
				render();
				write();
			} );
			typeRow.appendChild( typeSelect );
			panel.appendChild( typeRow );
			panel.appendChild( fields );
			render();
	}

	function recordPatch( patch ) {
		for ( var i = 0; i < patches.length; i++ ) {
			if (
				patches[ i ].id === patch.id &&
				patches[ i ].kind === patch.kind &&
				( patch.kind !== 'set-pseudo' || patches[ i ].pseudo === patch.pseudo )
			) {
				if ( patch.kind === 'set-style' || patch.kind === 'set-pseudo' ) {
					patch.styles = Object.assign( {}, patches[ i ].styles, patch.styles );
				}
				// Swapping the picture — from the Media Library, an
				// import — says nothing about where it points, so the address
				// already recorded carries over instead of being replaced by
				// nothing. Handled here rather than at each call site so a new
				// one cannot quietly drop the link again.
				if ( patch.kind === 'set-image' && typeof patch.link !== 'string' && typeof patches[ i ].link === 'string' ) {
					patch.link = patches[ i ].link;
					patch.linkTarget = patches[ i ].linkTarget;
				}
				patches[ i ] = patch;
				setDirty();
				return;
			}
		}
		patches.push( patch );
		setDirty();
	}

	// ---- Floating inspector (the open-design dark panel, minus Send to chat) ----

	var panel = null;
	var current = null; // selected target
	var pendingStyles = {};
	var pendingPseudo = {};

	function closePanel( revert ) {
		if ( panel ) {
			panel.remove();
			panel = null;
		}
		if ( revert && current && Object.keys( pendingStyles ).length ) {
			postToFrame( { type: 'revert-style', id: current.id } );
		}
		if ( revert && current && Object.keys( pendingPseudo ).length ) {
			postToFrame( { type: 'revert-pseudo', id: current.id } );
		}
		current = null;
		pendingStyles = {};
		pendingPseudo = {};
		postToFrame( { type: 'deselect' } );
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function pxNumber( value ) {
		var n = parseFloat( value );
		return isNaN( n ) ? 0 : Math.round( n * 100 ) / 100;
	}

	// ---- Preview watchdog ----
	// The preview iframe only loads bridge.js when the server recognises the
	// URL as an authorised edit preview; the signed parameter that proves that
	// eventually expires (and dies with the login session). When it does, the
	// page still renders — it just comes back as an ordinary front-end view,
	// with no bridge. Everything downstream then fails silently: nothing is
	// selectable, a finished result has nowhere to apply itself, and Save
	// stays disabled with no explanation. So: if the frame navigates and no
	// 'ready' handshake follows, say plainly that the session expired.

	var bridgeBanner = null;

	function bridgeArrived() {
		if ( bridgeBanner ) {
			bridgeBanner.hidden = true;
		}
	}

	function showBridgeLost() {
		// Two different situations look identical from here — the frame simply
		// has no bridge — but they need opposite advice. If the frame is no
		// longer on an edit-preview URL, the page was navigated away from and
		// the fix is to go back to what was being edited. Only when it IS on
		// such a URL and still came back without a bridge has the signed
		// session actually stopped being accepted.
		var navigatedAway = false;
		try {
			navigatedAway = !! frame.contentWindow &&
				-1 === frame.contentWindow.location.search.indexOf( 'clara_edit=1' );
		} catch ( e ) {
			navigatedAway = false;
		}

		if ( ! bridgeBanner ) {
			var canvas = document.getElementById( 'clara-ve-canvas' );
			if ( ! canvas ) {
				return;
			}
			bridgeBanner = document.createElement( 'div' );
			bridgeBanner.id = 'clara-ve-stale';
			var msg = document.createElement( 'div' );
			msg.className = 'clara-ve-stale-box';
			msg.appendChild( document.createElement( 'strong' ) );
			msg.appendChild( document.createElement( 'p' ) );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button button-primary';
			msg.appendChild( btn );
			bridgeBanner.appendChild( msg );
			canvas.appendChild( bridgeBanner );
		}

		var box = bridgeBanner.querySelector( '.clara-ve-stale-box' );
		var title = box.querySelector( 'strong' );
		var body = box.querySelector( 'p' );
		var action = box.querySelector( 'button' );
		var fresh = action.cloneNode( false ); // drop any previous handler
		action.parentNode.replaceChild( fresh, action );

		if ( navigatedAway ) {
			title.textContent = 'You left the page you were editing';
			body.textContent = 'The preview followed a link to another page, which can’t be edited from here.';
			fresh.textContent = 'Back to editing';
			fresh.addEventListener( 'click', function () {
				bridgeBanner.hidden = true;
				frame.src = reloadUrlForCurrentKey();
			} );
		} else {
			title.textContent = 'This editing session expired';
			body.textContent = 'The preview loaded without editing enabled, so changes can’t be made or saved. Reload to start a fresh session.';
			fresh.textContent = 'Reload editor';
			fresh.addEventListener( 'click', function () {
				window.location.reload();
			} );
		}
		bridgeBanner.hidden = false;
	}

	// Checked by looking for the bridge script itself rather than by waiting
	// for its handshake: the server enqueues bridge.js under exactly the
	// condition we care about, so its presence IS the answer — immediately,
	// and without the race a timeout has (the handshake can arrive before the
	// iframe's own load event, which made a timer fire on healthy sessions).
	frame.addEventListener( 'load', function () {
		var hasBridge = true; // never guess "broken" when we can't tell.
		try {
			var doc = frame.contentDocument;
			if ( doc ) {
				hasBridge = !! doc.querySelector( 'script[src*="bridge.js"], [data-cve-path]' );
			}
		} catch ( e ) {
			// Cross-origin — can't inspect, so assume it's fine.
		}
		if ( hasBridge ) {
			bridgeArrived();
		} else {
			showBridgeLost();
		}
	} );

	// Measure the box a soon-to-be-replaced media element currently occupies,
	// as inline styles to force onto the element that takes its place —
	// img->video AND video->img. Without this the swap breaks the layout: the
	// CSS that sized the original almost always targets a tag-specific selector
	// (e.g. `.card img{width:100%;height:100%;object-fit:cover}`) that doesn't
	// apply to the replacement tag, so it falls back to its intrinsic size and
	// blows the grid open. Reads the LIVE rendered element from the
	// (same-origin) preview iframe — a DOMParser'd source string has no layout,
	// so this can only be measured here, then carried in the patch so the saved
	// markup keeps the same box.
	function mediaBoxStyle( targetId ) {
		var out = {};
		try {
			var doc = frame.contentDocument;
			var win = frame.contentWindow;
			var img = doc && doc.querySelector( '[data-cve-path="' + targetId + '"]' );
			if ( ! img || ! win ) {
				return out;
			}
			var cs = win.getComputedStyle( img );
			var rect = img.getBoundingClientRect();

			// A video forced into an image's box should cover it (fill without
			// distortion), unless the image itself deliberately used contain.
			out['object-fit'] = ( cs.objectFit === 'contain' || cs.objectFit === 'scale-down' ) ? cs.objectFit : 'cover';
			if ( cs.objectPosition && cs.objectPosition !== '50% 50%' ) {
				out['object-position'] = cs.objectPosition;
			}
			if ( cs.borderRadius && cs.borderRadius !== '0px' ) {
				out['border-radius'] = cs.borderRadius;
			}
			out.display = ( cs.display && cs.display !== 'inline' ) ? cs.display : 'block';
			out['max-width'] = '100%';

			// Prefer 100% over pinned pixels on whichever axis the image FILLED
			// its parent's content box — the responsive grid/masonry case,
			// where the cell (not the image) owns the dimension, so the video
			// must track it at every viewport width, not freeze the one value
			// it happened to have in the narrower edit iframe. On an axis the
			// image did NOT fill (a natural-size inline image), pin the rendered
			// pixels so the box is still reproduced. object-fit:cover means a
			// pinned axis can't distort the clip, only crop it.
			var parent = media.parentElement;
			var filledWidth = false;
			var filledHeight = false;
			if ( parent ) {
				var pcs = win.getComputedStyle( parent );
				var pRect = parent.getBoundingClientRect();
				var innerW = pRect.width - ( parseFloat( pcs.paddingLeft ) || 0 ) - ( parseFloat( pcs.paddingRight ) || 0 );
				var innerH = pRect.height - ( parseFloat( pcs.paddingTop ) || 0 ) - ( parseFloat( pcs.paddingBottom ) || 0 );
				filledWidth = Math.abs( rect.width - innerW ) <= 1.5;
				filledHeight = Math.abs( rect.height - innerH ) <= 1.5;
			}
			out.width = filledWidth ? '100%' : Math.round( rect.width ) + 'px';
			out.height = filledHeight ? '100%' : Math.round( rect.height ) + 'px';
		} catch ( e ) {
			// Cross-frame access or a missing element — fall back to no box
			// hint (the old behavior) rather than throwing.
		}
		return out;
	}

	// Swap an <img> for a <video>, keeping the box the image occupied.
	function replaceImageWithVideo( targetId, result ) {
		var sources = [ { src: result.url, type: result.mime || 'video/mp4' } ];
		var boxStyle = mediaBoxStyle( targetId );
		postToFrame( { type: 'convert-to-video', id: targetId, poster: result.poster || '', sources: sources, boxStyle: boxStyle } );
		recordPatch( { id: targetId, kind: 'convert-to-video', poster: result.poster || '', sources: sources, boxStyle: boxStyle } );
		if ( current && current.id === targetId ) {
			// The element just changed kind (image -> video); this panel's
			// fields no longer apply. bridge.js's own 'select' re-post (sent
			// right after the DOM swap) refreshes it to the video panel.
			closePanelSilent();
		}
	}

	function previewStyle( key, value ) {
		pendingStyles[ key ] = value;
		postToFrame( { type: 'preview-style', id: current.id, styles: makeStyles( key, value ) } );
	}

	function previewPseudo( pseudo, key, value ) {
		pendingPseudo[ pseudo ] = pendingPseudo[ pseudo ] || {};
		pendingPseudo[ pseudo ][ key ] = value;
		postToFrame( { type: 'preview-pseudo', id: current.id, pseudo: pseudo, styles: makeStyles( key, value ) } );
	}

	function makeStyles( key, value ) {
		var styles = {};
		styles[ key ] = value;
		return styles;
	}

	// A ::before's computed `content` comes back as a quoted CSS string
	// (e.g. "“") — show the bare glyph for editing.
	function ornamentText( content ) {
		var m = /^"([\s\S]*)"$/.exec( content || '' );
		return m ? m[ 1 ] : ( content || '' );
	}

	// Re-wrap typed text as a safe CSS string literal for the pseudo layer.
	// Backslash and double-quote are CSS-escaped (not stripped) so any glyph —
	// including a straight " — round-trips; an empty value hides the ornament.
	// The server's `no {};` sanitiser still passes (escapes add none of those).
	function cssContentValue( value ) {
		return '"' + String( value ).replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ) + '"';
	}

	function stepperRow( label, key, initial, step, unit, previewFn ) {
		previewFn = previewFn || previewStyle;
		var row = el( 'div', 'cve-field' );
		row.appendChild( el( 'span', 'cve-field-label', label ) );
		var minus = el( 'button', 'cve-step', '−' );
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'cve-num';
		input.value = pxNumber( initial );
		var plus = el( 'button', 'cve-step', '+' );
		function commit( delta ) {
			var v = pxNumber( input.value ) + delta;
			var allowNegative = key === 'letterSpacing' || key.indexOf( 'margin' ) === 0;
			if ( v < 0 && ! allowNegative ) {
				v = 0;
			}
			if ( key === 'opacity' && v > 1 ) {
				v = 1;
			}
			input.value = Math.round( v * 100 ) / 100;
			previewFn( key, input.value + unit );
		}
		minus.addEventListener( 'click', function () {
			commit( -step );
		} );
		plus.addEventListener( 'click', function () {
			commit( step );
		} );
		input.addEventListener( 'change', function () {
			commit( 0 );
		} );
		row.appendChild( minus );
		row.appendChild( input );
		if ( unit ) {
			row.appendChild( el( 'span', 'cve-unit', unit ) );
		}
		row.appendChild( plus );
		return row;
	}

	// ---- Google fonts picker ----
	// The whole catalogue is ~1900 families, so two things keep it usable: the
	// list is capped to what a search actually narrows to, and each row loads
	// its own face only once it scrolls into view — and only the glyphs of the
	// preview line (Google's `text=` parameter), which keeps a row's download
	// to a few hundred bytes instead of a full webfont.

	var fontPickerEl = null;
	var fontCatalog = null;
	var fontPickerObserver = null;
	var FONT_PREVIEW_TEXT = 'Aa Bb Cc 123';
	// Rows are appended a batch at a time as the list is scrolled, so the whole
	// catalogue is browsable without ever building ~1900 rows at once.
	var FONT_BATCH = 60;

	function loadRowFace( row ) {
		if ( row.dataset.faceLoaded ) {
			return;
		}
		row.dataset.faceLoaded = '1';
		var link = document.createElement( 'link' );
		link.rel = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?family=' +
			encodeURIComponent( row.dataset.family ).replace( /%20/g, '+' ) +
			'&text=' + encodeURIComponent( FONT_PREVIEW_TEXT + row.dataset.family ) +
			'&display=swap';
		document.head.appendChild( link );
	}

	function openFontPicker() {
		if ( fontPickerEl ) {
			fontPickerEl.hidden = false;
			return;
		}
		fontPickerEl = el( 'div', 'cve-fontpicker' );
		var box = el( 'div', 'cve-fontpicker-box' );

		var head = el( 'div', 'cve-fontpicker-head' );
		head.appendChild( el( 'span', 'cve-grip', '⠿' ) );
		head.appendChild( el( 'strong', '', 'Google fonts' ) );
		var count = el( 'span', 'cve-fontpicker-count', '' );
		head.appendChild( count );
		var close = el( 'button', 'cve-close', '✕' );
		close.addEventListener( 'click', function () {
			fontPickerEl.hidden = true;
		} );
		head.appendChild( close );
		box.appendChild( head );

		var search = document.createElement( 'input' );
		search.type = 'search';
		search.className = 'cve-fontpicker-search';
		search.placeholder = 'Search all Google fonts…';
		box.appendChild( search );

		var chosen = el( 'div', 'cve-fontpicker-chosen' );
		box.appendChild( chosen );

		var list = el( 'div', 'cve-fontpicker-list', 'Loading the font list…' );
		box.appendChild( list );

		var note = el( 'div', 'cve-note', 'Added fonts appear in every element’s Font menu, on every page of the site.' );
		box.appendChild( note );

		fontPickerEl.appendChild( box );
		fontPickerEl.addEventListener( 'click', function ( ev ) {
			if ( ev.target === fontPickerEl ) {
				fontPickerEl.hidden = true; // click outside the box
			}
		} );
		document.body.appendChild( fontPickerEl );

		// Draggable, like the element panel and the questions popup. Positioned
		// in px once here rather than centred by the overlay's flexbox, because
		// makeDraggable() writes left/top and a flex-centred child would ignore
		// them. Done after the append so offsetWidth is real. Only on creation —
		// reopening unhides the same node, and re-centring it would throw away
		// wherever the owner had put it.
		var overlay = fontPickerEl.getBoundingClientRect();
		box.style.left = Math.max( 12, Math.round( ( overlay.width - box.offsetWidth ) / 2 ) ) + 'px';
		box.style.top = Math.max( 12, Math.round( ( overlay.height - box.offsetHeight ) / 2 ) ) + 'px';
		makeDraggable( box, head );

		var max = config.googleFontsMax || 5;

		function isChosen( family ) {
			return googleFonts.some( function ( g ) { return g.family === family; } );
		}

		function persist() {
			return window.wp
				.apiFetch( {
					path: '/clara-ve/v1/google-fonts',
					method: 'POST',
					data: { families: googleFonts },
				} )
				.then( function ( res ) {
					googleFonts = res.selected || [];
					refreshGoogleFontCss( res.cssUrl || '' );
					render();
					// An open element panel is showing the old font list.
					if ( current ) {
						openPanel( current );
					}
				} )
				.catch( function ( err ) {
					showToast( 'Could not save fonts: ' + ( ( err && err.message ) || 'error' ), 'cve-toast-error', 6000 );
				} );
		}

		function renderChosen() {
			chosen.innerHTML = '';
			count.textContent = googleFonts.length + ' / ' + max;
			if ( ! googleFonts.length ) {
				chosen.appendChild( el( 'span', 'cve-note', 'None added yet.' ) );
				return;
			}
			googleFonts.forEach( function ( g ) {
				var chip = el( 'span', 'cve-fontchip' );
				var nm = el( 'span', '', g.family );
				nm.style.fontFamily = fontStack( g );
				chip.appendChild( nm );
				var rm = el( 'button', 'cve-fontchip-x', '✕' );
				rm.title = 'Remove ' + g.family;
				rm.addEventListener( 'click', function () {
					googleFonts = googleFonts.filter( function ( x ) { return x.family !== g.family; } );
					persist();
				} );
				chip.appendChild( rm );
				chosen.appendChild( chip );
			} );
		}

		var matches = [];
		var shown = 0;
		var tail = null; // the "N of M" line, kept last in the list.

		function buildRow( f ) {
			var row = el( 'div', 'cve-fontrow' );
			row.dataset.family = f.family;
			var name = el( 'span', 'cve-fontrow-name', f.family );
			var prev = el( 'span', 'cve-fontrow-prev', FONT_PREVIEW_TEXT );
			prev.style.fontFamily = "'" + f.family + "', " + ( f.category || 'sans-serif' );
			row.appendChild( name );
			row.appendChild( prev );

			var already = isChosen( f.family );
			var btn = el( 'button', 'cve-btn cve-btn-light cve-fontrow-add', already ? 'Added' : 'Add' );
			btn.disabled = already || googleFonts.length >= max;
			btn.addEventListener( 'click', function () {
				if ( googleFonts.length >= max ) {
					return;
				}
				googleFonts = googleFonts.concat( [ { family: f.family, category: f.category || 'sans-serif' } ] );
				persist();
			} );
			row.appendChild( btn );
			return row;
		}

		function appendBatch() {
			if ( shown >= matches.length ) {
				return;
			}
			var upto = Math.min( shown + FONT_BATCH, matches.length );
			for ( ; shown < upto; shown++ ) {
				var row = buildRow( matches[ shown ] );
				list.insertBefore( row, tail );
				fontPickerObserver.observe( row );
			}
			tail.textContent = shown >= matches.length
				? 'All ' + matches.length + ' fonts shown.'
				: shown + ' of ' + matches.length + ' — scroll for more.';
		}

		function render() {
			renderChosen();
			if ( ! fontCatalog ) {
				return;
			}
			var q = search.value.trim().toLowerCase();
			matches = fontCatalog.filter( function ( f ) {
				return ! q || f.family.toLowerCase().indexOf( q ) !== -1;
			} );

			list.innerHTML = '';
			shown = 0;
			if ( fontPickerObserver ) {
				fontPickerObserver.disconnect();
			}
			fontPickerObserver = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( e ) {
					if ( e.isIntersecting ) {
						loadRowFace( e.target );
						fontPickerObserver.unobserve( e.target );
					}
				} );
			}, { root: list, rootMargin: '200px' } );

			if ( ! matches.length ) {
				list.appendChild( el( 'div', 'cve-note', 'No font matches “' + search.value + '”.' ) );
				return;
			}
			tail = el( 'div', 'cve-note cve-fontlist-tail', '' );
			list.appendChild( tail );
			appendBatch();
			// A short list may not fill the box, leaving nothing to scroll —
			// keep filling until it does.
			for ( var guard = 0; guard < 5 && shown < matches.length && list.scrollHeight <= list.clientHeight; guard++ ) {
				appendBatch();
			}
		}

		list.addEventListener( 'scroll', function () {
			if ( list.scrollTop + list.clientHeight >= list.scrollHeight - 300 ) {
				appendBatch();
			}
		} );

		search.addEventListener( 'input', render );
		renderChosen();

		window.wp
			.apiFetch( { path: '/clara-ve/v1/google-fonts' } )
			.then( function ( res ) {
				fontCatalog = res.catalog || [];
				googleFonts = res.selected || googleFonts;
				render();
			} )
			.catch( function ( err ) {
				list.textContent = 'Could not load the Google font list: ' + ( ( err && err.message ) || 'error' );
			} );
	}

	function selectRow( label, key, options, initial, mapValue ) {
		var row = el( 'div', 'cve-field' );
		row.appendChild( el( 'span', 'cve-field-label', label ) );
		var select = document.createElement( 'select' );
		select.className = 'cve-select';
		options.forEach( function ( opt ) {
			var o = document.createElement( 'option' );
			o.value = opt;
			o.textContent = opt;
			select.appendChild( o );
		} );
		if ( options.indexOf( initial ) === -1 && initial ) {
			var extra = document.createElement( 'option' );
			extra.value = initial;
			extra.textContent = initial;
			select.insertBefore( extra, select.firstChild );
		}
		select.value = initial || options[ 0 ];
		select.addEventListener( 'change', function () {
			previewStyle( key, mapValue ? mapValue( select.value ) : select.value );
		} );
		row.appendChild( select );
		return row;
	}

	/**
	 * Italic / underline / superscript / subscript as element-level styles.
	 *
	 * These ride the same previewStyle -> set-style pipeline as every other
	 * typography control, so undo, history and the saved source treat them
	 * identically. Turning a toggle OFF writes '' — set-style's contract for
	 * "remove the inline property", which hands the decision back to the
	 * stylesheet instead of pinning the opposite value (a heading the theme
	 * italicises stays italic again after toggling off, exactly like Weight).
	 *
	 * Super/subscript pair vertical-align with a 0.75em font-size, which is
	 * how browsers render real <sup>/<sub>; both are cleared together. The
	 * two are mutually exclusive — engaging one disengages the other.
	 */
	function formatRow( target ) {
		var row = el( 'div', 'cve-field' );
		row.appendChild( el( 'span', 'cve-field-label', 'Format' ) );
		var group = el( 'div', 'cve-fmtgroup' );

		var state = {
			italic: 'italic' === ( target.styles.fontStyle || '' ),
			underline: 'underline' === ( target.styles.textDecorationLine || '' ),
			valign: 'super' === target.styles.verticalAlign || 'sub' === target.styles.verticalAlign
				? target.styles.verticalAlign
				: '',
		};

		function btn( label, title, isOn, onClick ) {
			var b = el( 'button', 'cve-fmt' + ( isOn() ? ' on' : '' ), label );
			b.type = 'button';
			b.title = title;
			b.addEventListener( 'click', function () {
				onClick();
				Array.prototype.forEach.call( group.children, function ( c ) {
					c.classList.toggle( 'on', c._isOn() );
				} );
			} );
			b._isOn = isOn;
			return b;
		}

		group.appendChild( btn( 'I', 'Italic', function () { return state.italic; }, function () {
			state.italic = ! state.italic;
			previewStyle( 'fontStyle', state.italic ? 'italic' : '' );
		} ) );
		group.appendChild( btn( 'U', 'Underline', function () { return state.underline; }, function () {
			state.underline = ! state.underline;
			previewStyle( 'textDecorationLine', state.underline ? 'underline' : '' );
		} ) );

		function setValign( which ) {
			state.valign = state.valign === which ? '' : which;
			previewStyle( 'verticalAlign', state.valign );
			previewStyle( 'fontSize', state.valign ? '0.75em' : '' );
		}
		group.appendChild( btn( 'x²', 'Superscript', function () { return 'super' === state.valign; }, function () {
			setValign( 'super' );
		} ) );
		group.appendChild( btn( 'x₂', 'Subscript', function () { return 'sub' === state.valign; }, function () {
			setValign( 'sub' );
		} ) );

		row.appendChild( group );
		return row;
	}

	function colorRow( label, key, initial, previewFn ) {
		previewFn = previewFn || previewStyle;
		var row = el( 'div', 'cve-field' );
		row.appendChild( el( 'span', 'cve-field-label', label ) );
		var swatch = document.createElement( 'input' );
		swatch.type = 'color';
		swatch.className = 'cve-color';
		swatch.value = /^#[0-9a-f]{6}$/i.test( initial ) ? initial : '#000000';
		var hex = document.createElement( 'input' );
		hex.type = 'text';
		hex.className = 'cve-hex';
		hex.value = initial;
		swatch.addEventListener( 'input', function () {
			hex.value = swatch.value;
			previewFn( key, swatch.value );
		} );
		hex.addEventListener( 'change', function () {
			if ( /^#[0-9a-f]{3,6}$/i.test( hex.value ) ) {
				swatch.value = hex.value.length === 4 ? '#' + hex.value[ 1 ] + hex.value[ 1 ] + hex.value[ 2 ] + hex.value[ 2 ] + hex.value[ 3 ] + hex.value[ 3 ] : hex.value;
				previewFn( key, hex.value );
			}
		} );
		row.appendChild( swatch );
		row.appendChild( hex );
		return row;
	}

	function textRow( label, initial, onChange, live, placeholder ) {
		var row = el( 'div', 'cve-field' );
		row.appendChild( el( 'span', 'cve-field-label', label ) );
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'cve-text';
		input.value = initial;
		if ( placeholder ) {
			input.placeholder = placeholder;
		}
		input.addEventListener( 'change', function () {
			onChange( input.value );
		} );
		if ( live ) {
			// Preview per keystroke (used by the ornament Symbol field).
			input.addEventListener( 'input', function () {
				onChange( input.value );
			} );
		}
		row.appendChild( input );
		return row;
	}

	function makeDraggable( node, handle ) {
		var startX, startY, startLeft, startTop;
		handle.addEventListener( 'mousedown', function ( ev ) {
			if ( ev.target.closest && ev.target.closest( '.cve-close' ) ) {
				return; // the ✕ inside the header is a click, not a drag start.
			}
			ev.preventDefault();
			startX = ev.clientX;
			startY = ev.clientY;
			startLeft = node.offsetLeft;
			startTop = node.offsetTop;

			// THE jank fix: an iframe swallows mouse events, so the moment the
			// cursor crosses the preview iframe mid-drag, the parent document
			// stops receiving mousemove and the panel freezes/jumps. Disabling
			// the iframe's pointer events for the duration of the drag keeps
			// every move event in this document.
			frame.style.pointerEvents = 'none';
			document.body.classList.add( 'cve-dragging' );

			var raf = 0;
			var lastEv = null;
			function move( mev ) {
				// Batch position writes to animation frames — mousemove can fire
				// far more often than the display refreshes.
				lastEv = mev;
				if ( ! raf ) {
					raf = requestAnimationFrame( function () {
						raf = 0;
						node.style.left = startLeft + ( lastEv.clientX - startX ) + 'px';
						node.style.top = startTop + ( lastEv.clientY - startY ) + 'px';
					} );
				}
			}
			function up() {
				frame.style.pointerEvents = '';
				document.body.classList.remove( 'cve-dragging' );
				if ( raf ) {
					cancelAnimationFrame( raf );
					raf = 0;
				}
				document.removeEventListener( 'mousemove', move );
				document.removeEventListener( 'mouseup', up );
			}
			document.addEventListener( 'mousemove', move );
			document.addEventListener( 'mouseup', up );
		} );
	}

	function positionPanel( node, rect ) {
		var frameBox = frame.getBoundingClientRect();
		var bodyBox = body.getBoundingClientRect();
		var left = frameBox.left - bodyBox.left + rect.x + rect.width / 2 - 170;
		var top = frameBox.top - bodyBox.top + rect.y + rect.height + 14;
		left = Math.max( 10, Math.min( left, bodyBox.width - 360 ) );
		if ( top + 320 > bodyBox.height ) {
			top = Math.max( 10, frameBox.top - bodyBox.top + rect.y - 330 );
		}
		node.style.left = left + 'px';
		node.style.top = top + 'px';
	}

	function openPanel( target ) {
		closePanelSilent();
		current = target;
		pendingStyles = {};

		panel = el( 'div', 'cve-panel' );

		// Header: drag handle · label · close
		var head = el( 'div', 'cve-head' );
		var grip = el( 'span', 'cve-grip', '⠿' );
		head.appendChild( grip );
		head.appendChild( el( 'strong', 'cve-title', target.label || target.tagName ) );
		var close = el( 'button', 'cve-close', '✕' );
		close.addEventListener( 'click', function () {
			closePanel( true );
		} );
		head.appendChild( close );
		panel.appendChild( head );

		if ( target.menuZone ) {
			var origTitle = target.fields.text || '';
			var origUrl = target.fields.href || '';
			var labelInput, urlInput, blankInput;
			panel.appendChild( el( 'div', 'cve-section', 'MENU ITEM' ) );
			var labelRow = textRow( 'Label', origTitle, function () {} );
			labelInput = labelRow.querySelector( 'input' );
			panel.appendChild( labelRow );
			var urlRow = textRow( 'URL', origUrl, function () {} );
			urlInput = urlRow.querySelector( 'input' );
			panel.appendChild( urlRow );
			var blankWrapM = el( 'label', 'cve-checkrow' );
			blankInput = document.createElement( 'input' );
			blankInput.type = 'checkbox';
			blankInput.checked = target.fields.target === '_blank';
			blankWrapM.appendChild( blankInput );
			blankWrapM.appendChild( document.createTextNode( ' Open in new tab' ) );
			panel.appendChild( blankWrapM );

			var menuFoot = el( 'div', 'cve-foot' );
			var menuLink = el( 'a', 'cve-btn cve-btn-light', 'All Menus' );
			menuLink.href = config.menusUrl;
			menuLink.target = '_blank';
			menuLink.title = 'Open Appearance → Menus';
			var menuStatus = el( 'span', 'cve-note', '' );
			var menuSave = el( 'button', 'cve-btn cve-btn-save', 'Save' );
			menuSave.addEventListener( 'click', function () {
				menuStatus.textContent = 'Saving…';
				window.wp
					.apiFetch( {
						path: '/clara-ve/v1/menu-item',
						method: 'POST',
						data: {
							originalTitle: origTitle,
							originalUrl: origUrl,
							title: labelInput.value.trim(),
							url: urlInput.value.trim(),
							blank: blankInput.checked,
							// Which declared zone the click landed in, so the
							// edit targets the right menu on a site with several.
							location: target.menuLocation || '',
						},
					} )
					.then( function () {
						menuStatus.textContent = 'Saved ✓';
						// Live-update the clicked nav link; source stays
						// untouched — the nav re-renders from the menu.
						postToFrame( {
							type: 'set-link',
							id: target.id,
							href: urlInput.value.trim(),
							target: blankInput.checked ? '_blank' : '',
						} );
						postToFrame( { type: 'set-text-live', id: target.id, value: labelInput.value.trim() } );
						origTitle = labelInput.value.trim();
						origUrl = urlInput.value.trim();
					} )
					.catch( function ( err ) {
						menuStatus.textContent = 'Error: ' + ( err.message || 'unknown' );
					} );
			} );
			menuFoot.appendChild( menuLink );
			menuFoot.appendChild( el( 'span', 'cve-flex' ) );
			menuFoot.appendChild( menuStatus );
			menuFoot.appendChild( menuSave );
			panel.appendChild( menuFoot );

			body.appendChild( panel );
			positionPanel( panel, target.rect );
			makeDraggable( panel, head );
			return;
		}

		// Token-hydrated zones ([wp-posts]/[wp-form]/[wp-menu], see
		// includes/class-tokens.php) aren't edited here at all — their
		// content comes from WordPress data, not the page's own markup — so
		// clicking one just shows what's managing it and a link to go fix it
		// there, same pattern as the legacy menu-zone panel above.
		var ZONE_COPY = {
			posts: { title: 'POSTS ZONE', hint: 'These cards are populated from your WordPress posts — the titles, images and text come from each post and can’t be typed over here. The CARD itself is yours to style below: a change applies to every card, current and future.', linkText: 'Manage Posts', linkUrl: config.postsUrl },
			form: { title: 'FORM ZONE', hint: 'This form submits to WordPress and can’t be edited here.', linkText: 'View Submissions', linkUrl: config.submissionsUrl },
			menu: { title: 'MENU ZONE', hint: 'This menu is populated from a WordPress menu and can’t be edited here.', linkText: 'All Menus', linkUrl: config.menusUrl },
			article: { title: 'ARTICLE FIELD', hint: 'This comes from the post itself and changes with every article, so it can’t be edited here. Style the box around it instead — that applies to all articles.', linkText: 'All Posts', linkUrl: config.postsUrl },
		};
		if ( target.zone && ZONE_COPY[ target.zone ] ) {
			var zoneCopy = ZONE_COPY[ target.zone ];
			panel.appendChild( el( 'div', 'cve-section', zoneCopy.title ) );
			panel.appendChild( el( 'p', 'cve-note', zoneCopy.hint ) );
			// A connected form keeps its controls: the connection was made by
			// clicking, so it has to be changeable and undoable by clicking.
			if ( target.formPath ) {
				appendFormSection( panel, target.formPath );
			}

			// CARD STYLE — posts zone only. The zone's own path addresses the
			// card TEMPLATE inside the [wp-posts] token in the stored source
			// (the token's inner markup is the one element in this sibling
			// slot), so the ordinary style machinery does the whole job: a
			// preview-style fans out to every rendered card (bridge.js), and
			// the saved set-style patch lands on the template element, which
			// every card — including future posts — renders from. The values
			// (title, image, excerpt…) stay locked: nothing inside the zone is
			// stamped, so no text or image control can reach them.
			if ( 'posts' === target.zone ) {
				panel.appendChild( el( 'div', 'cve-section', 'CARD STYLE' ) );
				panel.appendChild( colorRow( 'Background', 'backgroundColor', target.styles.backgroundColor ) );
				var cardGrid = el( 'div', 'cve-grid' );
				cardGrid.appendChild( stepperRow( 'Radius', 'borderRadius', target.styles.borderRadius, 1, 'px' ) );
				cardGrid.appendChild( stepperRow( 'Opacity', 'opacity', target.styles.opacity || '1', 0.1, '' ) );
				panel.appendChild( cardGrid );
				panel.appendChild( el( 'div', 'cve-section', 'PADDING' ) );
				var cardPad = el( 'div', 'cve-grid' );
				cardPad.appendChild( stepperRow( '↑', 'paddingTop', target.styles.paddingTop, 4, 'px' ) );
				cardPad.appendChild( stepperRow( '↓', 'paddingBottom', target.styles.paddingBottom, 4, 'px' ) );
				cardPad.appendChild( stepperRow( '←', 'paddingLeft', target.styles.paddingLeft, 4, 'px' ) );
				cardPad.appendChild( stepperRow( '→', 'paddingRight', target.styles.paddingRight, 4, 'px' ) );
				panel.appendChild( cardPad );
				panel.appendChild( el( 'div', 'cve-section', 'TYPOGRAPHY' ) );
				var cardType = el( 'div', 'cve-grid' );
				cardType.appendChild( stepperRow( 'Size', 'fontSize', target.styles.fontSize, 1, 'px' ) );
				cardType.appendChild( selectRow( 'Weight', 'fontWeight', [ '300', '400', '500', '600', '700' ], target.styles.fontWeight ) );
				panel.appendChild( cardType );
				panel.appendChild( colorRow( 'Text color', 'color', target.styles.color ) );
				panel.appendChild(
					el( 'p', 'cve-note', 'Styles the card box every post renders in — all cards change together. Typography set here cascades to the card’s own text.' )
				);
			}

			var zoneFoot = el( 'div', 'cve-foot' );
			var zoneLink = el( 'a', 'cve-btn cve-btn-light', zoneCopy.linkText );
			zoneLink.href = zoneCopy.linkUrl;
			zoneLink.target = '_blank';
			zoneFoot.appendChild( zoneLink );
			if ( 'posts' === target.zone ) {
				var cardReset = el( 'button', 'cve-icon', '↺' );
				cardReset.title = 'Reset card style';
				cardReset.addEventListener( 'click', function () {
					postToFrame( { type: 'revert-style', id: current.id } );
					pendingStyles = {};
					for ( var i = patches.length - 1; i >= 0; i-- ) {
						if ( patches[ i ].id === current.id && 'set-style' === patches[ i ].kind ) {
							patches.splice( i, 1 );
						}
					}
					setDirty();
				} );
				var cardStatus = el( 'span', 'cve-note', '' );
				var cardSave = el( 'button', 'cve-btn cve-btn-save', 'Save' );
				cardSave.addEventListener( 'click', function () {
					if ( Object.keys( pendingStyles ).length ) {
						recordPatch( { id: current.id, kind: 'set-style', styles: Object.assign( {}, pendingStyles ) } );
						pendingStyles = {};
					}
					closePanelSilent();
				} );
				zoneFoot.appendChild( cardReset );
				zoneFoot.appendChild( el( 'span', 'cve-flex' ) );
				zoneFoot.appendChild( cardStatus );
				zoneFoot.appendChild( cardSave );
			}
			panel.appendChild( zoneFoot );

			body.appendChild( panel );
			positionPanel( panel, target.rect );
			makeDraggable( panel, head );
			return;
		}

		// Not a zone: the button IS the page's own markup, so every normal
		// control below stays available. It only needs explaining, because
		// pressing it here selects it instead of loading anything — the paging
		// script is deliberately absent from the preview, where appended cards
		// would exist in the canvas but not in the saved source.
		if ( target.formPath ) {
			appendFormSection( panel, target.formPath );
		}

		// The toast that accompanies this is raised by the frame's own click
		// message, not here — the click has to be answered with edit mode off
		// too, where no panel opens at all.
		if ( target.loadMore ) {
			panel.appendChild( el( 'div', 'cve-section', 'LOAD MORE' ) );
			panel.appendChild(
				el(
					'p',
					'cve-note',
					'On the live site this loads the next page of articles, and hides itself once there are none left. The editor always shows the first page. Its text and styling are yours.'
				)
			);
		}

		if ( target.kind === 'image' ) {
			var img = document.createElement( 'img' );
			img.className = 'cve-img';
			img.src = target.fields.src;
			panel.appendChild( img );
			var pick = el( 'button', 'cve-btn', 'Choose image from Media Library' );
			pick.addEventListener( 'click', function () {
				var mediaFrame = window.wp.media( { title: 'Select image', multiple: false, library: { type: 'image' } } );
				mediaFrame.on( 'select', function () {
					var att = mediaFrame.state().get( 'selection' ).first().toJSON();
					img.src = att.url;
					current.fields.src = att.url;
					postToFrame( { type: 'set-image', id: current.id, src: att.url, alt: current.fields.alt } );
					recordPatch( { id: current.id, kind: 'set-image', src: att.url, alt: current.fields.alt } );
				} );
				mediaFrame.open();
			} );
			panel.appendChild( pick );

			// Swap the <img> for a <video> using a library video. The current
			// image becomes the poster so the element keeps a still frame
			// before playback.
			var pickVideo = el( 'button', 'cve-btn', 'Replace with video from Media Library' );
			pickVideo.addEventListener( 'click', function () {
				var targetId = current.id;
				var poster = current.fields.src;
				var mediaFrame = window.wp.media( { title: 'Select video', multiple: false, library: { type: 'video' } } );
				mediaFrame.on( 'select', function () {
					var att = mediaFrame.state().get( 'selection' ).first().toJSON();
					replaceImageWithVideo( targetId, { url: att.url, mime: att.mime || 'video/mp4', poster: poster } );
				} );
				mediaFrame.open();
			} );
			panel.appendChild( pickVideo );
			panel.appendChild(
				textRow( 'Alt', target.fields.alt || '', function ( value ) {
					current.fields.alt = value;
					pushImage();
				} )
			);

			// Every field on this image writes through one place, because the
			// address it points at rides on the same patch as its source and
			// alt text (see applyPatch). Sending them together also means a
			// half-finished edit can never drop the other two.
			function pushImage() {
				var msg = {
					id: current.id,
					src: current.fields.src,
					alt: current.fields.alt,
					link: current.fields.link || '',
					linkTarget: current.fields.linkTarget || '',
				};
				postToFrame( Object.assign( { type: 'set-image' }, msg ) );
				recordPatch( Object.assign( { kind: 'set-image' }, msg ) );
			}

			panel.appendChild(
				textRow( 'Image URL', target.fields.src || '', function ( value ) {
					current.fields.src = value.trim();
					img.src = current.fields.src;
					pushImage();
				}, false, 'https://…' )
			);

			panel.appendChild( el( 'div', 'cve-section', 'LINK' ) );
			panel.appendChild(
				textRow( 'Opens', target.fields.link || '', function ( value ) {
					current.fields.link = value.trim();
					pushImage();
				}, false, '/about/  or  https://…' )
			);
			var imgBlankRow = el( 'label', 'cve-checkrow' );
			var imgBlank = document.createElement( 'input' );
			imgBlank.type = 'checkbox';
			imgBlank.checked = target.fields.linkTarget === '_blank';
			imgBlank.addEventListener( 'change', function () {
				current.fields.linkTarget = imgBlank.checked ? '_blank' : '';
				pushImage();
			} );
			imgBlankRow.appendChild( imgBlank );
			imgBlankRow.appendChild( el( 'span', '', 'Open in a new tab' ) );
			panel.appendChild( imgBlankRow );
			panel.appendChild(
				el( 'p', 'cve-note',
					target.fields.link && ! target.fields.linkOwned
						? 'This picture is already linked by the design. Changing the address here re-points it; emptying the field leaves the element in place and simply stops it linking, because the layout is built on it.'
						: 'Leave empty for a picture that is not clickable. Fill it in and the picture becomes a link.'
				)
			);

			// ---- Image hosted somewhere else ----
			// A converted design routinely points at a CDN or a stock-photo
			// host, which leaves the page depending on someone else's server
			// staying up. One click copies the image into this site's Media
			// Library and repoints the markup.
			var srcIsRemote = /^https?:\/\//i.test( target.fields.src || '' )
				&& 0 !== ( target.fields.src || '' ).indexOf( window.location.origin );
			if ( srcIsRemote ) {
				var remoteNote = el( 'p', 'cve-note',
					'This image is hosted on another site, so the page depends on that server staying up. Import it into your Media Library to serve it yourself.' );
				panel.appendChild( remoteNote );
				var importBtn = el( 'button', 'cve-btn cve-btn-block', 'Import image into this site' );
				importBtn.addEventListener( 'click', function () {
					var targetId = current.id;
					var altNow = current.fields.alt || '';
					importBtn.disabled = true;
					importBtn.textContent = 'Importing…';
					window.wp
						.apiFetch( {
							path: '/clara-ve/v1/import-image',
							method: 'POST',
							data: { src: current.fields.src, alt: altNow },
						} )
						.then( function ( res ) {
							img.src = res.url;
							current.fields.src = res.url;
							// The ordinary set-image patch, so this is one undo
							// away like any other edit and the remote original
							// is left untouched.
							postToFrame( { type: 'set-image', id: targetId, src: res.url, alt: altNow } );
							recordPatch( { id: targetId, kind: 'set-image', src: res.url, alt: altNow } );
							remoteNote.textContent = 'Imported into this site — it is served from your own Media Library now. Save to keep the change.';
							importBtn.remove();
						} )
						.catch( function ( err ) {
							importBtn.disabled = false;
							importBtn.textContent = 'Import image into this site';
							remoteNote.textContent = 'Could not import: ' + ( err && err.message ? err.message : 'unknown error' );
						} );
				} );
				panel.appendChild( importBtn );
			}
		}

		if ( target.kind === 'video' ) {
			var vid = document.createElement( 'video' );
			vid.className = 'cve-video-preview';
			vid.muted = true;
			vid.controls = true;

			var sendVideoPatch = function () {
				postToFrame( { type: 'set-video', id: current.id, poster: current.fields.poster, sources: current.fields.sources } );
				recordPatch( { id: current.id, kind: 'set-video', poster: current.fields.poster, sources: current.fields.sources } );
			};

			// The <video> preview shows every source (the browser itself picks the
			// one matching its own viewport/codec support, same as on the live page).
			function refreshPreview() {
				vid.querySelectorAll( 'source' ).forEach( function ( se ) {
					se.remove();
				} );
				( current.fields.sources || [] ).forEach( function ( s ) {
					if ( ! s.src ) {
						return;
					}
					var se = document.createElement( 'source' );
					se.src = s.src;
					if ( s.type ) {
						se.type = s.type;
					}
					if ( s.media ) {
						se.media = s.media;
					}
					vid.appendChild( se );
				} );
				vid.load();
			}
			if ( target.fields.poster ) {
				vid.poster = target.fields.poster;
			}
			panel.appendChild( vid );
			refreshPreview();

			// Swap the <video> back for an <img> using a library image — the
			// reverse of the image panel's "Replace with video". The video's
			// current box is measured and forced onto the image so the layout
			// is preserved (video-targeted sizing CSS won't apply to <img>).
			var toImageBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block', 'Replace with image from Media Library' );
			toImageBtn.addEventListener( 'click', function () {
				var targetId = current.id;
				var boxStyle = mediaBoxStyle( targetId );
				var mediaFrame = window.wp.media( { title: 'Select image', multiple: false, library: { type: 'image' } } );
				mediaFrame.on( 'select', function () {
					var att = mediaFrame.state().get( 'selection' ).first().toJSON();
					var alt = att.alt || att.title || '';
					postToFrame( { type: 'convert-to-image', id: targetId, src: att.url, alt: alt, boxStyle: boxStyle } );
					recordPatch( { id: targetId, kind: 'convert-to-image', src: att.url, alt: alt, boxStyle: boxStyle } );
					// bridge.js re-selects the new <img>, reopening the image panel.
				} );
				mediaFrame.open();
			} );
			panel.appendChild( toImageBtn );

			// ---- Play automatically on scroll ----
			// Always offered for any video (including one just converted from an
			// image): plays once when scrolled into view on the public page, then
			// rests on its final frame — the plugin's scroll-video.js drives it.
			var scrollRow = el( 'label', 'cve-checkrow' );
			var scrollCb = document.createElement( 'input' );
			scrollCb.type = 'checkbox';
			scrollCb.checked = ! ! target.fields.scrollVideo;
			scrollRow.appendChild( scrollCb );
			scrollRow.appendChild( document.createTextNode( ' Play automatically on scroll' ) );
			panel.appendChild( scrollRow );
			var scrollNote = el( 'div', 'cve-note', 'Plays once (muted) when it scrolls into view, then holds on the last frame. Player controls are hidden.' );
			panel.appendChild( scrollNote );
			scrollCb.addEventListener( 'change', function () {
				var on = scrollCb.checked;
				current.fields.scrollVideo = on;
				postToFrame( { type: 'set-scroll-video', id: current.id, enabled: on } );
				recordPatch( { id: current.id, kind: 'set-scroll-video', enabled: on } );
			} );

			function describeSource( s ) {
				var base = ( s.src || '' ).split( '/' ).pop() || 'video';
				var meta = [];
				if ( s.media ) {
					meta.push( s.media ); // e.g. a responsive breakpoint like (max-width: 767px)
				}
				if ( s.type ) {
					meta.push( s.type );
				}
				return meta.length ? base + ' — ' + meta.join( ' · ' ) : base;
			}

			// One row per EXISTING <source> — each keeps its own type/media (format
			// fallback or a responsive breakpoint) and is replaced independently, so
			// swapping one format/breakpoint never collapses the others into it.
			panel.appendChild( el( 'div', 'cve-section', 'SOURCES' ) );
			var sourcesWrap = el( 'div', 'cve-sources' );
			panel.appendChild( sourcesWrap );

			function renderSourceRows() {
				sourcesWrap.innerHTML = '';
				var list = current.fields.sources && current.fields.sources.length ? current.fields.sources : [ { src: '', type: '', media: '' } ];
				list.forEach( function ( s, idx ) {
					var row = el( 'div', 'cve-source-row' );
					row.appendChild( el( 'span', 'cve-source-label', s.src ? describeSource( s ) : 'No video set' ) );
					var replace = el( 'button', 'cve-btn cve-btn-light cve-source-replace', s.src ? 'Replace' : 'Choose' );
					replace.addEventListener( 'click', function () {
						var mediaFrame = window.wp.media( { title: 'Select video', multiple: false, library: { type: 'video' } } );
						mediaFrame.on( 'select', function () {
							var att = mediaFrame.state().get( 'selection' ).first().toJSON();
							var entry = { src: att.url, type: att.mime || '', media: s.media || '' };
							if ( current.fields.sources && current.fields.sources.length ) {
								current.fields.sources[ idx ] = entry;
							} else {
								current.fields.sources = [ entry ];
							}
							refreshPreview();
							renderSourceRows();
							sendVideoPatch();
						} );
						mediaFrame.open();
					} );
					row.appendChild( replace );
					sourcesWrap.appendChild( row );
				} );
			}
			renderSourceRows();

			// ---- Poster ----
			// The still shown in the video box before playback. Show which image
			// it currently is (thumbnail + filename), let it be replaced, and —
			// when one is set — removed entirely.
			panel.appendChild( el( 'div', 'cve-section', 'POSTER' ) );
			var posterWrap = el( 'div', 'cve-poster' );
			panel.appendChild( posterWrap );

			function renderPoster() {
				posterWrap.innerHTML = '';
				var poster = current.fields.poster || '';
				if ( poster ) {
					var pimg = document.createElement( 'img' );
					pimg.className = 'cve-img';
					pimg.src = poster;
					posterWrap.appendChild( pimg );
					posterWrap.appendChild( el( 'div', 'cve-note', poster.split( '/' ).pop() ) );
				} else {
					posterWrap.appendChild( el( 'div', 'cve-note', 'No poster image — the video box shows its first frame before playing.' ) );
				}

				var chooseBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block', poster ? 'Replace poster image' : 'Choose poster image' );
				chooseBtn.addEventListener( 'click', function () {
					var mediaFrame = window.wp.media( { title: 'Select poster image', multiple: false, library: { type: 'image' } } );
					mediaFrame.on( 'select', function () {
						var att = mediaFrame.state().get( 'selection' ).first().toJSON();
						current.fields.poster = att.url;
						vid.poster = att.url;
						sendVideoPatch();
						renderPoster();
					} );
					mediaFrame.open();
				} );
				posterWrap.appendChild( chooseBtn );

				if ( poster ) {
					var removeBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block', 'Remove poster' );
					removeBtn.addEventListener( 'click', function () {
						current.fields.poster = '';
						vid.removeAttribute( 'poster' );
						sendVideoPatch();
						renderPoster();
					} );
					posterWrap.appendChild( removeBtn );
				}
			}
			renderPoster();
		}

		// A link whose address is generated keeps everything else — text,
		// typography, spacing — and loses only the URL row. Offering it would
		// invite typing one article's address into a template every article
		// renders through.
		if ( target.kind === 'link' && target.dynamicHref ) {
			panel.appendChild( el( 'div', 'cve-section', 'LINK TARGET' ) );
			panel.appendChild(
				el( 'p', 'cve-note', 'This link points somewhere different on every article, so its address is set automatically. Its text and styling are yours.' )
			);
		}

		if ( target.kind === 'link' && ! target.dynamicHref ) {
			panel.appendChild(
				textRow( 'URL', target.fields.href || '', function ( value ) {
					current.fields.href = value;
					postToFrame( { type: 'set-link', id: current.id, href: value, target: current.fields.target } );
					recordPatch( { id: current.id, kind: 'set-link', href: value, target: current.fields.target } );
				} )
			);
			var blankRow = el( 'label', 'cve-checkrow' );
			var blank = document.createElement( 'input' );
			blank.type = 'checkbox';
			blank.checked = target.fields.target === '_blank';
			blank.addEventListener( 'change', function () {
				current.fields.target = blank.checked ? '_blank' : '';
				postToFrame( { type: 'set-link', id: current.id, href: current.fields.href, target: current.fields.target } );
				recordPatch( { id: current.id, kind: 'set-link', href: current.fields.href, target: current.fields.target } );
			} );
			blankRow.appendChild( blank );
			blankRow.appendChild( document.createTextNode( ' Open in new tab' ) );
			panel.appendChild( blankRow );
		}

		// Box controls are normally a container's alone, on the reasoning that a
		// text element is styled by its typography. A box holding a generated
		// field is both at once — the <h1> around the article title, the card
		// around a prev/next link — and it is the only handle on that part of
		// the template, so it gets the full set rather than half of it.
		if ( target.kind === 'container' || target.holdsField ) {
			panel.appendChild( el( 'div', 'cve-section', 'BACKGROUND' ) );
			panel.appendChild( colorRow( 'Color', 'backgroundColor', target.styles.backgroundColor ) );
			var contGrid = el( 'div', 'cve-grid' );
			contGrid.appendChild( stepperRow( 'Opacity', 'opacity', target.styles.opacity || '1', 0.1, '' ) );
			contGrid.appendChild( stepperRow( 'Radius', 'borderRadius', target.styles.borderRadius, 1, 'px' ) );
			panel.appendChild( contGrid );

			// Size — lets empty decorative elements (a 1px rule, a spacer) be
			// resized, not just recoloured.
			panel.appendChild( el( 'div', 'cve-section', 'SIZE' ) );
			var sizeGrid = el( 'div', 'cve-grid' );
			sizeGrid.appendChild( stepperRow( 'Width', 'width', target.styles.width, 1, 'px' ) );
			sizeGrid.appendChild( stepperRow( 'Height', 'height', target.styles.height, 1, 'px' ) );
			panel.appendChild( sizeGrid );

			var display = target.styles.display || '';
			if ( display.indexOf( 'flex' ) !== -1 || display.indexOf( 'grid' ) !== -1 ) {
				panel.appendChild( el( 'div', 'cve-section', 'LAYOUT' ) );
				if ( display.indexOf( 'flex' ) !== -1 ) {
					panel.appendChild(
						selectRow( 'Direction', 'flexDirection', [ 'row', 'row-reverse', 'column', 'column-reverse' ], target.styles.flexDirection )
					);
				}
				var layoutGrid = el( 'div', 'cve-grid' );
				layoutGrid.appendChild(
					selectRow( 'Justify', 'justifyContent', [ 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ], target.styles.justifyContent )
				);
				layoutGrid.appendChild(
					selectRow( 'Align', 'alignItems', [ 'stretch', 'flex-start', 'center', 'flex-end', 'baseline' ], target.styles.alignItems )
				);
				panel.appendChild( layoutGrid );
				panel.appendChild( stepperRow( 'Gap', 'gap', target.styles.gap, 2, 'px' ) );
			}

			panel.appendChild( el( 'div', 'cve-section', 'PADDING' ) );
			var padGrid = el( 'div', 'cve-grid' );
			padGrid.appendChild( stepperRow( '↑', 'paddingTop', target.styles.paddingTop, 4, 'px' ) );
			padGrid.appendChild( stepperRow( '↓', 'paddingBottom', target.styles.paddingBottom, 4, 'px' ) );
			padGrid.appendChild( stepperRow( '←', 'paddingLeft', target.styles.paddingLeft, 4, 'px' ) );
			padGrid.appendChild( stepperRow( '→', 'paddingRight', target.styles.paddingRight, 4, 'px' ) );
			panel.appendChild( padGrid );

			panel.appendChild( el( 'div', 'cve-section', 'MARGIN' ) );
			var marGrid = el( 'div', 'cve-grid' );
			marGrid.appendChild( stepperRow( '↑', 'marginTop', target.styles.marginTop, 4, 'px' ) );
			marGrid.appendChild( stepperRow( '↓', 'marginBottom', target.styles.marginBottom, 4, 'px' ) );
			marGrid.appendChild( stepperRow( '←', 'marginLeft', target.styles.marginLeft, 4, 'px' ) );
			marGrid.appendChild( stepperRow( '→', 'marginRight', target.styles.marginRight, 4, 'px' ) );
			panel.appendChild( marGrid );
		}

		// This box's words come from the post, so there is no text to type here —
		// but everything about how they LOOK is on the table. Say so, or the
		// absent text field reads as a broken panel.
		if ( target.holdsField ) {
			panel.appendChild( el( 'div', 'cve-section', 'FROM THE POST' ) );
			panel.appendChild(
				el(
					'p',
					'cve-note',
					'The words and images here come from the post, so they change with every article and can’t be typed over. How they LOOK is set here, and applies to every article.'
				)
			);
		}

		// Editable text field in the panel itself — mirrors the inline caret,
		// so text can be changed either way. Rich blocks (nested markup) stay
		// inline-only to avoid flattening their formatting.
		if ( ( target.kind === 'text' || target.kind === 'link' ) && target.editableNow && ! target.rich && ! target.holdsField ) {
			panel.appendChild(
				textRow( 'Text', target.fields.text || '', function ( value ) {
					current.fields.text = value;
					postToFrame( { type: 'set-text-live', id: current.id, value: value } );
					recordPatch( { id: current.id, kind: 'set-text', value: value } );
				} )
			);
		}

		if ( target.kind === 'text' || target.kind === 'link' ) {
			panel.appendChild( el( 'div', 'cve-section', 'TYPOGRAPHY' ) );
			panel.appendChild(
				selectRow( 'Font', 'fontFamily', fontOptions(), target.styles.fontFamily, fontValue )
			);
			var addFontBtn = el( 'button', 'cve-btn cve-btn-light cve-btn-block cve-addfont', '＋ Add Google fonts' );
			addFontBtn.addEventListener( 'click', openFontPicker );
			panel.appendChild( addFontBtn );
			var twoCol = el( 'div', 'cve-grid' );
			twoCol.appendChild( stepperRow( 'Size', 'fontSize', target.styles.fontSize, 1, 'px' ) );
			twoCol.appendChild( selectRow( 'Weight', 'fontWeight', [ '300', '400', '500', '600', '700' ], target.styles.fontWeight ) );
			panel.appendChild( twoCol );
			panel.appendChild( formatRow( target ) );
			var twoCol2 = el( 'div', 'cve-grid' );
			twoCol2.appendChild( colorRow( 'Color', 'color', target.styles.color ) );
			twoCol2.appendChild( selectRow( 'Align', 'textAlign', [ 'left', 'center', 'right', 'justify' ], target.styles.textAlign ) );
			panel.appendChild( twoCol2 );
			var twoCol3 = el( 'div', 'cve-grid' );
			twoCol3.appendChild( stepperRow( 'Line', 'lineHeight', target.styles.lineHeight || '0', 1, 'px' ) );
			twoCol3.appendChild( stepperRow( 'Tracking', 'letterSpacing', target.styles.letterSpacing, 0.1, 'px' ) );
			panel.appendChild( twoCol3 );
			if ( target.kind === 'text' && target.editableNow ) {
				panel.appendChild( el( 'p', 'cve-note', 'Edit text directly in the page — Enter commits, Esc cancels.' ) );
			}
		}

		if ( target.ornaments && target.ornaments.length && ! target.menuZone ) {
			target.ornaments.forEach( function ( orn ) {
				var makePreview = function ( key, value ) {
					previewPseudo( orn.pseudo, key, value );
				};
				panel.appendChild( el( 'div', 'cve-section', orn.pseudo === 'after' ? 'ORNAMENT (AFTER)' : 'ORNAMENT' ) );
				// Primary action: promote this CSS pseudo-glyph into a real inline
				// element you can click and edit as text.
				var convert = el( 'button', 'cve-btn cve-btn-light cve-btn-block', 'Convert to editable text' );
				convert.addEventListener( 'click', function () {
					postToFrame( { type: 'convert-ornament', id: current.id, pseudo: orn.pseudo } );
				} );
				panel.appendChild( convert );

				// Symbol field, rendered in the ornament's REAL font so the preview
				// matches the page (not the panel's UI font).
				var symRow = el( 'div', 'cve-field' );
				symRow.appendChild( el( 'span', 'cve-field-label', 'Symbol' ) );
				var symInput = document.createElement( 'input' );
				symInput.type = 'text';
				symInput.className = 'cve-text cve-symbol';
				symInput.value = ornamentText( orn.content );
				if ( orn.fontFamily ) {
					symInput.style.fontFamily = orn.fontFamily;
				}
				if ( orn.fontWeight ) {
					symInput.style.fontWeight = orn.fontWeight;
				}
				var setSymbol = function ( value ) {
					makePreview( 'content', cssContentValue( value ) );
				};
				symInput.addEventListener( 'input', function () {
					setSymbol( symInput.value );
				} );
				symInput.addEventListener( 'change', function () {
					setSymbol( symInput.value );
				} );
				symRow.appendChild( symInput );
				panel.appendChild( symRow );

				// Typographic glyphs that are awkward to type on a keyboard.
				var glyphs = [ '“', '”', '‘', '’', '«', '»', '—' ];
				var glyphRow = el( 'div', 'cve-glyphs' );
				glyphs.forEach( function ( g ) {
					var gb = el( 'button', 'cve-glyph', g );
					gb.type = 'button';
					gb.title = 'U+' + g.codePointAt( 0 ).toString( 16 ).toUpperCase();
					if ( orn.fontFamily ) {
						gb.style.fontFamily = orn.fontFamily;
					}
					gb.addEventListener( 'click', function () {
						symInput.value = g;
						setSymbol( g );
					} );
					glyphRow.appendChild( gb );
				} );
				panel.appendChild( glyphRow );

				var ornGrid = el( 'div', 'cve-grid' );
				ornGrid.appendChild( colorRow( 'Color', 'color', orn.color, makePreview ) );
				ornGrid.appendChild( stepperRow( 'Size', 'fontSize', orn.fontSize, 2, 'px', makePreview ) );
				panel.appendChild( ornGrid );
			} );
		}

		// Questions and answers. Only when the clicked element actually sits in a
		// repeated question unit — see faqUnitFor() in bridge.js.
		//
		// This exists because editing a question and MANAGING questions are
		// different jobs, and only the first one was possible. The panel let you
		// retype a question's text, which is fine; adding one meant hand-editing
		// HTML, and removing one meant deleting the heading and leaving its
		// answer behind as an orphan paragraph. Both operate on the whole unit
		// here, which is also what keeps the structured data correct — the FAQ is
		// re-read from the saved source, so it follows with no extra step.
		if ( current.faq ) {
			var faqUnit = current.faq;
			var faqSec  = el( 'div', 'cve-sec' );
			faqSec.appendChild( el( 'div', 'cve-sec-title', 'QUESTIONS' ) );

			var openQ = el(
				'button',
				'cve-btn cve-btn-light cve-btn-block',
				'Edit questions (' + faqUnit.items.length + ')'
			);
			openQ.type = 'button';
			openQ.addEventListener( 'click', function () {
				openFaqEditor( faqUnit );
			} );
			faqSec.appendChild( openQ );
			faqSec.appendChild(
				el( 'p', 'cve-note', 'Reword, reorder, add and remove — all of them together, saved once.' )
			);

			panel.appendChild( faqSec );
		}

		// Repeating card/list items (rooms, services, team members...). Only
		// when the clicked element sits in a set of >=2 structurally congruent,
		// contiguous siblings — see collectionUnitFor() in bridge.js. Mutually
		// exclusive with the QUESTIONS section above: FAQ keeps its own
		// dedicated path end to end, so a <details><summary> region never
		// offers both.
		if ( current.collection ) {
			var collectionUnit = current.collection;
			var collectionSec = el( 'div', 'cve-sec' );
			collectionSec.appendChild( el( 'div', 'cve-sec-title', 'ITEMS' ) );

			var openItems = el(
				'button',
				'cve-btn cve-btn-light cve-btn-block',
				'Edit items (' + collectionUnit.count + ')'
			);
			openItems.type = 'button';
			openItems.addEventListener( 'click', function () {
				openCollectionEditor( collectionUnit );
			} );
			collectionSec.appendChild( openItems );
			collectionSec.appendChild(
				el( 'p', 'cve-note', 'Edit, reorder, add and remove — all of them together, saved once.' )
			);

			panel.appendChild( collectionSec );
		}

		// Footer: delete · reset · Cancel / Save
		var foot = el( 'div', 'cve-foot' );
		var trash = el( 'button', 'cve-icon', '🗑' );
		trash.title = 'Delete element';
		trash.addEventListener( 'click', function () {
			postToFrame( { type: 'remove', id: current.id } );
			recordPatch( { id: current.id, kind: 'remove-element' } );
			closePanelSilent();
		} );
		var reset = el( 'button', 'cve-icon', '↺' );
		reset.title = 'Reset';
		reset.addEventListener( 'click', function () {
			postToFrame( { type: 'revert-style', id: current.id } );
			postToFrame( { type: 'revert-pseudo', id: current.id } );
			pendingStyles = {};
			pendingPseudo = {};
			var revertableKinds = { 'set-style': 1, 'set-pseudo': 1 };
			if ( current.kind === 'image' || current.kind === 'video' ) {
				// The bridge reverts the live src/poster/sources and posts back a
				// fresh 'select', which re-renders this panel with the original
				// media — mirrors the style revert above instead of leaving the
				// swapped file in place with no way back.
				postToFrame( { type: 'revert-media', id: current.id } );
				revertableKinds[ 'set-image' ] = 1;
				revertableKinds[ 'set-video' ] = 1;
			}
			for ( var i = patches.length - 1; i >= 0; i-- ) {
				if ( patches[ i ].id === current.id && revertableKinds[ patches[ i ].kind ] ) {
					patches.splice( i, 1 );
				}
			}
			setDirty();
		} );
		var cancel = el( 'button', 'cve-btn cve-btn-light', 'Cancel' );
		cancel.addEventListener( 'click', function () {
			closePanel( true );
		} );
		var save = el( 'button', 'cve-btn cve-btn-save', 'Save' );
		save.addEventListener( 'click', function () {
			if ( Object.keys( pendingStyles ).length ) {
				recordPatch( { id: current.id, kind: 'set-style', styles: Object.assign( {}, pendingStyles ) } );
				pendingStyles = {};
			}
			Object.keys( pendingPseudo ).forEach( function ( pseudo ) {
				if ( Object.keys( pendingPseudo[ pseudo ] ).length ) {
					recordPatch( { id: current.id, kind: 'set-pseudo', pseudo: pseudo, styles: Object.assign( {}, pendingPseudo[ pseudo ] ) } );
				}
			} );
			pendingPseudo = {};
			postToFrame( { type: 'finish-text', commit: true } );
			closePanelSilent();
		} );
		foot.appendChild( trash );
		foot.appendChild( reset );
		foot.appendChild( el( 'span', 'cve-flex' ) );
		foot.appendChild( cancel );
		foot.appendChild( save );
		panel.appendChild( foot );

		body.appendChild( panel );
		positionPanel( panel, target.rect );
		makeDraggable( panel, head );
	}

	function closePanelSilent() {
		if ( panel ) {
			panel.remove();
			panel = null;
		}
		current = null;
		pendingStyles = {};
		pendingPseudo = {};
	}

	// ---- Bridge messages ----

	window.addEventListener( 'message', function ( ev ) {
		var data = ev.data;
		if ( ! data || data.ns !== 'clara-ve' ) {
			return;
		}
		if ( data.type === 'select' ) {
			openPanel( data.target );
		} else if ( data.type === 'text-commit' ) {
			recordPatch( { id: data.id, kind: 'set-text', value: data.value } );
		} else if ( data.type === 'inner-commit' ) {
			recordPatch( { id: data.id, kind: 'set-inner-html', value: data.value } );
		} else if ( data.type === 'ornament-converted' ) {
			// The pseudo glyph is now a real <span> in the host's markup, and the
			// original pseudo is neutralised — persist both. A 'select' for the new
			// span follows so its glyph can be edited immediately.
			recordPatch( { id: data.id, kind: 'set-inner-html', value: data.hostInnerHtml } );
			recordPatch( { id: data.id, kind: 'set-pseudo', pseudo: data.pseudo, styles: { content: 'none' } } );
		} else if ( data.type === 'load-more' ) {
			// Raised whether or not edit mode is on, because "I pressed it and
			// nothing happened" is asked in both. Re-clicks replace the toast
			// rather than stacking copies of the same sentence.
			if ( loadMoreToast ) {
				loadMoreToast.remove();
			}
			loadMoreToast = showToast(
				'Load more works on the live site — press Preview to try it. Here it stays on the first page so editing it is safe.',
				null,
				6000
			);
		} else if ( data.type === 'background' ) {
			closePanelSilent();
		} else if ( data.type === 'navigate' ) {
			// A link was followed inside the preview. When it leads somewhere
			// the editor knows, switch to it properly — toolbar, page picker,
			// source and save target moving together. Otherwise just follow
			// it: most of a site's pages aren't visual-edit enabled, and
			// refusing to open them would make the menu feel broken. The frame
			// then lands on an ordinary page, which the lost-bridge banner
			// already explains, with a way back to what was being edited.
			var dest = visualPages.filter( function ( p ) {
				return samePagePath( p.url, data.href );
			} )[ 0 ];
			if ( dest ) {
				if ( dest.key !== currentKey ) {
					switchToKey( dest.key );
				}
			} else {
				// Load it AS AN EDIT PREVIEW rather than as a plain page. The
				// server decides what is editable, and it knows things this
				// list can't: every blog post resolves to the shared article
				// template, and there are as many of those URLs as there are
				// posts. If the destination turns out not to be editable the
				// bridge simply never boots and the lost-bridge banner explains
				// it, exactly as before — so this costs nothing when it misses.
				frame.src = editPreviewUrl( data.href );
			}
		} else if ( data.type === 'ready' ) {
			bridgeArrived();
			// The page that loaded may not be the key we thought we were on —
			// following a link to any article lands on the article template.
			// Adopt what the page reports instead of navigating again, which
			// would bounce the frame right back off the post the user opened.
			if ( data.pageKey && data.pageKey !== currentKey ) {
				adoptKey( data.pageKey );
			}
			// Which page the frame is ACTUALLY on, which is not always the
			// key's nominal preview URL: the article template is edited on
			// whichever post you opened, and there are as many of those as
			// there are posts.
			if ( data.url ) {
				currentFrameUrl = data.url;
			}
			// Every (re)load of the iframe — page switch, save-triggered
			// reload, restore — starts bridge.js fresh, which always boots
			// with edit mode OFF and waits right here to be told otherwise.
			// Without this, a freshly loaded page would silently stay OFF
			// even when the toggle button (and `editModeOn`) says ON, or —
			// the actual bug this fixes — the reverse: the toggle's "off"
			// icon would be trusted as ground truth while the newly loaded
			// frame quietly defaulted to its own ON state underneath it.
			postToFrame( { type: 'edit-mode', enabled: editModeOn } );
		}
	} );

	// ---- History panel (git-like: append-only, restore = new entry) ----

	var historyToggle = document.getElementById( 'clara-ve-history-toggle' );
	var historyPanel = document.getElementById( 'clara-ve-history' );
	var historyClose = document.getElementById( 'clara-ve-history-close' );
	var historyList = document.getElementById( 'clara-ve-history-list' );

	function historyAge( mysqlDate ) {
		// current_time('mysql') is site-local time in 'Y-m-d H:i:s' form; the
		// browser's local clock is treated as the same reference point.
		var then = new Date( mysqlDate.replace( ' ', 'T' ) );
		var mins = Math.floor( ( Date.now() - then.getTime() ) / 60000 );
		if ( mins < 1 ) {
			return 'now';
		}
		if ( mins < 60 ) {
			return mins + 'm';
		}
		var hours = Math.floor( mins / 60 );
		if ( hours < 24 ) {
			return hours + 'h';
		}
		return Math.floor( hours / 24 ) + 'd';
	}

	function renderHistory( entries ) {
		historyList.innerHTML = '';
		if ( ! entries || ! entries.length ) {
			historyList.appendChild( el( 'p', 'cve-note', 'No saves yet — click Save to start your history.' ) );
			return;
		}
		entries.forEach( function ( entry ) {
			var row = el( 'div', 'cve-history-row' );
			row.appendChild( el( 'span', 'cve-history-dot' + ( entry.isHead ? ' is-head' : '' ) ) );

			var main = el( 'div', 'cve-history-main' );

			var titleInput = document.createElement( 'input' );
			titleInput.type = 'text';
			titleInput.className = 'cve-history-title';
			titleInput.value = entry.message;
			titleInput.title = 'Click to rename';
			titleInput.addEventListener( 'change', function () {
				var value = titleInput.value.trim();
				window.wp
					.apiFetch( { path: '/clara-ve/v1/history/' + entry.id, method: 'PATCH', data: { message: value, key: currentKey } } )
					.then( function () {
						entry.message = value || entry.message;
					} )
					.catch( function () {
						titleInput.value = entry.message; // revert on failure
					} );
			} );
			main.appendChild( titleInput );

			main.appendChild( el( 'div', 'cve-history-meta', '#' + entry.id + ' · ' + historyAge( entry.createdAt ) + ' · ' + entry.hash ) );

			var restoreBtn = el( 'button', 'cve-btn cve-btn-light cve-history-restore', entry.isHead ? 'Current' : 'Restore' );
			restoreBtn.disabled = !! entry.isHead;
			restoreBtn.addEventListener( 'click', function () {
				restoreBtn.disabled = true;
				restoreBtn.textContent = 'Restoring…';
				window.wp
					.apiFetch( { path: '/clara-ve/v1/history/' + entry.id + '/restore', method: 'POST', data: { key: currentKey } } )
					.then( function ( res ) {
						source = res.source;
						patches = [];
						setDirty();
						closePanelSilent();
						frame.src = reloadUrlForCurrentKey(); // full reload — the whole page may differ
						renderHistory( res.history );
					} )
					.catch( function ( err ) {
						restoreBtn.disabled = false;
						restoreBtn.textContent = 'Restore';
						window.alert( 'Restore failed: ' + ( err.message || 'unknown error' ) );
					} );
			} );
			main.appendChild( restoreBtn );

			row.appendChild( main );
			historyList.appendChild( row );
		} );
	}

	function loadHistory() {
		historyList.innerHTML = '';
		historyList.appendChild( el( 'p', 'cve-note', 'Loading…' ) );
		window.wp
			.apiFetch( { path: '/clara-ve/v1/history?key=' + encodeURIComponent( currentKey ) } )
			.then( renderHistory )
			.catch( function ( err ) {
				historyList.innerHTML = '';
				historyList.appendChild( el( 'p', 'cve-note', 'Could not load history: ' + ( err.message || 'unknown error' ) ) );
			} );
	}

	function setHistoryOpen( open ) {
		if ( ! historyPanel || ! historyToggle ) {
			return;
		}
		historyPanel.classList.toggle( 'is-open', open );
		historyToggle.classList.toggle( 'is-active', open );
		historyToggle.setAttribute( 'aria-pressed', open ? 'true' : 'false' );
		if ( open ) {
			setSeoOpen( false ); // see setSeoOpen: only two docked panels fit
			loadHistory();
		}
	}

	if ( historyToggle ) {
		historyToggle.addEventListener( 'click', function () {
			setHistoryOpen( ! historyPanel.classList.contains( 'is-open' ) );
		} );
	}
	if ( historyClose ) {
		historyClose.addEventListener( 'click', function () {
			setHistoryOpen( false );
		} );
	}

	// ---- Search appearance (same slide-in mechanic as History) ----
	//
	// Exists because the owner of a converted site edits raw HTML by clicking,
	// never in Gutenberg — which is where an SEO plugin keeps its sidebar, and
	// which this editor's own takeover redirects them away from. Without a panel
	// here, the titles and descriptions the conversion carefully preserved are
	// somewhere they will never find.
	//
	// It is a write-through cache, not a competing store: a save writes our
	// record AND pushes to Yoast/Rank Math, and a load returns whatever is
	// actually live (so a value edited on their side shows up here). One visible
	// truth, and no "which of these two is right" dialog to design.

	var seoToggle = document.getElementById( 'clara-ve-seo-toggle' );
	var seoPanel = document.getElementById( 'clara-ve-seo' );
	var seoClose = document.getElementById( 'clara-ve-seo-close' );
	var seoBody = document.getElementById( 'clara-ve-seo-body' );
	var seoMediaFrame = null;

	// Guidance, not limits. Google truncates by pixel width, so these are the
	// widely-used character approximations and typing past them is allowed —
	// the counter just stops being reassuring.
	var SEO_TITLE_BUDGET = 60;
	var SEO_DESC_BUDGET = 155;

	function setSeoOpen( open ) {
		if ( ! seoPanel || ! seoToggle ) {
			return;
		}
		seoPanel.classList.toggle( 'is-open', open );
		seoToggle.classList.toggle( 'is-active', open );
		seoToggle.setAttribute( 'aria-pressed', open ? 'true' : 'false' );
		if ( open ) {
			// The docked panels are siblings of the canvas, each taking a fixed
			// 320px out of it. Two could coexist; a third cannot — on a 1440px
			// screen it leaves the preview under 500px wide, which is no longer a
			// preview of anything. So opening one closes the others. Only ever on
			// the way OPEN, which is also what keeps this from recursing.
			setHistoryOpen( false );
			setAiOpen( false );
			loadSeo();
		}
	}

	function loadSeo() {
		if ( ! seoBody ) {
			return;
		}
		seoBody.innerHTML = '';
		seoBody.appendChild( el( 'p', 'cve-note', 'Loading…' ) );
		window.wp
			.apiFetch( { path: '/clara-ve/v1/seo?key=' + encodeURIComponent( currentKey ) } )
			.then( renderSeo )
			.catch( function ( err ) {
				seoBody.innerHTML = '';
				seoBody.appendChild( el( 'p', 'cve-note', 'Could not load: ' + ( err.message || 'unknown error' ) ) );
			} );
	}

	/** A labelled field with a live character counter. */
	function seoField( labelText, control, budget ) {
		var wrap = el( 'div', 'cve-seo-field' );
		var label = el( 'label', null, labelText );
		var count = null;
		if ( budget ) {
			count = el( 'span', 'cve-seo-count' );
			label.appendChild( count );
		}
		wrap.appendChild( label );
		wrap.appendChild( control );

		if ( count ) {
			var sync = function () {
				var n = control.value.length;
				count.textContent = n + ' / ' + budget;
				count.classList.toggle( 'is-over', n > budget );
			};
			control.addEventListener( 'input', sync );
			sync();
		}
		return wrap;
	}

	function renderSeo( data ) {
		seoBody.innerHTML = '';

		if ( ! data.editable ) {
			seoBody.appendChild( el( 'p', 'cve-note', data.reason || 'This page has no search appearance of its own.' ) );
			return;
		}

		// --- preview -------------------------------------------------------
		var preview = el( 'div', 'cve-seo-preview' );
		var pUrl = el( 'div', 'cve-seo-preview-url', ( data.permalink || '' ).replace( /^https?:\/\//, '' ) );
		var pTitle = el( 'div', 'cve-seo-preview-title' );
		var pDesc = el( 'div', 'cve-seo-preview-desc' );
		preview.appendChild( pUrl );
		preview.appendChild( pTitle );
		preview.appendChild( pDesc );
		seoBody.appendChild( preview );

		// --- fields --------------------------------------------------------
		var titleInput = el( 'input' );
		titleInput.type = 'text';
		titleInput.value = data.title || '';
		titleInput.placeholder = data.fallbackTitle || '';

		var descInput = el( 'textarea' );
		descInput.rows = 3;
		descInput.value = data.description || '';
		descInput.placeholder = 'What this page is about, in one sentence.';

		seoBody.appendChild( seoField( 'Title', titleInput, SEO_TITLE_BUDGET ) );
		seoBody.appendChild( seoField( 'Description', descInput, SEO_DESC_BUDGET ) );

		var syncPreview = function () {
			// The placeholder is what WordPress would actually emit when the
			// field is blank, so the preview shows that rather than an empty line.
			pTitle.textContent = titleInput.value || data.fallbackTitle || '';
			pDesc.textContent = descInput.value || 'No description yet — search engines will pick a sentence from the page.';
		};
		titleInput.addEventListener( 'input', syncPreview );
		descInput.addEventListener( 'input', syncPreview );
		syncPreview();

		// --- social image --------------------------------------------------
		var imageUrl = data.ogImage || '';
		var imageField = el( 'div', 'cve-seo-field' );
		imageField.appendChild( el( 'label', null, 'Sharing image' ) );
		var thumb = el( 'img', 'cve-seo-thumb' );
		var actions = el( 'div', 'cve-seo-actions' );
		var pickBtn = el( 'button', 'cve-btn cve-btn-light' );
		pickBtn.type = 'button';
		var clearBtn = el( 'button', 'cve-btn cve-btn-light', 'Remove' );
		clearBtn.type = 'button';

		var syncImage = function () {
			if ( imageUrl ) {
				thumb.src = imageUrl;
				thumb.hidden = false;
				clearBtn.hidden = false;
				pickBtn.textContent = 'Replace';
			} else {
				thumb.removeAttribute( 'src' );
				thumb.hidden = true;
				clearBtn.hidden = true;
				pickBtn.textContent = 'Choose image';
			}
		};

		pickBtn.addEventListener( 'click', function () {
			// wp_enqueue_media() already runs for this screen, so the library
			// frame is available without loading anything else.
			if ( ! seoMediaFrame ) {
				seoMediaFrame = window.wp.media( {
					title: 'Sharing image',
					library: { type: 'image' },
					button: { text: 'Use this image' },
					multiple: false,
				} );
				seoMediaFrame.on( 'select', function () {
					var picked = seoMediaFrame.state().get( 'selection' ).first();
					if ( picked ) {
						imageUrl = picked.get( 'url' );
						syncImage();
					}
				} );
			}
			seoMediaFrame.open();
		} );
		clearBtn.addEventListener( 'click', function () {
			imageUrl = '';
			syncImage();
		} );

		actions.appendChild( pickBtn );
		actions.appendChild( clearBtn );
		imageField.appendChild( thumb );
		imageField.appendChild( actions );
		seoBody.appendChild( imageField );
		syncImage();

		// --- hide from search ----------------------------------------------
		var noindexWrap = el( 'div', 'cve-seo-field' );
		var noindexLabel = el( 'label', 'cve-seo-check' );
		var noindexInput = el( 'input' );
		noindexInput.type = 'checkbox';
		noindexInput.checked = !! data.noindex;
		noindexLabel.appendChild( noindexInput );
		noindexLabel.appendChild( el( 'span', null, 'Hide this page from search engines' ) );
		noindexWrap.appendChild( noindexLabel );
		seoBody.appendChild( noindexWrap );

		// --- save ----------------------------------------------------------
		var saveBtn = el( 'button', 'cve-btn cve-btn-save cve-btn-block', 'Save search appearance' );
		saveBtn.type = 'button';
		saveBtn.addEventListener( 'click', function () {
			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving…';
			window.wp
				.apiFetch( {
					path: '/clara-ve/v1/seo',
					method: 'POST',
					data: {
						key: currentKey,
						title: titleInput.value,
						description: descInput.value,
						ogImage: imageUrl,
						noindex: noindexInput.checked,
					},
				} )
				.then( function () {
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save search appearance';
					statusEl.textContent = 'Search appearance saved ✓';
					window.setTimeout( function () {
						if ( 'Search appearance saved ✓' === statusEl.textContent ) {
							statusEl.textContent = '';
						}
					}, 2500 );
				} )
				.catch( function ( err ) {
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save search appearance';
					window.alert( 'Could not save: ' + ( err.message || 'unknown error' ) );
				} );
		} );
		seoBody.appendChild( saveBtn );

		// --- the other place these values live -----------------------------
		if ( data.hostLabel ) {
			var note = el( 'p', 'cve-seo-host' );
			note.appendChild( document.createTextNode( data.hostLabel + ' is installed, so these are saved into it too. ' ) );
			if ( data.hostEditUrl ) {
				var link = el( 'a', null, 'Open its own settings for this page' );
				link.href = data.hostEditUrl;
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				note.appendChild( link );
				note.appendChild( document.createTextNode( '.' ) );
			}
			seoBody.appendChild( note );
		}
	}

	if ( seoToggle ) {
		seoToggle.addEventListener( 'click', function () {
			setSeoOpen( ! seoPanel.classList.contains( 'is-open' ) );
		} );
	}
	if ( seoClose ) {
		seoClose.addEventListener( 'click', function () {
			setSeoOpen( false );
		} );
	}

	// ---- Toolbar ----

	if ( previewBtn ) {
		previewBtn.addEventListener( 'click', function () {
			window.open( liveUrlForKey( currentKey ), '_blank', 'noopener' );
		} );
	}

	if ( toggleBtn ) {
		toggleBtn.addEventListener( 'click', function () {
			editModeOn = ! editModeOn;
			toggleBtn.classList.toggle( 'is-off', ! editModeOn );
			toggleBtn.setAttribute( 'aria-pressed', editModeOn ? 'true' : 'false' );
			closePanelSilent();
			postToFrame( { type: 'edit-mode', enabled: editModeOn } );
		} );
	}

	saveBtn.addEventListener( 'click', function () {
		postToFrame( { type: 'finish-text', commit: true } );
		window.setTimeout( function () {
			if ( current && Object.keys( pendingStyles ).length ) {
				recordPatch( { id: current.id, kind: 'set-style', styles: Object.assign( {}, pendingStyles ) } );
				pendingStyles = {};
			}
			var result = patchedSource();
			if ( ! result.ok ) {
				statusEl.textContent = 'Error: ' + result.error;
				return;
			}
			// Every other patch kind is already reflected in the preview —
			// bridge.js applies text, images, styles and the rest to the live
			// DOM as they are made. A [wp-form] token is the exception: what it
			// turns into (the hidden fields, the rewritten action) is decided
			// server-side at render, so the frame goes on showing the pre-save
			// form until it is fetched again. Noted before the promise clears
			// `patches`.
			var needsRerender = patches.some( function ( p ) {
				return 'set-form-token' === p.kind;
			} );
			saveBtn.disabled = true;
			statusEl.textContent = 'Saving…';
			var pseudoPatches = patches
				.filter( function ( p ) {
					return p.kind === 'set-pseudo';
				} )
				.map( function ( p ) {
					return { id: p.id, pseudo: p.pseudo || 'before', styles: p.styles };
				} );
			window.wp
				.apiFetch( { path: '/clara-ve/v1/source', method: 'POST', data: { key: currentKey, source: result.source, pseudo: pseudoPatches } } )
				.then( function () {
					source = result.source;
					patches = [];
					setDirty();
					statusEl.textContent = 'Saved ✓';
					if ( historyPanel && historyPanel.classList.contains( 'is-open' ) ) {
						loadHistory(); // this save just landed a new entry — show it
					}
					if ( needsRerender ) {
						closePanelSilent(); // its target is about to be replaced
						frame.src = reloadUrlForCurrentKey();
					}
					window.setTimeout( function () {
						statusEl.textContent = '';
					}, 2500 );
				} )
				.catch( function ( err ) {
					statusEl.textContent = 'Save failed: ' + ( err.message || 'unknown' );
					setDirty();
				} );
		}, 80 );
	} );

	discardBtn.addEventListener( 'click', function () {
		patches = [];
		closePanelSilent();
		setDirty();
		frame.src = reloadUrlForCurrentKey();
	} );

	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( patches.length ) {
			ev.preventDefault();
			ev.returnValue = '';
		}
	} );
} )();
