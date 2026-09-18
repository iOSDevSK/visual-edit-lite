<?php
/**
 * The sections a page can be composed out of.
 *
 * A block theme registers its patterns — a hero, a testimonial row, a footer
 * strip — and those are the pieces somebody assembling a page actually wants.
 * Two callers need the same list for the same reason: an extension that composes
 * a page by naming patterns, and the editor's "Add section" browser, where a
 * person picks one by eye. Keeping one list means a pattern the theme hides
 * from the inserter is hidden from both, and a pattern nobody may insert is
 * not one the model can propose either.
 *
 * On a native block theme the list also carries the sections saved from this
 * site's own pages — unsynced `wp_block` posts, which is what WordPress's own
 * "Create pattern" writes. They are in the same list for the same reason the
 * theme's are: one list means one set of rules about what may be inserted,
 * and the row's `source` says which kind it is so a caller that cares — the
 * assistant, which must not mistake this site's real words for a theme's demo
 * copy — can tell them apart. Synced patterns are deliberately left out: they
 * land content-only locked, which is not a section somebody can then edit.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Patterns {

	/** What WordPress names a wp_block post in the inserter. */
	const SAVED_PREFIX = 'core/block/';

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
		// A theme's patterns are namespaced by whatever the theme chose, and
		// that is very often NOT its folder name: a theme in
		// `acme-photo-2.4.0/` routinely registers `acme-photo/hero`. Matching
		// only on the folder found nothing on such a theme, and "Add section"
		// came up empty with thirteen of the theme's own patterns registered.
		// The text domain is the theme's own name for itself, so it is the
		// prefix to match beside the two directory names.
		$mine = array( get_stylesheet() . '/', get_template() . '/' );
		foreach ( array( wp_get_theme(), wp_get_theme( get_template() ) ) as $theme ) {
			$domain = $theme instanceof WP_Theme ? (string) $theme->get( 'TextDomain' ) : '';
			if ( '' !== $domain ) {
				$mine[] = $domain . '/';
			}
		}
		$mine = array_unique( $mine );
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

			$out[] = array(
				'name'        => $name,
				'title'       => isset( $pattern['title'] ) ? (string) $pattern['title'] : $name,
				'categories'  => array_values( (array) ( isset( $pattern['categories'] ) ? $pattern['categories'] : array() ) ),
				'description' => isset( $pattern['description'] ) ? (string) $pattern['description'] : '',
				'content'     => $content,
				'preview'     => self::preview( $content ),
				'source'      => 'theme',
			);
		}

		// Saved sections only where they mean anything. This same list feeds
		// the raw-HTML editor's section browser on a converted theme
		// (class-rest.php → assets/editor.js), where a wp_block post is not a
		// section anybody can insert — so the gate is here, once, rather than
		// in each caller.
		if ( class_exists( 'Clara_VE_Native_Gutenberg' ) && Clara_VE_Native_Gutenberg::is_native_mode() ) {
			$out = array_merge( $out, self::saved() );
		}
		return $out;
	}

	/**
	 * The sections saved from this site's own pages.
	 *
	 * Unsynced `wp_block` posts, which is exactly what core's "Create pattern"
	 * writes when the person leaves "Synced" off: the copy that lands on the
	 * next page is plain core blocks, editable block by block, and it survives
	 * this plugin being deactivated. A synced one is a reference — it renders
	 * through the original and is locked to content-only editing — so it is
	 * not offered as a section at all.
	 *
	 * @return array[] name, title, categories, description, content, preview, source.
	 */
	public static function saved() {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_key'    => 'wp_pattern_sync_status',
				'meta_value'  => 'unsynced',
			)
		) as $post ) {
			$terms      = wp_get_object_terms( $post->ID, 'wp_pattern_category', array( 'fields' => 'slugs' ) );
			$categories = is_wp_error( $terms ) ? array() : array_values( (array) $terms );
			// The same rule the editor's browser applies to the theme's own:
			// a header or a footer belongs to a template part, not between a
			// page's sections.
			if ( array_intersect( $categories, array( 'header', 'footer' ) ) ) {
				continue;
			}
			$content = (string) $post->post_content;
			// A pattern built on core's Patterns screen may hold a reference
			// to a synced one, or a template part. Inserted, it would arrive
			// content-only locked — the very thing excluding synced patterns
			// is meant to prevent. One list, one rule.
			if ( false !== strpos( $content, '<!-- wp:block ' ) || false !== strpos( $content, '<!-- wp:template-part ' ) ) {
				continue;
			}

			$out[] = array(
				'name'        => self::SAVED_PREFIX . $post->ID,
				'title'       => get_the_title( $post ) ? get_the_title( $post ) : 'Section ' . $post->ID,
				'categories'  => $categories,
				'description' => (string) $post->post_excerpt,
				'content'     => $content,
				'preview'     => self::preview( $content ),
				'source'      => 'saved',
			);
		}
		return $out;
	}

	/**
	 * What a section SAYS, not what it is made of.
	 *
	 * The model's next move after reading this list is a find/replace against
	 * the words, and it cannot quote what it has not been shown; the editor's
	 * browser searches the same string.
	 *
	 * @param string $content
	 * @return string At most 240 characters.
	 */
	private static function preview( $content ) {
		$preview = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $content ) ) );
		return strlen( $preview ) > 240 ? substr( $preview, 0, 237 ) . '…' : $preview;
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
