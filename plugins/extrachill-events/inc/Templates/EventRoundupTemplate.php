<?php
/**
 * Event Roundup Template
 *
 * Renders a multi-slide Instagram carousel (1080x1350) listing events
 * grouped by day. Brand identity (colors, fonts, day-of-week palette)
 * comes from BrandTokens — the template is intentionally brand-agnostic.
 *
 * Works for any date range: tonight, this weekend, a custom 5-day stretch,
 * a full week. The template only sees `day_groups`; date semantics live
 * in callers (EventRoundupAbilities, EventRoundupHandler, the CLI).
 *
 * Returns string[] of slide paths because the carousel format is
 * inherently multi-image: events are distributed across slides based on
 * available vertical space, with the title only on the first slide.
 *
 * Layout per slide:
 *   - Background fill (colors['event_roundup_bg'] or background_dark)
 *   - Title block (first slide only) with accent underline
 *   - Day group sections: day header in day-palette color + event rows
 *   - Each event row: title + venue/time meta line
 *   - Footer CTA pointing viewers to the full events site
 *
 * Required data fields:
 *   - day_groups (array) — date_key => { date_obj, events[] }, where each
 *     event item is { post: WP_Post, event_data: array }
 *
 * Optional data fields:
 *   - title (string) — appears only on first slide
 *   - cta_text (string) — footer CTA; defaults to current site host
 *
 * Optional brand token extensions consumed by this template:
 *   - colors['event_roundup_bg']    — slide background (falls back to background_dark)
 *   - colors['event_roundup_text']  — primary text (falls back to text_inverse)
 *   - colors['event_roundup_muted'] — venue/time meta (falls back to text_muted)
 *   - colors['event_roundup_cta']   — footer CTA (falls back to accent)
 *   - day_palette['sunday'..'saturday'] — hex per weekday (falls back to neutral palette)
 *
 * @package ExtraChillEvents\Templates
 * @since 4.5.0
 */

namespace ExtraChillEvents\Templates;

use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\BrandTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRoundupTemplate implements TemplateInterface {

	private const PADDING                = 60;
	private const TITLE_SIZE             = 36;
	private const TITLE_UNDERLINE_HEIGHT = 2;
	private const TITLE_UNDERLINE_GAP    = 8;
	private const DAY_HEADER_SIZE        = 28;
	private const EVENT_TITLE_SIZE       = 22;
	private const EVENT_META_SIZE        = 18;
	private const CTA_SIZE               = 18;
	private const CTA_LINE_WIDTH         = 180;
	private const CTA_LINE_GAP           = 18;
	private const LINE_HEIGHT_MULTIPLIER = 1.4;
	private const EVENT_GAP              = 15;
	private const DAY_HEADER_GAP         = 20;
	private const DAY_TRAILING_GAP       = 30;
	private const CTA_CLEARANCE          = 24;

	/**
	 * Neutral fallback palette for days of the week.
	 *
	 * Themes override via tokens['day_palette'] in the brand tokens filter.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACK_DAY_PALETTE = array(
		'sunday'    => '#ff6b6b',
		'monday'    => '#4ecdc4',
		'tuesday'   => '#45b7d1',
		'wednesday' => '#96ceb4',
		'thursday'  => '#feca57',
		'friday'    => '#d63384',
		'saturday'  => '#54a0ff',
	);

	public function get_id(): string {
		return 'event_roundup';
	}

	public function get_name(): string {
		return 'Event Roundup';
	}

	public function get_description(): string {
		return 'Instagram carousel slides (1080x1350) listing events grouped by day. Returns multiple images when events span more vertical space than a single slide. Works for any date range — tonight, this weekend, a custom stretch.';
	}

	public function get_fields(): array {
		return array(
			'day_groups' => array(
				'label'    => 'Day Groups',
				'type'     => 'array',
				'required' => true,
			),
			'title'      => array(
				'label'    => 'Title',
				'type'     => 'string',
				'required' => false,
			),
			'cta_text'   => array(
				'label'    => 'CTA Text',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$preset  = $options['preset'] ?? $this->get_default_preset();
		$format  = $options['format'] ?? 'png';
		$context = $options['context'] ?? array();

		$day_groups = (array) ( $data['day_groups'] ?? array() );
		$title      = (string) ( $data['title'] ?? '' );
		$cta_text   = $this->resolve_cta_text( $data );

		if ( empty( $day_groups ) ) {
			return array();
		}

		$tokens = BrandTokens::get( $this->get_id(), $data );

		// Build a per-render font lookup. GDRenderer is created fresh for
		// every slide so register_font() needs to run inside render_slide.
		$heading_path = isset( $tokens['fonts']['heading'] ) && is_string( $tokens['fonts']['heading'] ) ? $tokens['fonts']['heading'] : '';
		$body_path    = isset( $tokens['fonts']['body'] ) && is_string( $tokens['fonts']['body'] ) ? $tokens['fonts']['body'] : '';

		// Color and palette resolution. Roundup slides are dark-themed by
		// default, so we map onto background_dark + text_inverse rather
		// than the OG card's light-mode background/text_primary defaults.
		$bg_hex     = (string) ( $tokens['colors']['event_roundup_bg'] ?? $tokens['colors']['background_dark'] ?? '#1a1a1a' );
		$text_hex   = (string) ( $tokens['colors']['event_roundup_text'] ?? $tokens['colors']['text_inverse'] ?? '#e5e5e5' );
		$muted_hex  = (string) ( $tokens['colors']['event_roundup_muted'] ?? $tokens['colors']['text_muted'] ?? '#b0b0b0' );
		$accent_hex = (string) ( $tokens['colors']['accent'] ?? '#53940b' );
		$cta_hex    = (string) ( $tokens['colors']['event_roundup_cta'] ?? $accent_hex );

		$day_palette = isset( $tokens['day_palette'] ) && is_array( $tokens['day_palette'] )
			? array_merge( self::FALLBACK_DAY_PALETTE, $tokens['day_palette'] )
			: self::FALLBACK_DAY_PALETTE;

		$slides_distribution = $this->distribute_days_to_slides(
			$day_groups,
			$title,
			$cta_text,
			$heading_path,
			$body_path
		);

		$image_paths = array();

		foreach ( array_values( $slides_distribution ) as $index => $slide_days ) {
			$slide_title = ( 0 === $index ) ? $title : '';

			$path = $this->render_slide(
				$slide_days,
				$index + 1,
				$slide_title,
				$preset,
				$format,
				$context,
				$heading_path,
				$body_path,
				$bg_hex,
				$text_hex,
				$muted_hex,
				$accent_hex,
				$cta_hex,
				$cta_text,
				$day_palette
			);

			if ( $path ) {
				$image_paths[] = $path;
			}
		}

		return $image_paths;
	}

	/**
	 * Resolve footer CTA text shown on every slide.
	 */
	private function resolve_cta_text( array $data ): string {
		$cta_text = isset( $data['cta_text'] ) ? trim( (string) $data['cta_text'] ) : '';

		if ( '' === $cta_text ) {
			$host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$cta_text = $host ? sprintf( 'More shows at %s', $host ) : '';
		}

		return strtoupper( $cta_text );
	}

	/**
	 * Distribute day groups across slides based on available height.
	 *
	 * Operates on a temporary throwaway renderer so we can measure text
	 * heights with the actual fonts before allocating the real per-slide
	 * canvases. The packing itself lives in plan_slides() so it can be
	 * verified without GD.
	 *
	 * @param array  $day_groups   Day-grouped events.
	 * @param string $title        Optional title (affects first slide height).
	 * @param string $cta_text     Footer CTA (reserves space at the bottom).
	 * @param string $heading_path Heading font path.
	 * @param string $body_path    Body font path.
	 * @return array Array of slides, each a list of day groups that fit.
	 */
	private function distribute_days_to_slides( array $day_groups, string $title, string $cta_text, string $heading_path, string $body_path ): array {
		$preset_dims = \DataMachine\Abilities\Media\PlatformPresets::dimensions( $this->get_default_preset() );
		$width       = $preset_dims['width'] ?? 1080;
		$height      = $preset_dims['height'] ?? 1350;
		$max_width   = $width - ( self::PADDING * 2 );

		$measure = new GDRenderer();
		$measure->create_canvas( $width, $height );
		$measure->register_font( 'header', $heading_path ? $heading_path : 'Heading.ttf' );
		$measure->register_font( 'body', $body_path ? $body_path : 'Body.ttf' );

		$title_height = '' !== $title
			? $this->calculate_title_height( $measure, $title, $width )
			: 0;

		$event_height = function ( array $event_item ) use ( $measure, $max_width ): int {
			$post  = $event_item['post'] ?? null;
			$title = self::decode_text( $post ? (string) $post->post_title : 'Untitled Event' );

			return $measure->measure_text_height( $title, self::EVENT_TITLE_SIZE, 'body', $max_width, self::LINE_HEIGHT_MULTIPLIER )
				+ (int) ( self::EVENT_META_SIZE * self::LINE_HEIGHT_MULTIPLIER )
				+ self::EVENT_GAP;
		};

		$slides = self::plan_slides(
			$day_groups,
			self::PADDING,
			self::content_bottom( $height, $cta_text ),
			$title_height,
			self::day_header_height(),
			$event_height
		);

		$measure->destroy();

		return $slides;
	}

	/**
	 * Pack day groups onto slides, splitting a day across slides when it
	 * does not fit (#931).
	 *
	 * Events are sorted by start time before packing so a continued day
	 * picks up where the previous slide left off. A day that continues
	 * onto a new slide repeats its header there. The first item placed on
	 * an empty slide is always accepted, so an oversized event can never
	 * cause an infinite loop.
	 *
	 * @param array    $day_groups        date_key => { date_obj, events[] }.
	 * @param int      $content_top       Y where content starts on every slide.
	 * @param int      $content_bottom    Y content must not cross (CTA reserved).
	 * @param int      $title_height      Title block height on the first slide.
	 * @param int      $day_header_height Height of a day header incl. gap.
	 * @param callable $event_height      fn( array $event_item ): int.
	 * @return array<int, array<int, array>> Slides of day groups.
	 */
	public static function plan_slides(
		array $day_groups,
		int $content_top,
		int $content_bottom,
		int $title_height,
		int $day_header_height,
		callable $event_height
	): array {
		$slides  = array();
		$current = array();
		$cursor  = $content_top + $title_height;
		// The title belongs to the first slide even before any event lands.
		$slide_has_content = $title_height > 0;

		foreach ( $day_groups as $day_group ) {
			$events = self::sort_events_by_time( (array) ( $day_group['events'] ?? array() ) );
			if ( empty( $events ) ) {
				continue;
			}

			$chunk = array();
			foreach ( $events as $event_item ) {
				$item_height = (int) $event_height( $event_item );
				$needed      = $item_height + ( empty( $chunk ) ? $day_header_height : 0 );

				if ( $cursor + $needed > $content_bottom && ( $slide_has_content || ! empty( $chunk ) ) ) {
					if ( ! empty( $chunk ) ) {
						$current[] = array_merge( $day_group, array( 'events' => $chunk ) );
					}
					if ( ! empty( $current ) ) {
						$slides[] = $current;
					}
					$current           = array();
					$chunk             = array();
					$cursor            = $content_top;
					$slide_has_content = false;
					$needed            = $item_height + $day_header_height;
				}

				$chunk[]           = $event_item;
				$cursor           += $needed;
				$slide_has_content = true;
			}

			$current[] = array_merge( $day_group, array( 'events' => $chunk ) );
			$cursor   += self::DAY_TRAILING_GAP;
		}

		if ( ! empty( $current ) ) {
			$slides[] = $current;
		}

		return $slides;
	}

	/**
	 * Lowest Y content may reach before colliding with the footer CTA.
	 */
	public static function content_bottom( int $height, string $cta_text ): int {
		$bottom = $height - self::PADDING;
		if ( '' !== $cta_text ) {
			// render_cta() draws its rule CTA_SIZE + CTA_LINE_GAP above the
			// bottom padding; keep clearance above that rule.
			$bottom -= self::CTA_SIZE + self::CTA_LINE_GAP + self::CTA_CLEARANCE;
		}
		return $bottom;
	}

	/**
	 * Height consumed by a day header, matching render_day_group().
	 */
	private static function day_header_height(): int {
		return (int) ( self::DAY_HEADER_SIZE * self::LINE_HEIGHT_MULTIPLIER ) + self::DAY_HEADER_GAP;
	}

	/**
	 * Sort event items by start time (missing times last).
	 *
	 * @param array $events Event items.
	 * @return array Sorted event items.
	 */
	private static function sort_events_by_time( array $events ): array {
		usort(
			$events,
			static function ( $a, $b ) {
				$time_a = $a['event_data']['startTime'] ?? '23:59:59';
				$time_b = $b['event_data']['startTime'] ?? '23:59:59';
				return strcmp( (string) $time_a, (string) $time_b );
			}
		);
		return $events;
	}

	/**
	 * Decode HTML entities stored in post titles and venue names so slides
	 * show "&" rather than "&amp;".
	 */
	public static function decode_text( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Pixel height needed for the title block (with underline).
	 */
	private function calculate_title_height( GDRenderer $renderer, string $title, int $width ): int {
		$max_width   = $width - ( self::PADDING * 2 );
		$lines       = $renderer->wrap_text( $title, self::TITLE_SIZE, 'header', $max_width );
		$line_height = (int) ( self::TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );
		$text_height = count( $lines ) * $line_height;

		return $text_height + self::TITLE_UNDERLINE_GAP + self::TITLE_UNDERLINE_HEIGHT + 30;
	}

	/**
	 * Render a single slide and persist it via GDRenderer's repository helper.
	 *
	 * @return string|null Saved file path on success.
	 */
	private function render_slide(
		array $slide_days,
		int $slide_number,
		string $title,
		string $preset,
		string $format,
		array $context,
		string $heading_path,
		string $body_path,
		string $bg_hex,
		string $text_hex,
		string $muted_hex,
		string $accent_hex,
		string $cta_hex,
		string $cta_text,
		array $day_palette
	): ?string {
		$renderer = new GDRenderer();
		$renderer->create_canvas( $preset );

		if ( ! $renderer->get_image() ) {
			$renderer->destroy();
			return null;
		}

		$renderer->register_font( 'header', $heading_path ? $heading_path : 'Heading.ttf' );
		$renderer->register_font( 'body', $body_path ? $body_path : 'Body.ttf' );

		$bg_color    = $renderer->color_hex( 'bg', $bg_hex );
		$text_color  = $renderer->color_hex( 'text', $text_hex );
		$muted_color = $renderer->color_hex( 'muted', $muted_hex );
		$accent      = $renderer->color_hex( 'accent', $accent_hex );
		$cta_color   = $renderer->color_hex( 'cta', $cta_hex );

		$renderer->fill( $bg_color );

		$y = self::PADDING;

		if ( '' !== $title ) {
			$y = $this->render_title( $renderer, $title, $y, $text_color, $accent );
		}

		foreach ( $slide_days as $day_group ) {
			$y = $this->render_day_group( $renderer, $day_group, $y, $text_color, $muted_color, $day_palette );
		}

		$this->render_cta( $renderer, $cta_text, $cta_color );

		$filename = sprintf( 'roundup-slide-%d.%s', $slide_number, 'jpeg' === $format ? 'jpg' : 'png' );

		if ( ! empty( $context['pipeline_id'] ) && ! empty( $context['flow_id'] ) ) {
			$path = $renderer->save_to_repository( $filename, $context, $format );
		} else {
			$path = $renderer->save_temp( $format );
		}

		$renderer->destroy();

		return $path;
	}

	/**
	 * Render the slide title with an accent underline.
	 */
	private function render_title( GDRenderer $renderer, string $title, int $y, int $text_color, int $underline_color ): int {
		$max_width   = $renderer->get_width() - ( self::PADDING * 2 );
		$lines       = $renderer->wrap_text( $title, self::TITLE_SIZE, 'header', $max_width );
		$line_height = (int) ( self::TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );

		$max_line_width = 0;
		foreach ( $lines as $line ) {
			$renderer->draw_text( $line, self::TITLE_SIZE, self::PADDING, $y + self::TITLE_SIZE, $text_color, 'header' );
			$line_width     = $renderer->measure_text_width( $line, self::TITLE_SIZE, 'header' );
			$max_line_width = max( $max_line_width, $line_width );
			$y             += $line_height;
		}

		$y += self::TITLE_UNDERLINE_GAP;
		$renderer->filled_rect(
			self::PADDING,
			$y,
			self::PADDING + $max_line_width,
			$y + self::TITLE_UNDERLINE_HEIGHT,
			$underline_color
		);
		$y += self::TITLE_UNDERLINE_HEIGHT + 30;

		return $y;
	}

	/**
	 * Render a small footer CTA to send viewers back to the full calendar.
	 */
	private function render_cta( GDRenderer $renderer, string $cta_text, int $cta_color ): void {
		if ( '' === $cta_text ) {
			return;
		}

		$baseline = $renderer->get_height() - self::PADDING;
		$line_y   = $baseline - self::CTA_SIZE - self::CTA_LINE_GAP;
		$line_x   = (int) ( ( $renderer->get_width() - self::CTA_LINE_WIDTH ) / 2 );

		$renderer->filled_rect(
			$line_x,
			$line_y,
			$line_x + self::CTA_LINE_WIDTH,
			$line_y + self::TITLE_UNDERLINE_HEIGHT,
			$cta_color
		);

		$renderer->draw_text_centered( $cta_text, self::CTA_SIZE, $baseline, $cta_color, 'body' );
	}

	/**
	 * Render a day header followed by its event rows.
	 */
	private function render_day_group( GDRenderer $renderer, array $day_group, int $y, int $text_color, int $muted_color, array $day_palette ): int {
		$date_obj = $day_group['date_obj'] ?? null;
		$events   = $day_group['events'] ?? array();

		$day_name      = $date_obj ? strtolower( $date_obj->format( 'l' ) ) : 'monday';
		$day_color_hex = (string) ( $day_palette[ $day_name ] ?? $day_palette['monday'] ?? '#4ecdc4' );
		$day_color     = $renderer->color_hex( 'day_' . $day_name, $day_color_hex );

		$day_label = $date_obj ? strtoupper( $date_obj->format( 'l, M j' ) ) : 'UNKNOWN DATE';
		$renderer->draw_text( $day_label, self::DAY_HEADER_SIZE, self::PADDING, $y + self::DAY_HEADER_SIZE, $day_color, 'header' );
		$y += self::day_header_height();

		$events = self::sort_events_by_time( $events );

		foreach ( $events as $event_item ) {
			$y = $this->render_event( $renderer, $event_item, $y, $text_color, $muted_color );
		}

		$y += self::DAY_TRAILING_GAP;

		return $y;
	}

	/**
	 * Render a single event row: title (wrapped) + venue/time meta.
	 */
	private function render_event( GDRenderer $renderer, array $event_item, int $y, int $text_color, int $muted_color ): int {
		$post       = $event_item['post'] ?? null;
		$event_data = $event_item['event_data'] ?? array();

		$title      = self::decode_text( $post ? (string) $post->post_title : 'Untitled Event' );
		$venue      = self::decode_text( (string) ( $event_data['venue'] ?? '' ) );
		$start_time = (string) ( $event_data['startTime'] ?? '' );

		$formatted_time = '';
		if ( '' !== $start_time ) {
			$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
			if ( $time_obj ) {
				$formatted_time = $time_obj->format( 'g:i A' );
			}
		}

		$meta_parts = array_filter( array( $venue, $formatted_time ) );
		$meta_line  = implode( ' · ', $meta_parts );

		$max_width = $renderer->get_width() - ( self::PADDING * 2 );

		$y = $renderer->draw_text_wrapped(
			$title,
			self::EVENT_TITLE_SIZE,
			self::PADDING,
			$y,
			$text_color,
			'body',
			$max_width,
			self::LINE_HEIGHT_MULTIPLIER,
			'left'
		);

		if ( '' !== $meta_line ) {
			$renderer->draw_text( $meta_line, self::EVENT_META_SIZE, self::PADDING, $y + self::EVENT_META_SIZE, $muted_color, 'body' );
			$y += (int) ( self::EVENT_META_SIZE * self::LINE_HEIGHT_MULTIPLIER );
		}

		$y += self::EVENT_GAP;

		return $y;
	}
}
