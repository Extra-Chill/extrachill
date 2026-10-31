<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-hide
 *
 * Hides (or restores) a photo-contest entry in one action — admin moderation
 * for inappropriate submissions on contest night. Canonical logic lives in
 * inc/photo-contest/moderation.php via
 * extrachill_content_blocks_photo_contest_set_entry_hidden().
 *
 * Reachable by editors/admins via core /wp-abilities/v1 (show_in_rest) and by
 * staff via extrachill-cli (wp extrachill content photo-contest hide).
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_hide_ability' );

/**
 * Register the photo-contest-hide ability.
 */
function extrachill_content_blocks_register_photo_contest_hide_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-hide',
		array(
			'label'               => __( 'Photo Contest Hide Entry', 'extrachill-content-blocks' ),
			'description'         => __( 'Hide or restore a photo-contest entry (admin moderation). Hidden entries leave the public grid and cannot receive votes.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'entry_id' => array(
						'type'        => 'integer',
						'description' => __( 'Entry attachment ID.', 'extrachill-content-blocks' ),
					),
					'hidden'   => array(
						'type'        => 'boolean',
						'description' => __( 'True to hide (default), false to restore.', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'entry_id' ),
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
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_hide_ability_execute',
			'meta'                => array(
				'show_in_rest' => true,
				// Hiding is reversible (unhide restores the entry), so it is
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
 * Execute callback for extrachill/photo-contest-hide.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Result or error.
 */
function extrachill_content_blocks_photo_contest_hide_ability_execute( array $input ): array|\WP_Error {
	$entry_id = isset( $input['entry_id'] ) ? absint( $input['entry_id'] ) : 0;
	$hidden   = ! isset( $input['hidden'] ) || (bool) $input['hidden'];

	if ( 0 === $entry_id ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_entry_id',
			__( 'A valid entry ID is required.', 'extrachill-content-blocks' ),
			400
		);
	}

	// Domain errors are status-coded WP_Errors and pass through unchanged.
	return extrachill_content_blocks_photo_contest_set_entry_hidden( $entry_id, $hidden );
}
