<?php
/**
 * WP-CLI capture stubs for command-level integration tests.
 *
 * @package ExtraChillEvents\Tests\Integration
 */

namespace {
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}

	if ( ! class_exists( 'WP_CLI', false ) ) {
		class WP_CLI {
			public static array $logs = array();
			public static array $formatted = array();

			public static function log( string $message ): void {
				self::$logs[] = $message;
			}

			public static function warning( string $message ): void {
				self::$logs[] = $message;
			}

			public static function success( string $message ): void {
				self::$logs[] = $message;
			}

			public static function error( string $message ): void {
				throw new \RuntimeException( $message );
			}
		}
	}

	if ( ! function_exists( 'get_site_option' ) ) {
		function get_site_option( string $name, $default = false ) {
			return $default;
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( string $format, array $items, array $fields ): void {
			\WP_CLI::$formatted[] = compact( 'format', 'items', 'fields' );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\make_progress_bar' ) ) {
		function make_progress_bar(): object {
			return new class() {
				public function tick(): void {}
				public function finish(): void {}
			};
		}
	}
}
