<?php
/** Location SEO count cache regression tests. */

use PHPUnit\Framework\TestCase;

final class LocationSeoCountTest extends TestCase {
	/** A fresh PHP process resets function statics while retaining supplied cache data. */
	private function request( array $input = array() ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' '
			. escapeshellarg( dirname( __DIR__, 2 ) . '/fixtures/location-seo-count.php' ) . ' '
			. escapeshellarg( json_encode( $input ) );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	public function test_miss_queries_once_and_stores_for_one_hour(): void {
		$result = $this->request();
		$this->assertSame( array( 45, 45, 45, 45 ), $result['counts'] );
		$this->assertSame(
			array( array( 'scope' => 'upcoming', 'tax_filters' => array( 'location' => array( 42 ) ), 'fields' => 'count' ) ),
			$result['queries']
		);
		$this->assertCount( 1, $result['writes'] );
		$this->assertSame( 45, $result['writes'][0][1] );
		$this->assertSame( 3600, $result['writes'][0][2] );
		$this->assertSame(
			'Find live music in Charleston tonight and this week. 45 upcoming shows across 2 venues including Venue One, Venue Two, and more.',
			$result['description']
		);
	}

	public function test_cached_count_survives_request_memo_reset(): void {
		$first  = $this->request();
		$second = $this->request( array( 'cache' => $first['cache'], 'total' => 99 ) );
		$this->assertSame( array( 45, 45, 45, 45 ), $second['counts'] );
		$this->assertSame( array(), $second['queries'] );
		$this->assertSame( array(), $second['writes'] );
		$this->assertSame( $first['description'], $second['description'] );
	}

	public function test_zero_is_a_cache_hit(): void {
		$first  = $this->request( array( 'total' => 0 ) );
		$second = $this->request( array( 'cache' => $first['cache'] ) );
		$this->assertCount( 1, $first['queries'] );
		$this->assertSame( array( 0, 0, 0, 0 ), $second['counts'] );
		$this->assertSame( array(), $second['queries'] );
	}

	public function test_terms_have_independent_counts(): void {
		$result = $this->request( array( 'terms' => array( 42, 43, 42, 43 ) ) );
		$this->assertCount( 2, $result['queries'] );
		$this->assertCount( 2, $result['writes'] );
	}

	public function test_generation_change_invalidates_cached_count(): void {
		$first  = $this->request();
		$second = $this->request( array( 'cache' => $first['cache'], 'generation' => 'generation-two', 'total' => 99 ) );
		$this->assertSame( array( 99, 99, 99, 99 ), $second['counts'] );
		$this->assertCount( 1, $second['queries'] );
	}
}
