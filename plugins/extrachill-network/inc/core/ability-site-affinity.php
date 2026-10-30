<?php
/**
 * Ability-to-site affinity resolution.
 *
 * ec_get_route_site_affinity() (see cross-site-rest.php) answers "which site
 * owns this REST route?" from a small, hand-maintained prefix map — route
 * prefixes partition cleanly by site. Ability names do not: on the live
 * network, 129 `extrachill/` abilities are registered on both main and events
 * while 120 are events-only, inside a single namespace. A static map would
 * rot on the next plugin change and fail silently (the ability would simply
 * appear not to exist). This file builds and caches a real index instead.
 *
 * Why the index cannot be built with switch_to_blog() alone: abilities are
 * registered by each plugin's own init/rest_api_init hooks, which only run
 * once per PHP process, for whichever plugins are active on the site that
 * bootstrapped THIS process. switch_to_blog() changes the DB/options context
 * but does not retroactively load a target site's per-site-activated plugins
 * (see ec_cross_site_rest_request_in_process()'s docblock for the identical
 * problem on the REST dispatch side). So:
 *
 * - The CURRENT site's abilities are read in-process via wp_get_abilities()
 *   — zero HTTP cost, always accurate for this process.
 * - Every OTHER site's abilities are read via a forced HTTP loopback GET to
 *   its own `/wp-abilities/v1/abilities` list route (paginated, trimmed to
 *   `_fields=name`), which bootstraps that site's own plugin stack and
 *   therefore its own registry. This reuses ec_cross_site_rest_request(); it
 *   does not change its dispatch behavior.
 *
 * Only REST-visible abilities (`show_in_rest => true`) are indexed. That
 * flag is also required by core's `/wp-abilities/v1/abilities/{name}/run`
 * route, which is the only dispatch path ec_cross_site_rest_request() knows
 * how to reach for an ability (see its `/run` special case). An ability
 * that isn't REST-visible cannot be invoked cross-site regardless of which
 * site owns it, so indexing it would answer a question no consumer can act
 * on.
 *
 * The built index is a raw name => {site_key => true} ownership map, cached
 * network-wide (global object-cache group — the data is the same regardless
 * of which site computed or reads it). ec_get_network_abilities() resolves
 * that raw map to a single owner per name: the `ec_ability_site_affinity_overrides`
 * filter wins first, then an unambiguous single owner, otherwise the name is
 * omitted — an ability registered on several sites with no override is not a
 * guess this primitive makes on a consumer's behalf.
 *
 * @package ExtraChillNetwork
 * @since 2.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Object-cache group holding the raw network ability ownership index. */
const EC_ABILITY_AFFINITY_CACHE_GROUP = 'extrachill_ability_affinity';

/** Object-cache key for the raw ownership index within the group above. */
const EC_ABILITY_AFFINITY_CACHE_KEY = 'network_ability_owners';

/**
 * Page size used when paginating a remote site's abilities list.
 *
 * 100 is the maximum `per_page` the core abilities list controller accepts
 * (see WP_REST_Abilities_V1_List_Controller::get_collection_params()).
 */
const EC_ABILITY_AFFINITY_BATCH_SIZE = 100;

/**
 * Make the ability ownership index network-global on multisite caches.
 *
 * The index answers "which site registers ability X" — a fact that is the
 * same no matter which site's process computed or reads it. Registering it
 * as a global group means the first site to warm the cache serves every
 * other site, instead of each of the ~10 sites paying the full cold-build
 * cost independently.
 *
 * @return void
 */
function ec_register_ability_affinity_cache_group() {
	if ( function_exists( 'wp_cache_add_global_groups' ) ) {
		wp_cache_add_global_groups( array( EC_ABILITY_AFFINITY_CACHE_GROUP ) );
	}
}
add_action( 'init', 'ec_register_ability_affinity_cache_group', 0 );

/**
 * Get the site key that owns a given ability.
 *
 * Returns null in three distinct cases, none of which are guesses:
 * - The ability is already registered on the current site (no affinity
 *   needed — dispatch it locally).
 * - The ability is not registered anywhere in the known network index.
 * - The ability is registered on more than one site and no
 *   `ec_ability_site_affinity_overrides` entry names a preferred owner.
 *
 * @since 2.11.0
 *
 * @param string $ability_name Fully-qualified ability name, e.g. 'extrachill/add-venue'.
 * @return string|null Site key (e.g. 'events'), or null when local/unknown/ambiguous.
 */
function ec_get_ability_site_affinity( string $ability_name ): ?string {
	// Already here — no affinity question to answer. This also short-circuits
	// the common case (network-activated abilities present on every site)
	// without ever touching the cache.
	if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
		return null;
	}

	$network_abilities = ec_get_network_abilities();

	return $network_abilities[ $ability_name ] ?? null;
}

/**
 * Get the resolved network ability ownership map.
 *
 * Reads the cached raw ownership index (name => {site_key => true}) and
 * resolves it to a single site key per ability:
 *
 * 1. `ec_ability_site_affinity_overrides` filter entry, if present.
 * 2. The sole owner, if the raw index recorded exactly one.
 * 3. Otherwise the ability is omitted from the result — ambiguous ownership
 *    with no override is an explicit "don't know", not a silent pick.
 *
 * Overrides are applied on every call, not baked into the cached index, so
 * changing the filter takes effect immediately without needing a cache
 * invalidation.
 *
 * @since 2.11.0
 *
 * @return array<string,string> Ability name => site key.
 */
function ec_get_network_abilities(): array {
	$owners = ec_get_network_ability_owner_index();

	/**
	 * Filters preferred ownership for abilities registered on more than one
	 * site (or to force a specific owner regardless of the built index).
	 *
	 * Keys are fully-qualified ability names, values are site keys understood
	 * by ec_get_blog_id() (e.g. 'events', 'community').
	 *
	 * @since 2.11.0
	 *
	 * @param array<string,string> $overrides Ability name => preferred site key.
	 */
	/** @var mixed $overrides Filter output is caller-supplied; any type is possible. */
	$overrides = apply_filters( 'ec_ability_site_affinity_overrides', array() );
	if ( ! is_array( $overrides ) ) {
		$overrides = array();
	}

	$resolved = array();

	foreach ( $owners as $name => $site_keys ) {
		/** @var mixed $override */
		$override = $overrides[ $name ] ?? null;
		if ( is_string( $override ) && '' !== $override ) {
			$resolved[ $name ] = $override;
			continue;
		}

		// $owners is built internally and validated at the cache boundary in
		// ec_get_network_ability_owner_index(), so the shape is already known
		// here — unlike the filter output above, which is caller-supplied.
		if ( 1 === count( $site_keys ) ) {
			$resolved[ $name ] = array_key_first( $site_keys );
		}

		// 2+ owners and no override: omitted on purpose (see docblock above).
	}

	return $resolved;
}

/**
 * Read the raw network ability ownership index, building it on a cache miss.
 *
 * @return array<string,array<string,bool>> Ability name => {site_key => true}.
 */
function ec_get_network_ability_owner_index(): array {
	$cached = wp_cache_get( EC_ABILITY_AFFINITY_CACHE_KEY, EC_ABILITY_AFFINITY_CACHE_GROUP );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$owners = ec_build_network_ability_owner_index();

	/**
	 * Filters the cache TTL for the network ability ownership index.
	 *
	 * The cold build touches every other site over HTTP loopback (see
	 * ec_build_network_ability_owner_index()), so this defaults to a full day
	 * — invalidation on plugin activation/deactivation and site add/remove
	 * (see the hooks below) keeps it correct without a short TTL doing the
	 * same job at a much higher steady-state cost.
	 *
	 * @since 2.11.0
	 *
	 * @param int $ttl Cache lifetime in seconds. Default DAY_IN_SECONDS.
	 */
	$ttl = (int) apply_filters( 'ec_ability_site_affinity_cache_ttl', DAY_IN_SECONDS );

	wp_cache_set( EC_ABILITY_AFFINITY_CACHE_KEY, $owners, EC_ABILITY_AFFINITY_CACHE_GROUP, $ttl );

	return $owners;
}

/**
 * Build the raw network ability ownership index (uncached).
 *
 * Cost: one in-process wp_get_abilities() call for the current site, plus
 * one forced HTTP loopback per OTHER active site (each paginated in batches
 * of EC_ABILITY_AFFINITY_BATCH_SIZE, `_fields=name` to keep each response
 * small). Measured against the live network (9 sites, ~2,500 REST-visible
 * abilities total spread unevenly from ~120 to ~560 per site): roughly 20-30
 * HTTP loopback requests for a single cold build, each spinning up a PHP-FPM
 * worker on the target site per ec_cross_site_rest_request_http()'s known
 * cost. This is the expensive path the caching above exists to avoid paying
 * more than once a day (or once per activation/deactivation/site change).
 *
 * @return array<string,array<string,bool>> Ability name => {site_key => true}.
 */
function ec_build_network_ability_owner_index(): array {
	$owners = array();

	$current_blog_id = (int) get_current_blog_id();
	$local_site_key  = function_exists( 'ec_get_blog_slug_by_id' ) ? ec_get_blog_slug_by_id( $current_blog_id ) : null;

	// Local fast path: whatever is already registered in this process, at
	// zero HTTP cost. Covers every network-activated plugin plus whatever is
	// activated only on this site.
	if ( $local_site_key && function_exists( 'wp_get_abilities' ) ) {
		foreach ( wp_get_abilities( array( 'meta' => array( 'show_in_rest' => true ) ) ) as $name => $ability ) {
			$owners[ $name ][ $local_site_key ] = true;
		}
	}

	if ( ! function_exists( 'ec_get_all_site_ids' ) || ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return $owners;
	}

	foreach ( ec_get_all_site_ids() as $blog_id ) {
		$blog_id = (int) $blog_id;
		if ( $blog_id === $current_blog_id ) {
			continue; // Already covered by the local fast path above.
		}

		$site_key = function_exists( 'ec_get_blog_slug_by_id' ) ? ec_get_blog_slug_by_id( $blog_id ) : null;
		if ( ! $site_key ) {
			continue; // Not part of the known site-key vocabulary — nothing to attribute ownership to.
		}

		foreach ( ec_fetch_remote_ability_names( $site_key ) as $name ) {
			$owners[ $name ][ $site_key ] = true;
		}
	}

	return $owners;
}

/**
 * Fetch every REST-visible ability name registered on a remote site.
 *
 * Forces HTTP loopback: the default in-process dispatch would switch_to_blog()
 * into the target and hit the SAME core route, but with THIS process's
 * registry still loaded, silently returning an incomplete list instead of a
 * 404 — the in-process fallback in ec_cross_site_rest_request() only
 * retries over HTTP on rest_no_route, which a route that always exists (a
 * core route) never returns. Forcing the loopback here is required for a
 * correct result, not an optimization.
 *
 * @param string $site_key Logical site key understood by ec_get_blog_id().
 * @return string[] Ability names registered on that site.
 */
function ec_fetch_remote_ability_names( string $site_key ): array {
	$names = array();

	$force_http = static function () {
		return true;
	};

	add_filter( 'ec_cross_site_use_http_loopback', $force_http, 10, 0 );

	try {
		$page = 1;

		do {
			$response = ec_cross_site_rest_request(
				$site_key,
				'GET',
				'/wp-abilities/v1/abilities',
				array(
					'query'   => array(
						'per_page' => EC_ABILITY_AFFINITY_BATCH_SIZE,
						'page'     => $page,
						'_fields'  => 'name',
					),
					'user_id' => ec_ability_affinity_system_user_id(),
				)
			);

			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				break;
			}

			foreach ( $response as $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
					$names[] = $item['name'];
				}
			}

			$fetched = count( $response );
			++$page;
		} while ( EC_ABILITY_AFFINITY_BATCH_SIZE === $fetched );
	} finally {
		remove_filter( 'ec_cross_site_use_http_loopback', $force_http, 10 );
	}

	return $names;
}

/**
 * Resolve a system identity for reading another site's ability list.
 *
 * Listing abilities requires `current_user_can( 'read' )` (core's
 * WP_REST_Abilities_V1_List_Controller::get_items_permissions_check()) —
 * this is administrative introspection with no per-request user in context
 * (a cron-triggered cold build, or a cache miss on an anonymous page view),
 * so there is no "current user" to forward. A network super admin has
 * `current_user_can()` return true for every capability on every site
 * (WP_User::has_cap() short-circuits via is_super_admin()) regardless of
 * blog membership, which makes it the correct bearer identity for a
 * read-only, non-sensitive, network-wide introspection call signed over the
 * existing internal-user HMAC channel (ec_cross_site_build_auth_headers()).
 * Same idiom as ec_get_super_admin_user_id() in extrachill-artist-platform's
 * platform-artist-provisioning.php.
 *
 * @return int Super admin user ID, or 1 as a last-resort fallback.
 */
function ec_ability_affinity_system_user_id(): int {
	if ( ! function_exists( 'get_super_admins' ) ) {
		return 1;
	}

	$super_admins = get_super_admins();
	if ( empty( $super_admins ) ) {
		return 1;
	}

	$user = function_exists( 'get_user_by' ) ? get_user_by( 'login', $super_admins[0] ) : null;

	return $user ? (int) $user->ID : 1;
}

/**
 * Flush the network ability ownership index.
 *
 * The index answers "which site registers ability X", a fact that changes
 * whenever a plugin is activated or deactivated anywhere on the network
 * (abilities appear/disappear with the plugin) or a site is added or
 * removed (a whole site's worth of abilities appear/disappear at once).
 * Because the cache group is global (see
 * ec_register_ability_affinity_cache_group()), a flush triggered by any one
 * site's event correctly invalidates the shared index for every site.
 *
 * @return void
 */
function ec_flush_network_ability_affinity_cache() {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( EC_ABILITY_AFFINITY_CACHE_GROUP );
	} elseif ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( EC_ABILITY_AFFINITY_CACHE_KEY, EC_ABILITY_AFFINITY_CACHE_GROUP );
	}
}
add_action( 'activated_plugin', 'ec_flush_network_ability_affinity_cache' );
add_action( 'deactivated_plugin', 'ec_flush_network_ability_affinity_cache' );
add_action( 'wp_initialize_site', 'ec_flush_network_ability_affinity_cache' );
add_action( 'wp_delete_site', 'ec_flush_network_ability_affinity_cache' );
