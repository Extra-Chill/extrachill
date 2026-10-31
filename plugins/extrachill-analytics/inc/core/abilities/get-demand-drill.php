<?php
/**
 * Get Demand Drill Ability
 *
 * Demand-decline ATTRIBUTION instrument. The sibling `get-surface-growth`
 * ability reports a single aggregate demand slope per surface (e.g. events
 * organic demand -29.65%/wk), but when that slope is declining there is no
 * deterministic way to answer the only question that matters next: *which
 * specific pages and queries are dragging it down?* Without that, a declining
 * surface slope is an open question, not an action list — and the orchestrator
 * defaults to the wrong lever (e.g. "add more scrapers") because it can't see
 * whether the decline is broad or concentrated in a few rank-losing pages.
 *
 * This ability drills the slope down to its contributors. Given a surface (or
 * an explicit host) and a window, it pulls Google Search Console per-PAGE and
 * per-QUERY stats for the current window AND the immediately-preceding equal
 * window, joins them by page/query, and ranks the contributors by NET CLICK
 * CHANGE (current-window clicks minus prior-window clicks). It returns BOTH the
 * top decliners (pages/queries that lost the most clicks) AND the top risers,
 * each carrying its CURRENT and PRIOR average position so a rank-loss is
 * visible — the reader can tell at a glance whether a page bled clicks because
 * it lost rank (rank-recovery / gsc-opportunities is the lever) or because the
 * query demand itself softened (a content or crosslink problem).
 *
 * GSC SOURCING (reused, not rebuilt): all GSC transport, auth, and the
 * page_stats / query_stats actions live in the primitive
 * `datamachine/google-search-console` ability (Data Machine Business) — the
 * SAME demand basis `get-surface-growth` and `gsc-opportunity` already lean on.
 * This ability owns NO API auth and NO parallel GSC client; it consumes that
 * primitive twice (current + prior window) and does the join/rank in PHP. The
 * Extra Chill GSC property is a domain property (sc-domain:extrachill.com) that
 * spans every subdomain, so a surface drill is scoped to its host by passing
 * the host as GSC's `url_filter` (a `page`-contains filter), exactly the
 * mechanism gsc-opportunity uses for URL scoping.
 *
 * COVERAGE GAPS, not zeros: when GSC is unavailable/unconfigured, or a window
 * returns no rows, the affected dimension degrades to a not_instrumented marker
 * (reusing get-surface-growth's helper) rather than reporting a misleading 0.
 *
 * SCOPE: this is the ability only. The thin `wp extrachill analytics
 * demand-drill` CLI wrapper belongs in extrachill-cli per the canonical-home
 * rule and is a separate change.
 *
 * @package ExtraChill\Analytics
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-demand-drill ability.
 */
function extrachill_analytics_register_demand_drill_ability() {
	wp_register_ability(
		'extrachill/get-demand-drill',
		array(
			'label'               => __( 'Get Demand Drill', 'extrachill-analytics' ),
			'description'         => __( 'Attribute a surface\'s declining demand slope to the specific pages and queries dragging it. BASIS: Google Search Console CLICKS (~2-3 day lagged), netted as current-window-minus-prior-equal-window. Pulls GSC per-page and per-query stats for the current window and the immediately-preceding equal window, joins them, and returns the top decliners and risers ranked by net click change, each with current AND prior average position so a rank-loss is visible. This is a DIFFERENT lens from the get-surface-growth ability, whose demand figure is a GA4 organic-SESSIONS weekly slope — so the two can legitimately disagree in sign without either being wrong (GSC clicks vs GA sessions, net-delta vs slope, GSC-lagged window vs GA window). For the GA organic-sessions weekly slope, see extrachill/get-surface-growth. Reuses the datamachine/google-search-console primitive (no parallel GSC client). Unmeasurable dimensions return a not_instrumented coverage marker, never a zero.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'surface'      => array(
						'type'        => 'string',
						'description' => __( 'Surface key to drill (events, wire, community, artist, blog). Resolves to the surface host from the canonical surface map and scopes the GSC pull to that host. Omit when passing an explicit host/url_filter.', 'extrachill-analytics' ),
					),
					'host'         => array(
						'type'        => 'string',
						'description' => __( 'Explicit host to scope the drill to (e.g. events.extrachill.com). Used as the GSC url_filter. Overrides the surface-derived host when both are given.', 'extrachill-analytics' ),
					),
					'weeks'        => array(
						'type'        => 'integer',
						'description' => __( 'Number of weeks the current window spans. Default 4. The prior window is the immediately-preceding window of equal length.', 'extrachill-analytics' ),
						'default'     => 4,
					),
					'dimension'    => array(
						'type'        => 'string',
						'description' => __( 'Which contributor dimension(s) to return: "page", "query", or "both" (default).', 'extrachill-analytics' ),
						'default'     => 'both',
					),
					'limit'        => array(
						'type'        => 'integer',
						'description' => __( 'Max decliners and max risers to return per dimension (default 25).', 'extrachill-analytics' ),
						'default'     => 25,
					),
					'min_clicks'   => array(
						'type'        => 'integer',
						'description' => __( 'A page/query must have at least this many clicks in EITHER window to be considered a contributor (default 1). Filters out single-impression noise.', 'extrachill-analytics' ),
						'default'     => 1,
					),
					'site_url'     => array(
						'type'        => 'string',
						'description' => __( 'GSC property URL (sc-domain: or https://). Defaults to the configured property. Passed through to datamachine/google-search-console.', 'extrachill-analytics' ),
					),
					'query_filter' => array(
						'type'        => 'string',
						'description' => __( 'Restrict the drill to queries containing this string (passed through to GSC).', 'extrachill-analytics' ),
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with per-dimension (page, query) decliners and risers ranked by net click change, each carrying current/prior clicks and current/prior position, plus the surface/host and both UTC date windows.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_demand_drill',
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
 * Execute callback for get-demand-drill ability.
 *
 * @param array $input Input parameters.
 * @return array Demand-drill attribution read.
 */
function extrachill_analytics_ability_get_demand_drill( $input ) {
	$weeks      = isset( $input['weeks'] ) ? max( 1, (int) $input['weeks'] ) : 4;
	$days       = $weeks * 7;
	$dimension  = isset( $input['dimension'] ) ? strtolower( (string) $input['dimension'] ) : 'both';
	$limit      = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 25;
	$min_clicks = isset( $input['min_clicks'] ) ? max( 0, (int) $input['min_clicks'] ) : 1;

	if ( ! in_array( $dimension, array( 'page', 'query', 'both' ), true ) ) {
		$dimension = 'both';
	}

	// Resolve the host to scope the drill to. An explicit host wins; otherwise
	// resolve it from the surface map (single source of truth shared with
	// get-surface-growth). A drill with no host/surface still runs against the
	// whole property — useful, but we flag the resolution so the caller knows.
	$host         = isset( $input['host'] ) ? trim( (string) $input['host'] ) : '';
	$surface_key  = isset( $input['surface'] ) ? trim( (string) $input['surface'] ) : '';
	$surface_meta = null;

	if ( '' === $host && '' !== $surface_key && function_exists( 'extrachill_analytics_surface_growth_map' ) ) {
		$map = extrachill_analytics_surface_growth_map();
		if ( isset( $map[ $surface_key ] ) ) {
			$surface_meta = $map[ $surface_key ];
			$host         = (string) $surface_meta['host'];
		}
	}

	// GSC date windows. The primitive defaults its end to 3 days ago for
	// finalized data; we mirror that so both windows use settled data and the
	// comparison is like-for-like. Current window = [cur_start, cur_end]; prior
	// window = the equal-length span immediately before it.
	$cur_end     = gmdate( 'Y-m-d', (int) strtotime( '-3 days' ) );
	$cur_start   = gmdate( 'Y-m-d', (int) strtotime( $cur_end . ' -' . ( $days - 1 ) . ' days' ) );
	$prior_end   = gmdate( 'Y-m-d', (int) strtotime( $cur_start . ' -1 day' ) );
	$prior_start = gmdate( 'Y-m-d', (int) strtotime( $prior_end . ' -' . ( $days - 1 ) . ' days' ) );

	// Resolve the GSC primitive once. Its absence is a coverage gap for every
	// dimension, never a zeroed-out result.
	$gsc           = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/google-search-console' ) : null;
	$gsc_available = ( $gsc instanceof WP_Ability );

	$pass_through = array();
	if ( ! empty( $input['site_url'] ) ) {
		$pass_through['site_url'] = sanitize_text_field( (string) $input['site_url'] );
	}
	if ( ! empty( $input['query_filter'] ) ) {
		$pass_through['query_filter'] = sanitize_text_field( (string) $input['query_filter'] );
	}
	// Scope to the surface host via the GSC page-contains filter. The Extra
	// Chill property is a domain property spanning all subdomains, so url_filter
	// is how a single-host surface is isolated.
	if ( '' !== $host ) {
		$pass_through['url_filter'] = $host;
	}

	$dimensions = array();
	if ( 'page' === $dimension || 'both' === $dimension ) {
		$dimensions['page'] = 'page_stats';
	}
	if ( 'query' === $dimension || 'both' === $dimension ) {
		$dimensions['query'] = 'query_stats';
	}

	$result_dims = array();
	foreach ( $dimensions as $kind => $action ) {
		$result_dims[ $kind ] = extrachill_analytics_demand_drill_dimension(
			$gsc,
			$gsc_available,
			$action,
			$cur_start,
			$cur_end,
			$prior_start,
			$prior_end,
			$limit,
			$min_clicks,
			$pass_through
		);
	}

	return array(
		'surface'        => '' !== $surface_key ? $surface_key : null,
		'host'           => '' !== $host ? $host : null,
		'label'          => null !== $surface_meta ? $surface_meta['label'] : null,
		'dimension'      => $dimension,
		'weeks'          => $weeks,
		'days'           => $days,
		'current_window' => array(
			'start' => $cur_start,
			'end'   => $cur_end,
		),
		'prior_window'   => array(
			'start' => $prior_start,
			'end'   => $prior_end,
		),
		'page'           => isset( $result_dims['page'] ) ? $result_dims['page'] : null,
		'query'          => isset( $result_dims['query'] ) ? $result_dims['query'] : null,
		'gsc_available'  => $gsc_available,
		'host_resolved'  => ( '' !== $host ),
		'as_of'          => gmdate( 'Y-m-d H:i:s' ),
		'note'           => 'Contributors to a surface demand slope, ranked by NET CLICK CHANGE (current-window clicks minus prior equal-window clicks) from Google Search Console page_stats/query_stats — BASIS: GSC CLICKS, ~2-3 day lagged. Decliners are the pages/queries that lost the most clicks; risers gained the most. Each carries current AND prior average position: a decliner whose position rose (worsened) lost rank (rank-recovery lever); a decliner whose position held but clicks fell lost query demand (content/crosslink lever). Scoped to the surface host via the GSC url_filter. NOTE ON "DEMAND" vs the surface-growth instrument: this net-click figure is GSC CLICKS over a GSC-lagged window; the extrachill/get-surface-growth demand figure is a GA4 organic-SESSIONS weekly slope. They are different lenses (clicks vs sessions, net-delta vs slope, GSC-lagged window vs GA window) and can disagree in sign without either being wrong — do not read a sign mismatch between them as a regression. For the GA organic-sessions weekly slope, see extrachill/get-surface-growth. Unmeasurable dimensions return a not_instrumented marker (coverage gap), never a zero.',
	);
}

/**
 * Drill one GSC dimension (page or query) into ranked decliners + risers.
 *
 * Pulls the dimension's stats for the current and prior windows from the
 * primitive GSC ability, joins rows by their dimension key, computes the net
 * click change per key, and returns the top decliners and risers along with
 * each key's current/prior clicks and current/prior position. Any GSC failure
 * (absent ability, unconfigured, error, both windows empty) degrades to a
 * not_instrumented marker rather than a misleading zero.
 *
 * @param WP_Ability|null $gsc           Resolved GSC ability, or null.
 * @param bool            $gsc_available Whether the GSC ability resolved.
 * @param string          $action        GSC action (page_stats|query_stats).
 * @param string          $cur_start     Current window start (YYYY-MM-DD).
 * @param string          $cur_end       Current window end (YYYY-MM-DD).
 * @param string          $prior_start   Prior window start (YYYY-MM-DD).
 * @param string          $prior_end     Prior window end (YYYY-MM-DD).
 * @param int             $limit         Max decliners/risers to return.
 * @param int             $min_clicks    Minimum clicks (either window) to qualify.
 * @param array           $pass_through  Extra GSC inputs (site_url, url_filter, query_filter).
 * @return array Ranked decliners/risers, or a not_instrumented marker.
 */
function extrachill_analytics_demand_drill_dimension(
	$gsc,
	$gsc_available,
	$action,
	$cur_start,
	$cur_end,
	$prior_start,
	$prior_end,
	$limit,
	$min_clicks,
	$pass_through
) {
	if ( ! $gsc_available ) {
		return extrachill_analytics_not_instrumented( 'Google Search Console ability (datamachine/google-search-console) is not available on this install.' );
	}

	$current = extrachill_analytics_demand_drill_fetch( $gsc, $action, $cur_start, $cur_end, $pass_through );
	if ( is_wp_error( $current ) ) {
		return extrachill_analytics_not_instrumented( 'GSC ' . $action . ' failed for current window: ' . $current->get_error_message() );
	}

	$prior = extrachill_analytics_demand_drill_fetch( $gsc, $action, $prior_start, $prior_end, $pass_through );
	if ( is_wp_error( $prior ) ) {
		return extrachill_analytics_not_instrumented( 'GSC ' . $action . ' failed for prior window: ' . $prior->get_error_message() );
	}

	if ( empty( $current ) && empty( $prior ) ) {
		return extrachill_analytics_not_instrumented( 'GSC returned no rows for either window (no indexed demand for this scope).' );
	}

	// Union of all keys seen in either window. A key present in only one window
	// has a 0 click/impression basis for the missing side — that 0 is REAL
	// (measured: no demand that window), distinct from the dimension-level
	// not_instrumented gap above.
	$keys = array_unique( array_merge( array_keys( $current ), array_keys( $prior ) ) );

	$rows = array();
	foreach ( $keys as $key ) {
		$cur = isset( $current[ $key ] ) ? $current[ $key ] : null;
		$pri = isset( $prior[ $key ] ) ? $prior[ $key ] : null;

		$clicks_current = null !== $cur ? (int) $cur['clicks'] : 0;
		$clicks_prior   = null !== $pri ? (int) $pri['clicks'] : 0;

		// Qualify on clicks in EITHER window so a page that bled to zero is kept.
		if ( $clicks_current < $min_clicks && $clicks_prior < $min_clicks ) {
			continue;
		}

		$net = $clicks_current - $clicks_prior;

		$position_current = null !== $cur ? round( (float) $cur['position'], 1 ) : null;
		$position_prior   = null !== $pri ? round( (float) $pri['position'], 1 ) : null;
		// Position change: positive means the average position got WORSE
		// (numerically larger = lower on the SERP). Only defined when both
		// windows have the key.
		$position_change = ( null !== $position_current && null !== $position_prior )
			? round( $position_current - $position_prior, 1 )
			: null;

		$rows[] = array(
			'target'              => $key,
			'clicks_current'      => $clicks_current,
			'clicks_prior'        => $clicks_prior,
			'net_click_change'    => $net,
			'impressions_current' => null !== $cur ? (int) $cur['impressions'] : 0,
			'impressions_prior'   => null !== $pri ? (int) $pri['impressions'] : 0,
			'position_current'    => $position_current,
			'position_prior'      => $position_prior,
			'position_change'     => $position_change,
		);
	}

	// Decliners: most-negative net click change first. Risers: most-positive
	// first. A row with net 0 is neither, so it never pollutes a ranked list.
	$decliners = array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				return $row['net_click_change'] < 0;
			}
		)
	);
	$risers    = array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				return $row['net_click_change'] > 0;
			}
		)
	);

	usort(
		$decliners,
		static function ( $a, $b ) {
			return $a['net_click_change'] <=> $b['net_click_change'];
		}
	);
	usort(
		$risers,
		static function ( $a, $b ) {
			return $b['net_click_change'] <=> $a['net_click_change'];
		}
	);

	$total_net = 0;
	foreach ( $rows as $row ) {
		$total_net += $row['net_click_change'];
	}

	return array(
		'measured'        => true,
		'contributors'    => count( $rows ),
		'net_click_total' => $total_net,
		'decliners'       => array_slice( $decliners, 0, $limit ),
		'risers'          => array_slice( $risers, 0, $limit ),
		'decliner_count'  => count( $decliners ),
		'riser_count'     => count( $risers ),
	);
}

/**
 * Fetch a GSC dimension window and index its rows by dimension key.
 *
 * Calls the primitive datamachine/google-search-console ability for the given
 * action/window, requesting enough rows (MAX_LIMIT) to capture the full
 * contributor set, then folds the raw rows into a key => stats map. GSC
 * page_stats / query_stats are single-dimension, so each row's first `keys`
 * entry is the page URL or query string.
 *
 * @param WP_Ability $gsc          GSC ability instance.
 * @param string     $action       GSC action (page_stats|query_stats).
 * @param string     $start        Window start (YYYY-MM-DD).
 * @param string     $end          Window end (YYYY-MM-DD).
 * @param array      $pass_through Extra GSC inputs (site_url, url_filter, query_filter).
 * @return array<string,array{clicks:int,impressions:int,position:float}>|WP_Error
 */
function extrachill_analytics_demand_drill_fetch( $gsc, $action, $start, $end, $pass_through ) {
	// Request a high row limit so the contributor set isn't truncated. The GSC
	// primitive clamps to its own MAX_LIMIT (25000); a single-dimension window
	// for one host stays well under that.
	$gsc_input = array_merge(
		$pass_through,
		array(
			'action'     => $action,
			'start_date' => $start,
			'end_date'   => $end,
			'limit'      => 25000,
		)
	);

	$result = $gsc->execute( $gsc_input );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		$msg = is_array( $result ) && ! empty( $result['error'] ) ? $result['error'] : 'GSC returned no successful result.';
		return new WP_Error( 'demand_drill_gsc_failed', $msg );
	}

	$indexed = array();
	foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
		$keys = (array) ( $row['keys'] ?? array() );
		if ( empty( $keys ) ) {
			continue;
		}

		$key = (string) $keys[0];
		if ( '' === $key ) {
			continue;
		}

		$indexed[ $key ] = array(
			'clicks'      => (int) ( $row['clicks'] ?? 0 ),
			'impressions' => (int) ( $row['impressions'] ?? 0 ),
			'position'    => (float) ( $row['position'] ?? 0.0 ),
		);
	}

	return $indexed;
}
