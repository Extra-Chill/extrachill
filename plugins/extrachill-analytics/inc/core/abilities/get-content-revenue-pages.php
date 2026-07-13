<?php
/**
 * Get Content Revenue Pages Ability
 *
 * Page-level sibling of extrachill/get-content-revenue. The rollup ability owns
 * format/category/timeseries buckets; this ability owns the FLAT per-page lens —
 * every resolved published post (or unresolved route) as its own ranked row with
 * the full Mediavine delivery stack (views, revenue, derived RPM, source RPM,
 * CPM, viewability, fill rate, impressions/pageview) plus post metadata.
 *
 * Why a sibling and not a new group_by axis on the rollup: the rollup output
 * contract is rollups/totals/unresolved — it cannot cleanly represent a flat,
 * machine-sortable page list with per-page rate metrics and a benchmark flag.
 * The issue explicitly permits narrowly scoped siblings where the existing
 * contract cannot represent the new lens. This ability reuses the SAME store,
 * resolver, content-format classifier, and route-family classifier as the
 * rollup — no new tables, no parallel aggregation, no client.
 *
 * Derived vs source RPM (kept distinct, on purpose): derived_rpm is recomputed
 * here as revenue / (views / 1000); source_rpm is the Mediavine-reported `rpm`
 * column from the CSV. They can disagree (Mediavine rounds; its denominator may
 * differ from pageviews). Both are reported and both are sort keys, so the
 * analyst can rank on either and spot variance. They are never blended into one
 * "rpm" field.
 *
 * Source-rate averaging across collapsed URL variants: when multiple rows share
 * a page_key (URL variants, or multiple snapshots queried together), volume
 * (views/revenue) is SUMMED, but the rate columns (source_rpm, cpm,
 * viewability, fill_rate, impressions_per_pageview) are simple-averaged — NOT
 * views-weighted — because their true denominators (impressions/requests for
 * CPM/viewability/fill_rate) are NOT stored, so views-weighting would invent a
 * denominator. derived_rpm remains the correct aggregated RPM (recomputed from
 * the summed revenue/views). A caveat discloses this.
 *
 * Zero-view revenue handling: a Mediavine row CAN report revenue > 0 with views
 * = 0 (rounding, sub-threshold fills, a missed views cell). derived_rpm is set
 * to 0.0 (NEVER a division error, NEVER infinity), the page is flagged
 * `zero_views => true`, and the revenue is preserved and visible — never hidden.
 *
 * Default window contract: with no explicit period/window/batch, the query
 * scopes to the FRESHEST DATED period (the most recent monthly bucket by
 * period_end) — it never silently combines the flat "all-time" file with
 * monthly buckets and duplicate batches (which would double-count). Pass
 * period=all-time, or period_start/period_end, or import_batch for an explicit
 * combined/lifetime view. The response exposes selected_periods and
 * selected_batches so the caller always knows what was queried.
 *
 * Network/blog authorization: a current-site manage_options holder may read
 * their own blog; a CROSS-blog read requires manage_network_options (network
 * admin / super admin). WP_CLI is allowed (server-side operator). All slug->post
 * resolution and post-metadata lookup run switch_to_blog($blog_id) so post IDs
 * resolve in the correct blog context.
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-content-revenue-pages ability.
 */
function extrachill_analytics_register_content_revenue_pages_ability() {
	wp_register_ability(
		'extrachill/get-content-revenue-pages',
		array(
			'label'               => __( 'Get Content Revenue Pages Lens', 'extrachill-analytics' ),
			'description'         => __( 'Page-level Mediavine revenue lens. Returns every resolved published post (or unresolved route) as its own ranked row with the full delivery stack: views, revenue, DERIVED RPM (revenue / views/1000), SOURCE RPM (Mediavine-reported), CPM, viewability, fill rate, impressions/pageview, plus post metadata (ID, title, URL/path, category, content format, publication date) when resolved. Derived and source RPM are kept distinct. Revenue rows with zero pageviews are flagged zero_views and preserved (derived_rpm = 0, never a division error). Supports cohort (resolved/unresolved/all), minimum-views threshold, stable sorting by any metric, limit, and an optional defensible benchmark-opportunity flag. Default window is the FRESHEST DATED period (never silently combines all-time + monthly + duplicates); pass period/window/batch explicitly for a combined view. Cross-blog reads require network admin. Reuses the same store, resolver, and classifiers as extrachill/get-content-revenue — no new tables. CLI consumers map args and format output only.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'period'            => array(
						'type'        => 'string',
						'description' => __( 'Scope to one time bucket, e.g. "2026-05" or "all-time". Empty = default to the freshest dated period (see window contract).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start'      => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start (Y-m-d). With period_end, restricts to snapshots imported for that window.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'        => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end (Y-m-d). See period_start.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'import_batch'      => array(
						'type'        => 'string',
						'description' => __( 'Restrict to one import batch. Empty = all batches in the scope.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'           => array(
						'type'        => 'integer',
						'description' => __( 'Blog ID whose revenue to read. 0 = current blog. Cross-blog reads require manage_network_options.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'          => array(
						'type'        => 'string',
						'description' => __( 'Optional hostname for resolving relative slugs to posts. Empty = the target blog hostname.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'cohort'            => array(
						'type'        => 'string',
						'enum'        => array( 'resolved', 'unresolved', 'all' ),
						'description' => __( 'Page cohort: "resolved" (published content only, default), "unresolved" (route cohort), or "all".', 'extrachill-analytics' ),
						'default'     => 'resolved',
					),
					'min_views'         => array(
						'type'        => 'integer',
						'description' => __( 'Exclude pages with fewer than this many views. Default 0 (no floor).', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'sort_by'           => array(
						'type'        => 'string',
						'enum'        => array( 'views', 'revenue', 'derived_rpm', 'source_rpm', 'cpm', 'viewability', 'fill_rate', 'impressions_per_pageview', 'dollars_per_page', 'benchmark_opportunity' ),
						'description' => __( 'Sort key. Default derived_rpm.', 'extrachill-analytics' ),
						'default'     => 'derived_rpm',
					),
					'order'             => array(
						'type'        => 'string',
						'enum'        => array( 'desc', 'asc' ),
						'description' => __( 'Sort direction. Default desc.', 'extrachill-analytics' ),
						'default'     => 'desc',
					),
					'limit'             => array(
						'type'        => 'integer',
						'description' => __( 'Maximum pages to return. 0 = unlimited. Default 50.', 'extrachill-analytics' ),
						'default'     => 50,
					),
					'include_post_meta' => array(
						'type'        => 'boolean',
						'description' => __( 'Include post metadata (title, URL, path, category, format, publication date) on resolved pages. Default true.', 'extrachill-analytics' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array(
						'type'        => 'boolean',
						'description' => 'Whether the read succeeded.',
					),
					'rows'    => array(
						'type'        => 'integer',
						'description' => 'Snapshot rows examined in scope.',
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => 'Blog ID the revenue was read for.',
					),
					'window'  => array(
						'type'        => 'string',
						'description' => 'Human-readable window label.',
					),
					'cohort'  => array(
						'type'        => 'string',
						'enum'        => array( 'resolved', 'unresolved', 'all' ),
						'description' => 'Cohort filter applied.',
					),
					'sort_by' => array(
						'type'        => 'string',
						'description' => 'Sort key applied.',
					),
					'order'   => array(
						'type'        => 'string',
						'enum'        => array( 'desc', 'asc' ),
						'description' => 'Sort direction applied.',
					),
					'scope'   => array(
						'type'       => 'object',
						'properties' => array(
							'requested_period' => array(
								'type'        => 'string',
								'description' => 'Period the caller passed ("" = none).',
							),
							'effective_period' => array(
								'type'        => 'string',
								'description' => 'Period actually queried (the default when none requested).',
							),
							'effective_batch'  => array(
								'type'        => 'string',
								'description' => 'Import batch actually queried when the scope was defaulted.',
							),
							'defaulted'        => array(
								'type'        => 'boolean',
								'description' => 'True when the scope defaulted to the freshest dated period.',
							),
							'selected_periods' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Distinct period_labels present in the result rows.',
							),
							'selected_batches' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Distinct import_batches present in the result rows.',
							),
						),
						'required'   => array( 'requested_period', 'effective_period', 'effective_batch', 'defaulted', 'selected_periods', 'selected_batches' ),
					),
					'pages'   => array(
						'type'        => 'array',
						'description' => 'Ranked page rows.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'page_key'                 => array(
									'type'        => 'string',
									'description' => 'Dedupe key: "p"+post_id (resolved) or "u"+hash(slug) (unresolved).',
								),
								'cohort'                   => array(
									'type'        => 'string',
									'enum'        => array( 'resolved', 'unresolved' ),
									'description' => 'Which cohort this page belongs to.',
								),
								'post_id'                  => array(
									'type'        => 'integer',
									'description' => 'WordPress post ID (0 when unresolved).',
								),
								'title'                    => array(
									'type'        => 'string',
									'description' => 'Post title (resolved only, when include_post_meta).',
								),
								'url'                      => array(
									'type'        => 'string',
									'description' => 'Canonical URL or raw slug.',
								),
								'path'                     => array(
									'type'        => 'string',
									'description' => 'Site-relative path (resolved only).',
								),
								'categories'               => array(
									'type'        => 'array',
									'items'       => array( 'type' => 'string' ),
									'description' => 'Category slugs (resolved only; ["uncategorized"] when none assigned).',
								),
								'format'                   => array(
									'type'        => 'string',
									'description' => 'Content format (resolved only).',
								),
								'route_family'             => array(
									'type'        => 'string',
									'description' => 'Route family (unresolved only).',
								),
								'published_date'           => array(
									'type'        => 'string',
									'description' => 'Post publication date (resolved only).',
								),
								'views'                    => array(
									'type'        => 'integer',
									'description' => 'Pageviews (summed across collapsed variants).',
								),
								'revenue'                  => array(
									'type'        => 'number',
									'description' => 'Ad revenue in dollars (summed).',
								),
								'derived_rpm'              => array(
									'type'        => 'number',
									'description' => 'revenue / (views/1000), recomputed. 0.0 when views=0.',
								),
								'source_rpm'               => array(
									'type'        => 'number',
									'description' => 'Mediavine-reported rpm (simple-averaged across variants).',
								),
								'cpm'                      => array(
									'type'        => 'number',
									'description' => 'Source CPM (simple-averaged).',
								),
								'viewability'              => array(
									'type'        => 'number',
									'description' => 'Source viewability (simple-averaged).',
								),
								'fill_rate'                => array(
									'type'        => 'number',
									'description' => 'Source fill rate (simple-averaged).',
								),
								'impressions_per_pageview' => array(
									'type'        => 'number',
									'description' => 'Source impressions/pageview (simple-averaged).',
								),
								'dollars_per_page'         => array(
									'type'        => 'number',
									'description' => 'Revenue for this single page (= revenue at page granularity).',
								),
								'zero_views'               => array(
									'type'        => 'boolean',
									'description' => 'True when views=0 but revenue may be >0.',
								),
								'benchmark_opportunity'    => array(
									'type'        => 'boolean',
									'description' => 'True when high RPM at meaningful volume (defensible).',
								),
								'benchmark_score'          => array(
									'type'        => array( 'number', 'null' ),
									'description' => 'derived_rpm / cohort_median_rpm, or null when benchmark not computed.',
								),
							),
							'required'   => array( 'page_key', 'cohort', 'post_id', 'title', 'url', 'path', 'categories', 'format', 'route_family', 'published_date', 'views', 'revenue', 'derived_rpm', 'source_rpm', 'cpm', 'viewability', 'fill_rate', 'impressions_per_pageview', 'dollars_per_page', 'zero_views', 'benchmark_opportunity', 'benchmark_score' ),
						),
					),
					'totals'  => array(
						'type'       => 'object',
						'properties' => array(
							'pages_before_limit' => array(
								'type'        => 'integer',
								'description' => 'Distinct pages matching cohort+min_views BEFORE the limit (full cohort).',
							),
							'pages_returned'     => array(
								'type'        => 'integer',
								'description' => 'Pages returned after the limit.',
							),
							'cohort_pages'       => array(
								'type'        => 'integer',
								'description' => 'Distinct pages in the cohort (before min_views).',
							),
							'zero_views_pages'   => array(
								'type'        => 'integer',
								'description' => 'Pages in the full filtered cohort with zero views.',
							),
							'views'              => array(
								'type'        => 'integer',
								'description' => 'Sum of views across the full cohort (before limit).',
							),
							'revenue'            => array(
								'type'        => 'number',
								'description' => 'Sum of revenue across the full cohort (before limit).',
							),
						),
						'required'   => array( 'pages_before_limit', 'pages_returned', 'cohort_pages', 'zero_views_pages', 'views', 'revenue' ),
					),
					'sample'  => array(
						'type'       => 'object',
						'properties' => array(
							'rows_in_window'           => array(
								'type'        => 'integer',
								'description' => 'Raw snapshot rows in scope.',
							),
							'resolved_pages'           => array(
								'type'        => 'integer',
								'description' => 'Distinct resolved pages.',
							),
							'unresolved_pages'         => array(
								'type'        => 'integer',
								'description' => 'Distinct unresolved pages.',
							),
							'after_min_views'          => array(
								'type'        => 'integer',
								'description' => 'Distinct pages after the min_views floor.',
							),
							'truncated'                => array(
								'type'        => 'boolean',
								'description' => 'True when the limit cut the cohort.',
							),
							'benchmark_computed'       => array(
								'type'        => 'boolean',
								'description' => 'Whether benchmark was computed.',
							),
							'sufficient_for_benchmark' => array(
								'type'        => 'boolean',
								'description' => 'Whether the cohort met the benchmark sample floor.',
							),
						),
						'required'   => array( 'rows_in_window', 'resolved_pages', 'unresolved_pages', 'after_min_views', 'truncated', 'benchmark_computed', 'sufficient_for_benchmark' ),
					),
					'caveat'  => array(
						'type'        => 'string',
						'description' => 'Stable analyst caveat.',
					),
					'error'   => array(
						'type'        => 'string',
						'description' => 'Error message when success is false.',
					),
				),
				'required'   => array( 'success', 'rows', 'blog_id', 'window', 'cohort', 'sort_by', 'order', 'scope', 'pages', 'totals', 'sample', 'caveat' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_content_revenue_pages',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
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
 * Execute callback for get-content-revenue-pages ability.
 *
 * Owns the WordPress-dependent half: network/blog authorization, the default
 * window contract, switch_to_blog-scoped slug->post resolution + the
 * publish-status gate + term/format/path/metadata lookup. Hands one record per
 * resolved row to the pure builder, which owns dedupe, aggregation, metric
 * derivation, thresholding, sorting, and the benchmark computation.
 *
 * @param array $input Input parameters.
 * @return array Page-level response (consistent shape whether empty or not).
 */
function extrachill_analytics_ability_get_content_revenue_pages( $input ) {
	$requested_period  = isset( $input['period'] ) ? (string) $input['period'] : '';
	$period_start      = isset( $input['period_start'] ) ? (string) $input['period_start'] : '';
	$period_end        = isset( $input['period_end'] ) ? (string) $input['period_end'] : '';
	$import_batch      = isset( $input['import_batch'] ) ? (string) $input['import_batch'] : '';
	$blog_id           = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$hostname          = isset( $input['hostname'] ) ? trim( (string) $input['hostname'] ) : '';
	$cohort            = isset( $input['cohort'] ) ? (string) $input['cohort'] : 'resolved';
	$min_views         = isset( $input['min_views'] ) ? max( 0, (int) $input['min_views'] ) : 0;
	$sort_by           = isset( $input['sort_by'] ) ? (string) $input['sort_by'] : 'derived_rpm';
	$order             = isset( $input['order'] ) ? (string) $input['order'] : 'desc';
	$limit             = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 50;
	$include_post_meta = isset( $input['include_post_meta'] ) ? (bool) $input['include_post_meta'] : true;

	if ( ! in_array( $cohort, array( 'resolved', 'unresolved', 'all' ), true ) ) {
		$cohort = 'resolved';
	}
	if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
		$order = 'desc';
	}

	$valid_sorts = array(
		'views',
		'revenue',
		'derived_rpm',
		'source_rpm',
		'cpm',
		'viewability',
		'fill_rate',
		'impressions_per_pageview',
		'dollars_per_page',
		'benchmark_opportunity',
	);
	if ( ! in_array( $sort_by, $valid_sorts, true ) ) {
		$sort_by = 'derived_rpm';
	}

	// Network/blog authorization. permission_callback gates on the current
	// blog's manage_options; a CROSS-blog read additionally requires network
	// admin. WP_CLI is allowed (server-side operator).
	$auth = extrachill_analytics_revenue_authorize_blog_read( $blog_id );
	if ( true !== $auth ) {
		return array(
			'success' => false,
			'error'   => is_string( $auth ) ? $auth : 'Permission denied.',
			'rows'    => 0,
			'blog_id' => $blog_id,
			'window'  => '',
			'cohort'  => $cohort,
			'sort_by' => $sort_by,
			'order'   => $order,
			'scope'   => array(
				'requested_period' => $requested_period,
				'effective_period' => '',
				'effective_batch'  => '',
				'defaulted'        => false,
				'selected_periods' => array(),
				'selected_batches' => array(),
			),
			'pages'   => array(),
			'totals'  => array(
				'pages_before_limit' => 0,
				'pages_returned'     => 0,
				'cohort_pages'       => 0,
				'zero_views_pages'   => 0,
				'views'              => 0,
				'revenue'            => 0.0,
			),
			'sample'  => array(
				'rows_in_window'           => 0,
				'resolved_pages'           => 0,
				'unresolved_pages'         => 0,
				'after_min_views'          => 0,
				'truncated'                => false,
				'benchmark_computed'       => false,
				'sufficient_for_benchmark' => false,
			),
			'caveat'  => __( 'Permission denied.', 'extrachill-analytics' ),
		);
	}

	// Default window contract: with no explicit scope, default to the FRESHEST
	// DATED period so the query never silently combines all-time + monthly +
	// duplicate batches (double-counting). Explicit period/window/batch is used
	// as-is.
	$resolved_scope   = extrachill_analytics_revenue_resolve_default_period(
		$blog_id,
		$requested_period,
		$period_start,
		$period_end,
		$import_batch
	);
	$effective_period = $resolved_scope['effective_period'];
	$effective_batch  = $resolved_scope['effective_batch'];
	$defaulted        = $resolved_scope['defaulted'];

	$rows = extrachill_analytics_revenue_get_rows(
		array(
			'blog_id'               => $blog_id,
			'import_batch'          => $effective_batch,
			'restrict_import_batch' => $defaulted,
			'period_label'          => $effective_period,
			'period_start'          => $period_start,
			'period_end'            => $period_end,
		)
	);

	$selected_periods = array();
	$selected_batches = array();
	foreach ( $rows as $r ) {
		$selected_periods[ (string) $r->period_label ] = true;
		$selected_batches[ (string) $r->import_batch ] = true;
	}

	$scope_block = array(
		'requested_period' => $requested_period,
		'effective_period' => $effective_period,
		'effective_batch'  => $effective_batch,
		'defaulted'        => $defaulted,
		'selected_periods' => array_keys( $selected_periods ),
		'selected_batches' => array_keys( $selected_batches ),
	);

	$empty_totals = array(
		'pages_before_limit' => 0,
		'pages_returned'     => 0,
		'cohort_pages'       => 0,
		'zero_views_pages'   => 0,
		'views'              => 0,
		'revenue'            => 0.0,
	);
	$empty_sample = array(
		'rows_in_window'           => 0,
		'resolved_pages'           => 0,
		'unresolved_pages'         => 0,
		'after_min_views'          => 0,
		'truncated'                => false,
		'benchmark_computed'       => false,
		'sufficient_for_benchmark' => false,
	);

	if ( empty( $rows ) ) {
		return array(
			'success' => true,
			'rows'    => 0,
			'blog_id' => $blog_id,
			'window'  => extrachill_analytics_revenue_window_label( $period_start, $period_end, $effective_period ),
			'cohort'  => $cohort,
			'sort_by' => $sort_by,
			'order'   => $order,
			'scope'   => $scope_block,
			'pages'   => array(),
			'totals'  => $empty_totals,
			'sample'  => $empty_sample,
			'caveat'  => $defaulted
				? __( 'No revenue snapshots for this blog. Import a Mediavine Pages CSV first: wp extrachill analytics revenue import <csv>.', 'extrachill-analytics' )
				: __( 'No revenue snapshots match the requested scope for this blog. Check period/batch, or import: wp extrachill analytics revenue import <csv>.', 'extrachill-analytics' ),
		);
	}

	// switch_to_blog so slug->post resolution, publish-status, terms, permalink,
	// and format classification all run against the TARGET blog. A row stamped
	// for blog N must resolve against blog N's post table — without this, a
	// cross-blog read resolves every slug against the wrong posts.
	$records = extrachill_analytics_revenue_run_in_blog(
		$blog_id,
		static function () use ( $rows, $hostname, $include_post_meta ) {
			$records    = array();
			$post_cache = array();
			$hostname   = extrachill_analytics_revenue_resolution_hostname( $hostname );

			foreach ( $rows as $row ) {
				$post_id = (int) $row->post_id;
				if ( $post_id <= 0 && '' !== $row->slug ) {
					$post_id = extrachill_analytics_revenue_resolve_post_id( $row->url ? $row->url : $row->slug, $hostname );
				}

				$is_content = $post_id > 0 && 'publish' === get_post_status( $post_id );

				$title          = '';
				$url            = $row->url ? $row->url : $row->slug;
				$path           = '';
				$categories     = array();
				$format         = '';
				$published_date = '';
				$route_family   = '';

				if ( $is_content ) {
					if ( ! isset( $post_cache[ $post_id ] ) ) {
						$post = get_post( $post_id );
						if ( $post ) {
							$permalink              = get_permalink( $post_id );
							$categories_list        = get_the_terms( $post_id, 'category' );
							$post_cache[ $post_id ] = array(
								'title'          => $post->post_title,
								'url'            => is_string( $permalink ) ? $permalink : '',
								'path'           => is_string( $permalink ) ? wp_make_link_relative( $permalink ) : '',
								'published_date' => $post->post_date,
								'categories'     => ( is_array( $categories_list ) && ! empty( $categories_list ) )
								? wp_list_pluck( $categories_list, 'slug' )
								: array( 'uncategorized' ),
								'format'         => extrachill_analytics_classify_format( $post_id ),
							);
						} else {
							$post_cache[ $post_id ] = null;
						}
					}

					$cached = $post_cache[ $post_id ];
					if ( is_array( $cached ) ) {
						$title          = $include_post_meta ? $cached['title'] : '';
						$url            = $include_post_meta ? $cached['url'] : ( $row->url ? $row->url : $row->slug );
						$path           = $include_post_meta ? $cached['path'] : '';
						$published_date = $include_post_meta ? $cached['published_date'] : '';
						$categories     = $cached['categories'];
						$format         = $cached['format'];
					}
				} else {
					$route_family = extrachill_analytics_revenue_classify_route_family( $row->url ? $row->url : $row->slug );
				}

				$records[] = array(
					'page_key'                 => $is_content ? 'p' . $post_id : 'u' . md5( (string) $row->slug ),
					'is_content'               => $is_content,
					'post_id'                  => $is_content ? $post_id : 0,
					'format'                   => $format,
					'categories'               => $categories,
					'route_family'             => $route_family,
					'views'                    => (int) $row->views,
					'revenue'                  => (float) $row->revenue,
					'source_rpm'               => (float) $row->rpm,
					'cpm'                      => (float) $row->cpm,
					'viewability'              => (float) $row->viewability,
					'fill_rate'                => (float) $row->fill_rate,
					'impressions_per_pageview' => (float) $row->impressions_per_pageview,
					'url'                      => $url,
					'title'                    => $title,
					'path'                     => $path,
					'published_date'           => $published_date,
				);
			}

			return $records;
		}
	);

	$built = extrachill_analytics_revenue_build_pages(
		$records,
		array(
			'cohort'    => $cohort,
			'min_views' => $min_views,
			'sort_by'   => $sort_by,
			'order'     => $order,
			'limit'     => $limit,
		)
	);

	return array(
		'success' => true,
		'rows'    => count( $rows ),
		'blog_id' => $blog_id,
		'window'  => extrachill_analytics_revenue_window_label( $period_start, $period_end, $effective_period ),
		'cohort'  => $cohort,
		'sort_by' => $sort_by,
		'order'   => $order,
		'scope'   => $scope_block,
		'pages'   => $built['pages'],
		'totals'  => $built['totals'],
		'sample'  => $built['sample'],
		'caveat'  => __( 'Pages cover the requested cohort. derived_rpm = revenue / (views/1000), recomputed here; source_rpm is the Mediavine-reported rpm column — they can disagree and are kept distinct (rank on either). Pages with revenue but zero views are flagged zero_views with derived_rpm = 0 (never a division error) and revenue preserved. benchmark_opportunity flags genuinely strong RPM at meaningful volume (>= 1.5x cohort median RPM AND >= cohort median views; requires >= 5 views-positive pages). URL-variant rows of one post collapse by page_key: volume is summed, while rate metrics (source_rpm, cpm, viewability, fill_rate, impressions_per_pageview) are simple-averaged because their true impression/request denominators are not stored — derived_rpm stays the correct aggregated RPM. With no explicit scope the query defaults to the freshest dated period (never silently combines all-time + monthly + duplicates). Revenue is Mediavine-imported (the only source of ad income); never estimated.', 'extrachill-analytics' ),
	);
}

/**
 * Authorize a revenue read for a target blog.
 *
 * The current site's manage_options holder may read their OWN blog. A CROSS-blog
 * read additionally requires manage_network_options (network admin / super
 * admin). WP_CLI is allowed (server-side operator). This is the single authority
 * check for both revenue read abilities.
 *
 * @param int $blog_id Target blog ID.
 * @return true|string True when authorized, or an error message string.
 */
function extrachill_analytics_revenue_authorize_blog_read( $blog_id ) {
	if ( (int) $blog_id <= 0 || ( function_exists( 'get_site' ) && ! get_site( $blog_id ) ) ) {
		return __( 'Permission denied: target blog does not exist.', 'extrachill-analytics' );
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	$current = get_current_blog_id();
	if ( (int) $blog_id === $current ) {
		return current_user_can( 'manage_options' ) ? true : __( 'Permission denied: manage_options is required to read revenue.', 'extrachill-analytics' );
	}

	return current_user_can( 'manage_network_options' )
		? true
		: __( 'Permission denied: reading another blog\'s revenue requires manage_network_options.', 'extrachill-analytics' );
}

/**
 * Switch to the target blog unless it is already current.
 *
 * @param int $blog_id Target blog ID.
 * @return bool True if switched (caller must restore).
 */
function extrachill_analytics_revenue_maybe_switch_to_blog( $blog_id ) {
	if ( function_exists( 'switch_to_blog' ) && (int) $blog_id > 0 && get_current_blog_id() !== (int) $blog_id ) {
		return (bool) switch_to_blog( $blog_id );
	}
	return false;
}

/**
 * Restore the current blog only when a switch happened.
 *
 * @param bool $switched Whether switch_to_blog was called.
 */
function extrachill_analytics_revenue_maybe_restore_blog( $switched ) {
	if ( $switched && function_exists( 'restore_current_blog' ) ) {
		restore_current_blog();
	}
}

/**
 * Run a callback in the target blog and always restore the original context.
 *
 * @param int      $blog_id Target blog ID.
 * @param callable $callback Callback to run in the target blog.
 * @return mixed Callback result.
 */
function extrachill_analytics_revenue_run_in_blog( $blog_id, $callback ) {
	$switched = extrachill_analytics_revenue_maybe_switch_to_blog( $blog_id );

	try {
		return call_user_func( $callback );
	} finally {
		extrachill_analytics_revenue_maybe_restore_blog( $switched );
	}
}

/**
 * Get the hostname used to resolve a relative Mediavine path.
 *
 * This runs inside the target blog context. An explicit hostname remains an
 * operator override; otherwise the target blog's home URL supplies the host so
 * paths from a cross-blog read never resolve against the caller's site.
 *
 * @param string $hostname Optional explicit hostname.
 * @return string Hostname, including port when present.
 */
function extrachill_analytics_revenue_resolution_hostname( $hostname = '' ) {
	$hostname = trim( (string) $hostname );
	if ( '' !== $hostname ) {
		return $hostname;
	}

	$home = wp_parse_url( home_url( '/' ) );
	if ( empty( $home['host'] ) ) {
		return '';
	}

	return $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
}

/**
 * Resolve the default window scope for a revenue read.
 *
 * CONTRACT (issue #141 review): with no explicit period/window/batch, the query
 * must NOT silently combine all-time, monthly, and duplicate snapshots. So the
 * default is the FRESHEST DATED period_label (most recent by period_end). If
 * only the flat "all-time" bucket exists, it is used. If nothing exists, the
 * effective period is '' (empty result).
 *
 * @param int    $blog_id      Target blog.
 * @param string $period       Requested period_label.
 * @param string $period_start Requested window start.
 * @param string $period_end   Requested window end.
 * @param string $import_batch Requested batch.
 * @return array { effective_period, effective_batch, defaulted }
 */
function extrachill_analytics_revenue_resolve_default_period( $blog_id, $period, $period_start, $period_end, $import_batch ) {
	// Explicit scope is used as-is.
	if ( '' !== $period || ( '' !== $period_start && '' !== $period_end ) || '' !== $import_batch ) {
		return array(
			'effective_period' => $period,
			'effective_batch'  => $import_batch,
			'defaulted'        => false,
		);
	}

	$batches = extrachill_analytics_revenue_list_batches( $blog_id );

	return extrachill_analytics_revenue_select_default_scope( $batches );
}

/**
 * Select one freshest period/batch pair for an implicit page read.
 *
 * The batch list is ordered newest-first by the store reader, so equal period
 * boundaries retain the newest batch and cannot duplicate a snapshot.
 *
 * @param array $batches Period/batch aggregate rows, newest import first.
 * @return array { effective_period, effective_batch, defaulted }
 */
function extrachill_analytics_revenue_select_default_scope( array $batches ) {
	$best       = '';
	$best_batch = '';
	$best_end   = '';

	// Freshest DATED period by period_end (the data's own boundary, not the
	// import timestamp — see the freshness-check review note).
	foreach ( $batches as $b ) {
		$label = isset( $b->period_label ) ? (string) $b->period_label : '';
		if ( 'all-time' === $label || empty( $b->period_end ) ) {
			continue;
		}
		$end = (string) $b->period_end;
		if ( '' === $best_end || $end > $best_end ) {
			$best_end   = $end;
			$best       = $label;
			$best_batch = isset( $b->import_batch ) ? (string) $b->import_batch : '';
		}
	}

	if ( '' === $best ) {
		// Fall back to all-time if that's the only thing imported.
		foreach ( $batches as $b ) {
			if ( 'all-time' === ( isset( $b->period_label ) ? (string) $b->period_label : '' ) ) {
				$best       = 'all-time';
				$best_batch = isset( $b->import_batch ) ? (string) $b->import_batch : '';
				break;
			}
		}
	}

	return array(
		'effective_period' => $best,
		'effective_batch'  => $best_batch,
		'defaulted'        => '' !== $best,
	);
}

/**
 * Build the page-level lens from pre-classified revenue records.
 *
 * PURE — no WordPress / DB dependency — so dedupe, aggregation, metric
 * derivation, thresholding, sorting, and the benchmark computation are all
 * unit-testable in isolation. The execute_callback owns authorization,
 * resolution, window scope, and post-metadata; this function owns the
 * analytical contract.
 *
 * CONTRACT (issue #141):
 *   - One record per ROW is passed in; this function dedupes by page_key (the
 *     same 'p'.post_id / 'u'.hash(slug) convention as the rollup) so URL
 *     variants of one post collapse into one page row, SUMMING views/revenue.
 *   - Rate metrics (source_rpm, cpm, viewability, fill_rate,
 *     impressions_per_pageview) are simple-averaged across collapsed variants —
 *     NOT views-weighted — because their true denominators (impressions/
 *     requests) are not stored. derived_rpm is recomputed from the summed
 *     revenue/views and is the correct aggregated RPM.
 *   - Cohort filter (resolved/unresolved/all) and min_views threshold are
 *     applied AFTER aggregation (so a page's full volume counts toward the
 *     floor) and BEFORE sorting.
 *   - Totals (pages_before_limit, views, revenue, zero_views_pages) are computed
 *     over the full filtered cohort BEFORE the limit — they reflect the whole
 *     cohort, not just the truncated top-N.
 *   - Sorting is stable: the primary sort key is followed by a deterministic
 *     page_key tiebreaker so output is machine-stable across runs.
 *
 * @param array $records Pre-classified per-row records (see execute_callback).
 * @param array $args {
 *     Builder arguments.
 *
 *     @type string $cohort    'resolved' | 'unresolved' | 'all'.
 *     @type int    $min_views Minimum views floor.
 *     @type string $sort_by   Sort key.
 *     @type string $order     'desc' | 'asc'.
 *     @type int    $limit     Max pages (0 = unlimited).
 * }
 * @return array { pages, totals, sample }
 */
function extrachill_analytics_revenue_build_pages( array $records, array $args ) {
	$cohort    = isset( $args['cohort'] ) ? (string) $args['cohort'] : 'resolved';
	$min_views = isset( $args['min_views'] ) ? max( 0, (int) $args['min_views'] ) : 0;
	$sort_by   = isset( $args['sort_by'] ) ? (string) $args['sort_by'] : 'derived_rpm';
	$order     = isset( $args['order'] ) && 'asc' === $args['order'] ? 'asc' : 'desc';
	$limit     = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

	$resolved_pages   = 0;
	$unresolved_pages = 0;

	// 1. Aggregate by page_key, carrying metadata from the first variant.
	// Volume (views/revenue) is SUMMED; rate metrics collect their values for a
	// simple average at finalize (no invented denominators).
	$agg = array();
	foreach ( $records as $rec ) {
		$key = (string) ( isset( $rec['page_key'] ) ? $rec['page_key'] : '' );
		if ( '' === $key ) {
			continue;
		}

		$is_content = ! empty( $rec['is_content'] );

		if ( ! isset( $agg[ $key ] ) ) {
			if ( $is_content ) {
				++$resolved_pages;
			} else {
				++$unresolved_pages;
			}
			$agg[ $key ] = array(
				'page_key'           => $key,
				'is_content'         => $is_content,
				'post_id'            => (int) ( isset( $rec['post_id'] ) ? $rec['post_id'] : 0 ),
				'format'             => isset( $rec['format'] ) ? (string) $rec['format'] : '',
				'categories'         => isset( $rec['categories'] ) && is_array( $rec['categories'] ) ? array_values( $rec['categories'] ) : array(),
				'route_family'       => isset( $rec['route_family'] ) ? (string) $rec['route_family'] : '',
				'views'              => 0,
				'revenue'            => 0.0,
				'source_rpm_values'  => array(),
				'cpm_values'         => array(),
				'viewability_values' => array(),
				'fill_rate_values'   => array(),
				'impressions_values' => array(),
				'url'                => isset( $rec['url'] ) ? (string) $rec['url'] : '',
				'title'              => isset( $rec['title'] ) ? (string) $rec['title'] : '',
				'path'               => isset( $rec['path'] ) ? (string) $rec['path'] : '',
				'published_date'     => isset( $rec['published_date'] ) ? (string) $rec['published_date'] : '',
			);
		}

		$a             = &$agg[ $key ];
		$views         = (int) ( isset( $rec['views'] ) ? $rec['views'] : 0 );
		$a['views']   += $views;
		$a['revenue'] += (float) ( isset( $rec['revenue'] ) ? $rec['revenue'] : 0.0 );

		$a['source_rpm_values'][]  = (float) ( isset( $rec['source_rpm'] ) ? $rec['source_rpm'] : 0.0 );
		$a['cpm_values'][]         = (float) ( isset( $rec['cpm'] ) ? $rec['cpm'] : 0.0 );
		$a['viewability_values'][] = (float) ( isset( $rec['viewability'] ) ? $rec['viewability'] : 0.0 );
		$a['fill_rate_values'][]   = (float) ( isset( $rec['fill_rate'] ) ? $rec['fill_rate'] : 0.0 );
		$a['impressions_values'][] = (float) ( isset( $rec['impressions_per_pageview'] ) ? $rec['impressions_per_pageview'] : 0.0 );
		unset( $a );
	}

	$cohort_pages = 0;

	// 2. Finalize per-page derived metrics + cohort/min_views filtering.
	$pages = array();
	foreach ( $agg as $key => $a ) {
		$views   = (int) $a['views'];
		$revenue = (float) $a['revenue'];

		// Cohort filter.
		if ( 'resolved' === $cohort && ! $a['is_content'] ) {
			continue;
		}
		if ( 'unresolved' === $cohort && $a['is_content'] ) {
			continue;
		}
		++$cohort_pages;

		// min_views floor (applied AFTER aggregation so full volume counts).
		if ( $views < $min_views ) {
			continue;
		}

		// Rate metrics: simple average across collapsed variants (no invented
		// denominators — true impression/request weights are not stored).
		$source_rpm  = extrachill_analytics_revenue_simple_average( $a['source_rpm_values'] );
		$cpm         = extrachill_analytics_revenue_simple_average( $a['cpm_values'] );
		$viewability = extrachill_analytics_revenue_simple_average( $a['viewability_values'] );
		$fill_rate   = extrachill_analytics_revenue_simple_average( $a['fill_rate_values'] );
		$impressions = extrachill_analytics_revenue_simple_average( $a['impressions_values'] );

		$zero_views  = $views <= 0;
		$derived_rpm = $views > 0 ? round( $revenue / ( $views / 1000 ), 4 ) : 0.0;

		$pages[ $key ] = array(
			'page_key'                 => $key,
			'cohort'                   => $a['is_content'] ? 'resolved' : 'unresolved',
			'post_id'                  => $a['post_id'],
			'title'                    => $a['title'],
			'url'                      => $a['url'],
			'path'                     => $a['path'],
			'categories'               => $a['categories'],
			'format'                   => $a['is_content'] ? $a['format'] : '',
			'route_family'             => $a['is_content'] ? '' : $a['route_family'],
			'published_date'           => $a['published_date'],
			'views'                    => $views,
			'revenue'                  => round( $revenue, 4 ),
			'derived_rpm'              => $derived_rpm,
			'source_rpm'               => round( $source_rpm, 4 ),
			'cpm'                      => round( $cpm, 4 ),
			'viewability'              => round( $viewability, 4 ),
			'fill_rate'                => round( $fill_rate, 4 ),
			'impressions_per_pageview' => round( $impressions, 4 ),
			'dollars_per_page'         => round( $revenue, 4 ),
			'zero_views'               => $zero_views,
			'benchmark_opportunity'    => false,
			'benchmark_score'          => null,
		);
	}

	// 3. Benchmark opportunity — computed over the in-cohort, post-threshold
	// pages with views > 0. Conservative: requires >= 5 views-positive pages.
	$benchmark_computed       = false;
	$sufficient_for_benchmark = false;
	$views_positive           = array();
	foreach ( $pages as $p ) {
		if ( $p['views'] > 0 ) {
			$views_positive[] = $p;
		}
	}

	if ( count( $views_positive ) >= 5 ) {
		$sufficient_for_benchmark = true;
		$benchmark_computed       = true;
		$rpm_values               = array_map(
			static function ( $p ) {
				return $p['derived_rpm'];
			},
			$views_positive
		);
		$views_values             = array_map(
			static function ( $p ) {
				return $p['views'];
			},
			$views_positive
		);
		$median_rpm               = extrachill_analytics_revenue_median( $rpm_values );
		$median_views             = extrachill_analytics_revenue_median( $views_values );

		foreach ( $pages as $key => $p ) {
			if ( $p['views'] <= 0 || $median_rpm <= 0 ) {
				$pages[ $key ]['benchmark_score'] = $p['views'] > 0 ? 0.0 : null;
				continue;
			}
			$score                                  = round( $p['derived_rpm'] / $median_rpm, 4 );
			$pages[ $key ]['benchmark_score']       = $score;
			$pages[ $key ]['benchmark_opportunity'] = ( $score >= 1.5 && $p['views'] >= $median_views );
		}
	}

	// 4. Compute cohort totals BEFORE the limit — totals reflect the whole
	// matching cohort, not just the truncated top-N.
	$cohort_views       = 0;
	$cohort_revenue     = 0.0;
	$cohort_zero_views  = 0;
	$pages_before_limit = count( $pages );
	foreach ( $pages as $p ) {
		$cohort_views   += $p['views'];
		$cohort_revenue += $p['revenue'];
		if ( ! empty( $p['zero_views'] ) ) {
			++$cohort_zero_views;
		}
	}

	// 5. Stable sort: primary key then page_key tiebreaker.
	$pages = extrachill_analytics_revenue_sort_pages( array_values( $pages ), $sort_by, $order );

	// 6. Limit.
	$truncated = false;
	if ( $limit > 0 && count( $pages ) > $limit ) {
		$pages     = array_slice( $pages, 0, $limit );
		$truncated = true;
	}

	return array(
		'pages'  => $pages,
		'totals' => array(
			'pages_before_limit' => $pages_before_limit,
			'pages_returned'     => count( $pages ),
			'cohort_pages'       => $cohort_pages,
			'zero_views_pages'   => $cohort_zero_views,
			'views'              => $cohort_views,
			'revenue'            => round( $cohort_revenue, 4 ),
		),
		'sample' => array(
			'rows_in_window'           => count( $records ),
			'resolved_pages'           => $resolved_pages,
			'unresolved_pages'         => $unresolved_pages,
			'after_min_views'          => $pages_before_limit,
			'truncated'                => $truncated,
			'benchmark_computed'       => $benchmark_computed,
			'sufficient_for_benchmark' => $sufficient_for_benchmark,
		),
	);
}

/**
 * Simple (unweighted) average of a numeric list.
 *
 * Used for source rate metrics across collapsed URL variants: their true
 * denominators (impressions/requests) are not stored, so views-weighting would
 * invent a denominator. The single-variant case (the common path) passes the
 * value through unchanged.
 *
 * @param array $values Numeric values.
 * @return float
 */
function extrachill_analytics_revenue_simple_average( array $values ) {
	$n = count( $values );
	if ( 0 === $n ) {
		return 0.0;
	}
	$sum = 0.0;
	foreach ( $values as $v ) {
		$sum += (float) $v;
	}
	return $sum / $n;
}

/**
 * Sort a pages array by the requested key with a deterministic page_key tiebreaker.
 *
 * Stable: ties on the primary key are broken by page_key (ascending) so two
 * runs over the same input produce identical output — the contract a
 * machine-readable lens needs.
 *
 * @param array  $pages   Page rows.
 * @param string $sort_by Sort key.
 * @param string $order   'desc' | 'asc'.
 * @return array Sorted pages.
 */
function extrachill_analytics_revenue_sort_pages( array $pages, $sort_by, $order ) {
	$desc = 'asc' !== $order;

	usort(
		$pages,
		static function ( $a, $b ) use ( $sort_by, $desc ) {
			$av = extrachill_analytics_revenue_sort_value( $a, $sort_by );
			$bv = extrachill_analytics_revenue_sort_value( $b, $sort_by );

			// Primary key. Nulls sort last regardless of direction.
			if ( null === $av && null !== $bv ) {
				return 1;
			}
			if ( null === $bv && null !== $av ) {
				return -1;
			}
			if ( null === $av && null === $bv ) {
				$cmp = 0;
			} else {
				$cmp = ( $av <=> $bv );
			}
			if ( 0 !== $cmp ) {
				return $desc ? -$cmp : $cmp;
			}

			// Deterministic tiebreaker on page_key (always ascending).
			return strcmp( (string) $a['page_key'], (string) $b['page_key'] );
		}
	);

	return $pages;
}

/**
 * Resolve a sort key to the comparable scalar on a page row.
 *
 * The benchmark_opportunity sort maps to a composite value so the flag wins
 * and the score orders within. Null scores sort last.
 *
 * @param array  $page    Page row.
 * @param string $sort_by Sort key.
 * @return float|int|null Comparable value.
 */
function extrachill_analytics_revenue_sort_value( array $page, $sort_by ) {
	switch ( $sort_by ) {
		case 'views':
			return (int) $page['views'];
		case 'revenue':
		case 'dollars_per_page':
			return (float) $page['revenue'];
		case 'derived_rpm':
			return (float) $page['derived_rpm'];
		case 'source_rpm':
			return (float) $page['source_rpm'];
		case 'cpm':
			return (float) $page['cpm'];
		case 'viewability':
			return (float) $page['viewability'];
		case 'fill_rate':
			return (float) $page['fill_rate'];
		case 'impressions_per_pageview':
			return (float) $page['impressions_per_pageview'];
		case 'benchmark_opportunity':
			if ( null === $page['benchmark_score'] ) {
				return null;
			}
			return ( ! empty( $page['benchmark_opportunity'] ) ? 1000000 : 0 ) + (float) $page['benchmark_score'];
		default:
			return (float) $page['derived_rpm'];
	}
}

/**
 * Median of a numeric list (averages the two middle values on even counts).
 *
 * @param array $values Numeric values.
 * @return float
 */
function extrachill_analytics_revenue_median( array $values ) {
	$values = array_values(
		array_filter(
			$values,
			static function ( $v ) {
				return null !== $v;
			}
		)
	);
	$n      = count( $values );
	if ( 0 === $n ) {
		return 0.0;
	}
	sort( $values, SORT_NUMERIC );
	if ( 1 === $n % 2 ) {
		return (float) $values[ (int) floor( $n / 2 ) ];
	}
	$lo = (float) $values[ ( $n / 2 ) - 1 ];
	$hi = (float) $values[ $n / 2 ];
	return ( $lo + $hi ) / 2.0;
}
