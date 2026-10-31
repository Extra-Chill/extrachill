<?php
/**
 * Test-only stand-in for the `extrachill/track-analytics-event` ability that
 * `AnalyticsObservabilityHandler` emits through.
 *
 * extrachill-analytics is not installed in this plugin's isolated CI
 * environment, so `wp_get_ability()` is genuinely absent and the handler's
 * `function_exists()` guard takes the degraded path by default — which is
 * itself worth testing, since usage instrumentation must never fatal a
 * transport just because the analytics plugin is deactivated.
 *
 * Following `NetworkStub`, the global function is declared lazily inside
 * `install()` rather than at file scope, so tests asserting the absent path
 * can run first. `install()` is a no-op when a real `wp_get_ability()` is
 * present, and `$captured` stays empty — callers should skip in that case
 * rather than assert against a registry they do not own.
 *
 * @package ExtraChillMcp\Tests\Support
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Tests\Support;

final class AnalyticsStub {

	/**
	 * Executions recorded by the fake ability.
	 *
	 * @var array<int,array{event_type:string,event_data:array<string,mixed>}>
	 */
	public static array $captured = array();

	/**
	 * Whether the fake ability should be resolvable.
	 */
	public static bool $available = true;

	/**
	 * Whether executing it should throw, to prove the handler swallows it.
	 */
	public static bool $throws = false;

	/**
	 * True when this stub owns the global `wp_get_ability()`.
	 */
	public static bool $installed = false;

	/**
	 * Declare the global function this suite's handler path depends on.
	 *
	 * @return bool Whether the stub is in control of `wp_get_ability()`.
	 */
	public static function install(): bool {
		self::reset();

		if ( self::$installed ) {
			return true;
		}

		if ( function_exists( 'wp_get_ability' ) ) {
			// A real registry is present; this stub must not shadow it.
			return false;
		}

		require_once __DIR__ . '/analytics-stub-functions.php';

		self::$installed = true;

		return true;
	}

	public static function reset(): void {
		self::$captured  = array();
		self::$available = true;
		self::$throws    = false;
	}
}
