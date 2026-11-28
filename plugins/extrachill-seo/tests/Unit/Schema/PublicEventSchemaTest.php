<?php
/**
 * Public event schema seam (Extra-Chill/extrachill#1802).
 *
 * `ec_seo_get_event_schema()` must return the same Event entity the event
 * page graph emits, plus `@context`, so partner sites never drift from it.
 *
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermMetaRegistry;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use ExtraChill\SEO\Tests\Support\VenueDataRegistry;
use WP_Post;

use function ExtraChill\SEO\Schema\ec_seo_emit_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_get_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_normalize_schema_graph;

final class PublicEventSchemaTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		TermMetaRegistry::reset();
		VenueDataRegistry::reset();
		$GLOBALS['ec_seo_test_posts']      = array();
		$GLOBALS['ec_seo_test_blog_id']    = 7;
		$GLOBALS['ec_seo_test_blog_stack'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_seo_test_posts'], $GLOBALS['ec_seo_test_blog_stack'], $GLOBALS['ec_seo_test_is_singular'], $GLOBALS['ec_seo_test_queried_object'] );
		$GLOBALS['ec_seo_test_blog_id'] = 7;
	}

	private function event( array $overrides = array() ): WP_Post {
		$post = new WP_Post(
			array_merge(
				array(
					'ID'           => 4242,
					'post_title'   => 'Sam &#038; Dave Live',
					'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2026-09-19","startTime":"20:00","venue":"Lo-Fi Brewing","performer":"Sam &#038; Dave","performerType":"MusicGroup","price":"$10","eventStatus":"EventScheduled"} /-->',
					'permalink'    => 'https://events.extrachill.com/events/sam-dave-live',
				),
				$overrides
			)
		);
		$GLOBALS['ec_seo_test_posts'][ $post->ID ] = $post;
		return $post;
	}

	public function test_returns_page_entity_with_context(): void {
		$post   = $this->event();
		$schema = ec_seo_get_event_schema( $post->ID );

		$this->assertIsArray( $schema );
		$this->assertSame( 'https://schema.org', $schema['@context'] );
		$this->assertContains( $schema['@type'], array( 'Event', 'MusicEvent' ) );
		$this->assertSame( 'Sam & Dave Live', $schema['name'] );

		// Byte-identical (minus @context) to what the page graph emits.
		$GLOBALS['ec_seo_test_is_singular']    = true;
		$GLOBALS['ec_seo_test_queried_object'] = $post;
		$graph                                 = ec_seo_normalize_schema_graph( ec_seo_emit_event_schema( array() ) );
		unset( $schema['@context'] );
		$this->assertCount( 1, $graph );
		$this->assertSame( $graph[0], $schema );
	}

	public function test_rejects_missing_unpublished_and_foreign_posts(): void {
		$this->assertNull( ec_seo_get_event_schema( 0 ) );
		$this->assertNull( ec_seo_get_event_schema( 999 ) );
		$this->assertNull( ec_seo_get_event_schema( $this->event( array( 'ID' => 1, 'post_status' => 'draft' ) )->ID ) );
		$this->assertNull( ec_seo_get_event_schema( $this->event( array( 'ID' => 2, 'post_type' => 'post' ) )->ID ) );
		$this->assertNull( ec_seo_get_event_schema( $this->event( array( 'ID' => 3, 'post_content' => '<p>No block</p>' ) )->ID ) );
	}

	public function test_switches_to_events_site_and_restores_caller(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/SitemapHealthStubs.php';
		$post                           = $this->event();
		$GLOBALS['ec_seo_test_blog_id'] = 1;

		$this->assertIsArray( ec_seo_get_event_schema( $post->ID ) );
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['ec_seo_test_blog_stack'] );
	}
}
