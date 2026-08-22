<?php
/**
 * Media sideloading for the editor.
 *
 * One job: take bytes the editor fetched and store them as a real
 * attachment, so a page stops depending on somebody else's server for an
 * image it displays.
 *
 * @package Visual_Edit_Lite
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Media {

	const MAX_SOURCE_BYTES  = 16777216; // 16 MB — matches open-design's guardrail.
	const ALLOWED_IMAGE_EXT = array( 'png', 'jpg', 'jpeg', 'webp', 'gif' );

	public static function sideload( $bytes, $ext, $mime, $name_prefix, $title ) {
		$filename = $name_prefix . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'clara_ve_media_save', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => '' !== $title ? $title : __( 'AI-generated media', 'visual-edit-lite' ),
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'clara_ve_media_attach', __( 'The result could not be saved to the Media Library.', 'visual-edit-lite' ), array( 'status' => 500 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		// media.php too: for VIDEO attachments wp_generate_attachment_metadata()
		// calls wp_read_video_metadata(), which lives there — without it every
		// video sideload FATALED (500) in REST context (browser status polls),
		// while WP-CLI/admin contexts, which preload it, worked. image.php
		// alone only covered image sideloads.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		// wp_generate_attachment_metadata() handles both images and (where
		// ffmpeg/getid3 support exists) video; harmless no-op metadata
		// otherwise — the attachment is still fully usable either way.
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
