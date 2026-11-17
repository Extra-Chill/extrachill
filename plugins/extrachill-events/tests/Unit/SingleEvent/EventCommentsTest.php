<?php
/**
 * Single-event WordPress comments (#1632).
 *
 * Pure-PHP with file-level WordPress doubles; runs in
 * phpunit-event-comments-unit.xml.dist on the host runtime.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_post_type_support' ) ) {
	function add_post_type_support( $post_type, $feature ) {
		$GLOBALS['ec_comments_test']['supports'][ $post_type ][ $feature ] = true;
	}
}

if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( $post_type, $feature ) {
		return ! empty( $GLOBALS['ec_comments_test']['supports'][ $post_type ][ $feature ] );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['ec_comments_test']['posts'][ (int) $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['ec_comments_test']['options'][ $name ] ?? $default;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/single-event/comments.php';

final class EventCommentsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_comments_test'] = array(
			'supports' => array(),
			'options'  => array( 'default_comment_status' => 'open' ),
			'posts'    => array(
				714188 => (object) array(
					'ID'             => 714188,
					'post_type'      => 'data_machine_events',
					'comment_status' => 'closed',
				),
				42     => (object) array(
					'ID'             => 42,
					'post_type'      => 'post',
					'comment_status' => 'closed',
				),
			),
		);
		unset( $GLOBALS['ec_test_filters']['extrachill_events_comments_open'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_comments_test'], $GLOBALS['ec_test_filters']['extrachill_events_comments_open'] );
		parent::tearDown();
	}

	private function registered( string $hook, string $callback, int $priority ): bool {
		foreach ( $GLOBALS['ec_test_filters'][ $hook ][ $priority ] ?? array() as $registered ) {
			if ( $callback === $registered[0] ) {
				return true;
			}
		}
		return false;
	}

	public function test_hooks_are_registered(): void {
		$this->assertTrue( $this->registered( 'init', 'extrachill_events_add_comments_support', 5 ) );
		$this->assertTrue( $this->registered( 'comments_open', 'extrachill_events_comments_open', 9 ) );
	}

	public function test_event_post_type_supports_comments(): void {
		$this->assertFalse( post_type_supports( 'data_machine_events', 'comments' ) );

		extrachill_events_add_comments_support();

		$this->assertTrue( post_type_supports( 'data_machine_events', 'comments' ) );
	}

	public function test_legacy_closed_event_opens_when_site_default_is_open(): void {
		extrachill_events_add_comments_support();

		$this->assertTrue( extrachill_events_comments_open( false, 714188 ) );
	}

	public function test_closed_event_stays_closed_when_site_default_is_closed(): void {
		extrachill_events_add_comments_support();
		$GLOBALS['ec_comments_test']['options']['default_comment_status'] = 'closed';

		$this->assertFalse( extrachill_events_comments_open( false, 714188 ) );
	}

	public function test_event_stays_closed_without_comments_support(): void {
		$this->assertFalse( extrachill_events_comments_open( false, 714188 ) );
	}

	public function test_other_post_types_are_untouched(): void {
		extrachill_events_add_comments_support();

		$this->assertFalse( extrachill_events_comments_open( false, 42 ) );
		$this->assertFalse( extrachill_events_comments_open( false, 999 ) );
	}

	public function test_already_open_value_passes_through(): void {
		$GLOBALS['ec_comments_test']['options']['default_comment_status'] = 'closed';

		$this->assertTrue( extrachill_events_comments_open( true, 714188 ) );
	}

	public function test_filter_can_keep_a_specific_event_closed(): void {
		extrachill_events_add_comments_support();
		add_filter(
			'extrachill_events_comments_open',
			static function ( $open, $post ) {
				return 714188 === $post->ID ? false : $open;
			},
			10,
			2
		);

		$this->assertFalse( extrachill_events_comments_open( false, 714188 ) );
	}
}
