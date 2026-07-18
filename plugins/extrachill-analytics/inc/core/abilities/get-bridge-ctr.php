<?php
/**
 * Get Bridge Stored Event Ratio Ability
 *
 * Reports independently persisted bridge click and impression event counts.
 * Current impressions are attempted at 50% viewport exposure, but historical
 * rows include the former render-opportunity contract and carry no version.
 *
 * @package ExtraChill\Analytics
 * @since 0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-bridge-ctr ability.
 */
function extrachill_analytics_register_bridge_ctr_ability() {
	wp_register_ability(
		'extrachill/get-bridge-ctr',
		array(
			'label'               => __( 'Get Bridge Stored Event Ratio', 'extrachill-analytics' ),
			'description'         => __( 'Returns independently stored, bot-filtered bridge click and impression event counts and their potentially unbounded ratio.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all available history within max_events.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id'    => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'max_events' => array(
						'type'        => 'integer',
						'description' => __( 'Maximum stored bridge rows to inspect.', 'extrachill-analytics' ),
						'default'     => 50000,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Stored bridge click and impression counts, their ratio, destination aggregates, and coverage diagnostics.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_bridge_ctr',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
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
 * Initialize one stored bridge-event count bucket.
 *
 * @return array<string,int>
 */
function extrachill_analytics_bridge_bucket() {
	return array(
		'clicks'      => 0,
		'impressions' => 0,
	);
}

/**
 * Calculate the independently stored click/impression ratio.
 *
 * Independent best-effort writes can make clicks exceed impressions. A zero
 * denominator retains the ability's legacy 0.0 result type.
 *
 * @param array $counts Stored event counts.
 * @return float Stored click/impression ratio.
 */
function extrachill_analytics_bridge_stored_ratio( $counts ) {
	$impressions = (int) $counts['impressions'];

	return $impressions > 0 ? round( (int) $counts['clicks'] / $impressions, 4 ) : 0.0;
}

/**
 * Execute callback for get-bridge-ctr ability.
 *
 * @param array $input Input parameters.
 * @return array Stored bridge event summary.
 */
function extrachill_analytics_ability_get_bridge_ctr( $input ) {
	$days       = isset( $input['days'] ) ? max( 0, (int) $input['days'] ) : 28;
	$blog_id    = isset( $input['blog_id'] ) ? max( 0, (int) $input['blog_id'] ) : 0;
	$max_events = isset( $input['max_events'] ) ? (int) $input['max_events'] : 50000;
	$max_events = max( 1, min( 100000, $max_events ) );
	$page_size  = 1000;

	$query_args = array(
		'event_type' => array( 'bridge_click', 'bridge_impression' ),
		'limit'      => $page_size,
		'orderby'    => 'id',
		'order'      => 'DESC',
	);

	if ( $days > 0 ) {
		$query_args['date_from'] = gmdate( 'Y-m-d', (int) strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$query_args['blog_id'] = $blog_id;
	}

	$counts       = extrachill_analytics_bridge_bucket();
	$by_dest      = array();
	$loaded       = 0;
	$bot_rows     = 0;
	$identified   = 0;
	$anonymous    = 0;
	$missing_dest = 0;
	$truncated    = false;
	$before_id    = 0;

	while ( $loaded <= $max_events ) {
		$query_args['limit'] = min( $page_size, $max_events + 1 - $loaded );
		if ( $before_id > 0 ) {
			$query_args['before_id'] = $before_id;
		}

		$page       = (array) extrachill_get_analytics_events( $query_args );
		$page_count = count( $page );
		if ( 0 === $page_count ) {
			break;
		}

		foreach ( $page as $row ) {
			++$loaded;
			if ( $loaded > $max_events ) {
				$truncated = true;
				break 2;
			}

			$data = isset( $row->event_data ) && is_array( $row->event_data ) ? $row->event_data : array();
			if ( ! empty( $data['is_bot'] ) ) {
				++$bot_rows;
				continue;
			}

			$is_click = isset( $row->event_type ) && 'bridge_click' === $row->event_type;
			$key      = $is_click ? 'clicks' : 'impressions';
			++$counts[ $key ];

			if ( isset( $row->visitor_id ) && '' !== (string) $row->visitor_id ) {
				++$identified;
			} else {
				++$anonymous;
			}

			$dest_site = isset( $data['dest_site'] ) ? (string) $data['dest_site'] : '';
			if ( '' === $dest_site ) {
				++$missing_dest;
			}
			if ( ! isset( $by_dest[ $dest_site ] ) ) {
				$by_dest[ $dest_site ] = extrachill_analytics_bridge_bucket();
			}
			++$by_dest[ $dest_site ][ $key ];
		}

		$last_row  = $page[ $page_count - 1 ];
		$before_id = isset( $last_row->id ) ? (int) $last_row->id : 0;
		if ( $page_count < $query_args['limit'] || $before_id < 1 ) {
			break;
		}
	}

	$by_dest_site = array();
	foreach ( $by_dest as $dest => $dest_counts ) {
		$by_dest_site[] = array(
			'dest_site'                     => '' === $dest ? '(unknown)' : $dest,
			'clicks'                        => (int) $dest_counts['clicks'],
			'impressions'                   => (int) $dest_counts['impressions'],
			'ctr'                           => extrachill_analytics_bridge_stored_ratio( $dest_counts ),
			'stored_click_events'           => (int) $dest_counts['clicks'],
			'stored_impression_events'      => (int) $dest_counts['impressions'],
			'stored_click_impression_ratio' => extrachill_analytics_bridge_stored_ratio( $dest_counts ),
			'measurement_grain_notice'      => 'Aggregate stored event ratio only; no page-load or DOM-element identifier exists for exact opportunity or per-card CTR.',
		);
	}

	usort(
		$by_dest_site,
		static function ( $a, $b ) {
			if ( $a['impressions'] !== $b['impressions'] ) {
				return $b['impressions'] <=> $a['impressions'];
			}
			if ( $a['clicks'] !== $b['clicks'] ) {
				return $b['clicks'] <=> $a['clicks'];
			}
			return strcmp( $a['dest_site'], $b['dest_site'] );
		}
	);

	$eligible_rows      = $counts['clicks'] + $counts['impressions'];
	$destination_status = 'not_observed';
	if ( $eligible_rows > 0 ) {
		$destination_status = 0 === $missing_dest ? 'measured' : ( $missing_dest === $eligible_rows ? 'not_instrumented' : 'partial' );
	}
	$identity_status = 'not_observed';
	if ( $eligible_rows > 0 ) {
		$identity_status = 0 === $anonymous ? 'complete' : ( 0 === $identified ? 'unavailable' : 'partial' );
	}
	$ratio = extrachill_analytics_bridge_stored_ratio( $counts );

	return array(
		'clicks'                        => (int) $counts['clicks'],
		'impressions'                   => (int) $counts['impressions'],
		'ctr'                           => $ratio,
		'stored_click_events'           => (int) $counts['clicks'],
		'stored_impression_events'      => (int) $counts['impressions'],
		'stored_click_impression_ratio' => $ratio,
		'by_dest_site'                  => $by_dest_site,
		'coverage'                      => array(
			'status'                     => 0 === $loaded ? 'not_observed' : ( $truncated ? 'truncated' : 'observed' ),
			'measurement_contract'       => 0 === $loaded ? 'not_observed' : 'historically_mixed_unmarked',
			'destination_status'         => $destination_status,
			'rows_missing_dest_site'     => $missing_dest,
			'identity_status'            => $identity_status,
			'identified_rows'            => $identified,
			'anonymous_rows'             => $anonymous,
			'bot_rows_excluded'          => $bot_rows,
			'eligible_rows'              => $eligible_rows,
			'truncated'                  => $truncated,
			'loaded_rows'                => min( $loaded, $max_events ),
			'historical_contract_notice' => 'Stored bridge_impression rows carry no instrumentation version. Windows may mix legacy render-opportunity impressions with current 50%-viewport exposure attempts, so the eras cannot be separated exactly.',
			'privacy_notice'             => 'GPC/DNT and visitors without a usable first-party cookie remain anonymous. Aggregate stored event counts remain eligible, but visitor-level attribution is unavailable for those rows.',
			'bot_filter_notice'          => 'Rows with a truthy canonical event_data.is_bot stamp are excluded before totals and destination aggregation. Missing legacy stamps remain eligible.',
		),
		'compatibility'                 => array(
			'ability' => 'extrachill/get-bridge-ctr',
			'aliases' => array(
				'clicks'      => 'stored_click_events',
				'impressions' => 'stored_impression_events',
				'ctr'         => 'stored_click_impression_ratio',
			),
			'notice'  => 'Legacy keys retain their original independently stored count and ratio meanings and scalar types. New consumers should use the explicit stored_* fields.',
		),
		'pagination'                    => array(
			'order'      => 'id DESC',
			'cursor'     => 'id < before_id',
			'max_events' => $max_events,
		),
		'days'                          => $days,
		'blog_id'                       => $blog_id,
		'max_events'                    => $max_events,
		'period'                        => $days > 0
			? gmdate( 'Y-m-d', (int) strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'note'                          => 'This is a ratio of independently stored, bot-filtered event rows, not an atomic opportunity conversion rate. Current bridge_impression events are attempted at 50% viewport exposure, and a first click independently attempts an impression before its click event. Each request may be lost or duplicated, so ratios can exceed 100% under asymmetric loss or ambiguous retries. JavaScript execution filters non-JS crawlers and inert prefetches but does not prove human identity; truthy canonical is_bot stamps are excluded.',
	);
}
