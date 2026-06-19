<?php
/**
 * Get Surface Growth Ability
 *
 * Cross-surface growth-RATE instrument. Answers the recurring, previously
 * un-measured question: *which Extra Chill surface is growing fastest?*
 *
 * The Agent Ping can only read per-surface LEVELS (89k events, 3,202 sessions,
 * 3,935 wire posts, 622 users) and we have historically eyeballed growth from
 * two noisy points ("+474 vs last session"). Worse, each surface uses
 * incompatible units (events vs posts vs profiles vs users vs sessions), so the
 * deltas are un-rankable. This ability turns "the calendar is our fastest-
 * growing surface" from a vibe into a measured, ranked fact.
 *
 * TWO DISTINCT DIMENSIONS, kept separate on purpose:
 *
 *   SUPPLY (inventory growth) — how fast the surface is producing new content:
 *   new events / posts / profiles per week. Deterministic: a plain COUNT(*) of
 *   each surface's published post type over the window, derived directly from
 *   that blog's posts table (no new time-series table — the post_date column IS
 *   the time series). This is "are WE making more?".
 *
 *   DEMAND (audience growth) — how fast real human traffic to the surface is
 *   growing: the organic-sessions slope per host over the window, sourced from
 *   the data-machine `datamachine/google-analytics` ability. We deliberately
 *   lean on ORGANIC sessions (current vs previous equal-length period) rather
 *   than the bot-heavy Direct floor. This is "are MORE PEOPLE coming?".
 *
 * Growing inventory != growing audience. A surface can pump out posts while its
 * audience flatlines, or hold steady inventory while traffic climbs. Collapsing
 * the two would hide exactly the signal we are trying to surface, so they are
 * reported as separate, separately-ranked figures.
 *
 * NORMALIZATION (so different base units become comparable on one axis):
 *   - SUPPLY is normalized to BOTH new-items-per-week AND a percent-growth over
 *     the window (new_in_window / prior_total). The percent figure is the
 *     unit-free axis that lets events, posts, and profiles be ranked together.
 *   - DEMAND is normalized to a percent change in organic sessions (current
 *     period vs previous equal-length period), already unit-free.
 *   Each normalized figure carries its raw numbers so a caller can reproduce it.
 *
 * COVERAGE GAPS, not zeros: where a dimension cannot be measured (GA ability
 * absent / unconfigured, host has no data, a surface has no countable post
 * type), the figure is returned as a `not_instrumented` marker, NEVER a 0. A 0
 * means "measured and flat"; not_instrumented means "we cannot see this yet".
 * Conflating the two would let a blind spot masquerade as stagnation.
 *
 * SCOPE: this is the ability only. The thin `wp extrachill analytics growth`
 * CLI wrapper belongs in extrachill-cli per the canonical-home rule and is a
 * follow-up, not part of this file.
 *
 * @package ExtraChill\Analytics
 * @since 0.12.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-surface-growth ability.
 */
function extrachill_analytics_register_surface_growth_ability() {
	wp_register_ability(
		'extrachill/get-surface-growth',
		array(
			'label'               => __( 'Get Surface Growth', 'extrachill-analytics' ),
			'description'         => __( 'Returns a normalized, cross-surface growth-rate read (supply: inventory growth per week; demand: organic-sessions slope) for each live Extra Chill surface, plus a ranked fastest-growing surface. Supply and demand are reported separately. Unmeasurable dimensions return a not_instrumented coverage marker, never a zero.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'weeks' => array(
						'type'        => 'integer',
						'description' => __( 'Number of weeks the growth window spans. Default 4. The demand slope compares this window against the immediately-preceding window of equal length.', 'extrachill-analytics' ),
						'default'     => 4,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with per-surface supply + demand growth, a normalized comparable figure per surface, and a ranked fastest-growing surface, plus the exact UTC window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_surface_growth',
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
 * Live-surface definition map.
 *
 * Each surface declares the blog it lives on, the published post type whose
 * COUNT(*) over post_date is its supply signal, and the GA host whose organic
 * sessions are its demand signal. Blog IDs / host names resolve from the
 * canonical extrachill-multisite map when available (single source of truth),
 * with hardcoded production fallbacks so the read degrades rather than fatals
 * on a network where the helper hasn't loaded.
 *
 * @return array<string,array{label:string,blog_id:int,post_type:string,host:string,supply_unit:string}>
 */
function extrachill_analytics_surface_growth_map() {
	$blog = function ( $key, $fallback ) {
		return function_exists( 'ec_get_blog_id' ) && null !== ec_get_blog_id( $key )
			? (int) ec_get_blog_id( $key )
			: (int) $fallback;
	};

	return array(
		'events'    => array(
			'label'       => 'Events Calendar',
			'blog_id'     => $blog( 'events', 7 ),
			'post_type'   => 'data_machine_events',
			'host'        => 'events.extrachill.com',
			'supply_unit' => 'events',
		),
		'wire'      => array(
			'label'       => 'Festival Wire',
			'blog_id'     => $blog( 'wire', 11 ),
			'post_type'   => 'festival_wire',
			'host'        => 'wire.extrachill.com',
			'supply_unit' => 'posts',
		),
		'community' => array(
			// UGC growth is the community's supply: new topics per week is the
			// closest deterministic proxy for "the forum is producing more".
			'label'       => 'Community',
			'blog_id'     => $blog( 'community', 2 ),
			'post_type'   => 'topic',
			'host'        => 'community.extrachill.com',
			'supply_unit' => 'topics',
		),
		'artist'    => array(
			'label'       => 'Artist Platform',
			'blog_id'     => $blog( 'artist', 4 ),
			'post_type'   => 'artist_profile',
			'host'        => 'artist.extrachill.com',
			'supply_unit' => 'profiles',
		),
		'blog'      => array(
			'label'       => 'Main Blog',
			'blog_id'     => $blog( 'main', 1 ),
			'post_type'   => 'post',
			'host'        => 'extrachill.com',
			'supply_unit' => 'posts',
		),
	);
}

/**
 * Execute callback for get-surface-growth ability.
 *
 * @param array $input Input parameters.
 * @return array Cross-surface growth read.
 */
function extrachill_analytics_ability_get_surface_growth( $input ) {
	$weeks = isset( $input['weeks'] ) ? max( 1, (int) $input['weeks'] ) : 4;
	$days  = $weeks * 7;

	$now_utc      = gmdate( 'Y-m-d H:i:s' );
	$window_start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
	// Prior equal-length window, used for both the supply % (prior_total is
	// everything before the window) and the demand slope (previous period).
	$prior_start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", strtotime( $window_start ) ) );

	// Resolve the GA ability once. Its absence is a demand coverage gap, not a
	// fatal: every surface's demand figure degrades to not_instrumented.
	$ga_ability = ( function_exists( 'wp_get_ability' ) )
		? wp_get_ability( 'datamachine/google-analytics' )
		: null;
	$ga_available = ( $ga_ability instanceof WP_Ability );

	$surfaces = array();

	foreach ( extrachill_analytics_surface_growth_map() as $key => $surface ) {
		$supply = extrachill_analytics_surface_supply_growth( $surface, $window_start, $days );
		$demand = extrachill_analytics_surface_demand_growth( $surface, $ga_ability, $ga_available, $days );

		$surfaces[ $key ] = array(
			'surface'      => $key,
			'label'        => $surface['label'],
			'blog_id'      => $surface['blog_id'],
			'host'         => $surface['host'],
			'supply'       => $supply,
			'demand'       => $demand,
			// Single normalized, unit-free comparison figure per surface. We use
			// the supply percent-per-week growth as the cross-surface axis
			// because every live surface can produce it deterministically, while
			// demand depends on GA being configured. Demand is reported and
			// ranked separately below so it is never silently dropped.
			'growth_pct_per_week' => isset( $supply['pct_per_week'] ) ? $supply['pct_per_week'] : null,
		);
	}

	return array(
		'surfaces'          => array_values( $surfaces ),
		'supply_ranking'    => extrachill_analytics_rank_surfaces( $surfaces, 'supply' ),
		'demand_ranking'    => extrachill_analytics_rank_surfaces( $surfaces, 'demand' ),
		'fastest_growing'   => extrachill_analytics_fastest_growing( $surfaces ),
		'weeks'             => $weeks,
		'days'              => $days,
		'window'            => array(
			'start' => $window_start,
			'end'   => $now_utc,
		),
		'prior_window'      => array(
			'start' => $prior_start,
			'end'   => $window_start,
		),
		'ga_available'      => $ga_available,
		'as_of'             => $now_utc,
		'note'              => 'SUPPLY = inventory growth (new published items per week + % over prior total), deterministic COUNT(*) per surface post type. DEMAND = organic-sessions slope per host (current window vs previous equal window) from datamachine/google-analytics. The two are distinct: growing inventory != growing audience. Unmeasurable dimensions return a not_instrumented marker (coverage gap), never a zero. The cross-surface axis (growth_pct_per_week / fastest_growing) is supply-based because every live surface can produce it; demand is ranked separately and degrades gracefully when GA is unavailable.',
	);
}

/**
 * Compute a surface's SUPPLY growth: new published items in the window.
 *
 * Deterministic COUNT(*) of the surface's post type over post_date, plus the
 * prior total (everything published before the window) to express a unit-free
 * percent-over-window and a per-week rate. The blog's posts table is read
 * directly via the canonical per-blog prefix — post_date is the time series, so
 * no new tracking table is needed.
 *
 * @param array  $surface      Surface definition.
 * @param string $window_start UTC lower bound of the window (Y-m-d H:i:s).
 * @param int    $days         Window length in days.
 * @return array Supply growth figure, or a not_instrumented marker.
 */
function extrachill_analytics_surface_supply_growth( $surface, $window_start, $days ) {
	global $wpdb;

	$blog_id   = (int) $surface['blog_id'];
	$post_type = $surface['post_type'];

	if ( $blog_id <= 0 || '' === $post_type ) {
		return extrachill_analytics_not_instrumented( 'Surface has no countable blog/post type.' );
	}

	// post_date is stored in the site's local timezone, but the window bound is
	// UTC. Convert the bound to the blog's local time so the COUNT(*) compares
	// like with like. We switch into the blog only to read its timezone + table
	// prefix; the query itself is a single aggregate.
	$prefix = $wpdb->get_blog_prefix( $blog_id );
	$table  = $prefix . 'posts';

	// Guard: the posts table must exist (a decommissioned/absent blog is a
	// coverage gap, not a zero).
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return extrachill_analytics_not_instrumented( 'Posts table for this surface is not available.' );
	}

	// Express the UTC window bound in the blog's local time for post_date
	// comparison. get_post_time/Date helpers need blog context, so resolve the
	// offset from the blog's gmt_offset option directly to avoid a switch.
	$gmt_offset    = (float) get_blog_option( $blog_id, 'gmt_offset', 0 );
	$offset_secs   = (int) round( $gmt_offset * HOUR_IN_SECONDS );
	$window_local  = gmdate( 'Y-m-d H:i:s', strtotime( $window_start ) + $offset_secs );

	// New items inside the window.
	$new_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s",
			$post_type,
			$window_local
		)
	);

	// Prior total: everything published before the window. Basis for the
	// percent-growth figure (new_in_window / prior_total).
	$prior_total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE post_type = %s AND post_status = 'publish' AND post_date < %s",
			$post_type,
			$window_local
		)
	);

	$weeks        = $days / 7;
	$per_week     = $weeks > 0 ? round( $new_count / $weeks, 2 ) : (float) $new_count;
	// Unit-free axis: percent of the prior catalog added per week. When the
	// surface had no prior catalog (brand-new surface) the percent is undefined
	// rather than infinite — we report the raw per-week rate and flag the basis.
	$pct_per_week = null;
	if ( $prior_total > 0 && $weeks > 0 ) {
		$pct_per_week = round( ( $new_count / $prior_total ) / $weeks * 100, 3 );
	}

	return array(
		'measured'     => true,
		'new_in_window' => $new_count,
		'prior_total'  => $prior_total,
		'per_week'     => $per_week,
		'pct_per_week' => $pct_per_week,
		'unit'         => $surface['supply_unit'],
		'definition'   => 'New published ' . $surface['supply_unit'] . ' in the window; pct_per_week = (new / prior_total) / weeks * 100. Null pct_per_week means no prior catalog to grow against (raw per_week still given).',
	);
}

/**
 * Compute a surface's DEMAND growth: organic-sessions slope per host.
 *
 * Calls datamachine/google-analytics action=date_stats twice — current window
 * and the immediately-preceding equal-length window — scoped to the surface's
 * host, then derives the organic share from action=traffic_sources so the
 * reported demand leans on organic sessions rather than the bot-heavy Direct
 * floor. The slope is the percent change in (organic) sessions between the two
 * windows. Any failure (ability absent, unconfigured, no data) returns a
 * not_instrumented marker — never a zero.
 *
 * @param array           $surface      Surface definition.
 * @param WP_Ability|null $ga_ability   Resolved GA ability (or null).
 * @param bool            $ga_available Whether the GA ability resolved.
 * @param int             $days         Window length in days.
 * @return array Demand growth figure, or a not_instrumented marker.
 */
function extrachill_analytics_surface_demand_growth( $surface, $ga_ability, $ga_available, $days ) {
	if ( ! $ga_available ) {
		return extrachill_analytics_not_instrumented( 'Google Analytics ability (datamachine/google-analytics) is not available on this install.' );
	}

	$host = $surface['host'];

	// Window boundaries as GA-style YYYY-MM-DD dates. GA date_stats excludes
	// "today" (defaults to yesterday) so we mirror that: the current window ends
	// yesterday; the previous window is the equal-length span before it.
	$cur_end     = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
	$cur_start   = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	$prev_end    = gmdate( 'Y-m-d', strtotime( "-" . ( $days + 1 ) . ' days' ) );
	$prev_start  = gmdate( 'Y-m-d', strtotime( "-" . ( $days * 2 ) . ' days' ) );

	$cur_total  = extrachill_analytics_ga_sessions_for_window( $ga_ability, $host, $cur_start, $cur_end );
	if ( is_wp_error( $cur_total ) ) {
		return extrachill_analytics_not_instrumented( 'GA date_stats failed for current window: ' . $cur_total->get_error_message() );
	}

	$prev_total = extrachill_analytics_ga_sessions_for_window( $ga_ability, $host, $prev_start, $prev_end );
	if ( is_wp_error( $prev_total ) ) {
		return extrachill_analytics_not_instrumented( 'GA date_stats failed for previous window: ' . $prev_total->get_error_message() );
	}

	// Organic share of the current window, used to demote the bot-heavy Direct
	// floor. If the share can't be derived, fall back to total sessions and flag
	// it, rather than failing the whole demand read.
	$organic_share = extrachill_analytics_ga_organic_share( $ga_ability, $host, $cur_start, $cur_end );
	$organic_basis = 'organic';
	if ( is_wp_error( $organic_share ) || null === $organic_share ) {
		$organic_share = 1.0;
		$organic_basis = 'all_sessions';
	}

	$cur_organic  = (int) round( $cur_total * $organic_share );
	$prev_organic = (int) round( $prev_total * $organic_share );

	// Percent slope of (organic) sessions, current vs previous equal window.
	$slope_pct = null;
	if ( $prev_organic > 0 ) {
		$slope_pct = round( ( ( $cur_organic - $prev_organic ) / $prev_organic ) * 100, 2 );
	} elseif ( $cur_organic > 0 ) {
		// No prior traffic but current traffic exists: growth is real but the
		// percent base is zero, so we mark it "new" rather than divide by zero.
		$slope_pct = null;
	}

	$weeks       = $days / 7;
	$per_week_pct = ( null !== $slope_pct && $weeks > 0 ) ? round( $slope_pct / $weeks, 3 ) : null;

	return array(
		'measured'            => true,
		'basis'               => $organic_basis,
		'organic_share'       => round( (float) $organic_share, 4 ),
		'current_sessions'    => $cur_total,
		'previous_sessions'   => $prev_total,
		'current_organic'     => $cur_organic,
		'previous_organic'    => $prev_organic,
		'slope_pct'           => $slope_pct,
		'pct_per_week'        => $per_week_pct,
		'is_new_traffic'      => ( null === $slope_pct && $cur_organic > 0 ),
		'definition'          => 'Percent change in organic sessions (current window vs previous equal-length window) for the host, organic share derived from traffic_sources. basis=all_sessions means organic share was unavailable and totals were used. slope_pct null with is_new_traffic=true means traffic appeared this window with no prior base.',
	);
}

/**
 * Sum sessions for a host over a GA date window via action=date_stats.
 *
 * @param WP_Ability $ga_ability GA ability instance.
 * @param string     $host       Hostname filter.
 * @param string     $start      Start date (YYYY-MM-DD).
 * @param string     $end        End date (YYYY-MM-DD).
 * @return int|WP_Error Total sessions, or error.
 */
function extrachill_analytics_ga_sessions_for_window( $ga_ability, $host, $start, $end ) {
	$result = $ga_ability->execute(
		array(
			'action'     => 'date_stats',
			'hostname'   => $host,
			'start_date' => $start,
			'end_date'   => $end,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		$msg = is_array( $result ) && ! empty( $result['error'] ) ? $result['error'] : 'GA returned no successful result.';
		return new WP_Error( 'ga_demand_failed', $msg );
	}

	$total = 0;
	foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
		$total += (int) ( $row['sessions'] ?? 0 );
	}

	return $total;
}

/**
 * Derive the organic share of sessions for a host over a window.
 *
 * Uses action=traffic_sources (grouped by sessionSource / sessionMedium) and
 * counts the share of sessions whose medium is organic. Returns a float 0..1,
 * or null/WP_Error when it can't be derived (caller falls back to all sessions).
 *
 * @param WP_Ability $ga_ability GA ability instance.
 * @param string     $host       Hostname filter.
 * @param string     $start      Start date (YYYY-MM-DD).
 * @param string     $end        End date (YYYY-MM-DD).
 * @return float|null|WP_Error Organic share (0..1), null if no sessions, or error.
 */
function extrachill_analytics_ga_organic_share( $ga_ability, $host, $start, $end ) {
	$result = $ga_ability->execute(
		array(
			'action'     => 'traffic_sources',
			'hostname'   => $host,
			'start_date' => $start,
			'end_date'   => $end,
			'limit'      => 1000,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		return new WP_Error( 'ga_organic_failed', is_array( $result ) && ! empty( $result['error'] ) ? $result['error'] : 'GA traffic_sources returned no result.' );
	}

	$total   = 0;
	$organic = 0;
	foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
		$sessions = (int) ( $row['sessions'] ?? 0 );
		$total   += $sessions;
		$medium   = strtolower( (string) ( $row['sessionMedium'] ?? '' ) );
		// GA4 organic mediums: "organic" (search) and "organic_social". We count
		// search organic as the audience-growth signal; treat anything literally
		// containing "organic" as organic to stay robust to GA naming.
		if ( false !== strpos( $medium, 'organic' ) ) {
			$organic += $sessions;
		}
	}

	if ( $total <= 0 ) {
		return null;
	}

	return $organic / $total;
}

/**
 * Build a standard "not instrumented" coverage-gap marker.
 *
 * Distinct from a measured zero: signals that a dimension cannot currently be
 * read, so a caller never mistakes a blind spot for stagnation.
 *
 * @param string $reason Human-readable reason.
 * @return array Coverage-gap marker.
 */
function extrachill_analytics_not_instrumented( $reason ) {
	return array(
		'measured'         => false,
		'not_instrumented' => true,
		'reason'           => $reason,
	);
}

/**
 * Rank surfaces by a dimension's normalized per-week percent growth.
 *
 * Only measured surfaces with a non-null pct_per_week participate; coverage
 * gaps are listed separately so they remain visible without polluting the rank.
 *
 * @param array  $surfaces  Per-surface results keyed by surface key.
 * @param string $dimension 'supply' or 'demand'.
 * @return array Ranking with ranked[] and unranked[] (coverage gaps).
 */
function extrachill_analytics_rank_surfaces( $surfaces, $dimension ) {
	$ranked   = array();
	$unranked = array();

	foreach ( $surfaces as $key => $surface ) {
		$dim = $surface[ $dimension ];
		if ( empty( $dim['measured'] ) || ! isset( $dim['pct_per_week'] ) || null === $dim['pct_per_week'] ) {
			$unranked[] = array(
				'surface' => $key,
				'reason'  => ! empty( $dim['reason'] ) ? $dim['reason'] : 'No comparable per-week growth figure for this surface.',
			);
			continue;
		}

		$ranked[] = array(
			'surface'      => $key,
			'label'        => $surface['label'],
			'pct_per_week' => (float) $dim['pct_per_week'],
		);
	}

	usort(
		$ranked,
		static function ( $a, $b ) {
			return $b['pct_per_week'] <=> $a['pct_per_week'];
		}
	);

	return array(
		'ranked'   => $ranked,
		'unranked' => $unranked,
	);
}

/**
 * Determine the single fastest-growing surface on the cross-surface axis.
 *
 * The axis is supply pct_per_week (every live surface can produce it). Returns
 * the top-ranked surface plus its demand figure for context, or a clear marker
 * when nothing is rankable.
 *
 * @param array $surfaces Per-surface results keyed by surface key.
 * @return array Fastest-growing descriptor.
 */
function extrachill_analytics_fastest_growing( $surfaces ) {
	$supply_rank = extrachill_analytics_rank_surfaces( $surfaces, 'supply' );

	if ( empty( $supply_rank['ranked'] ) ) {
		return array(
			'surface' => null,
			'reason'  => 'No surface produced a comparable supply growth figure.',
		);
	}

	$top = $supply_rank['ranked'][0];
	$key = $top['surface'];

	return array(
		'surface'      => $key,
		'label'        => $top['label'],
		'axis'         => 'supply_pct_per_week',
		'pct_per_week' => $top['pct_per_week'],
		'demand'       => isset( $surfaces[ $key ]['demand'] ) ? $surfaces[ $key ]['demand'] : null,
		'note'         => 'Ranked by supply (inventory) growth — the one axis every live surface can produce. Cross-check the demand block to confirm audience is growing too, not just inventory.',
	);
}
