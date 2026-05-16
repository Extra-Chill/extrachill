<?php
/**
 * Tests for QualifyVerdict::canonicalize_url and url_hash.
 *
 * Pure-unit — no WP test framework required. See tests/bootstrap.php for
 * the small set of WP polyfills these tests rely on.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;
use PHPUnit\Framework\TestCase;

class QualifyVerdictCanonicalizerTest extends TestCase {

	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', QualifyVerdict::canonicalize_url( '' ) );
		$this->assertSame( '', QualifyVerdict::canonicalize_url( '   ' ) );
	}

	public function test_lowercases_host(): void {
		$this->assertSame(
			'https://example.com',
			QualifyVerdict::canonicalize_url( 'https://Example.COM' )
		);
	}

	public function test_drops_trailing_slash_from_path(): void {
		$this->assertSame(
			'https://example.com/calendar',
			QualifyVerdict::canonicalize_url( 'https://example.com/calendar/' )
		);
	}

	public function test_preserves_bare_host_without_path(): void {
		$this->assertSame(
			'https://example.com',
			QualifyVerdict::canonicalize_url( 'https://example.com' )
		);
		$this->assertSame(
			'https://example.com',
			QualifyVerdict::canonicalize_url( 'https://example.com/' )
		);
	}

	public function test_drops_fragment(): void {
		$this->assertSame(
			'https://example.com/events',
			QualifyVerdict::canonicalize_url( 'https://example.com/events#shows' )
		);
	}

	public function test_drops_non_identifying_query_params(): void {
		$this->assertSame(
			'https://example.com/events',
			QualifyVerdict::canonicalize_url( 'https://example.com/events?utm_source=fb&fbclid=abc' )
		);
	}

	public function test_keeps_identifying_query_params(): void {
		$canon = QualifyVerdict::canonicalize_url( 'https://example.com/?view=calendar&utm_source=fb' );
		$this->assertSame( 'https://example.com?view=calendar', $canon );
	}

	public function test_adds_scheme_when_missing(): void {
		$this->assertSame(
			'https://example.com/events',
			QualifyVerdict::canonicalize_url( 'example.com/events' )
		);
	}

	public function test_returns_empty_for_unparseable_input(): void {
		// No host after the scheme — unparseable.
		$this->assertSame( '', QualifyVerdict::canonicalize_url( 'https://' ) );
	}

	public function test_url_hash_is_stable_across_variations(): void {
		$a = QualifyVerdict::url_hash( 'https://Example.com/Calendar/#x' );
		$b = QualifyVerdict::url_hash( 'https://example.com/Calendar' );
		$this->assertSame( 40, strlen( $a ) );
		$this->assertSame( $a, $b );
	}

	public function test_url_hash_distinguishes_query_kept_params(): void {
		$plain = QualifyVerdict::url_hash( 'https://example.com/' );
		$view  = QualifyVerdict::url_hash( 'https://example.com/?view=calendar' );
		$this->assertNotSame( $plain, $view );
	}

	public function test_url_hash_empty_for_empty_input(): void {
		$this->assertSame( '', QualifyVerdict::url_hash( '' ) );
	}
}
