<?php
/**
 * NetworkStats engine.
 *
 * The composable cross-site data layer every landing page composes from.
 * Owns the provider registry, per-metric caching, and error isolation; the
 * individual metrics live in MetricProvider implementations registered via the
 * `extrachill_network_stat_providers` filter.
 *
 * Design goals:
 *  - Composable: add a metric by registering a provider class via filter — no
 *    engine change. (Layer purity: any plugin contributes without this plugin
 *    knowing about it.)
 *  - Individually cached: each metric gets its own transient keyed by metric +
 *    provider cache_ttl(), so fast metrics (online users, ~5 min) and slow
 *    metrics (cross-site counts, ~1 hour) refresh independently. A landing
 *    page requesting only two metrics never warms the rest.
 *  - Honest: a provider that cannot resolve its source returns null; the engine
 *    caches and returns a structured "not available" marker, NEVER a fake 0.
 *
 * @package ExtraChillNetwork\NetworkStats
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-site network statistics engine.
 */
class NetworkStats {

	/**
	 * Transient key prefix for per-metric caches.
	 */
	const CACHE_PREFIX = 'ec_network_stat_';

	/**
	 * Resolved provider registry, keyed by metric key.
	 *
	 * @var array<string,MetricProvider>|null
	 */
	private static ?array $providers = null;

	/**
	 * Get the registered provider registry, keyed by metric key.
	 *
	 * Providers are contributed via the `extrachill_network_stat_providers`
	 * filter. Each entry may be a MetricProvider instance or a callable that
	 * returns one (lazy instantiation). Invalid entries are skipped.
	 *
	 * @return array<string,MetricProvider>
	 */
	public static function providers(): array {
		if ( null !== self::$providers ) {
			return self::$providers;
		}

		/**
		 * Filters the registered network-stat metric providers.
		 *
		 * Add a provider to expose a new cross-site metric. Each value may be
		 * a MetricProvider instance or a callable returning one. The array key
		 * is ignored — the provider's own key() is authoritative — so plugins
		 * can append without coordinating array keys.
		 *
		 * @param array $providers List of MetricProvider instances/callables.
		 */
		$registered = apply_filters( 'extrachill_network_stat_providers', array() );

		$resolved = array();
		foreach ( (array) $registered as $entry ) {
			if ( is_callable( $entry ) && ! $entry instanceof MetricProvider ) {
				$entry = call_user_func( $entry );
			}

			if ( ! $entry instanceof MetricProvider ) {
				continue;
			}

			$resolved[ $entry->key() ] = $entry;
		}

		self::$providers = $resolved;

		return self::$providers;
	}

	/**
	 * Reset the cached provider registry.
	 *
	 * Primarily for tests and for callers that register providers after the
	 * registry has already been resolved within a single request.
	 */
	public static function reset_providers(): void {
		self::$providers = null;
	}

	/**
	 * Get network metrics.
	 *
	 * @param string[] $keys Metric keys to resolve. Empty = all registered.
	 * @return array<string,array{key:string,label:string,value:int|array|null,available:bool}>
	 *               Map keyed by metric key. Each entry always reports
	 *               `available` so callers can distinguish a real zero from an
	 *               unavailable source.
	 */
	public static function get( array $keys = array() ): array {
		$providers = self::providers();

		if ( ! empty( $keys ) ) {
			$providers = array_intersect_key( $providers, array_flip( $keys ) );
		}

		$out = array();
		foreach ( $providers as $metric_key => $provider ) {
			$out[ $metric_key ] = self::resolve_metric( $provider );
		}

		return $out;
	}

	/**
	 * Resolve a single metric, using its per-metric cache.
	 *
	 * @param MetricProvider $provider Provider to resolve.
	 * @return array{key:string,label:string,value:int|array|null,available:bool}
	 */
	private static function resolve_metric( MetricProvider $provider ): array {
		$metric_key   = $provider->key();
		$transient_id = self::CACHE_PREFIX . $metric_key;

		$cached = get_transient( $transient_id );
		if ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
			return self::shape( $provider, $cached['value'] );
		}

		$value = $provider->value();

		// Honesty rule: null means "could not resolve" — cache it briefly so a
		// flapping/unavailable source does not get hammered every request, but
		// with a short floor so it recovers quickly once the source returns.
		$ttl = null === $value
			? min( $provider->cache_ttl(), 5 * MINUTE_IN_SECONDS )
			: $provider->cache_ttl();

		set_transient( $transient_id, array( 'value' => $value ), $ttl );

		return self::shape( $provider, $value );
	}

	/**
	 * Shape a resolved value into the public output envelope.
	 *
	 * @param MetricProvider $provider Provider.
	 * @param int|array|null $value    Resolved value.
	 * @return array{key:string,label:string,value:int|array|null,available:bool}
	 */
	private static function shape( MetricProvider $provider, $value ): array {
		return array(
			'key'       => $provider->key(),
			'label'     => $provider->label(),
			'value'     => $value,
			'available' => null !== $value,
		);
	}

	/**
	 * Invalidate the cached value for a single metric.
	 *
	 * Deletes ONLY the per-metric transient for the given key, forcing the next
	 * read to recompute via the provider. Use this when an event makes one
	 * metric stale (e.g. a user becoming active invalidates "online_users")
	 * without disturbing every other cached metric.
	 *
	 * The key is the provider's machine key; it does not need to be registered
	 * for the delete to run (the transient name is derived purely from the key),
	 * so callers in other plugins can invalidate without resolving the registry.
	 *
	 * @param string $key Metric machine key (e.g. "online_users").
	 * @return bool True if a cached value was deleted, false otherwise.
	 */
	public static function forget( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		return delete_transient( self::CACHE_PREFIX . $key );
	}

	/**
	 * Flush all cached network-stat metrics.
	 *
	 * Deletes the per-metric transient for every registered provider. Useful
	 * after a bulk content change or from a cache-flush ability.
	 *
	 * @return int Number of metric caches cleared.
	 */
	public static function flush(): int {
		$cleared = 0;
		foreach ( self::providers() as $metric_key => $provider ) {
			if ( delete_transient( self::CACHE_PREFIX . $metric_key ) ) {
				++$cleared;
			}
		}

		return $cleared;
	}
}
