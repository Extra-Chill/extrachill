<?php
declare(strict_types=1);
/**
 * Ability: extrachill/trivia-list
 *
 * Reads the extrachill/trivia blocks out of a post and returns their parsed
 * attributes in document order. Lets callers (CLI, REST, agents) inspect an
 * existing quiz before appending to it or auditing its questions. Read-only;
 * contains no generation logic.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_trivia_list_ability' );

/**
 * Register the trivia-list ability.
 */
function extrachill_content_blocks_register_trivia_list_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/trivia-list',
		array(
			'label'               => __( 'List Trivia Questions', 'extrachill-content-blocks' ),
			'description'         => __( 'Read the extrachill/trivia blocks from a post and return their parsed attributes in order.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => __( 'Post ID to read trivia blocks from.', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post that was read.', 'extrachill-content-blocks' ),
					),
					'count'     => array(
						'type'        => 'integer',
						'description' => __( 'Number of trivia blocks found.', 'extrachill-content-blocks' ),
					),
					'questions' => array(
						'type'        => 'array',
						'description' => __( 'Parsed trivia block attributes in document order.', 'extrachill-content-blocks' ),
						'items'       => array( 'type' => 'object' ),
					),
				),
			),
			'permission_callback' => 'extrachill_content_blocks_trivia_list_permission',
			'execute_callback'    => 'extrachill_content_blocks_trivia_list_ability_execute',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Permission callback for trivia-list.
 *
 * Reading a post's blocks requires edit capability on that post (the same bar
 * as the editor that authored them).
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function extrachill_content_blocks_trivia_list_permission( array $input = array() ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to read this post.', 'extrachill-content-blocks' ),
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Execute callback for extrachill/trivia-list.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Parsed trivia questions or error.
 */
function extrachill_content_blocks_trivia_list_ability_execute( array $input ): array|\WP_Error {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	if ( $post_id <= 0 ) {
		return new \WP_Error(
			'invalid_post_id',
			__( 'A valid post ID is required.', 'extrachill-content-blocks' )
		);
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new \WP_Error(
			'post_not_found',
			sprintf(
				/* translators: %d: post ID */
				__( 'Post %d not found.', 'extrachill-content-blocks' ),
				$post_id
			)
		);
	}

	$questions = array();
	foreach ( parse_blocks( (string) $post->post_content ) as $block ) {
		if ( ( $block['blockName'] ?? '' ) !== 'extrachill/trivia' ) {
			continue;
		}
		$questions[] = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	}

	return array(
		'post_id'   => $post_id,
		'count'     => count( $questions ),
		'questions' => $questions,
	);
}
