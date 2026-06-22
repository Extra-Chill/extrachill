<?php
/**
 * Get Bridge CTR Ability
 *
 * Read-side ability that computes the cross-site network bridge's
 * bot-filtered click-through rate from the sibling `bridge_click` and
 * `bridge_impression` events recorded by the multisite bridge instrumentation
 * (extrachill-multisite#58).
 *
 * Why this is the bot-filtered density channel:
 *
 *   The raw GA4 `network_bridge` channel counts UTM *arrivals*, which
 *   prefetch/prerender/crawler hits fake — it shows physically-impossible
 *   sub-1.0 pageviews/session. Both events read here, by contrast, are fired
 *   client-side with sendBeacon and therefore only exist for real,
 *   JS-executing browsers. Counting them is the bot filter: every click and
 *   every impression is a human-with-JS by construction.
 *
 *   CTR = clicks / impressions is therefore a deterministic, bot-free
 *   engagement signal that can demote the bot-inflated raw `network_bridge`
 *   session count to a diagnostic.
 *
 * PER-DESTINATION GRAIN: both events now carry `dest_site` in event_data — the
 * click beacon always did, and the impression beacon does as of
 * extrachill-analytics#75 (one impression per rendered card instead of one
 * per pageview). Clicks and impressions therefore share the per-destination
 * grain, so this ability returns a real per-destination-site CTR breakdown
 * alongside the network total, rather than pairing a per-pageview denominator
 * with a per-link numerator.
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
			'label'               => __( 'Get Bridge CTR', 'extrachill-analytics' ),
			'description'         => __( 'Returns the bot-filtered cross-site bridge click-through rate (clicks / impressions) over a window.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with network-total clicks, impressions, ctr, and a per-destination-site breakdown (clicks, impressions, ctr per dest_site).', 'extrachill-analytics' ),
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
 * Execute callback for get-bridge-ctr ability.
 *
 * Strategy: pull the in-window `bridge_click` and `bridge_impression` rows
 * through the canonical events query helper (it owns the safe, prepared WHERE
 * building and returns event_data already JSON-decoded) and aggregate in PHP.
 * Both events now carry `dest_site` in event_data, so the same pass produces
 * the network total AND the per-destination-site breakdown. Volume is bounded
 * (these events fire only for humans-with-JS), so a paged fetch + PHP rollup is
 * clearer than GROUP-BY-on-JSON SQL — and it matches get-outbound-clicks.
 *
 * @param array $input Input parameters.
 * @return array CTR summary.
 */
function extrachill_analytics_ability_get_bridge_ctr( $input ) {
	$days    = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	// Pull both bridge events in pages so the whole window is aggregated
	// without an arbitrary single-query cap. event_data comes back decoded.
	$query_args = array(
		'event_type' => array( 'bridge_click', 'bridge_impression' ),
		'limit'      => 5000,
		'offset'     => 0,
	);

	if ( $days > 0 ) {
		$query_args['date_from'] = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$query_args['blog_id'] = $blog_id;
	}

	$rows = array();
	do {
		$page       = extrachill_get_analytics_events( $query_args );
		$page_count = count( (array) $page );
		if ( 0 === $page_count ) {
			break;
		}
		$rows                  = array_merge( $rows, $page );
		$query_args['offset'] += $query_args['limit'];
	} while ( $page_count === $query_args['limit'] );

	$clicks      = 0;
	$impressions = 0;

	// Per-destination accumulators keyed by dest_site.
	$by_dest = array();

	foreach ( (array) $rows as $row ) {
		// extrachill_get_analytics_events() returns event_data already decoded.
		$data      = is_array( $row->event_data ) ? $row->event_data : array();
		$dest_site = isset( $data['dest_site'] ) ? (string) $data['dest_site'] : '';
		$is_click  = ( 'bridge_click' === $row->event_type );

		if ( $is_click ) {
			++$clicks;
		} else {
			++$impressions;
		}

		if ( ! isset( $by_dest[ $dest_site ] ) ) {
			$by_dest[ $dest_site ] = array(
				'clicks'      => 0,
				'impressions' => 0,
			);
		}

		if ( $is_click ) {
			++$by_dest[ $dest_site ]['clicks'];
		} else {
			++$by_dest[ $dest_site ]['impressions'];
		}
	}

	// Build the per-destination ranking with a per-dest CTR. dest_site '' means
	// the event predates the per-card dest_site emit (extrachill-analytics#75)
	// or carried an untagged card; it is surfaced as '(unknown)' so the legacy
	// dest-less rows are visible rather than silently dropped.
	$by_dest_site = array();
	foreach ( $by_dest as $dest => $counts ) {
		$dest_clicks      = (int) $counts['clicks'];
		$dest_impressions = (int) $counts['impressions'];
		$by_dest_site[]   = array(
			'dest_site'   => '' === $dest ? '(unknown)' : $dest,
			'clicks'      => $dest_clicks,
			'impressions' => $dest_impressions,
			'ctr'         => $dest_impressions > 0 ? round( $dest_clicks / $dest_impressions, 4 ) : 0.0,
		);
	}

	// Rank by impressions (the exposure denominator) so the busiest hops lead.
	usort(
		$by_dest_site,
		static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		}
	);

	return array(
		'clicks'       => $clicks,
		'impressions'  => $impressions,
		'ctr'          => $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0.0,
		'by_dest_site' => $by_dest_site,
		'days'         => $days,
		'blog_id'      => $blog_id,
		'period'       => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'note'         => 'Both events fire client-side (sendBeacon) and are humans-with-JS by construction; this CTR is bot-filtered by design, unlike the raw GA4 network_bridge channel. Clicks and impressions now share the per-destination grain (one impression per rendered card carries dest_site as of extrachill-analytics#75), so by_dest_site pairs each destination\'s clicks to its own impressions. dest_site "(unknown)" rows are legacy page-level impressions emitted before the per-card change (or untagged cards); they shrink as new per-card data accumulates.',
	);
}
