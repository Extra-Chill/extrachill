<?php
/**
 * Tests for in-article content_link_click writes and the crosslink click report (#1436).
 *
 * @package ExtraChill\Analytics
 */

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';
require_once __DIR__ . '/network-hosts-fixture.php';

final class ContentLinkClickTest extends Extrachill_Analytics_TestCase {
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->set_ext_object_cache( true );
		$this->set_request( 'example.org', 'POST' );
		$_SERVER['HTTP_ORIGIN']     = 'http://localhost';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.21';
		$_COOKIE['ec_vid']          = '123e4567-e89b-42d3-a456-426614174000';
		add_filter( 'ec_allowed_redirect_hosts', array( $this, 'network_hosts' ) );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function network_hosts( $hosts ): array {
		return array_merge( $hosts, array( 'artist.extrachill.com' ) );
	}

	private function proof( int $post_id, string $path = '/story/' ): string {
		return extrachill_analytics_pageview_proof( $post_id, $path, 'singular', 'localhost' );
	}

	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'post_id'    => $this->post_id,
				'proof'      => $this->proof( $this->post_id ),
				'dest_host'  => 'localhost',
				'dest_path'  => '/other-story/?utm=x#h',
				'label'      => '  Other   story ',
				'source_url' => 'https://localhost/story/?private=x',
			),
			$overrides
		);
	}

	public function test_same_site_click_is_recorded_with_proven_post(): void {
		$ability = wp_get_ability( 'extrachill/track-content-link-click' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] );

		$id = extrachill_analytics_ability_track_content_link_click( $this->valid_input() );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$rows = $this->event_rows( EC_ANALYTICS_EVENT_CONTENT_LINK );
		$this->assertCount( 1, $rows );
		$this->assertSame( '123e4567-e89b-42d3-a456-426614174000', $rows[0]->visitor_id );
		$data = $this->event_data( $rows[0] );
		$this->assertSame( $this->post_id, (int) $data['post_id'] );
		$this->assertSame( 'localhost', $data['dest_host'] );
		$this->assertSame( '/other-story/', $data['dest_path'] );
		$this->assertSame( 'Other story', $data['label'] );
		$this->assertSame( 'same_site', $data['link_kind'] );
		$this->assertFalse( $data['is_bot'] );
	}

	public function test_cross_site_click_is_marked_network(): void {
		$id = extrachill_analytics_ability_track_content_link_click( $this->valid_input( array( 'dest_host' => 'artist.extrachill.com' ) ) );
		$this->assertIsInt( $id );
		$data = $this->event_data( $this->event_rows( EC_ANALYTICS_EVENT_CONTENT_LINK )[0] );
		$this->assertSame( 'network', $data['link_kind'] );
	}

	public function test_attribution_requires_the_rendered_proof(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cases = array(
			'forged proof'           => array( 'proof' => str_repeat( 'a', 64 ) ),
			'proof for another post' => array( 'post_id' => $other ),
			'proof for another path' => array( 'source_url' => 'https://localhost/elsewhere/' ),
			'empty proof'            => array( 'proof' => '' ),
		);
		foreach ( $cases as $label => $override ) {
			$result = extrachill_analytics_ability_track_content_link_click( $this->valid_input( $override ) );
			$this->assertInstanceOf( WP_Error::class, $result, $label );
			$this->assertSame( 'invalid_content_link_source', $result->get_error_code(), $label );
		}
		$this->assertSame( 0, $this->event_count() );
	}

	public function test_unpublished_source_post_is_rejected(): void {
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$result = extrachill_analytics_ability_track_content_link_click(
			$this->valid_input(
				array(
					'post_id' => $draft,
					'proof'   => $this->proof( $draft ),
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $this->event_count() );
	}

	public function test_off_network_and_url_destinations_are_rejected(): void {
		foreach ( array( 'evil.example', 'localhost.evil.example' ) as $host ) {
			$result = extrachill_analytics_ability_track_content_link_click( $this->valid_input( array( 'dest_host' => $host ) ) );
			$this->assertInstanceOf( WP_Error::class, $result, $host );
			$this->assertSame( 'invalid_network_destination', $result->get_error_code(), $host );
		}
		foreach ( array( 'https://evil.example/x', '//evil.example/x', '' ) as $path ) {
			$this->assertInstanceOf( WP_Error::class, extrachill_analytics_ability_track_content_link_click( $this->valid_input( array( 'dest_path' => $path ) ) ) );
		}
		$this->assertSame( 0, $this->event_count() );
	}

	public function test_network_click_still_rejects_same_host(): void {
		$result = extrachill_analytics_ability_track_network_click(
			array(
				'dest_host'  => 'localhost',
				'dest_path'  => '/x/',
				'label'      => 'x',
				'placement'  => 'main',
				'route'      => 'singular',
				'source_url' => 'https://localhost/story/',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_network_destination', $result->get_error_code() );
	}

	public function test_write_gate_bounds_dimensions(): void {
		foreach ( array(
			'dest_host' => 253,
			'dest_path' => 512,
			'label'     => 80,
			'link_kind' => 20,
			'unknown'   => 0,
		) as $field => $bound ) {
			$result = extrachill_analytics_validate_public_event_data( EC_ANALYTICS_EVENT_CONTENT_LINK, array( $field => str_repeat( 'x', $bound + 1 ) ) );
			$this->assertInstanceOf( WP_Error::class, $result, $field );
		}
	}

	private function seed( string $type, array $data, string $source_url, ?string $visitor = null ): void {
		global $wpdb;
		$wpdb->insert(
			extrachill_analytics_events_table(),
			array(
				'event_type' => $type,
				'event_data' => wp_json_encode( $data ),
				'source_url' => $source_url,
				'blog_id'    => get_current_blog_id(),
				'visitor_id' => $visitor,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);
	}

	public function test_report_joins_clicks_to_pageviews_and_marks_inserted_links(): void {
		$url = get_permalink( $this->post_id );
		update_post_meta(
			$this->post_id,
			'_datamachine_internal_links',
			array(
				'links' => array(
					array( 'url' => 'https://artist.extrachill.com/artists/grateful-dead' ),
				),
			)
		);
		// A crosslinked post nobody clicked must still be listed.
		$quiet = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $quiet, '_datamachine_internal_links', array( 'links' => array( array( 'url' => 'https://localhost/x/' ) ) ) );

		$v1 = '11111111-1111-4111-8111-111111111111';
		$v2 = '22222222-2222-4222-8222-222222222222';
		foreach ( array( $v1, $v1, $v2, $v2 ) as $visitor ) {
			$this->seed( 'pageview', array(
				'post_id' => $this->post_id,
				'is_bot'  => false,
			), $url, $visitor );
		}
		$this->seed( 'pageview', array(
			'post_id' => $this->post_id,
			'is_bot'  => true,
		), $url, null );

		$click = array(
			'post_id'   => $this->post_id,
			'dest_host' => 'artist.extrachill.com',
			'dest_path' => '/artists/grateful-dead',
			'label'     => 'Grateful Dead',
			'link_kind' => 'network',
			'is_bot'    => false,
		);
		$this->seed( EC_ANALYTICS_EVENT_CONTENT_LINK, $click, $url, $v1 );
		$this->seed( EC_ANALYTICS_EVENT_CONTENT_LINK, array_merge( $click, array(
			'dest_host' => 'localhost',
			'dest_path' => '/other/',
			'link_kind' => 'same_site',
		) ), $url, $v2 );
		$this->seed( EC_ANALYTICS_EVENT_CONTENT_LINK, array_merge( $click, array( 'is_bot' => true ) ), $url, null );

		$report = extrachill_analytics_ability_get_crosslink_clicks( array( 'days' => 7 ) );

		$this->assertSame( 2, $report['totals']['posts'] );
		$this->assertSame( 2, $report['totals']['clicks'] );
		$this->assertSame( 4, $report['totals']['pageviews'] );

		$row = $report['posts'][0];
		$this->assertSame( $this->post_id, $row['post_id'] );
		$this->assertSame( 4, $row['pageviews'] );
		$this->assertSame( 2, $row['pageview_viewers'] );
		$this->assertSame( 2, $row['clicks'] );
		$this->assertSame( 2, $row['click_visitors'] );
		$this->assertSame( 0.5, $row['click_rate'] );
		$this->assertSame( 1, $row['inserted_links'] );
		$this->assertSame( 1, $row['inserted_clicks'] );
		$this->assertSame( 1, $row['manual_clicks'] );
		$origins = wp_list_pluck( $row['destinations'], 'origin', 'destination' );
		$this->assertSame( 'inserted', $origins['artist.extrachill.com/artists/grateful-dead/'] );
		$this->assertSame( 'manual', $origins['localhost/other/'] );

		$this->assertSame( $quiet, $report['posts'][1]['post_id'] );
		$this->assertSame( 0, $report['posts'][1]['clicks'] );
	}

	public function test_report_reader_requires_report_access(): void {
		$ability = wp_get_ability( 'extrachill/get-crosslink-clicks' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertFalse( $ability->get_meta()['show_in_rest'] );
	}
}
