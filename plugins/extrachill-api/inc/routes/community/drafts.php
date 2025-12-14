<?php
/**
 * REST routes: bbPress drafts
 *
 * Endpoints:
 * - GET    /wp-json/extrachill/v1/community/drafts
 * - POST   /wp-json/extrachill/v1/community/drafts
 * - DELETE /wp-json/extrachill/v1/community/drafts
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_community_drafts_routes' );

function extrachill_api_register_community_drafts_routes() {
    register_rest_route(
        'extrachill/v1',
        '/community/drafts',
        [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'extrachill_api_community_drafts_get_handler',
                'permission_callback' => 'extrachill_api_community_drafts_permission',
                'args'                => extrachill_api_community_drafts_context_args(),
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'extrachill_api_community_drafts_post_handler',
                'permission_callback' => 'extrachill_api_community_drafts_permission',
                'args'                => array_merge(
                    extrachill_api_community_drafts_context_args(),
                    [
                        'title'   => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'content' => [
                            'required' => false,
                            'type'     => 'string',
                        ],
                    ]
                ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => 'extrachill_api_community_drafts_delete_handler',
                'permission_callback' => 'extrachill_api_community_drafts_permission',
                'args'                => extrachill_api_community_drafts_context_args(),
            ],
        ]
    );
}

function extrachill_api_community_drafts_permission() {
    return is_user_logged_in();
}

function extrachill_api_community_drafts_sanitize_int( $value, $request, $param ) {
    return is_numeric( $value ) ? (int) $value : 0;
}

function extrachill_api_community_drafts_context_args() {
    return [
        'type'    => [
            'required'          => true,
            'type'              => 'string',
            'enum'              => [ 'topic', 'reply' ],
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'forum_id' => [
            'required'          => false,
            'type'              => 'integer',
            'sanitize_callback' => 'extrachill_api_community_drafts_sanitize_int',
            'validate_callback' => function( $param ) {
                return is_numeric( $param ) && (int) $param >= 0;
            },
        ],
        'topic_id' => [
            'required'          => false,
            'type'              => 'integer',
            'sanitize_callback' => 'extrachill_api_community_drafts_sanitize_int',
            'validate_callback' => function( $param ) {
                return is_numeric( $param ) && (int) $param >= 0;
            },
        ],
        'reply_to' => [
            'required'          => false,
            'type'              => 'integer',
            'sanitize_callback' => 'extrachill_api_community_drafts_sanitize_int',
            'validate_callback' => function( $param ) {
                return is_numeric( $param ) && (int) $param >= 0;
            },
        ],
        'prefer_unassigned' => [
            'required'          => false,
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        ],
    ];
}

function extrachill_api_community_drafts_validate_context( WP_REST_Request $request ) {
    $type = $request->get_param( 'type' );
    $forum_id = (int) $request->get_param( 'forum_id' );
    $topic_id = (int) $request->get_param( 'topic_id' );

    if ( 'topic' === $type ) {
        if ( ! $request->has_param( 'forum_id' ) ) {
            return new WP_Error( 'missing_forum_id', 'forum_id is required for topic drafts.', [ 'status' => 400 ] );
        }

        if ( $forum_id < 0 ) {
            return new WP_Error( 'invalid_forum_id', 'forum_id must be >= 0.', [ 'status' => 400 ] );
        }

        return true;
    }

    if ( 'reply' === $type ) {
        if ( $topic_id <= 0 ) {
            return new WP_Error( 'invalid_topic_id', 'topic_id is required for reply drafts.', [ 'status' => 400 ] );
        }

        return true;
    }

    return new WP_Error( 'invalid_type', 'Invalid type.', [ 'status' => 400 ] );
}

function extrachill_api_community_drafts_get_context_from_request( WP_REST_Request $request ) {
    return [
        'type'    => (string) $request->get_param( 'type' ),
        'blog_id' => (int) get_current_blog_id(),
        'forum_id' => (int) $request->get_param( 'forum_id' ),
        'topic_id' => (int) $request->get_param( 'topic_id' ),
        'reply_to' => (int) $request->get_param( 'reply_to' ),
    ];
}

function extrachill_api_community_drafts_get_handler( WP_REST_Request $request ) {
    $valid = extrachill_api_community_drafts_validate_context( $request );
    if ( true !== $valid ) {
        return $valid;
    }

    if ( ! function_exists( 'extrachill_api_bbpress_draft_get' ) ) {
        return new WP_Error( 'missing_helpers', 'Draft utilities not loaded.', [ 'status' => 500 ] );
    }

    $context = extrachill_api_community_drafts_get_context_from_request( $request );
    $user_id = get_current_user_id();

    $prefer_unassigned = (bool) $request->get_param( 'prefer_unassigned' );

    $draft = extrachill_api_bbpress_draft_get( $user_id, $context );

    if ( null === $draft && 'topic' === $context['type'] && $prefer_unassigned && $context['forum_id'] > 0 ) {
        $fallback_context = $context;
        $fallback_context['forum_id'] = 0;
        $draft = extrachill_api_bbpress_draft_get( $user_id, $fallback_context );
    }

    return rest_ensure_response( [ 'draft' => $draft ] );
}

function extrachill_api_community_drafts_post_handler( WP_REST_Request $request ) {
    $valid = extrachill_api_community_drafts_validate_context( $request );
    if ( true !== $valid ) {
        return $valid;
    }

    if ( ! function_exists( 'extrachill_api_bbpress_draft_upsert' ) ) {
        return new WP_Error( 'missing_helpers', 'Draft utilities not loaded.', [ 'status' => 500 ] );
    }

    $context = extrachill_api_community_drafts_get_context_from_request( $request );

    $title = (string) $request->get_param( 'title' );
    $content = $request->get_param( 'content' );
    $content = is_string( $content ) ? wp_unslash( $content ) : '';

    $draft = [
        'type'    => $context['type'],
        'blog_id' => $context['blog_id'],
        'forum_id' => $context['forum_id'],
        'topic_id' => $context['topic_id'],
        'reply_to' => $context['reply_to'],
        'title'   => $title,
        'content' => $content,
    ];

    $saved = extrachill_api_bbpress_draft_upsert( get_current_user_id(), $draft );

    return rest_ensure_response( [
        'saved' => true,
        'draft' => $saved,
    ] );
}

function extrachill_api_community_drafts_delete_handler( WP_REST_Request $request ) {
    $valid = extrachill_api_community_drafts_validate_context( $request );
    if ( true !== $valid ) {
        return $valid;
    }

    if ( ! function_exists( 'extrachill_api_bbpress_draft_delete' ) ) {
        return new WP_Error( 'missing_helpers', 'Draft utilities not loaded.', [ 'status' => 500 ] );
    }

    $context = extrachill_api_community_drafts_get_context_from_request( $request );
    $deleted = extrachill_api_bbpress_draft_delete( get_current_user_id(), $context );

    return rest_ensure_response( [ 'deleted' => (bool) $deleted ] );
}
