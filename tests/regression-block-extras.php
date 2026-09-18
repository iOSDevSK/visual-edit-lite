<?php
/** Run in a disposable WordPress with VE Lite active (wp eval-file). */
defined( 'ABSPATH' ) || exit( 1 );

$check = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
// Print what is queued the way WordPress prints it, so the assertions below
// read the page rather than the class's private state. A printed handle is
// "done", which is what makes the next render open a new batch.
$print_styles = static function () {
	ob_start();
	wp_styles()->do_items();
	return ob_get_clean();
};
$extras = array(
	'responsive' => array( 'mobile' => array( 'typography.fontSize' => '18px', 'display' => 'none' ) ),
	'ornaments' => array( 'before' => array( 'content' => '“', 'color' => '#ffffff' ), 'after' => array( 'content' => '”', 'font-size' => '24px' ) ),
);
$clean = Clara_VE_Block_Extras::clean( $extras );
$check( $clean === $extras, 'Both ornaments and responsive values must survive cleaning.' );
$bad = Clara_VE_Block_Extras::clean( array( 'responsive' => array( 'mobile' => array( 'typography.fontSize' => '1px;}body{display:none' ) ), 'ornaments' => array( 'before' => array( 'color' => 'red;</style><script>alert(1)</script>' ) ) ) );
$check( array() === $bad, 'Unsafe CSS must be rejected.' );
$block = array( 'blockName' => 'core/paragraph', 'attrs' => array( 'claraVe' => $extras ) );
$source = '<p>A <strong>formatted</strong> sentence.</p>';
$first = Clara_VE_Block_Extras::render( $source, $block );
$check( false !== strpos( $first, 'cve-r-' ), 'Rendered block needs its stylesheet target.' );
$check( false !== strpos( $first, '<strong>formatted</strong>' ), 'Rendering must preserve inline content.' );
$check( $first === Clara_VE_Block_Extras::render( $source, $block ), 'Equal copies share the same harmless generated selector.' );
$block['attrs']['claraVe']['responsive']['mobile']['typography.fontSize'] = '20px';
$second = Clara_VE_Block_Extras::render( $source, $block );
$check( $first !== $second, 'Editing a duplicate must give it a different selector.' );
$check( $source === Clara_VE_Block_Extras::render( $source, array() ), 'Blocks without extras must remain byte-identical.' );
$block['attrs']['claraVe']['ornaments']['before']['hidden'] = true;
$promoted = Clara_VE_Block_Extras::render( $source, $block );
$css = $print_styles();
$check( false !== strpos( $css, 'content:"" !important;display:none !important;' ), 'Promoted ornaments must not render twice.' );
$check( false !== strpos( $css, 'clara-ve-block-extras-1' ), 'The first batch is printed under its own handle.' );
Clara_VE_Block_Extras::render( $source, $block );
$css = $print_styles();
$check( false !== strpos( $css, 'clara-ve-block-extras-2' ), 'A block rendered after its batch was printed opens the next one, or its rules would never reach the page.' );
$registered = WP_Block_Type_Registry::get_instance()->get_registered( 'core/paragraph' );
$check( isset( $registered->attributes['claraVe'] ), 'The server must register the extension attribute before client block registration.' );

// The front-end block editor keeps writing into the attribute a block already uses.
if ( class_exists( 'Clara_VE_Block_Patch' ) ) {
	$method = new ReflectionMethod( 'Clara_VE_Block_Patch', 'set_responsive' );
	$method->setAccessible( true );
	$workspaceBlock = array( 'blockName' => 'core/paragraph', 'attrs' => array( 'className' => 'lead', 'claraVe' => array( 'responsive' => array( 'mobile' => array( 'typography.fontSize' => '18px' ) ), 'ornaments' => array( 'before' => array( 'content' => '“' ) ) ) ), 'innerHTML' => '<p class="lead">x</p>', 'innerContent' => array( '<p class="lead">x</p>' ), 'innerBlocks' => array() );
	$result = $method->invokeArgs( null, array( &$workspaceBlock, 42, array( 'breakpoint' => 'tablet', 'path' => 'spacing.padding.top', 'value' => '12px' ) ) );
	$check( true === $result, 'Attribute-backed responsive write succeeds without touching page meta.' );
	$check( '12px' === $workspaceBlock['attrs']['claraVe']['responsive']['tablet']['spacing.padding.top'], 'The new value lands in claraVe.responsive.' );
	$check( '18px' === $workspaceBlock['attrs']['claraVe']['responsive']['mobile']['typography.fontSize'], 'Existing values stay.' );
	$check( 'lead' === $workspaceBlock['attrs']['className'], 'No legacy anchor class is added.' );
	$check( '“' === $workspaceBlock['attrs']['claraVe']['ornaments']['before']['content'], 'Ornaments are untouched.' );
	$check( '<p class="lead">x</p>' === $workspaceBlock['innerHTML'], 'Markup is untouched.' );
	$refused = $method->invokeArgs( null, array( &$workspaceBlock, 42, array( 'breakpoint' => 'mobile', 'path' => 'typography.fontSize', 'value' => '1px;}body{x' ) ) );
	$check( is_wp_error( $refused ) && '18px' === $workspaceBlock['attrs']['claraVe']['responsive']['mobile']['typography.fontSize'], 'Unsafe values are refused and nothing changes.' );
	$method->invokeArgs( null, array( &$workspaceBlock, 42, array( 'breakpoint' => 'mobile', 'path' => 'typography.fontSize', 'value' => '' ) ) );
	$method->invokeArgs( null, array( &$workspaceBlock, 42, array( 'breakpoint' => 'tablet', 'path' => 'spacing.padding.top', 'value' => '' ) ) );
	$check( ! isset( $workspaceBlock['attrs']['claraVe']['responsive'] ) && isset( $workspaceBlock['attrs']['claraVe']['ornaments'] ), 'Clearing the last value removes only the responsive map.' );
}
// Form styling: semantic selectors, a wrapper where the output has no element of its own.
$form = array( 'label' => array( 'color' => '#b03a2e' ), 'button' => array( 'background-color' => 'var:preset|color|accent' ) );
$shortcode = Clara_VE_Block_Extras::render( "<p>[contact_form]</p>\n", array( 'blockName' => 'core/shortcode', 'attrs' => array( 'claraVe' => array( 'form' => $form ) ) ) );
$check( 1 === preg_match( '~^<div class="cve-r-[a-f0-9]{16}"><p>\[contact_form\]</p>\n</div>$~', $shortcode ), 'A shortcode block is wrapped so the class survives shortcode expansion.' );
$html = Clara_VE_Block_Extras::render( '<label>A</label><input><button>Go</button>', array( 'blockName' => 'core/html', 'attrs' => array( 'claraVe' => array( 'form' => $form ) ) ) );
$check( 0 === strpos( $html, '<div class="cve-r-' ) && false !== strpos( $html, '<label>A</label>' ), 'An HTML block with several root nodes is wrapped.' );
$rooted = Clara_VE_Block_Extras::render( '<div class="wpforms-container"><form></form></div>', array( 'blockName' => 'wpforms/form-selector', 'attrs' => array( 'claraVe' => array( 'form' => $form ) ) ) );
$check( 1 === preg_match( '~^<div class="wpforms-container cve-r-[a-f0-9]{16}">~', $rooted ), 'A form block with its own root element gets the class, not a wrapper.' );
$styled = Clara_VE_Block_Extras::render( '<style>.x{}</style><div class="f"></div>', array( 'blockName' => 'acme/form', 'attrs' => array( 'claraVe' => array( 'form' => $form ) ) ) );
$check( 0 === strpos( $styled, '<div class="cve-r-' ), 'Output starting with a style tag is wrapped rather than classing the style tag.' );
$bare = Clara_VE_Block_Extras::render( '[contact-form-7 id="1"]', array( 'blockName' => 'contact-form-7/contact-form-selector', 'attrs' => array( 'claraVe' => array( 'form' => $form ) ) ) );
$check( 0 === strpos( $bare, '<div class="cve-r-' ), 'A block that renders a bare shortcode is wrapped.' );
$form_css = $print_styles();
$check( false !== strpos( $form_css, ':is(label, legend){color:#b03a2e !important;}' ) && false !== strpos( $form_css, 'background-color:var(--wp--preset--color--accent) !important;' ), 'Form rules reach the page stylesheet with presets expanded.' );
$refused = Clara_VE_Block_Extras::render( '<p>[x]</p>', array( 'blockName' => 'core/shortcode', 'attrs' => array( 'claraVe' => array( 'form' => array( 'label' => array( 'color' => 'red;}body{display:none' ), 'field' => array( 'position' => 'fixed' ) ) ) ) ) );
$check( '<p>[x]</p>' === $refused, 'Unsafe or unknown form values leave the block untouched.' );
$check( array() === Clara_VE_Block_Extras::clean( array( 'form' => 'nope' ) ), 'A malformed form map is dropped.' );
$unchanged = Clara_VE_Block_Extras::render( '<p>Text</p>', array( 'blockName' => 'core/paragraph', 'attrs' => array( 'claraVe' => array( 'ornaments' => array( 'before' => array( 'content' => '“' ) ) ) ) ) );
$check( 1 === preg_match( '~^<p class="cve-r-[a-f0-9]{16}">Text</p>$~', $unchanged ), 'Blocks without form styling keep the class on their own element.' );
echo "PASS: block extras — sanitizing, shared entities, copy isolation, markup, schema and attribute-backed responsive writes and form styling\n";
