<?php
/**
 * The original static-site importer: a ZIP of built HTML pages (an Astro
 * `dist/`), one flat *.html per page, turned into visual-edit pages.
 *
 * This is the conversion pipeline's path, not the site owner's. It was
 * removed from the plugin once because a non-technical owner could reach it
 * and clobber their own edits, came back behind an "Advanced" disclosure, and
 * is now behind a constant instead — the disclosure was still a door, and a
 * door on the screen someone opens looking for their content is a door that
 * eventually gets opened. A developer re-running a conversion adds
 * `define( 'CLARA_VE_ALLOW_STATIC_IMPORT', true );` to wp-config.php; a
 * delivered site never has it, so the path does not exist there at all.
 *
 * It is deliberately dumber than the bundle importer: last write wins, with
 * every overwrite recoverable from History. That is the semantics the
 * wp-visual-site-editor skill's "Update mode" documents and depends on.
 *
 * Scope is content + in-content media only. CSS/JS wiring for a page family
 * is theme-side, one-time work this cannot safely infer.
 *
 * NOTE: ensure_attachment() below is also used by the reviewed bundle
 * importer (Clara_VE_Import_Plan), so this class loads regardless of whether
 * the static path is enabled.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Import_Legacy {

	/**
	 * Whether an extracted directory looks like a built static site.
	 *
	 * @param string $extract_dir
	 * @return string|null The directory actually holding the *.html files, or null.
	 */
	public static function locate( $extract_dir ) {
		$html = glob( trailingslashit( $extract_dir ) . '*.html' );
		if ( $html ) {
			return untrailingslashit( $extract_dir );
		}
		// A ZIP built from a folder often nests everything one level down
		// (dist/index.html inside a top-level "dist/" entry).
		foreach ( (array) glob( trailingslashit( $extract_dir ) . '*', GLOB_ONLYDIR ) as $subdir ) {
			if ( glob( trailingslashit( $subdir ) . '*.html' ) ) {
				return untrailingslashit( $subdir );
			}
		}
		return null;
	}

	/**
	 * Import every top-level *.html file in $dir.
	 *
	 * @param string $dir
	 * @return array{lines:string[],errors:bool}
	 */
	public static function import_all( $dir ) {
		$lines  = array();
		$errors = false;
		foreach ( (array) glob( trailingslashit( $dir ) . '*.html' ) as $file ) {
			$result  = self::import_file( $file, $dir );
			$lines[] = $result['line'];
			if ( ! $result['ok'] ) {
				$errors = true;
			}
		}
		if ( ! $lines ) {
			$lines[] = __( 'No .html files found at the top level of the ZIP.', 'visual-edit-lite' );
			$errors  = true;
		}
		return array(
			'lines'  => $lines,
			'errors' => $errors,
		);
	}

	/**
	 * @param string $file        Absolute path to one extracted *.html file.
	 * @param string $extract_dir Absolute path to the extraction root (for resolving relative media refs).
	 * @return array{ok:bool,line:string}
	 */
	public static function import_file( $file, $extract_dir ) {
		$basename = basename( $file );
		$stem     = preg_replace( '/\.html?$/i', '', $basename );
		$key      = ( 'index' === strtolower( $stem ) ) ? CLARA_VE_DEFAULT_KEY : sanitize_key( $stem );

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === trim( $raw ) ) {
			return array(
				'ok'   => false,
				'line' => sprintf( '<strong>%s</strong> — %s', esc_html( $basename ), esc_html__( 'could not be read.', 'visual-edit-lite' ) ),
			);
		}

		$title = self::extract_title( $raw ) ? self::extract_title( $raw ) : ucfirst( str_replace( array( '-', '_' ), ' ', $stem ) );

		$fragment = self::extract_fragment( $raw, $key );
		if ( '' === trim( $fragment ) ) {
			$expected = ( CLARA_VE_DEFAULT_KEY === $key ) ? '<body>' : '<main>';
			return array(
				'ok'   => false,
				'line' => sprintf(
					/* translators: 1: filename, 2: expected wrapper tag */
					esc_html__( '%1$s — no %2$s content found, skipped.', 'visual-edit-lite' ),
					'<strong>' . esc_html( $basename ) . '</strong>',
					'<code>' . esc_html( $expected ) . '</code>'
				),
			);
		}

		$fragment = self::rewrite_media_refs( $fragment, $key, $extract_dir );

		$shape_check = Clara_VE_Source_Store::validate_shape( $key, $fragment );
		if ( true !== $shape_check ) {
			return array(
				'ok'   => false,
				'line' => sprintf( '<strong>%s</strong> — %s', esc_html( $basename ), esc_html( $shape_check ) ),
			);
		}

		$existed = ( CLARA_VE_DEFAULT_KEY === $key ) ? true : (bool) Clara_VE_Source_Store::find_page_by_key( $key );

		$result = Clara_VE_Source_Store::create_or_update_page( $key, $title, $stem, $fragment );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'   => false,
				'line' => sprintf( '<strong>%s</strong> — %s', esc_html( $basename ), esc_html( $result->get_error_message() ) ),
			);
		}

		// create_or_update_page() already persisted the source and captured the
		// pre-import state as a History baseline; this adds the explicit,
		// human-readable entry for the import itself. A no-op re-import still
		// produces nothing new — record() skips byte-identical content.
		Clara_VE_History::record(
			Clara_VE_Source_Store::tokenize( $fragment ),
			Clara_VE_Pseudo_Store::get( $key ),
			'save',
			'Import: ' . $basename,
			null,
			$key
		);

		$verb = $existed ? __( 'updated', 'visual-edit-lite' ) : __( 'created', 'visual-edit-lite' );
		$url  = ( CLARA_VE_DEFAULT_KEY === $key ) ? home_url( '/' ) : get_permalink( $result );

		return array(
			'ok'   => true,
			'line' => sprintf(
				'<strong>%1$s</strong> → key <code>%2$s</code>, %3$s — <a href="%4$s" target="_blank" rel="noopener">%5$s</a>',
				esc_html( $basename ),
				esc_html( $key ),
				esc_html( $verb ),
				esc_url( $url ),
				esc_html__( 'view', 'visual-edit-lite' )
			),
		);
	}

	/**
	 * @param string $html
	 * @return string|null First segment of <title>, split on "|".
	 */
	public static function extract_title( $html ) {
		if ( ! preg_match( '/<title>(.*?)<\/title>/is', $html, $m ) ) {
			return null;
		}
		$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES ) );
		$parts = explode( '|', $title );
		return trim( $parts[0] );
	}

	/**
	 * Extraction rule matches how the front page vs. a tagged page already
	 * consume their source elsewhere in the plugin: the front-page key takes
	 * the whole <body> (self-contained, no shared header/footer); any other
	 * key takes only <main> (the theme's real shared header/footer supplies
	 * chrome via templates/page.html's wp:template-part blocks).
	 *
	 * @param string $html
	 * @param string $key
	 * @return string
	 */
	public static function extract_fragment( $html, $key ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		$tag  = ( CLARA_VE_DEFAULT_KEY === $key ) ? 'body' : 'main';
		$node = $dom->getElementsByTagName( $tag )->item( 0 );
		if ( ! $node ) {
			return '';
		}

		$html_out = '';

		// The front page is stored/rendered as a single self-contained fragment
		// (no shared theme stylesheet), so any <style> left in <head> — as in
		// the static source HTML — must travel with the body content or the
		// page renders unstyled.
		if ( CLARA_VE_DEFAULT_KEY === $key ) {
			foreach ( $dom->getElementsByTagName( 'style' ) as $style_node ) {
				if ( $style_node->parentNode && 'body' !== $style_node->parentNode->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$html_out .= $dom->saveHTML( $style_node ) . "\n";
				}
			}
		}

		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$html_out .= $dom->saveHTML( $child );
		}
		return trim( $html_out );
	}

	/**
	 * Copies in-fragment media (img/video/source src, poster) referenced by a
	 * relative path into uploads/ve-import/{key}/ and rewrites the
	 * reference. External/absolute/data: URLs are left untouched.
	 *
	 * @param string $html
	 * @param string $key
	 * @param string $extract_dir
	 * @return string
	 */
	public static function rewrite_media_refs( $html, $key, $extract_dir ) {
		return preg_replace_callback(
			'/(<(?:img|source|video)\b[^>]*?\b(?:src|poster)=")([^"]+)(")/i',
			function ( $m ) use ( $key, $extract_dir ) {
				$ref = $m[2];
				if ( preg_match( '#^(https?:)?//#i', $ref ) || 0 === strpos( $ref, 'data:' ) || 0 === strpos( $ref, '#' ) ) {
					return $m[0]; // external or already absolute — leave as-is
				}
				$new_url = self::copy_media_asset( $ref, $key, $extract_dir );
				return $new_url ? ( $m[1] . esc_url( $new_url ) . $m[3] ) : $m[0];
			},
			$html
		);
	}

	/**
	 * @param string $rel_path    Relative path as referenced in the source HTML.
	 * @param string $key
	 * @param string $extract_dir
	 * @return string|null New uploads URL, or null if the source file doesn't exist.
	 */
	public static function copy_media_asset( $rel_path, $key, $extract_dir ) {
		$rel_path = ltrim( (string) wp_parse_url( $rel_path, PHP_URL_PATH ), '/' );
		if ( '' === $rel_path ) {
			return null;
		}
		$source_path = trailingslashit( $extract_dir ) . $rel_path;
		if ( ! file_exists( $source_path ) || ! is_file( $source_path ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . CLARA_VE_IMPORT_DIR . '/' . $key;
		wp_mkdir_p( $target_dir );

		// Re-importing the same file should be idempotent (no new copy, same
		// URL, so re-running an import is a History no-op) — reuse an existing
		// byte-identical file at the same name instead of always minting a new
		// "-1"/"-2" suffixed copy via wp_unique_filename().
		$same_name_path = trailingslashit( $target_dir ) . basename( $rel_path );
		if ( file_exists( $same_name_path ) && md5_file( $same_name_path ) === md5_file( $source_path ) ) {
			$url = trailingslashit( $upload_dir['baseurl'] ) . CLARA_VE_IMPORT_DIR . '/' . $key . '/' . basename( $rel_path );
			self::ensure_attachment( $url, $same_name_path );
			return $url;
		}

		$filename    = wp_unique_filename( $target_dir, basename( $rel_path ) );
		$target_path = trailingslashit( $target_dir ) . $filename;

		if ( ! copy( $source_path, $target_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return null;
		}

		$url = trailingslashit( $upload_dir['baseurl'] ) . CLARA_VE_IMPORT_DIR . '/' . $key . '/' . $filename;
		self::ensure_attachment( $url, $target_path );
		return $url;
	}

	/**
	 * Find the attachment representing a particular upload file.
	 *
	 * attachment_url_to_postid() is not sufficient for large images. WordPress
	 * keeps the imported original but changes `_wp_attached_file` and the public
	 * attachment URL to a generated `-scaled` image; the original name survives
	 * only as `original_image` in attachment metadata. A bundle continues to
	 * reference the original bytes, so looking up that URL alone reports zero
	 * and every re-import creates another attachment for the same image.
	 *
	 * @param string $url  Public URL of the original file.
	 * @param string $path Absolute filesystem path of the original file.
	 * @return int Attachment ID, or 0.
	 */
	public static function attachment_id_for_file( $url, $path ) {
		$existing = (int) attachment_url_to_postid( $url );
		if ( $existing ) {
			return $existing;
		}

		global $wpdb;
		$relative = _wp_relative_upload_path( $path );
		if ( $relative ) {
			$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
					$relative
				)
			);
			if ( $existing ) {
				return $existing;
			}
		}

		if ( ! function_exists( 'wp_get_original_image_path' ) ) {
			return 0;
		}
		$basename = basename( $path );
		$candidates = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s ORDER BY post_id ASC LIMIT 50",
				'%' . $wpdb->esc_like( $basename ) . '%'
			)
		);
		$wanted = wp_normalize_path( $path );
		foreach ( $candidates as $candidate ) {
			$original = wp_get_original_image_path( (int) $candidate );
			if ( $original && $wanted === wp_normalize_path( $original ) ) {
				return (int) $candidate;
			}
		}
		return 0;
	}

	/**
	 * Register a copied media file as a REAL Media Library attachment, not
	 * just bytes on disk with a URL pointing at them — otherwise nothing that
	 * expects a genuine attachment (attachment_url_to_postid(), "Edit image
	 * (AI)", "Generate video (AI)", the exporter's own media collection) can
	 * see these images at all. Idempotent.
	 *
	 * @param string $url  Public URL of the file.
	 * @param string $path Absolute filesystem path of the same file.
	 * @return int Attachment ID, or 0.
	 */
	public static function ensure_attachment( $url, $path ) {
		$existing = self::attachment_id_for_file( $url, $path );
		if ( $existing ) {
			return (int) $existing;
		}
		$type = wp_check_filetype( $path );
		if ( ! $type['type'] || 0 !== strpos( $type['type'], 'image/' ) ) {
			return 0; // only images are relevant to the Visual Editor's media features.
		}
		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'],
				'post_title'     => sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$path
		);
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );
		return (int) $attach_id;
	}
}
