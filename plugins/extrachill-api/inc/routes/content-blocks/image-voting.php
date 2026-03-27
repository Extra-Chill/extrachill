<?php
/**
 * REST route: GET /wp-json/extrachill/v1/content-blocks/image-voting/vote-count/{post_id}/{instance_id}
 *
 * Image voting count endpoint. Self-contained — no plugin dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_content_blocks_image_voting_route' );

function extrachill_api_register_content_blocks_image_voting_route() {
	register_rest_route( 'extrachill/v1', '/content-blocks/image-voting/vote-count/(?P<post_id>\d+)/(?P<instance_id>[a-zA-Z0-9\-]+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_content_blocks_image_voting_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'post_id'     => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'instance_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

function extrachill_api_content_blocks_image_voting_handler( $request ) {
	$post_id     = $request->get_param( 'post_id' );
	$instance_id = $request->get_param( 'instance_id' );

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error(
			'post_not_found',
			'Post not found',
			array( 'status' => 404 )
		);
	}

	$blocks     = parse_blocks( $post->post_content );
	$vote_count = 0;

	foreach ( $blocks as $block ) {
		if ( 'extrachill/image-voting' === $block['blockName'] ) {
			$block_id = $block['attrs']['uniqueBlockId'] ?? '';
			if ( $block_id === $instance_id ) {
				$vote_count = intval( $block['attrs']['voteCount'] ?? 0 );
				break;
			}
		}
	}

	return rest_ensure_response( array(
		'vote_count' => $vote_count,
	) );
}
