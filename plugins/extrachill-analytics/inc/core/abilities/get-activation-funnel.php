<?php
/**
 * Get Activation Funnel Ability
 *
 * Read-side ability that computes the artist-signup **activation funnel** —
 * the ordered start->finish steps a new member walks while building/claiming
 * an artist page — as a **per-person** funnel, so the conversion %s are
 * trustworthy.
 *
 * Why this ability has to dedup
 * -----------------------------
 * The funnel-step events are emitted at lifecycle points that can fire more
 * than once per person. In particular `artist_signup_started` is emitted on
 * EVERY render of the create-artist form (artist-platform render.php), so a
 * single indecisive visitor who reloads the form five times writes five rows.
 * The generic `extrachill/get-analytics-summary` reader is a deliberate plain
 * `COUNT(*)` (it documents that it applies "no dedup, DISTINCT, or
 * normalization"), which is correct for raw event volume but WRONG for a
 * funnel: counting rows instead of people inflates the top of the funnel and
 * makes every downstream conversion % too low.
 *
 * This ability is the funnel's rightful reader: it walks each person's events
 * in `created_at`, then row-ID order and counts a step only after that person
 * completed every preceding step. Repeated and out-of-order emits therefore
 * cannot inflate a downstream population.
 *
 * Friction / anomaly signals
 * --------------------------
 * Alongside the happy-path steps it also surfaces an `anomalies` section that
 * counts the activation FRICTION events (`EC_ANALYTICS_ARTIST_ACTIVATION_
 * FRICTION_EVENTS` — duplicate profile creation and re-registration attempts)
 * by DISTINCT person over the SAME window and dedup key. These make funnel
 * leaks visible: instead of a person silently vanishing between two steps, the
 * anomaly count records the thrash that explains the drop-off.
 *
 * Identity / dedup key
 * --------------------
 * Every funnel step is a logged-in action, so the dedicated `user_id` column
 * (populated server-side from `get_current_user_id()`) is the authoritative,
 * reliably-present per-person identity — confirmed against live rows where
 * `artist_profile_created` has a non-NULL user_id on 100% of rows. The
 * anonymous first-party `visitor_id` bridges pre-login events to a later row
 * carrying both that visitor and a user identity. As elsewhere in analytics,
 * `event_data.user_id` is preferred because registration is emitted before the
 * new account becomes the current user; the stored `user_id` column is the
 * fallback. A visitor observed with exactly one user is resolved to that user.
 * Rows with neither identity are excluded from per-person counts.
 *
 * The window, UTC handling, and `since`/`as_of` reproducibility echo
 * get-analytics-summary so a caller can reconcile the two readers exactly.
 *
 * @package ExtraChill\Analytics
 * @since 0.13.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-activation-funnel ability.
 */
function extrachill_analytics_register_activation_funnel_ability() {
	wp_register_ability(
		'extrachill/get-activation-funnel',
		array(
			'label'               => __( 'Get Activation Funnel', 'extrachill-analytics' ),
			'description'         => __( 'Returns the artist-signup activation funnel as an ordered per-person funnel (user_id with visitor_id fallback) with step-to-step and overall conversion and the biggest abandon step.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back. 0 for all time.', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter to a specific blog ID. 0 for network-wide.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with a chronologically ordered per-person steps array (event_type, people, conversion_from_prev, conversion_from_top), the biggest_abandon_step, an anomalies array of friction signals (duplicate profile creation, re-registration attempts) each with DISTINCT people and raw event counts, and the exact UTC window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_activation_funnel',
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
 * Read activation rows in bounded keyset pages.
 *
 * The upper UTC bound applies even to all-time reports. `created_at, id`
 * keyset pagination preserves deterministic equal-timestamp ordering without
 * materializing the report window in PHP.
 *
 * @param string   $table         Trusted analytics events table name.
 * @param string[] $event_types   Activation event types to read.
 * @param string[] $window_where  Prepared window/blog predicates.
 * @param array    $window_values Values for the window/blog predicates.
 * @param callable $consume       Receives each ordered page of event rows.
 */
function extrachill_analytics_activation_each_event_page( $table, $event_types, $window_where, $window_values, $consume ) {
	global $wpdb;

	$page_size          = 500;
	$event_placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
	$cursor_time        = null;
	$cursor_id          = 0;

	do {
		$where  = array_merge( array( "event_type IN ({$event_placeholders})" ), $window_where );
		$values = array_merge( array_map( 'sanitize_key', $event_types ), $window_values );

		if ( null !== $cursor_time ) {
			$where[]  = '(created_at > %s OR (created_at = %s AND id > %d))';
			$values[] = $cursor_time;
			$values[] = $cursor_time;
			$values[] = $cursor_id;
		}
		$values[] = $page_size;

		$where_clause = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- Bounded reporting page; identifiers are code-defined and every value is prepared.
		$sql  = "SELECT id, event_type, event_data, user_id, visitor_id, created_at
			FROM {$table}
			WHERE {$where_clause}
			ORDER BY created_at ASC, id ASC
			LIMIT %d";
		$page = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		if ( empty( $page ) ) {
			break;
		}

		$consume( $page );
		$page_count  = count( $page );
		$last        = end( $page );
		$cursor_time = (string) $last->created_at;
		$cursor_id   = (int) $last->id;
	} while ( $page_count === $page_size );
}

/**
 * Resolve the strongest user identity stored on an analytics event.
 *
 * Registration events carry the newly created user in event_data before that
 * account is authenticated, matching conversion-map outcome deduplication.
 *
 * @param object $row Analytics event row.
 * @return int Positive user ID, or 0 when unavailable.
 */
function extrachill_analytics_activation_event_user_id( $row ) {
	$data = is_array( $row->event_data )
		? $row->event_data
		: json_decode( (string) $row->event_data, true );
	$data = is_array( $data ) ? $data : array();

	return (int) ( $data['user_id'] ?? ( $row->user_id ?? 0 ) );
}

/**
 * Execute callback for get-activation-funnel ability.
 *
 * @param array $input Input parameters.
 * @return array Funnel data.
 */
function extrachill_analytics_ability_get_activation_funnel( $input ) {
	$days    = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$blog_id = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	$table = extrachill_analytics_events_table();

	// Ordered start->finish steps. Falls back to the literal sequence if the
	// constant group is somehow absent, so the reader never fatals on a partial
	// deploy (the constants ship in this same plugin, so this is belt-and-braces).
	$steps = defined( 'EC_ANALYTICS_ARTIST_ACTIVATION_STEPS' )
		? EC_ANALYTICS_ARTIST_ACTIVATION_STEPS
		: array( 'artist_signup_started', 'artist_profile_created', 'artist_profile_first_publish' );

	// Capture the exact UTC instant and the rolling lower bound, mirroring
	// get-analytics-summary so the two readers reconcile against the same window.
	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = '';

	$window_where  = array( 'created_at <= %s' );
	$window_values = array( $now_utc );

	if ( $days > 0 ) {
		$since           = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$window_where[]  = 'created_at >= %s';
		$window_values[] = $since;
	}

	if ( $blog_id > 0 ) {
		$window_where[]  = 'blog_id = %d';
		$window_values[] = $blog_id;
	}

	$friction_events = defined( 'EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS' )
		? EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS
		: array( 'artist_profile_duplicate_created', 'user_reregistration_attempt' );
	$event_types     = array_merge( $steps, $friction_events );

	// First pass: observe real visitor-to-user bridges. A visitor shared by more
	// than one user is deliberately left ambiguous rather than merging accounts.
	$visitor_users = array();
	extrachill_analytics_activation_each_event_page(
		$table,
		$event_types,
		$window_where,
		$window_values,
		static function ( $page ) use ( &$visitor_users ) {
			foreach ( $page as $row ) {
				$user_id    = extrachill_analytics_activation_event_user_id( $row );
				$visitor_id = trim( (string) $row->visitor_id );
				if ( $user_id > 0 && '' !== $visitor_id ) {
					$visitor_users[ $visitor_id ][ $user_id ] = true;
				}
			}
		}
	);

	$visitor_to_user = array();
	foreach ( $visitor_users as $visitor_id => $user_ids ) {
		if ( 1 === count( $user_ids ) ) {
			$visitor_to_user[ $visitor_id ] = (int) array_key_first( $user_ids );
		}
	}

	$step_index     = array_flip( $steps );
	$friction_index = array_flip( $friction_events );
	$ordered_counts = array_fill( 0, count( $steps ), 0 );
	$progress       = array();
	$anomaly_events = array_fill_keys( $friction_events, 0 );
	$anomaly_people = array_fill_keys( $friction_events, array() );

	// Second pass: resolve every row through the observed bridge and advance the
	// per-person state machine in deterministic stored event order.
	extrachill_analytics_activation_each_event_page(
		$table,
		$event_types,
		$window_where,
		$window_values,
		static function ( $page ) use ( &$ordered_counts, &$progress, &$anomaly_events, &$anomaly_people, $visitor_to_user, $step_index, $friction_index ) {
			foreach ( $page as $row ) {
				$user_id    = extrachill_analytics_activation_event_user_id( $row );
				$visitor_id = trim( (string) $row->visitor_id );

				if ( $user_id > 0 ) {
					$person_id = 'user:' . $user_id;
				} elseif ( '' !== $visitor_id && isset( $visitor_to_user[ $visitor_id ] ) ) {
					$person_id = 'user:' . $visitor_to_user[ $visitor_id ];
				} elseif ( '' !== $visitor_id ) {
					$person_id = 'visitor:' . $visitor_id;
				} else {
					continue;
				}

				$event_type = (string) $row->event_type;
				if ( isset( $step_index[ $event_type ] ) ) {
					$next_step = isset( $progress[ $person_id ] ) ? $progress[ $person_id ] : 0;
					if ( $step_index[ $event_type ] === $next_step ) {
						++$ordered_counts[ $next_step ];
						$progress[ $person_id ] = $next_step + 1;
					}
				} elseif ( isset( $friction_index[ $event_type ] ) ) {
					++$anomaly_events[ $event_type ];
					$anomaly_people[ $event_type ][ $person_id ] = true;
				}
			}
		}
	);

	$step_rows = array();
	$top_count = isset( $ordered_counts[0] ) ? $ordered_counts[0] : 0;

	foreach ( $steps as $index => $event_type ) {
		$step_rows[] = array(
			'event_type' => $event_type,
			'people'     => $ordered_counts[ $index ],
			'index'      => $index,
		);
	}

	// Compute conversion %s. conversion_from_prev = people(step)/people(prev);
	// conversion_from_top = people(step)/people(first step). Both are 0..1.
	$steps_out          = array();
	$prev_count         = 0;
	$biggest_abandon    = null;
	$biggest_abandon_pp = -1.0; // largest drop in percentage points, prev->this.

	foreach ( $step_rows as $row ) {
		$people = $row['people'];
		$index  = $row['index'];

		$from_prev = ( $index > 0 && $prev_count > 0 ) ? round( $people / $prev_count, 4 ) : ( 0 === $index ? 1.0 : 0.0 );
		$from_top  = $top_count > 0 ? round( $people / $top_count, 4 ) : 0.0;

		// Abandon = share of the previous step that did NOT advance to this one.
		if ( $index > 0 && $prev_count > 0 ) {
			$drop_pp = ( 1 - ( $people / $prev_count ) );
			if ( $drop_pp > $biggest_abandon_pp ) {
				$biggest_abandon_pp = $drop_pp;
				$biggest_abandon    = array(
					'from_step' => $step_rows[ $index - 1 ]['event_type'],
					'to_step'   => $row['event_type'],
					'dropped'   => $prev_count - $people,
					'drop_rate' => round( $drop_pp, 4 ),
				);
			}
		}

		$steps_out[] = array(
			'event_type'           => $row['event_type'],
			'people'               => $people,
			'conversion_from_prev' => $from_prev,
			'conversion_from_top'  => $from_top,
		);

		$prev_count = $people;
	}

	$last_count         = ! empty( $step_rows ) ? end( $step_rows )['people'] : 0;
	$overall_conversion = $top_count > 0 ? round( $last_count / $top_count, 4 ) : 0.0;

	// Friction / anomaly signals — failure modes that run ALONGSIDE the ordered
	// happy-path steps but are not steps themselves (a member creating a second
	// artist profile, or a known person re-registering a fresh account). These
	// make funnel leaks visible: instead of a person silently vanishing between
	// two steps, an anomaly count records the thrash. Counted by DISTINCT person
	// over the SAME window and dedup key as the steps so they reconcile exactly.
	$anomalies = array();

	foreach ( $friction_events as $event_type ) {
		$anomalies[] = array(
			'event_type' => $event_type,
			'people'     => count( $anomaly_people[ $event_type ] ),
			'events'     => $anomaly_events[ $event_type ],
		);
	}

	return array(
		'steps'                => $steps_out,
		'overall_conversion'   => $overall_conversion,
		'biggest_abandon_step' => $biggest_abandon,
		'anomalies'            => $anomalies,
		'days'                 => $days,
		'blog_id'              => $blog_id,
		'period'               => $days > 0
			? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
			: 'all time',
		// Exact UTC window the counts were computed over, reproducible with the
		// same created_at >= since bound used by get-analytics-summary.
		'since'                => $since,
		'as_of'                => $now_utc,
		'note'                 => 'Ordered per-person funnel: event_data.user_id (registration-safe) then stored user_id is authoritative; visitor_id is the anonymous fallback and is stitched to a user only when this window observes that visitor with exactly one user. Ambiguous visitors are not merged. Events are read in bounded keyset pages and ordered by created_at then event row ID; equal timestamps follow insertion order. A person counts at a step only after completing every prior step, and repeated emits count once. Rows with neither identity are excluded because their progression is unknowable. Counts are intentionally NOT comparable to get-analytics-summary COUNT(*) raw volume. The anomalies array remains independent reach over the same bounded stream.',
	);
}
