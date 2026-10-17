<?php
/**
 * Venue-scoped promo graphics ability.
 *
 * Lets an authorized venue member render Instagram-ready carousel slides of
 * their own upcoming shows from the venue workspace. Composes the internal
 * event roundup builder (EventRoundupAbilities::executeBuild) but with a
 * venue-member permission model instead of the staff `upload_files` gate:
 *
 *   - Permission is VenueAuthorization::ACTION_ACCESS_VENUE on the requested
 *     venue, the same gate as the profile and calendar-feed workspace tabs.
 *   - The venue taxonomy filter is forced to that one term inside the
 *     execute callback. The input schema accepts no tax_filters or location,
 *     so a member can never widen the render to another venue.
 *   - When the venue has a usable logo it is passed to the roundup template
 *     as `partner_logo_path`, co-branding the cover and closing slides. The
 *     Extra Chill mark stays on every slide.
 *
 * Slides are returned as base64 PNG data URIs so the browser can preview and
 * download them directly; the server temp files are deleted after encoding,
 * so nothing needs storage cleanup or an expiry.
 *
 * Exposed through core's ability REST runner (`show_in_rest`); it is a plain
 * authenticated JSON operation with no transport requirement that would
 * warrant a bespoke route.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueGraphicsAbilities {

	/**
	 * Longest renderable range in days (inclusive). Two full months is the
	 * widest promo artifact worth producing; anything longer stops being a
	 * carousel and starts being a yearbook.
	 */
	public const MAX_RANGE_DAYS = 62;

	private static bool $registered = false;

	/**
	 * Exact venue membership authorization service.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Roundup builder, lazily constructed unless injected for tests.
	 *
	 * @var EventRoundupAbilities|null
	 */
	private $roundup;

	/**
	 * Construct the ability registrar.
	 *
	 * @param VenueAuthorization|null    $authorization Optional authorization service.
	 * @param EventRoundupAbilities|null $roundup       Optional roundup builder.
	 */
	public function __construct( ?VenueAuthorization $authorization = null, ?EventRoundupAbilities $roundup = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->roundup       = $roundup;

		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the venue graphics contract. */
	public function register(): void {
		wp_register_ability(
			'extrachill/venue-graphics-build',
			array(
				'label'               => __( 'Venue Graphics Build', 'extrachill-events' ),
				'description'         => __( 'Render Instagram-ready carousel slides of one authorized venue\'s upcoming shows for a date range. Returns the slides as PNG data URIs.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Events-site venue term ID.', 'extrachill-events' ),
						),
						'date_start'    => array(
							'type'        => 'string',
							'description' => __( 'Start date (Y-m-d).', 'extrachill-events' ),
						),
						'date_end'      => array(
							'type'        => 'string',
							'description' => __( 'End date (Y-m-d). The range is capped at 62 days.', 'extrachill-events' ),
						),
						'layout'        => array(
							'type'        => 'string',
							'enum'        => array( 'daily', 'calendar' ),
							'description' => __( 'Optional list layout. Defaults to "daily" for a week or less and "calendar" for longer ranges.', 'extrachill-events' ),
						),
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'Optional cover title. Defaults to "This Week at <Venue>" or "<Venue> · <Month>".', 'extrachill-events' ),
						),
					),
					'required'             => array( 'venue_term_id', 'date_start', 'date_end' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'venue_term_id' => array( 'type' => 'integer' ),
						'venue_name'    => array( 'type' => 'string' ),
						'date_start'    => array( 'type' => 'string' ),
						'date_end'      => array( 'type' => 'string' ),
						'layout'        => array( 'type' => 'string' ),
						'title'         => array( 'type' => 'string' ),
						'total_events'  => array( 'type' => 'integer' ),
						'slide_count'   => array( 'type' => 'integer' ),
						'slides'        => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'filename' => array( 'type' => 'string' ),
									'data_uri' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);
	}

	/**
	 * Gate: an active member of this exact venue may render its graphics.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_access_venue( array $input ) {
		return $this->authorization->authorize(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			VenueAuthorization::ACTION_ACCESS_VENUE
		);
	}

	/**
	 * Build the carousel for one authorized venue.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute( array $input ) {
		$venue_term_id = absint( $input['venue_term_id'] ?? 0 );

		// Re-authorize inside execute so direct ability consumers (CLI,
		// agents) get the same gate as the REST runner.
		$allowed = $this->authorization->authorize( get_current_user_id(), $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$range = $this->validate_range( (string) ( $input['date_start'] ?? '' ), (string) ( $input['date_end'] ?? '' ) );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$venue_name = $this->venue_name( $venue_term_id );
		$layout     = $this->resolve_layout( (string) ( $input['layout'] ?? '' ), $range );
		$title      = $this->resolve_title( (string) ( $input['title'] ?? '' ), $venue_name, $range );

		// The venue filter is forced to this one term, built here from the
		// authorized venue_term_id alone. Caller-supplied tax_filters or
		// location are not part of this ability's contract, so a member can
		// never render another venue's calendar.
		$build_input = array(
			'date_start'        => $range['date_start'],
			'date_end'          => $range['date_end'],
			'tax_filters'       => array( 'venue' => array( $venue_term_id ) ),
			'title'             => $title,
			'cover'             => true,
			'closing'           => true,
			'layout'            => $layout,
			'partner_logo_path' => $this->venue_logo_path( $venue_term_id ),
		);

		$result = $this->builder()->executeBuild( $build_input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['success'] ) || empty( $result['image_paths'] ) ) {
			return new \WP_Error(
				'venue_graphics_no_events',
				sprintf(
					/* translators: 1: venue name, 2: start date, 3: end date. */
					__( 'No published shows were found for %1$s between %2$s and %3$s. Graphics are built from the public Extra Chill calendar, so events must be published there first — connect a calendar feed or submit your shows, then try again.', 'extrachill-events' ),
					'' !== $venue_name ? $venue_name : __( 'this venue', 'extrachill-events' ),
					$range['date_start'],
					$range['date_end']
				),
				array( 'status' => 404 )
			);
		}

		$slides = $this->encode_slides( (array) $result['image_paths'], $venue_term_id, $range['date_start'] );
		if ( is_wp_error( $slides ) ) {
			return $slides;
		}

		return array(
			'success'       => true,
			'venue_term_id' => $venue_term_id,
			'venue_name'    => $venue_name,
			'date_start'    => $range['date_start'],
			'date_end'      => $range['date_end'],
			'layout'        => $layout,
			'title'         => $title,
			'total_events'  => (int) ( $result['total_events'] ?? 0 ),
			'slide_count'   => count( $slides ),
			'slides'        => $slides,
		);
	}

	/**
	 * Validate the requested date range and enforce the range cap.
	 *
	 * @param string $date_start Start date (Y-m-d).
	 * @param string $date_end   End date (Y-m-d).
	 * @return array{date_start: string, date_end: string, days: int}|\WP_Error
	 */
	private function validate_range( string $date_start, string $date_end ) {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_start );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date_end );

		if ( ! $start || $start->format( 'Y-m-d' ) !== $date_start || ! $end || $end->format( 'Y-m-d' ) !== $date_end ) {
			return new \WP_Error(
				'venue_graphics_invalid_range',
				__( 'Provide date_start and date_end as Y-m-d dates.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		if ( $end < $start ) {
			return new \WP_Error(
				'venue_graphics_invalid_range',
				__( 'date_end must be on or after date_start.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$days = (int) $start->diff( $end )->days + 1;
		if ( $days > self::MAX_RANGE_DAYS ) {
			return new \WP_Error(
				'venue_graphics_range_too_long',
				sprintf(
					/* translators: %d: maximum number of days. */
					__( 'Graphics cover at most %d days per run. Pick a shorter range — a month works well — and generate again for the rest.', 'extrachill-events' ),
					self::MAX_RANGE_DAYS
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
			'days'       => $days,
		);
	}

	/**
	 * Default layout: daily lists for a week or less, compact calendar rows
	 * for longer ranges such as a venue's month.
	 *
	 * @param string $layout Requested layout, or ''.
	 * @param array  $range  Validated range.
	 * @return string 'daily' or 'calendar'.
	 */
	private function resolve_layout( string $layout, array $range ): string {
		if ( in_array( $layout, array( 'daily', 'calendar' ), true ) ) {
			return $layout;
		}
		return $range['days'] > 7 ? 'calendar' : 'daily';
	}

	/**
	 * Default cover title: "This Week at <Venue>" for a week or less,
	 * "<Venue> · <Month>" for a single-month range, else the venue name.
	 *
	 * @param string $title      Requested title, or ''.
	 * @param string $venue_name Venue display name.
	 * @param array  $range      Validated range.
	 * @return string
	 */
	private function resolve_title( string $title, string $venue_name, array $range ): string {
		$title = trim( $title );
		if ( '' !== $title ) {
			return $title;
		}
		if ( '' === $venue_name ) {
			return '';
		}
		if ( $range['days'] <= 7 ) {
			/* translators: %s: venue name. */
			return sprintf( __( 'This Week at %s', 'extrachill-events' ), $venue_name );
		}

		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $range['date_start'] );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $range['date_end'] );
		if ( $start && $end && $start->format( 'Y-m' ) === $end->format( 'Y-m' ) ) {
			return $venue_name . ' · ' . $start->format( 'F' );
		}

		return $venue_name;
	}

	/**
	 * Display name of the venue term, or ''.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return string
	 */
	private function venue_name( int $venue_term_id ): string {
		$term = get_term( $venue_term_id, 'venue' );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		return (string) $term->name;
	}

	/**
	 * Local file path of the venue's logo, or '' when none is usable.
	 *
	 * The attachment ID in `_venue_logo_attachment_id` is the source of
	 * truth (edited in the workspace profile tab). When the canonical
	 * resolver is present it also vets the attachment, so a deleted or
	 * non-image attachment degrades to today's un-co-branded output.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return string
	 */
	private function venue_logo_path( int $venue_term_id ): string {
		$attachment_id = absint( get_term_meta( $venue_term_id, '_venue_logo_attachment_id', true ) );
		if ( $attachment_id < 1 ) {
			return '';
		}

		if ( class_exists( '\\DataMachineEvents\\Core\\Venue_Taxonomy' )
			&& null === \DataMachineEvents\Core\Venue_Taxonomy::resolve_venue_logo( $attachment_id ) ) {
			return '';
		}

		$path = function_exists( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : '';
		return ( is_string( $path ) && '' !== $path && is_readable( $path ) ) ? $path : '';
	}

	/**
	 * Encode rendered temp files as data URIs and delete them.
	 *
	 * @param array  $image_paths   Rendered slide file paths.
	 * @param int    $venue_term_id Venue term ID (for stable filenames).
	 * @param string $date_start    Range start (for stable filenames).
	 * @return array|\WP_Error List of { filename, data_uri }.
	 */
	private function encode_slides( array $image_paths, int $venue_term_id, string $date_start ) {
		$slides = array();

		foreach ( array_values( $image_paths ) as $index => $path ) {
			$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file produced by this request's render.
			if ( false !== $contents ) {
				$slides[] = array(
					'filename' => sprintf( 'venue-%d-shows-%s-slide-%d.png', $venue_term_id, $date_start, $index + 1 ),
					'data_uri' => 'data:image/png;base64,' . base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Data URI payload for in-browser preview/download.
				);
			}
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		if ( empty( $slides ) ) {
			return new \WP_Error(
				'venue_graphics_encode_failed',
				__( 'The slides were rendered but could not be read back. Try again.', 'extrachill-events' ),
				array( 'status' => 500 )
			);
		}

		return $slides;
	}

	/** Resolve the roundup builder, constructing it on first use. */
	private function builder(): EventRoundupAbilities {
		if ( ! $this->roundup instanceof EventRoundupAbilities ) {
			$this->roundup = new EventRoundupAbilities();
		}
		return $this->roundup;
	}
}
