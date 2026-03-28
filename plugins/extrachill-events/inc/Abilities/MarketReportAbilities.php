<?php
/**
 * Market Report Abilities
 *
 * Generates a unified market report for event calendar locations, combining:
 * - Event and venue counts per location
 * - Flow breakdown (venue scrapers / Ticketmaster / Dice.fm)
 * - GA4 traffic data (sessions, pageviews)
 * - GSC search data (impressions, clicks, position)
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MarketReportAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/market-report',
			array(
				'label'               => __( 'Market Report', 'extrachill-events' ),
				'description'         => __( 'Generate a market overview for event calendar locations with traffic, events, venues, and flow data.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'location' => array(
							'type'        => 'string',
							'description' => __( 'Optional location slug to filter to a single city. Omit for all cities.', 'extrachill-events' ),
						),
						'days'     => array(
							'type'        => 'integer',
							'description' => __( 'Number of days of analytics data to include. Default 7.', 'extrachill-events' ),
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => __( 'Max number of locations to return. Default 30.', 'extrachill-events' ),
						),
						'sort'     => array(
							'type'        => 'string',
							'description' => __( 'Sort field: events, venues, sessions, impressions, scrapers, opportunity. Default: opportunity.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'locations' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'             => array( 'type' => 'string' ),
									'slug'             => array( 'type' => 'string' ),
									'term_id'          => array( 'type' => 'integer' ),
									'events'           => array( 'type' => 'integer' ),
									'upcoming_events'  => array( 'type' => 'integer' ),
									'venues'           => array( 'type' => 'integer' ),
									'flows'            => array(
										'type'       => 'object',
										'properties' => array(
											'venue_scrapers' => array( 'type' => 'integer' ),
											'ticketmaster'   => array( 'type' => 'integer' ),
											'dice'           => array( 'type' => 'integer' ),
											'total'          => array( 'type' => 'integer' ),
										),
									),
									'ga'               => array(
										'type'       => 'object',
										'properties' => array(
											'sessions'  => array( 'type' => 'integer' ),
											'pageviews' => array( 'type' => 'integer' ),
										),
									),
									'gsc'              => array(
										'type'       => 'object',
										'properties' => array(
											'clicks'      => array( 'type' => 'integer' ),
											'impressions' => array( 'type' => 'integer' ),
											'position'    => array( 'type' => 'number' ),
										),
									),
									'opportunity_score' => array( 'type' => 'number' ),
								),
							),
						),
						'summary'   => array(
							'type'       => 'object',
							'properties' => array(
								'total_locations' => array( 'type' => 'integer' ),
								'total_events'    => array( 'type' => 'integer' ),
								'total_venues'    => array( 'type' => 'integer' ),
								'total_flows'     => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Execute the market report.
	 *
	 * @param array $input Ability input.
	 * @return array Report data.
	 */
	public function execute( array $input ): array {
		$location_filter = $input['location'] ?? null;
		$days            = (int) ( $input['days'] ?? 7 );
		$limit           = (int) ( $input['limit'] ?? 30 );
		$sort            = $input['sort'] ?? 'opportunity';

		// 1. Get all city-level location terms.
		$locations = $this->getLocations( $location_filter );

		if ( empty( $locations ) ) {
			return array(
				'success'   => true,
				'locations' => array(),
				'summary'   => array(
					'total_locations' => 0,
					'total_events'    => 0,
					'total_venues'    => 0,
					'total_flows'     => 0,
				),
			);
		}

		// 2. Get venue counts per location.
		$venue_counts = $this->getVenueCountsByLocation( $locations );

		// 3. Get upcoming event counts per location.
		$upcoming_counts = $this->getUpcomingEventCountsByLocation( $locations );

		// 4. Get flow breakdown per location (pipeline → city mapping).
		$flow_data = $this->getFlowBreakdownByCity();

		// 5. Get GA4 data for location pages.
		$ga_data = $this->getGADataForLocations( $days );

		// 6. Get GSC data for location pages.
		$gsc_data = $this->getGSCDataForLocations( $days );

		// 7. Build results.
		$results       = array();
		$total_events  = 0;
		$total_venues  = 0;
		$total_flows   = 0;

		foreach ( $locations as $term ) {
			$name     = $term->name;
			$slug     = $term->slug;
			$events   = (int) $term->count;
			$upcoming = $upcoming_counts[ $term->term_id ] ?? 0;
			$venues   = $venue_counts[ $term->term_id ] ?? 0;

			$flows = $flow_data[ $name ] ?? array(
				'venue_scrapers' => 0,
				'ticketmaster'   => 0,
				'dice'           => 0,
				'total'          => 0,
			);

			$ga = $ga_data[ $slug ] ?? array(
				'sessions'  => 0,
				'pageviews' => 0,
			);

			$gsc = $gsc_data[ $slug ] ?? array(
				'clicks'      => 0,
				'impressions' => 0,
				'position'    => 0,
			);

			// Opportunity score: markets with high impressions/traffic but few venue scrapers.
			$opportunity = $this->calculateOpportunityScore( $events, $venues, $flows, $ga, $gsc );

			$results[] = array(
				'name'             => $name,
				'slug'             => $slug,
				'term_id'          => (int) $term->term_id,
				'events'           => $events,
				'upcoming_events'  => $upcoming,
				'venues'           => $venues,
				'flows'            => $flows,
				'ga'               => $ga,
				'gsc'              => $gsc,
				'opportunity_score' => round( $opportunity, 1 ),
			);

			$total_events += $events;
			$total_venues += $venues;
			$total_flows  += $flows['total'];
		}

		// Sort.
		$sort_map = array(
			'events'      => fn( $a, $b ) => $b['events'] <=> $a['events'],
			'venues'      => fn( $a, $b ) => $b['venues'] <=> $a['venues'],
			'sessions'    => fn( $a, $b ) => $b['ga']['sessions'] <=> $a['ga']['sessions'],
			'impressions' => fn( $a, $b ) => $b['gsc']['impressions'] <=> $a['gsc']['impressions'],
			'scrapers'    => fn( $a, $b ) => $b['flows']['venue_scrapers'] <=> $a['flows']['venue_scrapers'],
			'opportunity' => fn( $a, $b ) => $b['opportunity_score'] <=> $a['opportunity_score'],
		);

		$sort_fn = $sort_map[ $sort ] ?? $sort_map['opportunity'];
		usort( $results, $sort_fn );

		// Limit.
		$results = array_slice( $results, 0, $limit );

		return array(
			'success'   => true,
			'locations' => $results,
			'summary'   => array(
				'total_locations' => count( $locations ),
				'total_events'   => $total_events,
				'total_venues'   => $total_venues,
				'total_flows'    => $total_flows,
			),
		);
	}

	/**
	 * Get city-level location terms (leaf nodes with parent != 0).
	 *
	 * @param string|null $slug_filter Optional slug to filter to.
	 * @return array Array of WP_Term objects.
	 */
	private function getLocations( ?string $slug_filter ): array {
		$args = array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'fields'     => 'all',
		);

		if ( $slug_filter ) {
			$args['slug'] = $slug_filter;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Only city-level terms (has a parent).
		return array_filter( $terms, fn( $t ) => (int) $t->parent > 0 );
	}

	/**
	 * Get venue counts per location term.
	 *
	 * @param array $locations Location terms.
	 * @return array Map of term_id => venue count.
	 */
	private function getVenueCountsByLocation( array $locations ): array {
		global $wpdb;

		$counts = array();
		$term_ids = wp_list_pluck( $locations, 'term_id' );

		if ( empty( $term_ids ) ) {
			return $counts;
		}

		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT loc_tt.term_id AS location_id, COUNT(DISTINCT venue_t.term_id) AS venue_count
				FROM {$wpdb->term_relationships} loc_tr
				JOIN {$wpdb->term_taxonomy} loc_tt ON loc_tr.term_taxonomy_id = loc_tt.term_taxonomy_id
				JOIN {$wpdb->term_relationships} venue_tr ON loc_tr.object_id = venue_tr.object_id
				JOIN {$wpdb->term_taxonomy} venue_tt ON venue_tr.term_taxonomy_id = venue_tt.term_taxonomy_id
				JOIN {$wpdb->terms} venue_t ON venue_tt.term_id = venue_t.term_id
				WHERE loc_tt.taxonomy = 'location'
				AND loc_tt.term_id IN ($placeholders)
				AND venue_tt.taxonomy = 'venue'
				GROUP BY loc_tt.term_id",
				...$term_ids
			)
		);

		foreach ( $rows as $row ) {
			$counts[ (int) $row->location_id ] = (int) $row->venue_count;
		}

		return $counts;
	}

	/**
	 * Get upcoming event counts per location.
	 *
	 * @param array $locations Location terms.
	 * @return array Map of term_id => upcoming event count.
	 */
	private function getUpcomingEventCountsByLocation( array $locations ): array {
		global $wpdb;

		$counts   = array();
		$term_ids = wp_list_pluck( $locations, 'term_id' );

		if ( empty( $term_ids ) ) {
			return $counts;
		}

		$placeholders      = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
		$now               = current_time( 'mysql' );
		$event_dates_table = $wpdb->prefix . 'datamachine_event_dates';

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS location_id, COUNT(DISTINCT p.ID) AS upcoming_count
				FROM {$wpdb->posts} p
				JOIN {$event_dates_table} ed ON p.ID = ed.post_id
				JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE p.post_type = 'data_machine_events'
				AND p.post_status = 'publish'
				AND ed.end_datetime IS NOT NULL
				AND ed.end_datetime >= %s
				AND tt.taxonomy = 'location'
				AND tt.term_id IN ($placeholders)
				GROUP BY tt.term_id",
				$now,
				...$term_ids
			)
		);

		foreach ( $rows as $row ) {
			$counts[ (int) $row->location_id ] = (int) $row->upcoming_count;
		}

		return $counts;
	}

	/**
	 * Get flow breakdown per location term ID from flow configs.
	 *
	 * Reads the `taxonomy_location_selection` from each flow's upsert step
	 * to determine the location. Falls back to pipeline name matching if
	 * no location term is found in the config.
	 *
	 * @return array Map of term_id => { venue_scrapers, ticketmaster, dice, total }.
	 */
	private function getFlowBreakdownByCity(): array {
		global $wpdb;

		$table_flows     = $wpdb->prefix . 'datamachine_flows';
		$table_pipelines = $wpdb->prefix . 'datamachine_pipelines';

		$flows = $wpdb->get_results(
			"SELECT f.flow_config, p.pipeline_name
			FROM {$table_flows} f
			JOIN {$table_pipelines} p ON f.pipeline_id = p.pipeline_id"
		);

		// Build a name-to-term-id lookup for fallback matching.
		$all_locations = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );
		$name_to_id = array();
		foreach ( $all_locations as $term ) {
			$name_to_id[ $term->name ] = $term->term_id;
		}

		$city_data = array();

		foreach ( $flows as $f ) {
			$pipeline_city = str_replace( ' Events', '', $f->pipeline_name );

			if ( 'Frontend' === $pipeline_city || 'Weekly Roundup' === $pipeline_city ) {
				continue;
			}

			$config      = json_decode( $f->flow_config, true );
			$handler     = 'other';
			$location_id = 0;

			if ( is_array( $config ) ) {
				foreach ( $config as $step ) {
					$slugs = $step['handler_slugs'] ?? array();

					// Determine handler type.
					if ( in_array( 'universal_web_scraper', $slugs, true ) ) {
						$handler = 'venue_scrapers';
					} elseif ( in_array( 'ticketmaster', $slugs, true ) ) {
						$handler = 'ticketmaster';
					} elseif ( in_array( 'dice_fm', $slugs, true ) ) {
						$handler = 'dice';
					}

					// Extract location term ID from upsert step config.
					if ( in_array( 'upsert_event', $slugs, true ) && ! $location_id ) {
						$upsert_config = $step['handler_configs']['upsert_event'] ?? array();
						$loc_val       = $upsert_config['taxonomy_location_selection'] ?? '';
						if ( is_numeric( $loc_val ) && (int) $loc_val > 0 ) {
							$location_id = (int) $loc_val;
						} elseif ( is_string( $loc_val ) && ! empty( $loc_val ) ) {
							// Location selection might be a term name/slug.
							$location_id = $name_to_id[ $loc_val ] ?? 0;
						}
					}
				}
			}

			// Fallback: match pipeline name to location term name.
			if ( ! $location_id ) {
				$location_id = $name_to_id[ $pipeline_city ] ?? 0;
			}

			if ( ! $location_id ) {
				continue;
			}

			// Resolve term name for the key (used in execute() matching).
			$term = get_term( $location_id, 'location' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$key = $term->name;

			if ( ! isset( $city_data[ $key ] ) ) {
				$city_data[ $key ] = array(
					'venue_scrapers' => 0,
					'ticketmaster'   => 0,
					'dice'           => 0,
					'total'          => 0,
				);
			}

			if ( isset( $city_data[ $key ][ $handler ] ) ) {
				$city_data[ $key ][ $handler ]++;
			}
			$city_data[ $key ]['total']++;
		}

		return $city_data;
	}

	/**
	 * Get GA4 session/pageview data for location pages.
	 *
	 * @param int $days Number of days.
	 * @return array Map of location slug => { sessions, pageviews }.
	 */
	private function getGADataForLocations( int $days ): array {
		$ga = wp_get_ability( 'datamachine/google-analytics' );

		if ( ! $ga ) {
			return array();
		}

		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$result = $ga->execute( array(
			'action'      => 'page_stats',
			'start_date'  => $start,
			'end_date'    => $end,
			'hostname'    => 'events.extrachill.com',
			'page_filter' => '/location/',
			'limit'       => 200,
		) );

		$data = array();

		if ( ! empty( $result['success'] ) && ! empty( $result['results'] ) ) {
			foreach ( $result['results'] as $row ) {
				$path = $row['pagePath'] ?? '';
				$slug = $this->extractCitySlugFromPath( $path );
				if ( $slug ) {
					if ( ! isset( $data[ $slug ] ) ) {
						$data[ $slug ] = array(
							'sessions'  => 0,
							'pageviews' => 0,
						);
					}
					$data[ $slug ]['sessions']  += (int) ( $row['sessions'] ?? 0 );
					$data[ $slug ]['pageviews'] += (int) ( $row['screenPageViews'] ?? 0 );
				}
			}
		}

		return $data;
	}

	/**
	 * Get GSC impressions/clicks for location pages.
	 *
	 * @param int $days Number of days.
	 * @return array Map of location slug => { clicks, impressions, position }.
	 */
	private function getGSCDataForLocations( int $days ): array {
		$gsc = wp_get_ability( 'datamachine/google-search-console' );

		if ( ! $gsc ) {
			return array();
		}

		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$result = $gsc->execute( array(
			'action'     => 'page_stats',
			'start_date' => $start,
			'end_date'   => $end,
			'url_filter' => 'events.extrachill.com/location/',
			'limit'      => 200,
		) );

		$data = array();

		if ( ! empty( $result['success'] ) && ! empty( $result['results'] ) ) {
			foreach ( $result['results'] as $row ) {
				// GSC keys is an array, e.g. ["https://events.extrachill.com/location/..."].
				$url  = is_array( $row['keys'] ) ? ( $row['keys'][0] ?? '' ) : ( $row['keys'] ?? '' );
				$slug = $this->extractCitySlugFromUrl( $url );
				if ( $slug ) {
					if ( ! isset( $data[ $slug ] ) ) {
						$data[ $slug ] = array(
							'clicks'      => 0,
							'impressions' => 0,
							'position'    => 0,
						);
					}
					$data[ $slug ]['clicks']      += (int) ( $row['clicks'] ?? 0 );
					$data[ $slug ]['impressions'] += (int) ( $row['impressions'] ?? 0 );
					// Weighted average position.
					$new_imp = (int) ( $row['impressions'] ?? 0 );
					if ( $new_imp > 0 ) {
						$old_imp = $data[ $slug ]['impressions'] - $new_imp;
						if ( $old_imp > 0 ) {
							$data[ $slug ]['position'] = (
								$data[ $slug ]['position'] * $old_imp + ( $row['position'] ?? 0 ) * $new_imp
							) / $data[ $slug ]['impressions'];
						} else {
							$data[ $slug ]['position'] = (float) ( $row['position'] ?? 0 );
						}
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Extract city slug from a GA page path like /location/usa/texas/austin or /location/austin.
	 *
	 * @param string $path Page path.
	 * @return string|null City slug or null.
	 */
	private function extractCitySlugFromPath( string $path ): ?string {
		$path = rtrim( $path, '/' );

		// Skip discovery pages (tonight, this-weekend, this-week).
		if ( preg_match( '/(tonight|this-weekend|this-week)$/', $path ) ) {
			return null;
		}

		// /location/usa/state/city or /location/city.
		if ( preg_match( '#/location/(?:usa/[^/]+/)?([^/]+)$#', $path, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Extract city slug from a GSC URL like https://events.extrachill.com/location/usa/texas/austin.
	 *
	 * @param string $url Full URL.
	 * @return string|null City slug or null.
	 */
	private function extractCitySlugFromUrl( string $url ): ?string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return null;
		}
		return $this->extractCitySlugFromPath( $path );
	}

	/**
	 * Calculate opportunity score for a location.
	 *
	 * High score = market with strong signals (traffic, impressions, events)
	 * but few venue scrapers. This identifies where adding scrapers would
	 * have the biggest impact.
	 *
	 * @param int   $events Total events.
	 * @param int   $venues Total venues.
	 * @param array $flows  Flow breakdown.
	 * @param array $ga     GA data.
	 * @param array $gsc    GSC data.
	 * @return float Opportunity score.
	 */
	private function calculateOpportunityScore( int $events, int $venues, array $flows, array $ga, array $gsc ): float {
		$scrapers    = $flows['venue_scrapers'];
		$sessions    = $ga['sessions'];
		$impressions = $gsc['impressions'];

		// Demand signal: weighted combination of traffic + search interest + existing events.
		$demand = ( $sessions * 5 ) + ( $impressions * 0.5 ) + ( $events * 0.1 );

		// Supply gap: inverse of venue scraper count (diminishing returns).
		// 0 scrapers = multiplier 10, 1 = 5, 2 = 3.3, 5 = 1.7, 10 = 1, 20+ = baseline.
		$supply_gap = 10 / max( 1, $scrapers + 1 );

		return $demand * $supply_gap;
	}
}
