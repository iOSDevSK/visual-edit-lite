/* Search appearance panel shared by the VE workspace and the ordinary WordPress editor. */
( function ( wp, config ) {
	'use strict';
	if ( ! wp || ! config || ! wp.element || ! wp.data || ! wp.components ) {
		return;
	}
	var h = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var components = wp.components;

	function SeoPanel( props ) {
		var postId = props.postId;
		var state = useState( null );
		var seo = state[0];
		var setSeo = state[1];
		var busyState = useState( false );
		var busy = busyState[0];
		var setBusy = busyState[1];
		var noticeState = useState( null );
		var notice = noticeState[0];
		var setNotice = noticeState[1];

		useEffect( function () {
			var alive = true;
			setSeo( null );
			setNotice( null );
			wp.apiFetch( { path: config.nativeSeoPath + postId } ).then( function ( value ) {
				if ( alive ) {
					setSeo( value );
				}
			} ).catch( function ( error ) {
				if ( alive ) {
					setNotice( { status: 'error', text: error.message || __( 'Search settings could not be loaded.', 'visual-edit-lite' ) } );
				}
			} );
			return function () { alive = false; };
		}, [ postId ] );

		function change( key, value ) {
			setSeo( Object.assign( {}, seo, ( function () {
				var next = {};
				next[ key ] = value;
				return next;
			}() ) ) );
			setNotice( null );
		}

		function save() {
			setBusy( true );
			setNotice( null );
			wp.apiFetch( {
				path: config.nativeSeoPath + postId,
				method: 'POST',
				data: {
					title: seo.title || '',
					description: seo.description || '',
					ogImage: seo.ogImage || '',
					noindex: !! seo.noindex
				}
			} ).then( function ( value ) {
				setSeo( value );
				setNotice( { status: 'success', text: __( 'Search appearance saved.', 'visual-edit-lite' ) } );
			} ).catch( function ( error ) {
				setNotice( { status: 'error', text: error.message || __( 'Search settings could not be saved.', 'visual-edit-lite' ) } );
			} ).then( function () {
				setBusy( false );
			} );
		}

		if ( ! seo ) {
			return h( components.Spinner );
		}

		return h( Fragment, null,
			notice && h( components.Notice, { status: notice.status, isDismissible: true, onRemove: function () { setNotice( null ); } }, notice.text ),
			h( components.TextControl, {
				label: __( 'Search title', 'visual-edit-lite' ),
				__next40pxDefaultSize: config.next40pxDefaultSize ? true : undefined,
				value: seo.title || '',
				placeholder: seo.fallbackTitle || '',
				onChange: function ( value ) { change( 'title', value ); }
			} ),
			h( components.TextareaControl, {
				label: __( 'Meta description', 'visual-edit-lite' ),
				value: seo.description || '',
				onChange: function ( value ) { change( 'description', value ); }
			} ),
			h( components.TextControl, {
				label: __( 'Social sharing image URL', 'visual-edit-lite' ),
				__next40pxDefaultSize: config.next40pxDefaultSize ? true : undefined,
				type: 'url',
				value: seo.ogImage || '',
				onChange: function ( value ) { change( 'ogImage', value ); }
			} ),
			wp.blockEditor.MediaUpload && h( wp.blockEditor.MediaUpload, {
				allowedTypes: [ 'image' ],
				onSelect: function ( media ) { change( 'ogImage', media && media.url ? media.url : '' ); },
				render: function ( mediaProps ) {
					return h( 'div', { className: 'cve-gutenberg-media' },
						seo.ogImage && h( 'img', { src: seo.ogImage, alt: '' } ),
						h( components.Button, { variant: 'secondary', onClick: mediaProps.open }, seo.ogImage ? __( 'Replace image', 'visual-edit-lite' ) : __( 'Choose from Media Library', 'visual-edit-lite' ) ),
						seo.ogImage && h( components.Button, { variant: 'tertiary', isDestructive: true, onClick: function () { change( 'ogImage', '' ); } }, __( 'Remove', 'visual-edit-lite' ) )
					);
				}
			} ),
			h( components.ToggleControl, {
				label: __( 'Hide from search engines', 'visual-edit-lite' ),
				checked: !! seo.noindex,
				onChange: function ( value ) { change( 'noindex', value ); }
			} ),
			// Stay focusable while saving: a disabled button drops focus to the page, and Esc no longer closes the dialog.
			h( components.Button, { variant: 'primary', isBusy: busy, disabled: busy, accessibleWhenDisabled: true, __experimentalIsFocusable: true, onClick: save }, __( 'Save search appearance', 'visual-edit-lite' ) ),
			seo.hostLabel && h( 'p', { className: 'cve-gutenberg-note' }, __( 'These values are also copied to ', 'visual-edit-lite' ) + seo.hostLabel + '.' )
		);
	}

	window.ClaraVENative = Object.assign( window.ClaraVENative || {}, { SeoPanel: SeoPanel } );
}( window.wp, window.claraVeGutenberg ) );
