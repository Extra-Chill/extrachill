<?php
/**
 * Request-scoped record of what a `tools/call` actually reached.
 *
 * @package ExtraChillMcp\Usage
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the resolved ability name from the meta-ability to the observability
 * handler, within one request.
 *
 * The MCP server advertises exactly two tools, so the adapter's own
 * observability tags describe every call in the network as either
 * `extrachill/ability-search` or `extrachill/ability-call`. That is the whole
 * point of the search/call indirection and it is also, for usage analytics,
 * useless: a year of it would say nothing about which capabilities anyone
 * reaches. The interesting name — the ability the caller actually asked for —
 * is known only inside the meta-ability, which has no access to the MCP
 * request that wrapped it.
 *
 * So the meta-abilities record here and the observability handler drains on
 * its way past. Deliberately a plain static: PHP request scope is the exact
 * lifetime wanted, an MCP request handles one `tools/call`, and anything
 * durable would be state to reconcile for no gain.
 *
 * Nothing here is authoritative. A drained-but-unrecorded call still emits
 * with a null ability (the tool name is still known), and a recorded-but-
 * undrained value is discarded at request end. Instrumentation must never
 * change what the transport does.
 */
final class InvocationContext {

	/**
	 * Resolved ability name for the current `tools/call`, when known.
	 */
	private static ?string $ability = null;

	/**
	 * Network site key that served it, when known.
	 */
	private static ?string $site = null;

	/**
	 * Record what the current meta-ability invocation resolved to.
	 *
	 * Last write wins: a single MCP `tools/call` dispatches one ability, and
	 * a non-MCP caller reaching the same meta-ability simply leaves a value
	 * nobody drains.
	 *
	 * @param string      $ability Resolved ability name.
	 * @param string|null $site    Owning network site key, when resolved.
	 */
	public static function record( string $ability, ?string $site = null ): void {
		$ability = trim( $ability );

		if ( '' === $ability ) {
			return;
		}

		self::$ability = $ability;
		self::$site    = $site;
	}

	/**
	 * Take and clear the recorded invocation.
	 *
	 * @return array{ability: string|null, site: string|null}
	 */
	public static function drain(): array {
		$drained = array(
			'ability' => self::$ability,
			'site'    => self::$site,
		);

		self::$ability = null;
		self::$site    = null;

		return $drained;
	}
}
