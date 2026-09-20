<?php
/**
 * Regression: copying a page brings everything that belongs to it, and
 * removing one is recoverable.
 *
 * A page here is not only its post row. It carries the key that finds its
 * source, its search-appearance record, its small-screen rules, its featured
 * image and whatever a third-party plugin has hung on it. The assertions that
 * matter are the ones about the fields nobody would think to look at: a copy
 * that brings the words and drops the rest looks right and is not.
 *
 * Three kinds of page, because they store their content in three different
 * places: one the plugin keys (source in a row of its own), one the block
 * driver owns (source in post_content, key derived from the ID), and one
 * nobody manages at all. The suite runs this file under both themes, which is
 * what puts the first two on the same footing.
 *
 *   php tools/run-in-wp.php <wp-dir> tests/regression-page-actions.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

$theme = get_stylesheet();
echo "theme: {$theme}\n\n";

$made = array();

/**
 * A page with everything a page can carry.
 *
 * @param string $title Title.
 * @param string $slug  Slug.
 * @param bool   $keyed Give it a plugin source key.
 * @return int
 */
$make = static function ( $title, $slug, $keyed ) use ( &$made ) {
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'name' => $slug ) ) as $old ) {
		wp_delete_post( $old->ID, true );
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => "<!-- wp:paragraph -->\n<p>Original words.</p>\n<!-- /wp:paragraph -->",
			'post_excerpt' => 'A summary.',
			'menu_order'   => 7,
		)
	);
	$made[] = $id;

	// The fields a copy is most likely to drop: an array record of our own, a
	// scalar of our own, and one belonging to a plugin we have never heard of.
	update_post_meta(
		$id,
		Clara_VE_SEO::META,
		array(
			'title'       => 'Search title',
			'description' => 'Search description',
			'canonical'   => home_url( '/original/' ),
			'og_image'    => '',
		)
	);
	update_post_meta( $id, Clara_VE_Responsive::META, array( 'cve-r-abc12345' => array( 'mobile' => array( 'spacing.padding.top' => '8px' ) ) ) );
	update_post_meta( $id, '_wp_page_template', 'default' );
	update_post_meta( $id, '_some_other_plugin_field', 'kept' );
	update_post_meta( $id, '_edit_lock', '1600000000:1' );

	if ( $keyed ) {
		update_post_meta( $id, CLARA_VE_PAGE_KEY_META, $slug );
		update_post_meta( $id, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );
		// Through the store, not left as raw post_content: a keyed page really
		// holds its content wrapped in a wp:html block carrying the key, and a
		// fixture that skips that step compares the copy against something no
		// page on a real site looks like.
		Clara_VE_Source_Store::save_source( $slug, "<!-- wp:paragraph -->\n<p>Original words.</p>\n<!-- /wp:paragraph -->" );
	}
	return (int) $id;
};

/**
 * What a page actually contains, however it happens to store it.
 *
 * @param int $id Page.
 * @return string
 */
$content_of = static function ( $id ) {
	$key = Clara_VE_Page_Actions::key_for( get_post( $id ) );
	if ( '' !== $key ) {
		return (string) Clara_VE_Source_Store::get_current_source( $key );
	}
	return (string) get_post_field( 'post_content', $id );
};

// ------------------------------------------------------------ an ordinary copy

echo "--- copying a page the plugin keys ---\n";

$origin = $make( 'Origin page', 'clara-ve-origin', true );
$out    = Clara_VE_Page_Actions::duplicate( $origin, 'Origin page copy', 'clara-ve-origin-copy' );

$check( 'the copy was made', ! is_wp_error( $out ) );
if ( is_wp_error( $out ) ) {
	echo '  --   ' . $out->get_error_message() . "\n";
	foreach ( $made as $id ) {
		wp_delete_post( $id, true );
	}
	echo "\nFAIL: 1 assertion(s)\n";
	exit( 1 );
}

$copy   = get_post( $out['id'] );
$made[] = $out['id'];

$check( 'it has the title it was given', 'Origin page copy' === $copy->post_title );
$check( 'and the slug it was given', 'clara-ve-origin-copy' === $copy->post_name );
// Never published. A copy live at its own address the instant it exists,
// carrying every word of the original, is not something to do by default.
$check( 'it is a draft, not published', 'draft' === $copy->post_status );
$check( 'the words came with it', $content_of( $out['id'] ) === $content_of( $origin ) );
$check( 'so did the summary', 'A summary.' === $copy->post_excerpt );
$check( 'and the menu order', 7 === (int) $copy->menu_order );

echo "\n--- and everything hanging off it ---\n";

// Deep equality, not presence: get_post_meta() without a key hands back values
// still serialized, and passing those on stores a string that only LOOKS like
// an array. Asserting `! empty()` would pass on exactly that bug.
$was  = get_post_meta( $origin, Clara_VE_SEO::META, true );
$now  = get_post_meta( $out['id'], Clara_VE_SEO::META, true );
$check( 'the search-appearance record is an array, not a string of one', is_array( $now ) );
$check( 'its title came across', is_array( $now ) && $was['title'] === $now['title'] );
$check( 'and its description', is_array( $now ) && $was['description'] === $now['description'] );
// The one field where "everything that belongs to the page" is wrong: a
// canonical copied verbatim tells search engines to ignore the copy entirely.
$check( 'but the canonical was NOT copied', is_array( $now ) && '' === $now['canonical'] );

// Read through the accessor, not the meta row: since the responsive record
// became something Gutenberg edits over REST it is stored as a JSON string,
// and asserting on the raw row asserted on a storage detail rather than on
// the thing that matters — that the copy has the same small-screen rules.
$rules = Clara_VE_Responsive::rules( $out['id'] );
$check( 'the small-screen rules came across', is_array( $rules ) && $rules );
$check(
	'with their values intact',
	isset( $rules['cve-r-abc12345']['mobile']['spacing.padding.top'] )
		&& '8px' === $rules['cve-r-abc12345']['mobile']['spacing.padding.top']
);

$check( 'the page template came across', 'default' === get_post_meta( $out['id'], '_wp_page_template', true ) );
// An allowlist would have dropped this one silently, which is the whole reason
// the copy uses a denylist.
$check( "a field belonging to some other plugin came too", 'kept' === get_post_meta( $out['id'], '_some_other_plugin_field', true ) );
$check( 'but the edit lock did not', '' === get_post_meta( $out['id'], '_edit_lock', true ) );
$check( 'and the copy belongs to the active theme', sanitize_key( $theme ) === get_post_meta( $out['id'], CLARA_VE_PAGE_THEME_META, true ) );

echo "\n--- the copy edits itself, not the original ---\n";

$origin_key = (string) get_post_meta( $origin, CLARA_VE_PAGE_KEY_META, true );
$copy_key   = (string) get_post_meta( $out['id'], CLARA_VE_PAGE_KEY_META, true );

if ( '' !== $origin_key ) {
	// The bug this prevents: two pages sharing one key means editing either
	// writes to both, and nothing about the screen would say so.
	$check( 'the copy was given a source key of its own', '' !== $copy_key && $copy_key !== $origin_key );
	$check( 'and the editor can address it', '' !== $out['key'] );
} else {
	// Block driver: the key is derived from the post ID, so it cannot collide.
	$check( 'the block driver addresses the copy by its own ID', $out['key'] === Clara_VE_Source_Store::block_key( $copy ) );
	$check( 'which is not the original\'s', $out['key'] !== Clara_VE_Source_Store::block_key( get_post( $origin ) ) );
}

echo "\n--- a copy cannot take over a reserved key ---\n";

$chrome = Clara_VE_Page_Actions::duplicate( $origin, 'Header', CLARA_VE_HEADER_KEY );
$check( 'a page slugged "header" copies', ! is_wp_error( $chrome ) );
if ( ! is_wp_error( $chrome ) ) {
	$made[]     = $chrome['id'];
	$chrome_key = (string) get_post_meta( $chrome['id'], CLARA_VE_PAGE_KEY_META, true );
	if ( '' !== $origin_key ) {
		// Taking the slug verbatim would have made this copy the site's header
		// template part.
		$check( 'but does not become the header template part', CLARA_VE_HEADER_KEY !== $chrome_key );
	} else {
		echo "  --   block driver: keys come from IDs, so there is nothing to collide with\n";
	}
}

echo "\n--- a page nobody manages copies too ---\n";

$plain      = $make( 'Plain page', 'clara-ve-plain', false );
$plain_copy = Clara_VE_Page_Actions::duplicate( $plain, '', '' );
$check( '"every page" includes one with no source key', ! is_wp_error( $plain_copy ) );
if ( ! is_wp_error( $plain_copy ) ) {
	$made[] = $plain_copy['id'];
	$check( 'it is given a title that says what it is', false !== strpos( get_post_field( 'post_title', $plain_copy['id'] ), 'Plain page' ) );
	$check( 'and its words came across', $content_of( $plain_copy['id'] ) === $content_of( $plain ) );
}

echo "\n--- removing a page ---\n";

$doomed = $make( 'Doomed page', 'clara-ve-doomed', false );
$gone   = Clara_VE_Page_Actions::trash( $doomed );
$check( 'it goes to the trash', ! is_wp_error( $gone ) );
clean_post_cache( $doomed );
$check( 'and WordPress can still give it back', 'trash' === get_post_status( $doomed ) );

echo "\n--- except the one that would empty the home page ---\n";

$was_front = (int) get_option( 'page_on_front' );
$was_show  = get_option( 'show_on_front' );
$front     = $make( 'Front for a moment', 'clara-ve-front-probe', false );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $front );

$refused = Clara_VE_Page_Actions::trash( $front );
// Refused with a reason rather than repointed silently: which page is the
// front page is the site owner's decision, not this plugin's.
$check( 'the site\'s front page is refused', is_wp_error( $refused ) );
$check( 'and says why', is_wp_error( $refused ) && false !== strpos( $refused->get_error_message(), 'front page' ) );
clean_post_cache( $front );
$check( 'and is still there', 'publish' === get_post_status( $front ) );

update_option( 'show_on_front', $was_show );
update_option( 'page_on_front', $was_front );

// ------------------------------------------- the right to do what is asked

// A permission has to match what the request DOES, not what it reads. Copying
// a page reads one and CREATES another; removing one DELETES it; importing an
// image ADDS an attachment. Each of those is its own capability in WordPress,
// and "may edit this page" covers none of them. An administrator has them all,
// which is exactly why nothing here noticed they were never asked: so each is
// taken away in turn, from an administrator, and the door has to close.
$deny  = array();
$strip = static function ( $allcaps ) use ( &$deny ) {
	foreach ( $deny as $cap ) {
		$allcaps[ $cap ] = false;
	}
	return $allcaps;
};
add_filter( 'user_has_cap', $strip, 99 );

$request = static function ( $params ) {
	$r = new WP_REST_Request( 'POST', '/clara-ve/v1/x' );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return $r;
};
$page_type = get_post_type_object( 'page' );

$check( 'an administrator may copy a page', true === Clara_VE_REST::can_duplicate_page( $request( array( 'post' => $origin ) ) ) );
$deny = array( $page_type->cap->create_posts );
$check( 'without the right to create pages, the copy route refuses', true !== Clara_VE_REST::can_duplicate_page( $request( array( 'post' => $origin ) ) ) );
$refused = Clara_VE_Page_Actions::duplicate( $origin, 'Should not exist', 'clara-ve-should-not-exist' );
$check( 'and so does the method that would insert the post', is_wp_error( $refused ) && 'clara_ve_forbidden' === $refused->get_error_code() );
$check( 'nothing was created on the way to refusing', null === get_page_by_path( 'clara-ve-should-not-exist', OBJECT, 'page' ) );

$deny = array();
$check( 'an administrator may remove a page', true === Clara_VE_REST::can_trash_page( $request( array( 'post' => $origin ) ) ) );
$deny = array( $page_type->cap->delete_posts, $page_type->cap->delete_published_posts, $page_type->cap->delete_others_posts, $page_type->cap->delete_private_posts );
$check( 'without the right to delete it, the remove route refuses', true !== Clara_VE_REST::can_trash_page( $request( array( 'post' => $origin ) ) ) );

$deny = array();
$check( 'an administrator may import an image', true === Clara_VE_REST::can_import_image() );
$deny = array( 'upload_files' );
$check( 'without upload_files, the import-image route refuses', false === Clara_VE_REST::can_import_image() );

// A page is addressed one of two ways: a converted page by the key in its meta,
// a block page by a key derived from its ID. Whichever this one has.
$deny      = array();
$write_key = (string) get_post_meta( $origin, CLARA_VE_PAGE_KEY_META, true );
$write_key = '' !== $write_key ? $write_key : Clara_VE_Source_Store::block_key( get_post( $origin ) );
$check( 'the page under test has a key to be addressed by', '' !== $write_key );
$check( 'an administrator may write to a page by its key', true === Clara_VE_REST::can_edit_key( $request( array( 'key' => $write_key ) ) ) );
$deny = array( $page_type->cap->edit_posts, $page_type->cap->edit_others_posts, $page_type->cap->edit_published_posts, $page_type->cap->edit_private_posts );
$check( 'without the right to edit that page, a write addressed by its key refuses', true !== Clara_VE_REST::can_edit_key( $request( array( 'key' => $write_key ) ) ) );

$deny = array();
remove_filter( 'user_has_cap', $strip, 99 );

// ------------------------------------------------------------------- cleanup

foreach ( $made as $id ) {
	wp_delete_post( $id, true );
}

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s) — {$theme}\n";
	exit( 1 );
}
echo "PASS: a page copies with everything on it, and removing one is recoverable — {$theme}\n";
