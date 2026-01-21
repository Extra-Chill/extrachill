<?php
/**
 * Unit tests for ID Generator functions.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

class Test_ID_Generator extends TestCase {

	/**
	 * Reset post meta storage before each test.
	 */
	protected function setUp(): void {
		global $test_post_meta;
		$test_post_meta = array();
	}

	/**
	 * Test meta key map returns expected array.
	 */
	public function test_id_meta_key_map_returns_array() {
		$map = extrachill_api_id_meta_key_map();

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'section', $map );
		$this->assertArrayHasKey( 'link', $map );
		$this->assertArrayHasKey( 'social', $map );
		$this->assertEquals( '_ec_section_id_counter', $map['section'] );
		$this->assertEquals( '_ec_link_id_counter', $map['link'] );
		$this->assertEquals( '_ec_social_id_counter', $map['social'] );
	}

	/**
	 * Test get_next_id returns correct format for section type.
	 */
	public function test_get_next_id_section_format() {
		$link_page_id = 123;
		$id           = extrachill_api_get_next_id( $link_page_id, 'section' );

		$this->assertEquals( '123-section-1', $id );
	}

	/**
	 * Test get_next_id returns correct format for link type.
	 */
	public function test_get_next_id_link_format() {
		$link_page_id = 456;
		$id           = extrachill_api_get_next_id( $link_page_id, 'link' );

		$this->assertEquals( '456-link-1', $id );
	}

	/**
	 * Test get_next_id returns correct format for social type.
	 */
	public function test_get_next_id_social_format() {
		$link_page_id = 789;
		$id           = extrachill_api_get_next_id( $link_page_id, 'social' );

		$this->assertEquals( '789-social-1', $id );
	}

	/**
	 * Test get_next_id increments counter on each call.
	 */
	public function test_get_next_id_increments() {
		$link_page_id = 100;

		$first  = extrachill_api_get_next_id( $link_page_id, 'section' );
		$second = extrachill_api_get_next_id( $link_page_id, 'section' );
		$third  = extrachill_api_get_next_id( $link_page_id, 'section' );

		$this->assertEquals( '100-section-1', $first );
		$this->assertEquals( '100-section-2', $second );
		$this->assertEquals( '100-section-3', $third );
	}

	/**
	 * Test get_next_id returns empty string for invalid type.
	 */
	public function test_get_next_id_invalid_type() {
		$id = extrachill_api_get_next_id( 123, 'invalid_type' );

		$this->assertEquals( '', $id );
	}

	/**
	 * Test needs_id_assignment returns true for empty string.
	 */
	public function test_needs_id_assignment_empty() {
		$this->assertTrue( extrachill_api_needs_id_assignment( '' ) );
	}

	/**
	 * Test needs_id_assignment returns true for null.
	 */
	public function test_needs_id_assignment_null() {
		$this->assertTrue( extrachill_api_needs_id_assignment( null ) );
	}

	/**
	 * Test needs_id_assignment returns true for temp prefix.
	 */
	public function test_needs_id_assignment_temp() {
		$this->assertTrue( extrachill_api_needs_id_assignment( 'temp-abc123' ) );
		$this->assertTrue( extrachill_api_needs_id_assignment( 'temp-' ) );
	}

	/**
	 * Test needs_id_assignment returns false for valid ID.
	 */
	public function test_needs_id_assignment_valid() {
		$this->assertFalse( extrachill_api_needs_id_assignment( '123-section-1' ) );
		$this->assertFalse( extrachill_api_needs_id_assignment( '456-link-5' ) );
	}

	/**
	 * Test sync_counter_from_id extracts and stores counter.
	 */
	public function test_sync_counter_from_id_extracts_number() {
		$link_page_id = 200;

		extrachill_api_sync_counter_from_id( $link_page_id, 'section', '200-section-15' );

		$stored = get_post_meta( $link_page_id, '_ec_section_id_counter', true );
		$this->assertEquals( 15, $stored );
	}

	/**
	 * Test sync_counter_from_id only updates if higher.
	 */
	public function test_sync_counter_from_id_only_updates_if_higher() {
		$link_page_id = 300;

		// Set initial counter to 20.
		update_post_meta( $link_page_id, '_ec_section_id_counter', 20 );

		// Try to sync lower value.
		extrachill_api_sync_counter_from_id( $link_page_id, 'section', '300-section-10' );

		$stored = get_post_meta( $link_page_id, '_ec_section_id_counter', true );
		$this->assertEquals( 20, $stored );

		// Sync higher value.
		extrachill_api_sync_counter_from_id( $link_page_id, 'section', '300-section-25' );

		$stored = get_post_meta( $link_page_id, '_ec_section_id_counter', true );
		$this->assertEquals( 25, $stored );
	}

	/**
	 * Test sync_counter_from_id ignores invalid type.
	 */
	public function test_sync_counter_from_id_invalid_type() {
		$link_page_id = 400;

		extrachill_api_sync_counter_from_id( $link_page_id, 'invalid', '400-invalid-5' );

		// Should not create any meta.
		$stored = get_post_meta( $link_page_id, '_ec_invalid_id_counter', true );
		$this->assertEmpty( $stored );
	}

	/**
	 * Test sync_counter_from_id ignores mismatched link page ID.
	 */
	public function test_sync_counter_from_id_mismatched_link_page() {
		$link_page_id = 500;

		// ID contains different link page ID.
		extrachill_api_sync_counter_from_id( $link_page_id, 'section', '999-section-10' );

		$stored = get_post_meta( $link_page_id, '_ec_section_id_counter', true );
		$this->assertEmpty( $stored );
	}

	/**
	 * Test sync_counter_from_id ignores malformed ID.
	 */
	public function test_sync_counter_from_id_malformed_id() {
		$link_page_id = 600;

		extrachill_api_sync_counter_from_id( $link_page_id, 'section', 'not-a-valid-id' );

		$stored = get_post_meta( $link_page_id, '_ec_section_id_counter', true );
		$this->assertEmpty( $stored );
	}
}
