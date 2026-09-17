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
	 * @param array<string,mixed> $parameters Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run_ability( string $site_key, string $ability_name, array $parameters ) {
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return new \WP_Error(
				'extrachill_mcp_network_unavailable',
				__( 'Cross-site dispatch is unavailable on this site.', 'extrachill-mcp' )
			);
		}

		$route    = '/wp-abilities/v1/abilities/' . $ability_name . '/run';
		$response = null;

		foreach ( array( 'POST', 'GET', 'DELETE' ) as $method ) {
			$args = 'POST' === $method
				? array( 'body' => array( 'input' => $parameters ) )
				: array( 'query' => array( 'input' => $parameters ) );

			$response = ec_cross_site_rest_request( $site_key, $method, $route, $args );

			if ( ! is_wp_error( $response ) || 'rest_ability_invalid_method' !== $response->get_error_code() ) {
				return $response;
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
