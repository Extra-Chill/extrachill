<?php
/**
 * Dated Contribution Events — the temporal projection of point sources.
 *
 * While `extrachill_get_user_total_points()` computes a SCALAR total, this seam
 * exposes the same engagement data as DATED events — one record per
 * day+type+count — so a consumer (e.g. the contribution heatmap,
 * extrachill-community#147) can bucket activity per calendar day.
 *
 * Sources that bear a timestamp hook `ec_contribution_events` and return raw
 * UTC datetime strings. Sources without a timestamp trail (scalar counters that
 * have no per-day history) are intentionally excluded — they cannot be dated
 * without a new table, which is out of scope.
 *
 * Timezone contract (single-helper design): contributors NEVER compute calendar
 * days themselves. They SELECT the UTC-authoritative timestamp column
 * (`post_date_gmt`, `created_at`) and hand the raw strings to
 * `ec_bucket_utc_events_by_local_day()`, which is the ONE place that converts
 * UTC → site-local calendar day. This makes every source provably consistent
 * and removes reliance on the drift-prone local `post_date` column.
 *
 * Event shape (aggregated per day+type):
 *   array( 'date' => 'YYYY-MM-DD', 'type' => '<source-key>', 'count' => int )
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bucket a list of UTC datetime strings into per-day counts in the site timezone.
 *
 * The single, canonical UTC→site-local calendar-day conversion for ALL dated
 * contribution sources. Contributors return raw UTC timestamps; this is the one
 * place that decides "what local calendar day" — so every source is provably
 * consistent and no contributor reasons about timezones.
 *
 * Also applies the `since_ymd` lower bound (site-local date), so every source
 * that routes through this helper honors the window uniformly.
 *
 * @param string[] $utc_datetimes MySQL 'Y-m-d H:i:s' strings in UTC.
 * @param string   $type          Opaque source key stamped on each row.
 * @param string   $since_ymd     Inclusive site-local lower bound (YYYY-MM-DD),
 *                                or '' for all history.
 * @return array<int, array{date:string,type:string,count:int}>
 */
function ec_bucket_utc_events_by_local_day( array $utc_datetimes, $type, $since_ymd = '' ) {
	$tz       = wp_timezone();
	$utc_tz   = new DateTimeZone( 'UTC' );
	$by_date  = array();

	foreach ( $utc_datetimes as $dt_str ) {
		try {
			$dt = new DateTime( $dt_str, $utc_tz );
		} catch ( Exception $e ) {
			continue;
		}
		$dt->setTimezone( $tz );
		$day = $dt->format( 'Y-m-d' );

		// Authoritative since-window filter (site-local date comparison).
		if ( '' !== $since_ymd && $day < $since_ymd ) {
			continue;
		}

		if ( ! isset( $by_date[ $day ] ) ) {
			$by_date[ $day ] = 0;
		}
		++$by_date[ $day ];
	}

	ksort( $by_date );

	$out = array();
	foreach ( $by_date as $day => $count ) {
		$out[] = array(
			'date'  => $day,
			'type'  => (string) $type,
			'count' => (int) $count,
		);
	}

	return $out;
}

/**
 * Get a user's dated contribution events on or after a date.
 *
 * Applies the `ec_contribution_events` filter and returns the merged,
 * normalized event list from all sources.
 *
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD, site timezone).
 *                          Events on or after this date are returned.
 *                          Empty string = no lower bound (all history).
 * @return array<int, array{date:string,type:string,count:int}>
 */
function ec_get_contribution_events( $user_id, $since_ymd = '' ) {
	/**
	 * Contribute dated engagement events.
	 *
	 * Each source returns an array of aggregated rows:
	 *   array( 'date' => 'YYYY-MM-DD', 'type' => string, 'count' => int )
	 *
	 * Contributors SHOULD build their rows via
	 * `ec_bucket_utc_events_by_local_day()` so timezone conversion is
	 * centralized. `date` is a calendar day in the site timezone. `type` is an
	 * opaque source identifier (the engine does not inspect it). `count` is the
	 * number of contributions of that type on that date.
	 *
	 * Sources without per-day timestamps (e.g. scalar counters) MUST NOT
	 * participate — there is no honest date to assign.
	 *
	 * @param array  $events    Running event list.
	 * @param int    $user_id   WordPress user ID.
	 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
	 */
	$events = (array) apply_filters( 'ec_contribution_events', array(), (int) $user_id, (string) $since_ymd );

	// Lightweight shape-guard: enforce structure + valid values. The
	// since-window filtering is authoritative in the helper; this is a
	// defensive backstop for any contributor that doesn't route through it.
	$clean = array();
	foreach ( $events as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}

		$date  = isset( $event['date'] ) ? (string) $event['date'] : '';
		$count = isset( $event['count'] ) ? (int) $event['count'] : 1;
		$type  = isset( $event['type'] ) ? (string) $event['type'] : '';

		if ( '' === $date || $count < 1 ) {
			continue;
		}

		if ( '' !== $since_ymd && $date < $since_ymd ) {
			continue;
		}

		$clean[] = array(
			'date'  => $date,
			'type'  => $type,
			'count' => $count,
		);
	}

	return $clean;
}

/**
 * Built-in dated source: main-site published posts.
 *
 * Contributes one dated event per day the user published a `post` on the main
 * site. Network-level source owned by the engine (mirrors the scalar
 * `main_posts` source in points-engine.php). Hooks `ec_contribution_events`.
 *
 * Reads `post_date_gmt` (the UTC-authoritative column) and delegates day
 * computation to `ec_bucket_utc_events_by_local_day()`. Hooks `ec_contribution_events`.
 *
 * @param array  $events    Running event list.
 * @param int    $user_id   WordPress user ID.
 * @param string $since_ymd Inclusive start date (YYYY-MM-DD), or '' for all.
 * @return array
 */
function ec_users_main_posts_contribution_events( $events, $user_id, $since_ymd ) {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return $events;
	}

	global $wpdb;

	switch_to_blog( $main_blog_id );
	try {
		// SELECT the UTC-authoritative column; day computation is centralized
		// in ec_bucket_utc_events_by_local_day(). A prolific author may have
		// thousands of rows — that's trivial for PHP bucketing.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_date_gmt
				 FROM {$wpdb->posts}
				 WHERE post_author = %d AND post_type = 'post' AND post_status = 'publish'",
				$user_id
			)
		);
	} finally {
		// Always restore blog context, even on exception.
		restore_current_blog();
	}

	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return $events;
	}

	return array_merge( $events, ec_bucket_utc_events_by_local_day( $rows, 'post', $since_ymd ) );
}
add_filter( 'ec_contribution_events', 'ec_users_main_posts_contribution_events', 10, 3 );
