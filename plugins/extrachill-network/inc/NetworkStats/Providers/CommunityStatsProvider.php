<?php
/**
 * Community-stats metric providers.
 *
 * DELEGATES to the existing `extrachill/community-get-stats` ability
 * (extrachill-community) — it does NOT re-query bbPress. That ability owns the
 * authoritative forums/topics/replies/users/upvotes counts.
 *
 * Resolution strategy (two paths, in order):
 *  1. Local fast path — if the ability is registered in THIS process (i.e. the
 *     landing page is composed on the community blog, or a warmer runs there),
 *     execute it directly. No HTTP, no switch.
 *  2. Cross-site HTTP loopback — extrachill-community is a PER-SITE plugin
 *     (active only on community.extrachill.com), so its ability callback is not
 *     loaded in the PHP process of any other site, and switch_to_blog() does
 *     NOT load it. The only transport that reaches it from another site is an
 *     HTTP loopback that bootstraps the community site's full plugin stack.
 *     We dispatch to the canonical extrachill/v1 platform route
 *     `GET /extrachill/v1/community/stats` (a thin extrachill-api wrapper that
 *     delegates to the extrachill/community-get-stats ability) via
 *     ec_cross_site_rest_request('community', ...). Route-affinity maps
 *     `/extrachill/v1/community/` to the community blog.
 *
 * Honesty: if neither path resolves (loopback fails, ability returns an error),
 * value() returns null — the engine marks the metric "not available" rather
 * than fabricating a zero.
 *
 * NOTE: the cross-site loopback path reaches the community-get-stats ability
 * through the canonical extrachill/v1/community/stats route in extrachill-api,
 * NOT the generic core Abilities /run endpoint. The ability itself stays off
 * the core REST surface (`show_in_rest => false`); the thin api route is the
 * only HTTP door. Until extrachill-api ships that route, off-blog callers get
 * an honest null while the local fast path still works on the community blog.
 *
 * The community stats payload is fetched once and shared between the two
 * community providers within a request via a static cache, so requesting both
 * `community_members` and `community_topics` triggers a single delegation.
 *
 * @package ExtraChillNetwork\NetworkStats\Providers
 * @since   1.19.0
 */

namespace ExtraChillNetwork\NetworkStats\Providers;

use ExtraChillNetwork\NetworkStats\AbstractMetricProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Base for providers that read a field from the community-get-stats payload.
 */
abstract class CommunityStatsProvider extends AbstractMetricProvider {

	/**
	 * Per-request memo of the community stats payload.
	 *
	 * Null means not yet fetched, false means fetched-but-unavailable
	 * (delegation failed), and an array is the resolved stats payload.
	 *
	 * @var array|false|null
	 */
	private static $payload = null;

	/**
	 * Field within the community-get-stats payload this provider exposes.
	 *
	 * @return string
	 */
	abstract protected function field(): string;

	/**
	 * {@inheritDoc}
	 */
	public function value() {
		$stats = self::community_stats();
		if ( ! is_array( $stats ) ) {
			return null;
		}

		$field = $this->field();

		return isset( $stats[ $field ] ) ? (int) $stats[ $field ] : null;
	}

	/**
	 * Resolve the community stats payload (memoized per request).
	 *
	 * @return array|false Stats payload, or false when unavailable.
	 */
	protected static function community_stats() {
		if ( null !== self::$payload ) {
			return self::$payload;
		}

		// Path 1: local ability (community-blog origin / warmer).
		//
		// The `extrachill/community-get-stats` ability is registered ONLY in the
		// community site's PHP process (extrachill-community is a per-site
		// plugin). Off-blog it is never loaded — not even under
		// switch_to_blog() — so an unconditional wp_get_ability() lookup misses
		// and trips WP_Abilities_Registry::get_registered()'s _doing_it_wrong
		// notice (~1 per off-blog call) before Path 2 resolves the value
		// correctly. Guard the fast path to the community blog so off-blog
		// callers skip straight to the HTTP loopback (Path 2) without the
		// log-noise. Uses the canonical ec_get_blog_id('community') idiom
		// established elsewhere in this plugin.
		$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
		if ( $community_blog_id && (int) get_current_blog_id() === (int) $community_blog_id && function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( 'extrachill/community-get-stats' );
			if ( $ability ) {
				$result = $ability->execute( array() );
				if ( is_array( $result ) ) {
					self::$payload = $result;
					return self::$payload;
				}
			}
		}

		// Path 2: cross-site HTTP loopback to the community site, which
		// bootstraps extrachill-community (registering the ability) and
		// extrachill-api (registering the canonical thin route). We hit the
		// canonical extrachill/v1 route — a namespace-relative path that
		// ec_cross_site_rest_resolve_route() auto-prefixes to
		// /extrachill/v1/community/stats — NOT the generic core Abilities /run
		// endpoint. It is a READABLE GET (no body). Force the loopback
		// transport: the ability callback is only defined inside the community
		// site's process, so the default in-process switch_to_blog dispatch
		// cannot satisfy it.
		if ( function_exists( 'ec_cross_site_rest_request' ) ) {
			$force_http = static function ( $use_http, $site_key ) {
				return 'community' === $site_key ? true : $use_http;
			};

			add_filter( 'ec_cross_site_use_http_loopback', $force_http, 10, 2 );
			try {
				$response = ec_cross_site_rest_request(
					'community',
					'GET',
					'/community/stats'
				);
			} finally {
				remove_filter( 'ec_cross_site_use_http_loopback', $force_http, 10 );
			}

			if ( ! is_wp_error( $response ) && is_array( $response ) ) {
				self::$payload = $response;
				return self::$payload;
			}
		}

		self::$payload = false;
		return self::$payload;
	}

	/**
	 * Reset the per-request memo (tests).
	 */
	public static function reset_payload(): void {
		self::$payload = null;
	}
}
