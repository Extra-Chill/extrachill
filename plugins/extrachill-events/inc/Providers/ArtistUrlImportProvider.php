<?php
/**
 * Artist URL Import dependency registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads the Artist URL Import table and REST surface once. */
final class ArtistUrlImportProvider {

	/**
	 * Whether dependencies have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the Artist URL Import dependencies.
	 *
	 * @return bool Whether the provider is registered.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}

		if ( ! function_exists( 'add_action' ) ) {
			return false;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/ArtistUrlImport.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/ArtistUrlImportRoutes.php';

		self::$registered = true;
		return true;
	}
}
