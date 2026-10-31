<?php
/**
 * Global-namespace declaration of the Abilities API function the usage
 * handler depends on.
 *
 * Deliberately a separate, namespace-free file: a `function` written inside a
 * namespaced file is declared *in that namespace*, so it would never satisfy
 * an unqualified `wp_get_ability()` call from production code nor a global
 * `function_exists( 'wp_get_ability' )` guard. Loaded lazily by
 * `AnalyticsStub::install()` so tests asserting the genuinely-absent path can
 * run before it exists.
 *
 * @package ExtraChillMcp\Tests\Support
 */

declare( strict_types = 1 );

use ExtraChillMcp\Tests\Support\AnalyticsStub;
use ExtraChillMcp\Tests\Support\FakeAnalyticsAbility;

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * @param string $name Ability name.
	 * @return object|null
	 */
	function wp_get_ability( string $name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Deliberate test-only stand-in for the Abilities API global.
		if ( 'extrachill/track-analytics-event' !== $name || ! AnalyticsStub::$available ) {
			return null;
		}

		return new FakeAnalyticsAbility();
	}
}
