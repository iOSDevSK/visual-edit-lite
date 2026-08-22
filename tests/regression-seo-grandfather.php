<?php
/**
 * Regression: the SEO/GEO stand-down removes DEFAULT output and never
 * explicit configuration.
 *
 * The distinction is the whole safety of the stand-down, and it is finer than
 * "is the option empty": opening either settings screen and pressing Save
 * writes every registered field at once, so entity_type lands as
 * 'Organization', the separator as an en dash and ai_crawlers as 'on' whether
 * or not anyone chose them. Counting those as configuration would grandfather
 * the duplicate tags back in after one stray save — which is indistinguishable,
 * from the owner's side, from the bug never having been fixed.
 *
 * Restores every option it touches, including deleting rows that did not
 * exist before it ran.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-seo-grandfather.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! function_exists( 'clara_ve_public_seo_is_configured' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$options = array(
	Clara_VE_SEO::OPT_ENTITY_NAME,
	Clara_VE_SEO::OPT_ENTITY_LOGO,
	Clara_VE_SEO::OPT_DEFAULT_OG,
	Clara_VE_SEO::OPT_ENTITY_TYPE,
	Clara_VE_SEO::OPT_TITLE_SEPARATOR,
	Clara_VE_SEO::OPT_SAME_AS,
	Clara_VE_GEO::OPT_AI_CRAWLERS,
);

$restore = array();
foreach ( $options as $option ) {
	$restore[ $option ] = get_option( $option, null );
	delete_option( $option );
}

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

$check( 'an untouched install is not configured', ! clara_ve_public_seo_is_configured() );

// Exactly what the settings screen writes when somebody opens it and saves
// without changing anything.
update_option( Clara_VE_SEO::OPT_ENTITY_TYPE, 'Organization' );
update_option( Clara_VE_SEO::OPT_TITLE_SEPARATOR, '–' );
update_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'on' );
update_option( Clara_VE_SEO::OPT_SAME_AS, array( '', '  ' ) );
$check( 'a settings screen saved at its defaults is not configuration', ! clara_ve_public_seo_is_configured() );

update_option( Clara_VE_SEO::OPT_ENTITY_NAME, 'Amanda Rose Photography' );
$check( 'an entity name is configuration', clara_ve_public_seo_is_configured() );
delete_option( Clara_VE_SEO::OPT_ENTITY_NAME );

update_option( Clara_VE_SEO::OPT_TITLE_SEPARATOR, '|' );
$check( 'a separator somebody changed is configuration', clara_ve_public_seo_is_configured() );
update_option( Clara_VE_SEO::OPT_TITLE_SEPARATOR, '–' );

update_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'off' );
$check( 'AI crawlers turned off is configuration', clara_ve_public_seo_is_configured() );
update_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'on' );

update_option( Clara_VE_SEO::OPT_SAME_AS, array( 'https://example.com/profile' ) );
$check( 'a sameAs address is configuration', clara_ve_public_seo_is_configured() );
update_option( Clara_VE_SEO::OPT_SAME_AS, array( '', '  ' ) );

update_option( Clara_VE_SEO::OPT_DEFAULT_OG, 'https://example.com/share.png' );
$check( 'a default share image is configuration', clara_ve_public_seo_is_configured() );

foreach ( $restore as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value );
	}
}
$check( 'the install is back as it was found', array_map( static function ( $o ) {
	return get_option( $o, null );
}, array_combine( $options, $options ) ) === $restore );

echo "\n--- a page of a block theme has a search appearance of its own ---\n";

// A block theme's pages are keyed by their post ID rather than by a stored
// meta value, so the meta lookup that resolves a legacy key finds nothing for
// them. The panel read that as "no page", and told the owner every page of
// such a theme was "shared across every page, so it has no title or
// description of its own" — the whole feature, absent, with a sentence that
// sounded deliberate.
$seo_page = wp_insert_post( array(
	'post_type'    => 'page',
	'post_title'   => 'SEO key regression',
	'post_status'  => 'draft',
	'post_content' => "<!-- wp:paragraph -->\n<p>Quiet.</p>\n<!-- /wp:paragraph -->",
) );
$block_key = Clara_VE_Source_Store::block_key( $seo_page );

if ( '' === $block_key ) {
	// A raw-HTML theme: the block driver owns nothing, so there is no block
	// key to resolve and this must stay exactly as it was.
	$check( 'under a raw-HTML theme there is no block key at all', true );
	$check( 'and a made-up one resolves to nothing', 0 === Clara_VE_SEO::post_id_for_key( 'block__page-' . $seo_page ) );
} else {
	$check( 'the page has a block key', 0 === strpos( $block_key, 'block__page-' ) );
	$check( 'and the search appearance panel resolves it to this page', $seo_page === Clara_VE_SEO::post_id_for_key( $block_key ) );

	// Round-tripped through block_key(), so a key naming a post the block
	// driver does not own cannot point the panel at it.
	$check( 'a key naming a post that is not editable here resolves to nothing', 0 === Clara_VE_SEO::post_id_for_key( 'block__page-999999' ) );
	$check( 'and neither does a malformed one', 0 === Clara_VE_SEO::post_id_for_key( 'block__page-not-a-number' ) );
}
wp_delete_post( $seo_page, true );

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s)\n";
	exit( 1 );
}
echo "PASS: SEO stand-down grandfather clause\n";
