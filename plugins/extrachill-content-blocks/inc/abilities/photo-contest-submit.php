<?php
declare(strict_types=1);
/**
 * Ability: extrachill/photo-contest-submit
 *
 * Processes a public photo-contest entry submission. Canonical logic lives in
 * src/blocks/photo-contest/index.php via
 * extrachill_content_blocks_photo_contest_submit_entry().
 *
 * Deliberately NOT exposed via the generic Abilities REST endpoint
 * (show_in_rest => false): the photo arrives as a multipart upload, so the
 * thin public route in extrachill-api
 * (POST /extrachill/v1/content-blocks/photo-contest/submit) is the canonical
 * transport. That route passes its $_FILES['photo'] entry straight through as
 * the `photo` input. This mirrors the extrachill/submit-event anonymous-upload
 * precedent in extrachill-events.
 *
 * @package ExtraChillContentBlocks
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_submit_ability' );

/**
 * Register the photo-contest-submit ability.
 */
function extrachill_content_blocks_register_photo_contest_submit_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-submit',
		array(
			'label'               => __( 'Photo Contest Submit Entry', 'extrachill-content-blocks' ),
			'description'         => __( 'Submit a photo contest entry: email-gated, one entry per email per contest, anonymous upload with validation.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Contest post ID.', 'extrachill-content-blocks' ),
					),
					'contest_id'   => array(
						'type'        => 'string',
						'description' => __( 'Contest block instance ID (contestId attribute).', 'extrachill-content-blocks' ),
					),
					'email'        => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'Entrant email (dedup key and newsletter opt-in).', 'extrachill-content-blocks' ),
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => __( 'Entrant display name shown on the entry.', 'extrachill-content-blocks' ),
					),
					'instagram'    => array(
						'type'        => 'string',
						'description' => __( 'Optional Instagram handle.', 'extrachill-content-blocks' ),
					),
					'website'      => array(
						'type'        => 'string',
						'description' => __( 'Honeypot field — must be empty.', 'extrachill-content-blocks' ),
					),
					'photo'        => array(
						'type'        => 'object',
						'description' => __( 'Uploaded photo file data from $_FILES (passed through by the REST route).', 'extrachill-content-blocks' ),
					),
					'ip'           => array(
						'type'        => 'string',
						'description' => __( 'Client IP for rate limiting (route-supplied).', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'post_id', 'contest_id', 'email', 'display_name', 'photo' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'message' => array(
						'type'        => 'string',
						'description' => __( 'Human-readable result message.', 'extrachill-content-blocks' ),
					),
					'entry'   => array(
						'type'        => 'object',
						'description' => __( 'Public entry data including the share URL.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_submit_ability_execute',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'     => false,
					'idempotent'   => false,
					'destructive'  => false,
					'instructions' => __( 'The dedicated photo-contest submit REST route in extrachill-api is the public multipart entry point.', 'extrachill-content-blocks' ),
				),
			),
		)
	);
}

/**
 * Execute callback for extrachill/photo-contest-submit.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Submission result or error.
 */
function extrachill_content_blocks_photo_contest_submit_ability_execute( array $input ): array|\WP_Error {
	if ( ! function_exists( 'extrachill_content_blocks_photo_contest_submit_entry' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Photo contest function not available.', 'extrachill-content-blocks' )
		);
	}

	$result = extrachill_content_blocks_photo_contest_submit_entry(
		array(
			'post_id'      => absint( $input['post_id'] ?? 0 ),
			'contest_id'   => sanitize_text_field( (string) ( $input['contest_id'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'display_name' => sanitize_text_field( (string) ( $input['display_name'] ?? '' ) ),
			'instagram'    => sanitize_text_field( (string) ( $input['instagram'] ?? '' ) ),
			'honeypot'     => (string) ( $input['website'] ?? '' ),
			'file'         => is_array( $input['photo'] ?? null ) ? $input['photo'] : null,
			'ip'           => sanitize_text_field( (string) ( $input['ip'] ?? '' ) ),
		)
	);

	if ( ! empty( $result['success'] ) ) {
		return array(
			'message' => (string) $result['message'],
			'entry'   => $result['entry'],
		);
	}

	$error = new \WP_Error(
		$result['code'] ?? 'submission_failed',
		(string) $result['message']
	);

	// already_entered carries the existing entry so the route can hand the
	// entrant their share link back.
	if ( isset( $result['entry'] ) ) {
		$error->add_data( array( 'entry' => $result['entry'] ) );
	}

	return $error;
}
