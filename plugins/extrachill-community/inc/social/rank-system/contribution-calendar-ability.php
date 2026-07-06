<?php
/**
 * Contribution Calendar Ability
 *
 * Ability-first data layer for the GitHub-style contribution heatmap
 * (extrachill-community#147). Assembles a per-day contribution calendar by
 * CONSUMING the dated-contributions seam owned by extrachill-users
 * (`ec_get_contribution_events`). Community owns the calendar assembly +
 * rendering; users owns the underlying dated data.
 *
 * This file does NOT re-query posts or re-aggregate cross-site data. It calls
 * the seam, which already merges forum topics+replies, main-site posts, and
 * concert check-ins (each source hooks `ec_contribution_events`), and central
 * timezone conversion is handled in `ec_bucket_utc_events_by_local_day()`.
 *
 * Output payload (returned by the ability and consumed by the profile card):
 *   - user_id, days, weeks (column count)
 *   - first_sunday / window_start / window_end (site-local Y-m-d)
 *   - total_contributions, max_day_count (for shade scaling)
 *   - current_streak, longest_streak (consecutive-day runs)
 *   - counts: sparse map { 'YYYY-MM-DD': int } of days with >=1 contribution
 *
 * @package ExtraChillCommunity
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache TTL for the per-user contribution calendar (seconds).
 *
 * Busts on bbPress lifecycle events via extrachill_clear_user_points_cache().
 */
const EC_CONTRIB_CALENDAR_CACHE_TTL = HOUR_IN_SECONDS;

/**
 * Assemble a user's contribution calendar over the trailing window.
 *
 * Calls the users-owned dated-contributions seam and projects the result onto
 * a GitHub-style 53-week grid (weeks × 7 days, aligned to Sunday). The grid
 * always ends on the current site-local day; `weeks` is derived from `$days`
 * and capped at one year (53 columns) so the layout matches GitHub exactly.
 *
 * Sparse counts: only days with >=1 contribution appear in `counts`; every
 * other day in the grid range is implicitly zero. Consumers fill the gaps.
 *
 * Graceful degradation: if the seam function (`ec_get_contribution_events`)
 * is absent — e.g. extrachill-users is not yet deployed with #166 — the
 * calendar resolves to an all-zero grid with zero totals, never a fatal.
 *
 * @param int $user_id WordPress user ID.
 * @param int $days    Window length in days (default 365). Non-default windows
 *                     bypass the cache (the card always uses the default).
 * @return array Calendar payload.
 */
function extrachill_community_get_contribution_calendar( $user_id, $days = 365 ) {
	$user_id = (int) $user_id;
	$days    = max( 1, (int) $days );

	// Grid columns: full weeks needed to cover the window, capped at one year.
	$weeks = (int) ceil( $days / 7 );
	if ( $weeks > 53 ) {
		$weeks = 53;
	}
	if ( $weeks < 1 ) {
		$weeks = 1;
	}

	// Only the canonical one-year window is cached; custom windows compute live
	// so a transient keyed by user_id can never serve a stale window size.
	$use_cache = ( 365 === $days );
	$cache_key = 'ec_contrib_calendar_' . $user_id;

	if ( $use_cache ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	try {
		$today = current_datetime(); // DateTimeImmutable in site timezone.
	} catch ( Exception $e ) {
		$today = new DateTimeImmutable( 'now', wp_timezone() );
	}

	$dow          = (int) $today->format( 'w' ); // 0 = Sunday … 6 = Saturday.
	$last_sunday  = $today->modify( '-' . $dow . ' days' );
	$first_sunday = $last_sunday->modify( '-' . ( ( $weeks - 1 ) * 7 ) . ' days' );

	$window_start = $first_sunday->format( 'Y-m-d' );
	$window_end   = $today->format( 'Y-m-d' );

	// Consume the dated-contributions seam. The seam merges every registered
	// source (forum, main posts, concerts) and converts UTC → site-local day.
	$counts = array();
	if ( function_exists( 'ec_get_contribution_events' ) ) {
		$events = ec_get_contribution_events( $user_id, $window_start );
		if ( is_array( $events ) ) {
			foreach ( $events as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$date  = isset( $event['date'] ) ? (string) $event['date'] : '';
				$count = isset( $event['count'] ) ? (int) $event['count'] : 0;
				if ( '' === $date || $count < 1 ) {
					continue;
				}
				// Guard the upper bound (no future days) even though sources
				// should not emit any.
				if ( $date > $window_end ) {
					continue;
				}
				$counts[ $date ] = ( $counts[ $date ] ?? 0 ) + $count;
			}
		}
	}

	ksort( $counts );

	$total        = array_sum( $counts );
	$max_day_count = $counts ? (int) max( $counts ) : 0;

	$current_streak = extrachill_community_count_current_streak( $counts, $today );
	$longest_streak = extrachill_community_count_longest_streak( $counts, $first_sunday, $today );

	$payload = array(
		'user_id'             => $user_id,
		'days'                => $days,
		'weeks'               => $weeks,
		'first_sunday'        => $window_start,
		'window_start'        => $window_start,
		'window_end'          => $window_end,
		'total_contributions' => (int) $total,
		'max_day_count'       => $max_day_count,
		'current_streak'      => $current_streak,
		'longest_streak'      => $longest_streak,
		'counts'              => $counts,
	);

	if ( $use_cache ) {
		set_transient( $cache_key, $payload, EC_CONTRIB_CALENDAR_CACHE_TTL );
	}

	return $payload;
}

/**
 * Current streak: consecutive days with >=1 contribution, counting back from
 * today. If today has no contributions the streak is 0 (matches GitHub).
 *
 * @param array             $counts Sparse day => count map.
 * @param DateTimeImmutable $today  Current site-local day.
 * @return int
 */
function extrachill_community_count_current_streak( array $counts, $today ) {
	$streak = 0;
	$cursor = $today;
	while ( isset( $counts[ $cursor->format( 'Y-m-d' ) ] ) && $counts[ $cursor->format( 'Y-m-d' ) ] >= 1 ) {
		++$streak;
		$cursor = $cursor->modify( '-1 day' );
	}
	return $streak;
}

/**
 * Longest streak: the longest run of consecutive days with >=1 contribution
 * anywhere in the grid window.
 *
 * @param array             $counts       Sparse day => count map.
 * @param DateTimeImmutable $first_sunday Grid start date.
 * @param DateTimeImmutable $today        Grid end date (today).
 * @return int
 */
function extrachill_community_count_longest_streak( array $counts, $first_sunday, $today ) {
	$longest = 0;
	$run     = 0;
	$cursor  = $first_sunday;

	while ( $cursor <= $today ) {
		$ymd = $cursor->format( 'Y-m-d' );
		if ( isset( $counts[ $ymd ] ) && $counts[ $ymd ] >= 1 ) {
			++$run;
			if ( $run > $longest ) {
				$longest = $run;
			}
		} else {
			$run = 0;
		}
		$cursor = $cursor->modify( '+1 day' );
	}

	return $longest;
}

// ─── Ability registration ────────────────────────────────────────────────────

add_action( 'wp_abilities_api_init', 'extrachill_community_register_contribution_calendar_ability' );

/**
 * Register the contribution-calendar ability.
 *
 * Public + read-only: contribution counts are engagement signals shown on the
 * public profile, so no auth gate (mirrors extrachill/community-get-user-points).
 */
function extrachill_community_register_contribution_calendar_ability() {
	wp_register_ability(
		'extrachill/get-user-contribution-calendar',
		array(
			'label'               => __( 'Get User Contribution Calendar', 'extra-chill-community' ),
			'description'         => __( 'Get a user\'s per-day contribution counts over the trailing year, with totals and streaks (powers the profile heatmap).', 'extra-chill-community' ),
			'category'            => 'extrachill-community',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'User ID (required)', 'extra-chill-community' ),
					),
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Window length in days (default 365).', 'extra-chill-community' ),
					),
				),
				'required'   => array( 'user_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'user_id'             => array( 'type' => 'integer' ),
					'days'                => array( 'type' => 'integer' ),
					'weeks'               => array( 'type' => 'integer' ),
					'first_sunday'        => array( 'type' => 'string' ),
					'window_start'        => array( 'type' => 'string' ),
					'window_end'          => array( 'type' => 'string' ),
					'total_contributions' => array( 'type' => 'integer' ),
					'max_day_count'       => array( 'type' => 'integer' ),
					'current_streak'      => array( 'type' => 'integer' ),
					'longest_streak'      => array( 'type' => 'integer' ),
					'counts'              => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'integer' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_community_ability_get_contribution_calendar',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
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
 * Ability execute callback: resolve inputs and delegate to the builder.
 *
 * Degrades to an empty calendar when the dated-contributions seam is absent
 * (no fatal), so the ability remains safe to call before extrachill-users #166
 * is deployed.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_community_ability_get_contribution_calendar( $input ) {
	$user_id = isset( $input['user_id'] ) ? (int) $input['user_id'] : 0;
	if ( ! $user_id ) {
		return new WP_Error( 'missing_user', __( 'A user_id is required.', 'extra-chill-community' ) );
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return new WP_Error( 'user_not_found', __( 'User not found.', 'extra-chill-community' ) );
	}

	$days = isset( $input['days'] ) ? (int) $input['days'] : 365;

	return extrachill_community_get_contribution_calendar( $user_id, $days );
}
