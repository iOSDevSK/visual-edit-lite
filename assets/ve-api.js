/*
 * Public Visual Edit editor API — window.ClaraVE.
 *
 * One surface for both editors: the Gutenberg workspace (block themes) and the
 * raw-HTML editor (converted themes) each register an implementation. Other
 * plugins, including Visual Edit Pro's assistant, drive the editor through
 * operations instead of reaching into either editor's internals:
 *
 *   ClaraVE.ready( function ( ve ) {
 *     ve.apply( [ { op: 'set-style', id: ve.getSelection().id, style: { 'typography.fontSize': '32px' } } ] );
 *   } );
 *
 * Every write goes through the same permission checks the popup uses, and in
 * the workspace it is an ordinary unsaved editor change: Undo reverts it and
 * Save publishes it. Nothing here saves on its own.
 */
( function ( root ) {
	'use strict';
	if ( root.ClaraVE && root.ClaraVE.version ) { return; }
	var implementation = null;
	var waiting = [];
	function eventName( name ) { return 'clara-ve:' + name; }
	function notReady() { return Promise.reject( new Error( 'Visual Edit is not ready yet.' ) ); }
	function call( method, args, fallback ) {
		return implementation && typeof implementation[ method ] === 'function' ? implementation[ method ].apply( implementation, args ) : fallback;
	}
	var api = {
		version: 1,
		/** 'block' in the Gutenberg workspace, 'html' in the raw-HTML editor, null before either registers. */
		mode: null,
		ready: function ( callback ) {
			if ( implementation ) { callback( api ); } else { waiting.push( callback ); }
		},
		/** { id, name, title, attributes, editingMode, parents: [ { id, name, title } ], sectionName } or null. */
		getSelection: function () { return call( 'getSelection', [], null ); },
		select: function ( id ) { return call( 'select', [ id ], false ); },
		openPopup: function () { return call( 'openPopup', [], false ); },
		closePopup: function () { return call( 'closePopup', [], false ); },
		/**
		 * Apply operations. Resolves { applied: [ index ], refused: [ { index, op, reason } ] }.
		 * Operations: set-text, set-attrs, set-style, set-link, set-image, set-responsive,
		 * set-ornament, set-motion, convert-to-video, remove, duplicate, move, insert-pattern.
		 * See docs/developer/editor-api.md for payloads.
		 */
		apply: function ( ops ) {
			if ( ! Array.isArray( ops ) ) { return Promise.reject( new TypeError( 'ClaraVE.apply expects an array of operations.' ) ); }
			return implementation && implementation.apply ? Promise.resolve( implementation.apply( ops ) ) : notReady();
		},
		/** { mode, type, id, title, content } for the open document. */
		getDocument: function () { return call( 'getDocument', [], null ); },
		history: {
			list: function () { return implementation && implementation.historyList ? Promise.resolve( implementation.historyList() ) : notReady(); },
			restore: function ( id ) { return implementation && implementation.historyRestore ? Promise.resolve( implementation.historyRestore( id ) ) : notReady(); }
		},
		/** Events: ready, select, apply, save, restore, unlock. Returns an unsubscribe function. */
		on: function ( name, callback ) {
			function listener( event ) { callback( event.detail ); }
			root.addEventListener( eventName( name ), listener );
			return function () { root.removeEventListener( eventName( name ), listener ); };
		},
		emit: function ( name, detail ) {
			var event;
			try { event = new root.CustomEvent( eventName( name ), { detail: detail } ); } catch ( error ) { return; }
			root.dispatchEvent( event );
		},
		/** Called once by the active editor. */
		register: function ( next ) {
			implementation = next;
			api.mode = next.mode || null;
			var callbacks = waiting; waiting = [];
			callbacks.forEach( function ( callback ) {
				try { callback( api ); } catch ( error ) { if ( root.console ) { root.console.error( error ); } }
			} );
			api.emit( 'ready', { mode: api.mode } );
		}
	};
	root.ClaraVE = api;
}( window ) );
