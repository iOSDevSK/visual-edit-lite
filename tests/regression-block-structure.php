<?php
/**
 * Regression: adding, copying, moving and removing whole sections.
 *
 * Structural changes are the ones that can lose a page. Every other operation
 * rewrites a block in place; these renumber everything below them, and the
 * failures are quiet — a section swapped with a blank line and nothing moves,
 * a removal that leaves its separator behind so the next address is off by
 * one, a pattern spliced in with no space around it.
 *
 * The whitespace is the recurring theme. parse_blocks() reports the blank
 * lines between top-level blocks as freeform entries rather than dropping
 * them, which is why addresses on an ordinary page step by two, and why an
 * operation that ignores them appears to work and then does not.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-block-structure.php
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

$content = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">First</h2>\n<!-- /wp:heading -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Second.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Third, inside a group.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n"
	. "<!-- wp:query {\"queryId\":1,\"query\":{\"postType\":\"post\"}} -->\n<div class=\"wp-block-query\">\n"
	. "<!-- wp:post-template -->\n<!-- wp:post-title /-->\n<!-- /wp:post-template -->\n"
	. "</div>\n<!-- /wp:query -->";

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Block structure regression',
		'post_name'    => 'clara-ve-block-structure-regression',
		'post_content' => $content,
		'post_status'  => 'draft',
	),
	true
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( 'could not create the fixture page: ' . $page_id->get_error_message() );
}

/** Put the fixture back, so each case starts from the same page. */
$reset = static function () use ( $page_id, $content ) {
	wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $content ) ) );
	return get_post( $page_id );
};

if ( ! $block_mode ) {
	echo "--- a raw-HTML theme's pages are nobody's business but WordPress's ---\n";
	$result = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '0' ) );
	$check( 'a structural change is refused outright', is_wp_error( $result ) );
	$check( 'and the page is untouched', $content === get_post_field( 'post_content', $page_id ) );
	wp_delete_post( $page_id, true );
	echo "\nPASS: block structure — " . get_stylesheet() . " (correctly inert)\n";
	return;
}

// Addresses step by two: the blank lines between top-level blocks are entries
// of their own in the parsed page.
echo "--- moving ---\n";

$reset();
$moved = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'move', 'block' => '2', 'direction' => 'up' ) );
$check( 'a section can be moved up', ! is_wp_error( $moved ) );
if ( ! is_wp_error( $moved ) ) {
	// The heading was first and the paragraph second; after the move the
	// paragraph's markup has to come first in the file.
	$check( 'and it really changed places', strpos( $moved, '<p>Second.</p>' ) < strpos( $moved, '>First<' ) );
	// The trap: the entry beside a block is usually the blank line after it,
	// not the next section. Swapping with THAT leaves the page as it was.
	$check( 'it did not merely swap with a blank line', $moved !== $content );
	$check( 'the sections are still separated', false !== strpos( $moved, "-->\n\n<!-- wp:" ) );
	$check( 'and nothing was lost', 4 === count( array_filter( parse_blocks( $moved ), static function ( $b ) {
		return ! empty( $b['blockName'] );
	} ) ) );
}

$reset();
$edge = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'move', 'block' => '0', 'direction' => 'up' ) );
$check( 'the first section refuses to move up', is_wp_error( $edge ) );

$reset();
$down = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'move', 'block' => '0', 'direction' => 'down' ) );
$check( 'but moves down', ! is_wp_error( $down ) && strpos( $down, '<p>Second.</p>' ) < strpos( $down, '>First<' ) );

echo "\n--- removing ---\n";

$reset();
$removed = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '2' ) );
$check( 'a section can be removed', ! is_wp_error( $removed ) );
if ( ! is_wp_error( $removed ) ) {
	$check( 'it is gone', false === strpos( $removed, '<p>Second.</p>' ) );
	$check( 'the others are not', false !== strpos( $removed, '>First<' ) && false !== strpos( $removed, 'Third, inside a group.' ) );
	// A removal that leaves its blank line behind piles up gaps and pushes
	// every address below it off by one.
	$check( 'and it took its blank line with it', false === strpos( $removed, "\n\n\n" ) );
	$check( 'so the page has one separator fewer', substr_count( $removed, "\n\n" ) === substr_count( $content, "\n\n" ) - 1 );
}

// A page of its own for the bottom edge: on the fixture the last section is
// a query loop, which is refused for its own reasons and would hide whether
// the trailing separator is handled.
$two_id = wp_insert_post( array(
	'post_type'    => 'page',
	'post_title'   => 'Block structure tail',
	'post_status'  => 'draft',
	'post_content' => "<!-- wp:paragraph -->\n<p>One.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two.</p>\n<!-- /wp:paragraph -->",
) );
$last = Clara_VE_Block_Patch::apply_structure( $two_id, array( 'op' => 'remove', 'block' => '2' ) );
$check( 'the last section can go too', ! is_wp_error( $last ) && false === strpos( (string) $last, 'Two.' ) );
// Nothing below it, so the blank line ABOVE is the one left dangling.
$check( 'without leaving a trailing blank line', is_string( $last ) && rtrim( $last ) === $last );
$check( 'and the one above it survives intact', is_string( $last ) && false !== strpos( $last, '<p>One.</p>' ) );
wp_delete_post( $two_id, true );

echo "\n--- duplicating ---\n";

$reset();
$copied = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'duplicate', 'block' => '4' ) );
$check( 'a section can be duplicated', ! is_wp_error( $copied ) );
if ( ! is_wp_error( $copied ) ) {
	$check( 'there are two of it now', 2 === substr_count( $copied, 'Third, inside a group.' ) );
	// The copy is a whole block, not flattened markup: the group still has a
	// paragraph inside it rather than the paragraph's text.
	$check( 'and the copy kept what was inside it', 2 === substr_count( $copied, '<!-- wp:group -->' ) );
	// Two paragraphs on the fixture, one of them inside the group; copying
	// the group makes three. A copy that had been flattened to markup would
	// leave the count at two.
	$check( 'as blocks, not as markup', 3 === substr_count( $copied, '<!-- wp:paragraph -->' ) );
}

echo "\n--- adding a section from the theme ---\n";

$patterns = Clara_VE_Patterns::composable();
$check( 'the theme offers sections to add', ! empty( $patterns ) );
if ( $patterns ) {
	$first = $patterns[0];
	echo '  --   using ' . $first['name'] . "\n";

	$reset();
	$added = Clara_VE_Block_Patch::apply_structure( $page_id, array(
		'op'       => 'insert-pattern',
		'block'    => '0',
		'position' => 'after',
		'pattern'  => $first['name'],
	) );
	$check( 'a section can be added after another', ! is_wp_error( $added ) );
	if ( ! is_wp_error( $added ) ) {
		$before = count( array_filter( parse_blocks( $content ), static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
		$after = count( array_filter( parse_blocks( $added ), static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
		$check( 'the page has more sections than it did', $after > $before );
		$check( 'the existing ones are all still there', false !== strpos( $added, '>First<' ) && false !== strpos( $added, '<p>Second.</p>' ) );
		$check( 'and nothing was run together', false === strpos( $added, '--><!-- wp:' ) );
	}

	$reset();
	$appended = Clara_VE_Block_Patch::apply_structure( $page_id, array(
		'op'       => 'insert-pattern',
		'position' => 'end',
		'pattern'  => $first['name'],
	) );
	// Adding at the end is the one operation with nothing to point at.
	$check( 'a section can be added at the end without a target', ! is_wp_error( $appended ) );
}

$reset();
$unknown = Clara_VE_Block_Patch::apply_structure( $page_id, array(
	'op'       => 'insert-pattern',
	'position' => 'end',
	'pattern'  => 'core/three-columns-of-text',
) );
// Looked up through the composable list, not the registry: a pattern the
// theme hides — or one of core's, which is on nobody's design — must not be
// insertable by naming it directly.
$check( 'a pattern the theme does not offer is refused', is_wp_error( $unknown ) );

echo "\n--- what it refuses ---\n";

$reset();
$nested = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '4-0' ) );
// Nested addresses would mean editing a parent's innerContent, where every
// child is a null placeholder that has to be removed in step with the child.
$check( 'a block inside a section cannot be removed here', is_wp_error( $nested ) );

$stale = Clara_VE_Block_Patch::apply_structure( $page_id, array(
	'op'     => 'remove',
	'block'  => '0',
	'expect' => 'core/paragraph',
) );
$check( 'a section that is not what the client thinks is refused', is_wp_error( $stale ) );
$check( 'and says so as a conflict', is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] );

$gone = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '99' ) );
$check( 'an address off the end of the page is refused', is_wp_error( $gone ) );

// A query loop's markup is a template rendered once per post, and nothing
// about it is addressable — the stamp gives it no address, so the panel
// cannot select it in the first place. Refusing it here as well means the
// two layers agree rather than relying on the client to be well behaved.
$dynamic = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '6' ) );
$check( 'a query loop is not a section this editor removes', is_wp_error( $dynamic ) );

$whitespace = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '1' ) );
$check( 'a blank line is not a section', is_wp_error( $whitespace ) );

$check( 'the page was never written to by any of this', $content === get_post_field( 'post_content', $page_id ) );

echo "\n--- through the store, and back again ---\n";

$key = Clara_VE_Source_Store::block_key( $page_id );
$check( 'the page has a block key', '' !== $key );

Clara_VE_History::ensure_baseline( $key );
$structural = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '2' ) );
$shape      = Clara_VE_Source_Store::validate_shape( $key, $structural );
$check( 'a structurally changed page passes the gate', true === $shape );
Clara_VE_Source_Store::save_source( $key, $structural );
Clara_VE_History::record( Clara_VE_Source_Store::tokenize( Clara_VE_Source_Store::get_current_source( $key ) ), array(), 'save', null, null, $key );
$check( 'and the removal reached storage', false === strpos( (string) get_post_field( 'post_content', $page_id ), '<p>Second.</p>' ) );

// The point of history is that a structural change is as undoable as any
// other. A section deleted by accident is exactly the edit somebody needs
// back, and it is the one that cannot be retyped.
$entries = Clara_VE_History::visible_entries( $key );
$check( 'the change was recorded', ! empty( $entries ) );
$all = Clara_VE_History::list_entries( 100, $key );
// Oldest last: the final row is the baseline taken before the first edit.
$original = $all ? end( $all ) : null;
$check( 'there is an entry to go back to', (bool) $original );
$restored = $original ? Clara_VE_History::get( $original['id'], $key ) : null;
$check( 'and it can be read', (bool) $restored );
Clara_VE_Source_Store::save_source( $key, Clara_VE_Source_Store::untokenize( $restored['source'] ) );
// A section deleted by accident is exactly the edit somebody needs back, and
// the one that cannot be retyped.
$check( 'restoring brings the removed section back', false !== strpos( (string) get_post_field( 'post_content', $page_id ), '<p>Second.</p>' ) );

wp_delete_post( $page_id, true );

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: block structure — ' . get_stylesheet() . "\n";
