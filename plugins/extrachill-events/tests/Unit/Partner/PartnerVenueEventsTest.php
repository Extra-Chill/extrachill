<?php
/**
 * Partner venue events contract (Extra-Chill/extrachill#1802).
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\PartnerVenueEvents;
use ExtraChillEvents\Core\VenuePartnerKey;
use PHPUnit\Framework\TestCase;

final class PartnerVenueEventsTest extends TestCase {

	private string $key;

	protected function setUp(): void {
		$GLOBALS['partner_meta']          = array();
		$GLOBALS['partner_blog']          = 7;
		$GLOBALS['partner_stack']         = array();
		$GLOBALS['partner_allowed']       = true;
		$GLOBALS['partner_writes']        = 0;
		$GLOBALS['partner_deleted_terms'] = array();
		$GLOBALS['partner_thumbnails']    = array();
		$GLOBALS['partner_booking']       = array();
		$GLOBALS['partner_venue_data']    = array( 1524 => array( 'timezone' => 'America/New_York' ) );
		$GLOBALS['partner_posts']         = array(
			101 => (object) array( 'ID' => 101, 'post_title' => 'Show A', 'post_excerpt' => '<p>An evening &amp; more.</p>' ),
			102 => (object) array( 'ID' => 102, 'post_title' => 'Show B', 'post_excerpt' => '' ),
			103 => (object) array( 'ID' => 103, 'post_title' => 'Show C', 'post_excerpt' => '' ),
			104 => (object) array( 'ID' => 104, 'post_title' => 'Show D', 'post_excerpt' => '' ),
		);
		$GLOBALS['partner_event_data']    = array(
			101 => array( 'performer' => 'Fallback Band', 'price' => '$10', 'ticketUrl' => 'https://seller.example/buy?aff=1' ),
			102 => array( 'performer' => 'Solo Act' ),
			103 => array( 'eventStatus' => 'EventCancelled', 'ticketUrl' => 'https://seller.example/x' ),
			104 => array(),
		);
		// Page entities as ec_seo_build_event_schema() emits them for our own
		// event pages: offers.url is the outside seller, Place.url the venue's
		// own website, organizer.url a promoter site.
		$GLOBALS['partner_schemas'] = array(
			101 => array(
				'@context'            => 'https://schema.org',
				'@type'               => 'MusicEvent',
				'@id'                 => 'https://events.extrachill.com/events/show-a/#event',
				'name'                => 'Show A & Friends',
				'url'                 => 'https://events.extrachill.com/events/show-a/',
				'eventStatus'         => 'https://schema.org/EventScheduled',
				'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
				'image'               => 'https://events.extrachill.com/og/101.png',
				'location'            => array(
					'@type' => 'Place',
					'name'  => 'Lo-Fi Brewing',
					'url'   => 'https://www.lofibrewing.com/',
				),
				'organizer'           => array(
					'@type' => 'Organization',
					'name'  => 'Pixel Promotions',
					'url'   => 'https://pixelpromo.example/',
				),
				'performer'           => array(
					array( '@type' => 'MusicGroup', 'name' => 'Band One' ),
					array( '@type' => 'MusicGroup', 'name' => 'Band Two' ),
				),
				'offers'              => array(
					'@type'         => 'Offer',
					'price'         => '10',
					'priceCurrency' => 'USD',
					'availability'  => 'https://schema.org/InStock',
					'url'           => 'https://www.eventbrite.com/e/pixel-chix-tickets-123456789',
				),
			),
			102 => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'MusicEvent',
				'name'        => 'Show B',
				'url'         => 'https://events.extrachill.com/events/show-b/',
				'eventStatus' => 'https://schema.org/EventScheduled',
				'location'    => array(
					'@type' => 'Place',
					'name'  => 'Lo-Fi Brewing',
					'url'   => 'https://www.lofibrewing.com/',
				),
				'performer'   => array( '@type' => 'MusicGroup', 'name' => 'Solo Act' ),
				'offers'      => array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => '10',
					'highPrice'     => '15',
					'priceCurrency' => 'USD',
					'url'           => 'https://www.eventbrite.com/e/show-b-tickets-1',
					'offers'        => array(
						array( '@type' => 'Offer', 'price' => '10', 'url' => 'https://www.eventbrite.com/e/show-b-tickets-1?tier=ga' ),
						array( '@type' => 'Offer', 'price' => '15', 'url' => 'https://www.ticketmaster.com/show-b/event/2' ),
					),
				),
			),
			103 => array(
				'@context'    => 'https://schema.org',
				'@type'       => 'MusicEvent',
				'name'        => 'Show C',
				'eventStatus' => 'https://schema.org/EventCancelled',
				'offers'      => array(
					array( '@type' => 'Offer', 'url' => 'https://seller.example/x' ),
					array( '@type' => 'Offer', 'url' => 'https://seller.example/y' ),
				),
			),
		);
		$offers = array(
			102 => array( 'enabled' => true, 'sale_open' => true, 'remaining' => 5, 'price_minor' => 1250, 'currency' => 'usd' ),
			104 => array( 'enabled' => true, 'sale_open' => false, 'remaining' => 0, 'price_minor' => 1000, 'currency' => 'usd' ),
		);
		$GLOBALS['partner_abilities'] = array(
			'data-machine-events/query-events'  => new PartnerAbilityDouble(
				static function () {
					return array(
						'posts' => array(
							array( 'event_id' => 101, 'title' => 'Show A &amp; Friends', 'permalink' => 'https://events.extrachill.com/events/show-a/', 'start_datetime' => '2026-12-01 20:00:00', 'end_datetime' => null ),
							array( 'event_id' => 102, 'title' => 'Show B', 'permalink' => 'https://events.extrachill.com/events/show-b/', 'start_datetime' => '2026-07-04 19:30:00', 'end_datetime' => '2026-07-04 23:00:00' ),
							array( 'event_id' => 103, 'title' => 'Show C', 'permalink' => 'https://events.extrachill.com/events/show-c/', 'start_datetime' => '2026-12-02 20:00:00', 'end_datetime' => '2026-12-02 20:00:00' ),
							array( 'event_id' => 104, 'title' => 'Show D', 'permalink' => 'https://events.extrachill.com/events/show-d/', 'start_datetime' => '2026-12-03 20:00:00', 'end_datetime' => null ),
						),
						'total' => 4,
					);
				}
			),
			'extrachill/event-schema'           => new PartnerAbilityDouble(
				static function ( $input ) {
					return $GLOBALS['partner_schemas'][ $input['post_id'] ] ?? new WP_Error( 'extrachill_event_schema_unavailable', 'none', array( 'status' => 404 ) );
				}
			),
			'extrachill/get-event-ticket-offer' => new PartnerAbilityDouble(
				static function ( $input ) use ( $offers ) {
					return $offers[ $input['event'] ] ?? array( 'enabled' => false, 'sale_open' => false, 'remaining' => null, 'price_minor' => 0, 'currency' => 'usd' );
				}
			),
		);

		$this->key = VenuePartnerKey::manage( 1524, 'issue' )['key'];
	}

	public function test_contract_shape_for_the_keyed_venue(): void {
		$doc = PartnerVenueEvents::for_key( $this->key, 20 );

		$this->assertSame( array( 'venue', 'events', 'generated_at', 'cache_ttl' ), array_keys( $doc ) );
		$this->assertSame( 300, $doc['cache_ttl'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $doc['generated_at'] );
		$this->assertSame(
			array(
				'id'                => 1524,
				'name'              => 'Lo-Fi & Brewing',
				'slug'              => 'lo-fi-brewing',
				'url'               => 'https://events.extrachill.com/venue/lo-fi-brewing/',
				'booking_embed_url' => null,
			),
			$doc['venue']
		);

		// The venue comes from the key only.
		$query = $GLOBALS['partner_abilities']['data-machine-events/query-events']->calls[0];
		$this->assertSame( array( 'venue' => array( 1524 ) ), $query['tax_filters'] );
		$this->assertSame( 'upcoming', $query['scope'] );
		$this->assertSame( 20, $query['per_page'] );

		$keys = array( 'id', 'title', 'url', 'start', 'end', 'timezone', 'performers', 'price', 'image', 'excerpt', 'status', 'ticket', 'schema' );
		foreach ( $doc['events'] as $event ) {
			$this->assertSame( $keys, array_keys( $event ) );
			$this->assertSame( array( 'url', 'label', 'kind' ), array_keys( $event['ticket'] ) );
		}

		$a = $doc['events'][0];
		$this->assertSame( 101, $a['id'] );
		$this->assertSame( 'Show A & Friends', $a['title'] );
		$this->assertSame( '2026-12-01T20:00:00-05:00', $a['start'] );
		$this->assertNull( $a['end'] );
		$this->assertSame( 'America/New_York', $a['timezone'] );
		$this->assertSame( array( 'Band One', 'Band Two' ), $a['performers'], 'performers follow the JSON-LD' );
		$this->assertSame( '$10', $a['price'] );
		$this->assertSame( 'https://events.extrachill.com/og/101.png', $a['image'] );
		$this->assertSame( 'An evening & more.', $a['excerpt'] );
		$this->assertSame( 'EventScheduled', $a['status'] );
		$this->assertSame( 'https://schema.org', $a['schema']['@context'] );
		$this->assertSame( 'MusicEvent', $a['schema']['@type'] );
		// Outside seller: the event page, never the seller/affiliate URL.
		$this->assertSame( array( 'url' => 'https://events.extrachill.com/events/show-a/', 'label' => 'Get Tickets', 'kind' => 'external' ), $a['ticket'] );
		$this->assertStringNotContainsString( 'seller.example', json_encode( $doc ) );
	}

	public function test_ticket_kinds_dates_and_fallbacks(): void {
		$GLOBALS['partner_thumbnails'][102] = 'https://events.extrachill.com/thumb-102.jpg';
		$events                             = PartnerVenueEvents::for_key( $this->key, 20 )['events'];

		$b = $events[1];
		$this->assertSame( '2026-07-04T19:30:00-04:00', $b['start'], 'DST offset comes from the venue zone' );
		$this->assertSame( '2026-07-04T23:00:00-04:00', $b['end'] );
		$this->assertSame( array( 'Solo Act' ), $b['performers'] );
		$this->assertSame( '$12.50', $b['price'] );
		$this->assertSame( 'https://events.extrachill.com/thumb-102.jpg', $b['image'], 'no schema image: featured image' );
		$this->assertNull( $b['excerpt'] );
		$this->assertSame( array( 'url' => 'https://events.extrachill.com/events/show-b/', 'label' => 'Get Tickets', 'kind' => 'checkout' ), $b['ticket'] );

		$c = $events[2];
		$this->assertNull( $c['end'], 'end identical to start is no end' );
		$this->assertSame( 'EventCancelled', $c['status'] );
		$this->assertSame( array( 'url' => null, 'label' => 'Cancelled', 'kind' => 'none' ), $c['ticket'] );

		$d = $events[3];
		$this->assertNull( $d['schema'], 'schema is null when the SEO entity is unavailable' );
		$this->assertSame( array(), $d['performers'] );
		$this->assertNull( $d['image'] );
		$this->assertSame( array( 'url' => null, 'label' => 'Sold Out', 'kind' => 'none' ), $d['ticket'] );
	}

	public function test_info_ticket_when_no_seller(): void {
		$GLOBALS['partner_event_data'][101] = array();
		$a                                  = PartnerVenueEvents::for_key( $this->key, 1 )['events'][0];
		$this->assertSame( array( 'url' => 'https://events.extrachill.com/events/show-a/', 'label' => 'Event Details', 'kind' => 'info' ), $a['ticket'] );
	}

	public function test_limit_caps_events(): void {
		$doc = PartnerVenueEvents::for_key( $this->key, 2 );
		$this->assertCount( 2, $doc['events'] );
	}

	public function test_booking_embed_url_requires_enabled_allowlisted_booking(): void {
		$GLOBALS['partner_booking'][1524] = array( 'enabled' => true, 'embed' => array( 'allowed_parent_origins' => array() ) );
		$this->assertNull( PartnerVenueEvents::for_key( $this->key )['venue']['booking_embed_url'] );

		$GLOBALS['partner_booking'][1524] = array( 'enabled' => true, 'embed' => array( 'allowed_parent_origins' => array( 'https://lofibrewing.com' ) ) );
		$this->assertSame( 'https://events.extrachill.com/venue/lo-fi-brewing/?booking-embed=1', PartnerVenueEvents::for_key( $this->key )['venue']['booking_embed_url'] );
	}

	public function test_unusable_keys_are_one_401(): void {
		$bad = array( '', 'garbage', 'ecpk_1524_' . str_repeat( 'a', 64 ), str_replace( 'ecpk_1524_', 'ecpk_1525_', $this->key ) );
		foreach ( $bad as $key ) {
			$error = PartnerVenueEvents::for_key( $key );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'extrachill_partner_key_invalid', $error->code );
			$this->assertSame( 401, $error->data['status'] );
		}

		VenuePartnerKey::manage( 1524, 'revoke' );
		$this->assertSame( 401, PartnerVenueEvents::for_key( $this->key )->data['status'] );
		$this->assertSame( array(), $GLOBALS['partner_abilities']['data-machine-events/query-events']->calls, 'no data is read for a bad key' );
	}

	public function test_deleted_venue_key_is_401(): void {
		$GLOBALS['partner_deleted_terms'][1524] = true;
		$this->assertSame( 'extrachill_partner_key_invalid', PartnerVenueEvents::for_key( $this->key )->code );
	}

	public function test_limit_out_of_range_is_400_and_missing_query_is_503(): void {
		$this->assertSame( 400, PartnerVenueEvents::for_key( $this->key, 0 )->data['status'] );
		$this->assertSame( 400, PartnerVenueEvents::for_key( $this->key, 51 )->data['status'] );

		unset( $GLOBALS['partner_abilities']['data-machine-events/query-events'] );
		$this->assertSame( 503, PartnerVenueEvents::for_key( $this->key )->data['status'] );
	}

	public function test_ability_registration_keeps_key_off_wp_abilities_rest(): void {
		$GLOBALS['partner_registered'] = array();
		( new \ExtraChillEvents\Abilities\PartnerVenueEventsAbilities() )->register();
		$args = $GLOBALS['partner_registered']['extrachill/get-partner-venue-events'];

		$this->assertFalse( $args['meta']['show_in_rest'] );
		$this->assertSame( array( 'partner_key' ), $args['input_schema']['required'] );
		$this->assertSame( array( 'partner_key', 'limit' ), array_keys( $args['input_schema']['properties'] ) );
		$this->assertSame( 50, $args['input_schema']['properties']['limit']['maximum'] );
		$this->assertSame( 20, $args['input_schema']['properties']['limit']['default'] );

		$doc = ( $args['execute_callback'] )( array( 'partner_key' => $this->key, 'limit' => 1 ) );
		$this->assertSame( 1524, $doc['venue']['id'] );
		$this->assertSame( $args['output_schema']['required'], array_keys( $doc ) );
		$this->assertSame( $args['output_schema']['properties']['events']['items']['required'], array_keys( $doc['events'][0] ) );
	}

	public function test_external_ticket_schema_offer_routes_through_extra_chill(): void {
		$doc = PartnerVenueEvents::for_key( $this->key, 20 );
		$a   = $doc['events'][0];

		$this->assertSame( 'external', $a['ticket']['kind'] );
		$this->assertSame( $a['ticket']['url'], $a['schema']['offers']['url'], 'schema.offers.url equals ticket.url' );
		$this->assertSame( '10', $a['schema']['offers']['price'], 'the rest of the offer is the page entity' );
		$this->assertSame( 'https://schema.org/InStock', $a['schema']['offers']['availability'] );
		$this->assertSame( 'Lo-Fi Brewing', $a['schema']['location']['name'] );
		$this->assertArrayNotHasKey( 'url', $a['schema']['location'], 'off-network venue website is dropped' );
		$this->assertSame( array( '@type' => 'Organization', 'name' => 'Pixel Promotions' ), $a['schema']['organizer'] );
		$this->assertSame( 'https://events.extrachill.com/events/show-a/#event', $a['schema']['@id'] );

		$this->assertOnlyExtraChillHosts( $a );
	}

	public function test_checkout_aggregate_offer_and_nested_offers_route_through_extra_chill(): void {
		$b = PartnerVenueEvents::for_key( $this->key, 20 )['events'][1];

		$this->assertSame( 'checkout', $b['ticket']['kind'] );
		$offers = $b['schema']['offers'];
		$this->assertSame( 'AggregateOffer', $offers['@type'] );
		$this->assertSame( $b['ticket']['url'], $offers['url'] );
		$this->assertSame( '10', $offers['lowPrice'] );
		$this->assertCount( 2, $offers['offers'] );
		foreach ( $offers['offers'] as $offer ) {
			$this->assertSame( $b['ticket']['url'], $offer['url'] );
		}

		$this->assertOnlyExtraChillHosts( $b );
	}

	public function test_offer_list_without_ticket_url_drops_offer_urls(): void {
		$c = PartnerVenueEvents::for_key( $this->key, 20 )['events'][2];

		$this->assertNull( $c['ticket']['url'] );
		$this->assertSame( array( array( '@type' => 'Offer' ), array( '@type' => 'Offer' ) ), $c['schema']['offers'] );
		$this->assertOnlyExtraChillHosts( $c );
	}

	public function test_offer_list_with_ticket_url_points_every_offer_at_it(): void {
		$GLOBALS['partner_schemas'][101]['offers'] = array(
			array( '@type' => 'Offer', 'price' => '10', 'url' => 'https://www.eventbrite.com/e/a' ),
			array( '@type' => 'Offer', 'price' => '20' ),
		);
		$a = PartnerVenueEvents::for_key( $this->key, 1 )['events'][0];

		$this->assertSame( 'https://events.extrachill.com/events/show-a/', $a['ticket']['url'] );
		$this->assertSame(
			array(
				array( '@type' => 'Offer', 'price' => '10', 'url' => $a['ticket']['url'] ),
				array( '@type' => 'Offer', 'price' => '20', 'url' => $a['ticket']['url'] ),
			),
			$a['schema']['offers']
		);
	}

	public function test_offsite_schema_image_is_not_published(): void {
		$GLOBALS['partner_schemas'][101]['image'] = array( 'https://cdn.seller.example/poster.jpg' );
		$a = PartnerVenueEvents::for_key( $this->key, 1 )['events'][0];

		$this->assertSame( array(), $a['schema']['image'] );
		$this->assertNull( $a['image'] );
		$this->assertOnlyExtraChillHosts( $a );
	}

	public function test_whole_response_has_no_offsite_host(): void {
		$GLOBALS['partner_booking'][1524] = array( 'enabled' => true, 'embed' => array( 'allowed_parent_origins' => array( 'https://www.lofibrewing.com' ) ) );
		$this->assertOnlyExtraChillHosts( PartnerVenueEvents::for_key( $this->key, 20 ) );
	}

	/**
	 * Every URL host in the serialized value is Extra Chill (or a schema.org
	 * vocabulary IRI, which is an identifier, not a destination).
	 *
	 * @param mixed $value Response fragment.
	 */
	private function assertOnlyExtraChillHosts( $value ): void {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		preg_match_all( '#(?:[a-z][a-z0-9+.-]*:)?//([^/"?\\#:\s]+)#i', $json, $matches );
		$this->assertNotEmpty( $matches[1] );
		foreach ( $matches[1] as $host ) {
			$this->assertMatchesRegularExpression( '/^(?:[a-z0-9-]+\.)*(?:extrachill\.com|schema\.org)$/i', $host, "off-network host in partner response: {$host}" );
		}
		$this->assertStringNotContainsString( 'eventbrite', $json );
		$this->assertStringNotContainsString( 'ticketmaster', $json );
		$this->assertStringNotContainsString( 'seller.example', $json );
		$this->assertStringNotContainsString( 'lofibrewing', $json );
	}
}
