<?php
/**
 * Event-source intake dependency registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the event-source intake table once.
 *
 * The `/event-source/*` REST routes are extrachill-api adapters over the
 * event-source abilities.
 */
final class EventSourceIntakeProvider {

	/**
	 * Whether dependencies have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the event-source intake dependencies.
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

		self::$registered = true;
		return true;
	}
}
