<?php
/**
 * Draft Post Creator
 *
 * Cross-blog draft post creation for completed transcriptions. Creates a draft
 * on the target blog (default blog_id=1, the main extrachill.com site) under
 * the uploading user's authorship. extrachill-users guarantees user_id parity
 * across the network so post_author is the same id everywhere.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a draft post on the target blog with the transcription content.
 *
 * @since 0.10.0
 *
 * @param array  $job                 Persisted job row.
 * @param string $transcript_content  Transcript body to use as post_content.
 * @return int|\WP_Error Draft post ID on success, WP_Error on failure.
 */
function ec_studio_transcription_create_draft( array $job, string $transcript_content ): int|\WP_Error {
	$user_id        = (int) ( $job['user_id'] ?? 0 );
	$target_blog_id = (int) ( $job['target_blog_id'] ?? 1 );

	if ( $user_id <= 0 ) {
		return new \WP_Error(
			'invalid_user',
			__( 'Job is missing a valid user_id.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_has_main_site_account' ) || ! \ec_has_main_site_account( $user_id ) ) {
		return new \WP_Error(
			'no_main_site_account',
			__( 'User does not have an account on the main site and cannot author drafts there.', 'extrachill-studio' ),
			array( 'status' => 403 )
		);
	}

	if ( '' === trim( $transcript_content ) ) {
		return new \WP_Error(
			'empty_transcript',
			__( 'Transcript content is empty.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$attachment_url   = (string) ( $job['attachment_url'] ?? '' );
	$attachment_id    = (int) ( $job['attachment_id'] ?? 0 );
	$created_at       = (string) ( $job['created_at'] ?? '' );
	$created_ts       = $created_at ? strtotime( $created_at ) : false;
	$created_ts       = $created_ts ?: time();

	$title = sprintf(
		/* translators: 1: source recording filename, 2: human-readable date */
		__( 'Transcription: %1$s — %2$s', 'extrachill-studio' ),
		wp_basename( $attachment_url ),
		wp_date( 'M j, Y', $created_ts )
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

	switch_to_blog( $target_blog_id );

	try {
		$result = wp_insert_post( $postarr, true );
	} finally {
		restore_current_blog();
	}

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
