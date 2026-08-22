<?php
/**
 * Regression: the style writer keeps a block's attributes and its markup
 * saying the same thing.
 *
 * A block's appearance lives in two places — attributes inside the delimiter,
 * and the class/style attributes the block type's own save() bakes into the
 * stored HTML. Change one without the other and the page either looks
 * unchanged or opens in Gutenberg as invalid content. Everything here is
 * about those two halves agreeing.
 *
 * What this file cannot answer is whether Gutenberg agrees; only Gutenberg
 * can, which is what tools/e2e-style-matrix.py is for. These are the checks
 * that would otherwise need a browser to notice.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-block-supports.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Block_Supports' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

/** A fresh single block to work on. */
$make = static function ( $name, $html, $attrs = array() ) {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => $html,
		'innerContent' => array( $html ),
	);
};

$block_mode = ! clara_ve_active_theme_is_ours();
echo 'theme: ' . get_stylesheet() . ' — ' . ( $block_mode ? "block mode\n" : "raw-HTML mode\n" ) . "\n";

echo "--- custom values reach both halves ---\n";

$p = $make( 'core/paragraph', '<p>Quiet.</p>' );
Clara_VE_Block_Supports::apply_style(
	$p,
	array(
		'typography' => array( 'fontSize' => '24px', 'lineHeight' => '1.4' ),
		'color'      => array( 'text' => '#4a4038' ),
		'spacing'    => array( 'padding' => array( 'top' => 'var:preset|spacing|50' ) ),
	)
);
$check( 'the value is stored as an attribute', '24px' === $p['attrs']['style']['typography']['fontSize'] );
$check( 'and reaches the markup as CSS', false !== strpos( $p['innerHTML'], 'font-size:24px' ) );
$check( 'a spacing preset is expanded to its custom property', false !== strpos( $p['innerHTML'], 'var(--wp--preset--spacing--50)' ) );
$check( 'a custom colour brings the generic class', false !== strpos( $p['innerHTML'], 'has-text-color' ) );
$check( 'but NOT a slug class, which would name a preset that does not exist', ! preg_match( '~has-[a-z0-9-]+-color~', str_replace( 'has-text-color', '', $p['innerHTML'] ) ) );
$check( 'the words are untouched', false !== strpos( $p['innerHTML'], '>Quiet.<' ) );

echo "\n--- clearing takes both halves away ---\n";

Clara_VE_Block_Supports::apply_style( $p, array( 'typography' => array( 'fontSize' => null ) ) );
$check( 'one cleared property leaves the markup', false === strpos( $p['innerHTML'], 'font-size' ) );
$check( 'and the others stay', false !== strpos( $p['innerHTML'], 'line-height:1.4' ) );

Clara_VE_Block_Supports::apply_style(
	$p,
	array(
		'typography' => array( 'lineHeight' => null ),
		'color'      => array( 'text' => null ),
		'spacing'    => array( 'padding' => array( 'top' => null ) ),
	)
);
// An empty group left behind is not what the editor would have written, and
// the difference only surfaces as a block refusing to open months later.
$check( 'clearing everything removes the style attribute entirely', false === strpos( $p['innerHTML'], 'style=' ) );
$check( 'and the attribute, rather than leaving an empty one', ! isset( $p['attrs']['style'] ) );
$check( 'and the class it implied', false === strpos( $p['innerHTML'], 'has-text-color' ) );

echo "\n--- a preset and a custom value are one decision, not two ---\n";

$q = $make( 'core/paragraph', '<p>Quiet.</p>', array( 'textColor' => 'accent' ) );
Clara_VE_Block_Supports::sync( $q );
$check( 'a preset writes its own class and the generic one', false !== strpos( $q['innerHTML'], 'has-accent-color' ) && false !== strpos( $q['innerHTML'], 'has-text-color' ) );

Clara_VE_Block_Supports::apply_style( $q, array( 'color' => array( 'text' => '#c0ffee' ) ) );
$check( 'setting a custom colour drops the preset attribute', ! isset( $q['attrs']['textColor'] ) );
$check( 'and its class', false === strpos( $q['innerHTML'], 'has-accent-color' ) );
$check( 'while the custom value is in the markup', false !== strpos( $q['innerHTML'], 'color:#c0ffee' ) );

echo "\n--- where each block carries its styling ---\n";

$button = $make(
	'core/button',
	'<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/x/">Enquire</a></div>'
);
Clara_VE_Block_Supports::apply_style( $button, array( 'typography' => array( 'fontSize' => '18px' ) ) );
// A button's wrapper carries nothing; everything lands on the <a>. Writing to
// the wrapper would render nothing and invalidate the block at the same time.
$check( 'a button styles its link, not its wrapper', (bool) preg_match( '~<a[^>]*style="[^"]*font-size:18px~', $button['innerHTML'] ) );
$check( 'the wrapper div is left alone', (bool) preg_match( '~<div class="wp-block-button">~', $button['innerHTML'] ) );
// core/button's own save() marks any explicit size this way — a block whose
// classes disagree with its save() is the definition of invalid content.
$check( 'and any explicit size is marked the way the block marks it', false !== strpos( $button['innerHTML'], 'has-custom-font-size' ) );

echo "\n--- text alignment goes where the block declares it ---\n";

// Read from core's own block.json: paragraph/heading/button declare
// supports.typography.textAlign and have NO textAlign attribute; core/quote
// declares the attribute and no support.
$para     = $make( 'core/paragraph', '<p>Quiet.</p>' );
$set_attrs = new ReflectionMethod( 'Clara_VE_Block_Patch', 'set_attrs' );
$set_attrs->setAccessible( true );
$args = array( &$para, array( 'textAlign' => 'center' ) );
$set_attrs->invokeArgs( null, $args );
$check( 'a paragraph stores alignment as a typography style', 'center' === ( $para['attrs']['style']['typography']['textAlign'] ?? null ) );
$check( 'not as a top-level attribute the block does not declare', ! isset( $para['attrs']['textAlign'] ) );
$check( 'and the class is there either way', false !== strpos( $para['innerHTML'], 'has-text-align-center' ) );

echo "\n--- a container keeps what is inside it ---\n";

// The failure this guards against is silent and total: a block with children
// stores its markup in pieces, with a null where each child goes, and
// innerHTML is only the pieces that are really there. A group holding a
// paragraph therefore READS as <div class="wp-block-group"></div> — an empty
// element — and writing that back over the whole of innerContent serializes a
// group with nothing in it. The page loses a column of content to a padding
// change, and nothing anywhere reports an error.
$group = array(
	'blockName'    => 'core/group',
	'attrs'        => array(),
	'innerBlocks'  => array( $make( 'core/paragraph', '<p>Inside.</p>' ) ),
	'innerHTML'    => '<div class="wp-block-group"></div>',
	'innerContent' => array( '<div class="wp-block-group">', null, '</div>' ),
);
Clara_VE_Block_Supports::apply_style(
	$group,
	array(
		'spacing' => array( 'padding' => array( 'top' => '48px' ) ),
		'color'   => array( 'background' => '#efe9e1' ),
	)
);
$serialized = serialize_blocks( array( $group ) );
$check( 'the child block is still there after styling its container', false !== strpos( $serialized, 'Inside.' ) );
$check( 'and still as a block, not as flattened markup', false !== strpos( $serialized, '<!-- wp:paragraph -->' ) );
$check( 'the placeholder for it survives', 3 === count( $group['innerContent'] ) && null === $group['innerContent'][1] );
$check( 'the styling landed on the opening tag', false !== strpos( $group['innerContent'][0], 'padding-top:48px' ) );
$check( 'and the closing tag was not duplicated into it', false === strpos( $group['innerContent'][0], '</div>' ) );
$check( 'a background colour brings its class', false !== strpos( $group['innerContent'][0], 'has-background' ) );

// details is the shape where both halves matter at once: the question is
// markup in the first piece, the answer is child blocks after it.
$details = array(
	'blockName'    => 'core/details',
	'attrs'        => array(),
	'innerBlocks'  => array( $make( 'core/paragraph', '<p>Yes, always.</p>' ) ),
	'innerHTML'    => '<details class="wp-block-details"><summary>Old question</summary></details>',
	'innerContent' => array( '<details class="wp-block-details"><summary>Old question</summary>', null, '</details>' ),
);
$set_text = new ReflectionMethod( 'Clara_VE_Block_Patch', 'set_text' );
$set_text->setAccessible( true );
$args = array( &$details, 'Do you travel?' );
$set_text->invokeArgs( null, $args );
$check( 'a details block\'s summary is what its text edit changes', false !== strpos( $details['innerContent'][0], '<summary>Do you travel?</summary>' ) );
$check( 'and the answer inside it is untouched', ! empty( $details['innerBlocks'] ) && false !== strpos( serialize_blocks( array( $details ) ), 'Yes, always.' ) );

echo "\n--- what each capability may be asked for ---\n";

// A capability says a block may be edited, not how. set-text on a container
// would reach the text writer, which replaces everything between a block's
// tags — every child, in one save.
$page_with_group = wp_insert_post( array(
	'post_type'    => 'page',
	'post_title'   => 'Clara VE capability probe',
	'post_status'  => 'draft',
	'post_content' => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inside.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
) );
$refused = Clara_VE_Block_Patch::apply( $page_with_group, array( array( 'block' => '0', 'op' => 'set-text', 'html' => 'x' ) ) );
$check( 'a container refuses a text edit', is_wp_error( $refused ) );

$styled = Clara_VE_Block_Patch::apply( $page_with_group, array(
	array( 'block' => '0', 'op' => 'set-style', 'style' => array( 'spacing' => array( 'padding' => array( 'top' => '32px' ) ) ) ),
) );
if ( $block_mode ) {
	$check( 'but accepts a styling one', ! is_wp_error( $styled ) );
	$check( 'and its child survives the round trip through storage', is_string( $styled ) && false !== strpos( $styled, 'Inside.' ) );
} else {
	// Under a raw-HTML theme the block driver owns nothing, so the refusal
	// above was never about capabilities — every patch is refused before it
	// reaches them. Asserting it here is the point: this file must not become
	// the one that quietly starts writing to a legacy theme's pages.
	$check( 'and under a raw-HTML theme a styling patch is refused too', is_wp_error( $styled ) );
	$check( 'with nothing written', false === strpos( (string) get_post_field( 'post_content', $page_with_group ), 'padding-top' ) );
}
wp_delete_post( $page_with_group, true );

echo "\n--- everything the panel can offer can also be stored ---\n";

// The fault this catches is silent by construction. The panel builds itself
// from SUPPORT_FLAG, and the writer accepts values from a separate list in
// sanitize_style(). A path in the first and not the second is not refused —
// it is DROPPED, so the control appears, accepts a value, saves without
// complaint, and changes nothing. typography.fontFamily was exactly that:
// choosing a typeface of one's own did nothing at all, and nothing said why.
$representative = array(
	'border.radius'             => '8px',
	'border.width'              => '2px',
	'border.style'              => 'solid',
	'border.color'              => '#4a4038',
	'shadow'                    => 'var:preset|shadow|natural',
	'color.gradient'            => 'linear-gradient(135deg,#4a4038 0%,#efe9e1 100%)',
	'dimensions.minHeight'      => '400px',
	'dimensions.aspectRatio'    => '16/9',
	'spacing.blockGap'          => '2rem',
	'position.type'             => 'sticky',
	'typography.fontFamily'     => 'Georgia, serif',
	'typography.fontSize'       => '28px',
	'typography.lineHeight'     => '1.4',
	'typography.textAlign'      => 'center',
	'typography.fontWeight'     => '600',
	'typography.fontStyle'      => 'italic',
	'typography.textTransform'  => 'uppercase',
	'typography.textDecoration' => 'underline',
	'typography.letterSpacing'  => '0.05em',
	'color.text'                => '#4a4038',
	'color.background'          => '#efe9e1',
	'spacing.padding'           => '24px',
	'spacing.margin'            => '12px',
);
// Sets, not sequences: the order these are declared in means nothing, but a
// property added to one list and not the other means this test stops covering
// it — silently, which is the failure mode being guarded against.
$offered = array_keys( Clara_VE_Block_Supports::SUPPORT_FLAG );
$tried   = array_keys( $representative );
sort( $offered );
sort( $tried );
$check( 'every offered property has a value the test knows to try', $offered === $tried );

foreach ( $representative as $path => $value ) {
	// A block that supports the property, so the support gate is not what is
	// being measured here. core/cover is the only one declaring an aspect
	// ratio; everything else a group can do.
	$name = 'core/paragraph';
	if ( 0 === strpos( $path, 'spacing.' ) || 0 === strpos( $path, 'border.' )
		|| 'shadow' === $path || 0 === strpos( $path, 'dimensions.' )
		|| 0 === strpos( $path, 'position.' ) || 'color.gradient' === $path ) {
		$name = 'core/group';
	}
	if ( 'dimensions.aspectRatio' === $path ) {
		$name = 'core/cover';
	}
	$html = array(
		'core/paragraph' => '<p>Quiet.</p>',
		'core/group'     => '<div class="wp-block-group"></div>',
		'core/cover'     => '<div class="wp-block-cover"></div>',
	)[ $name ];
	$probe = $make( $name, $html );

	$style = array();
	$steps = explode( '.', $path );
	if ( 1 === count( $steps ) ) {
		$style[ $steps[0] ] = $value;
	} elseif ( 'spacing' === $steps[0] && 'blockGap' !== $steps[1] ) {
		$style['spacing'] = array( $steps[1] => array( 'top' => $value ) );
	} else {
		$style[ $steps[0] ] = array( $steps[1] => $value );
	}

	$applied = Clara_VE_Block_Supports::apply_style( $probe, $style );
	$stored  = ! is_wp_error( $applied ) && ! empty( $probe['attrs']['style'] );
	$check( $path . ' is stored rather than dropped', $stored );

	// Three of these are stored and DELIBERATELY absent from the markup —
	// core's own save() writes nothing for them, and writing a declaration
	// anyway is one entry too many in the style map. For everything else,
	// silence in the markup means the value went nowhere.
	if ( in_array( $path, array( 'spacing.blockGap', 'dimensions.aspectRatio', 'position.type' ), true ) ) {
		$check( $path . ' is kept OUT of the markup, as core keeps it', $probe['innerHTML'] === $html );
	} else {
		// Not "has a style attribute": alignment reaches the page as a CLASS
		// and no declaration at all. What matters is that the markup moved.
		$check( $path . ' reaches the markup', $probe['innerHTML'] !== $html );
	}
}

echo "\n--- only what the block actually supports ---\n";

// These answers come from the block registry, not from a list kept here, and
// they are not guessable: a column has padding but no margin, a spacer has
// neither colour nor typography. Writing one anyway produces markup the
// block's own save() would never emit.
$check( 'a column has padding', Clara_VE_Block_Supports::supports( 'core/column', 'spacing.padding.top' ) );
$check( 'but no margin', ! Clara_VE_Block_Supports::supports( 'core/column', 'spacing.margin.top' ) );
$check( 'a paragraph has a text colour it never declares', Clara_VE_Block_Supports::supports( 'core/paragraph', 'color.text' ) );
$check( 'an image, which declares it off, does not', ! Clara_VE_Block_Supports::supports( 'core/image', 'color.text' ) );
$check( 'a spacer has no typography', ! Clara_VE_Block_Supports::supports( 'core/spacer', 'typography.fontSize' ) );

$column = $make( 'core/column', '<div class="wp-block-column"></div>' );
$check(
	'and a margin on a column is refused rather than written',
	is_wp_error( Clara_VE_Block_Supports::apply_style( $column, array( 'spacing' => array( 'margin' => array( 'top' => '12px' ) ) ) ) )
);

// core/separator styles its colour by a recipe of its own. The validity
// matrix rejected the custom spelling outright and caught the preset one
// passing only by matching a DEPRECATED save — a block WordPress migrates
// silently, losing the attribute. Both spellings are cut; margin stays.
$check( 'a separator declares a background colour', ! empty( WP_Block_Type_Registry::get_instance()->get_registered( 'core/separator' )->supports['color']['background'] ) );
$check( 'but this editor does not offer it', ! Clara_VE_Block_Supports::supports( 'core/separator', 'color.background' ) );
$check( 'while its margin is still offered', Clara_VE_Block_Supports::supports( 'core/separator', 'spacing.margin.top' ) );

$separator = $make( 'core/separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
$set_attrs_ref = new ReflectionMethod( 'Clara_VE_Block_Patch', 'set_attrs' );
$set_attrs_ref->setAccessible( true );
$sep_args = array( &$separator, array( 'backgroundColor' => 'band' ) );
$check(
	'the preset spelling is refused too, not just the custom one',
	is_wp_error( $set_attrs_ref->invokeArgs( null, $sep_args ) )
);
$check( 'so nothing was written', ! isset( $separator['attrs']['backgroundColor'] ) );

// A spacer at its default height stores no height attribute at all — the
// 100px lives only in the markup. Styling it must not take that away.
$spacer = $make( 'core/spacer', '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>' );
Clara_VE_Block_Supports::apply_style( $spacer, array( 'spacing' => array( 'margin' => array( 'top' => '12px' ) ) ) );
$check( 'a spacer keeps its height when its margin changes', false !== strpos( $spacer['innerHTML'], 'height:100px' ) );
$check( 'and gains the margin', false !== strpos( $spacer['innerHTML'], 'margin-top:12px' ) );

// core/cover declares its background support as FALSE — the picture behind it
// is an overlay set another way entirely — while declaring the typography,
// text colour and padding it really does have. Reading the registry gets this
// right without anybody having to know it.
$check( 'a cover has padding', Clara_VE_Block_Supports::supports( 'core/cover', 'spacing.padding.top' ) );
$check( 'and a text colour', Clara_VE_Block_Supports::supports( 'core/cover', 'color.text' ) );
$check( 'but no background, which is its overlay and not ours to set', ! Clara_VE_Block_Supports::supports( 'core/cover', 'color.background' ) );

echo "\n--- movement, and the class it is stored as ---\n";

// Movement is deliberately NOT a new kind of stored thing. It is a CSS class,
// which every block type already understands, so a page carrying it stays
// ordinary WordPress content: valid in Gutenberg, and still rendering with
// this plugin switched off — it simply stops moving.
$moved = $make( 'core/group', '<div class="wp-block-group keep-me"></div>' );
$moved['attrs']['className'] = 'keep-me';
$set_move = new ReflectionMethod( 'Clara_VE_Block_Patch', 'set_attrs' );
$set_move->setAccessible( true );
$move_args = array( &$moved, array( 'veAnimation' => 'fade-up', 'veHover' => 'lift' ) );
$set_move->invokeArgs( null, $move_args );

$check( 'a scroll effect is stored as a class', false !== strpos( (string) $moved['attrs']['className'], 'cve-anim-fade-up' ) );
$check( 'a hover effect too', false !== strpos( (string) $moved['attrs']['className'], 'cve-hover-lift' ) );
// Both in one call is the case that used to lose the first of them.
$check( 'and both survive being set together', false !== strpos( $moved['innerHTML'], 'cve-anim-fade-up' ) && false !== strpos( $moved['innerHTML'], 'cve-hover-lift' ) );
$check( 'the owner\'s own class is untouched', false !== strpos( $moved['innerHTML'], 'keep-me' ) );

$move_args = array( &$moved, array( 'veAnimation' => 'zoom' ) );
$set_move->invokeArgs( null, $move_args );
$check( 'changing it replaces rather than accumulates', false === strpos( $moved['innerHTML'], 'cve-anim-fade-up' ) && false !== strpos( $moved['innerHTML'], 'cve-anim-zoom' ) );
$check( 'and leaves the hover alone', false !== strpos( $moved['innerHTML'], 'cve-hover-lift' ) );

$move_args = array( &$moved, array( 'veAnimation' => '', 'veHover' => '' ) );
$set_move->invokeArgs( null, $move_args );
$check( 'clearing takes both away', false === strpos( $moved['innerHTML'], 'cve-anim-' ) && false === strpos( $moved['innerHTML'], 'cve-hover-' ) );
$check( 'and still leaves the owner\'s class', false !== strpos( $moved['innerHTML'], 'keep-me' ) );

$move_args = array( &$moved, array( 'veAnimation' => 'explode' ) );
$check( 'a movement this editor does not offer is refused', is_wp_error( $set_move->invokeArgs( null, $move_args ) ) );

// A button keeps its own classes on its <a> and the owner's on the wrapper,
// which is where core's save() puts them. Writing both to one element is a
// block Gutenberg refuses to open.
$btn = $make( 'core/button', '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/x/">Enquire</a></div>' );
$move_args = array( &$btn, array( 'veAnimation' => 'fade-up' ) );
$set_move->invokeArgs( null, $move_args );
$check( 'a button carries movement on its wrapper', (bool) preg_match( '~<div class="wp-block-button cve-anim-fade-up">~', $btn['innerHTML'] ) );
$move_args = array( &$btn, array( 'textColor' => 'accent' ) );
$set_move->invokeArgs( null, $move_args );
$check( 'and its colour on the link inside', (bool) preg_match( '~<a[^>]*has-accent-color~', $btn['innerHTML'] ) );
$check( 'with the movement still on the wrapper', (bool) preg_match( '~<div class="wp-block-button cve-anim-fade-up">~', $btn['innerHTML'] ) );

echo "\n--- an added class reaches the page ---\n";

// Storing className without echoing it into the markup was a real fault: the
// block's own save() writes it into the class list, so the two halves
// disagreed and Gutenberg would have called such a block invalid.
$classed = $make( 'core/group', '<div class="wp-block-group"></div>' );
$class_args = array( &$classed, array( 'className' => 'my-thing' ) );
$set_move->invokeArgs( null, $class_args );
$check( 'a class added by hand is written into the markup', false !== strpos( $classed['innerHTML'], 'my-thing' ) );
$class_args = array( &$classed, array( 'className' => 'other-thing' ) );
$set_move->invokeArgs( null, $class_args );
$check( 'changing it removes the old one', false === strpos( $classed['innerHTML'], 'my-thing' ) && false !== strpos( $classed['innerHTML'], 'other-thing' ) );
$class_args = array( &$classed, array( 'className' => '' ) );
$set_move->invokeArgs( null, $class_args );
$check( 'and clearing it leaves the block as it was', '<div class="wp-block-group"></div>' === trim( $classed['innerHTML'] ) );

echo "\n--- what it refuses ---\n";

$bad = $make( 'core/paragraph', '<p>Quiet.</p>' );
$check(
	'a colour that is not a colour is refused',
	is_wp_error( Clara_VE_Block_Supports::apply_style( $bad, array( 'color' => array( 'text' => 'red; } body {' ) ) ) )
);
$check(
	'a length that is not a length is refused',
	is_wp_error( Clara_VE_Block_Supports::apply_style( $bad, array( 'typography' => array( 'fontSize' => 'javascript:1' ) ) ) )
);
$check( 'and neither reached the markup', false === strpos( $bad['innerHTML'], 'style=' ) );

$ignored = $make( 'core/paragraph', '<p>Quiet.</p>' );
Clara_VE_Block_Supports::apply_style( $ignored, array( 'border' => array( 'radius' => '4px' ), 'shadow' => 'natural' ) );
$check( 'a property this version does not claim is dropped, not stored', ! isset( $ignored['attrs']['style'] ) );

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: block style writer — ' . get_stylesheet() . "\n";
