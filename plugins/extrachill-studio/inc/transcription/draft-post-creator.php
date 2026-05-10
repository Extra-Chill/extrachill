<?php
/**
 * Draft Post Creator
 *
 * Creates a draft post on the current site (i.e. the site that submitted
 * the transcription job — typically studio.extrachill.com) under the
 * uploading user's authorship. The draft surfaces in the Blog tab's
 * drafts dropdown automatically because the Blog tab queries the same
 * `wp/v2/posts?status=draft` endpoint on the same site.
 *
 * Side-effect integration: Transcribe and Blog tabs share zero code, but
 * both operate on the same `status=draft` posts on the same site, so a
 * transcription draft is just a regular draft from the Blog tab's
 * perspective.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a draft post on the current site with the transcription content.
 *
 * @since 0.10.0
 *
 * @param array  $job                 Persisted job row.
 * @param string $transcript_content  Transcript body to use as post_content.
 * @return int|\WP_Error Draft post ID on success, WP_Error on failure.
 */
function ec_studio_transcription_create_draft( array $job, string $transcript_content ): int|\WP_Error {
	$user_id = (int) ( $job['user_id'] ?? 0 );

	if ( $user_id <= 0 ) {
		return new \WP_Error(
			'invalid_user',
			__( 'Job is missing a valid user_id.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	if ( '' === trim( $transcript_content ) ) {
		return new \WP_Error(
			'empty_transcript',
			__( 'Transcript content is empty.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$attachment_url = (string) ( $job['attachment_url'] ?? '' );
	$attachment_id  = (int) ( $job['attachment_id'] ?? 0 );

	$title = sprintf(
		/* translators: %s: source recording filename */
		__( 'Transcription: %s', 'extrachill-studio' ),
		wp_basename( $attachment_url )
	);

	$postarr = array(
		'post_title'   => $title,
		'post_status'  => 'draft',
		'post_author'  => $user_id,
		'post_content' => $transcript_content,
		'post_type'    => 'post',
		'meta_input'   => array(
			'_studio_source_recording_url'   => $attachment_url,
			'_studio_source_recording_id'    => $attachment_id,
			'_studio_transcription_model'    => (string) ( $job['model'] ?? '' ),
			'_studio_transcription_diarized' => ! empty( $job['diarize'] ) ? '1' : '0',
			'_studio_transcription_job_id'   => (string) ( $job['job_id'] ?? '' ),
		),
	);

	$result = wp_insert_post( $postarr, true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$post_id = (int) $result;
	if ( $post_id <= 0 ) {
		return new \WP_Error(
			'draft_create_failed',
			__( 'wp_insert_post returned 0; draft was not created.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	return $post_id;
}
