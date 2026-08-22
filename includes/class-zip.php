<?php
/**
 * Minimal ZIP read/write, with no dependency beyond what WordPress itself
 * ships. The plugin deliberately has no composer/vendor tree, so this wraps
 * PHP's ZipArchive and falls back to the PclZip copy bundled in WordPress
 * core.
 *
 * Writing works by zipping a prepared STAGING DIRECTORY rather than adding
 * entries one at a time. Two reasons: ZipArchive and PclZip disagree about
 * almost everything in their incremental APIs but agree on "archive this
 * folder", and staging makes "the ZIP contains exactly one top-level
 * directory" — which is what WordPress's theme installer requires — a
 * structural property instead of a rule every caller has to remember.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Zip {

	/**
	 * @return bool Whether ext/zip is present. PclZip still works without it,
	 *              but it reads whole archives into memory, so callers warn.
	 */
	public static function has_ziparchive() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Archive $source_dir so that its own basename is the single top-level
	 * entry (e.g. staging/my-theme → "my-theme/style.css", ...).
	 *
	 * @param string $source_dir Absolute path to the directory to archive.
	 * @param string $zip_path   Absolute path of the .zip to create.
	 * @return true|WP_Error
	 */
	public static function zip_directory( $source_dir, $zip_path ) {
		$source_dir = untrailingslashit( $source_dir );
		if ( ! is_dir( $source_dir ) ) {
			return new WP_Error( 'clara_ve_zip_source', __( 'Nothing to archive.', 'visual-edit-lite' ) );
		}

		if ( self::has_ziparchive() ) {
			return self::zip_with_ziparchive( $source_dir, $zip_path );
		}
		return self::zip_with_pclzip( $source_dir, $zip_path );
	}

	/**
	 * @param string $source_dir
	 * @param string $zip_path
	 * @return true|WP_Error
	 */
	private static function zip_with_ziparchive( $source_dir, $zip_path ) {
		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $opened ) {
			return new WP_Error( 'clara_ve_zip_open', __( 'Could not create the ZIP file.', 'visual-edit-lite' ) );
		}

		$root  = basename( $source_dir );
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $items as $item ) {
			$path = $item->getPathname();
			$rel  = $root . '/' . ltrim( substr( $path, strlen( $source_dir ) ), '/\\' );
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $rel );
			} else {
				$zip->addFile( $path, $rel );
			}
		}

		if ( ! $zip->close() ) {
			return new WP_Error( 'clara_ve_zip_close', __( 'Could not finish writing the ZIP file.', 'visual-edit-lite' ) );
		}
		return true;
	}

	/**
	 * @param string $source_dir
	 * @param string $zip_path
	 * @return true|WP_Error
	 */
	private static function zip_with_pclzip( $source_dir, $zip_path ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip = new PclZip( $zip_path );
		// Removing the PARENT path (not the source path itself) is what leaves
		// the source directory's own name as the single top-level entry.
		$result = $zip->create( $source_dir, PCLZIP_OPT_REMOVE_PATH, dirname( $source_dir ) );
		if ( 0 === $result ) {
			return new WP_Error( 'clara_ve_zip_pclzip', $zip->errorInfo( true ) );
		}
		return true;
	}

	/**
	 * Extract an uploaded archive. Delegates to core's unzip_file(), which
	 * already prefers ZipArchive and falls back to PclZip on its own, and
	 * which enforces the available-disk-space check this would otherwise have
	 * to repeat.
	 *
	 * @param string $zip_path
	 * @param string $dest_dir
	 * @return true|WP_Error
	 */
	public static function extract( $zip_path, $dest_dir ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'clara_ve_zip_fs', __( 'Could not initialize the filesystem to extract the ZIP.', 'visual-edit-lite' ) );
		}
		wp_mkdir_p( $dest_dir );
		return unzip_file( $zip_path, $dest_dir );
	}

	/**
	 * Copy a directory tree into $dest.
	 *
	 * $skip applies to the TOP LEVEL ONLY, and that restriction is the whole
	 * point. Matching those names at any depth looks tidier and quietly
	 * destroys themes: "vendor" is a composer directory at the theme root, but
	 * it is also assets/css/vendor/ and assets/js/vendor/, where a theme keeps
	 * the third-party front-end libraries its design depends on. Dropping
	 * those does not produce an obvious error — a stylesheet registered with a
	 * dependency that no longer exists is silently not printed at all, so the
	 * exported theme installs cleanly and renders with no CSS.
	 *
	 * Same reasoning for "src": a root-level src/ is unbuilt source, a nested
	 * one may be anything. When in doubt this copies too much, which costs
	 * bytes; the other direction costs the design.
	 *
	 * @param string   $source_dir
	 * @param string   $dest_dir
	 * @param string[] $skip Top-level names to exclude (directories or files).
	 * @return int Number of files copied.
	 */
	public static function copy_tree( $source_dir, $dest_dir, array $skip = array() ) {
		$source_dir = untrailingslashit( $source_dir );
		$dest_dir   = untrailingslashit( $dest_dir );
		$skip_map   = array_flip( $skip );
		$copied     = 0;

		wp_mkdir_p( $dest_dir );
		$handle = @opendir( $source_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return 0;
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || isset( $skip_map[ $entry ] ) ) {
				continue;
			}
			// Dotfiles are editor/tooling config (.editorconfig, .eslintrc.json,
			// .gitignore, .DS_Store) — never part of a shipped theme, and
			// listing each one by name would just rot.
			if ( '.' === $entry[0] ) {
				continue;
			}
			$from = $source_dir . '/' . $entry;
			$to   = $dest_dir . '/' . $entry;
			if ( is_dir( $from ) ) {
				// No $skip below the top level — see the docblock.
				$copied += self::copy_tree( $from, $to, array() );
			} elseif ( copy( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				++$copied;
			}
		}
		closedir( $handle );
		return $copied;
	}

	/**
	 * Total byte size of a directory tree, for the export screen's estimate.
	 *
	 * @param string   $dir
	 * @param string[] $skip
	 * @return int
	 */
	public static function dir_size( $dir, array $skip = array() ) {
		$dir = untrailingslashit( $dir );
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$skip_map = array_flip( $skip );
		$total    = 0;
		$handle   = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return 0;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry || '.' === $entry[0] || isset( $skip_map[ $entry ] ) ) {
				continue;
			}
			$path   = $dir . '/' . $entry;
			// Top-level-only skipping, to match copy_tree() — otherwise the
			// size shown on the export screen quietly under-reports what the
			// ZIP will actually contain.
			$total += is_dir( $path ) ? self::dir_size( $path, array() ) : (int) filesize( $path );
		}
		closedir( $handle );
		return $total;
	}

	/**
	 * Recursively delete a scratch directory.
	 *
	 * @param string $dir
	 * @return void
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = trailingslashit( $dir ) . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * A private scratch directory under uploads, unique per call.
	 *
	 * @param string $prefix
	 * @return string|WP_Error Absolute path (already created and verified
	 *                          writable), or an error naming exactly what
	 *                          could not be created.
	 *
	 *                          wp_mkdir_p() fails SILENTLY — no exception, no
	 *                          warning, just a false return the caller has to
	 *                          check. Every prior caller of this method
	 *                          skipped that check and treated the path as good
	 *                          regardless, so a permissions problem on
	 *                          wp-content/uploads surfaced three steps later
	 *                          as "Nothing to archive." — a message that reads
	 *                          like an empty theme, not a server permissions
	 *                          fault, and sends whoever is troubleshooting it
	 *                          looking in exactly the wrong place. Hit for
	 *                          real during this feature's own testing: a
	 *                          leftover directory owned by a different user
	 *                          than the webserver blocked exactly this.
	 */
	public static function scratch_dir( $prefix ) {
		$base = trailingslashit( wp_upload_dir()['basedir'] ) . $prefix . '/' . wp_generate_password( 12, false );
		wp_mkdir_p( $base );
		if ( ! is_dir( $base ) || ! wp_is_writable( $base ) ) {
			return new WP_Error(
				'clara_ve_scratch_dir',
				sprintf(
					/* translators: %s: absolute directory path */
					__( 'Could not create a writable folder at %s. Check that wp-content/uploads is writable by the web server (a leftover folder owned by a different user is a common cause).', 'visual-edit-lite' ),
					dirname( $base )
				)
			);
		}
		return $base;
	}
}
