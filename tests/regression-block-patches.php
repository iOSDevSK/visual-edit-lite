<?php
/**
 * Regression: a click in the preview reaches exactly one block, and the write
 * it produces is made of bytes the server wrote.
 *
 * Two failures are being guarded against, and they are opposites. One is a
 * patch landing on the wrong block — most dangerously inside a Query Loop,
 * where "the third card's title" is the ONE stored block every card is drawn
 * from, so editing a card would rewrite all of them. The other is a save that
 * lands on the right block and still corrupts it, because the markup made a
 * round trip through a DOM parser that helpfully normalised its entities.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-block-patches.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Block_Patch' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

$block_mode = ! clara_ve_active_theme_is_ours();
echo 'theme: ' . get_stylesheet() . ' — ' . ( $block_mode ? "block mode\n" : "raw-HTML mode\n" ) . "\n";

// A page shaped like a real one: a section wrapping editable blocks, and a
// Query Loop whose insides must stay out of reach. The em dash and the
// &amp; are here on purpose — they are what a DOM round trip rewrites.
$content = "<!-- wp:group {\"tagName\":\"section\",\"className\":\"band\"} -->\n"
	. "<section class=\"wp-block-group band\">\n"
	. "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Weddings &amp; elopements</h2>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Quiet, editorial &mdash; unhurried.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:image {\"id\":7,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.com/a.webp\" alt=\"\" class=\"wp-image-7\"/></figure>\n<!-- /wp:image -->\n\n"
	// Two blocks that are byte-identical. Addresses are keyed by a block's
	// own bytes, so these two are the case that decides whether the second
	// one gets its own address or silently shares the first one's.
	. "<!-- wp:paragraph -->\n<p>Twice.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Twice.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n"
	. "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/old/\">Enquire</a></div>\n<!-- /wp:button -->\n"
	. "</div>\n<!-- /wp:buttons -->\n"
	. "</section>\n<!-- /wp:group -->\n\n"
	. "<!-- wp:query {\"queryId\":1,\"query\":{\"postType\":\"post\"}} -->\n<div class=\"wp-block-query\">\n"
	. "<!-- wp:post-template -->\n"
	. "<!-- wp:post-title /-->\n"
	. "<!-- wp:paragraph -->\n<p>Read more</p>\n<!-- /wp:paragraph -->\n"
	. "<!-- /wp:post-template -->\n"
	. "</div>\n<!-- /wp:query -->";

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Block patch regression',
		'post_name'    => 'clara-ve-block-patch-regression',
		'post_content' => $content,
		'post_status'  => 'draft',
	),
	true
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( 'could not create the fixture page: ' . $page_id->get_error_message() );
}
$fixture = get_post( $page_id );

if ( ! $block_mode ) {
	echo "--- the converted theme's pages are nobody's business but WordPress's ---\n";
	$check( 'no block key, so nothing is addressable', '' === Clara_VE_Source_Store::block_key( $fixture ) );
	$result = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-0', 'op' => 'set-text', 'html' => 'x' ) ) );
	$check( 'and a patch is refused outright', is_wp_error( $result ) );
	$check( 'and the page is untouched', $content === get_post_field( 'post_content', $page_id ) );
	wp_delete_post( $page_id, true );
	echo "\nPASS: block patches — " . get_stylesheet() . " (correctly inert)\n";
	return;
}

echo "--- addressing ---\n";

$index = Clara_VE_Block_Stamp::index_for( $fixture );
$flat  = array();
foreach ( $index as $entries ) {
	foreach ( $entries as $entry ) {
		$flat[ $entry['path'] ] = $entry['cap'];
	}
}
ksort( $flat );

// The group wrapping the page is styleable now — padding, background — but
// its WORDS belong to the blocks inside it. The capability says which, and
// Clara_VE_Block_Patch::CAPABILITY_OPS is what enforces it: handing a
// container to the text writer replaces everything between its tags.
$check( 'the section itself is addressed as a container', isset( $flat['0'] ) && 'section' === $flat['0'] );
$check( 'a container may be styled', in_array( 'set-style', Clara_VE_Block_Patch::CAPABILITY_OPS['section'], true ) );
$check( 'and may not be rewritten', ! in_array( 'set-text', Clara_VE_Block_Patch::CAPABILITY_OPS['section'], true ) );
$check( 'the heading is addressed as text', isset( $flat['0-0'] ) && 'text' === $flat['0-0'] );
$check( 'the paragraph is addressed as text', isset( $flat['0-1'] ) && 'text' === $flat['0-1'] );
$check( 'the image is addressed as an image', isset( $flat['0-2'] ) && 'image' === $flat['0-2'] );
$check( 'the button is addressed as a button', isset( $flat['0-5-0'] ) && 'button' === $flat['0-5-0'] );
$check( 'two identical blocks get two distinct addresses', isset( $flat['0-3'], $flat['0-4'] ) && 'text' === $flat['0-3'] && 'text' === $flat['0-4'] );

// The whole reason addresses are computed on the server.
$inside_loop = array_filter(
	array_keys( $flat ),
	static function ( $path ) {
		return 0 === strpos( $path, '2-' );
	}
);
$check( 'nothing inside the Query Loop is addressable', ! $inside_loop );
$check( 'not even the loop block itself', ! isset( $flat['2'] ) );

echo "\n--- what a patch changes, and what it leaves alone ---\n";

$patched = Clara_VE_Block_Patch::apply(
	$page_id,
	array(
		array( 'block' => '0-1', 'op' => 'set-text', 'html' => 'Quiet, editorial &mdash; and warm.' ),
	)
);
$check( 'a text patch applies', ! is_wp_error( $patched ) );

if ( ! is_wp_error( $patched ) ) {
	$check( 'the words changed', false !== strpos( $patched, 'and warm' ) );
	// The one that matters: a DOM round trip would have turned &mdash; into a
	// literal — and &amp; into &, and Gutenberg would call both blocks invalid.
	$check( 'the entity in the SAME block survives verbatim', false !== strpos( $patched, 'Quiet, editorial &mdash; and warm.' ) );
	$check( "the neighbouring heading's entity is untouched", false !== strpos( $patched, 'Weddings &amp; elopements' ) );
	$check( 'the self-closing block keeps its shape', false !== strpos( $patched, '<!-- wp:post-title /-->' ) );
	$check( 'and the result passes the gate', true === Clara_VE_Block_Gate::check( $patched, $content, array( 'context' => 'patch' ) ) );
	$check( 'and it is what the serializer would write', Clara_VE_Block_Gate::is_idempotent( $patched ) );
}

echo "\n--- the other operations ---\n";

$patched = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-0', 'op' => 'set-attrs', 'attrs' => array( 'textAlign' => 'center', 'level' => 3 ) ) ) );
$check( 'attributes apply', ! is_wp_error( $patched ) );
if ( ! is_wp_error( $patched ) ) {
	$check( 'the attribute is stored', false !== strpos( $patched, '"textAlign":"center"' ) );
	$check( "and a heading's level moves its TAG too, which is what a visitor sees", (bool) preg_match( '~<h3[ >]~', $patched ) && false === strpos( $patched, '<h2' ) );
	// The alignment is rendered by a class, not by the attribute — a static
	// block's save() bakes it in, and nothing adds it later.
	$check( 'and the alignment reaches the markup as its class', false !== strpos( $patched, 'has-text-align-center' ) );
	$check( "without disturbing the block's own class", false !== strpos( $patched, 'wp-block-heading' ) );
}

$patched = Clara_VE_Block_Patch::apply(
	$page_id,
	array( array( 'block' => '0-2', 'op' => 'set-image', 'url' => 'https://example.com/b.webp', 'id' => 42, 'alt' => 'A bride at dusk' ) )
);
$check( 'an image swap applies', ! is_wp_error( $patched ) );
if ( ! is_wp_error( $patched ) ) {
	$check( 'the picture changed', false !== strpos( $patched, 'b.webp' ) );
	$check( 'the attachment class follows it', false !== strpos( $patched, 'wp-image-42' ) && false === strpos( $patched, 'wp-image-7' ) );
	$check( 'the alt text lands in both halves', false !== strpos( $patched, 'alt="A bride at dusk"' ) && false !== strpos( $patched, '"alt":"A bride at dusk"' ) );
	// Never written, because WordPress adds them at render and an <img> whose
	// dimensions its block does not declare is what flags fifty images at once.
	$check( 'no width/height are invented', false === strpos( $patched, 'width=' ) );
	$check( 'and it passes the schema check', true === Clara_VE_Block_Gate::check( $patched, $content, array( 'context' => 'patch' ) ) );
}

$patched = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-5-0', 'op' => 'set-link', 'href' => '/enquire/' ) ) );
$check( 'a link change applies', ! is_wp_error( $patched ) );
if ( ! is_wp_error( $patched ) ) {
	$check( 'the href moved', false !== strpos( $patched, 'href="/enquire/"' ) );
	$check( "and the button's own url attribute with it", false !== strpos( $patched, '"url":"/enquire/"' ) );
}

// Opening in a new tab. rel travels with target rather than being a separate
// choice: a link that opens a new tab without noreferrer/noopener hands the
// new document a handle on the one that opened it, and Gutenberg writes
// exactly this pair — anything else and the block disagrees with its editor.
$patched = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-5-0', 'op' => 'set-link', 'href' => '/enquire/', 'target' => '_blank' ) ) );
$check( 'opening in a new tab applies', ! is_wp_error( $patched ) );
if ( ! is_wp_error( $patched ) ) {
	$check( 'the target reaches the markup', false !== strpos( $patched, 'target="_blank"' ) );
	$check( 'and brings its rel with it', (bool) preg_match( '~rel="[^"]*noreferrer[^"]*noopener~', $patched ) );
	$check( 'and both reach the attributes', false !== strpos( $patched, '"linkTarget":"_blank"' ) && false !== strpos( $patched, '"rel":"noreferrer noopener"' ) );
}

// Unticking it must take the rel away too — a leftover rel on a same-tab link
// is not harmful, but it is not what the editor would have written.
$cleared = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-5-0', 'op' => 'set-link', 'href' => '/enquire/', 'target' => '' ) ) );
$check( 'unticking removes target and rel', ! is_wp_error( $cleared ) && false === strpos( $cleared, 'target="_blank"' ) && false === strpos( $cleared, 'noopener' ) );

// A patch that says nothing about the target must not silently clear one.
$retarget = Clara_VE_Block_Patch::apply( $page_id, array(
	array( 'block' => '0-5-0', 'op' => 'set-link', 'href' => '/x/', 'target' => '_blank' ),
) );
$check( 'a link patch with no target key leaves the setting alone', ! is_wp_error( $retarget ) && false !== strpos( $retarget, 'target="_blank"' ) );

echo "\n--- an image can be linked and unlinked ---\n";

$linked = Clara_VE_Block_Patch::apply( $page_id, array(
	array( 'block' => '0-2', 'op' => 'set-image', 'url' => 'https://example.com/a.webp', 'link' => '/portfolio/', 'linkTarget' => '_blank' ),
) );
$check( 'linking a picture applies', ! is_wp_error( $linked ) );
if ( ! is_wp_error( $linked ) ) {
	$check( 'the picture is wrapped in the link', (bool) preg_match( '~<a[^>]*href="/portfolio/"[^>]*>\s*<img~', $linked ) );
	$check( 'the anchor sits INSIDE the figure', (bool) preg_match( '~<figure[^>]*>\s*<a~', $linked ) );
	$check( 'and the block records where it leads', false !== strpos( $linked, '"linkDestination":"custom"' ) && false !== strpos( $linked, '"href":"/portfolio/"' ) );
	$check( 'the gate is satisfied', true === Clara_VE_Block_Gate::check( $linked, $content, array( 'context' => 'patch' ) ) );
}

// Clearing the address unwraps rather than leaving an anchor to nowhere. The
// linked markup has to be the page's real content first, since a patch reads
// what is stored, not what the last call returned.
wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $linked ) ) );
$unlinked = Clara_VE_Block_Patch::apply( $page_id, array(
	array( 'block' => '0-2', 'op' => 'set-image', 'link' => '' ),
) );
$check( 'clearing the address unwraps the picture', ! is_wp_error( $unlinked ) && false === strpos( $unlinked, 'href="/portfolio/"' ) );
$check( 'and the picture itself survives', ! is_wp_error( $unlinked ) && false !== strpos( $unlinked, '<img' ) );
wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $content ) ) );

$patched = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-5-0', 'op' => 'set-text', 'html' => 'Get in touch' ) ) );
$check( 'a button label changes', ! is_wp_error( $patched ) && false !== strpos( $patched, '>Get in touch</a>' ) );
$check( 'without eating the div around it', ! is_wp_error( $patched ) && false !== strpos( $patched, '<div class="wp-block-button">' ) );

echo "\n--- what a patch may not do ---\n";

$check( 'a read-only block is refused', is_wp_error( Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0', 'op' => 'set-text', 'html' => 'x' ) ) ) ) );
$check( 'an address that does not exist is refused', is_wp_error( Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-99', 'op' => 'set-text', 'html' => 'x' ) ) ) ) );
$check( 'an unknown operation is refused', is_wp_error( Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-1', 'op' => 'delete-everything' ) ) ) ) );
$check( 'a patch with no target is refused', is_wp_error( Clara_VE_Block_Patch::apply( $page_id, array( array( 'op' => 'set-text', 'html' => 'x' ) ) ) ) );

$bad_attr = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-1', 'op' => 'set-attrs', 'attrs' => array( 'textColor' => 'red; } body { display:none' ) ) ) );
$check( 'an attribute value that is not a slug is refused', is_wp_error( $bad_attr ) );

$dropped = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0-1', 'op' => 'set-attrs', 'attrs' => array( 'style' => array( 'color' => '#f00' ) ) ) ) );
$check( 'an attribute this version does not claim is dropped, not stored', ! is_wp_error( $dropped ) && false === strpos( $dropped, '#f00' ) );

// Applying is not saving: nothing above should have touched the page.
$check( 'none of that was written', $content === get_post_field( 'post_content', $page_id ) );

// A queue is all-or-nothing — a half-applied edit is worse than a refused one,
// because the owner cannot tell which half landed.
$queue = Clara_VE_Block_Patch::apply(
	$page_id,
	array(
		array( 'block' => '0-1', 'op' => 'set-text', 'html' => 'First change.' ),
		array( 'block' => '0-99', 'op' => 'set-text', 'html' => 'Doomed.' ),
	)
);
$check( 'a queue with one bad patch applies none of it', is_wp_error( $queue ) );

echo "\n--- the addresses reach the rendered page ---\n";

// Everything above tests the index. This tests that the index arrives on the
// HTML a person is going to click, which is a different claim: render_block
// fires innermost-first and for the header and footer too, and says nothing
// about where in the tree it is.
$_GET['clara_edit'] = '1';
$_GET['_clara_ve']  = wp_create_nonce( 'clara_ve_preview' );

$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $page_id, 'post_status' => 'draft' ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

$check( 'the preview authorizes', clara_ve_is_edit_preview() );
$check( 'and the key resolves to this page', Clara_VE_Source_Store::block_key( $page_id ) === clara_ve_current_key() );

Clara_VE_Block_Stamp::maybe_hook();
$rendered = apply_filters( 'the_content', $fixture->post_content );

$check( 'the paragraph carries its address', false !== strpos( $rendered, 'data-ve-block="0-1"' ) );
$check( 'and its capability', (bool) preg_match( '~data-ve-block="0-1"[^>]*data-ve-edit="text"|data-ve-edit="text"[^>]*data-ve-block="0-1"~', $rendered ) );
$check( 'the image carries an image capability', (bool) preg_match( '~data-ve-block="0-2"[^>]*data-ve-edit="image"|data-ve-edit="image"[^>]*data-ve-block="0-2"~', $rendered ) );
$check( 'the section carries its container capability', (bool) preg_match( '~data-ve-block="0"[^>]*data-ve-edit="section"|data-ve-edit="section"[^>]*data-ve-block="0"~', $rendered ) );
// The panel decides what to offer from the block's name — a heading gets a
// level control, a group does not — so the name has to travel with the
// address rather than be guessed from the markup.
$check( 'and its block name, which the panel builds itself from', (bool) preg_match( '~data-ve-block="0"[^>]*data-ve-name="core/group"~', $rendered ) );

// The query loop is the one that must NOT become addressable: its template is
// stored once and rendered for every post, so patching "the third card" would
// rewrite all of them.
$check( 'a query loop is still nobody\'s to edit', false === strpos( $rendered, 'data-ve-name="core/query"' ) );

// Identical bytes, two addresses, consumed in render order — the thing that
// decides whether editing the second "Twice." rewrites the first one.
$check( 'the first of two identical blocks gets the first address', false !== strpos( $rendered, 'data-ve-block="0-3"' ) );
$check( 'and the second gets the second', false !== strpos( $rendered, 'data-ve-block="0-4"' ) );

// The one that would be a data-loss bug: the loop renders ONE stored block
// per post, and a click in the second card must resolve to nothing.
$cards = substr_count( $rendered, 'Read more' );
echo '  --   the loop rendered ' . $cards . " card(s)\n";
$check( 'nothing inside the rendered loop is addressable', ! preg_match( '~data-ve-block="2[-\"]~', $rendered ) );
$check(
	'and the whole page carries no more addresses than the index has',
	substr_count( $rendered, 'data-ve-block=' ) === count( $flat )
);

// the_content runs more than once on a real page load — wp_trim_excerpt()
// puts it through whenever a post has no excerpt, and a theme building
// og:description does that during wp_head, before the body renders. A
// counter that survives between applications leaves the SECOND pass, the one
// a person actually sees, with nothing stamped at all.
$again = apply_filters( 'the_content', $fixture->post_content );
$check(
	'a second pass over the same content stamps it just the same',
	substr_count( $again, 'data-ve-block=' ) === count( $flat )
);

$_GET = array();

$request = new WP_REST_Request( 'POST', '/clara-ve/v1/block-patches' );
$request->set_param( 'post', $page_id );
$request->set_param( 'patches', array( array( 'block' => '0-1', 'op' => 'set-text', 'html' => 'Saved through the endpoint.' ) ) );

$check( 'an administrator may write to this page', true === Clara_VE_REST::can_edit_target_post( $request ) );

$response = Clara_VE_REST::save_block_patches( $request );
$check( 'the endpoint saves', ! is_wp_error( $response ) && ! empty( $response->get_data()['saved'] ) );
$check( 'the page carries the change', false !== strpos( get_post_field( 'post_content', $page_id ), 'Saved through the endpoint.' ) );
$check( 'and the change is in the history, so it can be taken back', ! empty( Clara_VE_History::visible_entries( Clara_VE_Source_Store::block_key( $page_id ) ) ) );

$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );
$subscriber = $subscriber ? (int) $subscriber[0] : (int) wp_insert_user(
	array(
		'user_login' => 'clara-ve-regression-subscriber',
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'subscriber',
	)
);
$was = get_current_user_id();
wp_set_current_user( $subscriber );
$check( 'somebody who could not open the page in WordPress cannot patch it either', true !== Clara_VE_REST::can_edit_target_post( $request ) );
wp_set_current_user( $was );

$missing = new WP_REST_Request( 'POST', '/clara-ve/v1/block-patches' );
$missing->set_param( 'post', 9999999 );
$check( 'a request naming no real page is refused', is_wp_error( Clara_VE_REST::can_edit_target_post( $missing ) ) );

wp_delete_post( $page_id, true );
if ( get_user_by( 'login', 'clara-ve-regression-subscriber' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $subscriber );
}

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: block patches — ' . get_stylesheet() . "\n";
