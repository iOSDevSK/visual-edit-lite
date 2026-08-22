<?php
/**
 * Destroying everything a converted theme brought, when its owner deletes the
 * theme.
 *
 * This is the one operation in the plugin that cannot be undone, and it is
 * deliberate: an owner who deletes a theme is told exactly what will go, is
 * offered a backup first, and then means it. Everything else in the lifecycle
 * — parking, restoring — is reversible precisely so that this does not have to
 * be reached by accident.
 *
 * WordPress refuses to delete an ACTIVE theme, so by the time anything here
 * runs the theme is already parked and its content is already out of sight.
 * What is destroyed is that parked world.
 *
 * Two hooks, because the manifest and the destruction need different moments:
 *
 * - `delete_theme` fires BEFORE the directory is removed, which is the last
 *   moment clara-content/ can be read. Anything that has to be learned from
 *   the theme's own files has to be learned here.
 * - `deleted_theme` fires after, with a flag saying whether it worked. A
 *   failed delete leaves the theme installed, and destroying its content then
 *   would be the worst of both.
 *
 * The sharp edge is shared media. apply_media() deliberately lets two themes
 * share ONE attachment when the bytes are identical — its own comment says
 * "deleting that attachment affects both themes" — and the theme stamp names
 * only whichever imported first. Purging on the stamp alone would delete a
 * file the surviving theme still renders, and nothing would say so until
 * someone looked at the other site.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Theme_Purge {

	/**
	 * What delete_theme learned while the files still existed, keyed by
	 * stylesheet. Lives for the one request in which both hooks fire.
	 *
	 * @var array<string,array>
	 */
	private static $manifest = array();

	public static function init() {
		add_action( 'delete_theme', array( __CLASS__, 'before_delete' ) );
		add_action( 'deleted_theme', array( __CLASS__, 'after_delete' ), 10, 2 );
	}

	/**
	 * @param string $stylesheet
	 * @return void
	 */
	public static function before_delete( $stylesheet ) {
		$slug = sanitize_key( $stylesheet );
		if ( '' === $slug || ! Clara_VE_Theme_Registry::known( $slug ) ) {
			return;
		}

		// Last chance to attribute anything this theme left unclaimed: the
		// matching runs against clara-content/, which is about to be deleted
		// with the rest of the directory. After this the manifests are gone and
		// an unattributed image is unattributable forever — it would survive
		// the purge and sit in the media library belonging to nobody.
		delete_option( 'clara_ve_legacy_media_stamp_version' );
		if ( function_exists( 'clara_ve_stamp_legacy_media_and_pages' ) ) {
			clara_ve_stamp_legacy_media_and_pages();
		}

		self::$manifest[ $slug ] = array(
			'shared_media' => self::shared_media( $slug ),
			'files'        => self::bundled_files( $slug ),
		);
	}

	/**
	 * @param string $stylesheet
	 * @param bool   $deleted
	 * @return void
	 */
	public static function after_delete( $stylesheet, $deleted ) {
		$slug = sanitize_key( $stylesheet );
		if ( ! $deleted || '' === $slug || ! Clara_VE_Theme_Registry::known( $slug ) ) {
			return;
		}
		$shared = isset( self::$manifest[ $slug ]['shared_media'] )
			? self::$manifest[ $slug ]['shared_media']
			: self::shared_media( $slug );
		$files = isset( self::$manifest[ $slug ]['files'] ) ? self::$manifest[ $slug ]['files'] : array();
		self::purge( $slug, $shared, $files );
	}

	/**
	 * The attachment IDs that must survive this theme's deletion because
	 * something else still needs them.
	 *
	 * Two ways an image can be shared, and both are asked about while the
	 * theme's own bundle is still readable:
	 *
	 * 1. Another installed theme's bundle declares the same sha1 — the file is
	 *    the same photograph, imported once and pointed at twice.
	 * 2. Content that is NOT being purged still references its URL. That covers
	 *    the case no manifest can: the owner used one of the theme's images in
	 *    a post of their own.
	 *
	 * @param string $slug
	 * @return int[]
	 */
	public static function shared_media( $slug ) {
		$ids = Clara_VE_Theme_Park::owned_ids( $slug, array( 'attachment' ) );
		if ( ! $ids ) {
			return array();
		}

		$claims = function_exists( 'clara_ve_bundle_claims' )
			? clara_ve_bundle_claims(
				'media/index.json',
				function ( $entry ) {
					return isset( $entry['sha1'] ) ? array( strtolower( (string) $entry['sha1'] ) ) : array();
				}
			)
			: array();

		$upload = wp_upload_dir();
		$base   = untrailingslashit( isset( $upload['basedir'] ) ? $upload['basedir'] : '' );
		$mine   = array_map( 'intval', Clara_VE_Theme_Park::owned_ids( $slug, array( 'page', 'post' ) ) );

		$keep = array();
		foreach ( $ids as $id ) {
			$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
			$full     = ( '' !== $base && '' !== $relative ) ? $base . '/' . $relative : '';
			if ( '' !== $full && is_readable( $full ) ) {
				$sha = strtolower( (string) sha1_file( $full ) );
				if ( isset( $claims[ $sha ] ) && count( array_unique( $claims[ $sha ] ) ) > 1 ) {
					$keep[] = (int) $id;
					continue;
				}
			}
			if ( self::referenced_outside( $id, $mine, $slug ) ) {
				$keep[] = (int) $id;
			}
		}
		return array_values( array_unique( $keep ) );
	}

	/**
	 * Every file this theme's bundle put into uploads, as paths relative to the
	 * uploads directory.
	 *
	 * Read while clara-content/ still exists, because after the directory goes
	 * there is no record of it anywhere. Needed because not every imported file
	 * becomes an attachment: WordPress refuses SVG by default, so a converted
	 * site's logo is copied into uploads, rendered by every page, and owned by
	 * no post — invisible to a purge that works through the Media Library.
	 * Measured: deleting a theme left exactly one file behind, and it was the
	 * logo.
	 *
	 * @param string $slug
	 * @return string[]
	 */
	private static function bundled_files( $slug ) {
		$dir = trailingslashit( wp_get_theme( $slug )->get_stylesheet_directory() ) . 'clara-content/media/index.json';
		if ( ! is_readable( $dir ) ) {
			return array();
		}
		$entries = json_decode( (string) file_get_contents( $dir ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$files = array();
		foreach ( $entries as $entry ) {
			foreach ( array_merge(
				isset( $entry['file'] ) ? array( $entry['file'] ) : array(),
				(array) ( isset( $entry['extra_files'] ) ? $entry['extra_files'] : array() )
			) as $one ) {
				$one = Clara_VE_Bundle_Reader::safe_relative( (string) $one );
				if ( '' !== $one ) {
					$files[] = $one;
					// The theme-namespaced variant apply_media() writes when a
					// filename collides with another theme's.
					// Both import folder names: a site converted before the
					// rename keeps its media under the old one, and a purge
					// that only knew the new name would leave it behind.
					foreach ( clara_ve_import_dirs() as $dir ) {
						$files[] = $dir . '/' . $slug . '/' . clara_ve_strip_import_dir( $one );
					}
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Remove files the bundle put in uploads that no attachment owns.
	 *
	 * Deliberately narrow: only paths the bundle itself declared, only under
	 * uploads, only when nothing in the Media Library still points at them, and
	 * empty directories afterwards. A file the owner put there is not this
	 * theme's to remove, whatever it sits next to.
	 *
	 * @param string[] $files
	 * @return int
	 */
	private static function remove_orphan_files( array $files ) {
		$upload = wp_upload_dir();
		$base   = untrailingslashit( isset( $upload['basedir'] ) ? $upload['basedir'] : '' );
		if ( '' === $base ) {
			return 0;
		}
		$removed = 0;
		$dirs    = array();
		foreach ( $files as $relative ) {
			$full = $base . '/' . $relative;
			if ( ! is_file( $full ) ) {
				continue;
			}
			if ( attachment_url_to_postid( trailingslashit( $upload['baseurl'] ) . $relative ) ) {
				continue; // something still owns it
			}
			if ( wp_delete_file_from_directory( $full, $base ) ) {
				++$removed;
				$dirs[ dirname( $full ) ] = true;
			}
		}
		foreach ( array_keys( $dirs ) as $dir ) {
			// Upwards until something is not empty, never above uploads.
			while ( 0 === strpos( $dir, $base . '/' ) && @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				$dir = dirname( $dir );
			}
		}
		return $removed;
	}

	/**
	 * Does anything that is NOT going away still point at this attachment?
	 *
	 * @param int    $id
	 * @param int[]  $doomed Post IDs being purged with the theme.
	 * @param string $slug
	 * @return bool
	 */
	private static function referenced_outside( $id, array $doomed, $slug ) {
		global $wpdb;
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			return false;
		}
		// The path rather than the whole URL: a site reached over both http and
		// https, or moved between domains, stores both forms in its content.
		$path = wp_make_link_relative( $url );
		$like = '%' . $wpdb->esc_like( $path ) . '%';
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status != 'trash' LIMIT 100",
				$like
			)
		);
		foreach ( $rows as $row ) {
			// A revision is not an independent user of the image — it is a
			// previous draft of the page that is going away, and wp_delete_post()
			// takes it with the parent. Counting it as an outside reference kept
			// sixteen of one theme's twenty-five images on a site where nothing
			// else used any of them.
			$holder = ( 'revision' === $row->post_type ) ? (int) $row->post_parent : (int) $row->ID;
			if ( in_array( $holder, $doomed, true ) ) {
				continue;
			}
			// The theme's own header, footer and article layout, mirrored into
			// wp_template_part posts and tagged by core with the theme's name.
			// They are purged too, so they cannot be a reason to keep anything.
			if ( 'wp_template_part' === $row->post_type
				&& has_term( $slug, 'wp_theme', $holder ) ) {
				continue;
			}
			return true;
		}

		// And the stored sources of every theme that is not this one — a
		// converted page's markup lives in an option, not in post_content.
		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s LIMIT 50",
				$wpdb->esc_like( 'clara_ve_source__' ) . '%',
				$like
			)
		);
		$prefix = 'clara_ve_source__' . sanitize_key( get_post_meta( $id, CLARA_VE_PAGE_THEME_META, true ) ) . '__';
		foreach ( $names as $name ) {
			if ( 0 !== strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Destroy everything held for a theme.
	 *
	 * @param string   $slug
	 * @param int[]    $keep_media Attachment IDs to leave alone.
	 * @param string[] $files      Bundle-declared upload paths, read before the
	 *                             theme directory went.
	 * @return array<string,int> What was removed.
	 */
	public static function purge( $slug, array $keep_media = array(), array $files = array() ) {
		global $wpdb;
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return array();
		}
		$removed = array_fill_keys(
			array( 'posts', 'images', 'images_kept', 'terms', 'submissions', 'subscribers', 'history', 'options', 'redirects', 'files' ),
			0
		);

		foreach ( Clara_VE_Theme_Park::owned_ids( $slug, array( 'page', 'post' ) ) as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed['posts'];
			}
		}

		foreach ( Clara_VE_Theme_Park::owned_ids( $slug, array( 'attachment' ) ) as $id ) {
			if ( in_array( (int) $id, array_map( 'intval', $keep_media ), true ) ) {
				// Hand it to whoever still needs it, or it stays stamped for a
				// theme that no longer exists and the next purge cannot see it.
				delete_post_meta( $id, CLARA_VE_PAGE_THEME_META );
				delete_post_meta( $id, Clara_VE_Theme_Park::PARKED_META );
				++$removed['images_kept'];
				continue;
			}
			if ( wp_delete_attachment( $id, true ) ) {
				++$removed['images'];
			}
		}

		foreach ( Clara_VE_Theme_Park::owned_terms( $slug ) as $term ) {
			if ( 'nav_menu' === $term->taxonomy ) {
				wp_delete_nav_menu( $term->term_id );
			} else {
				wp_delete_term( $term->term_id, $term->taxonomy );
			}
			++$removed['terms'];
		}

		foreach (
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
							'value' => $slug,
						),
					),
				)
			) as $id
		) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed['submissions'];
			}
		}

		$removed['subscribers'] = (int) $wpdb->delete( Clara_VE_Optin::table(), array( 'theme' => $slug ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$removed['history']     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}clara_ve_history WHERE page_key LIKE %s", $wpdb->esc_like( $slug . '__' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$removed['options'] = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'clara_ve_source__' . $slug . '__' ) . '%',
				$wpdb->esc_like( 'clara_ve_pseudo__' . $slug . '__' ) . '%'
			)
		);

		// Redirects, from both the live map and whatever parking is holding.
		$record = Clara_VE_Theme_Registry::get( $slug );
		$theirs = array();
		foreach ( array( 'redirects', 'redirects_held' ) as $field ) {
			if ( is_array( $record ) && isset( $record[ $field ] ) ) {
				$theirs += (array) $record[ $field ];
			}
		}
		if ( $theirs ) {
			$live = (array) get_option( Clara_VE_Redirects::OPTION, array() );
			$size = count( $live );
			$live = array_diff_key( $live, $theirs );
			update_option( Clara_VE_Redirects::OPTION, $live );
			$removed['redirects'] = $size - count( $live );
		}

		// Core's own per-theme rows, and this plugin's template-part mirrors,
		// which core tags with the wp_theme taxonomy.
		delete_option( 'theme_mods_' . $slug );
		$parts = get_posts(
			array(
				'post_type'     => 'wp_template_part',
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
				'tax_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $slug,
					),
				),
			)
		);
		foreach ( $parts as $id ) {
			wp_delete_post( $id, true );
		}

		$removed['files'] = self::remove_orphan_files( $files );

		if ( clara_ve_data_owner() === $slug ) {
			delete_option( CLARA_VE_OWNER_OPTION );
		}
		Clara_VE_Theme_Registry::forget( $slug );
		Clara_VE_Theme_Park::flush_parked_memo();
		flush_rewrite_rules( false );

		return $removed;
	}
}

Clara_VE_Theme_Purge::init();
