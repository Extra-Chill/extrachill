<?php

use PHPUnit\Framework\TestCase;

final class RestorePointGuardTest extends TestCase {
	protected function setUp(): void {
		ec_test_reset();
		$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Guarded Page', 'post_name' => 'guarded-page' );
		unset( $GLOBALS['ec_test']['now'] );
	}

	private function errorCode( $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	private function populated(): array {
		return array(
			array(
				'id'            => '40-section-1',
				'section_title' => 'Listen',
				'links'         => array(
					array( 'id' => '40-link-1', 'link_text' => 'Song', 'link_url' => 'https://example.com/song' ),
					array( 'id' => '40-link-2', 'link_text' => 'Show', 'link_url' => 'https://example.com/show' ),
				),
			),
		);
	}

	public function test_populated_to_empty_is_refused_and_storage_untouched(): void {
		update_post_meta( 40, '_link_page_links', $this->populated() );
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'];
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_save_link_page_persistence( 40, array( 'links' => array() ) ) ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
		$this->assertCount( 0, array_filter( $GLOBALS['ec_test']['fired_actions'] ?? array(), static function ( $action ) { return 'ec_link_page_persistence_saved' === $action[0]; } ) );
	}

	public function test_sectioned_payload_without_links_is_refused_before_id_assignment(): void {
		update_post_meta( 40, '_link_page_links', $this->populated() );
		$before = $GLOBALS['ec_test']['blogs'][4]['post_meta'];
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_save_link_page_persistence( 40, array( 'links' => array( array( 'id' => 'new-9', 'section_title' => 'Gigs', 'links' => array() ) ) ) ) ) );
		$this->assertSame( $before, $GLOBALS['ec_test']['blogs'][4]['post_meta'] );
	}

	public function test_populated_to_empty_with_explicit_intent_succeeds_and_stashes(): void {
		update_post_meta( 40, '_link_page_links', $this->populated() );
		$GLOBALS['ec_test']['now'] = 1700000000;
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_save_link_page_persistence( 40, array( 'links' => array(), 'allow_empty' => 1 ) ) ) );
		$result = ec_save_link_page_persistence( 40, array( 'links' => array(), 'allow_empty' => true ) );
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['links'] );
		$this->assertSame( array(), get_post_meta( 40, '_link_page_links', true ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links_previous', true ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1700000000 ), get_post_meta( 40, '_link_page_links_previous_stored_at', true ) );
	}

	public function test_empty_to_populated_is_unaffected_and_writes_no_restore_point(): void {
		$this->assertNull( ec_read_link_page_persistence( 40 )['previous_links'] );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'links' => $this->populated() ) ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links', true ) );
		$this->assertFalse( metadata_exists( 'post', 40, '_link_page_links_previous' ) );
		$this->assertFalse( metadata_exists( 'post', 40, '_link_page_links_previous_stored_at' ) );
	}

	public function test_populated_to_different_stashes_prior_value_and_identical_resave_preserves_it(): void {
		update_post_meta( 40, '_link_page_links', $this->populated() );
		$updated = array(
			array(
				'id'            => '40-section-1',
				'section_title' => 'Listen',
				'links'         => array( array( 'id' => '40-link-1', 'link_text' => 'New', 'link_url' => 'https://example.com/new' ) ),
			),
		);
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'links' => $updated ) ) );
		$this->assertSame( $updated, get_post_meta( 40, '_link_page_links', true ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links_previous', true ) );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'links' => $updated ) ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links_previous', true ) );
	}

	public function test_previous_links_are_retrievable_through_the_read_surface(): void {
		$GLOBALS['ec_test']['now'] = 1700000000;
		update_post_meta( 40, '_link_page_links', $this->populated() );
		ec_save_link_page_persistence( 40, array( 'links' => array(), 'allow_empty' => true ) );
		$previous = ec_get_link_page_previous_links( 40 );
		$this->assertSame( $this->populated(), $previous['links'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1700000000 ), $previous['stored_at'] );
		$this->assertSame( $previous, ec_read_link_page_persistence( 40 )['previous_links'] );
		$GLOBALS['ec_test']['blogs'][4]['posts'][41] = (object) array( 'ID' => 41, 'post_type' => EC_LINK_PAGE_POST_TYPE, 'post_status' => 'publish' );
		$this->assertNull( ec_get_link_page_previous_links( 41 ) );
		$this->assertSame( 'invalid_link_page', $this->errorCode( ec_get_link_page_previous_links( 99 ) ) );
	}

	public function test_legacy_flat_storage_is_counted_and_stashed_verbatim(): void {
		$legacy = array( array( 'id' => '', 'link_text' => 'Old', 'link_url' => 'https://example.com/old' ) );
		update_post_meta( 40, '_link_page_links', $legacy );
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_save_link_page_persistence( 40, array( 'links' => array() ) ) ) );
		$this->assertSame( $legacy, get_post_meta( 40, '_link_page_links', true ) );
		$this->assertIsArray( ec_save_link_page_persistence( 40, array( 'links' => array(), 'allow_empty' => true ) ) );
		$this->assertSame( $legacy, get_post_meta( 40, '_link_page_links_previous', true ) );
	}

	public function test_operation_save_refuses_silent_empty_and_intent_flows_through(): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'profile', 'post_status' => 'publish' );
		$this->assertTrue( ec_assign_link_page_owner( 40, 'post:4:profile:20' ) );
		update_post_meta( 40, '_link_page_links', $this->populated() );
		ec_register_link_page_operation_provider( 'passthrough', static function () {
			return array(
				'authorize' => '__return_true',
				'read'      => static function ( $target ) { return $target; },
				'save'      => static function ( $target, $data ) { return ec_save_link_page_persistence( $target['link_page_id'], $data ); },
			);
		} );
		$this->assertSame( 'link_page_refuses_silent_empty', $this->errorCode( ec_save_link_page( 40, array( 'links' => array() ) ) ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links', true ) );
		$this->assertIsArray( ec_save_link_page( 40, array( 'links' => array(), 'allow_empty' => true ) ) );
		$this->assertSame( array(), get_post_meta( 40, '_link_page_links', true ) );
		$this->assertSame( $this->populated(), get_post_meta( 40, '_link_page_links_previous', true ) );
	}

	public function test_expiration_cleanup_may_empty_a_page_and_stashes_the_prior_value(): void {
		update_post_meta( 40, '_link_page_links', $this->populated() );
		update_post_meta( 40, '_link_page_links_previous', $this->populated() );
		update_post_meta( 40, '_link_expiration_enabled', '1' );
		$expired = array(
			array(
				'id'            => '40-section-1',
				'section_title' => 'Listen',
				'links'         => array(
					array( 'id' => '40-link-1', 'link_text' => 'Song', 'link_url' => 'https://example.com/song', 'expires_at' => '2020-01-01' ),
					array( 'id' => '40-link-2', 'link_text' => 'Show', 'link_url' => 'https://example.com/show', 'expires_at' => '2020-01-01' ),
				),
			),
		);
		update_post_meta( 40, '_link_page_links', $expired );
		$GLOBALS['ec_test']['now'] = strtotime( '2026-01-01 00:00:00' );
		$this->assertTrue( ec_cleanup_expired_link_page_links() );
		$this->assertSame( array(), get_post_meta( 40, '_link_page_links', true ) );
		$this->assertSame( $expired, get_post_meta( 40, '_link_page_links_previous', true ) );
	}

	public function test_restore_point_write_failure_refuses_the_overwrite(): void {
		$stored = $this->populated();
		update_post_meta( 40, '_link_page_links', $stored );
		$updated = array(
			array(
				'id'            => '40-section-1',
				'section_title' => 'Listen',
				'links'         => array( array( 'id' => '40-link-1', 'link_text' => 'New', 'link_url' => 'https://example.com/new' ) ),
			),
		);
		$GLOBALS['ec_test']['meta_write_calls']      = 0;
		$GLOBALS['ec_test']['fail_meta_write_calls'] = array( 3 );
		$this->assertSame( 'link_page_restore_point_failed', $this->errorCode( ec_save_link_page_persistence( 40, array( 'bio' => 'Changed', 'links' => $updated ) ) ) );
		$this->assertSame( $stored, get_post_meta( 40, '_link_page_links', true ) );
		$this->assertSame( '', get_post_meta( 40, '_link_page_bio_text', true ) );
	}
}
