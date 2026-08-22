<?php
/**
 * Copying a page, and taking one out of service, from inside the editor.
 *
 * Both were things you had to leave for the Pages list — or, for copying, for
 * a second plugin. Neither is hard; what makes them worth doing carefully is
 * that a page here is not only its post row. It carries the key that finds its
 * source, its search-appearance record, its small-screen rules, its featured
 * image and whatever a third-party plugin has hung on it. A copy that brings
 * some of those and not others looks right and is not.
 *
 * The rule for meta is a DENYLIST, deliberately. "Everything that belongs to
 * the page" includes fields this plugin has never heard of — an SEO plugin's
 * own record, a page-builder's leftovers — and an allowlist would drop exactly
 * those, silently, with no way to notice until much later.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duplicate and trash, for the editor's own toolbar.
 */
class Clara_VE_Page_Actions {

	/**
	 * Meta a copy must NOT inherit.
	 *
	 * Editing bookkeeping, WordPress's own slug history, and the four fields
	 * that say WHICH page this is rather than what is on it: the source key is
	 * minted fresh below, and the parked slug/status pair belongs to whatever
	 * put the original away, not to a copy made today.
	 */
	const NEVER_COPIED = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		CLARA_VE_PAGE_KEY_META,
		CLARA_VE_PAGE_SLUG_META,
		CLARA_VE_PAGE_STATUS_META,
		CLARA_VE_PAGE_THEME_META,
	);

	/**
	 * Copy a page, with everything that belongs to it.
	 *
	 * The copy is always a DRAFT. A published copy would be live at its own
	 * address the instant it existed, carrying the original's every word — and
	 * publishing is one click away in WordPress, whereas un-publishing
	 * something Google has already seen is not.
	 *
	 * @param int    $post_id Page to copy.
	 * @param string $title   Title for the copy. Falls back to "<original> (copy)".
	 * @param string $slug    Slug for the copy. Falls back to one derived from the title.
	 * @return array|WP_Error {id, key, slug, slug_changed, edit_url}
	 */
	public static function duplicate( $post_id, $title = '', $slug = '' ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'clara_ve_no_page', __( 'That page no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'clara_ve_forbidden', __( 'You are not allowed to copy this page.', 'visual-edit-lite' ), array( 'status' => 403 ) );
		}

		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( '' === $title ) {
			/* translators: %s: the original page's title */
			$title = sprintf( __( '%s (copy)', 'visual-edit-lite' ), $post->post_title );
		}
		$slug = sanitize_title( '' !== trim( (string) $slug ) ? $slug : $title );
		if ( '' === $slug ) {
			$slug = sanitize_title( $title . '-copy' );
		}

		$copy_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'draft',
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_author'    => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $copy_id ) ) {
			return $copy_id;
		}

		self::copy_meta( $post->ID, (int) $copy_id );
		self::copy_terms( $post->ID, (int) $copy_id );
		update_post_meta( (int) $copy_id, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );

		// The source. A block page keeps its content in post_content, which the
		// insert above already carried, and its key is derived from its own ID —
		// there is nothing to mint and nothing to copy. A legacy page's content
		// lives in a row addressed by its key, so the copy needs a key of its
		// own and its own row, or the two pages would edit one another.
		$old_key = (string) get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true );
		if ( '' !== $old_key ) {
			$new_key = self::mint_key( $slug );
			update_post_meta( (int) $copy_id, CLARA_VE_PAGE_KEY_META, $new_key );
			$source = Clara_VE_Source_Store::get_current_source( $old_key );
			if ( is_string( $source ) && '' !== $source ) {
				Clara_VE_Source_Store::save_source( $new_key, $source );
			}
			// AFTER the row exists, so the copy's "Original" is the content it
			// actually starts from rather than an empty page.
			Clara_VE_History::ensure_baseline( $new_key );
		}

		$stored = get_post( (int) $copy_id );

		return array(
			'id'           => (int) $copy_id,
			'key'          => self::key_for( $stored ),
			'slug'         => $stored ? $stored->post_name : $slug,
			// wp_unique_post_slug() ignores post_status for hierarchical types,
			// so a page in the trash still holds its address and the copy
			// quietly becomes `about-2`. Reported rather than fought: the alternative
			// is emptying somebody's trash on their behalf.
			'slug_changed' => (bool) ( $stored && $stored->post_name !== $slug ),
			'edit_url'     => admin_url( 'post.php?post=' . (int) $copy_id . '&action=edit' ),
		);
	}

	/**
	 * Put a page in the trash.
	 *
	 * Recoverable on purpose — WordPress keeps it, and the editor says so
	 * before doing it.
	 *
	 * @param int $post_id Page to remove.
	 * @return array|WP_Error {id, title}
	 */
	public static function trash( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'clara_ve_no_page', __( 'That page no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error( 'clara_ve_forbidden', __( 'You are not allowed to remove this page.', 'visual-edit-lite' ), array( 'status' => 403 ) );
		}
		// The one page whose removal breaks the whole site rather than itself:
		// page_on_front would go on naming it, and the home page would render
		// nothing at all. Refused with a reason, not silently repointed —
		// choosing a front page is the site owner's decision.
		if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
			return new WP_Error(
				'clara_ve_is_front_page',
				__( 'This is the site\'s front page. Choose a different front page under Settings → Reading first, or the home page would be left empty.', 'visual-edit-lite' ),
				array( 'status' => 409 )
			);
		}

		$title = $post->post_title;
		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'clara_ve_trash_failed', __( 'WordPress would not move that page to the trash.', 'visual-edit-lite' ), array( 'status' => 500 ) );
		}

		return array(
			'id'    => (int) $post->ID,
			'title' => $title,
		);
	}

	/**
	 * The key the editor addresses a page by.
	 *
	 * @param WP_Post|null $post Page.
	 * @return string
	 */
	public static function key_for( $post ) {
		if ( ! $post ) {
			return '';
		}
		$block = Clara_VE_Source_Store::block_key( $post );
		if ( '' !== $block ) {
			return $block;
		}
		return (string) get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true );
	}

	/**
	 * A source key no other page is using, and that is not one of the reserved
	 * ones.
	 *
	 * A page slugged "header" must not end up owning the header template part,
	 * which is what taking its slug verbatim would do.
	 *
	 * @param string $slug Slug the copy was given.
	 * @return string
	 */
	private static function mint_key( $slug ) {
		$reserved = array(
			CLARA_VE_DEFAULT_KEY,
			CLARA_VE_HEADER_KEY,
			CLARA_VE_FOOTER_KEY,
			CLARA_VE_ARTICLE_KEY,
			CLARA_VE_404_KEY,
		);

		$base = sanitize_key( $slug );
		if ( '' === $base ) {
			$base = 'page';
		}
		$key = $base;
		$n   = 2;
		while ( in_array( $key, $reserved, true ) || Clara_VE_Source_Store::find_page_by_key( $key ) ) {
			$key = $base . '-' . $n;
			++$n;
			if ( $n > 200 ) {
				$key = $base . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
				break;
			}
		}
		return $key;
	}

	/**
	 * Every meta field except the ones that say which page this is.
	 *
	 * @param int $from Original.
	 * @param int $to   Copy.
	 * @return void
	 */
	private static function copy_meta( $from, $to ) {
		$all = get_post_meta( $from );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( $all as $meta_key => $values ) {
			if ( in_array( $meta_key, self::NEVER_COPIED, true ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				// get_post_meta() without a key returns values as they sit in
				// the database — still serialized. Handing those straight to
				// add_post_meta() serializes them a second time, and the copy's
				// search-appearance record comes back as a string that only
				// looks like an array.
				$value = maybe_unserialize( $value );

				if ( Clara_VE_SEO::META === $meta_key && is_array( $value ) ) {
					// A canonical copied verbatim points the copy at the
					// original, which tells every search engine not to index
					// the copy — the one field where "everything that belongs
					// to the page" is the wrong answer, because it belongs to
					// the ADDRESS rather than to the content.
					$value['canonical'] = '';
				}

				add_post_meta( $to, $meta_key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Whatever the page was filed under.
	 *
	 * @param int $from Original.
	 * @param int $to   Copy.
	 * @return void
	 */
	private static function copy_terms( $from, $to ) {
		foreach ( get_object_taxonomies( 'page' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $to, $terms, $taxonomy );
			}
		}
	}
}
