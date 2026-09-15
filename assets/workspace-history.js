/* Git-like VE save history. No direct persistence or replacement of editor stores. */
( function ( wp, config ) {
	'use strict';
	if ( ! wp || ! config ) { return; }
	var h = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__, c = wp.components;
	function endpoint( entity, id ) { return '/clara-ve/v1/native/history' + ( id ? '/' + id : '' ) + '?type=' + encodeURIComponent( entity.type ) + '&entity=' + encodeURIComponent( entity.id ); }
	function key( entity ) { return entity.type + ':' + entity.id; }
	function address( entity ) { return entity.type === 'wp_global_styles' ? [ 'root', 'globalStyles', entity.id ] : [ 'postType', entity.type, entity.id ]; }
	function button( label, onClick, props ) { return h( 'button', Object.assign( { type: 'button', onClick: onClick }, props ), label ); }
	function sameContent( a, b ) { return [ 'content', 'blocks', 'meta', 'styles', 'settings' ].every( function ( name ) { return a[name] === b[name]; } ); }
	/** Exported for isolated tests. One native undo point, only this entity. */
	async function stage( registry, entity, snapshot, before ) {
		if ( ! snapshot.entity || snapshot.entity.type !== entity.type || String( snapshot.entity.id ) !== String( entity.id ) ) { throw new Error( __( 'History response belongs to a different document.', 'visual-edit-lite' ) ); }
		var core = registry.select( 'core' );
		var target = address( entity );
		if ( core.isSavingEntityRecord.apply( core, target ) ) { throw new Error( __( 'Wait for the current save to finish before restoring.', 'visual-edit-lite' ) ); }
		var current = core.getEditedEntityRecord.apply( core, target );
		if ( ! current || ( before && ! sameContent( current, before ) ) ) { throw new Error( __( 'The document changed while the version was loading. Try again.', 'visual-edit-lite' ) ); }
		var edits = {};
		if ( entity.type === 'wp_global_styles' ) {
			if ( ! snapshot.edits || ! snapshot.edits.styles || ! snapshot.edits.settings || Array.isArray( snapshot.edits.styles ) || Array.isArray( snapshot.edits.settings ) || typeof snapshot.edits.styles !== 'object' || typeof snapshot.edits.settings !== 'object' ) { throw new Error( __( 'Invalid styles snapshot.', 'visual-edit-lite' ) ); }
			edits.styles = snapshot.edits.styles; edits.settings = snapshot.edits.settings;
		} else {
			if ( ! snapshot.edits || typeof snapshot.edits.content !== 'string' ) { throw new Error( __( 'Invalid content snapshot.', 'visual-edit-lite' ) ); }
			edits.content = snapshot.edits.content;
			edits.blocks = wp.blocks.parse( edits.content );
			edits.selection = undefined;
			// Never restore arbitrary metadata or replace another plugin's keys.
			var metaKey = config.responsiveMeta || '_clara_ve_responsive';
			if ( snapshot.edits.meta && typeof snapshot.edits.meta[metaKey] === 'string' ) { edits.meta = Object.assign( {}, current.meta ); edits.meta[metaKey] = snapshot.edits.meta[metaKey]; }
		}
		var actions = registry.dispatch( 'core' );
		await actions.editEntityRecord.apply( actions, target.concat( [ edits, { undoIgnore: false } ] ) );
	}
	function History( props ) {
		var registry = wp.data.useRegistry();
		var selected = useState( '' ), result = useState( null ), error = useState( '' ), retry = useState( 0 ), busy = useState( false ), confirm = useState( null ), loaded = useState( null ), rename = useState( null );
		var generation = useRef( 0 );
		var availableJSON = wp.data.useSelect( function ( select ) {
			var core = select( 'core' ), editor = select( 'core/editor' ), blocks = select( 'core/block-editor' );
			var entities = [];
			function add( type, id, label ) {
				if ( ! type || ! id || entities.some( function ( entry ) { return entry.type === type && String( entry.id ) === String( id ); } ) ) { return; }
				if ( label && typeof label === 'object' ) { label = label.raw || label.rendered; }
				entities.push( { type: type, id: id, title: label || type + ' · ' + id } );
			}
			add( props.postType, props.postId, props.title || __( 'Current document', 'visual-edit-lite' ) );
			if ( editor.getCurrentTemplateId ) { add( 'wp_template', editor.getCurrentTemplateId(), __( 'Template', 'visual-edit-lite' ) ); }
			function walk( list ) { ( list || [] ).forEach( function ( block ) {
				var attributes = block.attributes || {};
				if ( block.name === 'core/template-part' && attributes.slug ) { add( 'wp_template_part', ( attributes.theme || config.stylesheet ) + '//' + attributes.slug, __( 'Template part', 'visual-edit-lite' ) + ' · ' + attributes.slug ); }
				if ( block.name === 'core/block' ) { add( 'wp_block', attributes.ref, __( 'Synced pattern', 'visual-edit-lite' ) + ' · ' + attributes.ref ); }
				if ( block.name === 'core/navigation' ) { add( 'wp_navigation', attributes.ref, __( 'Navigation', 'visual-edit-lite' ) + ' · ' + attributes.ref ); }
				walk( block.innerBlocks );
			} ); }
			if ( blocks.getBlocks ) { walk( blocks.getBlocks() ); }
			( core.__experimentalGetDirtyEntityRecords ? core.__experimentalGetDirtyEntityRecords() : [] ).forEach( function ( record ) { if ( record.kind === 'postType' ) { add( record.name, record.key, record.name + ' · ' + record.key ); } else if ( record.kind === 'root' && record.name === 'globalStyles' ) { add( 'wp_global_styles', record.key, __( 'Global Styles', 'visual-edit-lite' ) ); } } );
			if ( core.__experimentalGetCurrentGlobalStylesId ) { add( 'wp_global_styles', core.__experimentalGetCurrentGlobalStylesId(), __( 'Global Styles', 'visual-edit-lite' ) ); }
			// A stable scalar avoids resubscribing on every unrelated store edit.
			return JSON.stringify( entities );
		}, [ props.postId, props.postType, props.title ] );
		var available = JSON.parse( availableJSON );
		var entity = available.find( function ( entry ) { return key( entry ) === selected[0]; } ) || available[0];
		var entityKey = entity ? key( entity ) : '';
		var target = entity ? address( entity ) : [];
		var native = wp.data.useSelect( function ( select ) {
			var core = select( 'core' );
			return entity ? { dirty: core.hasEditsForEntityRecord.apply( core, target ), saving: core.isSavingEntityRecord.apply( core, target ), record: core.getEditedEntityRecord.apply( core, target ) } : {};
		}, [ entityKey ] );
		var stillLoaded = loaded[0] && native.record && sameContent( loaded[0].record, native.record ) ? loaded[0].id : null;
		// The confirm box renders above the list, so pressing Restore on an entry
		// far down the panel leaves the button to press above the fold — the panel
		// scrolls on its own, so the page never moves and nothing appears to happen.
		// Bring it into view and put focus on it, for the pointer and the keyboard.
		var confirmRef = useRef( null );
		useEffect( function () {
			if ( ! confirm[0] || ! confirmRef.current ) { return; }
			if ( confirmRef.current.scrollIntoView ) { confirmRef.current.scrollIntoView( { block: 'start', behavior: 'smooth' } ); }
			var first = confirmRef.current.querySelector( 'button' );
			if ( first ) { first.focus( { preventScroll: true } ); }
		}, [ confirm[0] && confirm[0].id ] );
		useEffect( function () {
			var run = ++generation.current; result[1]( null ); error[1]( '' ); confirm[1]( null ); rename[1]( null ); loaded[1]( null );
			if ( ! entity ) { return; }
			wp.apiFetch( { path: endpoint( entity ) } ).then( function ( response ) { if ( run === generation.current ) { result[1]( response ); } } ).catch( function ( err ) { if ( run === generation.current ) { error[1]( err.message ); } } );
			return function () { generation.current++; };
		}, [ entityKey, retry[0], props.saving, native.saving ] );
		async function restore( entry ) {
			var run = generation.current; busy[1]( true ); error[1]( '' );
			try {
				// Resolve the existing record/config before taking a concurrency baseline.
				var resolving = registry.resolveSelect( 'core' );
				await resolving.getEntityRecord.apply( resolving, target );
				if ( run !== generation.current ) { return; }
				var core = registry.select( 'core' );
				var before = entry.before || core.getEditedEntityRecord.apply( core, target );
				var snapshot = await wp.apiFetch( { path: endpoint( entity, entry.id ) } );
				if ( run !== generation.current ) { return; }
				await stage( registry, entity, snapshot, before );
				if ( run !== generation.current ) { return; }
				loaded[1]( { id: entry.id, record: core.getEditedEntityRecord.apply( core, target ) } ); confirm[1]( null );
				if ( window.ClaraVE && window.ClaraVE.emit ) { window.ClaraVE.emit( 'restore', { entity: entity, id: entry.id } ); }
			} catch ( err ) { if ( run === generation.current ) { error[1]( err.message ); } }
			finally { busy[1]( false ); }
		}
		async function saveName() {
			var run = generation.current; busy[1]( true ); error[1]( '' );
			try {
				await wp.apiFetch( { path: endpoint( entity, rename[0].id ), method: 'PATCH', data: { message: rename[0].value } } );
				if ( run === generation.current ) { rename[1]( null ); retry[1]( retry[0] + 1 ); }
			} catch ( err ) { if ( run === generation.current ) { error[1]( err.message ); } }
			finally { busy[1]( false ); }
		}
		var children = [
			h( 'p', { className: 'cve-w-note' }, __( 'Saved versions, not individual clicks. Restore loads one document into the editor; Save publishes it. Other documents are unchanged.', 'visual-edit-lite' ) ),
			entity && h( 'label', { className: 'cve-w-field' }, h( 'span', null, __( 'Document', 'visual-edit-lite' ) ), h( 'select', { value: entityKey, disabled: busy[0], onChange: function ( event ) { selected[1]( event.target.value ); } }, available.map( function ( entry ) { return h( 'option', { key: key( entry ), value: key( entry ) }, entry.title ); } ) ) ),
			! entity && h( 'p', null, __( 'Open a document to see its history.', 'visual-edit-lite' ) ),
			result[0] && h( 'h3', null, result[0].entity.title ),
			error[0] && h( c.Notice, { status: 'error', isDismissible: false }, error[0] ),
			error[0] && ! result[0] && button( __( 'Retry', 'visual-edit-lite' ), function () { retry[1]( retry[0] + 1 ); } ),
			entity && ! result[0] && ! error[0] && h( c.Spinner ),
			native.dirty && ! stillLoaded && h( c.Notice, { status: 'warning', isDismissible: false }, __( 'This document has unsaved changes. Restoring will replace its content in the editor.', 'visual-edit-lite' ) ),
			stillLoaded && h( c.Notice, { status: 'success', isDismissible: false }, __( 'Version loaded. Use Save to apply it, or Undo to return to your previous work.', 'visual-edit-lite' ) ),
			confirm[0] && h( 'div', { ref: confirmRef, className: 'cve-w-history-confirm', role: 'group', 'aria-label': __( 'Confirm restore', 'visual-edit-lite' ) }, h( 'p', null, __( 'Load this version into the editor?', 'visual-edit-lite' ) + ' #' + confirm[0].id ), button( __( 'Restore version', 'visual-edit-lite' ), function () { restore( confirm[0] ); }, { disabled: busy[0] || native.saving || props.saving } ), button( __( 'Cancel', 'visual-edit-lite' ), function () { confirm[1]( null ); }, { disabled: busy[0] } ) ),
			h( 'ol', { className: 'cve-w-history-list' }, ( result[0] ? result[0].entries : [] ).map( function ( entry ) {
				var isOriginal = entry.message === 'Original';
				return h( 'li', { key: entry.id, className: 'cve-w-history-entry' + ( entry.isHead ? ' is-current' : '' ) },
					rename[0] && rename[0].id === entry.id ? h( 'div', null, h( 'input', { autoFocus: true, 'aria-label': __( 'Version name', 'visual-edit-lite' ), value: rename[0].value, maxLength: 255, disabled: busy[0], onChange: function ( event ) { rename[1]( { id: entry.id, value: event.target.value } ); }, onKeyDown: function ( event ) { if ( event.key === 'Enter' && ! busy[0] ) { event.preventDefault(); saveName(); } } } ), button( __( 'Save name', 'visual-edit-lite' ), saveName, { disabled: busy[0] } ), button( __( 'Cancel', 'visual-edit-lite' ), function () { rename[1]( null ); }, { disabled: busy[0] } ) ) : button( isOriginal ? __( 'Original', 'visual-edit-lite' ) : entry.message || __( 'Save', 'visual-edit-lite' ), function () { rename[1]( { id: entry.id, value: entry.message || '' } ); }, { className: 'cve-w-history-name', title: __( 'Rename version', 'visual-edit-lite' ), disabled: busy[0] } ),
					h( 'div', { className: 'cve-w-note' }, '#' + entry.id + ' · ' + entry.createdAt + ' · ' + entry.hash ),
					entry.isHead && h( 'span', { className: 'cve-w-history-badge' }, __( 'Current saved version', 'visual-edit-lite' ) ),
					stillLoaded === entry.id && h( 'span', { className: 'cve-w-history-badge' }, __( 'Loaded in editor', 'visual-edit-lite' ) ),
					button( __( 'Restore', 'visual-edit-lite' ), function () { confirm[1]( Object.assign( { before: native.record }, entry ) ); }, { disabled: busy[0] || native.saving || props.saving || ( entry.isHead && ! native.dirty ) } ) );
			} ) ),
			h( 'p', { className: 'cve-w-note' }, __( 'The latest 10 saves and the Original are available. Restoring does not delete newer versions.', 'visual-edit-lite' ) ) ];
		var close = busy[0] ? function () {} : props.onClose;
		if ( props.docked ) {
			// Docked beside the page, like the HTML editor's history panel.
			return wp.element.createPortal( h( 'aside', { className: 'cve-w-dock cve-w-history', role: 'complementary', 'aria-label': __( 'History', 'visual-edit-lite' ) },
				h( 'div', { className: 'cve-w-dock-head' }, h( 'strong', null, __( 'History', 'visual-edit-lite' ) ), button( '×', close, { 'aria-label': __( 'Close history', 'visual-edit-lite' ), disabled: busy[0] } ) ),
				h.apply( null, [ 'div', { className: 'cve-w-dock-body' } ].concat( children ) ) ), document.body );
		}
		return h.apply( null, [ c.Modal, { title: __( 'History', 'visual-edit-lite' ), className: 'cve-w-dialog cve-w-history', onRequestClose: close } ].concat( children ) );
	}
	window.ClaraVEHistory = { Panel: History, stage: stage };
}( window.wp, window.claraVeGutenberg ) );
