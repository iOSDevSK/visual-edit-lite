<?php
/**
 * What the plugin remembers about a converted theme once that theme can no
 * longer speak for itself.
 *
 * Everything the plugin knows about a theme's markup arrives through the
 * theme's own `clara_ve_theme_contract` filter, registered from its
 * functions.php — and WordPress loads functions.php for the ACTIVE theme only.
 * The moment a theme is deactivated its keys, its navigation locations and its
 * anchors are simply gone from the running site. clara_ve_theme_is_converted()
 * works around that with a disk probe, which holds up right until the theme
 * directory is deleted — precisely when the plugin needs to know what to clean
 * up, and precisely when the answer has just been thrown away.
 *
 * So the facts are copied into the database while the theme is present and can
 * still be asked. One option, one record per theme, surviving both
 * deactivation and deletion.
 *
 * The record also carries `before`: the site options as they stood immediately
 * BEFORE this theme's content was imported. show_on_front, permalink_structure
 * and the SEO identity are single site-wide values with no per-theme
 * equivalent, so an import necessarily takes them over. Without a record of
 * what they were, leaving the theme cannot put them back and the site keeps a
 * departed design's front page and business identity for good. It is written
 * once, on the first import, and never rewritten — a second import would
 * otherwise snapshot the theme's own values as "before" and turn the restore
 * into a no-op.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Theme_Registry {

	const OPTION = 'clara_ve_themes';

	/**
	 * Term meta naming the theme a term was created under. Same name as the
	 * post meta on purpose — one idea, one key, whatever kind of object carries
	 * it.
	 */
	const TERM_META = '_clara_ve_theme';

	/**
	 * Post types that must never be stamped.
	 *
	 * revision follows its parent. nav_menu_item is owned through its menu's
	 * term, and stamping thousands of items to say what one term already says
	 * is bookkeeping that can only go out of sync. The wp_* types are core's
	 * own, and core already scopes them per theme through the wp_theme
	 * taxonomy — a second, competing record of the same fact.
	 *
	 * @return string[]
	 */
	private static function unstampable_types() {
		return array(
			'revision',
			'nav_menu_item',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'customize_changeset',
			'oembed_cache',
			'user_request',
		);
	}

	/**
	 * Taxonomies that must never be stamped: core's own template-part
	 * bookkeeping, and post_format, which is a fixed vocabulary rather than
	 * anything a theme brings.
	 *
	 * @return string[]
	 */
	private static function unstampable_taxonomies() {
		return array( 'wp_theme', 'wp_template_part_area', 'post_format' );
	}

	/**
	 * The theme that should be recorded as the owner of anything created right
	 * now, or '' when nothing should be.
	 *
	 * The owner decided that what they make while a converted theme is active
	 * belongs to that theme and leaves with it. The converse matters just as
	 * much: with a stock theme active there is no converted design to belong
	 * to, so a post written then is the site's own and is stamped for nobody —
	 * which is what keeps it alive through every theme that comes and goes.
	 *
	 * @return string
	 */
	public static function active_owner() {
		$slug = sanitize_key( get_stylesheet() );
		if ( '' === $slug ) {
			return '';
		}
		return self::known( $slug ) ? $slug : '';
	}

	/**
	 * Record the active theme as a post's owner. Never overwrites: a stamp is
	 * a statement about where something came from, and that does not change
	 * because someone edited it afterwards.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public static function stamp_post( $post_id ) {
		$post_id = (int) $post_id;
		$owner   = self::active_owner();
		if ( ! $post_id || '' === $owner ) {
			return;
		}
		if ( in_array( get_post_type( $post_id ), self::unstampable_types(), true ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $post_id, CLARA_VE_PAGE_THEME_META, true ) ) {
			return;
		}
		update_post_meta( $post_id, CLARA_VE_PAGE_THEME_META, $owner );
	}

	/**
	 * @param int    $term_id
	 * @param int    $tt_id    Unused; the signature is created_term's.
	 * @param string $taxonomy
	 * @return void
	 */
	public static function stamp_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$term_id = (int) $term_id;
		$owner   = self::active_owner();
		if ( ! $term_id || '' === $owner ) {
			return;
		}
		if ( in_array( (string) $taxonomy, self::unstampable_taxonomies(), true ) ) {
			return;
		}
		if ( '' !== (string) get_term_meta( $term_id, self::TERM_META, true ) ) {
			return;
		}
		update_term_meta( $term_id, self::TERM_META, $owner );
	}

	/**
	 * Creation, as opposed to any later save.
	 *
	 * Hooked to transition_post_status rather than save_post because only a
	 * transition can tell the two apart reliably: the block editor writes an
	 * auto-draft first, so the save that looks like the first one to save_post
	 * already carries $update = true. Getting this wrong would be worse than
	 * missing a stamp — stamping on every save would hand the active theme
	 * ownership of an old post the moment anyone edited it, and that post would
	 * then be deleted along with a theme it never belonged to.
	 *
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 * @return void
	 */
	public static function stamp_on_create( $new_status, $old_status, $post ) {
		if ( 'new' !== $old_status && 'auto-draft' !== $old_status ) {
			return;
		}
		if ( 'auto-draft' === $new_status || ! $post instanceof WP_Post ) {
			return;
		}
		self::stamp_post( $post->ID );
	}

	/**
	 * The site-wide options a theme's import takes over. Each is a single value
	 * for the whole install — there is no per-theme WordPress mechanism for any
	 * of them, which is why they need remembering rather than scoping.
	 *
	 * theme_mods_{stylesheet} is deliberately absent: WordPress already keeps
	 * one row per theme, so menu assignment, custom logo and the rest come back
	 * on their own and copying them here would only create a second, staler
	 * copy to disagree with.
	 *
	 * @return string[]
	 */
	public static function site_options() {
		return array(
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'permalink_structure',
			'blogname',
			'blogdescription',
			'clara_ve_seo_entity_type',
			'clara_ve_seo_entity_name',
			'clara_ve_seo_entity_logo',
			'clara_ve_seo_entity_extra',
			'clara_ve_seo_same_as',
			'clara_ve_seo_default_og_image',
			'clara_ve_seo_title_separator',
			'clara_ve_google_fonts',
		);
	}

	/**
	 * The subset of site_options() that leaving a theme puts back.
	 *
	 * Everything is recorded; only these are restored. The rest —
	 * permalink_structure, blogname, blogdescription — are site decisions a
	 * theme's import merely seeded, and by the time it leaves the owner's own
	 * posts have addresses that depend on the permalink structure and their
	 * visitors have bookmarks that depend on the name. Reverting those would
	 * break content that has nothing to do with the theme, in order to be
	 * tidy about content that does.
	 *
	 * @return string[]
	 */
	public static function restorable_site_options() {
		return array(
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'clara_ve_seo_entity_type',
			'clara_ve_seo_entity_name',
			'clara_ve_seo_entity_logo',
			'clara_ve_seo_entity_extra',
			'clara_ve_seo_same_as',
			'clara_ve_seo_default_og_image',
			'clara_ve_seo_title_separator',
			'clara_ve_google_fonts',
		);
	}

	/**
	 * @return array<string,array> Every remembered theme, keyed by stylesheet.
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $slug Stylesheet.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$rows = self::all();
		$slug = sanitize_key( $slug );
		return isset( $rows[ $slug ] ) ? $rows[ $slug ] : null;
	}

	/**
	 * Is this a theme the plugin has a record of? Unlike
	 * clara_ve_theme_is_converted(), this answers from the database and so keeps
	 * answering after the theme's files are gone.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function known( $slug ) {
		return null !== self::get( $slug );
	}

	/**
	 * Merge fields into a theme's record, creating it if absent.
	 *
	 * @param string $slug
	 * @param array  $patch
	 * @return void
	 */
	public static function remember( $slug, array $patch ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return;
		}
		$rows          = self::all();
		$rows[ $slug ] = array_merge(
			isset( $rows[ $slug ] ) ? $rows[ $slug ] : array(),
			$patch
		);
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * @param string $slug
	 * @return void
	 */
	public static function forget( $slug ) {
		$rows = self::all();
		$slug = sanitize_key( $slug );
		if ( ! isset( $rows[ $slug ] ) ) {
			return;
		}
		unset( $rows[ $slug ] );
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * The current values of every option an import takes over.
	 *
	 * A missing option is recorded as null rather than skipped, so restoring
	 * can tell "it was empty before" from "we never looked" — deleting an option
	 * the site never had is the correct restore, and leaving the theme's value
	 * in place is not.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot_site_options() {
		$out = array();
		foreach ( self::site_options() as $name ) {
			$out[ $name ] = get_option( $name, null );
		}
		return $out;
	}

	/**
	 * Record a theme at the moment its content is imported.
	 *
	 * MUST be called before the first apply_* writes anything, or the "before"
	 * snapshot captures values the import itself has already changed.
	 *
	 * @param string $slug
	 * @return void
	 */
	public static function note_import( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return;
		}
		$patch = self::describe( $slug );
		$patch['imported_at'] = current_time( 'mysql' );

		// array_key_exists, not isset: backfill() records `before` as null to
		// mean "this theme took the options over before anyone was writing it
		// down". isset() reads that as absent, so a re-import would overwrite
		// the honest unknown with a snapshot of the theme's OWN values — and a
		// later restore would then put the departing theme's front page back
		// and call it the site's.
		$existing = self::get( $slug );
		if ( ! is_array( $existing ) || ! array_key_exists( 'before', $existing ) ) {
			$patch['before'] = self::snapshot_site_options();
		}
		self::remember( $slug, $patch );
	}

	/**
	 * Re-copy what the ACTIVE theme declares, leaving the "before" snapshot
	 * alone. Cheap enough to run on every admin request: it only writes when
	 * something actually changed, and a theme whose contract has been edited
	 * (a nav zone added, a key renamed) would otherwise be remembered wrong
	 * until its next import, which may never come.
	 *
	 * @return void
	 */
	public static function refresh_active() {
		$slug = sanitize_key( get_stylesheet() );
		if ( '' === $slug || ! self::known( $slug ) ) {
			return;
		}
		$patch    = self::describe( $slug );
		$existing = self::get( $slug );
		foreach ( $patch as $field => $value ) {
			if ( ! isset( $existing[ $field ] ) || $existing[ $field ] !== $value ) {
				self::remember( $slug, $patch );
				return;
			}
		}
	}

	/**
	 * Give every converted theme already on this install a record.
	 *
	 * Runs once. Anything imported before this class existed has no record, and
	 * without one it is invisible to everything built on top of the registry —
	 * an install that has been running for months would get the whole theme
	 * lifecycle only for themes imported after the update, which is no use to
	 * the person who needs it most.
	 *
	 * What cannot be recovered is the `before` snapshot: the site options were
	 * taken over at some point in the past and what they were then is not
	 * written down anywhere. It is recorded as `null` — explicitly unknown —
	 * rather than as the current values, which would be this theme's own and
	 * would make a restore silently keep them. Leaving such a theme therefore
	 * restores everything except those options, and says so.
	 *
	 * @return void
	 */
	public static function backfill() {
		if ( '1' === get_option( 'clara_ve_theme_registry_backfill', '' ) ) {
			return;
		}
		update_option( 'clara_ve_theme_registry_backfill', '1', false );

		foreach ( array_keys( (array) wp_get_themes() ) as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || self::known( $slug ) ) {
				continue;
			}
			if ( ! function_exists( 'clara_ve_theme_is_known' ) || ! clara_ve_theme_is_known( $slug ) ) {
				continue;
			}
			$patch           = self::describe( $slug );
			$patch['before'] = null;
			self::remember( $slug, $patch );
		}
	}

	/**
	 * What can be learned about a theme right now: its name from the stylesheet
	 * header (available for any installed theme), and its keys and navigation
	 * locations from the contract (available only while it is active — so those
	 * two fields are simply absent from the patch when it is not, rather than
	 * being written as empty over a record that already had them).
	 *
	 * @param string $slug
	 * @return array
	 */
	private static function describe( $slug ) {
		$theme = wp_get_theme( $slug );
		$patch = array(
			'name' => $theme->exists() ? (string) $theme->get( 'Name' ) : $slug,
		);

		if ( sanitize_key( get_stylesheet() ) !== sanitize_key( $slug ) ) {
			return $patch;
		}

		$contract  = clara_ve_theme_contract();
		$locations = array();
		foreach ( $contract['menus'] as $menu ) {
			$locations[ $menu['location'] ] = isset( $menu['label'] ) ? $menu['label'] : $menu['location'];
		}
		$patch['locations'] = $locations;

		$keys = array();
		foreach ( Clara_VE_Source_Store::list_keys() as $entry ) {
			$keys[] = $entry['key'];
		}
		$patch['keys'] = array_values( array_unique( $keys ) );

		return $patch;
	}
}
