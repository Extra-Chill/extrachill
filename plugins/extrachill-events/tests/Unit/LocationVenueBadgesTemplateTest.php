<?php
/**
 * Location venue directory template tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Focused template fixture intentionally declares WordPress doubles beside its test.

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/** Minimal REST request fixture used by the production template. */
	class WP_REST_Request {
		/** Store query parameters. */
		public function set_query_params( array $params ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		}
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/** Return the location term fixture. */
	function get_queried_object() {
		return $GLOBALS['location_venue_badges_test_term'];
	}
}

if ( ! function_exists( 'rest_do_request' ) ) {
	/** Return venue rows for the template's established REST adapter. */
	function rest_do_request() {
		return new LocationVenueBadgesTemplateResponse( $GLOBALS['location_venue_badges_test_rows'] );
	}
}

if ( ! function_exists( '_n' ) ) {
	/** Return the correct untranslated singular or plural test string. */
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/** Format fixture counts. */
	function number_format_i18n( $number ) {
		return number_format( (float) $number );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/** Escape test HTML. */
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/** Escape test attributes. */
	function esc_attr( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/** Return known-safe fixture URLs. */
	function esc_url( $value ) {
		return (string) $value;
	}
}

/** Minimal REST response fixture for the venue badge template. */
final class LocationVenueBadgesTemplateResponse {
	/** @var array<int, array<string, mixed>> */
	private array $data;

	/** @param array<int, array<string, mixed>> $data Venue rows. */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/** The fixture is always successful. */
	public function is_error(): bool {
		return false;
	}

	/** @return array<int, array<string, mixed>> */
	public function get_data(): array {
		return $this->data;
	}
}

/** Proves the directory remains in markup but starts collapsed. */
final class LocationVenueBadgesTemplateTest extends PHPUnit\Framework\TestCase {
	/** Render deterministic fixtures through the production template. */
	public function test_large_directory_uses_native_collapsed_disclosure(): void {
		$GLOBALS['location_venue_badges_test_term'] = (object) array(
			'slug'     => 'chicago',
			'taxonomy' => 'location',
		);
		$GLOBALS['test_queried_term']               = $GLOBALS['location_venue_badges_test_term'];
		$GLOBALS['location_venue_badges_test_rows'] = array(
			array( 'term_id' => 3, 'name' => 'Zulu Hall', 'slug' => 'zulu-hall', 'url' => '/venue/zulu-hall/', 'count' => 4 ),
			array( 'term_id' => 1, 'name' => 'Alpha Room', 'slug' => 'alpha-room', 'url' => '/venue/alpha-room/', 'count' => 8 ),
			array( 'term_id' => 2, 'name' => 'Beta Club', 'slug' => 'beta-club', 'url' => '/venue/beta-club/', 'count' => 4 ),
		);

		$output = $this->render_template();

		$this->assertStringContainsString( '<details class="location-archive-venue-directory">', $output );
		$this->assertStringNotContainsString( '<details class="location-archive-venue-directory" open', $output );
		$this->assertStringContainsString( 'Browse 3 venues', $output );
		$this->assertSame( 3, substr_count( $output, 'class="taxonomy-badge venue-badge' ) );
		$this->assertLessThan( strpos( $output, 'Zulu Hall' ), strpos( $output, 'Beta Club' ) );
	}

	/** Include the template with WordPress boundary stubs. */
	private function render_template(): string {
		ob_start();
		include dirname( __DIR__, 2 ) . '/inc/templates/location-venue-badges.php';
		return (string) ob_get_clean();
	}
}
