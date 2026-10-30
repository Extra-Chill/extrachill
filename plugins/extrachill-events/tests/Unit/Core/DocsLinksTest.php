<?php
/** Events screens link to the docs page that explains them. */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) ); }
}

require_once dirname( __DIR__, 3 ) . '/inc/core/docs-links.php';

final class DocsLinksTest extends TestCase {
	private const ROOT = __DIR__ . '/../../../';

	public function test_builds_events_calendar_doc_url(): void {
		$url = extrachill_events_docs_url( 'add-your-tour' );
		$this->assertMatchesRegularExpression( '#^https://[^/]+/events-calendar/add-your-tour/$#', $url );
	}

	public function test_slug_is_sanitized(): void {
		$this->assertStringEndsWith( '/events-calendar/venue-booking-inquiries/', extrachill_events_docs_url( 'Venue Booking Inquiries' ) );
	}

	/** @dataProvider screen_links */
	public function test_screen_links_its_doc( string $file, string $slug, int $expected ): void {
		$source = (string) file_get_contents( self::ROOT . $file );
		$this->assertSame( $expected, substr_count( $source, "extrachill_events_docs_url( '{$slug}' )" ), $file );
	}

	public static function screen_links(): array {
		return array(
			'submit form, logged in and logged out' => array( 'blocks/event-submission/render.php', 'add-your-tour', 2 ),
			'venue booking button'                  => array( 'inc/templates/archive.php', 'venue-booking-inquiries', 1 ),
			'home search'                           => array( 'inc/core/account-market.php', 'finding-live-music', 1 ),
		);
	}

	public function test_helper_is_loaded_with_public_experience(): void {
		$source = (string) file_get_contents( self::ROOT . 'inc/Providers/PublicExperienceProvider.php' );
		$this->assertStringContainsString( "inc/core/docs-links.php'", $source );
	}
}
