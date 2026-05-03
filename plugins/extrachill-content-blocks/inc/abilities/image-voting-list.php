<?php
declare(strict_types=1);
/**
 * Ability: extrachill/image-voting-list
 *
 * Retrieves the current vote count for an image-voting block instance.
 * Canonical logic ported from extrachill-api's image-voting.php route.
 *
 * @package ExtraChillContentBlocks
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_image_voting_list_ability' );

/**
 * Register the image-voting-list ability.
 */
function extrachill_content_blocks_register_image_voting_list_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/image-voting-list',
		array(
			'label'               => __( 'Image Voting List', 'extrachill-content-blocks' ),
			'description'         => __( 'Retrieve the current vote count for an image-voting block instance.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'Post ID containing the image-voting block.', 'extrachill-content-blocks' ),
					),
					'instance_id' => array(
						'type'        => 'string',
						'description' => __( 'Unique block instance ID (uniqueBlockId attribute).', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id', 'instance_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'vote_count' => array(
						'type'        => 'integer',
						'description' => __( 'Current vote count for the block instance.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_image_voting_list_ability_execute',
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
 * Execute callback for extrachill/image-voting-list.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Vote count data or error.
 */
function extrachill_content_blocks_image_voting_list_ability_execute( array $input ): array|\WP_Error {
	$post_id     = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$instance_id = isset( $input['instance_id'] ) ? sanitize_text_field( $input['instance_id'] ) : '';

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

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new \WP_Error(
			'post_not_found',
			__( 'Post not found.', 'extrachill-content-blocks' )
		);
	}

	$blocks     = parse_blocks( $post->post_content );
	$vote_count = 0;

	foreach ( $blocks as $block ) {
		if ( 'extrachill/image-voting' === $block['blockName'] ) {
			$block_id = $block['attrs']['uniqueBlockId'] ?? '';
			if ( $block_id === $instance_id ) {
				$vote_count = (int) ( $block['attrs']['voteCount'] ?? 0 );
				break;
			}
		}
	}

	return array(
		'vote_count' => $vote_count,
	);
}
