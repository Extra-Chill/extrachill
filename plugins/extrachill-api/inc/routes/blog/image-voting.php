<?php
/**
 * REST route: GET /wp-json/extrachill/v1/blog/image-voting/vote-count/{post_id}/{instance_id}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_blog_image_voting_routes' );

function extrachill_api_register_blog_image_voting_routes() {
    register_rest_route( 'extrachill/v1', '/blog/image-voting/vote-count/(?P<post_id>\d+)/(?P<instance_id>[a-zA-Z0-9\-]+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'extrachill_api_blog_image_voting_get_vote_count',
        'permission_callback' => '__return_true',
        'args'                => array(
            'post_id'    => array(
                'validate_callback' => 'is_numeric',
            ),
            'instance_id' => array(
                'validate_callback' => 'is_string',
            ),
        ),
    ) );
}

function extrachill_api_blog_image_voting_get_vote_count( WP_REST_Request $request ) {
    $post_id     = absint( $request->get_param( 'post_id' ) );
    $instance_id = sanitize_text_field( $request->get_param( 'instance_id' ) );

    if ( ! $post_id ) {
        return new WP_Error( 'invalid_post', 'Invalid post ID', array( 'status' => 400 ) );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'post_not_found', 'Post not found', array( 'status' => 404 ) );
    }

    $blocks     = parse_blocks( $post->post_content );
    $vote_count = 0;

    foreach ( $blocks as $block ) {
        if ( 'extrachill/image-voting' !== ( $block['blockName'] ?? '' ) ) {
            continue;
        }

        if ( isset( $block['attrs']['uniqueBlockId'] ) && $block['attrs']['uniqueBlockId'] === $instance_id ) {
            $vote_count = isset( $block['attrs']['voteCount'] ) ? (int) $block['attrs']['voteCount'] : 0;
            break;
        }
    }

    return rest_ensure_response( array( 'vote_count' => $vote_count ) );
}
