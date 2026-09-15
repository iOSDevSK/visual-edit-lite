<?php
/**
 * Plugin Name: VE QA — theme-shaped patterns
 * Description: Test-only. Registers, under the active theme's name, a section shaped like the ones
 * that ship Custom HTML: an FAQ whose questions sit in one Custom HTML block. Copy into
 * wp-content/mu-plugins of a THROWAWAY site; never ship.
 */
defined( 'ABSPATH' ) || exit;

// A theme pattern shaped like the one that taught the assistant to stop
// shipping Custom HTML: an FAQ whose questions sit in one Custom HTML block,
// in the theme's demo words. steps/x-ai-faq-page.cjs and steps/f-section-html.cjs use it.
add_action(
	'init',
	static function () {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}
		register_block_pattern(
			get_stylesheet() . '/qa-faq-html',
			array(
				'title'      => 'QA FAQ (Custom HTML)',
				'categories' => array( 'text' ),
				'content'    => "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Common questions, answered clearly.</h2>\n<!-- /wp:heading -->\n\n<!-- wp:html -->\n<details>\n\t<summary>Does the FAQ need JavaScript?</summary>\n\t<p>No. This pattern uses native HTML details and summary elements.</p>\n</details>\n<details>\n\t<summary>Where should FAQ schema live?</summary>\n\t<p>In a companion plugin, not in the theme.</p>\n</details>\n<details>\n\t<summary>Can this theme work with page builders?</summary>\n\t<p>Yes, with the Canvas template.</p>\n</details>\n<!-- /wp:html -->\n</div>\n<!-- /wp:group -->",
			)
		);
	},
	20
);
