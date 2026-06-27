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

	// Delegate the Sendy read mechanics (DB aggregate + API fallback) to the
	// generic data-machine-business Sendy primitive. EC owns the policy: the
	// 1-hour caching and the output shape. The mechanism — the single canonical
	// Sendy client — lives one layer down. The Data Machine suite is a hard
	// runtime dependency, so there is no in-plugin fallback Sendy reader.
	$dmb = extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-metrics' );
	if ( ! $dmb ) {
		return new WP_Error(
			'sendy_primitive_unavailable',
			__( 'Subscriber stats require the Data Machine Business Sendy integration to be active.', 'extrachill-newsletter' )
		);
	}

	$result = $dmb->execute(
		array(
			'config' => extrachill_newsletter_sendy_dmb_config(),
			'metric' => 'subscribers',
			'source' => $source_pref,
		)
	);

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
 * Invalidate the cached stats payload.
 *
 * Call after subscribe/unsubscribe events if you want the dashboard or any
 * frontend exposure to update sooner than the 1-hour TTL. Safe no-op when
 * the transient is absent.
 */
function extrachill_newsletter_invalidate_subscriber_stats_cache() {
	delete_transient( EXTRACHILL_NEWSLETTER_STATS_CACHE_KEY );
}
