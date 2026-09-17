<?php
/**
 * Test-only stand-in for the extrachill-network functions NetworkDispatch
 * consumes: `ec_get_blog_ids()`, `ec_get_blog_slug_by_id()`,
 * `ec_get_ability_site_affinity()`, `ec_cross_site_rest_request()`.
 *
 * extrachill-network is not installed in this plugin's isolated CI
 * environment, so `NetworkDispatch`'s `function_exists()` guards genuinely
 * take the degraded path by default. `NetworkAbilityAbsenceTest.php` relies
 * on that real absence and MUST finish running before `install()` is ever
 * called anywhere in the suite — PHPUnit requires every test file (and
 * therefore executes any file-scope code in it) before running any test
 * method at all, so the four global functions below are declared lazily,
 * inside `install()`, specifically so they do not exist until a test method
 * actually calls it. Test files are named so `NetworkAbilityAbsenceTest.php`
 * sorts before `NetworkAbilityCallTest.php` and `NetworkAbilitySearchTest.php`
 * alphabetically, matching this suite's observed (and PHPUnit's default)
 * declaration-order execution.
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
	 * Declare the four global stub functions, once, on first call.
	 */
	public static function install(): void {
		if ( function_exists( 'ec_get_blog_ids' ) ) {
			return;
		}

		/*
		 * These are GLOBAL function declarations that happen to be written
		 * inside a static method body. A nested named function does not
		 * inherit class scope, so `self::` here is not a style preference —
		 * it is a fatal:
		 *
		 *   PHP Fatal error: Cannot use "self" when no class scope is active
		 *
		 * The sniff cannot see that distinction, and its autofix would break
		 * the stub at runtime. Disabled for this block only, re-enabled
		 * immediately after.
		 */
		// phpcs:disable Squiz.Classes.SelfMemberReference.NotUsed
		function ec_get_blog_ids(): array {
			return NetworkStub::$blog_ids;
		}

		function ec_get_blog_slug_by_id( $blog_id ) {
			foreach ( NetworkStub::$blog_ids as $slug => $id ) {
				if ( (int) $id === (int) $blog_id ) {
					return $slug;
				}
			}

			return null;
		}

		function ec_get_ability_site_affinity( string $ability_name ) {
			return is_callable( NetworkStub::$affinity_handler )
				? ( NetworkStub::$affinity_handler )( $ability_name )
				: null;
		}

		function ec_cross_site_rest_request( string $site_key, string $method, string $path, array $args = array() ) {
			if ( ! is_callable( NetworkStub::$cross_site_handler ) ) {
				return new \WP_Error( 'stub_not_configured', 'No stub handler configured for this test.' );
			}

			return ( NetworkStub::$cross_site_handler )( $site_key, $method, $path, $args );
		}
		// phpcs:enable Squiz.Classes.SelfMemberReference.NotUsed
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
