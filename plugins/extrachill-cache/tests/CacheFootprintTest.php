<?php
/**
 * Page cache storage and eviction regression tests.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Filesystem fixtures use native PHP just like the early cache reader.
use PHPUnit\Framework\TestCase;

final class CacheFootprintTest extends TestCase {
	protected function tearDown(): void {
		extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
		unset( $GLOBALS['ec_test_budgets'], $GLOBALS['ec_test_options'] );
	}

	public function test_compressed_body_round_trips_with_original_headers(): void {
		if ( ! function_exists( 'gzencode' ) || ! function_exists( 'gzdecode' ) ) {
			$this->markTestSkipped( 'PHP zlib is unavailable.' );
		}

		$key     = extrachill_cache_key( 'https://example.com/events/' );
		$body    = '<html>' . str_repeat( '<p>Events &amp; Artists</p>', 5000 ) . '</html>';
		$headers = array( array( 'name' => 'Content-Type', 'value' => 'text/html; charset=UTF-8' ) );
		$expires = time() + 60;

		$this->assertTrue( extrachill_cache_write( $key, $body, $headers, 7, $expires ) );
		$path   = extrachill_cache_file_path( $key, 7 );
		$stored = unserialize( file_get_contents( $path ), array( 'allowed_classes' => false ) );
		$this->assertSame( 'gzip', $stored['body_encoding'] );
		$this->assertLessThan( strlen( $body ) / 10, filesize( $path ) );
		$this->assertSame( $expires, extrachill_cache_file_expires( $path ) );
		$this->assertSame(
			array( 'expires' => $expires, 'body' => $body, 'headers' => $headers ),
			extrachill_cache_read( $key, 7 )
		);
	}

	public function test_write_can_store_a_plain_body(): void {
		$key  = extrachill_cache_key( 'https://example.com/tiny/' );
		$body = '<p>Tiny</p>';
		$this->assertTrue( extrachill_cache_write( $key, $body, array(), 7 ) );
		$stored = unserialize( file_get_contents( extrachill_cache_file_path( $key, 7 ) ), array( 'allowed_classes' => false ) );
		$this->assertSame( array( 'body' => $body, 'headers' => array() ), $stored );
		$this->assertSame( $stored, extrachill_cache_read( $key, 7 ) );
	}

	public function test_legacy_entry_is_served_unchanged(): void {
		$key     = extrachill_cache_key( 'https://example.com/legacy/' );
		$payload = array( 'body' => '<p>Legacy</p>', 'headers' => array( 'Content-Type: text/html' ) );
		mkdir( extrachill_cache_blog_dir( 7 ), 0755, true );
		file_put_contents( extrachill_cache_file_path( $key, 7 ), serialize( $payload ) );
		$this->assertSame( $payload, extrachill_cache_read( $key, 7 ) );
	}

	public function test_gc_evicts_oldest_first_independently_of_ttl_batch(): void {
		$now    = time();
		$newest = $this->write_entry( 'newest', 7, $now - 10 );
		$oldest = $this->write_entry( 'oldest', 7, $now - 30 );
		$middle = $this->write_entry( 'middle', 7, $now - 20 );
		$other  = $this->write_entry( 'other', 2, $now - 100 );
		$root   = $this->write_entry( 'root', 0, $now - 100 );
		$tmp    = $newest . '.tmp';
		file_put_contents( $tmp, str_repeat( 'x', 4096 ) );

		$size                         = filesize( $newest );
		$GLOBALS['ec_test_budgets'][7] = $size;

		$dry = extrachill_cache_gc( array( 'file_limit' => 1, 'dry_run' => true ) );
		$this->assertSame( 2, $dry['deleted'] );
		$this->assertSame( 2 * $size, $dry['bytes'] );
		$this->assertFileExists( $oldest );
		$this->assertFileExists( $middle );

		$stats = extrachill_cache_gc( array( 'file_limit' => 1 ) );
		$this->assertSame( $dry, $stats );
		$this->assertSame( 1, $stats['examined'] );
		$this->assertSame( 1, $stats['partitions'][7]['files'] );
		$this->assertSame( $size, $stats['partitions'][7]['bytes'] );
		$this->assertSame( 1024, $stats['partitions'][2]['max_bytes'] );
		$this->assertSame( $size, $stats['partitions'][0]['bytes'] );
		$this->assertFileDoesNotExist( $oldest );
		$this->assertFileDoesNotExist( $middle );
		$this->assertFileExists( $newest );
		$this->assertFileExists( $other );
		$this->assertFileExists( $root );
		$this->assertFileExists( $tmp );
	}

	public function test_ttl_cleanup_takes_precedence_over_budget_eviction(): void {
		$now     = time();
		$fresh   = $this->write_entry( 'fresh', 7, $now - 120 );
		$expired = $this->write_entry( 'expired', 7, $now - 10, $now - 5 );
		$old     = $this->write_entry( 'old', 7, $now - EXTRACHILL_CACHE_TTL - 5, $now + 600 );
		$size    = filesize( $fresh );
		$GLOBALS['ec_test_budgets'][7] = $size;

		$dry = extrachill_cache_gc( array( 'dry_run' => true ) );
		$this->assertSame( 2, $dry['deleted'] );
		$this->assertSame( filesize( $old ) + filesize( $expired ), $dry['bytes'] );
		$this->assertFileExists( $expired );
		$this->assertFileExists( $old );

		$this->assertSame( $dry, extrachill_cache_gc() );
		$this->assertFileDoesNotExist( $expired );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh );
	}

	private function write_entry( string $name, int $blog_id, int $mtime, int $expires = 0 ): string {
		$key = extrachill_cache_key( 'https://example.com/' . $name . '/' );
		$this->assertTrue( extrachill_cache_write( $key, str_repeat( '<p>Event</p>', 20 ), array(), $blog_id, $expires ) );
		$path = extrachill_cache_file_path( $key, $blog_id );
		$this->assertTrue( touch( $path, $mtime ) );
		return $path;
	}
}
