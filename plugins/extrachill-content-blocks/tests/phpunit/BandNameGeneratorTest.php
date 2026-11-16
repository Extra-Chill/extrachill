<?php
/**
 * Band Name Generator tests.
 *
 * Covers the "Add 'The' at the beginning" option (#1542): the generated name
 * must lead with "The" ahead of the user's word, never produce "The The", and
 * leave output unchanged when the option is off. Generation is random, so the
 * assertions check structural properties across many seeded iterations.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/blocks/band-name-generator/index.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities/generate-band-name.php';

/**
 * Band name generation.
 */
class BandNameGeneratorTest extends TestCase {

	private const GENRES = array( 'rock', 'country', 'metal', 'indie', 'punk', 'jam', 'electronic', 'random', 'unknown' );

	private const ITERATIONS = 25;

	protected function setUp(): void {
		wp_stubs_reset();
		mt_srand( 1542 );
	}

	protected function tearDown(): void {
		mt_srand();
	}

	/**
	 * Every genre / word-count / "& The" combination with "The" on.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	public static function generationPaths(): array {
		$paths = array();
		foreach ( self::GENRES as $genre ) {
			foreach ( array( 2, 3, 4 ) as $words ) {
				foreach ( array( false, true ) as $and_the ) {
					$paths[ sprintf( '%s/%d/%s', $genre, $words, $and_the ? 'and-the' : 'plain' ) ] = array( $genre, $words, $and_the );
				}
			}
		}
		return $paths;
	}

	/**
	 * @dataProvider generationPaths
	 */
	public function test_first_the_leads_the_name_on_every_path( string $genre, int $words, bool $and_the ): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$name = extrachill_content_blocks_generate_band_name( 'Sam', $genre, $words, true, $and_the );

			$this->assertStringStartsWith( 'The Sam ', $name, "Expected 'The' before the user's word, got '{$name}'." );
			$this->assertDoesNotMatchRegularExpression( '/^The The\b/i', $name );
		}
	}

	public function test_first_the_with_and_the_keeps_middle_the(): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$name = extrachill_content_blocks_generate_band_name( 'Sam', 'rock', 4, true, true );

			$this->assertMatchesRegularExpression( '/^The Sam \S+ \S+ & The \S+ \S+$/', $name );
		}
	}

	public function test_first_the_is_not_doubled_when_input_already_starts_with_the(): void {
		foreach ( array( 'The Sam', 'the sam', 'The' ) as $input ) {
			$name = extrachill_content_blocks_generate_band_name( $input, 'metal', 2, true, false );

			$this->assertStringStartsWith( $input, $name );
			$this->assertDoesNotMatchRegularExpression( '/^the the\b/i', $name );
		}
	}

	public function test_without_first_the_the_users_word_still_comes_first(): void {
		foreach ( array( 2, 3, 4 ) as $words ) {
			foreach ( array( false, true ) as $and_the ) {
				$name = extrachill_content_blocks_generate_band_name( 'Sam', 'indie', $words, false, $and_the );

				$this->assertStringStartsWith( 'Sam ', $name );
				$this->assertSame( $words + 1 + ( $and_the ? 2 : 0 ), count( explode( ' ', $name ) ), "Unexpected word count in '{$name}'." );
			}
		}
	}

	public function test_and_the_middle_placement_is_unchanged_without_first_the(): void {
		$this->assertMatchesRegularExpression(
			'/^Sam \S+ & The \S+$/',
			extrachill_content_blocks_generate_band_name( 'Sam', 'punk', 2, false, true )
		);
		$this->assertMatchesRegularExpression(
			'/^Sam \S+ \S+ & The \S+ \S+$/',
			extrachill_content_blocks_generate_band_name( 'Sam', 'punk', 4, false, true )
		);
	}

	public function test_ability_passes_first_the_through(): void {
		$result = extrachill_content_blocks_generate_band_name_ability_execute(
			array(
				'input'           => 'Sam',
				'genre'           => 'jam',
				'number_of_words' => 3,
				'first_the'       => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( 'The Sam ', $result['name'] );
	}
}
