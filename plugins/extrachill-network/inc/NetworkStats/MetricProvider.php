<?php
/**
 * NetworkStats Metric Provider contract.
 *
 * A metric provider is a small, composable unit that resolves ONE cross-site
 * network metric (e.g. "how many published events", "how many artist profiles",
 * "how many people are online right now"). The NetworkStats engine owns
 * caching, error isolation, and aggregation; each provider only has to know how
 * to compute its own value.
 *
 * Providers are registered via the `extrachill_network_stat_providers` filter,
 * so any plugin on the network can contribute a metric WITHOUT this plugin
 * knowing about it (layer purity). extrachill-network ships the engine, the
 * interface, and the obvious cross-site CORE providers; strict per-plugin
 * ownership of individual metrics can migrate incrementally through the same
 * filter without any engine change.
 *
 * Honesty contract: a provider that cannot resolve its data source (e.g. the
 * target site does not exist, or a delegated ability is unavailable) MUST
 * return null from value() — never a fabricated zero. The engine surfaces null
 * to callers as a genuine "not available" marker so landing pages can tell the
 * difference between "zero of a thing" and "we could not count this".
 *
 * @package ExtraChillNetwork\NetworkStats
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every network-stat metric provider implements.
 */
interface MetricProvider {

	/**
	 * Stable machine key for this metric (e.g. "events_count").
	 *
	 * Used as the array key in NetworkStats::get() output and as part of the
	 * per-metric transient name. Must be unique across all registered
	 * providers and stable over time (it is a public contract).
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Human-readable label for this metric (e.g. "Upcoming Events").
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Resolve the metric value.
	 *
	 * Return an int for simple counts, an array for structured metrics, or
	 * NULL when the value genuinely cannot be resolved (unavailable site,
	 * missing delegated ability, etc.). NEVER return a fake 0 to paper over
	 * an unavailable source — return null and let the engine mark it
	 * "not available".
	 *
	 * @return int|array|null
	 */
	public function value();

	/**
	 * Cache lifetime for this metric, in seconds.
	 *
	 * Fast-moving metrics (online users) use a short TTL (~5 min); slow,
	 * expensive cross-site counts use a long TTL (~1 hour). The engine caches
	 * each metric individually under this TTL.
	 *
	 * @return int
	 */
	public function cache_ttl(): int;
}
