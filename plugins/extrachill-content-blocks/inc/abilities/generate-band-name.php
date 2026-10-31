<?php
declare(strict_types=1);
/**
 * Ability: extrachill/generate-band-name
 *
 * Generates a random band name based on genre, word count, and modifier flags.
 * Canonical logic lives in src/blocks/band-name-generator/index.php; this
 * ability wraps that function so it is callable via the Abilities API.
 *
 * @package ExtraChillContentBlocks
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_content_blocks_register_generate_band_name_ability' );

/**
 * Register the generate-band-name ability.
 */
function extrachill_content_blocks_register_generate_band_name_ability(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/generate-band-name',
		array(
			'label'               => __( 'Generate Band Name', 'extrachill-content-blocks' ),
			'description'         => __( 'Generate a random band name from genre-specific word lists.', 'extrachill-content-blocks' ),
			'category'            => 'extrachill-content',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'input'           => array(
						'type'        => 'string',
						'description' => __( 'Seed word or name to include in the generated band name.', 'extrachill-content-blocks' ),
					),
					'genre'           => array(
						'type'        => 'string',
						'description' => __( 'Music genre for word list selection.', 'extrachill-content-blocks' ),
						'enum'        => array( 'rock', 'country', 'metal', 'indie', 'punk', 'jam', 'electronic', 'random' ),
					),
					'number_of_words' => array(
						'type'        => 'integer',
						'description' => __( 'Number of random words to include (2-4).', 'extrachill-content-blocks' ),
					),
					'first_the'       => array(
						'type'        => 'boolean',
						'description' => __( 'Prepend "The" to the band name.', 'extrachill-content-blocks' ),
					),
					'and_the'         => array(
						'type'        => 'boolean',
						'description' => __( 'Insert "& The" in the middle of the band name.', 'extrachill-content-blocks' ),
					),
				),
				'required'   => array( 'input' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The generated band name.', 'extrachill-content-blocks' ),
					),
				),
			),
			'permission_callback' => '__return_true',
			'execute_callback'    => 'extrachill_content_blocks_generate_band_name_ability_execute',
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
 * Execute callback for extrachill/generate-band-name.
 *
 * @param array $input Ability input matching input_schema.
 * @return array|WP_Error Generated band name or error.
 */
function extrachill_content_blocks_generate_band_name_ability_execute( array $input ): array|\WP_Error {
	$seed            = isset( $input['input'] ) ? sanitize_text_field( $input['input'] ) : '';
	$genre           = isset( $input['genre'] ) ? sanitize_text_field( $input['genre'] ) : 'rock';
	$number_of_words = isset( $input['number_of_words'] ) ? absint( $input['number_of_words'] ) : 2;
	$first_the       = ! empty( $input['first_the'] );
	$and_the         = ! empty( $input['and_the'] );

	if ( '' === $seed ) {
		return new \WP_Error(
			'invalid_input',
			__( 'Please enter your name or word.', 'extrachill-content-blocks' )
		);
	}

	if ( ! function_exists( 'extrachill_content_blocks_generate_band_name' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'Band name generator function not available.', 'extrachill-content-blocks' )
		);
	}

	$generated_name = extrachill_content_blocks_generate_band_name(
		$seed,
		$genre,
		$number_of_words,
		$first_the,
		$and_the
	);

	return array(
		'name' => $generated_name,
	);
}
