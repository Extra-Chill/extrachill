<?php
/**
 * Browser-Initiated Transcription Draft Persistence
 *
 * Closes the data-loss window described in #193.
 *
 * Before this route existed, a finished transcript reached durable storage
 * through exactly ONE path: sweatpants signing and POSTing a completion
 * callback to `/extrachill/v1/transcribe/callback`. The React tab held the
 * transcript in component state only — see the module docblock in
 * `src/blocks/studio/tabs/transcribe/index.ts`:
 *
 *     "jobs live only in this component's React state — no local store.
 *      Past transcriptions disappear when the user navigates away."
 *
 * So when the callback did not arrive, a user who followed the UI's own
 * instruction ("We'll email you when it's done — you can close this tab")
 * silently lost the transcript. That is exactly how the only production
 * transcription to date ended up needing `_studio_transcription_manual_recovery`.
 *
 * This route lets the browser persist the transcript itself the moment its
 * polling loop observes a terminal `completed` status, so durability no
 * longer depends on an inbound network call from an external worker.
 *
 * Idempotency and convergence with the callback:
 *
 *   Both paths key on `_studio_transcription_job_id` and both resolve an
 *   existing draft through `ec_studio_transcription_callback_find_draft()`
 *   before creating anything. Whichever path runs first creates the draft;
 *   the other finds it and reuses it. A late callback therefore cannot
 *   produce a duplicate post, and the callback remains a useful redundant
 *   path (and the thing that sends the email) rather than the single point
 *   of failure.
 *
 * This route is deliberately NOT a replacement for the callback receiver.
 * The receiver still owns the unauthenticated, HMAC-verified ingress from
 * sweatpants. This route is the authenticated, first-party ingress from a
 * logged-in team member's own browser session.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      X.Y.Z
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the browser-facing persistence route.
 *
 * @return void
 */
function ec_studio_transcription_register_persist_route(): void {
	register_rest_route(
		'extrachill/v1',
		'/transcribe/draft',
		array(
			'methods'             => 'POST',
			'callback'            => 'ec_studio_transcription_handle_persist',
			'permission_callback' => 'ec_studio_transcription_persist_permission_check',
			'args'                => array(
				'job_id'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'transcript' => array(
					'type'     => 'string',
					'required' => true,
				),
				'filename'   => array(
					'type'     => 'string',
					'required' => false,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ec_studio_transcription_register_persist_route' );

/**
 * Only logged-in Studio team members may persist their own transcript.
 *
 * Mirrors the Studio compose gate: administrators, or team members holding
 * the `access_transcribe` capability granted by the `extra_chill_team` role.
 * The draft is always authored by the CURRENT user — the request body has no
 * say in authorship — so this cannot be used to write posts as someone else.
 *
 * @return true|\WP_Error
 */
function ec_studio_transcription_persist_permission_check() {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'You must be logged in to save a transcription.', 'extrachill-studio' ),
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'access_transcribe' ) ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Transcription is available to Extra Chill team members only.', 'extrachill-studio' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Persist a completed transcript as a draft on main extrachill.com.
 *
 * Idempotent per (user, job_id): a second call returns the existing post id
 * instead of creating another draft.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_transcription_handle_persist( \WP_REST_Request $request ) {
	$user_id = (int) get_current_user_id();

	$job_id     = trim( (string) $request->get_param( 'job_id' ) );
	$transcript = (string) $request->get_param( 'transcript' );
	$filename   = (string) $request->get_param( 'filename' );
	$stats      = $request->get_param( 'stats' );
	$stats      = is_array( $stats ) ? $stats : array();

	if ( '' === $job_id ) {
		return new \WP_Error( 'missing_job_id', __( 'A job id is required.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}
	if ( strlen( $job_id ) > 191 ) {
		return new \WP_Error( 'invalid_job_id', __( 'The job id is too long.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}
	if ( '' === trim( $transcript ) ) {
		return new \WP_Error( 'empty_transcript', __( 'The transcript is empty.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}
	if ( '' === trim( $filename ) ) {
		$filename = __( 'audio recording', 'extrachill-studio' );
	}

	// Reuse an existing draft for this job when one already exists — either
	// because the callback beat us here, or because this is a retry.
	$post_id = ec_studio_transcription_callback_find_draft( $user_id, $job_id );
	if ( $post_id > 0 ) {
		return rest_ensure_response(
			array(
				'post_id'  => $post_id,
				'created'  => false,
				'edit_url' => ec_studio_transcription_draft_review_url(),
			)
		);
	}

	try {
		$post_id = ec_studio_transcription_callback_create_draft( $user_id, $filename, $transcript, $job_id, $stats );
	} catch ( \Throwable $exception ) {
		return new \WP_Error( 'draft_create_failed', $exception->getMessage(), array( 'status' => 503 ) );
	}

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return rest_ensure_response(
		array(
			'post_id'  => (int) $post_id,
			'created'  => true,
			'edit_url' => ec_studio_transcription_draft_review_url(),
		)
	);
}
