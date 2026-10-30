<?php
/**
 * Sparse term rule shared by robots meta and the core taxonomy sitemaps.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function ExtraChill\SEO\Core\ec_seo_is_sparse_term;
use function ExtraChill\SEO\Core\ec_seo_sitemap_exclude_sparse_terms;
use function ExtraChill\SEO\Core\ec_seo_sitemap_min_count_clause;

final class SparseTermsTest extends TestCase {

	private function term( string $taxonomy, int $count ): object {
		return (object) array(
			'taxonomy' => $taxonomy,
			'count'    => $count,
		);
	}

	public function test_terms_under_two_posts_are_sparse(): void {
		$this->assertTrue( ec_seo_is_sparse_term( $this->term( 'venue', 0 ) ) );
		$this->assertTrue( ec_seo_is_sparse_term( $this->term( 'artist', 1 ) ) );
		$this->assertFalse( ec_seo_is_sparse_term( $this->term( 'venue', 2 ) ) );
	}

	public function test_festival_hubs_are_never_sparse(): void {
		$this->assertFalse( ec_seo_is_sparse_term( $this->term( 'festival', 0 ) ) );
	}

	/**
	 * Count `terms_clauses` registrations of the sitemap minimum-count callback.
	 */
	private function clause_registrations(): int {
		$count = 0;
		foreach ( $GLOBALS['ec_seo_test_filters']['terms_clauses'] ?? array() as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( 'ExtraChill\\SEO\\Core\\ec_seo_sitemap_min_count_clause' === $entry['callback'] ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Loading the module must not hook `terms_clauses` request-wide.
	 *
	 * A global `terms_clauses` callback disables data-machine-events' grouped
	 * filter-count SQL and forced past-event archives onto an unbounded
	 * fallback that exhausted memory.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_terms_clauses_not_hooked_until_sitemap_query(): void {
		$this->assertSame( 0, $this->clause_registrations() );

		ec_seo_sitemap_exclude_sparse_terms( array( 'taxonomy' => 'festival' ), 'festival' );
		$this->assertSame( 0, $this->clause_registrations(), 'Exempt taxonomies need no clause.' );

		ec_seo_sitemap_exclude_sparse_terms( array( 'taxonomy' => 'venue' ), 'venue' );
		ec_seo_sitemap_exclude_sparse_terms( array( 'taxonomy' => 'artist' ), 'artist' );
		$this->assertSame( 1, $this->clause_registrations(), 'Clause registers once per request.' );
	}

	public function test_sitemap_args_carry_minimum_count(): void {
		$args = ec_seo_sitemap_exclude_sparse_terms( array( 'taxonomy' => 'venue' ), 'venue' );
		$this->assertSame( 2, $args['ec_seo_min_count'] );
	}

	public function test_festival_sitemap_args_untouched(): void {
		$args = array( 'taxonomy' => 'festival' );
		$this->assertSame( $args, ec_seo_sitemap_exclude_sparse_terms( $args, 'festival' ) );
	}

	public function test_clause_added_only_for_flagged_queries(): void {
		$clauses = array( 'where' => "tt.taxonomy IN ('venue')" );

		$this->assertSame( $clauses, ec_seo_sitemap_min_count_clause( $clauses, array( 'venue' ), array( 'taxonomy' => 'venue' ) ) );
		$this->assertSame(
			"tt.taxonomy IN ('venue') AND tt.count >= 2",
			ec_seo_sitemap_min_count_clause( $clauses, array( 'venue' ), array( 'ec_seo_min_count' => 2 ) )['where']
		);
		$this->assertSame(
			'tt.count >= 2',
			ec_seo_sitemap_min_count_clause( array( 'where' => '' ), array( 'venue' ), array( 'ec_seo_min_count' => 2 ) )['where']
		);
	}
}
