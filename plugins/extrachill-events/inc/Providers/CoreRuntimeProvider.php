<?php
/**
 * Shared Events runtime prerequisites.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads core services shared by multiple feature providers. */
final class CoreRuntimeProvider {

	/**
	 * Whether shared runtime files have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register shared qualification and service-authority prerequisites once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-boost-service-authority.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdict.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictResolver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PlatformDetector.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyFingerprinter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueExpansionRunner.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/EventSourceRampEvaluator.php';
	}
}
