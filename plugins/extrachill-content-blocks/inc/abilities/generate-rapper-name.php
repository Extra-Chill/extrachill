<?php
declare(strict_types=1);
/**
 * Ability: extrachill/generate-rapper-name
 *
 * Generates a random rapper name based on style, gender, and word count.
 * Canonical logic lives in src/blocks/rapper-name-generator/index.php; this
 * ability wraps that function so it is callable via the Abilities API.
 *
 * @package ExtraChillContentBlocks
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_generate_rapper_name_ability' );

/**
 * Register the generate-rapper-name ability.
 */
function extrachill_content_blocks_register_generate_rapper_name_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/generate-rapper-name',
		array(
			'label'               => __( 'Generate Rapper Name', 'extrachill-content-blocks' ),
			'description'         => __( 'Generate a random rapper name from style-specific word lists.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'input'           => array(
						'type'        => 'string',
						'description' => __( 'Seed name to include in the generated rapper name.', 'extrachill-content-blocks' ),
					),
					'style'           => array(
						'type'        => 'string',
						'description' => __( 'Rap style for word list selection.', 'extrachill-content-blocks' ),
						'enum'        => array( 'old school', 'trap', 'grime', 'conscious', 'random' ),
					),
					'gender'          => array(
						'type'        => 'string',
						'description' => __( 'Gender for prefix word list selection.', 'extrachill-content-blocks' ),
						'enum'        => array( 'male', 'female', 'non-binary' ),
					),
					'number_of_words' => array(
						'type'        => 'integer',
						'description' => __( 'Number of words in the generated name (2 or 3).', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'input' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The generated rapper name.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_generate_rapper_name_ability_execute',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for extrachill/generate-rapper-name.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Generated rapper name or error.
 */
function extrachill_content_blocks_generate_rapper_name_ability_execute( array $input ): array|\WP_Error {
	$seed            = isset( $input['input'] ) ? sanitize_text_field( $input['input'] ) : '';
	$style           = isset( $input['style'] ) ? sanitize_text_field( $input['style'] ) : '';
	$gender          = isset( $input['gender'] ) ? sanitize_text_field( $input['gender'] ) : '';
	$number_of_words = isset( $input['number_of_words'] ) ? absint( $input['number_of_words'] ) : 2;

	if ( '' === $seed ) {
		return new \WP_Error(
			'invalid_input',
			__( 'Please enter your name.', 'extrachill-content-blocks' )
		);
	}

	if ( ! function_exists( 'extrachill_content_blocks_generate_rapper_name' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Rapper name generator function not available.', 'extrachill-content-blocks' )
		);
	}

	$generated_name = extrachill_content_blocks_generate_rapper_name(
		$seed,
		$style,
		$gender,
		$number_of_words
	);

	return array(
		'name' => $generated_name,
	);
}
