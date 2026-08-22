<?php
/**
 * The sections a page can be composed out of.
 *
 * A block theme registers its patterns — a hero, a testimonial row, a footer
 * strip — and those are the pieces somebody assembling a page actually wants.
 * Two callers need the same list for the same reason: the AI, which composes a
 * page by naming patterns, and the editor's "Add section" browser, where a
 * person picks one by eye. Keeping one list means a pattern the theme hides
 * from the inserter is hidden from both, and a pattern nobody may insert is
 * not one the model can propose either.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Patterns {

	/**
	 * Patterns this site's own theme offers as page sections.
	 *
	 * Core's bundled patterns are excluded deliberately: the point of
	 * composing from patterns is that the result is already on-design, and a
	 * core "three columns of text" is on nobody's design. So is anything the
	 * theme marked `inserter: false` — that is the theme saying a piece is
	 * machinery, not a section somebody assembles a page out of.
	 *
	 * Registration route is not a reliable filter on its own: a pattern
	 * registered from /patterns/*.php carries source 'theme', one registered
	 * by hand in PHP carries no source at all, and both are the theme's.
	 *
	 * @return array[] name, title, categories, description, content, preview.
	 */
	public static function composable() {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}
		$mine = array( get_stylesheet() . '/', get_template() . '/' );
		$out  = array();

		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$name   = isset( $pattern['name'] ) ? (string) $pattern['name'] : '';
			$source = isset( $pattern['source'] ) ? (string) $pattern['source'] : '';
			if ( '' === $name || 'core' === $source ) {
				continue;
			}
			if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
				continue;
			}
			$owned = ( 'theme' === $source );
			foreach ( $mine as $prefix ) {
				$owned = $owned || 0 === strpos( $name, $prefix );
			}
			if ( ! $owned ) {
				continue;
			}

			$content = (string) ( isset( $pattern['content'] ) ? $pattern['content'] : '' );

			// What the pattern SAYS, not what it is made of: the model's next
			// move is a find/replace against this text, and it cannot quote
			// what it has not been shown.
			$preview = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
			if ( strlen( $preview ) > 240 ) {
				$preview = substr( $preview, 0, 237 ) . '…';
			}

			$out[] = array(
				'name'        => $name,
				'title'       => isset( $pattern['title'] ) ? (string) $pattern['title'] : $name,
				'categories'  => array_values( (array) ( isset( $pattern['categories'] ) ? $pattern['categories'] : array() ) ),
				'description' => isset( $pattern['description'] ) ? (string) $pattern['description'] : '',
				'content'     => $content,
				'preview'     => $preview,
			);
		}
		return $out;
	}

	/**
	 * One composable pattern by name, or null.
	 *
	 * Looked up through composable() rather than the registry, so a pattern
	 * the theme hides from the inserter cannot be inserted by naming it
	 * directly — the list somebody may choose from and the list the server
	 * will act on are the same list.
	 *
	 * @param string $name
	 * @return array|null
	 */
	public static function get( $name ) {
		foreach ( self::composable() as $pattern ) {
			if ( $pattern['name'] === $name ) {
				return $pattern;
			}
		}
		return null;
	}
}
