<?php
/**
 * Instagram Carousel Slide Generator
 *
 * GD-based image generation for event roundup slides.
 * Creates 1080x1350px images with event listings grouped by day.
 *
 * @package ExtraChillEvents\Handlers\WeeklyRoundup
 */

namespace ExtraChillEvents\Handlers\WeeklyRoundup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SlideGenerator {

	private const WIDTH  = 1080;
	private const HEIGHT = 1350;

	private const COLORS = array(
		'background'       => array( 26, 26, 26 ),   // #1a1a1a
		'text'             => array( 229, 229, 229 ), // #e5e5e5
		'muted'            => array( 176, 176, 176 ), // #b0b0b0
		'title_underline'  => array( 83, 148, 11 ),   // #53940b (accent green)
	);

	private const DAY_COLORS = array(
		'sunday'    => array( 255, 107, 107 ), // #ff6b6b
		'monday'    => array( 78, 205, 196 ),  // #4ecdc4
		'tuesday'   => array( 69, 183, 209 ),  // #45b7d1
		'wednesday' => array( 150, 206, 180 ), // #96ceb4
		'thursday'  => array( 254, 202, 87 ),  // #feca57
		'friday'    => array( 214, 51, 132 ),  // #d63384
		'saturday'  => array( 84, 160, 255 ),  // #54a0ff
	);

	private const TITLE_SIZE            = 36;
	private const TITLE_UNDERLINE_HEIGHT = 2;
	private const TITLE_UNDERLINE_GAP    = 8;

	private const PADDING                = 60;
	private const DAY_HEADER_SIZE        = 28;
	private const EVENT_TITLE_SIZE       = 22;
	private const EVENT_META_SIZE        = 18;
	private const LINE_HEIGHT_MULTIPLIER = 1.4;

	private string $header_font_path;
	private string $body_font_path;

	public function __construct() {
		$this->header_font_path = $this->resolve_header_font_path();
		$this->body_font_path   = $this->resolve_body_font_path();
	}

	/**
	 * Generate carousel slides from day-grouped events.
	 *
	 * @param array  $day_groups Array of day groups from Calendar_Query::group_events_by_date()
	 * @param array  $context Storage context with pipeline_id and flow_id
	 * @param string $title Optional title for first slide
	 * @return array Array of generated image file paths
	 */
	public function generate_slides( array $day_groups, array $context, string $title = '' ): array {
		$slides_data = $this->distribute_days_to_slides( $day_groups, $title );
		$image_paths = array();

		foreach ( $slides_data as $index => $slide_days ) {
			$slide_title = ( $index === 0 ) ? $title : '';
			$image_path  = $this->render_slide( $slide_days, $index + 1, $context, $slide_title );
			if ( $image_path ) {
				$image_paths[] = $image_path;
			}
		}

		return $image_paths;
	}

	/**
	 * Distribute day groups across slides based on available height.
	 *
	 * @param array  $day_groups Day-grouped events
	 * @param string $title Optional title (affects first slide height)
	 * @return array Array of slides, each containing day groups that fit
	 */
	private function distribute_days_to_slides( array $day_groups, string $title = '' ): array {
		$slides           = array();
		$current_slide    = array();
		$available_height = self::HEIGHT - ( self::PADDING * 2 );
		$is_first_slide   = true;

		$title_height = 0;
		if ( $title !== '' ) {
			$title_height = $this->calculate_title_height( $title );
		}

		$current_height = self::PADDING + ( $is_first_slide ? $title_height : 0 );

		foreach ( $day_groups as $date_key => $day_group ) {
			$day_height = $this->calculate_day_height( $day_group );

			if ( $current_height + $day_height <= $available_height ) {
				$current_slide[ $date_key ] = $day_group;
				$current_height            += $day_height;
			} else {
				if ( ! empty( $current_slide ) ) {
					$slides[] = $current_slide;
					$is_first_slide = false;
				}
				$current_slide  = array( $date_key => $day_group );
				$current_height = self::PADDING + $day_height;
			}
		}

		if ( ! empty( $current_slide ) ) {
			$slides[] = $current_slide;
		}

		return $slides;
	}

	/**
	 * Calculate pixel height needed for title block.
	 *
	 * @param string $title Title text
	 * @return int Height in pixels
	 */
	private function calculate_title_height( string $title ): int {
		if ( $title === '' ) {
			return 0;
		}

		$max_width = self::WIDTH - ( self::PADDING * 2 );
		$lines = $this->wrap_text( $title, self::TITLE_SIZE, $this->header_font_path, $max_width );
		$line_height = (int) ( self::TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );
		$text_height = count( $lines ) * $line_height;

		return $text_height + self::TITLE_UNDERLINE_GAP + self::TITLE_UNDERLINE_HEIGHT + 30;
	}

	/**
	 * Calculate pixel height needed for a day group.
	 *
	 * @param array $day_group Day group with events array
	 * @return int Height in pixels
	 */
	private function calculate_day_height( array $day_group ): int {
		$events = $day_group['events'] ?? array();

		$day_header_height = (int) ( self::DAY_HEADER_SIZE * self::LINE_HEIGHT_MULTIPLIER ) + 20;
		$max_width = self::WIDTH - ( self::PADDING * 2 );

		$events_height = 0;
		foreach ( $events as $event_item ) {
			$post = $event_item['post'] ?? null;
			$title = $post ? $post->post_title : 'Untitled Event';

			$title_height = $this->get_wrapped_text_height( $title, self::EVENT_TITLE_SIZE, $this->body_font_path, $max_width );
			$meta_height = (int) ( self::EVENT_META_SIZE * self::LINE_HEIGHT_MULTIPLIER );

			$events_height += $title_height + $meta_height + 15;
		}

		return $day_header_height + $events_height + 30;
	}

	/**
	 * Render a single slide image.
	 *
	 * @param array  $slide_days Day groups for this slide
	 * @param int    $slide_number Slide number (1-based)
	 * @param array  $context Storage context with pipeline_id and flow_id
	 * @param string $title Optional title for this slide
	 * @return string|null File path on success, null on failure
	 */
	private function render_slide( array $slide_days, int $slide_number, array $context, string $title = '' ): ?string {
		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( ! $image ) {
			return null;
		}

		$bg_color         = imagecolorallocate( $image, ...self::COLORS['background'] );
		$text_color       = imagecolorallocate( $image, ...self::COLORS['text'] );
		$muted_color      = imagecolorallocate( $image, ...self::COLORS['muted'] );
		$underline_color  = imagecolorallocate( $image, ...self::COLORS['title_underline'] );

		imagefill( $image, 0, 0, $bg_color );

		$y = self::PADDING;

		if ( $title !== '' ) {
			$y = $this->render_title( $image, $title, $y, $text_color, $underline_color );
		}

		foreach ( $slide_days as $date_key => $day_group ) {
			$y = $this->render_day_group( $image, $day_group, $y, $text_color, $muted_color );
		}

		$file_path = $this->save_image( $image, $slide_number, $context );
		imagedestroy( $image );

		return $file_path;
	}

	/**
	 * Render title with accent underline on first slide.
	 *
	 * @param resource $image GD image resource
	 * @param string   $title Title text
	 * @param int      $y Current Y position
	 * @param int      $text_color Text color
	 * @param int      $underline_color Underline color
	 * @return int New Y position after rendering
	 */
	private function render_title( $image, string $title, int $y, int $text_color, int $underline_color ): int {
		$max_width = self::WIDTH - ( self::PADDING * 2 );
		$lines = $this->wrap_text( $title, self::TITLE_SIZE, $this->header_font_path, $max_width );
		$line_height = (int) ( self::TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );

		$max_line_width = 0;
		foreach ( $lines as $line ) {
			imagettftext( $image, self::TITLE_SIZE, 0, self::PADDING, $y + self::TITLE_SIZE, $text_color, $this->header_font_path, $line );

			$bbox = imagettfbbox( self::TITLE_SIZE, 0, $this->header_font_path, $line );
			$line_width = abs( $bbox[4] - $bbox[0] );
			$max_line_width = max( $max_line_width, $line_width );

			$y += $line_height;
		}

		$y += self::TITLE_UNDERLINE_GAP;
		imagefilledrectangle(
			$image,
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
	 * Render a day group (header + events) on the image.
	 *
	 * @param resource $image GD image resource
	 * @param array    $day_group Day group data
	 * @param int      $y Current Y position
	 * @param int      $text_color Text color
	 * @param int      $muted_color Muted text color
	 * @return int New Y position after rendering
	 */
	private function render_day_group( $image, array $day_group, int $y, int $text_color, int $muted_color ): int {
		$date_obj = $day_group['date_obj'] ?? null;
		$events   = $day_group['events'] ?? array();

		$day_name = $date_obj ? strtolower( $date_obj->format( 'l' ) ) : 'monday';
		$day_color_rgb = self::DAY_COLORS[ $day_name ] ?? self::DAY_COLORS['monday'];
		$day_header_color = imagecolorallocate( $image, ...$day_color_rgb );

		$day_label = $date_obj ? strtoupper( $date_obj->format( 'l, M j' ) ) : 'UNKNOWN DATE';
		imagettftext( $image, self::DAY_HEADER_SIZE, 0, self::PADDING, $y + self::DAY_HEADER_SIZE, $day_header_color, $this->header_font_path, $day_label );
		$y += (int) ( self::DAY_HEADER_SIZE * self::LINE_HEIGHT_MULTIPLIER ) + 20;

		usort( $events, function ( $a, $b ) {
			$time_a = $a['event_data']['startTime'] ?? '23:59:59';
			$time_b = $b['event_data']['startTime'] ?? '23:59:59';
			return strcmp( $time_a, $time_b );
		} );

		foreach ( $events as $event_item ) {
			$y = $this->render_event( $image, $event_item, $y, $text_color, $muted_color );
		}

		$y += 30;

		return $y;
	}

	/**
	 * Render a single event on the image.
	 *
	 * @param resource $image GD image resource
	 * @param array    $event_item Event data
	 * @param int      $y Current Y position
	 * @param int      $text_color Text color
	 * @param int      $muted_color Muted text color
	 * @return int New Y position after rendering
	 */
	private function render_event( $image, array $event_item, int $y, int $text_color, int $muted_color ): int {
		$post       = $event_item['post'] ?? null;
		$event_data = $event_item['event_data'] ?? array();

		$title      = $post ? $post->post_title : 'Untitled Event';
		$venue      = $event_data['venue'] ?? '';
		$start_time = $event_data['startTime'] ?? '';

		$formatted_time = '';
		if ( $start_time ) {
			$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
			if ( $time_obj ) {
				$formatted_time = $time_obj->format( 'g:i A' );
			}
		}

		$meta_parts = array_filter( array( $venue, $formatted_time ) );
		$meta_line  = implode( ' · ', $meta_parts );

		$max_width = self::WIDTH - ( self::PADDING * 2 );
		$title_lines = $this->wrap_text( $title, self::EVENT_TITLE_SIZE, $this->body_font_path, $max_width );
		$title_line_height = (int) ( self::EVENT_TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );

		foreach ( $title_lines as $line ) {
			imagettftext( $image, self::EVENT_TITLE_SIZE, 0, self::PADDING, $y + self::EVENT_TITLE_SIZE, $text_color, $this->body_font_path, $line );
			$y += $title_line_height;
		}

		if ( $meta_line ) {
			imagettftext( $image, self::EVENT_META_SIZE, 0, self::PADDING, $y + self::EVENT_META_SIZE, $muted_color, $this->body_font_path, $meta_line );
			$y += (int) ( self::EVENT_META_SIZE * self::LINE_HEIGHT_MULTIPLIER );
		}

		$y += 15;

		return $y;
	}

	/**
	 * Save the generated image to the files repository.
	 *
	 * @param resource $image GD image resource
	 * @param int      $slide_number Slide number
	 * @param array    $context Storage context with pipeline_id and flow_id
	 * @return string|null File path on success
	 */
	private function save_image( $image, int $slide_number, array $context ): ?string {
		$storage = new \DataMachine\Core\FilesRepository\FileStorage();

		$temp_file = tempnam( sys_get_temp_dir(), 'slide_' );
		$png_path  = $temp_file . '.png';

		if ( ! imagepng( $image, $png_path, 9 ) ) {
			@unlink( $temp_file );
			return null;
		}

		@unlink( $temp_file );

		$filename = sprintf( 'roundup-slide-%d.png', $slide_number );

		$stored_path = $storage->store_file( $png_path, $filename, $context );
		@unlink( $png_path );

		return $stored_path ? $stored_path : null;
	}

	/**
	 * Resolve header font path (Wilco Loft Sans for day headers).
	 *
	 * @return string Absolute path to TTF font
	 */
	private function resolve_header_font_path(): string {
		$theme_font = \get_template_directory() . '/assets/fonts/WilcoLoftSans-Treble.ttf';
		if ( file_exists( $theme_font ) ) {
			return $theme_font;
		}

		$theme_lobster = \get_template_directory() . '/assets/fonts/Lobster_Two/LobsterTwo-Regular.ttf';
		if ( file_exists( $theme_lobster ) ) {
			return $theme_lobster;
		}

		return '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
	}

	/**
	 * Resolve body font path (Helvetica for event text).
	 *
	 * @return string Absolute path to TTF font
	 */
	private function resolve_body_font_path(): string {
		$theme_font = \get_template_directory() . '/assets/fonts/helvetica.ttf';
		if ( file_exists( $theme_font ) ) {
			return $theme_font;
		}

		return '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
	}

	/**
	 * Wrap text to fit within max width.
	 *
	 * @param string $text Text to wrap
	 * @param int    $font_size Font size in points
	 * @param string $font_path Path to TTF font
	 * @param int    $max_width Maximum width in pixels
	 * @return array Array of text lines
	 */
	private function wrap_text( string $text, int $font_size, string $font_path, int $max_width ): array {
		$words = explode( ' ', $text );
		$lines = array();
		$current_line = '';

		foreach ( $words as $word ) {
			$test_line = $current_line === '' ? $word : $current_line . ' ' . $word;
			$bbox = imagettfbbox( $font_size, 0, $font_path, $test_line );
			$line_width = abs( $bbox[4] - $bbox[0] );

			if ( $line_width <= $max_width ) {
				$current_line = $test_line;
			} else {
				if ( $current_line !== '' ) {
					$lines[] = $current_line;
				}
				$current_line = $word;
			}
		}

		if ( $current_line !== '' ) {
			$lines[] = $current_line;
		}

		return $lines;
	}

	/**
	 * Calculate wrapped text height.
	 *
	 * @param string $text Text to measure
	 * @param int    $font_size Font size in points
	 * @param string $font_path Path to TTF font
	 * @param int    $max_width Maximum width in pixels
	 * @return int Height in pixels
	 */
	private function get_wrapped_text_height( string $text, int $font_size, string $font_path, int $max_width ): int {
		$lines = $this->wrap_text( $text, $font_size, $font_path, $max_width );
		$line_height = (int) ( $font_size * self::LINE_HEIGHT_MULTIPLIER );
		return count( $lines ) * $line_height;
	}

	/**
	 * Build text summary of events for AI caption generation.
	 *
	 * @param array $day_groups Day-grouped events
	 * @return string Plain text summary
	 */
	public function build_event_summary( array $day_groups ): string {
		$lines = array();

		foreach ( $day_groups as $date_key => $day_group ) {
			$date_obj = $day_group['date_obj'] ?? null;
			$events   = $day_group['events'] ?? array();

			$day_label = $date_obj ? $date_obj->format( 'l, M j' ) : 'Unknown Date';
			$lines[]   = $day_label . ':';

			foreach ( $events as $event_item ) {
				$post       = $event_item['post'] ?? null;
				$event_data = $event_item['event_data'] ?? array();

				$title      = $post ? $post->post_title : 'Untitled';
				$venue      = $event_data['venue'] ?? '';
				$start_time = $event_data['startTime'] ?? '';

				$formatted_time = '';
				if ( $start_time ) {
					$time_obj = \DateTime::createFromFormat( 'H:i:s', $start_time );
					if ( $time_obj ) {
						$formatted_time = $time_obj->format( 'g:i A' );
					}
				}

				$event_line = "- {$title}";
				if ( $venue ) {
					$event_line .= " @ {$venue}";
				}
				if ( $formatted_time ) {
					$event_line .= " ({$formatted_time})";
				}

				$lines[] = $event_line;
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
