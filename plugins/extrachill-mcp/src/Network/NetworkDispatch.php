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
	 * Query-string encoding of an empty ability input object.
	 *
	 * `http_build_query()` drops empty arrays entirely, so an empty `input`
	 * sent as `array()` never reaches the target over extrachill-network's
	 * HTTP-loopback transport; core then validates the absent (null) input
	 * against the target ability's object schema and rejects it with
	 * `ability_invalid_input` (Extra-Chill/extrachill-mcp#22).
	 *
	 * A bare comma is the one query-encodable value WordPress core resolves
	 * to a genuinely empty array on the receiving side: the run route
	 * registers `input` as a multi-type arg, `rest_get_best_type_for_value()`
	 * types a `','` string as `array` (`rest_is_array()` ->
	 * `wp_parse_list( ',' )` -> `array()`), and
	 * `WP_REST_Request::sanitize_params()` writes that sanitized empty array
	 * back into the query params the run controller reads
	 * (`WP_REST_Abilities_V1_Run_Controller::get_input_from_request()`). The
	 * target ability therefore receives a real `array()` — identical to a
	 * POST hop with empty JSON input — on both the in-process and
	 * HTTP-loopback transports. An empty string does NOT work: core
	 * deliberately keeps `''` typed as `string` when `string` is among the
	 * arg's types (see `rest_get_best_type_for_value()`), and that raw
	 * string would reach strict-typed ability callbacks and throw.
	 */
	private const EMPTY_INPUT_QUERY_VALUE = ',';

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
