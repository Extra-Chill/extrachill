<?php
/**
 * Get Outbound Clicks Ability
 *
 * Read-side ability that surfaces first-party outbound-click behaviour — where
 * readers EXIT the Extra Chill network to external domains.
 * It reads the sibling `outbound_click` events written by the outbound-click
 * instrumentation (assets/js/outbound-tracking.js → /analytics/click → the
 * track-analytics-event ability), the cross-DOMAIN counterpart to the
 * cross-SITE conversion map.
 *
 * Why this is the first-party exit channel:
 *
 *   Each `outbound_click` row is fired client-side with sendBeacon and
 *   therefore only exists for a real, JS-executing browser. The generic
 *   request classifier currently stamps these REST beacons as bots, so this
 *   report deliberately counts outbound_click rows regardless of that stamp.
 *   Rows carry the anonymous first-party visitor_id (NULL under GPC/DNT
 *   opt-out, excluded from per-visitor cuts exactly like the rest of the
 *   system).
 *
 * HONEST BY CONSTRUCTION: this event is NEW — it captures going forward only.
 * Until clicks accumulate, totals will be low or zero, and that low number is
 * the real (young-data) state, not a broken query. The note field always
 * carries that caveat plus the exact UTC window.
 *
 * OUTPUT surfaces three actionable cuts:
 *   - by_category    : outbound clicks grouped into spotify/social/ticketing/
 *                      artist-site/merch/other (the affiliate/ticketing-revenue
 *                      axis), with each category's share of total exits.
 *   - by_destination : the top external hosts readers exit to, ranked by clicks.
 *   - by_source      : the top source pages that drive the most exits, with the
 *                      outbound clicks each one generated (the "which content
 *                      drives valuable exits" question, paired with revenue).
 *
 * @package ExtraChill\Analytics
 * @since 0.17.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-outbound-clicks ability.
 */
function extrachill_analytics_register_outbound_clicks_ability() {
	wp_register_ability(
		'extrachill/get-outbound-clicks',
		array(
			'label'               => __( 'Get Outbound Clicks', 'extrachill-analytics' ),
			'description'         => __( 'First-party outbound-click report: where readers exit the network to external domains, grouped by category, top destination host, and top source page. Deterministic from the sendBeacon outbound_click events.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'         => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for all sites.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'category'     => array(
						'type'        => 'string',
						'description' => __( 'Filter to a single destination category (spotify|social|ticketing|artist-site|merch|other). Empty for all.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'limit'        => array(
						'type'        => 'integer',
						'description' => __( 'Number of rows to return for the destination and source rankings.', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'include_bots' => array(
						'type'        => 'boolean',
						'description' => __( 'Deprecated compatibility option. Outbound browser beacons are always included because the generic REST bot stamp does not distinguish them from bots.', 'extrachill-analytics' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with total, by_category, by_destination, by_source, the window, and a diagnostic when stored rows lack destination dimensions.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_outbound_clicks',
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
 * Execute callback for get-outbound-clicks ability.
 *
 * Strategy: pull the in-window `outbound_click` rows (bounded by the
 * event_type + created_at index) and aggregate in PHP. event_data carries the
 * dest_host, dest_url, and category the capture route stamped — so the read
 * does not re-derive classification, it just rolls up. The generic REST is_bot
 * stamp is intentionally not used: browser beacons are consistently marked as
 * bots despite being the canonical outbound signal. Volume is low (a new
 * event), so a single bounded fetch + PHP aggregation is clearer and cheaper
 * than three GROUP-BY JSON queries.
 *
 * @param array $input Input parameters.
 * @return array Outbound-click report.
 */
function extrachill_analytics_ability_get_outbound_clicks( $input ) {
	global $wpdb;

	$days       = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$blog_id    = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;
	$category   = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
	$limit      = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 25;
	$event_type = EC_ANALYTICS_EVENT_OUTBOUND_CLICK;

	// Read the in-window outbound_click rows through the canonical events query
	// helper (it owns the safe, prepared WHERE building) rather than re-rolling
	// raw SQL here. Pull in pages so the whole window is aggregated without an
	// arbitrary single-query cap; volume is low (a new event), so this is a
	// bounded read. event_data comes back already JSON-decoded.
	$query_args = array(
		'event_type' => $event_type,
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

	$total                          = 0;
	$by_category                    = array();
	$by_destination                 = array();
	$by_source                      = array();
	$missing_destination_dimensions = 0;

	foreach ( (array) $rows as $row ) {
		// extrachill_get_analytics_events() returns event_data already decoded.
		$data = is_array( $row->event_data ) ? $row->event_data : array();

		$dest_host = isset( $data['dest_host'] ) ? strtolower( (string) $data['dest_host'] ) : '';

		// Prefer the category stamped at write time; fall back to classifying
		// the host now (older rows, or a row that missed the stamp).
		$row_category = isset( $data['category'] ) && '' !== $data['category']
			? sanitize_key( (string) $data['category'] )
			: ( function_exists( 'extrachill_analytics_classify_outbound_host' )
				? extrachill_analytics_classify_outbound_host( $dest_host )
				: 'other' );

		// Optional category filter.
		if ( '' !== $category && $row_category !== $category ) {
			continue;
		}

		++$total;

		if ( ! isset( $by_category[ $row_category ] ) ) {
			$by_category[ $row_category ] = 0;
		}
		++$by_category[ $row_category ];

		if ( '' !== $dest_host ) {
			if ( ! isset( $by_destination[ $dest_host ] ) ) {
				$by_destination[ $dest_host ] = array(
					'clicks'   => 0,
					'category' => $row_category,
				);
			}
			++$by_destination[ $dest_host ]['clicks'];
		} else {
			++$missing_destination_dimensions;
		}

		$source = (string) $row->source_url;
		if ( '' !== $source ) {
			if ( ! isset( $by_source[ $source ] ) ) {
				$by_source[ $source ] = 0;
			}
			++$by_source[ $source ];
		}
	}

	// Category rollup with share-of-total.
	$category_rank = array();
	foreach ( $by_category as $cat => $count ) {
		$category_rank[] = array(
			'category' => (string) $cat,
			'clicks'   => (int) $count,
			'share'    => $total > 0 ? round( $count / $total, 4 ) : 0.0,
		);
	}
	usort(
		$category_rank,
		static function ( $a, $b ) {
			return $b['clicks'] <=> $a['clicks'];
		}
	);

	// Top destination hosts.
	$destination_rank = array();
	foreach ( $by_destination as $host => $info ) {
		$destination_rank[] = array(
			'dest_host' => (string) $host,
			'category'  => (string) $info['category'],
			'clicks'    => (int) $info['clicks'],
		);
	}
	usort(
		$destination_rank,
		static function ( $a, $b ) {
			return $b['clicks'] <=> $a['clicks'];
		}
	);
	$destination_rank = array_slice( $destination_rank, 0, $limit );

	$diagnostic = null;
	if ( $total > 0 && empty( $destination_rank ) ) {
		$diagnostic = array(
			'code'                   => 'missing_destination_dimensions',
			'message'                => sprintf(
				'%d outbound_click event(s) matched the requested window, but none contain a dest_host dimension.',
				$missing_destination_dimensions
			),
			'rows_missing_dest_host' => $missing_destination_dimensions,
		);
	}

	// Top source pages.
	$source_rank = array();
	foreach ( $by_source as $src => $count ) {
		$source_rank[] = array(
			'source_url' => (string) $src,
			'clicks'     => (int) $count,
		);
	}
	usort(
		$source_rank,
		static function ( $a, $b ) {
			return $b['clicks'] <=> $a['clicks'];
		}
	);
	$source_rank = array_slice( $source_rank, 0, $limit );

	return array(
		'total'          => $total,
		'by_category'    => $category_rank,
		'by_destination' => $destination_rank,
		'by_source'      => $source_rank,
		'days'           => $days,
		'blog_id'        => $blog_id,
		'category'       => $category,
		'diagnostic'     => $diagnostic,
		'period'         => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		'note'           => 'Outbound clicks fire client-side (sendBeacon) and are counted regardless of the generic REST bot stamp, which does not distinguish these browser beacons from bots. The outbound_click event captures exits going forward only, so totals are low/zero until clicks accumulate. NULL-visitor rows (GPC/DNT opt-out) are still counted in aggregate volume but excluded from any per-visitor cut, same as the rest of the system.',
	);
}
