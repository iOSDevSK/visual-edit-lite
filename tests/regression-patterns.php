<?php
/**
 * Regression: the sections a page can be built out of — the theme's own, and
 * the ones saved from this site — are one list with one set of rules.
 *
 * Two things are being guarded. The first is that a saved section is offered
 * ONLY where it means something: on a real block theme. The same list feeds
 * the raw-HTML editor's section browser on a converted theme, where a
 * wp_block post is not a section anybody could insert, so a leak there would
 * put unusable rows in front of somebody assembling a page.
 *
 * The second is which wp_block posts count. A synced one renders through its
 * original and arrives locked to content-only editing; a draft is not
 * published; a header or footer belongs to a template part; and one holding a
 * reference to a synced pattern smuggles the first case back in. Each of those
 * is excluded here, and each is a row somebody would otherwise be able to
 * insert and then not be able to edit.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-patterns.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Patterns' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

// Patterns register on init, which a CLI bootstrap has not run.
if ( ! did_action( 'init' ) ) {
	do_action( 'init' );
}

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};
// Only used behind a class_exists() guard: this file is shared with an edition
// that has no assistant, and a pure rename must not become a fatal there.
$call = static function ( $method, ...$args ) {
	$method = new ReflectionMethod( 'Clara_VE_AI_Chat', $method );
	$method->setAccessible( true );
	return $method->invokeArgs( null, $args );
};

$native = class_exists( 'Clara_VE_Native_Gutenberg' ) && Clara_VE_Native_Gutenberg::is_native_mode();
echo 'theme: ' . get_stylesheet() . ' — ' . ( $native ? "native block theme\n" : "not a native block theme\n" ) . "\n";

// ---------------------------------------------------------------- fixtures ---
// A section worth saving, and one of each kind that must not be offered.
$band = "<!-- wp:group {\"tagName\":\"section\"} -->\n<section class=\"wp-block-group\">\n"
	. "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Saved band</h2>\n<!-- /wp:heading -->\n"
	. "</section>\n<!-- /wp:group -->";

$make = static function ( $title, $content, $status, $sync ) {
	$id = wp_insert_post(
		array(
			'post_type'    => 'wp_block',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_excerpt' => 'A QA fixture.',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	// A SYNCED pattern carries no sync meta at all — that is how WordPress
	// stores one, so the fixture has to store it the same way.
	if ( null !== $sync ) {
		update_post_meta( $id, 'wp_pattern_sync_status', $sync );
	}
	return $id;
};

$a = $make( 'QA saved A', $band, 'publish', 'unsynced' );
$b = $make( 'QA synced B', $band, 'publish', null );
$c = $make( 'QA draft C', $band, 'draft', 'unsynced' );
$d = $make( 'QA header D', $band, 'publish', 'unsynced' );
$e = $make( 'QA reference E', "<!-- wp:block {\"ref\":" . $b . "} /-->", 'publish', 'unsynced' );

// The category has to exist before anything can be filed under it: a fresh
// site has no wp_pattern_category terms at all.
$term       = get_term_by( 'slug', 'header', 'wp_pattern_category' );
$made_term  = false;
if ( ! $term ) {
	$created = wp_insert_term( 'Header', 'wp_pattern_category', array( 'slug' => 'header' ) );
	if ( ! is_wp_error( $created ) ) {
		$term      = get_term( $created['term_id'], 'wp_pattern_category' );
		$made_term = true;
	}
}
if ( $term && ! is_wp_error( $term ) ) {
	wp_set_object_terms( $d, array( (int) $term->term_id ), 'wp_pattern_category' );
}

$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'QA patterns target',
		'post_content' => "<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->",
	)
);

$by_name = array();
foreach ( Clara_VE_Patterns::composable() as $row ) {
	$by_name[ $row['name'] ] = $row;
}

// ------------------------------------------------------------ the one list ---
echo "--- what composable() offers ---\n";
$check(
	$native ? 'a saved section is offered on a block theme' : 'no saved section is offered where one could not be inserted',
	isset( $by_name[ 'core/block/' . $a ] ) === $native
);
$check( 'a synced pattern is never offered', ! isset( $by_name[ 'core/block/' . $b ] ) );
$check( 'a draft is never offered', ! isset( $by_name[ 'core/block/' . $c ] ) );
$check( 'a header belongs to a template part, not between sections', ! isset( $by_name[ 'core/block/' . $d ] ) );
$check( 'nor is one holding a reference to a synced pattern', ! isset( $by_name[ 'core/block/' . $e ] ) );

$sources = array();
foreach ( $by_name as $row ) {
	$sources[ $row['source'] ] = true;
}
$check( 'every row says where it came from', count( $by_name ) === count( array_filter( $by_name, static function ( $row ) { return ! empty( $row['source'] ); } ) ) );
$check(
	$native ? 'and both kinds are in the one list' : 'and the theme\'s own are all there is',
	isset( $sources['saved'] ) === ( $native && isset( $by_name[ 'core/block/' . $a ] ) )
);

if ( $native ) {
	$row = $by_name[ 'core/block/' . $a ];
	$check( 'a saved row is sourced "saved"', 'saved' === $row['source'] );
	// Byte for byte: what lands on the next page has to be what was saved,
	// not a re-serialised approximation of it.
	$check( 'and carries the saved markup unchanged', $row['content'] === $band );
	$check( 'its preview is what the section says', false !== strpos( $row['preview'], 'Saved band' ) );
	$check( 'its title is the post title', 'QA saved A' === $row['title'] );
	$check( 'and its categories are slugs', is_array( $row['categories'] ) );
	$check( 'get() finds it by name', null !== Clara_VE_Patterns::get( 'core/block/' . $a ) );
}
$check( 'get() refuses a synced one by name', null === Clara_VE_Patterns::get( 'core/block/' . $b ) );

// ------------------------------------------------------------------- REST ---
echo "\n--- the editor's section browser ---\n";
$response = rest_do_request( new WP_REST_Request( 'GET', '/clara-ve/v1/block-patterns' ) );
$rows     = (array) ( $response->get_data()['patterns'] ?? array() );
$check( 'the route answers', ! $response->is_error() );
$check( 'every row carries a source', ! array_filter( $rows, static function ( $row ) { return empty( $row['source'] ); } ) );
$check( 'and its categories', ! array_filter( $rows, static function ( $row ) { return ! isset( $row['categories'] ); } ) );
$check(
	$native ? 'the saved section is in the browser' : 'no saved section reaches the raw-HTML browser',
	(bool) array_filter( $rows, static function ( $row ) use ( $a ) { return 'core/block/' . $a === $row['name']; } ) === $native
);

// ----------------------------------------------------------- inserting one ---
if ( $native ) {
	echo "\n--- inserting one ---\n";
	$markup = Clara_VE_Block_Patch::apply_structure( $page, array( 'op' => 'insert-pattern', 'position' => 'end', 'pattern' => 'core/block/' . $a ) );
	$check( 'a saved section can be inserted', ! is_wp_error( $markup ) && false !== strpos( (string) $markup, 'Saved band' ) );
	$refused = Clara_VE_Block_Patch::apply_structure( $page, array( 'op' => 'insert-pattern', 'position' => 'end', 'pattern' => 'core/block/' . $b ) );
	$check( 'a synced one cannot be inserted by naming it', is_wp_error( $refused ) );

	echo "\n--- and what the assistant is told about it ---\n";
	if ( ! class_exists( 'Clara_VE_AI_Chat' ) ) {
		echo "  --   no assistant on this edition: the tool checks are skipped\n";
	} else {
	$read = $call( 'tool_read_pattern', array( 'name' => 'core/block/' . $a ) );
	$check( 'read_pattern reads a saved section', isset( $read['content'] ) && false !== strpos( $read['content'], 'Saved band' ) );
	$check( 'and says it is saved', isset( $read['source'] ) && 'saved' === $read['source'] );
	// The whole point of the source. Told this is demo copy, the model
	// rewrites the site's own words on its way past.
	$check( 'and does not call its words demo copy', isset( $read['note'] ) && false === stripos( $read['note'], 'demo copy' ) );

	$made = $call( 'tool_create_page_from_patterns', array( 'title' => 'QA composed from a saved section', 'patterns' => array( 'core/block/' . $a ) ) );
	$check( 'create_page_from_patterns composes from one', ! empty( $made['ok'] ) && false !== strpos( (string) $made['content'], 'Saved band' ) );
	$check( 'and does not tell the model to rewrite this site\'s own words', isset( $made['note'] ) && false === strpos( $made['note'], "THEME'S DEMO COPY" ) );
	if ( ! empty( $made['id'] ) ) {
		wp_delete_post( (int) $made['id'], true );
	}

	$listed = $call( 'tool_list_patterns' );
	$check( 'list_patterns names the saved section', (bool) array_filter( (array) $listed['patterns'], static function ( $row ) use ( $a ) { return 'core/block/' . $a === $row['name']; } ) );
	$check( 'and explains the two sources', isset( $listed['note'] ) && false !== strpos( $listed['note'], 'saved' ) );
	}
}

// ---------------------------------------------------------------- clean up ---
foreach ( array( $a, $b, $c, $d, $e, $page ) as $id ) {
	wp_delete_post( (int) $id, true );
}
if ( $made_term && $term && ! is_wp_error( $term ) ) {
	wp_delete_term( (int) $term->term_id, 'wp_pattern_category' );
}

echo "\n";
if ( $failed ) {
	echo 'FAILED (' . count( $failed ) . "):\n - " . implode( "\n - ", $failed ) . "\n";
	exit( 1 );
}
echo "PASS: one list of sections, and a saved one only where it can be inserted\n";
