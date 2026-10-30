<?php
/**
 * Synopsis contract for `wp extrachill artists classify-genre`.
 *
 * WP-CLI resolves `--no-x` to `x => false` and rejects the negation unless
 * the positive flag is declared in the synopsis. Declaring only `[--no-ai]`
 * made the documented zero-cost run fail with "unknown --ai parameter" — the
 * one invocation that avoids spending money was the one that could not be
 * invoked.
 *
 * The synopsis is parsed from the docblock at runtime, so asserting on it is
 * asserting on the actual command contract rather than on a copy of it.
 *
 * @package ExtraChillEvents\Tests\Unit\Cli
 */

namespace ExtraChillEvents\Tests\Unit\Cli;

use ExtraChillEvents\Cli\ClassifyGenreCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__, 3 ) . '/inc/Cli/ClassifyGenreCommand.php';

class ClassifyGenreSynopsisTest extends TestCase {

	private function synopsis(): string {
		$doc = ( new ReflectionMethod( ClassifyGenreCommand::class, '__invoke' ) )->getDocComment();

		return is_string( $doc ) ? $doc : '';
	}

	/**
	 * Both halves of the negatable pair must be declared.
	 *
	 * WP-CLI will not accept `--no-ai` on its own.
	 */
	public function test_ai_flag_is_declared_so_its_negation_is_accepted(): void {
		$synopsis = $this->synopsis();

		$this->assertStringContainsString(
			'[--ai]',
			$synopsis,
			'WP-CLI rejects --no-ai unless the positive [--ai] flag is declared'
		);
		$this->assertStringContainsString( '[--no-ai]', $synopsis );
	}

	/**
	 * The zero-cost path has to stay documented, since it is the reason the
	 * flag exists at all.
	 */
	public function test_no_ai_documents_that_it_costs_nothing(): void {
		$this->assertMatchesRegularExpression(
			'/\[--no-ai\].*?zero AI\s+\*?\s*cost/s',
			$this->synopsis(),
			'--no-ai must keep documenting that it skips all AI spend'
		);
	}

	/**
	 * The flag is read through WP-CLI's negation-aware accessor.
	 *
	 * Reading $assoc_args['no-ai'] directly cannot work: WP-CLI never puts a
	 * literal 'no-ai' key there.
	 */
	public function test_flag_is_read_through_the_negation_aware_accessor(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/Cli/ClassifyGenreCommand.php' );

		$this->assertStringContainsString(
			"get_flag_value( \$assoc_args, 'ai', true )",
			$source,
			'the AI pass must default on and be disabled via WP-CLI negation'
		);
		$this->assertStringNotContainsString(
			"\$assoc_args['no-ai']",
			$source,
			"WP-CLI never populates a literal 'no-ai' key"
		);
	}
}
