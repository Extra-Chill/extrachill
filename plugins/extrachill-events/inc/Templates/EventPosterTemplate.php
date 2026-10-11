<?php
/**
 * Event Poster Template
 *
 * Renders a single promotional poster for one event, built entirely from
 * the event's own data: title, date/time, venue and address, price, and
 * the RSVP perk text. Works across portrait feed, story, and print
 * presets, plus a two-column 16:9 landscape layout for featured and
 * Open Graph images; spare vertical space on tall canvases is distributed instead
 * of pooling at the bottom.
 *
 * Layout (top to bottom):
 *   - Kicker ("EXTRA CHILL PRESENTS") with the brand logo top-right
 *     (logo_path_inverse on the dark background, brand text if absent)
 *   - Title in the heading font, accent rule, optional tagline/subline
 *   - Date/time line, venue line (accent), address line
 *   - Full-width accent banner with price + perk text
 *   - Footer: RSVP line, optional co-brand logos + footer note, and a QR
 *     code encoding the event permalink
 *
 * Required data fields:
 *   - event_id (int)
 *
 * Optional data fields:
 *   - title (string)             — poster headline; defaults to the event title
 *   - tagline, subline (string)   — short body lines under the title
 *   - rsvp_text (string)          — footer RSVP line; defaults to the event host
 *   - footer_note (string)        — small line under the RSVP line
 *   - cobrand_logos (string[])    — PNG paths drawn beside the footer note
 *   - perk_text (string)          — overrides `_extrachill_event_perk_text`
 *   - kicker (string)             — defaults to "<BRAND> PRESENTS"
 *
 * Text rendering goes through GDRenderer, which falls back per glyph run
 * when the brand font lacks a character, so no glyph handling lives here.
 *
 * @package ExtraChillEvents\Templates
 */

namespace ExtraChillEvents\Templates;

use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\GDRenderer;
use DataMachine\Abilities\Media\BrandTokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventPosterTemplate implements TemplateInterface {

	/** Print preset id registered by this plugin (11x17 at 150dpi). */
	public const PRINT_PRESET = 'print_tabloid';

	/** Landscape preset id for featured / Open Graph images (16:9). */
	public const LANDSCAPE_PRESET = 'landscape_16_9';

	/** Design unit: all sizes below are pixels at a 1080px-wide canvas. */
	private const BASE_WIDTH = 1080;

	private const MARGIN           = 80;
	private const KICKER_SIZE      = 26;
	private const TITLE_MAX_SIZE   = 84;
	private const TITLE_MIN_SIZE   = 52;
	private const BODY_SIZE        = 34;
	private const DETAIL_SIZE      = 54;
	private const ADDRESS_SIZE     = 28;
	private const RSVP_SIZE        = 26;
	private const NOTE_SIZE        = 22;
	private const LOGO_HEIGHT      = 150;
	private const COBRAND_HEIGHT   = 44;
	private const QR_SIZE          = 190;
	private const BANNER_HEIGHT    = 120;
	private const BANNER_TEXT_SIZE = 34;
	private const MIN_BANNER_GAP   = 40;

	/** Landscape design unit: sizes are pixels at a 1080px-tall canvas. */
	private const LANDSCAPE_BASE_HEIGHT   = 1080;
	private const LANDSCAPE_MARGIN        = 100;
	private const LANDSCAPE_LOGO_HEIGHT   = 200;
	private const LANDSCAPE_QR_SIZE       = 260;
	private const LANDSCAPE_BANNER_HEIGHT = 110;
	private const LANDSCAPE_TITLE_MAX     = 96;
	private const LANDSCAPE_TITLE_MIN     = 60;

	/** GD alpha (0 opaque .. 127 transparent) for the background stripes. */
	private const STRIPE_ALPHA = 122;

	/** Share of spare vertical space placed above the content block. */
	private const SLACK_ABOVE = 0.4;

	public function get_id(): string {
		return 'event_poster';
	}

	public function get_name(): string {
		return 'Event Poster';
	}

	public function get_description(): string {
		return 'Single promotional poster for one event (feed, story, or print_tabloid). Reads title, date, venue, price and RSVP perk from the event; adds a QR code to the event page.';
	}

	public function get_fields(): array {
		return array(
			'event_id'      => array(
				'label'    => 'Event ID',
				'type'     => 'integer',
				'required' => true,
			),
			'title'         => array(
				'label'    => 'Title',
				'type'     => 'string',
				'required' => false,
			),
			'tagline'       => array(
				'label'    => 'Tagline',
				'type'     => 'string',
				'required' => false,
			),
			'subline'       => array(
				'label'    => 'Subline',
				'type'     => 'string',
				'required' => false,
			),
			'rsvp_text'     => array(
				'label'    => 'RSVP Text',
				'type'     => 'string',
				'required' => false,
			),
			'footer_note'   => array(
				'label'    => 'Footer Note',
				'type'     => 'string',
				'required' => false,
			),
			'cobrand_logos' => array(
				'label'    => 'Co-brand Logo Paths',
				'type'     => 'array',
				'required' => false,
			),
			'perk_text'     => array(
				'label'    => 'Perk Text',
				'type'     => 'string',
				'required' => false,
			),
			'kicker'        => array(
				'label'    => 'Kicker',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'instagram_feed_portrait';
	}

	/**
	 * Register this template's presets (print tabloid and 16:9 landscape)
	 * with Data Machine's preset registry.
	 *
	 * @param array $presets Registered presets.
	 * @return array
	 */
	public static function register_print_preset( array $presets ): array {
		$presets[ self::PRINT_PRESET ]     = array(
			'width'  => 1650,
			'height' => 2550,
			'label'  => 'Print Tabloid (11x17 @ 150dpi)',
		);
		$presets[ self::LANDSCAPE_PRESET ] = array(
			'width'  => 1920,
			'height' => 1080,
			'label'  => 'Landscape 16:9 (featured / Open Graph)',
		);
		return $presets;
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$post = get_post( (int) ( $data['event_id'] ?? 0 ) );
		if ( ! $post ) {
			return array();
		}

		$preset  = (string) ( $options['preset'] ?? $this->get_default_preset() );
		$format  = (string) ( $options['format'] ?? 'png' );
		$context = (array) ( $options['context'] ?? array() );

		$attrs  = self::event_details_attrs( (string) $post->post_content );
		$perk   = isset( $data['perk_text'] ) ? (string) $data['perk_text'] : (string) get_post_meta( $post->ID, '_extrachill_event_perk_text', true );
		$fields = self::event_fields( $attrs, (string) $post->post_title, (string) get_permalink( $post ), $perk, (string) ( $data['title'] ?? '' ) );

		$fields['address'] = self::venue_address( (string) ( $attrs['venue'] ?? '' ) );

		$tokens = BrandTokens::get( $this->get_id(), $data );

		$renderer->create_canvas( $preset );
		if ( ! $renderer->get_image() ) {
			return array();
		}

		$this->draw( $renderer, $fields, $data, $tokens );

		$filename = sprintf( 'event-poster-%d-%s.%s', $post->ID, $preset, 'jpeg' === $format ? 'jpg' : 'png' );
		$path     = ( ! empty( $context['pipeline_id'] ) && ! empty( $context['flow_id'] ) )
			? $renderer->save_to_repository( $filename, $context, $format )
			: $renderer->save_temp( $format );

		return $path ? array( $path ) : array();
	}

	/**
	 * Draw the poster onto an already-created canvas.
	 *
	 * @param GDRenderer $r      Renderer with a canvas.
	 * @param array      $fields Output of event_fields() plus address.
	 * @param array      $data   Template input.
	 * @param array      $tokens Brand tokens.
	 */
	private function draw( GDRenderer $r, array $fields, array $data, array $tokens ): void {
		$width  = $r->get_width();
		$height = $r->get_height();
		$u      = $width / self::BASE_WIDTH;
		$px     = static fn( float $n ): int => (int) round( $n * $u );

		$r->register_font( 'heading', (string) ( $tokens['fonts']['heading'] ?? '' ) );
		$r->register_font( 'body', (string) ( $tokens['fonts']['body'] ?? '' ) );

		// Dark-mode palette (theme root.css `prefers-color-scheme: dark`),
		// falling back to the light-mode-derived tokens when absent.
		$c      = (array) ( $tokens['colors'] ?? array() );
		$bg_hex = (string) ( $c['dark_background'] ?? $c['background_dark'] ?? '#1a1a1a' );
		$bg     = $r->color_hex( 'bg', $bg_hex );
		$text   = $r->color_hex( 'text', (string) ( $c['dark_text'] ?? $c['text_inverse'] ?? '#e5e5e5' ) );
		$muted  = $r->color_hex( 'muted', (string) ( $c['dark_muted'] ?? $c['text_muted'] ?? '#b0b0b0' ) );
		$accent = $r->color_hex( 'accent', (string) ( $c['dark_accent'] ?? $c['accent'] ?? '#53940b' ) );
		$ink    = $r->color_hex( 'ink', $bg_hex );
		$r->fill( $bg );
		self::draw_stripes( $r, $width, $height, $u );

		if ( $width > $height ) {
			$this->draw_landscape( $r, $fields, $data, $tokens, compact( 'text', 'muted', 'accent', 'ink' ) );
			return;
		}

		$margin = $px( self::MARGIN );
		$max_w  = $width - 2 * $margin;

		// Brand mark, top-right.
		$logo   = (string) ( $tokens['logo_path_inverse'] ?? '' );
		$logo_h = $px( self::LOGO_HEIGHT );
		$logo_w = 0;
		$dims   = ( '' !== $logo && is_readable( $logo ) ) ? getimagesize( $logo ) : false;
		if ( $dims ) {
			$logo_w = (int) round( $dims[0] * $logo_h / max( 1, $dims[1] ) );
		}

		// Footer block: QR on the right, RSVP + note on the left.
		$qr_path = self::qr_image_path( $fields['qr_url'] );
		$qr_size = $qr_path ? $px( self::QR_SIZE ) : 0;
		$foot_w  = $max_w - ( $qr_size ? $qr_size + $px( 30 ) : 0 );
		$rsvp    = (string) ( $data['rsvp_text'] ?? '' );
		$rsvp    = '' !== $rsvp ? $rsvp : self::default_rsvp_text( $fields['qr_url'] );
		$note    = (string) ( $data['footer_note'] ?? '' );
		$cobrand = array_values( array_filter( (array) ( $data['cobrand_logos'] ?? array() ), static fn( $p ) => is_string( $p ) && is_readable( $p ) ) );
		$cb_h    = $px( self::COBRAND_HEIGHT );

		$rsvp_h   = $r->measure_text_height( $rsvp, $px( self::RSVP_SIZE ), 'body', $foot_w, 1.5 );
		$note_h   = ( '' !== $note || $cobrand ) ? max( $cb_h, $r->measure_text_height( $note, $px( self::NOTE_SIZE ), 'body', $foot_w, 1.3 ) ) + $px( 14 ) : 0;
		$footer_h = max( $rsvp_h + $note_h, $qr_size ) + $px( 20 );

		// Content block: shrink the title until it clears the banner.
		$kicker  = (string) ( $data['kicker'] ?? '' );
		$kicker  = '' !== $kicker ? $kicker : mb_strtoupper( trim( (string) ( $tokens['brand_text'] ?? '' ) . ' presents' ) );
		$title_w = $max_w - ( $logo_w ? $logo_w + $px( 30 ) : 0 );
		$lines   = array_values( array_filter( array( (string) ( $data['tagline'] ?? '' ), (string) ( $data['subline'] ?? '' ) ) ) );

		$title_size = $px( self::TITLE_MAX_SIZE );
		do {
			$content_h = $this->content_height( $r, $fields, $kicker, $lines, $title_size, $title_w, $max_w, $px );
			$plan      = self::plan_layout( $height, $margin, $content_h, $px( self::BANNER_HEIGHT ), $footer_h, $px( self::MIN_BANNER_GAP ) );
			if ( $plan['fits'] || $title_size <= $px( self::TITLE_MIN_SIZE ) ) {
				break;
			}
			$title_size -= $px( 4 );
		} while ( true );

		$y = $plan['content_top'];

		if ( $logo_w ) {
			$r->overlay_image( $logo, $width - $margin - $logo_w, max( $px( 20 ), $y - $px( 20 ) ), $logo_w, $logo_h );
		}

		$y = $r->draw_text_wrapped( $kicker, $px( self::KICKER_SIZE ), $margin, $y, $accent, 'body', $max_w, 1.3 ) + $px( 24 );
		$y = $r->draw_text_wrapped( $fields['title'], $title_size, $margin, $y, $text, 'heading', $title_w, 1.08 ) + $px( 30 );
		$r->filled_rect( $margin, $y, $margin + $px( 140 ), $y + $px( 10 ), $accent );
		$y += $px( 40 );
		foreach ( $lines as $i => $line ) {
			$y = $r->draw_text_wrapped( $line, $px( self::BODY_SIZE ), $margin, $y, 0 === $i ? $text : $muted, 'body', $max_w, 1.35 ) + $px( 10 );
		}
		$y += $px( 40 );
		if ( '' !== $fields['date_line'] ) {
			$y = $r->draw_text_wrapped( $fields['date_line'], $px( self::DETAIL_SIZE ), $margin, $y, $text, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['venue'] ) {
			$y = $r->draw_text_wrapped( $fields['venue'], $px( self::DETAIL_SIZE ), $margin, $y, $accent, 'heading', $max_w, 1.15 );
		}
		if ( '' !== $fields['address'] ) {
			$r->draw_text_wrapped( $fields['address'], $px( self::ADDRESS_SIZE ), $margin, $y + $px( 6 ), $muted, 'body', $max_w, 1.4 );
		}

		// Banner.
		$banner_top = $plan['banner_top'];
		$banner_h   = $px( self::BANNER_HEIGHT );
		$r->filled_rect( $margin, $banner_top, $width - $margin, $banner_top + $banner_h, $accent );
		if ( '' !== $fields['banner'] ) {
			$size = $px( self::BANNER_TEXT_SIZE );
			while ( $size > 12 && $r->measure_text_width( $fields['banner'], $size, 'heading' ) > $max_w - $px( 60 ) ) {
				--$size;
			}
			$r->draw_text( $fields['banner'], $size, $margin + $px( 30 ), $banner_top + (int) ( ( $banner_h + $size ) / 2 ), $ink, 'heading' );
		}

		// Footer.
		$fy = $banner_top + $banner_h + $px( 20 );
		if ( $qr_path ) {
			$r->overlay_image( $qr_path, $width - $margin - $qr_size, $fy, $qr_size, $qr_size );
			wp_delete_file( $qr_path );
		}
		$fy = $r->draw_text_wrapped( $rsvp, $px( self::RSVP_SIZE ), $margin, $fy, $text, 'body', $foot_w, 1.5 ) + $px( 14 );
		$nx = $margin;
		foreach ( $cobrand as $logo_path ) {
			$cb_dims = getimagesize( $logo_path );
			if ( ! $cb_dims ) {
				continue;
			}
			$cb_w = (int) round( $cb_dims[0] * $cb_h / max( 1, $cb_dims[1] ) );
			$r->overlay_image( $logo_path, $nx, $fy, $cb_w, $cb_h );
			$nx += $cb_w + $px( 16 );
		}
		if ( '' !== $note ) {
			$r->draw_text_wrapped( $note, $px( self::NOTE_SIZE ), $nx, $fy + (int) ( ( $cb_h - $px( self::NOTE_SIZE ) ) / 2 ) - $px( 4 ), $muted, 'body', $foot_w - ( $nx - $margin ), 1.3 );
		}
	}

	/**
	 * Two-column landscape layout (16:9 featured / Open Graph images).
	 *
	 * Left column: kicker, title, accent rule, tagline, date, venue.
	 * Right column: brand logo at the top, QR + "SCAN TO RSVP" above the
	 * banner. Full-width accent banner along the bottom, then co-brand
	 * logos and the footer note. Sizes are design pixels at 1080 tall.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param array      $fields Output of event_fields() plus address.
	 * @param array      $data   Template input.
	 * @param array      $tokens Brand tokens.
	 * @param array      $colors Allocated colors: text, muted, accent, ink.
	 */
	private function draw_landscape( GDRenderer $r, array $fields, array $data, array $tokens, array $colors ): void {
		$width  = $r->get_width();
		$height = $r->get_height();
		$u      = $height / self::LANDSCAPE_BASE_HEIGHT;
		$px     = static fn( float $n ): int => (int) round( $n * $u );
		$margin = $px( self::LANDSCAPE_MARGIN );

		$logo   = (string) ( $tokens['logo_path_inverse'] ?? '' );
		$logo_h = $px( self::LANDSCAPE_LOGO_HEIGHT );
		$dims   = ( '' !== $logo && is_readable( $logo ) ) ? getimagesize( $logo ) : false;
		$logo_w = $dims ? (int) round( $dims[0] * $logo_h / max( 1, $dims[1] ) ) : 0;

		$qr_path = self::qr_image_path( $fields['qr_url'] );
		$qr_size = $qr_path ? $px( self::LANDSCAPE_QR_SIZE ) : 0;

		$kicker  = (string) ( $data['kicker'] ?? '' );
		$kicker  = '' !== $kicker ? $kicker : mb_strtoupper( trim( (string) ( $tokens['brand_text'] ?? '' ) . ' presents' ) );
		$tagline = (string) ( $data['tagline'] ?? '' );
		$note    = (string) ( $data['footer_note'] ?? '' );
		$cobrand = array_values( array_filter( (array) ( $data['cobrand_logos'] ?? array() ), static fn( $p ) => is_string( $p ) && is_readable( $p ) ) );

		$banner_h = $px( self::LANDSCAPE_BANNER_HEIGHT );
		$footer_h = ( '' !== $note || $cobrand ) ? $px( 60 ) : 0;
		$right_w  = max( $logo_w, $qr_size );
		$left_w   = self::landscape_left_width( $width, $margin, $right_w, $px( 80 ) );

		$title_size = $px( self::LANDSCAPE_TITLE_MAX );
		do {
			$content_h = $r->measure_text_height( $kicker, $px( 30 ), 'body', $left_w, 1.3 ) + $px( 20 )
				+ $r->measure_text_height( $fields['title'], $title_size, 'heading', $left_w, 1.05 ) + $px( 26 ) + $px( 44 )
				+ ( '' !== $tagline ? $r->measure_text_height( $tagline, $px( 36 ), 'body', $left_w, 1.35 ) + $px( 30 ) : 0 )
				+ ( '' !== $fields['date_line'] ? $r->measure_text_height( $fields['date_line'], $px( 62 ), 'heading', $left_w, 1.15 ) : 0 )
				+ ( '' !== $fields['venue'] ? $r->measure_text_height( $fields['venue'], $px( 62 ), 'heading', $left_w, 1.15 ) : 0 );
			$plan      = self::plan_layout( $height, $margin, $content_h, $banner_h, $footer_h, $px( 40 ) );
			if ( $plan['fits'] || $title_size <= $px( self::LANDSCAPE_TITLE_MIN ) ) {
				break;
			}
			$title_size -= $px( 4 );
		} while ( true );

		$banner_top = $plan['banner_top'];

		if ( $logo_w ) {
			$r->overlay_image( $logo, $width - $margin - $logo_w, max( 0, $margin - $px( 20 ) ), $logo_w, $logo_h );
		}
		if ( $qr_path ) {
			$qr_x = $width - $margin - $qr_size;
			$qr_y = $banner_top - $px( 40 ) - $px( 34 ) - $qr_size;
			$r->overlay_image( $qr_path, $qr_x, $qr_y, $qr_size, $qr_size );
			$r->draw_text_wrapped( 'SCAN TO RSVP', $px( 22 ), $qr_x, $qr_y + $qr_size + $px( 6 ), $colors['muted'], 'body', $qr_size, 1.3, 'center' );
			wp_delete_file( $qr_path );
		}

		$y = $margin;
		$y = $r->draw_text_wrapped( $kicker, $px( 30 ), $margin, $y, $colors['accent'], 'body', $left_w, 1.3 ) + $px( 20 );
		$y = $r->draw_text_wrapped( $fields['title'], $title_size, $margin, $y, $colors['text'], 'heading', $left_w, 1.05 ) + $px( 26 );
		$r->filled_rect( $margin, $y, $margin + $px( 180 ), $y + $px( 12 ), $colors['accent'] );
		$y += $px( 44 );
		if ( '' !== $tagline ) {
			$y = $r->draw_text_wrapped( $tagline, $px( 36 ), $margin, $y, $colors['text'], 'body', $left_w, 1.35 ) + $px( 30 );
		}
		if ( '' !== $fields['date_line'] ) {
			$y = $r->draw_text_wrapped( $fields['date_line'], $px( 62 ), $margin, $y, $colors['text'], 'heading', $left_w, 1.15 );
		}
		if ( '' !== $fields['venue'] ) {
			$r->draw_text_wrapped( $fields['venue'], $px( 62 ), $margin, $y, $colors['accent'], 'heading', $left_w, 1.15 );
		}

		$r->filled_rect( $margin, $banner_top, $width - $margin, $banner_top + $banner_h, $colors['accent'] );
		if ( '' !== $fields['banner'] ) {
			$size = $px( 46 );
			while ( $size > 12 && $r->measure_text_width( $fields['banner'], $size, 'heading' ) > $width - 2 * $margin - $px( 80 ) ) {
				--$size;
			}
			$r->draw_text( $fields['banner'], $size, $margin + $px( 40 ), $banner_top + (int) ( ( $banner_h + $size ) / 2 ), $colors['ink'], 'heading' );
		}

		$fy   = $banner_top + $banner_h + $px( 16 );
		$nx   = $margin;
		$cb_h = $px( 36 );
		foreach ( $cobrand as $logo_path ) {
			$cb_dims = getimagesize( $logo_path );
			if ( ! $cb_dims ) {
				continue;
			}
			$cb_w = (int) round( $cb_dims[0] * $cb_h / max( 1, $cb_dims[1] ) );
			$r->overlay_image( $logo_path, $nx, $fy, $cb_w, $cb_h );
			$nx += $cb_w + $px( 14 );
		}
		if ( '' !== $note ) {
			$r->draw_text( $note, $px( 24 ), $nx, $fy + $px( 27 ), $colors['muted'], 'body' );
		}
	}

	/**
	 * Width of the landscape layout's left text column.
	 *
	 * @param int $width   Canvas width.
	 * @param int $margin  Outer margin.
	 * @param int $right_w Right column width (max of logo and QR widths).
	 * @param int $gutter  Gap between the columns.
	 */
	public static function landscape_left_width( int $width, int $margin, int $right_w, int $gutter ): int {
		return $width - 2 * $margin - ( $right_w > 0 ? $right_w + $gutter : 0 );
	}

	/**
	 * Subtle diagonal stripes for texture, as on the approved prototype.
	 *
	 * Near-transparent white lines at a 45-degree angle, spaced 1/18 of
	 * the canvas width, so the background reads as textured, not flat.
	 *
	 * @param GDRenderer $r      Renderer with a filled canvas.
	 * @param int        $width  Canvas width.
	 * @param int        $height Canvas height.
	 * @param float      $u      Scale unit (width / 1080).
	 */
	private static function draw_stripes( GDRenderer $r, int $width, int $height, float $u ): void {
		$stripe = $r->color( 'stripe', 255, 255, 255, self::STRIPE_ALPHA );
		$step   = max( 1, (int) ( $width / 18 ) );
		$weight = max( 2, (int) round( 4 * $u ) );
		for ( $x = -$height; $x < $width; $x += $step ) {
			$r->draw_line( $x, $height, $x + $height, 0, $stripe, $weight );
		}
	}

	/**
	 * Height of the content block (kicker through address) at a title size.
	 *
	 * Mirrors the vertical advances in draw(); keep the two in sync.
	 */
	private function content_height( GDRenderer $r, array $fields, string $kicker, array $lines, int $title_size, int $title_w, int $max_w, callable $px ): int {
		$h  = $r->measure_text_height( $kicker, $px( self::KICKER_SIZE ), 'body', $max_w, 1.3 ) + $px( 24 );
		$h += $r->measure_text_height( $fields['title'], $title_size, 'heading', $title_w, 1.08 ) + $px( 30 ) + $px( 40 );
		foreach ( $lines as $line ) {
			$h += $r->measure_text_height( $line, $px( self::BODY_SIZE ), 'body', $max_w, 1.35 ) + $px( 10 );
		}
		$h += $px( 40 );
		foreach ( array( 'date_line', 'venue' ) as $key ) {
			if ( '' !== $fields[ $key ] ) {
				$h += $r->measure_text_height( $fields[ $key ], $px( self::DETAIL_SIZE ), 'heading', $max_w, 1.15 );
			}
		}
		if ( '' !== $fields['address'] ) {
			$h += $px( 6 ) + $r->measure_text_height( $fields['address'], $px( self::ADDRESS_SIZE ), 'body', $max_w, 1.4 );
		}
		return $h;
	}

	/**
	 * Place the content block and banner on a canvas of the given height.
	 *
	 * The banner and footer are pinned to the bottom margin. Spare space
	 * between the content block and the banner is split so the content is
	 * not stranded at the top of tall canvases.
	 *
	 * @param int $height         Canvas height.
	 * @param int $margin         Outer margin.
	 * @param int $content_height Height of kicker..address block.
	 * @param int $banner_height  Banner height.
	 * @param int $footer_height  Footer height below the banner.
	 * @param int $min_gap        Minimum gap between content and banner.
	 * @return array{fits: bool, content_top: int, content_bottom: int, banner_top: int, footer_bottom: int}
	 */
	public static function plan_layout( int $height, int $margin, int $content_height, int $banner_height, int $footer_height, int $min_gap ): array {
		$banner_top = $height - $margin - $footer_height - $banner_height;
		$slack      = $banner_top - $min_gap - ( $margin + $content_height );
		$top        = $margin + ( $slack > 0 ? (int) floor( $slack * self::SLACK_ABOVE ) : 0 );

		return array(
			'fits'           => $slack >= 0,
			'content_top'    => $top,
			'content_bottom' => $top + $content_height,
			'banner_top'     => $banner_top,
			'footer_bottom'  => $banner_top + $banner_height + $footer_height,
		);
	}

	/**
	 * Normalize event data into the strings drawn on the poster.
	 *
	 * @param array  $attrs     event-details block attributes.
	 * @param string $title     Event post title (may contain entities).
	 * @param string $permalink Event permalink; also the QR payload.
	 * @param string $perk      RSVP perk text.
	 * @param string $headline  Optional poster headline overriding the title.
	 * @return array{title: string, date_line: string, venue: string, banner: string, qr_url: string, address: string}
	 */
	public static function event_fields( array $attrs, string $title, string $permalink, string $perk, string $headline = '' ): array {
		return array(
			'title'     => mb_strtoupper( html_entity_decode( '' !== trim( $headline ) ? $headline : $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'date_line' => self::date_line( $attrs ),
			'venue'     => mb_strtoupper( html_entity_decode( (string) ( $attrs['venue'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'banner'    => self::banner_text( (string) ( $attrs['price'] ?? '' ), $perk ),
			'qr_url'    => $permalink,
			'address'   => '',
		);
	}

	/**
	 * "WED, OCT 21 / 6:30–9PM" from block attributes.
	 *
	 * @param array $attrs event-details block attributes.
	 */
	public static function date_line( array $attrs ): string {
		$date = (string) ( $attrs['startDate'] ?? '' );
		$ts   = '' !== $date ? strtotime( $date . ' 12:00:00 UTC' ) : false;
		if ( false === $ts ) {
			return '';
		}
		$line = strtoupper( gmdate( 'D, M j', $ts ) );
		$time = self::time_range( (string) ( $attrs['startTime'] ?? '' ), (string) ( $attrs['endTime'] ?? '' ) );
		return '' !== $time ? $line . ' / ' . $time : $line;
	}

	/**
	 * "6:30–9PM" (shared meridiem collapsed), "11AM–1PM", or "8PM".
	 *
	 * @param string $start HH:MM[:SS].
	 * @param string $end   HH:MM[:SS] or empty.
	 */
	public static function time_range( string $start, string $end ): string {
		$fmt                     = static function ( string $t ): array {
			$ts = strtotime( '2000-01-01 ' . $t . ' UTC' );
			if ( false === $ts || '' === $t ) {
				return array( '', '' );
			}
			$clock = '00' === gmdate( 'i', $ts ) ? gmdate( 'g', $ts ) : gmdate( 'g:i', $ts );
			return array( $clock, gmdate( 'A', $ts ) );
		};
		list( $s_clock, $s_mer ) = $fmt( $start );
		list( $e_clock, $e_mer ) = $fmt( $end );
		if ( '' === $s_clock ) {
			return '';
		}
		if ( '' === $e_clock ) {
			return $s_clock . $s_mer;
		}
		return ( $s_mer === $e_mer ? $s_clock : $s_clock . $s_mer ) . "\u{2013}" . $e_clock . $e_mer;
	}

	/**
	 * Banner copy: price and perk, uppercase, each ending in a period.
	 *
	 * @param string $price Price attribute (e.g. "Free", "$10").
	 * @param string $perk  Perk text.
	 */
	public static function banner_text( string $price, string $perk ): string {
		$parts = array();
		foreach ( array( $price, $perk ) as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$parts[] = rtrim( $part, '.!' ) . '.';
			}
		}
		return mb_strtoupper( implode( ' ', $parts ) );
	}

	/**
	 * Default RSVP line: "RSVP at <host><path>".
	 *
	 * @param string $url Event permalink.
	 */
	public static function default_rsvp_text( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' !== $host ? 'RSVP at ' . $host : '';
	}

	/**
	 * Attributes of the first data-machine-events/event-details block.
	 *
	 * @param string $content Post content.
	 */
	public static function event_details_attrs( string $content ): array {
		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				return (array) $block['attrs'];
			}
		}
		return array();
	}

	/**
	 * "2038 Meeting Street Rd, Charleston" from the venue term's meta.
	 *
	 * @param string $venue Venue name from the event block.
	 */
	private static function venue_address( string $venue ): string {
		if ( '' === $venue || ! taxonomy_exists( 'venue' ) ) {
			return '';
		}
		$term = get_term_by( 'name', $venue, 'venue' );
		if ( ! $term ) {
			return '';
		}
		$parts = array_filter(
			array(
				trim( (string) get_term_meta( $term->term_id, '_venue_address', true ) ),
				trim( (string) get_term_meta( $term->term_id, '_venue_city', true ) ),
			)
		);
		return implode( ', ', $parts );
	}

	/**
	 * Render the QR for the event URL to a temp PNG via the network ability.
	 *
	 * @param string $url QR payload.
	 * @return string|null Temp file path, or null when QR is unavailable.
	 */
	private static function qr_image_path( string $url ): ?string {
		if ( '' === $url || ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}
		$ability = wp_get_ability( 'extrachill/generate-qr-code' );
		if ( ! $ability ) {
			return null;
		}
		$result = $ability->execute(
			array(
				'url'  => $url,
				'size' => 600,
			)
		);
		if ( ! is_array( $result ) || empty( $result['image'] ) ) {
			return null;
		}
		$path = wp_tempnam( 'event-poster-qr' );
		$png  = base64_decode( (string) $result['image'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the QR ability returns a base64 PNG.
		if ( ! $path || false === file_put_contents( $path, $png ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		return $path;
	}
}
