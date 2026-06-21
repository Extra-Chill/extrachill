<?php
/**
 * Get Surface Stickiness Ability
 *
 * Engagement instrument for the platform's NON-ARTICLE surfaces — community,
 * artist platform, and the events calendar. These surfaces earn $0 ad revenue
 * BY DESIGN (community has no ads; the artist platform is not monetized), so
 * the all-time revenue x-ray reads them as dead. They are not dead — they are
 * the platform pivot. Their value is STICKINESS: do real people come back, go
 * deeper, and traverse between surfaces? This ability answers that question
 * with a deterministic, first-party read, and deliberately NEVER references ad
 * revenue. A $0-ad-revenue surface can be the platform's most valuable asset if
 * it is building returning, dedicated visitors.
 *
 * WHAT IT COMPOSES (no new instrumentation — every signal already exists):
 *
 *   ENGAGEMENT CORE — extrachill/get-retention-stats, scoped per blog_id and
 *   called TWICE (current window + the immediately-preceding equal-length
 *   window) so every figure is a this-period-vs-prior TREND, not a lone level:
 *     - return_rate        share of visitors active on >= 2 distinct UTC days
 *     - session_depth      avg pageviews per visitor per active day
 *     - new_vs_returning   new = total - returning visitors (the split)
 *   These come from first-party `pageview` rows keyed on an anonymous first-
 *   party visitor_id (no PII, bot-filtered server-side) — the one engagement
 *   signal GA4's sampled/bot-inflated numbers cannot match.
 *
 *   CROSS-SURFACE TRAVERSAL — the retention ability's network-wide
 *   cross_site_return (visitors who hit >= 2 distinct blogs on >= 2 distinct
 *   days). This is inherently a network figure, so it is reported ONCE at the
 *   top level, never faked into per-surface granularity.
 *
 *   ACTIVITY CONTEXT — for the surfaces whose value is the content their
 *   community produces, a deterministic COUNT(*) of the surface's "stickiness
 *   post type" (community topics, artist profiles, calendar events) created in
 *   the window vs the prior equal window. post_date IS the time series — no new
 *   tracking table. This layers "are people DOING more here?" on top of "are
 *   people COMING BACK here?".
 *
 * COVERAGE GAPS, NOT ZEROS: where a signal can't be read (retention ability
 * absent, a surface's posts table missing), the cell is a `not_instrumented`
 * marker, NEVER a 0. A 0 means "measured and flat" — which for these low-
 * activity surfaces is the HONEST and expected finding right now; reporting a
 * real low number IS the point. not_instrumented means "we cannot see this yet"
 * and must never masquerade as stagnation.
 *
 * SCOPE: this is the ability only. The thin `wp extrachill analytics
 * stickiness` CLI wrapper belongs in extrachill-cli per the canonical-home
 * rule and ships alongside it.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-surface-stickiness ability.
 */
function extrachill_analytics_register_surface_stickiness_ability() {
	wp_register_ability(
		'extrachill/get-surface-stickiness',
		array(
			'label'               => __( 'Get Surface Stickiness', 'extrachill-analytics' ),
			'description'         => __( 'Returns a per-surface ENGAGEMENT (not revenue) scorecard for the platform pivot surfaces (community, artist, events): return rate, session depth, new-vs-returning, and windowed activity — each as a this-period-vs-prior trend — plus a network-wide cross-surface traversal figure. These surfaces earn $0 ad revenue by design and must be judged by stickiness. Unmeasurable cells return a not_instrumented coverage marker, never a zero.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days' => array(
						'type'        => 'integer',
						'description' => __( 'Length of the current window in days. The trend compares this window against the immediately-preceding window of equal length. Default 28.', 'extrachill-analytics' ),
						'default'     => 28,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with per-surface stickiness (engagement core + activity context) as current-vs-prior trends, a network-wide cross-surface traversal figure, and the exact UTC windows.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_surface_stickiness',
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
 * Pivot-surface definition map.
 *
 * Only the NON-ARTICLE surfaces whose value is stickiness rather than ad
 * revenue: community, artist platform, events calendar. Each declares the blog
 * it lives on (for first-party retention scoping) and, where its value is the
 * content its members produce, the "stickiness post type" whose windowed
 * COUNT(*) is its activity signal. Blog IDs resolve from the canonical
 * extrachill-multisite map when available (single source of truth), with
 * hardcoded production fallbacks so the read degrades rather than fatals.
 *
 * The events calendar's activity is inventory we publish, not member UGC, so
 * its activity counter is still useful context but is labelled as such.
 *
 * @return array<string,array{label:string,blog_id:int,host:string,activity_post_type:string,activity_unit:string}>
 */
function extrachill_analytics_stickiness_surface_map() {
	$blog = function ( $key, $fallback ) {
		return function_exists( 'ec_get_blog_id' ) && null !== ec_get_blog_id( $key )
			? (int) ec_get_blog_id( $key )
			: (int) $fallback;
	};

	return array(
		'community' => array(
			'label'              => 'Community',
			'blog_id'            => $blog( 'community', 2 ),
			'host'               => 'community.extrachill.com',
			'activity_post_type' => 'topic',
			'activity_unit'      => 'topics',
		),
		'artist'    => array(
			'label'              => 'Artist Platform',
			'blog_id'            => $blog( 'artist', 4 ),
			'host'               => 'artist.extrachill.com',
			'activity_post_type' => 'artist_profile',
			'activity_unit'      => 'profiles',
		),
		'events'    => array(
			'label'              => 'Events Calendar',
			'blog_id'            => $blog( 'events', 7 ),
			'host'               => 'events.extrachill.com',
			'activity_post_type' => 'data_machine_events',
			'activity_unit'      => 'events',
		),
	);
}

/**
 * Execute callback for get-surface-stickiness ability.
 *
 * @param array $input Input parameters.
 * @return array Per-surface stickiness read.
 */
function extrachill_analytics_ability_get_surface_stickiness( $input ) {
	$days = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 28;

	$now_utc      = gmdate( 'Y-m-d H:i:s' );
	$window_start = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days" ) );
	$prior_start  = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$days} days", (int) strtotime( $window_start ) ) );

	// Resolve the retention ability once. Its absence is the engagement-core
	// coverage gap for every surface — never a fatal, never a faked zero.
	$retention_ability = ( function_exists( 'wp_get_ability' ) )
		? wp_get_ability( 'extrachill/get-retention-stats' )
		: null;
	$retention_present = ( $retention_ability instanceof WP_Ability );

	$surfaces = array();
	foreach ( extrachill_analytics_stickiness_surface_map() as $key => $surface ) {
		$engagement = extrachill_analytics_surface_engagement_trend( $retention_ability, $retention_present, $surface['blog_id'], $days );
		$activity   = extrachill_analytics_surface_activity_trend( $surface, $window_start, $prior_start, $days );

		$surfaces[ $key ] = array(
			'surface'    => $key,
			'label'      => $surface['label'],
			'blog_id'    => $surface['blog_id'],
			'host'       => $surface['host'],
			'engagement' => $engagement,
			'activity'   => $activity,
		);
	}

	return array(
		'surfaces'            => array_values( $surfaces ),
		// Cross-surface traversal is inherently a network figure (a visitor
		// crossing >= 2 blogs). Reported once, never per-surface, and compared
		// this-window-vs-prior like every other signal.
		'cross_surface'       => extrachill_analytics_cross_surface_trend( $retention_ability, $retention_present, $days ),
		'days'                => $days,
		'window'              => array(
			'start' => $window_start,
			'end'   => $now_utc,
		),
		'prior_window'        => array(
			'start' => $prior_start,
			'end'   => $window_start,
		),
		'retention_available' => $retention_present,
		'as_of'               => $now_utc,
		'lens'                => 'engagement',
		'note'                => 'ENGAGEMENT lens, NOT revenue. These surfaces (community, artist, events) earn $0 ad revenue by design; judging them by ad revenue is the wrong instrument and always reads $0. ENGAGEMENT CORE = first-party return_rate / session_depth / new-vs-returning from extrachill/get-retention-stats, scoped per blog and reported as current-window-vs-prior-equal-window trend. ACTIVITY = deterministic COUNT(*) of each surface stickiness post type (topics / profiles / events) created per window. CROSS-SURFACE = network-wide visitors hitting >= 2 blogs on >= 2 days. A measured 0 means flat (honest + expected for these low-activity surfaces right now); not_instrumented is a coverage gap and must never be read as a zero.',
	);
}

/**
 * Compose a surface's first-party engagement trend from get-retention-stats.
 *
 * Calls the retention ability twice for the same blog — current window and the
 * immediately-preceding equal-length window — so return rate, session depth,
 * and the new-vs-returning split each carry a delta. The retention ability owns
 * all the SQL; this is pure composition.
 *
 * @param WP_Ability|null $ability Retention ability (or null if absent).
 * @param bool            $present Whether the retention ability resolved.
 * @param int             $blog_id Blog ID to scope the first-party read to.
 * @param int             $days    Current-window length in days.
 * @return array Engagement trend, or a not_instrumented marker.
 */
function extrachill_analytics_surface_engagement_trend( $ability, $present, $blog_id, $days ) {
	if ( ! $present ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Retention ability (extrachill/get-retention-stats) is not available on this install.' );
	}

	if ( (int) $blog_id <= 0 ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Surface has no resolvable blog_id for first-party retention scoping.' );
	}

	// Current window ends now (end_days_ago=0); the prior window is the exact
	// equal-length window immediately before it (end_days_ago=$days). The
	// retention ability's end_days_ago input makes both reads exact — no
	// end-date-less proxying.
	$current = extrachill_analytics_retention_snapshot( $ability, (int) $blog_id, $days, 0 );
	$prior   = extrachill_analytics_retention_snapshot( $ability, (int) $blog_id, $days, $days );

	if ( null === $current ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Retention ability returned no usable result for the current window.' );
	}

	// The prior window is best-effort context: if it can't be read we still
	// report the current level, just without a delta.
	$delta = function ( $cur, $prev ) {
		if ( null === $prev ) {
			return null;
		}
		return round( (float) $cur - (float) $prev, 4 );
	};

	return array(
		'measured'         => true,
		'return_rate'      => array(
			'current'    => $current['return_rate'],
			'prior'      => null !== $prior ? $prior['return_rate'] : null,
			'delta'      => null !== $prior ? $delta( $current['return_rate'], $prior['return_rate'] ) : null,
			'definition' => 'Share of visitors active on >= 2 distinct UTC days within the window.',
		),
		'session_depth'    => array(
			'current'    => $current['session_depth'],
			'prior'      => null !== $prior ? $prior['session_depth'] : null,
			'delta'      => null !== $prior ? $delta( $current['session_depth'], $prior['session_depth'] ) : null,
			'definition' => 'Average pageview events per visitor per active UTC day.',
		),
		'new_vs_returning' => array(
			'total_visitors'     => $current['total_visitors'],
			'returning_visitors' => $current['returning_visitors'],
			'new_visitors'       => $current['new_visitors'],
			'prior_total'        => null !== $prior ? $prior['total_visitors'] : null,
			'total_delta'        => null !== $prior ? (int) ( $current['total_visitors'] - $prior['total_visitors'] ) : null,
			'definition'         => 'New = total distinct visitors minus those active on >= 2 days (returning).',
		),
	);
}

/**
 * Pull a single normalized retention snapshot for a blog and window offset.
 *
 * Reads an exact $days-long window ending $offset_days ago via the retention
 * ability's end_days_ago input, then maps its nested result to the flat figures
 * the stickiness trend needs. Returns null on error so the caller can degrade
 * to a gap (current window) or to a delta-less level (prior window).
 *
 * @param WP_Ability $ability     Retention ability instance.
 * @param int        $blog_id     Blog ID.
 * @param int        $days        Window length in days.
 * @param int        $offset_days Days the window is shifted back (0 = current).
 * @return array{return_rate:float,session_depth:float,total_visitors:int,returning_visitors:int,new_visitors:int}|null
 */
function extrachill_analytics_retention_snapshot( $ability, $blog_id, $days, $offset_days ) {
	$result = $ability->execute(
		array(
			'days'         => $days,
			'end_days_ago' => max( 0, (int) $offset_days ),
			'blog_id'      => $blog_id,
		)
	);

	return extrachill_analytics_retention_extract( $result );
}

/**
 * Extract the flat stickiness figures from a retention ability result.
 *
 * @param mixed $result Retention ability result (array or WP_Error).
 * @return array{return_rate:float,session_depth:float,total_visitors:int,returning_visitors:int,new_visitors:int}|null
 */
function extrachill_analytics_retention_extract( $result ) {
	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return null;
	}

	$return_block = isset( $result['return_rate'] ) && is_array( $result['return_rate'] ) ? $result['return_rate'] : array();
	$depth_block  = isset( $result['session_depth'] ) && is_array( $result['session_depth'] ) ? $result['session_depth'] : array();

	$total     = (int) ( $return_block['total_visitors'] ?? 0 );
	$returning = (int) ( $return_block['returning_visitors'] ?? 0 );

	return array(
		'return_rate'        => round( (float) ( $return_block['rate'] ?? 0.0 ), 4 ),
		'session_depth'      => round( (float) ( $depth_block['avg_pageviews_per_visitor_day'] ?? 0.0 ), 2 ),
		'total_visitors'     => $total,
		'returning_visitors' => $returning,
		'new_visitors'       => max( 0, $total - $returning ),
	);
}

/**
 * Compose the network-wide cross-surface traversal trend.
 *
 * Cross-surface return is inherently a network figure (a visitor crossing >= 2
 * blogs), so it is sourced once from the retention ability's network-wide
 * cross_site_return block and compared current-window-vs-prior like the
 * per-surface metrics.
 *
 * @param WP_Ability|null $ability Retention ability (or null if absent).
 * @param bool            $present Whether the retention ability resolved.
 * @param int             $days    Current-window length in days.
 * @return array Cross-surface trend, or a not_instrumented marker.
 */
function extrachill_analytics_cross_surface_trend( $ability, $present, $days ) {
	if ( ! $present ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Retention ability (extrachill/get-retention-stats) is not available on this install.' );
	}

	$current = extrachill_analytics_cross_surface_rate( $ability, $days, 0 );
	if ( null === $current ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Retention ability returned no usable cross-surface result for the current window.' );
	}

	// Prior window is the exact equal-length window immediately before the
	// current one (end_days_ago=$days), sourced the same way.
	$prior = extrachill_analytics_cross_surface_rate( $ability, $days, $days );

	return array(
		'measured'   => true,
		'current'    => $current,
		'prior'      => $prior,
		'delta'      => null !== $prior ? round( $current - $prior, 4 ) : null,
		'definition' => 'Network-wide share of visitors who hit >= 2 distinct blogs on >= 2 distinct UTC days. Inherently cross-surface (ignores per-surface blog filter). Current and prior are exact equal-length windows.',
	);
}

/**
 * Read the network-wide cross-surface return rate for a window length.
 *
 * @param WP_Ability $ability     Retention ability instance.
 * @param int        $days        Window length in days.
 * @param int        $offset_days Days the window is shifted back (0 = current).
 * @return float|null Cross-site return rate (0..1), or null on error.
 */
function extrachill_analytics_cross_surface_rate( $ability, $days, $offset_days = 0 ) {
	$result = $ability->execute(
		array(
			'days'         => $days,
			'end_days_ago' => max( 0, (int) $offset_days ),
			'blog_id'      => 0,
		)
	);

	if ( is_wp_error( $result ) || ! is_array( $result ) || ! isset( $result['cross_site_return']['rate'] ) ) {
		return null;
	}

	return round( (float) $result['cross_site_return']['rate'], 4 );
}

/**
 * Compose a surface's ACTIVITY trend: new stickiness-post-type items per window.
 *
 * Deterministic COUNT(*) of the surface's activity post type (community topics,
 * artist profiles, calendar events) created in the current window vs the prior
 * equal-length window, read directly from the blog's posts table via the
 * canonical per-blog prefix. post_date IS the time series — no new tracking
 * table. Mirrors the proven get-surface-growth supply pattern.
 *
 * @param array  $surface      Surface definition.
 * @param string $window_start UTC lower bound of the current window (Y-m-d H:i:s).
 * @param string $prior_start  UTC lower bound of the prior window (Y-m-d H:i:s).
 * @param int    $days         Window length in days.
 * @return array Activity trend, or a not_instrumented marker.
 */
function extrachill_analytics_surface_activity_trend( $surface, $window_start, $prior_start, $days ) {
	global $wpdb;

	$blog_id   = (int) $surface['blog_id'];
	$post_type = $surface['activity_post_type'];

	if ( $blog_id <= 0 || '' === $post_type ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Surface has no countable blog/post type for activity.' );
	}

	$prefix = $wpdb->get_blog_prefix( $blog_id );
	$table  = $prefix . 'posts';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return extrachill_analytics_stickiness_not_instrumented( 'Posts table for this surface is not available.' );
	}

	// post_date is stored in the blog's local timezone; the window bounds are
	// UTC. Convert the bounds to the blog's local time so the COUNT compares
	// like with like, resolving the offset from the blog's gmt_offset option
	// directly (no switch_to_blog needed for a single aggregate).
	$gmt_offset  = (float) get_blog_option( $blog_id, 'gmt_offset', 0 );
	$offset_secs = (int) round( $gmt_offset * HOUR_IN_SECONDS );

	$window_local = gmdate( 'Y-m-d H:i:s', strtotime( $window_start ) + $offset_secs );
	$prior_local  = gmdate( 'Y-m-d H:i:s', strtotime( $prior_start ) + $offset_secs );

	// $table is an internal, trusted blog-prefix table name from
	// $wpdb->get_blog_prefix() — never user input — and cannot be a prepare()
	// placeholder. All values are placeholdered. Direct aggregate reads are
	// intentional (no cache layer for these cross-surface counts).
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$current_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s",
			$post_type,
			$window_local
		)
	);

	$prior_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s AND post_date < %s",
			$post_type,
			$prior_local,
			$window_local
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$weeks    = $days / 7;
	$per_week = $weeks > 0 ? round( $current_count / $weeks, 2 ) : (float) $current_count;

	return array(
		'measured'   => true,
		'unit'       => $surface['activity_unit'],
		'current'    => $current_count,
		'prior'      => $prior_count,
		'delta'      => $current_count - $prior_count,
		'per_week'   => $per_week,
		'definition' => 'New published ' . $surface['activity_unit'] . ' created in the window vs the prior equal-length window. delta > 0 means the surface produced more this period.',
	);
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
function extrachill_analytics_stickiness_not_instrumented( $reason ) {
	return array(
		'measured'         => false,
		'not_instrumented' => true,
		'reason'           => $reason,
	);
}
