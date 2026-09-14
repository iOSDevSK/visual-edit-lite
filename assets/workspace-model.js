/** Pure, shared operations. No editor state or DOM ownership in controls. */
( function ( root ) {
	'use strict';
	function copy( value ) { return value === undefined ? undefined : JSON.parse( JSON.stringify( value ) ); }
	function at( value, path ) { return path.split( '.' ).reduce( function ( node, key ) { return node == null ? undefined : node[ key ]; }, value ); }
	function put( value, path, next ) {
		var result = copy( value ) || {};
		var keys = path.split( '.' );
		function visit( node, index ) {
			var key = keys[ index ];
			if ( index === keys.length - 1 ) {
				if ( next === undefined || next === '' ) { delete node[ key ]; } else { node[ key ] = copy( next ); }
			} else {
				node[ key ] = node[ key ] && typeof node[ key ] === 'object' && ! Array.isArray( node[ key ] ) ? node[ key ] : {};
				visit( node[ key ], index + 1 );
				if ( ! Object.keys( node[ key ] ).length ) { delete node[ key ]; }
			}
		}
		visit( result, 0 );
		return result;
	}
	function equal( a, b ) { return JSON.stringify( a ) === JSON.stringify( b ); }
	function leaves( before, after, path, visit ) {
		var a = before && typeof before === 'object' && ! Array.isArray( before );
		var b = after && typeof after === 'object' && ! Array.isArray( after );
		if ( ( a || before === undefined ) && ( b || after === undefined ) && ( a || b ) ) {
			Array.from( new Set( Object.keys( before || {} ).concat( Object.keys( after || {} ) ) ) ).forEach( function ( key ) {
				leaves( a ? before[ key ] : undefined, b ? after[ key ] : undefined, path + '.' + key, visit );
			} );
		} else if ( ! equal( before, after ) ) { visit( path, after ); }
	}
	/** Revert only leaves still equal to what this panel wrote. */
	function revert( attributes, edits ) {
		return Object.keys( edits ).reduce( function ( next, path ) {
			return equal( at( next, path ), edits[ path ].after ) ? put( next, path, edits[ path ].before ) : next;
		}, copy( attributes ) );
	}
	function patch( before, after ) {
		var result = {};
		Array.from( new Set( Object.keys( before ).concat( Object.keys( after ) ) ) ).forEach( function ( key ) {
			if ( ! equal( before[ key ], after[ key ] ) ) { result[ key ] = after[ key ]; }
		} );
		return result;
	}
	/** Content-only is not read-only. Use the registered roles, including older WP. */
	function canEditAttribute( type, attributes, mode, path, context ) {
		if ( ! type || ( mode !== 'default' && mode !== 'contentOnly' ) ) { return false; }
		var name = path.split( '.' )[0];
		var bindings = at( attributes, 'metadata.bindings' ) || {};
		var schema = ( type.attributes || {} )[name] || {};
		var content = schema.role === 'content' || schema.__experimentalRole === 'content';
		if ( bindings[name] || ( bindings.__default && content ) ) { return false; }
		if ( mode === 'default' || content ) { return true; }
		// Cover's URL has a content role in newer WP, but its attachment ID,
		// alt and image/video discriminator are coupled fields without roles.
		// Allow only this media transaction, never arbitrary non-content writes.
		return context === 'media' && type.name === 'core/cover'
			&& [ 'id', 'alt', 'backgroundType' ].indexOf( name ) >= 0
			&& path === name && ! attributes.useFeaturedImage
			&& canEditAttribute( type, attributes, mode, 'url' );
	}
	function cssValue( value ) {
		return String( value || '' ).replace( /^var:preset\|([a-z-]+)\|([a-z0-9-]+)$/, 'var(--wp--preset--$1--$2)' );
	}
	/** Only replace the saved theme layer; leave unsaved custom font-library edits alone. */
	function fontSettings( settings, families ) {
		if ( ! families || ! Object.prototype.hasOwnProperty.call( families, 'theme' ) ) { return settings; }
		return put( settings, '__experimentalFeatures.typography.fontFamilies.theme', families.theme );
	}
	var responsiveProperties = {
		'spacing.padding.top': 'padding-top', 'spacing.padding.right': 'padding-right',
		'spacing.padding.bottom': 'padding-bottom', 'spacing.padding.left': 'padding-left',
		'spacing.margin.top': 'margin-top', 'spacing.margin.bottom': 'margin-bottom',
		'typography.fontSize': 'font-size', 'typography.textAlign': 'text-align',
		'dimensions.minHeight': 'min-height', display: 'display'
	};
	function responsiveCss( selector, screens ) {
		var css = '';
		Object.keys( { tablet: 781, mobile: 600 } ).forEach( function ( screen ) {
			var declarations = '';
			Object.keys( screens[ screen ] || {} ).forEach( function ( path ) {
				var value = cssValue( screens[ screen ][ path ] );
				if ( ! responsiveProperties[ path ] || ! value || /[{}<>;]|url\s*\(|expression\s*\(|@import/i.test( value ) ) { return; }
				declarations += responsiveProperties[ path ] + ':' + value + ' !important;';
			} );
			if ( declarations ) { css += '@media(max-width:' + ( screen === 'mobile' ? 600 : 781 ) + 'px){' + selector + '{' + declarations + '}}'; }
		} );
		return css;
	}
	/**
	 * Form styling, written against labels, fields and buttons rather than any theme's or
	 * plugin's class names. Identical to Clara_VE_Block_Extras::form_css() — see
	 * tests/form-css-congruence.mjs.
	 */
	var FORM_FIELD = 'input:not([type="submit"],[type="button"],[type="reset"],[type="checkbox"],[type="radio"],[type="file"],[type="hidden"],[type="range"],[type="image"],[type="color"])';
	var FORM_BUTTON = 'button[type="submit"], button:not([type]), input[type="submit"]';
	var formTargets = [
		[ 'label', ':is(label, legend)', [ 'color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-transform' ] ],
		[ 'field', ':is(' + FORM_FIELD + ', textarea, select)', [ 'color', 'background-color', 'font-family', 'font-size', 'border', 'border-color', 'border-width', 'border-radius' ] ],
		[ 'focus', ':is(' + FORM_FIELD + ', textarea, select):focus', [ 'border-color' ] ],
		[ 'placeholder', ':is(input, textarea)::placeholder', [ 'color' ] ],
		[ 'button', ':is(' + FORM_BUTTON + ')', [ 'color', 'background-color', 'border-color', 'border-radius', 'font-size', 'letter-spacing', 'text-transform' ] ],
		[ 'buttonHover', ':is(' + FORM_BUTTON + '):hover', [ 'color', 'background-color' ] ]
	];
	var formColor = /^(#[0-9a-f]{3,8}|transparent|currentcolor|var:preset\|color\|[a-z0-9-]{1,40}|rgba?\([0-9., %]{1,40}\))$/i;
	var formLength = /^([0-9.]{1,8}(px|rem|em|%)?)$/i;
	var formPatterns = {
		color: formColor, 'background-color': formColor, 'border-color': formColor,
		'font-family': /^(var:preset\|font-family\|[a-z0-9-]{1,40}|[a-z0-9 ,'"-]{1,200})$/i,
		'font-size': /^([0-9.]{1,8}(px|rem|em|%)?|var:preset\|font-size\|[a-z0-9-]{1,40})$/i,
		'font-weight': /^([1-9]00|normal|bold)$/,
		'letter-spacing': /^(-?[0-9.]{1,8}(px|rem|em)?|normal)$/i,
		'text-transform': /^(none|uppercase|lowercase|capitalize)$/,
		border: /^(underline|box|none)$/,
		'border-width': formLength, 'border-radius': formLength
	};
	function cleanForm( form ) {
		var out = {};
		if ( ! form || typeof form !== 'object' ) { return out; }
		formTargets.forEach( function ( target ) {
			var values = form[ target[0] ];
			if ( ! values || typeof values !== 'object' ) { return; }
			target[2].forEach( function ( property ) {
				var value = values[ property ];
				if ( typeof value === 'string' && value !== '' && formPatterns[ property ].test( value ) ) { out[ target[0] ] = out[ target[0] ] || {}; out[ target[0] ][ property ] = value; }
			} );
		} );
		return out;
	}
	function formCss( selector, form ) {
		form = cleanForm( form ); var css = '';
		formTargets.forEach( function ( target ) {
			var values = form[ target[0] ] || {}; var body = '';
			target[2].forEach( function ( property ) {
				var value = values[ property ];
				if ( value === undefined ) { return; }
				if ( property === 'border-width' ) { if ( ! values.border ) { body += 'border-width:' + value + ' !important;'; } return; }
				if ( property === 'border' ) {
					var width = values[ 'border-width' ] || '1px';
					body += value === 'none' ? 'border-width:0 !important;' : 'border-style:solid !important;border-width:' + ( value === 'underline' ? '0 0 ' + width + ' 0' : width ) + ' !important;';
					body += value === 'box' ? 'padding-left:.75em !important;padding-right:.75em !important;' : '';
					return;
				}
				if ( property === 'border-color' && values.border === 'none' ) { return; }
				body += property + ':' + String( value ).replace( /^var:preset\|([a-z-]+)\|([a-z0-9-]+)$/, 'var(--wp--preset--$1--$2)' ) + ' !important;';
			} );
			if ( body ) { css += selector + ' ' + target[1] + '{' + body + '}'; }
		} );
		return css;
	}
	root.ClaraVEModel = { copy: copy, at: at, put: put, equal: equal, leaves: leaves, revert: revert, patch: patch, canEditAttribute: canEditAttribute, cssValue: cssValue, fontSettings: fontSettings, responsiveCss: responsiveCss, responsiveProperties: responsiveProperties, formTargets: formTargets, cleanForm: cleanForm, formCss: formCss };
}( typeof window === 'undefined' ? globalThis : window ) );
