<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-vote
 *
 * Casts a vote on a photo-contest entry. Canonical logic lives in
 * src/blocks/photo-contest/index.php via
 * extrachill_content_blocks_photo_contest_process_vote(), which enforces the
 * voting window and entry visibility, then delegates to the shared
 * image-voting backend (image_vote comments, dedup per post × entry × email,
 * newsletter subscribe).
 *
 * Public transport: POST /extrachill/v1/content-blocks/photo-contest/vote in
 * extrachill-api (mirrors the image-voting vote route). Voters are anonymous
 * (QR code / shared link, identified by email only), so the permission
 * callback is public — the email-gated dedup and close-date checks inside
 * process_vote remain the authority. Abuse protection is a per-IP transient
 * rate limit on the optional route-supplied `ip` input; the route passes
 * REMOTE_ADDR through.
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
					'ip'            => array(
						'type'        => 'string',
						'description' => __( 'Client IP for rate limiting (route-supplied).', 'extrachill-content-blocks' ),
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
	$ip         = isset( $input['ip'] ) ? sanitize_text_field( (string) $input['ip'] ) : '';

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

	if ( 0 === $entry_id ) {
		return new \WP_Error(
			'invalid_entry_id',
			__( 'A valid entry ID is required.', 'extrachill-content-blocks' )
		);
	}

	if ( ! is_email( $email ) ) {
		return new \WP_Error(
			'invalid_email',
			__( 'A valid email address is required.', 'extrachill-content-blocks' )
		);
	}

	if ( function_exists( 'extrachill_content_blocks_photo_contest_vote_rate_limit_ok' )
		&& ! extrachill_content_blocks_photo_contest_vote_rate_limit_ok( $ip ) ) {
		return new \WP_Error(
			'rate_limited',
			__( 'Too many votes — please wait a few minutes and try again.', 'extrachill-content-blocks' )
		);
	}

	if ( ! function_exists( 'extrachill_content_blocks_photo_contest_process_vote' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Photo contest function not available.', 'extrachill-content-blocks' )
		);
	}

	$result = extrachill_content_blocks_photo_contest_process_vote( $post_id, $contest_id, $entry_id, $email );

	if ( ! empty( $result['success'] ) ) {
		return array(
			'message'    => (string) $result['message'],
			'vote_count' => (int) $result['vote_count'],
		);
	}

	return new \WP_Error(
		$result['code'] ?? 'vote_failed',
		(string) $result['message']
	);
}
