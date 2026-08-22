<?php
/**
 * Works out what importing a bundle would do BEFORE anything is written, and
 * then applies only the part the operator confirmed.
 *
 * The rule this class exists to enforce: an import never overwrites. Anything
 * that already exists and differs is reported as a conflict and left exactly
 * as it is. That is deliberately stricter than "overwrite but keep History" —
 * the importer was removed from this plugin once precisely because a
 * non-technical owner could destroy their own work with it, and "we can undo
 * it afterwards" is not the same promise as "we did not touch it".
 *
 * Overwriting with per-item choice, and the migration mode that needs it, are
 * a later step; the plan shape here already carries what they will need.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Import_Plan {

	const TRANSIENT = 'clara_ve_import_plan_';
	const TTL       = 30 * MINUTE_IN_SECONDS;

	/**
	 * Classify everything in a bundle against the current site.
	 *
	 * @param array $bundle From Clara_VE_Bundle_Reader::read().
	 * @return array{items:array,counts:array}
	 */
	public static function build( array $bundle ) {
		// The plan must resolve media exactly the way the apply will, or a
		// source that differs ONLY by a namespaced image path gets judged
		// against text this install would never write — see media_remap_for().
		Clara_VE_Bundle_Format::set_media_remap( self::media_remap_for( $bundle ) );

		$items = array();

		foreach ( $bundle['sources'] as $source ) {
			$items[] = self::classify_source( $source );
		}
		foreach ( $bundle['terms'] as $term ) {
			$items[] = self::classify_term( $term );
		}
		foreach ( $bundle['posts'] as $post ) {
			$items[] = self::classify_post( $post );
		}
		foreach ( $bundle['menus'] as $menu ) {
			$items[] = self::classify_menu( $menu );
		}
		foreach ( $bundle['media'] as $file ) {
			$items[] = self::classify_media( $file );
		}
		foreach ( $bundle['options'] as $name => $value ) {
			$items[] = self::classify_option( $name, $value );
		}
		foreach ( $bundle['submissions'] as $submission ) {
			$items[] = self::classify_submission( $submission );
		}
		foreach ( $bundle['subscribers'] as $subscriber ) {
			$items[] = self::classify_subscriber( $subscriber );
		}

		$counts = array(
			'new'       => 0,
			'identical' => 0,
			'conflict'  => 0,
			'blocked'   => 0,
		);
		foreach ( $items as $item ) {
			++$counts[ $item['status'] ];
		}

		return array(
			'items'  => $items,
			'counts' => $counts,
		);
	}

	/**
	 * @param array $source
	 * @return array
	 */
	private static function classify_source( array $source ) {
		$key      = $source['key'];
		$incoming = Clara_VE_Bundle_Format::from_portable( $source['html'] );

		$item = array(
			'type'   => 'source',
			'id'     => $key,
			'label'  => $source['title'],
			'status' => 'new',
			'detail' => '',
		);

		$shape = Clara_VE_Source_Store::validate_shape( $key, $incoming );
		if ( true !== $shape ) {
			$item['status'] = 'blocked';
			$item['detail'] = $shape;
			return $item;
		}

		// "Identical" is judged against what is LIVE, not against what has been
		// explicitly saved. The exporter reads get_current_source(), which
		// falls back to the theme file for a key nobody has edited yet — so a
		// key served straight from the theme is in the bundle, and comparing
		// only against get_resolved_source() (saved edits, null here) reported
		// it as something to add. Re-importing a bundle into the site it came
		// from would then always have something "new" in it, purely because
		// that page had never been touched.
		$live = Clara_VE_Source_Store::get_current_source( $key );
		if ( '' !== trim( (string) $live ) ) {
			$live_portable = Clara_VE_Bundle_Format::to_portable( Clara_VE_Source_Store::tokenize( $live ) );
			if ( Clara_VE_Bundle_Format::hash_source( $live_portable ) === $source['sha256'] ) {
				// Byte-identical to the bundle means NO OWNER EDIT exists here
				// — the stored copy is exactly what a previous import wrote. So
				// if this import would resolve it differently, rewriting loses
				// nothing and is the only way an install that already collided
				// heals: its pages still point at image paths that another
				// theme's files happen to occupy, and no amount of re-importing
				// media moves those references on its own.
				$resolved = Clara_VE_Source_Store::untokenize( $incoming );
				if ( trim( (string) $resolved ) !== trim( (string) $live ) ) {
					$item['detail'] = __( 'Image paths corrected — this theme\'s own files are stored under their own path.', 'visual-edit-lite' );
					return $item;
				}
				$item['status'] = 'identical';
				$item['detail'] = __( 'Already exactly this content.', 'visual-edit-lite' );
				return $item;
			}
		}

		// Content differs. Whether that is a conflict depends on whether any of
		// it is the owner's: with nothing saved for this key, the theme file is
		// still the source of truth and there is nothing to lose.
		if ( null === Clara_VE_Source_Store::get_resolved_source( $key ) ) {
			$item['detail'] = __( 'Nothing saved for this page yet.', 'visual-edit-lite' );
			return $item;
		}

		$item['status'] = 'conflict';
		$saves          = Clara_VE_History::list_entries( 100, $key );
		$item['detail'] = sprintf(
			/* translators: %d: number of saved versions */
			_n( 'You have edited this page (%d saved version). It will be left alone.', 'You have edited this page (%d saved versions). It will be left alone.', count( $saves ), 'visual-edit-lite' ),
			count( $saves )
		);
		return $item;
	}

	/**
	 * @param array $post
	 * @return array
	 */
	private static function classify_post( array $post ) {
		$slug     = isset( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';
		$existing = $slug ? get_page_by_path( $slug, OBJECT, 'post' ) : null;
		return array(
			'type'   => 'post',
			'id'     => $slug,
			'label'  => isset( $post['title'] ) ? $post['title'] : $slug,
			'status' => $existing ? 'conflict' : 'new',
			'detail' => $existing ? __( 'A post with this slug already exists.', 'visual-edit-lite' ) : '',
		);
	}

	/**
	 * @param array $term
	 * @return array
	 */
	private static function classify_term( array $term ) {
		$taxonomy = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category';
		$slug     = isset( $term['slug'] ) ? $term['slug'] : '';
		$exists   = $slug && taxonomy_exists( $taxonomy ) ? term_exists( $slug, $taxonomy ) : false;
		return array(
			'type'   => 'term',
			'id'     => $taxonomy . ':' . $slug,
			'label'  => isset( $term['name'] ) ? $term['name'] : $slug,
			'status' => $exists ? 'identical' : 'new',
			'detail' => $exists ? __( 'Already exists; kept as it is.', 'visual-edit-lite' ) : '',
		);
	}

	/**
	 * @param array $menu
	 * @return array
	 */
	private static function classify_menu( array $menu ) {
		$name     = isset( $menu['name'] ) ? $menu['name'] : '';
		$existing = $name ? wp_get_nav_menu_object( $name ) : false;
		$count    = isset( $menu['items'] ) ? count( $menu['items'] ) : 0;
		return array(
			'type'   => 'menu',
			'id'     => $name,
			'label'  => $name,
			'status' => $existing ? 'conflict' : 'new',
			'detail' => $existing
				? __( 'A menu with this name already exists; your own menu is kept.', 'visual-edit-lite' )
				: sprintf(
					/* translators: %d: number of menu items */
					_n( '%d link', '%d links', $count, 'visual-edit-lite' ),
					$count
				),
		);
	}

	/**
	 * @param array $file
	 * @return array
	 */
	/**
	 * The media path renames this install would have to make for this bundle.
	 *
	 * Pure: derived from the bundle and the filesystem, so the PLAN can compute
	 * the same answer apply_media() will, which is what lets classify_source()
	 * tell "already correct" apart from "still pointing at the file another
	 * theme happened to own".
	 *
	 * @param array $bundle
	 * @return array<string,string>
	 */
	private static function media_remap_for( array $bundle ) {
		$remap = array();
		foreach ( (array) ( isset( $bundle['media'] ) ? $bundle['media'] : array() ) as $file ) {
			$d = self::media_disposition( (array) $file );
			if ( empty( $d['scoped'] ) || '' === $d['path'] ) {
				continue;
			}
			$original = Clara_VE_Bundle_Reader::safe_relative( isset( $file['file'] ) ? $file['file'] : '' );
			if ( '' !== $original ) {
				$remap[ $original ] = $d['path'];
			}
			foreach ( (array) ( isset( $file['extra_files'] ) ? $file['extra_files'] : array() ) as $extra ) {
				$safe = Clara_VE_Bundle_Reader::safe_relative( $extra );
				if ( '' === $safe ) {
					continue;
				}
				$remap[ $safe ] = CLARA_VE_IMPORT_DIR . '/' . sanitize_key( get_stylesheet() ) . '/'
					. clara_ve_strip_import_dir( $safe );
			}
		}
		return $remap;
	}

	/**
	 * Where a bundled media file must land on THIS install, and what that means
	 * for the plan. ONE derivation, used by classify_media() and apply_media()
	 * alike — they used to reach the same conclusion by separate reasoning,
	 * which is only ever true until it isn't.
	 *
	 * The bundle names files `ve-import/{page-key}/{basename}`, which is
	 * not unique across converted themes: two sites both having front-page
	 * images called 1.webp is what export tooling produces, not a coincidence.
	 * Content decides what that means.
	 *
	 * @param array $file Bundle media entry.
	 * @return array{path:string,status:string,scoped:bool,media_state:string} Path is relative to uploads.
	 */
	private static function media_disposition( array $file ) {
		$relative = Clara_VE_Bundle_Reader::safe_relative( isset( $file['file'] ) ? $file['file'] : '' );
		if ( '' === $relative ) {
			return array( 'path' => '', 'status' => 'blocked', 'scoped' => false, 'media_state' => '' );
		}
		$upload  = wp_upload_dir();
		$basedir = untrailingslashit( $upload['basedir'] );
		$sha1    = isset( $file['sha1'] ) ? (string) $file['sha1'] : '';

		if ( ! file_exists( $basedir . '/' . $relative ) ) {
			return array( 'path' => $relative, 'status' => 'new', 'scoped' => false, 'media_state' => 'missing_file' );
		}
		if ( $sha1 && sha1_file( $basedir . '/' . $relative ) === $sha1 ) {
			$state = self::media_library_state( $relative, $basedir . '/' . $relative, $upload );
			if ( 'missing' === $state || 'parked' === $state ) {
				// A file is not a Media Library item. Theme deletion, an older
				// plugin release or an interrupted purge can leave the bytes in
				// uploads after the attachment row has gone; deactivation leaves
				// the row parked and deliberately hidden. Calling either state
				// "identical" prevents apply_media() from running, loses the old
				// attachment-ID map used by featured blog images, and leaves the
				// owner with files the Media Library cannot see.
				return array( 'path' => $relative, 'status' => 'new', 'scoped' => false, 'media_state' => $state );
			}
			// Byte-identical: genuinely the same file. Share the one attachment
			// rather than store a second copy — two themes shipping the same
			// logo should not double it. The trade, stated once: the first
			// importer's ALT text wins, and deleting that attachment affects
			// both themes.
			return array( 'path' => $relative, 'status' => 'identical', 'scoped' => false, 'media_state' => $state );
		}

		// Different bytes under the same name. Nothing is overwritten; this
		// theme gets its own folder and every reference follows (set_media_remap).
		$scoped = CLARA_VE_IMPORT_DIR . '/' . sanitize_key( get_stylesheet() ) . '/'
			. clara_ve_strip_import_dir( $relative );
		if ( ! file_exists( $basedir . '/' . $scoped ) ) {
			return array( 'path' => $scoped, 'status' => 'new', 'scoped' => true, 'media_state' => 'missing_file' );
		}
		if ( $sha1 && sha1_file( $basedir . '/' . $scoped ) === $sha1 ) {
			$state = self::media_library_state( $scoped, $basedir . '/' . $scoped, $upload );
			if ( 'missing' === $state || 'parked' === $state ) {
				return array( 'path' => $scoped, 'status' => 'new', 'scoped' => true, 'media_state' => $state );
			}
			return array( 'path' => $scoped, 'status' => 'identical', 'scoped' => true, 'media_state' => $state );
		}
		// This theme's OWN scoped file, and it differs — the owner replaced it.
		// Never overwrite that; references still point at it, which is right.
		return array( 'path' => $scoped, 'status' => 'conflict', 'scoped' => true, 'media_state' => '' );
	}

	/**
	 * Whether matching bytes also have a usable WordPress media record.
	 *
	 * Files WordPress cannot register as image attachments (most notably SVG
	 * on a default install) remain file-only by design and are therefore ready.
	 *
	 * @param string $relative Upload path relative to basedir.
	 * @param string $absolute Absolute path to the file.
	 * @param array  $upload   wp_upload_dir() result.
	 * @return string 'ready' | 'missing' | 'parked' | 'file_only'.
	 */
	private static function media_library_state( $relative, $absolute, array $upload ) {
		$type = wp_check_filetype( $absolute );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			return 'file_only';
		}
		$url = untrailingslashit( $upload['baseurl'] ) . '/' . $relative;
		$id  = Clara_VE_Import_Legacy::attachment_id_for_file( $url, $absolute );
		if ( ! $id ) {
			return 'missing';
		}
		return '' !== (string) get_post_meta( $id, Clara_VE_Theme_Park::PARKED_META, true ) ? 'parked' : 'ready';
	}

	private static function classify_media( array $file ) {
		$relative = Clara_VE_Bundle_Reader::safe_relative( isset( $file['file'] ) ? $file['file'] : '' );
		$item     = array(
			'type'   => 'media',
			'id'     => $relative,
			'label'  => basename( $relative ),
			'status' => 'new',
			'detail' => '',
		);

		if ( '' === $relative ) {
			$item['status'] = 'blocked';
			$item['detail'] = __( 'Unsafe file path in the bundle.', 'visual-edit-lite' );
			return $item;
		}
		if ( empty( $file['bundled'] ) ) {
			$item['status'] = 'blocked';
			$item['detail'] = __( 'Not included in this bundle.', 'visual-edit-lite' );
			return $item;
		}

		$disposition    = self::media_disposition( $file );
		$item['status'] = $disposition['status'];
		if ( 'missing' === $disposition['media_state'] ) {
			$item['detail'] = __( 'The file is still on disk, but its Media Library record is missing and will be recreated.', 'visual-edit-lite' );
		} elseif ( 'parked' === $disposition['media_state'] ) {
			$item['detail'] = __( 'The file is still hidden with a previously removed theme and will be restored to the Media Library.', 'visual-edit-lite' );
		} elseif ( 'identical' === $disposition['status'] ) {
			$item['detail'] = __( 'Same file already here.', 'visual-edit-lite' );
		} elseif ( 'conflict' === $disposition['status'] ) {
			$item['detail'] = __( 'A different file already has this name in this theme\'s own folder; yours is kept.', 'visual-edit-lite' );
		} elseif ( $disposition['scoped'] ) {
			// A DIFFERENT file under this name. Refusing to import used to be
			// the safe answer — never overwrite what is already there — but it
			// left the incoming page pointing at the other file, which is only
			// harmless when that file is a replacement the OWNER made. Across
			// two converted themes it is not: the bundle stores images as
			// `ve-import/{page-key}/{basename}`, and two sites both
			// having 1.webp, 2.webp, 3.webp on their front page is ordinary.
			// The result was one theme's pages rendering another's
			// photographs, at its own URLs, with an identical DOM.
			//
			// Nothing is overwritten: media_disposition() gives this file a
			// theme-namespaced path and every reference follows it.
			$item['detail'] = __( 'Another theme already has a file with this name, so this one is stored under its own path. Nothing existing is replaced.', 'visual-edit-lite' );
		}
		return $item;
	}

	/**
	 * @param string $name
	 * @param mixed  $value
	 * @return array
	 */
	private static function classify_option( $name, $value ) {
		$current = get_option( $name, null );
		if ( null === $current ) {
			$status = 'new';
			$detail = '';
		} elseif ( $current === $value ) {
			$status = 'identical';
			$detail = __( 'Already set to this.', 'visual-edit-lite' );
		} else {
			$status = 'conflict';
			$detail = __( 'You have your own setting here; it is kept.', 'visual-edit-lite' );
		}
		return array(
			'type'   => 'option',
			'id'     => $name,
			'label'  => $name,
			'status' => $status,
			'detail' => $detail,
		);
	}

	/**
	 * @param array $submission
	 * @return array
	 */
	private static function classify_submission( array $submission ) {
		$hash   = isset( $submission['hash'] ) ? $submission['hash'] : '';
		$exists = $hash && get_posts(
			array(
				'post_type'      => Clara_VE_Forms::CPT,
				'post_status'    => 'any',
				'numberposts'    => 1,
				'fields'         => 'ids',
				'meta_key'       => '_clara_ve_dedup_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		$label = ( isset( $submission['form_id'] ) ? $submission['form_id'] : 'form' ) . ' — ' . ( isset( $submission['date_gmt'] ) ? $submission['date_gmt'] : '' );
		return array(
			'type'   => 'submission',
			// The full hash, not a display-shortened one: this id is the exact
			// key apply() matches against $allowed, so it must be unique, not
			// just readable. The table renders it in a <code> tag as-is.
			'id'     => $hash ? $hash : $label,
			'label'  => $label,
			'status' => $exists ? 'identical' : 'new',
			'detail' => $exists ? __( 'Already imported.', 'visual-edit-lite' ) : '',
		);
	}

	/**
	 * @param array $subscriber
	 * @return array
	 */
	private static function classify_subscriber( array $subscriber ) {
		$email = isset( $subscriber['email'] ) ? sanitize_email( $subscriber['email'] ) : '';
		$item  = array(
			'type'   => 'subscriber',
			'id'     => $email,
			'label'  => $email,
			'status' => 'new',
			'detail' => '',
		);
		if ( '' === $email ) {
			$item['status'] = 'blocked';
			$item['detail'] = __( 'No email address.', 'visual-edit-lite' );
			return $item;
		}

		global $wpdb;
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT status FROM ' . Clara_VE_Optin::table() . ' WHERE email = %s', $email ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		);
		if ( $existing ) {
			$same             = isset( $subscriber['status'] ) && $existing->status === $subscriber['status'];
			$item['status']   = $same ? 'identical' : 'conflict';
			$item['detail']   = $same
				? __( 'Already on your list.', 'visual-edit-lite' )
				: __( 'Already on your list with a different status; yours is kept.', 'visual-edit-lite' );
		}
		return $item;
	}

	/**
	 * Persist a plan so the confirmation POST can apply exactly what was shown.
	 *
	 * @param string $bundle_dir
	 * @param array  $plan
	 * @return string Plan id.
	 */
	public static function store( $bundle_dir, array $plan ) {
		$id = wp_generate_password( 12, false );
		set_transient(
			self::TRANSIENT . $id,
			array(
				'dir'  => $bundle_dir,
				'plan' => $plan,
			),
			self::TTL
		);
		return $id;
	}

	/**
	 * @param string $id
	 * @return array|null
	 */
	public static function fetch( $id ) {
		$stored = get_transient( self::TRANSIENT . $id );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Apply the 'new' items of a stored plan.
	 *
	 * Order matters and is not cosmetic: media must land before posts (which
	 * reference attachment IDs) and before sources (whose URLs are remapped);
	 * terms before posts; posts before menus, because a menu link to an
	 * imported page is rebuilt from that page's freshly-created permalink.
	 *
	 * @param string $id Plan id.
	 * @return array{lines:string[],errors:bool}|WP_Error
	 */
	public static function apply( $id ) {
		$stored = self::fetch( $id );
		if ( ! $stored ) {
			return new WP_Error( 'clara_ve_plan_expired', __( 'That import has expired. Upload the ZIP again.', 'visual-edit-lite' ) );
		}

		// Refuse only while there is UNSCOPED legacy content this install
		// cannot attribute. Sources are stored per theme now, so importing a
		// second converted theme's content is a normal thing to do — it lands
		// in that theme's own profile and collides with nothing. What is still
		// unsafe is an install holding pre-scoping rows whose owner could not
		// be determined: those are read by fallback, so an import on top of
		// them would half-apply and leave a hybrid with no single step to undo.
		$owner = function_exists( 'clara_ve_foreign_data' ) ? clara_ve_foreign_data() : '';
		if ( '' !== $owner ) {
			$owner_theme = wp_get_theme( $owner );
			return new WP_Error(
				'clara_ve_foreign_owner',
				sprintf(
					/* translators: %s: the theme name that owns the existing content */
					__( 'This site already holds editable content created for %s. Importing another theme\'s content on top of it would leave the site a mix of two designs, so nothing was imported. Give this theme its own WordPress install, or activate that theme again to keep working on it.', 'visual-edit-lite' ),
					$owner_theme->exists() ? $owner_theme->get( 'Name' ) : $owner
				)
			);
		}

		$bundle = Clara_VE_Bundle_Reader::read( $stored['dir'] );
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		// Only what the plan classified as 'new' is eligible; re-derived here
		// rather than trusted from the browser, so a tampered POST cannot turn
		// a conflict into an overwrite.
		$allowed = array();
		foreach ( $stored['plan']['items'] as $item ) {
			if ( 'new' === $item['status'] ) {
				$allowed[ $item['type'] ][ (string) $item['id'] ] = true;
			}
		}

		// Before anything is written: this is the last moment the site options
		// still hold the values that were true without this theme, and the
		// record of them is the only way leaving it can put them back.
		Clara_VE_Theme_Registry::note_import( get_stylesheet() );

		$lines     = array();
		$media_map = self::apply_media( $bundle, $allowed, $lines );
		self::apply_terms( $bundle, $allowed, $lines );
		self::apply_posts( $bundle, $allowed, $media_map, $lines );
		self::apply_sources( $bundle, $allowed, $lines );
		self::repair_blank_pages( $bundle, $lines );
		self::apply_menus( $bundle, $allowed, $lines );
		self::apply_options( $bundle, $allowed, $lines );
		self::apply_settings( $bundle, $lines );
		self::apply_redirects( $bundle, $lines );
		self::apply_submissions( $bundle, $allowed, $lines );
		self::apply_subscribers( $bundle, $allowed, $lines );
		// Static state, and from_portable() is also called outside an import
		// (the SEO REST routes). Anything later in this request must not
		// resolve through a finished import's path map.
		Clara_VE_Bundle_Format::set_media_remap( array() );

		delete_transient( self::TRANSIENT . $id );

		return array(
			'lines'  => $lines,
			'errors' => false,
		);
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return array old attachment id => new attachment id
	 */
	private static function apply_media( array $bundle, array $allowed, array &$lines ) {
		$map      = array();
		$remap    = self::media_remap_for( $bundle );
		$upload   = wp_upload_dir();
		$basedir  = untrailingslashit( $upload['basedir'] );
		$baseurl  = untrailingslashit( $upload['baseurl'] );
		$imported = 0;

		foreach ( $bundle['media'] as $file ) {
			$relative = Clara_VE_Bundle_Reader::safe_relative( isset( $file['file'] ) ? $file['file'] : '' );
			if ( '' === $relative ) {
				continue;
			}

			// Same derivation the plan used — see media_disposition().
			$original    = $relative;
			$disposition = self::media_disposition( $file );
			$relative    = $disposition['path'];
			if ( $disposition['scoped'] ) {
				$remap[ $original ] = $relative;
				if ( isset( $allowed['media'][ $original ] ) ) {
					$allowed['media'][ $relative ] = true;
				}
			}

			$target = $basedir . '/' . $relative;
			$url    = $baseurl . '/' . $relative;

			// Present already (identical, or a conflict we are leaving alone):
			// reuse whatever attachment is there so references still resolve.
			if ( ! isset( $allowed['media'][ $relative ] ) ) {
				$existing = Clara_VE_Import_Legacy::attachment_id_for_file( $url, $target );
				// id 0 means the source site had no Media Library record for
				// this file — there is nothing to remap it to.
				if ( $existing && ! empty( $file['id'] ) ) {
					$map[ (int) $file['id'] ] = (int) $existing;
				}
				continue;
			}

			// Re-import can be a database repair rather than a file copy: the
			// exact bytes may have survived a previous theme uninstall while the
			// attachment row did not (or while it remained parked). In that case
			// an unwritable-but-readable existing file is still perfectly ready
			// to register, so success must not depend on copy() overwriting it.
			$sha1  = isset( $file['sha1'] ) ? (string) $file['sha1'] : '';
			$ready = is_file( $target ) && ( '' === $sha1 || sha1_file( $target ) === $sha1 );
			foreach ( array_merge( array( $original ), (array) ( isset( $file['extra_files'] ) ? $file['extra_files'] : array() ) ) as $one ) {
				$safe = Clara_VE_Bundle_Reader::safe_relative( $one );
				if ( '' === $safe ) {
					continue;
				}
				// Read from the bundle under its own name, write under the
				// possibly-namespaced one. A sized variant travels WITH its
				// original: leaving extra_files unscoped would copy this
				// theme's 1024px version straight over the other theme's, which
				// is the same corruption one level down — and content
				// references those variants, so they need remapping too.
				if ( $safe === $original ) {
					$dest = $relative;
				} elseif ( $disposition['scoped'] ) {
					$dest = CLARA_VE_IMPORT_DIR . '/' . sanitize_key( get_stylesheet() ) . '/'
						. clara_ve_strip_import_dir( $safe );
					$remap[ $safe ] = $dest;
				} else {
					$dest = $safe;
				}
				$from = $bundle['dir'] . '/media/files/' . $safe;
				$to   = $basedir . '/' . $dest;
				if ( ! file_exists( $from ) ) {
					continue;
				}
				// Never write over a file that is already there and different —
				// that is someone else's, whoever they are.
				if ( file_exists( $to ) && sha1_file( $to ) !== sha1_file( $from ) && $safe !== $original ) {
					continue;
				}
				wp_mkdir_p( dirname( $to ) );
				if ( copy( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
					if ( $safe === $original ) {
						$ready = true;
					}
				}
			}
			if ( ! $ready ) {
				continue;
			}

			// The file is on disk either way; registering it is a bonus that
			// only applies to images. Count it as imported regardless — the
			// page that references it works now.
			++$imported;

			$new_id = Clara_VE_Import_Legacy::ensure_attachment( $url, $target );
			if ( ! $new_id ) {
				continue;
			}
			// ensure_attachment() is intentionally idempotent and can return a
			// pre-existing row. If that row belonged to a deactivated theme it
			// still carries the marker that all Media Library queries exclude;
			// importing it means it is live again. Also claim an unowned survivor
			// so the current theme's later lifecycle can park/purge it correctly.
			delete_post_meta( $new_id, Clara_VE_Theme_Park::PARKED_META );
			Clara_VE_Theme_Registry::stamp_post( $new_id );
			if ( ! empty( $file['alt'] ) ) {
				update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $file['alt'] ) );
			}
			if ( ! empty( $file['id'] ) ) {
				$map[ (int) $file['id'] ] = $new_id;
			}
		}

		// Hand the renames to the token expander BEFORE sources, posts, SEO
		// and menu URLs are written — from_portable() applies them for all of
		// those in one place.
		Clara_VE_Bundle_Format::set_media_remap( $remap );
		if ( $remap ) {
			$lines[] = sprintf(
				/* translators: %d: number of files */
				_n(
					'%d image had the same name as another theme\'s but different contents, so it was stored separately.',
					'%d images had the same names as another theme\'s but different contents, so they were stored separately.',
					count( $remap ),
					'visual-edit-lite'
				),
				count( $remap )
			);
		}

		if ( $imported ) {
			/* translators: %d: number of files */
			$lines[] = sprintf( _n( '%d image added to the Media Library.', '%d images added to the Media Library.', $imported, 'visual-edit-lite' ), $imported );
		}
		return $map;
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_terms( array $bundle, array $allowed, array &$lines ) {
		$created = 0;
		$parents = array();

		foreach ( $bundle['terms'] as $term ) {
			$taxonomy = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category';
			$slug     = isset( $term['slug'] ) ? $term['slug'] : '';
			if ( '' === $slug || ! taxonomy_exists( $taxonomy ) || ! isset( $allowed['term'][ $taxonomy . ':' . $slug ] ) ) {
				continue;
			}
			$result = wp_insert_term(
				isset( $term['name'] ) ? $term['name'] : $slug,
				$taxonomy,
				array(
					'slug'        => $slug,
					'description' => isset( $term['description'] ) ? $term['description'] : '',
				)
			);
			if ( is_wp_error( $result ) ) {
				continue;
			}
			++$created;
			if ( ! empty( $term['parent_slug'] ) ) {
				$parents[ $result['term_id'] ] = array( $taxonomy, $term['parent_slug'] );
			}
		}

		// Second pass: a parent may itself have been created in this same run,
		// so re-parenting cannot happen while the first pass is still going.
		foreach ( $parents as $term_id => $info ) {
			list( $taxonomy, $parent_slug ) = $info;
			$parent = get_term_by( 'slug', $parent_slug, $taxonomy );
			if ( $parent ) {
				wp_update_term( $term_id, $taxonomy, array( 'parent' => $parent->term_id ) );
			}
		}

		if ( $created ) {
			/* translators: %d: number of categories */
			$lines[] = sprintf( _n( '%d category added.', '%d categories added.', $created, 'visual-edit-lite' ), $created );
		}
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param array    $media_map
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_posts( array $bundle, array $allowed, array $media_map, array &$lines ) {
		$created = 0;

		foreach ( $bundle['posts'] as $post ) {
			$slug = isset( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';
			if ( '' === $slug || ! isset( $allowed['post'][ $slug ] ) ) {
				continue;
			}

			$insert = array(
				'post_type'     => 'post',
				'post_name'     => $slug,
				'post_title'    => isset( $post['title'] ) ? $post['title'] : $slug,
				'post_status'   => isset( $post['status'] ) ? $post['status'] : 'publish',
				'post_content'  => Clara_VE_Bundle_Format::from_portable( isset( $post['content'] ) ? $post['content'] : '' ),
				'post_excerpt'  => Clara_VE_Bundle_Format::from_portable( isset( $post['excerpt'] ) ? $post['excerpt'] : '' ),
				'post_date_gmt' => isset( $post['date_gmt'] ) ? $post['date_gmt'] : '',
			);
			// The byline the article page printed. Without this every imported
			// post is attributed to whoever clicked Import — "admin" under
			// each article the site's own author signed. Only set when the
			// bundle names one; absent, the current default (the importing
			// user) is the honest answer, not a guess.
			if ( ! empty( $post['author'] ) && is_string( $post['author'] ) ) {
				$author_id = self::resolve_author( $post['author'] );
				if ( $author_id ) {
					$insert['post_author'] = $author_id;
				}
			}
			$post_id = wp_insert_post( $insert, true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			++$created;

			// Stamp the bundle key, the same way a Page carries one. A blog
			// conversion turns article PAGES into posts, so the addresses those
			// articles used to serve have to redirect here — and a redirect map
			// that named the slug would break the first time the owner retitled
			// a post, which is exactly what keying pages avoids. The key is
			// stable; the slug is the owner's to change.
			update_post_meta( $post_id, CLARA_VE_PAGE_KEY_META, isset( $post['key'] ) ? sanitize_key( $post['key'] ) : $slug );
			update_post_meta( $post_id, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );

			if ( ! empty( $post['featured_media'] ) && preg_match( '/^media:(\d+)$/', $post['featured_media'], $m ) ) {
				$old = (int) $m[1];
				if ( isset( $media_map[ $old ] ) ) {
					set_post_thumbnail( $post_id, $media_map[ $old ] );
				}
			}

			foreach ( (array) ( isset( $post['terms'] ) ? $post['terms'] : array() ) as $taxonomy => $slugs ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					wp_set_object_terms( $post_id, (array) $slugs, $taxonomy, false );
				}
			}

			// After the featured image, so the og:image lookup in
			// Clara_VE_SEO can resolve a URL that this run just imported into
			// the media library.
			if ( ! empty( $post['seo'] ) ) {
				Clara_VE_SEO::save( $post_id, Clara_VE_Bundle_Format::seo_from_portable( $post['seo'] ), true );
			}
		}

		if ( $created ) {
			/* translators: %d: number of posts */
			$lines[] = sprintf( _n( '%d blog post added.', '%d blog posts added.', $created, 'visual-edit-lite' ), $created );
		}
	}

	/**
	 * The WP user a bundle's author byline resolves to, created on first
	 * sight when no user carries the name.
	 *
	 * Matching is by display_name, exactly — the byline is display text and
	 * that is the field it must round-trip through ({author} and
	 * [wp-article field="author"] both read display_name). Creation follows
	 * the WXR importer's precedent: an import that names authors creates
	 * them, because attributing someone's article to the importing admin is
	 * worse than a new author row. Role 'author' (can write posts, cannot
	 * touch the site), no email, random password — a sign-in is something
	 * the owner grants later, not something an import hands out.
	 *
	 * @param string $display_name As written in the article's byline.
	 * @return int User ID, or 0 when the name is empty/unresolvable.
	 */
	private static function resolve_author( $display_name ) {
		static $cache = array();
		$display_name = trim( wp_strip_all_tags( $display_name ) );
		if ( '' === $display_name ) {
			return 0;
		}
		if ( isset( $cache[ $display_name ] ) ) {
			return $cache[ $display_name ];
		}
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s LIMIT 1", $display_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $found ) {
			$cache[ $display_name ] = (int) $found;
			return (int) $found;
		}
		$login = sanitize_user( sanitize_title( $display_name ), true );
		if ( '' === $login ) {
			$cache[ $display_name ] = 0;
			return 0;
		}
		$existing = get_user_by( 'login', $login );
		if ( $existing ) {
			$cache[ $display_name ] = (int) $existing->ID;
			return (int) $existing->ID;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => $display_name,
				'nickname'     => $display_name,
				'role'         => 'author',
			)
		);
		$cache[ $display_name ] = is_wp_error( $user_id ) ? 0 : (int) $user_id;
		return $cache[ $display_name ];
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	/**
	 * Fill any page that is bound to a key, has a stored source, and is EMPTY.
	 *
	 * A page in that state cannot be anything an owner made: the import created
	 * it, and something stopped the markup landing — before 1.20.7, an import
	 * with no current user, whose page mirror was refused by a capability check
	 * meant for hand-typed HTML. The plan classifies such a source as already
	 * applied (the option matches the bundle), so apply_sources() rightly skips
	 * it and the site stays broken through every re-import. Emptiness is the
	 * whole condition — a page with anything in it is left alone.
	 *
	 * @param array    $bundle
	 * @param string[] $lines
	 * @return void
	 */
	private static function repair_blank_pages( array $bundle, array &$lines ) {
		$filled = 0;
		Clara_VE_Source_Store::$trusted_write = true;
		foreach ( $bundle['sources'] as $source ) {
			$key = isset( $source['key'] ) ? $source['key'] : '';
			// The front page renders from the pattern override and chrome keys
			// from template parts; neither has a bound Page, which is exactly
			// what find_page_by_key() answers below.
			if ( '' === $key || CLARA_VE_DEFAULT_KEY === $key ) {
				continue;
			}
			$page = Clara_VE_Source_Store::find_page_by_key( $key );
			if ( ! $page || '' !== trim( (string) $page->post_content ) ) {
				continue;
			}
			$stored = Clara_VE_Source_Store::get_resolved_source( $key );
			if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
				continue;
			}
			Clara_VE_Source_Store::save_source( $key, $stored );
			$page = Clara_VE_Source_Store::find_page_by_key( $key );
			if ( $page && '' !== trim( (string) $page->post_content ) ) {
				++$filled;
			}
		}
		Clara_VE_Source_Store::$trusted_write = false;
		if ( $filled ) {
			$lines[] = sprintf(
				/* translators: %d: number of pages */
				_n( '%d page was empty and has been filled from its stored content.', '%d pages were empty and have been filled from their stored content.', $filled, 'visual-edit-lite' ),
				$filled
			);
		}
	}

	private static function apply_sources( array $bundle, array $allowed, array &$lines ) {
		$done    = array();
		$refused = array();

		// This markup is the theme's own bundle, not something a visitor typed,
		// and applying it must land the same way whoever started the run — the
		// admin screen, wp-cli, a provisioning script. Without this the page
		// mirror is skipped for a run with no current user, and the site comes
		// up with every source stored and every page blank.
		Clara_VE_Source_Store::$trusted_write = true;

		foreach ( $bundle['sources'] as $source ) {
			$key = $source['key'];
			if ( ! isset( $allowed['source'][ $key ] ) ) {
				continue;
			}
			// Both token layers have to be resolved before this is handed to
			// the store. from_portable() deals with uploads and home URLs;
			// untokenize() deals with the theme URI. Skipping the second one
			// looks safe — save_source() tokenizes for the option row anyway —
			// but it is not: save_source() ALSO passes the string straight to
			// sync_to_page()/sync_to_template_part(), whose contract is
			// "absolute theme URIs". An unresolved token reaches the render
			// target verbatim, and the footer ships <img src="__CLARA_THEME_URI__/…">.
			$html = Clara_VE_Source_Store::untokenize( Clara_VE_Bundle_Format::from_portable( $source['html'] ) );

			// Re-checked at write time, not only at plan time: the plan may be
			// half an hour old and this writes raw HTML into the site.
			$shape = Clara_VE_Source_Store::validate_shape( $key, $html );
			if ( true !== $shape ) {
				// Reported, not skipped in silence. This used to `continue` with
				// nothing said anywhere, and a refusal here is indistinguishable
				// from success at every level above: the page still renders (a
				// generated theme also ships its front page as a static
				// pattern), so the site looks converted and pixel checks pass
				// while the page holds no editable source at all. Verified
				// live — a front page imported as zero bytes and nothing in the
				// product said so.
				$refused[] = $key . ' — ' . $shape;
				continue;
			}

			self::free_slug_from_placeholder( $key, $source['slug'], $source['title'], $lines );

			Clara_VE_History::ensure_baseline( $key );
			$result = Clara_VE_Source_Store::create_or_update_page( $key, $source['title'], $source['slug'], $html );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			if ( ! empty( $source['pseudo'] ) ) {
				Clara_VE_Pseudo_Store::save( $key, $source['pseudo'] );
			}

			// The page's SEO, carried over from the original static <head>.
			// only_if_empty: an import must never overwrite wording the owner
			// has already changed, and this runs on re-imports too.
			//
			// Resolved against this site first, exactly like the HTML above — the
			// og:image arrives as "__CLARA_UPLOADS_URI__/…", which is not a URL
			// yet, and a social network handed that verbatim shows no preview at
			// all. Deliberately AFTER create_or_update_page(), because a page
			// created by this very call is where the record has to land.
			if ( ! empty( $source['seo'] ) ) {
				$resolved_seo = Clara_VE_Bundle_Format::seo_from_portable( $source['seo'] );
				$post_id      = Clara_VE_SEO::post_id_for_key( $key );
				if ( $post_id ) {
					Clara_VE_SEO::save( $post_id, $resolved_seo, true );
				}
				// The front page's schema.org block describes the BUSINESS, not
				// the page — so it becomes the site-wide entity every other
				// page's graph then refers to, instead of being one page's
				// orphaned markup. Adopting the @type the designer already wrote
				// is also what keeps us from having to guess it.
				if ( CLARA_VE_DEFAULT_KEY === $key && ! empty( $resolved_seo['jsonld'] ) ) {
					Clara_VE_GEO::seed_from_jsonld( $resolved_seo['jsonld'] );
				}
			}
			Clara_VE_History::record(
				Clara_VE_Source_Store::tokenize( $html ),
				Clara_VE_Pseudo_Store::get( $key ),
				'save',
				'Import: ' . ( isset( $bundle['manifest']['theme']['name'] ) ? $bundle['manifest']['theme']['name'] : 'bundle' ),
				null,
				$key
			);
			$done[] = $key;
		}
		Clara_VE_Source_Store::$trusted_write = false;

		if ( $done ) {
			/* translators: %d: number of pages */
			$lines[] = sprintf( _n( '%d page added.', '%d pages added.', count( $done ), 'visual-edit-lite' ), count( $done ) );
		}

		// Said out loud, and said last so it is the line left on screen. A page
		// the site kept but could not store is the one outcome an owner must
		// not have to discover for themselves.
		foreach ( $refused as $why ) {
			/* translators: %s: page key and the reason it was refused */
			$lines[] = sprintf( __( 'NOT imported — %s', 'visual-edit-lite' ), $why );
		}
	}

	/**
	 * A fresh WordPress ships placeholder pages of its own — a draft "Privacy
	 * Policy", a "Sample Page" — and one of them holds a slug the bundle
	 * needs. WordPress does not report the clash; it quietly appends "-2", so
	 * the imported page lands at /privacy-policy-2/ while every link in the
	 * design still points at /privacy-policy/, which serves an empty draft.
	 *
	 * The placeholder is adopted rather than duplicated or deleted: it becomes
	 * the imported page, keeping its ID (so WordPress's own privacy-page
	 * designation still points at the right thing) and freeing the slug.
	 *
	 * Strictly limited to WordPress's OWN untouched scaffolding — draft or
	 * auto-draft, never claimed by this plugin, and never edited since it was
	 * created. A page the owner wrote is left exactly where it is, and the
	 * suffixed slug is reported instead of being papered over.
	 *
	 * The same clash happens with a media file: bundles routinely carry an
	 * image whose filename matches a page's slug (contact.jpg / /contact/),
	 * media imports before pages, and WordPress's own slug-uniqueness check
	 * treats attachments and pages as one namespace — so the page silently
	 * loses the address to the picture. An attachment's post_name is never
	 * authored content and has no address anyone actually links to, so unlike
	 * an owner's placeholder page it is freed unconditionally, not adopted.
	 *
	 * @param string   $key
	 * @param string   $slug
	 * @param string   $title
	 * @param string[] $lines
	 * @return void
	 */
	private static function free_slug_from_placeholder( $key, $slug, $title, array &$lines ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || Clara_VE_Source_Store::find_page_by_key( $key ) ) {
			return; // nothing to import into, or this key already has its page
		}

		// Passing 'page' alone still surfaces an attachment holding this slug —
		// WordPress's own path resolution isn't strictly post-type-scoped for a
		// single path segment — so ask for both explicitly rather than lean on
		// that as an implementation detail.
		$holder = get_page_by_path( $slug, OBJECT, array( 'page', 'attachment' ) );
		if ( ! $holder ) {
			return; // slug is free
		}

		// A media file's post_name is bundler-derived from its filename, is
		// never authored content, and has no public-facing address WordPress
		// treats as canonical (an attachment's own page redirects to the file
		// it wraps). A real page's address is load-bearing — every link in the
		// design points at it — so it always wins the slug outright, on every
		// import including re-imports where the attachment already exists.
		if ( 'attachment' === $holder->post_type ) {
			wp_update_post(
				array(
					'ID'        => $holder->ID,
					'post_name' => $slug . '-file',
				)
			);
			$lines[] = sprintf(
				/* translators: %s: slug */
				__( 'Freed the address /%s/ from a media file so the imported page could use it.', 'visual-edit-lite' ),
				$slug
			);
			return;
		}

		$is_ours       = (bool) get_post_meta( $holder->ID, CLARA_VE_PAGE_KEY_META, true );
		$is_draft      = in_array( $holder->post_status, array( 'draft', 'auto-draft' ), true );
		$never_touched = $holder->post_modified_gmt === $holder->post_date_gmt;

		if ( $is_ours || ! $is_draft || ! $never_touched ) {
			$lines[] = sprintf(
				/* translators: 1: slug, 2: page title */
				__( 'The address /%1$s/ was already taken by your page "%2$s", so the imported page got a slightly different one. Links in the design that point at /%1$s/ will reach your page, not the imported one.', 'visual-edit-lite' ),
				$slug,
				get_the_title( $holder )
			);
			return;
		}

		// Tagging it is not enough: create_or_update_page()'s existing-page
		// branch only revives a TRASHED page, and touches neither status nor
		// title. An adopted draft would stay an unpublished page called
		// whatever WordPress named it.
		update_post_meta( $holder->ID, CLARA_VE_PAGE_KEY_META, $key );
		update_post_meta( $holder->ID, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );
		wp_update_post(
			array(
				'ID'          => $holder->ID,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
			)
		);
		$lines[] = sprintf(
			/* translators: %s: page title */
			__( 'Reused WordPress\'s own placeholder page "%s" so the imported page keeps its proper address.', 'visual-edit-lite' ),
			get_the_title( $holder )
		);
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_menus( array $bundle, array $allowed, array &$lines ) {
		$registered = get_registered_nav_menus();

		foreach ( $bundle['menus'] as $menu ) {
			$name = isset( $menu['name'] ) ? $menu['name'] : '';
			if ( '' === $name || ! isset( $allowed['menu'][ $name ] ) ) {
				continue;
			}

			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				continue;
			}

			$ref_to_item = array();
			$position    = 0;
			$items       = isset( $menu['items'] ) ? $menu['items'] : array();
			usort(
				$items,
				function ( $a, $b ) {
					return ( isset( $a['order'] ) ? $a['order'] : 0 ) <=> ( isset( $b['order'] ) ? $b['order'] : 0 );
				}
			);

			foreach ( $items as $item ) {
				$args = array(
					'menu-item-title'   => isset( $item['title'] ) ? $item['title'] : '',
					'menu-item-status'  => 'publish',
					'menu-item-classes' => implode( ' ', (array) ( isset( $item['classes'] ) ? $item['classes'] : array() ) ),
					'menu-item-target'  => isset( $item['target'] ) ? $item['target'] : '',
				);

				$item_type = isset( $item['type'] ) ? $item['type'] : '';
				$page      = ( 'page_key' === $item_type && ! empty( $item['page_key'] ) )
					? Clara_VE_Source_Store::find_page_by_key( $item['page_key'] )
					: null;
				// A nav group can deep-link an ARTICLE, which exists here only
				// as a Post (its page copy is deliberately excluded from the
				// bundle) — found by the bundle key apply_posts() stamped a few
				// lines ago, never by slug guessing: a post's slug comes from
				// its title, so the source filename's key routinely differs.
				$post = ( 'post_key' === $item_type && ! empty( $item['post_key'] ) )
					? Clara_VE_Redirects::find_post_by_key( $item['post_key'] )
					: null;

				if ( $page ) {
					// Rebuilt against the page that exists HERE, rather than
					// carrying over a URL that points at the site this bundle
					// came from.
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = 'page';
					$args['menu-item-object-id'] = $page->ID;
				} elseif ( $post ) {
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = 'post';
					$args['menu-item-object-id'] = $post->ID;
				} else {
					$args['menu-item-type'] = 'custom';
					$args['menu-item-url']  = Clara_VE_Bundle_Format::from_portable( isset( $item['url'] ) ? $item['url'] : '' );
				}

				$parent_ref = isset( $item['parent_ref'] ) ? (int) $item['parent_ref'] : 0;
				if ( $parent_ref && isset( $ref_to_item[ $parent_ref ] ) ) {
					$args['menu-item-parent-id'] = $ref_to_item[ $parent_ref ];
				}

				// An EXPLICIT 1-based position, never left to core's default.
				// Omitting it makes core compute one, and for the first item of a
				// new menu that computes to 0 — which SORTS first, so the menu
				// looks perfectly ordered and nothing downstream complains. But 0
				// is also core's sentinel for "append to the end", so the next
				// update of that item — a rename in the editor, anything — reads 0
				// back and moves it to last. Verified: the import stored
				// `47:0 48:2 49:3 50:4 51:5`, and one later edit turned it into
				// `48:2 49:3 50:4 51:5 47:6`. The bundle already carries the
				// authoritative order and the loop is already sorted by it, so
				// state it outright.
				$args['menu-item-position'] = ++$position;
				$new_id = wp_update_nav_menu_item( $menu_id, 0, $args );
				if ( ! is_wp_error( $new_id ) && ! empty( $item['ref'] ) ) {
					$ref_to_item[ (int) $item['ref'] ] = $new_id;
				}
			}

			// Assign locations, but only ones the active theme/plugin actually
			// registered — writing a theme_mod for an unknown location is dead
			// data that silently does nothing.
			$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
			$assigned  = array();
			foreach ( (array) ( isset( $menu['locations'] ) ? $menu['locations'] : array() ) as $location ) {
				if ( isset( $registered[ $location ] ) && empty( $locations[ $location ] ) ) {
					$locations[ $location ] = $menu_id;
					$assigned[]             = $location;
				}
			}
			if ( $assigned ) {
				set_theme_mod( 'nav_menu_locations', $locations );
			}

			/* translators: 1: menu name, 2: number of links */
			$line = sprintf( __( 'Menu "%1$s" added with %2$d links.', 'visual-edit-lite' ), $name, count( $items ) );

			// SAY when the menu is connected to nothing. A menu that exists but
			// is assigned to no location renders nowhere and turns menu
			// management off for the whole site — and the old wording reported
			// that outcome in exactly the same words as a working one, so the
			// only way to discover it was to wonder why editing the menu
			// changed nothing. Verified live: a theme shipped without the
			// clara_ve_theme_contract filter registers no location, so every
			// assignment here is skipped in silence.
			$wanted = (array) ( isset( $menu['locations'] ) ? $menu['locations'] : array() );
			if ( $assigned ) {
				$line .= ' ' . sprintf(
					/* translators: %s: comma-separated menu location slugs */
					__( 'Shown in: %s.', 'visual-edit-lite' ),
					implode( ', ', $assigned )
				);
			} elseif ( $wanted ) {
				$unknown = array_diff( $wanted, array_keys( $registered ) );
				$line   .= ' ' . ( $unknown
					? __( 'It is not shown anywhere yet: this theme does not register the navigation position it was built for, so there is nothing to attach it to. A theme made by the converter declares that in inc/visual-edit.php.', 'visual-edit-lite' )
					: __( 'It is not shown anywhere yet: another menu already occupies that position. Assign it under Appearance → Menus if you want this one instead.', 'visual-edit-lite' ) );
			}
			$lines[] = $line;
		}
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_options( array $bundle, array $allowed, array &$lines ) {
		$set = 0;
		foreach ( $bundle['options'] as $name => $value ) {
			if ( ! isset( $allowed['option'][ $name ] ) ) {
				continue;
			}
			update_option( $name, $value, false );
			++$set;
		}
		if ( $set ) {
			/* translators: %d: number of settings */
			$lines[] = sprintf( _n( '%d setting applied.', '%d settings applied.', $set, 'visual-edit-lite' ), $set );
		}
	}

	/**
	 * Core settings. Only the two page assignments are applied, and only when
	 * this site has not already made a choice: changing permalinks or the
	 * front-page mode underneath a running site is not something an import of
	 * sample content has any business doing.
	 *
	 * @param array    $bundle
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_settings( array $bundle, array &$lines ) {
		$settings = $bundle['settings'];

		// Permalinks are the exception to "settings are the owner's business",
		// because getting this wrong breaks the whole delivered site in a way
		// that LOOKS fine. Every internal link in a bundle is a path
		// (/about/), and a WordPress install still on plain permalinks has no
		// rewrite rule to match one — so it does not 404, it silently renders
		// the FRONT page instead. Every subpage appears to load and shows the
		// wrong content.
		//
		// Only ever applied when the target still carries a structure NOBODY
		// CHOSE. That used to mean empty, and stopped meaning it in WordPress
		// 6.7: a fresh install now arrives already set to the dated default, so
		// testing for empty alone quietly never fired again — every site
		// imported onto a current WordPress served its articles at
		// /2026/03/12/slug/ while the design's links and its redirect map
		// pointed at /slug/. Both of WordPress's own defaults count as
		// unchosen; any other structure is the owner's and is left alone.
		$wp_default_permalinks = array( '', '/%year%/%monthnum%/%day%/%postname%/' );
		if ( ! empty( $settings['permalink_structure'] )
			&& in_array( (string) get_option( 'permalink_structure' ), $wp_default_permalinks, true ) ) {
			global $wp_rewrite;
			update_option( 'permalink_structure', $settings['permalink_structure'] );
			if ( $wp_rewrite instanceof WP_Rewrite ) {
				$wp_rewrite->init();
			}
			flush_rewrite_rules( true );
			// Then throw the generated rules away again. Regenerating them
			// depends on every post type and taxonomy being registered, which
			// is true in an admin request and NOT guaranteed in whatever
			// context this runs next (a WP-CLI import regenerated rules that
			// served pages fine but 404'd every /category/… archive). Deleting
			// the option makes the next front-end request rebuild them from a
			// fully booted WordPress, which is the only moment they can be
			// built correctly.
			delete_option( 'rewrite_rules' );
			$lines[] = sprintf(
				/* translators: %s: permalink structure, e.g. /%postname%/ */
				__( 'Permalinks switched to %s — the imported pages need path-style links to resolve.', 'visual-edit-lite' ),
				'<code>' . esc_html( $settings['permalink_structure'] ) . '</code>'
			);
		}

		// The front page's anchor. collect_settings() has always exported
		// page_on_front_key (class-bundle-writer.php:502) and nothing ever read
		// it, so a bundle's front page arrived as "latest posts" — which made the
		// homepage is_home() rather than is_singular(). Two consequences that
		// both cost real ranking: no post to attach a title or meta description
		// to, and core's rel_canonical() never firing, so /page/2/ served a
		// second copy of the homepage with nothing declaring which was canonical.
		//
		// ensure_front_anchor() already runs from create_or_update_page()'s front
		// branch, so by this point the anchor normally exists and this is a
		// no-op that just confirms it. It is repeated here because a bundle can
		// legitimately carry settings without carrying that source (an update
		// run whose front page was left alone), and because a site whose owner
		// has since chosen their own static front page must keep it — which is
		// front_anchor_id()'s rule, not ours to second-guess.
		if ( ! empty( $settings['page_on_front_key'] ) && CLARA_VE_DEFAULT_KEY === $settings['page_on_front_key'] ) {
			$anchor = Clara_VE_Source_Store::ensure_front_anchor();
			if ( $anchor && (int) get_option( 'page_on_front' ) === $anchor ) {
				$lines[] = __( 'The front page is set as a static page, so it can carry its own title, description and social preview.', 'visual-edit-lite' );
			}
		}

		if ( empty( $settings['page_for_posts_key'] ) || (int) get_option( 'page_for_posts' ) ) {
			return;
		}
		$page = Clara_VE_Source_Store::find_page_by_key( $settings['page_for_posts_key'] );
		if ( ! $page ) {
			return;
		}
		// Deliberately NOT set: assigning a posts page makes WordPress render
		// its own archive template instead of the page's designed content, and
		// flips show_on_front in a way that stops the theme's front page being
		// recognised. Recorded as guidance instead of silently done.
		$lines[] = sprintf(
			/* translators: %s: page title */
			__( 'Note: the bundle used "%s" as its blog page. It lists posts through its own design, so do NOT set it under Settings → Reading → Posts page.', 'visual-edit-lite' ),
			get_the_title( $page )
		);
	}

	/**
	 * Keep the addresses the site had before it was WordPress.
	 *
	 * Applied unconditionally, like settings and unlike content: there is no
	 * plan row to tick, because a redirect is not something a reviewer would
	 * ever want to refuse page by page, and one that is missing is invisible
	 * until traffic is already lost. Nothing here can overwrite content, and
	 * Clara_VE_Redirects only ever answers a request WordPress has already
	 * given up on.
	 *
	 * Runs AFTER apply_sources() so the pages the map points at exist — though
	 * keys are resolved per request, so even a page imported later starts
	 * receiving its old address the moment it appears.
	 *
	 * @param array    $bundle
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_redirects( array $bundle, array &$lines ) {
		if ( empty( $bundle['redirects'] ) ) {
			return;
		}
		$added = Clara_VE_Redirects::store( $bundle['redirects'] );
		if ( ! $added ) {
			return;
		}
		$lines[] = sprintf(
			/* translators: %d: number of old addresses */
			_n(
				'%d address from the original site now redirects to its new page, so existing links and search results keep working.',
				'%d addresses from the original site now redirect to their new pages, so existing links and search results keep working.',
				$added,
				'visual-edit-lite'
			),
			$added
		);
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_submissions( array $bundle, array $allowed, array &$lines ) {
		$created = 0;
		foreach ( $bundle['submissions'] as $submission ) {
			$hash = isset( $submission['hash'] ) ? $submission['hash'] : '';
			$id   = $hash ? $hash : ( ( isset( $submission['form_id'] ) ? $submission['form_id'] : 'form' ) . ' — ' . ( isset( $submission['date_gmt'] ) ? $submission['date_gmt'] : '' ) );
			if ( ! isset( $allowed['submission'][ $id ] ) ) {
				continue;
			}

			$fields = array();
			foreach ( (array) ( isset( $submission['fields'] ) ? $submission['fields'] : array() ) as $key => $value ) {
				$fields[ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => Clara_VE_Forms::CPT,
					'post_title'  => sprintf(
						/* translators: 1: form id, 2: submission date. */
						__( '%1$s — %2$s', 'visual-edit-lite' ),
						isset( $submission['form_id'] ) ? $submission['form_id'] : 'form',
						isset( $submission['date_gmt'] ) ? $submission['date_gmt'] : ''
					),
					'post_status'   => 'publish',
					'post_date_gmt' => isset( $submission['date_gmt'] ) ? $submission['date_gmt'] : '',
					'meta_input'    => array_merge(
						$fields,
						array(
							'_clara_ve_form_ip'    => isset( $submission['ip'] ) ? $submission['ip'] : '',
							'_clara_ve_spam'       => ! empty( $submission['spam'] ) ? '1' : '',
							'_clara_ve_mail_failed' => ! empty( $submission['mail_failed'] ) ? '1' : '',
							// The dedup key future re-imports of this same
							// bundle look for — see classify_submission().
							'_clara_ve_dedup_hash' => $hash,
						)
					),
				),
				true
			);
			if ( ! is_wp_error( $post_id ) ) {
				++$created;
			}
		}
		if ( $created ) {
			/* translators: %d: number of form submissions */
			$lines[] = sprintf( _n( '%d form submission added.', '%d form submissions added.', $created, 'visual-edit-lite' ), $created );
		}
	}

	/**
	 * @param array    $bundle
	 * @param array    $allowed
	 * @param string[] $lines
	 * @return void
	 */
	private static function apply_subscribers( array $bundle, array $allowed, array &$lines ) {
		global $wpdb;
		$created = 0;
		foreach ( $bundle['subscribers'] as $subscriber ) {
			$email = isset( $subscriber['email'] ) ? sanitize_email( $subscriber['email'] ) : '';
			if ( '' === $email || ! isset( $allowed['subscriber'][ $email ] ) ) {
				continue;
			}
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Clara_VE_Optin::table(),
				array(
					'email'        => $email,
					'list_id'      => isset( $subscriber['list_id'] ) ? $subscriber['list_id'] : '',
					'form_id'      => isset( $subscriber['form_id'] ) ? $subscriber['form_id'] : '',
					'status'       => isset( $subscriber['status'] ) ? $subscriber['status'] : 'pending',
					// A fresh, unrelated token: the source site's raw
					// confirmation token was never exported (only email/list/
					// form/status/consent/ip/dates are — see
					// Clara_VE_Bundle_Writer::collect_subscribers()), so a
					// stale confirmation link from the old site can never
					// confirm this row on the new one. That is the correct
					// outcome, not a gap to fill.
					'token_hash'   => hash( 'sha256', wp_generate_password( 32, false ) ),
					'consent_text' => isset( $subscriber['consent_text'] ) ? $subscriber['consent_text'] : '',
					'ip'           => isset( $subscriber['ip'] ) ? $subscriber['ip'] : '',
					'created_at'   => isset( $subscriber['created_at'] ) ? $subscriber['created_at'] : current_time( 'mysql' ),
					'confirmed_at' => ! empty( $subscriber['confirmed_at'] ) ? $subscriber['confirmed_at'] : null,
				)
			);
			if ( $inserted ) {
				++$created;
			}
		}
		if ( $created ) {
			/* translators: %d: number of subscribers */
			$lines[] = sprintf( _n( '%d subscriber added.', '%d subscribers added.', $created, 'visual-edit-lite' ), $created );
		}
	}
}
