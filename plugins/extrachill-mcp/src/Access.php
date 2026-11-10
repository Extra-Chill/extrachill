<?php
/**
 * Access policy for the Extra Chill MCP surface.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may reach the Extra Chill MCP meta-abilities.
 *
 * `extrachill/ability-search` and `extrachill/ability-call` (see
 * src/Abilities/) are the advertised MCP tools. Their own
 * `permission_callback` is `Access::permission_callback()` below.
 * `extrachill/ability-call`'s local-site hop additionally runs the Agents API
 * substrate's `agents/ability-call` via `NetworkDispatch::run_local_ability()`,
 * which applies THAT ability's own `permission_callback` too — so this class
 * also widens `agents_ability_call_permission` from its upstream
 * `manage_options` default to the same audience, for exposed abilities only
 * (see filter_substrate_call_permission()). Otherwise an account that passes
 * the wrapper's gate would be denied one hop later by the substrate default.
 *
 * `agents_ability_search_permission` is deliberately NOT widened.
 * `extrachill/ability-search` reads the local registry directly through
 * Abilities\Exposure and never hops into `agents/ability-search`, while the
 * substrate search lists every registered ability — hidden server-side
 * primitives included — and is itself REST-visible. Widening it to a public
 * audience would disclose internal names and schemas for no consumer.
 *
 * None of this is the security boundary. `WP_Ability::execute()` calls
 * `check_permissions( $input )` on the TARGET ability itself before running
 * it (wp-includes/abilities-api/class-wp-ability.php), on whichever site
 * owns it, as the connected user — so `extrachill/ability-call` cannot reach
 * anything the caller could not already reach through REST or the CLI.
 * Venue-scoped booking abilities, for example, still resolve `booking_id` ->
 * venue grant -> per-action authorization on every call, on the events site,
 * as that user. The gate here governs reachability of the two meta-tools
 * and of the local hop, not what they may ultimately do.
 */
final class Access {

	/**
	 * Feature-rollout slug registered with extrachill-users'
	 * `ec_feature_available()` ladder (public / team / admin).
	 *
	 * Deliberately a feature slug, not a capability string: widening MCP
	 * access is a rollout decision (flip the live tier network option)
	 * rather than a code change.
	 */
	public const FEATURE = 'extrachill_mcp';

	/**
	 * Code-owned maximum tier. `public` means "any signed-in account" here:
	 * is_authorized() always requires a user, whatever the tier says.
	 */
	public const CEILING = 'public';

	/**
	 * Live tier used while the network option is unset.
	 *
	 * extrachill-users falls back to the ceiling when
	 * `ec_feature_tier_{feature}` does not exist. Raising the ceiling must not
	 * silently open the MCP on deploy, so the unset default stays at the
	 * previous effective tier and widening remains an explicit operator
	 * action (`wp site option update ec_feature_tier_extrachill_mcp public`).
	 */
	public const DEFAULT_TIER = 'team';

	/**
	 * Network option extrachill-users reads for the live tier.
	 */
	public const TIER_OPTION = 'ec_feature_tier_' . self::FEATURE;

	/**
	 * Capability used only when extrachill-users is not active, and so
	 * `ec_feature_available()` is unavailable.
	 *
	 * `access_studio` is what `ec_is_team_member()` resolves against — the
	 * platform's source of truth for the team tier. It previously pointed at
	 * `access_roadie`, which was removed with Roadie
	 * (Extra-Chill/extrachill-network#210) and which nothing read by then.
	 */
	private const FALLBACK_CAPABILITY = 'access_studio';

	/**
	 * Rollout resolver: `( string $feature, int $user_id ): bool`.
	 *
	 * @var callable|null
	 */
	private $feature_available;

	/**
	 * @param callable|null $feature_available Optional rollout resolver with
	 *                                         `ec_feature_available()`'s
	 *                                         signature. Defaults to
	 *                                         extrachill-users' function when
	 *                                         it is loaded; injectable so the
	 *                                         decision can be tested without
	 *                                         that plugin.
	 */
	public function __construct( ?callable $feature_available = null ) {
		$this->feature_available = $feature_available;
	}

	public function register(): void {
		add_filter( 'agents_ability_call_permission', array( $this, 'filter_substrate_call_permission' ), 10, 2 );
		add_filter( 'ec_feature_ceilings', array( $this, 'register_feature_ceiling' ) );
		add_filter( 'default_site_option_' . self::TIER_OPTION, array( $this, 'default_tier' ) );
	}

	/**
	 * Register the `extrachill_mcp` feature at a `public` ceiling.
	 *
	 * The live tier (a network option extrachill-users reads) can never
	 * exceed this code-owned ceiling — see extrachill-users'
	 * `ec_feature_tier()`. `public` is safe as a ceiling because the
	 * security boundary is the target ability's own `permission_callback`,
	 * run as the connected account on every call (see the class docblock),
	 * and because is_authorized() never admits an anonymous caller.
	 *
	 * @param mixed $ceilings Feature ceiling registry. Untrusted: any plugin
	 *                        can hook `ec_feature_ceilings` and return
	 *                        anything.
	 * @return array<string,string>
	 */
	public function register_feature_ceiling( $ceilings ): array {
		if ( ! is_array( $ceilings ) ) {
			$ceilings = array();
		}

		$ceilings[ self::FEATURE ] = self::CEILING;

		return $ceilings;
	}

	/**
	 * Default live tier while the network option is unset. See DEFAULT_TIER.
	 *
	 * Hooked on core's `default_site_option_{option}`, which only runs when
	 * the option does not exist, so a stored operator choice always wins.
	 *
	 * @return string
	 */
	public function default_tier(): string {
		return self::DEFAULT_TIER;
	}

	/**
	 * Permission callback for `extrachill/ability-search` and
	 * `extrachill/ability-call`.
	 *
	 * @param array<string,mixed> $input Meta-ability input (unused by the
	 *                                   decision itself; accepted so this can
	 *                                   be registered directly as an
	 *                                   ability's `permission_callback`).
	 */
	public function permission_callback( array $input = array() ): bool {
		return $this->is_authorized();
	}

	/**
	 * Filter callback for `agents_ability_call_permission`.
	 *
	 * `agents/ability-call` is itself REST-visible, so widening it for team
	 * members also opens it through core `/wp-abilities/v1`, bypassing
	 * `extrachill/ability-call`. Only widen it for abilities whose owner
	 * opted into an external surface (see Abilities\Exposure), so the
	 * substrate never becomes a route to internal primitives.
	 *
	 * @param mixed                $allowed Upstream decision (manage_options).
	 * @param array<string, mixed> $input   Meta-ability input.
	 */
	public function filter_substrate_call_permission( $allowed, $input = array() ): bool {
		if ( true === $allowed ) {
			return true;
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? $input['name'] : '';
		if ( '' === $name || ! Abilities\Exposure::is_locally_exposed( $name ) ) {
			return false;
		}

		return $this->is_authorized();
	}

	/**
	 * The actual decision, shared by both callbacks above.
	 *
	 * 1. A signed-in account is always required. Every MCP request carries a
	 *    bearer token, so "public" means "any account", never "anyone".
	 * 2. The rollout tier decides whether the MCP is live for that account
	 *    (admin -> team -> public, operator-controlled).
	 * 3. `extrachill_mcp_is_authorized` lets an entitlement layer (for
	 *    example a paid plan) narrow or widen the result per account without
	 *    changing this class. It cannot admit a signed-out caller.
	 */
	public function is_authorized(): bool {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return false;
		}

		$authorized = $this->rollout_allows( $user_id );

		/**
		 * Filter whether a signed-in account may reach the Extra Chill MCP.
		 *
		 * Runs after the rollout tier decision. Return false to deny (for
		 * example, an account without a required entitlement) or true to
		 * admit an account the tier would refuse (for example, a named
		 * partner during a team-tier rollout). Only a strict `true` admits.
		 *
		 * This gates reachability of the MCP meta-tools only. Every ability
		 * the account then calls still runs its own permission check as that
		 * account.
		 *
		 * @param bool     $authorized Decision from the rollout tier.
		 * @param \WP_User $user       The signed-in account.
		 */
		$filtered = apply_filters( 'extrachill_mcp_is_authorized', $authorized, wp_get_current_user() );

		return true === $filtered;
	}

	/**
	 * Rollout-tier decision for a signed-in account.
	 */
	private function rollout_allows( int $user_id ): bool {
		if ( null !== $this->feature_available ) {
			return (bool) call_user_func( $this->feature_available, self::FEATURE, $user_id );
		}

		if ( function_exists( 'ec_feature_available' ) ) {
			return (bool) ec_feature_available( self::FEATURE, $user_id );
		}

		return user_can( $user_id, self::FALLBACK_CAPABILITY );
	}
}
