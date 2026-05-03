<?php
declare(strict_types=1);
/**
 * Ability: extrachill/image-voting-vote
 *
 * Casts a vote on an image-voting block instance.
 * Canonical logic lives in src/blocks/image-voting/index.php via
 * extrachill_content_blocks_process_image_vote().
 *
 * @package ExtraChillContentBlocks
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_image_voting_vote_ability' );

/**
 * Register the image-voting-vote ability.
 */
function extrachill_content_blocks_register_image_voting_vote_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/image-voting-vote',
		array(
			'label'               => __( 'Image Voting Vote', 'extrachill-content-blocks' ),
			'description'         => __( 'Cast a vote on an image-voting block instance.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Post ID containing the image-voting block.', 'extrachill-content-blocks' ),
					),
					'instance_id'   => array(
						'type'        => 'string',
						'description' => __( 'Unique block instance ID (uniqueBlockId attribute).', 'extrachill-content-blocks' ),
					),
					'email_address' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Voter email address (used for deduplication and newsletter opt-in).', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id', 'instance_id', 'email_address' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message'    => array(
						'type'        => 'string',
						'description' => __( 'Human-readable result message.', 'extrachill-content-blocks' ),
					),
					'vote_count' => array(
						'type'        => 'integer',
						'description' => __( 'Updated vote count after the vote.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => 'is_user_logged_in',
			'execute_callback'    => 'extrachill_content_blocks_image_voting_vote_ability_execute',
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

/**
 * Execute callback for extrachill/image-voting-vote.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Vote result or error.
 */
function extrachill_content_blocks_image_voting_vote_ability_execute( array $input ): array|\WP_Error {
	$post_id       = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$instance_id   = isset( $input['instance_id'] ) ? sanitize_text_field( $input['instance_id'] ) : '';
	$email_address = isset( $input['email_address'] ) ? sanitize_email( $input['email_address'] ) : '';

	if ( 0 === $post_id ) {
		return new \WP_Error(
			'invalid_post_id',
			__( 'A valid post ID is required.', 'extrachill-content-blocks' )
		);
	}

	if ( '' === $instance_id ) {
		return new \WP_Error(
			'invalid_instance_id',
			__( 'A block instance ID is required.', 'extrachill-content-blocks' )
		);
	}

	if ( ! is_email( $email_address ) ) {
		return new \WP_Error(
			'invalid_email',
			__( 'A valid email address is required.', 'extrachill-content-blocks' )
		);
	}

	if ( ! function_exists( 'extrachill_content_blocks_process_image_vote' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Image voting function not available.', 'extrachill-content-blocks' )
		);
	}

	$result = extrachill_content_blocks_process_image_vote( $post_id, $instance_id, $email_address );

	if ( $result['success'] ) {
		return array(
			'message'    => $result['message'],
			'vote_count' => $result['vote_count'],
		);
	}

	return new \WP_Error(
		$result['code'] ?? 'vote_failed',
		$result['message']
	);
}
