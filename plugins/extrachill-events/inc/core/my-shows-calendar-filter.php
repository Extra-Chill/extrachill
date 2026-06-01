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
 * Hook contract (data-machine-events#325 / scope-token seam #160):
 *
 *     apply_filters(
 *         'data_machine_events_calendar_query_args',
 *         array $query_args, // WP_Query args about to be executed.
 *         array $input       // Raw ability input (scope, …, scope_token).
 *     );
 *
 * Scope-token model (#160). The previous implementation gated on
 * `is_page( 'my-shows' )` + `get_current_user_id()`. That gate is TRUE on
 * the initial PHP render but FALSE on the calendar block's prev/next
 * month REST re-fetch, so the `post__in` scoping silently dropped over
 * REST and the endpoint leaked the entire network-wide calendar.
 *
 * The fix: owner identity now travels as a DATA token, not page context.
 *   1. On the initial `/my-shows/` render we mint a non-spoofable HMAC
 *      token for the owner and hand it to data-machine-events via the
 *      generic `data_machine_events_calendar_scope_token` filter. DME
 *      emits it as `data-scope-token` on the calendar root and threads it
 *      into this filter's `$input['scope_token']`.
 *   2. The calendar frontend re-sends that token on every REST re-fetch,
 *      so DME hands it back into `$input['scope_token']` there too.
 *   3. This callback validates the token → owner user id and applies the
 *      `post__in` scoping regardless of page context.
 *
 * @package ExtraChillEvents
 * @since 0.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mint the owner scope token for the embedded My Shows calendar.
 *
 * Runs at server-render time via the generic
 * `data_machine_events_calendar_scope_token` seam. Page context IS
 * reliable here (this fires during the `/my-shows/` PHP render, never
 * over REST), so `is_page()` is the correct gate for EMISSION. We mint a
 * token for the logged-in owner so the constraint can be re-applied on
 * the REST round-trip where page context is gone.
 *
 * @param string $scope_token Incoming token (empty unless another consumer set one).
 * @param array  $context     Render context from DME (attributes, display_mode).
 * @return string Minted token, or the incoming value unchanged when not applicable.
 */
function ec_events_my_shows_emit_calendar_scope_token( $scope_token, $context = array() ) {
	unset( $context );

	// Only the embedded My Shows calendar should be scoped. Any other
	// calendar instance (public archives, etc.) must stay unscoped.
	if ( ! function_exists( 'is_page' ) || ! is_page( 'my-shows' ) ) {
		return $scope_token;
	}

	$user_id = get_current_user_id();
	if ( $user_id < 1 ) {
		return $scope_token;
	}

	return ec_events_my_shows_mint_scope_token( $user_id );
}
add_filter( 'data_machine_events_calendar_scope_token', 'ec_events_my_shows_emit_calendar_scope_token', 10, 2 );

/**
 * Inject a `post__in` constraint so the calendar block only renders
 * events the token's owner has marked attended.
 *
 * Active only when `$input['scope_token']` is a VALID owner token (minted
 * by `ec_events_my_shows_emit_calendar_scope_token()` and verified here).
 * No valid token → no scoping (the public calendar default). This is the
 * core of the #160 fix: scoping is driven by the verified data token, not
 * by `is_page()`, so it survives the prev/next month REST round-trip.
 *
 * When the user has zero tracked events we force `post__in = array( 0 )`
 * — `0` is never a valid post ID, so the query yields an empty result
 * set without throwing. WP_Query treats an empty `post__in` as "no
 * constraint", so we cannot pass `array()`; sentinel `[0]` is the
 * documented way to express "match nothing".
 *
 * @param array $query_args WP_Query args about to be executed.
 * @param array $input      Raw ability input; `scope_token` carries the owner token.
 * @return array Modified $query_args.
 */
function ec_events_my_shows_filter_calendar_query( $query_args, $input = array() ) {
	$token   = is_array( $input ) ? ( $input['scope_token'] ?? '' ) : '';
	$user_id = ec_events_my_shows_verify_scope_token( $token );

	if ( $user_id < 1 ) {
		// No valid owner token → leave the calendar unscoped (public
		// default). The forged / absent-token case lands here too, so a
		// public viewer can never read another user's tracked shows.
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
