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
 *   - JSON routes (posts, autosaves, single-media GET) forward via
 *     `ec_cross_site_rest_request( 'main', ... )` and guard on that helper
 *     being available (`ec_studio_compose_require_cross_site()`).
 *   - File/header-sensitive routes (media upload, media browse) do their own
 *     `switch_to_blog( main )`: a multipart `$_FILES` upload can't ride the
 *     in-process rest_do_request cleanly, and the browse grid needs the
 *     core controller's X-WP-Total pagination headers, which the cross-site
 *     helper strips. These guard on `ec_get_blog_id()` instead.
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

	/*
	 * Editorial-taxonomy term pickers. The compose UI reads/searches (GET) and
	 * creates (POST) terms on MAIN's category/artist/venue/location taxonomies
	 * so a submission reaches editorial review-ready. `<taxonomy>` is a slug
	 * from the allow-list in ec_studio_compose_resolve_term_taxonomy(); free
	 * tags (post_tag) are intentionally NOT proxied (see #108).
	 */
	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/terms/(?P<taxonomy>[a-z_]+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_terms',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'taxonomy' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_create_term',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'taxonomy' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
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

	return new \WP_Error(
		'ec_studio_compose_stranded_local_write',
		__( 'This post must be created on the main site, but the request was routed to Studio. Nothing was saved. Please reload the Compose tab and try again — if it keeps happening, contact an admin.', 'extrachill-studio' ),
		array( 'status' => 409 )
	);
}
add_filter( 'rest_pre_dispatch', 'ec_studio_compose_block_stranded_local_writes', 10, 3 );

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

	if ( ! current_user_can( 'manage_options' ) && function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
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
 * @param array|\WP_Error $response Cross-site request result.
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
function ec_studio_compose_list_drafts( \WP_REST_Request $request ) {
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

	return ec_studio_compose_relay_response( $response );
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

	$post_id = is_array( $response ) && isset( $response['id'] ) ? (int) $response['id'] : 0;
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
	if ( is_array( $raw ) ) {
		if ( isset( $raw['title'] ) ) {
			$body['title'] = (string) $raw['title'];
		}
		if ( isset( $raw['content'] ) ) {
			$body['content'] = (string) $raw['content'];
		}
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
 * Editorial taxonomies the compose pane is allowed to set on the main post,
 * keyed by the REST field name main's `/wp/v2/posts` controller expects (each
 * taxonomy's `rest_base`). Each accepts an array of term IDs.
 *
 * Deliberately excludes `post_tag`: Extra Chill does NOT use free-form tags
 * (see Extra-Chill/extrachill-studio#108). Only the platform taxonomies a
 * concert recap actually lives on are forwarded.
 */
const EC_STUDIO_COMPOSE_TERM_FIELDS = array( 'categories', 'artist', 'venue', 'location' );

/**
 * Sanitize the post params accepted from the compose pane.
 *
 * Whitelists the small set of fields the compose pane sends — title, content,
 * status, featured image, and the editorial taxonomies — so the proxy never
 * forwards arbitrary REST fields cross-site.
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
 * `featured_media` and the term fields (categories/artist/venue/location) are
 * coerced to their expected numeric shapes so a malformed client payload can't
 * reach main's controller as garbage; main's schema still validates that the
 * IDs resolve to real attachments/terms under the user's own auth. Because
 * every write is forwarded to main, the featured image and terms land on the
 * MAIN post (blog 1) — never on the Studio subsite (blog 12).
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

	// Featured image — a single attachment id on main. 0 clears it.
	if ( isset( $raw['featured_media'] ) ) {
		$params['featured_media'] = absint( $raw['featured_media'] );
	}

	/*
	 * Editorial taxonomies — arrays of term ids on main. An explicit empty
	 * array is forwarded so the writer can clear a taxonomy.
	 */
	foreach ( EC_STUDIO_COMPOSE_TERM_FIELDS as $field ) {
		if ( ! isset( $raw[ $field ] ) ) {
			continue;
		}
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) $raw[ $field ] )
				)
			)
		);
		$params[ $field ] = $ids;
	}

	return $params;
}

/**
 * Map a compose term-picker taxonomy slug to main's REST base.
 *
 * The compose UI addresses taxonomies by their slug (category/artist/venue/
 * location). Main's `/wp/v2/*` term routes are keyed by each taxonomy's
 * `rest_base`, which differs for the core category taxonomy (`categories`).
 * This allow-list is the single gate on which taxonomies the picker can touch
 * — anything not listed (notably `post_tag`) is rejected, so the tool can
 * never write free tags (see Extra-Chill/extrachill-studio#108).
 *
 * @since 0.21.0
 *
 * @param string $taxonomy Taxonomy slug from the route.
 * @return string|null Main's REST base, or null when not allowed.
 */
function ec_studio_compose_resolve_term_taxonomy( string $taxonomy ): ?string {
	$allowed = array(
		'category' => 'categories',
		'artist'   => 'artist',
		'venue'    => 'venue',
		'location' => 'location',
	);

	return $allowed[ $taxonomy ] ?? null;
}

/**
 * List / search terms in one of main's editorial taxonomies.
 *
 * Proxies `GET /wp/v2/<rest_base>` on main so the compose term pickers read
 * and autocomplete against MAIN's category/artist/venue/location terms — the
 * same place the forwarded post write assigns them. Query params (search,
 * per_page, include, orderby) are forwarded verbatim so the picker can search
 * and hydrate selected terms by id.
 *
 * @since 0.21.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_terms( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$rest_base = ec_studio_compose_resolve_term_taxonomy( (string) $request['taxonomy'] );
	if ( null === $rest_base ) {
		return new \WP_Error(
			'ec_studio_compose_bad_taxonomy',
			__( 'That taxonomy is not available in Studio.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$user_id = (int) get_current_user_id();
	$query   = $request->get_query_params();
	$query   = is_array( $query ) ? $query : array();

	// Sane default page size for a picker; the client may override.
	if ( ! isset( $query['per_page'] ) ) {
		$query['per_page'] = 20;
	}

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/' . $rest_base,
		array(
			'query'   => $query,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Create a term in one of main's editorial taxonomies.
 *
 * Proxies `POST /wp/v2/<rest_base>` on main so a writer can add a missing
 * artist/venue/location/category from the compose picker. The term is created
 * on MAIN (blog 1) under the user's own auth, so main's create-term capability
 * check applies. Only `name` and (for hierarchical category) `parent` are
 * forwarded.
 *
 * @since 0.21.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_create_term( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$rest_base = ec_studio_compose_resolve_term_taxonomy( (string) $request['taxonomy'] );
	if ( null === $rest_base ) {
		return new \WP_Error(
			'ec_studio_compose_bad_taxonomy',
			__( 'That taxonomy is not available in Studio.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$raw  = $request->get_json_params();
	$name = is_array( $raw ) && isset( $raw['name'] ) ? trim( (string) $raw['name'] ) : '';
	if ( '' === $name ) {
		return new \WP_Error(
			'ec_studio_compose_term_name_required',
			__( 'Enter a name to create this term.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$body = array( 'name' => $name );
	if ( 'categories' === $rest_base && is_array( $raw ) && isset( $raw['parent'] ) ) {
		$body['parent'] = absint( $raw['parent'] );
	}

	$user_id = (int) get_current_user_id();

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/' . $rest_base,
		array(
			'body'    => $body,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
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
	return ec_studio_compose_dispatch_media_list_on_main( is_array( $query ) ? $query : array() );
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
			'query'   => is_array( $query ) ? $query : array(),
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

	$files = $request->get_file_params();
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

	if ( is_wp_error( $response ) ) {
		return array( 'id' => $attachment_id );
	}

	$data = $response->get_data();

	return is_array( $data ) ? $data : array( 'id' => $attachment_id );
}
