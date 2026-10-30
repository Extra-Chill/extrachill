<?php
/**
 * Event schema image resolution (#71).
 *
 * The Event entity must carry the same image as the page's og:image: the
 * featured image when present, otherwise the `extrachill_seo_singular_og_image_url`
 * fallback (e.g. a generated OG card), and no `image` key when neither exists.
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

use function ExtraChill\SEO\Schema\ec_seo_build_event_schema;

final class SchemaEventImageTest extends TestCase {

	private const HOOK = 'extrachill_seo_singular_og_image_url';
	private const CARD = 'https://events.example.com/wp-content/uploads/og-cards/event-1.png';

	protected function setUp(): void {
		TermRegistry::reset();
		TermMetaRegistry::reset();
		VenueDataRegistry::reset();
		unset( $GLOBALS['ec_seo_test_filters'][ self::HOOK ] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ec_seo_test_filters'][ self::HOOK ] );
	}

	private function make_post( array $overrides = array() ): WP_Post {
		return new WP_Post( array_merge( array(
			'ID'           => 486727,
			'post_title'   => 'Meetup',
			'post_content' => '',
			'post_excerpt' => '',
			'post_name'    => 'meetup',
			'post_type'    => 'data_machine_events',
			'permalink'    => 'https://events.example.com/events/meetup',
		), $overrides ) );
	}

	private function register_card( string $url ): void {
		add_filter(
			self::HOOK,
			static function ( $image, $post ) use ( $url ) {
				return $post instanceof WP_Post ? $url : $image;
			},
			10,
			2
		);
	}

	public function test_featured_image_wins_over_the_og_fallback(): void {
		$this->register_card( self::CARD );
		$post = $this->make_post( array( 'thumbnail_url' => 'https://events.example.com/featured.jpg' ) );

		$schema = ec_seo_build_event_schema( $post, array( 'startDate' => '2026-10-21' ) );

		$this->assertSame( 'https://events.example.com/featured.jpg', $schema['image'] );
	}

	public function test_falls_back_to_the_singular_og_image_without_a_thumbnail(): void {
		$this->register_card( self::CARD );

		$schema = ec_seo_build_event_schema( $this->make_post(), array( 'startDate' => '2026-10-21' ) );

		$this->assertSame( self::CARD, $schema['image'] );
	}

	public function test_omits_image_when_no_thumbnail_and_no_fallback(): void {
		$schema = ec_seo_build_event_schema( $this->make_post(), array( 'startDate' => '2026-10-21' ) );

		$this->assertArrayNotHasKey( 'image', $schema );
	}
}
