/* Keep promoted ornaments editable in the ordinary WordPress editor, too. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.richText || wp.data.select( 'core/rich-text' ).getFormatType( 'clara-ve/ornament' ) ) { return; }
	wp.richText.registerFormatType( 'clara-ve/ornament', {
		title: wp.i18n.__( 'Ornament', 'visual-edit-lite' ),
		tagName: 'span', className: 'cve-ornament', attributes: { style: 'style' },
		edit: function () { return null; }
	} );
}( window.wp ) );
