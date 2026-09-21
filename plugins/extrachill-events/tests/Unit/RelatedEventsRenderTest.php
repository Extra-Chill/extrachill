<?php
/**
 * Related-events shared-renderer integration tests.
 *
 * Owns its file-level WP doubles because sibling pure suites define the
 * same symbols; a shared suite would make whichever file loads first win.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Files.FileName, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames -- Test doubles and their consuming test intentionally share this fixture; unused params and $list mirror the core signatures.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../fixtures/' );
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		return 'data_machine_events';
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		return false;
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		return false;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( ...$args ) {}
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_related_fixture_terms'][ $taxonomy ] ?? false;
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term ) {
		return 'https://events.example.test/tax/' . $term->term_id . '/';
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = null ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://events.example.test/event/' . $id . '/';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'Event ' . $id;
	}
}
if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return $GLOBALS['ec_related_fixture_thumbs'][ $id ] ?? false;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		return $GLOBALS['ec_related_fixture_post_venues'][ $post_id ] ?? array();
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field, $index_key = null ) {
		$pluck = array();
		foreach ( $list as $key => $value ) {
			$pluck[ $key ] = is_object( $value ) ? $value->$field : $value[ $field ];
		}
		return $pluck;
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'America/New_York' );
	}
}
if ( ! function_exists( 'data_machine_events_query_events' ) ) {
	function data_machine_events_query_events( $args ) {
		$GLOBALS['ec_related_fixture_query_args'] = $args;
		return array( 'posts' => $GLOBALS['ec_related_fixture_query'] );
	}
}
if ( ! function_exists( 'data_machine_events_parse_event_data' ) ) {
	function data_machine_events_parse_event_data( $post ) {
		return $GLOBALS['ec_related_fixture_event_data'][ $post->ID ] ?? null;
	}
}
if ( ! function_exists( 'data_machine_events_render_taxonomy_badges' ) ) {
	function data_machine_events_render_taxonomy_badges( $post_id ) {
		return '<span class="ec-tax-badge">Badge ' . (int) $post_id . '</span>';
	}
}
if ( ! function_exists( 'ec_icon' ) ) {
	function ec_icon( $name ) {
		return '<svg data-icon="' . $name . '"></svg>';
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public function __construct( $id ) {
			$this->ID = $id;
		}
	}
}

$GLOBALS['ec_related_fixture_terms']         = array(
	'venue'    => array(
		(object) array(
			'term_id' => 77,
			'name'    => 'The Hall',
		),
	),
	'location' => array(
		(object) array(
			'term_id' => 88,
			'name'    => 'Charleston County',
		),
	),
);
$GLOBALS['ec_related_fixture_thumbs']        = array(
	601 => 'https://events.example.test/img/601.jpg',
);
$GLOBALS['ec_related_fixture_event_data']    = array(
	601 => array(
		'startDate' => '2026-10-02',
		'startTime' => '19:30:00',
	),
	602 => array(),
);
$GLOBALS['ec_related_fixture_query']         = array();
$GLOBALS['ec_related_fixture_post_venues']   = array(
	601 => array( 90 ),
	602 => array( 91 ),
);
$GLOBALS['ec_related_captured_section_args'] = null;

/**
 * Test double for the theme's shared renderer. Defined lazily inside tests
 * that exercise the rendering path, so the degrade path can be tested with
 * the function undefined.
 *
 * @param array $args Section args from the events plugin.
 */
function ec_related_fake_renderer( array $args ) {
	$GLOBALS['ec_related_captured_section_args'] = $args;
	return '<section>shared-renderer</section>';
}

require_once dirname( __DIR__, 2 ) . '/inc/single-event/related-events.php';

final class RelatedEventsRenderTest extends BookingTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ec_related_captured_section_args'] = null;
		$GLOBALS['ec_related_fixture_query']         = array();
		$GLOBALS['ec_related_fixture_query_args']    = null;
	}

	/**
	 * Define the theme renderer double for rendering-path tests.
	 */
	private function enable_fake_renderer(): void {
		if ( ! function_exists( 'extrachill_render_related_tax_section' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Defines the theme-renderer double only when the rendering path is under test, so the degrade path sees an undefined function.
			eval( 'function extrachill_render_related_tax_section( array $args ) { return ec_related_fake_renderer( $args ); }' );
		}
	}

	public function test_degrades_without_theme_renderer(): void {
		$GLOBALS['ec_related_fixture_query'] = array( new WP_Post( 601 ) );

		$this->assertFalse( function_exists( 'extrachill_render_related_tax_section' ), 'Fixture requires the theme renderer to be undefined for the degrade path.' );

		ec_events_render_related_posts( 'venue', 500 );

		$this->assertSame( '', $this->getActualOutput(), 'Related events must render nothing when the theme renderer is unavailable.' );
		$this->assertNull( $GLOBALS['ec_related_captured_section_args'] );
	}

	public function test_venue_section_feeds_resolved_data_to_shared_renderer(): void {
		$this->enable_fake_renderer();
		$GLOBALS['ec_related_fixture_query'] = array(
			new WP_Post( 601 ),
			new WP_Post( 602 ),
		);

		ob_start();
		ec_events_render_related_posts( 'venue', 500 );
		$output = ob_get_clean();

		$this->assertSame( '<section>shared-renderer</section>', $output );

		$args = $GLOBALS['ec_related_captured_section_args'];
		$this->assertNotNull( $args );
		$this->assertSame( 'More at ', $args['heading_prefix'] );
		$this->assertSame( 'https://events.example.test/tax/77/', $args['term_link'] );

		$this->assertCount( 2, $args['items'] );

		$with_media = $args['items'][0];
		$this->assertSame( 601, $with_media['id'] );
		$this->assertSame( 'block', $with_media['layout'] );
		$this->assertSame( 'https://events.example.test/event/601/', $with_media['permalink'] );
		$this->assertSame( 'Event 601', $with_media['title'] );
		$this->assertSame( '<img src="https://events.example.test/img/601.jpg" alt="Event 601" loading="lazy">', $with_media['thumb_html'] );
		$this->assertSame( '<span class="ec-tax-badge">Badge 601</span>', $with_media['badges_html'] );
		$this->assertStringContainsString( '<svg data-icon="calendar"></svg>', $with_media['meta_html'] );
		$this->assertStringContainsString( '<svg data-icon="clock"></svg>', $with_media['meta_html'] );
		$this->assertStringContainsString( 'More Info</a>', $with_media['meta_html'] );

		$dateless = $args['items'][1];
		$this->assertSame( 602, $dateless['id'] );
		$this->assertSame( '', $dateless['thumb_html'] );
		$this->assertStringNotContainsString( 'ec-related-meta-item', $dateless['meta_html'] );
		$this->assertStringContainsString( 'More Info</a>', $dateless['meta_html'] );
	}

	public function test_location_heading_uses_in_preposition(): void {
		$this->enable_fake_renderer();
		$GLOBALS['ec_related_fixture_query'] = array( new WP_Post( 601 ) );

		ob_start();
		ec_events_render_related_posts( 'location', 500 );
		ob_end_clean();

		$this->assertSame( 'More in ', $GLOBALS['ec_related_captured_section_args']['heading_prefix'] );
	}

	public function test_venue_scoped_query_requests_three_events(): void {
		$this->enable_fake_renderer();
		$GLOBALS['ec_related_fixture_query'] = array( new WP_Post( 601 ) );

		ob_start();
		ec_events_render_related_posts( 'venue', 500 );
		ob_end_clean();

		$args = $GLOBALS['ec_related_fixture_query_args'];
		$this->assertSame( 'upcoming', $args['scope'] );
		$this->assertSame( 3, $args['per_page'] );
		$this->assertSame( array( 500 ), $args['exclude'] );
		$this->assertSame( array( 'venue' => array( 77 ) ), $args['tax_filters'] );
	}

	public function test_meta_html_composes_rows_and_more_info(): void {
		$post      = new WP_Post( 601 );
		$meta_html = ec_events_related_meta_html( $post, 'Fri, Oct 2, 2026', '7:30 PM' );

		$this->assertStringContainsString( '<div class="ec-related-meta-item">', $meta_html );
		$this->assertStringContainsString( '<span>Fri, Oct 2, 2026</span>', $meta_html );
		$this->assertStringContainsString( '<span>7:30 PM</span>', $meta_html );
		$this->assertSame( 2, substr_count( $meta_html, 'ec-related-meta-item' ), 'Date and time must render as two meta rows.' );
		$this->assertStringContainsString( 'class="data-machine-more-info-button button-3 button-small">More Info</a>', $meta_html );

		$dateless = ec_events_related_meta_html( $post, '', '' );
		$this->assertStringNotContainsString( 'ec-related-meta-item', $dateless );
		$this->assertStringContainsString( 'More Info</a>', $dateless );
	}
}
