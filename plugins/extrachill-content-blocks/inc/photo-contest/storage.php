<?php
/**
 * Photo Contest domain: entry photo storage.
 *
 * @package ExtraChillContentBlocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store an uploaded entry photo as an attachment parented to the contest post.
 *
 * The `extrachill_content_blocks_photo_contest_store_photo` filter is a
 * short-circuit seam: when it returns non-null (an attachment ID or a
 * WP_Error), that result is used and the filesystem is never touched. Tests
 * substitute an in-memory store through this filter.
 *
 * Mirrors the anonymous-upload precedent in extrachill-events'
 * EventSubmissionAbilities::storeFlyer() (wp_handle_upload, test_form=false)
 * but keeps the file in the media library as a real attachment.
 *
 * @param int    $post_id      Contest post ID.
 * @param array  $file         One $_FILES-shaped entry.
 * @param string $display_name Entrant display name (used for the title).
 * @return int|\WP_Error Attachment ID, or status-coded WP_Error on failure.
 */
function extrachill_content_blocks_photo_contest_store_photo( $post_id, array $file, $display_name ) {
	/**
	 * Short-circuit entry photo storage.
	 *
	 * @param int|\WP_Error|null $stored       Non-null short-circuits: attachment ID or WP_Error.
	 * @param int                $post_id      Contest post ID.
	 * @param array              $file         One $_FILES-shaped entry.
	 * @param string             $display_name Entrant display name.
	 */
	$stored = apply_filters( 'extrachill_content_blocks_photo_contest_store_photo', null, (int) $post_id, $file, (string) $display_name );
	if ( null !== $stored ) {
		return is_wp_error( $stored ) ? $stored : (int) $stored;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => extrachill_content_blocks_photo_contest_allowed_mimes(),
		)
	);

	if ( ! is_array( $upload ) || isset( $upload['error'] ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'upload_failed',
			is_array( $upload ) && isset( $upload['error'] ) ? (string) $upload['error'] : 'Upload failed.',
			400
		);
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => (string) $upload['type'],
			'post_title'     => sanitize_text_field( $display_name . ' — contest entry' ),
			'post_status'    => 'inherit',
		),
		$upload['file'],
		(int) $post_id,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'upload_failed',
			$attachment_id->get_error_message(),
			400
		);
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	if ( ! empty( $metadata ) ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	return (int) $attachment_id;
}
