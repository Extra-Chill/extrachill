<?php
/**
 * Get Content Revenue Ability
 *
 * The revenue sibling of datamachine/content-performance. Joins per-URL Mediavine
 * ad revenue (imported from the Pages CSV — Mediavine has no per-page API) to
 * WordPress content metadata (category, content format, post age) and rolls it
 * up by category AND by content format, reporting the metrics the manual stitch
 * surfaced: pages, views, revenue, RPM, and — the honest one — $/page.
 *
 * Why $/page is the load-bearing metric, not RPM: RPM (revenue per 1k views) is
 * a MULTIPLIER, not a volume measure. A trivia format can post a high RPM ($33)
 * yet a tiny $/page ($24) because it has almost no views — "high RPM on tiny
 * volume = pennies." $/page (total revenue / page count) is the only honest
 * answer to "is this format worth producing." Both are reported; $/page is the
 * one to rank on.
 *
 * Lifetime vs recent: high LIFETIME revenue can just mean a page is OLD and
 * accumulated earnings over years — not that it earns NOW. The ability accepts a
 * window (period_start/period_end) that filters to snapshots imported for that
 * window, so an all-time export and a last-30-days export produce distinct
 * "earned ever" vs "earning now" rollups from the same store.
 *
 * Source-of-truth note: Mediavine RPM is the ONLY view of ad income — GA and the
 * first-party events table cannot see ad revenue. So revenue here is exactly what
 * was imported; this ability never estimates it.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-content-revenue ability.
 */
function extrachill_analytics_register_content_revenue_ability() {
	wp_register_ability(
		'extrachill/get-content-revenue',
		array(
			'label'               => __( 'Get Content Revenue Lens', 'extrachill-analytics' ),
			'description'         => __( 'Join imported Mediavine per-URL ad revenue to WordPress content metadata (category, content format, age) and roll it up by category, by content format, or as a TIME-SERIES revenue arc. group_by=timeseries sums revenue/views per time bucket chronologically (month-over-month, the HCU cliff in dollars) — the first-class capability, since each monthly Mediavine export is one point on the arc. group_by=format/category/both reports pages, views, revenue, RPM, and $/page (revenue/pages) — $/page is the honest "worth producing" metric because RPM alone misleads (high RPM on tiny volume = pennies); scope these to one bucket with period=YYYY-MM. Revenue is exactly what was imported from the Mediavine Pages CSV (Mediavine has no per-page revenue API, only ad-config); this ability never estimates ad income. NOTE: the flat lifetime export is time-blind (no date column, one cumulative total per URL) and undercounts the 2022-2023 peak — import date-ranged monthly CSVs for the real arc.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'group_by'        => array(
						'type'        => 'string',
						'description' => __( 'Rollup axis: "format" (default), "category", "both", or "timeseries" (the revenue ARC — revenue/views per time bucket, chronologically, to see month-over-month and the HCU cliff in dollars).', 'extrachill-analytics' ),
						'default'     => 'format',
					),
					'period'          => array(
						'type'        => 'string',
						'description' => __( 'Scope the category/format rollup to one time bucket, e.g. "2026-05" or "all-time". Empty = every bucket combined. Ignored for the timeseries axis (which spans all buckets).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start'    => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start (Y-m-d). With period_end, restricts to snapshots imported for that window (the recent lens). Empty = all snapshots (lifetime).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'      => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end (Y-m-d). See period_start.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'include_alltime' => array(
						'type'        => 'boolean',
						'description' => __( 'For the timeseries axis, include the cumulative "all-time" flat-file bucket (default false — it is a lifetime total, not a point on the arc).', 'extrachill-analytics' ),
						'default'     => false,
					),
					'import_batch'    => array(
						'type'        => 'string',
						'description' => __( 'Restrict to one import batch so multiple imports are never double-counted. Empty = all batches.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'         => array(
						'type'        => 'integer',
						'description' => __( 'Blog ID whose revenue to read. 0 = current blog.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'        => array(
						'type'        => 'string',
						'description' => __( 'Hostname for resolving any still-unresolved slugs to posts (default: extrachill.com).', 'extrachill-analytics' ),
						'default'     => 'extrachill.com',
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Rollups keyed by axis, each with pages, views, revenue, rpm, dollars_per_page, plus totals and caveats.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_content_revenue',
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
 * Execute callback for get-content-revenue ability.
 *
 * @param array $input Input parameters.
 * @return array Rollup data.
 */
function extrachill_analytics_ability_get_content_revenue( $input ) {
	$group_by        = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'format';
	$period          = isset( $input['period'] ) ? (string) $input['period'] : '';
	$period_start    = isset( $input['period_start'] ) ? (string) $input['period_start'] : '';
	$period_end      = isset( $input['period_end'] ) ? (string) $input['period_end'] : '';
	$import_batch    = isset( $input['import_batch'] ) ? (string) $input['import_batch'] : '';
	$include_alltime = ! empty( $input['include_alltime'] );
	$blog_id         = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$hostname        = ! empty( $input['hostname'] ) ? (string) $input['hostname'] : 'extrachill.com';

	if ( ! in_array( $group_by, array( 'format', 'category', 'both', 'timeseries' ), true ) ) {
		$group_by = 'format';
	}

	// The revenue ARC: a pure SUM-by-time-bucket read, chronologically. This is
	// the first-class time-series capability — it does NOT join categories, it
	// answers "what did we earn month-over-month" so the HCU cliff shows in
	// dollars. Each monthly CSV the operator imports becomes one point.
	if ( 'timeseries' === $group_by ) {
		return extrachill_analytics_revenue_timeseries_response( $blog_id, $include_alltime );
	}

	$rows = extrachill_analytics_revenue_get_rows(
		array(
			'blog_id'      => $blog_id,
			'import_batch' => $import_batch,
			'period_label' => $period,
			'period_start' => $period_start,
			'period_end'   => $period_end,
		)
	);

	if ( empty( $rows ) ) {
		return array(
			'success' => true,
			'rows'    => 0,
			'window'  => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
			'rollups' => array(),
			'totals'  => array(
				'pages'            => 0,
				'views'            => 0,
				'revenue'          => 0.0,
				'rpm'              => 0.0,
				'dollars_per_page' => 0.0,
			),
			'caveat'  => __( 'No revenue snapshots for this blog/window. Import a Mediavine Pages CSV first: wp extrachill analytics revenue import <csv>.', 'extrachill-analytics' ),
		);
	}

	// Accumulators keyed by bucket label, per axis.
	$by_format   = array();
	$by_category = array();

	$total_pages   = 0;
	$total_views   = 0;
	$total_revenue = 0.0;

	// De-dupe pages across multiple URL variants of the same post within this
	// window so a post is counted once per bucket (its revenue is summed).
	$seen_post = array();

	foreach ( $rows as $row ) {
		$post_id = (int) $row->post_id;
		if ( 0 === $post_id && '' !== $row->slug ) {
			$post_id = extrachill_analytics_revenue_resolve_post_id( $row->url ? $row->url : $row->slug, $hostname );
		}

		$views   = (int) $row->views;
		$revenue = (float) $row->revenue;

		$format     = 'legacy-html';
		$categories = array( 'legacy-html' );

		if ( $post_id > 0 ) {
			$format = extrachill_analytics_classify_format( $post_id );
			$terms  = get_the_terms( $post_id, 'category' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$categories = wp_list_pluck( $terms, 'slug' );
			} else {
				$categories = array( 'uncategorized' );
			}
		} else {
			// Unresolved: bucket by legacy-html if .html, else uncategorized.
			$format     = preg_match( '/\.html(?:[\/?#]|$)/i', (string) $row->url ) ? 'legacy-html' : 'uncategorized';
			$categories = array( $format );
		}

		// A page (post) is counted once per format bucket; revenue/views still sum.
		$page_key               = $post_id > 0 ? 'p' . $post_id : 'u' . md5( $row->slug );
		$count_page             = empty( $seen_post[ $page_key ] );
		$seen_post[ $page_key ] = true;

		extrachill_analytics_revenue_accumulate( $by_format, $format, $views, $revenue, $count_page );

		foreach ( $categories as $cat ) {
			extrachill_analytics_revenue_accumulate( $by_category, $cat, $views, $revenue, $count_page );
		}

		$total_views   += $views;
		$total_revenue += $revenue;
		if ( $count_page ) {
			++$total_pages;
		}
	}

	$rollups = array();
	if ( 'format' === $group_by || 'both' === $group_by ) {
		$rollups['by_format'] = extrachill_analytics_revenue_finalize( $by_format );
	}
	if ( 'category' === $group_by || 'both' === $group_by ) {
		$rollups['by_category'] = extrachill_analytics_revenue_finalize( $by_category );
	}

	return array(
		'success'  => true,
		'rows'     => count( $rows ),
		'blog_id'  => $blog_id,
		'group_by' => $group_by,
		'window'   => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
		'rollups'  => $rollups,
		'totals'   => array(
			'pages'            => $total_pages,
			'views'            => $total_views,
			'revenue'          => round( $total_revenue, 2 ),
			'rpm'              => $total_views > 0 ? round( $total_revenue / ( $total_views / 1000 ), 2 ) : 0.0,
			'dollars_per_page' => $total_pages > 0 ? round( $total_revenue / $total_pages, 2 ) : 0.0,
		),
		'caveat'   => __( '$/page is the honest "worth producing" metric — RPM alone misleads (high RPM on tiny volume = pennies). High LIFETIME revenue can just mean a page is old and accumulated; use a window for the earning-NOW lens. Revenue is Mediavine-imported (the only source of ad income); never estimated.', 'extrachill-analytics' ),
	);
}

/**
 * Accumulate one snapshot into a bucket.
 *
 * @param array  $bucket     Accumulator (by reference).
 * @param string $key        Bucket key (format or category slug).
 * @param int    $views      Views to add.
 * @param float  $revenue    Revenue to add.
 * @param bool   $count_page Whether this contributes a new page to the count.
 */
function extrachill_analytics_revenue_accumulate( array &$bucket, $key, $views, $revenue, $count_page ) {
	if ( ! isset( $bucket[ $key ] ) ) {
		$bucket[ $key ] = array(
			'pages'   => 0,
			'views'   => 0,
			'revenue' => 0.0,
		);
	}

	$bucket[ $key ]['views']   += $views;
	$bucket[ $key ]['revenue'] += $revenue;
	if ( $count_page ) {
		++$bucket[ $key ]['pages'];
	}
}

/**
 * Finalize an accumulator into sorted rows with derived RPM + $/page.
 *
 * Sorted by $/page DESC — the honest ranking. RPM is reported alongside but is
 * never the sort key, on purpose.
 *
 * @param array $bucket Accumulator.
 * @return array<int, array<string, mixed>>
 */
function extrachill_analytics_revenue_finalize( array $bucket ) {
	$rows = array();

	foreach ( $bucket as $key => $agg ) {
		$pages   = (int) $agg['pages'];
		$views   = (int) $agg['views'];
		$revenue = (float) $agg['revenue'];

		$rows[] = array(
			'bucket'           => $key,
			'pages'            => $pages,
			'views'            => $views,
			'revenue'          => round( $revenue, 2 ),
			'rpm'              => $views > 0 ? round( $revenue / ( $views / 1000 ), 2 ) : 0.0,
			'dollars_per_page' => $pages > 0 ? round( $revenue / $pages, 2 ) : 0.0,
		);
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['dollars_per_page'] <=> $a['dollars_per_page'];
		}
	);

	return $rows;
}

/**
 * Human label for the requested window.
 *
 * @param string $period_start Window start (Y-m-d) or ''.
 * @param string $period_end   Window end (Y-m-d) or ''.
 * @param string $period       Period-label filter (e.g. "2026-05"), or ''.
 * @return string
 */
function extrachill_analytics_revenue_window_label( $period_start, $period_end, $period = '' ) {
	if ( '' !== $period && 'all-time' !== $period ) {
		return $period . ' (period bucket)';
	}

	if ( '' !== $period_start && '' !== $period_end ) {
		return $period_start . ' → ' . $period_end . ' (recent lens)';
	}

	return 'lifetime (all imported snapshots)';
}

/**
 * Build the revenue-ARC (time-series) response.
 *
 * SUMs revenue/views per period_label chronologically, then derives RPM and
 * $/page per point plus a month-over-month delta so the arc — and the HCU cliff
 * — is readable in dollars. The flat "all-time" cumulative bucket is excluded by
 * default (it is a lifetime total, not a point on the arc).
 *
 * @param int  $blog_id         Blog ID.
 * @param bool $include_alltime Include the cumulative "all-time" bucket.
 * @return array Ability response with a `series` array.
 */
function extrachill_analytics_revenue_timeseries_response( $blog_id, $include_alltime ) {
	$points = extrachill_analytics_revenue_get_timeseries(
		array(
			'blog_id'         => $blog_id,
			'include_alltime' => $include_alltime,
		)
	);

	$series        = array();
	$prev_revenue  = null;
	$total_revenue = 0.0;
	$total_views   = 0;
	$peak_revenue  = 0.0;
	$peak_label    = '';

	foreach ( $points as $p ) {
		$revenue = (float) $p->revenue;
		$views   = (int) $p->views;
		$pages   = (int) $p->pages;

		$mom_delta = null;
		$mom_pct   = null;
		if ( null !== $prev_revenue ) {
			$mom_delta = round( $revenue - $prev_revenue, 2 );
			$mom_pct   = $prev_revenue > 0 ? round( ( ( $revenue - $prev_revenue ) / $prev_revenue ) * 100, 1 ) : null;
		}

		$series[] = array(
			'period'           => $p->period_label,
			'period_start'     => $p->period_start,
			'period_end'       => $p->period_end,
			'pages'            => $pages,
			'views'            => $views,
			'revenue'          => round( $revenue, 2 ),
			'rpm'              => $views > 0 ? round( $revenue / ( $views / 1000 ), 2 ) : 0.0,
			'dollars_per_page' => $pages > 0 ? round( $revenue / $pages, 2 ) : 0.0,
			'mom_delta'        => $mom_delta,
			'mom_pct'          => $mom_pct,
		);

		$prev_revenue   = $revenue;
		$total_revenue += $revenue;
		$total_views   += $views;
		if ( $revenue > $peak_revenue ) {
			$peak_revenue = $revenue;
			$peak_label   = $p->period_label;
		}
	}

	return array(
		'success'  => true,
		'rows'     => count( $points ),
		'blog_id'  => $blog_id,
		'group_by' => 'timeseries',
		'window'   => 'revenue arc (per-period, chronological)',
		'series'   => $series,
		'peak'     => array(
			'period'  => $peak_label,
			'revenue' => round( $peak_revenue, 2 ),
		),
		'totals'   => array(
			'periods' => count( $series ),
			'views'   => $total_views,
			'revenue' => round( $total_revenue, 2 ),
		),
		'caveat'   => __( 'The revenue ARC needs DATE-RANGED (monthly) Mediavine exports — import each with --period=YYYY-MM. The flat lifetime export is one cumulative "all-time" total per URL (no dates) and is excluded here by default; on its own it is time-blind and undercounts the 2022-2023 peak, whose revenue ran on old URL structures. Revenue is Mediavine-imported (the only source of ad income); never estimated.', 'extrachill-analytics' ),
	);
}
