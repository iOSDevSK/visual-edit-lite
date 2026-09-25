/**
 * Editable form blocks: a form, its fields, rows and the send button.
 *
 * Every block saves plain HTML form markup, so a page keeps a visible form without the
 * plugin; Clara_VE_Form_Blocks::render_form() connects it to the submission backend.
 * The canvas shows the same markup the site gets (theme styles apply) with the
 * controls inert, so a click selects a field instead of typing into it.
 *
 * ClaraVEFormBlocks.fromHtml() turns an existing form's markup — a theme shortcode's
 * output, an HTML block — into these blocks, keeping its class names so it keeps its look.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) { return; }
	var h = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor;
	var c = wp.components;
	var __ = wp.i18n.__;
	var FIELD_TYPES = [ 'text', 'email', 'tel', 'url', 'number', 'date' ];

	function slug( value ) { return String( value || '' ).toLowerCase().replace( /<[^>]*>/g, '' ).replace( /[^a-z0-9_-]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ); }
	function nameOf( a ) { return slug( a.name ) || slug( a.label ) || 'field'; }
	function idOf( a ) { return a.inputId || 'cve-' + nameOf( a ); }
	function cls( value ) { return value ? value : undefined; }

	var textAttributes = {
		name: { type: 'string', default: '' }, inputId: { type: 'string', default: '' },
		label: { type: 'string', default: '' }, labelClass: { type: 'string', default: '' },
		hint: { type: 'string', default: '' }, hintClass: { type: 'string', default: '' },
		placeholder: { type: 'string', default: '' }, required: { type: 'boolean', default: false },
		wrapperClass: { type: 'string', default: 'field' }, inline: { type: 'boolean', default: false }
	};
	function withAttributes( extra ) { return Object.assign( {}, textAttributes, extra || {} ); }

	function labelElement( a, forEditor ) {
		if ( ! a.label && ! a.hint ) { return null; }
		return h( 'label', { htmlFor: forEditor ? undefined : idOf( a ), className: cls( a.labelClass ) }, a.label, a.hint ? ' ' : null, a.hint ? h( 'span', { className: cls( a.hintClass ) }, a.hint ) : null );
	}
	function inert( forEditor ) { return forEditor ? { tabIndex: -1, readOnly: true, style: { pointerEvents: 'none' }, onFocus: function ( event ) { event.target.blur(); } } : {}; }
	function control( kind, a, forEditor ) {
		var base = Object.assign( { id: forEditor ? undefined : idOf( a ), name: nameOf( a ), required: a.required || undefined }, inert( forEditor ) );
		if ( kind === 'textarea' ) { return h( 'textarea', Object.assign( base, { placeholder: cls( a.placeholder ), rows: a.rows > 0 ? a.rows : undefined } ) ); }
		if ( kind === 'select' ) { return h( 'select', base, ( a.options || [] ).map( function ( option, index ) { return h( 'option', { key: index }, option ); } ) ); }
		if ( kind === 'checkbox' ) { return h( 'input', Object.assign( base, { type: 'checkbox', value: 'yes' } ) ); }
		return h( 'input', Object.assign( base, { type: FIELD_TYPES.indexOf( a.type ) >= 0 ? a.type : 'text', placeholder: cls( a.placeholder ) } ) );
	}
	function fieldMarkup( kind, a, wrapperProps, forEditor ) {
		var label = labelElement( a, forEditor ); var input = control( kind, a, forEditor );
		return h( 'div', wrapperProps, kind === 'checkbox' ? [ h( Fragment, { key: 'i' }, input ), h( Fragment, { key: 'l' }, label ) ] : [ h( Fragment, { key: 'l' }, label ), h( Fragment, { key: 'i' }, input ) ] );
	}

	function FieldSettings( props ) {
		var a = props.attributes; var set = props.setAttributes; var kind = props.kind;
		return h( be.InspectorControls, null, h( c.PanelBody, { title: __( 'Field', 'visual-edit-lite' ) },
			h( c.TextControl, { label: __( 'Label', 'visual-edit-lite' ), value: a.label, onChange: function ( value ) { set( { label: value } ); } } ),
			kind !== 'select' && kind !== 'checkbox' && h( c.TextControl, { label: __( 'Placeholder', 'visual-edit-lite' ), value: a.placeholder, onChange: function ( value ) { set( { placeholder: value } ); } } ),
			kind === 'field' && h( c.SelectControl, { label: __( 'Type', 'visual-edit-lite' ), value: a.type, options: FIELD_TYPES.map( function ( type ) { return { label: type, value: type }; } ), onChange: function ( value ) { set( { type: value } ); } } ),
			kind === 'select' && h( c.TextareaControl, { label: __( 'Choices (one per line)', 'visual-edit-lite' ), value: ( a.options || [] ).join( '\n' ), onChange: function ( value ) { set( { options: value.split( '\n' ).map( function ( line ) { return line.trim(); } ).filter( Boolean ) } ); } } ),
			h( c.ToggleControl, { label: __( 'Required', 'visual-edit-lite' ), checked: !! a.required, onChange: function ( value ) { set( { required: value } ); } } ),
			h( c.TextControl, { label: __( 'Name in submissions', 'visual-edit-lite' ), help: nameOf( a ), value: a.name, onChange: function ( value ) { set( { name: slug( value ) } ); } } )
		) );
	}

	var FIELD_BLOCKS = [ 'clara-ve/field', 'clara-ve/textarea', 'clara-ve/select', 'clara-ve/checkbox' ];
	function uniqueName( base, taken ) {
		if ( taken.indexOf( base ) < 0 ) { return base; }
		var stem = base.replace( /-\d+$/, '' ) || base; var n = 2; var name = stem + '-' + n;
		while ( taken.indexOf( name ) >= 0 ) { n += 1; name = stem + '-' + n; }
		return name;
	}
	/**
	 * Two controls with one name lose a value on send, so a field pasted or duplicated
	 * with a key an earlier field of the same form already uses gets the next free one.
	 * Fields that do not collide are left untouched, so opening a page never dirties it.
	 */
	function useUniqueName( props ) {
		var a = props.attributes; var clientId = props.clientId;
		var names = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/block-editor' );
			var form = ( editor.getBlockParentsByBlockName ? editor.getBlockParentsByBlockName( clientId, 'clara-ve/form', true ) : [] )[0];
			if ( ! form ) { return '{"before":[],"others":[]}'; }
			var before = []; var others = []; var seen = false;
			( editor.getClientIdsOfDescendants( [ form ] ) || [] ).forEach( function ( id ) {
				if ( id === clientId ) { seen = true; return; }
				if ( FIELD_BLOCKS.indexOf( editor.getBlockName( id ) ) < 0 ) { return; }
				var key = nameOf( editor.getBlockAttributes( id ) || {} );
				others.push( key ); if ( ! seen ) { before.push( key ); }
			} );
			return JSON.stringify( { before: before, others: others } );
		}, [ clientId ] );
		wp.element.useEffect( function () {
			var taken = JSON.parse( names );
			var current = nameOf( a );
			if ( taken.before.indexOf( current ) < 0 ) { return; }
			var next = uniqueName( current, taken.others );
			if ( next === a.name ) { return; }
			var actions = wp.data.dispatch( 'core/block-editor' );
			if ( actions.__unstableMarkNextChangeAsNotPersistent ) { actions.__unstableMarkNextChangeAsNotPersistent(); }
			props.setAttributes( { name: next } );
		}, [ names, a.name ] );
	}

	/*
	 * An inline field is saved with display: contents, so its label and control are laid out by
	 * the form itself (".signup input { flex: 1 1 220px }" sizes the input across a row). The editor
	 * needs a real box to click, outline and label, so it uses a wrapping row that lets the
	 * control's own flex rules act on its width — never a column, where that basis became a height.
	 */
	var INLINE_EDITOR_STYLE = { display: 'flex', flexWrap: 'wrap', alignItems: 'stretch', flex: '1 1 220px', minWidth: 0, gap: 'inherit' };

	// The name and the title are written out at each registerBlockType() call
	// below, not passed in: the directory's block scanner reads the call as
	// text, and a title held in a variable came out as "Clara Ve Field" on the
	// plugin's page. The settings shared by the four fields live here.
	function fieldSettings( kind, title, extra ) {
		return {
			apiVersion: 3, category: 'widgets', icon: 'feedback', ancestor: [ 'clara-ve/form' ],
			attributes: withAttributes( extra ),
			supports: { html: false, customClassName: false, reusable: false },
			__experimentalLabel: function ( a ) { return a.label || title; },
			edit: function ( props ) {
				var a = props.attributes;
				useUniqueName( props );
				var blockProps = be.useBlockProps( { className: cls( a.wrapperClass ), style: a.inline ? INLINE_EDITOR_STYLE : undefined } );
				return h( Fragment, null, h( FieldSettings, { attributes: a, setAttributes: props.setAttributes, kind: kind } ), fieldMarkup( kind, a, blockProps, true ) );
			},
			save: function ( props ) {
				var a = props.attributes;
				return fieldMarkup( kind, a, be.useBlockProps.save( { className: cls( a.wrapperClass ), style: a.inline ? { display: 'contents' } : undefined } ), false );
			}
		};
	}
	wp.blocks.registerBlockType( 'clara-ve/field', Object.assign( { title: __( 'Form field', 'visual-edit-lite' ) }, fieldSettings( 'field', __( 'Form field', 'visual-edit-lite' ), { type: { type: 'string', default: 'text' } } ) ) );
	wp.blocks.registerBlockType( 'clara-ve/textarea', Object.assign( { title: __( 'Form text area', 'visual-edit-lite' ) }, fieldSettings( 'textarea', __( 'Form text area', 'visual-edit-lite' ), { rows: { type: 'number', default: 0 } } ) ) );
	wp.blocks.registerBlockType( 'clara-ve/select', Object.assign( { title: __( 'Form choice list', 'visual-edit-lite' ) }, fieldSettings( 'select', __( 'Form choice list', 'visual-edit-lite' ), { options: { type: 'array', default: [] } } ) ) );
	wp.blocks.registerBlockType( 'clara-ve/checkbox', Object.assign( { title: __( 'Form checkbox', 'visual-edit-lite' ) }, fieldSettings( 'checkbox', __( 'Form checkbox', 'visual-edit-lite' ) ) ) );

	wp.blocks.registerBlockType( 'clara-ve/form-group', {
		apiVersion: 3, title: __( 'Form row', 'visual-edit-lite' ), category: 'widgets', icon: 'columns', ancestor: [ 'clara-ve/form' ],
		attributes: { groupClass: { type: 'string', default: '' } },
		supports: { html: false, customClassName: false, reusable: false },
		edit: function ( props ) {
			var inner = be.useInnerBlocksProps( be.useBlockProps( { className: cls( props.attributes.groupClass ) } ), { templateLock: false } );
			return h( Fragment, null,
				h( be.InspectorControls, null, h( c.PanelBody, { title: __( 'Form row', 'visual-edit-lite' ) }, h( c.TextControl, { label: __( 'CSS class', 'visual-edit-lite' ), value: props.attributes.groupClass, onChange: function ( value ) { props.setAttributes( { groupClass: value } ); } } ) ) ),
				h( 'div', inner ) );
		},
		save: function ( props ) { return h( 'div', be.useInnerBlocksProps.save( be.useBlockProps.save( { className: cls( props.attributes.groupClass ) } ) ) ); }
	} );

	wp.blocks.registerBlockType( 'clara-ve/submit', {
		apiVersion: 3, title: __( 'Form send button', 'visual-edit-lite' ), category: 'widgets', icon: 'button', ancestor: [ 'clara-ve/form' ],
		attributes: { text: { type: 'string', default: '' }, buttonClass: { type: 'string', default: '' } },
		supports: { html: false, customClassName: false, className: false, reusable: false },
		__experimentalLabel: function ( a ) { return a.text || __( 'Send', 'visual-edit-lite' ); },
		edit: function ( props ) {
			var a = props.attributes;
			return h( Fragment, null,
				h( be.InspectorControls, null, h( c.PanelBody, { title: __( 'Button', 'visual-edit-lite' ) }, h( c.TextControl, { label: __( 'Button text', 'visual-edit-lite' ), value: a.text, onChange: function ( value ) { props.setAttributes( { text: value } ); } } ) ) ),
				h( 'button', be.useBlockProps( { type: 'submit', tabIndex: -1, className: cls( a.buttonClass ), onClick: function ( event ) { event.preventDefault(); } } ), a.text || __( 'Send', 'visual-edit-lite' ) ) );
		},
		save: function ( props ) { return h( 'button', be.useBlockProps.save( { type: 'submit', className: cls( props.attributes.buttonClass ) } ), props.attributes.text || __( 'Send', 'visual-edit-lite' ) ); }
	} );

	var TEMPLATE = [
		[ 'clara-ve/field', { label: __( 'Name', 'visual-edit-lite' ), required: true } ],
		[ 'clara-ve/field', { label: __( 'Email', 'visual-edit-lite' ), type: 'email', required: true } ],
		[ 'clara-ve/textarea', { label: __( 'Message', 'visual-edit-lite' ) } ],
		[ 'clara-ve/submit', { text: __( 'Send', 'visual-edit-lite' ) } ]
	];

	wp.blocks.registerBlockType( 'clara-ve/form', {
		apiVersion: 3, title: __( 'Form', 'visual-edit-lite' ), category: 'widgets', icon: 'feedback',
		description: __( 'A form whose fields, texts and button are edited like the rest of the page. Submissions go to Form Submissions and are emailed to the Form Settings address.', 'visual-edit-lite' ),
		attributes: { formId: { type: 'string', default: '' }, formClass: { type: 'string', default: '' }, wrapperClass: { type: 'string', default: '' }, redirect: { type: 'string', default: '' }, message: { type: 'string', default: '' }, formType: { type: 'string', default: 'contact' }, listId: { type: 'string', default: '' }, recipient: { type: 'string', default: '' } },
		supports: { html: false, customClassName: true, reusable: false },
		edit: function ( props ) {
			var a = props.attributes; var set = props.setAttributes;
			wp.element.useEffect( function () { if ( ! a.formId ) { set( { formId: 'form-' + Math.random().toString( 36 ).slice( 2, 8 ) } ); } }, [] );
			var options = { template: TEMPLATE, templateLock: false };
			// What the editor may offer depends on who is answering the form.
			// A theme that registers these blocks on its own has one way of
			// sending and no mailing lists, and it says so by setting
			// `lists: false` — a control that changes nothing is worse than no
			// control at all.
			var delivery = window.claraVeFormBlocks || null;
			var lists = !! ( delivery && false !== delivery.lists );
			var settings = h( be.InspectorControls, null, h( c.PanelBody, { title: __( 'Form', 'visual-edit-lite' ) },
				lists && h( c.SelectControl, { label: __( 'Does', 'visual-edit-lite' ), value: 'list' === a.formType ? 'list' : 'contact', options: [ { label: __( 'Contact form', 'visual-edit-lite' ), value: 'contact' }, { label: __( 'Mailing list', 'visual-edit-lite' ), value: 'list' } ], onChange: function ( value ) { set( { formType: value } ); } } ),
				lists && 'list' === a.formType && h( c.TextControl, { label: __( 'List', 'visual-edit-lite' ), help: __( 'The list id at your provider. The Visual Edit popup offers the names instead.', 'visual-edit-lite' ), value: a.listId, onChange: function ( value ) { set( { listId: value.trim() } ); } } ),
				delivery && 'list' !== a.formType && h( c.TextControl, { label: __( 'Send to', 'visual-edit-lite' ), help: __( 'Leave empty for the address in Form Settings.', 'visual-edit-lite' ), placeholder: delivery.recipient || '', value: a.recipient, onChange: function ( value ) { set( { recipient: value.trim() } ); } } ),
				h( c.TextControl, { label: __( 'After sending, go to', 'visual-edit-lite' ), help: __( 'A page address such as /thank-you/. Empty: stay on the page and show the message.', 'visual-edit-lite' ), value: a.redirect, onChange: function ( value ) { set( { redirect: value } ); } } ),
				h( c.TextControl, { label: __( 'Message after sending', 'visual-edit-lite' ), value: a.message, onChange: function ( value ) { set( { message: value } ); } } ) ) );
			var formProps = { className: cls( a.formClass ), onSubmit: function ( event ) { event.preventDefault(); } };
			if ( a.wrapperClass ) {
				return h( Fragment, null, settings, h( 'div', be.useBlockProps( { className: a.wrapperClass } ), h( 'form', be.useInnerBlocksProps( formProps, options ) ) ) );
			}
			return h( Fragment, null, settings, h( 'form', be.useInnerBlocksProps( be.useBlockProps( formProps ), options ) ) );
		},
		save: function ( props ) {
			var a = props.attributes;
			if ( a.wrapperClass ) { return h( 'div', be.useBlockProps.save( { className: a.wrapperClass } ), h( 'form', be.useInnerBlocksProps.save( { className: cls( a.formClass ) } ) ) ); }
			return h( 'form', be.useInnerBlocksProps.save( be.useBlockProps.save( { className: cls( a.formClass ) } ) ) );
		}
	} );

	/* ---------------------------------------------------------------- conversion */

	var CONTROL = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea';
	function isControl( el ) { return el.matches( CONTROL ); }
	function isSubmit( el ) { return el.matches( 'button:not([type="button"]):not([type="reset"]), input[type="submit"]' ); }
	function labelFor( scope, control ) { return control.id ? scope.querySelector( 'label[for="' + control.id.replace( /"/g, '' ) + '"]' ) : null; }

	function fieldFrom( create, control, label, placement ) {
		var tag = control.tagName.toLowerCase();
		var kind = tag === 'select' ? 'select' : tag === 'textarea' ? 'textarea' : control.type === 'checkbox' ? 'checkbox' : 'field';
		var text = '', hint = '', hintClass = '';
		if ( label ) {
			var copy = label.cloneNode( true );
			Array.prototype.forEach.call( copy.querySelectorAll( 'input, select, textarea' ), function ( node ) { node.parentNode.removeChild( node ); } );
			var last = copy.lastElementChild;
			if ( last && /^(span|small|em)$/i.test( last.tagName ) && copy.textContent.trim() !== last.textContent.trim() ) { hint = last.textContent.trim(); hintClass = last.getAttribute( 'class' ) || ''; copy.removeChild( last ); }
			text = copy.textContent.replace( /\s+/g, ' ' ).trim();
		}
		var attrs = { name: control.getAttribute( 'name' ) || slug( text ), inputId: control.id || '', label: text, labelClass: label ? label.getAttribute( 'class' ) || '' : '', hint: hint, hintClass: hintClass, required: control.hasAttribute( 'required' ), wrapperClass: placement.wrapperClass, inline: placement.inline };
		if ( kind === 'field' ) { attrs.type = FIELD_TYPES.indexOf( control.type ) >= 0 ? control.type : 'text'; }
		if ( kind === 'field' || kind === 'textarea' ) { attrs.placeholder = control.getAttribute( 'placeholder' ) || ''; }
		if ( kind === 'textarea' ) { attrs.rows = parseInt( control.getAttribute( 'rows' ), 10 ) || 0; }
		if ( kind === 'select' ) { attrs.options = Array.prototype.map.call( control.options, function ( option ) { return option.textContent.trim(); } ); }
		return create( kind === 'field' ? 'clara-ve/field' : 'clara-ve/' + kind, attrs );
	}

	function convertChildren( create, parent, form ) {
		var out = []; var used = [];
		Array.prototype.forEach.call( parent.children, function ( el ) {
			if ( used.indexOf( el ) >= 0 || /^(script|style|template|noscript)$/i.test( el.tagName ) || ( el.matches( 'input[type="hidden"]' ) ) ) { return; }
			var controls = el.querySelectorAll( CONTROL ); var submits = el.querySelectorAll( 'button:not([type="button"]):not([type="reset"]), input[type="submit"]' );
			if ( el.querySelector( 'input[type="radio"]' ) || el.matches( 'input[type="radio"]' ) ) { out.push( create( 'core/html', { content: el.outerHTML } ) ); return; }
			// A theme's own "sent" sentence: hidden until its script shows it. It becomes the form's message.
			if ( ! controls.length && ! submits.length && /(^|[\s_-])(msg|message|response|success|thanks)([\s_-]|$)/i.test( el.getAttribute( 'class' ) || '' ) ) { form.message = form.message || el.textContent.replace( /\s+/g, ' ' ).trim(); return; }
			if ( el.tagName === 'LABEL' && ! controls.length ) {
				var target = Array.prototype.find.call( parent.children, function ( sibling ) { return sibling !== el && sibling.id && sibling.id === el.getAttribute( 'for' ) && isControl( sibling ); } ) || ( el.nextElementSibling && isControl( el.nextElementSibling ) ? el.nextElementSibling : null );
				if ( target ) { used.push( target ); out.push( fieldFrom( create, target, el, { inline: true, wrapperClass: '' } ) ); return; }
			}
			if ( isControl( el ) ) { var own = labelFor( parent, el ); if ( own ) { used.push( own ); } out.push( fieldFrom( create, el, own, { inline: true, wrapperClass: '' } ) ); return; }
			if ( el.tagName === 'LABEL' && controls.length === 1 ) { out.push( fieldFrom( create, controls[0], el, { inline: true, wrapperClass: '' } ) ); return; }
			if ( isSubmit( el ) ) { out.push( create( 'clara-ve/submit', { text: el.tagName === 'INPUT' ? el.value : el.textContent.replace( /\s+/g, ' ' ).trim(), buttonClass: el.getAttribute( 'class' ) || '' } ) ); return; }
			if ( controls.length === 1 && ! submits.length && el.querySelectorAll( 'label' ).length <= 1 ) {
				out.push( fieldFrom( create, controls[0], el.querySelector( 'label' ) || labelFor( el, controls[0] ), { inline: false, wrapperClass: el.getAttribute( 'class' ) || '' } ) ); return;
			}
			if ( controls.length || submits.length ) { out.push( create( 'clara-ve/form-group', { groupClass: el.getAttribute( 'class' ) || '' }, convertChildren( create, el, form ) ) ); return; }
			if ( ! el.textContent.trim() ) { return; }
			out.push( el.tagName === 'P' ? create( 'core/paragraph', { content: el.innerHTML.trim(), className: el.getAttribute( 'class' ) || undefined } ) : create( 'core/html', { content: el.outerHTML } ) );
		} );
		return out;
	}

	/**
	 * @param {string}   html   Markup holding a <form>.
	 * @param {Function} create ( name, attributes, innerBlocks ) → block; wp.blocks.createBlock in the editor.
	 * @param {Object}   extra  Attributes for the form block (formId, claraVe…).
	 * @return {Object|null} The form block, or null when the markup holds no form.
	 */
	function fromHtml( html, create, extra ) {
		var doc = new window.DOMParser().parseFromString( '<!doctype html><body>' + String( html || '' ) + '</body>', 'text/html' );
		var form = doc.querySelector( 'form' );
		if ( ! form ) { return null; }
		var host = form.parentElement; var hostIsWrapper = host && host !== doc.body && host.parentElement === doc.body && host.children.length === 1;
		var redirectHost = form.closest( '[data-redirect]' ); var redirect = redirectHost ? redirectHost.getAttribute( 'data-redirect' ) || '' : '';
		if ( redirect ) { try { var url = new window.URL( redirect, window.location.href ); if ( url.origin === window.location.origin ) { redirect = url.pathname + url.search + url.hash; } } catch ( error ) { redirect = ''; } }
		var attrs = Object.assign( { formId: '', formClass: form.getAttribute( 'class' ) || '', wrapperClass: hostIsWrapper ? host.getAttribute( 'class' ) || '' : '', redirect: redirect, message: '' }, extra || {} );
		var inner = convertChildren( create, form, attrs );
		return create( 'clara-ve/form', attrs, inner );
	}

	window.ClaraVEFormBlocks = { fromHtml: fromHtml, nameOf: nameOf, idOf: idOf, uniqueName: uniqueName };
}( window.wp ) );
