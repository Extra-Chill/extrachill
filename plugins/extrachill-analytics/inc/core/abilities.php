<?php
/**
 * Abilities API Integration
 *
 * Registers analytics tracking capabilities via the WordPress Abilities API.
 *
 * @package ExtraChill\Analytics
 * @since 0.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_analytics_register_category' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_abilities' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_summary_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_meta_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_404_summary_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_404_top_urls_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_404_patterns_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_drill_404_category_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_list_404_events_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_purge_404_events_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_404_top_ips_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_track_page_view_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_get_link_page_analytics_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_bridge_ctr_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_retention_stats_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_surface_growth_ability' );
add_action( 'wp_abilities_api_init', 'extrachill_analytics_register_activation_funnel_ability' );

/**
 * Register analytics ability category.
 */
function extrachill_analytics_register_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-analytics' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-analytics',
		array(
			'label'       => __( 'Extra Chill Analytics', 'extrachill-analytics' ),
			'description' => __( 'Analytics tracking capabilities', 'extrachill-analytics' ),
		)
	);
}

/**
 * Register analytics abilities.
 */
function extrachill_analytics_register_abilities() {
	wp_register_ability(
		'extrachill/track-analytics-event',
		array(
			'label'               => __( 'Track Analytics Event', 'extrachill-analytics' ),
			'description'         => __( 'Record an analytics event to the network-wide events table.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'event_type' => array(
						'type'        => 'string',
						'description' => __( 'Event type identifier (e.g., newsletter_signup, user_registration, search).', 'extrachill-analytics' ),
					),
					'event_data' => array(
						'type'        => 'object',
						'description' => __( 'Flexible payload data stored as JSON.', 'extrachill-analytics' ),
						'default'     => array(),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'URL of the page where the event occurred.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'visitor_id' => array(
						'type'        => 'string',
						'description' => __( 'Optional anonymous first-party visitor UUID v4. Stored only if well-formed; never PII. Empty when the visitor opted out (GPC/DNT).', 'extrachill-analytics' ),
						'default'     => '',
					),
				),
				'required'   => array( 'event_type' ),
			),
			'output_schema'       => array(
				'type'        => 'integer',
				'description' => __( 'Event ID on success, 0 on failure.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_track_event',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => false,
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
 * Execute callback for track-analytics-event ability.
 *
 * @param array $input Input parameters.
 * @return int Event ID on success, 0 on failure.
 */
function extrachill_analytics_ability_track_event( $input ) {
	if ( empty( $input['event_type'] ) ) {
		return 0;
	}

	$event_type = $input['event_type'];
	$event_data = isset( $input['event_data'] ) ? $input['event_data'] : array();
	$source_url = isset( $input['source_url'] ) ? $input['source_url'] : '';
	$visitor_id = isset( $input['visitor_id'] ) ? (string) $input['visitor_id'] : '';

	// Defense-in-depth: if a 'search' event arrives with a payload-shaped term,
	// reclassify it as 'search_attack' so real search metrics stay clean while
	// the attack stays visible. This catches any caller (current or future)
	// that forgets to pre-classify, including community/forum/artist search.
	if ( $event_type === 'search' && is_array( $event_data ) && ! empty( $event_data['search_term'] ) ) {
		$classification = extrachill_analytics_classify_search_payload( (string) $event_data['search_term'] );
		if ( null !== $classification ) {
			$event_type = 'search_attack';
			$event_data = array_merge(
				$event_data,
				array(
					'classification' => $classification['pattern_name'],
					'pattern_family' => $classification['pattern_family'],
					'matched_token'  => $classification['matched_token'],
					'ip'             => extrachill_analytics_get_client_ip(),
					'user_agent'     => extrachill_analytics_get_user_agent(),
				)
			);
		}
	}

	// Endpoint-automation gate for search demand signals. Community search is
	// hammered by automation firing legitimate-looking artist names from the
	// bare homepage (~7,800 searches in 2 days, all with no visitor cookie),
	// which inflates the zero-result "demand" list. The existing payload
	// classifier above only catches scanner/injection *terms*, not a bot
	// spraying real names. Stamp a deterministic `is_bot` flag on `search`
	// events so demand readers can exclude automation while volume stays
	// visible — the same "keep it, don't hide it" posture as search_attack.
	//
	// Signal: a known-bot user agent. A real human searches only after loading
	// a page (which mints the ec_vid cookie), so we additionally treat a
	// cookieless search as bot-suspect. visitor_id resolution runs in the
	// tracker; here we read the cookie presence directly to avoid re-minting.
	if ( 'search' === $event_type && is_array( $event_data ) ) {
		$user_agent = extrachill_analytics_get_user_agent();

		$ua_is_bot = function_exists( 'extrachill_analytics_is_bot' )
			&& extrachill_analytics_is_bot( $user_agent );

		$has_visitor_cookie = function_exists( 'extrachill_analytics_read_visitor_id' )
			&& '' !== extrachill_analytics_read_visitor_id();

		// Bot when the UA matches a known crawler, or when the search is
		// cookieless AND the UA is empty (headless/scripted clients routinely
		// send no User-Agent). A human who reached the search box has a cookie.
		$is_bot = $ua_is_bot || ( ! $has_visitor_cookie && '' === $user_agent );

		$event_data['is_bot'] = $is_bot;
	}

	/**
	 * Filter whether to track an analytics event.
	 *
	 * Allows event types/contexts to opt-out of tracking. For example,
	 * auto-subscriptions during registration aren't explicit user actions.
	 *
	 * @param bool   $should_track Whether to track this event. Default true.
	 * @param string $event_type   Event type identifier.
	 * @param array  $event_data   Event payload data.
	 * @param string $source_url   URL where event occurred.
	 */
	$should_track = apply_filters( 'extrachill_should_track_analytics_event', true, $event_type, $event_data, $source_url );

	if ( ! $should_track ) {
		return 0;
	}

	$result = extrachill_track_analytics_event(
		$event_type,
		$event_data,
		$source_url,
		$visitor_id
	);

	return $result ? $result : 0;
}
