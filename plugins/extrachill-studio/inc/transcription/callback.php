<?php
/**
 * Transcription Completion Callback Receiver
 *
 * Endpoint sweatpants POSTs to when an audio-transcription job finishes.
 * The request body is JSON matching the module's yielded result envelope:
 *
 *   {
 *     "job_id": "<uuid>",
 *     "status": "complete",
 *     "files":  { "transcription": "<path>", ... },
 *     "content": { "transcription": "<text>", ... },
 *     "stats":  { "segments": 84, "speakers": null, "duration": 328.88 }
 *   }
 *
 * Authentication uses the same HMAC-signed bearer-token format as
 * sweatpants core's API auth, so we can validate with the existing
 * `wp_native_auth_verify_external_token` primitive against the shared
 * secret stored in the `sweatpants_signed_token_secret` network option.
 *
 * Required scope on the verified token: `callback:write`. Sweatpants
 * mints these via the issuer's `_sign_callback_token` helper (see
 * Extra-Chill/sweatpants-modules:audio-transcription/main.py).
 *
 * Side effects on a verified callback:
 *   1. Create a draft post on main extrachill.com under the uploader's
 *      authorship via the cross-site primitive.
 *   2. Set Studio-specific meta on that draft (model, job_id, etc.).
 *   3. Email the uploader with a link to edit the draft.
 *
 * The route returns 200 on success — that ACK is what tells sweatpants
 * the module can safely delete the upload + output directories.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the callback REST route.
 *
 * @since 0.13.0
 */
function ec_studio_transcription_register_callback_route(): void {
	register_rest_route(
		'extrachill/v1',
		'/transcribe/callback',
		array(
			'methods'             => 'POST',
			'callback'            => 'ec_studio_transcription_handle_callback',
			// We do our own HMAC validation in the callback. Any unauthenticated
			// caller is allowed through to the handler; the handler returns
			// 401 if the bearer token doesn't validate.
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'ec_studio_transcription_register_callback_route' );

/**
 * Handle a verified completion callback from sweatpants.
 *
 * @since 0.13.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_transcription_handle_callback( \WP_REST_Request $request ) {
	// --- Auth ---------------------------------------------------------
	if ( ! function_exists( 'wp_native_auth_verify_external_token' ) ) {
		return new \WP_Error(
			'verifier_unavailable',
			__( 'wp-native-auth is required to validate completion callbacks.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$secret = (string) get_site_option( 'sweatpants_signed_token_secret', '' );
	if ( '' === $secret ) {
		return new \WP_Error(
			'secret_not_configured',
			__( 'Sweatpants signing secret is not configured on this network.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$auth_header = $request->get_header( 'authorization' );
	if ( ! is_string( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
		return new \WP_Error( 'missing_auth', __( 'Missing Bearer token.', 'extrachill-studio' ), array( 'status' => 401 ) );
	}
	$token   = trim( substr( $auth_header, 7 ) );
	$payload = wp_native_auth_verify_external_token( $token, $secret );

	if ( ! is_array( $payload ) ) {
		return new \WP_Error( 'invalid_token', __( 'Invalid or expired callback token.', 'extrachill-studio' ), array( 'status' => 401 ) );
	}

	// --- Scope check --------------------------------------------------
	$scope  = isset( $payload['scope'] ) ? (string) $payload['scope'] : '';
	$scopes = array_filter( preg_split( '/\s+/', $scope ) );
	if ( ! in_array( 'callback:write', $scopes, true ) ) {
		return new \WP_Error( 'forbidden_scope', __( 'Token lacks callback:write scope.', 'extrachill-studio' ), array( 'status' => 403 ) );
	}

	// --- Subject (user_id) --------------------------------------------
	$user_id = isset( $payload['sub'] ) ? (int) $payload['sub'] : 0;
	if ( $user_id <= 0 ) {
		return new \WP_Error( 'invalid_subject', __( 'Callback token has no usable subject claim.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return new \WP_Error( 'unknown_user', __( 'Callback subject does not match a known user.', 'extrachill-studio' ), array( 'status' => 404 ) );
	}

	// --- Payload ------------------------------------------------------
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		return new \WP_Error( 'invalid_body', __( 'Callback body must be JSON.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$job_id     = isset( $body['job_id'] ) ? (string) $body['job_id'] : '';
	$status     = isset( $body['status'] ) ? (string) $body['status'] : '';
	$content    = isset( $body['content'] ) && is_array( $body['content'] ) ? $body['content'] : array();
	$stats      = isset( $body['stats'] ) && is_array( $body['stats'] ) ? $body['stats'] : array();
	$transcript = isset( $content['transcription'] ) ? (string) $content['transcription'] : '';
	$job_id     = trim( $job_id );

	if ( 'complete' !== $status ) {
		// We only act on success callbacks for v1. Failure handling is a
		// separate code path the React tab still surfaces via its in-flight
		// status polling.
		return rest_ensure_response(
			array(
				'received' => true,
				'action'   => 'skipped_non_complete_status',
				'status'   => $status,
			)
		);
	}

	if ( '' === trim( $transcript ) ) {
		return new \WP_Error( 'empty_transcript', __( 'Callback content has no transcription text.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}
	if ( '' === $job_id ) {
		return new \WP_Error( 'missing_job_id', __( 'Callback content has no job id.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}
	if ( strlen( $job_id ) > 191 ) {
		return new \WP_Error( 'invalid_job_id', __( 'Callback job id is too long.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$claim = ec_studio_transcription_callback_claim( $user_id, $job_id );
	if ( is_wp_error( $claim ) ) {
		return $claim;
	}
	if ( ! empty( $claim['duplicate'] ) ) {
		return rest_ensure_response( ec_studio_transcription_callback_result( $claim['state'] ) );
	}

	$receipt_key = (string) $claim['key'];
	$state       = $claim['state'];

	// --- Filename (from job_id metadata if we have it, else best-effort) ---
	$filename = ec_studio_transcription_callback_pick_filename( $body );

	// --- Create draft on main extrachill.com --------------------------
	$post_id = (int) $state['post_id'];
	if ( $post_id <= 0 ) {
		$post_id = ec_studio_transcription_callback_find_draft( $user_id, $job_id );
	}
	if ( $post_id <= 0 ) {
		try {
			$post_id = ec_studio_transcription_callback_create_draft( $user_id, $filename, $transcript, $job_id, $stats );
		} catch ( \Throwable $exception ) {
			$post_id = new \WP_Error( 'draft_create_failed', $exception->getMessage(), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $post_id ) ) {
			ec_studio_transcription_callback_release( $receipt_key, $state );
			return $post_id;
		}
	}

	if ( (int) $state['post_id'] <= 0 ) {
		$next_state            = $state;
		$next_state['post_id'] = (int) $post_id;
		$saved                 = ec_studio_transcription_callback_store( $receipt_key, $state, $next_state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$state = $next_state;
	}

	// Emit studio_transcription_run (extrachill-users#127 shared contract).
	// The acting user the analytics table records is 0 here (unauthenticated
	// HMAC callback), so the uploader's id is passed in the payload.
	if ( empty( $state['analytics_event_id'] ) ) {
		try {
			$event_id = function_exists( 'ec_studio_emit_team_experience_event' )
				? ec_studio_emit_team_experience_event(
					EC_ANALYTICS_EVENT_STUDIO_TRANSCRIPTION_RUN,
					$user_id,
					array(
						'post_id'  => (int) $post_id,
						'job_id'   => $job_id,
						'segments' => isset( $stats['segments'] ) ? (int) $stats['segments'] : 0,
					)
				)
				: 0;
		} catch ( \Throwable $exception ) {
			$event_id = 0;
		}
		if ( $event_id <= 0 ) {
			ec_studio_transcription_callback_release( $receipt_key, $state );
			return new \WP_Error( 'analytics_emit_failed', __( 'The transcription draft was created, but analytics could not be recorded. Retry the callback.', 'extrachill-studio' ), array( 'status' => 503 ) );
		}

		$next_state                       = $state;
		$next_state['analytics_event_id'] = (int) $event_id;
		$saved                            = ec_studio_transcription_callback_store( $receipt_key, $state, $next_state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$state = $next_state;
	}

	// --- Email the user -----------------------------------------------
	if ( empty( $state['email_sent'] ) ) {
		try {
			$email_sent = ec_studio_transcription_callback_send_email( $user, $post_id, $filename, $transcript, $stats );
		} catch ( \Throwable $exception ) {
			$email_sent = false;
		}
		if ( ! $email_sent ) {
			ec_studio_transcription_callback_release( $receipt_key, $state );
			return new \WP_Error( 'notification_failed', __( 'The transcription draft was created, but its notification could not be sent. Retry the callback.', 'extrachill-studio' ), array( 'status' => 503 ) );
		}

		$next_state               = $state;
		$next_state['email_sent'] = true;
		$saved                    = ec_studio_transcription_callback_store( $receipt_key, $state, $next_state );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$state = $next_state;
	}

	$complete_state           = $state;
	$complete_state['status'] = 'complete';
	$saved                    = ec_studio_transcription_callback_store( $receipt_key, $state, $complete_state );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return rest_ensure_response( ec_studio_transcription_callback_result( $complete_state ) );
}

/**
 * Atomically claim a callback receipt stored in main's uniquely keyed options table.
 *
 * @param int    $user_id Callback subject.
 * @param string $job_id  Provider job id.
 * @return array|\WP_Error Claim details or an error.
 */
function ec_studio_transcription_callback_claim( int $user_id, string $job_id ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id <= 0 ) {
		return new \WP_Error( 'main_site_unavailable', __( 'The main site could not be resolved.', 'extrachill-studio' ), array( 'status' => 500 ) );
	}

	$key   = '_ec_studio_transcription_' . hash( 'sha256', $user_id . "\0" . $job_id );
	$owner = wp_generate_uuid4();
	$now   = time();
	$state = array(
		'subject'            => $user_id,
		'job_id'             => $job_id,
		'status'             => 'processing',
		'owner'              => $owner,
		'updated_at'         => $now,
		'post_id'            => 0,
		'analytics_event_id' => 0,
		'email_sent'         => false,
	);

	switch_to_blog( $main_blog_id );
	try {
		if ( add_option( $key, $state, '', false ) ) {
			return array( 'key' => $key, 'state' => $state, 'duplicate' => false );
		}
		$existing = get_option( $key, null );
	} finally {
		restore_current_blog();
	}

	if ( ! is_array( $existing ) || (int) ( $existing['subject'] ?? 0 ) !== $user_id || (string) ( $existing['job_id'] ?? '' ) !== $job_id ) {
		return new \WP_Error( 'callback_receipt_invalid', __( 'The existing callback receipt is invalid.', 'extrachill-studio' ), array( 'status' => 500 ) );
	}
	if ( 'complete' === ( $existing['status'] ?? '' ) ) {
		return array( 'key' => $key, 'state' => $existing, 'duplicate' => true );
	}
	if ( 'processing' === ( $existing['status'] ?? '' ) && $now - (int) ( $existing['updated_at'] ?? 0 ) < 300 ) {
		return new \WP_Error( 'callback_in_progress', __( 'This callback is already being processed. Retry shortly.', 'extrachill-studio' ), array( 'status' => 409 ) );
	}

	$state               = $existing;
	$state['status']     = 'processing';
	$state['owner']      = $owner;
	$state['updated_at'] = $now;
	$claimed             = ec_studio_transcription_callback_replace( $main_blog_id, $key, $existing, $state );
	if ( ! $claimed ) {
		return new \WP_Error( 'callback_in_progress', __( 'This callback was claimed by another request. Retry shortly.', 'extrachill-studio' ), array( 'status' => 409 ) );
	}

	return array( 'key' => $key, 'state' => $state, 'duplicate' => false );
}

/**
 * Persist an owned callback receipt with compare-and-swap semantics.
 *
 * @param string $key      Option key.
 * @param array  $current  Expected state.
 * @param array  $next     Replacement state.
 * @return true|\WP_Error
 */
function ec_studio_transcription_callback_store( string $key, array $current, array &$next ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	$next['owner']      = (string) ( $current['owner'] ?? '' );
	$next['updated_at'] = time();
	if ( $main_blog_id <= 0 || ! ec_studio_transcription_callback_replace( $main_blog_id, $key, $current, $next ) ) {
		return new \WP_Error( 'callback_claim_lost', __( 'Callback ownership changed while processing. Retry shortly.', 'extrachill-studio' ), array( 'status' => 409 ) );
	}
	return true;
}

/**
 * Release an owned receipt after a known failure so a retry can resume it.
 *
 * @param string $key   Option key.
 * @param array  $state Current state.
 */
function ec_studio_transcription_callback_release( string $key, array $state ): void {
	$retry_state               = $state;
	$retry_state['status']     = 'retryable';
	$retry_state['owner']      = '';
	$retry_state['updated_at'] = time();
	$main_blog_id              = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id > 0 ) {
		ec_studio_transcription_callback_replace( $main_blog_id, $key, $state, $retry_state );
	}
}

/**
 * Replace one exact option value, preventing stale workers from overwriting a new owner.
 *
 * @param int    $blog_id Blog containing the receipt.
 * @param string $key     Option key.
 * @param array  $current Expected state.
 * @param array  $next    Replacement state.
 * @return bool
 */
function ec_studio_transcription_callback_replace( int $blog_id, string $key, array $current, array $next ): bool {
	global $wpdb;

	switch_to_blog( $blog_id );
	try {
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value CAS is required to transfer callback ownership safely.
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $next ) ),
			array(
				'option_name'  => $key,
				'option_value' => maybe_serialize( $current ),
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 === (int) $updated ) {
			wp_cache_delete( $key, 'options' );
			return true;
		}
		return false;
	} finally {
		restore_current_blog();
	}
}

/**
 * Build the stable success response persisted by a callback receipt.
 *
 * @param array $state Callback receipt.
 * @return array
 */
function ec_studio_transcription_callback_result( array $state ): array {
	return array(
		'received'   => true,
		'action'     => 'draft_created',
		'job_id'     => (string) $state['job_id'],
		'post_id'    => (int) $state['post_id'],
		'user_id'    => (int) $state['subject'],
		'email_sent' => ! empty( $state['email_sent'] ),
	);
}

/**
 * Resolve a post already stamped for this callback subject and job.
 *
 * This closes the recovery window where WordPress created and stamped the post
 * before the callback receipt could persist its post ID.
 *
 * @param int    $user_id Callback subject and post author.
 * @param string $job_id  Provider job id.
 * @return int Existing post ID, or zero.
 */
function ec_studio_transcription_callback_find_draft( int $user_id, string $job_id ): int {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id <= 0 ) {
		return 0;
	}

	switch_to_blog( $main_blog_id );
	try {
		$posts = get_posts(
			array(
				'author'         => $user_id,
				'fields'         => 'ids',
				'meta_key'       => '_studio_transcription_job_id',
				'meta_value'     => $job_id,
				'no_found_rows'  => true,
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);
		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	} finally {
		restore_current_blog();
	}
}

/**
 * Best-effort extraction of the original audio filename from the callback body.
 *
 * Sweatpants doesn't pass the original filename explicitly in the payload, but
 * the `files.transcription` path typically ends in `<basename>.whisper.txt`.
 * We strip the `.whisper.txt` suffix to reconstruct what the uploader saw.
 *
 * @since 0.13.0
 *
 * @param array $body Full callback body.
 * @return string Filename without path, with a sensible fallback.
 */
function ec_studio_transcription_callback_pick_filename( array $body ): string {
	$candidate = '';

	if ( isset( $body['files']['transcription'] ) && is_string( $body['files']['transcription'] ) ) {
		$basename = wp_basename( $body['files']['transcription'] );
		// `foo.m4a.whisper.txt` → strip `.whisper.txt` → `foo.m4a`
		if ( str_ends_with( $basename, '.whisper.txt' ) ) {
			$candidate = substr( $basename, 0, -strlen( '.whisper.txt' ) );
		} else {
			$candidate = $basename;
		}
	}

	if ( '' === $candidate ) {
		$candidate = sprintf( 'recording-%s', isset( $body['job_id'] ) ? substr( (string) $body['job_id'], 0, 8 ) : 'unknown' );
	}

	return $candidate;
}

/**
 * Create the draft on main extrachill.com under the uploader's authorship.
 *
 * Uses the universal cross-site REST primitive from extrachill-multisite
 * v1.12.3+. Studio-specific post meta is set in a follow-up
 * `switch_to_blog` because `/wp/v2/posts`'s `meta` field only accepts keys
 * registered with `show_in_rest`.
 *
 * @since 0.13.0
 *
 * @param int    $user_id     Author user id.
 * @param string $filename    Original recording filename, used in the title.
 * @param string $transcript  Plain-text transcript content.
 * @param string $job_id      Sweatpants job UUID.
 * @param array  $stats       Stats array from the callback body.
 * @return int|\WP_Error Post id on success, WP_Error otherwise.
 */
function ec_studio_transcription_callback_create_draft(
	int $user_id,
	string $filename,
	string $transcript,
	string $job_id,
	array $stats
) {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site draft creation.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$title = sprintf(
		/* translators: %s: source recording filename */
		__( 'Transcription: %s', 'extrachill-studio' ),
		$filename
	);

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts',
		array(
			'body'    => array(
				'title'   => $title,
				'status'  => 'draft',
				'author'  => $user_id,
				'content' => $transcript,
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

	// Attach Studio-specific meta on the main site.
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id > 0 ) {
		// Switch is for post-context resolution, NOT for SMTP: the draft
		// lives on main extrachill.com, so update_post_meta() must run in
		// main's blog context to hit the correct postmeta table. SMTP
		// routing for outgoing mail is handled separately by ec_send_email()
		// via the mail_site_id input.
		switch_to_blog( $main_blog_id );
		try {
			update_post_meta( $post_id, '_studio_source_filename', $filename );
			update_post_meta( $post_id, '_studio_transcription_job_id', $job_id );
			update_post_meta( $post_id, '_studio_transcription_segments', isset( $stats['segments'] ) ? (int) $stats['segments'] : 0 );
			update_post_meta( $post_id, '_studio_transcription_duration_sec', isset( $stats['duration'] ) ? (float) $stats['duration'] : 0 );
			update_post_meta( $post_id, '_studio_transcription_has_speakers', ! empty( $stats['speakers'] ) ? '1' : '0' );
		} finally {
			restore_current_blog();
		}
	}

	return $post_id;
}

/**
 * Send the "your transcription is ready" email to the uploader.
 *
 * Delegates mail dispatch to `ec_send_email()` (extrachill-multisite),
 * which wraps the `datamachine/send-email` ability. The branded shell
 * (`extrachill/branded`) owns the document chrome, greeting, CTA
 * button, and footer; this function only assembles the transcription-
 * specific inner body and context.
 *
 * @since 0.13.0
 * @since X.Y.Z Mail dispatch delegated to ec_send_email(); SMTP context
 *              is auto-resolved by the ability via mail_site_id
 *              (`ec_mail_site_id()`), retiring the manual
 *              `switch_to_blog( ec_get_blog_id('main') )` workaround
 *              previously needed because Easy WP SMTP stores its config
 *              per-site. The only remaining switch in this function is
 *              for resolving the post's edit URL in main's context — the
 *              draft lives there — which is unrelated to SMTP.
 *
 * @param \WP_User $user        Recipient.
 * @param int      $post_id     Draft post id on main.
 * @param string   $filename    Original recording filename.
 * @param string   $transcript  Plain-text transcript content.
 * @param array    $stats       Stats array from the callback body.
 * @return bool True if the ability reports a successful send.
 */
function ec_studio_transcription_callback_send_email(
	\WP_User $user,
	int $post_id,
	string $filename,
	string $transcript,
	array $stats
): bool {
	if ( ! function_exists( 'ec_send_email' ) ) {
		return false;
	}

	$duration_sec = isset( $stats['duration'] ) ? (float) $stats['duration'] : 0;
	$segments     = isset( $stats['segments'] ) ? (int) $stats['segments'] : 0;
	$has_speakers = ! empty( $stats['speakers'] );

	$preview_chars = 400;
	$preview       = '';
	$plain         = trim( wp_strip_all_tags( $transcript ) );
	if ( $plain ) {
		$preview = mb_substr( $plain, 0, $preview_chars );
		if ( mb_strlen( $plain ) > $preview_chars ) {
			$preview .= '…';
		}
	}

	$subject = sprintf(
		/* translators: %s: original recording filename */
		__( 'Your transcription is ready: %s', 'extrachill-studio' ),
		$filename
	);

	// Resolve the draft's edit URL in the context of the site that owns
	// the post (main extrachill.com). This switch is NOT for SMTP —
	// SMTP routing is now handled inside ec_send_email() via the
	// mail_site_id input. get_edit_post_link() needs main's blog
	// context to read the post and build a correct admin URL because
	// the draft was created on main via ec_cross_site_rest_request().
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	$edit_url     = '';
	if ( $main_blog_id > 0 ) {
		switch_to_blog( $main_blog_id );
		try {
			$edit_url = (string) get_edit_post_link( $post_id, 'raw' );
		} finally {
			restore_current_blog();
		}
	}

	$body_html = ec_studio_transcription_render_completion_email(
		array(
			'filename'     => $filename,
			'duration_sec' => $duration_sec,
			'segments'     => $segments,
			'has_speakers' => $has_speakers,
			'preview'      => $preview,
		)
	);

	$result = ec_send_email(
		array(
			'to'       => $user->user_email,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'recipient_name' => $user->display_name ? $user->display_name : $user->user_login,
				'preheader'      => __( 'Your transcription is ready', 'extrachill-studio' ),
				'body_html'      => $body_html,
				'cta_url'        => $edit_url,
				'cta_label'      => __( 'Review draft', 'extrachill-studio' ),
			),
		)
	);

	return is_array( $result ) && ! empty( $result['success'] );
}
