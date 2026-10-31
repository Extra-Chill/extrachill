<?php
/**
 * Isolated single-event contextual bridge tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_events_bridge_terms'][ $post_id ][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return $term->url;
	}
}
if ( ! function_exists( 'extrachill_network_bridge_get_cards' ) ) {
	function extrachill_network_bridge_get_cards( ...$args ) {
		return $GLOBALS['ec_events_bridge_network_cards'];
	}
}

require_once dirname( __DIR__, 2 ) . '/inc/single-event/network-bridge.php';

final class SingleEventNetworkBridgeTest extends PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_events_bridge_terms']         = array();
		$GLOBALS['ec_events_bridge_network_cards'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_events_bridge_terms'], $GLOBALS['ec_events_bridge_network_cards'] );
		parent::tearDown();
	}

	private function addTerm( $taxonomy ): object {
		$term = (object) array(
			'name' => 'venue' === $taxonomy ? 'The Pour House' : 'Charleston',
			'url'  => 'https://events.example/' . $taxonomy . '/canonical-term/',
		);
		$GLOBALS['ec_events_bridge_terms'][123][ $taxonomy ] = array( $term );
		return $term;
	}

	private function networkCards(): array {
		return array(
			array( 'site_key' => 'main', 'label' => 'Blog Posts', 'url' => 'https://example.com/coverage' ),
			array( 'site_key' => 'artist', 'label' => 'Artist Platform', 'url' => 'https://artist.example/band' ),
			array( 'site_key' => 'community', 'label' => 'Forum Discussions', 'url' => 'https://community.example/band' ),
		);
	}

	public function test_upcoming_events_prioritize_profile_then_discussion_then_coverage(): void {
		$args = ec_events_network_bridge_args( 123, 'upcoming' );

		$this->assertSame( 123, $args['post_id'] );
		$this->assertSame( array( 'artist', 'main', 'community' ), $args['allowed_site_keys'] );
		$this->assertSame( array( 'artist', 'events', 'community', 'main' ), $args['slot_order'] );
		$this->assertSame( 'Keep Exploring', $args['heading_text'] );
		$this->assertSame( 'ec_events_network_bridge_v2_current_', $args['cache_prefix'] );
	}

	public function test_past_events_prioritize_discovery_then_coverage_profile_and_discussion(): void {
		$args = ec_events_network_bridge_args( 123, 'past' );

		$this->assertSame( array( 'venue', 'events', 'main', 'artist', 'community' ), $args['slot_order'] );
		$this->assertSame( 'ec_events_network_bridge_v2_past_', $args['cache_prefix'] );
	}

	public function test_upcoming_and_ongoing_cards_include_nearby_shows_and_are_bounded_to_three(): void {
		$location = $this->addTerm( 'location' );
		$this->addTerm( 'venue' );
		foreach ( array( 'upcoming', 'ongoing' ) as $timing ) {
			$cards = ec_events_network_bridge_order_cards( ec_events_network_bridge_args( 123, $timing ), $this->networkCards() );

			$this->assertCount( 3, $cards );
			$this->assertSame( array( 'artist', 'events', 'community' ), array_column( $cards, 'site_key' ) );
			$this->assertSame( 'Profile', $cards[0]['label'] );
			$this->assertSame( 'More Shows', $cards[1]['label'] );
			$this->assertSame( 'Charleston', $cards[1]['term_name'] );
			$this->assertSame( get_term_link( $location ), $cards[1]['url'] );
			$this->assertTrue( $cards[1]['is_same_site'] );
		}
	}

	public function test_past_cards_without_local_terms_prioritize_coverage(): void {
		$cards = ec_events_network_bridge_order_cards( ec_events_network_bridge_args( 123, 'past' ), $this->networkCards() );

		$this->assertCount( 3, $cards );
		$this->assertSame( array( 'main', 'artist', 'community' ), array_column( $cards, 'site_key' ) );
		$this->assertSame( array( 'Coverage', 'Profile', 'Discussions' ), array_column( $cards, 'label' ) );
	}

	public function test_past_without_network_cards_offers_venue_and_location(): void {
		$venue    = $this->addTerm( 'venue' );
		$location = $this->addTerm( 'location' );
		$cards    = ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) );

		$this->assertSame( array( 'venue', 'events' ), array_column( $cards, 'site_key' ) );
		$this->assertSame( array( get_term_link( $venue ), get_term_link( $location ) ), array_column( $cards, 'url' ) );
		$this->assertSame( array( 'Upcoming Shows', 'More Shows' ), array_column( $cards, 'label' ) );
		$this->assertSame( array( 'The Pour House', 'Charleston' ), array_column( $cards, 'term_name' ) );
		$this->assertSame( array( true, true ), array_column( $cards, 'is_same_site' ) );
	}

	public function test_past_discovery_precedes_coverage_and_is_capped_at_three(): void {
		$this->addTerm( 'venue' );
		$this->addTerm( 'location' );
		$GLOBALS['ec_events_bridge_network_cards'] = $this->networkCards();
		$cards = ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) );

		$this->assertCount( 3, $cards );
		$this->assertSame( array( 'venue', 'events', 'main' ), array_column( $cards, 'site_key' ) );
		$this->assertSame( 'Coverage', $cards[2]['label'] );
		$this->assertSame( 'Blog Posts', $GLOBALS['ec_events_bridge_network_cards'][0]['label'] );
	}

	public function test_past_without_any_destinations_is_empty(): void {
		$this->assertSame( array(), ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) ) );
	}

	public function test_missing_local_slots_do_not_hide_other_discovery(): void {
		$this->addTerm( 'location' );
		$cards = ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) );
		$this->assertSame( array( 'events' ), array_column( $cards, 'site_key' ) );

		$GLOBALS['ec_events_bridge_terms'] = array();
		$this->addTerm( 'venue' );
		$cards = ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) );
		$this->assertSame( array( 'venue' ), array_column( $cards, 'site_key' ) );
	}

	public function test_invalid_terms_and_links_are_skipped(): void {
		foreach ( array( 'venue', 'location' ) as $taxonomy ) {
			foreach ( array( false, new WP_Error( 'missing' ), array( false ), array( new WP_Error( 'missing' ) ) ) as $terms ) {
				$GLOBALS['ec_events_bridge_terms'][123][ $taxonomy ] = $terms;
				$this->assertSame( array(), ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) ) );
			}
			foreach ( array( '', false, new WP_Error( 'missing_link' ) ) as $url ) {
				$term      = $this->addTerm( $taxonomy );
				$term->url = $url;
				$this->assertSame( array(), ec_events_network_bridge_cards( ec_events_network_bridge_args( 123, 'past' ) ) );
			}
			$GLOBALS['ec_events_bridge_terms'] = array();
		}
	}
}
