<?php
/**
 * REST route: GET /wp-json/extrachill/v1/object
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_object_routes' );

function extrachill_api_register_object_routes() {
	register_rest_route( 'extrachill/v1', '/object', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'extrachill_api_object_get_handler',
		'permission_callback' => 'extrachill_api_object_permission_check',
		'args'                => array(
			'object_type' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'blog_id'      => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'id'           => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}

function extrachill_api_object_permission_check( WP_REST_Request $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', 'Must be logged in.', array( 'status' => 401 ) );
	}

	$blog_id   = absint( $request->get_param( 'blog_id' ) );
	$object_id = $request->get_param( 'id' );

	if ( ! $blog_id || '' === (string) $object_id ) {
		return new WP_Error( 'invalid_params', 'blog_id and id are required.', array( 'status' => 400 ) );
	}

	return true;
}

function extrachill_api_object_get_handler( WP_REST_Request $request ) {
	$object_type = $request->get_param( 'object_type' );
	$blog_id     = absint( $request->get_param( 'blog_id' ) );
	$object_id   = $request->get_param( 'id' );

	if ( ! $blog_id ) {
		return new WP_Error( 'invalid_blog_id', 'blog_id is required.', array( 'status' => 400 ) );
	}

	if ( '' === (string) $object_id ) {
		return new WP_Error( 'invalid_id', 'id is required.', array( 'status' => 400 ) );
	}

	$switched = false;

	try {
		$switched = switch_to_blog( $blog_id );
		if ( ! $switched ) {
			return new WP_Error( 'invalid_blog_id', 'Invalid blog_id.', array( 'status' => 400 ) );
		}

		switch ( $object_type ) {
			case 'post':
				$post_id = absint( $object_id );
				if ( ! $post_id ) {
					return new WP_Error( 'invalid_id', 'Invalid post id.', array( 'status' => 400 ) );
				}

				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					$post = get_post( $post_id );
					if ( ! $post ) {
						return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
					}

					if ( 'publish' !== $post->post_status ) {
						return new WP_Error( 'rest_forbidden', 'Post not accessible.', array( 'status' => 403 ) );
					}
				}

				return extrachill_api_object_resolve_post( $post_id );

			case 'comment':
				$comment_id = absint( $object_id );
				if ( ! $comment_id ) {
					return new WP_Error( 'invalid_id', 'Invalid comment id.', array( 'status' => 400 ) );
				}

				$comment = get_comment( $comment_id );
				if ( ! $comment ) {
					return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
				}

				$is_approved = ( '1' === (string) $comment->comment_approved );
				if ( ! $is_approved ) {
					$user_id = get_current_user_id();
					if ( ! $user_id || (int) $comment->user_id !== (int) $user_id ) {
						if ( ! current_user_can( 'edit_comment', $comment_id ) ) {
							return new WP_Error( 'rest_forbidden', 'Comment not accessible.', array( 'status' => 403 ) );
						}
					}
				}

				return extrachill_api_object_resolve_comment( $comment_id );

			case 'artist':
				$artist_id = absint( $object_id );
				if ( ! $artist_id ) {
					return new WP_Error( 'invalid_id', 'Invalid artist id.', array( 'status' => 400 ) );
				}

				if ( ! function_exists( 'ec_can_manage_artist' ) ) {
					return new WP_Error( 'dependency_missing', 'Artist platform not active.', array( 'status' => 500 ) );
				}

				if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
					return new WP_Error( 'rest_forbidden', 'Cannot access this artist.', array( 'status' => 403 ) );
				}

				return extrachill_api_object_resolve_artist( $artist_id );

			default:
				return new WP_Error( 'unsupported_object_type', 'Unsupported object_type.', array( 'status' => 400 ) );
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

function extrachill_api_object_resolve_post( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return new WP_Error( 'invalid_id', 'Invalid post id.', array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	return rest_ensure_response( array(
		'object_type' => 'post',
		'blog_id'     => get_current_blog_id(),
		'id'          => (int) $post_id,
		'post_type'   => $post->post_type,
		'status'      => $post->post_status,
		'title'       => get_the_title( $post ),
		'excerpt'     => get_the_excerpt( $post ),
		'content'     => apply_filters( 'the_content', $post->post_content ),
		'author_id'   => (int) $post->post_author,
		'permalink'   => get_permalink( $post ),
		'date_gmt'    => mysql2date( 'c', $post->post_date_gmt, false ),
	) );
}

function extrachill_api_object_resolve_comment( $comment_id ) {
	$comment_id = absint( $comment_id );
	if ( ! $comment_id ) {
		return new WP_Error( 'invalid_id', 'Invalid comment id.', array( 'status' => 400 ) );
	}

	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return new WP_Error( 'not_found', 'Comment not found.', array( 'status' => 404 ) );
	}

	$post_id = absint( $comment->comment_post_ID );

	return rest_ensure_response( array(
		'object_type' => 'comment',
		'blog_id'     => get_current_blog_id(),
		'id'          => (int) $comment_id,
		'post_id'     => $post_id ? (int) $post_id : null,
		'author_id'   => $comment->user_id ? (int) $comment->user_id : null,
		'content'     => (string) $comment->comment_content,
		'date_gmt'    => mysql2date( 'c', $comment->comment_date_gmt, false ),
	) );
}

function extrachill_api_object_resolve_artist( $artist_id ) {
	$artist_id = absint( $artist_id );
	if ( ! $artist_id ) {
		return new WP_Error( 'invalid_id', 'Invalid artist id.', array( 'status' => 400 ) );
	}

	if ( function_exists( 'extrachill_api_build_artist_response' ) ) {
		$data = extrachill_api_build_artist_response( $artist_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response( array(
			'object_type' => 'artist',
			'blog_id'     => get_current_blog_id(),
			'id'          => (int) $artist_id,
			'artist'      => $data,
		) );
	}

	return new WP_Error( 'dependency_missing', 'Artist resolver not available.', array( 'status' => 500 ) );
}
