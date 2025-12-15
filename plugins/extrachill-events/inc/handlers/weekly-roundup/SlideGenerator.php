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
		'background' => array( 26, 26, 26 ),       // #1a1a1a
		'text'       => array( 229, 229, 229 ),          // #e5e5e5
		'muted'      => array( 176, 176, 176 ),         // #b0b0b0
		'day_header' => array( 159, 197, 232 ),    // #9fc5e8
	);

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
	 * @param array $day_groups Array of day groups from Calendar_Query::group_events_by_date()
	 * @param array $context Storage context with pipeline_id and flow_id
	 * @return array Array of generated image file paths
	 */
	public function generate_slides( array $day_groups, array $context ): array {
		$slides_data = $this->distribute_days_to_slides( $day_groups );
		$image_paths = array();

		foreach ( $slides_data as $index => $slide_days ) {
			$image_path = $this->render_slide( $slide_days, $index + 1, $context );
			if ( $image_path ) {
				$image_paths[] = $image_path;
			}
		}

		return $image_paths;
	}

	/**
	 * Distribute day groups across slides based on available height.
	 *
	 * @param array $day_groups Day-grouped events
	 * @return array Array of slides, each containing day groups that fit
	 */
	private function distribute_days_to_slides( array $day_groups ): array {
		$slides           = array();
		$current_slide    = array();
		$current_height   = self::PADDING;
		$available_height = self::HEIGHT - ( self::PADDING * 2 );

		foreach ( $day_groups as $date_key => $day_group ) {
			$day_height = $this->calculate_day_height( $day_group );

			if ( $current_height + $day_height <= $available_height ) {
				$current_slide[ $date_key ] = $day_group;
				$current_height            += $day_height;
			} else {
				if ( ! empty( $current_slide ) ) {
					$slides[] = $current_slide;
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
	 * Calculate pixel height needed for a day group.
	 *
	 * @param array $day_group Day group with events array
	 * @return int Height in pixels
	 */
	private function calculate_day_height( array $day_group ): int {
		$events      = $day_group['events'] ?? array();
		$event_count = count( $events );

		$day_header_height = (int) ( self::DAY_HEADER_SIZE * self::LINE_HEIGHT_MULTIPLIER ) + 20;
		$event_height      = (int) ( ( self::EVENT_TITLE_SIZE + self::EVENT_META_SIZE ) * self::LINE_HEIGHT_MULTIPLIER ) + 15;

		return $day_header_height + ( $event_count * $event_height ) + 30;
	}

	/**
	 * Render a single slide image.
	 *
	 * @param array $slide_days Day groups for this slide
	 * @param int   $slide_number Slide number (1-based)
	 * @param array $context Storage context with pipeline_id and flow_id
	 * @return string|null File path on success, null on failure
	 */
	private function render_slide( array $slide_days, int $slide_number, array $context ): ?string {
		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( ! $image ) {
			return null;
		}

		$bg_color         = imagecolorallocate( $image, ...self::COLORS['background'] );
		$text_color       = imagecolorallocate( $image, ...self::COLORS['text'] );
		$muted_color      = imagecolorallocate( $image, ...self::COLORS['muted'] );
		$day_header_color = imagecolorallocate( $image, ...self::COLORS['day_header'] );

		imagefill( $image, 0, 0, $bg_color );

		$y = self::PADDING;

		foreach ( $slide_days as $date_key => $day_group ) {
			$y = $this->render_day_group( $image, $day_group, $y, $text_color, $muted_color, $day_header_color );
		}

		$file_path = $this->save_image( $image, $slide_number, $context );
		imagedestroy( $image );

		return $file_path;
	}

	/**
	 * Render a day group (header + events) on the image.
	 *
	 * @param resource $image GD image resource
	 * @param array    $day_group Day group data
	 * @param int      $y Current Y position
	 * @param int      $text_color Text color
	 * @param int      $muted_color Muted text color
	 * @param int      $day_header_color Day header color
	 * @return int New Y position after rendering
	 */
	private function render_day_group( $image, array $day_group, int $y, int $text_color, int $muted_color, int $day_header_color ): int {
		$date_obj = $day_group['date_obj'] ?? null;
		$events   = $day_group['events'] ?? array();

		$day_label = $date_obj ? strtoupper( $date_obj->format( 'l, M j' ) ) : 'UNKNOWN DATE';
		imagettftext( $image, self::DAY_HEADER_SIZE, 0, self::PADDING, $y + self::DAY_HEADER_SIZE, $day_header_color, $this->header_font_path, $day_label );
		$y += (int) ( self::DAY_HEADER_SIZE * self::LINE_HEIGHT_MULTIPLIER ) + 20;

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

		$meta_line = $venue;
		if ( $formatted_time ) {
			$meta_line .= $venue ? ' - ' . $formatted_time : $formatted_time;
		}

		imagettftext( $image, self::EVENT_TITLE_SIZE, 0, self::PADDING, $y + self::EVENT_TITLE_SIZE, $text_color, $this->body_font_path, $title );
		$y += (int) ( self::EVENT_TITLE_SIZE * self::LINE_HEIGHT_MULTIPLIER );

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
