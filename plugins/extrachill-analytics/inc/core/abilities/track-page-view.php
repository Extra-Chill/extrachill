<?php
/**
 * Track Page View Ability
 *
 * Write-side ability that records post-backed and route-level pageviews.
 * High-frequency hot-path — keeps logic minimal.
 *
 * @package ExtraChill\Analytics
 * @since 0.8.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the track-page-view ability.
 */
function extrachill_analytics_register_track_page_view_ability(): void {
	wp_register_ability(
		'extrachill/track-page-view',
		array(
			'label'               => __( 'Track Page View', 'extrachill-analytics' ),
			'description'         => __( 'Record a pageview for an eligible public route and increment legacy counters for valid post-backed views.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Optional post ID for a singular post-backed view.', 'extrachill-analytics' ),
						'minimum'     => 1,
					),
					'source_path'  => array(
						'type'        => 'string',
						'description' => __( 'Normalized query-free browser route path.', 'extrachill-analytics' ),
						'maxLength'   => 512,
					),
					'route_family' => array(
						'type'        => 'string',
						'description' => __( 'Bounded browser route family.', 'extrachill-analytics' ),
						'enum'        => extrachill_analytics_route_families(),
					),
					'referrer'     => array(
						'type'        => 'string',
						'description' => __( 'Optional raw client-side referrer (document.referrer). Normalized server-side to a host-only `referrer_host` (no query strings, no PII) on the pageview event. Empty for direct traffic.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'proof'        => array(
						'type'        => 'string',
						'description' => __( 'Cache-safe proof binding the rendered host, path, route family, and post.', 'extrachill-analytics' ),
						'maxLength'   => 64,
					),
				),
				'required'   => array( 'source_path', 'route_family', 'proof' ),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Confirmation object with recorded flag.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_page_view',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for track-page-view ability.
 *
 * Mirrors the existing REST handler: quick post-meta increment,
 * plus link-page daily-table action when applicable. Additionally writes a
 * `pageview` event row to the network-wide events table (carrying the
 * anonymous visitor_id when present, plus a normalized `referrer_host` when the
 * reader arrived from a different surface) so per-visitor retention history and
 * cross-surface / AI-citation landing attribution exist.
 * The post-meta bump is retained for back-compat with the theme view counter.
 *
 * @param array $input Input parameters.
 * @return array{recorded: bool}|WP_Error Confirmation or error.
 */
function extrachill_analytics_ability_track_page_view( array $input ) {
	$post_id      = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$referrer     = isset( $input['referrer'] ) ? (string) $input['referrer'] : '';
	$source_path  = isset( $input['source_path'] ) ? extrachill_analytics_normalize_route_path( $input['source_path'] ) : '';
	$route_family = isset( $input['route_family'] ) ? sanitize_key( $input['route_family'] ) : '';
	$proof        = isset( $input['proof'] ) ? (string) $input['proof'] : '';

	if ( '' === $source_path || ! in_array( $route_family, extrachill_analytics_route_families(), true ) ) {
		return new \WP_Error(
			'invalid_route',
			__( 'A normalized source_path and valid route_family are required.', 'extrachill-analytics' ),
			array( 'status' => 400 )
		);
	}

	$admission = extrachill_analytics_validate_pageview_write( $post_id, $source_path, $route_family, $proof );
	if ( is_wp_error( $admission ) ) {
		return $admission;
	}

	if ( $post_id > 0 && ! function_exists( 'ec_track_post_views' ) ) {
		return new \WP_Error(
			'function_missing',
			__( 'View tracking function not available.', 'extrachill-analytics' ),
			array( 'status' => 500 )
		);
	}

	// Resolve identity from this request's first-party cookie, never from the
	// request body. A render-time UUID would be persisted in anonymous full-page
	// cache HTML and replayed by every visitor receiving that cache entry. The
	// REST response can safely mint the HttpOnly cookie for a first cached visit
	// because response headers have not been sent yet. GPC/DNT returns an empty
	// value and keeps the pageview anonymous.
	$visitor_id            = '';
	$is_first_party_beacon = function_exists( 'extrachill_analytics_beacon_is_first_party' )
		&& extrachill_analytics_beacon_is_first_party();
	// Do not stitch a custom-domain request even if a browser permits its
	// third-party cookie. Cross-site beacons have no durable first-party
	// identity guarantee and intentionally remain anonymous.
	if ( $is_first_party_beacon && function_exists( 'extrachill_analytics_read_visitor_id' ) ) {
		$visitor_id = extrachill_analytics_read_visitor_id();
	}

	if (
		$is_first_party_beacon
		&& '' === $visitor_id
		&& function_exists( 'extrachill_analytics_get_or_mint_visitor_id' )
	) {
		$visitor_id = extrachill_analytics_get_or_mint_visitor_id();
	}

	// All-time view increment (post meta) is retained only for post-backed views.
	if ( $post_id > 0 ) {
		ec_track_post_views( $post_id );
	}

	// Write a deterministic pageview event row so per-visitor retention can be
	// queried. visitor_id is persisted only when it is a valid UUID v4; an empty
	// or opted-out value still records the pageview anonymously (NULL visitor_id),
	// keeping aggregate volume accurate. Bots are excluded via the canonical
	// classifier's UA-class signal so the retention table stays bot-resistant by
	// construction, using the one shared bot-UA pattern list.
	//
	// The pageview gate intentionally keys on the UA signal ONLY (not the full
	// strict verdict): this beacon is a browser-initiated fetch, and a
	// privacy-opted-out human (GPC/DNT, hence no ec_vid cookie) is still a real
	// pageview we want counted anonymously. Requiring the cookie here would drop
	// those legitimate humans. Per-visitor retention metrics already exclude
	// NULL-visitor_id rows downstream, so anonymous pageviews never distort them.
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
		: '';

	$is_bot = function_exists( 'extrachill_analytics_classify_user_agent' )
		&& 'browser' !== extrachill_analytics_classify_user_agent( $user_agent );

	if ( ! $is_bot && function_exists( 'extrachill_track_analytics_event' ) ) {
		$permalink  = $post_id > 0 ? get_permalink( $post_id ) : home_url( $source_path );
		$event_data = array(
			'route_family' => $route_family,
			'view_kind'    => $post_id > 0 ? 'post' : 'route',
		);
		if ( $post_id > 0 ) {
			$event_data['post_id'] = $post_id;
		}

		// Stamp a NORMALIZED, host-only referrer provenance on the pageview so
		// AI-citation (chatgpt.com / perplexity.ai / gemini.google.com), social,
		// search-engine, and cross-surface landing attribution is no longer
		// blind. The raw referrer MUST come from the client (document.referrer):
		// this beacon fires after page load, so the request's own HTTP Referer
		// is the article page itself, not the page the reader came from. We
		// reduce it to a host only — no query strings, no PII — and drop direct
		// + same-host referrers so the field means "a different surface sent
		// this reader here". Additive + backward-compatible: old rows simply
		// lack the field. Mirrors the #86 search-source stamping shape (capture
		// provenance at event time) and the 404 tracker's referer capture.
		if ( function_exists( 'extrachill_analytics_normalize_referrer_host' ) ) {
			$referrer_host = extrachill_analytics_normalize_referrer_host( $referrer );
			if ( '' !== $referrer_host ) {
				$event_data['referrer_host'] = $referrer_host;
			}
		}

		// The write-time classifier in events.php treats request_origin as a
		// bot signal for anonymous traffic, and a REST ability request like
		// this beacon is detected as 'rest'. That would stamp every beacon
		// pageview as is_bot:true even though this endpoint is, by construction,
		// a browser-initiated fetch that already passed the UA-only gate above.
		// Because the beacon knows its own caller class, supply the verdict
		// explicitly here so the generic classifier's REST-origin policy does
		// not condemn real human pageviews. The value is always false at this
		// point: the surrounding `if ( ! $is_bot )` already rejected bot/empty
		// UAs. See extrachill-analytics#115.
		$event_data['is_bot'] = false;

		extrachill_track_analytics_event(
			defined( 'EC_ANALYTICS_EVENT_PAGEVIEW' ) ? EC_ANALYTICS_EVENT_PAGEVIEW : 'pageview',
			$event_data,
			is_string( $permalink ) ? $permalink : '',
			$visitor_id
		);
	}

	// Link pages also fire the 90-day daily-table action.
	if ( $post_id > 0 && get_post_type( $post_id ) === 'artist_link_page' ) {
		do_action( 'extrachill_link_page_view_recorded', $post_id );
	}

	return array( 'recorded' => true );
}
