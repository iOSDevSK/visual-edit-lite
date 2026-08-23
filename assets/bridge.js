/**
 * In-page edit bridge, ported from Design Ready Editor (open-design)
 * apps/web/src/edit-mode/bridge.ts and adapted for WordPress.
 *
 * - Runs as the FIRST deferred script: stamps `data-cve-path` (source path
 *   indexes) and `data-cve-kind` on the pristine DOM before the page's own
 *   scripts mutate it. Paths are relative to the element whose children map
 *   1:1 onto the stored source's body children. WordPress can split a block
 *   template so the front-page source becomes a sibling of `.wp-site-blocks`;
 *   that valid shape falls back to `<body>` while excluding generated chrome.
 * - Editable kinds (link / image / text leaf) carry permanent dashed
 *   outlines in edit mode, exactly like open-design's guide overlay.
 * - Click selects (solid outline) and reports the target incl. computed
 *   typography; text/links also get an inline plaintext caret.
 * - The host previews style changes live (`preview-style`), can revert to
 *   the session-start inline style (`revert-style`), swap images/links, and
 *   remove elements. Nothing is persisted here.
 */
( function () {
	'use strict';

	var config = window.claraVeBridgeConfig || {};
	// Server-computed per page. A tagged WP Page owns only its content area;
	// shared header/footer template parts must never be walked into. The front
	// page normally maps to `.wp-site-blocks`, but WordPress may close that
	// wrapper after the first template part and render the pattern as body-level
	// siblings. The root resolution below handles both valid DOM shapes.
	var ROOT_SELECTOR = config.rootSelector || '.wp-site-blocks';
	var PATH_ATTR = 'data-cve-path';
	var KIND_ATTR = 'data-cve-kind';
	// Menu-managed zones come from the THEME's declared contract, relayed by
	// the server per page ([ { selector, location } ], assigned locations
	// only). This used to be one specific site's selectors hardcoded here,
	// which made menu management silently dead on every other theme. Empty
	// when the theme declares nothing — then no element is ever a menu zone.
	var MENU_ZONE_LIST = config.menuZones || [];
	var MENU_ZONES = MENU_ZONE_LIST.map( function ( z ) {
		return z.selector;
	} ).join( ', ' );
	var ZONE_ATTR = 'data-cve-zone';
	var SKIP_ATTR = 'data-cve-skip';
	// Custom Content Collections (generic repeating card/list detector, see
	// collectionUnitFor below). Attribute diffs are only ever read for these
	// four — anything else differing between candidate siblings (class,
	// style, id, data-*) means they are not congruent, full stop. This is
	// what keeps a variant/featured card (an extra class) from being merged
	// into a reorder that would silently strip what makes it different.
	var COLLECTION_ATTR_WHITELIST = { src: 1, srcset: 1, href: 1, alt: 1 };
	// Attributes that may differ between members WITHOUT making them different
	// shapes. Two kinds, both of which every repeated widget produces by
	// construction:
	//
	//   plumbing  — the generated ids that wire a control to the panel it
	//               opens. Unique per instance is the entire point of them.
	//   state     — which item happens to be open, focused or hidden right now.
	//
	// Without this, no accordion, tab set or disclosure list on any component
	// library could ever be recognised as a collection: the congruence check
	// treats an unknown differing attribute as "opaque" and bails, so the whole
	// list is rejected. Verified live on a converted site whose five FAQ items
	// were byte-identical apart from `id="radix-_R_5klfiv5b_"` versus
	// `id="radix-_R_9klfiv5b_"` — the owner got no "manage as a list" panel at
	// all, only a click-to-edit box per question.
	//
	// Safe because none of these carries editorial content: they are never what
	// an owner is editing, and the slot diff below still reads every attribute
	// that is (src, href, alt, and text).
	var COLLECTION_ATTR_IGNORE = {
		id: 1, 'aria-controls': 1, 'aria-labelledby': 1, 'aria-describedby': 1, for: 1,
		'aria-expanded': 1, 'aria-selected': 1, 'aria-hidden': 1, 'data-state': 1,
		hidden: 1, tabindex: 1,
		// Some themes mark the first card in a dark section with this render
		// state attribute while the remaining cards inherit the same appearance
		// from their parent. It is not item content or structure; treating its
		// presence as a design difference rejects the whole repeated section.
		'data-dark': 1,
		// The OPEN item of an accordion/tab set carries this and the closed
		// ones do not -- same family as aria-expanded, and leaving it out
		// made the widget's own state look like a design difference.
		'aria-disabled': 1,
		// WordPress itself injects these onto rendered images ASYMMETRICALLY
		// (wp_filter_content_tags: fetchpriority="high" on the page's first
		// image, loading="lazy" only past its omit threshold) — so on any
		// live page, sibling images that are byte-identical in the stored
		// source legitimately differ by exactly these. Not editorial, not
		// authored: pure render-pipeline decoration. Without ignoring them,
		// an image row of 4+ items, or any group containing the page's
		// first image, silently loses its "manage as a list" panel — found
		// by the conversion gate comparing dist detection against live.
		loading: 1, decoding: 1, fetchpriority: 1,
		// Animation stagger: a reveal library (AOS and kin) gives every
		// member of a staggered list its OWN delay/duration by design —
		// card 1 at 0ms, card 2 at 100ms, card 3 at 200ms. The values
		// differ on every member of a group that is one design, so
		// comparing them verbatim rejects exactly the lists the stagger
		// was applied to (creative-013).
		'data-aos-delay': 1, 'data-aos-duration': 1,
		// Frozen carousel bookkeeping: a static export snapshots whatever
		// slide index the runtime had assigned at capture time (dexler-014).
		// Which slide a member WAS is state, not design.
		'data-swiper-slide-index': 1,
		// The same stagger, hand-rolled. A site with no reveal library writes
		// its own index -- nothing on the first member, then 1, 2, 3 -- and
		// styles the delay from it (`[data-d="2"] { transition-delay: .2s }`).
		// It is the AOS case with a shorter name, and it cost a whole site:
		// on one photographer's pages EVERY repeated section was rejected by
		// it at once -- service cards, process steps, add-ons, testimonials --
		// so the owner would have been offered none of them.
		'data-d': 1,
		// Image geometry and responsive plumbing. The same family as
		// loading/decoding/fetchpriority above, arriving from the other
		// direction: a performance pass (or a careful author) writes each
		// image's intrinsic width/height, and `sizes` only onto the images
		// that got a srcset variant -- so a film strip mixing portrait and
		// landscape photographs, some with variants and some without, has
		// members differing by exactly these three. None is item content:
		// what the owner edits is src and alt, which the slot diff reads
		// through the whitelist. Verified on a photographer's 18-frame
		// marquee -- every element carried a valid edit path and the group
		// was offered to nobody, because 1024x1536 beside 1536x1024 read
		// as two designs.
		width: 1, height: 1, sizes: 1,
	};
	// More than this many editable fields means the matched containers are
	// loosely-similar page sections, not a card collection -- bail rather
	// than offer a popup nobody asked for.
	var MAX_COLLECTION_SLOTS = 8;
	var root = document.querySelector( ROOT_SELECTOR );
	if ( ! root ) {
		return;
	}
	// A block template is allowed to close `.wp-site-blocks` after a template
	// part and emit the following raw HTML beside it. On that front-page shape,
	// the configured wrapper contains only shared chrome and none of this key's
	// source. Use body as the physical root, then omit the generated siblings in
	// sourceExternalChild() so paths still address the stored source exactly.
	var detachedFrontSiteBlocks = null;
	if ( 'front-page' === config.pageKey && root.classList && root.classList.contains( 'wp-site-blocks' ) ) {
		var hasFrontSourceChild = false;
		for ( var rootChildIndex = 0; rootChildIndex < root.children.length; rootChildIndex++ ) {
			if ( ! root.children[ rootChildIndex ].classList.contains( 'wp-block-template-part' ) ) {
				hasFrontSourceChild = true;
				break;
			}
		}
		if ( ! hasFrontSourceChild ) {
			detachedFrontSiteBlocks = root;
			root = document.body;
		}
	}
	// A shared template part is deliberately excluded from a page key's source
	// paths. A menu item inside it is still an independently managed WordPress
	// menu item, though: give it an iframe-local path only so the menu panel can
	// live-update the clicked link after saving. It is never persisted as page
	// source (menuZone returns from the host panel before any source patch).
	var transientMenuPath = 0;

	// Off until the host explicitly says otherwise (the 'ready' message below
	// is the host's cue to reply with the CURRENT toggle state) — every fresh
	// load of this script (first open, page switch, save-triggered reload,
	// restore) must come up in edit mode OFF by default, never silently ON.
	var enabled = false;

	function isTextLeaf( el ) {
		var text = ( el.textContent || '' ).trim();
		return !! text && el.children.length === 0;
	}

	// Inline formatting that may appear INSIDE an editable text block without
	// demoting it to a container. A heading like
	// `<h2>Choose the <em>support</em> your social needs.</h2>` stays fully
	// editable; the commit round-trips as innerHTML so the markup survives.
	var INLINE_TAGS = { em: 1, strong: 1, b: 1, i: 1, span: 1, br: 1, small: 1, mark: 1, sup: 1, sub: 1, a: 1 };

	function isRichTextBlock( el ) {
		if ( ! ( el.textContent || '' ).trim() || ! el.children.length ) {
			return false;
		}
		var descendants = el.querySelectorAll( '*' );
		for ( var i = 0; i < descendants.length; i++ ) {
			if ( ! INLINE_TAGS[ descendants[ i ].tagName.toLowerCase() ] ) {
				return false;
			}
		}
		return true;
	}

	function inferKind( el ) {
		var tag = el.tagName ? el.tagName.toLowerCase() : '';
		if ( tag === 'a' ) {
			return 'link';
		}
		if ( tag === 'img' ) {
			return 'image';
		}
		if ( tag === 'video' ) {
			return 'video';
		}
		if ( isTextLeaf( el ) || isRichTextBlock( el ) ) {
			return 'text';
		}
		return 'container';
	}

	/**
	 * The stamped source path of the form an element belongs to, or ''.
	 *
	 * A form zone counts as its own form: the token hydrates into one element
	 * standing exactly where the <form> stands in the source, so the zone's own
	 * path is the form's path.
	 */
	function formPathFor( el ) {
		var form = 'form' === ( el.getAttribute( 'data-cve-zone' ) || '' ) ? el : ( el.closest ? el.closest( 'form' ) : null );
		if ( ! form ) {
			return '';
		}
		// Whichever of the two actually carries the path. A posts/menu/article
		// zone still holds it, so it wins; a FORM zone's wrapper has yielded
		// its slot to the <form> (see sourceExternalChild), so there the form
		// carries it and preferring the wrapper returns nothing.
		//
		// That is not hypothetical: preferring the wrapper unconditionally is
		// what silently emptied every form's connection panel the moment the
		// wrapper stopped being stamped — the settings were still there, the
		// path to reach them was ''.
		var zone = form.closest ? form.closest( '[' + SKIP_ATTR + ']' ) : null;
		var zonePath = zone ? ( zone.getAttribute( PATH_ATTR ) || '' ) : '';
		return zonePath || form.getAttribute( PATH_ATTR ) || '';
	}

	// ---- Source-path + kind stamping (pristine DOM === parsed source DOM) ----
	// Set (or refresh) an element's kind/rich flags from its live shape.
	function applyKind( el ) {
		el.removeAttribute( KIND_ATTR );
		el.removeAttribute( 'data-cve-rich' );
		var kind = inferKind( el );
		if ( kind !== 'container' ) {
			el.setAttribute( KIND_ATTR, kind );
			if ( kind === 'text' && el.children.length ) {
				el.setAttribute( 'data-cve-rich', '1' );
			}
		}
	}

	// script/style/template elements must never be stamped, selected, or
	// recursed into — isTextLeaf() would otherwise misclassify them as
	// editable text (no element children + non-empty textContent). Skipping
	// the stamp (not the loop index) keeps positional paths consistent with
	// editor.js's findByPath(), which walks the parsed *source* string's own
	// children collection — a <script> tag still occupies a slot there too,
	// so leaving `i` unaffected is what keeps the two sides of the path in
	// sync, not excluding the element from the count.
	var UNSTAMPED_TAGS = { script: 1, style: 1, template: 1 };

	// An element the rendered WordPress document adds around/beside the stored
	// source. It occupies a physical DOM slot, but no slot in editor.js's parsed
	// source string, so it must be skipped without advancing the logical index.
	// The hidden fields the form runtime appends (class-tokens.php) — they are
	// in the rendered DOM and in no stored source, so they must not take a
	// path. A closed list rather than "any hidden input", because a design's
	// OWN hidden input does exist in the source and removing its slot would
	// shift every sibling after it.
	var FORM_RUNTIME_FIELDS = {
		clara_ve_nonce: 1, form_id: 1, to: 1, redirect: 1,
		form_type: 1, list_id: 1, cve_ts: 1,
	};

	function sourceExternalChild( child, parent ) {
		if ( child.classList && child.classList.contains( 'wp-block-template-part' ) ) {
			return true;
		}
		// A form zone's wrapper stands exactly where the <form> stands in the
		// stored source, so the two cannot both hold the slot. The wrapper
		// yields it: the form takes the path, and its interior — labels,
		// button text, the small print — becomes addressable and therefore
		// editable. Only for FORMS. A posts zone renders its one stored card
		// template N times, so there is no single source element to align an
		// interior path against, and it keeps the slot as before.
		if ( 'form' === ( child.getAttribute && child.getAttribute( ZONE_ATTR ) ) ) {
			return true;
		}
		if (
			child.tagName === 'INPUT' && child.type === 'hidden' &&
			FORM_RUNTIME_FIELDS[ child.name ] && child.closest( '[' + ZONE_ATTR + '="form"]' )
		) {
			return true;
		}
		if ( parent === document.body && detachedFrontSiteBlocks ) {
			if ( child === detachedFrontSiteBlocks ) {
				return true;
			}
			if ( child.matches && child.matches( 'a.skip-link.screen-reader-text' ) ) {
				return true;
			}
		}
		return false;
	}

	// Stamp a subtree's children with positional paths + kinds. Re-runnable, so
	// injecting a real ornament element can renumber the shifted siblings.
	//
	// A token-hydrated zone ([data-cve-skip], see includes/class-tokens.php)
	// is stamped like any other element — it's still one sibling slot,
	// selectable for its own hint panel — but its children are never
	// recursed into: in the STORED source the whole zone is a single text
	// node (the token), so descending into its hydrated markup would stamp
	// paths that don't exist on the source side at all.
	function stampSubtree( el, path, startIndex ) {
		var children = el.children;
		var sourceIndex = startIndex || 0;
		for ( var i = 0; i < children.length; i++ ) {
			var child = children[ i ];
			if ( sourceExternalChild( child, el ) ) {
				// A yielded form wrapper is transparent, not absent: its own
				// children are stamped as if they were this element's, so the
				// <form> lands on the slot the wrapper gave up.
				if ( 'form' === ( child.getAttribute && child.getAttribute( ZONE_ATTR ) ) ) {
					stampSubtree( child, path, sourceIndex );
					sourceIndex += child.children.length;
				}
				continue;
			}
			var childPath = path.length ? path + '-' + sourceIndex : String( sourceIndex );
			sourceIndex++;
			if ( UNSTAMPED_TAGS[ child.tagName.toLowerCase() ] ) {
				continue;
			}
			child.setAttribute( PATH_ATTR, 'path-' + childPath );
			// A pristine-class snapshot, taken once (never overwritten by a
			// later re-stamp — a cloneNode(true) copies it along for free).
			// shapeSignature() reads THIS, not the live className: this is the
			// first deferred script specifically so it stamps before the
			// page's own scripts run, but collectionUnitFor() is computed
			// fresh on every click, well after the page's own scroll-reveal
			// script (classList.add('in') on IntersectionObserver, see
			// front-page.js) has had time to mutate SOME but not all of a
			// card grid's siblings — which would otherwise make identical
			// cards look structurally different depending on which the
			// visitor happened to scroll past first.
			if ( ! child.hasAttribute( 'data-cve-class' ) ) {
				child.setAttribute( 'data-cve-class', child.className || '' );
			}
			applyKind( child );
			if ( child.hasAttribute( SKIP_ATTR ) ) {
				continue;
			}
			stampSubtree( child, childPath );
		}
	}
	// On a block page the addresses already exist: the server put them on the
	// elements at render time, computed from the parsed block tree, because
	// there is no walk of the rendered DOM that could find them. A Query Loop
	// is ONE stored block rendering N cards — the positional walk below would
	// happily stamp the third card, and a save against that path would rewrite
	// the template every card is drawn from.
	//
	// So the walk is skipped entirely and the server's addresses are adopted
	// under the attribute the rest of this file already reads. Everything with
	// no address is invisible to selection, which is the point: unstamped
	// means uneditable, by construction rather than by a check.
	//
	// The address goes on the element the panel should actually edit, which is
	// not always the element the block wraps: a button's words live in its <a>,
	// a picture in its <img>. The block's own address travels with them, so a
	// patch still names the block and the server still knows which part of it
	// this operation touches.
	function adoptServerAddresses() {
		var blocks = document.querySelectorAll( '[data-ve-block]' );
		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];
			var address = block.getAttribute( 'data-ve-block' );
			var capability = block.getAttribute( 'data-ve-edit' ) || 'none';
			var target = block;

			if ( 'image' === capability ) {
				target = block.querySelector( 'img' ) || block;
			} else if ( 'button' === capability ) {
				target = block.querySelector( 'a' ) || block;
			} else if ( 'details' === capability ) {
				// The question is the <summary>; the answer under it is child
				// blocks, each addressed in its own right.
				target = block.querySelector( 'summary' ) || block;
			} else if ( 'section' === capability || 'spacer' === capability || 'style-lite' === capability ) {
				// The element itself. These have no words of their own — what
				// they offer is styling, and the panel reads the capability to
				// know not to show a text field.
				target = block;
			} else if ( 'text' !== capability ) {
				// Read-only. It keeps its data-ve-edit="none" for anything that
				// wants to explain why, but nothing here can address it.
				continue;
			}

			target.setAttribute( PATH_ATTR, 'path-' + address );
			if ( ! target.hasAttribute( 'data-cve-class' ) ) {
				target.setAttribute( 'data-cve-class', target.className || '' );
			}
			applyKind( target );
		}
	}

	if ( config.blockMode ) {
		adoptServerAddresses();
	} else {
		stampSubtree( root, '' );
	}

	function pathOf( el ) {
		return el.getAttribute( PATH_ATTR ) || '';
	}

	// The numeric path (no `path-` prefix) — the base for re-stamping children.
	function basePathOf( el ) {
		var p = pathOf( el );
		return p.indexOf( 'path-' ) === 0 ? p.slice( 5 ) : p;
	}

	// Any key can host a declared zone — a footer menu lives in the footer
	// part's canvas, a self-contained front page carries every zone inline —
	// so membership is decided purely by the selectors: markup the theme
	// declared as a menu, with a menu assigned, is menu-managed wherever it
	// renders. Hand-editing such markup in the canvas would be silently
	// overwritten on the live site, which is why it must report the
	// menu-item panel instead of offering normal text/link editing.
	function inMenuZone( el ) {
		return !! ( MENU_ZONES && config.menuManaged && el.closest && el.closest( MENU_ZONES ) );
	}

	// Which declared zone (and so which WP menu location) the element sits
	// in — the host passes this to the menu-item endpoint so the edit lands
	// in the right menu when a site has several.
	function menuLocationFor( el ) {
		if ( ! el.closest ) {
			return '';
		}
		for ( var i = 0; i < MENU_ZONE_LIST.length; i++ ) {
			if ( el.closest( MENU_ZONE_LIST[ i ].selector ) ) {
				return MENU_ZONE_LIST[ i ].location || '';
			}
		}
		return '';
	}

	// 'posts' | 'form' | 'menu' | null — from the closest token-hydrated zone,
	// falling back to the legacy hardcoded-nav check (which predates
	// data-cve-zone and has no such marker of its own) so both report 'menu'.
	function zoneOf( el ) {
		var skipEl = el.closest && el.closest( '[' + SKIP_ATTR + ']' );
		if ( skipEl ) {
			return skipEl.getAttribute( ZONE_ATTR ) || null;
		}
		return inMenuZone( el ) ? 'menu' : null;
	}

	/**
	 * The kind of zone an element CONTAINS, as opposed to one it sits inside.
	 *
	 * zoneOf() looks upward and answers for the element's own context; this
	 * looks down. A container that holds a hydrated zone is style-only —
	 * holdsGeneratedField() decides that — but until now the panel could not
	 * say WHICH zone it held, so every one of them was explained with the
	 * article wording: "the words here come from the post, so they change with
	 * every article". Clicked on a box holding a contact form, that is simply
	 * untrue — the labels are the design's own words, in the stored source,
	 * and no post exists anywhere near them.
	 */
	function heldZoneOf( el ) {
		var skipEl = el.querySelector && el.querySelector( '[' + SKIP_ATTR + ']' );
		return skipEl ? ( skipEl.getAttribute( ZONE_ATTR ) || null ) : null;
	}

	function rgbToHex( value ) {
		var m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/.exec( value || '' );
		if ( ! m ) {
			return value || '';
		}
		function hex( n ) {
			return ( '0' + parseInt( n, 10 ).toString( 16 ) ).slice( -2 );
		}
		return '#' + hex( m[ 1 ] ) + hex( m[ 2 ] ) + hex( m[ 3 ] );
	}

	function bgToHex( value ) {
		var m = /rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*([\d.]+))?\s*\)/.exec( value || '' );
		if ( m && m[ 1 ] !== undefined && parseFloat( m[ 1 ] ) === 0 ) {
			return ''; // fully transparent — show as unset
		}
		return rgbToHex( value );
	}

	function stylesFor( el ) {
		var computed = window.getComputedStyle( el );
		var spacing = computed.letterSpacing === 'normal' ? '0px' : computed.letterSpacing;
		return {
			fontFamily: ( computed.fontFamily || '' ).split( ',' )[ 0 ].replace( /["']/g, '' ).trim(),
			fontSize: computed.fontSize,
			fontWeight: computed.fontWeight,
			fontStyle: computed.fontStyle,
			// First value only: "underline solid rgb(...)" — the panel toggle
			// cares whether an underline exists, not how it is painted.
			textDecorationLine: ( computed.textDecorationLine || '' ).split( ' ' )[ 0 ],
			verticalAlign: computed.verticalAlign,
			color: rgbToHex( computed.color ),
			textAlign: computed.textAlign === 'start' ? 'left' : computed.textAlign,
			lineHeight: computed.lineHeight === 'normal' ? '' : computed.lineHeight,
			letterSpacing: spacing,
			backgroundColor: bgToHex( computed.backgroundColor ),
			opacity: computed.opacity,
			paddingTop: computed.paddingTop,
			paddingBottom: computed.paddingBottom,
			paddingLeft: computed.paddingLeft,
			paddingRight: computed.paddingRight,
			marginTop: computed.marginTop,
			marginBottom: computed.marginBottom,
			marginLeft: computed.marginLeft,
			marginRight: computed.marginRight,
			borderRadius: computed.borderRadius,
			// The three the BORDER panel needs, reported per VISIBLE side.
			//
			// A form field in this design is a line under the text and nothing
			// else, so the four sides differ and the shorthand computes to
			// "0px 0px 1px 0px" — truthy, parsed as 0, and the panel said the
			// field had no border while a 1px line sat under it. So: if the
			// sides agree, that value; if they do not, the side that is
			// actually drawn, which is the one a person means.
			borderWidth: visibleBorder( computed, 'Width' ),
			borderStyle: visibleBorder( computed, 'Style' ),
			borderColor: rgbToHex( visibleBorder( computed, 'Color' ) ),
			width: computed.width,
			height: computed.height,
			display: computed.display,
			flexDirection: computed.flexDirection,
			justifyContent: computed.justifyContent === 'normal' ? 'flex-start' : computed.justifyContent,
			alignItems: computed.alignItems === 'normal' ? 'stretch' : computed.alignItems,
			gap: computed.gap === 'normal' ? '0px' : computed.gap,
		};
	}

	// True when the element's own content is generated — an <h1> around the
	// article title, the frame around the featured image. Such a box is fully
	// STYLEABLE (that is the entire point of an editable template) but its text
	// must never be typed over: the next article would overwrite it, and saving
	// would replace the token with one post's words for good.
	function holdsGeneratedField( el ) {
		return !! ( el.querySelector && el.querySelector( '[' + SKIP_ATTR + ']' ) );
	}

	/**
	 * The repeatable question-and-answer unit the given element sits inside.
	 *
	 * Found by SHAPE, never by class name. A converted site's FAQ carries
	 * whatever classes its designer chose — this one uses details.faq-item, the
	 * next will use something else entirely — so matching on "faq" in a class
	 * would work here and nowhere else. What is reliable is the structure: a
	 * <details> holding a <summary>, or a heading followed by prose, repeated as
	 * siblings.
	 *
	 * The repetition is the point. A lone <details> is a disclosure widget, not
	 * an FAQ, and offering to duplicate it would be offering to make a mess. Two
	 * or more of the same shape side by side is a list, and a list is the thing
	 * a person means when they say "add another question".
	 *
	 * @param {Element} el
	 * @return {Object|null} { path, questionSelector, answerSelector, count }
	 */
	function faqUnitFor( el ) {
		var node = el;
		while ( node && node !== document.body ) {
			var shape = faqShapeOf( node );
			if ( shape ) {
				var siblings = 0;
				var kids = node.parentElement ? node.parentElement.children : [];
				for ( var i = 0; i < kids.length; i++ ) {
					if ( faqShapeOf( kids[ i ] ) ) {
						siblings++;
					}
				}
				if ( siblings >= 2 ) {
					// The LIST is what gets addressed, not the clicked item. All
					// of the editing — rewording, reordering, adding, removing —
					// happens to the set at once and is written back as one
					// change, so a path to a single item would be the wrong
					// handle and would go stale the moment anything moved.
					var listPath = pathOf( node.parentElement );
					if ( listPath ) {
						var items = [];
						for ( var k = 0; k < kids.length; k++ ) {
							var kidShape = faqShapeOf( kids[ k ] );
							if ( ! kidShape ) {
								continue;
							}
							var qEl = kids[ k ].querySelector( kidShape.question );
							var aEl = kids[ k ].querySelector( kidShape.answer );
							items.push( {
								question: qEl ? ( qEl.textContent || '' ).trim() : '',
								answer: aEl ? ( aEl.textContent || '' ).trim() : '',
								current: kids[ k ] === node,
							} );
						}
						return {
							listPath: listPath,
							question: shape.question,
							answer: shape.answer,
							items: items,
							count: siblings,
						};
					}
				}
			}
			node = node.parentElement;
		}
		return null;
	}

	/**
	 * Whether one element is a question-and-answer unit, and where its two texts
	 * live inside it.
	 *
	 * @param {Element} node
	 * @return {Object|null} { question, answer } as selectors relative to node.
	 */
	function faqShapeOf( node ) {
		if ( ! node || 1 !== node.nodeType ) {
			return null;
		}
		if ( 'DETAILS' === node.tagName && node.querySelector( 'summary' ) ) {
			// The answer is whatever is not the summary. A selector rather than a
			// node, because the host applies it to a fresh clone.
			return { question: 'summary', answer: 'p' };
		}
		return null;
	}

	// ---- Custom Content Collections: generic repeating card/list detector ----
	//
	// The generalized form of faqShapeOf()/faqUnitFor() above -- for any
	// repeating unit (a service card, a team member, a portfolio tile), not
	// only a question and an answer. FAQ keeps its own dedicated path (its
	// own JSON-LD, its own popup) because FAQPage is a schema.org type with
	// no generic equivalent; this detector explicitly steps aside for it
	// (see collectionUnitFor) rather than offering a second, competing
	// "manage as list" affordance over the same region.
	//
	// Every rule below resolves ambiguity toward "not a match" rather than
	// toward a guess: a missed collection just falls back to ordinary
	// click-to-edit, while a wrong one would corrupt the page the next time
	// its list is saved as one atomic rebuild. Validated against every real
	// stored page on a live converted site before being written here
	// (see the Stage 0 harness in the project's plan) -- that pass is what
	// surfaced the contiguity rule below: without it, same-tag elements
	// merely scattered through unrelated content (four <h2> section
	// headings spread across an entire legal page, each followed by that
	// section's own paragraph) matched as one "collection," and reordering
	// or deleting an "item" there would have scrambled unrelated sections.

	function classBaseForCongruence( cls ) {
		// Tailwind variants describe where a utility applies, not a member's
		// identity. Strip variants outside arbitrary values so `[animation-delay:…]`
		// remains one utility rather than being mistaken for a variant prefix.
		var base = cls;
		var start = 0;
		while ( start < base.length ) {
			var depth = 0;
			var separator = -1;
			for ( var i = 0; i < base.length; i++ ) {
				if ( '[' === base[ i ] ) {
					depth++;
				} else if ( ']' === base[ i ] && depth > 0 ) {
					depth--;
				} else if ( ':' === base[ i ] && 0 === depth ) {
					separator = i;
					break;
				}
			}
			if ( separator < 0 ) {
				break;
			}
			base = base.slice( separator + 1 );
			start++;
		}
		return base;
	}

	function isPositionInSetUtility( cls ) {
		var base = classBaseForCongruence( cls ).replace( /!$/, '' );
		return /^-?(?:col|row)-(?:span|start|end)(?:-|$)/.test( base )
			|| /^-?order(?:-|$)/.test( base )
			|| /^z-(?:auto|0|10|20|30|40|50)(?:-|$)/.test( base )
			|| /^overflow-(?:auto|hidden|clip|visible|scroll)(?:-|$)/.test( base )
			|| /^rounded-(?:t|r|b|l|s|e|tl|tr|br|bl|ss|se|ee|es)(?:-|$)/.test( base )
			|| /^border(?:-|$)/.test( base )
			|| /^p(?:t|r|b|l|s|e)(?:-|$)/.test( base )
			|| /^\[(?:animation-delay|animation-duration):[^\]]+\]$/.test( base );
	}

	function classListSorted( el ) {
		// The pristine snapshot (see stampSubtree) when one exists -- the
		// live className can have drifted (scroll-reveal, active-nav, lazy-
		// load-loaded classes) by the time a click computes this, well after
		// the page's own scripts ran.
		var c = el.hasAttribute( 'data-cve-class' )
			? el.getAttribute( 'data-cve-class' )
			: ( el.className && typeof el.className === 'string' ? el.className : '' );
		c = ( c || '' ).trim();
		if ( ! c ) {
			return [];
		}
		// Frozen carousel state: an export captures whichever slide was
		// active/next/prev (and loop-mode duplicates) at snapshot time, and
		// those classes ride the STORED markup, so the pristine snapshot
		// carries them too. Members of one slider differing only by these
		// are one design in different runtime states (dexler-014). The bare
		// `swiper-slide` class is structure and stays.
		var classes = c.split( /\s+/ ).filter( function ( cls ) {
			return ! /^swiper-slide-(active|prev|next|duplicate)/.test( cls )
				&& ! isPositionInSetUtility( cls );
		} );
		return classes.sort();
	}

	function childTagSequence( el ) {
		var out = [];
		for ( var i = 0; i < el.children.length; i++ ) {
			out.push( el.children[ i ].tagName.toLowerCase() );
		}
		// Comparison tables commonly use one <tbody> per feature group, with
		// each group containing a heading row plus a different number of feature
		// rows. The body is still the repeatable unit; its row count is data, not
		// a different design. Keep the wildcard narrow so ordinary cards retain
		// exact child-shape congruence.
		if ( 'TBODY' === el.tagName && out.length && out.every( function ( tag ) {
			return 'tr' === tag;
		} ) ) {
			return [ 'tr*' ];
		}
		return out;
	}

	// The grouping key: identical tag, identical SORTED class list, identical
	// immediate child-tag sequence. No fuzzy scoring, no tunable threshold --
	// congruence or nothing, because a threshold is where an unbounded
	// false-positive swamp would start.
	function shapeSignature( el ) {
		return el.tagName.toLowerCase() + '|' + classListSorted( el ).join( ',' ) + '|' + childTagSequence( el ).join( ',' );
	}

	function congruentSiblings( node, parent ) {
		var sig = shapeSignature( node );
		var out = [];
		for ( var i = 0; i < parent.children.length; i++ ) {
			if ( shapeSignature( parent.children[ i ] ) === sig ) {
				out.push( parent.children[ i ] );
			}
		}
		return out;
	}

	// A decorative separator between list members: carries nothing an owner
	// could edit — no children, no text, no media or link — so it is
	// punctuation, not content. A word ticker's <i></i> dots and a card list's
	// <hr> qualify; a heading between paragraph groups fails on its text and
	// an <img> fails on its src, which is exactly the prose protection the
	// mixed-tag rule below exists for.
	function isDecorativeSeparator( el ) {
		if ( el.children.length || ( el.textContent || '' ).trim() ) {
			return false;
		}
		if ( /^(IMG|VIDEO|IFRAME|EMBED|OBJECT|INPUT|SVG|PICTURE|SOURCE|CANVAS)$/.test( el.tagName ) ) {
			return false;
		}
		return ! el.hasAttribute( 'src' ) && ! el.hasAttribute( 'href' );
	}

	// The separator nodes of a mixed-tag parent, or null when its non-member
	// children are anything more than punctuation. All of them must also share
	// ONE shape — a strip alternating words with two different ornaments is
	// not a pattern this claims to understand.
	function collectionSeparators( parent, members ) {
		var seps = [];
		for ( var i = 0; i < parent.children.length; i++ ) {
			if ( members.indexOf( parent.children[ i ] ) === -1 ) {
				seps.push( parent.children[ i ] );
			}
		}
		if ( ! seps.length ) {
			return null;
		}
		var sig = shapeSignature( seps[ 0 ] );
		for ( var s = 0; s < seps.length; s++ ) {
			if ( ! isDecorativeSeparator( seps[ s ] ) || shapeSignature( seps[ s ] ) !== sig ) {
				return null;
			}
		}
		return seps;
	}

	// A homogeneous-tag parent can contain separators such as table heading
	// rows between otherwise identical data rows. Return each maximal run in
	// that case. Mixed-tag parents retain the all-or-nothing contiguity rule —
	// which prevents prose paragraphs separated by headings or images from
	// becoming a collection — with ONE carve-out: when every non-member child
	// is a decorative separator of one shape (a word ticker's <i></i> dots),
	// the members are a single list that happens to be punctuated, and they
	// merge into one run. Verified need: a five-word strip duplicated for a
	// seamless loop was ten individually-editable spans and no list at all.
	function collectionRuns( parent, members ) {
		var kids = parent.children;
		var homogeneous = true;
		for ( var tagIndex = 1; tagIndex < kids.length; tagIndex++ ) {
			if ( kids[ tagIndex ].tagName !== kids[ 0 ].tagName ) {
				homogeneous = false;
				break;
			}
		}
		var runs = [];
		var run = [];
		for ( var i = 0; i < kids.length; i++ ) {
			if ( members.indexOf( kids[ i ] ) !== -1 ) {
				run.push( kids[ i ] );
			} else if ( run.length ) {
				runs.push( run );
				run = [];
			}
		}
		if ( run.length ) {
			runs.push( run );
		}
		if ( homogeneous ) {
			return runs;
		}
		if ( runs.length > 1 && members.length >= 2 && collectionSeparators( parent, members ) ) {
			var merged = [];
			for ( var r = 0; r < runs.length; r++ ) {
				merged.push.apply( merged, runs[ r ] );
			}
			return [ merged ];
		}
		return 1 === runs.length ? runs : [];
	}

	function collectionValuesVary( vals ) {
		for ( var i = 1; i < vals.length; i++ ) {
			if ( vals[ i ] !== vals[ 0 ] ) {
				return true;
			}
		}
		return false;
	}

	// The pristine snapshot for `class` specifically (see stampSubtree /
	// classListSorted) -- a nested wrapper can drift the same way a top-
	// level card can (its own scroll-reveal class, an active-state class
	// toggled by the page's own script), and this comparison runs at every
	// recursion depth, not only the top.
	function collectionAttrValue( el, name ) {
		if ( 'class' === name ) {
			return classListSorted( el ).join( ' ' );
		}
		if ( 'style' === name ) {
			return normalizeStyleForCongruence( el.getAttribute( 'style' ) );
		}
		return el.getAttribute( name ) || '';
	}

	/**
	 * An inline style compared for CONGRUENCE, with the component's own
	 * runtime bookkeeping removed.
	 *
	 * A JS-driven widget writes measured sizes into CSS custom properties and
	 * flips animation flags on whichever item is currently open, so a static
	 * export freezes those onto ONE member of an otherwise identical set:
	 * `--radix-collapsible-content-height: 72px; animation-name: none` on the
	 * open FAQ answer and nothing on the closed four. Compared verbatim that
	 * reads as a design difference and the whole group stops being a
	 * collection -- which is how a five-question FAQ, the most obviously
	 * repeating thing on the page, was never offered for editing.
	 *
	 * Authored inline style still counts: only custom properties and the
	 * animation/transition state a widget toggles are dropped.
	 */
	function normalizeStyleForCongruence( value ) {
		return String( value || '' ).split( ';' ).map( function ( decl ) {
			decl = decl.trim();
			var colon = decl.indexOf( ':' );
			if ( colon < 0 ) {
				return decl;
			}
			return decl.slice( 0, colon ).trim().toLowerCase() + ':' + decl.slice( colon + 1 ).trim();
		} ).filter( function ( decl ) {
			if ( ! decl ) {
				return false;
			}
			var prop = decl.split( ':' )[ 0 ].trim().toLowerCase();
			return 0 !== prop.indexOf( '--' )
				&& 'opacity' !== prop
				&& 'transform' !== prop
				&& 'animation-name' !== prop
				&& 'animation-duration' !== prop
				&& 'transition-duration' !== prop;
		} ).sort().join( ';' );
	}

	// A non-whitelisted attribute differing at this position means the
	// members are not congruent here -- an opaque slot (see diffCollectionMembers),
	// never a bail of the whole candidate group.
	//
	// Every `data-cve-*` attribute is the plugin's OWN bookkeeping, not part
	// of the site's markup: data-cve-path is a positional index, so it is
	// BY DESIGN different on every single stamped sibling. Comparing it
	// would make every candidate group opaque at the very first check --
	// this is invisible against raw static HTML (nothing is stamped there),
	// which is exactly why the Stage 0 harness never caught it and a live
	// browser test did.
	function collectionAttrsCongruent( a, b ) {
		var seen = {};
		var i;
		for ( i = 0; i < a.attributes.length; i++ ) {
			seen[ a.attributes[ i ].name ] = 1;
		}
		for ( i = 0; i < b.attributes.length; i++ ) {
			seen[ b.attributes[ i ].name ] = 1;
		}
		for ( var name in seen ) {
			// `data-spa-*` is the html2wp prerenderer's bookkeeping, the same
			// category as our own `data-cve-*`: a converted SPA carries one
			// `data-spa-toggle`/`data-spa-panel` id PER disclosure, so an
			// eight-question accordion has eight members differing only in
			// that id. Compared verbatim that reads as eight different
			// designs and the group is offered to nobody — verified live on
			// a converted React FAQ, where every answer was editable as text
			// and the list itself was not a list.
			if ( COLLECTION_ATTR_WHITELIST[ name ] || COLLECTION_ATTR_IGNORE[ name ]
				|| 0 === name.indexOf( 'data-cve-' ) || 0 === name.indexOf( 'data-spa-' ) ) {
				continue;
			}
			if ( collectionAttrValue( a, name ) !== collectionAttrValue( b, name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Walk N congruent-at-this-level elements and collect field slots.
	 *
	 * A leaf (no element children) becomes a field only if its value varies
	 * across members: image src for <img>, text+href for a link leaf, plain
	 * text otherwise (text-short at <=60 chars, else text-long). A position
	 * WITH element children only recurses if it is itself structurally
	 * congruent (same shape signature) across every member -- any mismatch
	 * there, or a whitelist-only attribute mismatch, makes that whole
	 * subtree opaque: skipped, uneditable through this popup, but the group
	 * match still stands. This is nearly free because the eventual save
	 * clones each row's OWN original node rather than a canonical template,
	 * so an untouched opaque subtree is carried along automatically.
	 *
	 * `path` is a list of child-indices from the unit root -- positional
	 * addressing, never a selector, because a generic card commonly has more
	 * than one slot of the same tag (e.g. two <p> elements: price,
	 * description), unlike FAQ's one-<summary>-per-unit case.
	 */
	function diffCollectionMembers( members, path, slots ) {
		var first = members[ 0 ];
		var i;
		for ( i = 1; i < members.length; i++ ) {
			if ( ! collectionAttrsCongruent( first, members[ i ] ) ) {
				return; // non-whitelisted attribute differs here -> opaque
			}
		}
		if ( 0 === first.children.length ) {
			var tag = first.tagName.toLowerCase();
			if ( 'img' === tag ) {
				// alt variance counts too, not only src: content-based media
				// dedup collapses byte-identical images to ONE attachment URL,
				// so five design-distinct badges can legitimately share a src
				// on the live site while their alts still differ — src-only
				// detection then offers no image slot and the whole group
				// loses its "manage as a list" panel.
				var srcs = members.map( function ( m ) { return m.getAttribute( 'src' ) || ''; } );
				var alts = members.map( function ( m ) { return m.getAttribute( 'alt' ) || ''; } );
				if ( collectionValuesVary( srcs ) || collectionValuesVary( alts ) ) {
					slots.push( { type: 'image', path: path } );
				}
				return;
			}
			if ( first.hasAttribute( 'href' ) ) {
				var texts = members.map( function ( m ) { return ( m.textContent || '' ).trim(); } );
				var hrefs = members.map( function ( m ) { return m.getAttribute( 'href' ) || ''; } );
				if ( collectionValuesVary( texts ) || collectionValuesVary( hrefs ) ) {
					slots.push( { type: 'link', path: path } );
				}
				return;
			}
			var textVals = members.map( function ( m ) { return ( m.textContent || '' ).trim(); } );
			if ( ! collectionValuesVary( textVals ) ) {
				return; // invariant leaf -- harmless, but nothing to offer
			}
			var maxLen = Math.max.apply( null, textVals.map( function ( v ) { return v.length; } ) );
			slots.push( { type: maxLen <= 60 ? 'text-short' : 'text-long', path: path } );
			return;
		}
		var sig = shapeSignature( first );
		for ( i = 1; i < members.length; i++ ) {
			if ( shapeSignature( members[ i ] ) !== sig ) {
				return; // structural mismatch at this position -> opaque, not a bail
			}
		}
		// Mixed content: this node has element children AND words of its own.
		// The words are as editable as any leaf's and are frequently the only
		// thing that varies across members (a label beside an icon), so they
		// become a slot here rather than being walked past.
		var ownVals = members.map( ownText );
		if ( collectionValuesVary( ownVals ) ) {
			slots.push( { type: 'text-own', path: path } );
		}
		// A table body may have a different number of rows from its sibling
		// bodies. Walk only the shared prefix; the untouched extra rows remain
		// part of each body and are carried through an atomic collection save.
		var commonChildren = first.children.length;
		for ( i = 1; i < members.length; i++ ) {
			commonChildren = Math.min( commonChildren, members[ i ].children.length );
		}
		for ( var c = 0; c < commonChildren; c++ ) {
			var kids = members.map( function ( m ) { return m.children[ c ]; } );
			diffCollectionMembers( kids, path.concat( [ c ] ), slots );
		}
	}

	function resolveCollectionSlotNode( member, path ) {
		var node = member;
		for ( var i = 0; i < path.length; i++ ) {
			if ( ! node || ! node.children ) {
				return null;
			}
			node = node.children[ path[ i ] ];
		}
		return node || null;
	}

	/**
	 * An element's own text — its direct text-node children, nothing from
	 * descendants.
	 *
	 * The case this exists for: a label that shares its element with an icon.
	 * `<button>What is Lumen?<svg/></button>` has an element child, so the slot
	 * walk below treated it as a container and recursed straight past the words
	 * into the icon's paths, which are identical in every member — so the list
	 * produced zero editable fields and was rejected as a collection entirely.
	 * Verified live: a five-item FAQ accordion offered no "manage as a list"
	 * panel because every question sat next to a chevron.
	 *
	 * @param {Element} el
	 * @return {string}
	 */
	function ownText( el ) {
		var out = '';
		for ( var i = 0; i < el.childNodes.length; i++ ) {
			if ( 3 === el.childNodes[ i ].nodeType ) {
				out += el.childNodes[ i ].textContent;
			}
		}
		return out.trim();
	}

	/**
	 * Replace an element's own text, leaving its element children — the icon —
	 * exactly where they are. Writing textContent would delete them.
	 *
	 * @param {Element} el
	 * @param {string} value
	 * @return {void}
	 */
	function setOwnText( el, value ) {
		var first = null;
		for ( var i = el.childNodes.length - 1; i >= 0; i-- ) {
			if ( 3 === el.childNodes[ i ].nodeType ) {
				if ( first ) {
					el.removeChild( el.childNodes[ i ] );
				} else {
					first = el.childNodes[ i ];
				}
			}
		}
		if ( first ) {
			first.textContent = value;
		} else if ( value ) {
			el.insertBefore( document.createTextNode( value ), el.firstChild );
		}
	}

	function collectionSlotValue( member, slot ) {
		var node = resolveCollectionSlotNode( member, slot.path );
		if ( ! node ) {
			return null;
		}
		if ( 'image' === slot.type ) {
			return { src: node.getAttribute( 'src' ) || '', alt: node.getAttribute( 'alt' ) || '' };
		}
		if ( 'link' === slot.type ) {
			return { text: ( node.textContent || '' ).trim(), href: node.getAttribute( 'href' ) || '' };
		}
		if ( 'text-own' === slot.type ) {
			return ownText( node );
		}
		return ( node.textContent || '' ).trim();
	}

	/**
	 * The repeatable, structurally congruent unit of elements the given
	 * element sits inside -- the generic form of faqUnitFor() above, for any
	 * repeating card/list rather than only a question and an answer.
	 *
	 * @param {Element} el
	 * @return {Object|null} { listPath, shape, slotSchema, items, count }
	 */
	function collectionUnitFor( el ) {
		if ( faqUnitFor( el ) ) {
			return null; // FAQ owns <details><summary> end-to-end already
		}
		if ( inMenuZone( el ) ) {
			return null; // that region already has its own, more specific panel
		}
		var node = el;
		while ( node && node !== root && node.parentElement ) {
			var parent = node.parentElement;
			var members = congruentSiblings( node, parent );
			var runs = collectionRuns( parent, members );
			var run = runs.filter( function ( candidate ) {
				return candidate.indexOf( node ) !== -1;
			} )[ 0 ] || null;
			// Cells in a table row are columns, not a repeatable list. The
			// containing row remains eligible when walking up to its tbody.
			var rowCells = 'TR' === parent.tagName && ( 'TD' === node.tagName || 'TH' === node.tagName );
			if ( ! rowCells && run && run.length >= 2 ) {
				var listPath = pathOf( parent );
				if ( listPath ) {
					var slots = [];
					diffCollectionMembers( run, [], slots );
					if ( slots.length >= 1 && slots.length <= MAX_COLLECTION_SLOTS ) {
						return {
							listPath: listPath,
							shape: shapeSignature( node ),
							slotSchema: slots,
							items: run.map( function ( m ) {
								return {
									current: m === node,
									values: slots.map( function ( s ) { return collectionSlotValue( m, s ); } ),
								};
							} ),
							count: run.length,
						};
					}
				}
			}
			node = parent;
		}
		return null;
	}

	function targetFrom( el ) {
		// A posts zone wrapper is display:contents — it paints no box of its
		// own, so its rect is empty and its computed styles say nothing about
		// what the visitor sees. Measure the FIRST CARD instead. This is not a
		// convenience: in the stored source the element at the zone's own path
		// IS the card template inside the [wp-posts] token, so the first
		// rendered card is the live instance of exactly the element a style
		// save on this path writes to — its box and computed styles are the
		// correct initial values for the CARD STYLE controls.
		var measureEl = el;
		if ( 'posts' === ( el.getAttribute && el.getAttribute( ZONE_ATTR ) ) && el.firstElementChild ) {
			measureEl = el.firstElementChild;
		}
		var rect = measureEl.getBoundingClientRect();
		// The stamped kind is source-accurate; live DOM may have gained
		// children (odometer, spring-scale) — those must not take a caret.
		var kind = el.getAttribute( KIND_ATTR ) || 'container';
		var holdsField = holdsGeneratedField( el );
		var editableNow = ! holdsField && ( kind === 'image' || el.children.length === 0 || el.hasAttribute( 'data-cve-rich' ) );
		var fields = {};
		// A form control's placeholder is design copy like any label — the
		// words a visitor reads before they type. It is an ATTRIBUTE, so no
		// caret can reach it; the panel offers it instead. Reported for the
		// control itself, independent of `kind`, because an <input> is not a
		// text leaf and would otherwise carry no fields at all.
		var isFormControl = 'INPUT' === el.tagName || 'TEXTAREA' === el.tagName || 'SELECT' === el.tagName;
		if ( 'INPUT' === el.tagName || 'TEXTAREA' === el.tagName ) {
			fields.placeholder = el.getAttribute( 'placeholder' ) || '';
		}
		if ( kind === 'link' ) {
			fields.text = ( el.textContent || '' ).trim();
			fields.href = el.getAttribute( 'href' ) || '';
			fields.target = el.getAttribute( 'target' ) || '';
		} else if ( kind === 'image' ) {
			fields.src = el.getAttribute( 'src' ) || '';
			fields.alt = el.getAttribute( 'alt' ) || '';
			// An image can be wrapped in a link — either one the design shipped
			// (a journal card whose photo opens the article) or one the owner
			// added here. Both are reported the same way, so the panel always
			// shows where this picture currently leads.
			var imgAnchor = el.parentNode && 'A' === el.parentNode.tagName ? el.parentNode : null;
			fields.link = imgAnchor ? ( imgAnchor.getAttribute( 'href' ) || '' ) : '';
			fields.linkTarget = imgAnchor ? ( imgAnchor.getAttribute( 'target' ) || '' ) : '';
			// Only a wrapper this plugin added may be removed again; one that
			// came with the design usually carries the classes its layout is
			// built on, so clearing the address leaves that element in place.
			fields.linkOwned = !! ( imgAnchor && imgAnchor.hasAttribute( 'data-cve-link' ) );
		} else if ( kind === 'video' ) {
			fields.poster = el.getAttribute( 'poster' ) || '';
			var sourceEls = el.querySelectorAll( 'source' );
			if ( sourceEls.length ) {
				fields.sources = Array.prototype.map.call( sourceEls, function ( s ) {
					return { src: s.getAttribute( 'src' ) || '', type: s.getAttribute( 'type' ) || '', media: s.getAttribute( 'media' ) || '' };
				} );
			} else if ( el.hasAttribute( 'src' ) ) {
				// Some markup sets the file directly via src= instead of <source> children.
				fields.sources = [ { src: el.getAttribute( 'src' ) || '', type: '' } ];
			} else {
				fields.sources = [];
			}
			fields.scrollVideo = el.classList.contains( 'scroll-video' );
		} else {
			fields.text = ( el.textContent || '' ).trim();
		}
		// CSS ::before/::after ornaments (quote marks etc.) are not DOM nodes;
		// expose their computed look so the host can style them through the
		// plugin's CSS layer or convert them into real, editable elements.
		//
		// Withheld on a block page for the same reason the repeating-set
		// detector is: an ornament is edited by writing a rule into this
		// plugin's own CSS layer, keyed on a positional path. A block page
		// has no such layer — its styling lives in block attributes — so the
		// panel would offer a control whose save the translator then refuses,
		// aborting everything else the person did in the same sitting.
		var ornaments = [];
		[ 'before', 'after' ].forEach( function ( which ) {
			if ( config.blockMode ) {
				return;
			}
			var cs = window.getComputedStyle( el, '::' + which );
			if ( cs && cs.content && cs.content !== 'none' && cs.content !== 'normal' && cs.content !== '""' ) {
				ornaments.push( {
					pseudo: which,
					content: cs.content,
					color: rgbToHex( cs.color ),
					fontSize: cs.fontSize,
					fontFamily: cs.fontFamily,
					fontWeight: cs.fontWeight,
				} );
			}
		} );
		return {
			id: pathOf( el ),
			kind: kind,
			editableNow: editableNow,
			rich: el.hasAttribute( 'data-cve-rich' ),
			ornaments: ornaments,
			menuZone: inMenuZone( el ),
			menuLocation: menuLocationFor( el ),
			holdsField: holdsField,
			heldZone: holdsField ? heldZoneOf( el ) : null,
			// A control is a container by shape — no children, no text — so
			// the type panel skipped it. But its font, size and colour are
			// exactly what the visitor reads, and what ::placeholder inherits.
			formControl: isFormControl,
			// href comes from the post (the category link) or is built from a
			// placeholder (share, prev/next). Everything about the link stays
			// editable except the address itself — typing one in would freeze
			// every article to a single destination.
			dynamicHref: el.hasAttribute( 'data-cve-field-href' ),
			// The "load more" button pages a listing on the live site. Here it
			// is an ordinary element to restyle and relabel — the paging script
			// is not loaded in the preview at all — so the panel says so rather
			// than leaving a button that visibly does nothing.
			loadMore: el.hasAttribute( 'data-cve-load-more' ),
			// The path of the form this element belongs to, if any — the panel's
			// FORM section acts on THAT path, never on the clicked element's.
			//
			// Reported for the form itself, for anything inside it, and for a
			// form zone (a form already wrapped in [wp-form] hydrates into one,
			// occupying the same single sibling slot the <form> occupies in the
			// source, so one path addresses both states). Inside it matters
			// most: a designed form is its input and its button edge to edge,
			// with no bare form left to click, so selecting the form itself is
			// something a person cannot reliably do with a mouse.
			formPath: formPathFor( el ),
			// The question-and-answer unit this element belongs to, if any — so
			// the panel can add or remove a WHOLE question instead of editing
			// the text of one and leaving its answer orphaned.
			// Withheld on a block page: the questions editor rewrites a whole
			// <details> run as one source string, which is a raw-HTML
			// operation. On blocks each question is its own core/details
			// block — editable in place once Phase 3 stamps that type — and
			// offering the old editor here only produces a save that aborts.
			faq: config.blockMode ? null : faqUnitFor( el ),
			// The generic repeating-card/list unit this element belongs to, if
			// any (rooms, services, team members...) -- same "whole list, not
			// one item" addressing as faq above, and mutually exclusive with
			// it: collectionUnitFor steps aside wherever faqUnitFor already
			// matches.
			// Withheld on a block page. This detector finds repeated shapes
			// in the RENDERED DOM and offers to reorder, add and delete them —
			// structural edits to a source string, which addressed patches to
			// named blocks cannot express. The repeated shapes on such a page
			// are usually a Query Loop's output anyway, where there is nothing
			// to reorder because there is only one stored block. Per-item text
			// editing still works: each list item is its own block. Decided
			// here rather than inside the detector, which stays a pure
			// question about the DOM.
			collection: config.blockMode ? null : collectionUnitFor( el ),
			zone: zoneOf( el ),
			tagName: el.tagName.toLowerCase(),
			label:
				kind === 'container' || kind === 'video'
					? el.tagName.toLowerCase() + ( el.classList.length ? '.' + el.classList[ 0 ] : '' )
					: ( el.textContent || el.getAttribute( 'alt' ) || el.tagName ).replace( /\s+/g, ' ' ).trim().slice( 0, 40 ),
			rect: { x: Math.round( rect.x ), y: Math.round( rect.y ), width: Math.round( rect.width ), height: Math.round( rect.height ) },
			fields: fields,
			styles: stylesFor( measureEl ),
			// Which theme presets this block is already using, so the styling
			// panel opens showing the truth rather than "theme default" on an
			// element that is plainly not the default. Read from the classes
			// WordPress emits for preset attributes — the attributes
			// themselves live in the stored markup, which the browser does not
			// have, and asking the server for them on every click would put a
			// request between a click and a panel.
			blockAttrs: config.blockMode ? blockPresetsOn( el ) : null,
			// The block's own styling, read back off the element the way the
			// preset classes are, so the panel opens showing what is set
			// rather than blank. Inline style is where custom values live;
			// nothing else on a block page writes one.
			blockStyle: config.blockMode ? blockStyleOn( styledElementOf( el ) ) : null,
			// Which block this element belongs to, and what it may become.
			// The panel branches on both: a heading gets a level control, a
			// section gets padding, a paragraph gets neither.
			blockName: config.blockMode ? blockNameOf( el ) : null,
			veCapability: config.blockMode ? capabilityOf( el ) : null,
			// The block's own class list, which is where movement is stored.
			// Read from the block element, not the clicked one: a click on a
			// paragraph inside a section must report the section's classes
			// when the section is what the panel is acting on.
			blockClassName: config.blockMode ? blockClassesOf( el ) : null,
			headingLevel: config.blockMode ? headingLevelOf( el ) : null,
			// The section this element sits in, for the operations that act on
			// whole sections rather than on what was clicked.
			sectionAddress: config.blockMode ? sectionAddressOf( el ) : null,
			sectionName: config.blockMode ? sectionNameOf( el ) : null,
			// What this block is already tuned to on smaller screens. Stamped
			// by the server, because the rules live in the page's meta and the
			// browser has no other way to see them.
			responsive: config.blockMode ? responsiveOn( el ) : null,
		};
	}

	// has-accent-color, has-band-background-color, has-large-font-size,
	// has-display-font-family, has-text-align-center — WordPress's own
	// conventions, and the only place a rendered page records which preset a
	// block chose.
	// Custom values live in the element's inline style, expressed as the same
	// dot-paths the panel and the store use. Read from whichever element the
	// server writes to, which styledElementOf() decides.
	var STYLE_PATHS = {
		fontSize: 'typography.fontSize',
		lineHeight: 'typography.lineHeight',
		letterSpacing: 'typography.letterSpacing',
		fontWeight: 'typography.fontWeight',
		fontStyle: 'typography.fontStyle',
		textTransform: 'typography.textTransform',
		textAlign: 'typography.textAlign',
		color: 'color.text',
		backgroundColor: 'color.background',
		borderRadius: 'border.radius',
		borderTopWidth: 'border.width',
		borderTopStyle: 'border.style',
		borderTopColor: 'border.color',
		boxShadow: 'shadow',
		minHeight: 'dimensions.minHeight',
		paddingTop: 'spacing.padding.top',
		paddingRight: 'spacing.padding.right',
		paddingBottom: 'spacing.padding.bottom',
		paddingLeft: 'spacing.padding.left',
		marginTop: 'spacing.margin.top',
		marginBottom: 'spacing.margin.bottom',
	};

	// The markup carries a spacing preset as the custom property it expands
	// to; a block stores it as the preset reference. Reading one and writing
	// the other is what would make the panel show a chosen step as though it
	// were a typed-in value — and turn it into one on the next save.
	function unexpandPreset( value ) {
		var match = /^var\(\s*--wp--preset--([a-z-]+)--([a-z0-9-]+)\s*\)$/.exec( value );
		return match ? 'var:preset|' + match[ 1 ] + '|' + match[ 2 ] : value;
	}

	function blockStyleOn( el ) {
		var out = {};
		if ( ! el.style ) {
			return out;
		}
		// core/spacer's height is an attribute, not a style, but the markup is
		// the only place it is written down: a spacer left at its default
		// carries no attribute at all. Reading it here is what stops the panel
		// opening a 140px spacer showing 100px.
		if ( el.style.height ) {
			out.height = el.style.height;
		}
		Object.keys( STYLE_PATHS ).forEach( function ( property ) {
			var value = el.style[ property ];
			if ( value ) {
				out[ STYLE_PATHS[ property ] ] = unexpandPreset( value );
			}
		} );
		// text-decoration reads back as a shorthand in some browsers.
		if ( el.style.textDecorationLine || el.style.textDecoration ) {
			out['typography.textDecoration'] = ( el.style.textDecorationLine || el.style.textDecoration ).split( ' ' )[ 0 ];
		}

		// A gradient is written to the `background` shorthand, and only a
		// gradient is — a flat colour goes to background-color.
		if ( el.style.background && el.style.background.indexOf( 'gradient' ) !== -1 ) {
			out['color.gradient'] = el.style.background;
		}

		// core/image keeps its border and shadow on the picture, not on the
		// figure around it, so those are read from where they were written.
		var block = stampedBlockOf( el );
		if ( block && 'core/image' === block.getAttribute( 'data-ve-name' ) ) {
			var picture = block.querySelector( 'img' );
			if ( picture ) {
				[ 'borderRadius', 'borderTopWidth', 'borderTopStyle', 'borderTopColor', 'boxShadow' ]
					.forEach( function ( property ) {
						if ( picture.style[ property ] ) {
							out[ STYLE_PATHS[ property ] ] = unexpandPreset( picture.style[ property ] );
						}
					} );
			}
		}

		// And what the markup cannot carry at all: a block's gap, its aspect
		// ratio, whether it sticks. The server stamps those, because they are
		// stored in the block and never written into the page.
		if ( block && block.getAttribute( 'data-ve-stored' ) ) {
			try {
				var stored = JSON.parse( block.getAttribute( 'data-ve-stored' ) );
				Object.keys( stored ).forEach( function ( path ) {
					out[ path ] = stored[ path ];
				} );
			} catch ( e ) {
				// A malformed stamp is not worth breaking the panel over.
			}
		}
		return out;
	}

	function stampedBlockOf( el ) {
		return el.closest ? el.closest( '[data-ve-block]' ) : null;
	}

	// The whole SECTION an element belongs to — the outermost block, whose
	// address is a bare number.
	//
	// Structural operations work on these and nothing else, so a click that
	// landed on a paragraph three levels down still has to report the section
	// it lives in. Reporting the paragraph instead is how a Delete button ends
	// up removing something other than what it names.
	function sectionOf( el ) {
		var node = stampedBlockOf( el );
		var last = null;
		while ( node ) {
			last = node;
			node = node.parentElement && node.parentElement.closest
				? node.parentElement.closest( '[data-ve-block]' )
				: null;
		}
		if ( ! last || ! /^\d+$/.test( last.getAttribute( 'data-ve-block' ) || '' ) ) {
			return null;
		}
		return last;
	}

	// Where the server writes a block's class and style — the block's own
	// outermost element, except a button, which carries everything on its <a>.
	// Reading anywhere else opens the panel blank on a block that is styled.
	function styledElementOf( el ) {
		var block = stampedBlockOf( el );
		if ( ! block ) {
			return el;
		}
		if ( 'button' === block.getAttribute( 'data-ve-edit' ) ) {
			return block.querySelector( 'a' ) || block;
		}
		return block;
	}

	function sectionAddressOf( el ) {
		var section = sectionOf( el );
		return section ? section.getAttribute( 'data-ve-block' ) : null;
	}

	function sectionNameOf( el ) {
		var section = sectionOf( el );
		return section ? section.getAttribute( 'data-ve-name' ) : null;
	}

	function responsiveOn( el ) {
		var block = stampedBlockOf( el );
		var raw = block && block.getAttribute( 'data-ve-responsive' );
		if ( ! raw ) {
			return {};
		}
		try {
			return JSON.parse( raw ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function blockClassesOf( el ) {
		var block = stampedBlockOf( el );
		return block ? ( block.getAttribute( 'class' ) || '' ) : '';
	}

	function blockNameOf( el ) {
		var block = stampedBlockOf( el );
		return block ? block.getAttribute( 'data-ve-name' ) : null;
	}

	function capabilityOf( el ) {
		var block = stampedBlockOf( el );
		return block ? block.getAttribute( 'data-ve-edit' ) : null;
	}

	function headingLevelOf( el ) {
		var match = /^h([1-6])$/i.exec( el.tagName || '' );
		return match ? parseInt( match[ 1 ], 10 ) : null;
	}

	function blockPresetsOn( el ) {
		var out = {};
		var classes = ( el.className || '' ).split( /\s+/ );
		for ( var i = 0; i < classes.length; i++ ) {
			var name = classes[ i ];
			var match;
			if ( ( match = /^has-(.+)-background-color$/.exec( name ) ) ) {
				out.backgroundColor = match[ 1 ];
			} else if ( ( match = /^has-(.+)-font-size$/.exec( name ) ) ) {
				out.fontSize = match[ 1 ];
			} else if ( ( match = /^has-(.+)-font-family$/.exec( name ) ) ) {
				out.fontFamily = match[ 1 ];
			} else if ( ( match = /^has-text-align-(.+)$/.exec( name ) ) ) {
				out.textAlign = match[ 1 ];
			} else if ( ( match = /^has-(.+)-color$/.exec( name ) ) && 'text' !== match[ 1 ] ) {
				// After the background rule, or "has-band-background-color"
				// would be read as a text colour called "band-background".
				out.textColor = match[ 1 ];
			}
		}
		return out;
	}

	// Mirrors the server's own class rules. `owns` has to be careful: three
	// different presets end in "-color", and a text colour that also stripped
	// the border's would take the wrong one off.
	var PRESET_CLASSES = {
		fontFamily: {
			owns: function ( c ) { return /^has-.+-font-family$/.test( c ); },
			make: function ( s ) { return 'has-' + s + '-font-family'; },
		},
		fontSize: {
			owns: function ( c ) { return /^has-.+-font-size$/.test( c ) && 'has-custom-font-size' !== c; },
			make: function ( s ) { return 'has-' + s + '-font-size'; },
		},
		textColor: {
			owns: function ( c ) {
				return /^has-.+-color$/.test( c )
					&& ! /-background-color$/.test( c )
					&& ! /-border-color$/.test( c )
					&& 'has-text-color' !== c;
			},
			make: function ( s ) { return 'has-' + s + '-color'; },
			generic: 'has-text-color',
		},
		backgroundColor: {
			owns: function ( c ) { return /^has-.+-background-color$/.test( c ); },
			make: function ( s ) { return 'has-' + s + '-background-color'; },
			generic: 'has-background',
		},
		borderColor: {
			owns: function ( c ) { return /^has-.+-border-color$/.test( c ); },
			make: function ( s ) { return 'has-' + s + '-border-color'; },
			generic: 'has-border-color',
		},
		gradient: {
			owns: function ( c ) { return /^has-.+-gradient-background$/.test( c ); },
			make: function ( s ) { return 'has-' + s + '-gradient-background'; },
			generic: 'has-background',
		},
	};

	/**
	 * The border value a person would name for an element whose sides differ.
	 *
	 * Equal sides answer for themselves. Unequal ones — a field underlined and
	 * open on the other three — are described by the side that is drawn: the
	 * first with a non-zero width, falling back to the bottom, which is where
	 * this pattern almost always lives.
	 */
	function visibleBorder( computed, prop ) {
		var sides = [ 'Top', 'Right', 'Bottom', 'Left' ];
		var widths = sides.map( function ( s ) { return parseFloat( computed[ 'border' + s + 'Width' ] ) || 0; } );
		var same = widths.every( function ( w ) { return w === widths[ 0 ]; } );
		if ( same ) {
			return computed[ 'borderTop' + prop ];
		}
		for ( var i = 0; i < sides.length; i++ ) {
			if ( widths[ i ] > 0 ) {
				return computed[ 'border' + sides[ i ] + prop ];
			}
		}
		return computed[ 'borderBottom' + prop ];
	}

	function post( message ) {
		window.parent.postMessage( Object.assign( { ns: 'clara-ve' }, message ), '*' );
	}

	function editableFrom( eventTarget ) {
		var el = eventTarget;
		while ( el && el !== root && el.nodeType === 1 ) {
			if ( pathOf( el ) && el.getAttribute( KIND_ATTR ) ) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	/**
	 * Container fallback (open-design behaviour): when a click lands on no
	 * text/link/image leaf, the nearest stamped ancestor — a wrap div or a
	 * whole section — is selected for style-only editing.
	 */
	function containerFrom( eventTarget ) {
		var el = eventTarget;
		while ( el && el !== root && el.nodeType === 1 ) {
			if ( pathOf( el ) ) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	/**
	 * Resolve the best target for a pointer position, looking THROUGH
	 * overlays: full-bleed media (the hero image under its content layer)
	 * is otherwise unreachable. The paint stack is scanned for the first
	 * editable leaf before falling back to ancestor walks.
	 */
	/**
	 * Which of these elements the SITE'S OWN CSS made click-through.
	 *
	 * Measured, not guessed from class names: `pointer-events:none` can come
	 * from any rule, and a converted site's class names are whatever its
	 * designer chose. bridge.css forces `pointer-events:auto` on stamped
	 * elements in edit mode, so the site's real value is only visible with
	 * that override lifted — done by removing the edit-mode attribute for the
	 * duration of the read and putting it straight back. One forced style
	 * recalculation per click, on the handful of elements under the cursor.
	 *
	 * @param {Element[]} stack
	 * @return {Element[]} The subset the site made click-through.
	 */
	function clickThroughInSiteCss( stack ) {
		var root = document.documentElement;
		var was = root.hasAttribute( 'data-cve-edit-mode' );
		if ( was ) {
			root.removeAttribute( 'data-cve-edit-mode' );
		}
		var out = [];
		try {
			for ( var i = 0; i < stack.length; i++ ) {
				var el = stack[ i ];
				if ( el && 1 === el.nodeType && 'none' === getComputedStyle( el ).pointerEvents ) {
					out.push( el );
				}
			}
		} finally {
			if ( was ) {
				root.setAttribute( 'data-cve-edit-mode', '' );
			}
		}
		return out;
	}

	/**
	 * The first real content element UNDER a decorative empty one.
	 *
	 * A kind-less EMPTY element is usually the decorative leaf the person is
	 * pointing at — a 1px rule, a spacer — and it should win over the rich
	 * text ancestor that surrounds it. But the identical shape is also the
	 * most common hero treatment there is: `<div class="absolute inset-0
	 * bg-foreground/35">` laid over a photograph to darken it. That div is
	 * stamped, empty, and sits ABOVE the image in the hit stack, so it
	 * answered every click on the hero and the photograph could not be
	 * selected at all. Verified live on a converted wedding site: the image
	 * panel was unreachable on the front page and on all seven hero
	 * subpages — the panel opened on `div.absolute` every time.
	 *
	 * The discriminator is CONTAINMENT, not size or position: a decorative
	 * leaf may outrank the ancestors that wrap it, never an element it merely
	 * overlaps. So a kinded element lower in the stack wins exactly when it
	 * is not an ancestor of the empty one — which leaves the 1px-rule case
	 * untouched (there the kinded text block DOES contain the rule) and
	 * reaches the image through the tint.
	 *
	 * @param {Element[]} stack       Hit stack, topmost first.
	 * @param {number}    from        Index of the empty candidate.
	 * @param {Element}   empty       The empty candidate itself.
	 * @param {Element[]} passthrough Elements the site made click-through.
	 * @return {Element|null} The element to prefer, or null to keep `empty`.
	 */
	function contentBeneathDecoration( stack, from, empty, passthrough ) {
		for ( var i = from + 1; i < stack.length; i++ ) {
			var k = stack[ i ];
			if (
				k && 1 === k.nodeType && k.getAttribute &&
				k.getAttribute( PATH_ATTR ) && k.getAttribute( KIND_ATTR ) &&
				passthrough.indexOf( k ) === -1 &&
				! k.contains( empty ) &&
				! ( k.closest && k.closest( '[data-cve-editing="true"]' ) )
			) {
				return k;
			}
		}
		return null;
	}

	function resolveTarget( ev ) {
		var stack = ( document.elementsFromPoint && document.elementsFromPoint( ev.clientX, ev.clientY ) ) || [];

		// A hydrated zone is ONE slot in the stored source, so anything clicked
		// inside it resolves to the zone itself and gets the "managed by
		// WordPress" panel. Resolved from the DOM rather than from the hit
		// stack, because the zone marker carries `display: contents` (it must
		// not add a box the public page doesn't have — see bridge.css) and an
		// element with no box never appears in elementsFromPoint at all.
		var hit = stack.length ? stack[ 0 ] : ev.target;
		// Header/footer parts are un-stamped while editing a page because they
		// belong to their own keys. Do not make their menu links inert on that
		// canvas: a declared, assigned menu is saved through the menu endpoint,
		// never through this page's source. The temporary path is only for the
		// host's immediate set-link/set-text preview messages.
		if ( hit && inMenuZone( hit ) ) {
			if ( ! pathOf( hit ) ) {
				transientMenuPath += 1;
				hit.setAttribute( PATH_ATTR, 'path-menu-live-' + transientMenuPath );
			}
			return hit;
		}
		var zone = hit && hit.closest ? hit.closest( '[' + SKIP_ATTR + ']' ) : null;
		if ( zone && zone.getAttribute( PATH_ATTR ) ) {
			// An article field is one VALUE inside a box — the title inside its
			// <h1>, the photo inside its frame. The value itself has nothing to
			// change, while the box has everything: type, size, spacing. So
			// select the box and let the panel explain where the words come
			// from. A posts/menu/form zone is a whole section rather than a
			// value, so those still select the zone itself.
			if ( 'article' === zone.getAttribute( ZONE_ATTR ) ) {
				var box = zone.parentElement;
				if ( box && box.getAttribute && box.getAttribute( PATH_ATTR ) ) {
					return box;
				}
			}
			return zone;
		}
		// First pass: the pointed-at editable leaf OR empty decorative element
		// Elements the SITE made click-through are demoted behind everything
		// else under the cursor. bridge.css re-enables pointer-events on every
		// stamped element so a decorative leaf can be picked at all — but a
		// decorative element is frequently a full-section overlay (a blurred
		// glow, a gradient wash, a noise layer), and re-enabling it put that
		// overlay on top of its whole section: every click anywhere in the
		// section resolved to the overlay, and the content under it could not
		// be selected at all. Verified live on a converted site — an FAQ
		// section where the heading, every question and every card all
		// selected the same 2382x846 `div.pointer-events-none`.
		//
		// They stay selectable when nothing real is under the cursor (the 1px
		// rule, the spacer this rule exists for), which is the whole point of
		// the passes below being ordered rather than filtered.
		var passthrough = clickThroughInSiteCss( stack );

		// First pass: the pointed-at editable leaf OR empty decorative element
		// (a 1px rule, a spacer). Reaches an image through an overlay, yet still
		// prefers a kind-less empty child over its rich text ancestor.
		var order = [ false, true ];
		for ( var pass = 0; pass < order.length; pass++ ) {
			for ( var i = 0; i < stack.length; i++ ) {
				var e = stack[ i ];
				if (
					e && e.nodeType === 1 && e.getAttribute && e.getAttribute( PATH_ATTR ) &&
					passthrough.indexOf( e ) > -1 === order[ pass ] &&
					( e.getAttribute( KIND_ATTR ) || e.children.length === 0 ) &&
					! ( e.closest && e.closest( '[data-cve-editing="true"]' ) )
				) {
					// Qualified only by being empty: it may be a decorative
					// overlay covering the thing actually being pointed at.
					if ( ! e.getAttribute( KIND_ATTR ) ) {
						var beneath = contentBeneathDecoration( stack, i, e, passthrough );
						if ( beneath ) {
							return beneath;
						}
					}
					return e;
				}
			}
			// Second pass: any stamped element (a wrapper div), so containers under
			// the cursor are still selectable for style-only editing.
			for ( var j = 0; j < stack.length; j++ ) {
				var c = stack[ j ];
				if (
					c && c.nodeType === 1 && c.getAttribute && c.getAttribute( PATH_ATTR ) &&
					passthrough.indexOf( c ) > -1 === order[ pass ] &&
					! ( c.closest && c.closest( '[data-cve-editing="true"]' ) )
				) {
					return c;
				}
			}
		}
		return editableFrom( ev.target ) || containerFrom( ev.target );
	}

	/**
	 * Drag the padding of a selected block, on the block itself.
	 *
	 * The panel can already set padding, and typing 48 into a field is
	 * perfectly precise — it is just not how anybody decides how much space a
	 * band of a page wants. That decision is made by looking. So the top and
	 * bottom edges of a selected section grow a handle, and dragging one moves
	 * the padding while the page reflows underneath.
	 *
	 * Nothing new is stored: a drag ends by sending the same style change the
	 * panel's own field sends. If this never ran, the field would still be
	 * there.
	 */
	var HANDLE_ATTR = 'data-cve-handle';

	function clearHandles() {
		var old = document.querySelectorAll( '[' + HANDLE_ATTR + ']' );
		for ( var i = 0; i < old.length; i++ ) {
			old[ i ].parentNode.removeChild( old[ i ] );
		}
	}

	/**
	 * @param {string} id The addressed block's path, as the host knows it.
	 */
	function showHandles( id ) {
		clearHandles();
		if ( ! config.blockMode ) {
			return;
		}
		// The HOST decides whether to ask for these. Whether a block's padding
		// is its own is a question about the block registry, and the registry
		// is what the panel is built from — asking it here would mean keeping
		// a second copy of that knowledge in the canvas, where it could
		// quietly disagree.
		var block = findById( id );
		block = block ? ( block.closest( '[data-ve-block]' ) || block ) : null;
		if ( ! block ) {
			return;
		}

		[ 'top', 'bottom' ].forEach( function ( side ) {
			var handle = document.createElement( 'div' );
			handle.setAttribute( HANDLE_ATTR, side );
			handle.setAttribute( 'aria-hidden', 'true' );
			handle.title = 'Drag to change the ' + side + ' padding';
			block.appendChild( handle );
			handle.addEventListener( 'pointerdown', function ( ev ) {
				startDrag( ev, block, side, handle );
			} );
		} );
	}

	function startDrag( ev, block, side, handle ) {
		ev.preventDefault();
		ev.stopPropagation();

		var property = 'padding' + side.charAt( 0 ).toUpperCase() + side.slice( 1 );
		var startY   = ev.clientY;
		var startPx  = parseFloat( getComputedStyle( block )[ property ] ) || 0;
		var current  = startPx;

		handle.setPointerCapture( ev.pointerId );
		document.documentElement.setAttribute( 'data-cve-dragging', '1' );

		function move( e ) {
			// Dragging the bottom handle DOWNWARD should add space, and the
			// top handle downward should too — both grow the band away from
			// its own edge.
			var delta = ( 'top' === side ) ? ( e.clientY - startY ) : ( startY - e.clientY );
			current = Math.max( 0, Math.round( startPx + delta ) );
			block.style[ property ] = current + 'px';
		}

		function end() {
			handle.removeEventListener( 'pointermove', move );
			handle.removeEventListener( 'pointerup', end );
			document.documentElement.removeAttribute( 'data-cve-dragging' );
			// The same change the panel's own field would send, so it lands in
			// the same queue and is saved by the same path.
			post( {
				type: 'handle-drag',
				id: pathOf( block ),
				path: 'spacing.padding.' + side,
				value: current + 'px',
			} );
		}

		handle.addEventListener( 'pointermove', move );
		handle.addEventListener( 'pointerup', end );
	}

	function clearSelected() {
		clearHandles();
		var selected = document.querySelectorAll( '[data-cve-selected]' );
		for ( var i = 0; i < selected.length; i++ ) {
			selected[ i ].removeAttribute( 'data-cve-selected' );
		}
	}

	function findById( id ) {
		if ( ! id ) {
			return null;
		}
		return document.querySelector( '[' + PATH_ATTR + '="' + id.replace( /"/g, '\\"' ) + '"]' );
	}

	// Session-start inline styles, so the host's Cancel/Reset can revert
	// live previews without a reload.
	var originalInlineStyles = {};
	// Per-card style snapshots for a posts zone, keyed by the ZONE's path:
	// preview-style fans a card-template style out to every rendered card
	// (the zone wrapper itself is display:contents and paints nothing), so
	// revert needs each card's own original inline style back, not the
	// wrapper's.
	var zoneChildOriginalStyles = {};

	// Session-start image src/alt or video poster/sources, captured once per
	// element (like originalInlineStyles), so Reset can also undo an image or
	// video swap, not just style changes.
	var originalMedia = {};

	function rememberOriginalMedia( el ) {
		var id = pathOf( el );
		if ( id in originalMedia ) {
			return;
		}
		var kind = el.getAttribute( KIND_ATTR );
		if ( kind === 'image' ) {
			originalMedia[ id ] = { kind: 'image', src: el.getAttribute( 'src' ) || '', alt: el.getAttribute( 'alt' ) || '' };
		} else if ( kind === 'video' ) {
			originalMedia[ id ] = {
				kind: 'video',
				poster: el.getAttribute( 'poster' ) || '',
				sources: Array.prototype.map.call( el.querySelectorAll( 'source' ), function ( s ) {
					return { src: s.getAttribute( 'src' ) || '', type: s.getAttribute( 'type' ) || '', media: s.getAttribute( 'media' ) || '' };
				} ),
			};
		}
	}

	function applyImageFields( el, src, alt ) {
		el.setAttribute( 'src', src );
		// srcset OUTRANKS src. WordPress renders a picture with a set of
		// candidate files and a sizes hint, and the browser picks from those —
		// so changing src alone leaves the OLD picture on screen, and the
		// change appears not to have happened until a save reloads the page
		// with a fresh srcset. Both go, and the browser is left with the one
		// address it has been given.
		el.removeAttribute( 'srcset' );
		el.removeAttribute( 'sizes' );
		// The old file's dimensions would letterbox the new one while the
		// preview is up.
		el.removeAttribute( 'width' );
		el.removeAttribute( 'height' );
		if ( typeof alt === 'string' ) {
			el.setAttribute( 'alt', alt );
		}
	}

	/**
	 * Point an image at an address, or take the address away.
	 *
	 * The wrapper is a plain <a> with no styling of its own on purpose. An
	 * inline element does not become the containing block for a percentage
	 * height, so rules the designs lean on — .frame img{height:100%} and
	 * friends — keep resolving against the same box they did before and the
	 * layout is unchanged. Giving the wrapper display:block is what breaks
	 * them, so it deliberately gets no display at all.
	 *
	 * An <a> the design already had is reused rather than nested inside a new
	 * one (nested links are invalid) and never taken apart, because its class
	 * is often what the surrounding layout is built on: clearing the address
	 * there drops the href and leaves a plain, non-linking element behind.
	 */
	function applyImageLink( img, href, linkTarget ) {
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
			anchor = img.ownerDocument.createElement( 'a' );
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

	function applyVideoFields( el, poster, sources ) {
		if ( typeof poster === 'string' ) {
			if ( poster ) {
				el.setAttribute( 'poster', poster );
			} else {
				el.removeAttribute( 'poster' );
			}
		}
		if ( Array.isArray( sources ) ) {
			// Normalize away from a direct src= attribute (if the original markup
			// used one) so <source> children are the single source of truth. Only
			// <source> children are replaced — <track> children (captions/
			// subtitles) are left untouched.
			el.removeAttribute( 'src' );
			Array.prototype.slice.call( el.querySelectorAll( 'source' ) ).forEach( function ( s ) {
				s.remove();
			} );
			var frag = document.createDocumentFragment();
			sources.forEach( function ( s ) {
				if ( ! s.src ) {
					return;
				}
				var se = document.createElement( 'source' );
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
			el.load(); // force the swapped <source> set to take effect
		}
	}

	// "Play automatically on scroll": mirror the original site's decorative
	// scroll-video markup — the `scroll-video` class (which the plugin's
	// front-end script and any theme CSS both key off), muted + playsinline so
	// programmatic autoplay is permitted, no `controls`/`loop`/`autoplay`.
	// Turning it off restores an ordinary controllable video.
	function applyScrollVideo( el, enabled ) {
		if ( enabled ) {
			el.classList.add( 'scroll-video' );
			el.setAttribute( 'muted', '' );
			el.muted = true;
			el.setAttribute( 'playsinline', '' );
			el.setAttribute( 'preload', 'auto' );
			el.removeAttribute( 'controls' );
			el.removeAttribute( 'loop' );
			el.removeAttribute( 'autoplay' );
		} else {
			el.classList.remove( 'scroll-video' );
			el.setAttribute( 'controls', '' );
			// In the editor, keep it paused on its poster rather than mid-play.
			try {
				el.pause();
			} catch ( e ) {}
		}
	}

	// Live ::before/::after ornament previews — one style element, keyed by
	// id then pseudo: pseudoRules[ id ] = { before: {k:v}, after: {k:v} }.
	var pseudoRules = {};
	var pseudoStyleEl = null;
	function renderPseudoRules() {
		if ( ! pseudoStyleEl ) {
			pseudoStyleEl = document.createElement( 'style' );
			pseudoStyleEl.setAttribute( 'data-cve-pseudo', '' );
			document.head.appendChild( pseudoStyleEl );
		}
		var css = '';
		Object.keys( pseudoRules ).forEach( function ( id ) {
			[ 'before', 'after' ].forEach( function ( which ) {
				var rule = pseudoRules[ id ][ which ];
				if ( ! rule ) {
					return;
				}
				var body = '';
				Object.keys( rule ).forEach( function ( key ) {
					var cssName = key.replace( /[A-Z]/g, function ( m ) {
						return '-' + m.toLowerCase();
					} );
					body += cssName + ':' + rule[ key ] + ' !important;';
				} );
				if ( body ) {
					css += '[data-cve-path="' + id + '"]::' + which + '{' + body + '}\n';
				}
			} );
		} );
		pseudoStyleEl.textContent = css;
	}

	// Merge preview props into an id's pseudo bucket.
	function setPseudoRule( id, pseudo, styles ) {
		pseudoRules[ id ] = pseudoRules[ id ] || {};
		pseudoRules[ id ][ pseudo ] = Object.assign( {}, pseudoRules[ id ][ pseudo ] || {}, styles || {} );
		renderPseudoRules();
	}

	// Promote a ::before/::after glyph into a real, editable <span> in the DOM,
	// styled to match, and neutralize the original pseudo so nothing doubles.
	// The host reports back so it can persist the span (set-inner-html) plus the
	// content:none override (set-pseudo). Works on any element's pseudo, any site.
	function convertOrnament( id, pseudo ) {
		var el = findById( id );
		if ( ! el ) {
			return;
		}
		// The host is likely mid inline-edit from the click that selected it;
		// clear that so it isn't later committed as plain text (it's about to
		// gain a child element and would fail the set-text "no nested markup").
		finishEdit( true );
		var cs = window.getComputedStyle( el, '::' + pseudo );
		if ( ! cs || ! cs.content || cs.content === 'none' || cs.content === 'normal' || cs.content === '""' ) {
			return;
		}
		var glyph = cs.content.replace( /^"([\s\S]*)"$/, '$1' );
		var span = document.createElement( 'span' );
		span.className = 'cve-ornament';
		span.setAttribute( 'data-cve-ornament', pseudo );
		span.textContent = glyph;
		// Copy the pseudo's look inline so the span matches without the CSS rule.
		[ 'display', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'color', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left' ].forEach( function ( prop ) {
			var v = cs.getPropertyValue( prop );
			if ( v && v !== 'normal' && v !== '' ) {
				span.style.setProperty( prop, v );
			}
		} );
		if ( pseudo === 'before' ) {
			el.insertBefore( span, el.firstChild );
		} else {
			el.appendChild( span );
		}
		// Kill the original pseudo (persists via the CSS layer) so it isn't doubled.
		setPseudoRule( id, pseudo, { content: 'none' } );
		// Re-stamp: host may have become a rich block; the span + shifted siblings
		// need fresh paths/kinds so the glyph is selectable and inline-editable.
		applyKind( el );
		stampSubtree( el, basePathOf( el ) );
		post( { type: 'ornament-converted', id: id, pseudo: pseudo, hostInnerHtml: cleanedInnerHtml( el ), newSpanId: pathOf( span ) } );
		// Select the new real element so its glyph can be edited straight away.
		clearSelected();
		span.setAttribute( 'data-cve-selected', 'true' );
		rememberOriginal( span );
		post( { type: 'select', target: targetFrom( span ) } );
	}

	function rememberOriginal( el ) {
		var id = pathOf( el );
		if ( ! ( id in originalInlineStyles ) ) {
			originalInlineStyles[ id ] = el.getAttribute( 'style' );
		}
	}

	// ---- Inline text editing ----
	var activeEdit = null;

	function placeCaret( ev, el ) {
		var range = null;
		try {
			if ( document.caretPositionFromPoint ) {
				var pos = document.caretPositionFromPoint( ev.clientX, ev.clientY );
				if ( pos ) {
					range = document.createRange();
					range.setStart( pos.offsetNode, pos.offset );
					range.collapse( true );
				}
			} else if ( document.caretRangeFromPoint ) {
				range = document.caretRangeFromPoint( ev.clientX, ev.clientY );
			}
		} catch ( e ) {}
		if ( ! range ) {
			range = document.createRange();
			range.selectNodeContents( el );
			range.collapse( false );
		}
		var sel = window.getSelection();
		if ( sel ) {
			sel.removeAllRanges();
			sel.addRange( range );
		}
	}

	/**
	 * Serialize a rich block's innerHTML with every editor-injected
	 * attribute stripped from the nested markup.
	 */
	function cleanedInnerHtml( el ) {
		var clone = el.cloneNode( true );
		var all = clone.querySelectorAll( '*' );
		for ( var i = 0; i < all.length; i++ ) {
			var attrs = all[ i ].attributes;
			for ( var j = attrs.length - 1; j >= 0; j-- ) {
				if ( attrs[ j ].name.indexOf( 'data-cve-' ) === 0 ) {
					all[ i ].removeAttribute( attrs[ j ].name );
				}
			}
		}
		return clone.innerHTML;
	}


	function finishEdit( commit ) {
		if ( ! activeEdit ) {
			return;
		}
		var session = activeEdit;
		activeEdit = null;
		var el = session.el;
		el.removeAttribute( 'contenteditable' );
		el.removeAttribute( 'data-cve-editing' );
		el.removeEventListener( 'keydown', session.onKey );
		if ( session.rich ) {
			var html = cleanedInnerHtml( el );
			if ( commit && html !== session.originalHtml ) {
				post( { type: 'inner-commit', id: pathOf( el ), value: html } );
			} else if ( ! commit ) {
				el.innerHTML = session.originalDomHtml;
			}
			return;
		}
		var value = ( el.textContent || '' ).trim();
		var changed = value !== session.original.trim();
		if ( commit && changed ) {
			post( { type: 'text-commit', id: pathOf( el ), value: value, kind: el.getAttribute( KIND_ATTR ) || 'text' } );
		} else if ( ! commit ) {
			el.textContent = session.original;
		}
	}

	function startEdit( el, ev ) {
		var rich = el.hasAttribute( 'data-cve-rich' );
		if ( el.children.length > 0 && ! rich ) {
			return; // live DOM gained markup (odometer etc.) — style-only
		}
		if ( activeEdit && activeEdit.el === el ) {
			placeCaret( ev, el );
			return;
		}
		finishEdit( true );
		el.setAttribute( 'contenteditable', 'plaintext-only' );
		el.setAttribute( 'data-cve-editing', 'true' );
		try {
			el.focus();
		} catch ( e ) {}
		placeCaret( ev, el );
		function onKey( kev ) {
			if ( kev.key === 'Enter' && ! kev.shiftKey ) {
				kev.preventDefault();
				kev.stopPropagation();
				finishEdit( true );
			}
			if ( kev.key === 'Escape' ) {
				kev.preventDefault();
				kev.stopPropagation();
				finishEdit( false );
			}
		}
		activeEdit = {
			el: el,
			rich: rich,
			original: el.textContent || '',
			originalHtml: rich ? cleanedInnerHtml( el ) : '',
			originalDomHtml: rich ? el.innerHTML : '',
			onKey: onKey,
		};
		el.addEventListener( 'keydown', onKey );
	}

	// ---- Events ----

	// Following a link inside the preview would load a plain front-end URL —
	// no edit parameters, so no bridge — and the editor would be left pointing
	// at a page it can no longer touch, while its toolbar still named the old
	// one. (Edit mode already swallows clicks; this is the OTHER mode, where
	// links still worked and quietly broke the session.) Hand the destination
	// to the host instead and let it switch pages properly.
	document.addEventListener(
		'click',
		function ( ev ) {
			if ( enabled ) {
				return; // edit mode has its own handler below.
			}
			var link = ev.target && ev.target.closest ? ev.target.closest( 'a[href]' ) : null;
			if ( ! link ) {
				return;
			}
			var href = link.getAttribute( 'href' ) || '';
			// In-page anchors and non-http schemes navigate nothing we own.
			if ( ! href || '#' === href.charAt( 0 ) || /^(mailto:|tel:|javascript:)/i.test( href ) ) {
				return;
			}
			// Opening elsewhere doesn't disturb this frame.
			if ( link.target && '_self' !== link.target ) {
				return;
			}
			if ( link.href && link.protocol && 0 !== link.protocol.indexOf( 'http' ) ) {
				return;
			}
			ev.preventDefault();
			ev.stopPropagation();
			post( { type: 'navigate', href: link.href } );
		},
		true // capture, so the page's own handlers can't navigate first.
	);

	document.addEventListener(
		'click',
		function ( ev ) {
			// Before the edit-mode gate, deliberately. With edit mode OFF the
			// preview behaves like the live page — except this one button,
			// whose paging script is not loaded here, so pressing it does
			// nothing at all and looks broken. That is the moment the
			// explanation is worth most, and it is exactly the moment the
			// gate below would swallow.
			if ( ev.target && ev.target.closest && ev.target.closest( '[data-cve-load-more]' ) ) {
				ev.preventDefault();
				post( { type: 'load-more' } );
				// No stopPropagation: with edit mode ON the click carries on
				// below and selects the button for editing as usual.
			}
			if ( ! enabled ) {
				return;
			}
			if ( ev.target && ev.target.closest && ev.target.closest( '[data-cve-editing="true"]' ) ) {
				return;
			}
			ev.preventDefault();
			ev.stopPropagation();

			var el = resolveTarget( ev );
			if ( ! el ) {
				finishEdit( true );
				clearSelected();
				post( { type: 'background' } );
				return;
			}
			if ( activeEdit && activeEdit.el !== el ) {
				finishEdit( true );
			}
			clearSelected();
			el.setAttribute( 'data-cve-selected', 'true' );
			rememberOriginal( el );
			rememberOriginalMedia( el );
			var target = targetFrom( el );
			post( { type: 'select', target: target } );
			// A form zone owns its SUBMISSION, not its wording. The labels,
			// the button and the small print are the design's own words,
			// sitting in the stored source exactly where they were written —
			// so a text leaf inside one is editable like any other. What the
			// zone still governs is the form element itself and its controls,
			// which are not text leaves and so never reach this branch.
			//
			// Menus and post cards are the opposite case and keep the block:
			// their words come from WordPress records, and typing over them
			// would be overwritten by the next render.
			var zoneBlocksEditing =
				target.zone && ! ( 'form' === target.zone && ( target.kind === 'text' || target.kind === 'link' ) );
			if ( ! target.menuZone && ! zoneBlocksEditing && ! target.holdsField && ( target.kind === 'text' || target.kind === 'link' ) ) {
				startEdit( el, ev );
			}
		},
		true
	);

	document.addEventListener(
		'pointerover',
		function ( ev ) {
			if ( ! enabled || activeEdit ) {
				return;
			}
			var hovered = document.querySelectorAll( '[data-cve-hover]' );
			for ( var i = 0; i < hovered.length; i++ ) {
				hovered[ i ].removeAttribute( 'data-cve-hover' );
			}
			var el = resolveTarget( ev );
			if ( el ) {
				el.setAttribute( 'data-cve-hover', 'true' );
			}
		},
		true
	);

	// ---- Host messages ----
	window.addEventListener( 'message', function ( ev ) {
		var data = ev.data;
		if ( ! data || data.ns !== 'clara-ve' ) {
			return;
		}
		var el;
		if ( data.type === 'edit-mode' ) {
			enabled = data.enabled !== false;
			document.documentElement.toggleAttribute( 'data-cve-edit-mode', enabled );
			if ( ! enabled ) {
				finishEdit( true );
				clearSelected();
			}
			return;
		}
		/**
		 * Swap a preset CLASS in the preview, not just the inline style.
		 *
		 * WordPress writes its preset rules with !important —
		 * `.has-display-font-family { font-family: …!important }` — so a block
		 * that already carries one cannot be previewed by setting an inline
		 * style: the old class beats it. The change appeared to do nothing
		 * until the page was saved and the class was rewritten, which is
		 * exactly how somebody reports that changing the font does not work.
		 *
		 * The naming rules here mirror Clara_VE_Block_Supports::rewrite_classes,
		 * because this is the one place that has the element in hand and has
		 * to take the old class off it.
		 */
		if ( data.type === 'preview-class' ) {
			el = findById( data.id );
			var rule = PRESET_CLASSES[ data.kind ];
			if ( el && rule ) {
				Array.prototype.slice.call( el.classList ).forEach( function ( cls ) {
					if ( rule.owns( cls ) ) {
						el.classList.remove( cls );
					}
				} );
				if ( rule.generic ) {
					el.classList.remove( rule.generic );
				}
				if ( data.slug ) {
					el.classList.add( rule.make( data.slug ) );
					if ( rule.generic ) {
						el.classList.add( rule.generic );
					}
				} else if ( data.custom && rule.generic ) {
					// A custom value keeps the generic marker — that is what
					// tells the stylesheet something was set at all — and
					// loses only the preset's own class.
					el.classList.add( rule.generic );
				}
			}
			return;
		}

		if ( data.type === 'show-handles' ) {
			showHandles( data.id );
			return;
		}

		if ( data.type === 'preview-style' ) {
			el = findById( data.id );
			if ( el ) {
				rememberOriginal( el );
				var previewEls = [ el ];
				// A posts zone is display:contents, so styling the wrapper
				// paints nothing. What a save on this path really styles is the
				// card template inside the token — every card renders from it —
				// so the live preview applies to every card, showing exactly
				// what the save will produce. Children's own inline styles are
				// snapshotted once for revert-style below.
				if ( 'posts' === el.getAttribute( ZONE_ATTR ) ) {
					if ( ! ( data.id in zoneChildOriginalStyles ) ) {
						zoneChildOriginalStyles[ data.id ] = Array.prototype.map.call( el.children, function ( child ) {
							return child.getAttribute( 'style' );
						} );
					}
					previewEls = Array.prototype.slice.call( el.children );
				}
				Object.keys( data.styles || {} ).forEach( function ( key ) {
					var cssName = key.replace( /[A-Z]/g, function ( m ) {
						return '-' + m.toLowerCase();
					} );
					var value = data.styles[ key ];
					previewEls.forEach( function ( targetEl ) {
						if ( typeof value !== 'string' || value.trim() === '' ) {
							targetEl.style.removeProperty( cssName );
						} else {
							targetEl.style.setProperty( cssName, value.trim() );
						}
					} );
				} );
			}
			return;
		}
		if ( data.type === 'revert-style' ) {
			el = findById( data.id );
			if ( el && data.id in originalInlineStyles ) {
				var orig = originalInlineStyles[ data.id ];
				if ( orig === null ) {
					el.removeAttribute( 'style' );
				} else {
					el.setAttribute( 'style', orig );
				}
			}
			if ( el && data.id in zoneChildOriginalStyles ) {
				var childOrig = zoneChildOriginalStyles[ data.id ];
				Array.prototype.forEach.call( el.children, function ( child, i ) {
					if ( i >= childOrig.length ) {
						return;
					}
					if ( null === childOrig[ i ] ) {
						child.removeAttribute( 'style' );
					} else {
						child.setAttribute( 'style', childOrig[ i ] );
					}
				} );
			}
			return;
		}
		if ( data.type === 'set-image' ) {
			el = findById( data.id );
			if ( el && el.tagName === 'IMG' ) {
				applyImageFields( el, data.src, data.alt );
				// Undefined means "this message is not about the link".
				if ( typeof data.link === 'string' ) {
					applyImageLink( el, data.link, data.linkTarget );
				}
			}
			return;
		}
		if ( data.type === 'set-video' ) {
			el = findById( data.id );
			if ( el && el.tagName === 'VIDEO' ) {
				applyVideoFields( el, data.poster, data.sources );
			}
			return;
		}
		if ( data.type === 'convert-to-video' ) {
			// An <img> becomes a real <video> at the same tree position — not
			// an attribute change like set-image/set-video, an actual tag
			// swap. The element is found by its own stamped path rather than
			// by "current selection", because the swap may land while
			// something else is selected.
			el = findById( data.id );
			if ( el && el.tagName === 'IMG' ) {
				var video = document.createElement( 'video' );
				if ( el.className ) {
					video.className = el.className;
				}
				if ( el.getAttribute( 'style' ) ) {
					video.setAttribute( 'style', el.getAttribute( 'style' ) );
				}
				// Force the box the image occupied (measured host-side, see
				// editor.js videoBoxStyle) — img-targeted sizing CSS doesn't
				// apply to <video>, so without this the clip renders at its
				// intrinsic size and breaks the layout. Applied after the copied
				// style attr so it wins.
				if ( data.boxStyle ) {
					Object.keys( data.boxStyle ).forEach( function ( prop ) {
						video.style.setProperty( prop, data.boxStyle[ prop ] );
					} );
				}
				video.setAttribute( 'controls', '' );
				video.setAttribute( 'preload', 'metadata' );
				// Generated clips are silent by policy (generate_audio:false at
				// the API), and muted also keeps any future autoplay usage
				// within browser autoplay rules.
				video.setAttribute( 'muted', '' );
				video.muted = true;
				applyVideoFields( video, data.poster, data.sources );
				var oldPath = pathOf( el );
				el.replaceWith( video );
				video.setAttribute( PATH_ATTR, oldPath );
				applyKind( video ); // tag is now <video> -> kind becomes 'video'
				rememberOriginal( video );
				// Refresh the host panel (if it's even still showing this
				// element) to the new video kind/fields.
				post( { type: 'select', target: targetFrom( video ) } );
			}
			return;
		}
		if ( data.type === 'convert-to-image' ) {
			// Reverse of convert-to-video: a <video> becomes a real <img> at the
			// same tree position. Found by its stamped path, independent of the
			// current selection.
			el = findById( data.id );
			if ( el && el.tagName === 'VIDEO' ) {
				var newImg = document.createElement( 'img' );
				if ( el.className ) {
					newImg.className = el.className;
				}
				newImg.classList.remove( 'scroll-video' ); // an image can't scroll-play.
				if ( el.getAttribute( 'style' ) ) {
					newImg.setAttribute( 'style', el.getAttribute( 'style' ) );
				}
				if ( data.boxStyle ) {
					Object.keys( data.boxStyle ).forEach( function ( prop ) {
						newImg.style.setProperty( prop, data.boxStyle[ prop ] );
					} );
				}
				newImg.src = data.src;
				newImg.setAttribute( 'alt', data.alt || '' );
				var oldImgPath = pathOf( el );
				el.replaceWith( newImg );
				newImg.setAttribute( PATH_ATTR, oldImgPath );
				applyKind( newImg ); // tag is now <img> -> kind becomes 'image'
				rememberOriginal( newImg );
				post( { type: 'select', target: targetFrom( newImg ) } );
			}
			return;
		}
		if ( data.type === 'set-scroll-video' ) {
			el = findById( data.id );
			if ( el && el.tagName === 'VIDEO' ) {
				applyScrollVideo( el, data.enabled );
				post( { type: 'select', target: targetFrom( el ) } );
			}
			return;
		}
		if ( data.type === 'revert-media' ) {
			el = findById( data.id );
			var origMedia = originalMedia[ data.id ];
			if ( el && origMedia ) {
				if ( origMedia.kind === 'image' && el.tagName === 'IMG' ) {
					applyImageFields( el, origMedia.src, origMedia.alt );
				} else if ( origMedia.kind === 'video' && el.tagName === 'VIDEO' ) {
					applyVideoFields( el, origMedia.poster, origMedia.sources );
				}
				// Refresh the host's panel so its preview/fields reflect the revert.
				post( { type: 'select', target: targetFrom( el ) } );
			}
			return;
		}
		if ( data.type === 'preview-pseudo' ) {
			setPseudoRule( data.id, data.pseudo === 'after' ? 'after' : 'before', data.styles );
			return;
		}
		if ( data.type === 'revert-pseudo' ) {
			if ( pseudoRules[ data.id ] ) {
				if ( data.pseudo ) {
					delete pseudoRules[ data.id ][ data.pseudo ];
				} else {
					delete pseudoRules[ data.id ];
				}
			}
			renderPseudoRules();
			return;
		}
		if ( data.type === 'convert-ornament' ) {
			convertOrnament( data.id, data.pseudo === 'after' ? 'after' : 'before' );
			return;
		}
		if ( data.type === 'set-text-live' ) {
			el = findById( data.id );
			if ( el && el.children.length === 0 ) {
				finishEdit( true );
				el.textContent = data.value;
			}
			return;
		}
		if ( data.type === 'set-placeholder' ) {
			el = findById( data.id );
			if ( el && ( 'INPUT' === el.tagName || 'TEXTAREA' === el.tagName ) ) {
				if ( data.value ) {
					el.setAttribute( 'placeholder', data.value );
				} else {
					el.removeAttribute( 'placeholder' );
				}
			}
			return;
		}
		if ( data.type === 'set-link' ) {
			el = findById( data.id );
			if ( el && el.tagName === 'A' ) {
				el.setAttribute( 'href', data.href );
				if ( data.target ) {
					el.setAttribute( 'target', data.target );
					el.setAttribute( 'rel', 'noopener noreferrer' );
				} else {
					el.removeAttribute( 'target' );
				}
			}
			return;
		}
		if ( data.type === 'remove' ) {
			el = findById( data.id );
			if ( el ) {
				finishEdit( false );
				el.remove();
			}
			return;
		}
		if ( data.type === 'faq-apply' ) {
			// Re-arrange a list of questions by MOVING the existing elements,
			// never by writing new markup over them.
			//
			// The first version of this set innerHTML, which was wrong in a way
			// that only a real site shows: this theme's own script adds a class
			// to the list and binds a click handler to every <summary>, taking
			// over the accordion from the native <details> behaviour it
			// preventDefault()s. Replacing the markup threw those elements away
			// and with them their handlers — while the class it had added stayed
			// on the list, so the CSS went on expecting a script that was no
			// longer listening. The questions then would not open at all, in or
			// out of edit mode, until the page was reloaded.
			//
			// Moving a node does not detach its listeners. So reordering,
			// rewording and removing all keep the site's own behaviour intact.
			// Only a question that did not exist before is a genuinely new
			// element with nothing bound to it, and the host reloads for that
			// case rather than leaving one dead row in a working list.
			el = findById( data.id );
			if ( ! el ) {
				return;
			}
			finishEdit( false );
			clearSelected();

			var originals = [];
			for ( var oi = 0; oi < el.children.length; oi++ ) {
				if ( el.children[ oi ].querySelector( data.question ) ) {
					originals.push( el.children[ oi ] );
				}
			}
			var keep = [];
			( data.rows || [] ).forEach( function ( row ) {
				var node = row.from >= 0 && originals[ row.from ] ? originals[ row.from ] : null;
				if ( ! node ) {
					return; // a new question — the host is reloading, nothing to do here
				}
				var q = node.querySelector( data.question );
				if ( q ) {
					q.textContent = row.question;
				}
				var a = data.answer ? node.querySelector( data.answer ) : null;
				if ( a ) {
					a.textContent = row.answer;
				}
				el.appendChild( node ); // appending an existing child MOVES it
				keep.push( node );
			} );
			originals.forEach( function ( node ) {
				if ( keep.indexOf( node ) === -1 ) {
					node.remove();
				}
			} );

			applyKind( el );
			stampSubtree( el, basePathOf( el ) );
			post( { type: 'faq-applied', id: data.id } );
			return;
		}
		if ( data.type === 'collection-highlight' ) {
			// The safety valve for detection risk: before anything is saved,
			// the owner sees exactly which elements the popup considers "this
			// list" — a distinct color from ordinary selection so it reads as
			// "this is the group," not "this is what's selected." If the
			// detector grabbed the wrong container, it shows here, before a
			// save, not after.
			var hlParent = findById( data.id );
			if ( hlParent ) {
				for ( var hi = 0; hi < hlParent.children.length; hi++ ) {
					if ( shapeSignature( hlParent.children[ hi ] ) === data.shape ) {
						hlParent.children[ hi ].setAttribute( 'data-cve-collection-hl', 'true' );
					}
				}
			}
			return;
		}
		if ( data.type === 'collection-unhighlight' ) {
			var hlEls = document.querySelectorAll( '[data-cve-collection-hl]' );
			for ( var hj = 0; hj < hlEls.length; hj++ ) {
				hlEls[ hj ].removeAttribute( 'data-cve-collection-hl' );
			}
			return;
		}
		if ( data.type === 'collection-apply' ) {
			// Exactly faq-apply's approach (see the block comment above),
			// generalized: existing rows are MOVED, never cloned, so their own
			// listeners and any divergent (opaque) subtree survive untouched --
			// only their detected slot values are mutated in place. A row with
			// no existing node (from < 0, a genuinely new item) has no
			// source-index yet, so the host reloads for that case instead.
			//
			// Membership is re-derived from the live DOM via the same shape
			// signature collectionUnitFor used to detect the group in the first
			// place, rather than trusting paths captured when the popup opened
			// — the same reason faq-apply re-derives `originals` via a shape
			// test instead of carrying node references across the postMessage
			// boundary.
			el = findById( data.id );
			if ( ! el ) {
				return;
			}
			finishEdit( false );
			clearSelected();

			var origItems = [];
			for ( var ci = 0; ci < el.children.length; ci++ ) {
				if ( shapeSignature( el.children[ ci ] ) === data.shape ) {
					origItems.push( el.children[ ci ] );
				}
			}
			var keptItems = [];
			( data.rows || [] ).forEach( function ( row ) {
				var node = row.from >= 0 && origItems[ row.from ] ? origItems[ row.from ] : null;
				if ( ! node ) {
					return; // new item -- the host is reloading, nothing to do here
				}
				( data.slotSchema || [] ).forEach( function ( slot, si ) {
					var slotNode = resolveCollectionSlotNode( node, slot.path );
					if ( ! slotNode ) {
						return;
					}
					var v = row.values ? row.values[ si ] : null;
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
					} else if ( 'text-own' === slot.type ) {
						// Only the element's OWN text — textContent would take
						// the icon sitting beside it with it.
						setOwnText( slotNode, null == v ? '' : v );
					} else {
						slotNode.textContent = null == v ? '' : v;
					}
				} );
				el.appendChild( node ); // appending an existing child MOVES it
				keptItems.push( node );
			} );
			origItems.forEach( function ( node ) {
				if ( keptItems.indexOf( node ) === -1 ) {
					node.remove();
				}
			} );

			// A punctuated list (word · word · word) has separator nodes the
			// member moves above just left stranded at the front — the strip
			// would render as all its dots, then all its words. Re-weave them:
			// members are already in final order at the end of the parent, so
			// walk them and move one separator after each, honouring whether
			// the original pattern ended with one. Leftover separators (after
			// removals) go away with their members.
			var sepPool = collectionSeparators( el, keptItems );
			if ( sepPool && keptItems.length ) {
				// Whether the pattern ends with a separator cannot be read off
				// the DOM any more — the member moves above already happened —
				// but the COUNT still says it: n members with n separators is
				// a trailing pattern, n-1 is not. Removed members donate their
				// separators to the leftover pool either way.
				var trailing = sepPool.length >= keptItems.length;
				for ( var wi = 0; wi < keptItems.length; wi++ ) {
					var isLast = wi === keptItems.length - 1;
					if ( isLast && ! trailing ) {
						break;
					}
					var sep = sepPool.shift();
					if ( ! sep ) {
						break;
					}
					keptItems[ wi ].after( sep );
				}
				sepPool.forEach( function ( leftover ) {
					leftover.remove();
				} );
			}

			applyKind( el );
			stampSubtree( el, basePathOf( el ) );
			post( { type: 'collection-applied', id: data.id } );
			return;
		}
		if ( data.type === 'finish-text' ) {
			finishEdit( data.commit !== false );
			return;
		}
		if ( data.type === 'deselect' ) {
			finishEdit( true );
			clearSelected();
			return;
		}
	} );

	// Deliberately NOT set to 'true' here — see `enabled` above. The host
	// answers 'ready' with an explicit edit-mode message carrying its actual
	// current toggle state; this script never assumes ON on its own.
	// pageKey tells the host which key this page actually resolved to, which
	// is not always the one it asked for: opening ANY blog post lands on the
	// shared article template. The server is the authority on that mapping,
	// so the host adopts what it is told rather than guessing from the URL.
	// Keep the reader where they were across a reload.
	//
	// A structural edit — adding a question, removing one, reordering them — has
	// to be written and the canvas re-stamped before the new elements can be
	// clicked, and re-stamping means reloading this frame. Which threw the page
	// back to the top every time, so editing the FAQ at the bottom of a long
	// front page meant scrolling back down after every single change.
	//
	// Kept here rather than passed between the host and the frame because the
	// position belongs to this document: it is written as it changes and read
	// when the same address loads again, so nothing has to be timed, asked for,
	// or handed across the boundary at exactly the right moment.
	( function () {
		var KEY = 'cve-scroll:' + location.pathname + location.search;
		var saved = null;
		try {
			saved = window.sessionStorage.getItem( KEY );
		} catch ( e ) {
			return; // storage unavailable (private mode, blocked) — not worth failing over
		}
		var restoring = false;
		var target = saved ? parseInt( saved, 10 ) || 0 : 0;

		if ( target > 0 ) {
			// Retried until it sticks, not attempted once.
			//
			// A single scrollTo after two animation frames does not work on a
			// real page and it was wrong to assume it would: this front page
			// carries nine lazy-loaded images and thirteen with no width or
			// height, so at that moment the document is a fraction of its final
			// height. Asking to scroll to 3000px in an 800px document scrolls to
			// 800 and the page then grows underneath — which lands you back near
			// the top, exactly the symptom this was meant to fix.
			//
			// So: keep asking until the position actually holds, give up after a
			// couple of seconds, and stop instantly if the reader scrolls
			// themselves — being dragged back to where you were half a second ago
			// is worse than the jump.
			if ( 'scrollRestoration' in window.history ) {
				window.history.scrollRestoration = 'manual';
			}
			restoring = true;
			var deadline = Date.now() + 2500;
			var timer = null;

			var stop = function () {
				restoring = false;
				if ( timer ) {
					window.clearTimeout( timer );
					timer = null;
				}
				[ 'wheel', 'touchstart', 'keydown', 'mousedown' ].forEach( function ( evt ) {
					window.removeEventListener( evt, stop, true );
				} );
			};

			var tick = function () {
				if ( ! restoring ) {
					return;
				}
				if ( Math.abs( window.scrollY - target ) > 2 ) {
					window.scrollTo( 0, target );
				}
				// Settled only when the browser AGREED to the position — which
				// it cannot until the document is tall enough.
				if ( Math.abs( window.scrollY - target ) <= 2 || Date.now() > deadline ) {
					stop();
					return;
				}
				timer = window.setTimeout( tick, 80 );
			};

			[ 'wheel', 'touchstart', 'keydown', 'mousedown' ].forEach( function ( evt ) {
				window.addEventListener( evt, stop, true );
			} );
			window.requestAnimationFrame( tick );
			window.addEventListener( 'load', tick );
		}

		var pending = null;
		window.addEventListener(
			'scroll',
			function () {
				// Not while restoring: the intermediate, clamped positions our own
				// scrollTo produces would be written over the real target and the
				// next reload would restore to the wrong place.
				if ( restoring || pending ) {
					return;
				}
				pending = window.setTimeout( function () {
					pending = null;
					try {
						window.sessionStorage.setItem( KEY, String( window.scrollY ) );
					} catch ( e ) {}
				}, 150 );
			},
			{ passive: true }
		);
	} )();

	post( { type: 'ready', menuManaged: !! config.menuManaged, pageKey: config.pageKey || '', url: location.href } );
} )();
