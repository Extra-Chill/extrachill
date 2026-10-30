<?php
/**
 * Event Roundup Abilities
 *
 * WordPress 6.9 Abilities API integration for event roundup operations.
 * Provides three abilities:
 *
 *   - extrachill/event-roundup-query   — query events for a date range
 *                                         and group by day
 *   - extrachill/event-roundup-render  — render slides from day_groups
 *                                         (consumes datamachine/render-image-template)
 *   - extrachill/event-roundup-build   — one-shot combo: query + render
 *                                         (the primary CLI / agent / on-demand entry point)
 *
 * Date range native: accepts arbitrary Y-m-d inputs OR weekday-name shortcuts
 * (e.g. week_start_day=thursday). Location accepts taxonomy term ID or slug.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use DataMachineEvents\Blocks\Calendar\Query\ScopeResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventRoundupAbilities {

	/**
	 * Capability shared by every roundup ability.
	 *
	 * Build composes query + render, so the three abilities must agree;
	 * otherwise the composable query -> render path is stricter than the
	 * one-shot build that calls the same query internally (#932). The query
	 * reads public event data and render writes an image, so the image-upload
	 * capability is the gate for all three.
	 */
	public const ROUNDUP_CAPABILITY = 'upload_files';

	private static bool $registered = false;

	/**
	 * Shared permission callback for the query, render, and build abilities.
	 */
	public static function canRunRoundup(): bool {
		return current_user_can( self::ROUNDUP_CAPABILITY );
	}

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		$this->registerQueryAbility();
		$this->registerRenderAbility();
		$this->registerBuildAbility();
	}

	private function registerQueryAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-query',
			array(
				'label'               => __( 'Event Roundup Query', 'extrachill-events' ),
				'description'         => __( 'Query events for a roundup by date range and location. Accepts arbitrary date ranges or weekday-name shortcuts.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_start'       => array(
							'type'        => 'string',
							'description' => __( 'Start date (Y-m-d). Mutually exclusive with week_start_day.', 'extrachill-events' ),
						),
						'date_end'         => array(
							'type'        => 'string',
							'description' => __( 'End date (Y-m-d). Defaults to date_start if omitted (single-day roundup).', 'extrachill-events' ),
						),
						'week_start_day'   => array(
							'type'        => 'string',
							'description' => __( 'Weekday-name shortcut: resolves to next occurrence (e.g. "thursday").', 'extrachill-events' ),
						),
						'week_end_day'     => array(
							'type'        => 'string',
							'description' => __( 'Weekday-name shortcut: resolves to next occurrence after week_start_day (e.g. "sunday").', 'extrachill-events' ),
						),
						'scope'            => array(
							'type'        => 'string',
							'enum'        => array( 'today', 'tonight', 'this-weekend', 'this-week' ),
							'description' => __( 'Named date scope shared with the calendar API. Ignored when date_start or week_start_day is provided.', 'extrachill-events' ),
						),
						'location'         => array(
							'type'        => 'string',
							'description' => __( 'Location taxonomy term slug or numeric term ID. Optional.', 'extrachill-events' ),
						),
						'location_term_id' => array(
							'type'        => 'integer',
							'description' => __( 'Deprecated alias for location (numeric term ID). Use location instead.', 'extrachill-events' ),
						),
						'tax_filters'      => array(
							'type'                 => 'object',
							'description'          => __( 'Extra taxonomy filters as { taxonomy: [slugs or term IDs] }, e.g. { "event_type": ["concert"] }. Terms within a taxonomy match any; taxonomies combine. Unknown taxonomies or terms are rejected.', 'extrachill-events' ),
							'additionalProperties' => array(
								'type'  => 'array',
								'items' => array( 'type' => array( 'string', 'integer' ) ),
							),
						),
						'venue_tier'       => array(
							'type'        => array( 'string', 'array' ),
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Venue tier slug(s), passed to the events query. Several tiers (array or comma-separated) match any of them.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'date_start'    => array( 'type' => 'string' ),
						'date_end'      => array( 'type' => 'string' ),
						'location_name' => array( 'type' => 'string' ),
						'total_events'  => array( 'type' => 'integer' ),
						'day_groups'    => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'object' ),
						),
						'event_summary' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeQuery' ),
				'permission_callback' => array( self::class, 'canRunRoundup' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRenderAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-render',
			array(
				'label'               => __( 'Event Roundup Render', 'extrachill-events' ),
				'description'         => __( 'Render Instagram carousel images from day-grouped event data via the event_roundup template.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'background'        => array(
							'type'  => array( 'string', 'integer', 'array' ),
							'items' => array( 'type' => 'integer' ),
						),
						'location'          => array( 'type' => 'string' ),
						'day_groups'        => array(
							'type'                 => 'object',
							'description'          => __( 'Date-keyed day groups from event-roundup-query.', 'extrachill-events' ),
							'additionalProperties' => array( 'type' => 'object' ),
						),
						'title'             => array(
							'type'        => 'string',
							'description' => __( 'Optional title for first slide.', 'extrachill-events' ),
						),
						'cta_text'          => array(
							'type'        => 'string',
							'description' => __( 'Optional footer CTA. Defaults to the current site host.', 'extrachill-events' ),
						),
						'cover'             => array(
							'type'        => 'boolean',
							'description' => __( 'Prepend a cover slide with the title, date range, and show/venue counts.', 'extrachill-events' ),
						),
						'closing'           => array(
							'type'        => 'boolean',
							'description' => __( 'Append a closing CTA slide and drop the per-slide footer.', 'extrachill-events' ),
						),
						'closing_text'      => array(
							'type'        => 'string',
							'description' => __( 'Optional supporting line on the closing slide.', 'extrachill-events' ),
						),
						'layout'            => array(
							'type'        => 'string',
							'enum'        => array( 'daily', 'calendar' ),
							'description' => __( 'List layout. "daily" (default) gives each day a header; "calendar" puts one compact row per show with the date in a left column, for long ranges such as a venue\'s month.', 'extrachill-events' ),
						),
						'partner_logo_path' => array(
							'type'        => 'string',
							'description' => __( 'Optional co-brand image path (e.g. a venue logo) drawn on the cover and closing slides. The Extra Chill mark stays on every slide.', 'extrachill-events' ),
						),
						'storage_context'   => array(
							'type'        => 'object',
							'description' => __( 'Optional storage context with pipeline_id and flow_id. When omitted, renders to temp files.', 'extrachill-events' ),
						),
					),
					'required'   => array( 'day_groups' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'image_paths' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'slide_count' => array( 'type' => 'integer' ),
						'slides'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'message'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeRender' ),
				'permission_callback' => array( self::class, 'canRunRoundup' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerBuildAbility(): void {
		wp_register_ability(
			'extrachill/event-roundup-build',
			array(
				'label'               => __( 'Event Roundup Build', 'extrachill-events' ),
				'description'         => __( 'One-shot: query events for a date range and location, then render the carousel slides. Returns image paths plus event metadata.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'background'        => array(
							'type'  => array( 'string', 'integer', 'array' ),
							'items' => array( 'type' => 'integer' ),
						),
						'date_start'        => array(
							'type'        => 'string',
							'description' => __( 'Start date (Y-m-d). Defaults to today.', 'extrachill-events' ),
						),
						'date_end'          => array(
							'type'        => 'string',
							'description' => __( 'End date (Y-m-d). Defaults to date_start (single-day roundup).', 'extrachill-events' ),
						),
						'scope'             => array(
							'type'        => 'string',
							'enum'        => array( 'today', 'tonight', 'this-weekend', 'this-week' ),
							'description' => __( 'Named date scope shared with the calendar API. Ignored when date_start is provided.', 'extrachill-events' ),
						),
						'location'          => array(
							'type'        => 'string',
							'description' => __( 'Location taxonomy term slug or numeric term ID. Optional.', 'extrachill-events' ),
						),
						'tax_filters'       => array(
							'type'                 => 'object',
							'description'          => __( 'Extra taxonomy filters as { taxonomy: [slugs or term IDs] }, e.g. { "event_type": ["concert"] }. Terms within a taxonomy match any; taxonomies combine. Unknown taxonomies or terms are rejected.', 'extrachill-events' ),
							'additionalProperties' => array(
								'type'  => 'array',
								'items' => array( 'type' => array( 'string', 'integer' ) ),
							),
						),
						'venue_tier'        => array(
							'type'        => array( 'string', 'array' ),
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Venue tier slug(s), passed to the events query. Several tiers (array or comma-separated) match any of them.', 'extrachill-events' ),
						),
						'title'             => array(
							'type'        => 'string',
							'description' => __( 'Optional title for the first slide.', 'extrachill-events' ),
						),
						'cta_text'          => array(
							'type'        => 'string',
							'description' => __( 'Optional footer CTA. Defaults to the current site host.', 'extrachill-events' ),
						),
						'cover'             => array(
							'type'        => 'boolean',
							'description' => __( 'Prepend a cover slide with the title, date range, and show/venue counts.', 'extrachill-events' ),
						),
						'closing'           => array(
							'type'        => 'boolean',
							'description' => __( 'Append a closing CTA slide and drop the per-slide footer.', 'extrachill-events' ),
						),
						'closing_text'      => array(
							'type'        => 'string',
							'description' => __( 'Optional supporting line on the closing slide.', 'extrachill-events' ),
						),
						'layout'            => array(
							'type'        => 'string',
							'enum'        => array( 'daily', 'calendar' ),
							'description' => __( 'List layout. "daily" (default) gives each day a header; "calendar" puts one compact row per show with the date in a left column, for long ranges such as a venue\'s month.', 'extrachill-events' ),
						),
						'partner_logo_path' => array(
							'type'        => 'string',
							'description' => __( 'Optional co-brand image path (e.g. a venue logo) drawn on the cover and closing slides. The Extra Chill mark stays on every slide.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'image_paths'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'slide_count'   => array( 'type' => 'integer' ),
						'slides'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'date_start'    => array( 'type' => 'string' ),
						'date_end'      => array( 'type' => 'string' ),
						'location_name' => array( 'type' => 'string' ),
						'total_events'  => array( 'type' => 'integer' ),
						'event_summary' => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'executeBuild' ),
				'permission_callback' => array( self::class, 'canRunRoundup' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	public function executeQuery( array $input ): array|\WP_Error {
		$date_range = $this->resolveDateRange( $input );
		if ( \is_wp_error( $date_range ) ) {
			return $date_range;
		}

		$location_term_id = $this->resolveLocationTermId( $input );

		$tax_filters = self::resolveTaxFilters( (array) ( $input['tax_filters'] ?? array() ) );
		if ( \is_wp_error( $tax_filters ) ) {
			return $tax_filters;
		}

		$day_groups = $this->queryEvents(
			$date_range['date_start'],
			$date_range['date_end'],
			$location_term_id,
			(string) ( $date_range['time_start'] ?? '' ),
			(string) ( $date_range['time_end'] ?? '' ),
			$tax_filters,
			$input['venue_tier'] ?? ''
		);

		if ( empty( $day_groups ) ) {
			return array(
				'date_start'    => $date_range['date_start'],
				'date_end'      => $date_range['date_end'],
				'location_name' => $this->getLocationName( $location_term_id ),
				'total_events'  => 0,
				'day_groups'    => array(),
				'event_summary' => '',
			);
		}

		return array(
			'date_start'    => $date_range['date_start'],
			'date_end'      => $date_range['date_end'],
			'location_name' => $this->getLocationName( $location_term_id ),
			'total_events'  => $this->countEvents( $day_groups ),
			'day_groups'    => $day_groups,
			'event_summary' => $this->buildEventSummary( $day_groups ),
		);
	}

	public function executeRender( array $input ): array|\WP_Error {
		$day_groups      = $input['day_groups'] ?? array();
		$title           = (string) ( $input['title'] ?? '' );
		$cta_text        = (string) ( $input['cta_text'] ?? '' );
		$storage_context = (array) ( $input['storage_context'] ?? array() );

		if ( empty( $day_groups ) ) {
			return new \WP_Error( 'missing_day_groups', __( 'day_groups is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'abilities_unavailable', __( 'Abilities API not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$ability = \wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			return new \WP_Error( 'template_renderer_missing', __( 'datamachine/render-image-template ability not registered.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$result = $ability->execute(
			array(
				'template_id' => 'event_roundup',
				'data'        => array_merge(
					array(
						'day_groups' => $day_groups,
						'title'      => $title,
						'cta_text'   => $cta_text,
					),
					self::slideOptions( $input )
				),
				'preset'      => 'instagram_feed_portrait',
				'format'      => 'png',
				'context'     => $storage_context,
			)
		);

		if ( \is_wp_error( $result ) ) {
			return new \WP_Error( 'generation_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		$image_paths = (array) ( $result['file_paths'] ?? array() );

		if ( empty( $image_paths ) ) {
			return new \WP_Error( 'generation_failed', __( 'Failed to generate carousel images.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		return array(
			'success'     => true,
			'image_paths' => $image_paths,
			'slide_count' => count( $image_paths ),
			'slides'      => \ExtraChillEvents\Templates\EventRoundupTemplate::$last_slides,
			'message'     => sprintf(
				/* translators: %d: number of generated slides */
				__( 'Generated %d carousel slides.', 'extrachill-events' ),
				count( $image_paths )
			),
		);
	}

	public function executeBuild( array $input ): array|\WP_Error {
		// Default to the shared calendar API's today scope when no date input is provided.
		if ( empty( $input['date_start'] ) && empty( $input['week_start_day'] ) ) {
			$input['scope'] = (string) ( $input['scope'] ?? 'today' );
		}

		$query_result = $this->executeQuery( $input );
		if ( \is_wp_error( $query_result ) ) {
			return $query_result;
		}

		if ( empty( $query_result['day_groups'] ) ) {
			return array(
				'success'       => false,
				'image_paths'   => array(),
				'slide_count'   => 0,
				'date_start'    => $query_result['date_start'],
				'date_end'      => $query_result['date_end'],
				'location_name' => $query_result['location_name'],
				'total_events'  => 0,
				'event_summary' => '',
				'message'       => __( 'No events found for the requested date range and location.', 'extrachill-events' ),
			);
		}

		$render_result = $this->executeRender(
			array_merge(
				array(
					'day_groups' => $query_result['day_groups'],
					'title'      => (string) ( $input['title'] ?? '' ),
					'cta_text'   => (string) ( $input['cta_text'] ?? '' ),
				),
				self::slideOptions( $input )
			)
		);
		if ( \is_wp_error( $render_result ) ) {
			return $render_result;
		}

		return array(
			'success'       => true,
			'image_paths'   => $render_result['image_paths'],
			'slide_count'   => $render_result['slide_count'],
			'slides'        => $render_result['slides'],
			'date_start'    => $query_result['date_start'],
			'date_end'      => $query_result['date_end'],
			'location_name' => $query_result['location_name'],
			'total_events'  => $query_result['total_events'],
			'event_summary' => $query_result['event_summary'],
			'message'       => $render_result['message'],
		);
	}

	/**
	 * Resolve date_start / date_end from inputs.
	 *
	 * Precedence: explicit Y-m-d dates > weekday-name shortcuts > named scope > error.
	 * date_end defaults to date_start (single-day roundup).
	 *
	 * @return array{date_start: string, date_end: string, time_start?: string, time_end?: string}|\WP_Error
	 */
	private function resolveDateRange( array $input ): array|\WP_Error {
		$date_start = isset( $input['date_start'] ) ? trim( (string) $input['date_start'] ) : '';
		$date_end   = isset( $input['date_end'] ) ? trim( (string) $input['date_end'] ) : '';

		if ( '' !== $date_start ) {
			if ( ! $this->isValidDate( $date_start ) ) {
				return new \WP_Error(
					'invalid_date_start',
					sprintf(
						/* translators: %s: invalid date string */
						__( 'date_start "%s" is not a valid Y-m-d date.', 'extrachill-events' ),
						$date_start
					),
					array( 'status' => 400 )
				);
			}

			if ( '' === $date_end ) {
				$date_end = $date_start;
			} elseif ( ! $this->isValidDate( $date_end ) ) {
				return new \WP_Error(
					'invalid_date_end',
					sprintf(
						/* translators: %s: invalid date string */
						__( 'date_end "%s" is not a valid Y-m-d date.', 'extrachill-events' ),
						$date_end
					),
					array( 'status' => 400 )
				);
			}

			if ( $date_end < $date_start ) {
				return new \WP_Error( 'invalid_date_range', __( 'date_end must be on or after date_start.', 'extrachill-events' ), array( 'status' => 400 ) );
			}

			return array(
				'date_start' => $date_start,
				'date_end'   => $date_end,
			);
		}

		$week_start_day = isset( $input['week_start_day'] ) ? strtolower( trim( (string) $input['week_start_day'] ) ) : '';
		$week_end_day   = isset( $input['week_end_day'] ) ? strtolower( trim( (string) $input['week_end_day'] ) ) : '';

		if ( '' !== $week_start_day && '' !== $week_end_day ) {
			return $this->resolveNextWeekdayRange( $week_start_day, $week_end_day );
		}

		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : '';
		if ( '' !== $scope && class_exists( ScopeResolver::class ) ) {
			$scope_range = ScopeResolver::resolve( $scope );
			if ( $scope_range ) {
				$range      = array(
					'date_start' => (string) $scope_range['date_start'],
					'date_end'   => (string) $scope_range['date_end'],
				);
				$time_start = (string) ( $scope_range['time_start'] ?? '' );
				$time_end   = (string) ( $scope_range['time_end'] ?? '' );
				if ( '' !== $time_start ) {
					$range['time_start'] = $time_start;
				}
				if ( '' !== $time_end ) {
					$range['time_end'] = $time_end;
				}
				return $range;
			}
		}

		return new \WP_Error(
			'missing_date_inputs',
			__( 'Provide date_start (Y-m-d), both week_start_day and week_end_day, or a valid scope.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve weekday-name shortcuts to the next matching date range.
	 *
	 * @return array{date_start: string, date_end: string}
	 */
	private function resolveNextWeekdayRange( string $week_start_day, string $week_end_day ): array {
		$now       = new \DateTime( 'now', \wp_timezone() );
		$start_obj = ( clone $now )->modify( 'next ' . $week_start_day )->setTime( 0, 0, 0 );
		$end_obj   = ( clone $start_obj )->modify( 'next ' . $week_end_day )->setTime( 0, 0, 0 );

		if ( $end_obj <= $start_obj ) {
			$end_obj = $end_obj->modify( '+7 days' );
		}

		return array(
			'date_start' => $start_obj->format( 'Y-m-d' ),
			'date_end'   => $end_obj->format( 'Y-m-d' ),
		);
	}

	private function isValidDate( string $date ): bool {
		$parsed = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Resolve a `location` input (slug or numeric ID) into a term ID.
	 *
	 * Returns 0 when no location filter requested. Falls back to the
	 * legacy `location_term_id` integer input when `location` isn't set.
	 */
	private function resolveLocationTermId( array $input ): int {
		$location = $input['location'] ?? null;
		if ( null !== $location && '' !== $location ) {
			if ( is_numeric( $location ) ) {
				return (int) $location;
			}

			$term = \get_term_by( 'slug', (string) $location, 'location' );
			if ( $term instanceof \WP_Term ) {
				return (int) $term->term_id;
			}

			$term = \get_term_by( 'name', (string) $location, 'location' );
			if ( $term instanceof \WP_Term ) {
				return (int) $term->term_id;
			}

			return 0;
		}

		return (int) ( $input['location_term_id'] ?? 0 );
	}

	/**
	 * Resolve caller tax filters ({ taxonomy: [slug|id] }) to term IDs.
	 *
	 * Only taxonomies registered for the event post type are accepted, and
	 * every term must exist, so a typo fails loudly instead of silently
	 * widening or emptying the roundup.
	 *
	 * @param array $raw Raw tax_filters input.
	 * @return array<string, int[]>|\WP_Error
	 */
	public static function resolveTaxFilters( array $raw ) {
		$post_type = class_exists( '\\DataMachineEvents\\Core\\Event_Post_Type' ) ? \DataMachineEvents\Core\Event_Post_Type::POST_TYPE : 'data_machine_events';
		$allowed   = \get_object_taxonomies( $post_type );
		$resolved  = array();

		foreach ( $raw as $taxonomy => $terms ) {
			$taxonomy = \sanitize_key( (string) $taxonomy );
			$terms    = is_array( $terms ) ? $terms : explode( ',', (string) $terms );
			if ( ! in_array( $taxonomy, $allowed, true ) ) {
				return new \WP_Error(
					'unknown_taxonomy',
					/* translators: %s: taxonomy slug */
					sprintf( __( 'Unknown event taxonomy: %s', 'extrachill-events' ), $taxonomy ),
					array( 'status' => 400 )
				);
			}

			foreach ( $terms as $term_ref ) {
				$term_ref = trim( (string) $term_ref );
				if ( '' === $term_ref ) {
					continue;
				}
				$term = ctype_digit( $term_ref )
					? \get_term( (int) $term_ref, $taxonomy )
					: \get_term_by( 'slug', \sanitize_title( $term_ref ), $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return new \WP_Error(
						'unknown_term',
						/* translators: 1: term slug or ID, 2: taxonomy slug */
						sprintf( __( 'Unknown %2$s term: %1$s', 'extrachill-events' ), $term_ref, $taxonomy ),
						array( 'status' => 400 )
					);
				}
				$resolved[ $taxonomy ][] = (int) $term->term_id;
			}

			if ( isset( $resolved[ $taxonomy ] ) ) {
				$resolved[ $taxonomy ] = array_values( array_unique( $resolved[ $taxonomy ] ) );
			}
		}

		return $resolved;
	}

	/**
	 * Query and group roundup events.
	 *
	 * @param string       $date_start       Range start (Y-m-d).
	 * @param string       $date_end         Range end (Y-m-d).
	 * @param int          $location_term_id Location term ID, or 0.
	 * @param string       $time_start       Optional time window start.
	 * @param string       $time_end         Optional time window end.
	 * @param array        $tax_filters      Resolved { taxonomy: [term IDs] }.
	 * @param string|array $venue_tier       Venue tier slug(s) for the events query.
	 * @return array
	 */
	private function queryEvents( string $date_start, string $date_end, int $location_term_id, string $time_start = '', string $time_end = '', array $tax_filters = array(), $venue_tier = '' ): array {
		$query_input = array(
			'date_start' => $date_start,
			'date_end'   => $date_end,
			'per_page'   => -1,
			'order'      => 'ASC',
		);

		if ( '' !== $time_start ) {
			$query_input['time_start'] = $time_start;
		}
		if ( '' !== $time_end ) {
			$query_input['time_end'] = $time_end;
		}

		if ( $location_term_id > 0 ) {
			$tax_filters['location'] = array( $location_term_id );
		}
		if ( ! empty( $tax_filters ) ) {
			$query_input['tax_filters'] = $tax_filters;
		}
		if ( ! empty( $venue_tier ) ) {
			$query_input['venue_tier'] = $venue_tier;
		}

		// Uses data-machine-events public integration API. See data-machine-events
		// docs/integration-api.md.
		if ( ! function_exists( 'data_machine_events_query_events' )
			|| ! function_exists( 'data_machine_events_parse_event_data' )
			|| ! function_exists( 'data_machine_events_group_by_date' ) ) {
			return array();
		}

		$result = data_machine_events_query_events( $query_input );

		$paged_events = array();
		foreach ( $result['posts'] ?? array() as $post ) {
			$event_data = data_machine_events_parse_event_data( $post );
			if ( ! $event_data ) {
				continue;
			}
			$paged_events[] = array(
				'post'       => $post,
				'event_data' => $event_data,
			);
		}

		// Pass date_start + date_end to the date grouper so it filters out
		// bleed-in from late-night events that span midnight. Without this,
		// a show that starts Friday 9pm and ends Saturday 12am would land
		// in the Friday day_group even when the caller asked for Saturday
		// only. (The underlying query ability deliberately uses overlap
		// semantics — start_datetime >= range_start OR end_datetime >= range_start —
		// for calendar-style "in-progress" callers. Roundups want strict
		// per-day grouping.)
		return data_machine_events_group_by_date(
			$paged_events,
			false,
			$date_start,
			$date_end
		);
	}

	/**
	 * Optional slide-structure inputs passed through to the template.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function slideOptions( array $input ): array {
		return array(
			'background'        => $input['background'] ?? '',
			'location'          => (string) ( $input['location'] ?? '' ),
			'cover'             => ! empty( $input['cover'] ),
			'closing'           => ! empty( $input['closing'] ),
			'closing_text'      => (string) ( $input['closing_text'] ?? '' ),
			'layout'            => 'calendar' === ( $input['layout'] ?? '' ) ? 'calendar' : 'daily',
			'partner_logo_path' => self::uploadsPath( (string) ( $input['partner_logo_path'] ?? '' ) ),
		);
	}

	/**
	 * Accept a co-brand image path only when it lives in this site's uploads.
	 *
	 * The path is caller input on staff-facing abilities, so it is confined
	 * to uploaded media rather than any readable file on the server.
	 *
	 * @param string $path Requested path.
	 * @return string The resolved path, or '' when outside uploads.
	 */
	public static function uploadsPath( string $path ): string {
		if ( '' === $path || ! function_exists( 'wp_get_upload_dir' ) ) {
			return '';
		}
		$real = realpath( $path );
		$base = realpath( (string) ( wp_get_upload_dir()['basedir'] ?? '' ) );
		if ( false === $real || false === $base ) {
			return '';
		}
		return 0 === strpos( $real, rtrim( $base, '/' ) . '/' ) ? $real : '';
	}

	private function countEvents( array $day_groups ): int {
		$count = 0;
		foreach ( $day_groups as $day_group ) {
			$count += count( $day_group['events'] ?? array() );
		}
		return $count;
	}

	private function getLocationName( int $term_id ): string {
		if ( $term_id <= 0 ) {
			return 'All Locations';
		}

		$term = get_term( $term_id, 'location' );
		if ( ! $term || is_wp_error( $term ) ) {
			return 'Unknown Location';
		}

		return $term->name;
	}

	private function buildEventSummary( array $day_groups ): string {
		$lines = array();

		foreach ( $day_groups as $day_group ) {
			$date_obj = $day_group['date_obj'] ?? null;
			$events   = $day_group['events'] ?? array();

			$day_label = $date_obj ? $date_obj->format( 'l, M j' ) : 'Unknown Date';
			$lines[]   = $day_label . ':';

			foreach ( $events as $event_item ) {
				$post       = $event_item['post'] ?? null;
				$event_data = $event_item['event_data'] ?? array();

				$title      = html_entity_decode( $post ? (string) $post->post_title : 'Untitled', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$venue      = html_entity_decode( (string) ( $event_data['venue'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
