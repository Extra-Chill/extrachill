<?php
/**
 * Redirect Conversion-Outcome Abilities
 *
 * Reads what happens AFTER a redirect fires. The redirect table only knows
 * hit counts; this answers the load-bearing question the rebuild thesis turns
 * on — do redirected (residual-SEO) visitors ENGAGE with the destination
 * platform surface, or bounce?
 *
 * How it is deterministic + bot-resistant:
 *
 *   The capture side (inc/core/redirects-db.php :: record_redirect_fire) writes
 *   one `redirect_fire` event per non-bot hit into the network-wide analytics
 *   events table, carrying the visitor's first-party `ec_vid` UUID. The reader
 *   stitches each fire to that SAME visitor's subsequent `pageview` rows (also
 *   first-party, bot-filtered, written only for real JS browsers). Counting
 *   distinct visitor outcomes is then a plain aggregate join — no GA sampling,
 *   no fingerprint, no PII. Fires with a NULL visitor_id (opted-out via GPC/DNT,
 *   or pre-cookie) are excluded from the per-visitor conversion metrics so they
 *   never inflate or deflate a rate.
 *
 * Per-rule outcome definitions (all within a post-fire session window):
 *   - hits     : authoritative counter from the redirect rules table.
 *   - measured : distinct visitors whose fire carried a usable visitor_id.
 *   - landed   : measured visitors with >= 1 pageview after the fire (the 301
 *                destination actually loaded a real, JS-executing page).
 *   - engaged  : landed visitors who went deeper — >= 2 post-fire pageviews,
 *                OR a post-fire pageview on a different blog_id (a real
 *                destination-surface interaction, not an instant bounce).
 *   - onward   : visitors who reached ANOTHER platform surface after the fire —
 *                a post-fire pageview on a blog_id different from where the fire
 *                was recorded. This is the cross-surface interconnection signal
 *                (e.g. blog → events.extrachill.com) the redirect table exists
 *                to create.
 *
 * Ranking is by CONVERSION (engaged rate), not raw hits — a rule with 132 hits
 * and 5% engagement is a different signal than 132 hits and 60%, and today they
 * look identical in `redirects list`.
 *
 * @package ExtraChill\SEO\Abilities
 * @since 0.10.0
 */

namespace ExtraChill\SEO\Abilities;

use ExtraChill\SEO\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default post-fire session window, in minutes, used to attribute pageviews to
 * a redirect fire. A pageview by the same visitor within this many minutes of
 * the fire is treated as part of the redirected session.
 */
const EXTRACHILL_SEO_CONVERSION_WINDOW_MINUTES = 30;

/**
 * Register redirect conversion-outcome abilities.
 *
 * Called from SEO_Abilities::register_abilities().
 */
function register_redirect_conversion_abilities() {

	wp_register_ability(
		'extrachill-seo/get-redirect-conversion-stats',
		array(
			'label'               => __( 'Get Redirect Conversion Stats', 'extrachill-seo' ),
			'description'         => __( 'Per-redirect conversion outcomes (landed / engaged / onward) computed from first-party, bot-filtered analytics events. Ranks rules by conversion, not just hits.', 'extrachill-seo' ),
			'category'            => 'extrachill-seo',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'           => array(
						'type'        => 'integer',
						'description' => __( 'Look-back window in days for redirect_fire events. Default 90.', 'extrachill-seo' ),
						'default'     => 90,
					),
					'rule_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Restrict to a single redirect rule ID. 0 for all rules.', 'extrachill-seo' ),
						'default'     => 0,
					),
					'min_fires'      => array(
						'type'        => 'integer',
						'description' => __( 'Only include rules with at least this many measured fires. Default 1.', 'extrachill-seo' ),
						'default'     => 1,
					),
					'window_minutes' => array(
						'type'        => 'integer',
						'description' => __( 'Post-fire session window (minutes) for attributing pageviews. Default 30.', 'extrachill-seo' ),
						'default'     => EXTRACHILL_SEO_CONVERSION_WINDOW_MINUTES,
					),
					'limit'          => array(
						'type'        => 'integer',
						'description' => __( 'Max rules to return, ranked by engaged rate. Default 50.', 'extrachill-seo' ),
						'default'     => 50,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with per-rule conversion rows, network totals, and the exact UTC window.', 'extrachill-seo' ),
			),
			'execute_callback'    => __NAMESPACE__ . '\\execute_get_redirect_conversion_stats',
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
 * Execute the get-redirect-conversion-stats ability.
 *
 * @param array $input Input parameters.
 * @return array Per-rule conversion stats plus totals and window metadata.
 */
function execute_get_redirect_conversion_stats( $input ) {
	global $wpdb;

	$days           = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 90;
	$rule_id        = isset( $input['rule_id'] ) ? (int) $input['rule_id'] : 0;
	$min_fires      = isset( $input['min_fires'] ) ? max( 0, (int) $input['min_fires'] ) : 1;
	$window_minutes = isset( $input['window_minutes'] ) ? max( 1, (int) $input['window_minutes'] ) : EXTRACHILL_SEO_CONVERSION_WINDOW_MINUTES;
	$limit          = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;

	// The analytics events table is the canonical first-party visitor history.
	// Without it there is nothing to measure against.
	if ( ! function_exists( 'extrachill_analytics_events_table' ) ) {
		return array(
			'error'  => 'extrachill-analytics is not active; no first-party events to read.',
			'rules'  => array(),
			'totals' => array(),
			'days'   => $days,
		);
	}

	$events_table   = extrachill_analytics_events_table();
	$redirect_table = Core\extrachill_seo_redirects_table();

	$pageview_type = defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview';
	$fire_type     = 'redirect_fire';

	$now_utc = gmdate( 'Y-m-d H:i:s' );
	$since   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	// ---------------------------------------------------------------------
	// Pull measurable fires: one row per redirect_fire event that carried a
	// usable visitor_id, in window. rule_id comes out of the event_data JSON
	// (written by the capture side). Anonymous (NULL visitor_id) fires are
	// excluded from per-visitor outcome math by construction.
	// ---------------------------------------------------------------------
	$fire_where  = array(
		'event_type = %s',
		"visitor_id IS NOT NULL AND visitor_id != ''",
		'created_at >= %s',
	);
	$fire_values = array( $fire_type, $since );

	if ( $rule_id > 0 ) {
		$fire_where[]  = "JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.rule_id')) = %d";
		$fire_values[] = $rule_id;
	}

	$fire_where_clause = implode( ' AND ', $fire_where );

	// For each fire we compute, via a correlated lookup against the SAME
	// visitor's pageviews in the [fire, fire + window] interval:
	// - post_views     : count of post-fire pageviews by this visitor.
	// - other_surfaces : count of those pageviews on a different blog_id than
	// the fire (cross-surface onward signal).
	// Grouping then rolls per-visitor outcomes up to per-rule rates.
	//
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names are internal helpers; all user input is bound via %s/%d placeholders below.
	$sql = "SELECT
			f.rule_id AS rule_id,
			COUNT(*) AS measured,
			SUM(CASE WHEN f.post_views >= 1 THEN 1 ELSE 0 END) AS landed,
			SUM(CASE WHEN f.post_views >= 2 OR f.other_surfaces >= 1 THEN 1 ELSE 0 END) AS engaged,
			SUM(CASE WHEN f.other_surfaces >= 1 THEN 1 ELSE 0 END) AS onward
		FROM (
			SELECT
				fire.id AS fire_id,
				CAST(JSON_UNQUOTE(JSON_EXTRACT(fire.event_data, '$.rule_id')) AS UNSIGNED) AS rule_id,
				fire.visitor_id AS visitor_id,
				(
					SELECT COUNT(*)
					FROM {$events_table} pv
					WHERE pv.event_type = %s
						AND pv.visitor_id = fire.visitor_id
						AND pv.created_at >= fire.created_at
						AND pv.created_at <= DATE_ADD(fire.created_at, INTERVAL %d MINUTE)
				) AS post_views,
				(
					SELECT COUNT(*)
					FROM {$events_table} pv2
					WHERE pv2.event_type = %s
						AND pv2.visitor_id = fire.visitor_id
						AND pv2.created_at >= fire.created_at
						AND pv2.created_at <= DATE_ADD(fire.created_at, INTERVAL %d MINUTE)
						AND pv2.blog_id <> fire.blog_id
				) AS other_surfaces
			FROM {$events_table} fire
			WHERE {$fire_where_clause}
		) AS f
		WHERE f.rule_id > 0
		GROUP BY f.rule_id
		HAVING measured >= %d";
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

	// Bind order matches placeholder order: the two subqueries first (each takes
	// pageview_type + window_minutes), then the outer fire WHERE values, then the
	// HAVING min_fires.
	$bind = array(
		$pageview_type,
		$window_minutes,
		$pageview_type,
		$window_minutes,
	);
	$bind = array_merge( $bind, $fire_values, array( $min_fires ) );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built from internal table names with all user input bound via the $bind placeholder array; this is an on-demand analytics read, not cacheable.
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $bind ) );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// ---------------------------------------------------------------------
	// Decorate each rule row with its from/to/hit_count from the rules table,
	// compute rates, and rank by engaged rate (then measured volume).
	// ---------------------------------------------------------------------
	$rule_ids = array();
	foreach ( (array) $rows as $row ) {
		$rule_ids[] = (int) $row->rule_id;
	}

	$rule_meta = array();
	if ( ! empty( $rule_ids ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $rule_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name; ids bound via %d placeholders.
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, from_url, to_url, hit_count FROM {$redirect_table} WHERE id IN ( {$placeholders} )",
				$rule_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $meta_rows as $meta ) {
			$rule_meta[ (int) $meta->id ] = $meta;
		}
	}

	$rules          = array();
	$total_measured = 0;
	$total_landed   = 0;
	$total_engaged  = 0;
	$total_onward   = 0;

	foreach ( (array) $rows as $row ) {
		$rid      = (int) $row->rule_id;
		$measured = (int) $row->measured;
		$landed   = (int) $row->landed;
		$engaged  = (int) $row->engaged;
		$onward   = (int) $row->onward;

		$meta = isset( $rule_meta[ $rid ] ) ? $rule_meta[ $rid ] : null;

		$total_measured += $measured;
		$total_landed   += $landed;
		$total_engaged  += $engaged;
		$total_onward   += $onward;

		$rules[] = array(
			'rule_id'      => $rid,
			'from_url'     => $meta ? (string) $meta->from_url : '',
			'to_url'       => $meta ? (string) $meta->to_url : '',
			'hits'         => $meta ? (int) $meta->hit_count : 0,
			'measured'     => $measured,
			'landed'       => $landed,
			'engaged'      => $engaged,
			'onward'       => $onward,
			'landed_rate'  => $measured > 0 ? round( $landed / $measured, 4 ) : 0.0,
			'engaged_rate' => $measured > 0 ? round( $engaged / $measured, 4 ) : 0.0,
			'onward_rate'  => $measured > 0 ? round( $onward / $measured, 4 ) : 0.0,
		);
	}

	// Rank by engaged rate desc, then measured volume desc — conversion first.
	usort(
		$rules,
		function ( $a, $b ) {
			if ( $a['engaged_rate'] === $b['engaged_rate'] ) {
				return $b['measured'] <=> $a['measured'];
			}
			return $b['engaged_rate'] <=> $a['engaged_rate'];
		}
	);

	if ( count( $rules ) > $limit ) {
		$rules = array_slice( $rules, 0, $limit );
	}

	return array(
		'rules'          => $rules,
		'totals'         => array(
			'rules_measured' => count( $rules ),
			'measured'       => $total_measured,
			'landed'         => $total_landed,
			'engaged'        => $total_engaged,
			'onward'         => $total_onward,
			'landed_rate'    => $total_measured > 0 ? round( $total_landed / $total_measured, 4 ) : 0.0,
			'engaged_rate'   => $total_measured > 0 ? round( $total_engaged / $total_measured, 4 ) : 0.0,
			'onward_rate'    => $total_measured > 0 ? round( $total_onward / $total_measured, 4 ) : 0.0,
		),
		'days'           => $days,
		'window_minutes' => $window_minutes,
		'period'         => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' ),
		'since'          => $since,
		'as_of'          => $now_utc,
		'definitions'    => array(
			'landed'  => 'Measured visitors with >= 1 pageview within the window after the fire.',
			'engaged' => 'Landed visitors with >= 2 post-fire pageviews OR a pageview on another platform surface (blog).',
			'onward'  => 'Visitors who reached another platform surface (different blog_id) after the fire.',
		),
		'note'           => 'Deterministic + bot-filtered: each redirect_fire is captured server-side only for non-bot requests and stitched to subsequent pageviews by the anonymous first-party ec_vid visitor_id (UUID v4, no PII). Opted-out (GPC/DNT) fires have NULL visitor_id and are excluded from per-visitor metrics. hits is the authoritative rule counter; measured is the subset with a usable visitor_id.',
	);
}
