<?php
/**
 * Get CTA Funnel Ability
 *
 * Read-side ability for the `cta_click` / `form_submit` instrumentation
 * (Extra-Chill/extrachill-analytics#293). For a page path and/or a route
 * family, returns pageviews, then each CTA's DISTINCT clicking visitors, then
 * how many of those same visitors later convert (`user_registration`,
 * `newsletter_signup`, `artist_signup_started`) STRICTLY AFTER their click —
 * the same shape as the manual `/power` analysis that motivated this issue
 * (105 visitors, 2 registrations, no visibility into which CTA, if any, they
 * clicked first).
 *
 * Why per-CTA distinct visitors, not raw click counts
 * ----------------------------------------------------
 * A raw `COUNT(*)` of `cta_click` rows would let one indecisive visitor
 * clicking the same button five times outweigh five different visitors who
 * each clicked once. The clicks total is still reported (it is a real signal
 * — "engagement" vs "reach") but visitors/conversions are the trustworthy
 * per-person numbers, matching the same distinction
 * `extrachill/get-activation-funnel` draws between raw event volume and a
 * per-person funnel.
 *
 * Why "strictly after the click"
 * -------------------------------
 * A visitor's conversion event that predates their click on THIS page cannot
 * be caused by it. Each clicking visitor's EARLIEST click on a given CTA
 * within the window is compared against that same visitor_id's earliest
 * occurrence of each conversion type, with NO upper bound — a conversion can
 * legitimately happen well after the reporting window closes (a visitor who
 * clicked "Join" 10 days ago and registered yesterday still converted from
 * that click), so the conversion lookup is not re-bounded to the `days`
 * window the click itself is scoped to.
 *
 * Bot exclusion
 * -------------
 * Pageview and cta_click rows are included ONLY when explicitly stamped
 * `is_bot: false` at write time (rows missing the stamp are excluded), the
 * same policy `extrachill/get-outbound-clicks` uses.
 *
 * @package ExtraChill\Analytics
 * @since   0.38.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-cta-funnel ability.
 */
function extrachill_analytics_register_cta_funnel_ability() {
	wp_register_ability(
		'extrachill/get-cta-funnel',
		array(
			'label'               => __( 'Get CTA Funnel', 'extrachill-analytics' ),
			'description'         => __( 'Per-CTA funnel for a page path and/or route: pageviews, distinct clicking visitors per CTA, and later conversions (user_registration, newsletter_signup, artist_signup_started) by those same visitors, ordered strictly after their click.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'path'    => array(
						'type'        => 'string',
						'description' => __( 'Normalized page path to scope the funnel to, e.g. /power/. Matches the stored pageview/cta_click source_url exactly.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'route'   => array(
						'type'        => 'string',
						'description' => __( 'Route family to scope the funnel to, instead of or alongside a path.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'cta'     => array(
						'type'        => 'string',
						'description' => __( 'Limit the report to a single CTA id.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'days'    => array(
						'type'        => 'integer',
						'description' => __( 'Number of days to look back for pageviews and clicks. 0 for all time. Conversions are never bounded by this window (see the ability description).', 'extrachill-analytics' ),
						'default'     => 28,
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'Filter pageviews and clicks to a specific blog ID. 0 for network-wide.', 'extrachill-analytics' ),
						'default'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Object with pageviews, a ctas array (cta, label, dest, placement, clicks, visitors, conversions, converted_visitors, conversion_rate), and the window.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_cta_funnel',
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
 * Read every page of a single event type into memory.
 *
 * Volume for a single page's pageviews/clicks is low (this is a per-page
 * report, not a network-wide one), so a bounded paged fetch is clearer than
 * building a second raw-SQL reader; mirrors get-outbound-clicks.php.
 *
 * @param string $event_type Event type to read.
 * @param array  $query_args Shared query args (date_from, blog_id).
 * @return array<int,object> Decoded event rows.
 */
function extrachill_analytics_cta_funnel_fetch_events( $event_type, array $query_args ) {
	$args               = $query_args;
	$args['event_type'] = $event_type;
	$args['limit']      = 5000;
	$args['offset']     = 0;

	$rows = array();
	do {
		$page       = extrachill_get_analytics_events( $args );
		$page_count = count( (array) $page );
		if ( 0 === $page_count ) {
			break;
		}
		$rows            = array_merge( $rows, $page );
		$args['offset'] += $args['limit'];
	} while ( $page_count === $args['limit'] );

	return $rows;
}

/**
 * Whether a stored row is server-stamped human traffic.
 *
 * @param array $event_data Decoded event_data.
 * @return bool
 */
function extrachill_analytics_cta_funnel_is_human( array $event_data ) {
	return array_key_exists( 'is_bot', $event_data ) && false === $event_data['is_bot'];
}

/**
 * Execute callback for get-cta-funnel ability.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error Funnel report, or an error when the scope is missing/invalid.
 */
function extrachill_analytics_ability_get_cta_funnel( $input ) {
	$path       = isset( $input['path'] ) ? extrachill_analytics_normalize_route_path( (string) $input['path'] ) : '';
	$route      = isset( $input['route'] ) ? sanitize_key( (string) $input['route'] ) : '';
	$cta_filter = isset( $input['cta'] ) ? sanitize_text_field( (string) $input['cta'] ) : '';
	$days       = isset( $input['days'] ) ? (int) $input['days'] : 28;
	$blog_id    = isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0;

	if ( '' === $path && '' === $route ) {
		return new WP_Error(
			'missing_cta_funnel_scope',
			__( 'A path or route is required to scope the CTA funnel.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	if ( '' !== $route && ! in_array( $route, extrachill_analytics_route_families(), true ) ) {
		return new WP_Error(
			'invalid_cta_funnel_route',
			__( 'route must be a known route family.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	$query_args = array();
	if ( $days > 0 ) {
		$query_args['date_from'] = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
	}
	if ( $blog_id > 0 ) {
		$query_args['blog_id'] = $blog_id;
	}

	$matches_scope = static function ( $row_source_url, $row_route ) use ( $path, $route ) {
		if ( '' !== $path && (string) $row_source_url !== $path ) {
			return false;
		}
		if ( '' !== $route && (string) $row_route !== $route ) {
			return false;
		}
		return true;
	};

	// Pageviews: human-stamped rows matching the requested path/route.
	$pageviews = 0;
	foreach ( extrachill_analytics_cta_funnel_fetch_events( EC_ANALYTICS_EVENT_PAGEVIEW, $query_args ) as $row ) {
		$data = is_array( $row->event_data ) ? $row->event_data : array();
		if ( ! extrachill_analytics_cta_funnel_is_human( $data ) ) {
			continue;
		}
		$row_route = isset( $data['route_family'] ) ? (string) $data['route_family'] : '';
		if ( $matches_scope( $row->source_url, $row_route ) ) {
			++$pageviews;
		}
	}

	// CTA clicks: group by cta id, tracking each visitor's earliest click.
	$by_cta = array();
	foreach ( extrachill_analytics_cta_funnel_fetch_events( EC_ANALYTICS_EVENT_CTA_CLICK, $query_args ) as $row ) {
		$data = is_array( $row->event_data ) ? $row->event_data : array();
		if ( ! extrachill_analytics_cta_funnel_is_human( $data ) ) {
			continue;
		}
		$row_route = isset( $data['route'] ) ? (string) $data['route'] : '';
		if ( ! $matches_scope( $row->source_url, $row_route ) ) {
			continue;
		}

		$cta = isset( $data['cta'] ) ? (string) $data['cta'] : '';
		if ( '' === $cta || ( '' !== $cta_filter && $cta !== $cta_filter ) ) {
			continue;
		}

		if ( ! isset( $by_cta[ $cta ] ) ) {
			$by_cta[ $cta ] = array(
				'label'               => isset( $data['label'] ) ? (string) $data['label'] : '',
				'dest'                => isset( $data['dest'] ) ? (string) $data['dest'] : '',
				'placement'           => isset( $data['placement'] ) ? (string) $data['placement'] : '',
				'clicks'              => 0,
				'visitor_first_click' => array(),
			);
		}

		++$by_cta[ $cta ]['clicks'];

		$visitor_id = trim( (string) $row->visitor_id );
		if ( '' !== $visitor_id ) {
			$created = (string) $row->created_at;
			if (
				! isset( $by_cta[ $cta ]['visitor_first_click'][ $visitor_id ] )
				|| $created < $by_cta[ $cta ]['visitor_first_click'][ $visitor_id ]
			) {
				$by_cta[ $cta ]['visitor_first_click'][ $visitor_id ] = $created;
			}
		}
	}

	$period = $days > 0
		? gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . ' to ' . gmdate( 'Y-m-d' )
		: 'all time';

	if ( empty( $by_cta ) ) {
		return array(
			'pageviews' => $pageviews,
			'ctas'      => array(),
			'path'      => $path,
			'route'     => $route,
			'days'      => $days,
			'blog_id'   => $blog_id,
			'period'    => $period,
			'note'      => 'No cta_click events matched this path/route and window.',
		);
	}

	// Union of every clicking visitor across the matched CTAs, so the
	// conversion lookup below is a single bounded IN() read instead of one
	// query per CTA.
	$all_visitor_ids = array();
	foreach ( $by_cta as $cta_data ) {
		foreach ( array_keys( $cta_data['visitor_first_click'] ) as $visitor_id ) {
			$all_visitor_ids[ $visitor_id ] = true;
		}
	}

	$conversion_types = array(
		EC_ANALYTICS_EVENT_USER_REGISTRATION,
		EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP,
		EC_ANALYTICS_EVENT_ARTIST_SIGNUP_STARTED,
	);

	// visitor_id => event_type => earliest created_at. Deliberately NOT bound
	// by the days/blog_id window — a conversion can legitimately land after
	// the click-reporting window closes (see file docblock).
	$conversions_by_visitor = array();
	if ( ! empty( $all_visitor_ids ) ) {
		global $wpdb;
		$table = extrachill_analytics_events_table();

		foreach ( array_chunk( array_keys( $all_visitor_ids ), 200 ) as $chunk ) {
			$type_placeholders    = implode( ', ', array_fill( 0, count( $conversion_types ), '%s' ) );
			$visitor_placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- Bounded reporting read; identifiers are code-defined and every value is prepared.
			$sql    = "SELECT event_type, visitor_id, created_at
				FROM {$table}
				WHERE event_type IN ({$type_placeholders})
				AND visitor_id IN ({$visitor_placeholders})
				ORDER BY created_at ASC";
			$values = array_merge( $conversion_types, $chunk );
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
			// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $rows as $row ) {
				$visitor_id = trim( (string) $row->visitor_id );
				if ( '' === $visitor_id ) {
					continue;
				}
				$type    = (string) $row->event_type;
				$created = (string) $row->created_at;
				if (
					! isset( $conversions_by_visitor[ $visitor_id ][ $type ] )
					|| $created < $conversions_by_visitor[ $visitor_id ][ $type ]
				) {
					$conversions_by_visitor[ $visitor_id ][ $type ] = $created;
				}
			}
		}
	}

	$ctas_out = array();
	foreach ( $by_cta as $cta => $data ) {
		$visitor_clicks     = $data['visitor_first_click'];
		$visitors           = count( $visitor_clicks );
		$conversion_totals  = array_fill_keys( $conversion_types, 0 );
		$converted_visitors = array();

		foreach ( $visitor_clicks as $visitor_id => $click_time ) {
			if ( ! isset( $conversions_by_visitor[ $visitor_id ] ) ) {
				continue;
			}
			foreach ( $conversion_types as $type ) {
				if (
					isset( $conversions_by_visitor[ $visitor_id ][ $type ] )
					&& $conversions_by_visitor[ $visitor_id ][ $type ] > $click_time
				) {
					++$conversion_totals[ $type ];
					$converted_visitors[ $visitor_id ] = true;
				}
			}
		}

		$ctas_out[] = array(
			'cta'                => (string) $cta,
			'label'              => $data['label'],
			'dest'               => $data['dest'],
			'placement'          => $data['placement'],
			'clicks'             => $data['clicks'],
			'visitors'           => $visitors,
			'conversions'        => $conversion_totals,
			'converted_visitors' => count( $converted_visitors ),
			'conversion_rate'    => $visitors > 0 ? round( count( $converted_visitors ) / $visitors, 4 ) : 0.0,
		);
	}

	usort(
		$ctas_out,
		static function ( $a, $b ) {
			return $b['clicks'] <=> $a['clicks'];
		}
	);

	return array(
		'pageviews' => $pageviews,
		'ctas'      => $ctas_out,
		'path'      => $path,
		'route'     => $route,
		'days'      => $days,
		'blog_id'   => $blog_id,
		'period'    => $period,
		'note'      => 'Pageviews and cta_click rows are limited to server-stamped human events (is_bot=false explicitly). A CTA\'s "visitors" is the count of distinct visitor_ids who clicked it at least once in the window; "conversions" counts each of those visitors once per conversion type when that type\'s FIRST occurrence for that visitor is strictly after their FIRST click on this CTA, with no upper time bound (a delayed conversion still counts). converted_visitors/visitors across ANY of the three tracked types is conversion_rate.',
	);
}
