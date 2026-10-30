<?php
/**
 * Unit tests for QR Code validation functions.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_QR_Validation extends TestCase {

	/**
	 * Test validate_qr_url returns true for valid URL.
	 */
	public function test_validate_url_valid() {
		$result = extrachill_api_validate_qr_url( 'https://example.com' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_url returns true for valid URL with path.
	 */
	public function test_validate_url_with_path() {
		$result = extrachill_api_validate_qr_url( 'https://example.com/some/path' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_url returns true for valid URL with query string.
	 */
	public function test_validate_url_with_query() {
		$result = extrachill_api_validate_qr_url( 'https://example.com?foo=bar&baz=qux' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_url returns error for empty URL.
	 */
	public function test_validate_url_empty() {
		$result = extrachill_api_validate_qr_url( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_url', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_url returns error for null.
	 */
	public function test_validate_url_null() {
		$result = extrachill_api_validate_qr_url( null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'missing_url', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_url returns error for invalid URL.
	 */
	public function test_validate_url_invalid() {
		$result = extrachill_api_validate_qr_url( 'not-a-url' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_url returns error for URL without protocol.
	 */
	public function test_validate_url_no_protocol() {
		$result = extrachill_api_validate_qr_url( 'example.com' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_size returns true for valid size.
	 */
	public function test_validate_size_valid() {
		$result = extrachill_api_validate_qr_size( 500 );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_size returns true for boundary minimum.
	 */
	public function test_validate_size_boundary_min() {
		$result = extrachill_api_validate_qr_size( 100 );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_size returns true for boundary maximum.
	 */
	public function test_validate_size_boundary_max() {
		$result = extrachill_api_validate_qr_size( 2000 );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_size returns error for too small.
	 */
	public function test_validate_size_too_small() {
		$result = extrachill_api_validate_qr_size( 99 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'size_too_small', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_size returns error for too large.
	 */
	public function test_validate_size_too_large() {
		$result = extrachill_api_validate_qr_size( 2001 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'size_too_large', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_size handles string input.
	 */
	public function test_validate_size_string_input() {
		$result = extrachill_api_validate_qr_size( '500' );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_qr_size handles zero as too small.
	 */
	public function test_validate_size_zero() {
		$result = extrachill_api_validate_qr_size( 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'size_too_small', $result->get_error_code() );
	}

	/**
	 * Test validate_qr_size handles negative (absint converts to positive).
	 */
	public function test_validate_size_negative() {
		// absint(-100) = 100, which is a valid size.
		$result = extrachill_api_validate_qr_size( -100 );

		$this->assertTrue( $result );
	}
}
