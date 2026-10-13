<?php
/** Tests for the context-aware "add your show" CTAs (#958). */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/class-wpterm.php';

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://events.example' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string { return $url . '?' . implode( '&', array_map( static fn( $k, $v ) => $k . '=' . $v, array_keys( $args ), $args ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomy = '' ) { return ( $GLOBALS['submit_cta_tax'] ?? '' ) === $taxonomy; }
}
if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() { return $GLOBALS['submit_cta_term'] ?? null; }
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $id, $key, $single = false ) { return $GLOBALS['submit_cta_term_meta'][ $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) { return $GLOBALS['submit_cta_post_terms'][ $post_id ][ $taxonomy ] ?? false; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $text, $domain = 'default' ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); }
}

require_once dirname( __DIR__, 3 ) . '/inc/core/submit-ctas.php';

/** @covers ::extrachill_events_submit_url */
final class SubmitCtasTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['submit_cta_tax']        = '';
		$GLOBALS['submit_cta_term']       = null;
		$GLOBALS['submit_cta_term_meta']  = array();
		$GLOBALS['submit_cta_post_terms'] = array();
		$_GET                             = array();
	}

	private function term( int $id, string $name ): WP_Term {
		return new WP_Term(
			(object) array(
				'term_id' => $id,
				'name'    => $name,
				'slug'    => strtolower( str_replace( ' ', '-', $name ) ),
			)
		);
	}

	private function render( callable $fn, ...$args ): string {
		ob_start();
		$fn( ...$args );
		return (string) ob_get_clean();
	}

	public function test_submit_url_carries_encoded_context(): void {
		$this->assertSame( 'https://events.example/submit/', extrachill_events_submit_url() );
		$this->assertSame( 'https://events.example/submit/?venue=The%20Pour%20House&city=Charleston', extrachill_events_submit_url( 'The Pour House', 'Charleston' ) );
		$this->assertSame( 'https://events.example/submit/?city=Austin', extrachill_events_submit_url( '', 'Austin' ) );
	}

	public function test_venue_archive_cta_names_venue_and_prefills_city(): void {
		$GLOBALS['submit_cta_tax']                     = 'venue';
		$GLOBALS['submit_cta_term']                    = $this->term( 11, 'The Pour House' );
		$GLOBALS['submit_cta_term_meta'][11]['_venue_city'] = 'Charleston';

		$html = $this->render( 'extrachill_events_render_submit_cta_archive' );

		$this->assertStringContainsString( 'Missing a show at The Pour House?', $html );
		$this->assertStringContainsString( 'venue=The%20Pour%20House&amp;city=Charleston', $html );
		$this->assertStringContainsString( 'data-ec-track="events_submit_cta"', $html );
		$this->assertStringContainsString( 'data-ec-track-placement="venue_archive"', $html );
	}

	public function test_location_archive_cta_names_city(): void {
		$GLOBALS['submit_cta_tax']  = 'location';
		$GLOBALS['submit_cta_term'] = $this->term( 22, 'Atlanta' );

		$html = $this->render( 'extrachill_events_render_submit_cta_archive' );

		$this->assertStringContainsString( 'Playing in Atlanta?', $html );
		$this->assertStringContainsString( '/submit/?city=Atlanta', $html );
		$this->assertStringContainsString( 'data-ec-track-placement="location_archive"', $html );
	}

	public function test_other_archives_render_nothing(): void {
		$GLOBALS['submit_cta_tax']  = 'artist';
		$GLOBALS['submit_cta_term'] = $this->term( 33, 'Some Band' );

		$this->assertSame( '', $this->render( 'extrachill_events_render_submit_cta_archive' ) );
	}

	public function test_single_event_link_uses_event_venue_and_city(): void {
		$GLOBALS['submit_cta_post_terms'][501]['venue'] = array( $this->term( 44, 'Ours Studios' ) );
		$GLOBALS['submit_cta_term_meta'][44]['_venue_city'] = 'Decatur';

		$html = $this->render( 'extrachill_events_render_submit_cta_single', 501, '' );

		$this->assertStringContainsString( 'Add a show at Ours Studios', $html );
		$this->assertStringContainsString( 'venue=Ours%20Studios&amp;city=Decatur', $html );
		$this->assertStringContainsString( 'data-ec-track-placement="single_event"', $html );
	}

	public function test_single_event_without_venue_falls_back_to_location(): void {
		$GLOBALS['submit_cta_post_terms'][502]['location'] = array( $this->term( 55, 'Oakland' ) );

		$html = $this->render( 'extrachill_events_render_submit_cta_single', 502, '' );

		$this->assertStringContainsString( '>Add a show<', $html );
		$this->assertStringContainsString( '/submit/?city=Oakland', $html );
	}

	public function test_prefill_is_sanitized_and_capped(): void {
		$_GET = array(
			'venue' => '<b>The Pour House</b>',
			'city'  => str_repeat( 'x', 500 ),
		);

		$prefill = extrachill_events_submit_prefill();

		$this->assertSame( 'The Pour House', $prefill['venue'] );
		$this->assertSame( EXTRACHILL_EVENTS_SUBMIT_PREFILL_MAX, strlen( $prefill['city'] ) );
	}

	public function test_prefill_ignores_non_scalar_input(): void {
		$_GET = array( 'venue' => array( 'x' ) );

		$this->assertSame( '', extrachill_events_submit_prefill()['venue'] );
	}

	public function test_hooks_are_registered_at_expected_priorities(): void {
		$registered = static function ( string $hook, int $priority ): array {
			return array_column( $GLOBALS['ec_test_filters'][ $hook ][ $priority ] ?? array(), 0 );
		};
		$this->assertContains( 'extrachill_events_render_submit_cta_archive', $registered( 'extrachill_archive_below_calendar', 15 ) );
		$this->assertContains( 'extrachill_events_render_submit_cta_single', $registered( 'data_machine_events_action_buttons', 25 ) );
	}
}
