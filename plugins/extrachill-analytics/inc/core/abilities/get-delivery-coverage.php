<?php
/**
 * Cross-source ad-delivery coverage diagnostic.
 *
 * Analytics owns reconciliation only. GA retrieval remains in Data Machine
 * Business, Mediavine rows remain in the persisted revenue store, and ad
 * eligibility remains in Extra Chill Network policy.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the delivery-coverage diagnostic ability.
 */
function extrachill_analytics_register_delivery_coverage_ability() {
	wp_register_ability(
		'extrachill/get-delivery-coverage',
		array(
			'label'               => __( 'Get Ad Delivery Coverage', 'extrachill-analytics' ),
			'description'         => __( 'Compare GA4 screenPageViews with persisted Mediavine pageviews for policy-confirmed ad-eligible sites over the same exact inclusive date window. Coverage is Mediavine applicable pageviews divided by GA applicable screenPageViews. GA and Mediavine are different counters; 80%-120% is aligned, values outside that band warn, and values below 50% or above 150% are flagged as extreme divergence. Missing policy/source data and non-exact Mediavine snapshot windows are unknown, never zero. Reports applicable, blocked, unknown, unresolved, and route-family evidence where each source supports it. Read-only: neither source nor policy is mutated.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'start_date' => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start in Y-m-d format.', 'extrachill-analytics' ),
					),
					'end_date'   => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end in Y-m-d format.', 'extrachill-analytics' ),
					),
					'blog_ids'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => __( 'Optional blog IDs to inspect. Empty means every active network site; policy decides applicability.', 'extrachill-analytics' ),
						'default'     => array(),
					),
				),
				'required'   => array( 'start_date', 'end_date' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Exact window, source contracts, thresholds, per-site status and evidence, and status summary.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_delivery_coverage',
			'permission_callback' => 'extrachill_analytics_can_read_reports',
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
 * Execute the delivery-coverage diagnostic.
 *
 * @param array $input Ability input.
 * @return array Diagnostic report.
 */
function extrachill_analytics_ability_get_delivery_coverage( $input ) {
	$start_date = isset( $input['start_date'] ) ? (string) $input['start_date'] : '';
	$end_date   = isset( $input['end_date'] ) ? (string) $input['end_date'] : '';

	if ( ! extrachill_analytics_delivery_valid_date( $start_date ) || ! extrachill_analytics_delivery_valid_date( $end_date ) || $start_date > $end_date ) {
		return array(
			'success' => false,
			'error'   => __( 'start_date and end_date must be valid Y-m-d dates, with start_date on or before end_date.', 'extrachill-analytics' ),
		);
	}

	$blog_ids = isset( $input['blog_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $input['blog_ids'] ) ) ) ) : array();
	if ( empty( $blog_ids ) && function_exists( 'get_sites' ) ) {
		$blog_ids = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'archived' => 0,
					'spam'     => 0,
					'deleted'  => 0,
				)
			)
		);
	}
	if ( empty( $blog_ids ) ) {
		$blog_ids = array( get_current_blog_id() );
	}

	$ga_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/google-analytics' ) : null;
	$sites      = array();
	foreach ( $blog_ids as $blog_id ) {
		$host = extrachill_analytics_delivery_blog_host( $blog_id );
		$base = extrachill_analytics_delivery_policy_for_context( array( 'blog_id' => $blog_id ) );
		$site = array(
			'blog_id'        => $blog_id,
			'canonical_host' => $host,
			'policy'         => $base,
			'ga'             => extrachill_analytics_delivery_unknown_source( 'Google Analytics was not requested because site policy is not applicable.' ),
			'mediavine'      => extrachill_analytics_delivery_unknown_source( 'Mediavine was not requested because site policy is not applicable.' ),
		);

		if ( 'applicable' === $base['status'] && '' !== $host ) {
			$site['ga']        = extrachill_analytics_delivery_read_ga( $ga_ability, $blog_id, $host, $start_date, $end_date );
			$site['mediavine'] = extrachill_analytics_delivery_read_mediavine( $blog_id, $start_date, $end_date );
		} elseif ( 'applicable' === $base['status'] ) {
			$site['ga']        = extrachill_analytics_delivery_unknown_source( 'The blog has no canonical hostname; Analytics will not guess GA ownership.' );
			$site['mediavine'] = extrachill_analytics_delivery_unknown_source( 'The blog has no canonical hostname; persisted attribution cannot be presented as host evidence.' );
		}

		$sites[] = $site;
	}

	return extrachill_analytics_delivery_build_report( $start_date, $end_date, $sites );
}

/**
 * Return the canonical host WordPress owns for a blog.
 *
 * @param int $blog_id Blog ID.
 * @return string Host, or empty when unavailable.
 */
function extrachill_analytics_delivery_blog_host( $blog_id ) {
	$url  = function_exists( 'get_home_url' ) ? get_home_url( (int) $blog_id, '/' ) : '';
	$host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
	return is_string( $host ) ? strtolower( $host ) : '';
}

/**
 * Read and normalize the network-owned policy contract.
 *
 * @param array $context Explicit request context.
 * @return array Normalized policy evidence.
 */
function extrachill_analytics_delivery_policy_for_context( array $context ) {
	if ( ! function_exists( 'extrachill_get_ad_policy' ) ) {
		return array(
			'status'                => 'unknown',
			'site_enabled'          => null,
			'serve_ads'             => null,
			'integration_available' => null,
			'reason'                => 'policy_unavailable',
			'drift'                 => 'unknown',
		);
	}

	$policy = extrachill_get_ad_policy( $context );
	if ( ! is_array( $policy ) || ! array_key_exists( 'site_enabled', $policy ) || ! array_key_exists( 'serve_ads', $policy ) ) {
		return array(
			'status'                => 'unknown',
			'site_enabled'          => null,
			'serve_ads'             => null,
			'integration_available' => null,
			'reason'                => 'policy_unavailable',
			'drift'                 => 'unknown',
		);
	}

	$site_enabled = is_bool( $policy['site_enabled'] ) ? $policy['site_enabled'] : null;
	$serve_ads    = is_bool( $policy['serve_ads'] ) ? $policy['serve_ads'] : null;
	$integration  = isset( $policy['integration_available'] ) && is_bool( $policy['integration_available'] ) ? $policy['integration_available'] : null;
	$reason       = isset( $policy['reason'] ) ? (string) $policy['reason'] : 'unknown';
	$status       = 'unknown';
	if ( false === $site_enabled || in_array( $reason, array( 'site_disabled', 'route_blocked', 'member_benefit' ), true ) ) {
		$status = 'blocked';
	} elseif ( true === $site_enabled && true === $serve_ads && true === $integration ) {
		$status = 'applicable';
	}

	return array(
		'status'                => $status,
		'site_enabled'          => $site_enabled,
		'serve_ads'             => $serve_ads,
		'integration_available' => $integration,
		'reason'                => $reason,
		'drift'                 => isset( $policy['drift'] ) ? (string) $policy['drift'] : 'unknown',
	);
}

/**
 * Read GA page rows through the existing Data Machine Business ability.
 *
 * @param mixed  $ability    GA ability.
 * @param int    $blog_id    Blog ID.
 * @param string $host       Canonical host.
 * @param string $start_date Inclusive start.
 * @param string $end_date   Inclusive end.
 * @return array Normalized source evidence.
 */
function extrachill_analytics_delivery_read_ga( $ability, $blog_id, $host, $start_date, $end_date ) {
	if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
		return extrachill_analytics_delivery_unknown_source( 'Google Analytics ability (datamachine/google-analytics) is unavailable.' );
	}

	$result = $ability->execute(
		array(
			'action'     => 'page_stats',
			'hostname'   => $host,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'limit'      => 10000,
		)
	);
	if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] ) ) {
		$error = is_wp_error( $result ) ? $result->get_error_message() : ( is_array( $result ) && ! empty( $result['error'] ) ? $result['error'] : 'GA returned no successful result.' );
		return extrachill_analytics_delivery_unknown_source( 'Google Analytics unavailable: ' . $error );
	}
	if ( ( $result['date_range']['start_date'] ?? '' ) !== $start_date || ( $result['date_range']['end_date'] ?? '' ) !== $end_date ) {
		return extrachill_analytics_delivery_unknown_source( 'Google Analytics returned a different date window than requested.' );
	}

	$rows = (array) ( $result['results'] ?? array() );
	if ( count( $rows ) >= 10000 ) {
		return extrachill_analytics_delivery_unknown_source( 'Google Analytics reached the 10,000-row ability limit; partial page totals are not compared.' );
	}

	$normalized = array();
	foreach ( $rows as $row ) {
		$path         = isset( $row['pagePath'] ) ? (string) $row['pagePath'] : '';
		$route_family = extrachill_analytics_revenue_classify_route_family( $path );
		$policy       = extrachill_analytics_delivery_policy_for_context(
			extrachill_analytics_revenue_ad_policy_context( $blog_id, $path, '', $route_family )
		);
		$normalized[] = array(
			'pageviews'    => isset( $row['screenPageViews'] ) ? (int) $row['screenPageViews'] : 0,
			'sessions'     => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
			'policy'       => $policy['status'],
			'route_family' => $route_family,
			'resolved'     => null,
		);
	}

	return extrachill_analytics_delivery_summarize_source( $normalized, $start_date, $end_date );
}

/**
 * Read exact-window Mediavine snapshots from the persisted revenue store.
 *
 * @param int    $blog_id    Blog ID.
 * @param string $start_date Inclusive start.
 * @param string $end_date   Inclusive end.
 * @return array Normalized source evidence.
 */
function extrachill_analytics_delivery_read_mediavine( $blog_id, $start_date, $end_date ) {
	$rows   = extrachill_analytics_revenue_get_rows(
		array(
			'blog_id'      => $blog_id,
			'period_start' => $start_date,
			'period_end'   => $end_date,
		)
	);
	$window = extrachill_analytics_delivery_validate_snapshot_window( $rows, $start_date, $end_date );
	if ( ! $window['exact'] ) {
		return extrachill_analytics_delivery_unknown_source( $window['reason'] );
	}

	$normalized = array();
	foreach ( $rows as $row ) {
		$source_url   = ! empty( $row->canonical_url ) ? $row->canonical_url : ( ! empty( $row->url ) ? $row->url : $row->slug );
		$route_family = extrachill_analytics_revenue_classify_route_family( $source_url );
		$policy       = extrachill_analytics_delivery_policy_for_context(
			extrachill_analytics_revenue_ad_policy_context( $blog_id, $source_url, '', $route_family )
		);
		$normalized[] = array(
			'pageviews'    => isset( $row->views ) ? (int) $row->views : 0,
			'sessions'     => 0,
			'policy'       => $policy['status'],
			'route_family' => $route_family,
			'resolved'     => ! empty( $row->post_id ) && ! empty( $row->content_blog_id ),
		);
	}

	$source                     = extrachill_analytics_delivery_summarize_source( $normalized, $start_date, $end_date );
	$source['snapshot_periods'] = $window['periods'];
	return $source;
}

/**
 * Verify that persisted snapshots cover the requested range exactly once.
 *
 * Rows cannot be prorated. A partial, gapped, overlapping, or duplicate batch
 * window is therefore unavailable for this comparison.
 *
 * @param array  $rows       Persisted rows.
 * @param string $start_date Requested start.
 * @param string $end_date   Requested end.
 * @return array Exactness result.
 */
function extrachill_analytics_delivery_validate_snapshot_window( array $rows, $start_date, $end_date ) {
	$periods = array();
	foreach ( $rows as $row ) {
		$start = isset( $row->period_start ) ? (string) $row->period_start : '';
		$end   = isset( $row->period_end ) ? (string) $row->period_end : '';
		$batch = isset( $row->import_batch ) ? (string) $row->import_batch : '';
		if ( ! extrachill_analytics_delivery_valid_date( $start ) || ! extrachill_analytics_delivery_valid_date( $end ) ) {
			continue;
		}
		$key = $start . '|' . $end;
		if ( ! isset( $periods[ $key ] ) ) {
			$periods[ $key ] = array(
				'start'   => $start,
				'end'     => $end,
				'batches' => array(),
			);
		}
		$periods[ $key ]['batches'][ $batch ] = true;
	}

	if ( empty( $periods ) ) {
		return array(
			'exact'   => false,
			'reason'  => 'No dated Mediavine snapshot covers the requested window; source is unknown, not zero.',
			'periods' => array(),
		);
	}

	$periods = array_values( $periods );
	usort(
		$periods,
		static function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] );
		}
	);
	foreach ( $periods as &$period ) {
		$period['batches'] = array_keys( $period['batches'] );
		if ( 1 !== count( $period['batches'] ) ) {
			return array(
				'exact'   => false,
				'reason'  => 'Mediavine has duplicate snapshot batches for a period; refusing to double-count.',
				'periods' => $periods,
			);
		}
	}
	unset( $period );

	if ( $periods[0]['start'] !== $start_date || $periods[ count( $periods ) - 1 ]['end'] !== $end_date ) {
		return array(
			'exact'   => false,
			'reason'  => 'Persisted Mediavine snapshots do not cover the exact requested boundaries; rows cannot be prorated.',
			'periods' => $periods,
		);
	}
	for ( $i = 1, $count = count( $periods ); $i < $count; ++$i ) {
		$expected = gmdate( 'Y-m-d', strtotime( $periods[ $i - 1 ]['end'] . ' +1 day' ) );
		if ( $periods[ $i ]['start'] !== $expected ) {
			return array(
				'exact'   => false,
				'reason'  => 'Persisted Mediavine snapshots contain a gap or overlap inside the requested window.',
				'periods' => $periods,
			);
		}
	}

	return array(
		'exact'   => true,
		'reason'  => '',
		'periods' => $periods,
	);
}

/**
 * Summarize normalized source rows without changing source definitions.
 *
 * @param array  $rows       Normalized rows.
 * @param string $start_date Inclusive start.
 * @param string $end_date   Inclusive end.
 * @return array Source summary.
 */
function extrachill_analytics_delivery_summarize_source( array $rows, $start_date, $end_date ) {
	$summary = array(
		'available'        => true,
		'reason'           => '',
		'window'           => array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
		),
		'pageviews'        => 0,
		'sessions'         => 0,
		'applicable_views' => 0,
		'blocked_views'    => 0,
		'unknown_views'    => 0,
		'resolved_views'   => 0,
		'unresolved_views' => 0,
		'route_families'   => array(),
	);
	foreach ( $rows as $row ) {
		$views                 = max( 0, (int) ( $row['pageviews'] ?? 0 ) );
		$summary['pageviews'] += $views;
		$summary['sessions']  += max( 0, (int) ( $row['sessions'] ?? 0 ) );

		$policy_key                           = in_array( $row['policy'] ?? '', array( 'applicable', 'blocked' ), true ) ? $row['policy'] : 'unknown';
		$summary[ $policy_key . '_views' ]   += $views;
		$family                               = isset( $row['route_family'] ) && '' !== (string) $row['route_family'] ? (string) $row['route_family'] : 'unknown';
		$summary['route_families'][ $family ] = isset( $summary['route_families'][ $family ] ) ? $summary['route_families'][ $family ] + $views : $views;
		if ( true === ( $row['resolved'] ?? null ) ) {
			$summary['resolved_views'] += $views;
		} elseif ( false === ( $row['resolved'] ?? null ) ) {
			$summary['unresolved_views'] += $views;
		}
	}
	ksort( $summary['route_families'] );
	return $summary;
}

/**
 * Build the pure cross-source report.
 *
 * @param string $start_date Inclusive start.
 * @param string $end_date   Inclusive end.
 * @param array  $sites      Normalized site snapshots.
 * @return array Report.
 */
function extrachill_analytics_delivery_build_report( $start_date, $end_date, array $sites ) {
	$counts = array_fill_keys( array( 'aligned', 'warning', 'blocked', 'unknown' ), 0 );
	$output = array();
	foreach ( $sites as $site ) {
		$policy_status = isset( $site['policy']['status'] ) ? $site['policy']['status'] : 'unknown';
		$ga            = isset( $site['ga'] ) ? $site['ga'] : extrachill_analytics_delivery_unknown_source( 'GA source was not supplied.' );
		$mediavine     = isset( $site['mediavine'] ) ? $site['mediavine'] : extrachill_analytics_delivery_unknown_source( 'Mediavine source was not supplied.' );
		$status        = 'unknown';
		$ratio         = null;
		$extreme       = false;
		$evidence      = array();

		if ( 'blocked' === $policy_status ) {
			$status     = 'blocked';
			$evidence[] = 'Network policy marks this site or route context as ad-free; no delivery coverage ratio is applicable.';
		} elseif ( 'applicable' !== $policy_status ) {
			$evidence[] = 'Authoritative ad policy is unavailable or cannot confirm eligibility; coverage is unknown.';
		} elseif ( empty( $ga['available'] ) || empty( $mediavine['available'] ) ) {
			$evidence[] = empty( $ga['available'] ) ? (string) $ga['reason'] : (string) $mediavine['reason'];
		} else {
			$denominator = (int) ( $ga['applicable_views'] ?? 0 );
			$numerator   = (int) ( $mediavine['applicable_views'] ?? 0 );
			if ( $denominator > 0 ) {
				$ratio   = round( $numerator / $denominator, 4 );
				$status  = $ratio >= 0.8 && $ratio <= 1.2 ? 'aligned' : 'warning';
				$extreme = $ratio < 0.5 || $ratio > 1.5;
			} elseif ( $numerator > 0 ) {
				$status     = 'warning';
				$extreme    = true;
				$evidence[] = 'Mediavine reports applicable delivery while GA applicable pageviews are zero; the ratio is undefined.';
			} else {
				$evidence[] = 'Both measured applicable counters are zero; the ratio denominator is zero, so coverage is unknown.';
			}
		}

		$evidence[] = 'GA screenPageViews and Mediavine pageviews are source-specific counters and are not expected to be identical.';
		++$counts[ $status ];
		$output[] = array(
			'blog_id'            => (int) ( $site['blog_id'] ?? 0 ),
			'canonical_host'     => (string) ( $site['canonical_host'] ?? '' ),
			'status'             => $status,
			'coverage_ratio'     => $ratio,
			'coverage_percent'   => null === $ratio ? null : round( $ratio * 100, 2 ),
			'extreme_divergence' => $extreme,
			'policy'             => $site['policy'],
			'ga'                 => $ga,
			'mediavine'          => $mediavine,
			'evidence'           => $evidence,
		);
	}

	return array(
		'success'          => true,
		'window'           => array(
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'inclusive'  => true,
		),
		'source_contracts' => array(
			'ga'         => 'GA4 screenPageViews and sessions from datamachine/google-analytics action=page_stats, filtered to the canonical WordPress host and exact inclusive window.',
			'mediavine'  => 'Persisted Mediavine pageviews from dated revenue snapshots. Snapshots must cover the exact inclusive window contiguously and without duplicate batches; rows are never prorated.',
			'comparison' => 'coverage_ratio = Mediavine applicable pageviews / GA applicable screenPageViews. These are different counters; the ratio diagnoses delivery/measurement divergence, not equality.',
		),
		'thresholds'       => array(
			'aligned_min'  => 0.8,
			'aligned_max'  => 1.2,
			'extreme_low'  => 0.5,
			'extreme_high' => 1.5,
		),
		'summary'          => array_merge( $counts, array( 'total' => count( $output ) ) ),
		'sites'            => $output,
		'caveat'           => 'Unknown means policy or source evidence was unavailable, incomplete, truncated, or not on the exact requested window; it never means zero. Host ownership comes only from the WordPress blog home URL. No read-time path resolver is used and neither source is mutated.',
	);
}

/**
 * Build a stable unavailable-source marker.
 *
 * @param string $reason Coverage gap reason.
 * @return array Marker.
 */
function extrachill_analytics_delivery_unknown_source( $reason ) {
	return array(
		'available' => false,
		'reason'    => (string) $reason,
	);
}

/**
 * Validate an exact Y-m-d date.
 *
 * @param string $date Candidate date.
 * @return bool Whether valid.
 */
function extrachill_analytics_delivery_valid_date( $date ) {
	if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
		return false;
	}
	return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
}
