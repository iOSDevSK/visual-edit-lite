<?php
/**
 * Reads a clara-content/ bundle from a directory — an extracted upload, or
 * the active theme's own folder — and hands back a validated, normalized
 * structure.
 *
 * The ONE reader. The setup wizard, the Import screen and anything added
 * later all go through it, so there is never a second interpretation of the
 * format to drift out of step with Clara_VE_Bundle_Format.
 *
 * Everything here treats the bundle as untrusted input: it may have been
 * hand-edited, or built by an older version of the plugin.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Bundle_Reader {

	/** Refuse absurd bundles rather than unpacking them into memory. */
	const MAX_SOURCES     = 500;
	const MAX_POSTS       = 2000;
	const MAX_MEDIA       = 5000;
	const MAX_SUBMISSIONS = 20000;
	const MAX_SUBSCRIBERS = 20000;

	/**
	 * Find a bundle inside an extracted directory: at its root, one level
	 * down (the usual "theme-slug/clara-content"), or two (a ZIP that wrapped
	 * everything in an extra folder).
	 *
	 * @param string $dir
	 * @return string|null Absolute path of the clara-content directory.
	 */
	public static function locate( $dir ) {
		$dir = untrailingslashit( $dir );
		foreach ( array( '', '/*', '/*/*' ) as $depth ) {
			foreach ( (array) glob( $dir . $depth . '/' . Clara_VE_Bundle_Format::DIR . '/manifest.json' ) as $found ) {
				return dirname( $found );
			}
		}
		return null;
	}

	/**
	 * Read and validate a bundle.
	 *
	 * @param string $bundle_dir Directory containing manifest.json.
	 * @return array|WP_Error {
	 *     @type string $dir
	 *     @type array  $manifest
	 *     @type array  $sources  Each with key/kind/html (portable)/sha256/title/slug/pseudo/seo.
	 *     @type array  $posts
	 *     @type array  $terms
	 *     @type array  $menus
	 *     @type array  $options
	 *     @type array  $settings
	 *     @type array  $redirects Original path => visual-edit key.
	 *     @type array  $media
	 * }
	 */
	public static function read( $bundle_dir ) {
		$bundle_dir = untrailingslashit( $bundle_dir );
		$manifest   = self::read_json( $bundle_dir . '/manifest.json' );
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'clara_ve_bundle_manifest', __( 'This bundle has no readable manifest.json.', 'visual-edit-lite' ) );
		}

		$format = isset( $manifest['format'] ) ? (string) $manifest['format'] : '';
		if ( 0 !== strpos( $format, 'clara-content/' ) ) {
			return new WP_Error( 'clara_ve_bundle_format', __( 'This does not look like a Clara content bundle.', 'visual-edit-lite' ) );
		}
		// Major version is the compatibility boundary; a bundle written by a
		// newer major is refused rather than half-understood.
		$major = (int) substr( $format, strlen( 'clara-content/' ) );
		if ( $major > (int) substr( Clara_VE_Bundle_Format::FORMAT, strlen( 'clara-content/' ) ) ) {
			return new WP_Error(
				'clara_ve_bundle_newer',
				sprintf(
					/* translators: %s: bundle format identifier */
					__( 'This bundle (%s) was made by a newer version of the plugin. Update Visual Edit and try again.', 'visual-edit-lite' ),
					$format
				)
			);
		}

		$sources = array();
		$index   = self::read_json( $bundle_dir . '/sources/index.json' );
		if ( is_array( $index ) ) {
			if ( count( $index ) > self::MAX_SOURCES ) {
				return new WP_Error( 'clara_ve_bundle_huge', __( 'This bundle declares an implausible number of pages and was not opened.', 'visual-edit-lite' ) );
			}
			foreach ( $index as $row ) {
				$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
				if ( '' === $key || empty( $row['file'] ) ) {
					continue;
				}
				$html = self::read_file( $bundle_dir, $row['file'] );
				if ( null === $html ) {
					continue;
				}
				$sources[] = array(
					'key'    => $key,
					'kind'   => isset( $row['kind'] ) ? (string) $row['kind'] : 'page',
					'html'   => $html,
					'sha256' => isset( $row['sha256'] ) ? (string) $row['sha256'] : Clara_VE_Bundle_Format::hash_source( $html ),
					'title'  => isset( $row['title'] ) ? (string) $row['title'] : $key,
					'slug'   => isset( $row['slug'] ) ? (string) $row['slug'] : $key,
					'pseudo' => empty( $row['pseudo'] ) ? array() : (array) self::read_json( $bundle_dir . '/' . self::safe_relative( $row['pseudo'] ) ),
					// Absent in a bundle written before SEO capture existed;
					// sanitize_seo() returns the empty shape for that case, so
					// the importer has one thing to handle instead of two.
					'seo'    => Clara_VE_Bundle_Format::sanitize_seo( isset( $row['seo'] ) ? $row['seo'] : null ),
				);
			}
		}

		$posts = (array) self::read_json( $bundle_dir . '/posts.json' );
		$media = (array) self::read_json( $bundle_dir . '/media/index.json' );
		if ( count( $posts ) > self::MAX_POSTS || count( $media ) > self::MAX_MEDIA ) {
			return new WP_Error( 'clara_ve_bundle_huge', __( 'This bundle declares an implausible number of posts or files and was not opened.', 'visual-edit-lite' ) );
		}

		// Options are filtered on the way IN as well as on the way out: a
		// hand-edited bundle must not be able to set an option the exporter
		// would never have written, least of all a credential.
		$allowed = array_flip( array_keys( Clara_VE_Bundle_Format::OPTIONS ) );
		$options = array();
		foreach ( (array) self::read_json( $bundle_dir . '/options.json' ) as $name => $value ) {
			if ( isset( $allowed[ $name ] ) && ! in_array( $name, Clara_VE_Bundle_Format::NEVER_EXPORT, true ) ) {
				$options[ $name ] = $value;
			}
		}

		// Absent entirely for a bundle exported without opting into private
		// data (or an older bundle from before this existed) — '(array)' on a
		// missing file already reads as "none", same as terms/menus/posts.
		$submissions = (array) self::read_json( $bundle_dir . '/submissions.json' );
		$subscribers = (array) self::read_json( $bundle_dir . '/subscribers.json' );
		if ( count( $submissions ) > self::MAX_SUBMISSIONS || count( $subscribers ) > self::MAX_SUBSCRIBERS ) {
			return new WP_Error( 'clara_ve_bundle_huge', __( 'This bundle declares an implausible number of submissions or subscribers and was not opened.', 'visual-edit-lite' ) );
		}

		return array(
			'dir'         => $bundle_dir,
			'manifest'    => $manifest,
			'sources'     => $sources,
			'posts'       => $posts,
			'terms'       => (array) self::read_json( $bundle_dir . '/terms.json' ),
			'menus'       => (array) self::read_json( $bundle_dir . '/menus.json' ),
			'options'     => $options,
			'settings'    => (array) self::read_json( $bundle_dir . '/settings.json' ),
			'media'       => $media,
			'submissions' => $submissions,
			'subscribers' => $subscribers,
			// Absent in bundles written before URL parity existed; an empty map
			// simply means no redirects, which is the old behaviour exactly.
			'redirects'   => Clara_VE_Bundle_Format::sanitize_redirects( self::read_json( $bundle_dir . '/redirects.json' ) ),
		);
	}

	/**
	 * The bundle shipped inside the active theme, if there is one.
	 *
	 * @return string|null
	 */
	public static function theme_bundle_dir() {
		$dir = untrailingslashit( get_template_directory() ) . '/' . Clara_VE_Bundle_Format::DIR;
		return file_exists( $dir . '/manifest.json' ) ? $dir : null;
	}

	/**
	 * Resolve a bundle-relative path, refusing anything that climbs out of
	 * the bundle. A bundle is a file someone was handed; "media/files/../../
	 * ../wp-config.php" must not resolve.
	 *
	 * @param string $relative
	 * @return string Cleaned relative path ('' when unsafe).
	 */
	public static function safe_relative( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		if ( '' === $relative || '/' === $relative[0] || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			return '';
		}
		if ( preg_match( '#^[A-Za-z]:#', $relative ) ) {
			return ''; // absolute Windows path
		}
		return ltrim( $relative, '/' );
	}

	/**
	 * @param string $bundle_dir
	 * @param string $relative
	 * @return string|null
	 */
	private static function read_file( $bundle_dir, $relative ) {
		$safe = self::safe_relative( $relative );
		if ( '' === $safe ) {
			return null;
		}
		$path = $bundle_dir . '/' . $safe;
		if ( ! file_exists( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$blob = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $blob ? null : $blob;
	}

	/**
	 * @param string $path
	 * @return mixed Decoded JSON, or null.
	 */
	private static function read_json( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$blob = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $blob ) {
			return null;
		}
		$data = json_decode( $blob, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $data : null;
	}
}
