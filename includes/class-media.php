<?php
/**
 * Saving raw bytes into the Media Library as a new attachment.
 *
 * Derived from Visual Edit Pro's class-ai-media.php, which existed to feed the
 * AI image/video tools. Lite ships no AI, so the two provider-facing helpers
 * (resolve_local_image() and its path resolver) are gone; what stays is the
 * part the ordinary editor uses — importing a remotely-hosted design image
 * into this site's own Media Library (see Clara_VE_REST::import_image()).
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Media {

	const MAX_SOURCE_BYTES  = 16777216; // 16 MB.
	const ALLOWED_IMAGE_EXT = array( 'png', 'jpg', 'jpeg', 'webp', 'gif' );

	/**
	 * Save raw bytes as a new Media Library attachment.
	 *
	 * @param string $bytes
	 * @param string $ext          File extension without a dot, e.g. 'png'.
	 * @param string $mime
	 * @param string $name_prefix  Filename prefix, e.g. 'imported'.
	 * @param string $title        Attachment title (also used as image alt text).
	 * @return array|WP_Error { id, url }.
	 */
	public static function sideload( $bytes, $ext, $mime, $name_prefix, $title ) {
		$filename = $name_prefix . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'clara_ve_media_save', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => '' !== $title ? $title : __( 'Imported media', 'visual-edit-lite' ),
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'clara_ve_media_attach', __( 'The image could not be saved to the Media Library.', 'visual-edit-lite' ), array( 'status' => 500 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );
		if ( '' !== $title ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $title );
		}

		return array(
			'id'  => (int) $attach_id,
			'url' => (string) wp_get_attachment_url( $attach_id ),
		);
	}
}
