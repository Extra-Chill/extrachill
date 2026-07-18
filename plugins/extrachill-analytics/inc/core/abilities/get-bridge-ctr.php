<?php
/**
 * Get Bridge Exposure Report Ability
 *
 * Reports stored bridge clicks against observed viewport exposure evidence.
 * The ability name and legacy output aliases remain for existing consumers.
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
			'label'               => __( 'Get Bridge Exposure Report', 'extrachill-analytics' ),
			'description'         => __( 'Returns bridge clicks and observed viewport exposures under the shipped best-effort delivery contract.', 'extrachill-analytics' ),
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
						'description' => __( 'Maximum stored bridge rows to aggregate.', 'extrachill-analytics' ),
						'default'     => 50000,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Bridge clicks, viewport exposure evidence, bounded click-to-observed-exposure ratio, destination aggregates, and coverage diagnostics.', 'extrachill-analytics' ),
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
 * Normalize nested event data before building an exact-row signature.
 *
 * @param mixed $value Value to normalize.
 * @return mixed Normalized value.
 */
function extrachill_analytics_bridge_signature_value( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = extrachill_analytics_bridge_signature_value( $item );
	}

	if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		ksort( $value );
	}

	return $value;
}

/**
 * Build the strongest retry-dedupe signature supported by canonical rows.
 *
 * Row IDs are deliberately excluded because an ambiguous retry creates a new
 * ID. No element or page-load identifier is stored, so this only collapses
 * otherwise exact rows; it cannot reconstruct exact client opportunities.
 *
 * @param object $row Canonical analytics event row.
 * @param array  $data Decoded event data.
 * @return string Row signature.
 */
function extrachill_analytics_bridge_row_signature( $row, $data ) {
	$signature = array(
		'event_type' => isset( $row->event_type ) ? (string) $row->event_type : '',
		'event_data' => extrachill_analytics_bridge_signature_value( $data ),
		'source_url' => isset( $row->source_url ) ? (string) $row->source_url : '',
		'blog_id'    => isset( $row->blog_id ) ? (int) $row->blog_id : 0,
		'user_id'    => isset( $row->user_id ) ? (int) $row->user_id : 0,
		'visitor_id' => isset( $row->visitor_id ) ? (string) $row->visitor_id : '',
		'created_at' => isset( $row->created_at ) ? (string) $row->created_at : '',
	);

	return hash( 'sha256', wp_json_encode( $signature ) );
}

/**
 * Initialize one report aggregate bucket.
 *
 * @return array<string,int>
 */
function extrachill_analytics_bridge_bucket() {
	return array(
		'click_events'             => 0,
		'viewport_exposure_events' => 0,
	);
}

/**
 * Finalize counts under the lossy exposure contract.
 *
 * A stored click proves browser exposure even when its independently delivered
 * exposure event is missing. The maximum is therefore the smallest honest
 * observed-exposure denominator supported by the stored aggregate evidence.
 *
 * @param array $counts Aggregate event counts.
 * @return array<string,int|float|null>
 */
function extrachill_analytics_bridge_finalize_counts( $counts ) {
	$clicks          = (int) $counts['click_events'];
	$exposure_events = (int) $counts['viewport_exposure_events'];
	$exposures       = max( $exposure_events, $clicks );

	return array(
		'clicks'                           => $clicks,
		'viewport_exposure_events'         => $exposure_events,
		'click_proven_missing_exposures'   => max( 0, $clicks - $exposure_events ),
		'observed_exposures'               => $exposures,
		'click_to_observed_exposure_ratio' => $exposures > 0 ? round( $clicks / $exposures, 4 ) : null,
	);
}

/**
 * Execute callback for get-bridge-ctr ability.
 *
 * @param array $input Input parameters.
 * @return array Bridge exposure summary.
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
		'offset'     => 0,
		'orderby'    => 'id',
		'order'      => 'DESC',
	);

	if ( $days > 0 ) {
		$query_args['date_from'] = gmdate( 'Y-m-d', (int) strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$query_args['blog_id'] = $blog_id;
	}

	$raw_counts   = extrachill_analytics_bridge_bucket();
	$counts       = extrachill_analytics_bridge_bucket();
	$by_dest      = array();
	$seen         = array();
	$loaded       = 0;
	$duplicates   = 0;
	$identified   = 0;
	$anonymous    = 0;
	$missing_dest = 0;
	$truncated    = false;

	while ( $loaded <= $max_events ) {
		$query_args['limit'] = min( $page_size, $max_events + 1 - $loaded );
		$page                = (array) extrachill_get_analytics_events( $query_args );
		$page_count          = count( $page );
		if ( 0 === $page_count ) {
			break;
		}

		foreach ( $page as $row ) {
			++$loaded;
			if ( $loaded > $max_events ) {
				$truncated = true;
				break 2;
			}

			$data     = isset( $row->event_data ) && is_array( $row->event_data ) ? $row->event_data : array();
			$is_click = isset( $row->event_type ) && 'bridge_click' === $row->event_type;
			$key      = $is_click ? 'click_events' : 'viewport_exposure_events';
			++$raw_counts[ $key ];

			$signature = extrachill_analytics_bridge_row_signature( $row, $data );
			if ( isset( $seen[ $signature ] ) ) {
				++$duplicates;
				continue;
			}
			$seen[ $signature ] = true;
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

		$query_args['offset'] += $page_count;
		if ( $page_count < $query_args['limit'] ) {
			break;
		}
	}

	$total        = extrachill_analytics_bridge_finalize_counts( $counts );
	$deduplicated = $counts['click_events'] + $counts['viewport_exposure_events'];
	$dest_rows    = array();
	foreach ( $by_dest as $dest => $dest_counts ) {
		$row              = extrachill_analytics_bridge_finalize_counts( $dest_counts );
		$row['dest_site'] = '' === $dest ? '(unknown)' : $dest;
		if ( '' === $dest ) {
			$row['click_to_observed_exposure_ratio'] = null;
		}
		$row['ctr']         = $row['click_to_observed_exposure_ratio'];
		$row['impressions'] = $row['observed_exposures'];
		$dest_rows[]        = $row;
	}

	usort(
		$dest_rows,
		static function ( $a, $b ) {
			if ( $a['observed_exposures'] !== $b['observed_exposures'] ) {
				return $b['observed_exposures'] <=> $a['observed_exposures'];
			}
			if ( $a['clicks'] !== $b['clicks'] ) {
				return $b['clicks'] <=> $a['clicks'];
			}
			return strcmp( $a['dest_site'], $b['dest_site'] );
		}
	);

	$destination_status = 'not_observed';
	if ( $deduplicated > 0 ) {
		$destination_status = 0 === $missing_dest ? 'measured' : ( $missing_dest === $deduplicated ? 'not_instrumented' : 'partial' );
	}
	$identity_status = 'not_observed';
	if ( $deduplicated > 0 ) {
		$identity_status = 0 === $anonymous ? 'complete' : ( 0 === $identified ? 'unavailable' : 'partial' );
	}

	$ratio = $total['click_to_observed_exposure_ratio'];

	return array_merge(
		$total,
		array(
			// Compatibility aliases retain the ability's existing shape while the
			// explicit fields above define the corrected semantics.
			'impressions'   => $total['observed_exposures'],
			'ctr'           => $ratio,
			'by_dest_site'  => $dest_rows,
			'stored'        => array(
				'click_rows'             => $raw_counts['click_events'],
				'viewport_exposure_rows' => $raw_counts['viewport_exposure_events'],
			),
			'dedupe'        => array(
				'unit'                   => 'exact_canonical_row_fields_except_id',
				'duplicate_rows_removed' => $duplicates,
				'limitation'             => 'No page-load or DOM-element identifier is stored. Exact retries can be collapsed, but indistinguishable legitimate opportunities and non-identical ambiguous retries cannot be separated.',
			),
			'coverage'      => array(
				'status'                     => 0 === $deduplicated ? 'not_observed' : ( $truncated ? 'truncated' : 'observed' ),
				'measurement_contract'       => 0 === $deduplicated ? 'not_observed' : 'historically_mixed_unmarked',
				'destination_status'         => $destination_status,
				'rows_missing_dest_site'     => $missing_dest,
				'identity_status'            => $identity_status,
				'identified_rows'            => $identified,
				'anonymous_rows'             => $anonymous,
				'truncated'                  => $truncated,
				'loaded_rows'                => min( $loaded, $max_events ),
				'historical_contract_notice' => 'Stored rows do not carry an instrumentation version. Windows may mix legacy render-opportunity impressions with current 50%-viewport exposure attempts, so the two eras cannot be separated exactly.',
				'privacy_notice'             => 'GPC/DNT and visitors without a usable first-party cookie remain anonymous. Aggregate exposure evidence is retained, but visitor-level dedupe or attribution is unavailable for those rows.',
			),
			'compatibility' => array(
				'ability' => 'extrachill/get-bridge-ctr',
				'aliases' => array(
					'clicks'      => 'deduplicated stored click rows',
					'impressions' => 'observed_exposures',
					'ctr'         => 'click_to_observed_exposure_ratio',
				),
				'notice'  => 'The legacy ability and keys remain, but impressions and ctr now use the bounded observed-exposure denominator. Use the explicit viewport_exposure_events and click_to_observed_exposure_ratio fields in new consumers.',
			),
			'days'          => $days,
			'blog_id'       => $blog_id,
			'max_events'    => $max_events,
			'period'        => $days > 0
				? gmdate( 'Y-m-d', (int) strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
				: 'all time',
			'note'          => 'A bridge impression is currently attempted once when an eligible initial link reaches at least 50% viewport visibility; a first click attempts any missing exposure first. Exposure and click persist independently through best-effort beacon/fetch delivery, so stored rows may be missing or duplicated. observed_exposures is the lower-bound aggregate evidence max(viewport_exposure_events, clicks), which prevents impossible ratios without claiming exact per-card reconstruction. JavaScript execution filters non-JS crawlers and inert prefetches, but does not prove human identity or exclude JS-capable automation.',
		)
	);
}
