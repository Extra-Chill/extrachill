<?php
/**
 * Calendar-style pass card (extrachill#1127).
 *
 * When an event has neither custom pass art nor a poster, the pass resolver
 * falls through to 'card' and the pass renders an events-calendar-style card:
 * taxonomy badges, title, and time.
 *
 * Pure-PHP unit test sharing the rsvp-perks-unit process. Stubs are guarded
 * with function_exists(); where an earlier file in the suite already defines
 * a stub (get_post, get_option, get_permalink, get_the_title), this file
 * feeds that stub's globals instead of redefining it.
 *
 * The badge renderer (data_machine_events_render_taxonomy_badges) is
 * declared at runtime by the badge test, so the degrade test must run first;
 * it skips if the renderer already exists.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO -- File intentionally pairs WP function shims with the test case class.

if ( ! defined( 'EXTRACHILL_EVENTS_PLUGIN_DIR' ) ) {
	define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', dirname( __DIR__, 3 ) . '/' );
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['event_perk_test']['meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['event_perk_test']['images'] ?? array(), true );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post = null ) {
		return (int) ( $GLOBALS['event_perk_test']['thumbnails'][ (int) $post ] ?? 0 );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['event_management_test']['posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		return 'Event ' . (int) $post;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		return 'https://events.extrachill.com/events/' . $post->ID;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default_value = false ) {
		return $GLOBALS['event_management_test']['options'][ $key ] ?? $default_value;
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

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = false ) {
		return gmdate( (string) $format, false === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		unset( $status );
		return in_array( $handle, $GLOBALS['pass_card_test']['registered_styles'] ?? array(), true );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle ) {
		$GLOBALS['pass_card_test']['enqueued_styles'][] = $handle;
	}
}

if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
	function datamachine_get_event_dates( int $post_id ): ?object {
		return $GLOBALS['pass_card_test']['dates'][ $post_id ] ?? null;
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/admin/event-perks.php';

final class RsvpPassCardTest extends PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_perk_test']                  = array(
			'meta'       => array(),
			'images'     => array( 900, 950 ),
			'thumbnails' => array(),
		);
		$GLOBALS['event_management_test']['posts']   = array( 42 => (object) array( 'ID' => 42 ) );
		$GLOBALS['event_management_test']['options'] = array( 'time_format' => 'g:i A' );
		$GLOBALS['pass_card_test']                   = array(
			'registered_styles' => array( 'wp-block-data-machine-events-calendar' ),
			'enqueued_styles'   => array(),
			'badges'            => '',
			'dates'             => array(
				42 => (object) array(
					'start_datetime' => '2026-06-03 20:00:00',
					'end_datetime'   => '2026-06-03 23:00:00',
				),
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['event_perk_test'], $GLOBALS['event_management_test'], $GLOBALS['pass_card_test'] );
		parent::tearDown();
	}

	public function test_without_a_badge_renderer_the_card_is_title_and_time(): void {
		if ( function_exists( 'data_machine_events_render_taxonomy_badges' ) ) {
			$this->markTestSkipped( 'Badge renderer already declared; the degrade path cannot be exercised in this process.' );
		}

		$html = extrachill_events_render_pass_card( 42 );

		$this->assertStringContainsString( 'class="data-machine-event-item ec-rsvp-pass__card"', $html );
		$this->assertStringContainsString( '<h4 class="data-machine-event-title">', $html );
		$this->assertStringContainsString( 'Event 42</a>', $html );
		$this->assertStringContainsString( 'Wed, Jun 3 · 8:00 - 11:00 PM', $html );
		$this->assertStringNotContainsString( 'data-machine-taxonomy-badges', $html );
	}

	public function test_card_renders_calendar_badges_title_and_time(): void {
		if ( ! function_exists( 'data_machine_events_render_taxonomy_badges' ) ) {
			// Declared at runtime so the degrade test above sees it missing.
			function data_machine_events_render_taxonomy_badges( int $post_id ): string { // phpcs:ignore Squiz.PHP.InnerFunctions.NotAllowed -- Runtime declaration is the point; see file docblock.
				unset( $post_id );
				return $GLOBALS['pass_card_test']['badges'] ?? '';
			}
		}
		$GLOBALS['pass_card_test']['badges'] = '<div class="data-machine-taxonomy-badges"><a class="data-machine-taxonomy-badge data-machine-taxonomy-location" href="#">Charleston</a><a class="data-machine-taxonomy-badge data-machine-taxonomy-venue" href="#">Burgundy Lounge</a><a class="data-machine-taxonomy-badge data-machine-taxonomy-promoter" href="#">Extra Chill</a></div>';

		$html = extrachill_events_render_pass_card( 42 );

		$badges_at = strpos( $html, 'data-machine-taxonomy-badges' );
		$title_at  = strpos( $html, 'data-machine-event-title' );
		$time_at   = strpos( $html, 'data-machine-event-time' );

		$this->assertNotFalse( $badges_at );
		$this->assertStringContainsString( 'Burgundy Lounge', $html );
		$this->assertStringContainsString( 'data-machine-taxonomy-promoter', $html );
		$this->assertStringContainsString( 'href="https://events.extrachill.com/events/42"', $html );
		$this->assertLessThan( $title_at, $badges_at, 'Badges sit above the title, like the calendar item.' );
		$this->assertLessThan( $time_at, $title_at, 'The time sits below the title.' );
		$this->assertStringContainsString( 'Wed, Jun 3 · 8:00 - 11:00 PM', $html );
	}

	public function test_card_enqueues_the_calendar_stylesheet_when_registered(): void {
		extrachill_events_render_pass_card( 42 );
		$this->assertSame( array( 'wp-block-data-machine-events-calendar' ), $GLOBALS['pass_card_test']['enqueued_styles'] );

		$GLOBALS['pass_card_test']['registered_styles'] = array();
		$GLOBALS['pass_card_test']['enqueued_styles']   = array();
		extrachill_events_render_pass_card( 42 );
		$this->assertSame( array(), $GLOBALS['pass_card_test']['enqueued_styles'] );
	}

	public function test_card_is_empty_for_a_missing_event(): void {
		$this->assertSame( '', extrachill_events_render_pass_card( 404 ) );
	}

	public function test_card_omits_the_time_row_without_event_dates(): void {
		$GLOBALS['pass_card_test']['dates'] = array();

		$html = extrachill_events_render_pass_card( 42 );

		$this->assertStringContainsString( 'Event 42</a>', $html );
		$this->assertStringNotContainsString( 'data-machine-event-time', $html );
	}

	public function test_time_is_start_only_for_the_sentinel_or_multi_day_end(): void {
		$GLOBALS['pass_card_test']['dates'][42]->end_datetime = '2026-06-03 23:59:59';
		$this->assertSame( 'Wed, Jun 3 · 8:00 PM', extrachill_events_get_pass_card_time( 42 ) );

		$GLOBALS['pass_card_test']['dates'][42]->end_datetime = '2026-06-06 22:00:00';
		$this->assertSame( 'Wed, Jun 3 · 8:00 PM', extrachill_events_get_pass_card_time( 42 ) );
	}

	public function test_time_shows_a_same_night_range_with_both_meridiems(): void {
		$GLOBALS['pass_card_test']['dates'][42]->end_datetime = '2026-06-04 02:00:00';
		$this->assertSame( 'Wed, Jun 3 · 8:00 PM - 2:00 AM', extrachill_events_get_pass_card_time( 42 ) );
	}

	public function test_pass_source_order_is_custom_then_poster_then_card(): void {
		$this->assertSame( 'card', extrachill_events_get_pass_image_source( 42 ) );
		$this->assertSame( 0, extrachill_events_get_pass_image_id( 42 ) );
		$this->assertSame( '', extrachill_events_get_pass_image_url( 42 ) );

		$GLOBALS['event_perk_test']['thumbnails'][42] = 950;
		$this->assertSame( 'poster', extrachill_events_get_pass_image_source( 42 ) );

		extrachill_events_set_perk_image_id( 42, 900 );
		$this->assertSame( 'custom', extrachill_events_get_pass_image_source( 42 ) );
	}
}
