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
 * `permission_callback` is `Access::permission_callback()` below. Their
 * local-site hop additionally hits the Agents API substrate abilities
 * (`agents/ability-search`, `agents/ability-call`) via
 * `NetworkDispatch::run_local_ability()`, which runs THOSE abilities' own
 * `permission_callback` too — so this class also widens
 * `agents_ability_search_permission` / `agents_ability_call_permission` from
 * their upstream `manage_options` default to the same audience, otherwise a
 * team member who passes the wrapper's gate would be denied one hop later by
 * the substrate ability's own default.
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
	 * access later is then a rollout decision (flip the live tier from
	 * wp-admin) rather than a code change, which matters because a caller who
	 * can reach ability-search can enumerate every registered ability name
	 * and JSON schema on the network — that is information disclosure even
	 * when nothing is executable, so widening below team tier deserves to be
	 * deliberate.
	 */
	private const FEATURE = 'extrachill_mcp';

	/**
	 * Capability used only when extrachill-users is not active. Matches the
	 * tier this plugin used before `ec_feature_available()` existed for it,
	 * so a site running extrachill-mcp without extrachill-users keeps the
	 * same reachability it always had rather than silently opening or
	 * closing the gate.
	 */
	private const FALLBACK_CAPABILITY = 'access_roadie';

	public function register(): void {
		add_filter( 'agents_ability_search_permission', array( $this, 'filter_substrate_permission' ), 10, 2 );
		add_filter( 'agents_ability_call_permission', array( $this, 'filter_substrate_permission' ), 10, 2 );
		add_filter( 'ec_feature_ceilings', array( $this, 'register_feature_ceiling' ) );
	}

	/**
	 * Register the `extrachill_mcp` feature at a `team` ceiling.
	 *
	 * The live tier (a network option extrachill-users reads) can never
	 * exceed this code-owned ceiling — see extrachill-users'
	 * `ec_feature_tier()`. `team` matches the tier this plugin enforced
	 * before this feature-rollout migration (`access_roadie`), just sourced
	 * from `ec_is_team_member()` (the platform's single source of truth for
	 * "team", resolved via the `access_studio` capability) instead of a
	 * second, divergent capability.
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

		$ceilings[ self::FEATURE ] = 'team';

		return $ceilings;
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
	 * Filter callback for the Agents API substrate's own
	 * `agents_ability_search_permission` / `agents_ability_call_permission`
	 * filters.
	 *
	 * @param mixed                $allowed Upstream decision (manage_options).
	 * @param array<string, mixed> $input   Meta-ability input.
	 */
	public function filter_substrate_permission( $allowed, $input = array() ): bool {
		if ( true === $allowed ) {
			return true;
		}

		return $this->is_authorized();
	}

	/**
	 * The actual decision, shared by both callbacks above.
	 */
	private function is_authorized(): bool {
		$user_id = get_current_user_id();

		if ( function_exists( 'ec_feature_available' ) ) {
			return (bool) ec_feature_available( self::FEATURE, $user_id );
		}

		return $user_id > 0 && user_can( $user_id, self::FALLBACK_CAPABILITY );
	}
}
