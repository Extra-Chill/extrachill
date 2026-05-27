<?php
/**
 * My Shows — Calendar query filter.
 *
 * Wires the personal-calendar tab on `/my-shows/` to the
 * data-machine-events calendar block by injecting a `post__in`
 * constraint into the WP_Query the calendar runs.
 *
 * Layer purity: data-machine-events stays generic (no knowledge of
 * `c8c_ec_concert_tracking`). Extrachill-events provides the
 * integration glue via the documented extension point.
 *
 * Hook contract (data-machine-events#325):
 *
 *     apply_filters(
 *         'data_machine_events_calendar_query_args',
 *         array $query_args, // WP_Query args about to be executed.
 *         array $input       // Raw ability input (scope, tax_filters, search, …).
 *     );
 *
 * `$input` does not carry a user_id — the filter callback resolves it
 * from request context (path b in the issue body: read the current user
 * + verify we're rendering on /my-shows/). This avoids a hard
 * dependency on a new block attribute / param key inside
 * data-machine-events.
 *
 * @package ExtraChillEvents
 * @since 0.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inject a `post__in` constraint so the calendar block only renders
 * events the current logged-in user has marked attended.
 *
 * Active only when:
 *   - request is rendering the `/my-shows/` page
 *   - a logged-in user is present
 *
 * When the user has zero tracked events we force `post__in = array( 0 )`
 * — `0` is never a valid post ID, so the query yields an empty result
 * set without throwing. WP_Query treats an empty `post__in` as "no
 * constraint", so we cannot pass `array()`; sentinel `[0]` is the
 * documented way to express "match nothing".
 *
 * @param array $query_args WP_Query args about to be executed.
 * @param array $input      Raw ability input (unused here; kept for signature compat).
 * @return array Modified $query_args.
 */
function ec_events_my_shows_filter_calendar_query( $query_args, $input = array() ) {
	unset( $input ); // Signature compatibility; we read from request context instead.

	if ( ! function_exists( 'is_page' ) || ! is_page( 'my-shows' ) ) {
		return $query_args;
	}

	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return $query_args;
	}

	$tracked = ec_events_my_shows_get_tracked_post_ids( $user_id );

	if ( empty( $tracked ) ) {
		// Force-empty result. `[0]` is the canonical "match nothing"
		// sentinel for post__in — passing an empty array would be a
		// no-op (WP_Query would skip the constraint entirely and the
		// user would see EVERY event on the site).
		$query_args['post__in'] = array( 0 );
		return $query_args;
	}

	// If the calendar already has a post__in constraint (defensive — no
	// known path sets it today, but the filter is generic), intersect
	// rather than overwrite so we don't silently widen another
	// consumer's narrowing.
	if ( ! empty( $query_args['post__in'] ) && is_array( $query_args['post__in'] ) ) {
		$existing = array_map( 'intval', $query_args['post__in'] );
		$tracked  = array_values( array_intersect( $tracked, $existing ) );
		if ( empty( $tracked ) ) {
			$query_args['post__in'] = array( 0 );
			return $query_args;
		}
	}

	$query_args['post__in'] = $tracked;
	return $query_args;
}
add_filter( 'data_machine_events_calendar_query_args', 'ec_events_my_shows_filter_calendar_query', 10, 2 );

/**
 * Resolve the list of event post IDs a given user has marked attended
 * on the events subsite.
 *
 * Queries the network-scoped `{$wpdb->base_prefix}ec_concert_tracking`
 * table directly (the table is owned by extrachill-users, but a
 * read-only select against a stable schema is acceptable cross-plugin
 * coupling for this integration). All event IDs are scoped to the
 * events blog (default blog_id=7, the documented default in the
 * tracking table schema).
 *
 * @param int $user_id User to look up.
 * @return int[] Event post IDs the user has marked attended (may be empty).
 */
function ec_events_my_shows_get_tracked_post_ids( $user_id ) {
	global $wpdb;

	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}

	$events_blog_id = function_exists( 'ec_get_blog_id' )
		? (int) ec_get_blog_id( 'events' )
		: 7;

	$table = $wpdb->base_prefix . 'ec_concert_tracking';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT event_id FROM {$table} WHERE user_id = %d AND blog_id = %d",
			$user_id,
			$events_blog_id
		)
	);
	// phpcs:enable

	if ( empty( $ids ) ) {
		return array();
	}

	return array_values( array_filter( array_map( 'intval', $ids ) ) );
}
