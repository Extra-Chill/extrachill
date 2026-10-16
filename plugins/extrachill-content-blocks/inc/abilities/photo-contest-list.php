<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-list
 *
 * Lists the visible entries of a photo contest with live vote counts.
 * Canonical logic lives in inc/photo-contest/entries.php via
 * extrachill_content_blocks_photo_contest_get_entries(). Entries are returned
 * in insertion order; the frontend shuffles client-side on every page load.
 *
 * Public transport: GET /extrachill/v1/content-blocks/photo-contest/entries
 * in extrachill-api delegates here.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_list_ability' );

/**
 * Register the photo-contest-list ability.
 */
function extrachill_content_blocks_register_photo_contest_list_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-list',
		array(
			'label'               => __( 'Photo Contest List Entries', 'extrachill-content-blocks' ),
			'description'         => __( 'Retrieve the visible entries of a photo contest with live vote counts and share URLs.', 'extrachill-content-blocks' ),
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
					'include_hidden' => array(
						'type'        => 'boolean',
						'description' => __( 'Include admin-hidden entries. Honoured only when the caller can moderate the contest; ignored otherwise.', 'extrachill-content-blocks' ),
						'default'     => false,
					),
				),
				'required'   => array( 'post_id', 'contest_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'entries' => array(
						'type'        => 'array',
						'description' => __( 'Entry objects: id, display_name, instagram, image_url, thumbnail_url, vote_count, share_url (plus hidden for honoured include_hidden requests). Entrant emails are never included.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_list_ability_execute',
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
 * Execute callback for extrachill/photo-contest-list.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Entries data or error.
 */
function extrachill_content_blocks_photo_contest_list_ability_execute( array $input ): array|\WP_Error {
	$post_id    = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$contest_id = isset( $input['contest_id'] ) ? sanitize_text_field( (string) $input['contest_id'] ) : '';

	if ( 0 === $post_id ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_post_id',
			__( 'A valid post ID is required.', 'extrachill-content-blocks' ),
			400
		);
	}

	if ( '' === $contest_id ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_contest_id',
			__( 'A contest ID is required.', 'extrachill-content-blocks' ),
			400
		);
	}

	if ( null === extrachill_content_blocks_photo_contest_get_settings( $post_id, $contest_id ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'contest_not_found',
			__( 'Contest not found.', 'extrachill-content-blocks' ),
			404
		);
	}

	// include_hidden is honoured only for moderators; anonymous and
	// non-moderator callers silently get the public list.
	$include_hidden = ! empty( $input['include_hidden'] )
		&& extrachill_content_blocks_photo_contest_can_moderate();

	$entries = extrachill_content_blocks_photo_contest_get_entries( $post_id, $contest_id, $include_hidden );

	if ( ! $include_hidden ) {
		// Public payload: strip the internal hidden flag (always false here).
		$entries = array_map(
			static function ( array $entry ): array {
				unset( $entry['hidden'] );
				return $entry;
			},
			$entries
		);
	}

	return array(
		'entries' => $entries,
	);
}
