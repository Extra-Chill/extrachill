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
				'description' => __( 'Object with clicks, impressions, ctr, and per-destination-site breakdown.', 'extrachill-analytics' ),
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
 * @param array $input Input parameters.
 * @return array CTR summary.
 */
function extrachill_analytics_ability_get_bridge_ctr( $input ) {
	global $wpdb;

	$days    = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$table = extrachill_analytics_events_table();

	$where  = array( "event_type IN ('bridge_click', 'bridge_impression')" );
	$values = array();

	if ( $days > 0 ) {
		$where[]  = 'created_at >= %s';
		$values[] = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	}

	if ( $blog_id > 0 ) {
		$where[]  = 'blog_id = %d';
		$values[] = $blog_id;
	}

	$where_clause = implode( ' AND ', $where );

	// Totals by event type.
	$totals_sql = "SELECT event_type, COUNT(*) AS count FROM {$table} WHERE {$where_clause} GROUP BY event_type";

	$totals_rows = empty( $values )
		? $wpdb->get_results( $totals_sql )
		: $wpdb->get_results( $wpdb->prepare( $totals_sql, $values ) );

	$clicks      = 0;
	$impressions = 0;

	foreach ( (array) $totals_rows as $row ) {
		if ( 'bridge_click' === $row->event_type ) {
			$clicks = (int) $row->count;
		} elseif ( 'bridge_impression' === $row->event_type ) {
			$impressions = (int) $row->count;
		}
	}

	return array(
		'clicks'      => $clicks,
		'impressions' => $impressions,
		'ctr'         => $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0.0,
		'days'        => $days,
		'period'      => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'note'        => 'Both events fire client-side (sendBeacon) and are humans-with-JS by construction; this CTR is bot-filtered by design, unlike the raw GA4 network_bridge channel.',
	);
}
