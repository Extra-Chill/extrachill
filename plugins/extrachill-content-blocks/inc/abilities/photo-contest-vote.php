<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-vote
 *
 * Casts a vote on a photo-contest entry. Canonical logic lives in
 * inc/photo-contest/voting.php via
 * extrachill_content_blocks_photo_contest_process_vote(), which enforces the
 * voting window and entry visibility, then delegates to the shared
 * image-voting backend (image_vote comments, dedup per post × entry × email,
 * newsletter subscribe with the photo_contest context).
 *
 * Public transport: POST /extrachill/v1/content-blocks/photo-contest/vote in
 * extrachill-api (mirrors the image-voting vote route). Voters are anonymous
 * (QR code / shared link, identified by email only), so the permission
 * callback is public — the email-gated dedup and close-date checks inside
 * process_vote remain the authority. Deliberately NOT exposed via the generic
 * Abilities REST endpoint (show_in_rest => false): it is an anonymous public
 * write, reachable only through the extrachill/v1 adapter route, which owns
 * per-client admission (public-write rate limiting). The domain does no IP
 * handling.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_vote_ability' );

/**
 * Register the photo-contest-vote ability.
 */
function extrachill_content_blocks_register_photo_contest_vote_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-vote',
		array(
			'label'               => __( 'Photo Contest Vote', 'extrachill-content-blocks' ),
			'description'         => __( 'Cast a vote on a photo-contest entry (one vote per email per entry).', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Contest post ID.', 'extrachill-content-blocks' ),
					),
					'contest_id'    => array(
						'type'        => 'string',
						'description' => __( 'Contest block instance ID (contestId attribute).', 'extrachill-content-blocks' ),
					),
					'entry_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Entry attachment ID being voted on.', 'extrachill-content-blocks' ),
					),
					'email_address' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Voter email address (dedup key and newsletter opt-in).', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id', 'contest_id', 'entry_id', 'email_address' ),
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
						'description' => __( 'Updated vote count for the entry.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_vote_ability_execute',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'     => false,
					'idempotent'   => false,
					'destructive'  => false,
					'instructions' => __( 'The dedicated photo-contest vote REST route in extrachill-api is the public entry point.', 'extrachill-content-blocks' ),
				),
			),
		)
	);
}

/**
 * Execute callback for extrachill/photo-contest-vote.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Vote result or error.
 */
function extrachill_content_blocks_photo_contest_vote_ability_execute( array $input ): array|\WP_Error {
	$post_id    = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$contest_id = isset( $input['contest_id'] ) ? sanitize_text_field( (string) $input['contest_id'] ) : '';
	$entry_id   = isset( $input['entry_id'] ) ? absint( $input['entry_id'] ) : 0;
	$email      = isset( $input['email_address'] ) ? sanitize_email( (string) $input['email_address'] ) : '';

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

	if ( 0 === $entry_id ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_entry_id',
			__( 'A valid entry ID is required.', 'extrachill-content-blocks' ),
			400
		);
	}

	if ( ! is_email( $email ) ) {
		return extrachill_content_blocks_photo_contest_error(
			'invalid_email',
			__( 'A valid email address is required.', 'extrachill-content-blocks' ),
			400
		);
	}

	// Domain errors are status-coded WP_Errors and pass through unchanged.
	return extrachill_content_blocks_photo_contest_process_vote( $post_id, $contest_id, $entry_id, $email );
}
