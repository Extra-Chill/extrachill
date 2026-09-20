<?php
/**
 * Test-only stand-in for the extrachill-network functions NetworkDispatch
 * consumes: `ec_get_blog_ids()`, `ec_get_blog_slug_by_id()`,
 * `ec_get_ability_site_affinity()`, `ec_cross_site_rest_request()`.
 *
 * extrachill-network is not installed in this plugin's isolated CI
 * environment, so `NetworkDispatch`'s `function_exists()` guards genuinely
 * take the degraded path by default — which `NetworkAbilityAbsenceTest`
 * relies on, and which MUST finish running before `install()` is ever
 * called anywhere in the suite. The four global functions live in a
 * separate, namespace-free file (`network-stub-functions.php`), required
 * lazily by `install()`, following `AnalyticsStub` /
 * `analytics-stub-functions.php`: a `function` declared inside this
 * namespaced file would land as `ExtraChillMcp\Tests\Support\ec_get_blog_ids`,
 * never the global name `NetworkDispatch::available()` guards on — that
 * mistake is exactly what Extra-Chill/extrachill-mcp#17 found.
 *
 * `install()` is a no-op (and returns false) when a real
 * `ec_get_blog_ids()` is already present, so this stub never shadows a
 * genuine extrachill-network registry. Test files are named so
 * `NetworkAbilityAbsenceTest.php` sorts, and therefore runs, before
 * `NetworkAbilityCallTest.php`, `NetworkAbilitySearchTest.php`, and
 * `NetworkDispatchTest.php` — matching PHPUnit's default declaration-order
 * execution for this suite.
 *
 * Handlers are per-test configurable static callables, not a single fixed
 * response, so different test methods can simulate different network
 * topologies without PHP's "cannot redeclare function" restriction getting
 * in the way.
 *
 * @package ExtraChillMcp\Tests\Support
 */

declare( strict_types = 1 );

namespace ExtraChillMcp\Tests\Support;

final class NetworkStub {

	/**
	 * @var array<string,int>
	 */
	public static array $blog_ids = array(
		'main'   => 1,
		'events' => 7,
	);

	/**
	 * @var callable|null Signature: (string $ability_name): ?string
	 */
	public static $affinity_handler = null;

	/**
	 * @var callable|null Signature: (string $site_key, string $method, string $path, array $args): mixed
	 */
	public static $cross_site_handler = null;

	/**
	 * True when this stub owns the global functions.
	 */
	public static bool $installed = false;

	/**
	 * Load the four global stub functions, once, on first call.
	 *
	 * @return bool Whether the stub is in control of the four globals.
	 */
	public static function install(): bool {
		if ( self::$installed ) {
			return true;
		}

		if ( function_exists( 'ec_get_blog_ids' ) ) {
			// A real extrachill-network registry is present; this stub must
			// not shadow it.
			return false;
		}

		require_once __DIR__ . '/network-stub-functions.php';

		self::$installed = true;

		return true;
	}

	/**
	 * Clear per-test handlers between tests. Does NOT (cannot) undeclare the
	 * global functions themselves — those persist for the rest of the suite
	 * once installed, which is why every test that runs after the first
	 * `install()` call must configure its own handler rather than assume a
	 * clean slate.
	 */
	public static function reset(): void {
		self::$blog_ids           = array(
			'main'   => 1,
			'events' => 7,
		);
		self::$affinity_handler   = null;
		self::$cross_site_handler = null;
	}
}
