<?php
/**
 * Builds a distributable theme ZIP: the theme directory as WordPress expects
 * it, optionally carrying a clara-content/ bundle of the site's editable
 * content beside it.
 *
 * The theme files are copied VERBATIM. Live content is not baked back into
 * patterns/parts, because the option rows — not the theme files — are this
 * plugin's source of truth, and a second divergent copy of the same state is
 * exactly the drift the architecture exists to avoid. A bare theme therefore
 * ships the design as its author wrote it; a bundle adds whatever the site
 * currently holds on top.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Bundle_Writer {

	/**
	 * Development and tooling files that are part of the repository but never
	 * part of a shipped theme. Dotfiles are excluded separately, by rule, in
	 * Clara_VE_Zip::copy_tree().
	 */
	const SKIP = array(
		'node_modules',
		'vendor',
		'src',
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'vite.config.js',
		'postcss.config.js',
		'phpcs.xml',
		'phpcs.xml.dist',
		Clara_VE_Bundle_Format::DIR,
	);

	/**
	 * Build the ZIP.
	 *
	 * @param array $args {
	 *     @type string $package              'theme' (default) or 'content' — the
	 *                                       bundle on its own, for an install
	 *                                       that already has the theme.
	 *     @type string $mode                 'none' (theme only), 'sample' or 'site'.
	 *     @type string $media                'referenced' or 'none'.
	 *     @type string $version              Version to stamp into style.css/readme.txt/manifest.
	 *     @type array  $exclude_keys         Page keys to leave out of the bundle.
	 *     @type int    $post_limit           Max posts in 'sample' mode (0 = all).
	 *     @type bool   $include_private_data Whether to also carry form
	 *                                        submissions and subscriber
	 *                                        emails. Off unless the caller
	 *                                        explicitly opts in — this is
	 *                                        visitor personal data, not site
	 *                                        design, and it never travels by
	 *                                        default in any mode.
	 * }
	 * @return array{path:string,filename:string,report:array}|WP_Error
	 */
	public static function build( array $args ) {
		$mode                 = isset( $args['mode'] ) ? $args['mode'] : 'sample';
		$media_mode           = isset( $args['media'] ) ? $args['media'] : 'referenced';
		$exclude_keys         = isset( $args['exclude_keys'] ) ? array_map( 'sanitize_key', (array) $args['exclude_keys'] ) : array();
		$post_limit           = isset( $args['post_limit'] ) ? (int) $args['post_limit'] : 0;
		$include_private_data = ! empty( $args['include_private_data'] );

		// 'theme' packages the design and, optionally, a bundle inside it.
		// 'content' packages the bundle alone, for a site that already has the
		// theme installed — moving a site to a new host, or handing the same
		// design a different set of pages, neither of which should have to
		// re-ship a theme the target already has.
		$package = ( isset( $args['package'] ) && 'content' === $args['package'] ) ? 'content' : 'theme';

		// Which theme is being packaged. Defaults to the active one, which is
		// every ordinary export — but a theme has to be DEACTIVATED before
		// WordPress will let it be deleted, so the one moment an owner most
		// needs a backup is the one moment the theme they want is not active.
		// Reading get_template_directory() then hands them a package of
		// whatever theme happens to be active instead, under the right name,
		// and nothing reveals the substitution until they try to restore it.
		$slug      = sanitize_key( isset( $args['theme'] ) && '' !== $args['theme'] ? $args['theme'] : get_stylesheet() );
		$theme     = wp_get_theme( $slug );
		$theme_dir = untrailingslashit( $theme->get_stylesheet_directory() );
		if ( ! $theme->exists() || ! is_dir( $theme_dir ) ) {
			return new WP_Error(
				'clara_ve_theme_missing',
				sprintf(
					/* translators: %s: theme directory name */
					__( 'The theme %s is not installed on this site, so there is nothing to package.', 'visual-edit-lite' ),
					$slug
				)
			);
		}
		$version   = isset( $args['version'] ) && '' !== $args['version']
			? preg_replace( '/[^0-9A-Za-z.\-]/', '', (string) $args['version'] )
			: (string) $theme->get( 'Version' );

		// A content package with nothing in it is not a smaller export, it is an
		// empty file — refused here rather than downloaded and puzzled over.
		if ( 'content' === $package && 'none' === $mode ) {
			return new WP_Error(
				'clara_ve_content_none',
				__( 'A content-only export needs content in it. Choose a different option.', 'visual-edit-lite' )
			);
		}

		// A theme with no screenshot renders as a grey placeholder tile in
		// Appearance → Themes and in the installer. That is a broken
		// deliverable, and it is silent — better to refuse than to ship it.
		// Irrelevant to a content package, which contains no theme to show.
		if ( 'theme' === $package
			&& ! file_exists( $theme_dir . '/screenshot.png' )
			&& ! file_exists( $theme_dir . '/screenshot.jpg' ) ) {
			return new WP_Error(
				'clara_ve_no_screenshot',
				__( 'This theme has no screenshot.png, so WordPress would show it as a blank tile. Generate one first: node tools/make-screenshot.mjs', 'visual-edit-lite' )
			);
		}

		$staging = Clara_VE_Zip::scratch_dir( 'clara-ve-export-tmp' );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		// Kept under the theme's slug either way, so the ZIP says which theme its
		// content belongs to — and so the reader finds clara-content one level
		// down, exactly where a theme ZIP already puts it.
		$root = $staging . '/' . $slug;

		$report = array();
		if ( 'theme' === $package ) {
			$report['theme_files'] = Clara_VE_Zip::copy_tree( $theme_dir, $root, self::SKIP );
			self::stamp_version( $theme_dir, $root, $version );
		} elseif ( ! wp_mkdir_p( $root ) ) {
			Clara_VE_Zip::rrmdir( $staging );
			return new WP_Error( 'clara_ve_staging', __( 'Could not prepare a temporary directory for the export.', 'visual-edit-lite' ) );
		}

		if ( 'none' !== $mode ) {
			$bundle = self::write_bundle( $root, $mode, $media_mode, $exclude_keys, $post_limit, $slug, $theme, $version, $include_private_data );
			if ( is_wp_error( $bundle ) ) {
				Clara_VE_Zip::rrmdir( $staging );
				return $bundle;
			}
			$report = array_merge( $report, $bundle );
		}

		$suffix   = 'content' === $package ? '-content' : ( 'none' === $mode ? '' : '-' . $mode );
		$filename = $slug . '-' . $version . $suffix . '.zip';
		$zip_path = $staging . '/' . $filename;

		$zipped = Clara_VE_Zip::zip_directory( $root, $zip_path );
		if ( is_wp_error( $zipped ) ) {
			Clara_VE_Zip::rrmdir( $staging );
			return $zipped;
		}

		// The staged tree has served its purpose; the ZIP beside it has not,
		// so it is removed on its own rather than with the whole scratch dir.
		Clara_VE_Zip::rrmdir( $root );

		return array(
			'path'     => $zip_path,
			'filename' => $filename,
			'report'   => $report,
		);
	}

	/**
	 * Rewrite the version in the staged copies of style.css and readme.txt so
	 * a shipped ZIP cannot contradict itself. The originals in the repository
	 * are untouched.
	 *
	 * @param string $theme_dir
	 * @param string $root
	 * @param string $version
	 * @return void
	 */
	private static function stamp_version( $theme_dir, $root, $version ) {
		$style = $root . '/style.css';
		if ( file_exists( $style ) ) {
			$css = (string) file_get_contents( $style ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$css = preg_replace( '/^(\s*Version:\s*).*$/mi', '${1}' . $version, $css, 1 );
			file_put_contents( $style, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$readme = $root . '/readme.txt';
		if ( file_exists( $readme ) ) {
			$txt = (string) file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$txt = preg_replace( '/^(\s*Stable tag:\s*).*$/mi', '${1}' . $version, $txt, 1 );
			file_put_contents( $readme, $txt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Write clara-content/ inside the staged theme.
	 *
	 * @param string   $root
	 * @param string   $mode
	 * @param string   $media_mode
	 * @param string[] $exclude_keys
	 * @param int      $post_limit
	 * @param string   $slug
	 * @param WP_Theme $theme
	 * @param string   $version
	 * @param bool     $include_private_data
	 * @return array|WP_Error Counts for the report, or an error.
	 */
	private static function write_bundle( $root, $mode, $media_mode, $exclude_keys, $post_limit, $slug, $theme, $version, $include_private_data ) {
		$dir = $root . '/' . Clara_VE_Bundle_Format::DIR;
		wp_mkdir_p( $dir . '/sources' );
		wp_mkdir_p( $dir . '/pseudo' );

		// Media is discovered from the RESOLVED html, before tokenizing, so
		// uploads URLs are still absolute and matchable. Collected across
		// sources and post bodies alike, then resolved to attachments once.
		$scan = array();

		// --- sources + pseudo -------------------------------------------------
		$index   = array();
		$exclude = array_flip( $exclude_keys );
		$active  = ( sanitize_key( $slug ) === sanitize_key( get_stylesheet() ) );
		foreach ( Clara_VE_Source_Store::list_keys( $slug ) as $entry ) {
			$key = $entry['key'];
			if ( isset( $exclude[ $key ] ) ) {
				continue;
			}
			// For the active theme, exactly as before: the stored edit, else
			// whatever the theme itself renders. For any other theme only the
			// stored edit can be read — get_current_source()'s fallbacks go
			// through WP_Block_Patterns_Registry and get_block_template(),
			// both bound to the theme WordPress currently has loaded, and
			// neither can be pointed elsewhere. A key with nothing stored is
			// therefore skipped and reported, rather than filled in with the
			// ACTIVE theme's markup under the parked theme's name.
			$resolved = $active
				? Clara_VE_Source_Store::get_current_source( $key )
				: Clara_VE_Source_Store::get_stored_for( $slug, $key );
			if ( '' === trim( (string) $resolved ) ) {
				continue;
			}
			$scan[] = $resolved;

			$portable = Clara_VE_Bundle_Format::to_portable( Clara_VE_Source_Store::tokenize( $resolved ) );
			$file     = 'sources/' . $key . '.html';
			file_put_contents( $dir . '/' . $file, $portable ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			$row = array(
				'key'    => $key,
				'kind'   => $entry['kind'],
				'file'   => $file,
				'sha256' => Clara_VE_Bundle_Format::hash_source( $portable ),
				'title'  => $entry['title'],
				'slug'   => $entry['slug'],
			);

			// Only written when there is something to write, so a bundle from a
			// site that never set any SEO looks exactly as it did before.
			$seo = Clara_VE_SEO::for_key( $key );
			if ( self::seo_has_content( $seo ) ) {
				$row['seo'] = Clara_VE_Bundle_Format::seo_to_portable( $seo );
			}

			$pseudo = Clara_VE_Pseudo_Store::get( $key );
			if ( ! empty( $pseudo ) ) {
				$row['pseudo'] = 'pseudo/' . $key . '.json';
				self::write_json( $dir . '/' . $row['pseudo'], $pseudo );
			}

			$index[] = $row;
		}
		self::write_json( $dir . '/sources/index.json', $index );

		// --- posts + terms ----------------------------------------------------
		$posts = self::collect_posts( $mode, $post_limit, $slug );
		$terms = array();
		$out   = array();
		foreach ( $posts as $post ) {
			$scan[] = $post->post_content;
			$row    = array(
				'slug'         => $post->post_name,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'date_gmt'     => $post->post_date_gmt,
				'modified_gmt' => $post->post_modified_gmt,
				'excerpt'      => Clara_VE_Bundle_Format::to_portable( $post->post_excerpt ),
				'content'      => Clara_VE_Bundle_Format::to_portable( $post->post_content ),
				'terms'        => array(),
			);

			// A post's SEO travels the same way a page's does. This is the one
			// piece of post meta the export carries, deliberately: exporting
			// post meta wholesale would sweep up every other plugin's private
			// state, so it stays an allowlist of exactly one key.
			$post_seo = Clara_VE_SEO::get( $post->ID );
			if ( self::seo_has_content( $post_seo ) ) {
				$row['seo'] = Clara_VE_Bundle_Format::seo_to_portable( $post_seo );
			}

			$thumb = get_post_thumbnail_id( $post );
			if ( $thumb ) {
				$row['featured_media'] = 'media:' . (int) $thumb;
				$thumb_url             = wp_get_attachment_url( $thumb );
				if ( $thumb_url ) {
					$scan[] = $thumb_url;
				}
			}

			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$assigned = wp_get_post_terms( $post->ID, $taxonomy );
				if ( is_wp_error( $assigned ) || ! $assigned ) {
					continue;
				}
				foreach ( $assigned as $term ) {
					$row['terms'][ $taxonomy ][]    = $term->slug;
					$terms[ $taxonomy . ':' . $term->slug ] = array(
						'taxonomy'    => $taxonomy,
						'slug'        => $term->slug,
						'name'        => $term->name,
						'description' => $term->description,
						'parent_slug' => $term->parent ? get_term( $term->parent )->slug : null,
					);
				}
			}
			$out[] = $row;
		}
		self::write_json( $dir . '/posts.json', $out );
		self::write_json( $dir . '/terms.json', array_values( $terms ) );

		// --- menus ------------------------------------------------------------
		$menus = self::collect_menus( $slug );
		self::write_json( $dir . '/menus.json', $menus );

		// --- options ----------------------------------------------------------
		$options = array();
		foreach ( Clara_VE_Bundle_Format::options_for_mode( $mode ) as $name ) {
			$value = get_option( $name, null );
			if ( null === $value || '' === $value ) {
				continue;
			}
			$options[ $name ] = $value;
		}
		$clean = Clara_VE_Bundle_Format::assert_no_secrets( $options );
		if ( true !== $clean ) {
			return new WP_Error( 'clara_ve_export_secret', $clean );
		}
		self::write_json( $dir . '/options.json', $options );

		// --- form submissions + subscribers (opt-in only) ----------------------
		// Visitor personal data, not site design — never written unless the
		// operator explicitly asked for it, in EITHER mode. See
		// Clara_VE_Bundle_Format::assert_no_secrets(): that guard is about
		// options.json specifically and has nothing to do with this data, so
		// the opt-in here is the only thing standing between a default export
		// and someone's inbox. Default stays off.
		$submissions = $include_private_data ? self::collect_submissions( $slug ) : array();
		$subscribers = $include_private_data ? self::collect_subscribers( $slug ) : array();
		if ( $include_private_data ) {
			self::write_json( $dir . '/submissions.json', $submissions );
			self::write_json( $dir . '/subscribers.json', $subscribers );
		}

		// --- core settings ----------------------------------------------------
		self::write_json( $dir . '/settings.json', self::collect_settings( $mode ) );

		// Written back out as rows, the same shape the bundler emits, so a site
		// that is exported and re-imported keeps the old addresses it had learned
		// — including ones added by an earlier conversion whose static source is
		// long gone.
		$redirect_rows = array();
		$stored        = get_option( Clara_VE_Redirects::OPTION, array() );
		foreach ( ( is_array( $stored ) ? $stored : array() ) as $from => $key ) {
			// Unwrap the "post:" marker back into the row shape the bundler
			// emits. Writing it out as a bare key would import as a PAGE key on
			// the next site, resolve to nothing, and silently drop every article
			// redirect on the round trip.
			$redirect_rows[] = ( 0 === strpos( $key, 'post:' ) )
				? array(
					'from' => $from,
					'post' => substr( $key, 5 ),
				)
				: array(
					'from' => $from,
					'key'  => $key,
				);
		}
		if ( $redirect_rows ) {
			self::write_json( $dir . '/redirects.json', $redirect_rows );
		}

		// --- media ------------------------------------------------------------
		$media = ( 'none' === $media_mode )
			? array()
			: self::collect_media( $scan, $dir );
		self::write_json( $dir . '/media/index.json', $media );

		// --- manifest ---------------------------------------------------------
		$manifest                  = Clara_VE_Bundle_Format::manifest_defaults();
		$manifest['mode']          = $mode;
		$manifest['theme']         = array(
			'slug'    => $slug,
			'name'    => $theme->get( 'Name' ),
			'version' => $version,
		);
		$manifest['contains']      = array(
			'sources'       => count( $index ),
			'posts'         => count( $out ),
			'terms'         => count( $terms ),
			'menus'         => count( $menus ),
			'options'       => count( $options ),
			'media'         => count( $media ),
			'media_bundled' => 'none' !== $media_mode,
			'submissions'   => count( $submissions ),
			'subscribers'   => count( $subscribers ),
		);
		// 'excluded' communicates what a bundle DELIBERATELY leaves out, so it
		// should still name these when they were left out — only drop them
		// from the list once the operator actually opted in.
		if ( $include_private_data ) {
			$manifest['excluded'] = array_values( array_diff( $manifest['excluded'], array( 'submissions', 'subscribers' ) ) );
			$manifest['notes']    = 'API keys and SMTP passwords are never exported. Form submissions and subscriber records were included because the operator explicitly chose to.';
		}
		self::write_json( $dir . '/manifest.json', $manifest );

		return $manifest['contains'];
	}

	/**
	 * @param string $mode
	 * @param int    $limit
	 * @param string $theme Stylesheet being packaged.
	 * @return WP_Post[]
	 */
	private static function collect_posts( $mode, $limit, $theme = '' ) {
		$statuses = ( 'site' === $mode ) ? array( 'publish', 'draft', 'pending' ) : array( 'publish' );
		$args     = array(
			'post_type'        => 'post',
			// A sample bundle ships finished work only; a full site export
			// keeps drafts, which are part of what the owner is moving.
			'post_status'      => $statuses,
			'numberposts'      => ( 'sample' === $mode && $limit > 0 ) ? $limit : -1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		);
		// Packaging a theme that is not the active one means packaging ITS
		// articles, not whatever the site happens to be showing — and by then
		// they are parked, so the ordinary statuses would find none of them.
		if ( '' !== $theme && sanitize_key( $theme ) !== sanitize_key( get_stylesheet() ) ) {
			$args['post_status'] = array_merge( $statuses, array( CLARA_VE_PARKED_STATUS ) );
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => CLARA_VE_PAGE_THEME_META,
					'value' => sanitize_key( $theme ),
				),
			);
		}
		return get_posts( $args );
	}

	/**
	 * The menus belonging to the theme being packaged.
	 *
	 * wp_get_nav_menus() returns every menu on the install, which is right for
	 * the active theme — the owner's own menus are part of their site. For any
	 * other theme it would sweep up menus that have nothing to do with it, so
	 * the stamp decides. A menu created before menus were stamped carries none
	 * and stays with the active theme, where it has always been.
	 *
	 * @param string $theme
	 * @return WP_Term[]
	 */
	private static function menus_for( $theme ) {
		$menus = wp_get_nav_menus();
		if ( '' === $theme || sanitize_key( $theme ) === sanitize_key( get_stylesheet() ) ) {
			return $menus;
		}
		$mine = array();
		foreach ( $menus as $menu ) {
			if ( sanitize_key( $theme ) === (string) get_term_meta( $menu->term_id, Clara_VE_Theme_Registry::TERM_META, true ) ) {
				$mine[] = $menu;
			}
		}
		return $mine;
	}

	/**
	 * Nav menus with their items. An item pointing at a visual-edit page is
	 * recorded as its page KEY rather than a URL or a post ID, so the importer
	 * can rebuild the link against the page it just created on the target
	 * site instead of writing a permalink that points at this one.
	 *
	 * @param string $theme Stylesheet being packaged.
	 * @return array
	 */
	private static function collect_menus( $theme = '' ) {
		// location slug => menu term_id, as theme_mods stores it. Cast to int
		// so the reverse lookup below can compare strictly against term_id.
		//
		// get_theme_mod() reads the ACTIVE theme's row. Another theme's
		// assignments live in theme_mods_{that theme}, which WordPress keeps
		// for it whether or not it is running — so packaging a parked theme
		// reads its own row and its menus keep the zones they were in.
		$mods = ( '' !== $theme && sanitize_key( $theme ) !== sanitize_key( get_stylesheet() ) )
			? (array) get_option( 'theme_mods_' . sanitize_key( $theme ), array() )
			: array( 'nav_menu_locations' => get_theme_mod( 'nav_menu_locations', array() ) );
		$locations = array_map( 'intval', array_filter( (array) ( isset( $mods['nav_menu_locations'] ) ? $mods['nav_menu_locations'] : array() ) ) );
		$out       = array();

		foreach ( self::menus_for( $theme ) as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( false === $items ) {
				$items = array();
			}
			$rows = array();
			foreach ( $items as $item ) {
				$row = array(
					'ref'        => (int) $item->ID,
					'parent_ref' => (int) $item->menu_item_parent,
					'order'      => (int) $item->menu_order,
					'title'      => $item->title,
					'type'       => $item->type,
					'url'        => Clara_VE_Bundle_Format::to_portable( $item->url ),
					'target'     => $item->target,
					'classes'    => array_values( array_filter( (array) $item->classes ) ),
				);
				if ( 'post_type' === $item->type && $item->object_id ) {
					$key = get_post_meta( (int) $item->object_id, CLARA_VE_PAGE_KEY_META, true );
					if ( $key ) {
						$row['type']     = 'page_key';
						$row['page_key'] = $key;
					}
				}
				$rows[] = $row;
			}

			$out[] = array(
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'locations' => array_keys( $locations, (int) $menu->term_id, true ),
				'items'     => $rows,
			);
		}
		return $out;
	}

	/**
	 * Every stored form submission, oldest first. Only called when the
	 * operator opted in — see the caller.
	 *
	 * @return array
	 */
	private static function collect_submissions( $theme = '' ) {
		$args = array(
			'post_type'        => Clara_VE_Forms::CPT,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'orderby'          => 'date',
			'order'            => 'ASC',
			'suppress_filters' => false,
		);
		// Packaging another theme carries what came in through ITS forms.
		// Submissions taken before submissions were stamped carry no theme and
		// are left out rather than attributed by guesswork — a form_id is a
		// string the page supplied, and two designs both calling theirs
		// "contact" is the ordinary case, not an unlucky one.
		if ( '' !== $theme && sanitize_key( $theme ) !== sanitize_key( get_stylesheet() ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => CLARA_VE_PAGE_THEME_META,
					'value' => sanitize_key( $theme ),
				),
			);
		}
		$posts = get_posts( $args );

		$out = array();
		foreach ( $posts as $post ) {
			$meta   = get_post_meta( $post->ID );
			$fields = array();
			foreach ( $meta as $key => $values ) {
				// Leading-underscore keys are the plugin's own bookkeeping
				// (IP, spam flag, mail-failure flag, list-subscribe error),
				// pulled out explicitly below rather than mixed in as if they
				// were a field the visitor typed.
				if ( '_' === substr( $key, 0, 1 ) ) {
					continue;
				}
				$fields[ $key ] = isset( $values[0] ) ? $values[0] : '';
			}

			// The identity a re-import recognises a submission by: the same
			// form, the same moment, the same content. Computed the same way
			// on import, so re-running an import is a no-op here too, same
			// as every other part of this bundle.
			$hash = hash( 'sha256', $post->post_date_gmt . '|' . wp_json_encode( $fields ) );

			$out[] = array(
				'form_id'     => preg_replace( '/\s+—.*$/u', '', $post->post_title ),
				'date_gmt'    => $post->post_date_gmt,
				'fields'      => $fields,
				'ip'          => isset( $meta['_clara_ve_form_ip'][0] ) ? $meta['_clara_ve_form_ip'][0] : '',
				'spam'        => ! empty( $meta['_clara_ve_spam'][0] ),
				'mail_failed' => ! empty( $meta['_clara_ve_mail_failed'][0] ),
				'hash'        => $hash,
			);
		}
		return $out;
	}

	/**
	 * Every subscriber row. Only called when the operator opted in.
	 *
	 * @return array
	 */
	private static function collect_subscribers( $theme = '' ) {
		global $wpdb;
		$select = 'SELECT email, list_id, form_id, status, consent_text, ip, created_at, confirmed_at FROM ' . Clara_VE_Optin::table();
		if ( '' !== $theme && sanitize_key( $theme ) !== sanitize_key( get_stylesheet() ) ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->prepare( $select . ' WHERE theme = %s ORDER BY id ASC', sanitize_key( $theme ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				ARRAY_A
			);
			return $rows ? $rows : array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$select . ' ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			ARRAY_A
		);
		// token_hash is deliberately not selected: it authorises confirming
		// THIS row on THIS site, and is meaningless — not a lesser secret,
		// meaningless — anywhere else, so there is nothing gained by carrying
		// it and a real (if small) reason not to.
		return $rows ? $rows : array();
	}

	/**
	 * @param string $mode
	 * @return array
	 */
	private static function collect_settings( $mode ) {
		$settings = array(
			'show_on_front'       => get_option( 'show_on_front' ),
			'posts_per_page'      => (int) get_option( 'posts_per_page' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'date_format'         => get_option( 'date_format' ),
			'time_format'         => get_option( 'time_format' ),
			'start_of_week'       => (int) get_option( 'start_of_week' ),
		);

		// Front/posts page as visual-edit KEYS, not post IDs: IDs are
		// meaningless on the target site.
		foreach ( array( 'page_on_front' => 'page_on_front_key', 'page_for_posts' => 'page_for_posts_key' ) as $option => $field ) {
			$id                 = (int) get_option( $option );
			$settings[ $field ] = $id ? ( get_post_meta( $id, CLARA_VE_PAGE_KEY_META, true ) ?: null ) : null;
		}

		// The studio's own name and strapline are the one thing a buyer of a
		// sample bundle definitely should not inherit.
		if ( 'site' === $mode ) {
			$settings['blogname']        = get_option( 'blogname' );
			$settings['blogdescription'] = get_option( 'blogdescription' );
		}

		return $settings;
	}

	/**
	 * Find every uploads-hosted file the exported content refers to, copy it
	 * into the bundle, and describe it.
	 *
	 * Only referenced media travels. Shipping the whole uploads directory is
	 * the obvious alternative and it is not viable: on the reference site that
	 * is 196MB against a 64MB upload limit, so the resulting ZIP could never
	 * be imported through WordPress at all.
	 *
	 * @param string[] $blobs Resolved HTML (and bare URLs) to scan.
	 * @param string   $dir   The clara-content directory being written.
	 * @return array
	 */
	private static function collect_media( array $blobs, $dir ) {
		$upload  = wp_upload_dir();
		$baseurl = untrailingslashit( $upload['baseurl'] );
		$basedir = untrailingslashit( $upload['basedir'] );
		if ( '' === $baseurl ) {
			return array();
		}

		$urls = array();
		foreach ( $blobs as $blob ) {
			if ( preg_match_all( '#' . preg_quote( $baseurl, '#' ) . '[^"\'\s)<>\\\\]+#i', (string) $blob, $m ) ) {
				foreach ( $m[0] as $url ) {
					$urls[ html_entity_decode( $url, ENT_QUOTES ) ] = true;
				}
			}
		}
		if ( ! $urls ) {
			return array();
		}

		$by_id = array();
		$loose = array();
		foreach ( array_keys( $urls ) as $url ) {
			$id = attachment_url_to_postid( $url );
			if ( ! $id ) {
				// A page usually references a GENERATED size
				// (hero-1024x683.jpg), which is not itself an attachment —
				// strip the dimension suffix and ask about the original.
				$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url ) );
			}
			if ( ! $id ) {
				// Still nothing — but "not an attachment" is not "not there".
				// A site converted before the importer registered what it
				// copied has real files sitting in uploads that the Media
				// Library has never heard of, and they are exactly the images
				// its pages use. Keying media discovery purely on
				// attachment_url_to_postid() drops every one of them, and the
				// export succeeds while the delivered subpages ship broken
				// images. Take the file itself.
				$rel = self::uploads_relative( $url );
				if ( '' !== $rel && file_exists( $basedir . '/' . $rel ) ) {
					$loose[ $rel ] = true;
				}
				continue;
			}
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = array();
			}
			// Keep the exact variant the content asked for as well as the
			// original: regenerating sizes on import depends on the target
			// registering identical image sizes, and it may not.
			$by_id[ $id ][ $url ] = true;
		}

		$out = array();
		foreach ( $by_id as $id => $variants ) {
			$relative = get_post_meta( $id, '_wp_attached_file', true );
			if ( ! $relative ) {
				continue;
			}
			$source = $basedir . '/' . $relative;
			if ( ! file_exists( $source ) ) {
				continue;
			}

			$files = array( $relative );
			foreach ( array_keys( $variants ) as $url ) {
				$rel = ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
				$rel = preg_replace( '#^.*?/uploads/#', '', $rel );
				if ( $rel !== $relative && file_exists( $basedir . '/' . $rel ) ) {
					$files[] = $rel;
				}
			}
			$files = array_values( array_unique( $files ) );

			unset( $loose[ $relative ] ); // an attachment already covers it
			foreach ( $files as $rel ) {
				unset( $loose[ $rel ] );
				$target = $dir . '/media/files/' . $rel;
				wp_mkdir_p( dirname( $target ) );
				copy( $basedir . '/' . $rel, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			}

			$post = get_post( $id );
			$out[] = array(
				'id'          => (int) $id,
				'file'        => $relative,
				'extra_files' => array_values( array_diff( $files, array( $relative ) ) ),
				'url'         => Clara_VE_Bundle_Format::to_portable( wp_get_attachment_url( $id ) ),
				'mime'        => get_post_mime_type( $id ),
				'title'       => $post ? $post->post_title : '',
				'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption'     => $post ? $post->post_excerpt : '',
				'description' => $post ? $post->post_content : '',
				'sha1'        => sha1_file( $source ),
				'bytes'       => (int) filesize( $source ),
				'bundled'     => true,
			);
		}

		// Files with no Media Library record of their own. They travel with the
		// same shape, minus the metadata there is none of; the importer
		// registers them properly on the target, which is more than this site
		// managed.
		foreach ( array_keys( $loose ) as $rel ) {
			$source = $basedir . '/' . $rel;
			$target = $dir . '/media/files/' . $rel;
			wp_mkdir_p( dirname( $target ) );
			if ( ! copy( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				continue;
			}
			$type  = wp_check_filetype( $source );
			$out[] = array(
				'id'          => 0,
				'file'        => $rel,
				'extra_files' => array(),
				'url'         => Clara_VE_Bundle_Format::to_portable( trailingslashit( $baseurl ) . $rel ),
				'mime'        => $type['type'] ? $type['type'] : 'application/octet-stream',
				'title'       => pathinfo( $rel, PATHINFO_FILENAME ),
				'alt'         => '',
				'caption'     => '',
				'description' => '',
				'sha1'        => sha1_file( $source ),
				'bytes'       => (int) filesize( $source ),
				'bundled'     => true,
			);
		}

		return $out;
	}

	/**
	 * A URL's path relative to the uploads directory, or '' if it is not in
	 * uploads at all.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function uploads_relative( $url ) {
		$base = untrailingslashit( (string) wp_parse_url( untrailingslashit( wp_upload_dir()['baseurl'] ), PHP_URL_PATH ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
			return '';
		}
		$rel = ltrim( substr( $path, strlen( $base ) ), '/' );
		return Clara_VE_Bundle_Reader::safe_relative( rawurldecode( $rel ) );
	}

	/**
	 * Whether an SEO record holds anything worth exporting.
	 *
	 * Checked field by field rather than against the blank record, because
	 * `noindex => false` is a legitimate stored value and an array comparison
	 * would call a record "empty" or "not empty" on the strength of it.
	 *
	 * @param array $seo
	 * @return bool
	 */
	private static function seo_has_content( $seo ) {
		if ( ! is_array( $seo ) ) {
			return false;
		}
		if ( ! empty( $seo['noindex'] ) ) {
			return true;
		}
		foreach ( array( 'title', 'description', 'canonical' ) as $field ) {
			if ( ! empty( $seo[ $field ] ) ) {
				return true;
			}
		}
		foreach ( array( 'og', 'twitter', 'jsonld' ) as $bag ) {
			if ( ! empty( $seo[ $bag ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $path
	 * @param mixed  $data
	 * @return void
	 */
	private static function write_json( $path, $data ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$path,
			wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}
}
