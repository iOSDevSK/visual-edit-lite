( function () {
	'use strict';
	var host = document.querySelector( '.cve-workspace-host' );
	if ( ! host ) { return; }
	var frame = host.querySelector( 'iframe' );
	var origin = new URL( frame.src ).origin;
	var dirty = false;
	window.addEventListener( 'message', function ( event ) {
		var data = event.data;
		if ( event.origin !== origin || event.source !== frame.contentWindow || ! data ||
			data.channel !== 'clara-ve-workspace' || data.version !== 1 || data.session !== host.dataset.session ) { return; }
		if ( data.type === 'ready' ) { host.classList.add( 'is-ready' ); }
		if ( data.type === 'dirty' ) { dirty = data.dirty === true; }
	} );
	// The frame is only ever the workspace. A link inside it that leads
	// anywhere else — the dashboard, a list screen, revisions, the site —
	// would otherwise render a second admin bar and menu inside the first, so
	// the destination opens in the whole window instead.
	frame.addEventListener( 'load', function () {
		var win = frame.contentWindow, href;
		try { href = win.location.href; } catch ( error ) { return; }
		if ( ! href || href === 'about:blank' ) { return; }
		// The frame document already asked about unsaved changes before it left.
		win.addEventListener( 'pagehide', function () { dirty = false; } );
		if ( new URL( href ).searchParams.get( 'clara_ve_workspace' ) === '1' ) { return; }
		dirty = false;
		window.location.assign( href );
	} );
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) { event.preventDefault(); event.returnValue = ''; }
	} );
}() );
