<?php
/**
 * Qualified root reconciliation service tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\RootLocationReconciler;
use PHPUnit\Framework\TestCase;

// phpcs:disable -- Test doubles intentionally share global WordPress state.

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifiedRootLocation.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RootLocationRepair.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RootLocationReconciler.php';

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms(): array {
		++$GLOBALS['ec_reconcile_cli_test']['term_queries'];
		return $GLOBALS['ec_reconcile_cli_test']['terms'];
	}
}

/** Verifies dry-run and apply authorization boundaries. */
final class RootLocationReconcilerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ec_reconcile_cli_test'] = array(
			'term_queries' => 0,
			'terms'        => array(),
		);
		$GLOBALS['ec_test_ability_resolver'] = null;
	}

	/** Dry runs inspect candidates without loading or authorizing redirect abilities. */
	public function test_dry_run_does_not_require_redirect_permissions(): void {
		$ability_requests = array();
		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) use ( &$ability_requests ) {
			$ability_requests[] = $name;
			return null;
		};

		$rows = ( new RootLocationReconciler() )->reconcile( false );

		$this->assertSame( array(), $rows );
		$this->assertSame( array(), $ability_requests );
		$this->assertSame( 1, $GLOBALS['ec_reconcile_cli_test']['term_queries'] );
	}

	/** Apply readiness fails without touching candidates when the user lacks permission. */
	public function test_apply_fails_fast_without_redirect_permission(): void {
		$GLOBALS['ec_test_ability_resolver'] = static fn() => new ReconcileAbilityDouble( false );

		$ready = ( new RootLocationReconciler() )->assert_apply_ready();

		$this->assertInstanceOf( WP_Error::class, $ready );
		$this->assertStringContainsString( '--user=<administrator-login-or-id>', $ready->get_error_message() );
		$this->assertSame( 0, $GLOBALS['ec_reconcile_cli_test']['term_queries'] );
	}

	/** A missing redirect ability names the ability. */
	public function test_apply_fails_when_a_redirect_ability_is_missing(): void {
		$GLOBALS['ec_test_ability_resolver'] = static fn() => null;

		$ready = ( new RootLocationReconciler() )->assert_apply_ready();

		$this->assertInstanceOf( WP_Error::class, $ready );
		$this->assertSame( 'Apply requires the extrachill-seo/list-redirects ability, but it is unavailable.', $ready->get_error_message() );
	}

	/** Authorized apply mode verifies all redirect abilities before retrieval. */
	public function test_apply_checks_all_redirect_abilities_with_authorized_context(): void {
		$ability_requests = array();
		$GLOBALS['ec_test_ability_resolver'] = static function ( string $name ) use ( &$ability_requests ) {
			$ability_requests[] = $name;
			return new ReconcileAbilityDouble( true );
		};

		$reconciler = new RootLocationReconciler();
		$this->assertTrue( $reconciler->assert_apply_ready() );
		$reconciler->reconcile( true );

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
