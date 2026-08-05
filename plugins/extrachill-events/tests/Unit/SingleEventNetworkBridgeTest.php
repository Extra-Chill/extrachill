<?php
/**
 * Single-event contextual bridge tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return false;
	}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_events_bridge_terms'][ $post_id ][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example/location/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'extrachill_network_bridge_tag_url' ) ) {
	function extrachill_network_bridge_tag_url( $url, $site_key, $source ) {
		return $url . '?utm_source=' . $source . '&utm_campaign=' . $site_key;
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/single-event/network-bridge.php';

final class SingleEventNetworkBridgeTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		register_post_type( 'data_machine_events' );
		register_taxonomy( 'location', 'data_machine_events' );
		$GLOBALS['ec_events_bridge_terms']          = array();
		$GLOBALS['ec_events_by_term_relationships'] = array();
	}

	public function test_upcoming_events_prioritize_profile_then_discussion_then_coverage(): void {
		$args = ec_events_network_bridge_args( 123, 'upcoming' );

		$this->assertSame( 123, $args['post_id'] );
		$this->assertSame( array( 'artist', 'main', 'community' ), $args['allowed_site_keys'] );
		$this->assertSame( array( 'artist', 'events', 'community', 'main' ), $args['slot_order'] );
		$this->assertSame( 'Keep Exploring', $args['heading_text'] );
		$this->assertSame( 'ec_events_network_bridge_v2_current_', $args['cache_prefix'] );
	}

	public function test_past_events_prioritize_coverage_then_profile_then_discussion(): void {
		$args = ec_events_network_bridge_args( 456, 'past' );

		$this->assertSame( array( 'main', 'artist', 'community', 'events' ), $args['slot_order'] );
		$this->assertSame( 'ec_events_network_bridge_v2_past_', $args['cache_prefix'] );
	}

	public function test_upcoming_cards_include_nearby_shows_and_are_bounded_to_three(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'data_machine_events' ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'location',
				'name'     => 'Charleston',
				'slug'     => 'charleston-sc',
			)
		);
		wp_set_object_terms( $post_id, array( $term_id ), 'location' );
		$resolved_cards = array(
			array( 'site_key' => 'main', 'label' => 'Blog Posts', 'url' => 'https://example.com/coverage' ),
			array( 'site_key' => 'artist', 'label' => 'Artist Platform', 'url' => 'https://artist.example/band' ),
			array( 'site_key' => 'community', 'label' => 'Forum Discussions', 'url' => 'https://community.example/band' ),
		);
		$cards          = ec_events_network_bridge_order_cards( ec_events_network_bridge_args( $post_id, 'upcoming' ), $resolved_cards );

		$this->assertCount( 3, $cards );
		$this->assertSame( array( 'artist', 'events', 'community' ), array_column( $cards, 'site_key' ) );
		$this->assertSame( 'Profile', $cards[0]['label'] );
		$this->assertSame( 'More Shows', $cards[1]['label'] );
		$this->assertSame( 'Charleston', $cards[1]['term_name'] );
		$this->assertSame( get_term_link( $term_id, 'location' ), $cards[1]['url'] );
		$this->assertTrue( $cards[1]['is_same_site'] );
	}

	public function test_past_cards_do_not_add_nearby_shows_and_prioritize_coverage(): void {
		$resolved_cards = array(
			array( 'site_key' => 'community', 'label' => 'Forum Discussions', 'url' => 'https://community.example/band' ),
			array( 'site_key' => 'artist', 'label' => 'Artist Platform', 'url' => 'https://artist.example/band' ),
			array( 'site_key' => 'main', 'label' => 'Blog Posts', 'url' => 'https://example.com/coverage' ),
		);

		$cards = ec_events_network_bridge_order_cards( ec_events_network_bridge_args( 456, 'past' ), $resolved_cards );

		$this->assertCount( 3, $cards );
		$this->assertSame( array( 'main', 'artist', 'community' ), array_column( $cards, 'site_key' ) );
		$this->assertSame( array( 'Coverage', 'Profile', 'Discussions' ), array_column( $cards, 'label' ) );
	}
}
