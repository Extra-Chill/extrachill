<?php
/**
 * Tests for JSON-LD @type resolution from event_type term meta.
 *
 * @see https://github.com/Extra-Chill/extrachill-seo/issues/64
 * @package ExtraChill\SEO\Tests
 */

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use ExtraChill\SEO\Tests\Support\TermMetaRegistry;
use ExtraChill\SEO\Tests\Support\TermRegistry;
use WP_Post;
use WP_Term;

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;
use function ExtraChill\SEO\Schema\ec_seo_resolve_event_schema_type;

final class SchemaEventTypeTest extends TestCase {

	protected function setUp(): void {
		TermRegistry::reset();
		TermMetaRegistry::reset();
	}

	private function make_post( array $overrides = array() ): WP_Post {
		return new WP_Post(
			array_merge(
				array(
					'ID'           => 486727,
					'post_title'   => 'Extra Chill Meetup',
					'post_content' => '',
					'post_excerpt' => '',
					'post_name'    => 'extra-chill-meetup',
					'post_type'    => 'data_machine_events',
					'permalink'    => 'https://events.example.com/events/extra-chill-meetup',
				),
				$overrides
			)
		);
	}

	private function assign_event_type( WP_Post $post, int $term_id, string $name, string $slug, $schema_type ): void {
		$term = new WP_Term(
			array(
				'term_id'  => $term_id,
				'name'     => $name,
				'slug'     => $slug,
				'taxonomy' => 'event_type',
			)
		);
		TermRegistry::set( $post->ID, 'event_type', array( $term ) );
		if ( null !== $schema_type ) {
			TermMetaRegistry::set( $term_id, '_schema_type', $schema_type );
		}
	}

	public function test_music_event_from_concert_term_meta(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 11, 'Concert', 'concert', 'MusicEvent' );

		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'MusicEvent', $schema['@type'] );
		$this->assertSame( 'MusicEvent', ec_seo_resolve_event_schema_type( $post->ID ) );
	}

	public function test_event_from_other_term_meta(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 12, 'Other', 'other', 'Event' );

		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'Event', $schema['@type'] );
	}

	public function test_comedy_event_from_comedy_term_meta(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 13, 'Comedy', 'comedy', 'ComedyEvent' );

		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'ComedyEvent', $schema['@type'] );
	}

	public function test_falls_back_to_event_when_no_event_type_term(): void {
		$post = $this->make_post();

		$this->assertSame( 'Event', ec_seo_resolve_event_schema_type( $post->ID ) );
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );
		$this->assertSame( 'Event', $schema['@type'] );
	}

	public function test_falls_back_to_event_when_schema_type_meta_missing(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 14, 'Concert', 'concert', null );

		$this->assertSame( 'Event', ec_seo_resolve_event_schema_type( $post->ID ) );
	}

	public function test_falls_back_to_event_when_schema_type_meta_empty(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 15, 'Concert', 'concert', '   ' );

		$this->assertSame( 'Event', ec_seo_resolve_event_schema_type( $post->ID ) );
	}

	public function test_falls_back_to_event_when_schema_type_unrecognised(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 16, 'Hackathon', 'hackathon', 'HackathonEvent' );

		$this->assertSame( 'Event', ec_seo_resolve_event_schema_type( $post->ID ) );
		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );
		$this->assertSame( 'Event', $schema['@type'] );
	}

	public function test_multi_term_uses_first_recognised_schema_type(): void {
		$post = $this->make_post();
		$first = new WP_Term(
			array(
				'term_id'  => 21,
				'name'     => 'Other',
				'slug'     => 'other',
				'taxonomy' => 'event_type',
			)
		);
		$second = new WP_Term(
			array(
				'term_id'  => 22,
				'name'     => 'Comedy',
				'slug'     => 'comedy',
				'taxonomy' => 'event_type',
			)
		);
		TermRegistry::set( $post->ID, 'event_type', array( $first, $second ) );
		TermMetaRegistry::set( 21, '_schema_type', 'Event' );
		TermMetaRegistry::set( 22, '_schema_type', 'ComedyEvent' );

		$this->assertSame( 'Event', ec_seo_resolve_event_schema_type( $post->ID ) );
	}

	public function test_multi_term_skips_unrecognised_then_uses_next_recognised(): void {
		$post = $this->make_post();
		$first = new WP_Term(
			array(
				'term_id'  => 31,
				'name'     => 'Hackathon',
				'slug'     => 'hackathon',
				'taxonomy' => 'event_type',
			)
		);
		$second = new WP_Term(
			array(
				'term_id'  => 32,
				'name'     => 'Comedy',
				'slug'     => 'comedy',
				'taxonomy' => 'event_type',
			)
		);
		TermRegistry::set( $post->ID, 'event_type', array( $first, $second ) );
		TermMetaRegistry::set( 31, '_schema_type', 'HackathonEvent' );
		TermMetaRegistry::set( 32, '_schema_type', 'ComedyEvent' );

		$this->assertSame( 'ComedyEvent', ec_seo_resolve_event_schema_type( $post->ID ) );
	}

	public function test_does_not_derive_type_from_block_event_type_attribute(): void {
		$post = $this->make_post();
		$this->assign_event_type( $post, 17, 'Other', 'other', 'Event' );

		$schema = ec_seo_build_event_schema(
			$post,
			array(
				'startDate' => '2026-04-20',
				'eventType' => 'MusicEvent',
			)
		);

		$this->assertSame( 'Event', $schema['@type'] );
	}

	public function test_music_event_entity_shape_is_unchanged_aside_from_resolved_type(): void {
		$post = $this->make_post(
			array(
				'ID'         => 175555,
				'post_title' => '2nd Annual 420 Festival',
				'post_name'  => '2nd-annual-420-festival',
				'permalink'  => 'https://events.example.com/events/2nd-annual-420-festival',
			)
		);
		$this->assign_event_type( $post, 11, 'Concert', 'concert', 'MusicEvent' );

		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-04-20' ) );

		$this->assertSame( 'MusicEvent', $schema['@type'] );
		$this->assertSame( 'https://events.example.com/events/2nd-annual-420-festival#event', $schema['@id'] );
		$this->assertSame( '2nd Annual 420 Festival', $schema['name'] );
		$this->assertSame( '2026-04-20', $schema['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $schema['eventStatus'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $schema['eventAttendanceMode'] );
	}
}
