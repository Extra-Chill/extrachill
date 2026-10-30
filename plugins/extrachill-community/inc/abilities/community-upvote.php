<?php
/**
 * Ability: extrachill/community-upvote
 *
 * Toggle an upvote on a bbPress topic or reply for the current user.
 * Canonical implementation — the REST route in extrachill-api refactors
 * to a thin shim that delegates here.
 *
 * @package ExtraChillCommunity
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_community_register_community_upvote_ability' );

/**
 * Register the community-upvote ability.
 */
function extrachill_community_register_community_upvote_ability(): void {

	wp_register_ability(
		'extrachill/community-upvote',
		array(
			'label'               => __( 'Community Upvote', 'extra-chill-community' ),
			'description'         => __( 'Toggle an upvote on a bbPress topic or reply for the current user.', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'bbPress topic or reply post ID.',
					),
					'type'    => array(
						'type'        => 'string',
						'enum'        => array( 'topic', 'reply' ),
						'description' => 'Post type to upvote.',
					),
				),
				'required'   => array( 'post_id', 'type' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message'   => array( 'type' => 'string' ),
					'new_count' => array( 'type' => 'integer' ),
					'upvoted'   => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_community_upvote',
			'permission_callback' => static function (): bool {
				return is_user_logged_in();
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

// ─── Execute callback ──────────────────────────────────────────────────────────

/**
 * Toggle an upvote on a topic or reply.
 *
 * Delegates to extrachill_process_upvote() — the canonical business-logic
 * helper already in this plugin (inc/social/upvote.php).
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_community_upvote( array $input ): array|WP_Error {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$type    = isset( $input['type'] ) ? (string) $input['type'] : '';
	$user_id = get_current_user_id();

	if ( $post_id <= 0 ) {
		return new WP_Error( 'missing_post_id', 'A valid post_id is required.', array( 'status' => 400 ) );
	}
	if ( ! in_array( $type, array( 'topic', 'reply' ), true ) ) {
		return new WP_Error( 'invalid_type', 'Type must be "topic" or "reply".', array( 'status' => 400 ) );
	}
	if ( ! $user_id ) {
		return new WP_Error( 'not_logged_in', 'A valid user is required.', array( 'status' => 401 ) );
	}

	if ( ! function_exists( 'extrachill_process_upvote' ) ) {
		return new WP_Error( 'upvote_unavailable', 'Upvote system is not loaded.', array( 'status' => 500 ) );
	}

	$result = extrachill_process_upvote( $post_id, $type, $user_id );

	if ( ! $result['success'] ) {
		return new WP_Error( 'upvote_failed', $result['message'], array( 'status' => 400 ) );
	}

	return array(
		'message'   => $result['message'],
		'new_count' => $result['new_count'],
		'upvoted'   => $result['upvoted'],
	);
}
