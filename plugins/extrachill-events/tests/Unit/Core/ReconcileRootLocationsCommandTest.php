<?php
/**
 * Qualified root reconciliation CLI tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Cli\ReconcileRootLocationsCommand;
use PHPUnit\Framework\TestCase;

// phpcs:disable -- Test doubles intentionally share global WordPress and WP-CLI state.

require_once dirname( __DIR__, 2 ) . '/Integration/Stubs/wp-cli-stubs.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifiedRootLocation.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RootLocationRepair.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/ReconcileRootLocationsCommand.php';

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists(): bool {
		return true;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms(): array {
		++$GLOBALS['ec_reconcile_cli_test']['term_queries'];
		return $GLOBALS['ec_reconcile_cli_test']['terms'];
	}
}

/** Verifies dry-run and apply authorization boundaries. */
final class ReconcileRootLocationsCommandTest extends TestCase {

	protected function setUp(): void {
		if ( ! property_exists( \WP_CLI::class, 'logs' ) || ! property_exists( \WP_CLI::class, 'formatted' ) ) {
			$this->markTestSkipped( 'Requires the isolated WP-CLI capture stub; managed real-WP-CLI coverage is tracked in #749.' );
		}
		$GLOBALS['ec_reconcile_cli_test'] = array(
			'term_queries' => 0,
			'terms'        => array(),
		);
		\WP_CLI::$logs                       = array();
		\WP_CLI::$formatted                  = array();
		$GLOBALS['ec_test_ability_resolver'] = null;
	}

	/** Dry runs inspect candidates without loading or authorizing redirect abilities. */
	public function test_dry_run_does_not_require_redirect_permissions(): void {
		$ability_requests = array();
		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) use ( &$ability_requests ) {
			$ability_requests[] = $name;
			return null;
		};

		( new ReconcileRootLocationsCommand() )( array(), array() );

		$this->assertSame( array(), $ability_requests );
		$this->assertSame( 1, $GLOBALS['ec_reconcile_cli_test']['term_queries'] );
		$this->assertStringStartsWith( 'Dry run:', end( \WP_CLI::$logs ) );
	}

	/** Apply mode fails before candidate retrieval when the user lacks permission. */
	public function test_apply_fails_fast_without_redirect_permission(): void {
		$GLOBALS['ec_test_ability_resolver'] = static fn() => new ReconcileAbilityDouble( false );

		try {
			( new ReconcileRootLocationsCommand() )( array(), array( 'apply' => true ) );
			$this->fail( 'Expected apply authorization to fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( '--user=<administrator-login-or-id>', $exception->getMessage() );
		}

		$this->assertSame( 0, $GLOBALS['ec_reconcile_cli_test']['term_queries'] );
	}

	/** Authorized apply mode verifies all redirect abilities before retrieval. */
	public function test_apply_checks_all_redirect_abilities_with_authorized_context(): void {
		$ability_requests = array();
		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) use ( &$ability_requests ) {
			$ability_requests[] = $name;
			return new ReconcileAbilityDouble( true );
		};

		( new ReconcileRootLocationsCommand() )( array(), array( 'apply' => true ) );

		$this->assertSame(
			array(
				'extrachill-seo/list-redirects',
				'extrachill-seo/add-redirect',
				'extrachill-seo/delete-redirect',
			),
			$ability_requests
		);
		$this->assertSame( 1, $GLOBALS['ec_reconcile_cli_test']['term_queries'] );
	}
}

/** Minimal ability permission test double. */
final class ReconcileAbilityDouble {

	private bool $allowed;

	public function __construct( bool $allowed ) {
		$this->allowed = $allowed;
	}

	public function check_permissions(): bool {
		return $this->allowed;
	}
}
