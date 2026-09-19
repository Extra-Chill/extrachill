<?php
/**
 * End-to-end guarantee: no HTML entity survives into emitted JSON-LD.
 *
 * Drives the REAL serialization pipeline — event, breadcrumb, and webpage
 * emitters collected through ec_seo_output_schema_graph() — for an event
 * whose stored title carries the raw `&#038;` entity (the production shape
 * of Extra-Chill/extrachill-seo#61). Every <script type="application/ld+json">
 * block is parsed, every emitted string value is swept for entity patterns,
 * and the schema.org contract (types, dates, structure) is asserted unchanged.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use ExtraChill\SEO\Tests\Support\VenueDataRegistry;
use WP_Post;
use WP_Term;

use function ExtraChill\SEO\Schema\ec_seo_decode_schema_text;
use function ExtraChill\SEO\Schema\ec_seo_normalize_schema_graph;
use function ExtraChill\SEO\Schema\ec_seo_output_schema_graph;

final class SchemaGraphEntityDecodeTest extends TestCase {

	/**
	 * Any HTML entity that survives into a JSON-LD value is indexed
	 * literally by search engines; none may be emitted.
	 */
	private const ENTITY_PATTERN = '/&(#\d+|[a-z]+);/i';

	private const EVENT_TITLE     = 'Jane Rundquist &#038; Too Blue';
	private const EVENT_EXCERPT   = 'Event: Jane Rundquist &amp; Too Blue When: Sep 19, 2026 at 8:00 PM. Where: Carousel Lounge, Austin.';
	private const EVENT_PERMALINK = 'https://events.extrachill.com/events/jane-rundquist-too-blue-5';

	protected function setUp(): void {
		TermRegistry::reset();
		VenueDataRegistry::reset();
		$GLOBALS['ec_seo_test_is_404'] = false;
	}

	/**
	 * Stage a singular event query mirroring the live page from the issue:
	 * entity-encoded title, entity-bearing excerpt, entity-bearing performer
	 * block attr, a ticket URL with a query string, and venue/location terms.
	 */
	private function stage_single_event( array $overrides = array() ): WP_Post {
		$post = new WP_Post(
			array_merge(
				array(
					'ID'           => 9001,
					'post_title'   => self::EVENT_TITLE,
					'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-09-19","startTime":"20:00","venue":"Carousel Lounge","performer":"Sam &#038; Dave","performerType":"MusicGroup","ticketUrl":"https://tickets.example.com/buy?ref=ec&amt=2","eventStatus":"EventScheduled"} /-->',
					'post_excerpt' => self::EVENT_EXCERPT,
					'post_name'    => 'jane-rundquist-too-blue-5',
					'post_type'    => 'data_machine_events',
					'permalink'    => self::EVENT_PERMALINK,
				),
				$overrides
			)
		);

		$GLOBALS['ec_seo_test_queried_object'] = $post;
		$GLOBALS['ec_seo_test_is_singular']    = true;
		$GLOBALS['ec_seo_test_is_404']         = false;

		$venue    = new WP_Term(
			array(
				'term_id'  => 55,
				'name'     => 'Carousel Lounge',
				'slug'     => 'carousel-lounge',
				'taxonomy' => 'venue',
			)
		);
		$location = new WP_Term(
			array(
				'term_id'  => 66,
				'name'     => 'Austin',
				'slug'     => 'austin',
				'taxonomy' => 'location',
			)
		);
		TermRegistry::set( $post->ID, 'venue', array( $venue ) );
		TermRegistry::set( $post->ID, 'location', array( $location ) );
		VenueDataRegistry::set(
			55,
			array(
				'name'     => 'Carousel Lounge',
				'city'     => 'Austin',
				'state'    => 'TX',
				'timezone' => 'America/Chicago',
			)
		);

		return $post;
	}

	/**
	 * Simulate the events-site integration: a raw-entity document title
	 * separator and a breadcrumb override built from get_the_title(), the
	 * same production data flow that leaks `&#038;` into BreadcrumbList.
	 */
	private function add_events_site_filters(): void {
		add_filter(
			'document_title_separator',
			static function () {
				return '&#8211;';
			},
			1000
		);

		add_filter(
			'extrachill_seo_breadcrumb_items',
			static function ( $items ) {
				unset( $items );
				$post  = $GLOBALS['ec_seo_test_queried_object'];
				$venue = TermRegistry::get( $post->ID, 'venue' );

				return array(
					array(
						'name' => 'Extra Chill',
						'url'  => 'https://extrachill.com',
					),
					array(
						'name' => 'Events Calendar',
						'url'  => home_url( '/' ),
					),
					array(
						'name' => $venue[0]->name,
						'url'  => get_term_link( $venue[0] ),
					),
					array(
						'name' => get_the_title(),
						'url'  => '',
					),
				);
			},
			10
		);
	}

	/**
	 * Run the real wp_head emitter and return the captured HTML plus every
	 * parsed ld+json graph (the same blocks `curl | grep` would extract).
	 *
	 * @return array{ 0: string, 1: array<int, array<string, mixed>> }
	 */
	private function capture_emitted_graphs(): array {
		ob_start();
		ec_seo_output_schema_graph();
		$html = (string) ob_get_clean();

		preg_match_all( '/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches );

		$graphs = array();
		foreach ( $matches[1] as $json ) {
			$decoded = json_decode( $json, true );
			$this->assertNotNull( $decoded, 'Emitted JSON-LD must parse: ' . $json );
			$this->assertSame( JSON_ERROR_NONE, json_last_error() );
			$graphs[] = $decoded;
		}

		return array( $html, $graphs );
	}

	/**
	 * @param array<string|int, mixed> $value
	 * @param array<int, string>       $out
	 */
	private function collect_strings( $value, array &$out ): void {
		if ( is_string( $value ) ) {
			$out[] = $value;
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$this->collect_strings( $child, $out );
			}
		}
	}

	/**
	 * @param array<int, mixed>       $graphs
	 * @param array<int, string>      $types
	 * @return array<int, array<string, mixed>>
	 */
	private function entities_of_types( array $graphs, array $types ): array {
		$found = array();
		foreach ( $graphs as $graph ) {
			foreach ( $graph['@graph'] ?? array() as $entity ) {
				if ( is_array( $entity ) && in_array( $entity['@type'] ?? '', $types, true ) ) {
					$found[] = $entity;
				}
			}
		}
		return $found;
	}

	public function test_full_pipeline_emits_no_html_entities_anywhere(): void {
		$this->stage_single_event();
		$this->add_events_site_filters();

		list( $html, $graphs ) = $this->capture_emitted_graphs();

		$this->assertNotEmpty( $graphs, 'The pipeline must emit at least one ld+json block.' );

		$all_strings = array();
		foreach ( $graphs as $graph ) {
			$this->collect_strings( $graph, $all_strings );
		}

		$violations = array_values(
			array_filter(
				$all_strings,
				static function ( string $value ) {
					return 1 === preg_match( self::ENTITY_PATTERN, $value );
				}
			)
		);

		$this->assertSame(
			array(),
			$violations,
			"HTML entities must never survive into JSON-LD values. Offenders:\n" . implode( "\n", $violations )
		);

		// Belt and braces: the live offenders from the issue must not appear
		// anywhere in the raw emitted markup either.
		$this->assertStringNotContainsString( '&#038;', $html );
		$this->assertStringNotContainsString( '&amp;', $html );
		$this->assertStringNotContainsString( '&#8211;', $html );
	}

	public function test_required_schema_properties_unchanged_after_normalization(): void {
		$this->stage_single_event();
		$this->add_events_site_filters();

		list( $html, $graphs ) = $this->capture_emitted_graphs();

		$this->assertCount( 1, $graphs );
		$this->assertSame( 'https://schema.org', $graphs[0]['@context'] );
		$this->assertNotEmpty( $graphs[0]['@graph'] );

		// MusicEvent: same shape, values decoded exactly once.
		list( $event ) = $this->entities_of_types( $graphs, array( 'MusicEvent' ) );
		$this->assertSame( self::EVENT_PERMALINK . '#event', $event['@id'] );
		$this->assertSame( self::EVENT_PERMALINK, $event['url'] );
		$this->assertSame( 'Jane Rundquist & Too Blue', $event['name'] );
		$this->assertSame( '2026-09-19T20:00:00-05:00', $event['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $event['eventStatus'] );
		$this->assertSame( 'Event: Jane Rundquist & Too Blue When: Sep 19, 2026 at 8:00 PM. Where: Carousel Lounge, Austin.', $event['description'] );
		$this->assertSame( 'Carousel Lounge', $event['location']['name'] );
		$this->assertSame( 'Austin', $event['location']['address']['addressLocality'] );
		$this->assertSame( 'Sam & Dave', $event['performer']['name'] );
		// URL values: literal ampersands preserved, entities would be decoded.
		$this->assertSame( 'https://tickets.example.com/buy?ref=ec&amt=2', $event['offers']['url'] );

		// BreadcrumbList: the get_the_title() override is decoded too.
		list( $breadcrumb ) = $this->entities_of_types( $graphs, array( 'BreadcrumbList' ) );
		$items              = $breadcrumb['itemListElement'];
		$this->assertCount( 4, $items );
		$this->assertSame( 1, $items[0]['position'] );
		$this->assertSame( 'Extra Chill', $items[0]['name'] );
		$this->assertSame( 'Events Calendar', $items[1]['name'] );
		$this->assertSame( 'Carousel Lounge', $items[2]['name'] );
		$this->assertSame( 'https://events.extrachill.com/venue/carousel-lounge', $items[2]['item'] );
		$this->assertSame( 'Jane Rundquist & Too Blue', $items[3]['name'] );
		$this->assertArrayNotHasKey( 'item', $items[3] );

		// WebPage: document title (with raw entity separator) is decoded,
		// description carries the excerpt decoded exactly once.
		list( $webpage ) = $this->entities_of_types( $graphs, array( 'WebPage' ) );
		$this->assertSame(
			'Jane Rundquist & Too Blue at Carousel Lounge, Austin, (Sep 19, 2026) – Extra Chill Events',
			$webpage['name']
		);
		$this->assertSame(
			'Event: Jane Rundquist & Too Blue When: Sep 19, 2026 at 8:00 PM. Where: Carousel Lounge, Austin.',
			$webpage['description']
		);
		$this->assertSame( self::EVENT_PERMALINK . '#webpage', $webpage['@id'] );
		$this->assertSame( self::EVENT_PERMALINK . '#breadcrumb', $webpage['breadcrumb']['@id'] );
		$this->assertSame( 'en-US', $webpage['inLanguage'] );
		$this->assertArrayHasKey( 'datePublished', $webpage );
	}

	/**
	 * A title that legitimately contains the literal text `&amp;` (stored
	 * double-encoded) must be decoded exactly ONE layer, not flattened.
	 */
	public function test_double_encoded_title_decodes_exactly_one_layer(): void {
		$this->stage_single_event(
			array(
				'ID'           => 9002,
				'post_title'   => 'A &amp;amp; B',
				'post_excerpt' => 'Show &amp;amp; tell.',
				'post_name'    => 'a-amp-b',
				'permalink'    => 'https://events.extrachill.com/events/a-amp-b',
			)
		);
		$this->add_events_site_filters();

		list( $html, $graphs ) = $this->capture_emitted_graphs();

		list( $event ) = $this->entities_of_types( $graphs, array( 'MusicEvent' ) );
		$this->assertSame( 'A &amp; B', $event['name'] );
		$this->assertSame( 'Show &amp; tell.', $event['description'] );

		list( $webpage ) = $this->entities_of_types( $graphs, array( 'WebPage' ) );
		$this->assertSame( 'A &amp; B at Carousel Lounge, Austin, (Sep 19, 2026) – Extra Chill Events', $webpage['name'] );

		// The remaining `&amp;` is intentional single-layer output; the
		// pipeline must not have run a second decode pass over it.
		$this->assertStringContainsString( 'A &amp; B', $html );
	}

	public function test_decode_schema_text_leaves_plain_ampersands_untouched(): void {
		$this->assertSame( 'Acoustic & Electric', ec_seo_decode_schema_text( 'Acoustic & Electric' ) );
		$this->assertSame( 'Jane & Too Blue', ec_seo_decode_schema_text( 'Jane &#038; Too Blue' ) );
		$this->assertSame( 'Jane & Too Blue', ec_seo_decode_schema_text( 'Jane &amp; Too Blue' ) );
		$this->assertSame( '–', ec_seo_decode_schema_text( '&#x2013;' ) );

		// Query-string safety: a legacy entity pattern WITHOUT a semicolon
		// (e.g. `&copy=` inside a URL) must not be decoded.
		$this->assertSame( '?a=1&copy=2', ec_seo_decode_schema_text( '?a=1&copy=2' ) );
		$this->assertSame( '?ref=ec&amt=2', ec_seo_decode_schema_text( '?ref=ec&amt=2' ) );
	}

	public function test_decode_schema_text_is_idempotent(): void {
		$raw = 'One &#038; Two &amp; Three &#8211; End';
		$one = ec_seo_decode_schema_text( $raw );

		$this->assertSame( 'One & Two & Three – End', $one );
		$this->assertSame( $one, ec_seo_decode_schema_text( $one ) );
	}

	public function test_normalize_schema_graph_walks_nested_values_and_preserves_scalars(): void {
		$graph = array(
			array(
				'@type'                   => 'MusicEvent',
				'@id'                     => 'https://events.example.com/events/watts-co#event',
				'name'                    => 'Watts &#038; Co.',
				'startDate'               => '2026-09-19',
				'isAccessibleForFree'     => false,
				'maximumAttendeeCapacity' => 250,
				'additionalProperty'      => null,
				'location'                => array(
					'@type'   => 'Place',
					'name'    => 'Hall &#038; Garden',
					'address' => array(
						'@type'           => 'PostalAddress',
						'addressLocality' => 'Austin &amp; Beyond',
					),
				),
				'performer'               => array(
					'@type' => 'MusicGroup',
					'name'  => 'Sam &#038; Dave',
				),
				'offers'                  => array(
					'@type' => 'Offer',
					'price' => '25.00',
					'url'   => 'https://tickets.example.com/watts?ref=ec&amt=2',
				),
				'sameAs'                  => array(
					'https://x.com/watts',
					'https://instagram.com/watts &#038; co',
				),
			),
			array(
				'@type' => 'WebPage',
				'name'  => 'Plain Title',
			),
		);

		$normalized = ec_seo_normalize_schema_graph( $graph );

		$event = $normalized[0];
		$this->assertSame( 'MusicEvent', $event['@type'] );
		$this->assertSame( 'https://events.example.com/events/watts-co#event', $event['@id'] );
		$this->assertSame( 'Watts & Co.', $event['name'] );
		$this->assertSame( '2026-09-19', $event['startDate'] );
		$this->assertFalse( $event['isAccessibleForFree'] );
		$this->assertSame( 250, $event['maximumAttendeeCapacity'] );
		$this->assertNull( $event['additionalProperty'] );
		$this->assertSame( 'Hall & Garden', $event['location']['name'] );
		$this->assertSame( 'Austin & Beyond', $event['location']['address']['addressLocality'] );
		$this->assertSame( 'Sam & Dave', $event['performer']['name'] );
		$this->assertSame( '25.00', $event['offers']['price'] );
		$this->assertSame( 'https://tickets.example.com/watts?ref=ec&amt=2', $event['offers']['url'] );
		$this->assertSame( 'https://instagram.com/watts & co', $event['sameAs'][1] );
		$this->assertSame( 'Plain Title', $normalized[1]['name'] );

		// The input graph is never mutated: emitters stay reusable.
		$this->assertSame( 'Watts &#038; Co.', $graph[0]['name'] );
	}
}
