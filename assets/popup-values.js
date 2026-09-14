/** Value adapters shared by the HTML and Gutenberg popup renderers. */
( function ( root ) {
	'use strict';
	function number( value ) {
		var match = /^(-?(?:\d+(?:\.\d*)?|\.\d+))\s*([a-z%]*)$/i.exec( String( value == null ? '' : value ).trim() );
		return match ? { value: Number( match[1] ), unit: match[2].toLowerCase() } : null;
	}
	function step( value, delta, options ) {
		options = options || {};
		var parsed = number( value );
		if ( ! parsed && String( value || '' ).trim() ) { return null; }
		var next = ( parsed ? parsed.value : 0 ) + delta;
		if ( options.min !== undefined ) { next = Math.max( options.min, next ); }
		if ( options.max !== undefined ) { next = Math.min( options.max, next ); }
		return String( Math.round( next * 10000 ) / 10000 ) + ( parsed ? parsed.unit : options.unit || '' );
	}
	/** Split only at top level: commas/spaces inside rgb(), var(), calc() stay intact. */
	function split( value, separator ) {
		var out = [], start = 0, depth = 0, quote = '';
		value = String( value || '' );
		for ( var i = 0; i < value.length; i++ ) {
			var char = value[i];
			if ( char === '\\' ) { i++; continue; }
			if ( quote ) { if ( char === quote ) { quote = ''; } continue; }
			if ( char === '"' || char === "'" ) { quote = char; continue; }
			if ( char === '(' ) { depth++; }
			if ( char === ')' ) { depth--; }
			if ( depth === 0 && ( separator === ' ' ? /\s/.test( char ) : char === separator ) ) {
				if ( value.slice( start, i ).trim() ) { out.push( value.slice( start, i ).trim() ); }
				start = i + 1;
			}
		}
		if ( value.slice( start ).trim() ) { out.push( value.slice( start ).trim() ); }
		return out;
	}
	function sides( value ) {
		var parts = split( value, ' ' );
		return [ parts[0], parts[1] || parts[0], parts[2] || parts[0], parts[3] || parts[1] || parts[0] ];
	}
	function box( value, corners ) {
		if ( value && typeof value === 'object' ) { return Object.assign( {}, value ); }
		var keys = corners ? [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] : [ 'top', 'right', 'bottom', 'left' ];
		var axes = split( value, '/' );
		var horizontal = sides( axes[0] ); var vertical = corners && axes[1] ? sides( axes[1] ) : null;
		return keys.reduce( function ( out, key, i ) {
			if ( horizontal[i] !== undefined ) { out[key] = horizontal[i] + ( vertical ? ' ' + vertical[i] : '' ); }
			return out;
		}, {} );
	}
	function makeGradient( from, to, direction ) { return 'linear-gradient(' + direction + ', ' + from + ' 0%, ' + to + ' 100%)'; }
	function parseGradient( css ) {
		var match = /^linear-gradient\((.*)\)$/i.exec( String( css || '' ).trim() );
		if ( ! match ) { return null; }
		var parts = split( match[1], ',' );
		if ( parts.length !== 3 || ! /^(?:-?[\d.]+(?:deg|turn|rad|grad)|to (?:top|bottom|left|right)(?: (?:top|bottom|left|right))?)$/.test( parts[0] ) ) { return null; }
		var from = /^(.*)\s+0%$/.exec( parts[1] ); var to = /^(.*)\s+100%$/.exec( parts[2] );
		return from && to ? { direction: parts[0], from: from[1], to: to[1] } : null;
	}
	function paletteGradients( palette ) {
		var colors = ( palette || [] ).filter( function ( color ) { return /^(#|rgb)/i.test( color.value || '' ); } );
		var out = [];
		for ( var i = 0; i + 1 < colors.length && out.length < 6; i++ ) {
			if ( colors[i].value !== colors[i + 1].value ) { out.push( { name: colors[i].name + ' → ' + colors[i + 1].name, value: makeGradient( colors[i].value, colors[i + 1].value, '135deg' ) } ); }
		}
		return out;
	}
	/**
	 * Where a popup opens, shared by both editors. Beside the element at the
	 * height of the click; below or above it when it fills the width; over it
	 * only as a last resort. All coordinates are one viewport's.
	 */
	function placePopup( rect, pointer, size, viewport ) {
		var gap = 16, margin = 8, top = viewport.top || 0;
		var width = size.width, height = Math.min( size.height, viewport.height - top - margin );
		function clampY( y ) { return Math.max( top, Math.min( y, viewport.height - height - margin ) ); }
		function clampX( x ) { return Math.max( margin, Math.min( x, viewport.width - width - margin ) ); }
		var y = pointer ? pointer.y - 24 : rect.top;
		if ( viewport.width - rect.right - margin >= width + gap ) { return { left: rect.right + gap, top: clampY( y ) }; }
		if ( rect.left - margin >= width + gap ) { return { left: rect.left - gap - width, top: clampY( y ) }; }
		var x = pointer ? pointer.x - width / 2 : rect.left;
		if ( viewport.height - rect.bottom - margin >= height + gap ) { return { left: clampX( x ), top: rect.bottom + gap }; }
		if ( rect.top - top >= height + gap ) { return { left: clampX( x ), top: rect.top - gap - height }; }
		// Nowhere free: next to the click, or at the right edge where it is predictable.
		return pointer ? { left: clampX( pointer.x + gap ), top: clampY( pointer.y + gap ) } : { left: clampX( viewport.width - width - gap ), top: clampY( Math.max( rect.top, top ) ) };
	}
	root.ClaraVEValues = { number: number, step: step, split: split, box: box, makeGradient: makeGradient, parseGradient: parseGradient, paletteGradients: paletteGradients, placePopup: placePopup };
}( typeof window === 'undefined' ? globalThis : window ) );
