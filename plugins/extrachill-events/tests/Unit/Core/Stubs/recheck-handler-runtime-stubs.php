<?php
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter,Universal.Namespaces.DisallowCurlyBraceSyntax,Universal.Namespaces.DisallowDeclarationWithoutName -- Test doubles mirror WordPress and dependency signatures.
/**
 * Runtime-faithful stubs for QualifyRecheckHandlerTest.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace {
	require_once __DIR__ . '/flow-gateway-stubs.php';

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return new class() {
				public function execute( array $input ) {
					return $GLOBALS['ec_test_ability_result'];
				}
			};
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
			$GLOBALS['ec_test_action_scheduler'][] = compact( 'timestamp', 'hook', 'args', 'group' );
			return 12345;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $hook, ...$args ): void {}
	}
}
