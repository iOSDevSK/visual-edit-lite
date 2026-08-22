<?php
/**
 * Regression: different values on smaller screens.
 *
 * This is the one feature that keeps state outside the page, so it is the one
 * that can lie. The failures worth guarding are all about the two halves
 * drifting: a rule for a block that no longer exists, a duplicated section
 * sharing its original's phone padding, a restore that puts the sections back
 * and leaves the tuning belonging to a layout that is gone.
 *
 * And the promise made when this was agreed to: with the plugin switched off
 * the page is still whole. Not "mostly" — the content byte for byte, valid in
 * Gutenberg, with only the small-screen tuning silent.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-responsive.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Responsive' ) ) {
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

$content = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Inside.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\n\n"
	. "<!-- wp:paragraph -->\n<p>Below.</p>\n<!-- /wp:paragraph -->";

$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Responsive regression',
		'post_name'    => 'clara-ve-responsive-regression',
		'post_content' => $content,
		'post_status'  => 'publish',
	),
	true
);

$apply = static function ( $patch ) use ( $page_id ) {
	$out = Clara_VE_Block_Patch::apply( $page_id, array( $patch ) );
	if ( ! is_wp_error( $out ) ) {
		wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $out ) ) );
	}
	return $out;
};

if ( ! $block_mode ) {
	echo "--- a raw-HTML theme's pages keep no rules of their own ---\n";
	$out = $apply( array( 'block' => '0', 'op' => 'set-responsive', 'breakpoint' => 'mobile', 'path' => 'spacing.padding.top', 'value' => '12px' ) );
	$check( 'the change is refused outright', is_wp_error( $out ) );
	$check( 'nothing was written to the page', $content === get_post_field( 'post_content', $page_id ) );
	$check( 'and no rules were stored', array() === Clara_VE_Responsive::rules( $page_id ) );
	// Refused by the STORE as well, not only by the layer above it: a fence
	// that only holds while every caller remembers to ask is not a fence.
	$direct = Clara_VE_Responsive::set( $page_id, Clara_VE_Responsive::new_anchor(), 'mobile', 'spacing.padding.top', '4px' );
	$check( 'even asked directly, the store refuses', is_wp_error( $direct ) );
	$check( 'and still nothing is stored', array() === Clara_VE_Responsive::rules( $page_id ) );
	wp_delete_post( $page_id, true );
	echo "\nPASS: responsive — " . get_stylesheet() . " (correctly inert)\n";
	return;
}

echo "--- setting a value ---\n";

$out = $apply( array( 'block' => '0', 'op' => 'set-responsive', 'breakpoint' => 'mobile', 'path' => 'spacing.padding.top', 'value' => '12px' ) );
$check( 'a block can be given a phone value', ! is_wp_error( $out ) );

$anchors = Clara_VE_Responsive::anchors_in( (string) get_post_field( 'post_content', $page_id ) );
$check( 'the block was given an anchor to hang it on', 1 === count( $anchors ) );
$anchor = $anchors ? $anchors[0] : '';
$check( 'and the rule is stored against it', isset( Clara_VE_Responsive::rules( $page_id )[ $anchor ]['mobile']['spacing.padding.top'] ) );

// The anchor is a class like any other, so both halves of the block have to
// carry it or Gutenberg will not open the block at all.
$stored_content = (string) get_post_field( 'post_content', $page_id );
$check( 'the anchor is in the block\'s attributes', false !== strpos( $stored_content, '"className":"' . $anchor . '"' ) );
$check( 'and in its markup', false !== strpos( $stored_content, 'wp-block-group ' . $anchor ) );

$apply( array( 'block' => '0', 'op' => 'set-responsive', 'breakpoint' => 'tablet', 'path' => 'display', 'value' => 'none' ) );
$check( 'a second value reuses the same anchor', 1 === count( Clara_VE_Responsive::anchors_in( (string) get_post_field( 'post_content', $page_id ) ) ) );

$css = Clara_VE_Responsive::compile( Clara_VE_Responsive::rules( $page_id ) );
$check( 'the tablet rule comes first, so the narrower one wins', strpos( $css, '781px' ) < strpos( $css, '600px' ) );
// A block's own styling is an inline style attribute, which beats any
// selector. An override without this does nothing at all.
$check( 'every declaration can actually override an inline style', substr_count( $css, '!important' ) === substr_count( $css, ':' ) - substr_count( $css, 'max-width:' ) );

echo "\n--- what it refuses ---\n";

$check( 'a screen it does not offer', is_wp_error( Clara_VE_Responsive::set( $page_id, $anchor, 'watch', 'spacing.padding.top', '4px' ) ) );
$check( 'a property that may not differ by screen', is_wp_error( Clara_VE_Responsive::set( $page_id, $anchor, 'mobile', 'typography.letterSpacing', '1px' ) ) );
$check( 'a value that is not a value', is_wp_error( Clara_VE_Responsive::set( $page_id, $anchor, 'mobile', 'spacing.padding.top', 'red; } body {' ) ) );
$check( 'an anchor it never issued', is_wp_error( Clara_VE_Responsive::set( $page_id, 'not-an-anchor', 'mobile', 'spacing.padding.top', '4px' ) ) );
// The only value hiding takes.
$check( 'a display value other than none', is_wp_error( Clara_VE_Responsive::set( $page_id, $anchor, 'mobile', 'display', 'block' ) ) );

echo "\n--- duplicating and removing ---\n";

$before = Clara_VE_Responsive::rules( $page_id );
$out    = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'duplicate', 'block' => '0' ) );
wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $out ) ) );

$after_anchors = Clara_VE_Responsive::anchors_in( $out );
$check( 'a duplicated section gets an anchor of its own', 2 === count( $after_anchors ) );
// Sharing one would mean tuning the original quietly tuned the copy too.
$check( 'not the original\'s', $after_anchors[0] !== $after_anchors[1] );
$check( 'and it keeps the tuning it was copied with', 2 === count( Clara_VE_Responsive::rules( $page_id ) ) );

$out = Clara_VE_Block_Patch::apply_structure( $page_id, array( 'op' => 'remove', 'block' => '0' ) );
wp_update_post( array( 'ID' => $page_id, 'post_content' => wp_slash( $out ) ) );
$check( 'removing a section takes its rules with it', 1 === count( Clara_VE_Responsive::rules( $page_id ) ) );
$check( 'and leaves the other one alone', 1 === count( Clara_VE_Responsive::anchors_in( $out ) ) );

echo "\n--- and it can be taken back ---\n";

$key = Clara_VE_Source_Store::block_key( $page_id );
Clara_VE_History::ensure_baseline( $key );
Clara_VE_History::record(
	Clara_VE_Source_Store::tokenize( Clara_VE_Source_Store::get_current_source( $key ) ),
	array(), 'save', null, null, $key,
	Clara_VE_Responsive::rules( $page_id )
);

$entries = Clara_VE_History::list_entries( 100, $key );
$newest  = $entries ? Clara_VE_History::get( $entries[0]['id'], $key ) : null;
$check( 'a saved version carries the rules that belonged to it', $newest && ! empty( $newest['responsive'] ) );

Clara_VE_Responsive::save_rules( $page_id, array() );
$check( 'which matters, because the live rules can be lost', array() === Clara_VE_Responsive::rules( $page_id ) );
Clara_VE_Responsive::save_rules( $page_id, $newest['responsive'] );
$check( 'and restoring the version brings them back', 1 === count( Clara_VE_Responsive::rules( $page_id ) ) );

echo "\n--- with the plugin switched off ---\n";

// The promise made when this feature was agreed to. The page is content, not
// a plugin's private format: every word, every block, every desktop style
// stays, and only the small-screen tuning goes quiet.
$live = (string) get_post_field( 'post_content', $page_id );
$check( 'the page still has its words', false !== strpos( $live, 'Inside.' ) && false !== strpos( $live, 'Below.' ) );
$check( 'and its blocks', 2 === count( array_filter( parse_blocks( $live ), static function ( $b ) {
	return ! empty( $b['blockName'] );
} ) ) );
// The anchor stays. It has to: deactivating a plugin must not rewrite
// anybody's content, and an unused class costs nothing.
$check( 'the anchor class is left in place, harmless', 1 === count( Clara_VE_Responsive::anchors_in( $live ) ) );
$check( 'the page passes the gate exactly as it is', true === Clara_VE_Source_Store::validate_shape( $key, $live ) );

// Nothing else on the page depends on the rules being emitted: no markup
// references them, so with the emitter silent the page renders its desktop
// self.
$check( 'and nothing in the markup depends on the rules being emitted',
	false === strpos( $live, '@media' ) && false === strpos( $live, '!important' ) );

wp_delete_post( $page_id, true );

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: responsive — ' . get_stylesheet() . "\n";
