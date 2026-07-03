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
 * This ability is the funnel's rightful reader: it iterates the ordered
 * `EC_ANALYTICS_ARTIST_ACTIVATION_STEPS` group and counts each step by
 * DISTINCT person, then computes step-to-step and overall conversion plus the
 * single biggest abandon step.
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
 * anonymous first-party `visitor_id` is the fallback for any row whose user_id
 * is NULL (opted-out context or a pre-login emit), which keeps the same
 * member's pre/post-login path stitched into one identity. The dedup key is
 * therefore `COALESCE(NULLIF(user_id,0), visitor_id)`; a row with neither is
 * excluded from the per-person count (it cannot be attributed to a person).
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
			'description'         => __( 'Returns the artist-signup activation funnel as a per-person funnel (DISTINCT user_id/visitor_id) with step-to-step and overall conversion and the biggest abandon step.', 'extrachill-analytics' ),
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
				'description' => __( 'Object with an ordered steps array (event_type, people, conversion_from_prev, conversion_from_top), the biggest_abandon_step, an anomalies array of friction signals (duplicate profile creation, re-registration attempts) each with DISTINCT people and raw event counts, and the exact UTC window.', 'extrachill-analytics' ),
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
 * Execute callback for get-activation-funnel ability.
 *
 * @param array $input Input parameters.
 * @return array Funnel data.
 */
function extrachill_analytics_ability_get_activation_funnel( $input ) {
	global $wpdb;

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

	$window_where  = array();
	$window_values = array();

	if ( $days > 0 ) {
		$since           = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$window_where[]  = 'created_at >= %s';
		$window_values[] = $since;
	}

	if ( $blog_id > 0 ) {
		$window_where[]  = 'blog_id = %d';
		$window_values[] = $blog_id;
	}

	// Per-person dedup key: prefer the authoritative user_id column, fall back
	// to the anonymous visitor_id so a pre-login emit still attributes to one
	// person. A row with neither identity is excluded (can't be a "person").
	$person_key = 'COALESCE(NULLIF(user_id, 0), visitor_id)';

	$step_rows = array();
	$top_count = 0;

	foreach ( $steps as $index => $event_type ) {
		$where  = array( 'event_type = %s', "{$person_key} IS NOT NULL AND {$person_key} != ''" );
		$values = array( sanitize_key( $event_type ) );

		if ( ! empty( $window_where ) ) {
			$where  = array_merge( $where, $window_where );
			$values = array_merge( $values, $window_values );
		}

		$where_clause = implode( ' AND ', $where );

		// Count DISTINCT people who reached this step — not rows. This is the
		// whole point: re-views of the form collapse to one person here.
		// phpcs:disable WordPress.DB.PreparedSQL -- $sql interpolates only code-defined identifiers ($person_key, $table) and a placeholder where_clause bound via prepare().
		$sql = "SELECT COUNT(DISTINCT {$person_key}) FROM {$table} WHERE {$where_clause}";

		$people = (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.PreparedSQL

		if ( 0 === $index ) {
			$top_count = $people;
		}

		$step_rows[] = array(
			'event_type' => $event_type,
			'people'     => $people,
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
	$friction_events = defined( 'EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS' )
		? EC_ANALYTICS_ARTIST_ACTIVATION_FRICTION_EVENTS
		: array( 'artist_profile_duplicate_created', 'user_reregistration_attempt' );

	$anomalies = array();

	foreach ( $friction_events as $event_type ) {
		$where  = array( 'event_type = %s', "{$person_key} IS NOT NULL AND {$person_key} != ''" );
		$values = array( sanitize_key( $event_type ) );

		if ( ! empty( $window_where ) ) {
			$where  = array_merge( $where, $window_where );
			$values = array_merge( $values, $window_values );
		}

		$where_clause = implode( ' AND ', $where );

		// Count DISTINCT people who hit this friction signal (people, not rows:
		// a member who created three duplicate profiles is one thrashing person)
		// AND the raw row volume (how much thrash that cohort generated).
		// phpcs:disable WordPress.DB.PreparedSQL -- both queries interpolate only code-defined identifiers and a placeholder where_clause bound via prepare().
		$people_sql = "SELECT COUNT(DISTINCT {$person_key}) FROM {$table} WHERE {$where_clause}";
		$events_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

		$anomalies[] = array(
			'event_type' => $event_type,
			'people'     => (int) $wpdb->get_var( $wpdb->prepare( $people_sql, $values ) ),
			'events'     => (int) $wpdb->get_var( $wpdb->prepare( $events_sql, $values ) ),
		);
		// phpcs:enable WordPress.DB.PreparedSQL
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
		'note'                 => 'Per-person funnel: each step is COUNT(DISTINCT COALESCE(NULLIF(user_id,0), visitor_id)), so multiple emits per person (e.g. re-views of the create form emitting artist_signup_started) collapse to one. Rows with neither identity are excluded. Counts here are intentionally NOT comparable to get-analytics-summary COUNT(*) raw volume. The anomalies array reports activation friction signals (duplicate profile creation, re-registration attempts) over the same window: people is DISTINCT persons hitting that signal, events is raw row volume.',
	);
}
