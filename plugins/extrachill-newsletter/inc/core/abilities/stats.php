<?php
/**
 * Subscriber Stats Ability
 *
 * Returns active subscriber counts across all Sendy lists. Primary path
 * queries the Sendy database directly for a single-round-trip aggregate;
 * falls back to the Sendy HTTP API (brands → lists → per-list counts)
 * if the database connection is unavailable.
 *
 * "Active" matches Sendy's own definition: confirmed = 1 AND unsubscribed = 0
 * AND bounced = 0 AND complaint = 0.
 *
 * @package ExtraChillNewsletter
 * @since 0.2.13
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_newsletter_register_stats_ability' );

/**
 * Register the subscriber-stats ability.
 */
function extrachill_newsletter_register_stats_ability() {
	wp_register_ability(
		'extrachill/newsletter-subscriber-stats',
		array(
			'label'               => __( 'Newsletter Subscriber Stats', 'extrachill-newsletter' ),
			'description'         => __( 'Total active Sendy subscribers across all lists, with per-list breakdown. Cached for 1 hour.', 'extrachill-newsletter' ),
			'category'            => 'extrachill-newsletter',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'force_refresh' => array(
						'type'        => 'boolean',
						'description' => __( 'Bypass the 1-hour cache and query fresh.', 'extrachill-newsletter' ),
					),
					'source'        => array(
						'type'        => 'string',
						'enum'        => array( 'auto', 'db', 'api' ),
						'description' => __( 'Where to read from. "auto" (default) prefers DB and falls back to API.', 'extrachill-newsletter' ),
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'total_active' => array( 'type' => 'integer' ),
					'list_count'   => array( 'type' => 'integer' ),
					'lists'        => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array(
									'description' => __( 'Integer primary key (DB path) or encrypted hash (API path).', 'extrachill-newsletter' ),
									'oneOf'       => array(
										array( 'type' => 'integer' ),
										array( 'type' => 'string' ),
									),
								),
								'name'   => array( 'type' => 'string' ),
								'active' => array( 'type' => 'integer' ),
							),
						),
					),
					'source'       => array( 'type' => 'string' ),
					'cached'       => array( 'type' => 'boolean' ),
					'generated_at' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'extrachill_newsletter_ability_subscriber_stats',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'show_in_rest' => true,
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
 * Cache key for the stats payload.
 */
const EXTRACHILL_NEWSLETTER_STATS_CACHE_KEY = 'extrachill_newsletter_subscriber_stats';

/**
 * Cache TTL in seconds (1 hour).
 */
const EXTRACHILL_NEWSLETTER_STATS_CACHE_TTL = HOUR_IN_SECONDS;

/**
 * Execute the subscriber-stats ability.
 *
 * @param array $input {
 *     @type bool   $force_refresh Bypass cache.
 *     @type string $source        'auto' | 'db' | 'api'.
 * }
 * @return array|WP_Error
 */
function extrachill_newsletter_ability_subscriber_stats( $input = array() ) {
	$force_refresh = ! empty( $input['force_refresh'] );
	$source_pref   = isset( $input['source'] ) ? (string) $input['source'] : 'auto';

	if ( ! in_array( $source_pref, array( 'auto', 'db', 'api' ), true ) ) {
		$source_pref = 'auto';
	}

	if ( ! $force_refresh ) {
		$cached = get_transient( EXTRACHILL_NEWSLETTER_STATS_CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['total_active'] ) ) {
			$cached['cached'] = true;
			return $cached;
		}
	}

	$result = null;

	if ( 'api' === $source_pref ) {
		$result = extrachill_newsletter_stats_via_api();
	} else {
		$result = extrachill_newsletter_stats_via_db();
		if ( is_wp_error( $result ) && 'auto' === $source_pref ) {
			$result = extrachill_newsletter_stats_via_api();
		}
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result['cached']       = false;
	$result['generated_at'] = gmdate( 'c' );

	set_transient(
		EXTRACHILL_NEWSLETTER_STATS_CACHE_KEY,
		$result,
		EXTRACHILL_NEWSLETTER_STATS_CACHE_TTL
	);

	return $result;
}

/**
 * Direct-DB path: single aggregate query against Sendy's subscribers table.
 *
 * @return array|WP_Error
 */
function extrachill_newsletter_stats_via_db() {
	if ( ! function_exists( 'extrachill_newsletter_get_sendy_db' ) ) {
		return new WP_Error( 'sendy_db_unavailable', 'Sendy DB helper not loaded.' );
	}

	$sendy_db = extrachill_newsletter_get_sendy_db();
	if ( is_wp_error( $sendy_db ) ) {
		return $sendy_db;
	}

	// Total active subscribers across the entire installation.
	// Matches Sendy's "active subscriber" semantics.
	$total = $sendy_db->get_var(
		"SELECT COUNT(*) FROM subscribers
		 WHERE confirmed = 1
		   AND unsubscribed = 0
		   AND bounced = 0
		   AND complaint = 0"
	);

	if ( null === $total ) {
		return new WP_Error( 'sendy_db_query_failed', 'Failed to query Sendy subscribers table.' );
	}

	// Per-list breakdown, including lists with zero active subscribers.
	$rows = $sendy_db->get_results(
		"SELECT l.id, l.name, COUNT(s.id) AS active
		 FROM lists l
		 LEFT JOIN subscribers s
		   ON s.list = l.id
		  AND s.confirmed = 1
		  AND s.unsubscribed = 0
		  AND s.bounced = 0
		  AND s.complaint = 0
		 GROUP BY l.id, l.name
		 ORDER BY active DESC, l.name ASC",
		ARRAY_A
	);

	if ( null === $rows ) {
		return new WP_Error( 'sendy_db_query_failed', 'Failed to query Sendy lists table.' );
	}

	$lists = array();
	foreach ( $rows as $row ) {
		$lists[] = array(
			'id'     => (int) $row['id'],
			'name'   => (string) $row['name'],
			'active' => (int) $row['active'],
		);
	}

	return array(
		'total_active' => (int) $total,
		'list_count'   => count( $lists ),
		'lists'        => $lists,
		'source'       => 'db',
	);
}

/**
 * API fallback path: walk brands → lists → per-list active count.
 *
 * Slower (N+M HTTP calls) but works without direct DB access.
 *
 * @return array|WP_Error
 */
function extrachill_newsletter_stats_via_api() {
	if ( ! function_exists( 'get_sendy_config' ) ) {
		return new WP_Error( 'sendy_config_unavailable', 'Sendy config helper not loaded.' );
	}

	$config = get_sendy_config();
	if ( empty( $config['api_key'] ) || empty( $config['sendy_url'] ) ) {
		return new WP_Error( 'sendy_config_incomplete', 'Sendy API key or URL not configured.' );
	}

	// 1. Brands.
	$brands_response = wp_remote_post(
		trailingslashit( $config['sendy_url'] ) . 'api/brands/get-brands.php',
		array(
			'timeout' => 15,
			'body'    => array( 'api_key' => $config['api_key'] ),
		)
	);

	if ( is_wp_error( $brands_response ) ) {
		return $brands_response;
	}

	$brands_body = wp_remote_retrieve_body( $brands_response );
	$brands      = json_decode( $brands_body, true );

	if ( ! is_array( $brands ) ) {
		return new WP_Error(
			'sendy_api_invalid_brands',
			'Unexpected response from Sendy get-brands API: ' . substr( (string) $brands_body, 0, 200 )
		);
	}

	$lists = array();

	// 2. Lists per brand.
	foreach ( $brands as $brand ) {
		if ( empty( $brand['id'] ) ) {
			continue;
		}

		$lists_response = wp_remote_post(
			trailingslashit( $config['sendy_url'] ) . 'api/lists/get-lists.php',
			array(
				'timeout' => 15,
				'body'    => array(
					'api_key'        => $config['api_key'],
					'brand_id'       => $brand['id'],
					'include_hidden' => 'yes',
				),
			)
		);

		if ( is_wp_error( $lists_response ) ) {
			continue;
		}

		$brand_lists = json_decode( wp_remote_retrieve_body( $lists_response ), true );
		if ( ! is_array( $brand_lists ) ) {
			continue;
		}

		foreach ( $brand_lists as $list ) {
			if ( empty( $list['id'] ) ) {
				continue;
			}
			// Sendy's get-lists API returns the encrypted hash ID, which is
			// what the active-subscriber-count endpoint requires. Keep it as
			// a string — it is NOT the integer primary key in the DB.
			$lists[] = array(
				'id'   => (string) $list['id'],
				'name' => isset( $list['name'] ) ? (string) $list['name'] : '',
			);
		}
	}

	// 3. Per-list active counts.
	$total = 0;
	$out   = array();

	foreach ( $lists as $list ) {
		$count_response = wp_remote_post(
			trailingslashit( $config['sendy_url'] ) . 'api/subscribers/active-subscriber-count.php',
			array(
				'timeout' => 15,
				'body'    => array(
					'api_key' => $config['api_key'],
					'list_id' => $list['id'],
				),
			)
		);

		$active = 0;
		if ( ! is_wp_error( $count_response ) ) {
			$body = trim( (string) wp_remote_retrieve_body( $count_response ) );
			if ( ctype_digit( $body ) ) {
				$active = (int) $body;
			}
		}

		$total += $active;
		$out[]  = array(
			'id'     => $list['id'],
			'name'   => $list['name'],
			'active' => $active,
		);
	}

	usort(
		$out,
		static function ( $a, $b ) {
			return $b['active'] <=> $a['active'];
		}
	);

	return array(
		'total_active' => $total,
		'list_count'   => count( $out ),
		'lists'        => $out,
		'source'       => 'api',
	);
}

/**
 * Invalidate the cached stats payload.
 *
 * Call after subscribe/unsubscribe events if you want the dashboard or any
 * frontend exposure to update sooner than the 1-hour TTL. Safe no-op when
 * the transient is absent.
 */
function extrachill_newsletter_invalidate_subscriber_stats_cache() {
	delete_transient( EXTRACHILL_NEWSLETTER_STATS_CACHE_KEY );
}
