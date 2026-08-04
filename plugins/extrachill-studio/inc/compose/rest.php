<?php
/**
 * Compose REST Proxy — cross-site bridge for the Studio Blog tab.
 *
 * The Studio Compose pane is an internal team tool that drafts blog articles
 * for editorial review and publishing on MAIN extrachill.com (blog 1). Studio
 * itself lives on blog 12. If the compose pane wrote posts to the local Studio
 * site, every submitted article would be stranded where no editor ever looks
 * (see Extra-Chill/extrachill-studio#75).
 *
 * Rather than draft on Studio and migrate to main later — which means
 * re-homing attachments, rewriting image URLs/IDs in block content, and
 * recreating terms — posts are BORN ON MAIN from the very first draft. Studio
 * stays a pure tool surface; the post lives on blog 1 its entire lifecycle
 * (draft → pending → publish).
 *
 * The compose pane is a frontend React/TS app, so it cannot call the PHP
 * `ec_cross_site_rest_request()` primitive directly. These thin Studio-local
 * REST routes are the server-side bridge: each one proxies to main via
 * `ec_cross_site_rest_request( 'main', ... )` (extrachill-multisite), which is
 * in-process by default (switch_to_blog + rest_do_request — no HTTP loopback)
 * and runs under the requesting user's auth, respecting WP core caps.
 *
 * Routes (all under the team-gated permission check):
 *   - GET  /extrachill/v1/studio/compose/posts                 List the user's drafts on main.
 *   - POST /extrachill/v1/studio/compose/posts                 Create a draft on main.
 *   - GET  /extrachill/v1/studio/compose/posts/<id>            Fetch a post from main.
 *   - POST /extrachill/v1/studio/compose/posts/<id>            Update / submit-for-review on main.
 *   - GET  /extrachill/v1/studio/compose/posts/<id>/autosaves  List autosaves for a draft on main.
 *   - POST /extrachill/v1/studio/compose/posts/<id>/autosaves  Autosave a draft on main (status omitted).
 *   - GET  /extrachill/v1/studio/compose/media                 Browse main's media library (inserter grid).
 *   - GET  /extrachill/v1/studio/compose/media/<id>            Fetch a single attachment on main.
 *   - POST /extrachill/v1/studio/compose/media                 Upload an image into main's media library.
 *
 * The frontend installs an apiFetch middleware that rewrites the compose
 * pane's `/wp/v2/posts*` and `/wp/v2/media*` calls onto these proxy routes,
 * which also captures the block editor's own inline media uploads so inserted
 * images land in main's library — not Studio's — avoiding the very migration
 * problem #75 set out to remove.
 *
 * Two dispatch strategies, by necessity:
 *   - JSON write/list routes (posts, autosaves, single-media GET) forward via
 *     `ec_cross_site_rest_request( 'main', ... )` and guard on that helper
 *     being available (`ec_studio_compose_require_cross_site()`).
 *   - Header-sensitive reads and file routes (single-post GET, media upload,
 *     media browse) do their own `switch_to_blog( main )`: the post read must
 *     preserve core response headers, a multipart `$_FILES` upload can't ride
 *     the in-process rest_do_request cleanly, and the browse grid needs the
 *     core controller's X-WP-Total pagination headers. These guard on
 *     `ec_get_blog_id()` instead.
 *
 * @package    ExtraChillStudio
 * @subpackage Compose
 * @since      0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST namespace and route prefix for the compose proxy.
 */
const EC_STUDIO_COMPOSE_NAMESPACE = 'extrachill/v1';
const EC_STUDIO_COMPOSE_ROUTE     = 'studio/compose';

/**
 * Register the compose proxy REST routes.
 *
 * @since 0.16.0
 *
 * @return void
 */
function ec_studio_compose_register_routes(): void {
	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_drafts',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_create_post',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_get_post',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_update_post',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts/(?P<id>\d+)/autosaves',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_autosaves',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_create_autosave',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/media',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_media',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_upload_media',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/media/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_get_media_item',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ec_studio_compose_register_routes' );

/**
 * Header the compose editor attaches to every write it originates, so the
 * apiFetch middleware rewrites it onto the compose proxy (→ main). Its
 * presence on a LOCAL core `/wp/v2/posts|media` write means the client-side
 * rewrite missed — see {@link ec_studio_compose_block_stranded_local_writes}.
 * Kept in sync with COMPOSE_MARKER_HEADER in cross-site-middleware.ts.
 */
const EC_STUDIO_COMPOSE_MARKER_HEADER = 'X-EC-Studio-Compose';

/**
 * Server-side backstop: never let a compose-originated write land on the
 * Studio subsite.
 *
 * Born-on-main was previously enforced ONLY in the browser (a racy apiFetch
 * middleware). When the rewrite missed, the write hit Studio-local
 * `/wp/v2/posts|media` and silently created the post/attachment on blog 12 —
 * exactly the stranded submission of #106, undetectable because nothing on the
 * server objected.
 *
 * This guard closes that hole. The compose editor tags every write it
 * originates with the `X-EC-Studio-Compose` header. A correctly-rewritten
 * request reaches the compose PROXY route (`/extrachill/v1/studio/compose/*`)
 * and is forwarded to main; it never carries this header into a core
 * `/wp/v2/*` route. So a core `/wp/v2/posts` or `/wp/v2/media` WRITE that
 * arrives on the Studio subsite CARRYING this marker is a proven routing miss:
 * we reject it with a clear error the client surfaces, instead of silently
 * committing to blog 12. A compose write is therefore provably on main or it
 * fails loudly — it can never silently strand again.
 *
 * Scope is deliberately narrow so legitimate Studio-local writes are never
 * touched:
 *   - Only runs on the Studio subsite (never on main).
 *   - Only WRITE methods (POST/PUT/PATCH) to the core posts/media collections
 *     and single-item routes.
 *   - Only when the compose marker header is present. Socials' local drafts
 *     carry the `X-EC-Studio-Local` marker and never the compose one, so they
 *     pass straight through.
 *
 * @since 0.20.1
 *
 * @param mixed            $result  Pre-dispatch short-circuit (null to continue).
 * @param \WP_REST_Server  $server  REST server instance.
 * @param \WP_REST_Request $request The request being dispatched.
 * @return mixed Null to continue dispatch, or a WP_Error to reject.
 */
function ec_studio_compose_block_stranded_local_writes( $result, $server, $request ) {
	// Already short-circuited by another handler — don't interfere.
	if ( null !== $result ) {
		return $result;
	}

	// Only guard the Studio subsite. On main these routes are the correct
	// destination, so the marker (which the proxy strips anyway) is harmless.
	if ( function_exists( 'ec_get_blog_id' ) ) {
		$main_blog_id = (int) ec_get_blog_id( 'main' );
		if ( $main_blog_id > 0 && (int) get_current_blog_id() === $main_blog_id ) {
			return $result;
		}
	}

	// Only writes can strand content; reads are harmless.
	$method = strtoupper( (string) $request->get_method() );
	if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		return $result;
	}

	// Only a compose-originated request should ever carry this marker.
	if ( '' === (string) $request->get_header( EC_STUDIO_COMPOSE_MARKER_HEADER ) ) {
		return $result;
	}

	// Only the core posts/media routes strand content on blog 12. Match the
	// collection and single-item routes; the compose proxy routes are a
	// different namespace and never reach here.
	$route                  = (string) $request->get_route();
	$is_core_posts_or_media = (bool) preg_match(
		'#^/wp/v2/(posts|media)(/\d+)?(/autosaves)?$#',
		$route
	);
	if ( ! $is_core_posts_or_media ) {
		return $result;
	}

	// Make the rejection observable. A single blocked request is a caught
	// stranding (good), but a RECURRING pattern means the client-side rewrite
	// is systematically failing — that must be visible to an operator, not
	// silently absorbed per-request. Record it on a persistent counter (with
	// last-seen context) and emit a gated error_log line. See #110.
	ec_studio_compose_record_guard_rejection( $method, $route );

	return new \WP_Error(
		'ec_studio_compose_stranded_local_write',
		__( 'This post must be created on the main site, but the request was routed to Studio. Nothing was saved. Please reload the Compose tab and try again — if it keeps happening, contact an admin.', 'extrachill-studio' ),
		array( 'status' => 409 )
	);
}
add_filter( 'rest_pre_dispatch', 'ec_studio_compose_block_stranded_local_writes', 10, 3 );

/**
 * Option key holding an observability record of guard rejections.
 *
 * Shape: {
 *   count        int     Total rejections since first seen.
 *   first_seen   string  ISO-8601 UTC of the first rejection.
 *   last_seen    string  ISO-8601 UTC of the most recent rejection.
 *   last_user_id int     User whose write was most recently blocked.
 *   last_method  string  HTTP method of the most recent blocked write.
 *   last_route   string  Core route of the most recent blocked write.
 * }
 */
const EC_STUDIO_COMPOSE_GUARD_REJECTIONS_OPTION = 'ec_studio_compose_guard_rejections';

/**
 * Record a stranded-local-write guard rejection for observability.
 *
 * The #107 guard rejects a compose-marked local write per-request, which stops
 * the stranding but says nothing to an operator. A one-off is expected noise;
 * a recurring count is a real, actionable signal that the born-on-main routing
 * is systematically missing. This persists a small running record (autoloaded
 * option, updated in place) and emits a gated error_log line so the failure is
 * surfaced in logs and can be read back by the CLI report (see #110).
 *
 * @since 0.20.1
 *
 * @param string $method HTTP method of the blocked write.
 * @param string $route  Core route the blocked write targeted.
 * @return void
 */
function ec_studio_compose_record_guard_rejection( string $method, string $route ): void {
	$now     = gmdate( 'c' );
	$user_id = (int) get_current_user_id();

	$record = get_option( EC_STUDIO_COMPOSE_GUARD_REJECTIONS_OPTION, array() );
	if ( ! is_array( $record ) ) {
		$record = array();
	}

	$record = array(
		'count'        => isset( $record['count'] ) ? ( (int) $record['count'] + 1 ) : 1,
		'first_seen'   => ! empty( $record['first_seen'] ) ? (string) $record['first_seen'] : $now,
		'last_seen'    => $now,
		'last_user_id' => $user_id,
		'last_method'  => $method,
		'last_route'   => $route,
	);

	update_option( EC_STUDIO_COMPOSE_GUARD_REJECTIONS_OPTION, $record, true );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated observability log for a proven routing miss (#110).
			sprintf(
				'[extrachill-studio] Compose born-on-main guard rejected a stranded local write (count=%d): %s %s by user %d. The client-side rewrite is missing — investigate if this recurs.',
				(int) $record['count'],
				$method,
				$route,
				$user_id
			)
		);
	}
}

/**
 * Permission check for the compose proxy routes.
 *
 * Mirrors the team-gating used by the compose editor surface itself
 * (compose-editor.php / render.php): the caller must be a logged-in
 * administrator or Extra Chill team member. The actual write on main is
 * still performed under the user's own auth via the cross-site primitive,
 * so main's core capability checks apply a second time.
 *
 * @since 0.16.0
 *
 * @return true|\WP_Error True when allowed, WP_Error otherwise.
 */
function ec_studio_compose_permission_check() {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'You must be logged in to use the Studio compose tools.', 'extrachill-studio' ),
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can( 'manage_options' ) && ( ! function_exists( 'ec_is_team_member' ) || ! ec_is_team_member() ) ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Studio is available to Extra Chill team members only.', 'extrachill-studio' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Guard that extrachill-multisite's cross-site primitive is available.
 *
 * @since 0.16.0
 *
 * @return true|\WP_Error True when available, WP_Error otherwise.
 */
function ec_studio_compose_require_cross_site() {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site compose.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	return true;
}

/**
 * Normalize a cross-site response into a REST response, preserving WP_Error.
 *
 * @since 0.16.0
 *
 * @param array|\WP_Error|\WP_REST_Response $response Cross-site request result.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_relay_response( $response ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return rest_ensure_response( $response );
}

/**
 * List the current user's drafts on main extrachill.com.
 *
 * Author-scoped so users with edit_others_posts (editors, admins) only see
 * their own Studio drafts in the compose picker — matching the frontend's
 * author filter intent.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_drafts( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required REST callback signature.
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();

	$query = array(
		'status'   => 'draft',
		'per_page' => 20,
		'orderby'  => 'modified',
		'order'    => 'desc',
		'context'  => 'edit',
		'author'   => $user_id,
	);

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/posts',
		array(
			'query'   => $query,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Create a post on main extrachill.com.
 *
 * Used for the initial draft create and for explicit "Save Draft" /
 * "Submit for Review" actions when no post id exists yet. The post is born on
 * main under the requesting user's authorship.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_create_post( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$params  = ec_studio_compose_whitelist_post_params( $request->get_json_params() );

	// Always attribute the post to the requesting user on main.
	$params['author'] = $user_id;

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts',
		array(
			'body'    => $params,
			'user_id' => $user_id,
		)
	);

	// Stamp Studio-submission provenance meta on the main-site post. This is
	// the detectability half of the born-on-main guarantee (#106): every
	// compose-created post carries an identifying marker on main, so a
	// submission that SHOULD be on main but is missing it (stranded on blog 12)
	// becomes observable instead of silent.
	ec_studio_compose_stamp_origin_meta( $response, $user_id );

	// On a successful create, emit a draft-created or submitted-for-review
	// event (extrachill-users#127 shared contract). A brand-new compose post
	// defaults to draft when status is omitted.
	ec_studio_compose_emit_lifecycle_event(
		$response,
		isset( $params['status'] ) ? (string) $params['status'] : 'draft',
		$user_id,
		true
	);
	ec_studio_compose_schedule_editor_notification(
		$response,
		isset( $params['status'] ) ? (string) $params['status'] : 'draft',
		$user_id
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Fetch an existing post from main extrachill.com.
 *
 * Dispatches through main's native posts controller so context=edit and
 * per-post read/edit permissions are enforced by WordPress core. Returning the
 * native response preserves its status and headers; native errors are relayed
 * as WP_Error like the other compose proxy routes.
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_get_post( \WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site compose.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return new \WP_Error(
			'main_blog_unresolved',
			__( 'Could not resolve the main site for compose.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$post_id = (int) $request['id'];
	$query   = $request->get_query_params();
	$query   = array_intersect_key(
		$query,
		array_flip( array( 'context', 'password', 'excerpt_length', '_fields', '_embed' ) )
	);
	$result  = null;

	switch_to_blog( $main_blog_id );
	try {
		$sub_request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$sub_request->set_query_params( $query );
		$sub_request->add_header( 'X-EC-Forwarded', '1' );

		$response = rest_do_request( $sub_request );
		$result   = $response->is_error()
			? $response->as_error()
			: ec_studio_compose_relay_response( $response );
	} finally {
		restore_current_blog();
	}

	return $result;
}

/**
 * Update an existing post on main extrachill.com.
 *
 * Handles "Update Draft" (status=draft) and "Submit for Review"
 * (status=pending). The status the frontend sends is forwarded verbatim —
 * the proxy does not demote or override it.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_update_post( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];
	$params  = ec_studio_compose_whitelist_post_params( $request->get_json_params() );

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts/' . $post_id,
		array(
			'body'    => $params,
			'user_id' => $user_id,
		)
	);

	// Ensure the submission-provenance meta is present on main. Idempotent:
	// a compose post created before this marker existed, or one whose create
	// stamp somehow did not land, is back-filled here on its next compose
	// write (e.g. Submit-for-Review). See #106.
	ec_studio_compose_stamp_origin_meta( $response, $user_id );

	// On a successful update, emit submitted-for-review when the writer
	// transitions the post to pending (extrachill-users#127 shared contract).
	// Plain draft updates are intentionally NOT counted as draft-created —
	// that event fires once, at create.
	ec_studio_compose_emit_lifecycle_event(
		$response,
		isset( $params['status'] ) ? (string) $params['status'] : '',
		$user_id,
		false
	);
	ec_studio_compose_schedule_editor_notification(
		$response,
		isset( $params['status'] ) ? (string) $params['status'] : '',
		$user_id
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Emit a Studio compose lifecycle analytics event for a successful write.
 *
 * Maps the resolved post status to the shared-contract event type:
 *   - status 'pending' → studio_submitted_for_review (create or update)
 *   - status 'draft'   → studio_draft_created (create only)
 *
 * No-op when the cross-site write errored or returned no post id, so we
 * never count a failed save.
 *
 * @since 0.17.0
 *
 * @param array|\WP_Error $response  Cross-site write result.
 * @param string          $status    Resolved post status that was written.
 * @param int             $user_id   Acting/subject user id.
 * @param bool            $is_create Whether this was a create (vs update).
 * @return void
 */
function ec_studio_compose_emit_lifecycle_event( $response, string $status, int $user_id, bool $is_create ): void {
	if ( ! function_exists( 'ec_studio_emit_team_experience_event' ) ) {
		return;
	}

	if ( is_wp_error( $response ) ) {
		return;
	}

	$post_id = isset( $response['id'] ) ? (int) $response['id'] : 0;
	if ( $post_id <= 0 ) {
		return;
	}

	if ( 'pending' === $status ) {
		ec_studio_emit_team_experience_event(
			EC_ANALYTICS_EVENT_STUDIO_SUBMITTED,
			$user_id,
			array( 'post_id' => $post_id )
		);
		return;
	}

	if ( $is_create && 'draft' === $status ) {
		ec_studio_emit_team_experience_event(
			EC_ANALYTICS_EVENT_STUDIO_DRAFT_CREATED,
			$user_id,
			array( 'post_id' => $post_id )
		);
	}
}

/**
 * Schedule the main site's editorial-owner notification in a trusted worker.
 *
 * Studio submissions run under contributor credentials, while the mail ability
 * is intentionally restricted to administrators and background workers. A
 * unique Action Scheduler action crosses that boundary without elevating the
 * submitting request. The delivery receipt still provides the final dedupe.
 *
 * @param array|\WP_Error $response Cross-site write result.
 * @param string          $status   Resolved post status that was written.
 * @param int             $user_id  Submitting user ID.
 * @return void
 */
function ec_studio_compose_schedule_editor_notification( $response, string $status, int $user_id ): void {
	if ( 'pending' !== $status || is_wp_error( $response ) ) {
		return;
	}

	$post_id = isset( $response['id'] ) ? (int) $response['id'] : 0;
	if ( $post_id <= 0 ) {
		return;
	}

	ec_studio_schedule_editor_notification( $post_id, $user_id );
}

/**
 * Enqueue one unique background delivery for a pending editorial submission.
 *
 * @param int $post_id Post awaiting review on main.
 * @param int $user_id Submitting user ID.
 * @return bool True when delivery was queued.
 */
function ec_studio_schedule_editor_notification( int $post_id, int $user_id ): bool {
	if ( $post_id <= 0 || $user_id <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
		return false;
	}

	try {
		$action_id = as_enqueue_async_action(
			'ec_studio_deliver_editor_notification',
			array( $post_id, $user_id ),
			'extrachill-studio-email',
			true
		);
	} catch ( \Throwable $exception ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
			sprintf( '[extrachill-studio] Failed to schedule the editor notification for pending post %1$d: %2$s', $post_id, $exception->getMessage() )
		);
		return false;
	}

	return (int) $action_id > 0;
}

/**
 * Create an idempotent editorial-review notification for one pending post.
 *
 * Kept separate from the REST response adapter so the review-queue recovery
 * scan can repair notifications for writes that bypassed the Compose proxy.
 *
 * @param int $post_id Post awaiting review on main.
 * @param int $user_id Submitting user ID.
 * @return bool True when the notification exists or was inserted.
 */
function ec_studio_notify_editor_for_post( int $post_id, int $user_id ): bool {
	if ( $post_id <= 0 || $user_id <= 0 || ! function_exists( 'ec_users_notify_with_receipts' ) || ! function_exists( 'ec_get_blog_id' ) ) {
		return false;
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return false;
	}

	$recipient_id = 0;
	$recipient    = null;
	$author_name  = '';
	$post_title   = '';
	$title        = '';
	$link         = '';

	switch_to_blog( $main_blog_id );
	try {
		$admin = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( ! $admin instanceof \WP_User || ! user_can( $admin, 'edit_others_posts' ) ) {
			$editors = get_users(
				array(
					'capability' => 'edit_others_posts',
					'orderby'    => 'ID',
					'order'      => 'ASC',
				)
			);
			$admin   = null;
			foreach ( $editors as $editor ) {
				if ( $editor instanceof \WP_User && user_can( $editor, 'edit_others_posts' ) ) {
					$admin = $editor;
					break;
				}
			}
		}
		$post = get_post( $post_id );
		if ( $admin instanceof \WP_User && $post instanceof \WP_Post && 'pending' === $post->post_status && user_can( $admin, 'edit_others_posts' ) ) {
			$recipient_id = (int) $admin->ID;
			$recipient    = $admin;
			$author_name  = (string) get_the_author_meta( 'display_name', $user_id );
			$post_title   = (string) get_the_title( $post );
			$link         = (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' );
			$title        = sprintf(
				/* translators: 1: author name, 2: submitted post title. */
				__( 'New post submitted for review by %1$s: %2$s', 'extrachill-studio' ),
				$author_name,
				$post_title
			);
		}
	} finally {
		restore_current_blog();
	}

	if ( $recipient_id <= 0 || ! $recipient instanceof \WP_User || '' === $link || '' === $title ) {
		return false;
	}

	$producer        = 'extrachill-studio/editorial-review';
	$idempotency_key = 'submitted:' . $post_id;
	$owns_email      = is_email( $recipient->user_email ) && function_exists( 'ec_users_release_notification_receipt' ) && function_exists( 'ec_send_email_queued' );
	$receipt         = ec_users_notify_with_receipts(
		array( $recipient_id ),
		array(
			'actor_id'            => $user_id,
			'type'                => 'editorial_submission',
			'link'                => $link,
			'title'               => $title,
			'item_id'             => $post_id,
			'producer'            => $producer,
			'idempotency_key'     => $idempotency_key,
			'producer_owns_email' => $owns_email,
		)
	);

	$recipient_receipt = is_array( $receipt['recipients'][ $recipient_id ] ?? null ) ? $receipt['recipients'][ $recipient_id ] : array();
	$status            = (string) ( $recipient_receipt['status'] ?? 'failed' );
	$notification_id   = (int) ( $recipient_receipt['notification_id'] ?? 0 );
	if ( 'existing' === $status ) {
		return true;
	}
	if ( 'inserted' !== $status || $notification_id <= 0 ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated observability for a failed editor handoff.
				sprintf( '[extrachill-studio] Failed to claim the editor notification for pending post %d.', $post_id )
			);
		}
		return false;
	}
	if ( ! $owns_email ) {
		return true;
	}

	$subject = sprintf(
		/* translators: %s: submitted post title. */
		__( 'New post submitted for review: %s', 'extrachill-studio' ),
		$post_title
	);
	$body_html = '<p>' . sprintf(
		/* translators: 1: author name, 2: submitted post title. */
		esc_html__( '%1$s submitted “%2$s” for editorial review.', 'extrachill-studio' ),
		esc_html( $author_name ),
		esc_html( $post_title )
	) . '</p><p>' . esc_html__( 'The draft is ready to review on Extra Chill.', 'extrachill-studio' ) . '</p>';
	try {
		$queue = ec_send_email_queued(
			array(
				'to'       => $recipient->user_email,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => array(
					'subject_html'   => esc_html( $subject ),
					'recipient_name' => $recipient->display_name,
					'body_html'      => $body_html,
					'cta_url'        => $link,
					'cta_label'      => __( 'Review submission', 'extrachill-studio' ),
					'preheader'      => __( 'A new post is ready for editorial review.', 'extrachill-studio' ),
				),
			)
		);
	} catch ( \Throwable $exception ) {
		$queue = array(
			'success' => false,
			'error'   => $exception->getMessage(),
		);
	}
	$queued = ! empty( $queue['success'] );
	if ( ! $queued ) {
		$released = ec_users_release_notification_receipt( $notification_id, $recipient_id, $producer, $idempotency_key );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Canonical operational logging surface.
			sprintf(
				'[extrachill-studio] Failed to queue the editor email for pending post %1$d; receipt released: %2$s.',
				$post_id,
				$released ? 'yes' : 'no'
			)
		);
		return false;
	}

	return true;
}
add_action( 'ec_studio_deliver_editor_notification', 'ec_studio_notify_editor_for_post', 10, 2 );

/**
 * Post-meta key stamped on a main-site post created via the Studio compose
 * proxy. Value is an array: { user_id, submitted_at (ISO-8601 UTC), source }.
 */
const EC_STUDIO_SUBMISSION_META = '_ec_studio_submission';

/**
 * Post-meta key recording the blog id the compose write originated from
 * (always the Studio subsite). Aids detecting a stranded/mis-homed submission.
 */
const EC_STUDIO_ORIGIN_BLOG_META = '_ec_studio_origin_blog';

/**
 * Stamp Studio-submission provenance meta on the MAIN-site post.
 *
 * The born-on-main guarantee (#106) needs more than routing — it needs
 * *provenance* and *detectability*. Every post the compose proxy writes gets
 * an identifying marker on main so that:
 *   - editors/tools can query for Studio submissions, and
 *   - a submission that should be on main but is missing the marker (i.e.
 *     stranded on the Studio subsite) is observable instead of silent.
 *
 * The marker is server-authored provenance, not user input, and is set
 * directly under `switch_to_blog( main )` via update_post_meta — it does NOT
 * rely on the main-site post type registering these keys for REST, so it works
 * regardless of which plugins are active on main. Idempotent: `submitted_at`
 * is only written once (first stamp wins) so re-stamping on a later update
 * does not rewrite the original submission time; `source` and origin-blog are
 * refreshed harmlessly.
 *
 * No-op when the cross-site write errored or returned no post id.
 *
 * @since 0.20.1
 *
 * @param array|\WP_Error $response Cross-site write result.
 * @param int             $user_id  Acting/subject user id.
 * @return void
 */
function ec_studio_compose_stamp_origin_meta( $response, int $user_id ): void {
	if ( is_wp_error( $response ) ) {
		return;
	}

	// After the WP_Error guard, a successful cross-site write is an array.
	$post_id = isset( $response['id'] ) ? (int) $response['id'] : 0;
	if ( $post_id <= 0 ) {
		return;
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return;
	}

	$origin_blog_id = (int) get_current_blog_id();

	switch_to_blog( $main_blog_id );
	try {
		$existing = get_post_meta( $post_id, EC_STUDIO_SUBMISSION_META, true );

		// Preserve the original submitted_at on re-stamp (first stamp wins).
		$submitted_at = is_array( $existing ) && ! empty( $existing['submitted_at'] )
			? (string) $existing['submitted_at']
			: gmdate( 'c' );

		update_post_meta(
			$post_id,
			EC_STUDIO_SUBMISSION_META,
			array(
				'user_id'      => $user_id,
				'submitted_at' => $submitted_at,
				'source'       => 'studio-compose',
			)
		);

		update_post_meta( $post_id, EC_STUDIO_ORIGIN_BLOG_META, $origin_blog_id );
	} finally {
		restore_current_blog();
	}
}

/**
 * List autosaves for a draft on main extrachill.com.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_autosaves( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/posts/' . $post_id . '/autosaves',
		array(
			'query'   => array( 'context' => 'edit' ),
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Create an autosave for a draft on main extrachill.com.
 *
 * Deliberately omits `status` so the parent post's status is preserved — an
 * out-of-band transition to pending/publish on main is never demoted back to
 * draft by an in-flight autosave. This mirrors the frontend autosave
 * contract.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_create_autosave( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];

	// Only title/content are forwarded — status is intentionally never sent on
	// autosave (see docblock). Title/content are passed through unaltered:
	// main's /wp/v2/posts autosave controller is the single sanitization
	// authority and expects unslashed input, which get_json_params() returns.
	// Pre-sanitizing here would double-process and mangle legitimate
	// characters (e.g. backslashes), so we don't.
	$raw  = $request->get_json_params();
	$body = array();
	if ( isset( $raw['title'] ) ) {
		$body['title'] = (string) $raw['title'];
	}
	if ( isset( $raw['content'] ) ) {
		$body['content'] = (string) $raw['content'];
	}

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts/' . $post_id . '/autosaves',
		array(
			'body'    => $body,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Sanitize the post params accepted from the compose pane.
 *
 * Whitelists the small set of fields the compose pane sends — title, content,
 * and status — so the proxy never forwards arbitrary REST fields cross-site.
 * Editorial metadata remains the responsibility of editors working in
 * wp-admin.
 *
 * Title and content are passed through unaltered. Main's `/wp/v2/posts`
 * controller is the single sanitization authority: it sanitizes the title via
 * its schema and sanitizes post HTML with the author's capabilities (post caps
 * are re-checked on main under the user's own auth). It expects UNSLASHED
 * input, which `get_json_params()` returns — REST JSON bodies are not slashed.
 * Pre-sanitizing or unslashing here would double-process and corrupt
 * legitimate characters (e.g. backslashes), so we don't.
 *
 * `status` is policy-gated against the lifecycle states the compose pane may
 * set (draft, pending) so the tool can never push a post straight to publish.
 *
 * @since 0.16.0
 *
 * @param mixed $raw Raw JSON params.
 * @return array Whitelisted post params.
 */
function ec_studio_compose_whitelist_post_params( $raw ): array {
	$params = array();

	if ( ! is_array( $raw ) ) {
		return $params;
	}

	if ( isset( $raw['title'] ) ) {
		$params['title'] = (string) $raw['title'];
	}

	if ( isset( $raw['content'] ) ) {
		$params['content'] = (string) $raw['content'];
	}

	if ( isset( $raw['status'] ) ) {
		$status = sanitize_key( (string) $raw['status'] );
		if ( in_array( $status, array( 'draft', 'pending' ), true ) ) {
			$params['status'] = $status;
		}
	}

	return $params;
}

/**
 * Browse main extrachill.com's media library for the inserter grid.
 *
 * The block editor's Media Library tab does `GET /wp/v2/media` (with search,
 * pagination, and mime-type filters) to browse existing uploads. The frontend
 * middleware rewrites that here so writers see and re-insert images from
 * MAIN's library (blog 1) — consistent with where uploads land — instead of
 * Studio's (blog 12) library.
 *
 * All query params are forwarded verbatim so the editor's search, paging, and
 * media_type/mime_type filters work unchanged. The X-WP-Total /
 * X-WP-TotalPages headers the inserter relies on for pagination are emitted by
 * the core media controller during the in-process dispatch and surfaced here.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_media( \WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site media browse.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$query = $request->get_query_params();

	// The core-data media grid reads X-WP-Total / X-WP-TotalPages headers off
	// the raw response for pagination (apiFetch parse:false). The generic
	// cross-site helper returns only the decoded body and strips those
	// headers, so we dispatch in-process here and re-emit the pagination
	// headers onto our own WP_REST_Response.
	return ec_studio_compose_dispatch_media_list_on_main( $query );
}

/**
 * Dispatch a media-list query on main and return a paginated REST response.
 *
 * Runs `GET /wp/v2/media` inside `switch_to_blog( main )` via rest_do_request
 * so we can read the core controller's X-WP-Total / X-WP-TotalPages headers
 * and forward them — the inserter grid relies on them for pagination.
 *
 * @since 0.16.0
 *
 * @param array $query Query params forwarded from the editor.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_dispatch_media_list_on_main( array $query ) {
	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return new \WP_Error(
			'main_blog_unresolved',
			__( 'Could not resolve the main site for media browse.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$result = null;

	switch_to_blog( $main_blog_id );
	try {
		$sub = new \WP_REST_Request( 'GET', '/wp/v2/media' );
		$sub->set_query_params( $query );
		$sub->add_header( 'X-EC-Forwarded', '1' );

		$sub_response = rest_do_request( $sub );

		if ( $sub_response->is_error() ) {
			$error  = $sub_response->as_error();
			$data   = $error->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$result = new \WP_Error(
				$error->get_error_code() ? $error->get_error_code() : 'ec_cross_site_error',
				$error->get_error_message() ? $error->get_error_message() : __( 'Cross-site media browse failed.', 'extrachill-studio' ),
				array( 'status' => $status )
			);
		} else {
			$response = rest_ensure_response( $sub_response->get_data() );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			// Forward the pagination headers the grid reads via parse:false.
			$headers = $sub_response->get_headers();
			foreach ( array( 'X-WP-Total', 'X-WP-TotalPages' ) as $header ) {
				if ( isset( $headers[ $header ] ) ) {
					$response->header( $header, (string) $headers[ $header ] );
				}
			}
			$result = $response;
		}
	} finally {
		restore_current_blog();
	}

	return $result;
}

/**
 * Fetch a single attachment from main extrachill.com.
 *
 * The editor fetches `/wp/v2/media/<id>` to hydrate an attachment after
 * insertion or when re-resolving a block's media. Forwarded to main so the
 * blog-1 attachment is returned.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_get_media_item( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id       = (int) get_current_user_id();
	$attachment_id = (int) $request['id'];
	$query         = $request->get_query_params();

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/media/' . $attachment_id,
		array(
			'query'   => $query,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Upload an inserted editor image into main extrachill.com's media library.
 *
 * The block editor's media-utils `uploadMedia()` POSTs files to
 * `/wp/v2/media` via apiFetch. The frontend middleware rewrites that onto
 * this route so the attachment is created on MAIN (blog 1) — not Studio
 * (blog 12). If it landed on Studio, the block content would reference
 * blog-12 attachment URLs/IDs and recreate the cross-site migration problem
 * #75 set out to eliminate.
 *
 * The multipart upload (`$_FILES`) does not survive an in-process
 * rest_do_request dispatch cleanly, so this handler performs the upload
 * directly inside `switch_to_blog( main )` — the same approach
 * extrachill-api uses for product images that must land on the shop site.
 * The response is shaped like core's `/wp/v2/media` create response
 * (`prepare_item_for_response`) so the editor consumes it unchanged.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_upload_media( \WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site media upload.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$files = $request->get_file_params(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This authenticated REST route has a team-member permission callback; uploaded file fields are validated below.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This authenticated REST route has a team-member permission callback; uploaded file fields are validated below.
	if ( empty( $files['file'] ) && isset( $_FILES['file'] ) ) {
		$files['file'] = $_FILES['file']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST cookie nonce already validated by the auth layer; file params are validated below.
	}

	if ( empty( $files['file']['name'] ) ) {
		return new \WP_Error(
			'no_file',
			__( 'No file was uploaded.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$uploaded_file = $files['file'];

	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	$file_type     = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );

	if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
		return new \WP_Error(
			'invalid_file_type',
			__( "That file isn't a supported image. Please upload a JPG, PNG, GIF, or WebP image.", 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return new \WP_Error(
			'main_blog_unresolved',
			__( 'Could not resolve the main site for media upload.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	// Enforce the size limit against MAIN's allowance — that's where the file
	// is actually written. Surface a clear, actionable message (file size +
	// limit + what to do) rather than letting wp_handle_upload fail opaquely.
	// This is the server-side backstop; the editor also validates client-side
	// before the upload starts (see the maxUploadFileSize editor setting),
	// which catches the common case (and absurdly large files) without a
	// round trip.
	$size_error = ec_studio_compose_check_upload_size( $uploaded_file, $main_blog_id );
	if ( is_wp_error( $size_error ) ) {
		return $size_error;
	}

	$user_id = (int) get_current_user_id();

	// Optional caption/alt/title forwarded from the editor upload form.
	$caption = $request->get_param( 'caption' );
	$alt     = $request->get_param( 'alt_text' );
	$title   = $request->get_param( 'title' );

	$result = null;

	switch_to_blog( $main_blog_id );
	try {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_result = wp_handle_upload( $uploaded_file, array( 'test_form' => false ) );

		if ( ! $upload_result || isset( $upload_result['error'] ) ) {
			$result = new \WP_Error(
				'upload_failed',
				isset( $upload_result['error'] ) ? $upload_result['error'] : __( 'Upload failed.', 'extrachill-studio' ),
				array( 'status' => 500 )
			);
		} else {
			$attachment = array(
				'guid'           => $upload_result['url'],
				'post_author'    => $user_id,
				'post_mime_type' => $upload_result['type'],
				'post_title'     => $title ? sanitize_text_field( $title ) : preg_replace( '/\.[^.]+$/', '', basename( $upload_result['file'] ) ),
				'post_content'   => '',
				'post_excerpt'   => $caption ? sanitize_textarea_field( $caption ) : '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload_result['file'], 0, true );

			if ( is_wp_error( $attachment_id ) ) {
				$result = $attachment_id;
			} else {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload_result['file'] );
				wp_update_attachment_metadata( $attachment_id, $attach_data );

				if ( $alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
				}

				$result = ec_studio_compose_prepare_media_response( $attachment_id );
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Validate an uploaded file against main extrachill.com's upload size limit.
 *
 * The image is written to MAIN (blog 1), so the limit that matters is main's
 * `wp_max_upload_size()` — not Studio's. Returns a WP_Error with a clear,
 * non-technical, actionable message (naming the file's size and the limit, and
 * telling the writer to resize/compress) when the file is too large.
 *
 * Note: files larger than the platform's edge/proxy limits (Cloudflare,
 * nginx) are rejected before the request ever reaches PHP — the editor's
 * client-side check is the first and primary line of defense against those.
 * This server-side guard catches the in-between band and any non-editor
 * caller, and guarantees a friendly JSON error instead of an opaque
 * wp_handle_upload failure.
 *
 * @since 0.16.0
 *
 * @param array $uploaded_file The $_FILES entry for the upload.
 * @param int   $main_blog_id  Resolved main blog id.
 * @return true|\WP_Error True when within limits, WP_Error otherwise.
 */
function ec_studio_compose_check_upload_size( array $uploaded_file, int $main_blog_id ) {
	$file_size = isset( $uploaded_file['size'] ) ? (int) $uploaded_file['size'] : 0;

	switch_to_blog( $main_blog_id );
	$max_size = (int) wp_max_upload_size();
	restore_current_blog();

	if ( $max_size <= 0 || $file_size <= $max_size ) {
		return true;
	}

	return new \WP_Error(
		'file_too_large',
		sprintf(
			/* translators: 1: the uploaded file's size (e.g. "40 MB"), 2: the maximum allowed size (e.g. "2 MB"). */
			__( 'That image is too large (%1$s). The maximum upload size is %2$s — please resize or compress the image and try again.', 'extrachill-studio' ),
			size_format( $file_size ),
			size_format( $max_size )
		),
		array( 'status' => 413 )
	);
}

/**
 * Build a `/wp/v2/media`-shaped response for a freshly uploaded attachment.
 *
 * Delegates to the core media REST controller's prepare method so the block
 * editor receives exactly the shape it expects from a normal media upload.
 * Must be called inside the target blog context.
 *
 * @since 0.16.0
 *
 * @param int $attachment_id Attachment id on the current (switched) blog.
 * @return array Prepared media response data.
 */
function ec_studio_compose_prepare_media_response( int $attachment_id ): array {
	$controller = new \WP_REST_Attachments_Controller( 'attachment' );
	$request    = new \WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id );
	$request->set_param( 'context', 'edit' );

	$response = $controller->prepare_item_for_response( get_post( $attachment_id ), $request );

	$data = $response->get_data();

	return is_array( $data ) ? $data : array( 'id' => $attachment_id );
}
