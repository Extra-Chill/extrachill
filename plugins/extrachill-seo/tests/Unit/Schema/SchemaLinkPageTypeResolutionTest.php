<?php

declare( strict_types=1 );

namespace ExtraChill\SEO\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The Link Page ProfilePage schema must follow the post type of the site
 * serving the request, not a hardcoded literal.
 *
 * extrachill.link is served by the dedicated Link Pages site whose post type
 * is `ec_link_page` (extrachill-link-pages#34). The legacy
 * `artist_link_page` type is no longer registered anywhere.
 */
final class SchemaLinkPageTypeResolutionTest extends TestCase {

	public function test_guard_resolves_type_for_the_serving_site(): void {
		$source = $this->source();

		self::assertStringContainsString(
			'ec_link_page_post_type( get_current_blog_id() )',
			$source,
			'The type must be resolved for the blog rendering the request.'
		);
		self::assertStringContainsString( 'is_singular( $link_page_type )', $source );
	}

	public function test_legacy_post_type_literal_is_gone(): void {
		$source = $this->source();

		self::assertStringNotContainsString( 'artist_link_page', $source, 'The legacy post type must not be referenced.' );
		self::assertSame(
			1,
			substr_count( $source, "'ec_link_page'" ),
			'The literal may appear only as the fallback when the runtime is absent.'
		);
	}

	private function source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/inc/schema/schema-link-page.php' );
	}
}
