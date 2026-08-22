<?php
/**
 * Regression: styling a block page writes the theme's own tokens, and fonts
 * kept in this plugin reach the block editor.
 *
 * The point of both is the same. A site whose type scale and palette are
 * declared in theme.json has one design, and an editor that lets a client
 * type #c0ffee into it has quietly given them two. So the panel offers slugs
 * and the store keeps slugs — a preset follows the theme when the palette
 * changes, which a copied hex does not.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-block-styles.php
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
$presets = ( new ReflectionMethod( 'Clara_VE_Editor_Page', 'block_presets' ) )->invoke( null );

$block_mode = ! clara_ve_active_theme_is_ours();
echo 'theme: ' . get_stylesheet() . ' — ' . ( $block_mode ? "block mode\n" : "raw-HTML mode\n" ) . "\n";

echo "--- the theme's tokens ---\n";

if ( ! $block_mode ) {
	$check( 'no presets are offered on a raw-HTML theme', array() === $presets );
} else {
	foreach ( array( 'colors', 'fontSizes', 'fontFamily' ) as $group ) {
		$check( "{$group} are offered (" . count( isset( $presets[ $group ] ) ? $presets[ $group ] : array() ) . ')', ! empty( $presets[ $group ] ) );
	}
	// Each carries its VALUE as well as its slug: the panel shows a choice in
	// the frame before anything is saved, and a preview needs a colour rather
	// than the name of one.
	$check(
		'each preset carries the value the panel previews with',
		! array_filter(
			$presets['colors'],
			static function ( $preset ) {
				return '' === $preset['value'];
			}
		)
	);
}

if ( ! $block_mode ) {
	echo "\nPASS: block styles — " . get_stylesheet() . " (correctly inert)\n";
	return;
}

echo "\n--- what a style change stores ---\n";

$content = "<!-- wp:paragraph -->\n<p>Quiet, editorial.</p>\n<!-- /wp:paragraph -->";
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Block style regression',
		'post_name'    => 'clara-ve-block-style-regression',
		'post_content' => $content,
		'post_status'  => 'draft',
	),
	true
);
if ( is_wp_error( $page_id ) ) {
	throw new RuntimeException( 'could not create the fixture page: ' . $page_id->get_error_message() );
}

$colour = $presets['colors'][0]['slug'];
$size   = $presets['fontSizes'][0]['slug'];
$family = $presets['fontFamily'][0]['slug'];

$styled = Clara_VE_Block_Patch::apply(
	$page_id,
	array(
		array(
			'block' => '0',
			'op'    => 'set-attrs',
			'attrs' => array(
				'textColor'  => $colour,
				'fontSize'   => $size,
				'fontFamily' => $family,
				'textAlign'  => 'center',
			),
		),
	)
);
$check( 'a style patch applies', ! is_wp_error( $styled ) );

if ( ! is_wp_error( $styled ) ) {
	$check( "the colour is stored as the slug '{$colour}', not a hex", false !== strpos( $styled, '"textColor":"' . $colour . '"' ) );
	$check( 'the size is stored as a slug', false !== strpos( $styled, '"fontSize":"' . $size . '"' ) );
	$check( 'the typeface is stored as a slug', false !== strpos( $styled, '"fontFamily":"' . $family . '"' ) );
	$check( 'no inline style attribute is written', false === strpos( $styled, 'style="' ) );
	$check( 'and the result passes the gate', true === Clara_VE_Block_Gate::check( $styled, $content, array( 'context' => 'patch' ) ) );

	// The slug only means anything if WordPress turns it back into a class the
	// theme's CSS matches. Rendering is what proves the round trip.
	wp_update_post( array( 'ID' => $page_id, 'post_content' => $styled ) );
	$rendered = apply_filters( 'the_content', $styled );
	$check( "rendering turns the colour slug into has-{$colour}-color", false !== strpos( $rendered, 'has-' . $colour . '-color' ) );
	$check( 'and the alignment into a class the theme styles', false !== strpos( $rendered, 'has-text-align-center' ) );
}

echo "\n--- what it refuses ---\n";

$check(
	'a raw hex is refused where a slug belongs',
	is_wp_error( Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0', 'op' => 'set-attrs', 'attrs' => array( 'textColor' => '#c0ffee' ) ) ) ) )
);
$inline = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0', 'op' => 'set-attrs', 'attrs' => array( 'style' => array( 'color' => array( 'text' => '#c0ffee' ) ) ) ) ) );
$check( 'an arbitrary style object is dropped rather than stored', ! is_wp_error( $inline ) && false === strpos( $inline, 'c0ffee' ) );

// Clearing goes back to the theme's default rather than pinning the opposite.
$cleared = Clara_VE_Block_Patch::apply( $page_id, array( array( 'block' => '0', 'op' => 'set-attrs', 'attrs' => array( 'textColor' => '' ) ) ) );
$check( 'clearing a preset removes the attribute', ! is_wp_error( $cleared ) && false === strpos( $cleared, '"textColor"' ) );
// And its class, or the page keeps rendering a colour whose attribute is gone
// — invalid in the block editor and wrong on screen at the same time.
$check( 'and the class it rendered through', ! is_wp_error( $cleared ) && false === strpos( $cleared, 'has-' . $colour . '-color' ) );
$check( 'while leaving the other presets alone', ! is_wp_error( $cleared ) && false !== strpos( $cleared, 'has-' . $size . '-font-size' ) );

wp_delete_post( $page_id, true );

echo "\n--- a kept Google font reaches the panel too ---\n";

// The complaint this phase answers: the picker saved the family, WordPress
// generated its CSS, and the block-mode dropdown never showed it — because
// the panel read the `theme` origin while the plugin merges into `custom`.
$before_fonts = get_option( Clara_VE_Fonts::OPTION, null );
update_option( Clara_VE_Fonts::OPTION, array( array( 'family' => 'Inter', 'category' => 'sans-serif' ) ), false );
wp_clean_theme_json_cache();

$with_font = ( new ReflectionMethod( 'Clara_VE_Editor_Page', 'block_presets' ) )->invoke( null );
$slugs     = wp_list_pluck( $with_font['fontFamily'], 'slug' );
$check( 'the kept family is offered in the panel', in_array( 'inter', $slugs, true ) );
$check( "and the theme's own typefaces are still there", count( $slugs ) > 1 );
$check( 'it carries a stack to preview with', '' !== $with_font['fontFamily'][ array_search( 'inter', $slugs, true ) ]['value'] );

// A slug is only worth storing if WordPress renders it.
$css = wp_get_global_stylesheet();
$check( 'WordPress generates the class the slug turns into', false !== strpos( $css, 'has-inter-font-family' ) );
$check( 'and the custom property behind it', false !== strpos( $css, '--wp--preset--font-family--inter' ) );

// What the picker hands back after a save — the panel refreshes from this
// rather than guessing the slug.
$presets = Clara_VE_Fonts::family_presets();
$check( 'the picker can answer with the recomputed presets', in_array( 'inter', wp_list_pluck( $presets, 'slug' ), true ) );

// Spacing steps arrived with this phase; the panel cannot offer them otherwise
// because a block has no spacing slug attribute to read back.
$check( 'spacing steps are offered', ! empty( $with_font['spacing'] ) );

if ( null === $before_fonts ) {
	delete_option( Clara_VE_Fonts::OPTION );
} else {
	update_option( Clara_VE_Fonts::OPTION, $before_fonts, false );
}
wp_clean_theme_json_cache();

echo "\n--- fonts kept here reach the block editor ---\n";

$before = get_option( Clara_VE_Fonts::OPTION, null );
update_option( Clara_VE_Fonts::OPTION, array( array( 'family' => 'Inter', 'category' => 'sans-serif' ) ), false );

$url = Clara_VE_Fonts::css_url();
$check( 'a kept family produces a stylesheet URL', false !== strpos( $url, 'family=Inter' ) );

$data   = new WP_Theme_JSON_Data( array( 'version' => 2 ), 'custom' );
$merged = Clara_VE_Fonts::merge_into_theme_json( $data );

// get_data() returns presets keyed by ORIGIN — theme, custom, default — not
// as one flat list, so a naive read finds nothing and reports the merge
// broken when it worked.
$typography = $merged->get_data()['settings']['typography']['fontFamilies'] ?? array();
$families   = isset( $typography['custom'] ) ? $typography['custom'] : $typography;
$slugs      = wp_list_pluck( (array) $families, 'slug' );
$check( "WordPress's own font picker is offered the same family", in_array( 'inter', $slugs, true ) );

$stack = '';
foreach ( (array) $families as $entry ) {
	if ( 'inter' === $entry['slug'] ) {
		$stack = $entry['fontFamily'];
	}
}
$check( 'and it carries a fallback rather than a bare name', false !== strpos( $stack, 'sans-serif' ) );

// enqueue_block_editor_assets was what this used to assert, under this same
// name — and it is exactly the hook that does NOT reach the canvas. Since
// WordPress 6.3 the canvas is an iframe of its own: that hook dresses the
// editor's chrome, so the font loaded where the sidebar lives and was absent
// where the words are. enqueue_block_assets is the one WordPress puts inside.
$check(
	'the editor canvas gets the stylesheet too',
	false !== has_action( 'enqueue_block_assets', array( 'Clara_VE_Fonts', 'enqueue_in_block_editor' ) )
);
$check(
	'and not only the editor chrome around it',
	false === has_action( 'enqueue_block_editor_assets', array( 'Clara_VE_Fonts', 'enqueue_in_block_editor' ) )
);

// Loaded LATE, and only if nobody else has. A theme generated by the
// converter reads this plugin's own list and publishes the faces itself; a
// block theme has never heard of them. Standing down for both left a block
// theme's chosen typeface never loading at all; standing down for neither had
// a converted theme fetching the same stylesheet twice.
$check(
	'the site load runs after everything else has registered its own',
	100 === has_action( 'wp_enqueue_scripts', array( 'Clara_VE_Fonts', 'enqueue_selected' ) )
);

$duplicate = new ReflectionMethod( 'Clara_VE_Fonts', 'already_enqueued' );
$duplicate->setAccessible( true );

wp_dequeue_style( 'clara-ve-google-fonts' );
wp_deregister_style( 'clara-ve-probe-fonts' );
$check( 'with nothing else on the page, it loads them', ! $duplicate->invoke( null ) );

if ( Clara_VE_Fonts::selected() ) {
	wp_enqueue_style( 'clara-ve-probe-fonts', Clara_VE_Fonts::css_url(), array(), null );
	$check( 'and when the theme already has, it stands aside', $duplicate->invoke( null ) );
	wp_dequeue_style( 'clara-ve-probe-fonts' );
	wp_deregister_style( 'clara-ve-probe-fonts' );

	// A theme loading ONE of several must not silence the rest.
	wp_enqueue_style( 'clara-ve-probe-fonts', 'https://fonts.googleapis.com/css2?family=Something+Else:wght@400', array(), null );
	$check( 'but a different typeface is not mistaken for these', ! $duplicate->invoke( null ) );
	wp_dequeue_style( 'clara-ve-probe-fonts' );
	wp_deregister_style( 'clara-ve-probe-fonts' );
}

if ( null === $before ) {
	delete_option( Clara_VE_Fonts::OPTION );
} else {
	update_option( Clara_VE_Fonts::OPTION, $before, false );
}

echo "--- a typeface added here joins the theme's own, it does not replace them ---\n";

// Both halves of one mistake. The families are merged into the THEME layer,
// because WordPress builds the block editor's canvas stylesheet from theme
// presets and skips the user's — a font kept in the user layer had its
// variable defined and its class on the block with no rule connecting them,
// so Gutenberg drew the theme's font over the chosen one while the site was
// correct. And a preset list is REPLACED by a merge rather than added to, so
// merging into that layer carelessly erased every typeface the theme ships.
$offered = wp_get_global_settings()['typography']['fontFamilies']['theme'] ?? array();
$slugs   = array_map( static function ( $f ) { return $f['slug']; }, (array) $offered );

$check( 'the theme\'s own typefaces are present', count( $slugs ) >= 1 );
if ( Clara_VE_Fonts::selected() ) {
	$added = array_map( static function ( $f ) { return sanitize_title( $f['family'] ); }, Clara_VE_Fonts::selected() );
	foreach ( $added as $slug ) {
		$check( 'a typeface added here is offered: ' . $slug, in_array( $slug, $slugs, true ) );
	}
	$theme_own = array_diff( $slugs, $added );
	$check( 'and the theme keeps its own beside them (' . count( $theme_own ) . ')', count( $theme_own ) >= 1 );
} else {
	echo "  --   no typeface has been added through the picker on this install\n";
}

// The layer matters: theme, not user. This is what puts the class rule into
// the block editor's canvas.
$check(
	'they are merged into the theme layer, which the block editor reads',
	10 === has_filter( 'wp_theme_json_data_theme', array( 'Clara_VE_Fonts', 'merge_into_theme_json' ) )
);
$check(
	'and not the user layer, whose presets the canvas skips',
	false === has_filter( 'wp_theme_json_data_user', array( 'Clara_VE_Fonts', 'merge_into_theme_json' ) )
);

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo 'PASS: block styles — ' . get_stylesheet() . "\n";
