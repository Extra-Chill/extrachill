<?php
/**
 * Tests for bounded network_click browser writes.
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

if ( ! function_exists( 'ec_get_allowed_redirect_hosts' ) ) {
	/**
	 * Mirror extrachill-network's helper, which is not loaded in this suite.
	 *
	 * @return string[] Network hosts.
	 */
	function ec_get_allowed_redirect_hosts() {
		return apply_filters( 'ec_allowed_redirect_hosts', array() );
	}
}

final class NetworkClickWriteTest extends Extrachill_Analytics_TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.20';
		$_COOKIE['ec_vid']          = '123e4567-e89b-42d3-a456-426614174000';
		add_filter( 'ec_allowed_redirect_hosts', array( $this, 'network_hosts' ) );
	}

	public function network_hosts( $hosts ): array {
		return array_merge( $hosts, array( 'community.extrachill.com' ) );
	}

	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'dest_host'  => 'community.extrachill.com',
				'dest_path'  => '/recent/?private=x#top',
				'label'      => 'Recent activity',
				'placement'  => 'nav',
				'route'      => 'singular',
				'source_url' => 'https://localhost/story/?private=x',
			),
			$overrides
		);
	}

	public function test_registered_rest_visible_ability_writes_valid_event(): void {
		$ability = wp_get_ability( 'extrachill/track-network-click' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );
		$id = extrachill_analytics_ability_track_network_click( $this->valid_input() );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$rows = $this->event_rows( EC_ANALYTICS_EVENT_NETWORK_CLICK );
		$this->assertCount( 1, $rows );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174000', $rows[0]->visitor_id );
		$data = $this->event_data( $rows[0] );
		$this->assertSame( 'community.extrachill.com', $data['dest_host'] );
		$this->assertSame( '/recent/', $data['dest_path'] );
		$this->assertSame( 'Recent activity', $data['label'] );
		$this->assertContains( EC_ANALYTICS_EVENT_NETWORK_CLICK, extrachill_analytics_experiment_outcome_types() );
	}

	public function test_non_network_and_lookalike_hosts_are_rejected(): void {
		foreach ( array( 'evil.example', 'extrachill.com.evil.example', 'localhost' ) as $host ) {
			$result = extrachill_analytics_ability_track_network_click( $this->valid_input( array( 'dest_host' => $host ) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_network_destination', $result->get_error_code() );
		}
		$this->assertSame( 0, $this->event_count() );
	}

	public function test_fields_are_bounded_and_normalized(): void {
		$result = extrachill_analytics_ability_track_network_click( $this->valid_input( array(
			'dest_path' => '/' . str_repeat( 'x', 600 ),
			'label' => str_repeat( 'x', 100 ),
			'placement' => str_repeat( 'x', 50 ),
			'route' => 'invalid',
		) ) );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_NETWORK_CLICK )[0] );
		$this->assertSame( 512, strlen( $data['dest_path'] ) );
		$this->assertSame( 80, strlen( $data['label'] ) );
		$this->assertSame( 40, strlen( $data['placement'] ) );
		$this->assertSame( 'other', $data['route'] );
	}

	public function test_write_gate_rejects_oversized_and_unknown_dimensions(): void {
		foreach ( array( 'dest_host' => 253, 'dest_path' => 512, 'label' => 80, 'placement' => 40, 'route' => 20, 'unknown' => 0 ) as $field => $bound ) {
			$result = extrachill_analytics_validate_public_event_data( EC_ANALYTICS_EVENT_NETWORK_CLICK, array( $field => str_repeat( 'x', $bound + 1 ) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
		}
	}

	public function test_invalid_destination_paths_are_rejected(): void {
		foreach ( array( 'https://evil.example/path', '//evil.example/path', '' ) as $path ) {
			$this->assertInstanceOf( WP_Error::class, extrachill_analytics_ability_track_network_click( $this->valid_input( array( 'dest_path' => $path ) ) ) );
		}
		$this->assertSame( 0, $this->event_count() );
	}

	public function test_is_bot_is_server_owned(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		$result = extrachill_analytics_ability_track_network_click( $this->valid_input( array( 'is_bot' => false ) ) );
		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_NETWORK_CLICK )[0] );
		$this->assertTrue( (bool) $data['is_bot'] );
	}
}
