<?php
/**
 * Rapper Name Generator tests.
 *
 * Covers #1571: generated names must never contain "The The", never contain
 * double spaces, and never touch an undefined variable (3 words with no
 * gender used to read an unset $secondWord). Generation is random, so the
 * assertions check structural properties across many seeded iterations.
 *
 * @package ExtraChillContentBlocks
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/blocks/rapper-name-generator/index.php';
require_once dirname( __DIR__, 2 ) . '/inc/abilities/generate-rapper-name.php';

/**
 * Rapper name generation.
 */
class RapperNameGeneratorTest extends TestCase {

	private const STYLES = array( 'old school', 'trap', 'grime', 'conscious', 'random', '' );

	private const GENDERS = array( 'male', 'female', 'non-binary', '' );

	private const ITERATIONS = 25;

	/**
	 * Previous error_reporting level.
	 *
	 * @var int
	 */
	private $previous_error_reporting = 0;

	protected function setUp(): void {
		wp_stubs_reset();
		mt_srand( 1571 );

		// Any notice/warning (e.g. an undefined variable) fails the test.
		$this->previous_error_reporting = error_reporting( E_ALL );
		set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
				throw new \ErrorException( $errstr, 0, $errno, $errfile, $errline );
			}
		);
	}

	protected function tearDown(): void {
		restore_error_handler();
		error_reporting( $this->previous_error_reporting );
		mt_srand();
	}

	/**
	 * Every style / gender / word-count combination.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function generationPaths(): array {
		$paths = array();
		foreach ( self::STYLES as $style ) {
			foreach ( self::GENDERS as $gender ) {
				foreach ( array( 2, 3 ) as $words ) {
					$key           = sprintf( '%s/%s/%d', '' === $style ? 'default' : $style, '' === $gender ? 'none' : $gender, $words );
					$paths[ $key ] = array( $style, $gender, $words );
				}
			}
		}
		return $paths;
	}

	/**
	 * Assert the structural invariants of a generated name.
	 *
	 * @param mixed $name Generated name.
	 */
	private function assertWellFormed( $name ): void {
		$this->assertIsString( $name );
		$this->assertNotSame( '', $name );
		$this->assertSame( trim( $name ), $name, "Name has leading/trailing whitespace: '{$name}'." );
		$this->assertStringNotContainsString( '  ', $name, "Name has double spaces: '{$name}'." );
		$this->assertDoesNotMatchRegularExpression( '/\bthe\s+the\b/i', $name, "Name repeats 'the': '{$name}'." );
	}

	/**
	 * @dataProvider generationPaths
	 */
	public function test_names_are_well_formed_on_every_path( string $style, string $gender, int $words ): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$name = extrachill_content_blocks_generate_rapper_name( 'Sam', $style, $gender, $words );

			$this->assertWellFormed( $name );
			$this->assertStringContainsString( 'Sam', $name );
		}
	}

	/**
	 * @dataProvider generationPaths
	 */
	public function test_input_ending_in_the_is_not_doubled( string $style, string $gender, int $words ): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$this->assertWellFormed( extrachill_content_blocks_generate_rapper_name( 'The', $style, $gender, $words ) );
		}
	}

	public function test_three_word_grime_has_single_spaced_the(): void {
		for ( $i = 0; $i < self::ITERATIONS; $i++ ) {
			$name = extrachill_content_blocks_generate_rapper_name( 'Sam', 'grime', 'male', 3 );

			$this->assertMatchesRegularExpression( '/ Sam the \S/', $name );
		}
	}

	public function test_male_prefix_list_does_not_include_the(): void {
		for ( $i = 0; $i < self::ITERATIONS * 4; $i++ ) {
			$name = extrachill_content_blocks_generate_rapper_name( 'Sam', 'conscious', 'male', 3 );

			$this->assertDoesNotMatchRegularExpression( '/^The\b/', $name );
		}
	}

	public function test_unsupported_word_counts_still_return_a_name(): void {
		foreach ( array( 0, 1, 4, '3', 'abc' ) as $words ) {
			$this->assertWellFormed( extrachill_content_blocks_generate_rapper_name( 'Sam', 'trap', '', $words ) );
		}
	}

	public function test_whitespace_in_input_is_collapsed(): void {
		$this->assertWellFormed( extrachill_content_blocks_generate_rapper_name( "  Big   Sam \t", 'random', '', 3 ) );
	}

	public function test_ability_returns_well_formed_name(): void {
		$result = extrachill_content_blocks_generate_rapper_name_ability_execute(
			array(
				'input'           => 'Sam',
				'style'           => 'grime',
				'number_of_words' => 3,
			)
		);

		$this->assertIsArray( $result );
		$this->assertWellFormed( $result['name'] );
	}
}
