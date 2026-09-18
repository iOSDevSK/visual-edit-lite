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
		// A scratch folder can hold an export with form submissions in it, under
		// a web-served path, for as long as the download takes. The folder name
		// is random; these keep its parent from being listed or served as well.
		// Written to the PARENT only: the folder itself is handed to unzip and
		// to the importer, which read what is in it.
		$parent = dirname( $base );
		if ( ! file_exists( $parent . '/index.php' ) ) {
			file_put_contents( $parent . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixed string into the plugin's own scratch folder under uploads.
		}
		if ( ! file_exists( $parent . '/.htaccess' ) ) {
			file_put_contents( $parent . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- as above.
		}
		return $base;
	}
}
