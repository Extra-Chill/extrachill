<?php
/**
 * PSR-4 Autoloader
 *
 * Registers a namespace-scoped autoloader for the `ExtraChill\CLI\` namespace
 * rooted at the plugin's `inc/` directory. Used in both the WP-CLI runtime
 * (to load command classes on demand) and in non-CLI compose contexts (so the
 * AGENTS.md section generator can reflect over command classes and resolve
 * any traits or dependencies they `use` without a fatal error).
 *
 * @package ExtraChill\CLI
 */

namespace ExtraChill\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {

	/**
	 * Whether the autoloader has already been registered this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the PSR-4 autoloader (idempotent).
	 *
	 * @param string $base_path Plugin root path (with trailing slash).
	 * @return void
	 */
	public static function register( $base_path ) {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		$inc = rtrim( $base_path, '/' ) . '/inc/';

		spl_autoload_register(
			function ( $class_name ) use ( $inc ) {
				$prefix = 'ExtraChill\\CLI\\';
				$len    = strlen( $prefix );

				if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
					return;
				}

				// Bail if the class is already declared. `require_once` only
				// dedupes by resolved file path, so when a second copy of this
				// plugin is loaded from a different directory (e.g. a Data
				// Machine Code worktree under the workspace alongside the live
				// plugin), each copy registers its own autoload closure rooted
				// at its own `inc/`. Without this guard the second closure
				// requires its copy of an already-declared class and triggers a
				// fatal "Cannot redeclare class" error. The class check keys on
				// the class name, not the path, so the first declaration wins.
				if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
					return;
				}

				$relative = substr( $class_name, $len );
				$file     = $inc . str_replace( '\\', '/', $relative ) . '.php';

				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
