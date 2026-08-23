<?php
/**
 * Putting a converted theme's world away when it is deactivated, and taking it
 * back out when it returns.
 *
 * The promise is the ordinary one an owner already expects from WordPress:
 * deactivating a theme leaves the site behaving as though that theme had never
 * been installed, and reactivating it brings everything back exactly as it was.
 * A converted theme makes that harder than a normal one, because it does not
 * only supply a design — its import created pages, posts, images, menus,
 * categories and redirects, and set the front page. None of that goes away on
 * its own, so a deactivated converted theme used to leave a site full of
 * content belonging to a design nobody could see.
 *
 * NOTHING HERE DELETES ANYTHING. Parking is a reversible change of state:
 * a status, a slug, a flag, an option put back to what it was. Deletion is
 * a separate, explicit act with its own warning.
 *
 * Two mechanisms, because they solve different problems and neither is
 * sufficient alone:
 *
 * - the STATUS takes a page out of every query — the site, the search, the
 *   sitemap, the Pages screen.
 * - the SLUG frees the address. wp_unique_post_slug() does not consider
 *   post_status at all for hierarchical types, so a parked page still holding
 *   `about` sends the next theme's page to `about-2` — the collision this
 *   whole surface exists to prevent.
 *
 * Both run inside `switch_theme`, park and restore together, deliberately not
 * split across `switch_theme` and `after_switch_theme`. The latter fires on the
 * NEXT request, so restoring there would serve one request — the redirect
 * straight after activating a theme, the one the owner is looking at — from a
 * site whose content was still put away.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Theme_Park {

	/**
	 * Marks an attachment as belonging to a theme that is currently away.
	 * Attachments keep post_status `inherit`, which WordPress relies on
	 * throughout, so their absence is arranged by query filters rather than by
	 * status — and those filters need something to test.
	 */
	const PARKED_META = '_clara_ve_parked';

	/**
	 * The themes whose content is currently put away.
	 *
	 * The registry flag is the record, and the content is the truth. They can
	 * disagree: park writes statuses and then the flag, so a crash between the
	 * two leaves content away with the registry saying otherwise — and so does
	 * handing the lifecycle back and forth with a converted theme, which owns
	 * the same park/restore when this plugin is inactive. That handover is how
	 * it was first seen: three converted themes on one site, this plugin
	 * switched off while one of them was parked, and every theme's menus turned
	 * up in every other theme, because the flag said nothing was away.
	 *
	 * Believing both heals it with no migration, in whichever side is running.
	 * The ACTIVE theme is never in the set — during its own restore its content
	 * is briefly still parked, and hiding a theme's menus from itself is the one
	 * answer always wrong.
	 *
	 * Memoized per request: the registry is one non-autoloaded option and the
	 * filters below ask on every query.
	 *
	 * @return string[]
	 */
	public static function parked_themes() {
		if ( null !== self::$parked_memo ) {
			return self::$parked_memo;
		}
		$parked = array();
		foreach ( Clara_VE_Theme_Registry::all() as $slug => $record ) {
			if ( ! empty( $record['parked'] ) ) {
				$parked[] = $slug;
			}
		}
		global $wpdb;
		$owners = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				   JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				  WHERE pm.meta_key = %s AND p.post_status = %s",
				CLARA_VE_PAGE_THEME_META,
				CLARA_VE_PARKED_STATUS
			)
		);
		foreach ( (array) $owners as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && ! in_array( $slug, $parked, true ) ) {
				$parked[] = $slug;
			}
		}
		$parked            = array_values( array_diff( $parked, array( sanitize_key( get_stylesheet() ) ) ) );
		self::$parked_memo = $parked;
		return $parked;
	}

	/**
	 * Parking and restoring both change the answer inside the very request
	 * that made the change — and that request goes on to run queries.
	 *
	 * @var string[]|null
	 */
	private static $parked_memo = null;

	/**
	 * @return void
	 */
	public static function flush_parked_memo() {
		self::$parked_memo = null;
	}

	/**
	 * True while this class is looking for its own content.
	 *
	 * The filters below hide a parked theme's images from every query — which
	 * includes the query restore() makes to FIND them again, so the flag would
	 * never be cleared and each round trip would leave the library a little
	 * more wrong than the last. The filters serve the rest of WordPress, not
	 * the bookkeeping that maintains them.
	 *
	 * @var bool
	 */
	private static $internal = false;

	public static function init() {
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'hide_parked_attachments' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_parked_from_queries' ) );
		add_filter( 'get_terms_args', array( __CLASS__, 'hide_parked_terms' ), 10, 2 );
		add_filter( 'wp_get_nav_menus', array( __CLASS__, 'hide_parked_menus' ) );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'hide_items_pointing_at_parked' ) );
		add_filter( 'get_user_option_nav_menu_recently_edited', array( __CLASS__, 'forget_parked_recent_menu' ) );
		add_action( 'load-nav-menus.php', array( __CLASS__, 'leave_parked_menu_screen' ) );
	}

	/**
	 * A meta_query clause excluding everything belonging to a parked theme.
	 *
	 * NOT EXISTS is included on purpose: content stamped for nobody — the
	 * owner's own, or anything predating stamping — must go on being visible.
	 *
	 * @return array|null Null when nothing is parked.
	 */
	private static function exclusion_clause( $meta_key ) {
		$parked = self::parked_themes();
		if ( ! $parked ) {
			return null;
		}
		return array(
			'relation' => 'OR',
			array(
				'key'     => $meta_key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => $meta_key,
				'value'   => $parked,
				'compare' => 'NOT IN',
			),
		);
	}

	/**
	 * Keep a parked theme's images out of the media modal.
	 *
	 * Their post_status stays `inherit` — WordPress relies on it everywhere an
	 * attachment is involved — so unlike pages they cannot be hidden by status
	 * and are hidden by asking instead.
	 *
	 * @param array $args
	 * @return array
	 */
	public static function hide_parked_attachments( $args ) {
		if ( self::$internal ) {
			return $args;
		}
		$clause = self::exclusion_clause( self::PARKED_META );
		if ( $clause ) {
			$args['meta_query'] = isset( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				? array( 'relation' => 'AND', $args['meta_query'], $clause )
				: $clause;
		}
		return $args;
	}

	/**
	 * The same, for the Media library list table and any other attachment
	 * query. Deliberately narrow: only attachment queries, and never a query
	 * for one specific post, so nothing that already knows which attachment it
	 * wants is denied it.
	 *
	 * @param WP_Query $query
	 * @return void
	 */
	public static function hide_parked_from_queries( $query ) {
		if ( self::$internal || ! $query instanceof WP_Query || $query->get( 'p' ) || $query->get( 'post__in' ) ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( array( 'attachment' ) !== array_values( array_filter( $types ) ) ) {
			return;
		}
		$clause = self::exclusion_clause( self::PARKED_META );
		if ( ! $clause ) {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, $clause ) : $clause );
	}

	/**
	 * Keep a parked theme's categories out of term queries.
	 *
	 * @param array $args
	 * @param array $taxonomies
	 * @return array
	 */
	public static function hide_parked_terms( $args, $taxonomies ) {
		if ( self::$internal ) {
			return $args;
		}
		// Core's own template-part bookkeeping must keep resolving, or block
		// templates stop being found.
		if ( array_intersect( (array) $taxonomies, array( 'wp_theme', 'wp_template_part_area' ) ) ) {
			return $args;
		}
		if ( ! empty( $args['include'] ) || ! empty( $args['slug'] ) || ! empty( $args['term_taxonomy_id'] ) ) {
			return $args;
		}
		$clause = self::exclusion_clause( Clara_VE_Theme_Registry::TERM_META );
		if ( $clause ) {
			$args['meta_query'] = isset( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				? array( 'relation' => 'AND', $args['meta_query'], $clause )
				: $clause;
		}
		return $args;
	}

	/**
	 * Keep a parked theme's menus out of Appearance → Menus and everywhere
	 * else. get_terms_args does not cover this: wp_get_nav_menus() asks for
	 * nav_menu terms with its own arguments and the result is what the menu
	 * screens enumerate.
	 *
	 * @param array $menus
	 * @return array
	 */
	public static function hide_parked_menus( $menus ) {
		$parked = self::parked_themes();
		if ( self::$internal || ! $parked || ! is_array( $menus ) ) {
			return $menus;
		}
		$out = array();
		foreach ( $menus as $menu ) {
			$owner = isset( $menu->term_id ) ? (string) get_term_meta( $menu->term_id, Clara_VE_Theme_Registry::TERM_META, true ) : '';
			if ( '' === $owner || ! in_array( $owner, $parked, true ) ) {
				$out[] = $menu;
			}
		}
		return $out;
	}

	/**
	 * Which theme owns this menu, or '' for nobody's.
	 *
	 * @param int $term_id
	 * @return string
	 */
	private static function menu_owner( $term_id ) {
		$term_id = (int) $term_id;
		return $term_id ? (string) get_term_meta( $term_id, Clara_VE_Theme_Registry::TERM_META, true ) : '';
	}

	/**
	 * Forget a departed theme's menu as "the one you were last editing".
	 *
	 * Appearance → Menus does not open on the first menu in its own list. It
	 * opens on `nav_menu_recently_edited` — a per-user option holding a bare
	 * term id, which survives a theme switch untouched — and it resolves that
	 * id with is_nav_menu(), which is wp_get_nav_menu_object() and therefore
	 * get_term(). A direct fetch by id goes through neither wp_get_nav_menus
	 * nor get_terms_args, so hide_parked_menus() cannot reach it: the dropdown
	 * correctly omits the parked menu while the screen is already open on it.
	 *
	 * The items then stay visible because hide_items_pointing_at_parked() only
	 * drops post_type items pointing at parked content, and a converted theme's
	 * navigation is mostly anchors — `menu-item-type => custom`. Sixteen of the
	 * seventeen items in the case this was found in.
	 *
	 * Answering at read time rather than clearing the option on switch_theme is
	 * deliberate twice over: it heals sites that have already switched, since
	 * nav-menus.php writes the option back for whatever it does select, and it
	 * stays clear of get_user_option()'s blog-prefixed key on multisite by
	 * running after that choice has been made.
	 *
	 * @param mixed $term_id
	 * @return mixed
	 */
	public static function forget_parked_recent_menu( $term_id ) {
		$id = (int) $term_id;
		if ( self::$internal || ! $id ) {
			return $term_id;
		}
		$parked = self::parked_themes();
		if ( ! $parked ) {
			return $term_id;
		}
		return in_array( self::menu_owner( $id ), $parked, true ) ? 0 : $term_id;
	}

	/**
	 * The same screen, reached by its `?menu=` argument.
	 *
	 * nav-menus.php reads $_REQUEST['menu'] before any of this runs, so a
	 * bookmark or the back button opens a parked theme's menu however well the
	 * recently-edited pointer behaves. Send the request to the screen without
	 * the argument and let it choose from the list it is allowed to see.
	 *
	 * @return void
	 */
	public static function leave_parked_menu_screen() {
		$id = isset( $_REQUEST['menu'] ) ? (int) $_REQUEST['menu'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $id ) {
			return;
		}
		$parked = self::parked_themes();
		if ( ! $parked || ! in_array( self::menu_owner( $id ), $parked, true ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'nav-menus.php' ) );
		exit;
	}

	/**
	 * Drop menu items whose target has been parked.
	 *
	 * WordPress marks an item as pointing at something gone — `_invalid`, which
	 * _is_valid_nav_menu_item() then filters out — only when the target's status
	 * is literally `trash`. Any other status leaves get_post() succeeding and
	 * get_permalink() returning a perfectly ordinary URL, so a parked page
	 * renders as a live link to a 404.
	 *
	 * A parked theme's own menus are already gone by here; what this catches is
	 * a menu THE OWNER built that happens to include one of the theme's pages.
	 *
	 * @param array $items
	 * @return array
	 */
	public static function hide_items_pointing_at_parked( $items ) {
		if ( self::$internal || ! self::parked_themes() || ! is_array( $items ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			$object_id = isset( $item->object_id ) ? (int) $item->object_id : 0;
			if ( $object_id && isset( $item->type ) && 'post_type' === $item->type
				&& CLARA_VE_PARKED_STATUS === get_post_status( $object_id ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Every post of any type this theme owns, whatever state it is in.
	 *
	 * @param string   $theme
	 * @param string[] $types
	 * @return int[]
	 */
	public static function owned_ids( $theme, $types ) {
		$theme = sanitize_key( $theme );
		if ( '' === $theme ) {
			return array();
		}
		$was            = self::$internal;
		self::$internal = true;
		$ids            = get_posts(
			array(
				'post_type'     => $types,
				// Every state a theme's content can be in, listed rather than
				// 'any': 'any' excludes trash, and a page the owner trashed is
				// still theirs to restore or to purge. `inherit` is what an
				// attachment carries.
				'post_status'   => array(
					'publish',
					'draft',
					'pending',
					'private',
					'future',
					'trash',
					'inherit',
					CLARA_VE_PARKED_STATUS,
				),
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => CLARA_VE_PAGE_THEME_META,
						'value' => $theme,
					),
				),
			)
		);
		self::$internal = $was;
		return $ids;
	}

	/**
	 * The terms — menus, categories, tags — created under one theme.
	 *
	 * Goes through the internal guard for the same reason the post query does:
	 * a parked theme's terms are filtered out of get_terms(), including the
	 * query that has to find them in order to count or remove them.
	 *
	 * @param string $theme
	 * @return WP_Term[]
	 */
	public static function owned_terms( $theme ) {
		$theme = sanitize_key( $theme );
		if ( '' === $theme ) {
			return array();
		}
		$was            = self::$internal;
		self::$internal = true;
		$terms          = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies( array(), 'names' ) ),
				'hide_empty' => false,
				'fields'     => 'all',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Clara_VE_Theme_Registry::TERM_META,
						'value' => $theme,
					),
				),
			)
		);
		self::$internal = $was;
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Everything being held for one theme, counted.
	 *
	 * What the Parked content screen shows, and what the delete confirmation
	 * promises to destroy — the same numbers from the same place, so the
	 * warning cannot drift from what actually happens.
	 *
	 * `unattributed_submissions` is counted separately and deliberately. A
	 * submission taken before submissions carried a theme cannot be attributed
	 * to one afterwards: the only thing naming its origin is a form_id the page
	 * supplied, and two designs both calling theirs "contact" is the ordinary
	 * case. Rather than guess or stay silent, the count is shown for what it
	 * is.
	 *
	 * @param string $theme
	 * @return array<string,int>
	 */
	public static function inventory( $theme ) {
		global $wpdb;
		$theme = sanitize_key( $theme );

		$pages = 0;
		$posts = 0;
		foreach ( self::owned_ids( $theme, array( 'page', 'post' ) ) as $id ) {
			if ( 'page' === get_post_type( $id ) ) {
				++$pages;
			} else {
				++$posts;
			}
		}

		$menus = 0;
		$terms = 0;
		foreach ( self::owned_terms( $theme ) as $term ) {
			if ( 'nav_menu' === $term->taxonomy ) {
				++$menus;
			} else {
				++$terms;
			}
		}

		$submissions = count(
			get_posts(
				array(
					'post_type'     => Clara_VE_Forms::CPT,
					'post_status'   => 'any',
					'numberposts'   => -1,
					'fields'        => 'ids',
					'no_found_rows' => true,
					'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => CLARA_VE_PAGE_THEME_META,
							'value' => $theme,
						),
					),
				)
			)
		);
		$orphan_submissions = count(
			get_posts(
				array(
					'post_type'     => Clara_VE_Forms::CPT,
					'post_status'   => 'any',
					'numberposts'   => -1,
					'fields'        => 'ids',
					'no_found_rows' => true,
					'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => CLARA_VE_PAGE_THEME_META,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);

		$record = Clara_VE_Theme_Registry::get( $theme );

		return array(
			'pages'                    => $pages,
			'posts'                    => $posts,
			'images'                   => count( self::owned_ids( $theme, array( 'attachment' ) ) ),
			'menus'                    => $menus,
			'terms'                    => $terms,
			'submissions'              => $submissions,
			'unattributed_submissions' => $orphan_submissions,
			'subscribers'              => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Clara_VE_Optin::table() . ' WHERE theme = %s', $theme ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			'history'                  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}clara_ve_history WHERE page_key LIKE %s", $wpdb->esc_like( $theme . '__' ) . '%' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'sources'                  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'clara_ve_source__' . $theme . '__' ) . '%' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'redirects'                => ( is_array( $record ) && isset( $record['redirects'] ) ) ? count( (array) $record['redirects'] ) : 0,
		);
	}

	/**
	 * Is this theme's content currently put away?
	 *
	 * @param string $theme
	 * @return bool
	 */
	public static function is_parked( $theme ) {
		$record = Clara_VE_Theme_Registry::get( $theme );
		return is_array( $record ) && ! empty( $record['parked'] );
	}


	/**
	 * Park and restore change a post's STATUS and its SLUG — and nothing else.
	 * `wp_update_post` does not know that: it merges the change into the whole
	 * row and re-saves it, content included, through `content_save_pre`. Where
	 * kses is armed — anywhere the acting user may not post unfiltered HTML,
	 * which includes having no user at all — that re-save silently strips a
	 * converted page's own form fields, inline SVG and embeds. Measured live:
	 * a contact form lost its boxes on every theme switch, on content that was
	 * whole a moment earlier.
	 *
	 * Restored exactly as found — re-arming unconditionally would switch
	 * filtering ON for an administrator entitled to post unfiltered HTML.
	 *
	 * @return bool Whether the caller must re-arm.
	 */
	private static function suspend_kses() {
		if ( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			kses_remove_filters();
			return true;
		}
		return false;
	}

	/**
	 * @param bool $was_armed
	 * @return void
	 */
	private static function resume_kses( $was_armed ) {
		if ( $was_armed ) {
			kses_init_filters();
		}
	}

	/**
	 * Put a theme's world away.
	 *
	 * @param string $theme Stylesheet being deactivated.
	 * @return void
	 */
	public static function park( $theme ) {
		self::flush_parked_memo();
		$theme = sanitize_key( $theme );
		if ( '' === $theme || ! Clara_VE_Theme_Registry::known( $theme ) || self::is_parked( $theme ) ) {
			return;
		}

		// FIRST, before any status is written. With show_on_front=page core
		// turns the home page into a singular page_id lookup, and its status
		// gate empties the result for any status that is not public, protected
		// or private — with no capability check, so an administrator sees the
		// same empty page. WP::handle_404() exempts is_home() but not
		// is_front_page(). Park the front page while it is still the front page
		// and the site's entire home page 404s for everyone.
		self::release_front_page( $theme );

		$kses = self::suspend_kses();
		foreach ( self::owned_ids( $theme, array( 'page', 'post' ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || CLARA_VE_PARKED_STATUS === $post->post_status ) {
				continue;
			}
			update_post_meta( $id, CLARA_VE_PAGE_STATUS_META, $post->post_status );

			$key    = (string) get_post_meta( $id, CLARA_VE_PAGE_KEY_META, true );
			$parked = clara_ve_parked_slug( '' !== $key ? $key : $post->post_name, $theme );
			// Remember the address it is giving up. A key is not always the
			// slug — an owner may have renamed any of them — so restoring from
			// the key alone would hand back a DIFFERENT address than the one
			// parked, silently changing a URL the owner chose.
			if ( '' === (string) get_post_meta( $id, CLARA_VE_PAGE_SLUG_META, true ) ) {
				update_post_meta( $id, CLARA_VE_PAGE_SLUG_META, $post->post_name );
			}
			wp_update_post(
				array(
					'ID'          => $id,
					'post_name'   => $parked,
					'post_status' => CLARA_VE_PARKED_STATUS,
				)
			);
		}
		self::resume_kses( $kses );

		foreach ( self::owned_ids( $theme, array( 'attachment' ) ) as $id ) {
			update_post_meta( $id, self::PARKED_META, $theme );
		}

		self::park_redirects( $theme );
		self::restore_site_options( $theme );

		Clara_VE_Theme_Registry::remember( $theme, array( 'parked' => current_time( 'mysql' ) ) );
		self::flush_parked_memo();
		flush_rewrite_rules( false );
	}

	/**
	 * Take a theme's world back out.
	 *
	 * @param string $theme Stylesheet being activated.
	 * @return void
	 */
	public static function restore( $theme ) {
		self::flush_parked_memo();
		$theme = sanitize_key( $theme );
		if ( '' === $theme ) {
			return;
		}

		$kses = self::suspend_kses();
		foreach ( self::owned_ids( $theme, array( 'page', 'post' ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$update = array( 'ID' => $id );

			if ( CLARA_VE_PARKED_STATUS === $post->post_status ) {
				$was = (string) get_post_meta( $id, CLARA_VE_PAGE_STATUS_META, true );
				// A status the site no longer knows about would leave the post
				// unreachable in a different way; published is what an imported
				// page was, and is the safe floor.
				$update['post_status'] = ( '' !== $was && get_post_status_object( $was ) ) ? $was : 'publish';
			}

			$want = self::wanted_slug( $post, $theme );
			if ( '' !== $want && $want !== $post->post_name ) {
				$update['post_name'] = $want;
			}

			delete_post_meta( $id, CLARA_VE_PAGE_STATUS_META );
			delete_post_meta( $id, CLARA_VE_PAGE_SLUG_META );
			if ( count( $update ) > 1 ) {
				wp_update_post( $update );
			}
		}
		self::resume_kses( $kses );

		foreach ( self::owned_ids( $theme, array( 'attachment' ) ) as $id ) {
			delete_post_meta( $id, self::PARKED_META );
		}

		self::unpark_redirects( $theme );
		self::claim_front_page( $theme );

		$record = Clara_VE_Theme_Registry::get( $theme );
		if ( is_array( $record ) ) {
			unset( $record['parked'] );
			$rows = Clara_VE_Theme_Registry::all();
			$rows[ $theme ] = $record;
			update_option( Clara_VE_Theme_Registry::OPTION, $rows, false );
		}
		self::flush_parked_memo();
		flush_rewrite_rules( false );
	}

	/**
	 * The address a page should go back to.
	 *
	 * @param WP_Post $post
	 * @param string  $theme
	 * @return string
	 */
	private static function wanted_slug( $post, $theme ) {
		$want = (string) get_post_meta( $post->ID, CLARA_VE_PAGE_SLUG_META, true );
		if ( '' === $want ) {
			// Parked before this memory existed, or never parked at all —
			// strip the parking suffix if it is wearing one.
			$want = preg_replace( '/-ve-' . preg_quote( $theme, '/' ) . '$/', '', $post->post_name );
		}

		// HEAL a collision suffix. A theme that imported while another one held
		// its addresses was given /about-2/, /blog-7/ — and those are what it
		// would otherwise reclaim forever, because they are genuinely the
		// address it gave up. When the key's own address is free and this page
		// is the one that should own it, take it back.
		$key       = (string) get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true );
		$canonical = sanitize_title( $key );
		if ( '' !== $canonical && $want !== $canonical
			&& preg_match( '/^' . preg_quote( $canonical, '/' ) . '-\d+$/', $want ) ) {
			$holder = ( 'page' === $post->post_type ) ? get_page_by_path( $canonical ) : null;
			if ( ! $holder || (int) $holder->ID === (int) $post->ID ) {
				$want = $canonical;
			}
		}
		return (string) $want;
	}

	/**
	 * Stop the home page and the posts page pointing at content about to be put
	 * away.
	 *
	 * Only when they point at THIS theme's pages: a front page the owner chose
	 * for themselves is not ours to move.
	 *
	 * @param string $theme
	 * @return void
	 */
	private static function release_front_page( $theme ) {
		foreach ( array( 'page_on_front', 'page_for_posts' ) as $option ) {
			$current = (int) get_option( $option );
			if ( ! $current ) {
				continue;
			}
			if ( sanitize_key( $theme ) !== (string) get_post_meta( $current, CLARA_VE_PAGE_THEME_META, true ) ) {
				continue;
			}
			update_option( $option, 0 );
			if ( 'page_on_front' === $option ) {
				update_option( 'show_on_front', 'posts' );
			}
		}
	}

	/**
	 * Point the home page at this theme's own front page, if it brought one.
	 *
	 * Not every converted theme has one: a self-contained front page renders
	 * from the theme's own template and imports no Page for it, in which case
	 * the site correctly goes on showing posts.
	 *
	 * @param string $theme
	 * @return void
	 */
	private static function claim_front_page( $theme ) {
		foreach ( self::owned_ids( $theme, array( 'page' ) ) as $id ) {
			if ( CLARA_VE_DEFAULT_KEY === (string) get_post_meta( $id, CLARA_VE_PAGE_KEY_META, true ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
				return;
			}
		}
	}

	/**
	 * Take this theme's redirects out of service, remembering them.
	 *
	 * A redirect to a parked page would send a visitor from one address that
	 * does not work to another that does not either, which is worse than the
	 * plain 404 they would otherwise get.
	 *
	 * @param string $theme
	 * @return void
	 */
	private static function park_redirects( $theme ) {
		$record = Clara_VE_Theme_Registry::get( $theme );
		$mine   = ( is_array( $record ) && isset( $record['redirects'] ) ) ? (array) $record['redirects'] : array();
		if ( ! $mine ) {
			return;
		}
		$live = (array) get_option( Clara_VE_Redirects::OPTION, array() );
		$held = array();
		foreach ( $mine as $from => $key ) {
			if ( isset( $live[ $from ] ) ) {
				$held[ $from ] = $live[ $from ];
				unset( $live[ $from ] );
			}
		}
		update_option( Clara_VE_Redirects::OPTION, $live );
		Clara_VE_Theme_Registry::remember( $theme, array( 'redirects_held' => $held ) );
	}

	/**
	 * @param string $theme
	 * @return void
	 */
	private static function unpark_redirects( $theme ) {
		$record = Clara_VE_Theme_Registry::get( $theme );
		$held   = ( is_array( $record ) && isset( $record['redirects_held'] ) ) ? (array) $record['redirects_held'] : array();
		if ( ! $held ) {
			return;
		}
		$live = (array) get_option( Clara_VE_Redirects::OPTION, array() );
		// Never over an address something else has claimed in the meantime.
		update_option( Clara_VE_Redirects::OPTION, array_merge( $held, $live ) );
		Clara_VE_Theme_Registry::remember( $theme, array( 'redirects_held' => array() ) );
	}

	/**
	 * Put back the site options this theme's import took over.
	 *
	 * Only the ones a design owns — the front page, the SEO identity, the
	 * fonts. A snapshot recorded as null means the theme took them over before
	 * anyone was writing it down, and nothing is guessed: the values stay as
	 * they are, and the Parked content screen says so.
	 *
	 * @param string $theme
	 * @return void
	 */
	private static function restore_site_options( $theme ) {
		$record = Clara_VE_Theme_Registry::get( $theme );
		$before = ( is_array( $record ) && isset( $record['before'] ) ) ? $record['before'] : null;
		if ( ! is_array( $before ) ) {
			return;
		}
		foreach ( Clara_VE_Theme_Registry::restorable_site_options() as $name ) {
			if ( ! array_key_exists( $name, $before ) ) {
				continue;
			}
			// release_front_page() has already dealt with these two, and it
			// knew something this snapshot does not: whether the page they
			// point at is the one being parked.
			if ( in_array( $name, array( 'page_on_front', 'show_on_front' ), true ) ) {
				continue;
			}
			if ( null === $before[ $name ] ) {
				delete_option( $name );
				continue;
			}
			update_option( $name, $before[ $name ] );
		}
	}
}
