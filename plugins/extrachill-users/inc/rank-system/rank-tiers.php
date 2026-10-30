<?php
/**
 * Rank System - Rank Tiers
 *
 * Centralized, data-driven rank tier registry for the Extra Chill Platform.
 *
 * Rank is derived from user points stored in `extrachill_total_points`. This
 * logic lives in extrachill-users as the single source of truth for
 * user-related primitives consumed by UI plugins and the centralized API.
 *
 * The ladder is defined as DATA (see ec_get_default_rank_tiers()) and exposed
 * through the `ec_rank_tiers` filter so any plugin can add, remove, or reorder
 * tiers without touching this file. Resolvers (label, full tier, next tier,
 * progress) are thin lookups over that registry.
 *
 * Design note: from `First Frost` (103) up to `Frozen Deep Space` (516246) the
 * thresholds follow a clean ~1.5x geometric curve. The first few onboarding
 * tiers ramp faster than 1.5x on purpose so new users climb quickly before the
 * curve settles. Thresholds are stored as explicit literals for readability and
 * easy hand-editing.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default rank tier definitions, ascending by min_points.
 *
 * Each tier is a structured record mirroring the badges system shape:
 *   - key:        stable machine identifier (snake_case)
 *   - label:      human-facing rank name
 *   - min_points: inclusive lower bound of the tier
 *   - icon:       icon hint for UI (Dashicons/Feather-style name)
 *   - class_name: CSS hook for styling the rank
 *
 * @return array<int, array{key:string,label:string,min_points:float,icon:string,class_name:string}>
 */
function ec_get_default_rank_tiers() {
	return array(
		array(
			'key'        => 'dew',
			'label'      => 'Dew',
			'min_points' => 0,
			'icon'       => 'water',
			'class_name' => 'rank-dew',
		),
		array(
			'key'        => 'droplet',
			'label'      => 'Droplet',
			'min_points' => 15,
			'icon'       => 'water',
			'class_name' => 'rank-droplet',
		),
		array(
			'key'        => 'puddle',
			'label'      => 'Puddle',
			'min_points' => 35,
			'icon'       => 'water',
			'class_name' => 'rank-puddle',
		),
		array(
			'key'        => 'crisp_air',
			'label'      => 'Crisp Air',
			'min_points' => 69,
			'icon'       => 'wind',
			'class_name' => 'rank-crisp-air',
		),
		array(
			'key'        => 'first_frost',
			'label'      => 'First Frost',
			'min_points' => 103,
			'icon'       => 'snowflake',
			'class_name' => 'rank-first-frost',
		),
		array(
			'key'        => 'powder',
			'label'      => 'Powder',
			'min_points' => 130,
			'icon'       => 'snowflake',
			'class_name' => 'rank-powder',
		),
		array(
			'key'        => 'overnight_freeze',
			'label'      => 'Overnight Freeze',
			'min_points' => 155,
			'icon'       => 'snowflake',
			'class_name' => 'rank-overnight-freeze',
		),
		array(
			'key'        => 'icicle',
			'label'      => 'Icicle',
			'min_points' => 190,
			'icon'       => 'snowflake',
			'class_name' => 'rank-icicle',
		),
		array(
			'key'        => 'ice_cube',
			'label'      => 'Ice Cube',
			'min_points' => 232,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ice-cube',
		),
		array(
			'key'        => 'full_ice_tray',
			'label'      => 'Full Ice Tray',
			'min_points' => 349,
			'icon'       => 'snowflake',
			'class_name' => 'rank-full-ice-tray',
		),
		array(
			'key'        => 'bag_of_ice',
			'label'      => 'Bag of Ice',
			'min_points' => 523,
			'icon'       => 'snowflake',
			'class_name' => 'rank-bag-of-ice',
		),
		array(
			'key'        => 'ice_maker',
			'label'      => 'Ice Maker',
			'min_points' => 785,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ice-maker',
		),
		array(
			'key'        => 'cooler',
			'label'      => 'Cooler',
			'min_points' => 1178,
			'icon'       => 'snowflake',
			'class_name' => 'rank-cooler',
		),
		array(
			'key'        => 'fridge',
			'label'      => 'Fridge',
			'min_points' => 1768,
			'icon'       => 'snowflake',
			'class_name' => 'rank-fridge',
		),
		array(
			'key'        => 'freezer',
			'label'      => 'Freezer',
			'min_points' => 2652,
			'icon'       => 'snowflake',
			'class_name' => 'rank-freezer',
		),
		array(
			'key'        => 'ice_machine',
			'label'      => 'Ice Machine',
			'min_points' => 3978,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ice-machine',
		),
		array(
			'key'        => 'frozen_foods_isle',
			'label'      => 'Frozen Foods Isle',
			'min_points' => 5968,
			'icon'       => 'snowflake',
			'class_name' => 'rank-frozen-foods-isle',
		),
		array(
			'key'        => 'walk_in_freezer',
			'label'      => 'Walk-In Freezer',
			'min_points' => 8952,
			'icon'       => 'snowflake',
			'class_name' => 'rank-walk-in-freezer',
		),
		array(
			'key'        => 'ice_rink',
			'label'      => 'Ice Rink',
			'min_points' => 13428,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ice-rink',
		),
		array(
			'key'        => 'flurry',
			'label'      => 'Flurry',
			'min_points' => 20143,
			'icon'       => 'snowflake',
			'class_name' => 'rank-flurry',
		),
		array(
			'key'        => 'snowstorm',
			'label'      => 'Snowstorm',
			'min_points' => 30214,
			'icon'       => 'snowflake',
			'class_name' => 'rank-snowstorm',
		),
		array(
			'key'        => 'ski_resort',
			'label'      => 'Ski Resort',
			'min_points' => 45322,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ski-resort',
		),
		array(
			'key'        => 'blizzard',
			'label'      => 'Blizzard',
			'min_points' => 67983,
			'icon'       => 'snowflake',
			'class_name' => 'rank-blizzard',
		),
		array(
			'key'        => 'glacier',
			'label'      => 'Glacier',
			'min_points' => 101974,
			'icon'       => 'snowflake',
			'class_name' => 'rank-glacier',
		),
		array(
			'key'        => 'iceberg',
			'label'      => 'Iceberg',
			'min_points' => 125000,
			'icon'       => 'snowflake',
			'class_name' => 'rank-iceberg',
		),
		array(
			'key'        => 'antarctica',
			'label'      => 'Antarctica',
			'min_points' => 152961,
			'icon'       => 'snowflake',
			'class_name' => 'rank-antarctica',
		),
		array(
			'key'        => 'ice_age',
			'label'      => 'Ice Age',
			'min_points' => 229442,
			'icon'       => 'snowflake',
			'class_name' => 'rank-ice-age',
		),
		array(
			'key'        => 'upper_atmosphere',
			'label'      => 'Upper Atmosphere',
			'min_points' => 344164,
			'icon'       => 'snowflake',
			'class_name' => 'rank-upper-atmosphere',
		),
		array(
			'key'        => 'frozen_deep_space',
			'label'      => 'Frozen Deep Space',
			'min_points' => 516246,
			'icon'       => 'snowflake',
			'class_name' => 'rank-frozen-deep-space',
		),
	);
}

/**
 * Flush the per-request rank tier registry cache.
 *
 * Useful when a plugin registers tiers after the registry was first read, or
 * in tests that exercise the `ec_rank_tiers` filter.
 *
 * @return void
 */
function ec_flush_rank_tiers_cache() {
	ec_get_rank_tiers( true );
}

/**
 * Get the rank tier registry, ascending by min_points.
 *
 * The default ladder is passed through the `ec_rank_tiers` filter so any plugin
 * can add, remove, or reorder tiers. The result is normalized (cast types,
 * sorted ascending, guaranteed floor tier) and cached for the request.
 *
 * @param bool $flush When true, clears the per-request cache and returns early.
 * @return array<int, array{key:string,label:string,min_points:float,icon:string,class_name:string}>
 */
function ec_get_rank_tiers( $flush = false ) {
	static $cache = null;

	if ( $flush ) {
		$cache = null;
		return array();
	}

	if ( null !== $cache ) {
		return $cache;
	}

	/**
	 * Filters the rank tier registry.
	 *
	 * Tiers are ordered ascending by `min_points` after filtering. Each tier
	 * must provide `key`, `label`, and `min_points`; `icon` and `class_name`
	 * are optional and default to empty strings.
	 *
	 * @param array<int, array> $tiers Default rank tiers.
	 */
	$tiers = apply_filters( 'ec_rank_tiers', ec_get_default_rank_tiers() );

	if ( ! is_array( $tiers ) || empty( $tiers ) ) {
		$tiers = ec_get_default_rank_tiers();
	}

	// Normalize each record.
	$normalized = array();
	foreach ( $tiers as $tier ) {
		if ( ! is_array( $tier ) || ! isset( $tier['label'] ) || ! isset( $tier['min_points'] ) ) {
			continue;
		}

		$normalized[] = array(
			'key'        => isset( $tier['key'] ) ? (string) $tier['key'] : sanitize_key( (string) $tier['label'] ),
			'label'      => (string) $tier['label'],
			'min_points' => (float) $tier['min_points'],
			'icon'       => isset( $tier['icon'] ) ? (string) $tier['icon'] : '',
			'class_name' => isset( $tier['class_name'] ) ? (string) $tier['class_name'] : '',
		);
	}

	if ( empty( $normalized ) ) {
		$normalized = ec_get_default_rank_tiers();
	}

	// Sort ascending by min_points.
	usort(
		$normalized,
		static function ( $a, $b ) {
			return $a['min_points'] <=> $b['min_points'];
		}
	);

	$cache = $normalized;

	return $cache;
}

/**
 * Resolve the full rank tier record for a point total.
 *
 * Returns the highest tier whose `min_points` is <= $points. Falls back to the
 * lowest defined tier when $points is below every threshold.
 *
 * @param float|int|string $points Point total.
 * @return array{key:string,label:string,min_points:float,icon:string,class_name:string} Tier record.
 */
function ec_get_rank_tier_for_points( $points ) {
	$points = (float) $points;
	$tiers  = ec_get_rank_tiers();

	$match = $tiers[0];
	foreach ( $tiers as $tier ) {
		if ( $points >= $tier['min_points'] ) {
			$match = $tier;
		} else {
			break;
		}
	}

	return $match;
}

/**
 * Get the next rank tier above a point total.
 *
 * @param float|int|string $points Point total.
 * @return array{key:string,label:string,min_points:float,icon:string,class_name:string}|null
 *         Next tier record, or null if already at the top tier.
 */
function ec_get_next_rank_tier( $points ) {
	$points = (float) $points;
	$tiers  = ec_get_rank_tiers();

	foreach ( $tiers as $tier ) {
		if ( $tier['min_points'] > $points ) {
			return $tier;
		}
	}

	return null;
}

/**
 * Get rank progress toward the next tier.
 *
 * @param float|int|string $points Point total.
 * @return array{
 *     current:array,
 *     next:?array,
 *     points:float,
 *     points_into_current:float,
 *     points_to_next:?float,
 *     span:?float,
 *     percent:float,
 *     is_max:bool
 * }
 */
function ec_get_rank_progress( $points ) {
	$points  = (float) $points;
	$current = ec_get_rank_tier_for_points( $points );
	$next    = ec_get_next_rank_tier( $points );

	$points_into_current = $points - $current['min_points'];

	if ( null === $next ) {
		return array(
			'current'             => $current,
			'next'                => null,
			'points'              => $points,
			'points_into_current' => $points_into_current,
			'points_to_next'      => null,
			'span'                => null,
			'percent'             => 100.0,
			'is_max'              => true,
		);
	}

	$span           = $next['min_points'] - $current['min_points'];
	$points_to_next = $next['min_points'] - $points;
	$percent        = $span > 0 ? min( 100.0, max( 0.0, ( $points_into_current / $span ) * 100 ) ) : 0.0;

	return array(
		'current'             => $current,
		'next'                => $next,
		'points'              => $points,
		'points_into_current' => $points_into_current,
		'points_to_next'      => $points_to_next,
		'span'                => $span,
		'percent'             => $percent,
		'is_max'              => false,
	);
}

/**
 * Determine rank name from point total.
 *
 * @param float|int|string $points Point total.
 * @return string Rank label.
 */
function ec_determine_rank_by_points( $points ) {
	$tier = ec_get_rank_tier_for_points( $points );
	return $tier['label'];
}

/**
 * Get rank label for a point total.
 *
 * @param float|int|string $points Point total.
 * @return string Rank label.
 */
function ec_get_rank_for_points( $points ) {
	return ec_determine_rank_by_points( $points );
}
