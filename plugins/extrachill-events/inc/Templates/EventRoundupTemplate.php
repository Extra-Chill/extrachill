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
 *   - title (string) — appears on the cover, or on the first list slide
 *     when there is no cover
 *   - cta_text (string) — CTA; defaults to current site host. A footer on
 *     every list slide, or the closing slide when `closing` is set
 *   - cover (bool) — prepend a cover slide: title, date range, and show
 *     and venue counts
 *   - picks (array) — human-chosen highlights rendered on a text-only
 *     picks slide after the cover. Items are event post IDs or
 *     { post_id, note }; IDs not in day_groups are skipped; max 5
 *   - picks_title (string) — picks slide heading; defaults to "Our Picks"
 *   - closing (bool) — append a closing CTA slide and drop the per-slide
 *     footer from the list slides
 *   - closing_text (string) — optional supporting line on the closing slide
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
	private const MIN_SPLIT_EVENTS       = 2;
	private const MAX_PICKS              = 5;
	private const COVER_TITLE_SIZE       = 92;
	private const COVER_DATE_SIZE        = 40;
	private const COVER_STATS_SIZE       = 28;
	private const STRIPE_HEIGHT          = 14;
	private const PICKS_HEADING_SIZE     = 52;
	private const PICK_DAY_SIZE          = 22;
	private const PICK_TITLE_SIZE        = 40;
	private const PICK_META_SIZE         = 22;
	private const PICK_NOTE_SIZE         = 24;
	private const PICK_GAP               = 42;
	private const CLOSING_HEADLINE_SIZE  = 80;
	private const CLOSING_URL_SIZE       = 40;
	private const CLOSING_TEXT_SIZE      = 28;

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
			'day_groups'   => array(
				'label'    => 'Day Groups',
				'type'     => 'array',
				'required' => true,
			),
			'title'        => array(
				'label'    => 'Title',
				'type'     => 'string',
				'required' => false,
			),
			'cta_text'     => array(
				'label'    => 'CTA Text',
				'type'     => 'string',
				'required' => false,
			),
			'cover'        => array(
				'label'    => 'Cover Slide',
				'type'     => 'boolean',
				'required' => false,
			),
			'picks'        => array(
				'label'    => 'Picks',
				'type'     => 'array',
				'required' => false,
			),
			'picks_title'  => array(
				'label'    => 'Picks Title',
				'type'     => 'string',
				'required' => false,
			),
			'closing'      => array(
				'label'    => 'Closing Slide',
				'type'     => 'boolean',
				'required' => false,
			),
			'closing_text' => array(
				'label'    => 'Closing Text',
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

		$cover   = ! empty( $data['cover'] );
		$closing = ! empty( $data['closing'] ) && '' !== $cta_text;
		$picks   = self::resolve_picks( $day_groups, (array) ( $data['picks'] ?? array() ) );

		// The cover owns the title; the closing slide owns the CTA.
		$list_title = $cover ? '' : $title;
		$footer_cta = $closing ? '' : $cta_text;

		$slides_distribution = $this->distribute_days_to_slides(
			$day_groups,
			$list_title,
			$footer_cta,
			$heading_path,
			$body_path
		);

		$image_paths  = array();
		$slide_number = 0;
		$colors       = array(
			'bg'     => $bg_hex,
			'text'   => $text_hex,
			'muted'  => $muted_hex,
			'accent' => $accent_hex,
			'cta'    => $cta_hex,
		);

		if ( $cover ) {
			$path = $this->render_cover_slide( ++$slide_number, $title, self::cover_stats( $day_groups ), $day_groups, $preset, $format, $context, $heading_path, $body_path, $colors, $day_palette );
			if ( $path ) {
				$image_paths[] = $path;
			}
		}

		if ( ! empty( $picks ) ) {
			$picks_title = trim( (string) ( $data['picks_title'] ?? '' ) );
			$path        = $this->render_picks_slide( ++$slide_number, '' !== $picks_title ? $picks_title : 'Our Picks', $picks, $preset, $format, $context, $heading_path, $body_path, $colors, $day_palette );
			if ( $path ) {
				$image_paths[] = $path;
			}
		}

		foreach ( array_values( $slides_distribution ) as $index => $slide_days ) {
			$slide_title = ( 0 === $index ) ? $list_title : '';

			$path = $this->render_slide(
				$slide_days,
				++$slide_number,
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
				$footer_cta,
				$day_palette
			);

			if ( $path ) {
				$image_paths[] = $path;
			}
		}

		if ( $closing ) {
			$path = $this->render_closing_slide( ++$slide_number, $cta_text, trim( (string) ( $data['closing_text'] ?? '' ) ), $day_groups, $preset, $format, $context, $heading_path, $body_path, $colors, $day_palette );
			if ( $path ) {
				$image_paths[] = $path;
			}
		}

		return $image_paths;
	}

	/**
	 * Show count, distinct venue count, and a date-range label for the cover.
	 *
	 * @param array $day_groups date_key => { date_obj, events[] }.
	 * @return array{shows: int, venues: int, date_label: string}
	 */
	public static function cover_stats( array $day_groups ): array {
		$shows  = 0;
		$venues = array();
		$dates  = array();

		foreach ( $day_groups as $day_group ) {
			$events = (array) ( $day_group['events'] ?? array() );
			if ( empty( $events ) ) {
				continue;
			}
			if ( ( $day_group['date_obj'] ?? null ) instanceof \DateTimeInterface ) {
				$dates[] = $day_group['date_obj'];
			}
			foreach ( $events as $event_item ) {
				++$shows;
				$venue = strtolower( trim( self::decode_text( (string) ( $event_item['event_data']['venue'] ?? '' ) ) ) );
				if ( '' !== $venue ) {
					$venues[ $venue ] = true;
				}
			}
		}

		$date_label = '';
		if ( ! empty( $dates ) ) {
			usort( $dates, static fn( $a, $b ) => $a <=> $b );
			$first = reset( $dates );
			$last  = end( $dates );
			if ( $first->format( 'Y-m-d' ) === $last->format( 'Y-m-d' ) ) {
				$date_label = $first->format( 'l, M j' );
			} elseif ( $first->format( 'Y-m' ) === $last->format( 'Y-m' ) ) {
				$date_label = $first->format( 'D M j' ) . ' – ' . $last->format( 'D j' );
			} else {
				$date_label = $first->format( 'D M j' ) . ' – ' . $last->format( 'D M j' );
			}
		}

		return array(
			'shows'      => $shows,
			'venues'     => count( $venues ),
			'date_label' => strtoupper( $date_label ),
		);
	}

	/**
	 * Resolve requested picks against the roundup's own events.
	 *
	 * Picks are chosen by a person, not ranked by the system, so order is
	 * preserved exactly as given. Unknown or duplicate IDs are skipped and
	 * the list is capped at MAX_PICKS.
	 *
	 * @param array $day_groups date_key => { date_obj, events[] }.
	 * @param array $picks      Post IDs or { post_id, note } items.
	 * @return array<int, array{post: object, event_data: array, date_obj: mixed, note: string}>
	 */
	public static function resolve_picks( array $day_groups, array $picks ): array {
		$index = array();
		foreach ( $day_groups as $day_group ) {
			foreach ( (array) ( $day_group['events'] ?? array() ) as $event_item ) {
				$post_id = (int) ( $event_item['post']->ID ?? 0 );
				if ( $post_id > 0 ) {
					$index[ $post_id ] = array(
						'post'       => $event_item['post'],
						'event_data' => (array) ( $event_item['event_data'] ?? array() ),
						'date_obj'   => $day_group['date_obj'] ?? null,
					);
				}
			}
		}

		$resolved = array();
		foreach ( $picks as $pick ) {
			$post_id = is_array( $pick ) ? (int) ( $pick['post_id'] ?? 0 ) : (int) $pick;
			if ( ! isset( $index[ $post_id ] ) || isset( $resolved[ $post_id ] ) ) {
				continue;
			}
			$resolved[ $post_id ] = $index[ $post_id ] + array(
				'note' => is_array( $pick ) ? trim( (string) ( $pick['note'] ?? '' ) ) : '',
			);
			if ( count( $resolved ) >= self::MAX_PICKS ) {
				break;
			}
		}

		return array_values( $resolved );
	}

	/**
	 * Format an H:i:s start time as "7:30 PM".
	 */
	private static function format_time( string $start_time ): string {
		if ( '' === $start_time ) {
			return '';
		}
		$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
		return $time_obj ? $time_obj->format( 'g:i A' ) : '';
	}

	/**
	 * Create a slide canvas with fonts and the background fill.
	 */
	private function start_canvas( string $preset, string $heading_path, string $body_path, string $bg_hex ): ?GDRenderer {
		$renderer = new GDRenderer();
		$renderer->create_canvas( $preset );
		if ( ! $renderer->get_image() ) {
			$renderer->destroy();
			return null;
		}
		$renderer->register_font( 'header', $heading_path ? $heading_path : 'Heading.ttf' );
		$renderer->register_font( 'body', $body_path ? $body_path : 'Body.ttf' );
		$renderer->fill( $renderer->color_hex( 'bg', $bg_hex ) );
		return $renderer;
	}

	/**
	 * Persist a finished slide and free the canvas.
	 */
	private function finish_canvas( GDRenderer $renderer, int $slide_number, string $format, array $context ): ?string {
		$filename = sprintf( 'roundup-slide-%d.%s', $slide_number, 'jpeg' === $format ? 'jpg' : 'png' );
		$path     = ( ! empty( $context['pipeline_id'] ) && ! empty( $context['flow_id'] ) )
			? $renderer->save_to_repository( $filename, $context, $format )
			: $renderer->save_temp( $format );
		$renderer->destroy();
		return $path;
	}

	/**
	 * Draw one full-width band per roundup day, in that day's palette color.
	 */
	private function draw_day_stripes( GDRenderer $renderer, array $day_groups, int $y, array $day_palette ): void {
		$days = array();
		foreach ( $day_groups as $day_group ) {
			if ( ! empty( $day_group['events'] ) && ( $day_group['date_obj'] ?? null ) instanceof \DateTimeInterface ) {
				$days[] = strtolower( $day_group['date_obj']->format( 'l' ) );
			}
		}
		if ( empty( $days ) ) {
			return;
		}

		$width   = $renderer->get_width();
		$segment = (int) ceil( $width / count( $days ) );
		foreach ( $days as $i => $day_name ) {
			$color = $renderer->color_hex( 'stripe_' . $day_name, (string) ( $day_palette[ $day_name ] ?? '#4ecdc4' ) );
			$renderer->filled_rect( $i * $segment, $y, min( $width, ( $i + 1 ) * $segment ), $y + self::STRIPE_HEIGHT, $color );
		}
	}

	/**
	 * Cover: day stripes, big title, date range, show and venue counts.
	 */
	private function render_cover_slide( int $slide_number, string $title, array $stats, array $day_groups, string $preset, string $format, array $context, string $heading_path, string $body_path, array $colors, array $day_palette ): ?string {
		$renderer = $this->start_canvas( $preset, $heading_path, $body_path, $colors['bg'] );
		if ( ! $renderer ) {
			return null;
		}

		$text   = $renderer->color_hex( 'text', $colors['text'] );
		$muted  = $renderer->color_hex( 'muted', $colors['muted'] );
		$accent = $renderer->color_hex( 'accent', $colors['accent'] );
		$cta    = $renderer->color_hex( 'cta', $colors['cta'] );
		$width  = $renderer->get_width();
		$height = $renderer->get_height();
		$max_w  = $width - ( self::PADDING * 2 );

		$this->draw_day_stripes( $renderer, $day_groups, 0, $day_palette );

		$title             = strtoupper( '' !== $title ? $title : 'Shows' );
		$lines             = $renderer->wrap_text( $title, self::COVER_TITLE_SIZE, 'header', $max_w );
		$title_line_height = (int) ( self::COVER_TITLE_SIZE * 1.15 );
		$block_height      = count( $lines ) * $title_line_height + 40 + (int) ( self::COVER_DATE_SIZE * 1.4 ) + (int) ( self::COVER_STATS_SIZE * 1.4 );
		$y                 = max( self::PADDING * 3, (int) ( ( $height - $block_height ) / 2 ) - 40 );

		foreach ( $lines as $line ) {
			$renderer->draw_text( $line, self::COVER_TITLE_SIZE, self::PADDING, $y + self::COVER_TITLE_SIZE, $text, 'header' );
			$y += $title_line_height;
		}

		$y += 14;
		$renderer->filled_rect( self::PADDING, $y, self::PADDING + 160, $y + 6, $accent );
		$y += 26;

		if ( '' !== $stats['date_label'] ) {
			$renderer->draw_text( $stats['date_label'], self::COVER_DATE_SIZE, self::PADDING, $y + self::COVER_DATE_SIZE, $accent, 'header' );
			$y += (int) ( self::COVER_DATE_SIZE * 1.4 );
		}

		$stats_line = sprintf(
			'%d %s · %d %s',
			$stats['shows'],
			1 === $stats['shows'] ? 'SHOW' : 'SHOWS',
			$stats['venues'],
			1 === $stats['venues'] ? 'VENUE' : 'VENUES'
		);
		$renderer->draw_text( $stats_line, self::COVER_STATS_SIZE, self::PADDING, $y + self::COVER_STATS_SIZE, $muted, 'body' );

		$renderer->draw_text( 'SWIPE FOR THE FULL LINEUP →', self::CTA_SIZE + 4, self::PADDING, $height - self::PADDING, $cta, 'body' );

		return $this->finish_canvas( $renderer, $slide_number, $format, $context );
	}

	/**
	 * Picks: text-only highlights chosen by a person, each with an optional note.
	 */
	private function render_picks_slide( int $slide_number, string $heading, array $picks, string $preset, string $format, array $context, string $heading_path, string $body_path, array $colors, array $day_palette ): ?string {
		$renderer = $this->start_canvas( $preset, $heading_path, $body_path, $colors['bg'] );
		if ( ! $renderer ) {
			return null;
		}

		$text   = $renderer->color_hex( 'text', $colors['text'] );
		$muted  = $renderer->color_hex( 'muted', $colors['muted'] );
		$accent = $renderer->color_hex( 'accent', $colors['accent'] );
		$max_w  = $renderer->get_width() - ( self::PADDING * 2 );

		$y = self::PADDING;
		$renderer->draw_text( strtoupper( $heading ), self::PICKS_HEADING_SIZE, self::PADDING, $y + self::PICKS_HEADING_SIZE, $accent, 'header' );
		$y += (int) ( self::PICKS_HEADING_SIZE * 1.3 );
		$renderer->filled_rect( self::PADDING, $y, self::PADDING + 160, $y + 6, $accent );
		$y += 50;

		foreach ( $picks as $pick ) {
			$date_obj  = $pick['date_obj'] ?? null;
			$day_name  = $date_obj instanceof \DateTimeInterface ? strtolower( $date_obj->format( 'l' ) ) : 'monday';
			$day_color = $renderer->color_hex( 'pick_' . $day_name, (string) ( $day_palette[ $day_name ] ?? '#4ecdc4' ) );
			$day_label = $date_obj instanceof \DateTimeInterface ? strtoupper( $date_obj->format( 'l' ) ) : '';

			$time = self::format_time( (string) ( $pick['event_data']['startTime'] ?? '' ) );
			if ( '' !== $day_label ) {
				$renderer->draw_text( trim( $day_label . ( '' !== $time ? ' · ' . $time : '' ) ), self::PICK_DAY_SIZE, self::PADDING, $y + self::PICK_DAY_SIZE, $day_color, 'header' );
				$y += (int) ( self::PICK_DAY_SIZE * 1.5 );
			}

			$y = $renderer->draw_text_wrapped( strtoupper( self::decode_text( (string) ( $pick['post']->post_title ?? '' ) ) ), self::PICK_TITLE_SIZE, self::PADDING, $y, $text, 'header', $max_w, 1.15 );

			$venue = self::decode_text( (string) ( $pick['event_data']['venue'] ?? '' ) );
			if ( '' !== $venue ) {
				$renderer->draw_text( $venue, self::PICK_META_SIZE, self::PADDING, $y + self::PICK_META_SIZE + 4, $muted, 'body' );
				$y += (int) ( self::PICK_META_SIZE * 1.5 ) + 4;
			}

			if ( '' !== $pick['note'] ) {
				$y = $renderer->draw_text_wrapped( self::decode_text( $pick['note'] ), self::PICK_NOTE_SIZE, self::PADDING, $y + 6, $text, 'body', $max_w, 1.35 );
			}

			$y += self::PICK_GAP;
		}

		return $this->finish_canvas( $renderer, $slide_number, $format, $context );
	}

	/**
	 * Closing: one clear call to action, replacing the per-slide footer.
	 */
	private function render_closing_slide( int $slide_number, string $cta_text, string $closing_text, array $day_groups, string $preset, string $format, array $context, string $heading_path, string $body_path, array $colors, array $day_palette ): ?string {
		$renderer = $this->start_canvas( $preset, $heading_path, $body_path, $colors['bg'] );
		if ( ! $renderer ) {
			return null;
		}

		$text   = $renderer->color_hex( 'text', $colors['text'] );
		$muted  = $renderer->color_hex( 'muted', $colors['muted'] );
		$cta    = $renderer->color_hex( 'cta', $colors['cta'] );
		$height = $renderer->get_height();
		$max_w  = $renderer->get_width() - ( self::PADDING * 2 );

		// "MORE SHOWS AT EVENTS.EXTRACHILL.COM" → headline + URL when the
		// CTA follows the default "<lead> AT <url>" shape.
		$headline = $cta_text;
		$url      = '';
		if ( preg_match( '/^(.+?)\s+AT\s+(\S+\.\S+)$/', $cta_text, $m ) ) {
			$headline = $m[1];
			$url      = $m[2];
		}

		$y  = (int) ( $height * 0.36 );
		$y  = $renderer->draw_text_wrapped( $headline, self::CLOSING_HEADLINE_SIZE, self::PADDING, $y, $text, 'header', $max_w, 1.15, 'center' );
		$y += 24;

		if ( '' !== $url ) {
			$y = $renderer->draw_text_wrapped( $url, self::CLOSING_URL_SIZE, self::PADDING, $y, $cta, 'header', $max_w, 1.3, 'center' );
		}

		if ( '' !== $closing_text ) {
			$renderer->draw_text_wrapped( $closing_text, self::CLOSING_TEXT_SIZE, self::PADDING, $y + 40, $muted, 'body', $max_w, 1.4, 'center' );
		}

		$this->draw_day_stripes( $renderer, $day_groups, $height - self::STRIPE_HEIGHT, $day_palette );

		return $this->finish_canvas( $renderer, $slide_number, $format, $context );
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
			$total = count( $events );
			foreach ( array_values( $events ) as $i => $event_item ) {
				$item_height = (int) $event_height( $event_item );
				$needed      = $item_height + ( empty( $chunk ) ? $day_header_height : 0 );

				if ( $cursor + $needed > $content_bottom && ( $slide_has_content || ! empty( $chunk ) ) ) {
					// Keep a split day readable: never strand a day header
					// with fewer than MIN_SPLIT_EVENTS at the bottom of a
					// slide that already holds other days, and never carry
					// fewer than MIN_SPLIT_EVENTS onto the next slide.
					$carry     = array();
					$remaining = $total - $i;
					if ( ! empty( $chunk ) && count( $chunk ) < self::MIN_SPLIT_EVENTS && ! empty( $current ) ) {
						$carry = $chunk;
						$chunk = array();
					} elseif ( $remaining < self::MIN_SPLIT_EVENTS && count( $chunk ) > self::MIN_SPLIT_EVENTS ) {
						$carry = array_splice( $chunk, -( self::MIN_SPLIT_EVENTS - $remaining ) );
					}

					if ( ! empty( $chunk ) ) {
						$current[] = array_merge( $day_group, array( 'events' => $chunk ) );
					}
					if ( ! empty( $current ) ) {
						$slides[] = $current;
					}
					$current           = array();
					$chunk             = $carry;
					$cursor            = $content_top;
					$slide_has_content = ! empty( $carry );
					if ( ! empty( $carry ) ) {
						$cursor += $day_header_height;
						foreach ( $carry as $carried ) {
							$cursor += (int) $event_height( $carried );
						}
					}
					$needed = $item_height + ( empty( $chunk ) ? $day_header_height : 0 );
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
