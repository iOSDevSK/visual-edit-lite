<?php
/**
 * Regression: Custom HTML becomes native blocks, written the way WordPress's
 * own save() writes them, and everything without a native block stays exactly
 * as it was.
 *
 * Each converted fixture must pass the block gate, be canonical (parse and
 * serialize round-trip unchanged) and convert to itself a second time. What the
 * EDITOR makes of the output — the only real proof that the markup matches
 * save() — is checked in the browser by tools/e2e-workspace steps; this file
 * pins the shapes that proof was taken against.
 *
 *   php tools/run-in-wp.php /path/to/wordpress tests/regression-block-convert.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Block_Convert' ) && file_exists( dirname( __DIR__ ) . '/includes/class-block-convert.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-block-convert.php';
}
if ( ! class_exists( 'Clara_VE_Block_Convert' ) || ! class_exists( 'Clara_VE_Block_Gate' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

/** Convert, and assert what every conversion must hold. */
$convert = static function ( $label, $markup, $args = array() ) use ( $check ) {
	$result = Clara_VE_Block_Convert::convert_document( $markup, $args );
	$again  = Clara_VE_Block_Convert::convert_document( $result['markup'], $args );
	$check( $label . ': passes the block gate', true === Clara_VE_Block_Gate::check( $result['markup'], null, array( 'context' => 'create' ) ) );
	$check( $label . ': canonical', Clara_VE_Block_Gate::is_idempotent( $result['markup'] ) );
	$check( $label . ': converting again changes nothing', $again['markup'] === $result['markup'] && ! $again['changed'] );
	return $result;
};

$names = static function ( $markup ) {
	$out  = array();
	$walk = static function ( $blocks ) use ( &$walk, &$out ) {
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] ) {
				$out[] = $block['blockName'];
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $markup ) );
	return array_count_values( $out );
};

$html = static function ( $inner ) {
	return "<!-- wp:html -->\n" . $inner . "\n<!-- /wp:html -->";
};

$text_align_support = static function ( $name ) {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
	return $type && ! empty( $type->supports['typography']['textAlign'] );
};

echo 'theme: ' . get_stylesheet() . "\n\n--- an FAQ held in Custom HTML (a theme pattern's shape) ---\n";

$faq = "<!-- wp:group {\"className\":\"site-faq\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group site-faq\">\n"
	. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Common questions</h2>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:html -->\n<details>\n\t<summary>Does it need JavaScript?</summary>\n\t<p>No. It uses <strong>native</strong> details.</p>\n</details>\n"
	. "<details>\n\t<summary>Where does schema live?</summary>\n\t<p>In a plugin.</p>\n</details>\n"
	. "<details>\n\t<summary>Page builders?</summary>\n\t<p>Yes.</p>\n</details>\n<!-- /wp:html -->\n</div>\n<!-- /wp:group -->";

$result = $convert( 'faq', $faq );
$count  = $names( $result['markup'] );
$check( 'faq: three details blocks', 3 === ( $count['core/details'] ?? 0 ) );
$check( 'faq: each answer is a paragraph', 3 === ( $count['core/paragraph'] ?? 0 ) );
$check( 'faq: no Custom HTML left', ! isset( $count['core/html'] ) );
$check( 'faq: the heading and group around it are untouched', 1 === ( $count['core/heading'] ?? 0 ) && false !== strpos( $result['markup'], 'site-faq' ) );
$check( 'faq: the report names what it made', 3 === count( array_keys( $result['converted'], 'core/details', true ) ) && 0 === $result['kept_html'] );
$check( 'faq: details saved as the block writes it', false !== strpos( $result['markup'], '<details class="wp-block-details"><summary>Does it need JavaScript?</summary>' ) );
$texts = Clara_VE_Block_Convert::texts( $result['markup'] );
$check( 'texts: the questions, in order', array_slice( $texts, 0, 2 ) === array( 'Common questions', 'Does it need JavaScript?' ) );
$check( 'texts: a string broken by formatting is left out', ! in_array( 'No. It uses', $texts, true ) && ! in_array( 'No. It uses <strong>native</strong> details.', $texts, true ) );
$check( 'texts: every string can be quoted back as a find', ! array_filter( $texts, static function ( $text ) use ( $result ) {
	return false === strpos( $result['markup'], $text );
} ) );

echo "\n--- headings and paragraphs ---\n";

$result = $convert( 'headings', $html( '<h1 class="hero has-text-align-center" id="top">Welcome <em>home</em></h1><h3>Sub &amp; more</h3><h2>Plain</h2>' ) );
$blocks = array_values( array_filter( parse_blocks( $result['markup'] ), static function ( $b ) {
	return null !== $b['blockName'];
} ) );
$check( 'heading: level kept, 2 is the default', 1 === $blocks[0]['attrs']['level'] && 3 === $blocks[1]['attrs']['level'] && ! isset( $blocks[2]['attrs']['level'] ) );
$check( 'heading: class and id become className and anchor', 'hero' === $blocks[0]['attrs']['className'] && 'top' === $blocks[0]['attrs']['anchor'] );
$check(
	'heading: alignment written the way this WordPress expects',
	$text_align_support( 'core/heading' )
		? 'center' === ( $blocks[0]['attrs']['style']['typography']['textAlign'] ?? '' )
		: 'center' === ( $blocks[0]['attrs']['textAlign'] ?? '' )
);
$check( 'heading: saved HTML', false !== strpos( $result['markup'], '<h1 class="wp-block-heading has-text-align-center hero" id="top">Welcome <em>home</em></h1>' ) );
$check( 'heading: entities stay encoded', false !== strpos( $result['markup'], 'Sub &amp; more' ) );

$result = $convert( 'paragraph', $html( '<p class="lead">A <strong>b</strong>, <a href="https://example.com/?a=1&amp;b=2" target="_blank" rel="noopener">link</a><br>two.</p><p></p>' ) );
$check( 'paragraph: inline formatting kept', false !== strpos( $result['markup'], '<p class="lead">A <strong>b</strong>, <a href="https://example.com/?a=1&amp;b=2" target="_blank" rel="noopener">link</a><br>two.</p>' ) );
$check( 'paragraph: an empty one is dropped', 1 === ( $names( $result['markup'] )['core/paragraph'] ?? 0 ) );

echo "\n--- lists ---\n";

$result = $convert( 'lists', $html( '<ul class="checks"><li>One</li><li>Two<ul><li>Two a</li></ul></li></ul><ol start="3" reversed><li>Three</li></ol>' ) );
$count  = $names( $result['markup'] );
$check( 'list: nested list is a list inside its item', 3 === $count['core/list'] && 4 === $count['core/list-item'] );
$check( 'list: item text then the nested list', false !== strpos( $result['markup'], "<li>Two<!-- wp:list -->" ) );
$check( 'list: ordered, start and reversed', false !== strpos( $result['markup'], '<!-- wp:list {"ordered":true,"start":3,"reversed":true} -->' ) && false !== strpos( $result['markup'], '<ol reversed start="3" class="wp-block-list">' ) );

echo "\n--- details ---\n";

$result = $convert( 'details', $html( '<details open><summary>Open?</summary><div><p>Yes.</p></div></details><details><p>No summary.</p></details>' ) );
$check( 'details: open becomes showContent', false !== strpos( $result['markup'], '<!-- wp:details {"showContent":true} -->' ) && false !== strpos( $result['markup'], '<details class="wp-block-details" open><summary>Open?</summary>' ) );
$check( 'details: a plain wrapping div adds nothing', 2 === ( $names( $result['markup'] )['core/paragraph'] ?? 0 ) && ! isset( $names( $result['markup'] )['core/group'] ) );
$check( 'details: no summary is written as WordPress writes it', false !== strpos( $result['markup'], '<summary>Details</summary>' ) );

echo "\n--- images, buttons, separator, quote, groups ---\n";

$result = $convert( 'images', $html( '<figure class="photo"><img src="https://example.com/a.jpg" alt="A cat" width="800" height="600" loading="lazy"><figcaption>A <em>cat</em></figcaption></figure><p><img src="https://example.com/b.jpg"></p>' ) );
$check( 'image: figure with caption', false !== strpos( $result['markup'], '<figure class="wp-block-image photo"><img src="https://example.com/a.jpg" alt="A cat"/><figcaption class="wp-element-caption">A <em>cat</em></figcaption></figure>' ) );
$check( 'image: render-time attributes dropped', false === strpos( $result['markup'], 'loading=' ) && false === strpos( $result['markup'], 'width=' ) );
$check( 'image: a paragraph holding only an image is an image, alt always present', 2 === ( $names( $result['markup'] )['core/image'] ?? 0 ) && false !== strpos( $result['markup'], '<img src="https://example.com/b.jpg" alt=""/>' ) );

$result = $convert( 'buttons', $html( '<a class="btn btn-primary" href="/contact">Book a call</a> <a class="btn btn-outline" href="/work" target="_blank" rel="noreferrer noopener">See work</a>' ) );
$count  = $names( $result['markup'] );
$check( 'buttons: neighbours share one Buttons block', 1 === $count['core/buttons'] && 2 === $count['core/button'] );
$check( 'buttons: link saved as the block writes it', false !== strpos( $result['markup'], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Book a call</a></div>' ) );
$check( 'buttons: target and rel stay on the link, not in the comment', false !== strpos( $result['markup'], 'target="_blank" rel="noreferrer noopener"' ) && false === strpos( $result['markup'], 'linkTarget' ) );
$check( 'buttons: an outline class becomes the outline style', false !== strpos( $result['markup'], '<!-- wp:button {"className":"is-style-outline"} -->' ) );

$result = $convert( 'misc', $html( '<hr><blockquote><p>Great work.</p><cite>Jane</cite></blockquote><section class="band"><h2>Band</h2><p>Inside.</p><div class="cols"><div><p>Left</p></div></div></section>' ) );
$check( 'separator: never a self-closing comment', false !== strpos( $result['markup'], "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->" ) );
$check( 'quote: paragraphs inside, cite after', false !== strpos( $result['markup'], '<cite>Jane</cite></blockquote>' ) );
$check( 'group: section keeps its tag and class, constrained', false !== strpos( $result['markup'], '<!-- wp:group {"tagName":"section","className":"band","layout":{"type":"constrained"}} -->' ) );
$check( 'group: a classed div inside is a group, a bare one is not', 2 === ( $names( $result['markup'] )['core/group'] ?? 0 ) );

echo "\n--- what stays Custom HTML ---\n";

$card   = $html( '<div class="card"><svg viewBox="0 0 1 1"><rect/></svg><h3>Card</h3></div>' );
$result = $convert( 'card with an svg', $card );
$check( 'kept: a card holding an svg is untouched, byte for byte', ! $result['changed'] && $result['markup'] === $card && 1 === $result['kept_html'] );

$styled = $html( '<p style="color:red">Styled</p><span class="icon">*</span>' );
$result = $convert( 'inline style', $styled );
$check( 'kept: inline style and a lone inline element stay', ! $result['changed'] && $result['markup'] === $styled );

$unsafe = $html( '<a class="btn" href="javascript:alert(1)">Go</a>' );
$result = $convert( 'unsafe link', $unsafe );
$check( 'kept: a javascript: link stays as it was', ! $result['changed'] );

$result = $convert( 'mixed', $html( '<p>Before</p><iframe src="https://www.youtube.com/embed/x"></iframe><p>After</p>' ) );
$shape  = array_values( array_filter( Clara_VE_Block_Gate::block_shape( $result['markup'] ), static function ( $row ) {
	return false === strpos( $row, 'core/list-item' );
} ) );
$check( 'mixed: paragraph, the embed as Custom HTML, paragraph', array( '0:core/paragraph', '0:core/html', '0:core/paragraph' ) === array_map(
	static function ( $row ) {
		return preg_replace( '/^\d+:/', '0:', $row );
	},
	$shape
) );
$check( 'mixed: the report says what stayed', 1 === $result['kept_html'] && in_array( 'iframe', $result['kept_why'], true ) );

echo "\n--- markup without block comments, and edits ---\n";

$result = $convert( 'loose', "<h2>Loose heading</h2>\n<p>Loose paragraph with ščťžý.</p>" );
$check( 'loose: HTML without comments becomes blocks', array( 'core/heading' => 1, 'core/paragraph' => 1 ) === $names( $result['markup'] ) );
$check( 'loose: UTF-8 survives', false !== strpos( $result['markup'], 'ščťžý' ) );

$embed  = $html( '<iframe src="https://example.com/map"></iframe>' );
$before = "<!-- wp:paragraph -->\n<p>Hi.</p>\n<!-- /wp:paragraph -->\n\n" . $html( '<p>Kept on purpose</p>' );
$after  = $before . "\n\n" . $html( '<p>New from the edit</p>' );
$result = $convert( 'delta', $after, array( 'baseline' => $before ) );
$check( 'delta: Custom HTML already on the page is left alone', false !== strpos( $result['markup'], "<!-- wp:html -->\n<p>Kept on purpose</p>\n<!-- /wp:html -->" ) );
$check( 'delta: only what the edit added is converted', false !== strpos( $result['markup'], "<!-- wp:paragraph -->\n<p>New from the edit</p>\n<!-- /wp:paragraph -->" ) );

echo "\n--- inside containers, and the whole document ---\n";

$result = $convert( 'nested', "<!-- wp:group -->\n<div class=\"wp-block-group\">" . $html( '<p>A</p><p>B</p>' ) . "</div>\n<!-- /wp:group -->" );
$group  = array_values( array_filter( parse_blocks( $result['markup'] ), static function ( $b ) {
	return null !== $b['blockName'];
} ) )[0];
$check( 'nested: inner blocks and content slots stay in step', count( $group['innerBlocks'] ) === count( array_filter( $group['innerContent'], 'is_null' ) ) && 2 === count( $group['innerBlocks'] ) );
$check( 'nested: round trip', serialize_blocks( parse_blocks( $result['markup'] ) ) === $result['markup'] );

$a = Clara_VE_Block_Convert::convert_document( $html( '<p>One</p>' ) )['markup'];
$b = Clara_VE_Block_Convert::convert_document( $html( '<h3>Two</h3>' ) )['markup'];
$check( 'locality: converting a page equals converting its parts', Clara_VE_Block_Convert::convert_document( $html( '<p>One</p>' ) . "\n\n" . $html( '<h3>Two</h3>' ) )['markup'] === $a . "\n\n" . $b );

$slug = get_stylesheet() . '/qa-convert-html';
register_block_pattern( $slug, array( 'title' => 'QA convert', 'categories' => array( 'text' ), 'content' => $html( '<details><summary>Q?</summary><p>A.</p></details>' ) ) );

if ( function_exists( 'resolve_pattern_blocks' ) ) {
	$result = $convert( 'pattern reference', '<!-- wp:pattern {"slug":"' . $slug . '"} /-->' );
	$check( 'pattern reference: expanded and converted', 1 === ( $names( $result['markup'] )['core/details'] ?? 0 ) && false === strpos( $result['markup'], 'wp:pattern' ) );
}

echo "\n--- where sections are added ---\n";

if ( class_exists( 'Clara_VE_Block_Patch' ) && method_exists( 'Clara_VE_Block_Patch', 'apply_structure' ) ) {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'QA convert section',
			'post_content' => "<!-- wp:paragraph -->\n<p>First.</p>\n<!-- /wp:paragraph -->",
		)
	);
	$added   = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'insert-pattern', 'position' => 'end', 'pattern' => $slug ) );
	$check( 'a section added on the server lands as native blocks', ! is_wp_error( $added ) && false !== strpos( $added, '<!-- wp:details -->' ) && false === strpos( $added, 'wp:html' ) );
	wp_delete_post( $page_id, true );
}

$server = rest_get_server();
if ( isset( $server->get_routes()['/clara-ve/v1/native/convert-blocks'] ) ) {
	$request = new WP_REST_Request( 'POST', '/clara-ve/v1/native/convert-blocks' );
	$request->set_param( 'markup', $html( '<details><summary>Q?</summary><p>A.</p></details>' ) );
	$response = rest_do_request( $request );
	$data     = $response->get_data();
	$check( 'the editor\'s convert route answers with native blocks', 200 === $response->get_status() && ! empty( $data['changed'] ) && false !== strpos( (string) $data['markup'], '<!-- wp:details -->' ) );

	$previous = get_current_user_id();
	wp_set_current_user( 0 );
	$check( 'and refuses anyone who cannot edit', 200 !== rest_do_request( $request )->get_status() );
	wp_set_current_user( $previous );
}

unregister_block_pattern( $slug );

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: Custom HTML to native blocks — ' . get_stylesheet() . "\n";
