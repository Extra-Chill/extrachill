<?php
/**
 * Draft Post Creator
 *
 * Creates a draft post on the main extrachill.com site for a completed
 * transcription. Studio runs at studio.extrachill.com (a subsite); the
 * Blog tab and Transcribe tab both target main extrachill.com because
 * that's where editorial content publishes.
 *
 * Cross-site dispatch goes through the universal extrachill-multisite
 * primitive `ec_cross_site_rest_request()`, which (as of multisite
 * v1.12.3) accepts fully-qualified REST paths including core WP routes
 * like `/wp/v2/posts`. No manual switch_to_blog ceremony in studio code.
 *
 * Side-effect integration with the Blog tab: both tabs write through
 * `/wp/v2/posts` on main extrachill.com, so a transcription draft surfaces
 * in the Blog tab's drafts dropdown automatically. Tabs are decoupled in
 * code; shared WordPress data does the integration.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a draft post on the main extrachill.com site.
 *
 * @since 0.10.0
 * @since 0.10.2 Refactored to use ec_cross_site_rest_request() universal primitive.
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

	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site draft creation.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$attachment_url = (string) ( $job['attachment_url'] ?? '' );
	$attachment_id  = (int) ( $job['attachment_id'] ?? 0 );

	$title = sprintf(
		/* translators: %s: source recording filename */
		__( 'Transcription: %s', 'extrachill-studio' ),
		wp_basename( $attachment_url )
	);

	// POST /wp/v2/posts on main extrachill.com via the universal cross-site
	// primitive. Path is fully-qualified (`/wp/v2/posts`); the helper passes
	// it through verbatim (multisite v1.12.3+).
	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts',
		array(
			'body'    => array(
				'title'   => $title,
				'status'  => 'draft',
				'author'  => $user_id,
				'content' => $transcript_content,
			),
			'user_id' => $user_id,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$post_id = isset( $response['id'] ) ? (int) $response['id'] : 0;
	if ( $post_id <= 0 ) {
		return new \WP_Error(
			'draft_create_no_id',
			__( 'wp/v2/posts returned a response without a post id.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	// Attach Studio-specific meta on main extrachill.com. The /wp/v2/posts
	// `meta` field only accepts keys registered with show_in_rest; rather
	// than register all of them globally, we make a second cross-site call
	// to update_post_meta via switch_to_blog inside the multisite helper.
	//
	// We do this via a tiny extrachill-studio REST route registered on main,
	// OR we accept the round-trip cost of N cross-site calls. For v1, the
	// simpler approach: a single cross-site update via the WP /wp/v2/posts/<id>
	// endpoint with `meta`, which works if the keys are registered. Since
	// they aren't, we set meta directly inside a switch_to_blog block in
	// studio's own process — the only switch_to_blog in this file, justified
	// because there's no /wp/v2/posts/<id>/meta route that accepts arbitrary keys.
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id > 0 ) {
		switch_to_blog( $main_blog_id );
		try {
			update_post_meta( $post_id, '_studio_source_recording_url', $attachment_url );
			update_post_meta( $post_id, '_studio_source_recording_id', $attachment_id );
			update_post_meta( $post_id, '_studio_transcription_model', (string) ( $job['model'] ?? '' ) );
			update_post_meta( $post_id, '_studio_transcription_diarized', ! empty( $job['diarize'] ) ? '1' : '0' );
			update_post_meta( $post_id, '_studio_transcription_job_id', (string) ( $job['job_id'] ?? '' ) );
		} finally {
			restore_current_blog();
		}
	}

	return $post_id;
}
