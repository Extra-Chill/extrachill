<?php
/**
 * Custom-host route views stay off unless the host runtime opts a route in.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

/**
 * Verify the `extrachill_analytics_custom_host_route_view_allowed` opt-in.
 */
final class CustomHostRouteViewTest extends Extrachill_Analytics_TestCase {
	/**
	 * A non-singular route on a custom (non-network) host.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->set_request( 'links.example', 'GET' );
		$_SERVER['REQUEST_URI'] = '/edit';
		wp_dequeue_script( 'extrachill-view-tracking' );
		wp_deregister_script( 'extrachill-view-tracking' );
	}

	/**
	 * Remove the opt-in so it never leaks into other tests.
	 */
	public function tear_down(): void {
		remove_all_filters( 'extrachill_analytics_custom_host_route_view_allowed' );
		wp_dequeue_script( 'extrachill-view-tracking' );
		wp_deregister_script( 'extrachill-view-tracking' );

		parent::tear_down();
	}

	/**
	 * Route-level collection is first-party only by default.
	 */
	public function test_custom_host_route_view_is_not_tracked_by_default(): void {
		extrachill_analytics_enqueue_view_tracking();

		$this->assertFalse( wp_script_is( 'extrachill-view-tracking', 'enqueued' ) );
	}

	/**
	 * The runtime that owns the host can opt one of its routes in.
	 */
	public function test_host_runtime_can_opt_a_route_in(): void {
		$seen = null;
		add_filter(
			'extrachill_analytics_custom_host_route_view_allowed',
			static function ( $allowed, $source_path ) use ( &$seen ) {
				$seen = $source_path;
				return '/edit' === $source_path ? true : $allowed;
			},
			10,
			2
		);

		extrachill_analytics_enqueue_view_tracking();

		$this->assertSame( '/edit', $seen );
		$this->assertTrue( wp_script_is( 'extrachill-view-tracking', 'enqueued' ) );
		$config = (string) wp_scripts()->get_data( 'extrachill-view-tracking', 'before' )[1];
		$this->assertStringContainsString( '"postId":0', $config );
		$this->assertStringContainsString( '"sourcePath":"\/edit"', $config );
	}

	/**
	 * The editor-save event type is part of the shared vocabulary.
	 */
	public function test_link_page_saved_event_type_is_defined(): void {
		$this->assertSame( 'artist_link_page_saved', EC_ANALYTICS_EVENT_ARTIST_LINK_PAGE_SAVED );
	}
}
