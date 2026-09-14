/* Evaluate in a loaded VE runtime: runVisualEditNativeAudit(window).
 * Read-only audit: isolated block objects, never insert/select/save/dispatch.
 */
function runVisualEditNativeAudit( editorWindow ) {
	'use strict';
	var wp = editorWindow.wp;
	var core = wp.data.select( 'core' );
	function dirty() { return JSON.stringify( core.__experimentalGetDirtyEntityRecords() ); }
	var before = dirty();
	var cases = [
		{ name: 'core/heading', attributes: {
			content: 'Keep <em>formatting</em>', level: 2, fontFamily: 'oooh-baby',
			style: { typography: { fontSize: '2rem', fontWeight: '700', textAlign: 'center' }, spacing: { padding: { top: '2rem', right: '1rem', bottom: '2rem', left: '1rem' } }, border: { radius: { topLeft: '50%', topRight: '0px', bottomLeft: '0px', bottomRight: '50%' } } },
			claraVe: { responsive: { mobile: { 'typography.fontSize': '18px' } }, ornaments: { before: { content: '“', hidden: true } } }
		}, contains: [ 'has-oooh-baby-font-family', 'has-text-align-center', 'font-size:2rem', 'border-top-left-radius:50%', 'Keep <em>formatting</em>' ] },
		{ name: 'core/paragraph', attributes: { content: 'Text', gradient: 'navy-wash' }, contains: [ 'has-navy-wash-gradient-background' ] },
		{ name: 'core/paragraph', attributes: { content: '<span class="cve-ornament" style="font-size:2em;">“</span>Keep <strong>formatting</strong>', claraVe: { ornaments: { before: { content: '“', hidden: true } } } }, contains: [ 'cve-ornament', 'font-size:2em', '<strong>formatting</strong>' ] },
		{ name: 'core/navigation-link', attributes: { label: 'Test', url: '#test', opensInNewTab: true }, contains: [ '"opensInNewTab":true' ] }
	];
	var checks = cases.map( function ( entry ) {
		var block = wp.blocks.createBlock( entry.name, entry.attributes );
		var serialized = wp.blocks.serialize( [ block ] );
		var restored = wp.blocks.parse( serialized )[0];
		return {
			name: entry.name,
			valid: !! ( restored && restored.isValid ),
			extrasPreserved: JSON.stringify( restored && restored.attributes.claraVe || {} ) === JSON.stringify( entry.attributes.claraVe || {} ),
			markupPreserved: entry.contains.every( function ( part ) { return serialized.indexOf( part ) !== -1; } )
		};
	} );
	return {
		checks: checks,
		documentUntouched: dirty() === before,
		formatRegistered: !! wp.data.select( 'core/rich-text' ).getFormatType( 'clara-ve/ornament' ),
		attributeRegistered: !! wp.blocks.getBlockType( 'core/heading' ).attributes.claraVe
	};
}
