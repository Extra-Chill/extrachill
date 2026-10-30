<?php
/**
 * Which registered abilities the MCP surface may invoke.
 *
 * @package ExtraChillMcp
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * An ability is callable through the MCP only when its owner opted it into
 * an external surface: `show_in_rest: true` (core's Abilities REST API), or
 * `mcp.public: true` (the WordPress MCP adapter's exposure convention).
 *
 * Abilities that opted out of both are server-side primitives. Their
 * permission callbacks often assume an in-process caller and are not a user
 * boundary, so the MCP must not dispatch them. Cross-site calls already
 * enforce `show_in_rest` through the target site's REST run route; this
 * applies the same contract to the local in-process hop.
 */
final class Exposure {

	/**
	 * @param array<string,mixed> $meta Ability meta.
	 */
	public static function meta_is_exposed( array $meta ): bool {
		if ( true === ( $meta['show_in_rest'] ?? false ) ) {
			return true;
		}

		$mcp = $meta['mcp'] ?? null;

		return is_array( $mcp ) && true === ( $mcp['public'] ?? false );
	}

	/**
	 * Whether a locally registered ability may be invoked through the MCP.
	 *
	 * Unknown names return true so the caller's normal "not registered"
	 * handling still applies.
	 */
	public static function is_locally_exposed( string $name ): bool {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
			return true;
		}

		$ability = wp_get_ability( $name );

		if ( null === $ability ) {
			return true;
		}

		return self::meta_is_exposed( $ability->get_meta() );
	}
}
