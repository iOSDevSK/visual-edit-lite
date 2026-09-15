/* VE owns the chrome; the mounted WordPress editor owns every block and save. */
( function ( wp, config, model ) {
	'use strict';
	if ( ! wp || ! config || ! config.workspace || ! model ) { return; }
	var h = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var c = wp.components;
	var be = wp.blockEditor;
	var at = model.at;
	var values = window.ClaraVEValues;
	var presets = config.presets || {};
	var fontState = { selected: config.googleFonts || [], presets: presets.fontFamily || [], css: config.googleFontsCss || '', version: 0 };
	var nativeHeader = '.editor-header, .edit-post-header, .edit-site-header';
	var previewExtras = new Map();
	var activePopupId = null;
	var lastTab = 'content';
	var nativeAreaRequested = 0;
	// Where the person last pressed, in this document's viewport. The popup opens
	// beside the block at that height, so it appears where they were looking.
	var lastPointer = null;
	var pinnedPosition = ( function () { try { return JSON.parse( window.sessionStorage.getItem( 'clara-ve-popup-pin' ) || 'null' ); } catch ( error ) { return null; } }() );
	var TEXT_BLOCKS = [ 'core/paragraph', 'core/heading', 'core/list-item', 'core/button' ];
	var ENTRANCES = [ 'fade', 'fade-up', 'fade-down', 'zoom', 'slide-left', 'slide-right' ];
	var HOVERS = [ 'lift', 'grow', 'soften', 'dim' ];
	function applyHooks( name, value, context ) { return wp.hooks && wp.hooks.applyFilters ? wp.hooks.applyFilters( name, value, context ) : value; }
	function emit( name, detail ) { if ( window.ClaraVE && window.ClaraVE.emit ) { window.ClaraVE.emit( name, detail ); } }
	function canvasFrames() { return Array.prototype.slice.call( document.querySelectorAll( 'iframe[name="editor-canvas"]' ) ); }
	/** The block's wrapper and the offset of the document it lives in. */
	function blockElement( clientId ) {
		var selector = '[data-block="' + String( clientId || '' ).replace( /[^a-z0-9-]/gi, '' ) + '"]';
		var node = document.querySelector( selector );
		if ( node ) { return { node: node, offset: { left: 0, top: 0 } }; }
		var found = null;
		canvasFrames().some( function ( frame ) {
			try { node = frame.contentDocument.querySelector( selector ); } catch ( error ) { node = null; }
			if ( node ) { var rect = frame.getBoundingClientRect(); found = { node: node, offset: { left: rect.left, top: rect.top } }; }
			return !! found;
		} );
		return found;
	}
	function placePopup( rect, pointer, size, viewport ) { return values.placePopup( rect, pointer, size, viewport ); }
	function designUnlocked( settings ) { return !! ( settings && settings.disableContentOnlyForUnsyncedPatterns && settings.disableContentOnlyForTemplateParts ); }
	function canSetDesignLock( registry ) {
		try { var editor = registry.dispatch( 'core/editor' ); return !! ( editor && editor.updateEditorSettings ); } catch ( error ) { return false; }
	}
	/**
	 * WordPress locks the design of pattern sections and template parts to
	 * content-only. This is the editor's own "Enable editing all patterns"
	 * command: an in-memory editor setting, never saved and never an undo step.
	 */
	function setDesignLock( registry, unlocked ) {
		if ( ! canSetDesignLock( registry ) ) { return false; }
		registry.dispatch( 'core/editor' ).updateEditorSettings( { disableContentOnlyForUnsyncedPatterns: !! unlocked, disableContentOnlyForTemplateParts: !! unlocked } );
		try { window.sessionStorage.setItem( 'clara-ve-design-unlocked', unlocked ? '1' : '' ); } catch ( error ) {}
		emit( 'unlock', { unlocked: !! unlocked } );
		return true;
	}
	function currentPostType( select ) {
		try { var editor = select( 'core/editor' ); return editor && editor.getCurrentPostType ? editor.getCurrentPostType() || '' : ''; } catch ( error ) { return ''; }
	}
	/** Sections live in a page's own content or in a template's main element. */
	function isSectionRoot( editor, rootClientId, postType ) {
		if ( ! rootClientId ) { return !! postType && [ 'wp_template', 'wp_template_part' ].indexOf( postType ) < 0; }
		var name = editor.getBlockName ? editor.getBlockName( rootClientId ) : '';
		var attributes = editor.getBlockAttributes ? editor.getBlockAttributes( rootClientId ) || {} : {};
		return name === 'core/post-content' || ( name === 'core/group' && attributes.tagName === 'main' );
	}
	function insertionTarget( select ) {
		var editor = select( 'core/block-editor' ); var postType = currentPostType( select );
		var selected = editor.getSelectedBlockClientId ? editor.getSelectedBlockClientId() : null;
		var chain = selected ? editor.getBlockParents( selected ).concat( [ selected ] ) : [];
		for ( var i = chain.length - 1; i >= 0; i-- ) {
			var root = editor.getBlockRootClientId( chain[ i ] ) || '';
			if ( isSectionRoot( editor, root, postType ) ) { return { rootClientId: root, index: editor.getBlockIndex( chain[ i ] ) + 1 }; }
		}
		var main = ( editor.getClientIdsWithDescendants ? editor.getClientIdsWithDescendants() : [] ).find( function ( id ) { return isSectionRoot( editor, id, postType ); } ) || '';
		return { rootClientId: main, index: editor.getBlockOrder( main ).length };
	}
	/** The active theme's own sections, allowed at this insertion point. */
	function themePatterns( editor, rootClientId ) {
		var all = editor.__experimentalGetAllowedPatterns ? editor.__experimentalGetAllowedPatterns( rootClientId || undefined ) || [] : [];
		var prefixes = [ config.stylesheet, config.template ].filter( Boolean ).map( function ( slug ) { return slug + '/'; } );
		return all.filter( function ( pattern ) {
			var mine = pattern.source === 'theme' || prefixes.some( function ( prefix ) { return String( pattern.name ).indexOf( prefix ) === 0; } );
			// Headers and footers belong to template parts, not between sections.
			var part = ( pattern.blockTypes || [] ).some( function ( name ) { return String( name ).indexOf( 'core/template-part/' ) === 0; } ) || ( pattern.categories || [] ).some( function ( name ) { return name === 'header' || name === 'footer'; } );
			return mine && ! part;
		} );
	}
	function insertPattern( registry, pattern, target, blocks ) {
		var list = ( blocks || pattern.blocks || [] ).map( function ( item ) { return wp.blocks.cloneBlock( item ); } );
		if ( ! list.length ) { return false; }
		if ( list.length === 1 ) {
			list[0] = wp.blocks.cloneBlock( list[0], { metadata: Object.assign( {}, list[0].attributes.metadata, { name: pattern.title, patternName: pattern.name } ) } );
		}
		registry.dispatch( 'core/block-editor' ).insertBlocks( list, target.index, target.rootClientId || undefined, true );
		return true;
	}
	wp.hooks.addFilter( 'blocks.registerBlockType', 'clara-ve/extras-schema', function ( settings ) {
		return Object.assign( {}, settings, { attributes: Object.assign( {}, settings.attributes, { claraVe: { type: 'object' } } ) } );
	} );
	function store( name, dispatch ) {
		try { return dispatch ? wp.data.dispatch( name ) : wp.data.select( name ); } catch ( error ) { return null; }
	}
	function portal( node ) { return wp.element.createPortal( node, document.body ); }
	function notifyHost( type, data ) {
		if ( window.parent === window ) { return; }
		window.parent.postMessage( Object.assign( { channel: 'clara-ve-workspace', version: 1, session: config.session, type: type }, data ), window.location.origin );
	}
	function showNative() { document.body.classList.remove( 'cve-native-collapsed' ); }
	function nativeAction( method, value, registry, quiet ) {
		var names = [ 'core/editor', config.isSiteEditor ? 'core/edit-site' : 'core/edit-post' ];
		for ( var i = 0; i < names.length; i++ ) {
			var actions;
			try { actions = registry ? registry.dispatch( names[ i ] ) : store( names[ i ], true ); } catch ( error ) { actions = null; }
			if ( actions && typeof actions[ method ] === 'function' ) { actions[ method ]( value ); return true; }
		}
		if ( method === 'setDeviceType' ) { return nativeAction( '__experimentalSetPreviewDeviceType', value, registry, quiet ); }
		if ( ! quiet ) { showNative(); }
		return false;
	}
	function advanced() {
		showNative();
		var ui = store( 'core/interface', true );
		if ( ui && ui.enableComplementaryArea ) {
			ui.enableComplementaryArea( 'core', 'edit-post/block' );
		}
	}
	function button( text, onClick, extra ) { return h( 'button', Object.assign( { type: 'button', onClick: onClick }, extra ), text ); }
	function Field( props ) {
		return h( 'label', { className: 'cve-w-field' }, h( 'span', null, props.label ),
			props.options ? h( 'select', { value: props.value == null ? '' : props.value, onChange: function ( event ) { props.onChange( event.target.value ); } },
				props.options.map( function ( option ) { return h( 'option', { key: option.value, value: option.value }, option.label ); } ) ) :
				h( 'input', { value: props.value == null ? '' : props.value, type: props.type || 'text', placeholder: props.placeholder || __( 'Inherit', 'visual-edit-lite' ), autoFocus: props.autoFocus, onKeyDown: props.onKeyDown,
					onChange: function ( event ) { props.onChange( event.target.value ); } } ) );
	}
	function NumberField( props ) {
		var parsed = values.number( props.value );
		var unit = parsed ? parsed.unit : ( props.unit === undefined ? 'px' : props.unit );
		var units = props.units || [ 'px', '%', 'rem', 'em', 'vw', 'vh' ];
		if ( units.indexOf( unit ) < 0 ) { units = [ unit ].concat( units ); }
		var canStep = !! parsed || ! props.value;
		function nudge( delta ) {
			var next = values.step( props.value, delta, { min: props.min, max: props.max, unit: unit } );
			if ( next !== null ) { props.onChange( next ); }
		}
		return h( 'div', { className: 'cve-w-field cve-w-number' }, h( 'span', { title: props.description }, props.label ),
			button( '−', function () { nudge( -( props.step || 1 ) ); }, { className: 'cve-w-step', disabled: ! canStep, 'aria-label': __( 'Decrease', 'visual-edit-lite' ) + ' ' + ( props.description || props.label ) } ),
			h( 'input', { type: 'text', value: parsed ? parsed.value : props.value || '', placeholder: __( 'Inherit', 'visual-edit-lite' ), 'aria-label': props.description || props.label,
				onChange: function ( event ) {
					var next = event.target.value; var numeric = values.number( next );
					if ( next !== '' && ! numeric && ! /^-?\d*\.?\d*$/.test( next ) ) { return; }
					props.onChange( numeric && ! numeric.unit ? next + unit : next );
				}, onKeyDown: function ( event ) {
					if ( canStep && ( event.key === 'ArrowUp' || event.key === 'ArrowDown' ) ) { event.preventDefault(); nudge( ( event.key === 'ArrowDown' ? -1 : 1 ) * ( props.step || 1 ) * ( event.shiftKey ? 10 : 1 ) ); }
				} } ),
			h( 'select', { value: unit, disabled: ! canStep, 'aria-label': ( props.description || props.label ) + ' ' + __( 'unit', 'visual-edit-lite' ), onChange: function ( event ) { props.onChange( ( parsed ? parsed.value : 0 ) + event.target.value ); } }, units.map( function ( item ) { return h( 'option', { key: item, value: item }, item || '—' ); } ) ),
			button( '+', function () { nudge( props.step || 1 ); }, { className: 'cve-w-step', disabled: ! canStep, 'aria-label': __( 'Increase', 'visual-edit-lite' ) + ' ' + ( props.description || props.label ) } ) );
	}
	function GradientField( props ) {
		var selected = ( props.presets || [] ).find( function ( item ) { return item.slug === props.slug; } );
		var css = props.value || ( selected && selected.value ) || '';
		var palette = props.palette || [];
		var state = values.parseGradient( css );
		if ( ! css ) { state = { from: palette[0] ? palette[0].value : '#000000', to: palette[1] ? palette[1].value : '#ffffff', direction: '135deg' }; }
		function changeStop( key, value ) { var next = Object.assign( {}, state ); next[key] = value; props.onChange( values.makeGradient( next.from, next.to, next.direction ) ); }
		var directions = [ { value: '135deg', label: __( '↘ diagonal', 'visual-edit-lite' ) }, { value: 'to right', label: __( '→ left to right', 'visual-edit-lite' ) }, { value: 'to bottom', label: __( '↓ top to bottom', 'visual-edit-lite' ) }, { value: '45deg', label: __( '↗ diagonal, upward', 'visual-edit-lite' ) }, { value: 'to left', label: __( '← right to left', 'visual-edit-lite' ) }, { value: 'to top', label: __( '↑ bottom to top', 'visual-edit-lite' ) } ];
		if ( state && ! directions.some( function ( item ) { return item.value === state.direction; } ) ) { directions.push( { value: state.direction, label: state.direction } ); }
		return h( Fragment, null,
			h( 'div', { className: 'cve-w-gradient', 'aria-label': __( 'Gradient preview', 'visual-edit-lite' ) }, h( 'div', { style: { background: css || 'transparent' } } ) ),
			h( 'div', { className: 'cve-w-swatches' }, ( props.presets || [] ).map( function ( item ) { return button( '', function () { props.onPreset( item.slug ); }, { key: item.slug, title: item.name, 'aria-label': item.name, 'aria-pressed': props.slug === item.slug, style: { background: item.value } } ); } ),
				props.custom && values.paletteGradients( palette ).map( function ( item ) { return button( '', function () { props.onChange( item.value ); }, { key: item.name, title: item.name, 'aria-label': item.name, 'aria-pressed': css === item.value, style: { background: item.value } } ); } ),
				button( '×', function () { props.onChange( '' ); }, { 'aria-label': __( 'No gradient', 'visual-edit-lite' ), title: __( 'No gradient', 'visual-edit-lite' ) } ) ),
			props.custom && h( Fragment, null,
				state && [ [ 'from', __( 'From', 'visual-edit-lite' ) ], [ 'to', __( 'To', 'visual-edit-lite' ) ] ].map( function ( item ) {
					var choices = palette.map( function ( color ) { return { label: color.name, value: color.value }; } );
					if ( ! choices.some( function ( color ) { return color.value === state[item[0]]; } ) ) { choices.push( { label: state[item[0]], value: state[item[0]] } ); }
					return h( 'div', { key: item[0], className: 'cve-w-gradient-stop' }, h( Field, { label: item[1], value: state[item[0]], options: choices, onChange: function ( value ) { changeStop( item[0], value ); } } ), h( 'input', { type: 'color', 'aria-label': item[1] + ' ' + __( 'colour', 'visual-edit-lite' ), value: /^#[0-9a-f]{6}$/i.test( state[item[0]] ) ? state[item[0]] : '#ffffff', onChange: function ( event ) { changeStop( item[0], event.target.value ); } } ) );
				} ),
				state && h( Field, { label: __( 'Direction', 'visual-edit-lite' ), value: state.direction, options: directions, onChange: function ( value ) { changeStop( 'direction', value ); } } ),
				! state && h( 'p', { className: 'cve-w-note' }, __( 'This gradient has custom stops. Edit them below without replacing the original.', 'visual-edit-lite' ) ),
				h( Field, { label: __( 'Custom', 'visual-edit-lite' ), value: css, placeholder: 'linear-gradient(135deg, #000 0%, #fff 100%)', onChange: props.onChange } ),
				c.GradientPicker && h( 'details', { className: 'cve-w-gradient-advanced' }, h( 'summary', null, __( 'Edit gradient stops', 'visual-edit-lite' ) ), h( c.GradientPicker, { value: css || undefined, onChange: props.onChange, gradients: [], clearable: false } ) ) ) );
	}
	function options( values ) {
		return [ { label: __( 'Inherit', 'visual-edit-lite' ), value: '' } ].concat( values.map( function ( value ) { return { label: value, value: value }; } ) );
	}
	function Section( props ) {
		var state = useState( function () {
			try { var saved = JSON.parse( localStorage.getItem( 'clara-ve-open-sections' ) || '{}' ); return saved[ props.title ] === undefined ? !! props.open : saved[ props.title ]; } catch ( error ) { return !! props.open; }
		} );
		return h( 'section', { className: 'cve-w-section' }, button( props.title, function () {
			var next = ! state[0]; state[1]( next );
			try { var saved = JSON.parse( localStorage.getItem( 'clara-ve-open-sections' ) || '{}' ); saved[ props.title ] = next; localStorage.setItem( 'clara-ve-open-sections', JSON.stringify( saved ) ); } catch ( error ) {}
		}, { 'aria-expanded': state[0], className: 'cve-w-section-title' } ), h( 'div', { hidden: ! state[0] }, props.children ) );
	}
	function FontPicker( props ) {
		var catalog = useState( null ); var query = useState( '' ); var selected = useState( fontState.selected );
		var preview = useState( '' ); var visible = useState( 60 ); var retry = useState( 0 );
		var busy = useState( false ); var error = useState( '' ); var limit = config.googleFontsMax || 5;
		useEffect( function () {
			var alive = true;
			error[1]( '' );
			wp.apiFetch( { path: '/clara-ve/v1/google-fonts' } ).then( function ( result ) {
				if ( alive ) { catalog[1]( result.catalog ); selected[1]( result.selected ); }
			} ).catch( function ( err ) { if ( alive ) { error[1]( err.message ); } } );
			return function () { alive = false; };
		}, [ retry[0] ] );
		function save() {
			busy[1]( true ); error[1]( '' );
			wp.apiFetch( { path: '/clara-ve/v1/google-fonts', method: 'POST', data: { families: selected[0] } } ).then( function ( result ) {
				fontState = { selected: result.selected, presets: result.presets, css: result.cssUrl, families: result.fontFamilies, version: fontState.version + 1 };
				presets.fontFamily = result.presets;
				window.dispatchEvent( new CustomEvent( 'clara-ve-fonts-changed' ) );
				props.onClose();
			} ).catch( function ( err ) { error[1]( err.message ); } ).finally( function () { busy[1]( false ); } );
		}
		var found = ( catalog[0] || [] ).filter( function ( font ) { return font.family.toLowerCase().indexOf( query[0].toLowerCase() ) !== -1; } );
		return h( c.Modal, { title: __( 'Google Fonts', 'visual-edit-lite' ), onRequestClose: busy[0] ? function () {} : props.onClose, className: 'cve-w-dialog' },
			preview[0] && h( 'link', { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent( preview[0] ) + ':wght@400&display=swap' } ),
			error[0] && h( c.Notice, { status: 'error', isDismissible: false }, error[0] ),
			error[0] && ! catalog[0] && button( __( 'Retry', 'visual-edit-lite' ), function () { retry[1]( retry[0] + 1 ); } ),
			h( 'p', null, __( 'Save fonts updates the font library for the whole site.', 'visual-edit-lite' ) ),
			h( Field, { label: __( 'Search', 'visual-edit-lite' ), value: query[0], onChange: function ( value ) { query[1]( value ); visible[1]( 60 ); }, placeholder: __( 'Search Google Fonts…', 'visual-edit-lite' ) } ),
			h( 'div', { className: 'cve-w-font-chips' }, selected[0].map( function ( font ) {
				return button( font.family + ' ×', function () { selected[1]( selected[0].filter( function ( item ) { return item.family !== font.family; } ) ); }, { key: font.family, disabled: busy[0] } );
			} ) ),
			h( 'p', null, selected[0].length + ' / ' + limit ),
			! catalog[0] && ! error[0] && h( c.Spinner ),
			h( 'div', { className: 'cve-w-font-list', onScroll: function ( event ) { var list = event.currentTarget; if ( list.scrollTop + list.clientHeight >= list.scrollHeight - 150 ) { visible[1]( function ( count ) { return Math.min( count + 60, found.length ); } ); } } }, found.slice( 0, visible[0] ).map( function ( font ) {
				var kept = selected[0].some( function ( item ) { return item.family === font.family; } );
				return h( 'div', { key: font.family, onMouseEnter: function () { preview[1]( font.family ); }, onFocus: function () { preview[1]( font.family ); } }, h( 'span', { style: preview[0] === font.family ? { fontFamily: '"' + font.family.replace( /["\\]/g, '' ) + '", sans-serif' } : undefined }, font.family ), button( kept ? '✓' : '+', function () { selected[1]( selected[0].concat( [ font ] ) ); }, { disabled: kept || busy[0] || selected[0].length >= limit, 'aria-label': __( 'Add font', 'visual-edit-lite' ) + ': ' + font.family } ) );
			} ) ),
			found.length > visible[0] && button( __( 'Load more fonts', 'visual-edit-lite' ), function () { visible[1]( visible[0] + 60 ); } ),
			button( __( 'Save fonts', 'visual-edit-lite' ), save, { disabled: busy[0] || ! catalog[0] } ) );
	}
	/** A portal keeps this component in the owning editor's registry. */
	function NativeFontSettings() {
		var registry = wp.data.useRegistry(); var version = useState( fontState.version );
		var settings = wp.data.useSelect( function ( select ) { return select( 'core/block-editor' ).getSettings(); }, [] );
		useEffect( function () {
			function refresh() { version[1]( fontState.version ); }
			window.addEventListener( 'clara-ve-fonts-changed', refresh );
			return function () { window.removeEventListener( 'clara-ve-fonts-changed', refresh ); };
		}, [] );
		useEffect( function () {
			if ( ! version[0] || ! settings ) { return; }
			var next = model.fontSettings( { __experimentalFeatures: settings.__experimentalFeatures || {} }, fontState.families );
			if ( model.equal( settings.__experimentalFeatures || {}, next.__experimentalFeatures ) ) { return; }
			var actions = registry.dispatch( 'core/block-editor' );
			// updateSettings intentionally strips private settings on newer WP.
			// Feature-detect the native provider action instead of editing entities.
			if ( actions.__experimentalUpdateSettings ) { actions.__experimentalUpdateSettings( next ); }
		}, [ settings, version[0], registry ] );
		return null;
	}
	/** Refresh font CSS in both documents without reloading unsaved content. */
	function FontsPreview() {
		useEffect( function () {
			function apply( doc ) {
				if ( ! doc || ! doc.head ) { return; }
				// enqueue_block_assets uses the -editor handle. Reuse that link
				// instead of creating a second request or leaving it stale on removal.
				var links = doc.querySelectorAll( '#clara-ve-google-fonts-editor-css, #clara-ve-google-fonts-css, #cve-workspace-fonts' );
				var link = links[0];
				Array.prototype.slice.call( links, 1 ).forEach( function ( duplicate ) { duplicate.remove(); } );
				if ( ! fontState.css ) { if ( link ) { link.remove(); } }
				else {
					if ( ! link ) { link = doc.createElement( 'link' ); link.id = 'cve-workspace-fonts'; link.rel = 'stylesheet'; doc.head.appendChild( link ); }
					if ( link.getAttribute( 'href' ) !== fontState.css ) { link.setAttribute( 'href', fontState.css ); }
				}
				var style = doc.getElementById( 'cve-workspace-font-presets' );
				if ( ! style ) { style = doc.createElement( 'style' ); style.id = 'cve-workspace-font-presets'; doc.head.appendChild( style ); }
				// The native style engine owns the theme/custom font presets. Only
				// provide VE's selected Google fonts here; overriding every preset
				// would mask unsaved Global Styles changes in the canvas.
				var css = fontState.presets.filter( function ( preset ) { return fontState.selected.some( function ( font ) { return font.family === preset.name; } ) && /^[a-z0-9-]+$/.test( preset.slug ) && ! /[{}<>;]/.test( preset.value ); } ).map( function ( preset ) {
					return ':root{--wp--preset--font-family--' + preset.slug + ':' + preset.value + ';}.has-' + preset.slug + '-font-family{font-family:' + preset.value + '!important;}';
				} ).join( '' );
				if ( style.textContent !== css ) { style.textContent = css; }
			}
			function refresh() {
				apply( document );
				document.querySelectorAll( 'iframe[name="editor-canvas"]' ).forEach( function ( frame ) { try { apply( frame.contentDocument ); } catch ( error ) {} } );
			}
			refresh();
			var observer = new MutationObserver( refresh ); observer.observe( document.body, { subtree: true, childList: true } );
			window.addEventListener( 'clara-ve-fonts-changed', refresh ); document.addEventListener( 'load', refresh, true );
			return function () { observer.disconnect(); window.removeEventListener( 'clara-ve-fonts-changed', refresh ); document.removeEventListener( 'load', refresh, true ); };
		}, [] );
		return null;
	}
	function ExtrasPreview() {
		useEffect( function () {
			function apply() {
				var css = '';
				previewExtras.forEach( function ( extras, id ) {
					if ( ! /^[a-z0-9-]+$/i.test( id ) ) { return; }
					var selector = '[data-block="' + id + '"]';
					css += model.responsiveCss( selector, extras.responsive || {} );
					css += model.formCss( selector, extras.form || {} );
					[ 'before', 'after' ].forEach( function ( pseudo ) {
						var values = ( extras.ornaments || {} )[ pseudo ] || {}; var body = '';
						if ( values.hidden === true ) { css += selector + '::' + pseudo + '{content:""!important;display:none!important;}'; return; }
						[ 'content', 'color', 'font-size', 'font-family', 'font-weight', 'line-height' ].forEach( function ( property ) {
							var value = values[ property ];
							if ( value === undefined ) { return; }
							if ( property === 'content' ) { value = JSON.stringify( String( value ) ).replace( /</g, '\\3c ' ).replace( />/g, '\\3e ' ); }
							else if ( /[{}<>;]|url\s*\(/i.test( value ) ) { return; }
							body += property + ':' + value + '!important;';
						} );
						if ( body ) { css += selector + '::' + pseudo + '{' + body + '}'; }
					} );
				} );
				function inject( doc ) {
					if ( ! doc || ! doc.head ) { return; }
					var style = doc.getElementById( 'cve-workspace-extras' );
					if ( ! style ) { style = doc.createElement( 'style' ); style.id = 'cve-workspace-extras'; doc.head.appendChild( style ); }
					if ( style.textContent !== css ) { style.textContent = css; }
				}
				inject( document ); document.querySelectorAll( 'iframe[name="editor-canvas"]' ).forEach( function ( frame ) { try { inject( frame.contentDocument ); } catch ( error ) {} } );
			}
			apply(); var observer = new MutationObserver( apply ); observer.observe( document.body, { subtree: true, childList: true } );
			window.addEventListener( 'clara-ve-extras-changed', apply ); document.addEventListener( 'load', apply, true );
			return function () { observer.disconnect(); window.removeEventListener( 'clara-ve-extras-changed', apply ); document.removeEventListener( 'load', apply, true ); };
		}, [] );
		return null;
	}
	function Popup( props ) {
		var registry = wp.data.useRegistry();
		var block = props.block;
		var attributes = block.attributes;
		var changes = useRef( {} );
		var fontOpen = useState( false ); var fontVersion = useState( 0 );
		var tabState = useState( lastTab ); var customRows = useState( {} ); var pinned = useState( !! pinnedPosition );
		var position = useState( null ); var panelRef = useRef( null ); var dragged = useRef( false );
		var breakpoint = useState( 'desktop' );
		var legacyRules = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var anchor = ( attributes.className || '' ).split( /\s+/ ).find( function ( token ) { return /^cve-r-[a-z0-9]{4,20}$/.test( token ); } );
			if ( ! anchor || ! editor || ! editor.getEditedPostAttribute ) { return null; }
			try {
				var meta = editor.getEditedPostAttribute( 'meta' ) || {};
				var rules = JSON.parse( meta[ config.responsiveMeta || '_clara_ve_responsive' ] || '{}' );
				return rules[ anchor ] ? { anchor: anchor, screens: rules[ anchor ] } : null;
			} catch ( error ) { return null; }
		}, [ block.clientId, attributes.className ] );
		var mode = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' ); return editor.getBlockEditingMode ? editor.getBlockEditingMode( block.clientId ) : 'default';
		}, [ block.clientId ] );
		var nativeArea = wp.data.useSelect( function ( select ) { try { var ui = select( 'core/interface' ); return ui && ui.getActiveComplementaryArea ? ui.getActiveComplementaryArea( 'core' ) : ''; } catch ( error ) { return ''; } }, [] );
		useEffect( function () { if ( nativeArea && tabState[0] === 'advanced' ) { tabState[1]( lastTab === 'advanced' ? 'content' : lastTab ); } }, [ nativeArea ] );
		useEffect( function () { function refresh() { fontVersion[1]( function ( value ) { return value + 1; } ); } window.addEventListener( 'clara-ve-fonts-changed', refresh ); return function () { window.removeEventListener( 'clara-ve-fonts-changed', refresh ); }; }, [] );
		useEffect( function () {
			function constrain() { position[1]( function ( value ) { return value ? { left: Math.max( 8, Math.min( value.left, window.innerWidth - 356 ) ), top: Math.max( 70, Math.min( value.top, window.innerHeight - 100 ) ) } : value; } ); }
			window.addEventListener( 'resize', constrain ); return function () { window.removeEventListener( 'resize', constrain ); };
		}, [] );
		var placementDevice = wp.data.useSelect( function ( select ) { try { var editor = select( 'core/editor' ); return editor && editor.getDeviceType ? editor.getDeviceType() || '' : ''; } catch ( error ) { return ''; } }, [] );
		var placement = dragged.current ? 'dragged' : placementDevice;
		useEffect( function () {
			if ( ! panelRef.current || placement === 'dragged' ) { return; }
			var size = { width: panelRef.current.offsetWidth || 340, height: panelRef.current.offsetHeight || 460 };
			var viewport = { width: window.innerWidth, height: window.innerHeight, top: 66 };
			if ( pinnedPosition ) {
				position[1]( { left: Math.max( 8, Math.min( pinnedPosition.left, viewport.width - size.width - 8 ) ), top: Math.max( viewport.top, Math.min( pinnedPosition.top, viewport.height - 100 ) ) } );
				return;
			}
			var found = blockElement( block.clientId );
			if ( ! found ) { position[1]( { left: Math.max( 8, viewport.width - size.width - 24 ), top: 132 } ); return; }
			var box = found.node.getBoundingClientRect();
			var rect = { left: box.left + found.offset.left, right: box.right + found.offset.left, top: box.top + found.offset.top, bottom: box.bottom + found.offset.top };
			var pointer = lastPointer && Date.now() - lastPointer.t < 2000 ? lastPointer : null;
			position[1]( placePopup( rect, pointer, size, viewport ) );
		}, [ block.clientId, placement ] );
		// The popup does not follow the page, but it never runs off the screen:
		// when it moves or its content grows (another tab, an unlocked design)
		// it rises.
		function keepVisible() {
			var node = panelRef.current; if ( ! node || window.innerWidth <= 782 ) { return; }
			var rect = node.getBoundingClientRect();
			if ( rect.bottom > window.innerHeight - 8 && rect.top > 66 ) {
				position[1]( function ( value ) { var top = Math.max( 66, window.innerHeight - 8 - rect.height ); return value && value.top !== top ? { left: value.left, top: top } : value; } );
			}
		}
		useEffect( keepVisible, [ position[0] ] );
		useEffect( function () {
			if ( ! panelRef.current || typeof ResizeObserver === 'undefined' ) { return; }
			var observer = new ResizeObserver( keepVisible );
			observer.observe( panelRef.current );
			return function () { observer.disconnect(); };
		}, [] );
		function current() { return registry.select( 'core/block-editor' ).getBlock( block.clientId ); }
		function canWrite( path, context ) {
			var live = current(); if ( ! live || live.name !== block.name ) { return false; }
			var editor = registry.select( 'core/block-editor' );
			var liveMode = editor.getBlockEditingMode ? editor.getBlockEditingMode( block.clientId ) : 'default';
			return model.canEditAttribute( wp.blocks.getBlockType( live.name ), live.attributes, liveMode, path, context );
		}
		function writeMany( values, context ) {
			var live = current(); if ( ! live ) { return; }
			if ( Object.prototype.hasOwnProperty.call( values, 'style' ) ) {
				var expanded = Object.assign( {}, values ); delete expanded.style;
				model.leaves( live.attributes.style, values.style, 'style', function ( path, value ) { expanded[ path ] = value; } ); values = expanded;
			}
			// Recheck at write time: a media modal may outlive a mode/binding change.
			if ( ! Object.keys( values ).every( function ( path ) { return canWrite( path, context ); } ) ) { return; }
			// One undo level per popup session: close whatever level WordPress had
			// open before the first write, so Undo after Apply reverts this popup only.
			if ( ! Object.keys( changes.current ).length ) { markPersistent(); } else { mergeNext(); }
			var next = live.attributes;
			Object.keys( values ).forEach( function ( path ) {
				if ( ! changes.current[ path ] ) { changes.current[ path ] = { before: model.copy( at( live.attributes, path ) ) }; }
				changes.current[ path ].after = values[ path ] === '' ? undefined : model.copy( values[ path ] );
				changes.current[ path ].context = context;
				next = model.put( next, path, values[ path ] );
			} );
			props.setAttributes( model.patch( live.attributes, next ) );
		}
		function write( path, value ) { var values = {}; values[ path ] = value; writeMany( values ); }
		function mergeNext() { try { var actions = registry.dispatch( 'core/block-editor' ); if ( actions.__unstableMarkNextChangeAsNotPersistent ) { actions.__unstableMarkNextChangeAsNotPersistent(); } } catch ( error ) {} }
		function markPersistent() { try { var actions = registry.dispatch( 'core/block-editor' ); if ( actions.__unstableMarkLastChangeAsPersistent ) { actions.__unstableMarkLastChangeAsPersistent(); } } catch ( error ) {} }
		function cancel() {
			var live = current();
			if ( live ) {
				var permitted = {};
				Object.keys( changes.current ).forEach( function ( path ) { if ( canWrite( path, changes.current[path].context ) ) { permitted[path] = changes.current[path]; } } );
				props.setAttributes( model.patch( live.attributes, model.revert( live.attributes, permitted ) ) );
			}
			changes.current = {}; markPersistent(); props.onClose();
		}
		function openAdvanced() {
			var ui;
			try { ui = registry.dispatch( 'core/interface' ); } catch ( error ) { ui = null; }
			if ( ! be.BlockInspector || ! ui || ! ui.disableComplementaryArea ) { props.onClose(); advanced(); return; }
			// A Slot has one owner. Unmount the sidebar before mounting the real
			// inspector in VE; do not clone plugin panels or move React's DOM.
			ui.disableComplementaryArea( 'core' ); tabState[1]( 'advanced' );
		}
		function supports( path ) {
			var settings = registry.select( 'core/block-editor' ).getSettings();
			var features = settings.__experimentalFeatures || {};
			var blockFeatures = ( features.blocks || {} )[ block.name ] || {};
			if ( at( blockFeatures, path ) === false || ( at( blockFeatures, path ) === undefined && at( features, path ) === false ) ) { return false; }
			if ( path === 'color.text' || path === 'color.background' ) {
				return wp.blocks.hasBlockSupport( block.name, 'color', false ) && wp.blocks.hasBlockSupport( block.name, path, true );
			}
			var nativePath = path.replace( /^border\./, '__experimentalBorder.' );
			var experimental = path.replace( /typography\.(fontFamily|fontWeight|fontStyle|textTransform|textDecoration|letterSpacing)/, function ( match, name ) { return 'typography.__experimental' + name[0].toUpperCase() + name.slice(1); } );
			return wp.blocks.hasBlockSupport( block.name, nativePath, false ) || wp.blocks.hasBlockSupport( block.name, experimental, false );
		}
		function setting( path ) {
			var features = registry.select( 'core/block-editor' ).getSettings().__experimentalFeatures || {};
			var specific = at( ( features.blocks || {} )[ block.name ], path );
			return specific === undefined ? at( features, path ) : specific;
		}
		function styleValue( path ) { var value = at( attributes, 'style.' + path ); return typeof value === 'string' || typeof value === 'number' ? value : ''; }
		function styleWrite( path, value ) { write( 'style.' + path, value ); }
		function field( label, path, choices ) {
			var numeric = ! choices && /(?:fontSize|lineHeight|letterSpacing|minHeight|width|blockGap)$/.test( path );
			return h( numeric ? NumberField : Field, { key: path, label: label, value: styleValue( path ), onChange: function ( value ) { styleWrite( path, value ); }, options: choices && options( choices ), min: /letterSpacing$/.test( path ) ? undefined : 0, unit: /lineHeight$/.test( path ) ? '' : 'px', step: /lineHeight|letterSpacing$/.test( path ) ? 0.1 : 1 } );
		}
		function preset( label, group, attribute, path, color ) {
			var choices = presets[ group ] || [];
			var value = attributes[ attribute ] ? 'preset:' + attributes[ attribute ] : styleValue( path );
			var custom = !( group === 'fontSizes' && setting( 'typography.customFontSize' ) === false ) && !( group === 'colors' && setting( 'color.custom' ) === false ) && !( group === 'gradients' && setting( 'color.customGradient' ) === false );
			var rawValue = value && value.indexOf( 'preset:' ) !== 0;
			var showCustom = custom && ( rawValue || customRows[0][ block.clientId + ':' + path ] );
			return h( Fragment, { key: path }, h( Field, { label: label, value: showCustom && ! rawValue ? '__custom' : value, options: [ { label: __( 'Inherit', 'visual-edit-lite' ), value: '' } ].concat( choices.map( function ( item ) { return { label: item.name, value: 'preset:' + item.slug }; } ) ).concat( rawValue ? [ { label: value, value: value } ] : [] ).concat( custom && ! rawValue ? [ { label: __( 'Custom…', 'visual-edit-lite' ), value: '__custom' } ] : [] ), onChange: function ( next ) {
				var shown = Object.assign( {}, customRows[0] ); shown[ block.clientId + ':' + path ] = next === '__custom'; customRows[1]( shown );
				if ( next === '__custom' ) { return; }
				var values = {}; values[ attribute ] = next.indexOf( 'preset:' ) === 0 ? next.slice(7) : undefined; values[ 'style.' + path ] = next.indexOf( 'preset:' ) === 0 ? undefined : next; writeMany( values );
			} } ), showCustom && h( group === 'fontSizes' ? NumberField : Field, { label: __( 'Custom', 'visual-edit-lite' ), description: label, value: styleValue( path ), min: 0, onChange: function ( next ) { var values = {}; values[ attribute ] = undefined; values[ 'style.' + path ] = next; writeMany( values ); } } ),
				color && custom && h( 'input', { type: 'color', 'aria-label': label, value: /^#[0-9a-f]{6}$/i.test( styleValue( path ) ) ? styleValue( path ) : '#ffffff', onChange: function ( event ) { var values = {}; values[ attribute ] = undefined; values[ 'style.' + path ] = event.target.value; writeMany( values ); } } ) );
		}
		function boxWrite( path, side, value, corners ) {
			var live = current(); if ( ! live ) { return; }
			var stored = at( live.attributes, 'style.' + path );
			if ( ( ! stored || typeof stored === 'object' ) && ! changes.current[ 'style.' + path ] ) { styleWrite( path + '.' + side, value ); return; }
			var next = values.box( stored, corners );
			if ( value === '' ) { delete next[ side ]; } else { next[ side ] = value; }
			styleWrite( path, next );
		}
		function spacing( title, path ) {
			if ( ! supports( path ) ) { return null; }
			var box = values.box( at( attributes, 'style.' + path ) );
			var permitted = wp.blocks.getBlockSupport ? wp.blocks.getBlockSupport( block.name, path ) : true;
			return h( Fragment, { key: path }, h( 'p', { className: 'cve-w-sub' }, title ), h( 'div', { className: 'cve-w-grid' }, [ [ 'top', '↑' ], [ 'bottom', '↓' ], [ 'left', '←' ], [ 'right', '→' ] ].filter( function ( side ) { return ! Array.isArray( permitted ) || permitted.indexOf( side[0] ) >= 0; } ).map( function ( side ) { return h( NumberField, { key: side[0], label: side[1], description: title + ' ' + side[0], value: box[side[0]], step: 4, min: path === 'spacing.margin' ? undefined : 0, onChange: function ( value ) { boxWrite( path, side[0], value ); } } ); } ) ) );
		}
		function drag( event ) {
			if ( event.target.closest( 'button' ) || event.button !== 0 ) { return; }
			var rect = panelRef.current.getBoundingClientRect(); var x = event.clientX; var y = event.clientY;
			event.currentTarget.setPointerCapture( event.pointerId );
			var grip = event.currentTarget; dragged.current = true;
			function move( next ) {
				var moved = { left: Math.max( 8, Math.min( rect.left + next.clientX - x, window.innerWidth - rect.width - 8 ) ), top: Math.max( 64, Math.min( rect.top + next.clientY - y, window.innerHeight - 100 ) ) };
				position[1]( moved );
				if ( pinnedPosition ) { pin( moved ); }
			}
			function stop() { grip.removeEventListener( 'pointermove', move ); grip.removeEventListener( 'pointerup', stop ); grip.removeEventListener( 'pointercancel', stop ); }
			grip.addEventListener( 'pointermove', move ); grip.addEventListener( 'pointerup', stop ); grip.addEventListener( 'pointercancel', stop );
		}
		function pin( next ) {
			pinnedPosition = next;
			try { window.sessionStorage.setItem( 'clara-ve-popup-pin', next ? JSON.stringify( next ) : 'null' ); } catch ( error ) {}
			pinned[1]( !! next );
		}
		var type = wp.blocks.getBlockType( block.name );
		var textKey = block.name === 'core/button' ? 'text' : 'content';
		var canText = [ 'core/paragraph', 'core/heading', 'core/list-item', 'core/button' ].indexOf( block.name ) >= 0 && canWrite( textKey );
		var linkTargetKey = block.name === 'core/navigation-link' ? 'opensInNewTab' : 'linkTarget';
		var mediaKey = block.name === 'core/video' || block.name === 'core/audio' ? 'src' : 'url';
		function canReplaceMedia() {
			var live = current();
			return live && !( live.name === 'core/cover' && live.attributes.useFeaturedImage )
				&& canWrite( mediaKey ) && canWrite( 'id', 'media' )
				&& ( live.name !== 'core/cover' || canWrite( 'backgroundType', 'media' ) );
		}
		function replaceMedia( media ) {
			if ( ! media || typeof media.url !== 'string' || ! media.url || ! canReplaceMedia() ) { return; }
			var next = { id: media.id }; next[mediaKey] = media.url;
			if ( ( block.name === 'core/image' || block.name === 'core/cover' ) && canWrite( 'alt', 'media' ) ) { next.alt = media.alt || ''; }
			if ( block.name === 'core/cover' ) { next.backgroundType = media.type === 'video' ? 'video' : 'image'; }
			writeMany( next, 'media' );
		}
		function promoteOrnament( pseudo ) {
			var live = current(); if ( ! live || ! canText ) { return; }
			var ornament = at( live.attributes, 'claraVe.ornaments.' + pseudo );
			if ( ! ornament || ! ornament.content || ornament.hidden ) { return; }
			function escape( value ) { return String( value ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' ); }
			var css = [ 'color', 'font-size', 'font-family', 'font-weight', 'line-height' ].map( function ( name ) {
				var value = ornament[name]; return value && ! /[{}<>;]|url\s*\(|expression\s*\(/i.test( value ) ? name + ':' + value + ';' : '';
			} ).join( '' );
			var glyph = '<span class="cve-ornament"' + ( css ? ' style="' + escape( css ) + '"' : '' ) + '>' + escape( ornament.content ) + '</span>';
			var changes = {}; var content = live.attributes[textKey] || '';
			changes[textKey] = pseudo === 'before' ? glyph + content : content + glyph;
			changes['claraVe.ornaments.' + pseudo + '.hidden'] = true; writeMany( changes );
		}
		var className = attributes.className || '';
		function effect( prefix, value ) { write( 'className', className.split( /\s+/ ).filter( function ( token ) { return token && token.indexOf( prefix ) !== 0; } ).concat( value ? [ prefix + value ] : [] ).join( ' ' ) ); }
		function effectValue( prefix ) { return ( className.split( /\s+/ ).find( function ( token ) { return token.indexOf( prefix ) === 0; } ) || '' ).slice( prefix.length ); }
		var parents = JSON.parse( wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' );
			if ( ! editor.getBlockParents || ! editor.getBlockName ) { return '[]'; }
			return JSON.stringify( editor.getBlockParents( block.clientId ).map( function ( id ) {
				var name = editor.getBlockName( id ); var own = editor.getBlockAttributes ? editor.getBlockAttributes( id ) || {} : {}; var parentType = wp.blocks.getBlockType( name );
				return { id: id, named: !! ( own.metadata && own.metadata.name ), title: ( own.metadata && own.metadata.name ) || ( parentType ? parentType.title : name ) };
			} ) );
		}, [ block.clientId ] ) );
		var structure = JSON.parse( wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' );
			var root = editor.getBlockRootClientId ? editor.getBlockRootClientId( block.clientId ) || '' : '';
			var order = editor.getBlockOrder ? editor.getBlockOrder( root ) : [];
			var index = order.indexOf( block.clientId );
			return JSON.stringify( {
				root: root, index: index, first: index <= 0, last: index < 0 || index === order.length - 1,
				canMove: !! ( editor.canMoveBlock && editor.canMoveBlock( block.clientId, root ) ),
				canRemove: !! ( editor.canRemoveBlock && editor.canRemoveBlock( block.clientId ) ),
				canDuplicate: !! ( editor.canInsertBlockType && editor.canInsertBlockType( block.name, root ) ),
				children: editor.getBlockOrder ? editor.getBlockOrder( block.clientId ).length : 0,
				isSection: !! ( editor.getBlockName && isSectionRoot( editor, root, currentPostType( select ) ) )
			} );
		}, [ block.clientId ] ) );
		var device = wp.data.useSelect( function ( select ) { try { var editor = select( 'core/editor' ); return editor && editor.getDeviceType ? editor.getDeviceType() || '' : ''; } catch ( error ) { return ''; } }, [] );
		var screen = device ? device.toLowerCase() : breakpoint[0];
		function setScreen( value ) { breakpoint[1]( value ); nativeAction( 'setDeviceType', value[0].toUpperCase() + value.slice( 1 ), registry, true ); }
		var unlocked = designUnlocked( registry.select( 'core/block-editor' ).getSettings() );
		var canUnlock = mode !== 'default' && ! unlocked && canSetDesignLock( registry );
		var parentId = parents.length ? parents[ parents.length - 1 ].id : '';
		function selectBlock( id ) { lastPointer = null; registry.dispatch( 'core/block-editor' ).selectBlock( id ); }
		function blockActions() { return registry.dispatch( 'core/block-editor' ); }

		var groups = [];
		// Form styling lives in claraVe.form and is rendered by Block Extras (see workspace-model.js formCss).
		function formPath( target, property ) { return 'claraVe.form.' + target + '.' + property; }
		function formValue( target, property ) { var value = at( attributes, formPath( target, property ) ); return typeof value === 'string' ? value : ''; }
		function formWrite( target, property, value ) { write( formPath( target, property ), value === '' || value == null ? undefined : String( value ) ); }
		function isFormBlock() {
			if ( applyHooks( 'clara_ve.form.blocks', [ 'core/shortcode', 'core/html', 'clara-ve/form' ], block ).indexOf( block.name ) >= 0 ) { return true; }
			// The parts of a form block are styled through the form itself.
			if ( block.name.indexOf( 'clara-ve/' ) === 0 ) { return false; }
			if ( /form/i.test( block.name ) ) { return true; }
			// Any other block that shows a form on the canvas (server-rendered form blocks, a group around one).
			var frame = document.querySelector( 'iframe[name="editor-canvas"]' ); var doc = frame && frame.contentDocument ? frame.contentDocument : document;
			var element = doc.querySelector( '[data-block="' + block.clientId + '"]' );
			return !! element && Array.prototype.some.call( element.querySelectorAll( 'form, input:not([type="hidden"]), select, textarea' ), function ( control ) {
				return ! control.closest( '.components-base-control, .components-placeholder, .block-editor-plain-text, [class*="blocks-shortcode"], [contenteditable="true"]' );
			} );
		}
		function formColor( target, property, label ) {
			var value = formValue( target, property ); var rowKey = block.clientId + ':form.' + target + '.' + property;
			var isPreset = /^var:preset\|color\|/.test( value ); var custom = !! value && ! isPreset; var showCustom = custom || customRows[0][ rowKey ];
			return h( Fragment, { key: 'form-' + target + '-' + property },
				h( Field, { label: label, value: showCustom ? '__custom' : value, options: [ { label: __( 'Inherit', 'visual-edit-lite' ), value: '' } ].concat( ( presets.colors || [] ).map( function ( item ) { return { label: item.name, value: 'var:preset|color|' + item.slug }; } ) ).concat( [ { label: __( 'Custom…', 'visual-edit-lite' ), value: '__custom' } ] ), onChange: function ( next ) {
					var shown = Object.assign( {}, customRows[0] ); shown[ rowKey ] = next === '__custom'; customRows[1]( shown );
					if ( next !== '__custom' ) { formWrite( target, property, next ); }
				} } ),
				showCustom && h( 'input', { type: 'color', 'aria-label': label, value: /^#[0-9a-f]{6}$/i.test( value ) ? value : '#000000', onChange: function ( event ) { formWrite( target, property, event.target.value ); } } ) );
		}
		function formFont( target ) {
			return h( Field, { key: 'form-' + target + '-font', label: __( 'Font', 'visual-edit-lite' ), value: formValue( target, 'font-family' ), options: [ { label: __( 'Inherit', 'visual-edit-lite' ), value: '' } ].concat( ( presets.fontFamily || [] ).map( function ( item ) { return { label: item.name, value: 'var:preset|font-family|' + item.slug }; } ) ), onChange: function ( value ) { formWrite( target, 'font-family', value ); } } );
		}
		function formSize( target, property, label, units, signed ) {
			return h( NumberField, { key: 'form-' + target + '-' + property, label: label, value: formValue( target, property ), units: units || [ 'px', 'rem', 'em' ], unit: ( units || [ 'px' ] )[0], min: signed ? undefined : 0, step: /letter-spacing/.test( property ) ? 0.5 : 1, onChange: function ( value ) { formWrite( target, property, value ); } } );
		}
		function formChoice( target, property, label, choices ) {
			return h( Field, { key: 'form-' + target + '-' + property, label: label, value: formValue( target, property ), options: options( choices ), onChange: function ( value ) { formWrite( target, property, value ); } } );
		}
		// A new field goes before the send button (or the row holding it), so the button stays last.
		function addFormField( name, attrs ) {
			var editor = registry.select( 'core/block-editor' ); var actions = registry.dispatch( 'core/block-editor' );
			var order = editor.getBlockOrder( block.clientId );
			var index = order.findIndex( function ( id ) {
				if ( editor.getBlockName( id ) === 'clara-ve/submit' ) { return true; }
				return ( editor.getClientIdsOfDescendants ? editor.getClientIdsOfDescendants( [ id ] ) : [] ).some( function ( child ) { return editor.getBlockName( child ) === 'clara-ve/submit'; } );
			} );
			// A new field takes a fixed key from its first label, numbered when the form already has it,
			// so a second Email does not overwrite the first and a later label rename keeps its column.
			var forms = window.ClaraVEFormBlocks;
			if ( forms && ! attrs.name ) {
				var taken = ( editor.getClientIdsOfDescendants( [ block.clientId ] ) || [] ).filter( function ( id ) { return /^clara-ve\/(field|textarea|select|checkbox)$/.test( editor.getBlockName( id ) ); } ).map( function ( id ) { return forms.nameOf( editor.getBlockAttributes( id ) || {} ); } );
				attrs = Object.assign( {}, attrs, { name: forms.uniqueName( forms.nameOf( attrs ), taken ) } );
			}
			var created = wp.blocks.createBlock( name, attrs );
			markPersistent(); actions.insertBlock( created, index < 0 ? order.length : index, block.clientId, true );
		}
		function group( key, tab, title, items, open ) {
			items = ( Array.isArray( items ) ? items : [ items ] ).filter( Boolean );
			if ( items.length ) { groups.push( { key: key, tab: tab, title: title, open: !! open, render: function () { return items; } } ); }
		}
		function sub( text, key ) { return h( 'p', { key: key || 'sub-' + text, className: 'cve-w-sub' }, text ); }

		// Content: what the block says and links to.
		group( 'link', 'content', __( 'Link', 'visual-edit-lite' ), ( block.name === 'core/button' || block.name === 'core/navigation-link' ) && [
			canWrite( 'url' ) && h( Field, { key: 'url', label: __( 'URL', 'visual-edit-lite' ), value: attributes.url, type: 'url', onChange: function ( value ) { if ( ! /^\s*(javascript|data|vbscript):/i.test( value ) ) { write( 'url', value ); } } } ),
			canWrite( linkTargetKey ) && h( Field, { key: 'target', label: __( 'Open in', 'visual-edit-lite' ), value: block.name === 'core/navigation-link' ? ( attributes.opensInNewTab ? '_blank' : '' ) : attributes.linkTarget, options: [ { label: __( 'Same tab', 'visual-edit-lite' ), value: '' }, { label: __( 'New tab', 'visual-edit-lite' ), value: '_blank' } ], onChange: function ( value ) { if ( block.name === 'core/navigation-link' ) { write( 'opensInNewTab', value === '_blank' ); } else { write( 'linkTarget', value ); } } } )
		], true );
		group( 'media', 'content', __( 'Media', 'visual-edit-lite' ), [ 'core/image', 'core/cover', 'core/video', 'core/audio' ].indexOf( block.name ) >= 0 && ( canReplaceMedia() || ( block.name === 'core/image' && canWrite( 'alt' ) ) ) && [
			block.name === 'core/image' && attributes.url && h( 'img', { key: 'preview', className: 'cve-w-media-preview', src: attributes.url, alt: '' } ),
			canReplaceMedia() && h( be.MediaUploadCheck, { key: 'upload' }, h( be.MediaUpload, { allowedTypes: block.name === 'core/audio' ? [ 'audio' ] : block.name === 'core/video' ? [ 'video' ] : block.name === 'core/cover' ? [ 'image', 'video' ] : [ 'image' ], onSelect: replaceMedia, render: function ( media ) { return button( __( 'Choose or replace media', 'visual-edit-lite' ), media.open, { className: 'cve-w-wide' } ); } } ) ),
			block.name === 'core/image' && canWrite( 'alt' ) && h( Field, { key: 'alt', label: __( 'Alternative text', 'visual-edit-lite' ), value: attributes.alt, onChange: function ( value ) { write( 'alt', value ); } } )
		], true );
		group( 'shortcode', 'content', __( 'Shortcode', 'visual-edit-lite' ), block.name === 'core/shortcode' && canWrite( 'text' ) && [
			h( Field, { key: 'shortcode', label: __( 'Shortcode', 'visual-edit-lite' ), value: attributes.text, placeholder: '[shortcode]', onChange: function ( value ) { write( 'text', value ); } } ),
			window.ClaraVEFormBlocks && h( ConvertForm, { key: 'convert', block: block, registry: registry } )
		], true );
		group( 'html-form', 'content', __( 'Form', 'visual-edit-lite' ), block.name === 'core/html' && /<form\b/i.test( String( attributes.content || '' ) ) && window.ClaraVEFormBlocks && h( ConvertForm, { key: 'convert', block: block, registry: registry } ), true );
		var fieldKind = { 'clara-ve/field': 'field', 'clara-ve/textarea': 'textarea', 'clara-ve/select': 'select', 'clara-ve/checkbox': 'checkbox' }[ block.name ];
		group( 'form-field-content', 'content', __( 'Field', 'visual-edit-lite' ), fieldKind && [
			h( Field, { key: 'label', label: __( 'Label', 'visual-edit-lite' ), value: attributes.label, onChange: function ( value ) { write( 'label', value ); } } ),
			( attributes.hint || fieldKind !== 'checkbox' ) && h( Field, { key: 'hint', label: __( 'Note after the label', 'visual-edit-lite' ), value: attributes.hint, onChange: function ( value ) { write( 'hint', value ); } } ),
			( fieldKind === 'field' || fieldKind === 'textarea' ) && h( Field, { key: 'placeholder', label: __( 'Placeholder', 'visual-edit-lite' ), value: attributes.placeholder, onChange: function ( value ) { write( 'placeholder', value ); } } ),
			fieldKind === 'field' && h( Field, { key: 'type', label: __( 'Type', 'visual-edit-lite' ), value: attributes.type || 'text', options: [ [ 'text', __( 'Text', 'visual-edit-lite' ) ], [ 'email', __( 'Email', 'visual-edit-lite' ) ], [ 'tel', __( 'Phone', 'visual-edit-lite' ) ], [ 'url', __( 'Web address', 'visual-edit-lite' ) ], [ 'number', __( 'Number', 'visual-edit-lite' ) ], [ 'date', __( 'Date', 'visual-edit-lite' ) ] ].map( function ( item ) { return { label: item[1], value: item[0] }; } ), onChange: function ( value ) { write( 'type', value ); } } ),
			fieldKind === 'select' && h( 'label', { key: 'options', className: 'cve-w-field cve-w-field-area' }, h( 'span', null, __( 'Choices, one per line', 'visual-edit-lite' ) ),
				h( 'textarea', { rows: Math.min( 10, Math.max( 3, ( attributes.options || [] ).length + 1 ) ), value: ( attributes.options || [] ).join( '\n' ), onChange: function ( event ) { write( 'options', event.target.value.split( '\n' ).map( function ( line ) { return line.replace( /^\s+/, '' ); } ).filter( function ( line, index, all ) { return line.trim() !== '' || index === all.length - 1; } ) ); }, onBlur: function ( event ) { write( 'options', event.target.value.split( '\n' ).map( function ( line ) { return line.trim(); } ).filter( Boolean ) ); } } ) ),
			h( Field, { key: 'required', label: __( 'Required', 'visual-edit-lite' ), value: attributes.required ? 'yes' : '', options: [ { label: __( 'No', 'visual-edit-lite' ), value: '' }, { label: __( 'Yes', 'visual-edit-lite' ), value: 'yes' } ], onChange: function ( value ) { write( 'required', value === 'yes' ); } } ),
			h( 'p', { key: 'name-note', className: 'cve-w-note' }, __( 'Each field keeps its own name in submissions; a name another field already uses is numbered automatically.', 'visual-edit-lite' ) ),
			h( Field, { key: 'name', label: __( 'Name in submissions', 'visual-edit-lite' ), value: attributes.name, placeholder: window.ClaraVEFormBlocks ? window.ClaraVEFormBlocks.nameOf( attributes ) : '', onChange: function ( value ) { write( 'name', value.toLowerCase().replace( /[^a-z0-9_-]+/g, '-' ).slice( 0, 40 ) ); } } )
		], true );
		group( 'form-submit-content', 'content', __( 'Button', 'visual-edit-lite' ), block.name === 'clara-ve/submit' && h( Field, { key: 'text', label: __( 'Button text', 'visual-edit-lite' ), value: attributes.text, onChange: function ( value ) { write( 'text', value ); } } ), true );
		group( 'form-settings', 'content', __( 'Form', 'visual-edit-lite' ), block.name === 'clara-ve/form' && [
			sub( __( 'Add a field', 'visual-edit-lite' ), 'form-add' ),
			h( 'div', { key: 'add', className: 'cve-w-form-add' }, [ [ 'clara-ve/field', __( 'Text', 'visual-edit-lite' ), { label: __( 'New field', 'visual-edit-lite' ) } ], [ 'clara-ve/field', __( 'Email', 'visual-edit-lite' ), { label: __( 'Email', 'visual-edit-lite' ), type: 'email' } ], [ 'clara-ve/textarea', __( 'Long text', 'visual-edit-lite' ), { label: __( 'Message', 'visual-edit-lite' ) } ], [ 'clara-ve/select', __( 'Choice list', 'visual-edit-lite' ), { label: __( 'Choose one', 'visual-edit-lite' ), options: [ __( 'First choice', 'visual-edit-lite' ), __( 'Second choice', 'visual-edit-lite' ) ] } ], [ 'clara-ve/checkbox', __( 'Checkbox', 'visual-edit-lite' ), { label: __( 'I agree', 'visual-edit-lite' ) } ] ].map( function ( item ) {
				return button( '＋ ' + item[1], function () { addFormField( item[0], item[2] ); }, { key: item[1] } );
			} ) ),
			h( 'p', { key: 'items-note', className: 'cve-w-note' }, __( 'Reorder or remove fields under Section › Items; click a field on the page to change it.', 'visual-edit-lite' ) ),
			sub( __( 'Where it goes', 'visual-edit-lite' ), 'form-delivery' ),
			h( Field, { key: 'type', label: __( 'Does', 'visual-edit-lite' ), value: 'list' === attributes.formType ? 'list' : 'contact', options: [ { label: __( 'Contact form', 'visual-edit-lite' ), value: 'contact' }, { label: __( 'Mailing list', 'visual-edit-lite' ), value: 'list' } ], onChange: function ( value ) { write( 'formType', value ); } } ),
			'list' === attributes.formType
				? h( ListField, { key: 'list', value: attributes.listId, onChange: function ( value ) { write( 'listId', value ); } } )
				: h( Field, { key: 'to', label: __( 'Send to', 'visual-edit-lite' ), value: attributes.recipient, type: 'email', placeholder: config.formRecipient || '', onChange: function ( value ) { write( 'recipient', value.trim() ); } } ),
			sub( __( 'After sending', 'visual-edit-lite' ), 'form-after' ),
			h( Field, { key: 'redirect', label: __( 'Go to page', 'visual-edit-lite' ), value: attributes.redirect, placeholder: __( 'Stay on this page', 'visual-edit-lite' ), onChange: function ( value ) { write( 'redirect', value ); } } ),
			h( Field, { key: 'message', label: __( 'Message', 'visual-edit-lite' ), value: attributes.message, placeholder: __( 'Thanks — check your inbox.', 'visual-edit-lite' ), onChange: function ( value ) { write( 'message', value ); } } ),
			config.formSettingsUrl && h( 'p', { key: 'where', className: 'cve-w-note' },
				'list' === attributes.formType
					? __( 'The address is added to a mailing list at your provider, which sends the confirmation and the download.', 'visual-edit-lite' )
					: __( 'Submissions are stored under Form Submissions and emailed to you. The sender gets a confirmation.', 'visual-edit-lite' ),
				' ', h( 'a', { href: config.formSettingsUrl, target: '_blank', rel: 'noopener' }, __( 'Form Settings', 'visual-edit-lite' ) ) )
		], true );
		group( 'text', 'content', __( 'Text', 'visual-edit-lite' ), canText && [
			h( FormatRow, { key: 'format', registry: registry, clientId: block.clientId, textKey: textKey, current: current, write: write } ),
			h( be.RichText, { key: 'text', tagName: 'div', className: 'cve-w-richtext', value: attributes[ textKey ] || '', onChange: function ( value ) { write( textKey, value ); }, 'aria-label': __( 'Text', 'visual-edit-lite' ) } )
		], true );
		group( 'ornaments', 'content', __( 'Ornaments', 'visual-edit-lite' ), mode === 'default' && block.name.indexOf( 'clara-ve/' ) !== 0 && [ 'before', 'after' ].map( function ( pseudo ) {
			var base = 'claraVe.ornaments.' + pseudo;
			return h( Fragment, { key: pseudo }, sub( pseudo === 'before' ? __( 'Before the text', 'visual-edit-lite' ) : __( 'After the text', 'visual-edit-lite' ) ),
				at( attributes, base + '.hidden' ) ? h( 'p', { className: 'cve-w-note' }, __( 'Converted to editable text. Use Undo to restore the ornament.', 'visual-edit-lite' ) ) : h( Fragment, null,
				canText && at( attributes, base + '.content' ) && button( __( 'Convert to editable text', 'visual-edit-lite' ), function () { promoteOrnament( pseudo ); }, { className: 'cve-w-wide' } ),
				h( 'div', { className: 'cve-w-swatches' }, [ '“', '”', '‘', '’', '«', '»', '—' ].map( function ( glyph ) { return button( glyph, function () { write( base + '.content', glyph ); }, { key: glyph, 'aria-label': __( 'Symbol', 'visual-edit-lite' ) + ' ' + glyph } ); } ) ),
				[ [ __( 'Symbol', 'visual-edit-lite' ), 'content' ], [ __( 'Colour', 'visual-edit-lite' ), 'color' ], [ __( 'Size', 'visual-edit-lite' ), 'font-size' ], [ __( 'Font', 'visual-edit-lite' ), 'font-family' ] ].map( function ( item ) { return h( Field, { key: item[1], label: item[0], value: at( attributes, base + '.' + item[1] ), onChange: function ( value ) { write( base + '.' + item[1], value ); } } ); } ) ) );
		} ) );

		// Style, for the whole site width.
		if ( mode === 'default' && screen === 'desktop' ) {
			group( 'typography', 'style', __( 'Typography', 'visual-edit-lite' ), ( supports( 'typography.fontSize' ) || supports( 'typography.fontFamily' ) ) && [
				supports( 'typography.fontFamily' ) && preset( __( 'Font', 'visual-edit-lite' ), 'fontFamily', 'fontFamily', 'typography.fontFamily' ),
				supports( 'typography.fontFamily' ) && config.canManageFonts && button( __( '＋ Add Google fonts', 'visual-edit-lite' ), function () { fontOpen[1]( true ); }, { key: 'google-fonts', className: 'cve-w-wide' } ),
				supports( 'typography.fontSize' ) && preset( __( 'Size', 'visual-edit-lite' ), 'fontSizes', 'fontSize', 'typography.fontSize' )
			].concat( [ [ __( 'Weight', 'visual-edit-lite' ), 'fontWeight', [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ] ], [ __( 'Style', 'visual-edit-lite' ), 'fontStyle', [ 'normal', 'italic' ] ], [ __( 'Case', 'visual-edit-lite' ), 'textTransform', [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ], [ __( 'Decoration', 'visual-edit-lite' ), 'textDecoration', [ 'none', 'underline', 'line-through' ] ], [ __( 'Line height', 'visual-edit-lite' ), 'lineHeight' ], [ __( 'Letter spacing', 'visual-edit-lite' ), 'letterSpacing' ] ].map( function ( item ) { return supports( 'typography.' + item[1] ) ? field( item[0], 'typography.' + item[1], item[2] ) : null; } ) ).concat( [
				supports( 'typography.textAlign' ) ? field( __( 'Align', 'visual-edit-lite' ), 'typography.textAlign', [ 'left', 'center', 'right', 'justify' ] ) : ( type && type.attributes && type.attributes.align && [ 'core/paragraph', 'core/heading' ].indexOf( block.name ) >= 0 ) && h( Field, { key: 'align', label: __( 'Align', 'visual-edit-lite' ), value: attributes.align, options: options( [ 'left', 'center', 'right' ] ), onChange: function ( value ) { write( 'align', value ); } } )
			] ), true );
			group( 'colours', 'style', __( 'Colours', 'visual-edit-lite' ), [
				supports( 'color.text' ) && preset( __( 'Text colour', 'visual-edit-lite' ), 'colors', 'textColor', 'color.text', true ),
				supports( 'color.background' ) && preset( __( 'Background', 'visual-edit-lite' ), 'colors', 'backgroundColor', 'color.background', true ),
				supports( 'color.gradients' ) && sub( __( 'Gradient', 'visual-edit-lite' ) ),
				supports( 'color.gradients' ) && h( GradientField, { key: 'gradient', value: styleValue( 'color.gradient' ), slug: attributes.gradient, presets: presets.gradients, palette: presets.colors, custom: setting( 'color.customGradient' ) !== false, onChange: function ( css ) { writeMany( { gradient: undefined, 'style.color.gradient': css } ); }, onPreset: function ( slug ) { writeMany( { gradient: slug, 'style.color.gradient': undefined } ); } } )
			] );
			group( 'spacing', 'style', __( 'Size and spacing', 'visual-edit-lite' ), [
				supports( 'dimensions.minHeight' ) && field( __( 'Minimum height', 'visual-edit-lite' ), 'dimensions.minHeight' ),
				supports( 'dimensions.aspectRatio' ) && field( __( 'Aspect ratio', 'visual-edit-lite' ), 'dimensions.aspectRatio' ),
				block.name === 'core/spacer' && h( NumberField, { key: 'spacer', label: __( 'Height', 'visual-edit-lite' ), value: attributes.height, min: 0, onChange: function ( value ) { write( 'height', value ); } } ),
				spacing( __( 'Padding', 'visual-edit-lite' ), 'spacing.padding' ),
				spacing( __( 'Margin', 'visual-edit-lite' ), 'spacing.margin' )
			] );
			group( 'border', 'style', __( 'Border and shadow', 'visual-edit-lite' ), [
				supports( 'border.color' ) && preset( __( 'Border colour', 'visual-edit-lite' ), 'colors', 'borderColor', 'border.color', true ),
				supports( 'border.width' ) && field( __( 'Border width', 'visual-edit-lite' ), 'border.width' ),
				supports( 'border.style' ) && field( __( 'Border style', 'visual-edit-lite' ), 'border.style', [ 'none', 'solid', 'dashed', 'dotted', 'double' ] ),
				supports( 'border.radius' ) && sub( __( 'Corners', 'visual-edit-lite' ) ),
				supports( 'border.radius' ) && h( 'div', { key: 'radius', className: 'cve-w-grid' }, [ [ 'topLeft', '◜' ], [ 'topRight', '◝' ], [ 'bottomLeft', '◟' ], [ 'bottomRight', '◞' ] ].map( function ( corner ) { return h( NumberField, { key: corner[0], label: corner[1], description: __( 'Radius', 'visual-edit-lite' ) + ' ' + corner[0], value: values.box( at( attributes, 'style.border.radius' ), true )[corner[0]], min: 0, step: 2, onChange: function ( value ) { boxWrite( 'border.radius', corner[0], value, true ); } } ); } ) ),
				supports( 'shadow' ) && field( __( 'Shadow', 'visual-edit-lite' ), 'shadow' )
			] );
			group( 'motion', 'style', __( 'Motion', 'visual-edit-lite' ), wp.blocks.hasBlockSupport( block.name, 'customClassName', true ) && [
				h( Field, { key: 'entrance', label: __( 'Entrance', 'visual-edit-lite' ), value: effectValue( 'cve-anim-' ), options: options( ENTRANCES ), onChange: function ( value ) { effect( 'cve-anim-', value ); } } ),
				h( Field, { key: 'hover', label: __( 'Hover', 'visual-edit-lite' ), value: effectValue( 'cve-hover-' ), options: options( HOVERS ), onChange: function ( value ) { effect( 'cve-hover-', value ); } } )
			] );
			if ( isFormBlock() ) {
				group( 'form-labels', 'style', __( 'Form labels', 'visual-edit-lite' ), [
					h( 'p', { key: 'form-note', className: 'cve-w-note' }, __( 'Styles every form inside this block. The fields and their texts come from the form itself.', 'visual-edit-lite' ) ),
					formColor( 'label', 'color', __( 'Colour', 'visual-edit-lite' ) ),
					formFont( 'label' ), formSize( 'label', 'font-size', __( 'Size', 'visual-edit-lite' ) ),
					formChoice( 'label', 'font-weight', __( 'Weight', 'visual-edit-lite' ), [ '300', '400', '500', '600', '700' ] ),
					formChoice( 'label', 'text-transform', __( 'Case', 'visual-edit-lite' ), [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ),
					formSize( 'label', 'letter-spacing', __( 'Letter spacing', 'visual-edit-lite' ), [ 'px', 'em', 'rem' ], true )
				], true );
				group( 'form-fields', 'style', __( 'Form fields', 'visual-edit-lite' ), [
					formColor( 'field', 'color', __( 'Text colour', 'visual-edit-lite' ) ),
					formColor( 'field', 'background-color', __( 'Background', 'visual-edit-lite' ) ),
					formFont( 'field' ), formSize( 'field', 'font-size', __( 'Size', 'visual-edit-lite' ) ),
					formColor( 'placeholder', 'color', __( 'Placeholder colour', 'visual-edit-lite' ) ),
					sub( __( 'Border', 'visual-edit-lite' ), 'form-border' ),
					h( Field, { key: 'form-border-kind', label: __( 'Shape', 'visual-edit-lite' ), value: formValue( 'field', 'border' ), options: [ { label: __( 'Inherit', 'visual-edit-lite' ), value: '' }, { label: __( 'Line underneath', 'visual-edit-lite' ), value: 'underline' }, { label: __( 'Box', 'visual-edit-lite' ), value: 'box' }, { label: __( 'No border', 'visual-edit-lite' ), value: 'none' } ], onChange: function ( value ) { formWrite( 'field', 'border', value ); } } ),
					formValue( 'field', 'border' ) !== 'none' && formColor( 'field', 'border-color', __( 'Border colour', 'visual-edit-lite' ) ),
					formValue( 'field', 'border' ) !== 'none' && formSize( 'field', 'border-width', __( 'Border width', 'visual-edit-lite' ), [ 'px' ] ),
					formValue( 'field', 'border' ) !== 'none' && formColor( 'focus', 'border-color', __( 'Border when typing', 'visual-edit-lite' ) ),
					formSize( 'field', 'border-radius', __( 'Corners', 'visual-edit-lite' ), [ 'px', 'rem', 'em', '%' ] )
				] );
				group( 'form-button', 'style', __( 'Form button', 'visual-edit-lite' ), [
					formColor( 'button', 'color', __( 'Text colour', 'visual-edit-lite' ) ),
					formColor( 'button', 'background-color', __( 'Background', 'visual-edit-lite' ) ),
					formColor( 'buttonHover', 'color', __( 'Text colour on hover', 'visual-edit-lite' ) ),
					formColor( 'buttonHover', 'background-color', __( 'Background on hover', 'visual-edit-lite' ) ),
					formColor( 'button', 'border-color', __( 'Border colour', 'visual-edit-lite' ) ),
					formSize( 'button', 'border-radius', __( 'Corners', 'visual-edit-lite' ), [ 'px', 'rem', 'em', '%' ] ),
					formSize( 'button', 'font-size', __( 'Size', 'visual-edit-lite' ) ),
					formChoice( 'button', 'text-transform', __( 'Case', 'visual-edit-lite' ), [ 'none', 'uppercase', 'lowercase', 'capitalize' ] ),
					formSize( 'button', 'letter-spacing', __( 'Letter spacing', 'visual-edit-lite' ), [ 'px', 'em', 'rem' ], true )
				] );
			}
		}

		// Style, for one smaller screen: the same fields, stored per breakpoint.
		if ( mode === 'default' && screen !== 'desktop' ) {
			var screenRules = at( attributes, 'claraVe.responsive.' + screen ) || ( legacyRules && legacyRules.screens[ screen ] ) || {};
			var responsiveWrite = function ( path, value ) {
				var live = current(); if ( ! live ) { return; }
				var all = model.copy( at( live.attributes, 'claraVe.responsive' ) || ( legacyRules && legacyRules.screens ) || {} );
				// A screen that came back from PHP as [] (an emptied object) is an object again.
				all[ screen ] = all[ screen ] && typeof all[ screen ] === 'object' && ! Array.isArray( all[ screen ] ) ? all[ screen ] : {};
				if ( value ) { all[ screen ][ path ] = value; } else { delete all[ screen ][ path ]; }
				if ( ! Object.keys( all[ screen ] ).length ) { delete all[ screen ]; }
				var next = { 'claraVe.responsive': Object.keys( all ).length ? all : undefined };
				if ( legacyRules ) { next.className = ( live.attributes.className || '' ).split( /\s+/ ).filter( function ( token ) { return token !== legacyRules.anchor; } ).join( ' ' ); }
				writeMany( next );
			};
			var responsiveField = function ( path, label, kind ) {
				var probe = path.replace( /\.(top|right|bottom|left)$/, '' );
				if ( path !== 'display' && ! supports( probe ) && !( path === 'typography.textAlign' && canText ) ) { return null; }
				var value = screenRules[ path ];
				var shown = value ? '● ' + label : label;
				if ( kind === 'hide' ) { return h( 'label', { key: path, className: 'cve-w-field cve-w-check' }, h( 'span', null, label ), h( 'input', { type: 'checkbox', checked: value === 'none', onChange: function ( event ) { responsiveWrite( path, event.target.checked ? 'none' : '' ); } } ) ); }
				if ( kind === 'number' ) { return h( NumberField, { key: path, label: shown, description: label + ' ' + screen, value: value, min: /margin/.test( path ) ? undefined : 0, step: /padding|margin/.test( path ) ? 4 : 1, onChange: function ( next ) { responsiveWrite( path, next ); } } ); }
				return h( Field, { key: path, label: shown, value: value, options: options( kind ), onChange: function ( next ) { responsiveWrite( path, next ); } } );
			};
			var sides = function ( base, list ) { var fields = list.map( function ( side ) { return responsiveField( base + '.' + side[0], side[1], 'number' ); } ).filter( Boolean ); return fields.length ? h( 'div', { key: base, className: 'cve-w-grid' }, fields ) : null; };
			var padding = sides( 'spacing.padding', [ [ 'top', '↑' ], [ 'bottom', '↓' ], [ 'left', '←' ], [ 'right', '→' ] ] );
			var margin = sides( 'spacing.margin', [ [ 'top', '↑' ], [ 'bottom', '↓' ] ] );
			group( 'responsive-typography', 'style', __( 'Typography', 'visual-edit-lite' ), [ responsiveField( 'typography.fontSize', __( 'Size', 'visual-edit-lite' ), 'number' ), responsiveField( 'typography.textAlign', __( 'Align', 'visual-edit-lite' ), [ 'left', 'center', 'right' ] ) ], true );
			group( 'responsive-spacing', 'style', __( 'Size and spacing', 'visual-edit-lite' ), [ responsiveField( 'dimensions.minHeight', __( 'Minimum height', 'visual-edit-lite' ), 'number' ), padding && sub( __( 'Padding', 'visual-edit-lite' ), 'sub-padding' ), padding, margin && sub( __( 'Margin', 'visual-edit-lite' ), 'sub-margin' ), margin ], true );
			group( 'responsive-visibility', 'style', __( 'Visibility', 'visual-edit-lite' ), responsiveField( 'display', screen === 'mobile' ? __( 'Hide on phones', 'visual-edit-lite' ) : __( 'Hide on tablets and phones', 'visual-edit-lite' ), 'hide' ), true );
		}

		// Section: how children are arranged, which children, and neighbours.
		group( 'layout', 'section', __( 'Layout', 'visual-edit-lite' ), mode === 'default' && wp.blocks.hasBlockSupport( block.name, 'layout', false ) && [
			h( Field, { key: 'type', label: __( 'Layout', 'visual-edit-lite' ), value: at( attributes, 'layout.type' ), options: options( [ 'constrained', 'flex', 'grid' ] ), onChange: function ( value ) { write( 'layout.type', value ); } } ),
			h( Field, { key: 'orientation', label: __( 'Direction', 'visual-edit-lite' ), value: at( attributes, 'layout.orientation' ), options: options( [ 'horizontal', 'vertical' ] ), onChange: function ( value ) { write( 'layout.orientation', value ); } } ),
			h( Field, { key: 'justify', label: __( 'Justify', 'visual-edit-lite' ), value: at( attributes, 'layout.justifyContent' ), options: options( [ 'left', 'center', 'right', 'space-between' ] ), onChange: function ( value ) { write( 'layout.justifyContent', value ); } } ),
			supports( 'spacing.blockGap' ) && field( __( 'Gap', 'visual-edit-lite' ), 'spacing.blockGap' )
		], true );
		group( 'items', 'section', __( 'Items', 'visual-edit-lite' ), structure.children > 0 && h( ItemsList, { key: 'items', clientId: block.clientId } ), true );
		group( 'section', 'section', __( 'Section', 'visual-edit-lite' ), structure.isSection && mode === 'default' && button( '＋ ' + __( 'Add a section after this one', 'visual-edit-lite' ), function () { window.dispatchEvent( new CustomEvent( 'clara-ve-open-patterns', { detail: { rootClientId: structure.root, index: structure.index + 1 } } ) ); }, { key: 'add-section', className: 'cve-w-wide' } ), true );

		groups = applyHooks( 'clara_ve.popup.groups', groups, { block: block, attributes: attributes, mode: mode, screen: screen, registry: registry, write: write, writeMany: writeMany, canWrite: canWrite, element: wp.element, fields: { Field: Field, NumberField: NumberField } } );
		var tabNames = [ [ 'content', __( 'Content', 'visual-edit-lite' ) ], [ 'style', __( 'Style', 'visual-edit-lite' ) ], [ 'section', __( 'Section', 'visual-edit-lite' ) ] ];
		var tabs = tabNames.filter( function ( item ) { return ( item[0] === 'style' && mode === 'default' && screen !== 'desktop' ) || groups.some( function ( entry ) { return entry.tab === item[0]; } ); } );
		var hasAdvanced = !! be.BlockInspector;
		var active = tabState[0] === 'advanced' && hasAdvanced ? 'advanced' : ( tabs.some( function ( item ) { return item[0] === tabState[0]; } ) ? tabState[0] : ( tabs[0] ? tabs[0][0] : ( hasAdvanced ? '' : '' ) ) );
		function chooseTab( name ) { lastTab = name; tabState[1]( name ); }
		var footerExtras = applyHooks( 'clara_ve.popup.footer', [], { block: block, attributes: attributes, mode: mode, registry: registry } );
		var shown = groups.filter( function ( entry ) { return entry.tab === active; } );
		var title = at( attributes, 'metadata.name' ) || ( canText && wp.htmlEntities ? wp.htmlEntities.decodeEntities( String( attributes[textKey] || '' ).replace( /<[^>]*>/g, '' ) ).slice( 0, 80 ) : '' ) || ( type ? type.title : block.name );
		// The nearest named section is always reachable in one click, then the direct parent.
		var sectionCrumb = parents.filter( function ( parent ) { return parent.named; } ).pop();
		var crumbs = parents.slice( -2 );
		if ( sectionCrumb && crumbs.indexOf( sectionCrumb ) < 0 ) { crumbs = [ sectionCrumb ].concat( parents.slice( -1 ) ); }
		function apply() { changes.current = {}; markPersistent(); emit( 'apply', { id: block.clientId } ); props.onClose(); }

		return portal( h( Fragment, null,
			h( 'div', { ref: panelRef, className: 'cve-w-popup' + ( position[0] ? '' : ' is-placing' ), style: position[0] || undefined, role: 'region', tabIndex: -1, 'aria-label': __( 'Visual Edit block settings', 'visual-edit-lite' ), onKeyDown: function ( event ) {
				if ( event.key === 'Escape' ) { event.stopPropagation(); cancel(); }
				if ( event.altKey && event.key === 'ArrowUp' && parentId ) { event.preventDefault(); selectBlock( parentId ); }
			} },
				h( 'div', { className: 'cve-w-head', onPointerDown: drag },
					h( 'span', { className: 'cve-w-grip', 'aria-hidden': 'true' }, '⠿' ),
					h( 'div', { className: 'cve-w-heading' },
						crumbs.length > 0 && h( 'nav', { className: 'cve-w-crumbs', 'aria-label': __( 'Block path', 'visual-edit-lite' ) }, crumbs.map( function ( crumb, index ) {
							var skipped = index === 0 ? parents.indexOf( crumb ) > 0 : parents.indexOf( crumb ) - parents.indexOf( crumbs[ index - 1 ] ) > 1;
							return h( Fragment, { key: crumb.id }, index > 0 && h( 'span', { 'aria-hidden': 'true' }, '›' ), skipped && h( 'span', { 'aria-hidden': 'true' }, '… ›' ), button( crumb.title, function () { selectBlock( crumb.id ); }, { className: 'cve-w-crumb', title: __( 'Select', 'visual-edit-lite' ) + ' ' + crumb.title } ) );
						} ) ),
						h( 'strong', null, title ) ),
					parentId && button( '↑', function () { selectBlock( parentId ); }, { className: 'cve-w-icon', 'aria-label': __( 'Select the parent block', 'visual-edit-lite' ), title: __( 'Select the parent block (Alt+↑)', 'visual-edit-lite' ) } ),
					button( h( 'span', { className: 'dashicons dashicons-admin-post', 'aria-hidden': 'true' } ), function () { pin( pinned[0] ? null : position[0] ); }, { className: 'cve-w-icon' + ( pinned[0] ? ' is-on' : '' ), 'aria-pressed': pinned[0], 'aria-label': __( 'Keep the popup in this place', 'visual-edit-lite' ), title: __( 'Keep the popup in this place', 'visual-edit-lite' ) } ),
					button( '×', cancel, { className: 'cve-w-icon', 'aria-label': __( 'Close', 'visual-edit-lite' ), title: __( 'Close and undo the changes made in this popup', 'visual-edit-lite' ) } ) ),
				mode === 'default' && ( structure.canDuplicate || structure.canMove || structure.canRemove ) && h( 'div', { className: 'cve-w-actions', role: 'group', 'aria-label': __( 'Block actions', 'visual-edit-lite' ) },
					structure.canDuplicate && button( h( Fragment, null, h( 'span', { className: 'dashicons dashicons-admin-page', 'aria-hidden': 'true' } ), ' ' + __( 'Duplicate', 'visual-edit-lite' ) ), function () { var actions = blockActions(); if ( actions.duplicateBlocks ) { actions.duplicateBlocks( [ block.clientId ] ); } } ),
					structure.canMove && button( '▲', function () { blockActions().moveBlocksUp( [ block.clientId ], structure.root || undefined ); }, { disabled: structure.first, 'aria-label': __( 'Move up', 'visual-edit-lite' ), title: __( 'Move up', 'visual-edit-lite' ) } ),
					structure.canMove && button( '▼', function () { blockActions().moveBlocksDown( [ block.clientId ], structure.root || undefined ); }, { disabled: structure.last, 'aria-label': __( 'Move down', 'visual-edit-lite' ), title: __( 'Move down', 'visual-edit-lite' ) } ),
					structure.canRemove && button( h( 'span', { className: 'dashicons dashicons-trash', 'aria-hidden': 'true' } ), function () { blockActions().removeBlocks( [ block.clientId ] ); }, { className: 'cve-w-danger', 'aria-label': __( 'Delete', 'visual-edit-lite' ), title: __( 'Delete', 'visual-edit-lite' ) } ) ),
				mode === 'contentOnly' && h( 'div', { className: 'cve-w-mode-note', role: 'status' }, h( 'span', null, __( 'Content-only editing: text and media can change, the design is locked by WordPress.', 'visual-edit-lite' ) ), canUnlock && button( __( 'Unlock design', 'visual-edit-lite' ), function () { setDesignLock( registry, true ); }, { className: 'cve-w-primary' } ) ),
				mode !== 'default' && mode !== 'contentOnly' && h( 'div', { className: 'cve-w-mode-note', role: 'status' }, h( 'span', null, __( 'Editing this block is disabled by WordPress.', 'visual-edit-lite' ) ), canUnlock && button( __( 'Unlock design', 'visual-edit-lite' ), function () { setDesignLock( registry, true ); }, { className: 'cve-w-primary' } ) ),
				( tabs.length + ( hasAdvanced ? 1 : 0 ) ) > 1 && h( 'div', { className: 'cve-w-tabs', role: 'tablist' },
					tabs.map( function ( item ) { return button( item[1], function () { chooseTab( item[0] ); }, { key: item[0], role: 'tab', 'aria-selected': active === item[0], className: 'cve-w-tab' } ); } ),
					hasAdvanced && button( __( 'Advanced', 'visual-edit-lite' ), openAdvanced, { role: 'tab', 'aria-selected': active === 'advanced', className: 'cve-w-tab', title: __( 'All WordPress settings for this block', 'visual-edit-lite' ) } ) ),
				active === 'advanced' ? h( 'div', { className: 'cve-w-body cve-w-advanced' }, h( 'p', { className: 'cve-w-note' }, __( 'Native block and plugin settings. Changes stay in the document; use Undo to revert them.', 'visual-edit-lite' ) ), h( 'div', { className: 'cve-w-native' }, h( be.BlockInspector ) ) ) :
				h( 'div', { className: 'cve-w-body cve-w-controls' + ( active === 'style' ? ' cve-w-style-controls' : '' ) },
					active === 'style' && h( 'div', { className: 'cve-w-screens', role: 'group', 'aria-label': __( 'Screen size', 'visual-edit-lite' ) }, [ [ 'desktop', __( 'Desktop', 'visual-edit-lite' ) ], [ 'tablet', __( 'Tablet', 'visual-edit-lite' ) ], [ 'mobile', __( 'Mobile', 'visual-edit-lite' ) ] ].map( function ( item ) { return button( item[1], function () { setScreen( item[0] ); }, { key: item[0], 'aria-pressed': screen === item[0], className: 'cve-w-screen' } ); } ) ),
					active === 'style' && screen !== 'desktop' && h( 'p', { className: 'cve-w-note' }, screen === 'mobile' ? __( 'These values apply to phones (600px and narrower). Empty fields keep the larger screen’s value.', 'visual-edit-lite' ) : __( 'These values apply to tablets and phones (781px and narrower). Empty fields keep the desktop value.', 'visual-edit-lite' ) ),
					active === 'style' && screen !== 'desktop' && ! shown.length && h( 'p', { className: 'cve-w-note' }, __( 'Nothing on this block can differ per screen size.', 'visual-edit-lite' ) ),
					! tabs.length && mode !== 'default' && ! canUnlock && h( 'p', { className: 'cve-w-note' }, __( 'Nothing here can be changed from Visual Edit.', 'visual-edit-lite' ) ),
					shown.map( function ( entry, index ) { return h( Section, { key: entry.key, title: entry.title, open: index === 0 || entry.open }, h.apply( null, [ Fragment, null ].concat( entry.render() ) ) ); } ) ),
				h( 'footer', { className: 'cve-w-foot' },
					mode === 'default' && active !== 'advanced' && button( __( 'Reset styles', 'visual-edit-lite' ), function () { markPersistent(); writeMany( Object.assign( { style: undefined, fontFamily: undefined, fontSize: undefined, textColor: undefined, backgroundColor: undefined, borderColor: undefined, gradient: undefined }, at( attributes, 'claraVe.form' ) ? { 'claraVe.form': undefined } : {} ) ); markPersistent(); }, { title: __( 'Remove this block’s own styling', 'visual-edit-lite' ) } ),
					footerExtras.map( function ( item ) { return button( item.label, item.onClick, { key: item.key, title: item.title } ); } ),
					h( 'span', { className: 'cve-w-flex' } ),
					button( __( 'Cancel', 'visual-edit-lite' ), cancel ), button( __( 'Apply', 'visual-edit-lite' ), apply, { className: 'cve-w-primary' } ) ) ),
			fontOpen[0] && h( FontPicker, { onClose: function () { fontOpen[1]( false ); } } ) ) );
	}

	/** Mounted inside each BlockEdit's registry, including nested entity editors. */
	/**
	 * WordPress's shortcode block shows only its text. In the workspace the canvas shows what
	 * the shortcode puts on the page instead — a form, a gallery — so it can be seen and
	 * styled; the text stays editable in the popup. Clicks pass through to the block.
	 */
	/**
	 * Replace a shortcode's or HTML block's form with form blocks: its fields, texts and
	 * button become editable, its class names (and so its look) stay. One undo step.
	 */
	function ConvertForm( props ) {
		var state = useState( '' );
		var block = props.block; var registry = props.registry;
		function run() {
			state[1]( 'working' );
			var shortcodeId = block.name === 'core/shortcode' ? ( String( block.attributes.text || '' ).match( /\bid=["']?([a-z0-9_-]+)/i ) || [] )[1] : '';
			var source = block.name === 'core/html' ? Promise.resolve( { html: block.attributes.content } ) : wp.apiFetch( { path: '/clara-ve/v1/native/render-shortcode', method: 'POST', data: { text: String( block.attributes.text || '' ).slice( 0, 2000 ), post: registry.select( 'core/editor' ).getCurrentPostId() || 0 } } );
			source.then( function ( response ) {
				var extra = { formId: shortcodeId || 'form-' + Math.random().toString( 36 ).slice( 2, 8 ) };
				if ( block.attributes.claraVe ) { extra.claraVe = block.attributes.claraVe; }
				var form = window.ClaraVEFormBlocks.fromHtml( response && response.html, function ( name, attributes, inner ) { return wp.blocks.createBlock( name, attributes, inner || [] ); }, extra );
				if ( ! form ) { state[1]( 'none' ); return; }
				var actions = registry.dispatch( 'core/block-editor' );
				if ( actions.__unstableMarkLastChangeAsPersistent ) { actions.__unstableMarkLastChangeAsPersistent(); }
				actions.replaceBlocks( block.clientId, form );
				actions.selectBlock( form.clientId );
			} ).catch( function () { state[1]( 'failed' ); } );
		}
		return h( Fragment, null,
			button( __( 'Make this form editable', 'visual-edit-lite' ), run, { className: 'cve-w-wide cve-w-primary', disabled: state[0] === 'working' } ),
			h( 'p', { className: 'cve-w-note' }, state[0] === 'none' ? __( 'There is no form in this block.', 'visual-edit-lite' ) : state[0] === 'failed' ? __( 'The form could not be read. Try again.', 'visual-edit-lite' ) : __( 'Turns the form into fields you can change here — labels, placeholders, choices, the button — keeping its look. Submissions then go to Form Submissions. Undo restores the original block.', 'visual-edit-lite' ) ) );
	}
	/**
	 * The provider's own lists. Asking for a numeric list id is exactly what an
	 * editor exists to stop doing, so the ids are fetched — once, lazily, and
	 * only when a form is actually set to feed a list.
	 */
	function ListField( props ) {
		var state = wp.element.useState( null ); // null = loading.
		wp.element.useEffect( function () {
			var live = true;
			wp.apiFetch( { path: '/clara-ve/v1/lists' } )
				.then( function ( data ) { if ( live ) { state[1]( data && 'object' === typeof data ? data : { ready: false } ); } } )
				.catch( function () { if ( live ) { state[1]( { ready: false } ); } } );
			return function () { live = false; };
		}, [] );
		var data = state[0];
		if ( ! data ) {
			return h( Field, { label: __( 'List', 'visual-edit-lite' ), value: props.value, options: [ { label: __( 'Loading…', 'visual-edit-lite' ), value: props.value || '' } ], onChange: function () {} } );
		}
		if ( ! data.ready || ! ( data.lists || [] ).length ) {
			return h( Fragment, null,
				h( Field, { label: __( 'List', 'visual-edit-lite' ), value: props.value, onChange: props.onChange } ),
				h( 'p', { className: 'cve-w-note' }, data.error || __( 'No mailing list provider is connected yet — pick one in Form Settings. Until then this form has nowhere to put an address.', 'visual-edit-lite' ) ) );
		}
		var options = data.lists.map( function ( list ) { return { label: list.name || String( list.id ), value: String( list.id ) }; } );
		if ( props.value && ! options.some( function ( option ) { return option.value === String( props.value ); } ) ) {
			options.unshift( { label: props.value, value: String( props.value ) } );
		}
		return h( Field, { label: __( 'List', 'visual-edit-lite' ), value: props.value, options: options, onChange: props.onChange } );
	}

	function ShortcodePreview( props ) {
		var html = useState( '' ); var host = useState( null );
		var postId = wp.data.useSelect( function ( select ) { var id = select( 'core/editor' ).getCurrentPostId(); return typeof id === 'number' ? id : 0; }, [] );
		useEffect( function () {
			var text = String( props.text || '' ); var live = true;
			if ( ! /\[[a-z0-9_-]+/i.test( text ) ) { html[1]( '' ); return; }
			var timer = setTimeout( function () {
				wp.apiFetch( { path: '/clara-ve/v1/native/render-shortcode', method: 'POST', data: { text: text.slice( 0, 2000 ), post: postId } } ).then( function ( response ) { if ( live ) { html[1]( response && response.html ? response.html : '' ); } } ).catch( function () { if ( live ) { html[1]( '' ); } } );
			}, 350 );
			return function () { live = false; clearTimeout( timer ); };
		}, [ props.text, postId ] );
		useEffect( function () {
			if ( ! html[0] ) { return; }
			var frame = document.querySelector( 'iframe[name="editor-canvas"]' ); var doc = frame && frame.contentDocument ? frame.contentDocument : document;
			var wrapper = doc.querySelector( '[data-block="' + props.clientId + '"]' ); if ( ! wrapper ) { return; }
			var node = doc.createElement( 'div' ); node.className = 'cve-shortcode-preview'; node.setAttribute( 'inert', '' );
			wrapper.appendChild( node ); wrapper.classList.add( 'cve-shortcode-previewing' ); host[1]( node );
			return function () { wrapper.classList.remove( 'cve-shortcode-previewing' ); if ( node.parentNode ) { node.parentNode.removeChild( node ); } host[1]( null ); };
		}, [ ! html[0], props.clientId ] );
		// The markup was kses-filtered by the render-shortcode route.
		return host[0] && html[0] ? wp.element.createPortal( h( 'div', { dangerouslySetInnerHTML: { __html: html[0] } } ), host[0] ) : null;
	}
	var withPopup = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var closed = useState( false );
			var active = useState( activePopupId );
			useEffect( function () {
				function selected( event ) { active[1]( event.detail ); }
				function reopen() { if ( props.isSelected ) { closed[1]( false ); activePopupId = props.clientId; window.dispatchEvent( new CustomEvent( 'clara-ve-active-popup', { detail: activePopupId } ) ); } }
				function dismiss() { if ( props.isSelected ) { closed[1]( true ); } }
				window.addEventListener( 'clara-ve-active-popup', selected ); window.addEventListener( 'clara-ve-open-popup', reopen ); window.addEventListener( 'clara-ve-close-popup', dismiss );
				if ( props.isSelected ) { activePopupId = props.clientId; window.dispatchEvent( new CustomEvent( 'clara-ve-active-popup', { detail: activePopupId } ) ); }
				return function () { window.removeEventListener( 'clara-ve-active-popup', selected ); window.removeEventListener( 'clara-ve-open-popup', reopen ); window.removeEventListener( 'clara-ve-close-popup', dismiss ); };
			}, [ props.isSelected, props.clientId ] );
			var extrasKey = JSON.stringify( props.attributes.claraVe || {} );
			useEffect( function () {
				if ( extrasKey !== '{}' ) { previewExtras.set( props.clientId, props.attributes.claraVe ); window.dispatchEvent( new CustomEvent( 'clara-ve-extras-changed' ) ); }
				return function () { if ( previewExtras.delete( props.clientId ) ) { window.dispatchEvent( new CustomEvent( 'clara-ve-extras-changed' ) ); } };
			}, [ props.clientId, extrasKey ] );
			useEffect( function () { if ( ! props.isSelected ) { closed[1]( false ); } }, [ props.isSelected ] );
			return h( Fragment, null, h( BlockEdit, props ), props.name === 'core/shortcode' && h( ShortcodePreview, { clientId: props.clientId, text: props.attributes.text } ), props.isSelected && h( NativeFontSettings ), props.isSelected && active[0] === props.clientId && ! closed[0] && h( Popup, { key: props.clientId, block: { name: props.name, clientId: props.clientId, attributes: props.attributes }, setAttributes: props.setAttributes, onClose: function () { closed[1]( true ); } } ) );
		};
	}, 'withVisualEditPopup' );
	wp.hooks.addFilter( 'editor.BlockEdit', 'clara-ve/workspace-popup', withPopup );

	/**
	 * Bold, italic and link for text selected on the page. The native block
	 * toolbar is hidden in the workspace, so the popup offers these three and
	 * applies them through RichText's own format functions.
	 */
	function FormatRow( props ) {
		var selection = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' );
			return editor.getSelectionStart ? { start: editor.getSelectionStart(), end: editor.getSelectionEnd() } : null;
		}, [] );
		var link = useState( null );
		var start = selection && selection.start, end = selection && selection.end;
		var range = start && end && start.clientId === props.clientId && end.clientId === props.clientId && start.attributeKey === props.textKey && end.attributeKey === props.textKey && typeof start.offset === 'number' && typeof end.offset === 'number' && start.offset !== end.offset ? { start: Math.min( start.offset, end.offset ), end: Math.max( start.offset, end.offset ) } : null;
		var rich = wp.richText;
		function value( within ) {
			var live = props.current(); within = within || range; if ( ! live || ! within || ! rich ) { return null; }
			var parsed = rich.create( { html: String( live.attributes[ props.textKey ] || '' ) } );
			parsed.start = within.start; parsed.end = within.end;
			return parsed;
		}
		function commit( next ) { props.write( props.textKey, rich.toHTMLString( { value: next } ) ); }
		function toggle( type ) { var current = value(); if ( current ) { commit( rich.toggleFormat( current, { type: type } ) ); } }
		var currentValue = range ? value() : null;
		function isActive( type ) { return !! ( currentValue && rich.getActiveFormat && rich.getActiveFormat( currentValue, type ) ); }
		var keepFocus = { onMouseDown: function ( event ) { event.preventDefault(); } };
		var hint = range ? undefined : __( 'Select text on the page first', 'visual-edit-lite' );
		// The link row keeps the range it was opened for. Typing the address moves
		// focus out of the page, and a browser is free to collapse the page
		// selection meanwhile; the link still belongs to the words picked first.
		var linkRange = link[0] && ( link[0].range || range );
		function openLink() {
			var active = currentValue && rich.getActiveFormat ? rich.getActiveFormat( currentValue, 'core/link' ) : null;
			link[1]( { url: active && active.attributes ? active.attributes.url || '' : '', range: range } );
		}
		function applyLink() {
			var raw = String( link[0].url || '' ).trim();
			if ( ! raw ) { link[1]( Object.assign( {}, link[0], { error: __( 'Enter a web address first.', 'visual-edit-lite' ) } ) ); return; }
			if ( /^\s*(javascript|data|vbscript):/i.test( raw ) ) { link[1]( Object.assign( {}, link[0], { error: __( 'This address is not allowed.', 'visual-edit-lite' ) } ) ); return; }
			// "example.com" would otherwise become a link relative to the current page.
			var url = wp.url && wp.url.prependHTTP ? wp.url.prependHTTP( raw ).replace( /^http:\/\//i, /^http:\/\//i.test( raw ) ? 'http://' : 'https://' ) : raw;
			var current = value( linkRange );
			if ( ! current ) { link[1]( Object.assign( {}, link[0], { error: __( 'Select the text on the page again.', 'visual-edit-lite' ) } ) ); return; }
			commit( rich.applyFormat( rich.removeFormat( current, 'core/link', linkRange.start, linkRange.end ), { type: 'core/link', attributes: { url: url } }, linkRange.start, linkRange.end ) ); link[1]( null );
		}
		return h( 'div', { className: 'cve-w-format' },
			h( 'div', { className: 'cve-w-format-buttons', role: 'group', 'aria-label': __( 'Text formatting', 'visual-edit-lite' ) },
				button( 'B', function () { toggle( 'core/bold' ); }, Object.assign( { disabled: ! range, 'aria-pressed': isActive( 'core/bold' ), 'aria-label': __( 'Bold', 'visual-edit-lite' ), title: hint || __( 'Bold', 'visual-edit-lite' ), className: 'cve-w-format-bold' }, keepFocus ) ),
				button( 'I', function () { toggle( 'core/italic' ); }, Object.assign( { disabled: ! range, 'aria-pressed': isActive( 'core/italic' ), 'aria-label': __( 'Italic', 'visual-edit-lite' ), title: hint || __( 'Italic', 'visual-edit-lite' ), className: 'cve-w-format-italic' }, keepFocus ) ),
				button( h( 'span', { className: 'dashicons dashicons-admin-links', 'aria-hidden': 'true' } ), openLink, Object.assign( { disabled: ! range, 'aria-pressed': isActive( 'core/link' ), 'aria-label': __( 'Link', 'visual-edit-lite' ), title: hint || __( 'Link', 'visual-edit-lite' ) }, keepFocus ) ),
				! range && ! link[0] && h( 'span', { className: 'cve-w-note' }, __( 'Select text on the page to format it.', 'visual-edit-lite' ) ) ),
			link[0] && linkRange && h( 'div', { className: 'cve-w-format-link' },
				h( Field, { label: __( 'URL', 'visual-edit-lite' ), type: 'url', value: link[0].url, placeholder: 'https://', autoFocus: true,
					onChange: function ( url ) { link[1]( { url: url, range: linkRange } ); },
					onKeyDown: function ( event ) {
						if ( event.key === 'Enter' ) { event.preventDefault(); applyLink(); }
						if ( event.key === 'Escape' ) { event.preventDefault(); event.stopPropagation(); link[1]( null ); }
					} } ),
				link[0].error && h( 'p', { className: 'cve-w-note cve-w-format-error', role: 'alert' }, link[0].error ),
				button( __( 'Apply link', 'visual-edit-lite' ), applyLink, Object.assign( { className: 'cve-w-primary' }, keepFocus ) ),
				button( __( 'Remove link', 'visual-edit-lite' ), function () { var current = value( linkRange ); if ( current ) { commit( rich.removeFormat( current, 'core/link', linkRange.start, linkRange.end ) ); } link[1]( null ); }, keepFocus ) ) );
	}
	/** Children of a container, in order: select, reorder, remove or add one more. */
	function ItemsList( props ) {
		var registry = wp.data.useRegistry();
		var items = JSON.parse( wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' );
			var order = editor.getBlockOrder ? editor.getBlockOrder( props.clientId ) : [];
			return JSON.stringify( order.map( function ( id, index ) {
				var name = editor.getBlockName( id ); var itemType = wp.blocks.getBlockType( name ); var own = editor.getBlockAttributes( id ) || {};
				var label = own.metadata && own.metadata.name ? own.metadata.name : ( wp.blocks.__experimentalGetBlockLabel && itemType ? wp.blocks.__experimentalGetBlockLabel( itemType, own, 'list-view' ) : '' );
				label = String( label || ( itemType ? itemType.title : name ) ).replace( /<[^>]*>/g, '' ).trim().slice( 0, 48 );
				return { id: id, name: name, label: label, canMove: !! ( editor.canMoveBlock && editor.canMoveBlock( id, props.clientId ) ), canRemove: !! ( editor.canRemoveBlock && editor.canRemoveBlock( id ) ), first: index === 0, last: index === order.length - 1 };
			} ) );
		}, [ props.clientId ] ) );
		var editor = registry.select( 'core/block-editor' ); var actions = registry.dispatch( 'core/block-editor' );
		var last = items[ items.length - 1 ];
		// A form adds fields from its own popup; a copy of its last child would be a second send button.
		var canAdd = !! ( last && editor.canInsertBlockType && editor.canInsertBlockType( last.name, props.clientId ) ) && String( editor.getBlockName( props.clientId ) || '' ).indexOf( 'clara-ve/' ) !== 0;
		return h( 'div', { className: 'cve-w-items' },
			h( 'ol', null, items.map( function ( item ) {
				return h( 'li', { key: item.id },
					button( item.label, function () { lastPointer = null; actions.selectBlock( item.id ); }, { className: 'cve-w-item-name', title: __( 'Select', 'visual-edit-lite' ) + ' ' + item.label } ),
					item.canMove && button( '▲', function () { actions.moveBlocksUp( [ item.id ], props.clientId ); }, { disabled: item.first, 'aria-label': __( 'Move up', 'visual-edit-lite' ) + ': ' + item.label } ),
					item.canMove && button( '▼', function () { actions.moveBlocksDown( [ item.id ], props.clientId ); }, { disabled: item.last, 'aria-label': __( 'Move down', 'visual-edit-lite' ) + ': ' + item.label } ),
					item.canRemove && button( '×', function () { actions.removeBlocks( [ item.id ], false ); }, { 'aria-label': __( 'Remove', 'visual-edit-lite' ) + ': ' + item.label } ) );
			} ) ),
			canAdd && button( '＋ ' + __( 'Add item', 'visual-edit-lite' ), function () { actions.duplicateBlocks( [ last.id ], false ); }, { className: 'cve-w-wide', title: __( 'Adds a copy of the last item', 'visual-edit-lite' ) } ) );
	}
	/** A small dark menu anchored to its button. No portal: it stays in the toolbar's stacking context. */
	function Menu( props ) {
		var open = useState( false ); var ref = useRef( null );
		useEffect( function () {
			if ( ! open[0] ) { return; }
			function outside( event ) { if ( ref.current && ! ref.current.contains( event.target ) ) { open[1]( false ); } }
			function key( event ) { if ( event.key === 'Escape' ) { open[1]( false ); } }
			document.addEventListener( 'pointerdown', outside, true ); document.addEventListener( 'keydown', key );
			return function () { document.removeEventListener( 'pointerdown', outside, true ); document.removeEventListener( 'keydown', key ); };
		}, [ open[0] ] );
		function close() { open[1]( false ); }
		return h( 'span', { ref: ref, className: 'cve-w-menu-wrap ' + ( props.className || '' ) },
			button( props.label, function () { open[1]( ! open[0] ); }, { 'aria-expanded': open[0], 'aria-haspopup': 'true', 'aria-label': props.ariaLabel, title: props.title } ),
			open[0] && h( 'div', { className: 'cve-w-menu' + ( props.align === 'right' ? ' is-right' : '' ), role: 'menu' }, props.children( close ) ) );
	}
	var CANVAS_CSS = [
		'html.cve-canvas .block-editor-block-list__block::after{box-shadow:none!important;outline:none!important}',
		'html.cve-canvas .block-editor-block-list__block:is([data-type="core/paragraph"],[data-type="core/heading"],[data-type="core/list-item"],[data-type="core/button"],[data-type="core/image"],[data-type="core/site-title"],[data-type="core/navigation-link"]):not(.is-selected):not([data-cve-hover]){outline:1px dashed rgba(37,99,235,.3);outline-offset:3px}',
		'html.cve-canvas .block-editor-block-list__block[data-cve-hover]:not(.is-selected){outline:2px solid rgba(37,99,235,.85)!important;outline-offset:3px!important}',
		'html.cve-canvas .block-editor-block-list__block.is-selected,html.cve-canvas .block-editor-block-list__block.is-multi-selected{outline:2px solid #2563eb!important;outline-offset:4px!important;box-shadow:0 0 0 4px rgba(37,99,235,.16)!important}',
		'html.cve-canvas .block-editor-block-list__block.is-selected:focus,html.cve-canvas .rich-text:focus{outline:2px solid #2563eb!important}',
		// An empty group is usually a theme's decorative layer (a scrim, a glow). Its
		// "Select a layout" placeholder does not exist on the page and covers the design.
		'html.cve-canvas .wp-block-group__placeholder>.components-placeholder{display:none!important}',
		// A shortcode block showing its rendered output instead of WordPress's text box.
		'html.cve-canvas .cve-shortcode-previewing{display:block!important;padding:0!important;border:0!important;box-shadow:none!important;background:none!important;min-height:0!important;color:inherit!important;font:inherit!important;letter-spacing:inherit!important}',
		'html.cve-canvas .cve-shortcode-previewing>:not(.cve-shortcode-preview){display:none!important}',
		'html.cve-canvas .cve-shortcode-preview{pointer-events:none}',
		// Themes often style a link inside running text exactly like the text, so a
		// link just added would look as if nothing happened. Editor-only hint.
		'html.cve-canvas [contenteditable="true"] a[href]:not(.wp-element-button){text-decoration-line:underline!important;text-decoration-style:dotted!important;text-decoration-thickness:1px!important;text-underline-offset:3px!important;text-decoration-color:#2563eb!important}',
		// A form is the one thing on a page that DOES something, and its fields
		// look like the design's own boxes, so until it was clicked nothing said
		// so. Marked permanently and in a colour used nowhere else — green,
		// beside blue for "editable" — and labelled, the same as in the HTML
		// editor (assets/bridge.css). `form` as well as the block covers a form
		// still living inside a Custom HTML or shortcode block.
		'html.cve-canvas .block-editor-block-list__block[data-type="clara-ve/form"],html.cve-canvas .block-editor-block-list__block form{position:relative;outline:2px dashed rgba(22,163,74,.55)!important;outline-offset:4px!important}',
		'html.cve-canvas .block-editor-block-list__block[data-type="clara-ve/form"]::before,html.cve-canvas .block-editor-block-list__block form::before{content:' + JSON.stringify( __( 'Form', 'visual-edit-lite' ) ) + ';position:absolute;top:0;left:0;z-index:99960;transform:translateY(-100%);padding:3px 8px;border-radius:6px 6px 6px 0;background:#16a34a;color:#fff;font:600 11px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;pointer-events:none;white-space:nowrap}',
		// Everything inside the form belongs to it: a blue ring on a field inside
		// a green box is the confusion this is here to remove.
		// The form block's own editor markup contains a real <form>, so both
		// rules above match it and the box would be drawn — and labelled —
		// twice on the same corner. The block wrapper is the one that gets it.
		'html.cve-canvas .block-editor-block-list__block[data-type="clara-ve/form"] form{outline:none!important}',
		'html.cve-canvas .block-editor-block-list__block[data-type="clara-ve/form"] form::before{content:none!important}',
		'html.cve-canvas .block-editor-block-list__block[data-type^="clara-ve/"]:not([data-type="clara-ve/form"]):not(.is-selected):not([data-cve-hover]){outline:1px dashed rgba(22,163,74,.35);outline-offset:3px}',
		'html.cve-canvas .block-editor-block-list__block[data-type^="clara-ve/"][data-cve-hover]:not(.is-selected){outline:2px solid rgba(22,163,74,.85)!important;outline-offset:3px!important}',
		'html.cve-canvas .block-editor-block-list__block[data-type^="clara-ve/"].is-selected,html.cve-canvas .block-editor-block-list__block[data-type^="clara-ve/"].is-multi-selected{outline:2px solid #16a34a!important;outline-offset:4px!important;box-shadow:0 0 0 4px rgba(22,163,74,.16)!important}'
	].join( '\n' );
	var MEDIA_BLOCKS = [ 'core/image', 'core/video', 'core/cover', 'core/media-text', 'core/site-logo', 'core/post-featured-image' ];
	/** clientId of a media block lying under a click that hit only a decorative layer or a container box. */
	function mediaBeneath( doc, event ) {
		if ( ! doc.elementsFromPoint ) { return null; }
		var stack = doc.elementsFromPoint( event.clientX, event.clientY );
		var hit = stack[0]; if ( ! hit || ! hit.closest ) { return null; }
		// Real content under the pointer (text being edited, a caret target, a control) keeps the click.
		if ( hit.closest( '[contenteditable="true"], a, button, input, textarea, select, video, img' ) ) { return null; }
		var top = hit.closest( '[data-block]' ); if ( ! top ) { return null; }
		var name = top.getAttribute( 'data-type' ) || '';
		if ( MEDIA_BLOCKS.indexOf( name ) > -1 || TEXT_BLOCKS.indexOf( name ) > -1 || /^core\/(button|list-item|navigation-link|site-title)$/.test( name ) ) { return null; }
		for ( var i = 1; i < stack.length; i++ ) {
			var below = stack[ i ].closest ? stack[ i ].closest( '[data-block]' ) : null;
			if ( ! below || below === top || below.contains( top ) || top.contains( below ) && ! stack[ i ].closest( 'img, video' ) ) { continue; }
			if ( MEDIA_BLOCKS.indexOf( below.getAttribute( 'data-type' ) || '' ) > -1 ) { return below.getAttribute( 'data-block' ); }
		}
		return null;
	}
	/*
	 * Gutenberg's own UI inside Visual Edit follows the dark popup design.
	 * WordPress styles it for a light ground in hundreds of hardcoded rules
	 * and CSS-in-JS, so instead of chasing selectors every native surface is
	 * measured after each change: light grounds become dark surfaces, text or
	 * icons that lost contrast get a readable colour. Swatches, previews and
	 * media keep their real colours.
	 */
	var DARK_ROOTS = [
		'.cve-w-native',
		'body.cve-native-collapsed .interface-interface-skeleton__sidebar',
		'body.cve-native-collapsed .interface-interface-skeleton__secondary-sidebar',
		'body.cve-native-collapsed .interface-interface-skeleton__actions',
		'body.cve-native-collapsed .components-popover:not(.block-editor-block-popover):not(.block-editor-block-list__block-popover):not(.block-editor-block-list__insertion-point-popover)',
		'body.cve-native-collapsed .components-modal__frame'
	].join( ',' );
	/* cve:dark-sweep:start — tests/workspace-dark-sweep.cjs slices from here to
	   the matching end marker and runs this code against a real DOM. Keep the
	   block self-contained: no reference to anything defined outside it. */
	var DARK_KEEP = 'img, video, iframe, canvas, [style*="background:"], [style*="background-color"], [style*="background-image"], .component-color-indicator, [class*="color-indicator"], [class*="circular-option-picker__option"], .block-editor-block-preview__container, .block-editor-block-preview__content, .react-colorful, .components-color-picker, [class*="gradient-picker"], [class*="duotone"], [class*="global-styles-ui-preview"], [class*="global-styles-preview"], [class*="variations_item-preview"], .block-editor-inserter__preview-container, .editor-post-featured-image__preview, .components-range-control__track, .components-range-control__thumb-wrapper, .components-form-toggle__track, .components-form-toggle__thumb, .components-checkbox-control__input, .components-radio-control__input';
	// Rendered page previews keep their own colours, text included.
	var DARK_PREVIEWS = '.block-editor-block-preview__container, [class*="global-styles-ui-preview"], [class*="global-styles-preview"], [class*="variations_item-preview"], .block-editor-inserter__preview-container, .edit-site-style-book__iframe';
	var DARK_CLASSES = [ 'cve-dk-surface', 'cve-dk-border', 'cve-dk-text', 'cve-dk-muted', 'cve-dk-accent', 'cve-dk-danger', 'cve-dk-ink', 'cve-dk-icon', 'cve-dk-icon-ink', 'cve-dk-stroke', 'cve-dk-stroke-ink' ];
	function rgba( value ) { var m = String( value || '' ).match( /[\d.]+/g ); return m && m.length >= 3 ? { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 } : null; }
	function luminance( c ) { function ch( v ) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 ); } return 0.2126 * ch( c.r ) + 0.7152 * ch( c.g ) + 0.0722 * ch( c.b ); }
	function contrast( a, b ) { var x = luminance( a ), y = luminance( b ); return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 ); }
	function darkSweep( root ) {
		var all = [ root ].concat( [].slice.call( root.querySelectorAll( '*' ) ) );
		all.forEach( function ( el ) { if ( el.classList ) { el.classList.remove.apply( el.classList, DARK_CLASSES ); } } );
		var styles = new Map();
		function cs( el ) { var v = styles.get( el ); if ( ! v ) { v = window.getComputedStyle( el ); styles.set( el, v ); } return v; }
		// Only look inside the root: the page's <html> carries design tokens named --…-background.
		var kept = all.map( function ( el ) { var k = el.closest && el.closest( DARK_KEEP ); return !! k && root.contains( k ); } );
		// 1. Light grounds and dark strokes.
		var surfaces = [], borders = [];
		all.forEach( function ( el, i ) {
			if ( kept[ i ] || el === root || el instanceof SVGElement ) { return; }
			var style = cs( el ); var bg = rgba( style.backgroundColor );
			if ( bg && bg.a > 0.4 && style.backgroundImage === 'none' && luminance( bg ) > 0.45 && ! ( bg.r === 245 && bg.g === 245 && bg.b === 247 ) && ! el.matches( '.is-active, .is-pressed, [aria-pressed="true"]' ) ) { surfaces.push( el ); }
			var bc = rgba( style.borderTopColor ); if ( bc && parseFloat( style.borderTopWidth ) > 0 && bc.a > 0.3 && luminance( bc ) < 0.12 ) { borders.push( el ); }
		} );
		surfaces.forEach( function ( el ) { el.classList.add( 'cve-dk-surface' ); } );
		borders.forEach( function ( el ) { el.classList.add( 'cve-dk-border' ); } );
		styles = new Map();
		// 2. Text and icons against the ground they now sit on.
		function ground( el ) {
			for ( var e = el; e && e.nodeType === 1; e = e.parentElement ) {
				var bg = rgba( cs( e ).backgroundColor ); if ( bg && bg.a > 0.4 ) { return bg; }
				if ( e === root ) { break; }
			}
			return { r: 32, g: 32, b: 36, a: 1 };
		}
		var marks = [];
		all.forEach( function ( el, i ) {
			var preview = el.closest && el.closest( DARK_PREVIEWS ); if ( preview && root.contains( preview ) ) { return; }
			var isIcon = el instanceof SVGElement && ( el.tagName === 'svg' || el.tagName === 'path' );
			var hasText = ! isIcon && ( /^(INPUT|SELECT|TEXTAREA)$/.test( el.tagName ) || [].some.call( el.childNodes, function ( n ) { return n.nodeType === 3 && n.textContent.trim(); } ) );
			if ( ! isIcon && ! hasText ) { return; }
			var style = cs( el ); var bg = ground( el );
			if ( isIcon ) {
				var swatch = el.closest( '[style*="background:"], [style*="background-color"], .component-color-indicator' );
				if ( swatch && root.contains( swatch ) ) { return; }
				var dark = luminance( bg ) <= 0.4;
				// A LINE icon is drawn with stroke and fill:none — a whole
				// family of third-party icon sets is nothing but these. Reading
				// only `fill` declared them untouchable and left them their
				// author's dark grey on a ground this sweep had just painted
				// dark: a picker full of invisible icons. They are repaired on
				// the property they are actually drawn with, and never by
				// filling them, which would turn an outline into a blob.
				var stroke = rgba( style.stroke );
				if ( stroke && style.stroke !== 'none' && parseFloat( style.strokeWidth ) > 0 && contrast( stroke, bg ) < 3 ) {
					marks.push( [ el, dark ? 'cve-dk-stroke' : 'cve-dk-stroke-ink' ] );
				}
				if ( style.fill === 'none' ) { return; }
				var fill = rgba( style.fill ); if ( fill && contrast( fill, bg ) < 3 ) { marks.push( [ el, dark ? 'cve-dk-icon' : 'cve-dk-icon-ink' ] ); }
				return;
			}
			var fg = rgba( style.color ); if ( ! fg || contrast( fg, bg ) >= 4.5 ) { return; }
			if ( luminance( bg ) > 0.4 ) { marks.push( [ el, 'cve-dk-ink' ] ); return; }
			var cls = 'cve-dk-text';
			if ( fg.b > fg.r + 60 && fg.b > fg.g + 20 ) { cls = 'cve-dk-accent'; } else if ( fg.r > fg.g + 80 && fg.r > fg.b + 80 ) { cls = 'cve-dk-danger'; } else if ( luminance( fg ) > 0.08 ) { cls = 'cve-dk-muted'; }
			marks.push( [ el, cls ] );
		} );
		marks.forEach( function ( m ) { m[0].classList.add( m[1] ); } );
	}
	/* cve:dark-sweep:end */
	function NativeDark() {
		useEffect( function () {
			var pending = 0;
			var observer = new MutationObserver( schedule );
			function watch() { observer.observe( document.body, { subtree: true, childList: true, attributes: true, attributeFilter: [ 'class', 'aria-pressed', 'aria-checked', 'aria-selected', 'aria-expanded', 'style' ] } ); }
			var lastCost = 0;
			function run() {
				pending = 0; var started = Date.now();
				observer.disconnect();
				try {
					[].slice.call( document.querySelectorAll( '.cve-dark-native' ) ).forEach( function ( el ) { if ( ! el.matches( DARK_ROOTS ) ) { el.classList.remove( 'cve-dark-native' ); } } );
					[].slice.call( document.querySelectorAll( DARK_ROOTS ) ).forEach( function ( root ) { root.classList.add( 'cve-dark-native' ); darkSweep( root ); } );
				} finally { watch(); lastCost = Date.now() - started; }
			}
			function schedule( records ) {
				if ( records && ! records.some( function ( r ) { return ! ( r.target.closest && r.target.closest( '.cve-w-toolbar' ) ); } ) ) { return; }
				if ( pending ) { return; }
				// Before the next paint, so a re-render never shows its light colours.
				// A large surface (the block inserter) is measured at most a few times
				// a second instead of on every hover.
				if ( lastCost > 25 ) { pending = window.setTimeout( run, 150 ); return; }
				pending = window.requestAnimationFrame ? window.requestAnimationFrame( run ) : window.setTimeout( run, 16 );
			}
			// Hover and focus colours change through CSS alone, without a DOM mutation.
			function interact( event ) { if ( event.target && event.target.closest && event.target.closest( '.cve-dark-native' ) ) { schedule(); } }
			document.addEventListener( 'pointerover', interact, true ); document.addEventListener( 'focusin', interact, true );
			run();
			return function () { observer.disconnect(); document.removeEventListener( 'pointerover', interact, true ); document.removeEventListener( 'focusin', interact, true ); if ( pending ) { window.clearTimeout( pending ); if ( window.cancelAnimationFrame ) { window.cancelAnimationFrame( pending ); } } };
		}, [] );
		return null;
	}
	/** Highlights and click tracking in every editor canvas, the way HTML mode outlines its page. */
	function CanvasOverlay() {
		useEffect( function () {
			function prepare( doc, frame ) {
				if ( ! doc || ! doc.documentElement ) { return; }
				if ( doc.head && ! doc.getElementById( 'cve-workspace-canvas' ) ) { var style = doc.createElement( 'style' ); style.id = 'cve-workspace-canvas'; style.textContent = CANVAS_CSS; doc.head.appendChild( style ); }
				var on = document.body.classList.contains( 'cve-native-collapsed' );
				if ( doc.documentElement.classList.contains( 'cve-canvas' ) !== on ) { doc.documentElement.classList.toggle( 'cve-canvas', on ); }
				if ( doc.__claraVeCanvas ) { return; }
				doc.__claraVeCanvas = true;
				var hovered = null;
				doc.addEventListener( 'pointerdown', function ( event ) {
					// Clicks on Visual Edit's own UI are not clicks on the page.
					if ( ! frame && event.target && event.target.closest && event.target.closest( '.cve-w-popup, .cve-w-toolbar, .cve-w-dock, .cve-w-badge, .cve-w-hint, .components-modal__frame, .components-popover' ) ) { return; }
					var offset = frame ? frame.getBoundingClientRect() : { left: 0, top: 0 };
					lastPointer = { x: event.clientX + offset.left, y: event.clientY + offset.top, t: Date.now() };
					redirect = document.body.classList.contains( 'cve-native-collapsed' ) ? mediaBeneath( doc, event ) : null;
				}, true );
				// Same rule as the HTML editor (bridge.js contentBeneathDecoration): a
				// click on a decorative layer or a container's own box that covers an
				// image selects the image, e.g. a hero photo under a scrim and overlay.
				var redirect = null;
				function swallow( event ) {
					if ( ! redirect ) { return; }
					event.preventDefault(); event.stopPropagation();
					if ( event.type === 'mousedown' ) { var id = redirect; wp.data.dispatch( 'core/block-editor' ).selectBlock( id ); }
					if ( event.type === 'click' ) { redirect = null; }
				}
				doc.addEventListener( 'mousedown', swallow, true );
				doc.addEventListener( 'mouseup', swallow, true );
				doc.addEventListener( 'click', swallow, true );
				// A click on the block that is already selected reopens a closed popup:
				// clicking the page always means "edit this", as in the HTML editor.
				doc.addEventListener( 'pointerup', function ( event ) {
					if ( redirect || ! document.body.classList.contains( 'cve-native-collapsed' ) ) { return; }
					if ( ! frame && event.target && event.target.closest && event.target.closest( '.cve-w-popup, .cve-w-toolbar, .cve-w-dock, .cve-w-badge, .cve-w-hint, .components-modal__frame, .components-popover' ) ) { return; }
					var el = event.target && event.target.closest ? event.target.closest( '[data-block]' ) : null;
					if ( ! el ) { return; }
					var selectedId = wp.data.select( 'core/block-editor' ).getSelectedBlockClientId();
					if ( el.getAttribute( 'data-block' ) === selectedId ) { window.setTimeout( function () { window.dispatchEvent( new CustomEvent( 'clara-ve-open-popup' ) ); }, 0 ); }
				}, true );
				doc.addEventListener( 'pointermove', function ( event ) {
					var target = event.target && event.target.closest ? event.target.closest( '.block-editor-block-list__block' ) : null;
					if ( target === hovered ) { return; }
					if ( hovered ) { hovered.removeAttribute( 'data-cve-hover' ); }
					hovered = target;
					if ( hovered ) { hovered.setAttribute( 'data-cve-hover', '' ); }
				}, true );
				doc.addEventListener( 'mouseout', function ( event ) { if ( ! event.relatedTarget && hovered ) { hovered.removeAttribute( 'data-cve-hover' ); hovered = null; } }, true );
			}
			function refresh() {
				prepare( document, null );
				canvasFrames().forEach( function ( frame ) { try { prepare( frame.contentDocument, frame ); } catch ( error ) {} } );
			}
			refresh();
			var observer = new MutationObserver( refresh ); observer.observe( document.body, { subtree: true, childList: true } );
			var classes = new MutationObserver( refresh ); classes.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
			document.addEventListener( 'load', refresh, true );
			return function () { observer.disconnect(); classes.disconnect(); document.removeEventListener( 'load', refresh, true ); };
		}, [] );
		return null;
	}
	/** The HTML editor's element label: what is selected, and in which section. */
	function SelectionBadge() {
		var selected = wp.data.useSelect( function ( select ) { var editor = select( 'core/block-editor' ); return editor.getSelectedBlockClientId ? editor.getSelectedBlockClientId() : null; }, [] );
		var blockName = wp.data.useSelect( function ( select ) { return selected ? select( 'core/block-editor' ).getBlockName( selected ) || '' : ''; }, [ selected ] );
		var label = wp.data.useSelect( function ( select ) {
			if ( ! selected ) { return ''; }
			var editor = select( 'core/block-editor' ); var name = editor.getBlockName( selected ); var blockType = wp.blocks.getBlockType( name );
			var own = ( editor.getBlockAttributes( selected ) || {} ).metadata || {};
			var section = '';
			editor.getBlockParents( selected ).slice().reverse().some( function ( id ) { var meta = ( editor.getBlockAttributes( id ) || {} ).metadata || {}; section = meta.name || ''; return !! section; } );
			var titleText = own.name || ( blockType ? blockType.title : name );
			return section && section !== titleText ? titleText + ' · ' + section : titleText;
		}, [ selected ] );
		var place = useState( null );
		useEffect( function () {
			if ( ! selected ) { place[1]( null ); return; }
			var frame = 0;
			function update() {
				frame = 0;
				var found = blockElement( selected );
				var visible = document.body.classList.contains( 'cve-native-collapsed' );
				if ( ! found || ! visible ) { place[1]( null ); return; }
				var box = found.node.getBoundingClientRect();
				var top = box.top + found.offset.top; var bottom = box.bottom + found.offset.top;
				if ( bottom < 70 || top > window.innerHeight ) { place[1]( null ); return; }
				var next = { left: Math.round( Math.max( 4, box.left + found.offset.left ) ), top: Math.round( Math.max( 62, top - 26 ) ) };
				place[1]( function ( previous ) { return previous && previous.left === next.left && previous.top === next.top ? previous : next; } );
			}
			function schedule() { if ( ! frame ) { frame = window.requestAnimationFrame( update ); } }
			update();
			var windows = [ window ].concat( canvasFrames().map( function ( item ) { try { return item.contentWindow; } catch ( error ) { return null; } } ).filter( Boolean ) );
			windows.forEach( function ( item ) { item.addEventListener( 'scroll', schedule, true ); item.addEventListener( 'resize', schedule ); } );
			var timer = window.setInterval( schedule, 300 );
			return function () { window.clearInterval( timer ); if ( frame ) { window.cancelAnimationFrame( frame ); } windows.forEach( function ( item ) { item.removeEventListener( 'scroll', schedule, true ); item.removeEventListener( 'resize', schedule ); } ); };
		}, [ selected ] );
		if ( ! selected || ! place[0] || ! label ) { return null; }
		// A form block's badge is green like its outline, so the selected thing
		// and the thing marked on the page are visibly the same thing.
		var className = 'cve-w-badge' + ( 0 === blockName.indexOf( 'clara-ve/' ) ? ' cve-w-badge-form' : '' );
		return portal( button( label, function () { window.dispatchEvent( new CustomEvent( 'clara-ve-open-popup' ) ); }, { className: className, style: place[0], title: __( 'Open Visual Edit settings', 'visual-edit-lite' ) } ) );
	}
	/** The theme's own sections. Inserted blocks are an ordinary, undoable edit. */
	function PatternBrowser( props ) {
		var registry = wp.data.useRegistry();
		var target = props.target && props.target.index !== undefined ? props.target : insertionTarget( registry.select );
		var patterns = wp.data.useSelect( function ( select ) { return themePatterns( select( 'core/block-editor' ), target.rootClientId ); }, [ target.rootClientId ] );
		var search = useState( '' );
		var visible = patterns.filter( function ( pattern ) { var query = search[0].trim().toLowerCase(); return ! query || String( pattern.title ).toLowerCase().indexOf( query ) >= 0 || String( pattern.description || '' ).toLowerCase().indexOf( query ) >= 0; } );
		function choose( pattern, blocks ) {
			lastPointer = null;
			if ( insertPattern( registry, pattern, target, blocks ) ) { emit( 'apply', { op: 'insert-pattern', pattern: pattern.name } ); }
			props.onClose();
		}
		var List = be.__experimentalBlockPatternsList;
		return h( c.Modal, { title: __( 'Add a section', 'visual-edit-lite' ), className: 'cve-w-dialog cve-w-patterns', onRequestClose: props.onClose },
			h( 'p', { className: 'cve-w-note' }, __( 'Sections come from your theme, so they already match the design. The new section is added after the selected one.', 'visual-edit-lite' ) ),
			h( Field, { label: __( 'Search', 'visual-edit-lite' ), value: search[0], onChange: search[1], placeholder: __( 'Search sections…', 'visual-edit-lite' ) } ),
			! patterns.length && h( 'p', null, __( 'This theme offers no sections that can be added here.', 'visual-edit-lite' ) ),
			List ? h( 'div', { className: 'cve-w-pattern-grid' }, h( List, { blockPatterns: visible, shownPatterns: visible, onClickPattern: choose, label: __( 'Theme sections', 'visual-edit-lite' ), isDraggable: false } ) ) :
				h( 'div', { className: 'cve-w-site-list' }, visible.map( function ( pattern ) { return button( pattern.title, function () { choose( pattern ); }, { key: pattern.name, title: pattern.description } ); } ) ) );
	}
	function Workspace() {
		var registry = wp.data.useRegistry();
		var panel = useState( '' ); var fontOpen = useState( false ); var historyOpen = useState( false );
		var patterns = useState( null ); var restored = useState( false ); var native = useState( false );
		var hint = useState( function () { try { return ! window.localStorage.getItem( 'clara-ve-workspace-hint' ); } catch ( error ) { return false; } } );
		var status = wp.data.useSelect( function ( select ) {
			var core = select( 'core' ); var editor = select( 'core/editor' ); var blocks = select( 'core/block-editor' );
			var dirty = core && core.__experimentalGetDirtyEntityRecords ? core.__experimentalGetDirtyEntityRecords() : [];
			var postType = editor && editor.getCurrentPostType ? editor.getCurrentPostType() : '';
			var type = postType && core && core.getPostType ? core.getPostType( postType ) : null;
			var error = dirty.map( function ( item ) { return core.getLastEntitySaveError ? core.getLastEntitySaveError( item.kind, item.name, item.key ) : null; } ).find( Boolean );
			return { dirty: dirty.length, saving: dirty.some( function ( item ) { return core.isSavingEntityRecord( item.kind, item.name, item.key ); } ), canUndo: editor && editor.hasEditorUndo ? editor.hasEditorUndo() : true, canRedo: editor && editor.hasEditorRedo ? editor.hasEditorRedo() : true, error: error && error.message, viewable: !! ( type && type.viewable ), postId: editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0, postType: postType, title: editor && editor.getEditedPostAttribute ? editor.getEditedPostAttribute( 'title' ) : '', link: editor && editor.getPermalink ? editor.getPermalink() : config.homeUrl, device: editor && editor.getDeviceType ? editor.getDeviceType() : '', selected: blocks && blocks.getSelectedBlockClientId ? blocks.getSelectedBlockClientId() : null, unlocked: designUnlocked( blocks && blocks.getSettings ? blocks.getSettings() : {} ) };
		}, [] );
		var wasSaving = useRef( false );
		useEffect( function () { notifyHost( 'dirty', { dirty: status.dirty > 0 } ); if ( ! status.dirty ) { restored[1]( false ); } }, [ status.dirty ] );
		useEffect( function () {
			if ( wasSaving.current && ! status.saving && ! status.error ) { emit( 'save', { dirty: status.dirty } ); }
			wasSaving.current = status.saving;
		}, [ status.saving ] );
		useEffect( function () {
			if ( ! status.selected ) { return; }
			if ( hint[0] ) { dismissHint(); }
			if ( window.ClaraVE && window.ClaraVE.getSelection ) { emit( 'select', window.ClaraVE.getSelection() ); }
		}, [ status.selected ] );
		useEffect( function () {
			document.body.classList.add( 'cve-workspace-runtime' ); notifyHost( 'ready' );
			// Collapse chrome only after both the VE toolbar and a native save
			// control exist. Unknown editor layouts retain all native controls.
			var initialized = false;
			function ready() {
				if ( initialized || ! document.querySelector( '.cve-w-toolbar' ) || ! document.querySelector( nativeHeader ) || ! document.querySelector( '.editor-post-publish-button__button, .edit-site-save-button__button' ) ) { return; }
				initialized = true; document.body.classList.add( 'cve-native-collapsed' );
				// Start from the page itself: no inserter, list view or sidebar.
				nativeAction( 'setIsInserterOpened', false, registry, true );
				nativeAction( 'setIsListViewOpened', false, registry, true );
				// WordPress remembers an open sidebar per user and restores it a moment
				// after the editor mounts, so close it again once that has happened.
				var opened = Date.now();
				function closeSidebar() { try { if ( ! nativeAreaRequested || nativeAreaRequested < opened ) { registry.dispatch( 'core/interface' ).disableComplementaryArea( 'core' ); } } catch ( error ) {} }
				closeSidebar(); [ 300, 1200, 3000 ].forEach( function ( delay ) { window.setTimeout( closeSidebar, delay ); } );
				try { if ( window.sessionStorage.getItem( 'clara-ve-design-unlocked' ) ) { setDesignLock( registry, true ); } } catch ( error ) {}
			}
			ready(); var observer = new MutationObserver( ready ); observer.observe( document.body, { childList: true, subtree: true } );
			function openPatterns( event ) { patterns[1]( event.detail || {} ); }
			function wasRestored() { restored[1]( true ); }
			window.addEventListener( 'clara-ve-open-patterns', openPatterns ); window.addEventListener( 'clara-ve:restore', wasRestored );
			return function () { observer.disconnect(); window.removeEventListener( 'clara-ve-open-patterns', openPatterns ); window.removeEventListener( 'clara-ve:restore', wasRestored ); document.body.classList.remove( 'cve-workspace-runtime', 'cve-native-collapsed', 'cve-history-open' ); };
		}, [] );
		useEffect( function () { document.body.classList.toggle( 'cve-history-open', historyOpen[0] ); }, [ historyOpen[0] ] );
		function dismissHint() { hint[1]( false ); try { window.localStorage.setItem( 'clara-ve-workspace-hint', '1' ); } catch ( error ) {} }
		function save() {
			// Use the existing button so multi-entity review, publish checks,
			// plugin validations and errors remain in the canonical save flow.
			var target = document.querySelector( '.edit-site-save-button__button, .editor-post-publish-button__button, .editor-post-publish-button' );
			if ( target && ! target.disabled && target.getAttribute( 'aria-disabled' ) !== 'true' ) { target.click(); }
		}
		function toggleNative() {
			if ( ! document.querySelector( nativeHeader ) ) { return; }
			var show = document.body.classList.contains( 'cve-native-collapsed' );
			document.body.classList.toggle( 'cve-native-collapsed', ! show ); native[1]( show );
		}
		// WordPress 7 mounts the Styles panel only while a template is on screen (a template, or a
		// page shown inside its template). Show the page inside its template for as long as the
		// panel is open, then return to the page alone.
		function openSiteStyles() {
			var editor = registry.select( 'core/editor' ); var actions = registry.dispatch( 'core/editor' );
			var previous = editor.getRenderingMode ? editor.getRenderingMode() : null;
			var swap = previous && previous !== 'template-locked' && editor.getCurrentPostType() !== 'wp_template' && actions.setRenderingMode;
			if ( swap ) { actions.setRenderingMode( 'template-locked' ); }
			setTimeout( function () {
				openArea( 'edit-site/global-styles' );
				if ( ! swap ) { return; }
				var unsubscribe = registry.subscribe( function () {
					if ( registry.select( 'core/interface' ).getActiveComplementaryArea( 'core' ) === 'edit-site/global-styles' ) { return; }
					unsubscribe(); if ( registry.select( 'core/editor' ).getRenderingMode() === 'template-locked' ) { actions.setRenderingMode( previous ); }
				} );
			}, 50 );
		}
		function openArea( name ) { nativeAreaRequested = Date.now(); try { registry.dispatch( 'core/interface' ).enableComplementaryArea( 'core', name ); } catch ( error ) { showNative(); } }
		var cleanNative = new URL( window.location.href ); cleanNative.searchParams.delete( 'clara_ve_workspace' ); cleanNative.searchParams.delete( 'clara_ve_session' );
		var moreItems = function () { return applyHooks( 'clara_ve.toolbar.more', [
			{ key: 'document', label: __( 'Page settings', 'visual-edit-lite' ), onClick: function () { openArea( 'edit-post/document' ); } },
			config.isSiteEditor && { key: 'styles', label: __( 'Site styles', 'visual-edit-lite' ), onClick: openSiteStyles },
			{ key: 'structure', label: __( 'Page structure (list view)', 'visual-edit-lite' ), onClick: function () { nativeAction( 'setIsListViewOpened', true, registry ); } },
			{ key: 'inserter', label: __( 'All blocks', 'visual-edit-lite' ), onClick: function () { nativeAction( 'setIsInserterOpened', true, registry ); } },
			config.canManageFonts && { key: 'fonts', label: __( 'Google Fonts', 'visual-edit-lite' ), onClick: function () { fontOpen[1]( true ); } },
			window.ClaraVENative && ( status.postType === 'post' || status.postType === 'page' ) && { key: 'seo', label: __( 'Search appearance', 'visual-edit-lite' ), onClick: function () { panel[1]( 'seo' ); } },
			canSetDesignLock( registry ) && { key: 'lock', label: status.unlocked ? __( 'Lock pattern design', 'visual-edit-lite' ) : __( 'Unlock pattern design', 'visual-edit-lite' ), onClick: function () { setDesignLock( registry, ! status.unlocked ); } },
			{ key: 'native', label: native[0] ? __( 'Hide WordPress controls', 'visual-edit-lite' ) : __( 'Show WordPress controls', 'visual-edit-lite' ), onClick: toggleNative },
			{ key: 'seo-settings', label: __( 'SEO & sharing settings', 'visual-edit-lite' ), href: config.seoUrl },
			{ key: 'forms', label: __( 'Form submissions', 'visual-edit-lite' ), href: config.formsUrl },
			{ key: 'wp-editor', label: __( 'Open in the WordPress editor', 'visual-edit-lite' ), href: cleanNative.toString() },
			{ key: 'exit', label: __( 'Back to the dashboard', 'visual-edit-lite' ), href: config.adminUrl, top: true }
		].filter( Boolean ), { registry: registry, status: status } ); };
		function menuItem( item, close ) {
			if ( item.href ) { return h( 'a', { key: item.key, href: item.href, role: 'menuitem', target: item.top ? '_top' : '_blank', rel: item.top ? undefined : 'noopener', onClick: close }, item.label ); }
			return button( item.label, function () { close(); item.onClick(); }, { key: item.key, role: 'menuitem' } );
		}
		var device = status.device || 'Desktop';
		var documentTitle = status.title && typeof status.title === 'object' ? status.title.raw || status.title.rendered : status.title;
		return portal( h( Fragment, null, h( 'div', { className: 'cve-w-toolbar', role: 'toolbar', 'aria-label': __( 'Visual Edit', 'visual-edit-lite' ) },
			h( Menu, { className: 'cve-w-doc', label: h( Fragment, null, h( 'span', { className: 'cve-w-doc-title' }, documentTitle || __( 'Your website', 'visual-edit-lite' ) ), ' ▾' ), title: __( 'Switch page, template or part', 'visual-edit-lite' ) }, function ( close ) { return h( SiteBrowser, { onClose: close, dirty: status.dirty > 0 } ); } ),
			h( 'select', { 'aria-label': __( 'Device', 'visual-edit-lite' ), value: device, onChange: function ( event ) { nativeAction( 'setDeviceType', event.target.value, registry ); } }, [ [ 'Desktop', __( 'Desktop', 'visual-edit-lite' ) ], [ 'Tablet', __( 'Tablet', 'visual-edit-lite' ) ], [ 'Mobile', __( 'Mobile', 'visual-edit-lite' ) ] ].map( function ( item ) { return h( 'option', { key: item[0], value: item[0] }, item[1] ); } ) ),
			button( '↶', function () { nativeAction( 'undo', undefined, registry ); }, { 'aria-label': __( 'Undo', 'visual-edit-lite' ), title: __( 'Undo', 'visual-edit-lite' ), disabled: ! status.canUndo || status.saving } ),
			button( '↷', function () { nativeAction( 'redo', undefined, registry ); }, { 'aria-label': __( 'Redo', 'visual-edit-lite' ), title: __( 'Redo', 'visual-edit-lite' ), disabled: ! status.canRedo || status.saving } ),
			button( h( Fragment, null, '↺ ', h( 'span', { className: 'cve-w-label' }, __( 'History', 'visual-edit-lite' ) ) ), function () { historyOpen[1]( ! historyOpen[0] ); }, { disabled: ! status.postId, 'aria-pressed': historyOpen[0], 'aria-label': __( 'History', 'visual-edit-lite' ), title: __( 'Saved versions and restore', 'visual-edit-lite' ) } ),
			button( h( Fragment, null, '＋ ', h( 'span', { className: 'cve-w-label' }, __( 'Section', 'visual-edit-lite' ) ) ), function () { patterns[1]( {} ); }, { 'aria-label': __( 'Add a section', 'visual-edit-lite' ), title: __( 'Add a section from your theme', 'visual-edit-lite' ) } ),
			status.unlocked && button( h( Fragment, null, h( 'span', { className: 'dashicons dashicons-unlock', 'aria-hidden': 'true' } ), ' ' + __( 'Design unlocked', 'visual-edit-lite' ) ), function () { setDesignLock( registry, false ); }, { className: 'cve-w-chip', title: __( 'Lock pattern design again', 'visual-edit-lite' ) } ),
			restored[0] && status.dirty > 0 && h( 'span', { className: 'cve-w-chip is-restored', role: 'status' }, __( 'Version restored — Save to keep it', 'visual-edit-lite' ), button( __( 'Undo', 'visual-edit-lite' ), function () { nativeAction( 'undo', undefined, registry ); } ) ),
			h( 'span', { className: 'cve-w-status', 'aria-live': 'polite', title: status.error || undefined }, status.saving ? __( 'Saving…', 'visual-edit-lite' ) : status.error ? __( 'Save failed — changes kept', 'visual-edit-lite' ) : status.dirty ? status.dirty + ' ' + __( 'unsaved', 'visual-edit-lite' ) : '● ' + __( 'Saved', 'visual-edit-lite' ) ),
			status.viewable && wp.editor && wp.editor.PostPreviewButton ? h( wp.editor.PostPreviewButton, { textContent: __( 'Preview', 'visual-edit-lite' ) } ) : h( 'a', { href: status.link || config.homeUrl, target: '_blank', rel: 'noopener' }, __( 'View site', 'visual-edit-lite' ) ),
			h( Menu, { className: 'cve-w-more', label: '⋯', ariaLabel: __( 'More', 'visual-edit-lite' ), title: __( 'More', 'visual-edit-lite' ), align: 'right' }, function ( close ) { return moreItems().map( function ( item ) { return menuItem( item, close ); } ); } ),
			button( __( 'Save', 'visual-edit-lite' ), save, { className: 'cve-w-primary', disabled: status.saving } ) ),
			historyOpen[0] && window.ClaraVEHistory && h( window.ClaraVEHistory.Panel, { docked: true, postId: status.postId, postType: status.postType, title: documentTitle, saving: status.saving, onClose: function () { historyOpen[1]( false ); } } ),
			patterns[0] && h( PatternBrowser, { target: patterns[0], onClose: function () { patterns[1]( null ); } } ),
			panel[0] === 'seo' && window.ClaraVENative && h( c.Modal, { title: __( 'Search appearance', 'visual-edit-lite' ), className: 'cve-w-dialog', onRequestClose: function () { panel[1]( '' ); } },
				// wp_localize_script turns false into '' — test for the key's presence, then its truthiness.
				config.publicSeo !== undefined && ! config.publicSeo && h( 'p', { className: 'cve-w-mode-note' }, __( 'This site does not print search titles and descriptions from Visual Edit: the theme provides its own, or the site-wide SEO & sharing settings are not enabled yet. Values saved here are kept.', 'visual-edit-lite' ), ' ', h( 'a', { href: config.seoSettingsUrl || config.seoUrl, target: '_blank', rel: 'noopener' }, __( 'SEO & sharing settings', 'visual-edit-lite' ) ) ),
				h( window.ClaraVENative.SeoPanel, { postId: status.postId } ) ),
			hint[0] && h( 'div', { className: 'cve-w-hint', role: 'status' }, h( 'span', null, __( 'Click anything on the page to change it.', 'visual-edit-lite' ) ), button( __( 'Got it', 'visual-edit-lite' ), dismissHint ) ),
			fontOpen[0] && h( FontPicker, { onClose: function () { fontOpen[1]( false ); } } ), h( NativeFontSettings ), h( FontsPreview ), h( ExtrasPreview ), h( CanvasOverlay ), h( NativeDark ), h( SelectionBadge ) ) );
	}
	function SiteBrowser( props ) {
		var registry = wp.data.useRegistry();
		var kind = useState( 'page' ); var search = useState( '' ); var page = useState( 1 );
		var types = wp.data.useSelect( function ( select ) { return select( 'core' ).getPostTypes( { per_page: -1 } ) || []; }, [] );
		var query = { per_page: 30, page: page[0], search: search[0], context: 'edit' };
		var result = wp.data.useSelect( function ( select ) {
			var core = select( 'core' ); var args = [ 'postType', kind[0], query ];
			return { records: core.getEntityRecords.apply( core, args ), error: core.getResolutionError ? core.getResolutionError( 'getEntityRecords', args ) : null, finished: core.hasFinishedResolution ? core.hasFinishedResolution( 'getEntityRecords', args ) : false };
		}, [ kind[0], search[0], page[0] ] );
		var records = result.records;
		var labels = { page: __( 'Pages', 'visual-edit-lite' ), post: __( 'Posts', 'visual-edit-lite' ), wp_template: __( 'Templates', 'visual-edit-lite' ), wp_template_part: __( 'Template parts', 'visual-edit-lite' ), wp_navigation: __( 'Navigation', 'visual-edit-lite' ), wp_block: __( 'Synced patterns', 'visual-edit-lite' ) };
		function navigate( url ) {
			if ( props.dirty && ! window.confirm( __( 'There are unsaved changes. Leave this document?', 'visual-edit-lite' ) ) ) { return; }
			var next = new URL( url, window.location.href ); next.searchParams.set( 'clara_ve_workspace', '1' ); next.searchParams.set( 'clara_ve_session', config.session ); window.location.assign( next.toString() );
		}
		return h( Fragment, null,
			// Other post types only when they are public pages of the site: Global Styles or
			// menu items have no list to open, only an error.
			h( Field, { label: __( 'Show', 'visual-edit-lite' ), value: kind[0], options: Object.keys( labels ).map( function ( type ) { return { label: labels[ type ], value: type }; } ).concat( types.filter( function ( type ) { return ! labels[ type.slug ] && type.viewable && type.supports && type.supports.editor && type.slug !== 'attachment'; } ).map( function ( type ) { return { label: type.name, value: type.slug }; } ) ), onChange: function ( value ) { kind[1]( value ); page[1]( 1 ); } } ),
			h( Field, { label: __( 'Search', 'visual-edit-lite' ), value: search[0], placeholder: __( 'Search…', 'visual-edit-lite' ), onChange: function ( value ) { search[1]( value ); page[1]( 1 ); } } ),
			! records && ! result.error && ! result.finished && h( c.Spinner ),
			( result.error || ( result.finished && ! records ) ) && h( c.Notice, { status: 'error', isDismissible: false }, result.error && result.error.message || __( 'Could not load this content.', 'visual-edit-lite' ), button( __( 'Retry', 'visual-edit-lite' ), function () { registry.dispatch( 'core' ).invalidateResolution( 'getEntityRecords', [ 'postType', kind[0], query ] ); } ) ),
			records && ! records.length && h( 'p', null, __( 'No results.', 'visual-edit-lite' ) ),
			records && records.length > 0 && h( 'div', { className: 'cve-w-site-list' }, records.map( function ( record ) {
				return button( ( record.title && ( record.title.raw || wp.htmlEntities.decodeEntities( record.title.rendered || '' ).replace( /<[^>]*>/g, '' ) ) ) || ( record.slug ? record.slug.charAt( 0 ).toUpperCase() + record.slug.slice( 1 ).replace( /-/g, ' ' ) : String( record.id ) ), function () {
					if ( kind[0] === 'wp_template' || kind[0] === 'wp_template_part' || kind[0] === 'wp_navigation' ) {
						var url = new URL( config.siteEditorUrl ); url.searchParams.set( 'postId', record.id ); url.searchParams.set( 'postType', kind[0] ); navigate( url );
					} else { navigate( config.adminUrl + 'post.php?post=' + encodeURIComponent( record.id ) + '&action=edit' ); }
				}, { key: record.id } );
			} ) ),
			( page[0] > 1 || ( records && records.length >= 30 ) ) && button( __( 'Previous', 'visual-edit-lite' ), function () { page[1]( page[0] - 1 ); }, { disabled: page[0] <= 1 } ),
			( page[0] > 1 || ( records && records.length >= 30 ) ) && button( __( 'Next', 'visual-edit-lite' ), function () { page[1]( page[0] + 1 ); }, { disabled: ! records || records.length < 30 } ),
			// WordPress's own site management is a different editor, so it is a
			// plain, labelled way out of the workspace rather than a screen that
			// replaces the workspace inside its frame.
			h( 'a', { className: 'cve-w-exit', href: siteManagementUrl(), target: '_top' }, __( 'Open the WordPress Site Editor', 'visual-edit-lite' ) + ' ↗' ),
			h( 'a', { className: 'cve-w-exit', href: config.adminUrl, target: '_top' }, '← ' + __( 'Back to the dashboard', 'visual-edit-lite' ) ) );
	}
	function siteManagementUrl() { var url = new URL( config.siteEditorUrl, window.location.href ); url.searchParams.delete( 'canvas' ); return url.toString(); }
	/** window.ClaraVE for the workspace: every operation is an ordinary, undoable editor change. */
	function registerApi() {
		if ( ! window.ClaraVE || ! window.ClaraVE.register ) { return; }
		var registry = wp.data;
		function editor() { return registry.select( 'core/block-editor' ); }
		function actions() { return registry.dispatch( 'core/block-editor' ); }
		function permitted( clientId, path, context ) {
			var live = editor().getBlock( clientId ); if ( ! live ) { return false; }
			var mode = editor().getBlockEditingMode ? editor().getBlockEditingMode( clientId ) : 'default';
			return model.canEditAttribute( wp.blocks.getBlockType( live.name ), live.attributes, mode, path, context );
		}
		function update( clientId, paths, context ) {
			var live = editor().getBlock( clientId );
			if ( ! live ) { return 'Block not found.'; }
			var names = Object.keys( paths );
			if ( ! names.length ) { return 'Nothing to change.'; }
			var refused = names.filter( function ( path ) { return /^(metadata|lock)(\.|$)/.test( path ) || ! permitted( clientId, path, context ); } );
			if ( refused.length ) { return 'Not editable here: ' + refused.join( ', ' ); }
			var next = live.attributes;
			names.forEach( function ( path ) { next = model.put( next, path, paths[ path ] ); } );
			actions().updateBlockAttributes( clientId, model.patch( live.attributes, next ) );
			return '';
		}
		function run( op ) {
			if ( ! op || typeof op !== 'object' || typeof op.op !== 'string' ) { return 'Invalid operation.'; }
			var id = op.id || op.clientId || '';
			var live = id ? editor().getBlock( id ) : null;
			var needsBlock = [ 'insert-pattern' ].indexOf( op.op ) < 0;
			if ( needsBlock && ! live ) { return 'Block not found.'; }
			var root = live ? editor().getBlockRootClientId( id ) || '' : '';
			var paths = {};
			switch ( op.op ) {
				case 'set-text':
					if ( TEXT_BLOCKS.indexOf( live.name ) < 0 ) { return 'This block has no editable text.'; }
					paths[ live.name === 'core/button' ? 'text' : 'content' ] = String( op.html !== undefined ? op.html : op.text || '' );
					return update( id, paths );
				case 'set-attrs':
					if ( ! op.attrs || typeof op.attrs !== 'object' || Array.isArray( op.attrs ) ) { return 'attrs must be an object.'; }
					return update( id, op.attrs );
				case 'set-style':
					if ( ! op.style || typeof op.style !== 'object' ) { return 'style must be an object of paths.'; }
					Object.keys( op.style ).forEach( function ( path ) { paths[ 'style.' + path.replace( /^style\./, '' ) ] = op.style[ path ]; } );
					return update( id, paths );
				case 'set-link':
					if ( live.name !== 'core/button' && live.name !== 'core/navigation-link' ) { return 'This block has no link.'; }
					if ( op.href && /^\s*(javascript|data|vbscript):/i.test( op.href ) ) { return 'Unsafe link.'; }
					if ( op.href !== undefined ) { paths.url = op.href; }
					if ( op.target !== undefined ) { paths[ live.name === 'core/navigation-link' ? 'opensInNewTab' : 'linkTarget' ] = live.name === 'core/navigation-link' ? op.target === '_blank' : op.target; }
					return update( id, paths );
				case 'set-image':
					if ( [ 'core/image', 'core/cover', 'core/video', 'core/audio' ].indexOf( live.name ) < 0 ) { return 'This block has no media.'; }
					if ( typeof op.url !== 'string' || ! op.url ) { return 'url is required.'; }
					paths[ live.name === 'core/video' || live.name === 'core/audio' ? 'src' : 'url' ] = op.url;
					paths.id = op.attachmentId;
					if ( op.alt !== undefined && ( live.name === 'core/image' || live.name === 'core/cover' ) ) { paths.alt = op.alt; }
					if ( live.name === 'core/cover' ) { paths.backgroundType = op.mediaType === 'video' ? 'video' : 'image'; }
					return update( id, paths, 'media' );
				case 'set-responsive':
					if ( [ 'tablet', 'mobile' ].indexOf( op.breakpoint ) < 0 || ! model.responsiveProperties[ op.path ] ) { return 'Unsupported responsive value.'; }
					var screens = model.copy( at( live.attributes, 'claraVe.responsive' ) ) || {};
					screens[ op.breakpoint ] = screens[ op.breakpoint ] || {};
					if ( op.value ) { screens[ op.breakpoint ][ op.path ] = String( op.value ); } else { delete screens[ op.breakpoint ][ op.path ]; }
					return update( id, { 'claraVe.responsive': screens } );
				case 'set-ornament':
					if ( [ 'before', 'after' ].indexOf( op.pseudo ) < 0 || ! op.props || typeof op.props !== 'object' ) { return 'pseudo must be before or after, with props.'; }
					var ornament = Object.assign( {}, at( live.attributes, 'claraVe.ornaments.' + op.pseudo ) );
					[ 'content', 'color', 'font-size', 'font-family', 'font-weight', 'line-height' ].forEach( function ( key ) { if ( op.props[ key ] !== undefined ) { if ( op.props[ key ] === '' || op.props[ key ] === null ) { delete ornament[ key ]; } else { ornament[ key ] = String( op.props[ key ] ); } } } );
					paths[ 'claraVe.ornaments.' + op.pseudo ] = Object.keys( ornament ).length ? ornament : undefined;
					return update( id, paths );
				case 'set-motion':
					if ( op.entrance && ENTRANCES.indexOf( op.entrance ) < 0 ) { return 'Unknown entrance: ' + op.entrance; }
					if ( op.hover && HOVERS.indexOf( op.hover ) < 0 ) { return 'Unknown hover: ' + op.hover; }
					var tokens = String( live.attributes.className || '' ).split( /\s+/ ).filter( Boolean );
					[ [ 'entrance', 'cve-anim-' ], [ 'hover', 'cve-hover-' ] ].forEach( function ( item ) {
						if ( op[ item[0] ] === undefined ) { return; }
						tokens = tokens.filter( function ( token ) { return token.indexOf( item[1] ) !== 0; } );
						if ( op[ item[0] ] ) { tokens.push( item[1] + op[ item[0] ] ); }
					} );
					return update( id, { className: tokens.join( ' ' ) || undefined } );
				case 'remove':
					if ( ! editor().canRemoveBlock( id ) ) { return 'This block cannot be removed.'; }
					actions().removeBlocks( [ id ], false ); return '';
				case 'duplicate':
					if ( ! editor().canInsertBlockType( live.name, root ) ) { return 'This block cannot be duplicated here.'; }
					actions().duplicateBlocks( [ id ], false ); return '';
				case 'move':
					if ( ! editor().canMoveBlock( id, root ) ) { return 'This block cannot be moved.'; }
					if ( op.direction === 'up' ) { actions().moveBlocksUp( [ id ], root || undefined ); return ''; }
					if ( op.direction === 'down' ) { actions().moveBlocksDown( [ id ], root || undefined ); return ''; }
					return 'direction must be up or down.';
				case 'insert-pattern':
					var target = live ? { rootClientId: root, index: editor().getBlockIndex( id ) + ( op.position === 'before' ? 0 : 1 ) } : insertionTarget( registry.select );
					var pattern = themePatterns( editor(), target.rootClientId ).find( function ( item ) { return item.name === op.pattern; } );
					if ( ! pattern ) { return 'Unknown theme pattern: ' + op.pattern; }
					return insertPattern( registry, pattern, target ) ? '' : 'The pattern is empty.';
				default:
					return 'Unknown operation: ' + op.op;
			}
		}
		function selection() {
			var id = editor().getSelectedBlockClientId(); var live = id ? editor().getBlock( id ) : null;
			if ( ! live ) { return null; }
			var blockType = wp.blocks.getBlockType( live.name );
			var parents = editor().getBlockParents( id ).map( function ( parent ) { var name = editor().getBlockName( parent ); var meta = ( editor().getBlockAttributes( parent ) || {} ).metadata || {}; var parentType = wp.blocks.getBlockType( name ); return { id: parent, name: name, title: meta.name || ( parentType ? parentType.title : name ) }; } );
			var section = parents.slice().reverse().find( function ( parent ) { return parent.title && ( editor().getBlockAttributes( parent.id ) || {} ).metadata; } );
			return { id: id, name: live.name, title: blockType ? blockType.title : live.name, attributes: model.copy( live.attributes ), editingMode: editor().getBlockEditingMode ? editor().getBlockEditingMode( id ) : 'default', parents: parents, sectionName: section ? section.title : '' };
		}
		function documentEntity() {
			var current = registry.select( 'core/editor' );
			return { type: current.getCurrentPostType(), id: current.getCurrentPostId() };
		}
		window.ClaraVE.register( {
			mode: 'block',
			getSelection: selection,
			select: function ( id ) { if ( ! editor().getBlock( id ) ) { return false; } lastPointer = null; actions().selectBlock( id ); return true; },
			openPopup: function () { window.dispatchEvent( new CustomEvent( 'clara-ve-open-popup' ) ); return true; },
			closePopup: function () { window.dispatchEvent( new CustomEvent( 'clara-ve-close-popup' ) ); return true; },
			apply: function ( ops ) {
				var result = { applied: [], refused: [] };
				ops.forEach( function ( op, index ) {
					var reason;
					try { reason = run( op ); } catch ( error ) { reason = error.message || String( error ); }
					if ( reason ) { result.refused.push( { index: index, op: op && op.op, reason: reason } ); } else { result.applied.push( index ); }
				} );
				emit( 'apply', result );
				return result;
			},
			getDocument: function () {
				var entity = documentEntity(); var current = registry.select( 'core/editor' );
				var titleValue = current.getEditedPostAttribute ? current.getEditedPostAttribute( 'title' ) : '';
				return { mode: 'block', type: entity.type, id: entity.id, title: titleValue && typeof titleValue === 'object' ? titleValue.raw : titleValue, content: wp.blocks.serialize( editor().getBlocks() ) };
			},
			historyList: function () { var entity = documentEntity(); return wp.apiFetch( { path: '/clara-ve/v1/native/history?type=' + encodeURIComponent( entity.type ) + '&entity=' + encodeURIComponent( entity.id ) } ); },
			historyRestore: function ( versionId ) {
				var entity = documentEntity();
				return wp.apiFetch( { path: '/clara-ve/v1/native/history/' + encodeURIComponent( versionId ) + '?type=' + encodeURIComponent( entity.type ) + '&entity=' + encodeURIComponent( entity.id ) } ).then( function ( snapshot ) {
					return window.ClaraVEHistory.stage( registry, entity, snapshot ).then( function () { emit( 'restore', { entity: entity, id: versionId } ); return true; } );
				} );
			}
		} );
	}
	wp.plugins.registerPlugin( 'clara-ve-workspace', { render: Workspace, icon: 'edit' } );
	registerApi();
	// Pure helpers, exposed for tests and for extensions that position their own UI.
	window.ClaraVEWorkspace = { placePopup: placePopup, darkSweep: darkSweep };
}( window.wp, window.claraVeGutenberg, window.ClaraVEModel ) );
