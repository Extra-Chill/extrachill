<?php
/**
 * NetworkStats bootstrap.
 *
 * Loads the engine + interface + core providers, registers the core providers
 * onto the `extrachill_network_stat_providers` filter, exposes the
 * `extrachill/get-network-stats` ability, and defines the thin
 * `ec_get_network_stats()` helper.
 *
 * The core providers ship here because they are obvious CROSS-SITE concerns
 * this network plugin already owns the blog map for. Per-plugin ownership of
 * individual metrics can migrate incrementally via the same public filter
 * without any engine change (layer purity).
 *
 * @package ExtraChillNetwork\NetworkStats
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/MetricProvider.php';
require_once __DIR__ . '/AbstractMetricProvider.php';
require_once __DIR__ . '/NetworkStats.php';
require_once __DIR__ . '/Providers/OnlineUsersProvider.php';
require_once __DIR__ . '/Providers/TotalMembersProvider.php';
require_once __DIR__ . '/Providers/CommunityStatsProvider.php';
require_once __DIR__ . '/Providers/CommunityMembersProvider.php';
require_once __DIR__ . '/Providers/CommunityTopicsProvider.php';
require_once __DIR__ . '/Providers/EventsCountProvider.php';
require_once __DIR__ . '/Providers/EventsCitiesProvider.php';
require_once __DIR__ . '/Providers/ArtistProfilesProvider.php';
require_once __DIR__ . '/Providers/WirePostsProvider.php';
require_once __DIR__ . '/Providers/TotalPostsProvider.php';

/**
 * Register the core cross-site metric providers.
 *
 * Each entry is a callable so providers are only instantiated when the
 * registry is actually resolved (lazy).
 *
 * @param array $providers Existing providers.
 * @return array Providers with the core set appended.
 */
function register_core_providers( array $providers ): array {
	$providers[] = static function () {
		return new Providers\OnlineUsersProvider();
	};
	$providers[] = static function () {
		return new Providers\TotalMembersProvider();
	};
	$providers[] = static function () {
		return new Providers\CommunityMembersProvider();
	};
	$providers[] = static function () {
		return new Providers\CommunityTopicsProvider();
	};
	$providers[] = static function () {
		return new Providers\EventsCountProvider();
	};
	$providers[] = static function () {
		return new Providers\EventsCitiesProvider();
	};
	$providers[] = static function () {
		return new Providers\ArtistProfilesProvider();
	};
	$providers[] = static function () {
		return new Providers\WirePostsProvider();
	};
	$providers[] = static function () {
		return new Providers\TotalPostsProvider();
	};

	return $providers;
}
add_filter( 'extrachill_network_stat_providers', __NAMESPACE__ . '\\register_core_providers' );

/**
 * Register the get-network-stats ability.
 *
 * Exposes NetworkStats over the universal ability surface so REST, chat, and
 * CLI consumers get it for free. Read-only and idempotent.
 */
function register_network_stats_ability(): void {
	wp_register_ability(
		'extrachill/get-network-stats',
		array(
			'label'               => __( 'Get Network Stats', 'extrachill-network' ),
			'description'         => __( 'Composable cross-site network statistics: events, cities, artist profiles, community members/topics, wire posts, online users, total members, total posts. Each metric is individually cached; unavailable sources return null (never a fake zero).', 'extrachill-network' ),
			'category'            => 'extrachill-network',
			'input_schema'        => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => array(
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional metric keys to resolve (e.g. ["events_count","artist_profiles"]). Omit for all registered metrics.', 'extrachill-network' ),
					),
				),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'       => 'object',
					'properties' => array(
						'key'       => array( 'type' => 'string' ),
						'label'     => array( 'type' => 'string' ),
						'value'     => array( 'type' => array( 'integer', 'object', 'array', 'null' ) ),
						'available' => array( 'type' => 'boolean' ),
					),
				),
			),
			'execute_callback'    => __NAMESPACE__ . '\\ability_get_network_stats',
			'permission_callback' => '__return_true',
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
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_network_stats_ability' );

/**
 * Execute callback for extrachill/get-network-stats.
 *
 * @param array $input Ability input.
 * @return array Map of metric key => metric envelope.
 */
function ability_get_network_stats( array $input ): array {
	$keys = array();
	if ( ! empty( $input['keys'] ) && is_array( $input['keys'] ) ) {
		$keys = array_map( 'sanitize_key', $input['keys'] );
	}

	return NetworkStats::get( $keys );
}
