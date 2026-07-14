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
			'description'         => __( 'Join imported Mediavine per-URL ad revenue to WordPress content metadata (category, content format, age) and roll it up by category, by content format, or as a TIME-SERIES revenue arc. Content totals and rollups cover RESOLVED PUBLISHED POSTS ONLY — unresolved routes (home, pagination, taxonomy archives, app/account routes, legacy .html ghosts) are excluded from content metrics so published-content RPM is honest, and reported separately under `unresolved` with a by_route_family breakdown. group_by=timeseries sums revenue/views per time bucket chronologically (month-over-month, the HCU cliff in dollars) — the first-class capability, since each monthly Mediavine export is one point on the arc. group_by=format/category/both reports pages, views, revenue, RPM, and $/page (revenue/pages) — $/page is the honest "worth producing" metric because RPM alone misleads (high RPM on tiny volume = pennies); scope these to one bucket with period=YYYY-MM. Revenue is exactly what was imported from the Mediavine Pages CSV (Mediavine has no per-page revenue API, only ad-config); this ability never estimates ad income. NOTE: the flat lifetime export is time-blind (no date column, one cumulative total per URL) and undercounts the 2022-2023 peak — import date-ranged monthly CSVs for the real arc.', 'extrachill-analytics' ),
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
			'success'    => true,
			'rows'       => 0,
			'window'     => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
			'rollups'    => array(),
			'totals'     => array(
				'pages'            => 0,
				'views'            => 0,
				'revenue'          => 0.0,
				'rpm'              => 0.0,
				'dollars_per_page' => 0.0,
			),
			'unresolved' => array(
				'pages'           => 0,
				'views'           => 0,
				'revenue'         => 0.0,
				'rpm'             => 0.0,
				'by_route_family' => array(),
			),
			'caveat'     => __( 'No revenue snapshots for this blog/window. Import a Mediavine Pages CSV first: wp extrachill analytics revenue import <csv>.', 'extrachill-analytics' ),
		);
	}

	// Classify each snapshot row into a resolved-content record or an unresolved
	// route record. Resolution = a post that currently EXISTS and is PUBLISHED.
	// url_to_postid() (used at import AND here) already returns only published
	// posts, but a stored post_id can go stale (post trashed since import), so the
	// publish-status recheck is what keeps non-published IDs out of content
	// totals. Unresolved rows become a diagnostic cohort, never content buckets.
	$records = array();

	foreach ( $rows as $row ) {
		$post_id = (int) $row->post_id;

		$views   = (int) $row->views;
		$revenue = (float) $row->revenue;

		$content_blog_id = ! empty( $row->content_blog_id ) ? (int) $row->content_blog_id : $blog_id;
		// Content remains eligible only when 'publish' === get_post_status( $post_id ).
		$content = $post_id > 0 ? extrachill_analytics_revenue_with_content_blog(
			$content_blog_id,
			static function () use ( $post_id ) {
				if ( 'publish' !== get_post_status( $post_id ) ) {
					return null;
				}
				$terms = get_the_terms( $post_id, 'category' );
				return array(
					'categories' => ( is_array( $terms ) && ! empty( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array( 'uncategorized' ),
					'format'     => extrachill_analytics_classify_format( $post_id ),
				);
			}
		) : null;

		if ( is_array( $content ) ) {
			$records[] = array(
				'is_content' => true,
				'page_key'   => 'p' . $content_blog_id . ':' . $post_id,
				'format'     => $content['format'],
				'categories' => $content['categories'],
				'views'      => $views,
				'revenue'    => $revenue,
				'url'        => ! empty( $row->canonical_url ) ? $row->canonical_url : ( $row->url ? $row->url : $row->slug ),
			);
		} else {
			// Unresolved route (post_id 0, or stale/non-published ID): diagnostic
			// cohort only — excluded from content totals and buckets.
			$records[] = array(
				'is_content' => false,
				'page_key'   => 'u' . md5( (string) $row->slug ),
				'views'      => $views,
				'revenue'    => $revenue,
				'url'        => $row->url ? $row->url : $row->slug,
			);
		}
	}

	$built = extrachill_analytics_revenue_build_rollups( $records, $group_by );

	return array(
		'success'    => true,
		'rows'       => count( $rows ),
		'blog_id'    => $blog_id,
		'group_by'   => $group_by,
		'window'     => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
		'rollups'    => $built['rollups'],
		'totals'     => $built['totals'],
		'unresolved' => $built['unresolved'],
		'caveat'     => __( 'Totals and rollups cover RESOLVED PUBLISHED CONTENT ONLY — unresolved routes (home, pagination, taxonomy archives, app/account routes, legacy .html ghosts) are excluded so published-content RPM is honest, and reported separately under `unresolved` with a by_route_family breakdown. $/page is the honest "worth producing" metric — RPM alone misleads (high RPM on tiny volume = pennies). Revenue is Mediavine-imported (the only source of ad income); never estimated. Note: the Mediavine Pages CSV carries one path per row and may omit hostnames, so cross-site paths (e.g. Festival Wire) can land under the import blog when the hostname is ambiguous — treat the unresolved cohort as coverage signal, not an attribution verdict.', 'extrachill-analytics' ),
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
 * Classify an unresolved (non-post) route into a coarse diagnostic family.
 *
 * Pure (no WordPress dependency) so the revenue rollup can report WHAT the
 * unresolved traffic actually is — home, pagination, taxonomy archives, app/
 * account routes, legacy .html ghost pages — instead of lumping every
 * non-content path under a misleading `uncategorized` bucket. See issue #130.
 *
 * @param string $url Raw URL/path from the revenue row (may be host-relative).
 * @return string Route family slug.
 */
function extrachill_analytics_revenue_classify_route_family( $url ) {
	$raw = strtolower( trim( (string) $url ) );

	// Reduce a full URL to its path; bare slugs/paths pass through.
	$path = $raw;
	if ( preg_match( '#^https?://#i', $raw ) ) {
		$parsed = parse_url( $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure by design (no WP at test time).
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
	}
	if ( '' === $path ) {
		$path = '/';
	}
	$path = '/' . ltrim( $path, '/' );

	if ( '/' === $path ) {
		return 'home';
	}

	if ( preg_match( '#^/page/\d+(/|$)#i', $path ) ) {
		return 'pagination';
	}

	// Legacy .html ghost pages that no longer resolve to a WordPress post.
	if ( preg_match( '/\.html(?:[\/?#]|$)/i', $path ) ) {
		return 'legacy-html';
	}

	// Taxonomy / archive routes (no single post to attribute revenue to).
	if ( preg_match( '#^/(location|locations|category|categories|tag|tags|t|festival|festivals|artist|artists|venue|venues)(/|$)#i', $path ) ) {
		return 'taxonomy-archive';
	}

	// App / account / auth / admin / shop chrome routes.
	if ( preg_match( '#^/(account|accounts|app|apps|login|log-in|signin|sign-in|register|sign-up|signup|member|members|my-|dashboard|cart|checkout|wp-|wp-admin|wp-login)(/|$)#i', $path ) ) {
		return 'app-account';
	}

	return 'other';
}

/**
 * Build the category/format rollups, content totals, and the unresolved
 * diagnostic cohort from pre-classified revenue records.
 *
 * This is the PURE core of the rollup — it touches no WordPress state, so it is
 * unit-tested directly. The execute_callback does the WordPress-dependent
 * resolution (post lookup, publish-status gate, term lookup) and hands the
 * resulting records here.
 *
 * CONTRACT (issue #130):
 *   - `totals` and `rollups` contain RESOLVED PUBLISHED CONTENT ONLY. Unresolved
 *     routes never inflate content pages/views/revenue or leak into category/
 *     format buckets. This keeps published-content RPM honest ($23.37, not the
 *     contaminated $13.98 the combined rollup reported).
 *   - `unresolved` is an explicit diagnostic cohort — same metrics, plus a
 *     `by_route_family` breakdown — so non-content routes (/, /page/2/,
 *     /location/..., app routes, legacy .html ghosts) stay visible without being
 *     mistaken for uncategorized posts.
 *
 * Each record shape:
 *   {
 *     is_content (bool):  true = resolved published post; false = unresolved.
 *     page_key   (string): dedupe key ('p'.post_id or 'u'.hash(slug)).
 *     format     (string): content format (content rows only; unused otherwise).
 *     categories (string[]): category slugs (content rows only).
 *     views      (int),
 *     revenue    (float),
 *     url        (string): raw URL/path (unresolved rows only, for route family).
 *   }
 *
 * @param array  $records  Pre-classified revenue records.
 * @param string $group_by 'format', 'category', 'both', or 'timeseries'.
 * @return array {
 *     @type array $rollups    by_format and/or by_category (content only).
 *     @type array $totals     Content-only pages/views/revenue/rpm/dollars_per_page.
 *     @type array $unresolved Diagnostic cohort + by_route_family breakdown.
 * }
 */
function extrachill_analytics_revenue_build_rollups( array $records, $group_by ) {
	$by_format   = array();
	$by_category = array();

	$content_pages   = 0;
	$content_views   = 0;
	$content_revenue = 0.0;
	$seen_content    = array();

	$unresolved_pages   = 0;
	$unresolved_views   = 0;
	$unresolved_revenue = 0.0;
	$seen_unresolved    = array();
	$unresolved_family  = array();

	foreach ( $records as $rec ) {
		$views   = (int) ( isset( $rec['views'] ) ? $rec['views'] : 0 );
		$revenue = (float) ( isset( $rec['revenue'] ) ? $rec['revenue'] : 0.0 );
		$key     = (string) ( isset( $rec['page_key'] ) ? $rec['page_key'] : '' );

		if ( empty( $rec['is_content'] ) ) {
			// Unresolved diagnostic cohort — excluded from content totals/buckets.
			if ( '' !== $key && empty( $seen_unresolved[ $key ] ) ) {
				$seen_unresolved[ $key ] = true;
				++$unresolved_pages;
			}
			$unresolved_views   += $views;
			$unresolved_revenue += $revenue;

			$family = extrachill_analytics_revenue_classify_route_family( isset( $rec['url'] ) ? $rec['url'] : '' );
			if ( ! isset( $unresolved_family[ $family ] ) ) {
				$unresolved_family[ $family ] = array(
					'pages'   => 0,
					'views'   => 0,
					'revenue' => 0.0,
					'seen'    => array(),
				);
			}
			if ( '' !== $key && empty( $unresolved_family[ $family ]['seen'][ $key ] ) ) {
				$unresolved_family[ $family ]['seen'][ $key ] = true;
				++$unresolved_family[ $family ]['pages'];
			}
			$unresolved_family[ $family ]['views']   += $views;
			$unresolved_family[ $family ]['revenue'] += $revenue;
			continue;
		}

		// Resolved content: de-dupe the page across URL variants in this window.
		$count_page           = '' !== $key && empty( $seen_content[ $key ] );
		$seen_content[ $key ] = true;

		$format = isset( $rec['format'] ) ? (string) $rec['format'] : 'uncategorized';
		extrachill_analytics_revenue_accumulate( $by_format, $format, $views, $revenue, $count_page );

		$categories = ( ! empty( $rec['categories'] ) && is_array( $rec['categories'] ) )
			? array_map( 'strval', $rec['categories'] )
			: array( 'uncategorized' );
		foreach ( $categories as $cat ) {
			extrachill_analytics_revenue_accumulate( $by_category, $cat, $views, $revenue, $count_page );
		}

		$content_views   += $views;
		$content_revenue += $revenue;
		if ( $count_page ) {
			++$content_pages;
		}
	}

	$rollups = array();
	if ( 'format' === $group_by || 'both' === $group_by ) {
		$rollups['by_format'] = extrachill_analytics_revenue_finalize( $by_format );
	}
	if ( 'category' === $group_by || 'both' === $group_by ) {
		$rollups['by_category'] = extrachill_analytics_revenue_finalize( $by_category );
	}

	$family_rows = array();
	foreach ( $unresolved_family as $family => $agg ) {
		$fpages        = (int) $agg['pages'];
		$fviews        = (int) $agg['views'];
		$frevenue      = (float) $agg['revenue'];
		$family_rows[] = array(
			'route_family' => $family,
			'pages'        => $fpages,
			'views'        => $fviews,
			'revenue'      => round( $frevenue, 2 ),
			'rpm'          => $fviews > 0 ? round( $frevenue / ( $fviews / 1000 ), 2 ) : 0.0,
		);
	}
	usort(
		$family_rows,
		static function ( $a, $b ) {
			return $b['views'] <=> $a['views'];
		}
	);

	return array(
		'rollups'    => $rollups,
		'totals'     => array(
			'pages'            => $content_pages,
			'views'            => $content_views,
			'revenue'          => round( $content_revenue, 2 ),
			'rpm'              => $content_views > 0 ? round( $content_revenue / ( $content_views / 1000 ), 2 ) : 0.0,
			'dollars_per_page' => $content_pages > 0 ? round( $content_revenue / $content_pages, 2 ) : 0.0,
		),
		'unresolved' => array(
			'pages'           => $unresolved_pages,
			'views'           => $unresolved_views,
			'revenue'         => round( $unresolved_revenue, 2 ),
			'rpm'             => $unresolved_views > 0 ? round( $unresolved_revenue / ( $unresolved_views / 1000 ), 2 ) : 0.0,
			'by_route_family' => $family_rows,
		),
	);
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
