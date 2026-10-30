<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-set-phase.
 * Reversible moderator-only manual close; clearing restores scheduled windows.
 * @package ExtraChillContentBlocks
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_set_phase_ability' );

/**
 * Register the photo-contest-set-phase ability.
 */
function extrachill_content_blocks_register_photo_contest_set_phase_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-set-phase',
		array(
			'label' => __( 'Photo Contest Set Phase', 'extrachill-content-blocks' ),
			'description' => __( 'Close entries or voting now, or restore the scheduled window.', 'extrachill-content-blocks' ),
			'category' => 'extrachill-content',
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'contest_id' => array( 'type' => 'string' ),
					'phase' => array( 'type' => 'string', 'enum' => array( 'entries', 'voting' ) ),
					'closed' => array( 'type' => 'boolean' ),
				),
				'required' => array( 'post_id', 'contest_id', 'phase', 'closed' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message' => array(
						'type'        => 'string',
						'description' => __( 'Human-readable result message.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => 'extrachill_content_blocks_photo_contest_can_moderate',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_set_phase_ability_execute',
			'meta'                => array(
				'show_in_rest' => true,
				// Manual closing is reversible, so it is
				// not destructive. Core's Abilities REST runner maps
				// destructive+idempotent to DELETE; this keeps it on POST,
				// which the front-end moderator controls use.
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for extrachill/photo-contest-set-phase.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Result or error.
 */
function extrachill_content_blocks_photo_contest_set_phase_ability_execute( array $input ): array|\WP_Error {
	$post_id = absint( $input['post_id'] ?? 0 );
	$contest_id = sanitize_text_field( (string) ( $input['contest_id'] ?? '' ) );
	$phase = (string) ( $input['phase'] ?? '' );
	if ( ! $post_id || '' === $contest_id || ! in_array( $phase, array( 'entries', 'voting' ), true )
		|| ! isset( $input['closed'] ) || ! is_bool( $input['closed'] ) ) {
		return extrachill_content_blocks_photo_contest_error( 'invalid_phase', 'A contest, phase and closed boolean are required.', 400 );
	}
	return extrachill_content_blocks_photo_contest_set_phase( $post_id, $contest_id, $phase, $input['closed'] );
}
