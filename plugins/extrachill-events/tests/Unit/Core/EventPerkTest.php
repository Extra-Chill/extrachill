<?php
/**
 * Event RSVP perk meta getters and the admin save handler.
 *
 * Pure-PHP unit test with a minimal in-memory post-meta store, following
 * this repo's qualify-v2-unit convention (see tests/PromoterAuthorityTest.php
 * for the canonical shape of a bespoke, self-contained WP-function double).
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		unset( $action );
		return 'valid-nonce' === $nonce;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, $object_id = null ) {
		unset( $capability, $object_id );
		return $GLOBALS['event_perk_test']['can_edit'] ?? true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub; core's own sanitizer is unavailable in this pure-PHP suite.
	}
}

if ( ! function_exists( 'extrachill_cache_purge_post' ) ) {
	function extrachill_cache_purge_post( $post_id ) {
		$GLOBALS['event_perk_test']['purged'][] = (int) $post_id;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';

final class EventPerkTest extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_perk_test'] = array(
			'meta'     => array(),
			'can_edit' => true,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['event_perk_test'] );
		parent::tearDown();
	}

	private function post_save_request( array $fields ): array {
		return array_merge(
			array(
				'extrachill_event_perk_nonce_field' => 'valid-nonce',
			),
			$fields
		);
	}

	public function test_perk_disabled_by_default(): void {
		$this->assertFalse( extrachill_events_perk_enabled( 42 ) );
		$this->assertSame( '', extrachill_events_get_perk_text( 42 ) );
	}

	public function test_save_enables_perk_and_stores_text(): void {
		$_POST = $this->post_save_request(
			array(
				'extrachill_event_perk_enabled' => '1',
				'extrachill_event_perk_text'    => 'First beer on Extra Chill',
			)
		);

		extrachill_events_save_event_perk( 42 );

		$this->assertTrue( extrachill_events_perk_enabled( 42 ) );
		$this->assertSame( 'First beer on Extra Chill', extrachill_events_get_perk_text( 42 ) );
	}

	public function test_save_disables_perk_and_clears_text(): void {
		$_POST = $this->post_save_request(
			array(
				'extrachill_event_perk_enabled' => '1',
				'extrachill_event_perk_text'    => 'First beer on Extra Chill',
			)
		);
		extrachill_events_save_event_perk( 42 );

		$_POST = $this->post_save_request(
			array(
				'extrachill_event_perk_text' => '',
			)
		);
		extrachill_events_save_event_perk( 42 );

		$this->assertFalse( extrachill_events_perk_enabled( 42 ) );
		$this->assertSame( '', extrachill_events_get_perk_text( 42 ) );
	}

	public function test_save_is_rejected_without_a_valid_nonce(): void {
		$_POST = array(
			'extrachill_event_perk_nonce_field' => 'not-the-right-nonce',
			'extrachill_event_perk_enabled'     => '1',
		);

		extrachill_events_save_event_perk( 42 );

		$this->assertFalse( extrachill_events_perk_enabled( 42 ) );
	}

	public function test_save_is_rejected_without_edit_capability(): void {
		$GLOBALS['event_perk_test']['can_edit'] = false;
		$_POST                                  = $this->post_save_request(
			array(
				'extrachill_event_perk_enabled' => '1',
			)
		);

		extrachill_events_save_event_perk( 42 );

		$this->assertFalse( extrachill_events_perk_enabled( 42 ) );
	}

	public function test_perk_text_is_truncated_to_the_maximum_length(): void {
		$_POST = $this->post_save_request(
			array(
				'extrachill_event_perk_enabled' => '1',
				'extrachill_event_perk_text'    => str_repeat( 'x', EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN + 50 ),
			)
		);

		extrachill_events_save_event_perk( 42 );

		$this->assertSame(
			EXTRACHILL_EVENTS_PERK_TEXT_MAXLEN,
			strlen( extrachill_events_get_perk_text( 42 ) )
		);
	}

	public function test_perk_meta_changes_purge_the_event_page(): void {
		$GLOBALS['event_perk_test']['purged'] = array();

		extrachill_events_purge_on_perk_meta_change( 1, 42, EXTRACHILL_EVENTS_PERK_ENABLED_META );
		extrachill_events_purge_on_perk_meta_change( array( 2 ), 43, EXTRACHILL_EVENTS_PERK_TEXT_META );
		extrachill_events_purge_on_perk_meta_change( 3, 44, '_some_other_meta' );

		$this->assertSame( array( 42, 43 ), $GLOBALS['event_perk_test']['purged'] );
	}
}
