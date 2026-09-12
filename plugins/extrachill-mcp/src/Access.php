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
 * Opens the Agents API ability meta-abilities to the Extra Chill team tier.
 *
 * Agents API registers `agents/ability-search` and `agents/ability-call` gated
 * behind `manage_options`, with a filter on each so hosts can widen them. This
 * class is that host decision for Extra Chill.
 *
 * Widening the gate is safe because it is NOT the security boundary.
 * `WP_Ability::execute()` calls `check_permissions( $input )` on the target
 * ability itself before running it (wp-includes/abilities-api/class-wp-ability.php),
 * so `agents/ability-call` cannot reach anything the caller could not already
 * reach through REST or the CLI. Venue-scoped booking abilities, for example,
 * still resolve `booking_id` -> venue grant -> per-action authorization on every
 * call. The gate here governs reachability of the two meta-tools, not what those
 * tools are permitted to do.
 */
final class Access {

	/**
	 * Default capability required to reach the MCP meta-tools.
	 *
	 * `access_roadie` is the existing Extra Chill team tier, already used to gate
	 * the Roadie tool surface. Reusing it keeps one definition of "team" rather
	 * than minting a second, parallel notion of MCP eligibility.
	 */
	private const DEFAULT_CAPABILITY = 'access_roadie';

	public function register(): void {
		add_filter( 'agents_ability_search_permission', array( $this, 'can_use' ), 10, 2 );
		add_filter( 'agents_ability_call_permission', array( $this, 'can_use' ), 10, 2 );
	}

	/**
	 * Decide whether the current user may reach the ability meta-tools.
	 *
	 * @param bool                 $allowed Upstream decision (manage_options).
	 * @param array<string, mixed> $input   Meta-ability input.
	 * @return bool
	 */
	public function can_use( $allowed, $input = array() ): bool {
		if ( true === $allowed ) {
			return true;
		}

		/**
		 * Filters the capability required to reach the Extra Chill MCP meta-tools.
		 *
		 * Defaults to the team tier. Widening this to a member-level capability is
		 * a deliberate product decision, not a default: while execution stays
		 * correctly gated per ability, a caller who can reach `agents/ability-search`
		 * can enumerate every registered ability name and JSON schema on the
		 * network. That is information disclosure even when nothing is executable.
		 *
		 * @param string               $capability Required capability.
		 * @param array<string, mixed> $input      Meta-ability input.
		 */
		/** @var mixed $capability */
		$capability = apply_filters( 'extrachill_mcp_capability', self::DEFAULT_CAPABILITY, $input );

		// The docblock above documents the contract a well-behaved filter should
		// honor; it is not a guarantee. `current_user_can()` throws a TypeError
		// on WP 7.1 for null/array input, so a filter returning anything other
		// than a non-empty string must resolve to a clean denial, not a fatal.
		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}
}
