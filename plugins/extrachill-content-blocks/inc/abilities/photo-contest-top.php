<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-top
 *
 * Admin-facing ranked top-N entries by vote count so staff can pull finalists
 * (e.g. the top 10 who go on stage). Canonical logic lives in
 * src/blocks/photo-contest/index.php via
 * extrachill_content_blocks_photo_contest_top_entries().
 *
 * Reachable by editors/admins via core /wp-abilities/v1 (show_in_rest) and by
 * staff via WP-CLI (wp extrachill-content-blocks photo-contest top). The
 * public "Finalists" view does not use this ability — the frontend ranks the
 * public entries list client-side once voting closes.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_top_ability' );

/**
 * Register the photo-contest-top ability.
 */
function extrachill_content_blocks_register_photo_contest_top_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-top',
		array(
			'label'               => __( 'Photo Contest Top Entries', 'extrachill-content-blocks' ),
			'description'         => __( 'Ranked top-N photo-contest entries by vote count (ties broken by earliest entry).', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Contest post ID.', 'extrachill-content-blocks' ),
					),
					'contest_id'     => array(
						'type'        => 'string',
						'description' => __( 'Contest block instance ID (contestId attribute).', 'extrachill-content-blocks' ),
					),
					'count'          => array(
						'type'        => 'integer',
						'description' => __( 'Number of entries to return (default 10).', 'extrachill-content-blocks' ),
					),
					'include_hidden' => array(
						'type'        => 'boolean',
						'description' => __( 'Include admin-hidden entries in the ranking.', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id', 'contest_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'entries' => array(
						'type'        => 'array',
						'description' => __( 'Ranked entry objects (highest votes first).', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => 'extrachill_content_blocks_photo_contest_can_moderate',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_top_ability_execute',
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
 * Execute callback for extrachill/photo-contest-top.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Ranked entries or error.
 */
function extrachill_content_blocks_photo_contest_top_ability_execute( array $input ): array|\WP_Error {
	$post_id        = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$contest_id     = isset( $input['contest_id'] ) ? sanitize_text_field( (string) $input['contest_id'] ) : '';
	$count          = isset( $input['count'] ) ? max( 1, absint( $input['count'] ) ) : 10;
	$include_hidden = ! empty( $input['include_hidden'] );

	if ( 0 === $post_id ) {
		return new \WP_Error(
			'invalid_post_id',
			__( 'A valid post ID is required.', 'extrachill-content-blocks' )
		);
	}

	if ( '' === $contest_id ) {
		return new \WP_Error(
			'invalid_contest_id',
			__( 'A contest ID is required.', 'extrachill-content-blocks' )
		);
	}

	if ( ! function_exists( 'extrachill_content_blocks_photo_contest_top_entries' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Photo contest function not available.', 'extrachill-content-blocks' )
		);
	}

	return array(
		'entries' => extrachill_content_blocks_photo_contest_top_entries( $post_id, $contest_id, $count, $include_hidden ),
	);
}
