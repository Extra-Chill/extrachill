<?php
/**
 * Upvote Abilities
 *
 * Abilities-first primitives for the community upvote system.
 * Colocated with inc/social/upvote.php which contains the business logic.
 *
 * @package ExtraChillCommunity
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_upvote_abilities' );

/**
 * Register upvote abilities.
 */
function extrachill_community_register_upvote_abilities() {

	// Note: extrachill/community-upvote moved to inc/abilities/community-upvote.php
	// with show_in_rest: true (see issue #24).

	wp_register_ability(
		'extrachill/community-get-upvotes',
		array(
			'label'               => __( 'Get Upvote Info', 'extra-chill-community' ),
			'description'         => __( 'Get upvote count for a post and whether a user has upvoted it.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'bbPress topic or reply post ID',
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => 'User to check vote status for (defaults to current user)',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'upvote_count' => array( 'type' => 'integer' ),
					'user_upvoted' => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_upvotes',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callbacks ─────────────────────────────────────────────────────────

/**
 * Toggle upvote on a topic or reply.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_upvote( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$type    = isset( $input['type'] ) ? (string) $input['type'] : '';
	$user_id = extrachill_community_resolve_user_id( $input );

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', 'A post_id is required.' );
	}
	if ( ! in_array( $type, array( 'topic', 'reply' ), true ) ) {
		return new WP_Error( 'invalid_type', 'Type must be "topic" or "reply".' );
	}
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', 'A valid user_id is required.' );
	}

	if ( ! function_exists( 'extrachill_process_upvote' ) ) {
		return new WP_Error( 'upvote_unavailable', 'Upvote system is not loaded.' );
	}

	return extrachill_process_upvote( $post_id, $type, $user_id );
}

/**
 * Get upvote count and user vote status.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_upvotes( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$user_id = extrachill_community_resolve_user_id( $input );

	if ( ! $post_id ) {
		return new WP_Error( 'missing_post_id', 'A post_id is required.' );
	}

	$count        = function_exists( 'get_upvote_count' ) ? get_upvote_count( $post_id ) : 0;
	$user_upvoted = false;

	if ( $user_id ) {
		$upvoted_posts = get_user_meta( $user_id, 'upvoted_posts', true );
		$user_upvoted  = is_array( $upvoted_posts ) && in_array( $post_id, $upvoted_posts, true );
	}

	return array(
		'post_id'      => $post_id,
		'upvote_count' => $count,
		'user_upvoted' => $user_upvoted,
	);
}
