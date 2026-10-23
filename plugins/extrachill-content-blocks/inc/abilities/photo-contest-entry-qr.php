<?php
declare(strict_types=1);
/**
 * Public read ability for validated entry QRs. The public GET transport lives
 * in extrachill-api at /extrachill/v1/content-blocks/photo-contest/entry-qr.
 *
 * @package ExtraChillContentBlocks
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_photo_contest_entry_qr_ability' );

/** Register the entry QR ability (not exposed through core REST). */
function extrachill_content_blocks_register_photo_contest_entry_qr_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/photo-contest-entry-qr',
		array(
			'label'               => __( 'Photo Contest Entry QR', 'extrachill-content-blocks' ),
			'description'         => __( 'Generate a QR code for a visible contest entry share URL.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'contest_id' => array( 'type' => 'string' ),
					'entry_id'   => array( 'type' => 'integer' ),
				),
				'required'   => array( 'post_id', 'contest_id', 'entry_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'image'     => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ),
				),
				'required'   => array( 'image', 'mime_type' ),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_photo_contest_entry_qr_ability_execute',
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
			),
		)
	);
}

/**
 * @param array $input Ability input.
 * @return array|WP_Error QR data or error.
 */
function extrachill_content_blocks_photo_contest_entry_qr_ability_execute( array $input ): array|\WP_Error {
	return extrachill_content_blocks_photo_contest_entry_qr(
		isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0,
		isset( $input['contest_id'] ) ? sanitize_text_field( (string) $input['contest_id'] ) : '',
		isset( $input['entry_id'] ) ? absint( $input['entry_id'] ) : 0
	);
}
