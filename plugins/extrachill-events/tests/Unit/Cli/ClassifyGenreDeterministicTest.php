<?php
/**
 * Deterministic-classifier tests for the ClassifyGenreCommand.
 *
 * Deterministic() and resolve_text() are pure static methods (no WordPress
 * dependencies; the genre resolver is injected), so the rules behind the
 * deterministic pass of `wp extrachill artists classify-genre` are asserted
 * without a WP test fixture.
 *
 * @package ExtraChillEvents\Tests\Unit\Cli
 */

namespace ExtraChillEvents\Tests\Unit\Cli;

use ExtraChillEvents\Cli\ClassifyGenreCommand;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Cli/ClassifyGenreCommand.php';

/**
 * Tests For the ClassifyGenreCommand pure deterministic classifier.
 */
class ClassifyGenreDeterministicTest extends TestCase {

	/**
	 * A fake resolver mirroring extrachill_network_resolve_genres()
	 * semantics: exact-match per piece against a closed vocabulary.
	 *
	 * Vocabulary: "Bluegrass" => bluegrass, "Indie Rock" alias => indie,
	 * "Rock" => rock, "Jazz" => jazz.
	 *
	 * @return callable
	 */
	private function fake_resolver(): callable {
		return static function ( string $value ): array {
			$pieces = preg_split( '/[,\/|+]|\s+and\s+/i', $value );
			$pieces = is_array( $pieces ) ? $pieces : array();

			$resolved = array();
			foreach ( $pieces as $piece ) {
				$needle = strtolower( trim( $piece ) );

				$slug = '';
				if ( 'bluegrass' === $needle ) {
					$slug = 'bluegrass';
				} elseif ( 'indie rock' === $needle || 'indie' === $needle ) {
					$slug = 'indie';
				} elseif ( 'rock' === $needle ) {
					$slug = 'rock';
				} elseif ( 'jazz' === $needle ) {
					$slug = 'jazz';
				}

				if ( '' !== $slug && ! in_array( $slug, $resolved, true ) ) {
					$resolved[] = $slug;
				}
			}

			return $resolved;
		};
	}

	/**
	 * Base signals with nothing that could fire.
	 *
	 * @return array<string,mixed>
	 */
	private function neutral_signals(): array {
		return array(
			'name'            => 'Some Random String',
			'performer_texts' => array(),
			'cobilled_genres' => array(),
		);
	}

	/**
	 * Assert nothing fires on neutral signals.
	 */
	public function test_nothing_fires_returns_empty(): void {
		$result = ClassifyGenreCommand::deterministic( $this->neutral_signals(), $this->fake_resolver() );

		$this->assertSame( array(), $result['genres'] );
		$this->assertSame( 0.0, $result['confidence'] );
		$this->assertSame( array(), $result['reasons'] );
	}

	/**
	 * Assert a whole-string name match proposes at 0.75.
	 */
	public function test_whole_name_match_scores_075(): void {
		$signals                    = $this->neutral_signals();
		$signals['name']            = 'Bluegrass';
		$signals['performer_texts'] = array( 'The Bluegrass' );

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array( 'bluegrass' ), $result['genres'] );
		$this->assertSame( 0.75, $result['confidence'] );
	}

	/**
	 * Assert a word-window name match proposes at 0.70.
	 */
	public function test_window_name_match_scores_070(): void {
		$signals         = $this->neutral_signals();
		$signals['name'] = 'The Bluegrass Boys';

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array( 'bluegrass' ), $result['genres'] );
		$this->assertSame( 0.70, $result['confidence'] );
	}

	/**
	 * Assert performer text fills the gap at 0.70 when the name does not resolve.
	 */
	public function test_performer_text_never_fills_the_gap_without_a_name_match(): void {
		// The performer attribute is a per-event act name ("Sebastian (Space
		// Punk Discoteca)"), so on its own it is per-event evidence, which
		// #30 forbids. It may only corroborate a name match.
		$signals                    = $this->neutral_signals();
		$signals['performer_texts'] = array( 'Sebastian (Space Punk Discoteca)', 'an indie rock trio from Charleston' );

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array(), $result['genres'] );
		$this->assertSame( 0.0, $result['confidence'] );
	}

	/**
	 * Assert agreement corroborates (+0.05) and merges genre sets.
	 */
	public function test_performer_agreement_corroborates_name_and_merges(): void {
		$signals                    = $this->neutral_signals();
		$signals['name']            = 'Bluegrass';
		$signals['performer_texts'] = array( 'bluegrass and rock' );

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array( 'bluegrass', 'rock' ), $result['genres'] );
		$this->assertSame( 0.80, $result['confidence'] );
	}

	/**
	 * Assert the co-billing prior fires with three agreeing co-bills (plus the extra-agreement bump).
	 */
	public function test_cobilling_prior_fires_at_070_with_three_agreeing(): void {
		$signals                    = $this->neutral_signals();
		$signals['cobilled_genres'] = array(
			array( 'rock' ),
			array( 'rock', 'jazz' ),
			array( 'rock' ),
		);

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array( 'rock' ), $result['genres'] );
		$this->assertSame( 0.75, $result['confidence'] );
	}

	/**
	 * Assert the co-billing confidence is capped at 0.85.
	 */
	public function test_cobilling_prior_confidence_capped_at_085(): void {
		$signals                    = $this->neutral_signals();
		$signals['cobilled_genres'] = array(
			array( 'rock' ),
			array( 'rock' ),
			array( 'rock' ),
			array( 'rock' ),
			array( 'rock' ),
			array( 'rock' ),
			array( 'rock' ),
		);

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array( 'rock' ), $result['genres'] );
		$this->assertLessThanOrEqual( 0.85, $result['confidence'] );
		$this->assertGreaterThanOrEqual( 0.80, $result['confidence'] );
	}

	/**
	 * Assert the prior needs at least three genre-carrying co-bills.
	 */
	public function test_cobilling_prior_ignores_single_genre_carrier(): void {
		$signals                    = $this->neutral_signals();
		$signals['cobilled_genres'] = array(
			array( 'rock' ),
			array(),
			array(),
		);

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array(), $result['genres'] );
	}

	/**
	 * Assert the prior refuses more than two distinct genres.
	 */
	public function test_cobilling_prior_ignores_three_distinct_genres(): void {
		$signals                    = $this->neutral_signals();
		$signals['cobilled_genres'] = array(
			array( 'rock' ),
			array( 'jazz' ),
			array( 'bluegrass' ),
		);

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array(), $result['genres'] );
	}

	/**
	 * Assert the prior refuses a share below 60%.
	 */
	public function test_cobilling_prior_ignores_majority_below_sixty_percent(): void {
		$signals                    = $this->neutral_signals();
		$signals['cobilled_genres'] = array(
			array( 'rock' ),
			array( 'rock' ),
			array( 'jazz' ),
			array( 'bluegrass' ),
		);

		$result = ClassifyGenreCommand::deterministic( $signals, $this->fake_resolver() );

		$this->assertSame( array(), $result['genres'] );
	}

	/**
	 * Assert the proposal is capped at three genres.
	 */
	public function test_genres_capped_at_three(): void {
		$signals         = $this->neutral_signals();
		$signals['name'] = 'Rock';

		$resolver = static function ( string $value ): array {
			if ( 'Rock' === $value ) {
				return array( 'rock', 'jazz', 'bluegrass', 'indie', 'punk' );
			}
			return array();
		};

		$result = ClassifyGenreCommand::deterministic( $signals, $resolver );

		$this->assertCount( 3, $result['genres'] );
	}

	/**
	 * Assert whole-string resolution is flagged whole.
	 */
	public function test_resolve_text_whole_match_flagged_whole(): void {
		$resolved = ClassifyGenreCommand::resolve_text( 'Bluegrass', $this->fake_resolver() );

		$this->assertSame( array( 'bluegrass' ), $resolved['genres'] );
		$this->assertTrue( $resolved['whole'] );
	}

	/**
	 * Assert window resolution is not flagged whole.
	 */
	public function test_resolve_text_window_match_not_flagged_whole(): void {
		$resolved = ClassifyGenreCommand::resolve_text( 'The Bluegrass Boys', $this->fake_resolver() );

		$this->assertSame( array( 'bluegrass' ), $resolved['genres'] );
		$this->assertFalse( $resolved['whole'] );
	}

	/**
	 * Assert the longest matching window wins.
	 */
	public function test_resolve_text_longest_window_wins(): void {
		$resolved = ClassifyGenreCommand::resolve_text( 'an indie rock trio', $this->fake_resolver() );

		$this->assertSame( array( 'indie' ), $resolved['genres'] );
		$this->assertFalse( $resolved['whole'] );
	}

	/**
	 * Assert empty input resolves to nothing.
	 */
	public function test_resolve_text_empty_input(): void {
		$resolved = ClassifyGenreCommand::resolve_text( '', $this->fake_resolver() );

		$this->assertSame( array(), $resolved['genres'] );
		$this->assertFalse( $resolved['whole'] );
	}

	/**
	 * Assert stop-word-only windows never resolve.
	 */
	public function test_resolve_text_stop_word_only_windows_skipped(): void {
		// "The Band" contains no vocabulary match; stop-word windows must not
		// rescue it (and there is no "band" genre anyway).
		$resolved = ClassifyGenreCommand::resolve_text( 'The Band', $this->fake_resolver() );

		$this->assertSame( array(), $resolved['genres'] );
	}
}
