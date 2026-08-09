<?php
/**
 * Regression: an identical upload is not an imported medium unless WordPress
 * also has a visible attachment for it.
 *
 * Run inside a disposable WordPress install:
 *   wp eval-file tests/regression-import-media.php
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Import_Plan' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$upload    = wp_upload_dir();
$relative  = 'clara-ve-import/visual-edit-regression/reinstall-media.png';
$target    = untrailingslashit( $upload['basedir'] ) . '/' . $relative;
$url       = untrailingslashit( $upload['baseurl'] ) . '/' . $relative;
$scaled_relative = dirname( $relative ) . '/reinstall-media-scaled.png';
$scaled_target   = untrailingslashit( $upload['basedir'] ) . '/' . $scaled_relative;
$fixture   = trailingslashit( get_temp_dir() ) . 'clara-ve-regression-' . wp_generate_password( 8, false );
$source    = $fixture . '/media/files/' . $relative;
$theme     = sanitize_key( get_stylesheet() );
$post_slug = 'visual-edit-media-regression-post';
$registry  = get_option( Clara_VE_Theme_Registry::OPTION, array() );
$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );

$find_attachment = static function () use ( $url, $target ) {
	return Clara_VE_Import_Legacy::attachment_id_for_file( $url, $target );
};

$remove_fixture = static function () use ( $find_attachment, $target, $scaled_target, $fixture, $registry, $post_slug ) {
	$post = get_page_by_path( $post_slug, OBJECT, 'post' );
	if ( $post ) {
		wp_delete_post( $post->ID, true );
	}
	$id = $find_attachment();
	if ( $id ) {
		wp_delete_attachment( $id, true );
	}
	if ( is_file( $target ) ) {
		wp_delete_file( $target );
	}
	if ( is_file( $scaled_target ) ) {
		wp_delete_file( $scaled_target );
	}
	Clara_VE_Zip::rrmdir( $fixture );
	update_option( Clara_VE_Theme_Registry::OPTION, $registry, false );
	Clara_VE_Theme_Park::flush_parked_memo();
};

$assert = static function ( $condition, $message ) use ( $remove_fixture ) {
	if ( $condition ) {
		return;
	}
	$remove_fixture();
	throw new RuntimeException( $message );
};

$old_id = $find_attachment();
if ( $old_id ) {
	wp_delete_attachment( $old_id, true );
}
wp_mkdir_p( dirname( $target ) );
wp_mkdir_p( dirname( $source ) );
file_put_contents( $target, $png_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $source, $png_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

$file = array(
	'id'      => 987654,
	'file'    => $relative,
	'sha1'    => sha1( $png_bytes ),
	'alt'     => 'Reinstalled media regression',
	'bundled' => true,
);
$bundle = array(
	'dir'   => $fixture,
	'media' => array( $file ),
);

$classify = new ReflectionMethod( 'Clara_VE_Import_Plan', 'classify_media' );
$apply    = new ReflectionMethod( 'Clara_VE_Import_Plan', 'apply_media' );
$posts    = new ReflectionMethod( 'Clara_VE_Import_Plan', 'apply_posts' );

// Case 1: uninstall left the bytes behind, but no attachment row survived.
$item = $classify->invoke( null, $file );
$assert( 'new' === $item['status'], 'An orphaned upload was incorrectly classified as already imported.' );

$lines   = array();
$allowed = array( 'media' => array( $relative => true ) );
$map     = $apply->invokeArgs( null, array( $bundle, $allowed, &$lines ) );
$id      = attachment_url_to_postid( $url );
$assert( $id > 0, 'The orphaned upload was not registered in the Media Library.' );
$assert( isset( $map[987654] ) && $id === (int) $map[987654], 'The old attachment ID was not remapped.' );

// Blog featured images consume that remap; test the user-visible failure, not
// only the lower-level attachment repair.
$blog_bundle = array(
	'posts' => array(
		array(
			'key'            => $post_slug,
			'slug'           => $post_slug,
			'title'          => 'Media regression post',
			'status'         => 'publish',
			'content'        => '',
			'excerpt'        => '',
			'date_gmt'       => '',
			'featured_media' => 'media:987654',
			'terms'          => array(),
			'seo'            => array(),
		),
	),
);
$lines       = array();
$post_allow  = array( 'post' => array( $post_slug => true ) );
$posts->invokeArgs( null, array( $blog_bundle, $post_allow, $map, &$lines ) );
$blog_post = get_page_by_path( $post_slug, OBJECT, 'post' );
$assert( $blog_post && $id === (int) get_post_thumbnail_id( $blog_post->ID ), 'The repaired attachment was not assigned as the blog featured image.' );

// Case 2: a deactivated/removed theme left an attachment parked and hidden.
Clara_VE_Theme_Registry::remember( $theme, array( 'parked' => current_time( 'mysql' ) ) );
update_post_meta( $id, CLARA_VE_PAGE_THEME_META, $theme );
update_post_meta( $id, Clara_VE_Theme_Park::PARKED_META, $theme );
Clara_VE_Theme_Park::flush_parked_memo();

$item = $classify->invoke( null, $file );
$assert( 'new' === $item['status'], 'A hidden parked attachment was incorrectly classified as already imported.' );

$lines = array();
$map   = $apply->invokeArgs( null, array( $bundle, $allowed, &$lines ) );
$assert( '' === (string) get_post_meta( $id, Clara_VE_Theme_Park::PARKED_META, true ), 'The imported attachment remained parked.' );
$assert( $id === attachment_url_to_postid( $url ), 'The existing attachment was duplicated instead of restored.' );

$visible = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
$assert( in_array( $id, array_map( 'intval', $visible ), true ), 'The restored attachment is still hidden from Media Library queries.' );
$item = $classify->invoke( null, $file );
$assert( 'identical' === $item['status'], 'A healthy attachment would be imported again instead of skipped.' );
$assert( $id === $find_attachment(), 'The parked attachment was duplicated instead of restored in place.' );

// WordPress changes a large image attachment to `name-scaled.ext` while
// keeping the bundle's original file and recording it only in original_image.
// The original URL must still resolve to that attachment for both planning
// and the featured-image ID map, or every re-import creates another copy.
copy( $target, $scaled_target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
update_attached_file( $id, $scaled_target );
$metadata                   = (array) wp_get_attachment_metadata( $id );
$metadata['file']           = $scaled_relative;
$metadata['original_image'] = basename( $relative );
wp_update_attachment_metadata( $id, $metadata );
$assert( 0 === attachment_url_to_postid( $url ), 'The scaled-image fixture still resolves through the ordinary URL lookup.' );

$item = $classify->invoke( null, $file );
$assert( 'identical' === $item['status'], 'A WordPress-scaled image was incorrectly classified as missing.' );
$lines = array();
$map   = $apply->invokeArgs( null, array( $bundle, array(), &$lines ) );
$assert( isset( $map[987654] ) && $id === (int) $map[987654], 'A scaled image did not reach the blog attachment-ID map.' );
$assert( $id === $find_attachment(), 'A scaled image was duplicated during re-import.' );

$remove_fixture();
echo "PASS: media is registered and visible after reinstall/import.\n";
