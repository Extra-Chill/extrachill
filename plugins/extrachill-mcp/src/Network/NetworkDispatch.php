<?php
/**
 * Cross-site ability routing built entirely on extrachill-network primitives.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Network;

defined( 'ABSPATH' ) || exit;

/**
 * Thin adapter over extrachill-network's ownership index and dispatcher.
 *
 * This class owns no routing logic of its own. `ec_get_ability_site_affinity()`
 * answers "which site owns this ability" and `ec_cross_site_rest_request()`
 * answers "how do I reach it" (in-process `switch_to_blog()` by default,
 * HTTP loopback when the local registry lacks the ability — see
 * extrachill-network's `docs/cross-site-routing.md`). Every method here
 * either delegates directly to one of those, or is guarded to degrade to
 * "local only" when extrachill-network is not active, per Extra-Chill/
 * extrachill-mcp#7.
 */
final class NetworkDispatch {

	/**
	 * Query value used for an empty `input` on GET/DELETE run-route hops.
	 *
	 * `http_build_query()` drops empty arrays, so `array()` never reaches the
	 * target over the HTTP-loopback transport, and an absent (null) input is
	 * rejected by any ability with an object input schema and no `default`.
	 *
	 * Since WordPress 7.1 the run route's `input` arg carries a
	 * `sanitize_callback` that coerces the raw value to the target ability's
	 * own input schema (`WP_REST_Abilities_V1_Run_Controller::
	 * coerce_input_to_schema()`). An empty string coerces to `array()` for an
	 * object schema (`rest_is_object( '' )`), so the target receives the same
	 * empty input a POST hop with `{}` would deliver. The earlier `','`
	 * encoding relied on the multi-type arg resolving it to an array and no
	 * longer survives that coercion (Extra-Chill/extrachill-mcp#26).
	 *
	 * Abilities that declare no input schema reject a non-null input, so
	 * run_ability() retries such a hop once without `input` at all.
	 */
	private const EMPTY_INPUT_QUERY_VALUE = '';

	/**
	 * Seconds a remote site's REST ability list stays cached (list_abilities()).
	 */
	private const LIST_CACHE_TTL = 600;

	/**
	 * Whether extrachill-network's site-key registry and dispatcher are
	 * available in this process.
	 */
	public static function available(): bool {
		return function_exists( 'ec_get_blog_ids' )
			&& function_exists( 'ec_get_blog_slug_by_id' )
			&& function_exists( 'ec_cross_site_rest_request' );
	}

	/**
	 * The current site's own key in extrachill-network's site-key vocabulary
	 * (e.g. 'main', 'events'), or null when extrachill-network is inactive or
	 * the current site has no registered key.
	 */
	public static function local_site_key(): ?string {
		if ( ! function_exists( 'ec_get_blog_slug_by_id' ) ) {
			return null;
		}

		$slug = ec_get_blog_slug_by_id( get_current_blog_id() );

		return is_string( $slug ) ? $slug : null;
	}

	/**
	 * Every known network site key other than the current site.
	 *
	 * Deliberately uses `ec_get_blog_ids()` (the hand-maintained, named
	 * site-key map), not `ec_get_all_site_ids()` (every active blog ID
	 * including ones with no site key) — a site this plugin cannot name is a
	 * site it cannot tag a search result with or route a call to.
	 *
	 * @return string[]
	 */
	public static function remote_sites(): array {
		if ( ! self::available() ) {
			return array();
		}

		$current_blog_id = (int) get_current_blog_id();
		$remote          = array();

		// self::available() already proved this exists, but static analysis
		// cannot follow that indirection. Re-checking inline keeps the type
		// honest without weakening the guard.
		if ( ! function_exists( 'ec_get_blog_ids' ) ) {
			return array();
		}

		foreach ( ec_get_blog_ids() as $site_key => $blog_id ) {
			if ( (int) $blog_id === $current_blog_id ) {
				continue;
			}

			$remote[] = (string) $site_key;
		}

		return $remote;
	}

	/**
	 * Resolve the site key that owns an ability.
	 *
	 * Returns null when the ability should be attempted locally: already
	 * registered here, extrachill-network is inactive, the name is unknown
	 * anywhere in the network index, or ownership is ambiguous with no
	 * `ec_ability_site_affinity_overrides` entry. See
	 * `ec_get_ability_site_affinity()`'s own docblock in extrachill-network
	 * for why those distinct cases share one answer — a local attempt
	 * naturally 404s for the "unknown anywhere" and "ambiguous" cases rather
	 * than this plugin guessing an owner.
	 */
	public static function resolve_owner( string $ability_name ): ?string {
		if ( ! function_exists( 'ec_get_ability_site_affinity' ) ) {
			return null;
		}

		return ec_get_ability_site_affinity( $ability_name );
	}

	/**
	 * Every site key the network ownership index records for an ability.
	 *
	 * `resolve_owner()` deliberately answers null for an ability registered
	 * on more than one site with no override — this method exposes the
	 * underlying candidate list so callers can validate an explicit `site`
	 * choice, or surface the candidates in an error instead of a misleading
	 * "not registered" (Extra-Chill/extrachill-mcp#22).
	 *
	 * @return string[] Owning site keys; empty when the index is unavailable
	 *                  or the ability is unknown to it.
	 */
	public static function owner_candidates( string $ability_name ): array {
		if ( ! function_exists( 'ec_get_network_ability_owner_index' ) ) {
			return array();
		}

		$index  = ec_get_network_ability_owner_index();
		$owners = $index[ $ability_name ] ?? array();

		return array_map( 'strval', array_keys( $owners ) );
	}

	/**
	 * Human-readable description of a WP_Error that keeps the error code
	 * visible.
	 *
	 * extrachill-network's HTTP-loopback transport preserves the upstream
	 * error CODE but replaces the upstream message with a generic
	 * "Cross-site request failed" — surfacing only the message therefore
	 * hides the one piece of real diagnostic information that survived the
	 * hop (Extra-Chill/extrachill-mcp#22). Appends the code unless the
	 * message already contains it.
	 */
	public static function describe_error( \WP_Error $error ): string {
		$message = $error->get_error_message();
		$code    = (string) $error->get_error_code();

		if ( '' === $code || false !== stripos( $message, $code ) ) {
			return $message;
		}

		return '' === $message ? $code : sprintf( '%s (%s)', $message, $code );
	}

	/**
	 * Run an ability on another site via its own
	 * `/wp-abilities/v1/abilities/{name}/run` route, entirely through
	 * `ec_cross_site_rest_request()` — identity, in-process vs. HTTP-loopback
	 * transport selection, and auth all stay owned there.
	 *
	 * The run route's expected HTTP method depends on the target ability's
	 * own annotations (`readonly` => GET, `destructive` + `idempotent` =>
	 * DELETE, otherwise POST — see WordPress core's
	 * `WP_REST_Abilities_V1_Run_Controller::validate_request_method()`),
	 * which this process cannot see for an ability it does not have
	 * registered locally. Rather than duplicating that annotation table or
	 * adding a lookup round-trip, this attempts the common case (POST) and
	 * retries with the next candidate only on core's own
	 * `rest_ability_invalid_method` error — the route itself is the source
	 * of truth for which method it wanted.
	 *
	 * When `$force_http` is true the whole attempt runs under
	 * extrachill-network's own `ec_cross_site_use_http_loopback` filter
	 * (the same pattern its `ec_fetch_remote_ability_names()` uses), so the
	 * target site answers from its OWN plugin bootstrap and registry. The
	 * default in-process transport switches blogs but keeps THIS process's
	 * registry loaded, which silently answers registry-introspection hops
	 * (`agents/ability-search`) with the source site's abilities instead of
	 * the target's (Extra-Chill/extrachill-mcp#22).
	 *
	 * @param array<string,mixed> $parameters Ability input.
	 * @param bool                $force_http Force the HTTP-loopback transport so the
	 *                                        target site's real plugin bootstrap answers.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run_ability( string $site_key, string $ability_name, array $parameters, bool $force_http = false ) {
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return new \WP_Error(
				'extrachill_mcp_network_unavailable',
				__( 'Cross-site dispatch is unavailable on this site.', 'extrachill-mcp' )
			);
		}

		$route    = '/wp-abilities/v1/abilities/' . $ability_name . '/run';
		$response = null;

		$force = static function (): bool {
			return true;
		};

		if ( $force_http ) {
			add_filter( 'ec_cross_site_use_http_loopback', $force, 10, 0 );
		}

		try {
			foreach ( array( 'POST', 'GET', 'DELETE' ) as $method ) {
				$args = 'POST' === $method
					? array( 'body' => array( 'input' => $parameters ) )
					: array( 'query' => array( 'input' => array() === $parameters ? self::EMPTY_INPUT_QUERY_VALUE : $parameters ) );

				$response = ec_cross_site_rest_request( $site_key, $method, $route, $args );

				// An ability with no input schema refuses the empty-input
				// encoding; retry once with no input at all.
				if ( 'POST' !== $method && array() === $parameters && is_wp_error( $response ) && 'ability_invalid_input' === $response->get_error_code() ) {
					$response = ec_cross_site_rest_request( $site_key, $method, $route, array() );
				}

				if ( ! is_wp_error( $response ) || 'rest_ability_invalid_method' !== $response->get_error_code() ) {
					return $response;
				}
			}
		} finally {
			if ( $force_http ) {
				remove_filter( 'ec_cross_site_use_http_loopback', $force, 10 );
			}
		}

		return $response;
	}

	/**
	 * List the abilities another site exposes over REST.
	 *
	 * Uses core's `GET /wp-abilities/v1/abilities` collection on the target
	 * site, which returns exactly the abilities registered with
	 * `show_in_rest: true` (the forced meta filter cannot be overridden by a
	 * caller) and only requires the `read` capability. That is the same set
	 * a cross-site ability-call can reach through the run route, so remote
	 * search never depends on a host-specific permission filter being
	 * registered on the target (Extra-Chill/extrachill-mcp#25).
	 *
	 * Forces the HTTP-loopback transport so the target answers from its own
	 * plugin bootstrap and registry (see run_ability()).
	 *
	 * The collection does not vary by caller (it needs only `read`), so each
	 * site's slimmed list is cached in a network transient for
	 * LIST_CACHE_TTL seconds (filterable via `extrachill_mcp_ability_list_ttl`,
	 * 0 disables). A full network listing is ~50 loopback requests; without
	 * the cache every search would pay for all of them. Errors are not cached.
	 *
	 * @param string $category Optional category filter.
	 * @param int    $max_pages Safety cap on collection pages fetched.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public static function list_abilities( string $site_key, string $category = '', int $max_pages = 10 ) {
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return new \WP_Error(
				'extrachill_mcp_network_unavailable',
				__( 'Cross-site dispatch is unavailable on this site.', 'extrachill-mcp' )
			);
		}

		$ttl       = (int) apply_filters( 'extrachill_mcp_ability_list_ttl', self::LIST_CACHE_TTL, $site_key );
		$cache_key = 'extrachill_mcp_abilities_' . md5( $site_key . '|' . $category );
		if ( $ttl > 0 ) {
			$cached = get_site_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$force = static function (): bool {
			return true;
		};
		add_filter( 'ec_cross_site_use_http_loopback', $force, 10, 0 );

		$abilities = array();

		try {
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$query = array(
					'per_page' => 100,
					'page'     => $page,
					'_fields'  => 'name,label,description,category,input_schema.required',
				);
				if ( '' !== $category ) {
					$query['category'] = $category;
				}

				$response = ec_cross_site_rest_request( $site_key, 'GET', '/wp-abilities/v1/abilities', array( 'query' => $query ) );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				if ( ! is_array( $response ) ) {
					break;
				}

				foreach ( $response as $record ) {
					if ( is_array( $record ) && is_string( $record['name'] ?? null ) ) {
						$abilities[] = $record;
					}
				}

				if ( count( $response ) < 100 ) {
					break;
				}
			}
		} finally {
			remove_filter( 'ec_cross_site_use_http_loopback', $force, 10 );
		}

		if ( $ttl > 0 ) {
			set_site_transient( $cache_key, $abilities, $ttl );
		}

		return $abilities;
	}

	/**
	 * Execute a locally-registered ability by name.
	 *
	 * Calls `WP_Ability::execute()` directly — the same core call agents-api's
	 * own `WP_Agent_Ability_Dispatcher::dispatch()` makes — rather than taking
	 * a hard class dependency on the agents-api vendor package, which this
	 * plugin does not composer-require (it is provided at runtime by the
	 * Agents API plugin; see `extrachill-mcp.php`'s `AGENTS_API_LOADED` gate).
	 * Same error code (`ability_not_found`) for a missing ability, so callers
	 * that already branch on that code behave identically either way.
	 *
	 * @param array<string,mixed> $parameters Ability input.
	 * @return mixed|\WP_Error
	 */
	public static function run_local_ability( string $ability_name, array $parameters ) {
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error(
				'abilities_api_missing',
				__( 'Abilities API is not loaded; cannot dispatch ability.', 'extrachill-mcp' )
			);
		}

		if ( ! wp_has_ability( $ability_name ) ) {
			return new \WP_Error(
				'ability_not_found',
				__( 'Ability is not registered.', 'extrachill-mcp' ),
				array( 'ability_name' => $ability_name )
			);
		}

		return wp_get_ability( $ability_name )->execute( $parameters );
	}
}
