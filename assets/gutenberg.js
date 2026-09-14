( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.element || ! wp.data || ! wp.plugins ) {
		return;
	}

	var h = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var components = wp.components;
	var PluginSidebar = wp.editor && wp.editor.PluginSidebar ? wp.editor.PluginSidebar : null;
	var InspectorControls = wp.blockEditor && wp.blockEditor.InspectorControls;
	var responsiveKey = config.responsiveMeta || '_clara_ve_responsive';

	function postContext() {
		try {
			var editor = wp.data.select( 'core/editor' );
			if ( ! editor || ! editor.getCurrentPostId ) {
				return { id: 0, type: '' };
			}
			return {
				id: parseInt( editor.getCurrentPostId(), 10 ) || 0,
				type: editor.getCurrentPostType ? ( editor.getCurrentPostType() || '' ) : ''
			};
		} catch ( error ) {
			return { id: 0, type: '' };
		}
	}

	function usePostContext() {
		return wp.data.useSelect( function () {
			return postContext();
		}, [] );
	}

	function LinkButton( props ) {
		return h(
			components.Button,
			{ href: props.href, variant: props.primary ? 'primary' : 'secondary' },
			props.children
		);
	}

	var SeoPanel = window.ClaraVENative && window.ClaraVENative.SeoPanel ? window.ClaraVENative.SeoPanel : function () { return null; };

	function Sidebar() {
		var context = usePostContext();
		var hasPost = context.id > 0 && ( 'page' === context.type || 'post' === context.type );

		if ( ! PluginSidebar ) {
			return null;
		}
		return h(
			PluginSidebar,
			{ name: 'clara-ve-sidebar', title: __( 'Visual Edit Lite', 'visual-edit-lite' ), icon: 'edit-page' },
			h( components.PanelBody, { title: __( 'Gutenberg integration', 'visual-edit-lite' ), initialOpen: true },
				h( 'p', null, config.isSiteEditor
					? __( 'You are editing the complete block theme. Gutenberg saves pages, templates, template parts, navigation, patterns and Global Styles in their native WordPress records.', 'visual-edit-lite' )
					: __( 'This page uses the complete native Gutenberg editor. Select a block to find Visual Edit movement and responsive controls in the block inspector.', 'visual-edit-lite' )
				),
				h( 'div', { className: 'cve-gutenberg-actions' },
					! config.isSiteEditor && h( LinkButton, { href: config.siteEditorUrl, primary: true }, __( 'Edit the complete site', 'visual-edit-lite' ) ),
					h( LinkButton, { href: config.pagesUrl }, __( 'Pages', 'visual-edit-lite' ) ),
					h( LinkButton, { href: config.postsUrl }, __( 'Posts', 'visual-edit-lite' ) )
				)
			),
			hasPost && h( components.PanelBody, { title: __( 'Search appearance', 'visual-edit-lite' ), initialOpen: false }, h( SeoPanel, { postId: context.id } ) ),
			h( components.PanelBody, { title: __( 'More Visual Edit tools', 'visual-edit-lite' ), initialOpen: false },
				h( 'div', { className: 'cve-gutenberg-actions' },
					h( LinkButton, { href: config.seoUrl }, __( 'SEO & sharing settings', 'visual-edit-lite' ) ),
					h( LinkButton, { href: config.formsUrl }, __( 'Form submissions', 'visual-edit-lite' ) )
				)
			)
		);
	}

	function classTokens( value ) {
		return ( value || '' ).split( /\s+/ ).filter( Boolean );
	}

	function classWithChoice( current, prefix, choice ) {
		var tokens = classTokens( current ).filter( function ( token ) {
			return 0 !== token.indexOf( prefix );
		} );
		if ( choice ) {
			tokens.push( prefix + choice );
		}
		return tokens.join( ' ' );
	}

	function currentChoice( current, prefix ) {
		var token = classTokens( current ).filter( function ( item ) {
			return 0 === item.indexOf( prefix );
		} )[0];
		return token ? token.slice( prefix.length ) : '';
	}

	function anchorIn( current ) {
		var match = ( current || '' ).match( /(?:^|\s)(cve-r-[a-z0-9]{4,20})(?=\s|$)/ );
		return match ? match[1] : '';
	}

	function newAnchor() {
		var random = '';
		if ( window.crypto && window.crypto.getRandomValues ) {
			var bytes = new Uint32Array( 2 );
			window.crypto.getRandomValues( bytes );
			random = bytes[0].toString( 36 ) + bytes[1].toString( 36 );
		} else {
			random = Math.random().toString( 36 ).slice( 2 );
		}
		return 'cve-r-' + random.slice( 0, 8 ).padEnd( 4, '0' );
	}

	function parseRules( value ) {
		if ( ! value ) {
			return {};
		}
		try {
			var parsed = 'string' === typeof value ? JSON.parse( value ) : value;
			return parsed && 'object' === typeof parsed ? parsed : {};
		} catch ( error ) {
			return {};
		}
	}

	function responsiveCss( rules ) {
		var widths = { tablet: 781, mobile: 600 };
		var properties = {
			'spacing.padding.top': 'padding-top',
			'spacing.padding.right': 'padding-right',
			'spacing.padding.bottom': 'padding-bottom',
			'spacing.padding.left': 'padding-left',
			'spacing.margin.top': 'margin-top',
			'spacing.margin.bottom': 'margin-bottom',
			'typography.fontSize': 'font-size',
			'typography.textAlign': 'text-align',
			'dimensions.minHeight': 'min-height',
			'display': 'display'
		};
		var css = '';
		[ 'tablet', 'mobile' ].forEach( function ( screen ) {
			var body = '';
			Object.keys( rules || {} ).forEach( function ( anchor ) {
				if ( ! /^cve-r-[a-z0-9]{4,20}$/.test( anchor ) || ! rules[ anchor ][ screen ] ) {
					return;
				}
				var declarations = '';
				Object.keys( rules[ anchor ][ screen ] ).forEach( function ( path ) {
					var value = String( rules[ anchor ][ screen ][ path ] || '' );
					if ( ! properties[ path ] || ! value || /[{}<>;]/.test( value ) || /url\s*\(|expression\s*\(|@import/i.test( value ) ) {
						return;
					}
					if ( 0 === value.indexOf( 'var:preset|' ) ) {
						var token = value.split( '|' );
						value = 3 === token.length ? 'var(--wp--preset--' + token[1] + '--' + token[2] + ')' : '';
					}
					if ( value ) {
						declarations += properties[ path ] + ':' + value + ' !important;';
					}
				} );
				if ( declarations ) {
					body += '.' + anchor + '{' + declarations + '}';
				}
			} );
			if ( body ) {
				css += '@media (max-width:' + widths[ screen ] + 'px){' + body + '}';
			}
		} );
		return css;
	}

	/** Keep unsaved responsive values visible in Gutenberg's preview iframe. */
	function ResponsivePreview() {
		var context = usePostContext();
		var metaValue = wp.data.useSelect( function ( select ) {
			if ( ! context.id || ( 'page' !== context.type && 'post' !== context.type ) ) {
				return '';
			}
			try {
				var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
				return meta[ responsiveKey ] || '';
			} catch ( error ) {
				return '';
			}
		}, [ context.id, context.type ] );

		useEffect( function () {
			var css = responsiveCss( parseRules( metaValue ) );
			var styleId = 'clara-ve-responsive-preview';

			function inject( targetDocument ) {
				if ( ! targetDocument || ! targetDocument.head ) {
					return;
				}
				var style = targetDocument.getElementById( styleId );
				if ( ! style ) {
					style = targetDocument.createElement( 'style' );
					style.id = styleId;
					targetDocument.head.appendChild( style );
				}
				if ( style.textContent !== css ) {
					style.textContent = css;
				}
			}

			function apply() {
				inject( document );
				document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
					try {
						inject( frame.contentDocument );
					} catch ( error ) {
						// Gutenberg editor frames are same-origin. Ignore any plugin
						// preview iframe that deliberately is not.
					}
				} );
			}

			apply();
			var observer = new MutationObserver( apply );
			observer.observe( document.body, { childList: true, subtree: true } );
			return function () {
				observer.disconnect();
				document.querySelectorAll( '#' + styleId ).forEach( function ( style ) { style.remove(); } );
				document.querySelectorAll( 'iframe' ).forEach( function ( frame ) {
					try {
						var style = frame.contentDocument && frame.contentDocument.getElementById( styleId );
						if ( style ) {
							style.remove();
						}
					} catch ( error ) {}
				} );
			};
		}, [ metaValue ] );

		return null;
	}

	function cloneRules( rules ) {
		return JSON.parse( JSON.stringify( rules || {} ) );
	}

	function responsiveControl( label, path, value, change ) {
		return h( components.TextControl, {
			label: label,
			__next40pxDefaultSize: config.next40pxDefaultSize ? true : undefined,
			value: value || '',
			placeholder: __( 'Inherit (for example 24px)', 'visual-edit-lite' ),
			onChange: function ( next ) { change( path, next.trim() ); }
		} );
	}

	if ( InspectorControls && wp.compose && wp.hooks && wp.blocks ) {
		var withVisualEditControls = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
			return function ( props ) {
				var context = usePostContext();
				var metaValue = wp.data.useSelect( function ( select ) {
					if ( ! context.id || ( 'page' !== context.type && 'post' !== context.type ) ) {
						return '';
					}
					try {
						var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
						return meta[ responsiveKey ] || '';
					} catch ( error ) {
						return '';
					}
				}, [ context.id, context.type ] );
				var breakpointState = useState( 'mobile' );
				var breakpoint = breakpointState[0];
				var setBreakpoint = breakpointState[1];
				var supportsClass = wp.blocks.hasBlockSupport( props.name, 'customClassName', true );
				var className = props.attributes.className || '';
				var anchor = anchorIn( className );
				var rules = parseRules( metaValue );
				var values = anchor && rules[ anchor ] && rules[ anchor ][ breakpoint ] ? rules[ anchor ][ breakpoint ] : {};

				function updateMeta( nextRules ) {
					var editor = wp.data.select( 'core/editor' );
					var latest = editor.getEditedPostAttribute( 'meta' ) || {};
					var nextMeta = Object.assign( {}, latest );
					nextMeta[ responsiveKey ] = Object.keys( nextRules ).length ? JSON.stringify( nextRules ) : '';
					wp.data.dispatch( 'core/editor' ).editPost( { meta: nextMeta } );
				}

				function setResponsive( path, value ) {
					var targetAnchor = anchor;
					if ( ! targetAnchor ) {
						targetAnchor = newAnchor();
						props.setAttributes( { className: classTokens( className ).concat( [ targetAnchor ] ).join( ' ' ) } );
					}
					var next = cloneRules( parseRules( metaValue ) );
					next[ targetAnchor ] = next[ targetAnchor ] || {};
					next[ targetAnchor ][ breakpoint ] = next[ targetAnchor ][ breakpoint ] || {};
					if ( value ) {
						next[ targetAnchor ][ breakpoint ][ path ] = value;
					} else {
						delete next[ targetAnchor ][ breakpoint ][ path ];
						if ( ! Object.keys( next[ targetAnchor ][ breakpoint ] ).length ) {
							delete next[ targetAnchor ][ breakpoint ];
						}
						if ( ! Object.keys( next[ targetAnchor ] ).length ) {
							delete next[ targetAnchor ];
						}
					}
					updateMeta( next );
				}

				var controls = null;
				if ( props.isSelected && supportsClass ) {
					controls = h( InspectorControls, null,
						h( components.PanelBody, { title: __( 'Visual Edit Lite', 'visual-edit-lite' ), initialOpen: false },
							h( components.SelectControl, {
								label: __( 'Entrance movement', 'visual-edit-lite' ),
								value: currentChoice( className, 'cve-anim-' ),
								options: [
									{ label: __( 'None', 'visual-edit-lite' ), value: '' },
									{ label: __( 'Fade', 'visual-edit-lite' ), value: 'fade' },
									{ label: __( 'Fade up', 'visual-edit-lite' ), value: 'fade-up' },
									{ label: __( 'Fade down', 'visual-edit-lite' ), value: 'fade-down' },
									{ label: __( 'Zoom', 'visual-edit-lite' ), value: 'zoom' },
									{ label: __( 'Slide left', 'visual-edit-lite' ), value: 'slide-left' },
									{ label: __( 'Slide right', 'visual-edit-lite' ), value: 'slide-right' }
								],
								onChange: function ( value ) { props.setAttributes( { className: classWithChoice( className, 'cve-anim-', value ) } ); }
							} ),
							h( components.SelectControl, {
								label: __( 'Hover effect', 'visual-edit-lite' ),
								value: currentChoice( className, 'cve-hover-' ),
								options: [
									{ label: __( 'None', 'visual-edit-lite' ), value: '' },
									{ label: __( 'Lift', 'visual-edit-lite' ), value: 'lift' },
									{ label: __( 'Grow', 'visual-edit-lite' ), value: 'grow' },
									{ label: __( 'Soften', 'visual-edit-lite' ), value: 'soften' },
									{ label: __( 'Dim', 'visual-edit-lite' ), value: 'dim' }
								],
								onChange: function ( value ) { props.setAttributes( { className: classWithChoice( className, 'cve-hover-', value ) } ); }
							} )
						),
						context.id > 0 && h( components.PanelBody, { title: __( 'Responsive values', 'visual-edit-lite' ), initialOpen: false },
							h( components.SelectControl, {
								label: __( 'Screen', 'visual-edit-lite' ),
								value: breakpoint,
								options: [
									{ label: __( 'Mobile (600px and below)', 'visual-edit-lite' ), value: 'mobile' },
									{ label: __( 'Tablet (781px and below)', 'visual-edit-lite' ), value: 'tablet' }
								],
								onChange: setBreakpoint
							} ),
							responsiveControl( __( 'Padding top', 'visual-edit-lite' ), 'spacing.padding.top', values['spacing.padding.top'], setResponsive ),
							responsiveControl( __( 'Padding right', 'visual-edit-lite' ), 'spacing.padding.right', values['spacing.padding.right'], setResponsive ),
							responsiveControl( __( 'Padding bottom', 'visual-edit-lite' ), 'spacing.padding.bottom', values['spacing.padding.bottom'], setResponsive ),
							responsiveControl( __( 'Padding left', 'visual-edit-lite' ), 'spacing.padding.left', values['spacing.padding.left'], setResponsive ),
							responsiveControl( __( 'Margin top', 'visual-edit-lite' ), 'spacing.margin.top', values['spacing.margin.top'], setResponsive ),
							responsiveControl( __( 'Margin bottom', 'visual-edit-lite' ), 'spacing.margin.bottom', values['spacing.margin.bottom'], setResponsive ),
							responsiveControl( __( 'Font size', 'visual-edit-lite' ), 'typography.fontSize', values['typography.fontSize'], setResponsive ),
							h( components.SelectControl, {
								label: __( 'Text alignment', 'visual-edit-lite' ),
								value: values['typography.textAlign'] || '',
								options: [
									{ label: __( 'Inherit', 'visual-edit-lite' ), value: '' },
									{ label: __( 'Left', 'visual-edit-lite' ), value: 'left' },
									{ label: __( 'Center', 'visual-edit-lite' ), value: 'center' },
									{ label: __( 'Right', 'visual-edit-lite' ), value: 'right' }
								],
								onChange: function ( value ) { setResponsive( 'typography.textAlign', value ); }
							} ),
							responsiveControl( __( 'Minimum height', 'visual-edit-lite' ), 'dimensions.minHeight', values['dimensions.minHeight'], setResponsive ),
							h( components.ToggleControl, {
								label: __( 'Hide on this screen size', 'visual-edit-lite' ),
								checked: 'none' === values.display,
								onChange: function ( checked ) { setResponsive( 'display', checked ? 'none' : '' ); }
							} ),
							h( 'p', { className: 'cve-gutenberg-note' }, __( 'Responsive values are saved with the page and take part in Gutenberg undo and redo.', 'visual-edit-lite' ) )
						)
					);
				}

				return h( Fragment, null, h( BlockEdit, props ), controls );
			};
		}, 'withVisualEditControls' );

		wp.hooks.addFilter( 'editor.BlockEdit', 'clara-ve/native-controls', withVisualEditControls );
	}

	function NativeIntegration() {
		return h( Fragment, null, h( Sidebar ), h( ResponsivePreview ) );
	}

	wp.plugins.registerPlugin( 'clara-ve-native', { render: NativeIntegration, icon: 'edit-page' } );
}( window.wp, window.claraVeGutenberg ) );
